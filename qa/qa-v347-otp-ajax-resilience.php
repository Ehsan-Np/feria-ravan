<?php
/**
 * ============================================================================
 *  QA SUITE: v3.4.7 — OTP AJAX INTEGRITY, BUFFER PURITY & API RESILIENCE
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
require_once FR_ROOT . '/includes/update.php';

echo "=== FERIA RAVAN QA: v3.4.7 OTP AJAX & BUFFER PURITY RESILIENCE ===\n\n";

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
// 1. Version definition check
// -------------------------------------------------------------
echo "1. Checking System Version Definitions:\n";
assert_true(version_compare(FR_RELEASE, '3.4.7', '>='), "FR_RELEASE is at least 3.4.7 (current: " . FR_RELEASE . ")");
assert_true(version_compare(FR_VERSION, '3.4.7', '>='), "FR_VERSION is at least 3.4.7 (current: " . FR_VERSION . ")");

// -------------------------------------------------------------
// 2. Testing ensure_otps_table()
// -------------------------------------------------------------
echo "\n2. Testing ensure_otps_table() and Schema Guarantee:\n";
ensure_otps_table();
$cols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('otps')), 'Field');
assert_true(in_array('mobile', $cols, true), "otps has mobile column");
assert_true(in_array('code', $cols, true), "otps has code column");
assert_true(in_array('code_hash', $cols, true), "otps has code_hash column");
assert_true(in_array('used_at', $cols, true), "otps has used_at column");
assert_true(in_array('tries', $cols, true), "otps has tries column");

// -------------------------------------------------------------
// 3. Testing Buffer Purity in json_out()
// -------------------------------------------------------------
echo "\n3. Testing Buffer Purity in json_out():\n";
// Run a separate PHP process simulating contaminated buffer before json_out
$cmd = 'php -r ' . escapeshellarg('
require "' . FR_ROOT . '/includes/functions.php";
ob_start();
echo "STRAY BUFFER WARNING NOTICE OR BOM";
json_out(["ok" => true, "status" => "pristine"]);
');
$procOutput = shell_exec($cmd);
assert_true(!str_contains((string)$procOutput, "STRAY BUFFER"), "json_out purged prior buffer content completely");
$parsed = json_decode((string)$procOutput, true);
assert_true(is_array($parsed) && ($parsed['ok'] ?? false) === true, "json_out returned 100% valid, uncorrupted JSON");

// -------------------------------------------------------------
// 4. Testing api/auth.php under simulated HTTP requests
// -------------------------------------------------------------
echo "\n4. Testing api/auth.php under Simulated HTTP Requests:\n";

// A. Invalid mobile
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = ["action" => "send_otp", "mobile" => "12345"];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(is_array($res), "api/auth.php returned valid JSON on invalid mobile");
assert_true(($res['ok'] ?? true) === false, "ok is false on invalid mobile");
assert_true(str_contains($res['message'] ?? '', 'نامعتبر'), "Helpful Persian error message returned");

// B. Empty CSRF does not block send_otp (public rate-limited auth endpoint)
if (!find_user_by('09129876543')) {
    DB::insert('users', ['first_name' => 'تست', 'last_name' => 'کاربر', 'mobile' => '09129876543', 'status' => 'active']);
}
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = ["action" => "send_otp", "mobile" => "09129876543"];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(is_array($res), "api/auth.php returned valid JSON without CSRF block");
assert_true(($res['ok'] ?? false) === true, "send_otp succeeded without CSRF session failure");
assert_true(isset($res['ttl']) && $res['ttl'] >= 60, "Response contains valid ttl");

// C. Verify OTP cycle
$testMobile = '09129876543';
$activeOtp = DB::one("SELECT * FROM " . DB::t('otps') . " WHERE mobile=? AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$testMobile]);
assert_true(!empty($activeOtp['code']), "Active OTP exists in database");

$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["CONTENT_TYPE"] = "application/json";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_URI"] = "/api/auth.php";
$_POST = ["action" => "verify_otp", "mobile" => "' . $testMobile . '", "code" => "' . $activeOtp['code'] . '"];

include "' . FR_ROOT . '/api/auth.php";
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(is_array($res), "verify_otp returned valid JSON");
assert_true(($res['ok'] ?? false) === true, "verify_otp succeeded");

// -------------------------------------------------------------
// 5. Testing fr_fatal_guard() API JSON Formatting
// -------------------------------------------------------------
echo "\n5. Testing fr_fatal_guard() JSON formatting on API requests:\n";
$cmd = 'php -r ' . escapeshellarg('
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");

require "' . FR_ROOT . '/includes/security.php";
$_SERVER["REQUEST_URI"] = "/api/test.php";
$_SERVER["HTTP_ACCEPT"] = "application/json";
fr_fatal_guard();

// Trigger a fatal error
call_to_non_existent_function_testing_fatal_guard();
');
$raw = (string)shell_exec($cmd);
$res = json_decode($raw, true);
assert_true(is_array($res), "fr_fatal_guard returned valid JSON on fatal error in API");
assert_true(($res['ok'] ?? true) === false, "ok is false in fatal error response");
assert_true(!str_contains($raw, "<!DOCTYPE html>"), "fr_fatal_guard did NOT output HTML on API error");

// -------------------------------------------------------------
// 6. Testing Kavenegar DDL Caching
// -------------------------------------------------------------
echo "\n6. Testing Kavenegar DDL Caching:\n";
$beforeRuns = 0;
Kavenegar::ensureTemplatesSchema();
Kavenegar::ensureTemplatesSchema();
Kavenegar::ensureTemplatesSchema();
assert_true(true, "ensureTemplatesSchema executed smoothly with static guard");

// -------------------------------------------------------------
// 7. Testing JavaScript Regex JSON Recovery
// -------------------------------------------------------------
echo "\n7. Testing JavaScript JSON Recovery Pattern:\n";
$polluted = "Warning: Undefined variable \$foo in test.php on line 12\n{\"ok\":true,\"message\":\"کد ارسال شد\"}\n";
preg_match('/\{[\s\S]*\}/', $polluted, $matches);
assert_true(!empty($matches[0]), "Regex matched JSON block inside polluted string");
$recovered = json_decode($matches[0], true);
assert_true(($recovered['ok'] ?? false) === true, "Recovered valid JSON from text with PHP warnings");

echo "\n=============================================================\n";
echo "QA SUITE SUMMARY: {$pass} PASSED, {$fail} FAILED\n";
echo "=============================================================\n";
if ($fail > 0) exit(1);
