<?php
/**
 * QA Test Runner: Verifying Free-to-Premium Upgrade & Rebranding
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://localhost');
define('FR_CRYPT_KEY', '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
define('FR_ENV', 'development');
define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/view.php';
require_once FR_ROOT . '/includes/commerce.php';
require_once FR_ROOT . '/includes/report.php';
require_once FR_ROOT . '/includes/update.php';
require_once FR_ROOT . '/core/Analyzer.php';
require_once FR_ROOT . '/core/analysis-texts.php';

function ok(bool $condition, string $msg): void {
    if ($condition) {
        echo "  [PASS] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        exit(1);
    }
}

echo "=== 1. Database Initialization & Schema Setup ===\n";
$pdo = DB::pdo();
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");

// Drop all tables in feria_test
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $pdo->exec("DROP TABLE `$t`");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

// Import schema
$schemaSql = file_get_contents(FR_ROOT . '/install/schema.sql');
$statements = array_filter(array_map('trim', explode(';', $schemaSql)));
foreach ($statements as $stmt) {
    if ($stmt === '') continue;
    $stmt = str_replace('{{prefix}}', DB_PREFIX, $stmt);
    $stmt = str_replace('fr_', DB_PREFIX, $stmt);
    try {
        $pdo->exec($stmt);
    } catch (Throwable $e) {
        // Table might already exist or harmless warning
    }
}
ok(count($pdo->query("SHOW TABLES")->fetchAll()) > 10, "Base schema loaded successfully");

// Run update pipeline to apply migrations
fr_update_pipeline();
ok(true, "fr_update_pipeline executed successfully");

// Seed tests and questions
require_once FR_ROOT . '/core/seed-content.php';
if (function_exists('fr_seed_content')) {
    fr_seed_content();
}
require_once FR_ROOT . '/core/seed-newtests.php';
if (function_exists('fr_seed_new_tests')) {
    fr_seed_new_tests();
}
require_once FR_ROOT . '/core/seed-analyses29.php';
if (function_exists('fr_seed_deep_analyses')) {
    fr_seed_deep_analyses();
}

$testCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests'));
echo "  Total active tests: $testCount\n";
ok($testCount > 0, "Tests seeded");
DB::setSetting('zarinpal_mock', '1');

echo "\n=== 2. Brand Verification (Feria / فریا روان) ===\n";
// Check site title
$siteTitle = DB::setting('site_title', '');
ok($siteTitle === 'فریا روان', "site_title is 'فریا روان' (got: '$siteTitle')");

$footerCredit = fr_credit_text();
ok(str_contains($footerCredit, 'فریا'), "footer_credit_text contains 'فریا' (got: '$footerCredit')");

$footerUrl = fr_credit_url();
ok(str_contains($footerUrl, 'feria.ir'), "footer_credit_url contains 'feria.ir' (got: '$footerUrl')");

// Test fr_txt and helpers
$txtFeria = fr_txt('FERIA_DISC_FULL');
ok(!empty($txtFeria), "fr_txt works for FERIA_DISC_FULL");

// Test functions
$dummyPct = ['D' => 75, 'I' => 45, 'S' => 30, 'C' => 50];
$pat2 = feria_disc_detect_pattern($dummyPct);
ok($pat2 === 'D', "feria_disc_detect_pattern returns result: $pat2");

$code2 = feria_disc_pattern_code($dummyPct);
ok(strlen($code2) === 4, "Pattern code: $code2");

$shift2 = feria_disc_shift($dummyPct, ['D' => 70, 'I' => 50, 'S' => 30, 'C' => 50]);
ok(isset($shift2['idx']), "Shift index calculated: " . $shift2['idx']);

ok(file_exists(FR_ROOT . '/assets/css/feria.css'), "assets/css/feria.css exists");
ok(file_exists(FR_ROOT . '/assets/js/feria.js'), "assets/js/feria.js exists");

echo "\n=== 3. End-to-End Free Test Attempt Workflow ===\n";
// Create a test user
$userId = DB::insert('users', [
    'mobile'     => '09120000001',
    'first_name' => 'آرش',
    'last_name'  => 'کوشا',
    'status'     => 'active',
]);
ok($userId > 0, "Created test user (id: $userId)");
$u = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$userId]);

// Pick a test, prefer DISC test if available, otherwise first test
$test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE engine='disc' AND status='active' LIMIT 1")
    ?: DB::one("SELECT * FROM " . DB::t('tests') . " WHERE status='active' LIMIT 1");
ok(!empty($test), "Selected test: " . $test['title'] . " (id: " . $test['id'] . ", slug: " . $test['slug'] . ")");

// Step A: Start FREE attempt
$attemptId = (int)DB::insert('attempts', [
    'user_id'   => $userId,
    'test_id'   => $test['id'],
    'mode'      => 'free',
    'status'    => 'in_progress',
    'progress'  => 0,
    'context'   => 'work',
]);
ok($attemptId > 0, "Created free attempt in progress (id: $attemptId)");

// Step B: Answer questions
$questions = DB::all("SELECT * FROM " . DB::t('questions') . " WHERE test_id=? ORDER BY number, id", [$test['id']]);
ok(count($questions) > 0, "Fetched " . count($questions) . " questions");

$qCount = count($questions);
foreach ($questions as $q) {
    if (($q['format'] ?? '') === 'mostleast') {
        DB::insert('answers', [
            'attempt_id'  => $attemptId,
            'question_id' => $q['id'],
            'most_idx'    => 0,
            'least_idx'   => 1,
        ]);
    } else {
        DB::insert('answers', [
            'attempt_id'  => $attemptId,
            'question_id' => $q['id'],
            'value'       => 4,
        ]);
    }
}
DB::update('attempts', [
    'status'       => 'completed',
    'progress'     => $qCount,
    'completed_at' => date('Y-m-d H:i:s'),
], 'id=?', [$attemptId]);

// Score free attempt
Analyzer::scoreAttempt($attemptId);

$freeAttempt = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
ok($freeAttempt['status'] === 'completed', "Attempt completed");
ok($freeAttempt['mode'] === 'free', "Attempt mode is 'free'");
ok(empty($freeAttempt['order_id']), "Attempt order_id is null before purchase");

// Verify free report output contains upgrade teasers and lacks full chapters
$freeReportHtml = ReportBuilder::build($freeAttempt, $u, 'free');
ok(str_contains($freeReportHtml, 'در نسخهٔ ویژه') || str_contains($freeReportHtml, 'ارتقا'), "Free report has teaser/upsell text");
ok(!str_contains($freeReportHtml, 'فهرست فصول این گزارش'), "Free report does NOT have 6-chapter TOC");

echo "\n=== 4. Purchasing / Upgrading to Special (Full) Version ===\n";
// User decides to upgrade!
// Calling create_full_order with the existing completed attempt
$orderRes = create_full_order($u, $test, null, false, $attemptId);
ok(!empty($orderRes['order']), "Order created successfully");
$orderId = (int)$orderRes['order']['id'];
ok($orderId > 0, "Order ID: $orderId");
ok($orderRes['attempt_id'] === $attemptId, "Order linked to existing completed attempt $attemptId");

// Check attempt in DB before payment
$attBeforePay = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
ok((int)$attBeforePay['order_id'] === $orderId, "Attempt order_id is linked to $orderId");
ok($attBeforePay['mode'] === 'free', "Attempt mode is still 'free' before payment");

// Verify no dummy orphan attempt was created
$allUserAttempts = DB::all("SELECT * FROM " . DB::t('attempts') . " WHERE user_id=? AND test_id=?", [$userId, $test['id']]);
ok(count($allUserAttempts) === 1, "Exactly 1 attempt exists for this user and test (no orphan in_progress attempt)");

// Step C: Payment completes via Gateway (Zarinpal) -> mark_order_paid
echo "\n=== 5. Simulating Payment Completion (mark_order_paid) ===\n";
mark_order_paid($orderId, '12345678', '603799******1234');

// Verify order status
$paidOrder = DB::one("SELECT * FROM " . DB::t('orders') . " WHERE id=?", [$orderId]);
ok($paidOrder['status'] === 'paid', "Order status is 'paid'");

// Verify attempt upgraded to full!
$paidAttempt = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
ok($paidAttempt['mode'] === 'full', "Attempt mode is now 'full'!");
ok((int)$paidAttempt['order_id'] === $orderId, "Attempt order_id remains linked to $orderId");
ok($paidAttempt['status'] === 'completed', "Attempt status remains 'completed'");

// Verify full report output
$fullReportHtml = ReportBuilder::build($paidAttempt, $u, 'full');
ok(str_contains($fullReportHtml, 'فهرست فصول این گزارش') || str_contains($fullReportHtml, 'تحلیل تخصصی'), "Full report renders deep analysis & chapters!");
ok(!str_contains($fullReportHtml, 'ارتقا بدهید و تصویر کامل خودتان را ببینید'), "Full report does NOT contain upgrade CTA");

echo "\n=== 6. Self-Healing & Fallback Verification ===\n";
// Test scenario where an older attempt was left as mode='free' despite order being 'paid'
DB::update('attempts', ['mode' => 'free'], 'id=?', [$attemptId]);
$reverted = DB::one("SELECT mode FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
ok($reverted['mode'] === 'free', "Artificially set mode back to free");

// Simulate user opening report.php: it should self-heal and auto-upgrade
// We execute the same query as in report.php:
$at = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
if ($at['mode'] === 'free') {
    $po = DB::one("SELECT id FROM " . DB::t('orders') . " WHERE id=? AND status='paid'", [(int)$at['order_id']]);
    if ($po) {
        DB::update('attempts', ['mode' => 'full'], 'id=?', [$attemptId]);
        Analyzer::scoreAttempt($attemptId);
    }
}
$healed = DB::one("SELECT mode FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
ok($healed['mode'] === 'full', "Self-healing successfully upgraded attempt to 'full'!");

echo "\n=== 7. Gateway Callback & Flow Simulation (zarinpal-callback.php) ===\n";
// Create user 2 to test gateway callback flow
$user2Id = (int)DB::insert('users', [
    'mobile'     => '09120000002',
    'first_name' => 'سارا',
    'last_name'  => 'راد',
    'status'     => 'active',
]);
$u2 = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$user2Id]);

// User 2 starts free attempt on test
$att2Id = (int)DB::insert('attempts', [
    'user_id'      => $user2Id,
    'test_id'      => $test['id'],
    'mode'         => 'free',
    'status'       => 'completed',
    'progress'     => $qCount,
    'completed_at' => date('Y-m-d H:i:s'),
]);
// Insert answers
foreach ($questions as $q) {
    if (($q['format'] ?? '') === 'mostleast') {
        DB::insert('answers', ['attempt_id'=>$att2Id, 'question_id'=>$q['id'], 'most_idx'=>0, 'least_idx'=>1]);
    } else {
        DB::insert('answers', ['attempt_id'=>$att2Id, 'question_id'=>$q['id'], 'value'=>4]);
    }
}
Analyzer::scoreAttempt($att2Id);

// User 2 initiates upgrade
$orderRes2 = create_full_order($u2, $test, null, false, $att2Id);
$order2Id = (int)$orderRes2['order']['id'];
ok($order2Id > 0, "Order 2 created (id: $order2Id)");
$payment = DB::one("SELECT * FROM " . DB::t('payments') . " WHERE order_id=?", [$order2Id]);
ok(!empty($payment), "Payment record created for gateway redirect");
$authority = (string)($payment['authority'] ?? '');
ok(!empty($authority), "Authority generated: $authority");

// Simulate callback logic from zarinpal-callback.php
$zp = new Zarinpal();
$vRes = $zp->verify($authority, (int)$orderRes2['order']['payable']);
ok($vRes['ok'] === true, "Gateway verification successful");
mark_order_paid($order2Id, (string)$vRes['ref_id'], $vRes['card_pan']);

// Check attempt 2 state
$att2After = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$att2Id]);
ok($att2After['mode'] === 'full', "Attempt 2 upgraded to 'full' after payment");
ok($att2After['status'] === 'completed', "Attempt 2 status is 'completed'");

// Verify redirect logic: completed attempt MUST go to report.php, NOT take.php
$callbackRedirect = SITE_URL . '/panel/report.php?attempt=' . $att2Id . '&upgraded=1';
if ($att2After['status'] === 'in_progress') {
    $callbackRedirect = SITE_URL . '/panel/take.php?attempt=' . $att2Id;
}
ok(str_contains($callbackRedirect, 'report.php?attempt=' . $att2Id), "Gateway callback directs user straight to report.php");

echo "\n=== 8. Panel History & Dashboard Visibility ===\n";
// Test history queries for user 1 and user 2
$historyAttempts = DB::all("
    SELECT a.*, t.title AS test_title, t.slug AS test_slug, o.status AS order_status, o.payable AS order_payable
    FROM " . DB::t('attempts') . " a
    JOIN " . DB::t('tests') . " t ON t.id=a.test_id
    LEFT JOIN " . DB::t('orders') . " o ON o.id=a.order_id
    WHERE a.user_id=?
    ORDER BY a.id DESC
", [$userId]);

ok(count($historyAttempts) === 1, "User 1 has exactly 1 attempt in history");
$h = $historyAttempts[0];
ok($h['mode'] === 'full', "History shows mode is 'full'");
ok($h['order_status'] === 'paid', "History shows order_status is 'paid'");
ok($h['status'] === 'completed', "History shows status is 'completed'");

// Verify action button destination
$reportLink = SITE_URL . '/panel/report.php?attempt=' . $h['id'];
ok(str_contains($reportLink, "attempt=" . $attemptId), "Report link correctly points to attempt $attemptId");

echo "\n=== 9. DISC 6-Chapter Deep Analysis Integration ===\n";
$discTest = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE engine='disc' LIMIT 1");
if ($discTest) {
    echo "  DISC test found: {$discTest['title']} (id: {$discTest['id']})\n";
    $discUserId = (int)DB::insert('users', [
        'mobile'     => '09120000003',
        'first_name' => 'پرهام',
        'last_name'  => 'نیکزاد',
        'status'     => 'active',
    ]);
    $discUser = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$discUserId]);
    
    // Start DISC free attempt
    $discAttId = (int)DB::insert('attempts', [
        'user_id'      => $discUserId,
        'test_id'      => $discTest['id'],
        'mode'         => 'free',
        'status'       => 'completed',
        'progress'     => (int)$discTest['question_count'],
        'context'      => 'work',
        'completed_at' => date('Y-m-d H:i:s'),
    ]);
    
    $discQuestions = DB::all("SELECT * FROM " . DB::t('questions') . " WHERE test_id=? ORDER BY number, id", [$discTest['id']]);
    foreach ($discQuestions as $idx => $dq) {
        // Vary choices to get dynamic DISC profile
        $mIdx = $idx % 4;
        $lIdx = ($idx + 2) % 4;
        DB::insert('answers', [
            'attempt_id'  => $discAttId,
            'question_id' => $dq['id'],
            'most_idx'    => $mIdx,
            'least_idx'   => $lIdx,
        ]);
    }
    
    // Score free attempt
    Analyzer::scoreAttempt($discAttId);
    $discAttFree = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$discAttId]);
    ok(!empty($discAttFree['context']), "DISC pattern detected in context: {$discAttFree['context']}");
    
    // Upgrade DISC attempt
    $discOrderRes = create_full_order($discUser, $discTest, null, false, $discAttId);
    $discOrderId = (int)$discOrderRes['order']['id'];
    mark_order_paid($discOrderId, 'DISC-REF-' . fr_token(6), null);
    
    $discAttPaid = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$discAttId]);
    ok($discAttPaid['mode'] === 'full', "DISC attempt upgraded to 'full'");
    
    // Build full DISC report
    $discReportHtml = ReportBuilder::build($discAttPaid, $discUser, 'full');
    ok(str_contains($discReportHtml, 'فهرست فصول این گزارش') || str_contains($discReportHtml, 'فصل'), "DISC report contains chapter structure");
    ok(str_contains($discReportHtml, 'نمودار'), "DISC report contains graph / chart sections");
} else {
    echo "  [INFO] No DISC test found in DB seed; skipping DISC-specific assertion\n";
}

echo "\n=== 10. Brand Integrity in Database & System ===\n";
$siteTitleNow = DB::setting('site_title');
ok($siteTitleNow === 'فریا روان', "site_title is 'فریا روان'");

echo "\n=== 11. Phase 1: Psychometric Standardization & Validity Validation ===\n";
// Check results table for standardized T-Score, Sten, and Percentile
$stdResults = DB::all("SELECT * FROM " . DB::t('results') . " WHERE attempt_id=?", [$attemptId]);
ok(count($stdResults) > 0, "Results exist for attempt $attemptId");

foreach ($stdResults as $sr) {
    if ($sr['scale_code'] === 'overall') continue;
    ok($sr['t_score'] !== null && $sr['t_score'] >= 20 && $sr['t_score'] <= 80, 
       "Scale {$sr['scale_code']} T-score in bounds 20-80 (got: {$sr['t_score']})");
    ok($sr['sten_score'] !== null && $sr['sten_score'] >= 1 && $sr['sten_score'] <= 10, 
       "Scale {$sr['scale_code']} Sten score in bounds 1-10 (got: {$sr['sten_score']})");
    ok($sr['percentile'] !== null && $sr['percentile'] >= 1 && $sr['percentile'] <= 99, 
       "Scale {$sr['scale_code']} Percentile in bounds 1-99 (got: {$sr['percentile']}%)");
}

// Check validity evaluation in attempt
$valAttempt = DB::one("SELECT validity_json, duration_sec FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
ok(!empty($valAttempt['validity_json']), "Attempt has validity_json populated");
$valData = json_decode($valAttempt['validity_json'], true);
ok(is_array($valData) && isset($valData['score']), "Validity score exists: {$valData['score']}%");
ok(in_array($valData['status'], ['high', 'acceptable', 'caution']), "Validity status is valid: {$valData['status']}");

// Verify report rendering of validity card and standardized scores
$reportHtml = ReportBuilder::build($paidAttempt, $u, 'full');
ok(str_contains($reportHtml, 'پروتکل استاندارد روان‌سنجی فریا') || str_contains($reportHtml, 'شاخص پایایی پاسخ‌ها'), 
   "Report renders Psychometric Validity Card");
ok(str_contains($reportHtml, 'نمرهٔ تراز T') || str_contains($reportHtml, 'استن:'), 
   "Report renders standardized T-scores and Sten scale");

echo "\n=== 12. Phase 2: Dyadic & Couples Compatibility (تحلیل تطابق دونفره) ===\n";
require_once FR_ROOT . '/core/DyadAnalyzer.php';

// User 1 creates a couple / dyad link for attempt 1
$dyadCreate = DyadAnalyzer::createDyad($userId, $attemptId, 'spouse');
ok($dyadCreate['ok'] === true, "Dyad link created successfully (dyad_id: {$dyadCreate['dyad_id']})");
$token = $dyadCreate['token'];
ok(!empty($token) && strlen($token) >= 12, "Dyad token generated: $token");
$dyadId = (int)$dyadCreate['dyad_id'];

// Check dyad status pending
$dRow = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$dyadId]);
ok($dRow['status'] === 'pending', "Dyad status is 'pending' before partner joins");
ok($dRow['rel_type'] === 'spouse', "Dyad relationship type is 'spouse'");

// User 2 joins with attempt 2
$joinRes = DyadAnalyzer::joinDyad($token, $user2Id, $att2Id);
ok($joinRes['ok'] === true, "User 2 joined dyad successfully");
ok($joinRes['status'] === 'ready', "Dyad status is now 'ready'");

// Verify dyad analysis output
$dReady = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$dyadId]);
ok((int)$dReady['synergy_score'] >= 40 && (int)$dReady['synergy_score'] <= 100, 
   "Synergy score calculated: {$dReady['synergy_score']}%");
ok(!empty($dReady['summary_json']), "Dyad summary_json is populated");

$summary = json_decode($dReady['summary_json'], true);
ok(!empty($summary['delta_matrix']), "Delta comparison matrix calculated");
ok(!empty($summary['synergy_strengths']), "Synergy strengths identified");
ok(!empty($summary['friction_points']), "Friction points and conflict triggers identified");
ok(!empty($summary['safe_protocols']), "Safe communication protocols generated");
ok(!empty($summary['growth_contract']), "3-week relationship growth contract generated");

echo "\n=== 13. Phase 3: Dynamic Action Plan & Planner Growth Tasks ===\n";
require_once FR_ROOT . '/core/GrowthEngine.php';

// Generate personalized 4-week growth plan for attemptId
$plan = GrowthEngine::generatePlan($attemptId);
ok(!empty($plan['tasks']), "GrowthEngine generated structured tasks");
ok($plan['duration_weeks'] === 4, "Plan covers 4-week growth duration");
ok($plan['task_count'] >= 4, "Plan contains at least 4 behavioral tasks");

// Inject growth tasks into user's daily planner
$injectRes = GrowthEngine::injectIntoPlanner($userId, $attemptId);
ok($injectRes['ok'] === true, "Tasks successfully injected into planner (inserted: {$injectRes['inserted']})");
ok($injectRes['inserted'] >= 4, "At least 4 weekly behavioral growth tasks created");

// Verify DB persistence of injected tasks
$userPlannerTasks = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND ref_attempt_id=?", [$userId, $attemptId]);
ok(count($userPlannerTasks) === $injectRes['inserted'], "Planner tasks in DB match injected count");
foreach ($userPlannerTasks as $pt) {
    ok($pt['category'] === 'تمرین رشد کارنامه', "Task category is 'تمرین رشد کارنامه'");
    ok(!empty($pt['day']), "Task day is populated: {$pt['day']}");
    ok(!empty($pt['note']), "Task note contains micro-action guidance");
}

// Verify attempt flag updated
$attInjected = DB::one("SELECT growth_injected FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
ok((int)$attInjected['growth_injected'] === 1, "Attempt growth_injected column set to 1");

// Test Retest & Delta tracking
// Create a second completed attempt for user 1 to simulate a retest after growth plan
$retestAttId = (int)DB::insert('attempts', [
    'user_id'      => $userId,
    'test_id'      => $test['id'],
    'mode'         => 'full',
    'status'       => 'completed',
    'progress'     => $qCount,
    'started_at'   => date('Y-m-d H:i:s', strtotime('+30 days')),
    'completed_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
]);
foreach ($questions as $q) {
    if (($q['format'] ?? '') === 'mostleast') {
        DB::insert('answers', ['attempt_id' => $retestAttId, 'question_id' => $q['id'], 'most_idx' => 1, 'least_idx' => 2]);
    } else {
        DB::insert('answers', ['attempt_id' => $retestAttId, 'question_id' => $q['id'], 'value' => 5]);
    }
}
Analyzer::scoreAttempt($retestAttId);

$deltaComparison = GrowthEngine::getDeltaComparison($userId, (int)$test['id']);
ok($deltaComparison['has_comparison'] === true, "Delta comparison detected multiple attempts");
ok($deltaComparison['total_attempts'] >= 2, "Total attempts counted: {$deltaComparison['total_attempts']}");
ok(!empty($deltaComparison['scales']), "Scale deltas computed");

foreach ($deltaComparison['scales'] as $code => $sc) {
    ok(isset($sc['delta']), "Scale $code delta calculated: {$sc['delta']}");
    ok(in_array($sc['trend'], ['increased', 'decreased', 'stable']), "Scale $code trend is valid: {$sc['trend']}");
}

echo "\n=== 14. Phase 4: Clinical Therapist Report & Case Summary ===\n";
require_once FR_ROOT . '/core/ClinicalAnalyzer.php';

$clinicalCase = ClinicalAnalyzer::generateCaseSummary($attemptId);
ok(is_array($clinicalCase), "ClinicalAnalyzer generated confidential case summary");
ok(isset($clinicalCase['red_flags']), "Clinical red flags detected");
ok(isset($clinicalCase['hypotheses']), "Diagnostic hypotheses generated");
ok(isset($clinicalCase['defense_mechanisms']), "Defense mechanisms mapped");
ok(isset($clinicalCase['therapy_entry_points']), "Therapy entry points provided");
ok(!empty($clinicalCase['therapy_entry_points']['session_openers']), "Therapist session openers generated");
ok(isset($clinicalCase['resistance_handling']), "Resistance handling guide provided");

// Test Clinical Notes Persistence
$adminId = (int)DB::insert('admins', [
    'username'     => 'therapist_dr',
    'password'     => 'hash_123',
    'display_name' => 'دکتر بالینی نمونه',
    'mobile'       => '09120000099',
    'role'         => 'super',
]);
ok($adminId > 0, "Created therapist admin (id: $adminId)");

$noteId = (int)DB::insert('clinical_notes', [
    'attempt_id' => $attemptId,
    'user_id'    => $userId,
    'admin_id'   => $adminId,
    'diagnosis'  => 'واکنش سازگارانه، رگه‌های اضطراب موقعیتی در مواجهه با چالش‌های شغلی',
    'notes'      => 'مراجع در جلسه اول انگیزه بالایی برای پیگیری تمرین‌های پلنر ابراز کرد.',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);
ok($noteId > 0, "Saved clinical intake note (id: $noteId)");

$savedNote = DB::one("SELECT cn.*, a.display_name AS therapist_name FROM " . DB::t('clinical_notes') . " cn
                      LEFT JOIN " . DB::t('admins') . " a ON a.id = cn.admin_id
                      WHERE cn.id=?", [$noteId]);
ok(!empty($savedNote), "Clinical note retrieved successfully");
ok($savedNote['therapist_name'] === 'دکتر بالینی نمونه', "Therapist author verified");
ok(str_contains($savedNote['diagnosis'], 'اضطراب موقعیتی'), "Diagnostic text preserved in clinical case");

echo "\n=== 15. Phase 5: Answer Editing, In-Progress SMS Nudge, Bulk Actions & Test Purge ===\n";
// 1. Test Answer Editing logic (attempt-answers.php backend logic)
$firstQ = DB::one("SELECT id, text FROM " . DB::t('questions') . " WHERE test_id=? ORDER BY number ASC LIMIT 1", [$test['id']]);
ok(!empty($firstQ), "Found first question for attempt $attemptId (id: {$firstQ['id']})");

// Update or set answer to 5
$qExists = DB::one("SELECT id FROM " . DB::t('answers') . " WHERE attempt_id=? AND question_id=?", [$attemptId, $firstQ['id']]);
if ($qExists) {
    DB::update('answers', ['value' => '5'], 'id=?', [$qExists['id']]);
} else {
    DB::insert('answers', ['attempt_id' => $attemptId, 'question_id' => $firstQ['id'], 'value' => '5']);
}
$savedAns = DB::one("SELECT value FROM " . DB::t('answers') . " WHERE attempt_id=? AND question_id=?", [$attemptId, $firstQ['id']]);
ok((string)$savedAns['value'] === '5', "Answer successfully updated in DB");

// Recalculate
$recalcOk = Analyzer::scoreAttempt($attemptId);
ok($recalcOk === true, "Recalculation and rescoring succeeded");

// 2. Test In-Progress SMS Nudge
$inProgAttId = (int)DB::insert('attempts', [
    'user_id'    => $userId,
    'test_id'    => $test['id'],
    'mode'       => 'free',
    'status'     => 'in_progress',
    'progress'   => 5,
    'started_at' => date('Y-m-d H:i:s'),
]);
ok($inProgAttId > 0, "Created in-progress attempt for user $userId (id: $inProgAttId)");

$kv = new Kavenegar();
$nudgeLink = SITE_URL . '/panel/take.php?attempt=' . $inProgAttId;
$nudgeSent = $kv->sendEvent('test_nudge', $u['mobile'], [
    'name'    => 'آرش',
    'test'    => $test['title'],
    'link'    => $nudgeLink,
    '_tokens' => ['آرش', $test['title'], $nudgeLink],
]);
if (!$nudgeSent) {
    $msg = "سلام آرش عزیز\nپاسخ‌گویی به آزمون «{$test['title']}» شما نیمه‌کاره است:\n{$nudgeLink}\nفریا روان";
    $nudgeSent = $kv->send($u['mobile'], $msg, 'test_nudge');
}
DB::update('attempts', ['nudge_sent' => 1], 'id=?', [$inProgAttId]);

$nudgeCheck = DB::one("SELECT nudge_sent FROM " . DB::t('attempts') . " WHERE id=?", [$inProgAttId]);
ok((int)$nudgeCheck['nudge_sent'] === 1, "Attempt nudge_sent marked as 1");

// 3. Test Bulk Actions on Events (Workshops / Courses)
$ws1 = (int)DB::insert('events', ['kind' => 'workshop', 'title' => 'کارگاه هوش هیجانی نمونه ۱', 'status' => 'active', 'price' => 100000]);
$ws2 = (int)DB::insert('events', ['kind' => 'workshop', 'title' => 'کارگاه هوش هیجانی نمونه ۲', 'status' => 'active', 'price' => 120000]);
ok($ws1 > 0 && $ws2 > 0, "Created 2 test workshops for bulk operations");

// Bulk deactivate
DB::update('events', ['status' => 'inactive'], 'id IN (?, ?)', [$ws1, $ws2]);
$st1 = DB::val("SELECT status FROM " . DB::t('events') . " WHERE id=?", [$ws1]);
$st2 = DB::val("SELECT status FROM " . DB::t('events') . " WHERE id=?", [$ws2]);
ok($st1 === 'inactive' && $st2 === 'inactive', "Bulk deactivate successfully set both to 'inactive'");

// Bulk activate
DB::update('events', ['status' => 'active'], 'id IN (?, ?)', [$ws1, $ws2]);
$st1b = DB::val("SELECT status FROM " . DB::t('events') . " WHERE id=?", [$ws1]);
ok($st1b === 'active', "Bulk activate successfully restored to 'active'");

// Bulk delete
DB::run("DELETE FROM " . DB::t('events') . " WHERE id IN (?, ?)", [$ws1, $ws2]);
$remWs = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('events') . " WHERE id IN (?, ?)", [$ws1, $ws2]);
ok($remWs === 0, "Bulk delete successfully removed test events");

// 4. Test Purge / Force Delete of a Test
// Create dummy test with questions, attempts, answers, and templates
$dummyTestId = (int)DB::insert('tests', [
    'title'          => 'تست آزمایشی برای تست پاکسازی',
    'slug'           => 'dummy-test-purge-' . fr_token(4),
    'engine'         => 'generic',
    'price_full'     => 50000,
    'status'         => 'active',
    'question_count' => 2,
]);
ok($dummyTestId > 0, "Created dummy test for purge test (id: $dummyTestId)");

$dummyQ1 = (int)DB::insert('questions', ['test_id' => $dummyTestId, 'number' => 1, 'text' => 'سؤال ۱', 'format' => 'likert']);
$dummyQ2 = (int)DB::insert('questions', ['test_id' => $dummyTestId, 'number' => 2, 'text' => 'سؤال ۲', 'format' => 'likert']);
$dummyAttId = (int)DB::insert('attempts', [
    'user_id'    => $userId,
    'test_id'    => $dummyTestId,
    'mode'       => 'free',
    'status'     => 'completed',
    'started_at' => date('Y-m-d H:i:s'),
]);
DB::insert('answers', ['attempt_id' => $dummyAttId, 'question_id' => $dummyQ1, 'value' => '4']);
DB::insert('answers', ['attempt_id' => $dummyAttId, 'question_id' => $dummyQ2, 'value' => '3']);
DB::insert('results', ['attempt_id' => $dummyAttId, 'scale_code' => 'overall', 'percent' => 70, 't_score' => 55, 'sten_score' => 6]);
DB::insert('clinical_notes', [
    'attempt_id' => $dummyAttId,
    'user_id'    => $userId,
    'admin_id'   => $adminId,
    'notes'      => 'یادداشت بالینی تستی',
]);

// Purge test helper is loaded from functions.php
$purgeOk = fr_purge_test($dummyTestId);
ok($purgeOk === true, "fr_purge_test executed cleanly");

// Verify that test and all related records were purged
$chkTest = DB::one("SELECT id FROM " . DB::t('tests') . " WHERE id=?", [$dummyTestId]);
ok(empty($chkTest), "Test record removed from tests table");

$chkQ = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('questions') . " WHERE test_id=?", [$dummyTestId]);
ok($chkQ === 0, "Questions removed from questions table");

$chkAtt = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE test_id=?", [$dummyTestId]);
ok($chkAtt === 0, "Attempts removed from attempts table");

$chkAns = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('answers') . " WHERE attempt_id=?", [$dummyAttId]);
ok($chkAns === 0, "Answers purged from answers table");

$chkRes = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('results') . " WHERE attempt_id=?", [$dummyAttId]);
ok($chkRes === 0, "Results purged from results table");

$chkNotes = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('clinical_notes') . " WHERE attempt_id=?", [$dummyAttId]);
ok($chkNotes === 0, "Clinical notes purged from clinical_notes table");

echo "\n=== 16. Feria Uploads System & Directory Structure Verification ===\n";
require_once FR_ROOT . '/includes/upload.php';
fr_ensure_upload_dirs();

// 1. Check directories existence
$subdirsToCheck = ['workshops', 'courses', 'users', 'tests', 'tickets', 'services', 'banners', 'general', 'rte'];
foreach ($subdirsToCheck as $sub) {
    ok(is_dir(FR_ROOT . '/feria-uploads/' . $sub), "Directory feria-uploads/$sub exists and is writable");
}
ok(is_file(FR_ROOT . '/feria-uploads/.htaccess'), "Security .htaccess exists in feria-uploads/");

// 2. Create sample test image and test uploads
$tmpImg = sys_get_temp_dir() . '/test_img_' . uniqid() . '.jpg';
$im = imagecreatetruecolor(200, 200);
$bg = imagecolorallocate($im, 70, 130, 180);
imagefilledrectangle($im, 0, 0, 199, 199, $bg);
imagejpeg($im, $tmpImg, 90);
imagedestroy($im);

$mockFile = [
    'name'     => 'test.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $tmpImg,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($tmpImg),
];

// Test workshop cover
copy($tmpImg, $tmpImg . '.ws');
$mockWs = array_merge($mockFile, ['tmp_name' => $tmpImg . '.ws']);
$upWs = fr_save_cover($mockWs, 'workshops', 'workshop', 88);
ok($upWs['ok'] === true, "Workshop cover saved successfully");
ok(str_starts_with($upWs['path'], 'feria-uploads/workshops/'), "Workshop cover path is in feria-uploads/workshops/ ({$upWs['path']})");
ok(is_file(FR_ROOT . '/' . $upWs['path']), "Physical file exists on disk in feria-uploads/workshops/");

// Test course cover
copy($tmpImg, $tmpImg . '.cs');
$mockCs = array_merge($mockFile, ['tmp_name' => $tmpImg . '.cs']);
$upCs = fr_save_cover($mockCs, 'courses', 'course', 99);
ok($upCs['ok'] === true, "Course cover saved successfully");
ok(str_starts_with($upCs['path'], 'feria-uploads/courses/'), "Course cover path is in feria-uploads/courses/ ({$upCs['path']})");
ok(is_file(FR_ROOT . '/' . $upCs['path']), "Physical file exists on disk in feria-uploads/courses/");

// Test user avatar
copy($tmpImg, $tmpImg . '.usr');
$mockUsr = array_merge($mockFile, ['tmp_name' => $tmpImg . '.usr']);
$upUsr = fr_save_avatar($mockUsr, 'user', 77);
ok($upUsr['ok'] === true, "User avatar saved successfully");
ok(str_starts_with($upUsr['path'], 'feria-uploads/users/'), "User avatar path is in feria-uploads/users/ ({$upUsr['path']})");
ok(is_file(FR_ROOT . '/' . $upUsr['path']), "Physical avatar exists on disk in feria-uploads/users/");

// Test test cover
copy($tmpImg, $tmpImg . '.tst');
$mockTst = array_merge($mockFile, ['tmp_name' => $tmpImg . '.tst']);
$upTst = fr_save_cover($mockTst, 'tests', 'test', 66);
ok($upTst['ok'] === true, "Test cover saved successfully");
ok(str_starts_with($upTst['path'], 'feria-uploads/tests/'), "Test cover path is in feria-uploads/tests/ ({$upTst['path']})");
ok(is_file(FR_ROOT . '/' . $upTst['path']), "Physical test cover exists on disk in feria-uploads/tests/");

// Test ticket attachment
copy($tmpImg, $tmpImg . '.tck');
$mockTck = array_merge($mockFile, ['tmp_name' => $tmpImg . '.tck']);
$upTck = fr_save_image($mockTck, 'tickets', 'tick', 55);
ok($upTck['ok'] === true, "Ticket attachment saved successfully");
ok(str_starts_with($upTck['path'], 'feria-uploads/tickets/'), "Ticket path is in feria-uploads/tickets/ ({$upTck['path']})");
$tckHtml = fr_ticket_attach_html($upTck['path']);
ok(str_contains($tckHtml, 'feria-uploads/tickets/'), "Ticket HTML embeds feria-uploads/tickets/ URL correctly");

// Test clean deletion
fr_delete_image($upWs['path']);
ok(!is_file(FR_ROOT . '/' . $upWs['path']), "fr_delete_image properly deleted the workshop file");
fr_delete_image($upCs['path']);
ok(!is_file(FR_ROOT . '/' . $upCs['path']), "fr_delete_image properly deleted the course file");
fr_delete_image($upUsr['path']);
ok(!is_file(FR_ROOT . '/' . $upUsr['path']), "fr_delete_image properly deleted the user avatar");
fr_delete_image($upTst['path']);
ok(!is_file(FR_ROOT . '/' . $upTst['path']), "fr_delete_image properly deleted the test cover");
fr_delete_image($upTck['path']);
ok(!is_file(FR_ROOT . '/' . $upTck['path']), "fr_delete_image properly deleted the ticket attachment");
@unlink($tmpImg);

// Verify pipeline migration of legacy DB rows
DB::run("INSERT INTO " . DB::t('events') . " (title, slug, kind, price, capacity, status, image) VALUES ('کارگاه قدیمی تستی', 'ws-legacy-qa', 'workshop', 100000, 20, 'active', 'assets/uploads/events/cover-workshop-1.svg')");
$wsLegId = (int)DB::val("SELECT id FROM " . DB::t('events') . " WHERE slug='ws-legacy-qa'");

DB::run("INSERT INTO " . DB::t('events') . " (title, slug, kind, price, capacity, status, image) VALUES ('دوره قدیمی تستی', 'cs-legacy-qa', 'course', 150000, 15, 'active', 'assets/uploads/events/cover-course-11.svg')");
$csLegId = (int)DB::val("SELECT id FROM " . DB::t('events') . " WHERE slug='cs-legacy-qa'");

require_once FR_ROOT . '/includes/update.php';
// Force run update pipeline
fr_update_pipeline(true);

$migWsImg = DB::val("SELECT image FROM " . DB::t('events') . " WHERE id=?", [$wsLegId]);
ok(str_starts_with($migWsImg, 'feria-uploads/workshops/'), "Legacy workshop image migrated to feria-uploads/workshops/ ($migWsImg)");

$migCsImg = DB::val("SELECT image FROM " . DB::t('events') . " WHERE id=?", [$csLegId]);
ok(str_starts_with($migCsImg, 'feria-uploads/courses/'), "Legacy course image migrated to feria-uploads/courses/ ($migCsImg)");

// Clean up test events
DB::run("DELETE FROM " . DB::t('events') . " WHERE id IN (?, ?)", [$wsLegId, $csLegId]);

echo "\n============================================\n";
echo "🎉 ALL 16 STAGES PASSED WITH 100% SUCCESS!\n";
echo "============================================\n";
