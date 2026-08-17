<?php
/**
 * =============================================================================
 *  API بوکینگ مشاوره (نسخهٔ ۱٫۴)
 *  ─ GET  action=month → دادهٔ تقویم شمسی ماه (عمومی، بدون ورود)
 *  ─ GET  action=slots → ساعت‌های آزاد یک روز (عمومی)
 *  ─ POST action=book  → رزرو اتمیک + سفارش/درگاه (نیازمند ورود + CSRF)
 *  ─ POST action=cancel→ لغو نوبت توسط خود کاربر (سیاست ساعتی)
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/view.php';
require_once FR_ROOT . '/includes/commerce.php';
require_once FR_ROOT . '/includes/consult.php';
require_once FR_ROOT . '/core/Kavenegar.php';
require_once FR_ROOT . '/core/Zarinpal.php';
fr_bootstrap();

header('Content-Type: application/json; charset=utf-8');

/* GET برای خواندن، POST برای تغییر */
$in    = $_SERVER['REQUEST_METHOD'] === 'POST'
       ? (json_decode((string)file_get_contents('php://input'), true) ?: $_POST)
       : $_GET;
$act   = (string)($in['action'] ?? '');
$svId  = (int)($in['service'] ?? 0);

/* ---------------------------------------------------------------------------
 | تقویم ماه شمسی — عمومی (نرخ‌محدود ضد اسکن)
 * -------------------------------------------------------------------------*/
if ($act === 'month' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!rate_limit('consult_month', 120, 600)) json_out(['ok'=>false,'message'=>'تعداد درخواست زیاد است؛ کمی بعد تلاش کنید.']);
    $service = consult_service($svId);
    if (!$service) json_out(['ok'=>false,'message'=>'خدمت یافت نشد.']);
    consult_release_expired_holds();
    [$jy0, $jm0] = fr_jnow();          // ماه جاری شمسی (ASCII، نه خروجی فارسی jdate)
    $jy = (int)($in['jy'] ?? $jy0); $jm = (int)($in['jm'] ?? $jm0);
    // محدودهٔ مجاز مرور: ماه جاری تا ۶ ماه آینده
    $key0 = $jy0 * 12 + $jm0; $key = $jy * 12 + $jm;
    if ($key < $key0 || $key > $key0 + 6) { $jy = $jy0; $jm = $jm0; }
    $m = consult_month($svId, $jy, $jm);
    json_out(['ok'=>true, 'cur_jy'=>$jy0, 'cur_jm'=>$jm0] + $m);
}

/* ---------------------------------------------------------------------------
 | اسلات‌های آزاد یک روز — عمومی
 * -------------------------------------------------------------------------*/
if ($act === 'slots' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!rate_limit('consult_slots', 120, 600)) json_out(['ok'=>false,'message'=>'تعداد درخواست زیاد است؛ کمی بعد تلاش کنید.']);
    $service = consult_service($svId);
    if (!$service) json_out(['ok'=>false,'message'=>'خدمت یافت نشد.']);
    $date = (string)($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_out(['ok'=>false,'message'=>'تاریخ نامعتبر است.']);
    if (consult_is_holiday($date)) json_out(['ok'=>true,'slots'=>[],'message'=>'این روز تعطیل است.']);
    json_out(['ok'=>true,'slots'=>consult_day_slots($svId, $date)]);
}

/* ---------------------------------------------------------------------------
 | رزرو — نیازمند ورود
 * -------------------------------------------------------------------------*/
if ($act === 'book') {
    $u = current_user();
    if (!$u) json_out(['ok'=>false,'message'=>'برای رزرو ابتدا وارد حساب شوید.']);
    if (!rate_limit('consult_book:' . (int)$u['id'], 10, 600))
        json_out(['ok'=>false,'message'=>'تلاش‌های رزرو زیاد شد؛ چند دقیقهٔ دیگر تلاش کنید.']);

    $service = consult_service($svId);
    if (!$service) json_out(['ok'=>false,'message'=>'خدمت یافت نشد یا غیرفعال است.']);

    $slotId = (int)($in['slot'] ?? 0);
    $mode   = (string)($in['mode'] ?? 'inperson');
    $note   = mb_substr(trim((string)($in['note'] ?? '')), 0, 500);

    $useWallet = !empty($in['wallet']);
    $discount  = mb_substr(trim((string)($in['discount'] ?? '')), 0, 60);
    /* ۳٫۰ — انتخاب کاربر: پرداخت کامل یا پیش‌پرداخت (بیعانه) */
    $payMode   = (string)($in['pay_mode'] ?? 'full') === 'deposit' ? 'deposit' : 'full';
    $r = consult_book($u, $service, $slotId, $mode, $note, $useWallet, $discount !== '' ? $discount : null, $payMode);
    if (!$r['ok']) json_out(['ok'=>false,'message'=>$r['error'],'refresh'=>true]);

    $depDone = (int)($r['deposit'] ?? 0) > 0;
    json_out(['ok'=>true,
              'payment_url'=>$r['payment_url'],
              'free'=>$r['free'],
              'appointment_id'=>$r['appointment_id'],
              'message'=>$r['free']
                    ? ($depDone
                        ? 'پیش‌پرداخت شما ثبت شد و نوبت قطعی است 🎉 — مانده را با لینک پیامکی یا پنل کاربری تسویه کنید.'
                        : (!empty($r['wallet']) ? 'نوبت شما با کیف پول قطعی شد 🎉👛' : 'نوبت شما ثبت شد 🎉'))
                    : ($depDone ? 'به درگاه پیش‌پرداخت منتقل می‌شوید…' : 'به درگاه پرداخت منتقل می‌شوید…')]);
}

/* ---------------------------------------------------------------------------
 | بررسی زندهٔ کد تخفیف برای یک خدمت مشاوره — نسخهٔ ۱٫۷ (فقط کدهای عمومی)
 * -------------------------------------------------------------------------*/
if ($act === 'check_discount') {
    $u = current_user();
    if (!$u) json_out(['ok'=>false,'message'=>'برای استفاده از کد تخفیف ابتدا وارد حساب شوید.']);
    $service = consult_service($svId);
    if (!$service) json_out(['ok'=>false,'message'=>'خدمت یافت نشد.']);
    $price = (int)$service['price'];
    if ($price <= 0) json_out(['ok'=>false,'message'=>'این جلسه رایگان است و تخفیف لازم ندارد.']);
    $code = trim((string)($in['code'] ?? ''));
    if ($code === '') json_out(['ok'=>false,'message'=>'کد تخفیف را بنویسید.']);
    require_once FR_ROOT . '/includes/commerce.php';
    $v = validate_discount($code, 0, (int)$u['id'], $price, 0);
    if (!$v['ok']) json_out(['ok'=>false,'message'=>$v['message']]);
    json_out(['ok'=>true,'message'=>$v['message'],
              'discount'=>$v['discount_amt'],'payable'=>$v['payable'],
              'discount_f'=>toman($v['discount_amt']),'payable_f'=>toman($v['payable'])]);
}

/* ---------------------------------------------------------------------------
 | لغو نوبت توسط کاربر
 * -------------------------------------------------------------------------*/
if ($act === 'cancel') {
    $u = current_user();
    if (!$u) json_out(['ok'=>false,'message'=>'ابتدا وارد حساب شوید.']);
    $apptId = (int)($in['appointment'] ?? 0);
    $r = consult_cancel_by_user($apptId, (int)$u['id']);
    json_out($r);
}

json_out(['ok'=>false,'message'=>'درخواست نامعتبر است.']);
