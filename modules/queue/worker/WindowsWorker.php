<?php

/**
 * Hệ thống Queue NukeViet - Worker Windows
 *
 * @version 1.0
 * @author Huỳnh Quốc Đạt <work@hqd.vn>
 * @website https://huynhquocdat.vn
 * @copyright (C) 2026 Huỳnh Quốc Đạt. All rights reserved
 * @license GNU/GPL version 2 or any later version
 *
 * Worker này sử dụng chiến lược vòng lặp đơn giản phù hợp cho môi trường Windows/XAMPP
 * nơi pcntl_fork không khả dụng. Nó xử lý công việc tuần tự với
 * giới hạn có thể định cấu hình để ngăn rò rỉ bộ nhớ.
 */

declare(strict_types=1);

namespace NukeViet\Module\queue\worker;

/**
 * WindowsWorker - Worker dựa trên vòng lặp cho Windows/XAMPP
 *
 * Sử dụng một vòng lặp đơn giản để xử lý công việc tuần tự.
 * Tự động thoát sau khi đạt đến bất kỳ giới hạn nào sau đây:
 * - 50 công việc đã xử lý
 * - 1 giờ chạy
 * - 100MB sử dụng bộ nhớ
 *
 * Để sử dụng production trên Windows, hãy sử dụng NSSM hoặc Windows Task Scheduler
 * để tự động khởi động lại worker khi nó thoát.
 */
class WindowsWorker extends AbstractWorker
{
    /**
     * Số lượng công việc trước khi buộc thu gom rác
     */
    protected int $gcInterval = 10;

    /**
     * Số lượng công việc tại lần thu gom rác cuối cùng
     */
    protected int $lastGcAt = 0;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        // Cài đặt cụ thể cho Windows
        // Đặt thời gian thực thi tối đa thành không giới hạn
        set_time_limit(0);

        // Kích hoạt thu gom rác
        gc_enable();
    }

    /**
     * Buộc thu gom rác nếu cần thiết
     */
    protected function maybeGarbageCollect(): void
    {
        if ($this->jobsProcessed - $this->lastGcAt >= $this->gcInterval) {
            $before = memory_get_usage(true);
            gc_collect_cycles();
            $after = memory_get_usage(true);
            $freed = $before - $after;

            if ($freed > 0) {
                $freedMB = round($freed / 1048576, 2);
                $this->log("Garbage collection freed {$freedMB}MB", 'debug');
            }

            $this->lastGcAt = $this->jobsProcessed;
        }
    }

    /**
     * Lấy phần trăm sử dụng bộ nhớ hiện tại so với giới hạn
     *
     * @return float Phần trăm (0-100)
     */
    protected function getMemoryUsagePercent(): float
    {
        return (memory_get_usage(true) / $this->maxMemory) * 100;
    }

    /**
     * Vòng lặp chạy chính - xử lý tuần tự đơn giản
     *
     * Xử lý công việc từng cái một cho đến khi đạt giới hạn:
     * 1. Lấy công việc từ hàng đợi
     * 2. Xử lý công việc
     * 3. Kiểm tra giới hạn
     * 4. Thu gom rác định kỳ
     * 5. Lặp lại
     */
    public function run(): void
    {
        $this->log("WindowsWorker started (Loop & Limit strategy)");
        $this->log("Queue: {$this->queueName}");
        $this->log("Limits: {$this->maxJobs} jobs, {$this->maxRuntime}s runtime, " .
            round($this->maxMemory / 1048576) . "MB memory");

        $emptyQueueCount = 0;
        $maxEmptyChecks = 60; // Exit if queue is empty for 5 minutes (60 * 5s)

        while ($this->shouldContinue()) {
        // Thử lấy một công việc
            $job = $this->popJob($this->sleepInterval);

            if ($job === null) {
                $emptyQueueCount++;

                // Ghi log trạng thái định kỳ khi hàng đợi trống
                if ($emptyQueueCount % 12 === 0) { // Mỗi phút
                    $stats = $this->getStats();
                    $this->log("Queue empty. Jobs: {$stats['jobs_processed']}, " .
                        "Runtime: {$stats['runtime_seconds']}s, " .
                        "Memory: {$stats['memory_usage_mb']}MB");
                }

                // Tùy chọn: Thoát nếu hàng đợi trống quá lâu
                if ($emptyQueueCount >= $maxEmptyChecks) {
                    $this->log("Queue has been empty for {$maxEmptyChecks} checks, exiting");
                    break;
                }

                continue;
            }

            // Đặt lại bộ đếm trống khi chúng ta nhận được công việc
            $emptyQueueCount = 0;

            // Xử lý công việc
            $success = $this->processJob($job);
            $this->jobsProcessed++;

            if (!$success) {
                $this->log("Job failed, continuing to next job", 'warning');
            }

            // Thu gom rác định kỳ
            $this->maybeGarbageCollect();

            // Ghi log tiến độ mỗi 10 công việc
            if ($this->jobsProcessed % 10 === 0) {
                $stats = $this->getStats();
                $memPercent = round($this->getMemoryUsagePercent(), 1);
                $this->log("Progress: {$stats['jobs_processed']}/{$this->maxJobs} jobs, " .
                    "{$stats['runtime_seconds']}s runtime, " .
                    "{$stats['memory_usage_mb']}MB ({$memPercent}% of limit)");
            }
        }

        // Thu gom rác cuối cùng
        gc_collect_cycles();

        // In thống kê cuối cùng
        $stats = $this->getStats();
        $this->log("Worker finished. Processed {$stats['jobs_processed']} jobs in {$stats['runtime_seconds']}s");
        $this->log("Peak memory: {$stats['peak_memory_mb']}MB");

        // In gợi ý khởi động lại cho Windows
        $this->log("Note: Use NSSM or Windows Task Scheduler to automatically restart this worker.");
    }
}
