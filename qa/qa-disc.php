<?php
declare(strict_types=1);
$APP = '/tmp/feria-smoke';
require $APP.'/config.php';
require FR_ROOT.'/includes/db.php';
require FR_ROOT.'/includes/functions.php';
require FR_ROOT.'/includes/security.php';
require FR_ROOT.'/core/Analyzer.php';
require FR_ROOT.'/includes/report.php';

$uid = (int)DB::val('SELECT id FROM '.DB::t('users').' WHERE mobile=?', ['09121112233']);
$user = DB::one('SELECT * FROM '.DB::t('users').' WHERE id=?', [$uid]);
$t = DB::one('SELECT * FROM '.DB::t('tests').' WHERE slug=?', ['disc-family']);
$qs = DB::all('SELECT * FROM '.DB::t('questions').' WHERE test_id=? ORDER BY number', [$t['id']]);
$old = DB::all('SELECT id FROM '.DB::t('attempts').' WHERE user_id=? AND test_id=?', [$uid,$t['id']]);
foreach ($old as $o) {
    DB::run('DELETE FROM '.DB::t('answers').' WHERE attempt_id=?',[$o['id']]);
    DB::run('DELETE FROM '.DB::t('results').' WHERE attempt_id=?',[$o['id']]);
    DB::run('DELETE FROM '.DB::t('attempts').' WHERE id=?',[$o['id']]);
}
$aid = (int)DB::insert('attempts', ['user_id'=>$uid,'test_id'=>$t['id'],'mode'=>'full','status'=>'completed','progress'=>100]);
foreach ($qs as $q) { DB::insert('answers', ['attempt_id'=>$aid,'question_id'=>$q['id'],'most_idx'=>0,'least_idx'=>1]); }
Analyzer::scoreAttempt($aid);
$at = DB::one('SELECT * FROM '.DB::t('attempts').' WHERE id=?', [$aid]);
$html = ReportBuilder::build($at, $user, 'full');
$fail = 0;
$checks = [
    'واکاوی ترکیبی' => 'deep synthesis',
    'ترکیب دو بُعد غالب' => 'DISC combo reading',
    'در بافت زندگی و کار ایران' => 'Iran context',
    'سه سؤال برای نشستن با خود' => 'closing card',
];
foreach ($checks as $needle=>$label) {
    $c = str_contains($html, $needle);
    echo ($c?'✅':'❌')." DISC: $label present\n"; if(!$c) $fail++;
}
echo $fail ? "FAIL\n" : "🎉 DISC REGRESSION PASSED\n";
