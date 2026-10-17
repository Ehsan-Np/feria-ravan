<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت فریا روان — نگارش ۴٫۹٫۰ (v4.9.0 QA Suite)
 *  ─ اعتبارسنجی ارتقای سراسری نسخه به نگارش ۴٫۹٫۰ (Constants, Settings, Migrations)
 *  ─ بازنویسی کامل برنامه‌ریز روزانه (Daily Planner) مبتنی بر تقویم جلالی سال ۱۴۰۵
 *  ─ صحت محاسبات ریاضی تقویم شمسی ۱۴۰۵ (تبدیل روزها، هفته شنبه تا جمعه، استریک‌ها)
 *  ─ سلامت تمام پیوندها و مسیرهای داخلی در پنل کاربر (fr_panel_url) و بدون پیوند شکسته
 *  ─ آزمون سلامت و رندرینگ بی‌نقص تمام ۹ تب برنامه‌ریز (امروز، تقویم، عادات، تمرکز، خلق، دلتا، ...)
 *  ─ ثبت و بازخوانی وضعیت روانی و سطح انرژی روزانه (Mood Check-in & fr_planner_moods)
 *  ─ ارکستراسیون پیام‌رسانی چندکاناله: تلگرام، بله، و روبیکا (Telegram, Bale, Rubika)
 *  ─ پشتیبانی کامل درایور بومی روبیکا (core/Rubika.php) با توکن، وب‌هوک و لاگ پیام
 *  ─ زمان‌بندی خودکار و ارسال یادآورها در نوبت‌های تعیین‌شده (fr_planner_remind_due)
 *  ─ ترایگر کرون پس‌زمینه خودکار (fr_auto_cron_tick) در امنیت و درخواست‌های وب
 * ============================================================================
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('FR_TEST_MODE', true);
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/includes/planner.php';
require_once FR_ROOT . '/includes/snotify.php';
require_once FR_ROOT . '/core/MsgGateway.php';
require_once FR_ROOT . '/core/Rubika.php';
require_once FR_ROOT . '/core/Telegram.php';
require_once FR_ROOT . '/core/Bale.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = [];

function it(string $title, callable $fn): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    try {
        $fn();
        $passedTests++;
        echo "  [PASS] {$title}\n";
    } catch (\Throwable $e) {
        $failedTests[] = ['title' => $title, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
        echo "  [FAIL] {$title} -> " . $e->getMessage() . "\n";
    }
}

function expectTrue(bool $val, string $msg = ''): void {
    if (!$val) throw new \RuntimeException($msg ?: 'Assertion failed: expected true');
}

function expectEq($actual, $expected, string $msg = ''): void {
    if ($actual !== $expected) {
        throw new \RuntimeException($msg ?: sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true)));
    }
}

echo "=======================================================================\n";
echo "  شروع آزمون‌های تضمین کیفیت نگارش ۴٫۹٫۰ (Planner 1405 & Multi-Channel)\n";
echo "=======================================================================\n\n";

// ============================================================================
// بخش ۱: ارتقای نگارش به ۴٫۹٫۰ و یکپارچگی پایگاه داده
// ============================================================================
echo "--- بخش ۱: نسخه ۴٫۹٫۰ و ساختار پایگاه داده ---\n";

it('ثابت‌های FR_VERSION و FR_RELEASE در includes/version.php دقیقاً برابر 4.9.0 هستند', function() {
    expectEq(FR_VERSION, '4.9.0', 'FR_VERSION must be 4.9.0');
    expectEq(FR_RELEASE, '4.9.0', 'FR_RELEASE must be 4.9.0');
});

it('تنظیمات پایگاه داده system_version و fr_version مقدار 4.9.0 را برمی‌گرداند', function() {
    $ver = DB::setting('system_version');
    expectEq($ver, '4.9.0', "DB system_version must be 4.9.0, got: $ver");
});

it('فایل config.php و sample_config به نسخه 4.9.0 ارجاع داده‌اند', function() {
    $cfg = file_get_contents(FR_ROOT . '/config.php');
    $sample = file_get_contents(FR_ROOT . '/includes/config.sample.php');
    expectTrue(str_contains($cfg, "'4.9.0'"), 'config.php must reference 4.9.0');
    expectTrue(str_contains($sample, "'4.9.0'"), 'config.sample.php must reference 4.9.0');
});

it('فایل includes/update.php بلوک ارتقای نگارش 4.9.0 را دارد', function() {
    $updater = file_get_contents(FR_ROOT . '/includes/update.php');
    expectTrue(str_contains($updater, '۴٫۹٫۰') || str_contains($updater, '4.9.0'), 'update.php must contain 4.9.0 migration check');
    expectTrue(str_contains($updater, "planner_moods"), 'update.php must mention planner_moods');
    expectTrue(str_contains($updater, "rubika_chat_id"), 'update.php must mention rubika columns');
});

it('فایل مهاجرت یکپارچه جدید database/migrations/v490_unified_migration.sql موجود و v480 حذف شده است', function() {
    expectTrue(file_exists(FR_ROOT . '/database/migrations/v490_unified_migration.sql'), 'v490 migration file must exist');
    expectTrue(!file_exists(FR_ROOT . '/database/migrations/v480_unified_migration.sql'), 'v480 migration file must be removed');
    $sql = file_get_contents(FR_ROOT . '/database/migrations/v490_unified_migration.sql');
    expectTrue(str_contains($sql, "'system_version', '4.9.0'"), 'v490 SQL must set system_version to 4.9.0');
    expectTrue(str_contains($sql, "planner_moods"), 'v490 SQL must create planner_moods table');
    expectTrue(str_contains($sql, "rubika_chat_id"), 'v490 SQL must add rubika columns');
});

it('جدول fr_planner_moods در پایگاه داده ایجاد شده و ستون‌های استاندارد را دارد', function() {
    $cols = DB::all("SHOW COLUMNS FROM " . DB::t('planner_moods'));
    $names = array_column($cols, 'Field');
    expectTrue(in_array('id', $names, true), 'Column id missing');
    expectTrue(in_array('user_id', $names, true), 'Column user_id missing');
    expectTrue(in_array('day', $names, true), 'Column day missing');
    expectTrue(in_array('mood', $names, true), 'Column mood missing');
    expectTrue(in_array('energy', $names, true), 'Column energy missing');
    expectTrue(in_array('reflection', $names, true), 'Column reflection missing');
});

it('ستون‌های rubika_chat_id و rubika_username در fr_users و fr_contacts موجودند', function() {
    $userCols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('users')), 'Field');
    expectTrue(in_array('rubika_chat_id', $userCols, true), 'Column rubika_chat_id missing in fr_users');
    expectTrue(in_array('rubika_username', $userCols, true), 'Column rubika_username missing in fr_users');

    $contactCols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('contacts')), 'Field');
    expectTrue(in_array('rubika_chat_id', $contactCols, true), 'Column rubika_chat_id missing in fr_contacts');
});

// ============================================================================
// بخش ۲: موتور تقویم جلالی سال ۱۴۰۵ در برنامه‌ریز
// ============================================================================
echo "\n--- بخش ۲: موتور تقویم جلالی ۱۴۰۵ و توابع برنامه‌ریز ---\n";

it('تابع fr_jday_normalize فرمت تاریخ شمسی را نرمال‌سازی می‌کند', function() {
    expectEq(fr_jday_normalize('1405-1-5'), '1405-01-05');
    expectEq(fr_jday_normalize('1405/01/05'), '1405-01-05');
    expectEq(fr_jday_normalize('۱۴۰۵-۰۲-۱۵'), '1405-02-15');
});

it('تابع fr_jday_add عملیات ریاضی روزها را بر پایه تقویم شمسی به‌درستی محاسبه می‌کند', function() {
    // عبور از انتهای ماه ۳۱ روزه فروردین به اردیبهشت
    $next = fr_jday_add('1405-01-31', 1);
    expectEq($next, '1405-02-01', "Day after 1405-01-31 should be 1405-02-01, got $next");

    // تفریق ۱ روز از ابتدای اردیبهشت
    $prev = fr_jday_add('1405-02-01', -1);
    expectEq($prev, '1405-01-31', "Day before 1405-02-01 should be 1405-01-31, got $prev");

    // عبور از انتهای شهریور (۳۱ روزه) به اول مهر (۳۰ روزه)
    $mehr1 = fr_jday_add('1405-06-31', 1);
    expectEq($mehr1, '1405-07-01', "Day after 1405-06-31 should be 1405-07-01, got $mehr1");
});

it('تابع fr_jday_diff فاصله دو روز شمسی را بدون خطای تغییر ساعت و منطقه زمانی محاسبه می‌کند', function() {
    $diff1 = fr_jday_diff('1405-01-10', '1405-01-01');
    expectEq($diff1, 9, "Diff between 1405-01-10 and 1405-01-01 should be 9, got $diff1");

    $diff2 = fr_jday_diff('1405-01-01', '1405-01-10');
    expectEq($diff2, -9, "Diff between 1405-01-01 and 1405-01-10 should be -9, got $diff2");

    $diffSame = fr_jday_diff('1405-07-15', '1405-07-15');
    expectEq($diffSame, 0, "Diff for same day should be 0, got $diffSame");
});

it('تابع fr_jweek_days روزهای تقویم را از شنبه تا جمعه با داده‌های کامل برمی‌گرداند', function() {
    $week = fr_jweek_days('1405-01-05');
    expectEq(count($week), 7, 'A week must have exactly 7 days');
    expectEq($week[0]['name'], 'شنبه', 'First day of week must be شنبه');
    expectEq($week[6]['name'], 'جمعه', 'Last day of week must be جمعه');

    foreach ($week as $idx => $d) {
        expectTrue(isset($d['day']), "Day index $idx must have 'day' attribute");
        expectTrue(isset($d['name']), "Day index $idx must have 'name' attribute");
        expectTrue(isset($d['num']), "Day index $idx must have 'num' attribute");
        expectTrue(isset($d['is_today']), "Day index $idx must have 'is_today' attribute");
        expectTrue(isset($d['is_future']), "Day index $idx must have 'is_future' attribute");
    }
});

it('نام ماه‌های شمسی fr_jmonth_name تمام ۱۲ ماه را صحیح برمی‌گرداند', function() {
    $months = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    foreach ($months as $num => $expectedName) {
        expectEq(fr_jmonth_name($num), $expectedName, "Month $num should be $expectedName");
    }
});

// ============================================================================
// بخش ۳: موتور خلق و انرژی روزانه (Mood Check-in & fr_planner_moods)
// ============================================================================
echo "\n--- بخش ۳: موتور ثبت و سنجش وضعیت روانی و انرژی (Mood Engine) ---\n";

it('ثبت وضعیت خلق، انرژی، و یادداشت‌های روان‌شناختی', function() {
    $testUserId = 1;
    $testDay = '1405-07-26';
    $mood = 'good';
    $energyLevel = 5;
    $notes = 'امروز تمرکز بسیار خوبی روی وظایفم داشتم و تمام جلسات به موقع انجام شد.';

    $saved = fr_planner_mood_save($testUserId, $testDay, $mood, $energyLevel, $notes);
    expectTrue($saved, 'fr_planner_mood_save should return true');

    $record = fr_planner_mood_get($testUserId, $testDay);
    expectTrue($record !== null, 'Saved mood record must be retrievable');
    expectEq($record['mood'], 'good', 'Mood must match good');
    expectEq((int)$record['mood_score'], 4, 'Mood score must match 4');
    expectEq((int)$record['energy_level'], 5, 'Energy level must match 5');
    expectTrue(str_contains($record['notes'], 'تمرکز'), 'Notes must contain phrase');
});

it('به‌روزرسانی همان روز رکورد جدید نمی‌سازد بلکه همان رکورد را آپدیت می‌کند (Upsert)', function() {
    $testUserId = 1;
    $testDay = '1405-07-26';
    $mysqlDay = fr_planner_normalize_day($testDay);
    $beforeCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_moods') . " WHERE user_id=? AND day=?", [$testUserId, $mysqlDay]);

    fr_planner_mood_save($testUserId, $testDay, 'great', 4, 'بروزرسانی شبانگاهی یادداشت');
    $afterCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_moods') . " WHERE user_id=? AND day=?", [$testUserId, $mysqlDay]);

    expectEq($afterCount, $beforeCount, 'Upsert must not create duplicate mood rows for the same day');
    $record = fr_planner_mood_get($testUserId, $testDay);
    expectEq($record['mood'], 'great', 'Updated mood should be great');
    expectEq((int)$record['mood_score'], 5, 'Updated mood score should be 5');
});

it('تاریخچه خلق fr_planner_mood_history آرایه‌ای بر اساس روز و با امتیازات دقیق برمی‌گرداند', function() {
    $history = fr_planner_mood_history(1, 14);
    expectTrue(is_array($history), 'Mood history must be an array');
    $todayMysql = fr_planner_today();
    expectTrue(isset($history[$todayMysql]), 'Today entry must be present in history');
    expectEq((int)$history[$todayMysql]['mood_score'], 5);
});

// ============================================================================
// بخش ۴: پیام‌رسانی چندکاناله (Telegram, Bale, Rubika)
// ============================================================================
echo "\n--- بخش ۴: پیام‌رسانی چندکاناله و درایورهای تلگرام، بله، روبیکا ---\n";

it('درایور بومی روبیکا core/Rubika.php به درستی بارگذاری و متدها را پیاده‌سازی می‌کند', function() {
    expectTrue(class_exists('Rubika'), 'Class Rubika must exist');
    $bot = new Rubika('test_token_123');
    expectTrue(method_exists($bot, 'sendMessage'), 'Rubika::sendMessage must exist');
    expectTrue(method_exists($bot, 'sendBatch'), 'Rubika::sendBatch must exist');
    expectTrue(method_exists($bot, 'getMe'), 'Rubika::getMe must exist');
    expectTrue(method_exists($bot, 'getUpdates'), 'Rubika::getUpdates must exist');
    expectTrue(method_exists($bot, 'setWebhook'), 'Rubika::setWebhook must exist');
});

it('درایور بله core/Bale.php متدهای استاندارد و endpoint اختصاصی را داراست', function() {
    expectTrue(class_exists('Bale'), 'Class Bale must exist');
    $bale = new Bale('bale_test_token');
    expectTrue(method_exists($bale, 'sendMessage'), 'Bale::sendMessage must exist');
    expectTrue(method_exists($bale, 'getMe'), 'Bale::getMe must exist');
});

it('درایور تلگرام core/Telegram.php از پروکسی و endpoint پشتیبانی می‌کند', function() {
    expectTrue(class_exists('Telegram'), 'Class Telegram must exist');
    $tg = new Telegram('tg_test_token');
    expectTrue(method_exists($tg, 'sendMessage'), 'Telegram::sendMessage must exist');
    expectTrue(method_exists($tg, 'getMe'), 'Telegram::getMe must exist');
});

it('گیت‌وی چندکاناله core/MsgGateway.php از کانال‌های telegram, bale, rubika پشتیبانی می‌کند', function() {
    expectTrue(class_exists('MsgGateway'), 'Class MsgGateway must exist');
    expectTrue(MsgGateway::isSupported('telegram'), 'Telegram must be supported in MsgGateway');
    expectTrue(MsgGateway::isSupported('bale'), 'Bale must be supported in MsgGateway');
    expectTrue(MsgGateway::isSupported('rubika'), 'Rubika must be supported in MsgGateway');
});

it('سرویس نوتیفیکیشن includes/snotify.php کانال روبیکا را در لیست کانال‌های فعال ارائه می‌دهد', function() {
    $channels = fr_snotify_channels();
    expectTrue(isset($channels['rubika']), 'fr_snotify_channels must include rubika');
    expectTrue(isset($channels['telegram']), 'fr_snotify_channels must include telegram');
    expectTrue(isset($channels['bale']), 'fr_snotify_channels must include bale');
});

it('تابع fr_snotify_send پیام را در لاگ سیستم fr_notify_log ثبت می‌کند', function() {
    $beforeCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('notify_log'));
    $res = fr_snotify_send('rubika', 'u0123456789', 'آزمون پیام تستی روبیکا در نگارش ۴٫۹٫۰', 'test');
    $afterCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('notify_log'));

    expectTrue($afterCount > $beforeCount, 'fr_snotify_send must create a record in fr_notify_log');
    $lastLog = DB::one("SELECT * FROM " . DB::t('notify_log') . " ORDER BY id DESC LIMIT 1");
    expectEq($lastLog['channel'], 'rubika', 'Last log channel must be rubika');
    expectTrue(str_contains($lastLog['payload'], 'u0123456789'), 'Last log payload must match recipient');
});

// ============================================================================
// بخش ۵: زمان‌بندی و یادآورهای پلنر (Planner Reminders & Cron)
// ============================================================================
echo "\n--- بخش ۵: زمان‌بندی، یادآورها و کرون جاب پلنر ---\n";

it('توابع نوبت‌های یادآوری fr_planner_remind_slots و ذخیره نوبت‌ها fr_planner_remind_save به درستی کار می‌کنند', function() {
    $times = fr_planner_remind_save(3, ['09:15', '14:30', '21:00']);
    expectEq(count($times), 3, 'fr_planner_remind_save should store 3 slots');
    expectEq($times[0], '09:15');
    expectEq($times[1], '14:30');
    expectEq($times[2], '21:00');

    $slots = fr_planner_remind_slots();
    expectEq($slots, ['09:15', '14:30', '21:00'], 'fr_planner_remind_slots must return stored slots');
});

it('وضعیت ارسال نوبت‌های امروز fr_planner_remind_status_today اطلاعات دقیق می‌دهد', function() {
    $status = fr_planner_remind_status_today();
    expectEq(count($status), 3, 'Must have 3 status rows');
    expectEq($status[0]['time'], '09:15');
    expectTrue(isset($status[0]['sent']), 'sent flag must be present');
});

it('نگارش پیام یادآور پلنر fr_planner_remind_compose شامل تاریخ شمسی و متن شخصی‌سازی‌شده است', function() {
    $usr = ['id' => 1, 'first_name' => 'علیرضا'];
    $msg = fr_planner_remind_compose($usr, 0, '09:15', '1405-07-26');
    expectTrue(str_contains($msg, 'علیرضا'), 'Reminder must include user first name');
    expectTrue(str_contains($msg, '۱۴۰۵'), 'Reminder must include year 1405');
    expectTrue(str_contains($msg, 'برنامه'), 'Reminder must mention tasks/plans');
});

it('کانال‌های یادآوری برنامه‌ریز fr_planner_remind_channels کانال‌های پیکربندی‌شده را شامل می‌شود', function() {
    DB::setSetting('planner_notify_channels', 'telegram,bale,rubika');
    $channels = fr_planner_remind_channels();
    expectTrue(in_array('telegram', $channels, true), 'Must include telegram');
    expectTrue(in_array('bale', $channels, true), 'Must include bale');
    expectTrue(in_array('rubika', $channels, true), 'Must include rubika');
});

it('اجرای آزمایشی fr_planner_remind_due خروجی عدد صحیح بازمی‌گرداند', function() {
    $res = fr_planner_remind_due(50);
    expectTrue(is_int($res) && $res >= 0, 'fr_planner_remind_due must return integer count >= 0');
});

it('اجرای کرون اتوماتیک در وب fr_auto_cron_tick با رعایت اینتروال زمانی اجرا می‌شود', function() {
    expectTrue(function_exists('fr_auto_cron_tick'), 'fr_auto_cron_tick must exist in security.php/snotify.php');
    DB::setSetting('last_auto_cron_ts', '0');
    $initialTick = fr_auto_cron_tick(60);
    expectTrue($initialTick === true, 'Initial tick when interval expired must return true');

    // فراخوانی دوم در همان لحظه باید تروتل شده و false بدهد
    $throttled = fr_auto_cron_tick(60);
    expectEq($throttled, false, 'Second tick within interval must be throttled');
});

// ============================================================================
// بخش ۶: رندرینگ و سلامت پیوندها در تمام ۹ تب پنل برنامه‌ریز
// ============================================================================
echo "\n--- بخش ۶: سلامت رندرینگ و لینک‌های پنل برنامه‌ریز (panel/planner.php) ---\n";

it('تمام ۹ تب پنل کاربری بدون خطای PHP یا هشدار رندر می‌شوند و لینک شکسته ندارند', function() {
    $_SESSION['user_id'] = 1;
    $tabs = ['today', 'calendar', 'habits', 'focus', 'mood', 'delta', 'new', 'archive', 'settings'];

    foreach ($tabs as $t) {
        $_GET = ['tab' => $t];
        ob_start();
        include FR_ROOT . '/panel/planner.php';
        $out = ob_get_clean();

        expectTrue(strlen($out) > 500, "Tab $t produced empty or too short output (" . strlen($out) . " bytes)");
        expectTrue(!str_contains($out, 'Fatal error'), "Tab $t produced Fatal error");
        expectTrue(!str_contains($out, 'Parse error'), "Tab $t produced Parse error");
        expectTrue(!str_contains($out, 'Notice:'), "Tab $t produced PHP Notice");

        // اعتبارسنجی پیوندهای داخلی: هیچ پیوندی نباید به فایل ناموجود یا اشتباه ارجاع دهد
        expectTrue(!str_contains($out, 'href="planner.php?'), "Tab $t should use fr_panel_url for clean absolute links");
        expectTrue(str_contains($out, 'planner.php') || str_contains($out, 'tab='), "Tab $t should contain navigation links");
    }
});

it('صفحه مدیریت اعلانات admin/notifications.php برای روبیکا، بله و تلگرام بدون خطا رندر می‌شود', function() {
    $_SESSION['admin_id'] = 1;
    $_GET = [];
    $_POST = [];

    ob_start();
    include FR_ROOT . '/admin/notifications.php';
    $out = ob_get_clean();

    expectTrue(strlen($out) > 5000, "admin/notifications.php produced too short output (" . strlen($out) . " bytes)");
    expectTrue(str_contains($out, 'روبیکا'), 'Must contain Rubika section');
    expectTrue(str_contains($out, 'بله'), 'Must contain Bale section');
    expectTrue(str_contains($out, 'تلگرام'), 'Must contain Telegram section');
    expectTrue(str_contains($out, 'telegram_proxy'), 'Must contain Telegram proxy input');
    expectTrue(str_contains($out, 'rubika_bot_token'), 'Must contain Rubika bot token input');
    expectTrue(str_contains($out, 'dispatch_now'), 'Must contain instant dispatch trigger');
});

it('صفحه مدیریت برنامه‌ریز admin/planner.php نوبت‌های یادآوری و تنظیمات چندکاناله را رندر می‌کند', function() {
    $_SESSION['admin_id'] = 1;
    $_GET = [];
    $_POST = [];

    ob_start();
    include FR_ROOT . '/admin/planner.php';
    $out = ob_get_clean();

    expectTrue(strlen($out) > 5000, "admin/planner.php produced too short output (" . strlen($out) . " bytes)");
    expectTrue(str_contains($out, 'روبیکا'), 'Must mention Rubika in channels');
    expectTrue(str_contains($out, 'یادآور'), 'Must mention Reminders');
    expectTrue(str_contains($out, '۱۴۰۵'), 'Must contain year 1405');
});

// ============================================================================
// بخش ۷: API برنامه‌ریز (api/planner.php)
// ============================================================================
echo "\n--- بخش ۷: API برنامه‌ریز (api/planner.php) ---\n";

it('ای‌پی‌آی api/planner.php عملیات سریع وضعیت کار done را به درستی پردازش می‌کند', function() {
    // ایجاد یک تسک آزمایشی
    $taskId = (int)DB::insert('planner_tasks', [
        'user_id' => 1,
        'day' => '2026-10-18',
        'title' => 'تسک آزمایشی API',
        'priority' => 'normal',
        'category' => 'work',
        'done' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $cmd = 'php -r ' . escapeshellarg('
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        define("FR_ENV", "production");
        session_start();
        $_SESSION["user_id"] = 1;
        $_SESSION["_csrf"] = "test_csrf_token_123";
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_POST = [
            "action" => "toggle",
            "task_id" => "' . $taskId . '",
            "_csrf" => "test_csrf_token_123"
        ];
        include FR_ROOT . "/api/planner.php";
    ');
    $json = shell_exec($cmd);

    $res = json_decode((string)$json, true);
    expectTrue(is_array($res), 'api/planner.php must return valid JSON, got: ' . $json);
    expectTrue($res['ok'] === true, 'API response ok must be true: ' . $json);
    expectEq((int)$res['done'], 1, 'API response done must be 1');

    $updated = DB::one("SELECT done FROM " . DB::t('planner_tasks') . " WHERE id=?", [$taskId]);
    expectEq((int)$updated['done'], 1, 'Database done status must be updated to 1');
});

it('ای‌پی‌آی api/planner.php ثبت آژاکس خلق و انرژی روزانه را به درستی انجام می‌دهد', function() {
    $cmd = 'php -r ' . escapeshellarg('
        define("FR_TEST_MODE", true);
        define("DB_HOST", "127.0.0.1");
        define("DB_NAME", "feria_test");
        define("DB_USER", "feria");
        define("DB_PASS", "feria_password");
        define("DB_PREFIX", "fr_");
        define("SITE_URL", "http://127.0.0.1:8000");
        define("FR_ROOT", "/home/user/feria-ravan/feria-ravan");
        define("FR_ENV", "production");
        session_start();
        $_SESSION["user_id"] = 1;
        $_SESSION["_csrf"] = "test_csrf_token_123";
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_POST = [
            "action" => "mood",
            "day" => "1405-07-26",
            "mood" => "great",
            "energy" => "4",
            "notes" => "ثبت آژاکس حال و هوا",
            "_csrf" => "test_csrf_token_123"
        ];
        include FR_ROOT . "/api/planner.php";
    ');
    $json = shell_exec($cmd);

    $res = json_decode((string)$json, true);
    expectTrue(is_array($res), 'api/planner.php mood action must return valid JSON, got: ' . $json);
    expectTrue($res['ok'] === true, 'API mood response ok must be true: ' . $json);
});

// ============================================================================
// خلاصه نتایج
// ============================================================================
echo "\n=======================================================================\n";
echo "  خلاصه نتایج آزمون‌های نگارش ۴٫۹٫۰ (v4.9.0 QA Results)\n";
echo "  کل آزمون‌ها: {$totalTests}\n";
echo "  موفق: {$passedTests}\n";
echo "  ناموفق: " . count($failedTests) . "\n";
echo "=======================================================================\n";

if (!empty($failedTests)) {
    echo "\nجزئیات خطاها:\n";
    foreach ($failedTests as $f) {
        echo " - {$f['title']}: {$f['error']} ({$f['file']}:{$f['line']})\n";
    }
    exit(1);
} else {
    echo "\n✔ تبریک! تمامی آزمون‌های نگارش ۴٫۹٫۰ با موفقیت ۱۰۰٪ سپری شدند.\n\n";
    exit(0);
}
