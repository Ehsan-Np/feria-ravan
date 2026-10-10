<?php
/** مدیریت کدهای تخفیف — همه حالت‌ها */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');
    if ($act === 'save') {
        $id = (int)post('id');
        $data = [
            'code' => strtoupper(trim(en_num((string)post('code')))),
            'type' => post('type') === 'fixed' ? 'fixed' : 'percent',
            'value' => (int)en_num((string)post('value','0')),
            'max_amount' => (($v = en_num((string)post('max_amount'))) !== '' ? (int)$v : null),
            'min_order' => (($v = en_num((string)post('min_order'))) !== '' ? (int)$v : null),
            'test_id' => post('test_id') ? (int)post('test_id') : null,
            'event_id' => post('event_id') ? (int)post('event_id') : null,   // محدود به رویداد (کارگاه/دوره)
            'user_id' => post('user_id') ? (int)en_num(post('user_id')) : null,
            'usage_limit' => (($v = en_num((string)post('usage_limit'))) !== '' ? (int)$v : null),
            'per_user' => max(1, (int)en_num((string)post('per_user','1'))),
            'starts_at' => ($s = jalali_to_mysql((string)post('starts_at'))) ? $s . ' ' . fr_valid_time((string)post('starts_time'), '00:00') . ':00' : null,
            'expires_at' => ($s = jalali_to_mysql((string)post('expires_at'))) ? $s . ' ' . fr_valid_time((string)post('expires_time'), '23:59') . ':59' : null,
            'status' => post('status') === 'inactive' ? 'inactive' : 'active',
        ];
        if ($data['code'] === '') { flash('کد تخفیف الزامی است.', 'err'); redirect(SITE_URL.'/admin/discounts.php'); }
        // حوزهٔ تست روانشناسی و رویداد هم‌زمان مجاز نیست
        if ($data['test_id'] && $data['event_id']) { flash('کد را می‌توان فقط به تست روانشناسی «یا» رویداد محدود کرد، نه هر دو.', 'err'); redirect(SITE_URL.'/admin/discounts.php'); }
        if ($id) DB::update('discounts', $data, 'id=?', [$id]);
        else DB::insert('discounts', $data);
        log_activity('admin_discount_save', 'discount', $id ?: null);
        flash('کد تخفیف ذخیره شد ✅');
    }
    if ($act === 'delete') {
        DB::run('DELETE FROM ' . DB::t('discounts') . ' WHERE id=?', [(int)post('id')]);
        flash('کد حذف شد.');
    }
    redirect(SITE_URL . '/admin/discounts.php');
}

$rows = DB::all('SELECT d.*, t.title AS ttitle, e.title AS etitle FROM ' . DB::t('discounts') . ' d
                 LEFT JOIN ' . DB::t('tests') . ' t ON t.id=d.test_id
                 LEFT JOIN ' . DB::t('events') . ' e ON e.id=d.event_id
                 ORDER BY d.id DESC');
$tests  = DB::all('SELECT id,title FROM ' . DB::t('tests') . ' ORDER BY sort_order');
$events = DB::all('SELECT id,title,kind FROM ' . DB::t('events') . " WHERE status='active' ORDER BY id DESC");
$editD = ($ed = (int)($_GET['edit_d'] ?? 0)) ? DB::one('SELECT * FROM ' . DB::t('discounts') . ' WHERE id=?', [$ed]) : null;
$toJ = fn($dt) => $dt ? fa_num(jdate('Y/m/d', strtotime($dt))) : '';

admin_layout($adm, 'discounts', 'کدهای تخفیف');
echo flash();
?>
<div class="fr-card" style="margin:0">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
    <h3 style="margin:0"><?= $editD ? '✏️ ویرایش کد تخفیف «' . e($editD['code']) . '»' : '➕ ایجاد کد تخفیف جدید' ?></h3>
    <?php if ($editD): ?>
      <a class="fr-btn ghost sm" href="discounts.php">← بازگشت به فهرست کدهای تخفیف</a>
    <?php endif; ?>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $editD['id'] ?? 0 ?>">
    <div class="fr-grid cols-4" style="gap:12px">
      <div class="fr-field"><label>کد *</label>
        <input class="fr-input" name="code" value="<?= e($editD['code'] ?? '') ?>" style="direction:ltr;text-transform:uppercase" required></div>
      <div class="fr-field"><label>نوع</label>
        <select class="fr-select" name="type">
          <option value="percent" <?= ($editD['type'] ?? '')==='percent'?'selected':'' ?>>درصدی ٪</option>
          <option value="fixed" <?= ($editD['type'] ?? '')==='fixed'?'selected':'' ?>>مبلغ ثابت (تومان)</option>
        </select></div>
      <div class="fr-field"><label>مقدار *</label>
        <input class="fr-input" name="value" value="<?= fa_num($editD['value'] ?? '') ?>" required></div>
      <div class="fr-field"><label>سقف تخفیف (درصدی)</label>
        <input class="fr-input" name="max_amount" value="<?= fa_num($editD['max_amount'] ?? '') ?>" placeholder="مثل ۵۰٬۰۰۰"></div>
      <div class="fr-field"><label>حداقل مبلغ خرید</label>
        <input class="fr-input" name="min_order" value="<?= fa_num($editD['min_order'] ?? '') ?>"></div>
      <div class="fr-field"><label>محدود به تست روانشناسی</label>
        <select class="fr-select" name="test_id">
          <option value="">همه تست‌های روانشناسی</option>
          <?php foreach ($tests as $t): ?>
          <option value="<?= $t['id'] ?>" <?= ($editD['test_id'] ?? '')==$t['id']?'selected':'' ?>><?= e($t['title']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fr-field"><label>محدود به رویداد (کارگاه/دوره)</label>
        <select class="fr-select" name="event_id">
          <option value="">—</option>
          <?php foreach ($events as $ev): ?>
          <option value="<?= $ev['id'] ?>" <?= ($editD['event_id'] ?? '')==$ev['id']?'selected':'' ?>>
            <?= ['workshop'=>'🛠','course'=>'📚','consult'=>'🛋'][$ev['kind']] ?> <?= e($ev['title']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fr-field"><label>محدود به کاربر (شناسه)</label>
        <input class="fr-input" name="user_id" value="<?= fa_num($editD['user_id'] ?? '') ?>" style="direction:ltr"></div>
      <div class="fr-field"><label>سقف استفاده کل</label>
        <input class="fr-input" name="usage_limit" value="<?= fa_num($editD['usage_limit'] ?? '') ?>"></div>
      <div class="fr-field"><label>سقف هر کاربر</label>
        <input class="fr-input" name="per_user" value="<?= fa_num($editD['per_user'] ?? 1) ?>"></div>
      <div class="fr-field"><label>شروع (شمسی)</label>
        <input class="fr-input" name="starts_at" value="<?= $toJ($editD['starts_at'] ?? null) ?>" placeholder="۱۴۰۵/۰۵/۱۵"></div>
      <div class="fr-field"><label>پایان (شمسی)</label>
        <input class="fr-input" name="expires_at" value="<?= $toJ($editD['expires_at'] ?? null) ?>" placeholder="۱۴۰۵/۱۲/۲۹"></div>
      <div class="fr-field"><label>وضعیت</label>
        <select class="fr-select" name="status">
          <option value="active" <?= ($editD['status'] ?? 'active')==='active'?'selected':'' ?>>فعال</option>
          <option value="inactive" <?= ($editD['status'] ?? '')==='inactive'?'selected':'' ?>>غیرفعال</option>
        </select></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap">
      <button class="fr-btn sm" type="submit"><?= $editD ? 'ذخیره تغییرات کد' : 'ذخیره کد' ?></button>
      <?php if ($editD): ?>
        <a class="fr-btn ghost sm" href="discounts.php">انصراف و بازگشت</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php
// در حالت ویرایش کد تخفیف، فقط فرم همان کد نمایش داده می‌شود و جدول کدهای موجود مخفی می‌ماند
if ($editD) {
    admin_layout_end();
    exit;
}
?>

<div class="fr-card">
  <h3>کدهای موجود (<?= fa_num(count($rows)) ?>)</h3>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>کد</th><th>نوع/مقدار</th><th>محدودیت‌ها</th><th>استفاده</th><th>انقضا</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $d): ?>
    <tr style="<?= $d['status']==='active'?'':'opacity:.45' ?>">
      <td><b style="direction:ltr"><?= e($d['code']) ?></b></td>
      <td><?= $d['type']==='percent' ? fa_num($d['value']).'٪' : toman((int)$d['value']) ?></td>
      <td style="font-size:11.5px;line-height:1.8">
        <?= $d['ttitle'] ? '🎯 '.e($d['ttitle']).'<br>' : '' ?>
        <?= $d['etitle'] ? '🎪 '.e($d['etitle']).'<br>' : '' ?>
        <?= $d['user_id'] ? '👤 کاربر '.fa_num($d['user_id']) : '' ?>
        <?= $d['min_order'] ? '<br>حداقل: '.toman((int)$d['min_order']) : '' ?>
      </td>
      <td><?= fa_num($d['used_count']) ?><?= $d['usage_limit'] ? '/'.fa_num($d['usage_limit']) : '' ?></td>
      <td style="font-size:12px" dir="ltr"><?= $toJ($d['expires_at']) ?: '∞' ?></td>
      <td><?= $d['status']==='active' ? '<span class="fr-chip ok">فعال</span>' : '<span class="fr-chip err">خاموش</span>' ?></td>
      <td class="actions">
        <a class="fr-btn sm ghost" href="?edit_d=<?= $d['id'] ?>">ویرایش</a>
        <form method="post" style="display:inline" onsubmit="return confirm('حذف؟')">
          <?= csrf_field() ?><input type="hidden" name="act" value="delete">
          <input type="hidden" name="id" value="<?= $d['id'] ?>">
          <button class="fr-btn sm danger">حذف</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php admin_layout_end(); ?>
