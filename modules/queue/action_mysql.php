<?php

if (!defined('NV_ADMIN') or !defined('NV_MAINFILE')) {
    exit('Stop!!!');
}

global $db_config, $module_name;

$sql_create_module = [];
$sql_drop_module = [];

$tableName = $db_config['prefix'] . '_queue_jobs';

// 1. Create Queue Jobs Table
$sql_create_module[] = "CREATE TABLE IF NOT EXISTS " . $tableName . " (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL DEFAULT 'default',
    payload LONGTEXT NOT NULL,
    attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
    reserved_at INT(10) UNSIGNED DEFAULT NULL,
    available_at INT(10) UNSIGNED NOT NULL,
    created_at INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY queue_reserved_available (queue, reserved_at, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// 1.1 Insert default config
$sql_create_module[] = "INSERT IGNORE INTO " . $db_config['prefix'] . "_config (lang, module, config_name, config_value) VALUES ('sys', '" . $module_name . "', 'active', '0')";
$sql_create_module[] = "INSERT IGNORE INTO " . $db_config['prefix'] . "_config (lang, module, config_name, config_value) VALUES ('sys', '" . $module_name . "', 'driver', 'database')";
$sql_create_module[] = "INSERT IGNORE INTO " . $db_config['prefix'] . "_config (lang, module, config_name, config_value) VALUES ('sys', '" . $module_name . "', 'redis_host', '127.0.0.1')";
$sql_create_module[] = "INSERT IGNORE INTO " . $db_config['prefix'] . "_config (lang, module, config_name, config_value) VALUES ('sys', '" . $module_name . "', 'redis_port', '6379')";
$sql_create_module[] = "INSERT IGNORE INTO " . $db_config['prefix'] . "_config (lang, module, config_name, config_value) VALUES ('sys', '" . $module_name . "', 'redis_pass', '')";
$sql_create_module[] = "INSERT IGNORE INTO " . $db_config['prefix'] . "_config (lang, module, config_name, config_value) VALUES ('sys', '" . $module_name . "', 'redis_db', '0')";
$sql_create_module[] = "INSERT IGNORE INTO " . $db_config['prefix'] . "_config (lang, module, config_name, config_value) VALUES ('sys', '" . $module_name . "', 'redis_prefix', 'nv_queue_')";

// 1.1 Create Failed Jobs Table
$failedTableName = $db_config['prefix'] . '_queue_failed_jobs';

$sql_create_module[] = "CREATE TABLE IF NOT EXISTS " . $failedTableName . " (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL DEFAULT 'default',
    payload LONGTEXT NOT NULL,
    exception LONGTEXT DEFAULT NULL,
    failed_at INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY queue_failed_at (queue, failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Drop tables on uninstall
$sql_drop_module[] = "DROP TABLE IF EXISTS " . $tableName;
$sql_drop_module[] = "DROP TABLE IF EXISTS " . $failedTableName;

// Remove config on uninstall
$sql_drop_module[] = "DELETE FROM " . $db_config['prefix'] . "_config WHERE module='" . $module_name . "'";

// 2. Register Autoloader Plugin
// This ensures that modules/queue/hooks/autoloader.php is loaded on every request
// Hardcode module name to ensure stability
$plugin_module_name = 'queue'; 
$plugin_area = 'autoloader';
$plugin_file = 'autoloader.php';
$plugin_module_file = 'queue';

$sql_create_module[] = "INSERT IGNORE INTO " . $db_config['prefix'] . "_plugins (
    hook_module, plugin_area, plugin_file, plugin_module_file, plugin_lang, weight, pid
) VALUES (
    '" . $plugin_module_name . "',
    '" . $plugin_area . "',
    '" . $plugin_file . "',
    '" . $plugin_module_file . "',
    'all',
    1,
    0
)";

// Remove plugin on uninstall
$sql_drop_module[] = "DELETE FROM " . $db_config['prefix'] . "_plugins WHERE hook_module='" . $plugin_module_name . "' AND plugin_area='" . $plugin_area . "'";
