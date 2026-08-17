<?php
/**
 * ============================================================================
 *  نقشهٔ سایت XML (sitemap.xml) — نسخهٔ ۱٫۷
 *  ─ فهرست صفحات عمومی: خانه، آرشیوها، لینک‌های کوتاه هر کارگاه/دوره/تست روانشناسی
 *  مسیر: /sitemap.xml (با قانون Rewrite در .htaccess)
 * ============================================================================
 */
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/seo.php';

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach (fr_sitemap_urls() as $u) {
    echo "  <url>\n    <loc>" . htmlspecialchars((string)$u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
    if (!empty($u['lastmod'])) echo "    <lastmod>{$u['lastmod']}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n    <priority>{$u['prio']}</priority>\n  </url>\n";
}
echo '</urlset>' . "\n";
