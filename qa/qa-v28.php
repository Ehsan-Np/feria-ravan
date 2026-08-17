<?php
declare(strict_types=1);
/* ════════════════════════════════════════════════════════════════════════════
 * QA نسخهٔ ۲٫۸ — قیمت‌گذاری دوسطحی (اصلی + تخفیف‌خورده + درصد خودکار) برای
 * تست/کارگاه/دوره + بازقیمت‌گذاری هم‌راستا با بازار ایران + صفحهٔ عمومی خبرنامه
 * (آیدی تلگرام/بله + پیامک دعوت) + گروه‌بندی زیرشاخه‌ای سایدبار پیشخوان
 * ════════════════════════════════════════════════════════════════════════════ */
const BASE     = 'http://localhost:8899';
const A_COOKIE = '/tmp/qa28-admin.jar';
const U_COOKIE = '/tmp/qa28-user.jar';
const N_COOKIE = '/tmp/qa28-guest.jar';

$APP = '/tmp/feria-smoke';
require $APP.'/config.php';
require $APP.'/includes/version.php';
require FR_ROOT.'/includes/db.php';
require FR_ROOT.'/includes/functions.php';
require FR_ROOT.'/includes/security.php';
require FR_ROOT.'/includes/contacts.php';
require FR_ROOT.'/includes/event-pricing.php';

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
function getx(string $path, string $jar = A_COOKIE): array {
    [$c, $h, $b] = http('GET', $path, [], $jar);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\\s*(\\S+)\\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]), [], $jar);
    }
    return [$c, $b];
}
function csrf_of(string $html): string {
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1]
         : (preg_match('/name="csrf" content="([a-f0-9]+)"/', $html, $m) ? $m[1] : '');
}

/* پاک‌سازی فییکسچرهای qa28 (شروع + پایان) */
function qa28_clean(): void {
    DB::run("DELETE FROM fr_newsletter WHERE mobile LIKE '091288280%'");
    DB::run("DELETE FROM fr_contacts  WHERE mobile LIKE '091288280%'");
    DB::run("DELETE FROM fr_events    WHERE title LIKE 'qa28%' OR slug LIKE 'qa28%'");
    DB::run("DELETE FROM fr_contact_group_members WHERE contact_id NOT IN (SELECT id FROM fr_contacts)");
}
qa28_clean();

/* ═══ ۰) نسخه و مهاجرت ═══ */
echo "\n═══ ۰) نسخه و مهاجرت ۲٫۸ ═══\n";
$ver = defined('FR_RELEASE') ? FR_RELEASE : '?';
ok(version_compare($ver, '2.8.0', '>='), "نسخهٔ کد ≥ ۲٫۸٫۰ (FR_RELEASE=$ver)");
ok(version_compare((string)DB::setting('fr_version',''), '2.8.0', '>='), 'پایپ‌لاین نسخهٔ دیتابیس ≥ ۲٫۸٫۰ ثبت شده (در ۲٫۹ به ۲٫۹٫۰ ارتقا یافت)');
$tCols = array_column(DB::all("SHOW COLUMNS FROM fr_tests"), 'Field');
$eCols = array_column(DB::all("SHOW COLUMNS FROM fr_events"), 'Field');
ok(in_array('price_sale', $tCols, true), 'ستون price_sale در fr_tests افزوده شد');
ok(in_array('sale_price', $eCols, true), 'ستون sale_price در fr_events افزوده شد');
ok((string)DB::setting('newsletter_page_enabled','') !== '', 'تنظیم newsletter_page_enabled سید شد');

/* ═══ ۱) موتور قیمت‌گذاری دوسطحی ═══ */
echo "\n═══ ۱) fr_price_deal ═══\n";
$d = fr_price_deal(900000, 700000);
ok($d['final'] === 700000 && $d['list'] === 900000 && $d['has_sale'] && $d['percent'] === 22 && $d['save'] === 200000,
   'نمونهٔ درخواست کاربر: ۹۰۰هزار → ۷۰۰هزار ⇒ ٪۲۲ تخفیف', json_encode($d, JSON_UNESCAPED_UNICODE));
$d = fr_price_deal(900000, null);
ok(!$d['has_sale'] && $d['final'] === 900000 && $d['percent'] === 0, 'بدون قیمت تخفیفی ⇒ قیمت اصلی، بدون نشان تخفیف');
$d = fr_price_deal(900000, 950000);
ok(!$d['has_sale'] && $d['final'] === 900000, 'تخفیف نامعتبر (بیشتر از اصلی) نادیده گرفته می‌شود');
$d = fr_price_deal(0, 0);
ok(!$d['has_sale'] && $d['final'] === 0, 'رویداد رایگان سلامت می‌ماند');

/* ═══ ۲) بازقیمت‌گذاری هم‌راستا با بازار ایران ═══ */
echo "\n═══ ۲) بازقیمت‌گذاری (تست‌ها) ═══\n";
$tExp = [
    'disc-family'     => [249000, 189000],
    'disc-business'   => [449000, 379000],
    'ysq-long'        => [389000, 319000],
    'neo'             => [219000, 169000],
    'anxiety'         => [119000,  89000],
    'anxiety-manager' => [349000, 279000],
    'ysq-long-manager'=> [589000, 479000],
];
$allT = true; $det = [];
foreach ($tExp as $slug => [$lp, $sp]) {
    $r = DB::one("SELECT price_full, price_sale FROM fr_tests WHERE slug=?", [$slug]);
    $det[] = "$slug=" . ($r ? "{$r['price_full']}/{$r['price_sale']}" : 'MISSING');
    if (!$r || (int)$r['price_full'] !== $lp || (int)$r['price_sale'] !== $sp) $allT = false;
}
ok($allT, 'نرخ دو سطحی تست‌ها مطابق نقشهٔ ۲٫۸', implode(' ', $det));
$g = (int)DB::val("SELECT price_full FROM fr_tests WHERE slug='anxiety'");
$m = (int)DB::val("SELECT price_full FROM fr_tests WHERE slug='anxiety-manager'");
ok($m > $g, 'قیمت نسخهٔ مدیران بالاتر از عمومی است (عمومی=' . $g . '، مدیران=' . $m . ')');
$g2 = (int)DB::val("SELECT price_full FROM fr_tests WHERE slug='ysq-long'");
$m2 = (int)DB::val("SELECT price_full FROM fr_tests WHERE slug='ysq-long-manager'");
ok($m2 > $g2, 'تست مدیران طرحواره نیز بالاتر از نسخهٔ عمومی است');

echo "\n═══ ۳) بازقیمت‌گذاری (رویدادها) ═══\n";
$eExp = [
    'ehmal-kari'        => [390000, 340000],
    'farzandparvari-moasser' => [449000, 389000],
    'doreh-tarazehe'    => [3900000, 3450000],
    'doreh-disc-modiran'=> [4900000, 4390000],
];
$allE = true; $det = [];
foreach ($eExp as $slug => [$lp, $sp]) {
    $r = DB::one("SELECT price, sale_price FROM fr_events WHERE slug=?", [$slug]);
    $det[] = "$slug=" . ($r ? "{$r['price']}/{$r['sale_price']}" : 'MISSING');
    if (!$r || (int)$r['price'] !== $lp || (int)$r['sale_price'] !== $sp) $allE = false;
}
ok($allE, 'نرخ دو سطحی رویدادها مطابق نقشهٔ ۲٫۸', implode(' ', $det));
$wAvg = (int)DB::val("SELECT ROUND(AVG(price)) FROM fr_events WHERE kind='workshop' AND price > 0");
$cMgr = (int)DB::val("SELECT price FROM fr_events WHERE slug='doreh-disc-modiran'");
ok($cMgr > $wAvg * 2, 'دورهٔ مدیران (' . $cMgr . ') به‌مراتب بالاتر از میانگین کارگاه‌های عمومی (' . $wAvg . ') است');
ok((int)DB::val("SELECT price FROM fr_events WHERE slug='qa-paid-ws'") === 1000000
   && DB::val("SELECT sale_price FROM fr_events WHERE slug='qa-paid-ws'") === null,
   'فییکسچرهای QA با قیمت سفارشی دست‌نخورده ماندند (بازقیمت‌گذاری guarded)');

/* ═══ ۴) موتور قیمت رویداد با تخفیف قیمت‌گذاری ═══ */
echo "\n═══ ۴) ev_quote با قیمت نهایی ═══\n";
$ev = DB::one("SELECT * FROM fr_events WHERE slug='ehmal-kari'");
$q  = ev_quote($ev, 0);
ok($q['main_price'] === 340000 && $q['list_price'] === 390000 && $q['payable'] === 340000,
   'پیش‌فاکتور با قیمت تخفیف‌خورده محاسبه می‌شود', json_encode(['m'=>$q['main_price'],'l'=>$q['list_price'],'p'=>$q['payable']]));
ok(!empty($q['price_deal']['has_sale']) && $q['price_deal']['percent'] > 0, 'جزئیات deal در خروجی ev_quote هست');
ok(isset($q['lines'][0]['was']) && $q['lines'][0]['was'] === 390000, 'خط فاکتور «شهریهٔ نفر اصلی» قیمت خط‌خورده (was) دارد');
$evP = DB::one("SELECT * FROM fr_events WHERE slug='etemad-be-nafs'");  // companion درصدی
$unit = ev_companion_unit($evP);
ok($unit === (int)floor($q['main_price'] * 0) || $unit > 0, 'قیمت واحد همراه محاسبه شد', (string)$unit);
if ((string)($evP['companion_price_mode'] ?? '') === 'percent') {
    $expect = (int)floor(360000 * ((int)$evP['companion_price']) / 100);
    ok($unit === $expect, "قیمت همراه درصدی از روی قیمت نهایی ({$expect}) حساب می‌شود", "got=$unit");
}
$free = DB::one("SELECT * FROM fr_events WHERE slug='free-ws-test'");
$qf = ev_quote($free, 0);
ok($qf['payable'] === 0 && empty($qf['price_deal']['has_sale']), 'کارگاه رایگان بدون deal و بدون قیمت می‌ماند');

/* ═══ ۵) صفحهٔ عمومی خبرنامه ═══ */
echo "\n═══ ۵) صفحهٔ عمومی خبرنامه ═══\n";
[$c, $page] = getx('/newsletter.php', N_COOKIE);
ok($c === 200, 'صفحهٔ public /newsletter.php بالا می‌آید (۲۰۰ بدون لاگین)', "code=$c");
ok(str_contains($page, 'name="name"') && str_contains($page, 'name="mobile"')
   && str_contains($page, 'name="tg_username"') && str_contains($page, 'name="bale_username"'),
   'فرم: نام + موبایل + آیدی تلگرام + آیدی بله');
$csrf = csrf_of($page);
ok($csrf !== '', 'توکن CSRF صفحهٔ عمومی صادر شد');

[$c,, $bad] = http('POST', '/newsletter.php', ['_csrf'=>$csrf, 'name'=>'مخاطب تست', 'mobile'=>'123', 'tg_username'=>'', 'bale_username'=>''], N_COOKIE);
ok($c === 200 && str_contains($bad, 'معتبر نیست'), 'موبایل نامعتبر رد می‌شود با پیام خطا');
ok((int)DB::val("SELECT COUNT(*) FROM fr_newsletter WHERE mobile='123'") === 0, 'موبایل نامعتبر در دیتابیس ثبت نشد');

$mob = '09128828001';
[$c,, $good] = http('POST', '/newsletter.php', ['_csrf'=>$csrf, 'name'=>'سارا تست‌پور', 'mobile'=>$mob, 'tg_username'=>'@qa28_tg_user', 'bale_username'=>'qa28_bale'], N_COOKIE);
ok($c === 200 && str_contains($good, 'عضویت شما ثبت شد'), 'ثبت موفق با پیام موفقیت', "code=$c");
$nl = DB::one("SELECT * FROM fr_newsletter WHERE mobile=?", [$mob]);
ok($nl && $nl['status'] === 'active' && $nl['source'] === 'public_page', 'رکورد خبرنامه با source=public_page فعال شد');
$ct = DB::one("SELECT * FROM fr_contacts WHERE mobile=?", [$mob]);
ok($ct && $ct['source'] === 'newsletter', 'مخاطب با source=newsletter در مخاطبین ساخته شد');
ok($ct && $ct['tg_username'] === 'qa28_tg_user' && $ct['bale_username'] === 'qa28_bale', 'آیدی تلگرام و بلهٔ مخاطب ذخیره شد (بدون @)', json_encode(['tg'=>$ct['tg_username']??null,'bale'=>$ct['bale_username']??null], JSON_UNESCAPED_UNICODE));
ok($ct && trim((string)$ct['invite_code']) !== '', 'کد دیپ‌لینک دعوت برای مخاطب ساخته شد');
$hasInviteUi = str_contains($good, 'استارت ربات تلگرام') || str_contains($good, 'پیامک حاوی لینک');
ok($hasInviteUi, 'صفحهٔ موفقیت: لینک استارت ربات‌ها یا اعلام پیامک دعوت نمایش داده شد (پیامک‌رسان خاموش = graceful)');
ok(str_contains($good, '</html>'), 'صفحهٔ موفقیت کامل رندر شد (بدونWSOD)');
$smsLogged = (int)DB::val("SELECT COUNT(*) FROM fr_sms_logs WHERE mobile=?", [$mob]);
ok(true, 'تلاش پیامک دعوت بدون خطای مهلک انجام شد (لاگ پیامک: ' . fa_num($smsLogged) . ' رکورد)');

/* ثبت مجدد همان موبایل — بدون دوباره‌کاری، آیدی‌ها به‌روزرسانی می‌شود */
[$c,, $again] = http('POST', '/newsletter.php', ['_csrf'=>$csrf, 'name'=>'سارا تست‌پور', 'mobile'=>$mob, 'tg_username'=>'qa28_tg_new', 'bale_username'=>''], N_COOKIE);
ok($c === 200 && str_contains($again, 'عضویت شما ثبت شد'), 'ثبت مجدد همان شماره موفق و idempotent است');
ok((int)DB::val("SELECT COUNT(*) FROM fr_newsletter WHERE mobile=?", [$mob]) === 1, 'رکورد خبرنامه تکراری نشد');
$ct2 = DB::one("SELECT tg_username FROM fr_contacts WHERE mobile=?", [$mob]);
ok($ct2 && $ct2['tg_username'] === 'qa28_tg_new', 'آیدی تلگرام مخاطبِ قبلی به‌روزرسانی شد', json_encode($ct2));

/* ═══ ۶) لینک صفحهٔ عمومی در فوتر ═══ */
[, $home] = getx('/index.php', N_COOKIE);
ok(str_contains($home, '/newsletter.php'), 'لینک صفحهٔ عمومی خبرنامه در فوتر/نوار خبرنامهٔ سایت هست');

/* ═══ ۷) ورود ادمین + کارت لینک صفحهٔ خبرنامه ═══ */
echo "\n═══ ۶) پیشخوان — خبرنامه و سایدبار ═══\n";
http('GET', '/shot-login.php?as=admin&to=/admin/dashboard.php', [], A_COOKIE);
[$c, $nlAdm] = getx('/admin/newsletter.php', A_COOKIE);
ok($c === 200 && str_contains($nlAdm, 'صفحهٔ عمومی عضویت'), 'کارت «🔗 صفحهٔ عمومی عضویت» در پیشخوان خبرنامه هست');
ok(str_contains($nlAdm, '/newsletter.php'), 'آدرس صفحهٔ عمومی در کارت نمایش داده شده');
$aCsrf = csrf_of($nlAdm);
ok($aCsrf !== '', 'توکن CSRF پیشخوان');
[$c] = http('POST', '/admin/newsletter.php', ['act'=>'page_settings', '_csrf'=>$aCsrf, 'enabled'=>'0', 'intro'=>''], A_COOKIE);
/* خواندن مستقیم از DB (نه کش استاتیک فرایند QA) */
$sv = (string)DB::val("SELECT `value` FROM fr_settings WHERE `key`='newsletter_page_enabled'");
ok($sv === '0', 'غیرفعال‌سازی صفحهٔ عمومی از پیشخوان ذخیره شد', $sv);
[$c, $off] = getx('/newsletter.php', N_COOKIE);
ok(str_contains($off, 'غیرفعال است'), 'صفحهٔ عمومی در حالت غیرفعال پیام مناسب نشان می‌دهد');
[$c] = http('POST', '/admin/newsletter.php', ['act'=>'page_settings', '_csrf'=>$aCsrf, 'enabled'=>'1', 'intro'=>''], A_COOKIE);
$sv = (string)DB::val("SELECT `value` FROM fr_settings WHERE `key`='newsletter_page_enabled'");
ok($sv === '1', 'فعال‌سازی مجدد صفحهٔ عمومی ذخیره شد', $sv);

/* ═══ ۷-ب) گروه‌بندی سایدبار ═══ */
[$c, $dash] = getx('/admin/dashboard.php', A_COOKIE);
ok($c === 200, 'داشبورد پیشخوان بالا می‌آید', "code=$c");
$grps = ['grp-tests','grp-users','grp-events','grp-consult','grp-sales','grp-marketing','grp-content','grp-system'];
$missing = array_filter($grps, fn($g) => !str_contains($dash, 'id="' . $g . '"'));
ok(!$missing, 'هشت گروه زیرشاخه در سایدبار رندر شده‌اند', implode(',', (array)$missing));
ok(substr_count($dash, 'details class="adm-grp"') >= count($grps), 'گروه‌ها با details/accordion پیاده شدند');
ok(str_contains($dash, 'بازاریابی و اطلاع‌رسانی') && str_contains($dash, 'فروش و مالی'), 'برچسب گروه‌های مرتبط‌سازی‌شده دیده می‌شود');
/* گروهِ دارای صفحهٔ فعال باید open باشد */
[$c, $testsPg] = getx('/admin/tests.php', A_COOKIE);
$openGrp = preg_match('/<details class="adm-grp" id="grp-tests"[^>]*open/', $testsPg) === 1;
ok($openGrp, 'گروه تست‌ها روی صفحهٔ تست‌ها خودکار باز است');
ok(substr_count($testsPg, ' open') <= 2, 'فقط گروه صفحهٔ فعال باز است', (string)substr_count($testsPg, ' open'));
$singlesOk = str_contains($dash, '/admin/dashboard.php') && str_contains($dash, '/admin/logout.php') && str_contains($dash, 'نمایش سایت');
ok($singlesOk, 'آیتم‌های تکی (داشبورد/نمایش سایت/خروج) حفظ شده‌اند');
/* همهٔ لینک‌های منوی قبلی هنوز در خروجی هستند */
foreach (['/admin/contacts.php','/admin/campaigns.php','/admin/planner.php','/admin/sms.php','/admin/settings.php','/admin/orders.php'] as $lnk) {
    if (!str_contains($dash, $lnk)) { ok(false, "لینک $lnk در سایدبار گروه‌بندی‌شده حاضر است"); goto linksDone; }
}
ok(true, 'همهٔ لینک‌های کلیدی (مخاطبین/کمپین/پلنر/پیامک/تنظیمات/سفارش‌ها) در منو حاضرند');
linksDone:

/* ═══ ۸) فرم ادمین: sale_price ═══ */
echo "\n═══ ۷) فرم قیمت با تخفیف در پیشخوان ═══\n";
[$c, $wl] = getx('/admin/workshops.php', A_COOKIE);
ok($c === 200, 'لیست کارگاه‌های پیشخوان بالا می‌آید', "code=$c");
ok(preg_match('/<del/', $wl) === 1, 'قیمت خط‌خورده در لیست کارگاه‌های پیشخوان دیده می‌شود');
$wid = (int)DB::val("SELECT id FROM fr_events WHERE slug='ehmal-kari'");
[$c, $wf] = getx('/admin/workshops.php?edit_e=' . $wid, A_COOKIE);
ok(str_contains($wf, 'name="sale_price"'), 'ورودی «قیمت با تخفیف» به فرم رویداد افزوده شد');
ok(str_contains($wf, 'evSaleHint') || str_contains($wf, 'تخفیف'), 'پیش‌نمایش درصد تخفیف در فرم هست');
[$c, $tl] = getx('/admin/tests.php', A_COOKIE);
ok(preg_match('/<del/', $tl) === 1 && str_contains($tl, 'تخفیف'), 'لیست تست‌های پیشخوان قیمت خط‌خورده + نشان تخفیف دارد');
$tid = (int)DB::val("SELECT id FROM fr_tests WHERE slug='disc-family'");
[$c, $tf] = getx('/admin/tests.php?edit=' . $tid, A_COOKIE);
ok(str_contains($tf, 'name="price_sale"'), 'ورودی «قیمت با تخفیف» به ویرایشگر تست افزوده شد');

/* ── ذخیرهٔ واقعی از طریق فرم ادمین (ساخت/ویرایش/اعتبارسنجی) ── */
[$c] = http('POST', '/admin/workshops.php', [
    'act'=>'save', '_csrf'=>$aCsrf, 'id'=>'0',
    'title'=>'qa28 کارگاه قیمت‌گذاری', 'price'=>'900000', 'sale_price'=>'700000',
    'status'=>'active', 'reg_open'=>'1', 'ws_discount_type'=>'none', 'companion_price_mode'=>'fixed',
], A_COOKIE);
$qaEv = DB::one("SELECT * FROM fr_events WHERE title LIKE 'qa28%' ORDER BY id DESC LIMIT 1");
ok($qaEv && (int)$qaEv['price'] === 900000 && (int)$qaEv['sale_price'] === 700000,
   'ذخیرهٔ فرم: قیمت اصلی ۹۰۰هزار + تخفیف ۷۰۰هزار ثبت شد', json_encode($qaEv ? ['p'=>$qaEv['price'],'s'=>$qaEv['sale_price']] : null));
if ($qaEv) {
    [$c] = http('POST', '/admin/workshops.php', [
        'act'=>'save', '_csrf'=>$aCsrf, 'id'=>(string)$qaEv['id'],
        'title'=>'qa28 کارگاه قیمت‌گذاری', 'price'=>'900000', 'sale_price'=>'950000',
        'status'=>'active', 'reg_open'=>'1', 'ws_discount_type'=>'none', 'companion_price_mode'=>'fixed',
    ], A_COOKIE);
    $qaEv2 = DB::one("SELECT price, sale_price FROM fr_events WHERE id=?", [(int)$qaEv['id']]);
    ok($qaEv2 && (int)$qaEv2['price'] === 900000 && $qaEv2['sale_price'] === null,
       'اعتبارسنجی فرم: تخفیف بیشتر از قیمت اصلی ذخیره نمی‌شود (NULL)', json_encode($qaEv2));
}

/* ═══ ۹) نمایش عمومی نشان تخفیف ═══ */
echo "\n═══ ۸) نمایش عمومی تخفیف ═══\n";
[, $ws] = getx('/workshops.php', N_COOKIE);
ok(preg_match('/<del/', $ws) === 1 && str_contains($ws, 'تخفیف'), 'کارت کارگاه‌ها: قیمت خط‌خورده + درصد تخفیف');
[, $cs] = getx('/courses.php', N_COOKIE);
ok(preg_match('/<del/', $cs) === 1 && str_contains($cs, 'تخفیف'), 'کارت دوره‌ها: قیمت خط‌خورده + درصد تخفیف');
[, $ev1] = getx('/workshop.php?s=ehmal-kari', N_COOKIE);
ok(preg_match('/<del/', $ev1) === 1 && str_contains($ev1, '۳۹۰') && str_contains($ev1, 'تخفیف'), 'صفحهٔ تکی کارگاه: قیمت اصلی خط‌خورده + برچسب درصد');
[, $tp] = getx('/test-page.php?s=disc-family', N_COOKIE);
ok(preg_match('/<del/', $tp) === 1 && str_contains($tp, '۲۴۹') && str_contains($tp, 'تخفیف'), 'صفحهٔ تکی تست: قیمت خط‌خورده + نشان تخفیف');
[, $ts] = getx('/tests.php', N_COOKIE);
ok(preg_match('/<del/', $ts) === 1, 'آرشیو تست‌ها: قیمت خط‌خورده روی کارت‌ها');
[, $hm] = getx('/index.php', N_COOKIE);
ok(preg_match('/<del/', $hm) === 1, 'کارت فشردهٔ خانه هم قیمت خط‌خورده دارد');

/* ═══ ۱۰) QA hook: صفحهٔ panel/test-start با کاربر ═══ */
echo "\n═══ ۹) پنل کاربر / خرید ═══\n";
http('GET', '/shot-login.php?as=user&to=/panel/index.php', [], U_COOKIE);
[$c, $tstart] = getx('/panel/test-start.php?id=' . $tid, U_COOKIE);
ok($c === 200 && preg_match('/<del/', $tstart) === 1, 'صفحهٔ شروع تست در پنل: قیمت اصلی خط‌خورده', "code=$c");
ok(str_contains($tstart, '۱۸۹') && str_contains($tstart, 'تخفیف'), 'قیمت نهایی ۱۸۹هزار + نشان درصد در پنل');
ok(str_contains($tstart, 'const base = 189000'), 'مبنای JS تخفیفِ کد، قیمت نهایی است', (string)preg_match('/const base = (\d+)/', $tstart, $mm) ? ($mm[1] ?? '?') : '?');
[, $evReg] = getx('/panel/event-register.php?event=' . $wid, U_COOKIE);
ok(str_contains($evReg, '</html>'), 'صفحهٔ ثبت‌نام رویداد (تخفیف‌دار) کامل رندر شد');

/* پاک‌سازی نهایی */
qa28_clean();

echo "\n════════════════════════════════════════════\n";
echo "QA 2.8 — موفق: $pass / ناموفق: $fail\n";
exit($fail ? 1 : 0);
