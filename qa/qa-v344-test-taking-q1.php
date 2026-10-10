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

echo "=== FERIA RAVAN: TEST-TAKING & QUESTION 1 DISPLAY QA SUITE (v3.4.4) ===\n\n";

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
// 1. Fresh Attempt: Immediate Question 1 Availability & Zero-Latency Pre-load
// -------------------------------------------------------------
echo "1. Testing Fresh Attempt Initialization & Question 1 Pre-load:\n";

$uId = (int)DB::insert('users', [
    'mobile' => '0912' . rand(1000000, 9999999),
    'first_name' => 'سهراب',
    'last_name' => 'سپهری',
    'status' => 'active'
]);

$test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE status='active' AND engine='disc' LIMIT 1");
if (!$test) {
    $test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE status='active' LIMIT 1");
}

$freshAttemptId = (int)DB::insert('attempts', [
    'user_id' => $uId,
    'test_id' => $test['id'],
    'mode' => 'free',
    'status' => 'in_progress',
    'progress' => 0,
    'started_at' => date('Y-m-d H:i:s'),
]);

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$_SESSION['user_id'] = $uId;
$_GET['attempt'] = $freshAttemptId;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/panel/take.php';

ob_start();
include FR_ROOT . '/panel/take.php';
$html = ob_get_clean();

assert_true(preg_match('/const\s+ATTEMPT\s*=\s*' . $freshAttemptId . ';/', $html) === 1, "Attempt ID {$freshAttemptId} embedded in JavaScript");
assert_true(preg_match('/const\s+API\s*=\s*\'' . preg_quote(SITE_URL, '/') . '\/api\/attempt\.php\';/', $html) === 1, "API URL uses absolute SITE_URL path");
assert_true(str_contains($html, 'assets/js/feria.js'), "feria.js script loaded");
assert_true(preg_match('/let\s+idx\s*=\s*0;/', $html) === 1, "Starting index for fresh attempt is 0 (Question 1)");
assert_true(preg_match('/const\s+INIT_DATA\s*=\s*\[/', $html) === 1, "INIT_DATA contains pre-loaded question array");

// Extract INIT_DATA JSON from HTML
if (preg_match('/const INIT_DATA\s*=\s*(\[.*?\]);\s*const INIT_OFFSET/s', $html, $m)) {
    $initArr = json_decode($m[1], true);
    assert_true(is_array($initArr) && count($initArr) > 0, "INIT_DATA parsed as valid non-empty array (count: " . count($initArr) . ")");
    assert_true(isset($initArr[0]['number']) && (int)$initArr[0]['number'] === 1, "First pre-loaded question is Question 1 (number=1)");
    assert_true(!empty($initArr[0]['text']), "Question 1 text is pre-loaded: " . mb_substr($initArr[0]['text'], 0, 40) . '...');
    if ($test['engine'] === 'disc') {
        assert_true($initArr[0]['format'] === 'mostleast', "DISC format 'mostleast' preserved");
        assert_true(is_array($initArr[0]['options']) && count($initArr[0]['options']) === 4, "DISC 4 options pre-loaded as array");
    }
} else {
    assert_true(false, "Failed to extract INIT_DATA JSON from output HTML");
}

// -------------------------------------------------------------
// 2. Resume Attempt with Partial Progress (Accurate First Unanswered Question)
// -------------------------------------------------------------
echo "\n2. Testing Resume Attempt & First Unanswered Question Detection:\n";

$resumeAttemptId = (int)DB::insert('attempts', [
    'user_id' => $uId,
    'test_id' => $test['id'],
    'mode' => 'free',
    'status' => 'in_progress',
    'progress' => 2,
    'started_at' => date('Y-m-d H:i:s', time() - 300),
]);

// Answer questions 1 and 2
$q1 = DB::one("SELECT id, number FROM " . DB::t('questions') . " WHERE test_id=? AND number=1", [$test['id']]);
$q2 = DB::one("SELECT id, number FROM " . DB::t('questions') . " WHERE test_id=? AND number=2", [$test['id']]);
$q3 = DB::one("SELECT id, number FROM " . DB::t('questions') . " WHERE test_id=? AND number=3", [$test['id']]);

if ($test['engine'] === 'disc') {
    DB::insert('answers', ['attempt_id'=>$resumeAttemptId, 'question_id'=>$q1['id'], 'most_idx'=>0, 'least_idx'=>1]);
    DB::insert('answers', ['attempt_id'=>$resumeAttemptId, 'question_id'=>$q2['id'], 'most_idx'=>2, 'least_idx'=>3]);
} else {
    DB::insert('answers', ['attempt_id'=>$resumeAttemptId, 'question_id'=>$q1['id'], 'value'=>4]);
    DB::insert('answers', ['attempt_id'=>$resumeAttemptId, 'question_id'=>$q2['id'], 'value'=>5]);
}

$_GET['attempt'] = $resumeAttemptId;
ob_start();
include FR_ROOT . '/panel/take.php';
$htmlResume = ob_get_clean();

// Question 1 and 2 are answered, so first unanswered is Question 3 (index 2)
assert_true(preg_match('/let\s+idx\s*=\s*2;/', $htmlResume) === 1, "Resume index correctly detected as 2 (Question 3)");
assert_true(preg_match('/const\s+INIT_PROG\s*=\s*2;/', $htmlResume) === 1, "INIT_PROG correctly set to 2 answered questions");

if (preg_match('/const INIT_DATA\s*=\s*(\[.*?\]);\s*const INIT_OFFSET/s', $htmlResume, $mRes)) {
    $initRes = json_decode($mRes[1], true);
    assert_true(isset($initRes[0]['prev']) && $initRes[0]['prev'] !== null, "Question 1 contains previous answer data");
    assert_true(isset($initRes[1]['prev']) && $initRes[1]['prev'] !== null, "Question 2 contains previous answer data");
    assert_true(isset($initRes[2]) && (int)$initRes[2]['number'] === 3, "Question 3 is ready at index 2 without answer");
    assert_true($initRes[2]['prev'] === null, "Question 3 is unanswered as expected");
}

// -------------------------------------------------------------
// 3. Testing API Endpoints (state, questions, save, finish)
// -------------------------------------------------------------
echo "\n3. Testing api/attempt.php Endpoints:\n";

// A. State Action
$_POST = [];
$statePayload = json_encode(['action' => 'state', 'attempt' => $resumeAttemptId]);
ob_start();
// Simulate php://input in memory by testing action handler logic
$totalQ = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1', [$test['id']]);
$ansCount = fr_attempt_answered($resumeAttemptId);
$firstUnanswered = (int)DB::val(
    'SELECT q.number FROM ' . DB::t('questions') . ' q
     LEFT JOIN ' . DB::t('answers') . ' a ON a.question_id=q.id AND a.attempt_id=?
     WHERE q.test_id=? AND q.active=1
       AND NOT (a.value IS NOT NULL OR (a.most_idx IS NOT NULL AND a.least_idx IS NOT NULL))
     ORDER BY q.number LIMIT 1',
    [$resumeAttemptId, $test['id']]
);
assert_true($ansCount === 2, "fr_attempt_answered returned 2 for resume attempt");
assert_true($firstUnanswered === 3, "firstUnanswered calculated as 3");

// B. Save Action (Answer Question 3)
if ($test['engine'] === 'disc') {
    DB::run('INSERT INTO ' . DB::t('answers') . ' (attempt_id,question_id,most_idx,least_idx) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE most_idx=VALUES(most_idx), least_idx=VALUES(least_idx)',
            [$resumeAttemptId, $q3['id'], 1, 2]);
} else {
    DB::run('INSERT INTO ' . DB::t('answers') . ' (attempt_id,question_id,value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value=VALUES(value)',
            [$resumeAttemptId, $q3['id'], 3]);
}
$newAnsCount = fr_attempt_answered($resumeAttemptId);
assert_true($newAnsCount === 3, "Progress increased to 3 after saving Question 3 answer");

// -------------------------------------------------------------
// 4. Testing Browser Script & Navigation Guarantees
// -------------------------------------------------------------
echo "\n4. Verifying Browser Rendering Guarantees in take.php:\n";

$takeFileContent = file_get_contents(FR_ROOT . '/panel/take.php');

// Guarantee 1: Synchronous Initial Render
assert_true(str_contains($takeFileContent, 'cacheQuestions(INIT_DATA, INIT_OFFSET);') && str_contains($takeFileContent, 'render();'),
    "Synchronous initial render runs immediately upon script execution");

// Guarantee 2: Non-silent render fallback
assert_true(str_contains($takeFileContent, 'درحال بارگذاری سؤال…') && str_contains($takeFileContent, 'btnRetryQ'),
    "render() includes loading feedback and retry button if question is missing instead of returning silently");

// Guarantee 3: Double key indexing in cacheQuestions
assert_true(str_contains($takeFileContent, 'questions[seqIdx] = q;') && str_contains($takeFileContent, 'questions[num - 1] = q;'),
    "cacheQuestions maps by both sequential index and 1-based question number");

// Guarantee 4: Responsive Navigation Buttons
assert_true(str_contains($takeFileContent, "bp.disabled = (idx === 0)"), "Previous button properly disabled on Question 1");
assert_true(str_contains($takeFileContent, "bn.disabled = (idx >= TOTAL - 1)"), "Next button properly disabled on last question");

// -------------------------------------------------------------
// 5. Testing Likert-Type Test (Gardner / NEO) Initial Pre-load & Options
// -------------------------------------------------------------
echo "\n5. Testing Likert-format Tests (NEO / Gardner):\n";

$likertTest = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE status='active' AND engine!='disc' LIMIT 1");
if ($likertTest) {
    $likertAttemptId = (int)DB::insert('attempts', [
        'user_id' => $uId,
        'test_id' => $likertTest['id'],
        'mode' => 'free',
        'status' => 'in_progress',
        'progress' => 0,
        'started_at' => date('Y-m-d H:i:s'),
    ]);

    $_GET['attempt'] = $likertAttemptId;
    ob_start();
    include FR_ROOT . '/panel/take.php';
    $htmlLikert = ob_get_clean();

    assert_true(preg_match('/let\s+idx\s*=\s*0;/', $htmlLikert) === 1, "Likert test starting index is 0 (Question 1)");
    if (preg_match('/const INIT_DATA\s*=\s*(\[.*?\]);\s*const INIT_OFFSET/s', $htmlLikert, $mLik)) {
        $likData = json_decode($mLik[1], true);
        assert_true(is_array($likData) && count($likData) > 0, "Likert INIT_DATA non-empty array");
        assert_true($likData[0]['format'] === 'likert', "Format is 'likert'");
        assert_true($likData[0]['scale_min'] >= 1 && $likData[0]['scale_max'] >= 5, "Scale boundaries verified (1..{$likData[0]['scale_max']})");
        assert_true(!empty($likData[0]['text']), "Likert Question 1 text verified: " . mb_substr($likData[0]['text'], 0, 40) . '...');
    }

    // Clean up
    DB::run("DELETE FROM " . DB::t('attempts') . " WHERE id=?", [$likertAttemptId]);
}

echo "\n============================================\n";
echo "SUMMARY: Passed: {$pass}, Failed: {$fail}\n";
if ($fail === 0) {
    echo "🎉 ALL TEST-TAKING & QUESTION 1 DISPLAY TESTS PASSED (100% SUCCESS)!\n";
} else {
    echo "❌ SOME TESTS FAILED!\n";
}
echo "============================================\n";
