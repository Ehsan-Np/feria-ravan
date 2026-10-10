<?php
/**
 * ============================================================================
 *  QA SUITE: v3.4.6 — OTP SMS DELIVERY, KAVENEGAR GATEWAY & AUTH RESILIENCE
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
define('FR_ENV', 'development');

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/admin-auth.php';
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/core/Kavenegar.php';
require_once FR_ROOT . '/includes/update.php';

echo "=== FERIA RAVAN QA: v3.4.6 OTP SMS & GATEWAY RESILIENCE ===\n\n";

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
assert_true(version_compare(FR_RELEASE, '3.4.6', '>='), "FR_RELEASE is at least '3.4.6' (got " . FR_RELEASE . ")");
assert_true(version_compare(FR_VERSION, '3.4.6', '>='), "FR_VERSION is at least '3.4.6' (got " . FR_VERSION . ")");

// -------------------------------------------------------------
// 2. Database Migration & Schema Self-Healing
// -------------------------------------------------------------
echo "\n2. Testing Schema Migration & Template Auto-Healing:\n";
Kavenegar::ensureTemplatesSchema();

$cols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('sms_templates')), 'Field');
assert_true(in_array('use_pattern', $cols, true), "sms_templates has 'use_pattern' column");
assert_true(in_array('pattern_code', $cols, true), "sms_templates has 'pattern_code' column");
assert_true(in_array('title', $cols, true), "sms_templates has 'title' column");

$reqEvents = ['otp', 'register', 'payment_ok', 'test_done', 'report_free', 'report_full', 'admin_new_order', 'refund_request_user', 'refund_request_admin', 'test_nudge'];
foreach ($reqEvents as $ev) {
    $row = DB::one("SELECT * FROM " . DB::t('sms_templates') . " WHERE event=?", [$ev]);
    assert_true(!empty($row), "sms_templates contains standard event '{$ev}'");
}

$otpTpl = DB::one("SELECT * FROM " . DB::t('sms_templates') . " WHERE event='otp'");
assert_true((int)($otpTpl['use_pattern'] ?? 0) === 1, "OTP template defaults to use_pattern=1");
assert_true(($otpTpl['pattern_code'] ?? '') === 'otp', "OTP template defaults to pattern_code='otp'");

// Run update pipeline migration to verify idempotency
fr_update_pipeline(true);
$dbVer = DB::setting('fr_version');
assert_true(version_compare((string)$dbVer, '3.4.6', '>='), "Database settings fr_version upgraded to at least '3.4.6' (got {$dbVer})");

// -------------------------------------------------------------
// 3. Mobile Normalization & Operator Matrix (All Iranian Carriers)
// -------------------------------------------------------------
echo "\n3. Testing Mobile Normalization across Iranian Operators:\n";
assert_true(clean_mobile('09121234567') === '09121234567', "MCI standard format 09121234567 unchanged");
assert_true(clean_mobile('+989121234567') === '09121234567', "MCI +98 converted to 09");
assert_true(clean_mobile('00989121234567') === '09121234567', "MCI 0098 converted to 09");
assert_true(clean_mobile('989121234567') === '09121234567', "MCI prefixed 98 converted to 09");
assert_true(clean_mobile('9121234567') === '09121234567', "MCI 10-digit without leading 0 converted to 09");
assert_true(clean_mobile('۰۹۱۲۱۲۳۴۵۶۷') === '09121234567', "MCI Persian digits converted to English 09121234567");
assert_true(clean_mobile('0912 123 4567') === '09121234567', "MCI spaces stripped correctly");
assert_true(clean_mobile(' ۰۹۱۲-۱۲۳-۴۵۶۷ ') === '09121234567', "MCI dashes and whitespace stripped with Persian digits");
assert_true(clean_mobile('+9809121234567') === '09121234567', "MCI +980 extra-zero mistake corrected to 09121234567");

// Testing Aptel (آپتل - 09991)
echo "\n   Testing Aptel (آپتل) Series:\n";
assert_true(clean_mobile('09991234567') === '09991234567', "Aptel 09991234567 valid");
assert_true(clean_mobile('+989991234567') === '09991234567', "Aptel +989991234567 converted to 09991234567");
assert_true(clean_mobile(' ۰۹۹۹۱-۲۳۴۵۶۷ ') === '09991234567', "Aptel Persian digits and dashes normalized");
assert_true(valid_mobile('09991234567'), "valid_mobile accepts Aptel 09991234567");
$opAptel = fr_mobile_operator('09991234567');
assert_true($opAptel['valid'] && $opAptel['slug'] === 'aptel', "fr_mobile_operator identifies Aptel (got {$opAptel['name']})");

// Testing SamanTel (سامان‌تل - 09999 / 09996)
echo "\n   Testing SamanTel (سامان‌تل) Series:\n";
assert_true(clean_mobile('09999876543') === '09999876543', "SamanTel 09999876543 valid");
assert_true(clean_mobile('+989999876543') === '09999876543', "SamanTel +989999876543 converted to 09999876543");
assert_true(clean_mobile('۰۹۹۹۹۸۷۶۵۴۳') === '09999876543', "SamanTel Persian digits normalized");
assert_true(valid_mobile('09999876543'), "valid_mobile accepts SamanTel 09999876543");
assert_true(valid_mobile('09996123456'), "valid_mobile accepts SamanTel 09996123456");
$opSaman = fr_mobile_operator('09999876543');
assert_true($opSaman['valid'] && $opSaman['slug'] === 'samantel', "fr_mobile_operator identifies SamanTel (got {$opSaman['name']})");

// Testing Irancell (ایرانسل - 0935 / 0902 / 0941)
echo "\n   Testing Irancell (ایرانسل) Series:\n";
assert_true(valid_mobile('09351234567'), "valid_mobile accepts Irancell 09351234567");
assert_true(valid_mobile('09021234567'), "valid_mobile accepts Irancell 09021234567");
assert_true(valid_mobile('09411234567'), "valid_mobile accepts Irancell 09411234567");
$opIrancell = fr_mobile_operator('09351234567');
assert_true($opIrancell['valid'] && $opIrancell['slug'] === 'irancell', "fr_mobile_operator identifies Irancell (got {$opIrancell['name']})");

// Testing Rightel (رایتل - 0921)
echo "\n   Testing Rightel (رایتل) Series:\n";
assert_true(valid_mobile('09211234567'), "valid_mobile accepts Rightel 09211234567");
assert_true(valid_mobile('09221234567'), "valid_mobile accepts Rightel 09221234567");
$opRightel = fr_mobile_operator('09211234567');
assert_true($opRightel['valid'] && $opRightel['slug'] === 'rightel', "fr_mobile_operator identifies Rightel (got {$opRightel['name']})");

// Testing Shatel Mobile (شاتل موبایل - 0998)
echo "\n   Testing Shatel Mobile (شاتل موبایل) Series:\n";
assert_true(valid_mobile('09981012345'), "valid_mobile accepts Shatel Mobile 09981012345");
$opShatel = fr_mobile_operator('09981012345');
assert_true($opShatel['valid'] && $opShatel['slug'] === 'shatel', "fr_mobile_operator identifies Shatel Mobile (got {$opShatel['name']})");

// Testing MCI Anarestan (انارستان - 0994)
echo "\n   Testing MCI Anarestan (انارستان) Series:\n";
assert_true(valid_mobile('09941234567'), "valid_mobile accepts Anarestan 09941234567");
$opAnar = fr_mobile_operator('09941234567');
assert_true($opAnar['valid'] && $opAnar['slug'] === 'mci' && str_contains($opAnar['name'], 'انارستان'), "fr_mobile_operator identifies Anarestan (got {$opAnar['name']})");

// Testing Other MVNOs & Carriers (LotusTel, Taliya, TeleKish, MTCE)
echo "\n   Testing Other Operators (LotusTel, Taliya, TeleKish, MTCE):\n";
assert_true(valid_mobile('09990123456'), "valid_mobile accepts LotusTel 09990123456");
assert_true(valid_mobile('09321234567'), "valid_mobile accepts Taliya 09321234567");
assert_true(valid_mobile('09341234567'), "valid_mobile accepts TeleKish 09341234567");
assert_true(valid_mobile('09311234567'), "valid_mobile accepts MTCE 09311234567");
assert_true(fr_mobile_operator('09321234567')['slug'] === 'taliya', "fr_mobile_operator identifies Taliya");
assert_true(fr_mobile_operator('09990123456')['slug'] === 'lotustel', "fr_mobile_operator identifies LotusTel");

// -------------------------------------------------------------
// 4. Kavenegar Gateway Defaults & Error Handling
// -------------------------------------------------------------
echo "\n4. Testing Kavenegar Gateway Methods:\n";
$kv = new Kavenegar();
$defs = Kavenegar::getDefaultTemplates();
assert_true(isset($defs['otp']) && str_contains($defs['otp']['body'], '%code%'), "getDefaultTemplates contains OTP with %code%");
assert_true(isset($defs['register']) && str_contains($defs['register']['body'], '%name%'), "getDefaultTemplates contains register with %name%");

// Delivery statuses
assert_true(Kavenegar::isDelivered(4), "delivery code 4 is marked delivered");
assert_true(Kavenegar::isFailedFinal(5), "delivery code 5 is marked final failure");
assert_true(str_contains(Kavenegar::deliveryLabel(4), 'تحویل'), "deliveryLabel(4) returns Persian confirmation");

// Disabled gateway behavior
DB::setSetting('kavenegar_enabled', '0');
$kvOff = new Kavenegar();
assert_true(!$kvOff->isEnabled(), "Kavenegar is disabled when setting kavenegar_enabled=0");

$testMob = '09198765432';
$resOff = $kvOff->sendDetailed($testMob, 'تست غیرفعال');
assert_true($resOff[0] === false, "sendDetailed returns false when gateway disabled");

$loggedOff = DB::one("SELECT * FROM " . DB::t('sms_logs') . " WHERE mobile=? AND status='disabled' ORDER BY id DESC LIMIT 1", [$testMob]);
assert_true(!empty($loggedOff), "Log row recorded with status='disabled'");

// -------------------------------------------------------------
// 5. OTP Generation, Delivery, and Recovery
// -------------------------------------------------------------
echo "\n5. Testing OTP Generation & Fallback Pipeline:\n";
// Clear rate limit bucket for test isolation
DB::run("DELETE FROM " . DB::t('rate_limits') . " WHERE bucket LIKE 'otp%'");

// Gateway disabled -> test fallback code must be returned
DB::setSetting('kavenegar_enabled', '0');
DB::setSetting('otp_length', '5');
DB::setSetting('otp_ttl', '120');

$userMob = '09351112233';
$otpResp = otp_send($userMob, 'login');

assert_true($otpResp['ok'] === true, "otp_send returns ok=true");
assert_true(!empty($otpResp['debug_code']), "otp_send returns debug_code when gateway not active");
assert_true(strlen($otpResp['debug_code']) === 5, "debug_code has exact configured length 5");
assert_true($otpResp['ttl'] === 120, "ttl matches setting 120");

// Check database OTP record
$otpRow = DB::one("SELECT * FROM " . DB::t('otps') . " WHERE mobile=? AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$userMob]);
assert_true(!empty($otpRow), "Database contains active OTP row");
$match = (!empty($otpRow['code_hash']) ? password_verify($otpResp['debug_code'], (string)$otpRow['code_hash']) : (($otpRow['code'] ?? '') === $otpResp['debug_code']));
assert_true($match, "Stored code or hash matches generated debug code");

// Wrong code verification
$failVerify = otp_verify($userMob, '00000', 'login');
assert_true($failVerify['ok'] === false, "Wrong code verification fails");

$updatedOtp = DB::one("SELECT * FROM " . DB::t('otps') . " WHERE id=?", [$otpRow['id']]);
assert_true((int)$updatedOtp['tries'] === 1, "Wrong code increments tries count to 1");

// Correct code verification
$succVerify = otp_verify($userMob, $otpResp['debug_code'], 'login');
assert_true($succVerify['ok'] === true, "Correct code verification succeeds");

$usedOtp = DB::one("SELECT * FROM " . DB::t('otps') . " WHERE id=?", [$otpRow['id']]);
assert_true(!empty($usedOtp['used_at']), "Verified code is invalidated with used_at timestamp");

// Re-verification with used code must fail
$reVerify = otp_verify($userMob, $otpResp['debug_code'], 'login');
assert_true($reVerify['ok'] === false, "Re-verifying used code fails");

// Invalidation of previous codes on new send
$newSend1 = otp_send($userMob, 'login');
$newSend2 = otp_send($userMob, 'login');
assert_true($newSend1['debug_code'] !== $newSend2['debug_code'], "Subsequent OTP sends generate unique codes");

$oldCodes = DB::all("SELECT * FROM " . DB::t('otps') . " WHERE mobile=? AND used_at IS NULL", [$userMob]);
assert_true(count($oldCodes) === 1, "Only the latest OTP code remains active/unused");

// Cross-Operator OTP verification tests
echo "\n   Testing OTP Delivery & Verification Across Different Operators:\n";
$opTestNumbers = [
    '09991001122' => 'Aptel (آپتل)',
    '09999112233' => 'SamanTel (سامان‌تل)',
    '09981001234' => 'Shatel Mobile (شاتل موبایل)',
    '09123456789' => 'MCI (همراه اول)',
    '09351234567' => 'Irancell (ایرانسل)',
    '09211234567' => 'Rightel (رایتل)',
];

foreach ($opTestNumbers as $opMob => $opTitle) {
    DB::run("DELETE FROM " . DB::t('rate_limits') . " WHERE bucket LIKE 'otp:%'");
    $res = otp_send($opMob, 'login');
    assert_true($res['ok'] === true, "otp_send succeeded for {$opTitle} ({$opMob})");
    assert_true(!empty($res['debug_code']), "debug_code received for {$opTitle}");

    $vRes = otp_verify($opMob, $res['debug_code'], 'login');
    assert_true($vRes['ok'] === true, "otp_verify successfully authenticated {$opTitle}");
}

// -------------------------------------------------------------
// 6. Template Saving & Customization
// -------------------------------------------------------------
echo "\n6. Testing Template Modification & Custom Pattern Code:\n";
DB::update('sms_templates', [
    'pattern_code' => 'feria_custom_otp',
    'use_pattern'  => 1,
    'body'         => 'کد ورود شما: %code%',
], "event='otp'");

$savedTpl = DB::one("SELECT * FROM " . DB::t('sms_templates') . " WHERE event='otp'");
assert_true($savedTpl['pattern_code'] === 'feria_custom_otp', "Custom pattern_code persisted in database");
assert_true((int)$savedTpl['use_pattern'] === 1, "Custom use_pattern persisted in database");
assert_true($savedTpl['body'] === 'کد ورود شما: %code%', "Custom body persisted in database");

// Revert back to standard default
DB::update('sms_templates', [
    'pattern_code' => 'otp',
    'use_pattern'  => 1,
    'body'         => 'کد تأیید ورود شما به %site%: %code%',
], "event='otp'");

// -------------------------------------------------------------
// 7. Settings tab check
// -------------------------------------------------------------
echo "\n7. Testing Kavenegar Settings Storage:\n";
DB::setSetting('kavenegar_otp_pattern', 'verify_user');
assert_true(DB::setting('kavenegar_otp_pattern') === 'verify_user', "kavenegar_otp_pattern setting stored and retrieved");
DB::setSetting('kavenegar_otp_pattern', 'otp');

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n=============================================================\n";
echo "QA SUITE SUMMARY: {$pass} PASSED, {$fail} FAILED\n";
echo "=============================================================\n";

if ($fail > 0) {
    exit(1);
}
