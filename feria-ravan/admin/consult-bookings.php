<?php
/**
 * =============================================================================
 *  کلینیک مشاوره — مدیریت نوبت‌ها (نسخهٔ ۱٫۴)
 *  ─ فیلتر خدمت/وضعیت، تغییر وضعیت (انجام‌شد/عدم حضور/لغو)، خروجی اکسل
 *  ─ لغو توسط مدیر: اسلات آینده آزاد + پیامک لغو به مراجع و مشاور
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/includes/consult.php';
$adm = require_admin(['superadmin','manager']);

/* ============================================================================
 | اقدامات POST — تغییر وضعیت رزرو
 * ==========================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act'); $id = (int)post('id');
    $a = DB::one('SELECT a.*, s.starts_at FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id WHERE a.id=?', [$id]);

    /* ارسال پیامک یادآوری (قالب قابل‌ویرایش در تنظیمات ← پیامک) */
    if ($a && $act === 'remind' && $a['status'] === 'booked' && strtotime($a['starts_at']) > time()) {
        consult_notify_booking($id, 'reminded');
        log_activity('consult_admin_remind', 'consult_appointment', $id);
        flash('پیامک یادآوری نوبت برای مراجع ارسال شد 📨 (متن قالب از تنظیمات خوانده شد)');
    }
    if ($a && in_array($act, ['done','noshow'], true) && $a['status'] === 'booked') {
        DB::run('UPDATE ' . DB::t('consult_appointments') . ' SET status=? WHERE id=?', [$act, $id]);
        log_activity('consult_admin_' . $act, 'consult_appointment', $id);
        flash($act === 'done' ? 'جلسه «انجام‌شد» ثبت شد ✅' : '«عدم حضور» ثبت شد.');
    }
    if ($a && $act === 'cancel' && in_array($a['status'], ['booked','pending_payment'], true)) {
        DB::run('UPDATE ' . DB::t('consult_appointments') . " SET status='canceled' WHERE id=?", [$id]);
        if ($a['order_id'])
            DB::run('UPDATE ' . DB::t('orders') . " SET status='canceled' WHERE id=? AND status='pending'", [$a['order_id']]);
        if (strtotime($a['starts_at']) > time())
            DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='open', held_until=NULL WHERE id=?", [$a['slot_id']]);
        log_activity('consult_admin_cancel', 'consult_appointment', $id);
        if ($a['status'] === 'booked') consult_notify_booking($id, 'canceled');
        flash('نوبت لغو شد' . ($a['status'] === 'booked' ? ' و به مراجع و مشاور پیامک اطلاع‌رسانی شد' : '') . '.');
    }
    redirect(SITE_URL . '/admin/consult-bookings.php');
}

/* ============================================================================
 | خروجی اکسل
 * ==========================================================================*/
if (isset($_GET['export'])) {
    $rows = DB::all('SELECT a.*, s.starts_at, sv.title AS service_title, c.name AS cname,
                            u.first_name, u.last_name, u.mobile, o.payable, o.status AS order_status
                     FROM ' . DB::t('consult_appointments') . ' a
                     JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id
                     JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id
                     JOIN ' . DB::t('consultants') . ' c ON c.id=a.consultant_id
                     JOIN ' . DB::t('users') . ' u ON u.id=a.user_id
                     LEFT JOIN ' . DB::t('orders') . ' o ON o.id=a.order_id
                     ORDER BY s.starts_at DESC');
    $stFa = ['pending_payment'=>'در انتظار پرداخت','booked'=>'قطعی','done'=>'انجام‌شد','canceled'=>'لغوشده','noshow'=>'عدم حضور'];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [$r['id'], trim($r['first_name'].' '.$r['last_name']), $r['mobile'], $r['service_title'], $r['cname'],
                  fa_num(jdate('Y/m/d H:i', strtotime($r['starts_at']))), $r['mode'] === 'online' ? 'آنلاین' : 'حضوری',
                  $stFa[$r['status']] ?? $r['status'], $r['payable'] !== null ? toman((int)$r['payable']) : '—'];
    }
    export_xls('consult-bookings', 'نوبت‌های مشاوره',
               ['#','مراجع','موبایل','خدمت','مشاور','زمان جلسه','شیوه','وضعیت','مبلغ'], $out);
}

/* ============================================================================
 | فهرست با فیلتر
 * ==========================================================================*/
consult_release_expired_holds();
$fService = (int)($_GET['service'] ?? 0);
$fStatus  = (string)($_GET['status'] ?? '');
$where = '1=1'; $params = [];
if ($fService) { $where .= ' AND a.service_id=?'; $params[] = $fService; }
if (in_array($fStatus, ['pending_payment','booked','done','canceled','noshow'], true)) {
    $where .= ' AND a.status=?'; $params[] = $fStatus;
}
if (($focus = (int)($_GET['appt'] ?? 0)) > 0) { $where .= ' AND a.id=?'; $params[] = $focus; }

$rows = DB::all('SELECT a.*, s.starts_at, s.ends_at, sv.title AS service_title, c.name AS cname,
                        u.first_name, u.last_name, u.mobile, o.payable, o.status AS order_status
                 FROM ' . DB::t('consult_appointments') . ' a
                 JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id
                 JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id
                 JOIN ' . DB::t('consultants') . ' c ON c.id=a.consultant_id
                 JOIN ' . DB::t('users') . ' u ON u.id=a.user_id
                 LEFT JOIN ' . DB::t('orders') . ' o ON o.id=a.order_id
                 WHERE ' . $where . ' ORDER BY s.starts_at DESC LIMIT 300', $params);
$services = DB::all('SELECT id, title FROM ' . DB::t('consult_services') . ' ORDER BY sort, id');
$stFa = ['pending_payment'=>['در انتظار پرداخت','warn'],'booked'=>['قطعی ✅','ok'],'done'=>['انجام‌شد','purple'],
         'canceled'=>['لغوشده','err'],'noshow'=>['عدم حضور','err']];

admin_layout($adm, 'consult-bookings', 'نوبت‌های مشاوره');
echo flash();
?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
  <a class="fr-btn sm ghost" href="consult.php">🛋 خدمات و مشاوران</a>
  <a class="fr-btn sm ghost" href="consult-schedule.php">🗓 زمان‌بندی</a>
  <a class="fr-btn sm" href="consult-bookings.php">📋 نوبت‌ها</a>
  <a class="fr-btn sm accent" href="?export=1">📊 خروجی اکسل</a>
</div>

<!-- فیلترها -->
<div class="fr-card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <select class="fr-select" name="service" style="max-width:220px">
      <option value="0">همهٔ خدمات</option>
      <?php foreach ($services as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $fService===(int)$s['id']?'selected':'' ?>><?= e($s['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="fr-select" name="status" style="max-width:180px">
      <option value="">همهٔ وضعیت‌ها</option>
      <?php foreach ($stFa as $k=>[$t,$c]): ?>
      <option value="<?= $k ?>" <?= $fStatus===$k?'selected':'' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
    <button class="fr-btn sm ghost">فیلتر</button>
    <a class="fr-btn sm ghost" href="consult-bookings.php">حذف فیلتر</a>
  </form>
  <span style="margin-right:auto;color:var(--muted);font-size:12.5px"><?= fa_num(count($rows)) ?> نوبت</span>
</div>

<div class="fr-card">
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead><tr><th>مراجع</th><th>خدمت / مشاور</th><th>زمان جلسه</th><th>شیوه</th><th>مبلغ</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
    <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:26px">نوبتی با این فیلترها یافت نشد.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): $ts = strtotime($r['starts_at']); [$stT,$stC] = $stFa[$r['status']]; ?>
    <tr id="appt-<?= $r['id'] ?>">
      <td>
        <b style="font-size:13px"><?= e(trim($r['first_name'].' '.$r['last_name']) ?: '—') ?></b><br>
        <small style="direction:ltr;display:inline-block;color:var(--muted)"><?= fa_num($r['mobile']) ?></small>
        <?php if ($r['note']): ?><br><small style="color:#525d7e" title="موضوع ثبت‌شده توسط مراجع">✏️ <?= e(mb_substr($r['note'], 0, 60)) ?></small><?php endif; ?>
      </td>
      <td style="font-size:12.5px"><b><?= e($r['service_title']) ?></b><br><small style="color:var(--muted)"><?= e($r['cname']) ?></small></td>
      <td style="white-space:nowrap;font-size:12.5px">
        <?= fa_num(jdate('l j F Y', $ts)) ?><br>
        <b style="direction:ltr;display:inline-block"><?= fa_num(date('H:i', $ts)) ?> — <?= fa_num(date('H:i', strtotime($r['ends_at']))) ?></b>
        <?php if ($ts < time() && $r['status']==='booked'): ?><br><span class="fr-chip warn">گذشته — ثبت نتیجه</span><?php endif; ?>
      </td>
      <td><span class="fr-chip info"><?= $r['mode']==='online' ? '💻 آنلاین' : '🏢 حضوری' ?></span></td>
      <td style="font-size:12px"><?= $r['payable'] !== null ? toman((int)$r['payable']) : 'رایگان' ?><br>
        <small style="color:var(--muted)"><?= $r['order_status'] === 'paid' ? 'پرداخت‌شده' : ($r['payable'] ? 'نقدی/نامشخص' : '—') ?></small></td>
      <td><span class="fr-chip <?= $stC ?>"><?= $stT ?></span></td>
      <td class="actions" style="white-space:nowrap">
        <a class="fr-btn sm ghost" style="color:#4338ca" href="user-view.php?id=<?= $r['user_id'] ?>" title="مشاهده پرونده و خلاصه بالینی مراجع">📋 پرونده</a>
        <?php if ($r['status'] === 'booked'): ?>
        <?php if ($ts > time()): ?>
        <form method="post" style="display:inline" title="ارسال پیامک یادآوری نوبت"><?= csrf_field() ?>
          <input type="hidden" name="act" value="remind"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="fr-btn sm ghost">📨</button></form>
        <?php endif; ?>
        <form method="post" style="display:inline" title="جلسه انجام شد"><?= csrf_field() ?>
          <input type="hidden" name="act" value="done"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="fr-btn sm accent">✔</button></form>
        <form method="post" style="display:inline" title="عدم حضور مراجع"><?= csrf_field() ?>
          <input type="hidden" name="act" value="noshow"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="fr-btn sm ghost">🚫</button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('لغو نوبت؟ به مراجع و مشاور پیامک می‌رود.')"><?= csrf_field() ?>
          <input type="hidden" name="act" value="cancel"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="fr-btn sm danger">لغو</button></form>
        <?php elseif ($r['status'] === 'pending_payment'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('لغو این رزرو معلق؟ اسلات آزاد می‌شود.')"><?= csrf_field() ?>
          <input type="hidden" name="act" value="cancel"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="fr-btn sm ghost">آزادسازی</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php admin_layout_end(); ?>
