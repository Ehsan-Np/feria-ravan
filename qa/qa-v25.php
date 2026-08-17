<?php
/* ============================================================================
   QA نسخهٔ ۲٫۵ — پنل تبلیغاتی: مخاطبین (ایمپورت اکسل/CSV) + کمپین متنی غنی
   با پترن {name} + صف ارسال حرفه‌ای + آمار دقیق + یادآوری اجتماعی رویدادها
   (مشاوره/کارگاه/دوره/تست ناتمام) + تداوم ساعتِ کاملاً ۲۴ساعته
   ============================================================================ */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

const BASE    = 'http://localhost:8899';
const COOKIE  = '/tmp/qa-v25-cookies.txt';   // مدیر
const AUSER   = 'admin';
const APASS   = 'AdminTest!2026';
const U2_MOB  = '09120008881';   // کاربر متصل تلگرام (qa-tg-111)
const U3_MOB  = '09120009993';   // کاربر با remind خاموش (qa-tg-333)
const C1_MOB  = '09120005555';   // مخاطب دستی (بدون کاربر → پیامک)
const SEG_MAIN = 'QA-ویژه';

date_default_timezone_set('Asia/Tehran');

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
        CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $headers,
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
$sql = fn(string $q) => trim((string)shell_exec(
    "mysql --default-character-set=utf8mb4 -u feria -pferia_test_pass_2026 -N -e " .
    escapeshellarg($q) . " feria_test 2>&1"));
$sqle = fn(string $q) => shell_exec(
    "mysql --default-character-set=utf8mb4 -u feria -pferia_test_pass_2026 -e " .
    escapeshellarg($q) . " feria_test 2>&1");

/* ─── پاک‌سازی QA (شروع و پایان) ─── */
function qa_cleanup(callable $sql, callable $sqle): void {
    $mobs = "'" . U2_MOB . "','" . U3_MOB . "','" . C1_MOB . "','09120007771','09120007772','09120007773','09120006661','09120006662','09120006663'";
    $uRows = $sql("SELECT GROUP_CONCAT(id) FROM fr_users WHERE mobile IN ($mobs)");
    if ($uRows && $uRows !== 'NULL') {
        foreach (['fr_social_links','fr_notify_log','fr_attempts','fr_event_registrations'] as $t)
            $sqle("DELETE t FROM $t t JOIN fr_users u ON u.id=t.user_id WHERE u.mobile IN ($mobs)");
        $sqle("DELETE a FROM fr_consult_appointments a JOIN fr_users u ON u.id=a.user_id WHERE u.mobile IN ($mobs)");
        $sqle("DELETE s FROM fr_consult_slots s WHERE s.id IN (SELECT slot_id FROM fr_consult_appointments a JOIN fr_users u ON u.id=a.user_id WHERE u.mobile IN ($mobs))");
        $sqle("DELETE FROM fr_campaign_messages WHERE mobile IN ($mobs)");
        $sql("DELETE FROM fr_users WHERE mobile IN ($mobs)");
    }
    $sql("DELETE FROM fr_contacts WHERE mobile IN ($mobs)");
    $sql("DELETE FROM fr_campaigns WHERE title LIKE 'کمپین QA%'");
    $sqle("DELETE s FROM fr_consult_slots s LEFT JOIN fr_consult_appointments a ON a.slot_id=s.id WHERE a.id IS NULL AND s.service_id=1 AND s.starts_at > DATE_ADD(NOW(), INTERVAL 20 HOUR) AND s.starts_at < DATE_ADD(NOW(), INTERVAL 28 HOUR)");
    @unlink('/tmp/qa-contacts.csv');
}

echo "── پاک‌سازی QA ۲٫۵ قبلی…\n";
qa_cleanup($sql, $sqle);
foreach (glob('/tmp/qa-v25-cookies*.txt') ?: [] as $f) @unlink($f);

/* کاربران QA */
$oldStartsAt = $sql("SELECT starts_at FROM fr_events WHERE id=21 LIMIT 1");
$sql("INSERT INTO fr_users (mobile,first_name,last_name,status,telegram_chat_id) VALUES ('" . U2_MOB . "','متصل','تستی','active','qa-tg-111')");
$sql("INSERT INTO fr_users (mobile,first_name,last_name,status,telegram_chat_id,notif_prefs) VALUES ('" . U3_MOB . "','خاموش','یادآور','active','qa-tg-333','{\"remind\":0,\"offers\":1}')");
$U2 = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . U2_MOB . "' LIMIT 1");
ok($U2 > 0, 'کاربر QA متصل ساخته شد', "id=$U2");

/* ═══ ۱) نگارش + مهاجرت ۲٫۵ ═══ */
echo "\n═══ ۱) نگارش + مهاجرت ۲٫۵ ═══\n";
$ver = $sql("SELECT `value` FROM fr_settings WHERE `key`='fr_version'");
$verOk = version_compare($ver, '2.5.0', '>=');
$vf = (string)file_get_contents('/tmp/feria-smoke/includes/version.php');
preg_match("/FR_RELEASE', '([0-9.]+)'/", $vf, $vm);
ok($verOk && !empty($vm[1]) && version_compare($vm[1], '2.5.0', '>='), 'نگارش ۲٫۵٫۰+ در DB و کد', $ver.' / code='.($vm[1]??'?'));
foreach (['fr_contacts','fr_campaigns','fr_campaign_messages'] as $t)
    ok($sql("SHOW TABLES LIKE '$t'") === $t, "جدول $t ساخته شد");
ok($sql("SHOW COLUMNS FROM fr_consult_appointments LIKE 'social_reminded_at'") !== '', 'ستون یادآوری اجتماعی مشاوره');
ok($sql("SHOW COLUMNS FROM fr_event_registrations LIKE 'social_reminded_at'") !== '', 'ستون یادآوری اجتماعی رویداد');
ok($sql("SHOW COLUMNS FROM fr_attempts LIKE 'nudge_sent'") !== '', 'ستون nudge_sent برای تست ناتمام');
$enum = $sql("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='feria_test' AND TABLE_NAME='fr_notify_log' AND COLUMN_NAME='msg_kind'");
ok(str_contains($enum, 'campaign') && str_contains($enum, 'event'), 'انواع پیام campaign/event در notify_log');
foreach (['social_event_remind' => '1','consult_social_hours' => '24','event_social_hours' => '24','test_nudge_days' => '2'] as $k => $dv)
    ok($sql("SELECT `value` FROM fr_settings WHERE `key`='$k'") === $dv, "تنظیم پیش‌فرض $k = $dv");

/* ═══ ۲) ورود مدیر + منو ═══ */
echo "\n═══ ۲) ورود مدیر + منو پنل تبلیغاتی ═══\n";
[, $alb] = getx('/admin/login.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $alb, $m0);
[, $bA] = post_csrf('/admin/login.php', ['username' => AUSER, 'password' => APASS], $m0[1] ?? '');
ok(str_contains($bA, 'داشبورد') || str_contains($bA, 'درآمد'), 'ورود مدیر موفق');
[, $ctPage] = getx('/admin/contacts.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $ctPage, $m1);
$ACS = $m1[1] ?? '';
ok($ACS !== '', 'توکن CSRF مدیر');
ok(str_contains($ctPage, 'مخاطبین') && str_contains($ctPage, 'کمپین‌های تبلیغاتی'), 'منوهای «مخاطبین» و «کمپین‌ها» در چیدمان مدیریت');
ok(str_contains($ctPage, 'درون‌ریزی گروهی از اکسل') && str_contains($ctPage, 'accept=".csv,.txt,.xlsx"'), 'فرم ایمپورت اکسل/CSV رندر شد');

/* ═══ ۳) مخاطبین: افزودن دستی + حذف‌تکراری ═══ */
echo "\n═══ ۳) مخاطبین — دستی، حذف‌تکراری، تطبیق اجتماعی ═══\n";
post_csrf('/admin/contacts.php', ['act' => 'add', 'name' => 'علی رضایی', 'mobile' => C1_MOB, 'segment' => SEG_MAIN, 'note' => 'qa'], $ACS);
ok($sql("SELECT name FROM fr_contacts WHERE mobile='" . C1_MOB . "'") === 'علی رضایی', 'مخاطب دستی ثبت شد');
ok($sql("SELECT segment FROM fr_contacts WHERE mobile='" . C1_MOB . "'") === SEG_MAIN, 'گروه مخاطب ذخیره شد');
post_csrf('/admin/contacts.php', ['act' => 'add', 'name' => 'علی رضایی۲', 'mobile' => '+98 912 000 5555', 'segment' => SEG_MAIN], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_contacts WHERE mobile='" . C1_MOB . "'") === 1, 'نرمال‌سازی +98 + حذف‌تکراری (هنوز ۱ ردیف)');
ok($sql("SELECT name FROM fr_contacts WHERE mobile='" . C1_MOB . "'") === 'علی رضایی۲', 'نام مخاطب تکراری به‌روزرسانی شد');
$bad = post_csrf('/admin/contacts.php', ['act' => 'add', 'name' => 'بد', 'mobile' => '123', 'segment' => ''], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_contacts WHERE mobile='123'") === 0, 'موبایل نامعتبر پذیرفته نمی‌شود');
/* مخاطب متصل (هم‌موبایل کاربر تلگرامی) */
post_csrf('/admin/contacts.php', ['act' => 'add', 'name' => 'کاربر متصل', 'mobile' => U2_MOB, 'segment' => SEG_MAIN], $ACS);
[, $ctPage2] = getx('/admin/contacts.php');   // در رندر صفحه، تطبیق اجتماعی اجرا می‌شود
ok($sql("SELECT tg_ok FROM fr_contacts WHERE mobile='" . U2_MOB . "'") === '1', 'تطبیق خودکار: مخاطب هم‌موبایل کاربر متصل → قابل‌دسترس در تلگرام');
ok(str_contains($ctPage2, 'قابل‌دسترس در تلگرام'), 'کارت آمار تلگرام در صفحهٔ مخاطبین');

/* ═══ ۴) ایمپورت: چسباندن متن + فایل CSV ═══ */
echo "\n═══ ۴) ایمپورت مخاطبین (متن چسبانده + فایل اکسل/CSV) ═══\n";
$pasted = "نام,موبایل\nسارا محمدی,09120007771\n09120007772,رضا کریمی\n+989120007773\nخط کاملاً نامعتبر\n";
post_csrf('/admin/contacts.php', ['act' => 'import', 'segment' => 'QA-ورود', 'pasted' => $pasted], $ACS);
ok($sql("SELECT name FROM fr_contacts WHERE mobile='09120007771'") === 'سارا محمدی', 'ایمپورت متنی: نام در ستون اول');
ok($sql("SELECT name FROM fr_contacts WHERE mobile='09120007772'") === 'رضا کریمی', 'ایمپورت متنی: تشخیص خودکار جهت ستون‌ها (موبایل اول)');
ok($sql("SELECT name FROM fr_contacts WHERE mobile='09120007773'") === '', 'ایمپورت متنی: شمارهٔ +98 نرمال شد');
ok($sql("SELECT segment FROM fr_contacts WHERE mobile='09120007773'") === 'QA-ورود', 'گروه مقصد ایمپورت اعمال شد');
ok((int)$sql("SELECT COUNT(*) FROM fr_contacts WHERE segment='QA-ورود'") === 3, 'سربرگ + ردیف نامعتبر نادیده گرفته شد (فقط ۳ مخاطب)');
/* فایل CSV واقعی با multipart */
file_put_contents('/tmp/qa-contacts.csv', "\xEF\xBB\xBFname,mobile\nCSV تست۱,09120006661\n09120006662,CSV تست۲\n");
$ch = curl_init(BASE . '/admin/contacts.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_COOKIEFILE => COOKIE, CURLOPT_COOKIEJAR => COOKIE,
    CURLOPT_POSTFIELDS => [
        '_csrf' => $ACS, 'act' => 'import', 'segment' => 'QA-فایل',
        'contacts_file' => new CURLFile('/tmp/qa-contacts.csv', 'text/csv', 'contacts.csv'),
    ],
]);
curl_exec($ch); curl_close($ch);
ok($sql("SELECT name FROM fr_contacts WHERE mobile='09120006661'") === 'CSV تست۱', 'ایمپورت فایل CSV (BOMدار): ردیف ۱');
ok($sql("SELECT name FROM fr_contacts WHERE mobile='09120006662'") === 'CSV تست۲', 'ایمپورت فایل CSV: جهت معکوس هم تشخیص داده شد');
/* مسدودسازی + حذف */
$C_BLK = (int)$sql("SELECT id FROM fr_contacts WHERE mobile='09120006661'");
post_csrf('/admin/contacts.php', ['act' => 'toggle', 'id' => (string)$C_BLK], $ACS);
ok($sql("SELECT status FROM fr_contacts WHERE id=$C_BLK") === 'blocked', 'مسدودسازی مخاطب');
post_csrf('/admin/contacts.php', ['act' => 'del', 'id' => (string)$C_BLK], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_contacts WHERE id=$C_BLK") === 0, 'حذف مخاطب');
/* مخاطب مسدود در سگمنت اصلی برای تست حذف از مخاطبین کمپین */
post_csrf('/admin/contacts.php', ['act' => 'add', 'name' => 'مسدود تست', 'mobile' => '09120006663', 'segment' => SEG_MAIN], $ACS);
$C_BLK2 = (int)$sql("SELECT id FROM fr_contacts WHERE mobile='09120006663'");
post_csrf('/admin/contacts.php', ['act' => 'toggle', 'id' => (string)$C_BLK2], $ACS);
ok($sql("SELECT status FROM fr_contacts WHERE id=$C_BLK2") === 'blocked', 'مخاطب مسدودِ سگمنت کمپین آماده شد');
/* خروجی CSV */
[$expC, $expB] = getx('/admin/contacts.php?export=csv');
ok($expC === 200 && str_contains($expB, 'name,mobile,segment,telegram,bale,status'), 'خروجی CSV مخاطبین');
ok(str_contains($expB, 'علی رضایی۲'), 'دادهٔ واقعی در خروجی CSV');

/* ═══ ۵) کمپین: ساخت با ویرایشگر غنی + پاک‌سازی ═══ */
echo "\n═══ ۵) کمپین — ساخت، پاک‌سازی HTML، پترن ═══\n";
$sql("UPDATE fr_settings SET `value`='FeriaQaBot' WHERE `key`='telegram_bot_username'");
$sql("UPDATE fr_settings SET `value`='' WHERE `key` IN ('telegram_bot_token','bale_bot_token')");  // ارسال واقعی → failed قابل‌پیش‌بینی
[, $newP] = getx('/admin/campaigns.php?v=new');
ok(str_contains($newP, 'id="rteBox"') && str_contains($newP, 'contenteditable'), 'ویرایشگر متنی غنی (contenteditable)');
ok(str_contains($newP, '{name}') && str_contains($newP, '{site}') && str_contains($newP, '{date}'), 'چیپ‌های پترن شخصی‌سازی');
ok(str_contains($newP, 'id="rtePrev"') && str_contains($newP, 'rteCnt'), 'پیش‌نمایش زنده + شمارندهٔ نویسه');
$bodyHtml = "سلام {name} عزیز 🎉\nاز «{site}» <strong>پیشنهاد ویژه</strong> داریم؛ کد تخفیف: <code>VIP25</code>\n<script>alert(1)</script><a href=\"javascript:x\">کلیک بد</a> <a href=\"https://example.com/off\">صفحهٔ آفر</a>";
[, $afterCreate] = post_csrf('/admin/campaigns.php', [
    'act' => 'create', 'title' => 'کمپین QA ۲٫۵', 'body_html' => $bodyHtml,
    'channel' => 'both', 'audience' => 'contacts', 'segment' => SEG_MAIN, 'sms_invite' => '1',
], $ACS);
$CAMP = (int)$sql("SELECT id FROM fr_campaigns WHERE title='کمپین QA ۲٫۵' ORDER BY id DESC LIMIT 1");
ok($CAMP > 0, 'کمپین ساخته شد', "id=$CAMP");
$cBody = $sql("SELECT body FROM fr_campaigns WHERE id=$CAMP");
ok(str_contains($cBody, '<b>پیشنهاد ویژه</b>') && str_contains($cBody, '<code>VIP25</code>'), 'پاک‌سازی: strong→b و code حفظ شد');
ok(!str_contains($cBody, '<script') && !str_contains($cBody, 'javascript'), 'پاک‌سازی: script و لینک javascript حذف شد');
ok(str_contains($cBody, '{name}') && str_contains($cBody, '<a href="https://example.com/off">'), 'پترن {name} بکر ماند و لینک سالم حفظ شد');
ok((int)$sql("SELECT sms_invite FROM fr_campaigns WHERE id=$CAMP") === 1 && $sql("SELECT status FROM fr_campaigns WHERE id=$CAMP") === 'draft', 'کمپین در حالت پیش‌نویس با پیامک دعوت فعال');

/* ═══ ۶) صف‌سازی: حل مخاطب، حذف‌تکراری موبایل، پیامک دعوت پیش از start ═══ */
echo "\n═══ ۶) صف ارسال — دقت حل مخاطب بر اساس موبایل ═══\n";
[, $afterQueue] = post_csrf('/admin/campaigns.php', ['act' => 'queue', 'id' => (string)$CAMP], $ACS);
ok($sql("SELECT status FROM fr_campaigns WHERE id=$CAMP") === 'queued', 'کمپین به حالت «در صف» رفت');
ok((int)$sql("SELECT total FROM fr_campaigns WHERE id=$CAMP") === 2, 'دقیقاً ۲ پیام در صف (مخاطب مسدود حذف شد)', 'total=' . $sql("SELECT total FROM fr_campaigns WHERE id=$CAMP"));
ok((int)$sql("SELECT COUNT(*) FROM fr_campaign_messages WHERE campaign_id=$CAMP AND mobile='09120006663'") === 0, 'مخاطب مسدود پیامی ندارد');
ok((int)$sql("SELECT COUNT(*) FROM fr_campaign_messages WHERE campaign_id=$CAMP AND channel='telegram' AND mobile='" . U2_MOB . "' AND chat_id='qa-tg-111'") === 1,
   'مخاطب متصل → پیام مستقیم تلگرام با چت‌آیدی واقعی');
ok((int)$sql("SELECT COUNT(*) FROM fr_campaign_messages WHERE campaign_id=$CAMP AND channel='sms' AND mobile='" . U2_MOB . "'") === 0,
   'مخاطب متصل پیامک نمی‌گیرد (بدون ارسال مضاعف)');
$smsRow = $sql("SELECT body FROM fr_campaign_messages WHERE campaign_id=$CAMP AND channel='sms' AND mobile='" . C1_MOB . "' LIMIT 1");
ok($smsRow !== '', 'مخاطب بدون اتصال → یک پیامک');
ok(str_contains($smsRow, 'https://t.me/FeriaQaBot'), 'پیامک حاوی لینک دعوت ربات (پوشش پیش از start)');
ok(str_contains($smsRow, 'علی رضایی۲') && !str_contains($smsRow, '{name}'), 'پترن {name} در پیامک با نام واقعی پر شد');
$tgBody = $sql("SELECT body FROM fr_campaign_messages WHERE campaign_id=$CAMP AND channel='telegram' LIMIT 1");
ok(str_contains($tgBody, 'کاربر متصل') && str_contains($tgBody, '<b>پیشنهاد ویژه</b>'), 'پیام تلگرام: نام + HTML غنی شخصی‌سازی‌شده');
$sitT = $sql("SELECT `value` FROM fr_settings WHERE `key`='site_title'");
ok(str_contains($tgBody, $sitT), 'پترن {site} با نام سایت پر شد', $sitT);

/* ═══ ۷) آمار حرفه‌ای + ارسال دستی دسته‌ای + وضعیت‌ها ═══ */
echo "\n═══ ۷) ارسال دسته‌ای + آمار دقیق ═══\n";
[, $detP] = getx("/admin/campaigns.php?v=detail&id=$CAMP");
ok(str_contains($detP, 'کل پیام‌های صف') && str_contains($detP, 'کمپین QA ۲٫۵'), 'صفحهٔ جزئیات کمپین با کارت‌های آمار');
ok(str_contains($detP, 'توقف') && str_contains($detP, 'ارسال دستی'), 'دکمه‌های کنترل صف (توقف/ارسال دستی)');
[, $afterSend] = post_csrf('/admin/campaigns.php', ['act' => 'send_now', 'id' => (string)$CAMP, 'batch' => '25'], $ACS);
ok($sql("SELECT status FROM fr_campaigns WHERE id=$CAMP") === 'done', 'پس از پردازش کامل صف: وضعیت «پایان‌یافته»');
ok((int)$sql("SELECT sent FROM fr_campaigns WHERE id=$CAMP") === 0 && (int)$sql("SELECT failed FROM fr_campaigns WHERE id=$CAMP") === 2,
   'شمارندهٔ دقیق ارسال/ناموفق (بدون توکن واقعی → ۲ ناموفق)');
ok((int)$sql("SELECT COUNT(*) FROM fr_campaign_messages WHERE campaign_id=$CAMP AND status='pending'") === 0, 'صف خالی شد');
ok((int)$sql("SELECT COUNT(*) FROM fr_campaign_messages WHERE campaign_id=$CAMP AND status='failed' AND error<>''") === 2, 'علت خطا برای هر پیام ثبت شد');
ok($sql("SELECT finished_at IS NOT NULL FROM fr_campaigns WHERE id=$CAMP") === '1', 'finished_at ثبت شد');
ok((int)$sql("SELECT COUNT(*) FROM fr_notify_log WHERE user_id=$U2 AND msg_kind='campaign'") >= 1, 'لاگ کمپین در مرکز اطلاع‌رسانی کاربر');
/* ارسال مجدد ناموفق‌ها */
post_csrf('/admin/campaigns.php', ['act' => 'resend_failed', 'id' => (string)$CAMP], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_campaign_messages WHERE campaign_id=$CAMP AND status='pending'") === 2, 'ارسال مجدد: ناموفق‌ها به صف برگشتند');
ok($sql("SELECT status FROM fr_campaigns WHERE id=$CAMP") === 'queued', 'کمپین دوباره «در صف» شد');

/* ═══ ۸) ورکر کرون کمپین‌ها (notify.php) + توقف/ادامه/حذف ═══ */
echo "\n═══ ۸) کرون ارسال کمپین + توقف/ادامه/حذف ═══\n";
$CRON = $sql("SELECT `value` FROM fr_settings WHERE `key`='cron_secret'");
[$cronC, $cronB] = getx('/cron/notify.php?key=' . urlencode($CRON));
ok($cronC === 200 && str_contains($cronB, 'campaigns: worked=2'), 'موتور ارسال کمپین در کرون کار کرد', trim(explode("\n", $cronB)[2] ?? ''));
ok($sql("SELECT status FROM fr_campaigns WHERE id=$CAMP") === 'done', 'کرون صف را تا پایان بُرد');
[, $cronB2] = getx('/cron/notify.php?key=' . urlencode($CRON));
ok(str_contains($cronB2, 'campaigns: idle'), 'بدون کمپین فعال: کرون idle است');
post_csrf('/admin/campaigns.php', ['act' => 'resend_failed', 'id' => (string)$CAMP], $ACS);
post_csrf('/admin/campaigns.php', ['act' => 'pause', 'id' => (string)$CAMP], $ACS);
ok($sql("SELECT status FROM fr_campaigns WHERE id=$CAMP") === 'paused', 'توقف کمپین');
post_csrf('/admin/campaigns.php', ['act' => 'resume', 'id' => (string)$CAMP], $ACS);
ok($sql("SELECT status FROM fr_campaigns WHERE id=$CAMP") === 'queued', 'ادامهٔ کمپین');
post_csrf('/admin/campaigns.php', ['act' => 'del', 'id' => (string)$CAMP], $ACS);
ok((int)$sql("SELECT COUNT(*) FROM fr_campaigns WHERE id=$CAMP") === 0, 'حذف کمپین');
ok((int)$sql("SELECT COUNT(*) FROM fr_campaign_messages WHERE campaign_id=$CAMP") === 0, 'حذف آبشاری صف کمپین (FK cascade)');

/* ═══ ۹) یادآوری اجتماعی رویدادها (cron/reminders.php) ═══ */
echo "\n═══ ۹) یادآوری اجتماعی: مشاوره + کارگاه + تست ناتمام ═══\n";
$sql("UPDATE fr_settings SET `value`='1' WHERE `key`='social_event_remind'");
$sql("UPDATE fr_settings SET `value`='24' WHERE `key` IN ('consult_social_hours','event_social_hours')");
$sql("UPDATE fr_settings SET `value`='2' WHERE `key`='test_nudge_days'");
$sql("UPDATE fr_events SET starts_at=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id=21");
$sqle("INSERT INTO fr_consult_slots (service_id, starts_at, ends_at, status) VALUES (1, DATE_ADD(NOW(), INTERVAL 24 HOUR), DATE_ADD(DATE_ADD(NOW(), INTERVAL 24 HOUR), INTERVAL 45 MINUTE), 'booked')");
$SLOT = (int)$sql("SELECT id FROM fr_consult_slots WHERE service_id=1 AND status='booked' ORDER BY id DESC LIMIT 1");
$U3 = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . U3_MOB . "' LIMIT 1");
$sqle("INSERT INTO fr_consult_appointments (slot_id, service_id, consultant_id, user_id, mode, status, note) VALUES ($SLOT,1,1,$U2,'online','booked','qa25-on'), ($SLOT,1,1,$U3,'inperson','booked','qa25-off')");
$sqle("INSERT INTO fr_event_registrations (event_id, user_id, status, amount_base) VALUES (21,$U2,'paid',1000000)");
$TID = (int)$sql("SELECT MIN(id) FROM fr_tests WHERE status<>'archived' OR TRUE");
$sqle("INSERT INTO fr_attempts (user_id, test_id, mode, status, progress, started_at) VALUES ($U2,$TID,'free','in_progress',5, DATE_SUB(NOW(), INTERVAL 3 DAY))");
ok($SLOT > 0 && $U3 > 0 && $TID > 0, 'دادهٔ QA کاشت شد', "slot=$SLOT,tid=$TID");
[$remC, $remB] = getx('/cron/reminders.php?key=' . urlencode($CRON) . '&force=1');
ok($remC === 200 && str_contains($remB, 'events:sent=0,failed=3'), 'کرون یادآوری: هر ۳ رویداد پردازش شدند (ارسال بدون توکن = ناموفق ثبت‌شده)',
   preg_match('/events:[^\n]+/', $remB, $mm) ? $mm[0] : 'missing');
ok($sql("SELECT social_reminded_at IS NOT NULL FROM fr_consult_appointments WHERE note='qa25-on'") === '1', 'یادآوری جلسهٔ مشاوره ثبت و مُهر زمان خورد');
ok($sql("SELECT social_reminded_at IS NULL FROM fr_consult_appointments WHERE note='qa25-off'") === '1', 'کاربر با یادآوری خاموش هیچ پیامی نگرفت (احترام به notif_prefs)');
ok($sql("SELECT social_reminded_at IS NOT NULL FROM fr_event_registrations WHERE user_id=$U2 AND event_id=21") === '1', 'یادآوری کارگاه ثبت و مُهر زمان خورد');
ok($sql("SELECT nudge_sent FROM fr_attempts WHERE user_id=$U2 AND test_id=$TID ORDER BY id DESC LIMIT 1") === '1', 'یادآوری تست ناتمام ثبت و مُهر خورد');
[$rc2, $remB2] = getx('/cron/reminders.php?key=' . urlencode($CRON) . '&force=1');
ok(!str_contains($remB2, 'events:sent'), 'هر یادآوری فقط یک‌بار ارسال می‌شود (اجرای دوم هیچ رویدادی ندارد)');
$sql("UPDATE fr_events SET starts_at=" . ($oldStartsAt !== '' && $oldStartsAt !== 'NULL' ? "'" . $oldStartsAt . "'" : 'NULL') . " WHERE id=21");

/* ═══ ۱۰) کارت تنظیمات یادآوری رویدادها در اطلاع‌رسانی ═══ */
echo "\n═══ ۱۰) پیشخوان اطلاع‌رسانی — کارت ۲٫۵ ═══\n";
[, $ntP] = getx('/admin/notifications.php');
ok(str_contains($ntP, 'save_events') && str_contains($ntP, 'consult_social_hours') && str_contains($ntP, 'event_social_hours') && str_contains($ntP, 'test_nudge_days'), 'فرم تنظیمات یادآوری رویدادها رندر شد');
ok(str_contains($ntP, 'reminders.php'), 'راهنمای کرون: کرون دوم (reminders.php) مستند شد');
ok(str_contains($ntP, 'کمپین تبلیغاتی'), 'برچسب فارسی کمپین در لاگ ارسال‌های اخیر (از ردیف واقعی بخش ۷)');
post_csrf('/admin/notifications.php', ['act' => 'save_events', 'consult_social_hours' => '12', 'event_social_hours' => '48', 'test_nudge_days' => '3'], $ACS);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='social_event_remind'") === '0'
   && $sql("SELECT `value` FROM fr_settings WHERE `key`='consult_social_hours'") === '12'
   && $sql("SELECT `value` FROM fr_settings WHERE `key`='test_nudge_days'") === '3', 'ذخیرهٔ تنظیمات یادآوری (خاموش + ۱۲ساعته + ۳روزه)');
post_csrf('/admin/notifications.php', ['act' => 'save_events', 'social_event_remind' => '1', 'consult_social_hours' => '24', 'event_social_hours' => '24', 'test_nudge_days' => '2'], $ACS);
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='social_event_remind'") === '1'
   && $sql("SELECT `value` FROM fr_settings WHERE `key`='event_social_hours'") === '24', 'بازگردانی تنظیمات به پیش‌فرض فعال');

/* ═══ ۱۱) ساعت کاملاً ۲۴ساعته + برچسب‌های پنل کاربر ═══ */
echo "\n═══ ۱۱) ساعت ۲۴ساعته + برچسب‌ها ═══\n";
$grepTime = trim((string)shell_exec('grep -rl \'type="time"\' /home/user/feria-ravan/admin /home/user/feria-ravan/panel 2>/dev/null'));
ok($grepTime === '', 'هیچ input type=time (AM/PM مرورگر) در پنل‌ها نیست');
$grepAmpm = trim((string)shell_exec("grep -rnE 'h:i[ ]+A|g:i[ ]+a|A\\)' /home/user/feria-ravan/cron/reminders.php | grep -v 'جمله' || true"));
ok(str_contains((string)file_get_contents('/home/user/feria-ravan/cron/reminders.php'), "jdate('l j F Y — H:i'"), 'متن یادآوری رویدادها با H:i (۲۴ساعته) فرمت می‌شود');
$profSrc = (string)file_get_contents('/home/user/feria-ravan/panel/profile.php');
ok(str_contains($profSrc, "'campaign' => 'اطلاعیه و پیشنهاد ویژه'") && str_contains($profSrc, "'event' => 'یادآوری رویداد'"), 'برچسب فارسی کمپین/رویداد در پروفایل کاربر');
$ntfa = (string)file_get_contents('/home/user/feria-ravan/admin/notifications.php');
ok(str_contains($ntfa, "'campaign' => 'کمپین تبلیغاتی'") && str_contains($ntfa, "'event' => 'یادآور رویداد'"), 'برچسب فارسی کمپین/رویداد در پیشخوان');

/* ─── پاک‌سازی پایانی ─── */
echo "\n── پاک‌سازی نهایی…\n";
qa_cleanup($sql, $sqle);

echo "\n══════════════════════════════\n";
echo "نتیجهٔ QA ۲٫۵:  ✅ $PASS موفق   ❌ $FAIL ناموفق\n";
exit($FAIL > 0 ? 1 : 0);
