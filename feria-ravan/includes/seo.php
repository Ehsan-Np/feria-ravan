<?php
/**
 * ============================================================================
 *  سئو و پیوندهای یکتا — نسخهٔ ۱٫۷
 *  ─ متا تگ‌های کامل (title/description/OG/Twitter/robots/canonical/JSON-LD)
 *  ─ پیوند کوتاه زیبا به سبک وردپرس: /w/اسلاگ (کارگاه) /c/اسلاگ (دوره) /t/اسلاگ (تست روانشناسی)
 *  ─ بازتولید خودکار .htaccess + robots.txt + sitemap.xml هنگام تغییر لینک‌ها
 * ============================================================================
 */
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 | پیوند کوتاه — نگاشت نوع محتوا به مسیر زیبا
 * -------------------------------------------------------------------------*/

/** نشانی زیبای تست روانشناسی: /t/اسلاگ */
function fr_test_url(array $t): string
{
    $s = trim((string)($t['slug'] ?? ''));
    return SITE_URL . ($s !== '' ? '/t/' . rawurlencode($s) : '/panel/test-start.php?id=' . (int)$t['id']);
}

/** نشانی کوتاه اختصاصی تست بر اساس شناسه عددی: /t/1 */
function fr_test_short_url(array|int $t): string
{
    $id = is_array($t) ? (int)($t['id'] ?? 0) : (int)$t;
    if ($id <= 0) return SITE_URL;
    return SITE_URL . '/t/' . $id;
}

/** نشانی فوق‌کوتاه (Tiny URL) تست بر اساس شناسه یا اسلاگ: /s/1 */
function fr_test_tiny_url(array|int $t): string
{
    $id = is_array($t) ? (int)($t['id'] ?? 0) : (int)$t;
    if ($id <= 0) return SITE_URL;
    return SITE_URL . '/s/' . $id;
}

/** نشانی زیبای رویداد: /w/اسلاگ برای کارگاه، /c/اسلاگ برای دوره */
function fr_event_url(array $ev): string
{
    $s = trim((string)($ev['slug'] ?? ''));
    if ($s === '') {
        return SITE_URL . '/' . ($ev['kind'] === 'course' ? 'course' : 'workshop') . '.php?id=' . (int)$ev['id'];
    }
    return SITE_URL . '/' . ($ev['kind'] === 'course' ? 'c' : 'w') . '/' . rawurlencode($s);
}

/* ---------------------------------------------------------------------------
 | ساخت نشانی مطلق از مسیر نسبی (برای OG/JSON-LD)
 * -------------------------------------------------------------------------*/
function fr_abs_url(string $path): string
{
    if ($path === '') return SITE_URL . '/assets/img/favicon.svg';
    if (preg_match('#^https?://#i', $path)) return $path;
    return SITE_URL . '/' . ltrim($path, '/');
}

/* ---------------------------------------------------------------------------
 | رندر متا تگ‌های کامل سئو
 | @param array $m [
 |   'title'    => تیتر سئو (بدون نام سایت؛ خودمان «| سایت» را می‌چسبانیم)
 |   'desc'     => توضیح متا (حداکثر ۱۶۰ کاراکتر معنادار)
 |   'kw'       => کلمات کلیدی (meta keywords — برای موتورهای محلی)
 |   'canonical'=> نشانی کانونیکال مطلق
 |   'image'    => مسیر یا URL تصویر OG
 |   'type'     => og:type (website/article/product)
 |   'noindex'  => bool
 |   'jsonld'   => آرایهٔ schema.org برای JSON-LD
 | ]
 * -------------------------------------------------------------------------*/
function fr_meta_tags(array $m): string
{
    $site  = fr_brand_title();
    $title = trim((string)($m['title'] ?? ''));
    $full  = $title !== '' ? ($title . ' | ' . $site) : $site;
    $desc  = trim((string)($m['desc'] ?? DB::setting('seo_home_desc', '')));
    if ($desc === '') $desc = fr_brand_slogan();
    $descX = mb_substr(preg_replace('/\\s+/', ' ', strip_tags($desc)), 0, 160);
    $descT = mb_substr(preg_replace('/\\s+/', ' ', strip_tags($desc)), 0, 200);
    $img   = fr_abs_url((string)($m['image'] ?? ''));
    $canon = (string)($m['canonical'] ?? '');
    $type  = in_array(($m['type'] ?? ''), ['website','article','product'], true) ? $m['type'] : 'website';

    $out  = '<title>' . e($full) . '</title>' . "\n";
    $out .= '<meta name="description" content="' . e($descX) . '">' . "\n";
    if (!empty($m['kw'])) $out .= '<meta name="keywords" content="' . e((string)$m['kw']) . '">' . "\n";
    $out .= !empty($m['noindex'])
        ? '<meta name="robots" content="noindex,nofollow">' . "\n"
        : '<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1">' . "\n";
    if ($canon !== '') $out .= '<link rel="canonical" href="' . e($canon) . '">' . "\n";

    /* Open Graph — فیسبوک/تلگرام/واتس‌اپ/ایتا */
    $out .= '<meta property="og:site_name" content="' . e($site) . '">' . "\n";
    $out .= '<meta property="og:title" content="' . e($full) . '">' . "\n";
    $out .= '<meta property="og:description" content="' . e($descT) . '">' . "\n";
    $out .= '<meta property="og:type" content="' . e($type) . '">' . "\n";
    $out .= '<meta property="og:locale" content="fa_IR">' . "\n";
    if ($canon !== '') $out .= '<meta property="og:url" content="' . e($canon) . '">' . "\n";
    $out .= '<meta property="og:image" content="' . e($img) . '">' . "\n";

    /* Twitter Card */
    $out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    $out .= '<meta name="twitter:title" content="' . e($full) . '">' . "\n";
    $out .= '<meta name="twitter:description" content="' . e($descT) . '">' . "\n";
    $out .= '<meta name="twitter:image" content="' . e($img) . '">' . "\n";

    /* JSON-LD ساختاری — درک مستقیم گوگل از محتوا */
    if (!empty($m['jsonld']) && is_array($m['jsonld'])) {
        $out .= '<script type="application/ld+json">'
              . json_encode($m['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
              . '</script>' . "\n";
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 | JSON-LD سازگار با schema.org برای رویداد (Event) و تست روانشناسی (Quiz + Product)
 * -------------------------------------------------------------------------*/
function fr_event_jsonld(array $ev, string $url): array
{
    $site = fr_brand_title();
    $ld = [
        '@context' => 'https://schema.org',
        '@type'    => $ev['kind'] === 'course' ? 'Course' : 'Event',
        'name'     => (string)$ev['title'],
        'description' => mb_substr(strip_tags((string)($ev['short_desc'] ?: $ev['description'])), 0, 300),
        'provider' => ['@type' => 'Organization', 'name' => $site, 'sameAs' => SITE_URL],
        'url'      => $url,
    ];
    if (!empty($ev['image'])) $ld['image'] = fr_abs_url((string)$ev['image']);
    if (!empty($ev['teacher'])) $ld['performer'] = ['@type' => 'Person', 'name' => (string)$ev['teacher']];
    if (!empty($ev['starts_at'])) {
        $ld['startDate'] = date('c', strtotime((string)$ev['starts_at']));
        if (!empty($ev['ends_at'])) $ld['endDate'] = date('c', strtotime((string)$ev['ends_at']));
    }
    if (!empty($ev['location'])) {
        $ld['location'] = ['@type' => 'Place', 'name' => (string)$ev['location']];
    }
    if ((int)$ev['price'] >= 0 && !empty($ev['reg_open'])) {
        $ld['offers'] = [
            '@type' => 'Offer', 'price' => (int)$ev['price'],
            'priceCurrency' => 'IRR', 'availability' => 'https://schema.org/InStock', 'url' => $url,
        ];
    }
    return $ld;
}

/** JSON-LD تست روانشناسی — Quiz که در گوگل ریچ‌ریزالت می‌گیرد */
function fr_test_jsonld(array $t, string $url): array
{
    $site = fr_brand_title();
    $ld = [
        '@context' => 'https://schema.org',
        '@type'    => 'Quiz',
        'name'     => (string)$t['title'],
        'about'    => ['@type' => 'Thing', 'name' => 'روانشناسی و خودشناسی'],
        'educationalLevel' => 'عمومی',
        'provider' => ['@type' => 'Organization', 'name' => $site, 'sameAs' => SITE_URL],
        'url'      => $url,
        'inLanguage' => 'fa',
    ];
    if (!empty($t['short_desc'])) $ld['description'] = mb_substr(strip_tags((string)$t['short_desc']), 0, 300);
    return $ld;
}

/* ---------------------------------------------------------------------------
 | بازتولید خودکار .htaccess + robots.txt — الگوی وردپرس
 |  هر بار که اسلاگ تغییر کند، این تابع قوانین صریح هر صفحه را می‌نویسد؛
 |  قوانین کلی /w/ /c/ /t/ هم همیشه حضور دارند تا اگر فایل قفل باشد،
 |  سیستم همچنان کار کند.
 * -------------------------------------------------------------------------*/
function fr_permalinks_flush(): array
{
    $base = (string)parse_url(SITE_URL, PHP_URL_PATH);
    $base = rtrim($base === '' ? '/' : $base, '/');

    $rules = [];
    try {
        foreach (DB::all('SELECT id,kind,slug FROM ' . DB::t('events') . " WHERE status='active' AND slug IS NOT NULL AND slug<>''") as $e) {
            $rules[] = [
                $e['kind'] === 'course' ? 'c' : 'w',
                (string)$e['slug'],
                $e['kind'] === 'course' ? 'course.php?s=' : 'workshop.php?s=',
            ];
        }
        foreach (DB::all('SELECT id,slug FROM ' . DB::t('tests') . " WHERE status='active' AND slug<>''") as $t) {
            $rules[] = ['t', (string)$t['slug'], 'test-page.php?s='];
        }
        foreach (DB::all("SELECT id,slug FROM " . DB::t('site_pages') . " WHERE active=1 AND slug<>''") as $p) {
            $rules[] = ['p', (string)$p['slug'], 'page.php?slug='];
        }
    } catch (Throwable $j) { /* دیتابیس هنوز آماده نیست — قوانین کلی فقط */ }

    $b = $base; // کوتاه‌نویسی
    $lines = [];
    $lines[] = '# BEGIN FERIA';
    $lines[] = '# ============================================================================';
    $lines[] = '#  ♻ قوانین پیوند یکتا — خودکار تولید می‌شود (نسخهٔ ۱٫۷)';
    $lines[] = '#  این بلوک هنگام تغییر اسلاگ از پیشخوان مدیریت بازنویسی می‌شود؛';
    $lines[] = '#  خارج از نشانگرهای BEGIN/END FERIA دست‌نویس ویرایش کنید.';
    $lines[] = '# ============================================================================';
    $lines[] = '<IfModule mod_rewrite.c>';
    $lines[] = 'RewriteEngine On';
    if ($b !== '' && $b !== '/') $lines[] = 'RewriteBase ' . $b . '/';
    foreach ($rules as [$prefix, $slug, $target]) {
         $q = preg_quote($slug);
         $lines[] = "RewriteRule ^{$prefix}/{$q}/?$ {$target}{$slug} [L,QSA]";
    }
    /* قوانین کلی — برای لینک‌های تازهٔ بعد از بازتولید بعدی */
    $lines[] = '# قوانین پیوندهای کوتاه تست‌ها و رویدادها (Short URLs)';
    $lines[] = 'RewriteRule ^t/([0-9]+)/?$ test-page.php?id=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^s/([0-9]+)/?$ test-page.php?id=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^s/([^/]+)/?$ test-page.php?s=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^quiz/([^/]+)/?$ test-page.php?s=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^test/([^/]+)/?$ test-page.php?s=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^w/([^/]+)/?$ workshop.php?s=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^c/([^/]+)/?$ course.php?s=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^t/([^/]+)/?$ test-page.php?s=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^p/([^/]+)/?$ page.php?slug=$1 [L,QSA]';
    $lines[] = 'RewriteRule ^page/([^/]+)/?$ page.php?slug=$1 [L,QSA]';

    /* قوانین هدایت پنل کاربری سفارشی (User Panel Slug) */
    $pSlug = function_exists('fr_panel_slug') ? fr_panel_slug() : 'panel';
    if ($pSlug !== '' && $pSlug !== 'panel') {
        $qSlug = preg_quote($pSlug, '#');
        $lines[] = "# قوانین هدایت پنل کاربری سفارشی ({$pSlug})";
        $lines[] = "RewriteRule ^{$qSlug}/?$ panel/index.php [L,QSA]";
        $lines[] = "RewriteRule ^{$qSlug}/(.*)$ panel/$1 [L,QSA]";
    }
    $lines[] = '</IfModule>';
    $lines[] = '# END FERIA';

    $static = (string)@file_get_contents(FR_ROOT . '/.htaccess');
    $block  = implode("\n", $lines) . "\n";
    $p1 = strpos($static, '# BEGIN FERIA');
    $p2 = strpos($static, '# END FERIA');
    if ($p1 !== false && $p2 !== false && $p2 > $p1) {
        $new = substr($static, 0, $p1) . trim($block) . substr($static, $p2 + strlen('# END FERIA'));
    } else {
        $new = rtrim($static) . "\n\n" . $block;
    }
    $ok = @file_put_contents(FR_ROOT . '/.htaccess', $new, LOCK_EX) !== false;

    /* robots.txt با ارجاع نقشهٔ سایت */
    $disSlug = ($pSlug !== '' && $pSlug !== 'panel') ? ("\nDisallow: /" . $pSlug . "/") : '';
    $robots = "# فریا روان — robots.txt\nUser-agent: *\nAllow: /workshops.php\nAllow: /courses.php\nAllow: /consult.php\nAllow: /w/\nAllow: /c/\nAllow: /t/\nAllow: /s/\nDisallow: /panel/{$disSlug}\nDisallow: /admin/\nDisallow: /api/\nDisallow: /install/\n\nSitemap: " . SITE_URL . "/sitemap.xml\n";
    @file_put_contents(FR_ROOT . '/robots.txt', $robots, LOCK_EX);

    return ['ok' => $ok, 'count' => count($rules)];
}

/* ---------------------------------------------------------------------------
 | نقشهٔ سایت XML (sitemap.php خروجی‌اش می‌کند)
 * -------------------------------------------------------------------------*/
function fr_sitemap_urls(): array
{
    $urls = [
        ['loc' => SITE_URL . '/',              'prio' => '1.0'],
        ['loc' => SITE_URL . '/tests.php',     'prio' => '0.9'],     // آرشیو تست‌های روانشناسی (۱٫۸)
        ['loc' => SITE_URL . '/workshops.php', 'prio' => '0.9'],
        ['loc' => SITE_URL . '/courses.php',   'prio' => '0.9'],
        ['loc' => SITE_URL . '/consult.php',   'prio' => '0.8'],
    ];
    foreach (DB::all("SELECT * FROM " . DB::t('events') . " WHERE status='active' AND slug IS NOT NULL AND slug<>''") as $e) {
        $urls[] = ['loc' => fr_event_url($e), 'prio' => $e['kind'] === 'course' ? '0.8' : '0.7',
                   'lastmod' => !empty($e['starts_at']) ? substr($e['starts_at'], 0, 10) : null];
    }
    foreach (DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active'") as $t) {
        $urls[] = ['loc' => fr_test_url($t), 'prio' => '0.7'];
    }
    try {
        foreach (DB::all("SELECT slug, updated_at, is_home FROM " . DB::t('site_pages') . " WHERE active=1 AND noindex=0 AND is_home=0") as $pg) {
            $urls[] = [
                'loc'     => SITE_URL . '/p/' . rawurlencode((string)$pg['slug']),
                'prio'    => '0.8',
                'lastmod' => !empty($pg['updated_at']) ? substr((string)$pg['updated_at'], 0, 10) : null,
            ];
        }
    } catch (Throwable $e) {}
    return $urls;
}
