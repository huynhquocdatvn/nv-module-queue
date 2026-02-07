<?php

/**
 * Hệ thống Queue NukeViet - Các hàm Dispatcher
 *
 * @version 1.0
 * @author Huỳnh Quốc Đạt <work@hqd.vn>
 * @website https://huynhquocdat.vn
 * @copyright (C) 2026 Huỳnh Quốc Đạt. All rights reserved
 * @license GNU/GPL version 2 or any later version
 *
 * Tệp này chứa hàm dispatcher để đẩy các công việc vào hàng đợi
 * hoặc xử lý chúng đồng bộ bằng kỹ thuật "Fire and Forget".
 */

if (!defined('NV_MAINFILE')) {
    exit('Stop!!!');
}

/**
 * Dispatch một công việc vào hệ thống hàng đợi.
 *
 * Hàm này hỗ trợ hai chế độ dựa trên cấu hình queue:
 * - Chế độ Async: Đẩy công việc vào Redis/Database để xử lý nền
 * - Chế độ Sync Fallback: Sử dụng register_shutdown_function để xử lý sau khi phản hồi
 *
 * @param string $module Tên module (ví dụ: 'news', 'users')
 * @param string $handler Tên lớp handler (ví dụ: 'SendEmail', 'Jobs\NotifyUser')
 * @param array $data Dữ liệu công việc để chuyển cho handler
 * @param int $priority Mức ưu tiên công việc (thấp hơn = ưu tiên cao hơn, sử dụng trong tương lai)
 * @return bool True nếu công việc được dispatch thành công
 */
function nv_dispatch_job(string $module, string $handler, array $data = [], int $priority = 0): bool
{
    global $site_mods, $db, $db_config;

    // Xác thực module tồn tại (kiểm tra mềm - worker sẽ xác thực lại)
    if (isset($site_mods) && is_array($site_mods) && !empty($site_mods)) {
        if (!isset($site_mods[$module])) {
            trigger_error("nv_dispatch_job: Module '{$module}' not found or not active", E_USER_WARNING);
            return false;
        }
    }

    // Xây dựng payload công việc
    $job = [
        'id' => uniqid('job_', true),
        'module' => $module,
        'handler' => $handler,
        'data' => $data,
        'priority' => $priority,
        'created_at' => time(),
        'created_by' => defined('NV_CLIENT_IP') ? NV_CLIENT_IP : 'system',
    ];

    // Lấy cấu hình từ database
    $queue_config = nv_queue_get_config();
    $useQueue = !empty($queue_config['active']);

    if ($useQueue) {
        $driver = $queue_config['driver'] ?? 'database';

        if ($driver === 'database') {
            return nv_dispatch_job_database($job);
        }

        // Chế độ Redis Async
        return nv_dispatch_job_async($job, $queue_config);
    }

    // Chế độ Sync Fallback với shutdown function
    return nv_dispatch_job_sync($job);
}

/**
 * Đẩy công việc vào hàng đợi Database
 *
 * @param array $job Payload công việc
 * @return bool True nếu đẩy thành công
 */
function nv_dispatch_job_database(array $job): bool
{
    global $db, $db_config;

    if (!is_object($db)) {
        trigger_error('nv_dispatch_job: Database connection not available', E_USER_WARNING);
        return false;
    }

    $tableName = ($db_config['prefix'] ?? 'nv4') . '_queue_jobs';

    $payload = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $createdAt = time();
    $availableAt = time();

    $sql = "INSERT INTO " . $tableName . " (queue, payload, attempts, reserved_at, available_at, created_at) VALUES (:queue, :payload, 0, NULL, :available_at, :created_at)";

    $dataInsert = [
        'queue' => 'default',
        'payload' => $payload,
        'available_at' => $availableAt,
        'created_at' => $createdAt
    ];

    try {
        $result = $db->insert_id($sql, 'id', $dataInsert);
        return $result > 0;
    } catch (\Throwable $e) {
        // Thử tạo bảng nếu nó không tồn tại
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            try {
                $createSql = "CREATE TABLE IF NOT EXISTS " . $tableName . " (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    queue VARCHAR(255) NOT NULL DEFAULT 'default',
                    payload LONGTEXT NOT NULL,
                    attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
                    reserved_at INT(10) UNSIGNED DEFAULT NULL,
                    available_at INT(10) UNSIGNED NOT NULL,
                    created_at INT(10) UNSIGNED NOT NULL,
                    PRIMARY KEY (id),
                    KEY queue_reserved_available (queue, reserved_at, available_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
                $db->query($createSql);

                // Thử lại insert
                $result = $db->insert_id($sql, 'id', $dataInsert);
                return $result > 0;
            } catch (\Throwable $ex) {
                trigger_error('nv_dispatch_job: Failed to create table or insert job to Database: ' . $ex->getMessage(), E_USER_WARNING);
                return false;
            }
        }

        trigger_error('nv_dispatch_job: Failed to insert job to Database: ' . $e->getMessage(), E_USER_WARNING);
        return false;
    }
}

/**
 * Lấy hoặc tạo kết nối Redis dùng chung cho request hiện tại
 *
 * @param array $queue_config Cấu hình queue
 * @return \Predis\Client|null
 */
function nv_queue_get_redis(array $queue_config): ?\Predis\Client
{
    static $redis = null;

    if ($redis !== null) {
        return $redis;
    }

    if (empty($queue_config['redis_host'])) {
        trigger_error(
            'nv_dispatch_job: Redis configuration is not defined. Please configure Redis in Queue module admin.',
            E_USER_WARNING
        );
        return null;
    }

    if (!class_exists('\\Predis\\Client')) {
        trigger_error(
            'nv_dispatch_job: Predis library is not installed. Unable to use Redis queue.',
            E_USER_WARNING
        );
        return null;
    }

    $options = [
        'scheme' => 'tcp',
        'host' => $queue_config['redis_host'] ?? '127.0.0.1',
        'port' => (int) ($queue_config['redis_port'] ?? 6379),
    ];

    if (!empty($queue_config['redis_pass'])) {
        $options['password'] = $queue_config['redis_pass'];
    }

    if (isset($queue_config['redis_db'])) {
        $options['database'] = (int) $queue_config['redis_db'];
    }

    try {
        $redis = new \Predis\Client($options);
        return $redis;
    } catch (\Throwable $e) {
        trigger_error('nv_queue_get_redis: Failed to connect to Redis: ' . $e->getMessage(), E_USER_WARNING);
        return null;
    }
}

/**
 * Đẩy công việc vào hàng đợi Redis (Chế độ Async)
 *
 * @param array $job Payload công việc
 * @param array $queue_config Cấu hình queue
 * @return bool True nếu đẩy thành công
 */
function nv_dispatch_job_async(array $job, array $queue_config = []): bool
{
    if (empty($queue_config)) {
        $queue_config = nv_queue_get_config();
    }

    $redis = nv_queue_get_redis($queue_config);
    if ($redis === null) {
        return false;
    }

    try {
        $queueName = ($queue_config['redis_prefix'] ?? 'nv_queue_') . 'jobs';
        $payload = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $redis->rpush($queueName, [$payload]);

        return true;
    } catch (\Throwable $e) {
        trigger_error('nv_dispatch_job: Failed to push job to Redis: ' . $e->getMessage(), E_USER_WARNING);
        return false;
    }
}

/**
 * Xử lý công việc đồng bộ bằng register_shutdown_function.
 *
 * Phương thức này đăng ký công việc để chạy sau khi PHP hoàn tất
 * gửi response cho client, không can thiệp vào output buffer hiện tại.
 *
 * @param array $job Payload công việc
 * @return bool True nếu đăng ký thành công
 */
function nv_dispatch_job_sync(array $job): bool
{
    // Giải quyết lớp handler
    $module = $job['module'];
    $handler = $job['handler'];
    $data = $job['data'];

    // Xây dựng tên lớp đầy đủ
    $handlerClass = nv_queue_resolve_handler($module, $handler);

    // Xác minh handler tồn tại
    if (!class_exists($handlerClass)) {
        trigger_error("nv_dispatch_job: Handler class not found: {$handlerClass}", E_USER_WARNING);
        return false;
    }

    if (!method_exists($handlerClass, 'handle')) {
        trigger_error("nv_dispatch_job: Handler class {$handlerClass} does not have handle() method", E_USER_WARNING);
        return false;
    }

    // Đăng ký xử lý sau khi response hoàn tất
    register_shutdown_function(function () use ($handlerClass, $data) {
        // Đóng session để không chặn request khác
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Kết thúc request cho client nếu dùng PHP-FPM
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            $handlerClass::handle($data);
        } catch (\Throwable $e) {
            trigger_error("nv_dispatch_job sync error: " . $e->getMessage(), E_USER_WARNING);
        }
    });

    return true;
}

/**
 * Giải quyết tên lớp handler đầy đủ từ tên ngắn
 *
 * @param string $module Tên module
 * @param string $handler Tên handler
 * @return string Tên lớp đầy đủ
 */
function nv_queue_resolve_handler(string $module, string $handler): string
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
 * Kiểm tra xem hệ thống hàng đợi có được bật không.
 *
 * @return bool True nếu hàng đợi được bật
 */
function nv_queue_enabled(): bool
{
    $config = nv_queue_get_config();
    return !empty($config['active']);
}

/**
 * Lấy thống kê hàng đợi (Redis/Database).
 *
 * @return array Thống kê hàng đợi
 */
function nv_queue_stats(): array
{
    global $db, $db_config;

    $queue_config = nv_queue_get_config();
    $driver = $queue_config['driver'] ?? 'database';

    if ($driver === 'database') {
        if (!is_object($db)) {
            $db = new \NukeViet\Core\Database($db_config);
        }
        $tableName = ($db_config['prefix'] ?? 'nv4') . '_queue_jobs';
        try {
            $sql = "SELECT COUNT(*) FROM " . $tableName . " WHERE reserved_at IS NULL";
            $result = $db->query($sql);
            $count = $result->fetchColumn();
            return [
                'queue_driver' => 'database',
                'queue_name' => 'default',
                'pending_jobs' => $count,
                'redis_connected' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'queue_driver' => 'database',
                'error' => $e->getMessage()
            ];
        }
    }

    // Redis stats
    $redis = nv_queue_get_redis($queue_config);
    if ($redis === null) {
        return [
            'queue_driver' => 'redis',
            'error' => 'Redis not configured or Predis not installed',
            'redis_connected' => false,
        ];
    }

    try {
        $queueName = ($queue_config['redis_prefix'] ?? 'nv_queue_') . 'jobs';
        return [
            'queue_driver' => 'redis',
            'queue_name' => $queueName,
            'pending_jobs' => $redis->llen($queueName),
            'redis_connected' => true,
        ];
    } catch (\Throwable $e) {
        return [
            'queue_driver' => 'redis',
            'error' => $e->getMessage(),
            'redis_connected' => false,
        ];
    }
}

/**
 * Lấy cấu hình hệ thống hàng đợi từ database.
 *
 * @return array Cấu hình hàng đợi
 */
function nv_queue_get_config(): array
{
    global $db, $db_config;

    static $cache_config = null;
    if ($cache_config !== null) {
        return $cache_config;
    }

    $module_name = 'queue';
    $config = [];
    try {
        $sql = "SELECT config_name, config_value FROM " . $db_config['prefix'] . "_config WHERE lang='sys' AND module='" . $module_name . "'";
        $result = $db->query($sql);
        while ($row = $result->fetch()) {
            $config[$row['config_name']] = $row['config_value'];
        }
    } catch (\Throwable $e) {
        // Fallback to defaults
    }

    $cache_config = array_merge([
        'active' => 0,
        'driver' => 'database',
        'redis_host' => '127.0.0.1',
        'redis_port' => 6379,
        'redis_pass' => '',
        'redis_db' => 0,
        'redis_prefix' => 'nv_queue_',
    ], $config);

    return $cache_config;
}
