<?php
/**
 * ============================================================================
 *  درایور پیام‌رسان روبیکا (Rubika Bot API Client — نسخهٔ ۴٫۹٫۱)
 *  ─ مستندات رسمی: Bot API روبیکا (botapi.rubika.ir)
 *  ─ پشتیبانی کامل از احراز هویت توکن، ارسال پیام متنی، ارسال گروهی (Batch)،
 *    وب‌هوک هوشمند، وضعیت ربات (getMe) و Polling (getUpdates)
 *  ─ پشتیبانی هم‌زمان از فراخوانی استاتیک و شیء‌گرا (Static & Instance) بدون خطای نوع
 * ============================================================================
 */
declare(strict_types=1);

final class Rubika
{
    private ?string $instanceToken;

    public function __construct(?string $token = null)
    {
        $this->instanceToken = $token !== null && trim($token) !== '' ? trim($token) : null;
    }

    /** آیا بات روبیکا در سیستم فعال و دارای توکن است؟ */
    public static function enabled(): bool
    {
        $t = trim((string)DB::setting('rubika_bot_token', ''));
        return $t !== '';
    }

    /** آدرس پایه وب‌سرویس بات روبیکا */
    public static function apiUrl(): string
    {
        $url = trim((string)DB::setting('rubika_api_url', ''));
        return $url !== '' ? rtrim($url, '/') : 'https://botapi.rubika.ir/v1';
    }

    /** نام کاربری ربات روبیکا */
    public static function botUsername(): string
    {
        return ltrim(trim((string)DB::setting('rubika_bot_username', '')), '@');
    }

    /**
     * ارسال پیام متنی به کاربر یا کانال روبیکا (استاتیک مستقیم و ایمن)
     * @return array [bool $ok, array|string $response]
     */
    public static function sendMessage(string $chatId, string $text, array $opts = [], ?string $tokenOverride = null): array
    {
        $chatId = trim($chatId);
        if ($chatId === '') return [false, 'chat_id_empty'];
        $text = trim($text);
        if ($text === '') return [false, 'text_empty'];

        $payload = array_merge([
            'chat_id' => $chatId,
            'text'    => $text,
        ], $opts);

        return self::call('sendMessage', $payload, $tokenOverride);
    }

    public static function sendMessageStatic(string $chatId, string $text, array $opts = [], ?string $tokenOverride = null): array
    {
        return self::sendMessage($chatId, $text, $opts, $tokenOverride);
    }

    /** ارسال دسته‌ای یک پیام به چند شناسه کاربری */
    public static function sendBatch(array $chatIds, string $text, ?string $tokenOverride = null): array
    {
        $results = [];
        foreach ($chatIds as $cid) {
            $results[$cid] = self::sendMessage((string)$cid, $text, [], $tokenOverride);
        }
        return $results;
    }

    public static function sendBatchStatic(array $chatIds, string $text): array
    {
        return self::sendBatch($chatIds, $text);
    }

    /** دریافت اطلاعات بات */
    public static function getMe(?string $tokenOverride = null): array
    {
        return self::call('getMe', [], $tokenOverride);
    }

    public static function getMeStatic(): array
    {
        return self::getMe();
    }

    /** دریافت آپدیت‌ها و پیام‌های جدید دریافتی */
    public static function getUpdates(int $offset = 0, int $limit = 100, ?string $tokenOverride = null): array
    {
        return self::call('getUpdates', ['offset' => $offset, 'limit' => $limit], $tokenOverride);
    }

    /** تنظیم آدرس وب‌هوک روبیکا */
    public static function setWebhook(string $url, ?string $tokenOverride = null): array
    {
        return self::call('setWebhook', ['url' => $url], $tokenOverride);
    }

    /** فراخوانی متد دلخواه در Bot API روبیکا با حفاظت کامل try/catch */
    public static function call(string $method, array $payload, ?string $tokenOverride = null): array
    {
        try {
            $token = $tokenOverride !== null && trim($tokenOverride) !== ''
                ? trim($tokenOverride)
                : trim((string)DB::setting('rubika_bot_token', ''));

            if ($token === '') {
                return [false, 'rubika_token_not_configured'];
            }

            $base = self::apiUrl();
            $url = $base . '/' . rawurlencode($token) . '/' . ltrim($method, '/');

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json; charset=utf-8',
                    'Accept: application/json',
                    'User-Agent: FeriaRavan-Rubika-Client/4.9.1'
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);

            $raw = curl_exec($ch);
            $errNo = curl_errno($ch);
            $errMsg = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errNo || $raw === false) {
                error_log("feria: rubika curl error ($errNo): $errMsg");
                return [false, "curl_error_{$errNo}: {$errMsg}"];
            }

            $decoded = json_decode((string)$raw, true);
            $ok = (is_array($decoded) && (!empty($decoded['ok']) || ($decoded['status'] ?? '') === 'OK'))
                || ($code >= 200 && $code < 300 && is_array($decoded) && !isset($decoded['error']));

            if (!$ok) {
                error_log("feria: rubika api failure code=$code body=" . mb_substr((string)$raw, 0, 300));
            }

            return [$ok, $decoded ?: $raw];
        } catch (\Throwable $e) {
            error_log('feria: rubika exception: ' . $e->getMessage());
            return [false, 'exception: ' . $e->getMessage()];
        }
    }

    public function __call(string $name, array $args)
    {
        if ($name === 'sendMessage') {
            return self::sendMessage($args[0] ?? '', $args[1] ?? '', $args[2] ?? [], $this->instanceToken);
        }
        if ($name === 'sendBatch') {
            return self::sendBatch($args[0] ?? [], $args[1] ?? '', $this->instanceToken);
        }
        if ($name === 'getMe') {
            return self::getMe($this->instanceToken);
        }
        if ($name === 'setWebhook') {
            return self::setWebhook($args[0] ?? '', $this->instanceToken);
        }
        if ($name === 'getUpdates') {
            return self::getUpdates((int)($args[0] ?? 0), (int)($args[1] ?? 100), $this->instanceToken);
        }
        if ($name === 'call') {
            return self::call($args[0] ?? '', $args[1] ?? [], $this->instanceToken);
        }
        throw new \BadMethodCallException("Method Rubika->$name not found");
    }

    public static function __callStatic(string $name, array $args)
    {
        if ($name === 'sendMessage') {
            return self::sendMessage($args[0] ?? '', $args[1] ?? '', $args[2] ?? [], $args[3] ?? null);
        }
        if ($name === 'sendBatch') {
            return self::sendBatch($args[0] ?? [], $args[1] ?? '');
        }
        if ($name === 'getMe') {
            return self::getMe($args[0] ?? null);
        }
        if ($name === 'setWebhook') {
            return self::setWebhook($args[0] ?? '', $args[1] ?? null);
        }
        if ($name === 'getUpdates') {
            return self::getUpdates((int)($args[0] ?? 0), (int)($args[1] ?? 100), $args[2] ?? null);
        }
        throw new \BadMethodCallException("Static method Rubika::$name not found");
    }
}
