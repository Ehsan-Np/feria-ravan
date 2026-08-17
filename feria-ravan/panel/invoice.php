<?php
/** فاکتور سفارش — قابلیت چاپ */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
fr_bootstrap();

$u = require_user();
// عنوان هر نوع سفارش: تست روانشناسی/رویداد/مشاوره — LEFT JOIN تا سفارش‌های غیرتستی هم دیده شوند (رفع نقص ۱٫۴)
$o = DB::one('SELECT o.*, COALESCE(t.title, ev.title,
                (SELECT CONCAT("رزرو ", sv.title) FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id
                  WHERE a.order_id=o.id LIMIT 1), "خدمت") AS test_title
              FROM ' . DB::t('orders') . ' o
              LEFT JOIN ' . DB::t('tests') . ' t ON t.id=o.test_id
              LEFT JOIN ' . DB::t('events') . ' ev ON ev.id=o.event_id
              WHERE o.id=? AND o.user_id=?', [(int)($_GET['order'] ?? 0), $u['id']]);
if (!$o) redirect(SITE_URL . '/panel/payments.php');
$pay = DB::one("SELECT ref_id FROM " . DB::t('payments') . " WHERE order_id=? AND status='ok' ORDER BY id DESC", [$o['id']]);
$site = (string)DB::setting('site_title', 'فریا روان');

render_header('فاکتور #' . $o['id']);
?>
<div style="max-width:640px;margin:40px auto">
  <div style="text-align:left;margin-bottom:14px"><button class="fr-btn sm" onclick="window.print()">🖨 چاپ فاکتور</button></div>
  <div class="fr-card">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <div><b style="font-size:18px;color:var(--c-primary)"><?= e($site) ?></b><br>
        <small style="color:var(--muted)">فاکتور رسمی خرید خدمات</small></div>
      <div style="text-align:left;font-size:13px;color:var(--muted)">
        شماره: <b><?= fa_num($o['id']) ?></b><br>
        تاریخ: <?= fa_num(jdate('j F Y', strtotime($o['created_at']))) ?>
      </div>
    </div>
    <table class="fr-table">
      <tr><th>خریدار</th><td><?= e(user_fullname($u)) ?> — <?= fa_num($u['mobile']) ?></td></tr>
      <tr><th>شرح خدمت</th><td><?= e($o['test_title']) ?> — نسخهٔ ویژه (تحلیل کامل)</td></tr>
      <tr><th>مبلغ</th><td><?= toman((int)$o['amount']) ?></td></tr>
      <tr><th>تخفیف</th><td><?= $o['discount_amt'] ? toman((int)$o['discount_amt']) . ' (کد: ' . e($o['discount_code']) . ')' : '—' ?></td></tr>
      <tr><th>مبلغ پرداخت‌شده</th><td><b><?= toman((int)$o['payable']) ?></b></td></tr>
      <tr><th>وضعیت</th><td><?= $o['status']==='paid' ? '<span class="fr-chip ok">پرداخت‌شده ✅</span>' : '<span class="fr-chip warn">در انتظار</span>' ?></td></tr>
      <?php if ($pay): ?><tr><th>کد رهگیری درگاه</th><td style="direction:ltr;text-align:right"><?= e($pay['ref_id']) ?></td></tr><?php endif; ?>
    </table>
    <div style="margin-top:16px;font-size:12px;color:var(--muted);text-align:center">از اعتماد شما سپاسگزاریم 🦢</div>
  </div>
</div>
<?php render_footer(); ?>
