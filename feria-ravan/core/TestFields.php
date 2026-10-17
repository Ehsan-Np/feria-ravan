<?php
/**
 * ============================================================================
 *  مدیریت فیلدهای سفارشی و داینامیک تست‌های روان‌شناسی (Test Custom Fields Engine)
 *  ─ نگارش ۳٫۹٫۰: فیلدهای دلخواه به سبک Pronamic / Gravity Forms
 *  ─ پشتیبانی کامل از چک‌باکس چندگزینه‌ای (انتخاب همزمان N گزینه با ذخیره آرایه/JSON)
 *  ─ رادیوباتن با گزینه‌های دلخواه، لیست‌های کشویی و منطق شرطی بلادرنگ
 *  ─ زمان‌بندی و گام اجرا: پایان آزمون (post_test)، آغاز آزمون (pre_test) یا هر دو (both)
 *  ─ اعتبارسنجی بلادرنگ کلاینت و سرور، نمایش کارنامه، پنل و پرونده بالینی
 * ============================================================================
 */
declare(strict_types=1);

class TestFields
{
    /**
     * نام جدول فیلدهای سفارشی تست‌ها
     */
    public static function table(): string
    {
        return DB::t('test_fields');
    }

    /**
     * اطمینان از وجود و به‌روزرسانی ساختار پایگاه‌داده (Self-Healing Schema)
     */
    public static function ensureSchema(): void
    {
        static $ensured = false;
        if ($ensured) return;

        // ۱. ایجاد یا ارتقای جدول اختصاصی test_fields
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
            `display_stage` VARCHAR(20) NOT NULL DEFAULT 'post_test',
            `sort_order` INT NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_test_active` (`test_id`, `active`, `sort_order`),
            INDEX `idx_test_stage` (`test_id`, `display_stage`, `active`),
            INDEX `idx_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ۲. ارتقای ستون display_stage در صورت عدم وجود
        $hasColStage = DB::one("SHOW COLUMNS FROM " . self::table() . " LIKE 'display_stage'");
        if (!$hasColStage) {
            try {
                DB::run("ALTER TABLE " . self::table() . " ADD COLUMN `display_stage` VARCHAR(20) NOT NULL DEFAULT 'post_test' AFTER `show_in_full`");
                DB::run("ALTER TABLE " . self::table() . " ADD INDEX `idx_test_stage` (`test_id`, `display_stage`, `active`)");
            } catch (Throwable $e) {
                error_log('TestFields display_stage migration: ' . $e->getMessage());
            }
        }

        // ۳. اطمینان از وجود ستون custom_fields_json در جدول attempts
        $hasColAttempts = DB::one("SHOW COLUMNS FROM " . DB::t('attempts') . " LIKE 'custom_fields_json'");
        if (!$hasColAttempts) {
            try {
                DB::run("ALTER TABLE " . DB::t('attempts') . " ADD COLUMN `custom_fields_json` MEDIUMTEXT NULL AFTER `validity_json`");
            } catch (Throwable $e) {
                error_log('TestFields migration attempts: ' . $e->getMessage());
            }
        }

        // ۴. اطمینان از وجود ستون custom_fields_json در جدول orders
        $hasColOrders = DB::one("SHOW COLUMNS FROM " . DB::t('orders') . " LIKE 'custom_fields_json'");
        if (!$hasColOrders) {
            try {
                DB::run("ALTER TABLE " . DB::t('orders') . " ADD COLUMN `custom_fields_json` MEDIUMTEXT NULL AFTER `payable`");
            } catch (Throwable $e) {
                error_log('TestFields migration orders: ' . $e->getMessage());
            }
        }

        // ۵. تنظیم گام‌های اجرای فیلدهای از پیش موجود
        try {
            DB::run("UPDATE " . self::table() . " SET display_stage='post_test' WHERE name='free_consultation' AND display_stage='pre_test'");
            DB::run("UPDATE " . self::table() . " SET display_stage='pre_test' WHERE name IN ('has_companion','companion_name','companion_phone')");
        } catch (Throwable $e) {}

        // ۶. کاشت بذر پیش‌فرض (مشاوره رایگان و همراه) در صورت خالی بودن جدول
        $count = (int)DB::val("SELECT COUNT(*) FROM " . self::table());
        if ($count === 0) {
            self::seedDefaultFields();
        }

        $ensured = true;
    }

    /**
     * کاشت بذر اولیه فیلدها شامل چک‌باکس چندگزینه‌ای مشاوره رایگان پایان آزمون
     */
    public static function seedDefaultFields(): void
    {
        $fields = [
            [
                'test_id'            => 0, // همه آزمون‌ها
                'label'              => 'آیا به جلسه مشاوره رایگان تخصصی در مورد نتایج آزمون نیاز دارید؟',
                'name'               => 'free_consultation',
                'type'               => 'checkbox', // چک‌باکس چند گزینه‌ای
                'options'            => json_encode([
                    ['key' => 'yes_individual', 'label' => 'بله، تمایل به مشاوره فردی و بررسی کارنامه آزمون دارم'],
                    ['key' => 'yes_career',     'label' => 'بله، تمایل به مشاوره شغلی و هدایت مسیر حرفه‌ای دارم'],
                    ['key' => 'yes_family',     'label' => 'بله، تمایل به مشاوره زوجین و روانشناسی خانواده دارم'],
                    ['key' => 'no',             'label' => 'خیر، در حال حاضر نیازی به مشاوره رایگان ندارم'],
                ], JSON_UNESCAPED_UNICODE),
                'placeholder'        => 'انتخاب یک یا چند گزینه...',
                'help_text'          => 'مشاوران متخصص فریا روان می‌توانند پس از اتمام تست، نتایج و کارنامه شما را تحلیل و راهنمایی اولیه ارائه دهند.',
                'required'           => 1,
                'condition_field'    => null,
                'condition_operator' => 'equals',
                'condition_value'    => null,
                'show_in_free'       => 1,
                'show_in_full'       => 1,
                'display_stage'      => 'post_test', // پایان آزمون
                'sort_order'         => 1,
                'active'             => 1,
            ],
            [
                'test_id'            => 0,
                'label'              => 'زمان پیشنهادی شما برای تماس کارشناس مشاور',
                'name'               => 'consultation_time',
                'type'               => 'radio', // رادیوباتن تک انتخابی
                'options'            => json_encode([
                    ['key' => 'morning', 'label' => 'صبح (ساعت ۹ الی ۱۳)'],
                    ['key' => 'evening', 'label' => 'عصر (ساعت ۱۵ الی ۱۹)'],
                    ['key' => 'anytime', 'label' => 'هر ساعتی در روزهای کاری'],
                ], JSON_UNESCAPED_UNICODE),
                'placeholder'        => 'انتخاب زمان',
                'help_text'          => 'تماس هماهنگی مشاوره در این بازه زمانی با شما برقرار خواهد شد.',
                'required'           => 1,
                'condition_field'    => 'free_consultation',
                'condition_operator' => 'contains',
                'condition_value'    => 'yes',
                'show_in_free'       => 1,
                'show_in_full'       => 1,
                'display_stage'      => 'post_test',
                'sort_order'         => 2,
                'active'             => 1,
            ],
            [
                'test_id'            => 0,
                'label'              => 'توضیحات تکمیلی یا دغدغه اصلی برای مشاور (اختیاری)',
                'name'               => 'consultation_notes',
                'type'               => 'textarea',
                'options'            => null,
                'placeholder'        => 'اگر موضوع یا سؤال خاصی مدنظر دارید اینجا بنویسید...',
                'help_text'          => 'این توضیحات به مشاور پرونده کمک می‌کند تا جلسه هدفمندتری داشته باشید.',
                'required'           => 0,
                'condition_field'    => 'free_consultation',
                'condition_operator' => 'contains',
                'condition_value'    => 'yes',
                'show_in_free'       => 1,
                'show_in_full'       => 1,
                'display_stage'      => 'post_test',
                'sort_order'         => 3,
                'active'             => 1,
            ],
            [
                'test_id'            => 0,
                'label'              => 'آیا در این آزمون همراه یا زوج دارید؟',
                'name'               => 'has_companion',
                'type'               => 'select',
                'options'            => json_encode([
                    ['key' => 'no',  'label' => 'خیر، به تنهایی شرکت می‌کنم'],
                    ['key' => 'yes', 'label' => 'بله، همراه دارم'],
                ], JSON_UNESCAPED_UNICODE),
                'placeholder'        => 'انتخاب کنید...',
                'help_text'          => 'در صورت تمایل به سنجش تطابق دونفره، گزینه بله را انتخاب نمایید.',
                'required'           => 1,
                'condition_field'    => null,
                'condition_operator' => 'equals',
                'condition_value'    => null,
                'show_in_free'       => 1,
                'show_in_full'       => 1,
                'display_stage'      => 'pre_test', // آغاز آزمون
                'sort_order'         => 4,
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
                'display_stage'      => 'pre_test',
                'sort_order'         => 5,
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
                'display_stage'      => 'pre_test',
                'sort_order'         => 6,
                'active'             => 1,
            ],
        ];

        foreach ($fields as $f) {
            DB::insert('test_fields', $f);
        }
    }

    /**
     * تجزیه و یکپارچه‌سازی گزینه‌ها به سبک Gravity Forms (آرایه‌ای از کلید و عنوان)
     */
    public static function parseOptions(mixed $rawOptions): array
    {
        if (empty($rawOptions)) return [];

        if (is_array($rawOptions)) {
            $parsed = [];
            foreach ($rawOptions as $k => $item) {
                if (is_array($item)) {
                    $key = trim((string)($item['key'] ?? ($item['value'] ?? $k)));
                    $lbl = trim((string)($item['label'] ?? ($item['text'] ?? $key)));
                    $parsed[] = ['key' => $key, 'label' => $lbl];
                } else {
                    $str = trim((string)$item);
                    if (str_contains($str, ':')) {
                        [$pk, $pv] = explode(':', $str, 2);
                        $parsed[] = ['key' => trim($pk), 'label' => trim($pv)];
                    } else {
                        $parsed[] = ['key' => $str, 'label' => $str];
                    }
                }
            }
            return $parsed;
        }

        if (is_string($rawOptions)) {
            $decoded = json_decode($rawOptions, true);
            if (is_array($decoded)) {
                return self::parseOptions($decoded);
            }
            // سطر به سطر
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $rawOptions))));
            $parsed = [];
            foreach ($lines as $line) {
                if (str_contains($line, ':')) {
                    [$pk, $pv] = explode(':', $line, 2);
                    $parsed[] = ['key' => trim($pk), 'label' => trim($pv)];
                } else {
                    $parsed[] = ['key' => $line, 'label' => $line];
                }
            }
            return $parsed;
        }

        return [];
    }

    /**
     * واکشی تمام فیلدهای فعال متناسب با تست، حالت و مرحله اجرا
     */
    public static function getFieldsForTest(int $testId, string $mode = 'all', ?string $stage = null): array
    {
        self::ensureSchema();

        $params = [$testId];
        $sql = "SELECT * FROM " . self::table() . " WHERE active=1 AND (test_id=0 OR test_id=?)";

        if ($mode === 'free') {
            $sql .= " AND show_in_free=1";
        } elseif ($mode === 'full') {
            $sql .= " AND show_in_full=1";
        }

        if ($stage === 'pre_test') {
            $sql .= " AND (display_stage='pre_test' OR display_stage='both')";
        } elseif ($stage === 'post_test') {
            $sql .= " AND (display_stage='post_test' OR display_stage='both')";
        } elseif (!empty($stage) && $stage !== 'all') {
            $sql .= " AND display_stage=?";
            $params[] = $stage;
        }

        $sql .= " ORDER BY sort_order ASC, id ASC";

        $rows = DB::all($sql, $params);
        foreach ($rows as &$r) {
            $r['parsed_options'] = self::parseOptions($r['options'] ?? null);
        }
        unset($r);

        return $rows;
    }

    /**
     * واکشی فیلدهای پیش از آزمون (پیش از شروع پاسخ‌گویی به سؤالات)
     */
    public static function getPreTestFields(int $testId, string $mode = 'all'): array
    {
        return self::getFieldsForTest($testId, $mode, 'pre_test');
    }

    /**
     * واکشی فیلدهای پایان آزمون (دقیقاً پس از اتمام سؤالات و قبل از تحلیل نهایی)
     */
    public static function getPostTestFields(int $testId, string $mode = 'all'): array
    {
        return self::getFieldsForTest($testId, $mode, 'post_test');
    }

    /**
     * رندر HTML کادرهای فرم ورودی فیلدها با منطق شرطی به سبک گرویتی فرمز
     */
    public static function renderFormFields(array $fields, array $currentValues = [], string $containerId = 'testCustomFieldsBox'): string
    {
        if (empty($fields)) return '';

        ob_start();
        ?>
        <div class="fr-test-custom-fields-box" id="<?= e($containerId) ?>">
          <div class="fr-custom-fields-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px">
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

              <?php if ($type === 'checkbox'): ?>
                <!-- چک‌باکس چندگزینه‌ای یا تک‌گزینه‌ای به سبک Gravity Forms -->
                <?php if (!empty($f['parsed_options'])): ?>
                  <div class="fr-checkbox-group" style="display:flex;flex-direction:column;gap:8px;padding:4px 0">
                    <?php foreach ($f['parsed_options'] as $opt):
                        $k = is_array($opt) ? ($opt['key'] ?? '') : (string)$opt;
                        $lbl = is_array($opt) ? ($opt['label'] ?? $k) : (string)$opt;
                        $isChecked = (is_array($val) && in_array($k, $val, true)) || ($val === $k);
                    ?>
                      <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;font-size:13.5px;color:#334155;background:#fff;padding:9px 14px;border-radius:10px;border:1.5px solid #cbd5e1;transition:all .15s ease">
                        <input type="checkbox"
                               name="test_custom_fields[<?= e($fieldName) ?>][]"
                               value="<?= e($k) ?>"
                               class="tf-input tf-checkbox"
                               data-field-name="<?= e($fieldName) ?>"
                               data-required="<?= $req ? '1' : '0' ?>"
                               <?= $isChecked ? 'checked' : '' ?>
                               style="width:18px;height:18px;accent-color:#2563eb;cursor:pointer">
                        <span style="font-weight:600"><?= e($lbl) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:9px 14px;background:#fff;border-radius:10px;border:1.5px solid #cbd5e1;font-size:13.5px;color:#334155">
                    <input type="checkbox"
                           name="test_custom_fields[<?= e($fieldName) ?>]"
                           id="tf_<?= e($fieldName) ?>"
                           class="tf-input tf-checkbox"
                           data-field-name="<?= e($fieldName) ?>"
                           data-required="<?= $req ? '1' : '0' ?>"
                           value="1"
                           <?= ($val === '1' || $val === 1 || $val === 'yes' || $val === true || $val === 'بله') ? 'checked' : '' ?>
                           style="width:18px;height:18px;accent-color:#2563eb">
                    <span><?= e($f['placeholder'] ?: 'تأیید و انتخاب این مورد') ?></span>
                  </label>
                <?php endif; ?>

              <?php elseif ($type === 'single_checkbox'): ?>
                <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:9px 14px;background:#fff;border-radius:10px;border:1.5px solid #cbd5e1;font-size:13.5px;color:#334155">
                  <input type="checkbox"
                         name="test_custom_fields[<?= e($fieldName) ?>]"
                         id="tf_<?= e($fieldName) ?>"
                         class="tf-input tf-checkbox"
                         data-field-name="<?= e($fieldName) ?>"
                         data-required="<?= $req ? '1' : '0' ?>"
                         value="1"
                         <?= ($val === '1' || $val === 1 || $val === 'yes' || $val === true || $val === 'بله') ? 'checked' : '' ?>
                         style="width:18px;height:18px;accent-color:#2563eb">
                  <span><?= e($f['placeholder'] ?: ($f['parsed_options'][0]['label'] ?? 'تأیید و موافقت')) ?></span>
                </label>

              <?php elseif ($type === 'radio'): ?>
                <!-- دکمه‌های رادیویی با انتخاب تک‌گزینه از میان N گزینه -->
                <div class="fr-radio-group" style="display:flex;flex-direction:column;gap:8px;padding:4px 0">
                  <?php foreach ($f['parsed_options'] as $opt):
                      $k = is_array($opt) ? ($opt['key'] ?? '') : (string)$opt;
                      $lbl = is_array($opt) ? ($opt['label'] ?? $k) : (string)$opt;
                      $checked = ($val !== '' && ($val === $k || $val === $lbl)) ? 'checked' : '';
                  ?>
                    <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;font-size:13.5px;color:#334155;background:#fff;padding:9px 14px;border-radius:10px;border:1.5px solid #cbd5e1;transition:all .15s ease">
                      <input type="radio"
                             name="test_custom_fields[<?= e($fieldName) ?>]"
                             value="<?= e($k) ?>"
                             class="tf-input tf-radio"
                             data-field-name="<?= e($fieldName) ?>"
                             data-required="<?= $req ? '1' : '0' ?>"
                             <?= $checked ?>
                             style="width:18px;height:18px;accent-color:#2563eb;cursor:pointer">
                      <span style="font-weight:600"><?= e($lbl) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>

              <?php elseif ($type === 'select'): ?>
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

              <?php elseif ($type === 'multiselect'): ?>
                <select name="test_custom_fields[<?= e($fieldName) ?>][]"
                        id="tf_<?= e($fieldName) ?>"
                        multiple
                        class="fr-input fr-select tf-input"
                        data-field-name="<?= e($fieldName) ?>"
                        data-required="<?= $req ? '1' : '0' ?>"
                        style="width:100%;min-height:90px;border-radius:10px;border:1.5px solid #cbd5e1;background:#fff;padding:6px 12px;font-family:inherit">
                  <?php foreach ($f['parsed_options'] as $opt):
                      $k = is_array($opt) ? ($opt['key'] ?? '') : (string)$opt;
                      $lbl = is_array($opt) ? ($opt['label'] ?? $k) : (string)$opt;
                      $selected = (is_array($val) && in_array($k, $val, true)) || ($val === $k) ? 'selected' : '';
                  ?>
                    <option value="<?= e($k) ?>" <?= $selected ?>><?= e($lbl) ?></option>
                  <?php endforeach; ?>
                </select>

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
        <?= self::renderClientScript('#' . $containerId) ?>
        <?php
        return ob_get_clean();
    }

    /**
     * رندر اسکریپت پیشرفته اجرای منطق شرطی کلاینت بر اساس معماری Gravity Forms
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
                        const allChecked = box.querySelectorAll('[data-field-name="' + name + '"]:checked');
                        if (inputs.length > 1 || (first.name && first.name.indexOf('[]') !== -1)) {
                            return Array.from(allChecked).map(el => el.value.trim());
                        }
                        return first.checked ? (first.value || '1').trim() : '';
                    }
                    if (first.tagName === 'SELECT' && first.multiple) {
                        return Array.from(first.selectedOptions).map(o => o.value.trim());
                    }
                    return first.value.trim();
                }

                function evalCondition(parentVal, op, condVal) {
                    condVal = (condVal || '').toString().trim().toLowerCase();
                    
                    // بررسی والد از نوع آرایه (چک‌باکس چندگزینه‌ای یا مالتی‌سلکت)
                    if (Array.isArray(parentVal)) {
                        const pArr = parentVal.map(v => (v || '').toString().trim().toLowerCase());
                        const hasSub = pArr.some(v => v === condVal || (condVal && v.indexOf(condVal) !== -1));
                        switch (op) {
                            case 'equals':
                            case 'is':
                                return pArr.includes(condVal);
                            case 'not_equals':
                            case 'isnot':
                                return !pArr.includes(condVal);
                            case 'contains':
                                return hasSub;
                            case 'not_contains':
                                return !hasSub;
                            case 'checked':
                            case 'is_not_empty':
                                return pArr.length > 0;
                            case 'not_checked':
                            case 'is_empty':
                                return pArr.length === 0;
                            case 'in':
                                const inList = condVal.split(',').map(s => s.trim().toLowerCase());
                                return pArr.some(v => inList.includes(v));
                            case 'not_in':
                                const notInList = condVal.split(',').map(s => s.trim().toLowerCase());
                                return !pArr.some(v => notInList.includes(v));
                            default:
                                return pArr.includes(condVal);
                        }
                    }

                    // بررسی والد از نوع رشته
                    const pStr = (parentVal || '').toString().trim().toLowerCase();
                    switch (op) {
                        case 'equals':
                        case 'is':
                            return pStr === condVal;
                        case 'not_equals':
                        case 'isnot':
                            return pStr !== '' && pStr !== condVal;
                        case 'checked':
                            return ['1', 'true', 'yes', 'on', 'checked', 'بله'].includes(pStr);
                        case 'not_checked':
                            return ['0', 'false', 'off', '', 'no', 'خیر'].includes(pStr);
                        case 'is_empty':
                            return pStr === '';
                        case 'is_not_empty':
                            return pStr !== '';
                        case 'contains':
                            return condVal !== '' && pStr.indexOf(condVal) !== -1;
                        case 'not_contains':
                            return condVal === '' || pStr.indexOf(condVal) === -1;
                        case 'in':
                            const list = condVal.split(',').map(s => s.trim().toLowerCase());
                            return list.includes(pStr);
                        case 'not_in':
                            const nList = condVal.split(',').map(s => s.trim().toLowerCase());
                            return !nList.includes(pStr);
                        default:
                            return pStr === condVal;
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

                box.addEventListener('input', applyConditions);
                box.addEventListener('change', applyConditions);
                applyConditions();

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
                            if (!inputs.length) return;

                            const primaryInput = inputs[0];
                            const isReq = wrap.querySelector('[data-required="1"]') !== null;
                            let isFilled = false;

                            if (primaryInput.type === 'checkbox') {
                                const checkedCount = wrap.querySelectorAll('input[type="checkbox"]:checked').length;
                                isFilled = (checkedCount > 0);
                            } else if (primaryInput.type === 'radio') {
                                const checkedRadio = wrap.querySelector('input[type="radio"]:checked');
                                isFilled = !!checkedRadio;
                            } else {
                                isFilled = (primaryInput.value || '').trim() !== '';
                            }

                            const errEl = wrap.querySelector('.field-error-msg');
                            if (isReq && !isFilled) {
                                hasError = true;
                                wrap.classList.add('has-error');
                                if (primaryInput.style && primaryInput.type !== 'checkbox' && primaryInput.type !== 'radio') {
                                    primaryInput.style.borderColor = '#ef4444';
                                }
                                if (errEl) {
                                    if (primaryInput.type === 'checkbox') {
                                        errEl.innerText = 'تأیید و انتخاب حداقل یکی از گزینه‌ها الزامی است.';
                                    } else if (primaryInput.type === 'radio' || primaryInput.tagName === 'SELECT') {
                                        errEl.innerText = 'انتخاب یکی از گزینه‌ها الزامی است.';
                                    } else {
                                        errEl.innerText = 'تکمیل این فیلد الزامی است.';
                                    }
                                    errEl.style.display = 'block';
                                }
                                if (!firstErrorInput) firstErrorInput = primaryInput;
                            } else {
                                wrap.classList.remove('has-error');
                                if (primaryInput.style && primaryInput.type !== 'checkbox' && primaryInput.type !== 'radio') {
                                    primaryInput.style.borderColor = '#cbd5e1';
                                }
                                if (errEl) errEl.style.display = 'none';
                            }

                            // اعتبارسنجی تلفن همراه در صورت پر بودن
                            if (primaryInput.type === 'tel' && (primaryInput.value || '').trim()) {
                                const cleanTel = primaryInput.value.replace(/[\s\-\(\)]/g, '');
                                if (!/^(\+98|0)?9\d{9}$/.test(cleanTel) && !/^0\d{2,3}\d{7,8}$/.test(cleanTel)) {
                                    hasError = true;
                                    wrap.classList.add('has-error');
                                    if (primaryInput.style) primaryInput.style.borderColor = '#ef4444';
                                    if (errEl) {
                                        errEl.innerText = 'شماره تماس واردشده معتبر نیست (مثال: 09123456789).';
                                        errEl.style.display = 'block';
                                    }
                                    if (!firstErrorInput) firstErrorInput = primaryInput;
                                }
                            }
                        });

                        if (hasError) {
                            e.preventDefault();
                            if (firstErrorInput) {
                                firstErrorInput.focus();
                                firstErrorInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                            return false;
                        }
                    });
                }

                document.querySelectorAll('form').forEach(attachFormValidation);
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
     * اعتبارسنجی امن سمت سرور با اجرای منطق شرطی و تفکیک مرحله اجرا
     */
    public static function validateAndProcess(int $testId, string $mode, array $postData, ?string $stage = null): array
    {
        $fields = self::getFieldsForTest($testId, $mode, $stage);
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
            $valMap[$name] = $submitted[$name] ?? '';
        }

        foreach ($fields as $f) {
            $name   = (string)$f['name'];
            $label  = (string)$f['label'];
            $type   = (string)$f['type'];
            $rawVal = $submitted[$name] ?? null;
            $isReq  = (int)$f['required'] === 1;

            // ۱. بررسی منطق شرطی (Conditional Logic)
            $isVisible = true;
            if (!empty($f['condition_field'])) {
                $condField = (string)$f['condition_field'];
                $condOp    = (string)($f['condition_operator'] ?? 'equals');
                $condVal   = (string)($f['condition_value'] ?? '');
                $parentVal = $valMap[$condField] ?? '';

                $isVisible = self::evaluateCondition($parentVal, $condOp, $condVal);
            }

            // اگر شرط برقرار نباشد، فیلد نامرئی بوده و اعتبارسنجی الزامی روی آن اعمال نمی‌شود
            if (!$isVisible) {
                continue;
            }

            // ۲. بررسی الزامی بودن (Required check)
            $isEmpty = false;
            if ($rawVal === null || $rawVal === '') {
                $isEmpty = true;
            } elseif (is_array($rawVal) && count($rawVal) === 0) {
                $isEmpty = true;
            } elseif (is_string($rawVal) && trim($rawVal) === '') {
                $isEmpty = true;
            }

            if ($isReq && $isEmpty) {
                if ($type === 'checkbox') {
                    $errors[] = "تأیید و انتخاب گزینه «{$label}» الزامی است.";
                } elseif ($type === 'radio' || $type === 'select') {
                    $errors[] = "انتخاب گزینه‌ای برای فیلد «{$label}» الزامی است.";
                } else {
                    $errors[] = "تکمیل فیلد «{$label}» الزامی است.";
                }
                continue;
            }

            // فیلد غیر اجباری (اختیاری) و بدون مقدار
            if (!$isReq && $isEmpty) {
                continue;
            }

            // ۳. اعتبارسنجی فرمت‌های خاص
            if ($type === 'tel' && !empty($rawVal) && is_string($rawVal)) {
                $cleanTel = preg_replace('/[\s\-\(\)]/', '', trim($rawVal));
                if (!preg_match('/^(\+98|0)?9\d{9}$/', $cleanTel) && !preg_match('/^0\d{2,3}\d{7,8}$/', $cleanTel)) {
                    $errors[] = "شماره تماس واردشده برای «{$label}» معتبر نیست.";
                    continue;
                }
            }

            if ($type === 'number' && !empty($rawVal) && is_string($rawVal)) {
                if (!is_numeric(trim($rawVal))) {
                    $errors[] = "مقدار فیلد «{$label}» باید عدد باشد.";
                    continue;
                }
            }

            // ۴. برچسب‌گذاری و تبدیل مقادیر گزینه‌ای به ساختار استاندارد Gravity Forms
            $displayValue = $rawVal;
            $labelsList   = [];

            if ($type === 'checkbox') {
                if (is_array($rawVal)) {
                    $labelsList = [];
                    foreach ($rawVal as $chosenKey) {
                        $foundLbl = (string)$chosenKey;
                        if (!empty($f['parsed_options'])) {
                            foreach ($f['parsed_options'] as $opt) {
                                $k = is_array($opt) ? ($opt['key'] ?? '') : (string)$opt;
                                $lbl = is_array($opt) ? ($opt['label'] ?? $k) : (string)$opt;
                                if ((string)$chosenKey === (string)$k || (string)$chosenKey === (string)$lbl) {
                                    $foundLbl = $lbl;
                                    break;
                                }
                            }
                        }
                        $labelsList[] = $foundLbl;
                    }
                    $displayValue = implode('، ', $labelsList);
                } else {
                    $displayValue = ($rawVal === '1' || $rawVal === 1 || $rawVal === 'yes' || $rawVal === 'بله') ? 'بله' : 'خیر';
                    $labelsList = [$displayValue];
                }
            } elseif (!empty($f['parsed_options']) && ($type === 'select' || $type === 'radio')) {
                $strVal = is_array($rawVal) ? '' : trim((string)$rawVal);
                foreach ($f['parsed_options'] as $opt) {
                    $k = is_array($opt) ? ($opt['key'] ?? '') : (string)$opt;
                    $lbl = is_array($opt) ? ($opt['label'] ?? $k) : (string)$opt;
                    if ($strVal === (string)$k || $strVal === (string)$lbl) {
                        $displayValue = $lbl;
                        $labelsList = [$lbl];
                        break;
                    }
                }
            }

            $responses[$name] = [
                'name'         => $name,
                'label'        => $label,
                'type'         => $type,
                'value'        => $displayValue,
                'raw'          => $rawVal,
                'value_labels' => $labelsList,
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
        $c = mb_strtolower(trim((string)$condVal));

        if (is_array($parentVal)) {
            $pArr = array_map(fn($v) => mb_strtolower(trim((string)$v)), $parentVal);
            $hasSub = false;
            foreach ($pArr as $item) {
                if ($item === $c || ($c !== '' && str_contains($item, $c))) {
                    $hasSub = true;
                    break;
                }
            }

            return match ($operator) {
                'equals', 'is'          => in_array($c, $pArr, true),
                'not_equals', 'isnot'   => !in_array($c, $pArr, true),
                'checked', 'is_not_empty' => !empty($pArr),
                'not_checked', 'is_empty' => empty($pArr),
                'contains'              => $hasSub,
                'not_contains'          => !$hasSub,
                'in'                    => !empty(array_intersect($pArr, array_map('trim', explode(',', $c)))),
                'not_in'                => empty(array_intersect($pArr, array_map('trim', explode(',', $c)))),
                default                 => in_array($c, $pArr, true),
            };
        }

        $p = mb_strtolower(trim((string)$parentVal));

        return match ($operator) {
            'equals', 'is'          => ($p === $c),
            'not_equals', 'isnot'   => ($p !== $c && $p !== ''),
            'checked'               => in_array($p, ['1', 'true', 'yes', 'on', 'checked', 'بله'], true),
            'not_checked'           => in_array($p, ['0', 'false', 'off', '', 'no', 'خیر'], true),
            'is_empty'              => ($p === ''),
            'is_not_empty'          => ($p !== ''),
            'contains'              => ($c !== '' && str_contains($p, $c)),
            'not_contains'          => ($c === '' || !str_contains($p, $c)),
            'in'                    => in_array($p, array_map('trim', explode(',', $c)), true),
            'not_in'                => !in_array($p, array_map('trim', explode(',', $c)), true),
            default                 => ($p === $c),
        };
    }

    /**
     * رندر کارت ویژوال نمایش اطلاعات ثبت‌شده در پنل مدیریت، کارنامه و پرونده بالینی
     */
    public static function renderDisplayCard(array|string|null $customFieldsJson, string $title = '📋 اطلاعات و گزینه‌های تکمیلی آزمون'): string
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

                $valStr = '';
                if (is_array($val)) {
                    $valStr = implode('، ', array_map('strval', $val));
                } else {
                    $valStr = (string)$val;
                }
            ?>
              <div style="background:#fff;border-radius:10px;padding:12px 14px;border:1px solid #e2e8f0;line-height:1.9">
                <div style="color:#64748b;font-size:12px;margin-bottom:2px"><?= e($label) ?></div>
                <div style="font-weight:700;color:#0f172a">
                  <?php if ($type === 'tel' && $valStr): ?>
                    <a href="tel:<?= e($valStr) ?>" dir="ltr" style="color:#2563eb;text-decoration:none">📱 <?= fa_num($valStr) ?></a>
                  <?php elseif ($valStr === 'بله' || $valStr === '1'): ?>
                    <span class="fr-chip ok sm" style="font-size:11.5px">✓ <?= e($valStr) ?></span>
                  <?php elseif ($valStr === 'خیر' || $valStr === '0'): ?>
                    <span class="fr-chip info sm" style="font-size:11.5px"><?= e($valStr) ?></span>
                  <?php else: ?>
                    <?= e($valStr) ?>
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
