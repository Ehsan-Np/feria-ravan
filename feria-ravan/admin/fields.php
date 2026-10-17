<?php
/**
 * مدیریت فیلدهای داینامیک ثبت‌نام و پروفایل کاربران — فریا روان (نسخه ۴٫۴٫۰)
 * ─ تغییرات الزام (اجباری/اختیاری)، وضعیت و نمایش در فرم ثبت‌نام به صورت آنی اعمال می‌شود.
 * ─ قابلیت تغییر سریع وضعیت با یک کلیک در جدول (تاگل سریع)
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/auth.php';
$adm = require_admin(['superadmin','manager']);

fr_ensure_profile_fields_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');

    // ذخیره یا ویرایش فیلد
    if ($act === 'save') {
        $id = (int)post('id');
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)post('name')));
        $opts = null;
        if (post('type') === 'select') {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)post('options')))));
            $opts = json_encode($lines, JSON_UNESCAPED_UNICODE);
        }
        $data = [
            'label'            => trim((string)post('label')),
            'type'             => post('type'),
            'options'          => $opts,
            'required'         => post('required') ? 1 : 0,
            'active'           => post('active') ? 1 : 0,
            'show_in_register' => post('show_in_register') ? 1 : 0,
            'show_in_profile'  => post('show_in_profile') ? 1 : 0,
            'placeholder'      => (string)post('placeholder') ?: null,
            'sort_order'       => (int)en_num((string)post('sort_order', '0')),
        ];
        if ($id) {
            DB::update('profile_fields', $data, 'id=?', [$id]);
        } else {
            if ($name === '') {
                flash('نام ماشینی (لاتین) الزامی است.', 'err');
                redirect(SITE_URL . '/admin/fields.php');
            }
            $data['name'] = $name;
            DB::insert('profile_fields', $data);
        }
        log_activity('admin_field_save', 'field', $id ?: null);
        flash('تنظیمات فیلد با موفقیت ذخیره شد ✅');
        redirect(SITE_URL . '/admin/fields.php');
    }

    // تغییر وضعیت الزام (اجباری / اختیاری)
    if ($act === 'toggle_required') {
        $id = (int)post('id');
        $f = DB::one('SELECT required, label FROM ' . DB::t('profile_fields') . ' WHERE id=?', [$id]);
        if ($f) {
            $newReq = (int)$f['required'] === 1 ? 0 : 1;
            DB::update('profile_fields', ['required' => $newReq], 'id=?', [$id]);
            $stText = $newReq ? '🔴 اجباری (الزامی)' : '⚪ غیر اجباری (اختیاری)';
            flash("وضعیت الزام فیلد «{$f['label']}» به {$stText} تغییر یافت ✅", 'ok');
        }
        redirect(SITE_URL . '/admin/fields.php');
    }

    // تغییر وضعیت نمایش در فرم ثبت‌نام
    if ($act === 'toggle_register') {
        $id = (int)post('id');
        $f = DB::one('SELECT show_in_register, label FROM ' . DB::t('profile_fields') . ' WHERE id=?', [$id]);
        if ($f) {
            $newReg = (int)$f['show_in_register'] === 1 ? 0 : 1;
            DB::update('profile_fields', ['show_in_register' => $newReg], 'id=?', [$id]);
            $stText = $newReg ? '🟢 نمایش در ثبت‌نام' : '⚪ عدم نمایش در ثبت‌نام';
            flash("نمایش فیلد «{$f['label']}» در ثبت‌نام به {$stText} تغییر یافت ✅", 'ok');
        }
        redirect(SITE_URL . '/admin/fields.php');
    }

    // تغییر وضعیت نمایش در پروفایل کاربر
    if ($act === 'toggle_profile') {
        $id = (int)post('id');
        $f = DB::one('SELECT show_in_profile, label FROM ' . DB::t('profile_fields') . ' WHERE id=?', [$id]);
        if ($f) {
            $newProf = (int)$f['show_in_profile'] === 1 ? 0 : 1;
            DB::update('profile_fields', ['show_in_profile' => $newProf], 'id=?', [$id]);
            $stText = $newProf ? '🟢 نمایش در پروفایل' : '⚪ عدم نمایش در پروفایل';
            flash("نمایش فیلد «{$f['label']}» در پروفایل به {$stText} تغییر یافت ✅", 'ok');
        }
        redirect(SITE_URL . '/admin/fields.php');
    }

    // تغییر وضعیت فعال / غیرفعال بودن
    if ($act === 'toggle_active') {
        $id = (int)post('id');
        $f = DB::one('SELECT active, label FROM ' . DB::t('profile_fields') . ' WHERE id=?', [$id]);
        if ($f) {
            $newAct = (int)$f['active'] === 1 ? 0 : 1;
            DB::update('profile_fields', ['active' => $newAct], 'id=?', [$id]);
            $stText = $newAct ? '🟢 فعال' : '🔴 غیرفعال';
            flash("وضعیت فیلد «{$f['label']}» به {$stText} تغییر یافت ✅", 'ok');
        }
        redirect(SITE_URL . '/admin/fields.php');
    }

    // حذف فیلد غیرسیستمی
    if ($act === 'delete') {
        $f = DB::one('SELECT is_system FROM ' . DB::t('profile_fields') . ' WHERE id=?', [(int)post('id')]);
        if ($f && empty($f['is_system'])) {
            DB::run('DELETE FROM ' . DB::t('profile_fields') . ' WHERE id=?', [(int)post('id')]);
            flash('فیلد با موفقیت حذف شد.');
        } else {
            flash('فیلد سیستمی قابل حذف نیست؛ اما می‌توانید آن را غیرفعال یا غیراجباری کنید.', 'warn');
        }
        redirect(SITE_URL . '/admin/fields.php');
    }

    redirect(SITE_URL . '/admin/fields.php');
}

$rows  = DB::all('SELECT * FROM ' . DB::t('profile_fields') . ' ORDER BY sort_order, id');
$editF = ($ef = (int)($_GET['edit_f'] ?? 0)) ? DB::one('SELECT * FROM ' . DB::t('profile_fields') . ' WHERE id=?', [$ef]) : null;
$types = [
    'text'         => 'متن کوتاه',
    'number'       => 'عدد',
    'select'       => 'انتخاب از لیست',
    'date'         => 'تاریخ شمسی',
    'textarea'     => 'متن بلند',
    'mobile'       => 'موبایل',
    'nationalcode' => 'کد ملی',
    'checkbox'     => 'چک‌باکس'
];

admin_layout($adm, 'fields', 'فیلدهای فرم ثبت‌نام و پروفایل');
echo flash();
?>
<?php if ($editF): ?>
  <div class="fr-card" style="margin:0">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
      <h3 style="margin:0">✏️ ویرایش فیلد: «<?= e($editF['label']) ?>»</h3>
      <a class="fr-btn ghost sm" href="fields.php">← بازگشت به فهرست فیلدها</a>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($editF['id'] ?? 0) ?>">
      <div class="fr-field"><label>نام ماشینی (لاتین، فقط حروف و _)</label>
        <input class="fr-input" name="name" value="<?= e($editF['name'] ?? '') ?>" style="direction:ltr" <?= !empty($editF['is_system']) ? 'readonly style="direction:ltr;background:#f8fafc;color:#64748b"' : 'required' ?>></div>
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
      <div style="background:#fff7ed;border:1.5px solid #fdba74;border-radius:10px;padding:12px 14px;margin-bottom:14px">
        <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:#9a3412">📌 وضعیت الزام پاسخ‌دهی (اجباری یا غیر اجباری):</label>
        <div style="display:flex;gap:20px">
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
            <input type="radio" name="required" value="1" <?= (!empty($editF['required'])) ? 'checked' : '' ?> style="accent-color:#ea580c">
            <b style="color:#c2410c">🔴 اجباری (الزامی)</b>
          </label>
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
            <input type="radio" name="required" value="0" <?= (empty($editF['required'])) ? 'checked' : '' ?> style="accent-color:#0284c7">
            <b style="color:#0369a1">⚪ غیر اجباری (اختیاری)</b>
          </label>
        </div>
      </div>
      <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px">
        <label class="tog"><input type="checkbox" name="active" <?= ($editF['active'] ?? 1)?'checked':'' ?>> فعال</label>
        <label class="tog"><input type="checkbox" name="show_in_register" <?= ($editF['show_in_register'] ?? 1)?'checked':'' ?>> در ثبت‌نام</label>
        <label class="tog"><input type="checkbox" name="show_in_profile" <?= ($editF['show_in_profile'] ?? 1)?'checked':'' ?>> در پروفایل</label>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap">
        <button class="fr-btn sm" type="submit">ذخیره تغییرات فیلد</button>
        <a class="fr-btn ghost sm" href="fields.php">انصراف و بازگشت</a>
      </div>
    </form>
  </div>
<?php
  admin_layout_end();
  exit;
endif;
?>

<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3>افزودن فیلد جدید</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save"><input type="hidden" name="id" value="0">
      <div class="fr-field"><label>نام ماشینی (لاتین، فقط حروف و _)</label>
        <input class="fr-input" name="name" value="" style="direction:ltr" required></div>
      <div class="fr-field"><label>عنوان نمایشی</label>
        <input class="fr-input" name="label" value="" required></div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>نوع فیلد</label>
          <select class="fr-select" name="type">
            <?php foreach ($types as $k=>$v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fr-field"><label>ترتیب نمایش</label>
          <input class="fr-input" name="sort_order" value="0"></div>
      </div>
      <div class="fr-field"><label>گزینه‌ها برای لیست (هر خط یک گزینه)</label>
        <textarea class="fr-textarea" name="options" rows="3"></textarea></div>
      <div class="fr-field"><label>متن راهنما (placeholder)</label>
        <input class="fr-input" name="placeholder" value=""></div>
      <div style="background:#fff7ed;border:1.5px solid #fdba74;border-radius:10px;padding:12px 14px;margin-bottom:14px">
        <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:#9a3412">📌 وضعیت الزام پاسخ‌دهی (اجباری یا غیر اجباری):</label>
        <div style="display:flex;gap:20px">
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
            <input type="radio" name="required" value="1" style="accent-color:#ea580c">
            <b style="color:#c2410c">🔴 اجباری (الزامی)</b>
          </label>
          <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
            <input type="radio" name="required" value="0" checked style="accent-color:#0284c7">
            <b style="color:#0369a1">⚪ غیر اجباری (اختیاری)</b>
          </label>
        </div>
      </div>
      <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px">
        <label class="tog"><input type="checkbox" name="active" checked> فعال</label>
        <label class="tog"><input type="checkbox" name="show_in_register" checked> در ثبت‌نام</label>
        <label class="tog"><input type="checkbox" name="show_in_profile" checked> در پروفایل</label>
      </div>
      <div style="margin-top:14px"><button class="fr-btn sm" type="submit">افزودن فیلد</button></div>
    </form>
  </div>

  <div class="fr-card" style="margin:0">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
      <h3 style="margin:0">فیلدهای موجود (<?= fa_num(count($rows)) ?>)</h3>
      <small style="color:var(--muted);font-size:12px">⚡ کلیک روی هر دکمه، وضعیت را فوراً تغییر می‌دهد.</small>
    </div>
    <div style="overflow-x:auto">
      <table class="fr-table">
        <thead>
          <tr>
            <th>عنوان و شناسه</th>
            <th>نوع</th>
            <th style="text-align:center">الزام پاسخ‌دهی</th>
            <th style="text-align:center">در ثبت‌نام</th>
            <th style="text-align:center">در پروفایل</th>
            <th style="text-align:center">وضعیت کلی</th>
            <th style="text-align:center">عملیات</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $f): ?>
        <tr style="<?= $f['active'] ? '' : 'opacity:.45' ?>">
          <td>
            <b><?= e($f['label']) ?></b><br>
            <small style="color:var(--muted);direction:ltr"><?= e($f['name']) ?><?= !empty($f['is_system']) ? ' 🔒' : '' ?></small>
          </td>
          <td style="font-size:12px"><?= e($types[$f['type']] ?? $f['type']) ?></td>
          <!-- الزام پاسخ دهی -->
          <td style="text-align:center">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="toggle_required">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <?php if (!empty($f['required'])): ?>
                <button type="submit" class="fr-chip sm warn" style="border:none;cursor:pointer;font-size:11px" title="کلیک برای تغییر به غیراجباری (اختیاری)">
                  🔴 اجباری
                </button>
              <?php else: ?>
                <button type="submit" class="fr-chip sm" style="border:none;cursor:pointer;font-size:11px;background:#f1f5f9;color:#64748b" title="کلیک برای تغییر به اجباری">
                  ⚪ اختیاری
                </button>
              <?php endif; ?>
            </form>
          </td>
          <!-- در ثبت‌نام -->
          <td style="text-align:center">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="toggle_register">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <?php if (!empty($f['show_in_register'])): ?>
                <button type="submit" class="fr-chip sm ok" style="border:none;cursor:pointer;font-size:11px" title="کلیک برای عدم نمایش در ثبت‌نام">
                  🟢 نمایش
                </button>
              <?php else: ?>
                <button type="submit" class="fr-chip sm" style="border:none;cursor:pointer;font-size:11px;background:#f1f5f9;color:#94a3b8" title="کلیک برای نمایش در ثبت‌نام">
                  ⚪ عدم نمایش
                </button>
              <?php endif; ?>
            </form>
          </td>
          <!-- در پروفایل -->
          <td style="text-align:center">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="toggle_profile">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <?php if (!empty($f['show_in_profile'])): ?>
                <button type="submit" class="fr-chip sm ok" style="border:none;cursor:pointer;font-size:11px" title="کلیک برای عدم نمایش در پروفایل">
                  🟢 نمایش
                </button>
              <?php else: ?>
                <button type="submit" class="fr-chip sm" style="border:none;cursor:pointer;font-size:11px;background:#f1f5f9;color:#94a3b8" title="کلیک برای نمایش در پروفایل">
                  ⚪ عدم نمایش
                </button>
              <?php endif; ?>
            </form>
          </td>
          <!-- وضعیت کلی -->
          <td style="text-align:center">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="toggle_active">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <?php if (!empty($f['active'])): ?>
                <button type="submit" class="fr-chip sm ok" style="border:none;cursor:pointer;font-size:11px" title="کلیک برای غیرفعال‌سازی فیلد">
                  🟢 فعال
                </button>
              <?php else: ?>
                <button type="submit" class="fr-chip sm err" style="border:none;cursor:pointer;font-size:11px" title="کلیک برای فعال‌سازی فیلد">
                  🔴 غیرفعال
                </button>
              <?php endif; ?>
            </form>
          </td>
          <!-- عملیات -->
          <td class="actions" style="text-align:center">
            <a class="fr-btn sm ghost" href="?edit_f=<?= (int)$f['id'] ?>">ویرایش</a>
            <?php if (empty($f['is_system'])): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('آیا از حذف این فیلد اطمینان دارید؟')">
              <?= csrf_field() ?><input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="fr-btn sm danger">حذف</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint" style="font-size:12px;color:var(--muted);margin-top:14px">
      🔒 فیلدهای سیستمی حذف‌شدنی نیستند؛ اما می‌توانید آن‌ها را غیرفعال، اختیاری یا از فرم‌های ثبت‌نام و پروفایل مخفی کنید. تمام تغییرات بلافاصله روی فرم‌ها و اعتبارسنجی سرور اعمال می‌شوند.
    </p>
  </div>
</div>
<?php admin_layout_end(); ?>
