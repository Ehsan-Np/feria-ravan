<?php
/**
 * فایل پیکربندی اصلی — به‌صورت خودکار توسط نصب‌کننده ساخته می‌شود.
 * ⚠️ این فایل را هرگز در اختیار دیگران قرار ندهید.
 */
declare(strict_types=1);

if (!defined('DB_HOST'))   define('DB_HOST',   '{{DB_HOST}}');
if (!defined('DB_NAME'))   define('DB_NAME',   '{{DB_NAME}}');
if (!defined('DB_USER'))   define('DB_USER',   '{{DB_USER}}');
if (!defined('DB_PASS'))   define('DB_PASS',   '{{DB_PASS}}');
if (!defined('DB_PREFIX')) define('DB_PREFIX', '{{DB_PREFIX}}');

if (!defined('SITE_URL'))  define('SITE_URL',  '{{SITE_URL}}');

/* کلید رمزنگاری داده‌های حساس — در صورت عدم مقداردهی، به‌صورت خودکار و ایمن تولید می‌شود */
$rawCryptKey = '{{ENCRYPT_KEY}}';
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
    if (!defined('FR_CRYPT_KEY')) define('FR_CRYPT_KEY', $savedKey);
} else {
    if (!defined('FR_CRYPT_KEY')) define('FR_CRYPT_KEY', $rawCryptKey);
}

/* محیط: production | development */
if (!defined('FR_ENV')) define('FR_ENV', 'production');

/* محض جلوگیری از هشدار «Constant already defined» — fr_bootstrap ممکن است پیشاپیش تعریف کرده باشد */
if (!defined('FR_ROOT')) define('FR_ROOT', dirname(__FILE__));
/* نسخهٔ ۲٫۵: نگارش واقعی کد در includes/version.php (FR_RELEASE) است؛ این‌جا فقط Fallback */
if (!defined('FR_VERSION')) define('FR_VERSION', defined('FR_RELEASE') ? FR_RELEASE : '4.2.0');
