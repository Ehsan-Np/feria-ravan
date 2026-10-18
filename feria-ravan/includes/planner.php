<?php
/**
 * فریا روان — برنامه‌ریز روزانه و پایش رشد روان‌شناختی کاربر — نسخهٔ ۴٫۹٫۰ (۱۴۰۵)
 * ─ تقویم کاملاً هجری شمسی سال ۱۴۰۵ با شروع هفته از شنبه
 * ─ تسک‌های روزانه، پایش خلق و انرژی روانی، استریک روزهای متوالی و پاداش خودکار (کد تخفیف)
 * ─ ارسال هوشمند و خودکار یادآورها از طریق تلگرام، بله، روبیکا و پیامک
 * ─ ماتریس اولویت، اتاق تمرکز عمیق (پومودورو) و پایش دلتای رشد آزمون‌ها
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** آیا برنامه‌ریز فعال است؟ */
function fr_planner_enabled(): bool
{
    return (string)DB::setting('planner_enabled', '1') === '1';
}

/** تاریخ امروز به میلادی (مبنای ذخیره‌سازی در دیتابیس) */
function fr_planner_today(): string
{
    date_default_timezone_set('Asia/Tehran');
    return date('Y-m-d');
}

/** تاریخ امروز به هجری شمسی (مثلاً ۱۴۰۵/۰۷/۲۶) */
function fr_planner_shamsi_today(): string
{
    date_default_timezone_set('Asia/Tehran');
    return (string)jdate('Y/m/d');
}

/** تبدیل تاریخ میلادی یا کلیدواژه به تاریخ استاندارد میلادی Y-m-d برای دیتابیس */
function fr_planner_normalize_day(string $day): string
{
    $day = trim($day);
    if ($day === '' || $day === 'today') return fr_planner_today();
    if ($day === 'tomorrow') return date('Y-m-d', strtotime('+1 day'));
    if ($day === 'day_after_tomorrow') return date('Y-m-d', strtotime('+2 days'));

    // اگر تاریخ شمسی ارسال شده باشد (مانند 1405/07/26 یا 1405-07-26)
    $cleanDay = en_num($day);
    if (preg_match('#^(13|14)\d{2}[-/.](0?[1-9]|1[0-2])[-/.](0?[1-9]|[12][0-9]|3[01])$#', $cleanDay)) {
        $conv = jalali_to_mysql($cleanDay);
        if ($conv) return $conv;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleanDay)) {
        return $cleanDay;
    }
    return fr_planner_today();
}

/** تبدیل تاریخ میلادی به فرمت شمسی YYYY/MM/DD */
function fr_planner_day_to_shamsi(string $day, string $format = 'Y/m/d'): string
{
    $ts = strtotime($day) ?: time();
    return (string)jdate($format, $ts);
}

/** تبدیل تاریخ شمسی به تاریخ میلادی Y-m-d */
function fr_planner_shamsi_to_day(string $shamsi): string
{
    $conv = jalali_to_mysql($shamsi);
    return $conv ?: fr_planner_today();
}

/** فرمت‌بندی خوانا و کامل تاریخ شمسی سال ۱۴۰۵ با نام روز هفته (مثلاً: شنبه ۲۶ مهر ۱۴۰۵) */
function fr_planner_format_day(string $day): string
{
    $ts = strtotime($day) ?: time();
    return fa_num((string)jdate('l j F Y', $ts));
}

/** نرمال‌سازی تاریخ شمسی با پد صفر (مثلاً ۱۴۰۵-۱-۵ به 1405-01-05) */
function fr_jday_normalize(string $day): string
{
    $day = en_num(trim($day));
    $parts = preg_split('#[-/.]#', $day);
    if (count($parts) === 3) {
        $y = (int)$parts[0];
        $m = (int)$parts[1];
        $d = (int)$parts[2];
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
    return $day;
}

/** افزودن یا کاستن روز به تاریخ شمسی با رعایت دقیق طول ماه‌های جلالی */
function fr_jday_add(string $jday, int $days): string
{
    $jday = fr_jday_normalize($jday);
    $g = jalali_to_mysql($jday);
    $ts = (strtotime($g . ' 12:00:00') ?: time()) + ($days * 86400);
    return en_num((string)jdate('Y-m-d', $ts, '', 'Asia/Tehran', 'en'));
}

/** محاسبه فاصله روز بین دو تاریخ شمسی بدون خطای DST */
function fr_jday_diff(string $jday1, string $jday2): int
{
    $g1 = jalali_to_mysql(fr_jday_normalize($jday1));
    $g2 = jalali_to_mysql(fr_jday_normalize($jday2));
    $ts1 = strtotime($g1 . ' 12:00:00') ?: 0;
    $ts2 = strtotime($g2 . ' 12:00:00') ?: 0;
    return (int)round(($ts1 - $ts2) / 86400);
}

/** لیست ۷ روز هفته از شنبه تا جمعه بر اساس تقویم شمسی ۱۴۰۵ */
function fr_jweek_days(string $refDay = ''): array
{
    $refDay = $refDay !== '' ? fr_jday_normalize($refDay) : (string)jdate('Y-m-d', time(), '', 'Asia/Tehran', 'en');
    $gRef = jalali_to_mysql($refDay);
    $tsRef = strtotime($gRef . ' 12:00:00') ?: time();

    $w = (int)date('w', $tsRef);
    $shamsiW = ($w + 1) % 7; // شنبه = 0, یکشنبه = 1, ... جمعه = 6

    $satTs = $tsRef - ($shamsiW * 86400);
    $dayNames = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];
    $todayJ = en_num((string)jdate('Y-m-d', time(), '', 'Asia/Tehran', 'en'));

    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $curTs = $satTs + ($i * 86400);
        $curJ = en_num((string)jdate('Y-m-d', $curTs, '', 'Asia/Tehran', 'en'));
        $curNum = en_num((string)jdate('j', $curTs, '', 'Asia/Tehran', 'en'));

        $days[] = [
            'idx'       => $i,
            'name'      => $dayNames[$i],
            'day'       => $curJ,
            'num'       => $curNum,
            'is_today'  => ($curJ === $todayJ),
            'is_future' => ($curJ > $todayJ),
            'ts'        => $curTs,
        ];
    }
    return $days;
}

/** نام ماه شمسی */
function fr_planner_shamsi_month_name(int $monthNum): string
{
    $names = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    return $names[$monthNum] ?? '';
}

function fr_jmonth_name(int $monthNum): string
{
    return fr_planner_shamsi_month_name($monthNum);
}

/** تسک‌های یک کاربر برای یک روز مشخص */
function fr_planner_day_tasks(int $userId, string $day): array
{
    $day = fr_planner_normalize_day($day);
    return DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=? ORDER BY FIELD(priority,'high','normal','low'), id",
                   [$userId, $day]);
}

/** افزودن تسک به برنامهٔ روزانه با پشتیبانی کامل از تاریخ شمسی سال ۱۴۰۵ */
function fr_planner_add(int $userId, string $day, string $title, string $timeNote, string $priority, string $category, string $note = '', ?string $remindAt = null): int
{
    $day = fr_planner_normalize_day($day);
    $title    = mb_substr(trim($title), 0, 190);
    $timeNote = mb_substr(trim($timeNote), 0, 5);   // «08:30»
    if ($title === '' || $userId <= 0) return 0;
    if (!in_array($priority, ['low','normal','high'], true)) $priority = 'normal';
    $category = mb_substr(trim($category), 0, 60);
    $remindAt = fr_planner_valid_dt($remindAt);

    return DB::insert('planner_tasks', [
        'user_id'   => $userId,
        'day'       => $day,
        'title'     => $title,
        'time_note' => $timeNote,
        'priority'  => $priority,
        'category'  => $category,
        'done'      => 0,
        'note'      => mb_substr(trim($note), 0, 500),
        'remind_at' => $remindAt,
    ]);
}

/** اعتبارسنجی datetime ورودی کاربر (فرمت 2026-08-10T14:30 یا با فاصله) */
function fr_planner_valid_dt(?string $dt): ?string
{
    $dt = trim((string)$dt);
    if ($dt === '') return null;
    $dt = str_replace('T', ' ', en_num($dt));

    // اگر تاریخ در قالب شمسی باشد (مثلاً 1405/07/26 14:30)
    if (preg_match('#^((?:13|14)\d{2})[-/.](0?[1-9]|1[0-2])[-/.](0?[1-9]|[12][0-9]|3[01])\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?#', $dt, $mj)) {
        $gDay = jalali_to_mysql($mj[1] . '/' . $mj[2] . '/' . $mj[3]);
        if ($gDay) {
            $h = (int)$mj[4]; $i = (int)$mj[5]; $s = isset($mj[6]) ? (int)$mj[6] : 0;
            return sprintf('%s %02d:%02d:%02d', $gDay, $h, $i, $s);
        }
    }

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $dt, $m)) return null;
    if ((int)$m[4] > 23 || (int)$m[5] > 59) return null;
    return sprintf('%04d-%02d-%02d %02d:%02d:00', (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5]);
}

/** ویرایش کامل تسک (فقط مالک) */
function fr_planner_update(int $userId, int $taskId, array $f): bool
{
    $t = DB::one("SELECT id FROM " . DB::t('planner_tasks') . " WHERE id=? AND user_id=?", [$taskId, $userId]);
    if (!$t) return false;
    $title = mb_substr(trim((string)($f['title'] ?? '')), 0, 190);
    if ($title === '') return false;
    $day = fr_planner_normalize_day((string)($f['day'] ?? ''));
    $pri0 = (string)($f['priority'] ?? 'normal');
    $pri = in_array($pri0, ['low','normal','high'], true) ? $pri0 : 'normal';

    $remindAt = null;
    $rawRemind = trim((string)($f['remind_at'] ?? ''));
    if ($rawRemind !== '') {
        $rawRemind = en_num($rawRemind);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $rawRemind, $tm)) {
            $remindAt = sprintf('%s %02d:%02d:00', $day, (int)$tm[1], (int)$tm[2]);
        } else {
            $remindAt = fr_planner_valid_dt($rawRemind);
        }
    }

    DB::update('planner_tasks', [
        'title'       => $title,
        'day'         => $day,
        'time_note'   => mb_substr(trim((string)($f['time_note'] ?? '')), 0, 5) ?: null,
        'priority'    => $pri,
        'category'    => mb_substr(trim((string)($f['category'] ?? 'personal')), 0, 60) ?: 'personal',
        'note'        => mb_substr(trim((string)($f['note'] ?? '')), 0, 500),
        'remind_at'   => $remindAt,
        'reminded_at' => null, // اگر ساعت یادآور تغییر کرد، امکان ارسال مجدد فعال شود
    ], 'id=?', [$taskId]);
    return true;
}

/** تعویض وضعیت انجام یک تسک (فقط مالک) */
function fr_planner_toggle(int $userId, int $taskId): ?bool
{
    $t = DB::one("SELECT id, done, day FROM " . DB::t('planner_tasks') . " WHERE id=? AND user_id=?", [$taskId, $userId]);
    if (!$t) return null;
    $new = $t['done'] ? 0 : 1;
    DB::run("UPDATE " . DB::t('planner_tasks') . " SET done=?, done_at=" . ($new ? 'NOW()' : 'NULL') . " WHERE id=?", [$new, $taskId]);
    if ($new) fr_planner_check_reward($userId);
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

/** یک روز «کامل» است اگر حداقل یک تسک داشته و همه done باشند */
function fr_planner_day_complete(int $userId, string $day): bool
{
    $r = DB::one("SELECT COUNT(*) AS total, SUM(CASE WHEN done=1 THEN 1 ELSE 0 END) AS done
                  FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=?", [$userId, $day]);
    return $r && (int)$r['total'] > 0 && (int)$r['done'] === (int)$r['total'];
}

/** استریک متوالی روزهای کامل تا امروز (یا دیروز) */
function fr_planner_current_streak(int $userId): int
{
    $anchor = fr_planner_today();
    if (!fr_planner_day_complete($userId, $anchor)) $anchor = date('Y-m-d', strtotime('-1 day'));
    $streak = 0; $d = $anchor;
    for ($i = 0; $i < 400; $i++) {
        if (fr_planner_day_complete($userId, $d)) {
            $streak++;
            $d = date('Y-m-d', strtotime($d . ' -1 day'));
        } else break;
    }
    return $streak;
}

/** آمار ۷ روز اخیر برای نوار هفتگی */
function fr_planner_week(int $userId): array
{
    $today = fr_planner_today();
    $rows = DB::all("SELECT day, COUNT(*) AS total, SUM(CASE WHEN done=1 THEN 1 ELSE 0 END) AS done
                     FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day >= DATE_SUB(?, INTERVAL 6 DAY)
                     GROUP BY day", [$userId, $today]);
    $map = [];
    foreach ($rows as $r) $map[$r['day']] = $r;
    $out = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $r = $map[$d] ?? null;
        $total = $r ? (int)$r['total'] : 0;
        $done  = $r ? (int)$r['done'] : 0;
        $out[] = [
            'day'      => $d,
            'shamsi'   => fr_planner_day_to_shamsi($d),
            'total'    => $total,
            'done'     => $done,
            'complete' => $total > 0 && $done === $total
        ];
    }
    return $out;
}

/**
 * نمای کامل هفته بر اساس تقویم رسمی ایران در سال ۱۴۰۵
 * هفته همواره از «شنبه» آغاز و به «جمعه» ختم می‌شود.
 */
function fr_planner_week_view_1405(int $userId, int $weekOffset = 0): array
{
    $today = fr_planner_today();
    // 0=یکشنبه ... 6=شنبه
    $dow = (int)date('w', strtotime($today));
    $toSaturday = ($dow + 1) % 7;

    $baseSaturdayTs = strtotime("$today -$toSaturday days");
    if ($weekOffset !== 0) {
        $baseSaturdayTs = strtotime(date('Y-m-d', $baseSaturdayTs) . " " . ($weekOffset > 0 ? "+$weekOffset" : (string)$weekOffset) . " weeks");
    }

    $start = date('Y-m-d', $baseSaturdayTs);
    $end   = date('Y-m-d', strtotime("$start +6 days"));

    $rows = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day BETWEEN ? AND ?
                     ORDER BY day, FIELD(priority,'high','normal','low'), id", [$userId, $start, $end]);

    $dayNames = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
    $byDay = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("$start +$i days"));
        $byDay[$d] = [
            'date'      => $d,
            'name'      => $dayNames[$i],
            'shamsi'    => fr_planner_day_to_shamsi($d),
            'shamsi_md' => (string)jdate('j F', strtotime($d)),
            'tasks'     => [],
        ];
    }
    foreach ($rows as $r) {
        $dStr = (string)$r['day'];
        if (isset($byDay[$dStr])) {
            $byDay[$dStr]['tasks'][] = $r;
        }
    }

    return [
        'start'     => $start,
        'end'       => $end,
        'start_fa'  => fa_num((string)jdate('j F ۱۴۰۵', strtotime($start))),
        'end_fa'    => fa_num((string)jdate('j F ۱۴۰۵', strtotime($end))),
        'days'      => $byDay,
    ];
}

/** سازگاری با تابع قبلی نمای هفته */
function fr_planner_week_view(int $userId, int $weekOffset = 0): array
{
    $wv = fr_planner_week_view_1405($userId, $weekOffset);
    $legacyDays = [];
    foreach ($wv['days'] as $d => $info) {
        $legacyDays[$d] = $info['tasks'];
    }
    return ['start' => $wv['start'], 'end' => $wv['end'], 'days' => $legacyDays];
}

/** پاداش‌های دریافت‌شدهٔ کاربر */
function fr_planner_rewards(int $userId): array
{
    return DB::all("SELECT * FROM " . DB::t('planner_rewards') . " WHERE user_id=? ORDER BY id DESC LIMIT 24", [$userId]);
}

/** بررسی پاداش استریک قبلی */
function fr_planner_reward_claimed(int $userId, int $streakTo): bool
{
    return (bool)DB::val("SELECT id FROM " . DB::t('planner_rewards') . " WHERE user_id=? AND streak_to=?", [$userId, $streakTo]);
}

/** بررسی و صدور خودکار پاداش استریک و ارسال به تلگرام، بله، روبیکا */
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
        $code = 'PLANNER-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            DB::insert('discounts', [
                'code'        => $code,
                'type'        => 'percent',
                'value'       => $percent,
                'user_id'     => $userId,
                'usage_limit' => 1,
                'per_user'    => 1,
                'used_count'  => 0,
                'expires_at'  => date('Y-m-d H:i:s', strtotime('+30 days')),
                'status'      => 'active',
            ]);
        } catch (Throwable $e) { $code = null; }
    }

    DB::run("INSERT INTO " . DB::t('planner_rewards') . " (user_id,streak_from,streak_to,type,percent,code,note,created_at)
             VALUES (?,?,?,?,?,?,?,NOW())",
            [$userId, $streak - $target + 1, $streak, $code ? 'discount' : 'message', $code ? $percent : null, $code, $msg]);

    log_activity('planner_reward', 'user', $userId, ['streak' => $streak, 'code' => $code]);

    // ارسال تبریک و کد تخفیف به تمامی پیام‌رسان‌های متصل کاربر (تلگرام، بله، روبیکا)
    $u = DB::one("SELECT telegram_chat_id, bale_chat_id, rubika_chat_id, first_name FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    if ($u) {
        $site = (string)DB::setting('site_title', 'فریا روان');
        $text = "🏆 تبریک فراوان " . ($u['first_name'] ?: 'همراه گرامی') . "!\n"
              . fa_num($streak) . " روز متوالی برنامهٔ روزانهٔ خود را با موفقیت کامل کردید.\n"
              . ($code ? "🎁 هدیهٔ استمرار شما: کد تخفیف " . fa_num($percent) . "٪ فریا با شناسه «{$code}» (اعتبار: ۳۰ روز)" : ($msg !== '' ? $msg : ''))
              . "\n— " . $site;
        fr_planner_notify($u, $text);
    }
}

/** ارسال پیام به کاربر از تمامی کانال‌های متصل (تلگرام / بله / روبیکا) */
function fr_planner_notify(array $userRow, string $text): void
{
    if (!empty($userRow['telegram_chat_id'])) {
        try {
            require_once __DIR__ . '/../core/Telegram.php';
            Telegram::sendMessage((string)$userRow['telegram_chat_id'], $text);
        } catch (Throwable $e) {}
    }
    if (!empty($userRow['bale_chat_id'])) {
        try {
            require_once __DIR__ . '/../core/Bale.php';
            Bale::sendMessage((string)$userRow['bale_chat_id'], $text);
        } catch (Throwable $e) {}
    }
    if (!empty($userRow['rubika_chat_id'])) {
        try {
            require_once __DIR__ . '/../core/Rubika.php';
            Rubika::sendMessage((string)$userRow['rubika_chat_id'], $text);
        } catch (Throwable $e) {}
    }
}

/** کارهای تکرارشوندهٔ کاربر */
function fr_planner_recur_list(int $userId): array
{
    try {
        return DB::all("SELECT * FROM " . DB::t('planner_recur') . " WHERE user_id=? ORDER BY active DESC, id DESC", [$userId]);
    } catch (Throwable $e) { return []; }
}

/** افزودن الگوی کار تکرارشونده */
function fr_planner_recur_add(int $userId, string $title, string $timeNote, string $priority, string $category, string $freq): int
{
    $title = mb_substr(trim($title), 0, 190);
    if ($title === '' || $userId <= 0) return 0;
    if (!in_array($priority, ['low','normal','high'], true)) $priority = 'normal';
    if (!in_array($freq, ['daily','weekday','weekly'], true)) $freq = 'daily';
    return DB::insert('planner_recur', [
        'user_id'   => $userId,
        'title'     => $title,
        'time_note' => mb_substr(trim($timeNote), 0, 5),
        'priority'  => $priority,
        'category'  => mb_substr(trim($category), 0, 60) ?: 'personal',
        'freq'      => $freq,
        'active'    => 1,
    ]);
}

/** تطابق الگوی تکرارشونده با روز (جمعه روز تعطیل رسمی) */
function fr_planner_recur_matches(array $recur, string $day): bool
{
    $w = (int)date('w', strtotime($day));
    return match ($recur['freq']) {
        'daily'   => true,
        'weekday' => $w !== 5, // شنبه تا پنجشنبه
        'weekly'  => $w === (int)date('w', strtotime($recur['created_at'])),
        default   => false,
    };
}

/** ساخت خودکار کارهای تکرارشوندهٔ روز */
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
                'user_id'   => $userId,
                'day'       => $day,
                'title'     => (string)$r['title'],
                'time_note' => $r['time_note'],
                'priority'  => $r['priority'],
                'category'  => $r['category'],
                'done'      => 0,
                'recur_id'  => (int)$r['id'],
            ]);
        }
    } catch (Throwable $e) {}
}

/** انتقال کارهای ناتمامِ روزهای گذشته به امروز */
function fr_planner_carry_over(int $userId): int
{
    $today = fr_planner_today();
    DB::run("UPDATE " . DB::t('planner_tasks') . " SET day=? WHERE user_id=? AND done=0 AND day<?", [$today, $userId, $today]);
    return (int)DB::val("SELECT ROW_COUNT()");
}

/** نوبت‌های یادآوری روزانه پلنر */
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
    if (!$times) {
        $times = [fr_valid_time((string)DB::setting('planner_remind_time', '08:30'), '08:30')];
    }
    sort($times);
    if ($count > 0) $times = array_slice($times, 0, min(6, $count));
    return array_values(array_slice($times, 0, 6));
}

/** ذخیرهٔ نوبت‌ها از پیشخوان (ورودی خام کاربر) */
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

/** متن یادآوری یک نوبت برای یک کاربر */
function fr_planner_remind_compose(array $usr, int $slotIdx, string $hhmm, string $today): string
{
    $pending = 0; $lines = [];
    foreach (DB::all("SELECT title, time_note FROM " . DB::t('planner_tasks') .
                     " WHERE user_id=? AND day=? AND done=0 ORDER BY FIELD(priority,'high','normal','low') LIMIT 10",
                     [(int)$usr['id'], $today]) as $t) {
        $pending++;
        $lines[] = '▫️ ' . ($t['time_note'] ? fa_num($t['time_note']) . ' — ' : '') . $t['title'];
    }
    $h = (int)substr($hhmm, 0, 2);
    $greet = $h < 12 ? '🌅 صبح بخیر' : ($h < 16 ? '🌤 ظهر بخیر' : ($h < 19 ? '🌆 عصر بخیر' : '🌙 شب بخیر'));
    $site = (string)DB::setting('site_title', 'فریا روان');
    $shamsiDate = fa_num(jdate('l j F ۱۴۰۵'));
    $tail = $slotIdx === 0
        ? '✅ هر روز متوالی موفق در سال ۱۴۰۵، یک گام به هدف استریک نزدیک‌ترتان می‌کند.'
        : '⏰ فقط چند قدم کوتاه مانده تا برنامه‌های امروزتان کامل شود — ادامه دهید!';

    return "$greet " . ($usr['first_name'] ?: 'همراه گرامی') . "!\n"
         . "🗓 $shamsiDate\n"
         . "در تقویم امروز شما " . fa_num($pending) . " برنامه و تمرین باقیمانده است:\n"
         . implode("\n", $lines) . "\n\n"
         . $tail . "\n— $site";
}

/** کانال‌های فعال یادآوری پلنر (تنظیمات مدیر): آرایه‌ای از telegram,bale,rubika,sms */
function fr_planner_notify_channels(): array
{
    $raw = (string)DB::setting('planner_notify_channels', 'telegram,bale,rubika');
    $list = array_values(array_intersect(array_filter(array_map('trim', explode(',', strtolower($raw)))), ['telegram','bale','rubika','sms']));
    return $list ?: ['telegram','bale','rubika'];
}

function fr_planner_remind_channels(): array
{
    return fr_planner_notify_channels();
}

/**
 * ارسال یادآورهای موعددار تسک‌های پلنر سر وقت مشخص (remind_at)
 * پشتیبانی کامل از تلگرام، بله، روبیکا و پیامک
 */
function fr_planner_remind_due(mixed $limit = 80): int
{
    $limit = is_numeric($limit) ? max(1, (int)$limit) : 80;
    date_default_timezone_set('Asia/Tehran');
    try {
        require_once __DIR__ . '/snotify.php';
        fr_snotify_ensure_schema();

        $rows = DB::all("SELECT t.*, u.mobile, u.first_name, u.telegram_chat_id, u.bale_chat_id, u.rubika_chat_id, u.id AS uid
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
            $shamsiDate = fa_num(jdate('l j F ۱۴۰۵', strtotime((string)$r['day'])));
            $remindClock = !empty($r['remind_at']) ? fa_num(substr((string)$r['remind_at'], 11, 5)) : '';

            $txt = "⏰ یادآوری برنامهٔ روزانه — «{$site}»\n"
                 . "🗓 " . $shamsiDate
                 . ($remindClock !== '' ? " — ساعت $remindClock" : '') . "\n"
                 . "▫️ «" . $r['title'] . "»"
                 . (trim((string)$r['note']) !== '' ? "\n📝 " . mb_substr((string)$r['note'], 0, 120) : '') . "\n";

            $anyOk = false;
            foreach ($channels as $ch) {
                if (in_array($ch, ['telegram', 'bale', 'rubika'], true)) {
                    $col = match($ch) {
                        'telegram' => 'telegram_chat_id',
                        'bale'     => 'bale_chat_id',
                        'rubika'   => 'rubika_chat_id',
                    };
                    $cid = trim((string)($r[$col] ?? ''));
                    if ($cid === '') continue;
                    try {
                        [$enabled, $send] = fr_snotify_driver($ch);
                        if (!$enabled) continue;
                        [$ok, $resp] = $send($cid, $txt);
                        if ($ok) $anyOk = true;
                        if (function_exists('fr_snotify_log')) {
                            fr_snotify_log((int)$r['uid'], $ch, 'planner', mb_substr($txt, 0, 900), (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : ''));
                        }
                    } catch (Throwable $e) {}
                } elseif ($ch === 'sms') {
                    $mob = trim((string)($r['mobile'] ?? ''));
                    if ($mob === '') continue;
                    try {
                        require_once __DIR__ . '/../core/Kavenegar.php';
                        if ((new Kavenegar())->send($mob, $txt, 'planner_remind')) $anyOk = true;
                    } catch (Throwable $e) {}
                }
            }

            DB::run("UPDATE " . DB::t('planner_tasks') . " SET reminded_at=NOW() WHERE id=?", [(int)$r['id']]);
            if ($anyOk) $sent++;
        }
        return $sent;
    } catch (Throwable $e) {
        error_log('feria: planner remind due error: ' . $e->getMessage());
        return 0;
    }
}

/** نقشهٔ دسته‌ها: کلید → [برچسب, رنگ] */
function fr_planner_cat_map(int $userId): array
{
    $map = [
        'personal' => ['شخصی و ذهن‌آگاهی 🧘', '#1e3a6e'],
        'growth'   => ['رشد فردی و روانشناسی 🧠', '#4338ca'],
        'work'     => ['کار و کسب‌وکار 💼', '#b45309'],
        'study'    => ['مطالعه و مهارت‌آموزی 📚', '#0f766e'],
        'health'   => ['سلامت و تندرستی 🏃', '#b91c1c'],
        'family'   => ['خانواده و روابط 🤝', '#6d28d9'],
        'fun'      => ['تفریح و سرگرمی 🎈', '#d97706'],
    ];
    try {
        foreach (DB::all("SELECT name, color FROM " . DB::t('planner_cats') . " WHERE user_id=? ORDER BY sort, id", [$userId]) as $row) {
            $map[(string)$row['name']] = [(string)$row['name'], (string)$row['color']];
        }
    } catch (Throwable $e) {}
    return $map;
}

/** دسته‌های سفارشی کاربر */
function fr_planner_cats(int $userId): array
{
    try {
        return DB::all("SELECT * FROM " . DB::t('planner_cats') . " WHERE user_id=? ORDER BY sort, id", [$userId]);
    } catch (Throwable $e) { return []; }
}

/** افزودن دسته سفارشی */
function fr_planner_cat_add(int $userId, string $name, string $color): int
{
    $name = mb_substr(trim($name), 0, 60);
    if ($name === '') return 0;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#1e3a6e';
    try {
        return DB::insert('planner_cats', ['user_id' => $userId, 'name' => $name, 'color' => $color]);
    } catch (Throwable $e) { return 0; }
}

/** حذف دسته سفارشی */
function fr_planner_cat_delete(int $userId, int $cid): bool
{
    try {
        DB::run("DELETE FROM " . DB::t('planner_cats') . " WHERE id=? AND user_id=?", [$cid, $userId]);
        return true;
    } catch (Throwable $e) { return false; }
}

/** جستجو و فیلتر کارهای کاربر با صفحه‌بندی پایدار */
function fr_planner_search(int $userId, array $filters, int $page = 1, int $per = 20): array
{
    $w = "user_id=?"; $p = [$userId];
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $w .= " AND (title LIKE ? OR note LIKE ?)";
        $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%';
    }
    $cat = trim((string)($filters['cat'] ?? ''));
    if ($cat !== '') { $w .= " AND category=?"; $p[] = $cat; }
    $pri = (string)($filters['pri'] ?? '');
    if (in_array($pri, ['low','normal','high'], true)) { $w .= " AND priority=?"; $p[] = $pri; }
    $done = (string)($filters['done'] ?? '');
    if ($done === '1' || $done === '0') { $w .= " AND done=?"; $p[] = (int)$done; }

    $from = fr_planner_normalize_day((string)($filters['from'] ?? ''));
    if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $w .= " AND day>=?"; $p[] = $from;
    }
    $to = fr_planner_normalize_day((string)($filters['to'] ?? ''));
    if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $w .= " AND day<=?"; $p[] = $to;
    }

    $total = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE $w", $p);
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($page, $pages));
    $rows = DB::all("SELECT * FROM " . DB::t('planner_tasks') . " WHERE $w ORDER BY day DESC, FIELD(priority,'high','normal','low'), id DESC
                     LIMIT " . (($page - 1) * $per) . ", " . (int)$per, $p);

    return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** عملیات گروهی روی کارها */
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

/** آمار و شاخص‌های پیشرفت روان‌شناختی کاربر */
function fr_planner_progress_stats(int $userId): array
{
    $today  = fr_planner_today();
    $total  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=?", [$userId]);
    $done   = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=1", [$userId]);
    $days   = (int)DB::val("SELECT COUNT(DISTINCT day) FROM " . DB::t('planner_tasks') . " WHERE user_id=?", [$userId]);

    $completeDays = 0;
    foreach (DB::all("SELECT day, COUNT(*) AS total, SUM(done) AS dn FROM " . DB::t('planner_tasks') . " WHERE user_id=? GROUP BY day", [$userId]) as $r) {
        if ((int)$r['total'] > 0 && (int)$r['dn'] === (int)$r['total']) $completeDays++;
    }

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

    $rtot  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day >= DATE_SUB(?, INTERVAL 29 DAY)", [$userId, $today]);
    $rdone = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=1 AND day >= DATE_SUB(?, INTERVAL 29 DAY)", [$userId, $today]);

    return [
        'total'         => $total,
        'done'          => $done,
        'days'          => $days,
        'complete_days' => $completeDays,
        'best_streak'   => $best,
        'rate30'        => $rtot ? (int)round($rdone / $rtot * 100) : 0,
    ];
}

/** داده‌های نمودار ۱۴ روز اخیر با برچسب‌های شمسی */
function fr_planner_chart(int $userId, int $days = 14): array
{
    $today = fr_planner_today();
    $rows = DB::all("SELECT day, COUNT(*) AS total, SUM(CASE WHEN done=1 THEN 1 ELSE 0 END) AS done
                     FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day >= DATE_SUB(?, INTERVAL " . ((int)$days - 1) . " DAY) GROUP BY day",
                    [$userId, $today]);
    $map = [];
    foreach ($rows as $r) $map[$r['day']] = $r;
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $r = $map[$d] ?? null;
        $tot = $r ? (int)$r['total'] : 0;
        $dn  = $r ? (int)$r['done'] : 0;
        $pct = $tot ? (int)round($dn / $tot * 100) : 0;
        $out[] = [
            'day'    => $d,
            'shamsi' => (string)jdate('j F', strtotime($d)),
            'total'  => $tot,
            'done'   => $dn,
            'pct'    => $pct,
        ];
    }
    return $out;
}

/** نشان‌ها و دستاوردهای پلنر */
function fr_planner_badges(int $userId): array
{
    $st  = fr_planner_progress_stats($userId);
    $stk = fr_planner_current_streak($userId);
    $early = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=1 AND HOUR(done_at) < 9", [$userId]);
    $wkd   = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND done=1 AND WEEKDAY(day) IN (3,4)", [$userId]);

    return [
        ['first',     '🌱', 'گام نخست ۱۴۰۵',    'ثبت و انجام اولین کار در پلنر',                 $st['done'] >= 1],
        ['streak3',   '🔥', 'پیوستگی ۳ روزه',    '۳ روز متوالی انجام تمام برنامه‌ها',            $stk >= 3 || $st['best_streak'] >= 3],
        ['streak7',   '💎', 'هفتهٔ طلایی',      '۷ روز استمرار بدون وقفه',                     $stk >= 7 || $st['best_streak'] >= 7],
        ['streak14',  '👑', 'قهرمان ثبات',       '۱۴ روز پیوستگی و تمرکز مستمر',                $stk >= 14 || $st['best_streak'] >= 14],
        ['tasks25',   '🎯', 'دستاورد ۲۵',       'تکمیل موفق حداقل ۲۵ کار و تمرین',            $st['done'] >= 25],
        ['tasks50',   '⚡', 'دستاورد ۵۰',       'تکمیل موفق ۵۰ برنامهٔ هدف‌گذاری‌شده',          $st['done'] >= 50],
        ['early',     '🌅', 'سحرخیز فعال',       'انجام کارها پیش از ساعت ۹ صبح',              $early >= 3],
        ['weekend',   '🧘', 'تعادل آخر هفته',    'تکمیل برنامه‌ها در پنجشنبه و جمعه',           $wkd >= 4],
        ['planner30', '🗓', 'ماهنامهٔ موفق',      'ثبت و انجام برنامه در ۳۰ روز متمایز',         $st['days'] >= 30],
    ];
}

/** ثبت حس و حال روانی روزانه (Daily Mood & Energy Check-in) */
function fr_planner_mood_save(int $userId, string $day, mixed $mood, int $energy = 3, mixed $reflection = '', ...$rest): bool
{
    $day = fr_planner_normalize_day($day);
    if (is_numeric($mood)) {
        $mMap = [1 => 'stressed', 2 => 'tired', 3 => 'neutral', 4 => 'good', 5 => 'great'];
        $mood = $mMap[(int)$mood] ?? 'good';
    }
    $allowed = ['great', 'good', 'neutral', 'tired', 'stressed'];
    if (!in_array((string)$mood, $allowed, true)) $mood = 'good';
    $energy = max(1, min(5, (int)$energy));

    $note = is_string($reflection) ? $reflection : '';
    foreach ($rest as $arg) {
        if (is_string($arg) && $note === '') {
            $note = $arg;
        } elseif (is_array($arg)) {
            $note .= ($note !== '' ? ' ' : '') . implode(' ', array_filter($arg, 'is_string'));
        }
    }
    $note = mb_substr(trim($note), 0, 500);

    try {
        require_once __DIR__ . '/snotify.php';
        fr_snotify_ensure_schema();

        DB::run("INSERT INTO " . DB::t('planner_moods') . " (user_id, day, mood, energy, reflection, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE mood=VALUES(mood), energy=VALUES(energy), reflection=VALUES(reflection), updated_at=NOW()",
                [$userId, $day, (string)$mood, $energy, $note]);
        return true;
    } catch (Throwable $e) {
        error_log('feria: mood save error: ' . $e->getMessage());
        return false;
    }
}

/** دریافت حس و حال روانی کاربر در یک روز مشخص */
function fr_planner_mood_get(int $userId, string $day): ?array
{
    $day = fr_planner_normalize_day($day);
    try {
        $row = DB::one("SELECT * FROM " . DB::t('planner_moods') . " WHERE user_id=? AND day=?", [$userId, $day]);
        if (!$row) return null;
        $map = ['stressed' => 1, 'tired' => 2, 'neutral' => 3, 'good' => 4, 'great' => 5];
        $row['mood_score'] = $map[$row['mood']] ?? 3;
        $row['energy_level'] = (int)$row['energy'];
        $row['sleep_hours'] = 8.0;
        $row['notes'] = (string)($row['reflection'] ?? '');
        $row['tags'] = (string)($row['reflection'] ?? '');
        return $row;
    } catch (Throwable $e) { return null; }
}

/** سابقهٔ حس و حال روانی روزهای اخیر */
function fr_planner_mood_history(int $userId, int $days = 14): array
{
    $today = fr_planner_today();
    try {
        $rows = DB::all("SELECT * FROM " . DB::t('planner_moods') . "
                        WHERE user_id=? AND day >= DATE_SUB(?, INTERVAL " . ((int)$days - 1) . " DAY)
                        ORDER BY day DESC", [$userId, $today]);
        $map = ['stressed' => 1, 'tired' => 2, 'neutral' => 3, 'good' => 4, 'great' => 5];
        $out = [];
        foreach ($rows as $r) {
            $r['mood_score'] = $map[$r['mood']] ?? 3;
            $r['energy_level'] = (int)$r['energy'];
            $r['notes'] = (string)($r['reflection'] ?? '');
            $r['tags'] = (string)($r['reflection'] ?? '');
            $out[$r['day']] = $r;
        }
        return $out;
    } catch (Throwable $e) { return []; }
}
