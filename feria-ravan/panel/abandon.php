<?php
/** انصراف از جلسهٔ ناتمام تست روانشناسی (برای شروع مجدد) */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
fr_bootstrap();
$u = require_user();
$id = (int)($_GET['attempt'] ?? 0);
DB::run('DELETE a FROM ' . DB::t('answers') . ' a JOIN ' . DB::t('attempts') .
        ' t ON t.id=a.attempt_id WHERE a.attempt_id=? AND t.user_id=? AND t.status="in_progress"', [$id, $u['id']]);
DB::run('DELETE o FROM ' . DB::t('orders') . ' o JOIN ' . DB::t('attempts') .
        ' t ON t.order_id=o.id WHERE t.id=? AND t.user_id=? AND t.status="in_progress" AND o.status="pending"', [$id, $u['id']]);
DB::run('DELETE FROM ' . DB::t('attempts') . ' WHERE id=? AND user_id=? AND status="in_progress"', [$id, $u['id']]);
log_activity('test_abandon', 'attempt', $id);
redirect((string)($_GET['back'] ?? (SITE_URL . '/panel/')));
