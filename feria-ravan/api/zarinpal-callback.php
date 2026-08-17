<?php
/**
 * ============================================================================
 *  کال‌بک زرین‌پال — تأیید پرداخت پس از بازگشت کاربر از درگاه
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/view.php';
require_once FR_ROOT . '/includes/commerce.php';
require_once FR_ROOT . '/core/Kavenegar.php';
require_once FR_ROOT . '/core/Zarinpal.php';
fr_bootstrap();

$authority = (string)($_GET['Authority'] ?? '');
$status    = (string)($_GET['Status'] ?? '');

$fail = function (string $msg) {
    render_header('نتیجه پرداخت');
    echo '<div style="max-width:520px;margin:60px auto" class="fr-card">';
    echo '<h3>نتیجه پرداخت</h3><div class="fr-alert err">' . e($msg) . '</div>';
    echo '<a class="fr-btn" href="' . SITE_URL . '/panel/payments.php">تراکنش‌های من</a></div>';
    render_footer(); exit;
};

if ($authority === '') { $fail('پارامتر پرداخت معتبر نیست.'); }

$pay = DB::one('SELECT p.*, o.payable, o.user_id FROM ' . DB::t('payments') . ' p
                JOIN ' . DB::t('orders') . ' o ON o.id=p.order_id WHERE p.authority=?', [$authority]);
if (!$pay) { $fail('تراکنش یافت نشد.'); }

// ورود خودکار کاربر در صورت نبود سشن (بازگشت از درگاه معمولاً سشن را نگه می‌دارد)
if (empty($_SESSION['user_id'])) { $_SESSION['user_id'] = (int)$pay['user_id']; }

if ($status !== 'OK') {
    DB::run('UPDATE ' . DB::t('payments') . " SET status='failed', gateway_msg='کاربر انصراف داد' WHERE id=?", [$pay['id']]);
    // اگر بخشی از مبلغ از کیف پول رفته بود، همین‌جا به کیف برمی‌گردد تا پول کاربر گم نشود
    require_once FR_ROOT . '/includes/wallet.php';
    fr_cancel_dead_order((int)$pay['order_id'], 'انصراف از پرداخت');
    log_activity('pay_cancel', 'order', (int)$pay['order_id']);
    $fail('پرداخت توسط شما لغو شد. مبلغ کیف پولتان بازگردانده شد و هر زمان خواستید می‌توانید دوباره اقدام کنید.');
}

$zp  = new Zarinpal();
// وریفای با مبلغِ همین تراکنش درگاهی (نه payable سفارش) — لازم برای پرداخت ترکیبی کیف‌پول+درگاه
$ver = $zp->verify($authority, (int)$pay['amount'] > 0 ? (int)$pay['amount'] : (int)$pay['payable']);
if (!$ver['ok']) {
    DB::run('UPDATE ' . DB::t('payments') . " SET status='verify_failed', gateway_msg=? WHERE id=?", [$ver['message'], $pay['id']]);
    // بخش کیف پول فوراً برمی‌گردد؛ وجه درگاه هم به‌صورت خودکار توسط زرین‌پال عودت می‌شود
    require_once FR_ROOT . '/includes/wallet.php';
    fr_cancel_dead_order((int)$pay['order_id'], 'ریکت وریفای درگاه');
    log_activity('pay_verify_failed', 'order', (int)$pay['order_id'], ['msg' => $ver['message']]);
    $fail('تأیید پرداخت ناموفق بود: ' . $ver['message'] . ' — مبلغ کیف پولتان برگشت؛ اگر مبلغی از کارتتان کم شده ظرف ۷۲ ساعت به‌صورت خودکار برمی‌گردد.');
}

/* پرداخت موفق */
mark_order_paid((int)$pay['order_id'], (string)$ver['ref_id'], $ver['card_pan'] ?? null);

// یافتن جلسهٔ تست روانشناسی یا ثبت‌نام رویداد مرتبط
$order = DB::one('SELECT kind, event_id FROM ' . DB::t('orders') . ' WHERE id=?', [(int)$pay['order_id']]);
$attempt = DB::one('SELECT id FROM ' . DB::t('attempts') . ' WHERE order_id=? ORDER BY id DESC', [(int)$pay['order_id']]);
render_header('پرداخت موفق');
echo '<div style="max-width:520px;margin:60px auto" class="fr-card" style="text-align:center">';
echo '<div style="font-size:46px">🎉</div><h3>پرداخت شما با موفقیت انجام شد</h3>';
echo '<p style="color:var(--muted)">کد رهگیری: <b style="direction:ltr;display:inline-block">' . e((string)$ver['ref_id']) . '</b></p>';
if ($attempt) {
    echo '<a class="fr-btn" href="' . SITE_URL . '/panel/take.php?attempt=' . (int)$attempt['id'] . '">شروع تست روانشناسی ←</a> ';
} elseif (($order['kind'] ?? '') === 'event') {
    /* نسخهٔ ۲٫۲: بازگشت به برگهٔ تفکیک‌شدهٔ درست (کارگاه/دوره) */
    $evk = (string)(DB::val('SELECT kind FROM ' . DB::t('events') . ' WHERE id=?', [(int)($order['event_id'] ?? 0)]) ?: 'workshop');
    echo '<a class="fr-btn" href="' . SITE_URL . ($evk === 'course' ? '/panel/courses.php' : '/panel/workshops.php') . '">رویدادهای من ←</a> ';
} elseif (($order['kind'] ?? '') === 'consult') {
    echo '<p style="color:var(--muted);font-size:13px">نوبت مشاورهٔ شما قطعی شد و پیامک تأیید ارسال گردید.</p>';
    echo '<a class="fr-btn" href="' . SITE_URL . '/panel/consult.php">نوبت‌های مشاورهٔ من ←</a> ';
}
echo '<a class="fr-btn ghost" href="' . SITE_URL . '/panel/payments.php">فاکتور</a></div>';
render_footer();
