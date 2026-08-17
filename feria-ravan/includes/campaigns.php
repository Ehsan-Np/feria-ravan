<?php
/**
 * ============================================================================
 *  کمپین‌های تبلیغاتی چندکاناله (نسخهٔ ۲٫۷) — پیامک + تلگرام + بله
 *  ─ کانال‌ها به‌صورت تیکی انتخاب می‌شوند (sms/telegram/bale — ترکیب دلخواه)؛
 *    برای هر گیرنده یک پیام به‌ازای هر کانالِ تیک‌خورده ساخته می‌شود؛ مخاطبِ
 *    بدون اتصال در پیام‌رسان با «پیامک دعوت (start خودکار)» پوشش می‌یابد.
 *  ─ کنترل کامل ارسال: حجم هر دسته (batch_size)، وقفهٔ بین پیام‌ها (delay_ms)،
 *    زمان‌بندی شروع (scheduled_at)، توقف/ادامه، ویرایش پیش‌نویس، تکثیر کمپین.
 *  ─ گزارش دقیق: ارسال‌شده/ناموفق/در صف، تحویل پیامک (استعلام لحظه‌ای کاوه‌نگار)،
 *    کلیک روی لینک‌ها (بازنویسی خودکار به go.php)، CTR، پاسخ‌های دریافتی در ربات.
 *  ─ نکتهٔ فنی صادقانه: Bot API تلگرام/بله «خوانده‌شدن» پیام را گزارش نمی‌دهد؛
 *    در گزارش، «مشاهده/خواندن» فقط برای پیامک (تحویل اپراتور) و تعاملها
 *    (کلیک/پاسخ) سنجیده می‌شود و در UI توضیح داده شده است.
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/snotify.php';

/* ── پارس کانال‌ها: پذیرش فرمت جدید (csv) و قدیمی (enum) ───────────────── */
function fr_campaign_channel_list(array $campaign): array
{
    $csv = trim((string)($campaign['channels'] ?? ''));
    if ($csv !== '') {
        $list = array_values(array_intersect(array_filter(array_map('trim', explode(',', strtolower($csv)))),
            ['sms', 'telegram', 'bale']));
        if ($list) return $list;
    }
    $legacy = (string)($campaign['channel'] ?? 'both');
    return $legacy === 'both' ? ['telegram', 'bale'] : [$legacy];
}

/** لیبل فارسی کانال‌ها */
function fr_campaign_channels_label(array $campaign): string
{
    $fa = ['sms' => 'پیامک', 'telegram' => 'تلگرام', 'bale' => 'بله'];
    $labels = array_map(fn($c) => $fa[$c] ?? $c, fr_campaign_channel_list($campaign));
    if ((int)($campaign['sms_invite'] ?? 0) === 1 && !in_array('sms', fr_campaign_channel_list($campaign), true)) {
        $labels[] = 'پیامک دعوت';
    }
    return implode(' + ', $labels);
}

/* ── پاک‌سازی HTML ویرایشگر به زیرمجموعهٔ مجاز تلگرام/بله ───────────────
   با متوازن‌سازی پشته‌ای: تگِ بستهٔ بی‌باز و بازِ بی‌بسته حذف می‌شود تا
   Telegram/Bale به‌خاطر entity ناقص، پیام را رد نکند. */
function fr_campaign_sanitize_html(string $html): string
{
    $html = str_replace(["\r\n", "\r"], "\n", $html);
    $aOpen = 0;
    $html = preg_replace_callback('#<(/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s+[a-zA-Z-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*/?>#',
        function ($m) use (&$aOpen) {
            $tag = strtolower($m[2]);
            $map = ['strong' => 'b', 'em' => 'i', 'strike' => 's', 'del' => 's'];
            $tag = $map[$tag] ?? $tag;
            if (!in_array($tag, ['b','i','u','s','a','code','pre'], true)) {
                return in_array($tag, ['br'], true) ? "\n" : (in_array($tag, ['div','p','li'], true) && $m[1] === '/' ? "\n" : '');
            }
            if ($m[1] === '/') {
                if ($tag === 'a') { if ($aOpen === 0) return ''; $aOpen--; }
                return "</$tag>";
            }
            if ($tag === 'a') {
                preg_match('/href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $m[3], $hm);
                $href = $hm[1] ?? ($hm[2] ?? '');
                if (!preg_match('#^https?://#i', $href)) return '';
                $aOpen++;
                return '<a href="' . e($href) . '">';
            }
            return "<$tag>";
        }, $html);
    /* پاس دوم: پشتهٔ توازن — بسته‌های بی‌باز حذف، بازهای بی‌بسته از انتها حذف */
    $stack = [];
    $html = preg_replace_callback('/<(\/?)(b|i|u|s|a|code|pre)(?:\s+[^>]*)?>/i', function ($m) use (&$stack) {
        $tag = strtolower($m[2]);
        if ($m[1] === '/') {
            if (!in_array($tag, $stack, true)) return '';
            while ($stack && array_pop($stack) !== $tag) { /* تگ‌های داخل را هم می‌پوزد */ }
            return "</$tag>";
        }
        $stack[] = $tag;
        return $m[0];
    }, (string)$html);
    while ($stack) {
        $tag = array_pop($stack);
        $pat = $tag === 'a' ? '#<a\s+[^>]*>(?![\s\S]*<a\s)#' : '#<' . $tag . '>(?![\s\S]*<' . $tag . '>)#';
        $html = preg_replace($pat, '', (string)$html, 1);
    }
    /* لایین‌های خالی مکرر و فاصله‌های اضافی */
    $html = preg_replace("/\n{3,}/", "\n\n", (string)$html);
    return mb_substr(trim($html), 0, 3900);
}

/** HTML بی‌برچسب برای پیامک (با حفظ سطرها) */
function fr_campaign_plain(string $html): string
{
    $t = preg_replace('#<a\s+href="([^"]*)"[^>]*>(.*?)</a>#s', '$2 ($1)', $html);
    $t = strip_tags((string)$t);
    return mb_substr(trim(preg_replace("/\n{3,}/", "\n\n", $t)), 0, 880);
}

/** جایگذاری الگوهای شخصی‌سازی */
function fr_campaign_fill(string $tpl, string $name, string $mobile): string
{
    return strtr($tpl, [
        '{name}'   => $name !== '' ? $name : 'عزیز',
        '{mobile}' => $mobile,
        '{site}'   => (string)DB::setting('site_title', 'فریا روان'),
        '{date}'   => fa_num(jdate('j F Y')),
    ]);
}

/**
 * ساخت کمپین (پیش‌نویس) — ۲٫۷: $opts = [
 *   channels[], batch_size, delay_ms, scheduled_at('Y-m-d H:i'), track_links
 * ]
 */
function fr_campaign_create(string $title, string $html, string $channel, string $audience, string $segment, int $smsInvite, int $adminId = 0, array $opts = []): int
{
    $title = mb_substr(trim($title), 0, 190);
    $html  = fr_campaign_sanitize_html($html);
    if ($title === '' || $html === '') return 0;
    if (!in_array($audience, ['users','contacts','both'], true)) $audience = 'contacts';

    $channels = array_values(array_intersect(array_map('strval', (array)($opts['channels'] ?? [])), ['sms','telegram','bale']));
    if (!$channels) $channels = in_array($channel, ['telegram','bale'], true) ? [$channel] : ['telegram','bale'];
    /* ستون legacy برای سازگاری */
    $legacy = 'both';
    if (count(array_intersect($channels, ['telegram','bale'])) === 1) $legacy = in_array('telegram', $channels, true) ? 'telegram' : 'bale';
    elseif (!array_intersect($channels, ['telegram','bale'])) $legacy = 'both';

    $batch = max(1, min(500, (int)($opts['batch_size'] ?? 15)));
    $delay = max(0, min(5000, (int)($opts['delay_ms'] ?? 150)));
    $sched = trim((string)($opts['scheduled_at'] ?? ''));
    $sched = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $sched) ? substr(str_replace('T', ' ', $sched), 0, 16) . ':00' : null;

    return DB::insert('campaigns', [
        'title' => $title, 'body' => $html, 'channel' => $legacy, 'audience' => $audience,
        'segment' => mb_substr(trim($segment), 0, 60), 'sms_invite' => $smsInvite ? 1 : 0,
        'channels' => implode(',', $channels), 'batch_size' => $batch, 'delay_ms' => $delay,
        'scheduled_at' => $sched, 'track_links' => !empty($opts['track_links']) ? 1 : 0,
        'status' => 'draft', 'created_by' => $adminId ?: null,
    ]);
}

/** ۲٫۷ — ویرایش کمپین (فقط تا پیش از شروع ارسال) */
function fr_campaign_edit(int $id, array $fields): bool
{
    $c = DB::one("SELECT * FROM " . DB::t('campaigns') . " WHERE id=?", [$id]);
    if (!$c || !in_array($c['status'], ['draft','paused','scheduled','edited'], true)) return false;
    $title = mb_substr(trim((string)($fields['title'] ?? '')), 0, 190);
    $html  = fr_campaign_sanitize_html((string)($fields['body'] ?? ''));
    if ($title === '' || $html === '') return false;
    $channels = array_values(array_intersect(array_map('strval', (array)($fields['channels'] ?? [])), ['sms','telegram','bale']));
    if (!$channels) $channels = ['telegram','bale'];
    $legacy = 'both';
    if (count(array_intersect($channels, ['telegram','bale'])) === 1) $legacy = in_array('telegram', $channels, true) ? 'telegram' : 'bale';
    $sched = trim((string)($fields['scheduled_at'] ?? ''));
    $sched = preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $sched) ? substr(str_replace('T', ' ', $sched), 0, 16) . ':00' : null;
    DB::update('campaigns', [
        'title' => $title, 'body' => $html, 'channel' => $legacy,
        'audience' => in_array(($fields['audience'] ?? ''), ['users','contacts','both'], true) ? $fields['audience'] : 'contacts',
        'segment' => mb_substr(trim((string)($fields['segment'] ?? '')), 0, 60),
        'sms_invite' => !empty($fields['sms_invite']) ? 1 : 0,
        'channels' => implode(',', $channels),
        'batch_size' => max(1, min(500, (int)($fields['batch_size'] ?? 15))),
        'delay_ms' => max(0, min(5000, (int)($fields['delay_ms'] ?? 150))),
        'scheduled_at' => $sched,
        'track_links' => !empty($fields['track_links']) ? 1 : 0,
        'status' => 'edited',
    ], 'id=?', [$id]);
    return true;
}

/** ۲٫۷ — تکثیر کمپین (پیش‌نویس تازه با همان تنظیم‌ها) */
function fr_campaign_duplicate(int $id, int $adminId = 0): int
{
    $c = DB::one("SELECT * FROM " . DB::t('campaigns') . " WHERE id=?", [$id]);
    if (!$c) return 0;
    return DB::insert('campaigns', [
        'title' => mb_substr($c['title'] . ' (کپی)', 0, 190), 'body' => $c['body'],
        'channel' => $c['channel'], 'audience' => $c['audience'], 'segment' => $c['segment'],
        'sms_invite' => (int)$c['sms_invite'], 'channels' => (string)($c['channels'] ?? ''),
        'batch_size' => (int)($c['batch_size'] ?? 15), 'delay_ms' => (int)($c['delay_ms'] ?? 150),
        'scheduled_at' => null, 'track_links' => (int)($c['track_links'] ?? 1),
        'status' => 'draft', 'created_by' => $adminId ?: null,
    ]);
}

/**
 * امضای لینک رهگیری — ضدجعل تا هر کس نتواند شمارش کلیک را خراب کند.
 */
function fr_campaign_link_sig(int $messageId, int $idx): string
{
    $salt = defined('FR_CRYPT_KEY') ? FR_CRYPT_KEY : 'feria';
    return substr(hash_hmac('sha256', $messageId . '.' . $idx, $salt), 0, 10);
}

/**
 * بازنویسی لینک‌های یک بدنهٔ کمپین برای رهگیری کلیک.
 * خروجی: [html, links] که links = [ idx => url ]
 * لینک‌ها به go.php?m=<mid>&i=<idx>&s=<sig> تبدیل می‌شوند (پس از دانلود id پیام جایگذاری می‌شود).
 */
function fr_campaign_track_extract(string $html): array
{
    $links = [];
    $out = preg_replace_callback('#<a\s+href="([^"]+)"([^>]*)>#i', function ($m) use (&$links) {
        $url = $m[1];
        if (!preg_match('#^https?://#i', $url)) return $m[0];
        $idx = count($links);
        $links[] = $url;
        return '<a href="__FRGO_' . $idx . '__"' . $m[2] . '>';
    }, $html);
    return [(string)$out, $links];
}

/** جایگذاری نشانی نهایی رهگیری پس از دانستن id پیام */
function fr_campaign_track_finalize(string $html, int $messageId): string
{
    return (string)preg_replace_callback('#__FRGO_(\d+)__#', function ($m) use ($messageId) {
        $idx = (int)$m[1];
        return rtrim(SITE_URL, '/') . '/go.php?m=' . $messageId . '&i=' . $idx . '&s=' . fr_campaign_link_sig($messageId, $idx);
    }, $html);
}

/**
 * صف‌سازی کمپین: گیرنده‌ها حل و پیامِ شخصی‌سازی‌شدهٔ هرکدام در campaign_messages
 * ثبت می‌شود؛ لینک‌ها برای رهگیری کلیک بازنویسی می‌شوند.
 * ۲٫۷: به‌ازای هر کانالِ تیک‌خورده یک پیام (telegram/bale/sms)؛ sms_invite فقط
 * برای گیرنده‌های بدون هیچ اتصال اجتماعی یک پیامک دعوت می‌سازد.
 */
function fr_campaign_queue(int $id): array
{
    $c = DB::one("SELECT * FROM " . DB::t('campaigns') . " WHERE id=?", [$id]);
    if (!$c || !in_array($c['status'], ['draft','paused','scheduled','edited'], true)) {
        return ['ok' => false, 'message' => 'کمپین قابلیت صف‌سازی ندارد.'];
    }
    DB::run("DELETE FROM " . DB::t('campaign_messages') . " WHERE campaign_id=? AND status='pending'", [$id]);

    fr_contacts_match_social();
    $channels = fr_campaign_channel_list($c);
    $targets = [];   // mobile => ['name','user_id'?,'contact_id'?,'tg','bl']

    if (in_array($c['audience'], ['users','both'], true)) {
        foreach (DB::all("SELECT id,mobile,first_name,last_name,telegram_chat_id,bale_chat_id FROM " . DB::t('users') . " WHERE status='active'") as $u) {
            $mob = fr_contact_mobile((string)$u['mobile']);
            if ($mob === '') continue;
            $targets[$mob] = ['name' => trim($u['first_name'] . ' ' . $u['last_name']), 'user_id' => (int)$u['id'],
                              'contact_id' => null, 'tg' => (string)$u['telegram_chat_id'], 'bl' => (string)$u['bale_chat_id']];
        }
    }
    if (in_array($c['audience'], ['contacts','both'], true)) {
        $segCol = trim((string)$c['segment']);
        $setSet = $segCol === '' ? [] : array_values(array_unique(array_filter(array_map('trim', explode('،', str_replace(',', '،', $segCol))))));
        $w = ""; $p = [];
        if ($setSet) {
            /* ۲٫۷: چند گروه هم‌زمان — با تطبیق نام گروه در جدول گروه‌ها یا برچسب segment قدیمی */
            $w = " AND (c.segment IN (" . implode(',', array_fill(0, count($setSet), '?')) . ")
                     OR EXISTS (SELECT 1 FROM " . DB::t('contact_group_members') . " gm
                                JOIN " . DB::t('contact_groups') . " g ON g.id=gm.group_id
                                WHERE gm.contact_id=c.id AND g.name IN (" . implode(',', array_fill(0, count($setSet), '?')) . ")))";
            $p = array_merge($setSet, $setSet);
        }
        foreach (DB::all("SELECT c.id,c.name,c.mobile,c.tg_chat_id,c.bale_chat_id,
                                 u.id AS uid, u.telegram_chat_id, u.bale_chat_id
                          FROM " . DB::t('contacts') . " c
                          LEFT JOIN " . DB::t('users') . " u ON u.mobile=c.mobile AND u.status='active'
                          WHERE c.status='active' $w", $p) as $ct) {
            $mob = fr_contact_mobile((string)$ct['mobile']);
            if ($mob === '') continue;
            $tgC = trim((string)($ct['tg_chat_id'] ?? '')) !== '' ? (string)$ct['tg_chat_id'] : (string)($ct['telegram_chat_id'] ?? '');
            $blC = trim((string)($ct['bale_chat_id'] ?? '')) !== '' ? (string)$ct['bale_chat_id'] : (string)($ct['bale_chat_id'] ?? '');
            $existing = $targets[$mob] ?? null;
            $targets[$mob] = [
                'name'       => $existing['name'] ?? (string)$ct['name'],
                'user_id'    => $existing['user_id'] ?? ($ct['uid'] ? (int)$ct['uid'] : null),
                'contact_id' => (int)$ct['id'],
                'tg'         => trim((string)($existing['tg'] ?? '')) !== '' ? $existing['tg'] : $tgC,
                'bl'         => trim((string)($existing['bl'] ?? '')) !== '' ? $existing['bl'] : $blC,
            ];
        }
    }

    /* لینک دعوت ربات برای مخاطبین پیشِ start */
    $botLinks = [];
    foreach (['telegram','bale'] as $ch) { [, , $bu] = fr_snotify_driver($ch); $bu = ltrim($bu, '@');
        $botLinks[$ch] = $bu !== '' ? (($ch === 'telegram' ? 'https://t.me/' : 'https://ble.ir/') . $bu) : ''; }

    $trackOn = (int)($c['track_links'] ?? 1) === 1 && (string)DB::setting('campaign_track_links', '1') === '1';
    $inserted = 0; $skips = 0; $perChannel = ['telegram' => 0, 'bale' => 0, 'sms' => 0];
    foreach ($targets as $mob => $t) {
        $queuedSocial = 0;
        foreach ($channels as $ch) {
            if ($ch === 'sms') {
                /* پیامکِ صریح: متن سادهٔ خودکار + (اختیاراً) لینک دعوت نمی‌خورد چون خودش کانال است */
                $body = fr_campaign_plain(fr_campaign_fill((string)$c['body'], (string)$t['name'], $mob));
                if (fr_campaign_add_message($id, 'sms', $t, $mob, '', $body, $trackOn)) { $inserted++; $perChannel['sms']++; }
                else $skips++;
                continue;
            }
            $cid = $ch === 'telegram' ? $t['tg'] : $t['bl'];
            if ($cid === '') { $skips++; continue; }   // این کانال برای این گیرنده لینک نیست
            $body = fr_campaign_fill((string)$c['body'], (string)$t['name'], $mob);
            if (fr_campaign_add_message($id, $ch, $t, $mob, $cid, $body, $trackOn)) { $inserted++; $perChannel[$ch]++; $queuedSocial++; }
            else $skips++;
        }
        /* پیامک دعوت: هیچ کانال اجتماعی صف نشد و sms هم جزو کانال‌ها نبود */
        if ($queuedSocial === 0 && (int)$c['sms_invite'] === 1 && !in_array('sms', $channels, true)) {
            $link = ($botLinks['telegram'] ?? '') !== '' ? $botLinks['telegram'] : ($botLinks['bale'] ?? '');
            $plain = fr_campaign_plain(fr_campaign_fill((string)$c['body'], (string)$t['name'], $mob));
            $smsBody = $plain . ($link !== '' ? "\n📲 دریافت در پیام‌رسان (استارت خودکار):\n" . $link : '');
            /* کد دعوت دیپ‌لینک اختصاصی مخاطب — اگر contact است کد خودش را بگذار */
            if (!empty($t['contact_id'])) {
                try {
                    $crow = DB::one("SELECT id,invite_code FROM " . DB::t('contacts') . " WHERE id=?", [(int)$t['contact_id']]);
                    if ($crow) {
                        $inv = fr_contact_invite_link($crow, 'telegram');
                        if ($inv !== '') $smsBody = $plain . "\n📲 دریافت در پیام‌رسان (استارت خودکار):\n" . $inv;
                    }
                } catch (Throwable $e) { }
            }
            if (fr_campaign_add_message($id, 'sms', $t, $mob, '', $smsBody, false)) { $inserted++; $perChannel['sms']++; }
            else $skips++;
        }
    }
    $newStatus = 'queued';
    if (!empty($c['scheduled_at']) && strtotime((string)$c['scheduled_at']) > time()) $newStatus = 'scheduled';
    DB::update('campaigns', ['status' => $newStatus, 'queued_at' => date('Y-m-d H:i:s'), 'total' => $inserted,
                             'sent' => 0, 'failed' => 0, 'skipped' => $skips], 'id=?', [$id]);
    return ['ok' => true, 'total' => $inserted, 'skipped' => $skips, 'channels' => $perChannel, 'status' => $newStatus];
}

/** درج یک پیام در صف + رهگیری لینک (دو مرحله‌ای برای دانستن id). خروجی bool */
function fr_campaign_add_message(int $campId, string $channel, array $t, string $mob, string $chatId, string $body, bool $track): bool
{
    try {
        [$trackedBody, $links] = $track ? fr_campaign_track_extract($body) : [$body, []];
        DB::insert('campaign_messages', [
            'campaign_id' => $campId, 'channel' => $channel, 'user_id' => $t['user_id'], 'contact_id' => $t['contact_id'],
            'name' => mb_substr((string)$t['name'], 0, 120), 'mobile' => $mob, 'chat_id' => $chatId,
            'body' => $trackedBody, 'status' => 'pending',
        ]);
        $mid = (int)DB::val("SELECT LAST_INSERT_ID()");
        if ($track && $links && $mid > 0) {
            foreach ($links as $idx => $url) {
                DB::run("INSERT INTO " . DB::t('campaign_links') . " (campaign_id,message_id,idx,url) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE url=VALUES(url)", [$campId, $mid, $idx, $url]);
            }
            DB::run("UPDATE " . DB::t('campaign_messages') . " SET body=? WHERE id=?", [fr_campaign_track_finalize($trackedBody, $mid), $mid]);
        }
        return true;
    } catch (Throwable $e) { return false; }   // duplicate (از اجرای قبلی مانده) و…
}

/** ارتقای زمان‌بندی‌ها: کمپین scheduled که موعدش رسیده به صف می‌رود */
function fr_campaign_promote_scheduled(): int
{
    $n = (int)DB::run("UPDATE " . DB::t('campaigns') . " SET status='queued'
                       WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()")->rowCount();
    /* کمپین‌های scheduled بدون موعد آینده باید قابل اجرا باشند اگر صف دارند */
    return $n;
}

/** پردازش دسته‌ای صف — از کرون یا دکمهٔ «ارسال/ادامه» در پیشخوان (۲٫۷: batch/delay فی‌کمپین) */
function fr_campaign_work(int $limit = 30): int
{
    $worked = 0;
    fr_campaign_promote_scheduled();
    $camps = DB::all("SELECT id,batch_size,delay_ms FROM " . DB::t('campaigns') . " WHERE status IN ('queued','sending') ORDER BY id LIMIT 3");
    foreach ($camps as $cp) {
        if ($worked >= $limit) break;
        $cid = (int)$cp['id'];
        $batchCap = max(1, min(500, (int)($cp['batch_size'] ?? 15)));
        $delayMs  = max(0, min(5000, (int)($cp['delay_ms'] ?? 150)));
        $take = min($batchCap, $limit - $worked);
        DB::update('campaigns', ['status' => 'sending'], 'id=? AND status=?', [$cid, 'queued']);
        $rows = DB::all("SELECT * FROM " . DB::t('campaign_messages') . " WHERE campaign_id=? AND status='pending' ORDER BY id LIMIT " . (int)$take, [$cid]);
        foreach ($rows as $m) {
            $worked++;
            $ok = false; $err = ''; $ref = null;
            try {
                if ($m['channel'] === 'sms') {
                    require_once __DIR__ . '/../core/Kavenegar.php';
                    [$ok, $ref] = (new Kavenegar())->sendDetailed((string)$m['mobile'], (string)$m['body'], 'campaign_' . $cid);
                    if (!$ok) $err = 'sms_failed_or_disabled';
                } else {
                    [$enabled, $send] = fr_snotify_driver((string)$m['channel']);
                    if ($enabled) [$ok] = $send((string)$m['chat_id'], (string)$m['body']);
                    else $err = 'bot_not_configured';
                }
            } catch (Throwable $e) { $err = mb_substr($e->getMessage(), 0, 280); }
            DB::run("UPDATE " . DB::t('campaign_messages') . " SET status=?, error=?, msg_ref=COALESCE(?,msg_ref), sent_at=" . ($ok ? "NOW()" : "NULL") . " WHERE id=?",
                    [$ok ? 'sent' : 'failed', $ok ? null : $err, $ref, (int)$m['id']]);
            if ($m['user_id'] && in_array($m['channel'], ['telegram','bale'], true)) {
                /* پیامک‌ها در sms_logs ثبت می‌شوند؛ این‌جا فقط پیام‌رسان‌ها */
                fr_snotify_log((int)$m['user_id'], (string)$m['channel'], 'campaign', mb_substr((string)$m['body'], 0, 900), (bool)$ok, $ok ? '' : $err);
            }
            if ($delayMs > 0) usleep($delayMs * 1000);
        }
        /* تجمیع دقیق شمارنده‌ها */
        $agg = DB::one("SELECT SUM(status='sent') AS s, SUM(status='failed') AS f, SUM(status='pending') AS p
                        FROM " . DB::t('campaign_messages') . " WHERE campaign_id=?", [$cid]);
        DB::run("UPDATE " . DB::t('campaigns') . " SET sent=?, failed=?, status=?, finished_at=? WHERE id=?",
                [(int)$agg['s'], (int)$agg['f'], (int)$agg['p'] === 0 ? 'done' : 'sending',
                (int)$agg['p'] === 0 ? date('Y-m-d H:i:s') : null, $cid]);
    }
    return $worked;
}

/**
 * ۲٫۷ — همگام‌سازی وضعیت تحویل پیامک‌های کمپین با کاوه‌نگار (متد status).
 * از کرون (یا دکمهٔ تازه‌سازی در گزارش) اجرا می‌شود؛ فقط پیامک‌های ارسال‌شدهٔ
 * بدون تحویل‌ثبت‌شدهٔ ۲۴ ساعت اخیر.
 */
function fr_campaign_sms_sync(?int $campaignId = null, int $limit = 200): int
{
    $w = "channel='sms' AND status='sent' AND delivered_at IS NULL AND msg_ref IS NOT NULL AND msg_ref<>'' AND sent_at >= NOW() - INTERVAL 3 DAY";
    $p = [];
    if ($campaignId) { $w .= " AND campaign_id=?"; $p[] = $campaignId; }
    $rows = DB::all("SELECT id,msg_ref FROM " . DB::t('campaign_messages') . " WHERE $w ORDER BY id DESC LIMIT " . (int)$limit, $p);
    if (!$rows) return 0;
    require_once __DIR__ . '/../core/Kavenegar.php';
    $kv = new Kavenegar();
    $map = $kv->deliveryStatus(array_column($rows, 'msg_ref'));
    $upd = 0;
    foreach ($rows as $r) {
        $st = $map[(string)$r['msg_ref']] ?? null;
        if (!$st) continue;
        if (Kavenegar::isDelivered((int)$st['status'])) {
            DB::run("UPDATE " . DB::t('campaign_messages') . " SET delivered_at=?, error=NULL WHERE id=?", [date('Y-m-d H:i:s', (int)$st['date'] ?: time()), (int)$r['id']]);
            $upd++;
        } elseif (Kavenegar::isFailedFinal((int)$st['status'])) {
            DB::run("UPDATE " . DB::t('campaign_messages') . " SET error=? WHERE id=?", ['delivery: ' . $st['statustext'], (int)$r['id']]);
            $upd++;
        }
    }
    return $upd;
}

/** ۲٫۷ — گزارش جامع یک کمپین برای صفحهٔ آنالیتیکس */
function fr_campaign_analytics(int $id): array
{
    $m = DB::t('campaign_messages');
    $agg = DB::one("SELECT COUNT(*) AS total,
        SUM(status='pending') AS pending, SUM(status='sent') AS sent, SUM(status='failed') AS failed,
        SUM(status='skipped') AS skipped,
        SUM(status='sent' AND delivered_at IS NOT NULL) AS delivered,
        SUM(clicked_at IS NOT NULL) AS clicked,
        SUM(replied_at IS NOT NULL) AS replied
        FROM $m WHERE campaign_id=?", [$id]) ?: [];
    $byChannel = DB::all("SELECT channel, COUNT(*) AS total,
        SUM(status='sent') AS sent, SUM(status='failed') AS failed, SUM(status='pending') AS pending,
        SUM(status='sent' AND delivered_at IS NOT NULL) AS delivered,
        SUM(clicked_at IS NOT NULL) AS clicked,
        SUM(replied_at IS NOT NULL) AS replied
        FROM $m WHERE campaign_id=? GROUP BY channel", [$id]);
    $topLinks = DB::all("SELECT url, SUM(clicks) AS clicks, COUNT(*) AS msgs
                         FROM " . DB::t('campaign_links') . " WHERE campaign_id=?
                         GROUP BY url ORDER BY clicks DESC, id LIMIT 10", [$id]);
    $daily = DB::all("SELECT DATE(sent_at) AS d, COUNT(*) AS n FROM $m
                      WHERE campaign_id=? AND status='sent' AND sent_at >= NOW() - INTERVAL 14 DAY
                      GROUP BY DATE(sent_at) ORDER BY d", [$id]);
    $clicksDaily = DB::all("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM " . DB::t('link_clicks') . "
                            WHERE campaign_id=? AND created_at >= NOW() - INTERVAL 14 DAY GROUP BY DATE(created_at) ORDER BY d", [$id]);
    $totalClicks = (int)DB::val("SELECT COALESCE(SUM(clicks),0) FROM " . DB::t('campaign_links') . " WHERE campaign_id=?", [$id]);
    $funnelOrder = ['total','pending','sent','delivered','clicked','replied','failed'];
    $out = [];
    foreach ($agg as $k => $v) $out[$k] = (int)$v;
    $out['ctr'] = $out['sent'] > 0 ? round($out['clicked'] / $out['sent'] * 100, 1) : 0.0;
    $out['delivery_rate'] = (int)DB::val("SELECT COUNT(*) FROM $m WHERE campaign_id=? AND channel='sms' AND status='sent'", [$id]) > 0
        ? round($out['delivered'] / max(1, (int)DB::val("SELECT COUNT(*) FROM $m WHERE campaign_id=? AND channel='sms' AND status='sent'", [$id])) * 100, 1) : 0.0;
    $out['by_channel'] = $byChannel;
    $out['top_links'] = $topLinks;
    $out['total_clicks'] = $totalClicks;
    $out['daily'] = $daily;
    $out['clicks_daily'] = $clicksDaily;
    return $out;
}

/** آمار یک‌نگاه داشبورد کمپین‌ها */
function fr_campaign_stats(): array
{
    $r = DB::one("SELECT COUNT(*) AS campaigns, COALESCE(SUM(sent),0) AS sent, COALESCE(SUM(failed),0) AS failed,
                  COALESCE(SUM(total),0) AS total, SUM(status IN ('queued','sending')) AS active FROM " . DB::t('campaigns'));
    $m = DB::one("SELECT SUM(status='pending') AS pending FROM " . DB::t('campaign_messages'));
    /* ۲٫۷: شاخص‌های تعامل روی همهٔ کمپین‌ها */
    $x = DB::one("SELECT SUM(clicked_at IS NOT NULL) AS clicked,
                  SUM(status='sent' AND delivered_at IS NOT NULL) AS delivered,
                  SUM(replied_at IS NOT NULL) AS replied FROM " . DB::t('campaign_messages'));
    return ['campaigns' => (int)$r['campaigns'], 'sent' => (int)$r['sent'], 'failed' => (int)$r['failed'],
            'total' => (int)$r['total'], 'active' => (int)$r['active'], 'pending' => (int)($m['pending'] ?? 0),
            'clicked' => (int)($x['clicked'] ?? 0), 'delivered' => (int)($x['delivered'] ?? 0), 'replied' => (int)($x['replied'] ?? 0)];
}
