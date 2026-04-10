<?php

namespace Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Model\VerificationCode;
use Model\UserVerification;
use Utils\DbManager;

class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Start command';
    protected $usage = '/start';
    protected $version = '1.0.0';

    private VerificationCode $verificationCode;
    private UserVerification $userVerification;

    public function __construct()
    {
        $botName = $GLOBALS['bot_config']['bot_name'] ?? 'bot1';
        
        // 初始化 DbManager
        DbManager::init($GLOBALS['bot_config']['mysql']);
        
        // 初始化模型
        $this->verificationCode = new VerificationCode($botName);
        $this->userVerification = new UserVerification($botName);
    }

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $user_id = $message->getFrom()->getId();
        $chat_id = $message->getChat()->getId();
        
        // 获取管理员配置
        $super_admin_id = $GLOBALS['bot_config']['super_admin_id'] ?? null;
        $is_super_admin = ($user_id == $super_admin_id);

        // 超级管理员不需要验证
        if ($is_super_admin) {
            return Request::emptyResponse();
        }

        // 清除旧验证
        $this->verificationCode->clear($user_id);

        // 生成数学题
        $math = $this->generateMath();
        $this->verificationCode->save($user_id, $math['question'], $math['answer']);

        // 生成图片
        $image_path = $this->generateImage($math['question']);

        Request::sendPhoto([
            'chat_id' => $chat_id,
            'photo' => $image_path,
            'caption' => "👋 欢迎使用！\n\n🤖 请计算图片中的数学题\n直接回复答案即可",
        ]);

        unlink($image_path);
        return Request::emptyResponse();
    }

    private function generateMath(): array
    {
        $a = rand(10, 99);
        $b = rand(10, 99);
        $answer = $a + $b;
        return ['question' => "{$a} + {$b} = ?", 'answer' => (string)$answer];
    }

    private function generateImage($text): string
    {
        $width = 300;
        $height = 100;
        $image = imagecreatetruecolor($width, $height);
        $bg_color = imagecolorallocate($image, 240, 240, 240);
        imagefill($image, 0, 0, $bg_color);

        for ($i = 0; $i < 5; $i++) {
            $line_color = imagecolorallocate($image, rand(100, 200), rand(100, 200), rand(100, 200));
            imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $line_color);
        }

        $text_color = imagecolorallocate($image, rand(30, 80), rand(30, 80), rand(30, 80));
        $font_size = 5;
        $text_width = imagefontwidth($font_size) * strlen($text);
        $text_height = imagefontheight($font_size);
        $x = intval(($width - $text_width) / 2);
        $y = intval(($height - $text_height) / 2);
        imagestring($image, $font_size, $x, $y, $text, $text_color);

        $bot_dir = $GLOBALS['bot_config']['bot_dir'];
        $file = $bot_dir . '/codes/' . uniqid() . '.png';
        
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        
        imagepng($image, $file);
        imagedestroy($image);
        return $file;
    }
}
