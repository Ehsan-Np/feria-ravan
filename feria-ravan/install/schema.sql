-- ============================================================
-- فریا روان (Feria Ravan) - ساختار پایگاه‌داده MySQL
-- Charset: utf8mb4 | Engine: InnoDB
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `{{prefix}}settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` MEDIUMTEXT NULL,
  `autoload` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}admins` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `username` VARCHAR(60) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NULL,
  `display_name` VARCHAR(120) NOT NULL DEFAULT '',
  `role` VARCHAR(40) NOT NULL DEFAULT 'super',
  `role_id` INT UNSIGNED NULL,
  `status` ENUM('active','blocked') NOT NULL DEFAULT 'active',
  `totp_secret` VARCHAR(64) NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `mobile` VARCHAR(11) DEFAULT NULL,
  `last_login` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_admin_user` (`user_id`),
  KEY `ix_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}users` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(80) NOT NULL DEFAULT '',
  `last_name` VARCHAR(80) NOT NULL DEFAULT '',
  `username` VARCHAR(60) NULL UNIQUE,
  `password_hash` VARCHAR(255) NULL,
  `national_code` VARCHAR(10) NULL,
  `mobile` VARCHAR(11) NOT NULL UNIQUE,
  `mobile_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `email` VARCHAR(190) NULL,
  `avatar` VARCHAR(255) NULL,
  `gender` ENUM('male','female','other') NULL,
  `education` VARCHAR(50) NULL,
  `birth_date` VARCHAR(10) NULL,
  `job_title` VARCHAR(150) NULL,
  `marital` VARCHAR(30) NULL,
  `status` ENUM('active','inactive','banned') NOT NULL DEFAULT 'active',
  `wallet_balance` INT NOT NULL DEFAULT 0,
  `bank_card_enc` TEXT DEFAULT NULL,
  `bank_card_last4` CHAR(4) DEFAULT NULL,
  `telegram_chat_id` VARCHAR(60) NULL,
  `bale_chat_id` VARCHAR(60) NULL,
  `tg_username` VARCHAR(60) NULL,
  `bale_username` VARCHAR(60) NULL,
  `notif_prefs` VARCHAR(255) NULL,
  `extra` TEXT NULL,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARBINARY(16) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_mobile_status` (`mobile`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}tests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(80) NOT NULL UNIQUE,
  `title` VARCHAR(150) NOT NULL,
  `short_desc` VARCHAR(255) NULL,
  `description` MEDIUMTEXT NULL,
  `image` VARCHAR(255) NULL,
  `instructions` TEXT NULL,
  `engine` VARCHAR(30) NOT NULL DEFAULT 'disc',
  `audience` ENUM('general','manager') NOT NULL DEFAULT 'general',
  `category` ENUM('clinical','personality','intelligence') NOT NULL DEFAULT 'personality',
  `question_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `est_minutes` INT UNSIGNED NOT NULL DEFAULT 15,
  `price_full` INT UNSIGNED NOT NULL DEFAULT 0,
  `price_sale` INT UNSIGNED NULL,
  `free_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `free_teaser` TEXT NULL,
  `seo_title` VARCHAR(200) NULL,
  `seo_desc` VARCHAR(320) NULL,
  `seo_kw` VARCHAR(120) NULL,
  `seo_noindex` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive','draft','trash') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}questions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `test_id` INT UNSIGNED NOT NULL,
  `number` INT UNSIGNED NOT NULL DEFAULT 1,
  `text` TEXT NOT NULL,
  `format` VARCHAR(40) NOT NULL DEFAULT 'likert',
  `options` MEDIUMTEXT NULL,
  `labels` TEXT NULL,
  `scale_min` INT NOT NULL DEFAULT 1,
  `scale_max` INT NOT NULL DEFAULT 5,
  `min_label` VARCHAR(100) NULL,
  `max_label` VARCHAR(100) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `note` VARCHAR(255) NULL,
  `scale` VARCHAR(30) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_test_num` (`test_id`, `number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}test_questions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `test_id` INT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `text` TEXT NOT NULL,
  `note` VARCHAR(255) NULL,
  `scale` VARCHAR(30) NULL,
  `options` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_test_sort` (`test_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}test_fields` (
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
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_test_active` (`test_id`, `active`, `sort_order`),
  KEY `ix_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}scoring_keys` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `test_id` INT UNSIGNED NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `scale_code` VARCHAR(30) NOT NULL,
  `weight` FLOAT NOT NULL DEFAULT 1.0,
  `reverse` TINYINT(1) NOT NULL DEFAULT 0,
  `meta` TEXT NULL,
  KEY `ix_test` (`test_id`), KEY `ix_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}discounts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(40) NOT NULL UNIQUE,
  `type` ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `value` INT UNSIGNED NOT NULL,
  `max_amount` INT UNSIGNED NULL,
  `min_order` INT UNSIGNED NULL,
  `test_id` INT UNSIGNED NULL,
  `event_id` INT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `usage_limit` INT UNSIGNED NULL,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `per_user` INT UNSIGNED NOT NULL DEFAULT 1,
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}discount_usage` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `discount_id` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_disc_user` (`discount_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}orders` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `test_id` INT UNSIGNED NULL,
  `event_id` INT UNSIGNED NULL,
  `kind` ENUM('test_full','event','consult') NOT NULL DEFAULT 'test_full',
  `amount` INT UNSIGNED NOT NULL DEFAULT 0,
  `discount_code` VARCHAR(40) NULL,
  `discount_amt` INT UNSIGNED NOT NULL DEFAULT 0,
  `payable` INT UNSIGNED NOT NULL DEFAULT 0,
  `custom_fields_json` MEDIUMTEXT NULL,
  `pay_stage` VARCHAR(12) NOT NULL DEFAULT 'full',
  `parent_order_id` BIGINT UNSIGNED NULL,
  `status` ENUM('pending','paid','failed','canceled','refunded') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` DATETIME NULL,
  KEY `ix_user` (`user_id`), KEY `ix_status` (`status`), KEY `ix_parent` (`parent_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}payments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `authority` VARCHAR(60) NOT NULL UNIQUE,
  `amount` INT UNSIGNED NOT NULL,
  `ref_id` VARCHAR(80) NULL,
  `card_pan` VARCHAR(30) NULL,
  `status` ENUM('init','ok','failed','verify_failed') NOT NULL DEFAULT 'init',
  `gateway_msg` VARCHAR(255) NULL,
  `ip` VARBINARY(16) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` DATETIME NULL,
  KEY `ix_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}attempts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `test_id` INT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `mode` ENUM('free','full') NOT NULL DEFAULT 'free',
  `status` ENUM('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
  `progress` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
  `validity_json` TEXT NULL,
  `custom_fields_json` MEDIUMTEXT NULL,
  `growth_injected` TINYINT(1) NOT NULL DEFAULT 0,
  `rep_code` VARCHAR(40) NULL,
  `context` MEDIUMTEXT NULL,
  `ip` VARBINARY(16) NULL,
  `user_agent` VARCHAR(255) NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  KEY `ix_user_test` (`user_id`, `test_id`), KEY `ix_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}answers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` BIGINT UNSIGNED NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `choice` VARCHAR(30) NULL,
  `value` VARCHAR(30) NULL,
  `most_idx` INT NULL,
  `least_idx` INT NULL,
  `raw` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_attempt_q` (`attempt_id`, `question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}results` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` BIGINT UNSIGNED NOT NULL,
  `scale_code` VARCHAR(40) NULL,
  `raw_score` FLOAT NOT NULL DEFAULT 0,
  `max_score` FLOAT NOT NULL DEFAULT 0,
  `percent` FLOAT NOT NULL DEFAULT 0,
  `mean_score` FLOAT NOT NULL DEFAULT 0,
  `t_score` FLOAT NULL DEFAULT NULL,
  `sten_score` FLOAT NULL DEFAULT NULL,
  `percentile` FLOAT NULL DEFAULT NULL,
  `band` VARCHAR(30) NOT NULL DEFAULT '',
  `raw_extra` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_attempt` (`attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}dyads` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(32) NOT NULL UNIQUE,
  `test_id` INT UNSIGNED NOT NULL,
  `user1_id` BIGINT UNSIGNED NOT NULL,
  `attempt1_id` BIGINT UNSIGNED NOT NULL,
  `order1_id` BIGINT UNSIGNED NULL,
  `user2_id` BIGINT UNSIGNED NULL,
  `attempt2_id` BIGINT UNSIGNED NULL,
  `order2_id` BIGINT UNSIGNED NULL,
  `rel_type` ENUM('couple','spouse','coworker','friend','business') NOT NULL DEFAULT 'couple',
  `pay_mode` ENUM('free','self_paid','partner_paid') NOT NULL DEFAULT 'free',
  `target_mode` ENUM('free','full') NOT NULL DEFAULT 'free',
  `partner_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `partner_name` VARCHAR(150) NULL,
  `partner_mobile` VARCHAR(20) NULL,
  `status` ENUM('pending','ready','archived') NOT NULL DEFAULT 'pending',
  `synergy_score` INT UNSIGNED NULL,
  `summary_json` MEDIUMTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_u1` (`user1_id`),
  KEY `ix_u2` (`user2_id`),
  KEY `ix_att1` (`attempt1_id`),
  KEY `ix_att2` (`attempt2_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}analysis_templates` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `test_id` INT UNSIGNED NOT NULL,
  `scale_code` VARCHAR(40) NULL,
  `band` VARCHAR(30) NOT NULL DEFAULT '',
  `level` VARCHAR(30) NULL,
  `title` VARCHAR(150) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `growth_plan` MEDIUMTEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  KEY `ix_test_scale` (`test_id`, `scale_code`, `band`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}sms_templates` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event` VARCHAR(60) NOT NULL UNIQUE,
  `title` VARCHAR(150) NULL,
  `body` TEXT NOT NULL,
  `use_pattern` TINYINT(1) NOT NULL DEFAULT 0,
  `pattern_code` VARCHAR(80) NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{{prefix}}sms_templates` (`event`, `title`, `body`, `use_pattern`, `pattern_code`, `enabled`) VALUES
('otp', 'کد یک‌بارمصرف ورود و ثبت‌نام', 'کد تأیید ورود شما به %site%: %code%', 1, 'otp', 1),
('register', 'خوش‌آمدگویی پس از ثبت‌نام', '%name% عزیز، به %site% خوش آمدید 🦢', 0, NULL, 1),
('payment_ok', 'پس از پرداخت موفق (قالب پیش‌فرض)', '%name% عزیز، پرداخت شما با کد رهگیری %refid% در %site% ثبت شد.', 0, NULL, 1),
('test_done', 'پس از تکمیل تست روانشناسی', '%name% عزیز، آزمون «%test%» شما با موفقیت ثبت شد و نتایج در پنل کاربری آماده است. %site%', 0, NULL, 1),
('report_free', 'پس از صدور تحلیل رایگان', '%name% عزیز، کارنامه مقدماتی آزمون «%test%» برای شما صادر شد: %link%', 0, NULL, 1),
('report_full', 'پس از صدور تحلیل ویژه', '%name% عزیز، گزارش تحلیلی کامل شما برای آزمون «%test%» آماده مشاهده است: %link%', 0, NULL, 1),
('admin_new_order', 'اطلاع ثبت‌نام/خرید جدید به مدیر', 'ثبت‌نام جدید در %site%: %name% (%mobile%) — %test% — مبلغ %amount%', 0, NULL, 1),
('refund_request_user', 'عودت وجه — تأیید دریافت درخواست به کاربر', '%name% عزیز، درخواست عودت وجه شما به مبلغ %amount% ثبت شد و در صف بررسی است؛ نتیجه پیامک می‌شود. %site%', 0, NULL, 1),
('refund_request_admin', 'عودت وجه — اطلاع به مدیر/حسابداری', 'درخواست عودت وجه جدید: %name% (%mobile%) مبلغ %amount% — علت: %reason%', 0, NULL, 1),
('test_nudge', 'یادآوری آزمون نیمه‌تمام (Nudge)', '%name% عزیز، آزمون شما در %site% نیمه‌تمام است؛ برای تکمیل و دریافت کارنامه مراجعه فرمایید: %link%', 0, NULL, 1);

CREATE TABLE IF NOT EXISTS `{{prefix}}sms_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `mobile` VARCHAR(20) NOT NULL,
  `event` VARCHAR(60) NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'sent',
  `provider_id` VARCHAR(100) NULL,
  `cost` INT NULL,
  `ip` VARBINARY(16) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_mobile` (`mobile`),
  KEY `ix_event` (`event`),
  KEY `ix_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}sms_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `mobile` VARCHAR(11) NOT NULL,
  `message` TEXT NOT NULL,
  `event` VARCHAR(60) NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'sent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `kind` ENUM('workshop','course') NOT NULL DEFAULT 'workshop',
  `slug` VARCHAR(191) NULL UNIQUE,
  `title` VARCHAR(191) NOT NULL,
  `short_desc` VARCHAR(255) NULL,
  `description` MEDIUMTEXT NULL,
  `image` VARCHAR(255) NULL,
  `teacher` VARCHAR(120) NULL,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 20,
  `price` INT UNSIGNED NOT NULL DEFAULT 0,
  `sale_price` INT UNSIGNED NULL,
  `deposit_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `reg_open` TINYINT(1) NOT NULL DEFAULT 1,
  `companion_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `companion_max` INT UNSIGNED NULL,
  `deposit_amount` INT UNSIGNED NULL,
  `companion_price` INT UNSIGNED NULL DEFAULT 0,
  `companion_price_mode` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  `ws_discount_type` ENUM('none','percent','fixed','tiered') NOT NULL DEFAULT 'none',
  `ws_discount_value` INT UNSIGNED NULL DEFAULT NULL,
  `ws_discount_tiers` TEXT NULL,
  `ws_discount_scope` ENUM('all','main','companion') NOT NULL DEFAULT 'all',
  `starts_at` DATETIME NULL,
  `ends_at` DATETIME NULL,
  `duration_hours` FLOAT NULL,
  `location` VARCHAR(191) NULL,
  `schedule_text` VARCHAR(191) NULL,
  `map_google` VARCHAR(500) NULL,
  `map_neshan` VARCHAR(500) NULL,
  `map_balad` VARCHAR(500) NULL,
  `sms_template` TEXT NULL,
  `seo_title` VARCHAR(200) NULL,
  `seo_desc` VARCHAR(320) NULL,
  `seo_kw` VARCHAR(120) NULL,
  `seo_noindex` TINYINT(1) NOT NULL DEFAULT 0,
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('active','inactive','completed') NOT NULL DEFAULT 'active',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}event_registrations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `companion_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `companions_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `amount_base` INT UNSIGNED NOT NULL DEFAULT 0,
  `paid_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `discount_amount` INT UNSIGNED NOT NULL DEFAULT 0,
  `discount_meta` TEXT NULL,
  `status` ENUM('pending','deposit','paid','canceled') NOT NULL DEFAULT 'pending',
  `social_reminded_at` DATETIME NULL,
  `settle_reminded_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_event` (`event_id`), KEY `ix_user` (`user_id`), KEY `ix_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}event_companions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `registration_id` BIGINT UNSIGNED NOT NULL,
  `first_name` VARCHAR(80) NOT NULL,
  `last_name` VARCHAR(80) NOT NULL,
  `mobile` VARCHAR(11) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `is_free` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','canceled') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_reg` (`registration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}consultants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `title` VARCHAR(150) NULL,
  `bio` TEXT NULL,
  `avatar` VARCHAR(255) NULL,
  `mobile` VARCHAR(11) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}consult_services` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `consultant_id` INT UNSIGNED NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `short_desc` VARCHAR(255) NULL,
  `description` MEDIUMTEXT NULL,
  `image` VARCHAR(255) NULL,
  `price` INT UNSIGNED NOT NULL DEFAULT 0,
  `deposit_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `deposit_amount` INT UNSIGNED NULL,
  `duration_min` INT UNSIGNED NOT NULL DEFAULT 45,
  `mode` ENUM('online','inperson','both') NOT NULL DEFAULT 'both',
  `meeting_hint` VARCHAR(255) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_consultant` (`consultant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}consult_schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_id` INT UNSIGNED NOT NULL,
  `weekday` TINYINT UNSIGNED NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  KEY `ix_service` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}consult_holidays` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `jdate` VARCHAR(10) NOT NULL UNIQUE,
  `title` VARCHAR(190) DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}consult_slots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_id` INT UNSIGNED NOT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `status` ENUM('open','held','booked','blocked') NOT NULL DEFAULT 'open',
  `held_until` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_slot` (`service_id`,`starts_at`),
  KEY `ix_status_time` (`status`,`starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}consult_appointments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slot_id` BIGINT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `consultant_id` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED DEFAULT NULL,
  `paid_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `mode` ENUM('online','inperson') NOT NULL DEFAULT 'inperson',
  `note` TEXT DEFAULT NULL,
  `status` ENUM('pending_payment','booked','done','canceled','noshow') NOT NULL DEFAULT 'pending_payment',
  `meeting_link` VARCHAR(255) NULL,
  `social_reminded_at` DATETIME NULL,
  `settle_reminded_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_user` (`user_id`,`status`),
  KEY `ix_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}wallet_transactions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` INT NOT NULL,
  `balance_after` INT NULL DEFAULT 0,
  `kind` ENUM('credit','debit','refund','gift','adjustment') NOT NULL DEFAULT 'credit',
  `type` VARCHAR(30) NULL DEFAULT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `admin_id` INT UNSIGNED NULL DEFAULT NULL,
  `reference_type` VARCHAR(40) DEFAULT NULL,
  `reference_id` BIGINT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_user` (`user_id`),
  KEY `ix_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}refund_requests` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `context` ENUM('event','consult','companion') NOT NULL,
  `reference_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` INT UNSIGNED NOT NULL,
  `reason` TEXT NULL,
  `status` ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
  `admin_note` TEXT NULL,
  `refund_ref` VARCHAR(80) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_user` (`user_id`), KEY `ix_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}banners` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(150) NOT NULL,
  `image` VARCHAR(255) NOT NULL,
  `link` VARCHAR(255) NULL,
  `placement` VARCHAR(40) NOT NULL DEFAULT 'dashboard',
  `sort_order` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}tickets` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `subject` VARCHAR(190) NOT NULL,
  `department` VARCHAR(50) NOT NULL DEFAULT 'general',
  `status` ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}ticket_messages` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `sender_type` ENUM('user','admin') NOT NULL DEFAULT 'user',
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `message` TEXT NOT NULL,
  `attachment` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}ticket_replies` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `author_id` BIGINT UNSIGNED NOT NULL,
  `is_admin` TINYINT NOT NULL DEFAULT 0,
  `body` TEXT NOT NULL,
  `attachment` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}activity_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(60) NOT NULL,
  `target_type` VARCHAR(40) NULL,
  `target_id` BIGINT UNSIGNED NULL,
  `meta` TEXT NULL,
  `ip` VARBINARY(16) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}otp_codes` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `mobile` VARCHAR(11) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `purpose` VARCHAR(30) NOT NULL DEFAULT 'login',
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}otps` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `mobile` VARCHAR(11) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `purpose` VARCHAR(32) NOT NULL DEFAULT 'login',
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `tries` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ip` VARBINARY(16) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_mobile_purpose` (`mobile`, `purpose`),
  KEY `ix_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}field_definitions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL UNIQUE,
  `label` VARCHAR(120) NOT NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT 'text',
  `options` TEXT NULL,
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}user_field_values` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `field_id` INT UNSIGNED NOT NULL,
  `value` TEXT NULL,
  UNIQUE KEY `uq_user_field` (`user_id`, `field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}clinical_screen_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `scale_code` VARCHAR(30) NOT NULL,
  `question_number` INT UNSIGNED NOT NULL,
  `text` TEXT NOT NULL,
  `weight` FLOAT NOT NULL DEFAULT 1.0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}contact_messages` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `mobile` VARCHAR(11) NOT NULL,
  `email` VARCHAR(190) NULL,
  `subject` VARCHAR(190) NULL,
  `message` TEXT NOT NULL,
  `ip` VARBINARY(16) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}contacts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `mobile` VARCHAR(11) NOT NULL UNIQUE,
  `email` VARCHAR(190) NULL,
  `tags` VARCHAR(255) NULL,
  `segment` VARCHAR(60) NULL,
  `bale_ok` TINYINT(1) NOT NULL DEFAULT 0,
  `tg_chat_id` VARCHAR(60) NULL,
  `bale_chat_id` VARCHAR(60) NULL,
  `tg_username` VARCHAR(60) NULL,
  `note` TEXT NULL,
  `invite_code` VARCHAR(60) NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}campaigns` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(150) NOT NULL,
  `channel` VARCHAR(30) NOT NULL DEFAULT 'sms',
  `template` TEXT NOT NULL,
  `status` ENUM('draft','running','completed','failed') NOT NULL DEFAULT 'draft',
  `scheduled_at` DATETIME NULL,
  `sms_invite` TINYINT(1) NOT NULL DEFAULT 0,
  `channels` VARCHAR(120) NULL,
  `batch_size` INT UNSIGNED NOT NULL DEFAULT 50,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}campaign_messages` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT UNSIGNED NOT NULL,
  `recipient` VARCHAR(100) NOT NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'sms',
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `body` TEXT NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_camp` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}campaign_targets` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT UNSIGNED NOT NULL,
  `recipient` VARCHAR(100) NOT NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_camp` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}campaign_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT UNSIGNED NOT NULL,
  `message` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}social_links` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `platform` VARCHAR(40) NOT NULL,
  `url` VARCHAR(255) NOT NULL,
  `title` VARCHAR(100) NULL,
  `sort` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}notify_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `channel` VARCHAR(30) NOT NULL,
  `payload` TEXT NOT NULL,
  `msg_kind` VARCHAR(60) NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'sent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}planner_recur` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `freq` VARCHAR(20) NOT NULL DEFAULT 'daily',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}review_labels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(120) NOT NULL,
  `color` VARCHAR(7) NOT NULL DEFAULT '#0c7a4d',
  `bg` VARCHAR(7) NOT NULL DEFAULT '#e9f9f1',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `context` ENUM('test','workshop','course') NOT NULL,
  `ref_id` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `author_name` VARCHAR(150) NOT NULL,
  `author_mobile` VARCHAR(11) DEFAULT NULL,
  `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `body` TEXT NOT NULL,
  `label_id` INT UNSIGNED DEFAULT NULL,
  `label_text` VARCHAR(120) DEFAULT NULL,
  `badge_auto` ENUM('participant','admin') DEFAULT NULL,
  `source` ENUM('user','admin','excel') NOT NULL DEFAULT 'user',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ref_status` (`context`,`ref_id`,`status`,`created_at`),
  KEY `ix_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}texts` (
  `k` VARCHAR(120) NOT NULL,
  `txt` TEXT NOT NULL,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}admin_roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(120) NOT NULL,
  `caps` TEXT NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}planner_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `ref_attempt_id` BIGINT UNSIGNED NULL,
  `day` DATE NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `time_note` VARCHAR(5) DEFAULT NULL,
  `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  `category` VARCHAR(60) NOT NULL DEFAULT 'personal',
  `note` VARCHAR(500) NOT NULL DEFAULT '',
  `recur_id` BIGINT UNSIGNED DEFAULT NULL,
  `done` TINYINT(1) NOT NULL DEFAULT 0,
  `done_at` DATETIME DEFAULT NULL,
  `remind_at` DATETIME DEFAULT NULL,
  `reminded_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_user_day` (`user_id`,`day`),
  KEY `ix_ref_att` (`ref_attempt_id`),
  KEY `ix_remind` (`reminded_at`,`remind_at`),
  KEY `ix_recur` (`recur_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}planner_cats` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT '#1e3a6e',
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_name` (`user_id`,`name`),
  KEY `ix_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}clinical_notes` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` INT UNSIGNED NOT NULL,
  `diagnosis` VARCHAR(255) NULL,
  `notes` MEDIUMTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_att` (`attempt_id`),
  KEY `ix_u` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}planner_rewards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `streak_from` INT NOT NULL,
  `streak_to` INT NOT NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'discount',
  `percent` INT UNSIGNED DEFAULT NULL,
  `code` VARCHAR(40) DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}newsletter` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mobile` VARCHAR(11) NOT NULL,
  `name` VARCHAR(150) DEFAULT NULL,
  `status` ENUM('active','unsub') NOT NULL DEFAULT 'active',
  `source` VARCHAR(30) NOT NULL DEFAULT 'footer',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unsub_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}site_pages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(190) NULL,
  `title` VARCHAR(190) NOT NULL,
  `slug` VARCHAR(190) NOT NULL UNIQUE,
  `content_html` LONGTEXT NULL,
  `content_css` LONGTEXT NULL,
  `builder_json` LONGTEXT NULL,
  `layout` VARCHAR(30) NOT NULL DEFAULT 'default',
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
  KEY `ix_active` (`active`),
  KEY `ix_page_type` (`page_type`),
  KEY `ix_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `{{prefix}}page_widgets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `page_id` INT UNSIGNED NOT NULL,
  `wtype` VARCHAR(40) NOT NULL,
  `settings` MEDIUMTEXT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `{{prefix}}test_scales` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `test_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `title_fa` VARCHAR(150) NOT NULL,
  `parent_code` VARCHAR(40) NULL,
  `color` VARCHAR(20) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  KEY `ix_test_code` (`test_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}sessions` (
  `id` VARCHAR(128) NOT NULL PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `admin_id` INT UNSIGNED NULL,
  `ip` VARBINARY(16) NULL,
  `user_agent` VARCHAR(255) NULL,
  `device` VARCHAR(60) NULL,
  `login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `logout_at` DATETIME NULL,
  KEY `ix_user` (`user_id`, `login_at`),
  KEY `ix_admin` (`admin_id`, `login_at`),
  KEY `ix_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}rate_limits` (
  `bucket` VARCHAR(80) NOT NULL PRIMARY KEY,
  `hits` INT UNSIGNED NOT NULL DEFAULT 1,
  `reset_at` DATETIME NOT NULL,
  KEY `ix_reset` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}activity_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `actor_type` VARCHAR(30) NOT NULL DEFAULT 'user',
  `actor_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(60) NOT NULL,
  `entity` VARCHAR(40) NULL,
  `entity_id` BIGINT UNSIGNED NULL,
  `meta` TEXT NULL,
  `ip` VARBINARY(16) NULL,
  `user_agent` VARCHAR(255) NULL,
  `device` VARCHAR(60) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `ix_actor` (`actor_type`, `actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT IGNORE INTO `{{prefix}}settings` (`key`, `value`, `autoload`) VALUES
('site_title', 'فریا روان', 1),
('site_slogan', 'سامانه جامع تست‌های روانشناسی، کارگاه و مشاوره', 1),
('footer_credit_text', 'طراحی و کد نویسی : احسان نادری پناه – 09024561001 | فریا', 1),
('footer_credit_url', 'https://feria.ir', 1);


INSERT IGNORE INTO `{{prefix}}tests` (`id`, `slug`, `title`, `short_desc`, `engine`, `audience`, `question_count`, `est_minutes`, `price_full`, `price_sale`, `free_enabled`, `sort_order`, `status`) VALUES
(1, 'disc-family', 'تست رفتارشناسی DISC خانواده', 'شناخت تمایلات رفتاری در تعاملات خانوادگی و فردی', 'disc', 'general', 28, 15, 390000, 249000, 1, 1, 'active'),
(2, 'disc-business', 'تست رفتارشناسی DISC کسب‌وکار و مدیران', 'ارزیابی سبک مدیریتی و رفتاری در محیط کاری', 'disc', 'manager', 28, 15, 550000, 349000, 1, 2, 'active'),
(3, 'neo', 'تست ۵ عاملی شخصیت نئو (NEO-FFI)', 'ارزیابی جامع پنج عامل اصلی شخصیت', 'likert', 'general', 60, 25, 450000, 290000, 1, 3, 'active');

SET FOREIGN_KEY_CHECKS=1;



