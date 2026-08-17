<?php
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
admin_logout();
redirect(SITE_URL . '/admin/login.php');
