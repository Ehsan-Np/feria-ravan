-- ============================================================================
-- مهاجرت نگارش ۴٫۰٫۰: ری‌برندینگ و سیستم White-Label کامل، تقویم شمسی جامع،
-- پایدارسازی صفحه‌ساز بصری و مدیر منو، و پاکسازی امن فایل‌های نصاب
-- ============================================================================

-- ۱. مقادیر پیش‌فرض هویت بصری، White-Label و نشان تجاری در جدول تنظیمات
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
  ('support_bale', '', 1);

-- ۲. به‌روزرسانی شماره نگارش جاری سامانه در پایگاه داده
INSERT INTO `{{prefix}}settings` (`key`, `value`, `autoload`)
VALUES ('system_version', '4.0.0', 1)
ON DUPLICATE KEY UPDATE `value` = '4.0.0';
