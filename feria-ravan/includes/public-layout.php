<?php
/**
 * ============================================================================
 *  چیدمان عمومی سایت (بدون نیاز به ورود) — نسخهٔ ۱٫۴
 *  ─ ناوبری شیشه‌ای چسبان + منوی موبایل، سئو/OG، فوتر با لینک‌های بخش‌ها
 *  ─ خط اعتباری ثابت (غیرقابل ویرایش توسط مدیر): طراحی و کدنویسی احسان نادری پناه
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/texts.php';    // نسخهٔ ۲٫۱: متن‌های قابل‌شخصی‌سازی (ری‌برندینگ)
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/../core/MenuBuilder.php';
require_once __DIR__ . '/../core/PageBuilder.php';

/**
 * سربرگ صفحات عمومی
 * @param array $opts ['active','desc','kw','canonical','image','type','noindex','tone','jsonld']
 */
function public_header(array $opts = [], string $title = ''): void
{
    $site   = fr_brand_title();
    $active = (string)($opts['active'] ?? '');
    $tone   = (string)($opts['tone'] ?? '');              // tone-course / tone-consult برای هویت رنگی بخش
    $nav = [
        ['home',     T('nav.home'),      '/'],
        ['tests',    T('nav.tests'),     '/tests.php'],
        ['workshops',T('nav.workshops'), '/workshops.php'],
        ['courses',  T('nav.courses'),   '/courses.php'],
        ['consult',  T('nav.consult'),   '/consult.php'],
    ];
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<link rel="icon" href="<?= e(fr_brand_favicon()) ?>">
<link rel="apple-touch-icon" href="<?= SITE_URL ?>/assets/img/apple-touch-icon.png">
<meta name="csrf" content="<?= csrf_token() ?>">
<meta name="app-url" content="<?= e(SITE_URL) ?>">
<?php
    // نسخهٔ ۱٫۷: مجموعهٔ کامل متا — فراتر از سئوی تک‌خطی قدیم
    echo fr_meta_tags([
        'title'    => $title,
        'desc'     => (string)($opts['desc'] ?? ''),
        'kw'       => (string)($opts['kw'] ?? ''),
        'canonical'=> (string)($opts['canonical'] ?? ''),
        'image'    => (string)($opts['image'] ?? ''),
        'type'     => (string)($opts['type'] ?? 'website'),
        'noindex'  => (bool)($opts['noindex'] ?? false),
        'jsonld'   => $opts['jsonld'] ?? null,
    ]);
    ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/feria.css?v=<?= FR_VERSION ?>">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/site.css?v=<?= FR_VERSION ?>">
<?= fr_brand_css() ?>
</head>
<body class="pb<?= $tone ? ' ' . e($tone) : '' ?>" data-active="<?= e($active) ?>">
<script>window.CSRF='<?= csrf_token() ?>';</script>

<?php
// ۳٫۷٫۰: بررسی وجود سربرگ سفارشی فعال طراحی‌شده با صفحه‌ساز دیداری
if (empty($_GET['classic']) && empty($_GET['default_header']) && class_exists('PageBuilder')) {
    $customHeader = PageBuilder::getActiveHeader();
    if ($customHeader && !empty($customHeader['content_html'])) {
        if (!empty($customHeader['content_css'])) {
            echo "<style id=\"fr-custom-header-css\">\n" . $customHeader['content_css'] . "\n</style>\n";
        }
        echo PageBuilder::resolveWidgets($customHeader['content_html']);
        return;
    }
}
?>

<!-- ===================== ناوبری عمومی ===================== -->
<header class="pb-nav" id="pbNav">
  <div class="pb-nav-in">
    <a class="pb-brand" href="<?= SITE_URL ?>/">
      <?= fr_logo_html('pb-brand-mark') ?>
      <span class="pb-brand-tx"><b><?= e($site) ?></b><small><?= e(DB::setting('site_slogan', '')) ?></small></span>
    </a>
    <nav class="pb-links" id="pbLinks" aria-label="ناوبری اصلی">
      <?php foreach ($nav as [$key, $label, $url]): ?>
      <a class="<?= $active === $key ? 'on' : '' ?>" href="<?= SITE_URL . $url ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <span class="pb-links-cta">
        <?php if ($u): ?>
          <a class="fr-btn sm" href="<?= function_exists('fr_panel_url') ? fr_panel_url() : (SITE_URL . '/panel/') ?>"><?= e(function_exists('fr_panel_title') ? fr_panel_title() : T('nav.panel')) ?> ←</a>
        <?php else: ?>
          <a class="fr-btn sm" href="<?= SITE_URL ?>/auth.php"><?= e(T('nav.login')) ?></a>
        <?php endif; ?>
      </span>
    </nav>
    <button class="pb-burger" id="pbBurger" type="button" aria-label="باز و بسته کردن منو" aria-expanded="false">☰</button>
  </div>
</header>
<div class="pb-nav-gap"></div>
    <?php
}

/** پانوشت عمومی + خط اعتباری ثابت (سیاست: مدیر هم نمی‌تواند حذفش کند) */
/** تجزیهٔ فهرست لینک‌های فوتر — قالب هر خط: «عنوان | نشانی» (نسخهٔ ۲٫۱) */
function fr_footer_links(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', trim($raw)) as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $parts = explode('|', $ln, 2);
        $label = trim($parts[0]);
        $url   = isset($parts[1]) ? trim($parts[1]) : '';
        if ($label === '') continue;
        if ($url === '') $url = '#';
        elseif (!preg_match('#^(https?:)?//#i', $url) && !str_starts_with($url, '/')) $url = '/' . ltrim($url, '/');
        $out[] = ['label' => $label, 'url' => $url];
    }
    return $out;
}

/** فهرست لینک‌های فوتر از تنظیمات (کلید) با پیش‌فرض کد */
function fr_footer_linklist(string $key, array $default): array
{
    $raw = trim((string)DB::setting($key, ''));
    if ($raw === '') return $default;
    $links = fr_footer_links($raw);
    return $links ?: $default;
}

/** چهار جایگاه نماد اعتماد فوتر با پردازش و خودترمیمی هوشمند کدهای اینماد، زرین‌پال و ساماندهی */
function fr_footer_badges(): array
{
    $titles = [
        1 => 'نماد اعتماد الکترونیکی (اینماد)',
        2 => 'نشان ملی ثبت (ساماندهی)',
        3 => 'درگاه پرداخت امن زرین‌پال',
        4 => 'انجمن روانشناسی'
    ];
    $out = [];
    for ($i = 1; $i <= 4; $i++) {
        $html = trim((string)DB::setting("footer_badge{$i}_html", ''));
        $img  = trim((string)DB::setting("footer_badge{$i}_img", ''));
        $url  = trim((string)DB::setting("footer_badge{$i}_url", ''));
        $alt  = trim((string)DB::setting("footer_badge{$i}_alt", $titles[$i])) ?: $titles[$i];

        // ۱. تشخیص درج اشتباه کد کامل HTML داخل فیلدهای Image یا URL
        if ($html === '') {
            if (str_contains($url, '<') && (str_contains($url, '<a') || str_contains($url, '<script') || str_contains($url, '<img') || str_contains($url, '<iframe'))) {
                $html = $url;
                $url  = '';
            } elseif (str_contains($img, '<') && (str_contains($img, '<a') || str_contains($img, '<script') || str_contains($img, '<img') || str_contains($img, '<iframe'))) {
                $html = $img;
                $img  = '';
            }
        }

        // ۲. نرمال‌سازی هوشمند کدهای اینماد (Enamad)
        if ($html !== '') {
            if (str_contains($html, 'enamad.ir') || str_contains($html, 'trustseal')) {
                // اطمینان از referrerpolicy="origin" روی لینک و تصویر
                if (!str_contains($html, 'referrerpolicy')) {
                    $html = preg_replace('/(<(?:a|img)\b)/i', '$1 referrerpolicy="origin"', $html);
                }
            }
        } elseif ($url !== '' && str_contains($url, 'trustseal.enamad.ir')) {
            // ساخت خودکار قطعه‌کد رسمی اینماد اگر مدیر فقط پیوند اینماد را قرار داده باشد
            $parts = parse_url($url);
            $query = $parts['query'] ?? '';
            $html = '<a referrerpolicy="origin" target="_blank" href="' . e($url) . '" rel="noopener" title="' . e($alt) . '">'
                  . '<img referrerpolicy="origin" src="https://trustseal.enamad.ir/logo.aspx?' . e($query) . '" alt="' . e($alt) . '" style="cursor:pointer" loading="lazy">'
                  . '</a>';
        }

        // ۳. نرمال‌سازی هوشمند کدهای زرین‌پال (ZarinPal)
        if ($html !== '') {
            if (str_contains($html, 'zarinpal.com')) {
                if (str_contains($html, 'TrustCode') && !str_contains($html, 'id="zarinpal"')) {
                    $html = '<div id="zarinpal">' . $html . '</div>';
                }
            }
        } elseif ($url !== '' && str_contains($url, 'zarinpal.com')) {
            if ($img === '') {
                $img = SITE_URL . '/assets/img/badges/zarinpal.svg';
            }
        } elseif ($html === '' && $img === '' && $url === '' && $i === 3) {
            $zpMerchant = trim((string)DB::setting('zarinpal_merchant', ''));
            if ($zpMerchant !== '' && $zpMerchant !== 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx') {
                $img = SITE_URL . '/assets/img/badges/zarinpal.svg';
                $url = 'https://www.zarinpal.com/trust';
            }
        }

        // ۴. نرمال‌سازی ساماندهی (Samandehi)
        if ($html !== '') {
            if (str_contains($html, 'samandehi.ir')) {
                if (!str_contains($html, 'cursor')) {
                    $html = preg_replace('/(<img\b)/i', '$1 style="cursor:pointer"', $html);
                }
            }
        } elseif ($url !== '' && str_contains($url, 'samandehi.ir')) {
            if ($img === '') {
                $img = SITE_URL . '/assets/img/badges/samandehi.svg';
            }
        }

        $hasBadge = ($html !== '' || $img !== '');

        $out[] = [
            'index'     => $i,
            'img'       => $img,
            'url'       => $url,
            'alt'       => $alt,
            'html'      => $html,
            'has_badge' => $hasBadge,
        ];
    }
    return $out;
}

function public_footer(): void
{
    $site     = fr_brand_title();
    $logoDesc = trim((string)DB::setting('logo_desc', ''));
    if ($logoDesc === '') {
        $logoDesc = (string)DB::setting('footer_text', 'مرجع تست‌های روانشناسی، کارگاه‌ها و دوره‌های تخصصی و مشاورهٔ آنلاین و حضوری.');
    }
    $links1 = fr_footer_linklist('footer_links_json', [
        ['label' => T('nav.tests'), 'url' => '/tests.php'],
        ['label' => T('nav.workshops'), 'url' => '/workshops.php'],
        ['label' => T('nav.courses'), 'url' => '/courses.php'],
        ['label' => T('nav.consult'), 'url' => '/consult.php'],
    ]);
    $links2 = fr_footer_linklist('footer_links2_json', [
        ['label' => '💌 عضویت در خبرنامه', 'url' => SITE_URL . '/newsletter.php'],
        ['label' => T('nav.login'), 'url' => '/auth.php'],
        ['label' => (function_exists('fr_panel_title') ? fr_panel_title() : T('nav.panel')), 'url' => (function_exists('fr_panel_url') ? fr_panel_url() : '/panel/')],
        ['label' => 'پشتیبانی و تیکت', 'url' => '/panel/tickets.php'],
    ]);
    $badges = fr_footer_badges();
    $u = function_exists('current_user') ? current_user() : null;

    // ۳٫۷٫۰: بررسی وجود پاورقی سفارشی فعال طراحی‌شده با صفحه‌ساز دیداری
    if (empty($_GET['classic']) && empty($_GET['default_footer']) && class_exists('PageBuilder')) {
        $customFooter = PageBuilder::getActiveFooter();
        if ($customFooter && !empty($customFooter['content_html'])) {
            if (!empty($customFooter['content_css'])) {
                echo "<style id=\"fr-custom-footer-css\">\n" . $customFooter['content_css'] . "\n</style>\n";
            }
            echo PageBuilder::resolveWidgets($customFooter['content_html']);
            echo '<script src="' . SITE_URL . '/assets/js/feria.js?v=' . FR_VERSION . '"></script>';
            echo '<script src="' . SITE_URL . '/assets/js/site.js?v=' . FR_VERSION . '"></script>';
            echo '</body></html>';
            return;
        }
    }
    ?>
<!-- ===================== فوتر عمومی (نسخهٔ ۲٫۱) ===================== -->
<footer class="pb-foot">
  <!-- نوار خبرنامهٔ پیامکی -->
  <div class="pb-foot-in pb-nl-wrap">
    <div class="pb-nl" id="frNewsletter">
      <div class="pb-nl-txt">
        <b>🔔 <?= e(DB::setting('newsletter_title', T('footer.newsletter_title'))) ?></b>
        <span><?= e(DB::setting('newsletter_desc', T('footer.newsletter_desc'))) ?></span>
      </div>
      <?php if ($u): ?>
      <button type="button" class="fr-btn white" id="nlJoin" data-mobile="<?= e((string)$u['mobile']) ?>" data-name="<?= e(function_exists('user_fullname') ? user_fullname($u) : '') ?>">
        <?= e(T('footer.newsletter_btn')) ?></button>
      <?php else: ?>
      <form class="pb-nl-form" id="nlForm">
        <input class="fr-input" id="nlName" type="text" maxlength="120"
               placeholder="نام و نام خانوادگی…" aria-label="نام و نام خانوادگی" style="direction:rtl;text-align:right">
        <input class="fr-input" id="nlMobile" type="tel" inputmode="numeric" maxlength="11" dir="ltr"
               placeholder="شماره موبایل…" aria-label="شماره موبایل">
        <button class="fr-btn white" type="submit"><?= e(T('footer.newsletter_btn')) ?></button>
      </form>
      <?php endif; ?>
      <span class="pb-nl-msg" id="nlMsg" role="status"></span>
      <a href="<?= SITE_URL ?>/newsletter.php" style="font-size:12px;color:#fff;opacity:.9;text-decoration:underline;margin-inline-start:8px">فرم کامل عضویت (با تلگرام و بله) ←</a>
    </div>
  </div>

  <div class="pb-foot-in">
    <!-- ستون ۱: برند + توضیحات سئویی کنار لوگو -->
    <div class="pb-foot-col pb-foot-brandcol">
      <div class="pb-foot-brand"><?= fr_logo_html('pb-brand-mark') ?><b><?= e($site) ?></b></div>
      <div class="pb-foot-desc"><?php
        /* نسخهٔ ۲٫۱: متن آزادِ سئومحور زیر لوگو — از تنظیمات ← فوتر (RTE) قابل تغییر */
        if (function_exists('fr_sanitize_html') && trim((string)DB::setting('logo_desc','')) !== '') {
            echo fr_sanitize_html($logoDesc);
        } else echo e($logoDesc);
      ?></div>
    </div>
    <!-- ستون ۲: دسترسی سریع — لینک‌ها از تنظیمات قابل مدیریت‌اند -->
    <div class="pb-foot-col">
      <b><?= e(T('footer.links_title')) ?></b>
      <?php foreach ($links1 as $l): ?>
      <a href="<?= e(function_exists('fr_format_url') ? fr_format_url($l['url']) : $l['url']) ?>"><?= e($l['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <!-- ستون ۳: خدمات کاربران -->
    <div class="pb-foot-col">
      <b><?= e(T('footer.support_title')) ?></b>
      <?php foreach ($links2 as $l): ?>
      <a href="<?= e(function_exists('fr_format_url') ? fr_format_url($l['url']) : $l['url']) ?>"><?= e($l['label']) ?></a>
      <?php endforeach; ?>
    </div>
    <!-- ستون ۴: چهار نماد هم‌اندازه (اینماد / ساماندهی / زرین‌پال / انجمن) — ۲٫۲ -->
    <div class="pb-foot-col pb-foot-badges">
      <b><?= e(T('footer.badges_title')) ?></b>
      <div class="fb-badges fb-count-4">
        <?php foreach ($badges as $bg): ?>
        <div class="fb-slot <?= $bg['has_badge'] ? 'fb-active' : 'fb-empty-slot' ?>" title="<?= e($bg['alt']) ?>">
          <?php if ($bg['html'] !== ''): ?>
            <div class="fb-embed"><?= $bg['html'] ?></div>
          <?php elseif ($bg['img'] !== ''): ?>
            <?php if ($bg['url'] !== ''): ?>
            <a href="<?= e($bg['url']) ?>" target="_blank" rel="noopener" referrerpolicy="origin" aria-label="<?= e($bg['alt']) ?>">
              <img src="<?= e($bg['img']) ?>" alt="<?= e($bg['alt']) ?>" referrerpolicy="origin" loading="lazy">
            </a>
            <?php else: ?>
            <img src="<?= e($bg['img']) ?>" alt="<?= e($bg['alt']) ?>" referrerpolicy="origin" loading="lazy">
            <?php endif; ?>
          <?php else: ?>
            <div class="fb-empty">
              <span>＋</span>
              <small><?= e($bg['alt']) ?></small>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="pb-foot-base">
    <span><?= fr_brand_copyright() ?></span>
    <!-- خط اعتباری توسعه‌دهنده — متن و لینکش از تنظیمات (تب برند) قابل ویرایش یا در حالت White-Label مخفی است -->
    <?php $cHtml = fr_credit_html(); if ($cHtml !== ''): ?>
    <span class="pb-credit"><?= $cHtml ?></span>
    <?php endif; ?>
  </div>
</footer>
<script src="<?= SITE_URL ?>/assets/js/feria.js?v=<?= FR_VERSION ?>"></script>
<script src="<?= SITE_URL ?>/assets/js/site.js?v=<?= FR_VERSION ?>"></script>
<script>
/* تضمین نمایش نمادهای رسمی (زرین‌پال، اینماد) */
(function(){
  window.addEventListener('load', function() {
    var zp = document.getElementById('zarinpal');
    if (zp && (zp.children.length === 0 || zp.innerHTML.trim() === '')) {
      zp.innerHTML = '<a href="https://www.zarinpal.com/trust" target="_blank" rel="noopener" aria-label="درگاه پرداخت امن زرین‌پال"><img src="<?= SITE_URL ?>/assets/img/badges/zarinpal.svg" alt="درگاه پرداخت زرین‌پال" style="max-height:80px;object-fit:contain"></a>';
    }
  });
})();
</script>
<script>
/* خبرنامهٔ پیامکی (۲٫۱) */
(function(){
  var msg = document.getElementById('nlMsg');
  function done(j){
    if (!msg) return;
    msg.style.color = (j && j.ok) ? '#8df0c0' : '#ffb4b4';
    msg.textContent = (j && j.message) ? j.message : 'خطای ارتباط؛ دوباره تلاش کنید.';
  }
  var join = document.getElementById('nlJoin');
  if (join) join.addEventListener('click', function(){
    join.disabled = true;
    window.api('<?= SITE_URL ?>/api/newsletter.php', {action:'subscribe', mobile: join.getAttribute('data-mobile'), name: join.getAttribute('data-name') || '', source: 'footer'}).then(done);
  });
  var f = document.getElementById('nlForm');
  if (f) f.addEventListener('submit', function(e){
    e.preventDefault();
    var m = document.getElementById('nlMobile').value.trim();
    var nm = document.getElementById('nlName').value.trim();
    /* ۲٫۲: نام و نام خانوادگی در یک فیلد دریافت می‌شود */
    window.api('<?= SITE_URL ?>/api/newsletter.php', {action:'subscribe', mobile: m, name: nm, source: 'footer'}).then(done);
  });
})();
</script>
</body>
</html>
    <?php
}

/* ---------------------------------------------------------------------------
 |  ۱٫۸ — نوار ابزار نمایش حرفه‌ای (تعداد + تعویض حالت + لینک نمایش همه)
 * -------------------------------------------------------------------------*/
/**
 * آغاز یک فهرست حرفه‌ای: نوار ابزار + بازشدن کانتینر کارت‌ها
 * @param string $id     شناسهٔ یکتای فهرست (برای حافظهٔ حالت نمایش کاربر)
 * @param int    $count  تعداد اقلام
 * @param string $unit   واحد شمارش (تست روانشناسی / کارگاه / دوره)
 * @param string $allUrl نشانی «نمایش تمام گزینه‌ها» (اختیاری)
 */
function fr_pc_toolbar(string $id, int $count, string $unit, string $allUrl = '', string $allLabel = 'نمایش تمام گزینه‌ها', bool $openCanvas = true): void
{
    ?>
<div class="pc-toolbar">
  <span class="pc-count"><?= fa_num($count) ?> <?= e($unit) ?></span>
  <span class="pc-sp"></span>
  <span class="pc-view-tgl" role="group" aria-label="حالت نمایش فهرست">
    <button type="button" class="on" data-pc-view="grid" data-pc-for="<?= e($id) ?>" aria-label="نمایش شبکه‌ای">▦ شبکه‌ای</button>
    <button type="button" data-pc-view="list" data-pc-for="<?= e($id) ?>" aria-label="نمایش فهرستی">☰ فهرستی</button>
  </span>
  <?php if ($allUrl !== ''): ?>
  <a class="pc-all-link" href="<?= e($allUrl) ?>"><?= e($allLabel) ?> ←</a>
  <?php endif; ?>
</div>
<?php if ($openCanvas): ?>
<div class="pc-canvas" data-pc-id="<?= e($id) ?>">
<?php endif;
}

/** پایان فهرست حرفه‌ای */
function fr_pc_toolbar_end(): void
{
    ?></div><?php
}

/* ---------------------------------------------------------------------------
 |  ۱٫۸ — کارت حرفه‌ای تست روانشناسی (کاور گرادیان + نشان‌ها + متا + دکمهٔ عمل)
 * -------------------------------------------------------------------------*/
function fr_test_pc_card(array $t): void
{
    $icon  = fr_test_icon($t);
    $url   = fr_test_url($t);
    $isMgr = (string)($t['audience'] ?? 'general') === 'manager';
    /* پالت کاور: ویژهٔ مدیران = تیرهٔ لوکس / هر موتور رنگ خودش (سازگار با هویت بنفشِ روشن) */
    $grads = [
        'disc'     => ['#6366f1', '#3e5f9e'],
        'ysq'      => ['#3e5f9e', '#c084fc'],
        'neo'      => ['#3b82f6', '#60a5fa'],
        'screen'   => ['#0ea5e9', '#38bdf8'],
        'jobsat'   => ['#f59e0b', '#fbbf24'],
        'mslikert' => ['#7c5cf0', '#a78bfa'],
    ];
    $g = $grads[(string)($t['engine'] ?? '')] ?? ['#7c5cf0', '#a78bfa'];
    $grad = $isMgr ? 'linear-gradient(135deg,#17122e,#3b2a7a)'
                   : 'linear-gradient(135deg,' . $g[0] . ',' . $g[1] . ')';
    $isNew = !empty($t['created_at']) && strtotime((string)$t['created_at']) > time() - 75 * 86400;
    $price = (int)($t['price_full'] ?? 0);
    $tDeal = fr_price_deal($price, isset($t['price_sale']) ? (int)$t['price_sale'] : null); // ۲٫۸
    ?>
<article class="pc-card">
  <a class="pc-cover" href="<?= e($url) ?>" style="background:<?= e($grad) ?>" aria-label="<?= e($t['title']) ?>">
    <span class="pc-cover-ic" aria-hidden="true"><?= $icon ?></span>
    <span class="pc-badge <?= $isMgr ? 'manager' : '' ?>"><?= $isMgr ? '👑 ویژهٔ مدیران' : 'عمومی' ?></span>
    <?php if ($isNew): ?><span class="pc-badge new">✨ جدید</span><?php endif; ?>
    <?php if ($tDeal['has_sale']): ?><span class="pc-badge off">٪ <?= fa_num($tDeal['percent']) ?> تخفیف</span><?php endif; ?>
  </a>
  <div class="pc-body">
    <span class="pc-kind"><?= e(fr_test_kind_fa($t)) ?></span>
    <h3 class="pc-title"><a href="<?= e($url) ?>"><?= e($t['title']) ?></a></h3>
    <p class="pc-desc"><?= e($t['short_desc']) ?></p>
    <div class="pc-meta">
      <span>📝 <?= fa_num($t['question_count']) ?> سؤال</span>
      <span>⏱ <?= fa_num($t['est_minutes']) ?> دقیقه</span>
      <span><?php if ($price <= 0): ?>🎁 کاملاً رایگان<?php elseif ($tDeal['has_sale']): ?>⭐ ویژه: <del style="opacity:.65"><?= toman($tDeal['list']) ?></del> <b style="color:#b91c1c"><?= toman($tDeal['final']) ?></b><?php else: ?>⭐ ویژه: <?= toman($price) ?><?php endif; ?></span>
    </div>
    <div class="pc-cta">
      <a class="fr-btn" href="<?= e($url) ?>">مشاهدهٔ تست روانشناسی ←</a>
    </div>
  </div>
</article>
    <?php
}

/* ---------------------------------------------------------------------------
 |  کارت حرفه‌ای رویداد (کارگاه / دوره) — نسخهٔ ۱٫۸ — دقیقاً در خانوادهٔ pc-card
 |  همان چیدمان کارت تست روانشناسی: کاور گرادیان/تصویر + نشان‌ها + بدنه با متا و دکمهٔ عمل
 |  ─ کارگاه: گرادیان بنفش / دوره: گرادیان فیروزه‌ای (حفظ هویت رنگی هر حوزه)
 |  ─ اگر رویداد کاور آپلودی داشته باشد، تصویر به‌جای ایموجی روی کاور می‌نشیند
 |  @param array $opts ['link'=>نشانی جایگزین, 'registered'=>بول, 'cta'=>برچسب دکمه]
 * -------------------------------------------------------------------------*/
function fr_event_pc_card(array $ev, ?int $seatsLeft, array $opts = []): void
{
    $isCourse   = ($ev['kind'] ?? '') === 'course';
    $grad       = $isCourse ? 'linear-gradient(135deg,#0e7490,#14b8a6)'   // دوره: فیروزه‌ای
                            : 'linear-gradient(135deg,#6d4bd0,#3e5f9e)';  // کارگاه: بنفش
    $coverImg   = fr_event_cover($ev);
    $coverStyle = $grad;
    if ($coverImg !== '') {
        $coverStyle .= ';background-image:url(' . str_replace(["'", ')', '('], '', $coverImg) . ');background-size:cover;background-position:center';
    }
    $url        = (string)($opts['link'] ?? '') !== '' ? (string)$opts['link'] : fr_event_url($ev);
    $registered = !empty($opts['registered']);
    $full       = $seatsLeft !== null && $seatsLeft <= 0;
    $past       = !empty($ev['starts_at']) && strtotime((string)$ev['starts_at']) < time();
    $closed     = !(int)($ev['reg_open'] ?? 1);
    $sold       = $full || $past || $closed;
    $off        = (string)($ev['ws_discount_type'] ?? 'none') !== 'none';
    $price      = (int)($ev['price'] ?? 0);
    $evDeal     = fr_price_deal($price, isset($ev['sale_price']) ? (int)$ev['sale_price'] : null); // ۲٫۸
    $kindFa     = $isCourse ? 'دورهٔ آموزشی جامع' : 'کارگاه آموزشی عملی';
    $desc       = (string)($ev['short_desc'] ?? '');
    if ($desc === '') { $desc = mb_substr(trim(strip_tags((string)($ev['description'] ?? ''))), 0, 110); }
    $when       = (string)($ev['schedule_text'] ?? '') !== ''
                ? (string)$ev['schedule_text']
                : ($ev['starts_at'] ? fa_num(jdate('l j F Y - H:i', strtotime((string)$ev['starts_at']))) : 'زمان‌بندی به‌زودی اعلام می‌شود');
    ?>
<article class="pc-card">
  <a class="pc-cover" href="<?= e($url) ?>" style="background:<?= e($coverStyle) ?>" aria-label="<?= e($ev['title']) ?>">
    <?php if ($coverImg === ''): ?><span class="pc-cover-ic" aria-hidden="true"><?= $isCourse ? '📚' : '🛠' ?></span><?php endif; ?>
    <span class="pc-badge"><?= $isCourse ? '📚 دوره' : '🛠 کارگاه' ?></span>
    <?php if ($full): ?><span class="pc-badge soldout">⛔ تکمیل ظرفیت</span>
    <?php elseif ($past): ?><span class="pc-badge held">✓ برگزار شد</span>
    <?php elseif ($evDeal['has_sale']): ?><span class="pc-badge off">٪ <?= fa_num($evDeal['percent']) ?> تخفیف</span>
    <?php elseif ($off): ?><span class="pc-badge off">٪ تخفیف ویژه</span><?php endif; ?>
  </a>
  <div class="pc-body">
    <span class="pc-kind"><?= e($kindFa) ?></span>
    <h3 class="pc-title"><a href="<?= e($url) ?>"><?= e($ev['title']) ?></a></h3>
    <p class="pc-desc"><?= e($desc) ?></p>
    <div class="pc-meta">
      <span>🗓 <?= e($when) ?></span>
      <?php if (!empty($ev['teacher'])): ?><span>👤 <?= e($ev['teacher']) ?></span><?php endif; ?>
      <?php if (!empty($ev['location'])): ?><span>📍 <?= e($ev['location']) ?></span><?php endif; ?>
      <?php if ($ev['capacity'] !== null && $seatsLeft !== null): ?>
      <span><?= $full ? '⛔ ظرفیت تکمیل' : '🪑 ' . fa_num(max(0, (int)$seatsLeft)) . ' صندلی آزاد' ?></span>
      <?php endif; ?>
      <span><?php if ($price <= 0): ?>🎁 رایگان<?php elseif ($evDeal['has_sale']): ?>💳 <del style="opacity:.65"><?= toman($evDeal['list']) ?></del> <b style="color:#b91c1c"><?= toman($evDeal['final']) ?></b><?php else: ?>💳 <?= toman($price) ?><?php endif; ?></span>
    </div>
    <div class="pc-cta">
      <?php if ($registered): ?>
      <a class="fr-btn" style="background:#19a974" href="<?= e($url) ?>">ثبت‌نام کرده‌اید ✅ — مشاهده</a>
      <?php elseif ($sold): ?>
      <a class="fr-btn ghost" href="<?= e($url) ?>">مشاهدهٔ جزئیات ←</a>
      <?php else: ?>
      <a class="fr-btn" href="<?= e($url) ?>">جزئیات و ثبت‌نام ←</a>
      <?php endif; ?>
    </div>
  </div>
</article>
    <?php
}

/* ---------------------------------------------------------------------------
 |  ابزار رندر کارت رویداد در آرشیو عمومی (کارگاه / دوره)
 * -------------------------------------------------------------------------*/
/** کاور رویداد: URL کامل تصویر یا کاور گرافیکی پیش‌فرض بر اساس نوع */
function fr_event_cover(array $ev): string
{
    if (!empty($ev['image'])) {
        return str_starts_with($ev['image'], 'http') ? $ev['image'] : SITE_URL . '/' . ltrim($ev['image'], '/');
    }
    return '';
}

/**
 * کارت رویداد برای آرشیو عمومی — هویت بصری متفاوت کارگاه (بنفش) / دوره (فیروزه‌ای)
 */
function fr_event_card(array $ev, ?int $seatsLeft, string $page): void
{
    $isCourse = $ev['kind'] === 'course';
    $cover    = fr_event_cover($ev);
    // نسخهٔ ۱٫۷: نشانی زیبای کوتاه (/w/اسلاگ یا /c/اسلاگ) — فال‌بک به URL قدیمی اگر اسلاگ نبود
    $url      = fr_event_url($ev);
    $full     = $seatsLeft !== null && $seatsLeft <= 0;
    $past     = $ev['starts_at'] && strtotime($ev['starts_at']) < time();
    $sold     = $full || $past || !(int)$ev['reg_open'];
    $emoji    = $isCourse ? '📚' : '🛠';
    $evcDeal  = fr_price_deal((int)$ev['price'], isset($ev['sale_price']) ? (int)$ev['sale_price'] : null); // ۲٫۸
    ?>
<article class="ev-card<?= $isCourse ? ' tone-course' : '' ?>">
  <a class="ev-cover" href="<?= e($url) ?>" aria-label="<?= e($ev['title']) ?>">
    <?php if ($cover): ?>
    <img src="<?= e($cover) ?>" alt="<?= e($ev['title']) ?>" loading="lazy" decoding="async" width="800" height="450">
    <?php else: ?>
    <span class="ev-cover-ph" aria-hidden="true"><i><?= $emoji ?></i></span>
    <?php endif; ?>
    <?php if ($ev['starts_at']): ?>
    <span class="ev-date"><b><?= fa_num(jdate('j', strtotime($ev['starts_at']))) ?></b><?= e(jdate('F', strtotime($ev['starts_at']))) ?></span>
    <?php endif; ?>
    <?php if ($evcDeal['has_sale']): ?><span class="ev-off">٪ <?= fa_num($evcDeal['percent']) ?> تخفیف</span>
    <?php elseif ($ev['ws_discount_type'] !== 'none'): ?><span class="ev-off">٪ تخفیف ویژه</span><?php endif; ?>
  </a>
  <div class="ev-body">
    <h3><a href="<?= e($url) ?>"><?= e($ev['title']) ?></a></h3>
    <p class="ev-sum"><?= e($ev['short_desc'] ?: mb_substr(trim(strip_tags((string)$ev['description'])), 0, 110)) ?></p>
    <ul class="ev-meta">
      <?php if ($ev['teacher']): ?><li>👤 <?= e($ev['teacher']) ?></li><?php endif; ?>
      <?php if ($ev['schedule_text']): ?><li>🗓 <?= e($ev['schedule_text']) ?></li>
      <?php elseif ($ev['starts_at']): ?><li>🗓 <?= fa_num(jdate('l j F Y - H:i', strtotime($ev['starts_at']))) ?></li><?php endif; ?>
      <?php if ($ev['location']): ?><li>📍 <?= e($ev['location']) ?></li><?php endif; ?>
      <?php if ($ev['capacity'] !== null && $seatsLeft !== null): ?>
      <li><?= $full ? '⛔ ظرفیت تکمیل شد' : '🪑 ' . fa_num(max(0, $seatsLeft)) . ' صندلی آزاد' ?></li>
      <?php endif; ?>
    </ul>
    <div class="ev-foot">
      <div class="ev-price">
        <?php if ((int)$ev['price'] > 0): ?>
          <?php if ($evcDeal['has_sale']): ?><del style="color:#94a3b8;font-size:12px;font-weight:500"><?= toman($evcDeal['list']) ?></del><?php endif; ?>
          <b><?= toman($evcDeal['final']) ?></b><small><?= $isCourse ? 'شهریه دوره' : 'شهریه کارگاه' ?></small>
        <?php else: ?><b class="free">رایگان</b><small>بدون هزینه</small><?php endif; ?>
      </div>
      <a class="fr-btn sm<?= $sold ? ' ghost' : '' ?>" href="<?= e($url) ?>"><?= $sold ? 'مشاهده جزئیات' : 'جزئیات و ثبت‌نام' ?></a>
    </div>
  </div>
</article>
    <?php
}
