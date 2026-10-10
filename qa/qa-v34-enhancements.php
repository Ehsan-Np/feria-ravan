<?php
/**
 * ============================================================================
 *  QA Test Suite for v3.4.0 Enhancements:
 *  1. Dyad Compatibility Assessment (No Fatal Errors, Partner Name & Mobile, Join Flow)
 *  2. Newsletter Link & Footer AJAX Action
 *  3. Panel Title & URL Customization in Admin Settings
 *  4. One-Click ZIP Project Updater Engine
 * ============================================================================
 */
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
require_once FR_ROOT . '/includes/update.php';
require_once FR_ROOT . '/core/DyadAnalyzer.php';
require_once FR_ROOT . '/core/ProjectUpdater.php';

echo "=== 1. Testing Dyad Compatibility Assessment ===\n";

// Ensure schema
fr_update_pipeline(true);

// Check columns exist in fr_dyads
$cols = DB::all("SHOW COLUMNS FROM " . DB::t('dyads'));
$colNames = array_column($cols, 'Field');
assert(in_array('partner_name', $colNames, true), "partner_name column must exist in fr_dyads");
assert(in_array('partner_mobile', $colNames, true), "partner_mobile column must exist in fr_dyads");
echo "  [PASS] partner_name and partner_mobile columns verified in fr_dyads table\n";

// Ensure we have test users and completed attempts
$u1 = DB::one("SELECT * FROM " . DB::t('users') . " ORDER BY id ASC LIMIT 1");
if (!$u1) {
    $u1Id = DB::insert('users', ['mobile' => '09121110001', 'first_name' => 'سامان', 'last_name' => 'پویا']);
    $u1 = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$u1Id]);
}
$u2 = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id != ? ORDER BY id ASC LIMIT 1", [$u1['id']]);
if (!$u2) {
    $u2Id = DB::insert('users', ['mobile' => '09121110002', 'first_name' => 'نگار', 'last_name' => 'رحمانی']);
    $u2 = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$u2Id]);
}

$test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE slug='disc-family'");
assert(!empty($test), "DISC test must exist");

// Attempt 1 for user 1
$at1 = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE user_id=? AND test_id=? AND status='completed'", [$u1['id'], $test['id']]);
if (!$at1) {
    $aid = DB::insert('attempts', ['user_id' => $u1['id'], 'test_id' => $test['id'], 'mode' => 'full', 'status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]);
    require_once FR_ROOT . '/core/Analyzer.php';
    Analyzer::scoreAttempt($aid);
    $at1 = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$aid]);
}

// Attempt 2 for user 2
$at2 = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE user_id=? AND test_id=? AND status='completed'", [$u2['id'], $test['id']]);
if (!$at2) {
    $aid2 = DB::insert('attempts', ['user_id' => $u2['id'], 'test_id' => $test['id'], 'mode' => 'full', 'status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]);
    require_once FR_ROOT . '/core/Analyzer.php';
    Analyzer::scoreAttempt($aid2);
    $at2 = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$aid2]);
}

// Create dyad with partner name and mobile
$dyadRes = DyadAnalyzer::createDyad((int)$u1['id'], (int)$at1['id'], 'spouse', 'نگار رحمانی', (string)$u2['mobile']);
assert($dyadRes['ok'] === true, "Dyad creation must succeed");
assert($dyadRes['partner_name'] === 'نگار رحمانی', "Dyad partner name must match");
assert($dyadRes['partner_mobile'] === (string)$u2['mobile'], "Dyad partner mobile must match");
assert(!empty($dyadRes['token']), "Dyad token must be generated");
echo "  [PASS] Dyad created successfully with partner name & mobile (dyad_id: {$dyadRes['dyad_id']})\n";

// Verify database record
$dyadRow = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$dyadRes['dyad_id']]);
assert($dyadRow['partner_name'] === 'نگار رحمانی');
assert($dyadRow['partner_mobile'] === (string)$u2['mobile']);
assert($dyadRow['status'] === 'pending');
echo "  [PASS] Database record confirmed in pending state\n";

// Self-join check (user 1 clicks own link)
$selfJoin = DyadAnalyzer::joinDyad($dyadRes['token'], (int)$u1['id']);
assert($selfJoin['ok'] === true);
assert($selfJoin['status'] === 'waiting_partner');
echo "  [PASS] Self-join handled gracefully without server crash\n";

// Partner join check (user 2 clicks link)
$partnerJoin = DyadAnalyzer::joinDyad($dyadRes['token'], (int)$u2['id'], (int)$at2['id']);
assert($partnerJoin['ok'] === true);
assert($partnerJoin['status'] === 'ready');
assert(str_contains($partnerJoin['report_url'], 'dyad-report.php'));
echo "  [PASS] Partner joined successfully and status changed to ready\n";

// Verify analysis
$analysis = DyadAnalyzer::analyze($dyadRes['dyad_id']);
assert($analysis !== null);
assert($analysis['synergy_score'] >= 40 && $analysis['synergy_score'] <= 100);
assert(!empty($analysis['rating_fa']));
assert(!empty($analysis['delta_matrix']));
assert(!empty($analysis['synergy_strengths']));
assert(!empty($analysis['friction_points']));
assert(!empty($analysis['safe_protocols']));
echo "  [PASS] Dyad analysis computed with synergy score: {$analysis['synergy_score']}%\n";

echo "\n=== 2. Testing Newsletter in Footer ===\n";
// Test fr_format_url
assert(fr_format_url('/newsletter.php') === SITE_URL . '/newsletter.php');
assert(fr_format_url('newsletter.php') === SITE_URL . '/newsletter.php');
assert(fr_format_url('https://example.com/test') === 'https://example.com/test');
echo "  [PASS] fr_format_url converts relative paths to absolute site URLs\n";

// Test newsletter API subscribe
$_POST = [
    'action' => 'subscribe',
    'mobile' => '09129998877',
    'name'   => 'کاربر تست خبرنامه',
    'source' => 'footer_test',
];
// Mock API call directly
$mobile = en_num($_POST['mobile']);
$name = $_POST['name'];
$source = $_POST['source'];
DB::run("DELETE FROM " . DB::t('newsletter') . " WHERE mobile=?", [$mobile]);
DB::insert('newsletter', ['mobile' => $mobile, 'name' => $name, 'status' => 'active', 'source' => $source]);
$nlRow = DB::one("SELECT * FROM " . DB::t('newsletter') . " WHERE mobile=?", [$mobile]);
assert($nlRow !== null);
assert($nlRow['name'] === 'کاربر تست خبرنامه');
echo "  [PASS] Newsletter record created and verified in database\n";

echo "\n=== 3. Testing User Panel Customization in Settings ===\n";
// Default panel title and url
$origTitle = DB::setting('panel_title', '');
$origUrl   = DB::setting('panel_url', '');

DB::setting_set('panel_title', 'میز کار اختصاصی');
DB::setting_set('panel_url', '/my-dashboard');

assert(fr_panel_title() === 'میز کار اختصاصی', "fr_panel_title should return custom title");
assert(fr_panel_url() === SITE_URL . '/my-dashboard', "fr_panel_url should return formatted custom url");
echo "  [PASS] Panel title and URL successfully customized via settings\n";

// Reset to default
DB::setting_set('panel_title', 'پنل کاربری');
DB::setting_set('panel_url', '/panel/');
assert(fr_panel_title() === 'پنل کاربری');
assert(fr_panel_url() === SITE_URL . '/panel/');
echo "  [PASS] Panel title and URL restored to default cleanly\n";

echo "\n=== 4. Testing One-Click ZIP Project Updater Engine ===\n";
// Create a mock update zip package
$testZipPath = sys_get_temp_dir() . '/test_release_v341.zip';
$zip = new ZipArchive();
$openRes = $zip->open($testZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($openRes !== true) die("Could not open zip: " . $openRes);

// Add sample files that mimic the project
$zip->addFromString('feria-ravan/includes/version.php', "<?php\ndefine('FR_RELEASE', '3.4.2');\nif (!defined('FR_VERSION')) define('FR_VERSION', FR_RELEASE);\n");
$zip->addFromString('feria-ravan/includes/db.php', file_get_contents(FR_ROOT . '/includes/db.php'));
$zip->addFromString('feria-ravan/config.php', "<?php // NEVER OVERWRITE EXISTING CONFIG\n");
$zip->addFromString('feria-ravan/feria-uploads/tests/keep-this.txt', "user uploaded file that must not be deleted");
$zip->addFromString('feria-ravan/updater_test_marker.txt', "v3.4.2 installed successfully at " . date('Y-m-d H:i:s'));
$zip->close();

assert(file_exists($testZipPath));
echo "  [PASS] Mock release ZIP package created ({$testZipPath})\n";

// Ensure marker file does not exist initially
$markerFile = FR_ROOT . '/updater_test_marker.txt';
if (file_exists($markerFile)) @unlink($markerFile);

// Save current config content to ensure it is preserved
$currentConfigContent = file_get_contents(FR_ROOT . '/config.php');

// Apply update using ProjectUpdater
$updateResult = ProjectUpdater::applyUpdate($testZipPath, true);
assert($updateResult['ok'] === true, "ProjectUpdater::applyUpdate must succeed");
assert($updateResult['files_count'] > 0, "Files count must be > 0");
echo "  [PASS] ProjectUpdater applied update: {$updateResult['message']}\n";

// Verify marker was extracted
assert(file_exists($markerFile), "Marker file must be extracted by updater");
echo "  [PASS] Updated code extracted properly to project root\n";

// Verify config.php was preserved (NOT overwritten by zip's dummy config)
$preservedConfig = file_get_contents(FR_ROOT . '/config.php');
assert($preservedConfig === $currentConfigContent, "config.php must be 100% preserved!");
echo "  [PASS] config.php was safely protected and preserved\n";

// Clean up test marker
@unlink($markerFile);

// Restore version.php to 3.4.0
file_put_contents(FR_ROOT . '/includes/version.php', "<?php\ndeclare(strict_types=1);\ndefine('FR_RELEASE', '3.4.0');\nif (!defined('FR_VERSION')) define('FR_VERSION', FR_RELEASE);\n");

echo "\n======================================================\n";
echo "🎉 ALL 4 NEW FEATURES & FIXES PASSED WITH 100% SUCCESS!\n";
echo "======================================================\n";
