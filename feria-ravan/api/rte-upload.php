<?php
/**
 * =============================================================================
 *  آپلود تصویر برای ویرایشگرهای متن (RTE) — نسخهٔ ۱٫۷
 *  ─ دسترسی: هر کاربر یا مدیر واردشده (نشست فعال + توکن CSRF سربرگ X-CSRF)
 *  ─ امنیت: تصویر واقعی (MIME + getimagesize)، نام تصادفی، فقط JPG/PNG/WEBP ≤۲MB
 *  ─ خروجی: {ok, url, path} — url مطلق برای درج مستقیم در ویرایشگر
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/upload.php';
fr_bootstrap();

header('Content-Type: application/json; charset=utf-8');

// ۱) نشست معتبر: کاربر یا مدیر هر دو مجازند (ویرایشگر در پنل کاربر و پیشخوان مدیر هر دو استفاده می‌شود)
$u  = current_user();
$ad = function_exists('current_admin') ? current_admin() : null;
if (!$u) {
    require_once FR_ROOT . '/includes/admin-auth.php';
    $ad = current_admin();
}
if (!$u && !$ad) json_out(['ok'=>false,'message'=>'برای بارگذاری تصویر ابتدا وارد شوید.']);

// ۲) CSRF: این API با fetch + هدر X-CSRF (یا فیلد _csrf) فراخوانی می‌شود
if (!csrf_check()) {
    json_out(['ok'=>false,'message'=>'نشست امنیتی معتبر نیست؛ صفحه را تازه کنید و دوباره تلاش کنید.']);
}

// ۳) نرخ‌محدودِ منصفانه
$who = $u ? 'u' . (int)$u['id'] : 'a' . (int)$ad['id'];
if (!rate_limit('rte_up:' . $who, 30, 300)) {
    json_out(['ok'=>false,'message'=>'تعداد آپلودها زیاد شد؛ چند دقیقهٔ دیگر تلاش کنید.']);
}

// ۴) ذخیرهٔ امن
$owner = $u ? (int)$u['id'] : ((int)$ad['id'] + 100000);
$r = fr_save_image($_FILES['file'] ?? [], 'rte', 'rte', $owner);
if (!$r['ok']) json_out(['ok'=>false,'message'=>$r['error'] ?? 'آپلود ناموفق بود.']);

log_activity('rte_image', 'upload', 0, ['owner'=>$who, 'path'=>$r['path']]);
json_out(['ok'=>true, 'url'=>SITE_URL . '/' . ltrim((string)$r['path'], '/'), 'path'=>$r['path']]);
