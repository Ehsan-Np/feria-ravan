<?php
/** مدیریت بنرهای تبلیغاتی */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin','manager']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');
    if ($act === 'save') {
        $id = (int)post('id');
        $image = (string)post('image');
        // آپلود تصویر (در صورت انتخاب)
        if (!empty($_FILES['file']['tmp_name'])) {
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
            $mime = mime_content_type($_FILES['file']['tmp_name']);
            if (isset($allowed[$mime]) && $_FILES['file']['size'] < 2_000_000) {
                $fn = 'banner-' . time() . '.' . $allowed[$mime];
                $dst = FR_ROOT . '/assets/uploads/' . $fn;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $dst)) {
                    $image = SITE_URL . '/assets/uploads/' . $fn;
                }
            }
        }
        $data = [
            'title' => trim((string)post('title')), 'image' => $image,
            'link' => (string)post('link') ?: null,
            'placement' => in_array(post('placement'), ['dashboard','test_form','sidebar','report'], true) ? post('placement') : 'dashboard',
            'active' => post('active') ? 1 : 0, 'sort_order' => (int)en_num((string)post('sort_order','0')),
        ];
        if ($id) DB::update('banners', $data, 'id=?', [$id]); else DB::insert('banners', $data);
        log_activity('admin_banner_save', 'banner', $id ?: null);
        flash('بنر ذخیره شد ✅');
    }
    if ($act === 'delete') {
        DB::run('DELETE FROM ' . DB::t('banners') . ' WHERE id=?', [(int)post('id')]);
        flash('بنر حذف شد.');
    }
    redirect(SITE_URL . '/admin/banners.php');
}

$rows = DB::all('SELECT * FROM ' . DB::t('banners') . ' ORDER BY placement, sort_order');
$editB = ($eb = (int)($_GET['edit_b'] ?? 0)) ? DB::one('SELECT * FROM ' . DB::t('banners') . ' WHERE id=?', [$eb]) : null;
$places = ['dashboard'=>'داشبورد کاربر','test_form'=>'هنگام فرم تست روانشناسی','sidebar'=>'سایدبار پنل','report'=>'اطراف گزارش'];

admin_layout($adm, 'banners', 'بنرهای تبلیغاتی');
echo flash();
?>
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3><?= $editB ? 'ویرایش بنر' : 'افزودن بنر' ?></h3>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $editB['id'] ?? 0 ?>">
      <div class="fr-field"><label>عنوان بنر</label>
        <input class="fr-input" name="title" value="<?= e($editB['title'] ?? '') ?>" required></div>
      <div class="fr-field"><label>آدرس تصویر (یا آپلود)</label>
        <input class="fr-input" name="image" value="<?= e($editB['image'] ?? '') ?>" style="direction:ltr">
        <input type="file" name="file" accept="image/*" style="margin-top:8px;font-size:12px"></div>
      <div class="fr-field"><label>لینک مقصد (اختیاری)</label>
        <input class="fr-input" name="link" value="<?= e($editB['link'] ?? '') ?>" style="direction:ltr"></div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>محل نمایش</label>
          <select class="fr-select" name="placement">
            <?php foreach ($places as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editB['placement'] ?? 'dashboard')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fr-field"><label>ترتیب</label>
          <input class="fr-input" name="sort_order" value="<?= fa_num($editB['sort_order'] ?? 0) ?>"></div>
      </div>
      <label class="tog"><input type="checkbox" name="active" <?= ($editB['active'] ?? 1)?'checked':'' ?>> فعال</label>
      <br><button class="fr-btn sm" style="margin-top:12px">ذخیره بنر</button>
      <?php if ($editB): ?><a class="fr-btn ghost sm" href="banners.php">لغو</a><?php endif; ?>
    </form>
  </div>
  <div class="fr-card" style="margin:0">
    <h3>بنرهای موجود</h3>
    <table class="fr-table">
      <thead><tr><th>تصویر</th><th>عنوان</th><th>محل</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $b): ?>
      <tr style="<?= $b['active']?'':'opacity:.45' ?>">
        <td><img src="<?= e($b['image']) ?>" style="width:64px;height:40px;object-fit:cover;border-radius:8px"></td>
        <td style="font-size:12.5px"><b><?= e($b['title']) ?></b></td>
        <td style="font-size:12px"><?= e($places[$b['placement']] ?? $b['placement']) ?></td>
        <td class="actions">
          <a class="fr-btn sm ghost" href="?edit_b=<?= $b['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف؟')">
            <?= csrf_field() ?><input type="hidden" name="act" value="delete">
            <input type="hidden" name="id" value="<?= $b['id'] ?>">
            <button class="fr-btn sm danger">حذف</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_layout_end(); ?>
