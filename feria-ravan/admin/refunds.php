<?php
/**
 * =============================================================================
 *  صف عودت وجه (مدیریت) — نسخهٔ ۱٫۶
 *  ─ بررسی درخواست‌ها: تأیید / رد
 *  ─ پرداخت: واریز دستی (ثبت کد پیگیری) یا واریز به کیف پول کاربر
 *  ─ پس از پرداخت، ثبت‌نام/نوبت لغو و ظرفیت/اسلات آزاد می‌شود (سازگار با سورس مادر)
 * =============================================================================
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/wallet.php';
$admin = require_admin();

/* ---------------- عملیات ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $id   = (int)($_POST['id'] ?? 0);
    $act  = (string)($_POST['act'] ?? '');
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000);
    $ref  = mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 80);

    $r = DB::one('SELECT * FROM ' . DB::t('refund_requests') . ' WHERE id=?', [$id]);
    if (!$r) flash('درخواست یافت نشد.', 'err');
    elseif ($r['status'] === 'paid') flash('این درخواست قبلاً تسویه شده است.', 'err');
    elseif ($act === 'approve') {
        DB::update('refund_requests', ['status'=>'approved','admin_note'=>$note], 'id=?', [$id]);
        log_activity('refund_approve', 'refund', $id);
        flash('درخواست تأیید شد؛ پس از واریز، وضعیت را «پرداخت‌شده» کنید.');
    } elseif ($act === 'reject') {
        DB::update('refund_requests', ['status'=>'rejected','admin_note'=>$note], 'id=?', [$id]);
        log_activity('refund_reject', 'refund', $id);
        flash('درخواست رد شد.');
    } elseif ($act === 'paid_manual' || $act === 'paid_wallet') {
        /* نهایی‌سازی: لغو ثبت‌نام/نوبت + ثبت وضعیت پرداخت */
        try {
            DB::pdo()->beginTransaction();
            if ($act === 'paid_wallet') {
                wallet_credit((int)$r['user_id'], (int)$r['amount'], 'refund',
                    'عودت وجه ' . ($r['context'] === 'event' ? 'ثبت‌نام رویداد' : 'نوبت مشاوره'),
                    'refund', $id);
                $ref = $ref !== '' ? $ref : 'WALLET';
            }
            DB::update('refund_requests',
                ['status'=>'paid','refund_ref'=>$ref,'admin_note'=>$note], 'id=?', [$id]);

            /* نسخهٔ ۱٫۹ (اصلاح درآمد): سفارش متناظر نهایتاً «refunded» شود تا در
               گزارش‌های درآمدی (داشبورد/سفارش‌ها/خالص درآمد) مبلغ برگشت‌شده جدا و
               شفاف محاسبه شود — خود سفارش حذف نمی‌شود، فقط وضعیتش تغییر می‌کند */
            try {
                if ($r['context'] === 'event') {
                    DB::run('UPDATE ' . DB::t('orders') . ' o JOIN ' . DB::t('event_registrations') . ' er ON er.order_id=o.id
                             SET o.status=\'refunded\' WHERE er.id=? AND o.status=\'paid\'', [(int)$r['reference_id']]);
                } else {
                    DB::run('UPDATE ' . DB::t('orders') . ' o JOIN ' . DB::t('consult_appointments') . ' a ON a.order_id=o.id
                             SET o.status=\'refunded\' WHERE a.id=? AND o.status=\'paid\'', [(int)$r['reference_id']]);
                }
            } catch (Throwable $e) { /* برای refund_requests qديمیه بدون ردیف سفارش */ }

            if ($r['context'] === 'event') {
                DB::run('UPDATE ' . DB::t('event_registrations') . " SET status='canceled' WHERE id=? AND status='paid'",
                        [(int)$r['reference_id']]);
            } else {
                DB::run('UPDATE ' . DB::t('consult_appointments') . " SET status='canceled' WHERE id=? AND status='booked'",
                        [(int)$r['reference_id']]);
                DB::run('UPDATE ' . DB::t('consult_slots') . " s
                          JOIN " . DB::t('consult_appointments') . " a ON a.slot_id=s.id
                          SET s.status='open', s.held_until=NULL
                          WHERE a.id=? AND s.starts_at>NOW()", [(int)$r['reference_id']]);
            }
            DB::pdo()->commit();
            log_activity('refund_paid', 'refund', $id, ['way'=>$act === 'paid_wallet' ? 'wallet' : 'manual']);
            flash($act === 'paid_wallet' ? 'مبلغ به کیف پول کاربر واریز شد ✅' : 'پرداخت دستی ثبت شد ✅');
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
            flash('خطا در ثبت پرداخت: ' . $e->getMessage(), 'err');
        }
    } else {
        flash('عملیات نامعتبر است.', 'err');
    }
    redirect(SITE_URL . '/admin/refunds.php');
}

/* ---------------- داده‌ها ---------------- */
$rows = DB::all('SELECT r.*, u.first_name, u.last_name, u.mobile, u.bank_card_enc, u.bank_card_last4
                 FROM ' . DB::t('refund_requests') . ' r
                 JOIN ' . DB::t('users') . ' u ON u.id=r.user_id
                 ORDER BY r.status=\'pending\' DESC, r.id DESC');
$pendN = count(array_filter($rows, fn($x) => $x['status'] === 'pending'));

$ctxTitle = function(array $r): string {
    if ($r['context'] === 'event') {
        $t = DB::val('SELECT e.title FROM ' . DB::t('event_registrations') . ' g
                      JOIN ' . DB::t('events') . ' e ON e.id=g.event_id WHERE g.id=?',
                     [(int)$r['reference_id']]);
        return '🛠 ' . ($t ?: ('ثبت‌نام #' . $r['reference_id']));
    }
    $t = DB::val('SELECT sv.title FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id WHERE a.id=?',
                 [(int)$r['reference_id']]);
    return '🛋 ' . ($t ?: ('نوبت #' . $r['reference_id']));
};
$stMap = ['pending'=>['warn','در انتظار'],'approved'=>['info','تأییدشده'],'rejected'=>['err','رد شده'],'paid'=>['ok','واریز شد']];

admin_layout($admin, 'refunds', 'عودت وجه و تسویه');
?>
<?= flash() ?>
<div class="fr-card">
  <h2>↩️ صف عودت وجه
    <span class="fr-chip warn" style="margin-right:auto"><?= fa_num($pendN) ?> در انتظار بررسی</span></h2>
  <p style="font-size:12.5px;color:var(--muted);margin:0">
    مسیر کار: کاربر درخواست می‌دهد → شما بررسی می‌کنید → یا وجه را <b>دستی واریز</b> می‌کنید و کد پیگیری را ثبت می‌کنید،
    یا مبلغ را <b>به کیف پول کاربر</b> برمی‌گردانید تا در خرید بعدی استفاده کند.
    در هر دو صورت، ثبت‌نام/نوبت لغو و ظرفیت آزاد می‌شود.
  </p>
</div>

<div class="fr-card">
  <?php if (!$rows): ?><p class="fr-alert info" style="margin:0">درخواستی ثبت نشده است.</p><?php endif; ?>
  <?php if ($rows): ?>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead><tr><th>کاربر</th><th>مرجع</th><th>مبلغ / کارت</th><th>دلیل</th><th>وضعیت</th><th>تصمیم و ثبت</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$cls,$lbl] = $stMap[$r['status']] ?? ['info',$r['status']];
      $card = fr_bank_card_full($r); ?>
    <tr>
      <td><b><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></b><br>
          <span dir="ltr" style="font-size:11.5px;color:var(--muted)"><?= e($r['mobile']) ?></span></td>
      <td style="font-size:12.5px"><?= e($ctxTitle($r)) ?><br>
          <small style="color:var(--muted)"><?= fa_num(jdate('j F Y', strtotime($r['created_at']))) ?></small></td>
      <td><b style="color:#7c3aed"><?= toman((int)$r['amount']) ?></b><br>
          <small dir="ltr" style="font-size:11px;color:var(--muted)"><?= $card !== '' ? e($card) : 'کارت ثبت نشده' ?></small></td>
      <td style="font-size:11.5px;max-width:180px"><?= e($r['reason'] ?: '—') ?>
          <?php if ($r['refund_ref']): ?><br><small style="color:#0c7a4d">پیگیری: <bdi dir="ltr"><?= e($r['refund_ref']) ?></bdi></small><?php endif; ?>
          <?php if ($r['admin_note']): ?><br><small style="color:var(--muted)">یادداشت: <?= e($r['admin_note']) ?></small><?php endif; ?></td>
      <td><span class="fr-chip <?= $cls ?>" style="font-size:11px"><?= $lbl ?></span></td>
      <td style="min-width:330px">
        <?php if ($r['status'] !== 'paid'): ?>
        <form method="post" style="display:flex;flex-wrap:wrap;gap:6px">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input class="fr-input" name="note" placeholder="یادداشت برای کاربر" style="font-size:11.5px;flex:1;min-width:120px">
          <input class="fr-input" name="ref" placeholder="کد پیگیری واریز" style="font-size:11.5px;max-width:110px;direction:ltr" dir="ltr">
          <?php if ($r['status'] === 'pending'): ?>
          <button class="fr-btn sm" name="act" value="approve">تأیید</button>
          <button class="fr-btn sm danger" name="act" value="reject" onclick="return confirm('رد شود؟')">رد</button>
          <?php endif; ?>
          <button class="fr-btn sm accent" name="act" value="paid_manual" onclick="return confirm('پرداخت دستی ثبت و ثبت‌نام/نوبت لغو شود؟')">واریز دستی شد</button>
          <button class="fr-btn sm" name="act" value="paid_wallet" title="مبلغ به کیف پول کاربر برمی‌گردد و ثبت‌نام/نوبت لغو می‌شود"
                  onclick="return confirm('مبلغ به کیف پول کاربر واریز و ثبت‌نام/نوبت لغو شود؟')">↩️ به کیف پول</button>
        </form>
        <?php else: ?><small style="color:var(--muted)">تسویه شد ✅</small><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php admin_layout_end(); ?>
