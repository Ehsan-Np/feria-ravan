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
 * تولید یا بازیابی توکن یکتا و فعال اتصال برای کاربر
 */
function fr_bot_generate_token(int $userId, string $channel = 'all'): string
{
    fr_snotify_ensure_schema();
    try {
        $existing = DB::one("SELECT token FROM " . DB::t('bot_connect_tokens') . "
                             WHERE user_id=? AND status='pending' AND (expires_at IS NULL OR expires_at > NOW())
                             ORDER BY id DESC LIMIT 1", [$userId]);
        if ($existing && !empty($existing['token'])) {
            return (string)$existing['token'];
        }

        // توکن تمیز ۱۰ نویسه‌ای: FR + ۸ کاراکتر هگز بزرگ
        $token = 'FR' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert('bot_connect_tokens', [
            'user_id'    => $userId,
            'token'      => $token,
            'channel'    => $channel,
            'status'     => 'pending',
            'expires_at' => date('Y-m-d H:i:s', time() + 14 * 86400),
        ]);
        return $token;
    } catch (\Throwable $e) {
        error_log('fr_bot_generate_token error: ' . $e->getMessage());
        return 'FR' . strtoupper(substr(md5((string)$userId . (string)time()), 0, 8));
    }
}

/** دریافت نشانی صفحه وب اتصال هوشمند به ربات برای ارسال به کاربر */
function fr_bot_get_activation_url(int $userId, string $channel = 'all'): string
{
    $token = fr_bot_generate_token($userId, $channel);
    $url = rtrim(SITE_URL, '/') . '/bot-connect.php?token=' . urlencode($token);
    if ($channel !== 'all' && in_array($channel, ['telegram', 'bale', 'rubika'], true)) {
        $url .= '&ch=' . $channel;
    }
    return $url;
}

/** دریافت پیوند دیپ‌لینک مستقیم به اپلیکیشن ربات پیام‌رسان */
function fr_bot_get_channel_deep_link(string $channel, string $token): string
{
    $token = trim($token);
    $bot = match($channel) {
        'telegram' => ltrim((string)DB::setting('telegram_bot_username', ''), '@'),
        'bale'     => ltrim((string)DB::setting('bale_bot_username', ''), '@'),
        'rubika'   => ltrim((string)DB::setting('rubika_bot_username', ''), '@'),
        default    => '',
    };

    if ($bot === '') return '';

    return match($channel) {
        'telegram' => 'https://t.me/' . rawurlencode($bot) . ($token !== '' ? '?start=' . rawurlencode($token) : ''),
        'bale'     => 'https://ble.ir/' . rawurlencode($bot) . ($token !== '' ? '?start=' . rawurlencode($token) : ''),
        'rubika'   => 'https://rubika.ir/' . rawurlencode($bot) . ($token !== '' ? '?start=' . rawurlencode($token) : ''),
        default    => '',
    };
}

/**
 * فعال‌سازی و اتصال قطعی کاربر به ربات هنگام ارسال پیام یا زدن استارت
 * @return array ['ok' => bool, 'user_id' => int, 'message' => string]
 */
function fr_bot_activate(string $tokenOrCode, string $channel, string $chatId, ?string $username = null, ?string $firstName = null): array
{
    fr_snotify_ensure_schema();
    $chatId = trim($chatId);
    if ($chatId === '') return ['ok' => false, 'message' => 'chat_id_empty'];
    if (!isset(fr_snotify_channels()[$channel])) return ['ok' => false, 'message' => 'invalid_channel'];

    [$cidCol, $unCol] = fr_snotify_cols($channel);
    $cleanToken = strtoupper(trim(preg_replace('/^(\/start(?:@\w+)?\s+)/i', '', $tokenOrCode)));
    $username = $username ? ltrim(trim($username), '@') : null;

    $targetUserId = 0;
    $matchedToken = null;

    // ۱. بررسی بر اساس جدول توکن‌های اتصال هوشمند
    if ($cleanToken !== '') {
        $row = DB::one("SELECT * FROM " . DB::t('bot_connect_tokens') . " WHERE token=? LIMIT 1", [$cleanToken]);
        if ($row && !empty($row['user_id'])) {
            $targetUserId = (int)$row['user_id'];
            $matchedToken = $row;
        }
    }

    // ۲. بررسی پشتیبان با نام کاربری یا کد اتصال در social_links
    if (!$targetUserId && $cleanToken !== '') {
        try {
            $sl = DB::one("SELECT user_id FROM " . DB::t('social_links') . " WHERE connect_code=? LIMIT 1", [$cleanToken]);
            if ($sl && !empty($sl['user_id'])) {
                $targetUserId = (int)$sl['user_id'];
            }
        } catch (\Throwable $e) {}
    }

    // ۳. بررسی بر اساس نام کاربری در صورت ثبت قبلی
    if (!$targetUserId && $username !== null && $username !== '') {
        $uRow = DB::one("SELECT id FROM " . DB::t('users') . " WHERE $unCol=? LIMIT 1", [$username]);
        if ($uRow) {
            $targetUserId = (int)$uRow['id'];
        }
    }

    // ۴. بررسی بر اساس شماره موبایل اگر کاربر شماره خود را فرستاده باشد
    if (!$targetUserId && preg_match('/^(?:0|\+?98)?(9\d{9})$/', preg_replace('/[^\d]/', '', $tokenOrCode), $mm)) {
        $mob = '0' . $mm[1];
        $uMob = DB::one("SELECT id FROM " . DB::t('users') . " WHERE mobile=? LIMIT 1", [$mob]);
        if ($uMob) {
            $targetUserId = (int)$uMob['id'];
        }
    }

    if (!$targetUserId) {
        return ['ok' => false, 'message' => 'token_or_user_not_found'];
    }

    $user = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$targetUserId]);
    if (!$user) return ['ok' => false, 'message' => 'user_not_found'];

    // ذخیره در جدول کاربران
    $updateData = [$cidCol => $chatId];
    if ($username !== null && $username !== '') {
        $updateData[$unCol] = $username;
    }
    DB::update('users', $updateData, 'id=?', [$targetUserId]);

    // به‌روزرسانی جدول توکن‌ها
    try {
        if ($matchedToken) {
            DB::update('bot_connect_tokens', [
                'status'            => 'connected',
                'chat_id'           => $chatId,
                'username'          => $username,
                'connected_channel' => $channel,
                'connected_at'      => date('Y-m-d H:i:s'),
            ], 'id=?', [$matchedToken['id']]);
        } else {
            DB::run("UPDATE " . DB::t('bot_connect_tokens') . "
                     SET status='connected', chat_id=?, username=?, connected_channel=?, connected_at=NOW()
                     WHERE user_id=? AND status='pending'", [$chatId, $username, $channel, $targetUserId]);
        }
    } catch (\Throwable $e) {}

    // همگام‌سازی با جدول مخاطبین در صورت وجود
    if (!empty($user['mobile'])) {
        try {
            $cOkCol = match($channel) { 'telegram' => 'tg_ok', 'bale' => 'bale_ok', 'rubika' => 'rubika_ok', default => '' };
            $cIdCol = match($channel) { 'telegram' => 'tg_chat_id', 'bale' => 'bale_chat_id', 'rubika' => 'rubika_chat_id', default => '' };
            if ($cOkCol !== '' && $cIdCol !== '') {
                DB::run("UPDATE " . DB::t('contacts') . " SET $cIdCol=?, $cOkCol=1 WHERE mobile=?", [$chatId, (string)$user['mobile']]);
            }
        } catch (\Throwable $e) {}
    }

    // ارسال پیام تأیید خوش‌آمدگویی از طریق همان بات
    $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'کاربر گرامی';
    $siteTitle = (string)DB::setting('site_title', 'فریا روان');
    $channelLabel = fr_snotify_channels()[$channel] ?? $channel;
    $welcomeMsg = "✅ اتصال شما به ربات اطلاع‌رسانی {$channelLabel} فریا روان با موفقیت برقرار شد!\n\n" .
                  "سلام {$userName} عزیز 🦢\n" .
                  "از این لحظه به بعد یادآورهای روزانه، نتیجه آزمون‌ها، کارنامه‌ها و برنامه‌های شما در این پیام‌رسان به‌صورت خودکار و سر وقت تقدیم حضورتان می‌شود.";

    [, $sender] = fr_snotify_driver($channel);
    [$sentOk, $sendResp] = $sender($chatId, $welcomeMsg);
    fr_snotify_log($targetUserId, $channel, 'bot_activated', $welcomeMsg, (bool)$sentOk, $sentOk ? '' : (is_string($sendResp) ? $sendResp : ''));

    if (function_exists('log_activity')) {
        log_activity('bot_activated', 'user', $targetUserId, ['channel' => $channel, 'chat_id' => $chatId]);
    }

    return [
        'ok'      => true,
        'user_id' => $targetUserId,
        'channel' => $channel,
        'name'    => $userName,
        'message' => 'اتصال ربات با موفقیت فعال گردید.',
    ];
}

/** اتصال دستی چت‌آیدی یا نام کاربری توسط مدیر */
function fr_bot_manual_bind(int $userId, string $channel, string $chatId, ?string $username = null): bool
{
    if (!isset(fr_snotify_channels()[$channel])) return false;
    [$cidCol, $unCol] = fr_snotify_cols($channel);
    $data = [];
    if (trim($chatId) !== '') $data[$cidCol] = trim($chatId);
    if ($username !== null && trim($username) !== '') $data[$unCol] = ltrim(trim($username), '@');
    if (empty($data)) return false;
    DB::update('users', $data, 'id=?', [$userId]);
    if (function_exists('log_activity')) {
        log_activity('admin_manual_bot_bind', 'user', $userId, ['channel' => $channel, 'data' => $data]);
    }
    return true;
}

/** ارسال پیامک حاوی لینک فعال‌سازی ربات توسط مدیر برای کاربر */
function fr_bot_send_activation_sms(int $userId, string $channel = 'all'): array
{
    $u = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    if (!$u || empty($u['mobile'])) {
        return ['ok' => false, 'message' => 'شماره تلفن کاربر یافت نشد.'];
    }

    $actUrl = fr_bot_get_activation_url($userId, $channel);
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر گرامی';
    $site = (string)DB::setting('site_title', 'فریا روان');

    $msg = "سلام {$name} عزیز\n" .
           "جهت دریافت خودکار یادآورها، کارنامه‌ها و برنامه‌های روزانه در پیام‌رسان دلخواه خود، روی لینک زیر بزنید:\n" .
           "{$actUrl}\n" .
           "{$site}";

    require_once __DIR__ . '/../core/Kavenegar.php';
    $kv = new Kavenegar();
    $ok = $kv->send((string)$u['mobile'], $msg, 'bot_activation');

    if (function_exists('log_activity')) {
        log_activity('admin_sms_bot_activation', 'user', $userId, ['channel' => $channel, 'ok' => $ok]);
    }

    return [
        'ok'      => $ok,
        'url'     => $actUrl,
        'message' => $ok ? 'پیامک لینک فعال‌سازی با موفقیت ارسال شد.' : 'ارسال پیامک با خطا مواجه شد؛ تنظیمات سامانه پیامک را بررسی کنید.',
    ];
}

/**
 * شروع/تجدید اتصال: نام کاربری ذخیره و کد اتصال یکتا ساخته می‌شود.
 * خروجی: ['ok'=>bool,'code'=>..., 'deeplink'=>..., 'message'=>...]
 */
function fr_snotify_link_start(int $userId, string $channel, string $username): array
{
    if (!isset(fr_snotify_channels()[$channel])) return ['ok' => false, 'message' => 'کانال نامعتبر است.'];
    $username = ltrim(trim($username), '@');
    [$cidCol, $unCol] = fr_snotify_cols($channel);

    if ($username !== '') {
        try {
            $other = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE $unCol=? AND id<>? LIMIT 1", [$username, $userId]);
            if ($other > 0) return ['ok' => false, 'message' => 'این نام کاربری قبلاً به حساب کاربری دیگری متصل شده است.'];
            DB::update('users', [$unCol => $username], 'id=?', [$userId]);
        } catch (\Throwable $e) {}
    }

    $token = fr_bot_generate_token($userId, $channel);
    $deep = fr_bot_get_channel_deep_link($channel, $token);
    [, , $botUser] = fr_snotify_driver($channel);

    return ['ok' => true, 'code' => $token, 'deeplink' => $deep, 'bot' => ltrim($botUser, '@')];
}

/** قطع اتصال کانال */
function fr_snotify_disconnect(int $userId, string $channel): void
{
    [$cidCol, $unCol] = fr_snotify_cols($channel);
    try {
        DB::update('users', [$cidCol => null, $unCol => null], 'id=?', [$userId]);
        DB::run("DELETE FROM " . DB::t('social_links') . " WHERE user_id=? AND channel=?", [$userId, $channel]);
        DB::run("UPDATE " . DB::t('bot_connect_tokens') . " SET status='revoked' WHERE user_id=? AND (connected_channel=? OR channel=?)", [$userId, $channel, $channel]);
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

/** خواندن getUpdates پیام‌رسان‌ها جهت اتصال خودکار کاربر به ربات */
function fr_snotify_poll_updates(): array
{
    fr_snotify_ensure_schema();
    $stats = [
        'telegram' => ['updates' => 0, 'bound' => 0, 'err' => ''],
        'bale'     => ['updates' => 0, 'bound' => 0, 'err' => ''],
        'rubika'   => ['updates' => 0, 'bound' => 0, 'err' => ''],
    ];

    foreach (['telegram' => 'tg', 'bale' => 'bale', 'rubika' => 'rubika'] as $channel => $short) {
        [$enabled] = fr_snotify_driver($channel);
        if (!$enabled) continue;
        [$cidCol, $unCol] = fr_snotify_cols($channel);
        $offSetting = $short . '_update_offset';
        $offset = (int)DB::setting($offSetting, '0');
        $klass  = match($channel) {
            'telegram' => 'Telegram',
            'bale'     => 'Bale',
            'rubika'   => 'Rubika',
        };
        require_once __DIR__ . '/../core/' . $klass . '.php';

        try {
            [$ok, $res] = $klass::call('getUpdates', ['offset' => $offset, 'limit' => 50, 'timeout' => 0, 'allowed_updates' => ['message']]);
            if (!$ok || !is_array($res)) {
                $stats[$channel]['err'] = 'poll_failed';
                continue;
            }
            $list = $res['result'] ?? (isset($res['messages']) ? $res['messages'] : []);
            $max = $offset;

            foreach ($list as $up) {
                $uid = (int)($up['update_id'] ?? ($up['id'] ?? 0));
                if ($uid > 0) $max = max($max, $uid + 1);
                $stats[$channel]['updates']++;
                $msg = $up['message'] ?? $up;
                if (!is_array($msg)) continue;
                $chatId = (string)($msg['chat']['id'] ?? ($msg['chat_id'] ?? ($msg['from']['id'] ?? '')));
                $from   = $msg['from'] ?? [];
                $uname  = (string)($from['username'] ?? '');
                $fname  = (string)($from['first_name'] ?? '');
                $text   = trim((string)($msg['text'] ?? ($msg['message'] ?? '')));
                if ($chatId === '') continue;

                // استخراج توکن یا دستور استارت
                $token = '';
                if (preg_match('#^/start(?:@\w+)?\s+([A-Za-z0-9_-]+)#u', $text, $m)) {
                    $token = trim($m[1]);
                } elseif (preg_match('/^(FR[A-Fa-f0-9]{8}|[A-Fa-f0-9]{8})$/i', $text, $m2)) {
                    $token = trim($m2[1]);
                } else {
                    $token = $text;
                }

                $actRes = fr_bot_activate($token, $channel, $chatId, $uname !== '' ? $uname : null, $fname);
                if (!empty($actRes['ok'])) {
                    $stats[$channel]['bound']++;
                }
            }
            if ($max !== $offset) {
                DB::setSetting($offSetting, (string)$max);
            }
        } catch (\Throwable $e) {
            $stats[$channel]['err'] = $e->getMessage();
        }
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

        // جدول bot_connect_tokens برای لینک‌های اتصال و فعال‌سازی ربات‌ها توسط مدیر
        DB::run("CREATE TABLE IF NOT EXISTS `{$p}bot_connect_tokens` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `token` VARCHAR(32) NOT NULL,
            `channel` VARCHAR(32) NOT NULL DEFAULT 'all',
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `chat_id` VARCHAR(100) NULL,
            `username` VARCHAR(100) NULL,
            `connected_channel` VARCHAR(32) NULL,
            `connected_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NULL,
            UNIQUE KEY `uq_token` (`token`),
            KEY `ix_user` (`user_id`),
            KEY `ix_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    } catch (Throwable $e) {
        error_log('feria: snotify ensure schema error: ' . $e->getMessage());
    }
}
