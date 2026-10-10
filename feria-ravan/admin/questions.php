<?php
/** مدیریت سوالات — مشاهده، افزودن، ویرایش، حذف، فعال/غیرفعال */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin();

$testId = (int)($_GET['test'] ?? 0) ?: (int)(DB::val("SELECT id FROM " . DB::t('tests') . " WHERE status='active' ORDER BY sort_order LIMIT 1") ?: 0);
$test = DB::one('SELECT * FROM ' . DB::t('tests') . ' WHERE id=?', [$testId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $test) {
    csrf_guard();
    $act = (string)post('act');
    $tid = (int)$test['id'];

    if ($act === 'save') {
        $qid = (int)post('qid');
        $data = [
            'text'      => trim((string)post('text')),
            'format'    => post('format') === 'mostleast' ? 'mostleast' : 'likert',
            'scale_min' => 1,
            'scale_max' => post('format') === 'mostleast' ? 3 : (int)post('scale_max', 6),
            'active'    => post('active') ? 1 : 0,
        ];
        if ($data['format'] === 'mostleast') {
            $opts = array_values(array_filter(array_map('trim', [post('o1'),post('o2'),post('o3'),post('o4')])));
            $letters = [post('l1'),post('l2'),post('l3'),post('l4')];
            if (count($opts) !== 4 || count(array_unique($letters)) !== 4) {
                flash('برای قالب DISC دقیقاً ۴ گزینه و ۴ حرف متفاوت (D/I/S/C) لازم است.', 'err');
                redirect(SITE_URL . "/admin/questions.php?test=$tid");
            }
            $data['options'] = json_encode($opts, JSON_UNESCAPED_UNICODE);
        } else {
            $data['options'] = null;
        }
        $scale = trim((string)post('scale'));
        $reverse = post('reverse') ? 1 : 0;

        if ($qid) {
            DB::update('questions', $data, 'id=? AND test_id=?', [$qid, $tid]);
            DB::run('DELETE FROM ' . DB::t('scoring_keys') . ' WHERE question_id=?', [$qid]);
        } else {
            $num = (int)DB::val('SELECT COALESCE(MAX(number),0)+1 FROM ' . DB::t('questions') . ' WHERE test_id=?', [$tid]);
            $data['number'] = $num;
            $data['test_id'] = $tid;
            $qid = DB::insert('questions', $data);
            DB::run('UPDATE ' . DB::t('tests') . ' SET question_count=(SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1) WHERE id=?', [$tid, $tid]);
        }
        // کلید نمره‌گذاری
        if ($data['format'] === 'mostleast') {
            DB::insert('scoring_keys', ['test_id'=>$tid,'question_id'=>$qid,'scale_code'=>'D',
                'meta'=>json_encode(['letters'=>$letters], JSON_UNESCAPED_UNICODE)]);
        } else {
            DB::insert('scoring_keys', ['test_id'=>$tid,'question_id'=>$qid,'scale_code'=>$scale,'reverse'=>$reverse]);
        }
        log_activity('admin_q_save', 'question', $qid, ['test'=>$tid]);
        flash('سؤال ذخیره شد ✅');
    }

    if ($act === 'delete') {
        $qid = (int)post('qid');
        DB::run('DELETE FROM ' . DB::t('scoring_keys') . ' WHERE question_id=?', [$qid]);
        DB::run('DELETE FROM ' . DB::t('questions') . ' WHERE id=? AND test_id=?', [$qid, $tid]);
        DB::run('UPDATE ' . DB::t('tests') . ' SET question_count=(SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1) WHERE id=?', [$tid, $tid]);
        log_activity('admin_q_delete', 'question', $qid);
        flash('سؤال حذف شد.');
    }

    redirect(SITE_URL . "/admin/questions.php?test=$tid" . (isset($_GET['page']) ? '&page='.$_GET['page'] : ''));
}

if (!$test) { redirect(SITE_URL . '/admin/tests.php'); }

/* صفحه‌بندی */
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 25;
$offset = ($page - 1) * $per;
$qs = DB::all('SELECT q.*, (SELECT GROUP_CONCAT(sk.scale_code,IF(sk.reverse=1,"(معکوس)","")) FROM ' . DB::t('scoring_keys') . ' sk WHERE sk.question_id=q.id) AS keysinfo
               FROM ' . DB::t('questions') . ' q WHERE q.test_id=? ORDER BY q.number LIMIT ? OFFSET ?', [$testId, $per, $offset]);
$totalQ = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=?', [$testId]);
$pages  = max(1, (int)ceil($totalQ / $per));
$scales = DB::all('SELECT code, title_fa FROM ' . DB::t('test_scales') . ' WHERE test_id=? ORDER BY sort_order', [$testId]);
$editQ  = ($eq = (int)($_GET['edit_q'] ?? 0)) ? DB::one('SELECT * FROM ' . DB::t('questions') . ' WHERE id=? AND test_id=?', [$eq, $testId]) : null;
$tests  = DB::all('SELECT id,title FROM ' . DB::t('tests') . ' ORDER BY sort_order');

admin_layout($adm, 'questions', 'سوالات: ' . $test['title']);
echo flash();
?>
<div class="fr-card">
  <form method="get" style="display:flex;gap:10px;align-items:center">
    <label style="color:var(--muted);font-size:13px">تست روانشناسی:</label>
    <select class="fr-select" name="test" style="max-width:340px" onchange="this.form.submit()">
      <?php foreach ($tests as $t): ?>
        <option value="<?= $t['id'] ?>" <?= $t['id']==$testId?'selected':'' ?>><?= e($t['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <span class="fr-chip info"><?= fa_num($totalQ) ?> سؤال</span>
  </form>
</div>

<!-- فرم افزودن/ویرایش -->
<div class="fr-card" style="margin:0">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
    <h3 style="margin:0"><?= $editQ ? '✏️ ویرایش سؤال شماره ' . fa_num($editQ['number']) . ' (' . e($test['title']) . ')' : '➕ افزودن سؤال جدید به ' . e($test['title']) ?></h3>
    <?php if ($editQ): ?>
      <a class="fr-btn ghost sm" href="?test=<?= $testId ?>&page=<?= $page ?>">← بازگشت به فهرست سوالات</a>
    <?php endif; ?>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="qid" value="<?= $editQ['id'] ?? 0 ?>">
    <div class="fr-field"><label>متن سؤال</label>
      <textarea class="fr-textarea" name="text" rows="2" required><?= e($editQ['text'] ?? '') ?></textarea></div>

    <div style="display:flex;gap:26px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
      <label class="tog"><input type="radio" name="format" value="likert" <?= (!$editQ || $editQ['format']==='likert')?'checked':'' ?> onchange="toggleFmt()"> لیکرت</label>
      <label class="tog"><input type="radio" name="format" value="mostleast" <?= ($editQ && $editQ['format']==='mostleast')?'checked':'' ?> onchange="toggleFmt()"> چهاروصفی DISC</label>
      <span id="fmtLikert">
        <select class="fr-select" name="scale_max" style="width:auto;display:inline-block">
          <option value="6" <?= ($editQ['scale_max'] ?? 6)==6?'selected':'' ?>>۶ درجه‌ای (یانگ)</option>
          <option value="5" <?= ($editQ['scale_max'] ?? 6)==5?'selected':'' ?>>۵ درجه‌ای (NEO)</option>
        </select>
      </span>
      <span id="fmtScale">
        مقیاس: <select class="fr-select" name="scale" style="width:auto;display:inline-block">
        <?php foreach ($scales as $s): ?><option value="<?= e($s['code']) ?>"><?= e($s['code'] . ' — ' . $s['title_fa']) ?></option><?php endforeach; ?>
        </select>
        &nbsp;<label class="tog"><input type="checkbox" name="reverse"> آیتم معکوس</label>
      </span>
      <label class="tog"><input type="checkbox" name="active" <?= (!$editQ || $editQ['active'])?'checked':'' ?>> فعال</label>
    </div>

    <div id="discBox" style="display:none;border:1px dashed var(--line);border-radius:12px;padding:14px;margin-bottom:12px">
      <p style="font-size:12.5px;color:var(--muted)">۴ توصیف + حرف متناظر (D/I/S/C — ترتیب دلخواه):</p>
      <div class="fr-grid cols-4" style="gap:10px">
      <?php $oldOpts = $editQ && $editQ['options'] ? (json_decode($editQ['options'], true) ?: []) : ['','','',''];
            $oldL = ['D','I','S','C'];
        if ($editQ && $editQ['format']==='mostleast') {
            $k = DB::one('SELECT meta FROM ' . DB::t('scoring_keys') . ' WHERE question_id=? LIMIT 1', [$editQ['id']]);
            if ($k) $oldL = (json_decode($k['meta'], true)['letters'] ?? $oldL);
        }
        for ($i=1;$i<=4;$i++): ?>
        <div>
          <input class="fr-input" name="o<?= $i ?>" placeholder="توصیف <?= fa_num($i) ?>" value="<?= e($oldOpts[$i-1] ?? '') ?>">
          <select class="fr-select" name="l<?= $i ?>" style="margin-top:6px">
            <?php foreach (['D','I','S','C'] as $L): ?>
            <option value="<?= $L ?>" <?= ($oldL[$i-1] ?? '')===$L?'selected':'' ?>><?= $L ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endfor; ?>
      </div>
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap">
      <button class="fr-btn sm" type="submit"><?= $editQ ? 'ذخیره تغییرات سؤال' : 'افزودن سؤال' ?></button>
      <?php if ($editQ): ?>
        <a class="fr-btn ghost sm" href="?test=<?= $testId ?>&page=<?= $page ?>">انصراف و بازگشت</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<script>
function toggleFmt(){
  const disc = document.querySelector('input[name=format]:checked').value==='mostleast';
  document.getElementById('discBox').style.display = disc?'block':'none';
  document.getElementById('fmtLikert').style.display = disc?'none':'inline';
  document.getElementById('fmtScale').style.display = disc?'none':'inline';
}
document.addEventListener('DOMContentLoaded', toggleFmt);
</script>
<?php
// در حالت ویرایش سؤال، فقط فرم همان سؤال نمایش داده می‌شود و فهرست سوالات زیر آن مخفی می‌ماند
if ($editQ) {
    admin_layout_end();
    exit;
}
?>

<!-- فهرست سوالات -->
<div class="fr-card">
  <h3>فهرست سوالات (<?= fa_num($totalQ) ?>)</h3>
  <table class="fr-table">
    <thead><tr><th>#</th><th>متن</th><th>قالب</th><th>کلید</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($qs as $q): ?>
    <tr style="<?= $q['active'] ? '' : 'opacity:.5' ?>">
      <td><?= fa_num($q['number']) ?></td>
      <td style="max-width:420px;font-size:12.5px"><?= e(mb_strimwidth($q['text'],0,110,'…')) ?></td>
      <td><span class="fr-chip <?= $q['format']==='mostleast'?'warn':'info' ?>"><?= $q['format']==='mostleast'?'DISC':'لیکرت '.$q['scale_max'] ?></span></td>
      <td style="font-size:12px"><?= e($q['keysinfo'] ?? '—') ?></td>
      <td><?= $q['active'] ? '<span class="fr-chip ok">فعال</span>' : 'غیرفعال' ?></td>
      <td class="actions">
        <a class="fr-btn sm ghost" href="?test=<?= $testId ?>&edit_q=<?= $q['id'] ?>&page=<?= $page ?>">ویرایش</a>
        <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')">
          <?= csrf_field() ?><input type="hidden" name="act" value="delete">
          <input type="hidden" name="qid" value="<?= $q['id'] ?>">
          <button class="fr-btn sm danger">حذف</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:14px">
    <?php for ($p=1;$p<=$pages;$p++): ?>
      <a class="fr-btn sm <?= $p===$page?'':'ghost' ?>" href="?test=<?= $testId ?>&page=<?= $p ?>"><?= fa_num($p) ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_layout_end(); ?>
