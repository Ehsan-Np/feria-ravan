<?php
/**
 * فریا روان — کلاینت سبک ربات پیام‌رسان «بله»
 * مستندات مرجع: https://docs.bale.ai  (بله از همان API ربات تلگرام با دامنهٔ tapi.bale.ai پشتیبانی می‌کند)
 * معماری توسعه‌پذیر: متدهای جدید API را همین‌جا اضافه کنید؛ ورودی/خروجی با Telegram یکسان نگه داشته شده.
 * نسخهٔ ۲٫۱
 */
final class Bale
{
    private const API = 'https://tapi.bale.ai';

    /** آیا بله آمادهٔ ارسال است؟ (ربات رسمی یا درگاه سازمانی — ۲٫۷) */
    public static function enabled(): bool
    {
        if (trim((string)DB::setting('bale_bot_token', '')) !== '') return true;
        /* ۲٫۷ — حالت درگاه سازمانی: بدون توکن ربات هم ارسال از مسیر درگاه ممکن است */
        return (string)DB::setting('bale_gateway_enabled', '0') === '1'
            && trim((string)DB::setting('bale_gateway_url', '')) !== '';
    }

    /** ارسال پیام متنی به یک چت در بله. برمی‌گرداند [ok, response] */
    public static function sendMessage(string $chatId, string $text, array $opts = []): array
    {
        /* ۲٫۷ — درگاه سازمانی پیام‌رسان (اگر فعال باشد جایگزین ربات می‌شود) */
        if ((string)DB::setting('bale_gateway_enabled', '0') === '1') {
            require_once __DIR__ . '/MsgGateway.php';
            if (MsgGateway::baleEnabled()) return MsgGateway::sendBale($chatId, $text);
        }
        $token = trim((string)DB::setting('bale_bot_token', ''));
        if ($token === '' || $chatId === '') return [false, 'bot_not_configured'];
        $payload = array_merge([
            'chat_id' => $chatId,
            'text'    => $text,
            'disable_web_page_preview' => true,
        ], $opts);
        return self::call('sendMessage', $payload, $token);
    }

    /** فراخوانی خام هر متد API بله */
    public static function call(string $method, array $payload, ?string $tokenOverride = null): array
    {
        $token = $tokenOverride ?? trim((string)DB::setting('bale_bot_token', ''));
        if ($token === '') return [false, 'no_token'];
        $url = self::API . '/bot' . $token . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errNo || $raw === false) return [false, 'curl_error'];
        $res = json_decode((string)$raw, true);
        $ok = is_array($res) && !empty($res['ok']);
        if (!$ok) error_log('feria: bale ' . $method . ' failed http=' . $code . ' body=' . mb_substr((string)$raw, 0, 300));
        return [$ok, $res ?: $raw];
    }

    /** نام کاربری ربات بله برای نمایش راهنما در پنل کاربر */
    public static function botUsername(): string
    {
        return trim((string)DB::setting('bale_bot_username', ''));
    }
}
