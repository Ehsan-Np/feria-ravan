-- ============================================================================
-- مهاجرت نگارش ۳٫۹٫۰: لینک‌های کوتاه تست‌ها و فیلدهای سفارشی به سبک گرویتی فرمز
-- ============================================================================

-- ۱. ستون مرحله نمایش فیلد (در آغاز آزمون pre_test یا در پایان آزمون post_test)
ALTER TABLE `{{prefix}}test_fields`
  ADD COLUMN IF NOT EXISTS `display_stage` VARCHAR(20) NOT NULL DEFAULT 'post_test' AFTER `show_in_full`;

-- ۲. ایندکس بهینه برای واکشی سریع فیلدهای آزمون بر اساس مرحله و فعال بودن
ALTER TABLE `{{prefix}}test_fields`
  ADD INDEX IF NOT EXISTS `ix_test_stage` (`test_id`, `display_stage`, `active`);

-- ۳. اطمینان از وجود ستون custom_fields_json در تلاش‌ها
ALTER TABLE `{{prefix}}attempts`
  ADD COLUMN IF NOT EXISTS `custom_fields_json` MEDIUMTEXT NULL AFTER `validity_json`;
