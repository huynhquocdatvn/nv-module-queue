<?php

/**
 * Hệ thống Queue NukeViet - Worker Linux
 *
 * @version 1.0
 * @author Huỳnh Quốc Đạt <work@hqd.vn>
 * @website https://huynhquocdat.vn
 * @copyright (C) 2026 Huỳnh Quốc Đạt. All rights reserved
 * @license GNU/GPL version 2 or any later version
 *
 * Worker này sử dụng pcntl_fork để tạo tiến trình con cho mỗi công việc.
 * Tiến trình cha quản lý hàng đợi trong khi các tiến trình con xử lý các công việc riêng lẻ.
 * Cách tiếp cận này cung cấp khả năng quản lý bộ nhớ tuyệt vời vì mỗi tiến trình con
 * được giải phóng hoàn toàn sau khi xử lý.
 */

declare(strict_types=1);

namespace NukeViet\Module\queue\worker;

/**
 * LinuxWorker - Worker dựa trên fork cho các hệ thống Linux/Unix
 *
 * Sử dụng pcntl_fork() để spawn tiến trình con cho mỗi công việc.
 * Ưu điểm:
 * - Cách ly bộ nhớ hoàn toàn giữa các công việc
 * - Không rò rỉ bộ nhớ từ các tiến trình chạy lâu
 * - Mỗi công việc có một môi trường mới
 */
class LinuxWorker extends AbstractWorker
{
    /**
     * Số lượng tiến trình con đồng thời tối đa
     */
    protected int $maxChildren = 4;

    /**
     * Các PID con đang chạy
     *
     * @var array<int, int> [pid => startTime]
     */
    protected array $children = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        // Xác minh extension pcntl có sẵn không
        if (!extension_loaded('pcntl')) {
            throw new \RuntimeException(
                'The pcntl extension is required for LinuxWorker. ' .
                'Please use WindowsWorker on systems without pcntl support.'
            );
        }

        if (!extension_loaded('posix')) {
            throw new \RuntimeException(
                'The posix extension is required for LinuxWorker.'
            );
        }

        parent::__construct();

        // Thiết lập các signal handler
        $this->setupSignalHandlers();
    }

    /**
     * Thiết lập các signal handler để tắt một cách an toàn
     */
    protected function setupSignalHandlers(): void
    {
        // Xử lý SIGTERM (kill) và SIGINT (Ctrl+C)
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            $this->log("Received SIGTERM, initiating graceful shutdown...");
            $this->shutdown();
        });

        pcntl_signal(SIGINT, function () {
            $this->log("Received SIGINT (Ctrl+C), initiating graceful shutdown...");
            $this->shutdown();
        });

        // Xử lý khi tiến trình con hoàn tất
        pcntl_signal(SIGCHLD, function () {
            $this->reapChildren();
        });
    }

    /**
     * Thu dọn các tiến trình con đã hoàn tất
     */
    protected function reapChildren(): void
    {
        while (true) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid <= 0) {
                break;
            }

            if (isset($this->children[$pid])) {
                $duration = time() - $this->children[$pid];
                unset($this->children[$pid]);

                if (pcntl_wifexited($status)) {
                    $exitCode = pcntl_wexitstatus($status);
                    $this->log("Child process {$pid} exited with code {$exitCode} after {$duration}s", 'debug');
                } elseif (pcntl_wifsignaled($status)) {
                    $signal = pcntl_wtermsig($status);
                    $this->log("Child process {$pid} terminated by signal {$signal}", 'warning');
                }
            }
        }
    }

    /**
     * Chờ một slot con trống
     */
    protected function waitForChildSlot(): void
    {
        while (count($this->children) >= $this->maxChildren) {
            $this->reapChildren();

            if (count($this->children) >= $this->maxChildren) {
                usleep(100000); // 100ms
            }
        }
    }

    /**
     * Vòng lặp chạy chính sử dụng fork
     *
     * Tiến trình cha liên tục:
     * 1. Chờ slot con trống
     * 2. Lấy một công việc từ Redis
     * 3. Fork một con để xử lý công việc
     * 4. Cha quay lại bước 1
     *
     * Tiến trình con:
     * 1. Xử lý công việc
     * 2. Thoát ngay lập tức (giải phóng bộ nhớ)
     */
    public function run(): void
    {
        $this->log("LinuxWorker started with max {$this->maxChildren} concurrent processes");
        $this->log("Queue: {$this->queueName}");
        $this->log("Limits: {$this->maxJobs} jobs, {$this->maxRuntime}s runtime, " .
            round($this->maxMemory / 1048576) . "MB memory");

        while ($this->shouldContinue()) {
            // Thu dọn bất kỳ con nào đã hoàn tất
            $this->reapChildren();

            // Chờ slot con trống
            $this->waitForChildSlot();

            // Kiểm tra giới hạn lại sau khi chờ
            if (!$this->shouldContinue()) {
                break;
            }

            // Thử lấy một công việc
            $job = $this->popJob($this->sleepInterval);

            if ($job === null) {
                continue;
            }

            // Fork một tiến trình con để xử lý công việc này
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork thất bại - xử lý công việc trong cha như dự phòng
                $this->log("Fork failed, processing job in parent process", 'warning');
                $this->processJob($job);
                $this->jobsProcessed++;
            } elseif ($pid === 0) {
                // Tiến trình con - kết nối lại Redis để tránh chia sẻ file descriptor
                if ($this->driver === 'redis' && $this->redis !== null) {
                    $this->redis->disconnect();
                    $this->connectRedis();
                }
                try {
                    $result = $this->processJob($job);
                    exit($result ? 0 : 1);
                } catch (\Throwable $e) {
                    $this->log("Child process error: " . $e->getMessage(), 'error');
                    exit(1);
                }
            } else {
                // Tiến trình cha
                $this->children[$pid] = time();
                $this->jobsProcessed++;
                $this->log("Spawned child process {$pid} for job", 'debug');
            }
        }

        // Chờ tất cả các con hoàn tất
        $this->log("Waiting for remaining child processes to complete...");
        while (!empty($this->children)) {
            $this->reapChildren();
            if (!empty($this->children)) {
                usleep(100000); // 100ms
            }
        }

        // In thống kê cuối cùng
        $stats = $this->getStats();
        $this->log("Worker finished. Processed {$stats['jobs_processed']} jobs in {$stats['runtime_seconds']}s");
        $this->log("Peak memory: {$stats['peak_memory_mb']}MB");
    }

    /**
     * Ghi đè shutdown để cũng chấm dứt các con
     */
    public function shutdown(): void
    {
        parent::shutdown();

        // Gửi SIGTERM tới tất cả các con
        foreach (array_keys($this->children) as $pid) {
            $this->log("Sending SIGTERM to child process {$pid}", 'debug');
            posix_kill($pid, SIGTERM);
        }
    }
}
