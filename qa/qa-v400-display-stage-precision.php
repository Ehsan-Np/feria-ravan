<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت: گام نمایش فیلد (Display Stage Precision)
 * ============================================================================
 */
declare(strict_types=1);

define('FR_TEST_MODE', true);
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
require_once FR_ROOT . '/core/TestFields.php';

$pass = 0;
$fail = 0;

function it(string $msg, callable $fn): void {
    global $pass, $fail;
    try {
        if ($fn()) {
            echo "  ✅ PASS: {$msg}\n";
            $pass++;
        } else {
            echo "  ❌ FAIL: {$msg}\n";
            $fail++;
        }
    } catch (Throwable $e) {
        echo "  ❌ ERROR: {$msg} -> " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "====================================================================\n";
echo "  شروع آزمون‌های اعتبارسنجی دقیق گام نمایش فیلد (Display Stage)\n";
echo "====================================================================\n\n";

TestFields::ensureSchema();

// یافتن یا ایجاد یک فیلد تستی
$f = DB::one("SELECT * FROM " . DB::t('test_fields') . " WHERE name='free_consultation'");
if (!$f) {
    $fId = (int)DB::insert('test_fields', [
        'test_id' => 0,
        'label'   => 'مشاوره آزمایشی',
        'name'    => 'free_consultation',
        'type'    => 'checkbox',
        'required' => 0,
        'display_stage' => 'post_test',
        'active'  => 1
    ]);
} else {
    $fId = (int)$f['id'];
}

it('تغییر گام نمایش به pre_test و ماندگاری ۱۰۰٪ پس از اجرای چندباره ensureSchema', function() use ($fId) {
    DB::update('test_fields', ['display_stage' => 'pre_test'], 'id=?', [$fId]);
    
    // اجرای چندباره ensureSchema جهت بررسی عدم بازنویسی ناخواسته
    TestFields::ensureSchema();
    TestFields::ensureSchema();
    TestFields::ensureSchema();

    $val = DB::val("SELECT display_stage FROM " . DB::t('test_fields') . " WHERE id=?", [$fId]);
    return $val === 'pre_test';
});

it('حضور فیلد در getPreTestFields و عدم حضور در getPostTestFields هنگام تنظیم روی pre_test', function() use ($fId) {
    $pre = TestFields::getPreTestFields(1, 'all');
    $post = TestFields::getPostTestFields(1, 'all');

    $inPre = false;
    foreach ($pre as $item) {
        if ((int)$item['id'] === $fId) { $inPre = true; break; }
    }
    $inPost = false;
    foreach ($post as $item) {
        if ((int)$item['id'] === $fId) { $inPost = true; break; }
    }

    return $inPre === true && $inPost === false;
});

it('چرخش گام نمایش از pre_test به both و ماندگاری آن', function() use ($fId) {
    // شبیه‌سازی تغییر به both
    DB::update('test_fields', ['display_stage' => 'both'], 'id=?', [$fId]);
    TestFields::ensureSchema();

    $val = DB::val("SELECT display_stage FROM " . DB::t('test_fields') . " WHERE id=?", [$fId]);
    return $val === 'both';
});

it('حضور فیلد در هر دو تابع getPreTestFields و getPostTestFields هنگام تنظیم روی both', function() use ($fId) {
    $pre = TestFields::getPreTestFields(1, 'all');
    $post = TestFields::getPostTestFields(1, 'all');

    $inPre = false;
    foreach ($pre as $item) {
        if ((int)$item['id'] === $fId) { $inPre = true; break; }
    }
    $inPost = false;
    foreach ($post as $item) {
        if ((int)$item['id'] === $fId) { $inPost = true; break; }
    }

    return $inPre === true && $inPost === true;
});

it('چرخش گام نمایش از both به post_test و حضور انحصاری در getPostTestFields', function() use ($fId) {
    DB::update('test_fields', ['display_stage' => 'post_test'], 'id=?', [$fId]);
    TestFields::ensureSchema();

    $val = DB::val("SELECT display_stage FROM " . DB::t('test_fields') . " WHERE id=?", [$fId]);
    $pre = TestFields::getPreTestFields(1, 'all');
    $post = TestFields::getPostTestFields(1, 'all');

    $inPre = false;
    foreach ($pre as $item) {
        if ((int)$item['id'] === $fId) { $inPre = true; break; }
    }
    $inPost = false;
    foreach ($post as $item) {
        if ((int)$item['id'] === $fId) { $inPost = true; break; }
    }

    return $val === 'post_test' && $inPre === false && $inPost === true;
});

it('پشتیبانی کامل اسکریپت کلاینت از تزریق داده‌های فیلد هنگام قرارگیری خارج از فرم', function() {
    $html = TestFields::renderClientScript('#testCustomFieldsBox');
    return str_contains($html, 'tf-injected-field') && str_contains($html, '!form.contains(box)');
});

it('حضور کدهای همگام‌ساز فیلدهای پیش از آزمون در panel/test-start.php', function() {
    $code = file_get_contents(FR_ROOT . '/panel/test-start.php');
    return str_contains($code, 'tf-injected-field') && str_contains($code, 'testCustomFieldsBox');
});

it('حضور دکمه تعاملی تغییر سریع گام نمایش در جدول admin/test-fields.php', function() {
    $code = file_get_contents(FR_ROOT . '/admin/test-fields.php');
    return str_contains($code, 'name="act" value="toggle_stage"') && str_contains($code, '🏁 پایان آزمون ⟳');
});

echo "\n====================================================================\n";
echo "نتیجه نهایی: {$pass} موفق | {$fail} ناموفق\n";
echo "====================================================================\n\n";

if ($fail > 0) exit(1);
exit(0);
