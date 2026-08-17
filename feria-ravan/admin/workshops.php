<?php
/**
 * =============================================================================
 *  مدیریت اختصاصی کارگاه‌ها (نسخهٔ ۱٫۴) — جدا از دوره‌ها
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin','manager']);
require_once FR_ROOT . '/includes/admin-events-shared.php';
admin_events_page($adm, 'workshop');
