-- ============================================================================
-- مهاجرت نگارش ۳٫۸٫۰: صفحه‌ساز چندبخشی، رفع خطای ۵۰۰ کیف پول و سازگاری ستون‌ها
-- ============================================================================

-- ۱. سازگاری دوطرفه ستون‌های کیف پول
ALTER TABLE `{{prefix}}wallet_transactions`
  ADD COLUMN IF NOT EXISTS `kind` VARCHAR(32) NOT NULL DEFAULT 'deposit' AFTER `type`,
  ADD COLUMN IF NOT EXISTS `reference_type` VARCHAR(32) NULL AFTER `kind`,
  ADD COLUMN IF NOT EXISTS `reference_id` BIGINT UNSIGNED NULL AFTER `reference_type`,
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL AFTER `reference_id`,
  ADD COLUMN IF NOT EXISTS `expires_at` DATETIME NULL AFTER `description`;

-- ۲. سازگاری ستون‌های همراهان رویدادها
ALTER TABLE `{{prefix}}event_registrations`
  ADD COLUMN IF NOT EXISTS `companion_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `order_id`,
  ADD COLUMN IF NOT EXISTS `companions_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `companion_count`;

-- ۳. ارتقای ساختار ستون‌های صفحه‌ساز
ALTER TABLE `{{prefix}}site_pages`
  ADD COLUMN IF NOT EXISTS `name` VARCHAR(190) NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `layout` VARCHAR(50) NOT NULL DEFAULT 'default' AFTER `slug`,
  ADD COLUMN IF NOT EXISTS `page_type` VARCHAR(20) NOT NULL DEFAULT 'page' AFTER `layout`,
  ADD COLUMN IF NOT EXISTS `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_home`,
  ADD COLUMN IF NOT EXISTS `content_html` LONGTEXT NULL AFTER `active`,
  ADD COLUMN IF NOT EXISTS `content_css` MEDIUMTEXT NULL AFTER `content_html`,
  ADD COLUMN IF NOT EXISTS `builder_json` LONGTEXT NULL AFTER `content_css`,
  ADD COLUMN IF NOT EXISTS `view_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `builder_json`;
