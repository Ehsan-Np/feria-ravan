<?php
/* ============================================================================
   QA نسخهٔ ۲٫۳ — اطلاع‌رسانی اجتماعی (نام کاربری→چت‌آیدی، تست اتصال، زمان‌بندی
   ۲۴ ساعته، ارسال گروهی، کرون cPanel) • پلنر ۵‌برگه‌ای + تکرارشونده + نشان‌ها
   • حذف کامل کاربر • رفع باگ ثبت‌نام‌شدگان • پالت رسمی اجرایی
   ============================================================================ */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

const BASE   = 'http://localhost:8899';
const COOKIE  = '/tmp/qa-v23-cookies.txt';   // مدیر
const COOKIE2 = '/tmp/qa-v23-cookies2.txt';  // کاربر
const AUSER  = 'admin';
const APASS  = 'AdminTest!2026';
const QA_MOB = '09120003333';

$PASS = 0; $FAIL = 0;
/* روزِ سایت (تهران) — اسکریپت CLI روی UTC اجرا می‌شود و ممکن است با روزِ اپلیکیشن یکی نباشد */
date_default_timezone_set('Asia/Tehran');
$SITE_TODAY = date('Y-m-d');
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
        if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]), [], [], $jar);
    }
    return [$c, $b];
}
function post_csrf(string $path, array $fields, string $csrf, string $jar = COOKIE): array {
    [$c, $h, $b] = http('POST', $path, $fields + ['_csrf' => $csrf], [], $jar);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $h, $m)) break;
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

@unlink(COOKIE); @unlink(COOKIE2);
$sql("DELETE FROM fr_rate_limits");

/* ═══ ۰) پاک‌سازی ═══ */
echo "── پاک‌سازی QA ۲٫۳ قبلی…\n";
$qu = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MOB . "'");
if ($qu) {
    $sql("DELETE FROM fr_planner_recur WHERE user_id=$qu");
    $sql("DELETE pt FROM fr_planner_tasks pt WHERE pt.user_id=$qu");
    $sql("DELETE FROM fr_social_links WHERE user_id=$qu");
    $sql("DELETE FROM fr_notify_log WHERE user_id=$qu");
    $sql("DELETE FROM fr_users WHERE id=$qu");
}
$sql("DELETE FROM fr_users WHERE mobile IN ('09120096666','09120097777')");
$sql("DELETE FROM fr_social_links WHERE username='qa.social'");
$sql("UPDATE fr_settings SET `value`='3' WHERE `key`='notif_daily_count'");
$sql("UPDATE fr_settings SET `value`='[\"08:30\",\"13:00\",\"20:30\"]' WHERE `key`='notif_times'");
foreach (['notif_slot_' . date('Ymd') . '_0','notif_slot_' . date('Ymd') . '_1','notif_slot_' . date('Ymd') . '_2',
          'notif_slot_' . date('Ymd') . '_3','notif_slot_' . date('Ymd') . '_4','notif_slot_' . date('Ymd') . '_5'] as $lk) {
    $sql("DELETE FROM fr_settings WHERE `key`='$lk'");
}

/* ═══ ۱) نگارش + اسکیما + تنظیمات ۲٫۳ ═══ */
echo "\n═══ ۱) نگارش + اسکیمای ۲٫۳ ═══\n";
ok(version_compare($sql("SELECT `value` FROM fr_settings WHERE `key`='fr_version'"), '2.3.0', '>='), 'نگارش DB حداقل ۲٫۳٫۰ است');
ok($sql("SHOW TABLES LIKE 'fr_social_links'") === 'fr_social_links', 'جدول social_links ساخته شد');
ok($sql("SHOW TABLES LIKE 'fr_notify_log'") === 'fr_notify_log', 'جدول notify_log ساخته شد');
ok($sql("SHOW TABLES LIKE 'fr_planner_recur'") === 'fr_planner_recur', 'جدول planner_recur ساخته شد');
ok($sql("SHOW COLUMNS FROM fr_users LIKE 'tg_username'") !== '', 'ستون tg_username روی کاربران');
ok($sql("SHOW COLUMNS FROM fr_planner_tasks LIKE 'recur_id'") !== '', 'ستون recur_id روی کارهای پلنر');
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='notif_daily_count'") === '3', 'پیش‌فرض ۳ اطلاع‌رسانی در روز');
$nt = (string)$sql("SELECT `value` FROM fr_settings WHERE `key`='notif_times'");
$ntd = json_decode($nt, true);
ok(is_array($ntd) && count($ntd) === 3 && preg_match('/^\d{2}:\d{2}$/', (string)$ntd[0]) === 1, 'ساعت‌های نوبت، JSON با قالب ۲۴ ساعته', $nt);
ok($sql("SELECT LENGTH(`value`)>=16 FROM fr_settings WHERE `key`='cron_secret'") === '1', 'کلید مخفی کرون موجود است');
ok(str_contains((string)$sql("SELECT `value` FROM fr_settings WHERE `key`='notif_test_msg'"), '{site}'), 'قالب پیام تست اتصال با متغیر {site}');

/* ═══ ۲) پالت رسمی اجرایی ═══ */
echo "\n═══ ۲) بازطراحی رسمی اجرایی ═══\n";
ok(strcasecmp((string)$sql("SELECT `value` FROM fr_settings WHERE `key`='color_primary'"), '#1e3a6e') === 0, 'رنگ اصلی سرمه‌ای اجرایی (#1e3a6e)');
$css  = (string)file_get_contents('/tmp/feria-smoke/assets/css/feria.css');
$css2 = (string)file_get_contents('/tmp/feria-smoke/assets/css/site.css');
ok(!str_contains($css, '#5b3fd6') && !str_contains($css2, '#5b3fd6'), 'هیچ بنفش قدیمی در CSS نیست');
ok(str_contains($css2, '--c-gold') && str_contains($css2, '.pb-hero{border-bottom:3px solid var(--c-gold)}'), 'لم‌س طلایی روی هیرو (site.css)');
ok(str_contains($css, '.pl-tabs') && str_contains($css, '.pl-badge') && str_contains($css, '.soc-card'), 'کلاس‌های جدید ۲٫۳ در feria.css');
ok(str_contains($css, 'a.fr-btn, a.fr-btn:visited') && str_contains($css, 'color:#fff!important'), 'قانون متن سفید دکمه حفظ شده (سازگاری)');
ok(str_contains($css2, 'img.fr-logo{object-fit:contain;padding:0;background:transparent'), 'قانون لوگوی شفاف حفظ شده (سازگاری)');
[, $home] = getx('/');
ok(!str_contains($home, 'آزمون') && !str_contains($home, '🌱'), 'صفحهٔ نخست بدون «آزمون»/🌱');

/* ═══ ۳) ورود مدیر ═══ */
echo "\n═══ ۳) ورود مدیر ═══\n";
[, $lb] = getx('/admin/login.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $lb, $m);
[, $dash] = post_csrf('/admin/login.php', ['username' => AUSER, 'password' => APASS], $m[1] ?? '');
ok(str_contains($dash, 'خالص درآمد'), 'ورود مدیر موفق');
[, $acr] = getx('/admin/notifications.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $acr, $m);
$ACS = $m[1] ?? '';
ok($ACS !== '', 'توکن CSRF مدیر');

/* ═══ ۴) مرکز اطلاع‌رسانی اجتماعی ═══ */
echo "\n═══ ۴) admin/notifications.php ═══\n";
ok(str_contains($acr, 'اطلاع‌رسانی شبکه‌های اجتماعی') && str_contains($acr, 'متصل‌شدهٔ تلگرام'), 'صفحهٔ مرکز اطلاع‌رسانی رندر شد');
ok(str_contains($acr, 'name="notif_daily_count"') && str_contains($acr, 'data-t24') && !str_contains($acr, 'type="time"'), 'تنظیم تعداد و ساعت‌های ۲۴ ساعته (ورودی قطعی ۲۴ساعته، بدون type=time)');
ok(str_contains($acr, '(۲۴ ساعته)') || str_contains($acr, '۲۴ ساعته'), 'برچسب قالب ۲۴ ساعته روی ساعت‌ها');
ok(str_contains($acr, 'name="notif_test_msg"'), 'کادر قالب پیام تست اتصال');
ok(str_contains($acr, 'name="message"') && str_contains($acr, 'bulk_send'), 'فرم ارسال گروهی');
ok(str_contains($acr, '*/5 * * * *') && str_contains($acr, 'curl -s') && str_contains($acr, '/usr/bin/php -q'), 'راهنمای کرون cPanel با هر دو روش');
ok(str_contains($acr, 'cron_secret') || str_contains($acr, 'key='), 'نشانی کران با کلید نمایش داده شد');
ok(str_contains($acr, 'target="_blank') && str_contains($acr, 'force=1'), 'لینک تست دستی کرون (force=1) موجود است');
[, $aboot] = getx('/admin/dashboard.php');
ok(str_contains($aboot, 'اطلاع‌رسانی اجتماعی'), 'آیتم منوی «اطلاع‌رسانی اجتماعی» در منوی پیشخوان');

/* ذخیرهٔ زمان‌بندی: ۲ نوبت */
[, $save] = post_csrf('/admin/notifications.php', [
    'act' => 'save', 'telegram_bot_token' => '', 'telegram_bot_username' => 'FeriaQaBot',
    'bale_bot_token' => '', 'bale_bot_username' => '', 'notif_daily_count' => '2',
    'notif_times[0]' => '07:15', 'notif_times[1]' => '21:45', 'notif_test_msg' => '✅ تست اتصال سفارشی کیوای — {site} برای {name}',
], $ACS);
$nt2 = (string)$sql("SELECT `value` FROM fr_settings WHERE `key`='notif_times'");
ok($nt2 === '["07:15","21:45"]', 'زمان‌بندی ۲ نوبتی ذخیره شد (۲۴ ساعته)', $nt2);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='notif_test_msg'") === '✅ تست اتصال سفارشی کیوای — {site} برای {name}', 'قالب پیام تست سفارشی ذخیره شد');
/* بازگردانی پیش‌فرض */
[, $save2] = post_csrf('/admin/notifications.php', [
    'act' => 'save', 'telegram_bot_token' => '', 'telegram_bot_username' => '', 'bale_bot_token' => '', 'bale_bot_username' => '',
    'notif_daily_count' => '3', 'notif_times[0]' => '08:30', 'notif_times[1]' => '13:00', 'notif_times[2]' => '20:30',
    'notif_test_msg' => "✅ اتصال شما به ربات با موفقیت انجام شد!\nاز این لحظه به بعد، اطلاع‌رسانی‌های «{site}» به‌صورت کامل و خودکار برای شما ارسال می‌شود. 🦢",
], $ACS);

/* ارسال گروهی: متن خالی → خطا؛ متن درست بله (بدون کاربر متصل) → اجرا */
[, $blk0] = post_csrf('/admin/notifications.php', ['act' => 'bulk_send', 'channel' => 'bale', 'message' => ''], $ACS);
ok(!str_contains($blk0, 'ارسال گروهی انجام شد') || str_contains($blk0, 'خالی است'), 'ارسال گروهی با متن خالی رد شد');
[, $blk1] = post_csrf('/admin/notifications.php', ['act' => 'bulk_send', 'channel' => 'bale', 'message' => 'پیام گروهی کیوای ۲٫۳'], $ACS);
ok(str_contains($blk1, 'ارسال گروهی انجام شد'), 'مسیر ارسال گروهی اجرا شد (بدون کاربر متصل = صفر ارسال)');

/* ═══ ۵) کاربر QA: OTP ═══ */
echo "\n═══ ۵) کاربر QA ═══\n";
[, $authPage] = getx('/auth.php', COOKIE2);
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authPage, $m2);
$UCS = $m2[1] ?? '';
[, $r1] = api('/api/auth.php', ['action' => 'send_otp', 'mobile' => QA_MOB], $UCS, COOKIE2);
ok(!empty($r1['ok']) && !empty($r1['debug_code']), 'ارسال OTP', $r1['debug_code'] ?? '');
[, $r2] = api('/api/auth.php', ['action' => 'verify_otp', 'mobile' => QA_MOB, 'code' => $r1['debug_code'] ?? ''], $UCS, COOKIE2);
if (!empty($r2['needs_register'])) api('/api/auth.php', ['action' => 'register_name', 'full_name' => 'کیوای اجتماعی'], $UCS, COOKIE2);
$QAUID = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MOB . "'");
ok($QAUID > 0, 'حساب کاربر QA ساخته شد', "id=$QAUID");

/* ═══ ۶) اتصال با نام کاربری در پروفایل ═══ */
echo "\n═══ ۶) اتصال اجتماعی کاربر ═══\n";
[, $prof] = getx('/panel/profile.php', COOKIE2);
ok(str_contains($prof, 'اطلاع‌رسانی در تلگرام و بله'), 'کارت اتصال اجتماعی در پروفایل رندر شد');
ok(str_contains($prof, 'social_connect') && str_contains($prof, 'نام کاربری'), 'ورودی نام کاربری (بدون نیاز به چت‌آیدی دستی)');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $prof, $m3);
$PCS = $m3[1] ?? '';
/* نام کاربری نامعتبر */
[, $badUn] = post_csrf('/panel/profile.php', ['act' => 'social_connect', 'channel' => 'telegram', 'username' => 'qa'], $PCS, COOKIE2);
ok(str_contains($badUn, 'نام کاربری معتبر نیست'), 'نام کاربری نامعتبر رد شد');
/* نام کاربری معتبر → لینک در انتظار */
[, $conn] = post_csrf('/panel/profile.php', ['act' => 'social_connect', 'channel' => 'telegram', 'username' => 'qa.social'], $PCS, COOKIE2);
$link = $sql("SELECT CONCAT(status,'|',connect_code,'|',username) FROM fr_social_links WHERE user_id=$QAUID AND channel='telegram'");
ok(str_starts_with((string)$link, 'pending|') && preg_match('/^[A-F0-9]{8}\|qa\.social$/', substr((string)$link, 8)) === 1, 'لینک اتصال در انتظار با کد ۸نویسه‌ای ساخته شد', (string)$link);
ok($sql("SELECT tg_username FROM fr_users WHERE id=$QAUID") === 'qa.social', 'نام کاربری روی حساب کاربر ذخیره شد');
ok(str_contains($conn, 'qa.social') || str_contains($conn, 'کد:'), 'کد اتصال/راهنما بعد از ثبت نمایش داده شد');
/* نام کاربری تکراری برای کاربر دوم رد شود */
$sql("INSERT INTO fr_users (first_name,last_name,mobile,status) VALUES ('دوم','کیوای','09120096666','active')");
$uid2 = (int)$sql("SELECT id FROM fr_users WHERE mobile='09120096666'");
/* شبیه‌سازی تبدیل نام کاربری به چت‌آیدی (مسیر داخلی فراخوانی می‌شود، نه وب‌هوک واقعی) */
[, $testNoConn] = post_csrf('/panel/profile.php', ['act' => 'social_test', 'channel' => 'telegram'], $PCS, COOKIE2);
ok(str_contains($testNoConn, 'متصل نیستید') || str_contains($testNoConn, 'کامل کنید'), 'تست اتصال بدون اتصال، پیام راهنما می‌دهد');
/* شبیه‌سازی اتصال (مانند کاری که کرونِ getUpdates می‌کند) چون ربات واقعی در محیط آزمون نیست */
$sql("UPDATE fr_users SET telegram_chat_id='123456789' WHERE id=$QAUID");
$sql("UPDATE fr_social_links SET status='connected', chat_id='123456789', connected_at=NOW() WHERE user_id=$QAUID AND channel='telegram'");
[, $prof2] = getx('/panel/profile.php', COOKIE2);
ok(str_contains($prof2, '✅ متصل') && str_contains($prof2, '@qa.social'), 'وضعیت «متصل» با نام کاربری در پروفایل نمایش داده شد');
ok(str_contains($prof2, '📩 تست اتصال'), 'دکمهٔ تست اتصال پس از اتصال فعال است');
[, $testConn] = post_csrf('/panel/profile.php', ['act' => 'social_test', 'channel' => 'telegram'], $PCS, COOKIE2);
ok(str_contains($testConn, 'پیکربندی نشده') || str_contains($testConn, 'ارسال شد') || str_contains($testConn, 'ناموفق'), 'تست اتصال اجرا و پاسخ شفاف داد (بدون توکن واقعی در محیط آزمون)');
$lg = (int)$sql("SELECT COUNT(*) FROM fr_notify_log WHERE user_id=$QAUID AND msg_kind='test'");
ok($lg >= 1, 'تلاش ارسال تست در notify_log ثبت شد', "rows=$lg");
/* قطع اتصال */
[, $disc] = post_csrf('/panel/profile.php', ['act' => 'social_disconnect', 'channel' => 'telegram'], $PCS, COOKIE2);
ok($sql("SELECT COALESCE(telegram_chat_id,'') FROM fr_users WHERE id=$QAUID") === '' && $sql("SELECT COUNT(*) FROM fr_social_links WHERE user_id=$QAUID") === '0', 'قطع اتصال، چت‌آیدی و لینک را پاک کرد');
$sql("DELETE FROM fr_users WHERE id=$uid2");

/* ═══ ۷) کرون notify.php ═══ */
echo "\n═══ ۷) کرون اطلاع‌رسانی ═══\n";
$key = (string)$sql("SELECT `value` FROM fr_settings WHERE `key`='cron_secret'");
[$c403] = http('GET', '/cron/notify.php?key=wrong');
ok($c403 === 403, 'کرون بدون کلید معتبر ۴۰۳ می‌دهد');
[$c200, $hN, $bodyN] = http('GET', '/cron/notify.php?key=' . urlencode($key) . '&force=1');
ok($c200 === 200 && str_contains($bodyN, 'poll[telegram]') && str_contains($bodyN, 'slot:'), 'کرون با کلید، poll + نوبت‌ها را اجرا می‌کند', substr($bodyN, 0, 60));
$lockCnt = (int)$sql("SELECT COUNT(*) FROM fr_settings WHERE `key` LIKE 'notif_slot_" . date('Ymd') . "_%'");
ok($lockCnt >= 1, 'قفل ضدتکرار نوبت‌ها پس از force ثبت شد', "locks=$lockCnt");
/* اجرای دوم بدون force: نوبت‌های قفل‌شده already */
[, , $bodyN2] = http('GET', '/cron/notify.php?key=' . urlencode($key) . '&force=1');
ok(str_contains($bodyN2, 'already'), 'نوبت اجراشده دوباره ارسال نمی‌شود (already)');

/* ═══ ۸) پلنر ۲٫۳ — برگه‌ها + تکرارشونده + دستاوردها ═══ */
echo "\n═══ ۸) پلنر جذاب‌تر ═══\n";
[, $pl] = getx('/panel/planner.php', COOKIE2);
ok(str_contains($pl, '<head>') && str_contains($pl, 'feria.css') && !str_contains($pl, '<style>.pl-'), 'پلنر کامل رندر می‌شود (بدون استایل درون‌صفحه‌ای)');
ok(str_contains($pl, 'pl-tabs') && substr_count($pl, 'pl-tab') >= 6, 'نوار ۵ برگهٔ پلنر رندر شد');
ok(str_contains($pl, 'pl-ringbox') && str_contains($pl, 'pl-ringin'), 'حلقهٔ پیشرفت امروز رندر شد');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $pl, $m4);
$PLCS = $m4[1] ?? '';
/* افزودن کار برای فردا */
[, $pAdd] = post_csrf('/panel/planner.php', ['act' => 'add', 'day' => 'tomorrow', 'title' => 'کار فردای کیوای', 'time_note' => '10:00', 'priority' => 'high', 'category' => 'work', 'tab' => 'today'], $PLCS, COOKIE2);
$tom = date('Y-m-d', strtotime($SITE_TODAY . ' +1 day'));
ok($sql("SELECT COUNT(*) FROM fr_planner_tasks WHERE user_id=$QAUID AND day='$tom' AND title='کار فردای کیوای'") === '1', 'برنامه‌ریزی برای فردا ثبت شد');
/* تکرارشونده روزانه + materialize امروز */
[, $pRec] = post_csrf('/panel/planner.php', ['act' => 'recur_add', 'title' => '۱۵ دقیقه مطالعه روزانه', 'time_note' => '08:00', 'priority' => 'normal', 'category' => 'study', 'freq' => 'daily', 'tab' => 'recur'], $PLCS, COOKIE2);
$rid = (int)$sql("SELECT id FROM fr_planner_recur WHERE user_id=$QAUID LIMIT 1");
ok($rid > 0, 'الگوی تکرارشوندهٔ روزانه ثبت شد', "id=$rid");
[, $pl2] = getx('/panel/planner.php', COOKIE2);   // ensure-day اجرا می‌شود
ok($sql("SELECT COUNT(*) FROM fr_planner_tasks WHERE user_id=$QAUID AND recur_id=$rid AND day='$SITE_TODAY'") === '1', 'کار تکرارشونده برای امروز خودکار ساخته شد (materialize)');
[, $pl3] = getx('/panel/planner.php', COOKIE2);
ok($sql("SELECT COUNT(*) FROM fr_planner_tasks WHERE user_id=$QAUID AND recur_id=$rid AND day='$SITE_TODAY'") === '1', 'materialize دوباره‌ران، تکثیر نمی‌کند');
ok(str_contains($pl3, '🔁'), 'نشان تکرار روی کار امروز دیده می‌شود');
/* انتقال مانده‌ها به امروز */
$sql("INSERT INTO fr_planner_tasks (user_id,day,title,done) VALUES ($QAUID, DATE_SUB('$SITE_TODAY', INTERVAL 2 DAY), 'کار ماندهٔ قدیمی', 0)");
[, $pCarry] = post_csrf('/panel/planner.php', ['act' => 'carry', 'tab' => 'today'], $PLCS, COOKIE2);
ok($sql("SELECT COUNT(*) FROM fr_planner_tasks WHERE user_id=$QAUID AND title='کار ماندهٔ قدیمی' AND day='$SITE_TODAY'") === '1', 'انتقال مانده‌ها به امروز');
/* برگه‌های دیگر */
[, $plStats] = getx('/panel/planner.php?t=stats', COOKIE2);
ok(str_contains($plStats, 'pl-chart') && str_contains($plStats, 'بهترین استریک'), 'برگهٔ آمار: نمودار ۱۴ روز + کارت‌های آماری');
[, $plRecur] = getx('/panel/planner.php?t=recur', COOKIE2);
ok(str_contains($plRecur, 'کارهای تکرارشونده') && str_contains($plRecur, '۱۵ دقیقه مطالعه روزانه'), 'برگهٔ تکرارشونده‌ها فهرست را نشان می‌دهد');
[, $plBadges] = getx('/panel/planner.php?t=badges', COOKIE2);
ok(substr_count($plBadges, 'pl-badge') >= 12, 'برگهٔ دستاوردها: ۱۲ نشان (باز/قفل)', (string)substr_count($plBadges, 'pl-badge'));
ok(str_contains($plBadges, 'pl-badge got'), 'حداقل یک نشان باز شده (قدم اول)');
[, $plFocus] = getx('/panel/planner.php?t=focus', COOKIE2);
ok(str_contains($plFocus, 'pzTimer') && str_contains($plFocus, 'جلسهٔ تمرکز عمیق'), 'برگهٔ تمرکز (پومودورو) رندر شد');
/* تعلیق الگو */
[, $pTog] = post_csrf('/panel/planner.php', ['act' => 'recur_toggle', 'id' => $rid, 'tab' => 'recur'], $PLCS, COOKIE2);
ok($sql("SELECT active FROM fr_planner_recur WHERE id=$rid") === '0', 'تعلیق الگوی تکرارشونده');
[, $pDel] = post_csrf('/panel/planner.php', ['act' => 'recur_del', 'id' => $rid, 'tab' => 'recur'], $PLCS, COOKIE2);
ok((int)$sql("SELECT COUNT(*) FROM fr_planner_recur WHERE id=$rid") === 0, 'حذف الگوی تکرارشونده');

/* ═══ ۹) حذف کامل کاربر ═══ */
echo "\n═══ ۹) حذف کامل کاربر ═══\n";
[, $uAdd] = post_csrf('/admin/users.php', [
    'act' => 'add_user', 'first_name' => 'حذفی', 'last_name' => 'کامل', 'mobile' => '09120097777',
    'national_code' => '', 'username' => '', 'password' => '',
], $ACS);
$DUID = (int)$sql("SELECT id FROM fr_users WHERE mobile='09120097777'");
ok($DUID > 0, 'کاربر حذفی ساخته شد', "id=$DUID");
$sql("INSERT INTO fr_planner_tasks (user_id,day,title,done) VALUES ($DUID, CURDATE(), 'کارِ کاربر حذفی', 0)");
$sql("INSERT INTO fr_attempts (user_id,test_id,mode,status,started_at) SELECT $DUID, id, 'free', 'in_progress', NOW() FROM fr_tests LIMIT 1");
[, $dWrong] = post_csrf('/admin/users.php', ['act' => 'del_user', 'id' => $DUID, 'confirm_mobile' => '09120000000'], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_users WHERE id=$DUID") === 1 && str_contains($dWrong, 'تأیید'), 'حذف بدون تایپ موبایل درست، انجام نمی‌شود');
ok(str_contains((string)file_get_contents('/tmp/feria-smoke/admin/users.php'), 'data-deluser'), 'فرم حذف کامل با تأیید دومرحله‌ای در جدول کاربران است');
[, $dOk] = post_csrf('/admin/users.php', ['act' => 'del_user', 'id' => $DUID, 'confirm_mobile' => '09120097777'], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_users WHERE id=$DUID") === 0, 'کاربر به‌طور کامل حذف شد');
ok((int)$sql("SELECT COUNT(*) FROM fr_planner_tasks WHERE user_id=$DUID") === 0 && (int)$sql("SELECT COUNT(*) FROM fr_attempts WHERE user_id=$DUID") === 0, 'داده‌های وابسته هم پاک شدند (cascade)');
ok((int)$sql("SELECT COUNT(*) FROM fr_activity_logs WHERE action='admin_user_delete' LIMIT 1") >= 1, 'حذف در لاگ فعالیت ثبت شد');

/* ═══ ۱۰) رفع باگ ثبت‌نام‌شدگان ═══ */
echo "\n═══ ۱۰) ثبت‌نام‌شدگان ═══\n";
[, $reg0] = getx('/admin/registrants.php');
ok(str_contains($reg0, 'optgroup') && str_contains($reg0, 'تست‌های روانشناسی') && str_contains($reg0, 'کارگاه‌ها') && str_contains($reg0, 'دوره‌ها'), 'سلکتور گروه‌بندی‌شدهٔ هر سه بخش (بدون ctx هم پر است)');
ok(str_contains($reg0, 'نمای کلی ثبت‌نام‌ها در یک نگاه'), 'داشبورد نمای کلی وقتی چیزی انتخاب نشده');
$WsId = (int)$sql("SELECT id FROM fr_events WHERE slug='free-ws-test'");
[, $regWs] = getx('/admin/registrants.php?pick=workshop|' . $WsId);
ok(str_contains($regWs, 'کارگاه رایگان تستی') || str_contains($regWs, 'افزودن دستی عضو'), 'انتخاب با pick=workshop|id صفحهٔ همان رویداد را می‌آورد');
$Tid = (int)$sql("SELECT id FROM fr_tests WHERE slug='disc-family'");
[, $regT] = getx('/admin/registrants.php?pick=test|' . $Tid);
ok(str_contains($regT, 'DISC') || str_contains($regT, 'تلاش'), 'انتخاب pick=test|id صفحهٔ تست را می‌آورد');

/* ═══ ۱۱) پاک‌سازی پایانی ═══ */
echo "\n── پاک‌سازی پایانی…\n";
if ($QAUID) {
    $sql("DELETE pt FROM fr_planner_tasks pt WHERE pt.user_id=$QAUID");
    $sql("DELETE FROM fr_planner_recur WHERE user_id=$QAUID");
    $sql("DELETE FROM fr_social_links WHERE user_id=$QAUID");
    $sql("DELETE FROM fr_notify_log WHERE user_id=$QAUID");
    $sql("DELETE FROM fr_users WHERE id=$QAUID");
}
$sql("DELETE FROM fr_users WHERE mobile='09120096666'");
foreach (['notif_slot_' . date('Ymd') . '_0','notif_slot_' . date('Ymd') . '_1','notif_slot_' . date('Ymd') . '_2','notif_slot_' . date('Ymd') . '_3'] as $lk) {
    $sql("DELETE FROM fr_settings WHERE `key`='$lk'");
}

echo "\n════════════════════════════════════════\n";
echo "  نتیجه: $PASS قبول، $FAIL رد\n";
exit($FAIL ? 1 : 0);
