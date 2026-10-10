<?php
/**
 * ============================================================================
 *  سرویس پیامک کاوه‌نگار — ارسال عادی (send) + ارسال پترن (verify/otp)
 *  مستندات: https://kavenegar.com/rest.html
 *  قابلیت شخصی‌سازی کامل متن‌ها از پنل ادمین + لاگ کامل ارسال
 * ============================================================================
 */
declare(strict_types=1);

final class Kavenegar
{
    private string $apiKey;
    private string $sender;
    private bool   $enabled;

    public function __construct()
    {
        $this->apiKey  = (string)DB::setting('kavenegar_apikey', '');
        $this->sender  = (string)DB::setting('kavenegar_sender', '');
        $this->enabled = DB::setting('kavenegar_enabled', '0') === '1' && $this->apiKey !== '';
    }

    /** فراخوانی REST */
    private function call(string $method, string $action, array $params): array
    {
        $url = "https://api.kavenegar.com/v1/{$this->apiKey}/{$method}/{$action}.json";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => http_build_query($params),
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    /** ثبت لاگ پیامک در دیتابیس (همراه IP و دستگاه) */
    private function log(string $mobile, ?string $event, string $body, string $status, ?string $providerId = null, ?int $cost = null): void
    {
        DB::insert('sms_logs', [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'mobile'      => $mobile, 'event' => $event, 'body' => $body,
            'status'      => $status, 'provider_id' => $providerId, 'cost' => $cost,
            'ip'          => client_ip_bin(),
            'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }

    /**
     * ارسال پیامک ساده (متن دلخواه)
     */
    public function sendDirect(string $mobile, string $message, ?string $event = null): bool
    {
        return $this->send($mobile, $message, $event);
    }

    public function send(string $mobile, string $message, ?string $event = null): bool
    {
        [$ok] = $this->sendDetailed($mobile, $message, $event);
        return $ok;
    }

    /**
     * ۲٫۷ — همان ارسال ساده ولی با ارجاع پیامک (messageid کاوه‌نگار)
     * خروجی: [ok, messageid|null] — برای رهگیری وضعیت تحویل در کمپین‌ها.
     */
    public function sendDetailed(string $mobile, string $message, ?string $event = null): array
    {
        if (!$this->enabled) { $this->log($mobile, $event, $message, 'disabled'); return [false, null]; }
        $res = $this->call('sms', 'send', [
            'receptor' => en_num($mobile), 'message' => $message,
            'sender'   => $this->sender ?: null,
        ]);
        $ok  = ($res['return']['status'] ?? 0) === 200;
        $ref = $ok ? (string)($res['entries'][0]['messageid'] ?? '') : null;
        $this->log($mobile, $event, $message, $ok ? 'sent' : 'failed', $ref,
            $res['entries'][0]['cost'] ?? null);
        return [$ok, $ref !== '' ? $ref : null];
    }

    /**
     * ارسال با پترن/الگو (VerifyLookup کاوه‌نگار)
     * توکن‌ها: token, token2, token3, token10 (مثلاً لینک) و token20
     */
    public function sendPattern(string $mobile, string $patternCode, array $tokens, ?string $event = null, ?string $renderedText = null): bool
    {
        $logBody = !empty($renderedText) ? $renderedText : ('الگوی کاوه‌نگار (' . $patternCode . ') — مقادیر: ' . implode(' | ', $tokens));
        if (!$this->enabled) { $this->log($mobile, $event, $logBody, 'disabled'); return false; }
        $params = ['receptor' => en_num($mobile), 'template' => $patternCode];
        // نگاشت آرایهٔ متغیرها به token های کاوه‌نگار
        $map = ['token','token2','token3','token10','token20'];
        $i = 0;
        foreach ($tokens as $t) { if (isset($map[$i])) $params[$map[$i++]] = (string)$t; }
        $res = $this->call('verify', 'lookup', $params);
        $ok  = ($res['return']['status'] ?? 0) === 200;
        $this->log($mobile, $event, $logBody, $ok ? 'sent' : 'failed',
            $ok ? (string)($res['entries'][0]['messageid'] ?? '') : null);
        return $ok;
    }

    /**
     * ارسال پیامک «رویدادی» سیستم: خواندن قالب از DB، جایگذاری متغیرها،
     * اگر پترن تعریف شده باشد از Verify استفاده می‌کند.
     */
    public function sendEvent(string $event, string $mobile, array $vars): bool
    {
        $tpl = DB::one('SELECT * FROM ' . DB::t('sms_templates') . ' WHERE event=? AND enabled=1', [$event]);
        if (!$tpl) return false;
        $vars += ['site' => DB::setting('site_title', 'فریا روان')];
        /* ۱٫۹: مبلغ همیشه با جداکنندهٔ هزارگان چاپ شود — فراخواننده‌ها معمولاً
           مبلغ خام (int) می‌فرستند و قالب %amount% باید خوانا رندر شود */
        $vars['amount'] = number_format((int)($vars['amount'] ?? 0));
        $fullText = tpl_vars($tpl['body'] ?? '', $vars);
        if (!empty($tpl['use_pattern']) && $tpl['pattern_code']) {
            // در پترن، محتوا همان توکن‌ها هستند و متن کامل رندرشده برای ثبت لاگ ذخیره می‌شود
            return $this->sendPattern($mobile, $tpl['pattern_code'], $vars['_tokens'] ?? array_values($vars), $event, $fullText);
        }
        return $this->send($mobile, $fullText, $event);
    }

    /** ارسال پیامک تست با متن دلخواه از پنل ادمین */
    public function sendTest(string $mobile, string $message): bool
    {
        return $this->send($mobile, $message, 'admin_test');
    }

    /**
     * ۲٫۷ — استعلام وضعیت تحویل پیامک‌ها (متد sms/status):
     * ورودی: آرایه‌ای از messageidها (حداکثر ~۵۰؛ خودش تکه‌تکه می‌کند).
     * خروجی: [ messageid => ['status'=>int,'statustext'=>string] ]
     * کدهای رایج کاوه‌نگار: ۱ در صف ارسال • ۲ زمان‌بندی • ۳ ارسال به اپراتور •
     * ۴ تحویل به گیرنده ✅ • ۵ خطا در ارسال • ۶ عدم تحویل • ۱۰ ناموجود/منقضی
     */
    public function deliveryStatus(array $messageIds): array
    {
        if (!$this->enabled || !$messageIds) return [];
        $out = [];
        foreach (array_chunk(array_values(array_filter(array_map('strval', $messageIds))), 50) as $chunk) {
            $res = $this->call('sms', 'status', ['messageid' => implode(',', $chunk)]);
            if (($res['return']['status'] ?? 0) !== 200) continue;
            foreach ((array)($res['entries'] ?? []) as $e) {
                $out[(string)$e['messageid']] = [
                    'status'     => (int)($e['status'] ?? 0),
                    'statustext' => (string)($e['statustext'] ?? ''),
                    'date'       => (int)($e['date'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /** برچسب فارسی کد تحویل کاوه‌نگار (برای گزارش‌ها) */
    public static function deliveryLabel(int $code): string
    {
        return match ($code) {
            1       => 'در صف ارسال',
            2       => 'زمان‌بندی‌شده',
            3       => 'ارسال به اپراتور',
            4       => 'تحویل به گیرنده ✅',
            5       => 'خطا در ارسال ❌',
            6       => 'عدم تحویل ❌',
            10      => 'ناموجود/منقضی',
            default => 'نامشخص',
        };
    }

    /** آیا پیامک سرانجام تحویل شده؟ */
    public static function isDelivered(int $code): bool { return $code === 4; }

    /** آیا پیامک سرانجام ناموفق بوده؟ */
    public static function isFailedFinal(int $code): bool { return in_array($code, [5, 6, 10], true); }
}
