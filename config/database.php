<?php
/**
 * 数据库配置
 * 从全局配置读取
 */

$global = require __DIR__ . '/global.php';

return [
    'super_admin_id' => $global['super_admin_id'],
    'mysql' => $global['mysql'],
];
