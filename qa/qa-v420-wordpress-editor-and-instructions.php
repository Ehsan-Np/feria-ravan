<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت: نگارش ۴٫۲٫۰ و ویرایشگر دوحالته وردپرس
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
require_once FR_ROOT . '/includes/sanitize.php';
require_once FR_ROOT . '/includes/update.php';

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
echo "  🧪 آزمون‌های جامع نگارش ۴٫۲٫۰: ویرایشگر وردپرسی و دستورالعمل تست‌ها\n";
echo "====================================================================\n\n";

// -----------------------------------------------------------------------------
echo "--- بخش اول: بررسی نگارش ۴٫۲٫۰ و همگام‌سازی خط‌لوله به‌روزرسانی ---\n";
// -----------------------------------------------------------------------------
it('نسخه سیستم در FR_RELEASE و FR_VERSION دقیقاً برابر 4.2.0 است', function() {
    return defined('FR_RELEASE') && FR_RELEASE === '4.2.0' && defined('FR_VERSION') && FR_VERSION === '4.2.0';
});

it('پایپ‌لاین به‌روزرسانی بدون خطا نگارش دیتابیس را به 4.2.0 ارتقا می‌دهد', function() {
    fr_update_pipeline(true);
    $ver = (string)DB::setting('fr_version');
    return $ver === '4.2.0';
});

// -----------------------------------------------------------------------------
echo "\n--- بخش دوم: راستی‌آزمایی تابع رندر متون غنی (fr_render_rich_html) ---\n";
// -----------------------------------------------------------------------------
it('متون ساده بدون تگ‌های HTML با nl2br و حفظ کاراکترهای امن فرمت می‌شوند', function() {
    $raw = "خط اول دستورالعمل\nخط دوم راهنما";
    $rendered = fr_render_rich_html($raw);
    return str_contains($rendered, 'خط اول دستورالعمل') &&
           str_contains($rendered, 'خط دوم راهنما') &&
           str_contains($rendered, '<br');
});

it('کدهای HTML معتبر با تگ‌های غنی، کلاس‌ها و استایل‌ها بدون نمایش تگ‌های خام رندر می‌شوند', function() {
    $html = '<div class="fr-callout info" style="color:#1e40af;text-align:right"><b>نکته مهم:</b><p>پاسخ دقیق دهید.</p></div>';
    $rendered = fr_render_rich_html($html);
    return str_contains($rendered, '<div') &&
           str_contains($rendered, 'class="fr-callout info"') &&
           str_contains($rendered, '<b>نکته مهم:</b>') &&
           str_contains($rendered, '<p>پاسخ دقیق دهید.</p>') &&
           !str_contains($rendered, '&lt;div'); // تگ‌ها نباید اسکیپ خام شوند
});

it('کدهای مخرب نظیر script یا رویدادهای onerror به صورت کامل پالایش می‌شوند', function() {
    $xss = '<script>alert("xss")</script><img src="x" onerror="alert(1)"><b>متن معتبر</b>';
    $rendered = fr_render_rich_html($xss);
    return !str_contains($rendered, '<script') &&
           !str_contains($rendered, 'onerror') &&
           str_contains($rendered, '<b>متن معتبر</b>');
});

// -----------------------------------------------------------------------------
echo "\n--- بخش سوم: راستی‌آزمایی کامپوننت ویرایشگر وردپرسی (fr_wp_editor) ---\n";
// -----------------------------------------------------------------------------
require_once FR_ROOT . '/admin/boot.php';

it('تابع fr_wp_editor ساختار کامل دوحالته (دیداری / متن HTML) و تولبارها را تولید می‌کند', function() {
    ob_start();
    fr_wp_editor('instructions', '<b>دستورالعمل اولیه</b>', 'test_inst', 'دستورالعمل تستی');
    $out = ob_get_clean();

    return str_contains($out, 'class="fr-wp-editor"') &&
           str_contains($out, 'data-tab-btn="visual"') &&
           str_contains($out, 'data-tab-btn="code"') &&
           str_contains($out, 'class="fr-wp-toolbar"') &&
           str_contains($out, 'class="fr-wp-quicktags"') &&
           str_contains($out, 'class="fr-wp-visual-canvas') &&
           str_contains($out, 'class="fr-wp-code-area"') &&
           str_contains($out, 'name="instructions"') &&
           str_contains($out, 'id="hd-test_inst"');
});

it('ویرایشگر وردپرسی دکمه‌های کادرهای راهنما (آبی، سبز، زرد) و ابزارهای سریع را دارد', function() {
    ob_start();
    fr_wp_editor('instructions', '', 'inst_btns');
    $out = ob_get_clean();

    return str_contains($out, 'data-cmd="__box_info"') &&
           str_contains($out, 'data-cmd="__box_success"') &&
           str_contains($out, 'data-cmd="__box_warn"') &&
           str_contains($out, 'data-tag="box-info"') &&
           str_contains($out, 'data-tag="box-warn"');
});

// -----------------------------------------------------------------------------
echo "\n--- بخش چهارم: صحت ادغام در فرم ادمین تست‌ها و پنل کاربری ---\n";
// -----------------------------------------------------------------------------
it('فایل admin/tests.php از fr_wp_editor برای instructions و description استفاده می‌کند', function() {
    $c = file_get_contents(FR_ROOT . '/admin/tests.php');
    return str_contains($c, "fr_wp_editor('instructions'") &&
           str_contains($c, "fr_wp_editor('description'") &&
           str_contains($c, "syncAllWpEditors");
});

it('فایل panel/test-start.php از fr_render_rich_html برای دستورالعمل قبل از شروع استفاده می‌کند', function() {
    $c = file_get_contents(FR_ROOT . '/panel/test-start.php');
    return str_contains($c, "fr_render_rich_html((string)\$test['instructions'])") &&
           str_contains($c, "class=\"fr-instructions-card\"") &&
           !str_contains($c, "nl2br(e(\$test['instructions']))");
});

it('اسکریپت assets/js/feria.js شامل توابع initWpEditors و syncAllWpEditors است', function() {
    $c = file_get_contents(FR_ROOT . '/assets/js/feria.js');
    return str_contains($c, "window.syncAllWpEditors") &&
           str_contains($c, "window.initWpEditors") &&
           str_contains($c, "switchToVisual") &&
           str_contains($c, "switchToCode");
});

echo "\n====================================================================\n";
echo "📊 نتیجه نهایی: {$pass} موفق | {$fail} ناموفق\n";
echo "====================================================================\n\n";

if ($fail > 0) exit(1);
exit(0);
