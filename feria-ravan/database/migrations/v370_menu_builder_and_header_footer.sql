-- ============================================================================
-- مهاجرت نگارش ۳٫۷٫۰: سیستم فهرست‌ساز و قالب‌های سربرگ و پاورقی فریا روان
-- ============================================================================

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
