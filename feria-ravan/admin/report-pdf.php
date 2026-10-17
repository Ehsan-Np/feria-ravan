<?php
/**
 * فوروارد امن درخواست دریافت PDF کارنامه از پنل مدیریت به کنترلر کارنامه
 * پشتیبانی کامل از نشست مدیر سامانه برای مشاهده گزارش تمام مراجعین
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$attemptId = (int)($_GET['attempt'] ?? ($_GET['id'] ?? 0));
if ($attemptId <= 0) {
    redirect(SITE_URL . '/admin/attempts.php');
}

require __DIR__ . '/../panel/report-pdf.php';
