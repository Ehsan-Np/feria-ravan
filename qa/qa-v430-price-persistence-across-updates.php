<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع تضمین کیفیت: نگارش ۴٫۳٫۰ و پایداری قیمت‌ها در به‌روزرسانی
 * ============================================================================
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

define('FR_TEST_MODE', true);
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'feria_test');
if (!defined('DB_USER')) define('DB_USER', 'feria');
if (!defined('DB_PASS')) define('DB_PASS', 'feria_password');
if (!defined('DB_PREFIX')) define('DB_PREFIX', 'fr_');
if (!defined('SITE_URL')) define('SITE_URL', 'http://127.0.0.1:8000');
if (!defined('FR_ROOT')) define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
if (!defined('FR_ENV')) define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/update.php';
require_once FR_ROOT . '/core/seed-content.php';
if (file_exists(FR_ROOT . '/core/seed-comprehensive-tests.php')) {
    require_once FR_ROOT . '/core/seed-comprehensive-tests.php';
}
if (file_exists(FR_ROOT . '/core/seed-events.php')) {
    require_once FR_ROOT . '/core/seed-events.php';
}

$pass = 0;
$fail = 0;

function it(string $msg, callable $fn): void {
    global $pass, $fail;
    try {
        if ($fn()) {
            echo "  ✅ PASS: {$msg}\n";
            $pass++;
        } else {
            echo "  ❌ FAIL: {$msg}\n";
            $fail++;
        }
    } catch (Throwable $e) {
        echo "  ❌ ERROR: {$msg} -> " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "====================================================================\n";
echo "  🧪 آزمون‌های جامع نگارش ۴٫۳٫۰: پایداری دائمی قیمت‌ها در ارتقا و بذرها\n";
echo "====================================================================\n";

// ۱. بررسی نسخه‌های نگارش در فایل‌های اصلی
it('1. نگارش FR_RELEASE در includes/version.php حداقل 4.3.0 است', function() {
    return defined('FR_RELEASE') && version_compare(FR_RELEASE, '4.3.0', '>=');
});

it('2. نگارش فال‌بک در config.php و sample حداقل 4.3.0 است', function() {
    $cfg = file_get_contents(FR_ROOT . '/config.php');
    $sample = file_get_contents(FR_ROOT . '/includes/config.sample.php');
    return (str_contains($cfg, "'4.3.0'") || str_contains($cfg, "'4.4.0'")) &&
           (str_contains($sample, "'4.3.0'") || str_contains($sample, "'4.4.0'"));
});

it('3. فایل مایگریشن v430_price_persistence_on_update.sql موجود و معتبر است', function() {
    $file = FR_ROOT . '/database/migrations/v430_price_persistence_on_update.sql';
    if (!file_exists($file)) return false;
    $content = file_get_contents($file);
    return str_contains($content, '4.3.0') && str_contains($content, 'price_persistence_enabled');
});

// ۲. راه‌اندازی اولیه و حصول اطمینان از وجود جداول و داده‌های پایه
$p = DB_PREFIX;
DB::run("CREATE TABLE IF NOT EXISTS `{$p}tests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `title` VARCHAR(255) NOT NULL,
    `short_desc` TEXT NULL,
    `description` MEDIUMTEXT NULL,
    `instructions` MEDIUMTEXT NULL,
    `category` ENUM('clinical','personality','intelligence') DEFAULT 'personality',
    `engine` VARCHAR(50) DEFAULT 'likert',
    `audience` ENUM('general','manager') DEFAULT 'general',
    `question_count` INT UNSIGNED DEFAULT 0,
    `est_minutes` INT UNSIGNED DEFAULT 15,
    `price_full` INT UNSIGNED NOT NULL DEFAULT 0,
    `price_sale` INT UNSIGNED NULL DEFAULT NULL,
    `free_enabled` TINYINT(1) DEFAULT 1,
    `sort_order` INT DEFAULT 10,
    `status` ENUM('active','inactive','trash','draft') DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

DB::run("CREATE TABLE IF NOT EXISTS `{$p}events` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(191) NULL UNIQUE,
    `kind` ENUM('workshop','course') NOT NULL DEFAULT 'workshop',
    `title` VARCHAR(255) NOT NULL,
    `short_desc` VARCHAR(255) NULL,
    `description` MEDIUMTEXT NULL,
    `teacher` VARCHAR(120) NULL,
    `price` INT UNSIGNED NOT NULL DEFAULT 0,
    `sale_price` INT UNSIGNED NULL DEFAULT NULL,
    `capacity` INT UNSIGNED DEFAULT 20,
    `companion_enabled` TINYINT(1) DEFAULT 0,
    `companion_max` INT NULL DEFAULT NULL,
    `companion_price` INT UNSIGNED NULL DEFAULT 0,
    `companion_price_mode` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
    `ws_discount_type` ENUM('none','percent','fixed','tiered') NOT NULL DEFAULT 'none',
    `ws_discount_value` INT NULL,
    `ws_discount_tiers` TEXT NULL,
    `ws_discount_scope` ENUM('all','main','companion') DEFAULT 'all',
    `location` VARCHAR(191) NULL,
    `schedule_text` VARCHAR(191) NULL,
    `views` INT UNSIGNED DEFAULT 0,
    `image` VARCHAR(255) NULL,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `reg_open` TINYINT(1) DEFAULT 1,
    `starts_at` DATETIME NULL,
    `ends_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

DB::run("CREATE TABLE IF NOT EXISTS `{$p}consult_services` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `consultant_id` INT UNSIGNED NULL,
    `slug` VARCHAR(191) NOT NULL UNIQUE,
    `title` VARCHAR(191) NOT NULL,
    `short_desc` VARCHAR(255) NULL,
    `description` MEDIUMTEXT NULL,
    `image` VARCHAR(255) NULL,
    `price` INT UNSIGNED NOT NULL DEFAULT 0,
    `duration_min` SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    `mode` ENUM('online','inperson','both') NOT NULL DEFAULT 'inperson',
    `meeting_hint` VARCHAR(255) NULL,
    `sort` INT NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ۳. درج رکوردهای اولیه تستی در صورت عدم وجود
DB::run("INSERT IGNORE INTO `{$p}tests` (`slug`, `title`, `price_full`, `price_sale`, `status`)
         VALUES ('disc-family', 'تست دیسک خانواده', 390000, 290000, 'active')");
DB::run("INSERT IGNORE INTO `{$p}tests` (`slug`, `title`, `price_full`, `price_sale`, `status`)
         VALUES ('jobsat', 'تست رضایت شغلی', 240000, 190000, 'active')");
DB::run("INSERT IGNORE INTO `{$p}tests` (`slug`, `title`, `price_full`, `price_sale`, `status`)
         VALUES ('mbti', 'تست مایرز بریگز MBTI', 390000, 290000, 'active')");

DB::run("INSERT IGNORE INTO `{$p}events` (`slug`, `kind`, `title`, `price`, `sale_price`, `companion_price`, `companion_price_mode`)
         VALUES ('stress-burnout-workshop', 'workshop', 'کارگاه مدیریت استرس و فرسودگی شغلی', 590000, 490000, 50, 'percent')");

DB::run("INSERT IGNORE INTO `{$p}consult_services` (`slug`, `title`, `price`)
         VALUES ('individual-counseling', 'مشاوره فردی', 750000)");

// تنظیم قیمت‌های سفارشی توسط مدیر
$customPrices = [
    'disc_price_full' => 777000,
    'disc_price_sale' => 555000,
    'jobsat_price_full' => 188000,
    'jobsat_price_sale' => 144000,
    'mbti_price_full' => 620000,
    'mbti_price_sale' => 480000,
    'ws_price' => 888000,
    'ws_sale' => 666000,
    'ws_comp_price' => 333000,
    'consult_price' => 999000,
];

DB::run("UPDATE `{$p}tests` SET price_full=?, price_sale=? WHERE slug='disc-family'",
    [$customPrices['disc_price_full'], $customPrices['disc_price_sale']]);
DB::run("UPDATE `{$p}tests` SET price_full=?, price_sale=? WHERE slug='jobsat'",
    [$customPrices['jobsat_price_full'], $customPrices['jobsat_price_sale']]);
DB::run("UPDATE `{$p}tests` SET price_full=?, price_sale=? WHERE slug='mbti'",
    [$customPrices['mbti_price_full'], $customPrices['mbti_price_sale']]);

DB::run("UPDATE `{$p}events` SET price=?, sale_price=?, companion_price=?, companion_price_mode='fixed' WHERE slug='stress-burnout-workshop'",
    [$customPrices['ws_price'], $customPrices['ws_sale'], $customPrices['ws_comp_price']]);

DB::run("UPDATE `{$p}consult_services` SET price=? WHERE slug='individual-counseling'",
    [$customPrices['consult_price']]);

it('4. قیمت‌های سفارشی تستی با موفقیت در دیتابیس تنظیم شدند', function() use ($p, $customPrices) {
    $disc = DB::one("SELECT price_full, price_sale FROM `{$p}tests` WHERE slug='disc-family'");
    $ws   = DB::one("SELECT price, sale_price, companion_price FROM `{$p}events` WHERE slug='stress-burnout-workshop'");
    $cs   = DB::one("SELECT price FROM `{$p}consult_services` WHERE slug='individual-counseling'");

    return (int)$disc['price_full'] === $customPrices['disc_price_full']
        && (int)$disc['price_sale'] === $customPrices['disc_price_sale']
        && (int)$ws['price'] === $customPrices['ws_price']
        && (int)$ws['sale_price'] === $customPrices['ws_sale']
        && (int)$ws['companion_price'] === $customPrices['ws_comp_price']
        && (int)$cs['price'] === $customPrices['consult_price'];
});

// ۴. اجرای خط‌لوله به‌روزرسانی (fr_update_pipeline)
it('5. اجرای کامل خط‌لوله مهاجرت (fr_update_pipeline) بدون خطا انجام شد', function() {
    fr_update_pipeline(true);
    return true;
});

// ۵. اعتبارسنجی عدم تغییر قیمت‌های سفارشی پس از fr_update_pipeline
it('6. قیمت آزمون‌های عمومی پس از اجرای update_pipeline کاملاً دست‌نخورده باقی ماند', function() use ($p, $customPrices) {
    $disc = DB::one("SELECT price_full, price_sale FROM `{$p}tests` WHERE slug='disc-family'");
    $jobsat = DB::one("SELECT price_full, price_sale FROM `{$p}tests` WHERE slug='jobsat'");

    $okDisc = ((int)$disc['price_full'] === $customPrices['disc_price_full'] && (int)$disc['price_sale'] === $customPrices['disc_price_sale']);
    $okJob  = ((int)$jobsat['price_full'] === $customPrices['jobsat_price_full'] && (int)$jobsat['price_sale'] === $customPrices['jobsat_price_sale']);

    return $okDisc && $okJob;
});

it('7. قیمت رویدادها، تخفیف‌ها و همراه پس از اجرای update_pipeline کاملاً حفظ شد', function() use ($p, $customPrices) {
    $ws = DB::one("SELECT price, sale_price, companion_price, companion_price_mode FROM `{$p}events` WHERE slug='stress-burnout-workshop'");
    return (int)$ws['price'] === $customPrices['ws_price']
        && (int)$ws['sale_price'] === $customPrices['ws_sale']
        && (int)$ws['companion_price'] === $customPrices['ws_comp_price']
        && $ws['companion_price_mode'] === 'fixed';
});

it('8. قیمت خدمات مشاوره پس از اجرای update_pipeline کاملاً حفظ شد', function() use ($p, $customPrices) {
    $cs = DB::one("SELECT price FROM `{$p}consult_services` WHERE slug='individual-counseling'");
    return (int)$cs['price'] === $customPrices['consult_price'];
});

// ۶. اعتبارسنجی عدم تغییر قیمت‌ها پس از اجرای توابع Seeder
it('9. اجرای fr_seed_comprehensive_tests قیمت آزمون‌های جامع مانند MBTI را بازنویسی نمی‌کند', function() use ($p, $customPrices) {
    if (function_exists('fr_seed_comprehensive_tests')) {
        fr_seed_comprehensive_tests(true);
    }
    $mbti = DB::one("SELECT price_full, price_sale FROM `{$p}tests` WHERE slug='mbti'");
    return (int)$mbti['price_full'] === $customPrices['mbti_price_full']
        && (int)$mbti['price_sale'] === $customPrices['mbti_price_sale'];
});

it('10. اجرای fr_seed_events_v5 قیمت و همراه کارگاه‌ها را بازنویسی نمی‌کند', function() use ($p, $customPrices) {
    if (function_exists('fr_seed_events_v5')) {
        fr_seed_events_v5();
    }
    $ws = DB::one("SELECT price, sale_price, companion_price FROM `{$p}events` WHERE slug='stress-burnout-workshop'");
    return (int)$ws['price'] === $customPrices['ws_price']
        && (int)$ws['sale_price'] === $customPrices['ws_sale']
        && (int)$ws['companion_price'] === $customPrices['ws_comp_price'];
});

it('11. مارکر سیستمی price_persistence_enabled در جدول تنظیمات فعال شد', function() {
    return DB::setting('price_persistence_enabled') === '1';
});

it('12. نسخه fr_version در جدول تنظیمات حداقل به 4.3.0 ارتقا یافت', function() {
    return version_compare((string)DB::setting('fr_version'), '4.3.0', '>=');
});

echo "====================================================================\n";
echo "  نتایج: {$pass} موفق / {$fail} ناموفق\n";
echo "====================================================================\n";

if ($fail > 0) {
    exit(1);
}
