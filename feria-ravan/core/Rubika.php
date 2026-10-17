<?php
/**
 * ============================================================================
 *  درایور پیام‌رسان روبیکا (Rubika Bot API Client — نسخهٔ ۴٫۹٫۰)
 *  ─ مستندات رسمی: Bot API روبیکا (botapi.rubika.ir)
 *  ─ پشتیبانی کامل از احراز هویت توکن، ارسال پیام متنی، ارسال گروهی (Batch)،
 *    وب‌هوک هوشمند، وضعیت ربات (getMe) و Polling (getUpdates)
 *  ─ پشتیبانی هم‌زمان از فراخوانی استاتیک و شیء‌گرا (Static & Instance)
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
     * ارسال پیام متنی به کاربر یا کانال روبیکا
     * @return array [bool $ok, array|string $response]
     */
    public function sendMessage(string $chatId, string $text, array $opts = []): array
    {
        $token = $this->instanceToken;
        return self::sendMessageStatic($chatId, $text, $opts, $token);
    }

    public static function sendMessageStatic(string $chatId, string $text, array $opts = [], ?string $tokenOverride = null): array
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

    /** ارسال دسته‌ای یک پیام به چند شناسه کاربری */
    public function sendBatch(array $chatIds, string $text): array
    {
        $results = [];
        foreach ($chatIds as $cid) {
            $results[$cid] = $this->sendMessage((string)$cid, $text);
        }
        return $results;
    }

    public static function sendBatchStatic(array $chatIds, string $text): array
    {
        $inst = new self();
        return $inst->sendBatch($chatIds, $text);
    }

    /** دریافت اطلاعات بات */
    public function getMe(): array
    {
        return self::call('getMe', [], $this->instanceToken);
    }

    public static function getMeStatic(): array
    {
        return self::call('getMe', []);
    }

    /** دریافت آپدیت‌ها و پیام‌های جدید دریافتی */
    public function getUpdates(int $offset = 0, int $limit = 100): array
    {
        return self::call('getUpdates', ['offset' => $offset, 'limit' => $limit], $this->instanceToken);
    }

    /** تنظیم آدرس وب‌هوک روبیکا */
    public function setWebhook(string $url): array
    {
        return self::call('setWebhook', ['url' => $url], $this->instanceToken);
    }

    /** فراخوانی متد دلخواه در Bot API روبیکا */
    public static function call(string $method, array $payload, ?string $tokenOverride = null): array
    {
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
                'User-Agent: FeriaRavan-Rubika-Client/4.9.0'
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
    }

    /** سازگاری با فراخوانی‌های استاتیک مستقیم sendMessage و غیره */
    public static function __callStatic(string $name, array $args)
    {
        if ($name === 'sendMessage') {
            return self::sendMessageStatic($args[0] ?? '', $args[1] ?? '', $args[2] ?? [], $args[3] ?? null);
        }
        if ($name === 'sendBatch') {
            return self::sendBatchStatic($args[0] ?? [], $args[1] ?? '');
        }
        if ($name === 'getMe') {
            return self::getMeStatic();
        }
        if ($name === 'setWebhook') {
            $inst = new self();
            return $inst->setWebhook($args[0] ?? '');
        }
        if ($name === 'getUpdates') {
            $inst = new self();
            return $inst->getUpdates((int)($args[0] ?? 0), (int)($args[1] ?? 100));
        }
        throw new \BadMethodCallException("Static method Rubika::$name not found");
    }
}
