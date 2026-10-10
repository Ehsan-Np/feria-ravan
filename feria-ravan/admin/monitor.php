<?php
/** رصد فعالیت — لاگ رویدادها، لاگ پیامک با متن کامل، نشست‌های فعال */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin();

$tab = (string)($_GET['tab'] ?? 'activity');

admin_layout($adm, 'monitor', 'رصد فعالیت و امنیت');
?>
<div class="fr-card" style="margin-bottom:20px">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="fr-btn sm <?= $tab==='activity'?'':'ghost' ?>" href="?tab=activity">⚡ رویدادها</a>
      <a class="fr-btn sm <?= $tab==='sms'?'':'ghost' ?>" href="?tab=sms">📩 پیامک‌های ارسالی</a>
      <a class="fr-btn sm <?= $tab==='sessions'?'':'ghost' ?>" href="?tab=sessions">🖥 نشست‌های آنلاین</a>
    </div>
    <a class="fr-btn sm ghost" href="?tab=<?= e($tab) ?>" title="به‌روزرسانی داده‌ها">🔄 تازه‌سازی</a>
  </div>
</div>

<?php if ($tab === 'activity'):
  $actFa = [
      'login'=>'ورود','logout'=>'خروج','otp_sent'=>'ارسال کد OTP','pay_init'=>'اقدام به پرداخت',
      'pay_ok'=>'پرداخت موفق','pay_cancel'=>'لغو پرداخت','pay_verify_failed'=>'خطای تأیید پرداخت',
      'test_start'=>'شروع تست روانشناسی','test_done'=>'اتمام تست روانشناسی','report_view'=>'مشاهده گزارش',
      'profile_update'=>'ویرایش پروفایل','password_set'=>'تنظیم رمز','admin_login'=>'ورود مدیر',
      'admin_login_fail'=>'ورود ناموفق مدیر','admin_q_delete'=>'حذف سؤال','admin_user_block'=>'مسدودسازی کاربر',
      'test_abandon'=>'انصراف از آزمون','admin_settings_save'=>'ذخیره تنظیمات سیستم'
  ];
  $logs = DB::all("SELECT l.*, COALESCE(u.mobile,(SELECT username FROM " . DB::t('admins') . " a WHERE a.id=l.actor_id)) AS ident
                   FROM " . DB::t('activity_logs') . " l
                   LEFT JOIN " . DB::t('users') . " u ON u.id=l.actor_id AND l.actor_type='user'
                   ORDER BY l.id DESC LIMIT 200");
?>
<div class="fr-card">
  <h3 style="margin-top:0">رویدادهای اخیر (<?= fa_num(count($logs)) ?>)</h3>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>زمان</th><th>کنش‌گر</th><th>رویداد</th><th>موجودیت</th><th>دستگاه</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
    <tr>
      <td style="font-size:12px;white-space:nowrap"><?= fa_num(jdate('j F - H:i:s', strtotime($l['created_at']))) ?></td>
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
  $evFa = [
      'otp'               => '🔐 کد ورود / تأیید شماره',
      'test_reminder'     => '📲 یادآوری تست نیمه‌کاره',
      'attempt_report'    => '📊 ارسال لینک کارنامه',
      'report_link'       => '📊 ارسال لینک گزارش',
      'consult_booked'    => '🛋 رزرو نوبت مشاوره',
      'consult_cancelled' => '❌ لغو نوبت مشاوره',
      'consult_reminder'  => '⏰ یادآوری جلسه مشاوره',
      'admin_order_alert' => '💳 اطلاع خرید به مدیر',
      'admin_refund_alert'=> '↩️ اطلاع درخواست عودت',
      'reg_success'       => '🎓 ثبت‌نام در رویداد',
      'newsletter'        => '📢 خبرنامه پیامکی',
      'campaign'          => '🎯 کمپین تبلیغاتی',
      'test_invite'       => '📩 دعوت به تست دو نفره',
      'sms_test'          => '🧪 پیامک آزمایشی',
  ];

  $q = trim((string)($_GET['q'] ?? ''));
  $st = trim((string)($_GET['st'] ?? ''));

  $where = [];
  $params = [];
  if ($q !== '') {
      $where[] = "(mobile LIKE ? OR body LIKE ? OR event LIKE ?)";
      $params[] = "%{$q}%";
      $params[] = "%{$q}%";
      $params[] = "%{$q}%";
  }
  if ($st !== '') {
      $where[] = "status = ?";
      $params[] = $st;
  }
  $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

  $totalSms = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('sms_logs'));
  $logs = DB::all("SELECT * FROM " . DB::t('sms_logs') . " {$whereSql} ORDER BY id DESC LIMIT 200", $params);
?>
<div class="fr-card" style="margin-bottom:20px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px">
    <h3 style="margin:0;font-size:16px">📩 تاریخچه و متن کامل پیامک‌های ارسالی (<?= fa_num(count($logs)) ?> از <?= fa_num($totalSms) ?>)</h3>
    <div style="font-size:12.5px;color:var(--muted)">
      نمایش بدون محدودیت متن + محاسبه جزء پیامک و کپی سریع
    </div>
  </div>

  <!-- فیلتر و جستجو در پیامک‌ها -->
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="tab" value="sms">
    <input class="fr-input sm" name="q" value="<?= e($q) ?>" placeholder="جستجو بر اساس شماره موبایل، متن یا رویداد..." style="flex:1;min-width:240px">
    <select class="fr-select sm" name="st" style="width:auto">
      <option value="">همه وضعیت‌ها</option>
      <option value="sent" <?= $st==='sent'?'selected':'' ?>>✅ ارسال شده</option>
      <option value="disabled" <?= $st==='disabled'?'selected':'' ?>>⚠️ غیرفعال در سامانه</option>
      <option value="failed" <?= $st==='failed'?'selected':'' ?>>❌ ناموفق / خطا</option>
    </select>
    <button class="fr-btn sm" type="submit">🔍 فیلتر</button>
    <?php if ($q !== '' || $st !== ''): ?>
      <a class="fr-btn sm ghost" href="?tab=sms">پاک‌سازی فیلتر</a>
    <?php endif; ?>
  </form>
</div>

<div class="fr-card">
  <?php if (empty($logs)): ?>
    <div style="text-align:center;padding:36px;color:var(--muted)">
      پیامکی مطابق با جستجوی شما یافت نشد.
    </div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table class="fr-table" style="vertical-align:top">
      <thead>
        <tr>
          <th style="width:125px">زمان ارسال</th>
          <th style="width:120px">شماره گیرنده</th>
          <th style="width:140px">رویداد</th>
          <th style="min-width:320px">متن کامل پیامک</th>
          <th style="width:110px">وضعیت</th>
          <th style="width:85px">هزینه</th>
          <th style="width:110px">شناسه / IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $l):
        $body = (string)($l['body'] ?? '');
        // بازیابی متن کامل برای رکوردهای قدیمی که فقط کد الگو ثبت کرده بودند
        if (str_starts_with($body, 'pattern:') && !empty($l['event'])) {
            try {
                $tplRow = DB::one('SELECT body, pattern_code FROM ' . DB::t('sms_templates') . ' WHERE event=?', [$l['event']]);
                if ($tplRow && !empty($tplRow['body'])) {
                    $body = "الگوی کاوه‌نگار (" . substr($body, 8) . "):
" . $tplRow['body'];
                }
            } catch (Throwable $e) {}
        }
        $charLen = mb_strlen($body);
        $parts = $charLen <= 70 ? 1 : (1 + (int)ceil(($charLen - 70) / 67));
        $evLabel = $evFa[$l['event'] ?? ''] ?? ($l['event'] ? e($l['event']) : 'عمومی / سایر');
      ?>
      <tr>
        <td style="font-size:12px;white-space:nowrap;color:#475569">
          <?= fa_num(jdate('Y/m/d', strtotime($l['created_at']))) ?><br>
          <b style="color:#0f172a"><?= fa_num(jdate('H:i:s', strtotime($l['created_at']))) ?></b>
        </td>
        <td style="font-size:12.5px;direction:ltr;white-space:nowrap;font-family:monospace;font-weight:600">
          <a href="?tab=sms&q=<?= urlencode((string)$l['mobile']) ?>" title="فیلتر پیامک‌های این شماره" style="color:inherit;text-decoration:none">
            <?= fa_num($l['mobile']) ?>
          </a>
        </td>
        <td style="font-size:12px;white-space:nowrap">
          <span class="fr-chip info" style="font-size:11px;padding:3px 8px;display:inline-block">
            <?= $evLabel ?>
          </span>
        </td>
        <td>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.85;color:#1e293b;white-space:pre-wrap;word-break:break-word;direction:rtl;text-align:right;box-shadow:0 1px 2px rgba(0,0,0,0.03)">
            <?= nl2br(e($body)) ?>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;gap:8px;flex-wrap:wrap">
            <span style="font-size:11px;color:var(--muted)">
              📏 <b><?= fa_num($charLen) ?></b> نویسه (≈ <b><?= fa_num($parts) ?></b> بخش پیامک فارسی)
            </span>
            <button type="button" class="fr-btn sm ghost copy-sms-btn" style="padding:2px 8px;font-size:11px;height:24px;line-height:22px" onclick="copySmsText(this, <?= e(json_encode($body)) ?>)">
              📋 کپی متن کامل
            </button>
          </div>
        </td>
        <td style="white-space:nowrap">
          <?php if ($l['status'] === 'sent'): ?>
            <span class="fr-chip ok" style="font-size:11px">✅ ارسال شد</span>
          <?php elseif ($l['status'] === 'disabled'): ?>
            <span class="fr-chip warn" style="font-size:11px">⚠️ غیرفعال</span>
          <?php else: ?>
            <span class="fr-chip err" style="font-size:11px">❌ <?= e($l['status'] ?: 'ناموفق') ?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;white-space:nowrap">
          <?= $l['cost'] ? fa_num($l['cost']) . ' <small style="color:var(--muted)">ریال</small>' : '—' ?>
        </td>
        <td style="font-size:11px;direction:ltr;color:var(--muted);white-space:nowrap">
          <?php if (!empty($l['provider_id'])): ?>
            <div style="font-family:monospace;font-size:10px;color:#64748b" title="شناسه پیگیری کاوه‌نگار">
              ID: <?= e((string)$l['provider_id']) ?>
            </div>
          <?php endif; ?>
          <div><?= $l['ip'] ? e(inet_ntop($l['ip'])) : '' ?></div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
function copySmsText(btn, text) {
  if (!navigator.clipboard) {
    var ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    btnSuccess(btn);
    return;
  }
  navigator.clipboard.writeText(text).then(function() {
    btnSuccess(btn);
  }).catch(function() {
    alert('متن پیامک:

' + text);
  });
}
function btnSuccess(btn) {
  var orig = btn.innerHTML;
  btn.innerHTML = '✓ کپی شد';
  btn.style.color = '#059669';
  btn.style.borderColor = '#059669';
  setTimeout(function() {
    btn.innerHTML = orig;
    btn.style.color = '';
    btn.style.borderColor = '';
  }, 2000);
}
</script>

<?php else:
  $now = DB::all("SELECT s.*, COALESCE(u.mobile,(SELECT username FROM " . DB::t('admins') . " a WHERE a.id=s.admin_id)) AS ident
                  FROM " . DB::t('sessions') . " s LEFT JOIN " . DB::t('users') . " u ON u.id=s.user_id
                  WHERE s.logout_at IS NULL AND s.last_seen > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                  ORDER BY s.last_seen DESC");
?>
<div class="fr-card">
  <h3 style="margin-top:0">نشست‌های فعال (۳۰ دقیقه اخیر)</h3>
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
