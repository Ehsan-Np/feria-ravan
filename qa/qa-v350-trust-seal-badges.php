<?php
/**
 * QA Test Suite for Feria Ravan v3.5.0
 * Comprehensive testing for Electronic Trust Seal Badges (Enamad, ZarinPal, Samandehi):
 * 1. Security Headers & Content-Security-Policy (CSP) allows third-party trust seals
 * 2. Referrer-Policy allows Enamad origin verification
 * 3. Smart resolution of Enamad URLs into official clickable badge HTML
 * 4. Auto-correction when admins paste raw HTML/script into URL or Image fields
 * 5. ZarinPal script & fallback container wrapping
 * 6. Vector fallbacks for ZarinPal, Enamad, and Samandehi
 * 7. Active slot CSS classes and card styling (.fb-active)
 * 8. Sanitizer whitelist for trusted badge image domains
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
require_once FR_ROOT . '/includes/public-layout.php';

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

echo "\n=======================================================\n";
echo "Feria Ravan v3.5.0 - Electronic Trust Seals QA Test Suite\n";
echo "=======================================================\n\n";

// --- Section 1: Security Headers & CSP ---
echo "--- Section 1: Security Headers & CSP Policy ---\n";
// Read security.php content to inspect CSP directives
$secContent = file_get_contents(FR_ROOT . '/includes/security.php');
assert_test(str_contains($secContent, 'img-src') && str_contains($secContent, 'https:'), "CSP allows https images for third-party seal icons");
assert_test(str_contains($secContent, 'trustseal.enamad.ir'), "CSP explicitly whitelists trustseal.enamad.ir");
assert_test(str_contains($secContent, '*.zarinpal.com'), "CSP explicitly whitelists ZarinPal domain");
assert_test(str_contains($secContent, '*.samandehi.ir'), "CSP explicitly whitelists Samandehi domain");
assert_test(str_contains($secContent, "Referrer-Policy: origin-when-cross-origin"), "Referrer-Policy sends origin on cross-origin requests for Enamad verification");

// --- Section 2: Sanitizer Whitelist ---
echo "\n--- Section 2: URL & Image Sanitizer ---\n";
$enamadImg = 'https://trustseal.enamad.ir/logo.aspx?id=123456&Code=test';
$zarinImg  = 'https://cdn.zarinpal.com/badges/trustLogo/1.svg';
$samandehiImg = 'https://logo.samandehi.ir/logo.aspx?id=999';
$randomExternal = 'https://malicious-site.com/evil.jpg';

assert_test(fr_safe_url($enamadImg, true) === $enamadImg, "fr_safe_url permits official Enamad trust seal image");
assert_test(fr_safe_url($zarinImg, true) === $zarinImg, "fr_safe_url permits official ZarinPal badge image");
assert_test(fr_safe_url($samandehiImg, true) === $samandehiImg, "fr_safe_url permits official Samandehi badge image");
assert_test(fr_safe_url($randomExternal, true) === null, "fr_safe_url blocks arbitrary untrusted external images");

// --- Section 3: Smart Resolution of Enamad URL ---
echo "\n--- Section 3: Smart Resolution of Enamad URL ---\n";
// Save an Enamad URL without image or HTML
DB::setSetting('footer_badge1_url', 'https://trustseal.enamad.ir/?id=987654&Code=abc123xyz');
DB::setSetting('footer_badge1_img', '');
DB::setSetting('footer_badge1_html', '');

$badges = fr_footer_badges();
$b1 = $badges[0];

assert_test($b1['has_badge'] === true, "Enamad slot detected as active badge");
assert_test(!empty($b1['html']), "Enamad URL automatically generated HTML code");
assert_test(str_contains($b1['html'], 'referrerpolicy="origin"'), "Generated Enamad HTML includes mandatory referrerpolicy='origin'");
assert_test(str_contains($b1['html'], 'trustseal.enamad.ir/logo.aspx?id=987654&amp;Code=abc123xyz'), "Generated Enamad HTML includes correct logo.aspx endpoint with query string");

// --- Section 4: Auto-correction of Misplaced HTML in URL field ---
echo "\n--- Section 4: Auto-correction of Misplaced HTML in URL or Image ---\n";
// User accidentally pastes an <a><img> HTML code into the URL input
$misplacedEnamadHtml = '<a target="_blank" href="https://trustseal.enamad.ir/?id=555"><img src="https://trustseal.enamad.ir/logo.aspx?id=555"></a>';
DB::setSetting('footer_badge1_url', $misplacedEnamadHtml);
DB::setSetting('footer_badge1_img', '');
DB::setSetting('footer_badge1_html', '');

$badges = fr_footer_badges();
$b1 = $badges[0];
assert_test($b1['has_badge'] === true, "Misplaced HTML in URL detected as badge");
assert_test(str_contains($b1['html'], 'trustseal.enamad.ir/?id=555'), "Misplaced HTML correctly shifted to html field");
assert_test(str_contains($b1['html'], 'referrerpolicy="origin"'), "Referrer policy automatically injected into shifted HTML");

// --- Section 5: ZarinPal Script Handling & Fallback ---
echo "\n--- Section 5: ZarinPal Script Handling & Fallback Container ---\n";
$zpScript = '<script src="https://www.zarinpal.com/webservice/TrustCode" type="text/javascript"></script>';
DB::setSetting('footer_badge3_html', $zpScript);
DB::setSetting('footer_badge3_url', '');
DB::setSetting('footer_badge3_img', '');

$badges = fr_footer_badges();
$b3 = $badges[2];
assert_test($b3['has_badge'] === true, "ZarinPal TrustCode detected as active");
assert_test(str_contains($b3['html'], 'id="zarinpal"'), "ZarinPal TrustCode wrapped in container with id='zarinpal'");

// --- Section 6: ZarinPal Direct URL to SVG Badge ---
echo "\n--- Section 6: ZarinPal Direct URL to SVG Badge ---\n";
DB::setSetting('footer_badge3_html', '');
DB::setSetting('footer_badge3_url', 'https://www.zarinpal.com/trust');
DB::setSetting('footer_badge3_img', '');

$badges = fr_footer_badges();
$b3 = $badges[2];
assert_test($b3['has_badge'] === true, "ZarinPal direct URL detected as active");
assert_test(str_contains($b3['img'], 'badges/zarinpal.svg'), "ZarinPal automatically assigned official vector badge");

// --- Section 7: Vector Badge Assets Existence ---
echo "\n--- Section 7: Vector Badge Assets Existence ---\n";
$zarinSvg = FR_ROOT . '/assets/img/badges/zarinpal.svg';
$enamadSvg = FR_ROOT . '/assets/img/badges/enamad.svg';
$samandehiSvg = FR_ROOT . '/assets/img/badges/samandehi.svg';

assert_test(file_exists($zarinSvg) && filesize($zarinSvg) > 100, "zarinpal.svg vector asset exists and valid");
assert_test(file_exists($enamadSvg) && filesize($enamadSvg) > 100, "enamad.svg vector asset exists and valid");
assert_test(file_exists($samandehiSvg) && filesize($samandehiSvg) > 100, "samandehi.svg vector asset exists and valid");

// --- Section 8: CSS Classes and Footer Output Rendering ---
echo "\n--- Section 8: Footer Output Rendering & .fb-active Styling ---\n";
ob_start();
public_footer();
$footerOutput = ob_get_clean();

assert_test(str_contains($footerOutput, 'fb-active'), "Footer output includes .fb-active class on configured badges");
assert_test(str_contains($footerOutput, 'referrerpolicy="origin"'), "Footer output includes referrerpolicy='origin' on links/images");
assert_test(str_contains($footerOutput, 'zarinpal'), "Footer output contains ZarinPal element or fallback script");

// --- Section 9: Version & Release Integrity ---
echo "\n--- Section 9: Version & Release Integrity ---\n";
assert_test(version_compare(FR_VERSION, '3.5.0', '>='), "FR_VERSION is 3.5.0 or higher");
assert_test(version_compare(FR_RELEASE, '3.5.0', '>='), "FR_RELEASE is 3.5.0 or higher");

echo "\n-------------------------------------------------------\n";
echo "QA Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n\n";

if ($failed > 0) {
    exit(1);
}
