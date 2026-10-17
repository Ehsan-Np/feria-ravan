<?php
/**
 * ============================================================================
 *  مدیریت فیلدهای سفارشی و منطق شرطی تست‌های روان‌شناسی (نسخهٔ ۳٫۹٫۰)
 *  ─ معماری چندگزینه‌ای به سبک Pronamic / Gravity Forms (چک‌باکس و رادیوباتن پویا)
 *  ─ گزینه‌ساز دیداری ریپیتر (Repeater Choices Builder) + ویرایشگر متنی چندخطی
 *  ─ تعیین گام اجرا: پایان آزمون (post_test)، آغاز آزمون (pre_test) یا هر دو (both)
 *  ─ فیلتر و هدف‌گذاری بر اساس آزمون، حالت (رایگان/ویژه) و منطق شرطی پیشرفته
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
        $id      = (int)post('id');
        $label   = trim((string)post('label'));
        $rawName = strtolower(trim((string)post('name')));
        $name    = preg_replace('/[^a-z0-9_]/', '', $rawName);
        $type    = (string)post('type', 'text');
        $testId  = (int)post('test_id', '0');
        $displayStage = in_array((string)post('display_stage'), ['post_test', 'pre_test', 'both'], true) ? (string)post('display_stage') : 'post_test';

        if ($label === '') {
            flash('عنوان فیلد الزامی است.', 'err');
            redirect(SITE_URL . '/admin/test-fields.php' . ($testId ? '?test_id=' . $testId : ''));
        }

        if ($name === '') {
            flash('نام ماشینی فیلد (فقط حروف کوچک انگلیسی و خط زیر) الزامی است.', 'err');
            redirect(SITE_URL . '/admin/test-fields.php' . ($testId ? '?test_id=' . $testId : ''));
        }

        // پردازش گزینه‌ها برای checkbox, radio, select, multiselect
        $optsJson = null;
        if (in_array($type, ['checkbox', 'radio', 'select', 'multiselect'], true)) {
            $choiceKeys   = (array)(post('choice_keys') ?? []);
            $choiceLabels = (array)(post('choice_labels') ?? []);
            $parsed = [];

            if (!empty($choiceKeys)) {
                foreach ($choiceKeys as $idx => $ck) {
                    $ck = trim((string)$ck);
                    $cl = trim((string)($choiceLabels[$idx] ?? $ck));
                    if ($ck === '' && $cl === '') continue;
                    if ($ck === '') $ck = $cl;
                    $parsed[] = ['key' => $ck, 'label' => $cl];
                }
            }

            // در صورتی که ریپیتر ارسال نشده بود، از متن چندخطی گزینه‌ها استخراج کن
            if (empty($parsed) && trim((string)post('options')) !== '') {
                $rawLines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)post('options')))));
                foreach ($rawLines as $line) {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $parsed[] = ['key' => trim($k), 'label' => trim($v)];
                    } else {
                        $parsed[] = ['key' => $line, 'label' => $line];
                    }
                }
            }

            $optsJson = !empty($parsed) ? json_encode($parsed, JSON_UNESCAPED_UNICODE) : null;
        }

        // منطق شرطی
        $hasCond   = !empty(post('has_condition'));
        $condField = $hasCond ? trim((string)post('condition_field')) : null;
        $condOp    = $hasCond ? trim((string)post('condition_operator', 'equals')) : 'equals';
        $condVal   = $hasCond ? trim((string)post('condition_value')) : null;

        // وضعیت الزام (اجباری یا غیر اجباری)
        $isRequired = (!empty(post('required')) && (string)post('required') !== '0') ? 1 : 0;

        $data = [
            'test_id'            => $testId,
            'label'              => $label,
            'name'               => $name,
            'type'               => $type,
            'options'            => $optsJson,
            'placeholder'        => trim((string)post('placeholder')) ?: null,
            'help_text'          => trim((string)post('help_text')) ?: null,
            'required'           => $isRequired,
            'condition_field'    => $condField ?: null,
            'condition_operator' => $condOp ?: 'equals',
            'condition_value'    => $condVal ?: null,
            'show_in_free'       => !empty(post('show_in_free')) ? 1 : 0,
            'show_in_full'       => !empty(post('show_in_full')) ? 1 : 0,
            'display_stage'      => $displayStage,
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

    if ($act === 'toggle_required') {
        $toggleId = (int)post('id');
        $f = DB::one('SELECT required, label FROM ' . DB::t('test_fields') . ' WHERE id=?', [$toggleId]);
        if ($f) {
            $newReq = (int)$f['required'] === 1 ? 0 : 1;
            DB::update('test_fields', ['required' => $newReq], 'id=?', [$toggleId]);
            $stText = $newReq ? '🔴 اجباری (الزامی)' : '⚪ غیر اجباری (اختیاری)';
            flash("وضعیت الزام فیلد «{$f['label']}» به {$stText} تغییر یافت ✅", 'ok');
        }
        $retTestId = (int)post('test_id', '0');
        $retReq = (string)post('filter_req', '');
        $query = [];
        if ($retTestId) $query['test_id'] = $retTestId;
        if ($retReq !== '') $query['req'] = $retReq;
        redirect(SITE_URL . '/admin/test-fields.php' . (!empty($query) ? '?' . http_build_query($query) : ''));
    }

    if ($act === 'toggle_stage' || $act === 'change_stage') {
        $toggleId = (int)post('id');
        $f = DB::one('SELECT id, display_stage, label FROM ' . DB::t('test_fields') . ' WHERE id=?', [$toggleId]);
        if ($f) {
            $newStage = (string)post('new_stage', '');
            if (!in_array($newStage, ['post_test', 'pre_test', 'both'], true)) {
                $cycle = ['post_test' => 'pre_test', 'pre_test' => 'both', 'both' => 'post_test'];
                $newStage = $cycle[$f['display_stage']] ?? 'post_test';
            }
            DB::update('test_fields', ['display_stage' => $newStage], 'id=?', [$toggleId]);
            $stageNames = [
                'post_test' => '🏁 پایان آزمون (Post-Test)',
                'pre_test'  => '🚀 آغاز آزمون (Pre-Test)',
                'both'      => '🔄 هر دو مرحله (Both)',
            ];
            flash("گام اجرای فیلد «{$f['label']}» به «{$stageNames[$newStage]}» تغییر یافت ✅", 'ok');
        }
        $retTestId = (int)post('test_id', '0');
        $retReq    = (string)post('filter_req', '');
        $retStage  = (string)post('filter_stage', '');
        $query = [];
        if ($retTestId) $query['test_id'] = $retTestId;
        if ($retReq !== '') $query['req'] = $retReq;
        if ($retStage !== '') $query['stage'] = $retStage;
        redirect(SITE_URL . '/admin/test-fields.php' . (!empty($query) ? '?' . http_build_query($query) : ''));
    }
}

// فیلترها
$filterTestId = (int)($_GET['test_id'] ?? 0);
$filterReq    = (isset($_GET['req']) && $_GET['req'] !== '') ? (int)$_GET['req'] : null;
$filterStage  = trim((string)($_GET['stage'] ?? ''));

$where = '1=1';
$params = [];
if ($filterTestId > 0) {
    $where .= ' AND (tf.test_id=0 OR tf.test_id=?)';
    $params[] = $filterTestId;
}
if ($filterReq !== null) {
    $where .= ' AND tf.required=?';
    $params[] = $filterReq;
}
if ($filterStage !== '') {
    if ($filterStage === 'both') {
        $where .= ' AND tf.display_stage="both"';
    } else {
        $where .= ' AND (tf.display_stage=? OR tf.display_stage="both")';
        $params[] = $filterStage;
    }
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
    'checkbox'        => 'چک‌باکس چندگزینه‌ای (امکان انتخاب همزمان چند گزینه دلخواه)',
    'single_checkbox' => 'چک‌باکس تک‌گزینه‌ای / تاییدیه (Consent / Toggle)',
    'radio'           => 'دکمه رادیویی (تک‌انتخابی از میان چند گزینه)',
    'select'          => 'لیست کشویی (Dropdown Select)',
    'multiselect'     => 'لیست کشویی چندانتخابی (Multi-Select)',
    'text'            => 'متن کوتاه (Text)',
    'textarea'        => 'متن بلند چندخطی (Textarea)',
    'number'          => 'عدد (Number)',
    'tel'             => 'شماره تلفن / موبایل (Telephone)',
];

$condOperators = [
    'equals'      => 'دقیقاً برابر با (Equals)',
    'not_equals'  => 'مخالف با (Not Equals)',
    'contains'    => 'شامل مقدار یا متن باشد (Contains)',
    'not_contains'=> 'شامل این مقدار نباشد (Not Contains)',
    'in'          => 'شامل یکی از مقادیر باشد (In - مقادیر با کاما)',
    'checked'     => 'انتخاب / تیک خورده باشد (Checked / Filled)',
    'not_checked' => 'تیک نخورده / خالی باشد (Not Checked / Empty)',
];

admin_layout($adm, 'test-fields', 'فیلدهای سفارشی و منطق شرطی تست‌ها');
echo flash();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0;font-size:20px;color:#1e293b">📋 مدیریت فیلدهای سفارشی و منطق شرطی تست‌ها</h2>
    <p style="margin:4px 0 0;font-size:13px;color:#64748b">
      معماری چک‌باکس و رادیوباتن به سبک Pronamic / Gravity Forms، نظرسنجی و مشاوره رایگان پایان آزمون و منطق شرطی هوشمند.
    </p>
  </div>
  <div style="display:flex;gap:10px">
    <a class="fr-btn ghost sm" href="tests.php">← بازگشت به آزمون‌ها</a>
    <?php if ($editField): ?>
      <a class="fr-btn sm primary" href="test-fields.php<?= $filterTestId ? '?test_id=' . $filterTestId : '' ?>">+ فیلد جدید</a>
    <?php endif; ?>
  </div>
</div>

<!-- نوار فیلتر آزمون، گام اجرا و وضعیت الزام -->
<div class="fr-card" style="margin-bottom:20px;padding:14px 18px;background:#f8fafc">
  <form method="get" action="test-fields.php" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:8px">
      <label style="font-size:13px;font-weight:700;color:#334155">آزمون:</label>
      <select name="test_id" class="fr-input fr-select" style="max-width:260px" onchange="this.form.submit()">
        <option value="0">همه آزمون‌ها (سراسری و اختصاصی)</option>
        <?php foreach ($allTests as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $filterTestId === (int)$t['id'] ? 'selected' : '' ?>>
            <?= e($t['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex;align-items:center;gap:8px">
      <label style="font-size:13px;font-weight:700;color:#334155">گام اجرا:</label>
      <select name="stage" class="fr-input fr-select" style="max-width:190px" onchange="this.form.submit()">
        <option value="" <?= $filterStage === '' ? 'selected' : '' ?>>همه گام‌ها</option>
        <option value="post_test" <?= $filterStage === 'post_test' ? 'selected' : '' ?>>🏁 پایان آزمون (Post-Test)</option>
        <option value="pre_test" <?= $filterStage === 'pre_test' ? 'selected' : '' ?>>🚀 آغاز آزمون (Pre-Test)</option>
        <option value="both" <?= $filterStage === 'both' ? 'selected' : '' ?>>🔄 هر دو مرحله (Both)</option>
      </select>
    </div>

    <div style="display:flex;align-items:center;gap:8px">
      <label style="font-size:13px;font-weight:700;color:#334155">وضعیت الزام:</label>
      <select name="req" class="fr-input fr-select" style="max-width:170px" onchange="this.form.submit()">
        <option value="" <?= $filterReq === null ? 'selected' : '' ?>>همه وضعیت‌ها</option>
        <option value="1" <?= $filterReq === 1 ? 'selected' : '' ?>>🔴 فقط اجباری‌ها</option>
        <option value="0" <?= $filterReq === 0 ? 'selected' : '' ?>>⚪ فقط اختیاری‌ها</option>
      </select>
    </div>

    <?php if ($filterTestId > 0 || $filterReq !== null || $filterStage !== ''): ?>
      <a class="fr-btn ghost sm" href="test-fields.php">پاک‌سازی فیلترها</a>
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

      <!-- گام اجرا و زمان‌بندی نمایش فیلد (Display Stage) -->
      <?php
      $currStage = $editField['display_stage'] ?? 'post_test';
      ?>
      <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:12px 14px;margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:800;color:#1e40af;margin-bottom:8px">
          ⏱️ گام نمایش فیلد (Display Stage / Execution Timing):
        </label>
        <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="radio" name="display_stage" value="post_test" <?= $currStage === 'post_test' ? 'checked' : '' ?> style="accent-color:#2563eb">
            <span>🏁 <b>پایان آزمون (Post-Test):</b> بلافاصله بعد از اتمام سؤالات و قبل از کارنامه (مشاوره رایگان، نظرسنجی و...)</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="radio" name="display_stage" value="pre_test" <?= $currStage === 'pre_test' ? 'checked' : '' ?> style="accent-color:#2563eb">
            <span>🚀 <b>آغاز آزمون (Pre-Test):</b> پیش از شروع پاسخ‌گویی به سؤالات (اطلاعات همراه، کد ملی و...)</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="radio" name="display_stage" value="both" <?= $currStage === 'both' ? 'checked' : '' ?> style="accent-color:#2563eb">
            <span>🔄 <b>هر دو مرحله (Both):</b> هم پیش از شروع و هم در پایان آزمون</span>
          </label>
        </div>
      </div>

      <!-- انتخاب آزمون -->
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">دامنه آزمون:</label>
        <select name="test_id" class="fr-input fr-select" style="width:100%">
          <option value="0" <?= (isset($editField['test_id']) && (int)$editField['test_id'] === 0) ? 'selected' : '' ?>>🌐 همه آزمون‌ها (سراسری / Global)</option>
          <?php foreach ($allTests as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= ((int)($editField['test_id'] ?? $filterTestId) === (int)$t['id']) ? 'selected' : '' ?>>
              🎯 <?= e($t['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11.5px;color:#64748b;margin-top:4px">اگر روی یک آزمون خاص تنظیم شود، فقط در همان آزمون نمایش داده می‌شود.</div>
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
               placeholder="مثال: free_consultation یا consultation_time">
        <div style="font-size:11.5px;color:#64748b;margin-top:4px">فقط حروف کوچک انگلیسی، اعداد و خط زیر (بدون فاصله). برای اتصال در منطق شرطی استفاده می‌شود.</div>
      </div>

      <!-- نوع فیلد -->
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">نوع فیلد ورودی:</label>
        <select name="type" id="selFieldType" class="fr-input fr-select" style="width:100%" onchange="toggleOptionsBox()">
          <?php foreach ($fieldTypes as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= (($editField['type'] ?? 'checkbox') === $k) ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- گزینه‌ساز ریپیتر به سبک Gravity Forms برای checkbox, radio, select, multiselect -->
      <?php
      $existingChoices = [];
      $rawOpts = '';
      if (!empty($editField['options'])) {
          $existingChoices = TestFields::parseOptions($editField['options']);
          $lines = [];
          foreach ($existingChoices as $c) {
              $lines[] = ($c['key'] ?? '') . ': ' . ($c['label'] ?? '');
          }
          $rawOpts = implode("\n", $lines);
      }
      $showChoices = in_array($editField['type'] ?? 'checkbox', ['checkbox', 'radio', 'select', 'multiselect'], true);
      ?>
      <div id="boxOptions" style="margin-bottom:16px;background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:12px;padding:14px;display:<?= $showChoices ? 'block' : 'none' ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <label style="font-size:13px;font-weight:800;color:#1e293b;margin:0">
            🔘 گزینه‌های انتخابی (Choices Builder):
          </label>
          <button type="button" class="fr-btn sm ghost" onclick="toggleBulkEditor()" style="font-size:11.5px;padding:2px 8px">
            ⇄ تغییر به ویرایشگر متنی سریع
          </button>
        </div>

        <!-- لیست سطرهای گزینه‌ها (Visual Repeater) -->
        <div id="choicesRepeaterWrap">
          <div id="choicesList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px">
            <?php if (!empty($existingChoices)): ?>
              <?php foreach ($existingChoices as $c): ?>
                <div class="choice-row" style="display:flex;gap:8px;align-items:center">
                  <input type="text" name="choice_keys[]" class="fr-input ch-key" placeholder="شناسه (Value)" dir="ltr" style="width:120px;font-family:monospace;font-size:12px" value="<?= e($c['key']) ?>">
                  <input type="text" name="choice_labels[]" class="fr-input ch-label" placeholder="عنوان نمایشی (Label)" style="flex:1;font-size:13px" value="<?= e($c['label']) ?>">
                  <button type="button" class="fr-btn sm ghost" onclick="removeChoiceRow(this)" style="color:#ef4444;padding:4px 8px" title="حذف گزینه">🗑</button>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <!-- سطرهای پیش‌فرض برای افزودن اولیه -->
              <div class="choice-row" style="display:flex;gap:8px;align-items:center">
                <input type="text" name="choice_keys[]" class="fr-input ch-key" placeholder="شناسه (Value)" dir="ltr" style="width:120px;font-family:monospace;font-size:12px" value="yes">
                <input type="text" name="choice_labels[]" class="fr-input ch-label" placeholder="عنوان نمایشی (Label)" style="flex:1;font-size:13px" value="بله، تمایل به مشاوره رایگان دارم">
                <button type="button" class="fr-btn sm ghost" onclick="removeChoiceRow(this)" style="color:#ef4444;padding:4px 8px">🗑</button>
              </div>
              <div class="choice-row" style="display:flex;gap:8px;align-items:center">
                <input type="text" name="choice_keys[]" class="fr-input ch-key" placeholder="شناسه (Value)" dir="ltr" style="width:120px;font-family:monospace;font-size:12px" value="no">
                <input type="text" name="choice_labels[]" class="fr-input ch-label" placeholder="عنوان نمایشی (Label)" style="flex:1;font-size:13px" value="خیر، نیازی ندارم">
                <button type="button" class="fr-btn sm ghost" onclick="removeChoiceRow(this)" style="color:#ef4444;padding:4px 8px">🗑</button>
              </div>
            <?php endif; ?>
          </div>

          <button type="button" class="fr-btn sm" onclick="addChoiceRow()" style="background:#2563eb;color:#fff;font-size:12px">
            + افزودن گزینه جدید
          </button>
        </div>

        <!-- ادیتور متنی چندخطی بک‌آپ -->
        <div id="choicesBulkWrap" style="display:none;margin-top:10px">
          <textarea id="taChoicesBulk" name="options" class="fr-input" rows="5" style="width:100%;direction:rtl;font-size:13px"
                    placeholder="مثال:
yes: بله، نیاز به مشاوره رایگان دارم
no: خیر، نیازی ندارم"><?= e($rawOpts) ?></textarea>
          <div style="font-size:11.5px;color:#64748b;margin-top:4px">هر گزینه در یک سطر. فرمت: <code>شناسه: عنوان</code> یا صرفاً <code>عنوان</code>.</div>
        </div>
      </div>

      <!-- تنظیم الزامی / اختیاری بودن فیلد (Required or Optional) -->
      <?php
        $currRequired = isset($editField['required']) ? (int)$editField['required'] : 1;
      ?>
      <div style="background:#fff7ed;border:1.5px solid #fdba74;border-radius:12px;padding:14px 16px;margin-bottom:16px">
        <label style="display:block;font-size:13.5px;font-weight:700;margin-bottom:10px;color:#9a3412">
          📌 وضعیت الزام پاسخ‌دهی (اجباری یا اختیاری):
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;background:#fff;border:2px solid <?= ($currRequired === 1) ? '#ea580c' : '#e2e8f0' ?>;border-radius:10px;cursor:pointer">
            <input type="radio" name="required" value="1" <?= ($currRequired === 1) ? 'checked' : '' ?> style="margin-top:3px;accent-color:#ea580c">
            <div>
              <b style="color:#c2410c;font-size:13px;display:block">🔴 اجباری (الزامی)</b>
              <div style="font-size:11.5px;color:#64748b;margin-top:3px;line-height:1.5">
                کاربر باید حتماً حداقل یکی از گزینه‌ها را انتخاب یا تکمیل کند.
              </div>
            </div>
          </label>

          <label style="display:flex;align-items:flex-start;gap:10px;padding:12px;background:#fff;border:2px solid <?= ($currRequired === 0) ? '#0284c7' : '#e2e8f0' ?>;border-radius:10px;cursor:pointer">
            <input type="radio" name="required" value="0" <?= ($currRequired === 0) ? 'checked' : '' ?> style="margin-top:3px;accent-color:#0284c7">
            <div>
              <b style="color:#0369a1;font-size:13px;display:block">⚪ اختیاری (غیر اجباری)</b>
              <div style="font-size:11.5px;color:#64748b;margin-top:3px;line-height:1.5">
                کاربر در تکمیل این فیلد مختار است و می‌تواند بدون وارد کردن مقدار ادامه دهد.
              </div>
            </div>
          </label>
        </div>
      </div>

      <!-- راهنما و پلیس‌هولدر -->
      <div class="fr-grid cols-2" style="margin-bottom:14px">
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">متن پیش‌فرض (Placeholder):</label>
          <input type="text" name="placeholder" class="fr-input" style="width:100%"
                 value="<?= e($editField['placeholder'] ?? '') ?>" placeholder="مثال: انتخاب گزینه‌ها...">
        </div>
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">متن راهنمای زیر فیلد:</label>
          <input type="text" name="help_text" class="fr-input" style="width:100%"
                 value="<?= e($editField['help_text'] ?? '') ?>" placeholder="توضیح کوتاه کم‌رنگ">
        </div>
      </div>

      <!-- تنظیمات نمایش در نسخه رایگان/ویژه و فعال‌بودن -->
      <div style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:16px">
        <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:#1e293b">⚙️ گزینه‌های انتشار و حالت آزمون:</div>
        <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
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
          <b style="font-size:13.5px;color:#1e40af">منطق شرطی پیشرفته (Gravity Forms Conditional Logic)</b>
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
                  <option value="<?= e($opK) ?>" <?= (($editField['condition_operator'] ?? 'equals') === $opK) ? 'selected' : '' ?>>
                    <?= e($opLbl) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:4px;color:#1e3a8a">مقدار مورد مقایسه (Value):</label>
              <input type="text" name="condition_value" class="fr-input" style="width:100%"
                     value="<?= e($editField['condition_value'] ?? '') ?>" placeholder="مثال: yes یا individual">
            </div>
          </div>
          <div style="font-size:11.5px;color:#64748b;line-height:1.6">
            💡 اگر فیلد والد چک‌باکس چندگزینه‌ای است، با عملگر <code>contains</code> می‌توانید بررسی کنید آیا گزینه موردنظر تیک خورده است یا خیر.
          </div>
        </div>
      </div>

      <div style="margin-bottom:16px">
        <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">ترتیب نمایش (Sort Order):</label>
        <input type="number" name="sort_order" class="fr-input" style="width:120px"
               value="<?= (int)($editField['sort_order'] ?? 0) ?>">
      </div>

      <button type="submit" class="fr-btn primary" style="width:100%;padding:12px;font-size:15px;font-weight:700">
        <?= $editField ? '💾 ذخیره تغییرات فیلد' : '➕ ثبت و ایجاد فیلد سفارشی' ?>
      </button>
    </form>
  </div>

  <!-- جدول لیست فیلدهای تعریف‌شده -->
  <div>
    <div class="fr-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3 style="margin:0;font-size:16px">📋 فیلدهای تعریف‌شده (<?= fa_num(count($fields)) ?> فیلد)</h3>
        <?php if ($filterTestId > 0): ?>
          <span class="fr-chip purple sm">فیلتر آزمون فعال است</span>
        <?php endif; ?>
      </div>

      <?php if (empty($fields)): ?>
        <div style="text-align:center;padding:30px 10px;color:#64748b">
          هنوز فیلد سفارشی برای این آزمون ثبت نشده است. از فرم روبرو فیلد جدید ایجاد نمایید.
        </div>
      <?php else: ?>
        <div style="overflow-x:auto">
          <table class="fr-table" style="font-size:13px;width:100%">
            <thead>
              <tr style="background:#f1f5f9;text-align:right">
                <th style="width:36px">#</th>
                <th>عنوان و نام ماشینی</th>
                <th>نوع ورودی</th>
                <th>گام اجرا</th>
                <th>دامنه تست</th>
                <th>منطق شرطی</th>
                <th style="text-align:center">الزام</th>
                <th style="text-align:center">وضعیت</th>
                <th style="text-align:left;width:90px">عملیات</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($fields as $idx => $f):
                  $isCond = !empty($f['condition_field']);
                  $isReq  = !empty($f['required']);
                  $opts   = TestFields::parseOptions($f['options'] ?? null);
              ?>
              <tr style="<?= empty($f['active']) ? 'opacity:0.6;background:#fcfcfc' : '' ?>">
                <td><?= fa_num($f['sort_order']) ?></td>
                <td>
                  <div style="font-weight:700;color:#0f172a"><?= e($f['label']) ?></div>
                  <div style="font-size:11.5px;color:#64748b;font-family:monospace;direction:ltr;text-align:right">
                    <?= e($f['name']) ?>
                  </div>
                </td>
                <td>
                  <span class="fr-chip sm purple" style="font-size:11px">
                    <?= e($fieldTypes[$f['type']] ?? $f['type']) ?>
                  </span>
                  <?php if (!empty($opts)): ?>
                    <div style="font-size:10.5px;color:#64748b;margin-top:2px">
                      <?= fa_num(count($opts)) ?> گزینه
                    </div>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="toggle_stage">
                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="test_id" value="<?= (int)$filterTestId ?>">
                    <input type="hidden" name="filter_req" value="<?= $filterReq !== null ? (int)$filterReq : '' ?>">
                    <input type="hidden" name="filter_stage" value="<?= e($filterStage) ?>">
                    <?php if ($f['display_stage'] === 'post_test'): ?>
                      <button type="submit" class="fr-chip sm" style="border:none;cursor:pointer;background:#f3e8ff;color:#7e22ce;font-size:11.5px;font-weight:700;transition:all .15s" title="گام فعلی: پایان آزمون — کلیک برای تغییر به: آغاز آزمون">
                        🏁 پایان آزمون ⟳
                      </button>
                    <?php elseif ($f['display_stage'] === 'pre_test'): ?>
                      <button type="submit" class="fr-chip sm" style="border:none;cursor:pointer;background:#dcfce7;color:#15803d;font-size:11.5px;font-weight:700;transition:all .15s" title="گام فعلی: آغاز آزمون — کلیک برای تغییر به: هر دو مرحله">
                        🚀 آغاز آزمون ⟳
                      </button>
                    <?php else: ?>
                      <button type="submit" class="fr-chip sm info" style="border:none;cursor:pointer;font-size:11.5px;font-weight:700;transition:all .15s" title="گام فعلی: هر دو مرحله — کلیک برای تغییر به: پایان آزمون">
                        🔄 هر دو مرحله ⟳
                      </button>
                    <?php endif; ?>
                  </form>
                </td>
                <td>
                  <?php if ((int)$f['test_id'] === 0): ?>
                    <span class="fr-chip info sm" style="font-size:11px">🌐 همه تست‌ها</span>
                  <?php else: ?>
                    <span class="fr-chip sm" style="font-size:11px;background:#f1f5f9;color:#334155">
                      <?= e($f['test_title'] ?? ('تست #' . $f['test_id'])) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isCond): ?>
                    <div style="font-size:11.5px;color:#2563eb;line-height:1.5">
                      🔀 وابسته به <code><?= e($f['condition_field']) ?></code><br>
                      <span style="font-size:10.5px;color:#64748b"><?= e($f['condition_operator']) ?>: <?= e($f['condition_value']) ?></span>
                    </div>
                  <?php else: ?>
                    <span style="font-size:12px;color:#94a3b8">— بدون شرط</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="toggle_required">
                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                    <input type="hidden" name="test_id" value="<?= (int)$filterTestId ?>">
                    <input type="hidden" name="filter_req" value="<?= $filterReq !== null ? (int)$filterReq : '' ?>">
                    <?php if ($isReq): ?>
                      <button type="submit" class="fr-chip sm danger" style="border:none;cursor:pointer;font-size:11px;font-weight:700" title="تغییر به اختیاری">
                        🔴 اجباری
                      </button>
                    <?php else: ?>
                      <button type="submit" class="fr-chip sm" style="border:none;cursor:pointer;font-size:11px;background:#f1f5f9;color:#475569" title="تغییر به اجباری">
                        ⚪ اختیاری
                      </button>
                    <?php endif; ?>
                  </form>
                </td>
                <td style="text-align:center">
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
    const hasChoices = ['checkbox', 'radio', 'select', 'multiselect'].includes(type);
    box.style.display = hasChoices ? 'block' : 'none';
}

function toggleConditionBox() {
    const chk = document.getElementById('chkHasCond').checked;
    const box = document.getElementById('boxCondSettings');
    box.style.display = chk ? 'block' : 'none';
}

function addChoiceRow(key = '', label = '') {
    const list = document.getElementById('choicesList');
    const div = document.createElement('div');
    div.className = 'choice-row';
    div.style.cssText = 'display:flex;gap:8px;align-items:center';
    div.innerHTML = `
      <input type="text" name="choice_keys[]" class="fr-input ch-key" placeholder="شناسه (Value)" dir="ltr" style="width:120px;font-family:monospace;font-size:12px" value="${key}">
      <input type="text" name="choice_labels[]" class="fr-input ch-label" placeholder="عنوان نمایشی (Label)" style="flex:1;font-size:13px" value="${label}">
      <button type="button" class="fr-btn sm ghost" onclick="removeChoiceRow(this)" style="color:#ef4444;padding:4px 8px" title="حذف">🗑</button>
    `;
    list.appendChild(div);
    div.querySelector('.ch-label').focus();
}

function removeChoiceRow(btn) {
    const row = btn.closest('.choice-row');
    const list = document.getElementById('choicesList');
    if (list.children.length > 1) {
        row.remove();
    } else {
        row.querySelector('.ch-key').value = '';
        row.querySelector('.ch-label').value = '';
    }
}

function toggleBulkEditor() {
    const repWrap = document.getElementById('choicesRepeaterWrap');
    const bulkWrap = document.getElementById('choicesBulkWrap');
    const ta = document.getElementById('taChoicesBulk');

    if (bulkWrap.style.display === 'none') {
        // همگام‌سازی از ریپیتر به تکست‌آریا
        const rows = document.querySelectorAll('#choicesList .choice-row');
        const lines = [];
        rows.forEach(r => {
            const k = r.querySelector('.ch-key').value.trim();
            const l = r.querySelector('.ch-label').value.trim();
            if (k || l) {
                lines.push(k ? `${k}: ${l || k}` : l);
            }
        });
        ta.value = lines.join('\n');
        repWrap.style.display = 'none';
        bulkWrap.style.display = 'block';
    } else {
        // همگام‌سازی از تکست‌آریا به ریپیتر
        const lines = ta.value.split('\n').map(s => s.trim()).filter(Boolean);
        const list = document.getElementById('choicesList');
        list.innerHTML = '';
        if (!lines.length) {
            addChoiceRow('', '');
        } else {
            lines.forEach(line => {
                if (line.includes(':')) {
                    const parts = line.split(':');
                    addChoiceRow(parts[0].trim(), parts.slice(1).join(':').trim());
                } else {
                    addChoiceRow(line, line);
                }
            });
        }
        bulkWrap.style.display = 'none';
        repWrap.style.display = 'block';
    }
}
</script>

<?php admin_layout_end(); ?>
