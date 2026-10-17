<?php
/**
 * ============================================================================
 *  مدیریت فیلدهای سفارشی و داینامیک تست‌های روان‌شناسی (Test Custom Fields Engine)
 *  ─ نگارش ۳٫۵٫۲: فیلدهای دلخواه، پشتیبانی از انواع ورودی و منطق شرطی بلادرنگ
 *  ─ اعتبارسنجی دوطرفه (کلاینت و سرور)، ذخیره ساختاریافته و نمایش در پنل و پرونده بالینی
 * ============================================================================
 */
declare(strict_types=1);

class TestFields
{
    /**
     * جدول فیلدهای سفارشی تست‌ها
     */
    public static function table(): string
    {
        return DB::t('test_fields');
    }

    /**
     * اطمینان از وجود ساختار پایگاه‌داده (Self-Healing Schema)
     */
    public static function ensureSchema(): void
    {
        static $ensured = false;
        if ($ensured) return;

        // ۱. ایجاد جدول اختصاصی test_fields در صورت عدم وجود
        DB::run("CREATE TABLE IF NOT EXISTS " . self::table() . " (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `test_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `label` VARCHAR(255) NOT NULL,
            `name` VARCHAR(60) NOT NULL,
            `type` VARCHAR(30) NOT NULL DEFAULT 'text',
            `options` MEDIUMTEXT NULL,
            `placeholder` VARCHAR(255) NULL,
            `help_text` VARCHAR(255) NULL,
            `required` TINYINT(1) NOT NULL DEFAULT 0,
            `condition_field` VARCHAR(60) NULL,
            `condition_operator` VARCHAR(20) NOT NULL DEFAULT 'equals',
            `condition_value` VARCHAR(255) NULL,
            `show_in_free` TINYINT(1) NOT NULL DEFAULT 1,
            `show_in_full` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` INT NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_test_active` (`test_id`, `active`, `sort_order`),
            INDEX `idx_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ۲. افزودن ستون custom_fields_json به جدول attempts در صورت عدم وجود
        $hasColAttempts = DB::one("SHOW COLUMNS FROM " . DB::t('attempts') . " LIKE 'custom_fields_json'");
        if (!$hasColAttempts) {
            try {
                DB::run("ALTER TABLE " . DB::t('attempts') . " ADD COLUMN `custom_fields_json` MEDIUMTEXT NULL AFTER `validity_json`");
            } catch (Throwable $e) {
                error_log('TestFields migration attempts: ' . $e->getMessage());
            }
        }

        // ۳. افزودن ستون custom_fields_json به جدول orders در صورت عدم وجود
        $hasColOrders = DB::one("SHOW COLUMNS FROM " . DB::t('orders') . " LIKE 'custom_fields_json'");
        if (!$hasColOrders) {
            try {
                DB::run("ALTER TABLE " . DB::t('orders') . " ADD COLUMN `custom_fields_json` MEDIUMTEXT NULL AFTER `payable`");
            } catch (Throwable $e) {
                error_log('TestFields migration orders: ' . $e->getMessage());
            }
        }

        // ۴. کاشت بذر پیش‌فرض (سناریوی واقعی مشاوره رایگان و همراه) در صورت خالی بودن جدول
        $count = (int)DB::val("SELECT COUNT(*) FROM " . self::table());
        if ($count === 0) {
            self::seedDefaultFields();
        }

        $ensured = true;
    }

    /**
     * بذر اولیه فیلدهای نمونه شامل منطق شرطی درخواست همراه و مشاوره
     */
    public static function seedDefaultFields(): void
    {
        $fields = [
            [
                'test_id'            => 0, // همه تست‌ها
                'label'              => 'آیا به جلسه مشاوره رایگان پیش یا پس از تحلیل نیاز دارید؟',
                'name'               => 'free_consultation',
                'type'               => 'select',
                'options'            => json_encode([
                    ['key' => 'no',  'label' => 'خیر، نیازی ندارم'],
                    ['key' => 'yes', 'label' => 'بله، تمایل به دریافت جلسه مشاوره رایگان دارم'],
                ], JSON_UNESCAPED_UNICODE),
                'placeholder'        => 'انتخاب گزینه...',
                'help_text'          => 'مشاوران فریا روان می‌توانند پس از اتمام آزمون، راهنمایی اولیه را به شما ارائه دهند.',
                'required'           => 1,
                'condition_field'    => null,
                'condition_operator' => 'equals',
                'condition_value'    => null,
                'show_in_free'       => 1,
                'show_in_full'       => 1,
                'sort_order'         => 1,
                'active'             => 1,
            ],
            [
                'test_id'            => 0, // همه تست‌ها
                'label'              => 'آیا در این فرآیند ارزیابی و آزمون همراه دارید؟',
                'name'               => 'has_companion',
                'type'               => 'select',
                'options'            => json_encode([
                    ['key' => 'no',  'label' => 'خیر، به تنهایی شرکت می‌کنم'],
                    ['key' => 'yes', 'label' => 'بله، همراه دارم'],
                ], JSON_UNESCAPED_UNICODE),
                'placeholder'        => 'انتخاب گزینه...',
                'help_text'          => 'در صورت انتخاب بله، اطلاعات همراه را در کادرهای زیر وارد کنید.',
                'required'           => 1,
                'condition_field'    => null,
                'condition_operator' => 'equals',
                'condition_value'    => null,
                'show_in_free'       => 1,
                'show_in_full'       => 1,
                'sort_order'         => 2,
                'active'             => 1,
            ],
            [
                'test_id'            => 0,
                'label'              => 'نام و نام خانوادگی همراه',
                'name'               => 'companion_name',
                'type'               => 'text',
                'options'            => null,
                'placeholder'        => 'مثال: مریم رضایی',
                'help_text'          => 'نام همراه شما در پرونده مشترک ثبت خواهد شد.',
                'required'           => 1,
                'condition_field'    => 'has_companion',
                'condition_operator' => 'in',
                'condition_value'    => 'yes,بله,بله، همراه دارم',
                'show_in_free'       => 1,
                'show_in_full'       => 1,
                'sort_order'         => 3,
                'active'             => 1,
            ],
            [
                'test_id'            => 0,
                'label'              => 'شماره تلفن همراهِ همراه',
                'name'               => 'companion_phone',
                'type'               => 'tel',
                'options'            => null,
                'placeholder'        => 'مثال: 09123456789',
                'help_text'          => 'جهت اطلاع‌رسانی و ثبت آزمون همراه استفاده می‌شود.',
                'required'           => 1,
                'condition_field'    => 'has_companion',
                'condition_operator' => 'in',
                'condition_value'    => 'yes,بله,بله، همراه دارم',
                'show_in_free'       => 1,
                'show_in_full'       => 1,
                'sort_order'         => 4,
                'active'             => 1,
            ],
        ];

        foreach ($fields as $f) {
            DB::insert('test_fields', $f);
        }
    }

    /**
     * واکشی تمام فیلدهای فعال متناسب با تست و حالت (رایگان/ویژه)
     */
    public static function getFieldsForTest(int $testId, string $mode = 'all'): array
    {
        self::ensureSchema();

        $params = [$testId];
        $sql = "SELECT * FROM " . self::table() . " WHERE active=1 AND (test_id=0 OR test_id=?)";

        if ($mode === 'free') {
            $sql .= " AND show_in_free=1";
        } elseif ($mode === 'full') {
            $sql .= " AND show_in_full=1";
        }

        $sql .= " ORDER BY sort_order ASC, id ASC";

        $rows = DB::all($sql, $params);
        foreach ($rows as &$r) {
            if (!empty($r['options']) && is_string($r['options'])) {
                $decoded = json_decode($r['options'], true);
                $r['parsed_options'] = is_array($decoded) ? $decoded : [];
            } else {
                $r['parsed_options'] = [];
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * رندر HTML کادرهای فرم ورودی فیلدها با اتریبیوت‌های شرطی
     */
    public static function renderFormFields(array $fields, array $currentValues = []): string
    {
        if (empty($fields)) return '';

        ob_start();
        ?>
        <div class="fr-test-custom-fields-box" id="testCustomFieldsBox">
          <div class="fr-custom-fields-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px">
            <?php foreach ($fields as $f):
                $fieldName = (string)$f['name'];
                $val = $currentValues[$fieldName] ?? '';
                $type = (string)$f['type'];
                $req = (int)$f['required'] === 1;
                $hasCond = !empty($f['condition_field']);
                $condField = (string)($f['condition_field'] ?? '');
                $condOp = (string)($f['condition_operator'] ?? 'equals');
                $condVal = (string)($f['condition_value'] ?? '');
                $wrapId = 'wrap_tf_' . $fieldName;
            ?>
            <div class="fr-test-field-wrap"
                 id="<?= e($wrapId) ?>"
                 data-field-name="<?= e($fieldName) ?>"
                 <?= $hasCond ? 'data-cond-field="' . e($condField) . '"' : '' ?>
                 <?= $hasCond ? 'data-cond-op="' . e($condOp) . '"' : '' ?>
                 <?= $hasCond ? 'data-cond-val="' . e($condVal) . '"' : '' ?>
                 style="position:relative;transition:all 0.25s ease-in-out">

              <label for="tf_<?= e($fieldName) ?>" style="display:block;font-size:13.5px;font-weight:700;color:#1e293b;margin-bottom:6px">
                <?= e($f['label']) ?>
                <?php if ($req): ?>
                  <span class="req-star" style="color:#ef4444;font-weight:700;margin-right:2px" title="تکمیل این فیلد اجباری است">*</span>
                  <span class="tf-badge-req" style="display:inline-block;background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:1px 6px;border-radius:4px;margin-right:4px">الزامی</span>
                <?php else: ?>
                  <span class="tf-badge-opt" style="display:inline-block;background:#f1f5f9;color:#64748b;font-size:10px;font-weight:500;padding:1px 6px;border-radius:4px;margin-right:4px">اختیاری</span>
                <?php endif; ?>
              </label>

              <?php if ($type === 'select'): ?>
                <select name="test_custom_fields[<?= e($fieldName) ?>]"
                        id="tf_<?= e($fieldName) ?>"
                        class="fr-input fr-select tf-input"
                        data-field-name="<?= e($fieldName) ?>"
                        data-required="<?= $req ? '1' : '0' ?>"
                        style="width:100%;height:42px;border-radius:10px;border:1.5px solid #cbd5e1;background:#fff;padding:0 12px;font-family:inherit">
                  <option value=""><?= e($f['placeholder'] ?: '— انتخاب کنید —') ?></option>
                  <?php foreach ($f['parsed_options'] as $opt):
                      $k = is_array($opt) ? ($opt['key'] ?? '') : (string)$opt;
                      $lbl = is_array($opt) ? ($opt['label'] ?? $k) : (string)$opt;
                      $selected = ($val !== '' && ($val === $k || $val === $lbl)) ? 'selected' : '';
                  ?>
                    <option value="<?= e($k) ?>" <?= $selected ?>><?= e($lbl) ?></option>
                  <?php endforeach; ?>
                </select>

              <?php elseif ($type === 'checkbox'): ?>
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:8px 0;font-size:13px;color:#334155">
                  <input type="checkbox"
                         name="test_custom_fields[<?= e($fieldName) ?>]"
                         id="tf_<?= e($fieldName) ?>"
                         class="tf-input"
                         data-field-name="<?= e($fieldName) ?>"
                         data-required="<?= $req ? '1' : '0' ?>"
                         value="1"
                         <?= ($val === '1' || $val === 1 || $val === 'yes' || $val === true) ? 'checked' : '' ?>
                         style="width:18px;height:18px;accent-color:#7c3aed">
                  <span><?= e($f['placeholder'] ?: 'تأیید و انتخاب این مورد') ?></span>
                </label>

              <?php elseif ($type === 'radio'): ?>
                <div class="fr-radio-group" style="display:flex;gap:14px;flex-wrap:wrap;padding:6px 0">
                  <?php foreach ($f['parsed_options'] as $opt):
                      $k = is_array($opt) ? ($opt['key'] ?? '') : (string)$opt;
                      $lbl = is_array($opt) ? ($opt['label'] ?? $k) : (string)$opt;
                      $checked = ($val !== '' && ($val === $k || $val === $lbl)) ? 'checked' : '';
                  ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#334155">
                      <input type="radio"
                             name="test_custom_fields[<?= e($fieldName) ?>]"
                             value="<?= e($k) ?>"
                             class="tf-input"
                             data-field-name="<?= e($fieldName) ?>"
                             data-required="<?= $req ? '1' : '0' ?>"
                             <?= $checked ?>
                             style="width:16px;height:16px;accent-color:#7c3aed">
                      <span><?= e($lbl) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>

              <?php elseif ($type === 'textarea'): ?>
                <textarea name="test_custom_fields[<?= e($fieldName) ?>]"
                          id="tf_<?= e($fieldName) ?>"
                          class="fr-input tf-input"
                          data-field-name="<?= e($fieldName) ?>"
                          data-required="<?= $req ? '1' : '0' ?>"
                          placeholder="<?= e($f['placeholder'] ?? '') ?>"
                          rows="3"
                          style="width:100%;border-radius:10px;border:1.5px solid #cbd5e1;padding:10px 12px;font-family:inherit"><?= e((string)$val) ?></textarea>

              <?php elseif ($type === 'tel'): ?>
                <input type="tel"
                       name="test_custom_fields[<?= e($fieldName) ?>]"
                       id="tf_<?= e($fieldName) ?>"
                       class="fr-input tf-input"
                       data-field-name="<?= e($fieldName) ?>"
                       data-required="<?= $req ? '1' : '0' ?>"
                       value="<?= e((string)$val) ?>"
                       placeholder="<?= e($f['placeholder'] ?: '09123456789') ?>"
                       dir="ltr"
                       style="width:100%;height:42px;border-radius:10px;border:1.5px solid #cbd5e1;padding:0 12px;text-align:right;font-family:inherit">

              <?php elseif ($type === 'number'): ?>
                <input type="number"
                       name="test_custom_fields[<?= e($fieldName) ?>]"
                       id="tf_<?= e($fieldName) ?>"
                       class="fr-input tf-input"
                       data-field-name="<?= e($fieldName) ?>"
                       data-required="<?= $req ? '1' : '0' ?>"
                       value="<?= e((string)$val) ?>"
                       placeholder="<?= e($f['placeholder'] ?? '') ?>"
                       style="width:100%;height:42px;border-radius:10px;border:1.5px solid #cbd5e1;padding:0 12px;font-family:inherit">

              <?php else: // text ?>
                <input type="text"
                       name="test_custom_fields[<?= e($fieldName) ?>]"
                       id="tf_<?= e($fieldName) ?>"
                       class="fr-input tf-input"
                       data-field-name="<?= e($fieldName) ?>"
                       data-required="<?= $req ? '1' : '0' ?>"
                       value="<?= e((string)$val) ?>"
                       placeholder="<?= e($f['placeholder'] ?? '') ?>"
                       style="width:100%;height:42px;border-radius:10px;border:1.5px solid #cbd5e1;padding:0 12px;font-family:inherit">
              <?php endif; ?>

              <?php if (!empty($f['help_text'])): ?>
                <div style="font-size:11.5px;color:#64748b;margin-top:4px;line-height:1.7"><?= e($f['help_text']) ?></div>
              <?php endif; ?>
              <div class="field-error-msg" style="display:none;color:#ef4444;font-size:11.5px;margin-top:4px;font-weight:600"></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?= self::renderClientScript('#testCustomFieldsBox') ?>
        <?php
        return ob_get_clean();
    }

    /**
     * رندر اسکریپت پیشرفته اجرای منطق شرطی در مرورگر کاربر
     */
    public static function renderClientScript(string $containerSelector = '#testCustomFieldsBox'): string
    {
        ob_start();
        ?>
        <script>
        (function() {
            function initTestConditionalFields() {
                const box = document.querySelector('<?= $containerSelector ?>');
                if (!box) return;

                function getFieldValue(name) {
                    const inputs = box.querySelectorAll('[data-field-name="' + name + '"]');
                    if (!inputs.length) return '';
                    
                    const first = inputs[0];
                    if (first.type === 'radio') {
                        const checked = box.querySelector('[data-field-name="' + name + '"]:checked');
                        return checked ? checked.value.trim() : '';
                    }
                    if (first.type === 'checkbox') {
                        return first.checked ? '1' : '0';
                    }
                    return first.value.trim();
                }

                function evalCondition(parentVal, op, condVal) {
                    parentVal = (parentVal || '').toString().trim().toLowerCase();
                    condVal = (condVal || '').toString().trim().toLowerCase();
                    
                    switch (op) {
                        case 'equals':
                            return parentVal === condVal;
                        case 'not_equals':
                            return parentVal !== '' && parentVal !== condVal;
                        case 'checked':
                            return parentVal === '1' || parentVal === 'true' || parentVal === 'yes' || parentVal === 'on' || parentVal === 'بله';
                        case 'not_checked':
                            return parentVal === '0' || parentVal === 'false' || parentVal === 'off' || parentVal === '' || parentVal === 'no' || parentVal === 'خیر';
                        case 'contains':
                            return condVal !== '' && parentVal.indexOf(condVal) !== -1;
                        case 'in':
                            const list = condVal.split(',').map(s => s.trim().toLowerCase());
                            return list.includes(parentVal);
                        default:
                            return parentVal === condVal;
                    }
                }

                function applyConditions() {
                    const condWraps = box.querySelectorAll('.fr-test-field-wrap[data-cond-field]');
                    condWraps.forEach(wrap => {
                        const condField = wrap.getAttribute('data-cond-field');
                        const condOp = wrap.getAttribute('data-cond-op') || 'equals';
                        const condVal = wrap.getAttribute('data-cond-val') || '';
                        
                        const parentVal = getFieldValue(condField);
                        const isMatch = evalCondition(parentVal, condOp, condVal);

                        const inputs = wrap.querySelectorAll('input, select, textarea');
                        if (isMatch) {
                            wrap.style.display = 'block';
                            wrap.style.opacity = '1';
                            wrap.style.pointerEvents = 'auto';
                            inputs.forEach(inp => {
                                inp.disabled = false;
                            });
                        } else {
                            wrap.style.display = 'none';
                            wrap.style.opacity = '0';
                            wrap.style.pointerEvents = 'none';
                            wrap.classList.remove('has-error');
                            const errEl = wrap.querySelector('.field-error-msg');
                            if (errEl) errEl.style.display = 'none';
                            inputs.forEach(inp => {
                                inp.disabled = true;
                            });
                        }
                    });
                }

                // ثبت لیسنر روی تمام اینپوت‌ها برای واکنش آنی
                box.addEventListener('input', applyConditions);
                box.addEventListener('change', applyConditions);

                // اجرای اولیه در زمان لود صفحه
                applyConditions();

                // اتصال خودکار به فرم‌های شروع و پرداخت در صفحه
                function attachFormValidation(form) {
                    if (!form || form._tfAttached) return;
                    form._tfAttached = true;

                    form.addEventListener('submit', function(e) {
                        applyConditions();
                        let hasError = false;
                        let firstErrorInput = null;

                        const visibleWraps = box.querySelectorAll('.fr-test-field-wrap');
                        visibleWraps.forEach(wrap => {
                            if (wrap.style.display === 'none') return;

                            const inputs = wrap.querySelectorAll('input, select, textarea');
                            inputs.forEach(inp => {
                                const isReq = inp.getAttribute('data-required') === '1';
                                let val = inp.value ? inp.value.trim() : '';
                                if (inp.type === 'checkbox') val = inp.checked ? '1' : '';
                                if (inp.type === 'radio') {
                                    const radName = inp.getAttribute('data-field-name');
                                    const checked = box.querySelector('[data-field-name="' + radName + '"]:checked');
                                    val = checked ? checked.value : '';
                                }

                                const errEl = wrap.querySelector('.field-error-msg');
                                if (isReq && !val) {
                                    hasError = true;
                                    wrap.classList.add('has-error');
                                    if (inp.style) inp.style.borderColor = '#ef4444';
                                    if (errEl) {
                                        if (inp.type === 'checkbox') {
                                            errEl.innerText = 'تأیید و انتخاب این مورد الزامی است.';
                                        } else if (inp.type === 'radio' || inp.tagName === 'SELECT') {
                                            errEl.innerText = 'انتخاب یکی از گزینه‌ها الزامی است.';
                                        } else {
                                            errEl.innerText = 'تکمیل این فیلد الزامی است.';
                                        }
                                        errEl.style.display = 'block';
                                    }
                                    if (!firstErrorInput) firstErrorInput = inp;
                                } else {
                                    wrap.classList.remove('has-error');
                                    if (inp.style) inp.style.borderColor = '#cbd5e1';
                                    if (errEl) errEl.style.display = 'none';
                                }

                                // اعتبارسنجی تلفن همراه در صورت پر بودن
                                if (inp.type === 'tel' && val) {
                                    const cleanTel = val.replace(/[\s\-\(\)]/g, '');
                                    if (!/^(\+98|0)?9\d{9}$/.test(cleanTel) && !/^0\d{2,3}\d{7,8}$/.test(cleanTel)) {
                                        hasError = true;
                                        wrap.classList.add('has-error');
                                        if (inp.style) inp.style.borderColor = '#ef4444';
                                        if (errEl) {
                                            errEl.innerText = 'شماره تماس واردشده معتبر نیست (مثال: 09123456789).';
                                            errEl.style.display = 'block';
                                        }
                                        if (!firstErrorInput) firstErrorInput = inp;
                                    }
                                }
                            });
                        });

                        if (hasError) {
                            e.preventDefault();
                            if (firstErrorInput) {
                                firstErrorInput.focus();
                                firstErrorInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                            return false;
                        }

                        // همگام‌سازی فیلدهای فعال با فرم در حال سابمیت به عنوان Hidden
                        visibleWraps.forEach(wrap => {
                            if (wrap.style.display === 'none') return;
                            const inp = wrap.querySelector('input:not([type="radio"]), select, textarea');
                            const radios = wrap.querySelectorAll('input[type="radio"]:checked');

                            if (inp && inp.name && !inp.name.includes('[')) return;
                            if (inp && inp.name) {
                                let val = inp.value;
                                if (inp.type === 'checkbox') val = inp.checked ? '1' : '0';
                                syncHiddenToForm(form, inp.name, val);
                            }
                            radios.forEach(r => {
                                syncHiddenToForm(form, r.name, r.value);
                            });
                        });
                    });
                }

                function syncHiddenToForm(form, name, value) {
                    let existing = form.querySelector('input[type="hidden"][name="' + CSS.escape(name) + '"]');
                    if (!existing) {
                        existing = document.createElement('input');
                        existing.type = 'hidden';
                        existing.name = name;
                        form.appendChild(existing);
                    }
                    existing.value = value;
                }

                document.querySelectorAll('form[method="post"]').forEach(attachFormValidation);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTestConditionalFields);
            } else {
                initTestConditionalFields();
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * اعتبارسنجی امن سمت سرور با اجرای منطق شرطی
     */
    public static function validateAndProcess(int $testId, string $mode, array $postData): array
    {
        $fields = self::getFieldsForTest($testId, $mode);
        if (empty($fields)) {
            return ['ok' => true, 'errors' => [], 'data' => [], 'json' => null];
        }

        $submitted = (array)($postData['test_custom_fields'] ?? []);
        $responses = [];
        $errors    = [];

        // نقشه سریع مقادیر ارسالی برای بررسی شروط
        $valMap = [];
        foreach ($fields as $f) {
            $name = (string)$f['name'];
            $valMap[$name] = trim((string)($submitted[$name] ?? ''));
        }

        foreach ($fields as $f) {
            $name = (string)$f['name'];
            $label = (string)$f['label'];
            $type = (string)$f['type'];
            $rawVal = trim((string)($submitted[$name] ?? ''));
            $isReq = (int)$f['required'] === 1;

            // ۱. بررسی منطق شرطی (Conditional Logic)
            $isVisible = true;
            if (!empty($f['condition_field'])) {
                $condField = (string)$f['condition_field'];
                $condOp = (string)($f['condition_operator'] ?? 'equals');
                $condVal = (string)($f['condition_value'] ?? '');
                $parentVal = $valMap[$condField] ?? '';

                $isVisible = self::evaluateCondition($parentVal, $condOp, $condVal);
            }

            // اگر شرط برقرار نباشد، فیلد نامرئی بوده و اعتبارسنجی الزامی روی آن اعمال نمی‌شود
            if (!$isVisible) {
                continue;
            }

            // ۲. بررسی الزامی بودن (Required check)
            if ($isReq && $rawVal === '') {
                if ($type === 'checkbox') {
                    $errors[] = "تأیید و انتخاب گزینه «{$label}» الزامی است.";
                } elseif ($type === 'radio' || $type === 'select') {
                    $errors[] = "انتخاب گزینه‌ای برای فیلد «{$label}» الزامی است.";
                } else {
                    $errors[] = "تکمیل فیلد «{$label}» الزامی است.";
                }
                continue;
            }

            // اگر فیلد غیر اجباری (اختیاری) باشد و مقداری وارد نشده باشد، بدون خطا عبور می‌کند
            if (!$isReq && $rawVal === '') {
                continue;
            }

            // ۳. اعتبارسنجی فرمت‌های خاص
            if ($type === 'tel' && $rawVal !== '') {
                $cleanTel = preg_replace('/[\s\-\(\)]/', '', $rawVal);
                if (!preg_match('/^(\+98|0)?9\d{9}$/', $cleanTel) && !preg_match('/^0\d{2,3}\d{7,8}$/', $cleanTel)) {
                    $errors[] = "شماره تماس واردشده برای «{$label}» معتبر نیست.";
                    continue;
                }
            }

            if ($type === 'number' && $rawVal !== '') {
                if (!is_numeric($rawVal)) {
                    $errors[] = "مقدار فیلد «{$label}» باید عدد باشد.";
                    continue;
                }
            }

            // ۴. برچسب‌گذاری و تبدیل مقادیر گزینه‌ای (Select / Radio)
            $displayValue = $rawVal;
            if (!empty($f['parsed_options']) && ($type === 'select' || $type === 'radio')) {
                foreach ($f['parsed_options'] as $opt) {
                    $k = is_array($opt) ? ($opt['key'] ?? '') : (string)$opt;
                    $lbl = is_array($opt) ? ($opt['label'] ?? $k) : (string)$opt;
                    if ($rawVal === $k || $rawVal === $lbl) {
                        $displayValue = $lbl;
                        break;
                    }
                }
            } elseif ($type === 'checkbox') {
                $displayValue = ($rawVal === '1' || $rawVal === 1) ? 'بله' : 'خیر';
            }

            $responses[$name] = [
                'name'    => $name,
                'label'   => $label,
                'type'    => $type,
                'value'   => $displayValue,
                'raw'     => $rawVal,
            ];
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors, 'data' => [], 'json' => null];
        }

        $json = !empty($responses) ? json_encode($responses, JSON_UNESCAPED_UNICODE) : null;
        return ['ok' => true, 'errors' => [], 'data' => $responses, 'json' => $json];
    }

    /**
     * ارزیابی یک شرط بین مقدار والد و عملگر/مقدار شرطی
     */
    public static function evaluateCondition(mixed $parentVal, string $operator, ?string $condVal): bool
    {
        $p = mb_strtolower(trim((string)$parentVal));
        $c = mb_strtolower(trim((string)$condVal));

        return match ($operator) {
            'equals' => ($p === $c),
            'not_equals' => ($p !== $c && $p !== ''),
            'checked' => in_array($p, ['1', 'true', 'yes', 'on', 'checked', 'بله'], true),
            'not_checked' => in_array($p, ['0', 'false', 'off', '', 'no', 'خیر'], true),
            'contains' => ($c !== '' && str_contains($p, $c)),
            'in' => in_array($p, array_map('trim', explode(',', $c)), true),
            default => ($p === $c),
        };
    }

    /**
     * رندر کارت ویژوال نمایش اطلاعات پیش از آزمون در پنل مدیریت، گزارش و پرونده بالینی
     */
    public static function renderDisplayCard(array|string|null $customFieldsJson, string $title = '📋 اطلاعات و گزینه‌های تکمیلی پیش از آزمون'): string
    {
        if (empty($customFieldsJson)) return '';

        $data = is_array($customFieldsJson) ? $customFieldsJson : json_decode((string)$customFieldsJson, true);
        if (empty($data) || !is_array($data)) return '';

        ob_start();
        ?>
        <div class="fr-card fr-pretest-data-card" style="margin-bottom:24px;border:1.5px solid #cbd5e1;background:#f8fafc;border-radius:14px;padding:18px 22px">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
            <span style="font-size:22px">📋</span>
            <h3 style="margin:0;font-size:15.5px;color:#1e3a8a"><?= e($title) ?></h3>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:14px;font-size:13.5px">
            <?php foreach ($data as $k => $item):
                $label = is_array($item) ? ($item['label'] ?? $k) : $k;
                $val   = is_array($item) ? ($item['value'] ?? '') : (string)$item;
                $type  = is_array($item) ? ($item['type'] ?? 'text') : 'text';
            ?>
              <div style="background:#fff;border-radius:10px;padding:12px 14px;border:1px solid #e2e8f0;line-height:1.9">
                <div style="color:#64748b;font-size:12px;margin-bottom:2px"><?= e($label) ?></div>
                <div style="font-weight:700;color:#0f172a">
                  <?php if ($type === 'tel' && $val): ?>
                    <a href="tel:<?= e($val) ?>" dir="ltr" style="color:#2563eb;text-decoration:none">📱 <?= fa_num($val) ?></a>
                  <?php elseif ($val === 'بله' || $val === '1'): ?>
                    <span class="fr-chip ok sm" style="font-size:11.5px">✓ <?= e($val) ?></span>
                  <?php elseif ($val === 'خیر' || $val === '0'): ?>
                    <span class="fr-chip info sm" style="font-size:11.5px"><?= e($val) ?></span>
                  <?php else: ?>
                    <?= e((string)$val) ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
