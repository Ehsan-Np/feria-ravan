<?php
/**
 * ============================================================================
 *  مجموعه آزمون‌های جامع فریا روان — نگارش ۳٫۷٫۰ (QA Test Suite v3.7.0)
 *  ─ اعتبارسنجی سیستم فهرست‌ساز وردپرسی (core/MenuBuilder.php و admin/menus.php)
 *  ─ آزمون ساخت و مدیریت منوهای چندسطحی، زیرمنوها و جایگاه‌های نمایش
 *  ─ آزمون طراحی قالب‌های سربرگ (Header) و پاورقی (Footer) دلخواه با صفحه‌ساز
 *  ─ آزمون انتصاب و رندر سراسری سربرگ/پاورقی پیش‌فرض در public_header/public_footer
 *  ─ آزمون ۱۲ ویجت اختصاصی هدر و فوتر و حل‌کننده شورت‌کدها
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
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/roles.php';
require_once FR_ROOT . '/includes/public-layout.php';
require_once FR_ROOT . '/core/MenuBuilder.php';
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

echo "=================================================================\n";
echo "   FERIA RAVAN v3.7.0 QA TEST SUITE: MENU BUILDER & HEADER/FOOTER\n";
echo "=================================================================\n\n";

// بخش ۱: نگارش ۳٫۷٫۰ و یکپارچگی فایل‌های هسته
echo "1. Testing Version & Architecture Integrity:\n";
assert_test(version_compare(FR_RELEASE, '3.7.0', '>='), "FR_RELEASE is 3.7.0 or higher (actual: " . FR_RELEASE . ")");
assert_test(version_compare(FR_VERSION, '3.7.0', '>='), "FR_VERSION is 3.7.0 or higher (actual: " . FR_VERSION . ")");
$configContent = file_get_contents(FR_ROOT . '/config.php');
assert_test(str_contains($configContent, '3.7.0'), "config.php references version 3.7.0");
$updateContent = file_get_contents(FR_ROOT . '/includes/update.php');
assert_test(str_contains($updateContent, '۳٫۷٫۰'), "includes/update.php contains 3.7.0 migration hook");

assert_test(file_exists(FR_ROOT . '/core/MenuBuilder.php'), "core/MenuBuilder.php exists");
assert_test(file_exists(FR_ROOT . '/core/PageBuilder.php'), "core/PageBuilder.php exists");
assert_test(file_exists(FR_ROOT . '/admin/menus.php'), "admin/menus.php exists");
assert_test(file_exists(FR_ROOT . '/admin/pages.php'), "admin/pages.php exists");
assert_test(file_exists(FR_ROOT . '/database/migrations/v370_menu_builder_and_header_footer.sql'), "database/migrations/v370 migration SQL file exists");

// بخش ۲: بررسی خودترمیمی اسکیما و جداول فهرست‌ها
echo "\n2. Testing MenuBuilder Database Schema & Self-Healing:\n";
MenuBuilder::ensureSchema();

$tblMenus = MenuBuilder::tableMenus();
$colsMenus = array_column(DB::all("SHOW COLUMNS FROM {$tblMenus}"), 'Field');
$expectedMenuCols = ['id', 'title', 'slug', 'location', 'created_at', 'updated_at'];
foreach ($expectedMenuCols as $c) {
    assert_test(in_array($c, $colsMenus, true), "Column '{$c}' exists in table {$tblMenus}");
}

$tblItems = MenuBuilder::tableMenuItems();
$colsItems = array_column(DB::all("SHOW COLUMNS FROM {$tblItems}"), 'Field');
$expectedItemCols = ['id', 'menu_id', 'parent_id', 'title', 'url', 'target', 'icon', 'badge', 'badge_color', 'type', 'type_id', 'css_class', 'sort_order', 'created_at', 'updated_at'];
foreach ($expectedItemCols as $c) {
    assert_test(in_array($c, $colsItems, true), "Column '{$c}' exists in table {$tblItems}");
}

// بررسی بذر اولیه منوهای پیش‌فرض
$defaultPrimary = MenuBuilder::getMenu('primary');
assert_test($defaultPrimary !== null, "Default Primary Header menu seeded automatically");
$defaultFooter = MenuBuilder::getMenu('footer');
assert_test($defaultFooter !== null, "Default Footer menu seeded automatically");

// بخش ۳: آزمون چرخه CRUD فهرست‌ها و مدیریت جایگاه‌ها (Locations)
echo "\n3. Testing Menu Lifecycle & Display Locations:\n";
$locs = MenuBuilder::getLocations();
assert_test(isset($locs['primary']) && isset($locs['footer']) && isset($locs['mobile']), "MenuBuilder supports primary, footer and mobile locations");

$testMenuSlug = 'qa-menu-' . time();
$testMenuId = MenuBuilder::saveMenu([
    'title'    => 'فهرست آزمایشی ناوبری',
    'slug'     => $testMenuSlug,
    'location' => 'topbar',
]);
assert_test($testMenuId > 0, "Created new test menu with ID {$testMenuId}");

$fetchedMenu = MenuBuilder::getMenu($testMenuId);
assert_test($fetchedMenu !== null && $fetchedMenu['slug'] === $testMenuSlug, "Menu fetched by numeric ID");

$fetchedBySlug = MenuBuilder::getMenu($testMenuSlug);
assert_test($fetchedBySlug !== null && (int)$fetchedBySlug['id'] === $testMenuId, "Menu fetched by slug");

$fetchedByLoc = MenuBuilder::getMenu('topbar');
assert_test($fetchedByLoc !== null && (int)$fetchedByLoc['id'] === $testMenuId, "Menu fetched by assigned location");

// انحصار جایگاه: انتصاب جایگاه topbar به منوی دیگر نباید باعث هم‌پوشانی شود
$testMenu2Id = MenuBuilder::saveMenu([
    'title'    => 'فهرست آزمایشی شماره ۲',
    'slug'     => $testMenuSlug . '-2',
    'location' => 'topbar',
]);
$oldMenuUpdated = MenuBuilder::getMenu($testMenuId);
assert_test(empty($oldMenuUpdated['location']), "Setting location on new menu cleared old assignment");
MenuBuilder::deleteMenu($testMenu2Id);

// بخش ۴: آزمون ساخت آیتم‌ها، زیرمنوهای چندسطحی و ترتیب
echo "\n4. Testing Menu Items, Multi-Level Sub-menus & Ordering:\n";
// آیتم والد ۱
$itemId1 = MenuBuilder::saveMenuItem([
    'menu_id'   => $testMenuId,
    'parent_id' => 0,
    'title'     => 'صفحه خدمات',
    'url'       => '/services',
    'icon'      => '💼',
    'target'    => '_self',
    'sort_order'=> 10,
]);
assert_test($itemId1 > 0, "Created top-level menu item #1 (ID: {$itemId1})");

// آیتم والد ۲
$itemId2 = MenuBuilder::saveMenuItem([
    'menu_id'   => $testMenuId,
    'parent_id' => 0,
    'title'     => 'آزمون‌ها',
    'url'       => '/tests.php',
    'icon'      => '🧠',
    'badge'     => 'جدید',
    'badge_color' => 'success',
    'sort_order'=> 20,
]);
assert_test($itemId2 > 0, "Created top-level menu item #2 (ID: {$itemId2})");

// زیرمنو ۱ (فرزند آیتم ۲)
$subId1 = MenuBuilder::saveMenuItem([
    'menu_id'   => $testMenuId,
    'parent_id' => $itemId2,
    'title'     => 'تست دیسک',
    'url'       => '/t/disc',
    'icon'      => '🎭',
    'sort_order'=> 5,
]);
assert_test($subId1 > 0, "Created nested sub-item (parent_id: {$itemId2})");

// زیرمنو ۲ (فرزند آیتم ۲)
$subId2 = MenuBuilder::saveMenuItem([
    'menu_id'   => $testMenuId,
    'parent_id' => $itemId2,
    'title'     => 'تست نئو',
    'url'       => '/t/neo',
    'icon'      => '🌟',
    'sort_order'=> 10,
]);
assert_test($subId2 > 0, "Created second nested sub-item");

// واکشی به صورت درختی
$nestedItems = MenuBuilder::getMenuItems($testMenuId, true);
assert_test(count($nestedItems) === 2, "Root items count in tree is 2");
$secondNode = array_values(array_filter($nestedItems, fn($it) => (int)$it['id'] === $itemId2))[0] ?? null;
assert_test($secondNode !== null && isset($secondNode['children']) && count($secondNode['children']) === 2, "Second root node correctly contains 2 child items");

// تغییر سلسله‌مراتب و ترتیب گروهی
$reorderRes = MenuBuilder::updateItemsHierarchy($testMenuId, [
    ['id' => $itemId2, 'parent_id' => 0],
    ['id' => $itemId1, 'parent_id' => 0],
]);
assert_test($reorderRes === true, "updateItemsHierarchy updated sort order successfully");

// بخش ۵: آزمون رندر خروجی HTML منو با پشتیبانی از Dropdown
echo "\n5. Testing HTML Navigation Menu Rendering:\n";
$menuHtml = MenuBuilder::renderMenu($testMenuId, ['class' => 'qa-custom-nav']);
assert_test(str_contains($menuHtml, 'qa-custom-nav'), "Rendered menu contains custom nav class");
assert_test(str_contains($menuHtml, 'has-children'), "Rendered menu includes 'has-children' class on parent node");
assert_test(str_contains($menuHtml, 'fr-submenu-list'), "Rendered menu includes 'fr-submenu-list' wrapper for dropdown");
assert_test(str_contains($menuHtml, 'تست دیسک') && str_contains($menuHtml, 'تست نئو'), "Dropdown contains child links");
assert_test(str_contains($menuHtml, 'جدید'), "Badge text rendered successfully");

// حذف آیتم و پاک‌سازی منوی تستی
MenuBuilder::deleteMenuItem($itemId2); // باید فرزندان را به والد منتقل کند یا پاک کند
MenuBuilder::deleteMenu($testMenuId);
assert_test(MenuBuilder::getMenu($testMenuId) === null, "Test menu and items deleted cleanly");

// بخش ۶: آزمون خودترمیمی جدول برگه‌ها برای نوع‌های Header و Footer
echo "\n6. Testing PageBuilder Header & Footer Schema Extension:\n";
PageBuilder::ensureSchema();
$tblPages = PageBuilder::table();
$colsPages = array_column(DB::all("SHOW COLUMNS FROM {$tblPages}"), 'Field');
assert_test(in_array('page_type', $colsPages, true), "Column 'page_type' exists in table {$tblPages}");
assert_test(in_array('is_default', $colsPages, true), "Column 'is_default' exists in table {$tblPages}");

// بخش ۷: آزمون ساخت و مدیریت قالب‌های اختصاصی سربرگ و پاورقی
echo "\n7. Testing Custom Header & Footer Templates Lifecycle:\n";
$customHeaderId = PageBuilder::savePage([
    'name'        => 'سربرگ تست کیفیت',
    'title'       => 'سربرگ اختصاصی کنترل کیفیت',
    'slug'        => 'qa-custom-header-' . time(),
    'page_type'   => 'header',
    'active'      => 1,
    'is_default'  => 0,
    'content_html'=> '<header class="qa-header"><div data-widget="site_logo"></div><div data-widget="header_cta"></div></header>',
    'content_css' => '.qa-header { background: #001122; color: #ffffff; }',
]);
assert_test($customHeaderId > 0, "Created custom header template with ID {$customHeaderId}");

$customFooterId = PageBuilder::savePage([
    'name'        => 'پاورقی تست کیفیت',
    'title'       => 'پاورقی اختصاصی کنترل کیفیت',
    'slug'        => 'qa-custom-footer-' . time(),
    'page_type'   => 'footer',
    'active'      => 1,
    'is_default'  => 0,
    'content_html'=> '<footer class="qa-footer"><div data-widget="copyright"></div></footer>',
    'content_css' => '.qa-footer { background: #110022; color: #ffffff; }',
]);
assert_test($customFooterId > 0, "Created custom footer template with ID {$customFooterId}");

// آزمون واکشی قالب‌ها بر اساس page_type
$headersList = PageBuilder::getTemplates('header');
assert_test(count(array_filter($headersList, fn($h) => (int)$h['id'] === $customHeaderId)) === 1, "getTemplates('header') retrieves newly created header template");

$footersList = PageBuilder::getTemplates('footer');
assert_test(count(array_filter($footersList, fn($f) => (int)$f['id'] === $customFooterId)) === 1, "getTemplates('footer') retrieves newly created footer template");

// آزمون فعال‌سازی به عنوان سربرگ پیش‌فرض سایت
$setHDefRes = PageBuilder::setActiveTemplate($customHeaderId, 'header');
assert_test($setHDefRes === true, "setActiveTemplate set header as active default");
$activeH = PageBuilder::getActiveHeader();
assert_test($activeH !== null && (int)$activeH['id'] === $customHeaderId, "getActiveHeader returns correct active header template");

// آزمون فعال‌سازی به عنوان پاورقی پیش‌فرض سایت
$setFDefRes = PageBuilder::setActiveTemplate($customFooterId, 'footer');
assert_test($setFDefRes === true, "setActiveTemplate set footer as active default");
$activeF = PageBuilder::getActiveFooter();
assert_test($activeF !== null && (int)$activeF['id'] === $customFooterId, "getActiveFooter returns correct active footer template");

// بخش ۸: آزمون ۱۲ ویجت اختصاصی سربرگ و پاورقی
echo "\n8. Testing Dedicated Header & Footer Widgets:\n";
$hWidgets = [
    'site_logo', 'menu', 'user_auth', 'header_cta', 'search_bar', 'topbar_contact',
    'footer_brand', 'footer_menu', 'footer_contact', 'social_icons', 'footer_badges', 'copyright'
];

foreach ($hWidgets as $hw) {
    $rendered = PageBuilder::renderWidget($hw, [], false);
    assert_test(is_string($rendered) && strlen($rendered) > 20, "renderWidget('{$hw}') produced valid rendered HTML output");
}

// بررسی محتوای ویجت‌های کلیدی
$logoHtml = PageBuilder::renderWidget('site_logo', [], false);
assert_test(str_contains($logoHtml, 'fr-widget-logo'), "site_logo widget contains fr-widget-logo class");

$authHtml = PageBuilder::renderWidget('user_auth', [], false);
assert_test(str_contains($authHtml, 'fr-widget-auth-box'), "user_auth widget contains fr-widget-auth-box class");

$ctaHtml = PageBuilder::renderWidget('header_cta', ['text' => 'نوبت آنلاین'], false);
assert_test(str_contains($ctaHtml, 'نوبت آنلاین'), "header_cta accepts custom dynamic text");

$copyHtml = PageBuilder::renderWidget('copyright', [], false);
assert_test(str_contains($copyHtml, 'حقوق') || str_contains($copyHtml, '©'), "copyright widget renders copyright notice");

// بخش ۹: آزمون حل‌کننده شورت‌کدها برای ویجت‌های سربرگ و پاورقی
echo "\n9. Testing Shortcode Resolution for Header & Footer Widgets:\n";
$tplInput = '<div class="custom-bar">[widget:site_logo][widget:header_cta text="تماس فوری"]</div>';
$tplResolved = PageBuilder::resolveWidgets($tplInput, false);
assert_test(!str_contains($tplResolved, '[widget:site_logo]'), "[widget:site_logo] resolved successfully");
assert_test(str_contains($tplResolved, 'تماس فوری'), "[widget:header_cta] resolved with custom parameter");

// بخش ۱۰: آزمون یکپارچگی رندر عمومی در public_header و public_footer
echo "\n10. Testing Public Header & Footer Override Integration:\n";
ob_start();
public_header([], 'تست هدر');
$headerOutput = ob_get_clean();

assert_test(str_contains($headerOutput, 'qa-header'), "public_header() cleanly rendered the active custom header template");
assert_test(str_contains($headerOutput, '.qa-header { background: #001122;'), "public_header() injected custom header CSS block");

ob_start();
public_footer();
$footerOutput = ob_get_clean();

assert_test(str_contains($footerOutput, 'qa-footer'), "public_footer() cleanly rendered the active custom footer template");
assert_test(str_contains($footerOutput, '.qa-footer { background: #110022;'), "public_footer() injected custom footer CSS block");

// آزمون دور زدن سربرگ سفارشی در صورت درخواست قالب کلاسیک (?classic=1)
$_GET['classic'] = '1';
ob_start();
public_header([], 'تست هدر کلاسیک');
$classicHeaderOut = ob_get_clean();
assert_test(str_contains($classicHeaderOut, 'class="pb-nav"'), "Passing ?classic=1 cleanly falls back to default classic navigation");
unset($_GET['classic']);

// بازگرداندن وضعیت پیش‌فرض و پاک‌سازی قالب‌های تستی
PageBuilder::unsetActiveTemplate($customHeaderId);
PageBuilder::unsetActiveTemplate($customFooterId);
PageBuilder::deletePage($customHeaderId);
PageBuilder::deletePage($customFooterId);
assert_test(PageBuilder::getActiveHeader() === null, "Active custom header cleanly unset");
assert_test(PageBuilder::getActiveFooter() === null, "Active custom footer cleanly unset");

// بخش ۱۱: آزمون کنترل دسترسی و نگاشت منو در پنل ادمین
echo "\n11. Testing Admin Menu Permissions & Role Capabilities:\n";
$pageMap = fr_page_cap_map();
assert_test(isset($pageMap['menus.php']) && $pageMap['menus.php'] === 'pages', "fr_page_cap_map() maps 'menus.php' to 'pages' capability");

$superAdmin = ['id' => 1, 'role' => 'superadmin'];
$manager    = ['id' => 2, 'role' => 'manager'];
$support    = ['id' => 3, 'role' => 'support'];

assert_test(fr_admin_can($superAdmin, 'pages') === true, "Superadmin has access to Menu Builder");
assert_test(fr_admin_can($manager, 'pages') === true, "Manager has access to Menu Builder");
assert_test(fr_admin_can($support, 'pages') === false, "Support role is restricted from Menu Builder");

echo "\n=================================================================\n";
echo "QA RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
