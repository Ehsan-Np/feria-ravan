<?php
declare(strict_types=1);
/* QA v2.6 — گزارش عمیق شش‌فصلی DISC (۳۰+ صفحه، تحلیل حرفه‌ای) */
$APP = '/tmp/feria-smoke';
require $APP.'/config.php';
require FR_ROOT.'/includes/db.php';
require FR_ROOT.'/includes/functions.php';
require FR_ROOT.'/includes/security.php';
require FR_ROOT.'/core/Analyzer.php';
require FR_ROOT.'/includes/report.php';

$fail = 0; $pass = 0;
function ok(bool $c, string $label): void {
    global $fail, $pass;
    echo ($c ? '✅' : '❌') . " $label\n";
    $c ? $pass++ : $fail++;
}

$uid = (int)DB::val('SELECT id FROM '.DB::t('users')." WHERE mobile='09121112233'");
$user = DB::one('SELECT * FROM '.DB::t('users').' WHERE id=?', [$uid]);
$t = DB::one('SELECT * FROM '.DB::t('tests')." WHERE slug='disc-family'");
$qs = DB::all('SELECT * FROM '.DB::t('questions').' WHERE test_id=? ORDER BY number', [$t['id']]);
$lettersMap = [];
foreach (DB::all('SELECT question_id, meta FROM '.DB::t('scoring_keys').' WHERE test_id=?', [$t['id']]) as $k)
    $lettersMap[$k['question_id']] = json_decode($k['meta'] ?? '{}', true)['letters'] ?? null;

// پاک‌سازی تلاش‌های قبلی
foreach (DB::all('SELECT id FROM '.DB::t('attempts').' WHERE user_id=? AND test_id=?', [$uid, $t['id']]) as $o) {
    DB::run('DELETE FROM '.DB::t('answers').' WHERE attempt_id=?', [$o['id']]);
    DB::run('DELETE FROM '.DB::t('results').' WHERE attempt_id=?', [$o['id']]);
    DB::run('DELETE FROM '.DB::t('attempts').' WHERE id=?', [$o['id']]);
}

// تلاش مصنوعی DC (غلبه‌گرایی + وظیفه‌شناسی برتریابنده)
$aid = (int)DB::insert('attempts', ['user_id'=>$uid,'test_id'=>$t['id'],'mode'=>'full','status'=>'completed','progress'=>100]);
foreach ($qs as $i => $q) {
    $letters = $lettersMap[$q['id']] ?? null;
    if (!$letters || count($letters) < 2) continue;
    $mi = array_search(($i % 2 === 0) ? 'C' : 'D', $letters, true);
    $li = array_search(($i % 2 === 0) ? 'I' : 'S', $letters, true);
    if ($mi === false) $mi = 0;
    if ($li === false || $li === $mi) $li = 1;
    DB::insert('answers', ['attempt_id'=>$aid,'question_id'=>$q['id'],'most_idx'=>$mi,'least_idx'=>$li]);
}
Analyzer::scoreAttempt($aid);
$at = DB::one('SELECT * FROM '.DB::t('attempts').' WHERE id=?', [$aid]);

$ver = (string)(defined('FR_VERSION') ? FR_VERSION : 'dev');
echo "ℹ️ FR_VERSION=$ver attempt=$aid context={$at['context']}\n";

/* ── تشخیص الگو و کد چهاررقمی ─────────────────────────────── */
$graph = Analyzer::discGraphData($aid);
[$pat, $code4] = ReportBuilder::discPattern($at, $graph['combined']);
ok($pat === 'DC', "الگوی صحیح تشخیص داده شد (DC) — [$pat]");
ok(is_string($code4) && preg_match('/^[1-7]{4}$/', $code4) === 1, "کد چهاررقمی سگمنت ۱–۷ معتبر است [$code4]");
ok(str_contains((string)$at['context'], 'pattern:DC'), 'context ذخیره‌شده الگوی درست را نگه می‌دارد');

/* ── خوددرمانی تلاش قدیمی با context اشتباه ───────────────── */
$legacy = (int)DB::insert('attempts', ['user_id'=>$uid,'test_id'=>$t['id'],'mode'=>'full','status'=>'completed','progress'=>100,'context'=>'base|pattern:DI|code:9999']);
foreach ($qs as $q) {
    $letters = $lettersMap[$q['id']] ?? null;
    if (!$letters || count($letters) < 2) continue;
    $mi = array_search('C', $letters, true); $li = array_search('I', $letters, true);
    if ($mi === false) $mi = 0; if ($li === false || $li === $mi) $li = 1;
    DB::insert('answers', ['attempt_id'=>$legacy,'question_id'=>$q['id'],'most_idx'=>$mi,'least_idx'=>$li]);
}
Analyzer::scoreAttempt($legacy);
$atL = DB::one('SELECT * FROM '.DB::t('attempts').' WHERE id=?', [$legacy]);
DB::update('attempts', ['context' => 'base|pattern:DI|code:9999'], 'id=?', [$legacy]);
$gL = Analyzer::discGraphData($legacy);
[$patL] = ReportBuilder::discPattern($atL, $gL['combined']);
ok($patL === 'C' || $patL === 'CD' || $patL === 'DC', "خوددرمانی الگوی legacy (DI→ $patL)");

/* ── رندر کامل (صفحه) ─────────────────────────────────────── */
$html = ReportBuilder::build($at, $user, 'full');
$must = [
    'فصل اول' => 'سربرگ فصل اول',
    'فصل دوم' => 'سربرگ فصل دوم',
    'فصل سوم' => 'سربرگ فصل سوم',
    'فصل چهارم' => 'سربرگ فصل چهارم',
    'فصل پنجم' => 'سربرگ فصل پنجم',
    'فصل ششم' => 'سربرگ فصل ششم',
    'فهرست فصول این گزارش' => 'فهرست فصول',
    'نقشهٔ یک‌نگاه — خلاصهٔ اجرایی پروفایل شما' => 'خلاصهٔ اجرایی',
    'در میان ۱۵ الگوی متداول جهانی' => 'نقشهٔ ۱۵ الگوی جهانی',
    'شاخص ابعاد' => 'سربرگ شاخص ابعاد',
    'ریسک‌پذیر' => 'نام شاخص ریسک‌پذیر',
    'شاخص تطابق دو نمودار' => 'شاخص تطابق دو نمودار',
    'حرکت هر بُعد بین دو نمودار' => 'حرکت ابعاد',
    'شکلِ غالب تصمیم‌گیری شما' => 'سبک تصمیم‌گیری',
    'تعدیل و تقویت لایه‌های ضعیف' => 'تعدیل لایه‌ها',
    'برنامهٔ هشت‌هفتهٔ تقویت' => 'برنامهٔ هشت‌هفته',
    'سبک فرعی Everything DiSC' => 'سبک فرعی Everything DiSC',
    'اولویت‌های کاری هر بُعد' => 'اولویت‌های Everything',
    'دوازده سبک فرعی' => '۱۲ سبک فرعی',
    'برش‌های تحلیلی عمیق' => 'برش‌های عمیق هر بُعد',
    'کارت سبک این بُعد' => 'کارت سبک',
    'سه سناریوی تصمیم‌گیری' => 'سه سناریوی تصمیم',
    'جهان‌بینی رفتاری شما' => 'جهان‌بینی الگو',
    'عادت‌های کاری عمیق' => 'عادت‌های کاری عمیق',
    'نقشهٔ کامل تعامل عمیق بین الگوها' => 'نقشهٔ تعامل عمیق',
    'واکاوی ترکیبی' => 'واکاوی ترکیبی (deep synthesis حفظ شد)',
    'در بافت زندگی و کار ایران' => 'بافت ایران (حفظ شد)',
    'سه سؤال برای نشستن با خود' => 'کارت پایانی (حفظ شد)',
];
foreach ($must as $needle => $label) ok(str_contains($html, $needle), "صفحه: $label");

/* ── رندر حالت چاپ (A4) ───────────────────────────────────── */
$prn = ReportBuilder::build($at, $user, 'full', ['print' => true]);
ok(substr_count($prn, 'fr-chapter-head') >= 6, 'چاپ: شش سربرگ فصل');
ok(str_contains($prn, 'نمودار خطی نتایج DISC'), 'چاپ: کارت نمودار خطی نتایج');
ok(str_contains($prn, 'سگمنت') || str_contains($prn, 'شناسهٔ عددی الگوی'), 'چاپ: سگمنت‌ها/شناسهٔ عددی');
ok(!str_contains($prn, 'data-chart='), 'چاپ: بدون کنواس تعاملی (جایگزین جدول)');

/* ── نسخهٔ رایگان: تیزر بماند و فصل‌ها نباشند ─────────────── */
$free = (int)DB::insert('attempts', ['user_id'=>$uid,'test_id'=>$t['id'],'mode'=>'free','status'=>'completed','progress'=>100]);
foreach ($qs as $q) {
    $letters = $lettersMap[$q['id']] ?? null;
    if (!$letters || count($letters) < 2) continue;
    DB::insert('answers', ['attempt_id'=>$free,'question_id'=>$q['id'],'most_idx'=>0,'least_idx'=>1]);
}
Analyzer::scoreAttempt($free);
$freeAt = DB::one('SELECT * FROM '.DB::t('attempts').' WHERE id=?', [$free]);
$fh = ReportBuilder::build($freeAt, $user, 'free');
ok(str_contains($fh, 'شش‌فصلی'), 'رایگان: تیزر شش‌فصلی');
ok(str_contains($fh, 'بیش از ۳۰ صفحه'), 'رایگان: اشاره به ۳۰+ صفحه');
ok(!str_contains($fh, 'نقشهٔ یک‌نگاه'), 'رایگان: بدون خلاصهٔ اجرایی (نسخهٔ ویژه)');
ok(!str_contains($fh, 'فصل اول'), 'رایگان: بدون ساختار فصلی');

echo "\n════════ qa-v26: " . ($fail ? "❌ $fail FAILED" : "🎉 ALL PASSED ($pass checks)") . " ════════\n";
exit($fail ? 1 : 0);
