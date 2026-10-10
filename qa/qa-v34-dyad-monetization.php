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
require_once FR_ROOT . '/includes/commerce.php';
require_once FR_ROOT . '/includes/wallet.php';
require_once FR_ROOT . '/core/DyadAnalyzer.php';
require_once FR_ROOT . '/core/Analyzer.php';

echo "=== FERIA RAVAN COUPLES & DYAD MONETIZATION QA SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assert_true(bool $cond, string $msg): void {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failCount++;
    }
}

function mock_disc_results(int $attId, array $scores): void {
    foreach ($scores as $dim => $pct) {
        DB::insert('results', [
            'attempt_id' => $attId,
            'scale_code' => $dim,
            'raw_score'  => $pct,
            'percent'    => $pct,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

// -------------------------------------------------------------
// Test 1: Mobile Phone Normalization (clean_mobile & valid_mobile)
// -------------------------------------------------------------
echo "1. Testing Mobile Phone Normalization:\n";
assert_true(clean_mobile('۰۹۱۲۳۴۵۶۷۸۹') === '09123456789', "Persian digits normalized");
assert_true(clean_mobile('+989123456789') === '09123456789', "+98 normalized");
assert_true(clean_mobile('00989123456789') === '09123456789', "0098 normalized");
assert_true(clean_mobile('989123456789') === '09123456789', "98 prefix normalized");
assert_true(clean_mobile(' 0912 345 6789 ') === '09123456789', "Spaces stripped");
assert_true(clean_mobile('0912-345-6789') === '09123456789', "Hyphens stripped");
assert_true(valid_mobile('۰۹۱۲۳۴۵۶۷۸۹') === true, "valid_mobile handles Persian digits");
assert_true(valid_mobile('+98 912 345 6789') === true, "valid_mobile handles formatted international number");

// Create or fetch sample test and sample users
$test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE status='active' AND engine='disc' LIMIT 1");
if (!$test) {
    $testId = DB::insert('tests', [
        'title' => 'تست رفتارشناسی دیسک (DISC)',
        'slug' => 'dyad-disc-qa-' . time(),
        'status' => 'active',
        'price_full' => 85000,
        'free_enabled' => 1,
        'question_count' => 28,
        'est_minutes' => 10,
        'engine' => 'disc'
    ]);
    $test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE id=?", [$testId]);
}

// Create User 1 & User 2
$u1Id = (int)DB::insert('users', [
    'mobile' => '0912' . rand(1000000, 9999999),
    'first_name' => 'علیرضا',
    'last_name' => 'مقدم',
    'status' => 'active'
]);

$u2Id = (int)DB::insert('users', [
    'mobile' => '0919' . rand(1000000, 9999999),
    'first_name' => 'مریم',
    'last_name' => 'رستمی',
    'status' => 'active'
]);

// User 1 completed attempt
$att1Id = (int)DB::insert('attempts', [
    'user_id' => $u1Id,
    'test_id' => $test['id'],
    'mode' => 'full',
    'status' => 'completed',
    'completed_at' => date('Y-m-d H:i:s'),
]);
mock_disc_results($att1Id, ['D' => 80, 'I' => 65, 'S' => 40, 'C' => 50]);

// -------------------------------------------------------------
// Test 2: Mode A (Free Dyadic Assessment)
// -------------------------------------------------------------
echo "\n2. Testing Mode A: Free Dyadic Assessment:\n";
$resFree = DyadAnalyzer::createDyad($u1Id, $att1Id, 'couple', 'مریم رستمی', '۰۹۱۹۱۲۳۴۵۶۷', 'free');
assert_true($resFree['ok'] === true, "Free Dyad created successfully");
assert_true($resFree['pay_mode'] === 'free', "Dyad pay_mode is free");
assert_true($resFree['target_mode'] === 'free', "Dyad target_mode is free");
assert_true((int)$resFree['partner_paid'] === 1, "Dyad partner_paid is 1 (no payment needed)");

$dyadFreeRow = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$resFree['dyad_id']]);
assert_true($dyadFreeRow['status'] === 'pending', "Dyad is pending before partner joins");

// User 2 joins and completes free attempt
$att2FreeId = (int)DB::insert('attempts', [
    'user_id' => $u2Id,
    'test_id' => $test['id'],
    'mode' => 'free',
    'status' => 'completed',
    'completed_at' => date('Y-m-d H:i:s'),
]);
mock_disc_results($att2FreeId, ['D' => 50, 'I' => 75, 'S' => 70, 'C' => 45]);

$joinFree = DyadAnalyzer::joinDyad($dyadFreeRow['token'], $u2Id, $att2FreeId);
assert_true($joinFree['ok'] === true, "User 2 successfully joined Free Dyad");
assert_true($joinFree['status'] === 'ready', "Free Dyad status became ready");

$dyadFreeUpdated = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$resFree['dyad_id']]);
assert_true($dyadFreeUpdated['status'] === 'ready', "DB dyad status is ready");
assert_true((int)$dyadFreeUpdated['user2_id'] === $u2Id, "user2_id recorded correctly");
assert_true((int)$dyadFreeUpdated['attempt2_id'] === $att2FreeId, "attempt2_id recorded correctly");
assert_true((int)$dyadFreeUpdated['synergy_score'] > 0, "Synergy score computed: " . $dyadFreeUpdated['synergy_score'] . "%");

// -------------------------------------------------------------
// Test 3: Mode B (Self-Paid / Prepaid Gift for Partner)
// -------------------------------------------------------------
echo "\n3. Testing Mode B: Self-Paid / Gift Dyadic Assessment:\n";
// User 1 creates Dyad with order
$orderId = (int)DB::insert('orders', [
    'user_id' => $u1Id,
    'test_id' => $test['id'],
    'kind' => 'test_full',
    'amount' => 85000,
    'payable' => 85000,
    'status' => 'pending'
]);

$resGift = DyadAnalyzer::createDyad($u1Id, $att1Id, 'spouse', 'مریم هدیه', '09198765432', 'self_paid', $orderId);
assert_true($resGift['ok'] === true, "Gift Dyad created successfully");
assert_true($resGift['pay_mode'] === 'self_paid', "Dyad pay_mode is self_paid");
assert_true($resGift['target_mode'] === 'full', "Dyad target_mode is full");
assert_true((int)$resGift['partner_paid'] === 0, "partner_paid is 0 before payment");

// Now User 1 pays the order
mark_order_paid($orderId, 'TEST-REF-' . time(), null);

$dyadGiftPaid = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$resGift['dyad_id']]);
assert_true((int)$dyadGiftPaid['partner_paid'] === 1, "partner_paid automatically became 1 upon mark_order_paid");

// Partner joins without having completed attempt yet
$uGiftUser = (int)DB::insert('users', [
    'mobile' => '0919' . rand(1000000, 9999999),
    'first_name' => 'نرگس',
    'last_name' => 'صادقی',
    'status' => 'active'
]);

$joinNeedsTest = DyadAnalyzer::joinDyad($dyadGiftPaid['token'], $uGiftUser, null);
assert_true($joinNeedsTest['status'] === 'needs_test', "joinDyad correctly reported needs_test for new partner");

// Partner starts gifted full attempt
$attGift = (int)DB::insert('attempts', [
    'user_id' => $uGiftUser,
    'test_id' => $test['id'],
    'mode' => 'full',
    'order_id' => $orderId,
    'status' => 'completed',
    'completed_at' => date('Y-m-d H:i:s'),
]);
mock_disc_results($attGift, ['D' => 60, 'I' => 70, 'S' => 60, 'C' => 50]);

$joinGiftReady = DyadAnalyzer::joinDyad($dyadGiftPaid['token'], $uGiftUser, $attGift);
assert_true($joinGiftReady['status'] === 'ready', "Gift Dyad became ready");

$dyadGiftUpdated = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$resGift['dyad_id']]);
assert_true($dyadGiftUpdated['status'] === 'ready', "DB dyad status is ready");
assert_true((int)$dyadGiftUpdated['attempt2_id'] === $attGift, "attempt2_id linked to gifted full attempt");
assert_true((int)$dyadGiftUpdated['synergy_score'] > 0, "Synergy score computed: " . $dyadGiftUpdated['synergy_score'] . "%");

// -------------------------------------------------------------
// Test 4: Mode C (Partner-Paid Dyadic Assessment)
// -------------------------------------------------------------
echo "\n4. Testing Mode C: Partner-Paid Dyadic Assessment:\n";
$resPartnerPaid = DyadAnalyzer::createDyad($u1Id, $att1Id, 'business', 'شریک تجاری', '09191112233', 'partner_paid', null);
assert_true($resPartnerPaid['ok'] === true, "Partner-Paid Dyad created successfully");
assert_true($resPartnerPaid['pay_mode'] === 'partner_paid', "Dyad pay_mode is partner_paid");
assert_true($resPartnerPaid['target_mode'] === 'full', "Dyad target_mode is full");
assert_true((int)$resPartnerPaid['partner_paid'] === 0, "partner_paid is 0 (partner pays)");

// User 3 (new user) opens partner_paid dyad
$u3Id = (int)DB::insert('users', [
    'mobile' => '0919' . rand(1000000, 9999999),
    'first_name' => 'سهراب',
    'last_name' => 'سپهری',
    'status' => 'active'
]);

$joinC = DyadAnalyzer::joinDyad($resPartnerPaid['token'], $u3Id, null);
assert_true($joinC['status'] === 'needs_test', "User 3 is told needs_test");
assert_true(strpos($joinC['redirect_url'], 'test-start.php') !== false, "User 3 redirected to test-start");

// User 3 purchases full test
$order3 = (int)DB::insert('orders', [
    'user_id' => $u3Id,
    'test_id' => $test['id'],
    'kind' => 'test_full',
    'amount' => 85000,
    'payable' => 85000,
    'status' => 'pending'
]);
mark_order_paid($order3, 'REF-U3-' . time(), null);

$att3 = (int)DB::insert('attempts', [
    'user_id' => $u3Id,
    'test_id' => $test['id'],
    'mode' => 'full',
    'order_id' => $order3,
    'status' => 'completed',
    'completed_at' => date('Y-m-d H:i:s'),
]);
mock_disc_results($att3, ['D' => 45, 'I' => 55, 'S' => 75, 'C' => 60]);

$joinCFull = DyadAnalyzer::joinDyad($resPartnerPaid['token'], $u3Id, $att3);
assert_true($joinCFull['status'] === 'ready', "Partner-paid Dyad became ready once full test was completed");

$dyadCUpdated = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$resPartnerPaid['dyad_id']]);
assert_true($dyadCUpdated['status'] === 'ready', "DB partner_paid dyad status is ready");
assert_true((int)$dyadCUpdated['synergy_score'] > 0, "Partner-paid dyad synergy score: " . $dyadCUpdated['synergy_score'] . "%");

// -------------------------------------------------------------
// Test 5: Self-Click / Owner Re-Opening Dyad
// -------------------------------------------------------------
echo "\n5. Testing Owner Click / Waiting Partner:\n";
$ownerPendingDyad = DyadAnalyzer::createDyad($u1Id, $att1Id, 'friend', 'دوست جدید', '09199990000', 'free');
$ownerClick = DyadAnalyzer::joinDyad($ownerPendingDyad['token'], $u1Id, null);
assert_true($ownerClick['status'] === 'waiting_partner', "Owner gets waiting_partner when partner has not joined");

echo "\n============================================\n";
echo "SUMMARY: Passed: {$passCount}, Failed: {$failCount}\n";
if ($failCount === 0) {
    echo "🎉 ALL TESTS PASSED WITH 100% SUCCESS!\n";
} else {
    echo "❌ SOME TESTS FAILED!\n";
}
echo "============================================\n";

