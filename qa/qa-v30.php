<?php
declare(strict_types=1);
/* ════════════════════════════════════════════════════════════════════════════
 * QA نسخهٔ ۳٫۰ — پیش‌پرداخت (بیعانه) + یادآور خودکار تصفیهٔ حساب (T-۳ روز)
 * کارگاه/دوره/نوبت مشاوره: ثبت‌نام با بیعانه → قطعی شدن جا → سفارش تصفیه
 * (لینک توکن‌دار عمومی + پنل کاربری) → کرون یادآور با پیامک + لینک پرداخت
 * ════════════════════════════════════════════════════════════════════════════ */
const BASE     = 'http://localhost:8899';
const A_COOKIE = '/tmp/qa30-admin.jar';
const U_COOKIE = '/tmp/qa30-user.jar';
const N_COOKIE = '/tmp/qa30-guest.jar';
const CRON_KEY = 'qa27cronsecretXYZ';

$APP = '/tmp/feria-smoke';
require $APP.'/config.php';
require $APP.'/includes/version.php';
require FR_ROOT.'/includes/db.php';
require FR_ROOT.'/includes/functions.php';
require FR_ROOT.'/includes/security.php';
require FR_ROOT.'/includes/commerce.php';
require FR_ROOT.'/includes/consult.php';
require FR_ROOT.'/includes/event-pricing.php';
require FR_ROOT.'/includes/deposit.php';
require FR_ROOT.'/includes/wallet.php';
require FR_ROOT.'/includes/update.php';

date_default_timezone_set('Asia/Tehran');

$fail = 0; $pass = 0;
function ok(bool $c, string $label, string $extra = ''): void {
    global $fail, $pass;
    echo ($c ? '✅' : '❌') . " $label" . (!$c && $extra !== '' ? "  ⟵ $extra" : '') . "\n";
    $c ? $pass++ : $fail++;
}
function http(string $method, string $path, array|string $data = [], string $jar = A_COOKIE): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
    ]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$code, substr($raw, 0, $hlen), substr($raw, $hlen)];
}
function httpJson(string $path, array $payload, string $jar, string $csrf): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF: ' . $csrf],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $raw];
}
function getx(string $path, string $jar = A_COOKIE): array {
    [$c, $h, $b] = http('GET', $path, [], $jar);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]), [], $jar);
    }
    return [$c, $b];
}
function post_csrf(string $path, array $fields, string $csrf, string $jar): array {
    [$c, $h, $b] = http('POST', $path, $fields + ['_csrf' => $csrf], $jar);
    return [$c, $h, $b];
}
/* پاک‌سازی فییکسچرهای qa30 (شروع + پایان) — idempotent */
function qa30_clean(): void {
    // رویدادهای qa30 → ثبت‌نام‌ها کاسکید می‌شوند؛ سفارش‌ها دستی پاک می‌شوند
    $evIds = array_column(DB::all("SELECT id FROM fr_events WHERE slug LIKE 'qa30%'"), 'id');
    $oIds  = [];
    if ($evIds) {
        $in = implode(',', array_map('intval', $evIds));
        foreach (DB::all("SELECT id,parent_order_id FROM fr_orders WHERE event_id IN ($in) OR parent_order_id IN (SELECT id FROM (SELECT id FROM fr_orders WHERE event_id IN ($in)) x)",) as $o) { $oIds[] = (int)$o['id']; $oIds[] = (int)$o['parent_order_id']; }
        // شرط‌بندی ساده‌تر: هر سفارش مرتبط با این رویدادها یا فرزندشان
        foreach (DB::all("SELECT o2.id FROM fr_orders o2 LEFT JOIN fr_orders o1 ON o1.id=o2.parent_order_id WHERE o2.event_id IN ($in) OR o1.event_id IN ($in)") as $o) { $oIds[] = (int)$o['id']; }
    }
    // مشاوران qa30 → خدمات/اسلات‌ها/نوبت‌ها کاسکید
    foreach (DB::all("SELECT o.id FROM fr_orders o JOIN fr_consult_appointments a ON a.order_id=o.id JOIN fr_consult_services sv ON sv.id=a.service_id JOIN fr_consultants c ON c.id=sv.consultant_id WHERE c.name LIKE 'qa30%'") as $o) { $oIds[] = (int)$o['id']; }
    foreach (DB::all("SELECT o.id FROM fr_orders o JOIN fr_orders m ON m.id=o.parent_order_id JOIN fr_consult_appointments a ON a.order_id=m.id JOIN fr_consult_services sv ON sv.id=a.service_id JOIN fr_consultants c ON c.id=sv.consultant_id WHERE c.name LIKE 'qa30%'") as $o) { $oIds[] = (int)$o['id']; }
    DB::run("DELETE FROM fr_consultants WHERE name LIKE 'qa30%'");
    DB::run("DELETE FROM fr_events WHERE slug LIKE 'qa30%'");
    $oIds = array_values(array_unique(array_filter($oIds)));
    if ($oIds) { $in = implode(',', $oIds); DB::run("DELETE FROM fr_orders WHERE id IN ($in)"); }
    DB::run("DELETE FROM fr_wallet_transactions WHERE description LIKE '%qa30%'");
    DB::run("DELETE FROM fr_refund_requests WHERE reason LIKE 'qa30%'");
    DB::run("DELETE FROM fr_sms_logs WHERE event IN ('settle_remind','deposit_ok','settle_done') AND created_at>NOW()-INTERVAL 2 DAY");
    try { wallet_sync(1); } catch (Throwable $e) { }
}
qa30_clean();

$U1 = DB::one('SELECT * FROM fr_users WHERE id=1');
ok($U1 && $U1['mobile'] !== '', 'کاربر آزمایشی (id=1) موجود است', (string)($U1['mobile'] ?? '?'));

/* ═══ ۰) نسخه و مهاجرت ۳٫۰ ═══ */
echo "\n═══ ۰) نسخه و مهاجرت ۳٫۰ ═══\n";
$ver = defined('FR_RELEASE') ? FR_RELEASE : '?';
ok($ver === '3.0.0', "نسخهٔ کد ۳٫۰٫۰ (FR_RELEASE=$ver)");
ok((string)DB::setting('fr_version','') === '3.0.0', 'پایپ‌لاین نسخهٔ دیتابیس را ۳٫۰٫۰ ثبت کرده');
$col = fn(string $t, string $c) => (bool)DB::val("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [$t, $c]);
ok($col('fr_events','deposit_enabled') && $col('fr_events','deposit_amount'), 'ستون‌های پیش‌پرداخت رویداد ساخته شدند');
ok($col('fr_consult_services','deposit_enabled') && $col('fr_consult_services','deposit_amount'), 'ستون‌های پیش‌پرداخت خدمت مشاوره ساخته شدند');
ok($col('fr_event_registrations','paid_total') && $col('fr_event_registrations','settle_reminded_at'), 'ستونهای paid_total/settle_reminded_at ثبت‌نام ساخته شدند');
ok($col('fr_consult_appointments','paid_total') && $col('fr_consult_appointments','settle_reminded_at'), 'ستونهای paid_total/settle_reminded_at نوبت ساخته شدند');
ok($col('fr_orders','pay_stage') && $col('fr_orders','parent_order_id'), 'ستونهای pay_stage/parent_order_id سفارش ساخته شدند');
$stType = (string)(DB::one("SHOW COLUMNS FROM fr_event_registrations LIKE 'status'")['Type'] ?? '');
ok(str_contains($stType, 'deposit'), 'enum وضعیت ثبت‌نام شامل deposit است', $stType);
foreach (['deposit_remind_hours'=>'72','settle_remind_on'=>'1'] as $k=>$v) {
    ok((string)DB::setting($k,'') === $v, "تنظیم $k=$v بذر شد");
}
ok(str_contains((string)DB::setting('sms_tpl_deposit_user',''), '{link}'), 'قالب پیامک پیش‌پرداخت دارای لینک تصفیه است');
ok(str_contains((string)DB::setting('sms_tpl_settle_remind',''), '{link}') && str_contains((string)DB::setting('sms_tpl_settle_remind',''), 'پنل کاربری'),
   'قالب یادآور تصفیه: لینک پرداخت + اشاره به پنل کاربری');
ok(str_contains((string)DB::setting('sms_tpl_settle_done_user',''), '{refid}'), 'قالب تبریک تصفیه دارای کد رهگیری است');
ok((int)DB::val("SELECT COUNT(*) FROM fr_analysis_templates") > 900, 'داده‌های نسخهٔ ۲٫۹ سالم مانده‌اند (سازگاری بازگشتی)');
// idempotency پایپ‌لاین
fr_update_pipeline();
ok((string)DB::setting('fr_version','') === '3.0.0', 'اجرای دوبارهٔ پایپ‌لاین بی‌اثر است (idempotent)');

/* ═══ ۱) موتور پیش‌پرداخت (توابع کمکی) ═══ */
echo "\n═══ ۱) موتور پیش‌پرداخت (توابع) ═══\n";
ok(!fr_deposit_available(['deposit_enabled'=>0,'deposit_amount'=>100,'price'=>1000]), 'پیش‌پرداختِ غیرفعال، در دسترس نیست');
ok(!fr_deposit_available(['deposit_enabled'=>1,'deposit_amount'=>1000,'price'=>1000]), 'بیعانهٔ برابرِ قیمت نامعتبر است');
ok(!fr_deposit_available(['deposit_enabled'=>1,'deposit_amount'=>1500,'price'=>1000]), 'بیعانهٔ بیشتر از قیمت نامعتبر است');
ok(fr_deposit_available(['deposit_enabled'=>1,'deposit_amount'=>300000,'price'=>1000000]), 'بیعانهٔ معتبر در دسترس است');
ok(fr_deposit_available(['deposit_enabled'=>1,'deposit_amount'=>300000,'price'=>1000000,'sale_price'=>800000])
   && fr_deposit_charge(['deposit_enabled'=>1,'deposit_amount'=>300000,'price'=>1000000,'sale_price'=>800000], 800000) === 300000,
   'بیعانه با قیمت تخفیف‌خورده (۲٫۸) سازگار است');
ok(fr_deposit_charge(['deposit_enabled'=>1,'deposit_amount'=>900000,'price'=>1000000], 700000) === 0,
   'بیعانهٔ بزرگ‌تر از ماندهٔ فاکتور، صفر می‌شود (fallback به پرداخت کامل)');
$tok = fr_settle_token('reg', 77, 1);
ok(fr_settle_token_ok('reg', 77, 1, $tok) && !fr_settle_token_ok('reg', 78, 1, $tok) && !fr_settle_token_ok('reg', 77, 2, $tok) && !fr_settle_token_ok('appt', 77, 1, $tok),
   'توکن تصفیه HMAC: پذیرش درست + رد دست‌کاری (id/user/نوع دیگر)');
$rend = fr_deposit_sms_render('{name}|{item}|{remain}|{link}|{panel}|{days}', ['name'=>'علی','item'=>'کارگاه','remain'=>'۱۰۰','link'=>'http://x','panel'=>'کارگاه‌های من','days'=>'۳']);
ok($rend === 'علی|کارگاه|۱۰۰|http://x|کارگاه‌های من|۳', 'رندر قالب پیامک با متغیرهای {...} سالم است', $rend);

/* ═══ ۲) E2E: ثبت‌نام رویداد با پیش‌پرداخت (کیف پول — بدون نیاز به درگاه) ═══ */
echo "\n═══ ۲) E2E: بیعانهٔ کارگاه/دوره (منطق مرکزی) ═══\n";
wallet_credit(1, 10_000_000, 'gift', 'qa30 شارژ آزمایشی');
$wsId = DB::insert('events', [
    'kind'=>'workshop','title'=>'qa30-کارگاه بیعانه','slug'=>'qa30-ws-dep','price'=>1_000_000,
    'capacity'=>10,'starts_at'=>date('Y-m-d H:i:s', time()+10*86400),'status'=>'active','reg_open'=>1,
    'deposit_enabled'=>1,'deposit_amount'=>300_000,
]);
$ev = DB::one('SELECT * FROM fr_events WHERE id=?', [$wsId]);
$r = create_event_order($U1, $ev, [], null, true, 'deposit');
ok(empty($r['error']) && !empty($r['wallet']) && (int)$r['deposit'] === 300000,
   'ثبت‌نام با بیعانهٔ ۳۰۰هزار از کیف پول انجام شد', json_encode(array_diff_key($r, ['quote'=>1]), JSON_UNESCAPED_UNICODE));
$reg = DB::one('SELECT * FROM fr_event_registrations WHERE event_id=? AND user_id=1 ORDER BY id DESC', [$wsId]);
ok($reg && $reg['status'] === 'deposit', 'وضعیت ثبت‌نام deposit شد (جای قطعی با بیعانه)');
ok((int)$reg['paid_total'] === 300000, 'paid_total = ۳۰۰٬۰۰۰ (بیعانه) ثبت شد', (string)$reg['paid_total']);
$depOrder = DB::one('SELECT * FROM fr_orders WHERE id=?', [(int)$reg['order_id']]);
ok($depOrder && $depOrder['pay_stage'] === 'deposit' && (int)$depOrder['payable'] === 1000000 && $depOrder['status'] === 'paid',
   'سفارش مادر: pay_stage=deposit + payable کامل + paid');
$payRow = DB::one("SELECT * FROM fr_payments WHERE order_id=? AND gateway='wallet'", [(int)$depOrder['id']]);
ok($payRow && (int)$payRow['amount'] === 300000 && $payRow['status'] === 'ok', 'رکورد پرداخت کیفِ بیعانه (۳۰۰هزار, ok)');
ok(fr_reg_remaining($reg) === 700000, 'ماندهٔ حساب = ۷۰۰٬۰۰۰', (string)fr_reg_remaining($reg));
ok(ev_paid_seats($wsId) === 1, 'صندلیِ ثبت‌نام بیعانه در ظرفیت اشغال حساب شد');
$r2 = create_event_order($U1, $ev, [], null, true, 'deposit');
ok(!empty($r2['error']) && str_contains((string)$r2['error'], 'تصفیه'), 'ثبت‌نام دوباره روی رکورد بیعانه مسدود و به تصفیه هدایت شد');
// تصفیه با کیف پول
$rs = fr_settle_create_order_reg(DB::one('SELECT * FROM fr_event_registrations WHERE id=?', [(int)$reg['id']]), $U1, true);
ok(empty($rs['error']) && !empty($rs['wallet']) && (int)$rs['remaining'] === 700000 || empty($rs['error']) && !empty($rs['wallet']),
   'سفارش تصفیهٔ حساب با کیف پول ساخته و پرداخت شد', json_encode($rs, JSON_UNESCAPED_UNICODE));
$stOrder = DB::one('SELECT * FROM fr_orders WHERE id=?', [(int)$rs['order_id']]);
ok($stOrder && $stOrder['pay_stage'] === 'settle' && (int)$stOrder['parent_order_id'] === (int)$depOrder['id'] && $stOrder['status'] === 'paid',
   'سفارش تصفیه: pay_stage=settle + parent به سفارش مادر + paid');
$reg2 = DB::one('SELECT * FROM fr_event_registrations WHERE id=?', [(int)$reg['id']]);
ok($reg2 && $reg2['status'] === 'paid' && (int)$reg2['paid_total'] === 1000000, 'پس از تصفیه: status=paid و paid_total=کل مبلغ');
ok(fr_reg_remaining($reg2) === 0, 'ماندهٔ پس از تصفیه صفر است');

/* ═══ ۳) E2E: نوبت مشاوره با پیش‌پرداخت ═══ */
echo "\n═══ ۳) E2E: بیعانهٔ نوبت مشاوره ═══\n";
$cnsId = DB::insert('consultants', ['name'=>'qa30 مشاور آزمون','title'=>'روانشناس','active'=>1]);
$svcId = DB::insert('consult_services', [
    'consultant_id'=>$cnsId,'slug'=>'qa30-svc-dep','title'=>'qa30 جلسهٔ آزمون بیعانه','price'=>800_000,
    'duration_min'=>45,'mode'=>'inperson','active'=>1,'deposit_enabled'=>1,'deposit_amount'=>200_000,
]);
$slotId = DB::run("INSERT INTO fr_consult_slots (service_id, starts_at, ends_at) VALUES (?, ?, ?)",
                  [$svcId, date('Y-m-d H:i:s', time()+9*86400), date('Y-m-d H:i:s', time()+9*86400+2700)])->rowCount();
$slotId = (int)DB::val("SELECT id FROM fr_consult_slots WHERE service_id=? ORDER BY id DESC LIMIT 1", [$svcId]);
$svc = consult_service($svcId, false);
ok($svc && fr_deposit_available($svc), 'خدمت آزمایشی با بیعانهٔ ۲۰۰هزار ساخته شد');
$rb = consult_book($U1, $svc, $slotId, 'inperson', 'qa30', true, null, 'deposit');
ok(!empty($rb['ok']) && (int)($rb['deposit'] ?? 0) === 200000 && !empty($rb['wallet']), 'رزرو با بیعانهٔ ۲۰۰هزار از کیف پول موفق بود', json_encode($rb, JSON_UNESCAPED_UNICODE));
$appt = DB::one('SELECT * FROM fr_consult_appointments WHERE id=?', [(int)$rb['appointment_id']]);
ok($appt && $appt['status'] === 'booked', 'نوبت با بیعانه قطعی (booked) شد');
ok((int)$appt['paid_total'] === 200000, 'paid_total نوبت = ۲۰۰٬۰۰۰', (string)$appt['paid_total']);
ok((string)DB::val("SELECT status FROM fr_consult_slots WHERE id=?", [$slotId]) === 'booked', 'اسلات booked شد');
$apOrder = DB::one('SELECT * FROM fr_orders WHERE id=?', [(int)$appt['order_id']]);
ok($apOrder && $apOrder['pay_stage'] === 'deposit' && (int)$apOrder['payable'] === 800000, 'سفارش نوبت: pay_stage=deposit + payable کامل');
ok(fr_appt_remaining($appt) === 600000, 'ماندهٔ نوبت = ۶۰۰٬۰۰۰', (string)fr_appt_remaining($appt));
$rs2 = fr_settle_create_order_appt(DB::one('SELECT * FROM fr_consult_appointments WHERE id=?', [(int)$appt['id']]), $U1, true);
ok(empty($rs2['error']) && !empty($rs2['wallet']), 'تصفیهٔ نوبت با کیف پول انجام شد', json_encode($rs2, JSON_UNESCAPED_UNICODE));
$appt2 = DB::one('SELECT * FROM fr_consult_appointments WHERE id=?', [(int)$appt['id']]);
ok((int)$appt2['paid_total'] === 800000 && fr_appt_remaining($appt2) === 0, 'پس از تصفیه: paid_total نوبت کامل شد');
$stOrder2 = DB::one('SELECT * FROM fr_orders WHERE id=?', [(int)$rs2['order_id']]);
ok($stOrder2 && $stOrder2['pay_stage'] === 'settle' && (int)$stOrder2['parent_order_id'] === (int)$apOrder['id'], 'سفارش تصفیهٔ نوبت به مادر پیوند خورد');

/* ═══ ۴) صفحهٔ عمومی تصفیهٔ حساب (settle.php) ═══ */
echo "\n═══ ۴) settle.php — لینک اختصاصی پیامکی ═══\n";
// رویداد تازه با بیعانهٔ تسویه‌نشده برای این بخش
$ws2 = DB::insert('events', [
    'kind'=>'course','title'=>'qa30-دورهٔ بیعانه (تسویه‌نشده)','slug'=>'qa30-course-dep','price'=>900_000,
    'starts_at'=>date('Y-m-d H:i:s', time()+12*86400),'status'=>'active','reg_open'=>1,
    'deposit_enabled'=>1,'deposit_amount'=>250_000,
]);
$ev2 = DB::one('SELECT * FROM fr_events WHERE id=?', [$ws2]);
$r3 = create_event_order($U1, $ev2, [], null, true, 'deposit');
ok(empty($r3['error']) && (int)$r3['deposit'] === 250000, 'بیعانهٔ دورهٔ دوم (۲۵۰هزار) ثبت شد');
$reg3 = DB::one('SELECT * FROM fr_event_registrations WHERE event_id=? AND user_id=1', [$ws2]);
$link = fr_settle_url_reg($reg3);
ok(str_contains($link, '/settle.php?t=reg&id=' . (int)$reg3['id'] . '&tok='), 'لینک تصفیهٔ اختصاصی ساخته شد: ' . $link);
// دسترسی مهمان با توکن معتبر — مجاز (بدون ورود)
[$gc, $gbody] = getx(str_replace(rtrim(SITE_URL,'/'), '', $link), N_COOKIE);
ok($gc === 200 && str_contains($gbody, 'ماندهٔ قابل تصفیه') && str_contains($gbody, 'qa30-دورهٔ بیعانه') && str_contains($gbody, toman(650000)),
   'صفحهٔ تصفیه برای مهمانِ دارای لینک معتبر باز شد (فاکتور مانده ۶۵۰هزار)', "http=$gc");
ok(str_contains($gbody, 'پنل کاربری'), 'متن صفحه به پنل کاربری هم اشاره می‌کند');
// توکن نامعتبر → رد
[$bc2, $bbody] = getx('/settle.php?t=reg&id=' . (int)$reg3['id'] . '&tok=deadbeefdeadbee', N_COOKIE);
ok($bc2 === 200 && str_contains($bbody, 'لینک نامعتبر است'), 'توکن دست‌کاری‌شده رد شد (لینک نامعتبر)');
// POST تصفیه از حساب خود کاربر (لاگین) با کیف پول → موفقیت بدون درگاه
[, $ub0] = getx('/shot-login.php?as=user&to=' . urlencode(str_replace(rtrim(SITE_URL,'/'), '', $link)), U_COOKIE);
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $ub0, $um);
ok(!empty($um[1]), 'توکن CSRF صفحهٔ تصفیه (سشن کاربر) گرفته شد');
[$sc, $sh, $sb] = post_csrf(str_replace(rtrim(SITE_URL,'/'), '', $link),
    ['t'=>'reg','id'=>(int)$reg3['id'],'tok'=>fr_settle_token('reg',(int)$reg3['id'],1),'use_wallet'=>'1'], $um[1] ?? '', U_COOKIE);
$reg3b = DB::one('SELECT * FROM fr_event_registrations WHERE id=?', [(int)$reg3['id']]);
ok($sc === 200 && (str_contains($sb, 'تصفیهٔ حساب انجام شد') || str_contains($sb, 'تسویه')) , 'POST تصفیه با کیف پول از لینک پیامکی موفق بود', "http=$sc");
ok($reg3b && $reg3b['status'] === 'paid' && (int)$reg3b['paid_total'] === 900000, 'پس از تصفیهٔ لینکی: status=paid با paid_total کامل');
ok(str_contains((string)fr_settle_url_reg($reg3b), '&tok='), 'لینک پس از تصفیه هم یکتا باقی می‌ماند');
[$sc2, $sb2] = getx(str_replace(rtrim(SITE_URL,'/'), '', fr_settle_url_reg($reg3b)), U_COOKIE);
ok($sc2 === 200 && str_contains($sb2, 'به‌طور کامل تسویه شده'), 'بازدید مجدد از لینک: وضعیت «تسویه کامل» نمایش داده می‌شود');

/* ═══ ۵) پنل کاربری ═══ */
echo "\n═══ ۵) پنل کاربری — نشان بیعانه + دکمهٔ تصفیه ═══\n";
// کارگاه با بیعانهٔ تسویه‌نشده برای نمایش در پنل
$ws3 = DB::insert('events', [
    'kind'=>'workshop','title'=>'qa30-کارگاه نمایش پنل','slug'=>'qa30-ws-panel','price'=>1_200_000,
    'capacity'=>20,'starts_at'=>date('Y-m-d H:i:s', time()+14*86400),'status'=>'active','reg_open'=>1,
    'deposit_enabled'=>1,'deposit_amount'=>400_000,
]);
$ev3 = DB::one('SELECT * FROM fr_events WHERE id=?', [$ws3]);
$r4 = create_event_order($U1, $ev3, [], null, true, 'deposit');
$reg4 = DB::one('SELECT * FROM fr_event_registrations WHERE event_id=? AND user_id=1', [$ws3]);
ok(empty($r4['error']) && $reg4 && $reg4['status'] === 'deposit', 'بیعانهٔ چهارم برای تست نمای پنل آماده شد');
[, $ub] = getx('/shot-login.php?as=user&to=%2Fpanel%2Fworkshops.php', U_COOKIE);
[$pwC, $pw] = getx('/panel/workshops.php', U_COOKIE);
ok($pwC === 200 && str_contains($pw, 'پیش‌پرداخت شده') && str_contains($pw, 'تصفیهٔ حساب'), 'پنل کارگاه‌ها: نشان پیش‌پرداخت + دکمهٔ تصفیه', "http=$pwC");
ok(str_contains($pw, 'settle.php?t=reg&amp;id=' . (int)$reg4['id']) || str_contains($pw, 'settle.php?t=reg&id=' . (int)$reg4['id']),
   'لینک اختصاصی تصفیه در پنل کارگاه‌ها نقش بسته است');
[, $pb] = getx('/panel/payments.php', U_COOKIE);
ok(str_contains($pb, 'پیش‌پرداخت'), 'صفحهٔ تراکنش‌ها: برچسب «پیش‌پرداخت» روی سفارش بیعانه');
// صفحهٔ ثبت‌نام رویداد بیعانه‌دار: انتخاب‌گر نحوهٔ پرداخت
[, $erb] = getx('/panel/event-register.php?id=' . $ws3, U_COOKIE);
ok(str_contains($erb, 'name="pay_mode"') && str_contains($erb, 'value="deposit"') && str_contains($erb, 'بیعانهٔ'),
   'فرم ثبت‌نام: انتخاب‌گر پرداخت کامل/پیش‌پرداخت رندر شد');
// نوبت‌نامهٔ تسویه‌نشده برای پنل مشاوره
$slot2 = (int)DB::run("INSERT INTO fr_consult_slots (service_id, starts_at, ends_at) VALUES (?, ?, ?)",
                      [$svcId, date('Y-m-d H:i:s', time()+2*86400), date('Y-m-d H:i:s', time()+2*86400+2700)])->rowCount();
$slot2 = (int)DB::val("SELECT id FROM fr_consult_slots WHERE service_id=? AND status='open' ORDER BY id DESC LIMIT 1", [$svcId]);
$rb2 = consult_book($U1, $svc, $slot2, 'inperson', 'qa30-p', true, null, 'deposit');
$apptP = DB::one('SELECT * FROM fr_consult_appointments WHERE id=?', [(int)$rb2['appointment_id']]);
ok($apptP && $apptP['status'] === 'booked' && (int)$apptP['paid_total'] === 200000, 'نوبت دوم با بیعانه (برای نمای پنل) رزرو شد');
[, $pcb] = getx('/panel/consult.php', U_COOKIE);
ok(str_contains($pcb, 'پیش‌پرداخت شده') && str_contains($pcb, 'تصفیهٔ حساب'), 'پنل مشاوره: نشان پیش‌پرداخت + دکمهٔ تصفیه');
ok(str_contains($pcb, 'settle.php?t=appt&amp;id=' . (int)$apptP['id']) || str_contains($pcb, 'settle.php?t=appt&id=' . (int)$apptP['id']),
   'لینک اختصاصی تصفیهٔ نوبت در پنل مشاوره نقش بسته است');
// صفحهٔ عمومی رزرو مشاوره: چک‌باکس بیعانه (ورود لازم)
[, $pubc] = getx('/shot-login.php?as=user&to=' . urlencode('/consult.php?s=qa30-svc-dep'), U_COOKIE);
[$pubcc, $pubcb] = getx('/consult.php?s=qa30-svc-dep', U_COOKIE);
ok($pubcc === 200 && str_contains($pubcb, 'id="bkDep"') && str_contains($pubcb, 'پیش‌پرداخت'), 'صفحهٔ رزرو عمومی: چک‌باکس بیعانه رندر شد', "http=$pubcc");

/* ═══ ۶) پنل مدیریت ═══ */
echo "\n═══ ۶) پنل مدیریت — فرم بیعانه + لیست ثبت‌نام‌شدگان ═══\n";
[, $ab] = getx('/shot-login.php?as=admin&to=%2Fadmin%2F', A_COOKIE);
[$awC, $aw] = getx('/admin/workshops.php?edit_e=' . $ws3, A_COOKIE);
ok($awC === 200 && str_contains($aw, 'name="deposit_enabled"') && str_contains($aw, 'name="deposit_amount"'), 'فرم مدیر کارگاه: فیلدهای بیعانه حضور دارند', "http=$awC");
[$acC, $acf] = getx('/admin/consult.php?edit_s=' . $svcId, A_COOKIE);
ok($acC === 200 && str_contains($acf, 'name="deposit_enabled"') && str_contains($acf, 'id="svcDepAmount"'), 'فرم مدیر خدمت مشاوره: فیلدهای بیعانه حضور دارند', "http=$acC");
ok(str_contains($acf, 'بیعانه'), 'لیست/فرم خدمت: برچسب بیعانه نمایش داده می‌شود');
// ذخیرهٔ بیعانهٔ نامعتبر (≥ قیمت) از فرم مدیر → نادیده گرفته می‌شود
$wsTmp = DB::insert('events', ['kind'=>'workshop','title'=>'qa30-ws-invalid','slug'=>'qa30-ws-invalid','price'=>500000,'status'=>'active','reg_open'=>1]);
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $aw, $acm);
[, , $svB] = post_csrf('/admin/workshops.php', [
    'act'=>'save','id'=>(int)$wsTmp,'title'=>'qa30-ws-invalid','price'=>'1,000,000',
    'deposit_enabled'=>'1','deposit_amount'=>'2,000,000',   // نامعتبر (بزرگ‌تر از قیمت)
    'ws_discount_type'=>'none','companion_price_mode'=>'fixed','status'=>'active','reg_open'=>'1',
  ], $acm[1] ?? '', A_COOKIE);
$inv = DB::one('SELECT deposit_enabled, deposit_amount FROM fr_events WHERE id=?', [(int)$wsTmp]);
ok($inv && (int)$inv['deposit_enabled'] === 0 && $inv['deposit_amount'] === null,
   'بیعانهٔ نامعتبر (۲م > قیمت ۱م) در ذخیرهٔ مدیر نادیده گرفته شد', json_encode($inv));
[, , $svB2] = post_csrf('/admin/workshops.php', [
    'act'=>'save','id'=>(int)$wsTmp,'title'=>'qa30-ws-invalid','price'=>'1,000,000',
    'deposit_enabled'=>'1','deposit_amount'=>'250,000',      // معتبر
    'ws_discount_type'=>'none','companion_price_mode'=>'fixed','status'=>'active','reg_open'=>'1',
  ], $acm[1] ?? '', A_COOKIE);
$inv2 = DB::one('SELECT deposit_enabled, deposit_amount FROM fr_events WHERE id=?', [(int)$wsTmp]);
ok($inv2 && (int)$inv2['deposit_enabled'] === 1 && (int)$inv2['deposit_amount'] === 250000,
   'بیعانهٔ معتبر (۲۵۰هزار) از فرم مدیر ذخیره شد', json_encode($inv2));
// ذخیرهٔ بیعانهٔ خدمت مشاوره از فرم مدیر
preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $acf, $acm2);
[, , $svC] = post_csrf('/admin/consult.php', [
    'act'=>'save_service','id'=>(int)$svcId,'consultant_id'=>(int)$cnsId,'title'=>'qa30 جلسهٔ آزمون بیعانه',
    'slug'=>'qa30-svc-dep','price'=>'800,000','duration_min'=>'45','mode'=>'inperson','active'=>'1',
    'deposit_enabled'=>'1','deposit_amount'=>'200,000','gap_minutes'=>'0',
  ], $acm2[1] ?? '', A_COOKIE);
$svcChk = DB::one('SELECT deposit_enabled, deposit_amount FROM fr_consult_services WHERE id=?', [$svcId]);
ok($svcChk && (int)$svcChk['deposit_enabled'] === 1 && (int)$svcChk['deposit_amount'] === 200000, 'بیعانهٔ خدمت مشاوره از فرم مدیر ذخیره شد');
// صفحهٔ ثبت‌نام‌شدگان مدیر: وضعیت بیعانه + مانده
[$rgC, $rgb] = getx('/admin/registrants.php?ctx=workshop&id=' . $ws3, A_COOKIE);
ok($rgC === 200 && (str_contains($rgb, 'پیش‌پرداخت') || str_contains($rgb, '💳')), 'صفحهٔ ثبت‌نام‌شدگان مدیر: وضعیت پیش‌پرداخت دیده می‌شود', "http=$rgC");
ok(str_contains($rgb, 'مانده'), 'ماندهٔ حساب در لیست ثبت‌نام‌شدگان مدیر نمایش داده می‌شود');

/* ═══ ۷) کرون — یادآور خودکار تصفیه (نهایتاً ۳ روز مانده) ═══ */
echo "\n═══ ۷) cron — یادآور T-۳ روز + لینک پرداخت ═══\n";
// رویداد با شروع ۴۸ ساعت دیگر (داخل پنجرهٔ ۷۲ ساعته) + بیعانهٔ تسویه‌نشده
$ws4 = DB::insert('events', [
    'kind'=>'workshop','title'=>'qa30-کارگاه کرون','slug'=>'qa30-ws-cron','price'=>1_000_000,
    'starts_at'=>date('Y-m-d H:i:s', time()+48*3600),'status'=>'active','reg_open'=>1,
    'deposit_enabled'=>1,'deposit_amount'=>300_000,
]);
$ev4 = DB::one('SELECT * FROM fr_events WHERE id=?', [$ws4]);
$r5 = create_event_order($U1, $ev4, [], null, true, 'deposit');
$reg5 = DB::one('SELECT * FROM fr_event_registrations WHERE event_id=? AND user_id=1', [$ws4]);
ok($reg5 && $reg5['status'] === 'deposit' && $reg5['settle_reminded_at'] === null, 'رکورد بیعانهٔ نزدیک به رویداد (۴۸ ساعت) آمادهٔ کرون است');
// نوبت مخاطب کرون: نوبت دوم (۲ روز دیگر) از بخش ۵ هنوز تصفیه نشده
$dueRegs = fr_settle_due_registrations(72);
ok((bool)array_filter($dueRegs, fn($x) => (int)$x['id'] === (int)$reg5['id']), 'انتخاب‌گر کرون: ثبت‌نام بیعانه در پنجرهٔ ۷۲ ساعت گرفته می‌شود');
$dueAppts = fr_settle_due_appointments(72);
ok((bool)array_filter($dueAppts, fn($x) => (int)$x['id'] === (int)$apptP['id']), 'انتخاب‌گر کرون: نوبت بیعانه در پنجرهٔ ۷۲ ساعت گرفته می‌شود');
$out = []; $rcron = 0;
exec('php ' . escapeshellarg('/tmp/feria-smoke/cron/reminders.php') . ' ' . escapeshellarg(CRON_KEY) . ' 2>&1', $out, $rcron);
$cronTxt = implode("\n", $out);
ok($rcron === 0, 'اجرای کرون بدون خطا', substr($cronTxt, 0, 200));
ok(str_contains($cronTxt, 'settle:sent=') || str_contains($cronTxt, 'settle:'), 'خروجی کرون خط وضعیت settle را دارد', $cronTxt);
$reg5b = DB::one('SELECT settle_reminded_at FROM fr_event_registrations WHERE id=?', [(int)$reg5['id']]);
ok(!empty($reg5b['settle_reminded_at']), 'یادآور رویداد: settle_reminded_at ثبت شد (فقط یک‌بار)');
$apptPb = DB::one('SELECT settle_reminded_at FROM fr_consult_appointments WHERE id=?', [(int)$apptP['id']]);
ok(!empty($apptPb['settle_reminded_at']), 'یادآور نوبت: settle_reminded_at ثبت شد');
// متن پیام به sms_logs: باید لینک پرداخت + مبلغ مانده + اشاره به پنل داشته باشد
$smsRow = DB::one("SELECT body FROM fr_sms_logs WHERE event='settle_remind' ORDER BY id DESC LIMIT 1");
ok($smsRow && str_contains((string)$smsRow['body'], 'settle.php'), 'متن یادآور: لینک پرداخت (settle.php) در پیامک ثبت شد', substr((string)($smsRow['body'] ?? ''), 0, 90));
ok($smsRow && (str_contains((string)$smsRow['body'], 'پنل کاربری') || str_contains((string)$smsRow['body'], 'پنل')), 'متن یادآور: اشاره به ورود به پنل کاربری');
ok($smsRow && (str_contains((string)$smsRow['body'], 'tok=') ) && str_contains((string)$smsRow['body'], 'تومان'), 'متن یادآور: توکن اختصاصی + مبلغ مانده');
$actRow = DB::one("SELECT id FROM fr_activity_logs WHERE action='settle_remind' ORDER BY id DESC LIMIT 1");
ok((bool)$actRow, 'لاگ فعالیت settle_remind ثبت شد');
// اجرای دوبارهٔ کرون — یادآور دوباره نرود (تک‌بار)
$out2 = []; exec('php ' . escapeshellarg('/tmp/feria-smoke/cron/reminders.php') . ' ' . escapeshellarg(CRON_KEY) . ' 2>&1', $out2);
ok(!str_contains(implode("\n", $out2), 'settle:sent='), 'اجرای دوبارهٔ کرون: یادآور تصفیه تکرار نمی‌شود', implode('|', $out2));

/* ═══ ۸) عودت وجه در حالت پیش‌پرداخت ═══ */
echo "\n═══ ۸) عودت وجه فقط به میزان واقعی پرداخت (بیعانه) ═══\n";
// کارت بانکی معتبر (لوهن) برای کاربر آزمایشی
$card = '603799' . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
while (!fr_valid_bank_card($card)) { $card = '603799' . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT); }
$setc = fr_set_bank_card(1, $card);
ok(!empty($setc['ok']), 'کارت بانکی معتبر آزمایشی ثبت شد (پیش‌نیاز عودت)');
// reg4 (کارگاه نمایش پنل) هنوز تسویه نشده — ۴۰۰هزار پرداخت کرده
$refId = refund_request_event((int)$reg4['id'], 1, 'qa30 refund test');
$rf = DB::one('SELECT amount, status FROM fr_refund_requests WHERE id=?', [$refId]);
ok($rf && (int)$rf['amount'] === 400000, 'درخواست عودت ثبت‌نام بیعانه: مبلغ = فقط ۴۰۰هزار پرداخت‌شده (نه کل ۱٫۲م)', (string)($rf['amount'] ?? 0));
$refId2 = refund_request_consult((int)$apptP['id'], 1, 'qa30 refund test');
$rf2 = DB::one('SELECT amount FROM fr_refund_requests WHERE id=?', [$refId2]);
ok($rf2 && (int)$rf2['amount'] === 200000, 'درخواست عودت نوبت بیعانه: مبلغ = فقط ۲۰۰هزار بیعانه', (string)($rf2['amount'] ?? 0));

/* ═══ پایانی ═══ */
qa30_clean();
echo "\n════════════════ qa-v30: PASS=$pass FAIL=$fail ════════════════\n";
exit($fail > 0 ? 1 : 0);
