<?php
/**
 * پیشخوان — کمپین‌های تبلیغاتی چندکاناله (نسخهٔ ۲٫۷)
 * ─ کانال‌ها تیکی: پیامک + تلگرام + بله (هر ترکیبی) • پیش‌نمایش زندهٔ دوگانه
 *   (حباب پیام‌رسان و حباب پیامک با شمارندهٔ جزء‌پیامک)
 * ─ کنترل کامل ارسال: حجم دسته، وقفهٔ بین پیام، زمان‌بندی شروع، توقف/ادامه،
 *   ویرایش متن پیش از شروع، تکثیر کمپین، ارسال مجدد ناموفق‌ها
 * ─ گزارش جامع: قیف ارسال (هدف→صف→ارسال→تحویل پیامک→کلیک→پاسخ)، CTR،
 *   تفکیک کانال، لینک‌های برتر، سری روزانه، خروجی CSV، تازه‌سازی تحویل پیامک
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/contacts.php';
require_once __DIR__ . '/../includes/campaigns.php';
$adm = require_admin(['superadmin','manager']);
csrf_guard();
if (!fr_admin_can($adm, 'newsletter')) { http_response_code(403); exit('عدم دسترسی'); }

$v  = (string)($_GET['v'] ?? 'list');
$id = (int)($_GET['id'] ?? 0);

/* ── اقدامات ── */
$act = (string)($_POST['act'] ?? '');

if ($act === 'create') {
    $cid = fr_campaign_create((string)($_POST['title'] ?? ''), (string)($_POST['body_html'] ?? ''),
                              (string)($_POST['channel'] ?? 'both'), (string)($_POST['audience'] ?? 'contacts'),
                              (string)($_POST['segment'] ?? ''), !empty($_POST['sms_invite']) ? 1 : 0, (int)$adm['id'], [
        'channels'     => (array)($_POST['channels'] ?? []),
        'batch_size'   => (int)($_POST['batch_size'] ?? 15),
        'delay_ms'     => (int)($_POST['delay_ms'] ?? 150),
        'scheduled_at' => (string)($_POST['scheduled_at'] ?? ''),
        'track_links'  => !empty($_POST['track_links']),
    ]);
    if ($cid) {
        log_activity('campaign_create', 'admin', (int)$adm['id'], ['id' => $cid]);
        flash('کمپین ساخته شد ✅ حالا «صف‌سازی گیرنده‌ها» را بزنید.');
        redirect(SITE_URL . '/admin/campaigns.php?v=detail&id=' . $cid);
    }
    flash('عنوان و متن پیام الزامی است.', 'err');
    redirect(SITE_URL . '/admin/campaigns.php?v=new');
}

/* ۲٫۷ — ویرایش کمپین */
if ($act === 'edit_save') {
    $cid = (int)($_POST['id'] ?? 0);
    $ok = fr_campaign_edit($cid, [
        'title' => (string)($_POST['title'] ?? ''), 'body' => (string)($_POST['body_html'] ?? ''),
        'audience' => (string)($_POST['audience'] ?? 'contacts'), 'segment' => (string)($_POST['segment'] ?? ''),
        'sms_invite' => !empty($_POST['sms_invite']), 'channels' => (array)($_POST['channels'] ?? []),
        'batch_size' => (int)($_POST['batch_size'] ?? 15), 'delay_ms' => (int)($_POST['delay_ms'] ?? 150),
        'scheduled_at' => (string)($_POST['scheduled_at'] ?? ''), 'track_links' => !empty($_POST['track_links']),
    ]);
    log_activity('campaign_edit', 'admin', (int)$adm['id'], ['id' => $cid, 'ok' => $ok]);
    flash($ok ? 'کمپین ویرایش شد ✅ — صف ارسال را از نو بسازید تا تغییرات اعمال شود.' : 'ویرایش ناموفق (کمپین شروع/تمام شده یا داده نامعتبر).', $ok ? 'ok' : 'err');
    redirect(SITE_URL . '/admin/campaigns.php?v=detail&id=' . $cid);
}

if (in_array($act, ['queue','pause','resume','del','resend_failed','send_now','duplicate','sync_delivery'], true)) {
    $cid = (int)($_POST['id'] ?? 0);
    $c = DB::one("SELECT * FROM " . DB::t('campaigns') . " WHERE id=?", [$cid]);
    if ($c) {
        if ($act === 'queue') {
            $r = fr_campaign_queue($cid);
            flash(!empty($r['ok'])
                ? 'صف ساخته شد 📮 ' . fa_num((int)$r['total']) . ' پیام در صف است (تلگرام: ' . fa_num((int)($r['channels']['telegram'] ?? 0))
                  . ' • بله: ' . fa_num((int)($r['channels']['bale'] ?? 0)) . ' • پیامک: ' . fa_num((int)($r['channels']['sms'] ?? 0)) . '، رد شده: ' . fa_num((int)($r['skipped'] ?? 0)) . ').'
                  . (($r['status'] ?? '') === 'scheduled' ? ' ⏰ زمان‌بندی‌شده — در موعد خودش شروع می‌شود.' : '')
                : ((string)($r['message'] ?? 'خطا')), !empty($r['ok']) ? 'ok' : 'err');
        }
        if ($act === 'pause')  { DB::update('campaigns', ['status' => 'paused'], 'id=?', [$cid]); flash('کمپین متوقف شد ⏸'); }
        if ($act === 'resume') { DB::update('campaigns', ['status' => 'queued'], 'id=?', [$cid]); flash('کمپین ادامه می‌یابد ▶️'); }
        if ($act === 'del')    { DB::run("DELETE FROM " . DB::t('campaign_links') . " WHERE campaign_id=?", [$cid]);
                                 DB::run("DELETE FROM " . DB::t('link_clicks') . " WHERE campaign_id=?", [$cid]);
                                 DB::run("DELETE FROM " . DB::t('campaigns') . " WHERE id=?", [$cid]); flash('کمپین و کل گزارشش حذف شد.'); redirect(SITE_URL . '/admin/campaigns.php'); }
        if ($act === 'duplicate') {
            $nid = fr_campaign_duplicate($cid, (int)$adm['id']);
            flash($nid ? 'کمپین تکثیر شد 📋 نسخهٔ پیش‌نویس تازه آمادهٔ ویرایش است.' : 'خطا در تکثیر.', $nid ? 'ok' : 'err');
            redirect(SITE_URL . '/admin/campaigns.php?v=detail&id=' . ($nid ?: $cid));
        }
        if ($act === 'resend_failed') {
            $n = (int)DB::run("UPDATE " . DB::t('campaign_messages') . " SET status='pending', error=NULL WHERE campaign_id=? AND status='failed'", [$cid])->rowCount();
            DB::update('campaigns', ['status' => 'queued'], 'id=?', [$cid]);
            flash(fa_num($n) . ' پیام ناموفق دوباره در صف قرار گرفت 🔁');
        }
        if ($act === 'send_now') {
            $n = fr_campaign_work(min(100, max(1, (int)($_POST['batch'] ?? (int)($c['batch_size'] ?? 15)))));
            flash(fa_num($n) . ' پیام پردازش شد — برای بقیه، کرون هر ۵ دقیقه ادامه می‌دهد یا دوباره بزنید.');
        }
        if ($act === 'sync_delivery') {
            $n = fr_campaign_sms_sync($cid);
            flash('استعلام تحویل پیامک‌ها از کاوه‌نگار انجام شد — ' . fa_num($n) . ' وضعیت به‌روزرسانی شد 🔄');
        }
        redirect(SITE_URL . '/admin/campaigns.php?v=detail&id=' . $cid);
    }
    redirect(SITE_URL . '/admin/campaigns.php');
}

$st = fr_campaign_stats();
$segments = array_filter(array_column(fr_contacts_segments(), 'segment'), fn($s) => $s !== '');
$groupNames = array_map(fn($g) => $g['name'], fr_contact_groups());
$segmentChoices = array_values(array_unique(array_merge($groupNames, $segments)));
$chanFa = ['telegram' => 'تلگرام', 'bale' => 'بله', 'sms' => 'پیامک'];
$audFa  = ['users' => 'کاربران سایت', 'contacts' => 'مخاطبین (دفترچه)', 'both' => 'هردو'];
$stFa   = ['draft' => ['پیش‌نویس', '#64748b'], 'edited' => ['ویرایش‌شده', '#64748b'], 'queued' => ['در صف', '#8a5a00'],
           'sending' => ['در حال ارسال', '#1e3a6e'], 'done' => ['پایان‌یافته', '#0c7a4d'], 'paused' => ['متوقف', '#b91c1c'],
           'scheduled' => ['⏰ زمان‌بندی‌شده', '#6d28d9']];
$mFa    = ['pending' => ['در انتظار', '#8a5a00'], 'sent' => ['ارسال شد ✅', '#0c7a4d'], 'failed' => ['ناموفق ❌', '#b91c1c'], 'skipped' => ['رد شده', '#64748b']];

admin_layout($adm, 'campaigns', '🎯 کمپین‌های تبلیغاتی');
echo flash();
?>
<style>
.cmp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px}
.cmp-stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px;text-align:center}
.cmp-stat b{display:block;font-size:20px}
.cmp-stat small{font-size:11px}
.rte-bar{display:flex;gap:4px;flex-wrap:wrap;background:var(--bg);border:1px solid var(--line);border-bottom:none;border-radius:12px 12px 0 0;padding:7px}
.rte-bar button{background:#fff;border:1px solid var(--line);border-radius:8px;min-width:32px;height:30px;cursor:pointer;font-size:13px;font-family:inherit}
.rte-bar button:hover{border-color:var(--c1)}
.rte-bar .split{width:1px;background:var(--line);margin:0 3px}
.rte-bar .ptn{background:#f4f6fa;color:#1e3a6e;font-size:11.5px;padding:0 8px}
#rteBox{min-height:150px;border:1px solid var(--line);border-top:none;border-radius:0 0 12px 12px;padding:12px;line-height:2;outline:none;background:#fff}
.rte-prev{background:#e7f0fb;border-radius:16px 16px 4px 16px;padding:12px 14px;line-height:2;font-size:13.5px;border:1px solid #cfdef3;min-height:70px;white-space:pre-wrap;word-break:break-word;position:relative}
.rte-prev .cap{font-size:10.5px;color:#7393bd;display:block;margin-top:6px}
.sms-prev{background:#f7f3e8;border-radius:14px;padding:12px 14px;line-height:2;font-size:13px;border:1px solid #e6d9ae;min-height:60px;white-space:pre-wrap;word-break:break-word;font-family:inherit}
.sms-prev .cap{font-size:10.5px;color:#a08b45;display:block;margin-top:6px}
.cmp-prog{height:8px;background:var(--bg);border-radius:99px;overflow:hidden;margin-top:6px}
.cmp-prog i{display:block;height:100%;background:linear-gradient(90deg,#1e3a6e,#3e5f9e);transition:width .4s}
.cmp-funnel{display:flex;gap:0;align-items:stretch;flex-wrap:nowrap;overflow-x:auto;margin:10px 0}
.cmp-fseg{flex:1;min-width:86px;text-align:center;padding:10px 6px;color:#fff;position:relative}
.cmp-fseg b{display:block;font-size:17px}
.cmp-fseg small{font-size:10.5px;opacity:.92}
.cmp-chanrow{display:flex;align-items:center;gap:8px;margin:6px 0}
.cmp-chanrow .bar{flex:1;height:10px;background:#eef2fa;border-radius:99px;overflow:hidden}
.cmp-chanrow .bar i{display:block;height:100%;border-radius:99px}
.ch-tog{display:inline-flex;align-items:center;gap:7px;border:1.5px solid var(--line);border-radius:12px;padding:8px 12px;cursor:pointer;font-size:13px;background:#fff}
.ch-tog input{accent-color:var(--c-primary)}
</style>

<div class="cmp-stats">
  <div class="cmp-stat"><b><?= fa_num($st['campaigns']) ?></b><small>کمپین</small></div>
  <div class="cmp-stat"><b style="color:#0c7a4d"><?= fa_num($st['sent']) ?></b><small>پیام ارسال‌شده</small></div>
  <div class="cmp-stat"><b style="color:#0f766e"><?= fa_num($st['delivered']) ?></b><small>تحویل‌شدهٔ پیامک</small></div>
  <div class="cmp-stat"><b style="color:#8a5a00"><?= fa_num($st['clicked']) ?></b><small>کلیک‌خورده</small></div>
  <div class="cmp-stat"><b style="color:#6d28d9"><?= fa_num($st['replied']) ?></b><small>پاسخ دریافتی</small></div>
  <div class="cmp-stat"><b style="color:#b91c1c"><?= fa_num($st['failed']) ?></b><small>ناموفق</small></div>
  <div class="cmp-stat"><b><?= fa_num($st['pending']) ?></b><small>در صف</small></div>
</div>

<?php if ($v === 'new'):
  $cRows = [
      'channels' => [], 'sms_invite' => 1, 'batch_size' => 15, 'delay_ms' => 150, 'scheduled_at' => '', 'track_links' => 1,
      'title' => '', 'audience' => 'contacts', 'segment' => '', 'body' => '',
  ];
  $formAct = 'create'; $formTitle = '🎯 کمپین جدید'; $formBtn = '💾 ساخت کمپین و ادامه';
  include __DIR__ . '/campaign-form.php';

elseif ($v === 'edit' && $id):
  $c = DB::one("SELECT * FROM " . DB::t('campaigns') . " WHERE id=?", [$id]);
  if (!$c) { echo '<div class="fr-alert err">کمپین پیدا نشد.</div>'; }
  elseif (!in_array($c['status'], ['draft','paused','scheduled','edited'], true)) { echo '<div class="fr-alert err">کمپینِ شروع/تمام‌شده قابل ویرایش نیست؛ ابتدا متوقفش کنید یا «تکثیر» بزنید.</div>'; }
  else {
      $cRows = $c; $cRows['channels'] = fr_campaign_channel_list($c);
      $formAct = 'edit_save'; $formTitle = '✏️ ویرایش کمپین «' . e($c['title']) . '»'; $formBtn = '💾 ذخیرهٔ ویرایش';
      include __DIR__ . '/campaign-form.php';
  }

elseif ($v === 'detail' && $id):
  $c = DB::one("SELECT * FROM " . DB::t('campaigns') . " WHERE id=?", [$id]);
  if (!$c) { echo '<div class="fr-alert err">کمپین پیدا نشد.</div>'; admin_layout_end(); exit; }
  $an = fr_campaign_analytics($id);
  $fSt = (string)($_GET['st'] ?? '');
  $w = "campaign_id=?"; $pp = [$id]; if (isset($mFa[$fSt])) { $w .= " AND status=?"; $pp[] = $fSt; }
  $msgs = DB::all("SELECT * FROM " . DB::t('campaign_messages') . " WHERE $w ORDER BY id DESC LIMIT 80", $pp);
  $doneP = $an['total'] > 0 ? (int)round($an['sent'] / $an['total'] * 100) : 0;
  $isEditable = in_array($c['status'], ['draft','paused','scheduled','edited'], true);

  /* خروجی CSV پیام‌ها */
  if (($_GET['export'] ?? '') === 'csv') {
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename=campaign-' . $id . '-' . date('Ymd-Hi') . '.csv');
      echo "\xEF\xBB\xBF" . "name,mobile,channel,status,error,sent_at,delivered_at,clicked_at,replied_at\n";
      foreach (DB::all("SELECT * FROM " . DB::t('campaign_messages') . " WHERE campaign_id=? ORDER BY id", [$id]) as $mm) {
          echo '"' . str_replace('"','""',$mm['name']) . '",' . $mm['mobile'] . ',"' . $mm['chat_id'] . '",' . $mm['channel'] . ',' . $mm['status'] . ',"'
             . str_replace('"','""',(string)$mm['error']) . '",' . ($mm['sent_at'] ?? '') . ',' . ($mm['delivered_at'] ?? '') . ',' . ($mm['clicked_at'] ?? '') . ',' . ($mm['replied_at'] ?? '') . "\n";
      }
      exit;
  }
?>
<!-- ═══════════ جزئیات و گزارش جامع کمپین ═══════════ -->
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
    <h3 style="margin:0">🎯 <?= e($c['title']) ?>
      <span class="chip" style="background:<?= $stFa[$c['status']][1] ?>22;color:<?= $stFa[$c['status']][1] ?>"><?= $stFa[$c['status']][0] ?></span></h3>
    <a class="fr-btn ghost sm" href="?v=list">↩ فهرست کمپین‌ها</a>
  </div>
  <p class="muted" style="font-size:12.5px;margin-top:0">
    کانال‌ها: <b><?= e(fr_campaign_channels_label($c)) ?></b> • مخاطب: <b><?= $audFa[$c['audience']] ?></b>
    <?= $c['segment'] !== '' ? '• گروه/برچسب: <b>' . e($c['segment']) . '</b>' : '' ?>
    • دستهٔ ارسال: <b><?= fa_num((int)($c['batch_size'] ?? 15)) ?></b> پیام با وقفهٔ <b><?= fa_num((int)($c['delay_ms'] ?? 150)) ?> م‌ث</b>
    <?= !empty($c['scheduled_at']) ? '• ⏰ شروع: <b>' . fa_num(jdate('Y/m/d H:i', strtotime((string)$c['scheduled_at']))) . '</b>' : '' ?>
    <?= (int)($c['track_links'] ?? 1) ? '• رهگیری لینک: <b>فعال</b>' : '• رهگیری لینک: خاموش' ?>
    <br>ساخته‌شده: <?= fa_num(jdate('Y/m/d H:i', strtotime($c['created_at']))) ?>
    <?= $c['finished_at'] ? ' • پایان: ' . fa_num(jdate('Y/m/d H:i', strtotime($c['finished_at']))) : '' ?>
  </p>

  <!-- قیف ارسال -->
  <div class="cmp-funnel" title="قیف ارسال">
    <div class="cmp-fseg" style="background:#334155"><b><?= fa_num($an['total']) ?></b><small>کل پیام‌های صف (هدف)</small></div>
    <div class="cmp-fseg" style="background:#8a5a00"><b><?= fa_num($an['pending']) ?></b><small>در انتظار</small></div>
    <div class="cmp-fseg" style="background:#1e3a6e"><b><?= fa_num($an['sent']) ?></b><small>ارسال‌شده (٪<?= fa_num($doneP) ?>)</small></div>
    <div class="cmp-fseg" style="background:#0f766e"><b><?= fa_num($an['delivered']) ?></b><small>تحویل‌شدهٔ پیامک</small></div>
    <div class="cmp-fseg" style="background:#8a5a00"><b><?= fa_num($an['clicked']) ?></b><small>کلیک‌خورده (CTR ٪<?= fa_num($an['ctr']) ?>)</small></div>
    <div class="cmp-fseg" style="background:#6d28d9"><b><?= fa_num($an['replied']) ?></b><small>پاسخ دریافتی</small></div>
    <div class="cmp-fseg" style="background:#b91c1c"><b><?= fa_num($an['failed']) ?></b><small>ناموفق</small></div>
  </div>
  <div class="cmp-prog"><i style="width:<?= $doneP ?>%"></i></div>
  <p class="hint" style="font-size:11px;margin:6px 0 0">
    📌 شفافیت فنی: «تحویل» فقط برای پیامک‌ها از استعلام کاوه‌نگار خوانده می‌شود؛ Bot API تلگرام/بله «خوانده‌شدن» را گزارش نمی‌دهد،
    بنابراین تعامل در پیام‌رسان با <b>کلیک روی لینک‌ها</b> و <b>پاسخ دریافتی به ربات</b> سنجیده می‌شود.
    مجموع کلیک‌ها روی همهٔ لینک‌ها: <b><?= fa_num($an['total_clicks']) ?></b> بار.
  </p>

  <!-- اکشن‌ها -->
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0;align-items:center">
    <?php if ($isEditable): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="queue"><input type="hidden" name="id" value="<?= $id ?>"><button class="fr-btn">📮 صف‌سازی گیرنده‌ها <?= $c['status']==='scheduled'||!empty($c['scheduled_at']) ? 'و زمان‌بندی' : 'و شروع' ?></button></form>
    <a class="fr-btn ghost" href="?v=edit&id=<?= $id ?>">✏️ ویرایش متن/تنظیمات</a>
    <?php endif; ?>
    <?php if (in_array($c['status'], ['queued','sending'], true)): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="pause"><input type="hidden" name="id" value="<?= $id ?>"><button class="fr-btn ghost">⏸ توقف</button></form>
    <form method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="act" value="send_now"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="fr-btn sm">⚡ ارسال دستی دستهٔ</button>
      <select class="fr-select sm" name="batch" style="max-width:80px">
        <?php foreach (array_unique([max(1,(int)($c['batch_size'] ?? 15)), 15, 30, 50, 100]) as $bb): ?><option <?= $bb === (int)($c['batch_size'] ?? 15) ? 'selected' : '' ?>><?= (int)$bb ?></option><?php endforeach; ?>
      </select></form>
    <?php endif; ?>
    <?php if ($c['status'] === 'paused'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="resume"><input type="hidden" name="id" value="<?= $id ?>"><button class="fr-btn">▶️ ادامه</button></form>
    <?php endif; ?>
    <?php if ($an['failed'] > 0 && !$isEditable): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="resend_failed"><input type="hidden" name="id" value="<?= $id ?>"><button class="fr-btn ghost">🔁 ارسال مجدد <b><?= fa_num($an['failed']) ?></b> ناموفق</button></form>
    <?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sync_delivery"><input type="hidden" name="id" value="<?= $id ?>"><button class="fr-btn ghost sm">🔄 تازه‌سازی تحویل پیامک</button></form>
    <a class="fr-btn ghost sm" href="?v=detail&id=<?= $id ?>&export=csv">⬇️ خروجی CSV گزارش</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="duplicate"><input type="hidden" name="id" value="<?= $id ?>"><button class="fr-btn ghost sm">📋 تکثیر</button></form>
    <form method="post" onsubmit="return confirm('کمپین، کل صف و گزارشش حذف شود؟')"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $id ?>"><button class="fr-btn danger ghost sm">🗑 حذف</button></form>
  </div>

  <!-- تفکیک کانال‌ها -->
  <h4 style="margin:14px 0 6px;font-size:13.5px">📡 تفکیک کانال‌ها</h4>
  <?php
  $chanCols = ['telegram' => '#2294c4', 'bale' => '#0f766e', 'sms' => '#8a5a00'];
  foreach ($an['by_channel'] as $bc):
      $cc = (string)$bc['channel'];
      $sent = (int)$bc['sent']; $tot = (int)$bc['total'];
      $pct = $tot ? (int)round($sent / $tot * 100) : 0; ?>
  <div class="cmp-chanrow">
    <span class="chip" style="min-width:64px;justify-content:center;background:<?= $chanCols[$cc] ?? '#64748b' ?>18;color:<?= $chanCols[$cc] ?? '#64748b' ?>"><?= $chanFa[$cc] ?? $cc ?></span>
    <div class="bar"><i style="width:<?= $pct ?>%;background:<?= $chanCols[$cc] ?? '#64748b' ?>"></i></div>
    <small style="min-width:220px;color:var(--muted)">
      <?= fa_num($sent) ?>/<?= fa_num($tot) ?> ارسال (٪<?= fa_num($pct) ?>) • ناموفق <?= fa_num((int)$bc['failed']) ?>
      <?= (int)$bc['delivered'] > 0 ? ' • تحویل ' . fa_num((int)$bc['delivered']) : '' ?>
      <?= (int)$bc['clicked'] > 0 ? ' • کلیک ' . fa_num((int)$bc['clicked']) : '' ?>
      <?= (int)$bc['replied'] > 0 ? ' • پاسخ ' . fa_num((int)$bc['replied']) : '' ?>
    </small>
  </div>
  <?php endforeach; ?>
  <?php if (!$an['by_channel']): ?><p class="muted" style="font-size:12.5px">هنوز پیامی صف نشده است.</p><?php endif; ?>

  <!-- سری ۱۴ روز -->
  <?php if ($an['daily'] || $an['clicks_daily']):
      $days = [];
      for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $days[$d] = ['sent' => 0, 'click' => 0]; }
      foreach ($an['daily'] as $rr) $days[$rr['d']]['sent'] = (int)$rr['n'];
      foreach ($an['clicks_daily'] as $rr) $days[$rr['d']]['click'] = (int)$rr['n'];
      $mx = max(1, max(array_map(fn($x) => max($x['sent'], $x['click']), $days))); ?>
  <h4 style="margin:16px 0 6px;font-size:13.5px">📈 سری روزانهٔ ارسال و کلیک (۱۴ روز اخیر)</h4>
  <div style="display:flex;gap:4px;align-items:flex-end;height:110px;border-bottom:1.5px solid var(--line);padding-bottom:2px">
    <?php foreach ($days as $d => $vv): ?>
    <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;gap:2px" title="<?= fa_num(jdate('j/n', strtotime($d))) ?> — ارسال: <?= fa_num($vv['sent']) ?> • کلیک: <?= fa_num($vv['click']) ?>">
      <i style="display:block;background:#1e3a6e;border-radius:3px 3px 0 0;height:<?= (int)round($vv['sent'] / $mx * 90) ?>px;min-height:<?= $vv['sent'] ? 3 : 0 ?>px"></i>
      <i style="display:block;background:#c99f4a;border-radius:3px;height:<?= (int)round($vv['click'] / $mx * 90) ?>px;min-height:<?= $vv['click'] ? 3 : 0 ?>px;opacity:.85"></i>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--muted);margin-top:4px">
    <span><?= fa_num(jdate('j/n', strtotime(array_key_first($days)))) ?></span>
    <span>■ ارسال &nbsp; <span style="color:#c99f4a">■ کلیک</span></span>
    <span><?= fa_num(jdate('j/n', strtotime(array_key_last($days)))) ?></span>
  </div>
  <?php endif; ?>

  <!-- لینک‌های برتر -->
  <?php if ($an['top_links']): ?>
  <h4 style="margin:16px 0 6px;font-size:13.5px">🔗 لینک‌های برتر کمپین</h4>
  <div class="tbl-wrap"><table class="fr-table" style="font-size:12px">
    <tr><th>نشانی</th><th>کلیک</th><th>در چند پیام</th></tr>
    <?php foreach ($an['top_links'] as $tl): ?>
    <tr><td dir="ltr" style="text-align:left;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><a href="<?= e($tl['url']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($tl['url'], 0, 80, '…')) ?></a></td>
        <td><b><?= fa_num((int)$tl['clicks']) ?></b></td><td><?= fa_num((int)$tl['msgs']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <details style="margin-top:14px"><summary style="cursor:pointer;font-weight:700;font-size:13px">👁 متن ارسالی کمپین</summary>
    <div class="rte-prev" style="margin-top:8px"><?= preg_replace('#\n#', '<br>', (string)$c['body']) ?></div>
  </details>

  <div style="border-top:1px dashed var(--line);margin:16px 0"></div>
  <h4 style="margin:0 0 6px;font-size:13.5px">📨 صندوق پیام‌ها</h4>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
    <a class="fr-btn sm <?= $fSt === '' ? '' : 'ghost' ?>" href="?v=detail&id=<?= $id ?>">همه</a>
    <?php foreach ($mFa as $k => [$lbl, $col]): ?>
    <a class="fr-btn sm <?= $fSt === $k ? '' : 'ghost' ?>" href="?v=detail&id=<?= $id ?>&st=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <div class="tbl-wrap"><table class="fr-table">
    <thead><tr><th>گیرنده</th><th>موبایل/چت</th><th>کانال</th><th>وضعیت</th><th>تحویل</th><th>کلیک</th><th>پاسخ</th><th>خطا</th><th>ارسال</th></tr></thead>
    <tbody>
    <?php foreach ($msgs as $mRow): ?>
      <tr>
        <td><b><?= e($mRow['name'] !== '' ? $mRow['name'] : '—') ?></b></td>
        <td dir="ltr" style="font-size:11.5px"><?= e($mRow['mobile'] ?: $mRow['chat_id']) ?></td>
        <td><span class="chip"><?= e($chanFa[$mRow['channel']] ?? $mRow['channel']) ?></span></td>
        <td style="color:<?= $mFa[$mRow['status']][1] ?>"><?= $mFa[$mRow['status']][0] ?></td>
        <td style="font-size:11.5px"><?= $mRow['delivered_at'] ? '<span style="color:#0f766e">✅ '.fa_num(jdate('j/n H:i', strtotime($mRow['delivered_at']))).'</span>' : '<span style="color:var(--muted)">—</span>' ?></td>
        <td style="font-size:11.5px"><?= $mRow['clicked_at'] ? '<span style="color:#8a5a00">🖱 '.fa_num(jdate('j/n H:i', strtotime($mRow['clicked_at']))).'</span>' : '<span style="color:var(--muted)">—</span>' ?></td>
        <td style="font-size:11.5px"><?= $mRow['replied_at'] ? '<span style="color:#6d28d9">💬 '.fa_num(jdate('j/n H:i', strtotime($mRow['replied_at']))).'</span>' : '<span style="color:var(--muted)">—</span>' ?></td>
        <td style="font-size:11px;color:var(--muted);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr((string)($mRow['error'] ?? ''), 0, 45)) ?></td>
        <td style="font-size:11.5px;white-space:nowrap"><?= $mRow['sent_at'] ? fa_num(jdate('j/n H:i', strtotime($mRow['sent_at']))) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$msgs): ?><tr><td colspan="9" style="text-align:center;color:var(--muted);padding:20px">پیامی در این وضعیت نیست — ابتدا «صف‌سازی گیرنده‌ها» را بزنید.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php else:
  $rows = DB::all("SELECT * FROM " . DB::t('campaigns') . " ORDER BY id DESC LIMIT 40");
?>
<!-- ═══════════ فهرست کمپین‌ها ═══════════ -->
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
    <h3 style="margin:0">🎯 کمپین‌های تبلیغاتی <small style="color:var(--muted)">(پیامک / تلگرام / بله)</small></h3>
    <a class="fr-btn" href="?v=new">➕ کمپین جدید</a>
  </div>
  <?php if ($st['active'] > 0 || $st['pending'] > 0): ?>
  <p class="muted" style="font-size:12.5px">⚙️ <?= fa_num($st['pending']) ?> پیام در صف است؛ کرون <code>cron/notify.php</code> هر ۵ دقیقه به‌صورت خودکار ارسال می‌کند و تحویل پیامک‌ها را هم تازه می‌سازد (راهنما: <a href="<?= SITE_URL ?>/admin/notifications.php#cron">اطلاع‌رسانی اجتماعی ← کرون</a>).</p>
  <?php endif; ?>
  <div class="tbl-wrap"><table class="fr-table">
    <thead><tr><th>#</th><th>عنوان</th><th>کانال‌ها</th><th>مخاطب</th><th>وضعیت</th><th>پیشرفت / تعامل</th><th>ساخت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $pct = (int)$r['total'] > 0 ? (int)round((int)$r['sent'] / (int)$r['total'] * 100) : 0;
      $ix = DB::one("SELECT SUM(clicked_at IS NOT NULL) AS ck, SUM(delivered_at IS NOT NULL) AS dv, SUM(replied_at IS NOT NULL) AS rp
                     FROM " . DB::t('campaign_messages') . " WHERE campaign_id=? AND status='sent'", [(int)$r['id']]); ?>
      <tr>
        <td><?= fa_num((int)$r['id']) ?></td>
        <td><b><?= e($r['title']) ?></b>
            <?= !empty($r['scheduled_at']) && $r['status'] === 'scheduled' ? '<br><small style="color:#6d28d9">⏰ ' . fa_num(jdate('Y/m/d H:i', strtotime($r['scheduled_at']))) . '</small>' : '' ?></td>
        <td><span class="chip"><?= e(fr_campaign_channels_label($r)) ?></span></td>
        <td style="font-size:12px"><?= $audFa[$r['audience']] ?><?= $r['segment'] !== '' ? ' (' . e($r['segment']) . ')' : '' ?></td>
        <td><span class="chip" style="background:<?= $stFa[$r['status']][1] ?>22;color:<?= $stFa[$r['status']][1] ?>"><?= $stFa[$r['status']][0] ?></span></td>
        <td style="min-width:170px">
          <small><?= fa_num((int)$r['sent']) ?>/<?= fa_num((int)$r['total']) ?><?= (int)$r['failed'] ? ' ❌' . fa_num((int)$r['failed']) : '' ?>
            <?= (int)($ix['dv'] ?? 0) ? ' 📬' . fa_num((int)$ix['dv']) : '' ?><?= (int)($ix['ck'] ?? 0) ? ' 🖱' . fa_num((int)$ix['ck']) : '' ?><?= (int)($ix['rp'] ?? 0) ? ' 💬' . fa_num((int)$ix['rp']) : '' ?></small>
          <div class="cmp-prog"><i style="width:<?= $pct ?>%"></i></div>
        </td>
        <td style="font-size:11.5px"><?= fa_num(jdate('j/n H:i', strtotime($r['created_at']))) ?></td>
        <td><a class="fr-btn sm ghost" href="?v=detail&id=<?= (int)$r['id'] ?>">مدیریت و گزارش ◀</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:26px">هنوز کمپینی نیست — با «کمپین جدید» اولین اطلاع‌رسانی چندکاناله‌ات را بساز؛ مخاطبین را هم می‌توانی از <a href="<?= SITE_URL ?>/admin/contacts.php">📇 مخاطبین</a> وارد کنی.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php admin_layout_end(); ?>
