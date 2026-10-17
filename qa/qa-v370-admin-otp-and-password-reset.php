<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های تضمین کیفیت: ورود پیامکی OTP و بازیابی رمز عبور مدیران
 *  (QA Test Suite: Admin OTP Login & Password Recovery System)
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

echo "=================================================================\n";
echo "   FERIA RAVAN QA TEST SUITE: ADMIN OTP LOGIN & PASSWORD RESET\n";
echo "=================================================================\n\n";

// --- فاز ۱: فایل‌ها و اندپوینت‌های مورد نیاز ---
echo "1. Testing Files & Endpoints Existence:\n";
assert_test(file_exists(FR_ROOT . '/admin/login.php'), 'admin/login.php exists');
assert_test(file_exists(FR_ROOT . '/admin/reset-password.php'), 'admin/reset-password.php exists');
assert_test(file_exists(FR_ROOT . '/api/admin-auth.php'), 'api/admin-auth.php exists');
assert_test(function_exists('admin_find_by_identity'), 'admin_find_by_identity function exists');
assert_test(function_exists('admin_otp_login_send'), 'admin_otp_login_send function exists');
assert_test(function_exists('admin_otp_login_verify'), 'admin_otp_login_verify function exists');
assert_test(function_exists('admin_password_reset_send'), 'admin_password_reset_send function exists');
assert_test(function_exists('admin_password_reset_verify'), 'admin_password_reset_verify function exists');

// --- فاز ۲: ایجاد و آمادگی داده‌های تست ---
echo "\n2. Preparing Test Admin Accounts in Database:\n";
$prefix = 'qa_adm_' . time() . '_';

// مدیر با شماره موبایل معتبر
$admMobile = '0912999' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
$admPass = 'AdminSecret@2026';
$adm1Id = DB::insert('admins', [
    'username'      => $prefix . 'valid',
    'password'      => password_hash($admPass, PASSWORD_ARGON2ID),
    'password_hash' => password_hash($admPass, PASSWORD_ARGON2ID),
    'display_name'  => 'مدیر تست پیامکی',
    'mobile'        => $admMobile,
    'email'         => $prefix . 'valid@feria.test',
    'role'          => 'superadmin',
    'status'        => 'active',
]);
assert_test($adm1Id > 0, "Test admin with mobile created (ID: {$adm1Id})");

// مدیر بدون شماره موبایل
$adm2Id = DB::insert('admins', [
    'username'      => $prefix . 'nomobile',
    'password'      => password_hash($admPass, PASSWORD_ARGON2ID),
    'password_hash' => password_hash($admPass, PASSWORD_ARGON2ID),
    'display_name'  => 'مدیر بدون تلفن',
    'mobile'        => null,
    'email'         => $prefix . 'nomob@feria.test',
    'role'          => 'manager',
    'status'        => 'active',
]);
assert_test($adm2Id > 0, "Test admin without mobile created (ID: {$adm2Id})");

// مدیر مسدود (blocked)
$adm3Id = DB::insert('admins', [
    'username'      => $prefix . 'blocked',
    'password'      => password_hash($admPass, PASSWORD_ARGON2ID),
    'password_hash' => password_hash($admPass, PASSWORD_ARGON2ID),
    'display_name'  => 'مدیر مسدودشده',
    'mobile'        => '0912888' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT),
    'email'         => $prefix . 'blocked@feria.test',
    'role'          => 'support',
    'status'        => 'blocked',
]);
assert_test($adm3Id > 0, "Test blocked admin created (ID: {$adm3Id})");

// --- فاز ۳: استعلام هویت مدیر (admin_find_by_identity) ---
echo "\n3. Testing Admin Identity Lookup:\n";
$f1 = admin_find_by_identity($prefix . 'valid');
assert_test(!empty($f1) && (int)$f1['id'] === $adm1Id, 'Find admin by exact username');

$f2 = admin_find_by_identity($admMobile);
assert_test(!empty($f2) && (int)$f2['id'] === $adm1Id, 'Find admin by registered mobile');

$f3 = admin_find_by_identity($prefix . 'valid@feria.test');
assert_test(!empty($f3) && (int)$f3['id'] === $adm1Id, 'Find admin by registered email');

$f4 = admin_find_by_identity('non_existent_admin_username_9999');
assert_test($f4 === null, 'Lookup non-existent admin returns null');

// --- فاز ۴: ورود با رمز عبور (admin_login) بر اساس شناسه ---
echo "\n4. Testing Multi-Identifier Password Login:\n";
admin_logout();
$lp1 = admin_login($prefix . 'valid', $admPass);
assert_test(!empty($lp1['ok']), 'Login with username + password');
assert_test(current_admin() !== null && (int)current_admin()['id'] === $adm1Id, 'Admin session authenticated');

admin_logout();
$lp2 = admin_login($admMobile, $admPass);
assert_test(!empty($lp2['ok']), 'Login with mobile + password');
assert_test(current_admin() !== null && (int)current_admin()['id'] === $adm1Id, 'Mobile-based login session active');

admin_logout();
$lp3 = admin_login($prefix . 'valid@feria.test', $admPass);
assert_test(!empty($lp3['ok']), 'Login with email + password');

admin_logout();
$lpFail = admin_login($prefix . 'valid', 'WrongPassword123');
assert_test(empty($lpFail['ok']), 'Login with incorrect password rejected');

$lpBlock = admin_login($prefix . 'blocked', $admPass);
assert_test(empty($lpBlock['ok']), 'Login for blocked admin account rejected');

// --- فاز ۵: ورود پیامکی مدیر (Admin OTP Login) ---
echo "\n5. Testing Admin OTP Login Lifecycle:\n";
admin_logout();

// ۵٫۱ اعتبارسنجی ورودی‌های نامعتبر
$oFail1 = admin_otp_login_send('');
assert_test(empty($oFail1['ok']), 'OTP send rejects empty identity');

$oFail2 = admin_otp_login_send('non_existent_manager_123');
assert_test(empty($oFail2['ok']), 'OTP send rejects non-existent manager');

$oFail3 = admin_otp_login_send($prefix . 'blocked');
assert_test(empty($oFail3['ok']), 'OTP send rejects blocked admin');

$oFail4 = admin_otp_login_send($prefix . 'nomobile');
assert_test(empty($oFail4['ok']), 'OTP send rejects admin without registered mobile');

// ۵٫۲ ارسال موفق کد تأیید ورود
$otpRes = admin_otp_login_send($prefix . 'valid');
assert_test(!empty($otpRes['ok']), 'OTP login send succeeds for valid admin');
assert_test(!empty($otpRes['step']) && $otpRes['step'] === 2, 'OTP send transitions to step 2');
assert_test(!empty($otpRes['masked_mobile']), 'Masked mobile returned: ' . ($otpRes['masked_mobile'] ?? ''));
assert_test(!empty($otpRes['ttl']) && $otpRes['ttl'] >= 60, 'TTL returned: ' . ($otpRes['ttl'] ?? 0));
assert_test(!empty($_SESSION['pending_admin_otp_login']), 'Session pending_admin_otp_login is stored');

// ۵٫۳ استخراج کد و اعتبارسنجی
$savedOtp = DB::one("SELECT * FROM " . DB::t('otps') . " WHERE mobile=? AND purpose='admin_login' ORDER BY id DESC LIMIT 1", [$admMobile]);
assert_test(!empty($savedOtp), 'OTP record stored in fr_otps with purpose=admin_login');
$code = (string)($savedOtp['code'] ?? '');
assert_test($code !== '', "OTP code generated: {$code}");

// ۵٫۴ رد کد اشتباه
$vFail = admin_otp_login_verify('00000');
assert_test(empty($vFail['ok']), 'OTP verify rejects wrong code (00000)');

// ۵٫۵ تأیید موفق کد صحیح و ورود مدیر
$vOk = admin_otp_login_verify($code);
assert_test(!empty($vOk['ok']), 'OTP verify succeeds with correct code');
assert_test(current_admin() !== null && (int)current_admin()['id'] === $adm1Id, 'Admin session authenticated via OTP');
assert_test(empty($_SESSION['pending_admin_otp_login']), 'Pending OTP session cleared after login');

// بررسی باطل شدن کد استفاده‌شده
$usedOtp = DB::one("SELECT used_at FROM " . DB::t('otps') . " WHERE id=?", [$savedOtp['id']]);
assert_test(!empty($usedOtp['used_at']), 'Used OTP marked with used_at timestamp');

// رد استفاده مجدد از کد قبلی
$vReused = admin_otp_login_verify($code);
assert_test(empty($vReused['ok']), 'Reused OTP code rejected');

// --- فاز ۶: بازیابی و ریست رمز عبور مدیر (Password Reset) ---
echo "\n6. Testing Admin Password Reset Lifecycle:\n";
admin_logout();

// ۶٫۱ درخواست ارسال کد بازیابی
$rFail1 = admin_password_reset_send('');
assert_test(empty($rFail1['ok']), 'Reset send rejects empty identity');

$rFail2 = admin_password_reset_send('unknown_user_9988');
assert_test(empty($rFail2['ok']), 'Reset send rejects non-existent admin');

$rFail3 = admin_password_reset_send($prefix . 'nomobile');
assert_test(empty($rFail3['ok']), 'Reset send rejects admin without mobile');

$rOk = admin_password_reset_send($prefix . 'valid');
assert_test(!empty($rOk['ok']), 'Reset send succeeds for admin by username');
assert_test(!empty($_SESSION['pending_admin_pwd_reset']), 'pending_admin_pwd_reset stored in session');

$resetOtp = DB::one("SELECT * FROM " . DB::t('otps') . " WHERE mobile=? AND purpose='admin_reset' ORDER BY id DESC LIMIT 1", [$admMobile]);
assert_test(!empty($resetOtp), 'Reset OTP stored in fr_otps with purpose=admin_reset');
$resetCode = (string)($resetOtp['code'] ?? '');

// ۶٫۲ اعتبارسنجی رمزهای نامعتبر
$rvShort = admin_password_reset_verify($resetCode, 'short', 'short');
assert_test(empty($rvShort['ok']), 'Reset verify rejects password shorter than 8 characters');

$rvMismatch = admin_password_reset_verify($resetCode, 'NewPass@2026', 'DifferentPass@2026');
assert_test(empty($rvMismatch['ok']), 'Reset verify rejects mismatched password confirmation');

$rvWrongCode = admin_password_reset_verify('99999', 'NewPass@2026', 'NewPass@2026');
assert_test(empty($rvWrongCode['ok']), 'Reset verify rejects invalid verification code');

// ۶٫۳ تغییر موفق رمز عبور
$newPassword = 'BrandNewAdminPass#2026';
$rvSuccess = admin_password_reset_verify($resetCode, $newPassword, $newPasswordConfirm = $newPassword);
assert_test(!empty($rvSuccess['ok']), 'Password reset successfully completed');
assert_test(empty($_SESSION['pending_admin_pwd_reset']), 'pending_admin_pwd_reset session cleared');

// ۶٫۴ اعتبارسنجی ورود با رمز جدید
admin_logout();
$oldLoginFail = admin_login($prefix . 'valid', $admPass);
assert_test(empty($oldLoginFail['ok']), 'Old password no longer works');

$newLoginOk = admin_login($prefix . 'valid', $newPassword);
assert_test(!empty($newLoginOk['ok']), 'Admin logged in successfully using NEW password');
assert_test(current_admin() !== null && (int)current_admin()['id'] === $adm1Id, 'New password login creates valid admin session');

// --- فاز ۷: آزمون API احراز هویت مدیریت (api/admin-auth.php) ---
echo "\n7. Testing API Endpoint (api/admin-auth.php):\n";

$apiAdminMob = '0912111' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
$apiAdminPass = 'ApiAdminPass@2026';
$apiAdminId = DB::insert('admins', [
    'username'      => 'qa_api_admin_' . time(),
    'password'      => password_hash($apiAdminPass, PASSWORD_ARGON2ID),
    'password_hash' => password_hash($apiAdminPass, PASSWORD_ARGON2ID),
    'display_name'  => 'مدیر تست API',
    'mobile'        => $apiAdminMob,
    'email'         => 'qa_api@feria.test',
    'role'          => 'manager',
    'status'        => 'active',
]);

$sessId = session_id();

function run_api_script(array $postData, string $sessId): array {
    session_write_close();
    $cmd = 'php -d display_errors=0 -d error_reporting=0 -r ' . escapeshellarg('
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
        $_SERVER["REQUEST_URI"] = "/api/admin-auth.php";
        $_POST = ' . var_export($postData, true) . ';

        include "' . FR_ROOT . '/api/admin-auth.php";
    ');
    $out = shell_exec($cmd);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return json_decode((string)$out, true) ?: [];
}

// ۷٫۱ لاگین موفق با پسورد از طریق API
$apiLoginOk = run_api_script(['action' => 'login', 'identity' => $apiAdminMob, 'password' => $apiAdminPass], $sessId);
assert_test(!empty($apiLoginOk['ok']), 'API password login succeeds with mobile + password');

// ۷٫۲ ارسال کد OTP از طریق API
$apiOtpSend = run_api_script(['action' => 'otp_send', 'identity' => $apiAdminMob], $sessId);
assert_test(!empty($apiOtpSend['ok']) && ($apiOtpSend['step'] ?? 0) === 2, 'API otp_send returns step=2');
assert_test(!empty($apiOtpSend['masked_mobile']), 'API otp_send returns masked_mobile');

// استخراج کد ارسالی
$apiOtpCode = (string)DB::val("SELECT code FROM " . DB::t('otps') . " WHERE mobile=? AND purpose='admin_login' ORDER BY id DESC LIMIT 1", [$apiAdminMob]);
assert_test(!empty($apiOtpCode), "API otp code found in db: {$apiOtpCode}");

// تأیید کد از طریق API
$apiOtpVerify = run_api_script(['action' => 'otp_verify', 'code' => $apiOtpCode, 'mobile' => $apiAdminMob], $sessId);
assert_test(!empty($apiOtpVerify['ok']), 'API otp_verify succeeds with valid code and mobile');

// ۷٫۳ بازیابی رمز عبور از طریق API
$apiResetSend = run_api_script(['action' => 'reset_send', 'identity' => $apiAdminMob], $sessId);
assert_test(!empty($apiResetSend['ok']) && ($apiResetSend['step'] ?? 0) === 2, 'API reset_send returns step=2');

$apiResetCode = (string)DB::val("SELECT code FROM " . DB::t('otps') . " WHERE mobile=? AND purpose='admin_reset' ORDER BY id DESC LIMIT 1", [$apiAdminMob]);
assert_test(!empty($apiResetCode), "API reset code found in db: {$apiResetCode}");

$newApiPass = 'NewApiPassword!2026';
$apiResetVerify = run_api_script([
    'action'               => 'reset_verify',
    'code'                 => $apiResetCode,
    'new_password'         => $newApiPass,
    'new_password_confirm' => $newApiPass,
    'identity'             => $apiAdminMob,
], $sessId);
assert_test(!empty($apiResetVerify['ok']), 'API reset_verify successfully updates password');

// بررسی امکان ورود با رمز جدید
$apiReLogin = run_api_script(['action' => 'login', 'identity' => $apiAdminMob, 'password' => $newApiPass], $sessId);
assert_test(!empty($apiReLogin['ok']), 'API login succeeds with newly reset password');

// پاک‌سازی ادمین API
DB::run("DELETE FROM " . DB::t('otps') . " WHERE mobile=?", [$apiAdminMob]);
DB::run("DELETE FROM " . DB::t('admins') . " WHERE id=?", [$apiAdminId]);

// پاک‌سازی داده‌های آزمایشی
echo "\n8. Cleaning Up Test Fixtures:\n";
DB::run("DELETE FROM " . DB::t('otps') . " WHERE mobile IN (?, ?)", [$admMobile, '09128889999']);
DB::run("DELETE FROM " . DB::t('admins') . " WHERE id IN (?, ?, ?)", [$adm1Id, $adm2Id, $adm3Id]);
assert_test(!DB::val("SELECT id FROM " . DB::t('admins') . " WHERE id=?", [$adm1Id]), 'Test admins cleaned up');

echo "\n=================================================================\n";
echo "QA RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=================================================================\n";

if ($failed > 0) exit(1);

echo "\n=================================================================\n";
echo "QA RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=================================================================\n";

if ($failed > 0) exit(1);
