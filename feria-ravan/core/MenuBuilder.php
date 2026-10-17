<?php
/**
 * ============================================================================
 *  موتور مدیریت فهرست‌ها و منوهای ناوبری فریا روان (MenuBuilder Engine)
 *  ─ نگارش ۳٫۷٫۰: الهام‌گرفته از سیستم منوهای وردپرس (WordPress Menus)
 *  ─ مدیریت نامحدود فهرست‌ها (Header, Footer, Mobile, Quick Links)
 *  ─ پشتیبانی از زیرمنوهای چندسطحی (Multi-Level Sub-menus & Dropdowns)
 *  ─ افزودن آسان برگه‌ها، تست‌های روانشناسی، دوره‌ها، کارگاه‌ها و مشاوره
 *  ─ رندر واکنش‌گرا (Responsive) با آیکون، نشان (Badge) و وضعیت فعال
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

class MenuBuilder
{
    /**
     * نام جدول فهرست‌ها
     */
    public static function tableMenus(): string
    {
        return DB::t('menus');
    }

    /**
     * نام جدول آیتم‌های فهرست
     */
    public static function tableMenuItems(): string
    {
        return DB::t('menu_items');
    }

    /**
     * خودترمیمی و مهاجرت اسکیما برای جداول فهرست‌ها
     */
    public static function ensureSchema(): void
    {
        // ۱. ایجاد جدول fr_menus
        DB::run("CREATE TABLE IF NOT EXISTS " . self::tableMenus() . " (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(190) NOT NULL,
            `slug` VARCHAR(190) NOT NULL UNIQUE,
            `location` VARCHAR(50) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `ix_location` (`location`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // ۲. ایجاد جدول fr_menu_items
        DB::run("CREATE TABLE IF NOT EXISTS " . self::tableMenuItems() . " (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `menu_id` INT UNSIGNED NOT NULL,
            `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `title` VARCHAR(190) NOT NULL,
            `url` VARCHAR(500) NOT NULL,
            `target` VARCHAR(20) NOT NULL DEFAULT '_self',
            `icon` VARCHAR(60) NULL,
            `badge` VARCHAR(50) NULL,
            `badge_color` VARCHAR(30) NULL,
            `type` VARCHAR(30) NOT NULL DEFAULT 'custom',
            `type_id` INT UNSIGNED NULL,
            `css_class` VARCHAR(100) NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `ix_menu` (`menu_id`),
            KEY `ix_parent` (`parent_id`),
            KEY `ix_sort` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // بذر اولیه فهرست‌ها در صورت خالی بودن
        $count = (int)DB::val("SELECT COUNT(*) FROM " . self::tableMenus());
        if ($count === 0) {
            self::seedDefaultMenus();
        }
    }

    /**
     * بذر اولیه‌ی منوهای پیش‌فرض (منوی اصلی سربرگ و فهرست پاورقی)
     */
    public static function seedDefaultMenus(): void
    {
        // ۱. منوی اصلی سربرگ (Primary Header Menu)
        $headerMenuId = (int)DB::insert('menus', [
            'title'    => 'منوی اصلی سربرگ',
            'slug'     => 'primary-menu',
            'location' => 'primary',
        ]);

        if ($headerMenuId > 0) {
            $items = [
                ['title' => 'صفحه اصلی', 'url' => '/', 'icon' => '🏠', 'sort_order' => 10],
                ['title' => 'تست‌های روان‌شناسی', 'url' => '/tests.php', 'icon' => '🧠', 'badge' => 'ویژه', 'badge_color' => 'primary', 'sort_order' => 20],
                ['title' => 'کارگاه‌ها', 'url' => '/workshops.php', 'icon' => '🛠', 'sort_order' => 30],
                ['title' => 'دوره‌های آموزشی', 'url' => '/courses.php', 'icon' => '📚', 'sort_order' => 40],
                ['title' => 'رزرو وقت مشاوره', 'url' => '/consult.php', 'icon' => '🛋', 'badge' => 'آنلاین', 'badge_color' => 'success', 'sort_order' => 50],
            ];

            foreach ($items as $it) {
                $parentId = (int)DB::insert('menu_items', array_merge([
                    'menu_id'   => $headerMenuId,
                    'parent_id' => 0,
                    'target'    => '_self',
                    'type'      => 'custom',
                ], $it));

                // افزودن چند زیرمنوی کاربردی برای تست‌های روان‌شناسی
                if ($it['url'] === '/tests.php' && $parentId > 0) {
                    $subItems = [
                        ['title' => 'تست شخصیت‌شناسی DISC', 'url' => '/t/disc', 'icon' => '🎭', 'sort_order' => 1],
                        ['title' => 'تست شخصیت NEO پنج عاملی', 'url' => '/t/neo', 'icon' => '🌟', 'sort_order' => 2],
                        ['title' => 'تست هوش هیجانی بار-آن (EQ)', 'url' => '/t/baron-eq', 'icon' => '💡', 'sort_order' => 3],
                        ['title' => 'غربالگری اضطراب و استرس', 'url' => '/t/anxiety', 'icon' => '🩺', 'badge' => 'رایگان', 'badge_color' => 'success', 'sort_order' => 4],
                    ];
                    foreach ($subItems as $sub) {
                        DB::insert('menu_items', array_merge([
                            'menu_id'   => $headerMenuId,
                            'parent_id' => $parentId,
                            'target'    => '_self',
                            'type'      => 'custom',
                        ], $sub));
                    }
                }
            }
        }

        // ۲. منوی پاورقی (Footer Quick Links Menu)
        $footerMenuId = (int)DB::insert('menus', [
            'title'    => 'دسترسی سریع پاورقی',
            'slug'     => 'footer-links',
            'location' => 'footer',
        ]);

        if ($footerMenuId > 0) {
            $footerItems = [
                ['title' => 'تست‌های پرطرفدار', 'url' => '/tests.php', 'sort_order' => 10],
                ['title' => 'خدمات مشاوره فردی', 'url' => '/consult.php', 'sort_order' => 20],
                ['title' => 'کارگاه‌های تخصصی', 'url' => '/workshops.php', 'sort_order' => 30],
                ['title' => 'عضویت در خبرنامه', 'url' => '/newsletter.php', 'sort_order' => 40],
                ['title' => 'درباره ما', 'url' => '/page.php?slug=about-us', 'sort_order' => 50],
            ];
            foreach ($footerItems as $fit) {
                DB::insert('menu_items', array_merge([
                    'menu_id'   => $footerMenuId,
                    'parent_id' => 0,
                    'target'    => '_self',
                    'type'      => 'custom',
                ], $fit));
            }
        }
    }

    /**
     * جایگاه‌های تعریف‌شده برای نمایش فهرست در قالب
     */
    public static function getLocations(): array
    {
        return [
            'primary' => 'منوی اصلی سربرگ (Header)',
            'footer'  => 'منوی پاورقی (Footer)',
            'mobile'  => 'منوی کشویی موبایل (Drawer)',
            'topbar'  => 'نوار دسترسی بالای سایت (Top Bar)',
        ];
    }

    /**
     * فهرست تمام منوها
     */
    public static function getMenus(): array
    {
        self::ensureSchema();
        $menus = DB::all("SELECT * FROM " . self::tableMenus() . " ORDER BY id ASC");
        foreach ($menus as &$m) {
            $m['item_count'] = (int)DB::val("SELECT COUNT(*) FROM " . self::tableMenuItems() . " WHERE menu_id=?", [(int)$m['id']]);
        }
        return $menus;
    }

    /**
     * دریافت یک منو بر اساس شناسه، اسلاگ، یا جایگاه نمایش (Location)
     */
    public static function getMenu(int|string $idOrSlugOrLocation): ?array
    {
        self::ensureSchema();
        if (is_numeric($idOrSlugOrLocation) && (int)$idOrSlugOrLocation > 0) {
            $m = DB::one("SELECT * FROM " . self::tableMenus() . " WHERE id=?", [(int)$idOrSlugOrLocation]);
        } else {
            $term = (string)$idOrSlugOrLocation;
            // ۱. ابتدا جستجو بر اساس جایگاه (Location)
            $m = DB::one("SELECT * FROM " . self::tableMenus() . " WHERE location=? LIMIT 1", [$term]);
            if (!$m) {
                // ۲. جستجو بر اساس اسلاگ
                $m = DB::one("SELECT * FROM " . self::tableMenus() . " WHERE slug=? LIMIT 1", [$term]);
            }
        }
        return $m ?: null;
    }

    /**
     * ایجاد یا به‌روزرسانی منو
     */
    public static function saveMenu(array $data, ?int $id = null): int
    {
        self::ensureSchema();
        $title = trim((string)($data['title'] ?? 'فهرست جدید'));
        $slug  = trim((string)($data['slug'] ?? ''));
        $slug  = preg_replace('/[^\p{L}\p{N}_\-]+/u', '-', mb_strtolower($slug));
        $slug  = trim($slug, '-');
        if ($slug === '') {
            $slug = 'menu-' . time();
        }

        $location = trim((string)($data['location'] ?? ''));
        if ($location === '') $location = null;

        // اگر جایگاهی انتخاب شده باشد، جایگاه منوهای دیگر با این نام را خالی می‌کنیم
        if ($location) {
            if ($id && $id > 0) {
                DB::run("UPDATE " . self::tableMenus() . " SET location=NULL WHERE location=? AND id!=?", [$location, $id]);
            } else {
                DB::run("UPDATE " . self::tableMenus() . " SET location=NULL WHERE location=?", [$location]);
            }
        }

        $record = [
            'title'    => $title,
            'slug'     => $slug,
            'location' => $location,
        ];

        if ($id && $id > 0) {
            $exists = DB::one("SELECT id FROM " . self::tableMenus() . " WHERE slug=? AND id!=?", [$slug, $id]);
            if ($exists) {
                $record['slug'] = $slug . '-' . $id;
            }
            DB::update('menus', $record, 'id=?', [$id]);
            return $id;
        } else {
            $exists = DB::one("SELECT id FROM " . self::tableMenus() . " WHERE slug=?", [$slug]);
            if ($exists) {
                $record['slug'] = $slug . '-' . time();
            }
            return (int)DB::insert('menus', $record);
        }
    }

    /**
     * حذف یک منو و تمام آیتم‌های آن
     */
    public static function deleteMenu(int $id): bool
    {
        self::ensureSchema();
        DB::run("DELETE FROM " . self::tableMenuItems() . " WHERE menu_id=?", [$id]);
        DB::run("DELETE FROM " . self::tableMenus() . " WHERE id=?", [$id]);
        return true;
    }

    /**
     * واکشی آیتم‌های یک منو (به صورت فلت یا ساختار درختی چندسطحی)
     */
    public static function getMenuItems(int $menuId, bool $nested = false): array
    {
        self::ensureSchema();
        $items = DB::all("SELECT * FROM " . self::tableMenuItems() . " WHERE menu_id=? ORDER BY sort_order ASC, id ASC", [$menuId]);
        if (!$nested) {
            return $items;
        }

        // تبدیل به ساختار درختی درایه با کلید children
        $tree = [];
        $byParent = [];
        foreach ($items as $it) {
            $byParent[(int)$it['parent_id']][] = $it;
        }

        $buildSub = function(int $parentId) use (&$buildSub, $byParent) {
            $branch = [];
            if (!empty($byParent[$parentId])) {
                foreach ($byParent[$parentId] as $node) {
                    $node['children'] = $buildSub((int)$node['id']);
                    $branch[] = $node;
                }
            }
            return $branch;
        };

        return $buildSub(0);
    }

    /**
     * ذخیره یا به‌روزرسانی آیتم منو
     */
    public static function saveMenuItem(array $data, ?int $id = null): int
    {
        self::ensureSchema();
        $menuId   = (int)($data['menu_id'] ?? 0);
        $parentId = (int)($data['parent_id'] ?? 0);
        $title    = trim((string)($data['title'] ?? 'پیوند'));
        $url      = trim((string)($data['url'] ?? '#'));
        $target   = (isset($data['target']) && in_array($data['target'], ['_self', '_blank'], true)) ? $data['target'] : '_self';
        $icon     = trim((string)($data['icon'] ?? '')) ?: null;
        $badge    = trim((string)($data['badge'] ?? '')) ?: null;
        $badgeCol = trim((string)($data['badge_color'] ?? 'primary')) ?: 'primary';
        $type     = trim((string)($data['type'] ?? 'custom'));
        $typeId   = !empty($data['type_id']) ? (int)$data['type_id'] : null;
        $cssClass = trim((string)($data['css_class'] ?? '')) ?: null;
        $sort     = (int)($data['sort_order'] ?? 0);

        if ($sort === 0 && (!$id || $id <= 0)) {
            $maxSort = (int)DB::val("SELECT MAX(sort_order) FROM " . self::tableMenuItems() . " WHERE menu_id=?", [$menuId]);
            $sort = $maxSort + 10;
        }

        $record = [
            'menu_id'     => $menuId,
            'parent_id'   => $parentId,
            'title'       => $title,
            'url'         => $url,
            'target'      => $target,
            'icon'        => $icon,
            'badge'       => $badge,
            'badge_color' => $badgeCol,
            'type'        => $type,
            'type_id'     => $typeId,
            'css_class'   => $cssClass,
            'sort_order'  => $sort,
        ];

        if ($id && $id > 0) {
            DB::update('menu_items', $record, 'id=?', [$id]);
            return $id;
        } else {
            return (int)DB::insert('menu_items', $record);
        }
    }

    /**
     * حذف یک آیتم از فهرست
     */
    public static function deleteMenuItem(int $id): bool
    {
        self::ensureSchema();
        $item = DB::one("SELECT * FROM " . self::tableMenuItems() . " WHERE id=?", [$id]);
        if (!$item) return false;

        $parentId = (int)$item['parent_id'];
        // ارتقای فرزندان به سطح والد برای جلوگیری از سرگردانی در درخت
        DB::run("UPDATE " . self::tableMenuItems() . " SET parent_id=? WHERE parent_id=?", [$parentId, $id]);
        DB::run("DELETE FROM " . self::tableMenuItems() . " WHERE id=?", [$id]);
        return true;
    }

    /**
     * به‌روزرسانی گروهی ترتیب و ساختار درختی آیتم‌ها
     */
    public static function updateItemsHierarchy(int $menuId, array $itemsHierarchy): bool
    {
        self::ensureSchema();
        $sort = 10;
        foreach ($itemsHierarchy as $it) {
            $itemId   = (int)($it['id'] ?? 0);
            $parentId = (int)($it['parent_id'] ?? 0);
            if ($itemId > 0) {
                DB::update('menu_items', [
                    'parent_id'  => $parentId,
                    'sort_order' => $sort,
                ], 'id=? AND menu_id=?', [$itemId, $menuId]);
                $sort += 10;
            }
        }
        return true;
    }

    /**
     * رندر کامل و شکیل منوی ناوبری با پشتیبانی از زیرمنوهای آبشاری (Dropdown)
     */
    public static function renderMenu(int|string $menuIdOrLocation, array $opts = []): string
    {
        $menu = self::getMenu($menuIdOrLocation);
        if (!$menu) {
            return '';
        }

        $items = self::getMenuItems((int)$menu['id'], true);
        if (empty($items)) {
            return '';
        }

        $customClass = (string)($opts['class'] ?? 'fr-nav-menu');
        $idAttr      = (string)($opts['id'] ?? 'frNavMenu_' . $menu['id']);
        $currentUri  = (string)($_SERVER['REQUEST_URI'] ?? '/');

        $out = '<nav class="' . e($customClass) . '" id="' . e($idAttr) . '" aria-label="' . e($menu['title']) . '">';
        $out .= '<ul class="fr-menu-list">';

        foreach ($items as $item) {
            $out .= self::renderMenuItemHtml($item, $currentUri);
        }

        $out .= '</ul>';
        $out .= '</nav>';

        return $out;
    }

    /**
     * رندر هر گره و فرزندان آن به صورت بازگشتی در HTML
     */
    private static function renderMenuItemHtml(array $item, string $currentUri): string
    {
        $hasChildren = !empty($item['children']);
        $url = $item['url'];
        if (!preg_match('#^(https?:)?//#i', $url) && !str_starts_with($url, '/')) {
            $url = '/' . ltrim($url, '/');
        }
        $fullUrl = (preg_match('#^(https?:)?//#i', $url)) ? $url : rtrim(SITE_URL, '/') . $url;

        $isActive = ($currentUri === $url || ($url !== '/' && str_starts_with($currentUri, $url)));
        $liClasses = ['fr-menu-item'];
        if ($hasChildren) $liClasses[] = 'has-children';
        if ($isActive) $liClasses[] = 'active';
        if (!empty($item['css_class'])) $liClasses[] = $item['css_class'];

        $html = '<li class="' . implode(' ', array_map('e', $liClasses)) . '">';
        $html .= '<a href="' . e($fullUrl) . '" target="' . e($item['target']) . '" class="fr-menu-link' . ($isActive ? ' on' : '') . '">';

        if (!empty($item['icon'])) {
            $html .= '<span class="fr-menu-icon" style="margin-left:5px">' . e($item['icon']) . '</span> ';
        }

        $html .= '<span class="fr-menu-title">' . e($item['title']) . '</span>';

        if (!empty($item['badge'])) {
            $bCol = $item['badge_color'] ?: 'primary';
            $badgeBg = ($bCol === 'success') ? '#10b981' : (($bCol === 'danger') ? '#ef4444' : '#3b82f6');
            $html .= ' <span class="fr-menu-badge" style="font-size:10px;padding:2px 6px;border-radius:6px;background:' . $badgeBg . ';color:#fff;margin-right:4px;font-weight:700">' . e($item['badge']) . '</span>';
        }

        if ($hasChildren) {
            $html .= ' <span class="fr-menu-arrow" style="font-size:9px;margin-right:4px;opacity:0.7">▾</span>';
        }

        $html .= '</a>';

        if ($hasChildren) {
            $html .= '<ul class="fr-submenu-list">';
            foreach ($item['children'] as $child) {
                $html .= self::renderMenuItemHtml($child, $currentUri);
            }
            $html .= '</ul>';
        }

        $html .= '</li>';

        return $html;
    }
}
