<?php

if (!defined('NV_IS_FILE_ADMIN')) {
    exit('Stop!!!');
}

$page_title = "Queue System Dashboard";

$tpl = new \NukeViet\Template\NVSmarty();
$tpl->setTemplateDir(NV_ROOTDIR . '/themes/default/modules/queue'); // Placeholder

$contents = '<div class="alert alert-info">Queue Module is installed and active.</div>';
$contents .= '<p>Use CLI worker to process jobs.</p>';

include NV_ROOTDIR . '/includes/header.php';
echo nv_admin_theme($contents);
include NV_ROOTDIR . '/includes/footer.php';
