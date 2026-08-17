<?php
/**
 * کرون‌جاب اطلاع‌رسانی شبکه‌های اجتماعی — نسخهٔ ۲٫۳  (توصیه‌شده: هر ۵ دقیقه)
 * کارها:
 *   ۱) getUpdates تلگرام/بله می‌خواند تا «نام کاربری» کاربران به‌صورت خودکار به چت‌آیدی عددی تبدیل و متصل شود؛
 *   ۲) نوبت‌های اطلاع‌رسانی روزانه (تعداد و ساعت ۲۴ساعته قابل‌تنظیم از پیشخوان) را سر وقت پخش می‌کند.
 * اجرا:
 *   وب:  https://site.com/cron/notify.php?key=<cron_secret>   (پارامتر force=1 برای تست دستی نوبت‌ها)
 *   CLI: php -q /home/USER/public_html/cron/notify.php <cron_secret>
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/planner.php';
require_once FR_ROOT . '/includes/contacts.php';   // ← نرمال‌سازی موبایل برای «اشتراک شماره» در getUpdates
require_once FR_ROOT . '/includes/snotify.php';

/* کرون مستقل از فراخوانی وب اجرا می‌شود — منطقهٔ زمانی سایت را صریح تنظیم می‌کنیم تا
   نوبت‌های ساعت‌دار دقیقاً سر وقت محلی اجرا شوند */
date_default_timezone_set('Asia/Tehran');

try { DB::pdo(); } catch (Throwable $e) { http_response_code(500); echo 'db_error'; exit; }
if (function_exists('fr_update_pipeline')) fr_update_pipeline();

header('Content-Type: text/plain; charset=utf-8');

/* احراز با کلید مخفی کرون — از وب یا از خط فرمان */
$secret = (string)DB::setting('cron_secret', '');
$key    = PHP_SAPI === 'cli' ? (string)($argv[1] ?? '') : (string)($_GET['key'] ?? '');
if ($secret === '' || !hash_equals($secret, $key)) { http_response_code(403); echo 'forbidden'; exit; }

$force = PHP_SAPI === 'cli' ? in_array('--force', $argv ?? [], true) : !empty($_GET['force']);

/* ۱) اتصال خودکار نام‌های کاربری (getUpdates) */
$poll = fr_snotify_poll_updates();
foreach ($poll as $ch => $st) {
    echo "poll[$ch]: updates={$st['updates']},bound={$st['bound']}" . ($st['err'] ? ",err={$st['err']}" : '') . "\n";
}

/* ۲) نوبت‌های امروز */
$onlyPoll = PHP_SAPI === 'cli' ? in_array('--poll-only', $argv ?? [], true) : !empty($_GET['poll_only']);
if (!$onlyPoll) {
    foreach (fr_snotify_run_due($force) as $line) echo "slot: $line\n";
}

/* ۳) ۲٫۵ — ورکر صف کمپین‌های تبلیغاتی: در هر اجرای کرون (۵ دقیقه) یک دستهٔ کوچک ارسال می‌شود */
try {
    require_once FR_ROOT . '/includes/contacts.php';
    require_once FR_ROOT . '/includes/campaigns.php';
    fr_campaign_promote_scheduled();
    $pendingCamp = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('campaigns') . " WHERE status IN ('queued','sending')");
    if ($pendingCamp > 0) {
        $worked = fr_campaign_work(30);
        echo "campaigns: worked=$worked\n";
    } else {
        echo "campaigns: idle\n";
    }
    /* ۲٫۷ — همگام‌سازی وضعیت تحویل پیامک‌ها از کاوه‌نگار (برای آمار دقیق کمپین) */
    $synced = fr_campaign_sms_sync();
    if ($synced > 0) echo "sms-sync: $synced\n";
} catch (Throwable $e) { error_log('feria: cron campaigns: ' . $e->getMessage()); echo "campaigns: error\n"; }

echo "done\n";
