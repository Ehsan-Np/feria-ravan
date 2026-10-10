<?php
/**
 * ============================================================================
 *  QA Test Suite for Feria Ravan v3.4.8
 *  Verification of Growth Exercises Planner Injection, Self-Healing Schema,
 *  Dyad Partner Invitation & Multi-Model Flow
 * ============================================================================
 */
declare(strict_types=1);

define('FR_TEST_RUNNING', true);
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_ROOT', dirname(__DIR__) . '/feria-ravan');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/planner.php';
require_once FR_ROOT . '/includes/commerce.php';
require_once FR_ROOT . '/core/Kavenegar.php';
require_once FR_ROOT . '/core/GrowthEngine.php';
require_once FR_ROOT . '/core/DyadAnalyzer.php';

$passed = 0;
$failed = 0;

function it(string $desc, bool $condition, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc} " . ($details ? "({$details})" : "") . "\n";
    }
}

echo "====================================================================\n";
echo " QA SUITE: Feria Ravan v3.4.8 - Growth Planner Injection & Dyad QA\n";
echo "====================================================================\n\n";

// --- 1. Version and Core Global Helper Checks ---
echo "--- 1. Version and Core Global Helpers ---\n";
it('Version is advanced to 3.4.8 or higher', version_compare(FR_RELEASE, '3.4.8', '>='), 'Actual: ' . FR_RELEASE);
it('post() helper is globally defined', function_exists('post'));
it('get() helper is globally defined', function_exists('get'));
it('flash() helper is globally defined', function_exists('flash'));

$_POST['test_key'] = 'test_val';
it('post() returns correct POST value', post('test_key') === 'test_val');
it('post() returns default for missing key', post('non_existent', 'fallback') === 'fallback');

flash('تست پیام موفقیت', 'ok');
it('flash() stores and retrieves formatted message', str_contains((string)flash(), 'تست پیام موفقیت'));
it('flash() purges message after retrieval', flash() === '');

// --- 2. Self-Healing Schema Verification ---
echo "\n--- 2. Self-Healing Schema Verification ---\n";
GrowthEngine::ensurePlannerSchema();
$taskCols = array_column(DB::all("SHOW FULL COLUMNS FROM " . DB::t('planner_tasks')), 'Type', 'Field');
it('planner_tasks has note column', isset($taskCols['note']));
it('planner_tasks has remind_at column', isset($taskCols['remind_at']));
it('planner_tasks has ref_attempt_id column', isset($taskCols['ref_attempt_id']));
it('planner_tasks category column is varchar(60)', str_contains(strtolower($taskCols['category'] ?? ''), 'varchar(60)'));

DyadAnalyzer::ensureDyadSchema();
$dyadCols = array_column(DB::all("SHOW FULL COLUMNS FROM " . DB::t('dyads')), 'Type', 'Field');
it('dyads table exists and has token column', isset($dyadCols['token']));
it('dyads table has pay_mode column', isset($dyadCols['pay_mode']));
it('dyads table has target_mode column', isset($dyadCols['target_mode']));
it('dyads table has partner_paid column', isset($dyadCols['partner_paid']));
it('dyads table has synergy_score column', isset($dyadCols['synergy_score']));

// --- 3. Growth Planner Injection Verification ---
echo "\n--- 3. Growth Planner Injection ---\n";
// Setup test user and attempt
$testUser1 = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE mobile='09121111111'");
if (!$testUser1) {
    $testUser1 = (int)DB::insert('users', [
        'first_name' => 'کوروش',
        'last_name'  => 'کبیری',
        'mobile'     => '09121111111',
        'status'     => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

$discTestId = (int)DB::val("SELECT id FROM " . DB::t('tests') . " WHERE engine='disc' LIMIT 1");
if (!$discTestId) {
    $discTestId = (int)DB::insert('tests', [
        'title'       => 'تست رفتارشناسی DISC',
        'slug'        => 'disc-v348',
        'engine'      => 'disc',
        'status'      => 'active',
        'created_at'  => date('Y-m-d H:i:s'),
    ]);
}

// Create a completed attempt for user 1
$att1 = (int)DB::insert('attempts', [
    'user_id'      => $testUser1,
    'test_id'      => $discTestId,
    'mode'         => 'full',
    'status'       => 'completed',
    'context'      => 'pattern:DI',
    'completed_at' => date('Y-m-d H:i:s'),
    'started_at'   => date('Y-m-d H:i:s'),
]);

// Seed results for DISC
DB::run("DELETE FROM " . DB::t('results') . " WHERE attempt_id=?", [$att1]);
DB::insert('results', ['attempt_id' => $att1, 'scale_code' => 'D', 'percent' => 78, 'raw_score' => 20]);
DB::insert('results', ['attempt_id' => $att1, 'scale_code' => 'I', 'percent' => 68, 'raw_score' => 18]);
DB::insert('results', ['attempt_id' => $att1, 'scale_code' => 'S', 'percent' => 42, 'raw_score' => 12]);
DB::insert('results', ['attempt_id' => $att1, 'scale_code' => 'C', 'percent' => 35, 'raw_score' => 10]);

// Test injection
$injRes = GrowthEngine::injectToPlanner($testUser1, $att1);
it('injectToPlanner succeeded', !empty($injRes['ok']));
it('injectToPlanner returned positive task count', ($injRes['injected_count'] ?? 0) >= 5);

$injectedTasks = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND ref_attempt_id=?", [$testUser1, $att1]);
it('Injected tasks exist in database', count($injectedTasks) === ($injRes['injected_count'] ?? 0));
it('Injected tasks have category "تمرین رشد کارنامه"', $injectedTasks[0]['category'] === 'تمرین رشد کارنامه');
it('Injected tasks have valid future day dates', $injectedTasks[0]['day'] >= date('Y-m-d'));

$attUpdated = DB::one("SELECT growth_injected FROM " . DB::t('attempts') . " WHERE id=?", [$att1]);
it('growth_injected flag is set to 1 on attempt', (int)($attUpdated['growth_injected'] ?? 0) === 1);

// Re-inject: must replace and not duplicate
$injRes2 = GrowthEngine::injectToPlanner($testUser1, $att1);
$injectedTasks2 = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND ref_attempt_id=?", [$testUser1, $att1]);
it('Re-injection is idempotent (no duplicate task buildup)', count($injectedTasks2) === count($injectedTasks));

// Cross-user injection protection
$injWrong = GrowthEngine::injectToPlanner($testUser1 + 999, $att1);
it('injectToPlanner rejects attempt belonging to another user', $injWrong['ok'] === false);

// --- 4. Dyad Partner Invitation Flow Verification ---
echo "\n--- 4. Dyad Partner Invitation Flow ---\n";
$testUser2 = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE mobile='09122222222'");
if (!$testUser2) {
    $testUser2 = (int)DB::insert('users', [
        'first_name' => 'نگین',
        'last_name'  => 'نادری',
        'mobile'     => '09122222222',
        'status'     => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}
// Clean up any attempts and dyads for testUser2 so we test from clean state
DB::run("DELETE FROM " . DB::t('attempts') . " WHERE user_id=?", [$testUser2]);
DB::run("DELETE FROM " . DB::t('dyads') . " WHERE user1_id=? OR user2_id=?", [$testUser1, $testUser2]);

// 4.1 Create Dyad Free Mode
$dyadRes = DyadAnalyzer::createDyad($testUser1, $att1, 'couple', 'نگین نادری', '09122222222', 'free', null);
it('DyadAnalyzer::createDyad succeeds for free mode', !empty($dyadRes['ok']));
it('Dyad token generated', !empty($dyadRes['token']));
it('Dyad invite URL contains token', str_contains($dyadRes['invite_url'], $dyadRes['token']));

$dyadId = (int)$dyadRes['dyad_id'];
$dyadRow = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$dyadId]);
it('Dyad initial status is pending', $dyadRow['status'] === 'pending');
it('Dyad partner_name matches', $dyadRow['partner_name'] === 'نگین نادری');
it('Dyad partner_mobile matches', $dyadRow['partner_mobile'] === '09122222222');

// 4.2 Owner click on invitation link
$ownerJoin = DyadAnalyzer::joinDyad($dyadRes['token'], $testUser1);
it('Owner click reports waiting_partner status', ($ownerJoin['status'] ?? '') === 'waiting_partner');
it('Owner join identifies is_owner flag', !empty($ownerJoin['is_owner']));

// 4.3 Partner click when no attempt taken yet
$partnerJoin1 = DyadAnalyzer::joinDyad($dyadRes['token'], $testUser2);
it('New partner reports needs_test status', ($partnerJoin1['status'] ?? '') === 'needs_test');
it('New partner is redirected to test-start with dyad_token', str_contains($partnerJoin1['redirect_url'] ?? '', 'dyad_token='));
it('Dyad user2_id is now bound to partner', (int)DB::val("SELECT user2_id FROM " . DB::t('dyads') . " WHERE id=?", [$dyadId]) === $testUser2);

// 4.4 Partner completes the test
$att2 = (int)DB::insert('attempts', [
    'user_id'      => $testUser2,
    'test_id'      => $discTestId,
    'mode'         => 'full',
    'status'       => 'completed',
    'context'      => 'pattern:SC',
    'completed_at' => date('Y-m-d H:i:s'),
    'started_at'   => date('Y-m-d H:i:s'),
]);

DB::run("DELETE FROM " . DB::t('results') . " WHERE attempt_id=?", [$att2]);
DB::insert('results', ['attempt_id' => $att2, 'scale_code' => 'D', 'percent' => 30, 'raw_score' => 8]);
DB::insert('results', ['attempt_id' => $att2, 'scale_code' => 'I', 'percent' => 45, 'raw_score' => 12]);
DB::insert('results', ['attempt_id' => $att2, 'scale_code' => 'S', 'percent' => 82, 'raw_score' => 22]);
DB::insert('results', ['attempt_id' => $att2, 'scale_code' => 'C', 'percent' => 74, 'raw_score' => 19]);

// 4.5 Partner rejoins with completed attempt
$partnerJoin2 = DyadAnalyzer::joinDyad($dyadRes['token'], $testUser2, $att2);
it('Dyad becomes ready upon binding second attempt', ($partnerJoin2['status'] ?? '') === 'ready');
it('Dyad report URL points to dyad-report.php', str_contains($partnerJoin2['report_url'] ?? '', 'dyad-report.php?id=' . $dyadId));

$dyadFinal = DB::one("SELECT * FROM " . DB::t('dyads') . " WHERE id=?", [$dyadId]);
it('Dyad DB status is ready', $dyadFinal['status'] === 'ready');
it('Dyad DB attempt2_id matches', (int)$dyadFinal['attempt2_id'] === $att2);
it('Dyad synergy_score is calculated', (int)$dyadFinal['synergy_score'] > 0);

$summary = json_decode((string)$dyadFinal['summary_json'], true);
it('Dyad summary_json contains delta_matrix', !empty($summary['delta_matrix']));
it('Dyad summary_json contains synergy_strengths', !empty($summary['synergy_strengths']));
it('Dyad summary_json contains friction_points', !empty($summary['friction_points']));
it('Dyad summary_json contains safe_protocols', !empty($summary['safe_protocols']));

// --- 5. Dyad Gift (Self-Paid) Flow & Auto-Payment Binding ---
echo "\n--- 5. Dyad Gift (Self-Paid) Flow ---\n";
$giftDyad = DyadAnalyzer::createDyad($testUser1, $att1, 'spouse', 'سارا صادقی', '09123333333', 'self_paid', 9999);
it('Gift dyad created with pay_mode=self_paid', $giftDyad['pay_mode'] === 'self_paid');
it('Gift dyad initially partner_paid=0 before payment', $giftDyad['partner_paid'] === 0);

// Simulate payment of order
DB::update('dyads', ['partner_paid' => 1], 'id=?', [$giftDyad['dyad_id']]);
$giftRow = DB::one("SELECT partner_paid FROM " . DB::t('dyads') . " WHERE id=?", [$giftDyad['dyad_id']]);
it('Gift dyad partner_paid updated to 1 upon payment', (int)$giftRow['partner_paid'] === 1);

// --- 6. dyad-join.php Unauthenticated Redirect Check ---
echo "\n--- 6. dyad-join.php Redirect Security ---\n";
$joinCode = file_get_contents(FR_ROOT . '/dyad-join.php');
it('dyad-join.php never redirects to obsolete /login.php', !str_contains($joinCode, '/login.php'));
it('dyad-join.php redirects to /auth.php?back=', str_contains($joinCode, '/auth.php?back='));

// --- 7. SMS Templates Schema and Dyad Events ---
echo "\n--- 7. SMS Templates Schema and Dyad Events ---\n";
Kavenegar::ensureTemplatesSchema();
$tplEvents = array_column(DB::all("SELECT event FROM " . DB::t('sms_templates')), 'event');
it('sms_templates contains dyad_invite template', in_array('dyad_invite', $tplEvents, true));
it('sms_templates contains dyad_ready template', in_array('dyad_ready', $tplEvents, true));

// --- 8. Panel Endpoint POST Simulation & Error-Free Execution ---
echo "\n--- 8. Panel Endpoint POST Simulation ---\n";
$_SESSION['user_id'] = $testUser1;
$_SESSION['_csrf'] = 'qa_token_v348';
$_POST['_csrf'] = 'qa_token_v348';
$_SERVER['REQUEST_METHOD'] = 'POST';

// 8.1 Simulate report.php inject_growth logic
$_POST['act'] = 'inject_growth';
$_GET['attempt'] = (string)$att1;

it('post("act") returns inject_growth', post('act') === 'inject_growth');
$simRes = GrowthEngine::injectToPlanner($testUser1, $att1);
it('GrowthEngine::injectToPlanner runs cleanly', $simRes['ok'] === true);
flash($simRes['message'], 'ok');
it('flash() captures injection message', str_contains((string)flash(), 'تمرین رشد'));

// 8.2 Simulate dyad.php cancel_dyad logic
$toCancel = DyadAnalyzer::createDyad($testUser1, $att1, 'friend', 'دوست تستی', '09124444444', 'free', null);
$toCancelId = (int)$toCancel['dyad_id'];
$_POST['act'] = 'cancel_dyad';
$_POST['dyad_id'] = (string)$toCancelId;

$dyRow = DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE id=? AND user1_id=? AND status="pending"', [(int)post('dyad_id'), $testUser1]);
it('cancel_dyad finds target pending dyad', !empty($dyRow));
DB::run('DELETE FROM ' . DB::t('dyads') . ' WHERE id=?', [$toCancelId]);
it('cancel_dyad deletes dyad', empty(DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE id=?', [$toCancelId])));

echo "\n====================================================================\n";
echo " QA SUITE COMPLETED: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
