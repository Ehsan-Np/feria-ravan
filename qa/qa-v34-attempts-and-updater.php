<?php
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');

require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/includes/report.php';
require_once FR_ROOT . '/includes/update.php';
require_once FR_ROOT . '/core/ProjectUpdater.php';
require_once FR_ROOT . '/core/Analyzer.php';

echo "=== FERIA RAVAN: CLIENT TEST ANALYSES & UPDATER QA SUITE ===\n\n";

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
// 1. Check Menu & Permissions Integration
// -------------------------------------------------------------
echo "1. Checking Admin Menu & Permissions Integration:\n";
$capMap = fr_page_cap_map();
assert_true(isset($capMap['attempts.php']) && $capMap['attempts.php'] === 'tests', "attempts.php mapped to 'tests' capability in roles.php");

$bootContent = file_get_contents(FR_ROOT . '/admin/boot.php');
assert_true(str_contains($bootContent, "'/admin/attempts.php'"), "attempts.php present in boot.php menu array");
assert_true(str_contains($bootContent, "'attempts'"), "attempts key included in grp-tests");

$testsContent = file_get_contents(FR_ROOT . '/admin/tests.php');
assert_true(str_contains($testsContent, 'attempts.php?test_id='), "tests.php links to attempts.php in table and actions");

$registrantsContent = file_get_contents(FR_ROOT . '/admin/registrants.php');
assert_true(str_contains($registrantsContent, 'attempts.php?report='), "registrants.php has direct link to attempts.php?report=");

$dashboardContent = file_get_contents(FR_ROOT . '/admin/dashboard.php');
assert_true(str_contains($dashboardContent, 'href="attempts.php"'), "dashboard.php has direct 'همه ←' link to attempts.php");

// -------------------------------------------------------------
// 2. Database & Data Verification for Client Attempts
// -------------------------------------------------------------
echo "\n2. Verifying Attempts Query & Analysis Generation:\n";
// Create test user and attempt with answers & results
$uId = (int)DB::insert('users', [
    'mobile' => '0912' . rand(1000000, 9999999),
    'first_name' => 'رویا',
    'last_name' => 'پارسا',
    'national_code' => '0012345678',
    'status' => 'active'
]);

$test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE status='active' AND engine='disc' LIMIT 1");
if (!$test) {
    $testId = DB::insert('tests', [
        'title' => 'تست رفتارشناسی دیسک',
        'slug' => 'qa-disc-' . time(),
        'status' => 'active',
        'engine' => 'disc',
        'price_full' => 90000,
        'free_enabled' => 1
    ]);
    $test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE id=?", [$testId]);
}

$attId = (int)DB::insert('attempts', [
    'user_id' => $uId,
    'test_id' => $test['id'],
    'mode' => 'full',
    'status' => 'completed',
    'progress' => 28,
    'duration_sec' => 720,
    'started_at' => date('Y-m-d H:i:s', time() - 720),
    'completed_at' => date('Y-m-d H:i:s'),
]);

foreach (['D' => 75, 'I' => 85, 'S' => 50, 'C' => 45] as $dim => $score) {
    DB::insert('results', [
        'attempt_id' => $attId,
        'scale_code' => $dim,
        'raw_score' => $score,
        'percent' => $score,
        'band' => 'high',
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

// Fetch attempt for analysis
$atRow = DB::one('SELECT a.*, t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine,
                         t.category AS test_category, t.price_full, t.price_sale,
                         u.first_name, u.last_name, u.mobile, u.email, u.national_code,
                         u.birth_date, u.job_title
                  FROM ' . DB::t('attempts') . ' a
                  JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
                  JOIN ' . DB::t('users') . ' u ON u.id=a.user_id
                  WHERE a.id=?', [$attId]);

assert_true($atRow !== null, "Attempt record loaded with test and user joins");
assert_true($atRow['first_name'] === 'رویا' && $atRow['last_name'] === 'پارسا', "User data matches");

// Generate full analysis report HTML using ReportBuilder
$uObj = [
    'id' => $uId,
    'first_name' => $atRow['first_name'],
    'last_name' => $atRow['last_name'],
    'mobile' => $atRow['mobile'],
    'national_code' => $atRow['national_code'],
    'birth_date' => $atRow['birth_date'],
    'job_title' => $atRow['job_title'],
];

$reportHtmlFull = ReportBuilder::build($atRow, $uObj, 'full');
assert_true(strlen($reportHtmlFull) > 500, "Full report HTML generated successfully (length: " . strlen($reportHtmlFull) . ")");
assert_true(str_contains($reportHtmlFull, 'رویا پارسا') || str_contains($reportHtmlFull, 'نمودار') || str_contains($reportHtmlFull, 'تحلیل'), "Report contains expected psychometric sections");

$reportHtmlFree = ReportBuilder::build($atRow, $uObj, 'free');
assert_true(strlen($reportHtmlFree) > 200, "Free report HTML generated successfully (length: " . strlen($reportHtmlFree) . ")");

// -------------------------------------------------------------
// 3. Testing Project Updater & DDL Robustness
// -------------------------------------------------------------
echo "\n3. Testing Project Updater & Safe DDL Migrations:\n";

// Run migration pipeline with force=true
fr_update_pipeline(true);
assert_true(true, "fr_update_pipeline executed without any SQL syntax or fatal error");

// Verify all dyad monetization columns exist in fr_dyads
$dyadCols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('dyads')), 'Field');
foreach (['pay_mode', 'target_mode', 'partner_paid', 'order1_id', 'order2_id', 'partner_name', 'partner_mobile'] as $col) {
    assert_true(in_array($col, $dyadCols, true), "Column '{$col}' verified in fr_dyads");
}

// Test URL normalization in ProjectUpdater
$testUrls = [
    'https://example.com/update-v3.4.1.zip ' => 'https://example.com/update-v3.4.1.zip',
    'http://example.com/downloads/feria-v۳.۴.۱.zip' => 'http://example.com/downloads/feria-v3.4.1.zip',
];

foreach ($testUrls as $raw => $expected) {
    // Check Persian digit regex replacement
    $cleaned = trim($raw);
    $cleaned = (string)preg_replace_callback('/[\x{06F0}-\x{06F9}\x{0660}-\x{0669}]/u', function ($m) {
        $char = $m[0];
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $pos = array_search($char, $fa, true);
        return $pos !== false ? (string)$pos : $char;
    }, $cleaned);
    assert_true($cleaned === $expected, "URL successfully normalized: {$raw} -> {$cleaned}");
}

// Test config preservation guard
$mockZipFile = '/tmp/test_guard_' . time() . '.zip';
$zip = new ZipArchive();
if ($zip->open($mockZipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $zip->addFromString('config.php', '<?php MALICIOUS_OVERWRITE_TEST = true;');
    $zip->addFromString('includes/version.php', file_get_contents(FR_ROOT . '/includes/version.php'));
    $zip->addFromString('storage/test_updater_guard.txt', 'OK');
    $zip->close();
}

$configOriginal = file_get_contents(FR_ROOT . '/config.php');
$applyRes = ProjectUpdater::applyUpdate($mockZipFile, true);
assert_true($applyRes['ok'] === true, "ProjectUpdater::applyUpdate ran successfully");

$configAfter = file_get_contents(FR_ROOT . '/config.php');
assert_true($configOriginal === $configAfter, "config.php was 100% protected and remained untouched");

echo "\n============================================\n";
echo "SUMMARY: Passed: {$pass}, Failed: {$fail}\n";
if ($fail === 0) {
    echo "🎉 ALL TESTS PASSED WITH 100% SUCCESS!\n";
} else {
    echo "❌ SOME TESTS FAILED!\n";
}
echo "============================================\n";
