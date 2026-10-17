<?php
/**
 * =============================================================================
 *  ارتقا + بذر مجدد محتوا — فقط برای مدیر
 *  اجرا: مرورگر → /install/reseed.php?force=1
 *  ─ تغییرات اسکیمای نسخه‌های ۱٫۲ تا ۱٫۶ را idempotent اعمال می‌کند
 *  ─ تست‌های روانشناسی جدید، نسخه‌های مدیران و متن‌های پیش‌فرض را می‌سازد/تکمیل می‌کند
 * =============================================================================
 */
declare(strict_types=1);
if (!file_exists(__DIR__ . '/../config.php')) exit('ابتدا اسکریپت را نصب کنید.');
define('FR_ROOT', dirname(__DIR__));
require_once FR_ROOT . '/config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/admin-auth.php';
require_once FR_ROOT . '/core/seed-content.php';

fr_bootstrap();

// اجرای اسکریپت با نشست مدیر فعال، محیط خط فرمان (CLI) یا کلید امنیتی ممکن است
if (PHP_SAPI !== 'cli' && !current_admin()) {
    $adminCount = 0;
    try { $adminCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('admins')); } catch (Throwable $e) {}
    $cronSecret = '';
    try { $cronSecret = (string)DB::setting('cron_secret', ''); } catch (Throwable $e) {}
    $providedKey = (string)($_GET['key'] ?? ($_GET['secret'] ?? ''));
    $keyMatches = ($cronSecret !== '' && $providedKey !== '' && hash_equals($cronSecret, $providedKey));

    if ($adminCount > 0 && !$keyMatches) {
        if (!headers_sent()) {
            redirect(SITE_URL . '/admin/login.php?back=' . urlencode($_SERVER['REQUEST_URI'] ?? '/install/reseed.php?force=1'));
        }
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("⛔ دسترسی غیرمجاز\nابتدا از مسیر /admin/login.php وارد شوید، سپس همین نشانی را دوباره باز کنید.");
    }
}

header('Content-Type: text/plain; charset=utf-8');

/* ---------------------------------------------------------------------------
 | اعمال idempotent تغییرات اسکیمای نسخهٔ ۱٫۲
 |  هر تغییر فقط اگر ستون مربوطه وجود نداشته باشد اجرا می‌شود.
 * -------------------------------------------------------------------------*/
function fr_has_col(string $table, string $col): bool
{
    try {
        $p = DB_PREFIX;
        $raw = str_starts_with($table, $p) ? $table : $p . $table;
        $cols = array_column(DB::all("SHOW COLUMNS FROM `{$raw}`"), 'Field');
        return in_array($col, $cols, true);
    } catch (Throwable $e) {
        return false;
    }
}
function fr_add_col(string $table, string $col, string $ddl): void
{
    if (!fr_has_col($table, $col)) {
        try {
            DB::run('ALTER TABLE ' . DB::t($table) . ' ADD ' . $ddl);
        } catch (Throwable $e) {
            error_log("reseed fr_add_col ({$table}.{$col}): " . $e->getMessage());
        }
    }
}

if (!fr_has_col('tests', 'audience')) {
    DB::run("ALTER TABLE " . DB::t('tests') .
        " ADD `audience` ENUM('general','manager') NOT NULL DEFAULT 'general' AFTER `engine`");
    echo "✓ ستون مخاطب تست‌های روانشناسی افزوده شد\n";
}
// مخاطب نسخهٔ سازمانی DISC همیشه «مدیران» است — idempotent و بی‌خطا روی هر نصب
DB::run("UPDATE " . DB::t('tests') . " SET audience='manager' WHERE slug='disc-business'");
fr_add_col('questions', 'labels',  "`labels` TEXT NULL AFTER `options`");
fr_add_col('events',    'companion_price_mode', "`companion_price_mode` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed' AFTER `companion_price`");
fr_add_col('events',    'ws_discount_tiers',    "`ws_discount_tiers` TEXT NULL AFTER `ws_discount_value`");
fr_add_col('events',    'ws_discount_scope',    "`ws_discount_scope` ENUM('all','main','companion') NOT NULL DEFAULT 'all' AFTER `ws_discount_tiers`");
fr_add_col('event_registrations', 'discount_meta', "`discount_meta` TEXT NULL AFTER `discount_amount`");
// نوع تخفیف پلکانی
DB::run("ALTER TABLE " . DB::t('events') .
    " MODIFY `ws_discount_type` ENUM('none','percent','fixed','tiered') NOT NULL DEFAULT 'none'");
echo "✓ اسکیمای نسخهٔ ۱٫۲ آماده است\n";

try {
    if (!DB::val("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . DB_PREFIX . "sessions'")) {
        DB::run("CREATE TABLE IF NOT EXISTS " . DB::t('sessions') . " (
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
    }
} catch (Throwable $e) {}

/* ── نسخهٔ ۱٫۳: پروفایل کامل + OTP تغییر موبایل + آواتار/ایمیل مدیر ── */
try {
    if (!DB::val("SHOW TABLES LIKE ?", [DB_PREFIX . 'otps'])) {
        DB::run("CREATE TABLE IF NOT EXISTS " . DB::t('otps') . " (
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
    } else {
        DB::run("ALTER TABLE " . DB::t('otps') .
            " MODIFY `purpose` ENUM('register','login','reset','mobile_change') NOT NULL DEFAULT 'login'");
    }
} catch (Throwable $e) {}
fr_add_col('admins', 'email',  "`email` VARCHAR(191) DEFAULT NULL AFTER `mobile`");
fr_add_col('admins', 'avatar', "`avatar` VARCHAR(255) DEFAULT NULL AFTER `email`");
fr_add_col('users', 'password_hash', "`password_hash` VARCHAR(255) DEFAULT NULL AFTER `mobile`");
fr_add_col('users', 'mobile_verified', "`mobile_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`");
fr_add_col('users', 'last_login_at', "`last_login_at` DATETIME DEFAULT NULL AFTER `extra`");
fr_add_col('users', 'last_login_ip', "`last_login_ip` VARBINARY(16) DEFAULT NULL AFTER `last_login_at`");
echo "✓ اسکیمای نسخهٔ ۱٫۳ آماده است\n";

/* ---------------------------------------------------------------------------
 | نسخهٔ ۱٫۴: صفحات عمومی کارگاه/دوره + سیستم بوکینگ مشاوره
 |  همهٔ تغییرات idempotent‌اند (create-if-missing / add-if-missing)
 * -------------------------------------------------------------------------*/
fr_add_col('events', 'slug',          "`slug` VARCHAR(191) DEFAULT NULL AFTER `title`");
fr_add_col('events', 'short_desc',    "`short_desc` VARCHAR(255) DEFAULT NULL AFTER `slug`");
fr_add_col('events', 'location',      "`location` VARCHAR(191) DEFAULT NULL AFTER `image`");
fr_add_col('events', 'schedule_text', "`schedule_text` VARCHAR(191) DEFAULT NULL AFTER `location`");
fr_add_col('events', 'views',         "`views` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `schedule_text`");
// کلید یکتای اسلاگ فقط اگر هنوز وجود ندارد
if (!DB::val("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='uq_slug'", [DB_PREFIX . 'events'])) {
    DB::run("ALTER TABLE " . DB::t('events') . " ADD UNIQUE KEY `uq_slug` (`slug`), ADD KEY `ix_kind_status` (`kind`,`status`,`starts_at`)");
}
// نوع سفارش «رزرو مشاوره»
DB::run("ALTER TABLE " . DB::t('orders') . " MODIFY `kind` ENUM('test_full','event','consult') NOT NULL DEFAULT 'test_full'");
// جداول بوکینگ مشاوره (ایمن با IF NOT EXISTS)
$cddl = [
"CREATE TABLE IF NOT EXISTS " . DB::t('consultants') . " (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(120) NOT NULL,
  `title` VARCHAR(150) DEFAULT NULL, `bio` TEXT DEFAULT NULL, `avatar` VARCHAR(255) DEFAULT NULL,
  `mobile` VARCHAR(11) DEFAULT NULL, `active` TINYINT(1) NOT NULL DEFAULT 1, `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS " . DB::t('consult_services') . " (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `consultant_id` INT UNSIGNED NOT NULL,
  `slug` VARCHAR(191) NOT NULL, `title` VARCHAR(191) NOT NULL, `short_desc` VARCHAR(255) DEFAULT NULL,
  `description` MEDIUMTEXT DEFAULT NULL, `image` VARCHAR(255) DEFAULT NULL,
  `price` INT UNSIGNED NOT NULL DEFAULT 0, `duration_min` SMALLINT UNSIGNED NOT NULL DEFAULT 45,
  `mode` ENUM('online','inperson','both') NOT NULL DEFAULT 'inperson', `meeting_hint` VARCHAR(255) DEFAULT NULL,
  `sort` INT NOT NULL DEFAULT 0, `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), UNIQUE KEY `uq_slug` (`slug`),
  KEY `ix_consultant` (`consultant_id`), FOREIGN KEY (`consultant_id`) REFERENCES " . DB::t('consultants') . "(`id`) ON DELETE CASCADE
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS " . DB::t('consult_schedules') . " (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `service_id` INT UNSIGNED NOT NULL,
  `weekday` TINYINT UNSIGNED NOT NULL, `start_time` TIME NOT NULL, `end_time` TIME NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`), KEY `ix_service` (`service_id`),
  FOREIGN KEY (`service_id`) REFERENCES " . DB::t('consult_services') . "(`id`) ON DELETE CASCADE
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS " . DB::t('consult_holidays') . " (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `jdate` VARCHAR(10) NOT NULL, `title` VARCHAR(190) DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`), UNIQUE KEY `uq_jdate` (`jdate`)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS " . DB::t('consult_slots') . " (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `service_id` INT UNSIGNED NOT NULL,
  `starts_at` DATETIME NOT NULL, `ends_at` DATETIME NOT NULL,
  `status` ENUM('open','held','booked','blocked') NOT NULL DEFAULT 'open', `held_until` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slot` (`service_id`,`starts_at`), KEY `ix_status_time` (`status`,`starts_at`),
  FOREIGN KEY (`service_id`) REFERENCES " . DB::t('consult_services') . "(`id`) ON DELETE CASCADE
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS " . DB::t('consult_appointments') . " (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `slot_id` BIGINT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL, `consultant_id` INT UNSIGNED NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED DEFAULT NULL, `mode` ENUM('online','inperson') NOT NULL DEFAULT 'inperson',
  `note` TEXT DEFAULT NULL,
  `status` ENUM('pending_payment','booked','done','canceled','noshow') NOT NULL DEFAULT 'pending_payment',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`),
  KEY `ix_user` (`user_id`,`status`), KEY `ix_slot` (`slot_id`), KEY `ix_service_time` (`service_id`,`status`),
  FOREIGN KEY (`slot_id`) REFERENCES " . DB::t('consult_slots') . "(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES " . DB::t('users') . "(`id`)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($cddl as $sql) DB::run($sql);
echo "✓ اسکیمای نسخهٔ ۱٫۴ آماده است\n";

/* ---------------------------------------------------------------------------
 | نسخهٔ ۱٫۶: کیف پول و عودت وجه + پانوشت قابل‌ویرایش + تأخیر بین جلسات
 |  همهٔ تغییرات idempotent‌اند؛ روی هاست فعلی فقط یک‌بار اجرا کنید.
 * -------------------------------------------------------------------------*/
fr_add_col('users',            'bank_card_enc',   "`bank_card_enc` TEXT DEFAULT NULL AFTER `wallet_balance`");
fr_add_col('users',            'bank_card_last4', "`bank_card_last4` CHAR(4) DEFAULT NULL AFTER `bank_card_enc`");
fr_add_col('tests',            'free_teaser',     "`free_teaser` TEXT DEFAULT NULL AFTER `description`");
fr_add_col('results',          'raw_extra',       "`raw_extra` TEXT DEFAULT NULL AFTER `mean_score`");
fr_add_col('consult_services', 'gap_minutes',     "`gap_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `duration_min`");
// ستون context نسخهٔ ۱٫۶ به «محیط|pattern:XX|code:NNNN» تبدیل شد — پهن‌کردن idempotent
DB::run("ALTER TABLE " . DB::t('attempts') . " MODIFY `context` VARCHAR(80) DEFAULT NULL");
// وضعیت جدید تراکنش «reversed» برای جبران بازگشت پرداخت کیف‌پول
DB::run("ALTER TABLE " . DB::t('payments') . " MODIFY `status` ENUM('init','ok','failed','verify_failed','reversed') NOT NULL DEFAULT 'init'");

DB::run("CREATE TABLE IF NOT EXISTS " . DB::t('wallet_transactions') . " (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `amount`         INT NOT NULL,
  `kind`           ENUM('credit','debit','refund','gift','adjustment') NOT NULL,
  `reference_type` VARCHAR(40) DEFAULT NULL,
  `reference_id`   BIGINT UNSIGNED DEFAULT NULL,
  `description`    VARCHAR(255) DEFAULT NULL,
  `expires_at`     DATETIME DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `ix_user` (`user_id`), KEY `ix_created` (`created_at`)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

DB::run("CREATE TABLE IF NOT EXISTS " . DB::t('refund_requests') . " (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `context`      ENUM('event','consult') NOT NULL,
  `reference_id` BIGINT UNSIGNED NOT NULL,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `amount`       INT UNSIGNED NOT NULL,
  `reason`       TEXT DEFAULT NULL,
  `status`       ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
  `admin_note`   TEXT DEFAULT NULL,
  `refund_ref`   VARCHAR(80) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_refund_once` (`context`,`reference_id`),
  KEY `ix_user` (`user_id`), KEY `ix_status` (`status`)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// تنظیمات جدید نسخهٔ ۱٫۶ (بدون رونویسی مقادیر فعلی مدیر)
DB::run("INSERT IGNORE INTO " . DB::t('settings') . " (`key`,`value`) VALUES
 ('footer_credit_text','طراحی و کد نویسی : احسان نادری پناه – 09024561001 | فریا'),
 ('footer_credit_url','https://feria.ir'),
 ('consult_gap_default','0'),
 ('sms_tpl_consult_book_user','{name} عزیز، نوبت «{service}» ({mode}) شما نزد {consultant} برای {when} ثبت شد. 🦢{hint}'),
 ('sms_tpl_consult_book_cons','نوبت جدید: «{service}» ({mode}) — {name} — {when}'),
 ('sms_tpl_consult_cancel','{name} عزیز، نوبت «{service}» مورخ {when} لغو شد.'),
 ('sms_tpl_consult_remind','{name} عزیز، یادآوری: نوبت «{service}» شما نزد {consultant} برای {when} است. 🦢')");
echo "✓ اسکیمای نسخهٔ ۱٫۶ آماده است\n";

/* ---------------------------------------------------------------------------
 | نسخهٔ ۱٫۷: سئو (رویداد/تست روانشناسی) + نقشه‌های محل برگزاری + پیامک اختصاصی رویداد
 |  + حساب خودکار همراهان + عودت همراه + ترجیحات اطلاع‌رسانی کاربر
 * -------------------------------------------------------------------------*/
foreach ([
    'map_google' => "`map_google` VARCHAR(500) DEFAULT NULL AFTER `location`",
    'map_neshan' => "`map_neshan` VARCHAR(500) DEFAULT NULL AFTER `map_google`",
    'map_balad'  => "`map_balad` VARCHAR(500) DEFAULT NULL AFTER `map_neshan`",
    'sms_template' => "`sms_template` TEXT NULL AFTER `reg_open`",
    'seo_title'  => "`seo_title` VARCHAR(200) DEFAULT NULL AFTER `sms_template`",
    'seo_desc'   => "`seo_desc` VARCHAR(320) DEFAULT NULL AFTER `seo_title`",
    'seo_kw'     => "`seo_kw` VARCHAR(120) DEFAULT NULL AFTER `seo_desc`",
    'seo_noindex'=> "`seo_noindex` TINYINT(1) NOT NULL DEFAULT 0 AFTER `seo_kw`",
] as $col => $ddl) fr_add_col('events', $col, $ddl);

foreach ([
    'seo_title'  => "`seo_title` VARCHAR(200) DEFAULT NULL AFTER `instructions`",
    'seo_desc'   => "`seo_desc` VARCHAR(320) DEFAULT NULL AFTER `seo_title`",
    'seo_kw'     => "`seo_kw` VARCHAR(120) DEFAULT NULL AFTER `seo_desc`",
    'seo_noindex'=> "`seo_noindex` TINYINT(1) NOT NULL DEFAULT 0 AFTER `seo_kw`",
] as $col => $ddl) fr_add_col('tests', $col, $ddl);

fr_add_col('event_companions', 'user_id', "`user_id` BIGINT UNSIGNED DEFAULT NULL AFTER `mobile`");
fr_add_col('event_companions', 'status',  "`status` ENUM('active','canceled') NOT NULL DEFAULT 'active' AFTER `user_id`");
fr_add_col('users', 'notif_prefs', "`notif_prefs` VARCHAR(255) DEFAULT NULL AFTER `extra`");
DB::run("ALTER TABLE " . DB::t('refund_requests') .
        " MODIFY `context` ENUM('event','consult','companion') NOT NULL");

// نسخهٔ ۱٫۷: ستون عنوان نمایشی قالب پیامک + قالب‌های جدید — بدون رونویسی ویرایش‌های مدیر
fr_add_col('sms_templates', 'title', "`title` VARCHAR(150) DEFAULT NULL AFTER `event`");
fr_add_col('sms_templates', 'use_pattern', "`use_pattern` TINYINT(1) NOT NULL DEFAULT 0 AFTER `body`");
fr_add_col('sms_templates', 'pattern_code', "`pattern_code` VARCHAR(80) NULL AFTER `use_pattern`");
DB::run("INSERT IGNORE INTO " . DB::t('sms_templates') . " (`event`,`title`,`body`,`use_pattern`,`pattern_code`,`enabled`) VALUES
 ('otp','کد یک‌بارمصرف ورود و ثبت‌نام','کد تأیید ورود شما به %site%: %code%',1,'otp',1),
 ('register','خوش‌آمدگویی پس از ثبت‌نام','%name% عزیز، به %site% خوش آمدید 🦢',0,NULL,1),
 ('payment_ok','پس از پرداخت موفق (قالب پیش‌فرض)','%name% عزیز، پرداخت شما با کد رهگیری %refid% در %site% ثبت شد.',0,NULL,1),
 ('test_done','پس از تکمیل تست روانشناسی','%name% عزیز، آزمون «%test%» شما با موفقیت ثبت شد و نتایج در پنل کاربری آماده است. %site%',0,NULL,1),
 ('report_free','پس از صدور تحلیل رایگان','%name% عزیز، کارنامه مقدماتی آزمون «%test%» برای شما صادر شد: %link%',0,NULL,1),
 ('report_full','پس از صدور تحلیل ویژه','%name% عزیز، گزارش تحلیلی کامل شما برای آزمون «%test%» آماده مشاهده است: %link%',0,NULL,1),
 ('refund_request_user','عودت وجه — تأیید دریافت درخواست','%name% عزیز، درخواست عودت وجه شما به مبلغ %amount% ثبت شد و در صف بررسی است؛ نتیجه پیامک می‌شود. %site%',0,NULL,1),
 ('refund_request_admin','عودت وجه — اطلاع به مدیر/حسابداری','درخواست عودت وجه جدید: %name% (%mobile%) مبلغ %amount% — علت: %reason%',0,NULL,1),
 ('admin_new_order','ثبت‌نام/خرید جدید — اطلاع به مدیر','ثبت‌نام جدید در %site%: %name% (%mobile%) — %test% — مبلغ %amount%',0,NULL,1),
 ('test_nudge','یادآوری آزمون نیمه‌تمام (Nudge)','%name% عزیز، آزمون شما در %site% نیمه‌تمام است؛ برای تکمیل و دریافت کارنامه مراجعه فرمایید: %link%',0,NULL,1)");

// تنظیمات جدید نسخهٔ ۱٫۷
DB::run("INSERT IGNORE INTO " . DB::t('settings') . " (`key`,`value`) VALUES
 ('logo_alt',''),
 ('workshops_hero_title','کارگاه‌های روانشناسی فریا'),
 ('workshops_hero_desc','اینجا کارگاه‌های عملی و یک‌روزه را پیدا می‌کنید؛ آن‌هایی که با تمرین واقعی یاد می‌گیرید، نه فقط حرف. جزئیات هر کدام آزادانه در دسترس است؛ هر وقت آماده شدید، ثبت‌نامش دو دقیقه بیشتر طول نمی‌کشد. اگر با عزیزانتان می‌آیید، قیمت‌های گروهی و نفرهای رایگان هر کارگاه را حتماً ببینید.'),
 ('courses_hero_title','دوره‌های آموزشی فریا'),
 ('courses_hero_desc','دوره‌های چندجلسه‌ای برای رشد عمیق‌تر؛ از طرحواره‌درمانی تا مهارت‌های ارتباطی. هر دوره سرفصل‌شفاف، مدرس مشخص و پشتیبانی کامل دارد.'),
 ('consult_hero_title','رزرو مشاورهٔ تخصصی'),
 ('consult_hero_desc','روز و ساعت دلخواهتان را از تقویم انتخاب کنید، آنلاین یا حضوری بودن جلسه را مشخص کنید و نوبت‌تان را همان‌جا قطعی کنید. قبل از جلسه یادآوری پیامکی می‌گیرید.'),
 ('admin_notify_mobiles',''),
 ('seo_home_desc','فریا روان؛ پلتفرم تست‌های روانشناسی آنلاین، کارگاه‌ها، دوره‌های آموزشی و رزرو مشاوره — تحلیل حرفه‌ای، ثبت‌نام سریع و پرداخت امن.')");
echo "✓ اسکیمای نسخهٔ ۱٫۷ آماده است\n";

/* ---------------------------------------------------------------------------
 | نسخهٔ ۱٫۸: آرشیو عمومی تست‌های روانشناسی (tests.php) + متن‌های پیش‌فرض هیرو
 |  ستون engine در fr_tests از نوع VARCHAR است؛ موتور جدید mslikert بدون
 |  تغییر اسکیما پشتیبانی می‌شود — فقط تنظیمات جدید idempotent درج می‌شود.
 * -------------------------------------------------------------------------*/
DB::run("INSERT IGNORE INTO " . DB::t('settings') . " (`key`,`value`) VALUES
 ('tests_hero_title','تست‌های روانشناسی فریا'),
 ('tests_hero_desc','مجموعهٔ کامل تست‌های روانشناسی علمی فریا را اینجا پیدا می‌کنید: از نقشهٔ هوش‌های چندگانه و هوش هیجانی تا شخصیت‌شناسی، طرحواره‌ها و غربالگرهای بالینی. هر تست روانشناسی با تحلیل رایگان شروع می‌شود و نسخهٔ ویژه‌اش واکاوی عمیق و پژوهش‌محور می‌دهد.')");

/* تنظیمات اطلاع‌رسانی محرک‌ها و مرکز خروجی‌ها (نسخهٔ ۱٫۸) */
DB::run("INSERT IGNORE INTO " . DB::t('settings') . " (`key`,`value`) VALUES
 ('activity_log_enabled','1'),
 ('activity_log_display_limit','120'),
 ('notify_milestone_events','1'),
 ('milestone_activity_collected_reason','ثبت شرکت در برنامهٔ توانمندسازی مدیران'),
 ('milestone_activity_collected_title','فعالیت‌های شما با موفقیت ثبت شد'),
 ('milestone_activity_collected_body','با سلام و احترام،\nفعالیت‌های مرتبط با «%reason%» برای شما در سامانه ثبت و دسته‌بندی شد.\nبرای مشاهدهٔ جزئیات به پیشخوان کاربری خود مراجعه بفرمایید.\n\nفریا روان'),
 ('milestone_activity_collected_sms','فریا: فعالیت‌های شما در «%reason%» در سامانه ثبت و دسته‌بندی شد.'),
 ('notify_export_ready','1'),
 ('report_export_ready_title','خروجی گزارش شما آماده است'),
 ('report_export_ready_body','با سلام و احترام،\nخروجی گزارش شما در سامانه آمادهٔ دریافت است. لینک‌های دانلود برای مدت کوتاهی فعال می‌مانند؛ لطفاً هر چه سریع‌تر آن‌ها را دریافت نمایید.\n\nفریا روان'),
 ('report_export_ready_sms','فریا: خروجی گزارش شما آماده است؛ برای دریافت به پیشخوان کاربری مراجعه نمایید.')");
echo "✓ تنظیمات نسخهٔ ۱٫۸ آماده است\n";

/* ---------------------------------------------------------------------------
 | نسخهٔ ۱٫۹: زباله‌دان تست‌های روانشناسی + اصلاح تاریخی درآمدها + ثبت نگارش دیتابیس
 |  (همان کاری که خط‌لولهٔ به‌روزرسانی خودکار includes/update.php می‌کند؛
 |   این‌جا برای اجرای دستی/اطمینان مدیر تکرار می‌شود — همه idempotent است)
 * -------------------------------------------------------------------------*/
try {
    DB::run("ALTER TABLE " . DB::t('tests') .
            " MODIFY `status` ENUM('active','inactive','draft','trash') NOT NULL DEFAULT 'active'");
    echo "✓ زباله‌دان تست‌های روانشناسی (status='trash') آماده است — ۱٫۹\n";
} catch (Throwable $e) { echo "- تغییر enum وضعیت تست‌های روانشناسی: " . $e->getMessage() . "\n"; }

try {
    $n1 = DB::run("UPDATE " . DB::t('orders') . " o
                   JOIN " . DB::t('event_registrations') . " er ON er.order_id = o.id
                   JOIN " . DB::t('refund_requests') . " rr
                     ON rr.context='event' AND rr.reference_id = er.id AND rr.status='paid'
                   SET o.status='refunded' WHERE o.status='paid'")->rowCount();
    $n2 = DB::run("UPDATE " . DB::t('orders') . " o
                   JOIN " . DB::t('consult_appointments') . " a ON a.order_id = o.id
                   JOIN " . DB::t('refund_requests') . " rr
                     ON rr.context='consult' AND rr.reference_id = a.id AND rr.status='paid'
                   SET o.status='refunded' WHERE o.status='paid'")->rowCount();
    echo "✓ اصلاح تاریخی درآمدها: " . ($n1 + $n2) . " سفارش برگشتی علامت‌گذاری شد — ۱٫۹\n";
} catch (Throwable $e) { echo "- اصلاح تاریخی درآمدها: " . $e->getMessage() . "\n"; }

/* ---------------------------------------------------------------------------
 | نسخهٔ ۲٫۰: سامانهٔ دیدگاه و امتیاز ستاره‌ای (جداول + لیبل‌ها + تنظیمات گزارش)
 * -------------------------------------------------------------------------*/
try {
    $p = DB_PREFIX;
    DB::run("CREATE TABLE IF NOT EXISTS `{$p}review_labels` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `title` VARCHAR(120) NOT NULL,
      `color` VARCHAR(7) NOT NULL DEFAULT '#0c7a4d', `bg` VARCHAR(7) NOT NULL DEFAULT '#e9f9f1',
      `sort_order` INT NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    DB::run("CREATE TABLE IF NOT EXISTS `{$p}reviews` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `context` ENUM('test','workshop','course') NOT NULL, `ref_id` INT UNSIGNED NOT NULL,
      `user_id` BIGINT UNSIGNED DEFAULT NULL, `author_name` VARCHAR(150) NOT NULL,
      `author_mobile` VARCHAR(11) DEFAULT NULL, `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
      `body` TEXT NOT NULL, `label_id` INT UNSIGNED DEFAULT NULL, `label_text` VARCHAR(120) DEFAULT NULL,
      `badge_auto` ENUM('participant','admin') DEFAULT NULL,
      `source` ENUM('user','admin','excel') NOT NULL DEFAULT 'user',
      `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `ix_ref_status` (`context`,`ref_id`,`status`,`created_at`), KEY `ix_user` (`user_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ جداول دیدگاه‌ها آماده است — ۲٫۰\n";
} catch (Throwable $e) { echo "- جداول دیدگاه‌ها: " . $e->getMessage() . "\n"; }

try {
    require_once __DIR__ . '/../includes/reviews.php';
    fr_review_seed_labels();
    echo "✓ لیبل‌های پیش‌فرض دیدگاه نشانده شد — ۲٫۰\n";
} catch (Throwable $e) { echo "- لیبل‌های دیدگاه: " . $e->getMessage() . "\n"; }

foreach (['reviews_auto_approve' => '1', 'report_qr_show' => '1', 'report_qr_link' => ''] as $k => $v) {
    try {
        if (DB::setting($k, null) === null) { DB::setSetting($k, $v); echo "✓ تنظیم $k بذر شد — ۲٫۰\n"; }
    } catch (Throwable $e) { echo "- تنظیم $k: " . $e->getMessage() . "\n"; }
}

/* ---------------------------------------------------------------------------
 | نسخهٔ ۲٫۱: ری‌برندینگ متن‌ها + فوتر نماددار + میهمان رایگانِ پلکانی
 |           + نقش‌ها/سطح دسترسی + برنامه‌ریز روزانه + یادآور تلگرام/بله + خبرنامهٔ پیامکی
 * -------------------------------------------------------------------------*/
try {
    $p = DB_PREFIX;
    DB::run("CREATE TABLE IF NOT EXISTS `{$p}texts` (
      `k` VARCHAR(120) NOT NULL, `txt` TEXT NOT NULL,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`k`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    DB::run("CREATE TABLE IF NOT EXISTS `{$p}admin_roles` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `title` VARCHAR(120) NOT NULL,
      `caps` JSON DEFAULT NULL, `is_system` TINYINT(1) NOT NULL DEFAULT 0,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    DB::run("CREATE TABLE IF NOT EXISTS `{$p}planner_tasks` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` BIGINT UNSIGNED NOT NULL,
      `day` DATE NOT NULL, `title` VARCHAR(190) NOT NULL, `time_note` VARCHAR(5) DEFAULT NULL,
      `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
      `category` VARCHAR(30) NOT NULL DEFAULT 'personal',
      `done` TINYINT(1) NOT NULL DEFAULT 0, `done_at` DATETIME DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `ix_user_day` (`user_id`,`day`),
      FOREIGN KEY (`user_id`) REFERENCES `{$p}users`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    DB::run("CREATE TABLE IF NOT EXISTS `{$p}planner_rewards` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` BIGINT UNSIGNED NOT NULL,
      `streak_from` INT NOT NULL, `streak_to` INT NOT NULL,
      `type` ENUM('discount','message') NOT NULL DEFAULT 'discount',
      `percent` INT DEFAULT NULL, `code` VARCHAR(40) DEFAULT NULL, `note` VARCHAR(300) DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `ix_user` (`user_id`),
      FOREIGN KEY (`user_id`) REFERENCES `{$p}users`(`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    DB::run("CREATE TABLE IF NOT EXISTS `{$p}newsletter` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `mobile` VARCHAR(11) NOT NULL,
      `name` VARCHAR(120) DEFAULT NULL, `status` ENUM('active','unsub') NOT NULL DEFAULT 'active',
      `source` VARCHAR(30) NOT NULL DEFAULT 'footer',
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `unsub_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`), UNIQUE KEY `uq_mobile` (`mobile`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ جداول نسخهٔ ۲٫۱ ساخته شد\n";
} catch (Throwable $e) { echo "- جداول ۲٫۱: " . $e->getMessage() . "\n"; }

foreach ([
    ['admins',            "ADD COLUMN `role_id` INT UNSIGNED DEFAULT NULL AFTER `role`"],
    ['users',             "ADD COLUMN `telegram_chat_id` VARCHAR(60) DEFAULT NULL AFTER `notif_prefs`"],
    ['users',             "ADD COLUMN `bale_chat_id` VARCHAR(60) DEFAULT NULL AFTER `telegram_chat_id`"],
    ['event_companions',  "ADD COLUMN `is_free` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_id`"],
] as [$tb, $stmt]) {
    try { DB::run("ALTER TABLE " . DB::t($tb) . " " . $stmt); echo "✓ ستون ۲٫۱ در {$tb}\n"; }
    catch (Throwable $e) { /* ستون هست */ }
}

try { require_once __DIR__ . '/../includes/roles.php'; fr_roles_seed(); echo "✓ نقش‌های سیستمی بذر شد — ۲٫۱\n"; }
catch (Throwable $e) { echo "- نقش‌ها: " . $e->getMessage() . "\n"; }
try { require_once __DIR__ . '/../includes/texts.php'; fr_texts_seed(); echo "✓ فرهنگ متن‌های پایه بذر شد — ۲٫۱\n"; }
catch (Throwable $e) { echo "- متن‌ها: " . $e->getMessage() . "\n"; }

foreach ([
    'planner_enabled'=>'1','planner_streak_target'=>'7','planner_reward_type'=>'discount',
    'planner_reward_percent'=>'20','planner_reward_message'=>'','planner_remind_time'=>'08:30',
    'planner_remind_count'=>'1','planner_remind_times'=>'["08:30"]',   // ۲٫۴: یادآوری چندنوبتی (پیش‌فرض ۱ نوبت)
    'social_event_remind'=>'1','consult_social_hours'=>'24','event_social_hours'=>'24','test_nudge_days'=>'2',   // ۲٫۵
    'planner_daily_target'=>'1',
    'telegram_bot_token'=>'','telegram_bot_username'=>'','bale_bot_token'=>'','bale_bot_username'=>'',
    'logo_desc'=>'','footer_links_json'=>'','footer_links2_json'=>'',
    'footer_badge1_img'=>'','footer_badge1_url'=>'','footer_badge1_alt'=>'نماد اعتماد الکترونیک','footer_badge1_html'=>'',
    'footer_badge2_img'=>'','footer_badge2_url'=>'','footer_badge2_alt'=>'درگاه پرداخت زرین‌پال','footer_badge2_html'=>'',
    'footer_badge3_img'=>'','footer_badge3_url'=>'','footer_badge3_alt'=>'انجمن روانشناسی','footer_badge3_html'=>'',
    'newsletter_title'=>'خبرنامهٔ پیامکی','newsletter_desc'=>'از دوره‌ها، کارگاه‌ها و تست‌های روانشناسی جدید زودتر از همه باخبر شوید.',
] as $k => $v) {
    try { if (DB::setting($k, null) === null) { DB::setSetting($k, $v); echo "✓ تنظیم $k بذر شد — ۲٫۱\n"; } }
    catch (Throwable $e) { echo "- تنظیم $k: " . $e->getMessage() . "\n"; }
}
try { if (DB::setting('cron_secret', null) === null) { DB::setSetting('cron_secret', bin2hex(random_bytes(16))); echo "✓ کلید کرون ساخته شد — ۲٫۱\n"; } }
catch (Throwable $e) { /* بی‌صدا */ }

/* نگارش فعلی دیتابیس ثبت شود تا خط‌لولهٔ به‌روزرسانی خودکار (۱٫۹) دوباره تکرار نشود */
$frRel = defined('FR_RELEASE') ? FR_RELEASE : (defined('FR_VERSION') ? FR_VERSION : '2.1.0');
/* ---------------------------------------------------------------------------
 | نسخهٔ ۲٫۲: ستون‌های نام کاربری/پیوند مدیر + دیدگاه همه‌کاربره + نماد چهارم
 |           + بازقیمت‌گذاری اقلام سیدشده بر اساس نرخ بازار روانشناسی ایران
 * -------------------------------------------------------------------------*/
try {
    $p = DB_PREFIX;
    foreach ([
        "ALTER TABLE `{$p}users`   ADD COLUMN IF NOT EXISTS `username` VARCHAR(60) NULL AFTER `mobile`",
        "ALTER TABLE `{$p}admins`  ADD COLUMN IF NOT EXISTS `user_id` BIGINT UNSIGNED NULL AFTER `id`",
        "ALTER TABLE `{$p}reviews` ADD COLUMN IF NOT EXISTS `kind` ENUM('review','question') NOT NULL DEFAULT 'review' AFTER `rating`",
        "ALTER TABLE `{$p}reviews` MODIFY `rating` TINYINT UNSIGNED NULL",
        "ALTER TABLE `{$p}reviews` MODIFY `badge_auto` ENUM('participant','admin','guest') NULL",
        "ALTER TABLE `{$p}reviews` ADD COLUMN IF NOT EXISTS `admin_reply` TEXT NULL AFTER `status`",
        "ALTER TABLE `{$p}reviews` ADD COLUMN IF NOT EXISTS `replied_at` DATETIME NULL AFTER `admin_reply`",
    ] as $q) { if (function_exists('fr_db_run_safe_ddl')) { fr_db_run_safe_ddl($q); } else { try { DB::run($q); } catch (Throwable $e) {} } }
    try { DB::run("ALTER TABLE `{$p}users` ADD UNIQUE KEY `uq_username` (`username`)"); } catch (Throwable $e) {}
    try { DB::run("ALTER TABLE `{$p}admins` ADD KEY `ix_admin_user` (`user_id`)"); } catch (Throwable $e) {}
    foreach (['footer_badge4_img' => '', 'footer_badge4_url' => '', 'footer_badge4_html' => '', 'footer_badge4_alt' => 'انجمن روانشناسی'] as $k => $v) {
        if (DB::setting($k, null) === null) DB::setSetting($k, $v);
    }
    echo "✓ اسکیمای نسخهٔ ۲٫۲ آماده است (نام کاربری + دیدگاه همه‌کاربره + نماد چهارم)\n";
} catch (Throwable $e) { echo "- اسکیمای ۲٫۲: " . $e->getMessage() . "\n"; }

/* ---------------------------------------------------------------------------
 | نسخهٔ ۲٫۳: اطلاع‌رسانی اجتماعی (نام کاربری→چت‌آیدی) + تکرارشوندهٔ پلنر
 * -------------------------------------------------------------------------*/
try {
    $p = DB_PREFIX;
    foreach ([
        "ALTER TABLE `{$p}users` ADD COLUMN IF NOT EXISTS `tg_username` VARCHAR(60) NULL AFTER `bale_chat_id`",
        "ALTER TABLE `{$p}users` ADD COLUMN IF NOT EXISTS `bale_username` VARCHAR(60) NULL AFTER `tg_username`",
        "ALTER TABLE `{$p}planner_tasks` ADD COLUMN IF NOT EXISTS `recur_id` BIGINT UNSIGNED NULL AFTER `category`",
        "CREATE TABLE IF NOT EXISTS `{$p}social_links` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` BIGINT UNSIGNED NOT NULL,
            `channel` ENUM('telegram','bale') NOT NULL, `username` VARCHAR(60) NULL,
            `connect_code` VARCHAR(12) NOT NULL DEFAULT '', `chat_id` VARCHAR(60) NULL,
            `status` ENUM('pending','connected') NOT NULL DEFAULT 'pending',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `connected_at` DATETIME NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_user_channel` (`user_id`,`channel`),
            KEY `ix_code` (`channel`,`connect_code`), KEY `ix_username` (`channel`,`username`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `{$p}notify_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` BIGINT UNSIGNED NULL,
            `channel` ENUM('telegram','bale') NOT NULL,
            `msg_kind` ENUM('slot','test','connected','bulk','planner') NOT NULL DEFAULT 'slot',
            `body` VARCHAR(900) NULL, `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
            `error` VARCHAR(300) NULL, `slot_date` DATE NULL, `slot_idx` TINYINT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`),
            KEY `ix_user` (`user_id`), KEY `ix_slot` (`slot_date`,`slot_idx`), KEY `ix_status` (`status`,`created_at`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `{$p}planner_recur` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` BIGINT UNSIGNED NOT NULL,
            `title` VARCHAR(190) NOT NULL, `time_note` VARCHAR(5) NULL,
            `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
            `category` VARCHAR(30) NOT NULL DEFAULT 'personal',
            `freq` ENUM('daily','weekday','weekly') NOT NULL DEFAULT 'daily',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`),
            KEY `ix_user` (`user_id`,`active`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ] as $q) { if (function_exists('fr_db_run_safe_ddl')) { fr_db_run_safe_ddl($q); } else { try { DB::run($q); } catch (Throwable $e) {} } }
    foreach ([
        'notif_daily_count' => '3', 'notif_times' => '["08:30","13:00","20:30"]',
        'notif_test_msg' => "✅ اتصال شما به ربات با موفقیت انجام شد!\nاز این لحظه به بعد، اطلاع‌رسانی‌های «{site}» به‌صورت کامل و خودکار برای شما ارسال می‌شود. 🦢",
        'tg_update_offset' => '0', 'bale_update_offset' => '0',
    ] as $k => $v) { if (DB::setting($k, null) === null) DB::setSetting($k, $v); }
    if ((string)DB::setting('cron_secret', '') === '') DB::setSetting('cron_secret', bin2hex(random_bytes(16)));
    echo "✓ اسکیمای نسخهٔ ۲٫۳ آماده است (اطلاع‌رسانی اجتماعی + تکرارشوندهٔ پلنر)\n";
} catch (Throwable $e) { echo "- اسکیمای ۲٫۳: " . $e->getMessage() . "\n"; }

/* ─── بازقیمت‌گذاری انبوه ۲٫۲ (منسوخ در ۴٫۳٫۰+ جهت پایداری قیمت‌های سفارشی مدیر) ─── */
echo "✓ پایداری قیمت‌های سفارشی مدیر فعال است (عدم بازنویسی نرخ‌ها)\n";

DB::setSetting('fr_version', $frRel);
echo "✓ نگارش دیتابیس ثبت شد: " . $frRel . "\n";

/* ---------------------------------------------------------------------------
 | بذر محتوا (v1 + v2)
 * -------------------------------------------------------------------------*/
if (isset($_GET['force'])) {
    DB::setSetting('content_seeded', '0');
    DB::setSetting('content_seeded_v2', '0');
    DB::setSetting('content_seeded_v3', '0');
    DB::setSetting('content_seeded_v4', '0');
    DB::setSetting('content_seeded_v5', '0');      // ۱۰ کارگاه + ۱۰ دورهٔ سورس گیت‌هاب (نسخهٔ ۱٫۵)
    DB::setSetting('texts_seeded_v6', '0');        // توضیحات پیش‌فرض تست‌های روانشناسی (نسخهٔ ۱٫۶)
    DB::setSetting('consult_seeded_v1', '0');
    DB::setSetting('content_seeded_v8', '0');      // گاردنر ۹هوش + هوش هیجانی شوت (نسخهٔ ۱٫۸)
}
fr_seed_content();

/* نسخهٔ ۱٫۸: بذر دو تست روانشناسی جدید حتی بدون force هم اجرا می‌شود (idempotent)؛
   کاربران نسخه‌های قبلی فقط با بازکردن همین صفحه، تست‌های روانشناسی تازه را می‌گیرند */
if (function_exists('fr_seed_mseq_tests')) fr_seed_mseq_tests();
if (file_exists(FR_ROOT . '/core/seed-comprehensive-tests.php')) {
    require_once FR_ROOT . '/core/seed-comprehensive-tests.php';
    if (function_exists('fr_seed_comprehensive_tests')) fr_seed_comprehensive_tests(isset($_GET['force']));
}

/* نسخهٔ ۲٫۹: بازتولید تحلیل‌های عمیق نسخهٔ ویژه (دکتری روان‌شناسی بالینی) —
   idempotent؛ قالب‌های بانددار + فصل پایانی هر آزمون از پایگاه دانش علمی
   core/analysis-deep بازسازی و به‌روز می‌شوند. */
try {
    require_once FR_ROOT . '/core/seed-analyses29.php';
    if (function_exists('fr_seed_deep_analyses')) fr_seed_deep_analyses(fn(string $m) => print("- {$m}\n"));
} catch (Throwable $e) { echo "- بذر تحلیل‌های عمیق ۲٫۹: " . $e->getMessage() . "\n"; }

echo "✅ بذر محتوا انجام شد.\n";
foreach (DB::all('SELECT slug, audience, (SELECT COUNT(*) FROM ' . DB::t('questions') . ' q WHERE q.test_id=t.id) AS qc FROM ' . DB::t('tests') . ' t ORDER BY t.sort_order') as $r) {
    echo "- {$r['slug']} ({$r['audience']}): {$r['qc']} سؤال\n";
}
echo "قالب‌های تحلیل: " . DB::val('SELECT COUNT(*) FROM ' . DB::t('analysis_templates')) . "\n";
echo "کارگاه‌ها: " . DB::val("SELECT COUNT(*) FROM " . DB::t('events') . " WHERE kind='workshop'") . "\n";
echo "دوره‌ها: " . DB::val("SELECT COUNT(*) FROM " . DB::t('events') . " WHERE kind='course'") . "\n";
echo "خدمات مشاوره: " . DB::val('SELECT COUNT(*) FROM ' . DB::t('consult_services')) . "\n";
echo "اسلات‌های باز آینده: " . DB::val("SELECT COUNT(*) FROM " . DB::t('consult_slots') . " WHERE status='open' AND starts_at>NOW()") . "\n";
