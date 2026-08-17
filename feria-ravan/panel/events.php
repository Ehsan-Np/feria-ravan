<?php
/**
 * سازگاری با نشانی قدیمی «کارگاه‌ها و دوره‌ها» — نسخهٔ ۲٫۲
 * برگهٔ واحد به دو برگهٔ مجزا (کارگاه‌ها / دوره‌ها) تفکیک شده است؛
 * این فایل با حفظ پارامترها به یکی از آن دو هدایت می‌کند.
 */
declare(strict_types=1);
$to = '/panel/workshops.php';
if ((string)($_GET['kind'] ?? '') === 'course') $to = '/panel/courses.php';
$qs = [];
foreach (['ok','err'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $qs[$k] = (string)$_GET[$k];
}
if ($qs) $to .= '?' . http_build_query($qs);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
fr_bootstrap();
redirect(SITE_URL . $to);
