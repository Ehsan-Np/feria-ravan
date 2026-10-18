-- ============================================================================
-- فریا روان (Feria Ravan) — اسکریپت تجمیعی و یکپارچه مهاجرت پایگاه‌داده
-- نگارش: ۴٫۹٫۳ (v4.9.3 Unified Migration)
-- تجمیع تمامی تغییرات، جداول جدید، ستون‌ها، ایندکس‌ها و تنظیمات نگارش‌های سامانه
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------------------------------------------------------
-- ۱. ساخت جداول جدید سامانه (در صورت عدم وجود)
-- ----------------------------------------------------------------------------

-- ۱٫۱. جدول برگه‌های سایت و صفحه‌ساز دیداری
CREATE TABLE IF NOT EXISTS `{{prefix}}site_pages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(190) NULL,
  `title` VARCHAR(190) NOT NULL,
  `slug` VARCHAR(190) NOT NULL UNIQUE,
  `content_html` LONGTEXT NULL,
  `content_css` LONGTEXT NULL,
  `builder_json` LONGTEXT NULL,
  `layout` VARCHAR(50) NOT NULL DEFAULT 'default',
  `page_type` VARCHAR(20) NOT NULL DEFAULT 'page',
  `seo_title` VARCHAR(190) NULL,
  `meta_desc` VARCHAR(300) NULL,
  `noindex` TINYINT(1) NOT NULL DEFAULT 0,
  `is_home` TINYINT(1) NOT NULL DEFAULT 0,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_is_home` (`is_home`),
  KEY `ix_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱٫۲. سیستم فهرست‌ساز و منوهای سفارشی
CREATE TABLE IF NOT EXISTS `{{prefix}}menus` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(190) NOT NULL,
  `slug` VARCHAR(190) NOT NULL UNIQUE,
  `location` VARCHAR(50) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_location` (`location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}menu_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `menu_id` INT UNSIGNED NOT NULL,
  `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(190) NOT NULL,
  `url` VARCHAR(500) NOT NULL,
  `target` VARCHAR(20) NOT NULL DEFAULT '_self',
  `icon` VARCHAR(60) NULL,
  `badge` VARCHAR(50) NULL,
  `badge_color` VARCHAR(30) NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT 'custom',
  `type_id` INT UNSIGNED NULL,
  `css_class` VARCHAR(100) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_menu` (`menu_id`),
  KEY `ix_parent` (`parent_id`),
  KEY `ix_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱٫۳. فیلدهای داینامیک ثبت‌نام و پروفایل کاربران
CREATE TABLE IF NOT EXISTS `{{prefix}}profile_fields` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL UNIQUE,
  `label` VARCHAR(150) NOT NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT 'text',
  `options` TEXT NULL,
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `show_in_register` TINYINT(1) NOT NULL DEFAULT 1,
  `show_in_profile` TINYINT(1) NOT NULL DEFAULT 1,
  `placeholder` VARCHAR(255) NULL,
  `validation` VARCHAR(255) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_active_reg` (`active`, `show_in_register`, `sort_order`),
  KEY `ix_active_prof` (`active`, `show_in_profile`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱٫۴. فیلدهای اختصاصی آزمون‌ها و منطق‌های شرطی
CREATE TABLE IF NOT EXISTS `{{prefix}}test_custom_fields` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `test_id` INT NULL DEFAULT NULL,
  `field_key` VARCHAR(64) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `field_type` ENUM('text', 'textarea', 'select', 'checkbox', 'radio', 'tel', 'number') NOT NULL DEFAULT 'text',
  `options_json` TEXT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `placeholder` VARCHAR(255) NULL,
  `default_value` VARCHAR(255) NULL,
  `validation_regex` VARCHAR(255) NULL,
  `validation_message` VARCHAR(255) NULL,
  `condition_rules_json` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_test_id` (`test_id`),
  INDEX `idx_field_key` (`field_key`),
  INDEX `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}attempt_custom_field_values` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT NOT NULL,
  `field_id` INT NOT NULL,
  `field_key` VARCHAR(64) NOT NULL,
  `field_label` VARCHAR(255) NOT NULL,
  `field_value` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_attempt_id` (`attempt_id`),
  INDEX `idx_field_id` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ۱٫۵. جدول پایش حس و حال روانی روزانه در پلنر (Mood & Energy Check-in ۱۴۰۵)
CREATE TABLE IF NOT EXISTS `{{prefix}}planner_moods` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `mood` ENUM('great','good','neutral','tired','stressed') NOT NULL DEFAULT 'good',
  `energy` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `reflection` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_day` (`user_id`, `day`),
  KEY `ix_user_day` (`user_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ۲. سازگاری ستون‌ها و ارتقای ساختار جداول موجود (Safe DDL)
-- ----------------------------------------------------------------------------

-- ۲٫۱. ستون‌های پیام‌رسان روبیکا در جدول کاربران
ALTER TABLE `{{prefix}}users`
  ADD COLUMN IF NOT EXISTS `rubika_chat_id` VARCHAR(100) DEFAULT NULL AFTER `bale_chat_id`,
  ADD COLUMN IF NOT EXISTS `rubika_username` VARCHAR(60) DEFAULT NULL AFTER `bale_username`;

-- ۲٫۲. ستون‌های پیام‌رسان روبیکا در جدول مخاطبین
ALTER TABLE `{{prefix}}contacts`
  ADD COLUMN IF NOT EXISTS `rubika_chat_id` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `rubika_username` VARCHAR(60) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `rubika_ok` TINYINT(1) NOT NULL DEFAULT 0;

-- ۲٫۳. سازگاری ستون‌های کیف پول
ALTER TABLE `{{prefix}}wallet_transactions`
  ADD COLUMN IF NOT EXISTS `kind` VARCHAR(32) NOT NULL DEFAULT 'deposit' AFTER `type`,
  ADD COLUMN IF NOT EXISTS `reference_type` VARCHAR(32) NULL AFTER `kind`,
  ADD COLUMN IF NOT EXISTS `reference_id` BIGINT UNSIGNED NULL AFTER `reference_type`,
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) NULL AFTER `reference_id`,
  ADD COLUMN IF NOT EXISTS `expires_at` DATETIME NULL AFTER `description`;

-- ۲٫۴. سازگاری ستون‌های همراهان ثبت‌نام رویدادها
ALTER TABLE `{{prefix}}event_registrations`
  ADD COLUMN IF NOT EXISTS `companion_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `order_id`,
  ADD COLUMN IF NOT EXISTS `companions_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `companion_count`;

-- ۲٫۵. ارتقای ساختار ستون‌های صفحه‌ساز
ALTER TABLE `{{prefix}}site_pages`
  ADD COLUMN IF NOT EXISTS `name` VARCHAR(190) NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `layout` VARCHAR(50) NOT NULL DEFAULT 'default' AFTER `slug`,
  ADD COLUMN IF NOT EXISTS `page_type` VARCHAR(20) NOT NULL DEFAULT 'page' AFTER `layout`,
  ADD COLUMN IF NOT EXISTS `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_home`,
  ADD COLUMN IF NOT EXISTS `content_html` LONGTEXT NULL AFTER `active`,
  ADD COLUMN IF NOT EXISTS `content_css` MEDIUMTEXT NULL AFTER `content_html`,
  ADD COLUMN IF NOT EXISTS `builder_json` LONGTEXT NULL AFTER `content_css`,
  ADD COLUMN IF NOT EXISTS `view_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `builder_json`;

-- ۲٫۶. مرحله نمایش فیلدهای سفارشی آزمون (pre_test / post_test)
ALTER TABLE `{{prefix}}test_fields`
  ADD COLUMN IF NOT EXISTS `display_stage` VARCHAR(20) NOT NULL DEFAULT 'post_test' AFTER `show_in_full`;

-- ۲٫۷. ذخیره مقادیر فیلدهای سفارشی در تلاش آزمون
ALTER TABLE `{{prefix}}attempts`
  ADD COLUMN IF NOT EXISTS `custom_fields_json` MEDIUMTEXT NULL AFTER `validity_json`;

-- ----------------------------------------------------------------------------
-- ۳. داده‌های پیش‌فرض و مقادیر سامانه
-- ----------------------------------------------------------------------------

-- ۳٫۱. فیلدهای سیستمی پیش‌فرض ثبت‌نام و پروفایل
INSERT IGNORE INTO `{{prefix}}profile_fields` (`name`, `label`, `type`, `options`, `required`, `active`, `show_in_register`, `show_in_profile`, `placeholder`, `sort_order`, `is_system`) VALUES
('full_name', 'نام و نام خانوادگی', 'text', NULL, 1, 1, 1, 1, 'مثل: سارا محمدی', 10, 1),
('mobile', 'شماره تلفن همراه', 'mobile', NULL, 1, 1, 1, 1, '۰۹۱۲۳۴۵۶۷۸۹', 20, 1),
('username', 'آیدی / نام کاربری دلخواه', 'text', NULL, 0, 1, 1, 1, 'حروف لاتین مثلاً sara_m', 30, 1),
('password', 'رمز عبور', 'text', NULL, 1, 1, 1, 0, 'حداقل ۶ نویسه', 40, 1),
('national_code', 'کد ملی ۱۰ رقمی', 'nationalcode', NULL, 0, 1, 1, 1, '– – – – – – – – – –', 50, 1),
('email', 'ایمیل', 'text', NULL, 0, 1, 0, 1, 'info@example.com', 60, 0),
('gender', 'جنسیت', 'select', '["\u0632\u0646","\u0645\u0631\u062f"]', 0, 1, 0, 1, '', 70, 0),
('birth_date', 'تاریخ تولد', 'date', NULL, 0, 1, 0, 1, 'مثلاً ۱۳۷۰/۰۵/۱۶', 80, 0),
('job_title', 'عنوان شغلی / رشته', 'text', NULL, 0, 1, 0, 1, 'مثلاً کارشناس روانشناسی', 90, 0),
('education', 'تحصیلات', 'select', '["\u0632\u06cc\u0631 \u062f\u06cc\u067e\u0644\u0645","\u062f\u06cc\u067e\u0644\u0645","\u06a9\u0627\u0631\u062f\u0627\u0646\u06cc","\u06a9\u0627\u0631\u0634\u0646\u0627\u0633\u06cc","\u06a9\u0627\u0631\u0634\u0646\u0627\u0633\u06cc \u0627\u0631\u0634\u062f","\u062f\u06a9\u062a\u0631\u06cc \u0648 \u0628\u0627\u0644\u0627\u062a\u0631"]', 0, 1, 0, 1, '', 100, 0);

-- ۳٫۲. تنظیمات هویت بصری، وایت‌لیبل و اطلاعات عمومی
INSERT IGNORE INTO `{{prefix}}settings` (`key`, `value`, `autoload`) VALUES
  ('site_title_en', 'Feria Ravan', 1),
  ('brand_short_name', 'فریا', 1),
  ('logo_dark', '', 1),
  ('favicon', '', 1),
  ('copyright_text', 'تمامی حقوق مادی و معنوی متعلق به {site} می‌باشد.', 1),
  ('hide_system_credits', '0', 1),
  ('support_phone', '۰۲۱-۸۸۸۸۸۸۸۸', 1),
  ('support_email', 'support@example.com', 1),
  ('support_telegram', '', 1),
  ('support_bale', '', 1),
  ('price_persistence_enabled', '1', 1);

-- ۳٫۳. تنظیمات کارنامه رسمی ISO A4، بارکد و دریافت فایل
INSERT INTO `{{prefix}}settings` (`key`, `value`, `autoload`)
VALUES 
  ('report_show_signature_box', '1', 1),
  ('report_show_qr', '1', 1),
  ('report_header_color', '#1e3a6e', 1),
  ('report_pdf_download_mode', 'direct_pdf', 1),
  ('report_download_fallback', '1', 1),
  ('report_qr_engine', 'local_svg', 1)
ON DUPLICATE KEY UPDATE `key`=`key`;

-- ۳٫۴. تنظیمات اطلاع‌رسانی، روبیکا و پیام‌رسان‌ها در نگارش ۴٫۹٫۰
INSERT INTO `{{prefix}}settings` (`key`, `value`, `autoload`)
VALUES 
  ('rubika_bot_token', '', 1),
  ('rubika_bot_username', '', 1),
  ('rubika_api_url', 'https://botapi.rubika.ir/v1', 1),
  ('telegram_proxy', '', 1),
  ('telegram_api_url', 'https://api.telegram.org', 1),
  ('bale_api_url', 'https://tapi.bale.ai', 1),
  ('planner_notify_channels', 'telegram,bale,rubika', 1)
ON DUPLICATE KEY UPDATE `key`=`key`;

-- ۳٫۵. ثبت و تثبیت نگارش ۴٫۹٫۳ در تنظیمات سامانه
INSERT INTO `{{prefix}}settings` (`key`, `value`, `autoload`)
VALUES 
  ('fr_version', '4.9.3', 1),
  ('system_version', '4.9.3', 1)
ON DUPLICATE KEY UPDATE `value`='4.9.3';

SET FOREIGN_KEY_CHECKS=1;
