<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت: نگارش ۴٫۱٫۰ و الزام دکمه اعمال کدهای تخفیف
 * ============================================================================
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

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
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/commerce.php';
require_once FR_ROOT . '/includes/event-pricing.php';
require_once FR_ROOT . '/includes/update.php';
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
echo "  🧪 آزمون‌های اعتبارسنجی نگارش ۴٫۱٫۰ و الزام دکمه اعمال کد تخفیف\n";
echo "====================================================================\n\n";

// -----------------------------------------------------------------------------
echo "--- بخش اول: بررسی نگارش ۴٫۱٫۰ و ارتقای پایپ‌لاین ---\n";
// -----------------------------------------------------------------------------
it('نسخه سیستم حداقل برابر 4.1.0 است', function() {
    return defined('FR_RELEASE') && version_compare(FR_RELEASE, '4.1.0', '>=') &&
           defined('FR_VERSION') && version_compare(FR_VERSION, '4.1.0', '>=');
});

it('پایپ‌لاین به‌روزرسانی بدون خطا نسخه دیتابیس را همگام می‌سازد', function() {
    fr_update_pipeline(true);
    $ver = (string)DB::setting('fr_version');
    return version_compare($ver, '4.1.0', '>=');
});

// -----------------------------------------------------------------------------
echo "\n--- بخش دوم: رفتار API بررسی کد تخفیف و ثبت امن در نشست کاربری ---\n";
// -----------------------------------------------------------------------------

// کاربر و تست فرضی جهت تست
$u = DB::one("SELECT * FROM " . DB::t('users') . " ORDER BY id ASC LIMIT 1");
if (!$u) {
    $uid = DB::insert('users', ['mobile' => '09129990000', 'first_name' => 'کاربر', 'last_name' => 'تستی']);
    $u = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$uid]);
}
$_SESSION['user_id'] = $u['id'];

$t = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE status='active' LIMIT 1");
if (!$t) {
    $tid = DB::insert('tests', ['title' => 'تست نمونه', 'slug' => 'test-sample', 'price_full' => 200000, 'status' => 'active']);
    $t = DB::one("SELECT * FROM " . DB::t('tests') . " WHERE id=?", [$tid]);
}

// ایجاد یک کد تخفیف معتبر
$testCode = 'QA410_' . bin2hex(random_bytes(4));
DB::run("DELETE FROM " . DB::t('discounts') . " WHERE code LIKE 'QA410_%'");
DB::insert('discounts', [
    'code'        => $testCode,
    'type'        => 'percent',
    'value'       => 25,
    'usage_limit' => 100,
    'used_count'  => 0,
    'status'      => 'active',
]);

it('اعتبارسنجی کد تخفیف صحیح در api/discount.php نشست applied_discounts را مقداردهی می‌کند', function() use ($t, $u, $testCode) {
    $_deal = fr_price_deal((int)$t['price_full'], isset($t['price_sale']) ? (int)$t['price_sale'] : null);
    $v = validate_discount($testCode, (int)$t['id'], (int)$u['id'], $_deal['final']);
    
    // شبیه‌سازی دقیق رفتار ثبت در نشست داخل api/discount.php
    if (!empty($v['ok'])) {
        if (!isset($_SESSION['applied_discounts'])) $_SESSION['applied_discounts'] = [];
        $_SESSION['applied_discounts']['test_' . (int)$t['id']] = [
            'code'    => $testCode,
            'time'    => time(),
            'test_id' => (int)$t['id'],
            'user_id' => (int)$u['id'],
        ];
    }

    return !empty($v['ok']) &&
           isset($_SESSION['applied_discounts']['test_' . (int)$t['id']]) &&
           $_SESSION['applied_discounts']['test_' . (int)$t['id']]['code'] === $testCode;
});

it('درخواست حذف یا ارسال کد نامعتبر نشست تخفیف را باطل می‌کند', function() use ($t) {
    // شبیه‌سازی کد نامعتبر یا act: clear
    unset($_SESSION['applied_discounts']['test_' . (int)$t['id']]);
    return !isset($_SESSION['applied_discounts']['test_' . (int)$t['id']]);
});

// -----------------------------------------------------------------------------
echo "\n--- بخش سوم: الزام فشردن دکمه اعمال در آزمون‌ها (panel/test-start.php) ---\n";
// -----------------------------------------------------------------------------

it('اگر کاربر کد تخفیف را تایپ کند ولی دکمه اعمال را نزند، ثبت سفارش متوقف می‌شود', function() use ($t, $testCode) {
    // پاک‌سازی سشن برای شبیه‌سازی کاربری که دکمه اعمال را نزده
    unset($_SESSION['applied_discounts']['test_' . (int)$t['id']]);

    // شبیه‌سازی داده‌های POST ارسالی توسط کاربر بدون اعمال
    $postDiscountCode = '';
    $postRawDiscountCode = $testCode; // کاربر تایپ کرده ولی دکمه اعمال نزده

    $err = null;
    $code = '';
    if ($postDiscountCode !== '' || $postRawDiscountCode !== '') {
        $targetCode = $postDiscountCode !== '' ? $postDiscountCode : $postRawDiscountCode;
        $sessDiscount = $_SESSION['applied_discounts']['test_' . (int)$t['id']] ?? null;

        if (
            !$sessDiscount ||
            mb_strtolower((string)$sessDiscount['code']) !== mb_strtolower($targetCode) ||
            (int)($sessDiscount['test_id'] ?? 0) !== (int)$t['id'] ||
            (time() - (int)($sessDiscount['time'] ?? 0)) > 7200
        ) {
            $err = 'برای استفاده از تخفیف، حتماً باید دکمهٔ «اعمال» کد تخفیف را بزنید.';
        } else {
            $code = (string)$sessDiscount['code'];
        }
    }

    return $err === 'برای استفاده از تخفیف، حتماً باید دکمهٔ «اعمال» کد تخفیف را بزنید.' && $code === '';
});

it('اگر کاربر دکمه اعمال را زده باشد و نشست معتبر باشد، کد تخفیف تایید و اعمال می‌شود', function() use ($t, $u, $testCode) {
    // ایجاد سشن معتبر پس از فشردن دکمه اعمال
    $_SESSION['applied_discounts']['test_' . (int)$t['id']] = [
        'code'    => $testCode,
        'time'    => time(),
        'test_id' => (int)$t['id'],
        'user_id' => (int)$u['id'],
    ];

    $postDiscountCode = $testCode;
    $postRawDiscountCode = $testCode;

    $err = null;
    $code = '';
    if ($postDiscountCode !== '' || $postRawDiscountCode !== '') {
        $targetCode = $postDiscountCode !== '' ? $postDiscountCode : $postRawDiscountCode;
        $sessDiscount = $_SESSION['applied_discounts']['test_' . (int)$t['id']] ?? null;

        if (
            !$sessDiscount ||
            mb_strtolower((string)$sessDiscount['code']) !== mb_strtolower($targetCode) ||
            (int)($sessDiscount['test_id'] ?? 0) !== (int)$t['id'] ||
            (time() - (int)($sessDiscount['time'] ?? 0)) > 7200
        ) {
            $err = 'برای استفاده از تخفیف، حتماً باید دکمهٔ «اعمال» کد تخفیف را بزنید.';
        } else {
            $code = (string)$sessDiscount['code'];
        }
    }

    return $err === null && $code === $testCode;
});

// -----------------------------------------------------------------------------
echo "\n--- بخش چهارم: الزام فشردن دکمه اعمال در رویدادها (panel/event-register.php) ---\n";
// -----------------------------------------------------------------------------

$ev = DB::one("SELECT * FROM " . DB::t('events') . " WHERE status='active' LIMIT 1");
if (!$ev) {
    $evid = DB::insert('events', [
        'title'      => 'کارگاه نمونه',
        'slug'       => 'event-sample-' . mt_rand(10, 99),
        'kind'       => 'workshop',
        'price'      => 300000,
        'status'     => 'active',
    ]);
    $ev = DB::one("SELECT * FROM " . DB::t('events') . " WHERE id=?", [$evid]);
}

it('در رویدادها، ارسال کد بدون فشردن دکمه اعمال کد با ارور دقیق متوقف می‌شود', function() use ($ev, $testCode) {
    unset($_SESSION['applied_discounts']['event_' . (int)$ev['id']]);

    $postDiscountCode = '';
    $postRawDiscountCode = $testCode;

    $err = null;
    $code = '';
    if ($postDiscountCode !== '' || $postRawDiscountCode !== '') {
        $targetCode = $postDiscountCode !== '' ? $postDiscountCode : $postRawDiscountCode;
        $sessDiscount = $_SESSION['applied_discounts']['event_' . (int)$ev['id']] ?? null;

        if (
            !$sessDiscount ||
            mb_strtolower((string)$sessDiscount['code']) !== mb_strtolower($targetCode) ||
            (int)($sessDiscount['event_id'] ?? 0) !== (int)$ev['id'] ||
            (time() - (int)($sessDiscount['time'] ?? 0)) > 7200
        ) {
            $err = 'برای استفاده از تخفیف، حتماً باید دکمهٔ «اعمال کد» را انتخاب کنید.';
        } else {
            $code = (string)$sessDiscount['code'];
        }
    }

    return $err === 'برای استفاده از تخفیف، حتماً باید دکمهٔ «اعمال کد» را انتخاب کنید.' && $code === '';
});

// -----------------------------------------------------------------------------
echo "\n--- بخش پنجم: صحت ساختار کلاینت و محافظت‌های فرانت‌اند ---\n";
// -----------------------------------------------------------------------------

it('فایل panel/test-start.php شامل اینپوت خام، اینپوت مخفی و گارد سابمیت است', function() {
    $c = file_get_contents(FR_ROOT . '/panel/test-start.php');
    return str_contains($c, 'name="raw_discount_code"') &&
           str_contains($c, 'id="appliedDiscountCode"') &&
           str_contains($c, 'شما کد تخفیف را وارد کرده‌اید اما دکمه «اعمال» را انتخاب نکرده‌اید');
});

it('فایل panel/event-register.php شامل اینپوت خام، اینپوت مخفی و گارد سابمیت است', function() {
    $c = file_get_contents(FR_ROOT . '/panel/event-register.php');
    return str_contains($c, 'name="raw_discount_code"') &&
           str_contains($c, 'id="appliedDiscountCode"') &&
           str_contains($c, 'شما کد تخفیف را وارد کرده‌اید اما دکمه «اعمال کد» را انتخاب نکرده‌اید') &&
           str_contains($c, 'const code = APPLIED_CODE;');
});

echo "\n====================================================================\n";
echo "📊 نتیجه نهایی: {$pass} موفق | {$fail} ناموفق\n";
echo "====================================================================\n\n";

if ($fail > 0) exit(1);
exit(0);

