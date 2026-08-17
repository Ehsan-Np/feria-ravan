<?php
/**
 * ============================================================================
 *  درگاه سازمانی پیام‌رسان (نسخهٔ ۲٫۷) — آداپتور عمومی ارسال پیام در «بله»
 *  ─ وضعیت بازار (شهریور ۱۴۰۵): هیچ‌یک از وب‌سرویس‌های عمومی پیامک (از جمله
 *    کاوه‌نگار) در مستندات عمومی خود «API ارسال خودکار در پیام‌رسان بله»
 *    ارائه نکرده‌اند؛ بله سرویس‌های سازمانی (ارسال انبوه + OTP رایگان) را از
 *    مسیر bale.ai/org و با هماهنگی تجاری ارائه می‌کند و API ربات رسمی (tapi.bale.ai)
 *    هم «پیش از start توسط کاربر» اجازهٔ پیام مستقیم نمی‌دهد — این محدودیتِ
 *    خودِ پلتفرم‌هاست، نه سیستم ما.
 *  ─ این آداپتور برای آینده آماده است: هرگاه هر درگاهی (بله سازمانی، کاوه‌نگار
 *    یا هر ارائه‌دهندهٔ دیگر) نشانی Endpoint ارسال در اختیارتان بگذارد، کافی است
 *    آن را در پیشخوان ← اطلاع‌رسانی ← «درگاه پیام‌رسان» وارد کنید؛ سیستم تمام
 *    ارسال‌های بله را به‌جای ربات رسمی، از همان درگاه عبور می‌دهد.
 *  ─ قرارداد درگاه: POST JSON به نشانی پایه
 *      { "chat_id": "...", "text": "...", "token": "<کلید درگاه>" }
 *    نشانی می‌تواند شامل جای‌نگهدار {token} باشد (با کلید جایگذاری می‌شود).
 * ============================================================================
 */
declare(strict_types=1);

final class MsgGateway
{
    /** آیا درگاه سازمانی برای بله فعال است؟ (نشانی پایه باید پر باشد) */
    public static function baleEnabled(): bool
    {
        return (string)DB::setting('bale_gateway_enabled', '0') === '1'
            && trim((string)DB::setting('bale_gateway_url', '')) !== '';
    }

    /** نشانی نهایی با جایگذاری توکن */
    public static function endpoint(): string
    {
        $url   = trim((string)DB::setting('bale_gateway_url', ''));
        $token = trim((string)DB::setting('bale_gateway_token', ''));
        if ($url === '') return '';
        /* دو الگوی رایج: {token} در نشانی، یا کلید به‌عنوان پارامتر JSON */
        if (str_contains($url, '{token}')) {
            $url = str_replace('{token}', rawurlencode($token), $url);
        }
        return $url;
    }

    /**
     * ارسال پیام از طریق درگاه سازمانی. خروجی: [ok, response]
     * خطاها همگام با ربات رسمی گزارش می‌شوند تا در گزارش‌ها قابل‌تمایز باشند.
     */
    public static function sendBale(string $chatId, string $text): array
    {
        $url = self::endpoint();
        if ($url === '') return [false, 'gateway_not_configured'];
        $payload = [
            'chat_id' => $chatId,
            'text'    => $text,
            'token'   => trim((string)DB::setting('bale_gateway_token', '')),
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errNo || $raw === false) return [false, 'gateway_curl_error'];
        $res = json_decode((string)$raw, true);
        /* سازگاری با دو قالب پاسخ رایج: Bot API ({ok:true}) و وب‌سرویس SMS-ای ({return:{status:200}}) */
        $ok = (is_array($res) && (!empty($res['ok']) || ((int)($res['return']['status'] ?? 0) === 200)))
            || ($code >= 200 && $code < 300 && !is_array($res));
        if (!$ok) error_log('feria: bale-gateway failed http=' . $code . ' body=' . mb_substr((string)$raw, 0, 300));
        return [$ok, $res ?: $raw];
    }
}
