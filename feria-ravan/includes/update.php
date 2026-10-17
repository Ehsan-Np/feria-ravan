<?php
/**
 * ============================================================================
 *  خط لولهٔ به‌روزرسانی خودکار — نسخهٔ ۱٫۹
 *  ─ با هر نصب نسخهٔ جدید (ارتقای بسته روی سرور)، در اولین درخواستِ وب
 *    تغییرات اسکیمای امن + بذر محتوای جدید (مثل تست‌های روانشناسی تازه) خودکار و
 *    idempotent اعمال می‌شود — بدون نیاز به اجرای دستی reseed.
 *  ─ مقایسهٔ «نسخهٔ ثبت‌شدهٔ دیتابیس» (setting: fr_version) با FR_VERSION
 *    فایل‌ها؛ اگر متفاوت بود یک‌بار اجرا و سپس مارکر به‌روزرسانی می‌شود.
 *  ─ قفل اتمیک روی settings از اجرای هم‌زمانِ چند پروسه جلوگیری می‌کند و
 *    قفلِ کهنه (>۱۲۰ ثانیه) خودبه‌خود آزاد می‌شود تا سایت هرگز گیر نکند.
 *  ─ تمام مراحل try/catch هستند و هیچ‌وقت خرابی نمایش عمومی نمی‌سازند.
 * ============================================================================
 */
declare(strict_types=1);

/**
 * اجرای فوق‌العاده ایمن دستورات DDL (سازگار با همهٔ نگارش‌های MySQL 5.7 / 8.0 / 8.4 و MariaDB)
 * دستورات ADD COLUMN IF NOT EXISTS و ADD KEY IF NOT EXISTS که در MySQL 8.0 خطای سینتکس می‌دهند را
 * با بررسی پیشین SHOW COLUMNS / SHOW INDEX به‌صورت ۱۰۰٪ ایمن و idempotent اجرا می‌کند.
 */
function fr_db_run_safe_ddl(string $sql): void
{
    $sql = trim($sql);
    if ($sql === '') return;

    // ۱. بررسی ADD COLUMN IF NOT EXISTS
    if (preg_match('/^ALTER\s+TABLE\s+[`]?([\w${}]+)[`]?\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+[`]?(\w+)[`]?\s+(.*)$/is', $sql, $m)) {
        $table = trim($m[1], '`');
        $col = trim($m[2], '`');
        $defn = trim($m[3]);
        try {
            $cols = array_column(DB::all("SHOW COLUMNS FROM `{$table}`"), 'Field');
            if (!in_array($col, $cols, true)) {
                DB::run("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$defn}");
            }
        } catch (Throwable $e) {
            error_log("fr_db_run_safe_ddl col ({$table}.{$col}): " . $e->getMessage());
        }
        return;
    }

    // ۲. بررسی ADD KEY IF NOT EXISTS
    if (preg_match('/^ALTER\s+TABLE\s+[`]?([\w${}]+)[`]?\s+ADD\s+KEY\s+IF\s+NOT\s+EXISTS\s+[`]?(\w+)[`]?\s*\((.*)\)$/is', $sql, $m)) {
        $table = trim($m[1], '`');
        $indexName = trim($m[2], '`');
        $indexCols = trim($m[3]);
        try {
            $indexes = array_column(DB::all("SHOW INDEX FROM `{$table}`"), 'Key_name');
            if (!in_array($indexName, $indexes, true)) {
                DB::run("ALTER TABLE `{$table}` ADD KEY `{$indexName}` ({$indexCols})");
            }
        } catch (Throwable $e) {
            error_log("fr_db_run_safe_ddl key ({$table}.{$indexName}): " . $e->getMessage());
        }
        return;
    }

    // ۳. سایر دستورات SQL به صورت ایمن درون try/catch
    try {
        DB::run($sql);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (!str_contains($msg, 'Duplicate column') && !str_contains($msg, 'Duplicate key') && !str_contains($msg, 'already exists')) {
            error_log("fr_db_run_safe_ddl error: " . $msg);
        }
    }
}

/**
 * پاک‌سازی خودکار و ایمن فایل‌های پوشه install پس از نصب یا آپدیت دستی
 * به درخواست صریح کاربر: با اعمال به‌روزرسانی یا نصب، فایل‌های نصب‌کننده خودکار حذف می‌شوند
 */
function fr_cleanup_install_folder(): array
{
    $installDir = defined('FR_ROOT') ? (FR_ROOT . '/install') : (dirname(__DIR__) . '/install');
    if (!is_dir($installDir)) {
        return ['ok' => true, 'cleaned' => 0, 'message' => 'پوشه install قبلاً پاک‌سازی شده است.'];
    }

    if (defined('DB_NAME') && DB_NAME === '{{' . 'DB_NAME' . '}}') {
        return ['ok' => false, 'cleaned' => 0, 'message' => 'سایت هنوز نصب نشده است؛ حذف پوشه install مجاز نیست.'];
    }

    $deleted = 0;
    $files = @scandir($installDir);
    if (is_array($files)) {
        foreach ($files as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $installDir . '/' . $item;
            if (is_file($full)) {
                if (@unlink($full)) $deleted++;
            }
        }
        @rmdir($installDir);
    }

    return [
        'ok'      => true,
        'cleaned' => $deleted,
        'message' => "تعداد {$deleted} فایل از پوشه install با موفقیت پاک‌سازی شد.",
    ];
}

/** اجرای خودکار به‌روزرسانی‌ها در اولین ریکوئست پس از آپلود نسخهٔ جدید */
function fr_update_pipeline(bool $force = false): void
{
    // قبل از نصب کامل، settings وجود ندارد — بی‌صدا رد می‌شویم
    static $tried = false;
    /* منبع حقیقت نگارش، کد است (FR_RELEASE از includes/version.php) */
    if (!defined('FR_RELEASE')) {
        $verFile = dirname(__DIR__) . '/includes/version.php';
        if (file_exists($verFile)) require_once $verFile;
    }
    $codeVer = defined('FR_RELEASE') ? FR_RELEASE : (defined('FR_VERSION') ? FR_VERSION : '3.4.5');
    if (!$force && $tried) return;
    $tried = true;

    $cur = '';
    try { $cur = (string)DB::setting('fr_version', '0'); } catch (Throwable $e) { return; }
    if (!$force && $cur === $codeVer) return;

    /* ── قفل اتمیک: اولین پروسه‌ای که موفق به نوشتن مهر زمانی شود، اجرا می‌کند ── */
    $T = DB::t('settings');
    $stamp  = date('c');
    $stale  = date('c', time() - 120);
    try {
        DB::run("INSERT IGNORE INTO $T (`key`,`value`) VALUES ('fr_version_lock','free')");
        $got = DB::run("UPDATE $T SET `value`=? WHERE `key`='fr_version_lock'
                        AND (`value`='free' OR `value` < ?)", [$stamp, $stale])->rowCount() === 1;
    } catch (Throwable $e) { return; }          // جدول هنوز نیست
    if (!$got) return;                          // پروسهٔ دیگر در حال اجراست

    try {
        /* ─── پایدارسازی و هماهنگ‌سازی جداول کلیدی admins و otps در تمام نسخه‌های MySQL ─── */
        try {
            $p = DB_PREFIX;
            fr_db_run_safe_ddl("ALTER TABLE `{$p}admins` ADD COLUMN IF NOT EXISTS `user_id` BIGINT UNSIGNED NULL AFTER `id`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}admins` ADD COLUMN IF NOT EXISTS `password_hash` VARCHAR(255) NULL AFTER `username`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}admins` ADD COLUMN IF NOT EXISTS `role_id` INT UNSIGNED NULL AFTER `role`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}admins` ADD COLUMN IF NOT EXISTS `status` ENUM('active','blocked') NOT NULL DEFAULT 'active' AFTER `role_id`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}admins` ADD COLUMN IF NOT EXISTS `totp_secret` VARCHAR(64) NULL AFTER `status`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}admins` ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL AFTER `last_login`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}admins` ADD KEY IF NOT EXISTS `ix_admin_user` (`user_id`)");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}admins` ADD KEY IF NOT EXISTS `ix_status` (`status`)");

            // ستون‌های احراز هویت و دسترسی پنل کاربری در جدول users
            fr_db_run_safe_ddl("ALTER TABLE `{$p}users` ADD COLUMN IF NOT EXISTS `password_hash` VARCHAR(255) NULL AFTER `mobile`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}users` ADD COLUMN IF NOT EXISTS `mobile_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}users` ADD COLUMN IF NOT EXISTS `last_login_at` DATETIME NULL AFTER `extra`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}users` ADD COLUMN IF NOT EXISTS `last_login_ip` VARBINARY(16) NULL AFTER `last_login_at`");

            // هماهنگ‌سازی فیلدهای رمز عبور و آخرین ورود
            try { DB::run("UPDATE `{$p}admins` SET `password_hash`=`password` WHERE (`password_hash` IS NULL OR `password_hash`='') AND `password` IS NOT NULL AND `password`<>''"); } catch (Throwable $e) {}
            try { DB::run("UPDATE `{$p}admins` SET `password`=`password_hash` WHERE (`password` IS NULL OR `password`='') AND `password_hash` IS NOT NULL AND `password_hash`<>''"); } catch (Throwable $e) {}
            try { DB::run("UPDATE `{$p}admins` SET `last_login_at`=`last_login` WHERE `last_login_at` IS NULL AND `last_login` IS NOT NULL"); } catch (Throwable $e) {}
            try { DB::run("UPDATE `{$p}admins` SET `last_login`=`last_login_at` WHERE `last_login` IS NULL AND `last_login_at` IS NOT NULL"); } catch (Throwable $e) {}

            // ایجاد جدول sessions در صورت عدم وجود (تضمین پایداری نشست‌های کاربر و مدیر)
            DB::run("CREATE TABLE IF NOT EXISTS `{$p}sessions` (
              `id` VARCHAR(128) NOT NULL PRIMARY KEY,
              `user_id` BIGINT UNSIGNED NULL,
              `admin_id` INT UNSIGNED NULL,
              `ip` VARBINARY(16) NULL,
              `user_agent` VARCHAR(255) NULL,
              `device` VARCHAR(60) NULL,
              `login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `logout_at` DATETIME NULL,
              KEY `ix_user` (`user_id`, `login_at`),
              KEY `ix_admin` (`admin_id`, `login_at`),
              KEY `ix_last_seen` (`last_seen`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // ایجاد جدول rate_limits در صورت عدم وجود
            DB::run("CREATE TABLE IF NOT EXISTS `{$p}rate_limits` (
              `bucket` VARCHAR(80) NOT NULL PRIMARY KEY,
              `hits` INT UNSIGNED NOT NULL DEFAULT 1,
              `reset_at` DATETIME NOT NULL,
              KEY `ix_reset` (`reset_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // ایجاد جدول otps در صورت عدم وجود (تضمین کارکرد سیستم پیامک و OTP ورود)
            DB::run("CREATE TABLE IF NOT EXISTS `{$p}otps` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `mobile` VARCHAR(11) NOT NULL,
              `code` VARCHAR(10) NOT NULL,
              `purpose` ENUM('register','login','reset','mobile_change') NOT NULL DEFAULT 'login',
              `expires_at` DATETIME NOT NULL,
              `used_at` DATETIME NULL,
              `tries` TINYINT UNSIGNED NOT NULL DEFAULT 0,
              `ip` VARBINARY(16) NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY `ix_mobile_purpose` (`mobile`, `purpose`),
              KEY `ix_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            try {
                $hasOtpCodes = (bool)DB::val("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$p}otp_codes'");
                if ($hasOtpCodes) {
                    DB::run("INSERT IGNORE INTO `{$p}otps` (`mobile`,`code`,`purpose`,`expires_at`,`created_at`)
                             SELECT `mobile`,`code`,`purpose`,`expires_at`,`created_at` FROM `{$p}otp_codes`");
                }
            } catch (Throwable $e) {}

            // پایداری و خودترمیمی جدول تراکنش‌های کیف‌پول (wallet_transactions)
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD COLUMN IF NOT EXISTS `kind` ENUM('credit','debit','refund','gift','adjustment') NOT NULL DEFAULT 'credit' AFTER `amount`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD COLUMN IF NOT EXISTS `reference_type` VARCHAR(40) DEFAULT NULL AFTER `kind`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD COLUMN IF NOT EXISTS `reference_id` BIGINT UNSIGNED DEFAULT NULL AFTER `reference_type`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) DEFAULT NULL AFTER `reference_id`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD COLUMN IF NOT EXISTS `expires_at` DATETIME DEFAULT NULL AFTER `description`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD COLUMN IF NOT EXISTS `balance_after` INT NULL DEFAULT 0");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD COLUMN IF NOT EXISTS `type` VARCHAR(30) NULL DEFAULT NULL");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD COLUMN IF NOT EXISTS `note` VARCHAR(255) DEFAULT NULL");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}wallet_transactions` ADD KEY IF NOT EXISTS `ix_created` (`created_at`)");
        } catch (Throwable $e) { error_log('feria: admins & otps hardening: ' . $e->getMessage()); }

        /* ─── ۱٫۹: زباله‌دانِ تست‌های روانشناسی (status='trash') ─── */
        try {
            DB::run("ALTER TABLE " . DB::t('tests') .
                    " MODIFY `status` ENUM('active','inactive','draft','trash') NOT NULL DEFAULT 'active'");
        } catch (Throwable $e) { /* نسخهٔ قدیمی‌تر/جدول هنوز نیست */ }

        /* ─── ۱٫۹: اصلاح تاریخیٔ درآمدها — سفارش‌های برگشت‌خوردهٔ قدیمی ───
           تا پیش از ۱٫۹ سفارش‌های مربوط به عودت وجه «paid» باقی می‌ماندند و
           گزارش‌های درآمد بالاتر از واقع ثبت می‌شد */
        try {
            DB::run("UPDATE " . DB::t('orders') . " o
                     JOIN " . DB::t('event_registrations') . " er ON er.order_id = o.id
                     JOIN " . DB::t('refund_requests') . " rr
                       ON rr.context='event' AND rr.reference_id = er.id AND rr.status='paid'
                     SET o.status='refunded' WHERE o.status='paid'");
            DB::run("UPDATE " . DB::t('orders') . " o
                     JOIN " . DB::t('consult_appointments') . " a ON a.order_id = o.id
                     JOIN " . DB::t('refund_requests') . " rr
                       ON rr.context='consult' AND rr.reference_id = a.id AND rr.status='paid'
                     SET o.status='refunded' WHERE o.status='paid'");
        } catch (Throwable $e) { /* جداول ارتقا هنوز موجود نیستند */ }

        /* ─── ۲٫۰: سامانهٔ دیدگاه و امتیاز (ستاره‌ای) — جداول + لیبل‌ها + تنظیمات ─── */
        try {
            $p = DB_PREFIX;
            DB::run("CREATE TABLE IF NOT EXISTS `{$p}review_labels` (
              `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `title`      VARCHAR(120) NOT NULL,
              `color`      VARCHAR(7)  NOT NULL DEFAULT '#0c7a4d',
              `bg`         VARCHAR(7)  NOT NULL DEFAULT '#e9f9f1',
              `sort_order` INT         NOT NULL DEFAULT 0,
              `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            DB::run("CREATE TABLE IF NOT EXISTS `{$p}reviews` (
              `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `context`       ENUM('test','workshop','course') NOT NULL,
              `ref_id`        INT UNSIGNED NOT NULL,
              `user_id`       BIGINT UNSIGNED DEFAULT NULL,
              `author_name`   VARCHAR(150) NOT NULL,
              `author_mobile` VARCHAR(11)  DEFAULT NULL,
              `rating`        TINYINT UNSIGNED NOT NULL DEFAULT 5,
              `body`          TEXT NOT NULL,
              `label_id`      INT UNSIGNED DEFAULT NULL,
              `label_text`    VARCHAR(120) DEFAULT NULL,
              `badge_auto`    ENUM('participant','admin') DEFAULT NULL,
              `source`        ENUM('user','admin','excel') NOT NULL DEFAULT 'user',
              `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
              `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `ix_ref_status` (`context`,`ref_id`,`status`,`created_at`),
              KEY `ix_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) { error_log('feria: reviews tables: ' . $e->getMessage()); }
        try {
            require_once __DIR__ . '/reviews.php';
            fr_review_seed_labels();                       // لیبل‌های پیش‌فرض قابل‌شخصی‌سازی
        } catch (Throwable $e) { error_log('feria: review seed: ' . $e->getMessage()); }
        foreach (['reviews_auto_approve' => '1', 'report_qr_show' => '1', 'report_qr_link' => ''] as $k => $v) {
            try {
                if (DB::setting($k, null) === null) DB::setSetting($k, $v);
            } catch (Throwable $e) { /* بی‌صدا */ }
        }

        /* ─── ۲٫۱: متن‌های قابل‌شخصی‌سازی + نقش‌ها + برنامه‌ریزی روزانه + خبرنامه ─── */
        try {
            $p = DB_PREFIX;
            $alt = [
                "ALTER TABLE `{$p}admins`            ADD COLUMN IF NOT EXISTS `role_id` INT UNSIGNED NULL AFTER `role`",
                "ALTER TABLE `{$p}event_companions`  ADD COLUMN IF NOT EXISTS `is_free` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_id`",
                "ALTER TABLE `{$p}users`             ADD COLUMN IF NOT EXISTS `telegram_chat_id` VARCHAR(60) NULL AFTER `notif_prefs`",
                "ALTER TABLE `{$p}users`             ADD COLUMN IF NOT EXISTS `bale_chat_id` VARCHAR(60) NULL AFTER `telegram_chat_id`",
                "CREATE TABLE IF NOT EXISTS `{$p}texts` (`k` VARCHAR(120) NOT NULL, `txt` TEXT NOT NULL,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`k`))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}admin_roles` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `title` VARCHAR(120) NOT NULL, `caps` TEXT NOT NULL, `is_system` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}planner_tasks` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL, `day` DATE NOT NULL, `title` VARCHAR(190) NOT NULL,
                    `time_note` VARCHAR(5) DEFAULT NULL, `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
                    `category` VARCHAR(30) NOT NULL DEFAULT 'personal', `done` TINYINT(1) NOT NULL DEFAULT 0,
                    `done_at` DATETIME DEFAULT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`), KEY `ix_user_day` (`user_id`,`day`),
                    CONSTRAINT `fk_ptask_user` FOREIGN KEY (`user_id`) REFERENCES `{$p}users`(`id`) ON DELETE CASCADE)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}planner_rewards` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL, `streak_from` INT NOT NULL, `streak_to` INT NOT NULL,
                    `type` VARCHAR(20) NOT NULL DEFAULT 'discount', `percent` INT UNSIGNED DEFAULT NULL,
                    `code` VARCHAR(40) DEFAULT NULL, `note` VARCHAR(255) DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`), KEY `ix_user` (`user_id`),
                    CONSTRAINT `fk_prew_user` FOREIGN KEY (`user_id`) REFERENCES `{$p}users`(`id`) ON DELETE CASCADE)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}newsletter` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `mobile` VARCHAR(11) NOT NULL, `name` VARCHAR(150) DEFAULT NULL,
                    `status` ENUM('active','unsub') NOT NULL DEFAULT 'active',
                    `source` VARCHAR(30) NOT NULL DEFAULT 'footer',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `unsub_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`), UNIQUE KEY `uq_mobile` (`mobile`))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
            foreach ($alt as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 2.1 ddl: ' . $e->getMessage()); }
            }
        } catch (Throwable $e) { error_log('feria: 2.1 tables: ' . $e->getMessage()); }
        /* نگه‌داری سازگاری: اگر streak_* به‌اشتباه DATE ساخته شده بود، به INT اصلاح شود */
        try {
            $col = DB::one("SHOW COLUMNS FROM " . DB::t('planner_rewards') . " LIKE 'streak_from'");
            if ($col && stripos((string)$col['Type'], 'date') !== false) {
                DB::run("ALTER TABLE " . DB::t('planner_rewards') . " MODIFY `streak_from` INT NOT NULL, MODIFY `streak_to` INT NOT NULL");
            }
        } catch (Throwable $e) { /* بی‌صدا — جدول هنوز نیست یا درست است */ }
        try {
            require_once __DIR__ . '/roles.php';
            fr_roles_seed();                                 // نقش‌های سیستمی پیش‌فرض
            require_once __DIR__ . '/texts.php';
            fr_texts_seed();                                 // فرهنگ متن‌های پایه (ری‌برندینگ)
        } catch (Throwable $e) { error_log('feria: 2.1 seeds: ' . $e->getMessage()); }
        foreach ([
            'planner_enabled' => '1', 'planner_streak_target' => '7', 'planner_reward_type' => 'discount',
            'planner_reward_percent' => '20', 'planner_reward_message' => '',
            'planner_remind_time' => '08:30', 'planner_daily_target' => '1',
            'telegram_bot_token' => '', 'telegram_bot_username' => '',
            'bale_bot_token' => '', 'bale_bot_username' => '',
            'logo_desc' => '', 'footer_links_json' => '', 'footer_links2_json' => '',
            'footer_badge1_img' => '', 'footer_badge1_url' => '', 'footer_badge1_alt' => 'نماد اعتماد الکترونیک', 'footer_badge1_html' => '',
            'footer_badge2_img' => '', 'footer_badge2_url' => '', 'footer_badge2_alt' => 'درگاه پرداخت زرین‌پال', 'footer_badge2_html' => '',
            'footer_badge3_img' => '', 'footer_badge3_url' => '', 'footer_badge3_alt' => 'انجمن روانشناسی', 'footer_badge3_html' => '',
            'newsletter_title' => 'خبرنامهٔ پیامکی', 'newsletter_desc' => 'از دوره‌ها، کارگاه‌ها و تست‌های روانشناسی جدید زودتر از همه باخبر شوید.',
        ] as $k => $v) {
            try { if (DB::setting($k, null) === null) DB::setSetting($k, $v); } catch (Throwable $e) { /* بی‌صدا */ }
        }
        try { if (DB::setting('cron_secret', null) === null) DB::setSetting('cron_secret', bin2hex(random_bytes(16))); } catch (Throwable $e) { /* بی‌صدا */ }

        /* ─── ۲٫۲: ورود با نام کاربری + پیوند مدیر↔کاربر + دیدگاه باز برای همه + ۴ نماد + نرخ بازار ─── */
        try {
            $p = DB_PREFIX;
            $alt22 = [
                "ALTER TABLE `{$p}users`   ADD COLUMN IF NOT EXISTS `username` VARCHAR(60) NULL AFTER `mobile`",
                "ALTER TABLE `{$p}admins`  ADD COLUMN IF NOT EXISTS `user_id` BIGINT UNSIGNED NULL AFTER `id`",
                "ALTER TABLE `{$p}reviews` ADD COLUMN IF NOT EXISTS `kind` ENUM('review','question') NOT NULL DEFAULT 'review' AFTER `rating`",
                "ALTER TABLE `{$p}reviews` MODIFY `rating` TINYINT UNSIGNED NULL",
                "ALTER TABLE `{$p}reviews` MODIFY `badge_auto` ENUM('participant','admin','guest') NULL",
                "ALTER TABLE `{$p}reviews` ADD COLUMN IF NOT EXISTS `admin_reply` TEXT NULL AFTER `status`",
                "ALTER TABLE `{$p}reviews` ADD COLUMN IF NOT EXISTS `replied_at` DATETIME NULL AFTER `admin_reply`",
            ];
            foreach ($alt22 as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 2.2 ddl: ' . $e->getMessage()); }
            }
            /* ایندکس یکتای نام کاربری + ایندکس پیوند مدیر↔کاربر (بدون خطا اگر قبلاً ساخته شده) */
            try { DB::run("ALTER TABLE `{$p}users` ADD UNIQUE KEY `uq_username` (`username`)"); }
            catch (Throwable $e) { error_log('feria: 2.2 uq_username: ' . $e->getMessage()); }
            try { DB::run("ALTER TABLE `{$p}admins` ADD KEY `ix_admin_user` (`user_id`)"); }
            catch (Throwable $e) { error_log('feria: 2.2 ix_admin_user: ' . $e->getMessage()); }
        } catch (Throwable $e) { error_log('feria: 2.2 tables: ' . $e->getMessage()); }

        /* نماد چهارم + تراز مجدد عنوان‌های پیش‌فرض نمادها (۱:اینماد، ۲:ساماندهی، ۳:زرین‌پال، ۴:انجمن) */
        try {
            if (DB::setting('footer_badge3_alt', '') === 'انجمن روانشناسی'
                && DB::setting('footer_badge2_alt', '') === 'درگاه پرداخت زرین‌پال'
                && trim((string)DB::setting('footer_badge4_alt', '')) === '') {
                /* مقادیر هنوز پیش‌فرض ۲٫۱‌اند و مدیر شخصی‌سازی نکرده → هم‌تراز با چیدمان چهارخانهٔ جدید */
                DB::setSetting('footer_badge2_alt', 'نشان ملی ثبت (ساماندهی)');
                DB::setSetting('footer_badge3_alt', 'درگاه پرداخت زرین‌پال');
                DB::setSetting('footer_badge4_alt', 'انجمن روانشناسی');
            }
            foreach (['footer_badge4_img' => '', 'footer_badge4_url' => '', 'footer_badge4_html' => '', 'footer_badge4_alt' => 'انجمن روانشناسی'] as $k => $v) {
                if (DB::setting($k, null) === null) DB::setSetting($k, $v);
            }
        } catch (Throwable $e) { error_log('feria: 2.2 badges: ' . $e->getMessage()); }

        /* ─── ۲٫۲: بازقیمت‌گذاری بازار روانشناسی ایران (منسوخ و غیرفعال در نسخهٔ ۴٫۳٫۰+) ───
           جهت تضمین پایداری و عدم بازنویسی قیمت‌های سفارشی‌سازی‌شده توسط مدیر سامانه،
           کوئری‌های بازقیمت‌گذاری انبوه در خط‌لوله به‌روزرسانی به‌طور کامل غیرفعال شده‌اند
           تا هرگونه قیمت تعیین‌شده برای آزمون‌ها، کارگاه‌ها، دوره‌ها و خدمات مشاوره حفظ شود. */

        /* ─── ۲٫۲: عادی‌سازی متن‌های قدیمی پایگاه‌داده — هر «آزمون» → «تست روانشناسی» و 🌱 → 🦢 ───
           (idempotent: پس از یک اجرا هیچ «آزمون»ی باقی نمی‌ماند؛ جایگذاری ترتیبی تا دو بار ترکیب نشود) */
        try {
            $word = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, 'آزمون‌های روانشناسی','تست‌های روانشناسی'),'آزمون‌های','تست‌های روانشناسی'),'آزمونها','تست‌های روانشناسی'),'آزمون روانشناسی','تست روانشناسی'),'آزمون','تست روانشناسی'),'🌱','🦢')";
            DB::run("UPDATE `{$p}settings` SET `value`=" . sprintf($word, '`value`') . " WHERE `value` LIKE '%آزمون%' OR `value` LIKE '%🌱%'");
            foreach (['title','short_desc','description','instructions','seo_title','seo_desc','seo_kw'] as $col) {
                try { DB::run("UPDATE `{$p}tests` SET `$col`=" . sprintf($word, "`$col`") . " WHERE `$col` LIKE '%آزمون%' OR `$col` LIKE '%🌱%'"); } catch (Throwable $e) { /* ستون ممکن است نباشد */ }
            }
            foreach (['title','short_desc','description','schedule_text','seo_title','seo_desc','seo_kw'] as $col) {
                try { DB::run("UPDATE `{$p}events` SET `$col`=" . sprintf($word, "`$col`") . " WHERE `$col` LIKE '%آزمون%' OR `$col` LIKE '%🌱%'"); } catch (Throwable $e) { }
            }
            foreach (['title','description'] as $col) {
                try { DB::run("UPDATE `{$p}consult_services` SET `$col`=" . sprintf($word, "`$col`") . " WHERE `$col` LIKE '%آزمون%' OR `$col` LIKE '%🌱%'"); } catch (Throwable $e) { }
            }
        } catch (Throwable $e) { error_log('feria: 2.2 text-normalize: ' . $e->getMessage()); }

        /* ─── ۲٫۳: اطلاع‌رسانی اجتماعی (تلگرام/بله با نام کاربری) + کارهای تکرارشونده + پالت رسمی کسب‌وکار ─── */
        try {
            foreach ([
                "ALTER TABLE `{$p}planner_tasks` ADD COLUMN `recur_id` BIGINT UNSIGNED DEFAULT NULL AFTER `category`",
                "ALTER TABLE `{$p}users` ADD COLUMN `tg_username` VARCHAR(60) DEFAULT NULL AFTER `bale_chat_id`",
                "ALTER TABLE `{$p}users` ADD COLUMN `bale_username` VARCHAR(60) DEFAULT NULL AFTER `tg_username`",
                "CREATE TABLE IF NOT EXISTS `{$p}social_links` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `channel` ENUM('telegram','bale') NOT NULL,
                    `username` VARCHAR(60) DEFAULT NULL,
                    `connect_code` VARCHAR(12) NOT NULL DEFAULT '',
                    `chat_id` VARCHAR(60) DEFAULT NULL,
                    `status` ENUM('pending','connected') NOT NULL DEFAULT 'pending',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `connected_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_user_channel` (`user_id`,`channel`),
                    KEY `ix_code` (`channel`,`connect_code`),
                    KEY `ix_username` (`channel`,`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}notify_log` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED DEFAULT NULL,
                    `channel` ENUM('telegram','bale') NOT NULL,
                    `msg_kind` ENUM('slot','test','connected','bulk','planner') NOT NULL DEFAULT 'slot',
                    `body` VARCHAR(900) DEFAULT NULL,
                    `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
                    `error` VARCHAR(300) DEFAULT NULL,
                    `slot_date` DATE DEFAULT NULL,
                    `slot_idx` TINYINT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `ix_user` (`user_id`),
                    KEY `ix_slot` (`slot_date`,`slot_idx`),
                    KEY `ix_status` (`status`,`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}planner_recur` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `title` VARCHAR(190) NOT NULL,
                    `time_note` VARCHAR(5) DEFAULT NULL,
                    `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
                    `category` VARCHAR(30) NOT NULL DEFAULT 'personal',
                    `freq` ENUM('daily','weekday','weekly') NOT NULL DEFAULT 'daily',
                    `active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `ix_user` (`user_id`,`active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ] as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 2.3 ddl: ' . $e->getMessage()); }
            }
            /* تنظیمات اطلاع‌رسانی اجتماعی */
            foreach ([
                'notif_daily_count' => '3',
                'notif_times'       => '["08:30","13:00","20:30"]',
                'notif_test_msg'    => "✅ اتصال شما به ربات با موفقیت انجام شد!\nاز این لحظه به بعد، اطلاع‌رسانی‌های «{site}» به‌صورت کامل و خودکار برای شما ارسال می‌شود. 🦢",
                'tg_update_offset'  => '0',
                'bale_update_offset'=> '0',
            ] as $k => $v) {
                if (DB::setting($k, null) === null) DB::setSetting($k, $v);
            }
            /* کلید مخفی کرون — اگر نبود بساز */
            if ((string)DB::setting('cron_secret', '') === '') DB::setSetting('cron_secret', bin2hex(random_bytes(16)));
            /* بازرنگی کاورهای SVG جایگذاری‌شدهٔ قدیمی (بنفش→سرمه‌ای رسمی — ۲٫۳) فقط برای تصاویر inline ذخیره‌شده */
            $recolor = [
                '#43309f' => '#1e3a6e', '#7a5af0' => '#3e5f9e', '#6d28d9' => '#122850', '#a78bfa' => '#2b4c85',
                '#4c1d95' => '#1b3568', '#8b5cf6' => '#3d5f9e', '#5b21b6' => '#16305e', '#7c3aed' => '#3563a0',
                '#3730a3' => '#234078', '#6366f1' => '#4a6fa5', '#7e22ce' => '#122850', '#c084fc' => '#2f568c',
                '#581c87' => '#1a2c57', '#a855f7' => '#33539a', '#312e81' => '#0f2450', '#818cf8' => '#2c4a82',
                '#6b21a8' => '#1e3a6e', '#d8b4fe' => '#5b7bb3',
                '#c2417c' => '#0f766e', '#e0659f' => '#14b8a6', '#be185d' => '#8a5a2e', '#f472b6' => '#c99f4a',
                '#9d174d' => '#35507e', '#fb7185' => '#6f8fc0', '#5b3fd6' => '#1e3a6e', '#8f6cf5' => '#3e5f9e',
                '#6f50e6' => '#2b4c85', '#4a32b8' => '#122850',
            ];
            $expr = '`image`';
            foreach ($recolor as $from => $to) { $expr = "REPLACE($expr,'$from','$to')"; }
            foreach (['events', 'consult_services'] as $tb) {
                try { DB::run("UPDATE `{$p}$tb` SET `image`=$expr WHERE `image` LIKE '<svg%'"); } catch (Throwable $e) { }
            }
            /* پالت رسمی جدید (نهال‌آبی اجرایی) — فقط اگر هنوز بنفش پیش‌فرض کارخانه باشد، دست‌نخورده ارتقا می‌یابد */
            if (strcasecmp((string)DB::setting('color_primary', ''), '#1e3a6e') === 0
                && strcasecmp((string)DB::setting('color_secondary', ''), '#3e5f9e') === 0) {
                DB::setSetting('color_primary', '#1e3a6e');
                DB::setSetting('color_secondary', '#3e5f9e');
            }
        } catch (Throwable $e) { error_log('feria: 2.3 social-notify: ' . $e->getMessage()); }

        /* ─── ۲٫۴: یادآوری چندنوبتی پلنر (۲۴ ساعته) — مهاجرت از تک‌نوبتی ─── */
        try {
            if (DB::setting('planner_remind_count', null) === null) DB::setSetting('planner_remind_count', '1');
            if (DB::setting('planner_remind_times', null) === null) {
                $t = (string)DB::setting('planner_remind_time', '08:30');
                if (!preg_match('/^\d{1,2}:\d{2}$/', $t)) $t = '08:30';
                DB::setSetting('planner_remind_times', json_encode([$t]));
            }
        } catch (Throwable $e) { error_log('feria: 2.4 multi-remind: ' . $e->getMessage()); }

        /* ─── ۲٫۵: پنل تبلیغاتی (مخاطبین + کمپین‌ها + صف ارسال) + یادآوری اجتماعی رویدادها ─── */
        try {
            foreach ([
                "CREATE TABLE IF NOT EXISTS `{$p}contacts` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(120) NOT NULL DEFAULT '',
                    `mobile` VARCHAR(20) NOT NULL,
                    `segment` VARCHAR(60) NOT NULL DEFAULT '',
                    `source` VARCHAR(30) NOT NULL DEFAULT 'manual',
                    `note` VARCHAR(190) NOT NULL DEFAULT '',
                    `tg_ok` TINYINT(1) NOT NULL DEFAULT 0,
                    `bale_ok` TINYINT(1) NOT NULL DEFAULT 0,
                    `status` ENUM('active','blocked') NOT NULL DEFAULT 'active',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`), UNIQUE KEY `uq_mobile` (`mobile`),
                    KEY `ix_segment` (`segment`,`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}campaigns` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `title` VARCHAR(190) NOT NULL,
                    `body` TEXT NOT NULL,
                    `channel` ENUM('telegram','bale','both') NOT NULL DEFAULT 'both',
                    `audience` ENUM('users','contacts','both') NOT NULL DEFAULT 'contacts',
                    `segment` VARCHAR(60) NOT NULL DEFAULT '',
                    `sms_invite` TINYINT(1) NOT NULL DEFAULT 1,
                    `status` ENUM('draft','queued','sending','done','paused') NOT NULL DEFAULT 'draft',
                    `total` INT UNSIGNED NOT NULL DEFAULT 0,
                    `sent` INT UNSIGNED NOT NULL DEFAULT 0,
                    `failed` INT UNSIGNED NOT NULL DEFAULT 0,
                    `skipped` INT UNSIGNED NOT NULL DEFAULT 0,
                    `created_by` INT UNSIGNED DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `queued_at` DATETIME DEFAULT NULL,
                    `finished_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`), KEY `ix_status` (`status`,`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}campaign_messages` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `campaign_id` BIGINT UNSIGNED NOT NULL,
                    `channel` ENUM('telegram','bale','sms') NOT NULL,
                    `user_id` BIGINT UNSIGNED DEFAULT NULL,
                    `contact_id` BIGINT UNSIGNED DEFAULT NULL,
                    `name` VARCHAR(120) NOT NULL DEFAULT '',
                    `mobile` VARCHAR(20) NOT NULL DEFAULT '',
                    `chat_id` VARCHAR(60) NOT NULL DEFAULT '',
                    `body` MEDIUMTEXT,
                    `status` ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
                    `error` VARCHAR(300) DEFAULT NULL,
                    `sent_at` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`), UNIQUE KEY `uq_camp` (`campaign_id`,`channel`,`mobile`),
                    KEY `ix_status` (`campaign_id`,`status`,`id`),
                    FOREIGN KEY (`campaign_id`) REFERENCES `{$p}campaigns`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "ALTER TABLE `{$p}notify_log` MODIFY COLUMN `msg_kind` ENUM('slot','test','connected','bulk','planner','campaign','event') NOT NULL DEFAULT 'slot'",
                "ALTER TABLE `{$p}attempts` ADD COLUMN IF NOT EXISTS `nudge_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `progress`",
                "ALTER TABLE `{$p}consult_appointments` ADD COLUMN IF NOT EXISTS `social_reminded_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `{$p}event_registrations` ADD COLUMN IF NOT EXISTS `social_reminded_at` DATETIME DEFAULT NULL",
            ] as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 2.5 ddl: ' . $e->getMessage()); }
            }
            foreach ([
                'social_event_remind'  => '1',
                'consult_social_hours' => '24',
                'event_social_hours'   => '24',
                'test_nudge_days'      => '2',
            ] as $k => $v) {
                if (DB::setting($k, null) === null) DB::setSetting($k, $v);
            }
        } catch (Throwable $e) { error_log('feria: 2.5 ads-panel: ' . $e->getMessage()); }

        /* ─── ۲٫۷: دفترچهٔ مخاطبین حرفه‌ای (گروه‌ها/تولد/کانال‌ها) + کمپین چندکاناله
                   با گزارش تحویل/کلیک + پلنر حرفه‌ای (دسته‌ها/یادآور چندکاناله) ─── */
        try {
            foreach ([
                /* گروه‌های مخاطبین — مدیریت کاملاً جداگانه */
                "CREATE TABLE IF NOT EXISTS `{$p}contact_groups` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(80) NOT NULL,
                    `color` VARCHAR(7) NOT NULL DEFAULT '#1e3a6e',
                    `note` VARCHAR(190) NOT NULL DEFAULT '',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`), UNIQUE KEY `uq_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}contact_group_members` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `contact_id` BIGINT UNSIGNED NOT NULL,
                    `group_id` INT UNSIGNED NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`), UNIQUE KEY `uq_pair` (`contact_id`,`group_id`),
                    KEY `ix_group` (`group_id`),
                    CONSTRAINT `fk_cgm_c` FOREIGN KEY (`contact_id`) REFERENCES `{$p}contacts`(`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_cgm_g` FOREIGN KEY (`group_id`) REFERENCES `{$p}contact_groups`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                /* فرادادهٔ گستردهٔ مخاطب */
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `birth_date` DATE DEFAULT NULL AFTER `segment`",
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `tg_chat_id` VARCHAR(60) DEFAULT NULL AFTER `bale_ok`",
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `bale_chat_id` VARCHAR(60) DEFAULT NULL AFTER `tg_chat_id`",
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `tg_username` VARCHAR(60) DEFAULT NULL AFTER `bale_chat_id`",
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `bale_username` VARCHAR(60) DEFAULT NULL AFTER `tg_username`",
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `invite_code` VARCHAR(12) DEFAULT NULL AFTER `note`",
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `user_id` BIGINT UNSIGNED DEFAULT NULL AFTER `invite_code`",
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `checked_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `{$p}contacts` ADD COLUMN IF NOT EXISTS `invite_sent_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `{$p}contacts` ADD KEY IF NOT EXISTS `ix_invite` (`invite_code`)",
                "ALTER TABLE `{$p}contacts` ADD KEY IF NOT EXISTS `ix_user` (`user_id`)",
                /* کمپین‌ها: کانال‌های چندتایی + بازشدن/زمان‌بندی/حجم دسته */
                "ALTER TABLE `{$p}campaigns` MODIFY `status` ENUM('draft','queued','sending','done','paused','scheduled','edited') NOT NULL DEFAULT 'draft'",
                "ALTER TABLE `{$p}campaigns` ADD COLUMN IF NOT EXISTS `channels` VARCHAR(40) NOT NULL DEFAULT '' AFTER `sms_invite`",
                "ALTER TABLE `{$p}campaigns` ADD COLUMN IF NOT EXISTS `batch_size` INT UNSIGNED NOT NULL DEFAULT 15 AFTER `channels`",
                "ALTER TABLE `{$p}campaigns` ADD COLUMN IF NOT EXISTS `delay_ms` INT UNSIGNED NOT NULL DEFAULT 150 AFTER `batch_size`",
                "ALTER TABLE `{$p}campaigns` ADD COLUMN IF NOT EXISTS `scheduled_at` DATETIME DEFAULT NULL AFTER `delay_ms`",
                "ALTER TABLE `{$p}campaigns` ADD COLUMN IF NOT EXISTS `track_links` TINYINT(1) NOT NULL DEFAULT 1 AFTER `scheduled_at`",
                "ALTER TABLE `{$p}campaign_messages` ADD COLUMN IF NOT EXISTS `delivered_at` DATETIME DEFAULT NULL AFTER `sent_at`",
                "ALTER TABLE `{$p}campaign_messages` ADD COLUMN IF NOT EXISTS `clicked_at` DATETIME DEFAULT NULL AFTER `delivered_at`",
                "ALTER TABLE `{$p}campaign_messages` ADD COLUMN IF NOT EXISTS `replied_at` DATETIME DEFAULT NULL AFTER `clicked_at`",
                "ALTER TABLE `{$p}campaign_messages` ADD COLUMN IF NOT EXISTS `msg_ref` VARCHAR(60) DEFAULT NULL AFTER `replied_at`",
                "ALTER TABLE `{$p}campaign_messages` ADD KEY IF NOT EXISTS `ix_ref` (`channel`,`msg_ref`)",
                /* رهگیری پیوندها */
                "CREATE TABLE IF NOT EXISTS `{$p}campaign_links` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `campaign_id` BIGINT UNSIGNED NOT NULL,
                    `message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `idx` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `url` VARCHAR(600) NOT NULL,
                    `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
                    `first_click_at` DATETIME DEFAULT NULL,
                    `last_click_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`), KEY `ix_cmp` (`campaign_id`,`idx`),
                    UNIQUE KEY `uq_msg_idx` (`message_id`,`idx`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}link_clicks` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `link_id` BIGINT UNSIGNED NOT NULL,
                    `campaign_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `ip` VARBINARY(16) DEFAULT NULL,
                    `ua` VARCHAR(255) NOT NULL DEFAULT '',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`), KEY `ix_cmp` (`campaign_id`,`created_at`),
                    KEY `ix_link` (`link_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                /* پلنر حرفه‌ای */
                "ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `note` VARCHAR(500) NOT NULL DEFAULT '' AFTER `category`",
                "ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `remind_at` DATETIME DEFAULT NULL AFTER `done_at`",
                "ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `reminded_at` DATETIME DEFAULT NULL AFTER `remind_at`",
                "ALTER TABLE `{$p}planner_tasks` ADD KEY IF NOT EXISTS `ix_remind` (`reminded_at`,`remind_at`)",
                "CREATE TABLE IF NOT EXISTS `{$p}planner_cats` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `name` VARCHAR(40) NOT NULL,
                    `color` VARCHAR(7) NOT NULL DEFAULT '#1e3a6e',
                    `sort` INT NOT NULL DEFAULT 0,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`), UNIQUE KEY `uq_user_name` (`user_id`,`name`),
                    CONSTRAINT `fk_pcat_user` FOREIGN KEY (`user_id`) REFERENCES `{$p}users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ] as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 2.7 ddl: ' . $e->getMessage()); }
            }
            /* تنظیمات پیش‌فرض ۲٫۷ */
            foreach ([
                'planner_notify_channels' => 'telegram,bale',   // کانال‌های یادآور پلنر: telegram,bale,sms
                'campaign_track_links'    => '1',
                'bale_gateway_enabled'    => '0',               // درگاه سازمانی پیام‌رسان (سرویس حقوقی نوین بله/کاوه‌نگار…)
                'bale_gateway_url'        => '',
                'bale_gateway_token'      => '',
                'contact_auto_check'      => '1',               // به‌محض ثبت مخاطب، وضعیت کانال‌هایش سنجیده شود
            ] as $k => $v) {
                if (DB::setting($k, null) === null) DB::setSetting($k, $v);
            }
        } catch (Throwable $e) { error_log('feria: 2.7 ads-suite: ' . $e->getMessage()); }

        /* ─── ۲٫۸: قیمت‌گذاری دوسطحی (اصلی + تخفیف‌خورده) برای تست/کارگاه/دوره
                   + بازقیمت‌گذاری پیش‌فرض‌ها هم‌راستا با نرخ بازار ایران (مرداد ۱۴۰۵) ─── */
        try {
            foreach ([
                "ALTER TABLE `{$p}tests` ADD COLUMN IF NOT EXISTS `price_sale` INT UNSIGNED DEFAULT NULL AFTER `price_full`",
                "ALTER TABLE `{$p}events` ADD COLUMN IF NOT EXISTS `sale_price` INT UNSIGNED DEFAULT NULL AFTER `price`",
            ] as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 2.8 ddl: ' . $e->getMessage()); }
            }

            /* ─── بازقیمت‌گذاری منصفانه با مارکت روانشناسی (منسوخ و غیرفعال در نسخهٔ ۴٫۳٫۰+) ───
               جهت تضمین پایداری قیمت‌های سفارشی مدیر، بازقیمت‌گذاری خودکار در ارتقای سیستم حذف شد. */

            /* تنظیمات پیش‌فرض ۲٫۸ — صفحهٔ عمومی خبرنامه */
            foreach ([
                'newsletter_page_enabled' => '1',
                'newsletter_page_intro'   => '',
            ] as $k => $v) {
                if (DB::setting($k, null) === null) DB::setSetting($k, $v);
            }
        } catch (Throwable $e) { error_log('feria: 2.8 newsletter: ' . $e->getMessage()); }

        /* ─── بذر محتوای جدید نسخه‌ها — خودکار و idempotent ─── */
        try {
            require_once __DIR__ . '/../core/seed-mseq.php';
            if (function_exists('fr_seed_mseq_tests')) fr_seed_mseq_tests();  // گاردنر + هوش هیجانی (۱٫۸)
        } catch (Throwable $e) { error_log('feria: update seed failed: ' . $e->getMessage()); }

        /* ─── ۲٫۹: تحلیل‌های عمیق نسخهٔ ویژه (دکتری روان‌شناسی بالینی) ───
           برای هر آزمون فعال: مونوگراف بانددار هر مقیاس + تحلیل نمرهٔ کلی
           + فصل پایانی تخصصی. idempotent — بدنه‌ها در هر به‌روزرسانیِ کد،
           از پایگاه دانش core/analysis-deep بازتولید و به‌روز می‌شوند. */
        try {
            require_once __DIR__ . '/../core/seed-analyses29.php';
            if (function_exists('fr_seed_deep_analyses')) fr_seed_deep_analyses();
        } catch (Throwable $e) { error_log('feria: 2.9 deep analyses: ' . $e->getMessage()); }

        /* ─── ۳٫۰: پیش‌پرداخت (بیعانه) + یادآور خودکار تصفیهٔ حساب ───
           کاربر با پرداخت بیعانه، جای خود را در کارگاه/دوره/نوبت مشاوره قطعی
           می‌کند و مانده را تا حداکثر ۳ روز مانده به برگزاری (با لینک اختصاصی
           پیامکی یا از پنل کاربری) تسویه می‌کند. */
        try {
            $p = DB_PREFIX;
            foreach ([
                "ALTER TABLE `{$p}events` ADD COLUMN IF NOT EXISTS `deposit_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sale_price`",
                "ALTER TABLE `{$p}events` ADD COLUMN IF NOT EXISTS `deposit_amount` INT UNSIGNED DEFAULT NULL AFTER `deposit_enabled`",
                "ALTER TABLE `{$p}consult_services` ADD COLUMN IF NOT EXISTS `deposit_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `price`",
                "ALTER TABLE `{$p}consult_services` ADD COLUMN IF NOT EXISTS `deposit_amount` INT UNSIGNED DEFAULT NULL AFTER `deposit_enabled`",
                "ALTER TABLE `{$p}event_registrations` MODIFY `status` ENUM('pending','deposit','paid','canceled') NOT NULL DEFAULT 'pending'",
                "ALTER TABLE `{$p}event_registrations` ADD COLUMN IF NOT EXISTS `paid_total` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `amount_base`",
                "ALTER TABLE `{$p}event_registrations` ADD COLUMN IF NOT EXISTS `settle_reminded_at` DATETIME DEFAULT NULL AFTER `social_reminded_at`",
                "ALTER TABLE `{$p}consult_appointments` ADD COLUMN IF NOT EXISTS `paid_total` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `order_id`",
                "ALTER TABLE `{$p}consult_appointments` ADD COLUMN IF NOT EXISTS `settle_reminded_at` DATETIME DEFAULT NULL AFTER `social_reminded_at`",
                "ALTER TABLE `{$p}orders` ADD COLUMN IF NOT EXISTS `pay_stage` VARCHAR(12) NOT NULL DEFAULT 'full' AFTER `payable`",
                "ALTER TABLE `{$p}orders` ADD COLUMN IF NOT EXISTS `parent_order_id` BIGINT UNSIGNED DEFAULT NULL AFTER `pay_stage`",
                "ALTER TABLE `{$p}orders` ADD KEY IF NOT EXISTS `ix_parent` (`parent_order_id`)",
            ] as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 3.0 ddl: ' . $e->getMessage()); }
            }
            /* بازنویسی paid_total برای داده‌های پیش از ۳٫۰ (فقط یک‌بار — ردیف‌های
               هنوز صفر که سفارشِ paid دارند) تا مانده‌ها درست گزارش شوند */
            try {
                DB::run("UPDATE `{$p}event_registrations` r
                         JOIN `{$p}orders` o ON o.id=r.order_id
                         SET r.paid_total=o.payable WHERE r.status='paid' AND r.paid_total=0 AND o.status='paid'");
                DB::run("UPDATE `{$p}consult_appointments` a
                         JOIN `{$p}orders` o ON o.id=a.order_id
                         SET a.paid_total=o.payable WHERE a.status='booked' AND a.paid_total=0 AND o.status='paid'");
            } catch (Throwable $e) { error_log('feria: 3.0 backfill paid_total: ' . $e->getMessage()); }
            /* تنظیمات پیش‌فرض ۳٫۰ — فقط اگر کلید موجود نیست */
            foreach ([
                'settle_remind_on'      => '1',
                'deposit_remind_hours'  => '72',
                'sms_tpl_deposit_user'  => "{name} عزیز، پیش‌پرداخت شما برای «{item}» با موفقیت ثبت شد و {thing} شما قطعی است. 🦢\nمبلغ کل: {total} تومان — پرداخت‌شده: {paid} تومان — ماندهٔ قابل تصفیه: {remain} تومان\nلینک تصفیهٔ حساب: {link}\nهمچنین می‌توانید وارد پنل کاربری خود شوید و از بخش «{panel}» پرداختتان را تکمیل کنید.",
                'sms_tpl_settle_remind' => "{name} عزیز، تنها {days} روز به برگزاری «{item}» ({when}) مانده است. لطفاً حتماً نسبت به تصفیهٔ حساب اقدام کنید و مابقی مبلغ ({remain} تومان) را پرداخت نمایید.\nلینک پرداخت: {link}\nهمچنین می‌توانید وارد پنل کاربری خود شوید و از بخش «{panel}» پرداخت را تکمیل کنید.",
                'sms_tpl_settle_done_user' => "{name} عزیز، تصفیهٔ حساب «{item}» با موفقیت انجام شد و {thing} شما به‌صورت کامل نهایی است. 🎉\nکد رهگیری: {refid}",
            ] as $k => $v) {
                try { if (DB::setting($k, null) === null) DB::setSetting($k, $v); } catch (Throwable $e) { /* بی‌صدا */ }
            }
        } catch (Throwable $e) { error_log('feria: 3.0 deposit-suite: ' . $e->getMessage()); }

        /* ─── ۳٫۰٫۱: اصلاح کامل دسترسی به نسخهٔ ویژه و همگام‌سازی آزمون‌های پرداخت‌شده ───
           ۱. ارتقای خودکار تلاش‌های رایگانی که سفارش پرداخت‌شده دارند به mode='full'
           ۲. پاک‌سازی تلاش‌های خالی معلق ناشی از فرآیند قبلی خرید */
        try {
            $p = DB_PREFIX;
            // ارتقای تلاش‌های دارای سفارش پرداخت‌شده
            DB::run("UPDATE `{$p}attempts` a
                     JOIN `{$p}orders` o ON o.user_id = a.user_id AND o.test_id = a.test_id AND o.status = 'paid'
                     SET a.mode = 'full', a.order_id = COALESCE(a.order_id, o.id)
                     WHERE a.mode = 'free' AND a.status = 'completed'");

            // پاک‌سازی تلاش‌های خالی معلق
            DB::run("DELETE a FROM `{$p}attempts` a
                     JOIN `{$p}orders` o ON o.id = a.order_id AND o.status = 'paid'
                     WHERE a.status = 'in_progress' AND a.progress = 0
                       AND EXISTS (SELECT 1 FROM `{$p}attempts` a2
                                   WHERE a2.user_id = a.user_id AND a2.test_id = a.test_id AND a2.status = 'completed')");
        } catch (Throwable $e) { error_log('feria: 3.0.1 upgrade-fix: ' . $e->getMessage()); }

        /* ─── ۳٫۱٫۰: فاز ۱ روان‌سنجی دقیق (T-Scores، پایایی و اعتبارسنجی L-Scale)
                   + فاز ۲ تحلیل تطابق دونفره و زوجین (Dyadic Synergy) ─── */
        try {
            $p = DB_PREFIX;
            foreach ([
                "ALTER TABLE `{$p}attempts` ADD COLUMN IF NOT EXISTS `duration_sec` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `progress`",
                "ALTER TABLE `{$p}attempts` ADD COLUMN IF NOT EXISTS `validity_json` TEXT NULL AFTER `duration_sec`",
                "ALTER TABLE `{$p}results` ADD COLUMN IF NOT EXISTS `t_score` FLOAT NULL DEFAULT NULL AFTER `percent`",
                "ALTER TABLE `{$p}results` ADD COLUMN IF NOT EXISTS `sten_score` FLOAT NULL DEFAULT NULL AFTER `t_score`",
                "ALTER TABLE `{$p}results` ADD COLUMN IF NOT EXISTS `percentile` FLOAT NULL DEFAULT NULL AFTER `sten_score`",
                "CREATE TABLE IF NOT EXISTS `{$p}dyads` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `token` VARCHAR(32) NOT NULL,
                    `test_id` INT UNSIGNED NOT NULL,
                    `user1_id` BIGINT UNSIGNED NOT NULL,
                    `attempt1_id` BIGINT UNSIGNED NOT NULL,
                    `user2_id` BIGINT UNSIGNED DEFAULT NULL,
                    `attempt2_id` BIGINT UNSIGNED DEFAULT NULL,
                    `rel_type` ENUM('couple','spouse','coworker','friend','business') NOT NULL DEFAULT 'couple',
                    `status` ENUM('pending','ready','archived') NOT NULL DEFAULT 'pending',
                    `synergy_score` INT UNSIGNED DEFAULT NULL,
                    `summary_json` MEDIUMTEXT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_token` (`token`),
                    KEY `ix_u1` (`user1_id`),
                    KEY `ix_u2` (`user2_id`),
                    KEY `ix_att1` (`attempt1_id`),
                    KEY `ix_att2` (`attempt2_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ] as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 3.1 ddl: ' . $e->getMessage()); }
            }
        } catch (Throwable $e) { error_log('feria: 3.1 psychometrics & dyads: ' . $e->getMessage()); }

        /* ─── ۳٫۲٫۰: فاز ۳ پیوند کارنامه به پلنر روزانه (Growth Tasks)
                   + فاز ۴ پرونده بالینی و تشخیصی مشاور (Clinical Case Summary) ─── */
        try {
            $p = DB_PREFIX;
            foreach ([
                "ALTER TABLE `{$p}attempts` ADD COLUMN IF NOT EXISTS `growth_injected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `validity_json`",
                "ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `ref_attempt_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user_id`",
                "ALTER TABLE `{$p}planner_tasks` ADD KEY IF NOT EXISTS `ix_ref_att` (`ref_attempt_id`)",
                "CREATE TABLE IF NOT EXISTS `{$p}sms_logs` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` BIGINT UNSIGNED NULL,
                    `mobile` VARCHAR(20) NOT NULL,
                    `event` VARCHAR(60) NULL,
                    `body` TEXT NOT NULL,
                    `status` VARCHAR(30) NOT NULL DEFAULT 'sent',
                    `provider_id` VARCHAR(100) NULL,
                    `cost` INT NULL,
                    `ip` VARBINARY(16) NULL,
                    `user_agent` VARCHAR(255) NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `ix_mobile` (`mobile`),
                    KEY `ix_event` (`event`),
                    KEY `ix_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS `{$p}clinical_notes` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `attempt_id` BIGINT UNSIGNED NOT NULL,
                    `user_id` BIGINT UNSIGNED NOT NULL,
                    `admin_id` INT UNSIGNED NOT NULL,
                    `diagnosis` VARCHAR(255) DEFAULT NULL,
                    `notes` MEDIUMTEXT NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `ix_att` (`attempt_id`),
                    KEY `ix_u` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ] as $q) {
                try { DB::run($q); } catch (Throwable $e) { error_log('feria: 3.2 ddl: ' . $e->getMessage()); }
            }
        } catch (Throwable $e) { error_log('feria: 3.2 growth & clinical: ' . $e->getMessage()); }

        /* ─── ۳٫۳٫۰: ساختار جدید و تفکیک‌شدهٔ آپلودها در روت پروژه (feria-uploads) ─── */
        try {
            require_once FR_ROOT . '/includes/upload.php';
            fr_ensure_upload_dirs();

            // انتقال یا همگام‌سازی فایل‌های موجود از assets/uploads به feria-uploads
            $legacyBase = FR_ROOT . '/assets/uploads';
            $newBase    = FR_ROOT . '/feria-uploads';

            if (is_dir($legacyBase . '/events')) {
                $evFiles = glob($legacyBase . '/events/*') ?: [];
                foreach ($evFiles as $srcFile) {
                    if (!is_file($srcFile)) continue;
                    $bName = basename($srcFile);
                    if (str_contains($bName, 'workshop')) {
                        $dst = $newBase . '/workshops/' . $bName;
                        if (!is_file($dst)) @copy($srcFile, $dst);
                    } elseif (str_contains($bName, 'course')) {
                        $dst = $newBase . '/courses/' . $bName;
                        if (!is_file($dst)) @copy($srcFile, $dst);
                    }
                }
            }
            if (is_dir($legacyBase . '/services')) {
                $srvFiles = glob($legacyBase . '/services/*') ?: [];
                foreach ($srvFiles as $srcFile) {
                    if (!is_file($srcFile)) continue;
                    $dst = $newBase . '/services/' . basename($srcFile);
                    if (!is_file($dst)) @copy($srcFile, $dst);
                }
            }
            if (is_dir($legacyBase . '/avatars')) {
                $avaFiles = glob($legacyBase . '/avatars/*') ?: [];
                foreach ($avaFiles as $srcFile) {
                    if (!is_file($srcFile)) continue;
                    $dst = $newBase . '/users/' . basename($srcFile);
                    if (!is_file($dst)) @copy($srcFile, $dst);
                }
            }
            if (is_dir($legacyBase . '/tickets')) {
                $tckFiles = glob($legacyBase . '/tickets/*') ?: [];
                foreach ($tckFiles as $srcFile) {
                    if (!is_file($srcFile)) continue;
                    $dst = $newBase . '/tickets/' . basename($srcFile);
                    if (!is_file($dst)) @copy($srcFile, $dst);
                }
            }

            // به‌روزرسانی آدرس‌های قدیمی در پایگاه‌داده به feria-uploads
            $p = DB_PREFIX;
            DB::run("UPDATE `{$p}events` SET `image` = REPLACE(`image`, 'assets/uploads/events/cover-workshop', 'feria-uploads/workshops/cover-workshop') WHERE `image` LIKE 'assets/uploads/events/cover-workshop%'");
            DB::run("UPDATE `{$p}events` SET `image` = REPLACE(`image`, 'assets/uploads/events/cover-course', 'feria-uploads/courses/cover-course') WHERE `image` LIKE 'assets/uploads/events/cover-course%'");
            DB::run("UPDATE `{$p}events` SET `image` = REPLACE(`image`, 'assets/uploads/events/workshop-', 'feria-uploads/workshops/workshop-') WHERE `image` LIKE 'assets/uploads/events/workshop-%'");
            DB::run("UPDATE `{$p}events` SET `image` = REPLACE(`image`, 'assets/uploads/events/course-', 'feria-uploads/courses/course-') WHERE `image` LIKE 'assets/uploads/events/course-%'");
            DB::run("UPDATE `{$p}events` SET `image` = REPLACE(`image`, 'assets/uploads/events/', 'feria-uploads/workshops/') WHERE `kind`='workshop' AND `image` LIKE 'assets/uploads/events/%'");
            DB::run("UPDATE `{$p}events` SET `image` = REPLACE(`image`, 'assets/uploads/events/', 'feria-uploads/courses/') WHERE `kind`='course' AND `image` LIKE 'assets/uploads/events/%'");
            DB::run("UPDATE `{$p}consult_services` SET `image` = REPLACE(`image`, 'assets/uploads/services/', 'feria-uploads/services/') WHERE `image` LIKE 'assets/uploads/services/%'");
            DB::run("UPDATE `{$p}consultants` SET `avatar` = REPLACE(`avatar`, 'assets/uploads/avatars/', 'feria-uploads/users/') WHERE `avatar` LIKE 'assets/uploads/avatars/%'");
            DB::run("UPDATE `{$p}users` SET `avatar` = REPLACE(`avatar`, 'assets/uploads/avatars/', 'feria-uploads/users/') WHERE `avatar` LIKE 'assets/uploads/avatars/%'");
            DB::run("UPDATE `{$p}admins` SET `avatar` = REPLACE(`avatar`, 'assets/uploads/avatars/', 'feria-uploads/users/') WHERE `avatar` LIKE 'assets/uploads/avatars/%'");
            DB::run("UPDATE `{$p}ticket_messages` SET `attachment` = REPLACE(`attachment`, 'assets/uploads/tickets/', 'feria-uploads/tickets/') WHERE `attachment` LIKE 'assets/uploads/tickets/%'");
            DB::run("UPDATE `{$p}settings` SET `value` = REPLACE(`value`, 'assets/uploads/', 'feria-uploads/general/') WHERE `key` IN ('logo', 'footer_badge1_img', 'footer_badge2_img', 'footer_badge3_img', 'footer_badge4_img') AND `value` LIKE '%assets/uploads/%'");
            DB::run("UPDATE `{$p}banners` SET `image` = REPLACE(`image`, 'assets/uploads/', 'feria-uploads/banners/') WHERE `image` LIKE '%assets/uploads/%'");
        } catch (Throwable $e) { error_log('feria: 3.3 feria-uploads: ' . $e->getMessage()); }

        /* ─── ۳٫۴٫۵: تصحیح و تضمین ساختار پلنر روزانه و موتور تمرین‌های رشد ─── */
        try {
            $p = DB_PREFIX;
            fr_db_run_safe_ddl("CREATE TABLE IF NOT EXISTS `{$p}planner_cats` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `name` VARCHAR(60) NOT NULL,
                `color` VARCHAR(20) NOT NULL DEFAULT '#1e3a6e',
                `sort` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_name` (`user_id`,`name`),
                KEY `ix_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            fr_db_run_safe_ddl("ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `note` VARCHAR(500) NOT NULL DEFAULT '' AFTER `category`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `remind_at` DATETIME DEFAULT NULL AFTER `done_at`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `reminded_at` DATETIME DEFAULT NULL AFTER `remind_at`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `recur_id` BIGINT UNSIGNED DEFAULT NULL AFTER `note`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `ref_attempt_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user_id`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}planner_tasks` ADD KEY IF NOT EXISTS `ix_remind` (`reminded_at`,`remind_at`)");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}planner_tasks` ADD KEY IF NOT EXISTS `ix_ref_att` (`ref_attempt_id`)");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}attempts` ADD COLUMN IF NOT EXISTS `growth_injected` TINYINT(1) NOT NULL DEFAULT 0 AFTER `validity_json`");
        } catch (Throwable $e) { error_log('feria: 3.4.5 planner ddl: ' . $e->getMessage()); }

        /* ─── ۳٫۴٫۶: تضمین ساختار جدول قالب‌های پیامک و ردیف‌های پیش‌فرض + تنظیم پترن پیش‌فرض ─── */
        try {
            $p = DB_PREFIX;
            fr_db_run_safe_ddl("CREATE TABLE IF NOT EXISTS `{$p}sms_templates` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `event` VARCHAR(60) NOT NULL UNIQUE,
                `title` VARCHAR(150) NULL,
                `body` TEXT NOT NULL,
                `use_pattern` TINYINT(1) NOT NULL DEFAULT 0,
                `pattern_code` VARCHAR(80) NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            fr_db_run_safe_ddl("ALTER TABLE `{$p}sms_templates` ADD COLUMN IF NOT EXISTS `title` VARCHAR(150) NULL AFTER `event`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}sms_templates` ADD COLUMN IF NOT EXISTS `use_pattern` TINYINT(1) NOT NULL DEFAULT 0 AFTER `body`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}sms_templates` ADD COLUMN IF NOT EXISTS `pattern_code` VARCHAR(80) NULL AFTER `use_pattern`");

            if (DB::setting('kavenegar_otp_pattern', null) === null) {
                DB::setSetting('kavenegar_otp_pattern', 'otp');
            }

            if (class_exists('Kavenegar')) {
                Kavenegar::ensureTemplatesSchema();
            }
        } catch (Throwable $e) { error_log('feria: 3.4.6 sms migration: ' . $e->getMessage()); }

        /* ---- ۳٫۴٫۷: پایداری ۱۰۰٪ سیستم ارسال OTP، تضمین جدول otps و ایزولاسیون کامل API ---- */
        try {
            $p = DB_PREFIX;
            fr_db_run_safe_ddl("CREATE TABLE IF NOT EXISTS `{$p}otps` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `mobile` VARCHAR(20) NOT NULL,
              `code` VARCHAR(10) NOT NULL,
              `code_hash` VARCHAR(255) NULL,
              `purpose` VARCHAR(30) NOT NULL DEFAULT 'login',
              `expires_at` DATETIME NOT NULL,
              `used_at` DATETIME NULL,
              `tries` TINYINT UNSIGNED NOT NULL DEFAULT 0,
              `ip` VARBINARY(16) NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              KEY `ix_mobile_purpose` (`mobile`, `purpose`),
              KEY `ix_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}otps` MODIFY COLUMN `mobile` VARCHAR(20) NOT NULL");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}otps` ADD COLUMN IF NOT EXISTS `code_hash` VARCHAR(255) NULL AFTER `code`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}otps` ADD COLUMN IF NOT EXISTS `used_at` DATETIME NULL AFTER `expires_at`");
            fr_db_run_safe_ddl("ALTER TABLE `{$p}otps` ADD COLUMN IF NOT EXISTS `tries` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `used_at`");
        } catch (Throwable $e) { error_log('feria: 3.4.7 otp migration: ' . $e->getMessage()); }

        /* ---- ۳٫۴٫۸: پایداری ۱۰۰٪ تزریق تمرین‌های پلنر، خودترمیمی ساختار و اصلاح دعوت پارتنر زوجین ---- */
        try {
            $p = DB_PREFIX;
            if (class_exists('GrowthEngine')) {
                GrowthEngine::ensurePlannerSchema();
            }
            if (class_exists('DyadAnalyzer')) {
                DyadAnalyzer::ensureDyadSchema();
            }
            if (class_exists('Kavenegar')) {
                Kavenegar::ensureTemplatesSchema();
            }
        } catch (Throwable $e) { error_log('feria: 3.4.8 planner & dyad migration: ' . $e->getMessage()); }

        /* ---- ۳٫۴٫۹: سامانه جامع سطل زباله، حذف نرم، بازیابی و پایداری دکمه‌های ویرایش و حذف در تمام بخش‌ها ---- */
        try {
            require_once FR_ROOT . '/core/TrashManager.php';
            TrashManager::ensureSchema();
        } catch (Throwable $e) { error_log('feria: 3.4.9 trash manager migration: ' . $e->getMessage()); }

        /* ---- ۳٫۵٫۰: پشتیبانی و سازگاری کامل از نمادهای اعتماد الکترونیکی (اینماد، زرین‌پال، ساماندهی) ---- */
        try {
            // اطمینان از وجود دایرکتوری وکتورهای نماد اعتماد رسمی
            $badgeDir = FR_ROOT . '/assets/img/badges';
            if (!is_dir($badgeDir)) @mkdir($badgeDir, 0755, true);
        } catch (Throwable $e) { error_log('feria: 3.5.0 trust seal badges migration: ' . $e->getMessage()); }

        /* ---- ۳٫۵٫۱: موتور فرضیه‌سازی بالینی و تحلیل‌های عمیق و تخصصی روان‌سنجی ---- */
        try {
            require_once FR_ROOT . '/core/ClinicalAnalyzer.php';
            require_once FR_ROOT . '/core/analysis-deep.php';
        } catch (Throwable $e) { error_log('feria: 3.5.1 clinical psychometric engine migration: ' . $e->getMessage()); }

        /* ---- ۳٫۵٫۲: فیلدهای سفارشی و منطق شرطی تست‌های روان‌شناسی ---- */
        try {
            require_once FR_ROOT . '/core/TestFields.php';
            TestFields::ensureSchema();
        } catch (Throwable $e) { error_log('feria: 3.5.2 test custom fields migration: ' . $e->getMessage()); }

        /* ---- ۳٫۵٫۳: تفکیک فیلدهای اجباری و اختیاری، ضامن تغییر سریع و به‌روزرسانی اعتبارسنجی ---- */
        try {
            require_once FR_ROOT . '/core/TestFields.php';
            TestFields::ensureSchema();
        } catch (Throwable $e) { error_log('feria: 3.5.3 required-optional fields migration: ' . $e->getMessage()); }

        /* ---- ۳٫۶٫۰: صفحه‌ساز دیداری PHPageBuilder / GrapesJS، مدیریت برگه‌ها و ویجت‌های اختصاصی ---- */
        try {
            require_once FR_ROOT . '/core/PageBuilder.php';
            PageBuilder::ensureSchema();
        } catch (Throwable $e) { error_log('feria: 3.6.0 page builder migration: ' . $e->getMessage()); }

        /* ---- ۳٫۷٫۰: فهرست‌ساز وردپرسی، مدیریت سربرگ و پاورقی اختصاصی با صفحه‌ساز دیداری ---- */
        try {
            require_once FR_ROOT . '/core/MenuBuilder.php';
            MenuBuilder::ensureSchema();
            require_once FR_ROOT . '/core/PageBuilder.php';
            PageBuilder::ensureSchema();
        } catch (Throwable $e) { error_log('feria: 3.7.0 menu builder and header/footer migration: ' . $e->getMessage()); }

        /* ---- ۳٫۸٫۰: صفحه‌ساز چندبخشی (تست‌ها، دوره‌ها، کارگاه‌ها، مشاوره) و رفع خطای ۵۰۰ کیف پول ---- */
        try {
            fr_db_run_safe_ddl("ALTER TABLE " . DB::t('event_registrations') . " ADD COLUMN `companion_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `order_id`");
            fr_db_run_safe_ddl("ALTER TABLE " . DB::t('event_registrations') . " ADD COLUMN `companions_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `companion_count`");
            require_once FR_ROOT . '/core/PageBuilder.php';
            PageBuilder::ensureSchema();
            PageBuilder::seedDefaultPages();
        } catch (Throwable $e) { error_log('feria: 3.8.0 page builder and section catalog migration: ' . $e->getMessage()); }

        /* ---- ۳٫۹٫۰: لینک‌های کوتاه تست‌ها و فیلدهای سفارشی به سبک گرویتی فرمز و مشاوره پایان آزمون ---- */
        try {
            require_once FR_ROOT . '/core/TestFields.php';
            TestFields::ensureSchema();
            fr_db_run_safe_ddl("ALTER TABLE " . DB::t('test_fields') . " ADD COLUMN `display_stage` VARCHAR(20) NOT NULL DEFAULT 'post_test' AFTER `show_in_full`");
            fr_db_run_safe_ddl("ALTER TABLE " . DB::t('test_fields') . " ADD INDEX `ix_test_stage` (`test_id`, `display_stage`, `active`)");
            if (function_exists('fr_permalinks_flush')) {
                fr_permalinks_flush();
            }
        } catch (Throwable $e) { error_log('feria: 3.9.0 short links and gravity fields migration: ' . $e->getMessage()); }

        /* ---- ۴٫۰٫۰: ری‌برندینگ کامل (White-Label)، تاریخ تمام‌شمسی، رفع باگ لود صفحه‌ساز هدر/فوتر و پاکسازی پوشه نصب ---- */
        try {
            // ۱. قفل‌سازی هوشمند پوشه install
            @file_put_contents(FR_ROOT . '/install/installed.lock', date('Y-m-d H:i:s'));
            @file_put_contents(FR_ROOT . '/storage/installed.lock', date('Y-m-d H:i:s'));

            // ۲. بررسی و تضمین خودکار کلید رمزنگاری FR_CRYPT_KEY
            if (is_writable(FR_ROOT . '/config.php')) {
                $cfg = @file_get_contents(FR_ROOT . '/config.php');
                if ($cfg && (str_contains($cfg, "'{{ENCRYPT_KEY}}'") || str_contains($cfg, "define('FR_CRYPT_KEY', '')"))) {
                    $newKey = bin2hex(random_bytes(32));
                    $cfg = str_replace(["'{{ENCRYPT_KEY}}'", "define('FR_CRYPT_KEY', '')"], [var_export($newKey, true), "define('FR_CRYPT_KEY', " . var_export($newKey, true) . ")"], $cfg);
                    @file_put_contents(FR_ROOT . '/config.php', $cfg, LOCK_EX);
                }
            }

            // ۳. تنظیمات پیش‌فرض White-Label در دیتابیس
            $wlDefaults = [
                'site_title_en'        => 'Feria Ravan',
                'brand_short_name'     => 'فریا',
                'site_slogan'          => 'مرکز تخصصی آزمون‌های روان‌شناختی، کارگاه‌ها و مشاوره',
                'logo_dark'            => '',
                'favicon'              => '',
                'copyright_text'       => 'تمامی حقوق مادی و معنوی متعلق به {site} می‌باشد.',
                'hide_system_credits'  => '0',
                'footer_credit_text'   => 'طراحی و توسعه اختصاصی',
                'footer_credit_url'    => '',
                'support_phone'        => '021-88888888',
                'support_email'        => 'support@example.com',
                'support_telegram'     => '',
                'support_bale'         => '',
            ];
            foreach ($wlDefaults as $wk => $wv) {
                if (DB::setting($wk, null) === null) {
                    DB::setSetting($wk, $wv);
                }
            }

            // ۴. خودترمیمی و پایداری صفحه‌ساز و فهرست‌ساز
            if (class_exists('PageBuilder')) {
                PageBuilder::ensureSchema();
            }
            if (class_exists('MenuBuilder')) {
                MenuBuilder::ensureSchema();
            }
            if (function_exists('fr_permalinks_flush')) {
                fr_permalinks_flush();
            }
        } catch (Throwable $e) { error_log('feria: 4.0.0 migration: ' . $e->getMessage()); }

        // ---------------------------------------------------------------------
        // ۵. نگارش ۴٫۱٫۰: تثبیت گام‌های فیلدهای سفارشی و اعتبارسنجی الزامی دکمه اعمال کدهای تخفیف
        // ---------------------------------------------------------------------
        try {
            if (class_exists('TestFields')) {
                TestFields::ensureSchema();
            }
        } catch (Throwable $e) { error_log('feria: 4.1.0 migration: ' . $e->getMessage()); }

        // ---------------------------------------------------------------------
        // ۶. نگارش ۴٫۲٫۰: ویرایشگر دیداری و متنی وردپرسی و رندر استاندارد دستورالعمل قبل از شروع
        // ---------------------------------------------------------------------
        try {
            // اطمینان از صحت محتوای دستورالعمل‌ها و پاک‌سازی هرگونه تگ غیرمجاز قدیمی
            if (class_exists('TestFields')) {
                TestFields::ensureSchema();
            }
        } catch (Throwable $e) { error_log('feria: 4.2.0 migration: ' . $e->getMessage()); }

        // ---------------------------------------------------------------------
        // ۷. نگارش ۴٫۳٫۰: پایداری دائمی و قطعی قیمت‌های سفارشی‌سازی‌شده توسط مدیر در تمامی به‌روزرسانی‌ها
        // ---------------------------------------------------------------------
        try {
            DB::setSetting('price_persistence_enabled', '1');
        } catch (Throwable $e) { error_log('feria: 4.3.0 migration: ' . $e->getMessage()); }

        /* نگارش جدید ثبت شود */
        DB::setSetting('fr_version', $codeVer);
        if (function_exists('log_activity')) log_activity('auto_update', 'system', 0, ['from' => $cur, 'to' => $codeVer]);

        /* اگر مدیر لاگین است، خبرش بدهیم */
        if (function_exists('current_admin') && current_admin() && function_exists('flash')) {
            flash('🚀 به‌روزرسانی خودکار اعمال شد (رسیدن به نسخهٔ ' . $codeVer . ') — تست‌های روانشناسی و قابلیت‌های جدید بدون هیچ اقدام دستی فعال شدند.', 'ok');
        }
    } catch (Throwable $e) {
        error_log('feria: update pipeline failed: ' . $e->getMessage());
    } finally {
        /* آزادسازی قفل در هر حال (موفق/خطا) تا مهلت کهنگی هم صرفاً Fallback باشد */
        try { DB::run("UPDATE $T SET `value`='free' WHERE `key`='fr_version_lock'"); } catch (Throwable $e) {}
    }
}
