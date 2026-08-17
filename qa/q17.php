<?php
declare(strict_types=1);
require '/tmp/feria-smoke/config.php';
require FR_ROOT.'/includes/db.php';
require FR_ROOT.'/includes/functions.php';
require FR_ROOT.'/includes/security.php';
require FR_ROOT.'/core/Analyzer.php';
require FR_ROOT.'/includes/report.php';
$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? '✅' : '❌')." $m\n"; if (!$c) $fail++; };

$uid  = (int)DB::val('SELECT id FROM '.DB::t('users').' WHERE mobile=?', ['09121112233']);
$user = DB::one('SELECT * FROM '.DB::t('users').' WHERE id=?', [$uid]);
$t = DB::one('SELECT * FROM '.DB::t('tests').' WHERE slug=?', ['learning-motivation']);
$ok((bool)$t && $t['engine']==='mslikert' && $t['audience']==='general' && (int)$t['price_full']===99000, 'ردیف آزمون: slug/engine/audience/price');
$ok((int)$t['question_count']===10, 'question_count=10');
$qs = DB::all('SELECT * FROM '.DB::t('questions').' WHERE test_id=? ORDER BY number', [$t['id']]);
$ok(count($qs)===10, '۱۰ سؤال در DB');
$keys = DB::all('SELECT k.*, q.number n FROM '.DB::t('scoring_keys').' k JOIN '.DB::t('questions').' q ON q.id=k.question_id WHERE k.test_id=?', [$t['id']]);
$rev = array_values(array_map(fn($k) => (int)$k['n'], array_filter($keys, fn($k) => (bool)$k['reverse'])));
$ok($rev === [8], 'سؤال ۸ معکوس ثبت شد ('.implode(',', $rev).')');
$labels = json_decode((string)$qs[0]['labels'], true);
$ok(is_array($labels) && count($labels)===5 && end($labels)==='کاملاً موافقم', 'برچسب‌های لیکرت ۵تایی سفارشی');

$aid = (int)DB::insert('attempts', ['user_id'=>$uid,'test_id'=>$t['id'],'mode'=>'full','status'=>'completed','progress'=>100]);
foreach ($qs as $q) DB::insert('answers', ['attempt_id'=>$aid,'question_id'=>$q['id'],'value'=>'4']);
Analyzer::scoreAttempt($aid);
$res = DB::all('SELECT * FROM '.DB::t('results').' WHERE attempt_id=?', [$aid]);
$sc  = array_column($res, 'percent', 'scale_code');
$extExp = round(((4*4)+(6-4))/25*100, 2);   // ۴ سؤال مستقیم (۲۰)؟ نه: ۵ کلید EXT — چهار مستقیم٫۴ + معکوسِ ۴→۲ = ۱۸ از ۲۵
$ok(isset($sc['INN'], $sc['EXT'], $sc['overall']), 'ردیف‌های INN/EXT/overall');
$ok(abs((float)$sc['INN']-80.0) < 0.01, "INN={$sc['INN']} (۵×۴/۵×۵ = ۸۰٪)");
$ok(abs((float)$sc['EXT']-$extExp) < 0.01, "EXT={$sc['EXT']} معکوس درست (۱۸/۲۵ = ۷۲٪)");
$ok(abs((float)$sc['overall']-round(((float)$sc['INN']+(float)$sc['EXT'])/2,2)) < 0.01, 'overall=میانگین دو مقیاس');

$at   = DB::one('SELECT * FROM '.DB::t('attempts').' WHERE id=?', [$aid]);
$ok(str_contains((string)$at['context'], '|top:'), 'کانتکست top/low');
$html = ReportBuilder::build($at, $user, 'full');
$ok(str_contains($html, 'تحلیل عمیق «انگیزهٔ درون‌ساخت'), 'متن تحلیل عمیق INN در گزارش ویژه (۲٫۹)');
$ok(str_contains($html, 'تحلیل عمیق «انگیزهٔ برون‌ساخت'), 'متن تحلیل عمیق EXT در گزارش ویژه (۲٫۹)');
$ok(str_contains($html, 'جمع‌بندی تخصصی انگیزهٔ یادگیری'), 'فصل پایانی تخصصی (انتقال به قالب عمیق ۲٫۹)');
$ok(str_contains($html, 'سه سؤال برای نشستن با خود'), 'کارت پایانی مشترک');
$free = ReportBuilder::build($at, $user, 'free');
$ok(mb_strlen($free)>400 && str_contains($free, 'نگاه کوتاه به «انگیزهٔ درون‌ساخت»'), 'گزارش رایگان با تیزر مقیاس');
echo ($fail ? "⚠️ $fail خطا" : '🎉 نصب‌گر نمونه — چرخهٔ کامل موفق'), "\n";
