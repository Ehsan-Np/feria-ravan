<?php
/**
 * ============================================================================
 *  موتور پیش‌پرداخت (بیعانه) و تصفیهٔ حساب — نسخهٔ ۳٫۰
 *  ─ برای کارگاه‌ها، دوره‌ها و نوبت‌های مشاوره: کاربر به‌جای کل مبلغ، یک
 *    بیعانهٔ ازپیش‌تعریف‌شده توسط مدیر می‌پردازد تا جایش «قطعی» شود؛
 *  ─ ماندهٔ حساب حداکثر تا ۳ روز مانده به برگزاری (قابل‌تنظیم: deposit_remind_hours)
 *    با یادآور خودکار (پیامک + تلگرام/بله) و «لینک اختصاصی تصفیه» تسویه می‌شود؛
 *  ─ لینک تصفیه دارای توکن HMAC است و حتی بدون ورود به حساب هم کار می‌کند؛
 *    همان لینک در پنل کاربری هم به‌صورت دکمهٔ «تصفیهٔ حساب» می‌نشیند؛
 *  ─ چرخهٔ سفارش: سفارش مادر (pay_stage=deposit) ← سفارش تصفیه (pay_stage=settle
 *    با parent_order_id به سفارش مادر)؛ جمع پرداخت‌شده در paid_total ردیف می‌شود.
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/Kavenegar.php';
require_once __DIR__ . '/../core/Zarinpal.php';

/* ---------------------------------------------------------------------------
 | تشخیص قابلیت پیش‌پرداخت
 * -------------------------------------------------------------------------*/
/**
 * آیا پیش‌پرداخت برای این رویداد/خدمت فعال و معتبر است؟
 * شرط‌ها: فعال‌شده + مبلغ مثبت + کمتر از قیمت نهایی (با تخفیف قیمت‌گذاری رویداد)
 */
function fr_deposit_available(array $entity): bool
{
    if ((int)($entity['deposit_enabled'] ?? 0) !== 1) return false;
    $dep = (int)($entity['deposit_amount'] ?? 0);
    if ($dep <= 0) return false;
    $full = fr_price_deal((int)($entity['price'] ?? 0),
                          isset($entity['sale_price']) ? (int)$entity['sale_price'] : null)['final'];
    return $full > 0 && $dep < $full;
}

/** مبلغ بیعانهٔ قابل پرداخت هم‌اکنون — هرگز از ماندهٔ فاکتور بیشتر نمی‌شود */
function fr_deposit_charge(array $entity, int $payable): int
{
    if (!fr_deposit_available($entity) || $payable <= 1) return 0;
    $dep = (int)$entity['deposit_amount'];
    return ($dep > 0 && $dep < $payable) ? $dep : 0;
}

/* ---------------------------------------------------------------------------
 | ماندهٔ حساب ثبت‌نام رویداد / نوبت مشاوره
 * -------------------------------------------------------------------------*/
/** مبلغ کل قابل پرداخت یک ثبت‌نام — از اسنپ‌شات فاکتور (discount_meta.payable) */
function fr_reg_total(array $reg): int
{
    $meta = json_decode((string)($reg['discount_meta'] ?? ''), true);
    if (is_array($meta) && isset($meta['payable'])) return max(0, (int)$meta['payable']);
    return max(0, (int)($reg['amount_base'] ?? 0) - (int)($reg['discount_amount'] ?? 0));
}

/** ماندهٔ قابل تصفیهٔ یک ثبت‌نام رویداد */
function fr_reg_remaining(array $reg): int
{
    return max(0, fr_reg_total($reg) - (int)($reg['paid_total'] ?? 0));
}

/** مبلغ کل قابل پرداخت یک نوبت مشاوره — از سفارش مادر */
function fr_appt_total(array $appt): int
{
    return max(0, (int)DB::val('SELECT payable FROM ' . DB::t('orders') . ' WHERE id=?',
                               [(int)($appt['order_id'] ?? 0)]));
}

/** ماندهٔ قابل تصفیهٔ یک نوبت مشاوره */
function fr_appt_remaining(array $appt): int
{
    return max(0, fr_appt_total($appt) - (int)($appt['paid_total'] ?? 0));
}

/* ---------------------------------------------------------------------------
 | لینک اختصاصی تصفیهٔ حساب (توکن HMAC — معتبر مادامی که رکورد همان کاربر است)
 * -------------------------------------------------------------------------*/
function fr_settle_token(string $type, int $id, int $userId): string
{
    $type = $type === 'appt' ? 'appt' : 'reg';
    return substr(hash_hmac('sha256', 'settle|' . $type . '|' . $id . '|' . $userId, FR_CRYPT_KEY), 0, 40);
}

function fr_settle_token_ok(string $type, int $id, int $userId, string $tok): bool
{
    return $tok !== '' && hash_equals(fr_settle_token($type, $id, $userId), $tok);
}

/** لینک تصفیهٔ ثبت‌نام رویداد — نیازمند ردیف ثبت‌نام با ستون‌های id/user_id */
function fr_settle_url_reg(array $reg): string
{
    return rtrim(SITE_URL, '/') . '/settle.php?t=reg&id=' . (int)$reg['id']
         . '&tok=' . fr_settle_token('reg', (int)$reg['id'], (int)$reg['user_id']);
}

/** لینک تصفیهٔ نوبت مشاوره */
function fr_settle_url_appt(array $appt): string
{
    return rtrim(SITE_URL, '/') . '/settle.php?t=appt&id=' . (int)$appt['id']
         . '&tok=' . fr_settle_token('appt', (int)$appt['id'], (int)$appt['user_id']);
}

/* ---------------------------------------------------------------------------
 | رندر قالب پیامک‌های پیش‌پرداخت/تصفیه — متغیرها (سبک {placeholder}):
 | {name} {item} {thing} {total} {paid} {remain} {link} {panel} {when} {days} {refid} {site}
 * -------------------------------------------------------------------------*/
function fr_deposit_sms_render(string $tpl, array $vars): string
{
    $vars['site']  = $vars['site']  ?? (string)DB::setting('site_title', 'فریا روان');
    $vars['refid'] = $vars['refid'] ?? '—';
    $map = [];
    foreach ($vars as $k => $v) { $map['{' . $k . '}'] = (string)$v; }
    return strtr($tpl, $map);
}

/**
 * پیامک پس از پرداخت پیش‌پرداختِ ثبت‌نام رویداد — تأیید قطعی‌شدن + مانده + لینک تصفیه
 */
function fr_deposit_notify_reg(int $regId): void
{
    $reg = DB::one('SELECT r.*, e.title, e.kind, e.starts_at
                    FROM ' . DB::t('event_registrations') . ' r
                    JOIN ' . DB::t('events') . ' e ON e.id=r.event_id WHERE r.id=?', [$regId]);
    if (!$reg) return;
    $u = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [(int)$reg['user_id']]);
    if (!$u) return;
    $total  = fr_reg_total($reg);
    $paid   = (int)$reg['paid_total'];
    $kindFa = $reg['kind'] === 'course' ? 'دوره' : 'کارگاه';
    $tpl = trim((string)DB::setting('sms_tpl_deposit_user', ''));
    if ($tpl === '') {
        $tpl = "{name} عزیز، پیش‌پرداخت شما برای «{item}» با موفقیت ثبت شد و {thing} شما قطعی است. 🦢\nماندهٔ قابل تصفیه: {remain} تومان\nلینک تصفیهٔ حساب: {link}\nهمچنین می‌توانید وارد پنل کاربری خود شوید و از بخش «{panel}» پرداختتان را تکمیل کنید.";
    }
    $text = fr_deposit_sms_render($tpl, [
        'name'   => user_fullname($u) ?: 'کاربر',
        'item'   => (string)$reg['title'],
        'thing'  => 'ثبت‌نام شما در ' . $kindFa,
        'total'  => number_format($total),
        'paid'   => number_format($paid),
        'remain' => number_format(max(0, $total - $paid)),
        'link'   => fr_settle_url_reg($reg),
        'panel'  => $reg['kind'] === 'course' ? 'دوره‌های من' : 'کارگاه‌های من',
        'when'   => $reg['starts_at'] ? fa_num(jdate('j F Y ساعت H:i', strtotime($reg['starts_at']))) : '—',
        'days'   => $reg['starts_at'] ? fa_num(max(0, (int)ceil((strtotime($reg['starts_at']) - time()) / 86400))) : '۰',
    ]);
    (new Kavenegar())->send((string)$u['mobile'], $text . "\n" . DB::setting('site_title', 'فریا روان'), 'deposit_ok');
    log_activity('deposit_paid', 'event', (int)$reg['event_id'], ['reg' => $regId, 'remain' => max(0, $total - $paid)]);
}

/** پیامک پس از پیش‌پرداختِ نوبت مشاوره */
function fr_deposit_notify_appt(int $apptId): void
{
    $a = DB::one('SELECT a.*, s.starts_at, sv.title
                  FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id
                  JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id
                  WHERE a.id=?', [$apptId]);
    if (!$a) return;
    $u = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [(int)$a['user_id']]);
    if (!$u) return;
    $total = fr_appt_total($a);
    $paid  = (int)$a['paid_total'];
    $tpl = trim((string)DB::setting('sms_tpl_deposit_user', ''));
    if ($tpl === '') {
        $tpl = "{name} عزیز، پیش‌پرداخت شما برای «{item}» با موفقیت ثبت شد و {thing} شما قطعی است. 🦢\nماندهٔ قابل تصفیه: {remain} تومان\nلینک تصفیهٔ حساب: {link}\nهمچنین می‌توانید وارد پنل کاربری خود شوید و از بخش «{panel}» پرداختتان را تکمیل کنید.";
    }
    $text = fr_deposit_sms_render($tpl, [
        'name'   => user_fullname($u) ?: 'کاربر',
        'item'   => (string)$a['title'],
        'thing'  => 'نوبت شما در جلسهٔ مشاوره',
        'total'  => number_format($total),
        'paid'   => number_format($paid),
        'remain' => number_format(max(0, $total - $paid)),
        'link'   => fr_settle_url_appt($a),
        'panel'  => 'نوبت‌های مشاورهٔ من',
        'when'   => fa_num(jdate('j F Y ساعت H:i', strtotime($a['starts_at']))),
        'days'   => fa_num(max(0, (int)ceil((strtotime($a['starts_at']) - time()) / 86400))),
    ]);
    (new Kavenegar())->send((string)$u['mobile'], $text . "\n" . DB::setting('site_title', 'فریا روان'), 'deposit_ok');
    log_activity('deposit_paid', 'consult_appointment', $apptId, ['remain' => max(0, $total - $paid)]);
}

/** پیامک «تصفیهٔ کامل انجام شد» — ثبت‌نام رویداد */
function fr_settle_notify_reg(int $regId, string $refId = ''): void
{
    $reg = DB::one('SELECT r.*, e.title, e.kind FROM ' . DB::t('event_registrations') . ' r
                    JOIN ' . DB::t('events') . ' e ON e.id=r.event_id WHERE r.id=?', [$regId]);
    if (!$reg) return;
    $u = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [(int)$reg['user_id']]);
    if (!$u) return;
    $kindFa = $reg['kind'] === 'course' ? 'دوره' : 'کارگاه';
    $tpl = trim((string)DB::setting('sms_tpl_settle_done_user', ''));
    if ($tpl === '') {
        $tpl = "{name} عزیز، تصفیهٔ حساب «{item}» با موفقیت انجام شد و {thing} شما به‌صورت کامل نهایی است. 🎉\nکد رهگیری: {refid}";
    }
    $text = fr_deposit_sms_render($tpl, [
        'name' => user_fullname($u) ?: 'کاربر', 'item' => (string)$reg['title'],
        'thing' => 'ثبت‌نام شما در ' . $kindFa, 'refid' => $refId !== '' ? $refId : '—',
    ]);
    (new Kavenegar())->send((string)$u['mobile'], $text . "\n" . DB::setting('site_title', 'فریا روان'), 'settle_done');
}

/** پیامک «تصفیهٔ کامل انجام شد» — نوبت مشاوره */
function fr_settle_notify_appt(int $apptId, string $refId = ''): void
{
    $a = DB::one('SELECT a.*, sv.title FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id WHERE a.id=?', [$apptId]);
    if (!$a) return;
    $u = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [(int)$a['user_id']]);
    if (!$u) return;
    $tpl = trim((string)DB::setting('sms_tpl_settle_done_user', ''));
    if ($tpl === '') {
        $tpl = "{name} عزیز، تصفیهٔ حساب «{item}» با موفقیت انجام شد و {thing} شما به‌صورت کامل نهایی است. 🎉\nکد رهگیری: {refid}";
    }
    $text = fr_deposit_sms_render($tpl, [
        'name' => user_fullname($u) ?: 'کاربر', 'item' => (string)$a['title'],
        'thing' => 'نوبت شما در جلسهٔ مشاوره', 'refid' => $refId !== '' ? $refId : '—',
    ]);
    (new Kavenegar())->send((string)$u['mobile'], $text . "\n" . DB::setting('site_title', 'فریا روان'), 'settle_done');
}

/* ---------------------------------------------------------------------------
 | ساخت سفارش تصفیهٔ حساب — از صفحهٔ settle.php یا پنل کاربری
 * -------------------------------------------------------------------------*/
/**
 * سفارش تصفیه برای ثبت‌نام رویداد (kind=event, pay_stage=settle, parent←سفارش مادر)
 * @return array ['ok'=>bool,'payment_url'=>?string,'error'=>?string,'free'=>bool,'order_id'=>int,'remaining'=>int]
 */
function fr_settle_create_order_reg(array $reg, array $u, bool $useWallet = false): array
{
    $fail = fn(string $m) => ['ok'=>false,'payment_url'=>null,'error'=>$m,'free'=>false,'order_id'=>0,'remaining'=>0];
    if (($reg['status'] ?? '') !== 'deposit') return $fail('این ثبت‌نام ماندهٔ قابل تصفیه ندارد (پیش‌پرداخت انجام نشده یا قبلاً نهایی شده است).');
    $remaining = fr_reg_remaining($reg);
    if ($remaining <= 0) return $fail('مانده‌ای برای پرداخت وجود ندارد؛ ثبت‌نام شما کاملاً تسویه است. ✅');
    $ev = DB::one('SELECT * FROM ' . DB::t('events') . ' WHERE id=?', [(int)$reg['event_id']]);
    if (!$ev) return $fail('رویداد یافت نشد.');

    /* سفارش تصفیهٔ باز از قبل ساخته شده؟ (جلوگیری از تکرار با دابل‌کلیک) */
    $dup = DB::one('SELECT p.authority, o.id AS oid FROM ' . DB::t('orders') . ' o
                    JOIN ' . DB::t('payments') . " p ON p.order_id=o.id AND p.status='init'
                    WHERE o.parent_order_id=? AND o.pay_stage='settle' AND o.status='pending'
                    ORDER BY o.id DESC LIMIT 1", [(int)$reg['order_id']]);
    // برای سادگی و شفافیت مالی، هر تلاش سفارش تازه می‌سازد؛ تلاش‌های پرداخت‌نشدهٔ قبلی باطل می‌شوند
    DB::run('UPDATE ' . DB::t('orders') . " SET status='canceled'
             WHERE parent_order_id=? AND pay_stage='settle' AND status='pending'", [(int)$reg['order_id']]);

    $orderId = DB::insert('orders', [
        'user_id' => (int)$reg['user_id'], 'test_id' => null, 'event_id' => (int)$reg['event_id'], 'kind' => 'event',
        'amount' => $remaining, 'discount_code' => null, 'discount_amt' => 0, 'payable' => $remaining,
        'pay_stage' => 'settle', 'parent_order_id' => (int)($reg['order_id'] ?? 0) > 0 ? (int)$reg['order_id'] : null,
    ]);
    log_activity('pay_init', 'order', $orderId, ['event' => (int)$reg['event_id'], 'stage' => 'settle', 'amount' => $remaining]);

    /* پرداخت با کیف پول (کل یا بخشی) — اختیاری */
    $chargeNow = $remaining;
    if ($useWallet) {
        require_once __DIR__ . '/wallet.php';
        $wp = wallet_pay_part($orderId, (int)$reg['user_id'], $chargeNow,
                              'تصفیهٔ حساب «' . $ev['title'] . '» از کیف پول');
        if ($wp['gateway'] <= 0) {
            return ['ok'=>true,'payment_url'=>null,'error'=>null,'free'=>true,'wallet'=>true,'order_id'=>$orderId,'remaining'=>0];
        }
        $chargeNow = $wp['gateway'];
    }

    $zp  = new Zarinpal();
    $res = $zp->request($orderId, $chargeNow, 'تصفیهٔ حساب رویداد «' . $ev['title'] . '»', (string)$u['mobile']);
    if (!$res['ok']) {
        require_once __DIR__ . '/wallet.php';
        fr_cancel_dead_order($orderId, 'خطای اتصال به درگاه');
        return $fail('خطا در اتصال به درگاه پرداخت: ' . $res['message']);
    }
    DB::insert('payments', [
        'order_id'=>$orderId, 'authority'=>$res['authority'], 'amount'=>$chargeNow,
        'ip'=>client_ip_bin(), 'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    return ['ok'=>true,'payment_url'=>$res['url'],'error'=>null,'free'=>false,'order_id'=>$orderId,'remaining'=>$remaining];
}

/** سفارش تصفیه برای نوبت مشاوره (kind=consult) */
function fr_settle_create_order_appt(array $appt, array $u, bool $useWallet = false): array
{
    $fail = fn(string $m) => ['ok'=>false,'payment_url'=>null,'error'=>$m,'free'=>false,'order_id'=>0,'remaining'=>0];
    if (($appt['status'] ?? '') !== 'booked' || (int)($appt['paid_total'] ?? 0) <= 0)
        return $fail('این نوبت ماندهٔ قابل تصفیه ندارد (پیش‌پرداخت انجام نشده یا قبلاً نهایی شده است).');
    $remaining = fr_appt_remaining($appt);
    if ($remaining <= 0) return $fail('مانده‌ای برای پرداخت وجود ندارد؛ حساب شما کاملاً تسویه است. ✅');
    $sv = DB::one('SELECT * FROM ' . DB::t('consult_services') . ' WHERE id=?', [(int)$appt['service_id']]);
    if (!$sv) return $fail('خدمت یافت نشد.');

    DB::run('UPDATE ' . DB::t('orders') . " SET status='canceled'
             WHERE parent_order_id=? AND pay_stage='settle' AND status='pending'", [(int)$appt['order_id']]);

    $orderId = DB::insert('orders', [
        'user_id' => (int)$appt['user_id'], 'test_id' => null, 'event_id' => null, 'kind' => 'consult',
        'amount' => $remaining, 'discount_code' => null, 'discount_amt' => 0, 'payable' => $remaining,
        'pay_stage' => 'settle', 'parent_order_id' => (int)($appt['order_id'] ?? 0) > 0 ? (int)$appt['order_id'] : null,
    ]);
    log_activity('pay_init', 'order', $orderId, ['consult' => (int)$appt['service_id'], 'stage' => 'settle', 'amount' => $remaining]);

    $chargeNow = $remaining;
    if ($useWallet) {
        require_once __DIR__ . '/wallet.php';
        $wp = wallet_pay_part($orderId, (int)$appt['user_id'], $chargeNow,
                              'تصفیهٔ حساب نوبت مشاوره «' . $sv['title'] . '» از کیف پول');
        if ($wp['gateway'] <= 0) {
            return ['ok'=>true,'payment_url'=>null,'error'=>null,'free'=>true,'wallet'=>true,'order_id'=>$orderId,'remaining'=>0];
        }
        $chargeNow = $wp['gateway'];
    }

    $zp  = new Zarinpal();
    $res = $zp->request($orderId, $chargeNow, 'تصفیهٔ حساب نوبت مشاوره «' . $sv['title'] . '»', (string)$u['mobile']);
    if (!$res['ok']) {
        require_once __DIR__ . '/wallet.php';
        fr_cancel_dead_order($orderId, 'خطای اتصال به درگاه');
        return $fail('خطا در اتصال به درگاه پرداخت: ' . $res['message']);
    }
    DB::insert('payments', [
        'order_id'=>$orderId, 'authority'=>$res['authority'], 'amount'=>$chargeNow,
        'ip'=>client_ip_bin(), 'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    return ['ok'=>true,'payment_url'=>$res['url'],'error'=>null,'free'=>false,'order_id'=>$orderId,'remaining'=>$remaining];
}

/* ---------------------------------------------------------------------------
 | انتخاب‌گرهای کرون — سررسید یادآور تصفیه
 * -------------------------------------------------------------------------*/
/**
 * ثبت‌نام‌های پیش‌پرداخت‌شده‌ای که رویدادشان تا $hours ساعت آینده برگزار می‌شود
 * و هنوز یادآور تصفیه دریافت نکرده‌اند («نهایتاً ۳ روز مانده»).
 */
function fr_settle_due_registrations(int $hours): array
{
    $hours = max(1, min(720, $hours));
    return DB::all("SELECT r.*, e.title, e.kind, e.slug, e.starts_at,
                           u.mobile AS umobile, u.first_name, u.last_name,
                           u.telegram_chat_id, u.bale_chat_id
                    FROM " . DB::t('event_registrations') . " r
                    JOIN " . DB::t('events') . " e ON e.id=r.event_id
                    JOIN " . DB::t('users') . " u ON u.id=r.user_id
                    WHERE r.status='deposit' AND r.settle_reminded_at IS NULL
                      AND e.starts_at IS NOT NULL AND e.starts_at > NOW()
                      AND e.starts_at <= NOW() + INTERVAL " . $hours . " HOUR
                    ORDER BY e.starts_at LIMIT 200");
}

/** نوبت‌های مشاورهٔ پیش‌پرداخت‌شده با ماندهٔ باز که جلسه‌شان تا $hours ساعت آینده است */
function fr_settle_due_appointments(int $hours): array
{
    $hours = max(1, min(720, $hours));
    return DB::all("SELECT a.*, s.starts_at, sv.title,
                           o.payable AS ototal, o.pay_stage,
                           u.mobile AS umobile, u.first_name, u.last_name,
                           u.telegram_chat_id, u.bale_chat_id
                    FROM " . DB::t('consult_appointments') . " a
                    JOIN " . DB::t('consult_slots') . " s ON s.id=a.slot_id
                    JOIN " . DB::t('consult_services') . " sv ON sv.id=a.service_id
                    JOIN " . DB::t('orders') . " o ON o.id=a.order_id
                    JOIN " . DB::t('users') . " u ON u.id=a.user_id
                    WHERE a.status='booked' AND a.settle_reminded_at IS NULL
                      AND o.pay_stage='deposit' AND a.paid_total < o.payable
                      AND s.starts_at > NOW() AND s.starts_at <= NOW() + INTERVAL " . $hours . " HOUR
                    ORDER BY s.starts_at LIMIT 200");
}
