<?php

/**
 * Hệ thống Queue NukeViet - Worker trừu tượng
 *
 * @version 1.0
 * @author Huỳnh Quốc Đạt <work@hqd.vn>
 * @website https://huynhquocdat.vn
 * @copyright (C) 2026 Huỳnh Quốc Đạt. All rights reserved
 * @license GNU/GPL version 2 or any later version
 *
 * Lớp cơ sở cho tất cả các chiến lược worker. Xử lý kết nối Redis,
 * kết nối lại cơ sở dữ liệu và logic điều phối công việc.
 */

declare(strict_types=1);

namespace NukeViet\Module\queue\worker;

use NukeViet\Core\Database;

/**
 * AbstractWorker - Lớp cơ sở cho các Queue Worker
 *
 * Lớp này cung cấp chức năng chung cho cả LinuxWorker và WindowsWorker:
 * - Quản lý kết nối Redis
 * - Kết nối lại cơ sở dữ liệu trước mỗi công việc (ngăn lỗi "MySQL has gone away")
 * - Giải mã công việc và gọi handler
 * - Xác thực module
 */
abstract class AbstractWorker
{
    /**
     * Instance Redis client
     * @var \Predis\Client|null
     */
    protected $redis = null;

    /**
     * Cấu hình Redis từ config.php
     */
    protected array $redisConfig;

    /**
     * Tên hàng đợi/key trong Redis
     */
    protected string $queueName;

    /**
     * Tên hàng đợi processing cho Redis (at-least-once delivery)
     */
    protected string $processingQueueName;

    /**
     * Số lượng công việc đã xử lý
     */
    protected int $jobsProcessed = 0;

    /**
     * Thời gian bắt đầu worker
     */
    protected int $startTime;

    /**
     * Bộ nhớ tối đa tính bằng byte (100MB)
     */
    protected int $maxMemory = 104857600;

    /**
     * Thời gian chạy tối đa tính bằng giây (1 giờ)
     */
    protected int $maxRuntime = 3600;

    /**
     * Số lượng công việc tối đa cần xử lý trước khi thoát
     */
    protected int $maxJobs = 50;

    /**
     * Khoảng thời gian polling tính bằng giây khi hàng đợi trống
     */
    protected int $sleepInterval = 5;

    /**
     * Liệu worker có nên tiếp tục chạy không
     */
    protected bool $shouldRun = true;

    /**
     * Loại driver ('redis' hoặc 'database')
     */
    protected string $driver = 'redis';

    /**
     * Có hỗ trợ SKIP LOCKED không (MySQL 8.0+ / MariaDB 10.6+)
     */
    protected bool $supportsSkipLocked = false;

    /**
     * Số lần thử tối đa
     */
    protected int $maxAttempts = 3;

    /**
     * Constructor - Khởi tạo kết nối Redis
     *
     * @throws \RuntimeException Nếu thiếu cấu hình Redis
     */
    public function __construct()
    {
        // Tải cấu hình từ database
        $queue_config = $this->loadConfigFromDb();

        $this->driver = $queue_config['driver'] ?? 'database';

        if ($this->driver === 'redis') {
            if (empty($queue_config['redis_host'])) {
                throw new \RuntimeException(
                    'Redis configuration is not defined in database. ' .
                    'Please configure Redis in Queue module admin.'
                );
            }

            if (!class_exists('\\Predis\\Client')) {
                throw new \RuntimeException(
                    'Predis library is not installed. ' .
                    'Please run "composer require predis/predis" to use Redis driver.'
                );
            }

            $this->redisConfig = [
                'host' => $queue_config['redis_host'],
                'port' => $queue_config['redis_port'],
                'password' => $queue_config['redis_pass'],
                'database' => $queue_config['redis_db'],
                'prefix' => $queue_config['redis_prefix'],
            ];
            $this->queueName = ($this->redisConfig['prefix'] ?? 'nv_queue_') . 'jobs';
            $this->processingQueueName = ($this->redisConfig['prefix'] ?? 'nv_queue_') . 'processing';

            $this->connectRedis();
        } else {
            $this->log("Using Database Queue Driver");
            $this->queueName = 'default';
            $this->detectSkipLockedSupport();
        }

        $this->startTime = time();
    }

    /**
     * Tải cấu hình hàng đợi từ database
     */
    protected function loadConfigFromDb(): array
    {
        global $db, $db_config;

        // Đảm bảo kết nối
        if (!is_object($db) || empty($db->connect)) {
            $this->reconnectDatabase();
        }

        $config = [];
        $module_name = 'queue';
        try {
            $sql = "SELECT config_name, config_value FROM " . $db_config['prefix'] . "_config WHERE lang='sys' AND module='" . $module_name . "'";
            $result = $db->query($sql);
            while ($row = $result->fetch()) {
                $config[$row['config_name']] = $row['config_value'];
            }
        } catch (\Exception $e) {
            $this->log("Error loading config from database: " . $e->getMessage(), 'error');
        }

        return array_merge([
            'driver' => 'database',
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'redis_pass' => '',
            'redis_db' => 0,
            'redis_prefix' => 'nv_queue_',
        ], $config);
    }

    /**
     * Phát hiện MySQL/MariaDB có hỗ trợ SKIP LOCKED không
     */
    protected function detectSkipLockedSupport(): void
    {
        global $db;

        try {
            $result = $db->query("SELECT VERSION() as v");
            $row = $result->fetch();
            if ($row) {
                $version = $row['v'];
                // MariaDB: version chứa "MariaDB", ví dụ "10.6.12-MariaDB"
                if (stripos($version, 'mariadb') !== false) {
                    // MariaDB 10.6+
                    if (preg_match('/^(\d+\.\d+)/', $version, $m)) {
                        $this->supportsSkipLocked = version_compare($m[1], '10.6', '>=');
                    }
                } else {
                    // MySQL 8.0+
                    if (preg_match('/^(\d+\.\d+)/', $version, $m)) {
                        $this->supportsSkipLocked = version_compare($m[1], '8.0', '>=');
                    }
                }

                if ($this->supportsSkipLocked) {
                    $this->log("SKIP LOCKED supported (version: {$version})");
                }
            }
        } catch (\Exception $e) {
            $this->log("Could not detect MySQL version: " . $e->getMessage(), 'warning');
        }
    }

    /**
     * Kết nối đến máy chủ Redis
     *
     * @throws \RuntimeException Nếu kết nối thất bại
     */
    protected function connectRedis(): void
    {
        try {
            $options = [
                'scheme' => 'tcp',
                'host' => $this->redisConfig['host'] ?? '127.0.0.1',
                'port' => (int) ($this->redisConfig['port'] ?? 6379),
            ];

            if (!empty($this->redisConfig['password'])) {
                $options['password'] = $this->redisConfig['password'];
            }

            if (isset($this->redisConfig['database'])) {
                $options['database'] = (int) $this->redisConfig['database'];
            }

            $this->redis = new \Predis\Client($options);

            // Kiểm tra kết nối
            $this->redis->ping();

            $this->log("Connected to Redis at {$options['host']}:{$options['port']}");
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to connect to Redis: " . $e->getMessage()
            );
        }
    }

    /**
     * Kết nối lại cơ sở dữ liệu, chỉ khi kết nối hiện tại đã mất.
     *
     * @return Database Instance kết nối cơ sở dữ liệu
     */
    protected function reconnectDatabase(): Database
    {
        global $db, $db_config;

        // Kiểm tra kết nối hiện tại còn hoạt động không
        if ($db instanceof Database && !empty($db->connect)) {
            try {
                $db->query('SELECT 1');
                return $db;
            } catch (\Exception $e) {
                // Kết nối đã mất, tiếp tục reconnect
                $this->log("Database connection lost, reconnecting...", 'warning');
            }
        }

        // Đóng kết nối hiện tại nếu có
        $db = null;

        // Tạo kết nối cơ sở dữ liệu mới
        $db = new Database($db_config);

        if (empty($db->connect)) {
            throw new \RuntimeException('Failed to reconnect to database');
        }

        return $db;
    }

    /**
     * Kiểm tra xem một module có hoạt động không
     *
     * @param string $module Tên module
     * @return bool True nếu module hoạt động hoặc không thể xác minh
     */
    protected function isModuleActive(string $module): bool
    {
        global $site_mods;

        // Nếu site_mods không khả dụng (ngữ cảnh CLI), giả định module hợp lệ
        if (!isset($site_mods) || !is_array($site_mods) || empty($site_mods)) {
            $this->log("Note: \$site_mods not available (CLI context), proceeding with job", 'debug');
            return true;
        }

        return isset($site_mods[$module]) && !empty($site_mods[$module]['module_file']);
    }

    /**
     * Lấy một công việc từ hàng đợi (Redis hoặc Database)
     *
     * @param int $timeout Thời gian chờ tính bằng giây cho blocking pop (chỉ Redis)
     * @return array|null Dữ liệu công việc hoặc null nếu không có công việc
     */
    protected function popJob(int $timeout = 5): ?array
    {
        if ($this->driver === 'database') {
            return $this->popJobFromDatabase();
        }

        return $this->popJobFromRedis($timeout);
    }

    /**
     * Lấy công việc từ Redis sử dụng BRPOPLPUSH cho at-least-once delivery
     */
    protected function popJobFromRedis(int $timeout = 5): ?array
    {
        try {
            // Sử dụng BRPOPLPUSH: di chuyển nguyên tử từ queue chính sang processing queue
            // Nếu worker crash, job vẫn còn trong processing queue để recovery
            $jobData = $this->redis->brpoplpush($this->queueName, $this->processingQueueName, $timeout);

            if ($jobData === null) {
                return null;
            }

            $job = json_decode($jobData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("Invalid JSON in job data: " . json_last_error_msg(), 'error');
                // Xóa khỏi processing queue vì dữ liệu hỏng
                $this->redis->lrem($this->processingQueueName, 1, $jobData);
                return null;
            }

            // Lưu raw data để xóa khỏi processing queue sau
            $job['__raw_data'] = $jobData;

            return $job;
        } catch (\Exception $e) {
            $this->log("Error popping job from queue: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Lấy công việc từ Database
     */
    protected function popJobFromDatabase(): ?array
    {
        global $db, $db_config;

        $tableName = ($db_config['prefix'] ?? 'nv4') . '_queue_jobs';

        // Đảm bảo kết nối
        try {
            $this->reconnectDatabase();
        } catch (\Exception $e) {
            $this->log("Database connection failed: " . $e->getMessage(), 'error');
            usleep(1000000);
            return null;
        }

        try {
            $now = time();

            $db->query('START TRANSACTION');

            $sql = "SELECT id, payload, attempts FROM " . $tableName
                . " WHERE reserved_at IS NULL AND available_at <= " . $now
                . " ORDER BY id ASC LIMIT 1 FOR UPDATE"
                . ($this->supportsSkipLocked ? ' SKIP LOCKED' : '');

            $result = $db->query($sql);
            $row = $result->fetch();

            if ($row) {
                $jobId = (int) $row['id'];

                // Đặt trước công việc
                $db->query("UPDATE " . $tableName . " SET reserved_at = " . $now . ", attempts = attempts + 1 WHERE id = " . $jobId);

                $db->query('COMMIT');

                $job = json_decode($row['payload'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $db->query("DELETE FROM " . $tableName . " WHERE id = " . $jobId);
                    return null;
                }

                $job['__db_id'] = $jobId;
                $job['attempts'] = (int) $row['attempts'] + 1;

                return $job;
            }

            $db->query('COMMIT');

            // Không tìm thấy công việc, chờ theo sleepInterval
            usleep($this->sleepInterval * 1000000);

            return null;
        } catch (\Exception $e) {
            // ROLLBACK để tránh transaction treo
            try {
                $db->query('ROLLBACK');
            } catch (\Exception $rollbackEx) {
                // Bỏ qua lỗi rollback
            }
            $this->log("Error popping job from database: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Xử lý một công việc đơn lẻ
     *
     * @param array $job Dữ liệu công việc chứa 'module', 'handler', 'data'
     * @return bool True nếu công việc được xử lý thành công
     */
    protected function processJob(array $job): bool
    {
        $module = $job['module'] ?? '';
        $handler = $job['handler'] ?? '';
        $data = $job['data'] ?? [];
        $jobId = $job['id'] ?? uniqid();

        $this->log("Processing job {$jobId}: {$module}::{$handler}");

        // Xác thực các trường bắt buộc
        if (empty($module) || empty($handler)) {
            $this->log("Invalid job: missing module or handler", 'error');
            $this->handleFailedJob($job, 'Invalid job: missing module or handler');
            return false;
        }

        // Kiểm tra xem module có hoạt động không
        if (!$this->isModuleActive($module)) {
            $this->log("Module '{$module}' is not active, skipping job", 'warning');
            $this->handleFailedJob($job, "Module '{$module}' is not active");
            return false;
        }

        // Kết nối lại cơ sở dữ liệu trước khi xử lý
        try {
            $this->reconnectDatabase();
        } catch (\Exception $e) {
            $this->log("Database reconnection failed: " . $e->getMessage(), 'error');
            // Không gọi handleFailedJob vì DB không khả dụng, job sẽ timeout và được retry
            return false;
        }

        // Giải quyết lớp handler
        $handlerClass = $this->resolveHandlerClass($module, $handler);

        if (!class_exists($handlerClass)) {
            $this->log("Handler class not found: {$handlerClass}", 'error');
            $this->handleFailedJob($job, "Handler class not found: {$handlerClass}");
            return false;
        }

        if (!method_exists($handlerClass, 'handle')) {
            $this->log("Handler class {$handlerClass} does not have a handle() method", 'error');
            $this->handleFailedJob($job, "Handler class {$handlerClass} missing handle() method");
            return false;
        }

        // Thực thi công việc
        try {
            $startTime = microtime(true);
            $result = $handlerClass::handle($data);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result) {
                $this->log("Job {$jobId} completed in {$duration}ms");

                // Xóa khỏi processing queue (Redis) hoặc DB
                $this->removeFromProcessingQueue($job);
                if (isset($job['__db_id'])) {
                    $this->deleteJobFromDatabase($job['__db_id']);
                }
            } else {
                $this->log("Job {$jobId} returned false after {$duration}ms", 'warning');
                $this->handleFailedJob($job, 'Job handler returned false');
            }

            return (bool) $result;
        } catch (\Throwable $e) {
            $this->log("Job {$jobId} failed: " . $e->getMessage(), 'error');
            $this->log("Stack trace: " . $e->getTraceAsString(), 'debug');

            $this->handleFailedJob($job, $e->getMessage());

            return false;
        }
    }

    /**
     * Xóa công việc khỏi Redis processing queue
     */
    protected function removeFromProcessingQueue(array $job): void
    {
        if ($this->driver !== 'redis' || !isset($job['__raw_data']) || $this->redis === null) {
            return;
        }

        try {
            $this->redis->lrem($this->processingQueueName, 1, $job['__raw_data']);
        } catch (\Exception $e) {
            $this->log("Failed to remove job from processing queue: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Giải quyết tên lớp đầy đủ cho một job handler
     *
     * @param string $module Tên module (ví dụ: 'news')
     * @param string $handler Tên handler (ví dụ: 'Jobs\SendEmail' hoặc 'SendEmail')
     * @return string Tên lớp đầy đủ
     */
    protected function resolveHandlerClass(string $module, string $handler): string
    {
        if (str_contains($handler, '\\')) {
            if (str_starts_with($handler, 'NukeViet\\')) {
                return $handler;
            }
            return "NukeViet\\Module\\{$module}\\{$handler}";
        }

        return "NukeViet\\Module\\{$module}\\Jobs\\{$handler}";
    }

    /**
     * Kiểm tra xem worker có nên tiếp tục chạy không
     *
     * @return bool True nếu worker nên tiếp tục
     */
    protected function shouldContinue(): bool
    {
        if (!$this->shouldRun) {
            return false;
        }

        // Kiểm tra giới hạn công việc
        if ($this->jobsProcessed >= $this->maxJobs) {
            $this->log("Reached maximum jobs limit ({$this->maxJobs})");
            return false;
        }

        // Kiểm tra giới hạn thời gian chạy
        $runtime = time() - $this->startTime;
        if ($runtime >= $this->maxRuntime) {
            $this->log("Reached maximum runtime limit ({$this->maxRuntime}s)");
            return false;
        }

        // Kiểm tra giới hạn bộ nhớ
        $memoryUsage = memory_get_usage(true);
        if ($memoryUsage >= $this->maxMemory) {
            $memoryMB = round($memoryUsage / 1048576, 2);
            $this->log("Reached maximum memory limit ({$memoryMB}MB)");
            return false;
        }

        return true;
    }

    /**
     * Ghi log ra stdout/stderr
     *
     * @param string $message Thông điệp cần log
     * @param string $level Mức độ log (info, warning, error, debug)
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $formattedMessage = "[{$timestamp}] [{$levelUpper}] {$message}\n";

        if ($level === 'error') {
            fwrite(STDERR, $formattedMessage);
        } else {
            fwrite(STDOUT, $formattedMessage);
        }
    }

    /**
     * Handler tắt graceful
     */
    public function shutdown(): void
    {
        $this->shouldRun = false;
        $this->log("Shutting down worker...");
    }

    /**
     * Lấy thống kê về worker
     *
     * @return array Thống kê worker
     */
    public function getStats(): array
    {
        return [
            'jobs_processed' => $this->jobsProcessed,
            'runtime_seconds' => time() - $this->startTime,
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];
    }

    /**
     * Xử lý công việc thất bại bằng cách release hoặc lưu vào failed_jobs
     *
     * @param array $job Dữ liệu công việc
     * @param string|null $exception Thông báo lỗi (nếu có)
     */
    protected function handleFailedJob(array $job, ?string $exception = null): void
    {
        global $db, $db_config;

        // Xóa khỏi Redis processing queue
        $this->removeFromProcessingQueue($job);

        if (!isset($job['__db_id'])) {
            return;
        }

        $id = (int) $job['__db_id'];
        $attempts = $job['attempts'] ?? 1;

        if ($attempts < $this->maxAttempts) {
            // Release công việc trở lại hàng đợi
            $tableName = ($db_config['prefix'] ?? 'nv4') . '_queue_jobs';

            // Delay 30s * attempts
            $delay = 30 * $attempts;
            $availableAt = time() + $delay;

            try {
                $db->query("UPDATE " . $tableName . " SET reserved_at = NULL, available_at = " . $availableAt . " WHERE id = " . $id);
                $this->log("Released job {$id} back to queue for attempt " . ($attempts + 1) . " in {$delay}s");
            } catch (\Exception $e) {
                $this->log("Failed to release job {$id}: " . $e->getMessage(), 'error');
            }
        } else {
            // Đã đạt số lần thử tối đa - lưu vào failed_jobs
            $this->log("Job {$id} exceeded max attempts ({$this->maxAttempts}). Moving to failed_jobs.", 'error');
            $this->moveToFailedJobs($job, $exception);
            $this->deleteJobFromDatabase($id);
        }
    }

    /**
     * Lưu công việc thất bại vào bảng failed_jobs
     *
     * @param array $job Dữ liệu công việc
     * @param string|null $exception Thông báo lỗi
     */
    protected function moveToFailedJobs(array $job, ?string $exception = null): void
    {
        global $db, $db_config;

        $tableName = ($db_config['prefix'] ?? 'nv4') . '_queue_failed_jobs';

        // Loại bỏ các trường nội bộ trước khi lưu
        $payload = $job;
        unset($payload['__db_id'], $payload['__raw_data'], $payload['attempts']);

        try {
            $exceptionMsg = $exception ?? 'Exceeded max attempts (' . $this->maxAttempts . ')';
            $sql = "INSERT INTO " . $tableName . " (queue, payload, exception, failed_at) VALUES (:queue, :payload, :exception, :failed_at)";
            $db->insert_id($sql, 'id', [
                'queue' => 'default',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'exception' => $exceptionMsg,
                'failed_at' => time(),
            ]);
        } catch (\Exception $e) {
            // Bảng có thể chưa tồn tại (phiên bản cũ), log lỗi
            $this->log("Failed to move job to failed_jobs table: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Xóa công việc khỏi cơ sở dữ liệu (sau khi xử lý thành công)
     */
    protected function deleteJobFromDatabase(int $id): void
    {
        global $db, $db_config;
        $tableName = ($db_config['prefix'] ?? 'nv4') . '_queue_jobs';
        try {
            $db->query("DELETE FROM " . $tableName . " WHERE id = " . $id);
        } catch (\Exception $e) {
            $this->log("Failed to delete job {$id} from database: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Vòng lặp chạy chính - được triển khai bởi các lớp con
     *
     * LinuxWorker: Sử dụng pcntl_fork cho mỗi công việc
     * WindowsWorker: Sử dụng vòng lặp với các giới hạn
     */
    abstract public function run(): void;
}
