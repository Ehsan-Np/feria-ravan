<?php
/**
 * کرون‌جاب یادآور برنامهٔ روزانه — نسخهٔ ۲٫۴ (چندنوبتی، قالب ۲۴ ساعته)
 * ─ فراخوانی هر ۵ دقیقه توسط کرون هاست:
 *   GET /cron/reminders.php?key=<cron_secret>
 * ─ نوبت‌ها (تعداد ۱ تا ۶ بار در روز + ساعت هر نوبت) از پیشخوان قابل تنظیم است
 *   (planner_remind_count + planner_remind_times)؛ هر نوبت فقط در بازهٔ
 *   «۱۵ دقیقه قبل تا ۵۹ دقیقه بعد» از ساعتش ارسال می‌شود و قفل روزانهٔ مخصوص خودش را دارد؛
 *   با پارامتر force=1 (همراه کلید) همهٔ نوبت‌های امروز برای تست اجباری اجرا می‌شوند.
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/planner.php';
require_once FR_ROOT . '/core/Telegram.php';
require_once FR_ROOT . '/core/Bale.php';

/* کرون مستقل از فراخوانی وب اجرا می‌شود — منطقهٔ زمانی سایت را صریح تنظیم می‌کنیم تا
   نوبت‌های ساعت‌دار دقیقاً سر وقت محلی اجرا شوند */
date_default_timezone_set('Asia/Tehran');

try { DB::pdo(); } catch (Throwable $e) { http_response_code(500); echo 'db_error'; exit; }
if (function_exists('fr_update_pipeline')) fr_update_pipeline();

header('Content-Type: text/plain; charset=utf-8');

/* احراز با کلید مخفی کرون */
$secret = (string)DB::setting('cron_secret', '');
$key    = PHP_SAPI === 'cli' ? (string)($argv[1] ?? '') : (string)($_GET['key'] ?? '');
if ($secret === '' || !hash_equals($secret, $key)) { http_response_code(403); echo 'forbidden'; exit; }

/* ۲٫۵: یادآوری‌های رویداد (مشاوره/کارگاه/تست) مستقل از برنامه‌ریز روزانه است؛
   بنابراین غیرفعال بودن پلنر فقط بخش نوبت‌ها را می‌خواباند، نه کل کرون را */
$plannerOn = fr_planner_enabled();

/* ۲٫۴: نوبت‌های یادآوری روز (۱ تا ۶ نوبت، قالب ۲۴ ساعته) */
$force  = PHP_SAPI === 'cli' ? in_array('--force', $argv ?? [], true) : !empty($_GET['force']);
$today  = date('Y-m-d');
$nowMin = (int)date('H') * 60 + (int)date('i');
$slots  = fr_planner_remind_slots();
$lines  = [];
$sentT = 0; $failedT = 0;

if (!$plannerOn) { $lines[] = 'planner_disabled'; }

if ($plannerOn) {
/* کاربرانی که پیام‌رسان متصل دارند و امروز ≥۱ کارِ انجام‌نشده دارند */
$users = DB::all(
    "SELECT u.id, u.first_name, u.telegram_chat_id, u.bale_chat_id, u.rubika_chat_id,
            (SELECT COUNT(*) FROM " . DB::t('planner_tasks') . " t WHERE t.user_id=u.id AND t.day=? AND t.done=0) AS pending
     FROM " . DB::t('users') . " u
     WHERE u.status='active'
       AND (COALESCE(u.telegram_chat_id,'') <> '' OR COALESCE(u.bale_chat_id,'') <> '' OR COALESCE(u.rubika_chat_id,'') <> '')
       AND EXISTS (SELECT 1 FROM " . DB::t('planner_tasks') . " t2 WHERE t2.user_id=u.id AND t2.day=? AND t2.done=0)",
    [$today, $today]
);

try { require_once FR_ROOT . '/includes/snotify.php'; } catch (Throwable $e) { /* بی‌صدا */ }
foreach ($slots as $idx => $hhmm) {
    [$sh, $sm] = array_map('intval', explode(':', $hhmm));
    $slotMin = $sh * 60 + $sm;
    $lockKey = 'planner_reminded_' . date('Ymd') . '_' . $idx;
    if (!$force && DB::setting($lockKey, '0') === '1') { $lines[] = $hhmm . ':already'; continue; }
    if (!$force && $nowMin < $slotMin) { $lines[] = $hhmm . ':wait'; continue; }
    $sent = 0; $failed = 0;
    foreach ($users as $usr) {
        $text = fr_planner_remind_compose($usr, $idx, $hhmm, $today);
        $okAny = false;
        if (trim((string)$usr['telegram_chat_id']) !== '') {
            [$ok] = Telegram::sendMessage((string)$usr['telegram_chat_id'], $text);
            $okAny = $okAny || $ok;
            if (function_exists('fr_snotify_log')) { try { fr_snotify_log((int)$usr['id'], 'telegram', 'planner', $text, (bool)$ok); } catch (Throwable $e) { /* بی‌صدا */ } }
        }
        if (trim((string)$usr['bale_chat_id']) !== '') {
            [$ok] = Bale::sendMessage((string)$usr['bale_chat_id'], $text);
            $okAny = $okAny || $ok;
            if (function_exists('fr_snotify_log')) { try { fr_snotify_log((int)$usr['id'], 'bale', 'planner', $text, (bool)$ok); } catch (Throwable $e) { /* بی‌صدا */ } }
        }
        if (trim((string)($usr['rubika_chat_id'] ?? '')) !== '') {
            require_once FR_ROOT . '/core/Rubika.php';
            [$ok] = Rubika::sendMessage((string)$usr['rubika_chat_id'], $text);
            $okAny = $okAny || $ok;
            if (function_exists('fr_snotify_log')) { try { fr_snotify_log((int)$usr['id'], 'rubika', 'planner', $text, (bool)$ok); } catch (Throwable $e) { /* بی‌صدا */ } }
        }
        $okAny ? $sent++ : $failed++;
    }
    try { DB::setSetting($lockKey, '1'); } catch (Throwable $e) { /* بی‌صدا */ }
    $sentT += $sent; $failedT += $failed;
    $lines[] = $hhmm . ':sent=' . $sent . ',failed=' . $failed;
}

try { log_activity('cron_planner_remind', 'system', 0, ['slots' => count($slots), 'sent' => $sentT, 'failed' => $failedT]); } catch (Throwable $e) { /* بی‌صدا */ }

/* ══ ۲٫۷ — یادآورهای فردیِ هر کار (remind_at) از کانال‌های منتخب مدیر ══ */
try {
    $pn = fr_planner_remind_due();
    if ($pn > 0) $lines[] = 'tasks:sent=' . $pn;
} catch (Throwable $e) { error_log('feria: cron planner task remind: ' . $e->getMessage()); $lines[] = 'tasks:error'; }
} /* if ($plannerOn) */

/* ════════════════════════════════════════════════════════════════════════════
   ۲٫۵ — یادآوری‌های اجتماعی رویدادها (جدا از پیامک): جلسهٔ مشاوره، کارگاه/دوره، تست ناتمام
   هر مورد فقط یک‌بار، در بازهٔ زمانی هدفش، از تلگرام/بله فرستاده و ثبت می‌شود.
 * ══════════════════════════════════════════════════════════════════════════ */
$evSent = 0; $evFailed = 0;
if (DB::setting('social_event_remind', '1') === '1') {
    $sendEv = function (array $uRow, string $text, string $tag): bool {
        $any = false;
        foreach (['telegram' => 'telegram_chat_id', 'bale' => 'bale_chat_id', 'rubika' => 'rubika_chat_id'] as $ch => $col) {
            $cid = trim((string)($uRow[$col] ?? ''));
            if ($cid === '') continue;
            $any = true;
            [$enabled, $send] = fr_snotify_driver($ch);
            if (!$enabled) continue;
            [$ok, $resp] = $send($cid, $text);
            if (function_exists('fr_snotify_log')) { try { fr_snotify_log((int)$uRow['uid'], $ch, 'event', mb_substr($text, 0, 900), (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : '')); } catch (Throwable $e) { } }
            if ($ok) return true;
        }
        return false;
    };
    $prefOk = "COALESCE(u.notif_prefs,'') NOT LIKE '%\"remind\":0%'";

    /* ۱) جلسات مشاوره — consult_social_hours ساعت قبل */
    $ch = max(1, min(168, (int)DB::setting('consult_social_hours', '24')));
    foreach (DB::all("SELECT a.id, u.id AS uid, u.first_name, u.telegram_chat_id, u.bale_chat_id, sv.title AS service, s.starts_at, a.mode
                      FROM " . DB::t('consult_appointments') . " a
                      JOIN " . DB::t('consult_slots') . " s ON s.id=a.slot_id
                      JOIN " . DB::t('consult_services') . " sv ON sv.id=a.service_id
                      JOIN " . DB::t('users') . " u ON u.id=a.user_id
                      WHERE a.status='booked' AND a.social_reminded_at IS NULL AND $prefOk
                        AND s.starts_at BETWEEN NOW() + INTERVAL " . ($ch * 60 - 30) . " MINUTE AND NOW() + INTERVAL " . ($ch * 60 + 30) . " MINUTE
                      LIMIT 100") as $r) {
        $modeFa = $r['mode'] === 'online' ? 'آنلاین 🎥' : 'حضوری 📍';
        $txt = "⏰ یادآوری جلسهٔ مشاوره — " . fa_num($ch) . " ساعت مانده!\n"
             . "🗓 " . fa_num(jdate('l j F Y — H:i', strtotime($r['starts_at']))) . "\n"
             . "🛋 خدمت: " . $r['service'] . "\n"
             . "حالت: " . $modeFa . "\n"
             . "اگر لازم شد جابه‌جا کنی، از پنل کاربری زمان را تغییر بده.\n— " . (string)DB::setting('site_title', 'فریا روان');
        $okAny = $sendEv($r, $txt, 'consult');
        $okAny ? $evSent++ : $evFailed++;
        DB::run("UPDATE " . DB::t('consult_appointments') . " SET social_reminded_at=NOW() WHERE id=?", [(int)$r['id']]);
    }

    /* ۲) کارگاه‌ها و دوره‌ها — event_social_hours ساعت قبل از شروع */
    $eh = max(1, min(240, (int)DB::setting('event_social_hours', '24')));
    foreach (DB::all("SELECT r.id, u.id AS uid, u.first_name, u.telegram_chat_id, u.bale_chat_id, e.title, e.kind, e.slug, e.location, e.starts_at
                      FROM " . DB::t('event_registrations') . " r
                      JOIN " . DB::t('events') . " e ON e.id=r.event_id
                      JOIN " . DB::t('users') . " u ON u.id=r.user_id
                      WHERE r.status='paid' AND r.social_reminded_at IS NULL AND e.starts_at IS NOT NULL AND $prefOk
                        AND e.starts_at BETWEEN NOW() + INTERVAL " . ($eh * 60 - 30) . " MINUTE AND NOW() + INTERVAL " . ($eh * 60 + 30) . " MINUTE
                      LIMIT 200") as $r) {
        $kindFa = $r['kind'] === 'course' ? 'دوره' : 'کارگاه';
        $url = rtrim(SITE_URL, '/') . '/' . ($r['kind'] === 'course' ? 'course.php?s=' : 'workshop.php?s=') . $r['slug'];
        $txt = "🎪 یادآوری " . $kindFa . " — فقط " . fa_num($eh) . " ساعت مانده!\n"
             . "«" . $r['title'] . "»\n"
             . "🗓 " . fa_num(jdate('l j F Y — H:i', strtotime($r['starts_at'])))
             . (trim((string)$r['location']) !== '' ? "\n📍 " . $r['location'] : "")
             . "\n🔗 جزئیات: " . $url . "\n— " . (string)DB::setting('site_title', 'فریا روان');
        $okAny = $sendEv($r, $txt, 'event');
        $okAny ? $evSent++ : $evFailed++;
        DB::run("UPDATE " . DB::t('event_registrations') . " SET social_reminded_at=NOW() WHERE id=?", [(int)$r['id']]);
    }

    /* ۳) تست‌های ناتمام — پس از test_nudge_days روز (یک‌بار برای هر تلاش) */
    $nd = max(1, min(30, (int)DB::setting('test_nudge_days', '2')));
    foreach (DB::all("SELECT a.id, a.progress, u.id AS uid, u.first_name, u.telegram_chat_id, u.bale_chat_id, t.title, t.slug
                      FROM " . DB::t('attempts') . " a
                      JOIN " . DB::t('tests') . " t ON t.id=a.test_id
                      JOIN " . DB::t('users') . " u ON u.id=a.user_id
                      WHERE a.status='in_progress' AND a.nudge_sent=0 AND $prefOk
                        AND a.started_at <= NOW() - INTERVAL " . $nd . " DAY
                      ORDER BY a.id LIMIT 50") as $r) {
        $url = rtrim(SITE_URL, '/') . '/test-page.php?s=' . $r['slug'];
        $txt = "📝 " . ($r['first_name'] !== '' ? $r['first_name'] . ' عزیز،' : '') . " تست «" . $r['title'] . "» را ناتمام گذاشتی"
             . ((int)$r['progress'] > 0 ? " — " . fa_num((int)$r['progress']) . " پاسخ ثبت شده و ذخیرهٔ تو امن است" : "") . " 😊\n"
             . "چند دقیقه وقت بگذار و نتیجهٔ شخصی‌ات را ببین:\n🔗 " . $url . "\n— " . (string)DB::setting('site_title', 'فریا روان');
        $okAny = $sendEv($r, $txt, 'test');
        $okAny ? $evSent++ : $evFailed++;
        DB::run("UPDATE " . DB::t('attempts') . " SET nudge_sent=1 WHERE id=?", [(int)$r['id']]);
    }
    if ($evSent + $evFailed > 0) $lines[] = 'events:sent=' . $evSent . ',failed=' . $evFailed;
    try { log_activity('cron_event_remind', 'system', 0, ['sent' => $evSent, 'failed' => $evFailed]); } catch (Throwable $e) { }
}

/* ════════════════════════════════════════════════════════════════════════════
   ۳٫۰ — یادآور خودکار تصفیهٔ حساب پیش‌پرداخت
   «نهایتاً ۳ روز مانده به برگزاری»: به ثبت‌نام‌های بیعانه‌شدهٔ رویداد و نوبت‌های
   مشاورهٔ پیش‌پرداخت‌شده پیامک می‌رود: مبلغ مانده + لینک اختصاصی پرداخت + راهنمای
   تسویه از پنل کاربری. هر رکورد فقط یک‌بار (settle_reminded_at).
 * ══════════════════════════════════════════════════════════════════════════ */
$stSent = 0; $stFailed = 0;
if (DB::setting('settle_remind_on', '1') === '1') {
    try {
        require_once FR_ROOT . '/includes/deposit.php';
        require_once FR_ROOT . '/core/Kavenegar.php';
        $dh = max(1, min(720, (int)DB::setting('deposit_remind_hours', '72')));
        $kvSt = new Kavenegar();

        $sendSettle = function (array $row, string $text) use ($kvSt): bool {
            $okAny = false;
            /* پیامک به موبایل کاربر (کانال اصلیِ پیوند پرداخت) */
            $mob = en_num((string)($row['umobile'] ?? ''));
            if (valid_mobile($mob)) {
                try { $okAny = $kvSt->send($mob, $text, 'settle_remind') || $okAny; } catch (Throwable $e) { }
            }
            /* افزوده: کانال‌های اجتماعی متصل اگر فعال‌اند */
            try { require_once FR_ROOT . '/includes/snotify.php'; } catch (Throwable $e) { }
            foreach (['telegram' => 'telegram_chat_id', 'bale' => 'bale_chat_id'] as $ch => $col) {
                $cid = trim((string)($row[$col] ?? ''));
                if ($cid === '' || !function_exists('fr_snotify_driver')) continue;
                try {
                    [$enabled, $send] = fr_snotify_driver($ch);
                    if (!$enabled) continue;
                    [$ok, $resp] = $send($cid, $text);
                    if (function_exists('fr_snotify_log')) { try { fr_snotify_log((int)$row['user_id'], $ch, 'event', mb_substr($text, 0, 900), (bool)$ok, $ok ? '' : (is_string($resp) ? $resp : '')); } catch (Throwable $e2) { } }
                    $okAny = $okAny || (bool)$ok;
                } catch (Throwable $e) { }
            }
            return $okAny;
        };
        $tplRemind = trim((string)DB::setting('sms_tpl_settle_remind', ''));
        if ($tplRemind === '') {
            $tplRemind = "{name} عزیز، تنها {days} روز به برگزاری «{item}» ({when}) مانده است. لطفاً حتماً نسبت به تصفیهٔ حساب اقدام کنید و مابقی مبلغ ({remain} تومان) را پرداخت نمایید.\nلینک پرداخت: {link}\nهمچنین می‌توانید وارد پنل کاربری خود شوید و از بخش «{panel}» پرداخت را تکمیل کنید.\n{site}";
        }

        /* ۱) ثبت‌نام‌های کارگاه/دوره با پرداخت بیعانه */
        foreach (fr_settle_due_registrations($dh) as $r) {
            $remain = fr_reg_remaining($r);
            if ($remain <= 0) { DB::run("UPDATE " . DB::t('event_registrations') . " SET settle_reminded_at=NOW() WHERE id=?", [(int)$r['id']]); continue; }
            $days  = max(0, (int)ceil((strtotime((string)$r['starts_at']) - time()) / 86400));
            $kindFa = $r['kind'] === 'course' ? 'دوره' : 'کارگاه';
            $txt = fr_deposit_sms_render($tplRemind, [
                'name'   => trim((string)($r['first_name'] ?? '')) !== '' ? (string)$r['first_name'] : 'کاربر',
                'item'   => (string)$r['title'],
                'thing'  => 'ثبت‌نام شما در ' . $kindFa,
                'remain' => number_format($remain),
                'paid'   => number_format((int)$r['paid_total']),
                'total'  => number_format($remain + (int)$r['paid_total']),
                'link'   => fr_settle_url_reg($r),
                'panel'  => $r['kind'] === 'course' ? 'دوره‌های من' : 'کارگاه‌های من',
                'when'   => fa_num(jdate('j F Y ساعت H:i', strtotime((string)$r['starts_at']))),
                'days'   => fa_num($days),
            ]);
            $okAny = $sendSettle($r, $txt);
            $okAny ? $stSent++ : $stFailed++;
            DB::run("UPDATE " . DB::t('event_registrations') . " SET settle_reminded_at=NOW() WHERE id=?", [(int)$r['id']]);
            log_activity('settle_remind', 'event', (int)$r['event_id'], ['reg' => (int)$r['id'], 'remain' => $remain, 'sent' => $okAny]);
        }

        /* ۲) نوبت‌های مشاورهٔ پیش‌پرداخت‌شده با ماندهٔ باز */
        foreach (fr_settle_due_appointments($dh) as $a) {
            $remain = max(0, (int)$a['ototal'] - (int)$a['paid_total']);
            if ($remain <= 0) { DB::run("UPDATE " . DB::t('consult_appointments') . " SET settle_reminded_at=NOW() WHERE id=?", [(int)$a['id']]); continue; }
            $days = max(0, (int)ceil((strtotime((string)$a['starts_at']) - time()) / 86400));
            $txt = fr_deposit_sms_render($tplRemind, [
                'name'   => trim((string)($a['first_name'] ?? '')) !== '' ? (string)$a['first_name'] : 'مراجع',
                'item'   => (string)$a['title'],
                'thing'  => 'نوبت شما در جلسهٔ مشاوره',
                'remain' => number_format($remain),
                'paid'   => number_format((int)$a['paid_total']),
                'total'  => number_format((int)$a['ototal']),
                'link'   => fr_settle_url_appt($a),
                'panel'  => 'نوبت‌های مشاورهٔ من',
                'when'   => fa_num(jdate('j F Y ساعت H:i', strtotime((string)$a['starts_at']))),
                'days'   => fa_num($days),
            ]);
            $okAny = $sendSettle($a, $txt);
            $okAny ? $stSent++ : $stFailed++;
            DB::run("UPDATE " . DB::t('consult_appointments') . " SET settle_reminded_at=NOW() WHERE id=?", [(int)$a['id']]);
            log_activity('settle_remind', 'consult_appointment', (int)$a['id'], ['remain' => $remain, 'sent' => $okAny]);
        }
        if ($stSent + $stFailed > 0) $lines[] = 'settle:sent=' . $stSent . ',failed=' . $stFailed;
        try { log_activity('cron_settle_remind', 'system', 0, ['sent' => $stSent, 'failed' => $stFailed, 'hours' => $dh]); } catch (Throwable $e) { }
    } catch (Throwable $e) {
        error_log('feria: cron settle remind: ' . $e->getMessage());
        $lines[] = 'settle:error';
    }
} else { $lines[] = 'settle:disabled'; }
echo implode("\n", $lines);
