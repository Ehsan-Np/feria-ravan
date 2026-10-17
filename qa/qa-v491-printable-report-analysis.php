<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های تضمین کیفیت فریا روان — نگارش ۴٫۹٫۱ (v4.9.1 QA Suite)
 *  ─ اعتبارسنجی ارتقای نگارش به ۴٫۹٫۱ در ثابت‌ها، پایگاه‌داده و مایگریشن یکپارچه
 *  ─ حل قطعی و تضمین دریافت و چاپ نسخه چاپی کارنامه آزمون (Printable Analysis)
 *  ─ وجود دکمه‌های واضح و اختصاصی «🖨️ دریافت نسخه چاپی کارنامه (A4)» در پنل کاربر و ادمین
 *  ─ تعبیه دکمه‌های چاپ و خروجی رسمی در بالا و انتهای صفحه کارنامه (panel/report.php)
 *  ─ فعال‌سازی استایل اختصاصی @media print در صفحه گزارش جهت چاپ تمیز و بدون سایدبار
 *  ─ پردازش خودکار پارامتر print=1 و فراخوانی خودکار دستور چاپ (window.frPrintReport)
 *  ─ تبدیل بهینه نمودارهای بوم (Canvas) به تصاویر برداری باکیفیت قبل از ارسال به چاپ
 *  ─ تضمین دانلود مستقیم فایل سند چاپی (download_file=1) به عنوان پیوست قابل ذخیره
 *  ─ حفظ ۱۰۰ درصدی محرمانگی بالینی برای مراجعین و دسترسی کامل مدیران
 * ============================================================================
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('FR_TEST_MODE', true);
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/includes/report.php';
require_once FR_ROOT . '/core/Analyzer.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = [];

function it(string $title, callable $fn): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    try {
        $fn();
        $passedTests++;
        echo "  [PASS] {$title}\n";
    } catch (\Throwable $e) {
        $failedTests[] = ['title' => $title, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
        echo "  [FAIL] {$title} -> " . $e->getMessage() . "\n";
    }
}

function expectTrue(bool $val, string $msg = ''): void {
    if (!$val) throw new \RuntimeException($msg ?: 'Assertion failed: expected true');
}

function expectEq($actual, $expected, string $msg = ''): void {
    if ($actual !== $expected) {
        throw new \RuntimeException($msg ?: sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true)));
    }
}

echo "=======================================================================\n";
echo "  شروع آزمون‌های تضمین کیفیت نگارش ۴٫۹٫۱ (Printable Report Analysis QA)\n";
echo "=======================================================================\n\n";

// ============================================================================
// فاز ۱: ارتقای نگارش به ۴٫۹٫۱ و سلامت مایگریشن یکپارچه
// ============================================================================
echo "--- فاز ۱: نسخه ۴٫۹٫۱ و ساختار مهاجرت پایگاه داده ---\n";

it('ثابت‌های FR_VERSION و FR_RELEASE در includes/version.php برابر 4.9.1 هستند', function() {
    expectEq(FR_VERSION, '4.9.1', 'FR_VERSION must be 4.9.1');
    expectEq(FR_RELEASE, '4.9.1', 'FR_RELEASE must be 4.9.1');
});

it('پایگاه داده system_version و fr_version برابر 4.9.1 است', function() {
    $sysVer = DB::setting('system_version');
    $frVer = DB::setting('fr_version');
    expectEq($sysVer, '4.9.1', "DB system_version must be 4.9.1, got: $sysVer");
    expectEq($frVer, '4.9.1', "DB fr_version must be 4.9.1, got: $frVer");
});

it('فایل‌های config.php و includes/config.sample.php به نسخه 4.9.1 اشاره دارند', function() {
    $cfg = file_get_contents(FR_ROOT . '/config.php');
    $sample = file_get_contents(FR_ROOT . '/includes/config.sample.php');
    expectTrue(str_contains($cfg, "'4.9.1'"), 'config.php must reference 4.9.1');
    expectTrue(str_contains($sample, "'4.9.1'"), 'config.sample.php must reference 4.9.1');
});

it('فایل مهاجرت یکپارچه database/migrations/v491_unified_migration.sql وجود دارد و v490 حذف شده است', function() {
    expectTrue(file_exists(FR_ROOT . '/database/migrations/v491_unified_migration.sql'), 'v491 migration must exist');
    expectTrue(!file_exists(FR_ROOT . '/database/migrations/v490_unified_migration.sql'), 'v490 migration must be removed');
    $sql = file_get_contents(FR_ROOT . '/database/migrations/v491_unified_migration.sql');
    expectTrue(str_contains($sql, "'system_version', '4.9.1'"), 'v491 SQL must set system_version to 4.9.1');
});

it('فایل includes/update.php بلوک ارتقای نگارش 4.9.1 را دارد', function() {
    $updater = file_get_contents(FR_ROOT . '/includes/update.php');
    expectTrue(str_contains($updater, '۴٫۹٫۱') || str_contains($updater, '4.9.1'), 'update.php must contain 4.9.1');
});

// ============================================================================
// فاز ۲: امکان دریافت نسخه چاپی در پنل کاربری (panel/report.php)
// ============================================================================
echo "\n--- فاز ۲: امکان دریافت نسخه چاپی در صفحه کارنامه مراجع (panel/report.php) ---\n";

it('صفحه گزارش کارنامه دارای دکمه برجسته «دریافت نسخه چاپی کارنامه (A4)» در نوار بالای صفحه است', function() {
    $_SESSION['user_id'] = 1;
    $_GET = ['attempt' => '1'];

    ob_start();
    include FR_ROOT . '/panel/report.php';
    $out = ob_get_clean();

    expectTrue(str_contains($out, '🖨️ دریافت نسخه چاپی کارنامه (A4)'), 'Must contain print version button at top');
    expectTrue(str_contains($out, 'report-pdf.php?attempt=1&amp;print=1') || str_contains($out, 'report-pdf.php?attempt=1&print=1'), 'Button must link to print=1');
    expectTrue(str_contains($out, '📥 دانلود فایل PDF'), 'Must contain direct PDF download button');
    expectTrue(str_contains($out, '📄 مشاهده نسخه A4'), 'Must contain view A4 button');
    expectTrue(str_contains($out, '🖨️ چاپ سریع صفحه'), 'Must contain quick print button');
});

it('صفحه کارنامه مراجع دارای کارت اختصاصی دریافت نسخه چاپی و دانلود در انتهای کارنامه است', function() {
    $_SESSION['user_id'] = 1;
    $_GET = ['attempt' => '1'];

    ob_start();
    include FR_ROOT . '/panel/report.php';
    $out = ob_get_clean();

    expectTrue(str_contains($out, '🖨️ دریافت و چاپ کارنامه رسمی آزمون'), 'Must contain bottom print card');
    expectTrue(str_contains($out, 'شما می‌توانید کارنامه رسمی ارزیابی روان‌شناختی خود را با سربرگ معتبر'), 'Must contain explanatory text');
});

it('صفحه کارنامه مراجع دارای استایل @media print جهت چاپ تمیز بدون سایدبار و المان‌های اضافه است', function() {
    $_SESSION['user_id'] = 1;
    $_GET = ['attempt' => '1'];

    ob_start();
    include FR_ROOT . '/panel/report.php';
    $out = ob_get_clean();

    expectTrue(str_contains($out, '@media print'), 'Must contain @media print styles');
    expectTrue(str_contains($out, '.panel-sidebar'), 'Print styles must target sidebar');
    expectTrue(str_contains($out, '.fr-report-actions'), 'Print styles must hide action toolbars');
    expectTrue(str_contains($out, 'page-break-inside: avoid'), 'Print styles must prevent awkward page breaks inside cards');
});

// ============================================================================
// فاز ۳: دکمه نسخه چاپی در سوابق آزمون‌ها (panel/history.php)
// ============================================================================
echo "\n--- فاز ۳: دکمه نسخه چاپی در جدول سوابق آزمون‌ها (panel/history.php) ---\n";

it('جدول سوابق آزمون‌ها دارای دکمه مشخص «🖨️ نسخه چاپی» با پیوند مستقیم به print=1 است', function() {
    $_SESSION['user_id'] = 1;
    $_GET = [];

    ob_start();
    include FR_ROOT . '/panel/history.php';
    $out = ob_get_clean();

    expectTrue(str_contains($out, '🖨️ نسخه چاپی'), 'Must contain 🖨️ نسخه چاپی button');
    expectTrue(str_contains($out, 'print=1'), 'Must link to print=1 parameter');
    expectTrue(str_contains($out, '📥 PDF'), 'Must contain 📥 PDF button');
});

// ============================================================================
// فاز ۴: دسترسی به نسخه چاپی در پنل مدیریت (admin/attempts.php و admin/user-view.php)
// ============================================================================
echo "\n--- فاز ۴: دسترسی به نسخه چاپی در بخش مدیریت ---\n";

it('کنترلر مدیریت admin/attempts.php در نمای تکی کارنامه مراجع دارای دکمه‌های دریافت نسخه چاپی در بالا و پایین است', function() {
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

    expectTrue(str_contains($out, '🖨️ دریافت نسخه چاپی'), 'Admin top toolbar must contain print button');
    expectTrue(str_contains($out, '🖨️ چاپ و خروجی رسمی کارنامه مراجع'), 'Admin bottom card must exist');
    expectTrue(str_contains($out, 'admin/report-pdf.php?attempt=1&amp;print=1') || str_contains($out, 'admin/report-pdf.php?attempt=1&print=1'), 'Must link to admin report-pdf with print=1');
});

it('جدول فهرست مراجعین admin/attempts.php دارای دکمه «🖨️ نسخه چاپی» است', function() {
    $_SESSION['admin_id'] = 1;
    $_GET = [];

    ob_start();
    include FR_ROOT . '/admin/attempts.php';
    $out = ob_get_clean();

    expectTrue(str_contains($out, '🖨️ نسخه چاپی'), 'Admin attempts table must contain print button');
});

it('پرونده کاربر در مدیریت admin/user-view.php دارای دکمه «🖨️ دریافت نسخه چاپی» است', function() {
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

    expectTrue(str_contains($out, '🖨️ دریافت نسخه چاپی (A4)'), 'Admin user-view must contain print button');
});

// ============================================================================
// فاز ۵: موتور اختصاصی برگه چاپی و PDF کارنامه (panel/report-pdf.php)
// ============================================================================
echo "\n--- فاز ۵: موتور برگه رسمی استاندارد A4 و پردازش پرینت (panel/report-pdf.php) ---\n";

it('نوار ابزار بالای کارنامه دارای دکمه شاخص «🖨️ چاپ با پرینتر (نسخه چاپی)» است', function() {
    $_SESSION['user_id'] = 1;
    $_GET = ['attempt' => '1'];

    ob_start();
    include FR_ROOT . '/panel/report-pdf.php';
    $out = ob_get_clean();

    expectTrue(str_contains($out, '🖨️ چاپ با پرینتر (نسخه چاپی)'), 'Toolbar must contain print button');
    expectTrue(str_contains($out, 'frPrintReport()'), 'Print button must call frPrintReport()');
    expectTrue(str_contains($out, '📥 دانلود فایل PDF کارنامه (A4)'), 'Toolbar must contain PDF button');
    expectTrue(str_contains($out, '💾 دریافت مستقیم فایل'), 'Toolbar must contain direct document download button');
});

it('اسکریپت صفحه دارای متد تبدیل بوم‌ها به تصاویر برداری frConvertCanvases قبل از چاپ است', function() {
    $_SESSION['user_id'] = 1;
    $_GET = ['attempt' => '1'];

    ob_start();
    include FR_ROOT . '/panel/report-pdf.php';
    $out = ob_get_clean();

    expectTrue(str_contains($out, 'window.frConvertCanvases'), 'Must define frConvertCanvases');
    expectTrue(str_contains($out, 'toDataURL(\'image/png\', 1.0)'), 'Must convert canvas to high-DPI data URL');
    expectTrue(str_contains($out, 'window.frPrintReport'), 'Must define frPrintReport');
});

it('درخواست با پارامتر print=1 به صورت خودکار frPrintReport را فراخوانی می‌کند', function() {
    $_SESSION['user_id'] = 1;
    $_GET = ['attempt' => '1', 'print' => '1'];

    ob_start();
    include FR_ROOT . '/panel/report-pdf.php';
    $out = ob_get_clean();

    expectTrue(str_contains($out, 'isAutoPrint'), 'Must include auto print detection');
    expectTrue(str_contains($out, 'setTimeout(window.frPrintReport'), 'Must trigger frPrintReport on isAutoPrint');
});

it('درخواست با پارامتر download_file=1 دارای هدرهای پیوست Content-Disposition است', function() {
    $code = file_get_contents(FR_ROOT . '/panel/report-pdf.php');
    expectTrue(str_contains($code, "\$_GET['download_file'] === '1'"), 'Must handle download_file=1');
    expectTrue(str_contains($code, 'Content-Disposition: attachment'), 'Must set Content-Disposition: attachment');
});

// ============================================================================
// فاز ۶: ارزیابی کارنامه چاپی در تمامی آزمون‌های تکمیل‌شده پایگاه‌داده
// ============================================================================
echo "\n--- فاز ۶: رندرینگ بدون خطای نسخه چاپی برای تمامی آزمون‌های دیتابیس ---\n";

it('تمامی کارنامه‌های تکمیل‌شده پایگاه‌داده برای مدیر و کاربر بدون خطای ۵۰۰ رندر می‌شوند', function() {
    $completedAttempts = DB::all("SELECT a.id, a.user_id, a.mode, t.title FROM " . DB::t('attempts') . " a
                                  JOIN " . DB::t('tests') . " t ON t.id=a.test_id
                                  WHERE a.status='completed'");

    expectTrue(count($completedAttempts) >= 10, 'Must have at least 10 completed attempts in DB');

    foreach ($completedAttempts as $ca) {
        $attId = (int)$ca['id'];
        $uId = (int)$ca['user_id'];

        // ۱. رندر برای مدیر سایت
        $_SESSION['admin_id'] = 1;
        unset($_SESSION['user_id']);
        $_GET = ['attempt' => (string)$attId];
        ob_start();
        include FR_ROOT . '/admin/report-pdf.php';
        $adminOut = ob_get_clean();

        expectTrue(strlen($adminOut) > 10000, "Attempt #{$attId} produced empty/too small admin output");
        expectTrue(!str_contains($adminOut, 'Fatal error'), "Attempt #{$attId} produced fatal error for admin");
        expectTrue(!str_contains($adminOut, 'خطای موقتی رخ داده است'), "Attempt #{$attId} produced 500 error for admin");

        // ۲. رندر برای کاربر مراجع
        $_SESSION['user_id'] = $uId;
        unset($_SESSION['admin_id']);
        $_GET = ['attempt' => (string)$attId];
        ob_start();
        include FR_ROOT . '/panel/report-pdf.php';
        $userOut = ob_get_clean();

        expectTrue(strlen($userOut) > 10000, "Attempt #{$attId} produced empty/too small user output");
        expectTrue(!str_contains($userOut, 'Fatal error'), "Attempt #{$attId} produced fatal error for user");
        expectTrue(!str_contains($userOut, 'خطای موقتی رخ داده است'), "Attempt #{$attId} produced 500 error for user");

        // ۳. بررسی قانون قطعی محرمانگی بالینی برای مراجعین
        expectTrue(!str_contains($userOut, '🎯 راهنمای مداخلهٔ بالینی و پروتکل شواهدمحور'),
            "Clinical protocol must be strictly stripped for user on attempt #{$attId}");
    }
});

// ============================================================================
// خلاصه نتایج
// ============================================================================
echo "\n=======================================================================\n";
echo "  خلاصه نتایج آزمون‌های نگارش ۴٫۹٫۱ (v4.9.1 QA Results)\n";
echo "  کل آزمون‌ها: {$totalTests}\n";
echo "  موفق: {$passedTests}\n";
echo "  ناموفق: " . count($failedTests) . "\n";
echo "=======================================================================\n";

if (!empty($failedTests)) {
    echo "\nجزئیات خطاها:\n";
    foreach ($failedTests as $f) {
        echo " - {$f['title']}: {$f['error']} ({$f['file']}:{$f['line']})\n";
    }
    exit(1);
} else {
    echo "\n✔ تبریک! تمامی آزمون‌های نسخه چاپی کارنامه آزمون با موفقیت ۱۰۰٪ سپری شدند.\n\n";
    exit(0);
}
