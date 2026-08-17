<?php
declare(strict_types=1);
$APP = '/tmp/feria-smoke';
require $APP.'/config.php';
require FR_ROOT.'/includes/db.php';
require FR_ROOT.'/includes/functions.php';
require FR_ROOT.'/includes/security.php';
require FR_ROOT.'/core/Analyzer.php';
require FR_ROOT.'/includes/report.php';

$fail = 0;
function ok(bool $c, string $msg): void { global $fail; echo ($c?'✅':'❌')." $msg\n"; if(!$c) $fail++; }

$uid = (int)DB::val('SELECT id FROM '.DB::t('users').' WHERE mobile=?', ['09121112233']);
if (!$uid) {
    $uid = (int)DB::insert('users', ['first_name'=>'کاربر','last_name'=>'آزمون','mobile'=>'09121112233','password_hash'=>password_hash('x',PASSWORD_DEFAULT),'status'=>'active']);
}
ok($uid>0, "test user id=$uid");
$user = DB::one('SELECT * FROM '.DB::t('users').' WHERE id=?', [$uid]);

foreach (['gardner-mi'=>90,'eq-shutte'=>33] as $slug=>$expectedQ) {
    echo "\n─── $slug ───\n";
    $t = DB::one('SELECT * FROM '.DB::t('tests').' WHERE slug=?', [$slug]);
    ok((bool)$t && $t['engine']==='mslikert', "test exists, engine={$t['engine']}");
    $qs = DB::all('SELECT * FROM '.DB::t('questions').' WHERE test_id=? ORDER BY number', [$t['id']]);
    ok(count($qs)===$expectedQ, 'questions: '.count($qs)."/$expectedQ");

    $old = DB::all('SELECT id FROM '.DB::t('attempts').' WHERE user_id=? AND test_id=?', [$uid,$t['id']]);
    foreach ($old as $o) {
        DB::run('DELETE FROM '.DB::t('answers').' WHERE attempt_id=?',[$o['id']]);
        DB::run('DELETE FROM '.DB::t('results').' WHERE attempt_id=?',[$o['id']]);
        DB::run('DELETE FROM '.DB::t('reports').' WHERE attempt_id=?',[$o['id']]);
        DB::run('DELETE FROM '.DB::t('attempts').' WHERE id=?',[$o['id']]);
    }

    $aid = (int)DB::insert('attempts', ['user_id'=>$uid,'test_id'=>$t['id'],'mode'=>'full','status'=>'completed','progress'=>100]);
    foreach ($qs as $q) DB::insert('answers', ['attempt_id'=>$aid,'question_id'=>$q['id'],'value'=>'5']);
    Analyzer::scoreAttempt($aid);
    $res = DB::all('SELECT * FROM '.DB::t('results').' WHERE attempt_id=?', [$aid]);
    $scales  = array_values(array_filter($res, fn($r)=>$r['scale_code']!=='overall'));
    $overall = array_values(array_filter($res, fn($r)=>$r['scale_code']==='overall'));
    $expectScales = $slug==='gardner-mi' ? 9 : 4;
    ok(count($scales)===$expectScales, 'scale rows: '.count($scales)."/$expectScales");
    ok(count($overall)===1, 'overall row present');

    if ($slug==='gardner-mi') {
        $bad = array_filter($scales, fn($r)=>abs((float)$r['percent']-100)>0.01);
        ok(!$bad, 'all MI scales 100% with all-5 answers');
        ok(in_array('BODY',array_column($scales,'scale_code'),true), 'BODY scale present');
    } else {
        $keys = DB::all('SELECT k.*, q.number FROM '.DB::t('scoring_keys').' k JOIN '.DB::t('questions').' q ON q.id=k.question_id WHERE k.test_id=?', [$t['id']]);
        $exp=[];$mx=[];
        foreach($keys as $k){$c=$k['scale_code'];$v=$k['reverse']?1:5;$exp[$c]=($exp[$c]??0)+$v;$mx[$c]=($mx[$c]??0)+5;}
        $okq=true;
        foreach($scales as $r){$p=round($exp[$r['scale_code']]/$mx[$r['scale_code']]*100,2);if(abs($p-(float)$r['percent'])>0.01){$okq=false;echo"   mismatch {$r['scale_code']}: {$r['percent']} vs $p\n";}}
        ok($okq, 'reverse scoring correct ('.implode(',',array_map(fn($c)=>$c.'='.round($exp[$c]/$mx[$c]*100,1),array_keys($exp))).')');
        $revKeys = DB::all('SELECT q.number FROM '.DB::t('scoring_keys').' k JOIN '.DB::t('questions').' q ON q.id=k.question_id WHERE k.test_id=? AND k.`reverse`=1 ORDER BY q.number', [$t['id']]);
        ok(array_column($revKeys,'number')==[5,28,33], '3 reverse keys = items 5,28,33');
    }

    $at = DB::one('SELECT * FROM '.DB::t('attempts').' WHERE id=?', [$aid]);
    ok(str_contains((string)$at['context'],'|top:'), 'context has top/low markers');

    $html = ReportBuilder::build($at, $user, 'full');
    ok(mb_strlen($html)>3000, 'premium report rendered ('.mb_strlen($html).' chars)');
    ok(str_contains($html,'سه سؤال برای نشستن با خود'), 'closing card «سه سؤال برای نشستن با خود» present');
    ok(!str_contains($html,'فرمول نمرهٔ خام'), 'no abandoned draft leakage');
    if ($slug==='gardner-mi') {
        ok(str_contains($html,'نقشهٔ کلی')&&str_contains($html,'سرمایه‌های آماده'), 'MI families+top3 layers present');
        ok(str_contains($html,'گاردنر'), 'Gardner refs present');
    } else {
        ok(str_contains($html,'نمرهٔ کلی شما در برابر هنجارهای'), 'EQ norm card present');
        ok(str_contains($html,'توازن بین چهار عامل'), 'EQ balance card present');
        ok(str_contains($html,'نقطهٔ حساس'), 'EQ weak-spot present');
        ok(str_contains($html,'سه‌هفته‌ای')||str_contains($html,'تمرین'), 'EQ 3-week program present');
    }
}
echo "\n".($fail===0?'🎉 ALL CHECKS PASSED':"⚠️ $fail CHECK(S) FAILED")."\n";
