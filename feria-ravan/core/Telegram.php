<?php
/**
 * ============================================================================
 *  درایور پیام‌رسان تلگرام (Telegram Bot API Client — نسخهٔ ۴٫۹٫۱)
 *  ─ مستندات رسمی: core.telegram.org/bots/api
 *  ─ پشتیبانی از پروکسی دلخواه، نشانی اختصاصی API، متدهای استاتیک و شیء‌گرا
 * ============================================================================
 */
declare(strict_types=1);

final class Telegram
{
    private ?string $instanceToken;

    public function __construct(?string $token = null)
    {
        $this->instanceToken = $token !== null && trim($token) !== '' ? trim($token) : null;
    }

    /** آیا بات تلگرام تنظیم شده و فعال است؟ */
    public static function enabled(): bool
    {
        $t = trim((string)DB::setting('telegram_bot_token', ''));
        return $t !== '';
    }

    /** نشانی پایه وب‌سرویس تلگرام */
    public static function apiUrl(): string
    {
        $url = trim((string)DB::setting('telegram_api_url', ''));
        return $url !== '' ? rtrim($url, '/') : 'https://api.telegram.org';
    }

    /** نام کاربری ربات تلگرام */
    public static function botUsername(): string
    {
        return ltrim(trim((string)DB::setting('telegram_bot_username', '')), '@');
    }

    /** ارسال پیام متنی به کاربر یا کانال تلگرام (فراخوانی مستقیم استاتیک و بدون خطای شیء) */
    public static function sendMessage(string $chatId, string $text, array $opts = [], ?string $tokenOverride = null): array
    {
        $chatId = trim($chatId);
        if ($chatId === '') return [false, 'chat_id_empty'];
        $text = trim($text);
        if ($text === '') return [false, 'text_empty'];

        $payload = array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $opts);

        return self::call('sendMessage', $payload, $tokenOverride);
    }

    public static function sendMessageStatic(string $chatId, string $text, array $opts = [], ?string $tokenOverride = null): array
    {
        return self::sendMessage($chatId, $text, $opts, $tokenOverride);
    }

    /** دریافت اطلاعات بات تلگرام */
    public static function getMe(?string $tokenOverride = null): array
    {
        return self::call('getMe', [], $tokenOverride);
    }

    public static function getMeStatic(): array
    {
        return self::getMe();
    }

    /** فراخوانی متد دلخواه در Bot API تلگرام */
    public static function call(string $method, array $payload, ?string $tokenOverride = null): array
    {
        try {
            $token = $tokenOverride !== null && trim($tokenOverride) !== ''
                ? trim($tokenOverride)
                : trim((string)DB::setting('telegram_bot_token', ''));

            if ($token === '') {
                return [false, 'telegram_token_not_configured'];
            }

            $base = self::apiUrl();
            $url = $base . '/bot' . rawurlencode($token) . '/' . ltrim($method, '/');

            $ch = curl_init($url);
            $curlOpts = [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json; charset=utf-8',
                    'Accept: application/json',
                    'User-Agent: FeriaRavan-Telegram-Client/4.9.1'
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ];

            // در صورت تنظیم پروکسی توسط مدیر (مثلاً socks5://127.0.0.1:1080 یا http://proxy:8080)
            $proxy = trim((string)DB::setting('telegram_proxy', ''));
            if ($proxy !== '') {
                $curlOpts[CURLOPT_PROXY] = $proxy;
            }

            curl_setopt_array($ch, $curlOpts);

            $raw = curl_exec($ch);
            $errNo = curl_errno($ch);
            $errMsg = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errNo || $raw === false) {
                error_log("feria: telegram curl error ($errNo): $errMsg");
                return [false, "curl_error_{$errNo}: {$errMsg}"];
            }

            $decoded = json_decode((string)$raw, true);
            $ok = is_array($decoded) && (!empty($decoded['ok']) || ($decoded['status'] ?? '') === 'OK');

            if (!$ok) {
                error_log("feria: telegram api failure code=$code body=" . mb_substr((string)$raw, 0, 300));
            }

            return [$ok, $decoded ?: $raw];
        } catch (\Throwable $e) {
            error_log('feria: telegram exception: ' . $e->getMessage());
            return [false, 'exception: ' . $e->getMessage()];
        }
    }

    public function __call(string $name, array $args)
    {
        if ($name === 'sendMessage') {
            return self::sendMessage($args[0] ?? '', $args[1] ?? '', $args[2] ?? [], $this->instanceToken);
        }
        if ($name === 'getMe') {
            return self::getMe($this->instanceToken);
        }
        if ($name === 'call') {
            return self::call($args[0] ?? '', $args[1] ?? [], $this->instanceToken);
        }
        throw new \BadMethodCallException("Method Telegram->$name not found");
    }

    public static function __callStatic(string $name, array $args)
    {
        if ($name === 'sendMessage') {
            return self::sendMessage($args[0] ?? '', $args[1] ?? '', $args[2] ?? [], $args[3] ?? null);
        }
        if ($name === 'getMe') {
            return self::getMe($args[0] ?? null);
        }
        throw new \BadMethodCallException("Static method Telegram::$name not found");
    }
}
