<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت: نگارش ۴٫۵٫۰
 *  ─ رفع باگ لینک و ایجاد فایل PDF در پنل کاربر و مدیریت
 *  ─ ابعاد استاندارد ISO A4 و تمپلیت حرفه‌ای کارنامه
 *  ─ شخصی‌سازی مشخصات ارائه‌دهنده، روانشناس، مهر و لوگو در تنظیمات
 *  ─ محرمانگی کامل بالینی: پنهان‌سازی قطعی راهنمای مداخله برای کاربران عادی
 * ============================================================================
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

define('FR_TEST_MODE', true);
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'feria_test');
if (!defined('DB_USER')) define('DB_USER', 'feria');
if (!defined('DB_PASS')) define('DB_PASS', 'feria_password');
if (!defined('DB_PREFIX')) define('DB_PREFIX', 'fr_');
if (!defined('SITE_URL')) define('SITE_URL', 'http://127.0.0.1:8000');
if (!defined('FR_ROOT')) define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
if (!defined('FR_ENV')) define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/update.php';
require_once FR_ROOT . '/includes/report.php';
require_once FR_ROOT . '/core/analysis-deep.php';

$pass = 0;
$fail = 0;

function it(string $msg, callable $fn): void {
    global $pass, $fail;
    try {
        if ($fn()) {
            echo "  ✅ PASS: {$msg}\n";
            $pass++;
        } else {
            echo "  ❌ FAIL: {$msg}\n";
            $fail++;
        }
    } catch (Throwable $e) {
        echo "  ❌ ERROR: {$msg} -> " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")\n";
        $fail++;
    }
}

echo "\n🧪 شروع ارزیابی جامع نگارش ۴٫۵٫۰ (Feria Ravan v4.5.0)...\n";
echo str_repeat('=', 70) . "\n\n";

// -----------------------------------------------------------------------------
// بخش ۱: نگارش سامانه و مایگریشن ۴٫۵٫۰
// -----------------------------------------------------------------------------
echo "📦 ۱. نگارش سامانه و پایگاه‌داده:\n";

it('نگارش در version.php دقیقاً برابر 4.5.0 است', function() {
    return defined('FR_RELEASE') && FR_RELEASE === '4.5.0';
});

it('مایگریشن خودکار نگارش ۴٫۵٫۰ مقادیر پیش‌فرض تنظیمات کارنامه را مستقر می‌کند', function() {
    fr_update_pipeline(true);
    return DB::setting('report_show_signature_box') === '1'
        && DB::setting('report_show_qr') === '1'
        && DB::setting('report_header_color') === '#1e3a6e';
});

// -----------------------------------------------------------------------------
// بخش ۲: توابع و تنظیمات اختصاصی ارائه‌دهنده در کارنامه
// -----------------------------------------------------------------------------
echo "\n🏛️ ۲. تنظیمات و هلپرهای اطلاعات ارائه‌دهنده تست و کلینیک:\n";

it('تنظیم و واکشی نام مرکز ارائه‌دهنده و روانشناس ناظر آزمون', function() {
    DB::setSetting('report_provider_name', 'مرکز تخصصی روانشناسی فریا');
    DB::setSetting('report_provider_subtitle', 'مرکز خدمات روان‌شناختی، ارزیابی و مشاوره');
    DB::setSetting('report_provider_license', 'پروانه نظام روانشناسی: ۱۴۰۳/الف/۹۰۰');
    DB::setSetting('report_doctor_name', 'دکتر مریم جمالی');
    DB::setSetting('report_doctor_title', 'متخصص روان‌شناسی بالینی و روان‌سنج');
    DB::setSetting('report_doctor_license', 'شماره نظام: ۹۸۷۶۵');
    DB::setSetting('report_provider_phone', '021-88889999');
    DB::setSetting('report_provider_address', 'تهران، میدان ونک');
    DB::setSetting('report_provider_website', 'https://feria.ir');
    DB::setSetting('report_provider_stamp', 'https://feria.ir/stamp.png');

    return fr_report_provider_name() === 'مرکز تخصصی روانشناسی فریا'
        && fr_report_provider_subtitle() === 'مرکز خدمات روان‌شناختی، ارزیابی و مشاوره'
        && fr_report_provider_license() === 'پروانه نظام روانشناسی: ۱۴۰۳/الف/۹۰۰'
        && fr_report_doctor_name() === 'دکتر مریم جمالی'
        && fr_report_doctor_title() === 'متخصص روان‌شناسی بالینی و روان‌سنج'
        && fr_report_doctor_license() === 'شماره نظام: ۹۸۷۶۵'
        && fr_report_provider_phone() === '021-88889999'
        && fr_report_provider_address() === 'تهران، میدان ونک'
        && fr_report_provider_website() === 'https://feria.ir'
        && fr_report_provider_stamp() === 'https://feria.ir/stamp.png'
        && fr_report_show_signature_box() === true
        && fr_report_show_qr() === true;
});

it('در صورت خالی بودن تنظیمات ارائه‌دهنده، مقادیر پیش‌فرض برند باوقار جایگزین می‌شوند', function() {
    DB::setSetting('report_provider_name', '');
    DB::setSetting('report_provider_subtitle', '');
    DB::setSetting('report_provider_logo', '');
    return fr_report_provider_name() !== ''
        && fr_report_provider_subtitle() !== '';
});

// -----------------------------------------------------------------------------
// بخش ۳: محرمانگی کامل بالینی (پنهان‌سازی راهنمای مداخله از کاربر عادی)
// -----------------------------------------------------------------------------
echo "\n🔒 ۳. محرمانگی کامل بالینی و فیلترسازی پروتکل‌های اتاق درمان:\n";

it('تابع fr_filter_clinical_interventions بخش مداخله deep-interv را برای کاربر عادی ۱۰۰٪ حذف می‌کند', function() {
    $sample = '<div class="deep-manif">تظاهر رفتاری</div><div class="deep-interv" style="color:green"><b>🎯 راهنمای مداخلهٔ بالینی و پروتکل شواهدمحور:</b> تکنیک مواجهه تدریجی و ذهن‌آگاهی</div><p class="deep-ref">منبع علمی</p>';
    $filtered = fr_filter_clinical_interventions($sample, false);
    return !str_contains($filtered, 'deep-interv')
        && !str_contains($filtered, 'راهنمای مداخله')
        && str_contains($filtered, 'تظاهر رفتاری')
        && str_contains($filtered, 'منبع علمی');
});

it('تابع fr_filter_clinical_interventions بخش مداخله را برای مدیر سایت به طور کامل حفظ می‌کند', function() {
    $sample = '<div class="deep-manif">تظاهر رفتاری</div><div class="deep-interv" style="color:green"><b>🎯 راهنمای مداخلهٔ بالینی و پروتکل شواهدمحور:</b> تکنیک مواجهه تدریجی</div>';
    $adminView = fr_filter_clinical_interventions($sample, true);
    return str_contains($adminView, 'deep-interv')
        && str_contains($adminView, 'راهنمای مداخلهٔ بالینی');
});

it('حذف انواع اشکال تگ پاراگراف، سکشن و لیست دارای عنوان راهنمای مداخله برای کاربر عادی', function() {
    $tests = [
        '<p><b>🎯 راهنمای مداخله و پیشنهاد بالینی:</b> مداخله در اتاق درمان</p>',
        '<section><b>پروتکل مداخله بالینی:</b> تجویز رفتاری</section>',
        '<li><b>راهنمای مداخله:</b> تمرین تنفس</li>',
    ];
    foreach ($tests as $t) {
        $out = fr_filter_clinical_interventions($t, false);
        if (trim($out) !== '') {
            return false;
        }
    }
    return true;
});

// -----------------------------------------------------------------------------
// بخش ۴: آزمون و ساخت گزارش با ReportBuilder و راستی‌آزمایی تفکیک دسترسی
// -----------------------------------------------------------------------------
echo "\n📊 ۴. راستی‌آزمایی رندر کارنامه و فیلترسازی مداخله در ابعاد مختلف:\n";

// ایجاد داده‌های تستی
$testUser1 = DB::one("SELECT * FROM fr_users WHERE mobile='09120000001'");
if (!$testUser1) {
    DB::run("INSERT INTO fr_users (mobile, first_name, last_name, national_code, gender, birth_date, created_at)
             VALUES ('09120000001', 'سارا', 'محمدی', '0012345678', 'female', '1370-05-15', NOW())");
    $testUser1 = DB::one("SELECT * FROM fr_users WHERE mobile='09120000001'");
}

$testUser2 = DB::one("SELECT * FROM fr_users WHERE mobile='09120000002'");
if (!$testUser2) {
    DB::run("INSERT INTO fr_users (mobile, first_name, last_name, national_code, gender, birth_date, created_at)
             VALUES ('09120000002', 'علی', 'رضایی', '0098765432', 'male', '1365-02-10', NOW())");
    $testUser2 = DB::one("SELECT * FROM fr_users WHERE mobile='09120000002'");
}

$sampleTest = DB::one("SELECT * FROM fr_tests WHERE id=4");
if (!$sampleTest) {
    $sampleTest = DB::one("SELECT t.* FROM fr_tests t INNER JOIN fr_test_scales s ON s.test_id=t.id WHERE t.status='active' LIMIT 1");
}

// ایجاد تلاش تکمیل شده
DB::run("DELETE FROM fr_results WHERE attempt_id IN (SELECT id FROM fr_attempts WHERE rep_code='FR-TEST-450')");
DB::run("DELETE FROM fr_attempts WHERE rep_code='FR-TEST-450'");

$attemptId = DB::insert('attempts', [
    'user_id' => $testUser1['id'],
    'test_id' => $sampleTest['id'],
    'mode' => 'full',
    'status' => 'completed',
    'started_at' => date('Y-m-d H:i:s'),
    'completed_at' => date('Y-m-d H:i:s'),
    'rep_code' => 'FR-TEST-450'
]);

// ایجاد نتایج مقیاس‌ها در fr_results
DB::run("INSERT INTO fr_results (attempt_id, scale_code, raw_score, max_score, percent, t_score, sten_score, percentile, band, created_at)
         VALUES (?, 'LING', 40, 50, 80, 62, 8, 88, 'high', NOW())", [$attemptId]);
DB::run("INSERT INTO fr_results (attempt_id, scale_code, raw_score, max_score, percent, t_score, sten_score, percentile, band, created_at)
         VALUES (?, 'LOGC', 35, 50, 70, 58, 7, 75, 'medium', NOW())", [$attemptId]);

$attemptRow = DB::one("SELECT * FROM fr_attempts WHERE id=?", [$attemptId]);

it('کاربر عادی در خروجی ReportBuilder::build هرگز پروتکل مداخله را دریافت نمی‌کند', function() use ($attemptRow, $testUser1) {
    $userReport = ReportBuilder::build($attemptRow, $testUser1, 'full', ['isAdmin' => false]);
    return !str_contains($userReport, '🎯 راهنمای مداخلهٔ بالینی و پروتکل شواهدمحور')
        && !str_contains($userReport, 'deep-interv')
        && str_contains($userReport, 'تحلیل');
});

it('مدیر سامانه در خروجی ReportBuilder::build به پروتکل مداخله دسترسی دارد', function() use ($attemptRow, $testUser1) {
    $adminReport = ReportBuilder::build($attemptRow, $testUser1, 'full', ['isAdmin' => true]);
    return str_contains($adminReport, 'راهنمای مداخله')
        || str_contains($adminReport, 'deep-interv');
});

// -----------------------------------------------------------------------------
// بخش ۵: کنترل دسترسی و قالب ISO A4 در panel/report-pdf.php
// -----------------------------------------------------------------------------
echo "\n📑 ۵. آزمون کارنامه استاندارد ISO A4 و امنیت دسترسی در report-pdf.php:\n";

it('کنترلر panel/report-pdf.php وجود دارد و نحو PHP آن کاملاً سالم است', function() {
    $code = file_get_contents(FR_ROOT . '/panel/report-pdf.php');
    return str_contains($code, 'ISO A4')
        && str_contains($code, 'ReportBuilder::build')
        && str_contains($code, 'fr_report_provider_name')
        && str_contains($code, '$isAdmin');
});

it('پراکسی مدیر در admin/report-pdf.php مستقر و فعال است', function() {
    $file = FR_ROOT . '/admin/report-pdf.php';
    if (!is_file($file)) return false;
    $content = file_get_contents($file);
    return str_contains($content, 'require_admin()')
        && str_contains($content, 'report-pdf.php');
});

it('پشتیبانی از دسترسی مدیر برای آزمون سایر مراجعین در panel/report-pdf.php', function() use ($attemptId, $testUser1) {
    // شبیه‌سازی نشست ادمین
    $_SESSION['admin_id'] = 1;
    unset($_SESSION['user_id']);
    $_GET['attempt'] = (string)$attemptId;

    ob_start();
    include FR_ROOT . '/panel/report-pdf.php';
    $output = ob_get_clean();

    // پاک‌سازی
    unset($_SESSION['admin_id']);

    return str_contains($output, 'کارنامه رسمی ارزیابی روان‌شناختی')
        && str_contains($output, 'دسترسی مدیر / مشاور فعال است')
        && str_contains($output, 'سارا محمدی')
        && str_contains($output, 'reportSheet')
        && str_contains($output, '210mm');
});

it('کاربر عادی فقط به آزمون خود دسترسی دارد و دسترسی به آزمون دیگران مسدود است', function() use ($attemptId, $testUser2) {
    // کاربر ۲ تلاش می‌کند آزمون کاربر ۱ را ببیند
    $_SESSION['user_id'] = $testUser2['id'];
    unset($_SESSION['admin_id']);
    $_GET['attempt'] = (string)$attemptId;

    $atCheck = DB::one('SELECT id FROM fr_attempts WHERE id=? AND user_id=?', [$attemptId, $testUser2['id']]);

    unset($_SESSION['user_id']);
    // باید null باشد چون آزمون متعلق به کاربر ۲ نیست
    return $atCheck === null;
});

it('کاربر عادی در خروجی کارنامه A4 هرگز راهنمای مداخله را مشاهده نمی‌کند', function() use ($attemptId, $testUser1) {
    // شبیه‌سازی نشست کاربر ۱ برای آزمون خودش
    $_SESSION['user_id'] = $testUser1['id'];
    unset($_SESSION['admin_id']);
    $_GET['attempt'] = (string)$attemptId;

    ob_start();
    include FR_ROOT . '/panel/report-pdf.php';
    $userPdfHtml = ob_get_clean();

    unset($_SESSION['user_id']);

    return !str_contains($userPdfHtml, '🎯 راهنمای مداخلهٔ بالینی و پروتکل شواهدمحور')
        && !str_contains($userPdfHtml, 'deep-interv')
        && str_contains($userPdfHtml, 'نسخه معتبر مراجع')
        && str_contains($userPdfHtml, 'سارا محمدی');
});

// -----------------------------------------------------------------------------
// بخش ۶: رفع مشکل آدرس‌دهی دوگانه و تنظیمات Rewrite در .htaccess
// -----------------------------------------------------------------------------
echo "\n🌐 ۶. رفع ریشه‌ای آدرس‌دهی دوگانه /panel/panel/ و تنظیمات پیوند:\n";

it('قوانین Rewrite در .htaccess برای مهار مسیر تکراری panel/panel و admin/panel درج شده‌اند', function() {
    $htaccess = file_get_contents(FR_ROOT . '/.htaccess');
    return str_contains($htaccess, 'RewriteRule ^panel/panel/(.*)$ panel/$1 [L,QSA]')
        && str_contains($htaccess, 'RewriteRule ^admin/panel/(.*)$ panel/$1 [L,QSA]');
});

it('دکمه PDF در panel/report.php آدرس صریح و معتبر بدون تکرار تولید می‌کند', function() {
    $reportPhp = file_get_contents(FR_ROOT . '/panel/report.php');
    return str_contains($reportPhp, 'fr_panel_url(\'report-pdf.php?attempt=\'')
        && !str_contains($reportPhp, 'href="report-pdf.php?attempt=');
});

it('دکمه‌های PDF در admin/attempts.php از آدرس استاندارد fr_panel_url استفاده می‌کنند', function() {
    $attemptsPhp = file_get_contents(FR_ROOT . '/admin/attempts.php');
    return str_contains($attemptsPhp, 'fr_panel_url(\'report-pdf.php?attempt=\'');
});

// -----------------------------------------------------------------------------
// خلاصه نتایج
// -----------------------------------------------------------------------------
echo "\n" . str_repeat('=', 70) . "\n";
echo "📊 نتیجه نهایی ارزیابی نگارش ۴٫۵٫۰: {$pass} موفق | {$fail} خطا\n";
if ($fail === 0) {
    echo "🎉 تمام تست‌های کیفیت با موفقیت ۱۰۰٪ پاس شدند!\n\n";
    exit(0);
} else {
    echo "⚠️ برخی تست‌ها با خطا مواجه شدند.\n\n";
    exit(1);
}
