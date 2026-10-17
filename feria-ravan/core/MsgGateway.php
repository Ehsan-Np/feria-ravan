<?php
/**
 * ============================================================================
 *  درگاه یکپارچه پیام‌رسان‌ها (MsgGateway — نسخهٔ ۴٫۹٫۰)
 *  ─ مدیریت متمرکز ارسال پیام و ارکستراسیون تلگرام، بله و روبیکا
 *  ─ پشتیبانی از درگاه‌های وب‌سرویس مستقیم، بات‌های اختصاصی و درگاه‌های سازمانی
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/Telegram.php';
require_once __DIR__ . '/Bale.php';
require_once __DIR__ . '/Rubika.php';

final class MsgGateway
{
    /** بررسی پشتیبانی از پیام‌رسان */
    public static function isSupported(string $channel): bool
    {
        return in_array(strtolower(trim($channel)), ['telegram', 'bale', 'rubika'], true);
    }

    /** لیست کانال‌های پیام‌رسان پشتیبانی‌شده */
    public static function supportedChannels(): array
    {
        return ['telegram', 'bale', 'rubika'];
    }

    /** ارسال پیام هوشمند بر اساس کانال انتخابی */
    public static function send(string $channel, string $chatId, string $text, array $opts = []): array
    {
        return match (strtolower(trim($channel))) {
            'telegram' => Telegram::sendMessage($chatId, $text, $opts),
            'bale'     => self::baleEnabled() ? self::sendBale($chatId, $text) : Bale::sendMessage($chatId, $text, $opts),
            'rubika'   => Rubika::sendMessage($chatId, $text, $opts),
            default    => [false, 'unsupported_channel'],
        };
    }

    /** ارسال پیام تلگرام */
    public static function sendTelegram(string $chatId, string $text, array $opts = []): array
    {
        return Telegram::sendMessage($chatId, $text, $opts);
    }

    /** ارسال پیام روبیکا */
    public static function sendRubika(string $chatId, string $text, array $opts = []): array
    {
        return Rubika::sendMessage($chatId, $text, $opts);
    }

    /** آیا درگاه سازمانی برای بله فعال است؟ */
    public static function baleEnabled(): bool
    {
        return (string)DB::setting('bale_gateway_enabled', '0') === '1'
            && trim((string)DB::setting('bale_gateway_url', '')) !== '';
    }

    /** نشانی نهایی درگاه سازمانی بله با جایگذاری توکن */
    public static function endpoint(): string
    {
        $url   = trim((string)DB::setting('bale_gateway_url', ''));
        $token = trim((string)DB::setting('bale_gateway_token', ''));
        if ($url === '') return '';
        if (str_contains($url, '{token}')) {
            $url = str_replace('{token}', rawurlencode($token), $url);
        }
        return $url;
    }

    /**
     * ارسال پیام بله از طریق درگاه سازمانی
     */
    public static function sendBale(string $chatId, string $text): array
    {
        $url = self::endpoint();
        if ($url === '') {
            return Bale::sendMessage($chatId, $text);
        }

        $payload = [
            'chat_id' => $chatId,
            'text'    => $text,
            'token'   => trim((string)DB::setting('bale_gateway_token', '')),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo || $raw === false) return [false, 'gateway_curl_error'];
        $res = json_decode((string)$raw, true);
        $ok = (is_array($res) && (!empty($res['ok']) || ((int)($res['return']['status'] ?? 0) === 200)))
            || ($code >= 200 && $code < 300 && !is_array($res));

        if (!$ok) error_log('feria: bale-gateway failed http=' . $code . ' body=' . mb_substr((string)$raw, 0, 300));
        return [$ok, $res ?: $raw];
    }
}
