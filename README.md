# NukeViet Queue Module

Module Queue cho NukeViet CMS - Hỗ trợ xử lý các tác vụ nền (background jobs).

## Branches

- `main` - Module cho NukeViet 4.x
- `nukeviet5.x` - Module cho NukeViet 5.x (đang phát triển)

## Cấu trúc thư mục

```
nv-module-queue/
├── modules/queue/           # Code module chính
│   ├── admin/               # Chức năng admin
│   ├── funcs/               # Chức năng frontend
│   ├── hooks/               # Hooks
│   ├── language/            # File ngôn ngữ
│   ├── worker/              # Worker xử lý queue
│   ├── action_mysql.php     # SQL cài đặt/nâng cấp
│   ├── version.php          # Thông tin phiên bản
│   └── ...
├── themes/
│   ├── default/modules/queue/        # Template frontend
│   └── admin_future/modules/queue/   # Template admin
└── README.md
```

## Cài đặt

1. Tải module và giải nén
2. Copy thư mục `modules/queue/` vào `src/modules/`
3. Copy thư mục `themes/default/modules/queue/` vào `src/themes/default/modules/`
4. Copy thư mục `themes/admin_future/modules/queue/` vào `src/themes/admin_future/modules/`
5. Vào Admin > Quản lý modules > Cài đặt module

## Tác giả

VINADES.,JSC - https://nukeviet.vn

## License

GNU/GPL version 2 or any later version
