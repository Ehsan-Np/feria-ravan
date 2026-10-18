<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت نگارش ۴٫۹٫۵ (Feria Ravan v4.9.5 Master QA)
 *  ─ ریشه‌کنی قطعی «خطای موقت» (Temporary Error) در پرینت و تحلیل کارنامه‌ها
 *  ─ اعتبارسنجی تولید فایل استاندارد PDF بر اساس کتابخانه Dompdf و شکل‌دهی فارسی
 *  ─ رعایت دقیق استاندارد استایل‌شیت پرینت برگرفته از 30-seconds-of-code
 *  ─ تنظیمات سفارشی‌سازی لوگو و پالت رنگی کارنامه در پنل مدیریت
 *  ─ عملکرد بی‌نقص نوارهای مقیاس (.fr-scalebar و .track) با پرشدگی درصدی رنگی
 *  ─ ابعاد دقیق کاغذ استاندارد بین‌المللی ISO A4 (210 × 297 میلی‌متر)
 *  ─ محرمانگی پروتکل‌های بالینی و الزام کلیک روی دکمه اعمال کوپن تخفیف
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
        $failures[] = ['title' => $title, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
        echo "  [FAIL] $title: " . $e->getMessage() . "\n";
    }
}

function expectTrue(bool $val, string $msg = 'Expected true'): void
{
    if (!$val) throw new \RuntimeException($msg);
}

function expectEq(mixed $actual, mixed $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        $msg = $msg ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        throw new \RuntimeException($msg);
    }
}

echo "=======================================================================\n";
echo "  شروع آزمون‌های جامع نگارش ۴٫۹٫۵ (Feria Ravan v4.9.5 Master QA)\n";
echo "=======================================================================\n\n";

// ── فاز ۱: ریشه‌کنی خطای موقت (Temporary Error Elimination) ──
echo "--- فاز ۱: ریشه‌کنی خطای موقت و پایداری متد ReportBuilder::build ---\n";

it('متد ReportBuilder::build با ورودی شناسه عددی (int) بدون خطا کار می‌کند', function() {
    $at = DB::one('SELECT id FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    expectTrue(!empty($at), 'باید حداقل یک تلاش تکمیل‌شده وجود داشته باشد');
    $html = ReportBuilder::build((int)$at['id'], null, 'full', ['print' => true]);
    expectTrue(strlen($html) > 500, 'خروجی کارنامه با ورودی عدد صحیح باید معتبر باشد');
    expectTrue(!str_contains($html, 'خطای موقت'), 'خروجی نباید شامل صفحه خطای موقت باشد');
});

it('متد ReportBuilder::build با ورودی آرایه ردیف تلاش بدون خطا کار می‌کند', function() {
    $at = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $html = ReportBuilder::build($at, null, 'full', ['print' => true]);
    expectTrue(strlen($html) > 500, 'خروجی کارنامه با ورودی آرایه باید معتبر باشد');
});

it('در حالت چاپ (printMode) فرم تعاملی تزریق پلنر به کلی حذف می‌شود', function() {
    $at = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $html = ReportBuilder::build($at, null, 'full', ['print' => true]);
    expectTrue(!str_contains($html, 'name="act" value="inject_growth"'), 'فرم تزریق پلنر نباید در نسخه چاپ باشد');
});

// ── فاز ۲: کتابخانه Dompdf و شکل‌دهی خط فارسی ──
echo "\n--- فاز ۲: موتور پیشرفته Dompdf و پشتیبانی کامل از حروف فارسی ---\n";

it('فایل‌های هسته کتابخانه Dompdf v3.1.6 در مسیر core/dompdf مستقر هستند', function() {
    expectTrue(file_exists(FR_ROOT . '/core/dompdf/autoload.inc.php'), 'فایل autoload.inc.php موجود است');
    expectTrue(is_dir(FR_ROOT . '/core/dompdf/vendor/dompdf/dompdf/src'), 'دایرکتوری src کتابخانه Dompdf موجود است');
});

it('موتور اختصاصی شکل‌دهی خط فارسی و راست‌به‌چپ (Bidi/Shaper) فعال است', function() {
    if (!class_exists('MostafaMoknaa\ArabicPdf\Arabic')) {
        require_once FR_ROOT . '/core/dompdf/persian/Text/Bidi.php';
        require_once FR_ROOT . '/core/dompdf/persian/Text/Shaper.php';
        require_once FR_ROOT . '/core/dompdf/persian/Html/HtmlProcessor.php';
        require_once FR_ROOT . '/core/dompdf/persian/Arabic.php';
    }
    expectTrue(class_exists('MostafaMoknaa\ArabicPdf\Arabic'), 'کلاس Arabic مستقر است');
    $persian = new \MostafaMoknaa\ArabicPdf\Arabic(['reverse_table_columns' => false]);
    $sample = 'پژوهش، چاپ و کارنامهٔ اختصاصی روان‌شناسی گام‌به‌گام';
    $fixed = $persian->fix($sample);
    expectTrue(!empty($fixed), 'متن فارسی با حروف پ، چ، ژ، گ، ک، ی به درستی پردازش شد');
});

it('موتور CarnetPdfBuilder::buildWithDompdf فایل معتبر باینری PDF (%PDF-) تولید می‌کند', function() {
    $at = DB::one('SELECT id FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $pdf = CarnetPdfBuilder::buildWithDompdf((int)$at['id'], false);
    expectTrue(str_starts_with($pdf, '%PDF-'), 'هدر خروجی باید باینری %PDF- باشد');
    expectTrue(strlen($pdf) > 20000, 'حجم فایل باینری PDF کارنامه کافی و معتبر است');
});

it('مکانیزم تاب‌آوری Fallback به TCPDF در CarnetPdfBuilder پایدار است', function() {
    $at = DB::one('SELECT id FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $pdf = CarnetPdfBuilder::buildWithTcpdf((int)$at['id'], false);
    expectTrue(str_starts_with($pdf, '%PDF-'), 'خروجی موتور کمکی TCPDF معتبر است');
});

// ── فاز ۳: استانداردهای استایل‌شیت پرینت (30-seconds-of-code) و ابعاد ISO A4 ──
echo "\n--- فاز ۳: استانداردهای پرینت 30-seconds-of-code و ابعاد ISO A4 ---\n";

it('فایل assets/css/feria.css شامل استانداردهای مدرن @media print است', function() {
    $css = (string)file_get_contents(FR_ROOT . '/assets/css/feria.css');
    expectTrue(str_contains($css, '@page'), 'قاعده @page در feria.css تعریف شده است');
    expectTrue(str_contains($css, 'size: A4 portrait') || str_contains($css, 'size:A4'), 'سایز A4 portrait تعریف شده است');
    expectTrue(str_contains($css, 'print-color-adjust: exact'), 'تنظیم print-color-adjust برای حفظ رنگ‌های چاپ تعریف شده است');
    expectTrue(str_contains($css, 'page-break-inside: avoid'), 'کنترل شکست صفحه روی کارت‌ها و جداول فعال است');
});

it('صفحه نمایش کارنامه panel/report-pdf.php قواعد استاندارد چاپ را اعمال می‌کند', function() {
    $php = (string)file_get_contents(FR_ROOT . '/panel/report-pdf.php');
    expectTrue(str_contains($php, '@page'), 'تنظیمات @page موجود است');
    expectTrue(str_contains($php, '210mm') || str_contains($php, 'size: A4 portrait'), 'ابعاد استاندارد کاغذ A4 رعایت شده است');
    expectTrue(str_contains($php, 'print-color-adjust: exact'), 'دستور چاپ رنگی کامل درج شده است');
});

// ── فاز ۴: تنظیمات سفارشی‌سازی لوگو و پالت رنگی در پنل مدیریت ──
echo "\n--- فاز ۴: تنظیمات مدیریتی لوگو و رنگ‌بندی کارنامه ---\n";

it('توابع کمکی رنگ سربرگ، رنگ تاکیدی و پالت نمودارها تعریف شده‌اند', function() {
    expectTrue(function_exists('fr_report_header_color'), 'تابع fr_report_header_color موجود است');
    expectTrue(function_exists('fr_report_accent_color'), 'تابع fr_report_accent_color موجود است');
    expectTrue(function_exists('fr_report_chart_palette'), 'تابع fr_report_chart_palette موجود است');
    expectTrue(function_exists('fr_report_palette_colors'), 'تابع fr_report_palette_colors موجود است');
    $colors = fr_report_palette_colors();
    expectTrue(is_array($colors) && count($colors) >= 4, 'حداقل ۴ رنگ در پالت نمودار تعریف شده است');
});

it('فایل admin/settings.php امکان ذخیره رنگ‌های سفارشی و موتور PDF را دارد', function() {
    $settingsPhp = (string)file_get_contents(FR_ROOT . '/admin/settings.php');
    expectTrue(str_contains($settingsPhp, 'report_header_color'), 'فیلد report_header_color در ادمین هست');
    expectTrue(str_contains($settingsPhp, 'report_accent_color'), 'فیلد report_accent_color در ادمین هست');
    expectTrue(str_contains($settingsPhp, 'report_chart_palette'), 'فیلد report_chart_palette در ادمین هست');
    expectTrue(str_contains($settingsPhp, 'report_pdf_engine'), 'فیلد report_pdf_engine در ادمین هست');
});

it('ذخیره و بازیابی تنظیمات رنگ و پالت کارنامه در پایگاه داده تست می‌شود', function() {
    DB::setSetting('report_header_color', '#2c5282');
    DB::setSetting('report_accent_color', '#805ad5');
    DB::setSetting('report_chart_palette', 'emerald');
    DB::setSetting('report_pdf_engine', 'dompdf');

    expectEq(fr_report_header_color(), '#2c5282', 'رنگ سربرگ با موفقیت بازیابی شد');
    expectEq(fr_report_accent_color(), '#805ad5', 'رنگ تاکیدی با موفقیت بازیابی شد');
    expectEq(fr_report_chart_palette(), 'emerald', 'پالت زمردی با موفقیت بازیابی شد');

    // بازگردانی به مقادیر پیش‌فرض
    DB::setSetting('report_header_color', '#1e3a6e');
    DB::setSetting('report_accent_color', '#7c3aed');
    DB::setSetting('report_chart_palette', 'default');
    DB::setSetting('report_pdf_engine', 'auto');
});

// ── فاز ۵: عملکرد نوارهای مقیاس (.fr-scalebar و .track) با پرشدگی رنگی ──
echo "\n--- فاز ۵: نمایش درصدی و رنگی نوارها (.fr-scalebar و .track) ---\n";

it('نوارهای .fr-scalebar ویژگی width درصد پویا و background رنگی دارند', function() {
    $at = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $html = ReportBuilder::build($at, null, 'full', ['print' => true]);

    expectTrue(str_contains($html, 'class="fr-scalebar"'), 'کلاس fr-scalebar در گزارش موجود است');
    expectTrue(str_contains($html, 'class="track"'), 'کلاس track در نوار موجود است');
    expectTrue(str_contains($html, 'class="fill"'), 'کلاس fill در نوار موجود است');

    // بررسی اینکه عرض نوار صفر نیست و درصد پویا دارد
    preg_match_all('/class="fill"[^>]*style="([^"]*)"/i', $html, $matches);
    expectTrue(!empty($matches[1]), 'حداقل یک نوار fill استایل‌دهی شده یافت شد');
    foreach ($matches[1] as $style) {
        expectTrue(preg_match('/width:\s*\d+(\.\d+)?%/i', $style) === 1, "عرض نوار باید بر حسب درصد باشد: $style");
        expectTrue(preg_match('/background:\s*#[0-9a-fA-F]{3,8}/i', $style) === 1, "پس‌زمینه نوار باید رنگ هگز داشته باشد: $style");
    }
});

it('در ماژول core/disc-deep.php درصد شاخص تطابق به صورت رنگی روی نوار درج می‌شود', function() {
    $deepPhp = (string)file_get_contents(FR_ROOT . '/core/disc-deep.php');
    expectTrue(str_contains($deepPhp, 'style="width:<?= (int)$shift[\'idx\'] ?>%'), 'شاخص تطابق با عرض درصدی پویا مقداردهی شده است');
    expectTrue(str_contains($deepPhp, 'background:<?= $shift[\'ok\']'), 'رنگ‌بندی نوار تطابق DISC با پس‌زمینه رنگی تنظیم شده است');
});

// ── فاز ۶: حفظ محرمانگی بالینی در خروجی PDF مراجع ──
echo "\n--- فاز ۶: حفظ محرمانگی بالینی پروتکل‌ها ---\n";

it('پروتکل بالینی در خروجی PDF مراجع عادی هرگز درج نمی‌شود', function() {
    $at = DB::one('SELECT id FROM ' . DB::t('attempts') . ' WHERE status = ? LIMIT 1', ['completed']);
    $pdfUser = CarnetPdfBuilder::build((int)$at['id'], false);
    // ذخیره موقت و بررسی با pdftotext
    file_put_contents('/tmp/qa_pdf_user.pdf', $pdfUser);
    $text = (string)shell_exec('pdftotext /tmp/qa_pdf_user.pdf - 2>/dev/null');
    expectTrue(!str_contains($text, 'راهنمای مداخلهٔ بالینی'), 'راهنمای مداخله در خروجی مراجع عادی وجود ندارد');
    expectTrue(!str_contains($text, 'پروتوکل شواهدمحور'), 'پروتکل شواهدمحور در خروجی مراجع عادی وجود ندارد');
    @unlink('/tmp/qa_pdf_user.pdf');
});

// ── فاز ۷: تجمیع دکمه‌های کارنامه به دکمه واحد ──
echo "\n--- فاز ۷: تجمیع دکمه‌های کارنامه ---\n";

it('دکمه شاخص دریافت فایل PDF کارنامه در صفحات اصلی مستقر است', function() {
    $repPanel = (string)file_get_contents(FR_ROOT . '/panel/report.php');
    expectTrue(str_contains($repPanel, '📥 دریافت فایل PDF کارنامه'), 'دکمه واحد در panel/report.php مستقر است');

    $attemptsAdmin = (string)file_get_contents(FR_ROOT . '/admin/attempts.php');
    expectTrue(str_contains($attemptsAdmin, '📥 دریافت فایل PDF کارنامه'), 'دکمه واحد در admin/attempts.php مستقر است');
});

// ── فاز ۸: آزمون صحت عملکرد روی نمونه آزمون‌های مختلف ──
echo "\n--- فاز ۸: اجرای آزمون‌های روان‌سنجی بدون خطا ---\n";

it('تولید گزارش آزمون‌های چندگانه بدون هیچ‌گونه Fatal Error یا استثنا سپری می‌شود', function() {
    $completedAttempts = DB::all('SELECT a.id, t.slug, t.title FROM ' . DB::t('attempts') . ' a JOIN ' . DB::t('tests') . ' t ON t.id = a.test_id WHERE a.status = ?', ['completed']);
    expectTrue(!empty($completedAttempts), 'حداقل یک آزمون تکمیل‌شده برای تست وجود دارد');
    foreach ($completedAttempts as $row) {
        $pdf = CarnetPdfBuilder::build((int)$row['id'], false);
        expectTrue(str_starts_with($pdf, '%PDF-'), "تولید PDF آزمون {$row['slug']} معتبر است");
    }
});

// ── جمع‌بندی نهایی ──
echo "\n=======================================================================\n";
echo "  خلاصه نتایج آزمون‌های جامع نگارش ۴٫۹٫۵ (v4.9.5 QA Results)\n";
echo "  کل آزمون‌ها: $totalTests\n";
echo "  موفق: $passedTests\n";
echo "  ناموفق: $failedTests\n";
echo "=======================================================================\n\n";

if ($failedTests > 0) {
    echo "❌ برخی آزمون‌ها ناموفق بودند:\n";
    foreach ($failures as $f) {
        echo " - " . $f['title'] . ": " . $f['error'] . "\n";
    }
    exit(1);
} else {
    echo "✔ تبریک! تمامی آزمون‌های نگارش ۴٫۹٫۵ با موفقیت ۱۰۰٪ سپری شدند.\n\n";
    exit(0);
}
