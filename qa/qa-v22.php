<?php
/* ============================================================================
   QA نسخهٔ ۲٫۲ — ایموجی قو 🦢 • اصلاح کامل پلنر + مدیریت پلنر • نام در خبرنامه
   • ۴ نماد اعتماد + HTML خام • تفکیک کارگاه/دوره در پنل • «تست روانشناسی»
   • کد تخفیف مشاوره ۸۰/۲۰ • ورود با نام کاربری/رمز • لوگوی شفاف • رنگ fr-btn
   • افزودن کاربر توسط مدیر • ارتقا/تنزیل مدیر از کاربران • بازقیمت‌گذاری بازار
   • دیدگاه/پرسش باز برای همه در دوره‌ها (نظر تست = فقط شرکت‌کنندگان)
   ============================================================================ */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

const BASE   = 'http://localhost:8899';
const COOKIE  = '/tmp/qa-v22-cookies.txt';    // مدیر
const COOKIE2 = '/tmp/qa-v22-cookies2.txt';   // کاربر
const AUSER  = 'admin';
const APASS  = 'AdminTest!2026';
const QA_MOB = '09120005555';   // کاربر اصلی QA ۲٫۲
const QA_MB2 = '09120004444';   // کاربر ساخته‌شده توسط مدیر
const NL_MOB = '09303334455';   // مشترک خبرنامهٔ ۲٫۲

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
function getx_nofollow(string $path, string $jar = COOKIE): array {
    return http('GET', $path, [], [], $jar);
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

@unlink(COOKIE); @unlink(COOKIE2);
$sql("DELETE FROM fr_rate_limits");

/* ═══ ۰) پاک‌سازی QA قبلی ═══ */
echo "── پاک‌سازی QA نسخهٔ ۲٫۲ قبلی…\n";
foreach ([QA_MOB, QA_MB2] as $m) {
    $qu = (int)$sql("SELECT id FROM fr_users WHERE mobile='$m'");
    if ($qu) {
        $sql("DELETE FROM fr_admins WHERE user_id=$qu");
        $sql("DELETE FROM fr_reviews WHERE user_id=$qu");
        $sql("DELETE pt FROM fr_planner_tasks pt WHERE pt.user_id=$qu");
        $sql("DELETE er FROM fr_event_registrations er WHERE er.user_id=$qu");
        $sql("DELETE FROM fr_users WHERE id=$qu");
    }
}
$sql("DELETE FROM fr_admins WHERE username LIKE 'qa22-%' OR username='qa.admin2'");
$sql("DELETE FROM fr_newsletter WHERE mobile='" . NL_MOB . "'");
$sql("DELETE FROM fr_reviews WHERE body LIKE '%کیوای ۲٫۲%'");
foreach (['footer_badge1_html','footer_badge2_html','footer_badge3_html','footer_badge4_html'] as $k) {
    $sql("UPDATE fr_settings SET `value`='' WHERE `key`='$k'");
}
$sql("UPDATE fr_settings SET `value`='1' WHERE `key`='reviews_auto_approve'");
$sql("UPDATE fr_settings SET `value`='7' WHERE `key`='planner_streak_target'");
$sql("UPDATE fr_settings SET `value`='1' WHERE `key`='planner_enabled'");

/* ═══ ۱) نگارش + ری‌برند + ایموجی قو ═══ */
echo "\n═══ ۱) نگارش ۲٫۲ + ری‌برند ═══\n";
ok(version_compare($sql("SELECT `value` FROM fr_settings WHERE `key`='fr_version'"), '2.2.0', '>='), 'نگارش DB حداقل ۲٫۲٫۰ است');
[, $home] = getx('/');
[, $tests] = getx('/tests.php');
ok(str_contains($tests, 'تست‌های روانشناسی') && !str_contains($tests, 'آزمون'), 'آرشیو تست‌ها: «تست روانشناسی» و بدون «آزمون»');
ok(!str_contains($home, '🌱'), 'صفحهٔ نخست (مهمان) دیگر 🌱 ندارد');
[, $authP] = getx('/auth.php');
ok(str_contains($authP, 'نام کاربری، موبایل یا کد ملی'), 'برگهٔ ورود: لیبل سه‌حالتهٔ هویت');
$css = (string)file_get_contents('/tmp/feria-smoke/assets/css/feria.css');
ok(str_contains($css, 'a.fr-btn, a.fr-btn:visited') && str_contains($css, 'color:#fff!important'), 'قانون رنگ متن دکمه‌های fr-btn سفید شد');
$css2 = (string)file_get_contents('/tmp/feria-smoke/assets/css/site.css');
ok(str_contains($css2, 'img.fr-logo{object-fit:contain;padding:0;background:transparent'), 'قانون لوگوی شفاف (بدون جعبه/پس‌زمینه) در CSS');

/* ═══ ۲) ورود مدیر ═══ */
echo "\n═══ ۲) ورود مدیر ═══\n";
[, $lb] = getx('/admin/login.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $lb, $m);
[, $dash] = post_csrf('/admin/login.php', ['username' => AUSER, 'password' => APASS], $m[1] ?? '');
ok(str_contains($dash, 'خالص درآمد'), 'ورود مدیر موفق');
[, $acr] = getx('/admin/roles.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $acr, $m);
$ACS = $m[1] ?? '';
ok($ACS !== '', 'توکن CSRF مدیر');
$SELF = (int)$sql("SELECT id FROM fr_admins WHERE username='" . AUSER . "'");

/* ═══ ۳) کاربر QA: OTP + ثبت‌نام ═══ */
echo "\n═══ ۳) کاربر QA ═══\n";
[, $authPage] = getx('/auth.php', COOKIE2);
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authPage, $m2);
$UCS = $m2[1] ?? '';
ok($UCS !== '', 'توکن CSRF کاربر');
[, $r1] = api('/api/auth.php', ['action' => 'send_otp', 'mobile' => QA_MOB], $UCS, COOKIE2);
ok(!empty($r1['ok']) && !empty($r1['debug_code']), 'ارسال OTP موفق', $r1['debug_code'] ?? '');
[, $r2] = api('/api/auth.php', ['action' => 'verify_otp', 'mobile' => QA_MOB, 'code' => $r1['debug_code'] ?? ''], $UCS, COOKIE2);
if (!empty($r2['needs_register'])) {
    api('/api/auth.php', ['action' => 'register_name', 'full_name' => 'کیوای دوووم'], $UCS, COOKIE2);
}
$QAUID = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MOB . "'");
ok($QAUID > 0, 'حساب کاربر QA ساخته/یافته شد', "id=$QAUID");
[, $homeIn] = getx('/', COOKIE2);
ok(str_contains($homeIn, '🦢') && !str_contains($homeIn, '🌱'), 'صفحهٔ نخست کاربر واردشده: ایموجی قو 🦢');
$sql("UPDATE fr_users SET national_code='0012345678' WHERE id=$QAUID");

/* ═══ ۴) تفکیک کارگاه/دوره در پنل کاربری ═══ */
echo "\n═══ ۴) تفکیک کارگاه‌ها و دوره‌ها در پنل ═══\n";
[$cEv, $hEv] = getx_nofollow('/panel/events.php', COOKIE2);
ok($cEv === 302 && str_contains($hEv, 'workshops.php'), 'events.php → ریدایرکت به workshops.php');
[$cEv2, $hEv2] = getx_nofollow('/panel/events.php?kind=course', COOKIE2);
ok($cEv2 === 302 && str_contains($hEv2, 'courses.php'), 'events.php?kind=course → ریدایرکت به courses.php');
[, $pWs] = getx('/panel/workshops.php', COOKIE2);
[, $pCo] = getx('/panel/courses.php', COOKIE2);
ok(str_contains($pWs, 'کارگاه') && str_contains($pWs, 'workshops.php') && str_contains($pWs, 'courses.php'), 'منوی دو گزینهٔ جدا در پنل (کارگاه‌ها | دوره‌ها)');
ok(str_contains($pWs, '/panel/workshops.php') && str_contains($pCo, '/panel/courses.php'), 'لینک‌های هر صفحه خود‌ارجاع و تفکیک‌شده');

/* ═══ ۵) پلنر روزانه: اصلاح استایل + مدیریت ═══ */
echo "\n═══ ۵) پلنر روزانه ═══\n";
[, $pl] = getx('/panel/planner.php', COOKIE2);
ok(str_contains($pl, '<head>') && str_contains($pl, 'feria.css'), 'پلنر با ساختار کامل سند (render_header) رندر می‌شود');
ok(str_contains($pl, 'pl-hero') && !str_contains($pl, '<style>.pl-'), 'استایل‌های پلنر به feria.css منتقل شده‌اند (بدون <style> درون‌صفحه‌ای)');
ok(str_contains($css, '.pl-hero') && str_contains($css, '.pl-addgrid'), 'کلاس‌های pl-* در feria.css موجودند');
[, $apl] = getx('/admin/planner.php');
ok(str_contains($apl, 'برنامهٔ روزانه') && str_contains($apl, 'planner_streak_target'), 'صفحهٔ مدیریت پلنر در پیشخوان رندر شد');
ok(str_contains($apl, 'cron') || str_contains($apl, 'فراخوانی'), 'آدرس/راهنمای کرون در صفحهٔ مدیریت پلنر دیده می‌شود');
[, $plSave] = post_csrf('/admin/planner.php', [
    'act' => 'save_settings', 'planner_enabled' => '1', 'planner_streak_target' => '14',
    'planner_reward_type' => 'discount', 'planner_reward_percent' => '25',
    'planner_reward_message' => 'آفرین به استمرارت!', 'planner_remind_time' => '09:00',
], $ACS);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='planner_streak_target'") === '14'
   && $sql("SELECT `value` FROM fr_settings WHERE `key`='planner_reward_percent'") === '25', 'ذخیرهٔ تنظیمات پلنر از پیشخوان اعمال شد');
ok(str_contains($plSave, 'ذخیره شد'), 'پیام موفقیت ذخیرهٔ تنظیمات پلنر');
$sql("UPDATE fr_settings SET `value`='7' WHERE `key`='planner_streak_target'");
$sql("UPDATE fr_settings SET `value`='20' WHERE `key`='planner_reward_percent'");

/* ═══ ۶) خبرنامه با نام و نام خانوادگی ═══ */
echo "\n═══ ۶) خبرنامه با نام ═══\n";
[, $n0] = api('/api/newsletter.php', ['action' => 'subscribe', 'mobile' => NL_MOB], $UCS, COOKIE2);
ok(empty($n0['ok']) && !empty($n0['need_name']), 'عضویت بدون نام رد شد (need_name)', $n0['message'] ?? '');
[, $n1] = api('/api/newsletter.php', ['action' => 'subscribe', 'mobile' => NL_MOB, 'name' => 'مشترک کیوای دو'], $UCS, COOKIE2);
ok(!empty($n1['ok']), 'عضویت با نام موفق بود');
ok($sql("SELECT name FROM fr_newsletter WHERE mobile='" . NL_MOB . "'") === 'مشترک کیوای دو', 'نام مشترک در دیتابیس ثبت شد');
[, $nlPg] = getx('/admin/newsletter.php');
ok(str_contains($nlPg, 'مشترک کیوای دو'), 'نام مشترک در پیشخوان خبرنامه نمایش داده می‌شود');
[, $homeNl] = getx('/');
ok(str_contains($homeNl, 'id="nlName"'), 'فیلد نام در فرم خبرنامهٔ فوتر موجود است');

/* ═══ ۷) نمادهای اعتماد: ۴ جایگاه + HTML خام ═══ */
echo "\n═══ ۷) نمادهای اعتماد (۴ جایگاه) ═══\n";
ok(substr_count($homeNl, 'fb-slot') === 4, 'چهار جایگاه نماد در فوتر رندر شد', (string)substr_count($homeNl, 'fb-slot'));
ok(str_contains($homeNl, 'ساماندهی') && str_contains($homeNl, 'زرین‌پال') && str_contains($homeNl, 'انجمن روانشناسی'), 'عناوین پیش‌فرض ۴ نماد هم‌تراز با ۲٫۲');
$badgeHtml = '<a href="https://trustseal.enamad.ir/?id=qa" target="_blank"><img src="https://example.com/qa-enamad.png" alt="اینماد QA"></a>';
[, $sBadge] = post_csrf('/admin/settings.php?tab=footer', [
    'act' => 'save',
    'footer_badge4_html' => $badgeHtml,
    'footer_badge4_alt'  => 'اینماد نمونهٔ QA',
], $ACS);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='footer_badge4_html'") === $badgeHtml, 'کد HTML نماد ۴ بدون تحریف ذخیره شد');
[, $homeB] = getx('/');
ok(str_contains($homeB, $badgeHtml) && !str_contains($homeB, '&lt;a href=&quot;https://trustseal'), 'کد HTML نماد به‌صورت خام (بدون escape) رندر شد');
[, $fTab] = getx('/admin/settings.php?tab=footer');
ok(substr_count($fTab, 'footer_badge') >= 16 && str_contains($fTab, 'footer_badge4_html'), 'هر ۴ نماد در تب تنظیمات فوتر قابل ویرایش‌اند');
/* بازنشانی نماد ۴ */
$sql("UPDATE fr_settings SET `value`='' WHERE `key`='footer_badge4_html'");
$sql("UPDATE fr_settings SET `value`='انجمن روانشناسی' WHERE `key`='footer_badge4_alt'");

/* ═══ ۸) نام کاربری + رمز عبور کاربر ═══ */
echo "\n═══ ۸) نام کاربری و رمز عبور ═══\n";
[, $prof] = getx('/panel/profile.php', COOKIE2);
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $prof, $m3);
$PCS = $m3[1] ?? '';
ok(str_contains($prof, 'نام کاربری و رمز عبور'), 'کارت «نام کاربری و رمز عبور» در پروفایل رندر شد');
[, $setB] = post_csrf('/panel/profile.php', [
    'act' => 'set_password', 'username' => 'qa.user22', 'password' => 'QaPass2026!', 'password2' => 'QaPass2026!',
], $PCS, COOKIE2);
ok($sql("SELECT username FROM fr_users WHERE id=$QAUID") === 'qa.user22', 'نام کاربری در پروفایل ثبت شد');
ok(str_contains($setB, 'با موفقیت ثبت شد'), 'پیام موفقیت ثبت نام کاربری/رمز');
/* نام کاربری نامعتبر */
[, $badUn] = post_csrf('/panel/profile.php', [
    'act' => 'set_password', 'username' => 'qa', 'password' => '', 'password2' => '',
], $PCS, COOKIE2);
ok(str_contains($badUn, '۴ تا ۳۰ نویسه') || str_contains($badUn, 'لاتین'), 'نام کاربری کوتاه/نامعتبر رد شد');
/* ورود با نام کاربری */
@unlink(COOKIE2);
[, $authPage2] = getx('/auth.php', COOKIE2);
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authPage2, $m4);
$LCS = $m4[1] ?? '';
[, $pl1] = api('/api/auth.php', ['action' => 'password_login', 'identity' => 'qa.user22', 'password' => 'QaPass2026!'], $LCS, COOKIE2);
ok(!empty($pl1['ok']), 'ورود با نام کاربری + رمز ثابت', $pl1['message'] ?? '');
/* ورود با موبایل */
@unlink(COOKIE2);
[, $authPage3] = getx('/auth.php', COOKIE2);
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authPage3, $m5);
$LCS2 = $m5[1] ?? '';
[, $pl2] = api('/api/auth.php', ['action' => 'password_login', 'identity' => QA_MOB, 'password' => 'QaPass2026!'], $LCS2, COOKIE2);
ok(!empty($pl2['ok']), 'ورود با موبایل + رمز ثابت');
/* ورود با کد ملی */
@unlink(COOKIE2);
[, $authPage4] = getx('/auth.php', COOKIE2);
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authPage4, $m6);
$LCS3 = $m6[1] ?? '';
[, $pl3] = api('/api/auth.php', ['action' => 'password_login', 'identity' => '0012345678', 'password' => 'QaPass2026!'], $LCS3, COOKIE2);
ok(!empty($pl3['ok']), 'ورود با کد ملی + رمز ثابت');
/* رمز اشتباه */
@unlink(COOKIE2);
[, $authPage5] = getx('/auth.php', COOKIE2);
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authPage5, $m7);
[, $pl4] = api('/api/auth.php', ['action' => 'password_login', 'identity' => 'qa.user22', 'password' => 'WrongPass!23'], $m7[1] ?? '', COOKIE2);
ok(empty($pl4['ok']), 'رمز اشتباه رد شد');
/* بازگشت به نشست کاربر QA برای ادامهٔ تست‌ها */
[, $rr] = api('/api/auth.php', ['action' => 'password_login', 'identity' => QA_MOB, 'password' => 'QaPass2026!'], $m7[1] ?? '', COOKIE2);

/* ═══ ۹) افزودن کاربر از پیشخوان ═══ */
echo "\n═══ ۹) افزودن کاربر توسط مدیر ═══\n";
[, $uAdd] = post_csrf('/admin/users.php', [
    'act' => 'add_user', 'first_name' => 'کیوای', 'last_name' => 'ادمینی',
    'mobile' => QA_MB2, 'national_code' => '', 'username' => 'qa.admin2', 'password' => '',
], $ACS);
$NUID = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MB2 . "'");
ok($NUID > 0, 'کاربر جدید از پیشخوان ساخته شد', "id=$NUID");
ok(str_contains($uAdd, 'رمز یک‌بارمصرف') || str_contains($uAdd, 'ساخته شد'), 'رمز خودکار یک‌بارنمایش به مدیر فلش شد');
ok($sql("SELECT mobile_verified FROM fr_users WHERE id=$NUID") === '1', 'کاربر ساخته‌شدهٔ مدیر، موبایل تأییدشده دارد');
[, $uDup] = post_csrf('/admin/users.php', [
    'act' => 'add_user', 'first_name' => 'تکراری', 'last_name' => 'موبایل',
    'mobile' => QA_MB2, 'national_code' => '', 'username' => '', 'password' => '',
], $ACS);
ok(str_contains($uDup, 'از قبل وجود دارد'), 'موبایل تکراری رد شد');
[, $uDup2] = post_csrf('/admin/users.php', [
    'act' => 'add_user', 'first_name' => 'تکراری', 'last_name' => 'یوزرنیم',
    'mobile' => '09123334455', 'national_code' => '', 'username' => 'qa.admin2', 'password' => '',
], $ACS);
ok(str_contains($uDup2, 'نام کاربری'), 'نام کاربری تکراری رد شد');
$sql("DELETE FROM fr_users WHERE mobile='09123334455'");
[, $uSrch] = getx('/admin/users.php?q=qa.admin2');
ok(str_contains($uSrch, QA_MB2) || str_contains($uSrch, strtr(QA_MB2, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'])), 'جستجوی کاربران با نام کاربری کار می‌کند');

/* ═══ ۱۰) ارتقا/تنزیل مدیر از کاربران سایت ═══ */
echo "\n═══ ۱۰) ارتقا و تنزیل مدیران ═══\n";
[, $promo] = post_csrf('/admin/roles.php', [
    'act' => 'user_promote', 'user_pick' => QA_MB2 . ' — کیوای ادمینی (@qa.admin2)',
    'role_pick' => 'legacy:support', 'password' => '',
], $ACS);
$AID2 = (int)$sql("SELECT id FROM fr_admins WHERE user_id=$NUID");
ok($AID2 > 0, 'کاربر سایت به مدریت ارتقاء یافت (ردیف مدیر پیوند خورد)', "admin_id=$AID2");
ok(str_contains($promo, 'به مدریت ارتقاء یافت'), 'پیام موفقیت ارتقا + نمایش نام کاربری پیشخوان');
ok($sql("SELECT role FROM fr_admins WHERE id=$AID2") === 'support', 'نقش پشتیبان تخصیص یافت');
/* تغییر نقش همان کاربر (بدون ساخت ردیف دوم) */
[, $promo2] = post_csrf('/admin/roles.php', [
    'act' => 'user_promote', 'user_pick' => QA_MB2 . ' — کیوای ادمینی (@qa.admin2)',
    'role_pick' => 'legacy:manager', 'password' => '',
], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_admins WHERE user_id=$NUID") === 1 && $sql("SELECT role FROM fr_admins WHERE user_id=$NUID") === 'manager', 'ارتقای مجدد همان کاربر = به‌روزرسانی نقش (بدون ردیف تکراری)');
/* نگه‌داشت: تنزیل خود */
[, $demSelf] = post_csrf('/admin/roles.php', ['act' => 'demote_admin', 'id' => $SELF], $ACS);
ok(str_contains($demSelf, 'نمی‌توانید حساب خودتان را تنزیل کنید'), 'تنزیل حساب خود مدیر مسدود است');
/* تنزیل مدیر ارتقایافته */
[, $dem] = post_csrf('/admin/roles.php', ['act' => 'demote_admin', 'id' => $AID2], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_admins WHERE id=$AID2") === 0, 'تنزیل مدیر: حساب پیشخوان حذف شد');
ok((int)$sql("SELECT COUNT(*) FROM fr_users WHERE id=$NUID") === 1, 'حساب کاربریِ سایت پس از تنزیل دست‌نخورده ماند');
ok(str_contains($dem, 'تنزیل یافت'), 'پیام موفقیت تنزیل');

/* ═══ ۱۱) بازقیمت‌گذاری بازار ایران ═══
   (به‌روزرسانی ۲٫۸: نرخ‌های جدید دو سطحی — قیمت اصلی + قیمت تخفیف‌خورده — جایگزین نرخ‌های ۲٫۲ شد) */
echo "\n═══ ۱۱) بازقیمت‌گذاری ═══\n";
$priceChecks = [
    "disc-family|249000" => "SELECT price_full FROM fr_tests WHERE slug='disc-family'",
    "disc-business|449000" => "SELECT price_full FROM fr_tests WHERE slug='disc-business'",
    "ysq-long|389000" => "SELECT price_full FROM fr_tests WHERE slug='ysq-long'",
    "neo|219000" => "SELECT price_full FROM fr_tests WHERE slug='neo'",
    "gardner-mi|219000" => "SELECT price_full FROM fr_tests WHERE slug='gardner-mi'",
    "anxiety|119000" => "SELECT price_full FROM fr_tests WHERE slug='anxiety'",
];
$allP = true; $detail = [];
foreach ($priceChecks as $expect => $q) {
    [$slug, $p] = explode('|', $expect);
    $got = $sql($q); $detail[] = "$slug=$got";
    if ($got !== $p) $allP = false;
}
ok($allP, 'قیمت تست‌های پایه مطابق نرخ بازار', implode(' ', $detail));
ok($sql("SELECT price FROM fr_events WHERE slug='doreh-tarazehe'") === '3900000', 'دورهٔ پرمحتوا (طرحواره) ۳٬۹۰۰٬۰۰۰ تومان (نرخ ۲٫۸)', $sql("SELECT price FROM fr_events WHERE slug='doreh-tarazehe'"));
ok($sql("SELECT GROUP_CONCAT(price ORDER BY id) FROM fr_consult_services WHERE status='active' OR status IS NULL LIMIT 3") !== '', 'خدمات مشاوره قیمت دارند');
$cp = $sql("SELECT GROUP_CONCAT(price ORDER BY id SEPARATOR ',') FROM fr_consult_services LIMIT 3");
ok(str_contains($cp, '750000') && str_contains($cp, '1050000') && str_contains($cp, '1350000'), 'قیمت جلسات مشاوره: ۷۵۰هزار/۱٬۰۵۰/۱٬۳۵۰', (string)$cp);
$fa390 = strtr('390,000', ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',','=>',']);
[, $tpDisc] = getx('/test-page.php?s=disc-family');
ok(str_contains($tpDisc, '۲۴۹') || str_contains($tpDisc, '249'), 'قیمت جدید تست روانشناسی روی صفحهٔ عمومی دیده می‌شود (شفافیت)');

/* ═══ ۱۲) کد تخفیف مشاوره ۸۰/۲۰ ═══ */
echo "\n═══ ۱۲) جعبهٔ کد تخفیف مشاوره ═══\n";
[, $cons] = getx('/consult.php?s=individual-counseling', COOKIE2);
ok(str_contains($cons, 'flex:4 1 80%') && str_contains($cons, 'flex:1 1 20%'), 'فیلد کد تخفیف ۸۰٪ و دکمهٔ اعمال ۲۰٪ شد');

/* ═══ ۱۳) دیدگاه‌ها و پرسش‌های باز دوره ═══ */
echo "\n═══ ۱۳) دیدگاه/پرسش باز + پاسخ مدیریت ═══\n";
$CORSID = (int)$sql("SELECT id FROM fr_events WHERE slug='doreh-tarazehe' AND kind='course'");
ok($CORSID > 0, 'دورهٔ هدف QA یافت شد', "id=$CORSID");
$sql("DELETE FROM fr_event_registrations WHERE user_id=$QAUID AND event_id=$CORSID");
$isReg = (int)$sql("SELECT COUNT(*) FROM fr_event_registrations WHERE user_id=$QAUID AND event_id=$CORSID");
ok($isReg === 0, 'کاربر QA در این دوره ثبت‌نام نکرده (غیرشرکت‌کننده)');
[, $corsP0] = getx('/course.php?s=doreh-tarazehe', COOKIE2);
ok(str_contains($corsP0, 'rv-type-btn') && str_contains($corsP0, 'نظر و امتیاز') && str_contains($corsP0, 'پرسش'), 'فرم دوحالتهٔ «نظر و امتیاز / پرسش» برای کاربرِ غیرشرکت‌کننده رندر شد');
[, $rv1] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'course', 'ref_id' => $CORSID, 'rating' => 4, 'body' => 'کیوای ۲٫۲ — به نظرم سرفصل‌ها کامل است؛ برای من که هنوز ثبت‌نام نکردم مفید بود.'], $m7[1] ?? $UCS, COOKIE2);
ok(!empty($rv1['ok']), 'غیرشرکت‌کنندهٔ دوره هم توانست نظر بگذارد', $rv1['message'] ?? '');
ok($sql("SELECT badge_auto FROM fr_reviews WHERE user_id=$QAUID AND context='course' AND ref_id=$CORSID") === 'guest', 'نشان خودکار «کاربر مهمان» برای غیرشرکت‌کننده ثبت شد');
[, $q1] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'course', 'ref_id' => $CORSID, 'kind' => 'question', 'rating' => 0, 'body' => 'کیوای ۲٫۲ — پرسش: آیا برای این دوره پیش‌نیازی لازم است؟'], $m7[1] ?? $UCS, COOKIE2);
/* پرسش همان کاربر = به‌روزرسانی همان ردیف (هر کاربر یک دیدگاه/پرسش) */
ok(!empty($q1['ok']), 'ثبت پرسش بدون امتیاز پذیرفته شد', $q1['message'] ?? '');
$QRID = (int)$sql("SELECT id FROM fr_reviews WHERE user_id=$QAUID AND context='course' AND ref_id=$CORSID");
ok($sql("SELECT kind FROM fr_reviews WHERE id=$QRID") === 'question' && $sql("SELECT rating IS NULL FROM fr_reviews WHERE id=$QRID") === '1', 'ردیف نهایی از نوع «پرسش» با امتیاز NULL است');
[, $corsP] = getx('/course.php?s=doreh-tarazehe');
ok(str_contains($corsP, 'rv-qchip') || str_contains($corsP, 'پرسش'), 'نشان «پرسش» روی صفحهٔ عمومی دوره دیده می‌شود');
ok(str_contains($corsP, 'کاربر مهمان') || str_contains($corsP, 'مهمان'), 'لیبل «کاربر مهمان» کنار دیدگاه عمومی دیده می‌شود');
ok(str_contains($corsP, 'گفتید') || str_contains($corsP, 'دیدگاه ثبت') || str_contains($corsP, 'rv-item'), 'پس از ثبت، دیدگاه/پرسش خود کاربر روی صفحه دیده می‌شود');
/* آمار فقط از نظرها */
$totAppr = (int)$sql("SELECT COUNT(*) FROM fr_reviews WHERE context='course' AND ref_id=$CORSID AND status='approved'");
$revOnly = (int)$sql("SELECT COUNT(*) FROM fr_reviews WHERE context='course' AND ref_id=$CORSID AND status='approved' AND kind='review' AND rating IS NOT NULL");
ok($totAppr === 1 && $revOnly === 0, 'پرسشِ منتشرشده در آمار امتیاز «نظر»ها شمرده نمی‌شود', "approved=$totAppr review-kind=$revOnly");
/* پاسخ مدیریت */
[, $rep] = post_csrf('/admin/reviews.php', ['act' => 'reply', 'id' => $QRID, 'admin_reply' => 'پاسخ کیوای ۲٫۲: خیر، پیش‌نیاز لازم نیست.'], $ACS);
ok($sql("SELECT admin_reply FROM fr_reviews WHERE id=$QRID") === 'پاسخ کیوای ۲٫۲: خیر، پیش‌نیاز لازم نیست.', 'پاسخ مدیریت در دیتابیس ذخیره شد');
[, $corsP2] = getx('/course.php?s=doreh-tarazehe');
ok(str_contains($corsP2, 'پاسخ کیوای ۲٫۲') && str_contains($corsP2, 'rv-reply'), 'پاسخ مدیریت زیر پرسش به‌صورت عمومی نمایش داده شد');
[, $admRv] = getx('/admin/reviews.php?tab=list');
ok(str_contains($admRv, 'پرسش‌های بی‌پاسخ'), 'کارت آماری «پرسش‌های بی‌پاسخ» در پیشخوان دیدگاه‌ها');
/* سخت‌گیری تست روانشناسی: نظر فقط شرکت‌کنندگان، پرسش آزاد */
$TID5 = (int)$sql("SELECT id FROM fr_tests WHERE slug='neo'");
$sql("DELETE FROM fr_reviews WHERE user_id=$QAUID AND context='test' AND ref_id=$TID5");
$sql("DELETE FROM fr_attempts WHERE user_id=$QAUID AND test_id=$TID5");
[, $rvT] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'test', 'ref_id' => $TID5, 'rating' => 5, 'body' => 'کیوای ۲٫۲ — نظر بدون شرکت در تست'], $m7[1] ?? $UCS, COOKIE2);
ok(empty($rvT['ok']) && str_contains((string)($rvT['message'] ?? ''), 'شرکت‌کنندگان'), 'نظر غیرشرکت‌کننده روی «تست روانشناسی» همچنان رد می‌شود', $rvT['message'] ?? '');
[, $qT] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'test', 'ref_id' => $TID5, 'kind' => 'question', 'rating' => 0, 'body' => 'کیوای ۲٫۲ — این تست چقدر طول می‌کشد؟'], $m7[1] ?? $UCS, COOKIE2);
ok(!empty($qT['ok']), 'پرسش روی تست روانشناسی برای همه آزاد است', $qT['message'] ?? '');
$sql("DELETE FROM fr_reviews WHERE user_id=$QAUID AND context='test' AND ref_id=$TID5");

/* ═══ ۱۴) پاک‌سازی پایانی ═══ */
echo "\n── پاک‌سازی پایانی…\n";
$sql("DELETE FROM fr_reviews WHERE user_id=$QAUID AND context='course' AND ref_id=$CORSID");
$sql("DELETE FROM fr_newsletter WHERE mobile='" . NL_MOB . "'");
$sql("DELETE FROM fr_admins WHERE user_id IN ($QAUID,$NUID)");
if ($NUID) $sql("DELETE FROM fr_users WHERE id=$NUID");
if ($QAUID) {
    $sql("DELETE FROM fr_users WHERE id=$QAUID");
}
foreach (['footer_badge1_html','footer_badge2_html','footer_badge3_html','footer_badge4_html'] as $k) {
    $sql("UPDATE fr_settings SET `value`='' WHERE `key`='$k'");
}

echo "\n════════════════════════════════════════\n";
echo "  نتیجه: $PASS قبول، $FAIL رد\n";
exit($FAIL ? 1 : 0);
