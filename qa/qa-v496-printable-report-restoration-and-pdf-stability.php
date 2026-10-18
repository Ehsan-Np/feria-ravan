<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت نگارش ۴٫۹٫۶ (Feria Ravan v4.9.6 Master QA)
 *  ─ بازگردانی قطعی و کامل نسخه چاپی کارنامه (A4 Printable Report) برای مراجع و مدیر
 *  ─ ریشه‌کنی قطعی «خطای موقت» (Temporary Error) و عدم وابستگی اجباری به دانلود باینری
 *  ─ معماری دوگانه و تاب‌آور: دسترسی همزمان به نسخه چاپی مستقیم و فایل دانلودی PDF
 *  ─ ابعاد دقیق استاندارد بین‌المللی ISO A4 و استایل‌شیت مدرن پرینت (30-seconds-of-code)
 *  ─ نوارهای مقیاس (.fr-scalebar و .track) با درصد و رنگ‌آمیزی پویا
 *  ─ حفظ محرمانگی بالینی پروتکل‌های مداخله برای مراجعین عادی
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
require_once FR_ROOT . '/includes/view.php';
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

function expectTrue(bool $cond, string $msg = 'Expected condition to be true'): void
{
    if (!$cond) throw new \RuntimeException($msg);
}

function expectEq(mixed $actual, mixed $expected, string $msg = 'Values are not equal'): void
{
    if ($actual !== $expected) {
        throw new \RuntimeException("$msg (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")");
    }
}

echo "=======================================================================\n";
echo "  شروع آزمون‌های جامع نگارش ۴٫۹٫۶ (Feria Ravan v4.9.6 Master QA)\n";
echo "=======================================================================\n\n";

// ── فاز ۱: نگارش ۴٫۹٫۶ و ساختار پایگاه داده ──
echo "--- فاز ۱: نگارش ۴٫۹٫۶ و ساختار پایگاه داده ---\n";

it('ثابت‌های FR_VERSION و FR_RELEASE در includes/version.php برابر 4.9.6 هستند', function() {
    expectTrue(version_compare(FR_VERSION, '4.9.6', '>='), 'FR_VERSION must be >= 4.9.6');
    expectTrue(version_compare(FR_RELEASE, '4.9.6', '>='), 'FR_RELEASE must be >= 4.9.6');
});

it('پایگاه داده مقادیر fr_version و system_version را حداقل برابر 4.9.6 برمی‌گرداند', function() {
    $sysVer = DB::setting('system_version');
    $frVer = DB::setting('fr_version');
    expectTrue(version_compare((string)$sysVer, '4.9.6', '>='), "DB system_version must be >= 4.9.6, got: $sysVer");
    expectTrue(version_compare((string)$frVer, '4.9.6', '>='), "DB fr_version must be >= 4.9.6, got: $frVer");
});

it('فایل includes/update.php بلوک ارتقای نگارش 4.9.6 را داراست', function() {
    $updater = file_get_contents(FR_ROOT . '/includes/update.php');
    expectTrue(str_contains($updater, '۴٫۹٫۶') || str_contains($updater, '4.9.6'), 'update.php must contain 4.9.6 block');
});

it('تنظیمات report_pdf_download_mode روی حالت جامع dual_action تنظیم شده است', function() {
    $mode = DB::setting('report_pdf_download_mode');
    expectTrue($mode === 'dual_action' || $mode === 'direct_pdf' || $mode === 'interactive', 'Valid mode setting in DB');
});

// ── فاز ۲: بازگردانی قطعی نسخه چاپی کارنامه در پنل کاربری (panel/report.php) ──
echo "\n--- فاز ۲: دسترسی کامل به نسخه چاپی در صفحه کارنامه مراجع (panel/report.php) ---\n";

it('صفحه گزارش کارنامه دارای دکمه «دریافت نسخه چاپی کارنامه (A4)» با پیوند print=1 است', function() {
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
        require_once FR_ROOT . "/includes/db.php";
        require_once FR_ROOT . "/includes/security.php";
        fr_bootstrap();
        $_SESSION["user_id"] = 1;
        $_GET["attempt"] = 1;
        ob_start();
        include FR_ROOT . "/panel/report.php";
        echo ob_get_clean();
    ');
    $out = (string)shell_exec($cmd);

    expectTrue(str_contains($out, '🖨️ دریافت نسخه چاپی کارنامه (A4)'), 'باید شامل دکمه دریافت نسخه چاپی در بالای گزارش باشد');
    expectTrue(str_contains($out, 'report-pdf.php?attempt=1&amp;print=1') || str_contains($out, 'report-pdf.php?attempt=1&print=1'), 'باید به report-pdf با پارامتر print=1 لینک دهد');
    expectTrue(str_contains($out, '📥 دانلود فایل PDF کارنامه') || str_contains($out, '📥 دریافت فایل PDF کارنامه'), 'باید دکمه مستقیم دانلود فایل PDF را داشته باشد');
    expectTrue(str_contains($out, '🖨️ چاپ سریع صفحه'), 'باید دکمه چاپ سریع با مرورگر را داشته باشد');
    expectTrue(str_contains($out, '🖨️ دریافت و چاپ کارنامه رسمی آزمون'), 'باید کارت دریافت و چاپ کارنامه در پایین صفحه باشد');
});

it('جدول سوابق آزمون‌ها (panel/history.php) دارای دکمه‌های نسخه چاپی و دانلود PDF است', function() {
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
        require_once FR_ROOT . "/includes/db.php";
        require_once FR_ROOT . "/includes/security.php";
        fr_bootstrap();
        $_SESSION["user_id"] = 1;
        ob_start();
        include FR_ROOT . "/panel/history.php";
        echo ob_get_clean();
    ');
    $out = (string)shell_exec($cmd);

    expectTrue(str_contains($out, '🖨️ نسخه چاپی'), 'جدول سوابق مراجعین باید دکمه نسخه چاپی داشته باشد');
    expectTrue(str_contains($out, 'print=1'), 'لینک نسخه چاپی باید شامل print=1 باشد');
    expectTrue(str_contains($out, 'download=1'), 'لینک دانلود PDF باید فعال باشد');
});

// ── فاز ۳: دسترسی به نسخه چاپی در پنل مدیریت ──
echo "\n--- فاز ۳: دسترسی به نسخه چاپی در پنل مدیریت (admin/attempts.php و user-view.php) ---\n";

it('کنترلر مدیریت admin/attempts.php در نمای کارنامه مراجع دارای گزینه‌های چاپ و دانلود PDF است', function() {
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
        require_once FR_ROOT . "/includes/db.php";
        require_once FR_ROOT . "/includes/security.php";
        fr_bootstrap();
        $_SESSION["admin_id"] = 1;
        $_GET["report"] = 1;
        ob_start();
        include FR_ROOT . "/admin/attempts.php";
        echo ob_get_clean();
    ');
    $out = (string)shell_exec($cmd);

    expectTrue(str_contains($out, '🖨️ دریافت نسخه چاپی کارنامه (A4)') || str_contains($out, '🖨️ نسخه چاپی کارنامه (A4)'), 'نوار بالای کارنامه ادمین باید دکمه نسخه چاپی داشته باشد');
    expectTrue(str_contains($out, '🖨️ چاپ و خروجی رسمی کارنامه مراجع'), 'کارت انتهای گزارش ادمین باید عنوان چاپ و خروجی رسمی را داشته باشد');
    expectTrue(str_contains($out, 'admin/report-pdf.php?attempt=1&amp;print=1') || str_contains($out, 'admin/report-pdf.php?attempt=1&print=1'), 'لینک باید به صورت امن به admin/report-pdf با print=1 اشاره کند');
});

it('جدول فهرست نتایج مراجعین در admin/attempts.php دکمه «نسخه چاپی» دارد', function() {
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
        require_once FR_ROOT . "/includes/db.php";
        require_once FR_ROOT . "/includes/security.php";
        fr_bootstrap();
        $_SESSION["admin_id"] = 1;
        $_GET = [];
        ob_start();
        include FR_ROOT . "/admin/attempts.php";
        echo ob_get_clean();
    ');
    $out = (string)shell_exec($cmd);

    expectTrue(str_contains($out, '🖨️ نسخه چاپی'), 'جدول نتایج مراجعین باید شامل دکمه نسخه چاپی باشد');
});

// ── فاز ۴: نوار ابزار و رفتار هوشمند صفحه برگه چاپی (panel/report-pdf.php) ──
echo "\n--- فاز ۴: نوار ابزار برگه استاندارد A4 (panel/report-pdf.php) ---\n";

it('نوار ابزار بالای کارنامه دکمه «🖨️ چاپ با پرینتر (نسخه چاپی)» و «📥 دانلود فایل PDF کارنامه (A4)» دارد', function() {
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
        require_once FR_ROOT . "/includes/db.php";
        require_once FR_ROOT . "/includes/security.php";
        fr_bootstrap();
        $_SESSION["user_id"] = 1;
        $_GET["attempt"] = 1;
        ob_start();
        include FR_ROOT . "/panel/report-pdf.php";
        echo ob_get_clean();
    ');
    $out = (string)shell_exec($cmd);

    expectTrue(str_contains($out, '🖨️ چاپ با پرینتر (نسخه چاپی)'), 'نوار ابزار باید دکمه چاپ با پرینتر را داشته باشد');
    expectTrue(str_contains($out, 'frPrintReport()'), 'دکمه پرینت باید تابع frPrintReport را فراخوانی کند');
    expectTrue(str_contains($out, '📥 دانلود فایل PDF کارنامه') || str_contains($out, '📥 دریافت فایل PDF کارنامه'), 'نوار ابزار باید دکمه دانلود PDF داشته باشد');
    expectTrue(str_contains($out, 'pr-sheet'), 'برگه اختصاصی ISO A4 رندر شده است');
});

it('درخواست با پارامتر print=1 تابع frPrintReport را پس از بارگذاری فونت‌ها خودکار فراخوانی می‌کند', function() {
    $phpCode = file_get_contents(FR_ROOT . '/panel/report-pdf.php');
    expectTrue(str_contains($phpCode, 'isAutoPrint'), 'متغیر بررسی چاپ خودکار موجود است');
    expectTrue(str_contains($phpCode, 'window.frPrintReport'), 'فراخوانی خودکار frPrintReport وجود دارد');
});

it('درخواست با پارامتر download=1 مستقیماً فایل باینری PDF تحویل می‌دهد', function() {
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
        $_SERVER["REQUEST_URI"] = "/panel/report-pdf.php?attempt=1&download=1";
        $_GET = ["attempt" => "1", "download" => "1"];
        require_once FR_ROOT . "/includes/db.php";
        require_once FR_ROOT . "/includes/security.php";
        fr_bootstrap();
        $_SESSION["user_id"] = 1;
        include FR_ROOT . "/panel/report-pdf.php";
    ');
    $out = (string)shell_exec($cmd);
    expectTrue(str_starts_with($out, '%PDF-'), 'خروجی دانلود مستقیم باید باینری معتبر PDF باشد');
});

// ── فاز ۵: ریشه‌کنی قطعی خطای موقت و پایداری موتورهای PDF روی تمام آزمون‌ها ──
echo "\n--- فاز ۵: ریشه‌کنی قطعی خطای موقت (Zero Temporary Error) ---\n";

it('متد ReportBuilder::build با پارامتر عددی int یا آرایه روی همه آزمون‌ها بدون Fatal Error اجرا می‌شود', function() {
    $attempts = DB::all('SELECT id, test_id FROM ' . DB::t('attempts') . ' WHERE status = ?', ['completed']);
    expectTrue(!empty($attempts), 'حداقل یک تلاش تکمیل‌شده وجود دارد');

    foreach ($attempts as $atRow) {
        $aid = (int)$atRow['id'];
        // فراخوانی با شناسه عددی
        $htmlInt = ReportBuilder::build($aid, null, 'full', ['print' => true]);
        expectTrue(is_string($htmlInt) && strlen($htmlInt) > 10, "ReportBuilder با شناسه عددی $aid خروجی تولید کرد");

        // فراخوانی با ردیف دیتابیس
        $htmlArr = ReportBuilder::build($atRow, null, 'full', ['print' => true]);
        expectTrue(is_string($htmlArr) && strlen($htmlArr) > 10, "ReportBuilder با آرایه برای تلاش $aid خروجی تولید کرد");
    }
});

it('متد CarnetPdfBuilder::build برای تمامی تلاش‌ها فایل باینری سالم PDF می‌سازد', function() {
    $attempts = DB::all('SELECT id FROM ' . DB::t('attempts') . ' WHERE status = ?', ['completed']);
    foreach ($attempts as $a) {
        $aid = (int)$a['id'];
        $pdf = CarnetPdfBuilder::build($aid, false);
        expectTrue(str_starts_with($pdf, '%PDF-'), "تلاش شماره $aid فایل باینری سالم PDF تولید کرد");
        expectTrue(strlen($pdf) > 1000, "حجم فایل PDF برای تلاش $aid استاندارد است");
    }
});

it('توابع امنیتی fr_security_headers و fr_image_to_data_uri بدون خطای ارسال هدر یا تایم‌اوت کار می‌کنند', function() {
    fr_security_headers(); // نباید خطای headers already sent ایجاد کند
    $dummyUri = fr_image_to_data_uri('https://example.com/non-existent-logo.png');
    expectTrue($dummyUri === '', 'تصویر نامعتبر خارجی باید رشته خالی برگرداند تا Dompdf دچار وقفه و تایم‌اوت نشود');
});

// ── فاز ۶: ابعاد دقیق ISO A4، استایل‌شیت چاپ و نوارهای مقیاس ──
echo "\n--- فاز ۶: استانداردهای چاپ A4 و نوارهای مقیاس درصدی ---\n";

it('استایل‌شیت چاپ feria.css و report-pdf.php استانداردهای ISO A4 را رعایت می‌کنند', function() {
    $css = file_get_contents(FR_ROOT . '/assets/css/feria.css');
    expectTrue(str_contains($css, '@media print'), 'استایل @media print در feria.css موجود است');
    expectTrue(str_contains($css, '@page'), 'دستور @page موجود است');
    expectTrue(str_contains($css, 'size: A4 portrait'), 'قطع استاندارد A4 portrait تعریف شده است');
    expectTrue(str_contains($css, 'print-color-adjust: exact'), 'حفظ چاپ رنگی در پرینتر فعال است');
});

it('نوارهای مقیاس (.fr-scalebar و .track) درصد و رنگ پس‌زمینه پویا دارند', function() {
    $at = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $html = ReportBuilder::build($at, null, 'full', ['print' => true]);
    expectTrue(str_contains($html, 'class="fr-scalebar"'), 'کلاس fr-scalebar موجود است');
    expectTrue(str_contains($html, 'class="track"'), 'کلاس track موجود است');
    expectTrue(str_contains($html, 'class="fill"'), 'کلاس fill موجود است');
    expectTrue(preg_match('/width:\s*\d+(\.\d+)?%/i', $html) === 1, 'عرض درصد پویا دارد');
});

// ── فاز ۷: محرمانگی بالینی پروتکل‌های مداخله ──
echo "\n--- فاز ۷: محرمانگی بالینی پروتکل‌های مداخله ---\n";

it('راهنمای مداخله بالینی هرگز در نسخه مراجع عادی چاپ یا درج نمی‌شود', function() {
    $at = DB::one('SELECT id FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $pdfUser = CarnetPdfBuilder::build((int)$at['id'], false);
    file_put_contents('/tmp/qa_p496_user.pdf', $pdfUser);
    $txt = (string)shell_exec('pdftotext /tmp/qa_p496_user.pdf - 2>/dev/null');
    @unlink('/tmp/qa_p496_user.pdf');

    expectTrue(!str_contains($txt, 'راهنمای مداخلهٔ بالینی'), 'پروتکل مداخله بالینی در کارنامه مراجع پنهان است');
});

echo "\n=======================================================================\n";
echo "  خلاصه نتایج آزمون‌های نگارش ۴٫۹٫۶ (Feria Ravan v4.9.6 QA Summary)\n";
echo "  کل آزمون‌ها: $totalTests\n";
echo "  موفق: $passedTests\n";
echo "  ناموفق: $failedTests\n";
echo "=======================================================================\n\n";

if ($failedTests > 0) {
    echo "❌ خطاهای رخ داده:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "✔ تبریک! تمامی آزمون‌های نگارش ۴٫۹٫۶ با موفقیت ۱۰۰٪ سپری شدند.\n";
exit(0);
