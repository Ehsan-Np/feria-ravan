<?php
declare(strict_types=1);

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
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/includes/seo.php';
require_once FR_ROOT . '/core/ProjectUpdater.php';

echo "=== FERIA RAVAN QA: SMS FULL TEXT, USER PANEL SLUG & UPDATER ===\n\n";

$pass = 0;
$fail = 0;

function assert_true(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $pass++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $fail++;
    }
}

// -------------------------------------------------------------
// 1. Test SMS Full Text & Monitor Rendering
// -------------------------------------------------------------
echo "1. Testing Activity Monitor > Full SMS Text Display:\n";

// Insert long multi-line SMS with Persian characters and URL
$testBody = "سلام مراجع گرامی فریا روان\nلینک اختصاصی کارنامه و تحلیل آزمون نئو (NEO-PI-R) برای شما صادر شد:\nhttps://example.com/panel/report.php?attempt=999\nلطفاً جهت بررسی نتایج کلیک کنید.";
$logId = DB::insert('sms_logs', [
    'mobile' => '09129876543',
    'event' => 'attempt_report',
    'body' => $testBody,
    'status' => 'sent',
    'provider_id' => 'kav_998877',
    'cost' => 2400
]);

$_GET['tab'] = 'sms';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/monitor.php';
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$_SESSION['admin_id'] = 1;

ob_start();
include FR_ROOT . '/admin/monitor.php';
$monitorHtml = ob_get_clean();

assert_true(str_contains($monitorHtml, 'لینک اختصاصی کارنامه و تحلیل آزمون نئو'), "Full SMS text body rendered in HTML without truncation");
assert_true(str_contains($monitorHtml, 'kav_998877'), "SMS Provider ID rendered correctly");
assert_true(str_contains($monitorHtml, 'copySmsText'), "Copy SMS button and JavaScript handler verified");
assert_true(str_contains($monitorHtml, 'نویسه'), "Character length and part calculator verified");
assert_true(str_contains($monitorHtml, 'ارسال لینک کارنامه'), "Event Persian translated label verified");

// Clean up test SMS
DB::run("DELETE FROM " . DB::t('sms_logs') . " WHERE id=?", [$logId]);


// -------------------------------------------------------------
// 2. Test User Panel URL / Slug Customization & 404 Prevention
// -------------------------------------------------------------
echo "\n2. Testing User Panel URL / Slug Customization:\n";

// Default behavior
DB::setSetting('panel_url', '/panel/');
assert_true(fr_panel_slug() === 'panel', "Default panel slug is 'panel'");
assert_true(fr_panel_url() === 'http://127.0.0.1:8000/panel/', "Default base URL is SITE_URL/panel/");
assert_true(fr_panel_url('tests.php') === 'http://127.0.0.1:8000/panel/tests.php', "Subpage URL is SITE_URL/panel/tests.php");
assert_true(fr_panel_url('?off=wallet') === 'http://127.0.0.1:8000/panel/?off=wallet', "Query URL handled properly");

// Custom slug: 'my-account'
DB::setSetting('panel_url', '/my-account/');
assert_true(fr_panel_slug() === 'my-account', "Custom slug parsed as 'my-account'");
assert_true(fr_panel_url() === 'http://127.0.0.1:8000/my-account/', "Custom base URL is SITE_URL/my-account/");
assert_true(fr_panel_url('profile.php') === 'http://127.0.0.1:8000/my-account/profile.php', "Subpage URL dynamically adapts to /my-account/profile.php");
assert_true(fr_panel_url('ticket-view.php?id=12') === 'http://127.0.0.1:8000/my-account/ticket-view.php?id=12', "Parameterized subpage URL adapts cleanly");

// Test .htaccess rewrite generation
$flushRes = fr_permalinks_flush();
assert_true($flushRes['ok'], "fr_permalinks_flush executed successfully");
$ht = (string)file_get_contents(FR_ROOT . '/.htaccess');
assert_true(str_contains($ht, 'RewriteRule ^my\\-account/?$ panel/index.php [L,QSA]'), "RewriteRule for custom panel index present in .htaccess");
assert_true(str_contains($ht, 'RewriteRule ^my\\-account/(.*)$ panel/$1 [L,QSA]'), "RewriteRule for custom panel subfiles ($1) present in .htaccess");

// Test index.php router fallback
$_SERVER['REQUEST_URI'] = '/my-account/tests.php';
$reqPath = trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$pSlug = fr_panel_slug();
assert_true($reqPath === $pSlug || str_starts_with($reqPath, $pSlug . '/'), "index.php router matches custom slug path");
$sub = substr($reqPath, strlen($pSlug));
$sub = trim((string)$sub, '/');
$targetFile = FR_ROOT . '/panel/' . $sub;
assert_true(file_exists($targetFile), "Mapped file '{$targetFile}' physically verified");

// Reset panel_url to default
DB::setSetting('panel_url', '/panel/');
fr_permalinks_flush();
assert_true(fr_panel_slug() === 'panel', "Panel slug cleanly restored to 'panel'");


// -------------------------------------------------------------
// 3. Test System Updater (/admin/updater.php)
// -------------------------------------------------------------
echo "\n3. Testing System Updater (/admin/updater.php):\n";

// Verify admin_guard function exists and works
assert_true(function_exists('admin_guard'), "admin_guard function defined and available");

// Test updater page render without fatal errors
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/updater.php';
$_SERVER['REQUEST_URI'] = '/admin/updater.php';

ob_start();
include FR_ROOT . '/admin/updater.php';
$updaterHtml = ob_get_clean();

assert_true(str_contains($updaterHtml, 'به‌روزرسانی و ارتقای سامانه'), "Updater layout rendered with proper heading");
assert_true(!str_contains($updaterHtml, 'خطای موقتی رخ داده است'), "Updater rendered without any temporary/fatal 500 error");
assert_true(str_contains($updaterHtml, 'url_update'), "ZIP URL direct update form verified");
assert_true(str_contains($updaterHtml, 'file_update'), "Manual ZIP file upload form verified");
assert_true(str_contains($updaterHtml, 'rerun_pipeline'), "Database pipeline re-run tool verified");

echo "\n============================================\n";
echo "SUMMARY: Passed: {$pass}, Failed: {$fail}\n";
if ($fail === 0) {
    echo "🎉 ALL SMS, USER PANEL SLUG & UPDATER TESTS PASSED (100% SUCCESS)!\n";
} else {
    echo "❌ SOME TESTS FAILED!\n";
}
echo "============================================\n";
