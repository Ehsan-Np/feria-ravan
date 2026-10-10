<?php
/**
 * ============================================================================
 *  مدیریت سطل زباله، حذف نرم و بازیابی سیستم (Trash & Soft-Delete Manager)
 *  ─ حذف امن (انتقال به سطل زباله) بدون از دست رفتن اطلاعات
 *  ─ بازیابی سریع (Restore) به حالت فعال
 *  ─ حذف نهایی و پاک‌سازی کامل (Permanent Purge) همراه با تمام وابستگی‌ها
 *  ─ مدیریت یکپارچه در پنل مدیریت (admin/trash.php) و پنل کاربری (panel/trash.php)
 * ============================================================================
 */
declare(strict_types=1);

final class TrashManager
{
    private static bool $schemaEnsured = false;

    /**
     * تضمین خودکار فیلدهای deleted_at و deleted_by و تبدیل ستون‌های وضعیت
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $p = DB_PREFIX;
        $tables = [
            'attempts'            => 'status',
            'events'              => 'status',
            'users'               => 'status',
            'tests'               => 'status',
            'tickets'             => 'status',
            'event_registrations' => 'status',
            'reviews'             => 'status',
        ];

        // تضمین وجود جدول ticket_replies جهت سازگاری کامل کنترلرهای تیکت
        try {
            DB::run("CREATE TABLE IF NOT EXISTS `{$p}ticket_replies` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `ticket_id` BIGINT UNSIGNED NOT NULL,
                `author_id` BIGINT UNSIGNED NOT NULL,
                `is_admin` TINYINT NOT NULL DEFAULT 0,
                `body` TEXT NOT NULL,
                `attachment` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `ix_ticket` (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {}

        foreach ($tables as $tbl => $statusCol) {
            $tableName = "{$p}{$tbl}";
            try {
                // بررسی وجود جدول
                $exists = DB::val("SHOW TABLES LIKE '{$tableName}'");
                if (!$exists) {
                    continue;
                }

                $cols = array_column(DB::all("SHOW COLUMNS FROM `{$tableName}`"), 'Field');

                if (!in_array('deleted_at', $cols, true)) {
                    DB::run("ALTER TABLE `{$tableName}` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `{$statusCol}`");
                    try {
                        DB::run("ALTER TABLE `{$tableName}` ADD INDEX `ix_deleted_at` (`deleted_at`)");
                    } catch (Throwable $e) {}
                }

                if (!in_array('deleted_by', $cols, true)) {
                    DB::run("ALTER TABLE `{$tableName}` ADD COLUMN `deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`");
                }

                // ارتقای نوع ENUM به VARCHAR(32) برای جلوگیری از خطای حالت Strict دیتابیس
                if ($statusCol !== '') {
                    $colInfo = DB::one("SHOW COLUMNS FROM `{$tableName}` WHERE Field='{$statusCol}'");
                    if ($colInfo && str_starts_with(strtolower((string)$colInfo['Type']), 'enum')) {
                        $def = ($tbl === 'attempts') ? "'in_progress'"
                             : (($tbl === 'tests') ? "'draft'"
                             : (($tbl === 'tickets') ? "'open'"
                             : (($tbl === 'event_registrations') ? "'pending'" : "'active'")));
                        try {
                            DB::run("ALTER TABLE `{$tableName}` MODIFY COLUMN `{$statusCol}` VARCHAR(32) NOT NULL DEFAULT {$def}");
                        } catch (Throwable $e) {}
                    }
                }
            } catch (Throwable $e) {
                error_log("Feria TrashManager schema [{$tbl}]: " . $e->getMessage());
            }
        }

        self::$schemaEnsured = true;
    }

    /**
     * انتقال یک مورد به سطل زباله (Soft Delete)
     */
    public static function trash(string $type, int $id, ?int $operatorId = null): bool
    {
        self::ensureSchema();
        if ($id <= 0) return false;
        $now = date('Y-m-d H:i:s');

        try {
            switch ($type) {
                case 'attempt':
                    $row = DB::one("SELECT a.id, t.title FROM " . DB::t('attempts') . " a JOIN " . DB::t('tests') . " t ON t.id=a.test_id WHERE a.id=?", [$id]);
                    if (!$row) return false;
                    DB::update('attempts', [
                        'deleted_at' => $now,
                        'deleted_by' => $operatorId,
                    ], 'id=?', [$id]);
                    log_activity('attempt_trash', 'attempt', $id, ['title' => $row['title']]);
                    return true;

                case 'event':
                    $row = DB::one("SELECT id, title, kind FROM " . DB::t('events') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('events', [
                        'deleted_at' => $now,
                        'deleted_by' => $operatorId,
                        'status'     => 'inactive',
                    ], 'id=?', [$id]);
                    log_activity('event_trash', 'event', $id, ['title' => $row['title'], 'kind' => $row['kind']]);
                    return true;

                case 'user':
                    $row = DB::one("SELECT id, first_name, last_name, mobile FROM " . DB::t('users') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('users', [
                        'deleted_at' => $now,
                        'deleted_by' => $operatorId,
                        'status'     => 'banned',
                    ], 'id=?', [$id]);
                    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $row['mobile'];
                    log_activity('user_trash', 'user', $id, ['name' => $name, 'mobile' => $row['mobile']]);
                    return true;

                case 'test':
                    $row = DB::one("SELECT id, title FROM " . DB::t('tests') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('tests', [
                        'deleted_at' => $now,
                        'deleted_by' => $operatorId,
                        'status'     => 'trash',
                    ], 'id=?', [$id]);
                    log_activity('test_trash', 'test', $id, ['title' => $row['title']]);
                    return true;

                case 'ticket':
                    $row = DB::one("SELECT id, subject FROM " . DB::t('tickets') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('tickets', [
                        'deleted_at' => $now,
                        'deleted_by' => $operatorId,
                    ], 'id=?', [$id]);
                    log_activity('ticket_trash', 'ticket', $id, ['subject' => $row['subject']]);
                    return true;

                case 'registration':
                    $row = DB::one("SELECT id, event_id FROM " . DB::t('event_registrations') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('event_registrations', [
                        'deleted_at' => $now,
                        'deleted_by' => $operatorId,
                    ], 'id=?', [$id]);
                    log_activity('registration_trash', 'registration', $id);
                    return true;

                case 'review':
                    $row = DB::one("SELECT id, author_name FROM " . DB::t('reviews') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('reviews', [
                        'deleted_at' => $now,
                        'deleted_by' => $operatorId,
                        'status'     => 'rejected',
                    ], 'id=?', [$id]);
                    log_activity('review_trash', 'review', $id);
                    return true;
            }
        } catch (Throwable $e) {
            error_log("Feria TrashManager::trash failed [{$type}:{$id}]: " . $e->getMessage());
        }

        return false;
    }

    /**
     * بازیابی یک مورد از سطل زباله (Restore)
     */
    public static function restore(string $type, int $id, ?int $userId = null): bool
    {
        self::ensureSchema();
        if ($id <= 0) return false;

        try {
            switch ($type) {
                case 'attempt':
                    $where = 'id=?';
                    $params = [$id];
                    if ($userId !== null) {
                        $where .= ' AND user_id=?';
                        $params[] = $userId;
                    }
                    $row = DB::one("SELECT id FROM " . DB::t('attempts') . " WHERE {$where}", $params);
                    if (!$row) return false;
                    DB::update('attempts', [
                        'deleted_at' => null,
                        'deleted_by' => null,
                    ], 'id=?', [$id]);
                    log_activity('attempt_restore', 'attempt', $id);
                    return true;

                case 'event':
                    $row = DB::one("SELECT id FROM " . DB::t('events') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('events', [
                        'deleted_at' => null,
                        'deleted_by' => null,
                        'status'     => 'active',
                    ], 'id=?', [$id]);
                    log_activity('event_restore', 'event', $id);
                    return true;

                case 'user':
                    $row = DB::one("SELECT id FROM " . DB::t('users') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('users', [
                        'deleted_at' => null,
                        'deleted_by' => null,
                        'status'     => 'active',
                    ], 'id=?', [$id]);
                    log_activity('user_restore', 'user', $id);
                    return true;

                case 'test':
                    $row = DB::one("SELECT id FROM " . DB::t('tests') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('tests', [
                        'deleted_at' => null,
                        'deleted_by' => null,
                        'status'     => 'active',
                    ], 'id=?', [$id]);
                    log_activity('test_restore', 'test', $id);
                    return true;

                case 'ticket':
                    $where = 'id=?';
                    $params = [$id];
                    if ($userId !== null) {
                        $where .= ' AND user_id=?';
                        $params[] = $userId;
                    }
                    $row = DB::one("SELECT id FROM " . DB::t('tickets') . " WHERE {$where}", $params);
                    if (!$row) return false;
                    DB::update('tickets', [
                        'deleted_at' => null,
                        'deleted_by' => null,
                    ], 'id=?', [$id]);
                    log_activity('ticket_restore', 'ticket', $id);
                    return true;

                case 'registration':
                    $where = 'id=?';
                    $params = [$id];
                    if ($userId !== null) {
                        $where .= ' AND user_id=?';
                        $params[] = $userId;
                    }
                    $row = DB::one("SELECT id FROM " . DB::t('event_registrations') . " WHERE {$where}", $params);
                    if (!$row) return false;
                    DB::update('event_registrations', [
                        'deleted_at' => null,
                        'deleted_by' => null,
                    ], 'id=?', [$id]);
                    log_activity('registration_restore', 'registration', $id);
                    return true;

                case 'review':
                    $row = DB::one("SELECT id FROM " . DB::t('reviews') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    DB::update('reviews', [
                        'deleted_at' => null,
                        'deleted_by' => null,
                        'status'     => 'approved',
                    ], 'id=?', [$id]);
                    log_activity('review_restore', 'review', $id);
                    return true;
            }
        } catch (Throwable $e) {
            error_log("Feria TrashManager::restore failed [{$type}:{$id}]: " . $e->getMessage());
        }

        return false;
    }

    /**
     * حذف کامل و نهایی از پایگاه داده (Permanent Purge)
     */
    public static function purge(string $type, int $id, ?int $userId = null): bool
    {
        self::ensureSchema();
        if ($id <= 0) return false;

        try {
            switch ($type) {
                case 'attempt':
                    $where = 'id=?';
                    $params = [$id];
                    if ($userId !== null) {
                        $where .= ' AND user_id=?';
                        $params[] = $userId;
                    }
                    $row = DB::one("SELECT id FROM " . DB::t('attempts') . " WHERE {$where}", $params);
                    if (!$row) return false;

                    DB::pdo()->beginTransaction();
                    try {
                        try { DB::run("DELETE FROM " . DB::t('clinical_notes') . " WHERE attempt_id=?", [$id]); } catch (Throwable $e) {}
                        try { DB::run("DELETE FROM " . DB::t('answers') . " WHERE attempt_id=?", [$id]); } catch (Throwable $e) {}
                        try { DB::run("DELETE FROM " . DB::t('results') . " WHERE attempt_id=?", [$id]); } catch (Throwable $e) {}
                        try { DB::run("DELETE FROM " . DB::t('reports') . " WHERE attempt_id=?", [$id]); } catch (Throwable $e) {}
                        try { DB::run("UPDATE " . DB::t('planner_tasks') . " SET ref_attempt_id=NULL WHERE ref_attempt_id=?", [$id]); } catch (Throwable $e) {}
                        try { DB::run("DELETE FROM " . DB::t('dyads') . " WHERE attempt1_id=? OR attempt2_id=?", [$id, $id]); } catch (Throwable $e) {}
                        DB::run("DELETE FROM " . DB::t('attempts') . " WHERE id=?", [$id]);
                        DB::pdo()->commit();
                        log_activity('attempt_purge', 'attempt', $id);
                        return true;
                    } catch (Throwable $e) {
                        if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
                        throw $e;
                    }

                case 'event':
                    $row = DB::one("SELECT id, title, image, kind FROM " . DB::t('events') . " WHERE id=?", [$id]);
                    if (!$row) return false;

                    DB::pdo()->beginTransaction();
                    try {
                        $rids = array_column(DB::all("SELECT id FROM " . DB::t('event_registrations') . " WHERE event_id=?", [$id]), 'id');
                        if ($rids) {
                            $inRegs = '(' . implode(',', array_map('intval', $rids)) . ')';
                            try { DB::run("DELETE FROM " . DB::t('event_companions') . " WHERE reg_id IN {$inRegs}"); } catch (Throwable $e) {}
                            try { DB::run("DELETE FROM " . DB::t('event_registrations') . " WHERE id IN {$inRegs}"); } catch (Throwable $e) {}
                        }
                        try { DB::run("DELETE FROM " . DB::t('reviews') . " WHERE context=? AND ref_id=?", [$row['kind'], $id]); } catch (Throwable $e) {}
                        if (!empty($row['image'])) {
                            try {
                                require_once FR_ROOT . '/includes/upload.php';
                                fr_delete_image($row['image']);
                            } catch (Throwable $e) {}
                        }
                        DB::run("DELETE FROM " . DB::t('events') . " WHERE id=?", [$id]);
                        DB::pdo()->commit();
                        log_activity('event_purge', 'event', $id, ['title' => $row['title']]);
                        return true;
                    } catch (Throwable $e) {
                        if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
                        throw $e;
                    }

                case 'user':
                    $row = DB::one("SELECT id, mobile, first_name, last_name FROM " . DB::t('users') . " WHERE id=?", [$id]);
                    if (!$row) return false;
                    require_once FR_ROOT . '/includes/functions.php';
                    if (function_exists('fr_admin_delete_user_fully')) {
                        fr_admin_delete_user_fully($id, (string)$row['mobile']);
                    } else {
                        DB::run("DELETE FROM " . DB::t('users') . " WHERE id=?", [$id]);
                    }
                    log_activity('user_purge', 'user', $id);
                    return true;

                case 'test':
                    require_once FR_ROOT . '/includes/functions.php';
                    if (function_exists('fr_purge_test')) {
                        return fr_purge_test($id);
                    }
                    DB::run("DELETE FROM " . DB::t('tests') . " WHERE id=?", [$id]);
                    return true;

                case 'ticket':
                    $where = 'id=?';
                    $params = [$id];
                    if ($userId !== null) {
                        $where .= ' AND user_id=?';
                        $params[] = $userId;
                    }
                    $row = DB::one("SELECT id FROM " . DB::t('tickets') . " WHERE {$where}", $params);
                    if (!$row) return false;

                    try {
                        $reps = DB::all("SELECT attachment FROM " . DB::t('ticket_replies') . " WHERE ticket_id=? AND attachment IS NOT NULL", [$id]);
                        foreach ($reps as $rp) {
                            if (!empty($rp['attachment'])) {
                                @unlink(FR_ROOT . '/' . ltrim((string)$rp['attachment'], '/'));
                            }
                        }
                    } catch (Throwable $e) {}

                    try { DB::run("DELETE FROM " . DB::t('ticket_replies') . " WHERE ticket_id=?", [$id]); } catch (Throwable $e) {}
                    DB::run("DELETE FROM " . DB::t('tickets') . " WHERE id=?", [$id]);
                    log_activity('ticket_purge', 'ticket', $id);
                    return true;

                case 'registration':
                    $where = 'id=?';
                    $params = [$id];
                    if ($userId !== null) {
                        $where .= ' AND user_id=?';
                        $params[] = $userId;
                    }
                    $row = DB::one("SELECT id FROM " . DB::t('event_registrations') . " WHERE {$where}", $params);
                    if (!$row) return false;

                    try { DB::run("DELETE FROM " . DB::t('event_companions') . " WHERE reg_id=?", [$id]); } catch (Throwable $e) {}
                    DB::run("DELETE FROM " . DB::t('event_registrations') . " WHERE id=?", [$id]);
                    log_activity('registration_purge', 'registration', $id);
                    return true;

                case 'review':
                    DB::run("DELETE FROM " . DB::t('reviews') . " WHERE id=?", [$id]);
                    log_activity('review_purge', 'review', $id);
                    return true;
            }
        } catch (Throwable $e) {
            error_log("Feria TrashManager::purge failed [{$type}:{$id}]: " . $e->getMessage());
        }

        return false;
    }

    /**
     * خالی کردن کامل سطل زباله (Empty Trash)
     */
    public static function emptyTrash(string $type = 'all', ?int $userId = null): int
    {
        self::ensureSchema();
        $count = 0;
        $types = ($type === 'all') ? ['attempt', 'event', 'test', 'user', 'ticket', 'registration', 'review'] : [$type];

        foreach ($types as $t) {
            $ids = self::getTrashedIds($t, $userId);
            foreach ($ids as $id) {
                if (self::purge($t, $id, $userId)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * استخراج شناسه‌های موارد در سطل زباله
     */
    public static function getTrashedIds(string $type, ?int $userId = null): array
    {
        self::ensureSchema();
        try {
            switch ($type) {
                case 'attempt':
                    $sql = "SELECT id FROM " . DB::t('attempts') . " WHERE deleted_at IS NOT NULL";
                    $p = [];
                    if ($userId !== null) { $sql .= " AND user_id=?"; $p[] = $userId; }
                    return array_map('intval', array_column(DB::all($sql, $p), 'id'));

                case 'event':
                    return array_map('intval', array_column(DB::all("SELECT id FROM " . DB::t('events') . " WHERE deleted_at IS NOT NULL"), 'id'));

                case 'user':
                    return array_map('intval', array_column(DB::all("SELECT id FROM " . DB::t('users') . " WHERE deleted_at IS NOT NULL OR status='deleted'"), 'id'));

                case 'test':
                    return array_map('intval', array_column(DB::all("SELECT id FROM " . DB::t('tests') . " WHERE deleted_at IS NOT NULL OR status='trash'"), 'id'));

                case 'ticket':
                    $sql = "SELECT id FROM " . DB::t('tickets') . " WHERE deleted_at IS NOT NULL";
                    $p = [];
                    if ($userId !== null) { $sql .= " AND user_id=?"; $p[] = $userId; }
                    return array_map('intval', array_column(DB::all($sql, $p), 'id'));

                case 'registration':
                    $sql = "SELECT id FROM " . DB::t('event_registrations') . " WHERE deleted_at IS NOT NULL";
                    $p = [];
                    if ($userId !== null) { $sql .= " AND user_id=?"; $p[] = $userId; }
                    return array_map('intval', array_column(DB::all($sql, $p), 'id'));

                case 'review':
                    return array_map('intval', array_column(DB::all("SELECT id FROM " . DB::t('reviews') . " WHERE deleted_at IS NOT NULL"), 'id'));
            }
        } catch (Throwable $e) {
            error_log("Feria TrashManager::getTrashedIds [{$type}]: " . $e->getMessage());
        }

        return [];
    }

    /**
     * آمار تعداد موارد در سطل زباله
     */
    public static function getStats(?int $userId = null): array
    {
        self::ensureSchema();
        $stats = [
            'attempts'      => 0,
            'events'        => 0,
            'users'         => 0,
            'tests'         => 0,
            'tickets'       => 0,
            'registrations' => 0,
            'reviews'       => 0,
            'total'         => 0,
        ];

        try {
            if ($userId !== null) {
                $stats['attempts']      = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE deleted_at IS NOT NULL AND user_id=?", [$userId]);
                $stats['tickets']       = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE deleted_at IS NOT NULL AND user_id=?", [$userId]);
                $stats['registrations'] = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('event_registrations') . " WHERE deleted_at IS NOT NULL AND user_id=?", [$userId]);
                $stats['total']         = $stats['attempts'] + $stats['tickets'] + $stats['registrations'];
            } else {
                $stats['attempts']      = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE deleted_at IS NOT NULL");
                $stats['events']        = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('events') . " WHERE deleted_at IS NOT NULL");
                $stats['users']         = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE deleted_at IS NOT NULL OR status='deleted'");
                $stats['tests']         = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE deleted_at IS NOT NULL OR status='trash'");
                $stats['tickets']       = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE deleted_at IS NOT NULL");
                $stats['registrations'] = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('event_registrations') . " WHERE deleted_at IS NOT NULL");
                $stats['reviews']       = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('reviews') . " WHERE deleted_at IS NOT NULL");
                $stats['total']         = $stats['attempts'] + $stats['events'] + $stats['users'] + $stats['tests'] + $stats['tickets'] + $stats['registrations'] + $stats['reviews'];
            }
        } catch (Throwable $e) {
            error_log("Feria TrashManager::getStats error: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * دریافت اقلام سطل زباله با ساختار یکنواخت جهت نمایش
     */
    public static function getList(string $type = 'all', int $limit = 100, int $offset = 0, ?int $userId = null): array
    {
        self::ensureSchema();
        $items = [];

        try {
            // ۱. آزمون‌ها
            if ($type === 'all' || $type === 'attempts') {
                $sql = "SELECT a.id, a.user_id, a.mode, a.status, a.started_at, a.completed_at, a.deleted_at,
                               t.title AS test_title, u.first_name, u.last_name, u.mobile
                        FROM " . DB::t('attempts') . " a
                        JOIN " . DB::t('tests') . " t ON t.id=a.test_id
                        LEFT JOIN " . DB::t('users') . " u ON u.id=a.user_id
                        WHERE a.deleted_at IS NOT NULL";
                $p = [];
                if ($userId !== null) { $sql .= " AND a.user_id=?"; $p[] = $userId; }
                $sql .= " ORDER BY a.deleted_at DESC LIMIT {$limit} OFFSET {$offset}";
                $rows = DB::all($sql, $p);
                foreach ($rows as $r) {
                    $uName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['mobile'] ?? 'کاربر');
                    $items[] = [
                        'type'        => 'attempt',
                        'type_label'  => 'جلسه آزمون',
                        'type_icon'   => '🧠',
                        'id'          => (int)$r['id'],
                        'title'       => $r['test_title'],
                        'meta'        => 'کاربر: ' . $uName . ' · نسخه: ' . ($r['mode'] === 'full' ? 'ویژه' : 'رایگان'),
                        'deleted_at'  => $r['deleted_at'],
                        'restore_act' => 'restore_attempt',
                        'purge_act'   => 'purge_attempt',
                    ];
                }
            }

            // ۲. تست‌های روانشناسی
            if (($type === 'all' || $type === 'tests') && $userId === null) {
                $rows = DB::all("SELECT id, title, slug, engine, category, deleted_at, created_at FROM " . DB::t('tests') . "
                                 WHERE deleted_at IS NOT NULL OR status='trash'
                                 ORDER BY COALESCE(deleted_at, created_at) DESC LIMIT {$limit} OFFSET {$offset}");
                foreach ($rows as $r) {
                    $items[] = [
                        'type'        => 'test',
                        'type_label'  => 'تست روانشناسی',
                        'type_icon'   => '📋',
                        'id'          => (int)$r['id'],
                        'title'       => $r['title'],
                        'meta'        => 'موتور: ' . ($r['engine'] ?? 'روان‌سنجی') . ' · اسلاگ: ' . $r['slug'],
                        'deleted_at'  => $r['deleted_at'] ?? $r['created_at'],
                        'restore_act' => 'restore_test',
                        'purge_act'   => 'purge_test',
                    ];
                }
            }

            // ۳. کارگاه‌ها و دوره‌ها
            if (($type === 'all' || $type === 'events') && $userId === null) {
                $rows = DB::all("SELECT id, title, kind, teacher, starts_at, deleted_at FROM " . DB::t('events') . "
                                 WHERE deleted_at IS NOT NULL
                                 ORDER BY deleted_at DESC LIMIT {$limit} OFFSET {$offset}");
                foreach ($rows as $r) {
                    $kindTitle = ($r['kind'] === 'course') ? 'دوره آموزشی' : 'کارگاه آموزشی';
                    $items[] = [
                        'type'        => 'event',
                        'type_label'  => $kindTitle,
                        'type_icon'   => ($r['kind'] === 'course') ? '📚' : '🛠',
                        'id'          => (int)$r['id'],
                        'title'       => $r['title'],
                        'meta'        => ($r['teacher'] ? 'مدرس: ' . $r['teacher'] . ' · ' : '') . 'نوع: ' . $kindTitle,
                        'deleted_at'  => $r['deleted_at'],
                        'restore_act' => 'restore_event',
                        'purge_act'   => 'purge_event',
                    ];
                }
            }

            // ۴. کاربران
            if (($type === 'all' || $type === 'users') && $userId === null) {
                $rows = DB::all("SELECT id, first_name, last_name, mobile, national_code, username, deleted_at, created_at FROM " . DB::t('users') . "
                                 WHERE deleted_at IS NOT NULL OR status='deleted'
                                 ORDER BY COALESCE(deleted_at, created_at) DESC LIMIT {$limit} OFFSET {$offset}");
                foreach ($rows as $r) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'بی‌نام';
                    $items[] = [
                        'type'        => 'user',
                        'type_label'  => 'کاربر سیستم',
                        'type_icon'   => '👤',
                        'id'          => (int)$r['id'],
                        'title'       => $name . ' (' . $r['mobile'] . ')',
                        'meta'        => ($r['username'] ? '@' . $r['username'] . ' · ' : '') . ($r['national_code'] ? 'کد ملی: ' . $r['national_code'] : 'شناسه: #' . $r['id']),
                        'deleted_at'  => $r['deleted_at'] ?? $r['created_at'],
                        'restore_act' => 'restore_user',
                        'purge_act'   => 'purge_user',
                    ];
                }
            }

            // ۵. تیکت‌ها
            if ($type === 'all' || $type === 'tickets') {
                $sql = "SELECT t.id, t.subject, t.status, t.priority, t.deleted_at, t.created_at,
                               u.first_name, u.last_name, u.mobile
                        FROM " . DB::t('tickets') . " t
                        LEFT JOIN " . DB::t('users') . " u ON u.id=t.user_id
                        WHERE t.deleted_at IS NOT NULL";
                $p = [];
                if ($userId !== null) { $sql .= " AND t.user_id=?"; $p[] = $userId; }
                $sql .= " ORDER BY t.deleted_at DESC LIMIT {$limit} OFFSET {$offset}";
                $rows = DB::all($sql, $p);
                foreach ($rows as $r) {
                    $uName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['mobile'] ?? 'کاربر');
                    $items[] = [
                        'type'        => 'ticket',
                        'type_label'  => 'تیکت پشتیبانی',
                        'type_icon'   => '🎫',
                        'id'          => (int)$r['id'],
                        'title'       => $r['subject'],
                        'meta'        => 'ارسال‌کننده: ' . $uName . ' · وضعیت قبلی: ' . $r['status'],
                        'deleted_at'  => $r['deleted_at'] ?? $r['created_at'],
                        'restore_act' => 'restore_ticket',
                        'purge_act'   => 'purge_ticket',
                    ];
                }
            }

            // ۶. نظرات و دیدگاه‌ها
            if (($type === 'all' || $type === 'reviews') && $userId === null) {
                $rows = DB::all("SELECT id, author_name, context, body, deleted_at, created_at FROM " . DB::t('reviews') . "
                                 WHERE deleted_at IS NOT NULL
                                 ORDER BY deleted_at DESC LIMIT {$limit} OFFSET {$offset}");
                foreach ($rows as $r) {
                    $items[] = [
                        'type'        => 'review',
                        'type_label'  => 'دیدگاه مراجع',
                        'type_icon'   => '💬',
                        'id'          => (int)$r['id'],
                        'title'       => $r['author_name'] ?: 'کاربر ناشناس',
                        'meta'        => mb_substr((string)$r['body'], 0, 80) . '...',
                        'deleted_at'  => $r['deleted_at'] ?? $r['created_at'],
                        'restore_act' => 'restore_review',
                        'purge_act'   => 'purge_review',
                    ];
                }
            }

            // مرتب‌سازی تجمیعی بر اساس زمان حذف (نزولی)
            usort($items, function ($a, $b) {
                return strtotime((string)($b['deleted_at'] ?? 0)) <=> strtotime((string)($a['deleted_at'] ?? 0));
            });

        } catch (Throwable $e) {
            error_log("Feria TrashManager::getList error: " . $e->getMessage());
        }

        return $items;
    }
}
