<?php
/**
 * ============================================================================
 *  کیف پول و عودت وجه فریا روان — نسخهٔ ۱٫۶
 *  ─ منبع حقیقت موجودی: جدول wallet_transactions (مثبت=شارژ، منفی=برداشت)
 *    ستون wallet_balance در users فقط کشِ سریع است و همیشه سینک می‌شود.
 *  ─ مسیر عودت وجه (الگو از سورس مادر مخزن گیت‌هاب):
 *    کاربر → ثبت درخواست (در صورت داشتن کارت بانکی + قبل از برگزاری)
 *    مدیر → تأیید/رد → پرداخت: واریز دستی (با کد پیگیری) یا واریز به کیف پول
 * ============================================================================
 */
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 | موجودی و تراکنش‌ها
 * -------------------------------------------------------------------------*/

/** موجودی قابل‌استفادهٔ کاربر (جمع تراکنش‌های منقضی‌نشده) */
function wallet_balance(int $userId): int
{
    return (int)DB::val('SELECT COALESCE(SUM(amount),0) FROM ' . DB::t('wallet_transactions')
        . ' WHERE user_id=? AND (expires_at IS NULL OR expires_at>NOW())', [$userId]);
}

/** سینک کشِ موجودی در جدول کاربران */
function wallet_sync(int $userId): int
{
    $bal = wallet_balance($userId);
    DB::update('users', ['wallet_balance' => $bal], 'id=?', [$userId]);
    return $bal;
}

/**
 * شارژ کیف پول (مبلغ مثبت)
 * kinds: credit (شارژ دستی مدیر) | refund (عودت وجه) | gift (هدیه) | adjustment (تعدیل)
 */
function wallet_credit(int $userId, int $amount, string $kind, string $desc, string $refType = '', int $refId = 0): int
{
    if ($amount <= 0) throw new RuntimeException('مبلغ شارژ باید بزرگ‌تر از صفر باشد.');
    if (!in_array($kind, ['credit','refund','gift','adjustment'], true)) $kind = 'credit';
    $id = DB::insert('wallet_transactions', [
        'user_id' => $userId, 'amount' => $amount, 'kind' => $kind,
        'reference_type' => $refType !== '' ? $refType : null,
        'reference_id'   => $refId > 0 ? $refId : null,
        'description'    => mb_substr($desc, 0, 255),
    ]);
    wallet_sync($userId);
    log_activity('wallet_credit', 'user', $userId, ['amount'=>$amount, 'kind'=>$kind]);
    return $id;
}

/**
 * برداشت از کیف پول (ذخیره به‌صورت مبلغ منفی) — تراکنشی و امن در برابر مسابقه
 * @throws RuntimeException اگر موجودی کافی نباشد
 */
function wallet_debit(int $userId, int $amount, string $desc, string $refType = '', int $refId = 0): int
{
    if ($amount <= 0) throw new RuntimeException('مبلغ برداشت باید بزرگ‌تر از صفر باشد.');
    $pdo = DB::pdo();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // قفل ردیف کاربر تا دو خرید هم‌زمان موجودی را منفی نکنند
        DB::run('SELECT id FROM ' . DB::t('users') . ' WHERE id=? FOR UPDATE', [$userId]);
        $bal = (int)DB::val('SELECT COALESCE(SUM(amount),0) FROM ' . DB::t('wallet_transactions')
            . ' WHERE user_id=? AND (expires_at IS NULL OR expires_at>NOW())', [$userId]);
        if ($bal < $amount) throw new RuntimeException('موجودی کیف پول کافی نیست.');
        $id = DB::insert('wallet_transactions', [
            'user_id' => $userId, 'amount' => -$amount, 'kind' => 'debit',
            'reference_type' => $refType !== '' ? $refType : null,
            'reference_id'   => $refId > 0 ? $refId : null,
            'description'    => mb_substr($desc, 0, 255),
        ]);
        wallet_sync($userId);
        if ($own) $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** تاریخچهٔ تراکنش‌های کاربر */
function wallet_transactions(int $userId, int $limit = 100): array
{
    return DB::all('SELECT * FROM ' . DB::t('wallet_transactions')
        . ' WHERE user_id=? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$userId]);
}

/* ---------------------------------------------------------------------------
 | کارت بانکی کاربر (برای واریز عودت وجه) — نگهداری رمزنگاری‌شده AES-256-GCM
 * -------------------------------------------------------------------------*/

/** اعتبارسنجی شماره کارت ۱۶ رقمی ایران (الگوریتم لوهن) */
function fr_valid_bank_card(string $digits): bool
{
    $d = en_num(preg_replace('/\D/', '', $digits));
    if (!preg_match('/^[0-9]{16}$/', $d)) return false;
    $sum = 0;
    for ($i = 0; $i < 16; $i++) {
        $n = (int)$d[$i];
        if ($i % 2 === 0) { $n *= 2; if ($n > 9) $n -= 9; }
        $sum += $n;
    }
    return $sum % 10 === 0;
}

/** ذخیرهٔ کارت بانکی کاربر (رمزنگاری‌شده) — خروجی: چهار رقم آخر یا خطا */
function fr_set_bank_card(int $userId, string $digits): array
{
    $d = en_num(preg_replace('/\D/', '', $digits));
    if (!fr_valid_bank_card($d)) return ['ok'=>false,'message'=>'شماره کارت ۱۶ رقمی معتبر نیست.'];
    DB::update('users', [
        'bank_card_enc'   => fr_encrypt($d),
        'bank_card_last4' => substr($d, -4),
    ], 'id=?', [$userId]);
    return ['ok'=>true,'last4'=>substr($d, -4)];
}

/** آیا کاربر کارت ثبت کرده؟ */
function fr_has_bank_card(array $u): bool
{
    return !empty($u['bank_card_enc']);
}

/** نمایش امن کارت: فقط چهار رقم آخر */
function fr_bank_card_mask(?string $last4): string
{
    $l = (string)$last4;
    return $l !== '' ? '**** **** **** ' . e($l) : '—';
}

/** دریافت کامل کارت (فقط برای مدیر در صف عودت وجه) */
function fr_bank_card_full(array $u): string
{
    $d = fr_decrypt((string)($u['bank_card_enc'] ?? '')) ?: '';
    return $d !== '' ? chunk_split($d, 4, ' ') : '';
}

/* ---------------------------------------------------------------------------
 | درخواست عودت وجه
 * -------------------------------------------------------------------------*/

/**
 * ثبت درخواست عودت وجه ثبت‌نام رویداد (کارگاه/دوره)
 * قواعد (مطابق سورس مادر): ثبت‌نام پرداخت‌شده + رویداد شروع نشده + کارت بانکی ثبت‌شده
 */
function refund_request_event(int $regId, int $userId, string $reason): int
{
    if (!DB::val('SELECT bank_card_enc FROM ' . DB::t('users') . ' WHERE id=?', [$userId])) {
        throw new RuntimeException('برای ثبت درخواست عودت وجه، ابتدا شماره کارت بانکی‌تان را در «ویرایش پروفایل» وارد کنید.');
    }
    $r = DB::one('SELECT r.*, e.starts_at, e.title FROM ' . DB::t('event_registrations') . ' r
                  JOIN ' . DB::t('events') . ' e ON e.id=r.event_id
                  WHERE r.id=? AND r.user_id=? AND r.status IN (\'paid\',\'deposit\')', [$regId, $userId]);
    if (!$r) throw new RuntimeException('این ثبت‌نام برای درخواست عودت وجه معتبر نیست (پرداخت‌نشده یا لغوشده است).');
    if (!empty($r['starts_at']) && strtotime($r['starts_at']) < time()) {
        throw new RuntimeException('رویداد برگزار شده است؛ برای بررسی عودت وجه از «پشتیبانی» تیکت بزنید.');
    }
    /* ۳٫۰: فقط همان مبلغی که واقعاً پرداخت شده (پیش‌پرداخت/پرداخت کامل) عودت داده می‌شود */
    $paidCol = (int)DB::val('SELECT paid_total FROM ' . DB::t('event_registrations') . ' WHERE id=?', [$regId]);
    $amount  = $paidCol > 0 ? $paidCol
                            : (int)DB::val('SELECT payable FROM ' . DB::t('orders') . ' WHERE id=?', [(int)$r['order_id']]);
    if ($amount <= 0) throw new RuntimeException('ثبت‌نام رایگان مبلغی برای عودت ندارد.');
    try {
        $id = DB::insert('refund_requests', [
            'context' => 'event', 'reference_id' => $regId, 'user_id' => $userId,
            'amount' => $amount, 'reason' => mb_substr(trim($reason), 0, 2000),
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_refund_once') || str_contains($e->getMessage(), 'Duplicate'))
            throw new RuntimeException('برای این ثبت‌نام قبلاً درخواست عودت وجه ثبت کرده‌اید.');
        throw $e;
    }
    log_activity('refund_request', 'refund', $id, ['reg'=>$regId, 'amount'=>$amount]);
    // نسخهٔ ۱٫۷: پیامک تأیید دریافت به کاربر + اطلاع به مدیر/حسابداری
    require_once __DIR__ . '/notify.php';
    fr_refund_notify($userId, $amount, 'ثبت‌نام «' . (string)$r['title'] . '»', $reason);
    return $id;
}

/** ثبت درخواست عودت وجه نوبت مشاوره */
function refund_request_consult(int $apptId, int $userId, string $reason): int
{
    if (!DB::val('SELECT bank_card_enc FROM ' . DB::t('users') . ' WHERE id=?', [$userId])) {
        throw new RuntimeException('برای ثبت درخواست عودت وجه، ابتدا شماره کارت بانکی‌تان را در «ویرایش پروفایل» وارد کنید.');
    }
    $a = DB::one('SELECT a.*, s.starts_at, sv.title AS service_title FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id
                  JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id
                  WHERE a.id=? AND a.user_id=? AND a.status=\'booked\'', [$apptId, $userId]);
    if (!$a) throw new RuntimeException('این نوبت برای درخواست عودت وجه معتبر نیست.');
    if (strtotime($a['starts_at']) < time()) {
        throw new RuntimeException('زمان جلسه گذشته است؛ برای بررسی از «پشتیبانی» تیکت بزنید.');
    }
    /* ۳٫۰: فقط همان مبلغی که واقعاً پرداخت شده (پیش‌پرداخت/پرداخت کامل) عودت داده می‌شود */
    $paidCol = (int)DB::val('SELECT paid_total FROM ' . DB::t('consult_appointments') . ' WHERE id=?', [$apptId]);
    $amount  = $paidCol > 0 ? $paidCol
                            : (int)DB::val('SELECT payable FROM ' . DB::t('orders') . ' WHERE id=?', [(int)$a['order_id']]);
    if ($amount <= 0) throw new RuntimeException('این نوبت مبلغی برای عودت ندارد.');
    try {
        $id = DB::insert('refund_requests', [
            'context' => 'consult', 'reference_id' => $apptId, 'user_id' => $userId,
            'amount' => $amount, 'reason' => mb_substr(trim($reason), 0, 2000),
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_refund_once') || str_contains($e->getMessage(), 'Duplicate'))
            throw new RuntimeException('برای این نوبت قبلاً درخواست عودت وجه ثبت کرده‌اید.');
        throw $e;
    }
    log_activity('refund_request', 'refund', $id, ['appt'=>$apptId, 'amount'=>$amount]);
    // نسخهٔ ۱٫۷: پیامک تأیید دریافت به کاربر + اطلاع به مدیر/حسابداری
    require_once __DIR__ . '/notify.php';
    fr_refund_notify($userId, $amount, 'نوبت مشاوره «' . (string)$a['service_title'] . '»', $reason);
    return $id;
}

/**
 * ثبت درخواست عودت/لغو «ثبت‌نام همراه» از پنل خود همراه — نسخهٔ ۱٫۷
 * قانون: هر موبایل (حتی آن‌هایی که به‌عنوان همراه ثبت شده‌اند) پنل کاربری دارد
 * و از همان پنل می‌تواند ثبت‌نام همراهش را لغو و درخواست عودت بدهد.
 * @param int $compId  شناسهٔ ردیف event_companions
 */
function refund_request_companion(int $compId, int $userId, string $reason): int
{
    if (!DB::val('SELECT bank_card_enc FROM ' . DB::t('users') . ' WHERE id=?', [$userId])) {
        throw new RuntimeException('برای ثبت درخواست عودت، ابتدا شماره کارت بانکی‌تان را در «کیف پول» وارد کنید.');
    }
    $c = DB::one('SELECT c.*, r.status AS reg_status, r.order_id, r.discount_meta,
                         e.title, e.starts_at, e.price, e.companion_price, e.companion_price_mode
                  FROM ' . DB::t('event_companions') . ' c
                  JOIN ' . DB::t('event_registrations') . ' r ON r.id=c.reg_id
                  JOIN ' . DB::t('events') . ' e ON e.id=r.event_id
                  WHERE c.id=? AND c.user_id=?', [$compId, $userId]);
    if (!$c) throw new RuntimeException('چنین ثبت‌نام همراهی برای حساب شما یافت نشد.');
    if ((string)($c['status'] ?? 'active') !== 'active') throw new RuntimeException('این ثبت‌نام همراه قبلاً لغو شده است.');
    if ($c['reg_status'] !== 'paid') throw new RuntimeException('این ثبت‌نام پرداخت نهایی نشده و قابل عودت نیست.');
    if (!empty($c['starts_at']) && strtotime($c['starts_at']) < time()) {
        throw new RuntimeException('رویداد برگزار شده است؛ برای بررسی عودت وجه از «پشتیبانی» تیکت بزنید.');
    }
    // سهم همراه از پیش‌فاکتور — ابتدا از مِتای ثبت‌شده، در غیر این‌صورت از قیمت جاری همراه
    $meta = $c['discount_meta'] ? json_decode((string)$c['discount_meta'], true) : [];
    $amount = (int)($meta['companion_unit'] ?? 0);
    if ($amount <= 0) { require_once __DIR__ . '/event-pricing.php'; $amount = ev_companion_unit(['price'=>$c['price'],'companion_price'=>$c['companion_price'],'companion_price_mode'=>$c['companion_price_mode'] ?: 'fixed']); }
    if ($amount <= 0) throw new RuntimeException('این همراهی رایگان بوده و مبلغی برای عودت ندارد.');
    try {
        $id = DB::insert('refund_requests', [
            'context' => 'companion', 'reference_id' => $compId, 'user_id' => $userId,
            'amount' => $amount, 'reason' => mb_substr(trim($reason), 0, 2000),
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'uq_refund_once') || str_contains($e->getMessage(), 'Duplicate'))
            throw new RuntimeException('برای این ثبت‌نام همراه قبلاً درخواست عودت ثبت کرده‌اید.');
        throw $e;
    }
    log_activity('refund_request', 'refund', $id, ['companion'=>$compId, 'amount'=>$amount]);
    require_once __DIR__ . '/notify.php';
    fr_refund_notify($userId, $amount, 'همراهی در «' . (string)$c['title'] . '»', $reason);
    return $id;
}

/** وضعیت درخواست عودت کاربر برای یک مرجع (برای نمایش دکمه/برچسب در پنل) */
function refund_status_for(string $context, int $refId): ?string
{
    $v = DB::val('SELECT status FROM ' . DB::t('refund_requests') . ' WHERE context=? AND reference_id=?', [$context, $refId]);
    return $v === false ? null : (string)$v;
}

/** فارسیِ وضعیت عودت */
function refund_status_fa(string $st): string
{
    return ['pending'=>'در انتظار بررسی','approved'=>'تأیید شده — در انتظار واریز',
            'rejected'=>'رد شده','paid'=>'واریز شد'][$st] ?? $st;
}

/* ---------------------------------------------------------------------------
 | پرداخت سفارش با کیف پول — نسخهٔ ۱٫۶
 * -------------------------------------------------------------------------*/
/**
 * تلاش برای پرداخت (کل یا بخشی از) سفارش با موجودی کیف پول.
 * خروجی: ['wallet'=>مبلغ پرداخت‌شده از کیف، 'gateway'=>ماندهٔ قابل پرداخت در درگاه]
 * ─ اگر کل مبلغ پوشش داده شود، تراکنش کیف ثبت می‌شود و caller باید سفارش را paid کند.
 * ─ اگر بخشی پوشش داده شود، caller درگاه را با «مانده» فراخوانی می‌کند.
 */
function wallet_pay_part(int $orderId, int $userId, int $payable, string $desc): array
{
    if ($payable <= 0) return ['wallet'=>0,'gateway'=>0];
    $bal = wallet_balance($userId);
    if ($bal <= 0) return ['wallet'=>0,'gateway'=>$payable];

    $portion = min($bal, $payable);
    try {
        $txnId = wallet_debit($userId, $portion, $desc, 'order', $orderId);
    } catch (Throwable $e) {
        return ['wallet'=>0,'gateway'=>$payable];     // موجودی لحظه‌ای کافی نبود — عادی ادامه بده
    }
    DB::insert('payments', [
        'order_id'=>$orderId, 'gateway'=>'wallet', 'authority'=>'WALLET-' . $txnId,
        'amount'=>$portion, 'status'=>'ok', 'ref_id'=>'WALLET-' . $txnId,
        'ip'=>null, 'user_agent'=>null,
    ]);
    $rest = $payable - $portion;
    if ($rest <= 0) {
        // پرداخت کامل با کیف پول — سفارش نهایی می‌شود
        if (function_exists('mark_order_paid')) {
            mark_order_paid($orderId, 'WALLET-' . $txnId, null);
        }
    }
    log_activity('wallet_pay', 'order', $orderId, ['wallet'=>$portion, 'gateway'=>$rest]);
    return ['wallet'=>$portion, 'gateway'=>max(0,$rest)];
}

/**
 * جبران پرداخت کیف پولِ یک سفارش — اگر پس از برداشت از کیف پول اتصال به درگاه
 |  شکست بخورد یا پرداخت لغو/ناموفق شود، مبلغ به کیف کاربر برمی‌گردد تا هیچ پولی
 |  «گم» نشود. فقط پرداخت‌های walletِ تأییدشدهٔ همان سفارش برمی‌گردند و اجرای دوبارهٔ
 |  این تابع بی‌اثر است (idempotent).
 * @return int مجموع مبلغ برگردانده‌شده (تومان)
 */
function wallet_pay_reverse(int $orderId, int $userId, string $reason): int
{
    $rows = DB::all("SELECT id, amount FROM " . DB::t('payments') .
                    " WHERE order_id=? AND gateway='wallet' AND status='ok'", [$orderId]);
    $back = 0;
    foreach ($rows as $p) {
        wallet_credit($userId, (int)$p['amount'], 'refund',
                      'بازگشت مبلغ کیف پول — ' . $reason . ' (سفارش #' . $orderId . ')',
                      'order', $orderId);
        DB::run("UPDATE " . DB::t('payments') . " SET status='reversed' WHERE id=?", [(int)$p['id']]);
        $back += (int)$p['amount'];
    }
    if ($back > 0) log_activity('wallet_reverse', 'order', $orderId, ['amount'=>$back]);
    return $back;
}

/**
 * لغوی کاملِ سفارش مرده + برگشت کیف پول + آزادسازی هر مورد وابسته
 |  (ثبت‌نام رویداد/نوبت مشاوره) و برگرداندن ظرفیت کد تخفیف — کد مشترک سه مسیر.
 */
function fr_cancel_dead_order(int $orderId, string $reason): void
{
    $o = DB::one('SELECT * FROM ' . DB::t('orders') . ' WHERE id=?', [$orderId]);
    if (!$o) return;
    require_once __DIR__ . '/wallet.php';
    wallet_pay_reverse($orderId, (int)$o['user_id'], $reason);
    DB::run('UPDATE ' . DB::t('orders') . " SET status='canceled' WHERE id=? AND status IN('pending','failed')", [$orderId]);

    if ((string)($o['kind'] ?? '') === 'event') {
        // ثبت‌نام آزاد می‌شود تا صندلی برگردد
        $reg = DB::one('SELECT id FROM ' . DB::t('event_registrations') . ' WHERE order_id=?', [$orderId]);
        if ($reg) DB::run('UPDATE ' . DB::t('event_registrations') . " SET status='canceled' WHERE id=? AND status='pending'", [(int)$reg['id']]);
    } elseif ((string)($o['kind'] ?? '') === 'consult') {
        // نوبت آزاد می‌شود تا اسلاتِ آینده دوباره باز شود
        $appt = DB::one('SELECT a.id, a.slot_id, s.starts_at FROM ' . DB::t('consult_appointments') . ' a
                         JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id WHERE a.order_id=?', [$orderId]);
        if ($appt) {
            DB::run('UPDATE ' . DB::t('consult_appointments') . " SET status='canceled' WHERE id=? AND status='pending_payment'", [(int)$appt['id']]);
            if (strtotime((string)$appt['starts_at']) > time()) {
                // فقط هولدِ همین رزرو آزاد می‌شود — اسلات‌های مسدودشدهٔ دستی مدیر دست نمی‌خورند
                DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='open', held_until=NULL WHERE id=? AND status='held'", [(int)$appt['slot_id']]);
            }
        }
    }
    // ظرفیت رزروشدهٔ کد تخفیفِ این سفارش آزاد می‌شود
    if (DB::val('SELECT COUNT(*) FROM ' . DB::t('discount_usage') . ' WHERE order_id=?', [$orderId])) {
        $did = (int)DB::val('SELECT discount_id FROM ' . DB::t('discount_usage') . ' WHERE order_id=? LIMIT 1', [$orderId]);
        DB::run('DELETE FROM ' . DB::t('discount_usage') . ' WHERE order_id=?', [$orderId]);
        if ($did) DB::run('UPDATE ' . DB::t('discounts') . ' SET used_count=GREATEST(0, used_count-1) WHERE id=?', [$did]);
    }
}
