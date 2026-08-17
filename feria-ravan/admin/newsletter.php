<?php
/**
 * مدیریت خبرنامهٔ پیامکی — نسخهٔ ۲٫۷ (مدیریت کامل شماره‌ها)
 * ─ ویرایش تکی (نام/موبایل/وضعیت) • حذف • انتخاب چندتایی + عملیات گروهی
 *   (حذف گروهی/فعال/لغو عضویت) • صفحه‌بندی حرفه‌ای • جستجو • خروجی CSV
 * ─ ارسال گروهی متن دلخواه یا «پترن کاوه‌نگار» (مانند قبل)
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/contacts.php';   // ۲٫۷ — نرمال‌سازی موبایل
$adm = require_admin(['superadmin','manager']);
csrf_guard();

$act = (string)($_POST['act'] ?? '');
$q   = trim((string)($_GET['q'] ?? ''));
$flt = (string)($_GET['flt'] ?? 'active');
$backQ = http_build_query(array_filter(['flt' => $flt, 'q' => $q]));

/* ── اقدامات ── */
/* ۲٫۸: تنظیمات صفحهٔ عمومی عضویت (فعال/غیرفعال + متن خوش‌آمد) */
if ($act === 'page_settings') {
    DB::setSetting('newsletter_page_enabled', !empty($_POST['enabled']) ? '1' : '0');
    DB::setSetting('newsletter_page_intro', mb_substr(trim((string)($_POST['intro'] ?? '')), 0, 2000));
    flash('تنظیمات صفحهٔ عمومی خبرنامه ذخیره شد ✅');
    redirect(SITE_URL . '/admin/newsletter.php');
}

if ($act === 'send') {
    $tokens   = array_map('trim', array_filter(array_map('strval', (array)($_POST['tokens'] ?? []))));
    $pattern  = mb_substr(trim((string)($_POST['pattern_code'] ?? '')), 0, 60);
    $message  = trim((string)($_POST['message'] ?? ''));
    $selected = array_map('intval', (array)($_POST['ids'] ?? []));
    $toAll    = !empty($_POST['to_all']);

    if (!$toAll && !$selected) { flash('هیچ شماره‌ای انتخاب نشده است.', 'err'); redirect(SITE_URL . '/admin/newsletter.php?' . $backQ); }
    if ($pattern === '' && $message === '') { flash('متن پیام یا کد پترن را بنویسید.', 'err'); redirect(SITE_URL . '/admin/newsletter.php?' . $backQ); }

    $subs = $toAll
        ? DB::all("SELECT * FROM " . DB::t('newsletter') . " WHERE status='active'")
        : DB::all("SELECT * FROM " . DB::t('newsletter') . " WHERE status='active' AND id IN (" . implode(',', array_fill(0, count($selected), '?')) . ")", $selected);

    $kv = new Kavenegar();
    $sent = 0; $failed = 0;
    foreach ($subs as $s) {
        if ($pattern !== '') {
            $vars = $tokens;
            if (count($vars) < 5 && trim((string)$s['name']) !== '') $vars[] = trim((string)$s['name']);
            $ok = $kv->sendPattern((string)$s['mobile'], $pattern, $vars, 'newsletter');
        } else {
            $ok = $kv->send((string)$s['mobile'], $message, 'newsletter');
        }
        $ok ? $sent++ : $failed++;
    }
    log_activity('newsletter_send', 'admin', (int)$adm['id'], ['count' => count($subs), 'sent' => $sent, 'failed' => $failed, 'pattern' => $pattern]);
    flash('ارسال گروهی انجام شد — موفق: ' . fa_num($sent) . '، ناموفق: ' . fa_num($failed) . ($failed ? ' (جزئیات در رصد فعالیت/پیامک)' : '') . ($sent || $failed ? ' ✅' : ''), $failed ? 'err' : 'ok');
    redirect(SITE_URL . '/admin/newsletter.php?' . $backQ);
}

if ($act === 'add') {
    $mob = en_num(trim((string)($_POST['mobile'] ?? '')));
    $nm  = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    if (!valid_mobile($mob)) { flash('موبایل معتبر نیست.', 'err'); redirect(SITE_URL . '/admin/newsletter.php'); }
    $has = DB::val("SELECT id FROM " . DB::t('newsletter') . " WHERE mobile=?", [$mob]);
    if ($has) {
        DB::run("UPDATE " . DB::t('newsletter') . " SET status='active', unsub_at=NULL, name=COALESCE(NULLIF(?,''),name) WHERE id=?", [$nm, (int)$has]);
        flash('این شماره بود — دوباره فعال شد ✅');
    } else {
        DB::insert('newsletter', ['mobile'=>$mob, 'name'=>$nm !== '' ? $nm : null, 'status'=>'active', 'source'=>'admin']);
        flash('مشترک جدید اضافه شد ✅');
    }
    redirect(SITE_URL . '/admin/newsletter.php');
}

/* ۲٫۷ — ویرایش تکی مشترک */
if ($act === 'edit') {
    $id  = (int)($_POST['id'] ?? 0);
    $mob = en_num(trim((string)($_POST['mobile'] ?? '')));
    $nm  = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    $st  = in_array(($_POST['status'] ?? ''), ['active','unsub'], true) ? (string)$_POST['status'] : 'active';
    if (!valid_mobile($mob)) { flash('موبایل معتبر نیست.', 'err'); redirect(SITE_URL . '/admin/newsletter.php'); }
    $dup = (int)DB::val("SELECT id FROM " . DB::t('newsletter') . " WHERE mobile=? AND id<>? LIMIT 1", [$mob, $id]);
    if ($dup > 0) { flash('این شماره قبلاً برای مشترک دیگری ثبت شده.', 'err'); redirect(SITE_URL . '/admin/newsletter.php'); }
    DB::run("UPDATE " . DB::t('newsletter') . " SET mobile=?, name=?, status=?, unsub_at=IF(?='unsub', COALESCE(unsub_at,NOW()), NULL) WHERE id=?",
            [$mob, $nm !== '' ? $nm : null, $st, $st, $id]);
    log_activity('newsletter_edit', 'admin', (int)$adm['id'], ['id' => $id, 'mobile' => $mob]);
    flash('مشترک به‌روزرسانی شد ✅');
    redirect(SITE_URL . '/admin/newsletter.php?' . $backQ);
}

if ($act === 'del') {
    DB::run("DELETE FROM " . DB::t('newsletter') . " WHERE id=?", [(int)($_POST['id'] ?? 0)]);
    flash('مشترک حذف شد.');
    redirect(SITE_URL . '/admin/newsletter.php?' . $backQ);
}

/* ۲٫۷ — عملیات گروهی روی انتخاب‌شده‌ها */
if ($act === 'bulk') {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $op  = (string)($_POST['op'] ?? '');
    $n = 0;
    if ($ids) {
        $in = implode(',', $ids);
        if ($op === 'delete')        $n = (int)DB::run("DELETE FROM " . DB::t('newsletter') . " WHERE id IN ($in)")->rowCount();
        elseif ($op === 'activate')  $n = (int)DB::run("UPDATE " . DB::t('newsletter') . " SET status='active', unsub_at=NULL WHERE id IN ($in)")->rowCount();
        elseif ($op === 'unsub')     $n = (int)DB::run("UPDATE " . DB::t('newsletter') . " SET status='unsub', unsub_at=NOW() WHERE id IN ($in)")->rowCount();
        log_activity('newsletter_bulk', 'admin', (int)$adm['id'], ['op' => $op, 'count' => count($ids), 'done' => $n]);
        flash('عملیات گروهی انجام شد — ' . fa_num($n) . ' شماره ✅');
    } else flash('هیچ شماره‌ای انتخاب نشده است.', 'err');
    redirect(SITE_URL . '/admin/newsletter.php?' . $backQ);
}

/* ۲٫۷ — خروجی CSV */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=newsletter-' . date('Ymd-Hi') . '.csv');
    echo "\xEF\xBB\xBF" . "mobile,name,status,source,created_at,unsub_at\n";
    foreach (DB::all("SELECT * FROM " . DB::t('newsletter') . " ORDER BY id DESC") as $s) {
        echo $s['mobile'] . ',"' . str_replace('"', '""', (string)$s['name']) . '",' . $s['status'] . ',' . $s['source'] . ','
           . $s['created_at'] . ',' . ($s['unsub_at'] ?? '') . "\n";
    }
    exit;
}

/* ── داده با صفحه‌بندی حرفه‌ای ── */
$where = "1"; $args = [];
if ($flt === 'active') { $where .= " AND status='active'"; }
elseif ($flt === 'unsub') { $where .= " AND status='unsub'"; }
if ($q !== '') { $where .= " AND (mobile LIKE ? OR name LIKE ?)"; $args[] = '%'.en_num($q).'%'; $args[] = '%'.$q.'%'; }
$per  = (int)($_GET['per'] ?? 20); $per = in_array($per, [20, 50, 100], true) ? $per : 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$total = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('newsletter') . " WHERE $where", $args);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$list  = DB::all("SELECT * FROM " . DB::t('newsletter') . " WHERE $where ORDER BY id DESC LIMIT " . (($page - 1) * $per) . ", $per", $args);
$cActive = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('newsletter') . " WHERE status='active'");
$cUnsub  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('newsletter') . " WHERE status='unsub'");
$nq = fn(array $over = []) => '?' . http_build_query(array_merge(['flt' => $flt, 'q' => $q, 'per' => $per], $over));

admin_layout($adm, 'newsletter', 'خبرنامهٔ پیامکی');
echo flash();
?>
<style>
.nl-bulkbar{position:sticky;top:10px;z-index:30;background:linear-gradient(135deg,#122850,#1e3a6e);color:#fff;border-radius:14px;padding:9px 12px;margin:0 0 12px;display:none;gap:8px;align-items:center;flex-wrap:wrap;box-shadow:0 10px 24px rgba(18,40,80,.25)}
.nl-bulkbar.on{display:flex}
.nl-bulkbar .fr-btn.sm{background:#ffffff22;color:#fff;border:1px solid #ffffff44}
.nl-bulkbar select{background:#ffffff16;color:#fff;border:1px solid #ffffff33;border-radius:9px;padding:5px 8px;font-family:inherit}
.nl-bulkbar select option{color:#122850}
.nl-pager{display:flex;gap:6px;flex-wrap:wrap;align-items:center;justify-content:center;margin-top:12px}
</style>

<?php
/* ۲٫۸: صفحهٔ عمومی عضویت در خبرنامه — لینک مجزا برای مخاطبان */
$nlPageUrl  = SITE_URL . '/newsletter.php';
$nlPageOn   = (string)DB::setting('newsletter_page_enabled', '1') === '1';
$nlPageIn   = (string)DB::setting('newsletter_page_intro', '');
?>
<div class="fr-card" style="margin-bottom:16px;border:1.5px solid #c9bcf5;background:linear-gradient(135deg,#faf8ff,#f3f0ff)">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h3 style="margin:0">🔗 صفحهٔ عمومی عضویت در خبرنامه <span class="fr-chip <?= $nlPageOn ? 'ok' : 'warn' ?>"><?= $nlPageOn ? 'فعال' : 'غیرفعال' ?></span></h3>
    <a class="fr-btn sm ghost" href="<?= e($nlPageUrl) ?>" target="_blank" rel="noopener">🌐 مشاهدهٔ صفحه</a>
  </div>
  <p style="font-size:12.5px;color:var(--muted);line-height:2;margin:8px 0 10px">
    این لینک را در شبکه‌های اجتماعی، بیو اینستاگرام یا کمپین‌ها منتشر کن؛ مخاطبان بدون ورود به سایت، نام و موبایل و آیدی تلگرام/بلهٔ خود را ثبت می‌کنند — هم در خبرنامه عضو می‌شوند و هم به «مخاطبین» (با برچسب خبرنامه) اضافه شده و پیامک دعوت برای استارت ربات بله و تلگرام دریافت می‌کنند تا اطلاعیه‌ها مستقیم در اکانتشان برسد.
  </p>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <code id="nlPageUrl" style="direction:ltr;background:#fff;border:1.5px solid #d4dcec;border-radius:10px;padding:8px 12px;font-size:13px;user-select:all"><?= e($nlPageUrl) ?></code>
    <button type="button" class="fr-btn sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('nlPageUrl').textContent).then(()=>{this.textContent='✅ کپی شد';setTimeout(()=>this.textContent='📋 کپی لینک',1500)})">📋 کپی لینک</button>
  </div>
  <form method="post" style="display:flex;gap:14px;align-items:end;flex-wrap:wrap;margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="act" value="page_settings">
    <label class="tog" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="enabled" value="1" <?= $nlPageOn ? 'checked' : '' ?>> صفحهٔ عمومی فعال باشد</label>
    <div class="fr-field" style="margin:0;flex:1;min-width:260px"><label style="font-size:12px">متن خوش‌آمد بالای فرم (اختیاری — HTML ساده)</label>
      <input class="fr-input" name="intro" value="<?= e($nlPageIn) ?>" maxlength="2000" placeholder="مثلاً: برای دریافت تخفیف‌های ویژه عضو شوید…"></div>
    <button class="fr-btn sm">💾 ذخیره</button>
  </form>
</div>

<div class="fr-grid" style="grid-template-columns:1.05fr .95fr;gap:16px;align-items:start">

  <!-- ═══ ارسال گروهی ═══ -->
  <div class="fr-card" style="border:1.5px solid #d4dcec;background:linear-gradient(180deg,#fafbfd,#fff);position:sticky;top:12px">
    <h3>📢 ارسال پیامک به خبرنامه</h3>
    <p style="font-size:12.5px;color:var(--muted);margin-top:0;line-height:2">
      گیرنده‌ها را با چک‌مارک از جدول انتخاب کن (یا «همهٔ مشترک‌های فعال»). دو راه ارسال داری:
      <b>متن دلخواه</b> (ارسال سادهٔ کاوه‌نگار) یا <b>پترن</b> (قالب تاییدشده — مناسب خطوط خدماتی).
    </p>
    <form method="post" id="nlSendForm">
      <?= csrf_field() ?><input type="hidden" name="act" value="send">
      <label class="tog" style="margin-bottom:10px;display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="to_all" id="nlAll"> <b>ارسال به همهٔ مشترک‌های فعال</b> (<?= fa_num($cActive) ?> نفر)
      </label>
      <div id="nlIds"></div>

      <div class="fr-field"><label>۱) متن دلخواه پیامک</label>
        <textarea class="fr-input" name="message" rows="4" maxlength="900" id="nlMsg"
          placeholder="مثال: سلام! کارگاه جدید «مدیریت اضطراب» با ظرفیت محدود باز شد…"></textarea>
        <span style="font-size:11px;color:var(--muted)" id="nlCount">۰ کاراکتر — هر پیامک ≈ ۷۰ کاراکتر فارسی</span></div>

      <div style="display:flex;align-items:center;gap:8px;margin:10px 0"><span style="flex:1;border-top:1.5px dashed #e6dffa"></span><small style="color:var(--muted)">یا</small><span style="flex:1;border-top:1.5px dashed #e6dffa"></span></div>

      <div class="fr-grid cols-2">
        <div class="fr-field"><label>۲) کد پترن کاوه‌نگار (اختیاری — جایگزین متن می‌شود)</label>
          <input class="fr-input" name="pattern_code" style="direction:ltr" placeholder="newsletter-offer" maxlength="60"></div>
        <div class="fr-field"><label>متغیرهای پترن (هر خط یک توکن: token، token2، …)</label>
          <textarea class="fr-input" name="tokens_txt" rows="2" style="direction:ltr" oninput="
            this.form.querySelectorAll('input[name=\'tokens[]\']').forEach(i=>i.remove());
            this.value.split('\n').filter(t=>t.trim()!=='').forEach(t=>{const i=document.createElement('input');i.type='hidden';i.name='tokens[]';i.value=t.trim();this.form.appendChild(i);});
          "></textarea></div>
      </div>
      <button class="fr-btn" onclick="return confirm('ارسال گروهی انجام شود؟ هزینهٔ پیامک از پنل کاوه‌نگار کم می‌شود.')">🚀 ارسال گروهی</button>
      <small class="hint" style="display:block;margin-top:8px">اگر هم متن و هم پترن پر باشند، <b>پترن</b> ارسال می‌شود. گزارش هر ارسال در «رصد فعالیت ← پیامک» دیده می‌شود.</small>
    </form>

    <div style="border-top:1.5px dashed var(--line);margin:14px 0"></div>
    <h4 style="margin:0 0 8px">افزودن دستی مشترک</h4>
    <form method="post" class="fr-grid" style="grid-template-columns:1fr 1fr auto;gap:10px;align-items:end">
      <?= csrf_field() ?><input type="hidden" name="act" value="add">
      <div class="fr-field" style="margin:0"><input class="fr-input" name="mobile" dir="ltr" placeholder="0912…" maxlength="11" required></div>
      <div class="fr-field" style="margin:0"><input class="fr-input" name="name" placeholder="نام (اختیاری)" maxlength="150"></div>
      <button class="fr-btn">➕ افزودن</button>
    </form>
  </div>

  <!-- ═══ فهرست مشترک‌ها (مدیریت کامل) ═══ -->
  <div>
    <div class="fr-card" style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <h3 style="margin:0">مشترک‌های خبرنامه <small style="color:var(--muted)">(نمایش <?= fa_num(min($per, $total)) ?> از <?= fa_num($total) ?>)</small></h3>
        <a class="fr-btn ghost sm" href="?export=csv">⬇️ خروجی CSV</a>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <a class="fr-btn sm <?= $flt==='active'?'':'ghost' ?>" href="?flt=active">فعال (<?= fa_num($cActive) ?>)</a>
        <a class="fr-btn sm <?= $flt==='unsub'?'':'ghost' ?>" href="?flt=unsub">لغوشده (<?= fa_num($cUnsub) ?>)</a>
        <a class="fr-btn sm <?= $flt==='all'?'':'ghost' ?>" href="?flt=all">همه</a>
        <form method="get" style="display:flex;gap:6px;margin-inline-start:auto;flex-wrap:wrap">
          <input type="hidden" name="flt" value="<?= e($flt) ?>">
          <input class="fr-input sm" name="q" value="<?= e($q) ?>" placeholder="جستجوی موبایل یا نام…" style="max-width:160px">
          <select class="fr-select sm" name="per" style="max-width:90px" onchange="this.form.submit()">
            <?php foreach ([20, 50, 100] as $pp): ?><option value="<?= $pp ?>" <?= $per === $pp ? 'selected' : '' ?>><?= fa_num($pp) ?>/صفحه</option><?php endforeach; ?>
          </select>
          <button class="fr-btn sm ghost">🔎</button>
        </form>
      </div>

      <!-- نوار عملیات گروهی -->
      <form method="post" id="nlBulk" class="nl-bulkbar" onsubmit="return document.getElementById('nlBulkOp').value!=='delete'||confirm('شماره‌های انتخاب‌شده حذف شوند؟')">
        <?= csrf_field() ?><input type="hidden" name="act" value="bulk">
        <b id="nlBulkN">۰ انتخاب</b>
        <select name="op" id="nlBulkOp">
          <option value="activate">✅ فعال‌سازی عضویت</option>
          <option value="unsub">🔕 لغو عضویت</option>
          <option value="delete">🗑 حذف دائمی</option>
        </select>
        <button class="fr-btn sm">اجرا</button>
      </form>

      <div style="max-height:470px;overflow:auto;border:1px solid var(--line);border-radius:12px">
        <table class="fr-table" style="margin:0">
          <thead><tr>
            <th style="width:34px"><input type="checkbox" id="nlAllChk" title="انتخاب همهٔ صفحه"></th>
            <th>موبایل</th><th>نام</th><th>وضعیت</th><th>عضویت</th><th style="width:120px"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($list as $s): ?>
          <tr>
            <td><input type="checkbox" class="nl-chk" value="<?= (int)$s['id'] ?>"></td>
            <td dir="ltr" style="text-align:right"><?= fa_num($s['mobile']) ?></td>
            <td><?= e($s['name'] ?? '—') ?></td>
            <td><?= $s['status']==='active' ? '<span style="color:var(--c-ok);font-size:12px">فعال</span>' : '<span style="color:var(--muted);font-size:12px">لغوشده</span>' ?></td>
            <td style="font-size:11.5px;color:var(--muted)"><?= fa_num(jdate('y/m/d', strtotime($s['created_at']))) ?></td>
            <td style="white-space:nowrap">
              <a class="fr-btn sm ghost" href="<?= e(SITE_URL) ?>/admin/newsletter.php?v=edit&id=<?= (int)$s['id'] ?>&<?= e($backQ) ?>" title="ویرایش">✏️</a>
              <form method="post" style="display:inline" onsubmit="return confirm('این شماره حذف شود؟')">
                <?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="fr-btn sm danger" title="حذف">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$list): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:22px">مشترکی پیدا نشد.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1): ?>
      <div class="nl-pager">
        <?php if ($page > 1): ?><a class="fr-btn sm ghost" href="<?= e($nq(['p' => $page - 1])) ?>">‹ قبلی</a><?php endif; ?>
        <?php
        $prev = 0;
        for ($i = 1; $i <= $pages; $i++):
            if (!($i === 1 || $i === $pages || abs($i - $page) <= 2)) continue;
            if ($prev && $i - $prev > 1): ?><span style="color:var(--muted)">…</span><?php endif;
            $prev = $i; ?>
          <a class="fr-btn sm <?= $i === $page ? '' : 'ghost' ?>" href="<?= e($nq(['p' => $i])) ?>"><?= fa_num($i) ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a class="fr-btn sm ghost" href="<?= e($nq(['p' => $page + 1])) ?>">بعدی ›</a><?php endif; ?>
        <small style="color:var(--muted)">صفحهٔ <?= fa_num($page) ?> از <?= fa_num($pages) ?></small>
      </div>
      <?php endif; ?>
      <small class="hint" style="display:block;margin-top:8px">عضویت از نوار خبرنامهٔ فوتر سایت انجام می‌شود؛ ویرایش/حذف/لغو عضویت برای تک‌تک شماره‌ها یا گروهی فعال است.</small>
    </div>
  </div>
</div>

<?php
/* ── ویرایش تکی (دید مجزا) ── */
if (($v = (string)($_GET['v'] ?? '')) === 'edit'):
  $sid = (int)($_GET['id'] ?? 0);
  $sub = DB::one("SELECT * FROM " . DB::t('newsletter') . " WHERE id=?", [$sid]);
  if ($sub):
?>
<style>.nl-modal{position:fixed;inset:0;background:rgba(10,25,47,.55);display:flex;align-items:center;justify-content:center;z-index:99;padding:16px}</style>
<div class="nl-modal">
  <div class="fr-card" style="max-width:430px;width:100%;margin:0">
    <h3 style="margin-top:0">✏️ ویرایش مشترک <span class="chip">#<?= fa_num($sid) ?></span></h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="edit"><input type="hidden" name="id" value="<?= $sid ?>">
      <div class="fr-field"><label>موبایل *</label><input class="fr-input" name="mobile" dir="ltr" maxlength="11" value="<?= e($sub['mobile']) ?>" required></div>
      <div class="fr-field"><label>نام</label><input class="fr-input" name="name" maxlength="150" value="<?= e((string)$sub['name']) ?>"></div>
      <div class="fr-field"><label>وضعیت عضویت</label>
        <select class="fr-select" name="status">
          <option value="active" <?= $sub['status'] === 'active' ? 'selected' : '' ?>>فعال — پیامک دریافت می‌کند</option>
          <option value="unsub" <?= $sub['status'] === 'unsub' ? 'selected' : '' ?>>لغوشده</option>
        </select></div>
      <div style="font-size:11.5px;color:var(--muted);margin-bottom:10px">عضویت: <?= fa_num(jdate('Y/m/d H:i', strtotime($sub['created_at']))) ?> • منشأ: <?= e($sub['source']) ?><?= $sub['unsub_at'] ? ' • لغو در: ' . fa_num(jdate('Y/m/d', strtotime($sub['unsub_at']))) : '' ?></div>
      <div style="display:flex;gap:8px">
        <button class="fr-btn">💾 ذخیره</button>
        <a class="fr-btn ghost" href="<?= e(SITE_URL) ?>/admin/newsletter.php?<?= e($backQ) ?>">انصراف</a>
      </div>
    </form>
  </div>
</div>
<?php endif; endif; ?>

<script>
/* شمارش کاراکتر پیامک + همگام‌سازی چک‌مارک‌ها با فرم ارسال و نوار گروهی */
(function(){
  var t = document.getElementById('nlMsg'), c = document.getElementById('nlCount');
  function fa(n){ return String(n).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  if (t && c) t.addEventListener('input', function(){
    var L = t.value.length;
    c.textContent = fa(L) + ' کاراکتر — ≈ ' + fa(Math.max(1, Math.ceil(L/70))) + ' پیامک';
  });
  var sendForm = document.getElementById('nlSendForm');
  function sync(){
    document.getElementById('nlIds').innerHTML = '';
    var bulk = document.getElementById('nlBulk');
    bulk.querySelectorAll('input[name="ids[]"]').forEach(function(el){ el.remove(); });
    document.querySelectorAll('.nl-chk:checked').forEach(function(ch){
      var i = document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=ch.value;
      document.getElementById('nlIds').appendChild(i);
      var j = document.createElement('input'); j.type='hidden'; j.name='ids[]'; j.value=ch.value;
      bulk.appendChild(j);
    });
    var n = document.querySelectorAll('.nl-chk:checked').length;
    bulk.classList.toggle('on', n > 0);
    document.getElementById('nlBulkN').textContent = fa(n) + ' انتخاب';
  }
  document.querySelectorAll('.nl-chk').forEach(function(ch){ ch.addEventListener('change', sync); });
  var allChk = document.getElementById('nlAllChk');
  if (allChk) allChk.addEventListener('change', function(){
    document.querySelectorAll('.nl-chk').forEach(function(ch){ ch.checked = allChk.checked; });
    sync();
  });
  sendForm && sendForm.addEventListener('submit', sync);
})();
</script>
<?php admin_layout_end(); ?>
