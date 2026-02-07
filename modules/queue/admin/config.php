<?php

/**
 * @Project NUKEVIET 5.x
 * @Author Huỳnh Quốc Đạt <work@hqd.vn>
 * @Website https://huynhquocdat.vn
 * @Copyright (C) 2026
 * @License GNU/GPL version 2 or any later version
 */

if (!defined('NV_IS_FILE_ADMIN')) {
    exit('Stop!!!');
}

$page_title = \NukeViet\Core\Language::$lang_module['config'] ?? 'Cấu hình';

if ($nv_Request->isset_request('save', 'post')) {
    $config = [];
    $config['active'] = $nv_Request->get_int('active', 'post', 0);
    $config['driver'] = $nv_Request->get_title('driver', 'post', 'database');
    $config['redis_host'] = $nv_Request->get_title('redis_host', 'post', '127.0.0.1');
    $config['redis_port'] = $nv_Request->get_int('redis_port', 'post', 6379);
    $config['redis_pass'] = $nv_Request->get_title('redis_pass', 'post', '');
    $config['redis_db'] = $nv_Request->get_int('redis_db', 'post', 0);
    $config['redis_prefix'] = $nv_Request->get_title('redis_prefix', 'post', 'nv_queue_');

    foreach ($config as $config_name => $config_value) {
        $nv_Request->set_Cookie($module_name . '_' . $config_name, $config_value, NV_LIVE_COOKIE_TIME);
        
        // Save to DB
        try {
            // Check if config exists
            $stm = $db->prepare('SELECT config_name FROM ' . $db_config['prefix'] . '_config WHERE lang = :lang AND module = :module AND config_name = :config_name');
            $stm->execute([':lang' => 'sys', ':module' => $module_name, ':config_name' => $config_name]);
            
            if ($stm->fetch()) {
                $stm = $db->prepare('UPDATE ' . $db_config['prefix'] . '_config SET config_value = :config_value WHERE lang = :lang AND module = :module AND config_name = :config_name');
            } else {
                $stm = $db->prepare('INSERT INTO ' . $db_config['prefix'] . '_config (lang, module, config_name, config_value) VALUES (:lang, :module, :config_name, :config_value)');
            }
            $stm->execute([':lang' => 'sys', ':module' => $module_name, ':config_name' => $config_name, ':config_value' => $config_value]);
            
        } catch(PDOException $e) {
            trigger_error($e->getMessage());
        }
    }

    nv_insert_logs(NV_LANG_DATA, $module_name, 'config', "Updated configuration", $admin_info['userid']);
    $nv_Cache->delMod('sys');
    Header('Location: ' . NV_BASE_ADMINURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=' . $op . '&saved=1');
    die();
}

// Load current config
$sql = "SELECT config_name, config_value FROM " . $db_config['prefix'] . "_config WHERE lang='sys' AND module='" . $module_name . "'";
$result = $db->query($sql);
$queue_config = [];
while ($row = $result->fetch()) {
    $queue_config[$row['config_name']] = $row['config_value'];
}

// Defaults
$queue_config['active'] = $queue_config['active'] ?? 0;
$queue_config['driver'] = $queue_config['driver'] ?? 'database';
$queue_config['redis_host'] = $queue_config['redis_host'] ?? '127.0.0.1';
$queue_config['redis_port'] = $queue_config['redis_port'] ?? 6379;
$queue_config['redis_pass'] = $queue_config['redis_pass'] ?? '';
$queue_config['redis_db'] = $queue_config['redis_db'] ?? 0;
$queue_config['redis_prefix'] = $queue_config['redis_prefix'] ?? 'nv_queue_';

$contents = '';
if ($nv_Request->get_int('saved', 'get', 0) == 1) {
    $contents .= '<div class="alert alert-success">' . (\NukeViet\Core\Language::$lang_global['saved'] ?? 'Đã lưu cấu hình thành công') . '</div>';
}

$xtpl = new XTemplate('config.tpl', NV_ROOTDIR . '/themes/default/modules/' . $module_file);
$xtpl->assign('LANG', \NukeViet\Core\Language::$lang_module);
$xtpl->assign('GLANG', \NukeViet\Core\Language::$lang_global);
$xtpl->assign('FORM_ACTION', NV_BASE_ADMINURL . 'index.php?' . NV_LANG_VARIABLE . '=' . NV_LANG_DATA . '&' . NV_NAME_VARIABLE . '=' . $module_name . '&' . NV_OP_VARIABLE . '=' . $op);

$xtpl->assign('ACTIVE_CHECKED', $queue_config['active'] ? 'checked' : '');
$xtpl->assign('DRIVER_DATABASE_CHECKED', $queue_config['driver'] == 'database' ? 'selected' : '');
$xtpl->assign('DRIVER_REDIS_CHECKED', $queue_config['driver'] == 'redis' ? 'selected' : '');

$xtpl->assign('DATA', $queue_config);

$xtpl->parse('main');
$contents .= $xtpl->text('main');

include NV_ROOTDIR . '/includes/header.php';
echo nv_admin_theme($contents);
include NV_ROOTDIR . '/includes/footer.php';
