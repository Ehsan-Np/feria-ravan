<?php
/**
 * مدیریت «برنامهٔ روزانه» از پیشخوان — نسخهٔ ۲٫۴ (یادآوری چندنوبتی ۲۴ساعته)
 * ─ آمار کلی + رهبران استریک + فعالیت امروز + پاداش‌های صادرشده (لغو قابل انجام)
 * ─ ویرایش سریع تنظیمات پلنر برای مدیران دارای دسترس «تنظیمات»
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/planner.php';
require_once __DIR__ . '/../includes/roles.php';
$adm = require_admin(['superadmin','manager']);
csrf_guard();

$today = fr_planner_today();

/* ── اقدامات ── */
$act = (string)($_POST['act'] ?? '');

/* ذخیرهٔ تنظیمات سریع پلنر — فقط مدیران دارای دسترسی «تنظیمات» */
if ($act === 'save_settings') {
    if (!fr_admin_can($adm, 'settings')) {
        flash('شما مجوز ویرایش تنظیمات کلی را ندارید.', 'err');
        redirect(SITE_URL . '/admin/planner.php');
    }
    $enabled  = (string)($_POST['planner_enabled'] ?? '1') === '1' ? '1' : '0';
    $target   = max(2, min(365, (int)en_num((string)($_POST['planner_streak_target'] ?? '7'))));
    $type     = (string)($_POST['planner_reward_type'] ?? 'discount') === 'message' ? 'message' : 'discount';
    $percent  = max(1, min(100, (int)en_num((string)($_POST['planner_reward_percent'] ?? '20'))));
    $message  = mb_substr(trim((string)($_POST['planner_reward_message'] ?? '')), 0, 300);
    /* ۲٫۴ — نوبت‌های یادآوری روز (۱ تا ۶ نوبت، ۲۴ ساعته) + سازگاری با فرم تک‌نوبتی قدیمی */
    if (isset($_POST['planner_remind_times']) || isset($_POST['planner_remind_count'])) {
        fr_planner_remind_save((int)($_POST['planner_remind_count'] ?? 1), (array)($_POST['planner_remind_times'] ?? []));
    } elseif (isset($_POST['planner_remind_time'])) {
        fr_planner_remind_save(1, [(string)$_POST['planner_remind_time']]);
    }
    /* ۲٫۷ — کانال‌های یادآوری تک‌کارها (تلگرام/بله/روبیکا/پیامک — انتخاب چندتایی مدیر) */
    $pnCh = array_values(array_intersect((array)($_POST['pn_ch'] ?? []), ['telegram', 'bale', 'rubika', 'sms']));
    DB::setSetting('planner_notify_channels', $pnCh ? implode(',', $pnCh) : 'telegram,bale,rubika');
    DB::setSetting('planner_enabled', $enabled);
    DB::setSetting('planner_streak_target', (string)$target);
    DB::setSetting('planner_reward_type', $type);
    DB::setSetting('planner_reward_percent', (string)$percent);
    DB::setSetting('planner_reward_message', $message);
    log_activity('planner_settings', 'admin', (int)$adm['id'], ['enabled' => $enabled, 'target' => $target, 'channels' => implode(',', $pnCh)]);
    flash('تنظیمات برنامهٔ روزانه ذخیره شد ✅');
    redirect(SITE_URL . '/admin/planner.php');
}

/* لغو یک پاداش صادرشده (و حذف کد تخفیفش اگر هنوز استفاده نشده) */
if ($act === 'revoke') {
    $rid = (int)($_POST['id'] ?? 0);
    $rw  = DB::one("SELECT * FROM " . DB::t('planner_rewards') . " WHERE id=?", [$rid]);
    if ($rw) {
        DB::run("DELETE FROM " . DB::t('planner_rewards') . " WHERE id=?", [$rid]);
        if (trim((string)$rw['code']) !== '') {
            /* کد تخفیف صادرشده هم باطل می‌شود (استفاده‌شده‌ها تاریخچه‌شان محفوظ است چون کد حذف «نمی‌شود» بلکه غیرفعال می‌گردد) */
            DB::run("UPDATE " . DB::t('discounts') . " SET active=0 WHERE code=?", [(string)$rw['code']]);
        }
        log_activity('planner_reward_revoke', 'admin', (int)$adm['id'], ['reward_id' => $rid, 'user_id' => (int)$rw['user_id']]);
        flash('پاداش لغو شد؛ کد تخفیف مرتبط هم غیرفعال گردید.');
    } else {
        flash('پاداش پیدا نشد.', 'err');
    }
    redirect(SITE_URL . '/admin/planner.php');
}

/* ── آمار کلی ── */
$usersToday   = (int)DB::val("SELECT COUNT(DISTINCT user_id) FROM " . DB::t('planner_tasks') . " WHERE day=?", [$today]);
$tasksToday   = DB::one("SELECT COUNT(*) AS total, COALESCE(SUM(done=1),0) AS done FROM " . DB::t('planner_tasks') . " WHERE day=?", [$today]);
$tTotal = (int)$tasksToday['total']; $tDone = (int)$tasksToday['done'];
$tPct   = $tTotal ? (int)round($tDone / $tTotal * 100) : 0;
$rewardsTotal = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_rewards'));
$rewardsMonth = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_rewards') . " WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

/* ── استریک همهٔ کاربران (۶۰ روز اخیر، محاسبهٔ حافظه‌ای بدون N+1) ── */
$rows60 = DB::all("SELECT user_id, day, COUNT(*) AS total, SUM(done=1) AS done
                   FROM " . DB::t('planner_tasks') . " WHERE day >= DATE_SUB(?, INTERVAL 60 DAY)
                   GROUP BY user_id, day", [$today]);
$maps = [];   /* user_id => [ day => bool complete ] */
foreach ($rows60 as $r) {
    $uid = (int)$r['user_id'];
    $maps[$uid][(string)$r['day']] = ((int)$r['total'] > 0 && (int)$r['done'] === (int)$r['total']);
}
$streaks = [];  /* user_id => streak */
foreach ($maps as $uid => $map) {
    $anchor = $today;
    if (empty($map[$anchor])) $anchor = date('Y-m-d', strtotime('-1 day'));
    $st = 0; $d = $anchor;
    for ($i = 0; $i < 60; $i++) {
        if (!empty($map[$d])) { $st++; $d = date('Y-m-d', strtotime($d . ' -1 day')); }
        else break;
    }
    if ($st > 0) $streaks[$uid] = $st;
}
arsort($streaks);
$topStreak = $streaks ? max($streaks) : 0;

/* اطلاعات کاربران لیدربورد */
$leadIds = array_slice(array_keys($streaks), 0, 20);
$leadUsers = [];
if ($leadIds) {
    $in = implode(',', array_map('intval', $leadIds));
    foreach (DB::all("SELECT id, first_name, last_name, mobile FROM " . DB::t('users') . " WHERE id IN ($in)") as $u) {
        $leadUsers[(int)$u['id']] = $u;
    }
}

/* ── فعالیت امروز: کاربرانی که امروز تسک دارند ── */
$todayRows = DB::all("SELECT t.user_id, COUNT(*) AS total, SUM(t.done=1) AS done,
                             u.first_name, u.last_name, u.mobile
                      FROM " . DB::t('planner_tasks') . " t
                      LEFT JOIN " . DB::t('users') . " u ON u.id = t.user_id
                      WHERE t.day=? GROUP BY t.user_id
                      ORDER BY done DESC, total DESC LIMIT 40", [$today]);

/* ── پاداش‌های صادرشده ── */
$rewards = DB::all("SELECT r.*, u.first_name, u.last_name, u.mobile
                    FROM " . DB::t('planner_rewards') . " r
                    LEFT JOIN " . DB::t('users') . " u ON u.id = r.user_id
                    ORDER BY r.id DESC LIMIT 30");

/* وضعیت کران یادآور امروز */
$remindSlots  = fr_planner_remind_slots();
$remindStatus = fr_planner_remind_status_today();
$remindCount  = (int)DB::setting('planner_remind_count', (string)max(1, count($remindSlots)));
$cronUrl = SITE_URL . '/cron/reminders.php?key=' . rawurlencode((string)DB::setting('cron_secret','')) . '&force=1';

$s = fn(string $k, string $d = '') => (string)DB::setting($k, $d);
$canSettings = fr_admin_can($adm, 'settings');

admin_layout($adm, 'planner', 'برنامهٔ روزانهٔ کاربران');
?>
<?= flash() ?>

<div class="fr-grid cols-4" style="margin-bottom:16px">
  <?php stat_card('🗓', 'کاربران فعال امروز', fa_num($usersToday), '#eaf0f8'); ?>
  <?php stat_card('✅', 'کارهای امروز (انجام/کل)', fa_num($tDone . ' از ' . $tTotal) . ' — ' . fa_num($tPct) . '٪', '#e3f7ef'); ?>
  <?php stat_card('🔥', 'بیشترین استریک فعلی', fa_num($topStreak) . ' روز', '#fff3d6'); ?>
  <?php stat_card('🎁', 'پاداش‌های صادرشده', fa_num($rewardsTotal) . ' (' . fa_num($rewardsMonth) . ' در ۳۰ روز)', '#ffe9ef'); ?>
</div>

<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h3 style="margin:0">⚙️ تنظیمات سریع برنامهٔ روزانه</h3>
    <span class="chip <?= $s('planner_enabled','1') === '1' ? 'ok' : 'err' ?>">
      <?= $s('planner_enabled','1') === '1' ? 'فعال — در پنل کاربران نمایش داده می‌شود' : 'غیرفعال — از پنل کاربران پنهان است' ?>
    </span>
  </div>
  <?php if ($canSettings): ?>
  <form method="post" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="act" value="save_settings">
    <div class="fr-grid cols-3">
      <div class="fr-field"><label>وضعیت برنامهٔ روزانه</label>
        <select class="fr-select" name="planner_enabled">
          <option value="1" <?= $s('planner_enabled','1')==='1'?'selected':'' ?>>فعال</option>
          <option value="0" <?= $s('planner_enabled','1')==='0'?'selected':'' ?>>غیرفعال</option>
        </select></div>
      <div class="fr-field"><label>هدف استریک (روز متوالی)</label>
        <input class="fr-input" name="planner_streak_target" value="<?= e($s('planner_streak_target','7')) ?>" style="direction:ltr;max-width:120px" inputmode="numeric"></div>
      <div class="fr-field"><label>نوع پاداش</label>
        <select class="fr-select" name="planner_reward_type">
          <option value="discount" <?= $s('planner_reward_type','discount')==='discount'?'selected':'' ?>>کد تخفیف خودکار (مخصوص همان کاربر)</option>
          <option value="message" <?= $s('planner_reward_type','discount')==='message'?'selected':'' ?>>فقط پیام تشویقی</option>
        </select></div>
      <div class="fr-field"><label>درصد تخفیف پاداش</label>
        <input class="fr-input" name="planner_reward_percent" value="<?= e($s('planner_reward_percent','20')) ?>" style="direction:ltr;max-width:120px" inputmode="numeric"></div>
      <div class="fr-field"><label>پیام تشویقی (اگر نوع «پیام» باشد)</label>
        <input class="fr-input" name="planner_reward_message" value="<?= e($s('planner_reward_message')) ?>" maxlength="300"></div>
      <div class="fr-field"><label>تعداد یادآوری در روز</label>
        <select class="fr-select" name="planner_remind_count" id="prCount">
          <?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>" <?= $remindCount === $i ? 'selected' : '' ?>><?= fa_num($i) ?> نوبت در روز</option><?php endfor; ?>
        </select>
        <small class="hint">مثلاً ۴ نوبت: صبح، ظهر، عصر و شب.</small></div>
      <div class="fr-field" style="grid-column:1/-1"><label>ساعت هر نوبت یادآوری <small>(قالب ۲۴ ساعته کامل)</small></label>
        <div id="prTimes" data-t24-wrap data-t24-count="#prCount" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <?php for ($i = 0; $i < 6; $i++): ?>
          <span data-t24-item style="display:inline-flex;align-items:center;gap:6px">
            <small style="color:var(--muted)"><?= fa_num($i + 1) ?>.</small>
            <input class="fr-input" name="planner_remind_times[]" type="text" data-t24 inputmode="numeric" maxlength="5"
                   pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="08:30" dir="ltr" style="max-width:105px;text-align:center"
                   value="<?= e($remindSlots[$i] ?? '') ?>">
          </span>
          <?php endfor; ?>
        </div>
        <small class="hint">ساعت‌ها را ۲۴ ساعته بنویسید (مثل ۰۷:۳۰ یا ۲۱:۱۵)؛ هر نوبت فقط یک‌بار در روز و در بازهٔ ساعتِ خودش برای کاربرانی که به تلگرام/بله متصل‌اند ارسال می‌شود.</small></div>
      <div class="fr-field" style="grid-column:1/-1"><label>🔔 کانال‌های یادآوری تک‌کارها <small>۲٫۷ — برای یادآور «remind_at» هر کار</small></label>
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;background:#f7faff;border:1.5px dashed var(--line);border-radius:12px;padding:10px 12px">
          <?php $pnSel = fr_planner_notify_channels(); ?>
          <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer"><input type="checkbox" name="pn_ch[]" value="telegram" <?= in_array('telegram', $pnSel, true) ? 'checked' : '' ?>> ✈️ تلگرام</label>
          <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer"><input type="checkbox" name="pn_ch[]" value="bale" <?= in_array('bale', $pnSel, true) ? 'checked' : '' ?>> 🟣 بله</label>
          <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer"><input type="checkbox" name="pn_ch[]" value="rubika" <?= in_array('rubika', $pnSel, true) ? 'checked' : '' ?>> 🟪 روبیکا</label>
          <label style="display:flex;gap:6px;align-items:center;font-size:13px;cursor:pointer"><input type="checkbox" name="pn_ch[]" value="sms" <?= in_array('sms', $pnSel, true) ? 'checked' : '' ?>> 📩 پیامک</label>
          <?php
            $dueN = 0;
            try { $dueN = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE remind_at IS NOT NULL AND reminded_at IS NULL AND done=0 AND remind_at <= NOW()"); } catch (Throwable $e) {}
            if ($dueN > 0): ?>
          <span class="chip warn">⏰ <?= fa_num($dueN) ?> یادآور موعد‌گذشته در صف ارسال است</span>
          <?php endif; ?>
        </div>
        <small class="hint">وقتی کاربر برای یک کار «یادآور» می‌گذارد، سر ساعتش از کانال‌های تیک‌خورده اطلاع‌رسانی می‌شود؛ پیامک نیازمند فعال‌بودن کاوه‌نگار در تنظیمات اطلاع‌رسانی است. پیش‌فرض: تلگرام و بله.</small></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap">
      <button class="fr-btn">💾 ذخیره تنظیمات</button>
      <a class="fr-btn ghost sm" href="<?= SITE_URL ?>/admin/settings.php?tab=planner">تنظیمات کامل‌تر (بات‌ها و قالب‌ها) ↗</a>
    </div>
  </form>
  <?php else: ?>
  <p class="muted" style="margin-top:10px">برای ویرایش تنظیمات، دسترسی «تنظیمات کلی سایت» لازم است. <a href="<?= SITE_URL ?>/admin/settings.php?tab=planner">برگهٔ تنظیمات ↗</a></p>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin-bottom:0">
    یادآورهای امروز (<?= fa_num(count($remindSlots)) ?> نوبت):
    <?php foreach ($remindStatus as $rs): ?>
      <span class="chip" style="<?= $rs['sent'] ? 'background:#e3f7ef;color:#0c7a4d' : '' ?>"><?= $rs['sent'] ? '✅' : '⏳' ?> <?= fa_num($rs['time']) ?></span>
    <?php endforeach; ?>
    — نشانی کران برای تنظیم در هاست: <code dir="ltr" style="font-size:11px;user-select:all"><?= e($cronUrl) ?></code>
    <br>📣 نسخهٔ ۲٫۳: برای اتصال خودکار نام کاربری کاربران (تلگرام/بله) و اطلاع‌رسانی‌های زمان‌بندی‌شده،
    <a href="<?= SITE_URL ?>/admin/notifications.php#cron">راهنمای کرون cPanel را این‌جا ببینید ↗</a>
    (توصیه: یک کرونِ ۵‌دقیقه‌ای روی cron/notify.php — ساعت یادآور بالا با همان قالب ۲۴ ساعته است.)
  </p>
</div>

<div class="fr-grid cols-2">
  <!-- لیدربورد استریک -->
  <div class="fr-card" style="margin:0">
    <h3>🏆 رهبران استریک (روزهای متوالی کامل)</h3>
    <?php if ($leadIds): ?>
    <div class="tbl-wrap"><table class="fr-table">
      <thead><tr><th>#</th><th>کاربر</th><th>موبایل</th><th>استریک</th></tr></thead>
      <tbody>
      <?php $i = 1; foreach ($leadIds as $uid): $lu = $leadUsers[$uid] ?? null; ?>
        <tr>
          <td><?= fa_num($i++) ?></td>
          <td><b><?= e($lu ? trim($lu['first_name'] . ' ' . $lu['last_name']) ?: '—' : 'کاربر #' . fa_num($uid)) ?></b></td>
          <td dir="ltr"><?= e($lu['mobile'] ?? '—') ?></td>
          <td><span class="chip" style="background:#fff3d6;color:#8a5a00">🔥 <?= fa_num($streaks[$uid]) ?> روز</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <p class="muted">هنوز کاربری استریک فعال ندارد؛ روزِ کامل = حداقل یک کار که همهٔ کارهای همان روز انجام شده باشند.</p>
    <?php endif; ?>
  </div>

  <!-- فعالیت امروز -->
  <div class="fr-card" style="margin:0">
    <h3>📋 فعالیت امروز (<?= fa_num(jdate('l j F Y')) ?>)</h3>
    <?php if ($todayRows): ?>
    <div class="tbl-wrap"><table class="fr-table">
      <thead><tr><th>کاربر</th><th>موبایل</th><th>پیشرفت امروز</th></tr></thead>
      <tbody>
      <?php foreach ($todayRows as $tr): $full = (int)$tr['total'] > 0 && (int)$tr['done'] === (int)$tr['total']; ?>
        <tr>
          <td><b><?= e(trim(($tr['first_name'] ?? '') . ' ' . ($tr['last_name'] ?? '')) ?: '—') ?></b></td>
          <td dir="ltr"><?= e($tr['mobile'] ?? '—') ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;max-width:110px;height:8px;background:#e8edf7;border-radius:99px;overflow:hidden">
                <i style="display:block;height:100%;width:<?= (int)$tr['total'] ? (int)round($tr['done']/$tr['total']*100) : 0 ?>%;background:<?= $full ? '#22b07d' : 'var(--c-primary)' ?>;border-radius:99px"></i>
              </div>
              <small><?= fa_num((int)$tr['done'] . '/' . (int)$tr['total']) ?></small>
              <?= $full ? '<span title="روز کامل">✅</span>' : '' ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <p class="muted">امروز هنوز هیچ کاربری کاری در برنامهٔ خود ثبت نکرده است.</p>
    <?php endif; ?>
  </div>
</div>

<!-- پاداش‌های صادرشده -->
<div class="fr-card">
  <h3>🎁 پاداش‌های صادرشده (۳۰ مورد اخیر)</h3>
  <?php if ($rewards): ?>
  <div class="tbl-wrap"><table class="fr-table">
    <thead><tr><th>کاربر</th><th>استریک</th><th>نوع</th><th>کد تخفیف</th><th>تاریخ صدور</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rewards as $rw): ?>
      <tr>
        <td><b><?= e(trim(($rw['first_name'] ?? '') . ' ' . ($rw['last_name'] ?? '')) ?: ('کاربر #' . fa_num((int)$rw['user_id']))) ?></b><br>
            <small class="muted" dir="ltr"><?= e($rw['mobile'] ?? '') ?></small></td>
        <td><?= fa_num((int)$rw['streak_from'] . ' تا ' . (int)$rw['streak_to']) ?> روز</td>
        <td><?= $rw['type'] === 'discount' ? '🏷 تخفیف ' . fa_num((int)$rw['percent']) . '٪' : '💌 پیام تشویقی' ?></td>
        <td dir="ltr"><b><?= e($rw['code'] ?: '—') ?></b></td>
        <td><?= fa_num(jdate('j F Y — H:i', strtotime($rw['created_at']))) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('پاداش این کاربر لغو و کد تخفیفش غیرفعال شود؟')">
            <?= csrf_field() ?><input type="hidden" name="act" value="revoke"><input type="hidden" name="id" value="<?= (int)$rw['id'] ?>">
            <button class="fr-btn sm danger">لغو</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
  <p class="muted">هنوز پاداشی صادر نشده است؛ پس از رسیدن کاربران به هدف استریک، این‌جا فهرست می‌شود.</p>
  <?php endif; ?>
</div>

<?php admin_layout_end(); ?>
