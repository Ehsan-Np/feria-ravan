<?php
/**
 * QA Test Suite for Feria Ravan v3.5.2
 * Testing:
 * 1. Test Custom Fields Schema & Database Migration
 * 2. Real-time Conditional Logic Evaluation (equals, not_equals, checked, not_checked, in, contains)
 * 3. Server-side Conditional Validation (enforcing required only when condition is active)
 * 4. End-to-end Test Registration Flow with Custom Pre-test Responses (Free & Full mode)
 * 5. Admin Management Controller (admin/test-fields.php), Menu & Role Capabilities
 * 6. Visual Display Cards in attempts.php, clinical-case.php and orders.php
 * 7. Version & Packaging Integrity (3.5.2)
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
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/includes/commerce.php';
require_once FR_ROOT . '/core/TestFields.php';

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

echo "=== FERIA RAVAN v3.5.2 QA TEST SUITE ===\n\n";

// =========================================================================
// 1. DATABASE SCHEMA & MIGRATION
// =========================================================================
echo "1. Testing Schema & Database Migration for Test Custom Fields:\n";

TestFields::ensureSchema();

$testFieldsTable = DB::val("SELECT COUNT(*) FROM " . DB::t('test_fields'));
assert_test($testFieldsTable !== false, "Table test_fields exists in database");

$colAttempts = DB::one("SHOW COLUMNS FROM " . DB::t('attempts') . " LIKE 'custom_fields_json'");
assert_test(!empty($colAttempts), "Column attempts.custom_fields_json exists");

$colOrders = DB::one("SHOW COLUMNS FROM " . DB::t('orders') . " LIKE 'custom_fields_json'");
assert_test(!empty($colOrders), "Column orders.custom_fields_json exists");

$defaultFields = TestFields::getFieldsForTest(1, 'all');
assert_test(count($defaultFields) >= 4, "Default seeded custom fields exist (" . count($defaultFields) . " fields)");

$names = array_column($defaultFields, 'name');
assert_test(in_array('free_consultation', $names, true), "Default field 'free_consultation' seeded");
assert_test(in_array('has_companion', $names, true), "Default field 'has_companion' seeded");
assert_test(in_array('companion_name', $names, true), "Default field 'companion_name' seeded");
assert_test(in_array('companion_phone', $names, true), "Default field 'companion_phone' seeded");


// =========================================================================
// 2. CONDITIONAL LOGIC OPERATOR ENGINE
// =========================================================================
echo "\n2. Testing Conditional Logic Engine (evaluateCondition):\n";

assert_test(TestFields::evaluateCondition('yes', 'equals', 'yes'), "evaluateCondition: 'yes' equals 'yes'");
assert_test(TestFields::evaluateCondition('YES ', 'equals', 'yes'), "evaluateCondition: case-insensitive & whitespace trimmed");
assert_test(!TestFields::evaluateCondition('no', 'equals', 'yes'), "evaluateCondition: 'no' does not equal 'yes'");

assert_test(TestFields::evaluateCondition('yes', 'not_equals', 'no'), "evaluateCondition: 'yes' not_equals 'no'");
assert_test(!TestFields::evaluateCondition('yes', 'not_equals', 'yes'), "evaluateCondition: 'yes' not_equals 'yes' is false");

assert_test(TestFields::evaluateCondition('1', 'checked', null), "evaluateCondition: '1' is checked");
assert_test(TestFields::evaluateCondition('بله', 'checked', null), "evaluateCondition: 'بله' is checked");
assert_test(!TestFields::evaluateCondition('0', 'checked', null), "evaluateCondition: '0' is not checked");

assert_test(TestFields::evaluateCondition('0', 'not_checked', null), "evaluateCondition: '0' is not_checked");
assert_test(TestFields::evaluateCondition('', 'not_checked', null), "evaluateCondition: '' is not_checked");
assert_test(!TestFields::evaluateCondition('1', 'not_checked', null), "evaluateCondition: '1' is not_checked is false");

assert_test(TestFields::evaluateCondition('بله', 'in', 'yes,بله,1'), "evaluateCondition: 'بله' in 'yes,بله,1'");
assert_test(TestFields::evaluateCondition('yes', 'in', 'yes,بله,1'), "evaluateCondition: 'yes' in 'yes,بله,1'");
assert_test(!TestFields::evaluateCondition('خیر', 'in', 'yes,بله,1'), "evaluateCondition: 'خیر' in 'yes,بله,1' is false");

assert_test(TestFields::evaluateCondition('بله، همراه دارم', 'contains', 'همراه'), "evaluateCondition: 'بله، همراه دارم' contains 'همراه'");
assert_test(!TestFields::evaluateCondition('خیر، تنها هستم', 'contains', 'همراه'), "evaluateCondition: 'خیر، تنها هستم' contains 'همراه' is false");


// =========================================================================
// 3. SERVER-SIDE CONDITIONAL VALIDATION & ERROR MESSAGES
// =========================================================================
echo "\n3. Testing Server-side Validation with Conditional Dependencies:\n";

// Scenario A: User selects NO companion
$resA = TestFields::validateAndProcess(1, 'all', [
    'test_custom_fields' => [
        'free_consultation' => 'no',
        'has_companion'     => 'no',
        'companion_name'    => '',
        'companion_phone'   => '',
    ]
]);
assert_test($resA['ok'] === true, "Scenario A: Form valid when has_companion is 'no' without companion info");
assert_test(isset($resA['data']['free_consultation']), "Scenario A: free_consultation captured");
assert_test(isset($resA['data']['has_companion']), "Scenario A: has_companion captured");
assert_test(!isset($resA['data']['companion_name']), "Scenario A: dependent companion_name correctly omitted");
assert_test(!isset($resA['data']['companion_phone']), "Scenario A: dependent companion_phone correctly omitted");

// Scenario B: User selects YES companion, but leaves companion name and phone empty
$resB = TestFields::validateAndProcess(1, 'all', [
    'test_custom_fields' => [
        'free_consultation' => 'yes',
        'has_companion'     => 'yes',
        'companion_name'    => '',
        'companion_phone'   => '',
    ]
]);
assert_test($resB['ok'] === false, "Scenario B: Form invalid when has_companion is 'yes' but companion details missing");
assert_test(count($resB['errors']) >= 2, "Scenario B: Correctly reports multiple required errors (" . count($resB['errors']) . ")");

$errStr = implode(' ', $resB['errors']);
assert_test(str_contains($errStr, 'نام و نام خانوادگی همراه'), "Scenario B: Error specifies companion_name label");
assert_test(str_contains($errStr, 'شماره تلفن همراهِ همراه'), "Scenario B: Error specifies companion_phone label");

// Scenario C: User enters invalid phone format
$resC = TestFields::validateAndProcess(1, 'all', [
    'test_custom_fields' => [
        'free_consultation' => 'yes',
        'has_companion'     => 'yes',
        'companion_name'    => 'رضا علوی',
        'companion_phone'   => '12345', // invalid phone
    ]
]);
assert_test($resC['ok'] === false, "Scenario C: Form invalid with invalid phone number format");
assert_test(str_contains(implode(' ', $resC['errors']), 'شماره تماس'), "Scenario C: Phone validation error returned");

// Scenario D: User completes all fields accurately
$resD = TestFields::validateAndProcess(1, 'all', [
    'test_custom_fields' => [
        'free_consultation' => 'yes',
        'has_companion'     => 'yes',
        'companion_name'    => 'سارا حسینی',
        'companion_phone'   => '09121112233',
    ]
]);
assert_test($resD['ok'] === true, "Scenario D: Form valid with complete companion info");
assert_test($resD['data']['companion_name']['value'] === 'سارا حسینی', "Scenario D: companion_name saved accurately");
assert_test($resD['data']['companion_phone']['value'] === '09121112233', "Scenario D: companion_phone saved accurately");
assert_test(!empty($resD['json']), "Scenario D: JSON string generated for persistence");


// =========================================================================
// 4. END-TO-END WORKFLOW SIMULATION WITH ATTEMPTS & ORDERS
// =========================================================================
echo "\n4. Testing End-to-End Attempt & Order Persistence:\n";

$testRow = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE id=1");
$userRow = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=1");

// A. Insert Free Attempt with Custom Fields
$customJson = $resD['json'];
$attemptId = DB::insert('attempts', [
    'user_id'            => $userRow['id'],
    'test_id'            => $testRow['id'],
    'mode'               => 'free',
    'status'             => 'in_progress',
    'custom_fields_json' => $customJson,
    'ip'                 => client_ip_bin(),
    'user_agent'         => 'QA Runner',
]);
assert_test($attemptId > 0, "Inserted attempt with custom_fields_json (ID: {$attemptId})");

$savedAttempt = DB::one("SELECT * FROM " . DB::t('attempts') . " WHERE id=?", [$attemptId]);
assert_test(!empty($savedAttempt['custom_fields_json']), "Saved attempt custom_fields_json is not empty");
$decodedAtt = json_decode((string)$savedAttempt['custom_fields_json'], true);
assert_test(isset($decodedAtt['companion_name']) && $decodedAtt['companion_name']['value'] === 'سارا حسینی', "Attempt custom_fields_json contains companion_name");

// B. Create Full Order with Custom Fields
$freeTestRow = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE price_full=0 LIMIT 1");
if (!$freeTestRow) {
    $ftId = DB::insert('tests', [
        'title' => 'تست رایگان آزمایشی',
        'slug' => 'qa-free-test-' . time(),
        'engine' => 'disc',
        'status' => 'active',
        'price_full' => 0,
        'free_enabled' => 1,
    ]);
    $freeTestRow = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE id=?", [$ftId]);
}
$orderRes = create_full_order($userRow, $freeTestRow, null, false, null, $customJson);
assert_test(!empty($orderRes['order']['id']), "create_full_order succeeded (Order ID: " . ($orderRes['order']['id'] ?? '') . ")");

$savedOrder = DB::one("SELECT * FROM " . DB::t('orders') . " WHERE id=?", [$orderRes['order']['id']]);
assert_test(!empty($savedOrder['custom_fields_json']), "Order saved custom_fields_json");


// =========================================================================
// 5. HTML FORM RENDERING & CLIENT SCRIPT GENERATION
// =========================================================================
echo "\n5. Testing HTML Form & Client Script Generation:\n";

$formHtml = TestFields::renderFormFields($defaultFields, [
    'free_consultation' => 'yes',
    'has_companion'     => 'no',
]);
assert_test(str_contains($formHtml, 'id="testCustomFieldsBox"'), "renderFormFields generates container id");
assert_test(str_contains($formHtml, 'data-cond-field="has_companion"'), "renderFormFields renders data-cond-field attribute");
assert_test(str_contains($formHtml, 'data-cond-op="in"'), "renderFormFields renders data-cond-op attribute");
assert_test(str_contains($formHtml, 'data-cond-val='), "renderFormFields renders data-cond-val attribute");
assert_test(str_contains($formHtml, 'initTestConditionalFields'), "renderFormFields embeds client-side reactive script");


// =========================================================================
// 6. VISUAL DISPLAY CARDS (attempts.php, clinical-case.php)
// =========================================================================
echo "\n6. Testing Visual Display Card Generation:\n";

$displayCard = TestFields::renderDisplayCard($customJson, 'اطلاعات ثبت‌شده پیش از آزمون مراجع');
assert_test(str_contains($displayCard, 'fr-pretest-data-card'), "renderDisplayCard renders .fr-pretest-data-card");
assert_test(str_contains($displayCard, 'سارا حسینی'), "renderDisplayCard displays companion_name value");
assert_test(str_contains($displayCard, '09121112233'), "renderDisplayCard displays companion_phone value");
assert_test(str_contains($displayCard, 'tel:09121112233'), "renderDisplayCard formats phone as clickable tel: link");


// =========================================================================
// 7. ADMIN INTERFACES, ROLES & MENU INTEGRATION
// =========================================================================
echo "\n7. Testing Admin Controllers, Roles & Menu Integration:\n";

$capMap = fr_page_cap_map();
assert_test(isset($capMap['test-fields.php']) && $capMap['test-fields.php'] === 'tests', "roles.php maps test-fields.php to 'tests' cap");

$bootContent = file_get_contents(FR_ROOT . '/admin/boot.php');
assert_test(str_contains($bootContent, "'test-fields'"), "admin/boot.php includes test-fields in menu and grp-tests");

$testsContent = file_get_contents(FR_ROOT . '/admin/tests.php');
assert_test(str_contains($testsContent, 'test-fields.php'), "admin/tests.php links to test-fields.php");

$attemptsContent = file_get_contents(FR_ROOT . '/admin/attempts.php');
assert_test(str_contains($attemptsContent, 'renderDisplayCard'), "admin/attempts.php integrates TestFields::renderDisplayCard");

$caseContent = file_get_contents(FR_ROOT . '/admin/clinical-case.php');
assert_test(str_contains($caseContent, 'renderDisplayCard'), "admin/clinical-case.php integrates TestFields::renderDisplayCard");

$ordersContent = file_get_contents(FR_ROOT . '/admin/orders.php');
assert_test(str_contains($ordersContent, 'custom_fields_json'), "admin/orders.php displays custom fields chip");

$startContent = file_get_contents(FR_ROOT . '/panel/test-start.php');
assert_test(str_contains($startContent, 'TestFields::renderFormFields'), "panel/test-start.php renders TestFields form");
assert_test(str_contains($startContent, 'TestFields::validateAndProcess'), "panel/test-start.php validates TestFields on submit");


// =========================================================================
// 8. VERSION & ARCHITECTURE INTEGRITY
// =========================================================================
echo "\n8. Testing Version & Architecture Integrity:\n";

assert_test(FR_RELEASE === '3.5.2', "includes/version.php sets FR_RELEASE to 3.5.2");
$configContent = file_get_contents(FR_ROOT . '/config.php');
assert_test(str_contains($configContent, '3.5.2'), "config.php default version matches 3.5.2");
$updateContent = file_get_contents(FR_ROOT . '/includes/update.php');
assert_test(str_contains($updateContent, '۳٫۵٫۲'), "includes/update.php contains 3.5.2 migration hook");


// =========================================================================
// 9. REQUIRED VS OPTIONAL (اجباری / غیر اجباری) FIELDS SPECIFICATION
// =========================================================================
echo "\n9. Testing Required vs Optional (اجباری / غیر اجباری) Fields Behavior:\n";

// ایجاد یک فیلد اختیاری و یک فیلد اجباری در تست شماره ۱
DB::run("DELETE FROM " . DB::t('test_fields') . " WHERE name IN ('qa_opt_field', 'qa_req_field')");
$optFieldId = DB::insert('test_fields', [
    'test_id'     => 1,
    'label'       => 'توضیحات اختیاری مراجع',
    'name'        => 'qa_opt_field',
    'type'        => 'text',
    'required'    => 0, // اختیاری (غیر اجباری)
    'sort_order'  => 50,
    'active'      => 1
]);
$reqFieldId = DB::insert('test_fields', [
    'test_id'     => 1,
    'label'       => 'کد پرونده اجباری',
    'name'        => 'qa_req_field',
    'type'        => 'text',
    'required'    => 1, // اجباری
    'sort_order'  => 51,
    'active'      => 1
]);
assert_test($optFieldId > 0 && $reqFieldId > 0, "Created test fixtures for optional and required fields");

// سناریوی الف: ارسال فیلد اختیاری به صورت خالی بدون خطا باید عبور کند
$subOptional = [
    'test_custom_fields' => [
        'free_consultation' => 'no',
        'has_companion'     => 'no',
        'qa_opt_field'      => '', // خالی به عنوان اختیاری
        'qa_req_field'      => 'FILE-9988', // پر شده برای جلوگیری از خطای فیلد اجباری
    ]
];
$resOpt = TestFields::validateAndProcess(1, 'all', $subOptional);
assert_test($resOpt['ok'] === true, "Optional field left empty passes validation cleanly with ok=true");
assert_test(empty($resOpt['errors']), "No errors generated for empty optional field");

// سناریوی ب: خالی گذاشتن فیلد اجباری باید منجر به خطای صریح شود
$subReqMissing = [
    'test_custom_fields' => [
        'free_consultation' => 'no',
        'has_companion'     => 'no',
        'qa_opt_field'      => '',
        'qa_req_field'      => '', // خالی ماندن فیلد اجباری
    ]
];
$resReq = TestFields::validateAndProcess(1, 'all', $subReqMissing);
assert_test($resReq['ok'] === false, "Missing required field produces validation failure");
assert_test(str_contains(implode(' ', $resReq['errors']), 'کد پرونده اجباری'), "Error message explicitly references required field label");

// سناریوی ج: تست جابجایی وضعیت الزام (toggle_required)
DB::run("UPDATE " . DB::t('test_fields') . " SET required = 1 - required WHERE id=?", [$optFieldId]);
$updatedOptField = DB::one("SELECT required FROM " . DB::t('test_fields') . " WHERE id=?", [$optFieldId]);
assert_test((int)$updatedOptField['required'] === 1, "Field toggled from optional (0) to required (1)");

DB::run("UPDATE " . DB::t('test_fields') . " SET required = 1 - required WHERE id=?", [$optFieldId]);
$restoredOptField = DB::one("SELECT required FROM " . DB::t('test_fields') . " WHERE id=?", [$optFieldId]);
assert_test((int)$restoredOptField['required'] === 0, "Field toggled back from required (1) to optional (0)");

// سناریوی د: بررسی رندر نشان‌های «الزامی» و «اختیاری» در فرم کاربری
$renderedHtml = TestFields::renderFormFields([
    ['id' => 101, 'name' => 'req_demo', 'label' => 'فیلد اجباری دمو', 'type' => 'text', 'required' => 1, 'active' => 1],
    ['id' => 102, 'name' => 'opt_demo', 'label' => 'فیلد اختیاری دمو', 'type' => 'text', 'required' => 0, 'active' => 1],
]);
assert_test(str_contains($renderedHtml, 'tf-badge-req'), "renderFormFields renders 'tf-badge-req' badge for required field");
assert_test(str_contains($renderedHtml, 'tf-badge-opt'), "renderFormFields renders 'tf-badge-opt' badge for optional field");
assert_test(str_contains($renderedHtml, 'الزامی'), "renderFormFields includes Persian 'الزامی' indicator");
assert_test(str_contains($renderedHtml, 'اختیاری'), "renderFormFields includes Persian 'اختیاری' indicator");

// سناریوی هـ: اعتبارسنجی کدهای مدیریت پنل
$adminTestFieldsCode = file_get_contents(FR_ROOT . '/admin/test-fields.php');
assert_test(str_contains($adminTestFieldsCode, 'toggle_required'), "admin/test-fields.php contains toggle_required POST handler");
assert_test(str_contains($adminTestFieldsCode, 'غیر اجباری (اختیاری)'), "admin/test-fields.php includes Persian 'غیر اجباری (اختیاری)' option");
assert_test(str_contains($adminTestFieldsCode, 'اجباری (الزامی)'), "admin/test-fields.php includes Persian 'اجباری (الزامی)' option");

$adminProfileFieldsCode = file_get_contents(FR_ROOT . '/admin/fields.php');
assert_test(str_contains($adminProfileFieldsCode, 'toggle_required'), "admin/fields.php contains toggle_required POST handler");
assert_test(str_contains($adminProfileFieldsCode, 'غیر اجباری (اختیاری)'), "admin/fields.php includes Persian 'غیر اجباری (اختیاری)' option");

// پاکسازی فیلدهای تستی
DB::run("DELETE FROM " . DB::t('test_fields') . " WHERE name IN ('qa_opt_field', 'qa_req_field')");


// Summary
echo "\n==========================================\n";
echo "QA SUITE COMPLETED: {$passed} PASSED, {$failed} FAILED\n";
echo "==========================================\n";

if ($failed > 0) {
    exit(1);
}
