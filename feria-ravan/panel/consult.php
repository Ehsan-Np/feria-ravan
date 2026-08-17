<?php
/**
 * =============================================================================
 *  نوبت‌های مشاورهٔ من (پنل کاربر) — نسخهٔ ۱٫۴
 *  ─ پیش‌رو: کارت با تاریخ/ساعت/شیوه + لغو آنلاین (رعایت سیاست ساعتی)
 *  ─ گذشته: انجام‌شد/عدم حضور/لغوشده + دکمهٔ رزرو مجدد
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/consult.php';
require_once __DIR__ . '/../includes/deposit.php';
require_once __DIR__ . '/../includes/wallet.php';
fr_bootstrap();

$u = require_user();
consult_release_expired_holds();

/* ── ثبت درخواست عودت وجه نوبت (قبل از شروع جلسه) — نسخهٔ ۱٫۶ ── */
$okMsg = ''; $errMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['act'] ?? '') === 'refund') {
    try {
        refund_request_consult((int)($_POST['appt'] ?? 0), (int)$u['id'], (string)($_POST['reason'] ?? ''));
        $okMsg = 'درخواست عودت وجه ثبت شد؛ نتیجهٔ بررسی از «کیف پول و عودت وجه» و پیامک اطلاع‌رسانی می‌شود. 💌';
    } catch (Throwable $e) { $errMsg = $e->getMessage(); }
}

$rows = DB::all('SELECT a.*, s.starts_at, s.ends_at, sv.title AS service_title, sv.slug AS service_slug,
                        c.name AS cname, c.title AS ctitle, o.payable, o.status AS order_status, o.id AS order_id,
                        o.pay_stage
                 FROM ' . DB::t('consult_appointments') . ' a
                 JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id
                 JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id
                 JOIN ' . DB::t('consultants') . ' c ON c.id=a.consultant_id
                 LEFT JOIN ' . DB::t('orders') . ' o ON o.id=a.order_id
                 WHERE a.user_id=? AND a.status<>\'pending_payment\'
                 ORDER BY s.starts_at DESC', [$u['id']]);

$upcoming = array_values(array_filter($rows, fn($r) => $r['status'] === 'booked' && strtotime($r['starts_at']) > time()));
$past     = array_values(array_filter($rows, fn($r) => !($r['status'] === 'booked' && strtotime($r['starts_at']) > time())));
$cancelH  = consult_cancel_hours();

/* رندر یک کارت نوبت */
$stFa = ['booked'=>['قطعی ✅','ok'],'done'=>['انجام‌شد','purple'],'canceled'=>['لغوشده','err'],'noshow'=>['عدم حضور','err']];
$card = function(array $r, bool $canCancel) use ($stFa, $u) {
    $ts   = strtotime($r['starts_at']);
    $cls  = $r['status'];
    $canRefund = $canCancel && (int)($r['payable'] ?? 0) > 0 && ($r['order_status'] ?? '') === 'paid';
    /* ۳٫۰: نوبت پیش‌پرداخت‌شده با ماندهٔ باز → نشان + دکمهٔ تصفیهٔ حساب */
    $depRemain = ($cls === 'booked' && ($r['pay_stage'] ?? '') === 'deposit')
               ? fr_appt_remaining($r) : 0;
    ?>
<div class="ap-card">
  <div class="dt">
    <b><?= fa_num(jdate('j', $ts)) ?></b>
    <span><?= e(jdate('F', $ts)) ?></span><br>
    <small style="font-weight:700"><?= fa_num(date('H:i', $ts)) ?></small>
  </div>
  <div class="inf">
    <b><?= e($r['service_title']) ?></b> <span class="fr-chip info" style="font-size:10.5px"><?= $r['mode']==='online' ? '💻 آنلاین' : '🏢 حضوری' ?></span>
    <?php if ($depRemain > 0): ?><span class="fr-chip warn" style="font-size:10.5px">💳 پیش‌پرداخت شده</span><?php endif; ?>
    <small>
      👤 <?= e($r['cname']) ?><?= $r['ctitle'] ? ' — ' . e($r['ctitle']) : '' ?><br>
      🗓 <?= fa_num(jdate('l j F Y ساعت H:i', $ts)) ?>
      <?= $r['payable'] !== null ? ' · 💳 ' . toman((int)$r['payable']) . ($r['order_status']==='paid' ? ' (پرداخت‌شده)' : '') : ' · رایگان' ?>
      <?php if ($depRemain > 0): ?><br><span style="color:#8a5a2e">پرداخت‌شده: <?= toman((int)$r['paid_total']) ?> · مانده: <b><?= toman($depRemain) ?></b></span><?php endif; ?>
    </small>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <span class="fr-chip <?= $stFa[$cls][1] ?>"><?= $stFa[$cls][0] ?></span>
    <?php if ($depRemain > 0): ?>
    <a class="fr-btn sm" href="<?= e(fr_settle_url_appt($r)) ?>" title="تسویهٔ ماندهٔ حساب — لینک اختصاصی شما">💳 تصفیهٔ حساب</a>
    <?php endif; ?>
    <?php if ($canCancel): ?>
    <button class="fr-btn sm danger" type="button" data-ap-cancel="<?= (int)$r['id'] ?>" data-api="<?= SITE_URL ?>/api/consult.php">لغو نوبت</button>
    <?php endif; ?>
    <?php
    // عودت وجه: نوبت آیندهٔ پرداخت‌شده با مبلغ مثبت (نسخهٔ ۱٫۶)
    if ($canRefund):
        $rst = refund_status_for('consult', (int)$r['id']);
        if ($rst !== null): ?>
            <span class="fr-chip <?= ['pending'=>'warn','approved'=>'info','rejected'=>'err','paid'=>'ok'][$rst] ?? 'info' ?>" style="font-size:10.5px">
                عودت: <?= refund_status_fa($rst) ?></span>
        <?php else: ?>
            <button class="fr-btn sm ghost" type="button"
                onclick="document.getElementById('rf-<?= (int)$r['id'] ?>').style.display='flex';this.style.display='none'">↩️ عودت وجه</button>
    <?php endif; endif; ?>
  </div>
  <?php if ($canRefund && refund_status_for('consult', (int)$r['id']) === null): ?>
  <form method="post" id="rf-<?= (int)$r['id'] ?>" style="display:none;gap:6px;margin-top:8px;width:100%"
        onsubmit="return confirm('درخواست عودت وجه این نوبت ثبت شود؟')">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="refund">
      <input type="hidden" name="appt" value="<?= (int)$r['id'] ?>">
      <input class="fr-input" name="reason" placeholder="دلیل درخواست (اختیاری)" maxlength="300" style="font-size:12px;flex:1">
      <button class="fr-btn sm accent">ثبت درخواست</button>
  </form>
  <?php endif; ?>
</div>
    <?php
};

render_header('نوبت‌های مشاورهٔ من', ['css' => ['site.css']]);
panel_layout($u, 'consult');
?>
<?php if ($okMsg): ?><div class="fr-alert ok" style="margin-bottom:14px"><?= e($okMsg) ?></div><?php endif; ?>
<?php if ($errMsg): ?><div class="fr-alert err" style="margin-bottom:14px"><?= e($errMsg) ?></div><?php endif; ?>
<div class="fr-card">
  <h3>🛋 نوبت‌های مشاورهٔ من
    <a class="more" href="<?= SITE_URL ?>/consult.php">رزرو نوبت جدید ←</a></h3>
  <p style="font-size:13px;color:var(--muted);margin:0 0 6px">
    تا <?= fa_num($cancelH) ?> ساعت قبل از شروع جلسه، لغو آنلاین ممکن است؛ پس از آن با پشتیبانی در تماس باشید.
  </p>
</div>

<h3 style="font-size:15px;margin:4px 0 12px">⏳ نوبت‌های پیش‌رو</h3>
<?php if (!$upcoming): ?>
<div class="fr-card" style="text-align:center;color:var(--muted);padding:34px">
  <div style="font-size:38px;margin-bottom:8px">🛋</div>
  نوبت پیش‌رویی ندارید.
  <div style="margin-top:14px"><a class="fr-btn sm" href="<?= SITE_URL ?>/consult.php">رزرو نوبت مشاوره ←</a></div>
</div>
<?php endif; ?>
<?php foreach ($upcoming as $r): $card($r, strtotime($r['starts_at']) - time() >= $cancelH * 3600); endforeach; ?>

<?php if ($past): ?>
<h3 style="font-size:15px;margin:22px 0 12px">📜 تاریخچه</h3>
<?php foreach ($past as $r): $card($r, false); endforeach; ?>
<?php endif; ?>

<?php panel_layout_end(); ?>
<script src="<?= SITE_URL ?>/assets/js/site.js?v=<?= FR_VERSION ?>"></script>
<?php render_footer(); ?>
