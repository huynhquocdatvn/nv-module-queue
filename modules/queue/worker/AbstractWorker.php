<?php

/**
 * Hệ thống Queue NukeViet - Worker trừu tượng
 *
 * @version 1.0
 * @author AI Assistant
 * @copyright (C) 2026 VINADES.,JSC. All rights reserved
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
     * @var object|null
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
            
            $this->connectRedis();
        } else {
             $this->log("Using Database Queue Driver");
             $this->queueName = 'default';
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

            // Thêm mật khẩu nếu được cấu hình
            if (!empty($this->redisConfig['password'])) {
                $options['password'] = $this->redisConfig['password'];
            }

            // Thêm lựa chọn database nếu được cấu hình
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
     * Kết nối lại cơ sở dữ liệu trước khi xử lý một công việc.
     *
     * QUAN TRỌNG: Phương thức này PHẢI được gọi trước khi xử lý bất kỳ công việc nào.
     * Các tiến trình CLI chạy lâu sẽ gặp lỗi "MySQL has gone away"
     * nếu cùng một kết nối được giữ mở trong thời gian dài.
     *
     * @return Database Instance kết nối cơ sở dữ liệu mới
     */
    protected function reconnectDatabase(): Database
    {
        global $db, $db_config;

        // Đóng kết nối hiện tại nếu có
        if ($db instanceof Database) {
            $db = null;
        }

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
     * Trong ngữ cảnh CLI, $site_mods có thể không được tải đầy đủ bởi mainfile.php.
     * Khi $site_mods không khả dụng, chúng ta giả định công việc đã được xác thực
     * tại thời điểm dispatch và cho phép nó tiếp tục.
     *
     * @param string $module Tên module
     * @return bool True nếu module hoạt động hoặc không thể xác minh
     */
    protected function isModuleActive(string $module): bool
    {
        global $site_mods;

        // Nếu site_mods không khả dụng (ngữ cảnh CLI), giả định module hợp lệ
        // Công việc đã được xác thực tại thời điểm dispatch
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
     * Lấy công việc từ Redis
     */
    protected function popJobFromRedis(int $timeout = 5): ?array
    {
        try {
            // Sử dụng BLPOP để lấy với timeout (blocking)
            $result = $this->redis->blpop([$this->queueName], $timeout);

            if ($result === null) {
                return null;
            }

            // BLPOP trả về [key, value]
            $jobData = $result[1] ?? null;

            if ($jobData === null) {
                return null;
            }

            $job = json_decode($jobData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("Invalid JSON in job data: " . json_last_error_msg(), 'error');
                return null;
            }

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
        if (!is_object($db) || empty($db->connect)) {
            $this->reconnectDatabase();
        }

        try {
            // Sử dụng atomic UPDATE để đặt trước công việc (đơn giản hơn transaction/locking cho MySQL)
            // Hoạt động cho cả MyISAM (mặc dù không khuyến khích)
            
            // 1. Tìm một công việc
            // Sử dụng timestamp hiện tại
            $now = time();
            
            // Chúng ta cần một cách để đặt trước một cách nguyên tử (atomic).
            // Cách 1: Locking Read (SELECT FOR UPDATE) - Yêu cầu InnoDB
            // Cách 2: Atomic Update với LIMIT 1 (MySQL specific)
            
            // Hãy sử dụng Cách 2:
            // UPDATE table SET reserved_at = ?, attempts = attempts + 1 WHERE reserved_at IS NULL ORDER BY id ASC LIMIT 1
            // Nhưng chúng ta cần biết công việc NÀO đã được cập nhật để lấy nó.
            // Với driver PDO/MySQL thuần trong PHP, điều này khó khăn nếu không có transaction.
            
            // Cách đơn giản nhất: Transaction
            if ($db_config['dbtype'] == 'mysql' || $db_config['dbtype'] == 'mariadb') {
                $db->query('START TRANSACTION');
                
                $sql = "SELECT id, payload, attempts FROM " . $tableName . " WHERE reserved_at IS NULL AND available_at <= " . $now . " ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED"; 
                // SKIP LOCKED rất tuyệt nhưng yêu cầu MySQL 8.0+ / MariaDB 10.6+
                // Fallback cho các phiên bản cũ hơn: đơn giản là FOR UPDATE
                $result = $db->query(str_replace(' SKIP LOCKED', '', $sql)); 
                
                $row = $result->fetch();
                
                if ($row) {
                    $jobId = $row['id'];
                    $payload = $row['payload'];
                    
                    // Đặt trước nó
                    $db->query("UPDATE " . $tableName . " SET reserved_at = " . $now . ", attempts = attempts + 1 WHERE id = " . $jobId);
                    
                    $db->query('COMMIT');
                    
                    $job = json_decode($payload, true);
                     if (json_last_error() !== JSON_ERROR_NONE) {
                        // Đánh dấu là lỗi/xóa?
                        $db->query("DELETE FROM " . $tableName . " WHERE id = " . $jobId);
                        return null;
                    }
                    
                    // Thêm DB ID vào công việc để xóa sau này
                    $job['__db_id'] = $jobId;
                    $job['attempts'] = (int) $row['attempts'];
                    
                    return $job;
                }
                
                $db->query('COMMIT');
            }
            
            // Nếu không tìm thấy công việc, ngủ một chút để tránh CPU quay vòng (polling)
            usleep(1000000); // 1 second
            
            return null;

        } catch (\Exception $e) {
            $this->log("Error popping job from database: " . $e->getMessage(), 'error');
            // Thử kết nối lại lần sau
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
            return false;
        }

        // Kiểm tra xem module có hoạt động không
        if (!$this->isModuleActive($module)) {
            $this->log("Module '{$module}' is not active, skipping job", 'warning');
            return false;
        }

        // Kết nối lại cơ sở dữ liệu trước khi xử lý
        try {
            $this->reconnectDatabase();
        } catch (\Exception $e) {
            $this->log("Database reconnection failed: " . $e->getMessage(), 'error');
            return false;
        }

        // Giải quyết lớp handler
        // Định dạng mong đợi: Jobs\ClassName hoặc namespace đầy đủ
        $handlerClass = $this->resolveHandlerClass($module, $handler);

        if (!class_exists($handlerClass)) {
            $this->log("Handler class not found: {$handlerClass}", 'error');
            return false;
        }

        if (!method_exists($handlerClass, 'handle')) {
            $this->log("Handler class {$handlerClass} does not have a handle() method", 'error');
            return false;
        }

        // Thực thi công việc
        try {
            $startTime = microtime(true);
            $result = $handlerClass::handle($data);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result) {
                $this->log("Job {$jobId} completed in {$duration}ms");
                
                // Nếu là driver database, xóa công việc sau khi hoàn thành
                if (isset($job['__db_id'])) {
                    $this->deleteJobFromDatabase($job['__db_id']);
                }
            } else {
                $this->log("Job {$jobId} returned false after {$duration}ms", 'warning');
                if (isset($job['__db_id'])) {
                    $this->handleFailedJob($job);
                }
            }

            return (bool) $result;
        } catch (\Throwable $e) {
            $this->log("Job {$jobId} failed: " . $e->getMessage(), 'error');
            $this->log("Stack trace: " . $e->getTraceAsString(), 'debug');
            
            if (isset($job['__db_id'])) {
                $this->handleFailedJob($job);
            }
            
            return false;
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
        // Nếu handler đã chứa namespace, sử dụng nguyên trạng
        if (str_contains($handler, '\\')) {
            // Nếu nó bắt đầu bằng NukeViet, giả định nó là đầy đủ
            if (str_starts_with($handler, 'NukeViet\\')) {
                return $handler;
            }
            // Ngược lại, thêm namespace của module vào trước
            return "NukeViet\\Module\\{$module}\\{$handler}";
        }

        // Mặc định: giả định handler nằm trong namespace Jobs của module
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

        // Kiểm tra giới hạn thời qian chạy
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
     * Handler tắt gracefull
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
     * Xử lý công việc thất bại bằng cách release hoặc xóa
     */
    protected function handleFailedJob(array $job): void
    {
        global $db, $db_config;
        
        $id = $job['__db_id'];
        // Lưu ý: attempts đã được tăng trong popJob khi đặt trước
        $attempts = $job['attempts'] ?? 1;
        
        // Tăng attempts cho lần kiểm tra tiếp theo (vì DB đã có attempts+1)
        // Chờ đã, cột 'attempts' trong DB lưu số lần nó đã được pop.
        // Nếu giá trị hiện tại là 1, nghĩa là đây là lần thử đầu tiên.
        // Nếu chúng ta release nó, chúng ta muốn nó được lấy lại lần nữa.
        
        $maxAttempts = 3; // Số lần thử tối đa có thể cấu hình
        
        if ($attempts < $maxAttempts) {
            // Release công việc trở lại hàng đợi
            $tableName = ($db_config['prefix'] ?? 'nv4') . '_queue_jobs';
            
            // Delay 30s * attempts
            $delay = 30 * $attempts;
            $availableAt = time() + $delay;
            
            try {
                $db->query("UPDATE " . $tableName . " SET reserved_at = NULL, available_at = " . $availableAt . " WHERE id = " . $id);
                $this->log("Released job {$id} back to queue for attempt " . ($attempts + 1) . " in {$delay}s", 'info');
            } catch (\Exception $e) {
                $this->log("Failed to release job {$id}: " . $e->getMessage(), 'error');
            }
        } else {
            // Đã đạt số lần thử tối đa - xóa công việc
            $this->log("Job {$id} exceeded max attempts ({$maxAttempts}). Deleting job.", 'error');
            $this->deleteJobFromDatabase($id);
        }
    }

    /**
     * Xóa công việc khỏi cơ sở dữ liệu (sau khi xử lý thành công)
     */
    protected function deleteJobFromDatabase($id): void
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
