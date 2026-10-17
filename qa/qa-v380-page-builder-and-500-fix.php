<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت: نگارش ۳٫۸٫۰
 *  (QA Test Suite: v3.8.0 - PageBuilder Multi-Page System & Test 500 Fix)
 * ============================================================================
 *  ۱. بررسی رفع خطای ۵۰۰ در شروع و نمایش آزمون‌ها و سازگاری دیتابیس کیف پول
 *  ۲. بررسی حل کامل صفحه سفید صفحه‌ساز دیداری (PHPageBuilder / GrapesJS)
 *  ۳. بررسی مدیریت و طراحی برگه‌های تست‌ها، دوره‌ها، کارگاه‌ها، مشاوره و صفحه اصلی
 *  ۴. بررسی ویجت‌های جدید، قابلیت نوسازی، انتخاب‌گر سریع برگه‌ها و مودال‌ها
 * ============================================================================
 */
declare(strict_types=1);

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
require_once FR_ROOT . '/includes/admin-auth.php';
require_once FR_ROOT . '/includes/wallet.php';
require_once FR_ROOT . '/includes/seo.php';
require_once FR_ROOT . '/core/PageBuilder.php';
require_once FR_ROOT . '/core/MenuBuilder.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('FRSESSID');
    session_id(bin2hex(random_bytes(16)));
    session_start();
}

$total = 0;
$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $desc, string $info = ''): void {
    global $total, $passed, $failed;
    $total++;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($info !== '' ? " -> {$info}" : "") . "\n";
    }
}

function run_script_capture(string $script, array $get = []): string {
    $code = 'define("DB_HOST", "127.0.0.1"); define("DB_NAME", "feria_test"); define("DB_USER", "feria"); define("DB_PASS", "feria_password"); define("DB_PREFIX", "fr_"); define("SITE_URL", "http://127.0.0.1:8000"); define("FR_ROOT", "/home/user/feria-ravan/feria-ravan"); define("FR_ENV", "production"); ';
    $code .= '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_HOST"] = "127.0.0.1:8000"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; ';
    $code .= '$_GET = ' . var_export($get, true) . '; ';
    $code .= 'ob_start(); require FR_ROOT . "/' . $script . '"; echo ob_get_clean();';

    $cmd = 'php -r ' . escapeshellarg($code) . ' 2>&1';
    return (string)shell_exec($cmd);
}

echo "=================================================================\n";
echo "   FERIA RAVAN QA TEST SUITE: v3.8.0 RELEASE VERIFICATION\n";
echo "=================================================================\n\n";

// --- بخش ۱: بررسی نگارش سامانه ---
echo "1. System Version Verification:\n";
assert_test(defined('FR_RELEASE') && version_compare(FR_RELEASE, '3.8.0', '>='), 'FR_RELEASE is 3.8.0 or higher');
assert_test(defined('FR_VERSION') && version_compare(FR_VERSION, '3.8.0', '>='), 'FR_VERSION is 3.8.0 or higher');

// --- بخش ۲: رفع خطای ۵۰۰ در ورود به آزمون‌ها و کیف پول ---
echo "\n2. Test Start & Wallet 500 Error Prevention:\n";

// اطمینان از وجود کاربر تست در دیتابیس
$testUser = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id = 1");
if (!$testUser) {
    DB::run("INSERT INTO " . DB::t('users') . " (id, mobile, first_name, last_name, status, created_at)
             VALUES (1, '09123456789', 'کاربر', 'تستی', 'active', NOW())
             ON DUPLICATE KEY UPDATE status='active'");
}

// ساخت تراکنش تستی کیف پول و اعتبارسنجی سازگاری نام ستون‌ها
$balBefore = wallet_balance(1);
assert_test(is_int($balBefore) && $balBefore >= 0, 'wallet_balance() returns integer without PDOException', "Balance: {$balBefore}");

$txIdCredit = wallet_credit(1, 50000, 'deposit', 'تست شارژ کیف پول QA v3.8.0');
assert_test($txIdCredit > 0, 'wallet_credit() succeeds with safe multi-column compatibility', "Tx ID: {$txIdCredit}");

$balAfter = wallet_balance(1);
assert_test($balAfter === $balBefore + 50000, 'wallet balance correctly increased after credit');

$txIdDebit = wallet_debit(1, 20000, 'تست کسر از کیف پول QA v3.8.0');
assert_test($txIdDebit > 0, 'wallet_debit() succeeds with safe multi-column compatibility', "Tx ID: {$txIdDebit}");

$balFinal = wallet_balance(1);
assert_test($balFinal === $balAfter - 20000, 'wallet balance correctly decreased after debit');

// بررسی ستون‌های جدول fr_wallet_transactions
$colsWallet = DB::all("SHOW COLUMNS FROM " . DB::t('wallet_transactions'));
$colNames = array_column($colsWallet, 'Field');
assert_test(in_array('kind', $colNames, true), 'fr_wallet_transactions has kind column');
assert_test(in_array('type', $colNames, true), 'fr_wallet_transactions has type column');
assert_test(in_array('expires_at', $colNames, true), 'fr_wallet_transactions has expires_at column');
assert_test(in_array('balance_after', $colNames, true), 'fr_wallet_transactions has balance_after column');
assert_test(in_array('description', $colNames, true), 'fr_wallet_transactions has description column');
assert_test(in_array('note', $colNames, true), 'fr_wallet_transactions has note column');

// اطمینان از وجود تست شماره ۱
$firstTest = DB::one("SELECT id, slug FROM " . DB::t('tests') . " WHERE status='active' LIMIT 1");
if (!$firstTest) {
    DB::run("INSERT INTO " . DB::t('tests') . " (id, title, slug, short_desc, status, is_published, free_enabled)
             VALUES (1, 'تست شخصیت و رفتارشناسی DISC', 'disc-family', 'ارزیابی ابعاد رفتاری', 'active', 1, 1)
             ON DUPLICATE KEY UPDATE status='active'");
    $firstTest = ['id' => 1, 'slug' => 'disc-family'];
}

// تست بارگذاری panel/test-start.php
$_SESSION['user_id'] = 1;
$_SESSION['user_logged_in'] = true;
$_GET['id'] = (string)$firstTest['id'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
$_SERVER['REQUEST_URI'] = '/panel/test-start.php?id=' . $firstTest['id'];

ob_start();
require FR_ROOT . '/panel/test-start.php';
$outTestStart = ob_get_clean();
assert_test(strlen($outTestStart) > 500, 'panel/test-start.php renders cleanly (>500 bytes)', 'Length: ' . strlen($outTestStart));
assert_test(strpos($outTestStart, 'Fatal') === false && strpos($outTestStart, '500 Server Error') === false, 'panel/test-start.php contains zero fatal/500 errors');

// تست بارگذاری test-page.php با اسلاگ
$_GET = ['slug' => (string)$firstTest['slug']];
$_SERVER['REQUEST_URI'] = '/test-page.php?slug=' . urlencode((string)$firstTest['slug']);
ob_start();
require FR_ROOT . '/test-page.php';
$outTestPage = ob_get_clean();
assert_test(strlen($outTestPage) > 500, 'test-page.php?slug=... renders cleanly (>500 bytes)', 'Length: ' . strlen($outTestPage));
assert_test(strpos($outTestPage, 'Fatal') === false, 'test-page.php contains zero fatal errors');

// --- بخش ۳: ساختار و همگام‌سازی برگه‌ها در صفحه‌ساز دیداری ---
echo "\n3. PageBuilder Schema & Default Pages Seeding:\n";

PageBuilder::ensureSchema();
PageBuilder::seedDefaultPages();

$seededHome = PageBuilder::getHomepage();
assert_test($seededHome !== null, 'PageBuilder::getHomepage() finds active home page');
assert_test(($seededHome['slug'] ?? '') === 'home' || !empty($seededHome['is_home']), 'Home page has correct slug or is_home flag');

$seededSections = ['tests', 'courses', 'workshops', 'consult'];
foreach ($seededSections as $sec) {
    $secPage = PageBuilder::getSectionPage($sec);
    assert_test($secPage !== null, "PageBuilder::getSectionPage('{$sec}') successfully found", "Title: " . ($secPage['title'] ?? 'N/A'));
    assert_test(($secPage['page_type'] ?? '') === $sec || ($secPage['slug'] ?? '') === $sec, "Section '{$sec}' has matching page_type or slug");
}

$aboutPage = PageBuilder::getSectionPage('about-us');
assert_test($aboutPage !== null, "PageBuilder::getSectionPage('about-us') found");

// --- بخش ۴: رندر و پیش‌نمایش ویجت‌ها در ویرایشگر (جلوگیری از صفحه سفید) ---
echo "\n4. Widget Resolution & Visual Preview (Preventing White Screen):\n";

$rawHtmlWithEmptyWidgets = '<div class="test-container">
    <div data-widget="hero" data-title="عنوان تست" data-subtitle="زیرعنوان تست"></div>
    <div data-widget="tests_catalog" data-limit="4"></div>
    <div data-widget="test_single"></div>
    <div data-widget="courses" data-limit="3"></div>
    <div data-widget="course_single"></div>
    <div data-widget="workshops" data-limit="3"></div>
    <div data-widget="workshop_single"></div>
    <div data-widget="consult"></div>
    <div data-widget="consultants_list"></div>
    <div data-widget="banner_cta"></div>
    <div data-widget="newsletter"></div>
    <div data-widget="faq"></div>
    <div data-widget="contact_box"></div>
</div>';

$resolvedForEditor = PageBuilder::resolveWidgets($rawHtmlWithEmptyWidgets, true);
assert_test(strlen($resolvedForEditor) > strlen($rawHtmlWithEmptyWidgets) * 3, 'PageBuilder::resolveWidgets(isEditor=true) replaced empty placeholders with rich HTML', 'Length: ' . strlen($resolvedForEditor));
assert_test(strpos($resolvedForEditor, 'fr-widget-hero') !== false, 'Hero widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-tests-catalog-wrap') !== false, 'tests_catalog widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-test-single') !== false, 'test_single widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-courses-wrapper') !== false, 'courses widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-course-single') !== false, 'course_single widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-workshops-wrapper') !== false, 'workshops widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-workshop-single') !== false, 'workshop_single widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-consult-wrapper') !== false, 'consult widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-consultants-wrap') !== false, 'consultants_list widget resolved into preview markup');
assert_test(strpos($resolvedForEditor, 'fr-widget-banner-cta') !== false, 'banner_cta widget resolved into preview markup');

// بررسی لیست ویجت‌های GrapesJS Block Manager
$availableWidgets = PageBuilder::getAvailableWidgets();
assert_test(count($availableWidgets) >= 12, 'Available widgets count >= 12', 'Count: ' . count($availableWidgets));
$widgetIds = array_column($availableWidgets, 'id');
assert_test(in_array('tests_catalog', $widgetIds, true), 'tests_catalog is in available widgets');
assert_test(in_array('test_single', $widgetIds, true), 'test_single is in available widgets');
assert_test(in_array('course_single', $widgetIds, true), 'course_single is in available widgets');
assert_test(in_array('workshop_single', $widgetIds, true), 'workshop_single is in available widgets');
assert_test(in_array('consultants_list', $widgetIds, true), 'consultants_list is in available widgets');
assert_test(in_array('banner_cta', $widgetIds, true), 'banner_cta is in available widgets');

// --- بخش ۵: رندر کامل admin/page-builder.php در حالت‌های مختلف ---
echo "\n5. admin/page-builder.php UI & Canvas Verification:\n";

// لاگین مدیر
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'superadmin';
$_SESSION['admin_name'] = 'مدیر ارشد';

// تست لود صفحه اصلی
$_GET = ['id' => '1'];
$_SERVER['REQUEST_URI'] = '/admin/page-builder.php?id=1';
$_SERVER['SCRIPT_NAME'] = '/admin/page-builder.php';
ob_start();
require FR_ROOT . '/admin/page-builder.php';
$outBuilderHome = ob_get_clean();

assert_test(strlen($outBuilderHome) > 10000, 'admin/page-builder.php?id=1 renders full editor (>10KB)', 'Length: ' . strlen($outBuilderHome));
assert_test(strpos($outBuilderHome, 'grapesjs.init') !== false, 'admin/page-builder.php contains grapesjs.init');
assert_test(strpos($outBuilderHome, 'id="pb-loader"') !== false, 'admin/page-builder.php contains #pb-loader overlay');
assert_test(strpos($outBuilderHome, 'id="pbPageSwitcher"') !== false, 'admin/page-builder.php contains #pbPageSwitcher section dropdown');
assert_test(strpos($outBuilderHome, '[data-widget]:empty') !== false, 'admin/page-builder.php contains [data-widget]:empty anti-collapse canvas styles');
assert_test(strpos($outBuilderHome, 'fr_page_builder_catch') !== false, 'admin/page-builder.php contains error boundary recovery block');

// تست لود بخش تست‌ها با section=tests
$testSecPage = PageBuilder::getSectionPage('tests');
if ($testSecPage) {
    $_GET = ['id' => (string)$testSecPage['id']];
    $_SERVER['REQUEST_URI'] = '/admin/page-builder.php?id=' . $testSecPage['id'];
    ob_start();
    require FR_ROOT . '/admin/page-builder.php';
    $outBuilderTests = ob_get_clean();
    assert_test(strlen($outBuilderTests) > 10000, 'admin/page-builder.php for tests catalog renders full editor', 'Length: ' . strlen($outBuilderTests));
    assert_test(strpos($outBuilderTests, 'مرکز آزمون‌های تخصصی') !== false, 'Builder title reflects tests catalog page');
}

// --- بخش ۶: رندر صفحات عمومی کاتالوگ با صفحه‌ساز و حالت Classic ---
echo "\n6. Public Section Pages PageBuilder & Classic Bypass:\n";

// تست ۱: tests.php
$outTestsPb = run_script_capture('tests.php');
assert_test(strlen($outTestsPb) > 2000, 'tests.php renders custom PageBuilder template', 'Length: ' . strlen($outTestsPb));

$outTestsClassic = run_script_capture('tests.php', ['classic' => '1']);
assert_test(strlen($outTestsClassic) > 2000, 'tests.php?classic=1 falls back cleanly to classic template', 'Length: ' . strlen($outTestsClassic));

// تست ۲: courses.php
$outCoursesPb = run_script_capture('courses.php');
assert_test(strlen($outCoursesPb) > 2000, 'courses.php renders custom PageBuilder template', 'Length: ' . strlen($outCoursesPb));

$outCoursesClassic = run_script_capture('courses.php', ['classic' => '1']);
assert_test(strlen($outCoursesClassic) > 2000, 'courses.php?classic=1 falls back cleanly to classic template', 'Length: ' . strlen($outCoursesClassic));

// تست ۳: workshops.php
$outWorkshopsPb = run_script_capture('workshops.php');
assert_test(strlen($outWorkshopsPb) > 2000, 'workshops.php renders custom PageBuilder template', 'Length: ' . strlen($outWorkshopsPb));

$outWorkshopsClassic = run_script_capture('workshops.php', ['classic' => '1']);
assert_test(strlen($outWorkshopsClassic) > 2000, 'workshops.php?classic=1 falls back cleanly to classic template', 'Length: ' . strlen($outWorkshopsClassic));

// تست ۴: consult.php
$outConsultPb = run_script_capture('consult.php');
assert_test(strlen($outConsultPb) > 2000, 'consult.php renders custom PageBuilder template', 'Length: ' . strlen($outConsultPb));

// --- بخش ۷: بررسی admin/pages.php و تب‌های بخش‌های مختلف ---
echo "\n7. admin/pages.php UI & Section Tabs Verification:\n";

$_GET = ['tab' => 'all'];
$_SERVER['REQUEST_URI'] = '/admin/pages.php?tab=all';
$_SERVER['SCRIPT_NAME'] = '/admin/pages.php';
ob_start();
require FR_ROOT . '/admin/pages.php';
$outPagesAll = ob_get_clean();
assert_test(strlen($outPagesAll) > 5000, 'admin/pages.php?tab=all renders cleanly', 'Length: ' . strlen($outPagesAll));
assert_test(strpos($outPagesAll, 'tab=tests') !== false, 'admin/pages.php contains tests tab link');
assert_test(strpos($outPagesAll, 'tab=courses') !== false, 'admin/pages.php contains courses tab link');
assert_test(strpos($outPagesAll, 'tab=workshops') !== false, 'admin/pages.php contains workshops tab link');
assert_test(strpos($outPagesAll, 'tab=consult') !== false, 'admin/pages.php contains consult tab link');
assert_test(strpos($outPagesAll, 'seed_defaults') !== false, 'admin/pages.php contains seed_defaults button action');
assert_test(strpos($outPagesAll, 'id="modalAddPage"') !== false, 'admin/pages.php contains #modalAddPage with multi-type support');

// خلاصه نهایی
echo "\n=================================================================\n";
echo "QA SUITE SUMMARY: Total: {$total} | Passed: {$passed} | Failed: {$failed}\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
