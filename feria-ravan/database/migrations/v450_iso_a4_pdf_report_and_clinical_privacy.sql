-- ============================================================================
-- نگارش ۴٫۵٫۰ فریا روان: تنظیمات کارنامه رسمی استاندارد ISO A4 و اعتبارسنجی
-- ============================================================================
INSERT INTO `fr_options` (`name`, `value`, `autoload`)
VALUES 
  ('report_show_signature_box', '1', 1),
  ('report_show_qr', '1', 1),
  ('report_header_color', '#1e3a6e', 1)
ON DUPLICATE KEY UPDATE `name`=`name`;

INSERT INTO `fr_options` (`name`, `value`, `autoload`)
VALUES ('fr_version', '4.5.0', 1)
ON DUPLICATE KEY UPDATE `value`='4.5.0';
