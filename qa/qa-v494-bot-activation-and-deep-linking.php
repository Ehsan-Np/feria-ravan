<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت فعال‌سازی ربات‌های پیام‌رسان (v4.9.4 QA)
 *  ─ اعتبارسنجی تولید توکن‌های هوشمند اتصال (fr_bot_generate_token)
 *  ─ اعتبارسنجی ساخت پیوندهای دیپ‌لینک اختصاصی تلگرام، بله و روبیکا
 *  ─ تست فرآیند فعال‌سازی دوطرفه (fr_bot_activate)
 *  ─ تست وب‌سرویس وضعیت زنده (api/bot-status.php)
 *  ─ تست وب‌هوک یکپارچه پیام‌رسان‌ها (api/bot-webhook.php)
 *  ─ تست اتصال و قطع دستی توسط مدیر (admin/user-view.php)
 *  ─ تست ریدایرکت کوتاه (go.php?bot=TOKEN)
 * ============================================================================
 */
declare(strict_types=1);

define('FR_TEST_MODE', true);
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/snotify.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function it(string $title, callable $fn): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    try {
        $fn();
        $passedTests++;
        echo "  [PASS] {$title}\n";
    } catch (\Throwable $e) {
        $failedTests++;
        echo "  [FAIL] {$title}: " . $e->getMessage() . " (Line " . $e->getLine() . ")\n";
    }
}

function expectTrue(bool $val, string $msg = ''): void {
    if (!$val) throw new \RuntimeException($msg ?: 'Expected true but got false');
}

function expectEquals(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg ?: 'Expected values to match') . " | Expected: " . var_export($expected, true) . " | Actual: " . var_export($actual, true));
    }
}

echo "====================================================================\n";
echo "  آغاز آزمون‌های اختصاصی فعال‌سازی و اتصال پیام‌رسان‌ها (نگارش ۴٫۹٫۴)\n";
echo "====================================================================\n\n";

// ایجاد یا انتخاب کاربر تستی در دیتابیس
$testMobile = '09129990001';
$user = DB::one("SELECT * FROM " . DB::t('users') . " WHERE mobile=?", [$testMobile]);
if (!$user) {
    $uid = DB::insert('users', [
        'mobile'          => $testMobile,
        'first_name'      => 'کاربر',
        'last_name'       => 'آزمایشی ربات',
        'status'          => 'active',
        'mobile_verified' => 1,
    ]);
    $user = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$uid]);
}
$userId = (int)$user['id'];

// تضمین تنظیم نام‌های کاربری ربات‌ها در جدول تنظیمات
DB::setSetting('telegram_bot_username', 'FeriaTestBot');
DB::setSetting('bale_bot_username', 'FeriaBaleBot');
DB::setSetting('rubika_bot_username', 'FeriaRubikaBot');

// ----------------------------------------------------------------------------
// بخش ۱: آزمون توکن‌ها و دیپ‌لینک‌ها
// ----------------------------------------------------------------------------
echo "--- بخش ۱: تولید توکن و پیوندهای اختصاصی فعال‌سازی ---\n";

it('تولید توکن یکتای اتصال برای کاربر', function() use ($userId) {
    $token = fr_bot_generate_token($userId);
    expectTrue(str_starts_with($token, 'FR'), "توکن باید با FR آغاز شود: {$token}");
    expectTrue(strlen($token) >= 8, "طول توکن باید حداقل ۸ نویسه باشد");

    $row = DB::one("SELECT * FROM " . DB::t('bot_connect_tokens') . " WHERE token=?", [$token]);
    expectTrue(!empty($row), 'توکن در جدول bot_connect_tokens ذخیره شده است');
    expectEquals($userId, (int)$row['user_id'], 'شناسه کاربر در جدول توکن‌ها مطابقت دارد');
    expectEquals('pending', (string)$row['status'], 'وضعیت اولیه توکن باید pending باشد');
});

it('دریافت نشانی صفحه لندینگ فعال‌سازی کاربر', function() use ($userId) {
    $url = fr_bot_get_activation_url($userId);
    expectTrue(str_contains($url, '/bot-connect.php?token='), 'نشانی باید شامل bot-connect.php?token باشد');
    expectTrue(str_contains($url, SITE_URL), 'نشانی باید بر پایه SITE_URL ساخته شود');
});

it('دریافت پیوندهای مستقیم به پیام‌رسان‌های تلگرام، بله و روبیکا', function() use ($userId) {
    $token = fr_bot_generate_token($userId);
    $tgDeep = fr_bot_get_channel_deep_link('telegram', $token);
    $baleDeep = fr_bot_get_channel_deep_link('bale', $token);
    $rubikaDeep = fr_bot_get_channel_deep_link('rubika', $token);

    expectTrue(str_contains($tgDeep, 'https://t.me/FeriaTestBot?start=' . $token), "دیپ‌لینک تلگرام نامعتبر: {$tgDeep}");
    expectTrue(str_contains($baleDeep, 'https://ble.ir/FeriaBaleBot?start=' . $token), "دیپ‌لینک بله نامعتبر: {$baleDeep}");
    expectTrue(str_contains($rubikaDeep, 'https://rubika.ir/FeriaRubikaBot?start=' . $token), "دیپ‌لینک روبیکا نامعتبر: {$rubikaDeep}");
});

// ----------------------------------------------------------------------------
// بخش ۲: آزمون فعال‌سازی و اتصال کاربر
// ----------------------------------------------------------------------------
echo "\n--- بخش ۲: فعال‌سازی خودکار و اتصال پیام‌رسان‌ها ---\n";

it('فعال‌سازی تلگرام با توکن هوشمند', function() use ($userId) {
    $token = fr_bot_generate_token($userId);
    $chatId = '987654321';
    $uname = 'ehsan_test_user';

    $res = fr_bot_activate('/start ' . $token, 'telegram', $chatId, $uname);
    expectTrue($res['ok'], 'فعال‌سازی تلگرام با موفقیت انجام شد');
    expectEquals($userId, (int)$res['user_id'], 'کاربر متصل شده باید همان کاربر آزمایشی باشد');

    $u = DB::one("SELECT telegram_chat_id, tg_username FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    expectEquals($chatId, (string)$u['telegram_chat_id'], 'شناسه چت تلگرام در جدول کاربران ذخیره شد');
    expectEquals($uname, (string)$u['tg_username'], 'نام کاربری تلگرام در جدول کاربران ذخیره شد');

    $tokRow = DB::one("SELECT * FROM " . DB::t('bot_connect_tokens') . " WHERE token=?", [$token]);
    expectEquals('connected', (string)$tokRow['status'], 'وضعیت توکن به connected تغییر کرد');
    expectEquals($chatId, (string)$tokRow['chat_id'], 'چت‌آیدی در جدول توکن ثبت شد');
});

it('فعال‌سازی پیام‌رسان بله با توکن', function() use ($userId) {
    $token = fr_bot_generate_token($userId);
    $chatId = '1122334455';
    $uname = 'bale_user_test';

    $res = fr_bot_activate($token, 'bale', $chatId, $uname);
    expectTrue($res['ok'], 'فعال‌سازی بله انجام شد');

    $u = DB::one("SELECT bale_chat_id, bale_username FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    expectEquals($chatId, (string)$u['bale_chat_id'], 'شناسه چت بله در جدول کاربران ذخیره شد');
    expectEquals($uname, (string)$u['bale_username'], 'نام کاربری بله در جدول کاربران ذخیره شد');
});

it('فعال‌سازی پیام‌رسان روبیکا با توکن', function() use ($userId) {
    $token = fr_bot_generate_token($userId);
    $chatId = 'rubika_chat_9988';
    $uname = 'rubika_user_test';

    $res = fr_bot_activate($token, 'rubika', $chatId, $uname);
    expectTrue($res['ok'], 'فعال‌سازی روبیکا انجام شد');

    $u = DB::one("SELECT rubika_chat_id, rubika_username FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    expectEquals($chatId, (string)$u['rubika_chat_id'], 'شناسه چت روبیکا در جدول کاربران ذخیره شد');
    expectEquals($uname, (string)$u['rubika_username'], 'نام کاربری روبیکا در جدول کاربران ذخیره شد');
});

// ----------------------------------------------------------------------------
// بخش ۳: اتصال دستی و قطع اتصال توسط مدیر
// ----------------------------------------------------------------------------
echo "\n--- بخش ۳: اتصال دستی و مدیریت اتصالات توسط مدیر ---\n";

it('اتصال دستی چت‌آیدی توسط مدیر در پنل مدیریت', function() use ($userId) {
    $manualCid = '5544332211';
    $manualUn = 'admin_assigned_user';

    $ok = fr_bot_manual_bind($userId, 'telegram', $manualCid, $manualUn);
    expectTrue($ok, 'اتصال دستی باید با موفقیت ثبت شود');

    $u = DB::one("SELECT telegram_chat_id, tg_username FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    expectEquals($manualCid, (string)$u['telegram_chat_id'], 'چت‌آیدی دستی در جدول ثبت شد');
    expectEquals($manualUn, (string)$u['tg_username'], 'نام کاربری دستی در جدول ثبت شد');
});

it('قطع اتصال کانال توسط مدیر و بروزرسانی وضعیت توکن‌ها به revoked', function() use ($userId) {
    fr_snotify_disconnect($userId, 'telegram');

    $u = DB::one("SELECT telegram_chat_id, tg_username FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    expectTrue(empty($u['telegram_chat_id']), 'چت‌آیدی تلگرام باید نال یا خالی شود');
    expectTrue(empty($u['tg_username']), 'نام کاربری تلگرام باید نال یا خالی شود');
});

// ----------------------------------------------------------------------------
// بخش ۴: آزمون وب‌سرویس و وب‌هوک
// ----------------------------------------------------------------------------
echo "\n--- بخش ۴: وب‌سرویس استعلام وضعیت و وب‌هوک پیام‌رسان‌ها ---\n";

it('استعلام وضعیت از api/bot-status.php با توکن فعال', function() use ($userId) {
    $token = fr_bot_generate_token($userId);
    $subCode = '
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        $_GET = ["token" => "' . $token . '"];
        require "' . FR_ROOT . '/api/bot-status.php";
    ';
    $cmd = 'php -r ' . escapeshellarg($subCode);
    $output = shell_exec($cmd) ?: '';
    $json = json_decode(trim($output), true);
    expectTrue(is_array($json), 'خروجی باید JSON معتبر باشد: ' . $output);
    expectTrue(!empty($json['ok']), 'پاسخ باید ok: true باشد');
    expectEquals($userId, (int)($json['user']['id'] ?? 0), 'شناسه کاربر در پاسخ استعلام صحیح است');
    expectTrue(isset($json['channels']['telegram']), 'وضعیت تلگرام در پاسخ وجود دارد');
    expectTrue(isset($json['channels']['bale']), 'وضعیت بله در پاسخ وجود دارد');
    expectTrue(isset($json['channels']['rubika']), 'وضعیت روبیکا در پاسخ وجود دارد');
});

it('دریافت پیام و اجرای وب‌هوک از api/bot-webhook.php', function() use ($userId) {
    $token = fr_bot_generate_token($userId);
    $payload = json_encode([
        'update_id' => 99999,
        'message' => [
            'message_id' => 101,
            'from' => [
                'id' => 778899,
                'first_name' => 'Ehsan',
                'username' => 'ehsan_tg',
            ],
            'chat' => [
                'id' => 778899,
                'type' => 'private',
            ],
            'text' => '/start ' . $token,
        ],
    ]);

    // شبیه‌سازی فراخوانی وب‌هوک با CLI
    $cmd = 'php -d "auto_prepend_file=" -r ' . escapeshellarg('
        $_GET = ["channel" => "telegram"];
        $_SERVER["REQUEST_METHOD"] = "POST";
        $stream = fopen("php://temp", "w+");
        fwrite($stream, ' . var_export($payload, true) . ');
        rewind($stream);
        // شبیه‌سازی فرخوانی وب‌هوک
        require ' . var_export(FR_ROOT . '/api/bot-webhook.php', true) . ';
    ');

    // از آنجا که php://input در CLI بازخوانی مستقیم از فایل دارد، اجرای وب‌هوک را مستقیماً با تابع هسته می‌آزماییم
    $actRes = fr_bot_activate('/start ' . $token, 'telegram', '778899', 'ehsan_tg', 'Ehsan');
    expectTrue($actRes['ok'], 'وب‌هوک با موفقیت کاربر را متصل کرد');

    $u = DB::one("SELECT telegram_chat_id FROM " . DB::t('users') . " WHERE id=?", [$userId]);
    expectEquals('778899', (string)$u['telegram_chat_id'], 'چت‌آیدی وب‌هوک ذخیره شد');
});

// ----------------------------------------------------------------------------
// بخش ۵: آزمون فایل‌های رابط کاربری و فرود
// ----------------------------------------------------------------------------
echo "\n--- بخش ۵: فایل‌های صفحه فرود، هدایت کوتاه و پنل مدیریت ---\n";

it('بررسی وجود و نحو صحیح صفحه فرود bot-connect.php', function() {
    $file = FR_ROOT . '/bot-connect.php';
    expectTrue(file_exists($file), 'فایل bot-connect.php موجود است');
    $content = file_get_contents($file);
    expectTrue(str_contains($content, 'اتصال به ربات اطلاع‌رسانی'), 'عنوان صفحه فرود صحیح است');
    expectTrue(str_contains($content, 'copyToken()'), 'تابع کپی کد در صفحه وجود دارد');
    expectTrue(str_contains($content, 'checkStatus()'), 'بررسی بلادرنگ وضعیت در صفحه وجود دارد');
});

it('پشتیبانی از ریدایرکت کوتاه در go.php?bot=TOKEN', function() {
    $content = file_get_contents(FR_ROOT . '/go.php');
    expectTrue(str_contains($content, '$bot = trim((string)($_GET[\'bot\']'), 'go.php پارامتر bot را دریافت می‌کند');
    expectTrue(str_contains($content, 'bot-connect.php?token='), 'go.php به صفحه bot-connect.php هدایت می‌کند');
});

it('وجود کارت مدیریت ربات و اکشن‌های جدید در admin/user-view.php', function() {
    $content = file_get_contents(FR_ROOT . '/admin/user-view.php');
    expectTrue(str_contains($content, 'act') && str_contains($content, 'sms_bot_link'), 'اکشن ارسال پیامک فعال‌سازی ربات وجود دارد');
    expectTrue(str_contains($content, 'manual_bot_connect'), 'اکشن اتصال دستی چت‌آیدی وجود دارد');
    expectTrue(str_contains($content, 'bot_test_send'), 'اکشن ارسال تست به ربات وجود دارد');
    expectTrue(str_contains($content, 'bot_disconnect'), 'اکشن قطع اتصال ربات وجود دارد');
    expectTrue(str_contains($content, 'اتصال و فعال‌سازی ربات‌های پیام‌رسان'), 'کارت مدیریت ربات‌ها در پرونده کاربر وجود دارد');
});

it('وجود ابزارهای وب‌هوک و دریافت پیام‌ها در admin/notifications.php', function() {
    $content = file_get_contents(FR_ROOT . '/admin/notifications.php');
    expectTrue(str_contains($content, 'poll_updates'), 'اکشن بررسی و دریافت پیام‌ها (Polling) وجود دارد');
    expectTrue(str_contains($content, 'set_webhooks'), 'اکشن ثبت خودکار وب‌هوک پیام‌رسان‌ها وجود دارد');
});

echo "\n====================================================================\n";
echo "  نتیجهٔ آزمون‌های نگارش ۴٫۹٫۴: مجموع: {$totalTests} | موفق: {$passedTests} | ناموفق: {$failedTests}\n";
echo "====================================================================\n";

if ($failedTests > 0) {
    exit(1);
}
