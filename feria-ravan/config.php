<?php
/**
 * ============================================================================
 *  فایل پیکربندی اصلی — فریا روان (نسخهٔ ۱٫۹)
 *  ─ این فایل همراه بسته ارائه می‌شود تا ساختار پوشه کامل باشد؛ مقادیر زیر
 *    «قالب» هستند و دو راه برای فعال‌سازی دارید:
 *      ۱) به‌سادگی به نشانی /install/ بروید و نصب‌کنندهٔ گرافیکی این فایل را
 *         به‌صورت خودکار با اطلاعات هاست شما بازنویسی می‌کند (پیشنهادی)؛
 *      ۲) خودتان مقادیر {{...}} را با اطلاعات دیتابیس و دامنهٔ هاست جایگزین کنید
 *         و یک کلید تصادفی ۶۴ رقمی در FR_CRYPT_KEY بگذارید.
 *  ⚠️ این فایل را هرگز در اختیار دیگران قرار ندهید.
 * ============================================================================
 */
declare(strict_types=1);

if (!defined('DB_HOST'))   define('DB_HOST',   '{{DB_HOST}}');
if (!defined('DB_NAME'))   define('DB_NAME',   '{{DB_NAME}}');
if (!defined('DB_USER'))   define('DB_USER',   '{{DB_USER}}');
if (!defined('DB_PASS'))   define('DB_PASS',   '{{DB_PASS}}');
if (!defined('DB_PREFIX')) define('DB_PREFIX', '{{DB_PREFIX}}');

if (!defined('SITE_URL'))  define('SITE_URL',  '{{SITE_URL}}');

/* کلید رمزنگاری داده‌های حساس — در صورت عدم مقداردهی، به‌صورت خودکار و ایمن تولید می‌شود */
$rawCryptKey = '1d695bc8e6b75c418d0761c79b0ea4aaa74d3fdf604096e418e062c362bed0bc';
if ($rawCryptKey === '{{' . 'ENCRYPT_KEY' . '}}' || trim($rawCryptKey) === '') {
    $keyFile = __DIR__ . '/storage/.crypt_key';
    $savedKey = '';
    if (file_exists($keyFile)) {
        $savedKey = trim((string)@file_get_contents($keyFile));
    }
    if ($savedKey === '' || strlen($savedKey) < 32) {
        try {
            $savedKey = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            $savedKey = hash('sha256', uniqid((string)mt_rand(), true) . microtime(true));
        }
        if (is_dir(__DIR__ . '/storage') && is_writable(__DIR__ . '/storage')) {
            @file_put_contents($keyFile, $savedKey, LOCK_EX);
        }
    }
    if (is_writable(__FILE__)) {
        $cfgContent = @file_get_contents(__FILE__);
        if ($cfgContent && str_contains($cfgContent, "'2028ca40a2f629a7b79a3720ef5b406d201d63ca3c4c187c81923666fecb5dab'")) {
            $updatedCfg = str_replace("'2028ca40a2f629a7b79a3720ef5b406d201d63ca3c4c187c81923666fecb5dab'", var_export($savedKey, true), $cfgContent);
            @file_put_contents(__FILE__, $updatedCfg, LOCK_EX);
        }
    }
    if (!defined('FR_CRYPT_KEY')) define('FR_CRYPT_KEY', $savedKey);
} else {
    $keyFile = __DIR__ . '/storage/.crypt_key';
    if (is_dir(__DIR__ . '/storage') && is_writable(__DIR__ . '/storage')) {
        @file_put_contents($keyFile, $rawCryptKey, LOCK_EX);
    }
    if (!defined('FR_CRYPT_KEY')) define('FR_CRYPT_KEY', $rawCryptKey);
}

/* محیط: production | development */
if (!defined('FR_ENV')) define('FR_ENV', 'production');

if (!defined('FR_ROOT')) define('FR_ROOT', dirname(__FILE__));
/* نسخهٔ ۲٫۵: نگارش واقعی کد در includes/version.php (FR_RELEASE) است؛ این‌جا فقط Fallback */
if (!defined('FR_VERSION')) define('FR_VERSION', defined('FR_RELEASE') ? FR_RELEASE : '4.5.0');

/* ---------------------------------------------------------------------------
 | اگر این فایل هنوز «قالب خام» است (مقادیر جایگزین نشده‌اند)، نصب انجام نشده
 | است؛ کاربر را به نصب‌کنندهٔ گرافیکی هدایت می‌کنیم تا تجربهٔ اول ساده بماند.
 * -------------------------------------------------------------------------*/
if (DB_NAME === '{{' . 'DB_NAME' . '}}' && (!defined('FR_TEST_MODE') || !FR_TEST_MODE)) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        $path = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        $base = rtrim((string)preg_replace('#/(panel|admin|api|install)$#', '', $path), '/');
        header('Location: ' . ($base !== '' ? $base : '') . '/install/');
    }
    exit('سایت هنوز نصب نشده است؛ لطفاً نصب‌کننده را از مسیر /install/ اجرا کنید.');
}
