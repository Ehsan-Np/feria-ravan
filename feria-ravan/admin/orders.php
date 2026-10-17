<?php
/**
 * =============================================================================
 *  سفارش‌ها و تراکنش‌ها — نمایش، فیلتر، خروجی اکسل (نسخهٔ ۱٫۴)
 *  ─ پوشش هر سه نوع: تست روانشناسی / رویداد / رزرو مشاوره (رفع نقص نامرئی‌بودن رویدادها)
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin();

$st = (string)($_GET['status'] ?? '');
$kd = (string)($_GET['kind'] ?? '');
$where = '1=1'; $params = [];
if (in_array($st, ['pending','paid','failed','refunded','canceled'], true)) { $where .= " AND o.status=?"; $params[] = $st; }
if (in_array($kd, ['test_full','event','consult'], true)) { $where .= " AND o.kind=?"; $params[] = $kd; }

/* عنوان محصول هر سفارش بر اساس نوع (تست روانشناسی/رویداد/مشاوره) */
$titleSelect = "CASE o.kind
    WHEN 'test_full' THEN COALESCE(t.title,'تست روانشناسی')
    WHEN 'event'     THEN COALESCE(ev.title,'رویداد')
    WHEN 'consult'   THEN CONCAT('رزرو ', COALESCE((SELECT sv.title FROM " . DB::t('consult_appointments') . " a
                            JOIN " . DB::t('consult_services') . " sv ON sv.id=a.service_id
                            WHERE a.order_id=o.id LIMIT 1),'مشاوره'))
    ELSE '—' END AS ititle";
$baseJoins = " FROM " . DB::t('orders') . " o
      JOIN " . DB::t('users') . " u ON u.id=o.user_id
      LEFT JOIN " . DB::t('tests') . " t ON t.id=o.test_id
      LEFT JOIN " . DB::t('events') . " ev ON ev.id=o.event_id";

if (isset($_GET['export'])) {
    $rows = DB::all("SELECT o.*, u.first_name, u.last_name, u.mobile, $titleSelect,
      (SELECT p.ref_id FROM " . DB::t('payments') . " p WHERE p.order_id=o.id AND p.status='ok' ORDER BY p.id DESC LIMIT 1) AS refid
      $baseJoins WHERE $where ORDER BY o.id DESC LIMIT 5000", $params);
    $kindFa = ['test_full'=>'تست روانشناسی','event'=>'رویداد','consult'=>'مشاوره'];
    $out = [];
    foreach ($rows as $o) {
        $out[] = [$o['id'], trim($o['first_name'].' '.$o['last_name']), $o['mobile'], $kindFa[$o['kind']] ?? $o['kind'], $o['ititle'],
            number_format($o['amount']), $o['discount_code'] ?: '', number_format($o['discount_amt']),
            number_format($o['payable']), $o['status'], $o['refid'] ?: '',
            fa_num(jdate('Y/m/d H:i', strtotime($o['created_at'])))];
    }
    export_xls('orders-' . date('Ymd-Hi'), 'گزارش فروش — ' . DB::setting('site_title','فریا روان'),
               ['شناسه','کاربر','موبایل','نوع','تست روانشناسی/خدمت','مبلغ','کد تخفیف','تخفیف','پرداختی','وضعیت','کد رهگیری','تاریخ'], $out);
}

$rows = DB::all("SELECT o.*, u.first_name, u.last_name, u.mobile, $titleSelect $baseJoins
                 WHERE $where ORDER BY o.id DESC LIMIT 200", $params);
$sumPaid = (int)DB::val("SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " WHERE status='paid'");
$kindFa  = ['test_full'=>['🧠 تست روانشناسی','purple'],'event'=>['🛠 رویداد','info'],'consult'=>['🛋 مشاوره','warn']];

admin_layout($adm, 'orders', 'سفارش‌ها و تراکنش‌ها');
?>
<div class="fr-card">
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <span class="fr-chip purple">جمع فروش: <?= toman($sumPaid) ?></span>
    <a class="fr-btn sm <?= !$st?'':'ghost' ?>" href="orders.php">همه</a>
    <?php foreach (['paid'=>'موفق','pending'=>'در انتظار','failed'=>'ناموفق'] as $k=>$v): ?>
    <a class="fr-btn sm <?= $st===$k?'':'ghost' ?>" href="?status=<?= $k ?>"><?= $v ?></a>
    <?php endforeach; ?>
    <span style="border-right:1.5px dashed #d4dcec;height:22px"></span>
    <?php foreach ($kindFa as $k=>[$t,$c]): ?>
    <a class="fr-btn sm <?= $kd===$k?'':'ghost' ?>" href="?kind=<?= $k ?>"><?= $t ?></a>
    <?php endforeach; ?>
    <a class="fr-btn sm accent" href="?export=1<?= $st?'&status='.$st:'' ?><?= $kd?'&kind='.$kd:'' ?>">📊 خروجی Excel</a>
  </div>
</div>
<div class="fr-card">
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>#</th><th>کاربر</th><th>نوع</th><th>تست روانشناسی/خدمت</th><th>مبلغ</th><th>تخفیف</th><th>پرداختی</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">سفارشی یافت نشد.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $o): [$kt,$kc] = $kindFa[$o['kind']] ?? [$o['kind'],'info']; ?>
    <tr>
      <td><a href="user-view.php?id=<?= $o['user_id'] ?>"><?= fa_num($o['id']) ?></a></td>
      <td style="font-size:12.5px"><?= e(trim($o['first_name'].' '.$o['last_name'])) ?><br>
        <small style="color:var(--muted)"><?= fa_num($o['mobile']) ?></small></td>
      <td><span class="fr-chip <?= $kc ?>" style="font-size:10.5px"><?= $kt ?></span></td>
      <td style="font-size:12px">
        <?= e($o['ititle']) ?>
        <?php if (!empty($o['custom_fields_json'])):
          $cfData = json_decode((string)$o['custom_fields_json'], true);
          if (!empty($cfData) && is_array($cfData)):
            $snippets = [];
            foreach ($cfData as $item) {
                $snippets[] = ($item['label'] ?? '') . ': ' . ($item['value'] ?? '');
            }
        ?>
          <div style="font-size:11px;color:#2563eb;margin-top:3px" title="<?= e(implode(' | ', $snippets)) ?>">
            📋 <?= fa_num(count($cfData)) ?> فیلد سفارشی
            <?php if (!empty($cfData['has_companion']['value']) && ($cfData['has_companion']['value'] === 'بله' || str_contains((string)$cfData['has_companion']['value'], 'بله'))): ?>
              <span class="fr-chip purple sm" style="font-size:10px;padding:1px 5px">همراه‌دار</span>
            <?php endif; ?>
            <?php if (!empty($cfData['free_consultation']['value']) && str_contains((string)$cfData['free_consultation']['value'], 'بله')): ?>
              <span class="fr-chip ok sm" style="font-size:10px;padding:1px 5px">مشاوره رایگان</span>
            <?php endif; ?>
          </div>
        <?php endif; endif; ?>
      </td>
      <td><?= fa_num(number_format($o['amount'])) ?></td>
      <td style="font-size:12px"><?= $o['discount_amt'] ? fa_num(number_format($o['discount_amt'])) . ' (' . e($o['discount_code']) . ')' : '—' ?></td>
      <td><b><?= fa_num(number_format($o['payable'])) ?></b></td>
      <td><span class="fr-chip <?= ['paid'=>'ok','pending'=>'warn','failed'=>'err','canceled'=>'err','refunded'=>'info'][$o['status']] ?>">
        <?= ['paid'=>'موفق','pending'=>'در انتظار','failed'=>'ناموفق','canceled'=>'لغو','refunded'=>'برگشت'][$o['status']] ?></span></td>
      <td style="font-size:12px"><?= fa_num(jdate('j F Y - H:i', strtotime($o['created_at']))) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php admin_layout_end(); ?>
