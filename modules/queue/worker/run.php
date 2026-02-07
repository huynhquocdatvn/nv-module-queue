<?php

/**
 * Hệ thống Queue NukeViet - Điểm vào CLI
 *
 * @version 1.0
 * @author Huỳnh Quốc Đạt <work@hqd.vn>
 * @website https://huynhquocdat.vn
 * @copyright (C) 2026 Huỳnh Quốc Đạt. All rights reserved
 * @license GNU/GPL version 2 or any later version
 *
 * Sử dụng:
 *   php worker/run.php [options]
 *
 * Tùy chọn:
 *   --help          Hiển thị thông báo trợ giúp này
 *   --debug         Bật ghi log debug
 *   --max-jobs=N    Số lượng công việc tối đa cần xử lý (mặc định: 50)
 *   --max-time=N    Thời gian chạy tối đa tính bằng giây (mặc định: 3600)
 *   --max-memory=N  Bộ nhớ tối đa tính bằng MB (mặc định: 100)
 */

declare(strict_types=1);

// Đảm bảo chạy từ CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Kịch bản này chỉ có thể được chạy từ dòng lệnh.');
}

// Phân tích các tùy chọn dòng lệnh
$options = getopt('h', [
    'help',
    'debug',
    'max-jobs:',
    'max-time:',
    'max-memory:',
]);

// Hiển thị trợ giúp
if (isset($options['h']) || isset($options['help'])) {
    echo <<<HELP
NukeViet Queue Worker v1.0

Sử dụng:
  php worker/run.php [options]

Tùy chọn:
  -h, --help          Hiển thị thông báo trợ giúp này
  --debug             Bật ghi log debug
  --max-jobs=N        Số lượng công việc tối đa cần xử lý trước khi thoát (mặc định: 50)
  --max-time=N        Thời gian chạy tối đa tính bằng giây trước khi thoát (mặc định: 3600)
  --max-memory=N      Sử dụng bộ nhớ tối đa tính bằng MB trước khi thoát (mặc định: 100)

Ví dụ:
  php worker/run.php
  php worker/run.php --max-jobs=100 --max-time=7200
  php worker/run.php --debug

Để deploy Linux production, hãy sử dụng Supervisor hoặc systemd để quản lý worker.
Đối với Windows/XAMPP, sử dụng NSSM hoặc Windows Task Scheduler để tự động khởi động lại.

HELP;
    exit(0);
}

// Kích hoạt chế độ debug
if (isset($options['debug'])) {
    define('NV_QUEUE_DEBUG', true);
}

/**
 * Bootstrap môi trường NukeViet.
 * Điều này tải config.php, mainfile.php và khởi tạo $db, $site_mods, v.v.
 */
require __DIR__ . '/bootstrap.php';

use NukeViet\Module\queue\worker\LinuxWorker;
use NukeViet\Module\queue\worker\WindowsWorker;

/**
 * Phát hiện lớp worker phù hợp dựa trên môi trường.
 *
 * - Nếu extension pcntl có sẵn: Sử dụng LinuxWorker (dựa trên fork)
 * - Ngược lại: Sử dụng WindowsWorker (dựa trên vòng lặp)
 */
function detectWorkerClass(): string
{
    // Kiểm tra extension pcntl (chỉ Linux/Unix)
    if (extension_loaded('pcntl') && function_exists('pcntl_fork')) {
        // Kiểm tra bổ sung: Đảm bảo chúng ta đang ở trên hệ thống giống Unix
        if (DIRECTORY_SEPARATOR === '/') {
            return LinuxWorker::class;
        }
    }

    // Dự phòng sang Windows worker
    return WindowsWorker::class;
}

/**
 * Thực thi chính
 */
try {
    echo "============================================\n";
    echo "  NukeViet Queue Worker v1.0\n";
    echo "============================================\n";
    echo "Started at: " . date('Y-m-d H:i:s') . "\n";
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "OS: " . PHP_OS . "\n";
    echo "============================================\n\n";

    // Phát hiện và khởi tạo worker
    $workerClass = detectWorkerClass();
    echo "Using worker: {$workerClass}\n\n";

    /** @var \NukeViet\Module\queue\worker\AbstractWorker $worker */
    $worker = new $workerClass();

    // Áp dụng các tùy chọn dòng lệnh
    if (isset($options['max-jobs'])) {
        $reflection = new \ReflectionProperty($workerClass, 'maxJobs');
        $reflection->setAccessible(true);
        $reflection->setValue($worker, (int) $options['max-jobs']);
    }

    if (isset($options['max-time'])) {
        $reflection = new \ReflectionProperty($workerClass, 'maxRuntime');
        $reflection->setAccessible(true);
        $reflection->setValue($worker, (int) $options['max-time']);
    }

    if (isset($options['max-memory'])) {
        $reflection = new \ReflectionProperty($workerClass, 'maxMemory');
        $reflection->setAccessible(true);
        $reflection->setValue($worker, (int) $options['max-memory'] * 1048576);
    }

    // Chạy worker
    $worker->run();

    echo "\n============================================\n";
    echo "Worker stopped at: " . date('Y-m-d H:i:s') . "\n";
    echo "============================================\n";

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "============================================\n");
    fwrite(STDERR, "  FATAL ERROR\n");
    fwrite(STDERR, "============================================\n");
    fwrite(STDERR, "Message: " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    fwrite(STDERR, "\nStack Trace:\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    fwrite(STDERR, "============================================\n");

    exit(1);
}
