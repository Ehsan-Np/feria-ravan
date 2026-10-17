<?php
/**
 * فوروارد امن درخواست دریافت PDF کارنامه از پنل مدیریت به کنترلر کارنامه
 * پشتیبانی کامل از نشست مدیر سامانه برای مشاهده گزارش تمام مراجعین
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../core/Analyzer.php';
fr_bootstrap();

require_admin();

$attemptId = (int)($_GET['attempt'] ?? ($_GET['id'] ?? 0));
if ($attemptId <= 0) {
    redirect(SITE_URL . '/admin/attempts.php');
}

require __DIR__ . '/../panel/report-pdf.php';
