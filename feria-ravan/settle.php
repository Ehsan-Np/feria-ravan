<?php
/**
 * ============================================================================
 *  صفحهٔ تصفیهٔ حساب (لینک اختصاصی پیامک) — نسخهٔ ۳٫۰
 *  ─ مقصد لینکی که در یادآور خودکار «نهایتاً ۳ روز مانده به برگزاری» پیامک
 *    می‌شود؛ با توکن HMAC کار می‌کند و حتی بدون ورود به حساب هم پرداخت ممکن است؛
 *  ─ اگر خود کاربر وارد شده باشد، می‌تواند از موجودی کیف پولش هم استفاده کند؛
 *  ─ پس از پرداخت موفق، کال‌بک درگاه سفارش تصفیه را نهایی و ثبت‌نام/نوبت را
 *    به‌صورت کامل «تأییدشده» علامت می‌زند.
 * ============================================================================
 */
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install/'); exit; }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/public-layout.php';
require_once __DIR__ . '/includes/commerce.php';
require_once __DIR__ . '/includes/deposit.php';
require_once __DIR__ . '/includes/wallet.php';
require_once __DIR__ . '/core/Kavenegar.php';
require_once __DIR__ . '/core/Zarinpal.php';
fr_bootstrap();

$type = (($_GET['t'] ?? ($_POST['t'] ?? '')) === 'appt') ? 'appt' : 'reg';
$id   = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$tok  = (string)($_GET['tok'] ?? ($_POST['tok'] ?? ''));
$me   = current_user();

$failPage = function (string $title, string $msg, string $icon = '🔐'): never {
    public_header(['active' => '', 'noindex' => true], $title);
    echo '<div class="pb-wrap"><div class="fr-card" style="max-width:560px;margin:60px auto;text-align:center">';
    echo '<div style="font-size:44px">' . $icon . '</div><h3>' . e($title) . '</h3>';
    echo '<p style="color:var(--muted);font-size:13.5px;line-height:2">' . e($msg) . '</p>';
    echo '<a class="fr-btn ghost" href="' . SITE_URL . '/">بازگشت به خانه</a></div></div>';
    public_footer(); exit;
};

/* ── واکشی رکورد بر اساس نوع ── */
$rec = null;
if ($type === 'reg') {
    $rec = DB::one('SELECT r.*, e.title AS item_title, e.kind, e.starts_at
                    FROM ' . DB::t('event_registrations') . ' r
                    JOIN ' . DB::t('events') . ' e ON e.id=r.event_id WHERE r.id=?', [$id]);
} else {
    $rec = DB::one('SELECT a.*, sv.title AS item_title, s.starts_at
                    FROM ' . DB::t('consult_appointments') . ' a
                    JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id
                    JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id WHERE a.id=?', [$id]);
}
if (!$rec || !fr_settle_token_ok($type, $id, (int)$rec['user_id'], $tok)) {
    $failPage('لینک نامعتبر است', 'لینک تصفیهٔ حساب معتبر نیست یا منقضی شده است. لطفاً از پیامک جدید یا از پنل کاربری‌تان اقدام کنید.');
}

$owner = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [(int)$rec['user_id']]);
$total  = $type === 'reg' ? fr_reg_total($rec) : fr_appt_total($rec);
$paid   = (int)$rec['paid_total'];
$remain = max(0, $total - $paid);
$isSelf = $me && (int)$me['id'] === (int)$rec['user_id'];
$panelUrl = SITE_URL . ($type === 'reg'
    ? (($rec['kind'] ?? '') === 'course' ? '/panel/courses.php' : '/panel/workshops.php')
    : '/panel/consult.php');
$thingFa = $type === 'reg' ? (($rec['kind'] ?? '') === 'course' ? 'دوره' : 'کارگاه') : 'جلسهٔ مشاوره';

$canSettle = $remain > 0 && ($type === 'reg'
    ? $rec['status'] === 'deposit'
    : ($rec['status'] === 'booked' && $paid > 0));
$done = $remain <= 0 || ($type === 'reg' ? $rec['status'] === 'paid' : false);

$err = '';

/* ── پردازش ارسال (ایجاد سفارش تصفیه → درگاه) ── */
if ($canSettle && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fr_settle_token_ok($type, (int)($_POST['id'] ?? 0), (int)$rec['user_id'], (string)($_POST['tok'] ?? ''))
        || (int)($_POST['id'] ?? 0) !== $id) {
        $failPage('لینک نامعتبر است', 'توکن امنیتی لینک معتبر نیست.');
    }
    if (!csrf_check()) {
        $err = 'نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید و دوباره تلاش نمایید.';
    } else {
        $useWallet = $isSelf && !empty($_POST['use_wallet']);
        $r = $type === 'reg'
            ? fr_settle_create_order_reg($rec, $owner, $useWallet)
            : fr_settle_create_order_appt($rec, $owner, $useWallet);
        if (!empty($r['error'])) {
            $err = (string)$r['error'];
        } elseif (!empty($r['payment_url'])) {
            redirect($r['payment_url']);
        } else {
            /* تسویهٔ کامل با کیف پول — بدون درگاه */
            $failPage('تصفیهٔ حساب انجام شد 🎉',
                'ماندهٔ حساب شما با موجودی کیف پول تسویه شد و ' . $thingFa . ' شما به‌صورت کامل نهایی است.',
                '🎉');
        }
    }
}

/* ── نمای ── */
public_header(['active' => '', 'noindex' => true,
               'desc' => 'تصفیهٔ حساب «' . $rec['item_title'] . '» — پرداخت امن مابقی مبلغ ثبت‌نام/نوبت.'],
              'تصفیهٔ حساب: ' . $rec['item_title']);
?>
<div class="pb-wrap" style="max-width:640px;margin:0 auto;padding-top:24px">

  <?php if ($err): ?><div class="fr-alert err" style="margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>

  <div class="fr-card">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;border-bottom:1.5px dashed #d4dcec;padding-bottom:14px;margin-bottom:14px">
      <span style="font-size:34px"><?= $type === 'reg' ? '🎪' : '🛋' ?></span>
      <div style="flex:1;min-width:200px">
        <h3 style="margin:0"><?= e($rec['item_title']) ?></h3>
        <small style="color:var(--muted)">
          <?= e($thingFa) ?>
          <?php if ($rec['starts_at']): ?> · 🗓 <?= fa_num(jdate('l j F Y ساعت H:i', strtotime($rec['starts_at']))) ?><?php endif; ?>
        </small>
      </div>
      <span class="fr-chip <?= $done ? 'ok' : 'warn' ?>"><?= $done ? 'تسویه‌شده ✅' : '💳 پیش‌پرداخت ثبت شده' ?></span>
    </div>

    <!-- صورتحساب -->
    <div style="font-size:14px;line-height:2.4;color:#2c3a5c">
      <div style="display:flex;justify-content:space-between"><span>مبلغ کل <?= $thingFa ?></span><b><?= toman($total) ?></b></div>
      <div style="display:flex;justify-content:space-between;color:#0c7a4d"><span>پرداخت‌شده تا این لحظه (پیش‌پرداخت)</span><b><?= toman($paid) ?></b></div>
      <div style="display:flex;justify-content:space-between;border-top:1.5px dashed #d4dcec;margin-top:6px;padding-top:8px">
        <b>ماندهٔ قابل تصفیه</b><b style="color:<?= $done ? '#0c7a4d' : '#b3261e' ?>;font-size:18px"><?= toman($remain) ?></b>
      </div>
    </div>

    <?php if ($done): ?>
    <div class="fr-alert ok" style="margin-top:16px">
      🎉 حساب شما به‌طور کامل تسویه شده و <?= $thingFa ?>‌تان نهایی است؛ منتظر شما هستیم.
    </div>
    <div style="text-align:center;margin-top:10px"><a class="fr-btn" href="<?= e($panelUrl) ?>">رفتن به پنل کاربری ←</a></div>

    <?php elseif ($canSettle): ?>
    <div class="fr-alert info" style="margin-top:16px;font-size:12.5px;line-height:2">
      با پرداخت بیعانه، جای شما قطعی شده است؛ برای حفظ آن، لطفاً <b>حداکثر تا ۳ روز مانده به برگزاری</b> نسبت به تصفیهٔ حساب اقدام کنید.
      همچنین هر وقت خواستید می‌توانید وارد <a href="<?= e($panelUrl) ?>" style="color:var(--c-primary);font-weight:700">پنل کاربری</a> شوید و از همان‌جا پرداخت را تکمیل نمایید.
    </div>

    <form method="post" style="margin-top:16px">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($type) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="tok" value="<?= e($tok) ?>">
      <?php $wBal = $isSelf ? wallet_balance((int)$me['id']) : 0; if ($wBal > 0): ?>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;background:#f7f3ff;border:1.5px dashed #d4dcec;border-radius:12px;padding:10px 12px;margin-bottom:12px">
        <input type="checkbox" name="use_wallet" value="1" style="width:18px;height:18px">
        <span>👛 پرداخت با کیف پول — موجودی شما: <b style="color:var(--c-primary)"><?= toman(min($wBal, $remain)) ?></b>
          <small style="display:block;color:var(--muted);font-size:11.5px">ابتدا از کیف پول کم می‌شود و اگر ماند، باقی از درگاه پرداخت می‌شود.</small>
        </span>
      </label>
      <?php endif; ?>
      <button class="fr-btn" style="width:100%;justify-content:center;font-size:15px">
        💳 پرداخت مانده — <?= toman($remain) ?>
      </button>
      <small style="display:block;text-align:center;color:var(--muted);margin-top:10px">
        پرداخت امن از طریق درگاه زرین‌پال 🔒 — پس از پرداخت، رسید و تأیید نهایی پیامک می‌شود.
      </small>
    </form>

    <?php else: ?>
    <div class="fr-alert err" style="margin-top:16px">
      این لینک برای ردیفی صادر شده که ماندهٔ قابل پرداخت ندارد یا وضعیتش تغییر کرده است؛
      اگر نیاز به راهنمایی دارید با پشتیبانی در تماس باشید یا از <a href="<?= e($panelUrl) ?>" style="color:var(--c-primary)">پنل کاربری</a> اقدام کنید.
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$isSelf && !$done): ?>
  <p style="text-align:center;font-size:12px;color:var(--muted);margin-top:14px">
    حساب شما نیست؟ <a class="lnk" href="<?= e(SITE_URL . '/auth.php') ?>">ورود به حساب کاربری ←</a>
  </p>
  <?php endif; ?>
</div>
<?php public_footer(); ?>
