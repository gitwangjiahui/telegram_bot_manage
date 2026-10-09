<?php

namespace Utils;

use Model\CaptchaPool;

/**
 * 验证码池常驻维护 Worker（由守护进程 fork 出的子进程运行）
 *
 * 职责：
 *   - 启动即预生成到目标数量（config: verify_code_pre_gen_num，默认 100）
 *   - 周期性检查余量，低于目标即批量并发补货
 *   - 清理已使用的旧记录
 *
 * 上传方式：sendPhoto 到「存储聊天」拿 file_id。
 *   - 推荐在 config 设置 captcha_chat_id 为一个 bot 任管理员的静音私有频道（消息保留、零打扰）
 *   - 未设置则使用超管个人聊天，发送后立即删除消息
 *
 * PHP 无原生协程，这里用 pcntl_fork 常驻子进程 + curl_multi 并发实现等价效果。
 */
class CaptchaPoolWorker
{
    private const BATCH_CHANNEL = 8;   // 每批并发数（存储频道，容量大）
    private const BATCH_CHAT    = 3;   // 每批并发数（超管个人聊天，避免触发单聊天限速）
    private const LOOP_SLEEP   = 10;  // 余量检查周期（秒）
    private const DEFAULT_NUM  = 100; // 默认池容量

    private string $botName;
    private array $mysql;
    private string $token;
    private int $storageChat;
    private bool $deleteAfter;
    private bool $running = true;
    private int $parentPid;

    public function __construct(string $botName, array $mysql, string $token, int $storageChat, bool $deleteAfter)
    {
        $this->botName = $botName;
        $this->mysql = $mysql;
        $this->token = $token;
        $this->storageChat = $storageChat;
        $this->deleteAfter = $deleteAfter;
    }

    /**
     * 当前代理（每次上传实时读取，DB 恢复后能自动拿到）
     */
    private function proxy(): ?string
    {
        return Config::getProxy();
    }

    /**
     * 常驻主循环
     */
    public function run(): void
    {
        pcntl_signal(SIGTERM, function () { $this->running = false; });
        pcntl_signal(SIGINT, function () { $this->running = false; });

        // fork 后必须重建独立 DB 连接，不能与父进程共享
        DbManager::resetConnection();
        DbManager::init($this->mysql);

        // 记录父守护进程 PID；父进程被杀后本进程会被 init(1) 收养，据此自动退出
        $this->parentPid = posix_getppid();

        BotLog::write('验证码池维护进程已启动', $this->botName, 'POOL');

        while ($this->running) {
            pcntl_signal_dispatch();

            // 父守护进程已死亡（被 stop/崩溃）→ 自动退出，不残留
            if (posix_getppid() !== $this->parentPid) {
                break;
            }

            try {
                $target = $this->getTargetNum();
                $pool = new CaptchaPool($this->botName);
                $available = $pool->countAvailable();

                if ($available < $target) {
                    $need = $target - $available;
                    $made = $this->refill($pool, $need);
                    BotLog::write("补货完成 需{$need} 成{$made} 余量{$available}", $this->botName, 'POOL');
                }

                $pool->pruneUsed(3);
            } catch (\Throwable $e) {
                // DB/网络异常不能让 worker 退出，下一轮继续
                BotLog::write('池维护异常，下轮重试: ' . $e->getMessage(), $this->botName, 'POOL');
            }

            for ($i = 0; $i < self::LOOP_SLEEP && $this->running; $i++) {
                sleep(1);
                pcntl_signal_dispatch();
            }
        }

        BotLog::write('验证码池维护进程已退出', $this->botName, 'POOL');
    }

    /**
     * 读取目标池容量
     */
    private function getTargetNum(): int
    {
        $val = Config::get('verify_code_pre_gen_num', null, self::DEFAULT_NUM);
        $num = (int) $val;
        return $num > 0 ? $num : self::DEFAULT_NUM;
    }

    /**
     * 生成并并发上传补足 $need 个
     */
    private function refill(CaptchaPool $pool, int $need): int
    {
        $made = 0;
        // 超管个人聊天限速更严，用较小批量
        $batchSize = $this->deleteAfter ? self::BATCH_CHAT : self::BATCH_CHANNEL;

        while ($need > 0 && $this->running) {
            $size = min($batchSize, $need);

            // 生成本批题目与图片
            $batch = [];
            for ($i = 0; $i < $size; $i++) {
                $math = $this->generateMath();
                $file = $this->renderImage($math['question']);
                $batch[] = ['file' => $file] + $math;
            }

            // 并发上传
            $result = $this->uploadBatch($batch);

            // 落库
            if (!empty($result['items'])) {
                $pool->insertMany($result['items']);
                $made += count($result['items']);
            }

            // 清理临时图片
            foreach ($batch as $b) {
                @unlink($b['file']);
            }

            // 触发单聊天限速：按 retry_after 退避（本批失败项需重试，need 不递减）
            if ($result['retry_after'] !== null) {
                $wait = (int) $result['retry_after'];
                BotLog::write("触发限速，等待 {$wait}s", $this->botName, 'POOL');
                for ($i = 0; $i < $wait && $this->running; $i++) {
                    sleep(1);
                    pcntl_signal_dispatch();
                }
                continue;
            }

            $need -= $size;
        }

        return $made;
    }

    /**
     * curl_multi 并发上传一批
     *
     * @param array $batch
     * @return array{items:array<int, array{file_id:string,code:string,answer:string}>, retry_after:?int}
     */
    private function uploadBatch(array $batch): array
    {
        $proxy = $this->proxy();
        $mh = curl_multi_init();
        $handles = [];

        foreach ($batch as $idx => $b) {
            $ch = curl_init("https://api.telegram.org/bot{$this->token}/sendPhoto");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'chat_id' => $this->storageChat,
                    'photo'   => new \CURLFile($b['file'], 'image/png', 'captcha.png'),
                ],
            ]);
            if ($proxy) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        // 执行
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        // 收集结果
        $ok = [];
        $toDelete = [];
        $retryAfter = null;
        foreach ($handles as $idx => $ch) {
            $resp = curl_multi_getcontent($ch);
            $data = json_decode($resp, true);
            if (is_array($data) && ($data['ok'] ?? false)) {
                $photos = $data['result']['photo'] ?? [];
                $last = end($photos);
                if ($last && !empty($last['file_id'])) {
                    $ok[$idx] = [
                        'file_id' => $last['file_id'],
                        'code'    => $batch[$idx]['question'],
                        'answer'  => $batch[$idx]['answer'],
                    ];
                    $toDelete[] = $data['result']['message_id'];
                }
            } elseif (($data['error_code'] ?? 0) === 429) {
                $retryAfter = (int) ($data['parameters']['retry_after'] ?? 1);
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        // 超管个人聊天兜底：发送后立即删除，避免堆积
        if ($this->deleteAfter && !empty($toDelete)) {
            $this->deleteMessages($toDelete);
        }

        return ['items' => array_values($ok), 'retry_after' => $retryAfter];
    }

    /**
     * 并发删除消息
     */
    private function deleteMessages(array $messageIds): void
    {
        $proxy = $this->proxy();
        $mh = curl_multi_init();
        $handles = [];

        foreach ($messageIds as $mid) {
            $ch = curl_init("https://api.telegram.org/bot{$this->token}/deleteMessage");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => ['chat_id' => $this->storageChat, 'message_id' => $mid],
            ]);
            if ($proxy) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    /**
     * 生成两位数加法题
     *
     * @return array{question:string, answer:string}
     */
    private function generateMath(): array
    {
        $a = rand(10, 99);
        $b = rand(10, 99);
        return [
            'question' => "{$a} + {$b} = ?",
            'answer'   => (string) ($a + $b),
        ];
    }

    /**
     * GD 渲染验证码图片（复用原 /start 的干扰线风格）
     */
    private function renderImage(string $text): string
    {
        $width = 300;
        $height = 100;
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 240));

        for ($i = 0; $i < 5; $i++) {
            $lc = imagecolorallocate($img, rand(100, 200), rand(100, 200), rand(100, 200));
            imageline($img, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $lc);
        }

        $tc = imagecolorallocate($img, rand(30, 80), rand(30, 80), rand(30, 80));
        $tw = imagefontwidth(5) * strlen($text);
        $x = (int) (($width - $tw) / 2);
        $y = (int) (($height - imagefontheight(5)) / 2);
        imagestring($img, 5, $x, $y, $text, $tc);

        $dir = sys_get_temp_dir() . '/captcha_pool';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . uniqid('', true) . '.png';
        imagepng($img, $file);
        imagedestroy($img);

        return $file;
    }
}
