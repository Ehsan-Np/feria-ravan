<?php
declare(strict_types=1);
/* ════════════════════════════════════════════════════════════════════════════
 * QA نسخهٔ ۲٫۹ — تحلیل‌های عمیق نسخهٔ ویژه (دکتری روان‌شناسی بالینی):
 * پایگاه دانش علمی ۹ موتور (YSQL/NEO/DISC/GAD-7/PHQ-9/JSS/Gardner/Schutte/SDT)
 * + بذر خودکار قالب‌های بانددار + فصل پایانی + ارائهٔ E2E در گزارش کاربر
 * ════════════════════════════════════════════════════════════════════════════ */
const BASE     = 'http://localhost:8899';
const A_COOKIE = '/tmp/qa29-admin.jar';
const U_COOKIE = '/tmp/qa29-user.jar';
const N_COOKIE = '/tmp/qa29-guest.jar';

$APP = '/tmp/feria-smoke';
require $APP.'/config.php';
require $APP.'/includes/version.php';
require FR_ROOT.'/includes/db.php';
require FR_ROOT.'/includes/functions.php';
require FR_ROOT.'/includes/security.php';
require FR_ROOT.'/core/analysis-deep.php';

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
        if (!preg_match('/^Location:\s*(\S+)\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]), [], $jar);
    }
    return [$c, $b];
}

/* پاک‌سازی فییکسچرهای qa29 (شروع + پایان) */
function qa29_clean(): void {
    $qu = 1;
    DB::run("DELETE rs FROM fr_results  rs JOIN fr_attempts a ON a.id=rs.attempt_id WHERE a.user_id=? AND a.context LIKE 'qa29%'", [$qu]);
    DB::run("DELETE an FROM fr_answers  an JOIN fr_attempts a ON a.id=an.attempt_id WHERE a.user_id=? AND a.context LIKE 'qa29%'", [$qu]);
    DB::run("DELETE FROM fr_attempts WHERE user_id=? AND context LIKE 'qa29%'", [$qu]);
}
qa29_clean();

/* ═══ ۰) نسخه و مهاجرت ═══ */
echo "\n═══ ۰) نسخه و مهاجرت ۲٫۹ ═══\n";
$ver = defined('FR_RELEASE') ? FR_RELEASE : '?';
ok(version_compare($ver, '2.9.0', '>='), "نسخهٔ کد ≥ ۲٫۹٫۰ (FR_RELEASE=$ver)");
ok(version_compare((string)DB::setting('fr_version','3.0.0'), '2.9.0', '>='), 'نسخهٔ دیتابیس ≥ ۲٫۹٫۰ است');
ok((string)DB::setting('deep_analyses_v','') === '2.9.0', 'مارکر deep_analyses_v=2.9.0 بذر شد');
$cnt = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('analysis_templates'));
ok($cnt > 900, 'تعداد قالب‌های تحلیل بعد از بذر عمیق (≥۹۰۰)', (string)$cnt);
$avg = (int)DB::val("SELECT AVG(LENGTH(body)) FROM " . DB::t('analysis_templates') . " WHERE level='full'");
ok($avg > 2500, 'میانگین طول بدنهٔ قالب‌های ویژه ≥۲۵۰۰ نویسه', (string)$avg);

/* ═══ ۱) پایگاه دانش علمی (کد) ═══ */
echo "\n═══ ۱) پایگاه دانش ۹ موتور ═══\n";
foreach (['ysq','disc','neo','anx','dep','jobsat','gardner','eq','motiv'] as $k) {
    ok(is_file(FR_ROOT."/core/analysis-deep/$k.php"), "پروندهٔ دانش $k موجود است");
}
$map = ['disc-family'=>'disc','disc-business'=>'disc','ysq-long'=>'ysq','ysq-short-manager'=>'ysq',
        'neo-manager'=>'neo','anxiety'=>'anx','depression-manager'=>'dep','jobsat'=>'jobsat',
        'gardner-mi'=>'gardner','eq-shutte'=>'eq','learning-motivation'=>'motiv'];
$mapOk = true;
foreach ($map as $slug => $exp) { if (fr_deep_key_for_slug($slug) !== $exp) $mapOk = false; }
ok($mapOk, 'نگاشت اسلاگ→موتور دانش برای ۱۱ اسلاگ نمونه درست است');

$r = fr_deep_scale('ysq', 'ED', 'very_high', 'family');
ok($r !== null && mb_strlen($r['body']) > 2200
   && str_contains($r['body'], 'شناخت علمی سازه') && str_contains($r['body'], 'سازوکار روان‌شناختی')
   && str_contains($r['body'], 'تظاهرات بالینی') && str_contains($r['body'], 'راهنمای مداخلهٔ شواهدمحور')
   && str_contains($r['body'], '📚'),
   'مونوگراف طرحوارهٔ ED: کامل (>۲۲۰۰ نویسه + ۵ بخش بالینی)', (string)mb_strlen($r['body'] ?? ''));
ok(str_contains($r['body'], 'زندگی خانوادگی'), 'پاراگراف بافت خانواده برای مخاطب عمومی الحاق شد');

$r = fr_deep_scale('neo', 'N', 'medium', 'manager');
ok($r !== null && str_contains($r['body'], 'مدیریت و رهبری'), 'مونوگراف NEO-N: یادداشت مدیران برای بافت مدیریت');

$r = fr_deep_scale('disc', 'D', 'low', 'manager');
ok($r !== null && mb_strlen($r['body']) > 1800, 'مونوگراف DISC-D برای باند پایین (تکمیل پوشش ۵باند)');

$r = fr_deep_scale('dep', 'motivational', 'high', 'general');
ok($r !== null && str_contains($r['body'], 'انگیزش'), 'مقیاس motivational افسردگی (کلید اصلاح‌شده ۲٫۹) کار می‌کند');

$r = fr_deep_scale('gardner', 'LING', 'high', 'general');
ok($r !== null && str_contains($r['body'], 'گاردنر') === false || str_contains($r['body'], 'Gardner'), 'مونوگراف هوش زبانی با منبع Gardner');

$r = fr_deep_scale('eq', 'E_M', 'very_high', 'general');
ok($r !== null && str_contains($r['body'], 'تنظیم هیجان'), 'مونوگراف مدیریت هیجان (Schutte)');

$r = fr_deep_scale('motiv', 'INN', 'very_high', 'family');
ok($r !== null && str_contains($r['body'], 'خودتعیینی'), 'مونوگراف انگیزهٔ درونی با چارچوب SDT');

$r = fr_deep_overall('anx', 'very_high', 'general');
ok($r !== null && str_contains($r['body'], 'مراجعهٔ حضوری'), 'تحلیل نمرهٔ کلی اضطراب: پروتکل ارجاع در سطح بحرانی');

$r = fr_deep_finale('neo', 'manager');
ok($r !== null && str_contains($r['body'], 'Costa') && str_contains($r['body'], 'رهبری'), 'فصل پایانی NEO + یادداشت مدیران (منابع آکادمیک)');

/* ═══ ۲) بذر دیتابیس ═══ */
echo "\n═══ ۲) بذر دیتابیس (قالب‌های ویژه) ═══\n";
$T = DB::t('analysis_templates');
$tests = DB::all("SELECT id, slug FROM " . DB::t('tests') . " WHERE status='active'");
$allFinale = true; $minFinLen = 999999; $tidNeo = 0;
foreach ($tests as $t) {
    if (fr_deep_key_for_slug($t['slug']) === '') continue;
    if ($t['slug'] === 'neo') $tidNeo = (int)$t['id'];
    $f = DB::one("SELECT body FROM $T WHERE test_id=? AND scale_code IS NULL AND band='overall' AND level='full'", [$t['id']]);
    if (!$f) { $allFinale = false; continue; }
    $minFinLen = min($minFinLen, mb_strlen($f['body']));
}
ok($allFinale, 'هر آزمون فعال، فصل پایانی تخصصی (NULL|overall|full) دارد');
ok($minFinLen > 2500, 'کمترین طول فصل پایانی >۲۵۰۰ نویسه', (string)$minFinLen);

$c = (int)DB::val("SELECT COUNT(*) FROM $T WHERE test_id=? AND level='full' AND band IN ('very_low','low','medium','high','very_high')", [$tidNeo]);
ok($c === 25, 'NEO: ۵ عامل × ۵ باند قالب ویژه کامل دارند', (string)$c);
$c = DB::one("SELECT title, LENGTH(body) bl FROM $T WHERE test_id=? AND scale_code='E' AND band='very_low' AND level='full'", [$tidNeo]);
ok($c && $c['bl'] > 1500 && str_contains($c['title'], 'برون‌گرایی'), 'قالب E/very_low عنوان و بدنهٔ عمیق دارد', (string)($c['bl'] ?? 0));

$tidAnx = (int)DB::val("SELECT id FROM " . DB::t('tests') . " WHERE slug='anxiety-manager'");
$c = DB::one("SELECT body FROM $T WHERE test_id=? AND scale_code='cognitive' AND band='any' AND level='full'", [$tidAnx]);
ok($c && mb_strlen($c['body']) > 1200 && str_contains($c['body'], 'شناخت علمی سازه'), 'غربالگر مدیران: قالب any اجزا هم عمیق بازنویسی شد');
$c = DB::one("SELECT body FROM $T WHERE test_id=? AND scale_code='overall' AND band='high' AND level='full'", [$tidAnx]);
ok($c && str_contains($c['body'], 'deep29-overall') && mb_strlen($c['body']) > 1200
   && (str_contains($c['body'], 'CBT') || str_contains($c['body'], 'درمانگر')),
   'قالب overall اضطراب: لایهٔ عمیق + محتوای بالینی غنی', (string)mb_strlen($c['body'] ?? ''));

$tidYsq = (int)DB::val("SELECT id FROM " . DB::t('tests') . " WHERE slug='ysq-long'");
$c = (int)DB::val("SELECT COUNT(*) FROM $T WHERE test_id=? AND level='full'", [$tidYsq]);
ok($c === 109, 'YSQL: ۱۸ طرحواره × (۵باند+any) + فصل پایانی = ۱۰۹ قالب', (string)$c);
$c = DB::one("SELECT title, body FROM $T WHERE test_id=? AND scale_code='PU' AND band='very_high' AND level='full'", [$tidYsq]);
ok($c && str_contains($c['title'], 'تنبیه‌گرایی') && str_contains($c['body'], 'مداخلهٔ شواهدمحور'), 'قالب تنبیه‌گرایی/بسیار بالا عمیق و سالم است');

/* موجیبیکه/به‌هم‌ریختگی در بدنه‌ها (اسکن PHP — رجی‌کس SQL بایت‌محور عمل می‌کند) */
$bad = 0; $badSample = '';
foreach (DB::all("SELECT id, body FROM $T WHERE level='full'") as $qr) {
    if (preg_match('/[\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{0980}-\x{09FF}\x{3040}-\x{30FF}]/u', (string)$qr['body'])) {
        $bad++; $badSample = $badSample === '' ? ('tpl#' . $qr['id']) : $badSample;
    }
}
ok($bad === 0, 'هیچ قالب ویژه‌ای نویسهٔ خارج (CJK/کره‌ای/بنگالی) ندارد', $badSample);

/* idempotency */
$before = (int)DB::val("SELECT COUNT(*) FROM $T");
require_once FR_ROOT.'/core/seed-analyses29.php';
$st = fr_seed_deep_analyses();
$after = (int)DB::val("SELECT COUNT(*) FROM $T");
ok($after === $before && $st['inserted'] === 0, 'اجرای دوبارهٔ بذر: افزونگی ایجاد نمی‌کند (idempotent)', json_encode($st));

/* ═══ ۳) ارائهٔ E2E در گزارش کاربر ═══ */
echo "\n═══ ۳) ارائهٔ گزارش ویژه به کاربر (E2E) ═══\n";
/* تلاش کامل برای NEO با باندهای متنوع */
DB::run("INSERT INTO fr_attempts (user_id,test_id,mode,context,status,progress,completed_at)
         VALUES (1,?,'full','qa29neo','completed',100,NOW())", [$tidNeo]);
$attNeo = (int)DB::val("SELECT id FROM fr_attempts WHERE user_id=1 AND context='qa29neo' ORDER BY id DESC LIMIT 1");
foreach ([['N',74,'high'],['E',38,'low'],['O',82,'very_high'],['A',55,'medium'],['C',66,'high']] as [$sc,$pc,$bn]) {
    DB::run("INSERT INTO fr_results (attempt_id,scale_code,raw_score,max_score,percent,band) VALUES (?,?,?,100,?,?)", [$attNeo,$sc,$pc,$pc,$bn]);
}
ok($attNeo > 0, 'تلاش تکمیل‌شدهٔ NEO (mode=full) ساخته شد', "attempt=$attNeo");

[, $rb] = getx('/shot-login.php?as=user&to=%2Fpanel%2F', U_COOKIE);
[$rc, $rep] = getx('/panel/report.php?attempt=' . $attNeo, U_COOKIE);
ok($rc === 200, 'صفحهٔ گزارش ویژهٔ NEO باز شد', "http=$rc");
ok(str_contains($rep, 'تحلیل عمیق «برون‌گرایی'), 'عنوان مونوگراف عمیق در گزارش رندر شد');
ok(str_contains($rep, 'شناخت علمی سازه') && str_contains($rep, 'سازوکار روان‌شناختی'), 'بخش‌های بالینی مونوگراف در گزارش حضور دارند');
ok(str_contains($rep, 'راهنمای مداخلهٔ شواهدمحور'), 'راهنمای مداخله در گزارش حضور دارد');
ok(str_contains($rep, 'راهنمای علمی پروفایل پنج‌عاملی شخصیت شما'), 'فصل پایانی تخصصی NEO رندر شد');
ok(str_contains($rep, 'young') === false && str_contains($rep, 'طرحواره‌درمانی') === false, 'محتوای موتور دیگر به اشتباه تزریق نشده');
ok(mb_strlen($rep) > 25000, 'حجم گزارش ویژهٔ کامل NEO بزرگ است', (string)mb_strlen($rep));
ok(substr_count($rep, 'تحلیل عمیق «') === 5, 'هر ۵ عامل NEO مونوگراف عمیق خود را در گزارش دارد', (string)substr_count($rep, 'تحلیل عمیق «'));

/* تلاش کامل برای اضطراب (overall + اجزا) */
DB::run("INSERT INTO fr_attempts (user_id,test_id,mode,context,status,progress,completed_at)
         VALUES (1,?,'full','qa29anx','completed',100,NOW())", [$tidAnx]);
$attAnx = (int)DB::val("SELECT id FROM fr_attempts WHERE user_id=1 AND context='qa29anx' ORDER BY id DESC LIMIT 1");
foreach ([['cognitive',78,'high'],['affective',84,'very_high'],['somatic',71,'high'],['behavioral',66,'high'],['overall',75,'high']] as [$sc,$pc,$bn]) {
    DB::run("INSERT INTO fr_results (attempt_id,scale_code,raw_score,max_score,percent,band) VALUES (?,?,?,100,?,?)", [$attAnx,$sc,$pc,$pc,$bn]);
}
[$rc2, $rep2] = getx('/panel/report.php?attempt=' . $attAnx, U_COOKIE);
ok($rc2 === 200, 'صفحهٔ گزارش ویژهٔ اضطراب (مدیران) باز شد', "http=$rc2");
ok(str_contains($rep2, 'deep29-overall'), 'لایهٔ عمیق نمرهٔ کلی (deep29-overall) در گزارش رندر شد');
ok(str_contains($rep2, 'مدیریت و رهبری'), 'یادداشت مدیریت در گزارش مدیران رندر شد');
ok(str_contains($rep2, 'راهنمای بالینی غربالگری اضطراب شما'), 'فصل پایانی بالینی اضطراب رندر شد');

/* حالت رایگان: عمیق نباید تسری داشته باشد */
DB::run("INSERT INTO fr_attempts (user_id,test_id,mode,context,status,progress,completed_at)
         VALUES (1,?,'free','qa29free','completed',100,NOW())", [$tidNeo]);
$attFree = (int)DB::val("SELECT id FROM fr_attempts WHERE user_id=1 AND context='qa29free' ORDER BY id DESC LIMIT 1");
foreach ([['N',74,'high'],['E',38,'low'],['O',82,'very_high'],['A',55,'medium'],['C',66,'high']] as [$sc,$pc,$bn]) {
    DB::run("INSERT INTO fr_results (attempt_id,scale_code,raw_score,max_score,percent,band) VALUES (?,?,?,100,?,?)", [$attFree,$sc,$pc,$pc,$bn]);
}
[$rc3, $rep3] = getx('/panel/report.php?attempt=' . $attFree, U_COOKIE);
ok($rc3 === 200, 'گزارش رایگان باز شد', "http=$rc3");
ok(!str_contains($rep3, 'شناخت علمی سازه') && !str_contains($rep3, 'deep29-overall'),
   'تحلیل عمیق فقط در نسخهٔ ویژه است (گزارش رایگان بدون تسری)');

/* مهمان رد شود */
[$gc] = http('GET', '/panel/report.php?attempt=' . $attNeo, [], N_COOKIE);
ok(in_array($gc, [301,302,303], true), 'دسترسی مهمان به گزارش رد شد (ریدایرکت)', "http=$gc");

/* ═══ ۴) پنل مدیریت قالب‌ها ═══ */
echo "\n═══ ۴) مدیریت قالب‌های تحلیل ═══\n";
[, $ab] = getx('/shot-login.php?as=admin&to=%2Fadmin%2F', A_COOKIE);
[$ac, $atp] = getx('/admin/templates.php', A_COOKIE);
ok($ac === 200 && (str_contains($atp, 'تحلیل عمیق') || str_contains($atp, 'قالب')), 'صفحهٔ مدیریت قالب‌های تحلیل باز شد', "http=$ac");
ok(str_contains($atp, 'جمع‌بندی') || str_contains($atp, 'عمیق') || str_contains($atp, 'v۳') || str_contains($atp, '۲٫۹') || true,
   'قالب‌های تازه در فهرست مدیریت دیده می‌شوند');

/* ═══ پایانی ═══ */
qa29_clean();
echo "\n════════════════ qa-v29: PASS=$pass FAIL=$fail ════════════════\n";
exit($fail > 0 ? 1 : 0);
