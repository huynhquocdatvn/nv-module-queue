# NukeViet Queue Module

Module Queue cho NukeViet CMS - Hệ thống xử lý tác vụ nền (Background Job Processing).

## Tổng quan

Module Queue cung cấp hệ thống hàng đợi để xử lý các tác vụ nặng một cách bất đồng bộ, giúp cải thiện hiệu suất website bằng cách không chặn request của người dùng.

### Tính năng chính

- **Hai chế độ xử lý:**
  - **Async Mode**: Đẩy job vào hàng đợi, worker xử lý nền
  - **Sync Mode (Fire & Forget)**: Xử lý ngay sau khi trả response cho client
- **Hai driver lưu trữ:**
  - **Database**: Sử dụng MySQL/MariaDB (không cần cài thêm)
  - **Redis**: Hiệu suất cao hơn (cần cài Redis + Predis)
- **Worker đa nền tảng:**
  - **LinuxWorker**: Sử dụng `pcntl_fork()` để xử lý đa tiến trình
  - **WindowsWorker**: Xử lý tuần tự với giới hạn tài nguyên

## Yêu cầu hệ thống

### Bắt buộc
- NukeViet 5.x
- PHP 8.2+
- MySQL 5.7+ hoặc MariaDB 10.3+

### Tùy chọn (cho Redis driver)
- Redis Server 5.0+
- Thư viện Predis: `composer require predis/predis`

### Tùy chọn (cho LinuxWorker)
- PHP extension: `pcntl`, `posix` (có sẵn trên Linux)

## Cài đặt

### Bước 1: Copy files

```bash
# Copy module
cp -r modules/queue/ /path/to/nukeviet/src/modules/

# Copy theme frontend (nếu có)
cp -r themes/default/modules/queue/ /path/to/nukeviet/src/themes/default/modules/

# Copy theme admin (nếu có)
cp -r themes/admin_future/modules/queue/ /path/to/nukeviet/src/themes/admin_future/modules/
```

### Bước 2: Cài đặt trong Admin

1. Đăng nhập Admin Panel
2. Vào **Quản lý modules** > **Cài đặt module**
3. Tìm module "Queue System" và cài đặt
4. Vào **Cấu hình module** để thiết lập

### Bước 3: Cài đặt Predis (nếu dùng Redis)

```bash
cd /path/to/nukeviet/src/includes
composer require predis/predis
```

## Cấu hình

### Cấu hình trong Admin

Vào **Admin** > **Queue System** > **Cấu hình**:

| Tùy chọn | Mô tả | Mặc định |
|----------|-------|----------|
| Kích hoạt Queue | Bật/tắt hệ thống hàng đợi | Tắt |
| Driver | `database` hoặc `redis` | database |
| Redis Host | Địa chỉ Redis server | 127.0.0.1 |
| Redis Port | Cổng Redis | 6379 |
| Redis Password | Mật khẩu Redis (nếu có) | (trống) |
| Redis Database | Index database Redis | 0 |
| Redis Prefix | Tiền tố key trong Redis | nv_queue_ |

### Cấu hình lưu trữ

Cấu hình được lưu trong bảng `{prefix}_config` với `module='queue'` và `lang='sys'`.

## Cơ chế hoạt động

### 1. Dispatch Job

Khi gọi `nv_dispatch_job()`, hệ thống kiểm tra cấu hình:

```
┌─────────────────────────────────────────────────────────────┐
│                    nv_dispatch_job()                        │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
              ┌─────────────────────────┐
              │  Queue được kích hoạt?   │
              └─────────────────────────┘
                     │            │
                    Yes          No
                     │            │
                     ▼            ▼
         ┌───────────────┐  ┌──────────────────┐
         │ Kiểm tra      │  │ Fire & Forget    │
         │ Driver        │  │ (Sync Mode)      │
         └───────────────┘  └──────────────────┘
              │      │              │
         database   redis          │
              │      │              │
              ▼      ▼              ▼
         ┌──────┐ ┌──────┐    ┌──────────────┐
         │MySQL │ │Redis │    │ Xử lý ngay   │
         │Queue │ │Queue │    │ sau response │
         └──────┘ └──────┘    └──────────────┘
```

### 2. Worker xử lý

Worker chạy liên tục, lấy job từ hàng đợi và xử lý:

```
┌─────────────────────────────────────────────────────────────┐
│                        Worker Loop                          │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
              ┌─────────────────────────┐
              │   Pop job từ Queue      │
              │  (BLPOP/SELECT)         │
              └─────────────────────────┘
                            │
                            ▼
              ┌─────────────────────────┐
              │  Reconnect Database     │
              │  (tránh MySQL gone away)│
              └─────────────────────────┘
                            │
                            ▼
              ┌─────────────────────────┐
              │  Resolve Handler Class  │
              │  NukeViet\Module\       │
              │  {module}\Jobs\{handler}│
              └─────────────────────────┘
                            │
                            ▼
              ┌─────────────────────────┐
              │  Execute handle($data)  │
              └─────────────────────────┘
                            │
                   success / failed
                     │          │
                     ▼          ▼
              ┌──────────┐ ┌──────────────┐
              │ Delete   │ │ Retry hoặc  │
              │ Job      │ │ Delete (3x)  │
              └──────────┘ └──────────────┘
```

### 3. Fire & Forget Mode

Khi Queue tắt, job được xử lý đồng bộ nhưng sau khi đã gửi response:

```php
// 1. Flush output buffer
// 2. Gửi header Connection: close
// 3. fastcgi_finish_request() nếu có
// 4. Client nhận response ngay
// 5. PHP tiếp tục xử lý job trong background
```

## Sử dụng

### Dispatch một Job

```php
// Cú pháp cơ bản
nv_dispatch_job('module_name', 'HandlerClass', ['key' => 'value']);

// Ví dụ: Gửi email thông báo
nv_dispatch_job('news', 'SendNotification', [
    'article_id' => 123,
    'user_ids' => [1, 2, 3],
]);

// Ví dụ: Xử lý ảnh
nv_dispatch_job('gallery', 'ProcessImage', [
    'image_path' => '/uploads/photo.jpg',
    'sizes' => ['thumb', 'medium', 'large'],
]);
```

### Tạo Job Handler

Tạo file `modules/{module}/Jobs/{HandlerName}.php`:

```php
<?php

namespace NukeViet\Module\news\Jobs;

class SendNotification
{
    /**
     * Xử lý job
     *
     * @param array $data Dữ liệu từ nv_dispatch_job()
     * @return bool True nếu thành công
     */
    public static function handle(array $data): bool
    {
        $articleId = $data['article_id'] ?? 0;
        $userIds = $data['user_ids'] ?? [];

        if (empty($articleId) || empty($userIds)) {
            return false;
        }

        // Xử lý logic gửi thông báo
        foreach ($userIds as $userId) {
            // Gửi notification...
        }

        return true;
    }
}
```

### Quy tắc đặt tên Handler

| Handler trong dispatch | Class được resolve |
|------------------------|-------------------|
| `SendEmail` | `NukeViet\Module\{module}\Jobs\SendEmail` |
| `Jobs\SendEmail` | `NukeViet\Module\{module}\Jobs\SendEmail` |
| `Tasks\ProcessData` | `NukeViet\Module\{module}\Tasks\ProcessData` |
| `NukeViet\Custom\Handler` | `NukeViet\Custom\Handler` |

## Chạy Worker

### Development

```bash
cd /path/to/nukeviet/src/modules/queue/worker
php run.php
```

### Các tùy chọn

```bash
php run.php --help                 # Xem trợ giúp
php run.php --debug                # Bật debug log
php run.php --max-jobs=100         # Xử lý tối đa 100 jobs
php run.php --max-time=7200        # Chạy tối đa 2 giờ
php run.php --max-memory=200       # Giới hạn 200MB RAM
```

### Production - Linux (Supervisor)

Tạo file `/etc/supervisor/conf.d/nukeviet-queue.conf`:

```ini
[program:nukeviet-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/nukeviet/src/modules/queue/worker/run.php --max-jobs=100
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/nukeviet-queue.log
stopwaitsecs=60
```

Khởi động:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start nukeviet-queue:*
```

### Production - Linux (systemd)

Tạo file `/etc/systemd/system/nukeviet-queue@.service`:

```ini
[Unit]
Description=NukeViet Queue Worker %i
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/nukeviet/src
ExecStart=/usr/bin/php modules/queue/worker/run.php --max-jobs=100
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Khởi động:

```bash
sudo systemctl daemon-reload
sudo systemctl enable nukeviet-queue@1
sudo systemctl start nukeviet-queue@1
```

### Production - Windows (NSSM)

1. Tải NSSM từ https://nssm.cc/
2. Mở CMD với quyền Admin:

```cmd
nssm install NukeVietQueue "C:\xampp\php\php.exe" "C:\xampp\htdocs\nukeviet\src\modules\queue\worker\run.php"
nssm set NukeVietQueue AppDirectory "C:\xampp\htdocs\nukeviet\src\modules\queue\worker"
nssm start NukeVietQueue
```

## Cấu trúc Database

### Bảng `{prefix}_queue_jobs`

```sql
CREATE TABLE {prefix}_queue_jobs (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL DEFAULT 'default',
    payload LONGTEXT NOT NULL,
    attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
    reserved_at INT(10) UNSIGNED DEFAULT NULL,
    available_at INT(10) UNSIGNED NOT NULL,
    created_at INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY queue_reserved_available (queue, reserved_at, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Cấu trúc Payload

```json
{
    "id": "job_65b8f7a3e4b01",
    "module": "news",
    "handler": "SendNotification",
    "data": {
        "article_id": 123,
        "user_ids": [1, 2, 3]
    },
    "priority": 0,
    "created_at": 1706547619,
    "created_by": "127.0.0.1"
}
```

## API Functions

### `nv_dispatch_job()`

Đẩy job vào hàng đợi.

```php
nv_dispatch_job(
    string $module,      // Tên module
    string $handler,     // Tên handler class
    array $data = [],    // Dữ liệu truyền cho handler
    int $priority = 0    // Ưu tiên (chưa sử dụng)
): bool
```

### `nv_queue_enabled()`

Kiểm tra Queue có được bật không.

```php
if (nv_queue_enabled()) {
    // Queue đang hoạt động
}
```

### `nv_queue_stats()`

Lấy thống kê hàng đợi.

```php
$stats = nv_queue_stats();
// [
//     'queue_driver' => 'database',
//     'queue_name' => 'default',
//     'pending_jobs' => 15,
//     'redis_connected' => false,
// ]
```

### `nv_queue_get_config()`

Lấy cấu hình Queue từ database.

```php
$config = nv_queue_get_config();
// [
//     'active' => 1,
//     'driver' => 'redis',
//     'redis_host' => '127.0.0.1',
//     ...
// ]
```

## Xử lý lỗi

### Job thất bại

- Job được retry tối đa **3 lần**
- Mỗi lần retry có delay tăng dần: 30s, 60s, 90s
- Sau 3 lần thất bại, job bị xóa khỏi hàng đợi

### Lỗi kết nối Database

Worker tự động reconnect database trước mỗi job để tránh lỗi "MySQL has gone away".

### Lỗi kết nối Redis

Nếu Redis không khả dụng, job dispatch sẽ trả về `false` và log warning.

## Cấu trúc thư mục

```
nv-module-queue/
├── modules/queue/
│   ├── admin/
│   │   ├── config.php           # Trang cấu hình admin
│   │   └── main.php             # Trang chính admin
│   ├── funcs/
│   │   └── main.php             # Trang frontend (nếu có)
│   ├── hooks/
│   │   └── autoloader.php       # Hook tự động load global.functions.php
│   ├── language/
│   │   └── vi.php               # File ngôn ngữ
│   ├── worker/
│   │   ├── AbstractWorker.php   # Base class cho workers
│   │   ├── LinuxWorker.php      # Worker cho Linux (pcntl_fork)
│   │   ├── WindowsWorker.php    # Worker cho Windows
│   │   ├── bootstrap.php        # Bootstrap môi trường NukeViet
│   │   └── run.php              # Entry point CLI
│   ├── action_mysql.php         # SQL cài đặt/gỡ bỏ
│   ├── admin.functions.php      # Hàm admin
│   ├── admin.menu.php           # Menu admin
│   ├── functions.php            # Hàm module
│   ├── global.functions.php     # Các hàm dispatch (nv_dispatch_job, etc.)
│   └── version.php              # Thông tin phiên bản
├── themes/
│   ├── default/modules/queue/
│   │   └── config.tpl           # Template cấu hình
│   └── admin_future/modules/queue/
│       └── (templates admin)
└── README.md
```

## Branches

- `main` - Module cho NukeViet 4.x (sẽ phát triển sau)
- `nukeviet5.x` - Module cho NukeViet 5.x (đang phát triển)

## Tác giả

- **Huỳnh Quốc Đạt** - work@hqd.vn - https://huynhquocdat.vn

## License

GNU/GPL version 2 or any later version
