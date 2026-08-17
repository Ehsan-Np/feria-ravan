<?php
/**
 * API خبرنامهٔ پیامکی — عضویت/لغو عضویت (نسخهٔ ۲٫۱)
 * ─ عمومی و بدون لاگین (جمع‌آوری شماره از فوتر) — با ریت‌لیمیت ضد اسپم.
 * پاسخ: {ok, message}
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
fr_bootstrap();

$in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($in['action'] ?? 'subscribe');
$mobile = en_num(trim((string)($in['mobile'] ?? '')));
$name   = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
$source = mb_substr(trim((string)($in['source'] ?? 'footer')), 0, 30);

/* ریت‌لیمیت نرمِ ضد ربات: هر IP فقط هر چند ثانیه یک‌بار */
$ip = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16);
if (!rate_limit('newsletter:' . $ip, 6, 300)) {
    json_out(['ok'=>false,'message'=>'کمی آرام‌تر! چند دقیقهٔ دیگر دوباره تلاش کنید.']);
}

if (!valid_mobile($mobile)) {
    json_out(['ok'=>false,'message'=>'شماره موبایل معتبر نیست (مثال: 09123456789).']);
}

if ($action === 'subscribe') {
    /* نسخهٔ ۲٫۲: نام و نام خانوادگی (یک فیلد) برای عضو جدید الزامی است */
    $row = DB::one("SELECT * FROM " . DB::t('newsletter') . " WHERE mobile=?", [$mobile]);
    if (!$row && mb_strlen($name) < 3) {
        json_out(['ok'=>false,'need_name'=>true,'message'=>'نام و نام خانوادگی خود را هم بنویسید (مثلاً: سارا احمدی).']);
    }
    if ($row) {
        if ($row['status'] === 'active') {
            json_out(['ok'=>true,'message'=>'این شماره قبلاً عضو خبرنامه است 🦢']);
        }
        DB::run("UPDATE " . DB::t('newsletter') . " SET status='active', unsub_at=NULL, name=COALESCE(NULLIF(?,''),name), source=? WHERE id=?",
                [$name, $source, (int)$row['id']]);
        log_activity('newsletter_resub', 'user', null, ['mobile' => $mobile]);
        json_out(['ok'=>true,'message'=>'عضویت شما در خبرنامهٔ پیامکی دوباره فعال شد 🎉']);
    }
    DB::insert('newsletter', ['mobile'=>$mobile, 'name'=>$name !== '' ? $name : null, 'status'=>'active', 'source'=>$source]);
    log_activity('newsletter_sub', 'user', null, ['mobile' => $mobile]);
    json_out(['ok'=>true,'message'=>'عضویت شما در خبرنامهٔ پیامکی ثبت شد 🎉 به‌زودی خبرهای خوب می‌آیند.']);
}

if ($action === 'unsubscribe') {
    $row = DB::one("SELECT id,status FROM " . DB::t('newsletter') . " WHERE mobile=?", [$mobile]);
    if (!$row) json_out(['ok'=>false,'message'=>'این شماره در خبرنامه عضو نیست.']);
    if ($row['status'] !== 'active') json_out(['ok'=>true,'message'=>'عضویت این شماره قبلاً لغو شده است.']);
    DB::run("UPDATE " . DB::t('newsletter') . " SET status='unsub', unsub_at=NOW() WHERE id=?", [(int)$row['id']]);
    log_activity('newsletter_unsub', 'user', null, ['mobile' => $mobile]);
    json_out(['ok'=>true,'message'=>'عضویت شما در خبرنامهٔ پیامکی لغو شد.']);
}

json_out(['ok'=>false,'message'=>'درخواست نامعتبر.']);
