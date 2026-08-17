<?php
/**
 * فریا روان — اطلاع‌رسانی شبکه‌های اجتماعی (تلگرام / بله) — نسخهٔ ۲٫۳
 * ─ اتصال با «نام کاربری»: کاربر @username خودش را می‌دهد؛ به محض اینکه یک پیام/استارت به ربات بزند،
 *   ورودی‌های getUpdates (در کرون) نام کاربری‌اش را به chat_id عددی تبدیل و متصل می‌کند.
 * ─ تست اتصال: پیام پیش‌فرضِ قابل‌ویرایش از پنل ادمین برای کاربر ارسال می‌شود.
 * ─ زمان‌بندی روزانهٔ انعطاف‌پذیر: مدیر تعداد اطلاع‌رسانی روز + ساعت‌ها (قالب ۲۴ ساعته) را تعیین می‌کند.
 * ─ ارسال گروهی به کاربران متصل در تلگرام/بله.
 * ─ همهٔ ارسال‌ها (تکی/تست/گروهی/زمان‌بندی‌شده) در fr_notify_log ثبت می‌شوند.
 */

/** کانال‌های پشتیبانی‌شده */
function fr_snotify_channels(): array
{
    return ['telegram' => '🟦 تلگرام', 'bale' => '🟩 بله'];
}

/** کلاینت کانال را لود می‌کند و [enabled, sendClosure, botUsername] برمی‌گرداند */
function fr_snotify_driver(string $channel): array
{
    if ($channel === 'telegram') {
        require_once __DIR__ . '/../core/Telegram.php';
        return [Telegram::enabled(), fn(string $cid, string $text) => Telegram::sendMessage($cid, $text), Telegram::botUsername(), 'Telegram'];
    }
    require_once __DIR__ . '/../core/Bale.php';
    return [Bale::enabled(), fn(string $cid, string $text) => Bale::sendMessage($cid, $text), Bale::botUsername(), 'Bale'];
}

/** اعتبارسنجی نام کاربری پیام‌رسان‌ها (تلگرام: حرف شروع + حروف/عدد/زیرخط ۵ تا ۳۲ کاراکتر) */
function fr_snotify_valid_username(string $un): bool
{
    return (bool)preg_match('/^[A-Za-z][A-Za-z0-9_.]{3,31}$/', $un);
}

/** ستون‌های کاربری هر کانال */
function fr_snotify_cols(string $channel): array
{
    return $channel === 'telegram' ? ['telegram_chat_id', 'tg_username'] : ['bale_chat_id', 'bale_username'];
}

/** آیا کاربر روی کانال متصل است؟ */
function fr_snotify_connected(int $userId, string $channel): bool
{
    [$cidCol] = fr_snotify_cols($channel);
    return trim((string)DB::val("SELECT COALESCE($cidCol,'') FROM " . DB::t('users') . " WHERE id=?", [$userId])) !== '';
}

/** متن پیام تست اتصال — از تنظیمات (قابل ویرایش در پنل ادمین) */
function fr_snotify_test_message(string $channel, string $userName = ''): string
{
    $def = "✅ اتصال شما به ربات با موفقیت انجام شد!\nاز این لحظه به بعد، اطلاع‌رسانی‌های «{site}» به‌صورت کامل و خودکار برای شما ارسال می‌شود. 🦢";
    $tpl = (string)DB::setting('notif_test_msg', $def);
    $site = (string)DB::setting('site_title', 'فریا روان');
    $bot  = (string)DB::setting($channel === 'telegram' ? 'telegram_bot_username' : 'bale_bot_username', '');
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
        return ['ok' => false, 'message' => 'نام کاربری معتبر نیست؛ لاتین بنویسید (مثل ali_mohammadi).'];
    }
    [$cidCol, $unCol] = fr_snotify_cols($channel);
    /* نام کاربری نباید قبلاً به کاربرِ دیگری متصل شده باشد */
    $other = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE $unCol=? AND id<>? LIMIT 1", [$username, $userId]);
    if ($other > 0) return ['ok' => false, 'message' => 'این نام کاربری قبلاً به حساب دیگری متصل شده است.'];

    DB::update('users', [$unCol => $username], 'id=?', [$userId]);

    $code = strtoupper(bin2hex(random_bytes(4)));   // ۸ نویسه
    $row = DB::one("SELECT id FROM " . DB::t('social_links') . " WHERE user_id=? AND channel=?", [$userId, $channel]);
    if ($row) {
        DB::run("UPDATE " . DB::t('social_links') . " SET username=?, connect_code=?, status='pending', created_at=NOW() WHERE id=?",
                [$username, $code, (int)$row['id']]);
    } else {
        DB::insert('social_links', ['user_id' => $userId, 'channel' => $channel, 'username' => $username,
                                    'connect_code' => $code, 'status' => 'pending']);
    }
    [, , $botUser] = fr_snotify_driver($channel);
    $base = $channel === 'telegram' ? 'https://t.me/' : 'https://ble.ir/';
    $botUser = ltrim($botUser, '@');
    $deep  = $botUser !== '' ? $base . $botUser . '?start=' . $code : '';
    return ['ok' => true, 'code' => $code, 'deeplink' => $deep, 'bot' => $botUser];
}

/** قطع اتصال کانال */
function fr_snotify_disconnect(int $userId, string $channel): void
{
    [$cidCol, $unCol] = fr_snotify_cols($channel);
    DB::update('users', [$cidCol => null, $unCol => null], 'id=?', [$userId]);
    DB::run("DELETE FROM " . DB::t('social_links') . " WHERE user_id=? AND channel=?", [$userId, $channel]);
}

/** ثبت لاگ ارسال */
function fr_snotify_log(int $userId, string $channel, string $kind, string $body, bool $ok, string $err = '', ?string $slotDate = null, ?int $slotIdx = null): void
{
    try {
        DB::insert('notify_log', [
            'user_id' => $userId, 'channel' => $channel, 'msg_kind' => $kind, 'body' => mb_substr($body, 0, 900),
            'status' => $ok ? 'sent' : 'failed', 'error' => mb_substr($err, 0, 300),
            'slot_date' => $slotDate, 'slot_idx' => $slotIdx,
        ]);
    } catch (Throwable $e) { /* جدول هنوز نیامده */ }
}

/** ارسال تست اتصال به کاربر — روی کانال مشخص (باید متصل باشد) */
function fr_snotify_send_test(int $userId, string $channel): array
{
    [$cidCol] = fr_snotify_cols($channel);
    $u = DB::one("SELECT id,first_name,$cidCol AS cid FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    $cid = trim((string)($u['cid'] ?? ''));
    if (!$u || $cid === '') return ['ok' => false, 'message' => 'هنوز به این کانال متصل نیستید؛ ابتدا مراحل اتصال را کامل کنید.'];
    $text = fr_snotify_test_message($channel, (string)($u['first_name'] ?? ''));
    [$enabled, $send] = fr_snotify_driver($channel);
    if (!$enabled) {
        // تلاش ثبت می‌شود تا مدیر در گزارش‌ها ببیند تست اتصال کلیک شده
        fr_snotify_log($userId, $channel, 'test', $text, false, 'bot_not_configured');
        return ['ok' => false, 'message' => 'ربات هنوز توسط مدیریت پیکربندی نشده است؛ اتصال شما ثبت شد و پس از فعال‌سازی ربات، پیام تست می‌رسد.'];
    }
    [$ok, $resp] = $send($cid, $text);
    fr_snotify_log($userId, $channel, 'test', $text, (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : json_encode($resp, JSON_UNESCAPED_UNICODE)));
    return ['ok' => (bool)$ok, 'message' => $ok ? 'پیام تست برای شما ارسال شد ✅' : 'ارسال پیام تست ناموفق بود.'];
}

/**
 * خواندن getUpdates و اتصال خودکار نام‌های کاربری به chat_id عددی — در کرون اجرا می‌شود.
 * هر ورودی: /start <کد> → اتصال صریح؛ وگرنه تطابق username فرستنده با اتصال‌های در انتظار/کاربرانِ فاقد chat_id.
 */
function fr_snotify_poll_updates(): array
{
    $stats = ['telegram' => ['updates' => 0, 'bound' => 0, 'err' => ''], 'bale' => ['updates' => 0, 'bound' => 0, 'err' => '']];
    foreach (['telegram' => 'tg', 'bale' => 'bale'] as $channel => $short) {
        [$enabled] = fr_snotify_driver($channel);
        if (!$enabled) continue;
        [$cidCol, $unCol] = fr_snotify_cols($channel);
        $offSetting = $short . '_update_offset';
        $offset = (int)DB::setting($offSetting, '0');
        $klass  = $channel === 'telegram' ? 'Telegram' : 'Bale';
        require_once __DIR__ . '/../core/' . $klass . '.php';
        [$ok, $res] = $klass::call('getUpdates', ['offset' => $offset, 'limit' => 100, 'timeout' => 0, 'allowed_updates' => ['message']]);
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

            /* ۰) ۲٫۵ — به‌اشتراک‌گذاری شمارهٔ تماس: تطبیق خودکار با «مخاطبین» و کاربر سایت
               (مسیر دعوت کمپین‌ها پیش از start: شماره‌اش را می‌فرستد → به‌رسمیت شناخته می‌شود) */
            if (!empty($msg['contact']['phone_number'])) {
                $ph = (string)$msg['contact']['phone_number'];
                if (function_exists('fr_contact_mobile')) $phN = fr_contact_mobile($ph);
                else { $phN = en_num($ph); $phN = preg_replace('/[^\d]/', '', $phN); if (strlen($phN) === 10) $phN = '0' . $phN; }
                if (preg_match('/^09\d{9}$/', (string)$phN)) {
                    try {
                        /* کاربر سایت با این موبایل ←‌ اتصال خودکار چت‌آیدی (چت با ربات را start کرده) */
                        $uT = DB::one("SELECT id FROM " . DB::t('users') . " WHERE mobile=? LIMIT 1", [$phN]);
                        if ($uT) {
                            DB::update('users', [$cidCol => $chatId], 'id=?', [(int)$uT['id']]);
                            DB::run("INSERT INTO " . DB::t('social_links') . " (user_id,channel,username,connect_code,chat_id,status,connected_at)
                                     VALUES (?,?,?,?,?,'connected',NOW())
                                     ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id), status='connected', connected_at=NOW()",
                                    [(int)$uT['id'], $channel, $uname !== '' ? $uname : null, strtoupper(bin2hex(random_bytes(4))), $chatId]);
                        }
                    /* مخاطب درون‌ریزی‌شده ← علامت قابل‌دسترس + ۲٫۷: چت‌آیدی هم ثبت می‌شود */
                    $cidColName = $channel === 'telegram' ? 'tg_chat_id' : 'bale_chat_id';
                    DB::run("UPDATE " . DB::t('contacts') . " SET " . ($channel === 'telegram' ? 'tg_ok' : 'bale_ok') . "=1,
                             $cidColName=?, updated_at=NOW() WHERE mobile=?", [$chatId, $phN]);
                        [, $sendC] = fr_snotify_driver($channel);
                        $cName = (string)DB::val("SELECT COALESCE(NULLIF(name,''), first_name, 'عزیز') FROM " . DB::t('contacts') . " c LEFT JOIN " . DB::t('users') . " u ON u.mobile=c.mobile WHERE c.mobile=? LIMIT 1", [$phN]);
                        [$okC, $respC] = $sendC($chatId, '🤝 سپاس ' . ($cName !== '' ? $cName : 'عزیز') . "!\nشماره‌ات ثبت شد؛ از این پس اطلاع‌رسانی‌های «" . (string)DB::setting('site_title', 'فریا روان') . "» همین‌جا، مستقیم برایت می‌آید. 🦢");
                        if ($uT) fr_snotify_log((int)$uT['id'], $channel, 'connected', 'ثبت شمارهٔ تماس توسط کاربر', (bool)$okC, $okC ? '' : (is_string($respC) ? $respC : ''));
                    } catch (Throwable $e) { error_log('feria: contact-share bind: ' . $e->getMessage()); }
                }
            }

            /* ۱) اتصال صریح با کد: /start CODE یا ارسال خود کد */
            $code = '';
            if (preg_match('#^/start(?:@\w+)?\s+([A-Fa-f0-9]{8})\b#u', $text, $m)) $code = strtoupper($m[1]);
            elseif (preg_match('/^([A-Fa-f0-9]{8})$/', $text, $m2)) $code = strtoupper($m2[1]);
            $link = null;
            if ($code !== '') {
                $link = DB::one("SELECT * FROM " . DB::t('social_links') . " WHERE channel=? AND connect_code=? AND status='pending' LIMIT 1", [$channel, $code]);
                /* ۲٫۷ — کد دعوت «مخاطبین» (دیپ‌لینک start خودکار از پیامک دعوت/کمپین) */
                if (!$link) {
                    try {
                        $cInv = DB::one("SELECT id FROM " . DB::t('contacts') . " WHERE invite_code=? LIMIT 1", [$code]);
                        if ($cInv) {
                            $cidCol2 = $channel === 'telegram' ? 'tg_chat_id' : 'bale_chat_id';
                            $okCol2  = $channel === 'telegram' ? 'tg_ok' : 'bale_ok';
                            $unCol2  = $channel === 'telegram' ? 'tg_username' : 'bale_username';
                            $fixC = [$cidCol2 => $chatId, $okCol2 => 1, 'checked_at' => date('Y-m-d H:i:s')];
                            if ($uname !== '') $fixC[$unCol2] = $uname;
                            DB::update('contacts', $fixC, 'id=?', [(int)$cInv['id']]);
                            $cName = (string)DB::val("SELECT name FROM " . DB::t('contacts') . " WHERE id=?", [(int)$cInv['id']]);
                            [, $sendW] = fr_snotify_driver($channel);
                            [$okW] = $sendW($chatId, '🤝 ' . ($cName !== '' ? $cName : 'سلام') . "! اتصال شما ثبت شد.\nاز این پس پیام‌های «" . (string)DB::setting('site_title', 'فریا روان') . "» مستقیم همین‌جا برایتان می‌آید. 🦢");
                            $stats[$channel]['bound']++;
                        }
                    } catch (Throwable $e) { error_log('feria: contact invite bind: ' . $e->getMessage()); }
                }
            }
            /* ۲٫۷ — ثبت «پاسخ دریافتی» برای گزارش کمپین‌ها: پیام متنیِ چتی که پیام کمپین
               گرفته، نزدیک‌ترین کمپینِ اخیرش را replied علامت می‌خورد (یک‌بار برای هر گیرنده). */
            if ($text !== '' && $code === '' && empty($msg['contact']['phone_number'])) {
                try {
                    DB::run("UPDATE " . DB::t('campaign_messages') . "
                             SET replied_at = NOW()
                             WHERE channel=? AND chat_id=? AND status='sent' AND replied_at IS NULL
                               AND sent_at >= NOW() - INTERVAL 14 DAY
                             ORDER BY sent_at DESC LIMIT 1", [$channel, $chatId]);
                } catch (Throwable $e) { /* جدول/ستون ۲٫۷ هنوز نیامده */ }
            }
            /* ۲) تطابق نام کاربری — اتصال خودکار «تبدیل نام کاربری به شناسهٔ عددی» */
            if (!$link && $uname !== '') {
                $link = DB::one("SELECT * FROM " . DB::t('social_links') . " WHERE channel=? AND username=? AND status='pending' LIMIT 1", [$channel, $uname]);
                if (!$link) {
                    $uidByUn = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE $unCol=? AND COALESCE($cidCol,'')='' LIMIT 1", [$uname]);
                    if ($uidByUn > 0) {
                        $link = ['user_id' => $uidByUn, 'username' => $uname];
                    }
                }
            }
            if ($link) {
                $targetUid = (int)$link['user_id'];
                DB::update('users', [$cidCol => $chatId, $unCol => $uname !== '' ? $uname : ($link['username'] ?? null)], 'id=?', [$targetUid]);
                DB::run("UPDATE " . DB::t('social_links') . " SET status='connected', chat_id=?, connected_at=NOW() WHERE user_id=? AND channel=?",
                        [$chatId, $targetUid, $channel]);
                $stats[$channel]['bound']++;
                /* پیام خوش‌آمد اتصال — همان قالبِ قابل‌ویرایشِ «تست اتصال» */
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

/** ساعت‌های اطلاع‌رسانی روزانه (۲۴ ساعته HH:MM) — بر اساس تنظیم تعداد روزانه */
function fr_snotify_slots(): array
{
    $count = max(1, min(6, (int)DB::setting('notif_daily_count', '3')));
    $raw = trim((string)DB::setting('notif_times', ''));
    $times = [];
    if ($raw !== '') {
        $dec = json_decode($raw, true);
        if (is_array($dec)) $times = $dec;
    }
    if (!$times) $times = ['08:30', '13:00', '20:30', '17:00', '10:30', '22:00'];   // پیش‌فرض‌ها تا ۶ نوبت
    $times = array_values(array_filter(array_map(function ($t) {
        $t = trim((string)$t);
        return fr_valid_time($t, '');
    }, $times)));
    if (!$times) $times = ['08:30'];
    return array_slice($times, 0, $count);
}

/** ساخت پیام هر نوبت برای یک کاربر (محتوای پویا: برنامهٔ امروز + انگیزشی + محتوای تازهٔ سایت) */
function fr_snotify_compose(array $u, int $slotIdx, string $slotTime): string
{
    $site  = (string)DB::setting('site_title', 'فریا روان');
    $name  = trim((string)($u['first_name'] ?? '')) ?: 'عزیز';
    $today = date('Y-m-d');
    $pending = 0; $topTasks = [];
    if (fr_planner_enabled()) {
        $rows = DB::all("SELECT title FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=? AND done=0 ORDER BY FIELD(priority,'high','normal','low'), id LIMIT 3", [(int)$u['id'], $today]);
        $pending = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " WHERE user_id=? AND day=? AND done=0", [(int)$u['id'], $today]);
        $topTasks = array_map(fn($r) => '▫️ ' . $r['title'], $rows);
    }
    $tips = [
        'یک قدم کوچک امروز، از یک برنامهٔ بزرگ فردا مهم‌تر است. 🦢',
        'تمرکز عمیق ۲۵ دقیقه‌ای، معجزهٔ بهره‌وری مدیران است. ⏱',
        'پیشرفت یعنی استمرار، نه کمال. امروز هم ادامه بده. 💎',
        'آرامش ذهن یعنی دانستنِ اینکه کارهایت مرتب و برنامه‌ریزی‌شده‌اند.',
        'یک تست روانشناسی امروز، خودشناسیِ بهترِ فرداست. 🧠',
        'عادت‌های کوچک، سرنوشت‌های بزرگ می‌سازند.',
    ];
    $greet = ['صبح بخیر 🌅', 'وقت بخیر ☀️', 'عصر بخیر 🌆', 'شب بخیر 🌙'];
    $h = (int)substr($slotTime, 0, 2);
    $g = $greet[$h < 11 ? 0 : ($h < 16 ? 1 : ($h < 20 ? 2 : 3))];

    $msg = "$g $name!\n";
    if ($slotIdx === 0 && $pending > 0) {
        $msg .= 'امروز ' . fa_num($pending) . " کار در برنامه‌ات مانده:\n" . implode("\n", $topTasks) . "\n";
    } elseif ($slotIdx >= 1 && $pending > 0) {
        $msg .= 'هنوز ' . fa_num($pending) . " کارِ امروز باقی است — وقتشه شروعشان کنی! ✅\n";
    } else {
        $msg .= $tips[array_rand($tips)] . "\n";
    }
    if ($slotIdx >= 2) {
        $new = DB::one("SELECT title FROM " . DB::t('events') . " WHERE kind='workshop' AND status='active' ORDER BY id DESC LIMIT 1");
        if ($new) $msg .= "🛠 تازه‌ترین کارگاه: «" . $new['title'] . "»\n";
    }
    $msg .= '— ' . $site;
    return $msg;
}

/**
 * اجرای سر نوبت‌های اطلاع‌رسانی (در کرون، هر چند دقیقه فراخوانی می‌شود) — قالب ساعت ۲۴ ساعته.
 * هر نوبت فقط یک‌بار در روز اجرا می‌شود (قفل تنظیماتی)؛ بدون force فقط در پنجرهٔ [HH:MM تا +۳۰ دقیقه].
 */
function fr_snotify_run_due(bool $force = false): array
{
    $today = date('Y-m-d');
    $nowMin = (int)date('H') * 60 + (int)date('i');
    $result = [];
    foreach (fr_snotify_slots() as $idx => $hhmm) {
        [$sh, $sm] = array_map('intval', explode(':', $hhmm));
        $slotMin = $sh * 60 + $sm;
        $lock = 'notif_slot_' . date('Ymd') . '_' . $idx;
        if (DB::setting($lock, '0') === '1') { $result[] = "$hhmm:already"; continue; }
        if (!$force && ($nowMin < $slotMin || $nowMin > $slotMin + 30)) { $result[] = "$hhmm:wait"; continue; }
        $users = DB::all("SELECT id,first_name,telegram_chat_id,bale_chat_id FROM " . DB::t('users') .
                         " WHERE status='active' AND (COALESCE(telegram_chat_id,'')<>'' OR COALESCE(bale_chat_id,'')<>'')");
        $sent = 0; $fail = 0;
        foreach ($users as $u) {
            $msg = fr_snotify_compose($u, (int)$idx, $hhmm);
            foreach (['telegram' => 'telegram_chat_id', 'bale' => 'bale_chat_id'] as $ch => $col) {
                $cid = trim((string)($u[$col] ?? ''));
                if ($cid === '') continue;
                [$enabled, $send] = fr_snotify_driver($ch);
                if (!$enabled) continue;
                [$ok, $resp] = $send($cid, $msg);
                $ok ? $sent++ : $fail++;
                fr_snotify_log((int)$u['id'], $ch, 'slot', $msg, (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : ''), $today, (int)$idx);
                usleep(120000);   // ملایم با محدودیت نرخ ربات‌ها
            }
        }
        DB::setSetting($lock, '1');
        $result[] = "$hhmm:sent=$sent,failed=$fail";
    }
    return $result;
}

/** ارسال گروهی از پنل ادمین — خروجی [sent, failed] */
function fr_snotify_bulk(string $channel, string $text, array $userIds = []): array
{
    $sent = 0; $fail = 0;
    $targets = [];
    if ($userIds) {
        $in = implode(',', array_map('intval', $userIds));
        $targets = DB::all("SELECT id,telegram_chat_id,bale_chat_id FROM " . DB::t('users') . " WHERE id IN ($in)");
    } else {
        $w = $channel === 'both'
            ? "(COALESCE(telegram_chat_id,'')<>'' OR COALESCE(bale_chat_id,'')<>'')"
            : "COALESCE(" . ($channel === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id') . ",'')<>''";
        $targets = DB::all("SELECT id,telegram_chat_id,bale_chat_id FROM " . DB::t('users') . " WHERE status='active' AND $w");
    }
    foreach ($targets as $u) {
        foreach (['telegram' => 'telegram_chat_id', 'bale' => 'bale_chat_id'] as $ch => $col) {
            if ($channel !== 'both' && $ch !== $channel) continue;
            $cid = trim((string)($u[$col] ?? ''));
            if ($cid === '') continue;
            [$enabled, $send] = fr_snotify_driver($ch);
            if (!$enabled) { $fail++; continue; }
            [$ok, $resp] = $send($cid, $text);
            $ok ? $sent++ : $fail++;
            fr_snotify_log((int)$u['id'], $ch, 'bulk', $text, (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : ''));
            usleep(120000);
        }
    }
    return [$sent, $fail];
}

/** وضعیت اتصال یک کاربر برای نمایش در پنل */
function fr_snotify_status(int $userId): array
{
    $u = DB::one("SELECT telegram_chat_id,tg_username,bale_chat_id,bale_username FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    $links = DB::all("SELECT channel,username,connect_code,status FROM " . DB::t('social_links') . " WHERE user_id=? AND status='pending'", [$userId]);
    $pending = [];
    foreach ($links as $l) $pending[$l['channel']] = $l;
    $out = [];
    foreach (fr_snotify_channels() as $ch => $label) {
        [$cidCol, $unCol] = fr_snotify_cols($ch);
        $out[$ch] = [
            'label'   => $label,
            'connected' => trim((string)($u[$cidCol] ?? '')) !== '',
            'chat_id' => (string)($u[$cidCol] ?? ''),
            'username' => (string)($u[$unCol] ?? ''),
            'pending'  => $pending[$ch] ?? null,
        ];
    }
    return $out;
}
