<?php
/**
 * پنل کاربر — برنامهٔ روزانه (نسخهٔ ۲٫۳)
 * ─ پنج برگه: امروز | آمار و پیشرفت | کارهای تکرارشونده | دستاوردها | تمرکز (پومودورو)
 * ─ حلقهٔ پیشرفت امروز، نمودار ۱۴ روز، نشان‌های انگیزشی، انتقال مانده‌ها به امروز، برنامه‌ریزی فردا
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/planner.php';
fr_bootstrap();

$u = require_user();
if (!fr_planner_enabled()) redirect(fr_panel_url());

$today  = fr_planner_today();
$target = max(1, (int)DB::setting('planner_streak_target', '7'));
$msg = '';

/* فلش سبک داخلی */
$plFlash = function (?string $m = null) {
    if ($m !== null) { $_SESSION['pl_flash'] = $m; return null; }
    if (!empty($_SESSION['pl_flash'])) { $x = (string)$_SESSION['pl_flash']; unset($_SESSION['pl_flash']);
        return '<div class="fr-alert ok" style="margin-bottom:14px">' . e($x) . '</div>'; }
    return '';
};

$catMap = fr_planner_cat_map((int)$u['id']);   // ۲٫۷ — دسته‌ها با رنگ

/* ── اقدامات ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)($_POST['act'] ?? '');
    $backTab = (string)($_POST['tab'] ?? 'today');
    $redir = fr_panel_url('planner.php') . ($backTab !== 'today' ? '?t=' . urlencode($backTab) : '');
    if ($act === 'add') {
        $day = (string)($_POST['day'] ?? $today);
        if ($day === 'tomorrow') $day = date('Y-m-d', strtotime('+1 day'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = $today;
        $id = fr_planner_add((int)$u['id'], $day, (string)($_POST['title'] ?? ''),
                             (string)($_POST['time_note'] ?? ''), (string)($_POST['priority'] ?? 'normal'), (string)($_POST['category'] ?? 'personal'),
                             (string)($_POST['note'] ?? ''), !empty($_POST['remind_at']) ? (string)$_POST['remind_at'] : null);
        $msg = $id ? ($day > $today ? 'کار برای فردا برنامه‌ریزی شد 🗓' : 'کار جدید به برنامهٔ روزانه اضافه شد ✅') : 'عنوان کار را بنویسید.';
    }
    /* ۲٫۷ — ویرایش تسک */
    if ($act === 'edit') {
        $ok = fr_planner_update((int)$u['id'], (int)($_POST['id'] ?? 0), $_POST);
        $msg = $ok ? 'کار به‌روزرسانی شد ✏️' : 'ویرایش ناموفق (کار پیدا نشد یا عنوان خالی است).';
    }
    /* ۲٫۷ — عملیات گروهی */
    if ($act === 'bulk') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $op  = (string)($_POST['op'] ?? 'done');
        $n = fr_planner_bulk((int)$u['id'], $ids, $op === 'delete' ? 'delete' : ($op === 'undone' ? 'undone' : 'done'));
        $msg = $ids ? ('عملیات گروهی روی ' . fa_num($n) . ' کار انجام شد ✅') : 'هیچ کاری انتخاب نشده است.';
        if ($op === 'done') fr_planner_check_reward((int)$u['id']);
    }
    /* ۲٫۷ — دسته‌های سفارشی */
    if ($act === 'cat_add') {
        $nc = fr_planner_cat_add((int)$u['id'], (string)($_POST['name'] ?? ''), (string)($_POST['color'] ?? '#1e3a6e'));
        $msg = $nc ? 'دستهٔ تازه ساخته شد 🏷' : 'نام دسته خالی یا تکراری است.';
        $redir = fr_panel_url('planner.php?t=cats');
    }
    if ($act === 'cat_del') {
        fr_planner_cat_delete((int)$u['id'], (int)($_POST['id'] ?? 0));
        $msg = 'دسته حذف شد (کارهای آن دسته باقی می‌مانند).';
        $redir = fr_panel_url('planner.php?t=cats');
    }
    if ($act === 'toggle')  { fr_planner_toggle((int)$u['id'], (int)($_POST['id'] ?? 0)); }
    if ($act === 'del')     { fr_planner_delete((int)$u['id'], (int)($_POST['id'] ?? 0)); $msg = 'کار حذف شد.'; }
    if ($act === 'carry')   { $n = fr_planner_carry_over((int)$u['id']); $msg = $n > 0 ? fa_num($n) . ' کارِ ناتمامِ قبلی به امروز منتقل شد 📥' : 'کارِ ناتمامی از روزهای گذشته نداری — عالی!'; }
    if ($act === 'recur_add') {
        $id = fr_planner_recur_add((int)$u['id'], (string)($_POST['title'] ?? ''), (string)($_POST['time_note'] ?? ''),
                                   (string)($_POST['priority'] ?? 'normal'), (string)($_POST['category'] ?? 'personal'), (string)($_POST['freq'] ?? 'daily'));
        $msg = $id ? 'کار تکرارشونده ثبت شد 🔁 از امروز خودش سر جایش می‌آید.' : 'عنوان کار را بنویسید.';
        $redir = fr_panel_url('planner.php?t=recur');
    }
    if ($act === 'recur_del' || $act === 'recur_toggle') {
        $rid = (int)($_POST['id'] ?? 0);
        if ($act === 'recur_del') DB::run("DELETE FROM " . DB::t('planner_recur') . " WHERE id=? AND user_id=?", [$rid, (int)$u['id']]);
        else DB::run("UPDATE " . DB::t('planner_recur') . " SET active=1-active WHERE id=? AND user_id=?", [$rid, (int)$u['id']]);
        $redir = fr_panel_url('planner.php?t=recur');
    }
    if ($act !== 'toggle') { $plFlash($msg); redirect($redir); }
    redirect($redir);
}

/* کارهای تکرارشونده امروز را بساز (اگر هنوز ساخته نشده‌اند) */
fr_planner_ensure_day((int)$u['id'], $today);

$tabRaw = (string)($_GET['t'] ?? 'today');
$tab = in_array($tabRaw, ['today','week','all','cats','stats','recur','badges','focus','delta'], true) ? $tabRaw : 'today';

$tasks   = fr_planner_day_tasks((int)$u['id'], $today);
$week    = fr_planner_week((int)$u['id']);
$streak  = fr_planner_current_streak((int)$u['id']);
$rewards = fr_planner_rewards((int)$u['id']);
$doneCnt = count(array_filter($tasks, fn($t) => (int)$t['done'] === 1));
$allDone = count($tasks) > 0 && $doneCnt === count($tasks);
$todayPct = count($tasks) ? (int)round($doneCnt / count($tasks) * 100) : 0;
$targetPct = min(100, (int)round($streak / $target * 100));
$priFa  = ['high' => 'مهم 🔴', 'normal' => 'عادی', 'low' => 'سبک ⚪'];
$catFa  = ['personal' => 'شخصی', 'study' => 'درس و رشد', 'work' => 'کار', 'health' => 'سلامت', 'fun' => 'تفریح'];
$freqFa = ['daily' => 'هر روز', 'weekday' => 'روزهای کاری (شنبه تا پنجشنبه)', 'weekly' => 'هفته‌ای یک‌بار'];
$hasMsgBot = trim((string)$u['telegram_chat_id']) !== '' || trim((string)$u['bale_chat_id']) !== '';
$oldCnt  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=0 AND day<?", [(int)$u['id'], $today]);

/* حلقهٔ SVG پیشرفت */
$ring = function (int $pct, string $color, int $size = 86): string {
    $r = ($size / 2) - 7; $c = 2 * M_PI * $r; $off = $c * (1 - max(0, min(100, $pct)) / 100);
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" style="transform:rotate(-90deg)">'
         . '<circle cx="' . $size/2 . '" cy="' . $size/2 . '" r="' . $r . '" fill="none" stroke="rgba(255,255,255,.25)" stroke-width="7"/>'
         . '<circle cx="' . $size/2 . '" cy="' . $size/2 . '" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="7" stroke-linecap="round" stroke-dasharray="' . $c . '" stroke-dashoffset="' . $off . '" style="transition:stroke-dashoffset .8s"/>'
         . '</svg>';
};

/* ۲٫۲ — render_header برای رندر کامل سند */
render_header('برنامهٔ روزانه');
panel_layout($u, 'planner');
echo (string)$plFlash();

/* نوار برگه‌ها */
$TABS = [
    'today'  => '🗓 امروز',
    'week'   => '📅 نمای هفته',
    'all'    => '🔎 همهٔ کارها',
    'recur'  => '🔁 تکرارشونده‌ها',
    'cats'   => '🏷 دسته‌ها',
    'stats'  => '📈 آمار و پیشرفت',
    'badges' => '🏅 دستاوردها',
    'focus'  => '⏱ تمرکز',
    'delta'  => '🎯 پایش رشد آزمون‌ها',
];
?>
<div class="pl-tabs">
  <?php foreach ($TABS as $k => $lbl): ?>
  <a class="pl-tab <?= $tab === $k ? 'on' : '' ?>" href="?t=<?= $k ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="pl-hero">
  <div class="pl-streak">
    <span class="fire"><?= $streak >= $target ? '🏆' : ($streak > 0 ? '🔥' : '🦢') ?></span>
    <div style="flex:1">
      <b><?= fa_num($streak) ?></b> <span style="font-size:13px;opacity:.92">روز متوالی کامل</span>
      <div class="pl-bar"><i style="width:<?= $targetPct ?>%"></i></div>
      <small style="font-size:11.5px;opacity:.9">هدف پاداش: <?= fa_num($target) ?> روز متوالی — <?= $target === $streak ? '🎉 به هدف رسیدید!' : fa_num(max(0, $target - $streak)) . ' روز مانده' ?></small>
    </div>
  </div>
  <div class="pl-ringbox" title="پیشرفت امروز">
    <?= $ring($todayPct, '#ffd98e') ?>
    <div class="pl-ringin"><b><?= fa_num($todayPct) ?>٪</b><small>امروز</small></div>
  </div>
  <div class="fr-card" style="margin:0">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <b style="font-size:14px">نمای هفته</b>
      <small style="color:var(--muted)"><?= fa_num(jdate('l j F Y')) ?></small>
    </div>
    <?php $remSlots = fr_planner_remind_slots(); if ($remSlots): ?>
    <div style="font-size:11.5px;color:var(--muted);margin:-4px 0 8px">
      ⏰ یادآوری‌های امروز (۲۴ ساعته):
      <?php foreach ($remSlots as $i => $rt): ?><?= $i ? ' • ' : '' ?><b><?= fa_num($rt) ?></b><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="pl-week">
      <?php foreach ($week as $w):
          $cls = $w['complete'] ? 'ok' : ($w['done'] > 0 ? 'part' : ''); ?>
      <div class="pl-day <?= $cls ?> <?= $w['day'] === $today ? 'today' : '' ?>">
        <?= fa_num(jdate('D', strtotime($w['day']))) ?>
        <span class="dot"><?= $w['complete'] ? '✓' : fa_num($w['done'] . '/' . $w['total']) ?></span>
        <span style="font-size:10.5px"><?= fa_num(jdate('j/n', strtotime($w['day']))) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($tab === 'today'): ?>
<!-- ════════════ برگهٔ امروز ════════════ -->
<div class="pl-main">
  <div>
    <div class="fr-card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h3 style="margin:0">🗓 کارهای امروز</h3>
        <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?php if ($oldCnt > 0): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('<?= fa_num($oldCnt) ?> کارِ ناتمامِ روزهای گذشته به امروز منتقل شود؟')">
            <?= csrf_field() ?><input type="hidden" name="act" value="carry">
            <button class="fr-btn sm ghost" title="انتقال مانده‌های قبلی به امروز">📥 انتقال <?= fa_num($oldCnt) ?> مانده به امروز</button>
          </form>
          <?php endif; ?>
          <span class="chip" style="<?= $allDone ? 'background:#e3f7ef;color:#0c7a4d' : '' ?>">
            <?= $tasks ? fa_num($doneCnt . '/' . count($tasks)) . ' انجام شد' : 'هنوز کاری ثبت نشده' ?>
          </span>
        </span>
      </div>
      <?php if ($allDone): ?>
      <div class="fr-alert ok" style="font-size:13px">🎉 آفرین! همهٔ کارهای امروز را انجام دادی — این روز در استریک تو ثبت شد.</div>
      <?php endif; ?>

      <!-- ۲٫۷ — نوار انتخاب گروهی -->
      <form method="post" id="plBulkBar" style="display:none;gap:8px;align-items:center;flex-wrap:wrap;background:#f2f6fd;border:1.5px dashed var(--line);border-radius:12px;padding:8px 10px;margin-bottom:10px">
        <?= csrf_field() ?><input type="hidden" name="act" value="bulk">
        <b style="font-size:12.5px"><span id="plBulkN">۰</span> انتخاب‌شده:</b>
        <select class="fr-select sm" name="op" style="max-width:140px">
          <option value="done">✅ علامت انجام‌شده</option>
          <option value="undone">↩ برگشت به ناتمام</option>
          <option value="delete">🗑 حذف گروهی</option>
        </select>
        <button class="fr-btn sm">اجرا</button>
      </form>

      <div id="plList">
        <?php foreach ($tasks as $t):
          [$catLbl, $catColor] = $catMap[$t['category']] ?? [$t['category'], '#64748b']; ?>
        <div class="pl-task <?= $t['done'] ? 'done' : '' ?>" data-id="<?= (int)$t['id'] ?>">
          <input type="checkbox" class="pl-sel" name="ids[]" form="plBulkBar" value="<?= (int)$t['id'] ?>" title="انتخاب" style="width:16px;height:16px;align-self:center">
          <button type="button" class="pl-chk <?= $t['done'] ? 'on' : '' ?>" title="انجام شد؟"><?= $t['done'] ? '✓' : '' ?></button>
          <div style="flex:1">
            <div class="tt"><?= e($t['title']) ?>
              <?php if (!empty($t['recur_id'])): ?><span title="کار تکرارشونده" style="font-size:11px;opacity:.7">🔁</span><?php endif; ?></div>
            <div class="meta">
              <?php if ($t['time_note']): ?><span>⏰ <?= fa_num($t['time_note']) ?></span><?php endif; ?>
              <span class="pl-pri <?= e($t['priority']) ?>"><?= $priFa[$t['priority']] ?? 'عادی' ?></span>
              <span class="chip" style="border-right:3px solid <?= e($catColor) ?>;font-size:11px"><?= e($catLbl) ?></span>
              <?php if (!empty($t['remind_at'])): ?><span title="یادآور" style="font-size:11px">🔔 <?= fa_num(jdate('j/n H:i', strtotime((string)$t['remind_at']))) ?><?= !empty($t['reminded_at']) ? ' ✓' : '' ?></span><?php endif; ?>
            </div>
            <?php if (trim((string)($t['note'] ?? '')) !== ''): ?>
            <div style="font-size:11.5px;color:var(--muted);margin-top:3px;line-height:1.9">📝 <?= e(mb_strimwidth((string)$t['note'], 0, 110, '…')) ?></div>
            <?php endif; ?>
          </div>
          <a class="fr-btn sm ghost" href="?t=today&edit=<?= (int)$t['id'] ?>" title="ویرایش">✏️</a>
          <form method="post" onsubmit="return confirm('این کار حذف شود؟')">
            <?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button class="fr-btn sm danger" title="حذف">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$tasks): ?>
        <div style="text-align:center;color:var(--muted);padding:26px 10px;font-size:13.5px">
          🌅 صبحت بخیر! اولین کار امروزت را از فرم پایین اضافه کن؛ روزهای پشت سر همِ کامل، پاداش دارند.
        </div>
        <?php endif; ?>
      </div>

      <!-- افزودن کار -->
      <form method="post" style="margin-top:14px;border-top:1.5px dashed var(--line);padding-top:14px">
        <?= csrf_field() ?><input type="hidden" name="act" value="add">
        <div class="pl-addgrid">
          <div class="fr-field" style="margin:0"><input class="fr-input" name="title" maxlength="190" required placeholder="کار جدید… (مثلاً ۳۰ دقیقه مطالعه)"></div>
          <div class="fr-field" style="margin:0"><input class="fr-input" name="time_note" type="text" data-t24 inputmode="numeric" maxlength="5"
                                                       pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="08:30" dir="ltr" style="text-align:center"
                                                       title="ساعت (قالب ۲۴ ساعته — اختیاری)"></div>
          <div class="fr-field" style="margin:0">
            <select class="fr-select" name="priority">
              <?php foreach ($priFa as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select></div>
          <div class="fr-field" style="margin:0">
            <select class="fr-select" name="category">
              <?php foreach ($catMap as $k => [$lbl, $col]): ?>
              <option value="<?= e($k) ?>" style="color:<?= e($col) ?>"><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <!-- ۲٫۷ — یادداشت + یادآور اختصاصی -->
        <div class="pl-addgrid" style="margin-top:8px">
          <div style="display:flex;gap:8px;align-items:center">
            <span style="font-size:12px;color:var(--muted);white-space:nowrap">📝</span>
            <input class="fr-input" name="note" maxlength="500" placeholder="یادداشت (اختیاری — مثلاً لینک جلسه یا جزئیات کار)">
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <span style="font-size:12px;color:var(--muted);white-space:nowrap">🔔 یادآور:</span>
            <input class="fr-input" type="datetime-local" name="remind_at" dir="ltr" title="اگر تنظیم شود، سر موعد از کانال‌های فعال (تلگرام/بله/پیامک — به انتخاب مدیر) یادآوری می‌رسد">
          </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
          <select class="fr-select" name="day" style="max-width:150px" title="برای کدام روز؟">
            <option value="<?= e($today) ?>">امروز</option>
            <option value="tomorrow">فردا 🗓</option>
          </select>
          <button class="fr-btn">➕ افزودن به برنامه</button>
          <a class="fr-btn ghost sm" href="?t=cats">🏷 دسته‌ها</a>
        </div>
      </form>
    </div>
  </div>

  <div>
    <div class="fr-card" style="margin:0 0 14px">
      <h3 style="font-size:15px">🎁 جوایز استریک</h3>
      <p style="font-size:12.5px;color:var(--muted);line-height:2;margin-top:0">
        هر <?= fa_num($target) ?> روزِ متوالی که همهٔ کارهایت کامل شود، یک جایزه می‌گیری —
        <?= (string)DB::setting('planner_reward_type','discount') === 'discount'
            ? 'کد تخفیف ' . fa_num((int)DB::setting('planner_reward_percent','20')) . '٪ مخصوص خودت (قابل استفاده در تست‌های روانشناسی و رویدادها)'
            : e(DB::setting('planner_reward_message','پیام تشویقی ویژهٔ مدیر مجموعه')) ?>.
      </p>
      <?php if ($rewards): foreach ($rewards as $rw): ?>
      <div class="pl-reward">
        <span><?= $rw['type'] === 'discount' ? '🏷' : '💌' ?></span>
        <div style="flex:1">
          <?php if ($rw['type'] === 'discount' && $rw['code']): ?>
          کد <b dir="ltr" style="letter-spacing:1px"><?= e($rw['code']) ?></b> — <?= fa_num((int)$rw['percent']) ?>٪ تخفیف
          <?php else: ?>پیام ویژهٔ مدیر مجموعه<?php endif; ?><br>
          <small style="color:var(--muted)">برای استریک <?= fa_num((int)$rw['streak_to']) ?> روزه — <?= fa_num(jdate('j F Y', strtotime($rw['created_at']))) ?></small>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div style="font-size:12.5px;color:var(--muted)">هنوز جایزه‌ای نگرفتی؛ از امروز شروع کن 💪</div>
      <?php endif; ?>
    </div>

    <div class="fr-card" style="margin:0;background:linear-gradient(180deg,rgba(30,58,110,.05),#fff);border:1.5px solid var(--line)">
      <h3 style="font-size:15px">📨 اطلاع‌رسانی برنامه‌ریزی</h3>
      <?php if ($hasMsgBot): ?>
      <p style="font-size:12.5px;line-height:2;color:var(--muted)">✅ متصل هستی — هر صبح حوالی ساعت <b dir="ltr"><?= fa_num(DB::setting('planner_remind_time','08:30')) ?></b> (۲۴ ساعته) فهرست کارهای روز برایت در <?= trim((string)$u['telegram_chat_id']) !== '' ? 'تلگرام' : '' ?><?= (trim((string)$u['telegram_chat_id']) !== '' && trim((string)$u['bale_chat_id']) !== '') ? ' و ' : '' ?><?= trim((string)$u['bale_chat_id']) !== '' ? 'بله' : '' ?> پیام می‌شود.</p>
      <?php else: ?>
      <p style="font-size:12.5px;line-height:2;color:var(--muted)">با نوشتن <b>نام کاربری</b> اکانت تلگرام یا بله‌ات در پروفایل، به‌صورت خودکار وصل می‌شوی و یادآورها را دریافت می‌کنی — بدون هیچ شناسهٔ عددی.</p>
      <?php endif; ?>
      <a class="fr-btn sm ghost" href="<?= fr_panel_url("profile.php") ?>">⚙️ تنظیم اطلاع‌رسانی در پروفایل</a>
    </div>
  </div>
</div>

<?php elseif ($tab === 'week'): ?>
<!-- ════════════ برگهٔ نمای هفته (۲٫۷) ════════════ -->
<?php
  $wOff = (int)($_GET['w'] ?? 0);
  $wv = fr_planner_week_view((int)$u['id'], $wOff);
  $dayFa = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
?>
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
    <h3 style="margin:0">📅 نمای هفته <small style="color:var(--muted);font-weight:400">(<?= fa_num(jdate('j F', strtotime($wv['start']))) ?> تا <?= fa_num(jdate('j F', strtotime($wv['end']))) ?>)</small></h3>
    <div style="display:flex;gap:6px">
      <a class="fr-btn sm ghost" href="?t=week&w=<?= $wOff - 1 ?>">‹ هفتهٔ قبل</a>
      <?php if ($wOff !== 0): ?><a class="fr-btn sm" href="?t=week">این هفته</a><?php endif; ?>
      <a class="fr-btn sm ghost" href="?t=week&w=<?= $wOff + 1 ?>">هفتهٔ بعد ›</a>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px" class="pl-weekgrid">
    <?php $di = 0; foreach ($wv['days'] as $d => $dt):
      $isToday = $d === $today;
      $dn = count(array_filter($dt, fn($x) => (int)$x['done'] === 1)); ?>
    <div style="border:1.5px solid <?= $isToday ? 'var(--c-primary)' : 'var(--line)' ?>;border-radius:12px;padding:8px;background:<?= $isToday ? '#f0f5fd' : 'var(--card)' ?>;min-height:110px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <b style="font-size:12px"><?= $dayFa[$di] ?? '' ?></b>
        <small style="color:var(--muted)"><?= fa_num(jdate('j/n', strtotime($d))) ?></small>
      </div>
      <?php if ($dt): ?><div style="font-size:10.5px;color:var(--muted)"><?= fa_num($dn) ?>/<?= fa_num(count($dt)) ?> ✓</div><?php endif; ?>
      <?php foreach ($dt as $x): [$cl, $cc] = $catMap[$x['category']] ?? [$x['category'], '#64748b']; ?>
      <div style="font-size:11px;border-radius:8px;padding:4px 6px;margin:4px 0;background:<?= $x['done'] ? '#eef2f7' : '#fff' ?>;border-right:3px solid <?= e($cc) ?>;<?= $x['done'] ? 'text-decoration:line-through;opacity:.65' : '' ?>" title="<?= e($x['title']) ?> <?= !empty($x['remind_at']) ? '🔔' : '' ?>">
        <?= !empty($x['time_note']) ? '<b style="color:var(--muted)">' . fa_num($x['time_note']) . '</b> ' : '' ?><?= e(mb_strimwidth($x['title'], 0, 30, '…')) ?>
        <?= !empty($x['remind_at']) && !$x['done'] ? '🔔' : '' ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php $di++; endforeach; ?>
  </div>
  <small class="hint" style="display:block;margin-top:10px">دید بال‌پرنده از برنامه‌ات — شنبه تا جمعه؛ برای افزودن کار به روز خاص، از فرم «افزودن» در برگهٔ امروز استفاده کن یا کار را ویرایش و روزش را جابه‌جا کن.</small>
</div>
<style>@media (max-width:860px){.pl-weekgrid{grid-template-columns:repeat(2,1fr) !important}}</style>

<?php elseif ($tab === 'all'): ?>
<!-- ════════════ برگهٔ همهٔ کارها — جستجو/فیلتر (۲٫۷) ════════════ -->
<?php
  $fltQ = ['q' => (string)($_GET['q'] ?? ''), 'cat' => (string)($_GET['cat'] ?? ''), 'pri' => (string)($_GET['pri'] ?? ''),
           'done' => (string)($_GET['done'] ?? ''), 'from' => '', 'to' => ''];
  $pgA = fr_planner_search((int)$u['id'], $fltQ, (int)($_GET['p'] ?? 1), 20);
  $aq = fn(array $over = []) => '?' . http_build_query(array_merge(['t' => 'all'], $fltQ, $over));
?>
<div class="fr-card">
  <h3 style="margin-top:0">🔎 همهٔ کارها <small style="color:var(--muted);font-weight:400">(<?= fa_num($pgA['total']) ?> مورد)</small></h3>
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <input type="hidden" name="t" value="all">
    <input class="fr-input sm" name="q" value="<?= e($fltQ['q']) ?>" placeholder="جستجو در عنوان/یادداشت…" style="max-width:190px">
    <select class="fr-select sm" name="cat" style="max-width:130px"><option value="">همهٔ دسته‌ها</option>
      <?php foreach ($catMap as $k => [$lbl, $col]): ?><option value="<?= e($k) ?>" <?= $fltQ['cat'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select>
    <select class="fr-select sm" name="pri" style="max-width:110px"><option value="">هر اولویت</option>
      <?php foreach ($priFa as $k => $vP): ?><option value="<?= $k ?>" <?= $fltQ['pri'] === $k ? 'selected' : '' ?>><?= $vP ?></option><?php endforeach; ?></select>
    <select class="fr-select sm" name="done" style="max-width:110px"><option value="">هر دو</option>
      <option value="0" <?= $fltQ['done'] === '0' ? 'selected' : '' ?>>ناتمام</option>
      <option value="1" <?= $fltQ['done'] === '1' ? 'selected' : '' ?>>انجام‌شده</option></select>
    <button class="fr-btn sm">جستجو</button>
    <a class="fr-btn sm ghost" href="?t=all">پاک‌سازی</a>
  </form>
  <div class="tbl-wrap"><table class="fr-table" style="font-size:12.5px">
    <thead><tr><th>عنوان</th><th>روز</th><th>ساعت</th><th>دسته</th><th>اولویت</th><th>وضعیت</th><th style="width:90px"></th></tr></thead>
    <tbody>
    <?php foreach ($pgA['rows'] as $x): [$cl, $cc] = $catMap[$x['category']] ?? [$x['category'], '#64748b']; ?>
      <tr>
        <td><b><?= e(mb_strimwidth($x['title'], 0, 48, '…')) ?></b>
            <?= !empty($x['note']) ? '<br><small style="color:var(--muted)">📝 ' . e(mb_strimwidth((string)$x['note'], 0, 50, '…')) . '</small>' : '' ?>
            <?= !empty($x['remind_at']) && !$x['done'] ? '<br><small>🔔 ' . fa_num(jdate('j/n H:i', strtotime((string)$x['remind_at']))) . '</small>' : '' ?></td>
        <td style="white-space:nowrap"><?= fa_num(jdate('j/n/y', strtotime($x['day']))) ?></td>
        <td><?= !empty($x['time_note']) ? fa_num($x['time_note']) : '—' ?></td>
        <td><span class="chip" style="border-right:3px solid <?= e($cc) ?>;font-size:11px"><?= e($cl) ?></span></td>
        <td><span class="pl-pri <?= e($x['priority']) ?>"><?= $priFa[$x['priority']] ?? 'عادی' ?></span></td>
        <td><?= $x['done'] ? '<span style="color:var(--c-accent)">✅</span>' : '<span style="color:#8a5a00">⏳</span>' ?></td>
        <td style="white-space:nowrap">
          <a class="fr-btn sm ghost" href="?t=all&edit=<?= (int)$x['id'] ?>">✏️</a>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')"><input type="hidden" name="tab" value="all">
            <?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
            <button class="fr-btn sm danger">✕</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$pgA['rows']): ?><tr><td colspan="7" style="text-align:center;color:var(--muted);padding:22px">موردی با این فیلترها پیدا نشد.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($pgA['pages'] > 1): ?>
  <div class="ct-pager" style="display:flex;gap:6px;justify-content:center;margin-top:12px;flex-wrap:wrap">
    <?php for ($i = 1; $i <= $pgA['pages']; $i++): ?>
    <a class="fr-btn sm <?= $i === $pgA['page'] ? '' : 'ghost' ?>" href="<?= e($aq(['p' => $i])) ?>"><?= fa_num($i) ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'cats'): ?>
<!-- ════════════ برگهٔ دسته‌ها (۲٫۷) ════════════ -->
<div class="fr-grid cols-2" style="align-items:start">
  <div class="fr-card" style="margin:0">
    <h3 style="margin-top:0">🏷 دسته‌های من با رنگ اختصاصی</h3>
    <?php foreach ($catMap as $k => [$lbl, $col]):
      $isCustom = !in_array($k, ['personal','study','work','health','fun'], true); ?>
    <div class="pl-task">
      <span style="width:16px;height:16px;border-radius:50%;background:<?= e($col) ?>;align-self:center"></span>
      <div style="flex:1"><div class="tt"><?= e($lbl) ?></div>
        <div class="meta"><small style="color:var(--muted)" dir="ltr"><?= e($col) ?></small><?= $isCustom ? '' : '<small style="color:var(--muted)">• پیش‌فرض سیستم</small>' ?></div></div>
      <?php if ($isCustom):
        $cid2 = 0; foreach (fr_planner_cats((int)$u['id']) as $cr) { if ($cr['name'] === $k) $cid2 = (int)$cr['id']; } ?>
      <form method="post" onsubmit="return confirm('این دسته حذف شود؟ کارهایش به‌عنوان برچسب آزاد باقی می‌مانند.')"><input type="hidden" name="tab" value="cats">
        <?= csrf_field() ?><input type="hidden" name="act" value="cat_del"><input type="hidden" name="id" value="<?= $cid2 ?>">
        <button class="fr-btn sm danger">✕</button></form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="fr-card" style="margin:0">
    <h3 style="margin-top:0;font-size:15px">➕ دستهٔ تازه</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="cat_add"><input type="hidden" name="tab" value="cats">
      <div class="fr-field"><label>نام دسته</label><input class="fr-input" name="name" maxlength="40" required placeholder="مثلاً مدیریت تیم یا مادرانه"></div>
      <div class="fr-field"><label>رنگ</label><input class="fr-input" type="color" name="color" value="#0f766e" style="height:44px;padding:4px"></div>
      <button class="fr-btn">🏷 افزودن دسته</button>
    </form>
    <div class="fr-alert" style="font-size:12px;margin-top:12px;line-height:2">
      دسته‌ها در فرم افزودن/ویرایش کار و فیلتر «همهٔ کارها» ظاهر می‌شوند و رنگ‌شان روی کارت‌ها و نمای هفته اعمال می‌شود.
    </div>
  </div>
</div>

<?php elseif ($tab === 'stats'): ?>
<!-- ════════════ برگهٔ آمار و پیشرفت ════════════ -->
<?php $st = fr_planner_progress_stats((int)$u['id']); $chart = fr_planner_chart((int)$u['id'], 14); $maxPct = 100; ?>
<div class="fr-grid cols-4" style="margin-bottom:14px">
  <div class="fr-card pl-stat"><span>✅</span><b><?= fa_num($st['done']) ?></b><small>کار انجام‌شده از <?= fa_num($st['total']) ?> کار</small></div>
  <div class="fr-card pl-stat"><span>📆</span><b><?= fa_num($st['complete_days']) ?></b><small>روزِ کاملاً کامل (از <?= fa_num($st['days']) ?> روزِ فعال)</small></div>
  <div class="fr-card pl-stat"><span>🔥</span><b><?= fa_num($st['best_streak']) ?></b><small>بهترین استریک (روزهای متوالی)</small></div>
  <div class="fr-card pl-stat"><span>🎯</span><b><?= fa_num($st['rate30']) ?>٪</b><small>موفقیت ۳۰ روز اخیر</small></div>
</div>
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3 style="font-size:15px">📊 نمودار ۱۴ روز اخیر</h3>
    <p style="font-size:12px;color:var(--muted);margin:0 0 10px">درصد کارهای انجام‌شدهٔ هر روز — هرچه میله بلندتر، روز منظم‌تر.</p>
    <div class="pl-chart">
      <?php foreach ($chart as $c): ?>
      <div class="pl-col" title="<?= fa_num(jdate('l j/n', strtotime($c['day']))) ?> — <?= $c['have'] ? fa_num($c['pct']) . '٪' : 'کاری نداشتی' ?>">
        <div class="pl-barv"><i style="height:<?= $c['have'] ? max(6, $c['pct']) : 0 ?>%"></i></div>
        <small><?= fa_num(jdate('j/n', strtotime($c['day']))) ?></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fr-card" style="margin:0;text-align:center">
    <h3 style="font-size:15px;text-align:right">🎯 هدف‌گذاری استریک</h3>
    <div style="position:relative;display:inline-flex;margin:12px auto">
      <?= $ring(min(100, (int)round($streak / $target * 100)), 'var(--c-primary)', 120) ?>
      <div class="pl-ringin" style="color:var(--ink)"><b><?= fa_num($streak) ?> / <?= fa_num($target) ?></b><small>روز متوالی</small></div>
    </div>
    <p style="font-size:12.5px;color:var(--muted);line-height:2">
      هر روز که همهٔ کارهایت را بزنی، یک پله به پاداش نزدیک‌تر می‌شوی؛ وقفه بزنی استریک صفر می‌شود —
      اما بهترین رکوردت (<?= fa_num($st['best_streak']) ?>) همیشه می‌ماند.
    </p>
  </div>
</div>

<?php elseif ($tab === 'recur'): ?>
<!-- ════════════ برگهٔ کارهای تکرارشونده ════════════ -->
<?php $recurs = fr_planner_recur_list((int)$u['id']); ?>
<div class="pl-main">
  <div>
    <div class="fr-card">
      <h3>🔁 کارهای تکرارشونده</h3>
      <p style="font-size:12.5px;color:var(--muted);line-height:2;margin-top:0">
        کارهای روتینت (مثل ورزش صبحگاهی یا مطالعه) را یک‌بار تعریف کن؛ هر روز سرِ جایشان در برنامهٔ روزانه ظاهر می‌شوند.
        روزهای کاری یعنی شنبه تا پنجشنبه — جمعه‌ها استراحت!
      </p>
      <?php foreach ($recurs as $r): ?>
      <div class="pl-task <?= $r['active'] ? '' : 'done' ?>">
        <span style="font-size:17px"><?= $r['active'] ? '🔁' : '⏸' ?></span>
        <div style="flex:1">
          <div class="tt"><?= e($r['title']) ?></div>
          <div class="meta">
            <span><?= $freqFa[$r['freq']] ?? $r['freq'] ?></span>
            <?php if ($r['time_note']): ?><span>⏰ <?= fa_num($r['time_note']) ?></span><?php endif; ?>
            <span class="pl-pri <?= e($r['priority']) ?>"><?= $priFa[$r['priority']] ?? 'عادی' ?></span>
          </div>
        </div>
        <form method="post" style="display:inline"><input type="hidden" name="tab" value="recur">
          <?= csrf_field() ?><input type="hidden" name="act" value="recur_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="fr-btn sm ghost" title="تعلیق/فعال‌سازی"><?= $r['active'] ? '⏸ تعلیق' : '▶️ فعال' ?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('این الگوی تکرار حذف شود؟ (کارهای ساخته‌شدهٔ امروز باقی می‌مانند)')"><input type="hidden" name="tab" value="recur">
          <?= csrf_field() ?><input type="hidden" name="act" value="recur_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="fr-btn sm danger">✕</button>
        </form>
      </div>
      <?php endforeach; ?>
      <?php if (!$recurs): ?>
      <div style="text-align:center;color:var(--muted);padding:22px 8px;font-size:13px">هنوز کار تکرارشونده‌ای نداری — از فرم پایین مثلاً «۱۵ دقیقه کتاب» را روزانه کن 👇</div>
      <?php endif; ?>

      <form method="post" style="margin-top:14px;border-top:1.5px dashed var(--line);padding-top:14px">
        <?= csrf_field() ?><input type="hidden" name="act" value="recur_add"><input type="hidden" name="tab" value="recur">
        <div class="pl-addgrid">
          <div class="fr-field" style="margin:0"><input class="fr-input" name="title" maxlength="190" required placeholder="کار تکرارشونده… (مثلاً ۱۵ دقیقه ورزش)"></div>
          <div class="fr-field" style="margin:0"><input class="fr-input" name="time_note" type="text" data-t24 inputmode="numeric" maxlength="5"
                                                       pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="08:30" dir="ltr" style="text-align:center"
                                                       title="ساعت (قالب ۲۴ ساعته — اختیاری)"></div>
          <div class="fr-field" style="margin:0">
            <select class="fr-select" name="priority">
              <?php foreach ($priFa as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select></div>
          <div class="fr-field" style="margin:0">
            <select class="fr-select" name="freq">
              <?php foreach ($freqFa as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <button class="fr-btn" style="margin-top:10px">🔁 ساخت کار تکرارشونده</button>
      </form>
    </div>
  </div>
  <div>
    <div class="fr-card" style="margin:0;background:linear-gradient(180deg,rgba(30,58,110,.05),#fff)">
      <h3 style="font-size:15px">💡 چرا تکرارشونده؟</h3>
      <p style="font-size:12.5px;color:var(--muted);line-height:2.1;margin:0">
        عادت‌های روزانه، موتور استریک تو هستند؛ با یک‌بار تعریف، برنامه‌ریز هر روز پیش‌پر می‌شود و
        شانس رسیدنت به جوایز هفتگی چند برابر می‌شود. 🏆
      </p>
    </div>
  </div>
</div>

<?php elseif ($tab === 'badges'): ?>
<!-- ════════════ برگهٔ دستاوردها ════════════ -->
<?php $badges = fr_planner_badges((int)$u['id']); $unlocked = count(array_filter($badges, fn($b) => $b[4])); ?>
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:10px">
    <h3 style="margin:0">🏅 نشان‌های من</h3>
    <span class="chip"><?= fa_num($unlocked) . ' از ' . fa_num(count($badges)) ?> باز شده</span>
  </div>
  <div class="pl-badges">
    <?php foreach ($badges as [$key, $ico, $name, $desc, $got]): ?>
    <div class="pl-badge <?= $got ? 'got' : '' ?>" title="<?= e($desc) ?>">
      <span class="ic"><?= $got ? $ico : '🔒' ?></span>
      <b><?= e($name) ?></b>
      <small><?= e($desc) ?></small>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab === 'focus'): ?>
<!-- ════════════ برگهٔ تمرکز (پومودورو) ════════════ -->
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0;text-align:center">
    <h3 style="font-size:15px">⏱ جلسهٔ تمرکز عمیق</h3>
    <p style="font-size:12.5px;color:var(--muted);line-height:2">۲۵ دقیقه تمرکز کامل روی یک کار → ۵ دقیقه استراحت. تکنیک معروف پومودورو برای مدیران پرمشغله.</p>
    <div class="pl-focus-timer" id="pzTimer">۲۵:۰۰</div>
    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
      <button class="fr-btn" id="pzStart">شروع تمرکز 🍅</button>
      <button class="fr-btn ghost" id="pzBreak">استراحت ۵ دقیقه ☕</button>
      <button class="fr-btn ghost danger" id="pzReset">توقف</button>
    </div>
    <small id="pzState" style="display:block;margin-top:10px;color:var(--muted)">آماده‌ای؟ یک کار از فهرست امروزت را انتخاب کن و دکمهٔ شروع را بزن.</small>
  </div>
  <div class="fr-card" style="margin:0">
    <h3 style="font-size:15px">📌 کارهای ناتمام امروز برای تمرکز</h3>
    <?php $pend = array_filter($tasks, fn($t) => !(int)$t['done']); if (!$pend): ?>
      <p style="font-size:12.5px;color:var(--muted)">چیزی نمانده — همه‌چیز انجام شده! 🎉</p>
    <?php else: foreach ($pend as $t): ?>
      <div class="pl-task" style="margin-bottom:6px">
        <span>🍅</span><div style="flex:1"><div class="tt"><?= e($t['title']) ?></div></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<script>
/* تایمر پومودورو سبک — بدون نیاز به سرور */
(function(){
  var el = document.getElementById('pzTimer'); if(!el) return;
  var fa = function(s){ return s.replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
  var total = 25*60, left = total, timer = null, mode = 'focus';
  function fmt(){ var m = Math.floor(left/60), s = left%60; el.textContent = fa((m<10?'0':'')+m+':' + (s<10?'0':'')+s); }
  function state(t){ document.getElementById('pzState').textContent = t; }
  function stop(){ if(timer){ clearInterval(timer); timer = null; } }
  function tick(){
    left--;
    if (left <= 0){ stop(); fmt();
      if (mode==='focus'){ state('وقت استراحت! ☕ آفرین — ۲۵ دقیقهٔ عمیق گذشت. حالا دکمهٔ «استراحت ۵ دقیقه» را بزن.'); if(navigator.vibrate) navigator.vibrate(200);
      } else { state('استراحت تمام شد — بیا برگردیم! 🍅'); }
      return;
    }
    fmt();
  }
  function run(min, m, label){
    stop(); total = min*60; left = total; mode = m; fmt(); state(label);
    timer = setInterval(tick, 1000);
  }
  document.getElementById('pzStart').onclick = function(){ run(25, 'focus', '🍅 در حال تمرکز… گوشی را کنار بگذار!'); };
  document.getElementById('pzBreak').onclick = function(){ run(5, 'break', '☕ استراحت… نفس عمیق بکش.'); };
  document.getElementById('pzReset').onclick = function(){ stop(); total=25*60; left=total; mode='focus'; fmt(); state('آماده‌ای؟ دوباره شروع کن.'); };
  fmt();
})();
</script>

<?php elseif ($tab === 'delta'): ?>
<!-- ════════════ برگهٔ پایش تغییرات و دلتا آزمون‌ها (فاز ۳) ════════════ -->
<?php
require_once __DIR__ . '/../core/GrowthEngine.php';

$userTests = DB::all("SELECT DISTINCT t.id, t.title FROM " . DB::t('tests') . " t
                      JOIN " . DB::t('attempts') . " a ON a.test_id = t.id
                      WHERE a.user_id = ? AND a.status = 'completed'
                      ORDER BY t.title ASC", [(int)$u['id']]);

$selectedTestId = (int)($_GET['test_id'] ?? ($userTests[0]['id'] ?? 0));
$deltaData = $selectedTestId > 0 ? GrowthEngine::getDeltaComparison((int)$u['id'], $selectedTestId) : ['has_comparison' => false, 'scales' => []];
?>
<div class="fr-card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0 0 4px 0;font-size:16px">🎯 پایش تغییرات و مقایسهٔ آزمون‌های مجدد (Retest &amp; Delta Tracking)</h3>
      <p style="font-size:12.5px;color:var(--muted);margin:0">
        روند تغییرات و اثربخشی تمرین‌های خود را با مقایسهٔ آزمون‌های مجدد در گذر زمان مشاهده کنید.
      </p>
    </div>
    <?php if ($userTests): ?>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin:0">
      <input type="hidden" name="t" value="delta">
      <select class="fr-input" name="test_id" onchange="this.form.submit()" style="font-size:13px;padding:6px 12px">
        <?php foreach ($userTests as $ut): ?>
        <option value="<?= $ut['id'] ?>" <?= $selectedTestId === (int)$ut['id'] ? 'selected' : '' ?>><?= e($ut['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$userTests): ?>
  <div class="fr-card" style="text-align:center;padding:36px">
    <div style="font-size:40px;margin-bottom:12px">🧠</div>
    <h4>هنوز آزمونی تکمیل نکرده‌اید</h4>
    <p style="color:var(--muted);font-size:13px;max-width:440px;margin:8px auto 16px auto;line-height:2">
      برای سنجش ابعاد شخصیتی و پایش رشد رفتاری خود، ابتدا در یکی از آزمون‌های تخصصی شرکت کنید.
    </p>
    <a href="<?= fr_panel_url("tests.php") ?>" class="fr-btn">مشاهده و شرکت در آزمون‌ها ←</a>
  </div>
<?php elseif (!$deltaData['has_comparison']): ?>
  <div class="fr-card" style="text-align:center;padding:36px">
    <div style="font-size:40px;margin-bottom:12px">🌱</div>
    <h4>نیاز به تکرار آزمون (Retest)</h4>
    <p style="color:var(--muted);font-size:13px;max-width:520px;margin:8px auto 16px auto;line-height:2">
      شما این آزمون را یک بار انجام داده‌اید. برای محاسبهٔ دلتای رشد (&Delta;) و مقایسهٔ تغییرات شخصیتی یا رفتاری، پیشنهاد می‌شود پس از طی کردن تمرین‌های پلنر روزانه (حداقل ۲ تا ۴ هفته)، آزمون را مجدداً تکرار کنید.
    </p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:14px">
      <a href="<?= fr_panel_url("test-start.php?id=") ?><?= $selectedTestId ?>" class="fr-btn">شرکت مجدد در این آزمون (Retest)</a>
      <a href="?t=today" class="fr-btn ghost">انجام تمرین‌های امروز</a>
    </div>
  </div>
<?php else: ?>
  <div class="fr-grid cols-3" style="margin-bottom:16px">
    <div class="fr-card" style="margin:0;text-align:center">
      <small style="color:var(--muted)">تعداد دفعات آزمون</small>
      <div style="font-size:24px;font-weight:bold;color:#4338ca;margin-top:4px"><?= fa_num($deltaData['total_attempts']) ?> نوبت</div>
    </div>
    <div class="fr-card" style="margin:0;text-align:center">
      <small style="color:var(--muted)">تاریخ آزمون پایه</small>
      <div style="font-size:14px;font-weight:bold;margin-top:8px"><?= fa_num(jdate('j F Y', strtotime($deltaData['first_attempt']['created_at'] ?? 'now'))) ?></div>
    </div>
    <div class="fr-card" style="margin:0;text-align:center">
      <small style="color:var(--muted)">تاریخ آخرین آزمون</small>
      <div style="font-size:14px;font-weight:bold;color:#10b981;margin-top:8px"><?= fa_num(jdate('j F Y', strtotime($deltaData['latest_attempt']['created_at'] ?? 'now'))) ?></div>
    </div>
  </div>

  <div class="fr-card">
    <h4 style="margin:0 0 14px 0">مقایسهٔ شاخص‌ها و میزان تغییر (&Delta;)</h4>
    <div style="overflow-x:auto">
      <table class="fr-table">
        <thead>
          <tr>
            <th>شاخص / مؤلفه</th>
            <th style="text-align:center">نمرهٔ اولیه</th>
            <th style="text-align:center">نمرهٔ اخیر</th>
            <th style="text-align:center">میزان تغییرات (&Delta;)</th>
            <th>وضعیت روند</th>
            <th>تحلیل و توصیه</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deltaData['scales'] as $code => $sc):
            $d = $sc['delta'];
            $color = $d > 0 ? '#10b981' : ($d < 0 ? '#ef4444' : '#6b7280');
            $badge = $d > 0 ? '+'.fa_num($d) : ($d < 0 ? fa_num($d) : 'بدون تغییر');
          ?>
          <tr>
            <td><b><?= e($sc['title']) ?></b></td>
            <td style="text-align:center;font-weight:bold"><?= fa_num(round($sc['first_score'], 1)) ?></td>
            <td style="text-align:center;font-weight:bold"><?= fa_num(round($sc['latest_score'], 1)) ?></td>
            <td style="text-align:center">
              <span class="fr-chip" style="background:<?= $d >= 0 ? '#ecfdf5' : '#fef2f2' ?>;color:<?= $color ?>;font-weight:bold">
                <?= $badge ?>
              </span>
            </td>
            <td>
              <?php if ($sc['trend'] === 'increased'): ?>
                <span style="color:#10b981">▲ افزایش شاخص</span>
              <?php elseif ($sc['trend'] === 'decreased'): ?>
                <span style="color:#ef4444">▼ کاهش شاخص</span>
              <?php else: ?>
                <span style="color:#6b7280">■ پایدار و بدون تغییر</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--muted)">
              <?php if ($d > 0): ?>
                نشان‌دهندهٔ تقویت فعالانهٔ این شاخص در رفتارهای روزمره.
              <?php elseif ($d < 0): ?>
                نیاز به بازنگری در الگوهای واکنشی و تمرین مستمرتر.
              <?php else: ?>
                شاخص در سطح تعادل پایدار حفظ شده است.
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php
/* ۲٫۷ — مودال ویرایش کار (از لینک ✏️ در هر برگه) */
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0):
    $et = DB::one("SELECT * FROM " . DB::t('planner_tasks') . " WHERE id=? AND user_id=?", [$editId, (int)$u['id']]);
    if ($et):
?>
<style>.pl-modal{position:fixed;inset:0;background:rgba(10,25,47,.5);z-index:90;display:flex;align-items:center;justify-content:center;padding:16px}</style>
<div class="pl-modal">
  <div class="fr-card" style="max-width:480px;width:100%;margin:0;max-height:90vh;overflow:auto">
    <h3 style="margin-top:0;font-size:15px">✏️ ویرایش کار «<?= e(mb_strimwidth((string)$et['title'], 0, 40, '…')) ?>»</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="edit"><input type="hidden" name="id" value="<?= $editId ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="fr-field"><label>عنوان *</label><input class="fr-input" name="title" maxlength="190" required value="<?= e($et['title']) ?>"></div>
      <div class="pl-addgrid">
        <div class="fr-field" style="margin:0"><label>روز</label><input class="fr-input" type="date" name="day" dir="ltr" value="<?= e($et['day']) ?>"></div>
        <div class="fr-field" style="margin:0"><label>ساعت</label><input class="fr-input" name="time_note" type="text" data-t24 maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" dir="ltr" style="text-align:center" value="<?= e((string)$et['time_note']) ?>"></div>
        <div class="fr-field" style="margin:0"><label>اولویت</label>
          <select class="fr-select" name="priority"><?php foreach ($priFa as $k => $vP): ?><option value="<?= $k ?>" <?= $et['priority'] === $k ? 'selected' : '' ?>><?= $vP ?></option><?php endforeach; ?></select></div>
        <div class="fr-field" style="margin:0"><label>دسته</label>
          <select class="fr-select" name="category"><?php foreach ($catMap as $k => [$lbl, $col]): ?><option value="<?= e($k) ?>" <?= $et['category'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="fr-field"><label>یادداشت</label><textarea class="fr-input" name="note" rows="2" maxlength="500"><?= e((string)($et['note'] ?? '')) ?></textarea></div>
      <div class="fr-field"><label>🔔 یادآور (خالی = بدون یادآور)</label>
        <input class="fr-input" type="datetime-local" name="remind_at" dir="ltr" value="<?= e(!empty($et['remind_at']) ? str_replace(' ', 'T', substr((string)$et['remind_at'], 0, 16)) : '') ?>"></div>
      <div style="display:flex;gap:8px;margin-top:6px">
        <button class="fr-btn">💾 ذخیرهٔ ویرایش</button>
        <a class="fr-btn ghost" href="?t=<?= e($tab) ?>">انصراف</a>
      </div>
    </form>
  </div>
</div>
<?php endif; endif; ?>

<script>
/* ۲٫۷ — نوار انتخاب گروهی */
(function(){
  var bar = document.getElementById('plBulkBar'); if (!bar) return;
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  function sync(){
    var n = document.querySelectorAll('.pl-sel:checked').length;
    bar.style.display = n > 0 ? 'flex' : 'none';
    document.getElementById('plBulkN').textContent = String(n).replace(/\d/g, function(d){ return FA[+d]; });
  }
  document.querySelectorAll('.pl-sel').forEach(function(c){ c.addEventListener('change', sync); });
  sync();
})();

/* تیک زدن سریع با AJAX — بدون رفرش صفحه */
document.querySelectorAll('.pl-chk').forEach(function(btn){
  btn.addEventListener('click', async function(){
    const row = btn.closest('.pl-task');
    const id = row.dataset.id;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('act', 'toggle'); fd.append('id', id);
    fd.append('_csrf', '<?= csrf_token() ?>');
    try {
      const r = await fetch('<?= SITE_URL ?>/api/planner.php', {method:'POST', body:fd});
      const j = await r.json();
      if (j.ok) {
        const on = !!j.done;
        btn.classList.toggle('on', on); btn.textContent = on ? '✓' : '';
        row.classList.toggle('done', on);
        if (j.reward) toast('🏆 پاداش استریک صادر شد! کدت را در کارت جوایز ببین.','ok');
      } else { toast(j.message || 'خطا','err'); }
    } catch(e) { location.reload(); }
    btn.disabled = false;
  });
});
</script>
<?php panel_layout_end(); render_footer(); ?>
