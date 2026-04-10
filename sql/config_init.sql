-- 配置表初始化数据
-- 全局配置 + Bot 配置（Bot 配置优先）

-- ========================================
-- 全局配置（bot_id = NULL）
-- ========================================

-- 全局代理配置
INSERT INTO config (bot_id, config_key, config_value, description) 
VALUES (NULL, 'http_proxy', 'http://127.0.0.1:7890', '全局HTTP代理')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- 全局自动回复（用于消息转发后提示）
INSERT INTO config (bot_id, config_key, config_value, description) 
VALUES (NULL, 'auto_reply_message', '小助理已将消息转发给主人，主人看到后会立马回复你哦', '全局自动回复消息')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- 全局欢迎消息
INSERT INTO config (bot_id, config_key, config_value, description) 
VALUES (NULL, 'welcome_message', '欢迎使用！发送 /start 开始验证。', '全局欢迎消息')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- ========================================
-- Bot 专属配置示例（根据实际 bot_id 修改）
-- ========================================

-- 假设 bot_id = 1 的专属配置
-- 如果这个 Bot 有配置，优先使用；没有则使用全局配置

-- Bot 1 的专属自动回复
-- INSERT INTO config (bot_id, config_key, config_value, description) 
-- VALUES (1, 'auto_reply_message', '您好，我是 Bot1 的专属助手，主人不在时请留言。', 'Bot1 自动回复')
-- ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- Bot 1 的专属欢迎消息
-- INSERT INTO config (bot_id, config_key, config_value, description) 
-- VALUES (1, 'welcome_message', '欢迎加入 Bot1！', 'Bot1 欢迎消息')
-- ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- ========================================
-- 查询示例
-- ========================================

-- 查看所有全局配置
-- SELECT * FROM config WHERE bot_id IS NULL;

-- 查看指定 Bot 的所有配置
-- SELECT * FROM config WHERE bot_id = 1;

-- 查看所有配置（含全局和 Bot）
-- SELECT 
--     c.config_key,
--     c.config_value as bot_value,
--     g.config_value as global_value,
--     CASE 
--         WHEN c.config_value IS NOT NULL THEN 'Bot专属'
--         ELSE '全局默认'
--     END as source
-- FROM config g
-- LEFT JOIN config c ON c.config_key = g.config_key AND c.bot_id = 1
-- WHERE g.bot_id IS NULL;
