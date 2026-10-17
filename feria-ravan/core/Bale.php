<?php
/**
 * ============================================================================
 *  درایور پیام‌رسان بله (Bale Bot API Client — نسخهٔ ۴٫۹٫۰)
 *  ─ مستندات رسمی: docs.bale.ai (همان Bot API تلگرام روی دامنه tapi.bale.ai)
 *  ─ پشتیبانی هم‌زمان از متدهای استاتیک و شیء‌گرا، getMe، ارسال پیام، و وب‌هوک
 * ============================================================================
 */
declare(strict_types=1);

final class Bale
{
    private ?string $instanceToken;

    public function __construct(?string $token = null)
    {
        $this->instanceToken = $token !== null && trim($token) !== '' ? trim($token) : null;
    }

    /** آیا بات بله تنظیم شده و فعال است؟ */
    public static function enabled(): bool
    {
        $t = trim((string)DB::setting('bale_bot_token', ''));
        return $t !== '';
    }

    /** نشانی پایه وب‌سرویس بات بله */
    public static function apiUrl(): string
    {
        $url = trim((string)DB::setting('bale_api_url', ''));
        return $url !== '' ? rtrim($url, '/') : 'https://tapi.bale.ai';
    }

    /** نام کاربری ربات بله */
    public static function botUsername(): string
    {
        return ltrim(trim((string)DB::setting('bale_bot_username', '')), '@');
    }

    /** ارسال پیام متنی به چت بله */
    public function sendMessage(string $chatId, string $text, array $opts = []): array
    {
        return self::sendMessageStatic($chatId, $text, $opts, $this->instanceToken);
    }

    public static function sendMessageStatic(string $chatId, string $text, array $opts = [], ?string $tokenOverride = null): array
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

    /** دریافت اطلاعات بات بله */
    public function getMe(): array
    {
        return self::call('getMe', [], $this->instanceToken);
    }

    public static function getMeStatic(): array
    {
        return self::call('getMe', []);
    }

    /** فراخوانی متد دلخواه در Bot API بله */
    public static function call(string $method, array $payload, ?string $tokenOverride = null): array
    {
        $token = $tokenOverride !== null && trim($tokenOverride) !== ''
            ? trim($tokenOverride)
            : trim((string)DB::setting('bale_bot_token', ''));

        if ($token === '') {
            return [false, 'bale_token_not_configured'];
        }

        $base = self::apiUrl();
        $url = $base . '/bot' . rawurlencode($token) . '/' . ltrim($method, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
                'User-Agent: FeriaRavan-Bale-Client/4.9.0'
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo || $raw === false) {
            error_log("feria: bale curl error ($errNo): $errMsg");
            return [false, "curl_error_{$errNo}: {$errMsg}"];
        }

        $decoded = json_decode((string)$raw, true);
        $ok = is_array($decoded) && (!empty($decoded['ok']) || ($decoded['status'] ?? '') === 'OK');

        if (!$ok) {
            error_log("feria: bale api failure code=$code body=" . mb_substr((string)$raw, 0, 300));
        }

        return [$ok, $decoded ?: $raw];
    }

    public static function __callStatic(string $name, array $args)
    {
        if ($name === 'sendMessage') {
            return self::sendMessageStatic($args[0] ?? '', $args[1] ?? '', $args[2] ?? [], $args[3] ?? null);
        }
        if ($name === 'getMe') {
            return self::getMeStatic();
        }
        throw new \BadMethodCallException("Static method Bale::$name not found");
    }
}
