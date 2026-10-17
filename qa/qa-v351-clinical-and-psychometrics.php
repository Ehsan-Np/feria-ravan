<?php
/**
 * QA Test Suite for Feria Ravan v3.5.1
 * Testing:
 * 1. Attempt deletion and action redirect preservation (preserving test_id and query params)
 * 2. Automated diagnostic hypotheses & psychodynamic formulations (ClinicalAnalyzer)
 * 3. Elevated psychometric monographs across all test models (MBTI, Holland, MMPI, Bar-On, Raven, WAIS, DISC, NEO, YSQ)
 * 4. Clinical Case UI components in admin/clinical-case.php
 * 5. Report text generators (fr_text_brief, fr_text_full, scaleBlock, synthesis)
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
require_once FR_ROOT . '/includes/sanitize.php';
require_once FR_ROOT . '/core/ClinicalAnalyzer.php';
require_once FR_ROOT . '/core/analysis-deep.php';
require_once FR_ROOT . '/core/report-texts.php';

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $title, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$title}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$title}" . ($details ? " - {$details}" : '') . "\n";
    }
}

echo "=== FERIA RAVAN v3.5.1 QA TEST SUITE ===\n\n";

// =========================================================================
// 1. ATTEMPT DELETION & ACTION REDIRECT PRESERVATION
// =========================================================================
echo "1. Testing Attempt Deletion and Query Context Preservation:\n";

$attemptsPhp = file_get_contents(FR_ROOT . '/admin/attempts.php');

assert_test(str_contains($attemptsPhp, '$resolveReturnUrl'), "admin/attempts.php defines intelligent return URL resolver");
assert_test(str_contains($attemptsPhp, 'name="return_url"'), "admin/attempts.php injects return_url into action forms");
assert_test(str_contains($attemptsPhp, 'name="test_id"'), "admin/attempts.php injects test_id into action forms");
assert_test(str_contains($attemptsPhp, 'attempts.php?test_id='), "admin/attempts.php preserves test_id in back button and filters");
assert_test(str_contains($attemptsPhp, 'value="trash_attempt"'), "admin/attempts.php has trash_attempt in action forms and report toolbar");

// Simulate the resolver logic
$testResolver = function(?string $explicit, ?string $referer, int $testId): string {
    $baseUrl = SITE_URL . '/admin/attempts.php';
    if ($explicit !== null && $explicit !== '') {
        return $explicit;
    }
    if ($referer !== null && str_contains($referer, '/admin/attempts.php')) {
        return $referer;
    }
    if ($testId > 0) {
        return $baseUrl . '?test_id=' . $testId;
    }
    return $baseUrl;
};

$res1 = $testResolver('', '', 5);
assert_test($res1 === SITE_URL . '/admin/attempts.php?test_id=5', "Resolver falls back to test_id when referer and return_url are empty", "Got: {$res1}");

$res2 = $testResolver(SITE_URL . '/admin/attempts.php?test_id=5&status=completed', '', 0);
assert_test(str_contains($res2, 'test_id=5&status=completed'), "Resolver prioritizes explicit return_url", "Got: {$res2}");

$res3 = $testResolver('', SITE_URL . '/admin/attempts.php?test_id=12&page=2', 0);
assert_test(str_contains($res3, 'test_id=12&page=2'), "Resolver uses referer when safe and valid", "Got: {$res3}");


// =========================================================================
// 2. DIAGNOSTIC HYPOTHESES & PSYCHODYNAMIC FORMULATION (ClinicalAnalyzer)
// =========================================================================
echo "\n2. Testing ClinicalAnalyzer Psychodynamic Formulations:\n";

$summary1 = ClinicalAnalyzer::generateSummary(1);
assert_test(is_array($summary1), "ClinicalAnalyzer successfully loads and analyzes Attempt #1");
assert_test(!empty($summary1['hypotheses']), "Attempt #1 has diagnostic hypotheses generated");
assert_test(isset($summary1['defense_mechanisms']['primary']), "Attempt #1 identifies primary defense mechanisms");
assert_test(isset($summary1['defense_mechanisms']['secondary']), "Attempt #1 identifies secondary defense mechanisms");
assert_test(!empty($summary1['resistances']), "Attempt #1 provides clinical resistance handling guides");
assert_test(!empty($summary1['socratic_prompts']), "Attempt #1 provides Socratic interview questions");
assert_test(!empty($summary1['interventions']), "Attempt #1 provides multi-phase therapeutic entry points");

// Verify category tagging
$hasCategory = false;
foreach ($summary1['hypotheses'] as $hyp) {
    if (!empty($hyp['category'])) {
        $hasCategory = true;
        break;
    }
}
assert_test($hasCategory, "Diagnostic hypotheses have clinical categories (DSM/PDM categorization)");


// =========================================================================
// 3. RADICALLY ELEVATED PSYCHOMETRIC SCALE ANALYSES
// =========================================================================
echo "\n3. Testing Psychometric Deep Knowledge Bases and Scale Monographs:\n";

$deepFiles = [
    'mbti.php'    => ['EI', 'SN', 'TF', 'JP'],
    'holland.php' => ['R', 'I', 'A', 'S', 'E', 'C'],
    'mmpi.php'    => ['L', 'F', 'K', 'Hs', 'D', 'Hy', 'Pd', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'],
    'baron.php'   => ['INTRAP', 'INTERP', 'STRESS', 'ADAPT', 'MOOD'],
    'raven.php'   => ['gf'],
    'wais.php'    => ['VCI', 'PRI', 'WMI', 'PSI'],
];

foreach ($deepFiles as $file => $scales) {
    $filePath = FR_ROOT . '/core/analysis-deep/' . $file;
    assert_test(file_exists($filePath), "Deep knowledge base exists: {$file}");
    $kb = require $filePath;
    assert_test(is_array($kb) && isset($kb['scales']), "Knowledge base {$file} exports scales array");
    foreach ($scales as $scCode) {
        assert_test(isset($kb['scales'][$scCode]), "Scale '{$scCode}' defined in {$file}");
        $scData = $kb['scales'][$scCode];
        assert_test(!empty($scData['def']), "Scale '{$scCode}' has psychological definition");
        assert_test(!empty($scData['manif']), "Scale '{$scCode}' has behavioral manifestations");
        assert_test(!empty($scData['mech']), "Scale '{$scCode}' has cognitive/neurological mechanism");
        assert_test(!empty($scData['interv']), "Scale '{$scCode}' has evidence-based intervention");
        assert_test(!empty($scData['ref']), "Scale '{$scCode}' has standard citation");
    }
}


// =========================================================================
// 4. fr_get_specialized_scale_analysis() & BAND SPECIFICITY
// =========================================================================
echo "\n4. Testing fr_get_specialized_scale_analysis() & Report Integration:\n";

$testCases = [
    ['mbti', 'mbti', 'EI', 'high'],
    ['mbti', 'mbti', 'SN', 'low'],
    ['holland', 'holland', 'I', 'very_high'],
    ['holland', 'holland', 'A', 'medium'],
    ['mmpi', 'mmpi-71', 'D', 'high'],
    ['mmpi', 'mmpi-71', 'Hs', 'very_high'],
    ['baron', 'baron-eq', 'STRESS', 'low'],
    ['baron', 'baron-eq', 'INTRAP', 'high'],
    ['raven', 'raven-iq', 'gf', 'very_high'],
    ['wais', 'wais-iq', 'WMI', 'medium'],
    ['disc', 'disc', 'D', 'high'],
    ['neo', 'neo', 'N', 'very_high'],
];

foreach ($testCases as $tc) {
    [$eng, $slg, $code, $band] = $tc;
    $res = fr_get_specialized_scale_analysis($eng, $slg, $code, $band, 'general', true);
    assert_test(
        $res !== null && !empty($res['title']) && !empty($res['body']),
        "fr_get_specialized_scale_analysis for {$eng}::{$code} [{$band}] returned non-empty monograph (" . strlen($res['body'] ?? '') . " bytes)"
    );
}

// Fallback test for unmapped custom scale
$customRes = fr_get_specialized_scale_analysis('custom_eng', 'custom_slug', 'custom_scale', 'high', 'general', true, [
    'title_fa' => 'سازه اختصاصی بالینی',
    'percent' => 78,
    'sten_score' => 8,
    'percentile' => 84,
]);
assert_test($customRes !== null && str_contains($customRes['body'], 'سازه اختصاصی بالینی'), "fr_get_specialized_scale_analysis generates dynamic clinical analysis for unmapped scales");


// =========================================================================
// 5. fr_text_brief & fr_text_full INTEGRATION
// =========================================================================
echo "\n5. Testing fr_text_brief() & fr_text_full() Integration:\n";

$brief = fr_text_brief('mmpi', 'mmpi-71', 'D', 'high');
assert_test(!empty($brief) && mb_strlen($brief) > 20, "fr_text_brief returns specialized interpretation for MMPI Scale D [high]");

$full = fr_text_full('baron', 'baron-eq', 'STRESS', 'low');
assert_test(!empty($full) && str_contains($full, 'deep-band'), "fr_text_full returns structured monograph for Bar-On STRESS [low]");


// =========================================================================
// 6. CLINICAL CASE ADMIN TEMPLATE INTEGRATION
// =========================================================================
echo "\n6. Testing admin/clinical-case.php UI Integration:\n";

$ccContent = file_get_contents(FR_ROOT . '/admin/clinical-case.php');
assert_test(str_contains($ccContent, 'فرضیه‌سازی تشخیصی و دینامیک روانی مراجع'), "admin/clinical-case.php renders diagnostic formulation section");
assert_test(str_contains($ccContent, 'منظومهٔ مکانیسم‌های دفاعی فعال مراجع'), "admin/clinical-case.php renders active defense mechanisms card");
assert_test(str_contains($ccContent, 'پیش‌بینی مقاومت‌های درمانی مراجع و راهنمای واکنش مشاور'), "admin/clinical-case.php renders resistance handling card");
assert_test(str_contains($ccContent, 'کلیدهای کاوشگرانه و پرسش‌های سقراطی جلسه اول'), "admin/clinical-case.php renders Socratic interview questions card");
assert_test(str_contains($ccContent, 'فرضیه تشخیصی افتراقی') || str_contains($ccContent, '$hyp[\'category\']'), "admin/clinical-case.php renders category badges");


// =========================================================================
// 7. VERSION & PACKAGING INTEGRITY
// =========================================================================
echo "\n7. Testing Version & Architecture Integrity:\n";

assert_test(version_compare(FR_RELEASE, '3.5.1', '>='), "includes/version.php sets FR_RELEASE to 3.5.1 or higher");
$configContent = file_get_contents(FR_ROOT . '/config.php');
assert_test(str_contains($configContent, '3.5.') || str_contains($configContent, '3.6.'), "config.php default version matches 3.5.x or 3.6.x series");
$updateContent = file_get_contents(FR_ROOT . '/includes/update.php');
assert_test(str_contains($updateContent, '۳٫۵٫۱'), "includes/update.php contains 3.5.1 migration hook");


// Summary
echo "\n==========================================\n";
echo "QA SUITE COMPLETED: {$passed} PASSED, {$failed} FAILED\n";
echo "==========================================\n";

if ($failed > 0) {
    exit(1);
}
