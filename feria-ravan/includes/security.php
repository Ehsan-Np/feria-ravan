<?php
/**
 * ============================================================================
 *  لایهٔ امنیت — Session سخت‌گیرانه، CSRF، Rate-Limit، هدرهای امنیتی، لاگ
 * ============================================================================
 */
declare(strict_types=1);

/** شروع سشن امن */
function fr_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('FRSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                       // غیرقابل دسترس برای JS
        'samesite' => 'Lax',                      // ضد CSRF پایه
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    // چرخش شناسهٔ سشن هر ۳۰ دقیقه (ضد Session Fixation)
    if (!isset($_SESSION['_rotated'])) {
        $_SESSION['_rotated'] = time();
    } elseif (time() - $_SESSION['_rotated'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_rotated'] = time();
    }
}

/** هدرهای امنیتی — روی همهٔ خروجی‌ها */
function fr_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');                 // ضد Clickjacking
    // ارسال origin برای سازگاری کامل با الزامات اعتبارسنجی نماد اعتماد الکترونیکی (اینماد)، ساماندهی و درگاه‌ها
    header('Referrer-Policy: origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (FR_ENV === 'production') {
        // سیاست CSP استاندارد و امن سازگار با نمادهای اعتماد رسمی (اینماد، زرین‌پال، ساماندهی، شاپرک)
        header("Content-Security-Policy: default-src 'self' data: https: 'unsafe-inline' 'unsafe-eval'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://trustseal.enamad.ir https://*.enamad.ir https://*.zarinpal.com https://cdn.zarinpal.com https://*.samandehi.ir https://*.shaparak.ir https://*.ecunion.ir; frame-src 'self' https://trustseal.enamad.ir https://*.enamad.ir https://*.zarinpal.com https://*.samandehi.ir https://*.shaparak.ir; connect-src 'self' https://trustseal.enamad.ir https://*.enamad.ir https://*.zarinpal.com https://cdn.zarinpal.com https://*.samandehi.ir; font-src 'self' data: https:; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
    }
}

/* ---------------------------------------------------------------------------
 | CSRF Token
 * -------------------------------------------------------------------------*/
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}
/** بررسی توکن — POST و AJAX (header X-CSRF و بادی JSON) */
function csrf_check(): bool
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ($_SERVER['HTTP_X_XSRF_TOKEN'] ?? ($GLOBALS['FR_JSON_CSRF'] ?? '')));
    if (!$t && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
        $raw = @file_get_contents('php://input');
        if ($raw) {
            $parsed = @json_decode($raw, true);
            if (!empty($parsed['_csrf']) && is_string($parsed['_csrf'])) {
                $t = $parsed['_csrf'];
            }
        }
    }
    return is_string($t) && !empty($t) && hash_equals($_SESSION['_csrf'] ?? '', $t);
}
/** اگر CSRF نامعتبر بود، قطع اجرا */
function csrf_guard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        // درخواست‌های احراز هویت اولیه ورود (ارسال OTP، تأیید و ورود) با ریت‌لیمیت دیتابیسی امن شده‌اند و نباید با نوسان سشن مسدود شوند
        if (str_contains($uri, '/api/auth.php') || str_ends_with($uri, 'auth.php')) {
            return;
        }

        if (!csrf_check()) {
            http_response_code(419);
            /* نسخهٔ ۲٫۰: هر دو کلید error و message برگردانده می‌شود (JS سایت message را
               نمایش می‌دهد) + پرچم csrf_expired تا کلاینت صفحه را خودکار تازه‌سازی کند */
            $isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')
                   || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json')
                   || str_contains($uri, '/api/');
            if ($isJson) {
                json_out(['ok'=>false,'csrf_expired'=>true,
                    'error'  =>'نشست امنیتی منقضی شده؛ صفحه را تازه کنید.',
                    'message'=>'نشست امنیتی منقضی شده است؛ صفحه به‌صورت خودکار تازه‌سازی می‌شود…']);
            }
            json_out(['ok'=>false,'message'=>'نشست امنیتی منقضی شده است. لطفاً صفحه را تازه‌سازی کنید.']);
        }
    }
}
/** نام مستعار برای سازگاری فراخوانی‌های قدیمی یا افزونه‌ها */
function check_csrf(): void
{
    csrf_guard();
}

/* ---------------------------------------------------------------------------
 | محدودکنندهٔ نرخ درخواست (Rate Limit) بر مبنای دیتابیس
 |  مثال: rate_limit('otp:0912...', 5, 600) → حداکثر ۵ بار در ۱۰ دقیقه
 * -------------------------------------------------------------------------*/
function rate_limit(string $bucket, int $maxHits, int $windowSec): bool
{
    try {
        $t = DB::t('rate_limits');
        $bucket = substr($bucket . '|' . client_ip(), 0, 80);
        $row = DB::one("SELECT * FROM $t WHERE bucket=?", [$bucket]);
        $now = date('Y-m-d H:i:s');
        if (!$row || strtotime($row['reset_at']) < time()) {
            DB::run("REPLACE INTO $t (bucket,hits,reset_at) VALUES (?,?,?)",
                [$bucket, 1, date('Y-m-d H:i:s', time()+$windowSec)]);
            return true;
        }
        if ((int)$row['hits'] >= $maxHits) return false;
        DB::run("UPDATE $t SET hits=hits+1 WHERE bucket=?", [$bucket]);
        return true;
    } catch (Throwable $e) {
        // در صورت عدم وجود جدول در مراحل اولیه نصب یا خطا، درخواست مسدود نشود
        return true;
    }
}

/* ---------------------------------------------------------------------------
 | صدور و ثبت لاگ فعالیت (نظارت کامل ادمین)
 * -------------------------------------------------------------------------*/
function log_activity(string $action, ?string $entity = null, ?int $entityId = null, array $meta = []): void
{
    static $done = []; // از ثبت تکراریِ یک اکشن در یک ریکوئست جلوگیری می‌کند
    $k = $action . ($entityId ?? '');
    if (isset($done[$k])) return;
    $done[$k] = true;

    $actorType = 'guest'; $actorId = null;
    if (!empty($_SESSION['user_id']))  { $actorType = 'user';  $actorId = $_SESSION['user_id']; }
    if (!empty($_SESSION['admin_id'])) { $actorType = 'admin'; $actorId = $_SESSION['admin_id']; }

    try {
        DB::insert('activity_logs', [
            'actor_type' => $actorType === 'guest' ? 'user' : $actorType,
            'actor_id'   => $actorId,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'meta'       => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'ip'         => client_ip_bin(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'device'     => client_device(),
        ]);
    } catch (Throwable $e) {}
}

/* ---------------------------------------------------------------------------
 | سربارگذاری اولیهٔ همهٔ صفحات — تنها فایلی که همه include می‌کنند
 * -------------------------------------------------------------------------*/
function fr_bootstrap(): void
{
    /* مهم: قبل از لود config ثابت FR_ROOT تعریف نمی‌کنیم تا با نصب‌های قدیمی
       (تعریف مستقیم در config.php) هشدار redefine رخ ندهد — مسیر با __DIR__ می‌گیریم */
    $root = dirname(__DIR__);
    /* نسخهٔ ۱٫۹: نگارش کد (FR_RELEASE) پیش از کانفیگ کاربر — مستقل از config.php
       تا به‌روزرسانی خودکار حتی با حفظ config.php قدیمی کاربر فعال شود */
    require_once $root . '/includes/version.php';
    require_once $root . '/config.php';
    if (!defined('FR_ROOT')) define('FR_ROOT', $root);      // سازگاری با کانفیگ‌های قدیمی

    if (defined('FR_ENV') && FR_ENV === 'production') {
        ini_set('display_errors', '0');
        error_reporting(0);
    }
    // لاگ خطاهای سرور (خارج از دسترس وب)
    ini_set('error_log', FR_ROOT . '/storage/logs/php-error.log');
    fr_fatal_guard();                                        // جایگزینِ «صفحهٔ سفید» در خطاهای ناگهانی

    fr_session_start();
    fr_security_headers();
    date_default_timezone_set('Asia/Tehran');
    csrf_guard();

    /* نسخهٔ ۱٫۹: خط لولهٔ به‌روزرسانی خودکار — پس از آپلود نسخهٔ جدید روی سرور،
       تغییرات امن دیتابیس + بذر محتوای تازه (تست‌های روانشناسی جدید) در اولین ریکوئست
       اعمال می‌شود؛ در حالت عادی فقط یک خواند تنظیمات است */
    require_once __DIR__ . '/update.php';
    fr_update_pipeline();
}

/* ---------------------------------------------------------------------------
 | نگهبان خطاهای کشنده — در پروداکشن به‌جای صفحهٔ سفید، پیام فارسی + لاگ سرور
 * -------------------------------------------------------------------------*/
function fr_fatal_guard(): void
{
    if (!defined('FR_ENV') || FR_ENV !== 'production') return;      // در توسعه خطاها دیده شود
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        // ثبت در لاگ سرور (display خاموش است؛ جزئیات فقط در فایل لاگ)
        error_log('Feria Fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $isApi = str_contains($uri, '/api/')
              || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json')
              || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json');

        while (ob_get_level() > 0) { @ob_end_clean(); }

        if ($isApi) {
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'      => false,
                'message' => 'خطای موقت در سرور؛ لطفاً چند لحظه بعد دوباره تلاش کنید.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (headers_sent()) return;                                 // خروجی ناقص ارسال شده؛ دست‌کاری نکن
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>خطای موقت | فریا روان</title></head>'
           . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
           . 'background:#f4f6fa;font-family:Tahoma,sans-serif;padding:20px">'
           . '<div style="background:#fff;border-radius:22px;box-shadow:0 10px 40px rgba(30,58,110,.12);'
           . 'max-width:460px;width:100%;padding:40px 32px;text-align:center;border:1px solid #e8ecf4">'
           . '<div style="font-size:46px">🛠</div>'
           . '<h2 style="color:#3b2b86;font-size:20px;margin:14px 0 8px">خطای موقتی رخ داده است</h2>'
           . '<p style="color:#655e8f;font-size:14px;line-height:2">'
           . 'جزئیات در لاگ سرور ثبت شد و تیم فنی مطلع می‌شود.<br>لطفاً چند لحظهٔ دیگر دوباره تلاش کنید.</p>'
           . '<a href="/" style="display:inline-block;background:#1e3a6e;color:#fff;border-radius:12px;'
           . 'padding:12px 28px;text-decoration:none;font-size:14px;margin-top:10px">بازگشت به خانه</a>'
           . '</div></body></html>';
    });
}
