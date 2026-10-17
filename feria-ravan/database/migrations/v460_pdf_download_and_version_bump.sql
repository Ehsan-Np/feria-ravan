-- ============================================================================
-- نگارش ۴٫۶٫۰ فریا روان: پایدارسازی دانلود مستقیم کارنامه و به‌روزرسانی نگارش
-- ============================================================================
INSERT INTO `fr_settings` (`key`, `value`, `autoload`)
VALUES 
  ('report_pdf_download_mode', 'direct', 1)
ON DUPLICATE KEY UPDATE `key`=`key`;

INSERT INTO `fr_settings` (`key`, `value`, `autoload`)
VALUES ('fr_version', '4.6.0', 1)
ON DUPLICATE KEY UPDATE `value`='4.6.0';
