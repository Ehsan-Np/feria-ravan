<?php
/* QA نسخهٔ ۱٫۹ — رانر برنامه‌ای: عملیات گروهی آزمون‌ها، پیامک مدیر، دقت درآمد، مدیریت ثبت‌نام‌شدگان */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

const BASE   = 'http://localhost:8899';
const COOKIE = '/tmp/qa-v19-cookies.txt';
const AUSER  = 'admin';
const APASS  = 'AdminTest!2026';

$PASS = 0; $FAIL = 0; $NOTES = [];
function ok(bool $c, string $name, string $extra = ''): void {
    global $PASS, $FAIL, $NOTES;
    if ($c) { $PASS++; echo "  ✅ $name" . ($extra ? " — $extra" : '') . "\n"; }
    else    { $FAIL++; echo "  ❌ $name" . ($extra ? " — $extra" : '') . "\n"; }
    if (!$c) $NOTES[] = $name . ($extra ? " ($extra)" : '');
}

/* ریدایرکت دستی: POST بدون FOLLOWLOCATION (CUSTOMREQUEST روی 30x تکرار می‌شد!) + GET جدا */
function http(string $method, string $path, array $data = []): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => COOKIE,
        CURLOPT_COOKIEJAR      => COOKIE,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $raw  = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hlen); $body = substr($raw, $hlen);
    /* دنبال‌کردن دستی ریدایرکت با GET (حداکثر ۳ پرش) */
    for ($i = 0; $i < 3 && in_array($code, [301, 302, 303, 307, 308], true); $i++) {
        if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $head, $m)) break;
        $loc = trim($m[1]);
        if (!preg_match('#^https?://#', $loc)) $loc = BASE . $loc;
        $ch = curl_init($loc);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => COOKIE,
            CURLOPT_COOKIEJAR      => COOKIE,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw  = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hlen); $body = substr($raw, $hlen);
    }
    return [$code, $body];
}
function csrf(): string {
    [$c, $b] = http('GET', '/admin/tests.php');
    return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $b, $m) ? $m[1] : '';
}

$sql = fn(string $q) => trim((string)shell_exec(
    "mysql --default-character-set=utf8mb4 -u feria -pferia_test_pass_2026 -N -e " .
    escapeshellarg($q) . " feria_test 2>&1"));

@unlink(COOKIE);
$sql("DELETE FROM fr_rate_limits WHERE bucket LIKE 'admin_login%'");

/* ═══ پاک‌سازی بقایای اجراهای قبلی QA (ترتیب وابستگی رعایت شده) ═══ */
echo "── پاک‌سازی داده‌های QA قبلی…\n";
$qms = "'09120001111','09120002222','09120003333','09120004444','09120005555','09120006666','09120007777'";
$uids = $sql("SELECT GROUP_CONCAT(id) FROM fr_users WHERE mobile IN ($qms)");
if ($uids) {
    $sql("DELETE rr FROM fr_refund_requests rr WHERE rr.user_id IN ($uids)");
    $sql("DELETE rp FROM fr_reports  rp JOIN fr_attempts a ON a.id=rp.attempt_id WHERE a.user_id IN ($uids)");
    $sql("DELETE rs FROM fr_results  rs JOIN fr_attempts a ON a.id=rs.attempt_id WHERE a.user_id IN ($uids)");
    $sql("DELETE an FROM fr_answers  an JOIN fr_attempts a ON a.id=an.attempt_id WHERE a.user_id IN ($uids)");
    $sql("DELETE a  FROM fr_attempts a  WHERE a.user_id IN ($uids)");
    $sql("DELETE c  FROM fr_event_companions c JOIN fr_event_registrations r ON r.id=c.reg_id WHERE r.user_id IN ($uids)");
    $sql("DELETE r  FROM fr_event_registrations r WHERE r.user_id IN ($uids)");
    $sql("DELETE o  FROM fr_orders o WHERE o.user_id IN ($uids)");
    $sql("DELETE FROM fr_users WHERE id IN ($uids)");
}
$sql("DELETE p FROM fr_payments p WHERE p.authority LIKE 'MANUAL-%'");
$sql("DELETE o FROM fr_orders o WHERE o.user_id NOT IN (SELECT id FROM fr_users)");
foreach ((array)explode(',', (string)$sql("SELECT GROUP_CONCAT(id) FROM fr_tests WHERE slug='qa-bulk-test'")) as $tid) {
    $tid = (int)$tid; if (!$tid) continue;
    $sql("DELETE FROM fr_analysis_templates WHERE test_id=$tid");
    $sql("DELETE FROM fr_scoring_keys WHERE test_id=$tid");
    $sql("DELETE FROM fr_questions WHERE test_id=$tid");
    $sql("DELETE FROM fr_test_scales WHERE test_id=$tid");
    $sql("DELETE FROM fr_tests WHERE id=$tid");
}
$sql("DELETE FROM fr_sms_logs WHERE mobile IN ($qms,'09129340001')");
echo "── پاک‌سازی تمام شد ✓\n";

/* ═══ ۰) ورود مدیر ═══ */
echo "\n═══ ۰) ورود مدیر ═══\n";
[$c, $b] = http('GET', '/admin/login.php');
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $b, $m);
[$c, $b] = http('POST', '/admin/login.php', ['_csrf' => $m[1] ?? '', 'username' => AUSER, 'password' => APASS]);
[$c2, $b2] = http('GET', '/admin/dashboard.php');
ok($c2 === 200 && str_contains($b2, 'خالص درآمد') && !str_contains($b2, 'ورود به پیشخوان'), 'ورود مدیر موفق (داشبورد قابل دسترس)');
$t = csrf();
ok($t !== '', 'توکن CSRF نشست دریافت شد');

/* ═══ ۱) راستی‌آزمایی خط لولهٔ به‌روزرسانی خودکار ═══ */
echo "\n═══ ۱) خط لولهٔ به‌روزرسانی خودکار ═══\n";
$sql("UPDATE fr_settings SET `value`='1.8.0' WHERE `key`='fr_version'");
ok($sql("SELECT `value` FROM fr_settings WHERE `key`='fr_version'") === '1.8.0', 'نسخهٔ DB به 1.8.0 برگردانده شد (شبیه‌سازی ارتقا)');
[$c, $b] = http('GET', '/admin/dashboard.php');   // اولین درخواست مدیر ← خط‌لوله باید اجرا شود
$v = $sql("SELECT `value` FROM fr_settings WHERE `key`='fr_version'");
preg_match('/define\(\'FR_RELEASE\',\s*\'([^\']+)\'/', (string)@file_get_contents('/tmp/feria-smoke/includes/version.php'), $mr);
$relX = $mr[1] ?? '1.9.0';
ok(version_compare($v, '1.9.0', '>=') && $v === $relX, 'نسخهٔ DB خودکار به FR_RELEASE رسید', $v);
ok(str_contains($sql("SHOW COLUMNS FROM fr_tests LIKE 'status'"), 'trash'), 'ENUM وضعیت آزمون‌ها trash دارد');
ok(str_contains($b, 'به‌روزرسانی خودکار اعمال شد'), 'بنر اطلاع‌رسانی به‌روزرسانی در پیشخوان دیده شد');
$nGardner = (int)$sql("SELECT COUNT(*) FROM fr_tests WHERE slug='gardner-mi'");
$nEq      = (int)$sql("SELECT COUNT(*) FROM fr_tests WHERE slug='eq-shutte'");
ok($nGardner === 1 && $nEq === 1, 'آزمون‌های جدید (گاردنر/هوش هیجانی) تک‌نسخه و موجود', "gardner=$nGardner eq=$nEq");
[$c, $b] = http('GET', '/admin/dashboard.php');   // درخواست دوم ← بدون اجرای مجدد
ok(!str_contains($b, 'به‌روزرسانی خودکار اعمال شد'), 'اجرای مجدد خط‌لوله رخ نداد (idempotent)');

/* ═══ ۲) عملیات گروهی آزمون‌ها + زباله‌دان ═══ */
echo "\n═══ ۲) عملیات گروهی + زباله‌دان آزمون‌ها ═══\n";
$sql("INSERT INTO fr_tests (title, slug, engine, audience, price_full, free_enabled, est_minutes, status, sort_order)
      VALUES ('آزمون آزمایشی کیوای','qa-bulk-test','mslikert','general',1000,1,5,'active',999)");
$qtid = (int)$sql("SELECT id FROM fr_tests WHERE slug='qa-bulk-test'");
ok($qtid > 0, 'آزمون آزمایشی ساخته شد', "id=$qtid");
$pickId = (int)$sql("SELECT id FROM fr_tests WHERE status='active' ORDER BY id LIMIT 1");
ok($pickId > 0, 'آزمون فعال موجود برای غیرفعال/فعال گروهی', "id=$pickId");

[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'active', 'do' => 'trash']);
ok(str_contains($b, 'هیچ تستی انتخاب نشده'), 'POST bulk بدون انتخاب → پیام راهنما');

[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'active', 'do' => 'deactivate', 'ids[0]' => $pickId, 'ids[1]' => $qtid]);
$st = $sql("SELECT GROUP_CONCAT(DISTINCT status) FROM fr_tests WHERE id IN ($pickId,$qtid)");
ok($st === 'inactive', 'غیرفعال‌سازی گروهی اعمال شد', $st);
ok(str_contains($b, 'غیرفعال‌سازی برای'), 'پیام موفق غیرفعال‌سازی در صفحه');
[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'active', 'do' => 'activate', 'ids[0]' => $pickId, 'ids[1]' => $qtid]);
$st = $sql("SELECT GROUP_CONCAT(DISTINCT status) FROM fr_tests WHERE id IN ($pickId,$qtid)");
ok($st === 'active', 'فعال‌سازی گروهی اعمال شد', $st);

/* چک معکوس: هندلر تکی نباید روی bulk اثر بگذارد (رفع باگ ترتیب پردازشگرها) */
$before = $sql("SELECT title FROM fr_tests WHERE id=$pickId");
[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'active', 'do' => 'activate', 'ids[0]' => $pickId]);
$after = $sql("SELECT title FROM fr_tests WHERE id=$pickId");
ok($before === $after, 'POST bulk روی داده‌های تک‌آزمون اثر نگذاشت (ترتیب هندلرها درست است)');

[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'active', 'do' => 'trash', 'ids[0]' => $qtid]);
$st = $sql("SELECT status FROM fr_tests WHERE id=$qtid");
ok($st === 'trash', 'انتقال به زباله‌دان اعمال شد', $st);
[$c, $b] = http('GET', '/admin/tests.php?view=trash');
ok(str_contains($b, 'qa-bulk-test') || str_contains($b, 'آزمون آزمایشی کیوای'), 'آزمون در تب زباله‌دان دیده می‌شود');
[$c, $b] = http('GET', '/admin/tests.php');
ok(!str_contains($b, 'qa-bulk-test'), 'آزمون زباله‌دانی در تب اصلی نیست');
[$c, $b] = http('GET', '/tests.php');
ok(!str_contains($b, 'آزمون آزمایشی کیوای'), 'آزمون زباله‌دانی در ویترین عمومی نیست');

[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'trash', 'do' => 'restore', 'ids[0]' => $qtid]);
$st = $sql("SELECT status FROM fr_tests WHERE id=$qtid");
ok($st === 'draft', 'بازگردانی از زباله‌دان → پیش‌نویس', $st);
[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'active', 'do' => 'trash', 'ids[0]' => $qtid]);

/* گارد حذف: آزمونی با تلاش واقعی */
$guardId = (int)$sql("SELECT test_id FROM fr_attempts LIMIT 1");
if (!$guardId) {
    $uidX = (int)$sql("SELECT id FROM fr_users ORDER BY id LIMIT 1");
    $sql("INSERT INTO fr_attempts (user_id, test_id, mode, status) VALUES ($uidX, $pickId, 'free', 'completed')");
    $guardId = $pickId;
}
[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'active', 'do' => 'trash', 'ids[0]' => $guardId]);
[$c, $b] = http('POST', '/admin/tests.php', ['_csrf' => $t, 'act' => 'bulk', 'view' => 'trash', 'do' => 'delete', 'ids[0]' => $guardId, 'ids[1]' => $qtid]);
$still = (int)$sql("SELECT COUNT(*) FROM fr_tests WHERE id=$guardId");
ok($still === 1 && str_contains($b, 'ممنوع'), 'گارد حذف نهایی: آزمونِ دارای سابقه مسدود ماند', "guardId=$guardId");
$gone = (int)$sql("SELECT COUNT(*) FROM fr_tests WHERE id=$qtid");
ok($gone === 0, 'حذف نهایی آزمون بدون سابقه موفق بود');

/* ═══ ۳) مدیریت ثبت‌نام‌شدگان — رویداد ═══ */
echo "\n═══ ۳) مدیریت ثبت‌نام‌شدگان (رویداد) ═══\n";
$evId = (int)$sql("SELECT id FROM fr_events WHERE kind='workshop' AND status='active' AND price>0 LIMIT 1");
ok($evId > 0, 'کارگاه فعال پولی برای تست', "id=$evId");
[$c, $b] = http('GET', "/admin/registrants.php?ctx=workshop&id=$evId");
ok($c === 200 && str_contains($b, 'افزودن دستی عضو'), 'صفحهٔ ثبت‌نام‌شدگان رویداد رندر شد');
ok(str_contains($b, '▸') || str_contains($b, 'ثبت‌نام‌شدگان'), 'منوی جدید موجود است');

$mob1 = '09120001111';
$revBefore = (int)$sql("SELECT COALESCE(SUM(payable),0) FROM fr_orders WHERE status='paid'");
[$c, $b] = http('POST', '/admin/registrants.php', [
    '_csrf' => $t, 'act' => 'reg_add', 'event_id' => $evId,
    'mobile' => $mob1, 'first_name' => 'کیوای', 'last_name' => 'پرداختی',
    'pay_type' => 'paid', 'companions_text' => "همراه یک 09120002222\nهمراه دو 09120003333",
]);
$regId = (int)$sql("SELECT id FROM fr_event_registrations WHERE event_id=$evId AND user_id=(SELECT id FROM fr_users WHERE mobile='$mob1') ORDER BY id DESC LIMIT 1");
$revAfter = (int)$sql("SELECT COALESCE(SUM(payable),0) FROM fr_orders WHERE status='paid'");
$compsN  = (int)$sql("SELECT COUNT(*) FROM fr_event_companions WHERE reg_id=$regId");
ok($regId > 0, 'افزودن دستی عضو با رکورد پرداخت انجام شد', "reg=$regId");
ok($revAfter > $revBefore, 'رکورد پرداخت دستی در جمع سفارش‌های paid لحاظ شد', (string)($revAfter - $revBefore) . ' تومان افزایش');
ok($compsN === 2, 'دو همراه با موبایل ثبت و حسابشان ساخته شد', "companions=$compsN");
$ordSt = $sql("SELECT o.status FROM fr_orders o JOIN fr_event_registrations r ON r.order_id=o.id WHERE r.id=$regId");
ok($ordSt === 'paid', 'سفارش دستی با وضعیت paid ثبت شد', $ordSt);
$payGw = $sql("SELECT CONCAT(gateway,'|',authority) FROM fr_payments p JOIN fr_event_registrations r ON r.order_id=p.order_id WHERE r.id=$regId");
ok(str_contains($payGw, 'manual') && str_contains($payGw, 'MANUAL-'), 'ردیف پرداخت شاهد با gateway=manual ساخته شد', $payGw);

/* گارد تکراری */
[$c, $b] = http('POST', '/admin/registrants.php', [
    '_csrf' => $t, 'act' => 'reg_add', 'event_id' => $evId,
    'mobile' => $mob1, 'first_name' => 'ک', 'last_name' => 'پ', 'pay_type' => 'free',
]);
ok(str_contains($b, 'قبلاً در این رویداد ثبت‌نام شده'), 'گارد ثبت‌نام تکراری کار کرد');

/* افزودن دستی رایگان (بدون اثر مالی) */
$mob2 = '09120004444';
$revBefore = (int)$sql("SELECT COALESCE(SUM(payable),0) FROM fr_orders WHERE status='paid'");
[$c, $b] = http('POST', '/admin/registrants.php', [
    '_csrf' => $t, 'act' => 'reg_add', 'event_id' => $evId,
    'mobile' => $mob2, 'first_name' => 'کیوای', 'last_name' => 'رایگان', 'pay_type' => 'free',
]);
$regFree = (int)$sql("SELECT id FROM fr_event_registrations WHERE event_id=$evId AND user_id=(SELECT id FROM fr_users WHERE mobile='$mob2') ORDER BY id DESC LIMIT 1");
$revAfter = (int)$sql("SELECT COALESCE(SUM(payable),0) FROM fr_orders WHERE status='paid'");
ok($regFree > 0 && $revAfter === $revBefore, 'ثبت رایگان/دستی بدون اثر مالی ثبت شد', "reg=$regFree بدون تغییر درآمد");
ok($sql("SELECT COALESCE(order_id,'NULL') FROM fr_event_registrations WHERE id=$regFree") === 'NULL', 'ثبت رایگان سفارش ندارد');

/* ویرایش عضو: نام + موبایل + وضعیت */
[$c, $b] = http('POST', '/admin/registrants.php', [
    '_csrf' => $t, 'act' => 'reg_save', 'reg_id' => $regFree,
    'mobile' => '09120005555', 'first_name' => 'نام‌جدید', 'last_name' => 'فامیل‌جدید',
    'status' => 'canceled', 'companion_count' => 0,
]);
$chk = $sql("SELECT CONCAT(u.first_name,'|',u.last_name,'|',u.mobile,'|',r.status) FROM fr_event_registrations r JOIN fr_users u ON u.id=r.user_id WHERE r.id=$regFree");
ok($chk === 'نام‌جدید|فامیل‌جدید|09120005555|canceled', 'ویرایش عضو (نام/موبایل/وضعیت) اعمال شد', $chk);

/* حذف رایگان ← مجاز */
[$c, $b] = http('POST', '/admin/registrants.php', ['_csrf' => $t, 'act' => 'reg_del', 'reg_id' => $regFree]);
ok((int)$sql("SELECT COUNT(*) FROM fr_event_registrations WHERE id=$regFree") === 0, 'حذف عضو رایگان انجام شد');

/* حذف پرداختی ← ممنوع (سفارش paid دارد) */
[$c, $b] = http('POST', '/admin/registrants.php', ['_csrf' => $t, 'act' => 'reg_del', 'reg_id' => $regId]);
ok((int)$sql("SELECT COUNT(*) FROM fr_event_registrations WHERE id=$regId") === 1 && str_contains($b, 'سفارشِ پرداخت‌شده'), 'گارد حذف عضو دارای سفارش paid کار کرد');

/* ═══ ۴) درآمد دقیق — چرخهٔ کامل عودت ═══ */
echo "\n═══ ۴) دقت درآمد و عودت ═══\n";
$qNet   = "SELECT COALESCE(SUM(payable),0) FROM fr_orders WHERE status='paid'";
$qGross = "SELECT COALESCE(SUM(payable),0) FROM fr_orders WHERE status IN ('paid','refunded')";
$qRef   = "SELECT COALESCE(SUM(payable),0) FROM fr_orders WHERE status='refunded'";
$net0 = (int)$sql($qNet); $gross0 = (int)$sql($qGross); $ref0 = (int)$sql($qRef);
$paidAmt = (int)$sql("SELECT o.payable FROM fr_orders o JOIN fr_event_registrations r ON r.order_id=o.id WHERE r.id=$regId");
$uidPaid = (int)$sql("SELECT user_id FROM fr_event_registrations WHERE id=$regId");
$sql("INSERT INTO fr_refund_requests (user_id, context, reference_id, amount, reason, status) VALUES ($uidPaid,'event',$regId,$paidAmt,'تست QA','pending')");
$rid = (int)$sql("SELECT id FROM fr_refund_requests ORDER BY id DESC LIMIT 1");
[$c, $b] = http('POST', '/admin/refunds.php', ['_csrf' => $t, 'act' => 'approve', 'id' => $rid, 'note' => 'QA']);
[$c, $b] = http('POST', '/admin/refunds.php', ['_csrf' => $t, 'act' => 'paid_manual', 'id' => $rid, 'note' => 'QA', 'ref' => 'QA-TRACE-1']);
$ordSt = $sql("SELECT o.status FROM fr_orders o JOIN fr_event_registrations r ON r.order_id=o.id WHERE r.id=$regId");
ok($ordSt === 'refunded', 'پس از عودت، سفارش «refunded» شد', $ordSt);
$regSt = $sql("SELECT status FROM fr_event_registrations WHERE id=$regId");
ok($regSt === 'canceled', 'ثبت‌نام پس از عودت لغو شد', $regSt);
$net1 = (int)$sql($qNet); $gross1 = (int)$sql($qGross); $ref1 = (int)$sql($qRef);
ok($net1 === $net0 - $paidAmt, 'خالص درآمد دقیقاً به‌اندازهٔ عودت کم شد', "$net0 → $net1 (−$paidAmt)");
ok($gross1 === $gross0, 'ناخالص ثابت ماند (paid+refunded)', (string)$gross1);
ok($ref1 === $ref0 + $paidAmt, 'مبالغ برگشتی به‌اندازهٔ عودت بیشتر شد', "$ref0 → $ref1");
[$c, $b] = http('GET', '/admin/dashboard.php');
$fpat  = fn(int $v) => str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], number_format($v));
ok(str_contains($b, $fpat($net1)), 'داشبورد همان خالصِ SQL را نشان می‌دهد', $fpat($net1));
ok(str_contains($b, $fpat($ref1)), 'داشبورد همان برگشتیِ SQL را نشان می‌دهد', $fpat($ref1));
ok(str_contains($b, 'ناخالص') && str_contains($b, 'برگشتی'), 'کارت‌های شفافیت درآمد (ناخالص/برگشتی) در داشبورد');
ok(str_contains($b, 'قاعدهٔ محاسبه') || str_contains($b, 'گزارش امروز'), 'توضیح قاعدهٔ محاسبهٔ درآمد در داشبورد');
ok($gross1 === $net1 + $ref1, 'همیشه: ناخالص = خالص + برگشتی', "$gross1 = $net1 + $ref1");

/* ═══ ۵) مدیریت ثبت‌نام‌شدگان — آزمون ═══ */
echo "\n═══ ۵) مدیریت ثبت‌نام‌شدگان (آزمون) ═══\n";
$testX = (int)$sql("SELECT id FROM fr_tests WHERE status='active' AND price_full>0 LIMIT 1");
ok($testX > 0, 'آزمون فعال پولی برای تست', "id=$testX");
[$c, $b] = http('GET', "/admin/registrants.php?ctx=test&id=$testX");
ok(str_contains($b, 'اعطای دسترسی دستی'), 'صفحهٔ شرکت‌کنندگان آزمون رندر شد');

$um = '09120006666';
[$c, $b] = http('POST', '/admin/registrants.php', [
    '_csrf' => $t, 'act' => 'att_add', 'test_id' => $testX, 'mobile' => $um,
    'first_name' => 'کیوای', 'last_name' => 'آزمون', 'mode' => 'free',
]);
$afree = (int)$sql("SELECT a.id FROM fr_attempts a JOIN fr_users u ON u.id=a.user_id WHERE u.mobile='$um' AND a.test_id=$testX ORDER BY a.id DESC LIMIT 1");
ok($afree > 0, 'اعطای دسترسی رایگان ثبت شد', "attempt=$afree");
[$c, $b] = http('POST', '/admin/registrants.php', [
    '_csrf' => $t, 'act' => 'att_add', 'test_id' => $testX, 'mobile' => $um,
    'first_name' => 'ک', 'last_name' => 'آ', 'mode' => 'free',
]);
ok(str_contains($b, 'جلسهٔ ناتمام'), 'گارد جلسهٔ ناتمام موازی کار کرد');
[$c, $b] = http('POST', '/admin/registrants.php', ['_csrf' => $t, 'act' => 'att_del', 'attempt_id' => $afree]);
ok((int)$sql("SELECT COUNT(*) FROM fr_attempts WHERE id=$afree") === 0, 'حذف تلاش رایگان انجام شد');
$net0 = (int)$sql($qNet);
[$c, $b] = http('POST', '/admin/registrants.php', [
    '_csrf' => $t, 'act' => 'att_add', 'test_id' => $testX, 'mobile' => $um,
    'first_name' => 'کیوای', 'last_name' => 'آزمون', 'mode' => 'full', 'pay_record' => '1',
]);
$afull = (int)$sql("SELECT a.id FROM fr_attempts a JOIN fr_users u ON u.id=a.user_id WHERE u.mobile='$um' AND a.test_id=$testX AND a.mode='full' ORDER BY a.id DESC LIMIT 1");
$tprice = (int)$sql("SELECT CASE WHEN price_sale IS NOT NULL AND price_sale>0 AND price_sale<price_full THEN price_sale ELSE price_full END FROM fr_tests WHERE id=$testX"); /* ۲٫۸: رکورد پرداخت دستی با «قیمت نهایی» (تخفیف‌خورده) ثبت می‌شود */
$net1 = (int)$sql($qNet);
ok($afull > 0 && $net1 === $net0 + $tprice, 'اعطای ویژه با رکورد پرداخت → سفارش paid + لحاظ در درآمد', "attempt=$afull +$tprice");
[$c, $b] = http('POST', '/admin/registrants.php', ['_csrf' => $t, 'act' => 'att_del', 'attempt_id' => $afull]);
ok((int)$sql("SELECT COUNT(*) FROM fr_attempts WHERE id=$afull") === 1 && str_contains($b, 'سفارشِ پرداخت‌شده'), 'گارد حذف تلاشِ دارای سفارش paid کار کرد');
[$c, $b] = http('POST', '/admin/registrants.php', [
    '_csrf' => $t, 'act' => 'att_save', 'attempt_id' => $afull,
    'mobile' => '09120007777', 'first_name' => 'ویرایش‌شده', 'last_name' => 'کیوای',
    'mode' => 'full', 'status' => 'in_progress',
]);
$chk = $sql("SELECT CONCAT(u.first_name,'|',u.mobile) FROM fr_attempts a JOIN fr_users u ON u.id=a.user_id WHERE a.id=$afull");
ok($chk === 'ویرایش‌شده|09120007777', 'ویرایش تلاش (نام/موبایل) اعمال شد', $chk);

/* ═══ ۶) زنجیرهٔ پیامک اطلاع به مدیر ═══ */
echo "\n═══ ۶) پیامک اطلاع به مدیر (قالب/تنظیمات/مسیر ارسال) ═══\n";
$tpl = $sql("SELECT CONCAT(event,'|',enabled,'|',COALESCE(body,'')) FROM fr_sms_templates WHERE event='admin_new_order'");
ok(str_contains($tpl, 'admin_new_order|1') && str_contains($tpl, '%name%') && str_contains($tpl, '%amount%'), 'قالب admin_new_order فعال و کامل است');
[$c, $b] = http('GET', '/admin/settings.php?tab=sms');
ok(str_contains($b, 'admin_notify_mobiles'), 'فیلد شماره‌های دلخواه اطلاع‌رسانی مدیر در تنظیمات هست');
$sql("INSERT INTO fr_settings (`key`,`value`) VALUES ('admin_notify_mobiles','09129340001') ON DUPLICATE KEY UPDATE `value`='09129340001'");
$logsBefore = (int)$sql("SELECT COUNT(*) FROM fr_sms_logs WHERE event='admin_new_order'");
$out = trim((string)shell_exec("php -r \"
  require '/tmp/feria-smoke/config.php';
  require '/tmp/feria-smoke/includes/version.php';
  require '/tmp/feria-smoke/includes/db.php';
  require '/tmp/feria-smoke/includes/functions.php';
  require '/tmp/feria-smoke/core/Kavenegar.php';
  require '/tmp/feria-smoke/includes/notify.php';
  \\\$n = fr_notify_admins_sms('admin_new_order', ['name'=>'کیوای تست','mobile'=>'09129998888','test'=>'رویداد «تست»','amount'=>12345,'refid'=>'QA']);
  echo 'notify_sent=' . \\\$n;
\" 2>&1"));
ok(str_contains($out, 'notify_sent='), 'فراخوانی زنجیرهٔ fr_notify_admins_sms بدون استثنا اجرا شد', $out);
$targets = (string)$sql("SELECT GROUP_CONCAT(mobile) FROM fr_sms_logs WHERE event='admin_new_order' AND id>" . (int)$logsBefore);
ok($targets !== '', 'پیامک‌های اطلاع به مدیر در sms_logs ثبت شدند (در محیط تست: disabled)', "to: $targets");
ok(str_contains($targets, '09129340001'), 'شمارهٔ دلخواه تنظیمات هم هدف گرفته شد', $targets);
/* متن نهایی پیامک باید متغیرپر شده باشد */
$lastBody = (string)$sql("SELECT body FROM fr_sms_logs WHERE event='admin_new_order' ORDER BY id DESC LIMIT 1");
ok(str_contains($lastBody, 'کیوای تست') && str_contains($lastBody, '09129998888') && str_contains($lastBody, '12,345'), 'متن پیامک مدیر با متغیرها رندر شد', $lastBody);

/* ═══ جمع‌بندی ═══ */
echo "\n════════════════════════════════\n";
echo "نتیجهٔ کل: ✅ $PASS موفق / ❌ $FAIL ناموفق\n";
if ($NOTES) { echo "موارد ناموفق:\n - " . implode("\n - ", $NOTES) . "\n"; exit(1); }
echo "همهٔ تست‌ها سبز 🎉\n";
exit(0);
