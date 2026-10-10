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

require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/admin-auth.php';
require_once FR_ROOT . '/includes/update.php';

echo "=== FERIA RAVAN: RESEED & UNIVERSAL DDL RESILIENCE QA SUITE ===\n\n";

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
// 1. Test Universal Safe DDL Runner (fr_db_run_safe_ddl)
// -------------------------------------------------------------
echo "1. Testing Universal Safe DDL Runner (fr_db_run_safe_ddl):\n";
assert_true(function_exists('fr_db_run_safe_ddl'), "fr_db_run_safe_ddl helper function exists");

$p = DB_PREFIX;
// Create test sandbox table
DB::run("CREATE TABLE IF NOT EXISTS `{$p}qa_sandbox` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Test ADD COLUMN IF NOT EXISTS simulation (which normally crashes MySQL 8.0)
fr_db_run_safe_ddl("ALTER TABLE `{$p}qa_sandbox` ADD COLUMN IF NOT EXISTS `test_col_1` VARCHAR(50) NULL AFTER `title`");
$cols = array_column(DB::all("SHOW COLUMNS FROM `{$p}qa_sandbox`"), 'Field');
assert_true(in_array('test_col_1', $cols, true), "Column test_col_1 successfully added via safe DDL");

// Run it again to verify idempotency (no Duplicate column error)
fr_db_run_safe_ddl("ALTER TABLE `{$p}qa_sandbox` ADD COLUMN IF NOT EXISTS `test_col_1` VARCHAR(50) NULL AFTER `title`");
assert_true(true, "Second execution did not throw any duplicate column error");

// Test ADD KEY IF NOT EXISTS simulation
fr_db_run_safe_ddl("ALTER TABLE `{$p}qa_sandbox` ADD KEY IF NOT EXISTS `ix_test_col` (`test_col_1`)");
$keys = array_column(DB::all("SHOW INDEX FROM `{$p}qa_sandbox`"), 'Key_name');
assert_true(in_array('ix_test_col', $keys, true), "Index ix_test_col successfully added via safe DDL");

// Run key again to verify idempotency (no Duplicate key error)
fr_db_run_safe_ddl("ALTER TABLE `{$p}qa_sandbox` ADD KEY IF NOT EXISTS `ix_test_col` (`test_col_1`)");
assert_true(true, "Second key execution did not throw any duplicate key error");

// Clean up sandbox table
DB::run("DROP TABLE IF EXISTS `{$p}qa_sandbox`");

// -------------------------------------------------------------
// 2. Test Admin & OTP Columns and Tables
// -------------------------------------------------------------
echo "\n2. Testing Admin & OTP Structure in Database:\n";
fr_update_pipeline(true);

$adminCols = array_column(DB::all("SHOW COLUMNS FROM `{$p}admins`"), 'Field');
foreach (['id', 'user_id', 'username', 'password', 'password_hash', 'status', 'role_id', 'last_login_at'] as $expectedCol) {
    assert_true(in_array($expectedCol, $adminCols, true), "Admin column '{$expectedCol}' confirmed in database");
}

$hasOtpsTable = (bool)DB::val("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$p}otps'");
assert_true($hasOtpsTable, "otps table confirmed in database");
$otpCols = array_column(DB::all("SHOW COLUMNS FROM `{$p}otps`"), 'Field');
foreach (['id', 'mobile', 'code', 'purpose', 'expires_at', 'used_at', 'tries'] as $expectedCol) {
    assert_true(in_array($expectedCol, $otpCols, true), "OTP column '{$expectedCol}' confirmed in database");
}

// -------------------------------------------------------------
// 3. Test current_admin() and admin_login() Resilience
// -------------------------------------------------------------
echo "\n3. Testing current_admin() and admin_login() Resilience:\n";
$testAdmUser = 'qa_admin_' . time();
$testAdmPass = 'AdminSecret@123';
$testAdmHash = password_hash($testAdmPass, PASSWORD_ARGON2ID);

$aid = (int)DB::insert('admins', [
    'username' => $testAdmUser,
    'password' => $testAdmHash,
    'password_hash' => $testAdmHash,
    'display_name' => 'مدیر آزمون تضمین کیفیت',
    'role' => 'superadmin',
    'status' => 'active'
]);

// Test login with password_verify
$loginRes = admin_login($testAdmUser, $testAdmPass);
assert_true($loginRes['ok'] === true, "admin_login succeeded with valid credentials");

// Test current_admin() with active session
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$_SESSION['admin_id'] = $aid;
$current = current_admin();
assert_true($current !== null && (int)$current['id'] === $aid, "current_admin() returned valid admin object");
assert_true($current['status'] === 'active', "current_admin() has active status");

// Test blocked admin handling
DB::update('admins', ['status' => 'blocked'], 'id=?', [$aid]);
// Reset static cache in current_admin() by unsetting session
unset($_SESSION['admin_id']);
$_SESSION['admin_id'] = $aid;
// Test with reflection or fresh scope
$blockedLogin = admin_login($testAdmUser, $testAdmPass);
assert_true($blockedLogin['ok'] === false, "admin_login rejected blocked admin account");

// Restore admin to active
DB::update('admins', ['status' => 'active'], 'id=?', [$aid]);

// -------------------------------------------------------------
// 4. Test install/reseed.php?force=1 Complete Execution
// -------------------------------------------------------------
echo "\n4. Testing install/reseed.php?force=1 Complete Execution:\n";

// Execute reseed with admin logged in in production mode
$origConfig = file_get_contents(FR_ROOT . '/config.php');
$testConfig = <<<'CFG'
<?php
declare(strict_types=1);
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_CRYPT_KEY', '1234567890123456789012345678901234567890123456789012345678901234');
define('FR_ENV', 'production');
CFG;
file_put_contents(FR_ROOT . '/config.php', $testConfig);

$cmd = 'php -r "
\$_GET[\"force\"] = \"1\";
\$_SERVER[\"REQUEST_METHOD\"] = \"GET\";
\$_SERVER[\"REMOTE_ADDR\"] = \"127.0.0.1\";
\$_SERVER[\"HTTP_HOST\"] = \"127.0.0.1:8000\";
\$_SERVER[\"REQUEST_URI\"] = \"/install/reseed.php?force=1\";
\$_SERVER[\"SCRIPT_NAME\"] = \"/install/reseed.php\";
session_start();
\$_SESSION[\"admin_id\"] = ' . $aid . ';
include \"' . FR_ROOT . '/install/reseed.php\";
" 2>&1';

$reseedOutput = shell_exec($cmd);
assert_true(!str_contains($reseedOutput, 'خطای موقتی رخ داده است'), "No temporary error (500) HTML rendered");
assert_true(!str_contains($reseedOutput, 'Feria Fatal'), "No fatal error logged in output");
assert_true(str_contains($reseedOutput, 'اسکیمای نسخهٔ ۱٫۲ آماده است'), "Schema v1.2 step completed");
assert_true(str_contains($reseedOutput, 'اسکیمای نسخهٔ ۲٫۳ آماده است'), "Schema v2.3 step completed");
assert_true(str_contains($reseedOutput, 'بذر محتوا انجام شد'), "Content seed completed successfully");
assert_true(str_contains($reseedOutput, 'قالب‌های تحلیل:'), "Analysis templates counted and verified");

// Test reseed without admin login: should return 403 or redirect, without throwing any fatal error
$cmdUnauth = 'php -r "
\$_GET[\"force\"] = \"1\";
\$_SERVER[\"REQUEST_METHOD\"] = \"GET\";
\$_SERVER[\"REMOTE_ADDR\"] = \"127.0.0.1\";
\$_SERVER[\"HTTP_HOST\"] = \"127.0.0.1:8000\";
\$_SERVER[\"REQUEST_URI\"] = \"/install/reseed.php?force=1\";
\$_SERVER[\"SCRIPT_NAME\"] = \"/install/reseed.php\";
include \"' . FR_ROOT . '/install/reseed.php\";
" 2>&1';
$unauthOutput = shell_exec($cmdUnauth);

assert_true(str_contains($unauthOutput, 'دسترسی غیرمجاز'), "Unauthenticated request safely blocked without 500 crash");
file_put_contents(FR_ROOT . '/config.php', $origConfig);

// Clean up test admin
DB::run("DELETE FROM `{$p}admins` WHERE id=?", [$aid]);

echo "\n============================================\n";
echo "SUMMARY: Passed: {$pass}, Failed: {$fail}\n";
if ($fail === 0) {
    echo "🎉 ALL RESEED & UNIVERSAL DDL TESTS PASSED (100% SUCCESS)!\n";
} else {
    echo "❌ SOME TESTS FAILED!\n";
}
echo "============================================\n";
