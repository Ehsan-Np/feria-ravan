<?php
/**
 * ============================================================================
 *  مجموعه آزمون جامع اعتبارسنجی نگارش ۴٫۷٫۰ (QA Suite v4.7.0)
 *  بررسی حل ریشه‌ای خطای دریافت فایل تحلیل کارنامه و حفظ محرمانگی بالینی
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
define('FR_ROOT', dirname(__DIR__) . '/feria-ravan');
define('FR_ENV', 'development');

require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/admin-auth.php';
require_once FR_ROOT . '/includes/view.php';
require_once FR_ROOT . '/includes/report.php';
require_once FR_ROOT . '/core/Analyzer.php';
fr_bootstrap();

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

echo "\n====================================================================\n";
echo "  شروع آزمون‌های جامع نگارش ۴٫۷٫۰ — حل ریشه‌ای دریافت فایل کارنامه\n";
echo "====================================================================\n\n";

// بخش ۱: نگارش ۴٫۷٫۰
echo "--- بخش ۱: بررسی ثبات و ارتقاء شماره نسخه به ۴٫۷٫۰ ---\n";
assert_test("ثابت FR_RELEASE بر روی 4.7.0 تنظیم است", defined('FR_RELEASE') && FR_RELEASE === '4.7.0', FR_RELEASE);
assert_test("ثابت FR_VERSION بر روی 4.7.0 تنظیم است", defined('FR_VERSION') && FR_VERSION === '4.7.0', FR_VERSION);
assert_test("نسخه ثبت‌شده در دیتابیس ۴٫۷٫۰ است", DB::setting('fr_version') === '4.7.0', DB::setting('fr_version'));

// بخش ۲: تابع تولید بارکد QR وکتور محلی خالص
echo "\n--- بخش ۲: بررسی تولید بارکد QR وکتور محلی خالص ---\n";
$qrSvg = fr_qr_svg(SITE_URL . '/panel/report.php?attempt=6', 115);
assert_test("تولید بارکد QR با خروجی غیرتهی", strlen($qrSvg) > 500, strlen($qrSvg) . " bytes");
assert_test("خروجی حاوی تگ استاندارد SVG است", str_contains($qrSvg, '<svg') && str_contains($qrSvg, '</svg>'));
assert_test("خروجی حاوی المان‌های چهارگوش وکتور است", str_contains($qrSvg, '<rect'));
assert_test("بارکد فاقد هرگونه نشانی خارجی و گوگل است", !str_contains($qrSvg, 'googleapis.com'));

// بخش ۳: حل خطای Tainted Canvas با تبدیل تصاویر به Data URI
echo "\n--- بخش ۳: بررسی هلپر تبدیل تصاویر محلی به Data URI ---\n";
$sampleLogo = FR_ROOT . '/feria-uploads/workshops/cover-workshop-3.svg';
if (file_exists($sampleLogo)) {
    $uri = fr_image_to_data_uri($sampleLogo);
    assert_test("تبدیل فایل محلی به data:image/svg+xml;base64", str_starts_with($uri, 'data:image/'), substr($uri, 0, 30) . '...');
} else {
    assert_test("تبدیل فایل خالی بدون خطا", fr_image_to_data_uri('') === '');
}

// بخش ۴: بررسی عملکرد غیربازگشتی و ضدانقطاع فیلتر محرمانگی بالینی
echo "\n--- بخش ۴: بررسی فیلتر محرمانگی بالینی و عدم قطعی در آزمون‌های طولانی ---\n";
$dummyReport = '<div class="fr-report">'
    . str_repeat('<p>پاراگراف استاندارد محتوای روان‌سنجی برای تست حجم و ظرفیت تحلیل کارنامه.</p>', 500)
    . '<div class="deep-interv"><div style="badge">🔒 راهنما</div><b>🎯 راهنمای مداخلهٔ بالینی و پروتکل شواهدمحور:</b> محتوای محرمانه اتاق درمان</div>'
    . '<p><b>🎯 راهنمای مداخله</b>: پیشنهاد تکنیک درمانی</p>'
    . '<p>پایان تحلیل کارنامه استاندارد مراجع.</p>'
    . '</div>';

$cleanedUser = fr_filter_clinical_interventions($dummyReport, false);
$cleanedAdmin = fr_filter_clinical_interventions($dummyReport, true);

assert_test("فیلتر برای کاربر عادی مقدار غیرتهی بازمی‌گرداند", strlen($cleanedUser) > 10000, strlen($cleanedUser) . " bytes");
assert_test("پروتکل مداخله بالینی از دید کاربر عادی حذف شده است", !str_contains($cleanedUser, 'deep-interv') && !str_contains($cleanedUser, 'محتوای محرمانه اتاق درمان'));
assert_test("پروتکل مداخله برای مدیر سامانه حفظ شده است", str_contains($cleanedAdmin, 'deep-interv') && str_contains($cleanedAdmin, 'محتوای محرمانه اتاق درمان'));

// بخش ۵: بررسی تک‌تک ۲۰ تلاش موجود در دیتابیس از نظر تولید خروجی غیرصفر
echo "\n--- بخش ۵: اعتبارسنجی تمام ۲۰ تلاش در دیتابیس (عدم وقوع 0 bytes) ---\n";
$attempts = DB::all("SELECT a.*, t.engine, t.title FROM " . DB::t("attempts") . " a JOIN " . DB::t("tests") . " t ON t.id = a.test_id WHERE a.status='completed' ORDER BY a.id ASC");
assert_test("تعداد کل کارنامه‌های تکمیل‌شده جهت ارزیابی", count($attempts) === 20, (string)count($attempts));

$allNonZero = true;
$allCleanForUser = true;

foreach ($attempts as $at) {
    $u = DB::one("SELECT * FROM " . DB::t("users") . " WHERE id=?", [$at["user_id"]]);
    if (!$u) $u = ["id" => $at["user_id"], "first_name" => "کاربر", "last_name" => "تست", "mobile" => "09120000000"];

    $adminRes = ReportBuilder::build($at, $u, $at["mode"] ?? "full", ["print" => true, "isAdmin" => true]);
    $userRes  = ReportBuilder::build($at, $u, $at["mode"] ?? "full", ["print" => true, "isAdmin" => false]);

    if (strlen($adminRes) === 0 || strlen($userRes) === 0) {
        $allNonZero = false;
        echo "    [ERROR] تلاش شماره {$at['id']} موتور {$at['engine']} خروجی تهی داد!\n";
    }

    if (str_contains($userRes, 'deep-interv') || str_contains($userRes, '🎯 راهنمای مداخلهٔ بالینی')) {
        $allCleanForUser = false;
        echo "    [SECURITY LEAK] تلاش شماره {$at['id']} حاوی نشت پروتکل بالینی برای کاربر عادی است!\n";
    }
}

assert_test("هیچ کارنامه‌ای در سامانه خروجی تهی (0 bytes) تولید نمی‌کند", $allNonZero);
assert_test("پروتکل‌های محرمانه بالینی در هیچ تلاشی برای کاربر عادی نشت نمی‌کند", $allCleanForUser);

// بخش ۶: ارزیابی تمپلیت رسمی ISO A4 کارنامه برای مدیر و کاربر
echo "\n--- بخش ۶: ارزیابی تمپلیت رسمی ISO A4 در panel/report-pdf.php و admin/report-pdf.php ---\n";
// مدیر
$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'superadmin';
$_GET['attempt'] = 6;
$_GET['auto'] = 1;
unset($_GET['download_file']);

ob_start();
require FR_ROOT . '/admin/report-pdf.php';
$adminPdfHtml = ob_get_clean();

assert_test("تولید کامل برگه A4 برای مدیر از کنترلر ادمین", strlen($adminPdfHtml) > 50000, strlen($adminPdfHtml) . " bytes");
assert_test("برگه مدیر حاوی بارکد وکتور SVG است", str_contains($adminPdfHtml, 'frQrContainer') && str_contains($adminPdfHtml, '<svg'));
assert_test("برگه مدیر فاقد هرگونه آدرس خارجی chart.googleapis.com است", !str_contains($adminPdfHtml, 'chart.googleapis.com'));
assert_test("دکمه دریافت مستقیم فایل در کنترلر مدیر فعال است", str_contains($adminPdfHtml, 'download_file=1'));

// کاربر عادی
$at6 = DB::one("SELECT * FROM " . DB::t("attempts") . " WHERE id=6");
unset($_SESSION['admin_id']);
$_SESSION['user_id'] = $at6['user_id'];
$_GET['attempt'] = 6;
unset($_GET['download_file']);

ob_start();
require FR_ROOT . '/panel/report-pdf.php';
$userPdfHtml = ob_get_clean();

assert_test("تولید کامل برگه A4 برای کاربر عادی", strlen($userPdfHtml) > 50000, strlen($userPdfHtml) . " bytes");
assert_test("برگه کاربر عادی فاقد هرگونه پروتکل محرمانه بالینی است", !str_contains($userPdfHtml, 'deep-interv') && !str_contains($userPdfHtml, '🎯 راهنمای مداخلهٔ بالینی'));
assert_test("برگه کاربر حاوی بارکد وکتور محلی است", str_contains($userPdfHtml, 'frQrContainer') && str_contains($userPdfHtml, '<svg'));
assert_test("دکمه دانلود فایل PDF بر روی برگه تعبیه شده است", str_contains($userPdfHtml, 'pr-btn-download'));
assert_test("دکمه دریافت مستقیم فایل کارنامه تعبیه شده است", str_contains($userPdfHtml, 'download_file=1'));

// دانلود مستقیم فایل
$_GET['download_file'] = '1';
ob_start();
require FR_ROOT . '/panel/report-pdf.php';
$downloadHtml = ob_get_clean();
assert_test("دریافت مستقیم فایل سند کارنامه با حجم استاندارد", strlen($downloadHtml) > 50000, strlen($downloadHtml) . " bytes");

echo "\n====================================================================\n";
echo "  خلاصه نتایج آزمون: {$passed} آزمون موفق | {$failed} آزمون ناموفق\n";
echo "====================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
