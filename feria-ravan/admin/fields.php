<?php
/** مدیریت فیلدهای داینامیک ثبت‌نام/پروفایل — افزودن/ویرایش/اجباری/حذف */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin','manager']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');
    if ($act === 'save') {
        $id = (int)post('id');
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)post('name')));
        $opts = null;
        if (post('type') === 'select') {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)post('options')))));
            $opts = json_encode($lines, JSON_UNESCAPED_UNICODE);
        }
        $data = [
            'label' => trim((string)post('label')), 'type' => post('type'),
            'options' => $opts, 'required' => post('required') ? 1 : 0,
            'active' => post('active') ? 1 : 0,
            'show_in_register' => post('show_in_register') ? 1 : 0,
            'show_in_profile' => post('show_in_profile') ? 1 : 0,
            'placeholder' => (string)post('placeholder') ?: null,
            'sort_order' => (int)en_num((string)post('sort_order', '0')),
        ];
        if ($id) { DB::update('profile_fields', $data, 'id=?', [$id]); }
        else {
            if ($name === '') { flash('نام ماشینی (لاتین) الزامی است.', 'err'); redirect(SITE_URL.'/admin/fields.php'); }
            $data['name'] = $name;
            DB::insert('profile_fields', $data);
        }
        log_activity('admin_field_save', 'field', $id ?: null);
        flash('فیلد ذخیره شد ✅');
    }
    if ($act === 'delete') {
        $f = DB::one('SELECT is_system FROM ' . DB::t('profile_fields') . ' WHERE id=?', [(int)post('id')]);
        if ($f && !$f['is_system']) {
            DB::run('DELETE FROM ' . DB::t('profile_fields') . ' WHERE id=?', [(int)post('id')]);
            flash('فیلد حذف شد.');
        } else flash('فیلد سیستمی قابل حذف نیست؛ می‌توانید غیرفعالش کنید.', 'warn');
    }
    redirect(SITE_URL . '/admin/fields.php');
}

$rows  = DB::all('SELECT * FROM ' . DB::t('profile_fields') . ' ORDER BY sort_order');
$editF = ($ef = (int)($_GET['edit_f'] ?? 0)) ? DB::one('SELECT * FROM ' . DB::t('profile_fields') . ' WHERE id=?', [$ef]) : null;
$types = ['text'=>'متن کوتاه','number'=>'عدد','select'=>'انتخاب از لیست','date'=>'تاریخ شمسی','textarea'=>'متن بلند','mobile'=>'موبایل','nationalcode'=>'کد ملی','checkbox'=>'چک‌باکس'];

admin_layout($adm, 'fields', 'فیلدهای فرم ثبت‌نام و پروفایل');
echo flash();
?>
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3><?= $editF ? 'ویرایش فیلد' : 'افزودن فیلد جدید' ?></h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $editF['id'] ?? 0 ?>">
      <div class="fr-field"><label>نام ماشینی (لاتین، فقط حروف و _)</label>
        <input class="fr-input" name="name" value="<?= e($editF['name'] ?? '') ?>" style="direction:ltr" <?= ($editF['is_system'] ?? 0) ? 'disabled' : 'required' ?>></div>
      <div class="fr-field"><label>عنوان نمایشی</label>
        <input class="fr-input" name="label" value="<?= e($editF['label'] ?? '') ?>" required></div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>نوع فیلد</label>
          <select class="fr-select" name="type">
            <?php foreach ($types as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editF['type'] ?? 'text')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fr-field"><label>ترتیب نمایش</label>
          <input class="fr-input" name="sort_order" value="<?= fa_num($editF['sort_order'] ?? 0) ?>"></div>
      </div>
      <div class="fr-field"><label>گزینه‌ها برای لیست (هر خط یک گزینه)</label>
        <textarea class="fr-textarea" name="options" rows="3"><?= e(implode("\n", (array)(json_decode($editF['options'] ?? '[]', true) ?: []))) ?></textarea></div>
      <div class="fr-field"><label>متن راهنما (placeholder)</label>
        <input class="fr-input" name="placeholder" value="<?= e($editF['placeholder'] ?? '') ?>"></div>
      <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px">
        <label class="tog"><input type="checkbox" name="required" <?= ($editF['required'] ?? 0)?'checked':'' ?>> اجباری</label>
        <label class="tog"><input type="checkbox" name="active" <?= ($editF['active'] ?? 1)?'checked':'' ?>> فعال</label>
        <label class="tog"><input type="checkbox" name="show_in_register" <?= ($editF['show_in_register'] ?? 1)?'checked':'' ?>> در ثبت‌نام</label>
        <label class="tog"><input type="checkbox" name="show_in_profile" <?= ($editF['show_in_profile'] ?? 1)?'checked':'' ?>> در پروفایل</label>
      </div>
      <button class="fr-btn sm">ذخیره فیلد</button>
      <?php if ($editF): ?><a class="fr-btn ghost sm" href="fields.php">لغو</a><?php endif; ?>
    </form>
  </div>

  <div class="fr-card" style="margin:0">
    <h3>فیلدهای موجود (<?= fa_num(count($rows)) ?>)</h3>
    <table class="fr-table">
      <thead><tr><th>عنوان</th><th>نوع</th><th>اجباری</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $f): ?>
      <tr style="<?= $f['active']?'':'opacity:.45' ?>">
        <td><b><?= e($f['label']) ?></b><br><small style="color:var(--muted);direction:ltr"><?= e($f['name']) ?><?= $f['is_system'] ? ' 🔒' : '' ?></small></td>
        <td style="font-size:12px"><?= e($types[$f['type']] ?? $f['type']) ?></td>
        <td><?= $f['required'] ? '<span class="fr-chip warn">اجباری</span>' : '—' ?></td>
        <td><?= $f['active'] ? '<span class="fr-chip ok">فعال</span>' : '<span class="fr-chip err">غیرفعال</span>' ?></td>
        <td class="actions">
          <a class="fr-btn sm ghost" href="?edit_f=<?= $f['id'] ?>">ویرایش</a>
          <?php if (!$f['is_system']): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')">
            <?= csrf_field() ?><input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= $f['id'] ?>">
            <button class="fr-btn sm danger">حذف</button></form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="hint" style="font-size:12px;color:var(--muted)">🔒 فیلدهای سیستمی را می‌توانید غیرفعال یا ویرایش کنید، اما حذف‌شدنی نیستند.</p>
  </div>
</div>
<?php admin_layout_end(); ?>
