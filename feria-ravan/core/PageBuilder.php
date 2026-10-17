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

        // ۱. ایجاد جدول در صورت عدم وجود
        DB::run("CREATE TABLE IF NOT EXISTS " . self::table() . " (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(190) NULL,
            `title` VARCHAR(190) NOT NULL,
            `slug` VARCHAR(190) NOT NULL UNIQUE,
            `layout` VARCHAR(50) NOT NULL DEFAULT 'default',
            `seo_title` VARCHAR(190) NULL,
            `meta_desc` VARCHAR(300) NULL,
            `noindex` TINYINT(1) NOT NULL DEFAULT 0,
            `is_home` TINYINT(1) NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `content_html` LONGTEXT NULL,
            `content_css` MEDIUMTEXT NULL,
            `builder_json` LONGTEXT NULL,
            `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_slug` (`slug`),
            INDEX `idx_home` (`is_home`),
            INDEX `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ۲. اطمینان از وجود ستون‌های مورد نیاز صفحه‌ساز
        $cols = [
            'name'         => "VARCHAR(190) NULL AFTER `id`",
            'layout'       => "VARCHAR(50) NOT NULL DEFAULT 'default' AFTER `slug`",
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

        // ۳. اگر هیچ برگه‌ای وجود ندارد، ایجاد برگه پیش‌فرض صفحه اصلی با محتوای اولیه
        $count = (int)DB::val("SELECT COUNT(*) FROM " . self::table());
        if ($count === 0) {
            self::seedDefaultHomepage();
        }

        $ensured = true;
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

        $record = [
            'name'        => $name,
            'title'       => $title,
            'slug'        => $slug,
            'layout'      => (string)($data['layout'] ?? 'default'),
            'seo_title'   => trim((string)($data['seo_title'] ?? '')) ?: null,
            'meta_desc'   => trim((string)($data['meta_desc'] ?? '')) ?: null,
            'noindex'     => !empty($data['noindex']) ? 1 : 0,
            'is_home'     => $isHome,
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
            'seo_title'    => $p['seo_title'] ?? null,
            'meta_desc'    => $p['meta_desc'] ?? null,
            'noindex'      => (int)($p['noindex'] ?? 0),
            'is_home'      => 0, // کپی هیچگاه پیش‌فرض صفحه اصلی نمی‌شود
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

            case 'courses':
                self::renderWidgetCourses($params, $isEditor);
                break;

            case 'workshops':
                self::renderWidgetWorkshops($params, $isEditor);
                break;

            case 'consult':
                self::renderWidgetConsult($params, $isEditor);
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
     * ویجت ۵: باکس عضویت در خبرنامه روان‌شناسی
     */
    public static function renderWidgetNewsletter(array $params, bool $isEditor): void
    {
        $title = (string)($params['title'] ?? '📩 دریافت تحلیل‌های روان‌شناسی و کدهای تخفیف اختصاصی');
        $subtitle = (string)($params['subtitle'] ?? 'با عضویت در خبرنامه فریا روان، از جدیدترین مقالات بالینی، تست‌های منتشرشده و تخفیف‌های مناسبتی مطلع شوید.');
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
        $tel = (string)DB::setting('contact_phone', '۰۲۱-۸۸۸۸۸۸۸۸');
        $addr = (string)DB::setting('contact_address', 'تهران، میدان ونک، خیابان ملاصدرا');
        ?>
        <div class="fr-widget-contact-box" style="padding:30px 16px;max-width:900px;margin:20px auto;background:#fff;border-radius:16px;border:1px solid #e2e8f0;text-align:center">
          <h3 style="font-size:18px;font-weight:800;color:#1e293b;margin:0 0 8px">📞 ارتباط با مرکز روان‌شناسی فریا روان</h3>
          <p style="font-size:13px;color:#64748b;margin:0 0 16px">همکاران ما در روزهای کاری پاسخگوی سوالات شما هستند</p>
          <div style="display:flex;justify-content:center;gap:20px;flex-wrap:wrap;font-size:13px;color:#334155;margin-bottom:16px">
            <div>📍 <b>نشانی:</b> <?= e($addr) ?></div>
            <div>☎️ <b>تلفن تماس:</b> <a href="tel:<?= e(preg_replace('/[^\d\+]/', '', $tel)) ?>" style="color:#0284c7;text-decoration:none"><?= e($tel) ?></a></div>
          </div>
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
                'id'          => 'courses',
                'name'        => 'courses',
                'title'       => 'دوره‌های آموزشی',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '📚',
                'description' => 'فهرست دوره‌های آموزشی روان‌شناسی با تصویر، مدرس و قیمت',
                'content'     => '<div data-widget="courses" data-limit="3" class="fr-pb-block fr-pb-widget-courses"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>📚 ویجت دوره‌های آموزشی روان‌شناسی</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">در نمایش زنده، کارت‌های دوره‌های آموزشی فعال نمایش داده خواهند شد.</p></div></div>'
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
                'id'          => 'consult',
                'name'        => 'consult',
                'title'       => 'رزرو وقت مشاوره',
                'category'    => 'ویجت‌های فریا روان',
                'icon'        => '🛋',
                'description' => 'بخش نوبت‌دهی خدمات مشاوره فردی، زوجین و خانواده',
                'content'     => '<div data-widget="consult" class="fr-pb-block fr-pb-widget-consult"><div class="fr-widget-placeholder" style="padding:30px;text-align:center;background:#f8fafc;border:2px dashed #93c5fd;border-radius:12px;color:#1e40af;font-family:inherit"><b>🛋 ویجت رزرو وقت مشاوره روان‌شناسی</b><p style="margin:6px 0 0;font-size:12px;color:#64748b">کارت‌های خدمات مشاوره به همراه هزینه و زمان‌بندی جلسه.</p></div></div>'
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
}
