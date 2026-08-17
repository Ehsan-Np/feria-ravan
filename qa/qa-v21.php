<?php
/* ============================================================================
   QA نسخهٔ ۲٫۱ — ری‌برندینگ متن‌ها • فوتر نماددار+خبرنامه • میهمان رایگان پلکانی
   • نقش‌ها/سطح دسترسی • برنامه‌ریز روزانه+استریک+پاداش • یادآور کرون • خبرنامه
   ============================================================================ */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

const BASE   = 'http://localhost:8899';
const COOKIE = '/tmp/qa-v21-cookies.txt';
const COOKIE2 = '/tmp/qa-v21-cookies2.txt';
const AUSER  = 'admin';
const APASS  = 'AdminTest!2026';
const QA_MOB = '09120008888';   // کاربر اصلی QA
const QA_MO3 = '09120007777';   // کاربر دوم (تست سقف میهمان رایگان)
const NL_MOB = '09301112233';   // مشترک خبرنامهٔ QA

$PASS = 0; $FAIL = 0;
date_default_timezone_set('Asia/Tehran');   // هم‌تراز با منطقهٔ زمانی اپلیکیشن
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
function api(string $url, array $data, string $token, string $jar = COOKIE): array {
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
$sql("DELETE FROM fr_rate_limits");   // پاک‌سازی کامل سقف‌ها (QA مکرر)

/* ═══ پاک‌سازی QA قبلی ═══ */
echo "── پاک‌سازی QA نسخهٔ ۲٫۱ قبلی…\n";
$sql("DELETE FROM fr_texts WHERE `k` LIKE 'nav.home'");
$sql("DELETE FROM fr_admin_roles WHERE title='کیوای نقش'");
$sql("DELETE FROM fr_admins WHERE username='qa-role-admin'");
$sql("DELETE FROM fr_newsletter WHERE mobile IN ('".NL_MOB."','09120009988')");
$sql("DELETE FROM fr_discounts WHERE code='QA100' OR code LIKE 'PLANNER-%'");
foreach ([QA_MOB, QA_MO3] as $m) {
    $qu = (int)$sql("SELECT id FROM fr_users WHERE mobile='$m'");
    if ($qu) {
        $sql("DELETE pt FROM fr_planner_tasks pt WHERE pt.user_id=$qu");
        $sql("DELETE pw FROM fr_planner_rewards pw WHERE pw.user_id=$qu");
        $sql("DELETE dc FROM fr_discounts dc WHERE dc.user_id=$qu AND dc.code LIKE 'PLANNER-%'");
        $regIds = $sql("SELECT GROUP_CONCAT(id) FROM fr_event_registrations WHERE user_id=$qu");
        if ($regIds) $sql("DELETE FROM fr_event_companions WHERE reg_id IN ($regIds)");
        $sql("DELETE FROM fr_event_registrations WHERE user_id=$qu");
        $sql("UPDATE fr_users SET telegram_chat_id=NULL, bale_chat_id=NULL WHERE id=$qu");
    }
}
/* رویداد کیوای به حالت قبل از تست برگردد */
$sql("UPDATE fr_events SET ws_discount_type='none', ws_discount_tiers=NULL, companion_max=4 WHERE slug='qa-paid-ws'");
/* تنظیمات فوتر/برند به پیش‌فرض */
foreach (['logo_desc'=>'','footer_links_json'=>'','footer_links2_json'=>'',
          'footer_badge1_img'=>'','footer_badge1_url'=>'','footer_badge1_html'=>'',
          'footer_badge2_img'=>'','footer_badge2_url'=>'','footer_badge2_html'=>'',
          'footer_badge3_img'=>'','footer_badge3_url'=>'','footer_badge3_html'=>''] as $k=>$v) {
    $sql("UPDATE fr_settings SET `value`='$v' WHERE `key`='$k'");
}
$sql("UPDATE fr_settings SET `value`='1' WHERE `key` IN ('planner_enabled')");
$sql("UPDATE fr_settings SET `value`='7' WHERE `key`='planner_streak_target'");
$sql("UPDATE fr_settings SET `value`='20' WHERE `key`='planner_reward_percent'");

/* ═══ ۰) خط‌لولهٔ مهاجرت ۲٫۱ ═══ */
echo "\n═══ ۰) خط‌لولهٔ مهاجرت ۲٫۱ ═══\n";
foreach (['fr_texts','fr_admin_roles','fr_planner_tasks','fr_planner_rewards','fr_newsletter'] as $t) {
    ok($sql("SHOW TABLES LIKE '$t'") !== '', "جدول $t ساخته شد");
}
ok($sql("SHOW COLUMNS FROM fr_admins LIKE 'role_id'") !== '', 'ستون role_id در مدیران');
ok($sql("SHOW COLUMNS FROM fr_users LIKE 'telegram_chat_id'") !== '' && $sql("SHOW COLUMNS FROM fr_users LIKE 'bale_chat_id'") !== '', 'ستون‌های چت تلگرام/بله در کاربران');
ok($sql("SHOW COLUMNS FROM fr_event_companions LIKE 'is_free'") !== '', 'ستون is_free در همراهان');
ok((int)$sql("SELECT COUNT(*) FROM fr_admin_roles") >= 3, 'نقش‌های سیستمی بذر شدند (≥۳)');
ok((int)$sql("SELECT JSON_LENGTH(caps) FROM fr_admin_roles WHERE title LIKE 'مدیر ارشد%'") >= 17, 'نقش مدیر ارشد همهٔ capها را دارد');
ok(strlen($sql("SELECT `value` FROM fr_settings WHERE `key`='cron_secret'")) >= 16, 'کلید مخفی کرون ساخته شد');
ok(version_compare($sql("SELECT `value` FROM fr_settings WHERE `key`='fr_version'"), '2.1.0', '>='), 'نگارش DB حداقل ۲٫۱٫۰ است');
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='footer_badge1_alt'") !== '', 'پیش‌فرض عنوان نمادها بذر شد');

/* ═══ ۱) فوتر: نمادها + خبرنامه + لینک‌های مدیریتی ═══ */
echo "\n═══ ۱) فوتر عمومی ═══\n";
[, $home] = getx('/');
ok(substr_count($home, 'fb-slot') >= 3, 'سه جایگاه نماد هم‌اندازه در فوتر رندر شد', (string)substr_count($home, 'fb-slot'));
ok(str_contains($home, 'id="nlForm"') && str_contains($home, 'pb-nl'), 'نوار خبرنامهٔ پیامکی فوتر رندر شد (مهمان)');
ok(str_contains($home, 'fb-empty'), 'جایگاه‌های خالی نماد با فریم نقطه‌چین دیده می‌شوند');

/* ورود مدیر */
[, $lb] = getx('/admin/login.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $lb, $m);
[, $dash] = post_csrf('/admin/login.php', ['username' => AUSER, 'password' => APASS], $m[1] ?? '');
ok(str_contains($dash, 'خالص درآمد'), 'ورود مدیر موفق');
[, $acr] = getx('/admin/tests.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $acr, $m);
$ACS = $m[1] ?? '';
ok($ACS !== '', 'توکن CSRF مدیر');

/* ذخیرهٔ تنظیمات فوتر */
[, $stBody] = post_csrf('/admin/settings.php?tab=footer', [
    'act' => 'save',
    'logo_desc' => '<p>فریا روان — مرجع تخصصی آزمون‌های روان‌شناسی و رشد فردی</p>',
    'footer_links_json' => "پیوند کیوای|/tests\nتلگرام|https://t.me/example",
    'footer_badge1_img' => 'https://example.com/enamad.png',
    'footer_badge1_url' => 'https://trustseal.enamad.ir/',
    'footer_badge1_alt' => 'نماد اعتماد الکترونیک',
    'footer_badge1_html' => '',
    'footer_badge2_img' => '', 'footer_badge2_url' => '', 'footer_badge2_alt' => 'زرین‌پال',
    'footer_badge2_html' => '<b>درگاه امن</b><script>alert(1)</script>',
    'footer_badge3_img' => '', 'footer_badge3_url' => '', 'footer_badge3_alt' => 'انجمن', 'footer_badge3_html' => '',
    'newsletter_title' => 'خبرنامهٔ کیوای', 'newsletter_desc' => 'توضیح خبرنامهٔ آزمایشی',
], $ACS);
[, $home2] = getx('/');
ok(str_contains($home2, 'مرجع تخصصی آزمون‌های روان‌شناسی'), 'متن سئویی دلخواه زیر لوگوی فوتر نمایش داده شد');
ok(str_contains($home2, 'enamad.png') && str_contains($home2, 'trustseal.enamad.ir'), 'نماد ۱ با تصویر + لینک رندر شد');
ok(str_contains($home2, 'پیوند کیوای') && str_contains($home2, '/tests'), 'لینک‌های مدیریتی فوتر اعمال شدند');
ok(str_contains($home2, '<script>alert(1)'), 'کد HTML خام نماد کامل و دست‌نخورده رندر می‌شود (پشتیبانی کد HTML)');
ok(str_contains($home2, 'درگاه امن'), 'محتوای HTML نماد ۲ نمایش داده شد');
ok(str_contains($home2, 'خبرنامهٔ کیوای'), 'عنوان سفارشی خبرنامه از تنظیمات خوانده شد');
/* تب‌های جدید تنظیمات رندر شوند */
[, $ftab] = getx('/admin/settings.php?tab=footer');
ok(str_contains($ftab, 'footer_badge1_img') && str_contains($ftab, 'footer_links_json'), 'تب «فوتر و نمادها» در تنظیمات رندر شد');
[, $ptab] = getx('/admin/settings.php?tab=planner');
ok(str_contains($ptab, 'planner_streak_target') && str_contains($ptab, 'telegram_bot_token') && str_contains($ptab, 'bale_bot_token'), 'تب «برنامه‌ریز روزانه» با فیلدهای ربات تلگرام/بله رندر شد');

/* ═══ ۲) ری‌برندینگ متن‌ها ═══ */
echo "\n═══ ۲) شخصی‌سازی کامل متن‌ها (ری‌برندینگ) ═══\n";
[, $tx] = getx('/admin/texts.php');
ok(str_contains($tx, 'nav.home') && str_contains($tx, 'ری‌برندینگ'), 'صفحهٔ مدیریت متن‌ها رندر شد', '');
[, $txr] = post_csrf('/admin/texts.php', ['act' => 'save', 'k' => 'nav.home', 'txt' => 'خانهٔ برندمن'], $ACS);
[, $home3] = getx('/');
ok(str_contains($home3, 'خانهٔ برندمن'), 'اورراید متن ناوبری بلافاصله در سایت اعمال شد');
ok($sql("SELECT txt FROM fr_texts WHERE `k`='nav.home'") === 'خانهٔ برندمن', 'اورراید در جدول fr_texts ذخیره شد');
[, $txr2] = post_csrf('/admin/texts.php', ['act' => 'save', 'k' => 'nav.home', 'txt' => ''], $ACS);   // ریست به پیش‌فرض
[, $home4] = getx('/');
ok(!str_contains($home4, 'خانهٔ برندمن') && str_contains($home4, '>خانه<'), 'بازنشانی متن به پیش‌فرض با مقدار خالی کار کرد');

/* ═══ ۳) نقش‌ها و سطح دسترسی ═══ */
echo "\n═══ ۳) نقش‌ها و سطح دسترسی مدیران ═══\n";
[, $rl] = getx('/admin/roles.php');
ok(str_contains($rl, 'مدیر ارشد') && str_contains($rl, 'caps'), 'صفحهٔ نقش‌ها رندر شد');
[, $r2] = post_csrf('/admin/roles.php', ['act' => 'save', 'title' => 'کیوای نقش', 'caps' => ['newsletter']], $ACS);
$qaRoleId = (int)$sql("SELECT id FROM fr_admin_roles WHERE title='کیوای نقش'");
ok($qaRoleId > 0, 'نقش سفارشی ساخته شد', "id=$qaRoleId");
[, $adm] = getx('/admin/admins.php');
ok(str_contains($adm, 'افزودن مدیر جدید') && str_contains($adm, 'نقش قدیمی'), 'صفحهٔ حساب‌های مدیر رندر شد');
[, $r3] = post_csrf('/admin/admins.php', ['act' => 'add', 'username' => 'qa-role-admin', 'display_name' => 'کیوای رول', 'password' => 'QaAdmin!2026', 'role_id' => $qaRoleId], $ACS);
$qaAdminId = (int)$sql("SELECT id FROM fr_admins WHERE username='qa-role-admin'");
ok($qaAdminId > 0 && (int)$sql("SELECT role_id FROM fr_admins WHERE id=$qaAdminId") === $qaRoleId, 'مدیر با نقش سفارشی ساخته شد');

/* ورود مدیر نقش‌دار در جار جدا */
[, $lb2] = getx('/admin/login.php', COOKIE2);
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $lb2, $m2);
[, $dash2] = post_csrf('/admin/login.php', ['username' => 'qa-role-admin', 'password' => 'QaAdmin!2026'], $m2[1] ?? '', COOKIE2);
ok(str_contains($dash2, 'پیشخوان'), 'ورود مدیر نقش‌دار موفق');
[$c1, $b1] = getx('/admin/newsletter.php', COOKIE2);
ok($c1 === 200 && str_contains($b1, 'خبرنامهٔ پیامکی'), 'دسترسی به برگهٔ مجاز نقش (خبرنامه) باز است');
[$c2] = http('GET', '/admin/tests.php', [], [], COOKIE2);
ok($c2 !== 200, 'برگهٔ غیرمجازِ نقش مسدود است (ریدایرکت به ورود)', (string)$c2);
[$c3] = http('GET', '/admin/settings.php', [], [], COOKIE2);
ok($c3 !== 200, 'تنظیمات برای نقش سفارشی مسدود است', (string)$c3);
ok(str_contains($dash2, 'newsletter.php') && !str_contains($dash2, '/admin/settings.php'), 'منوی سایدبار بر اساس capهای نقش فیلتر شد');
/* منوی مدیر ارشد همهٔ لینک‌های جدید را دارد */
ok(str_contains($dash, 'newsletter.php') && str_contains($dash, 'roles.php') && str_contains($dash, 'texts.php'), 'منوی مدیر ارشد شامل بخش‌های جدید ۲٫۱ است');
/* حذف نقش در حال استفاده باید ممنوع باشد */
[, $rDel] = post_csrf('/admin/roles.php', ['act' => 'del', 'id' => $qaRoleId], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_admin_roles WHERE id=$qaRoleId") === 1, 'حذف نقش متصل به مدیر ممنوع است');
$sql("DELETE FROM fr_admins WHERE id=$qaAdminId");
[, $rDel2] = post_csrf('/admin/roles.php', ['act' => 'del', 'id' => $qaRoleId], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_admin_roles WHERE id=$qaRoleId") === 0, 'پس از آزادشدن، حذف نقش ممکن شد');

/* ═══ ۴) ورود کاربر اصلی QA ═══ */
echo "\n═══ ۴) ورود کاربر QA ═══\n";
[, $authPage] = getx('/auth.php');
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authPage, $mm);
$UCS = $mm[1] ?? '';
[, $r1] = api('/api/auth.php', ['action' => 'send_otp', 'mobile' => QA_MOB], $UCS);
[, $r2] = api('/api/auth.php', ['action' => 'verify_otp', 'mobile' => QA_MOB, 'code' => $r1['debug_code'] ?? ''], $UCS);
if (!empty($r2['needs_register'])) {
    [, $r3] = api('/api/auth.php', ['action' => 'register_name', 'full_name' => 'کیوای کاربر'], $UCS);
}
$qaUid = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MOB . "'");
ok($qaUid > 0, 'کاربر QA وارد/ثبت‌نام شد', "id=$qaUid");
[, $pnl] = getx('/panel/');
ok(str_contains($pnl, 'داشبورد'), 'پنل کاربر باز است');
ok(str_contains($pnl, 'planner.php'), 'لینک «برنامهٔ روزانه» در منوی پنل دیده می‌شود');

/* ═══ ۵) تخفیف پلکانی با میهمان رایگان ═══ */
echo "\n═══ ۵) تخفیف پلکانی + میهمان‌های رایگان هدیه ═══\n";
$evId = (int)$sql("SELECT id FROM fr_events WHERE slug='qa-paid-ws'");
$sql("UPDATE fr_events SET ws_discount_type='tiered', ws_discount_tiers='[{\"min\":11,\"percent\":30,\"free\":2}]', companion_max=15, companion_price_mode='fixed', companion_price=100000, price=1000000, capacity=60, reg_open=1, status='active', companion_enabled=1 WHERE id=$evId");
$sql("INSERT INTO fr_discounts (code,type,`value`,max_amount,min_order,test_id,event_id,user_id,usage_limit,per_user,used_count,starts_at,expires_at,status)
      VALUES ('QA100','percent',100,NULL,0,NULL,$evId,NULL,50,5,0,NULL,NULL,'active')");

/* قیمت زنده: ۱۰ همراه پولی → پله فعال (۱۱ نفر پرداخت‌کننده) */
[, $q1] = api('/api/discount.php', ['event_id' => $evId, 'cmp' => 10, 'code' => ''], $UCS);
ok(!empty($q1['ok']) && (int)($q1['quote']['free_allowed'] ?? -1) === 2, 'با ۱۰ همراه (۱۱ نفر پرداخت‌کننده) ۲ میهمان رایگان فعال شد');
ok((int)($q1['quote']['ws_discount'] ?? 0) === 600000, 'درصد پله (۳۰٪ روی جمع ۲ میلیون) = ۶۰۰ هزار', (string)($q1['quote']['ws_discount'] ?? ''));
[, $q2] = api('/api/discount.php', ['event_id' => $evId, 'cmp' => 10, 'free' => 2, 'code' => ''], $UCS);
ok((int)($q2['quote']['seats_total'] ?? 0) === 13, 'مجموع صندلی‌ها = ۱۳ (۱۱ پرداخت‌کننده + ۲ میهمان رایگان)', (string)($q2['quote']['seats_total'] ?? ''));
ok((int)($q2['quote']['payable'] ?? -1) === 1400000, 'مبلغ فقط از ۱۱ نفر پرداخت‌کننده با ۳۰٪ تخفیف محاسبه شد', (string)($q2['quote']['payable'] ?? ''));
$hasFreeLine = false;
foreach ((array)($q2['quote']['lines'] ?? []) as $ln) { if (str_contains((string)$ln['label'], 'میهمان رایگان')) $hasFreeLine = true; }
ok($hasFreeLine, 'خط «میهمان رایگان — رایگان 🎁» در فاکتور زنده دیده شد');
[, $q3] = api('/api/discount.php', ['event_id' => $evId, 'cmp' => 9, 'code' => ''], $UCS);
ok((int)($q3['quote']['free_allowed'] ?? -1) === 0 && (int)($q3['quote']['ws_discount'] ?? 1) === 0, 'با ۹ همراه (۱۰ نفر) پله فعال نمی‌شود');

/* صفحهٔ ثبت‌نام با فیلدهای میهمان رایگان (سمت JS به quote واکنش نشان می‌دهد) */
[, $er] = getx('/panel/event-register.php?id=' . $evId);
ok(str_contains($er, 'free_name[]') && str_contains($er, 'syncFreeRows'), 'فرم ثبت‌نام منطق میهمان رایگان را دارد');

/* ثبت‌نام کامل: ۱۰ همراه پولی + ۲ میهمان رایگان + کد ۱۰۰٪ → نهایی رایگان */
$fields = ['discount_code' => 'QA100'];
for ($i = 0; $i < 10; $i++) { $fields["cmp_name[$i]"] = 'همراه ' . ($i + 1); $fields["cmp_mob[$i]"] = '0911000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT); }
$fields['cmp_mob[8]'] = '09110000008';
for ($i = 0; $i < 2; $i++) { $fields["free_name[$i]"] = 'میهمان ' . ($i + 1); $fields["free_mob[$i]"] = '0922' . str_pad((string)(1000 + $i), 7, '0', STR_PAD_LEFT); }
[, $regBody] = post_csrf('/panel/event-register.php?id=' . $evId, $fields, $UCS);
$regId = (int)$sql("SELECT id FROM fr_event_registrations WHERE user_id=$qaUid AND event_id=$evId ORDER BY id DESC LIMIT 1");
ok($regId > 0, 'ثبت‌نام با ۱۰ همراه + ۲ میهمان رایگان انجام شد', "reg=$regId");
ok((int)$sql("SELECT COUNT(*) FROM fr_event_companions WHERE reg_id=$regId") === 12, 'هر ۱۲ همراه ذخیره شدند');
ok((int)$sql("SELECT COUNT(*) FROM fr_event_companions WHERE reg_id=$regId AND is_free=1") === 2, 'دقیقاً ۲ همراه با پرچم میهمان رایگان');
ok((int)$sql("SELECT COUNT(*) FROM fr_event_companions WHERE reg_id=$regId AND is_free=0") === 10, '۱۰ همراه پولی معمولی');
ok((int)$sql("SELECT companion_count FROM fr_event_registrations WHERE id=$regId") === 12, 'companion_count شامل میهمان‌های رایگان (۱۲) است');
ok((int)$sql("SELECT COALESCE(SUM(1+COALESCE(companion_count,0)),0) FROM fr_event_registrations WHERE event_id=$evId AND status='paid'") >= 13, 'صندلی‌های مصرفی رویداد ≥ ۱۳ (میهمان رایگان صندلی می‌گیرد)');
/* هر میهمان/همراه حساب کاربری گرفته */
ok((int)$sql("SELECT COUNT(*) FROM fr_users WHERE mobile IN ('09220001000','09220001001')") === 2, 'برای میهمان‌های رایگان هم حساب کاربری خودکار ساخته شد');

/* کاربر دوم: ۳ میهمان رایگان با همان ۱۰ همراه → رد اعتبارسنجی */
[, $ap3] = getx('/auth.php');
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $ap3, $mm3);
$U3 = $mm3[1] ?? '';
[, $r1b] = api('/api/auth.php', ['action' => 'send_otp', 'mobile' => QA_MO3], $U3);
[, $r2b] = api('/api/auth.php', ['action' => 'verify_otp', 'mobile' => QA_MO3, 'code' => $r1b['debug_code'] ?? ''], $U3);
if (!empty($r2b['needs_register'])) api('/api/auth.php', ['action' => 'register_name', 'full_name' => 'کیوای کاربر۳'], $U3);
$f2 = ['discount_code' => 'QA100'];
for ($i = 0; $i < 10; $i++) { $f2["cmp_name[$i]"] = 'همراه۳ ' . $i; $f2["cmp_mob[$i]"] = '0933000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT); }
for ($i = 0; $i < 3; $i++) { $f2["free_name[$i]"] = 'میهمان۳ ' . $i; $f2["free_mob[$i]"] = '0944' . str_pad((string)(1000 + $i), 7, '0', STR_PAD_LEFT); }
[, $regBody2] = post_csrf('/panel/event-register.php?id=' . $evId, $f2, $U3);
$uid3x = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MO3 . "'");
ok(str_contains($regBody2, 'میهمان رایگان هدیه می‌گیرید') || str_contains($regBody2, 'کم کنید'), 'میهمان رایگانِ بیش از سقف پله (۳ از ۲) با پیام روشن رد شد');
ok((int)$sql("SELECT COUNT(*) FROM fr_event_registrations WHERE user_id=$uid3x AND event_id=$evId") === 0, 'سفارش نامعتبر هیچ ثبت‌نامی نساخت');

/* ═══ ۶) برنامه‌ریز روزانه + استریک + پاداش ═══ */
echo "\n═══ ۶) برنامه‌ریز روزانه و پاداش استریک ═══\n";
/* بازگشت به حساب کاربر اصلی QA (بخش ۵ سشن را به کاربر ۳ عوض کرد) */
[, $r1c] = api('/api/auth.php', ['action' => 'send_otp', 'mobile' => QA_MOB], $UCS);
[, $r2c] = api('/api/auth.php', ['action' => 'verify_otp', 'mobile' => QA_MOB, 'code' => $r1c['debug_code'] ?? ''], $UCS);
ok(!empty($r2c['ok']), 'بازگشت سشن به کاربر اصلی QA موفق بود', (string)($r2c['ok'] ?? ''));
/* اتصال تلگرام از پروفایل — نسخهٔ ۲٫۳: به‌جای ورود دستی چت‌آیدی، کارت اتصال با «نام کاربری» */
[, $prof] = getx('/panel/profile.php');
ok(str_contains($prof, 'social_connect') && str_contains($prof, 'نام کاربری') && !str_contains($prof, 'name="telegram_chat_id"'), 'کارت اتصال تلگرام/بله با نام کاربری در پروفایل دیده می‌شود (بدون ورود دستی چت‌آیدی)');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $prof, $pm);
[, $profSave] = post_csrf('/panel/profile.php', ['act' => 'save_notifs', 'notif_remind' => '1'], $pm[1] ?? $UCS);
/* کاربر به‌صورت متصل شبیه‌سازی می‌شود (کاری که کرون getUpdates انجام می‌دهد؛ خود جریان اتصال در qa-v23 تست شده) */
$sql("UPDATE fr_users SET telegram_chat_id='123456789', tg_username='qa.user21' WHERE id=$qaUid");
ok($sql("SELECT telegram_chat_id FROM fr_users WHERE id=$qaUid") === '123456789', 'شناسهٔ چت تلگرام کاربر (متصل‌شده) در حساب ثبت است');

[, $pl] = getx('/panel/planner.php');
ok(str_contains($pl, 'کارهای امروز') && str_contains($pl, 'روز متوالی'), 'صفحهٔ برنامهٔ روزانه رندر شد');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $pl, $pm2);
$PCS = $pm2[1] ?? $UCS;
[, $plAdd] = post_csrf('/panel/planner.php', ['act' => 'add', 'day' => $SITE_TODAY, 'title' => 'کار آزمایشی کیوای', 'time_note' => '09:00', 'priority' => 'high', 'category' => 'study'], $PCS);
$taskId = (int)$sql("SELECT id FROM fr_planner_tasks WHERE user_id=$qaUid AND title='کار آزمایشی کیوای' ORDER BY id DESC LIMIT 1");
ok($taskId > 0, 'کار روزانه از فرم پنل ثبت شد');
/* ۶ روز کاملِ گذشته بذر شود تا با امروز استریک ۷ شود */
for ($i = 6; $i >= 1; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $sql("INSERT INTO fr_planner_tasks (user_id,day,title,priority,category,done,done_at) VALUES ($qaUid,'$d','کار قدیمی','normal','personal',1,NOW())");
}
[, $tj] = api('/api/planner.php', ['act' => 'toggle', 'id' => $taskId], $PCS);
ok(!empty($tj['ok']) && !empty($tj['done']), 'تیک زدن کار از API موفق بود', json_encode($tj, JSON_UNESCAPED_UNICODE));
ok((int)($tj['streak'] ?? 0) >= 7, 'استریک به ۷ رسید (۶ روز گذشته + امروز)', (string)($tj['streak'] ?? ''));
ok(!empty($tj['reward']), 'پاسخ API صدور پاداش را اعلام کرد');
$rw = $sql("SELECT code FROM fr_planner_rewards WHERE user_id=$qaUid ORDER BY id DESC LIMIT 1");
ok(str_starts_with((string)$rw, 'PLANNER-'), 'پاداش استریک با کد تخفیف صادر شد', (string)$rw);
ok($sql("SELECT code FROM fr_discounts WHERE code='$rw' AND user_id=$qaUid AND `value`=20 AND type='percent'") === $rw, 'کد تخفیف ۲۰٪ مخصوص همان کاربر در جدول تخفیف‌ها ثبت شد');
/* پاداش تکراری برای همان استریک صادر نمی‌شود */
[, $tj2] = api('/api/planner.php', ['act' => 'toggle', 'id' => $taskId], $PCS);   // برداشتن تیک
[, $tj3] = api('/api/planner.php', ['act' => 'toggle', 'id' => $taskId], $PCS);   // زدن دوباره — استریک دوباره ۷
ok((int)$sql("SELECT COUNT(*) FROM fr_planner_rewards WHERE user_id=$qaUid") === 1, 'پاداش یک استریکِ مشخص فقط یک‌بار صادر می‌شود');

/* غیرفعال‌کردن برنامه‌ریز از منو حذف می‌کند */
$sql("UPDATE fr_settings SET `value`='0' WHERE `key`='planner_enabled'");
[, $pnl2] = getx('/panel/');
ok(!str_contains($pnl2, 'planner.php'), 'با غیرفعال‌شدن، لینک برنامهٔ روزانه از منو پاک شد');
$sql("UPDATE fr_settings SET `value`='1' WHERE `key`='planner_enabled'");

/* ═══ ۷) کرون یادآور (تلگرام/بله) ═══ */
echo "\n═══ ۷) کرون یادآور برنامهٔ روزانه ═══\n";
/* یک کار انجام‌نشده برای امروز تا یادآور چیزی برای فرستادن داشته باشد */
$sql("INSERT INTO fr_planner_tasks (user_id,day,title,priority,category,done) VALUES ($qaUid, '$SITE_TODAY', 'کار معلق کرون', 'normal', 'personal', 0)");
$secret = $sql("SELECT `value` FROM fr_settings WHERE `key`='cron_secret'");
[$cBad] = http('GET', '/cron/reminders.php?key=wrong');
ok($cBad === 403, 'کرون با کلید اشتباه ۴۰۳ می‌دهد', (string)$cBad);
[, , $cronBody] = http('GET', '/cron/reminders.php?key=' . $secret . '&force=1');
ok(str_contains((string)$cronBody, 'sent=') || str_contains((string)$cronBody, 'failed='), 'کرون با کلید درست و force اجرا شد', (string)$cronBody);
ok(str_contains((string)$cronBody, 'failed=1'), 'یک یادآور تلاش شد (تلگرام خاموش → ناموفق ثبت شد)', (string)$cronBody);

/* ═══ ۸) خبرنامهٔ پیامکی ═══ */
echo "\n═══ ۸) خبرنامهٔ پیامکی ═══\n";
[, $n1] = api('/api/newsletter.php', ['action' => 'subscribe', 'mobile' => NL_MOB, 'name' => 'مشترک کیوای', 'source' => 'footer'], $UCS);
ok(!empty($n1['ok']), 'عضویت مهمان در خبرنامه از فوتر موفق بود');
ok($sql("SELECT status FROM fr_newsletter WHERE mobile='" . NL_MOB . "'") === 'active', 'مشترک در دیتابیس فعال شد');
[, $n2] = api('/api/newsletter.php', ['action' => 'subscribe', 'mobile' => NL_MOB, 'name' => 'مشترک کیوای'], $UCS);
ok(str_contains((string)($n2['message'] ?? ''), 'قبلاً'), 'عضویت تکراری با پیام دوستانه پاسخ داده شد');
[, $nBad] = api('/api/newsletter.php', ['action' => 'subscribe', 'mobile' => '123', 'name' => 'نامعتبر'], $UCS);
ok(empty($nBad['ok']), 'موبایل نامعتبر رد شد');
[, $nla] = getx('/admin/newsletter.php');
$nlFa = strtr(NL_MOB, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
ok((str_contains($nla, NL_MOB) || str_contains($nla, $nlFa)) && str_contains($nla, 'ارسال پیامک به خبرنامه'), 'مشترک در پیشخوان خبرنامه دیده می‌شود + فرم ارسال گروهی');
/* ارسال گروهی با چک‌مارک — کاوه‌نگار خاموش است اما مسیر ارسال و لاگ باید کار کند */
$nlId = (int)$sql("SELECT id FROM fr_newsletter WHERE mobile='" . NL_MOB . "'");
[, $nSend] = post_csrf('/admin/newsletter.php', ['act' => 'send', 'ids' => [$nlId], 'message' => 'متن آزمایشی خبرنامهٔ کیوای'], $ACS, COOKIE);
$logRow = $sql("SELECT body FROM fr_sms_logs WHERE mobile='" . NL_MOB . "' ORDER BY id DESC LIMIT 1");
ok(str_contains((string)$logRow, 'متن آزمایشی خبرنامه') || str_contains((string)$nSend, 'ناموفق'), 'مسیر ارسال گروهی اجرا و در لاگ پیامک ثبت شد');
/* ارسال با پترن */
[, $nSend2] = post_csrf('/admin/newsletter.php', ['act' => 'send', 'ids' => [$nlId], 'pattern_code' => 'qa-pattern', 'tokens' => ['سلام','تست']], $ACS, COOKIE);
$logRow2 = $sql("SELECT body FROM fr_sms_logs WHERE mobile='" . NL_MOB . "' ORDER BY id DESC LIMIT 1");
ok(str_contains((string)$logRow2, 'pattern:qa-pattern') || str_contains((string)$logRow2, 'تست'), 'ارسال گروهی با پترن کاوه‌نگار ثبت شد', (string)$logRow2);
[, $nU] = api('/api/newsletter.php', ['action' => 'unsubscribe', 'mobile' => NL_MOB], $UCS);
ok($sql("SELECT status FROM fr_newsletter WHERE mobile='" . NL_MOB . "'") === 'unsub', 'لغو عضویت کار کرد');

/* ═══ ۹) پاک‌سازی نهایی QA ═══ */
echo "\n═══ ۹) پاک‌سازی ═══\n";
$sql("DELETE FROM fr_texts WHERE `k` LIKE 'nav.home'");
$sql("DELETE FROM fr_newsletter WHERE mobile='" . NL_MOB . "'");
$sql("DELETE FROM fr_discounts WHERE code IN ('QA100') OR code LIKE 'PLANNER-%'");
$sql("DELETE pt FROM fr_planner_tasks pt JOIN fr_users u ON u.id=pt.user_id WHERE u.mobile IN ('" . QA_MOB . "','" . QA_MO3 . "')");
$sql("DELETE pw FROM fr_planner_rewards pw JOIN fr_users u ON u.id=pw.user_id WHERE u.mobile IN ('" . QA_MOB . "','" . QA_MO3 . "')");
foreach ([QA_MOB, QA_MO3] as $m) {
    $qu = (int)$sql("SELECT id FROM fr_users WHERE mobile='$m'");
    if ($qu) { $regIds = $sql("SELECT GROUP_CONCAT(id) FROM fr_event_registrations WHERE user_id=$qu"); if ($regIds) $sql("DELETE FROM fr_event_companions WHERE reg_id IN ($regIds)"); $sql("DELETE FROM fr_event_registrations WHERE user_id=$qu"); $sql("UPDATE fr_users SET telegram_chat_id=NULL WHERE id=$qu"); }
}
/* همراهان/میهمانان ساخته‌شدهٔ خودکار هم پاک شوند */
$sql("DELETE FROM fr_users WHERE mobile LIKE '0911000%' OR mobile LIKE '0922000%' OR mobile LIKE '0933000%' OR mobile LIKE '0944000%'");
$sql("UPDATE fr_events SET ws_discount_type='none', ws_discount_tiers=NULL, companion_max=4 WHERE slug='qa-paid-ws'");
foreach (['logo_desc'=>'','footer_links_json'=>'','footer_links2_json'=>'',
          'footer_badge1_img'=>'','footer_badge1_url'=>'','footer_badge1_html'=>'',
          'footer_badge2_img'=>'','footer_badge2_url'=>'','footer_badge2_html'=>'',
          'newsletter_title'=>'خبرنامهٔ پیامکی','newsletter_desc'=>'از دوره‌ها، کارگاه‌ها و تست‌های روانشناسی جدید زودتر از همه باخبر شوید.'] as $k=>$v) {
    $sql("UPDATE fr_settings SET `value`='$v' WHERE `key`='$k'");
}
ok(true, 'داده‌های QA پاک شدند');

echo "\n════════════════════════════════\n";
echo "  نتیجه: $PASS قبول، $FAIL رد\n";
exit($FAIL ? 1 : 0);
