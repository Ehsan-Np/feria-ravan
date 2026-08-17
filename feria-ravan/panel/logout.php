<?php
/** خروج از حساب کاربری */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
fr_bootstrap();
do_user_logout();
redirect(SITE_URL . '/auth.php');
