<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع فریا روان — نگارش ۳٫۶٫۰ (QA Test Suite v3.6.0)
 *  ─ اعتبارسنجی سیستم مدیریت برگه‌ها (admin/pages.php)
 *  ─ اعتبارسنجی صفحه‌ساز دیداری بر پایه معماری PHPageBuilder / GrapesJS
 *  ─ آزمون ویجت‌های اختصاصی فریا روان (تست‌ها، دوره‌ها، کارگاه‌ها، مشاوره و...)
 *  ─ آزمون حل شورت‌کدها و بلوک‌های داینامیک
 *  ─ آزمون تنظیم صفحه اصلی سفارشی، مجوزها، سئو و پیوندهای یکتا
 * ============================================================================
 */
declare(strict_types=1);

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
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/includes/seo.php';
require_once FR_ROOT . '/core/PageBuilder.php';

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "   FERIA RAVAN v3.6.0 QA TEST SUITE: PAGE BUILDER & WIDGETS\n";
echo "========================================================\n\n";

// بخش ۱: تطابق نگارش ۳٫۶٫۰ و فایل‌های هسته
echo "1. Testing Version & Core Files Consistency:\n";
assert_test(version_compare(FR_RELEASE, '3.6.0', '>='), "FR_RELEASE is 3.6.0 or higher (actual: " . FR_RELEASE . ")");
assert_test(version_compare(FR_VERSION, '3.6.0', '>='), "FR_VERSION is 3.6.0 or higher (actual: " . FR_VERSION . ")");
$configContent = file_get_contents(FR_ROOT . '/config.php');
assert_test(str_contains($configContent, '3.6.') || str_contains($configContent, '3.7.') || str_contains($configContent, '3.8.'), "config.php references version 3.6.0 or higher");
$updateContent = file_get_contents(FR_ROOT . '/includes/update.php');
assert_test(str_contains($updateContent, '۳٫۶٫۰'), "includes/update.php has v3.6.0 migration hook");

assert_test(file_exists(FR_ROOT . '/core/PageBuilder.php'), "core/PageBuilder.php exists");
assert_test(file_exists(FR_ROOT . '/admin/pages.php'), "admin/pages.php exists");
assert_test(file_exists(FR_ROOT . '/admin/page-builder.php'), "admin/page-builder.php exists");
assert_test(file_exists(FR_ROOT . '/api/page-builder.php'), "api/page-builder.php exists");
assert_test(file_exists(FR_ROOT . '/page.php'), "page.php exists");

// بخش ۲: بررسی توزیع محلی GrapesJS برای استفاده بدون وابستگی به اینترنت
echo "\n2. Testing Local GrapesJS Standalone Distribution:\n";
$grapesJsPath  = FR_ROOT . '/assets/vendor/grapesjs/grapes.min.js';
$grapesCssPath = FR_ROOT . '/assets/vendor/grapesjs/grapes.min.css';
assert_test(file_exists($grapesJsPath), "grapes.min.js exists in assets/vendor/grapesjs/");
assert_test(filesize($grapesJsPath) > 500000, "grapes.min.js has valid standalone bundle size (" . round(filesize($grapesJsPath) / 1024) . " KB)");
assert_test(file_exists($grapesCssPath), "grapes.min.css exists in assets/vendor/grapesjs/");
assert_test(filesize($grapesCssPath) > 10000, "grapes.min.css has valid CSS size (" . round(filesize($grapesCssPath) / 1024) . " KB)");

// بخش ۳: اعتبارسنجی خودترمیمی پایگاه داده و ساختار جدول صفحات
echo "\n3. Testing Database Self-Healing Schema Migration:\n";
PageBuilder::ensureSchema();
$tbl = PageBuilder::table();
$cols = array_column(DB::all("SHOW COLUMNS FROM {$tbl}"), 'Field');
$requiredCols = [
    'id', 'name', 'title', 'slug', 'content_html', 'content_css',
    'builder_json', 'layout', 'seo_title', 'meta_desc', 'noindex',
    'is_home', 'active', 'view_count', 'created_at', 'updated_at'
];
foreach ($requiredCols as $c) {
    assert_test(in_array($c, $cols, true), "Column '{$c}' exists in table {$tbl}");
}

$indexes = array_column(DB::all("SHOW INDEX FROM {$tbl}"), 'Key_name');
assert_test(in_array('ix_is_home', $indexes, true) || in_array('PRIMARY', $indexes, true), "Indexes exist on '{$tbl}'");

// بخش ۴: آزمون ثبت و بازخوانی برگه‌ها (CRUD Operations)
echo "\n4. Testing Page CRUD Lifecycle:\n";
$testSlug = 'qa-test-page-' . time();
$pageId = PageBuilder::savePage([
    'name'        => 'برگه آزمایشی آزمون کیفیت ۳٫۶٫۰',
    'title'       => 'برگه آزمایشی کنترل کیفیت',
    'slug'        => $testSlug,
    'layout'      => 'default',
    'seo_title'   => 'سئو برگه کنترل کیفیت',
    'meta_desc'   => 'توضیحات متای برگه آزمایشی',
    'noindex'     => 1,
    'is_home'     => 0,
    'active'      => 1,
]);
assert_test($pageId > 0, "Page created successfully with ID {$pageId}");

// بازخوانی بر اساس شناسه
$pById = PageBuilder::getPage($pageId);
assert_test($pById !== null && $pById['title'] === 'برگه آزمایشی کنترل کیفیت', "Page retrieved by numeric ID");
assert_test($pById['slug'] === $testSlug, "Page slug matches expected '{$testSlug}'");
assert_test((int)$pById['noindex'] === 1, "Page noindex is preserved");

// بازخوانی بر اساس اسلاگ
$pBySlug = PageBuilder::getPage($testSlug);
assert_test($pBySlug !== null && (int)$pBySlug['id'] === $pageId, "Page retrieved by slug");

// به‌روزرسانی محتوای طراحی‌شده با صفحه‌ساز دیداری
$sampleHtml = '<section class="test-sec"><h1>تیتر آزمایشی</h1><p>متن برگه</p></section>';
$sampleCss  = '.test-sec { background: #f0f0f0; }';
$sampleJson = json_encode(['components' => ['type' => 'text', 'content' => 'تیتر آزمایشی']]);

$saveContentRes = PageBuilder::saveBuilderContent($pageId, $sampleHtml, $sampleCss, $sampleJson);
assert_test($saveContentRes === true, "saveBuilderContent saved successfully");

$pUpdated = PageBuilder::getPage($pageId);
assert_test(str_contains((string)$pUpdated['content_html'], 'تیتر آزمایشی'), "content_html successfully stored and retrieved");
assert_test(str_contains((string)$pUpdated['content_css'], '.test-sec'), "content_css successfully stored and retrieved");
assert_test(!empty($pUpdated['builder_json']), "builder_json successfully stored and retrieved");

// تکثیر برگه (Duplicate)
$dupId = PageBuilder::duplicatePage($pageId);
assert_test($dupId > 0 && $dupId !== $pageId, "Page duplicated successfully to new ID {$dupId}");
$dupPage = PageBuilder::getPage($dupId);
assert_test(str_starts_with((string)$dupPage['title'], 'کپی برگه آزمایشی'), "Duplicated page title is prefixed with 'کپی'");
assert_test((int)$dupPage['active'] === 0, "Duplicated page is set to draft (active=0)");
assert_test((int)$dupPage['is_home'] === 0, "Duplicated page is never set as homepage");

// حذف برگه تکثیر شده
$delDupRes = PageBuilder::deletePage($dupId);
assert_test($delDupRes === true, "Duplicated page deleted successfully");
assert_test(PageBuilder::getPage($dupId) === null, "Deleted page no longer exists in database");

// بخش ۵: آزمون تنظیم و مدیریت صفحه اصلی سایت
echo "\n5. Testing Homepage Setting and Protection:\n";
$setHomeRes = PageBuilder::setHomepage($pageId);
assert_test($setHomeRes === true, "setHomepage successfully set page {$pageId} as homepage");

$activeHome = PageBuilder::getHomepage();
assert_test($activeHome !== null && (int)$activeHome['id'] === $pageId, "getHomepage returns page {$pageId}");

// آزمون محافظت از حذف صفحه اصلی فعال
$delHomeAttempt = PageBuilder::deletePage($pageId);
assert_test($delHomeAttempt === false, "deletePage refuses to delete the active homepage");
assert_test(PageBuilder::getPage($pageId) !== null, "Active homepage remains intact in database");

// بازگرداندن وضعیت صفحه اصلی به حالت پیش‌فرض و حذف برگه تستی
DB::update('site_pages', ['is_home' => 0], 'id=?', [$pageId]);
PageBuilder::deletePage($pageId);
assert_test(PageBuilder::getPage($pageId) === null, "Test page cleaned up after unsetting is_home");

// بخش ۶: کاتالوگ ویجت‌های اختصاصی سامانه
echo "\n6. Testing Dedicated Feria Ravan Widgets Catalog:\n";
$widgets = PageBuilder::getAvailableWidgets();
assert_test(is_array($widgets) && count($widgets) >= 12, "Widget catalog contains at least 12 dedicated widgets (count: " . count($widgets) . ")");

$expectedWidgetIds = [
    'tests', 'courses', 'workshops',
    'consult', 'newsletter', 'reviews',
    'trust_badges', 'hero', 'stats',
    'faq', 'features', 'contact_box'
];
$wIds = array_column($widgets, 'id');
foreach ($expectedWidgetIds as $ewId) {
    assert_test(in_array($ewId, $wIds, true), "Widget '{$ewId}' registered in catalog");
}

// بخش ۷: رندر واقعی ویجت‌ها و اتصال به داده‌های زنده دیتابیس
echo "\n7. Testing Live Database Widget Renderers:\n";

// ۷.۱ ویجت تست‌های روان‌شناختی
$testsHtml = PageBuilder::renderWidget('tests', ['limit' => 3, 'cols' => 3], false);
assert_test(is_string($testsHtml) && strlen($testsHtml) > 100, "renderWidget('tests') returns rendered HTML");
assert_test(str_contains($testsHtml, 'تست') || str_contains($testsHtml, 'سؤال'), "tests widget contains clinical test labels");

// ۷.۲ ویجت دوره‌های آموزشی
$coursesHtml = PageBuilder::renderWidget('courses', ['limit' => 2], false);
assert_test(is_string($coursesHtml) && strlen($coursesHtml) > 50, "renderWidget('courses') returns rendered HTML");

// ۷.۳ ویجت کارگاه‌ها
$workshopsHtml = PageBuilder::renderWidget('workshops', ['limit' => 2], false);
assert_test(is_string($workshopsHtml) && strlen($workshopsHtml) > 50, "renderWidget('workshops') returns rendered HTML");

// ۷.۴ ویجت نوبت‌دهی مشاوره
$consultHtml = PageBuilder::renderWidget('consult', [], false);
assert_test(is_string($consultHtml) && (str_contains($consultHtml, 'مشاوره') || str_contains($consultHtml, 'رزرو')), "renderWidget('consult') contains consultation booking info");

// ۷.۵ ویجت عضویت در خبرنامه
$newsletterHtml = PageBuilder::renderWidget('newsletter', [], false);
assert_test(str_contains($newsletterHtml, 'عضویت') && str_contains($newsletterHtml, 'mobile'), "renderWidget('newsletter') contains mobile subscription form");

// ۷.۶ ویجت نمادهای اعتماد و امنیت
$trustHtml = PageBuilder::renderWidget('trust_badges', [], false);
assert_test(str_contains($trustHtml, 'نماد') || str_contains($trustHtml, 'امنیت') || str_contains($trustHtml, 'badge'), "renderWidget('trust_badges') contains trust seals");

// ۷.۷ ویجت دیدگاه‌ها و رضایت مراجعین
$reviewsHtml = PageBuilder::renderWidget('reviews', [], false);
assert_test(str_contains($reviewsHtml, 'نظر') || str_contains($reviewsHtml, 'دیدگاه') || str_contains($reviewsHtml, 'ستاره'), "renderWidget('reviews') contains reviews section");

// ۷.۸ ویجت هیرو روان‌شناسی
$heroHtml = PageBuilder::renderWidget('hero', ['title' => 'عنوان آزمایشی هیرو'], false);
assert_test(str_contains($heroHtml, 'عنوان آزمایشی هیرو'), "renderWidget('hero') correctly uses dynamic parameters");

// ۷.۹ ویجت آمار
$statsHtml = PageBuilder::renderWidget('stats', [], false);
assert_test(str_contains($statsHtml, 'آمار') || str_contains($statsHtml, 'مراجع') || str_contains($statsHtml, 'ساعت'), "renderWidget('stats') contains statistics metrics");

// ۷.۱۰ ویجت سؤالات متداول (FAQ)
$faqHtml = PageBuilder::renderWidget('faq', [], false);
assert_test(str_contains($faqHtml, 'پرسش') || str_contains($faqHtml, 'سوالات متداول'), "renderWidget('faq') contains FAQ items");

// ۷.۱۱ ویجت ویژگی‌های کلیدی
$featuresHtml = PageBuilder::renderWidget('features', [], false);
assert_test(str_contains($featuresHtml, 'ویژگی') || str_contains($featuresHtml, 'تخصصی'), "renderWidget('features') contains key features");

// ۷.۱۲ ویجت جعبه تماس
$contactHtml = PageBuilder::renderWidget('contact_box', [], false);
assert_test(str_contains($contactHtml, 'تماس') || str_contains($contactHtml, 'پشتیبانی'), "renderWidget('contact_box') contains contact details");

// بخش ۸: آزمون حل‌کننده شورت‌کدها و بلوک‌های داده (Shortcode & Dynamic Block Resolver)
echo "\n8. Testing Shortcode and Dynamic Block Resolving:\n";
$inputShortcode = '<div class="content"><p>قبل از ویجت</p>[widget:tests limit="2"]<p>بعد از ویجت</p></div>';
$resolvedShortcode = PageBuilder::resolveShortcodes($inputShortcode, false);
assert_test(!str_contains($resolvedShortcode, '[widget:tests'), "Shortcode [widget:tests] was successfully replaced");
assert_test(str_contains($resolvedShortcode, 'قبل از ویجت'), "Pre-content preserved in shortcode resolution");
assert_test(str_contains($resolvedShortcode, 'بعد از ویجت'), "Post-content preserved in shortcode resolution");

$inputDataAttr = '<div data-fr-widget="workshops" data-params=\'{"limit":3}\'>محتوای پیش‌فرض ویرایشگر</div>';
$resolvedDataAttr = PageBuilder::resolveShortcodes($inputDataAttr, false);
assert_test(!str_contains($resolvedDataAttr, 'محتوای پیش‌فرض ویرایشگر'), "Dynamic data-fr-widget block was resolved with live content");

// بخش ۹: آزمون سطوح دسترسی و کنترل امنیتی
echo "\n9. Testing Admin Roles, Menus & Security Capabilities:\n";
$caps = fr_caps_catalog();
assert_test(isset($caps['pages']), "fr_caps_catalog() contains 'pages' capability");

$pageMap = fr_page_cap_map();
assert_test(isset($pageMap['pages.php']) && $pageMap['pages.php'] === 'pages', "fr_page_cap_map() maps 'pages.php' to 'pages'");
assert_test(isset($pageMap['page-builder.php']) && $pageMap['page-builder.php'] === 'pages', "fr_page_cap_map() maps 'page-builder.php' to 'pages'");

$superAdmin = ['id' => 1, 'role' => 'superadmin'];
$manager    = ['id' => 2, 'role' => 'manager'];
$support    = ['id' => 3, 'role' => 'support'];

assert_test(fr_admin_can($superAdmin, 'pages') === true, "Superadmin can manage pages");
assert_test(fr_admin_can($manager, 'pages') === true, "Manager can manage pages");
assert_test(fr_admin_can($support, 'pages') === false, "Support role is restricted from page builder");

// بخش ۱۰: آزمون نقشه سایت و قوانین پیوندهای یکتا
echo "\n10. Testing Permalinks Flush and Sitemap Integration:\n";
$flushRes = fr_permalinks_flush();
assert_test(is_array($flushRes) && $flushRes['count'] >= 0, "fr_permalinks_flush() executed successfully");
$htaccessContent = (string)@file_get_contents(FR_ROOT . '/.htaccess');
assert_test(str_contains($htaccessContent, '^p/([^/]+)/?$ page.php?slug=$1'), ".htaccess includes RewriteRule for custom pages (^p/)");
assert_test(str_contains($htaccessContent, '^page/([^/]+)/?$ page.php?slug=$1'), ".htaccess includes RewriteRule for custom pages (^page/)");

$sitemapUrls = fr_sitemap_urls();
assert_test(is_array($sitemapUrls) && count($sitemapUrls) > 0, "fr_sitemap_urls() returns non-empty URL list");

// بخش ۱۱: اعتبارسنجی قالب‌ها و وضعیت‌های نمایش عمومی
echo "\n11. Testing Public Rendering Modes and Increments:\n";
$tempPageId = PageBuilder::savePage([
    'name'        => 'برگه تستی رندر',
    'title'       => 'برگه تستی رندر',
    'slug'        => 'qa-test-render-' . time(),
    'layout'      => 'blank',
    'content_html'=> '<div class="qa-blank-box">محتوای خام برگه بدون سربرگ و فوتر</div>',
    'content_css' => '.qa-blank-box { color: red; }',
    'active'      => 1,
]);

$loadedTemp = PageBuilder::getPage($tempPageId);
$initialViews = (int)$loadedTemp['view_count'];

// شبیه‌سازی رندر با بافرینگ خروجی
ob_start();
PageBuilder::renderPage($loadedTemp, true);
$renderedOutput = ob_get_clean();

assert_test(str_contains($renderedOutput, 'محتوای خام برگه بدون سربرگ و فوتر'), "Page rendered HTML content cleanly");
assert_test(str_contains($renderedOutput, '.qa-blank-box { color: red; }'), "Page rendered custom CSS style block");

$updatedAfterView = PageBuilder::getPage($tempPageId);
assert_test((int)$updatedAfterView['view_count'] === $initialViews + 1, "view_count successfully incremented on public render");

// پاک‌سازی
PageBuilder::deletePage($tempPageId);

echo "\n========================================================\n";
echo "QA RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
