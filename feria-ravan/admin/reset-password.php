<?php
/**
 * بازیابی رمز عبور مدیریت — هدایت به تب اختصاصی در صفحه ورود
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

redirect(SITE_URL . '/admin/login.php?tab=reset');
