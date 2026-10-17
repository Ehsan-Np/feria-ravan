-- ============================================================================
-- مهاجرت نگارش ۳٫۶٫۰: جدول برگه‌های سایت و صفحه‌ساز دیداری فریا روان
-- ============================================================================

CREATE TABLE IF NOT EXISTS `{{prefix}}site_pages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(190) NULL,
  `title` VARCHAR(190) NOT NULL,
  `slug` VARCHAR(190) NOT NULL UNIQUE,
  `content_html` LONGTEXT NULL,
  `content_css` LONGTEXT NULL,
  `builder_json` LONGTEXT NULL,
  `layout` VARCHAR(30) NOT NULL DEFAULT 'default',
  `seo_title` VARCHAR(190) NULL,
  `meta_desc` VARCHAR(300) NULL,
  `noindex` TINYINT(1) NOT NULL DEFAULT 0,
  `is_home` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `ix_is_home` (`is_home`),
  KEY `ix_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
