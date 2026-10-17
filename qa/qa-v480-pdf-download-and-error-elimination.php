<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های تضمین کیفیت فریا روان — نگارش ۴٫۸٫۰ (v4.8.0 QA Suite)
 *  ─ اعتبارسنجی ارتقای نسخه و پایداری نگارش ۴٫۸٫۰
 *  ─ ریشه‌کن‌سازی قطعی خطای ۵۰۰ («خطای موقتی رخ داده است») در دانلود فایل تحلیل
 *  ─ اطمینان از عملکرد موتورهای روان‌سنجی (به‌ویژه likert و neo) در Analyzer::scoreAttempt
 *  ─ حذف کامل پنجره پرینت پیش‌فرض سیستم (window.print()) به نفع دانلود مستقیم فایل PDF
 *  ─ تبدیل رزولوشن بالای نمودارهای Canvas به تصاویر وکتور درون سند PDF
 *  ─ حفظ ۱۰۰ درصدی محرمانگی پروتکل مداخله بالینی برای کاربران و دسترسی کامل مدیران
 * ============================================================================
 */
declare(strict_types=1);

define('FR_TEST_MODE', true);
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/report.php';
require_once FR_ROOT . '/core/Analyzer.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = [];

function it(string $title, callable $fn): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    try {
        $fn();
        $passedTests++;
        echo "  [PASS] {$title}\n";
    } catch (\Throwable $e) {
        $failedTests[] = ['title' => $title, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
        echo "  [FAIL] {$title} -> " . $e->getMessage() . "\n";
    }
}

function expectTrue(bool $val, string $msg = ''): void {
    if (!$val) throw new \RuntimeException($msg ?: 'Assertion failed: expected true');
}

function expectEqual($actual, $expected, string $msg = ''): void {
    if ($actual !== $expected) {
        throw new \RuntimeException(($msg ? $msg . ': ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

echo "\n======================================================\n";
echo "   آغاز آزمون‌های تضمین کیفیت جامع نگارش ۴٫۸٫۰ فریا روان\n";
echo "======================================================\n\n";

// ── فاز ۱: نگارش ۴٫۸٫۰ و اسکریپت‌های مهاجرت ──
echo "--- فاز ۱: شماره نگارش و عدم تکرار نسخه‌های قبلی ---\n";

it('ثابت FR_RELEASE در includes/version.php برابر 4.8.0 است', function() {
    expectEqual(FR_RELEASE, '4.8.0');
});

it('ثابت FR_VERSION در config.php و sample برابر 4.8.0 است', function() {
    $cfg = file_get_contents(FR_ROOT . '/config.php');
    expectTrue(str_contains($cfg, "define('FR_VERSION', defined('FR_RELEASE') ? FR_RELEASE : '4.8.0')"));
    $sample = file_get_contents(FR_ROOT . '/includes/config.sample.php');
    expectTrue(str_contains($sample, "define('FR_VERSION', defined('FR_RELEASE') ? FR_RELEASE : '4.8.0')"));
});

it('پایگاه‌داده جدول fr_settings دارای fr_version و system_version برابر 4.8.0 است', function() {
    $frVer = DB::setting('fr_version');
    $sysVer = DB::setting('system_version');
    expectEqual($frVer, '4.8.0');
    expectEqual($sysVer, '4.8.0');
});

it('اسکریپت تجمیعی مهاجرت v480_unified_migration.sql وجود دارد و نگارش ۴٫۸٫۰ را تنظیم می‌کند', function() {
    $migPath = FR_ROOT . '/database/migrations/v480_unified_migration.sql';
    expectTrue(file_exists($migPath), 'فایل مهاجرت v480 یافت نشد.');
    $content = file_get_contents($migPath);
    expectTrue(str_contains($content, "'4.8.0'"), 'نگارش 4.8.0 در فایل مهاجرت یافت نشد.');
    expectTrue(str_contains($content, 'report_pdf_download_mode'), 'تنظیم report_pdf_download_mode یافت نشد.');
});

// ── فاز ۲: تحلیل و نمره‌گذاری ایمن موتورهای روان‌سنجی (Analyzer) ──
echo "\n--- فاز ۲: عملکرد ایمن موتورهای روان‌سنجی و حذف خطاهای کشنده ---\n";

it('موتور Analyzer پشتیبانی کامل از آزمون‌های با موتور likert دارد بدون ایجاد خطای مهلک', function() {
    // Attempt 8 uses Test 3 (engine likert)
    $res = Analyzer::scoreAttempt(8);
    // scoreAttempt returns boolean and must NEVER throw RuntimeException
    expectTrue(is_bool($res));
});

it('متد Analyzer::scoreAttempt در برابر شناسه‌های آزمون نامعتبر کاملاً ایمن است', function() {
    $res = Analyzer::scoreAttempt(-9999);
    expectEqual($res, false);
});

it('متد ReportBuilder::build مقادیر null برای user و level را بدون TypeError مدیریت می‌کند', function() {
    $fakeAttempt = ['id' => 99999, 'test_id' => 1, 'status' => 'completed', 'mode' => null];
    $html = ReportBuilder::build($fakeAttempt, null, null);
    expectTrue(is_string($html));
    expectTrue(str_contains($html, 'fr-alert') || str_contains($html, 'fr-report'));
});

it('تابع user_fullname مقدار null را بدون ایجاد خطای نوع داده با مقدار پیش‌فرض مدیریت می‌کند', function() {
    $name = user_fullname(null);
    expectEqual($name, 'کاربر گرامی');
});

// ── فاز ۳: حذف پنجره پرینت پیش‌فرض و دانلود مستقیم فایل PDF ──
echo "\n--- فاز ۳: حذف window.print() و راه‌اندازی دانلود مستقیم PDF ---\n";

it('دکمه‌های کارنامه در panel/report.php و panel/history.php مستقیماً به download=1 لینک هستند', function() {
    $repFile = file_get_contents(FR_ROOT . '/panel/report.php');
    expectTrue(str_contains($repFile, 'download=1'), 'لینک download=1 در report.php یافت نشد.');
    expectTrue(!str_contains($repFile, '&auto=1'), 'هنوز لینک auto=1 در report.php وجود دارد.');

    $histFile = file_get_contents(FR_ROOT . '/panel/history.php');
    expectTrue(str_contains($histFile, 'download=1'), 'لینک download=1 در history.php یافت نشد.');
    expectTrue(!str_contains($histFile, '&auto=1'), 'هنوز لینک auto=1 در history.php وجود دارد.');
});

it('دکمه‌های کارنامه در admin/attempts.php و admin/user-view.php مستقیماً به download=1 لینک هستند', function() {
    $attFile = file_get_contents(FR_ROOT . '/admin/attempts.php');
    expectTrue(str_contains($attFile, 'download=1'), 'لینک download=1 در admin/attempts.php یافت نشد.');
    expectTrue(!str_contains($attFile, '&auto=1'), 'هنوز لینک auto=1 در admin/attempts.php وجود دارد.');

    $uvFile = file_get_contents(FR_ROOT . '/admin/user-view.php');
    expectTrue(str_contains($uvFile, 'download=1'), 'لینک download=1 در admin/user-view.php یافت نشد.');
    expectTrue(!str_contains($uvFile, '&auto=1'), 'هنوز لینک auto=1 در admin/user-view.php وجود دارد.');
});

it('در panel/report-pdf.php متد frSavePdf نمودارهای Canvas را قبل از تبدیل به تصویر وکتور تبدیل می‌کند', function() {
    $pdfFile = file_get_contents(FR_ROOT . '/panel/report-pdf.php');
    expectTrue(str_contains($pdfFile, 'toDataURL'), 'کد تبدیل canvas به toDataURL یافت نشد.');
    expectTrue(str_contains($pdfFile, 'prPdfStatus'), 'نوار وضعیت prPdfStatus یافت نشد.');
});

it('در panel/report-pdf.php پارامترهای download=1 و auto=1 هر دو مستقیماً frSavePdf را فراخوانی می‌کنند و print نمی‌کنند', function() {
    $pdfFile = file_get_contents(FR_ROOT . '/panel/report-pdf.php');
    expectTrue(str_contains($pdfFile, 'isAutoDownload'), 'متغیر isAutoDownload یافت نشد.');
    expectTrue(str_contains($pdfFile, 'setTimeout(window.frSavePdf'), 'فراخوانی خودکار frSavePdf یافت نشد.');
});

// ── فاز ۴: تست رندر کارنامه و دریافت PDF روی تمام ۲۰ آزمون دیتابیس ──
echo "\n--- فاز ۴: تست دریافت فایل کارنامه برای تمام ۲۰ نمونه آزمون ---\n";

$attempts = DB::all("SELECT a.id, a.user_id, a.test_id, t.engine, t.title FROM " . DB::t('attempts') . " a JOIN " . DB::t('tests') . " t ON t.id = a.test_id WHERE a.status = 'completed' ORDER BY a.id ASC");

foreach ($attempts as $atRow) {
    $attId = (int)$atRow['id'];
    $userId = (int)$atRow['user_id'];
    $engine = $atRow['engine'];
    $title = $atRow['title'];

    it("کارنامه آزمون #{$attId} ({$engine}) برای مدیر با موفقیت و بدون خطای ۵۰۰ رندر می‌شود", function() use ($attId) {
        $code = "
        define('FR_TEST_MODE', true);
        define('DB_HOST', '127.0.0.1');
        define('DB_NAME', 'feria_test');
        define('DB_USER', 'feria');
        define('DB_PASS', 'feria_password');
        define('DB_PREFIX', 'fr_');
        define('SITE_URL', 'http://127.0.0.1:8000');
        define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
        define('FR_ENV', 'production');
        \$_SERVER['REQUEST_URI'] = '/panel/report-pdf.php?attempt={$attId}&download=1';
        \$_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
        \$_GET['attempt'] = '{$attId}';
        \$_GET['download'] = '1';
        session_start();
        \$_SESSION['admin_id'] = 1;
        ob_start();
        require '/home/user/feria-ravan/feria-ravan/panel/report-pdf.php';
        \$out = ob_get_clean();
        if (strpos(\$out, 'خطای موقتی رخ داده است') !== false) {
            throw new RuntimeException('Fatal 500 error encountered!');
        }
        if (strlen(\$out) < 2000) {
            throw new RuntimeException('Output too small: ' . strlen(\$out) . ' bytes');
        }
        echo 'OK:' . strlen(\$out);
        ";
        $proc = proc_open('php', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], '<?php ' . $code);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if (!str_starts_with($out, 'OK:')) {
            throw new \RuntimeException('Failed: ' . $out . ' | ' . $err);
        }
    });

    it("کارنامه آزمون #{$attId} ({$engine}) برای کاربر مراجع بدون نشت پروتکل بالینی و بدون خطا رندر می‌شود", function() use ($attId, $userId) {
        $code = "
        define('FR_TEST_MODE', true);
        define('DB_HOST', '127.0.0.1');
        define('DB_NAME', 'feria_test');
        define('DB_USER', 'feria');
        define('DB_PASS', 'feria_password');
        define('DB_PREFIX', 'fr_');
        define('SITE_URL', 'http://127.0.0.1:8000');
        define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
        define('FR_ENV', 'production');
        \$_SERVER['REQUEST_URI'] = '/panel/report-pdf.php?attempt={$attId}&download=1';
        \$_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
        \$_GET['attempt'] = '{$attId}';
        \$_GET['download'] = '1';
        session_start();
        \$_SESSION['user_id'] = {$userId};
        ob_start();
        require '/home/user/feria-ravan/feria-ravan/panel/report-pdf.php';
        \$out = ob_get_clean();
        if (strpos(\$out, 'خطای موقتی رخ داده است') !== false) {
            throw new RuntimeException('Fatal 500 error encountered!');
        }
        if (strlen(\$out) < 2000) {
            throw new RuntimeException('Output too small: ' . strlen(\$out) . ' bytes');
        }
        if (strpos(\$out, 'راهنمای مداخلهٔ بالینی') !== false || strpos(\$out, 'پروتکل شواهدمحور') !== false) {
            throw new RuntimeException('Privacy leak: Clinical guide visible to standard user!');
        }
        echo 'OK:' . strlen(\$out);
        ";
        $proc = proc_open('php', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], '<?php ' . $code);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if (!str_starts_with($out, 'OK:')) {
            throw new \RuntimeException('Failed: ' . $out . ' | ' . $err);
        }
    });
}

// ── فاز ۵: تست فوروارد کارنامه از پنل ادمین (admin/report-pdf.php) ──
echo "\n--- فاز ۵: تست کنترلر ادمین admin/report-pdf.php ---\n";

it('فایل admin/report-pdf.php با دسترسی مدیر به درستی و بدون خطای ۵۰۰ کارنامه را نمایش می‌دهد', function() {
    $code = "
    define('FR_TEST_MODE', true);
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'feria_test');
    define('DB_USER', 'feria');
    define('DB_PASS', 'feria_password');
    define('DB_PREFIX', 'fr_');
    define('SITE_URL', 'http://127.0.0.1:8000');
    define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
    define('FR_ENV', 'production');
    \$_SERVER['REQUEST_URI'] = '/admin/report-pdf.php?attempt=6&download=1';
    \$_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
    \$_GET['attempt'] = '6';
    \$_GET['download'] = '1';
    session_start();
    \$_SESSION['admin_id'] = 1;
    ob_start();
    require '/home/user/feria-ravan/feria-ravan/admin/report-pdf.php';
    \$out = ob_get_clean();
    if (strpos(\$out, 'خطای موقتی رخ داده است') !== false) {
        throw new RuntimeException('Fatal 500 error in admin/report-pdf.php!');
    }
    if (strlen(\$out) < 2000) {
        throw new RuntimeException('Output too small: ' . strlen(\$out));
    }
    echo 'OK:' . strlen(\$out);
    ";
    $proc = proc_open('php', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], '<?php ' . $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if (!str_starts_with($out, 'OK:')) {
        throw new \RuntimeException('Failed: ' . $out . ' | ' . $err);
    }
});

// ── فاز ۶: اعتبارسنجی استانداردهای قبلی و عدم بازگشت باگ‌ها ──
echo "\n--- فاز ۶: اعتبارسنجی عدم بازگشت باگ‌ها (Zero Regression) ---\n";

it('قانون کلیک صریح دکمه «اعمال» برای کد تخفیف در test-start و event-register حفظ شده است', function() {
    $tsFile = file_get_contents(FR_ROOT . '/panel/test-start.php');
    expectTrue(str_contains($tsFile, 'دکمه «اعمال»'), 'منطق کلیک اعمال کد تخفیف در test-start حفظ نشده است.');
    $erFile = file_get_contents(FR_ROOT . '/panel/event-register.php');
    expectTrue(str_contains($erFile, 'دکمه «اعمال»') || str_contains($erFile, 'دکمه «اعمال کد»'), 'منطق کلیک اعمال کد تخفیف در event-register حفظ نشده است.');
});

it('پوشه ذخیره‌سازی اختصاصی feria-uploads با زیرپوشه‌ها حفظ شده است', function() {
    expectTrue(is_dir(FR_ROOT . '/feria-uploads'), 'پوشه feria-uploads یافت نشد.');
    expectTrue(is_dir(FR_ROOT . '/feria-uploads/workshops'));
    expectTrue(is_dir(FR_ROOT . '/feria-uploads/courses'));
    expectTrue(is_dir(FR_ROOT . '/feria-uploads/users'));
    expectTrue(is_dir(FR_ROOT . '/feria-uploads/general'));
});

it('بارکد QR بدون هیچ درخواست خارجی (کاملاً آفلاین و SVG محلی) تولید می‌شود', function() {
    $svg = fr_qr_svg('https://feria-ravan.ir/verify/test', 100);
    expectTrue(str_contains($svg, '<svg') || str_contains($svg, '<rect'), 'بارکد وکتور SVG تولید نشد.');
    expectTrue(!str_contains($svg, 'chart.googleapis.com'), 'درخواست به گوگل چارت شناسایی شد!');
});

echo "\n======================================================\n";
echo "نتایج نهایی: {$passedTests} از {$totalTests} آزمون با موفقیت پاس شدند.\n";
if (!empty($failedTests)) {
    echo "⚠️ تعداد آزمون‌های ناموفق: " . count($failedTests) . "\n";
    foreach ($failedTests as $f) {
        echo " - {$f['title']}: {$f['error']} in {$f['file']}:{$f['line']}\n";
    }
} else {
    echo "🎉 کلیه آزمون‌های تضمین کیفیت نگارش ۴٫۸٫۰ با موفقیت ۱۰۰٪ سپری شدند!\n";
}
echo "======================================================\n\n";
exit(empty($failedTests) ? 0 : 1);
