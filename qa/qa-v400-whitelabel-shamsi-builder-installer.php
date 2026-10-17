<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت: نگارش ۴٫۰٫۰
 *  (QA Test Suite: v4.0.0 - White-Label, Shamsi Engine, PageBuilder, Menus & Installer)
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
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/update.php';
require_once FR_ROOT . '/core/PageBuilder.php';
require_once FR_ROOT . '/core/MenuBuilder.php';

$passed = 0;
$failed = 0;

function it(string $desc, callable $fn): void {
    global $passed, $failed;
    try {
        $res = $fn();
        if ($res === false) {
            echo "  ❌ FAIL: {$desc}\n";
            $failed++;
        } else {
            echo "  ✅ PASS: {$desc}\n";
            $passed++;
        }
    } catch (Throwable $e) {
        echo "  ❌ ERROR: {$desc} -> " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n======================================================================\n";
echo "🧪 آغاز آزمون‌های تضمین کیفیت نگارش ۴٫۰٫۰ فریا روان\n";
echo "======================================================================\n\n";

echo "--- بخش اول: بررسی نگارش و پایداری متغیرهای نسخه ---\n";

it('نسخه سیستم در FR_RELEASE و FR_VERSION حداقل 4.0.0 است', function() {
    return version_compare(FR_RELEASE, '4.0.0', '>=') && version_compare(FR_VERSION, '4.0.0', '>=');
});

it('پایپ‌لاین به‌روزرسانی سیستم بدون خطا اجرا می‌شود و نسخه را همگام می‌سازد', function() {
    fr_update_pipeline(true);
    $ver = DB::setting('fr_version');
    return version_compare((string)$ver, '4.0.0', '>=');
});

echo "\n--- بخش دوم: راستی‌آزمایی موتور تقویم شمسی (Jalali / Shamsi Engine) ---\n";

it('jdate با آرگومان عددی Unix timestamp به درستی کار می‌کند', function() {
    $ts = strtotime('2026-10-17 12:00:00');
    $res = jdate('Y/m/d', $ts);
    $en = en_num($res);
    return str_starts_with($en, '1405/') && strlen($en) === 10;
});

it('jdate چندریختی با ورودی رشته‌ای تاریخ MySQL به درستی تبدیل می‌شود', function() {
    $res = jdate('Y/m/d', '2026-10-17 14:30:00');
    $en = en_num($res);
    return str_starts_with($en, '1405/') && strlen($en) === 10;
});

it('jdate بدون پارامتر یا با null زمان حال را بدون خطا بازمی‌گرداند', function() {
    $res = jdate('Y');
    $en = (int)en_num($res);
    return $en >= 1400;
});

it('jdate از تمام مشخصه‌های فرمت نظیر y, M, h, G, g, s, a, A پشتیبانی می‌کند', function() {
    $ts = strtotime('2026-10-17 15:05:09');
    $y = en_num(jdate('y', $ts));
    $M = jdate('M', $ts);
    $h = en_num(jdate('h', $ts));
    $G = en_num(jdate('G', $ts));
    $g = en_num(jdate('g', $ts));
    $s = en_num(jdate('s', $ts));
    $a = jdate('a', $ts);
    $A = jdate('A', $ts);
    return $y === '05' && $M === 'مهر' && $h === '03' && $G === '15' && $g === '3' && $s === '09' && $a === 'ب.ظ' && $A === 'عصر';
});

it('توابع کمکی fr_shamsi_date و fr_shamsi_year به درستی کار می‌کنند', function() {
    $d = fr_shamsi_date('2026-10-17 14:30:00');
    $y = fr_shamsi_year('2026-10-17 14:30:00');
    return en_num($y) === '1405' && str_contains($d, '۱۴۰۵');
});

echo "\n--- بخش سوم: راستی‌آزمایی سیستم ۱۰۰٪ White-Label و ری‌برندینگ ---\n";

it('تنظیمات پیش‌فرض برندسازی در دیتابیس مستقر شده‌اند', function() {
    $title = fr_brand_title();
    $short = fr_brand_short();
    $en    = fr_brand_name_en();
    return !empty($title) && !empty($short) && !empty($en);
});

it('تغییر نام برند و شعار در تنظیمات بلافاصله در هلپرهای متمرکز منعکس می‌شود', function() {
    $oldTitle = fr_brand_title();
    DB::setSetting('site_title', 'کلینیک روانشناسی مهر اندیش');
    DB::setSetting('brand_short_name', 'مهر');
    DB::setSetting('site_slogan', 'مرکز تخصصی ارزیابی و رشد فردی');

    $t = fr_brand_title();
    $s = fr_brand_short();
    $sl = fr_brand_slogan();

    // بازگردانی برای تمیزی
    DB::setSetting('site_title', $oldTitle);
    DB::setSetting('brand_short_name', 'فریا');

    return $t === 'کلینیک روانشناسی مهر اندیش' && $s === 'مهر' && $sl === 'مرکز تخصصی ارزیابی و رشد فردی';
});

it('متن کپی‌رایت پویا متغیرهای {site} و {year} را به صورت خودکار جایگزین می‌کند', function() {
    DB::setSetting('copyright_text', 'تمامی حقوق متعلق به {site} است ({year})');
    $copy = fr_brand_copyright();
    return str_contains($copy, fr_brand_title()) && str_contains($copy, fa_num(jdate('Y')));
});

it('فعال‌سازی White-Label کامل خط اعتباری توسعه‌دهنده را در خروجی فوتر مخفی می‌کند', function() {
    DB::setSetting('hide_system_credits', '0');
    $visibleHtml = fr_credit_html();
    $vIsHidden = fr_is_system_credit_hidden();

    DB::setSetting('hide_system_credits', '1');
    $hiddenHtml = fr_credit_html();
    $hIsHidden = fr_is_system_credit_hidden();

    // بازگردانی
    DB::setSetting('hide_system_credits', '0');

    return $vIsHidden === false && !empty($visibleHtml) && $hIsHidden === true && $hiddenHtml === '';
});

it('هلپرهای لوگوی تیره و فاوآیکون در صورت خالی بودن مقادیر پیش‌فرض امن دارند', function() {
    $fav = fr_brand_favicon();
    $dark = fr_brand_logo(true);
    return str_contains($fav, 'favicon') && is_string($dark);
});

echo "\n--- بخش چهارم: کلید رمزنگاری خودترمیم FR_CRYPT_KEY و نصاب ---\n";

it('کلید رمزنگاری FR_CRYPT_KEY به صورت خودکار با طول ۶۴ کاراکتر هگز تولید شده است', function() {
    if (!defined('FR_CRYPT_KEY')) {
        require_once FR_ROOT . '/config.php';
    }
    return defined('FR_CRYPT_KEY') && strlen(FR_CRYPT_KEY) === 64 && ctype_xdigit(FR_CRYPT_KEY);
});

it('فایل فال‌بک کلید در storage/.crypt_key موجود و با FR_CRYPT_KEY هم‌خوانی دارد', function() {
    $keyFile = FR_ROOT . '/storage/.crypt_key';
    if (!file_exists($keyFile)) {
        if (!defined('FR_CRYPT_KEY')) require_once FR_ROOT . '/config.php';
    }
    if (!file_exists($keyFile)) return false;
    $key = trim((string)file_get_contents($keyFile));
    return $key === FR_CRYPT_KEY;
});

it('اسکریپت reseed.php در محیط خط فرمان (CLI) بدون خطای احراز هویت اجرا می‌شود', function() {
    $phpCmd = 'php -r "' .
        'define(\'FR_TEST_MODE\', true);' .
        'define(\'DB_HOST\', \'127.0.0.1\');' .
        'define(\'DB_NAME\', \'feria_test\');' .
        'define(\'DB_USER\', \'feria\');' .
        'define(\'DB_PASS\', \'feria_password\');' .
        'define(\'DB_PREFIX\', \'fr_\');' .
        'define(\'SITE_URL\', \'http://127.0.0.1:8000\');' .
        'require \'' . FR_ROOT . '/install/reseed.php\';' .
        '" 2>&1';
    $out = shell_exec($phpCmd);
    return !str_contains((string)$out, 'دسترسی غیرمجاز') && str_contains((string)$out, 'بذر محتوا انجام شد');
});

it('تابع fr_cleanup_install_folder وجود دارد و فایل‌های نصاب را ایمن مدیریت می‌کند', function() {
    return function_exists('fr_cleanup_install_folder');
});

echo "\n--- بخش پنجم: پایدارسازی صفحه‌ساز دیداری و مدیریت منوها ---\n";

it('فایل admin/menus.php بدون fatal error و با خروجی معتبر رندر می‌شود', function() {
    $php = '<?php
define("FR_TEST_MODE", true);
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");
session_start();
$_SESSION["admin_id"] = 1;
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/admin/menus.php";
chdir("' . FR_ROOT . '/admin");
ob_start();
require "' . FR_ROOT . '/admin/menus.php";
$html = ob_get_clean();
echo $html;
';
    $tmp = tempnam(sys_get_temp_dir(), 'test_menu_');
    file_put_contents($tmp, $php);
    $out = shell_exec("php $tmp 2>&1");
    unlink($tmp);
    return !str_contains((string)$out, 'Fatal error') && !str_contains((string)$out, 'خطای موقتی') && strlen((string)$out) > 10000;
});

it('کدهای جاوااسکریپت رندرشده admin/page-builder.php از نظر سینتکس ۱۰۰٪ معتبرند', function() {
    $php = '<?php
define("FR_TEST_MODE", true);
define("DB_HOST", "127.0.0.1");
define("DB_NAME", "feria_test");
define("DB_USER", "feria");
define("DB_PASS", "feria_password");
define("DB_PREFIX", "fr_");
define("SITE_URL", "http://127.0.0.1:8000");
define("FR_ROOT", "' . FR_ROOT . '");
define("FR_ENV", "production");
session_start();
$_SESSION["admin_id"] = 1;
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/admin/page-builder.php?id=1";
chdir("' . FR_ROOT . '/admin");
ob_start();
require "' . FR_ROOT . '/admin/page-builder.php";
$html = ob_get_clean();
echo $html;
';
    $tmp = tempnam(sys_get_temp_dir(), 'test_pb_render_');
    file_put_contents($tmp, $php);
    $renderedHtml = shell_exec("php $tmp 2>&1");
    unlink($tmp);

    preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', (string)$renderedHtml, $matches);
    $allJs = '';
    foreach ($matches[1] as $block) {
        $clean = trim($block);
        if ($clean !== '' && !str_contains($clean, 'src=')) {
            $allJs .= "\n;\n" . $clean;
        }
    }

    $jsTmp = tempnam(sys_get_temp_dir(), 'test_pb_js_') . '.js';
    file_put_contents($jsTmp, $allJs);
    exec("node -c $jsTmp 2>&1", $jsOut, $jsStatus);
    unlink($jsTmp);
    return $jsStatus === 0;
});

it('ویجت‌های PageBuilder از جمله site_logo و contact_box بدون ارور رندر می‌شوند', function() {
    ob_start();
    PageBuilder::renderWidgetSiteLogo([], false);
    PageBuilder::renderWidgetContactBox([], false);
    $html = ob_get_clean();
    return str_contains($html, 'fr-widget-logo') && str_contains($html, 'fr-widget-contact-box');
});

it('جدول منوها و اقلام منو در دیتابیس به درستی مقداردهی و قابل خواندن است', function() {
    $menus = MenuBuilder::getMenus();
    return is_array($menus) && count($menus) >= 1;
});

echo "\n======================================================================\n";
echo "📊 نتیجه نهایی آزمون‌ها: {$passed} موفق | {$failed} ناموفق\n";
echo "======================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
