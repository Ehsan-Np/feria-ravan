<?php
/**
 * ============================================================================
 *  رهگیری کلیک کمپین‌ها (نسخهٔ ۲٫۷) — go.php?m=<message_id>&i=<link_idx>&s=<sig>
 *  ─ هر لینکِ کمپین هنگام صف‌سازی به این نشانی بازنویسی می‌شود؛ این‌جا کلیک ثبت
 *    (link_clicks + شمارندهٔ campaign_links + first_clicked_at پیام) و کاربر
 *    فوراً (302) به مقصد واقعی هدایت می‌شود.
 *  ─ امضای HMAC جلوی جعل شمارنده‌ها را می‌گیرد.
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$mid = (int)($_GET['m'] ?? 0);
$idx = (int)($_GET['i'] ?? 0);
$sig = (string)($_GET['s'] ?? '');
$bot = trim((string)($_GET['bot'] ?? ''));

$home = rtrim(SITE_URL, '/') . '/';

// هدایت کوتاه اتصال سریع ربات
if ($bot !== '') {
    header('Location: ' . rtrim(SITE_URL, '/') . '/bot-connect.php?token=' . urlencode($bot), true, 302);
    exit;
}

if ($mid <= 0 || $sig === '') { header('Location: ' . $home, true, 302); exit; }

try {
    require_once __DIR__ . '/includes/campaigns.php';
    if (!hash_equals(fr_campaign_link_sig($mid, $idx), $sig)) {
        header('Location: ' . $home, true, 302); exit;
    }
    $link = DB::one("SELECT l.*, m.status AS mstatus FROM " . DB::t('campaign_links') . " l
                     LEFT JOIN " . DB::t('campaign_messages') . " m ON m.id=l.message_id
                     WHERE l.message_id=? AND l.idx=? LIMIT 1", [$mid, $idx]);
    if (!$link || !preg_match('#^https?://#i', (string)$link['url'])) {
        header('Location: ' . $home, true, 302); exit;
    }
    $target = (string)$link['url'];
    /* ثبت کلیک — هر کلیک یک ردیف؛ شمارندهٔ تجمیعی هم آپدیت می‌شود (idempotent نسبت به رفرش نیست —
       رفتار استاندارد رهگیری ایمیل مارکتینگ است) */
    DB::insert('link_clicks', [
        'link_id'     => (int)$link['id'],
        'campaign_id' => (int)$link['campaign_id'],
        'message_id'  => $mid,
        'ip'          => function_exists('client_ip_bin') ? client_ip_bin() : null,
        'ua'          => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    DB::run("UPDATE " . DB::t('campaign_links') . " SET clicks=clicks+1,
             first_click_at=COALESCE(first_click_at,NOW()), last_click_at=NOW() WHERE id=?", [(int)$link['id']]);
    DB::run("UPDATE " . DB::t('campaign_messages') . " SET clicked_at=COALESCE(clicked_at,NOW()) WHERE id=?", [$mid]);
    header('Location: ' . $target, true, 302);
    exit;
} catch (Throwable $e) {
    header('Location: ' . $home, true, 302); exit;
}
