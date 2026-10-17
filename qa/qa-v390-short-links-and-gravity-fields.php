<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت: نگارش ۳٫۹٫۰
 *  (QA Test Suite: v3.9.0 - Ultra-Short Links, Gravity Fields & Post-Test Consultation)
 * ============================================================================
 *  ۱. بررسی تولید و هدایت پیوندهای کوتاه (/t/{id}، /s/{id}، /s/{slug}) و دکمه‌های کپی
 *  ۲. بررسی معماری فیلدهای سفارشی به سبک Pronamic / Gravity Forms (چک‌باکس چندگزینه‌ای و رادیوباتن)
 *  ۳. بررسی منطق شرطی پیشرفته روی چک‌باکس‌های چندانتخابی (contains, in, equals, is_empty)
 *  ۴. بررسی تفکیک گام اجرا و زمان‌بندی: پایان آزمون (post_test) در برابر آغاز آزمون (pre_test)
 *  ۵. بررسی جریان ثبت مشاوره رایگان پایان آزمون و اعتبارسنجی بلادرنگ سرور در api/attempt.php
 *  ۶. بررسی عدم خطای تبدیل آرایه به رشته در رندر کارنامه و پنل مدیریت
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
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/seo.php';
require_once FR_ROOT . '/core/TestFields.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('FRSESSID');
    session_id(bin2hex(random_bytes(16)));
    session_start();
}

$total = 0;
$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $desc, string $info = ''): void {
    global $total, $passed, $failed;
    $total++;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($info ? " -> {$info}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "  شروع آزمون‌های تضمین کیفیت فریا روان نگارش ۳٫۹٫۰ (v3.9.0)\n";
echo "====================================================================\n\n";

// اطمینان از ایجاد اسکیما
TestFields::ensureSchema();

// --------------------------------------------------------------------
// بخش ۱: بررسی تولید و هدایت لینک‌های کوتاه (/t/{id}، /s/{id})
// --------------------------------------------------------------------
echo "--- بخش ۱: پیوندهای کوتاه تست‌ها و رویدادها ---\n";

$mockTest = ['id' => 42, 'slug' => 'disc-personality', 'title' => 'تست دیسک'];
$shortUrl = fr_test_short_url($mockTest);
$tinyUrl  = fr_test_tiny_url($mockTest);
$idShort  = fr_test_short_url(42);

assert_test($shortUrl === SITE_URL . '/t/42', 'تولید نشانی کوتاه آزمون با آرایه (/t/42)', $shortUrl);
assert_test($tinyUrl === SITE_URL . '/s/42', 'تولید نشانی فوق‌کوتاه آزمون (/s/42)', $tinyUrl);
assert_test($idShort === SITE_URL . '/t/42', 'تولید نشانی کوتاه آزمون با شناسه عددی مستقیم', $idShort);

// بررسی بازتولید فایل .htaccess با قوانین جدید
$flushRes = fr_permalinks_flush();
assert_test(!empty($flushRes['ok']), 'بازتولید موفق پیوندهای یکتا در فایل .htaccess');

$htaccessContent = (string)file_get_contents(FR_ROOT . '/.htaccess');
assert_test(str_contains($htaccessContent, 'RewriteRule ^t/([0-9]+)/?$ test-page.php?id=$1 [L,QSA]'), 'وجود قاعده بازنویسی عددی /t/{id} در .htaccess');
assert_test(str_contains($htaccessContent, 'RewriteRule ^s/([0-9]+)/?$ test-page.php?id=$1 [L,QSA]'), 'وجود قاعده بازنویسی عددی /s/{id} در .htaccess');
assert_test(str_contains($htaccessContent, 'RewriteRule ^s/([^/]+)/?$ test-page.php?s=$1 [L,QSA]'), 'وجود قاعده بازنویسی متنی /s/{slug} در .htaccess');

// --------------------------------------------------------------------
// بخش ۲: بررسی معماری فیلدها و تجزیه گزینه‌ها (Gravity Forms Options Parser)
// --------------------------------------------------------------------
echo "\n--- بخش ۲: تجزیه گزینه‌ها به سبک Gravity Forms ---\n";

// ورودی JSON ساختاریافته
$jsonOpts = json_encode([
    ['key' => 'yes_individual', 'label' => 'مشاوره فردی'],
    ['key' => 'yes_career', 'label' => 'مشاوره شغلی'],
], JSON_UNESCAPED_UNICODE);
$parsedJson = TestFields::parseOptions($jsonOpts);
assert_test(count($parsedJson) === 2, 'تجزیه گزینه‌های JSON دو گزینه‌ای');
assert_test($parsedJson[0]['key'] === 'yes_individual' && $parsedJson[0]['label'] === 'مشاوره فردی', 'صحت کلید و عنوان گزینه اول JSON');

// ورودی متنی چندخطی
$textOpts = "morning: صبح (۹ تا ۱۳)\nevening: عصر (۱۵ تا ۱۹)\nساعات اداری";
$parsedText = TestFields::parseOptions($textOpts);
assert_test(count($parsedText) === 3, 'تجزیه گزینه‌های متنی ۳ سطری');
assert_test($parsedText[0]['key'] === 'morning' && $parsedText[0]['label'] === 'صبح (۹ تا ۱۳)', 'استخراج کلید و عنوان با جداکننده دونقطه');
assert_test($parsedText[2]['key'] === 'ساعات اداری' && $parsedText[2]['label'] === 'ساعات اداری', 'استخراج مقدار ساده بدون جداکننده دونقطه');

// ورودی آرایه خام
$arrOpts = ['opt1' => 'گزینه یک', 'opt2' => 'گزینه دو'];
$parsedArr = TestFields::parseOptions($arrOpts);
assert_test(count($parsedArr) === 2, 'تجزیه آرایه انجمنی به ساختار گزینه‌ها');

// --------------------------------------------------------------------
// بخش ۳: بررسی چک‌باکس‌های چندگزینه‌ای و رادیوباتن‌ها
// --------------------------------------------------------------------
echo "\n--- بخش ۳: چک‌باکس چندگزینه‌ای و رادیوباتن ---\n";

$fieldsToRender = [
    [
        'id'                 => 101,
        'test_id'            => 0,
        'label'              => 'آیا به مشاوره رایگان نیاز دارید؟',
        'name'               => 'free_consultation',
        'type'               => 'checkbox',
        'options'            => $jsonOpts,
        'placeholder'        => 'انتخاب کنید',
        'help_text'          => 'راهنمای مشاوره',
        'required'           => 1,
        'condition_field'    => null,
        'condition_operator' => 'equals',
        'condition_value'    => null,
        'parsed_options'     => $parsedJson,
    ],
    [
        'id'                 => 102,
        'test_id'            => 0,
        'label'              => 'زمان پیشنهادی',
        'name'               => 'consult_time',
        'type'               => 'radio',
        'options'            => null,
        'placeholder'        => '',
        'help_text'          => '',
        'required'           => 1,
        'condition_field'    => 'free_consultation',
        'condition_operator' => 'contains',
        'condition_value'    => 'yes',
        'parsed_options'     => [
            ['key' => 'm', 'label' => 'صبح'],
            ['key' => 'e', 'label' => 'عصر'],
        ],
    ]
];

$renderedHtml = TestFields::renderFormFields($fieldsToRender, [
    'free_consultation' => ['yes_individual']
], 'testCustomBoxQA');

assert_test(str_contains($renderedHtml, 'name="test_custom_fields[free_consultation][]"'), 'رندر ورودی چک‌باکس چندگزینه‌ای با فرمت آرایه‌ای []');
assert_test(str_contains($renderedHtml, 'value="yes_individual"') && str_contains($renderedHtml, 'checked'), 'علامت‌گذاری خودکار گزینه انتخاب‌شده در چک‌باکس');
assert_test(str_contains($renderedHtml, 'name="test_custom_fields[consult_time]"'), 'رندر رادیوباتن با فرمت تک‌انتخابی');
assert_test(str_contains($renderedHtml, 'data-cond-field="free_consultation"'), 'اتصال اتریبیوت شرطی به فیلد والد');
assert_test(str_contains($renderedHtml, 'data-cond-op="contains"'), 'اتصال عملگر contains در منطق شرطی');

// --------------------------------------------------------------------
// بخش ۴: بررسی منطق شرطی پیشرفته (Gravity Forms Conditional Logic)
// --------------------------------------------------------------------
echo "\n--- بخش ۴: منطق شرطی پیشرفته روی چک‌باکس چندگزینه‌ای ---\n";

// الف: والد آرایه‌ای از چند چک‌باکس است
$checkedArray = ['yes_individual', 'yes_career'];

assert_test(TestFields::evaluateCondition($checkedArray, 'contains', 'yes') === true, 'ارزیابی contains روی آرایه والد (شامل yes)');
assert_test(TestFields::evaluateCondition($checkedArray, 'contains', 'career') === true, 'ارزیابی contains روی آرایه والد (شامل career)');
assert_test(TestFields::evaluateCondition($checkedArray, 'contains', 'family') === false, 'ارزیابی contains منفی (فاقد family)');
assert_test(TestFields::evaluateCondition($checkedArray, 'in', 'yes_individual,no') === true, 'ارزیابی in روی آرایه والد');
assert_test(TestFields::evaluateCondition($checkedArray, 'equals', 'yes_individual') === true, 'ارزیابی equals (تطابق یکی از گزینه‌ها)');
assert_test(TestFields::evaluateCondition($checkedArray, 'is_not_empty', '') === true, 'ارزیابی is_not_empty روی آرایه پر');
assert_test(TestFields::evaluateCondition([], 'is_empty', '') === true, 'ارزیابی is_empty روی آرایه خالی');
assert_test(TestFields::evaluateCondition([], 'checked', '') === false, 'ارزیابی checked روی آرایه خالی');

// ب: والد رشته‌ای
assert_test(TestFields::evaluateCondition('yes', 'equals', 'yes') === true, 'ارزیابی equals روی رشته');
assert_test(TestFields::evaluateCondition('no', 'not_equals', 'yes') === true, 'ارزیابی not_equals روی رشته');
assert_test(TestFields::evaluateCondition('بله', 'checked', '') === true, 'ارزیابی checked روی کلمه بله فارسی');

// --------------------------------------------------------------------
// بخش ۵: بررسی تفکیک گام اجرا و زمان‌بندی (pre_test / post_test / both)
// --------------------------------------------------------------------
echo "\n--- بخش ۵: تفکیک گام اجرا و فیلتر زمان‌بندی ---\n";

// درج دو فیلد تستی با مراحل مختلف در دیتابیس تست
DB::run("DELETE FROM " . DB::t('test_fields') . " WHERE name IN ('qa_pre_field', 'qa_post_field', 'qa_both_field')");

DB::insert('test_fields', [
    'test_id'       => 9999,
    'label'         => 'فیلد شروع آزمون',
    'name'          => 'qa_pre_field',
    'type'          => 'text',
    'display_stage' => 'pre_test',
    'required'      => 1,
    'active'        => 1,
]);

DB::insert('test_fields', [
    'test_id'       => 9999,
    'label'         => 'فیلد پایان آزمون',
    'name'          => 'qa_post_field',
    'type'          => 'checkbox',
    'display_stage' => 'post_test',
    'required'      => 1,
    'active'        => 1,
]);

DB::insert('test_fields', [
    'test_id'       => 9999,
    'label'         => 'فیلد هر دو مرحله',
    'name'          => 'qa_both_field',
    'type'          => 'select',
    'display_stage' => 'both',
    'required'      => 0,
    'active'        => 1,
]);

$preFields  = TestFields::getPreTestFields(9999);
$postFields = TestFields::getPostTestFields(9999);

$preNames  = array_column($preFields, 'name');
$postNames = array_column($postFields, 'name');

assert_test(in_array('qa_pre_field', $preNames, true), 'حضور فیلد pre_test در واکشی getPreTestFields');
assert_test(in_array('qa_both_field', $preNames, true), 'حضور فیلد both در واکشی getPreTestFields');
assert_test(!in_array('qa_post_field', $preNames, true), 'عدم حضور فیلد post_test در واکشی getPreTestFields (شروع آزمون)');

assert_test(in_array('qa_post_field', $postNames, true), 'حضور فیلد post_test در واکشی getPostTestFields (پایان آزمون)');
assert_test(in_array('qa_both_field', $postNames, true), 'حضور فیلد both در واکشی getPostTestFields');
assert_test(!in_array('qa_pre_field', $postNames, true), 'عدم حضور فیلد pre_test در واکشی getPostTestFields');

// --------------------------------------------------------------------
// بخش ۶: بررسی اعتبارسنجی سرور در validateAndProcess با چک‌باکس چندگزینه‌ای
// --------------------------------------------------------------------
echo "\n--- بخش ۶: اعتبارسنجی سرور validateAndProcess با چک‌باکس چندگزینه‌ای ---\n";

// تست ۱: فیلد اجباری پایان آزمون پر نشده
$valResFail = TestFields::validateAndProcess(9999, 'free', [
    'test_custom_fields' => []
], 'post_test');
assert_test($valResFail['ok'] === false, 'رد شدن فرم به دلیل خالی بودن چک‌باکس اجباری پایان آزمون');
assert_test(!empty($valResFail['errors']), 'تولید پیام خطای اعتبارسنجی برای فیلد الزامی');

// تست ۲: فیلد اجباری با آرایه مقادیر تیک‌خورده پر شده
$valResSuccess = TestFields::validateAndProcess(9999, 'free', [
    'test_custom_fields' => [
        'free_consultation' => ['yes_individual'],
        'consultation_time' => 'morning',
        'qa_post_field'     => ['yes_val1', 'yes_val2']
    ]
], 'post_test');
assert_test($valResSuccess['ok'] === true, 'تأیید موفق چک‌باکس چندگزینه‌ای با آرایه مقادیر تیک‌خورده');
assert_test(isset($valResSuccess['data']['qa_post_field']), 'ثبت ساختار داده چک‌باکس در خروجی');
assert_test(is_array($valResSuccess['data']['qa_post_field']['raw']), 'نگه‌داری مقدار خام به صورت آرایه بدون تبدیل ناقص');

// پاک‌سازی فیلدهای تست موقت
DB::run("DELETE FROM " . DB::t('test_fields') . " WHERE name IN ('qa_pre_field', 'qa_post_field', 'qa_both_field')");

// --------------------------------------------------------------------
// بخش ۷: بررسی رندر کارت گزارش renderDisplayCard بدون خطای Array to String
// --------------------------------------------------------------------
echo "\n--- بخش ۷: رندر کارت گزارش و پرونده بالینی renderDisplayCard ---\n";

$sampleSavedJson = json_encode([
    'free_consultation' => [
        'name'  => 'free_consultation',
        'label' => 'آیا به مشاوره رایگان نیاز دارید؟',
        'type'  => 'checkbox',
        'value' => ['مشاوره فردی', 'مشاوره شغلی'],
        'raw'   => ['yes_ind', 'yes_car']
    ],
    'companion_phone' => [
        'name'  => 'companion_phone',
        'label' => 'شماره همراه',
        'type'  => 'tel',
        'value' => '09121112233',
        'raw'   => '09121112233'
    ]
], JSON_UNESCAPED_UNICODE);

$cardHtml = TestFields::renderDisplayCard($sampleSavedJson, 'نتایج ثبت‌شده');
assert_test(!empty($cardHtml), 'تولید موفق کارت گزارش سفارشی');
assert_test(str_contains($cardHtml, 'مشاوره فردی، مشاوره شغلی'), 'چسباندن و نمایش صحیح گزینه‌های آرایه‌ای بدون خطای Array to string');
assert_test(str_contains($cardHtml, 'tel:09121112233'), 'رندر لینک تلفن تماس کلیک‌پذیر');

// --------------------------------------------------------------------
// بخش ۸: بررسی نگارش ۳٫۹٫۰ و فایل‌های مایگریشن
// --------------------------------------------------------------------
echo "\n--- بخش ۸: بررسی نسخه ۳٫۹٫۰ و فایل‌های مایگریشن ---\n";

assert_test(FR_RELEASE === '3.9.0', 'تعریف ثابت FR_RELEASE به نگارش 3.9.0');
assert_test(FR_VERSION === '3.9.0', 'تعریف ثابت FR_VERSION به نگارش 3.9.0');

$migrationPath = FR_ROOT . '/database/migrations/v390_short_links_and_gravity_fields.sql';
assert_test(file_exists($migrationPath), 'وجود فایل مایگریشن v390_short_links_and_gravity_fields.sql');
$sqlContent = (string)file_get_contents($migrationPath);
assert_test(str_contains($sqlContent, 'display_stage'), 'دستور افزودن ستون display_stage در فایل مایگریشن SQL');

echo "\n====================================================================\n";
echo "  نتیجه آزمون‌های QA نگارش ۳٫۹٫۰: {$passed} موفق از مجموع {$total} آزمون (" . round(($passed / $total) * 100) . "٪)\n";
if ($failed === 0) {
    echo "  🎉 تمامی آزمون‌ها با موفقیت ۱۰۰٪ سپری شدند!\n";
} else {
    echo "  ⚠️ تعداد {$failed} آزمون با خطا مواجه شد.\n";
}
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
