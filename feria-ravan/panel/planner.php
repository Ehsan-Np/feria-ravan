<?php
/**
 * فریا روان — برنامهٔ روزانه و پایش رشد روان‌شناختی مراجع — نگارش ۴٫۹٫۰ (۱۴۰۵)
 * ─ تقویم کاملاً هجری شمسی سال ۱۴۰۵ با شروع هفته از شنبه
 * ─ بازنویسی کامل و رفع ریشه‌ای کلیه پیوندها و ناوبری‌ها به صورت Canonical
 * ─ پایش حس و حال روانی (Mood Check-in) و ماتریس اولویت‌بندی روان‌شناختی آیزنهاور
 * ─ نمای هفتهٔ ۱۴۰۵، اتاق تمرکز عمیق (پومودورو)، استریک متوالی، پاداش‌ها و دلتای رشد آزمون‌ها
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/planner.php';
require_once __DIR__ . '/../includes/snotify.php';
fr_bootstrap();

$u = require_user();
if (!fr_planner_enabled()) {
    redirect(fr_panel_url());
}

$today = fr_planner_today();
$todayShamsi = fr_planner_shamsi_today();
$todayFormatted = fr_planner_format_day($today);
$target = max(1, (int)DB::setting('planner_streak_target', '7'));

/* هلپر پیوند استاندارد و مطلق پلنر */
$pUrl = function (string $extra = ''): string {
    return fr_panel_url('planner.php' . ($extra !== '' ? (str_starts_with($extra, '?') ? $extra : '?' . $extra) : ''));
};

/* پیام فلش داخلی */
$plFlash = function (?string $m = null) {
    if ($m !== null) {
        $_SESSION['pl_flash'] = $m;
        return null;
    }
    if (!empty($_SESSION['pl_flash'])) {
        $x = (string)$_SESSION['pl_flash'];
        unset($_SESSION['pl_flash']);
        return '<div class="fr-alert ok" style="margin-bottom:16px;border-radius:12px;box-shadow:0 2px 8px rgba(16,185,129,.15)">' . e($x) . '</div>';
    }
    return '';
};

$catMap = fr_planner_cat_map((int)$u['id']);

/* ── پردازش اقدامات فرم‌ها (POST) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)($_POST['act'] ?? '');
    $backTab = (string)($_POST['tab'] ?? 'today');
    $redir = $pUrl($backTab !== 'today' ? 't=' . urlencode($backTab) : '');

    // ۱) افزودن کار جدید با تاریخ شمسی ۱۴۰۵
    if ($act === 'add') {
        $rawDay = (string)($_POST['day'] ?? $today);
        if ($rawDay === 'custom') {
            $rawDay = (string)($_POST['day_custom'] ?? $today);
        }
        $normDay = fr_planner_normalize_day($rawDay);
        $title = (string)($_POST['title'] ?? '');
        $timeNote = (string)($_POST['time_note'] ?? '');
        $priority = (string)($_POST['priority'] ?? 'normal');
        $category = (string)($_POST['category'] ?? 'personal');
        $note = (string)($_POST['note'] ?? '');

        $remindAt = null;
        $rawRemind = trim((string)($_POST['remind_at'] ?? ($_POST['remind_time'] ?? '')));
        if ($rawRemind !== '') {
            $rawRemind = en_num($rawRemind);
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $rawRemind, $tm)) {
                $remindAt = sprintf('%s %02d:%02d:00', $normDay, (int)$tm[1], (int)$tm[2]);
            } else {
                $remindAt = fr_planner_valid_dt($rawRemind);
            }
        }

        $id = fr_planner_add((int)$u['id'], $normDay, $title, $timeNote, $priority, $category, $note, $remindAt);
        if ($id > 0) {
            $isFuture = $normDay > $today;
            $msg = $isFuture
                ? 'برنامه برای تاریخ ' . fa_num(jdate('l j F ۱۴۰۵', strtotime($normDay))) . ' با موفقیت ثبت شد 🗓'
                : 'برنامهٔ جدید با موفقیت به تقویم امروز اضافه شد ✅';
        } else {
            $msg = 'لطفاً عنوان برنامه را وارد کنید.';
        }
        $plFlash($msg);
        redirect($redir);
    }

    // ۲) ویرایش کار
    if ($act === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $ok = fr_planner_update((int)$u['id'], $id, $_POST);
        $plFlash($ok ? 'برنامه با موفقیت ویرایش و ذخیره شد ✏️' : 'ویرایش ناموفق بود.');
        redirect($redir);
    }

    // ۳) عملیات گروهی
    if ($act === 'bulk') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $op  = (string)($_POST['op'] ?? 'done');
        $n = fr_planner_bulk((int)$u['id'], $ids, $op === 'delete' ? 'delete' : ($op === 'undone' ? 'undone' : 'done'));
        if ($op === 'done') fr_planner_check_reward((int)$u['id']);
        $plFlash($ids ? ('عملیات گروهی روی ' . fa_num($n) . ' مورد انجام شد ✅') : 'موردی انتخاب نشده است.');
        redirect($redir);
    }

    // ۴) انتقال مانده‌های قبلی به امروز
    if ($act === 'carry') {
        $n = fr_planner_carry_over((int)$u['id']);
        $plFlash($n > 0 ? (fa_num($n) . ' برنامهٔ ناتمام قبلی به تقویم امروز منتقل شد 📥') : 'برنامهٔ ناتمامی از روزهای گذشته ندارید — عالی!');
        redirect($pUrl('t=today'));
    }

    // ۵) حذف تکی کار
    if ($act === 'del') {
        fr_planner_delete((int)$u['id'], (int)($_POST['id'] ?? 0));
        $plFlash('برنامه حذف شد.');
        redirect($redir);
    }

    // ۶) ایجاد دسته سفارشی
    if ($act === 'cat_add') {
        $nc = fr_planner_cat_add((int)$u['id'], (string)($_POST['name'] ?? ''), (string)($_POST['color'] ?? '#1e3a6e'));
        $plFlash($nc ? 'دسته‌بندی جدید با موفقیت ساخته شد 🏷' : 'نام دسته‌بندی تکراری یا خالی است.');
        redirect($pUrl('t=cats'));
    }

    // ۷) حذف دسته سفارشی
    if ($act === 'cat_del') {
        fr_planner_cat_delete((int)$u['id'], (int)($_POST['id'] ?? 0));
        $plFlash('دسته‌بندی حذف شد.');
        redirect($pUrl('t=cats'));
    }

    // ۸) کارهای تکرارشونده و عادت‌ها
    if ($act === 'recur_add') {
        $id = fr_planner_recur_add(
            (int)$u['id'],
            (string)($_POST['title'] ?? ''),
            (string)($_POST['time_note'] ?? ''),
            (string)($_POST['priority'] ?? 'normal'),
            (string)($_POST['category'] ?? 'personal'),
            (string)($_POST['freq'] ?? 'daily')
        );
        $plFlash($id ? 'عادت و برنامهٔ تکرارشونده ثبت شد 🔁' : 'عنوان عادت را بنویسید.');
        redirect($pUrl('t=recur'));
    }
    if ($act === 'recur_del' || $act === 'recur_toggle') {
        $rid = (int)($_POST['id'] ?? 0);
        if ($act === 'recur_del') {
            DB::run("DELETE FROM " . DB::t('planner_recur') . " WHERE id=? AND user_id=?", [$rid, (int)$u['id']]);
            $plFlash('عادت تکرارشونده حذف شد.');
        } else {
            DB::run("UPDATE " . DB::t('planner_recur') . " SET active=1-active WHERE id=? AND user_id=?", [$rid, (int)$u['id']]);
            $plFlash('وضعیت عادت به‌روزرسانی شد.');
        }
        redirect($pUrl('t=recur'));
    }

    // ۹) ثبت حس و حال روانی امروز (Mood Check-in)
    if ($act === 'mood_save') {
        $mDay = fr_planner_normalize_day((string)($_POST['day'] ?? $today));
        $mood = (string)($_POST['mood'] ?? 'good');
        $energy = (int)($_POST['energy'] ?? 3);
        $reflection = (string)($_POST['reflection'] ?? '');
        fr_planner_mood_save((int)$u['id'], $mDay, $mood, $energy, $reflection);
        $plFlash('ارزیابی روانی و حس و حال امروز شما با موفقیت ثبت شد 🌿');
        redirect($redir);
    }

    redirect($redir);
}

/* ساخت خودکار برنامه‌های تکرارشونده برای امروز */
fr_planner_ensure_day((int)$u['id'], $today);

/* مدیریت برگه‌ها (Tabs) */
$tabRaw = (string)($_GET['t'] ?? 'today');
$allowedTabs = ['today', 'week', 'all', 'recur', 'cats', 'stats', 'badges', 'focus', 'delta'];
$tab = in_array($tabRaw, $allowedTabs, true) ? $tabRaw : 'today';

$tasks = fr_planner_day_tasks((int)$u['id'], $today);
$week = fr_planner_week((int)$u['id']);
$streak = fr_planner_current_streak((int)$u['id']);
$rewards = fr_planner_rewards((int)$u['id']);
$currentMood = fr_planner_mood_get((int)$u['id'], $today);

$doneCnt = count(array_filter($tasks, fn($t) => (int)$t['done'] === 1));
$allDone = count($tasks) > 0 && $doneCnt === count($tasks);
$todayPct = count($tasks) ? (int)round($doneCnt / count($tasks) * 100) : 0;
$targetPct = min(100, (int)round($streak / $target * 100));

$priFa = [
    'high'   => 'فوری و مهم 🔴',
    'normal' => 'مهم و رشد فردی 🟡',
    'low'    => 'عادی و سبک ⚪',
];

$freqFa = [
    'daily'   => 'هر روز (۷ روز هفته)',
    'weekday' => 'روزهای کاری (شنبه تا پنجشنبه)',
    'weekly'  => 'هفتگی (یک روز در هفته)',
];

$moodFa = [
    'great'   => ['عالی و پرانرژی 🌟', '#059669', 'خلق در اوج انگیزه'],
    'good'    => ['خوب و آرام 🌿', '#2563eb', 'تعادل روانی پایدار'],
    'neutral' => ['معمولی و متمرکز ⚖️', '#4b5563', 'آرامش در انجام وظایف'],
    'tired'   => ['خسته یا کم‌رمق 🥱', '#d97706', 'نیاز به استراحت و تنفس'],
    'stressed'=> ['پراسترس یا مضطرب 🌪', '#dc2626', 'نیاز به تمرکز و خودآگاهی'],
];

$oldCnt = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=0 AND day<?", [(int)$u['id'], $today]);
$hasMsgBot = trim((string)$u['telegram_chat_id']) !== '' || trim((string)$u['bale_chat_id']) !== '' || trim((string)($u['rubika_chat_id'] ?? '')) !== '';

/* رندر هدر صفحه */
render_header('برنامهٔ روزانه ۱۴۰۵ — ' . $todayFormatted);
panel_layout($u, 'planner');
echo (string)$plFlash();

/* تعریف عناوین برگه‌ها */
$TABS = [
    'today'  => '🗓 امروز (' . fa_num(jdate('j F')) . ')',
    'week'   => '📅 تقویم هفتگی ۱۴۰۵',
    'all'    => '🔎 همهٔ برنامه‌ها',
    'recur'  => '🔁 عادت‌ها و برنامه‌های دوره‌ای',
    'cats'   => '🏷 دسته‌بندی‌ها',
    'stats'  => '📈 آمار و روان‌سنجی عملکرد',
    'badges' => '🏅 مدال‌ها و جوایز',
    'focus'  => '⏱ تمرکز عمیق (پومودورو)',
    'delta'  => '🎯 پایش رشد آزمون‌ها',
];
?>

<!-- نوار ناوبری برگه‌های پلنر -->
<div class="pl-tabs" style="display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-bottom:18px;border-bottom:1px solid var(--line)">
  <?php foreach ($TABS as $k => $lbl): ?>
  <a class="pl-tab <?= $tab === $k ? 'on' : '' ?>" href="<?= e($pUrl('t=' . $k)) ?>" style="white-space:nowrap;padding:9px 16px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;<?= $tab === $k ? 'background:#1e3a6e;color:#fff' : 'background:#f1f5f9;color:#334155' ?>">
    <?= $lbl ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- کارت سربرگ تقویم خورشیدی سال ۱۴۰۵ و نشانگر استریک -->
<div class="fr-card" style="margin-bottom:18px;background:linear-gradient(135deg, #1e3a6e 0%, #2e599b 100%);color:#fff;border:none;border-radius:16px;padding:20px 24px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
        <span style="background:rgba(255,255,255,.2);padding:4px 12px;border-radius:8px;font-size:12px;font-weight:600">تقویم رسمی سال ۱۴۰۵</span>
        <span style="font-size:13px;opacity:.9">⏰ منطقه زمانی: تهران (Asia/Tehran)</span>
      </div>
      <h2 style="margin:0 0 4px 0;font-size:20px;color:#fff"><?= fa_num($todayFormatted) ?></h2>
      <p style="margin:0;font-size:13px;opacity:.85">گام‌های مستمر و کوچک امروز، تحول بزرگ روان‌شناختی فردا را رقم می‌زنند.</p>
    </div>

    <!-- استریک و پیشرفت امروز -->
    <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">
      <div style="background:rgba(255,255,255,.12);padding:10px 16px;border-radius:12px;text-align:center;min-width:110px">
        <div style="font-size:11px;opacity:.85">استریک پیوستگی</div>
        <div style="font-size:22px;font-weight:bold;margin:2px 0"><?= $streak >= $target ? '🏆 ' : ($streak > 0 ? '🔥 ' : '🌱 ') ?><?= fa_num($streak) ?> <small style="font-size:12px;font-weight:normal">روز</small></div>
        <div style="font-size:10.5px;opacity:.8">هدف پاداش: <?= fa_num($target) ?> روز</div>
      </div>
      <div style="background:rgba(255,255,255,.12);padding:10px 16px;border-radius:12px;text-align:center;min-width:110px">
        <div style="font-size:11px;opacity:.85">پیشرفت کارهای امروز</div>
        <div style="font-size:22px;font-weight:bold;margin:2px 0"><?= fa_num($todayPct) ?>٪</div>
        <div style="font-size:10.5px;opacity:.8"><?= fa_num($doneCnt) ?> از <?= fa_num(count($tasks)) ?> انجام شد</div>
      </div>
    </div>
  </div>
</div>

<?php if ($tab === 'today'): ?>
<!-- ════════════ برگهٔ ۱: برنامهٔ امروز و پایش خلق ۱۴۰۵ ════════════ -->
<div class="fr-grid cols-3" style="gap:18px;align-items:start">
  <!-- ستون اصلی: کارهای امروز -->
  <div style="grid-column: span 2">
    <div class="fr-card" style="margin-bottom:18px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:10px">
        <h3 style="margin:0;font-size:16px;display:flex;align-items:center;gap:8px">
          <span>🗓 برنامه‌های امروز</span>
          <span style="font-size:12px;background:#e2e8f0;color:#334155;padding:2px 8px;border-radius:6px"><?= fa_num($todayShamsi) ?></span>
        </h3>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?php if ($oldCnt > 0): ?>
          <form method="post" action="<?= e($pUrl('t=today')) ?>" style="margin:0" onsubmit="return confirm('<?= fa_num($oldCnt) ?> برنامهٔ ناتمام قبلی به امروز منتقل شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="carry">
            <input type="hidden" name="tab" value="today">
            <button class="fr-btn sm ghost" style="color:#b45309;border-color:#f59e0b" title="انتقال برنامه‌های عقب‌مانده به امروز">
              📥 انتقال <?= fa_num($oldCnt) ?> کار معوقه به امروز
            </button>
          </form>
          <?php endif; ?>
          <span class="fr-chip" style="<?= $allDone ? 'background:#ecfdf5;color:#047857' : 'background:#f8fafc;color:#64748b' ?>">
            <?= $tasks ? (fa_num($doneCnt) . ' از ' . fa_num(count($tasks)) . ' برنامه تکمیل شد') : 'هنوز برنامه‌ای ثبت نشده' ?>
          </span>
        </div>
      </div>

      <?php if ($allDone): ?>
      <div class="fr-alert ok" style="margin-bottom:14px;font-size:13px;border-radius:10px">
        🎉 آفرین! تمام برنامه‌های امروز در تقویم ۱۴۰۵ کامل شد — این روز در استریک طلایی شما ثبت گردید.
      </div>
      <?php endif; ?>

      <!-- نوار عملیات گروهی -->
      <form method="post" action="<?= e($pUrl('t=today')) ?>" id="plBulkBar" style="display:none;gap:10px;align-items:center;flex-wrap:wrap;background:#f0f7ff;border:1.5px dashed #93c5fd;border-radius:12px;padding:10px 14px;margin-bottom:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="bulk">
        <input type="hidden" name="tab" value="today">
        <span style="font-size:12.5px;font-weight:bold;color:#1e3a6e"><span id="plBulkN">۰</span> برنامه انتخاب شده:</span>
        <select class="fr-select sm" name="op" style="max-width:160px">
          <option value="done">✅ علامت انجام‌شده</option>
          <option value="undone">↩ برگشت به ناتمام</option>
          <option value="delete">🗑 حذف گروهی</option>
        </select>
        <button class="fr-btn sm">اجرای عملیات</button>
      </form>

      <!-- فهرست کارهای امروز -->
      <div id="plList" style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($tasks as $t):
          [$catLbl, $catColor] = $catMap[$t['category']] ?? [$t['category'], '#64748b'];
          $isDone = (int)$t['done'] === 1;
        ?>
        <div class="pl-task <?= $isDone ? 'done' : '' ?>" data-id="<?= (int)$t['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:1.5px solid <?= $isDone ? '#e2e8f0' : 'var(--line)' ?>;border-radius:12px;background:<?= $isDone ? '#f8fafc' : '#fff' ?>;transition:all .2s">
          <input type="checkbox" class="pl-sel" name="ids[]" form="plBulkBar" value="<?= (int)$t['id'] ?>" title="انتخاب برای عملیات گروهی" style="width:17px;height:17px;cursor:pointer">
          <button type="button" class="pl-chk <?= $isDone ? 'on' : '' ?>" title="<?= $isDone ? 'علامت به عنوان ناتمام' : 'علامت به عنوان انجام‌شده' ?>" style="width:26px;height:26px;border-radius:7px;border:1.5px solid <?= $isDone ? '#059669' : '#94a3b8' ?>;background:<?= $isDone ? '#10b981' : '#fff' ?>;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:bold">
            <?= $isDone ? '✓' : '' ?>
          </button>
          <div style="flex:1;min-width:0">
            <div style="font-size:13.5px;font-weight:600;<?= $isDone ? 'text-decoration:line-through;color:#94a3b8' : 'color:#0f172a' ?>">
              <?= e($t['title']) ?>
              <?php if (!empty($t['recur_id'])): ?><span title="برنامه دوره‌ای و تکرارشونده" style="font-size:12px;margin-right:4px">🔁</span><?php endif; ?>
              <?php if (!empty($t['ref_attempt_id'])): ?><span title="تمرین رشد متصل به کارنامه" style="font-size:12px;margin-right:4px;color:#7c3aed">🧠</span><?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px;font-size:11px">
              <?php if ($t['time_note']): ?><span style="color:#64748b">⏰ <?= fa_num($t['time_note']) ?></span><?php endif; ?>
              <span style="color:<?= $t['priority']==='high' ? '#dc2626' : ($t['priority']==='normal' ? '#d97706' : '#64748b') ?>"><?= $priFa[$t['priority']] ?? 'عادی' ?></span>
              <span class="chip" style="border-right:3px solid <?= e($catColor) ?>;padding:1px 6px;border-radius:4px;background:#f1f5f9;font-size:10.5px"><?= e($catLbl) ?></span>
              <?php if (!empty($t['remind_at'])): ?>
                <span title="یادآوری زمان‌بندی‌شده" style="color:#4338ca">🔔 <?= fa_num(jdate('j F — H:i', strtotime((string)$t['remind_at']))) ?><?= !empty($t['reminded_at']) ? ' ✅ ارسال شد' : '' ?></span>
              <?php endif; ?>
            </div>
            <?php if (trim((string)($t['note'] ?? '')) !== ''): ?>
            <div style="font-size:11.5px;color:#64748b;margin-top:4px;line-height:1.8">
              📝 <?= e(mb_strimwidth((string)$t['note'], 0, 140, '…')) ?>
            </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:6px">
            <a class="fr-btn sm ghost" href="<?= e($pUrl('t=today&edit=' . (int)$t['id'])) ?>" title="ویرایش برنامه">✏️</a>
            <form method="post" action="<?= e($pUrl('t=today')) ?>" style="margin:0" onsubmit="return confirm('این برنامه حذف شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="del">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <input type="hidden" name="tab" value="today">
              <button class="fr-btn sm danger" title="حذف">✕</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if (!$tasks): ?>
        <div style="text-align:center;padding:34px 16px;background:#f8fafc;border-radius:12px;border:1px dashed #cbd5e1">
          <div style="font-size:32px;margin-bottom:8px">🌅</div>
          <div style="font-weight:600;color:#334155;margin-bottom:4px">روزتان پر از انگیزه و آرامش!</div>
          <div style="font-size:12.5px;color:#64748b;max-width:420px;margin:0 auto">
            هنوز برنامه‌ای برای امروز ثبت نکرده‌اید. با افزودن اولین هدف از فرم زیر، مسیر موفقیت و استریک طلایی خود را شروع کنید.
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- فرم افزودن برنامه با تقویم شمسی سال ۱۴۰۵ -->
      <div style="margin-top:20px;padding-top:16px;border-top:1.5px dashed var(--line)">
        <h4 style="margin:0 0 12px 0;font-size:14px;color:#1e3a6e">➕ ثبت برنامهٔ جدید در تقویم ۱۴۰۵</h4>
        <form method="post" action="<?= e($pUrl('t=today')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="add">
          <input type="hidden" name="tab" value="today">

          <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;margin-bottom:10px" class="pl-addgrid">
            <div>
              <label style="font-size:11.5px;color:#475569;margin-bottom:4px;display:block">عنوان برنامه یا تمرین *</label>
              <input class="fr-input" name="title" maxlength="190" required placeholder="مثلاً: ۳۰ دقیقه تمرین تنفس عمیق یا مطالعه کتاب">
            </div>
            <div>
              <label style="font-size:11.5px;color:#475569;margin-bottom:4px;display:block">ساعت شروع (قالب ۲۴ ساعته)</label>
              <input class="fr-input" name="time_note" type="text" data-t24 maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="08:30" dir="ltr" style="text-align:center">
            </div>
            <div>
              <label style="font-size:11.5px;color:#475569;margin-bottom:4px;display:block">اولویت روان‌شناختی</label>
              <select class="fr-select" name="priority">
                <?php foreach ($priFa as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px" class="pl-addgrid">
            <div>
              <label style="font-size:11.5px;color:#475569;margin-bottom:4px;display:block">دسته‌بندی</label>
              <select class="fr-select" name="category">
                <?php foreach ($catMap as $k => [$lbl, $col]): ?>
                <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="font-size:11.5px;color:#475569;margin-bottom:4px;display:block">تاریخ در تقویم ۱۴۰۵</label>
              <select class="fr-select" name="day" id="plDayQuick">
                <option value="<?= e($today) ?>">امروز: <?= fa_num(jdate('l j F ۱۴۰۵')) ?></option>
                <option value="<?= date('Y-m-d', strtotime('+1 day')) ?>">فردا: <?= fa_num(jdate('l j F ۱۴۰۵', strtotime('+1 day'))) ?></option>
                <option value="<?= date('Y-m-d', strtotime('+2 days')) ?>">پس‌فردا: <?= fa_num(jdate('l j F ۱۴۰۵', strtotime('+2 days'))) ?></option>
                <option value="custom">📅 انتخاب تاریخ شمسی دلخواه…</option>
              </select>
              <input class="fr-input" name="day_custom" id="plDayCustom" data-jdate maxlength="10" placeholder="1405/07/26" dir="ltr" style="display:none;margin-top:6px;text-align:center">
            </div>
            <div>
              <label style="font-size:11.5px;color:#475569;margin-bottom:4px;display:block">🔔 ساعت یادآوری (۲۴ ساعته)</label>
              <input class="fr-input" type="text" data-t24 maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" name="remind_at" placeholder="08:30" dir="ltr" style="text-align:center" title="در صورت تنظیم، سر ساعت مشخص از تلگرام، بله، روبیکا یا پیامک یادآوری ارسال می‌شود">
            </div>
          </div>

          <div style="margin-bottom:12px">
            <label style="font-size:11.5px;color:#475569;margin-bottom:4px;display:block">یادداشت و جزئیات انجام (اختیاری)</label>
            <input class="fr-input" name="note" maxlength="500" placeholder="توضیحات تکمیلی، لینک یا هدف این برنامه">
          </div>

          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="fr-btn">➕ افزودن به تقویم</button>
            <a class="fr-btn sm ghost" href="<?= e($pUrl('t=cats')) ?>">🏷 مدیریت دسته‌ها</a>
            <a class="fr-btn sm ghost" href="<?= e($pUrl('t=recur')) ?>">🔁 تعریف برنامه دوره‌ای</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ستون کناری: پایش حس و حال، جوایز و وضعیت اطلاع‌رسانی -->
  <div>
    <!-- ویجت ارزیابی روزانه حس و حال و انرژی روانی (Mood & Energy Check-in) -->
    <div class="fr-card" style="margin-bottom:18px;border:1.5px solid #e0e7ff;border-radius:14px;background:#fbfcfe">
      <h3 style="margin:0 0 8px 0;font-size:14.5px;color:#1e3a6e;display:flex;align-items:center;gap:6px">
        <span>🌿 پایش حس و حال روانی امروز</span>
      </h3>
      <p style="font-size:12px;color:#64748b;line-height:1.9;margin:0 0 12px 0">
        ثبت احساس و سطح انرژی روزانه، الگوهای روان‌شناختی شما را در طول سال ۱۴۰۵ شفاف می‌سازد.
      </p>

      <form method="post" action="<?= e($pUrl('t=today')) ?>" id="plMoodForm">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="mood_save">
        <input type="hidden" name="tab" value="today">
        <input type="hidden" name="day" value="<?= e($today) ?>">

        <label style="font-size:11px;color:#475569;font-weight:600;display:block;margin-bottom:6px">حس و حال کنونی شما:</label>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-bottom:12px">
          <?php foreach ($moodFa as $mKey => [$mTitle, $mCol]):
            $isSelected = ($currentMood['mood'] ?? 'good') === $mKey;
          ?>
          <label style="display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:8px;border:1.5px solid <?= $isSelected ? e($mCol) : '#e2e8f0' ?>;background:<?= $isSelected ? '#f0f9ff' : '#fff' ?>;cursor:pointer;font-size:11.5px">
            <input type="radio" name="mood" value="<?= e($mKey) ?>" <?= $isSelected ? 'checked' : '' ?> style="cursor:pointer">
            <span><?= $mTitle ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <label style="font-size:11px;color:#475569;font-weight:600">سطح انرژی روانی:</label>
          <select class="fr-select sm" name="energy" style="max-width:130px;font-size:12px">
            <option value="5" <?= ((int)($currentMood['energy'] ?? 3) === 5) ? 'selected' : '' ?>>⭐⭐⭐⭐⭐ بسیار بالا</option>
            <option value="4" <?= ((int)($currentMood['energy'] ?? 3) === 4) ? 'selected' : '' ?>>⭐⭐⭐⭐ بالا و خوب</option>
            <option value="3" <?= ((int)($currentMood['energy'] ?? 3) === 3) ? 'selected' : '' ?>>⭐⭐⭐ متوسط و پایدار</option>
            <option value="2" <?= ((int)($currentMood['energy'] ?? 3) === 2) ? 'selected' : '' ?>>⭐⭐ کم‌انرژی</option>
            <option value="1" <?= ((int)($currentMood['energy'] ?? 3) === 1) ? 'selected' : '' ?>>⭐ بسیار پایین</option>
          </select>
        </div>

        <div style="margin-bottom:10px">
          <input class="fr-input sm" name="reflection" maxlength="500" placeholder="یادداشت شکرگزاری یا تمرکز امروز…" value="<?= e((string)($currentMood['reflection'] ?? '')) ?>" style="font-size:12px">
        </div>

        <button class="fr-btn sm ghost" style="width:100%;border-color:#4338ca;color:#4338ca">💾 ثبت ارزیابی روانی امروز</button>
      </form>
    </div>

    <!-- کارت جوایز استریک و کدهای تخفیف روانشناسی -->
    <div class="fr-card" style="margin-bottom:18px;border-radius:14px">
      <h3 style="font-size:14.5px;color:#1e3a6e;margin:0 0 8px 0">🎁 جوایز استریک پیوستگی</h3>
      <p style="font-size:12px;color:#64748b;line-height:1.9;margin:0 0 10px 0">
        به ازای هر <?= fa_num($target) ?> روز متوالی کامل، یک کد تخفیف اختصاصی در آزمون‌های روان‌سنجی یا کارگاه‌ها به صورت خودکار دریافت می‌کنید.
      </p>
      <?php if ($rewards): foreach (array_slice($rewards, 0, 3) as $rw): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:6px">
        <span style="font-size:18px">🏷</span>
        <div style="flex:1;font-size:12px">
          کد: <b dir="ltr" style="letter-spacing:1px;color:#4338ca"><?= e((string)$rw['code']) ?></b> (<?= fa_num((int)$rw['percent']) ?>٪ تخفیف)
          <div style="color:#94a3b8;font-size:10.5px">استریک <?= fa_num((int)$rw['streak_to']) ?> روزه — <?= fa_num(jdate('j F ۱۴۰۵', strtotime((string)$rw['created_at']))) ?></div>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div style="font-size:12px;color:#94a3b8;text-align:center;padding:12px">هنوز جایزه‌ای ثبت نشده؛ از امروز شروع کنید 💪</div>
      <?php endif; ?>
    </div>

    <!-- وضعیت اطلاع‌رسانی پیام‌رسان‌ها (تلگرام، بله، روبیکا) -->
    <div class="fr-card" style="border-radius:14px;background:#f0f7ff;border:1.5px solid #bfdbfe">
      <h3 style="font-size:14px;color:#1e3a6e;margin:0 0 6px 0;display:flex;align-items:center;gap:6px">
        <span>📨 یادآورهای خودکار پیام‌رسان‌ها</span>
      </h3>
      <p style="font-size:12px;color:#475569;line-height:1.9;margin:0 0 10px 0">
        ارسال خودکار و سر وقت یادآورهای روزانه در پیام‌رسان‌های متصل شما:
      </p>
      <div style="display:flex;flex-direction:column;gap:6px;font-size:11.5px;margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span>🟦 تلگرام:</span>
          <b><?= trim((string)$u['telegram_chat_id']) !== '' ? '<span style="color:#059669">✅ متصل</span>' : '<span style="color:#94a3b8">غیرفعال</span>' ?></b>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span>🟩 بله:</span>
          <b><?= trim((string)$u['bale_chat_id']) !== '' ? '<span style="color:#059669">✅ متصل</span>' : '<span style="color:#94a3b8">غیرفعال</span>' ?></b>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span>🟪 روبیکا:</span>
          <b><?= trim((string)($u['rubika_chat_id'] ?? '')) !== '' ? '<span style="color:#059669">✅ متصل</span>' : '<span style="color:#94a3b8">غیرفعال</span>' ?></b>
        </div>
      </div>
      <a class="fr-btn sm ghost" style="width:100%;text-align:center;border-color:#3b82f6;color:#1d4ed8" href="<?= e(fr_panel_url('profile.php#social')) ?>">
        ⚙️ مدیریت و اتصال پیام‌رسان‌ها در پروفایل
      </a>
    </div>
  </div>
</div>

<?php elseif ($tab === 'week'): ?>
<!-- ════════════ برگهٔ ۲: تقویم هفتگی سال ۱۴۰۵ ════════════ -->
<?php
  $wOff = (int)($_GET['w'] ?? 0);
  $wv = fr_planner_week_view_1405((int)$u['id'], $wOff);
?>
<div class="fr-card" style="margin-bottom:18px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:10px">
    <h3 style="margin:0;font-size:16px">
      📅 تقویم هفتگی سال ۱۴۰۵ <small style="color:#64748b;font-weight:normal">(از <?= e($wv['start_fa']) ?> تا <?= e($wv['end_fa']) ?>)</small>
    </h3>
    <div style="display:flex;gap:6px">
      <a class="fr-btn sm ghost" href="<?= e($pUrl('t=week&w=' . ($wOff - 1))) ?>">‹ هفتهٔ قبل</a>
      <?php if ($wOff !== 0): ?><a class="fr-btn sm" href="<?= e($pUrl('t=week')) ?>">هفتهٔ جاری</a><?php endif; ?>
      <a class="fr-btn sm ghost" href="<?= e($pUrl('t=week&w=' . ($wOff + 1))) ?>">هفتهٔ بعد ›</a>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:10px" class="pl-weekgrid">
    <?php foreach ($wv['days'] as $dDate => $dInfo):
      $isToday = $dDate === $today;
      $tasksD = $dInfo['tasks'];
      $dn = count(array_filter($tasksD, fn($x) => (int)$x['done'] === 1));
      $tot = count($tasksD);
    ?>
    <div style="border:1.5px solid <?= $isToday ? '#2563eb' : '#e2e8f0' ?>;border-radius:12px;padding:10px;background:<?= $isToday ? '#eff6ff' : '#fff' ?>;min-height:140px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;border-bottom:1px solid <?= $isToday ? '#bfdbfe' : '#f1f5f9' ?>;padding-bottom:4px">
        <b style="font-size:12.5px;color:<?= $isToday ? '#1d4ed8' : '#1e293b' ?>"><?= e($dInfo['name']) ?></b>
        <small style="font-size:11px;color:#64748b"><?= fa_num($dInfo['shamsi_md']) ?></small>
      </div>
      <?php if ($tot > 0): ?>
      <div style="font-size:11px;color:#64748b;margin-bottom:6px">
        <?= fa_num($dn) ?>/<?= fa_num($tot) ?> تکمیل شد <?= $dn === $tot ? '✅' : '' ?>
      </div>
      <?php endif; ?>
      <div style="display:flex;flex-direction:column;gap:4px">
        <?php foreach ($tasksD as $tItem):
          [$cLabel, $cHex] = $catMap[$tItem['category']] ?? [$tItem['category'], '#64748b'];
          $tDone = (int)$tItem['done'] === 1;
        ?>
        <div style="font-size:11px;padding:4px 6px;border-radius:6px;background:<?= $tDone ? '#f1f5f9' : '#fff' ?>;border:1px solid #e2e8f0;border-right:3px solid <?= e($cHex) ?>;<?= $tDone ? 'text-decoration:line-through;opacity:.65' : '' ?>" title="<?= e($tItem['title']) ?>">
          <?= !empty($tItem['time_note']) ? '<b style="color:#64748b">' . fa_num($tItem['time_note']) . '</b> ' : '' ?>
          <?= e(mb_strimwidth($tItem['title'], 0, 26, '…')) ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<style>@media(max-width:920px){.pl-weekgrid{grid-template-columns:repeat(2,1fr) !important}}</style>

<?php elseif ($tab === 'all'): ?>
<!-- ════════════ برگهٔ ۳: مدیریت و آرشیو همهٔ برنامه‌ها ════════════ -->
<?php
  $fltQ = [
      'q'    => (string)($_GET['q'] ?? ''),
      'cat'  => (string)($_GET['cat'] ?? ''),
      'pri'  => (string)($_GET['pri'] ?? ''),
      'done' => (string)($_GET['done'] ?? ''),
      'from' => (string)($_GET['from'] ?? ''),
      'to'   => (string)($_GET['to'] ?? ''),
  ];
  $pgA = fr_planner_search((int)$u['id'], $fltQ, (int)($_GET['p'] ?? 1), 20);
?>
<div class="fr-card" style="margin-bottom:18px">
  <h3 style="margin:0 0 12px 0;font-size:16px">
    🔎 همهٔ برنامه‌های سال ۱۴۰۵ <small style="color:#64748b;font-weight:normal">(مجموع: <?= fa_num($pgA['total']) ?> مورد)</small>
  </h3>

  <form method="get" action="<?= e(fr_panel_url('planner.php')) ?>" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <input type="hidden" name="t" value="all">
    <input class="fr-input sm" name="q" value="<?= e($fltQ['q']) ?>" placeholder="جستجو در عنوان یا یادداشت…" style="max-width:180px">
    <select class="fr-select sm" name="cat" style="max-width:140px">
      <option value="">همهٔ دسته‌ها</option>
      <?php foreach ($catMap as $k => [$lbl]): ?><option value="<?= e($k) ?>" <?= $fltQ['cat'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
    </select>
    <select class="fr-select sm" name="pri" style="max-width:130px">
      <option value="">هر اولویتی</option>
      <?php foreach ($priFa as $k => $v): ?><option value="<?= $k ?>" <?= $fltQ['pri'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
    </select>
    <select class="fr-select sm" name="done" style="max-width:120px">
      <option value="">همه وضعیت‌ها</option>
      <option value="0" <?= $fltQ['done'] === '0' ? 'selected' : '' ?>>⏳ ناتمام</option>
      <option value="1" <?= $fltQ['done'] === '1' ? 'selected' : '' ?>>✅ انجام‌شده</option>
    </select>
    <button class="fr-btn sm">فیلتر</button>
    <a class="fr-btn sm ghost" href="<?= e($pUrl('t=all')) ?>">پاک‌سازی فیلترها</a>
  </form>

  <div class="tbl-wrap">
    <table class="fr-table" style="font-size:12.5px">
      <thead>
        <tr>
          <th>عنوان برنامه</th>
          <th>تاریخ شمسی</th>
          <th>ساعت</th>
          <th>دسته‌بندی</th>
          <th>اولویت</th>
          <th>وضعیت</th>
          <th style="width:100px">عملیات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pgA['rows'] as $x):
          [$cLabel, $cHex] = $catMap[$x['category']] ?? [$x['category'], '#64748b'];
          $xDone = (int)$x['done'] === 1;
        ?>
        <tr>
          <td>
            <b><?= e($x['title']) ?></b>
            <?php if (!empty($x['note'])): ?><br><small style="color:#64748b">📝 <?= e(mb_strimwidth((string)$x['note'], 0, 60, '…')) ?></small><?php endif; ?>
            <?php if (!empty($x['remind_at'])): ?><br><small style="color:#4338ca">🔔 <?= fa_num(jdate('j F — H:i', strtotime((string)$x['remind_at']))) ?></small><?php endif; ?>
          </td>
          <td style="white-space:nowrap"><?= fa_num(jdate('l j F ۱۴۰۵', strtotime((string)$x['day']))) ?></td>
          <td><?= !empty($x['time_note']) ? fa_num($x['time_note']) : '—' ?></td>
          <td><span class="chip" style="border-right:3px solid <?= e($cHex) ?>;font-size:11px"><?= e($cLabel) ?></span></td>
          <td><?= $priFa[$x['priority']] ?? 'عادی' ?></td>
          <td><?= $xDone ? '<span style="color:#059669;font-weight:600">✅ انجام شد</span>' : '<span style="color:#d97706">⏳ در انتظار</span>' ?></td>
          <td style="white-space:nowrap">
            <a class="fr-btn sm ghost" href="<?= e($pUrl('t=all&edit=' . (int)$x['id'])) ?>" title="ویرایش">✏️</a>
            <form method="post" action="<?= e($pUrl('t=all')) ?>" style="display:inline" onsubmit="return confirm('حذف شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="del">
              <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
              <input type="hidden" name="tab" value="all">
              <button class="fr-btn sm danger">✕</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$pgA['rows']): ?>
        <tr><td colspan="7" style="text-align:center;padding:26px;color:#94a3b8">هیچ برنامه‌ای با فیلترهای انتخابی یافت نشد.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pgA['pages'] > 1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:16px;flex-wrap:wrap">
    <?php for ($p = 1; $p <= $pgA['pages']; $p++): ?>
    <a class="fr-btn sm <?= $p === $pgA['page'] ? '' : 'ghost' ?>" href="<?= e($pUrl('t=all&p=' . $p . '&' . http_build_query($fltQ))) ?>">
      <?= fa_num($p) ?>
    </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'recur'): ?>
<!-- ════════════ برگهٔ ۴: عادت‌ها و برنامه‌های دوره‌ای ════════════ -->
<?php $recurs = fr_planner_recur_list((int)$u['id']); ?>
<div class="fr-grid cols-2" style="gap:18px;align-items:start">
  <div class="fr-card">
    <h3 style="margin:0 0 10px 0;font-size:15px">🔁 عادت‌های پایدار و برنامه‌های دوره‌ای</h3>
    <p style="font-size:12px;color:#64748b;line-height:1.9;margin:0 0 14px 0">
      عادت‌هایی که ثبت می‌کنید به صورت خودکار در تقویم روزهای مشخص‌شدهٔ سال ۱۴۰۵ ظاهر می‌شوند.
    </p>

    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($recurs as $rc):
        [$rcLbl, $rcHex] = $catMap[$rc['category']] ?? [$rc['category'], '#64748b'];
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc">
        <div>
          <b style="font-size:13.5px"><?= e($rc['title']) ?></b>
          <div style="font-size:11px;color:#64748b;margin-top:2px">
            <?= $freqFa[$rc['freq']] ?? $rc['freq'] ?> &nbsp;•&nbsp;
            <?= !empty($rc['time_note']) ? 'ساعت ' . fa_num($rc['time_note']) . ' • ' : '' ?>
            <span style="color:<?= e($rcHex) ?>"><?= e($rcLbl) ?></span>
          </div>
        </div>
        <div style="display:flex;gap:6px">
          <form method="post" action="<?= e($pUrl('t=recur')) ?>" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="recur_toggle">
            <input type="hidden" name="id" value="<?= (int)$rc['id'] ?>">
            <button class="fr-btn sm <?= (int)$rc['active'] ? 'ghost' : '' ?>"><?= (int)$rc['active'] ? 'فعال' : 'غیرفعال' ?></button>
          </form>
          <form method="post" action="<?= e($pUrl('t=recur')) ?>" style="margin:0" onsubmit="return confirm('عادت حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="recur_del">
            <input type="hidden" name="id" value="<?= (int)$rc['id'] ?>">
            <button class="fr-btn sm danger">✕</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$recurs): ?>
      <div style="text-align:center;padding:24px;color:#94a3b8;font-size:12.5px">هنوز عادت دوره‌ای ثبت نشده است.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="fr-card">
    <h3 style="margin:0 0 10px 0;font-size:15px">➕ تعریف عادت دوره‌ای جدید</h3>
    <form method="post" action="<?= e($pUrl('t=recur')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="recur_add">
      <div class="fr-field">
        <label>عنوان عادت یا برنامه *</label>
        <input class="fr-input" name="title" required maxlength="190" placeholder="مثلاً: ۲۰ دقیقه مطالعه روانشناسی">
      </div>
      <div class="fr-field">
        <label>تکرار در تقویم ۱۴۰۵</label>
        <select class="fr-select" name="freq">
          <option value="daily">هر روز (۷ روز هفته)</option>
          <option value="weekday">روزهای کاری (شنبه تا پنجشنبه)</option>
          <option value="weekly">یک روز در هفته</option>
        </select>
      </div>
      <div class="fr-field">
        <label>ساعت ترجیحی</label>
        <input class="fr-input" name="time_note" placeholder="09:00" dir="ltr" style="text-align:center">
      </div>
      <div class="fr-field">
        <label>دسته‌بندی</label>
        <select class="fr-select" name="category">
          <?php foreach ($catMap as $k => [$lbl]): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="fr-btn">ثبت عادت دوره‌ای</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'cats'): ?>
<!-- ════════════ برگهٔ ۵: دسته‌بندی‌های شخصی ════════════ -->
<div class="fr-grid cols-2" style="gap:18px;align-items:start">
  <div class="fr-card">
    <h3 style="margin:0 0 12px 0;font-size:15px">🏷 دسته‌بندی‌های برنامهٔ روزانه</h3>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($catMap as $cKey => [$cTitle, $cColor]):
        $isCustom = !in_array($cKey, ['personal','growth','work','study','health','family','fun'], true);
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px">
        <div style="display:flex;align-items:center;gap:10px">
          <span style="width:18px;height:18px;border-radius:50%;background:<?= e($cColor) ?>"></span>
          <b style="font-size:13px"><?= e($cTitle) ?></b>
        </div>
        <?php if ($isCustom): ?>
        <form method="post" action="<?= e($pUrl('t=cats')) ?>" style="margin:0" onsubmit="return confirm('دسته حذف شود؟')">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="cat_del">
          <input type="hidden" name="id" value="<?= (int)DB::val("SELECT id FROM " . DB::t('planner_cats') . " WHERE user_id=? AND name=?", [(int)$u['id'], $cKey]) ?>">
          <button class="fr-btn sm danger">✕</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="fr-card">
    <h3 style="margin:0 0 12px 0;font-size:15px">➕ افزودن دسته‌بندی جدید</h3>
    <form method="post" action="<?= e($pUrl('t=cats')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cat_add">
      <div class="fr-field">
        <label>نام دسته *</label>
        <input class="fr-input" name="name" required maxlength="50" placeholder="مثلاً: پروژه ارشد">
      </div>
      <div class="fr-field">
        <label>رنگ اختصاصی</label>
        <input class="fr-input" type="color" name="color" value="#4338ca" style="height:42px;padding:4px">
      </div>
      <button class="fr-btn">ایجاد دسته‌بندی</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'stats'): ?>
<!-- ════════════ برگهٔ ۶: آمار و روان‌سنجی عملکرد ════════════ -->
<?php
  $st = fr_planner_progress_stats((int)$u['id']);
  $chart = fr_planner_chart((int)$u['id'], 14);
?>
<div class="fr-grid cols-3" style="gap:14px;margin-bottom:18px">
  <div class="fr-card" style="text-align:center;margin:0">
    <small style="color:#64748b">کل برنامه‌های انجام‌شده</small>
    <div style="font-size:26px;font-weight:bold;color:#1e3a6e;margin-top:6px"><?= fa_num($st['done']) ?> مورد</div>
  </div>
  <div class="fr-card" style="text-align:center;margin:0">
    <small style="color:#64748b">استریک طلایی (بیشترین پیوستگی)</small>
    <div style="font-size:26px;font-weight:bold;color:#d97706;margin-top:6px"><?= fa_num($st['best_streak']) ?> روز</div>
  </div>
  <div class="fr-card" style="text-align:center;margin:0">
    <small style="color:#64748b">نرخ پایبندی ۳۰ روز اخیر</small>
    <div style="font-size:26px;font-weight:bold;color:#059669;margin-top:6px"><?= fa_num($st['rate30']) ?>٪</div>
  </div>
</div>

<div class="fr-card">
  <h3 style="margin:0 0 14px 0;font-size:15px">📈 نمودار عملکرد و تکمیل برنامه‌ها در ۱۴ روز گذشته</h3>
  <div style="display:flex;align-items:flex-end;gap:8px;height:180px;padding:10px 0;border-bottom:1.5px solid var(--line)">
    <?php foreach ($chart as $cp): ?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;height:100%;justify-content:flex-end" title="<?= e($cp['shamsi']) ?>: <?= fa_num($cp['done']) ?> از <?= fa_num($cp['total']) ?>">
      <div style="font-size:10px;color:#64748b;margin-bottom:4px"><?= $cp['total'] ? fa_num($cp['pct']) . '٪' : '' ?></div>
      <div style="width:100%;max-width:24px;border-radius:6px 6px 0 0;background:<?= $cp['pct'] >= 100 ? '#059669' : ($cp['pct'] > 0 ? '#3b82f6' : '#e2e8f0') ?>;height:<?= max(6, (int)($cp['pct'] * 1.3)) ?>px"></div>
      <div style="font-size:9.5px;color:#64748b;margin-top:6px;writing-mode:vertical-rl;transform:rotate(180deg)"><?= fa_num($cp['shamsi']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab === 'badges'): ?>
<!-- ════════════ برگهٔ ۷: مدال‌ها و دستاوردها ════════════ -->
<?php $badges = fr_planner_badges((int)$u['id']); ?>
<div class="fr-card">
  <h3 style="margin:0 0 12px 0;font-size:15px">🏅 مدال‌ها و افتخارات استمرار روان‌شناختی</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
    <?php foreach ($badges as $bg):
      [$bKey, $bIcon, $bTitle, $bDesc, $bUnlocked] = $bg;
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:12px;border:1.5px solid <?= $bUnlocked ? '#93c5fd' : '#e2e8f0' ?>;background:<?= $bUnlocked ? '#eff6ff' : '#f8fafc' ?>;opacity:<?= $bUnlocked ? '1' : '.65' ?>">
      <span style="font-size:32px"><?= $bIcon ?></span>
      <div>
        <b style="font-size:13.5px;color:<?= $bUnlocked ? '#1e3a6e' : '#64748b' ?>"><?= $bTitle ?></b>
        <div style="font-size:11.5px;color:#64748b;margin-top:2px"><?= $bDesc ?></div>
        <div style="font-size:10.5px;font-weight:600;margin-top:4px;color:<?= $bUnlocked ? '#059669' : '#94a3b8' ?>">
          <?= $bUnlocked ? '✅ باز شده' : '🔒 در حال تلاش' ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif ($tab === 'focus'): ?>
<!-- ════════════ برگهٔ ۸: تمرکز عمیق (پومودورو) ════════════ -->
<div class="fr-grid cols-2" style="gap:18px;align-items:start">
  <div class="fr-card" style="text-align:center">
    <h3 style="font-size:16px;color:#1e3a6e;margin:0 0 8px 0">⏱ اتاق تمرکز عمیق و ذهن‌آگاهی</h3>
    <p style="font-size:12px;color:#64748b;line-height:1.9;margin:0 0 16px 0">
      ۲۵ دقیقه تمرکز خالص روی یک برنامه → ۵ دقیقه تنفس و استراحت.
    </p>
    <div id="pzTimer" style="font-size:56px;font-weight:bold;color:#1e3a6e;font-family:monospace;letter-spacing:2px;margin-bottom:18px">۲۵:۰۰</div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <button class="fr-btn" id="pzStart">شروع تمرکز 🍅</button>
      <button class="fr-btn ghost" id="pzBreak">استراحت ۵ دقیقه ☕</button>
      <button class="fr-btn ghost danger" id="pzReset">توقف</button>
    </div>
    <div id="pzState" style="margin-top:14px;font-size:12.5px;color:#64748b">آماده‌اید؟ یک کار را انتخاب کنید و شروع را بزنید.</div>
  </div>

  <div class="fr-card">
    <h3 style="font-size:15px;margin:0 0 12px 0">📌 برنامه‌های امروز برای تمرکز</h3>
    <?php $pends = array_filter($tasks, fn($t) => !(int)$t['done']); ?>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($pends as $pt): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px">
        <span>🍅</span>
        <b style="font-size:13px"><?= e($pt['title']) ?></b>
      </div>
      <?php endforeach; ?>
      <?php if (!$pends): ?>
      <div style="text-align:center;padding:24px;color:#94a3b8;font-size:12px">همهٔ برنامه‌ها انجام شده است! 🎉</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function(){
  var el = document.getElementById('pzTimer'); if(!el) return;
  var fa = function(s){ return String(s).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
  var total = 25*60, left = total, timer = null, mode = 'focus';
  function fmt(){ var m = Math.floor(left/60), s = left%60; el.textContent = fa((m<10?'0':'')+m+':' + (s<10?'0':'')+s); }
  function state(t){ document.getElementById('pzState').textContent = t; }
  function stop(){ if(timer){ clearInterval(timer); timer = null; } }
  function tick(){
    left--;
    if (left <= 0){
      stop(); fmt();
      if (mode === 'focus'){
        state('وقت استراحت! ☕ ۲۵ دقیقه تمرکز سپری شد. استراحت ۵ دقیقه را بزنید.');
        if (navigator.vibrate) navigator.vibrate(200);
      } else {
        state('استراحت تمام شد — آماده بازگشت به تمرکز! 🍅');
      }
      return;
    }
    fmt();
  }
  function run(min, m, label){
    stop(); total = min*60; left = total; mode = m; fmt(); state(label);
    timer = setInterval(tick, 1000);
  }
  document.getElementById('pzStart').onclick = function(){ run(25, 'focus', '🍅 در حال تمرکز عمیق… تمام عوامل حواس‌پرتی را کنار بگذارید.'); };
  document.getElementById('pzBreak').onclick = function(){ run(5, 'break', '☕ وقت استراحت و تنفس عمیق.'); };
  document.getElementById('pzReset').onclick = function(){ stop(); total=25*60; left=total; mode='focus'; fmt(); state('آماده‌اید؟ دوباره شروع کنید.'); };
  fmt();
})();
</script>

<?php elseif ($tab === 'delta'): ?>
<!-- ════════════ برگهٔ ۹: پایش دلتای رشد و بازآزمون روانشناسی ════════════ -->
<?php
require_once __DIR__ . '/../core/GrowthEngine.php';
$userTests = DB::all("SELECT DISTINCT t.id, t.title, t.slug FROM " . DB::t('tests') . " t
                      JOIN " . DB::t('attempts') . " a ON a.test_id = t.id
                      WHERE a.user_id = ? AND a.status = 'completed'
                      ORDER BY t.title ASC", [(int)$u['id']]);

$selectedTestId = (int)($_GET['test_id'] ?? ($userTests[0]['id'] ?? 0));
$deltaData = $selectedTestId > 0 ? GrowthEngine::getDeltaComparison((int)$u['id'], $selectedTestId) : ['has_comparison' => false, 'scales' => []];
?>
<div class="fr-card" style="margin-bottom:18px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0 0 4px 0;font-size:16px">🎯 پایش تغییرات روان‌سنجی و بازآزمون (Retest &amp; Delta Tracking)</h3>
      <p style="font-size:12.5px;color:#64748b;margin:0">
        مقایسهٔ نتایج بازآزمون‌های روانشناسی در طول زمان برای سنجش میزان اثربخشی تمرین‌های پلنر.
      </p>
    </div>
    <?php if ($userTests): ?>
    <form method="get" action="<?= e(fr_panel_url('planner.php')) ?>" style="margin:0">
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
    <div style="font-size:40px;margin-bottom:10px">🧠</div>
    <h4>هنوز آزمونی در فریا تکمیل نکرده‌اید</h4>
    <p style="color:#64748b;font-size:13px;max-width:440px;margin:8px auto 16px auto;line-height:2">
      برای سنجش ابعاد شخصیتی و پایش رشد رفتاری، ابتدا در یکی از آزمون‌های تخصصی شرکت فرمایید.
    </p>
    <a href="<?= e(fr_panel_url('tests.php')) ?>" class="fr-btn">مشاهده و شرکت در آزمون‌ها ←</a>
  </div>
<?php elseif (!$deltaData['has_comparison']): ?>
  <div class="fr-card" style="text-align:center;padding:36px">
    <div style="font-size:40px;margin-bottom:10px">🌱</div>
    <h4>نیاز به تکرار آزمون (Retest)</h4>
    <p style="color:#64748b;font-size:13px;max-width:520px;margin:8px auto 16px auto;line-height:2">
      شما این آزمون را یک بار انجام داده‌اید. پیشنهاد می‌شود پس از استمرار در تمرین‌های پلنر روزانه، آزمون را مجدداً تکرار کنید تا شاخص دلتا (&Delta;) محاسبه شود.
    </p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:14px">
      <a href="<?= e(fr_panel_url('test-start.php?id=' . $selectedTestId)) ?>" class="fr-btn">شرکت مجدد در این آزمون (Retest)</a>
      <a href="<?= e($pUrl('t=today')) ?>" class="fr-btn ghost">انجام تمرین‌های امروز</a>
    </div>
  </div>
<?php else: ?>
  <div class="fr-grid cols-3" style="gap:14px;margin-bottom:16px">
    <div class="fr-card" style="margin:0;text-align:center">
      <small style="color:#64748b">دفعات آزمون</small>
      <div style="font-size:24px;font-weight:bold;color:#4338ca;margin-top:4px"><?= fa_num($deltaData['total_attempts']) ?> نوبت</div>
    </div>
    <div class="fr-card" style="margin:0;text-align:center">
      <small style="color:#64748b">تاریخ آزمون اولیه</small>
      <div style="font-size:13.5px;font-weight:bold;margin-top:8px"><?= fa_num(jdate('j F ۱۴۰۵', strtotime($deltaData['first_attempt']['created_at'] ?? 'now'))) ?></div>
    </div>
    <div class="fr-card" style="margin:0;text-align:center">
      <small style="color:#64748b">تاریخ آخرین آزمون</small>
      <div style="font-size:13.5px;font-weight:bold;color:#059669;margin-top:8px"><?= fa_num(jdate('j F ۱۴۰۵', strtotime($deltaData['latest_attempt']['created_at'] ?? 'now'))) ?></div>
    </div>
  </div>

  <div class="fr-card">
    <h4 style="margin:0 0 14px 0">مقایسهٔ مؤلفه‌ها و دلتای رشد (&Delta;)</h4>
    <div class="tbl-wrap">
      <table class="fr-table">
        <thead>
          <tr>
            <th>شاخص روان‌سنجی</th>
            <th style="text-align:center">نمرهٔ اولیه</th>
            <th style="text-align:center">نمرهٔ اخیر</th>
            <th style="text-align:center">دلتای تغییر (&Delta;)</th>
            <th>روند</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deltaData['scales'] as $code => $sc):
            $d = $sc['delta'];
            $color = $d > 0 ? '#059669' : ($d < 0 ? '#dc2626' : '#64748b');
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
                <span style="color:#059669">▲ ارتقاء شاخص</span>
              <?php elseif ($sc['trend'] === 'decreased'): ?>
                <span style="color:#dc2626">▼ تعدیل شاخص</span>
              <?php else: ?>
                <span style="color:#64748b">■ حفظ تعادل</span>
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
/* مودال ویرایش کار با تاریخ شمسی سال ۱۴۰۵ */
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0):
    $et = DB::one("SELECT * FROM " . DB::t('planner_tasks') . " WHERE id=? AND user_id=?", [$editId, (int)$u['id']]);
    if ($et):
        $etShamsi = fr_planner_day_to_shamsi((string)$et['day']);
?>
<div style="position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:999;display:flex;align-items:center;justify-content:center;padding:16px">
  <div class="fr-card" style="max-width:500px;width:100%;margin:0;max-height:92vh;overflow-y:auto;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.25)">
    <h3 style="margin:0 0 14px 0;font-size:15px;color:#1e3a6e">✏️ ویرایش برنامه در تقویم ۱۴۰۵</h3>
    <form method="post" action="<?= e($pUrl('t=' . $tab)) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="edit">
      <input type="hidden" name="id" value="<?= $editId ?>">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">

      <div class="fr-field">
        <label>عنوان برنامه *</label>
        <input class="fr-input" name="title" maxlength="190" required value="<?= e($et['title']) ?>">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px" class="pl-addgrid">
        <div class="fr-field">
          <label>تاریخ شمسی (مثلاً: <?= fa_num($todayShamsi) ?>)</label>
          <input class="fr-input" data-jdate maxlength="10" name="day" dir="ltr" value="<?= e($etShamsi) ?>" style="text-align:center" placeholder="1405/07/26">
        </div>
        <div class="fr-field">
          <label>ساعت (۲۴ ساعته)</label>
          <input class="fr-input" data-t24 maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" name="time_note" dir="ltr" style="text-align:center" value="<?= e((string)$et['time_note']) ?>" placeholder="08:30">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px" class="pl-addgrid">
        <div class="fr-field">
          <label>اولویت روان‌شناختی</label>
          <select class="fr-select" name="priority">
            <?php foreach ($priFa as $k => $vP): ?>
            <option value="<?= $k ?>" <?= $et['priority'] === $k ? 'selected' : '' ?>><?= $vP ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fr-field">
          <label>دسته‌بندی</label>
          <select class="fr-select" name="category">
            <?php foreach ($catMap as $k => [$lbl]): ?>
            <option value="<?= e($k) ?>" <?= $et['category'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="fr-field">
        <label>یادداشت</label>
        <textarea class="fr-input" name="note" rows="2" maxlength="500"><?= e((string)($et['note'] ?? '')) ?></textarea>
      </div>

      <div class="fr-field">
        <label>🔔 ساعت یادآور (۲۴ ساعته)</label>
        <input class="fr-input" type="text" data-t24 maxlength="5" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" name="remind_at" dir="ltr" style="text-align:center" placeholder="08:30" value="<?= e(!empty($et['remind_at']) ? substr(date('H:i', strtotime((string)$et['remind_at'])), 0, 5) : '') ?>">
      </div>

      <div style="display:flex;gap:10px;margin-top:14px">
        <button class="fr-btn">💾 ذخیرهٔ ویرایش</button>
        <a class="fr-btn ghost" href="<?= e($pUrl('t=' . $tab)) ?>">انصراف</a>
      </div>
    </form>
  </div>
</div>
<?php endif; endif; ?>

<script>
// ۱) جابه‌جایی هوشمند انتخاب تاریخ شمسی دلخواه
(function(){
  var sel = document.getElementById('plDayQuick');
  var custom = document.getElementById('plDayCustom');
  if (sel && custom) {
    sel.addEventListener('change', function(){
      if (sel.value === 'custom') {
        custom.style.display = 'block';
        custom.name = 'day';
        sel.name = 'day_quick';
        custom.focus();
      } else {
        custom.style.display = 'none';
        custom.name = 'day_custom';
        sel.name = 'day';
      }
    });
  }
})();

// ۲) نوار عملیات گروهی
(function(){
  var bar = document.getElementById('plBulkBar'); if (!bar) return;
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  function sync(){
    var n = document.querySelectorAll('.pl-sel:checked').length;
    bar.style.display = n > 0 ? 'flex' : 'none';
    var numEl = document.getElementById('plBulkN');
    if (numEl) numEl.textContent = String(n).replace(/\d/g, function(d){ return FA[+d]; });
  }
  document.querySelectorAll('.pl-sel').forEach(function(c){ c.addEventListener('change', sync); });
  sync();
})();

// ۳) تیک زدن سریع و AJAX با اعتبارسنجی
document.querySelectorAll('.pl-chk').forEach(function(btn){
  btn.addEventListener('click', async function(){
    var row = btn.closest('.pl-task');
    if (!row) return;
    var id = row.dataset.id;
    btn.disabled = true;

    var fd = new FormData();
    fd.append('act', 'toggle');
    fd.append('id', id);
    fd.append('_csrf', '<?= csrf_token() ?>');

    try {
      var r = await fetch('<?= SITE_URL ?>/api/planner.php', {method:'POST', body:fd});
      var j = await r.json();
      if (j.ok) {
        var on = !!j.done;
        btn.classList.toggle('on', on);
        btn.textContent = on ? '✓' : '';
        btn.style.background = on ? '#10b981' : '#fff';
        btn.style.borderColor = on ? '#059669' : '#94a3b8';
        row.classList.toggle('done', on);
        if (j.reward) {
          alert('🏆 تبریک! استریک پیوستگی شما به حد نصاب رسید و کد تخفیف جدید برایتان صادر شد.');
        }
      } else {
        alert(j.message || 'خطا در ارتباط با سرور');
      }
    } catch(e) {
      location.reload();
    }
    btn.disabled = false;
  });
});
</script>

<?php
panel_layout_end();
render_footer();
