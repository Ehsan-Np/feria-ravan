<?php
/**
 * ============================================================================
 *  صفحهٔ عمومی یک دوره — بدون نیاز به ورود (نسخهٔ ۱٫۴)
 *  نشانی: /course.php?s=اسلاگ  (هویت رنگی فیروزه‌ای)
 * ============================================================================
 */
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install/'); exit; }
$PUBLIC_KIND = 'course';
require __DIR__ . '/includes/public-event.php';
