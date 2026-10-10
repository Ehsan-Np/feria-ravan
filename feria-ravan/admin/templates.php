<?php
/** قالب‌های تحلیل — نگارش دستی تحلیل‌ها برای هر مقیاس/باند/سطح */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin();

$testId = (int)($_GET['test'] ?? 0) ?: (int)(DB::val("SELECT id FROM " . DB::t('tests') . " ORDER BY sort_order LIMIT 1") ?: 0);
$test   = DB::one('SELECT * FROM ' . DB::t('tests') . ' WHERE id=?', [$testId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');
    if ($act === 'save') {
        $id = (int)post('id');
        $data = [
            'test_id'    => $testId,
            'scale_code' => post('scale_code') === '' ? null : trim((string)post('scale_code')),
            'band'       => in_array(post('band'), ['any','very_low','low','medium','high','very_high','overall'], true) ? post('band') : 'any',
            'level'      => post('level') === 'free' ? 'free' : 'full',
            'title'      => trim((string)post('title')),
            'body'       => (string)post('body'),
        ];
        if ($id) { DB::update('analysis_templates', $data, 'id=?', [$id]); }
        else     { DB::insert('analysis_templates', $data); }
        log_activity('admin_tpl_save', 'analysis_template', $id ?: null, ['test'=>$testId]);
        flash('تحلیل ذخیره شد ✅');
    }
    if ($act === 'delete') {
        DB::run('DELETE FROM ' . DB::t('analysis_templates') . ' WHERE id=?', [(int)post('id')]);
        flash('حذف شد.');
    }
    redirect(SITE_URL . "/admin/templates.php?test=$testId");
}

$tpls   = DB::all('SELECT a.*, s.title_fa FROM ' . DB::t('analysis_templates') . ' a
                   LEFT JOIN ' . DB::t('test_scales') . ' s ON s.test_id=a.test_id AND s.code=a.scale_code
                   WHERE a.test_id=? ORDER BY a.level, s.sort_order, a.band', [$testId]);
$scales = DB::all('SELECT code, title_fa FROM ' . DB::t('test_scales') . ' WHERE test_id=? ORDER BY sort_order', [$testId]);
$tests  = DB::all('SELECT id,title FROM ' . DB::t('tests') . ' ORDER BY sort_order');
$editT  = ($et = (int)($_GET['edit_t'] ?? 0)) ? DB::one('SELECT * FROM ' . DB::t('analysis_templates') . ' WHERE id=?', [$et]) : null;
$bandFa = ['any'=>'هر نمره (خلاصه کلی)','very_low'=>'خیلی پایین','low'=>'پایین','medium'=>'متوسط','high'=>'بالا','very_high'=>'خیلی بالا','overall'=>'تحلیل کلی تست روانشناسی'];

admin_layout($adm, 'templates', 'قالب‌های تحلیل: ' . ($test['title'] ?? ''));
echo flash();
?>
<div class="fr-card">
  <form method="get" style="display:flex;gap:10px;align-items:center">
    <label style="color:var(--muted);font-size:13px">تست روانشناسی:</label>
    <select class="fr-select" name="test" style="max-width:340px" onchange="this.form.submit()">
      <?php foreach ($tests as $t): ?><option value="<?= $t['id'] ?>" <?= $t['id']==$testId?'selected':'' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<!-- فرم افزودن/ویرایش تحلیل -->
<div class="fr-card" style="margin:0">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
    <h3 style="margin:0"><?= $editT ? '✏️ ویرایش قالب تحلیل (' . e($test['title']) . ')' : '➕ تحلیل جدید دستی (' . e($test['title']) . ')' ?></h3>
    <?php if ($editT): ?>
      <a class="fr-btn ghost sm" href="?test=<?= $testId ?>">← بازگشت به فهرست تحلیل‌ها</a>
    <?php endif; ?>
  </div>
  <form method="post" onsubmit="document.getElementById('hd-body').value=document.getElementById('rte-body').innerHTML">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $editT['id'] ?? 0 ?>">
    <div class="fr-grid cols-4" style="gap:12px">
      <div class="fr-field"><label>مقیاس</label>
        <select class="fr-select" name="scale_code">
          <option value="">— تحلیل کلی تست روانشناسی —</option>
          <?php foreach ($scales as $s): ?>
          <option value="<?= e($s['code']) ?>" <?= ($editT['scale_code'] ?? '')===$s['code']?'selected':'' ?>><?= e($s['code'].' — '.$s['title_fa']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fr-field"><label>باند نمره</label>
        <select class="fr-select" name="band">
          <?php foreach ($bandFa as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($editT['band'] ?? 'any')===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fr-field"><label>سطح گزارش</label>
        <select class="fr-select" name="level">
          <option value="free" <?= ($editT['level'] ?? '')==='free'?'selected':'' ?>>رایگان (خلاصه)</option>
          <option value="full" <?= ($editT['level'] ?? 'full')==='full'?'selected':'' ?>>ویژه (کامل)</option>
        </select></div>
      <div class="fr-field"><label>عنوان تحلیل</label>
        <input class="fr-input" name="title" value="<?= e($editT['title'] ?? '') ?>" required></div>
    </div>
    <div class="fr-field"><label>متن تحلیل (ویرایشگر کامل)</label>
      <div class="rte-toolbar" data-rte="body"></div>
      <div class="rte-area" id="rte-body" contenteditable="true"><?= $editT['body'] ?? '' ?></div>
      <input type="hidden" name="body" id="hd-body"></div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap">
      <button class="fr-btn sm" type="submit"><?= $editT ? 'ذخیره تغییرات تحلیل' : 'ذخیره تحلیل' ?></button>
      <?php if ($editT): ?>
        <a class="fr-btn ghost sm" href="?test=<?= $testId ?>">انصراف و بازگشت</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php
// در حالت ویرایش، فقط فرم همان قالب نمایش داده می‌شود و جدول تحلیل‌ها مخفی می‌ماند
if ($editT) {
    admin_layout_end();
    exit;
}
?>

<!-- فهرست تحلیل‌ها -->
<div class="fr-card">
  <h3>تحلیل‌های موجود (<?= fa_num(count($tpls)) ?>)</h3>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>مقیاس</th><th>باند</th><th>سطح</th><th>عنوان</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tpls as $tp): ?>
    <tr>
      <td><?= $tp['scale_code'] ? e($tp['scale_code'] . ' · ' . $tp['title_fa']) : '<span class="fr-chip warn">کلی</span>' ?></td>
      <td style="font-size:12px"><?= e($bandFa[$tp['band']] ?? $tp['band']) ?></td>
      <td><span class="fr-chip <?= $tp['level']==='full'?'purple':'info' ?>"><?= $tp['level']==='full'?'ویژه':'رایگان' ?></span></td>
      <td style="font-size:12.5px"><?= e($tp['title']) ?></td>
      <td class="actions">
        <a class="fr-btn sm ghost" href="?test=<?= $testId ?>&edit_t=<?= $tp['id'] ?>">ویرایش</a>
        <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')">
          <?= csrf_field() ?><input type="hidden" name="act" value="delete">
          <input type="hidden" name="id" value="<?= $tp['id'] ?>">
          <button class="fr-btn sm danger">حذف</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php admin_layout_end(); ?>
