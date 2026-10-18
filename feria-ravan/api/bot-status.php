<?php
/**
 * فریا روان — استعلام وضعیت اتصال ربات اطلاع‌رسانی
 * بررسی بلادرنگ (Real-Time Polling) فعال‌سازی تلگرام، بله و روبیکا
 * نسخه ۴٫۹٫۴
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/snotify.php';

fr_bootstrap();
header('Content-Type: application/json; charset=utf-8');

$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$doPoll = !empty($_GET['poll']) || !empty($_POST['poll']);

$userId = 0;
$tokenRow = null;

if ($token !== '') {
    $tokenRow = DB::one("SELECT * FROM " . DB::t('bot_connect_tokens') . " WHERE token=? LIMIT 1", [$token]);
    if ($tokenRow && !empty($tokenRow['user_id'])) {
        $userId = (int)$tokenRow['user_id'];
    }
}

if (!$userId) {
    $cur = current_user();
    if ($cur) {
        $userId = (int)$cur['id'];
    }
}

if (!$userId) {
    echo json_encode([
        'ok'        => false,
        'connected' => false,
        'message'   => 'توکن نامعتبر است یا کاربر یافت نشد.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// در صورت درخواست، دریافت پینگ‌های جدید پیام‌رسان‌ها به صورت سریع
if ($doPoll) {
    try {
        fr_snotify_poll_updates();
    } catch (\Throwable $e) {}
}

$u = DB::one("SELECT id, first_name, last_name, mobile, telegram_chat_id, tg_username, bale_chat_id, bale_username, rubika_chat_id, rubika_username FROM " . DB::t('users') . " WHERE id=?", [$userId]);
if (!$u) {
    echo json_encode(['ok' => false, 'connected' => false, 'message' => 'کاربر یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// وضعیت هر کانال
$channels = [
    'telegram' => [
        'title'     => 'تلگرام',
        'connected' => !empty($u['telegram_chat_id']),
        'chat_id'   => $u['telegram_chat_id'] ?? '',
        'username'  => $u['tg_username'] ?? '',
    ],
    'bale' => [
        'title'     => 'بله',
        'connected' => !empty($u['bale_chat_id']),
        'chat_id'   => $u['bale_chat_id'] ?? '',
        'username'  => $u['bale_username'] ?? '',
    ],
    'rubika' => [
        'title'     => 'روبیکا',
        'connected' => !empty($u['rubika_chat_id']),
        'chat_id'   => $u['rubika_chat_id'] ?? '',
        'username'  => $u['rubika_username'] ?? '',
    ],
];

$hasAnyConnected = !empty($u['telegram_chat_id']) || !empty($u['bale_chat_id']) || !empty($u['rubika_chat_id']);
$isTokenConnected = $tokenRow && ($tokenRow['status'] === 'connected' || $hasAnyConnected);

$activatedChannel = $tokenRow['connected_channel'] ?? '';
if (!$activatedChannel) {
    if (!empty($u['telegram_chat_id'])) $activatedChannel = 'telegram';
    elseif (!empty($u['bale_chat_id'])) $activatedChannel = 'bale';
    elseif (!empty($u['rubika_chat_id'])) $activatedChannel = 'rubika';
}

echo json_encode([
    'ok'                => true,
    'connected'         => $hasAnyConnected,
    'token_connected'   => $isTokenConnected,
    'activated_channel' => $activatedChannel,
    'user'              => [
        'id'   => (int)$u['id'],
        'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر گرامی',
    ],
    'channels'          => $channels,
    'message'           => $hasAnyConnected ? 'ربات با موفقیت متصل شد.' : 'در انتظار ارسال پیام یا زدن دکمه استارت...',
], JSON_UNESCAPED_UNICODE);
exit;
