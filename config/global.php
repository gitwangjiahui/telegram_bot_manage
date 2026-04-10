<?php
/**
 * 全局配置文件
 * 所有 Bot 共享的配置
 */

return [
    /**
     * 超级管理员 ID
     * 超级管理员可以：
     * - 接收所有 Bot 的消息
     * - 回复用户
     * - 看到普通管理员的回复
     * 
     * 设置你的 Telegram ID
     */
    'super_admin_id' => 5622823242,
    
    /**
     * MySQL 数据库配置
     */
    'mysql' => [
        'host' => '101.200.84.19',
        'port' => 3306,
        'user' => 'wangjiahui',
        'password' => 'Jiahui**8',
        'database' => 'telegram_bot',
    ],
];
