-- ============================================================================
-- نگارش ۴٫۷٫۰ فریا روان: حل ریشه‌ای خطای دریافت فایل تحلیل کارنامه
-- ۱) حذف کامل خطای تهی شدن کارنامه (0 bytes) در آزمون‌های DISC, MSLIKERT, MMPI و...
-- ۲) استقرار بارکد QR وکتور محلی خالص بدون وابستگی به Google Chart API
-- ۳) تبدیل هوشمند تصاویر و مهرهای کارنامه به Data URI جهت رفع خطای Tainted Canvas
-- ۴) فراهم‌سازی امکان دانلود مستقیم سند کارنامه علاوه بر چاپ استاندارد ISO A4
-- ============================================================================

INSERT INTO `fr_settings` (`key`, `value`, `autoload`)
VALUES 
  ('report_download_fallback', '1', 1),
  ('report_qr_engine', 'local_svg', 1)
ON DUPLICATE KEY UPDATE `key`=`key`;

INSERT INTO `fr_settings` (`key`, `value`, `autoload`)
VALUES ('fr_version', '4.7.0', 1)
ON DUPLICATE KEY UPDATE `value`='4.7.0';
