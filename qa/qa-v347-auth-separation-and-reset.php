<?php
/**
 * ============================================================================
 *  QA SUITE: v3.4.7 — AUTH SEPARATION, CUSTOM CREDENTIALS & PASSWORD RESET
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
define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/core/Kavenegar.php';

echo "=== FERIA RAVAN QA: AUTH SEPARATION, ID/PASSWORD & PASSWORD RESET ===\n\n";

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

// Clear test data and rate limits
DB::run("DELETE FROM " . DB::t('rate_limits') . " WHERE bucket LIKE 'otp%' OR bucket LIKE 'login%' OR bucket LIKE 'pwd%'");
$testMobile = '09359998877';
$testUsername = 'sara_psy_qa';
$testNatCode = '0100000002'; // valid national code
DB::run("DELETE FROM " . DB::t('users') . " WHERE mobile=? OR username=? OR national_code=?", [$testMobile, $testUsername, $testNatCode]);
DB::run("DELETE FROM " . DB::t('otps') . " WHERE mobile=?", [$testMobile]);

$sessId = bin2hex(random_bytes(16));

// -------------------------------------------------------------
// 1. Testing Unregistered Phone in Login Flow
// -------------------------------------------------------------
echo "1. Testing Unregistered Phone in Login Flow:\n";
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_COOKIE["FRSESSID"] = "' . $sessId . '";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = ["action" => "send_otp", "mobile" => "' . $testMobile . '", "purpose" => "login"];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(is_array($res), "API returned valid JSON");
assert_true(($res['ok'] ?? true) === false, "ok is false for unregistered phone in login mode");
assert_true(($res['not_registered'] ?? false) === true, "not_registered flag is true");
assert_true(str_contains($res['message'] ?? '', 'ثبت‌نام'), "Prompt instructs user to complete registration form");

// -------------------------------------------------------------
// 2. Testing Dedicated Registration with Username & Password
// -------------------------------------------------------------
echo "\n2. Testing Dedicated Registration with Username & Password:\n";

// Password mismatch test
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_COOKIE["FRSESSID"] = "' . $sessId . '";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = [
    "action"           => "register_start",
    "full_name"        => "سارا محمدی",
    "mobile"           => "' . $testMobile . '",
    "username"         => "' . $testUsername . '",
    "password"         => "secret123",
    "password_confirm" => "different123",
];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(($res['ok'] ?? true) === false, "Password mismatch was rejected");

// Valid register_start
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_COOKIE["FRSESSID"] = "' . $sessId . '";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = [
    "action"           => "register_start",
    "full_name"        => "سارا محمدی",
    "mobile"           => "' . $testMobile . '",
    "username"         => "' . $testUsername . '",
    "password"         => "secret123",
    "password_confirm" => "secret123",
    "national_code"    => "' . $testNatCode . '",
];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(is_array($res), "register_start returned valid JSON");
assert_true(($res['ok'] ?? false) === true, "register_start succeeded (" . ($res['message'] ?? '') . ")");
assert_true(($res['step'] ?? 0) === 2, "register_start advanced to step 2 (OTP verification)");

// Retrieve OTP code from database
$otpRow = DB::one("SELECT * FROM " . DB::t('otps') . " WHERE mobile=? AND purpose='register' AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$testMobile]);
assert_true(!empty($otpRow['code']), "Active register OTP found in database");
$regOtpCode = (string)($otpRow['code'] ?? '');

// Complete registration
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_COOKIE["FRSESSID"] = "' . $sessId . '";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = [
    "action" => "register_complete",
    "code"   => "' . $regOtpCode . '",
];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(is_array($res), "register_complete returned valid JSON");
assert_true(($res['ok'] ?? false) === true, "register_complete successfully created account");

// Verify created user in database
$createdUser = DB::one("SELECT * FROM " . DB::t('users') . " WHERE mobile=?", [$testMobile]);
assert_true(!empty($createdUser), "User row exists in users table");
assert_true(($createdUser['first_name'] ?? '') === 'سارا', "First name correctly saved");
assert_true(($createdUser['last_name'] ?? '') === 'محمدی', "Last name correctly saved");
assert_true(($createdUser['username'] ?? '') === $testUsername, "Custom username (آیدی) correctly saved: " . ($createdUser['username'] ?? ''));
assert_true(!empty($createdUser['password_hash']), "password_hash is securely stored");
assert_true(password_verify('secret123', (string)($createdUser['password_hash'] ?? '')), "password_hash matches configured password");
assert_true((int)($createdUser['mobile_verified'] ?? 0) === 1, "mobile_verified flag is 1");

// -------------------------------------------------------------
// 3. Testing Login with Username, Mobile, and National Code
// -------------------------------------------------------------
echo "\n3. Testing Login with Multiple Identities:\n";

// Login with Username
$loginByUname = password_login($testUsername, 'secret123');
assert_true($loginByUname['ok'] === true, "Login with Username (آیدی) succeeded");

// Login with Mobile
$loginByMob = password_login($testMobile, 'secret123');
assert_true($loginByMob['ok'] === true, "Login with Mobile number succeeded");

// Login with National Code
$loginByNat = password_login($testNatCode, 'secret123');
assert_true($loginByNat['ok'] === true, "Login with National Code succeeded");

// Wrong password
$loginWrong = password_login($testUsername, 'wrongpass');
assert_true($loginWrong['ok'] === false, "Login with wrong password rejected");

// -------------------------------------------------------------
// 4. Testing Password Recovery via Username, Mobile & National Code
// -------------------------------------------------------------
echo "\n4. Testing Password Recovery Flow:\n";

// Clear reset rate limits
DB::run("DELETE FROM " . DB::t('rate_limits') . " WHERE bucket LIKE 'pwd%' OR bucket LIKE 'otp%'");

// A. Forgot password by Username
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_COOKIE["FRSESSID"] = "' . $sessId . '";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = [
    "action"   => "forgot_password_send",
    "identity" => "' . $testUsername . '",
];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(($res['ok'] ?? false) === true, "forgot_password_send succeeded via Username");
assert_true(!empty($res['masked_mobile']), "Masked mobile returned: " . ($res['masked_mobile'] ?? ''));
assert_true(($res['step'] ?? 0) === 2, "Response directs to step 2");

// B. Forgot password by National Code
DB::run("DELETE FROM " . DB::t('rate_limits') . " WHERE bucket LIKE 'pwd%' OR bucket LIKE 'otp%'");
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_COOKIE["FRSESSID"] = "' . $sessId . '";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = [
    "action"   => "forgot_password_send",
    "identity" => "' . $testNatCode . '",
];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(($res['ok'] ?? false) === true, "forgot_password_send succeeded via National Code");

// C. Forgot password by Mobile
DB::run("DELETE FROM " . DB::t('rate_limits') . " WHERE bucket LIKE 'pwd%' OR bucket LIKE 'otp%'");
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_COOKIE["FRSESSID"] = "' . $sessId . '";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = [
    "action"   => "forgot_password_send",
    "identity" => "' . $testMobile . '",
];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(($res['ok'] ?? false) === true, "forgot_password_send succeeded via Mobile Number");

// Retrieve reset OTP code
$resetOtpRow = DB::one("SELECT * FROM " . DB::t('otps') . " WHERE mobile=? AND purpose='reset' AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$testMobile]);
assert_true(!empty($resetOtpRow['code']), "Reset OTP code found in database");
$resetOtpCode = (string)($resetOtpRow['code'] ?? '');

// D. Verify Reset OTP and Set New Password
$newTestPassword = 'newPassword9988';
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_COOKIE["FRSESSID"] = "' . $sessId . '";
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = [
    "action"               => "forgot_password_verify",
    "code"                 => "' . $resetOtpCode . '",
    "new_password"         => "' . $newTestPassword . '",
    "new_password_confirm" => "' . $newTestPassword . '",
];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(is_array($res), "forgot_password_verify returned valid JSON");
assert_true(($res['ok'] ?? false) === true, "Password reset succeeded");

// Verify new password in database
$updatedUser = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$createdUser['id']]);
assert_true(password_verify($newTestPassword, (string)($updatedUser['password_hash'] ?? '')), "Database stores new password hash");

// Verify login with new password succeeds and old password fails
$oldPassLogin = password_login($testUsername, 'secret123');
assert_true($oldPassLogin['ok'] === false, "Old password rejected after reset");

$newPassLogin = password_login($testUsername, $newTestPassword);
assert_true($newPassLogin['ok'] === true, "New password authenticated successfully");

// Clean up test user
DB::run("DELETE FROM " . DB::t('users') . " WHERE mobile=?", [$testMobile]);
DB::run("DELETE FROM " . DB::t('otps') . " WHERE mobile=?", [$testMobile]);

echo "\n=============================================================\n";
echo "QA SUITE SUMMARY: {$pass} PASSED, {$fail} FAILED\n";
echo "=============================================================\n";
if ($fail > 0) exit(1);
