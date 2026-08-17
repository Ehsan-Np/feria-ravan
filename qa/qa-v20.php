<?php
/* ============================================================================
   QA نسخهٔ ۲٫۰ — دیدگاه+امتیاز ستاره‌ای، ورود اکسل، لیبل‌ها، گزارش PDF A4+QR،
   بازطراحی پنل، سختی‌گرفتن احراز هویت (کروم ۱۵۱) و پاکیزگی مرورگرها
   ============================================================================ */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

const BASE   = 'http://localhost:8899';
const COOKIE = '/tmp/qa-v20-cookies.txt';
const AUSER  = 'admin';
const APASS  = 'AdminTest!2026';
const QA_MOB = '09120008888';   // کاربر شرکت‌کنندهٔ QA
const QA_MO2 = '09120009999';   // کاربر غیرشرکت‌کننده (ایجاد نمی‌شود: صرفاً مهمان)

$PASS = 0; $FAIL = 0;
function ok(bool $c, string $name, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($c) { $PASS++; echo "  ✅ $name" . ($extra ? " — $extra" : '') . "\n"; }
    else    { $FAIL++; echo "  ❌ $name" . ($extra ? " — $extra" : '') . "\n"; }
}
function http(string $method, string $path, array|string $data = [], array $headers = []): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => COOKIE, CURLOPT_COOKIEJAR => COOKIE,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$code, substr($raw, 0, $hlen), substr($raw, $hlen)];
}
/* GET با دنبال‌کردن دستی ریدایرکت */
function getx(string $path): array {
    [$c, $h, $b] = http('GET', $path);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]));
    }
    return [$c, $b];
}
function post_csrf(string $path, array $fields, string $csrf): array {
    [$c, $h, $b] = http('POST', $path, $fields + ['_csrf' => $csrf]);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]));
    }
    return [$c, $b];
}
/* POST چندبخشی (آپلود فایل) + دنبال‌کردن ریدایرکت با GET */
function post_upload(string $path, array $fields, string $fileField, string $file): array {
    $ch = curl_init(BASE . $path);
    $fields[$fileField] = new CURLFile($file, 'text/csv', basename($file));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => COOKIE, CURLOPT_COOKIEJAR => COOKIE,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields, CURLOPT_TIMEOUT => 60,
    ]);
    $raw = (string)curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $h = substr($raw, 0, $hlen); $b = substr($raw, $hlen);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]));
    }
    return [$c, $b];
}
/* فراخوانی JSON API با هدر X-CSRF */
function api(string $url, array $data, string $token): array {
    [$c, $h, $b] = http('POST', $url, json_encode($data, JSON_UNESCAPED_UNICODE), [
        'Content-Type: application/json', 'X-CSRF: ' . $token,
    ]);
    $j = json_decode($b, true);
    return [$c, is_array($j) ? $j : ['__raw' => $b]];
}
$sql = fn(string $q) => trim((string)shell_exec(
    "mysql --default-character-set=utf8mb4 -u feria -pferia_test_pass_2026 -N -e " .
    escapeshellarg($q) . " feria_test 2>&1"));

@unlink(COOKIE);
$sql("DELETE FROM fr_rate_limits WHERE bucket LIKE 'admin_login%'");

/* ═══ پاک‌سازی QA قبلی ═══ */
echo "── پاک‌سازی QA قبلی…\n";
$sql("DELETE rv FROM fr_reviews rv WHERE rv.author_mobile IN ('09120008888','09120009999','09120000001','09120000002','09120000003')
       OR rv.author_name IN ('کیوای کاربر','سرکار خانم رستمی','آقای محمدی','خانم نادری')");
$qu = (int)$sql("SELECT id FROM fr_users WHERE mobile='09120008888'");
if ($qu) {
    $sql("DELETE rp FROM fr_reports  rp JOIN fr_attempts a ON a.id=rp.attempt_id WHERE a.user_id=$qu");
    $sql("DELETE rs FROM fr_results  rs JOIN fr_attempts a ON a.id=rs.attempt_id WHERE a.user_id=$qu");
    $sql("DELETE an FROM fr_answers  an JOIN fr_attempts a ON a.id=an.attempt_id WHERE a.user_id=$qu");
    $sql("DELETE FROM fr_attempts WHERE user_id=$qu");
    $sql("DELETE FROM fr_event_registrations WHERE user_id=$qu");
    $sql("DELETE FROM fr_users WHERE id=$qu");
}
$sql("DELETE FROM fr_review_labels WHERE title='لیبل کیوای'");
$sql("DELETE FROM fr_rate_limits WHERE bucket LIKE '%09120008888%'");
/* سازگاری قافل رانرها: qa-v19 در تست زباله‌دان، آزمون ۱ را trash می‌کند → برگردان */
$sql("UPDATE fr_tests SET status='active' WHERE slug IN ('disc-family','disc-business','ysq-long','ysq-short','gardner-mi','eq-shutte')");

/* ═══ ۰) خط‌لبهٔ به‌روزرسانی خودکار (جدول‌ها + تنظیمات ۲٫۰) ═══ */
echo "\n═══ ۰) خط‌لبهٔ ۲٫۰ ═══\n";
ok($sql("SHOW TABLES LIKE 'fr_reviews'") !== '' && $sql("SHOW TABLES LIKE 'fr_review_labels'") !== '', 'جداول fr_reviews + fr_review_labels وجود دارند');
ok((int)$sql("SELECT COUNT(*) FROM fr_review_labels") >= 5, 'لیبل‌های پیش‌فرض نشانده شدند (≥۵)', (string)$sql("SELECT COUNT(*) FROM fr_review_labels"));
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='review_labels_seeded'") === '1', 'مارکر review_labels_seeded ثبت است');
ok(version_compare($sql("SELECT `value` FROM fr_settings WHERE `key`='fr_version'"), '2.0.0', '>='), 'نگارش DB حداقل ۲٫۰٫۰ است');
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='report_qr_show'") === '1', 'تنظیم report_qr_show بذر شد');

/* ═══ ۱) رندر عمومی سکشن دیدگاه‌ها ═══ */
echo "\n═══ ۱) رندر عمومی دیدگاه‌ها ═══\n";
[$c, $b] = getx('/test-page.php?s=disc-family');
ok($c === 200 && str_contains($b, 'rv-box') && str_contains($b, 'دیدگاه‌ها و پرسش‌ها'), 'صفحهٔ تست روانشناسی عمومی: سکشن دیدگاه‌ها و پرسش‌ها رندر شد');
[$c2, $b2] = getx('/workshop.php?s=free-ws-test');
ok($c2 === 200 && str_contains($b2, 'rv-box'), 'صفحهٔ کارگاه عمومی: سکشن دیدگاه‌ها رندر شد');
ok(str_contains($b2, 'وارد حساب شوید'), 'مهمان: پیوند ورود برای ثبت دیدگاه دیده می‌شود');

/* ═══ ۲) لاگین مدیر + صفحات مدیریت دیدگاه ═══ */
echo "\n═══ ۲) مدیریت ═══\n";
[, $lb] = getx('/admin/login.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $lb, $m);
[, $dash] = post_csrf('/admin/login.php', ['username' => AUSER, 'password' => APASS], $m[1] ?? '');
ok(str_contains($dash, 'خالص درآمد'), 'ورود مدیر موفق');
[, $acr] = getx('/admin/tests.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $acr, $m);
$ACS = $m[1] ?? '';
ok($ACS !== '', 'توکن CSRF مدیر');
[, $ar] = getx('/admin/reviews.php');
ok(str_contains($ar, 'ورود فله') && str_contains($ar, 'لیبل‌ها'), 'صفحهٔ مدیریت دیدگاه‌ها با تب‌های کامل رندر شد');
[, $st] = getx('/admin/settings.php?tab=reviews');
ok(str_contains($st, 'reviews_auto_approve'), 'تب تنظیمات دیدگاه‌ها رندر شد');
[, $st2] = getx('/admin/settings.php?tab=report');
ok(str_contains($st2, 'report_qr_link') && str_contains($st2, 'report_qr_show'), 'تنظیمات بارکد QR در تب گزارش رندر شد');

/* ═══ ۳) کاربر: ثبت‌نام/ورود از مسیر API (همان مسیر کروم) ═══ */
echo "\n═══ ۳) جریان کاربر از API ═══\n";
[, $authPage] = getx('/auth.php');
preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $authPage, $m2);
$UCS = $m2[1] ?? '';
ok($UCS !== '', 'توکن CSRF کاربر از متا خوانده شد');
[, $r1] = api('/api/auth.php', ['action' => 'send_otp', 'mobile' => QA_MOB], $UCS);
ok(!empty($r1['ok']) && !empty($r1['debug_code']), 'ارسال OTP موفق (+ کد تست)', $r1['debug_code'] ?? '');
[, $r2] = api('/api/auth.php', ['action' => 'verify_otp', 'mobile' => QA_MOB, 'code' => $r1['debug_code'] ?? ''], $UCS);
ok(!empty($r2['ok']) && !empty($r2['needs_register']), 'تأیید OTP → نیازمند ثبت‌نام');
[, $r3] = api('/api/auth.php', ['action' => 'register_name', 'full_name' => 'کیوای کاربر'], $UCS);
$QAUID = (int)$sql("SELECT id FROM fr_users WHERE mobile='" . QA_MOB . "'");
ok($QAUID > 0, 'حساب کاربر QA ساخته شد', "id=$QAUID");

/* ═══ ۴) API دیدگاه: سخت‌گیری‌ها ═══ */
echo "\n═══ ۴) سخت‌گیری‌های API دیدگاه ═══\n";
[, $j] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'test', 'ref_id' => 1, 'rating' => 5, 'body' => 'بدون شرکت‌کردن نباید قبول شود'], $UCS);
ok(empty($j['ok']) && str_contains((string)($j['message'] ?? ''), 'شرکت‌کنندگان'), 'دیدگاهِ بدون شرکت‌کردن رد شد', $j['message'] ?? '');
[, $j2] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'test', 'ref_id' => 1, 'rating' => 9, 'body' => 'امتیاز نامعتبر'], $UCS);
ok(empty($j2['ok']), 'امتیاز خارج از بازهٔ ۱–۵ رد شد');
/* ۴۱۹ با توکن اشتباه */
[$c419, $j419] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'test', 'ref_id' => 1, 'rating' => 5, 'body' => 'تست توکن'], str_repeat('0', 64));
ok($c419 === 419 && !empty($j419['csrf_expired']) && !empty($j419['message']), 'پاسخ 419 دارای csrf_expired + message است', "http=$c419");

/* ═══ ۵) شرکت‌کننده‌شدن و ثبت دیدگاه واقعی ═══ */
echo "\n═══ ۵) شرکت‌کننده + ثبت دیدگاه ═══\n";
$sql("INSERT INTO fr_attempts (user_id,test_id,mode,status,progress,completed_at)
      VALUES ($QAUID,1,'free','completed',100,NOW())");
$QAATT = (int)$sql("SELECT id FROM fr_attempts WHERE user_id=$QAUID ORDER BY id DESC LIMIT 1");
/* raw_extra با ساختار most/least/n تا نمودار خطی DISC دادهٔ واقعی داشته باشد */
foreach ([['D',72,'high',14,4],['I',58,'medium',10,7],['S',41,'medium',2,12],['C',66,'high',5,9]] as [$sc,$pc,$bn,$mo,$le]) {
    $rx = json_encode(['most' => $mo, 'least' => $le, 'n' => 28]);
    $sql("INSERT INTO fr_results (attempt_id,scale_code,raw_score,max_score,percent,band,raw_extra)
          VALUES ($QAATT,'$sc',$pc,100,$pc,'$bn'," . escapeshellarg($rx) . ")");
}
ok($QAATT > 0, 'تلاش تکمیل‌شدهٔ شرکت‌کننده ساخته شد', "attempt=$QAATT");
$body1 = 'آزمون بسیار دقیق و گزارش حرفه‌ای بود؛ از کیفیت تحلیل‌ها شگفت‌زده شدم.';
[, $j3] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'test', 'ref_id' => 1, 'rating' => 5, 'body' => $body1], $UCS);
ok(!empty($j3['ok']) && ($j3['status'] ?? '') === 'approved', 'ثبت دیدگاه شرکت‌کننده (تأیید خودکار)', $j3['message'] ?? '');
[, $tp] = getx('/test-page.php?s=disc-family');
ok(str_contains($tp, $body1) && str_contains($tp, 'کیوای کاربر'), 'دیدگاه روی صفحهٔ عمومی منتشر شد');
ok(str_contains($tp, 'شرکت‌کننده'), 'لیبل «شرکت‌کننده» کنار نام دیده می‌شود');
$body2 = 'پس از خواندن دقیق گزارش، امتیازم را به‌روز می‌کنم؛ تجربهٔ خوبی بود.';
[, $j4] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'test', 'ref_id' => 1, 'rating' => 4, 'body' => $body2], $UCS);
ok(!empty($j4['ok']) && !empty($j4['updated']), 'ثبت دوباره = ویرایش (نه درج مجدد)');
ok((int)$sql("SELECT COUNT(*) FROM fr_reviews WHERE user_id=$QAUID AND context='test' AND ref_id=1") === 1, 'هر کاربر فقط یک دیدگاه برای هر مورد');

/* ═══ ۶) ورود فلهٔ اکسل/CSV ═══ */
echo "\n═══ ۶) ورود فلهٔ اکسل ═══\n";
$csv = "\xEF\xBB\xBF" . implode("\n", [
    'بخش,مورد,نام,موبایل,امتیاز,متن,لیبل',
    'test,disc-family,سرکار خانم رستمی,09120000001,5,"کارگاه فوق‌العاده بود، مدرس حرفه‌ای",خریدار نسخه ویژه',
    'آزمون,disc-family,آقای محمدی,09120000002,4,"گزارش دقیق و کاربردی بود",',
    'کارگاه,free-ws-test,خانم نادری,09120000003,5,"تمرین‌های عملی عالی بودند",شرکت‌کننده',
]);
file_put_contents('/tmp/qa-v20-import.csv', $csv);
$n0 = (int)$sql("SELECT COUNT(*) FROM fr_reviews WHERE source='excel'");
[, $imp1] = post_upload('/admin/reviews.php', ['act' => 'import', 'status' => 'approved', '_csrf' => $ACS], 'sheet', '/tmp/qa-v20-import.csv');
$n1 = (int)$sql("SELECT COUNT(*) FROM fr_reviews WHERE source='excel'");
ok($n1 - $n0 === 3, 'ورود فله: ۳ دیدگاه از اکسل درج شد', "before=$n0 after=$n1");
ok(str_contains($imp1, 'دیدگاه ثبت شد') , 'پیام موفقیت ورود فله به مدیر نشان داده شد');
[, $imp2] = post_upload('/admin/reviews.php', ['act' => 'import', 'status' => 'approved', '_csrf' => $ACS], 'sheet', '/tmp/qa-v20-import.csv');
$n2 = (int)$sql("SELECT COUNT(*) FROM fr_reviews WHERE source='excel'");
ok($n2 === $n1, 'ورود مجدد همان فایل: هیچ تکراری درج نشد (idempotent)');
[, $tp2] = getx('/test-page.php?s=disc-family');
ok(str_contains($tp2, 'سرکار خانم رستمی') && str_contains($tp2, 'خریدار نسخه ویژه'), 'دیدگاه‌های اکسلی با لیبل در صفحهٔ عمومی منتشر شدند');
[, $wp] = getx('/workshop.php?s=free-ws-test');
ok(str_contains($wp, 'خانم نادری'), 'دیدگاه اکسلی کارگاه روی صفحهٔ کارگاه منتشر شد');

/* ═══ ۷) لیبل‌ها: CRUD + تخصیص دستی ═══ */
echo "\n═══ ۷) لیبل‌های قابل‌شخصی‌سازی ═══\n";
[, $lbSaved] = post_csrf('/admin/reviews.php', ['act' => 'label_save', 'id' => 0, 'title' => 'لیبل کیوای', 'color' => '#123456', 'bg' => '#ddeeff'], $ACS);
$lid = (int)$sql("SELECT id FROM fr_review_labels WHERE title='لیبل کیوای'");
ok($lid > 0, 'لیبل جدید ساخته شد', "id=$lid");
[, $ar2] = getx('/admin/reviews.php?tab=labels');
ok(str_contains($ar2, 'لیبل کیوای'), 'لیبل جدید در تب لیبل‌ها دیده می‌شود');
/* تخصیص لیبل سفارشی به یک دیدگاه عضو */
$ridAny = (int)$sql("SELECT id FROM fr_reviews WHERE author_name='آقای محمدی' LIMIT 1");
post_csrf('/admin/reviews.php', ['act' => 'save', 'id' => $ridAny, 'author_name' => 'آقای محمدی', 'rating' => 4,
    'body' => 'گزارش دقیق و کاربردی بود', 'label_id' => $lid, 'label_text' => '', 'status' => 'approved'], $ACS);
[, $tp3] = getx('/test-page.php?s=disc-family');
ok(str_contains($tp3, 'لیبل کیوای'), 'لیبل سفارشی کنار دیدگاه در صفحهٔ عمومی چاپ شد');

/* ═══ ۸) جریان تأیید دستی (reviews_auto_approve=0) ═══ */
echo "\n═══ ۸) تأیید دستی ═══\n";
$sql("UPDATE fr_settings SET `value`='0' WHERE `key`='reviews_auto_approve'");
$WSID = (int)$sql("SELECT id FROM fr_events WHERE slug='free-ws-test'");
$sql("INSERT INTO fr_event_registrations (event_id,user_id,status) VALUES ($WSID,$QAUID,'paid')");
[, $j5] = api('/api/reviews.php', ['action' => 'submit', 'context' => 'workshop', 'ref_id' => $WSID, 'rating' => 5, 'body' => 'کارگاه عالی و به‌روز بود، توصیه می‌کنم.'], $UCS);
ok(!empty($j5['ok']) && ($j5['status'] ?? '') === 'pending', 'ثبت دیدگاه کارگاه: در صف تأیید ماند', $j5['status'] ?? '');
[, $wp2] = getx('/workshop.php?s=free-ws-test');
$inList = false;
if (preg_match_all('#<article class="rv-item">(.+?)</article>#su', $wp2, $mm)) {
    foreach ($mm[1] as $bl) if (str_contains($bl, 'کارگاه عالی و به‌روز بود')) { $inList = true; break; }
}
ok(!$inList && substr_count($wp2, 'کارگاه عالی و به‌روز بود') === 1,
   'دیدگاه تأییدنشده در فهرست عمومی نیست (فقط پیش‌نمایش صاحبش در فرم)');
$ridP = (int)$sql("SELECT id FROM fr_reviews WHERE status='pending' AND context='workshop' AND user_id=$QAUID");
[, $apr] = post_csrf('/admin/reviews.php', ['act' => 'approve', 'id' => $ridP], $ACS);
[, $wp3] = getx('/workshop.php?s=free-ws-test');
ok(str_contains($wp3, 'کارگاه عالی و به‌روز بود'), 'بعد از تأیید مدیر، دیدگاه عمومی شد');
$sql("UPDATE fr_settings SET `value`='1' WHERE `key`='reviews_auto_approve'");

/* ═══ ۹) گزارش PDF نسخهٔ ۲٫۰ ═══ */
echo "\n═══ ۹) گزارش PDF (A4 + نمودار + QR) ═══\n";
[$pc, $pb] = getx('/panel/report-pdf.php?attempt=' . $QAATT);
ok($pc === 200, 'صفحهٔ چاپ گزارش باز شد');
ok(str_contains($pb, '@page') && str_contains($pb, 'size: A4'), 'ابعاد دقیق ISO A4 در CSS چاپ');
ok(preg_match('/210mm.+min-height:297mm|min-height:297mm/s', $pb) === 1, 'ورق پیش‌نمایش ۲۱۰×۲۹۷ میلی‌متر');
ok(str_contains($pb, 'chart.googleapis.com/chart?cht=qr'), 'بارکد QR از Google Chart API');
ok(str_contains($pb, '>Feria.ir<'), 'کپشن «Feria.ir» زیر بارکد');
ok(str_contains($pb, 'pr-verify') && str_contains($pb, 'کد پیگیری گزارش'), 'کارت تأییدیهٔ صدور (بارکد+کد) رندر شد');
ok(str_contains($pb, "data-pr='") && str_contains($pb, 'نمودار خطی نتایج DISC') && (str_contains($pb, '&quot;type&quot;:&quot;line&quot;') || str_contains($pb, '"type":"line"')), 'دیتای نمودار خطی DISC در گزارش چاپی تزریق شد');
ok(str_contains($pb, 'دانلود PDF'), 'دکمهٔ دانلود PDF در نوار ابزار');
ok(str_contains($pb, 'drawLine') && str_contains($pb, 'drawHBars'), 'موتور رسم نمودارهای چاپی (خطی/افقی) در صفحه');
/* مهمان نباید گزارش ببیند */
@file_put_contents(COOKIE . '.bak', (string)@file_get_contents(COOKIE));
@copy(COOKIE, COOKIE . '.keep');
@unlink(COOKIE);
[$gc, $gh, $gb] = http('GET', '/panel/report-pdf.php?attempt=' . $QAATT);
ok(in_array($gc, [301,302,303], true), 'دسترسی مهمان به گزارش چاپی رد شد (ریدایرکت)', "http=$gc");
@copy(COOKIE . '.keep', COOKIE);

/* ═══ ۱۰) پنل بازطراحی‌شده ═══ */
echo "\n═══ ۱۰) پنل کاربری ۲٫۰ ═══\n";
[$dc, $db] = getx('/panel/');
ok($dc === 200, 'داشبورد پنل باز شد');
ok(str_contains($db, 'pc-canvas') && str_contains($db, 'pc-card'), 'کارت‌های حرفه‌ای آزمون (pc-card) در داشبورد');
ok(!str_contains($db, 'border:1px solid var(--line)'), 'باکس‌های قدیمی حذف شدند');
$mobFa = str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], QA_MOB);
ok(str_contains($db, $mobFa), 'موبایل کاربر (ارقام فارسی) در کارت هویت دیده می‌شود');
ok(str_contains($db, 'کارگاه‌ها و دوره‌های من'), 'بخش رویدادهای من در داشبورد');
ok(str_contains($db, 'تکمیل پروفایل') && str_contains($db, 'عضو فریا از'), 'درصد تکمیل پروفایل + تاریخ عضویت');
ok(str_contains($db, 'ثبت‌نام رویدادها'), 'کارت آمار رویدادها در داشبورد');
[$tc, $tb] = getx('/panel/tests.php');
ok($tc === 200 && str_contains($tb, 'pc-card'), 'panel/tests.php با کارت مشترک رندر شد');

/* ═══ ۱۱) سختی‌گیر احراز هویت + مرورگرها ═══ */
echo "\n═══ ۱۱) سختی‌گیر کروم/مرورگرها ═══\n";
$js = (string)file_get_contents('/tmp/feria-smoke/assets/js/feria.js');
ok(str_contains($js, 'csrf_expired'), 'feria.js مدیریت نشست ۴۱۹ خودکار دارد');
ok(str_contains($js, 'pageshow') && str_contains($js, 'persisted'), 'رفع bfcache (بازگشت از کش مرورگر) تعبیه شده');
ok(str_contains($js, 'catch (netErr)'), 'api() با خطای شبکه هرگز فرم را قفل نمی‌کند');
$sec = (string)file_get_contents('/tmp/feria-smoke/includes/security.php');
ok(str_contains($sec, "'csrf_expired'=>true"), 'security.php پاسخ JSON ۴۱۹ استاندارد دارد');
$uas = [
    'Chrome151'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
    'Firefox128' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
    'Edge151'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0',
    'Safari17'   => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
    'ChromeAndroid' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/151.0.0.0 Mobile Safari/537.36',
];
$uaOk = true;
foreach ($uas as $name => $ua) {
    $ch = curl_init(BASE . '/auth.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 20]);
    curl_exec($ch);
    $uaOk = $uaOk && (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
    curl_close($ch);
}
ok($uaOk, 'صفحهٔ ورود در ۵/User-Agent مرورگر پاسخ ۲۰۰ می‌دهد');
[$hc, $hb] = getx('/');
ok($hc === 200, 'صفحهٔ اصلی سالم (رگرسیون)');

/* ═══ پایانی ═══ */
echo "\n════════════════════════════════\n";
echo "  نتیجه: $PASS قبول، $FAIL رد\n";
exit($FAIL ? 1 : 0);
