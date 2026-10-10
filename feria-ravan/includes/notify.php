<?php
/**
 * ============================================================================
 *  اعلان‌های پیامکی مدیران و قالب‌های سفارشی رویداد — نسخهٔ ۱٫۷
 *  ─ fr_admin_notify_mobiles : شماره‌های ادمین‌ها + شماره‌های دلخواه (حسابداری…)
 *  ─ fr_notify_admins_sms    : ارسال قالبِ رویداد به همهٔ شماره‌های بالا
 *  ─ fr_event_user_sms       : پیامک «ثبت‌نام موفق» — متن اختصاصی رویداد اگر
 *                              مدیر برایش تعریف کرده باشد، وگرنه قالب پیش‌فرض
 *  ─ fr_refund_notify        : درخواست عودت وجه ← پیامک به کاربر + اطلاع به مدیر
 * ============================================================================
 */
declare(strict_types=1);

/** لیست موبایل‌های اطلاع‌رسانی مدیر — ادمین‌های دارای موبایل + شماره‌های دلخواه تنظیمات؛ بدون تکرار */
function fr_admin_notify_mobiles(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $list = [];
    try {
        foreach (DB::all("SELECT mobile FROM " . DB::t('admins') . " WHERE (status='active' OR status IS NULL) AND mobile IS NOT NULL AND mobile<>''") as $a) {
            $m = en_num((string)$a['mobile']);
            if (valid_mobile($m)) $list[] = $m;
        }
    } catch (Throwable $e) { /* جدول ادمین هنوز آماده نیست */ }
    foreach (preg_split('/[\s,;،]+/u', (string)DB::setting('admin_notify_mobiles', '')) as $m) {
        $m = en_num(trim((string)$m));
        if (valid_mobile($m)) $list[] = $m;
    }
    return $cache = array_values(array_unique($list));
}

/** ارسال قالب پیامکی (sms_templates) به همهٔ شماره‌های اطلاع‌رسانی مدیر — تعداد ارسال موفق */
function fr_notify_admins_sms(string $event, array $vars = []): int
{
    $sent = 0;
    $kv = new Kavenegar();
    foreach (fr_admin_notify_mobiles() as $mob) {
        if ($kv->sendEvent($event, $mob, $vars)) $sent++;
    }
    return $sent;
}

/** render متن پیامک اختصاصی رویداد — متغیرها: %name% %event% %amount% %refid% %site% %date% */
function fr_event_sms_render(string $tpl, string $name, string $eventTitle, int $amount, string $refId): string
{
    return tpl_vars($tpl, [
        'name'   => $name,
        'event'  => $eventTitle,
        'test'   => $eventTitle,            // سازگاری با عادت تایپ %test%
        'amount' => number_format($amount),
        'refid'  => $refId !== '' ? $refId : '—',
        'site'   => (string)DB::setting('site_title', 'فریا روان'),
        'date'   => jdate('j F Y'),
    ]);
}

/**
 * پیامک ثبت‌نام موفق به کاربر — اگر رویداد «متن پیامک اختصاصی» داشته باشد همان
 * ارسال می‌شود؛ در غیر این‌صورت قالب پیش‌فرض سیستم (sms_templates/payment_ok).
 */
function fr_event_user_sms(array $ev, array $u, int $amount, string $refId): void
{
    $name = user_fullname($u);
    $tpl  = trim((string)($ev['sms_template'] ?? ''));
    if ($tpl !== '') {
        (new Kavenegar())->send((string)$u['mobile'],
            fr_event_sms_render($tpl, $name, (string)$ev['title'], $amount, $refId) . "\n" . DB::setting('site_title', 'فریا روان'),
            'event_custom');
        return;
    }
    (new Kavenegar())->sendEvent('payment_ok', (string)$u['mobile'], [
        'name'    => $name,
        'amount'  => $amount,
        'refid'   => $refId,
        'test'    => (string)$ev['title'],
        '_tokens' => [$name, number_format($amount), $refId],
    ]);
}

/**
 * اطلاع‌رسانی درخواست عودت وجه:
 *  ۱) پیامک تأیید دریافت به خود کاربر (قالب refund_request_user)
 *  ۲) پیامک اطلاع به مدیران و شماره‌های دلخواه (قالب refund_request_admin)
 */
function fr_refund_notify(int $userId, int $amount, string $label, string $reason = ''): void
{
    $u = DB::one('SELECT id,mobile,first_name,last_name FROM ' . DB::t('users') . ' WHERE id=?', [$userId]);
    if (!$u) return;
    $name = user_fullname($u);
    $kv = new Kavenegar();
    $kv->sendEvent('refund_request_user', (string)$u['mobile'], [
        'name'   => $name,
        'amount' => $amount,
        'test'   => $label,
        'reason' => $reason !== '' ? $reason : '—',
    ]);
    fr_notify_admins_sms('refund_request_admin', [
        'name'   => $name,
        'mobile' => $u['mobile'],
        'amount' => $amount,
        'test'   => $label,
        'reason' => $reason !== '' ? mb_substr($reason, 0, 120) : '—',
    ]);
}
