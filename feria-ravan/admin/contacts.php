<?php
/**
 * پیشخوان — دفترچهٔ مخاطبین اطلاع‌رسانی (نسخهٔ ۲٫۷ — بازسازی کامل)
 * ─ سه دید: لیست (فیلتر/صفحه‌بندی حرفه‌ای/انتخاب گروهی) | گروه‌ها (مدیریت کامل) | ویرایش تکی
 * ─ ثبت تاریخ تولد (شمسی)، اتصال کانال‌ها (username/chat_id دستی + بررسی خودکار)،
 *   کد دعوت دیپ‌لینک (start خودکار)، پیامک دعوت، همگام‌سازی خودکار با ثبت‌نام سایت
 * ─ عملیات گروهی: افزودن به گروه/حذف از گروه/مسدود/فعال/حذف/پیامک دعوت/خروجی CSV انتخابی
 * ─ لایه‌بندی واکنش‌گرا: جدول دسکتاپ، کارت موبایل
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/contacts.php';
$adm = require_admin(['superadmin','manager']);
csrf_guard();
if (!fr_admin_can($adm, 'newsletter')) { http_response_code(403); exit('عدم دسترسی'); }

$q    = trim((string)($_GET['q'] ?? ''));
$segF = (string)($_GET['seg'] ?? '');
$gF   = (int)($_GET['g'] ?? 0);
$chF  = (string)($_GET['ch'] ?? '');
$stF  = (string)($_GET['st'] ?? '');
$v    = (string)($_GET['v'] ?? 'list');

/* نگه‌داشتن فیلترها در بازگشت‌ها */
$back = SITE_URL . '/admin/contacts.php?' . http_build_query(array_filter(['q'=>$q,'seg'=>$segF,'g'=>$gF?:'','ch'=>$chF,'st'=>$stF,'p'=>(int)($_GET['p']??1)>1?(int)$_GET['p']:'']));

/* ── اقدامات ── */
$act = (string)($_POST['act'] ?? '');

if ($act === 'add') {
    [$id, $created] = fr_contact_add((string)($_POST['name'] ?? ''), (string)($_POST['mobile'] ?? ''),
                                     (string)($_POST['segment'] ?? ''), 'manual', (string)($_POST['note'] ?? ''),
                                     ['birth_date' => (string)($_POST['birth_date'] ?? ''),
                                      'groups' => (array)($_POST['groups'] ?? []),
                                      'tg_username' => (string)($_POST['tg_username'] ?? ''),
                                      'bale_username' => (string)($_POST['bale_username'] ?? '')]);
    flash($id ? ($created ? 'مخاطب اضافه شد ✅ وضعیت کانال‌هایش هم بررسی می‌شود.' : 'این شماره قبلاً بود؛ اطلاعاتش به‌روزرسانی شد.') : 'شمارهٔ موبایل معتبر نیست (مثل 0912………).', $id ? 'ok' : 'err');
    redirect($back);
}

if ($act === 'edit') {
    $cid = (int)($_POST['id'] ?? 0);
    $ok = fr_contact_update($cid, $_POST, (array)($_POST['groups'] ?? []));
    flash($ok ? 'اطلاعات مخاطب ذخیره شد ✅' : 'ذخیره ناموفق — موبایل معتبر/یکتا نیست یا مخاطب یافت نشد.', $ok ? 'ok' : 'err');
    redirect($ok ? (SITE_URL . '/admin/contacts.php?v=edit&id=' . $cid . '&saved=1') : $back);
}

if ($act === 'import') {
    $seg = trim((string)($_POST['segment'] ?? ''));
    $r = fr_contacts_import($_FILES['contacts_file'] ?? [], $seg, (string)($_POST['pasted'] ?? ''));
    $gid = (int)($_POST['import_group'] ?? 0);
    if (!$r['ok']) flash($r['error'] ?? 'خطا در خواندن فایل.', 'err');
    else {
        if ($gid > 0) {
            /* همهٔ شماره‌های همین درون‌ریزی به گروه انتخابی اضافه می‌شوند */
            $ids = DB::all("SELECT id FROM " . DB::t('contacts') . " WHERE source='import' AND updated_at >= NOW() - INTERVAL 10 MINUTE");
            fr_contacts_group_assign(array_column($ids, 'id'), $gid);
        }
        $extra = $r['invalid'] > 0 ? ' — ' . fa_num($r['invalid']) . ' ردیف نامعتبر' . ($r['samples'] ? ' (نمونه: ' . e(implode('، ', array_slice($r['samples'], 0, 3))) . ')' : '') : '';
        flash('درون‌ریزی انجام شد ✅ ' . fa_num($r['added']) . ' مخاطب تازه، ' . fa_num($r['merged']) . ' به‌روزرسانی (تکراری)' . $extra . ($gid > 0 ? ' — به گروه انتخابی هم افزوده شدند.' : ''));
        log_activity('contacts_import', 'admin', (int)$adm['id'], $r);
    }
    redirect($back);
}

if (in_array($act, ['toggle','del','invite','check'], true)) {
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'toggle') { DB::run("UPDATE " . DB::t('contacts') . " SET status=IF(status='active','blocked','active') WHERE id=?", [$id]); flash('وضعیت مخاطب تغییر کرد.'); }
    elseif ($act === 'del') { fr_contacts_bulk_status([$id], 'delete'); flash('مخاطب حذف شد.'); }
    elseif ($act === 'invite') {
        $r = fr_contact_send_invite($id);
        flash(($r['ok'] ? '✅ ' : '❌ ') . $r['message'], $r['ok'] ? 'ok' : 'err');
    }
    elseif ($act === 'check') {
        $r = fr_contact_check($id);
        $fa = ['connected' => 'متصل ✅', 'needs_start' => 'نیازمند استارت ربات', 'bot_off' => 'ربات خاموش', 'unknown' => 'نامشخص'];
        flash('نتیجهٔ بررسی کانال‌ها — تلگرام: ' . ($fa[$r['telegram']] ?? $r['telegram']) . ' • بله: ' . ($fa[$r['bale']] ?? $r['bale']));
    }
    redirect($back);
}

/* عملیات گروهی با چک‌باکس‌ها */
if ($act === 'bulk') {
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $op  = (string)($_POST['op'] ?? '');
    $n = 0;
    if ($ids) {
        if ($op === 'group_add')    $n = fr_contacts_group_assign($ids, (int)($_POST['group_id'] ?? 0));
        elseif ($op === 'group_del')  $n = fr_contacts_group_detach($ids, (int)($_POST['group_id'] ?? 0));
        elseif ($op === 'block')    $n = fr_contacts_bulk_status($ids, 'block');
        elseif ($op === 'activate') $n = fr_contacts_bulk_status($ids, 'activate');
        elseif ($op === 'delete')   $n = fr_contacts_bulk_status($ids, 'delete');
        elseif ($op === 'invite')   { foreach ($ids as $cid) { $r = fr_contact_send_invite($cid); if (!empty($r['ok'])) $n++; } }
        elseif ($op === 'check')    { foreach ($ids as $cid) { fr_contact_check($cid); $n++; } }
        log_activity('contacts_bulk', 'admin', (int)$adm['id'], ['op' => $op, 'count' => count($ids), 'done' => $n]);
        flash('عملیات گروهی انجام شد — ' . fa_num($n) . ' مخاطب از ' . fa_num(count($ids)) . ' انتخاب ✅');
    } else flash('هیچ مخاطبی انتخاب نشده است.', 'err');
    redirect($back);
}

/* مدیریت گروه‌ها */
if (in_array($act, ['group_add','group_edit','group_del'], true)) {
    if ($act === 'group_add') {
        $gid = fr_contact_group_add((string)($_POST['name'] ?? ''), (string)($_POST['color'] ?? '#1e3a6e'), (string)($_POST['note'] ?? ''));
        flash($gid ? 'گروه ساخته شد ✅' : 'نام گروه تکراری یا نامعتبر است.', $gid ? 'ok' : 'err');
    }
    if ($act === 'group_edit') {
        $ok = fr_contact_group_update((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), (string)($_POST['color'] ?? '#1e3a6e'), (string)($_POST['note'] ?? ''));
        flash($ok ? 'گروه به‌روزرسانی شد ✅' : 'نام گروه نامعتبر/تکراری است.', $ok ? 'ok' : 'err');
    }
    if ($act === 'group_del') {
        fr_contact_group_delete((int)($_POST['id'] ?? 0));
        flash('گروه حذف شد (مخاطبین حذف نمی‌شوند).');
    }
    redirect(SITE_URL . '/admin/contacts.php?v=groups');
}

/* خروجی CSV — همه یا فقط انتخاب‌شده‌ها */
if (($exp = (string)($_GET['export'] ?? '')) !== '') {
    fr_contacts_match_social();
    $sel = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
    $w = '1'; $pr = [];
    if ($exp === 'sel' && $sel) { $w = 'c.id IN (' . implode(',', $sel) . ')'; }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=contacts-' . date('Ymd-Hi') . '.csv');
    echo "\xEF\xBB\xBF" . "name,mobile,segment,telegram,bale,status,birth_date,groups,telegram_username,bale_username,source\n";
    foreach (DB::all("SELECT c.*, (SELECT GROUP_CONCAT(g.name SEPARATOR '، ') FROM " . DB::t('contact_group_members') . " m JOIN " . DB::t('contact_groups') . " g ON g.id=m.group_id WHERE m.contact_id=c.id) AS gnames
                      FROM " . DB::t('contacts') . " c WHERE $w ORDER BY c.id DESC", $pr) as $c) {
        $bd = !empty($c['birth_date']) ? jdate('Y/m/d', strtotime((string)$c['birth_date'])) : '';
        $bd = en_num($bd);
        echo '"' . str_replace('"', '""', (string)$c['name']) . '",' . $c['mobile'] . ',"'
           . str_replace('"', '""', (string)$c['segment']) . '",' . ((int)$c['tg_ok'] ? 1 : 0) . ',' . ((int)$c['bale_ok'] ? 1 : 0) . ','
           . $c['status'] . ',"' . $bd . '","'
           . str_replace('"', '""', (string)$c['gnames']) . '","'
           . str_replace('"', '""', (string)($c['tg_username'] ?? '')) . '","' . str_replace('"', '""', (string)($c['bale_username'] ?? '')) . '",'
           . $c['source'] . "\n";
    }
    exit;
}

/* ── داده ── */
fr_contacts_match_social();
$stats = DB::one("SELECT COUNT(*) AS total, SUM(tg_ok) AS tg, SUM(bale_ok) AS bl, SUM(status='active') AS act,
                  SUM(source='register') AS reg, SUM(birth_date IS NOT NULL) AS bd FROM " . DB::t('contacts'));
$groups = fr_contact_groups();
$groupPads = ['#1e3a6e','#0f766e','#8a5a00','#b91c1c','#6d28d9','#0c7a4d','#be185d','#334155'];
$segments = fr_contacts_segments();

$where = "1"; $params = [];
if ($q !== '')     { $where .= " AND (c.name LIKE ? OR c.mobile LIKE ?)"; $params[] = '%' . $q . '%'; $params[] = '%' . en_num($q) . '%'; }
if ($segF !== '')  { $where .= " AND c.segment=?"; $params[] = $segF; }
if ($gF > 0)       { $where .= " AND EXISTS (SELECT 1 FROM " . DB::t('contact_group_members') . " gm WHERE gm.contact_id=c.id AND gm.group_id=?)"; $params[] = $gF; }
if ($chF === 'tg')       $where .= " AND c.tg_ok=1";
if ($chF === 'bale')     $where .= " AND c.bale_ok=1";
if ($chF === 'none')     $where .= " AND c.tg_ok=0 AND c.bale_ok=0";
if ($stF === 'active')   $where .= " AND c.status='active'";
if ($stF === 'blocked')  $where .= " AND c.status='blocked'";

$per  = (int)($_GET['per'] ?? 15); $per = in_array($per, [15, 30, 60, 120], true) ? $per : 15;
$page = max(1, (int)($_GET['p'] ?? 1));
$totalRows = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('contacts') . " c WHERE $where", $params);
$pages = max(1, (int)ceil($totalRows / $per));
$page = min($page, $pages);
$rows = $v === 'list' ? DB::all("SELECT c.* FROM " . DB::t('contacts') . " c WHERE $where ORDER BY c.id DESC LIMIT " . (($page - 1) * $per) . ", $per", $params) : [];
/* گروه‌های ردیف‌های همین صفحه (بدون N+1) */
$rowGroups = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_map('intval', $ids));
    foreach (DB::all("SELECT m.contact_id, g.id, g.name, g.color FROM " . DB::t('contact_group_members') . " m
                      JOIN " . DB::t('contact_groups') . " g ON g.id=m.group_id WHERE m.contact_id IN ($in) ORDER BY g.name") as $gr) {
        $rowGroups[(int)$gr['contact_id']][] = $gr;
    }
}
$query = fn(array $over = []) => '?' . http_build_query(array_merge(['q'=>$q,'seg'=>$segF,'g'=>$gF?:'','ch'=>$chF,'st'=>$stF,'per'=>$per], $over));

admin_layout($adm, 'contacts', '📇 مخاطبین اطلاع‌رسانی');
echo flash();
?>
<style>
/* ── ۲٫۷: لایهٔ واکنش‌گرای مخاطبین ───────────────────────────── */
.ct-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin-bottom:14px}
.ct-stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px;text-align:center}
.ct-stat b{display:block;font-size:20px}
.ct-group-chip{display:inline-flex;align-items:center;gap:5px;border-radius:99px;padding:1px 10px;font-size:11px;border:1px solid var(--line);background:var(--card);white-space:nowrap}
.ct-group-chip .dot{width:9px;height:9px;border-radius:50%;display:inline-block}
.ct-bulkbar{position:sticky;top:10px;z-index:30;background:linear-gradient(135deg,#122850,#1e3a6e);color:#fff;border-radius:14px;padding:9px 12px;margin:0 0 12px;display:none;gap:8px;align-items:center;flex-wrap:wrap;box-shadow:0 10px 24px rgba(18,40,80,.25)}
.ct-bulkbar.on{display:flex}
.ct-bulkbar .fr-btn.sm{background:#ffffff22;color:#fff;border:1px solid #ffffff44}
.ct-bulkbar select{background:#ffffff16;color:#fff;border:1px solid #ffffff33;border-radius:9px;padding:5px 8px;font-family:inherit}
.ct-bulkbar select option{color:#122850}
.ct-gcard{border:1.5px solid var(--line);border-radius:16px;padding:14px;background:var(--card);border-top:4px solid var(--group-c,#1e3a6e)}
.ct-gcard h4{margin:0 0 4px;font-size:14.5px}
.ct-pager{display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:center;margin-top:14px}
.ct-pager .fr-btn.sm{min-width:36px}
.ct-invlink{direction:ltr;text-align:left;font-family:monospace;font-size:11px;background:#f4f7fc;border:1px dashed var(--line);border-radius:8px;padding:6px 8px;word-break:break-all;user-select:all}
@media (max-width:860px){
  .ct-hide-sm{display:none !important}
  .ct-cards tr{display:block;border:1px solid var(--line);border-radius:14px;margin-bottom:10px;padding:10px;background:var(--card)}
  .ct-cards td{display:flex;justify-content:space-between;gap:8px;border:none;padding:5px 2px;font-size:12.5px}
  .ct-cards td::before{content:attr(data-l);font-weight:700;color:var(--muted);font-size:11px}
  .ct-cards thead{display:none}
  .ct-cards{display:block}
  .ct-cards tbody{display:block}
}
</style>

<div class="ct-grid">
  <div class="ct-stat"><b><?= fa_num((int)$stats['total']) ?></b><small>کل مخاطبین</small></div>
  <div class="ct-stat"><b style="color:#2294c4"><?= fa_num((int)$stats['tg']) ?></b><small>قابل‌دسترس در تلگرام</small></div>
  <div class="ct-stat"><b style="color:#0f766e"><?= fa_num((int)$stats['bl']) ?></b><small>قابل‌دسترس در بله</small></div>
  <div class="ct-stat"><b><?= fa_num((int)$stats['act']) ?></b><small>فعال</small></div>
  <div class="ct-stat"><b><?= fa_num(count($groups)) ?></b><small>گروه</small></div>
  <div class="ct-stat"><b style="color:#8a5a00"><?= fa_num((int)$stats['reg']) ?></b><small>از ثبت‌نام سایت</small></div>
  <div class="ct-stat"><b style="color:#be185d"><?= fa_num((int)$stats['bd']) ?></b><small>دارای تاریخ تولد</small></div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
  <a class="fr-btn <?= $v === 'list' ? '' : 'ghost' ?> sm" href="?v=list">📇 لیست مخاطبین</a>
  <a class="fr-btn <?= $v === 'groups' ? '' : 'ghost' ?> sm" href="?v=groups">🗂 مدیریت گروه‌ها (<?= fa_num(count($groups)) ?>)</a>
  <a class="fr-btn ghost sm" href="<?= e(SITE_URL) ?>/admin/campaigns.php?v=new">📣 کمپین جدید برای مخاطبین ↗</a>
</div>

<?php if ($v === 'groups'): ?>
<!-- ═══════════ دید گروه‌ها (مدیریت کامل جداگانه) ═══════════ -->
<div class="fr-card">
  <h3 style="margin-top:0">🗂 گروه‌های مخاطبین — مدیریت کامل</h3>
  <p class="muted" style="font-size:12.5px;line-height:2;margin-top:0">
    گروه‌ها «برچسب» کامل مدیریتی‌اند: هر مخاطب می‌تواند هم‌زمان در چند گروه باشد؛ در کمپین‌ها می‌توانی مخاطبین یک گروه خاص را هدف بگیری.
    رنگ هر گروه در لیست مخاطبین به‌صورت چیپ رنگی دیده می‌شود.
  </p>
  <form method="post" class="fr-grid" style="grid-template-columns:2fr 1fr 2fr auto;gap:10px;align-items:end">
    <?= csrf_field() ?><input type="hidden" name="act" value="group_add">
    <div class="fr-field" style="margin:0"><label>نام گروه تازه</label><input class="fr-input" name="name" maxlength="80" required placeholder="مثلاً مدیران تهران"></div>
    <div class="fr-field" style="margin:0"><label>رنگ</label><input class="fr-input" type="color" name="color" value="#1e3a6e" style="height:42px;padding:4px"></div>
    <div class="fr-field" style="margin:0"><label>توضیح (اختیاری)</label><input class="fr-input" name="note" maxlength="190" placeholder="منشأ یا هدف این گروه…"></div>
    <button class="fr-btn">➕ ساخت گروه</button>
  </form>
</div>

<div class="fr-grid cols-3" style="align-items:start">
  <?php foreach ($groups as $g): ?>
  <div class="ct-gcard" style="--group-c:<?= e($g['color']) ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
      <h4><span style="display:inline-block;width:11px;height:11px;border-radius:50%;background:<?= e($g['color']) ?>"></span> <?= e($g['name']) ?></h4>
      <span class="chip"><?= fa_num((int)$g['members']) ?> عضو</span>
    </div>
    <?php if ($g['note'] !== ''): ?><p class="muted" style="font-size:12px;margin:6px 0"><?= e($g['note']) ?></p><?php endif; ?>
    <div style="font-size:11px;color:var(--muted)">ساخته‌شده: <?= fa_num(jdate('Y/m/d', strtotime($g['created_at']))) ?></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
      <a class="fr-btn sm ghost" href="<?= e($query(['v'=>'list','g'=>(int)$g['id'],'p'=>null])) ?>">👥 مشاهده اعضا</a>
      <details style="flex:1">
        <summary class="fr-btn sm ghost" style="display:inline-block">✏️ ویرایش</summary>
        <form method="post" style="margin-top:8px;display:grid;gap:8px">
          <?= csrf_field() ?><input type="hidden" name="act" value="group_edit"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <input class="fr-input sm" name="name" value="<?= e($g['name']) ?>" maxlength="80" required>
          <div style="display:flex;gap:8px"><input class="fr-input sm" type="color" name="color" value="<?= e($g['color']) ?>" style="height:38px;padding:3px;max-width:60px">
          <input class="fr-input sm" name="note" value="<?= e($g['note']) ?>" maxlength="190" placeholder="توضیح"></div>
          <button class="fr-btn sm">ذخیره</button>
        </form>
      </details>
      <form method="post" onsubmit="return confirm('گروه «<?= e($g['name']) ?>» حذف شود؟ اعضایش به‌عنوان مخاطب باقی می‌مانند.')">
        <?= csrf_field() ?><input type="hidden" name="act" value="group_del"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
        <button class="fr-btn sm danger">🗑</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$groups): ?>
  <div class="fr-card" style="grid-column:1/-1;text-align:center;color:var(--muted)">
    هنوز گروهی نساخته‌ای — اولین گروه را از فرم بالا اضافه کن (مثلاً «مشتریان وفادار» یا «مدیران شرکت‌ها»)؛ بعد در لیست مخاطبین با انتخاب چندتایی اعضا را بهش اضافه می‌کنی.
  </div>
  <?php endif; ?>
</div>

<?php elseif ($v === 'edit'):
  $cid = (int)($_GET['id'] ?? 0);
  $c = DB::one("SELECT * FROM " . DB::t('contacts') . " WHERE id=?", [$cid]);
  if (!$c) { echo '<div class="fr-alert err">مخاطب پیدا نشد.</div>'; admin_layout_end(); exit; }
  $cGroups = array_map(fn($g) => (int)$g['id'], fr_contact_group_of($cid));
  $invTg = fr_contact_invite_link($c, 'telegram');
  $invBl = fr_contact_invite_link($c, 'bale');
  $chState = function (array $cc, string $which) {
      $chatCol = $which === 'telegram' ? 'tg_chat_id' : 'bale_chat_id';
      $unCol = $which === 'telegram' ? 'tg_username' : 'bale_username';
      $okCol = $which === 'telegram' ? 'tg_ok' : 'bale_ok';
      if (trim((string)($cc[$chatCol] ?? '')) !== '' && (int)($cc[$okCol] ?? 0) === 1) return ['متصل ✅', '#0c7a4d', 'chat_id: ' . (string)$cc[$chatCol]];
      if (trim((string)($cc[$unCol] ?? '')) !== '') return ['نیازمند استارت ⏳', '#8a5a00', '@' . (string)$cc[$unCol]];
      return ['تنظیم نشده', '#64748b', '—'];
  };
?>
<!-- ═══════════ ویرایش تکی مخاطب ═══════════ -->
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
    <h3 style="margin:0">✏️ ویرایش مخاطب <span class="chip">#<?= fa_num($cid) ?></span></h3>
    <a class="fr-btn ghost sm" href="<?= e($back) ?>">↩ بازگشت به لیست</a>
  </div>
  <?php if (!empty($_GET['saved'])): ?><div class="fr-alert ok">ذخیره شد ✅</div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="edit"><input type="hidden" name="id" value="<?= $cid ?>">
    <div class="fr-grid cols-3">
      <div class="fr-field"><label>نام مخاطب</label><input class="fr-input" name="name" value="<?= e($c['name']) ?>" maxlength="120"></div>
      <div class="fr-field"><label>موبایل *</label><input class="fr-input" name="mobile" dir="ltr" maxlength="11" value="<?= e($c['mobile']) ?>" required></div>
      <div class="fr-field"><label>تاریخ تولد <small>(شمسی: ۱۳۶۵/۰۴/۱۲)</small></label><input class="fr-input" name="birth_date" dir="ltr" value="<?= e(!empty($c['birth_date']) ? en_num(jdate('Y/m/d', strtotime((string)$c['birth_date']))) : '') ?>" placeholder="1365/04/12" pattern="\d{3,4}[/-]\d{1,2}[/-]\d{1,2}"></div>
      <div class="fr-field"><label>برچسب قدیمی (segment)</label><input class="fr-input" name="segment" value="<?= e($c['segment']) ?>" maxlength="60" list="segList">
        <datalist id="segList"><?php foreach ($segments as $sg): if ($sg['segment'] === '') continue; ?><option value="<?= e($sg['segment']) ?>"><?php endforeach; ?></datalist></div>
      <div class="fr-field"><label>یادداشت</label><input class="fr-input" name="note" value="<?= e($c['note']) ?>" maxlength="190"></div>
      <div class="fr-field"><label>وضعیت</label><div style="padding-top:8px"><?= $c['status'] === 'active' ? '<span class="chip" style="background:#e3f7ef;color:#0c7a4d">فعال</span>' : '<span class="chip" style="background:#fde8e8;color:#b91c1c">مسدود</span>' ?>
        <span class="hint"> (تغییر وضعیت از لیست انجام می‌شود)</span></div></div>
    </div>

    <div class="fr-field"><label>عضویت در گروه‌ها</label>
      <div style="display:flex;gap:12px;flex-wrap:wrap;border:1.5px solid var(--line);border-radius:12px;padding:10px">
        <?php foreach ($groups as $g): ?>
        <label class="tog"><input type="checkbox" name="groups[]" value="<?= (int)$g['id'] ?>" <?= in_array((int)$g['id'], $cGroups, true) ? 'checked' : '' ?>>
          <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= e($g['color']) ?>"></span> <?= e($g['name']) ?></label>
        <?php endforeach; ?>
        <?php if (!$groups): ?><span class="muted" style="font-size:12px">گروهی نیست — <a href="?v=groups">بساز ↗</a></span><?php endif; ?>
      </div>
    </div>

    <h4 style="margin:16px 0 8px">📡 اتصال کانال‌های پیام‌رسان <small style="color:var(--muted);font-weight:400">(موبایل / آیدی / شناسهٔ عددی)</small></h4>
    <div class="fr-grid cols-2">
      <?php foreach (['telegram' => ['🟦 تلگرام', 'tg_username', 'tg_chat_id', $invTg], 'bale' => ['🟩 بله', 'bale_username', 'bale_chat_id', $invBl]] as $chKey => [$chName, $unCol, $cidCol, $inv]):
        [$stLbl, $stColor, $stInfo] = $chState($c, $chKey); ?>
      <div style="border:1.5px solid var(--line);border-radius:14px;padding:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <b><?= $chName ?></b>
          <span class="chip" style="background:<?= $stColor ?>18;color:<?= $stColor ?>"><?= $stLbl ?></span>
        </div>
        <div class="fr-field"><label>آیدی کاربر (username)</label>
          <input class="fr-input" name="<?= $unCol ?>" dir="ltr" value="<?= e((string)($c[$unCol] ?? '')) ?>" placeholder="username" maxlength="60"></div>
        <div class="fr-field" style="margin-bottom:6px"><label>شناسهٔ عددی چت (chat_id) — ورود دستی مجاز است</label>
          <input class="fr-input" name="<?= $cidCol ?>" dir="ltr" value="<?= e((string)($c[$cidCol] ?? '')) ?>" placeholder="123456789" maxlength="60"></div>
        <?php if ($inv !== ''): ?>
        <small class="hint">لینک دعوت با start خودکار (کد اختصاصی این مخاطب):</small>
        <div class="ct-invlink"><?= e($inv) ?></div>
        <?php else: ?>
        <small class="hint" style="color:#b91c1c">نام کاربری ربات در «اطلاع‌رسانی اجتماعی» تنظیم نشده — لینک دعوت ساخته نمی‌شود.</small>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="fr-alert info" style="font-size:12px;line-height:2;margin-top:10px">
      <b>نکتهٔ فنی مهم (بندهای ۴ و ۷):</b> API رسمی ربات تلگرام/بله به دلیل حریم خصوصی <b>اجازهٔ جستجوی حساب با شمارهٔ موبایل</b> و
      <b>ارسال پیام پیش از زدن دکمهٔ start توسط کاربر</b> را نمی‌دهد — این محدودیتِ خود پلتفرم است نه سیستم.
      به همین دلیل راه‌حل حرفه‌ایِ پیاده‌شده چنین است: ۱) ثبت خودکار هنگام استارت کاربر از سایت (اتصال درجا میراث برداشته می‌شود)
      ۲) لینک دعوت با <b>کد start خودکار</b> (از پیامک دعوت) ۳) اشتراک شمارهٔ تماس در چت ربات ۴) getChat با آیدی ذخیره‌شده
      ۵) ورود دستی chat_id. اگر سرویس سازمانی بله (از bale.ai/org) یا درگاه پیام‌رسان کاوه‌نگار را داری، در
      <a href="<?= SITE_URL ?>/admin/notifications.php#gw">اطلاع‌رسانی ← درگاه پیام‌رسان</a> وصلش کن تا ارسال بله از آن عبور کند.
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
      <button class="fr-btn">💾 ذخیرهٔ مخاطب</button>
      <button class="fr-btn ghost" form="invF" onclick="event.preventDefault();document.getElementById('invF').submit()">📩 ارسال پیامک دعوت (start خودکار)</button>
      <button class="fr-btn ghost" form="chkF" onclick="event.preventDefault();document.getElementById('chkF').submit()">🔎 بررسی کانال‌ها</button>
    </div>
  </form>
  <form method="post" id="invF"><?= csrf_field() ?><input type="hidden" name="act" value="invite"><input type="hidden" name="id" value="<?= $cid ?>"></form>
  <form method="post" id="chkF"><?= csrf_field() ?><input type="hidden" name="act" value="check"><input type="hidden" name="id" value="<?= $cid ?>"></form>
</div>
<div class="fr-card">
  <h4 style="margin-top:0">🗂 جزئیات تکمیلی</h4>
  <div style="font-size:12.5px;color:var(--muted);line-height:2.2">
    منشأ ثبت: <b><?= e($c['source']) ?></b> • ثبت‌شده: <?= fa_num(jdate('Y/m/d H:i', strtotime($c['created_at']))) ?>
    • آخرین ویرایش: <?= fa_num(jdate('Y/m/d H:i', strtotime($c['updated_at']))) ?>
    <?= $c['user_id'] ? '• کاربر سایت: ✅ (شناسهٔ ' . fa_num((int)$c['user_id']) . ')' : '• کاربر سایت نیست (فقط مخاطب)' ?>
    <?= !empty($c['invite_sent_at']) ? '• آخرین پیامک دعوت: ' . fa_num(jdate('Y/m/d H:i', strtotime((string)$c['invite_sent_at']))) : '' ?>
    <?= !empty($c['checked_at']) ? '• آخرین بررسی کانال: ' . fa_num(jdate('Y/m/d H:i', strtotime((string)$c['checked_at']))) : '' ?>
  </div>
</div>

<?php else: ?>
<!-- ═══════════ دید لیست ═══════════ -->
<div class="fr-grid cols-2" style="align-items:start">
  <div class="fr-card" style="margin:0">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h3 style="margin:0">👤 افزودن مخاطب</h3>
      <a class="fr-btn ghost sm" data-toggle="addBox">بیشتر ▾</a>
    </div>
    <form method="post" id="addBox" style="margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="act" value="add">
      <div class="fr-grid cols-2">
        <div class="fr-field"><label>نام</label><input class="fr-input" name="name" maxlength="120" placeholder="مثلاً علی رضایی"></div>
        <div class="fr-field"><label>موبایل *</label><input class="fr-input" name="mobile" dir="ltr" maxlength="20" required placeholder="0912xxxxxxx"></div>
        <div class="fr-field"><label>تاریخ تولد (شمسی)</label><input class="fr-input" name="birth_date" dir="ltr" maxlength="10" placeholder="1365/04/12" pattern="\d{3,4}[/-]\d{1,2}[/-]\d{1,2}"></div>
        <div class="fr-field"><label>گروه‌ها</label>
          <select class="fr-select" name="groups[]" multiple size="<?= min(4, max(2, count($groups))) ?>">
            <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="fr-field"><label>برچسب/یادداشت</label>
          <div style="display:flex;gap:6px"><input class="fr-input" name="segment" placeholder="برچسب" list="segList2"><input class="fr-input" name="note" placeholder="یادداشت" maxlength="190"></div>
          <datalist id="segList2"><?php foreach ($segments as $sg): if ($sg['segment'] === '') continue; ?><option value="<?= e($sg['segment']) ?>"><?php endforeach; ?></datalist></div>
        <div class="fr-field"><label>آیدی تلگرام / بله (اختیاری)</label>
          <div style="display:flex;gap:6px"><input class="fr-input" name="tg_username" dir="ltr" placeholder="TG @…" maxlength="60"><input class="fr-input" name="bale_username" dir="ltr" placeholder="Bale @…" maxlength="60"></div></div>
      </div>
      <button class="fr-btn">➕ افزودن و بررسی خودکار کانال‌ها</button>
      <small class="hint" style="display:block;margin-top:6px">به‌محض ثبت: تطبیق با کاربران سایت + getChat روی آیدی‌ها خودکار انجام و وضعیت تلگرام/بله روشن می‌شود.</small>
    </form>
  </div>
  <div class="fr-card" style="margin:0">
    <h3 style="margin-top:0">📥 درون‌ریزی گروهی از اکسل / چسباندن</h3>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="act" value="import">
      <div class="fr-field"><input class="fr-input" type="file" name="contacts_file" accept=".csv,.txt,.xlsx" style="padding:9px">
        <small class="hint">ستون‌ها خودکار تشخیص داده می‌شوند: نام، موبایل، و حتی <b>تاریخ تولد</b> (شمسی یا میلادی با جداکنندهٔ /).</small></div>
      <div class="fr-field"><textarea class="fr-input" name="pasted" rows="2" dir="rtl" placeholder="یا این‌جا بچسبان — هر سطر: نام,موبایل[,تاریخ تولد]"></textarea></div>
      <div class="fr-grid cols-2">
        <div class="fr-field" style="margin:0"><label>ثبت در برچسب</label><input class="fr-input" name="segment" maxlength="60" list="segList2"></div>
        <div class="fr-field" style="margin:0"><label>افزودن به گروه</label>
          <select class="fr-select" name="import_group"><option value="0">— بدون گروه —</option>
            <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <button class="fr-btn" style="margin-top:10px">📥 ورود مخاطبین + بررسی کانال‌ها</button>
    </form>
  </div>
</div>

<!-- فیلترها + لیست -->
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
    <h3 style="margin:0">📇 فهرست مخاطبین <small style="color:var(--muted)">(نمایش <?= fa_num(min($per, $totalRows)) ?> از <?= fa_num($totalRows) ?>)</small></h3>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a class="fr-btn ghost sm" id="ctCsvSel" href="?export=all">⬇️ CSV</a>
      <button class="fr-btn ghost sm" type="button" onclick="document.getElementById('ctCsvSel').href='?export=sel&ids='+[...document.querySelectorAll('.ct-chk:checked')].map(c=>c.value).join(',')">⬇️ CSV انتخابی</button>
    </div>
  </div>
  <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
    <input type="hidden" name="v" value="list">
    <input class="fr-input sm" name="q" value="<?= e($q) ?>" placeholder="جستجوی نام/موبایل" style="max-width:170px">
    <select class="fr-select sm" name="g" style="max-width:150px">
      <option value="">همهٔ گروه‌ها</option>
      <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $gF === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= fa_num((int)$g['members']) ?>)</option><?php endforeach; ?>
    </select>
    <select class="fr-select sm" name="ch" style="max-width:140px">
      <option value="">همهٔ کانال‌ها</option>
      <option value="tg" <?= $chF === 'tg' ? 'selected' : '' ?>>متصل در تلگرام</option>
      <option value="bale" <?= $chF === 'bale' ? 'selected' : '' ?>>متصل در بله</option>
      <option value="none" <?= $chF === 'none' ? 'selected' : '' ?>>بدون اتصال</option>
    </select>
    <select class="fr-select sm" name="st" style="max-width:110px">
      <option value="">هر وضعیت</option>
      <option value="active" <?= $stF === 'active' ? 'selected' : '' ?>>فعال</option>
      <option value="blocked" <?= $stF === 'blocked' ? 'selected' : '' ?>>مسدود</option>
    </select>
    <select class="fr-select sm" name="per" style="max-width:95px" onchange="this.form.submit()">
      <?php foreach ([15, 30, 60, 120] as $pp): ?><option value="<?= $pp ?>" <?= $per === $pp ? 'selected' : '' ?>><?= fa_num($pp) ?>/صفحه</option><?php endforeach; ?>
    </select>
    <button class="fr-btn sm">فیلتر</button>
    <a class="fr-btn sm ghost" href="?v=list">پاک‌سازی</a>
  </form>

  <!-- نوار عملیات گروهی چسبنده -->
  <form method="post" id="ctBulk" class="ct-bulkbar" onsubmit="return ctBulkConfirm(event)">
    <?= csrf_field() ?><input type="hidden" name="act" value="bulk">
    <b id="ctBulkN">۰ انتخاب</b>
    <select name="op" id="ctOp">
      <option value="group_add">➕ افزودن به گروه…</option>
      <option value="group_del">➖ حذف از گروه…</option>
      <option value="block">🚫 مسدودسازی</option>
      <option value="activate">✅ فعال‌سازی</option>
      <option value="invite">📩 ارسال پیامک دعوت (start خودکار)</option>
      <option value="check">🔎 بررسی کانال‌ها</option>
      <option value="delete">🗑 حذف دائمی</option>
    </select>
    <select name="group_id" id="ctBulkG">
      <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="fr-btn sm">اجرا</button>
    <button type="button" class="fr-btn sm" onclick="document.querySelectorAll('.ct-chk').forEach(c=>c.checked=false);ctSyncSel()">✕ لغو انتخاب</button>
  </form>

  <div class="tbl-wrap"><table class="fr-table ct-cards">
    <thead><tr>
      <th style="width:34px"><input type="checkbox" id="ctAll" title="انتخاب همهٔ صفحه"></th>
      <th>نام</th><th>موبایل</th><th class="ct-hide-sm">تولد</th><th>گروه‌ها</th><th class="ct-hide-sm">کانال‌ها</th><th class="ct-hide-sm">منشأ</th><th>وضعیت</th><th style="width:170px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $c):
      $grs = $rowGroups[(int)$c['id']] ?? [];
      $bd = !empty($c['birth_date']) ? jdate('Y/m/d', strtotime((string)$c['birth_date'])) : '';
      $chipTg = trim((string)($c['tg_chat_id'] ?? '')) !== '' ? '<span class="chip" title="تلگرام: متصل — chat_id ' . e($c['tg_chat_id']) . '" style="background:#e7f0fb;color:#1e3a6e">🟦 ✅</span>'
                : (trim((string)($c['tg_username'] ?? '')) !== '' ? '<span class="chip" title="تلگرام: آیدی دارد، منتظر استارت">🟦 ⏳</span>' : '<span style="color:var(--muted)" title="تلگرام: —">🟦 —</span>');
      $chipBl = trim((string)($c['bale_chat_id'] ?? '')) !== '' ? '<span class="chip" title="بله: متصل — chat_id ' . e($c['bale_chat_id']) . '" style="background:#ecfbf4;color:#0f766e">🟩 ✅</span>'
                : (trim((string)($c['bale_username'] ?? '')) !== '' ? '<span class="chip" title="بله: آیدی دارد، منتظر استارت">🟩 ⏳</span>' : '<span style="color:var(--muted)" title="بله: —">🟩 —</span>');
      $srcFa = ['manual' => 'دستی', 'import' => 'اکسل', 'register' => '🌐 ثبت‌نام سایت'];
    ?>
      <tr>
        <td data-l="انتخاب"><input type="checkbox" class="ct-chk" name="ids[]" value="<?= (int)$c['id'] ?>"></td>
        <td data-l="نام"><b><?= e($c['name'] !== '' ? $c['name'] : '—') ?></b>
          <?php if ($grs): ?><div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">
            <?php foreach ($grs as $gr): ?><span class="ct-group-chip"><span class="dot" style="background:<?= e($gr['color']) ?>"></span><?= e($gr['name']) ?></span><?php endforeach; ?>
          </div><?php endif; ?></td>
        <td data-l="موبایل"><span dir="ltr"><?= e($c['mobile']) ?></span></td>
        <td data-l="تولد" class="ct-hide-sm"><?= $bd !== '' ? fa_num(en_num($bd)) : '<span style="color:var(--muted)">—</span>' ?></td>
        <td data-l="گروه" class="ct-hide-sm"><?= $c['segment'] !== '' ? '<span class="chip">' . e($c['segment']) . '</span>' : '<span style="color:var(--muted)">—</span>' ?></td>
        <td data-l="کانال‌ها" class="ct-hide-sm" style="white-space:nowrap"><?= $chipTg ?> <?= $chipBl ?></td>
        <td data-l="منشأ" class="ct-hide-sm"><small style="color:var(--muted)"><?= e($srcFa[$c['source']] ?? $c['source']) ?></small></td>
        <td data-l="وضعیت"><?= $c['status'] === 'active' ? '<span class="chip" style="background:#e3f7ef;color:#0c7a4d">فعال</span>' : '<span class="chip" style="background:#fde8e8;color:#b91c1c">مسدود</span>' ?></td>
        <td data-l="عملیات" style="white-space:nowrap">
          <a class="fr-btn sm ghost" href="?v=edit&id=<?= (int)$c['id'] ?>" title="ویرایش کامل">✏️</a>
          <form method="post" style="display:inline" title="ارسال پیامک دعوت با لینک start خودکار"><?= csrf_field() ?><input type="hidden" name="act" value="invite"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="fr-btn sm ghost">📩</button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="fr-btn sm ghost"><?= $c['status'] === 'active' ? '🚫' : '✅' ?></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('این مخاطب برای همیشه حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="fr-btn sm danger">✕</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:22px">مخاطبی با این فیلترها یافت نشد.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

  <!-- صفحه‌بندی حرفه‌ای -->
  <?php if ($pages > 1): ?>
  <div class="ct-pager">
    <?php if ($page > 1): ?><a class="fr-btn sm ghost" href="<?= e($query(['p' => $page - 1, 'v' => 'list'])) ?>">‹ قبلی</a><?php endif; ?>
    <?php
    $win = [];
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= 2) $win[] = $i;
    }
    $prev = 0;
    foreach ($win as $i):
        if ($prev && $i - $prev > 1): ?><span style="color:var(--muted)">…</span><?php endif;
        $prev = $i; ?>
      <a class="fr-btn sm <?= $i === $page ? '' : 'ghost' ?>" href="<?= e($query(['p' => $i, 'v' => 'list'])) ?>"><?= fa_num($i) ?></a>
    <?php endforeach; ?>
    <?php if ($page < $pages): ?><a class="fr-btn sm ghost" href="<?= e($query(['p' => $page + 1, 'v' => 'list'])) ?>">بعدی ›</a><?php endif; ?>
    <small style="color:var(--muted)">صفحهٔ <?= fa_num($page) ?> از <?= fa_num($pages) ?></small>
  </div>
  <?php endif; ?>
</div>

<div class="fr-card" style="background:linear-gradient(180deg,rgba(30,58,110,.04),#fff)">
  <h4 style="margin-top:0">💡 چطور کار می‌کند؟</h4>
  <ol style="font-size:12.5px;line-height:2.2;margin:0;padding-right:18px">
    <li><b>هر ثبت‌نام سایت</b> خودکار این‌جا مخاطب می‌شود (منشأ «ثبت‌نام سایت») و اتصال‌هایش هم میراث برداشته می‌شود.</li>
    <li>ثبت دستی/اکسل → <b>بررسی خودکار</b>: تطبیق با کاربران + getChat روی آیدی‌ها؛ chat_id را هم می‌توانی <b>دستی</b> وارد کنی.</li>
    <li><b>پیامک دعوت (📩)</b>: لینک دیپ‌لینک با کد اختصاصی مخاطب؛ با لمسش، استارت ربات <b>خودکار ثبت</b> و مخاطب متصل می‌شود.</li>
    <li>گروه‌ها را در <a href="?v=groups">🗂 مدیریت گروه‌ها</a> بساز و با چک‌باکسهای لیست، گروهی عضوگیری کن؛ کمپین را هم با <b>چند گروه هم‌زمان</b> هدف بگیر.</li>
    <li>محدودیت فنی صادقانه: هیچ رباتی پیش از استارت کاربر نمی‌تواند در تلگرام/بله پیام بدهد (سیاست خودِ پیام‌رسان‌هاست) — برای پوشش کامل از پیامک دعوت استفاده کن.</li>
  </ol>
</div>

<script>
/* انتخاب گروهی + نوار عملیات چسبنده */
function ctSyncSel(){
  var n = document.querySelectorAll('.ct-chk:checked').length;
  var bar = document.getElementById('ctBulk');
  bar.classList.toggle('on', n > 0);
  var fa = function(s){ return String(s).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  document.getElementById('ctBulkN').textContent = fa(n) + ' انتخاب';
}
document.querySelectorAll('.ct-chk').forEach(function(c){ c.addEventListener('change', ctSyncSel); });
var all = document.getElementById('ctAll');
if (all) all.addEventListener('change', function(){
  document.querySelectorAll('.ct-chk').forEach(function(c){ c.checked = all.checked; });
  ctSyncSel();
});
function ctBulkConfirm(e){
  var op = document.getElementById('ctOp').value;
  if (op === 'delete') return confirm('مخاطبین انتخاب‌شده برای همیشه حذف شوند؟');
  if (op === 'invite') return confirm('برای همهٔ انتخاب‌شده‌ها پیامک دعوت ارسال شود؟ (هزینهٔ پیامک دارد)');
  return true;
}
</script>
<?php endif; ?>
<?php admin_layout_end(); ?>
