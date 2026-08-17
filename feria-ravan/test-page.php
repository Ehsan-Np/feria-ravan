<?php
/**
 * ============================================================================
 *  صفحهٔ عمومی تست روانشناسی (نشانی زیبا /t/اسلاگ) — نسخهٔ ۱٫۷
 *  ─ بدون نیاز به ورود: جزئیات، دستورالعمل و قیمت تست روانشناسی + دکمهٔ شروع
 *  ─ سئوی کامل: متا، OG، JSON-LD از نوع Quiz
 * ============================================================================
 */
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install/'); exit; }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/public-layout.php';
require_once __DIR__ . '/includes/sanitize.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/reviews-ui.php';
fr_bootstrap();

$slug = trim((string)($_GET['s'] ?? ''));
$t    = $slug !== ''
      ? DB::one('SELECT * FROM ' . DB::t('tests') . " WHERE slug=? AND status='active'", [$slug])
      : DB::one('SELECT * FROM ' . DB::t('tests') . " WHERE id=? AND status='active'", [(int)($_GET['id'] ?? 0)]);
if (!$t) { http_response_code(404); public_header(['desc'=>'تست روانشناسی یافت نشد'],'خطا'); echo '<div class="pb-wrap"><div class="pb-empty"><span class="ic">🧠</span>این تست روانشناسی یافت نشد یا غیرفعال شده است.</div></div>'; public_footer(); exit; }

/* قانون کانونیکال: فقط نشانی قدیمی ?id= به نشانی زیبا ریدایرکت ۳۰۱ می‌شود.
   خود نشانی زیبا /t/اسلاگ توسط .htaccess به همین فایل با ?s= بازنویسی می‌شود؛
   ریدایرکتِ ?s= به /t/ حلقهٔ بی‌نهایت می‌سازد، پس هرگز انجام نمی‌شود. */
$pretty = fr_test_url($t);
if ($t['slug'] && !isset($_GET['s']) && isset($_GET['id'])) {
    header('Location: ' . $pretty, true, 301);
    exit;
}

$u     = current_user();
$price = (int)$t['price_full'];
$seoTitle = trim((string)($t['seo_title'] ?? '')) !== '' ? (string)$t['seo_title'] : (string)$t['title'];
$seoDesc  = trim((string)($t['seo_desc'] ?? ''))  !== '' ? (string)$t['seo_desc']
          : (string)($t['short_desc'] ?: $t['title']);

public_header([
    'active'   => 'tests',
    'title'    => $seoTitle,
    'desc'     => $seoDesc,
    'kw'       => (string)($t['seo_kw'] ?? ''),
    'canonical'=> $pretty,
    'image'    => (string)($t['image'] ?? ''),
    'type'     => 'article',
    'noindex'  => (bool)$t['seo_noindex'],
    'jsonld'   => fr_test_jsonld($t, $pretty),
], $seoTitle);
?>
<section class="pb-hero">
  <span class="pb-hero-ic" aria-hidden="true">🧠</span>
  <div class="pb-hero-in">
    <h1><?= e($t['title']) ?></h1>
    <p><?= e(strip_tags($seoDesc)) ?></p>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:16px;font-size:13px;opacity:.92">
      <span>📝 <?= fa_num((int)$t['question_count']) ?> سؤال</span>
      <span>⏱ حدود <?= fa_num((int)$t['est_minutes']) ?> دقیقه</span>
      <span><?php $tpDeal = fr_price_deal($price, isset($t['price_sale']) ? (int)$t['price_sale'] : null);
        if ($price <= 0): ?>⭐ کاملاً رایگان<?php elseif ($tpDeal['has_sale']): ?>⭐ نسخهٔ ویژه: <del style="opacity:.7"><?= toman($tpDeal['list']) ?></del> <b><?= toman($tpDeal['final']) ?></b> <span style="background:#fef2f2;color:#b91c1c;border-radius:999px;padding:1px 9px;font-size:11.5px;font-weight:800">٪<?= fa_num($tpDeal['percent']) ?> تخفیف</span><?php else: ?>⭐ نسخهٔ ویژه: <?= toman($price) ?><?php endif; ?></span>
      <?php if ((int)$t['free_enabled']): ?><span>🦢 شروع رایگان دارد</span><?php endif; ?>
    </div>
  </div>
</section>

<div class="pb-wrap" style="max-width:900px">
  <div class="fr-card" style="margin-bottom:18px">
    <?php if ($t['description']): ?>
    <div class="ev-content rte-area" style="line-height:2.1;font-size:15px"><?= fr_sanitize_html((string)$t['description']) ?></div>
    <?php else: ?>
    <p style="line-height:2.1"><?= e($t['short_desc']) ?></p>
    <?php endif; ?>
  </div>

  <?php if ($t['instructions']): ?>
  <div class="fr-card" style="margin-bottom:18px">
    <h3 style="margin-bottom:10px">📋 قبل از شروع بدانید</h3>
    <div class="ev-content rte-area" style="line-height:2.1;font-size:14px;color:#2c3a5c"><?= fr_sanitize_html((string)$t['instructions']) ?></div>
  </div>
  <?php endif; ?>

  <div class="fr-card" style="text-align:center;background:linear-gradient(135deg,#faf8ff,#f1ecff);border:1.5px solid #e6ddff">
    <a class="fr-btn" style="font-size:16px;padding:14px 34px" href="<?= SITE_URL ?>/panel/test-start.php?id=<?= (int)$t['id'] ?>">
      <?= $u ? '🦢 شروع تست روانشناسی' : 'ورود و شروع تست روانشناسی ←' ?></a>
    <?php if ($t['free_teaser']): ?>
    <p style="font-size:13px;color:var(--muted);margin-top:12px;line-height:1.9"><?= e(strip_tags((string)$t['free_teaser'])) ?></p>
    <?php endif; ?>
  </div>

  <?php fr_reviews_section('test', (int)$t['id']); ?>

  <p style="text-align:center;margin-top:18px"><a href="<?= SITE_URL ?>/#tests">→ همهٔ تست‌های روانشناسی</a></p>
</div>
<?php public_footer(); ?>
