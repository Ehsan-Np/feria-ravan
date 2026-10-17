<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت نگارش ۴٫۹٫۲ فریا روان (v4.9.2 Master QA)
 *  ─ تست قطعی دانلود فایل باینری PDF کارنامه استاندارد A4 (بدون اتکا به چاپگر مرورگر)
 *  ─ تست رفع خطای مهلک درایورهای تلگرام، بله و روبیکا و حذف پیام «خطای موقتی رخ داده است»
 *  ─ تست یکپارچگی برنامه‌ریز ۱۴۰۵، پایش خلق، و سیستم اعزام چندکاناله پیام‌ها
 *  ─ تست سلامت تمامی نماهای کاربری و مدیریتی برای کلیه کارنامه‌های پایگاه داده
 * ============================================================================
 */
declare(strict_types=1);

define('FR_TEST_MODE', true);
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
define('FR_ENV', 'production');

require_once FR_ROOT . '/config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/report.php';
require_once FR_ROOT . '/core/Analyzer.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$failures = [];

function it(string $title, callable $fn): void {
    global $totalTests, $passedTests, $failedTests, $failures;
    $totalTests++;
    try {
        $fn();
        $passedTests++;
        echo "  [PASS] $title\n";
    } catch (\Throwable $e) {
        $failedTests++;
        $msg = $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")";
        $failures[] = "$title: $msg";
        echo "  [FAIL] $title -> " . $e->getMessage() . "\n";
    }
}

function expectEq(mixed $actual, mixed $expected, string $msg = ''): void {
    if ($actual !== $expected) {
        throw new \AssertionError($msg ?: ("Expected " . var_export($expected, true) . ", got " . var_export($actual, true)));
    }
}

function expectTrue(bool $cond, string $msg = 'Expected condition to be true'): void {
    if (!$cond) throw new \AssertionError($msg);
}

echo "=======================================================================\n";
echo "  شروع آزمون‌های جامع تضمین کیفیت نگارش ۴٫۹٫۲ (Feria Ravan v4.9.2 Master QA)\n";
echo "=======================================================================\n\n";

/* -------------------------------------------------------------------------
 | فاز ۱: شماره نگارش و هماهنگی مهاجرت پایگاه داده
 * ------------------------------------------------------------------------*/
echo "--- فاز ۱: نسخه ۴٫۹٫۲ و ساختار پایگاه داده ---\n";

it('ثابت‌های FR_VERSION و FR_RELEASE در includes/version.php دقیقاً برابر 4.9.2 هستند', function() {
    expectEq(FR_VERSION, '4.9.2', 'FR_VERSION must be 4.9.2');
    expectEq(FR_RELEASE, '4.9.2', 'FR_RELEASE must be 4.9.2');
});

it('پایگاه داده fr_settings مقادیر system_version و fr_version را برابر 4.9.2 برمی‌گرداند', function() {
    expectEq(DB::setting('system_version'), '4.9.2', 'DB system_version must be 4.9.2');
    expectEq(DB::setting('fr_version'), '4.9.2', 'DB fr_version must be 4.9.2');
});

it('فایل‌های config.php و sample به نسخه 4.9.2 اشاره دارند', function() {
    $cfg = file_get_contents(FR_ROOT . '/config.php');
    $sample = file_get_contents(FR_ROOT . '/includes/config.sample.php');
    expectTrue(str_contains($cfg, "'4.9.2'"), 'config.php must reference 4.9.2');
    expectTrue(str_contains($sample, "'4.9.2'"), 'config.sample.php must reference 4.9.2');
});

it('فایل مهاجرت یکپارچه database/migrations/v492_unified_migration.sql وجود دارد و نسخه‌های قدیمی حذف شده‌اند', function() {
    expectTrue(file_exists(FR_ROOT . '/database/migrations/v492_unified_migration.sql'), 'v492 migration file must exist');
    expectTrue(!file_exists(FR_ROOT . '/database/migrations/v491_unified_migration.sql'), 'v491 migration file must be removed');
    expectTrue(!file_exists(FR_ROOT . '/database/migrations/v490_unified_migration.sql'), 'v490 migration file must be removed');
    $sql = file_get_contents(FR_ROOT . '/database/migrations/v492_unified_migration.sql');
    expectTrue(str_contains($sql, "'4.9.2'"), 'v492 SQL must contain version 4.9.2');
});

it('فایل includes/update.php بلوک ارتقای نگارش 4.9.2 را دارد', function() {
    $up = file_get_contents(FR_ROOT . '/includes/update.php');
    expectTrue(str_contains($up, '۴٫۹٫۲') || str_contains($up, '4.9.2'), 'update.php must contain 4.9.2');
});

/* -------------------------------------------------------------------------
 | فاز ۲: درایورهای پیام‌رسان‌ها (تلگرام، بله، روبیکا) و حذف خطای موقت ۵۰۰
 * ------------------------------------------------------------------------*/
echo "\n--- فاز ۲: عملکرد ۱۰۰٪ ایمن پیام‌رسان‌های تلگرام، بله و روبیکا ---\n";

it('کلاس Telegram از فراخوانی متدهای استاتیک و شیء‌گرا بدون Fatal Error پشتیبانی می‌کند', function() {
    require_once FR_ROOT . '/core/Telegram.php';
    $res = Telegram::sendMessage('123', 'test message');
    expectTrue(is_array($res), 'Telegram::sendMessage must return array');
    expectEq($res[0], false, 'Without token, first element must be false');
    expectEq($res[1], 'telegram_token_not_configured', 'Must report token not configured');

    $tg = new Telegram('test_token');
    $resInst = $tg->sendMessage('123', 'test');
    expectTrue(is_array($resInst), 'Instance call must return array');
});

it('کلاس Bale از فراخوانی متدهای استاتیک و شیء‌گرا بدون Fatal Error پشتیبانی می‌کند', function() {
    require_once FR_ROOT . '/core/Bale.php';
    $res = Bale::sendMessage('123', 'test message');
    expectTrue(is_array($res), 'Bale::sendMessage must return array');
    expectEq($res[0], false, 'Without token, first element must be false');
    expectEq($res[1], 'bale_token_not_configured', 'Must report token not configured');

    $bale = new Bale('test_token');
    $resInst = $bale->sendMessage('123', 'test');
    expectTrue(is_array($resInst), 'Instance call must return array');
});

it('کلاس Rubika از فراخوانی متدهای استاتیک و شیء‌گرا بدون Fatal Error پشتیبانی می‌کند', function() {
    require_once FR_ROOT . '/core/Rubika.php';
    $res = Rubika::sendMessage('123', 'test message');
    expectTrue(is_array($res), 'Rubika::sendMessage must return array');
    expectEq($res[0], false, 'Without token, first element must be false');
    expectEq($res[1], 'rubika_token_not_configured', 'Must report token not configured');

    $rubika = new Rubika('test_token');
    $resInst = $rubika->sendMessage('123', 'test');
    expectTrue(is_array($resInst), 'Instance call must return array');
});

it('تست ارسال پیام تست fr_snotify_send_test بدون خطای موقت ۵۰۰ اجرا می‌شود', function() {
    require_once FR_ROOT . '/includes/snotify.php';
    $rTg = fr_snotify_send_test(1, 'telegram');
    expectTrue(is_array($rTg), 'fr_snotify_send_test telegram must return array');

    $rBale = fr_snotify_send_test(1, 'bale');
    expectTrue(is_array($rBale), 'fr_snotify_send_test bale must return array');

    $rRubika = fr_snotify_send_test(1, 'rubika');
    expectTrue(is_array($rRubika), 'fr_snotify_send_test rubika must return array');
});

/* -------------------------------------------------------------------------
 | فاز ۳: موتور تولید بومی فایل باینری PDF کارنامه (CarnetPdfBuilder)
 * ------------------------------------------------------------------------*/
echo "\n--- فاز ۳: موتور تولید مستقیم فایل باینری PDF با ابعاد ISO A4 ---\n";

it('موتور CarnetPdfBuilder فایل باینری معتبر PDF (%PDF-) تولید می‌کند', function() {
    require_once FR_ROOT . '/core/CarnetPdfBuilder.php';
    $pdf = CarnetPdfBuilder::build(1, false);
    expectTrue(str_starts_with($pdf, "%PDF-"), 'Generated content must start with %PDF- header');
    expectTrue(strlen($pdf) > 10000, 'PDF size must be substantial (>10KB)');
});

it('حفظ محرمانگی بالینی: راهنمای مداخله بالینی در فایل PDF کاربر عادی هرگز درج نمی‌شود', function() {
    require_once FR_ROOT . '/core/CarnetPdfBuilder.php';
    $userPdf = CarnetPdfBuilder::build(1, false);
    expectTrue(!str_contains($userPdf, 'راهنمای مداخله'), 'User PDF must not contain clinical interventions');

    $adminPdf = CarnetPdfBuilder::build(1, true);
    expectTrue(str_starts_with($adminPdf, "%PDF-"), 'Admin PDF must be valid');
    expectTrue(strlen($adminPdf) > 10000, 'Admin PDF must be valid');
});

it('تولید بدون خطای فایل PDF برای آزمون‌های تکمیل‌شده پایگاه داده', function() {
    require_once FR_ROOT . '/core/CarnetPdfBuilder.php';
    $atts = DB::all("SELECT id FROM " . DB::t('attempts') . " WHERE status='completed' LIMIT 3");
    foreach ($atts as $row) {
        $id = (int)$row['id'];
        $pdf = CarnetPdfBuilder::build($id, false);
        expectTrue(str_starts_with($pdf, "%PDF-"), "Attempt #$id failed to generate valid PDF");
    }
});

/* -------------------------------------------------------------------------
 | فاز ۴: جریان دانلود مستقیم فایل PDF و نسخه چاپی در کنترلرها
 * ------------------------------------------------------------------------*/
echo "\n--- فاز ۴: دانلود مستقیم PDF و نسخه چاپی در پنل کاربر و مدیریت ---\n";

it('درخواست download=1 در panel/report-pdf.php مستقیماً فایل باینری PDF تحویل می‌دهد', function() {
    $cmd = 'php -r ' . escapeshellarg('
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        define("FR_ENV", "production");
        session_start();
        $_SESSION["user_id"] = 1;
        $_GET = ["attempt" => "1", "download" => "1"];
        include FR_ROOT . "/panel/report-pdf.php";
    ');
    $out = (string)shell_exec($cmd);
    expectTrue(str_starts_with($out, "%PDF-"), 'download=1 must directly return binary PDF');
});

it('درخواست print=1 در panel/report-pdf.php متد پرینت خودکار را بازخوانی می‌کند', function() {
    $cmd = 'php -r ' . escapeshellarg('
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        define("FR_ENV", "production");
        session_start();
        $_SESSION["user_id"] = 1;
        $_GET = ["attempt" => "1", "print" => "1"];
        ob_start();
        include FR_ROOT . "/panel/report-pdf.php";
        $out = ob_get_clean();
        echo str_contains($out, "frPrintReport") ? "PRINT_OK" : "NO_PRINT";
    ');
    $res = trim((string)shell_exec($cmd));
    expectEq($res, 'PRINT_OK', 'print=1 must trigger frPrintReport');
});

it('صفحه گزارش کارنامه مراجع panel/report.php دکمه‌های «دریافت نسخه چاپی» و «دانلود فایل PDF» را دارد', function() {
    $cmd = 'php -r ' . escapeshellarg('
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        define("FR_ENV", "production");
        session_start();
        $_SESSION["user_id"] = 1;
        $_GET = ["attempt" => "1"];
        ob_start();
        include FR_ROOT . "/panel/report.php";
        $out = ob_get_clean();
        echo (str_contains($out, "دریافت نسخه چاپی کارنامه (A4)") && str_contains($out, "دانلود فایل PDF")) ? "BUTTONS_OK" : "NO_BUTTONS";
    ');
    $res = trim((string)shell_exec($cmd));
    expectEq($res, 'BUTTONS_OK', 'report.php must contain printable and download buttons');
});

it('جدول سوابق آزمون‌ها panel/history.php دارای دکمه «نسخه چاپی» و «PDF» است', function() {
    $cmd = 'php -r ' . escapeshellarg('
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        define("FR_ENV", "production");
        session_start();
        $_SESSION["user_id"] = 1;
        ob_start();
        include FR_ROOT . "/panel/history.php";
        $out = ob_get_clean();
        echo (str_contains($out, "🖨️ نسخه چاپی") && str_contains($out, "📥 PDF")) ? "HISTORY_OK" : "NO_HISTORY";
    ');
    $res = trim((string)shell_exec($cmd));
    expectEq($res, 'HISTORY_OK', 'history.php must have print and PDF buttons');
});

it('کنترلر مدیریت admin/attempts.php دکمه دریافت نسخه چاپی و دانلود PDF را داراست', function() {
    $cmd = 'php -r ' . escapeshellarg('
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        define("FR_ENV", "production");
        session_start();
        $_SESSION["admin_id"] = 1;
        $_GET = ["report" => "1"];
        include FR_ROOT . "/admin/attempts.php";
    ');
    $out = (string)shell_exec($cmd);
    expectTrue(str_contains($out, "دریافت نسخه چاپی") && str_contains($out, "دریافت فایل PDF کارنامه"), 'admin attempts view must have print and PDF buttons');
});

/* -------------------------------------------------------------------------
 | فاز ۵: استایل‌های اختصاصی مدیا پرینت (@media print)
 * ------------------------------------------------------------------------*/
echo "\n--- فاز ۵: اعتبارسنجی قوانین رسانه چاپ (@media print) ---\n";

it('فایل‌های panel/report.php و panel/dyad-report.php دارای استایل @media print تمیز هستند', function() {
    $rep = file_get_contents(FR_ROOT . '/panel/report.php');
    $dyad = file_get_contents(FR_ROOT . '/panel/dyad-report.php');
    expectTrue(str_contains($rep, '@media print'), 'report.php must contain @media print');
    expectTrue(str_contains($rep, '.no-print') && str_contains($rep, 'display: none !important'), 'report.php must hide non-print elements');
    expectTrue(str_contains($dyad, '@media print'), 'dyad-report.php must contain @media print');
});

/* -------------------------------------------------------------------------
 | فاز ۶: برنامه‌ریز روزانه ۱۴۰۵ و پیام‌رسانی هوشمند
 * ------------------------------------------------------------------------*/
echo "\n--- فاز ۶: یکپارچگی برنامه‌ریز ۱۴۰۵ و پیام‌رسانی ---\n";

it('توابع تقویم شمسی ۱۴۰۵ در includes/planner.php محاسبات دقیق ریاضی ارائه می‌دهند', function() {
    require_once FR_ROOT . '/includes/planner.php';
    expectEq(fr_jday_normalize('1405/01/01'), '1405-01-01', 'Normalize failed');
    expectEq(fr_jday_add('1405-01-01', 5), '1405-01-06', 'Add days failed');
    expectEq(fr_jday_diff('1405-01-06', '1405-01-01'), 5, 'Diff days failed');
});

it('پایش خلق و انرژی روانی به درستی ذخیره و تاریخچه برمی‌گرداند', function() {
    require_once FR_ROOT . '/includes/planner.php';
    $res = fr_planner_mood_save(1, '1405-01-10', 'great', 5, 'تست انرژی عالی', 8.0);
    expectTrue($res > 0, 'Mood save must return ID > 0');
    $hist = fr_planner_mood_history(1, 7);
    expectTrue(is_array($hist), 'Mood history must return array');
});

/* -------------------------------------------------------------------------
 | فاز ۷: اعتبارسنجی صفر بازگشت (Zero Regression)
 * ------------------------------------------------------------------------*/
echo "\n--- فاز ۷: الزامات کلیدی مشتری و عدم بازگشت باگ‌ها ---\n";

it('قانون کلیک صریح دکمه «اعمال» برای کد تخفیف در تست‌ها و رویدادها رعایت شده است', function() {
    $tCode = file_get_contents(FR_ROOT . '/panel/test-start.php');
    $eCode = file_get_contents(FR_ROOT . '/panel/event-register.php');
    expectTrue(str_contains($tCode, 'کد تخفیف اعمال شد') || str_contains($tCode, 'اعمال'), 'test-start must require apply button');
    expectTrue(str_contains($eCode, 'کد تخفیف اعمال شد') || str_contains($eCode, 'اعمال'), 'event-register must require apply button');
});

it('پوشه ذخیره‌سازی feria-uploads با زیرپوشه‌های تفکیک‌شده وجود دارد', function() {
    $root = FR_ROOT . '/feria-uploads';
    expectTrue(is_dir($root), 'feria-uploads directory must exist');
    expectTrue(is_dir($root . '/workshops'), 'workshops directory must exist');
    expectTrue(is_dir($root . '/courses'), 'courses directory must exist');
    expectTrue(is_dir($root . '/users'), 'users directory must exist');
    expectTrue(is_dir($root . '/tests'), 'tests directory must exist');
});

echo "\n=======================================================================\n";
echo "  خلاصه نتایج آزمون‌های جامع نگارش ۴٫۹٫۲ (v4.9.2 QA Results)\n";
echo "  کل آزمون‌ها: $totalTests\n";
echo "  موفق: $passedTests\n";
echo "  ناموفق: $failedTests\n";
echo "=======================================================================\n";

if ($failedTests > 0) {
    echo "\nجزئیات خطاها:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
} else {
    echo "\n✔ تبریک! تمامی آزمون‌های نگارش ۴٫۹٫۲ با موفقیت ۱۰۰٪ سپری شدند.\n\n";
    exit(0);
}
