<?php
/**
 * ============================================================================
 *  QA Test Suite for Feria Ravan v3.4.5
 *  Verification of GrowthEngine, Dynamic Planner Injection & Delta Retest
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

require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/planner.php';
require_once FR_ROOT . '/core/GrowthEngine.php';

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
echo " QA SUITE: Feria Ravan v3.4.5 - Growth Engine & Planner Integration\n";
echo "====================================================================\n\n";

// --- 1. Version and Config Checks ---
echo "--- 1. Version Constants ---\n";
it("FR_RELEASE is at least 3.4.5", defined('FR_RELEASE') && version_compare(FR_RELEASE, '3.4.5', '>='), FR_RELEASE ?? 'unset');
it("FR_VERSION is at least 3.4.5", defined('FR_VERSION') && version_compare(FR_VERSION, '3.4.5', '>='), FR_VERSION ?? 'unset');

// --- 2. Schema Guard & Self-Healing ---
echo "\n--- 2. Schema Guard & Auto-Healing ---\n";
GrowthEngine::ensurePlannerSchema();

$taskCols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('planner_tasks')), 'Field');
it("planner_tasks has 'note' column", in_array('note', $taskCols, true));
it("planner_tasks has 'remind_at' column", in_array('remind_at', $taskCols, true));
it("planner_tasks has 'reminded_at' column", in_array('reminded_at', $taskCols, true));
it("planner_tasks has 'recur_id' column", in_array('recur_id', $taskCols, true));
it("planner_tasks has 'ref_attempt_id' column", in_array('ref_attempt_id', $taskCols, true));

$tableName = str_replace('`', '', DB::t('planner_cats'));
$catTable = DB::one("SHOW TABLES LIKE '{$tableName}'");
it("planner_cats table exists in database", !empty($catTable));

$attCols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('attempts')), 'Field');
it("attempts table has 'growth_injected' column", in_array('growth_injected', $attCols, true));

// --- 3. End-to-End DISC Growth Plan Injection ---
echo "\n--- 3. DISC Growth Plan Injection ---\n";
$at = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE test_id=1 AND status='completed' ORDER BY id DESC LIMIT 1");
if (!$at) {
    echo "Creating dummy completed DISC attempt...\n";
    $atId = DB::insert('attempts', [
        'user_id' => 1, 'test_id' => 1, 'status' => 'completed',
        'started_at' => date('Y-m-d H:i:s', time() - 3600),
        'completed_at' => date('Y-m-d H:i:s'),
        'progress' => 100,
    ]);
    DB::insert('results', ['attempt_id' => $atId, 'scale_code' => 'D', 'percent' => 30, 'raw_score' => 30]);
    DB::insert('results', ['attempt_id' => $atId, 'scale_code' => 'I', 'percent' => 50, 'raw_score' => 50]);
    DB::insert('results', ['attempt_id' => $atId, 'scale_code' => 'S', 'percent' => 40, 'raw_score' => 40]);
    DB::insert('results', ['attempt_id' => $atId, 'scale_code' => 'C', 'percent' => 35, 'raw_score' => 35]);
    $at = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$atId]);
}

$res = GrowthEngine::injectToPlanner((int)$at['user_id'], (int)$at['id']);
it("injectToPlanner returns ok=true", !empty($res['ok']), $res['message'] ?? '');
it("injectToPlanner inserted > 0 tasks", ($res['injected_count'] ?? 0) >= 6, (string)($res['injected_count'] ?? 0));

$tasks = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE ref_attempt_id=?", [(int)$at['id']]);
it("Tasks saved in DB match injected_count", count($tasks) === ($res['injected_count'] ?? 0));
it("Tasks assigned to correct user_id", count(array_filter($tasks, fn($t) => (int)$t['user_id'] === (int)$at['user_id'])) === count($tasks));
it("Tasks assigned to 'تمرین رشد کارنامه' category", count(array_filter($tasks, fn($t) => $t['category'] === 'تمرین رشد کارنامه')) === count($tasks));
it("Tasks have non-empty notes (descriptions)", count(array_filter($tasks, fn($t) => trim((string)$t['note']) !== '')) === count($tasks));
it("Tasks have proper remind_at timestamps", count(array_filter($tasks, fn($t) => !empty($t['remind_at']))) === count($tasks));

$attFresh = DB::one("SELECT growth_injected FROM " . DB::t('attempts') . " WHERE id=?", [(int)$at['id']]);
it("Attempt marked as growth_injected=1", (int)($attFresh['growth_injected'] ?? 0) === 1);

// Re-injection test (idempotency: should replace, not duplicate)
$res2 = GrowthEngine::injectToPlanner((int)$at['user_id'], (int)$at['id']);
$tasksAfter = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE ref_attempt_id=?", [(int)$at['id']]);
it("Re-injection does not create duplicate tasks (idempotent)", count($tasksAfter) === count($tasks));

// --- 4. Multi-Engine Plan Generation Verification ---
echo "\n--- 4. Multi-Engine Plan Generators ---\n";

// Helper to simulate a test attempt & plan
function testEnginePlan(string $slug, string $engine, string $title, array $scales): array {
    // Upsert dummy test
    $t = DB::one("SELECT id FROM " . DB::t('tests') . " WHERE slug=?", [$slug]);
    if (!$t) {
        $tid = DB::insert('tests', [
            'slug' => $slug, 'engine' => $engine, 'title' => $title,
            'short_desc' => 'تست آزمایشی ' . $title, 'description' => '', 'price_full' => 0, 'est_minutes' => 10,
        ]);
    } else {
        $tid = (int)$t['id'];
        DB::update('tests', ['engine' => $engine, 'title' => $title], 'id=?', [$tid]);
    }

    $aid = DB::insert('attempts', [
        'user_id' => 1, 'test_id' => $tid, 'status' => 'completed',
        'started_at' => date('Y-m-d H:i:s', time() - 3600),
        'completed_at' => date('Y-m-d H:i:s'), 'progress' => 100,
    ]);

    foreach ($scales as $sc => $pct) {
        DB::insert('results', ['attempt_id' => $aid, 'scale_code' => $sc, 'percent' => $pct, 'raw_score' => $pct]);
    }

    $plan = GrowthEngine::generatePlan($aid);
    $inj = GrowthEngine::injectToPlanner(1, $aid);

    // Clean up attempt
    DB::run("DELETE FROM " . DB::t('planner_tasks') . " WHERE ref_attempt_id=?", [$aid]);
    DB::run("DELETE FROM " . DB::t('results') . " WHERE attempt_id=?", [$aid]);
    DB::run("DELETE FROM " . DB::t('attempts') . " WHERE id=?", [$aid]);

    return ['plan' => $plan, 'inj' => $inj];
}

// 4.1 Anxiety screening
$anx = testEnginePlan('anxiety-qa', 'screen', 'غربالگری اضطراب (GAD-7)', ['cognitive' => 65, 'affective' => 70, 'somatic' => 60, 'overall' => 65]);
it("Anxiety test generates 9 protocol tasks", ($anx['plan']['task_count'] ?? 0) === 9, (string)($anx['plan']['task_count'] ?? 0));
it("Anxiety test injects to planner successfully", !empty($anx['inj']['ok']));

// 4.2 Depression screening
$dep = testEnginePlan('depression-qa', 'screen', 'غربالگری افسردگی (PHQ-9)', ['cognitive' => 60, 'affective' => 65, 'somatic' => 70, 'overall' => 65]);
it("Depression test generates 9 BA protocol tasks", ($dep['plan']['task_count'] ?? 0) === 9, (string)($dep['plan']['task_count'] ?? 0));
it("Depression test injects to planner successfully", !empty($dep['inj']['ok']));

// 4.3 Job Satisfaction
$js = testEnginePlan('jobsat-qa', 'jobsat', 'رضایت شغلی (JSS)', ['pay' => 40, 'supervision' => 50, 'coworkers' => 60]);
it("Job satisfaction test generates 8 workplace growth tasks", ($js['plan']['task_count'] ?? 0) === 8, (string)($js['plan']['task_count'] ?? 0));
it("Job satisfaction test injects to planner successfully", !empty($js['inj']['ok']));

// 4.4 Gardner Multiple Intelligences
$mi = testEnginePlan('gardner-mi-qa', 'mslikert', 'هوش‌های چندگانه گاردنر', ['linguistic' => 70, 'logical' => 50, 'spatial' => 60, 'interpersonal' => 45]);
it("Gardner MI test generates 8 development tasks", ($mi['plan']['task_count'] ?? 0) === 8, (string)($mi['plan']['task_count'] ?? 0));
it("Gardner MI test injects to planner successfully", !empty($mi['inj']['ok']));

// 4.5 Emotional Intelligence (Shutte / Bar-On)
$eq = testEnginePlan('eq-shutte-qa', 'mslikert', 'هوش هیجانی شاته', ['perception' => 55, 'regulation' => 45, 'utilization' => 60]);
it("EQ test generates 8 emotional regulation tasks", ($eq['plan']['task_count'] ?? 0) === 8, (string)($eq['plan']['task_count'] ?? 0));
it("EQ test injects to planner successfully", !empty($eq['inj']['ok']));

// 4.6 Young Schema Questionnaire (YSQ)
$ysq = testEnginePlan('ysq-qa', 'ysq', 'طرحواره‌های یانگ (YSQ)', ['ed' => 65, 'ab' => 70, 'ma' => 50]);
it("YSQ Schema test generates 8 schema therapy tasks", ($ysq['plan']['task_count'] ?? 0) === 8, (string)($ysq['plan']['task_count'] ?? 0));
it("YSQ Schema test injects to planner successfully", !empty($ysq['inj']['ok']));

// 4.7 MBTI
$mbti = testEnginePlan('mbti-qa', 'mbti', 'تیپ‌شناسی شخصیت مایرز-بریگز', ['E' => 60, 'I' => 40, 'S' => 30, 'N' => 70, 'T' => 65, 'F' => 35, 'J' => 55, 'P' => 45]);
it("MBTI test generates 7 personality balance tasks", ($mbti['plan']['task_count'] ?? 0) === 7, (string)($mbti['plan']['task_count'] ?? 0));
it("MBTI test injects to planner successfully", !empty($mbti['inj']['ok']));

// 4.8 Holland Career Interests
$hol = testEnginePlan('holland-qa', 'holland', 'رغبت‌سنجی شغلی هالند', ['R' => 40, 'I' => 75, 'A' => 60, 'S' => 65, 'E' => 55, 'C' => 50]);
it("Holland test generates 7 vocational exploration tasks", ($hol['plan']['task_count'] ?? 0) === 7, (string)($hol['plan']['task_count'] ?? 0));
it("Holland test injects to planner successfully", !empty($hol['inj']['ok']));

// 4.9 NEO-FFI (Five Factor)
$neo = testEnginePlan('neo-qa', 'likert', 'تست ۵ عاملی نئو (NEO-FFI)', ['N' => 65, 'E' => 40, 'O' => 55, 'A' => 50, 'C' => 42]);
it("NEO test generates tailored Big Five tasks", ($neo['plan']['task_count'] ?? 0) >= 6, (string)($neo['plan']['task_count'] ?? 0));
it("NEO test injects to planner successfully", !empty($neo['inj']['ok']));

// 4.10 MMPI Clinical
$mmpi = testEnginePlan('mmpi-qa', 'mmpi', 'آزمون بالینی چندوجهی مینه سوتا (MMPI)', ['D' => 65, 'Pt' => 60]);
it("MMPI test generates clinical wellness tasks", ($mmpi['plan']['task_count'] ?? 0) === 6, (string)($mmpi['plan']['task_count'] ?? 0));
it("MMPI test injects to planner successfully", !empty($mmpi['inj']['ok']));

// 4.11 Cognitive (Raven / WAIS)
$cog = testEnginePlan('raven-qa', 'raven', 'ماتریس‌های پیشرونده ریون', ['overall' => 75]);
it("Raven cognitive test generates mental agility tasks", ($cog['plan']['task_count'] ?? 0) === 6, (string)($cog['plan']['task_count'] ?? 0));
it("Raven cognitive test injects to planner successfully", !empty($cog['inj']['ok']));

// 4.12 Generic Fallback
$gen = testEnginePlan('custom-test-qa', 'custom', 'تست سفارشی عمومی', ['score' => 50]);
it("Generic test generates 7 comprehensive growth tasks", ($gen['plan']['task_count'] ?? 0) === 7, (string)($gen['plan']['task_count'] ?? 0));
it("Generic test injects to planner successfully", !empty($gen['inj']['ok']));

// --- 5. Retest & Delta Tracking (GrowthEngine::getDeltaComparison) ---
echo "\n--- 5. Retest & Delta Tracking ---\n";
// Ensure baseline attempt 1 exists for user 1, test 1
$createdAid1 = false;
$aid1 = (int)DB::val("SELECT id FROM " . DB::t('attempts') . " WHERE user_id=1 AND test_id=1 AND status='completed' ORDER BY id ASC LIMIT 1");
if (!$aid1) {
    $aid1 = DB::insert('attempts', [
        'user_id' => 1, 'test_id' => 1, 'status' => 'completed',
        'started_at' => date('Y-m-d H:i:s', time() - 3600),
        'completed_at' => date('Y-m-d H:i:s', time() - 3500), 'progress' => 100,
    ]);
    DB::insert('results', ['attempt_id' => $aid1, 'scale_code' => 'D', 'percent' => 30, 'raw_score' => 30]);
    DB::insert('results', ['attempt_id' => $aid1, 'scale_code' => 'I', 'percent' => 50, 'raw_score' => 50]);
    DB::insert('results', ['attempt_id' => $aid1, 'scale_code' => 'S', 'percent' => 40, 'raw_score' => 40]);
    DB::insert('results', ['attempt_id' => $aid1, 'scale_code' => 'C', 'percent' => 35, 'raw_score' => 35]);
    $createdAid1 = true;
}

// Create second attempt for test 1 to test Delta comparison
$aid2 = DB::insert('attempts', [
    'user_id' => 1, 'test_id' => 1, 'status' => 'completed',
    'started_at' => date('Y-m-d H:i:s', time() - 1800),
    'completed_at' => date('Y-m-d H:i:s'), 'progress' => 100,
]);
DB::insert('results', ['attempt_id' => $aid2, 'scale_code' => 'D', 'percent' => 45, 'raw_score' => 45]);
DB::insert('results', ['attempt_id' => $aid2, 'scale_code' => 'I', 'percent' => 55, 'raw_score' => 55]);
DB::insert('results', ['attempt_id' => $aid2, 'scale_code' => 'S', 'percent' => 50, 'raw_score' => 50]);
DB::insert('results', ['attempt_id' => $aid2, 'scale_code' => 'C', 'percent' => 45, 'raw_score' => 45]);

$delta = GrowthEngine::getDeltaComparison(1, 1);
it("getDeltaComparison recognizes comparison (has_comparison=true)", !empty($delta['has_comparison']));
it("getDeltaComparison total_attempts >= 2", ($delta['total_attempts'] ?? 0) >= 2);
it("getDeltaComparison contains D scale delta", isset($delta['scales']['D']));
it("Scale D shows positive improvement (+15)", ($delta['scales']['D']['delta'] ?? 0) === 15.0, (string)($delta['scales']['D']['delta'] ?? 0));
it("Scale D has Persian title", !empty($delta['scales']['D']['title']) && $delta['scales']['D']['title'] !== 'D', $delta['scales']['D']['title'] ?? '');

// Cleanup dummy attempts
DB::run("DELETE FROM " . DB::t('results') . " WHERE attempt_id=?", [$aid2]);
DB::run("DELETE FROM " . DB::t('attempts') . " WHERE id=?", [$aid2]);
if ($createdAid1) {
    DB::run("DELETE FROM " . DB::t('results') . " WHERE attempt_id=?", [$aid1]);
    DB::run("DELETE FROM " . DB::t('attempts') . " WHERE id=?", [$aid1]);
}

// Clean up temporary qa tests
DB::run("DELETE FROM " . DB::t('tests') . " WHERE slug LIKE '%-qa'");

// --- 6. Schema & Upstream Compatibility ---
echo "\n--- 6. Schema & Installer Consistency ---\n";
$schemaSql = file_get_contents(FR_ROOT . '/install/schema.sql');
it("schema.sql contains planner_cats table definition", str_contains($schemaSql, 'CREATE TABLE IF NOT EXISTS `{{prefix}}planner_cats`'));
it("schema.sql planner_tasks contains note column", str_contains($schemaSql, '`note` VARCHAR(500) NOT NULL DEFAULT \'\''));
it("schema.sql planner_tasks contains remind_at column", str_contains($schemaSql, '`remind_at` DATETIME DEFAULT NULL'));
it("schema.sql planner_tasks contains reminded_at column", str_contains($schemaSql, '`reminded_at` DATETIME DEFAULT NULL'));
it("schema.sql planner_tasks contains recur_id column", str_contains($schemaSql, '`recur_id` BIGINT UNSIGNED DEFAULT NULL'));

$updatePhp = file_get_contents(FR_ROOT . '/includes/update.php');
it("update.php contains 3.4.5 migration step", str_contains($updatePhp, '۳٫۴٫۵: تصحیح و تضمین ساختار پلنر روزانه'));

echo "\n====================================================================\n";
echo " QA RESULTS: {$passed} passed, {$failed} failed\n";
echo "====================================================================\n";

exit($failed > 0 ? 1 : 0);
