<?php
/**
 * فریا روان — اطلاع‌رسانی شبکه‌های اجتماعی (تلگرام / بله / روبیکا) — نسخهٔ ۴٫۹٫۰ (۱۴۰۵)
 * ─ اتصال با «نام کاربری»: کاربر @username خود را در پروفایل ثبت می‌کند؛ با ارسال استارت
 *   یا کد اتصال به ربات، شناسهٔ عددی چت متصل می‌شود.
 * ─ پشتیبانی کامل از ۳ پیام‌رسان محبوب: تلگرام (با پروکسی/آدرس مستقیم)، بله و روبیکا.
 * ─ ارسال هوشمند و خودکار سر وقت مشخص (Scheduled Dispatch) با پنجرهٔ زمانی مطمئن
 * ─ سیستم Auto-Dispatch خودکار و ایمن بدون وابستگی ۱۰۰٪ به کرون سرور
 * ─ تست اتصال، ارسال گروهی، لاگ یکپارچه در fr_notify_log
 */
declare(strict_types=1);

/** کانال‌های پشتیبانی‌شده */
function fr_snotify_channels(): array
{
    return [
        'telegram' => '🟦 تلگرام',
        'bale'     => '🟩 بله',
        'rubika'   => '🟪 روبیکا',
    ];
}

/** کلاینت کانال را لود می‌کند و [enabled, sendClosure, botUsername, driverName] برمی‌گرداند */
function fr_snotify_driver(string $channel): array
{
    if ($channel === 'telegram') {
        require_once __DIR__ . '/../core/Telegram.php';
        return [Telegram::enabled(), fn(string $cid, string $text) => Telegram::sendMessage($cid, $text), Telegram::botUsername(), 'Telegram'];
    }
    if ($channel === 'bale') {
        require_once __DIR__ . '/../core/Bale.php';
        return [Bale::enabled(), fn(string $cid, string $text) => Bale::sendMessage($cid, $text), Bale::botUsername(), 'Bale'];
    }
    if ($channel === 'rubika') {
        require_once __DIR__ . '/../core/Rubika.php';
        return [Rubika::enabled(), fn(string $cid, string $text) => Rubika::sendMessage($cid, $text), Rubika::botUsername(), 'Rubika'];
    }
    return [false, fn() => [false, 'unknown_channel'], '', 'Unknown'];
}

/** اعتبارسنجی نام کاربری پیام‌رسان‌ها */
function fr_snotify_valid_username(string $un): bool
{
    return (bool)preg_match('/^[A-Za-z][A-Za-z0-9_.]{2,31}$/', $un);
}

/** ستون‌های کاربری هر کانال در جدول users */
function fr_snotify_cols(string $channel): array
{
    return match($channel) {
        'telegram' => ['telegram_chat_id', 'tg_username'],
        'bale'     => ['bale_chat_id', 'bale_username'],
        'rubika'   => ['rubika_chat_id', 'rubika_username'],
        default    => ['telegram_chat_id', 'tg_username'],
    };
}

/** ستون‌های مخاطبین هر کانال در جدول contacts */
function fr_snotify_contact_cols(string $channel): array
{
    return match($channel) {
        'telegram' => ['tg_chat_id', 'tg_username', 'tg_ok'],
        'bale'     => ['bale_chat_id', 'bale_username', 'bale_ok'],
        'rubika'   => ['rubika_chat_id', 'rubika_username', 'rubika_ok'],
        default    => ['tg_chat_id', 'tg_username', 'tg_ok'],
    };
}

/** آیا کاربر روی کانال متصل است؟ */
function fr_snotify_connected(int $userId, string $channel): bool
{
    [$cidCol] = fr_snotify_cols($channel);
    try {
        return trim((string)DB::val("SELECT COALESCE($cidCol,'') FROM " . DB::t('users') . " WHERE id=?", [$userId])) !== '';
    } catch (Throwable $e) {
        return false;
    }
}

/** متن پیام تست اتصال — از تنظیمات (قابل ویرایش در پنل ادمین) */
function fr_snotify_test_message(string $channel, string $userName = ''): string
{
    $def = "✅ اتصال شما به ربات {channel} با موفقیت انجام شد!\nاز این لحظه به بعد، یادآورها و برنامه‌های روزانهٔ «{site}» به‌صورت کامل، خودکار و سر وقت برای شما ارسال می‌شود. 🦢";
    $tpl = (string)DB::setting('notif_test_msg', $def);
    $site = (string)DB::setting('site_title', 'فریا روان');
    $bot = match($channel) {
        'telegram' => (string)DB::setting('telegram_bot_username', ''),
        'bale'     => (string)DB::setting('bale_bot_username', ''),
        'rubika'   => (string)DB::setting('rubika_bot_username', ''),
        default    => '',
    };
    return strtr($tpl, ['{site}' => $site, '{name}' => $userName, '{bot}' => $bot, '{channel}' => fr_snotify_channels()[$channel] ?? $channel]);
}

/**
 * شروع/تجدید اتصال: نام کاربری ذخیره و کد اتصال یکتا ساخته می‌شود.
 * خروجی: ['ok'=>bool,'code'=>..., 'deeplink'=>..., 'message'=>...]
 */
function fr_snotify_link_start(int $userId, string $channel, string $username): array
{
    if (!isset(fr_snotify_channels()[$channel])) return ['ok' => false, 'message' => 'کانال نامعتبر است.'];
    $username = ltrim(trim($username), '@');
    if (!fr_snotify_valid_username($username)) {
        return ['ok' => false, 'message' => 'نام کاربری معتبر نیست؛ حروف انگلیسی و اعداد مجاز است (مثل ali_mohammadi).'];
    }
    [$cidCol, $unCol] = fr_snotify_cols($channel);

    /* بررسی عدم اتصال به حساب دیگر */
    try {
        $other = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE $unCol=? AND id<>? LIMIT 1", [$username, $userId]);
        if ($other > 0) return ['ok' => false, 'message' => 'این نام کاربری قبلاً به حساب کاربری دیگری متصل شده است.'];
        DB::update('users', [$unCol => $username], 'id=?', [$userId]);
    } catch (Throwable $e) {
        // ممکن است ستون جدول با مایگریشن اضافه شود
    }

    $code = strtoupper(bin2hex(random_bytes(4)));   // ۸ نویسه یکتا
    try {
        $row = DB::one("SELECT id FROM " . DB::t('social_links') . " WHERE user_id=? AND channel=?", [$userId, $channel]);
        if ($row) {
            DB::run("UPDATE " . DB::t('social_links') . " SET username=?, connect_code=?, status='pending', created_at=NOW() WHERE id=?",
                    [$username, $code, (int)$row['id']]);
        } else {
            DB::insert('social_links', ['user_id' => $userId, 'channel' => $channel, 'username' => $username,
                                        'connect_code' => $code, 'status' => 'pending']);
        }
    } catch (Throwable $e) {}

    [, , $botUser] = fr_snotify_driver($channel);
    $botUser = ltrim($botUser, '@');

    $deep = match($channel) {
        'telegram' => $botUser !== '' ? 'https://t.me/' . $botUser . '?start=' . $code : '',
        'bale'     => $botUser !== '' ? 'https://ble.ir/' . $botUser . '?start=' . $code : '',
        'rubika'   => $botUser !== '' ? 'https://rubika.ir/' . $botUser . '?start=' . $code : '',
        default    => '',
    };

    return ['ok' => true, 'code' => $code, 'deeplink' => $deep, 'bot' => $botUser];
}

/** قطع اتصال کانال */
function fr_snotify_disconnect(int $userId, string $channel): void
{
    [$cidCol, $unCol] = fr_snotify_cols($channel);
    try {
        DB::update('users', [$cidCol => null, $unCol => null], 'id=?', [$userId]);
        DB::run("DELETE FROM " . DB::t('social_links') . " WHERE user_id=? AND channel=?", [$userId, $channel]);
    } catch (Throwable $e) {}
}

/** ثبت لاگ ارسال */
function fr_snotify_log(int $userId, string $channel, string $kind, string $body, bool $ok, string $err = '', ?string $slotDate = null, ?int $slotIdx = null): void
{
    try {
        DB::insert('notify_log', [
            'user_id'   => $userId,
            'channel'   => $channel,
            'msg_kind'  => $kind,
            'body'      => mb_substr($body, 0, 900),
            'status'    => $ok ? 'sent' : 'failed',
            'error'     => mb_substr($err, 0, 300),
            'slot_date' => $slotDate,
            'slot_idx'  => $slotIdx,
        ]);
    } catch (Throwable $e) { /* بی‌صدا */ }
}

/** ارسال پیام تست به کاربر روی کانال مشخص */
function fr_snotify_send_test(int $userId, string $channel): array
{
    [$cidCol] = fr_snotify_cols($channel);
    $u = DB::one("SELECT id, first_name, $cidCol AS cid FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    $cid = trim((string)($u['cid'] ?? ''));
    if (!$u || $cid === '') {
        return ['ok' => false, 'message' => 'کاربر شناسهٔ چت فعال روی این پیام‌رسان ندارد؛ ابتدا اتصال را برقرار کنید.'];
    }
    $text = fr_snotify_test_message($channel, (string)($u['first_name'] ?? ''));
    [$enabled, $send] = fr_snotify_driver($channel);
    if (!$enabled) {
        fr_snotify_log($userId, $channel, 'test', $text, false, 'bot_not_configured');
        return ['ok' => false, 'message' => 'ربات ' . (fr_snotify_channels()[$channel] ?? $channel) . ' هنوز در بخش تنظیمات پیکربندی نشده است.'];
    }
    [$ok, $resp] = $send($cid, $text);
    fr_snotify_log($userId, $channel, 'test', $text, (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : json_encode($resp, JSON_UNESCAPED_UNICODE)));
    return ['ok' => (bool)$ok, 'message' => $ok ? 'پیام تست با موفقیت ارسال شد ✅' : ('خطا در ارسال: ' . (is_string($resp) ? $resp : 'عدم دریافت پاسخ معتبر'))];
}

/** ساعت‌های اطلاع‌رسانی روزانه (۲۴ ساعته HH:MM) */
function fr_snotify_slots(): array
{
    $count = max(1, min(6, (int)DB::setting('notif_daily_count', '3')));
    $raw = trim((string)DB::setting('notif_times', ''));
    $times = [];
    if ($raw !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) $times = $dec;
    }
    if (!$times) $times = ['08:30', '13:00', '20:30', '17:00', '10:30', '22:00'];
    $times = array_values(array_filter(array_map(function ($t) {
        $t = trim((string)$t);
        return fr_valid_time($t, '');
    }, $times)));
    if (!$times) $times = ['08:30'];
    return array_slice($times, 0, $count);
}

/** ساخت پیام هر نوبت برای یک کاربر بر مبنای تقویم ۱۴۰۵ و وضعیت پلنر */
function fr_snotify_compose(array $u, int $slotIdx, string $slotTime): string
{
    $site = (string)DB::setting('site_title', 'فریا روان');
    $name = trim((string)($u['first_name'] ?? '')) ?: 'همراه گرامی';
    $today = date('Y-m-d');
    $shamsiDate = fa_num(jdate('l j F Y'));

    $pending = 0; $topTasks = [];
    if (function_exists('fr_planner_enabled') && fr_planner_enabled()) {
        try {
            $rows = DB::all("SELECT title, priority FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=? AND done=0 ORDER BY FIELD(priority,'high','normal','low'), id LIMIT 3", [(int)$u['id'], $today]);
            $pending = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=? AND done=0", [(int)$u['id'], $today]);
            $topTasks = array_map(fn($r) => '▫️ ' . $r['title'], $rows);
        } catch (Throwable $e) {}
    }

    $tips = [
        'یک قدم کوچک و متوالی امروز، از یک تصمیم بزرگ فردا اثربخش‌تر است. 🦢',
        'آرامش ذهن یعنی مدیریت آگاهانهٔ اولویت‌ها و تمرکز عمیق. ⏱',
        'پیشرفت واقعی یعنی استمرار در تمرین‌های روزانه، نه کمال‌گرایی. 💎',
        'شناخت الگوهای رفتاری، اولین گام در شکوفایی فردی است. 🧠',
        'عادت‌های کوچک و پایدار، تحولات بزرگ زندگی را می‌سازند. 🌱',
    ];

    $greet = ['صبح بخیر 🌅', 'وقت بخیر ☀️', 'عصر بخیر 🌆', 'شب بخیر 🌙'];
    $h = (int)substr($slotTime, 0, 2);
    $g = $greet[$h < 11 ? 0 : ($h < 16 ? 1 : ($h < 20 ? 2 : 3))];

    $msg = "$g $name!\n🗓 امروز: $shamsiDate\n\n";
    if ($slotIdx === 0 && $pending > 0) {
        $msg .= "🎯 امروز " . fa_num($pending) . " برنامه و تمرین در انتظار شماست:\n" . implode("\n", $topTasks) . "\n\n";
    } elseif ($slotIdx >= 1 && $pending > 0) {
        $msg .= "⏳ یادآوری: هنوز " . fa_num($pending) . " کار از برنامهٔ امروز باقی مانده است. با یک قدم کوتاه شروع کنید! ✅\n\n";
    } else {
        $msg .= $tips[array_rand($tips)] . "\n\n";
    }

    if ($slotIdx >= 1) {
        try {
            $new = DB::one("SELECT title, slug FROM " . DB::t('events') . " WHERE status='active' ORDER BY id DESC LIMIT 1");
            if ($new) $msg .= "🎪 تازه‌ترین رویداد فریا: «" . $new['title'] . "»\n";
        } catch (Throwable $e) {}
    }

    $msg .= "— " . $site;
    return $msg;
}

/**
 * اجرای سر نوبت‌های اطلاع‌رسانی زمان‌بندی‌شده (در ساعت‌های مشخص‌شده)
 * با رفع ریشه‌ای باگ رد شدن از پنجره زمانی (اگر ساعت گذشته باشد و امروز هنوز ارسال نشده، فرستاده می‌شود)
 */
function fr_snotify_run_due(bool $force = false): array
{
    date_default_timezone_set('Asia/Tehran');
    $today = date('Y-m-d');
    $nowMin = (int)date('H') * 60 + (int)date('i');
    $result = [];

    // بررسی و اضافه کردن ستون rubika در صورت لزوم
    fr_snotify_ensure_schema();

    foreach (fr_snotify_slots() as $idx => $hhmm) {
        [$sh, $sm] = array_map('intval', explode(':', $hhmm));
        $slotMin = $sh * 60 + $sm;
        $lock = 'notif_slot_' . date('Ymd') . '_' . $idx;

        if (!$force && DB::setting($lock, '0') === '1') {
            $result[] = "$hhmm:already";
            continue;
        }

        // ارسال دقیقاً در زمان یا پس از رسیدن به زمان مشخص‌شده در همان روز
        if (!$force && $nowMin < $slotMin) {
            $result[] = "$hhmm:wait";
            continue;
        }

        $users = DB::all("SELECT id, first_name, telegram_chat_id, bale_chat_id, rubika_chat_id FROM " . DB::t('users') .
                         " WHERE status='active' AND (COALESCE(telegram_chat_id,'')<>'' OR COALESCE(bale_chat_id,'')<>'' OR COALESCE(rubika_chat_id,'')<>'')");
        $sent = 0; $fail = 0;

        foreach ($users as $u) {
            $msg = fr_snotify_compose($u, (int)$idx, $hhmm);
            foreach (['telegram' => 'telegram_chat_id', 'bale' => 'bale_chat_id', 'rubika' => 'rubika_chat_id'] as $ch => $col) {
                $cid = trim((string)($u[$col] ?? ''));
                if ($cid === '') continue;
                [$enabled, $send] = fr_snotify_driver($ch);
                if (!$enabled) continue;
                [$ok, $resp] = $send($cid, $msg);
                $ok ? $sent++ : $fail++;
                fr_snotify_log((int)$u['id'], $ch, 'slot', $msg, (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : ''), $today, (int)$idx);
                usleep(50000);
            }
        }

        DB::setSetting($lock, '1');
        $result[] = "$hhmm:sent=$sent,failed=$fail";
    }
    return $result;
}

/** ارسال گروهی از پنل ادمین (تلگرام، بله، روبیکا یا همه) */
function fr_snotify_bulk(string $channel, string $text, array $userIds = []): array
{
    $sent = 0; $fail = 0;
    fr_snotify_ensure_schema();

    $targets = [];
    if ($userIds) {
        $in = implode(',', array_map('intval', $userIds));
        $targets = DB::all("SELECT id, telegram_chat_id, bale_chat_id, rubika_chat_id FROM " . DB::t('users') . " WHERE id IN ($in)");
    } else {
        $w = match($channel) {
            'telegram' => "COALESCE(telegram_chat_id,'')<>''",
            'bale'     => "COALESCE(bale_chat_id,'')<>''",
            'rubika'   => "COALESCE(rubika_chat_id,'')<>''",
            default    => "(COALESCE(telegram_chat_id,'')<>'' OR COALESCE(bale_chat_id,'')<>'' OR COALESCE(rubika_chat_id,'')<>'')",
        };
        $targets = DB::all("SELECT id, telegram_chat_id, bale_chat_id, rubika_chat_id FROM " . DB::t('users') . " WHERE status='active' AND $w");
    }

    $channelsToSend = ($channel === 'all' || $channel === 'both') ? ['telegram', 'bale', 'rubika'] : [$channel];

    foreach ($targets as $u) {
        foreach ($channelsToSend as $ch) {
            [$cidCol] = fr_snotify_cols($ch);
            $cid = trim((string)($u[$cidCol] ?? ''));
            if ($cid === '') continue;
            [$enabled, $send] = fr_snotify_driver($ch);
            if (!$enabled) { $fail++; continue; }
            [$ok, $resp] = $send($cid, $text);
            $ok ? $sent++ : $fail++;
            fr_snotify_log((int)$u['id'], $ch, 'bulk', $text, (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : ''));
            usleep(60000);
        }
    }
    return [$sent, $fail];
}

/** وضعیت اتصال پیام‌رسان‌های یک کاربر برای نمایش در پنل کاربری */
function fr_snotify_status(int $userId): array
{
    fr_snotify_ensure_schema();
    $u = DB::one("SELECT telegram_chat_id, tg_username, bale_chat_id, bale_username, rubika_chat_id, rubika_username FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    $links = DB::all("SELECT channel, username, connect_code, status FROM " . DB::t('social_links') . " WHERE user_id=? AND status='pending'", [$userId]);
    $pending = [];
    foreach ($links as $l) $pending[$l['channel']] = $l;

    $out = [];
    foreach (fr_snotify_channels() as $ch => $label) {
        [$cidCol, $unCol] = fr_snotify_cols($ch);
        $out[$ch] = [
            'label'     => $label,
            'connected' => trim((string)($u[$cidCol] ?? '')) !== '',
            'chat_id'   => (string)($u[$cidCol] ?? ''),
            'username'  => (string)($u[$unCol] ?? ''),
            'pending'   => $pending[$ch] ?? null,
        ];
    }
    return $out;
}

/** خواندن getUpdates تلگرام و بله جهت اتصال خودکار نام کاربری به chat_id */
function fr_snotify_poll_updates(): array
{
    $stats = [
        'telegram' => ['updates' => 0, 'bound' => 0, 'err' => ''],
        'bale'     => ['updates' => 0, 'bound' => 0, 'err' => ''],
        'rubika'   => ['updates' => 0, 'bound' => 0, 'err' => ''],
    ];

    foreach (['telegram' => 'tg', 'bale' => 'bale'] as $channel => $short) {
        [$enabled] = fr_snotify_driver($channel);
        if (!$enabled) continue;
        [$cidCol, $unCol] = fr_snotify_cols($channel);
        $offSetting = $short . '_update_offset';
        $offset = (int)DB::setting($offSetting, '0');
        $klass  = $channel === 'telegram' ? 'Telegram' : 'Bale';
        require_once __DIR__ . '/../core/' . $klass . '.php';

        [$ok, $res] = $klass::call('getUpdates', ['offset' => $offset, 'limit' => 50, 'timeout' => 0, 'allowed_updates' => ['message']]);
        if (!$ok || !is_array($res)) { $stats[$channel]['err'] = 'poll_failed'; continue; }
        $list = $res['result'] ?? [];
        $max = $offset;

        foreach ($list as $up) {
            $uid = (int)($up['update_id'] ?? 0);
            $max = max($max, $uid + 1);
            $stats[$channel]['updates']++;
            $msg = $up['message'] ?? null;
            if (!$msg) continue;
            $chatId = (string)($msg['chat']['id'] ?? '');
            $from   = $msg['from'] ?? [];
            $uname  = (string)($from['username'] ?? '');
            $text   = trim((string)($msg['text'] ?? ''));
            if ($chatId === '') continue;

            // اتصال با کد یا نام کاربری
            $code = '';
            if (preg_match('#^/start(?:@\w+)?\s+([A-Fa-f0-9]{8})\b#u', $text, $m)) $code = strtoupper($m[1]);
            elseif (preg_match('/^([A-Fa-f0-9]{8})$/', $text, $m2)) $code = strtoupper($m2[1]);

            $link = null;
            if ($code !== '') {
                $link = DB::one("SELECT * FROM " . DB::t('social_links') . " WHERE channel=? AND connect_code=? AND status='pending' LIMIT 1", [$channel, $code]);
            }
            if (!$link && $uname !== '') {
                $link = DB::one("SELECT * FROM " . DB::t('social_links') . " WHERE channel=? AND username=? AND status='pending' LIMIT 1", [$channel, $uname]);
                if (!$link) {
                    $uidByUn = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE $unCol=? AND COALESCE($cidCol,'')='' LIMIT 1", [$uname]);
                    if ($uidByUn > 0) $link = ['user_id' => $uidByUn, 'username' => $uname];
                }
            }

            if ($link) {
                $targetUid = (int)$link['user_id'];
                DB::update('users', [$cidCol => $chatId, $unCol => $uname !== '' ? $uname : ($link['username'] ?? null)], 'id=?', [$targetUid]);
                DB::run("UPDATE " . DB::t('social_links') . " SET status='connected', chat_id=?, connected_at=NOW() WHERE user_id=? AND channel=?",
                        [$chatId, $targetUid, $channel]);
                $stats[$channel]['bound']++;

                $first = (string)DB::val("SELECT first_name FROM " . DB::t('users') . " WHERE id=?", [$targetUid]);
                $hello = fr_snotify_test_message($channel, $first);
                [, $send2] = fr_snotify_driver($channel);
                [$okS, $resp] = $send2($chatId, $hello);
                fr_snotify_log($targetUid, $channel, 'connected', $hello, (bool)$okS, $okS ? '' : (is_string($resp) ? $resp : ''));
            }
        }
        if ($max !== $offset) DB::setSetting($offSetting, (string)$max);
    }
    return $stats;
}

/**
 * ════════════════════════════════════════════════════════════════════════════
 *  موتور ارکستراتور ارسال خودکار سر وقت مشخص (Auto-Dispatch Engine)
 *  این تابع تمام پیام‌های دارای موعد را بررسی و سر وقت مقرر ارسال می‌کند:
 *  ۱) نوبت‌های روزانه اطلاع‌رسانی پیام‌رسان‌ها (تلگرام / بله / روبیکا)
 *  ۲) یادآورهای موعددار تسک‌های پلنر روزانه (remind_at)
 *  ۳) کمپین‌های تبلیغاتی زمان‌بندی‌شده (scheduled_at)
 *  ۴) یادآوری‌های رویدادها (مشاوره، کارگاه، تست ناتمام)
 * ════════════════════════════════════════════════════════════════════════════
 */
function fr_scheduled_dispatch_all(bool $force = false): array
{
    date_default_timezone_set('Asia/Tehran');
    $summary = [
        'slots'     => [],
        'planner'   => 0,
        'campaigns' => 0,
        'events'    => 0,
    ];

    // ۱) نوبت‌های روزانه تلگرام، بله، روبیکا
    try {
        $summary['slots'] = fr_snotify_run_due($force);
    } catch (Throwable $e) {
        error_log('feria: scheduled slots error: ' . $e->getMessage());
    }

    // ۲) یادآورهای موعددار تسک‌های پلنر
    try {
        require_once __DIR__ . '/planner.php';
        if (function_exists('fr_planner_remind_due')) {
            $summary['planner'] = fr_planner_remind_due();
        }
    } catch (Throwable $e) {
        error_log('feria: scheduled planner error: ' . $e->getMessage());
    }

    // ۳) ارتقا و ارسال دسته‌ای کمپین‌های زمان‌بندی‌شده
    try {
        require_once __DIR__ . '/campaigns.php';
        if (function_exists('fr_campaign_promote_scheduled')) {
            fr_campaign_promote_scheduled();
        }
        if (function_exists('fr_campaign_work')) {
            $pendingCamp = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('campaigns') . " WHERE status IN ('queued','sending')");
            if ($pendingCamp > 0) {
                $summary['campaigns'] = fr_campaign_work(25);
            }
        }
    } catch (Throwable $e) {
        error_log('feria: scheduled campaigns error: ' . $e->getMessage());
    }

    return $summary;
}

/**
 * ارسال مستقیم پیام در یک کانال مشخص به همراه لاگ
 * @return array [bool $ok, mixed $response]
 */
function fr_snotify_send(string $channel, string $recipient, string $text, string $context = 'direct'): array
{
    fr_snotify_ensure_schema();
    $channel = strtolower(trim($channel));
    $recipient = trim($recipient);
    if ($recipient === '') return [false, 'empty_recipient'];

    [$enabled, $send, $botUser, $driverName] = fr_snotify_driver($channel);
    $ok = false;
    $resp = null;
    if ($enabled && is_callable($send)) {
        [$ok, $resp] = $send($recipient, $text);
    } else {
        $resp = 'driver_disabled_or_unsupported';
    }

    try {
        $msgKind = in_array($context, ['slot','test','connected','bulk','planner','campaign','event'], true) ? $context : 'test';
        DB::insert('notify_log', [
            'user_id'   => 0,
            'channel'   => $channel,
            'payload'   => json_encode(['recipient' => $recipient, 'text' => $text, 'context' => $context], JSON_UNESCAPED_UNICODE),
            'msg_kind'  => $msgKind,
            'status'    => $ok ? 'sent' : 'failed',
            'created_at'=> date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {}

    return [$ok, $resp];
}

/**
 * رانر پس‌زمینهٔ Opportunistic برای تضمین ارسال خودکار سر وقت مشخص
 * حتی در صورت عدم تنظیم یا قطع بودن کرون سرور، با هر درخواست وب (حداکثر ۱ بار در بازه زمانی) اجرا می‌شود
 */
function fr_auto_cron_tick(int $minIntervalSec = 60): bool
{
    try {
        $now = time();
        $last = (int)DB::setting('last_auto_cron_ts', '0');
        if (($now - $last) < $minIntervalSec) return false;
        DB::setSetting('last_auto_cron_ts', (string)$now);
        fr_scheduled_dispatch_all(false);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** تضمین وجود ستون‌های روبیکا در پایگاه داده به صورت کاملاً امن */
function fr_snotify_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $p = DB_PREFIX;
        $uCols = array_column(DB::all("SHOW COLUMNS FROM `{$p}users`"), 'Field');
        if (!in_array('rubika_chat_id', $uCols, true)) {
            DB::run("ALTER TABLE `{$p}users` ADD COLUMN `rubika_chat_id` VARCHAR(100) DEFAULT NULL AFTER `bale_chat_id`");
        }
        if (!in_array('rubika_username', $uCols, true)) {
            DB::run("ALTER TABLE `{$p}users` ADD COLUMN `rubika_username` VARCHAR(60) DEFAULT NULL AFTER `bale_username`");
        }

        // جدول contacts
        $cCols = array_column(DB::all("SHOW COLUMNS FROM `{$p}contacts`"), 'Field');
        if (!in_array('rubika_chat_id', $cCols, true)) {
            DB::run("ALTER TABLE `{$p}contacts` ADD COLUMN `rubika_chat_id` VARCHAR(100) DEFAULT NULL AFTER `bale_chat_id`");
        }
        if (!in_array('rubika_username', $cCols, true)) {
            DB::run("ALTER TABLE `{$p}contacts` ADD COLUMN `rubika_username` VARCHAR(60) DEFAULT NULL AFTER `bale_username`");
        }
        if (!in_array('rubika_ok', $cCols, true)) {
            DB::run("ALTER TABLE `{$p}contacts` ADD COLUMN `rubika_ok` TINYINT(1) NOT NULL DEFAULT 0 AFTER `bale_ok`");
        }

        // جدول planner_moods برای پایش حس و حال روانشناختی سال ۱۴۰۵
        DB::run("CREATE TABLE IF NOT EXISTS `{$p}planner_moods` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `day` DATE NOT NULL,
            `mood` ENUM('great','good','neutral','tired','stressed') NOT NULL DEFAULT 'good',
            `energy` TINYINT UNSIGNED NOT NULL DEFAULT 3,
            `reflection` VARCHAR(500) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_user_day` (`user_id`, `day`),
            KEY `ix_user_day` (`user_id`, `day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    } catch (Throwable $e) {
        error_log('feria: snotify ensure schema error: ' . $e->getMessage());
    }
}
