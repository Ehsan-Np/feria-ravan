<?php
/**
 * ============================================================================
 *  سرویس پیامک کاوه‌نگار — ارسال عادی (send) + ارسال پترن (verify/lookup)
 *  مستندات: https://kavenegar.com/rest.html
 *  قابلیت شخصی‌سازی کامل متن‌ها از پنل ادمین + لاگ کامل ارسال + ارسال پترنی پرسرعت OTP
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
        $this->apiKey  = trim((string)DB::setting('kavenegar_apikey', ''));
        $this->sender  = trim((string)DB::setting('kavenegar_sender', ''));
        $this->enabled = DB::setting('kavenegar_enabled', '0') === '1' && $this->apiKey !== '';
    }

    /** آیا درگاه پیامک فعال و دارای کلید معتبر است؟ */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getSender(): string
    {
        return $this->sender;
    }

    private static bool $schemaEnsured = false;

    /**
     * تضمین خودکار ساختار جدول قالب‌های پیامک و ردیف‌های پیش‌فرض
     */
    public static function ensureTemplatesSchema(): void
    {
        if (self::$schemaEnsured) return;
        self::$schemaEnsured = true;

        try {
            $p = DB_PREFIX;
            // ساخت جدول قالب‌ها در صورت عدم وجود
            DB::run("CREATE TABLE IF NOT EXISTS `{$p}sms_templates` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `event` VARCHAR(60) NOT NULL UNIQUE,
                `title` VARCHAR(150) NULL,
                `body` TEXT NOT NULL,
                `use_pattern` TINYINT(1) NOT NULL DEFAULT 0,
                `pattern_code` VARCHAR(80) NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // بررسی ستون‌های use_pattern و pattern_code
            $cols = array_column(DB::all("SHOW COLUMNS FROM `{$p}sms_templates`"), 'Field');
            if (!in_array('use_pattern', $cols, true)) {
                DB::run("ALTER TABLE `{$p}sms_templates` ADD COLUMN `use_pattern` TINYINT(1) NOT NULL DEFAULT 0 AFTER `body`");
            }
            if (!in_array('pattern_code', $cols, true)) {
                DB::run("ALTER TABLE `{$p}sms_templates` ADD COLUMN `pattern_code` VARCHAR(80) NULL AFTER `use_pattern`");
            }
            if (!in_array('title', $cols, true)) {
                DB::run("ALTER TABLE `{$p}sms_templates` ADD COLUMN `title` VARCHAR(150) NULL AFTER `event`");
            }

            // تضمین وجود قالب‌های ضروری سیستم
            $defaults = self::getDefaultTemplates();
            foreach ($defaults as $ev => $d) {
                $exists = (bool)DB::val("SELECT id FROM `{$p}sms_templates` WHERE event=?", [$ev]);
                if (!$exists) {
                    try {
                        DB::insert('sms_templates', [
                            'event'        => $ev,
                            'title'        => $d['title'],
                            'body'         => $d['body'],
                            'use_pattern'  => (int)($d['use_pattern'] ?? 0),
                            'pattern_code' => $d['pattern_code'] ?? null,
                            'enabled'      => 1,
                        ]);
                    } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {
            error_log('feria ensureTemplatesSchema: ' . $e->getMessage());
        }
    }

    /**
     * فهرست قالب‌های پیش‌فرض سیستم فریا روان
     */
    public static function getDefaultTemplates(): array
    {
        return [
            'otp' => [
                'title'        => 'کد یک‌بارمصرف ورود و ثبت‌نام',
                'body'         => 'کد تأیید ورود شما به %site%: %code%',
                'use_pattern'  => 1,
                'pattern_code' => 'otp',
                'enabled'      => 1,
            ],
            'register' => [
                'title'        => 'خوش‌آمدگویی پس از ثبت‌نام',
                'body'         => '%name% عزیز، به %site% خوش آمدید 🦢',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
            'payment_ok' => [
                'title'        => 'پس از پرداخت موفق (قالب پیش‌فرض)',
                'body'         => '%name% عزیز، پرداخت شما با کد رهگیری %refid% در %site% ثبت شد.',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
            'test_done' => [
                'title'        => 'پس از تکمیل تست روانشناسی',
                'body'         => '%name% عزیز، آزمون «%test%» شما با موفقیت ثبت شد و نتایج در پنل کاربری آماده است. %site%',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
            'report_free' => [
                'title'        => 'پس از صدور تحلیل رایگان',
                'body'         => '%name% عزیز، کارنامه مقدماتی آزمون «%test%» برای شما صادر شد: %link%',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
            'report_full' => [
                'title'        => 'پس از صدور تحلیل ویژه',
                'body'         => '%name% عزیز، گزارش تحلیلی کامل شما برای آزمون «%test%» آماده مشاهده است: %link%',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
            'admin_new_order' => [
                'title'        => 'اطلاع ثبت‌نام/خرید جدید به مدیر',
                'body'         => 'ثبت‌نام جدید در %site%: %name% (%mobile%) — %test% — مبلغ %amount%',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
            'refund_request_user' => [
                'title'        => 'عودت وجه — تأیید دریافت درخواست به کاربر',
                'body'         => '%name% عزیز، درخواست عودت وجه شما به مبلغ %amount% ثبت شد و در صف بررسی است؛ نتیجه پیامک می‌شود. %site%',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
            'refund_request_admin' => [
                'title'        => 'عودت وجه — اطلاع به مدیر/حسابداری',
                'body'         => 'درخواست عودت وجه جدید: %name% (%mobile%) مبلغ %amount% — علت: %reason%',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
            'test_nudge' => [
                'title'        => 'یادآوری آزمون نیمه‌تمام',
                'body'         => '%name% عزیز، آزمون شما در %site% نیمه‌تمام است؛ برای تکمیل و دریافت کارنامه مراجعه فرمایید: %link%',
                'use_pattern'  => 0,
                'pattern_code' => null,
                'enabled'      => 1,
            ],
        ];
    }

    /**
     * فراخوانی وب‌سرویس REST کاوه‌نگار با پشتیبانی دوگانه از cURL و Stream Context
     */
    private function call(string $method, string $action, array $params): array
    {
        if ($this->apiKey === '') return [];
        $url = "https://api.kavenegar.com/v1/{$this->apiKey}/{$method}/{$action}.json";

        // ۱. تلاش با cURL در صورت در دسترس بودن
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_POSTFIELDS     => http_build_query($params),
            ]);
            $raw = curl_exec($ch);
            $errNo = curl_errno($ch);
            $errStr = curl_error($ch);
            curl_close($ch);

            if ($raw !== false && $raw !== '') {
                $json = json_decode((string)$raw, true);
                if (is_array($json)) {
                    $status = (int)($json['return']['status'] ?? 0);
                    if ($status !== 200) {
                        $msg = (string)($json['return']['message'] ?? 'ناشناخته');
                        error_log("Kavenegar API {$method}/{$action} status={$status}: {$msg}");
                    }
                    return $json;
                }
            }
            if ($errNo) {
                error_log("Kavenegar cURL error ({$method}/{$action}) #{$errNo}: {$errStr}");
            }
        }

        // ۲. تلاش با stream_context (fallback در صورت فقدان یا خطای cURL)
        try {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
                    'content'       => http_build_query($params),
                    'timeout'       => 4,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw !== false && $raw !== '') {
                $json = json_decode((string)$raw, true);
                if (is_array($json)) return $json;
            }
        } catch (Throwable $e) {
            error_log("Kavenegar stream error ({$method}/{$action}): " . $e->getMessage());
        }

        return [];
    }

    /** ثبت لاگ پیامک در دیتابیس (همراه IP و دستگاه) */
    private function log(string $mobile, ?string $event, string $body, string $status, ?string $providerId = null, ?int $cost = null): void
    {
        try {
            DB::insert('sms_logs', [
                'user_id'     => $_SESSION['user_id'] ?? null,
                'mobile'      => clean_mobile($mobile),
                'event'       => $event,
                'body'        => $body,
                'status'      => $status,
                'provider_id' => $providerId,
                'cost'        => $cost,
                'ip'          => client_ip_bin(),
                'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Throwable $e) {}
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
     * ارسال ساده با بازگرداندن messageid کاوه‌نگار
     * خروجی: [ok, messageid|null]
     */
    public function sendDetailed(string $mobile, string $message, ?string $event = null): array
    {
        $mobile = clean_mobile($mobile);
        if (!$this->enabled) {
            $this->log($mobile, $event, $message, 'disabled');
            return [false, null];
        }

        $sender = $this->sender ?: trim((string)DB::setting('kavenegar_sender', ''));
        $params = [
            'receptor' => $mobile,
            'message'  => $message,
        ];
        if ($sender !== '') {
            $params['sender'] = $sender;
        }

        $res = $this->call('sms', 'send', $params);
        $status = (int)($res['return']['status'] ?? 0);
        $ok  = ($status === 200);
        $ref = $ok ? (string)($res['entries'][0]['messageid'] ?? '') : null;
        $errMsg = !$ok ? (string)($res['return']['message'] ?? 'خطا در ارتباط') : '';

        $this->log($mobile, $event, $message, $ok ? 'sent' : ('failed: ' . $errMsg), $ref, $res['entries'][0]['cost'] ?? null);
        return [$ok, $ref !== '' ? $ref : null];
    }

    /**
     * ارسال با پترن/الگو (VerifyLookup کاوه‌نگار)
     * جهت عبور از بلک‌لیست تبلیغاتی و ارسال فوری کدهای احراز هویت
     */
    public function sendPattern(string $mobile, string $patternCode, array $tokens, ?string $event = null, ?string $renderedText = null): bool
    {
        $mobile = clean_mobile($mobile);
        $patternCode = trim($patternCode);
        if ($patternCode === '') {
            $patternCode = trim((string)DB::setting('kavenegar_otp_pattern', 'otp'));
            if ($patternCode === '') $patternCode = 'otp';
        }

        $tokenList = array_values(array_map('strval', $tokens));
        $logBody = !empty($renderedText) ? $renderedText : ('الگوی کاوه‌نگار (' . $patternCode . ') — مقادیر: ' . implode(' | ', $tokenList));

        if (!$this->enabled) {
            $this->log($mobile, $event, $logBody, 'disabled');
            return false;
        }

        $params = [
            'receptor' => $mobile,
            'template' => $patternCode,
        ];

        // نگاشت آرایهٔ مقادیر به tokenهای کاوه‌نگار (token, token2, token3, token10, token20)
        $tokenKeys = ['token', 'token2', 'token3', 'token10', 'token20'];
        foreach ($tokenList as $idx => $val) {
            if (isset($tokenKeys[$idx])) {
                $params[$tokenKeys[$idx]] = (string)$val;
            }
        }

        $res = $this->call('verify', 'lookup', $params);
        $status = (int)($res['return']['status'] ?? 0);
        $ok = ($status === 200);
        $ref = $ok ? (string)($res['entries'][0]['messageid'] ?? '') : null;
        $errMsg = !$ok ? (string)($res['return']['message'] ?? 'پترن رد شد') : '';

        $this->log($mobile, $event, $logBody, $ok ? 'sent' : ('failed: ' . $errMsg), $ref);
        return $ok;
    }

    /**
     * ارسال پیامک «رویدادی» سیستم:
     * - خواندن قالب از دیتابیس با فال‌بک خودکار به قالب‌های پیش‌فرض
     * - در صورت فعال بودن پترن، ارسال سریع VerifyLookup و فال‌بک به پیامک مستقیم در صورت بروز خطا
     */
    public function sendEvent(string $event, string $mobile, array $vars): bool
    {
        $mobile = clean_mobile($mobile);
        self::ensureTemplatesSchema();

        $tpl = DB::one('SELECT * FROM ' . DB::t('sms_templates') . ' WHERE event=? AND enabled=1', [$event]);
        if (!$tpl) {
            // استفاده از قالب پیش‌فرض سیستمی در صورت نبود رکورد در دیتابیس
            $defaults = self::getDefaultTemplates();
            $tpl = $defaults[$event] ?? null;
            if (!$tpl) {
                error_log("feria: unknown SMS event '{$event}'");
                return false;
            }
        }

        $vars += ['site' => DB::setting('site_title', 'فریا روان')];
        if (isset($vars['amount'])) {
            $vars['amount'] = number_format((int)$vars['amount']);
        }

        $fullText = tpl_vars($tpl['body'] ?? '', $vars);

        // ۱. اگر ارسال پترنی فعال است و کد پترن تعریف شده است
        if (!empty($tpl['use_pattern']) && !empty($tpl['pattern_code'])) {
            $tokens = $vars['_tokens'] ?? array_values(array_filter($vars, fn($k) => !str_starts_with((string)$k, '_'), ARRAY_FILTER_USE_KEY));
            $sent = $this->sendPattern($mobile, (string)$tpl['pattern_code'], $tokens, $event, $fullText);
            if ($sent) return true;

            // در صورت عدم موفقیت پترن، تلاش جایگزین با پیامک مستقیم
            error_log("feria: pattern send for '{$event}' failed, trying direct SMS fallback for {$mobile}");
        }

        // ۲. ارسال پیامک مستقیم متنی
        $directSent = $this->send($mobile, $fullText, $event);
        if ($directSent) return true;

        // ۳. اگر ارسال مستقیم برای رویداد OTP ناموفق بود و پترن هنوز امتحان نشده بود، تلاش با پترن پیش‌فرض
        if ($event === 'otp' && empty($tpl['use_pattern'])) {
            $defaultPattern = trim((string)DB::setting('kavenegar_otp_pattern', 'otp')) ?: 'otp';
            $tokens = $vars['_tokens'] ?? [($vars['code'] ?? '')];
            return $this->sendPattern($mobile, $defaultPattern, $tokens, $event, $fullText);
        }

        return false;
    }

    /** ارسال پیامک تست با متن دلخواه از پنل ادمین */
    public function sendTest(string $mobile, string $message): bool
    {
        return $this->send($mobile, $message, 'admin_test');
    }

    /**
     * استعلام وضعیت تحویل پیامک‌ها (متد sms/status):
     * خروجی: [ messageid => ['status'=>int,'statustext'=>string] ]
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

    /** برچسب فارسی کد تحویل کاوه‌نگار */
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

    public static function isDelivered(int $code): bool { return $code === 4; }
    public static function isFailedFinal(int $code): bool { return in_array($code, [5, 6, 10], true); }
}
