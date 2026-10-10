<?php
/** تاریخچه تست‌های روانشناسی — مشاهده گزارش، فاکتور، PDF و بارکد */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
fr_bootstrap();
$u = require_user();
fr_panel_guard('history');

$rows = DB::all("SELECT a.*, t.title AS test_title, o.id AS order_id, o.status AS order_status
                 FROM " . DB::t('attempts') . " a
                 JOIN " . DB::t('tests') . " t ON t.id=a.test_id
                 LEFT JOIN " . DB::t('orders') . " o ON o.id=a.order_id
                 WHERE a.user_id=? ORDER BY a.id DESC", [$u['id']]);

// همگام‌سازی دسترسی‌های پرداخت‌شده: اگر کاربری هزینهٔ تست را پرداخت کرده، تلاشش به حالت ویژه ارتقا یابد
foreach ($rows as &$r) {
    if ($r['mode'] === 'free' && $r['status'] !== 'in_progress') {
        $paidOrder = null;
        if (!empty($r['order_id'])) {
            $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') . " WHERE id=? AND status='paid'", [(int)$r['order_id']]);
        }
        if (!$paidOrder) {
            $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') .
                                 " WHERE user_id=? AND test_id=? AND status='paid' ORDER BY id DESC LIMIT 1",
                                 [$u['id'], $r['test_id']]);
        }
        if ($paidOrder) {
            DB::update('attempts', ['mode' => 'full', 'order_id' => $paidOrder['id']], 'id=?', [$r['id']]);
            $r['mode'] = 'full';
            $r['order_id'] = $paidOrder['id'];
            $r['order_status'] = 'paid';
        }
    }
}
unset($r);

render_header('تاریخچه تست‌های روانشناسی');
panel_layout($u, 'history');
?>
<div class="fr-card">
  <h3>تاریخچه تست‌های روانشناسی</h3>
  <?php if (!$rows): ?>
    <p class="fr-alert info" style="margin:0">هنوز تستی ثبت نشده است.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead><tr><th>تست روانشناسی</th><th>نوع</th><th>وضعیت</th><th>پیشرفت</th><th>تاریخ</th><th>اقدامات</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $totalQ = (int)DB::val('SELECT question_count FROM ' . DB::t('tests') . ' WHERE id=?', [$r['test_id']]);
      $pct = $totalQ ? min(100, round($r['progress'] / $totalQ * 100)) : 0;
    ?>
    <tr>
      <td><b><?= e($r['test_title']) ?></b></td>
      <td><span class="fr-chip <?= $r['mode']==='full'?'purple':'info' ?>"><?= $r['mode']==='full'?'ویژه':'رایگان' ?></span></td>
      <td><?= $r['status']==='in_progress' ? '<span class="fr-chip warn">درحال انجام</span>' : '<span class="fr-chip ok">تکمیل‌شده</span>' ?></td>
      <td style="min-width:120px"><div class="fr-progress"><i style="width:<?= $pct ?>%"></i></div>
          <small style="color:var(--muted)"><?= fa_num($pct) ?>٪</small></td>
      <td><?= fa_num(jdate('j F Y', strtotime($r['started_at']))) ?></td>
      <td class="actions" style="white-space:nowrap">
        <?php if ($r['status']==='in_progress'): ?>
          <a class="fr-btn sm" href="take.php?attempt=<?= (int)$r['id'] ?>">ادامه</a>
        <?php else: ?>
          <a class="fr-btn sm" href="report.php?attempt=<?= (int)$r['id'] ?>">گزارش</a>
          <a class="fr-btn sm ghost" href="dyad.php?action=new&attempt=<?= (int)$r['id'] ?>" title="سنجش تطابق دونفره">👥 تطابق</a>
          <?php if ($r['mode'] === 'free'): ?>
          <a class="fr-btn sm accent" href="test-start.php?id=<?= (int)$r['test_id'] ?>&upgrade_attempt=<?= (int)$r['id'] ?>" title="ارتقا به ویژه">⭐ نسخه ویژه</a>
          <?php endif; ?>
          <a class="fr-btn sm ghost" href="report-pdf.php?attempt=<?= (int)$r['id'] ?>" title="دریافت PDF">PDF 📄</a>
          <a class="fr-btn sm ghost" href="barcode.php?attempt=<?= (int)$r['id'] ?>" title="بارکد گزارش">▮▮▮</a>
        <?php endif; ?>
        <?php if ($r['order_id'] && $r['order_status']==='paid'): ?>
          <a class="fr-btn sm ghost" href="invoice.php?order=<?= (int)$r['order_id'] ?>">فاکتور</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php panel_layout_end(); render_footer(); ?>
