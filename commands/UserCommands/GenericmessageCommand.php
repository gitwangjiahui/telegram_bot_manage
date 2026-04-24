<?php

namespace Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Model\VerificationCode;
use Model\UserVerification;
use Model\ForwardMap;
use Utils\Config;
use Utils\DbManager;

class GenericmessageCommand extends UserCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Handle generic message';
    protected $version = '1.0.0';

    private VerificationCode $verificationCode;
    private UserVerification $userVerification;
    private ForwardMap $forwardMap;
    private $super_admin_id;
    private $admin_ids;

    public function __construct()
    {
        try {
            $bot_name = $GLOBALS['bot_config']['bot_name'] ?? 'bot1';
            
            // 初始化 DbManager
            DbManager::init($GLOBALS['bot_config']['mysql']);
            
            // 初始化模型
            $this->verificationCode = new VerificationCode($bot_name);
            $this->userVerification = new UserVerification($bot_name);
            $this->forwardMap = new ForwardMap($bot_name);
            
            // 初始化 Config
            $logFile = $GLOBALS['bot_config']['bot_dir'] . '/debug.log';
            Config::init(DbManager::getConnection(), $logFile);
            
            $this->super_admin_id = $GLOBALS['bot_config']['super_admin_id'] ?? null;
            $this->admin_ids = $GLOBALS['bot_config']['admin_ids'] ?? [];
            
            $this->log("GenericmessageCommand constructed successfully");
        } catch (\Exception $e) {
            error_log("[GenericmessageCommand] Constructor error: " . $e->getMessage());
            throw $e;
        }
    }

    private function log($message): void
    {
        $log_file = $GLOBALS['bot_config']['bot_dir'] . '/debug.log';
        file_put_contents($log_file, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function isAdmin($user_id): bool
    {
        return $user_id == $this->super_admin_id || in_array($user_id, $this->admin_ids);
    }

    private function isSuperAdmin($user_id): bool
    {
        return $user_id == $this->super_admin_id;
    }

    public function execute(): ServerResponse
    {
        $this->log("execute() called");
        
        $message = $this->getMessage();
        if (!$message) {
            $this->log("ERROR: No message in execute()");
            return Request::emptyResponse();
        }
        
        $user_id = $message->getFrom()->getId();
        $chat_id = $message->getChat()->getId();
        $text = $message->getText() ?: '[no text]';
        
        $this->log("Message from user_id={$user_id}, chat_id={$chat_id}, text={$text}");

        // 管理员回复消息处理
        $is_admin = $this->isAdmin($user_id);
        if ($is_admin && $message->getReplyToMessage()) {
            return $this->handleAdminReply($message, $user_id);
        }
        
        // 管理员发送消息但没有点击回复按钮，提示需要回复
        if ($is_admin && !$message->getReplyToMessage()) {
            return Request::sendMessage([
                'chat_id' => $user_id,
                'text' => "长按对方消息选择回复，对方才能收到消息哦",
            ]);
        }

        // 检查验证（非管理员需要验证）
        if (!$is_admin) {
            $is_verified = $this->userVerification->isVerified($user_id);
            if (!$is_verified) {
                $text = $message->getText() ?: '';
                if (preg_match('/^\d+$/', trim($text))) {
                    return $this->checkAnswer($user_id, $chat_id, trim($text));
                }
                return Request::sendMessage([
                    'chat_id' => $user_id,
                    'text' => "⚠️ 请先完成验证\n发送 /start 获取验证码",
                ]);
            }
        }

        // 转发消息给所有管理员
        $result = $this->forwardToAllAdmins($message, $user_id, $chat_id);
        
        // 用户发送消息后，提示消息已转发
        if (!$is_admin) {
            $botId = $this->getBotId();
            $replyMessage = Config::getAutoReply($botId) ?? '消息已转发，请等待回复。';

            if ($replyMessage) {
                Request::sendMessage([
                    'chat_id' => $user_id,
                    'text' => $replyMessage,
                ]);
            }
        }
        
        return $result;
    }

    private function getBotId(): int
    {
        $botName = $GLOBALS['bot_config']['bot_name'] ?? 'bot1';
        $pdo = DbManager::getConnection();
        $stmt = $pdo->prepare("SELECT id FROM bots WHERE bot_name = ?");
        $stmt->execute([$botName]);
        return (int)$stmt->fetchColumn();
    }

    private function forwardToAllAdmins($message, $user_id, $chat_id): ServerResponse
    {
        $is_admin = $this->isAdmin($user_id);
        
        if ($is_admin) {
            if ($this->super_admin_id && $user_id != $this->super_admin_id) {
                $result = Request::forwardMessage([
                    'chat_id' => $this->super_admin_id,
                    'from_chat_id' => $chat_id,
                    'message_id' => $message->getMessageId(),
                ]);
                
                if ($result->isOk()) {
                    $forwarded_msg_id = $result->getResult()->getMessageId();
                    $this->forwardMap->save($forwarded_msg_id, $message->getMessageId(), $user_id);
                }
            }
        } else {
            $all_admins = array_merge([$this->super_admin_id], $this->admin_ids);
            $all_admins = array_unique(array_filter($all_admins));

            foreach ($all_admins as $admin_id) {
                if (empty($admin_id)) continue;
                $result = Request::forwardMessage([
                    'chat_id' => $admin_id,
                    'from_chat_id' => $chat_id,
                    'message_id' => $message->getMessageId(),
                ]);
                
                if ($result->isOk()) {
                    $forwarded_msg_id = $result->getResult()->getMessageId();
                    $this->forwardMap->save($forwarded_msg_id, $message->getMessageId(), $user_id);
                }
            }
        }

        return Request::emptyResponse();
    }

    private function handleAdminReply($message, $admin_id): ServerResponse
    {
        $reply_to_msg = $message->getReplyToMessage();
        
        $sync_admin_id = $this->parseSyncMessage($reply_to_msg);
        if ($sync_admin_id && $this->isSuperAdmin($admin_id)) {
            $this->sendReplyToUser($message, $sync_admin_id);
            return Request::emptyResponse();
        }
        
        $forward_from = $reply_to_msg->getForwardFrom();
        $target_user_id = null;
        
        if ($forward_from) {
            $target_user_id = $forward_from->getId();
        } else {
            $target_user_id = $this->forwardMap->getUserId($reply_to_msg->getMessageId());
        }
        
        if (!$target_user_id) {
            return Request::emptyResponse();
        }

        $this->sendReplyToUser($message, $target_user_id);

        if (!$this->isSuperAdmin($admin_id) && $this->super_admin_id) {
            $this->forwardReplyToSuperAdmin($message, $admin_id, $target_user_id);
        }

        return Request::emptyResponse();
    }

    private function parseSyncMessage($reply_to_msg): ?int
    {
        $text = $reply_to_msg->getText() ?: $reply_to_msg->getCaption() ?: '';
        if (preg_match('/^\[转发自管理员 (\d+)\]/', $text, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    private function forwardReplyToSuperAdmin($message, $admin_id, $target_user_id): void
    {
        $admin_name = $message->getFrom()->getFirstName() ?: "管理员{$admin_id}";
        $header = "[转发自管理员 {$admin_id}] 回复了用户 {$target_user_id}\n管理员: {$admin_name}\n─────────────\n\n";
        
        if ($msg_text = $message->getText()) {
            Request::sendMessage(['chat_id' => $this->super_admin_id, 'text' => $header . $msg_text]);
        } elseif ($message->getPhoto()) {
            $photo = $message->getPhoto();
            $file_id = $photo[count($photo) - 1]->getFileId();
            Request::sendPhoto(['chat_id' => $this->super_admin_id, 'photo' => $file_id, 'caption' => $header . ($message->getCaption() ?: '')]);
        }
    }

    private function sendReplyToUser($message, $target_user_id): void
    {
        if ($message->getPhoto()) {
            $photo = $message->getPhoto();
            $file_id = $photo[count($photo) - 1]->getFileId();
            Request::sendPhoto(['chat_id' => $target_user_id, 'photo' => $file_id, 'caption' => $message->getCaption() ?: '']);
        } elseif ($message->getText()) {
            Request::sendMessage(['chat_id' => $target_user_id, 'text' => $message->getText()]);
        }
    }

    private function checkAnswer($user_id, $chat_id, $answer): ServerResponse
    {
        if ($this->verificationCode->verify($user_id, $answer)) {
            $this->userVerification->markVerified($user_id);
            $this->notifyAdminsUserVerified($user_id);
            
            return Request::sendMessage([
                'chat_id' => $chat_id,
                'text' => "✅ 验证成功！\n\n欢迎使用，现在可以正常使用了。",
            ]);
        }
        return Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => "❌ 答案错误或已过期\n请发送 /start 重新获取验证码",
        ]);
    }

    private function notifyAdminsUserVerified($user_id): void
    {
        $message = $this->getMessage();
        $from = $message->getFrom();
        
        $username = $from->getUsername() ? '@' . $from->getUsername() : '无';
        $firstName = $from->getFirstName() ?: '无';
        $lastName = $from->getLastName() ?: '';
        $fullName = trim($firstName . ' ' . $lastName) ?: '无';
        
        $text = "✅ 新用户验证通过\n\n👤 用户信息\n├ ID: <code>{$user_id}</code>\n├ 用户名: {$username}\n├ 姓名: {$fullName}\n└ 时间: " . date('Y-m-d H:i:s') . "\n\n💡 可直接回复此用户的消息";

        $all_admins = array_merge([$this->super_admin_id], $this->admin_ids);
        $all_admins = array_unique(array_filter($all_admins));

        foreach ($all_admins as $admin_id) {
            if (empty($admin_id)) continue;
            Request::sendMessage(['chat_id' => $admin_id, 'text' => $text, 'parse_mode' => 'HTML']);
        }
    }
}
