<?php

namespace Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Model\UserVerification;
use Utils\DbManager;

/**
 * 处理用户聊天状态变化（删除/拉黑 Bot）
 */
class MychatmemberCommand extends UserCommand
{
    protected $name = 'mychatmember';
    protected $description = 'Handle chat member status changes';
    protected $version = '1.0.0';

    private UserVerification $userVerification;

    public function __construct()
    {
        $bot_name = $GLOBALS['bot_config']['bot_name'] ?? 'bot1';
        
        // 初始化 DbManager
        DbManager::init($GLOBALS['bot_config']['mysql']);
        
        // 初始化模型
        $this->userVerification = new UserVerification($bot_name);
    }

    public function execute(): ServerResponse
    {
        $update = $this->getUpdate();
        $chat_member = $update->getMyChatMember();
        
        if (!$chat_member) {
            return Request::emptyResponse();
        }

        $user = $chat_member->getFrom();
        $user_id = $user->getId();
        $new_status = $chat_member->getNewChatMember()->getStatus();

        // 用户删除或拉黑 Bot
        if ($new_status === 'kicked') {
            $this->userVerification->remove($user_id);
        }

        return Request::emptyResponse();
    }
}
