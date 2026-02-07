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
 * Hàm này hỗ trợ hai chế độ dựa trên $global_config['sys_use_queue']:
 * - Chế độ 1 (Redis Async): Đẩy công việc vào Redis để xử lý nền
 * - Chế độ 0 (Sync Fallback): Sử dụng "Fire and Forget" để xử lý ngay sau khi phản hồi
 *
 * @param string $module Tên module (ví dụ: 'news', 'users')
 * @param string $handler Tên lớp handler (ví dụ: 'SendEmail', 'Jobs\NotifyUser')
 * @param array $data Dữ liệu công việc để chuyển cho handler
 * @param int $priority Mức ưu tiên công việc (thấp hơn = ưu tiên cao hơn, sử dụng trong tương lai)
 * @return bool True nếu công việc được dispatch thành công
 *
 * @example
 * // Dispatch một công việc gửi thông báo
 * nv_dispatch_job('news', 'SendNotification', [
 *     'article_id' => 123,
 *     'user_ids' => [1, 2, 3],
 * ]);
 */
function nv_dispatch_job(string $module, string $handler, array $data = [], int $priority = 0): bool
{
    global $site_mods, $db, $db_config;

    // Xác thực module tồn tại (kiểm tra mềm - worker sẽ xác thực lại)
    // Trong ngữ cảnh CLI hoặc khi dispatch async, $site_mods có thể chưa được tải đầy đủ
    // Worker sẽ thực hiện xác thực module thích hợp trước khi thực thi công việc
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

        // Chế độ 1: Redis Async (Mặc định)
        return nv_dispatch_job_async($job, $queue_config);
    } else {
        // Chế độ 0: Sync Fallback với Fire and Forget
        return nv_dispatch_job_sync($job);
    }
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
    
    // Đảm bảo bảng tồn tại (kiểm tra đơn giản để tránh overhead, thường nên được tạo bởi migration)
    // Chúng ta dựa vào khối catch để xử lý bảng bị thiếu nếu cần, hoặc tạo nó một lần.
    // Lý tưởng nhất là việc này nên được thực hiện trong cài đặt/cập nhật module.
    
    $payload = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $createdAt = time();
    $availableAt = time(); // Có thể hỗ trợ công việc bị trì hoãn sau này

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
 * Đẩy công việc vào hàng đợi Redis (Chế độ Async)
 *
 * @param array $job Payload công việc
 * @return bool True nếu đẩy thành công
 */
function nv_dispatch_job_async(array $job, array $queue_config = []): bool
{
    if (empty($queue_config)) {
        $queue_config = nv_queue_get_config();
    }

    // Xác thực cấu hình Redis
    if (empty($queue_config['redis_host'])) {
        trigger_error(
            'nv_dispatch_job: Redis configuration is not defined in database. ' .
            'Please configure Redis in Queue module admin.',
            E_USER_WARNING
        );
        return false;
    }

    if (!class_exists('\\Predis\\Client')) {
        trigger_error(
            'nv_dispatch_job: Predis library is not installed. Unable to use Redis queue.',
            E_USER_WARNING
        );
        return false;
    }

    try {
        // Tạo kết nối Redis
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

        $redis = new \Predis\Client($options);

        // Xây dựng tên hàng đợi
        $queueName = ($queue_config['redis_prefix'] ?? 'nv_queue_') . 'jobs';

        // Đẩy công việc vào hàng đợi
        $payload = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $redis->rpush($queueName, [$payload]);

        return true;
    } catch (\Throwable $e) {
        trigger_error('nv_dispatch_job: Failed to push job to Redis: ' . $e->getMessage(), E_USER_WARNING);
        return false;
    }
}

/**
 * Xử lý công việc đồng bộ bằng kỹ thuật "Fire and Forget".
 *
 * Phương thức này:
 * 1. Làm sạch bộ đệm đầu ra
 * 2. Gửi header phản hồi để đóng kết nối với client
 * 3. Sử dụng fastcgi_finish_request() nếu có sẵn
 * 4. Tiếp tục xử lý công việc sau khi kết nối đã đóng
 *
 * @param array $job Payload công việc
 * @return bool True nếu công việc được thực thi thành công
 */
function nv_dispatch_job_sync(array $job): bool
{
    // Giải quyết lớp handler
    $module = $job['module'];
    $handler = $job['handler'];
    $data = $job['data'];

    // Xây dựng tên lớp đầy đủ
    if (str_contains($handler, '\\')) {
        if (str_starts_with($handler, 'NukeViet\\')) {
            $handlerClass = $handler;
        } else {
            $handlerClass = "NukeViet\\Module\\{$module}\\{$handler}";
        }
    } else {
        $handlerClass = "NukeViet\\Module\\{$module}\\Jobs\\{$handler}";
    }

    // Xác minh handler tồn tại
    if (!class_exists($handlerClass)) {
        trigger_error("nv_dispatch_job: Handler class not found: {$handlerClass}", E_USER_WARNING);
        return false;
    }

    if (!method_exists($handlerClass, 'handle')) {
        trigger_error("nv_dispatch_job: Handler class {$handlerClass} does not have handle() method", E_USER_WARNING);
        return false;
    }

    // Sử dụng kỹ thuật Fire and Forget
    nv_fire_and_forget(function () use ($handlerClass, $data) {
        try {
            return $handlerClass::handle($data);
        } catch (\Throwable $e) {
            trigger_error("nv_dispatch_job sync error: " . $e->getMessage(), E_USER_WARNING);
            return false;
        }
    });

    return true;
}

/**
 * Thực thi một callback sau khi gửi phản hồi cho client.
 *
 * Điều này thực hiện mẫu "Fire and Forget":
 * 1. Làm sạch bộ đệm đầu ra và chuẩn bị phản hồi
 * 2. Gửi header phản hồi để đóng kết nối
 * 3. Sử dụng fastcgi_finish_request() nếu có sẵn (PHP-FPM)
 * 4. Tiếp tục chạy callback trong nền
 *
 * @param callable $callback Hàm để thực thi trong nền
 * @return void
 */
function nv_fire_and_forget(callable $callback): void
{
    // Cho phép script tiếp tục sau khi client ngắt kết nối
    ignore_user_abort(true);

    // Loại bỏ giới hạn thời gian
    set_time_limit(0);

    // Đóng session để ngăn chặn chặn các yêu cầu khác
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Làm sạch bộ đệm đầu ra
    $level = ob_get_level();
    for ($i = 0; $i < $level; $i++) {
        ob_end_clean();
    }

    // Bắt đầu bộ đệm đầu ra mới
    ob_start();

    // Body phản hồi tối thiểu
    echo json_encode(['status' => 'queued']);

    // Lấy độ dài nội dung
    $size = ob_get_length();

    // Gửi header để đóng kết nối
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . $size);
    header('Connection: close');

    // Flush và đóng kết nối
    ob_end_flush();

    if (function_exists('ob_flush')) {
        @ob_flush();
    }

    @flush();

    // Nếu sử dụng PHP-FPM, sử dụng fastcgi_finish_request để đóng kết nối đúng cách
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // Bây giờ thực thi callback trong nền
    // Client đã nhận phản hồi và kết nối đã đóng
    $callback();
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

    if (!isset($redis_config) || !is_array($redis_config)) {
        return ['error' => 'Redis not configured'];
    }

    if (!class_exists('\\Predis\\Client')) {
        return ['error' => 'Predis library not installed'];
    }

    try {
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

        $redis = new \Predis\Client($options);
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
        // Fallback or handle error
    }

    // Mặc định nếu không tìm thấy
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
