<?php
/**
 * فریا روان — کلاینت سبک ربات تلگرام (Bot API)
 * مستندات مرجع: https://core.telegram.org/bots/api
 * معماری توسعه‌پذیر: برای افزودن قابلیت‌های جدید (دکمه، وب‌هوک و…) متد هم‌نام API را همین‌جا اضافه کنید.
 * نسخهٔ ۲٫۱
 */
final class Telegram
{
    private const API = 'https://api.telegram.org';

    /** آیا ربات تلگرام پیکربندی شده؟ */
    public static function enabled(): bool
    {
        return trim((string)DB::setting('telegram_bot_token', '')) !== '';
    }

    /** ارسال پیام متنی/اچ‌تی‌ام‌ال به یک چت. برمی‌گرداند [ok, response] */
    public static function sendMessage(string $chatId, string $text, array $opts = []): array
    {
        $token = trim((string)DB::setting('telegram_bot_token', ''));
        if ($token === '' || $chatId === '') return [false, 'bot_not_configured'];
        $payload = array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $opts);
        return self::call('sendMessage', $payload, $token);
    }

    /** فراخوانی خام هر متد Bot API */
    public static function call(string $method, array $payload, ?string $tokenOverride = null): array
    {
        $token = $tokenOverride ?? trim((string)DB::setting('telegram_bot_token', ''));
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
        if (!$ok) error_log('feria: telegram ' . $method . ' failed http=' . $code . ' body=' . mb_substr((string)$raw, 0, 300));
        return [$ok, $res ?: $raw];
    }

    /** نام کاربری ربات برای نمایش راهنما در پنل کاربر */
    public static function botUsername(): string
    {
        return trim((string)DB::setting('telegram_bot_username', ''));
    }
}
