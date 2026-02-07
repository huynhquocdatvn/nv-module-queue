<?php

/**
 * Hệ thống Queue NukeViet - Bootstrap
 *
 * @version 1.0
 * @author Huỳnh Quốc Đạt <work@hqd.vn>
 * @website https://huynhquocdat.vn
 * @copyright (C) 2026 Huỳnh Quốc Đạt. All rights reserved
 * @license GNU/GPL version 2 or any later version
 *
 * QUAN TRỌNG: Tệp này khởi tạo môi trường NukeViet cho các tiến trình worker CLI.
 * Nó phải định nghĩa đúng NV_ROOTDIR để mainfile.php có thể định vị config.php tại gốc.
 */

declare(strict_types=1);

// Ngăn chặn truy cập web trực tiếp - script này chỉ dành cho CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script này chỉ có thể được chạy từ dòng lệnh.');
}

/**
 * Hằng số NV_SYSTEM thông báo cho mainfile.php rằng đây là script cấp hệ thống.
 * Điều này bỏ qua việc chuyển hướng đến index.php ở đầu mainfile.php.
 */
define('NV_SYSTEM', true);

/**
 * NV_ROOTDIR phải trỏ đến thư mục cha của /worker/
 * Vì tệp này nằm trong /src/worker/bootstrap.php,
 * dirname(__DIR__) sẽ trả về /src/ chính là gốc NukeViet.
 */
define('NV_ROOTDIR', dirname(__DIR__, 3));

/**
 * Giả lập các biến $_SERVER được yêu cầu bởi NukeViet core.
 * Những biến này thường được đặt bởi web server nhưng bị thiếu trong ngữ cảnh CLI.
 * mainfile.php và các phụ thuộc của nó mong đợi chúng tồn tại.
 */
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'NukeViet Queue Worker';
$_SERVER['REQUEST_TIME'] = $_SERVER['REQUEST_TIME'] ?? time();
$_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? '/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'] ?? NV_ROOTDIR . '/index.php';
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? NV_ROOTDIR;

/**
 * Include Composer autoloader.
 * Cái này phải được tải trước mainfile.php để đảm bảo tất cả các class đều có sẵn.
 */
$autoloadPath = NV_ROOTDIR . '/includes/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Composer autoload not found at: {$autoloadPath}\n");
    fwrite(STDERR, "Please run 'composer install' in the includes/ directory.\n");
    exit(1);
}
require $autoloadPath;

/**
 * TẢI TRƯỚC CẤU HÌNH DATABASE
 *
 * mainfile.php gọi `unset($db_config['dbpass'])` sau khi thiết lập
 * kết nối DB ban đầu vì lý do bảo mật. Tuy nhiên, worker cần
 * mật khẩu để kết nối lại database cho mỗi công việc.
 *
 * Giải pháp: Sử dụng token_get_all để parse PHP an toàn, xử lý được
 * các trường hợp password có ký tự đặc biệt như quotes, escaped chars.
 */
$configPath = NV_ROOTDIR . '/config.php';
$saved_dbpass = null;
if (file_exists($configPath)) {
    $content = file_get_contents($configPath);
    $tokens = token_get_all($content);
    $tokenCount = count($tokens);

    for ($i = 0; $i < $tokenCount; $i++) {
        // Tìm pattern: $db_config['dbpass'] = 'value';
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$db_config') {
            continue;
        }

        // Kiểm tra ['dbpass']
        $j = $i + 1;
        // Bỏ qua whitespace
        while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $tokenCount || $tokens[$j] !== '[') {
            continue;
        }
        $j++;
        // Bỏ qua whitespace
        while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $tokenCount || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $key = trim($tokens[$j][1], "\"'");
        if ($key !== 'dbpass') {
            continue;
        }
        $j++;
        // Bỏ qua whitespace và ]
        while ($j < $tokenCount && (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE)) {
            $j++;
        }
        if ($j >= $tokenCount || $tokens[$j] !== ']') {
            continue;
        }
        $j++;
        // Bỏ qua whitespace và =
        while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $tokenCount || $tokens[$j] !== '=') {
            continue;
        }
        $j++;
        // Bỏ qua whitespace
        while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        // Lấy giá trị string
        if ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
            $rawValue = $tokens[$j][1];
            // Xử lý escape sequences tùy theo quote type
            $quote = $rawValue[0];
            $inner = substr($rawValue, 1, -1);
            if ($quote === '"') {
                // Double quotes: xử lý escape sequences
                $saved_dbpass = stripcslashes($inner);
            } else {
                // Single quotes: chỉ xử lý \' và \\
                $saved_dbpass = str_replace(["\\'" , "\\\\"], ["'", "\\"], $inner);
            }
            break;
        }
    }
}

/**
 * Include file bootstrap chính của NukeViet.
 * Việc này sẽ:
 * - Tải config.php từ NV_ROOTDIR (lại một lần nữa, nhưng NV_MAINFILE ngăn chặn việc require lại)
 * - Khởi tạo kết nối database ($db)
 * - Tải cấu hình toàn cục ($global_config)
 * - Khởi tạo các module của site ($site_mods)
 */
$mainfilePath = NV_ROOTDIR . '/includes/mainfile.php';
if (!file_exists($mainfilePath)) {
    fwrite(STDERR, "Error: mainfile.php not found at: {$mainfilePath}\n");
    exit(1);
}
require $mainfilePath;

/**
 * KHÔI PHỤC MẬT KHẨU DATABASE
 * Khôi phục mật khẩu đã bị unset bởi mainfile.php
 * Điều này cho phép reconnectDatabase() hoạt động trong worker.
 */
if ($saved_dbpass !== null) {
    $db_config['dbpass'] = $saved_dbpass;
}
unset($saved_dbpass);


/**
 * Xuất thông báo thành công cho mục đích debug.
 */
if (defined('NV_QUEUE_DEBUG') && NV_QUEUE_DEBUG) {
    fwrite(STDOUT, "NukeViet Queue Bootstrap loaded successfully.\n");
    fwrite(STDOUT, "NV_ROOTDIR: " . NV_ROOTDIR . "\n");
    fwrite(STDOUT, "PHP Version: " . PHP_VERSION . "\n");
}
