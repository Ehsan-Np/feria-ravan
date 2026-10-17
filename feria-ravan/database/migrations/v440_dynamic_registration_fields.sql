-- ============================================================
-- فریا روان (Feria Ravan) - مایگریشن نسخه ۴٫۴٫۰
-- اعمال داینامیک و آنی فیلدهای فرم ثبت‌نام و پروفایل کاربران
-- ============================================================

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

INSERT INTO `{{prefix}}settings` (`key`, `value`, `autoload`)
VALUES ('fr_version', '4.4.0', 1)
ON DUPLICATE KEY UPDATE `value`='4.4.0';
