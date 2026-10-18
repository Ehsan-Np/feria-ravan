<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت نگارش ۴٫۹٫۳ (Feria Ravan v4.9.3 Master QA)
 *  ─ تجمیع ۳ گزینه (نسخه چاپی، فایل PDF، نسخه A4) به یک گزینه واحد «دریافت فایل PDF کارنامه»
 *  ─ اعتبارسنجی تحویل قطعی فایل باینری PDF (%PDF-) به مراجع و مدیر
 *  ─ بررسی سلامت ورود کاربران (User Authentication / Login / OTP)
 *  ─ حفظ اصل محرمانگی بالینی در خروجی PDF مراجع
 *  ─ آزمون تاب‌آوری و سازگاری پیام‌رسان‌ها، پلنر ۱۴۰۵ و ساختار پایگاه داده
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

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/admin-auth.php';
require_once FR_ROOT . '/includes/report.php';
require_once FR_ROOT . '/core/CarnetPdfBuilder.php';

fr_bootstrap();

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$failures = [];

function it(string $title, callable $fn): void
{
    global $totalTests, $passedTests, $failedTests, $failures;
    $totalTests++;
    try {
        $fn();
        $passedTests++;
        echo "  [PASS] $title\n";
    } catch (\Throwable $e) {
        $failedTests++;
        $failures[] = "$title: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")";
        echo "  [FAIL] $title -> " . $e->getMessage() . "\n";
    }
}

function expectTrue(bool $val, string $msg = 'Assertion failed'): void
{
    if (!$val) throw new \RuntimeException($msg);
}

function expectEq($actual, $expected, string $msg = 'Values are not equal'): void
{
    if ($actual !== $expected) {
        $aStr = is_scalar($actual) ? (string)$actual : json_encode($actual);
        $eStr = is_scalar($expected) ? (string)$expected : json_encode($expected);
        throw new \RuntimeException("$msg (Expected: $eStr, Got: $aStr)");
    }
}

echo "=======================================================================\n";
echo "  شروع آزمون‌های جامع نگارش ۴٫۹٫۳ (Feria Ravan v4.9.3 Master QA)\n";
echo "=======================================================================\n\n";

// ----------------------------------------------------------------------------
// فاز ۱: نگارش ۴٫۹٫۳، ارتقای پایگاه داده و فایل مهاجرت یکپارچه
// ----------------------------------------------------------------------------
echo "--- فاز ۱: نگارش ۴٫۹٫۳ و ساختار پایگاه داده ---\n";

it('ثابت‌های FR_VERSION و FR_RELEASE در includes/version.php برابر 4.9.3 هستند', function() {
    expectEq(FR_VERSION, '4.9.3', 'FR_VERSION must be 4.9.3');
    expectEq(FR_RELEASE, '4.9.3', 'FR_RELEASE must be 4.9.3');
});

it('پایگاه داده system_version و fr_version را برابر 4.9.3 برمی‌گرداند', function() {
    $sysVer = DB::setting('system_version');
    $frVer  = DB::setting('fr_version');
    expectEq($sysVer, '4.9.3', 'DB system_version must be 4.9.3');
    expectEq($frVer, '4.9.3', 'DB fr_version must be 4.9.3');
});

it('فایل مهاجرت یکپارچه database/migrations/v493_unified_migration.sql مستقر است', function() {
    expectTrue(file_exists(FR_ROOT . '/database/migrations/v493_unified_migration.sql'), 'v493 migration must exist');
    expectTrue(!file_exists(FR_ROOT . '/database/migrations/v492_unified_migration.sql'), 'v492 migration must be cleaned');
    $sql = file_get_contents(FR_ROOT . '/database/migrations/v493_unified_migration.sql');
    expectTrue(str_contains($sql, "'system_version', '4.9.3'"), 'v493 migration must reference 4.9.3');
});

it('فایل includes/update.php بلوک ارتقای نگارش 4.9.3 را دارد', function() {
    $updater = file_get_contents(FR_ROOT . '/includes/update.php');
    expectTrue(str_contains($updater, '۴٫۹٫۳') && str_contains($updater, '4.9.3'), 'update.php must contain 4.9.3');
});

// ----------------------------------------------------------------------------
// فاز ۲: بررسی صحت و پایداری ورود کاربر به سامانه (User Auth & Login)
// ----------------------------------------------------------------------------
echo "\n--- فاز ۲: سلامت فرآیند ورود کاربران به سامانه ---\n";

it('صفحه auth.php بدون خطای PHP یا هشدار رندر می‌شود', function() {
    $cmd = 'php -r ' . escapeshellarg('
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/auth.php";
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        define("FR_ENV", "production");
        ob_start();
        include FR_ROOT . "/auth.php";
        $out = ob_get_clean();
        echo str_contains($out, "ورود") ? "AUTH_OK" : "NO_AUTH";
    ');
    $res = trim((string)shell_exec($cmd));
    expectEq($res, 'AUTH_OK', 'auth.php must render properly');
});

it('ارسال کد یک‌بارمصرف ورود (send_otp) در api/auth.php پاسخ معتبر بازمی‌گرداند', function() {
    $user = DB::one("SELECT mobile FROM fr_users WHERE mobile IS NOT NULL AND mobile != '' LIMIT 1");
    if (!$user) {
        DB::insert('users', ['mobile' => '09121112233', 'first_name' => 'کاربر', 'last_name' => 'آزمایشی', 'status' => 'active']);
        $mob = '09121112233';
    } else {
        $mob = (string)$user['mobile'];
    }
    $res = otp_send($mob, 'login');
    expectTrue(!empty($res['ok']), 'otp_send must succeed');
});

// ----------------------------------------------------------------------------
// فاز ۳: تجمیع گزینه‌ها به یک گزینه واحد «دریافت فایل PDF کارنامه»
// ----------------------------------------------------------------------------
echo "\n--- فاز ۳: تجمیع گزینه‌های کارنامه به یک گزینه واحد «دریافت فایل PDF کارنامه» ---\n";

it('صفحه گزارش کارنامه mراجع panel/report.php فقط دکمه «دریافت فایل PDF کارنامه» را دارد و ۳ گزینه قبلی حذف شده‌اند', function() {
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
        $hasSingleBtn = str_contains($out, "📥 دریافت فایل PDF کارنامه");
        $hasOldPrintBtn = str_contains($out, "دریافت نسخه چاپی کارنامه (A4)");
        $hasOldA4Btn = str_contains($out, "مشاهده نسخه A4");
        echo ($hasSingleBtn && !$hasOldPrintBtn && !$hasOldA4Btn) ? "UNIFIED_OK" : "NOT_UNIFIED";
    ');
    $res = trim((string)shell_exec($cmd));
    expectEq($res, 'UNIFIED_OK', 'report.php must only have the single unified PDF button');
});

it('جدول سوابق آزمون‌ها panel/history.php دکمه واحد «📥 دریافت فایل PDF» دارد', function() {
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
        $hasPdf = str_contains($out, "📥 دریافت فایل PDF");
        $hasOldPrint = str_contains($out, "🖨️ نسخه چاپی");
        echo ($hasPdf && !$hasOldPrint) ? "HISTORY_UNIFIED_OK" : "HISTORY_NOT_UNIFIED";
    ');
    $res = trim((string)shell_exec($cmd));
    expectEq($res, 'HISTORY_UNIFIED_OK', 'history.php must have the single unified PDF button');
});

it('نوار ابزار panel/report-pdf.php دکمه واحد دریافت PDF کارنامه دارد', function() {
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
        include FR_ROOT . "/panel/report-pdf.php";
        $out = ob_get_clean();
        $hasUnified = str_contains($out, "📥 دریافت فایل PDF کارنامه");
        $hasOldPrint = str_contains($out, "چاپ با پرینتر (نسخه چاپی)");
        echo ($hasUnified && !$hasOldPrint) ? "TOOLBAR_UNIFIED_OK" : "TOOLBAR_NOT_UNIFIED";
    ');
    $res = trim((string)shell_exec($cmd));
    expectEq($res, 'TOOLBAR_UNIFIED_OK', 'report-pdf.php toolbar must be unified');
});

it('کنترلر مدیریت admin/attempts.php دکمه واحد دریافت PDF کارنامه دارد', function() {
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
    expectTrue(str_contains($out, '📥 دریافت فایل PDF کارنامه'), 'admin attempts must have unified button');
    expectTrue(!str_contains($out, 'مشاهده سند رسمی کارنامه A4'), 'admin attempts must remove old A4 view button');
});

it('صفحه پرونده مراجع admin/user-view.php دکمه واحد دریافت PDF کارنامه دارد', function() {
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
        $_GET = ["id" => "1", "report" => "1"];
        include FR_ROOT . "/admin/user-view.php";
    ');
    $out = (string)shell_exec($cmd);
    expectTrue(str_contains($out, '📥 دریافت فایل PDF کارنامه'), 'admin user-view must have unified button');
    expectTrue(!str_contains($out, '📄 مشاهده نسخه A4'), 'admin user-view must remove old A4 view button');
});

// ----------------------------------------------------------------------------
// فاز ۴: تحویل قطعی فایل باینری PDF و حفظ محرمانگی بالینی
// ----------------------------------------------------------------------------
echo "\n--- فاز ۴: تحویل قطعی فایل باینری PDF و حفظ محرمانگی بالینی ---\n";

it('موتور CarnetPdfBuilder فایل باینری معتبر PDF (%PDF-) تولید می‌کند', function() {
    $pdf = CarnetPdfBuilder::build(1, false);
    expectTrue(str_starts_with($pdf, '%PDF-'), 'CarnetPdfBuilder output must start with %PDF-');
    expectTrue(strlen($pdf) > 20000, 'PDF size must be substantial (>20KB)');
});

it('مکانیزم تاب‌آوری Fallback در CarnetPdfBuilder فایل باینری معتبر برمی‌گرداند', function() {
    $ref = new \ReflectionClass('CarnetPdfBuilder');
    $m = $ref->getMethod('buildFallbackPdf');
    $m->setAccessible(true);
    $pdf = $m->invoke(null, 1, false, 'Simulated failure');
    expectTrue(str_starts_with($pdf, '%PDF-'), 'Fallback PDF must be valid %PDF-');
});

it('درخواست download=1 مستقیماً فایل باینری PDF با هدر استاندارد پیوست برمی‌گرداند', function() {
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
    expectTrue(str_starts_with($out, '%PDF-'), 'download=1 must directly return %PDF- binary');
});

it('حفظ محرمانگی بالینی: راهنمای مداخله بالینی در فایل PDF کاربر عادی هرگز درج نمی‌شود', function() {
    $userPdf = CarnetPdfBuilder::build(1, false);
    expectTrue(!str_contains($userPdf, 'راهنمای مداخله'), 'User PDF must NEVER contain clinical intervention guide');
    expectTrue(!str_contains($userPdf, 'پروتکل شواهدمحور'), 'User PDF must NEVER contain evidence-based protocols');
});

// ----------------------------------------------------------------------------
// فاز ۵: تاب‌آوری پیام‌رسان‌ها و درایورهای تلگرام، بله و روبیکا
// ----------------------------------------------------------------------------
echo "\n--- فاز ۵: عملکرد ۱۰۰٪ ایمن پیام‌رسان‌های تلگرام، بله و روبیکا ---\n";

it('کلاس‌های Telegram، Bale و Rubika بدون خطای نوع یا Fatal فراخوانی می‌شوند', function() {
    require_once FR_ROOT . '/core/Telegram.php';
    require_once FR_ROOT . '/core/Bale.php';
    require_once FR_ROOT . '/core/Rubika.php';

    $rTg = Telegram::sendMessage('', 'test');
    expectEq($rTg[0], false);
    $rBale = Bale::sendMessage('', 'test');
    expectEq($rBale[0], false);
    $rRub = Rubika::sendMessage('', 'test');
    expectEq($rRub[0], false);
});

// ----------------------------------------------------------------------------
// فاز ۶: یکپارچگی محاسبات تقویم شمسی ۱۴۰۵
// ----------------------------------------------------------------------------
echo "\n--- فاز ۶: یکپارچگی تقویم شمسی ۱۴۰۵ ---\n";

it('توابع تقویم شمسی ۱۴۰۵ در includes/planner.php محاسبات دقیق ریاضی ارائه می‌دهند', function() {
    require_once FR_ROOT . '/includes/planner.php';
    expectEq(fr_jday_normalize('1405/01/01'), '1405-01-01', 'Normalize failed');
    expectEq(fr_jday_add('1405-01-01', 5), '1405-01-06', 'Add days failed');
    expectEq(fr_jday_diff('1405-01-06', '1405-01-01'), 5, 'Diff days failed');
});

echo "\n=======================================================================\n";
echo "  خلاصه نتایج آزمون‌های نگارش ۴٫۹٫۳ (v4.9.3 QA Results)\n";
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
}

echo "\n✔ تبریک! تمامی آزمون‌های نگارش ۴٫۹٫۳ با موفقیت ۱۰۰٪ سپری شدند.\n\n";
exit(0);
