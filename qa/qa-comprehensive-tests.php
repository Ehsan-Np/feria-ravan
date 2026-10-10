<?php
/**
 * ============================================================================
 *  QA Test Runner: Comprehensive Psychometric Battery & Categories
 *  ─ Verification of:
 *     1) 3 Categories: Clinical, Personality, Intelligence
 *     2) MBTI (60 items, 16 types, Jungian functions, 6 chapters)
 *     3) Holland RIASEC (60 items, 6 scales, 3-letter codes, Iranian careers)
 *     4) YSQ (90 items, 18 schemas, 5 domains)
 *     5) Bar-On EQ-i (60 items, 5 composites, overall EQ)
 *     6) MMPI (71 items, validity L/F/K, 9 clinical scales, Gough index)
 *     7) Raven Matrices (36 items, fluid IQ, percentiles)
 *     8) WAIS Index (40 items, 4 indices, FSIQ)
 *     9) Standardization & Psychometrics (T-score, Sten, Percentile)
 *    10) ClinicalAnalyzer Case Summaries
 *    11) UI Rendering across Panel, Public, and Admin
 * ============================================================================
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
require_once FR_ROOT . '/core/Psychometrics.php';
require_once FR_ROOT . '/core/ClinicalAnalyzer.php';
require_once FR_ROOT . '/core/seed-comprehensive-tests.php';

function ok(bool $cond, string $msg): void {
    if ($cond) {
        echo "  [PASS] $msg\n";
    } else {
        echo "  [FAIL] $msg\n";
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        exit(1);
    }
}

echo "=== 1. Database & Seeding Verification ===\n";
fr_update_pipeline(true);
fr_seed_comprehensive_tests(true);

$tests = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active'");
ok(count($tests) >= 15, "Found " . count($tests) . " active tests in database");

$clinical = DB::all("SELECT slug FROM " . DB::t('tests') . " WHERE category='clinical' AND status='active'");
$personality = DB::all("SELECT slug FROM " . DB::t('tests') . " WHERE category='personality' AND status='active'");
$intelligence = DB::all("SELECT slug FROM " . DB::t('tests') . " WHERE category='intelligence' AND status='active'");

$cSlugs = array_column($clinical, 'slug');
$pSlugs = array_column($personality, 'slug');
$iSlugs = array_column($intelligence, 'slug');

echo "  Clinical tests (" . count($clinical) . "): " . implode(', ', $cSlugs) . "\n";
echo "  Personality tests (" . count($personality) . "): " . implode(', ', $pSlugs) . "\n";
echo "  Intelligence tests (" . count($intelligence) . "): " . implode(', ', $iSlugs) . "\n";

ok(in_array('ysq', $cSlugs, true), "YSQ is categorized as clinical");
ok(in_array('mmpi', $cSlugs, true), "MMPI is categorized as clinical");
ok(in_array('depression', $cSlugs, true), "Depression is categorized as clinical");
ok(in_array('anxiety', $cSlugs, true), "Anxiety is categorized as clinical");

ok(in_array('mbti', $pSlugs, true), "MBTI is categorized as personality");
ok(in_array('disc-family', $pSlugs, true), "DISC is categorized as personality");
ok(in_array('neo', $pSlugs, true), "NEO is categorized as personality");

ok(in_array('holland', $iSlugs, true), "Holland is categorized as intelligence");
ok(in_array('baron-eq', $iSlugs, true), "Bar-On EQ is categorized as intelligence");
ok(in_array('raven', $iSlugs, true), "Raven Matrices is categorized as intelligence");
ok(in_array('wais', $iSlugs, true), "WAIS Index is categorized as intelligence");
ok(in_array('gardner-mi', $iSlugs, true), "Gardner MI is categorized as intelligence");

$user = DB::one("SELECT * FROM " . DB::t('users') . " LIMIT 1");
if (!$user) {
    $uid = (int)DB::insert('users', [
        'mobile'     => '09129998877',
        'first_name' => 'روژین',
        'last_name'  => 'آریامنش',
        'status'     => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $user = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$uid]);
}

function simulateTestRun(array $user, string $slug, callable $answerProvider): array
{
    $test = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE slug=?", [$slug]);
    if (!$test) throw new RuntimeException("Test $slug not found");

    $repCode = 'FR-' . strtoupper(substr(md5(uniqid()), 0, 8));
    $attemptId = (int)DB::insert('attempts', [
        'user_id'      => $user['id'],
        'test_id'      => $test['id'],
        'mode'         => 'full',
        'status'       => 'in_progress',
        'progress'     => 0,
        'context'      => 'work',
        'rep_code'     => $repCode,
        'started_at'   => date('Y-m-d H:i:s', time() - 900),
        'duration_sec' => 900,
    ]);

    $questions = DB::all("SELECT * FROM " . DB::t('questions') . " WHERE test_id=? AND active=1 ORDER BY number", [$test['id']]);
    foreach ($questions as $q) {
        $val = $answerProvider($q);
        DB::insert('answers', [
            'attempt_id'  => $attemptId,
            'question_id' => $q['id'],
            'value'       => (string)$val,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    $scored = Analyzer::scoreAttempt($attemptId);
    if (!$scored) throw new RuntimeException("Scoring failed for $slug");

    $attempt = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
    $results = DB::all("SELECT * FROM " . DB::t('results') . " WHERE attempt_id=?", [$attemptId]);
    return ['attempt' => $attempt, 'test' => $test, 'results' => $results];
}

echo "\n=== 2. MBTI Engine & Deep Report Test ===\n";
// Answering toward INTJ:
// EI: I answers high, E answers low
// SN: N answers high, S answers low
// TF: T answers high, F answers low
// JP: J answers high, P answers low
$mbtiRun = simulateTestRun($user, 'mbti', function($q) {
    // EI questions: 1-15 (odd=E rev=0, even=I rev=1)
    // SN questions: 16-30
    // TF questions: 31-45
    // JP questions: 46-60
    $num = (int)$q['number'];
    if ($num <= 15) {
        return ($num % 2 === 0) ? 5 : 1; // strongly I
    } elseif ($num <= 30) {
        return ($num % 2 === 1) ? 5 : 1; // strongly N
    } elseif ($num <= 45) {
        return ($num % 2 === 1) ? 5 : 1; // strongly T
    } else {
        return ($num % 2 === 0) ? 1 : 5; // strongly J
    }
});

ok($mbtiRun['attempt']['status'] === 'completed', "MBTI attempt completed");
$context = (string)$mbtiRun['attempt']['context'];
echo "  MBTI context output: $context\n";
ok(str_contains($context, 'type:'), "MBTI type detected in context");
preg_match('/type:([A-Z]{4})/', $context, $m);
$detectedType = $m[1] ?? '';
ok(!empty($detectedType), "Detected MBTI 4-letter type: $detectedType");

$mbtiResults = $mbtiRun['results'];
$scaleCodes = array_column($mbtiResults, 'scale_code');
ok(in_array('EI', $scaleCodes, true), "Result contains EI scale");
ok(in_array('SN', $scaleCodes, true), "Result contains SN scale");
ok(in_array('TF', $scaleCodes, true), "Result contains TF scale");
ok(in_array('JP', $scaleCodes, true), "Result contains JP scale");
ok(in_array($detectedType, $scaleCodes, true), "Result contains $detectedType scale entry");

// Test report rendering
$mbtiReportHtml = ReportBuilder::build($mbtiRun['attempt'], $user, 'full');
ok(str_contains($mbtiReportHtml, $detectedType), "MBTI full report contains type code $detectedType");
ok(str_contains($mbtiReportHtml, 'فصل ۱: کالبدشکافی ساختار روانی'), "MBTI full report contains chapter 1");
ok(str_contains($mbtiReportHtml, 'فصل ۶: برنامه اقدام عملی'), "MBTI full report contains chapter 6 actionable blueprint");

// Free report teaser check
$mbtiFreeHtml = ReportBuilder::build($mbtiRun['attempt'], $user, 'free');
ok(str_contains($mbtiFreeHtml, 'فصل‌های قفل‌شده نسخهٔ ویژه') || str_contains($mbtiFreeHtml, 'دریافت نسخهٔ ویژه'), "MBTI free report contains teaser/upsell hook");

echo "\n=== 3. Holland RIASEC Engine & Vocational Alignment Test ===\n";
// Answering toward Investigative (I), Artistic (A), Realistic (R):
$hollandRun = simulateTestRun($user, 'holland', function($q) {
    $num = (int)$q['number'];
    if ($num >= 11 && $num <= 20) return 5; // I
    if ($num >= 21 && $num <= 30) return 4; // A
    if ($num >= 1 && $num <= 10)   return 3; // R
    return 1;
});

ok($hollandRun['attempt']['status'] === 'completed', "Holland attempt completed");
$hContext = (string)$hollandRun['attempt']['context'];
echo "  Holland context: $hContext\n";
ok(str_contains($hContext, 'holland_code:'), "Holland 3-letter code present");
preg_match('/holland_code:([A-Z]{3})/', $hContext, $hm);
$hollandCode = $hm[1] ?? '';
ok(!empty($hollandCode), "Detected Holland code: $hollandCode");

$hReportHtml = ReportBuilder::build($hollandRun['attempt'], $user, 'full');
ok(str_contains($hReportHtml, 'عناوین شغلی و رشته‌های دانشگاهی هم‌راستا در ایران') || str_contains($hReportHtml, 'رغبت شغلی'), "Holland report contains career guidance");

echo "\n=== 4. YSQ (Young Schema Questionnaire) 18 Schemas Test ===\n";
$ysqRun = simulateTestRun($user, 'ysq', function($q) {
    $num = (int)$q['number'];
    // Give high scores on Abandonment (AB) and Emotional Deprivation (ED)
    return ($num <= 10) ? 6 : 2;
});

ok($ysqRun['attempt']['status'] === 'completed', "YSQ attempt completed");
$ysqScales = array_column($ysqRun['results'], 'scale_code');
ok(count($ysqScales) >= 18, "YSQ scored all 18 schemas (count: " . count($ysqScales) . ")");
$activeSchemas = Analyzer::activeSchemas((int)$ysqRun['attempt']['id']);
ok(count($activeSchemas) >= 1, "Active schemas detected (count: " . count($activeSchemas) . ")");

$ysqReportHtml = ReportBuilder::build($ysqRun['attempt'], $user, 'full');
ok(str_contains($ysqReportHtml, 'طرحواره') || str_contains($ysqReportHtml, 'تله'), "YSQ report rendered schema analysis");

echo "\n=== 5. Bar-On EQ-i Emotional Intelligence Test ===\n";
$baronRun = simulateTestRun($user, 'baron-eq', function($q) {
    return 4; // balanced high EQ
});

ok($baronRun['attempt']['status'] === 'completed', "Bar-On EQ-i attempt completed");
$bScales = array_column($baronRun['results'], 'scale_code');
ok(in_array('INTRAP', $bScales, true), "Bar-On contains INTRAP");
ok(in_array('INTERP', $bScales, true), "Bar-On contains INTERP");
ok(in_array('STRESS', $bScales, true), "Bar-On contains STRESS");
ok(in_array('ADAPT', $bScales, true), "Bar-On contains ADAPT");
ok(in_array('MOOD', $bScales, true), "Bar-On contains MOOD");
ok(in_array('overall', $bScales, true), "Bar-On contains overall EQ");

$bReportHtml = ReportBuilder::build($baronRun['attempt'], $user, 'full');
ok(str_contains($bReportHtml, 'هوش هیجانی') || str_contains($bReportHtml, 'درون‌فردی'), "Bar-On report rendered successfully");

echo "\n=== 6. MMPI Standard Screening Test ===\n";
$mmpiRun = simulateTestRun($user, 'mmpi', function($q) {
    $num = (int)$q['number'];
    // Normal response pattern with slight elevation on Depression
    if ($num >= 27 && $num <= 33) return 4; // Depression scale
    if ($num <= 7) return 2; // L scale low
    return 2;
});

ok($mmpiRun['attempt']['status'] === 'completed', "MMPI attempt completed");
$mScales = array_column($mmpiRun['results'], 'scale_code');
ok(in_array('L', $mScales, true), "MMPI contains L validity scale");
ok(in_array('F', $mScales, true), "MMPI contains F validity scale");
ok(in_array('K', $mScales, true), "MMPI contains K validity scale");
ok(in_array('D', $mScales, true), "MMPI contains D clinical scale");
ok(in_array('profile', $mScales, true), "MMPI contains profile summary");

$mContext = (string)$mmpiRun['attempt']['context'];
echo "  MMPI context: $mContext\n";
ok(str_contains($mContext, 'codetype:'), "MMPI codetype identified");
ok(str_contains($mContext, 'fk:'), "MMPI F-K Gough index calculated");

echo "\n=== 7. Raven Progressive Matrices Fluid IQ Test ===\n";
$ravenRun = simulateTestRun($user, 'raven', function($q) {
    // Provide correct answers for 30 out of 36 items
    $num = (int)$q['number'];
    if ($num <= 30) {
        return (($num * 3 + 1) % 6); // correct key
    }
    return 0; // incorrect
});

ok($ravenRun['attempt']['status'] === 'completed', "Raven attempt completed");
$rOverall = null;
foreach ($ravenRun['results'] as $r) {
    if ($r['scale_code'] === 'overall') $rOverall = $r;
}
ok($rOverall !== null, "Raven overall result exists");
$rawCorrect = (int)$rOverall['raw_score'];
$estIq = (int)$rOverall['mean_score'];
echo "  Raven Raw Score: $rawCorrect/36, Estimated IQ: $estIq\n";
ok($rawCorrect === 30, "Raven correct items counted correctly (30)");
ok($estIq >= 120, "Raven estimated IQ in superior range (got: $estIq)");

$rContext = (string)$ravenRun['attempt']['context'];
ok(str_contains($rContext, 'iq:' . $estIq), "Raven context has estimated IQ");

echo "\n=== 8. WAIS Cognitive Index Test ===\n";
$waisRun = simulateTestRun($user, 'wais', function($q) {
    return 4; // uniformly solid cognitive capability
});

ok($waisRun['attempt']['status'] === 'completed', "WAIS attempt completed");
$wScales = array_column($waisRun['results'], 'scale_code');
ok(in_array('VCI', $wScales, true), "WAIS contains VCI (Verbal Comprehension)");
ok(in_array('PRI', $wScales, true), "WAIS contains PRI (Perceptual Reasoning)");
ok(in_array('WMI', $wScales, true), "WAIS contains WMI (Working Memory)");
ok(in_array('PSI', $wScales, true), "WAIS contains PSI (Processing Speed)");
ok(in_array('overall', $wScales, true), "WAIS contains overall FSIQ equivalent");

echo "\n=== 9. Psychometrics Norms & Standardization Test ===\n";
foreach ([$mbtiRun, $hollandRun, $baronRun, $mmpiRun, $ravenRun, $waisRun] as $run) {
    $slug = $run['test']['slug'];
    $attemptId = (int)$run['attempt']['id'];
    $res = DB::all("SELECT * FROM " . DB::t('results') . " WHERE attempt_id=?", [$attemptId]);
    foreach ($res as $r) {
        $tScore = (float)($r['t_score'] ?? 0);
        $sten = (int)($r['sten_score'] ?? 0);
        $p = (float)($r['percentile'] ?? 0);
        ok($tScore >= 20.0 && $tScore <= 80.0, "[$slug: {$r['scale_code']}] T-score in bounds (20-80, got: $tScore)");
        ok($sten >= 1 && $sten <= 10, "[$slug: {$r['scale_code']}] Sten score in bounds (1-10, got: $sten)");
        ok($p >= 1.0 && $p <= 99.0, "[$slug: {$r['scale_code']}] Percentile in bounds (1-99, got: $p)");
    }
}

echo "\n=== 10. ClinicalAnalyzer Case Summaries for Therapists ===\n";
$clinSummaryYsq = ClinicalAnalyzer::generateSummary((int)$ysqRun['attempt']['id']);
ok($clinSummaryYsq !== null, "YSQ clinical case summary generated");
ok(count($clinSummaryYsq['hypotheses']) > 0, "YSQ diagnostic hypotheses generated");
ok(count($clinSummaryYsq['interventions']) > 0, "YSQ therapist interventions provided");

$clinSummaryMmpi = ClinicalAnalyzer::generateSummary((int)$mmpiRun['attempt']['id']);
ok($clinSummaryMmpi !== null, "MMPI clinical case summary generated");
ok(isset($clinSummaryMmpi['defense_mechanisms']['primary']), "MMPI defense mechanisms mapped");

$clinSummaryMbti = ClinicalAnalyzer::generateSummary((int)$mbtiRun['attempt']['id']);
ok($clinSummaryMbti !== null, "MBTI clinical case summary generated");

echo "\n=== 11. UI Views & Filter Logic Verification ===\n";
// Test admin tests page query logic
$adminActive = DB::all("SELECT t.* FROM " . DB::t('tests') . " t WHERE t.status<>'trash' ORDER BY t.sort_order");
ok(count($adminActive) >= 15, "Admin query fetches active tests with category");

$adminClin = DB::all("SELECT t.* FROM " . DB::t('tests') . " t WHERE t.status<>'trash' AND t.category='clinical'");
ok(count($adminClin) >= 4, "Admin clinical filter returns clinical tests");

$adminPers = DB::all("SELECT t.* FROM " . DB::t('tests') . " t WHERE t.status<>'trash' AND t.category='personality'");
ok(count($adminPers) >= 4, "Admin personality filter returns personality tests");

$adminIntel = DB::all("SELECT t.* FROM " . DB::t('tests') . " t WHERE t.status<>'trash' AND t.category='intelligence'");
ok(count($adminIntel) >= 5, "Admin intelligence filter returns intelligence tests");

// Verify helper function
ok(fr_test_category_fa('clinical') === 'بالینی و سلامت روان', "fr_test_category_fa('clinical') correct");
ok(fr_test_category_fa('personality') === 'شخصیت و خودشناسی', "fr_test_category_fa('personality') correct");
ok(fr_test_category_fa('intelligence') === 'هوش، استعداد و پیشرفت شغلی', "fr_test_category_fa('intelligence') correct");

echo "\n============================================================\n";
echo "🎉 ALL COMPREHENSIVE PSYCHOMETRIC BATTERY TESTS PASSED 100%!\n";
echo "============================================================\n";
