<?php
/**
 * =============================================================================
 *  تاریخچه تراکنش‌ها و فاکتورها (پنل کاربر) — نسخهٔ ۱٫۴
 *  ─ پوشش هر سه نوع سفارش: تست روانشناسی / رویداد (کارگاه-دوره) / رزرو مشاوره
 *  ─ رفع نقص نسخه‌های قبل: JOIN داخلی tests باعث نامرئی‌شدن سفارش‌های رویداد بود
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
fr_bootstrap();
$u = require_user();
fr_panel_guard('payments');

$rows = DB::all("SELECT o.*, t.title AS test_title, ev.title AS event_title,
                 (SELECT sv.title FROM " . DB::t('consult_appointments') . " a
                   JOIN " . DB::t('consult_services') . " sv ON sv.id=a.service_id
                   WHERE a.order_id=o.id LIMIT 1) AS consult_title,
                 (SELECT p.ref_id FROM " . DB::t('payments') . " p WHERE p.order_id=o.id AND p.status='ok' ORDER BY p.id DESC LIMIT 1) AS ref_id
                 FROM " . DB::t('orders') . " o
                 LEFT JOIN " . DB::t('tests') . " t ON t.id=o.test_id
                 LEFT JOIN " . DB::t('events') . " ev ON ev.id=o.event_id
                 WHERE o.user_id=? ORDER BY o.id DESC", [$u['id']]);
$map = ['pending'=>['warn','در انتظار پرداخت'],'paid'=>['ok','موفق'],'failed'=>['err','ناموفق'],'refunded'=>['info','مستردشده'],'canceled'=>['err','لغوشده']];
$kindFa = ['test_full'=>'تست روانشناسی','event'=>'رویداد','consult'=>'مشاوره'];

/** عنوان محصول بر اساس نوع سفارش */
$orderTitle = fn(array $r) => $r['kind'] === 'event'
    ? ('🛠 ' . ($r['event_title'] ?? 'رویداد'))
    : ($r['kind'] === 'consult' ? ('🛋 رزرو ' . ($r['consult_title'] ?? 'مشاوره')) : ('🧠 ' . ($r['test_title'] ?? 'تست روانشناسی')));

render_header('تراکنش‌ها');
panel_layout($u, 'payments');
?>
<div class="fr-card">
  <h3>تراکنش‌های مالی</h3>
  <?php if (!$rows): ?><p class="fr-alert info" style="margin:0">تراکنشی ثبت نشده است.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead><tr><th>تست روانشناسی/خدمت</th><th>نوع</th><th>مبلغ</th><th>تخفیف</th><th>پرداختی</th><th>وضعیت</th><th>کد رهگیری</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$cls,$lbl] = $map[$r['status']] ?? ['info',$r['status']]; ?>
    <tr>
      <td><b><?= e($orderTitle($r)) ?></b>
        <?php /* ۳٫۰: برچسب مرحلهٔ پرداخت (بیعانه/تصفیه) */ $stg = (string)($r['pay_stage'] ?? 'full');
        if ($stg === 'deposit'): ?><br><span class="fr-chip warn" style="font-size:10px">💳 پیش‌پرداخت</span>
        <?php elseif ($stg === 'settle'): ?><br><span class="fr-chip info" style="font-size:10px">✅ تصفیهٔ حساب</span><?php endif; ?></td>
      <td style="font-size:11.5px;color:var(--muted)"><?= $kindFa[$r['kind']] ?? $r['kind'] ?></td>
      <td><?= fa_num(number_format($r['amount'])) ?></td>
      <td><?= $r['discount_amt'] ? fa_num(number_format($r['discount_amt'])) . ' (' . e($r['discount_code']) . ')' : '—' ?></td>
      <td><?= fa_num(number_format($r['payable'])) ?> تومان</td>
      <td><span class="fr-chip <?= $cls ?>"><?= $lbl ?></span></td>
      <td style="direction:ltr"><?= e($r['ref_id'] ?: '—') ?></td>
      <td><?= fa_num(jdate('j F Y - H:i', strtotime($r['created_at']))) ?></td>
      <td><?php if ($r['status']==='paid'): ?>
            <a class="fr-btn sm ghost" href="invoice.php?order=<?= (int)$r['id'] ?>">فاکتور</a>
          <?php elseif ($r['status']==='pending'): ?>
            <?php if ($r['kind']==='event' && $r['event_id']): ?>
            <a class="fr-btn sm" href="event-register.php?id=<?= (int)$r['event_id'] ?>">پرداخت</a>
            <?php elseif ($r['kind']==='consult'): ?>
            <a class="fr-btn sm" href="consult.php">پیگیری نوبت</a>
            <?php else: ?>
            <a class="fr-btn sm" href="test-start.php?id=<?= (int)$r['test_id'] ?>&resume=1">پرداخت</a>
            <?php endif; ?>
          <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php panel_layout_end(); render_footer(); ?>
