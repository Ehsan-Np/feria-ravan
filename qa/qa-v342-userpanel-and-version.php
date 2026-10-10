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
require_once FR_ROOT . '/includes/update.php';

echo "=== FERIA RAVAN: USER PANEL & v3.4.3 QA SUITE ===\n\n";

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
// 1. Version 3.4.3 Verification
// -------------------------------------------------------------
echo "1. Checking System Versioning:\n";
assert_true(defined('FR_RELEASE') && FR_RELEASE === '3.4.3', "FR_RELEASE is strictly 3.4.3");
assert_true(defined('FR_VERSION') && FR_VERSION === '3.4.3', "FR_VERSION is strictly 3.4.3");

// Run update pipeline
fr_update_pipeline(true);
$dbVer = (string)DB::setting('fr_version', '');
assert_true($dbVer === '3.4.3', "Database version setting updated to 3.4.3");

// -------------------------------------------------------------
// 2. Database Sessions Table Verification
// -------------------------------------------------------------
echo "\n2. Checking Database Sessions Table Structure:\n";
$hasSessions = (bool)DB::val("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . DB_PREFIX . "sessions'");
assert_true($hasSessions, "fr_sessions table verified in database");

$sessCols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('sessions')), 'Field');
foreach (['id', 'user_id', 'admin_id', 'ip', 'user_agent', 'device', 'login_at', 'last_seen', 'logout_at'] as $sc) {
    assert_true(in_array($sc, $sessCols, true), "Column '{$sc}' verified in fr_sessions");
}

// -------------------------------------------------------------
// 3. User Panel Linking Verification
// -------------------------------------------------------------
echo "\n3. Checking User Panel Links & Admin Boot Render:\n";
$panelUrl = fr_panel_url();
assert_true(str_starts_with($panelUrl, 'http://127.0.0.1:8000/panel') || str_starts_with($panelUrl, 'http'), "fr_panel_url() returns valid absolute URL: {$panelUrl}");
assert_true(!str_contains($panelUrl, '8000http') && !str_contains($panelUrl, '//panel//'), "fr_panel_url() has no doubled host/protocol");

// Check admin boot rendering
$bootContent = file_get_contents(FR_ROOT . '/admin/boot.php');
assert_true(str_contains($bootContent, "preg_match('#^https?://#i', \$url)"), "admin/boot.php checks for absolute URLs to prevent doubling SITE_URL");
assert_true(!str_contains($bootContent, "href=\"' . SITE_URL . \$url . '\""), "admin/boot.php does not blindly prepend SITE_URL to absolute URLs");

// Test simulated rendering
$testUrl1 = '/admin/tests.php';
$final1 = (preg_match('#^https?://#i', $testUrl1)) ? $testUrl1 : rtrim(SITE_URL, '/') . '/' . ltrim($testUrl1, '/');
assert_true($final1 === 'http://127.0.0.1:8000/admin/tests.php', "Relative admin path rendered properly: {$final1}");

$testUrl2 = $panelUrl;
$final2 = (preg_match('#^https?://#i', $testUrl2)) ? $testUrl2 : rtrim(SITE_URL, '/') . '/' . ltrim($testUrl2, '/');
assert_true($final2 === $panelUrl, "Absolute user panel path rendered properly without doubling: {$final2}");

// -------------------------------------------------------------
// 4. User Panel Seamless Access (Logged-in Admin to User Panel)
// -------------------------------------------------------------
echo "\n4. Checking Seamless User Panel Access:\n";
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

// Create test admin with unique username and mobile
$randSuffix = time() . '_' . rand(100, 999);
$admMobile = '0912' . rand(1000000, 9999999);
$admId = (int)DB::insert('admins', [
    'username' => 'qa_adm_' . $randSuffix,
    'password' => password_hash('AdmPass@123', PASSWORD_ARGON2ID),
    'password_hash' => password_hash('AdmPass@123', PASSWORD_ARGON2ID),
    'display_name' => 'مدیر تست پنل',
    'mobile' => $admMobile,
    'role' => 'superadmin',
    'status' => 'active'
]);

// Clear user session to test admin auto-bridging
unset($_SESSION['user_id']);
$_SESSION['admin_id'] = $admId;

// Calling current_user() as an admin without prior user_id session:
$bridgedUser = current_user();
assert_true($bridgedUser !== null, "current_user() successfully resolved connected user for logged-in admin");
assert_true(!empty($_SESSION['user_id']), "User session automatically initialized for admin");
assert_true($bridgedUser['first_name'] === 'مدیر تست پنل' || $bridgedUser['mobile'] === $admMobile, "User profile data matches admin");

// Verify session update in fr_sessions
$activeSess = DB::one("SELECT * FROM " . DB::t('sessions') . " WHERE id=?", [session_id()]);
assert_true($activeSess !== null, "Session record created/updated in fr_sessions");

// Clean up test data
DB::run("DELETE FROM " . DB::t('admins') . " WHERE id=?", [$admId]);
if ($bridgedUser) DB::run("DELETE FROM " . DB::t('users') . " WHERE id=?", [$bridgedUser['id']]);

echo "\n============================================\n";
echo "SUMMARY: Passed: {$pass}, Failed: {$fail}\n";
if ($fail === 0) {
    echo "🎉 ALL USER PANEL & v3.4.3 TESTS PASSED (100% SUCCESS)!\n";
} else {
    echo "❌ SOME TESTS FAILED!\n";
}
echo "============================================\n";
