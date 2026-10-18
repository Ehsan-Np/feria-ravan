<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت نگارش ۴٫۹٫۸ (Feria Ravan v4.9.8 Master QA)
 *  ─ اعتبارسنجی پاکسازی جامع فایل‌های اضافه Dompdf و بهینه‌سازی قلم‌ها
 *  ─ حذف کتابخانه منسوخ کلاینت‌ساید html2pdf.bundle.min.js
 *  ─ حذف فونت‌های غیرضروری DejaVuSerif و DejaVuSansMono و ذخیره بیش از ۵ مگابایت
 *  ─ تولید بدون خطای فایل باینری PDF کارنامه با قلم بومی DejaVu Sans فارسی
 *  ─ پاکسازی مستندات اضافه، تست‌ها و فایل‌های موقت بدون هیچ‌گونه شکست در سیستم
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

$passed = 0;
$failed = 0;
$errors = [];

function it(string $title, callable $fn): void {
    global $passed, $failed, $errors;
    try {
        $fn();
        echo "  [PASS] $title\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  [FAIL] $title: " . $e->getMessage() . "\n";
        $failed++;
        $errors[] = "$title: " . $e->getMessage();
    }
}

function expectTrue(bool $cond, string $msg = 'Expected condition to be true'): void {
    if (!$cond) throw new \AssertionError($msg);
}

echo "=======================================================================\n";
echo "  شروع آزمون‌های پاکسازی ساختار و بهینه‌سازی Dompdf (Feria Ravan v4.9.8 QA)\n";
echo "=======================================================================\n\n";

// ── فاز ۱: حذف فایل‌های زائد و کتابخانه‌های منسوخ ──
echo "--- فاز ۱: اعتبارسنجی حذف فایل‌های بلااستفاده و منسوخ ---\n";

it('فایل حجیم و منسوخ html2pdf.bundle.min.js از پوشه assets/js به طور کامل حذف شده است', function() {
    expectTrue(!file_exists(FR_ROOT . '/assets/js/html2pdf.bundle.min.js'), 'html2pdf.bundle.min.js must not exist');
});

it('فایل‌های متنی readme.txt و OFL.txt از assets/fonts حذف شده‌اند', function() {
    expectTrue(!file_exists(FR_ROOT . '/assets/fonts/readme.txt'), 'readme.txt must not exist');
    expectTrue(!file_exists(FR_ROOT . '/assets/fonts/OFL.txt'), 'OFL.txt must not exist');
});

it('فایل موقت dump-analysis.php از پوشه qa حذف شده است', function() {
    expectTrue(!file_exists(dirname(FR_ROOT) . '/qa/dump-analysis.php'), 'dump-analysis.php must not exist');
});

it('یادداشت‌های انتشار نسخه‌های قدیمی پاکسازی شده و فقط نسخه جاری فعال است', function() {
    expectTrue(file_exists(FR_ROOT . '/RELEASE-NOTES-v4.9.8.md'), 'RELEASE-NOTES-v4.9.8.md must exist');
    expectTrue(!file_exists(FR_ROOT . '/RELEASE-NOTES-v4.4.0.md'), 'Old release note 4.4.0 must be deleted');
    expectTrue(!file_exists(FR_ROOT . '/RELEASE-NOTES-v4.6.0.md'), 'Old release note 4.6.0 must be deleted');
});

// ── فاز ۲: بهینه‌سازی دایرکتوری Dompdf و قلم‌ها ──
echo "\n--- فاز ۲: بهینه‌سازی دایرکتوری Dompdf و قلم‌های فارسی ---\n";

it('فونت‌های غیرضروری DejaVuSerif و DejaVuSansMono از دایرکتوری Dompdf حذف شده‌اند', function() {
    $fontDir = FR_ROOT . '/core/dompdf/vendor/dompdf/dompdf/lib/fonts';
    expectTrue(!file_exists($fontDir . '/DejaVuSerif.ttf'), 'DejaVuSerif.ttf must be deleted');
    expectTrue(!file_exists($fontDir . '/DejaVuSansMono.ttf'), 'DejaVuSansMono.ttf must be deleted');
    expectTrue(!file_exists($fontDir . '/DejaVuSans-BoldOblique.ttf'), 'DejaVuSans-BoldOblique.ttf must be deleted');
});

it('کش‌های نامربوط و مستندات mustRead.html حذف شده و فونت‌های سرپوشیده پاکسازی شده‌اند', function() {
    $fontDir = FR_ROOT . '/core/dompdf/vendor/dompdf/dompdf/lib/fonts';
    expectTrue(!file_exists($fontDir . '/mustRead.html'), 'mustRead.html must be deleted');
    expectTrue(!file_exists($fontDir . '/DejaVuSansMono.ufm.json'), 'DejaVuSansMono.ufm.json must be deleted');
    expectTrue(!file_exists($fontDir . '/DejaVuSerif.ufm.json'), 'DejaVuSerif.ufm.json must be deleted');
});

it('قلم‌های ضروری DejaVuSans برای متون فارسی و استاندارد چاپی بدون دستکاری باقی مانده‌اند', function() {
    $fontDir = FR_ROOT . '/core/dompdf/vendor/dompdf/dompdf/lib/fonts';
    expectTrue(file_exists($fontDir . '/DejaVuSans.ttf'), 'DejaVuSans.ttf must exist');
    expectTrue(file_exists($fontDir . '/DejaVuSans.ufm'), 'DejaVuSans.ufm must exist');
    expectTrue(file_exists($fontDir . '/DejaVuSans-Bold.ttf'), 'DejaVuSans-Bold.ttf must exist');
    expectTrue(file_exists($fontDir . '/DejaVuSans-Bold.ufm'), 'DejaVuSans-Bold.ufm must exist');
    expectTrue(file_exists($fontDir . '/DejaVuSans-Oblique.ttf'), 'DejaVuSans-Oblique.ttf must exist');
    expectTrue(file_exists($fontDir . '/DejaVuSans-Oblique.ufm'), 'DejaVuSans-Oblique.ufm must exist');
    expectTrue(file_exists($fontDir . '/installed-fonts.dist.json'), 'installed-fonts.dist.json must exist');
});

it('فایل نگارش رسمی VERSION در Dompdf برای ممانعت از خطای file_get_contents وجود دارد', function() {
    expectTrue(file_exists(FR_ROOT . '/core/dompdf/vendor/dompdf/dompdf/VERSION'), 'dompdf VERSION file must exist');
});

// ── فاز ۳: کارکرد بی‌نقص تولید کارنامه PDF پس از پاکسازی ──
echo "\n--- فاز ۳: کارکرد بی‌نقص تولید کارنامه PDF پس از پاکسازی ---\n";

it('موتور CarnetPdfBuilder::buildWithDompdf فایل سالم PDF (%PDF-) تولید می‌کند', function() {
    $at = DB::one('SELECT id FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    expectTrue(!empty($at['id']), 'یک آزمون تکمیل‌شده در پایگاه داده وجود دارد');
    $pdf = CarnetPdfBuilder::buildWithDompdf((int)$at['id'], false);
    expectTrue(str_starts_with($pdf, '%PDF-'), 'هدر خروجی فایل باید %PDF- باشد');
    expectTrue(strlen($pdf) > 20000, 'فایل باینری کارنامه خروجی کامل و رنگی دارد');
});

it('متد همگانی CarnetPdfBuilder::build خروجی بی‌نقص بدون خطا تولید می‌کند', function() {
    $at = DB::one('SELECT id FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $pdf = CarnetPdfBuilder::build((int)$at['id'], true);
    expectTrue(str_starts_with($pdf, '%PDF-'), 'خروجی کارنامه مدیر معتبر است');
    expectTrue(strlen($pdf) > 20000, 'خروجی کارنامه مدیر حجم و دیتای کافی دارد');
});

echo "\n=======================================================================\n";
echo "  خلاصه آزمون‌های پاکسازی نگارش ۴٫۹٫۸: کل: " . ($passed + $failed) . " | موفق: $passed | ناموفق: $failed\n";
echo "=======================================================================\n\n";

if ($failed > 0) {
    echo "❌ برخی آزمون‌ها ناموفق بودند:\n";
    foreach ($errors as $e) echo " - $e\n";
    exit(1);
} else {
    echo "✔ تبریک! تمامی آزمون‌های پاکسازی با موفقیت ۱۰۰٪ سپری شدند.\n";
    exit(0);
}
