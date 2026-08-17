<?php
/** رصد فعالیت — لاگ رویدادها، لاگ پیامک، نشست‌های فعال (به‌روزرسانی با AJAX) */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin();

$tab = (string)($_GET['tab'] ?? 'activity');

admin_layout($adm, 'monitor', 'رصد فعالیت و امنیت');
?>
<div class="fr-card">
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="fr-btn sm <?= $tab==='activity'?'':'ghost' ?>" href="?tab=activity">⚡ رویدادها</a>
    <a class="fr-btn sm <?= $tab==='sms'?'':'ghost' ?>" href="?tab=sms">📩 پیامک‌های ارسالی</a>
    <a class="fr-btn sm <?= $tab==='sessions'?'':'ghost' ?>" href="?tab=sessions">🖥 نشست‌های آنلاین</a>
    <a class="fr-btn sm ghost" href="" title="به‌روزرسانی">🔄 تازه‌سازی</a>
  </div>
</div>

<?php if ($tab === 'activity'):
  $actFa = ['login'=>'ورود','logout'=>'خروج','otp_sent'=>'ارسال کد','pay_init'=>'اقدام به پرداخت','pay_ok'=>'پرداخت موفق','pay_cancel'=>'لغو پرداخت','pay_verify_failed'=>'خطای تأیید پرداخت','test_start'=>'شروع تست روانشناسی','test_done'=>'اتمام تست روانشناسی','report_view'=>'مشاهده گزارش','profile_update'=>'ویرایش پروفایل','password_set'=>'تنظیم رمز','admin_login'=>'ورود مدیر','admin_login_fail'=>'ورود ناموفق مدیر','admin_q_delete'=>'حذف سؤال','admin_user_block'=>'مسدودسازی کاربر','test_abandon'=>'انصراف'];
  $logs = DB::all("SELECT l.*, COALESCE(u.mobile,(SELECT username FROM " . DB::t('admins') . " a WHERE a.id=l.actor_id)) AS ident
                   FROM " . DB::t('activity_logs') . " l
                   LEFT JOIN " . DB::t('users') . " u ON u.id=l.actor_id AND l.actor_type='user'
                   ORDER BY l.id DESC LIMIT 200");
?>
<div class="fr-card">
  <h3>رویدادهای اخیر (<?= fa_num(count($logs)) ?>)</h3>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>زمان</th><th>کنش‌گر</th><th>رویداد</th><th>موجودیت</th><th>دستگاه</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td style="font-size:12px"><?= fa_num(jdate('j F - H:i:s', strtotime($l['created_at']))) ?></td>
      <td style="font-size:12px"><?= e($l['ident'] ?? 'سیستم') ?> <span class="fr-chip info" style="font-size:10px"><?= e($l['actor_type']) ?></span></td>
      <td><span class="fr-chip <?= str_contains($l['action'],'fail')||str_contains($l['action'],'block')||str_contains($l['action'],'delete') ? 'err':'purple' ?>">
        <?= e($actFa[$l['action']] ?? $l['action']) ?></span></td>
      <td style="font-size:12px"><?= e(($l['entity'] ?? '') . ($l['entity_id'] ? '#'.$l['entity_id'] : '')) ?></td>
      <td style="font-size:12px"><?= e($l['device'] ?? '') ?></td>
      <td style="font-size:11px;direction:ltr"><?= $l['ip'] ? e(inet_ntop($l['ip'])) : '' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php elseif ($tab === 'sms'):
  $logs = DB::all("SELECT * FROM " . DB::t('sms_logs') . " ORDER BY id DESC LIMIT 200");
?>
<div class="fr-card">
  <h3>تاریخچه پیامک‌ها</h3>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>زمان</th><th>گیرنده</th><th>رویداد</th><th>متن</th><th>وضعیت</th><th>هزینه</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td style="font-size:12px"><?= fa_num(jdate('j F - H:i:s', strtotime($l['created_at']))) ?></td>
      <td style="font-size:12px;direction:ltr"><?= fa_num($l['mobile']) ?></td>
      <td style="font-size:12px"><?= e($l['event'] ?? '—') ?></td>
      <td style="max-width:300px;font-size:11.5px"><?= e(mb_strimwidth($l['body'] ?? '', 0, 70, '…')) ?></td>
      <td><span class="fr-chip <?= $l['status']==='sent'?'ok':($l['status']==='disabled'?'warn':'err') ?>"><?= e($l['status'] ?? '—') ?></span></td>
      <td style="font-size:12px"><?= $l['cost'] ? fa_num($l['cost']) : '—' ?></td>
      <td style="font-size:11px;direction:ltr"><?= $l['ip'] ? e(inet_ntop($l['ip'])) : '' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php else:
  $now = DB::all("SELECT s.*, COALESCE(u.mobile,(SELECT username FROM " . DB::t('admins') . " a WHERE a.id=s.admin_id)) AS ident
                  FROM " . DB::t('sessions') . " s LEFT JOIN " . DB::t('users') . " u ON u.id=s.user_id
                  WHERE s.logout_at IS NULL AND s.last_seen > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                  ORDER BY s.last_seen DESC");
?>
<div class="fr-card">
  <h3>نشست‌های فعال (۳۰ دقیقه اخیر)</h3>
  <table class="fr-table">
    <thead><tr><th>کاربر</th><th>ورود</th><th>آخرین فعالیت</th><th>دستگاه</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($now as $s): ?>
    <tr>
      <td style="font-size:12px"><?= e($s['ident'] ?? 'مهمان') ?></td>
      <td style="font-size:12px"><?= fa_num(jdate('H:i', strtotime($s['login_at']))) ?></td>
      <td style="font-size:12px"><?= fa_num(jdate('H:i:s', strtotime($s['last_seen']))) ?></td>
      <td style="font-size:12px"><?= e($s['device']) ?></td>
      <td style="font-size:11px;direction:ltr"><?= $s['ip'] ? e(inet_ntop($s['ip'])) : '' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php admin_layout_end(); ?>
