<?php
/**
 * ============================================================================
 *  موتور برگه‌ها و صفحه‌ساز بصری فریا روان (Visual PageBuilder Engine)
 *  ─ نگارش ۳٫۶٫۰: مدیریت کامل چیدمان صفحه اصلی و برگه‌های دلخواه
 *  ─ یکپارچه‌سازی با معماری PHPageBuilder و ویرایشگر بصری GrapesJS
 *  ─ ویجت‌های اختصاصی: تست‌ها، دوره‌ها، کارگاه‌ها، مشاوره، خبرنامه، دیدگاه‌ها و...
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/MenuBuilder.php';

class PageBuilder
{
    /**
     * نام جدول برگه‌ها در دیتابیس
     */
    public static function rawTableName(): string
    {
        return 'site_pages';
    }

    public static function table(): string
    {
        return DB::t('site_pages');
    }

    /**
     * اطمینان از وجود ساختار دیتابیس و ستون‌های صفحه‌ساز (Self-Healing Schema)
     */
    public static function ensureSchema(): void
    {
        static $ensured = false;
        if ($ensured) return;
        $ensured = true;

        MenuBuilder::ensureSchema();

        // ۱. ایجاد جدول در صورت عدم وجود
        DB::run("CREATE TABLE IF NOT EXISTS " . self::table() . " (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(190) NULL,
            `title` VARCHAR(190) NOT NULL,
            `slug` VARCHAR(190) NOT NULL UNIQUE,
            `layout` VARCHAR(50) NOT NULL DEFAULT 'default',
            `page_type` VARCHAR(20) NOT NULL DEFAULT 'page',
            `seo_title` VARCHAR(190) NULL,
            `meta_desc` VARCHAR(300) NULL,
            `noindex` TINYINT(1) NOT NULL DEFAULT 0,
            `is_home` TINYINT(1) NOT NULL DEFAULT 0,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `content_html` LONGTEXT NULL,
            `content_css` MEDIUMTEXT NULL,
            `builder_json` LONGTEXT NULL,
            `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_slug` (`slug`),
            INDEX `idx_home` (`is_home`),
            INDEX `idx_active` (`active`),
            INDEX `idx_page_type` (`page_type`),
            INDEX `idx_is_default` (`is_default`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ۲. اطمینان از وجود ستون‌های مورد نیاز صفحه‌ساز
        $cols = [
            'name'         => "VARCHAR(190) NULL AFTER `id`",
            'layout'       => "VARCHAR(50) NOT NULL DEFAULT 'default' AFTER `slug`",
            'page_type'    => "VARCHAR(20) NOT NULL DEFAULT 'page' AFTER `layout`",
            'is_default'   => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_home`",
            'content_html' => "LONGTEXT NULL AFTER `active`",
            'content_css'  => "MEDIUMTEXT NULL AFTER `content_html`",
            'builder_json' => "LONGTEXT NULL AFTER `content_css`",
            'view_count'   => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `builder_json`",
        ];

        foreach ($cols as $col => $ddl) {
            $has = DB::one("SHOW COLUMNS FROM " . self::table() . " LIKE '$col'");
            if (!$has) {
                try {
                    DB::run("ALTER TABLE " . self::table() . " ADD COLUMN `$col` $ddl");
                } catch (Throwable $e) {
                    error_log("PageBuilder schema check $col: " . $e->getMessage());
                }
            }
        }

        // ۳. اطمینان از وجود برگه‌های پیش‌فرض سامانه (صفحه اصلی، تست‌ها، دوره‌ها، کارگاه‌ها، مشاوره و...)
        self::seedDefaultPages();
    }

    /**
     * ایجاد و همگام‌سازی برگه‌های اصلی سامانه جهت مدیریت در صفحه‌ساز
     */
    public static function seedDefaultPages(): void
    {
        static $seeding = false;
        if ($seeding) return;
        $seeding = true;

        self::ensureSchema();

        // ۱. صفحه اصلی (Homepage)
        $homeExists = DB::one("SELECT id FROM " . self::table() . " WHERE is_home=1");
        if (!$homeExists) {
            $slugHome = DB::one("SELECT id FROM " . self::table() . " WHERE slug='home'");
            if ($slugHome) {
                try {
                    DB::run("UPDATE " . self::table() . " SET is_home=1 WHERE id=?", [(int)$slugHome['id']]);
                } catch (Throwable $e) {}
            } else {
                self::seedDefaultHomepage();
            }
        }

        // ۲. آرشیو تست‌های روان‌شناسی (Tests Catalog)
        $testsExists = DB::one("SELECT id FROM " . self::table() . " WHERE page_type='tests' OR slug='tests'");
        if (!$testsExists) {
            DB::insert('site_pages', [
                'name'         => 'آزمون‌های روان‌شناسی',
                'title'        => 'مرکز آزمون‌های تخصصی روان‌شناسی و خودشناسی',
                'slug'         => 'tests',
                'page_type'    => 'tests',
                'layout'       => 'default',
                'seo_title'    => 'تست‌های روانشناسی تخصصی و آنلاین | فریا روان',
                'meta_desc'    => 'فهرست کامل آزمون‌های معتبر روان‌سنجی شامل DISC، نئو NEO، هوش هیجانی و غربالگری سلامت روان با تفسیر بالینی.',
                'is_home'      => 0,
                'is_default'   => 1,
                'active'       => 1,
                'content_html' => self::getDefaultTestsHtml(),
                'content_css'  => self::getDefaultHomepageCss(),
                'builder_json' => null,
                'view_count'   => 0
            ]);
        }

        // ۳. دوره‌های آموزشی (Courses)
        $coursesExists = DB::one("SELECT id FROM " . self::table() . " WHERE page_type='courses' OR slug='courses'");
        if (!$coursesExists) {
            DB::insert('site_pages', [
                'name'         => 'دوره‌های آموزشی',
                'title'        => 'دوره‌های تخصصی و کاربردی روان‌شناسی و مهارت‌افزایی',
                'slug'         => 'courses',
                'page_type'    => 'courses',
                'layout'       => 'default',
                'seo_title'    => 'دوره‌های آموزشی روانشناسی و مهارت‌های فردی | فریا روان',
                'meta_desc'    => 'دوره‌های تخصصی چندجلسه‌ای با تدریس اساتید برجسته روان‌شناسی، سرفصل‌های جامع و ارائه گواهی پایان دوره.',
                'is_home'      => 0,
                'is_default'   => 1,
                'active'       => 1,
                'content_html' => self::getDefaultCoursesHtml(),
                'content_css'  => self::getDefaultHomepageCss(),
                'builder_json' => null,
                'view_count'   => 0
            ]);
        }

        // ۴. کارگاه‌های تعاملی (Workshops)
        $workshopsExists = DB::one("SELECT id FROM " . self::table() . " WHERE page_type='workshops' OR slug='workshops'");
        if (!$workshopsExists) {
            DB::insert('site_pages', [
                'name'         => 'کارگاه‌های تعاملی',
                'title'        => 'کارگاه‌های عملی و مهارت‌محور روان‌شناختی',
                'slug'         => 'workshops',
                'page_type'    => 'workshops',
                'layout'       => 'default',
                'seo_title'    => 'کارگاه‌های عملی روانشناسی و رشد فردی | فریا روان',
                'meta_desc'    => 'کارگاه‌های یک‌روزه و فشرده با تمرین‌های کاربردی، بررسی کیس‌های بالینی و تخفیف ویژه همراهی.',
                'is_home'      => 0,
                'is_default'   => 1,
                'active'       => 1,
                'content_html' => self::getDefaultWorkshopsHtml(),
                'content_css'  => self::getDefaultHomepageCss(),
                'builder_json' => null,
                'view_count'   => 0
            ]);
        }

        // ۵. رزرو مشاوره (Consult)
        $consultExists = DB::one("SELECT id FROM " . self::table() . " WHERE page_type='consult' OR slug='consult'");
        if (!$consultExists) {
            DB::insert('site_pages', [
                'name'         => 'رزرو وقت مشاوره',
                'title'        => 'مرکز خدمات و نوبت‌دهی مشاوره فردی و خانواده',
                'slug'         => 'consult',
                'page_type'    => 'consult',
                'layout'       => 'default',
                'seo_title'    => 'رزرو نوبت مشاوره روانشناسی آنلاین و حضوری | فریا روان',
                'meta_desc'    => 'رزرو وقت مشاوره آنلاین و حضوری با روان‌درمانگران مجرب کلینیک فریا روان در زمینه‌های فردی، زوجین و شغلی.',
                'is_home'      => 0,
                'is_default'   => 1,
                'active'       => 1,
                'content_html' => self::getDefaultConsultHtml(),
                'content_css'  => self::getDefaultHomepageCss(),
                'builder_json' => null,
                'view_count'   => 0
            ]);
        }

        // ۶. درباره ما (About Us)
        $aboutExists = DB::one("SELECT id FROM " . self::table() . " WHERE slug='about-us'");
        if (!$aboutExists) {
            DB::insert('site_pages', [
                'name'         => 'درباره ما',
                'title'        => 'درباره مرکز خدمات روان‌شناسی و مشاوره فریا روان',
                'slug'         => 'about-us',
                'page_type'    => 'page',
                'layout'       => 'default',
                'seo_title'    => 'درباره فریا روان | معرفی کلینیک، اهداف و متخصصان',
                'meta_desc'    => 'آشنایی با تاریخچه، رسالت علمی، استانداردهای بالینی و اعضای هیئت علمی و درمانگران مرکز فریا روان.',
                'is_home'      => 0,
                'is_default'   => 0,
                'active'       => 1,
                'content_html' => self::getDefaultAboutHtml(),
                'content_css'  => self::getDefaultHomepageCss(),
                'builder_json' => null,
                'view_count'   => 0
            ]);
        }

        // ۷. تماس با ما (Contact Us)
        $contactExists = DB::one("SELECT id FROM " . self::table() . " WHERE slug='contact-us'");
        if (!$contactExists) {
            DB::insert('site_pages', [
                'name'         => 'تماس با ما',
                'title'        => 'راه‌های ارتباطی و نشانی کلینیک فریا روان',
                'slug'         => 'contact-us',
                'page_type'    => 'page',
                'layout'       => 'default',
                'seo_title'    => 'تماس با ما | نشانی، تلفن و پشتیبانی فریا روان',
                'meta_desc'    => 'آدرس کلینیک، شماره تلفن‌های پشتیبانی و مشاوره، ساعات کاری و نقشه دسترسی حضوری.',
                'is_home'      => 0,
                'is_default'   => 0,
                'active'       => 1,
                'content_html' => self::getDefaultContactHtml(),
                'content_css'  => self::getDefaultHomepageCss(),
                'builder_json' => null,
                'view_count'   => 0
            ]);
        }
    }

    /**
     * ایجاد برگه اولیه صفحه اصلی جهت ویرایش و شخصی‌سازی اولیه
     */
    public static function seedDefaultHomepage(): int
    {
        $defaultHtml = self::getDefaultHomepageHtml();
        $defaultCss  = self::getDefaultHomepageCss();

        return (int)DB::insert('site_pages', [
            'name'         => 'صفحه اصلی فریا روان',
            'title'        => 'مرکز ارزیابی روان‌شناختی، تست‌های آنلاین و مشاوره تخصصی',
            'slug'         => 'home',
            'layout'       => 'default',
            'seo_title'    => 'فریا روان | سامانه ارزیابی روان‌شناسی و مشاوره آنلاین',
            'meta_desc'    => 'سامانه جامع ارزیابی روان‌شناختی، آزمون‌های تخصصی شخصیت و هوش، دوره‌ها و کارگاه‌ها، و رزرو نوبت مشاوره فردی و زوجین.',
            'is_home'      => 1,
            'active'       => 1,
            'content_html' => $defaultHtml,
            'content_css'  => $defaultCss,
            'builder_json' => null,
            'view_count'   => 0
        ]);
    }

    /**
     * دریافت فهرست تمام برگه‌ها
     */
    public static function getPages(?string $status = null): array
    {
        self::ensureSchema();
        $where = '1=1';
        $params = [];
        if ($status === 'published') {
            $where .= ' AND active=1';
        } elseif ($status === 'draft') {
            $where .= ' AND active=0';
        }

        return DB::all("SELECT * FROM " . self::table() . " WHERE $where ORDER BY is_home DESC, updated_at DESC", $params);
    }

    /**
     * دریافت برگه بر اساس شناسه یا اسلاگ
     */
    public static function getPage(int|string $idOrSlug): ?array
    {
        self::ensureSchema();
        if (is_numeric($idOrSlug)) {
            $p = DB::one("SELECT * FROM " . self::table() . " WHERE id=?", [(int)$idOrSlug]);
        } else {
            $p = DB::one("SELECT * FROM " . self::table() . " WHERE slug=?", [(string)$idOrSlug]);
        }
        return $p ?: null;
    }

    /**
     * دریافت برگه فعال صفحه اصلی
     */
    public static function getHomepage(): ?array
    {
        self::ensureSchema();
        $p = DB::one("SELECT * FROM " . self::table() . " WHERE is_home=1 AND active=1 ORDER BY id DESC LIMIT 1");
        if (!$p) {
            $p = DB::one("SELECT * FROM " . self::table() . " WHERE slug='home' AND active=1 ORDER BY id ASC LIMIT 1");
            if ($p) {
                try {
                    DB::run("UPDATE " . self::table() . " SET is_home=1 WHERE id=?", [(int)$p['id']]);
                    $p['is_home'] = 1;
                } catch (Throwable $e) {}
            }
        }
        return $p ?: null;
    }

    /**
     * تنظیم یک برگه به عنوان صفحه اصلی سایت
     */
    public static function setHomepage(int $pageId): bool
    {
        self::ensureSchema();
        DB::run("UPDATE " . self::table() . " SET is_home=0");
        DB::run("UPDATE " . self::table() . " SET is_home=1, active=1 WHERE id=?", [$pageId]);
        return true;
    }

    /**
     * دریافت برگه اختصاصی یک بخش از سامانه (home, tests, courses, workshops, consult)
     */
    public static function getSectionPage(string $section): ?array
    {
        self::ensureSchema();
        if ($section === 'home') {
            return self::getHomepage();
        }

        // ۱. جستجو بر اساس page_type فعال
        $p = DB::one("SELECT * FROM " . self::table() . " WHERE page_type=? AND active=1 ORDER BY is_default DESC, id DESC LIMIT 1", [$section]);
        if ($p) return $p;

        // ۲. جستجو بر اساس اسلاگ یکتا
        $p = DB::one("SELECT * FROM " . self::table() . " WHERE slug=? AND active=1 LIMIT 1", [$section]);
        return $p ?: null;
    }

    /**
     * دریافت سربرگ (Header) سفارشی پیش‌فرض و فعال سایت
     */
    public static function getActiveHeader(): ?array
    {
        self::ensureSchema();
        $h = DB::one("SELECT * FROM " . self::table() . " WHERE page_type='header' AND is_default=1 AND active=1 ORDER BY id DESC LIMIT 1");
        return $h ?: null;
    }

    /**
     * دریافت پاورقی (Footer) سفارشی پیش‌فرض و فعال سایت
     */
    public static function getActiveFooter(): ?array
    {
        self::ensureSchema();
        $f = DB::one("SELECT * FROM " . self::table() . " WHERE page_type='footer' AND is_default=1 AND active=1 ORDER BY id DESC LIMIT 1");
        return $f ?: null;
    }

    /**
     * تنظیم یک قالب سربرگ یا پاورقی به عنوان پیش‌فرض فعال سایت
     */
    public static function setActiveTemplate(int $id, string $type): bool
    {
        self::ensureSchema();
        if (!in_array($type, ['header', 'footer'], true)) return false;
        DB::run("UPDATE " . self::table() . " SET is_default=0 WHERE page_type=?", [$type]);
        DB::run("UPDATE " . self::table() . " SET is_default=1, active=1 WHERE id=? AND page_type=?", [$id, $type]);
        return true;
    }

    /**
     * غیرفعال‌سازی قالب پیش‌فرض سربرگ یا پاورقی (بازگشت به قالب کلاسیک)
     */
    public static function unsetActiveTemplate(int $id): bool
    {
        self::ensureSchema();
        DB::run("UPDATE " . self::table() . " SET is_default=0 WHERE id=?", [$id]);
        return true;
    }

    /**
     * واکشی قالب‌های طراحی‌شده بر اساس نوع (page / header / footer)
     */
    public static function getTemplates(string $type = 'header'): array
    {
        self::ensureSchema();
        return DB::all("SELECT * FROM " . self::table() . " WHERE page_type=? ORDER BY is_default DESC, updated_at DESC", [$type]);
    }

    /**
     * ذخیره یا به‌روزرسانی متادیتا و تنظیمات برگه
     */
    public static function savePage(array $data, ?int $id = null): int
    {
        self::ensureSchema();
        $title = trim((string)($data['title'] ?? ''));
        $name  = trim((string)($data['name'] ?? '')) ?: $title;
        $slug  = trim((string)($data['slug'] ?? ''));
        $slug  = preg_replace('/[^\p{L}\p{N}_\-]+/u', '-', mb_strtolower($slug));
        $slug  = trim($slug, '-');

        if ($slug === '') {
            $slug = 'page-' . time();
        }

        $isHome = !empty($data['is_home']) ? 1 : 0;
        $pageType = trim((string)($data['page_type'] ?? 'page'));
        if (!in_array($pageType, ['page', 'header', 'footer'], true)) {
            $pageType = 'page';
        }
        $isDefault = !empty($data['is_default']) ? 1 : 0;

        $record = [
            'name'        => $name,
            'title'       => $title,
            'slug'        => $slug,
            'layout'      => (string)($data['layout'] ?? 'default'),
            'page_type'   => $pageType,
            'seo_title'   => trim((string)($data['seo_title'] ?? '')) ?: null,
            'meta_desc'   => trim((string)($data['meta_desc'] ?? '')) ?: null,
            'noindex'     => !empty($data['noindex']) ? 1 : 0,
            'is_home'     => $isHome,
            'is_default'  => $isDefault,
            'active'      => !empty($data['active']) ? 1 : 0,
        ];

        if (isset($data['content_html'])) {
            $record['content_html'] = (string)$data['content_html'];
        }
        if (isset($data['content_css'])) {
            $record['content_css'] = (string)$data['content_css'];
        }
        if (isset($data['builder_json'])) {
            $record['builder_json'] = (string)$data['builder_json'];
        }

        if ($id && $id > 0) {
            // اطمینان از یکتا بودن اسلاگ
            $exists = DB::one("SELECT id FROM " . self::table() . " WHERE slug=? AND id!=?", [$slug, $id]);
            if ($exists) {
                $record['slug'] = $slug . '-' . $id;
            }
            DB::update('site_pages', $record, 'id=?', [$id]);
            if ($isHome) {
                self::setHomepage($id);
            }
            return $id;
        } else {
            $exists = DB::one("SELECT id FROM " . self::table() . " WHERE slug=?", [$slug]);
            if ($exists) {
                $record['slug'] = $slug . '-' . time();
            }
            $newId = (int)DB::insert('site_pages', $record);
            if ($isHome) {
                self::setHomepage($newId);
            }
            return $newId;
        }
    }

    /**
     * ذخیره خروجی کد HTML، CSS و ساختار JSON صفحه‌ساز
     */
    public static function saveBuilderContent(int $pageId, string $html, string $css, ?string $json = null): bool
    {
        self::ensureSchema();
        DB::update('site_pages', [
            'content_html' => $html,
            'content_css'  => $css,
            'builder_json' => $json,
            'updated_at'   => date('Y-m-d H:i:s')
        ], 'id=?', [$pageId]);

        return true;
    }

    /**
     * حذف یک برگه
     */
    public static function deletePage(int $pageId): bool
    {
        self::ensureSchema();
        $p = self::getPage($pageId);
        if (!$p) return false;

        // ممانعت از حذف صفحه اصلی فعال
        if (!empty($p['is_home'])) {
            return false;
        }

        DB::run("DELETE FROM " . self::table() . " WHERE id=?", [$pageId]);
        return true;
    }

    /**
     * تکثیر / کپی یک برگه
     */
    public static function duplicatePage(int $pageId): ?int
    {
        self::ensureSchema();
        $p = self::getPage($pageId);
        if (!$p) return null;

        $newTitle = 'کپی ' . $p['title'];
        $newSlug  = $p['slug'] . '-copy-' . time();

        $newId = (int)DB::insert('site_pages', [
            'name'         => ($p['name'] ?: $p['title']) . ' (کپی)',
            'title'        => $newTitle,
            'slug'         => $newSlug,
            'layout'       => $p['layout'] ?? 'default',
            'page_type'    => $p['page_type'] ?? 'page',
            'seo_title'    => $p['seo_title'] ?? null,
            'meta_desc'    => $p['meta_desc'] ?? null,
            'noindex'      => (int)($p['noindex'] ?? 0),
            'is_home'      => 0, // کپی هیچگاه پیش‌فرض صفحه اصلی نمی‌شود
            'is_default'   => 0, // کپی هیچگاه سربرگ/پاورقی پیش‌فرض نمی‌شود
            'active'       => 0, // کپی به‌صورت پیش‌نویس ذخیره می‌شود
            'content_html' => $p['content_html'] ?? null,
            'content_css'  => $p['content_css'] ?? null,
            'builder_json' => $p['builder_json'] ?? null,
            'view_count'   => 0
        ]);

        return $newId > 0 ? $newId : null;
    }

    /**
     * رندر کامل و نهایی یک برگه (برای نمایش زنده در وب‌سایت)
     */
    public static function renderPage(array $page, bool $isLive = true): void
    {
        if ($isLive && !empty($page['id'])) {
            try {
                DB::run("UPDATE " . self::table() . " SET view_count = view_count + 1 WHERE id=?", [(int)$page['id']]);
            } catch (Throwable $e) {}
        }

        $rawHtml = (string)($page['content_html'] ?? '');
        $css     = (string)($page['content_css'] ?? '');
        $layout  = (string)($page['layout'] ?? 'default');
        $title   = (string)($page['title'] ?? 'فریا روان');
        $seoTitle = (string)($page['seo_title'] ?? $title);
        $metaDesc = (string)($page['meta_desc'] ?? '');

        // حل و رندر ویجت‌های پویا در کدهای HTML
        $resolvedHtml = self::resolveWidgets($rawHtml, false);

        if ($layout === 'blank') {
            // قالب خام بدون هدر و فوتر عمومی
            ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($seoTitle) ?></title>
  <?php if (!empty($metaDesc)): ?><meta name="description" content="<?= e($metaDesc) ?>"><?php endif; ?>
  <?php if (!empty($page['noindex'])): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/site.css">
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/feria.css">
  <?php if (!empty($css)): ?>
    <style><?= $css ?></style>
  <?php endif; ?>
</head>
<body class="fr-page-blank">
  <?= $resolvedHtml ?>
</body>
</html>
            <?php
            return;
        }

        // قالب پیش‌فرض یا تمام‌صفحه با هدر و فوتر استاندارد
        $opts = [
            'active' => !empty($page['is_home']) ? 'home' : 'page',
            'desc'   => $metaDesc,
        ];
        if (!empty($page['noindex'])) {
            $opts['noindex'] = true;
        }

        public_header($opts, $seoTitle);

        if (!empty($css)) {
            echo "<style id=\"fr-page-custom-css\">\n" . $css . "\n</style>\n";
        }

        echo "<main class=\"fr-custom-page-container\">\n";
        echo $resolvedHtml;
        echo "\n</main>\n";

        public_footer();
    }

    /**
     * جایگزینی ویجت‌های پویا و شورت‌کدهای اختصاصی در HTML
     */
    public static function resolveWidgets(string $html, bool $isEditor = false): string
    {
        if ($html === '') return '';

        // ۱. پردازش المنت‌هایی که صفت data-widget یا data-fr-widget دارند: <div data-widget="tests" data-limit="6">...</div>
        $pattern = '/<([a-z0-9]+)\b([^>]*?)\b(?:data-widget|data-fr-widget)=([\'"])(.*?)\3([^>]*)>(.*?)<\/\1>/si';
        $html = preg_replace_callback($pattern, function ($matches) use ($isEditor) {
            $tag      = $matches[1];
            $before   = $matches[2];
            $widget   = trim($matches[4]);
            $after    = $matches[5];
            $existing = $matches[6];

            // استخراج پارامترهای data-*
            $allAttrs = $before . ' ' . $after;
            preg_match_all('/data-([a-z0-9_\-]+)=([\'"])(.*?)\2/i', $allAttrs, $attrMatches, PREG_SET_ORDER);
            $params = [];
            foreach ($attrMatches as $am) {
                if ($am[1] === 'params') {
                    $dec = json_decode(html_entity_decode($am[3]), true);
                    if (is_array($dec)) {
                        $params = array_merge($params, $dec);
                    }
                } else {
                    $params[$am[1]] = $am[3];
                }
            }

            $rendered = self::renderWidget($widget, $params, $isEditor);
            return "<{$tag}{$before} data-widget=\"{$widget}\"{$after}>{$rendered}</{$tag}>";
        }, $html);

        // ۲. پردازش شورت‌کدهای سبک: [fr_widget name="tests" limit="6"]
        $scPattern = '/\[fr_widget\s+name=([\'"])(.*?)\1([^\]]*)\]/i';
        $html = preg_replace_callback($scPattern, function ($matches) use ($isEditor) {
            $widget = trim($matches[2]);
            $extra  = $matches[3];
            preg_match_all('/([a-z0-9_\-]+)=([\'"])(.*?)\2/i', $extra, $attrMatches, PREG_SET_ORDER);
            $params = [];
            foreach ($attrMatches as $am) {
                $params[$am[1]] = $am[3];
            }
            return self::renderWidget($widget, $params, $isEditor);
        }, $html);

        // ۳. پردازش شورت‌کدهای فرمت: [widget:tests limit="6"]
        $scPattern2 = '/\[widget:([a-zA-Z0-9_-]+)(.*?)\]/i';
        $html = preg_replace_callback($scPattern2, function ($matches) use ($isEditor) {
            $widget = trim($matches[1]);
            $extra  = $matches[2];
            preg_match_all('/([a-z0-9_\-]+)=([\'"])(.*?)\2/i', $extra, $attrMatches, PREG_SET_ORDER);
            $params = [];
            foreach ($attrMatches as $am) {
                $params[$am[1]] = $am[3];
            }
            return self::renderWidget($widget, $params, $isEditor);
        }, $html);

        return $html;
    }

    /**
     * نام مستعار جهت راحتی استفاده (Shortcode Resolver Alias)
     */
    public static function resolveShortcodes(string $html, bool $isEditor = false): string
    {
        return self::resolveWidgets($html, $isEditor);
    }

    /**
     * رندر خروجی هر ویجت اختصاصی بر مبنای داده‌های واقعی سامانه
     */
    public static function renderWidget(string $name, array $params = [], bool $isEditor = false): string
    {
        ob_start();

        switch ($name) {
            case 'tests':
                self::renderWidgetTests($params, $isEditor);
                break;

            case 'tests_catalog':
            case 'tests-catalog':
                self::renderWidgetTestsCatalog($params, $isEditor);
                break;

            case 'test_single':
            case 'test-single':
            case 'test_featured':
                self::renderWidgetTestSingle($params, $isEditor);
                break;

            case 'courses':
                self::renderWidgetCourses($params, $isEditor);
                break;

            case 'course_single':
            case 'course-single':
            case 'course_featured':
                self::renderWidgetCourseSingle($params, $isEditor);
                break;

            case 'workshops':
                self::renderWidgetWorkshops($params, $isEditor);
                break;

            case 'workshop_single':
            case 'workshop-single':
            case 'workshop_featured':
                self::renderWidgetWorkshopSingle($params, $isEditor);
                break;

            case 'consult':
                self::renderWidgetConsult($params, $isEditor);
                break;

            case 'consultants_list':
            case 'consultants-list':
            case 'therapists':
                self::renderWidgetConsultantsList($params, $isEditor);
                break;

            case 'banner_cta':
            case 'banner-cta':
                self::renderWidgetBannerCta($params, $isEditor);
                break;

            case 'newsletter':
                self::renderWidgetNewsletter($params, $isEditor);
                break;

            case 'reviews':
            case 'testimonials':
                self::renderWidgetReviews($params, $isEditor);
                break;

            case 'trust_badges':
            case 'trust-badges':
                self::renderWidgetTrustBadges($params, $isEditor);
                break;

            case 'hero':
                self::renderWidgetHero($params, $isEditor);
                break;

            case 'stats':
                self::renderWidgetStats($params, $isEditor);
                break;

            case 'faq':
                self::renderWidgetFaq($params, $isEditor);
                break;

            case 'features':
                self::renderWidgetFeatures($params, $isEditor);
                break;

            case 'contact_box':
            case 'contact-box':
                self::renderWidgetContactBox($params, $isEditor);
                break;

            // ویجت‌های اختصاصی سربرگ (Header)
            case 'site_logo':
            case 'logo':
                self::renderWidgetSiteLogo($params, $isEditor);
                break;

            case 'menu':
            case 'nav_menu':
                self::renderWidgetMenu($params, $isEditor);
                break;

            case 'user_auth':
            case 'auth_buttons':
                self::renderWidgetUserAuth($params, $isEditor);
                break;

            case 'header_cta':
            case 'cta_btn':
                self::renderWidgetHeaderCta($params, $isEditor);
                break;

            case 'search_bar':
            case 'search':
                self::renderWidgetSearchBar($params, $isEditor);
                break;

            case 'topbar_contact':
                self::renderWidgetTopbarContact($params, $isEditor);
                break;

            // ویجت‌های اختصاصی پاورقی (Footer)
            case 'footer_brand':
                self::renderWidgetFooterBrand($params, $isEditor);
                break;

            case 'footer_menu':
                self::renderWidgetFooterMenu($params, $isEditor);
                break;

            case 'footer_contact':
                self::renderWidgetFooterContact($params, $isEditor);
                break;

            case 'social_icons':
            case 'social_links':
                self::renderWidgetSocialIcons($params, $isEditor);
                break;

            case 'footer_badges':
                self::renderWidgetFooterBadges($params, $isEditor);
                break;

            case 'copyright':
                self::renderWidgetCopyright($params, $isEditor);
                break;

            default:
                echo "<!-- ویجت ناشناخته: " . htmlspecialchars($name) . " -->";
                break;
        }

        return (string)ob_get_clean();
    }

    /**
     * ویجت ۱: تست‌های روان‌شناسی
     */
    public static function renderWidgetTests(array $params, bool $isEditor): void
    {
        $limit    = !empty($params['limit']) ? (int)$params['limit'] : 6;
        $category = (string)($params['category'] ?? 'all');
        $title    = (string)($params['title'] ?? '🧠 آزمون‌های تخصصی روان‌شناسی و خودشناسی');
        $subtitle = (string)($params['subtitle'] ?? 'سنجش علمی، معتبر و چندبعدی با تحلیل خودکار و کارنامه تحلیلی تفصیلی');

        $where = "status='active'";
        $qParams = [];
        if ($category === 'general') {
            $where .= " AND audience='general'";
        } elseif ($category === 'manager') {
            $where .= " AND audience='manager'";
        }

        $tests = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE $where ORDER BY sort_order ASC LIMIT $limit", $qParams);
        ?>
        <div class="fr-widget-tests-wrapper" style="padding:40px 16px;max-width:1200px;margin:0 auto">
          <?php if (!empty($title)): ?>
            <div style="text-align:center;margin-bottom:30px">
              <h2 style="font-size:24px;font-weight:800;color:#1e293b;margin:0 0 10px"><?= e($title) ?></h2>
              <?php if (!empty($subtitle)): ?>
                <p style="font-size:14px;color:#64748b;margin:0"><?= e($subtitle) ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if (empty($tests)): ?>
            <div style="text-align:center;padding:30px;color:#94a3b8">آزمونی برای نمایش یافت نشد.</div>
          <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:22px">
              <?php foreach ($tests as $t):
                  $isFree = !empty($t['free_enabled']);
                  $price = (int)($t['price_full'] ?? 0);
                  $salePrice = (int)($t['price_sale'] ?? 0);
                  $testUrl = SITE_URL . '/test-page.php?slug=' . urlencode((string)$t['slug']);
              ?>
                <div class="fr-test-card" style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,0.03);display:flex;flex-direction:column;transition:transform .2s,box-shadow .2s">
                  <?php if (!empty($t['image'])): ?>
                    <div style="height:160px;overflow:hidden;background:#f1f5f9;position:relative">
                      <img src="<?= e(SITE_URL . '/' . ltrim((string)$t['image'], '/')) ?>" alt="<?= e($t['title']) ?>" style="width:100%;height:100%;object-fit:cover">
                      <?php if ($isFree): ?>
                        <span style="position:absolute;top:10px;right:10px;background:#10b981;color:#fff;font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px">نسخه رایگان</span>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div style="height:100px;background:linear-gradient(135deg, #6366f1, #8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:32px">
                      🧠
                    </div>
                  <?php endif; ?>

                  <div style="padding:16px;display:flex;flex-direction:column;flex:1">
                    <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px;line-height:1.5">
                      <a href="<?= e($testUrl) ?>" style="color:inherit;text-decoration:none"><?= e($t['title']) ?></a>
                    </h3>
                    <p style="font-size:12.5px;color:#64748b;margin:0 0 14px;line-height:1.7;flex:1">
                      <?= e(mb_strimwidth((string)($t['short_desc'] ?: $t['description']), 0, 110, '...')) ?>
                    </p>

                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:11.5px;color:#64748b;padding-bottom:12px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">
                      <span>⏱️ حدود <?= fa_num($t['est_minutes'] ?: 15) ?> دقیقه</span>
                      <span>❓ <?= fa_num($t['question_count'] ?: 60) ?> سؤال</span>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center">
                      <div>
                        <?php if ($isFree): ?>
                          <span style="color:#10b981;font-weight:700;font-size:13.5px">رایگان</span>
                        <?php elseif ($salePrice > 0 && $salePrice < $price): ?>
                          <div style="text-decoration:line-through;color:#94a3b8;font-size:11px"><?= fa_num(number_format($price)) ?> ت</div>
                          <div style="color:#0284c7;font-weight:700;font-size:13.5px"><?= fa_num(number_format($salePrice)) ?> تومان</div>
                        <?php else: ?>
                          <div style="color:#0f172a;font-weight:700;font-size:13.5px"><?= fa_num(number_format($price)) ?> تومان</div>
                        <?php endif; ?>
                      </div>
                      <a href="<?= e($testUrl) ?>" class="fr-btn sm primary" style="padding:6px 14px;border-radius:8px;font-size:12px;text-decoration:none">
                        شروع آزمون ←
                      </a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div style="text-align:center;margin-top:28px">
              <a href="<?= SITE_URL ?>/tests.php" class="fr-btn ghost sm" style="text-decoration:none">
                مشاهده همه آزمون‌های روان‌شناسی (<?= fa_num(count($tests)) ?> مورد) ←
              </a>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ویجت ۱-ب: کاتالوگ جامع تست‌های روان‌شناسی با فیلتر دسته‌بندی
     */
    public static function renderWidgetTestsCatalog(array $params, bool $isEditor): void
    {
        $limit = !empty($params['limit']) ? (int)$params['limit'] : 12;
        $activeCat = (string)($params['category'] ?? ($_GET['cat'] ?? 'all'));
        $where = "status='active'";
        $qParams = [];
        if (in_array($activeCat, ['clinical', 'personality', 'intelligence'], true)) {
            $where .= " AND category=?";
            $qParams[] = $activeCat;
        }
        $tests = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE $where ORDER BY sort_order ASC LIMIT $limit", $qParams);
        $totalTests = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active'");
        $clinCount  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active' AND category='clinical'");
        $persCount  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active' AND category='personality'");
        $intelCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active' AND category='intelligence'");
        ?>
        <div class="fr-widget-tests-catalog-wrap" style="padding:40px 16px;max-width:1200px;margin:0 auto">
          <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:30px">
            <a href="<?= SITE_URL ?>/tests.php" class="fr-btn sm <?= $activeCat==='all'?'primary':'ghost' ?>" style="text-decoration:none;border-radius:10px;padding:8px 18px">
              همه آزمون‌ها (<?= fa_num($totalTests) ?>)
            </a>
            <a href="<?= SITE_URL ?>/tests.php?cat=clinical" class="fr-btn sm <?= $activeCat==='clinical'?'primary':'ghost' ?>" style="text-decoration:none;border-radius:10px;padding:8px 18px">
              🩺 بالینی و سلامت روان (<?= fa_num($clinCount) ?>)
            </a>
            <a href="<?= SITE_URL ?>/tests.php?cat=personality" class="fr-btn sm <?= $activeCat==='personality'?'primary':'ghost' ?>" style="text-decoration:none;border-radius:10px;padding:8px 18px">
              🎭 شخصیت و خودشناسی (<?= fa_num($persCount) ?>)
            </a>
            <a href="<?= SITE_URL ?>/tests.php?cat=intelligence" class="fr-btn sm <?= $activeCat==='intelligence'?'primary':'ghost' ?>" style="text-decoration:none;border-radius:10px;padding:8px 18px">
              💡 هوش و پیشرفت شغلی (<?= fa_num($intelCount) ?>)
            </a>
          </div>

          <?php if (empty($tests)): ?>
            <div style="text-align:center;padding:40px;color:#94a3b8">آزمونی در این دسته‌بندی یافت نشد.</div>
          <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(290px, 1fr));gap:24px">
              <?php foreach ($tests as $t):
                $testUrl = fr_test_url($t);
                $isFree = !empty($t['free_enabled']);
                $price = (int)($t['price_full'] ?? 0);
                $salePrice = (int)($t['price_sale'] ?? 0);
                $isMgr = ((string)($t['audience'] ?? 'general')) === 'manager';
              ?>
                <div class="fr-test-card" style="background:#fff;border-radius:18px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,0.04);display:flex;flex-direction:column;transition:all .2s ease">
                  <div style="height:120px;background:linear-gradient(135deg,<?= $isMgr ? '#1e1b4b,#3b2a7a' : '#4f46e5,#7c3aed' ?>);display:flex;align-items:center;justify-content:center;position:relative;color:#fff">
                    <span style="font-size:38px"><?= $isMgr ? '👑' : '🧠' ?></span>
                    <?php if ($isMgr): ?>
                      <span style="position:absolute;top:10px;right:10px;background:#f59e0b;color:#0f172a;font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px">ویژه مدیران</span>
                    <?php endif; ?>
                    <?php if ($isFree): ?>
                      <span style="position:absolute;top:10px;left:10px;background:#10b981;color:#fff;font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px">تحلیل خلاصه رایگان</span>
                    <?php endif; ?>
                  </div>
                  <div style="padding:18px;display:flex;flex-direction:column;flex:1">
                    <h3 style="font-size:16px;font-weight:800;color:#0f172a;margin:0 0 8px;line-height:1.5">
                      <a href="<?= e($testUrl) ?>" style="color:inherit;text-decoration:none"><?= e($t['title']) ?></a>
                    </h3>
                    <p style="font-size:12.5px;color:#64748b;margin:0 0 16px;line-height:1.7;flex:1">
                      <?= e(mb_strimwidth((string)($t['short_desc'] ?: $t['description']), 0, 100, '...')) ?>
                    </p>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:11.5px;color:#64748b;padding-bottom:12px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">
                      <span>⏱️ حدود <?= fa_num($t['est_minutes'] ?: 15) ?> دقیقه</span>
                      <span>❓ <?= fa_num($t['question_count'] ?: 50) ?> سؤال</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center">
                      <div>
                        <?php if ($isFree): ?>
                          <span style="color:#10b981;font-weight:800;font-size:13.5px">رایگان</span>
                        <?php elseif ($salePrice > 0 && $salePrice < $price): ?>
                          <div style="text-decoration:line-through;color:#94a3b8;font-size:11px"><?= fa_num(number_format($price)) ?> ت</div>
                          <div style="color:#0284c7;font-weight:800;font-size:13.5px"><?= fa_num(number_format($salePrice)) ?> تومان</div>
                        <?php else: ?>
                          <div style="color:#0f172a;font-weight:800;font-size:13.5px"><?= fa_num(number_format($price)) ?> تومان</div>
                        <?php endif; ?>
                      </div>
                      <a href="<?= e($testUrl) ?>" class="fr-btn sm primary" style="padding:6px 14px;border-radius:8px;font-size:12px;text-decoration:none">
                        شروع آزمون ←
                      </a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ویجت ۱-ج: معرفی آزمون برگزیده (تکی)
     */
    public static function renderWidgetTestSingle(array $params, bool $isEditor): void
    {
        $testId = !empty($params['id']) ? (int)$params['id'] : 0;
        $t = $testId > 0
            ? DB::one("SELECT * FROM " . DB::t('tests') . " WHERE id=? AND status='active'", [$testId])
            : DB::one("SELECT * FROM " . DB::t('tests') . " WHERE status='active' ORDER BY (audience='manager') DESC, id ASC LIMIT 1");

        if (!$t) {
            if ($isEditor) {
                ?>
                <div class="fr-widget-test-single" style="max-width:1100px;margin:30px auto;padding:24px;border:2px dashed #4338ca;border-radius:18px;text-align:center;background:#eef2ff;color:#3730a3">
                  <b>🧠 ویجت معرفی آزمون برگزیده (تکی)</b>
                  <p style="font-size:12px;margin:6px 0 0">هنوز تستی در سامانه ثبت نشده است. پس از افزودن آزمون، در این بخش نمایش می‌یابد.</p>
                </div>
                <?php
            }
            return;
        }

        $testUrl = fr_test_url($t);
        $price = (int)($t['price_full'] ?? 0);
        $salePrice = (int)($t['price_sale'] ?? 0);
        ?>
        <div class="fr-widget-test-single" style="max-width:1100px;margin:30px auto;padding:0 16px">
          <div style="background:linear-gradient(135deg, #1e3a8a, #312e81);border-radius:24px;padding:36px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;box-shadow:0 12px 30px rgba(30,58,138,0.25)">
            <div style="flex:1;min-width:280px">
              <span style="background:rgba(255,255,255,0.15);padding:4px 12px;border-radius:8px;font-size:11px;font-weight:700;display:inline-block;margin-bottom:12px">
                ✨ آزمون برگزیده روان‌سنجی
              </span>
              <h2 style="font-size:24px;font-weight:900;margin:0 0 10px;line-height:1.4"><?= e($t['title']) ?></h2>
              <p style="font-size:14px;color:#cbd5e1;line-height:1.9;margin:0 0 20px;max-width:620px">
                <?= e((string)($t['short_desc'] ?: $t['description'])) ?>
              </p>
              <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px;color:#93c5fd;margin-bottom:24px">
                <span>⏱ زمان تخمینی: <?= fa_num($t['est_minutes'] ?: 15) ?> دقیقه</span>
                <span>❓ تعداد سوالات: <?= fa_num($t['question_count'] ?: 50) ?> سوال</span>
                <span>📊 کارنامه تحلیلی هوشمند</span>
              </div>
              <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                <a href="<?= e($testUrl) ?>" class="fr-btn white" style="font-weight:800;padding:12px 28px;border-radius:12px;font-size:14px;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,0.2)">
                  ورود به آزمون و شروع ارزیابی ←
                </a>
                <span style="font-size:15px;font-weight:800">
                  <?php if (!empty($t['free_enabled'])): ?>
                    رایگان
                  <?php elseif ($salePrice > 0 && $salePrice < $price): ?>
                    <del style="opacity:.6;font-size:12px"><?= fa_num(number_format($price)) ?></del> <?= fa_num(number_format($salePrice)) ?> تومان
                  <?php else: ?>
                    <?= fa_num(number_format($price)) ?> تومان
                  <?php endif; ?>
                </span>
              </div>
            </div>
            <div style="font-size:80px;background:rgba(255,255,255,0.08);width:140px;height:140px;border-radius:24px;display:flex;align-items:center;justify-content:center;box-shadow:inset 0 0 20px rgba(255,255,255,0.05)">
              🧠
            </div>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۲: دوره‌های آموزشی روان‌شناسی
     */
    public static function renderWidgetCourses(array $params, bool $isEditor): void
    {
        $limit = !empty($params['limit']) ? (int)$params['limit'] : 3;
        $title = (string)($params['title'] ?? '📚 دوره‌های تخصصی روان‌شناسی و مهارت‌افزایی');
        $subtitle = (string)($params['subtitle'] ?? 'آموزش‌های کاربردی گام‌به‌گام با تدریس اساتید و روان‌درمانگران برجسته');

        $courses = DB::all("SELECT * FROM " . DB::t('events') . " WHERE kind='course' AND status='active' ORDER BY starts_at ASC LIMIT $limit");
        ?>
        <div class="fr-widget-courses-wrapper" style="padding:40px 16px;max-width:1200px;margin:0 auto">
          <?php if (!empty($title)): ?>
            <div style="text-align:center;margin-bottom:30px">
              <h2 style="font-size:24px;font-weight:800;color:#1e293b;margin:0 0 10px"><?= e($title) ?></h2>
              <?php if (!empty($subtitle)): ?><p style="font-size:14px;color:#64748b;margin:0"><?= e($subtitle) ?></p><?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if (empty($courses)): ?>
            <div style="text-align:center;padding:30px;color:#94a3b8">دوره‌ای در حال حاضر فعال نیست.</div>
          <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:24px">
              <?php foreach ($courses as $c):
                  $courseUrl = SITE_URL . '/course.php?slug=' . urlencode((string)$c['slug']);
                  $price = (int)($c['price'] ?? 0);
                  $salePrice = (int)($c['sale_price'] ?? 0);
              ?>
                <div class="fr-course-card" style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,0.03);display:flex;flex-direction:column">
                  <?php if (!empty($c['image'])): ?>
                    <div style="height:170px;overflow:hidden;background:#f8fafc">
                      <img src="<?= e(SITE_URL . '/' . ltrim((string)$c['image'], '/')) ?>" alt="<?= e($c['title']) ?>" style="width:100%;height:100%;object-fit:cover">
                    </div>
                  <?php endif; ?>
                  <div style="padding:18px;display:flex;flex-direction:column;flex:1">
                    <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px;line-height:1.5">
                      <a href="<?= e($courseUrl) ?>" style="color:inherit;text-decoration:none"><?= e($c['title']) ?></a>
                    </h3>
                    <p style="font-size:12.5px;color:#64748b;margin:0 0 12px;line-height:1.7;flex:1">
                      <?= e(mb_strimwidth((string)($c['short_desc'] ?: $c['description']), 0, 110, '...')) ?>
                    </p>
                    <div style="font-size:12px;color:#475569;margin-bottom:14px;display:flex;justify-content:space-between">
                      <span>👤 مدرس: <?= e($c['teacher'] ?: 'اساتید فریا روان') ?></span>
                      <span>⏱️ <?= fa_num($c['duration_hours'] ?: 10) ?> ساعت آموزش</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f1f5f9;padding-top:12px">
                      <div>
                        <?php if ($salePrice > 0 && $salePrice < $price): ?>
                          <span style="text-decoration:line-through;color:#94a3b8;font-size:11px"><?= fa_num(number_format($price)) ?></span>
                          <span style="color:#0284c7;font-weight:700;font-size:14px"><?= fa_num(number_format($salePrice)) ?> ت</span>
                        <?php else: ?>
                          <span style="color:#0f172a;font-weight:700;font-size:14px"><?= fa_num(number_format($price)) ?> تومان</span>
                        <?php endif; ?>
                      </div>
                      <a href="<?= e($courseUrl) ?>" class="fr-btn sm primary" style="text-decoration:none">مشاهده دوره ←</a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ویجت ۲-ب: معرفی دوره آموزشی برگزیده (تکی)
     */
    public static function renderWidgetCourseSingle(array $params, bool $isEditor): void
    {
        $courseId = !empty($params['id']) ? (int)$params['id'] : 0;
        $c = $courseId > 0
            ? DB::one("SELECT * FROM " . DB::t('events') . " WHERE id=? AND kind='course' AND status='active'", [$courseId])
            : DB::one("SELECT * FROM " . DB::t('events') . " WHERE kind='course' AND status='active' ORDER BY starts_at ASC LIMIT 1");

        if (!$c) {
            if ($isEditor) {
                ?>
                <div class="fr-widget-course-single" style="max-width:1100px;margin:30px auto;padding:24px;border:2px dashed #0284c7;border-radius:18px;text-align:center;background:#f0f9ff;color:#0369a1">
                  <b>📚 ویجت معرفی دوره برگزیده (تکی)</b>
                  <p style="font-size:12px;margin:6px 0 0">هنوز دوره‌ای در سیستم ثبت نشده است. پس از افزودن دوره در پنل، دوره منتخب در این بخش نمایش خواهد یافت.</p>
                </div>
                <?php
            }
            return;
        }

        $courseUrl = SITE_URL . '/course.php?slug=' . urlencode((string)$c['slug']);
        $price = (int)($c['price'] ?? 0);
        $salePrice = (int)($c['sale_price'] ?? 0);
        ?>
        <div class="fr-widget-course-single" style="max-width:1100px;margin:30px auto;padding:0 16px">
          <div style="background:#fff;border-radius:20px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.05);display:flex;flex-wrap:wrap">
            <div style="flex:1;min-width:300px;padding:32px;display:flex;flex-direction:column;justify-content:center">
              <span style="color:#0284c7;font-size:12px;font-weight:800;display:inline-block;margin-bottom:8px">📚 دوره آموزشی ویژه</span>
              <h2 style="font-size:22px;font-weight:900;color:#0f172a;margin:0 0 12px;line-height:1.4"><?= e($c['title']) ?></h2>
              <p style="font-size:13.5px;color:#64748b;line-height:1.8;margin:0 0 18px">
                <?= e((string)($c['short_desc'] ?: mb_strimwidth(strip_tags((string)$c['description']), 0, 150, '...'))) ?>
              </p>
              <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:#475569;margin-bottom:20px">
                <?php if (!empty($c['starts_at'])): ?>
                  <span>📅 تاریخ شروع: <?= fa_num(jdate('j F Y', strtotime($c['starts_at']))) ?></span>
                <?php endif; ?>
                <?php if (!empty($c['location'])): ?>
                  <span>📍 نحوه برگزاری: <?= e($c['location']) ?></span>
                <?php endif; ?>
              </div>
              <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                <div>
                  <?php if ($salePrice > 0 && $salePrice < $price): ?>
                    <del style="font-size:11px;color:#94a3b8"><?= fa_num(number_format($price)) ?> ت</del>
                    <b style="color:#0284c7;font-size:16px"><?= fa_num(number_format($salePrice)) ?> تومان</b>
                  <?php else: ?>
                    <b style="color:#0f172a;font-size:16px"><?= fa_num(number_format($price)) ?> تومان</b>
                  <?php endif; ?>
                </div>
                <a href="<?= e($courseUrl) ?>" class="fr-btn sm primary" style="font-weight:700;padding:10px 20px;border-radius:10px;text-decoration:none">
                  مشاهده سرفصل‌ها و ثبت‌نام ←
                </a>
              </div>
            </div>
            <?php if (!empty($c['image'])): ?>
              <div style="width:340px;min-height:240px;background:#f8fafc;position:relative">
                <img src="<?= e(SITE_URL . '/' . ltrim((string)$c['image'], '/')) ?>" alt="<?= e($c['title']) ?>" style="width:100%;height:100%;object-fit:cover">
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۳: کارگاه‌های روان‌شناسی
     */
    public static function renderWidgetWorkshops(array $params, bool $isEditor): void
    {
        $limit = !empty($params['limit']) ? (int)$params['limit'] : 3;
        $title = (string)($params['title'] ?? '🛠 کارگاه‌های روان‌شناختی تعاملی');
        $subtitle = (string)($params['subtitle'] ?? 'یادگیری تجربی، تحلیل کیس‌های بالینی و پرسش و پاسخ مستقیم با مشاور');

        $workshops = DB::all("SELECT * FROM " . DB::t('events') . " WHERE kind='workshop' AND status='active' ORDER BY starts_at ASC LIMIT $limit");
        ?>
        <div class="fr-widget-workshops-wrapper" style="padding:40px 16px;max-width:1200px;margin:0 auto">
          <?php if (!empty($title)): ?>
            <div style="text-align:center;margin-bottom:30px">
              <h2 style="font-size:24px;font-weight:800;color:#1e293b;margin:0 0 10px"><?= e($title) ?></h2>
              <?php if (!empty($subtitle)): ?><p style="font-size:14px;color:#64748b;margin:0"><?= e($subtitle) ?></p><?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if (empty($workshops)): ?>
            <div style="text-align:center;padding:30px;color:#94a3b8">کارگاهی در حال حاضر فعال نیست.</div>
          <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:24px">
              <?php foreach ($workshops as $w):
                  $wsUrl = SITE_URL . '/workshop.php?slug=' . urlencode((string)$w['slug']);
                  $price = (int)($w['price'] ?? 0);
              ?>
                <div class="fr-workshop-card" style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,0.03);display:flex;flex-direction:column">
                  <?php if (!empty($w['image'])): ?>
                    <div style="height:160px;overflow:hidden;background:#f8fafc">
                      <img src="<?= e(SITE_URL . '/' . ltrim((string)$w['image'], '/')) ?>" alt="<?= e($w['title']) ?>" style="width:100%;height:100%;object-fit:cover">
                    </div>
                  <?php endif; ?>
                  <div style="padding:18px;display:flex;flex-direction:column;flex:1">
                    <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px;line-height:1.5">
                      <a href="<?= e($wsUrl) ?>" style="color:inherit;text-decoration:none"><?= e($w['title']) ?></a>
                    </h3>
                    <p style="font-size:12.5px;color:#64748b;margin:0 0 12px;line-height:1.7;flex:1">
                      <?= e(mb_strimwidth((string)($w['short_desc'] ?: $w['description']), 0, 110, '...')) ?>
                    </p>
                    <div style="font-size:12px;color:#475569;margin-bottom:14px;display:flex;justify-content:space-between">
                      <span>👤 مدرس: <?= e($w['teacher'] ?: 'تیم تخصصی فریا') ?></span>
                      <span>📅 <?= !empty($w['starts_at']) ? fa_num(substr((string)$w['starts_at'], 0, 10)) : 'به‌زودی' ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f1f5f9;padding-top:12px">
                      <span style="color:#0f172a;font-weight:700;font-size:14px"><?= fa_num(number_format($price)) ?> تومان</span>
                      <a href="<?= e($wsUrl) ?>" class="fr-btn sm primary" style="text-decoration:none">ثبت‌نام کارگاه ←</a>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ویجت ۳-ب: معرفی کارگاه روان‌شناسی برگزیده (تکی)
     */
    public static function renderWidgetWorkshopSingle(array $params, bool $isEditor): void
    {
        $wsId = !empty($params['id']) ? (int)$params['id'] : 0;
        $w = $wsId > 0
            ? DB::one("SELECT * FROM " . DB::t('events') . " WHERE id=? AND kind='workshop' AND status='active'", [$wsId])
            : DB::one("SELECT * FROM " . DB::t('events') . " WHERE kind='workshop' AND status='active' ORDER BY starts_at ASC LIMIT 1");

        if (!$w) {
            if ($isEditor) {
                ?>
                <div class="fr-widget-workshop-single" style="max-width:1100px;margin:30px auto;padding:24px;border:2px dashed #059669;border-radius:18px;text-align:center;background:#ecfdf5;color:#047857">
                  <b>🛠 ویجت معرفی کارگاه برگزیده (تکی)</b>
                  <p style="font-size:12px;margin:6px 0 0">هنوز کارگاهی در سیستم ثبت نشده است. پس از تعریف کارگاه، در این محل قرار می‌گیرد.</p>
                </div>
                <?php
            }
            return;
        }

        $wsUrl = SITE_URL . '/workshop.php?slug=' . urlencode((string)$w['slug']);
        $price = (int)($w['price'] ?? 0);
        $salePrice = (int)($w['sale_price'] ?? 0);
        ?>
        <div class="fr-widget-workshop-single" style="max-width:1100px;margin:30px auto;padding:0 16px">
          <div style="background:linear-gradient(135deg, #064e3b, #047857);border-radius:20px;padding:32px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;box-shadow:0 10px 25px rgba(4,120,87,0.25)">
            <div style="flex:1;min-width:280px">
              <span style="background:rgba(255,255,255,0.18);padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;display:inline-block;margin-bottom:10px">
                🛠 کارگاه تعاملی برگزیده
              </span>
              <h2 style="font-size:22px;font-weight:900;margin:0 0 10px"><?= e($w['title']) ?></h2>
              <p style="font-size:13.5px;color:#d1fae5;line-height:1.8;margin:0 0 18px;max-width:600px">
                <?= e((string)($w['short_desc'] ?: mb_strimwidth(strip_tags((string)$w['description']), 0, 140, '...'))) ?>
              </p>
              <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:#a7f3d0;margin-bottom:20px">
                <?php if (!empty($w['starts_at'])): ?>
                  <span>📅 زمان برگزاری: <?= fa_num(jdate('j F Y - H:i', strtotime($w['starts_at']))) ?></span>
                <?php endif; ?>
                <?php if (!empty($w['capacity'])): ?>
                  <span>👥 ظرفیت کارگاه: <?= fa_num($w['capacity']) ?> نفر</span>
                <?php endif; ?>
              </div>
              <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                <a href="<?= e($wsUrl) ?>" class="fr-btn white" style="font-weight:800;padding:10px 24px;border-radius:10px;font-size:13px;text-decoration:none">
                  رزرو صندلی در کارگاه ←
                </a>
                <span style="font-size:15px;font-weight:800">
                  <?php if ($salePrice > 0 && $salePrice < $price): ?>
                    <del style="opacity:.6;font-size:11px"><?= fa_num(number_format($price)) ?></del> <?= fa_num(number_format($salePrice)) ?> تومان
                  <?php else: ?>
                    <?= fa_num(number_format($price)) ?> تومان
                  <?php endif; ?>
                </span>
              </div>
            </div>
            <div style="font-size:75px;background:rgba(255,255,255,0.1);width:130px;height:130px;border-radius:20px;display:flex;align-items:center;justify-content:center">
              🛠
            </div>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۴: رزرو نوبت مشاوره روان‌شناسی
     */
    public static function renderWidgetConsult(array $params, bool $isEditor): void
    {
        $title = (string)($params['title'] ?? '🛋 نوبت‌دهی مشاوره و روان‌درمانی فردی و زوجین');
        $subtitle = (string)($params['subtitle'] ?? 'همراهی مشاوران و روان‌شناسان متخصص در محیطی امن، حرفه‌ای و محرمانه');

        $services = DB::all("SELECT * FROM " . DB::t('consult_services') . " WHERE active=1 ORDER BY sort ASC LIMIT 4");
        ?>
        <div class="fr-widget-consult-wrapper" style="padding:40px 16px;max-width:1200px;margin:0 auto;background:#f8fafc;border-radius:24px;border:1px solid #e2e8f0;margin-top:20px;margin-bottom:20px">
          <div style="text-align:center;margin-bottom:30px">
            <h2 style="font-size:24px;font-weight:800;color:#1e293b;margin:0 0 10px"><?= e($title) ?></h2>
            <p style="font-size:14px;color:#64748b;margin:0"><?= e($subtitle) ?></p>
          </div>

          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));gap:20px;margin-bottom:24px">
            <?php foreach ($services as $s): ?>
              <div style="background:#fff;padding:20px;border-radius:14px;border:1px solid #e2e8f0;text-align:center">
                <div style="font-size:32px;margin-bottom:10px">🛋</div>
                <h4 style="font-size:15.5px;font-weight:700;color:#0f172a;margin:0 0 6px"><?= e($s['title']) ?></h4>
                <p style="font-size:12px;color:#64748b;margin:0 0 12px"><?= e($s['short_desc'] ?: 'مشاوره تخصصی آنلاین و حضوری') ?></p>
                <div style="font-size:13px;font-weight:700;color:#0284c7;margin-bottom:12px">
                  <?= fa_num(number_format((int)$s['price'])) ?> تومان / <?= fa_num($s['duration_min'] ?: 45) ?> دقیقه
                </div>
                <a href="<?= SITE_URL ?>/consult.php?service=<?= (int)$s['id'] ?>" class="fr-btn sm primary" style="width:100%;text-decoration:none">
                  رزرو وقت مشاوره
                </a>
              </div>
            <?php endforeach; ?>
          </div>

          <div style="text-align:center">
            <a href="<?= SITE_URL ?>/consult.php" class="fr-btn ghost sm" style="text-decoration:none">
              مشاهده راهنمای کامل نوبت‌دهی مشاوره ←
            </a>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۴-ب: فهرست مشاوران و درمانگران بالینی کلینیک
     */
    public static function renderWidgetConsultantsList(array $params, bool $isEditor): void
    {
        $title = (string)($params['title'] ?? '🛋 مشاوران و متخصصان بالینی کلینیک');
        $subtitle = (string)($params['subtitle'] ?? 'تیم روان‌درمانگران و متخصصان ارزیابی با شماره عضویت سازمان نظام روان‌شناسی');
        $consultants = DB::all("SELECT * FROM " . DB::t('consultants') . " WHERE active=1 ORDER BY sort ASC");
        ?>
        <div class="fr-widget-consultants-wrap" style="padding:40px 16px;max-width:1200px;margin:0 auto">
          <?php if (!empty($title)): ?>
            <div style="text-align:center;margin-bottom:30px">
              <h2 style="font-size:24px;font-weight:800;color:#1e293b;margin:0 0 10px"><?= e($title) ?></h2>
              <?php if (!empty($subtitle)): ?><p style="font-size:14px;color:#64748b;margin:0"><?= e($subtitle) ?></p><?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if (empty($consultants)): ?>
            <div style="text-align:center;padding:30px;color:#94a3b8">اطلاعات مشاوران به‌زودی بارگذاری می‌شود.</div>
          <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:24px">
              <?php foreach ($consultants as $c): ?>
                <div class="fr-consultant-card" style="background:#fff;border-radius:18px;border:1px solid #e2e8f0;padding:24px;text-align:center;box-shadow:0 6px 18px rgba(0,0,0,0.03);display:flex;flex-direction:column;align-items:center">
                  <div style="width:90px;height:90px;border-radius:50%;overflow:hidden;background:#f1f5f9;margin-bottom:14px;border:3px solid #e0e7ff;box-shadow:0 4px 12px rgba(99,102,241,0.15)">
                    <?php if (!empty($c['avatar'])): ?>
                      <img src="<?= e(SITE_URL . '/' . ltrim((string)$c['avatar'], '/')) ?>" alt="<?= e($c['name']) ?>" style="width:100%;height:100%;object-fit:cover">
                    <?php else: ?>
                      <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px;background:#e0e7ff;color:#4338ca">👤</div>
                    <?php endif; ?>
                  </div>
                  <h3 style="font-size:16px;font-weight:800;color:#0f172a;margin:0 0 6px"><?= e($c['name']) ?></h3>
                  <div style="font-size:12.5px;color:#4338ca;font-weight:700;margin-bottom:10px"><?= e($c['title'] ?: 'روانشناس و مشاور بالینی') ?></div>
                  <p style="font-size:12px;color:#64748b;line-height:1.7;margin:0 0 18px;flex:1">
                    <?= e(mb_strimwidth((string)$c['bio'], 0, 110, '...')) ?>
                  </p>
                  <a href="<?= SITE_URL ?>/consult.php" class="fr-btn sm primary" style="width:100%;text-align:center;padding:8px 16px;border-radius:10px;text-decoration:none;font-weight:700">
                    رزرو نوبت مشاوره ←
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ویجت ۴-ج: بنر دعوت به اقدام و ثبت‌نام
     */
    public static function renderWidgetBannerCta(array $params, bool $isEditor): void
    {
        $title = (string)($params['title'] ?? 'شروع مسیر خودشناسی و سلامت روان');
        $subtitle = (string)($params['subtitle'] ?? 'با انجام تست‌های معتبر روان‌شناسی و مشاوره تخصصی، کیفیت روابط و زندگی فردی خود را ارتقا دهید.');
        $btnText = (string)($params['btn_text'] ?? 'مشاهده تمام آزمون‌ها');
        $btnUrl = (string)($params['btn_url'] ?? (SITE_URL . '/tests.php'));
        ?>
        <div class="fr-widget-banner-cta" style="padding:40px 16px;max-width:1200px;margin:0 auto">
          <div style="background:linear-gradient(135deg, #1e3a8a, #0284c7);border-radius:24px;padding:48px 24px;text-align:center;color:#fff;box-shadow:0 12px 30px rgba(2,132,199,0.25)">
            <h2 style="font-size:26px;font-weight:900;margin:0 0 12px"><?= e($title) ?></h2>
            <p style="font-size:14px;color:#e0f2fe;line-height:1.9;margin:0 auto 24px;max-width:650px"><?= e($subtitle) ?></p>
            <a href="<?= e($btnUrl) ?>" class="fr-btn white" style="font-weight:800;padding:12px 32px;border-radius:12px;font-size:14px;text-decoration:none;display:inline-block;box-shadow:0 6px 20px rgba(0,0,0,0.15)">
              <?= e($btnText) ?> ←
            </a>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۵: باکس عضویت در خبرنامه روان‌شناسی
     */
    public static function renderWidgetNewsletter(array $params, bool $isEditor): void
    {
        $title = (string)($params['title'] ?? '📩 دریافت تحلیل‌های روان‌شناسی و کدهای تخفیف اختصاصی');
        $subtitle = (string)($params['subtitle'] ?? ('با عضویت در خبرنامه ' . fr_brand_title() . '، از جدیدترین مقالات بالینی، تست‌های منتشرشده و تخفیف‌های مناسبتی مطلع شوید.'));
        ?>
        <div class="fr-widget-newsletter-wrapper" style="background:linear-gradient(135deg, #1e1b4b, #312e81);color:#fff;padding:40px 24px;border-radius:20px;max-width:1100px;margin:30px auto;text-align:center;box-shadow:0 10px 25px -5px rgba(49,46,129,0.25)">
          <div style="font-size:36px;margin-bottom:10px">✉️</div>
          <h2 style="font-size:22px;font-weight:800;margin:0 0 10px;color:#fff"><?= e($title) ?></h2>
          <p style="font-size:13.5px;color:#c7d2fe;max-width:650px;margin:0 auto 24px;line-height:1.8"><?= e($subtitle) ?></p>

          <form action="<?= SITE_URL ?>/api/newsletter.php" method="post" id="frWidgetNewsletterForm" style="display:flex;justify-content:center;gap:10px;max-width:540px;margin:0 auto;flex-wrap:wrap">
            <input type="text" name="name" class="fr-input" placeholder="نام و نام خانوادگی (مثال: سارا احمدی)" required
                   style="flex:1;min-width:180px;height:46px;border-radius:10px;border:none;padding:0 14px;font-family:inherit;font-size:13.5px;text-align:right">
            <input type="text" name="mobile" class="fr-input" placeholder="شماره موبایل (مثال: 09123456789)" required
                   style="flex:1;min-width:180px;height:46px;border-radius:10px;border:none;padding:0 14px;font-family:inherit;font-size:13.5px;text-align:right" dir="ltr">
            <button type="submit" class="fr-btn primary" style="height:46px;padding:0 24px;border-radius:10px;background:#38bdf8;color:#0f172a;font-weight:700">
              عضویت رایگان
            </button>
          </form>
          <div id="frWidgetNewsletterMsg" style="display:none;margin-top:14px;font-size:13px;font-weight:700"></div>
        </div>
        <script>
        document.getElementById('frWidgetNewsletterForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const btn = form.querySelector('button');
            const msg = document.getElementById('frWidgetNewsletterMsg');
            const nameVal = form.querySelector('input[name="name"]')?.value.trim() || '';
            const mobVal  = form.querySelector('input[name="mobile"]')?.value.trim() || '';
            if (!mobVal) return;

            btn.disabled = true;
            btn.innerText = 'در حال ثبت...';

            fetch('<?= SITE_URL ?>/api/newsletter.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=subscribe&source=page_builder&name=' + encodeURIComponent(nameVal) + '&mobile=' + encodeURIComponent(mobVal)
            }).then(r => r.json()).then(data => {
                btn.disabled = false;
                btn.innerText = 'عضویت رایگان';
                msg.style.display = 'block';
                if (data.ok) {
                    msg.style.color = '#34d399';
                    msg.innerText = data.message || 'عضویت شما با موفقیت ثبت شد 🎉';
                    form.reset();
                } else {
                    msg.style.color = '#f87171';
                    msg.innerText = data.message || 'خطا در ثبت اطلاعات.';
                }
            }).catch(() => {
                btn.disabled = false;
                btn.innerText = 'عضویت رایگان';
                msg.style.display = 'block';
                msg.style.color = '#f87171';
                msg.innerText = 'خطای ارتباط با سرور.';
            });
        });
        </script>
        <?php
    }

    /**
     * ویجت ۶: دیدگاه‌ها و رضایت مراجعان
     */
    public static function renderWidgetReviews(array $params, bool $isEditor): void
    {
        $limit = !empty($params['limit']) ? (int)$params['limit'] : 3;
        $title = (string)($params['title'] ?? '💬 بازخورد و تجربه مراجعان فریا روان');
        $subtitle = (string)($params['subtitle'] ?? 'دیدگاه‌های واقعی مراجعانی که از خدمات ارزیابی روان‌سنجی و مشاوره‌های ما استفاده کرده‌اند');

        $reviews = DB::all("SELECT * FROM " . DB::t('reviews') . " WHERE status='approved' ORDER BY id DESC LIMIT $limit");
        if (empty($reviews)) {
            // دیدگاه‌های نمونه اگر دیتابیس هنوز دیدگاهی ندارد
            $reviews = [
                ['author_name' => 'مهدی کاظمی', 'rating' => 5, 'comment' => 'کارنامه آزمون DISC فوق‌العاده دقیق بود و نقاط کور رفتاری‌ام را در مدیریت تیم به شفاف‌ترین شکل ممکن به من نشان داد.'],
                ['author_name' => 'سارا ناصری', 'rating' => 5, 'comment' => 'جلسه مشاوره بر پایه فرضیه‌سازی بالینی کارنامه برگزار شد؛ در همان جلسه اول احساس کردم مشاور کاملاً سازوکارهای روانی مرا می‌شناسد.'],
                ['author_name' => 'علیرضا رضوانی', 'rating' => 5, 'comment' => 'تست سازگاری دونفره به همراه پارتنرم را انجام دادیم. نقاط سایش و تمرینات روزانه پلنر رشد به بهبود ارتباطمان کمک شایانی کرد.'],
            ];
        }
        ?>
        <div class="fr-widget-reviews-wrapper" style="padding:40px 16px;max-width:1200px;margin:0 auto">
          <div style="text-align:center;margin-bottom:30px">
            <h2 style="font-size:24px;font-weight:800;color:#1e293b;margin:0 0 10px"><?= e($title) ?></h2>
            <p style="font-size:14px;color:#64748b;margin:0"><?= e($subtitle) ?></p>
          </div>

          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:20px">
            <?php foreach ($reviews as $rev): ?>
              <div style="background:#fff;padding:22px;border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(0,0,0,0.03);display:flex;flex-direction:column">
                <div style="color:#f59e0b;font-size:16px;margin-bottom:10px">
                  <?= str_repeat('⭐', max(1, min(5, (int)($rev['rating'] ?? 5)))) ?>
                </div>
                <p style="font-size:13.5px;color:#334155;line-height:1.8;flex:1;margin:0 0 16px;font-style:italic">
                  «<?= e($rev['comment']) ?>»
                </p>
                <div style="display:flex;align-items:center;gap:10px;border-top:1px solid #f8fafc;padding-top:12px">
                  <div style="width:36px;height:36px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px">
                    <?= e(mb_substr((string)($rev['author_name'] ?? 'م'), 0, 1)) ?>
                  </div>
                  <div>
                    <b style="font-size:13px;color:#0f172a;display:block"><?= e($rev['author_name'] ?: 'مراجع محترم فریا') ?></b>
                    <span style="font-size:11px;color:#94a3b8">مراجع احراز هویت‌شده</span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۷: نمادهای رسمی اعتماد الکترونیکی
     */
    public static function renderWidgetTrustBadges(array $params, bool $isEditor): void
    {
        $title = (string)($params['title'] ?? '🛡 نمادهای اعتماد الکترونیکی و مجوزهای رسمی');
        ?>
        <div class="fr-widget-trust-wrapper" style="padding:30px 16px;max-width:900px;margin:20px auto;text-align:center">
          <h4 style="font-size:14px;color:#64748b;font-weight:700;margin:0 0 18px"><?= e($title) ?></h4>
          <div style="display:flex;justify-content:center;align-items:center;gap:24px;flex-wrap:wrap">
            <div style="background:#fff;padding:12px 18px;border-radius:12px;border:1px solid #e2e8f0;display:inline-flex;align-items:center;gap:10px;box-shadow:0 2px 8px rgba(0,0,0,0.02)">
              <img src="<?= SITE_URL ?>/assets/img/badges/enamad.svg" alt="اینماد" style="height:36px;width:auto">
              <span style="font-size:12px;font-weight:700;color:#334155">نماد تجارت الکترونیک (اینماد)</span>
            </div>
            <div style="background:#fff;padding:12px 18px;border-radius:12px;border:1px solid #e2e8f0;display:inline-flex;align-items:center;gap:10px;box-shadow:0 2px 8px rgba(0,0,0,0.02)">
              <img src="<?= SITE_URL ?>/assets/img/badges/samandehi.svg" alt="ساماندهی" style="height:36px;width:auto">
              <span style="font-size:12px;font-weight:700;color:#334155">مرکز فناوری اطلاعات (ساماندهی)</span>
            </div>
            <div style="background:#fff;padding:12px 18px;border-radius:12px;border:1px solid #e2e8f0;display:inline-flex;align-items:center;gap:10px;box-shadow:0 2px 8px rgba(0,0,0,0.02)">
              <img src="<?= SITE_URL ?>/assets/img/badges/zarinpal.svg" alt="زرین‌پال" style="height:36px;width:auto">
              <span style="font-size:12px;font-weight:700;color:#334155">درگاه پرداخت امن و معتبر</span>
            </div>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۸: هیرو سکشن اصلی کلینیک
     */
    public static function renderWidgetHero(array $params, bool $isEditor): void
    {
        $title    = (string)($params['title'] ?? 'سامانه جامع آزمون‌های روان‌شناختی، کارگاه‌ها و نوبت‌دهی مشاوره');
        $subtitle = (string)($params['subtitle'] ?? 'فریا روان، مرجع علمی روان‌سنجی معتبر، خودشناسی چندبعدی و جلسات مشاوره تخصصی زیر نظر درمانگران متخصص است.');
        ?>
        <div class="fr-widget-hero" style="background:radial-gradient(ellipse at top, #1e1b4b, #0f172a);color:#fff;padding:65px 20px 55px;text-align:center;border-radius:24px;margin:10px auto 30px;max-width:1200px;box-shadow:0 15px 30px -10px rgba(15,23,42,0.4)">
          <div style="display:inline-block;background:rgba(99,102,241,0.2);color:#a5b4fc;border:1px solid rgba(165,180,252,0.3);padding:4px 14px;border-radius:30px;font-size:12px;font-weight:700;margin-bottom:18px">
            ✨ ارتقا به نگارش ۳٫۶٫۰ — مجهز به موتور اختصاصی صفحه‌ساز
          </div>
          <h1 style="font-size:28px;font-weight:900;color:#fff;margin:0 auto 16px;max-width:850px;line-height:1.5">
            <?= e($title) ?>
          </h1>
          <p style="font-size:15px;color:#cbd5e1;max-width:720px;margin:0 auto 30px;line-height:1.8">
            <?= e($subtitle) ?>
          </p>
          <div style="display:flex;justify-content:center;gap:14px;flex-wrap:wrap">
            <a href="<?= SITE_URL ?>/tests.php" class="fr-btn primary" style="padding:12px 28px;border-radius:12px;font-size:14px;font-weight:700;text-decoration:none">
              🎯 شروع آزمون‌های روان‌شناسی
            </a>
            <a href="<?= SITE_URL ?>/consult.php" class="fr-btn ghost" style="padding:12px 28px;border-radius:12px;font-size:14px;font-weight:700;text-decoration:none;border-color:rgba(255,255,255,0.25);color:#fff">
              🛋 رزرو نوبت مشاوره آنلاین
            </a>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۹: شمارنده‌های آماری کلینیک
     */
    public static function renderWidgetStats(array $params, bool $isEditor): void
    {
        ?>
        <div class="fr-widget-stats" style="padding:20px 16px;max-width:1100px;margin:0 auto 30px">
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;text-align:center">
            <div style="background:#fff;padding:20px;border-radius:14px;border:1px solid #e2e8f0">
              <div style="font-size:26px;font-weight:900;color:#4f46e5;margin-bottom:4px">+۵۰,۰۰۰</div>
              <div style="font-size:12.5px;color:#64748b">آزمون روان‌سنجی تکمیل‌شده</div>
            </div>
            <div style="background:#fff;padding:20px;border-radius:14px;border:1px solid #e2e8f0">
              <div style="font-size:26px;font-weight:900;color:#0284c7;margin-bottom:4px">+۱۰,۰۰۰</div>
              <div style="font-size:12.5px;color:#64748b">مراجع فعال و رضایت‌مند</div>
            </div>
            <div style="background:#fff;padding:20px;border-radius:14px;border:1px solid #e2e8f0">
              <div style="font-size:26px;font-weight:900;color:#059669;margin-bottom:4px">۹۸٪</div>
              <div style="font-size:12.5px;color:#64748b">انطباق کارنامه و رضایت</div>
            </div>
            <div style="background:#fff;padding:20px;border-radius:14px;border:1px solid #e2e8f0">
              <div style="font-size:26px;font-weight:900;color:#d97706;margin-bottom:4px">+۱۵</div>
              <div style="font-size:12.5px;color:#64748b">روان‌درمانگر و مشاور متخصص</div>
            </div>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۱۰: پرسش‌های متداول آکاردئونی
     */
    public static function renderWidgetFaq(array $params, bool $isEditor): void
    {
        $faqs = [
            ['q' => 'آیا آزمون‌های فریا روان از نظر علمی معتبر هستند؟', 'a' => 'بله؛ تمامی آزمون‌های سامانه بر پایه مقیاس‌های استاندارد بین‌المللی روان‌سنجی (مانند MMPI، MBTI، DISC، YSQ، بار-آن و هالند) پیاده‌سازی شده و نرم‌سازی کشوری بر روی آن‌ها اعمال شده است.'],
            ['q' => 'کارنامه تحلیلی چه زمانی صادر می‌شود؟', 'a' => 'به محض پاسخ‌دهی به آخرین پرسش آزمون، موتور نمره‌گذاری و تحلیل بالینی سیستم به طور آنی کارنامه تفصیلی و نمودارهای شما را تولید کرده و در پنل کاربری ذخیره می‌نماید.'],
            ['q' => 'آیا اطلاعات مراجعان محرمانه می‌ماند؟', 'a' => 'کاملاً. فریا روان به اصول اخلاق حرفه‌ای سازمان نظام روان‌شناسی پایبند است و تمام داده‌ها در سرورهای امن نگهداری شده و در دسترس اشخاص ثالث قرار نمی‌گیرد.'],
            ['q' => 'آیا امکان بررسی نتایج با یک مشاور وجود دارد؟', 'a' => 'بله؛ پس از دریافت کارنامه می‌توانید از بخش مشاوره، جلسه تکمیلی با متخصص مدنظرتان را رزرو کنید تا روان‌شناس با استناد به نتایج آزمون، برنامه درمانی را تدوین کند.'],
        ];
        ?>
        <div class="fr-widget-faq" style="padding:35px 16px;max-width:900px;margin:0 auto">
          <div style="text-align:center;margin-bottom:28px">
            <h2 style="font-size:22px;font-weight:800;color:#1e293b;margin:0 0 8px">❓ پرسش‌های متداول مراجعان</h2>
            <p style="font-size:13.5px;color:#64748b;margin:0">پاسخ به سوالات متداول پیرامون آزمون‌ها، تفسیر کارنامه و نوبت‌دهی مشاوره</p>
          </div>
          <div style="display:flex;flex-direction:column;gap:12px">
            <?php foreach ($faqs as $idx => $f): ?>
              <details style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;cursor:pointer">
                <summary style="font-size:14.5px;font-weight:700;color:#1e293b;list-style:none;display:flex;justify-content:space-between;align-items:center">
                  <span><?= e($f['q']) ?></span>
                  <span style="font-size:18px;color:#94a3b8">▾</span>
                </summary>
                <div style="font-size:13px;color:#475569;line-height:1.8;margin-top:12px;padding-top:10px;border-top:1px solid #f1f5f9">
                  <?= e($f['a']) ?>
                </div>
              </details>
            <?php endforeach; ?>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۱۱: کارت‌های ویژگی و مزایای بالینی
     */
    public static function renderWidgetFeatures(array $params, bool $isEditor): void
    {
        ?>
        <div class="fr-widget-features" style="padding:35px 16px;max-width:1200px;margin:0 auto">
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:20px">
            <div style="background:#fff;padding:24px;border-radius:16px;border:1px solid #e2e8f0;text-align:center">
              <div style="width:50px;height:50px;border-radius:14px;background:#e0e7ff;color:#4f46e5;font-size:24px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">🔬</div>
              <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px">ارزیابی عمیق روان‌سنجی</h3>
              <p style="font-size:12.5px;color:#64748b;line-height:1.7;margin:0">تک‌نگاری‌های تخصصی در سطح دکتری روان‌شناسی برای تمامی مقیاس‌ها و شاخص‌ها.</p>
            </div>
            <div style="background:#fff;padding:24px;border-radius:16px;border:1px solid #e2e8f0;text-align:center">
              <div style="width:50px;height:50px;border-radius:14px;background:#dbeafe;color:#0284c7;font-size:24px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">🩺</div>
              <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px">فرمول‌بندی تشخیصی PDM-2</h3>
              <p style="font-size:12.5px;color:#64748b;line-height:1.7;margin:0">واکاوی خودکار ساختار ایگو، مکانیسم‌های دفاعی فعال و راهنمای واکنش درمانگر.</p>
            </div>
            <div style="background:#fff;padding:24px;border-radius:16px;border:1px solid #e2e8f0;text-align:center">
              <div style="width:50px;height:50px;border-radius:14px;background:#dcfce7;color:#059669;font-size:24px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">🌱</div>
              <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px">پلنر رشد و تمرینات روزانه</h3>
              <p style="font-size:12.5px;color:#64748b;line-height:1.7;margin:0">تزریق مستقیم تکالیف رشد شناختی-رفتاری به برنامه روزمره مراجع بر پایه نتایج.</p>
            </div>
            <div style="background:#fff;padding:24px;border-radius:16px;border:1px solid #e2e8f0;text-align:center">
              <div style="width:50px;height:50px;border-radius:14px;background:#fef3c7;color:#d97706;font-size:24px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px">🔒</div>
              <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px">امنیت کامل و محرمانگی</h3>
              <p style="font-size:12.5px;color:#64748b;line-height:1.7;margin:0">رمزنگاری داده‌ها، تفکیک دسترسی‌ها و رعایت دقیق اصول رازداری بالینی.</p>
            </div>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت ۱۲: اطلاعات تماس و کانال‌های ارتباطی
     */
    public static function renderWidgetContactBox(array $params, bool $isEditor): void
    {
        $tel = fr_brand_support_phone() ?: (string)DB::setting('contact_phone', '۰۲۱-۸۸۸۸۸۸۸۸');
        $addr = (string)DB::setting('contact_address', 'تهران، میدان ونک، خیابان ملاصدرا');
        ?>
        <div class="fr-widget-contact-box" style="padding:30px 16px;max-width:900px;margin:20px auto;background:#fff;border-radius:16px;border:1px solid #e2e8f0;text-align:center">
          <h3 style="font-size:18px;font-weight:800;color:#1e293b;margin:0 0 8px">📞 ارتباط با <?= e(fr_brand_title()) ?></h3>
          <p style="font-size:13px;color:#64748b;margin:0 0 16px">همکاران ما در روزهای کاری پاسخگوی سوالات شما هستند</p>
          <div style="display:flex;justify-content:center;gap:20px;flex-wrap:wrap;font-size:13px;color:#334155;margin-bottom:16px">
            <div>📍 <b>نشانی:</b> <?= e($addr) ?></div>
            <div>☎️ <b>تلفن تماس:</b> <a href="tel:<?= e(preg_replace('/[^\d\+]/', '', $tel)) ?>" style="color:#0284c7;text-decoration:none"><?= e($tel) ?></a></div>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت سربرگ ۱: لوگو و نشان رسمی سایت
     */
    public static function renderWidgetSiteLogo(array $params, bool $isEditor): void
    {
        $site   = fr_brand_title();
        $slogan = fr_brand_slogan();
        ?>
        <div class="fr-widget-logo" style="display:inline-flex;align-items:center;gap:10px">
          <a href="<?= SITE_URL ?>/" style="display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:inherit">
            <?= fr_logo_html('fr-logo-mark') ?>
            <div>
              <b style="font-size:16px;color:#0f172a;display:block;line-height:1.3"><?= e($site) ?></b>
              <?php if (!empty($slogan)): ?>
                <small style="font-size:11px;color:#64748b;display:block"><?= e($slogan) ?></small>
              <?php endif; ?>
            </div>
          </a>
        </div>
        <?php
    }

    /**
     * ویجت سربرگ ۲: منوی ناوبری داینامیک از فهرست‌ساز
     */
    public static function renderWidgetMenu(array $params, bool $isEditor): void
    {
        $loc = $params['location'] ?? ($params['menu_id'] ?? 'primary');
        echo MenuBuilder::renderMenu($loc, [
            'class' => (string)($params['class'] ?? 'fr-nav-menu'),
        ]);
    }

    /**
     * ویجت سربرگ ۳: دکمه‌های ورود و پنل کاربری مراجع
     */
    public static function renderWidgetUserAuth(array $params, bool $isEditor): void
    {
        $u = function_exists('current_user') ? current_user() : null;
        ?>
        <div class="fr-widget-auth-box" style="display:inline-flex;align-items:center;gap:8px">
          <?php if ($u): ?>
            <a href="<?= function_exists('fr_panel_url') ? fr_panel_url() : (SITE_URL . '/panel/') ?>" class="fr-btn sm" style="background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;font-weight:700;display:inline-flex;align-items:center;gap:6px;text-decoration:none">
              <span>👤</span>
              <span><?= e(function_exists('user_fullname') ? user_fullname($u) : ($u['display_name'] ?? 'پنل کاربری')) ?></span>
            </a>
          <?php else: ?>
            <a href="<?= SITE_URL ?>/auth.php" class="fr-btn sm ghost" style="text-decoration:none;font-weight:700">ورود</a>
            <a href="<?= SITE_URL ?>/auth.php?action=register" class="fr-btn sm primary" style="text-decoration:none;font-weight:700">ثبت‌نام</a>
          <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ویجت سربرگ ۴: دکمه فراخوان به اقدام (Header CTA)
     */
    public static function renderWidgetHeaderCta(array $params, bool $isEditor): void
    {
        $text = (string)($params['text'] ?? 'رزرو وقت مشاوره 🛋');
        $url  = (string)($params['url'] ?? (SITE_URL . '/consult.php'));
        ?>
        <a href="<?= e($url) ?>" class="fr-btn sm primary" style="font-weight:700;padding:8px 18px;border-radius:10px;text-decoration:none;box-shadow:0 4px 12px rgba(59,130,246,0.25)">
          <?= e($text) ?>
        </a>
        <?php
    }

    /**
     * ویجت سربرگ ۵: نوار جستجوی سریع آزمون‌ها
     */
    public static function renderWidgetSearchBar(array $params, bool $isEditor): void
    {
        $placeholder = (string)($params['placeholder'] ?? 'جستجوی تست روانشناسی یا دوره...');
        ?>
        <form action="<?= SITE_URL ?>/tests.php" method="get" class="fr-widget-search-bar" style="display:inline-flex;align-items:center;position:relative;max-width:320px;width:100%">
          <input type="text" name="q" placeholder="<?= e($placeholder) ?>" class="fr-input sm" style="width:100%;padding-right:34px;height:38px;border-radius:10px;font-size:12.5px">
          <button type="submit" style="position:absolute;right:8px;background:transparent;border:none;cursor:pointer;font-size:14px;color:#64748b" aria-label="جستجو">🔍</button>
        </form>
        <?php
    }

    /**
     * ویجت سربرگ ۶: اطلاعات تماس نوار بالای سایت
     */
    public static function renderWidgetTopbarContact(array $params, bool $isEditor): void
    {
        $phone = (string)DB::setting('contact_phone', '۰۲۱-۸۸۸۸۸۸۸۸');
        $hours = (string)DB::setting('work_hours', 'شنبه تا چهارشنبه: ۸ الی ۲۰');
        ?>
        <div class="fr-widget-topbar-contact" style="display:flex;align-items:center;gap:16px;font-size:12px;color:#64748b">
          <div>📞 پشتیبانی: <a href="tel:<?= e(preg_replace('/[^\d\+]/', '', $phone)) ?>" style="color:#0284c7;text-decoration:none;direction:ltr"><?= e($phone) ?></a></div>
          <div style="opacity:0.8">⏰ <?= e($hours) ?></div>
        </div>
        <?php
    }

    /**
     * ویجت پاورقی ۷: ستون برند و بیوگرافی کلینیک
     */
    public static function renderWidgetFooterBrand(array $params, bool $isEditor): void
    {
        $site = (string)DB::setting('site_title', 'فریا روان');
        $bio  = (string)DB::setting('logo_desc', 'مرکز خدمات روان‌شناسی و مشاوره فریا روان با کادری مجرب از متخصصان بالینی، آماده ارائه خدمات ارزیابی روان‌سنجی و مشاوره‌های فردی، زوجین و شغلی است.');
        ?>
        <div class="fr-widget-footer-brand" style="margin-bottom:20px">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
            <?= fr_logo_html('fr-footer-logo-mark') ?>
            <b style="font-size:18px;color:#fff"><?= e($site) ?></b>
          </div>
          <p style="font-size:13px;color:#94a3b8;line-height:1.9;margin:0"><?= e($bio) ?></p>
        </div>
        <?php
    }

    /**
     * ویجت پاورقی ۸: منوی پیوندهای پاورقی
     */
    public static function renderWidgetFooterMenu(array $params, bool $isEditor): void
    {
        $loc = $params['location'] ?? ($params['menu_id'] ?? 'footer');
        echo MenuBuilder::renderMenu($loc, [
            'class' => 'fr-footer-nav-menu',
        ]);
    }

    /**
     * ویجت پاورقی ۹: نشانی، تلفن و ساعات کاری
     */
    public static function renderWidgetFooterContact(array $params, bool $isEditor): void
    {
        $phone = (string)DB::setting('contact_phone', '۰۲۱-۸۸۸۸۸۸۸۸');
        $addr  = (string)DB::setting('contact_address', 'تهران، میدان ونک، خیابان ملاصدرا');
        $email = (string)DB::setting('contact_email', 'info@feria-ravan.ir');
        ?>
        <div class="fr-widget-footer-contact" style="font-size:13px;line-height:2.2;color:#94a3b8">
          <h3 style="font-size:15px;font-weight:700;color:#38bdf8;margin:0 0 12px">راه‌های ارتباطی</h3>
          <div>📍 نشانی: <?= e($addr) ?></div>
          <div>☎️ تلفن: <a href="tel:<?= e(preg_replace('/[^\d\+]/', '', $phone)) ?>" style="color:#e2e8f0;text-decoration:none"><?= e($phone) ?></a></div>
          <div>✉️ ایمیل: <a href="mailto:<?= e($email) ?>" style="color:#e2e8f0;text-decoration:none"><?= e($email) ?></a></div>
        </div>
        <?php
    }

    /**
     * ویجت پاورقی ۱۰: آیکون‌های شبکه‌های اجتماعی
     */
    public static function renderWidgetSocialIcons(array $params, bool $isEditor): void
    {
        $tg = (string)DB::setting('social_telegram', 'https://t.me/feriaravan');
        $ig = (string)DB::setting('social_instagram', 'https://instagram.com/feriaravan');
        $wa = (string)DB::setting('social_whatsapp', '');
        $ba = (string)DB::setting('social_bale', '');
        ?>
        <div class="fr-widget-socials" style="display:inline-flex;align-items:center;gap:10px">
          <?php if ($tg): ?><a href="<?= e($tg) ?>" target="_blank" rel="noopener" style="width:34px;height:34px;border-radius:10px;background:#1e293b;display:inline-flex;align-items:center;justify-content:center;color:#38bdf8;text-decoration:none" title="تلگرام">✈️</a><?php endif; ?>
          <?php if ($ig): ?><a href="<?= e($ig) ?>" target="_blank" rel="noopener" style="width:34px;height:34px;border-radius:10px;background:#1e293b;display:inline-flex;align-items:center;justify-content:center;color:#f43f5e;text-decoration:none" title="اینستاگرام">📷</a><?php endif; ?>
          <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" style="width:34px;height:34px;border-radius:10px;background:#1e293b;display:inline-flex;align-items:center;justify-content:center;color:#10b981;text-decoration:none" title="واتساپ">💬</a><?php endif; ?>
          <?php if ($ba): ?><a href="<?= e($ba) ?>" target="_blank" rel="noopener" style="width:34px;height:34px;border-radius:10px;background:#1e293b;display:inline-flex;align-items:center;justify-content:center;color:#10b981;text-decoration:none" title="بله">🍃</a><?php endif; ?>
        </div>
        <?php
    }

    /**
     * ویجت پاورقی ۱۱: نمادهای رسمی اعتماد الکترونیکی
     */
    public static function renderWidgetFooterBadges(array $params, bool $isEditor): void
    {
        ?>
        <div class="fr-widget-footer-badges">
          <h3 style="font-size:15px;font-weight:700;color:#38bdf8;margin:0 0 12px">نمادهای اعتماد</h3>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <div style="width:75px;height:75px;background:#1e293b;border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;text-align:center;padding:6px;border:1px solid #334155">
              <span style="font-size:22px">🛡</span>
              <span style="margin-top:2px">اینماد</span>
            </div>
            <div style="width:75px;height:75px;background:#1e293b;border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;text-align:center;padding:6px;border:1px solid #334155">
              <span style="font-size:22px">🏛</span>
              <span style="margin-top:2px">ساماندهی</span>
            </div>
            <div style="width:75px;height:75px;background:#1e293b;border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;text-align:center;padding:6px;border:1px solid #334155">
              <span style="font-size:22px">💳</span>
              <span style="margin-top:2px">زرین‌پال</span>
            </div>
          </div>
        </div>
        <?php
    }

    /**
     * ویجت پاورقی ۱۲: متن کپی‌رایت و حقوق نشر
     */
    public static function renderWidgetCopyright(array $params, bool $isEditor): void
    {
        $site = (string)DB::setting('site_title', 'فریا روان');
        ?>
        <div class="fr-widget-copyright" style="font-size:12px;color:#94a3b8">
          <span>© <?= fa_num(jdate('Y')) ?> تمامی حقوق مادی و معنوی متعلق به <?= e($site) ?> می‌باشد.</span>
        </div>
        <?php
    }

    /**
     * دریافت کاتالوگ تمام ویجت‌ها جهت معرفی در نوار ابزار بلوک‌های GrapesJS
     */
    public static function getAvailableWidgets(): array
    {
        return [
            [
                'id'          => 'tests',
                'name'        => 'tests',
                'title'       => 'آزمون‌های روان‌شناسی',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🧠',
                'description' => 'شبکه کارتی آزمون‌های روان‌سنجی همراه با قیمت، تعداد سوال و دکمه شروع',
                'content'     => '<div data-widget="tests" data-limit="6" data-category="all" class="fr-pb-block fr-pb-widget-tests"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>🧠 ویجت آزمون‌های روان‌شناسی فریا روان</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">در نمایش زنده، کارت‌های آخرین آزمون‌های دیتابیس جایگزین این بخش می‌شوند.</p></div></div>'
            ],
            [
                'id'          => 'tests_catalog',
                'name'        => 'tests_catalog',
                'title'       => 'کاتالوگ جامع تست‌ها',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '📑',
                'description' => 'کاتالوگ کامل با تب‌های بالینی، شخصیت، هوش و فیلترها',
                'content'     => '<div data-widget="tests_catalog" data-limit="12" class="fr-pb-block fr-pb-widget-tests-catalog"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>📑 کاتالوگ جامع آزمون‌های روان‌شناسی</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">نمایش کارت‌های تست با تب‌های دسته‌بندی و فیلتر هوشمند.</p></div></div>'
            ],
            [
                'id'          => 'test_single',
                'name'        => 'test_single',
                'title'       => 'معرفی آزمون ویژه (تکی)',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🎯',
                'description' => 'بنر برجسته معرفی یک آزمون خاص با دکمه شروع و قیمت',
                'content'     => '<div data-widget="test_single" class="fr-pb-block fr-pb-widget-test-single"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>🎯 ویجت معرفی آزمون برگزیده</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">بنر جذاب آزمون شاخص همراه با خلاصه بالینی و دکمه شروع.</p></div></div>'
            ],
            [
                'id'          => 'courses',
                'name'        => 'courses',
                'title'       => 'دوره‌های آموزشی',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '📚',
                'description' => 'فهرست دوره‌های آموزشی روان‌شناسی با تصویر، مدرس و قیمت',
                'content'     => '<div data-widget="courses" data-limit="3" class="fr-pb-block fr-pb-widget-courses"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>📚 ویجت دوره‌های آموزشی روان‌شناسی</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">در نمایش زنده، کارت‌های دوره‌های آموزشی فعال نمایش داده خواهند شد.</p></div></div>'
            ],
            [
                'id'          => 'course_single',
                'name'        => 'course_single',
                'title'       => 'معرفی دوره ویژه (تکی)',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🎓',
                'description' => 'باکس معرفی یک دوره آموزشی خاص با تصویر، سرفصل و ثبت‌نام',
                'content'     => '<div data-widget="course_single" class="fr-pb-block fr-pb-widget-course-single"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>🎓 ویجت معرفی دوره برگزیده</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">بنر دوره آموزشی همراه با کاور، مدرس، ساعت آموزش و ثبت‌نام.</p></div></div>'
            ],
            [
                'id'          => 'workshops',
                'name'        => 'workshops',
                'title'       => 'کارگاه‌های تعاملی',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🛠',
                'description' => 'کارگاه‌های روان‌شناختی همراه با تاریخ برگزاری و ظرفیت',
                'content'     => '<div data-widget="workshops" data-limit="3" class="fr-pb-block fr-pb-widget-workshops"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>🛠 ویجت کارگاه‌های روان‌شناختی</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">در نمایش زنده، جدیدترین کارگاه‌های فعال لود می‌شوند.</p></div></div>'
            ],
            [
                'id'          => 'workshop_single',
                'name'        => 'workshop_single',
                'title'       => 'معرفی کارگاه ویژه (تکی)',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🏷️',
                'description' => 'باکس معرفی یک کارگاه خاص با زمان برگزاری، ظرفیت و ثبت‌نام',
                'content'     => '<div data-widget="workshop_single" class="fr-pb-block fr-pb-widget-workshop-single"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>🏷️ ویجت معرفی کارگاه برگزیده</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">بنر کارگاه عملی با زمان‌بندی و دکمه رزرو صندلی.</p></div></div>'
            ],
            [
                'id'          => 'consult',
                'name'        => 'consult',
                'title'       => 'رزرو وقت مشاوره',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🛋',
                'description' => 'بخش نوبت‌دهی خدمات مشاوره فردی، زوجین و خانواده',
                'content'     => '<div data-widget="consult" class="fr-pb-block fr-pb-widget-consult"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>🛋 ویجت رزرو وقت مشاوره روان‌شناسی</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">کارت‌های خدمات مشاوره به همراه هزینه و زمان‌بندی جلسه.</p></div></div>'
            ],
            [
                'id'          => 'consultants_list',
                'name'        => 'consultants_list',
                'title'       => 'مشاوران و روان‌درمانگران',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '👥',
                'description' => 'فهرست مشاوران و متخصصان کلینیک با آواتار و دکمه رزرو',
                'content'     => '<div data-widget="consultants_list" class="fr-pb-block fr-pb-widget-consultants"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>👥 ویجت کادر مشاوران و درمانگران کلینیک</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">کارت‌های معرفی مشاوران همراه با بیوگرافی و رزرو نوبت.</p></div></div>'
            ],
            [
                'id'          => 'banner_cta',
                'name'        => 'banner_cta',
                'title'       => 'بنر دعوت به اقدام (CTA)',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '📣',
                'description' => 'بنر جذاب تمام‌عرض با تیتر، توضیحات و دکمه ثبت‌نام یا شروع',
                'content'     => '<div data-widget="banner_cta" class="fr-pb-block fr-pb-widget-cta"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>📣 ویجت بنر دعوت به اقدام (Call To Action)</b></div></div>'
            ],
            [
                'id'          => 'newsletter',
                'name'        => 'newsletter',
                'title'       => 'عضویت در خبرنامه',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '📩',
                'description' => 'فرم شکیل عضویت پیامکی/ایمیلی با ارسال AJAX',
                'content'     => '<div data-widget="newsletter" class="fr-pb-block fr-pb-widget-newsletter"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>📩 ویجت فرم عضویت در خبرنامه</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">فرم تعاملی عضویت با ثبت مستقیم در دیتابیس.</p></div></div>'
            ],
            [
                'id'          => 'reviews',
                'name'        => 'reviews',
                'title'       => 'نظرات مراجعان',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '⭐',
                'description' => 'کارت‌های دیدگاه‌ها، ستاره‌های رضایت و تجربیات مراجعین',
                'content'     => '<div data-widget="reviews" data-limit="3" class="fr-pb-block fr-pb-widget-reviews"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>⭐ ویجت دیدگاه‌ها و رضایت مراجعان</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">نظرات تأییدشده همراه با رتبه ۵ ستاره مراجعان.</p></div></div>'
            ],
            [
                'id'          => 'trust_badges',
                'name'        => 'trust_badges',
                'title'       => 'نمادهای اعتماد الکترونیکی',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🛡',
                'description' => 'نمادهای رسمی اینماد، ساماندهی و زرین‌پال',
                'content'     => '<div data-widget="trust_badges" class="fr-pb-block fr-pb-widget-trust"><div class="fr-widget-placeholder" style="padding:24px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>🛡 ویجت نمادهای رسمی اعتماد (اینماد، ساماندهی، زرین‌پال)</b></div></div>'
            ],
            [
                'id'          => 'hero',
                'name'        => 'hero',
                'title'       => 'هیرو بنر اصلی',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🎯',
                'description' => 'بخش سربرگ جذاب با دکمه‌های دوتایی شروع آزمون و مشاوره',
                'content'     => '<div data-widget="hero" class="fr-pb-block fr-pb-widget-hero"><div class="fr-widget-placeholder" style="padding:40px;text-align:center;background:#1e1b4b;color:#fff;border-radius:16px;font-family:inherit"><h2>🎯 هیرو سکشن معرفی کلینیک فریا روان</h2><p>سربرگ جذاب خوش‌آمدگویی به همراه دکمه‌های فراخوان اقدام</p></div></div>'
            ],
            [
                'id'          => 'stats',
                'name'        => 'stats',
                'title'       => 'شمارنده‌های آماری',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '📊',
                'description' => 'باکس ۴ تایی آمار آزمون‌ها، مراجعان و رضایت‌مندی',
                'content'     => '<div data-widget="stats" class="fr-pb-block fr-pb-widget-stats"><div class="fr-widget-placeholder" style="padding:24px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>📊 ویجت شمارنده‌های آماری کلینیک</b></div></div>'
            ],
            [
                'id'          => 'faq',
                'name'        => 'faq',
                'title'       => 'پرسش‌های متداول (FAQ)',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '❓',
                'description' => 'آکاردئون پرسش‌ها و پاسخ‌های متداول روان‌شناسی',
                'content'     => '<div data-widget="faq" class="fr-pb-block fr-pb-widget-faq"><div class="fr-widget-placeholder" style="padding:24px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>❓ ویجت آکاردئونی پرسش‌های متداول</b></div></div>'
            ],
            [
                'id'          => 'features',
                'name'        => 'features',
                'title'       => 'مزایا و ویژگی‌های بالینی',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '✨',
                'description' => 'کارت‌های ۴ گانه خدمات و ارزش‌های بنیادین کلینیک',
                'content'     => '<div data-widget="features" class="fr-pb-block fr-pb-widget-features"><div class="fr-widget-placeholder" style="padding:24px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>✨ ویجت کارت‌های مزایا و امکانات کلینیک</b></div></div>'
            ],
            [
                'id'          => 'contact_box',
                'name'        => 'contact_box',
                'title'       => 'اطلاعات تماس و آدرس',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '📍',
                'description' => 'نشانی، تلفن، ساعات پاسخگویی و شبکه‌های اجتماعی',
                'content'     => '<div data-widget="contact_box" class="fr-pb-block fr-pb-widget-contact"><div class="fr-widget-placeholder" style="padding:24px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>📍 ویجت اطلاعات تماس، آدرس و شبکه‌ها</b></div></div>'
            ],

            // ویجت‌های اختصاصی سربرگ (Header Widgets)
            [
                'id'          => 'site_logo',
                'name'        => 'site_logo',
                'title'       => 'لوگوی سایت و عنوان',
                'category'    => 'ویجت‌های سربرگ (Header)',
                'icon'        => '🏷️',
                'description' => 'نشان رسمی کلینیک فریا روان به همراه نام و شعار سازمانی',
                'content'     => '<div data-widget="site_logo" class="fr-pb-block fr-pb-widget-logo"><div class="fr-widget-placeholder" style="padding:16px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:10px;color:#1e40af"><b>🏷️ ویجت لوگو و برند سایت</b></div></div>'
            ],
            [
                'id'          => 'menu',
                'name'        => 'menu',
                'title'       => 'منوی ناوبری اصلی',
                'category'    => 'ویجت‌های سربرگ (Header)',
                'icon'        => '📋',
                'description' => 'منوی آبشاری و ریسپانسیو ایجادشده با فهرست‌ساز',
                'content'     => '<div data-widget="menu" data-location="primary" class="fr-pb-block fr-pb-widget-menu"><div class="fr-widget-placeholder" style="padding:16px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:10px;color:#1e40af"><b>📋 ویجت منوی ناوبری سربرگ</b><p style="margin:4px 0 0;font-size:11px;color:#64748b">نمایش خودکار گزینه‌های منوی فعال سربرگ همراه با زیرمنوهای آبشاری</p></div></div>'
            ],
            [
                'id'          => 'user_auth',
                'name'        => 'user_auth',
                'title'       => 'ورود و حساب کاربری',
                'category'    => 'ویجت‌های سربرگ (Header)',
                'icon'        => '👤',
                'description' => 'دکمه‌های ورود/ثبت‌نام یا پروفایل و پنل مراجع',
                'content'     => '<div data-widget="user_auth" class="fr-pb-block fr-pb-widget-auth"><div class="fr-widget-placeholder" style="padding:14px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:10px;color:#1e40af"><b>👤 ویجت دکمه‌های حساب کاربری و ورود</b></div></div>'
            ],
            [
                'id'          => 'header_cta',
                'name'        => 'header_cta',
                'title'       => 'دکمه فراخوان سربرگ (CTA)',
                'category'    => 'ویجت‌های سربرگ (Header)',
                'icon'        => '🔘',
                'description' => 'دکمه برجسته رزرو نوبت مشاوره یا شروع آزمون',
                'content'     => '<div data-widget="header_cta" data-text="رزرو وقت مشاوره 🛋" class="fr-pb-block fr-pb-widget-cta"><div class="fr-widget-placeholder" style="padding:14px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:10px;color:#1e40af"><b>🔘 ویجت دکمه فراخوان به اقدام (رزرو مشاوره)</b></div></div>'
            ],
            [
                'id'          => 'search_bar',
                'name'        => 'search_bar',
                'title'       => 'نوار جستجوی سریع',
                'category'    => 'ویجت‌های سربرگ (Header)',
                'icon'        => '🔍',
                'description' => 'کادر جستجوی بلادرنگ تست‌های روان‌شناسی و مقالات',
                'content'     => '<div data-widget="search_bar" class="fr-pb-block fr-pb-widget-search"><div class="fr-widget-placeholder" style="padding:14px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:10px;color:#1e40af"><b>🔍 ویجت نوار جستجوی سریع تست‌ها</b></div></div>'
            ],
            [
                'id'          => 'topbar_contact',
                'name'        => 'topbar_contact',
                'title'       => 'اطلاعات نوار بالا (تاپ‌بار)',
                'category'    => 'ویجت‌های سربرگ (Header)',
                'icon'        => '📞',
                'description' => 'شماره تماس کلینیک و ساعات پاسخگویی',
                'content'     => '<div data-widget="topbar_contact" class="fr-pb-block fr-pb-widget-topbar"><div class="fr-widget-placeholder" style="padding:12px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:10px;color:#1e40af"><b>📞 ویجت اطلاعات تماس تاپ‌بار</b></div></div>'
            ],

            // ویجت‌های اختصاصی پاورقی (Footer Widgets)
            [
                'id'          => 'footer_brand',
                'name'        => 'footer_brand',
                'title'       => 'معرفی و بیوگرافی کلینیک',
                'category'    => 'ویجت‌های پاورقی (Footer)',
                'icon'        => '🏢',
                'description' => 'ستون معرفی مرکز، بیوگرافی و مجوزها',
                'content'     => '<div data-widget="footer_brand" class="fr-pb-block fr-pb-widget-footer-brand"><div class="fr-widget-placeholder" style="padding:20px;text-align:center;background:#0f172a;color:#38bdf8;border:2px dashed #334155;border-radius:10px"><b>🏢 ویجت معرفی و برند کلینیک در فوتر</b></div></div>'
            ],
            [
                'id'          => 'footer_menu',
                'name'        => 'footer_menu',
                'title'       => 'منوی پیوندهای فوتر',
                'category'    => 'ویجت‌های پاورقی (Footer)',
                'icon'        => '🔗',
                'description' => 'فهرست پیوندهای دسترسی سریع ایجادشده در فهرست‌ساز',
                'content'     => '<div data-widget="footer_menu" data-location="footer" class="fr-pb-block fr-pb-widget-footer-menu"><div class="fr-widget-placeholder" style="padding:16px;text-align:center;background:#0f172a;color:#38bdf8;border:2px dashed #334155;border-radius:10px"><b>🔗 ویجت منوی دسترسی سریع فوتر</b></div></div>'
            ],
            [
                'id'          => 'footer_contact',
                'name'        => 'footer_contact',
                'title'       => 'راه‌های ارتباطی فوتر',
                'category'    => 'ویجت‌های پاورقی (Footer)',
                'icon'        => '📍',
                'description' => 'نشانی، تلفن، ایمیل و ساعات کاری در پاورقی',
                'content'     => '<div data-widget="footer_contact" class="fr-pb-block fr-pb-widget-footer-contact"><div class="fr-widget-placeholder" style="padding:16px;text-align:center;background:#0f172a;color:#38bdf8;border:2px dashed #334155;border-radius:10px"><b>📍 ویجت اطلاعات تماس و نشانی در فوتر</b></div></div>'
            ],
            [
                'id'          => 'social_icons',
                'name'        => 'social_icons',
                'title'       => 'شبکه‌های اجتماعی',
                'category'    => 'ویجت‌های پاورقی (Footer)',
                'icon'        => '🌐',
                'description' => 'آیکون‌های تلگرام، اینستاگرام، بله، ایتا و واتساپ',
                'content'     => '<div data-widget="social_icons" class="fr-pb-block fr-pb-widget-socials"><div class="fr-widget-placeholder" style="padding:14px;text-align:center;background:#0f172a;color:#38bdf8;border:2px dashed #334155;border-radius:10px"><b>🌐 ویجت آیکون‌های شبکه‌های اجتماعی</b></div></div>'
            ],
            [
                'id'          => 'footer_badges',
                'name'        => 'footer_badges',
                'title'       => 'نمادهای اعتماد فوتر',
                'category'    => 'ویجت‌های پاورقی (Footer)',
                'icon'        => '🛡️',
                'description' => 'باکس نمادهای اعتماد الکترونیکی مخصوص تم فوتر',
                'content'     => '<div data-widget="footer_badges" class="fr-pb-block fr-pb-widget-footer-badges"><div class="fr-widget-placeholder" style="padding:16px;text-align:center;background:#0f172a;color:#38bdf8;border:2px dashed #334155;border-radius:10px"><b>🛡️ ویجت نمادهای اعتماد الکترونیکی (فوتر)</b></div></div>'
            ],
            [
                'id'          => 'copyright',
                'name'        => 'copyright',
                'title'       => 'کپی‌رایت و حقوق نشر',
                'category'    => 'ویجت‌های پاورقی (Footer)',
                'icon'        => '©️',
                'description' => 'متن رسمی حقوق مالکیت با سال شمسی جاری',
                'content'     => '<div data-widget="copyright" class="fr-pb-block fr-pb-widget-copyright"><div class="fr-widget-placeholder" style="padding:12px;text-align:center;background:#0f172a;color:#38bdf8;border:2px dashed #334155;border-radius:10px"><b>©️ ویجت کپی‌رایت و سال جاری</b></div></div>'
            ],
        ];
    }

    /**
     * کدهای HTML اولیه برگه پیش‌فرض صفحه اصلی
     */
    public static function getDefaultHomepageHtml(): string
    {
        return '<div class="fr-homepage-builder-content">
  <div data-widget="hero" data-title="سامانه جامع ارزیابی روان‌شناختی، تست‌های آنلاین و مشاوره تخصصی"></div>
  <div data-widget="stats"></div>
  <div data-widget="features"></div>
  <div data-widget="tests" data-limit="6" data-category="all" data-title="🧠 محبوب‌ترین آزمون‌های روان‌شناسی" data-subtitle="آزمون‌های استاندارد با تفسیر عمیق و کارنامه بالینی هوشمند"></div>
  <div data-widget="consult"></div>
  <div data-widget="courses" data-limit="3"></div>
  <div data-widget="workshops" data-limit="3"></div>
  <div data-widget="reviews" data-limit="3"></div>
  <div data-widget="faq"></div>
  <div data-widget="newsletter"></div>
  <div data-widget="trust_badges"></div>
  <div data-widget="contact_box"></div>
</div>';
    }

    /**
     * کدهای CSS اختصاصی برگه پیش‌فرض صفحه اصلی
     */
    public static function getDefaultHomepageCss(): string
    {
        return '.fr-homepage-builder-content {
    font-family: "Vazirmatn", system-ui, -apple-system, sans-serif;
    color: #1e293b;
    line-height: 1.7;
}
.fr-widget-placeholder {
    transition: all 0.2s ease-in-out;
}
.fr-widget-placeholder:hover {
    border-color: #3b82f6 !important;
    background: #eff6ff !important;
}';
    }

    /**
     * قالب اولیه بخش تست‌های روان‌شناسی
     */
    public static function getDefaultTestsHtml(): string
    {
        return '<div class="fr-tests-page-builder-content">
  <div data-widget="hero" data-title="🧠 مرکز آزمون‌های تخصصی روان‌شناسی و خودشناسی" data-subtitle="آزمون‌های استاندارد و معتبر با تفسیر عمیق، تحلیل شاخص‌های بالینی و کارنامه تفصیلی هوشمند"></div>
  <div data-widget="test_single"></div>
  <div data-widget="tests_catalog" data-limit="12"></div>
  <div data-widget="features" data-title="چرا آزمون‌های روان‌شناسی فریا روان؟"></div>
  <div data-widget="reviews" data-limit="3"></div>
  <div data-widget="faq" data-title="پرسش‌های متداول درباره تست‌های روانشناسی"></div>
  <div data-widget="newsletter"></div>
</div>';
    }

    /**
     * قالب اولیه بخش دوره‌های آموزشی
     */
    public static function getDefaultCoursesHtml(): string
    {
        return '<div class="fr-courses-page-builder-content">
  <div data-widget="hero" data-title="📚 دوره‌های آموزشی تخصصی روان‌شناسی و مهارت‌افزایی" data-subtitle="آموزش‌های کاربردی گام‌به‌گام با تدریس اساتید و روان‌درمانگران برجسته"></div>
  <div data-widget="course_single"></div>
  <div data-widget="courses" data-limit="6"></div>
  <div data-widget="features" data-title="مزایای دوره‌های آموزشی کلینیک"></div>
  <div data-widget="reviews" data-limit="3"></div>
  <div data-widget="faq"></div>
</div>';
    }

    /**
     * قالب اولیه بخش کارگاه‌های تعاملی
     */
    public static function getDefaultWorkshopsHtml(): string
    {
        return '<div class="fr-workshops-page-builder-content">
  <div data-widget="hero" data-title="🛠 کارگاه‌های عملی و تعاملی روان‌شناختی" data-subtitle="کارگاه‌های حضوری و آنلاین با تمرین واقعی، بررسی پرونده و تخفیف ویژه همراهان"></div>
  <div data-widget="workshop_single"></div>
  <div data-widget="workshops" data-limit="6"></div>
  <div data-widget="features" data-title="چرا در کارگاه‌های روانشناسی شرکت کنیم؟"></div>
  <div data-widget="reviews" data-limit="3"></div>
  <div data-widget="faq"></div>
</div>';
    }

    /**
     * قالب اولیه بخش رزرو نوبت مشاوره
     */
    public static function getDefaultConsultHtml(): string
    {
        return '<div class="fr-consult-page-builder-content">
  <div data-widget="hero" data-title="🛋 مرکز خدمات و رزرو وقت مشاوره فردی و خانواده" data-subtitle="مشاوره تخصصی آنلاین و حضوری با درمانگران رسمی سازمان نظام روان‌شناسی"></div>
  <div data-widget="consultants_list"></div>
  <div data-widget="consult"></div>
  <div data-widget="features" data-title="فرایند برگزاری جلسات مشاوره"></div>
  <div data-widget="faq" data-title="پرسش‌های متداول مراجعان مشاوره"></div>
  <div data-widget="contact_box"></div>
</div>';
    }

    /**
     * قالب اولیه برگه درباره ما
     */
    public static function getDefaultAboutHtml(): string
    {
        return '<div class="fr-about-page-builder-content" style="max-width:1100px;margin:30px auto;padding:0 16px">
  <div data-widget="hero" data-title="درباره مرکز خدمات روان‌شناسی و مشاوره فریا روان" data-subtitle="بیش از یک دهه همراهی در مسیر سلامت روان، خودشناسی و رشد فردی با استانداردهای علمی"></div>
  <div data-widget="features" data-title="رسالت و ارزش‌های بنیادین کلینیک فریا روان"></div>
  <div data-widget="stats"></div>
  <div data-widget="consultants_list" data-title="کادر متخصصان و روان‌درمانگران مرکز"></div>
  <div data-widget="trust_badges"></div>
</div>';
    }

    /**
     * قالب اولیه برگه تماس با ما
     */
    public static function getDefaultContactHtml(): string
    {
        return '<div class="fr-contact-page-builder-content" style="max-width:1100px;margin:30px auto;padding:0 16px">
  <div data-widget="hero" data-title="راه‌های ارتباطی و نشانی کلینیک فریا روان" data-subtitle="ما در تمام روزهای کاری آماده همراهی و پاسخگویی به مراجعان گرامی هستیم"></div>
  <div data-widget="contact_box"></div>
  <div data-widget="faq" data-title="پرسش‌های متداول پیش از مراجعه"></div>
  <div data-widget="consult"></div>
</div>';
    }
}
