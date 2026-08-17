<?php
/* ============================================================================
   QA نسخهٔ ۲٫۴ — یادآوری چندنوبتی پلنر (مثلاً ۴ بار در روز) + ساعتِ کاملاً
   ۲۴ ساعته در پنل مدیریت و پنل کاربر (ورودی ماسک‌دار t24 به‌جای type=time)
   ============================================================================ */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

const BASE   = 'http://localhost:8899';
const COOKIE  = '/tmp/qa-v24-cookies.txt';   // مدیر
const COOKIE2 = '/tmp/qa-v24-cookies2.txt';  // کاربر
const AUSER  = 'admin';
const APASS  = 'AdminTest!2026';
const QA_MOB = '09120004444';

date_default_timezone_set('Asia/Tehran');   // هم‌تراز با منطقهٔ زمانی اپلیکیشن
$SITE_TODAY = date('Y-m-d');

$PASS = 0; $FAIL = 0;
function ok(bool $c, string $name, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($c) { $PASS++; echo "  ✅ $name" . ($extra ? " — $extra" : '') . "\n"; }
    else    { $FAIL++; echo "  ❌ $name" . ($extra ? " — $extra" : '') . "\n"; }
}
function http(string $method, string $path, array|string $data = [], array $headers = [], string $jar = COOKIE): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$code, substr($raw, 0, $hlen), substr($raw, $hlen)];
}
function getx(string $path, string $jar = COOKIE): array {
    [$c, $h, $b] = http('GET', $path, [], [], $jar);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\\s*(\\S+)\\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]), [], [], $jar);
    }
    return [$c, $b];
}
function post_csrf(string $path, array $fields, string $csrf, string $jar = COOKIE): array {
    [$c, $h, $b] = http('POST', $path, $fields + ['_csrf' => $csrf], [], $jar);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\\s*(\\S+)\\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]), [], [], $jar);
    }
    return [$c, $b];
}
function api(string $url, array $data, string $token, string $jar = COOKIE2): array {
    [$c, $h, $b] = http('POST', $url, json_encode($data, JSON_UNESCAPED_UNICODE), [
        'Content-Type: application/json', 'X-CSRF: ' . $token,
    ], $jar);
    $j = json_decode($b, true);
    return [$c, is_array($j) ? $j : ['__raw' => $b]];
}
$sql = fn(string $q) => trim((string)shell_exec(
    "mysql --default-character-set=utf8mb4 -u feria -pferia_test_pass_2026 -N -e " .
    escapeshellarg($q) . " feria_test 2>&1"));

/* ── پاک‌سازی QA قبلی ── */
echo "── پاک‌سازی QA ۲٫۴ قبلی…\n";
$sql("DELETE FROM fr_rate_limits WHERE bucket LIKE 'otp:%'");   // جلوگیری از ریت‌لیمیت OTP در اجراهای پیاپی QA
$old = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MOB . "' LIMIT 1");
if ($old) {
    $sql("DELETE pt FROM fr_planner_tasks pt WHERE pt.user_id=$old");
    $sql("DELETE FROM fr_planner_recur WHERE user_id=$old");
    $sql("DELETE FROM fr_social_links WHERE user_id=$old");
    $sql("DELETE FROM fr_notify_log WHERE user_id=$old");
    $sql("DELETE FROM fr_users WHERE id=$old");
}
foreach (glob('/tmp/qa-v24-cookies*.txt') ?: [] as $f) @unlink($f);
for ($i = 0; $i < 8; $i++) $sql("DELETE FROM fr_settings WHERE `key`='planner_reminded_" . date('Ymd') . "_$i'");
$sql("DELETE FROM fr_settings WHERE `key` LIKE 'planner_reminded_%'");

/* ═══ ۱) نگارش + مهاجرت ۲٫۴ ═══ */
echo "\n═══ ۱) نگارش + تنظیمات ۲٫۴ ═══\n";
$frv = $sql("SELECT `value` FROM fr_settings WHERE `key`='fr_version'");
ok(version_compare($frv, '2.4.0', '>='), 'نگارش DB حداقل ۲٫۴٫۰ است', $frv);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='planner_remind_count'") !== '', 'تنظیم تعداد یادآوری ساخته شد');
$rt = (string)$sql("SELECT `value` FROM fr_settings WHERE `key`='planner_remind_times'");
ok(str_starts_with(ltrim($rt), '[') && json_decode($rt, true) !== null, 'آرایهٔ JSON نوبت‌های یادآوری موجود است', $rt);

/* ═══ ۲) JS تازهٔ ۲۴ ساعته ═══ */
echo "\n═══ ۲) دارایی JS ساعتِ ۲۴ ساعته ═══\n";
$js = (string)file_get_contents('/tmp/feria-smoke/assets/js/feria.js');
ok(str_contains($js, "querySelectorAll('input[data-t24]')"), 'ماسک t24 به feria.js اضافه شده');
ok(str_contains($js, 'data-t24-wrap'), 'منطق نمایش نوبت‌ها بر اساس تعداد در feria.js است');

/* ═══ ۳) ورود مدیر ═══ */
echo "\n═══ ۳) ورود مدیر ═══\n";
[, $alb] = getx('/admin/login.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $alb, $m0);
[, $bA] = post_csrf('/admin/login.php', ['username' => AUSER, 'password' => APASS], $m0[1] ?? '');
ok(str_contains($bA, 'داشبورد') || str_contains($bA, 'خالص درآمد'), 'ورود مدیر موفق');
[, $apg] = getx('/admin/planner.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $apg, $m1);
$ACS = $m1[1] ?? '';
ok($ACS !== '', 'توکن CSRF مدیر');

/* ═══ ۴) admin/planner.php — فرم چندنوبتی ═══ */
echo "\n═══ ۴) admin/planner.php — یادآوری چندنوبتی ═══\n";
ok(str_contains($apg, 'name="planner_remind_count"') && str_contains($apg, 'name="planner_remind_times[]"'), 'فرم تعداد + ساعت‌های نوبت رندر شد');
ok(!str_contains($apg, 'type="time"') && str_contains($apg, 'data-t24'), 'ورودی ۲۴ساعتهٔ قطعی (بدون type=time) در فرم پلنر');
ok(str_contains($apg, '۴ نوبت') || str_contains($apg, 'نوبت در روز'), 'گزینه‌های ۱ تا ۶ نوبت در روز دیده می‌شود');
ok(str_contains($apg, 'data-t24-wrap') && str_contains($apg, '#prCount'), 'اتصال پویای تعداد↔ساعت‌ها (t24-wrap)');

/* ذخیرهٔ ۴ نوبت (درخواست کاربر: مثلاً ۴ بار در روز) */
[, $sv] = post_csrf('/admin/planner.php', [
    'act' => 'save_settings', 'planner_enabled' => '1', 'planner_streak_target' => '7',
    'planner_reward_type' => 'discount', 'planner_reward_percent' => '20', 'planner_reward_message' => '',
    'planner_remind_count' => '4',
    'planner_remind_times[0]' => '06:45', 'planner_remind_times[1]' => '12:30',
    'planner_remind_times[2]' => '17:00', 'planner_remind_times[3]' => '22:15',
], $ACS);
$nt = (string)$sql("SELECT `value` FROM fr_settings WHERE `key`='planner_remind_times'");
ok($nt === '["06:45","12:30","17:00","22:15"]', '۴ نوبت ۲۴ساعته ذخیره و مرتب شد', $nt);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='planner_remind_count'") === '4', 'تعداد ۴ نوبت ذخیره شد');
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='planner_remind_time'") === '06:45', 'سازگاری: اولین نوبت همان planner_remind_time قدیمی است');

/* مرتب‌سازی خودکار ورودی نامرتب */
[, $sv2] = post_csrf('/admin/planner.php', [
    'act' => 'save_settings', 'planner_enabled' => '1', 'planner_streak_target' => '7',
    'planner_reward_type' => 'discount', 'planner_reward_percent' => '20', 'planner_reward_message' => '',
    'planner_remind_count' => '4',
    'planner_remind_times[0]' => '22:15', 'planner_remind_times[1]' => '06:45',
    'planner_remind_times[2]' => '17:00', 'planner_remind_times[3]' => '12:30',
], $ACS);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='planner_remind_times'") === '["06:45","12:30","17:00","22:15"]', 'ورودی نامرتب، مرتب ذخیره می‌شود');

/* ═══ ۵) admin/settings.php — برگهٔ پلنر ═══ */
echo "\n═══ ۵) admin/settings.php?tab=planner ═══\n";
[, $stp] = getx('/admin/settings.php?tab=planner');
ok(str_contains($stp, 'name="planner_remind_count"') && str_contains($stp, 'data-t24'), 'فرم چندنوبتی در تنظیمات کلی هم آمده');
ok(!str_contains($stp, 'type="time"'), 'هیچ type=timeٔ لوکیل‌وابسته در تنظیمات نیست');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $stp, $m2);
[, $sv3] = post_csrf('/admin/settings.php', [
    'act' => 'save', 'planner_remind_count' => '2',
    'planner_remind_times[0]' => '08:00', 'planner_remind_times[1]' => '21:30',
], $m2[1] ?? $ACS);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='planner_remind_times'") === '["08:00","21:30"]', 'ذخیرهٔ ۲ نوبت از تنظیمات کلی هم کار می‌کند');

/* ═══ ۶) کرون یادآوری چندنوبتی ═══ */
echo "\n═══ ۶) کرون یادآوری پلنر (چندنوبتی) ═══\n";
$key = (string)$sql("SELECT `value` FROM fr_settings WHERE `key`='cron_secret'");
[$c403] = http('GET', '/cron/reminders.php?key=bad');
ok($c403 === 403, 'کرون بدون کلید معتبر ۴۰۳ می‌دهد');
[, , $r1] = http('GET', '/cron/reminders.php?key=' . urlencode($key) . '&force=1');
ok(substr_count($r1, ':sent=') === 2, 'هر دو نوبت در اجرای force پردازش شدند', str_replace("\n", ' | ', $r1));
$locks = (int)$sql("SELECT COUNT(*) FROM fr_settings WHERE `key` LIKE 'planner_reminded_" . date('Ymd') . "_%'");
ok($locks === 2, 'قفل روزانهٔ مستقل برای هر نوبت ثبت شد', "locks=$locks");
[, , $r2] = http('GET', '/cron/reminders.php?key=' . urlencode($key));   // بدون force
ok(str_contains($r2, 'already'), 'اجرای عادیِ مجدد: نوبت‌های قفل‌شده already می‌گویند');
[, , $r3] = http('GET', '/cron/reminders.php?key=' . urlencode($key) . '&force=1');
ok(str_contains($r3, ':sent='), 'force برای تست دستی مدیر همیشه اجرا می‌کند (از قفل می‌گذرد)');

/* تلاش ارسال واقعی برای کاربر متصل (بدون توکن واقعی → failed لاگ می‌شود) */
[, $r1a] = api('/api/auth.php', ['action' => 'send_otp', 'mobile' => QA_MOB], '', COOKIE2);
[, $authP] = getx('/auth.php', COOKIE2);
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authP, $m3);
$UCS = $m3[1] ?? '';
[, $r1b] = api('/api/auth.php', ['action' => 'send_otp', 'mobile' => QA_MOB], $UCS, COOKIE2);
[, $r2b] = api('/api/auth.php', ['action' => 'verify_otp', 'mobile' => QA_MOB, 'code' => $r1b['debug_code'] ?? ''], $UCS, COOKIE2);
if (!empty($r2b['needs_register'])) api('/api/auth.php', ['action' => 'register_name', 'full_name' => 'کیوای یادآوری'], $UCS, COOKIE2);
$QAUID = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MOB . "'");
ok($QAUID > 0, 'حساب کاربر QA ساخته شد', "id=$QAUID");
$sql("UPDATE fr_users SET telegram_chat_id='99887766', tg_username='qa.rem24' WHERE id=$QAUID");
$sql("INSERT INTO fr_planner_tasks (user_id,day,title,done) VALUES ($QAUID, '$SITE_TODAY', 'کار معلق یادآوری چندنوبتی', 0)");
for ($i = 0; $i < 8; $i++) $sql("DELETE FROM fr_settings WHERE `key`='planner_reminded_" . date('Ymd') . "_$i'");
[, , $r4] = http('GET', '/cron/reminders.php?key=' . urlencode($key) . '&force=1');
ok(str_contains($r4, 'failed=1'), 'یادآوری برای کاربر متصل تلاش و نتیجه‌اش ثبت شد', str_replace("\n", ' | ', $r4));
$lg = (int)$sql("SELECT COUNT(*) FROM fr_notify_log WHERE user_id=$QAUID AND msg_kind='planner'");
ok($lg >= 1, 'تلاش یادآوری در notify_log ثبت شد', "rows=$lg");
$body = (string)$sql("SELECT body FROM fr_notify_log WHERE user_id=$QAUID AND msg_kind='planner' ORDER BY id DESC LIMIT 1");
ok(str_contains($body, 'صبح بخیر') || str_contains($body, 'ظهر بخیر') || str_contains($body, 'عصر بخیر') || str_contains($body, 'شب بخیر'), 'سلام متناسب با ساعت ۲۴ساعتهٔ نوبت در متن یادآوری', mb_substr($body, 0, 24));

/* ═══ ۷) پنل کاربر — ساعت کاملاً ۲۴ ساعته ═══ */
echo "\n═══ ۷) پنل کاربر ═══\n";
[, $pl] = getx('/panel/planner.php', COOKIE2);
ok(str_contains($pl, 'یادآوری‌های امروز (۲۴ ساعته)'), 'نوبت‌های یادآوری امروز به کاربر نمایش داده می‌شود');
ok(str_contains($pl, '۰۸:۰۰') || str_contains($pl, '۸:۰۰'), 'ساعت نوبت با ارقام فارسیِ ۲۴ساعته دیده می‌شود');
ok(!str_contains($pl, 'type="time"') && str_contains($pl, 'data-t24'), 'فیلد ساعتِ افزودن کار، ورودی قطعی ۲۴ساعته است');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $pl, $m4);
$PLCS = $m4[1] ?? '';
[, $pAdd] = post_csrf('/panel/planner.php', ['act' => 'add', 'day' => $SITE_TODAY, 'title' => 'کار ساعت‌دار ۲۴', 'time_note' => '05:05', 'priority' => 'normal', 'category' => 'personal'], $PLCS, COOKIE2);
ok($sql("SELECT time_note FROM fr_planner_tasks WHERE user_id=$QAUID AND title='کار ساعت‌دار ۲۴'") === '05:05', 'time_note به‌صورت HH:MM ذخیره شد');
[, $recT] = getx('/panel/planner.php?t=recur', COOKIE2);
ok(!str_contains($recT, 'type="time"') && str_contains($recT, 'data-t24'), 'فیلد ساعتِ الگوی تکرارشونده هم ۲۴ساعتهٔ قطعی است');

/* ═══ ۸) مرکز اطلاع‌رسانی — ورودی‌های ۲۴ساعتهٔ جدید ═══ */
echo "\n═══ ۸) admin/notifications.php ═══\n";
[, $an] = getx('/admin/notifications.php');
ok(!str_contains($an, 'type="time"') && str_contains($an, 'data-t24'), 'ورودی‌های نوبت اطلاع‌رسانی هم t24 شدند');
ok(str_contains($an, 'pattern="([01][0-9]|2[0-3]):[0-5][0-9]"'), 'الگوی اعتبارسنجی ۰۰:۰۰ تا ۲۳:۵۹ روی ورودی‌هاست');

/* ═══ ۹) پاک‌سازی پایانی ═══ */
echo "\n── پاک‌سازی پایانی…\n";
if ($QAUID) {
    $sql("DELETE pt FROM fr_planner_tasks pt WHERE pt.user_id=$QAUID");
    $sql("DELETE FROM fr_social_links WHERE user_id=$QAUID");
    $sql("DELETE FROM fr_notify_log WHERE user_id=$QAUID");
    $sql("DELETE FROM fr_users WHERE id=$QAUID");
}
/* بازگشت زمان‌بندی یادآور به حالت استاندارد ۲ نوبتی برای سایر سویت‌ها */
post_csrf('/admin/planner.php', [
    'act' => 'save_settings', 'planner_enabled' => '1', 'planner_streak_target' => '7',
    'planner_reward_type' => 'discount', 'planner_reward_percent' => '20', 'planner_reward_message' => '',
    'planner_remind_count' => '2', 'planner_remind_times[0]' => '08:30', 'planner_remind_times[1]' => '21:00',
], $ACS);
$sql("DELETE FROM fr_settings WHERE `key` LIKE 'planner_reminded_%'");

echo "\n════════════════════════════════════════\n";
echo "  نتیجه: $PASS قبول، $FAIL رد\n";
exit($FAIL ? 1 : 0);
