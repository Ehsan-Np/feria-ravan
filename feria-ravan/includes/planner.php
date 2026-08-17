<?php
/**
 * فریا روان — برنامه‌ریز روزانهٔ کاربر (نسخهٔ ۲٫۱)
 * ─ تسک‌های روزانه + استریک روزهای متوالی + پاداش خودکار (کد تخفیف)
 * ساده‌ترین و حرفه‌ای‌ترین مدل: هر روز چند کار؛ روز «کامل» یعنی همهٔ کارهایش انجام شده باشند.
 */

/** آیا برنامه‌ریز فعال است؟ */
function fr_planner_enabled(): bool
{
    return (string)DB::setting('planner_enabled', '1') === '1';
}

/** تاریخ امروز به میلادی (مبنای محاسبات) */
function fr_planner_today(): string { return date('Y-m-d'); }

/** تسک‌های یک کاربر برای یک روز مشخص */
function fr_planner_day_tasks(int $userId, string $day): array
{
    return DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=? ORDER BY FIELD(priority,'high','normal','low'), id",
                   [$userId, $day]);
}

/** افزودن تسک — ۲٫۷: note + remind_at */
function fr_planner_add(int $userId, string $day, string $title, string $timeNote, string $priority, string $category, string $note = '', ?string $remindAt = null): int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = fr_planner_today();
    $title    = mb_substr(trim($title), 0, 190);
    $timeNote = mb_substr(trim($timeNote), 0, 5);   // «08:30»
    if ($title === '' || $userId <= 0) return 0;
    if (!in_array($priority, ['low','normal','high'], true)) $priority = 'normal';
    $category = mb_substr(trim($category), 0, 40);
    $remindAt = fr_planner_valid_dt($remindAt);
    return DB::insert('planner_tasks', [
        'user_id' => $userId, 'day' => $day, 'title' => $title, 'time_note' => $timeNote,
        'priority' => $priority, 'category' => $category, 'done' => 0,
        'note' => mb_substr(trim($note), 0, 500), 'remind_at' => $remindAt,
    ]);
}

/** اعتبارسنجی datetime ورودی کاربر (از فرم: 2026-08-10T14:30 یا با فاصله) */
function fr_planner_valid_dt(?string $dt): ?string
{
    $dt = trim((string)$dt);
    if ($dt === '') return null;
    $dt = str_replace('T', ' ', en_num($dt));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $dt, $m)) return null;
    if ((int)$m[4] > 23 || (int)$m[5] > 59) return null;
    return sprintf('%04d-%02d-%02d %02d:%02d:00', (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5]);
}

/** ۲٫۷ — ویرایش کامل تسک (فقط مالک) */
function fr_planner_update(int $userId, int $taskId, array $f): bool
{
    $t = DB::one("SELECT id FROM " . DB::t('planner_tasks') . " WHERE id=? AND user_id=?", [$taskId, $userId]);
    if (!$t) return false;
    $title = mb_substr(trim((string)($f['title'] ?? '')), 0, 190);
    if ($title === '') return false;
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['day'] ?? '')) ? (string)$f['day'] : fr_planner_today();
    $pri0 = (string)($f['priority'] ?? 'normal');
    $pri = in_array($pri0, ['low','normal','high'], true) ? $pri0 : 'normal';
    DB::update('planner_tasks', [
        'title' => $title, 'day' => $day,
        'time_note' => mb_substr(trim((string)($f['time_note'] ?? '')), 0, 5) ?: null,
        'priority' => $pri,
        'category' => mb_substr(trim((string)($f['category'] ?? 'personal')), 0, 40) ?: 'personal',
        'note' => mb_substr(trim((string)($f['note'] ?? '')), 0, 500),
        'remind_at' => fr_planner_valid_dt($f['remind_at'] ?? null),
        /* اگر موعد یادآور تغییر کرد، فلگ ارسال ریست شود */
        'reminded_at' => null,
    ], 'id=?', [$taskId]);
    return true;
}

/** تعویض وضعیت انجام یک تسک (فقط مالک) */
function fr_planner_toggle(int $userId, int $taskId): ?bool
{
    $t = DB::one("SELECT id,done,day FROM " . DB::t('planner_tasks') . " WHERE id=? AND user_id=?", [$taskId, $userId]);
    if (!$t) return null;
    $new = $t['done'] ? 0 : 1;
    DB::run("UPDATE " . DB::t('planner_tasks') . " SET done=?, done_at=" . ($new ? 'NOW()' : 'NULL') . " WHERE id=?", [$new, $taskId]);
    if ($new) fr_planner_check_reward($userId);     // ممکن است استریک کامل شده باشد
    return (bool)$new;
}

/** حذف تسک */
function fr_planner_delete(int $userId, int $taskId): bool
{
    $t = DB::one("SELECT id FROM " . DB::t('planner_tasks') . " WHERE id=? AND user_id=?", [$taskId, $userId]);
    if (!$t) return false;
    DB::run("DELETE FROM " . DB::t('planner_tasks') . " WHERE id=?", [$taskId]);
    return true;
}

/** یک روز «کامل» است اگر ≥۱ تسک داشته و همه done باشند */
function fr_planner_day_complete(int $userId, string $day): bool
{
    $r = DB::one("SELECT COUNT(*) AS total, SUM(CASE WHEN done=1 THEN 1 ELSE 0 END) AS done
                  FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=?", [$userId, $day]);
    return $r && (int)$r['total'] > 0 && (int)$r['done'] === (int)$r['total'];
}

/**
 * استریک فعلی: تعداد روزهای متوالیِ متمادی که روزشان «کامل» بوده، تا امروز (یا دیروز).
 * امروزِ هنوز ناتمام استریک را نمی‌شکند؛ دیروزِ ناتمام می‌شکند.
 */
function fr_planner_current_streak(int $userId): int
{
    $anchor = fr_planner_today();
    if (!fr_planner_day_complete($userId, $anchor)) $anchor = date('Y-m-d', strtotime('-1 day'));
    $streak = 0; $d = $anchor;
    for ($i = 0; $i < 400; $i++) {
        if (fr_planner_day_complete($userId, $d)) { $streak++; $d = date('Y-m-d', strtotime($d . ' -1 day')); }
        else break;
    }
    return $streak;
}

/** آمار ۷ روز اخیر برای نوار هفتگی: [ ['day'=>Y-m-d,'j'=>'…','total'=>n,'done'=>n,'complete'=>bool], … ] */
function fr_planner_week(int $userId): array
{
    $rows = DB::all("SELECT day, COUNT(*) AS total, SUM(CASE WHEN done=1 THEN 1 ELSE 0 END) AS done
                     FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day >= DATE_SUB(?, INTERVAL 6 DAY)
                     GROUP BY day", [$userId, fr_planner_today()]);
    $map = [];
    foreach ($rows as $r) $map[$r['day']] = $r;
    $out = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $r = $map[$d] ?? null;
        $total = $r ? (int)$r['total'] : 0;
        $done  = $r ? (int)$r['done'] : 0;
        $out[] = ['day' => $d, 'total' => $total, 'done' => $done, 'complete' => $total > 0 && $done === $total];
    }
    return $out;
}

/** پاداش‌های دریافت‌شدهٔ کاربر */
function fr_planner_rewards(int $userId): array
{
    return DB::all("SELECT * FROM " . DB::t('planner_rewards') . " WHERE user_id=? ORDER BY id DESC LIMIT 24", [$userId]);
}

/** تعداد روزهای یک بازهٔ استریک که قبلاً پاداش خورده (جلوگیری از پاداش تکراری) */
function fr_planner_reward_claimed(int $userId, int $streakTo): bool
{
    return (bool)DB::val("SELECT id FROM " . DB::t('planner_rewards') . " WHERE user_id=? AND streak_to=?", [$userId, $streakTo]);
}

/**
 * بررسی و صدور پاداش استریک — هر بار که تسکی done می‌شود صدا زده می‌شود.
 * اگر استریک فعلی مضربِ هدف (planner_streak_target) باشد و برای آن بازه پاداش ثبت نشده باشد → صدور.
 */
function fr_planner_check_reward(int $userId): void
{
    $target = max(1, (int)DB::setting('planner_streak_target', '7'));
    $streak = fr_planner_current_streak($userId);
    if ($streak < $target || $streak % $target !== 0) return;
    if (fr_planner_reward_claimed($userId, $streak)) return;

    $type    = (string)DB::setting('planner_reward_type', 'discount');
    $percent = max(1, min(100, (int)DB::setting('planner_reward_percent', '20')));
    $msg     = trim((string)DB::setting('planner_reward_message', ''));
    $code    = null;

    if ($type === 'discount') {
        /* صدور کد تخفیف خودکارِ مخصوص همین کاربر */
        $code = 'PLANNER-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            DB::insert('discounts', [
                'code'        => $code,
                'type'        => 'percent',
                'value'       => $percent,
                'max_amount'  => null,
                'min_order'   => 0,
                'test_id'     => null,   // روی همهٔ تست‌های روانشناسی/رویدادها اعمال می‌شود
                'event_id'    => null,
                'user_id'     => $userId,
                'usage_limit' => 1,
                'per_user'    => 1,
                'used_count'  => 0,
                'starts_at'   => null,
                'expires_at'  => date('Y-m-d H:i:s', strtotime('+30 days')),
                'status'      => 'active',
            ]);
        } catch (Throwable $e) { error_log('feria: planner reward discount: ' . $e->getMessage()); $code = null; }
    }

    DB::run("INSERT INTO " . DB::t('planner_rewards') . " (user_id,streak_from,streak_to,type,percent,code,note,created_at)
             VALUES (?,?,?,?,?,?,?,NOW())",
            [$userId, $streak - $target + 1, $streak, $code ? 'discount' : 'message', $code ? $percent : null, $code, $msg]);

    log_activity('planner_reward', 'user', $userId, ['streak' => $streak, 'code' => $code]);

    /* اطلاع‌رسانی به کاربر از طریق پیام‌رسان‌ها (اگر متصل باشد) */
    $u = DB::one("SELECT telegram_chat_id,bale_chat_id,first_name FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    if ($u) {
        $text = "🏆 آفرین " . ($u['first_name'] ?: 'عزیز') . "!\n"
              . fa_num($streak) . " روز متوالی برنامهٔ روزانه‌ات را کامل کردی.\n"
              . ($code ? "🎁 پاداشت: کد تخفیف " . fa_num($percent) . "٪ با کد «{$code}» (مهلت: ۳۰ روز)" : ($msg !== '' ? $msg : ''))
              . "\n— " . (string)DB::setting('site_title', 'فریا روان');
        fr_planner_notify($u, $text);
    }
}

/** ارسال پیام به کاربر از هر دو کانال متصل */
function fr_planner_notify(array $userRow, string $text): void
{
    if (!empty($userRow['telegram_chat_id'])) {
        try { require_once __DIR__ . '/../core/Telegram.php'; Telegram::sendMessage((string)$userRow['telegram_chat_id'], $text); } catch (Throwable $e) { /* بی‌صدا */ }
    }
    if (!empty($userRow['bale_chat_id'])) {
        try { require_once __DIR__ . '/../core/Bale.php'; Bale::sendMessage((string)$userRow['bale_chat_id'], $text); } catch (Throwable $e) { /* بی‌صدا */ }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   نسخهٔ ۲٫۳ — قابلیت‌های تازهٔ پلنر: کارهای تکرارشونده، آمار پیشرفت،
   دستاوردها، انتقال مانده‌ها به امروز
 * ═══════════════════════════════════════════════════════════════════════════ */

/** کارهای تکرارشوندهٔ کاربر */
function fr_planner_recur_list(int $userId): array
{
    try {
        return DB::all("SELECT * FROM " . DB::t('planner_recur') . " WHERE user_id=? ORDER BY active DESC, id DESC", [$userId]);
    } catch (Throwable $e) { return []; }
}

/** افزودن الگوی کار تکرارشونده؛ خروجی id */
function fr_planner_recur_add(int $userId, string $title, string $timeNote, string $priority, string $category, string $freq): int
{
    $title = mb_substr(trim($title), 0, 190);
    if ($title === '' || $userId <= 0) return 0;
    if (!in_array($priority, ['low','normal','high'], true)) $priority = 'normal';
    if (!in_array($freq, ['daily','weekday','weekly'], true)) $freq = 'daily';
    return DB::insert('planner_recur', [
        'user_id' => $userId, 'title' => $title, 'time_note' => mb_substr(trim($timeNote), 0, 5),
        'priority' => $priority, 'category' => mb_substr(trim($category), 0, 40) ?: 'personal', 'freq' => $freq, 'active' => 1,
    ]);
}

/** آیا الگو در روز مشخص باید بیاید؟ (تقویم فارسی: جمعه تنها روز تعطیل هفته) */
function fr_planner_recur_matches(array $recur, string $day): bool
{
    $w = (int)date('w', strtotime($day));   // 0=یکشنبه … 5=جمعه 6=شنبه
    return match ($recur['freq']) {
        'daily'   => true,
        'weekday' => $w !== 5,               // شنبه تا پنجشنبه
        'weekly'  => $w === (int)date('w', strtotime($recur['created_at'])),
        default   => false,
    };
}

/**
 * Materialize: کارهای تکرارشوندهٔ فعال که امروز (یا روز داده‌شده) هنوز ساخته نشده‌اند را می‌سازد.
 * idempotent — با ستون recur_id روی planner_tasks جلوی تکثیر گرفته می‌شود.
 */
function fr_planner_ensure_day(int $userId, string $day): void
{
    try {
        $recurs = DB::all("SELECT * FROM " . DB::t('planner_recur') . " WHERE user_id=? AND active=1", [$userId]);
        if (!$recurs) return;
        $have = DB::all("SELECT recur_id FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=? AND recur_id IS NOT NULL", [$userId, $day]);
        $haveSet = array_flip(array_map('intval', array_column($have, 'recur_id')));
        foreach ($recurs as $r) {
            if (isset($haveSet[(int)$r['id']]) || !fr_planner_recur_matches($r, $day)) continue;
            DB::insert('planner_tasks', [
                'user_id' => $userId, 'day' => $day, 'title' => (string)$r['title'], 'time_note' => $r['time_note'],
                'priority' => $r['priority'], 'category' => $r['category'], 'done' => 0, 'recur_id' => (int)$r['id'],
            ]);
        }
    } catch (Throwable $e) { /* جدول هنوز نیامده */ }
}

/** انتقال کارهای ناتمامِ روزهای گذشته به امروز. خروجی: تعداد منتقل‌شده */
function fr_planner_carry_over(int $userId): int
{
    $today = fr_planner_today();
    DB::run("UPDATE " . DB::t('planner_tasks') . " SET day=? WHERE user_id=? AND done=0 AND day<?", [$today, $userId, $today]);
    return (int)DB::val("SELECT ROW_COUNT()");
}

/* ---------------------------------------------------------------------------
 | نسخهٔ ۲٫۴ — یادآوری چندنوبتی روزانهٔ پلنر (قالب ۲۴ ساعته کامل)
 * -------------------------------------------------------------------------*/
/** نوبت‌های یادآوری امروز — همیشه HH:MM مرتب‌شده؛ سازگار با تنظیم تک‌نوبتی قدیمی */
function fr_planner_remind_slots(): array
{
    $count = (int)DB::setting('planner_remind_count', '0');
    $times = [];
    $raw = (string)DB::setting('planner_remind_times', '');
    if ($raw !== '' && str_starts_with(ltrim($raw), '[')) {
        foreach ((array)json_decode($raw, true) as $t) {
            $t = fr_valid_time((string)$t, '');
            if ($t !== '') $times[] = $t;
        }
    }
    if (!$times) {   // سازگاری با نسخهٔ ۲٫۲/۲٫۳ (تنها یک planner_remind_time)
        $times = [fr_valid_time((string)DB::setting('planner_remind_time', '08:30'), '08:30')];
    }
    sort($times);
    if ($count > 0) $times = array_slice($times, 0, min(6, $count));
    return array_values(array_slice($times, 0, 6));
}

/** ذخیرهٔ نوبت‌ها از پیشخوان (ورودی خام کاربر) — خروجی: آرایهٔ نهایی ذخیره‌شده */
function fr_planner_remind_save(int $count, array $inputs): array
{
    $count = max(1, min(6, $count));
    $times = [];
    foreach ($inputs as $t) {
        $t = fr_valid_time((string)$t, '');
        if ($t !== '' && !in_array($t, $times, true)) $times[] = $t;
    }
    sort($times);
    if (!$times) $times = ['08:30'];
    $times = array_values(array_slice($times, 0, $count));
    DB::setSetting('planner_remind_count', (string)$count);
    DB::setSetting('planner_remind_times', json_encode($times, JSON_UNESCAPED_UNICODE));
    /* سازگاری با نسخه‌های قبلی/گزارش‌های قدیمی: اولین نوبت، همان planner_remind_time است */
    DB::setSetting('planner_remind_time', $times[0]);
    return $times;
}

/** وضعیت ارسال امروزِ هر نوبت (برای نمایش در پیشخوان) */
function fr_planner_remind_status_today(): array
{
    $base = 'planner_reminded_' . date('Ymd') . '_';
    $out = [];
    foreach (fr_planner_remind_slots() as $i => $hhmm) {
        $out[] = ['idx' => $i, 'time' => $hhmm, 'sent' => (string)DB::setting($base . $i, '0') === '1'];
    }
    return $out;
}

/** متن یادآوری یک نوبت برای یک کاربر (سلام بر اساس ساعت ۲۴ ساعتهٔ همان نوبت) */
function fr_planner_remind_compose(array $usr, int $slotIdx, string $hhmm, string $today): string
{
    $pending = 0; $lines = [];
    foreach (DB::all("SELECT title,time_note FROM " . DB::t('planner_tasks') .
                     " WHERE user_id=? AND day=? AND done=0 ORDER BY FIELD(priority,'high','normal','low') LIMIT 10",
                     [(int)$usr['id'], $today]) as $t) {
        $pending++;
        $lines[] = '▫️ ' . ($t['time_note'] ? fa_num($t['time_note']) . ' — ' : '') . $t['title'];
    }
    $h = (int)substr($hhmm, 0, 2);
    $greet = $h < 12 ? '🌅 صبح بخیر' : ($h < 16 ? '🌤 ظهر بخیر' : ($h < 19 ? '🌆 عصر بخیر' : '🌙 شب بخیر'));
    $site = (string)DB::setting('site_title', 'فریا روان');
    $tail = $slotIdx === 0
        ? '✅ هر روز کامل، یک پله به جایزهٔ استریک نزدیک‌ترت می‌کند.'
        : '⏰ فقط چند قدم مانده تا «روز کامل» — الان جمعشان کن!';
    return $greet . ' ' . ($usr['first_name'] ?: 'عزیز') . "!
"
         . 'در برنامهٔ امروزت ' . fa_num($pending) . ' کار مانده:' . "
"
         . implode("
", $lines) . "
"
         . $tail . "
"
         . '— ' . $site;
}

/** آمار کلی پیشرفت کاربر */
function fr_planner_progress_stats(int $userId): array
{
    $today = fr_planner_today();
    $total  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=?", [$userId]);
    $done   = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=1", [$userId]);
    $days   = (int)DB::val("SELECT COUNT(DISTINCT day) FROM " . DB::t('planner_tasks') . " WHERE user_id=?", [$userId]);
    /* روزهای کامل */
    $completeDays = 0;
    foreach (DB::all("SELECT day, COUNT(*) AS total, SUM(done) AS dn FROM " . DB::t('planner_tasks') . " WHERE user_id=? GROUP BY day", [$userId]) as $r) {
        if ((int)$r['total'] > 0 && (int)$r['dn'] === (int)$r['total']) $completeDays++;
    }
    /* بهترین استریک */
    $best = 0; $cur = 0; $lastD = null;
    $rows = DB::all("SELECT day, COUNT(*) AS total, SUM(done) AS dn FROM " . DB::t('planner_tasks') . " WHERE user_id=? GROUP BY day ORDER BY day", [$userId]);
    foreach ($rows as $r) {
        $okDay = (int)$r['total'] > 0 && (int)$r['dn'] === (int)$r['total'];
        if ($okDay) {
            $cur = ($lastD && date('Y-m-d', strtotime($lastD . ' +1 day')) === $r['day']) ? $cur + 1 : 1;
            $best = max($best, $cur);
        } else $cur = 0;
        $lastD = $r['day'];
    }
    /* درصد موفقیت ۳۰ روز اخیر */
    $rtot = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day >= DATE_SUB(?, INTERVAL 29 DAY)", [$userId, $today]);
    $rdone= (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=1 AND day >= DATE_SUB(?, INTERVAL 29 DAY)", [$userId, $today]);
    return [
        'total' => $total, 'done' => $done, 'days' => $days, 'complete_days' => $completeDays,
        'best_streak' => $best, 'rate30' => $rtot ? (int)round($rdone / $rtot * 100) : 0,
    ];
}

/* ─────────────────────────────────────────────────────────────────────────
   ۲٫۷ — پلنر حرفه‌ای: دسته‌ها با رنگ، جستجو/فیلتر، نمای هفته، عملیات گروهی،
   یادآور چندکانالهٔ هر کار (تلگرام/بله/پیامک)
 * ─────────────────────────────────────────────────────────────────────── */

/** نقشهٔ دسته‌ها: کلید → [برچسب, رنگ] (پیش‌فرض‌ها + سفارشی‌های کاربر) */
function fr_planner_cat_map(int $userId): array
{
    $map = [
        'personal' => ['شخصی', '#1e3a6e'],
        'study'    => ['درس و رشد', '#0f766e'],
        'work'     => ['کار', '#8a5a00'],
        'health'   => ['سلامت', '#b91c1c'],
        'fun'      => ['تفریح', '#6d28d9'],
    ];
    try {
        foreach (DB::all("SELECT name,color FROM " . DB::t('planner_cats') . " WHERE user_id=? ORDER BY sort,id", [$userId]) as $row) {
            $map[(string)$row['name']] = [(string)$row['name'], (string)$row['color']];
        }
    } catch (Throwable $e) { /* جدول ۲٫۷ هنوز نیامده */ }
    return $map;
}

/** فهرست دسته‌های سفارشی کاربر */
function fr_planner_cats(int $userId): array
{
    try {
        return DB::all("SELECT * FROM " . DB::t('planner_cats') . " WHERE user_id=? ORDER BY sort,id", [$userId]);
    } catch (Throwable $e) { return []; }
}

/** افزودن دستهٔ سفارشی (نام + رنگ)؛ نام تکراری → 0 */
function fr_planner_cat_add(int $userId, string $name, string $color): int
{
    $name = mb_substr(trim($name), 0, 40);
    if ($name === '') return 0;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#1e3a6e';
    try {
        return DB::insert('planner_cats', ['user_id' => $userId, 'name' => $name, 'color' => $color]);
    } catch (Throwable $e) { return 0; }
}

/** حذف دستهٔ سفارشی — کارها دست‌نخورده می‌مانند (برچسبشان حفظ می‌شود) */
function fr_planner_cat_delete(int $userId, int $cid): bool
{
    try {
        DB::run("DELETE FROM " . DB::t('planner_cats') . " WHERE id=? AND user_id=?", [$cid, $userId]);
        return true;
    } catch (Throwable $e) { return false; }
}

/**
 * جستجو/فیلتر همهٔ کارهای کاربر (برگهٔ «همهٔ کارها») با صفحه‌بندی.
 * فیلترها: q (در عنوان/یادداشت)، cat، priority، done ('1'/'0'/'')، from/to (تاریخ)
 */
function fr_planner_search(int $userId, array $filters, int $page = 1, int $per = 20): array
{
    $w = "user_id=?"; $p = [$userId];
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') { $w .= " AND (title LIKE ? OR note LIKE ?)"; $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%'; }
    $cat = trim((string)($filters['cat'] ?? ''));
    if ($cat !== '') { $w .= " AND category=?"; $p[] = $cat; }
    $pri = (string)($filters['pri'] ?? '');
    if (in_array($pri, ['low','normal','high'], true)) { $w .= " AND priority=?"; $p[] = $pri; }
    $done = (string)($filters['done'] ?? '');
    if ($done === '1' || $done === '0') { $w .= " AND done=?"; $p[] = (int)$done; }
    $from = (string)($filters['from'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w .= " AND day>=?"; $p[] = $from; }
    $to = (string)($filters['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w .= " AND day<=?"; $p[] = $to; }
    $total = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE $w", $p);
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($page, $pages));
    $rows = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE $w ORDER BY day DESC, FIELD(priority,'high','normal','low'), id DESC
                     LIMIT " . (($page - 1) * $per) . ", " . (int)$per, $p);
    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** عملیات گروهی روی کارهای انتخاب‌شده — تعداد متأثر */
function fr_planner_bulk(int $userId, array $ids, string $op): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return 0;
    $in = implode(',', $ids);
    if ($op === 'delete') {
        return (int)DB::run("DELETE FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND id IN ($in)", [$userId])->rowCount();
    }
    $done = $op === 'done' ? 1 : 0;
    return (int)DB::run("UPDATE " . DB::t('planner_tasks') . " SET done=?, done_at=" . ($done ? 'NOW()' : 'NULL') . " WHERE user_id=? AND id IN ($in)", [$done, $userId])->rowCount();
}

/** نمای هفته: ۷ روز از مبدأ (weekOffset هفتهٔ ±N نسبت به هفتهٔ جاری) */
function fr_planner_week_view(int $userId, int $weekOffset = 0): array
{
    /* شنبه آغاز هفتهٔ ایران */
    $today = fr_planner_today();
    $dow = (int)date('w', strtotime($today));   // 0=یکشنبه … 6=شنبه
    $toSaturday = ($dow + 1) % 7;
    $start = date('Y-m-d', strtotime($today . " -$toSaturday day " . ($weekOffset >= 0 ? "+$weekOffset" : (string)$weekOffset) . " week"));
    $end = date('Y-m-d', strtotime($start . ' +6 day'));
    $rows = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day BETWEEN ? AND ?
                     ORDER BY day, FIELD(priority,'high','normal','low'), id", [$userId, $start, $end]);
    $byDay = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($start . " +$i day"));
        $byDay[$d] = [];
    }
    foreach ($rows as $r) {
        if (isset($byDay[(string)$r['day']])) $byDay[(string)$r['day']][] = $r;
    }
    return ['start' => $start, 'end' => $end, 'days' => $byDay];
}

/** کانال‌های فعال یادآوری پلنر (تنظیمات مدیر): آرایه‌ای از telegram,bale,sms */
function fr_planner_notify_channels(): array
{
    $raw = (string)DB::setting('planner_notify_channels', 'telegram,bale');
    $list = array_values(array_intersect(array_filter(array_map('trim', explode(',', strtolower($raw)))), ['telegram','bale','sms']));
    return $list ?: ['telegram','bale'];
}

/**
 * اجرای یادآورهای موعددار — از کرون reminders.php هر چند دقیقه:
 * کارهایی که remind_at گذشته و reminded_at ندارند و انجام‌نشده‌اند؛
 * برای هر کاربر از کانال‌های انتخابیِ مدیر ارسال و ثبت می‌شود.
 */
function fr_planner_remind_due(int $limit = 80): int
{
    $rows = DB::all("SELECT t.*, u.mobile, u.first_name, u.telegram_chat_id, u.bale_chat_id, u.id AS uid
                     FROM " . DB::t('planner_tasks') . " t
                     JOIN " . DB::t('users') . " u ON u.id=t.user_id AND u.status='active'
                     WHERE t.remind_at IS NOT NULL AND t.reminded_at IS NULL AND t.done=0
                       AND t.remind_at <= NOW()
                     ORDER BY t.remind_at LIMIT " . (int)$limit);
    if (!$rows) return 0;
    $channels = fr_planner_notify_channels();
    $site = (string)DB::setting('site_title', 'فریا روان');
    $sent = 0;
    foreach ($rows as $r) {
        $txt = "⏰ یادآوری برنامهٔ روزانه — «{$site}»\n"
             . "🗓 " . fa_num(jdate('l j F', strtotime((string)$r['day'])))
             . (!empty($r['remind_at']) ? ' — ساعت ' . fa_num(substr((string)$r['remind_at'], 11, 5)) : '') . "\n"
             . "▫️ «" . $r['title'] . "»"
             . (trim((string)$r['note']) !== '' ? "\n📝 " . mb_substr((string)$r['note'], 0, 120) : '') . "\n";
        $anyOk = false; $anyTried = false;
        foreach ($channels as $ch) {
            if ($ch === 'telegram' || $ch === 'bale') {
                $col = $ch === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
                $cid = trim((string)($r[$col] ?? ''));
                if ($cid === '') continue;
                $anyTried = true;
                try {
                    require_once __DIR__ . '/snotify.php';
                    [$enabled, $send] = fr_snotify_driver($ch);
                    if (!$enabled) continue;
                    [$ok, $resp] = $send($cid, $txt);
                    if ($ok) $anyOk = true;
                    if (function_exists('fr_snotify_log')) { try { fr_snotify_log((int)$r['uid'], $ch, 'planner', mb_substr($txt, 0, 900), (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : '')); } catch (Throwable $e) { } }
                } catch (Throwable $e) { }
            } elseif ($ch === 'sms') {
                $mob = trim((string)($r['mobile'] ?? ''));
                if ($mob === '') continue;
                $anyTried = true;
                try {
                    require_once __DIR__ . '/../core/Kavenegar.php';
                    if ((new Kavenegar())->send($mob, $txt, 'planner_remind')) $anyOk = true;
                } catch (Throwable $e) { }
            }
        }
        DB::run("UPDATE " . DB::t('planner_tasks') . " SET reminded_at=NOW() WHERE id=?", [(int)$r['id']]);
        if ($anyOk) $sent++;
    }
    return $sent;
}

/** نمودار میله‌ای N روز اخیر: برچسب شمسی + درصد */
function fr_planner_chart(int $userId, int $days = 14): array
{
    $rows = DB::all("SELECT day, COUNT(*) AS total, SUM(CASE WHEN done=1 THEN 1 ELSE 0 END) AS dn
                     FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day >= DATE_SUB(?, INTERVAL " . ((int)$days - 1) . " DAY) GROUP BY day",
                    [$userId, fr_planner_today()]);
    $map = [];
    foreach ($rows as $r) $map[$r['day']] = $r;
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $t = isset($map[$d]) ? (int)$map[$d]['total'] : 0;
        $dn = isset($map[$d]) ? (int)$map[$d]['dn'] : 0;
        $out[] = ['day' => $d, 'pct' => $t ? (int)round($dn / $t * 100) : 0, 'have' => $t > 0];
    }
    return $out;
}

/** دستاوردهای انگیزشی — هر نشان با وضعیت باز/قفل */
function fr_planner_badges(int $userId): array
{
    $st  = fr_planner_progress_stats($userId);
    $stk = fr_planner_current_streak($userId);
    $early = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=1 AND HOUR(done_at) < 9", [$userId]);
    $wkd   = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=1 AND WEEKDAY(day) IN (2,3)", [$userId]); /* پنجشنبه/جمعه میلادی ≈ آخر هفته ایران گاه ناهماهنگ — ساده نگه داشتیم */
    $all = [
        ['first', '🐣', 'قدم اول', 'اولین کار را در برنامه ثبت کن', $st['total'] >= 1],
        ['ten',   '🌿', 'ده کار', 'انجام ۱۰ کار از برنامهٔ روزانه', $st['done'] >= 10],
        ['fifty', '🌳', 'پنجاه کار', 'انجام ۵۰ کار — نظم تازه‌متولد شده', $st['done'] >= 50],
        ['hundred','🏔', 'صد کار', 'سه‌رقمی! ستایشِ استمرار تو', $st['done'] >= 100],
        ['streak3','🔥', 'سه‌روز گرم', '۳ روز متوالی کامل', $stk >= 3 || $st['best_streak'] >= 3],
        ['streak7','🏆', 'هفتهٔ طلایی', '۷ روز متوالی کامل', $stk >= 7 || $st['best_streak'] >= 7],
        ['streak14','💎', 'دو هفتهٔ الماس', '۱۴ روز متوالی کامل', $stk >= 14 || $st['best_streak'] >= 14],
        ['streak30','👑', 'ماهِ پادشاهی', '۳۰ روز متوالی کامل', $stk >= 30 || $st['best_streak'] >= 30],
        ['early', '🌅', 'سحرخیز', '۵ کار را پیش از ساعت ۹ صبح انجام بده', $early >= 5],
        ['week',  '📊', 'هفته‌باز', 'در هر روز یک هفته، حداقل یک کار انجام‌شده', $st['days'] >= 7],
        ['rate80','🎯', 'تیرانداز هدف', 'موفقیت ۸۰٪ در ۳۰ روز اخیر (با حداقل ۱۵ کار)', $st['rate30'] >= 80 && $st['done'] >= 15],
        ['planner30','🗓', 'ماهنامه', 'کار در ۳۰ روز متمایز ثبت شده', $st['days'] >= 30],
    ];
    return $all;
}
