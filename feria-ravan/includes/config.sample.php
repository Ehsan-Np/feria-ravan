<?php
/**
 * فایل پیکربندی اصلی — به‌صورت خودکار توسط نصب‌کننده ساخته می‌شود.
 * ⚠️ این فایل را هرگز در اختیار دیگران قرار ندهید.
 */
declare(strict_types=1);

define('DB_HOST',    '{{DB_HOST}}');
define('DB_NAME',    '{{DB_NAME}}');
define('DB_USER',    '{{DB_USER}}');
define('DB_PASS',    '{{DB_PASS}}');
define('DB_PREFIX',  '{{DB_PREFIX}}');

define('SITE_URL',   '{{SITE_URL}}');

/* کلید رمزنگاری داده‌های حساس (توسط نصب‌کننده تولید می‌شود) */
define('FR_CRYPT_KEY', '{{ENCRYPT_KEY}}');

/* محیط: production | development */
define('FR_ENV', 'production');

/* محض جلوگیری از هشدار «Constant already defined» — fr_bootstrap ممکن است پیشاپیش تعریف کرده باشد */
if (!defined('FR_ROOT')) define('FR_ROOT', dirname(__FILE__));
/* نسخهٔ ۲٫۵: نگارش واقعی کد در includes/version.php (FR_RELEASE) است؛ این‌جا فقط Fallback */
if (!defined('FR_VERSION')) define('FR_VERSION', defined('FR_RELEASE') ? FR_RELEASE : '3.6.0');
