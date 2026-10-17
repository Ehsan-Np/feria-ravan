<?php
/**
 * ============================================================================
 *  مدیریت فیلدهای سفارشی و منطق شرطی تست‌های روان‌شناسی (نسخهٔ ۳٫۵٫۲)
 *  ─ امکان تعریف انواع فیلد (متنی، عددی، چک‌باکس، لیست کشویی، رادیویی، تلفن و...)
 *  ─ منطق شرطی پیشرفته: نمایش وابسته به پاسخ فیلدهای والد
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/core/TestFields.php';
$adm = require_admin(['superadmin','manager']);

TestFields::ensureSchema();

// پردازش عملیات‌های POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');

    if ($act === 'save') {
        $id = (int)post('id');
        $label = trim((string)post('label'));
        $rawName = strtolower(trim((string)post('name')));
        $name = preg_replace('/[^a-z0-9_]/', '', $rawName);
        $type = (string)post('type', 'text');
        $testId = (int)post('test_id', '0');

        if ($label === '') {
            flash('عنوان فیلد الزامی است.', 'err');
            redirect(SITE_URL . '/admin/test-fields.php' . ($testId ? '?test_id=' . $testId : ''));
        }

        if ($name === '') {
            flash('نام ماشینی فیلد (فقط حروف کوچک انگلیسی و خط زیر) الزامی است.', 'err');
            redirect(SITE_URL . '/admin/test-fields.php' . ($testId ? '?test_id=' . $testId : ''));
        }

        // پردازش گزینه‌ها برای select و radio
        $optsJson = null;
        if ($type === 'select' || $type === 'radio') {
            $rawLines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)post('options')))));
            $parsed = [];
            foreach ($rawLines as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $parsed[] = ['key' => trim($k), 'label' => trim($v)];
                } else {
                    $parsed[] = ['key' => $line, 'label' => $line];
                }
            }
            $optsJson = !empty($parsed) ? json_encode($parsed, JSON_UNESCAPED_UNICODE) : null;
        }

        // منطق شرطی
        $hasCond = !empty(post('has_condition'));
        $condField = $hasCond ? trim((string)post('condition_field')) : null;
        $condOp    = $hasCond ? trim((string)post('condition_operator', 'equals')) : 'equals';
        $condVal   = $hasCond ? trim((string)post('condition_value')) : null;

        $data = [
            'test_id'            => $testId,
            'label'              => $label,
            'name'               => $name,
            'type'               => $type,
            'options'            => $optsJson,
            'placeholder'        => trim((string)post('placeholder')) ?: null,
            'help_text'          => trim((string)post('help_text')) ?: null,
            'required'           => !empty(post('required')) ? 1 : 0,
            'condition_field'    => $condField ?: null,
            'condition_operator' => $condOp ?: 'equals',
            'condition_value'    => $condVal ?: null,
            'show_in_free'       => !empty(post('show_in_free')) ? 1 : 0,
            'show_in_full'       => !empty(post('show_in_full')) ? 1 : 0,
            'sort_order'         => (int)en_num((string)post('sort_order', '0')),
            'active'             => !empty(post('active')) ? 1 : 0,
        ];

        if ($id > 0) {
            DB::update('test_fields', $data, 'id=?', [$id]);
            log_activity('test_field_update', 'test_field', $id, ['name' => $name]);
            flash('فیلد سفارشی با موفقیت ویرایش شد ✅', 'ok');
        } else {
            // بررسی عدم تکراری بودن نام ماشینی در همان دامنه تست
            $exists = DB::one('SELECT id FROM ' . DB::t('test_fields') . ' WHERE name=? AND (test_id=0 OR test_id=?)', [$name, $testId]);
            if ($exists) {
                flash('فیلدی با این نام ماشینی قبلاً ثبت شده است؛ لطفاً شناسه دیگری انتخاب کنید.', 'err');
                redirect(SITE_URL . '/admin/test-fields.php' . ($testId ? '?test_id=' . $testId : ''));
            }
            $newId = DB::insert('test_fields', $data);
            log_activity('test_field_create', 'test_field', $newId, ['name' => $name]);
            flash('فیلد سفارشی جدید با موفقیت ایجاد شد 🎉', 'ok');
        }

        redirect(SITE_URL . '/admin/test-fields.php' . ($testId ? '?test_id=' . $testId : ''));
    }

    if ($act === 'delete') {
        $delId = (int)post('id');
        $f = DB::one('SELECT * FROM ' . DB::t('test_fields') . ' WHERE id=?', [$delId]);
        if ($f) {
            DB::run('DELETE FROM ' . DB::t('test_fields') . ' WHERE id=?', [$delId]);
            log_activity('test_field_delete', 'test_field', $delId, ['name' => $f['name']]);
            flash('فیلد سفارشی با موفقیت حذف گردید.', 'ok');
        }
        $retTestId = (int)post('test_id', '0');
        redirect(SITE_URL . '/admin/test-fields.php' . ($retTestId ? '?test_id=' . $retTestId : ''));
    }

    if ($act === 'toggle_active') {
        $toggleId = (int)post('id');
        $f = DB::one('SELECT active FROM ' . DB::t('test_fields') . ' WHERE id=?', [$toggleId]);
        if ($f) {
            $newSt = (int)$f['active'] === 1 ? 0 : 1;
            DB::update('test_fields', ['active' => $newSt], 'id=?', [$toggleId]);
            flash('وضعیت فعال‌سازی فیلد تغییر یافت.', 'ok');
        }
        $retTestId = (int)post('test_id', '0');
        redirect(SITE_URL . '/admin/test-fields.php' . ($retTestId ? '?test_id=' . $retTestId : ''));
    }
}

// فیلتر تست
$filterTestId = (int)($_GET['test_id'] ?? 0);
$where = '1=1';
$params = [];
if ($filterTestId > 0) {
    $where .= ' AND (tf.test_id=0 OR tf.test_id=?)';
    $params[] = $filterTestId;
}

$fields = DB::all("SELECT tf.*, t.title AS test_title
                   FROM " . DB::t('test_fields') . " tf
                   LEFT JOIN " . DB::t('tests') . " t ON t.id=tf.test_id
                   WHERE $where
                   ORDER BY tf.sort_order ASC, tf.id ASC", $params);

$allTests = DB::all("SELECT id, title FROM " . DB::t('tests') . " ORDER BY title ASC");

$editId = (int)($_GET['edit'] ?? 0);
$editField = $editId > 0 ? DB::one("SELECT * FROM " . DB::t('test_fields') . " WHERE id=?", [$editId]) : null;

$fieldTypes = [
    'text'     => 'متن کوتاه (Text)',
    'textarea' => 'متن بلند چندخطی (Textarea)',
    'number'   => 'عدد (Number)',
    'select'   => 'لیست کشویی (Dropdown Select)',
    'radio'    => 'انتخاب تک‌گزینه‌ای (Radio Group)',
    'checkbox' => 'چک‌باکس تایید/بله-خیر (Checkbox)',
    'tel'      => 'شماره تلفن / موبایل (Telephone)',
];

$condOperators = [
    'equals'      => 'دقیقاً برابر با (Equals)',
    'not_equals'  => 'مخالف با (Not Equals)',
    'checked'     => 'تیک خورده / فعال باشد (Checked)',
    'not_checked' => 'تیک نخورده / غیرفعال باشد (Not Checked)',
    'in'          => 'شامل یکی از مقادیر باشد (In - مقادیر با کاما)',
    'contains'    => 'شامل متن باشد (Contains)',
];

admin_layout($adm, 'test-fields', 'فیلدهای سفارشی و منطق شرطی تست‌ها');
echo flash();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0;font-size:20px;color:#1e293b">📋 مدیریت فیلدهای سفارشی و منطق شرطی تست‌ها</h2>
    <p style="margin:4px 0 0;font-size:13px;color:#64748b">
      امکان افزودن فیلدهای متنی، کشویی، انتخابی و شرطی پیش از پرداخت و شروع آزمون (مانند درخواست مشاوره رایگان، ثبت اطلاعات همراه و...).
    </p>
  </div>
  <div style="display:flex;gap:10px">
    <a class="fr-btn ghost sm" href="tests.php">← بازگشت به آزمون‌ها</a>
    <?php if ($editField): ?>
      <a class="fr-btn sm primary" href="test-fields.php<?= $filterTestId ? '?test_id=' . $filterTestId : '' ?>">+ فیلد جدید</a>
    <?php endif; ?>
  </div>
</div>

<!-- نوار فیلتر آزمون -->
<div class="fr-card" style="margin-bottom:20px;padding:14px 18px;background:#f8fafc">
  <form method="get" action="test-fields.php" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <label style="font-size:13.5px;font-weight:700;color:#334155">فیلتر بر اساس آزمون:</label>
    <select name="test_id" class="fr-input fr-select" style="max-width:320px" onchange="this.form.submit()">
      <option value="0">همه آزمون‌ها (نمایش فیلدهای سراسری و اختصاصی)</option>
      <?php foreach ($allTests as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $filterTestId === (int)$t['id'] ? 'selected' : '' ?>>
          <?= e($t['title']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ($filterTestId > 0): ?>
      <a class="fr-btn ghost sm" href="test-fields.php">پاک‌سازی فیلتر</a>
    <?php endif; ?>
  </form>
</div>

<div class="fr-grid cols-2" style="align-items:start">
  <!-- فرم افزودن / ویرایش فیلد -->
  <div class="fr-card" style="position:sticky;top:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:12px">
      <h3 style="margin:0;font-size:16px;color:#0f172a">
        <?= $editField ? '✏️ ویرایش فیلد سفارشی: «' . e($editField['label']) . '»' : '➕ افزودن فیلد سفارشی جدید' ?>
      </h3>
      <?php if ($editField): ?>
        <a class="fr-btn ghost sm" href="test-fields.php<?= $filterTestId ? '?test_id=' . $filterTestId : '' ?>">لغو ویرایش</a>
      <?php endif; ?>
    </div>

    <form method="post" id="fieldForm">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)($editField['id'] ?? 0) ?>">

      <!-- انتخاب آزمون -->
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">دامنه آزمون:</label>
        <select name="test_id" class="fr-input fr-select" style="width:100%">
          <option value="0" <?= (isset($editField['test_id']) && (int)$editField['test_id'] === 0) ? 'selected' : '' ?>>🌐 همه آزمون‌ها (سراسری)</option>
          <?php foreach ($allTests as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= ((int)($editField['test_id'] ?? $filterTestId) === (int)$t['id']) ? 'selected' : '' ?>>
              <?= e($t['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11.5px;color:#64748b;margin-top:4px">اگر روی «همه آزمون‌ها» باشد، این فیلد در صفحه شروع همه آزمون‌ها فعال خواهد شد.</div>
      </div>

      <!-- عنوان فیلد -->
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">
          عنوان نمایشی فیلد (فارسی): <span style="color:#ef4444">*</span>
        </label>
        <input type="text" name="label" class="fr-input" style="width:100%" required
               value="<?= e($editField['label'] ?? '') ?>"
               placeholder="مثال: آیا به جلسه مشاوره رایگان نیاز دارید؟">
      </div>

      <!-- نام ماشینی -->
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">
          نام ماشینی انگلیسی (Name): <span style="color:#ef4444">*</span>
        </label>
        <input type="text" name="name" class="fr-input" style="width:100%;direction:ltr;text-align:left" required
               value="<?= e($editField['name'] ?? '') ?>"
               placeholder="مثال: free_consultation یا has_companion">
        <div style="font-size:11.5px;color:#64748b;margin-top:4px">فقط حروف کوچک انگلیسی، اعداد و خط زیر (بدون فاصله). برای اتصال در منطق شرطی استفاده می‌شود.</div>
      </div>

      <!-- نوع فیلد -->
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">نوع فیلد ورودی:</label>
        <select name="type" id="selFieldType" class="fr-input fr-select" style="width:100%" onchange="toggleOptionsBox()">
          <?php foreach ($fieldTypes as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= (($editField['type'] ?? 'text') === $k) ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- گزینه‌ها برای select و radio -->
      <?php
      $rawOpts = '';
      if (!empty($editField['options'])) {
          $dec = json_decode($editField['options'], true);
          if (is_array($dec)) {
              $lines = [];
              foreach ($dec as $item) {
                  if (is_array($item)) {
                      $lines[] = ($item['key'] ?? '') . ': ' . ($item['label'] ?? '');
                  } else {
                      $lines[] = (string)$item;
                  }
              }
              $rawOpts = implode("\n", $lines);
          }
      }
      ?>
      <div id="boxOptions" style="margin-bottom:14px;display:<?= in_array($editField['type'] ?? 'text', ['select','radio'], true) ? 'block' : 'none' ?>">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">گزینه‌های انتخابی (هر گزینه در یک خط):</label>
        <textarea name="options" class="fr-input" rows="4" style="width:100%;direction:rtl"
                  placeholder="مثال:
no: خیر، نیازی ندارم
yes: بله، تمایل به دریافت مشاوره دارم"><?= e($rawOpts) ?></textarea>
        <div style="font-size:11.5px;color:#64748b;margin-top:4px">می‌توانید به فرمت «کلید: عنوان» بنویسید (مثال: yes: بله) یا فقط عنوان هر گزینه را در یک سطر وارد نمایید.</div>
      </div>

      <!-- راهنما و پلیس‌هولدر -->
      <div class="fr-grid cols-2" style="margin-bottom:14px">
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">متن پیش‌فرض (Placeholder):</label>
          <input type="text" name="placeholder" class="fr-input" style="width:100%"
                 value="<?= e($editField['placeholder'] ?? '') ?>" placeholder="مثال: 09123456789">
        </div>
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">متن راهنمای زیر فیلد:</label>
          <input type="text" name="help_text" class="fr-input" style="width:100%"
                 value="<?= e($editField['help_text'] ?? '') ?>" placeholder="توضیح کوتاه کم‌رنگ">
        </div>
      </div>

      <!-- تنظیمات الزامی و نمایش -->
      <div style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:16px">
        <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:#1e293b">⚙️ گزینه‌های اعتبارسنجی و نمایش:</div>
        <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="required" value="1" <?= (!empty($editField['required']) || !$editField) ? 'checked' : '' ?> style="width:16px;height:16px">
            <span><b>اجباری بودن فیلد:</b> کاربر ملزم به تکمیل این مورد باشد</span>
          </label>
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="show_in_free" value="1" <?= (!isset($editField['show_in_free']) || !empty($editField['show_in_free'])) ? 'checked' : '' ?> style="width:16px;height:16px">
            <span>نمایش در نسخهٔ رایگان آزمون</span>
          </label>
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="show_in_full" value="1" <?= (!isset($editField['show_in_full']) || !empty($editField['show_in_full'])) ? 'checked' : '' ?> style="width:16px;height:16px">
            <span>نمایش در نسخهٔ ویژه / پولی آزمون</span>
          </label>
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="active" value="1" <?= (!isset($editField['active']) || !empty($editField['active'])) ? 'checked' : '' ?> style="width:16px;height:16px">
            <span>وضعیت: فعال</span>
          </label>
        </div>
      </div>

      <!-- تنظیمات پیشرفته منطق شرطی (Conditional Logic) -->
      <?php $hasCondNow = !empty($editField['condition_field']); ?>
      <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:14px 16px;margin-bottom:18px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
          <span style="font-size:18px">🔀</span>
          <b style="font-size:13.5px;color:#1e40af">منطق شرطی پیشرفته (Conditional Logic)</b>
        </div>
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-bottom:12px">
          <input type="checkbox" name="has_condition" id="chkHasCond" value="1" <?= $hasCondNow ? 'checked' : '' ?> onchange="toggleConditionBox()">
          <span style="font-weight:700;color:#1e3a8a">این فیلد تنها در صورت برقراری یک شرط نمایش داده شود</span>
        </label>

        <div id="boxCondSettings" style="display:<?= $hasCondNow ? 'block' : 'none' ?>;padding-top:10px;border-top:1px dashed #93c5fd">
          <div style="margin-bottom:12px">
            <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:4px;color:#1e3a8a">فیلد والد (وابسته به پاسخ چه فیلدی است؟):</label>
            <select name="condition_field" class="fr-input fr-select" style="width:100%">
              <option value="">— انتخاب فیلد والد —</option>
              <?php foreach ($fields as $other):
                  if ($editField && (int)$other['id'] === (int)$editField['id']) continue;
              ?>
                <option value="<?= e($other['name']) ?>" <?= (($editField['condition_field'] ?? '') === $other['name']) ? 'selected' : '' ?>>
                  <?= e($other['label']) ?> (<?= e($other['name']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="fr-grid cols-2" style="margin-bottom:8px">
            <div>
              <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:4px;color:#1e3a8a">عملگر شرط:</label>
              <select name="condition_operator" class="fr-input fr-select" style="width:100%">
                <?php foreach ($condOperators as $opK => $opLbl): ?>
                  <option value="<?= e($opK) ?>" <?= (($editField['condition_operator'] ?? 'equals') === $opK) ? 'selected' : '' ?>><?= e($opLbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:4px;color:#1e3a8a">مقدار شرط (Target Value):</label>
              <input type="text" name="condition_value" class="fr-input" style="width:100%"
                     value="<?= e($editField['condition_value'] ?? '') ?>"
                     placeholder="مثال: yes یا بله">
            </div>
          </div>
          <div style="font-size:11.5px;color:#2563eb;line-height:1.7">
            💡 مثال کاربردی: اگر فیلد والد <code>has_companion</code> باشد، عملگر <code>in</code> و مقدار <code>yes,بله</code> تنظیم شود تا به محض انتخاب «بله»، این فیلد ظاهر شود.
          </div>
        </div>
      </div>

      <!-- ترتیب و دکمه ثبت -->
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
        <div style="display:flex;align-items:center;gap:8px">
          <label style="font-size:13px;font-weight:700">ترتیب:</label>
          <input type="number" name="sort_order" class="fr-input" style="width:75px"
                 value="<?= (int)($editField['sort_order'] ?? (count($fields) + 1)) ?>">
        </div>
        <button type="submit" class="fr-btn primary" style="padding:10px 24px">
          <?= $editField ? 'ذخیره تغییرات فیلد 💾' : 'ایجاد و ثبت فیلد سفارشی ➕' ?>
        </button>
      </div>
    </form>
  </div>

  <!-- فهرست فیلدهای تعریف‌شده -->
  <div>
    <div class="fr-card" style="padding:0;overflow:hidden">
      <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center">
        <b style="font-size:15px;color:#1e293b">📋 فیلدهای فعال آزمون‌ها (<?= fa_num(count($fields)) ?> فیلد)</b>
        <span class="fr-chip info sm">اجرای بلادرنگ منطق شرطی</span>
      </div>

      <?php if (empty($fields)): ?>
        <div style="padding:30px;text-align:center;color:#64748b">
          هنوز هیچ فیلد سفارشی برای این فیلتر تعریف نشده است. از فرم روبرو می‌توانید اولین فیلد خود را ایجاد کنید.
        </div>
      <?php else: ?>
        <div style="overflow-x:auto">
          <table class="fr-table" style="width:100%;font-size:13px">
            <thead>
              <tr style="background:#f1f5f9;text-align:right">
                <th style="width:40px">#</th>
                <th>عنوان و نام ماشینی</th>
                <th>نوع ورودی</th>
                <th>دامنه تست</th>
                <th>منطق شرطی</th>
                <th>وضعیت</th>
                <th style="text-align:left;width:120px">عملیات</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fields as $idx => $f):
                  $isCond = !empty($f['condition_field']);
              ?>
              <tr style="<?= empty($f['active']) ? 'opacity:0.6;background:#fcfcfc' : '' ?>">
                <td><?= fa_num($f['sort_order']) ?></td>
                <td>
                  <div style="font-weight:700;color:#0f172a"><?= e($f['label']) ?></div>
                  <div style="font-size:11.5px;color:#64748b;font-family:monospace;direction:ltr;text-align:right">
                    <?= e($f['name']) ?>
                    <?php if (!empty($f['required'])): ?>
                      <span style="color:#ef4444;font-family:inherit;font-weight:700"> (الزامی)</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <span class="fr-chip sm purple" style="font-size:11px">
                    <?= e($fieldTypes[$f['type']] ?? $f['type']) ?>
                  </span>
                </td>
                <td>
                  <?php if ((int)$f['test_id'] === 0): ?>
                    <span class="fr-chip info sm" style="font-size:11px">🌐 همه تست‌ها</span>
                  <?php else: ?>
                    <span class="fr-chip sm" style="font-size:11px;background:#f3e8ff;color:#6b21a8">
                      <?= e($f['test_title'] ?? ('تست #' . $f['test_id'])) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isCond): ?>
                    <div style="font-size:12px;color:#2563eb;line-height:1.6">
                      🔀 وابسته به <code><?= e($f['condition_field']) ?></code><br>
                      <span style="font-size:11px;color:#64748b"><?= e($f['condition_operator']) ?>: <?= e($f['condition_value']) ?></span>
                    </div>
                  <?php else: ?>
                    <span style="font-size:12px;color:#94a3b8">— بدون شرط</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="test_id" value="<?= (int)$filterTestId ?>">
                    <button type="submit" class="fr-chip sm <?= !empty($f['active']) ? 'ok' : 'err' ?>" style="border:none;cursor:pointer;font-size:11px">
                      <?= !empty($f['active']) ? '✓ فعال' : '✕ غیرفعال' ?>
                    </button>
                  </form>
                </td>
                <td style="text-align:left">
                  <div style="display:flex;gap:6px;justify-content:flex-end">
                    <a class="fr-btn sm ghost" href="test-fields.php?edit=<?= (int)$f['id'] ?><?= $filterTestId ? '&test_id=' . $filterTestId : '' ?>" title="ویرایش فیلد">✏️</a>
                    <form method="post" onsubmit="return confirm('آیا از حذف این فیلد سفارشی اطمینان دارید؟');" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                      <input type="hidden" name="test_id" value="<?= (int)$filterTestId ?>">
                      <button type="submit" class="fr-btn sm ghost" style="color:#ef4444" title="حذف فیلد">🗑</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function toggleOptionsBox() {
    const type = document.getElementById('selFieldType').value;
    const box = document.getElementById('boxOptions');
    box.style.display = (type === 'select' || type === 'radio') ? 'block' : 'none';
}
function toggleConditionBox() {
    const chk = document.getElementById('chkHasCond').checked;
    const box = document.getElementById('boxCondSettings');
    box.style.display = chk ? 'block' : 'none';
}
</script>
