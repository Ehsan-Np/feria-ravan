<?php
/**
 * QA Test Suite for:
 * 1. Dedicated Edit Screens across all entities (No list/table underneath, no target="_blank")
 * 2. Universal Back to Top Floating Button (CSS & JS)
 */
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

// Find or create admin
$adm = DB::one("SELECT id FROM " . DB::t('admins') . " WHERE role='superadmin' LIMIT 1");
if (!$adm) {
    $aid = DB::insert('admins', [
        'username' => 'test_qa_admin',
        'password' => password_hash('Pass1234!', PASSWORD_DEFAULT),
        'role' => 'superadmin',
        'display_name' => 'مدیر تست',
        'email' => 'admin@test.ir'
    ]);
    $adminId = $aid;
} else {
    $adminId = (int)$adm['id'];
}
$_SESSION['admin_id'] = $adminId;

require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/admin-auth.php';

echo "=== 1. Testing Tests Management Edit Screen ===\n";

// Check tests file content directly and simulated
$testsPhp = file_get_contents(FR_ROOT . '/admin/tests.php');

// 1. Check edit link in table does NOT contain target="_blank"
assert(!str_contains($testsPhp, 'target="_blank" title="ویرایش تست در برگهٔ جدید"'), "Edit link must NOT have target=_blank");
assert(str_contains($testsPhp, 'title="ویرایش تست روانشناسی">ویرایش</a>'), "Edit link must be clean");
echo "  [PASS] Edit link does not have target='_blank' (opens in same page)\n";

// 2. Check edit view has back button and stops execution before table
assert(str_contains($testsPhp, '← بازگشت به فهرست تست‌ها'), "Edit view must have back button");
assert(str_contains($testsPhp, 'admin_layout_end();' . "\n" . 'exit;' . "\n" . 'endif;'), "Edit view must exit before rendering tests list table");
echo "  [PASS] Edit view shows ONLY test edit form (table and other content are completely hidden)\n";

echo "\n=== 2. Testing Workshops and Courses Shared Edit Screen ===\n";
$eventsFile = file_get_contents(FR_ROOT . '/includes/admin-events-shared.php');
assert(!str_contains($eventsFile, 'target="_blank" title="ویرایش در برگهٔ جدید"'), "Event edit link must not open in new tab");
assert(str_contains($eventsFile, '← بازگشت به فهرست'), "Event edit screen must have back button");
assert(str_contains($eventsFile, 'admin_layout_end();' . "\n" . '  return;' . "\n" . '  endif;'), "Event edit view must exit before rendering events list table");
echo "  [PASS] Events edit screen verified: no target=_blank, isolated form, clear back navigation\n";

echo "\n=== 3. Testing Questions Edit Screen ===\n";
$questionsFile = file_get_contents(FR_ROOT . '/admin/questions.php');
assert(str_contains($questionsFile, '✏️ ویرایش سؤال شماره'), "Edit questions view must show edit header");
assert(str_contains($questionsFile, '← بازگشت به فهرست سوالات'), "Edit questions view must have back button");
assert(str_contains($questionsFile, 'if ($editQ) {' . "\n" . '    admin_layout_end();' . "\n" . '    exit;' . "\n" . '}'), "Questions edit view must exit before rendering table");
echo "  [PASS] Question edit view shows ONLY the question edit form without list underneath\n";

echo "\n=== 4. Testing Templates Edit Screen ===\n";
$templatesFile = file_get_contents(FR_ROOT . '/admin/templates.php');
assert(str_contains($templatesFile, '✏️ ویرایش قالب تحلیل'), "Edit template view must show edit header");
assert(str_contains($templatesFile, '← بازگشت به فهرست قالب‌ها'), "Edit template view must have back button");
assert(str_contains($templatesFile, 'if ($editT) {' . "\n" . '    admin_layout_end();' . "\n" . '    exit;' . "\n" . '}'), "Template edit view must exit before rendering table");
echo "  [PASS] Template edit view shows ONLY the template edit form without list underneath\n";

echo "\n=== 5. Testing Discounts, Banners, Consult, Fields & Roles Edit Screens ===\n";
// Discounts
$discountsFile = file_get_contents(FR_ROOT . '/admin/discounts.php');
assert(str_contains($discountsFile, '✏️ ویرایش کد تخفیف'), "Edit discount view must show edit header");
assert(str_contains($discountsFile, '← بازگشت به فهرست کدهای تخفیف'), "Edit discount view must have back button");
assert(str_contains($discountsFile, 'if ($editD) {' . "\n" . '    admin_layout_end();' . "\n" . '    exit;' . "\n" . '}'), "Discount edit view must exit before table");
echo "  [PASS] Discount edit view isolated cleanly without table underneath\n";

// Banners
$bannersFile = file_get_contents(FR_ROOT . '/admin/banners.php');
assert(str_contains($bannersFile, '✏️ ویرایش بنر:'), "Edit banner view must show edit header");
assert(str_contains($bannersFile, '← بازگشت به فهرست بنرها'), "Edit banner view must have back button");
assert(str_contains($bannersFile, 'admin_layout_end();' . "\n" . '  exit;' . "\n" . 'endif;'), "Banner edit view must exit before table");
echo "  [PASS] Banner edit view isolated cleanly without table underneath\n";

// Fields
$fieldsFile = file_get_contents(FR_ROOT . '/admin/fields.php');
assert(str_contains($fieldsFile, '✏️ ویرایش فیلد:'), "Edit field view must show edit header");
assert(str_contains($fieldsFile, '← بازگشت به فهرست فیلدها'), "Edit field view must have back button");
assert(str_contains($fieldsFile, 'admin_layout_end();' . "\n" . '  exit;' . "\n" . 'endif;'), "Field edit view must exit before table");
echo "  [PASS] Field edit view isolated cleanly without table underneath\n";

// Roles
$rolesFile = file_get_contents(FR_ROOT . '/admin/roles.php');
assert(str_contains($rolesFile, '✏️ ویرایش نقش:'), "Edit role view must show edit header");
assert(str_contains($rolesFile, '← بازگشت به فهرست نقش‌ها'), "Edit role view must have back button");
assert(str_contains($rolesFile, 'admin_layout_end();' . "\n" . 'exit;' . "\n" . 'endif;'), "Role edit view must exit before table");
echo "  [PASS] Role edit view isolated cleanly without table underneath\n";

echo "\n=== 6. Testing Universal Back to Top Button ===\n";
$cssContent = file_get_contents(FR_ROOT . '/assets/css/feria.css');
assert(str_contains($cssContent, '.fr-back-to-top'), "feria.css must contain .fr-back-to-top rule");
assert(str_contains($cssContent, '.fr-back-to-top.show'), "feria.css must contain .show state for button");

$jsContent = file_get_contents(FR_ROOT . '/assets/js/feria.js');
assert(str_contains($jsContent, 'frBackToTop'), "feria.js must define frBackToTop element");
assert(str_contains($jsContent, "window.scrollTo({\n          top: 0,\n          behavior: 'smooth'"), "feria.js must implement smooth window.scrollTo");

// In report templates:
$repContent = file_get_contents(FR_ROOT . '/includes/report.php');
assert(str_contains($repContent, 'بازگشت به بالای کارنامه'), "Report includes explicit back to top button");

$dyadRepContent = file_get_contents(FR_ROOT . '/panel/dyad-report.php');
assert(str_contains($dyadRepContent, 'بازگشت به بالای کارنامه تطابق'), "Dyad report includes explicit back to top button");

echo "  [PASS] Universal Back to Top button styles and script verified across CSS, JS, and report templates\n";

echo "\n===================================================================\n";
echo "🎉 ALL DEDICATED EDIT SCREENS AND BACK TO TOP TESTS PASSED 100%!\n";
echo "===================================================================\n";
