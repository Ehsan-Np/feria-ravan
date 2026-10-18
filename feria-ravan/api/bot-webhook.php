<?php
/**
 * فریا روان — وب‌هوک پیام‌رسان‌های تلگرام، بله و روبیکا
 * دریافت آنی رویدادها، دکمه‌های استارت و اتصال خودکار کاربران
 * نسخه ۴٫۹٫۴
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/snotify.php';

fr_bootstrap();
header('Content-Type: application/json; charset=utf-8');

$channel = strtolower(trim((string)($_GET['channel'] ?? ($_GET['ch'] ?? 'telegram'))));
if (!in_array($channel, ['telegram', 'bale', 'rubika'], true)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_channel']);
    exit;
}

$rawInput = (string)file_get_contents('php://input');
if ($rawInput === '') {
    echo json_encode(['ok' => true, 'message' => 'no_input']);
    exit;
}

$update = json_decode($rawInput, true);
if (!is_array($update)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// استخراج پیام از بدنه
$msg = $update['message'] ?? ($update['edited_message'] ?? ($update['data'] ?? null));
if (!$msg && isset($update['chat_id'])) {
    $msg = $update;
}

if (!is_array($msg)) {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$chatId = (string)($msg['chat']['id'] ?? ($msg['chat_id'] ?? ($msg['from']['id'] ?? '')));
$from = $msg['from'] ?? [];
$username = (string)($from['username'] ?? '');
$firstName = (string)($from['first_name'] ?? '');
$text = trim((string)($msg['text'] ?? ($msg['message'] ?? '')));

if ($chatId === '') {
    echo json_encode(['ok' => true, 'ignored' => 'no_chat_id']);
    exit;
}

// شناسایی توکن از دستور استارت
$token = '';
if (preg_match('#^/start(?:@\w+)?\s+([A-Za-z0-9_-]+)#u', $text, $m)) {
    $token = trim($m[1]);
} elseif (preg_match('/^(FR[A-Fa-f0-9]{8}|[A-Fa-f0-9]{8})$/i', $text, $m2)) {
    $token = trim($m2[1]);
} else {
    $token = $text;
}

// تلاش برای فعال‌سازی خودکار
$act = fr_bot_activate($token, $channel, $chatId, $username !== '' ? $username : null, $firstName);

if ($act['ok']) {
    echo json_encode(['ok' => true, 'activated' => true, 'user_id' => $act['user_id']]);
    exit;
}

// اگر با توکن متصل نشد، اما دستور /start زده شده بود، راهنمای اتصال ارسال شود
if (str_starts_with($text, '/start')) {
    $site = (string)DB::setting('site_title', 'فریا روان');
    $siteUrl = rtrim(SITE_URL, '/');
    $channelLabel = fr_snotify_channels()[$channel] ?? $channel;
    $reply = "سلام {$firstName} عزیز 🦢\n" .
             "به ربات اطلاع‌رسانی {$channelLabel} {$site} خوش آمدید.\n\n" .
             "برای فعال‌سازی و اتصال این ربات به حساب کاربری خود، لطفاً وارد بخش «پروفایل» یا لینک دریافتی پیامک خود شده و دکمهٔ اختصاصی اتصال ربات را لمس کنید:\n" .
             "🔗 {$siteUrl}/panel/profile.php\n\n" .
             "یا در صورت داشتن کد اتصال (مثال: FR12A34B56)، آن را همین‌جا ارسال فرمایید.";

    [, $sender] = fr_snotify_driver($channel);
    $sender($chatId, $reply);
}

echo json_encode(['ok' => true, 'handled' => true]);
exit;
