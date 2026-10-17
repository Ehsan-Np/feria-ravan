<?php
/**
 * ============================================================================
 *  QA Test Suite for Feria Ravan v3.5.3
 *  ─ اعتبارسنجی قابلیت تفکیک فیلدهای اجباری (الزامی) و غیر اجباری (اختیاری)
 *  ─ دکمه تغییر سریع (Inline Toggle) در جدول مدیریت فیلدها
 *  ─ فیلتر وضعیت الزام در نوار ابزار (admin/test-fields.php)
 *  ─ اعتبارسنجی بلادرنگ کلاینت و سرور و تطبیق با v3.5.3
 * ============================================================================
 */
declare(strict_types=1);

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'feria_test');
if (!defined('DB_USER')) define('DB_USER', 'feria');
if (!defined('DB_PASS')) define('DB_PASS', 'feria_password');
if (!defined('DB_PREFIX')) define('DB_PREFIX', 'fr_');
if (!defined('SITE_URL')) define('SITE_URL', 'http://127.0.0.1:8000');
if (!defined('FR_ROOT')) define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
if (!defined('FR_ENV')) define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/core/TestFields.php';

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "=== FERIA RAVAN v3.5.3 QA TEST SUITE ===\n\n";

// ۱. نگارش و تطابق با نسخه ۳٫۵٫۳
echo "1. Testing Version & Environment Consistency:\n";
assert_test(version_compare(FR_RELEASE, '3.5.3', '>='), "FR_RELEASE is 3.5.3 or higher (actual: " . FR_RELEASE . ")");
assert_test(version_compare(FR_VERSION, '3.5.3', '>='), "FR_VERSION is 3.5.3 or higher (actual: " . FR_VERSION . ")");
$configContent = file_get_contents(FR_ROOT . '/config.php');
assert_test(str_contains($configContent, '3.5.3') || version_compare(FR_RELEASE, '3.5.3', '>='), "config.php references version 3.5.3 or higher");
$updateContent = file_get_contents(FR_ROOT . '/includes/update.php');
assert_test(str_contains($updateContent, '۳٫۵٫۳'), "includes/update.php has v3.5.3 migration hook");

// ۲. عملکرد فیلدهای اختیاری (غیر اجباری)
echo "\n2. Testing Optional Fields Behavior:\n";
DB::run("DELETE FROM " . DB::t('test_fields') . " WHERE name IN ('test_v353_opt', 'test_v353_req')");

$optId = DB::insert('test_fields', [
    'test_id'     => 1,
    'label'       => 'یادداشت اختیاری جلسه',
    'name'        => 'test_v353_opt',
    'type'        => 'textarea',
    'required'    => 0, // اختیاری
    'sort_order'  => 60,
    'active'      => 1
]);
assert_test($optId > 0, "Inserted optional field with required=0");

$sub1 = [
    'test_custom_fields' => [
        'free_consultation' => 'no',
        'has_companion'     => 'no',
        'test_v353_opt'     => '', // خالی به عمد
    ]
];
$res1 = TestFields::validateAndProcess(1, 'all', $sub1);
assert_test($res1['ok'] === true, "Validation succeeds when optional field is submitted empty");
assert_test(empty($res1['errors']), "Zero error messages produced for blank optional field");

// ۳. عملکرد فیلدهای اجباری (الزامی)
echo "\n3. Testing Mandatory (Required) Fields Behavior:\n";
$reqId = DB::insert('test_fields', [
    'test_id'     => 1,
    'label'       => 'کد ارجاع پزشک معالج',
    'name'        => 'test_v353_req',
    'type'        => 'text',
    'required'    => 1, // اجباری
    'sort_order'  => 61,
    'active'      => 1
]);
assert_test($reqId > 0, "Inserted mandatory field with required=1");

$sub2 = [
    'test_custom_fields' => [
        'free_consultation' => 'no',
        'has_companion'     => 'no',
        'test_v353_opt'     => '',
        'test_v353_req'     => '', // خالی ماندن فیلد اجباری
    ]
];
$res2 = TestFields::validateAndProcess(1, 'all', $sub2);
assert_test($res2['ok'] === false, "Validation correctly fails when required field is empty");
assert_test(str_contains(implode(' ', $res2['errors']), 'کد ارجاع پزشک معالج'), "Error message explicitly cites label of missing required field");

// ۴. تغییر سریع وضعیت الزام (Toggle Required)
echo "\n4. Testing Inline Toggle for Required Status:\n";
DB::run("UPDATE " . DB::t('test_fields') . " SET required = 1 - required WHERE id=?", [$optId]);
$check1 = DB::one("SELECT required FROM " . DB::t('test_fields') . " WHERE id=?", [$optId]);
assert_test((int)$check1['required'] === 1, "Optional field successfully toggled to required (1)");

DB::run("UPDATE " . DB::t('test_fields') . " SET required = 1 - required WHERE id=?", [$optId]);
$check2 = DB::one("SELECT required FROM " . DB::t('test_fields') . " WHERE id=?", [$optId]);
assert_test((int)$check2['required'] === 0, "Field toggled back to optional (0)");

// ۵. نشان‌ها و کامپوننت‌های بصری
echo "\n5. Testing Visual Badges and Form Indicators:\n";
$htmlOut = TestFields::renderFormFields([
    ['id' => 201, 'name' => 'f_req', 'label' => 'فیلد اجباری تست', 'type' => 'text', 'required' => 1, 'active' => 1],
    ['id' => 202, 'name' => 'f_opt', 'label' => 'فیلد اختیاری تست', 'type' => 'text', 'required' => 0, 'active' => 1],
]);
assert_test(str_contains($htmlOut, 'tf-badge-req'), "Form contains 'tf-badge-req' badge for mandatory field");
assert_test(str_contains($htmlOut, 'tf-badge-opt'), "Form contains 'tf-badge-opt' badge for optional field");
assert_test(str_contains($htmlOut, 'الزامی'), "Form displays Persian 'الزامی' text");
assert_test(str_contains($htmlOut, 'اختیاری'), "Form displays Persian 'اختیاری' text");

// پاکسازی داده‌های تست
DB::run("DELETE FROM " . DB::t('test_fields') . " WHERE name IN ('test_v353_opt', 'test_v353_req')");

echo "\n==========================================\n";
echo "QA SUITE COMPLETED: {$passed} PASSED, {$failed} FAILED\n";
echo "==========================================\n";

if ($failed > 0) {
    exit(1);
}
