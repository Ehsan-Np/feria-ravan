<?php
/**
 * ============================================================================
 *  موتور قالب — سربرگ/زیربرگ عمومی و چیدمان پنل (سایدبار + تاپ‌بار)
 *  رنگ‌ها و فونت‌ها از تنظیمات دیتابیس تزریق می‌شوند (شخصی‌سازی از ادمین)
 * ============================================================================
 */
declare(strict_types=1);

/** تزریق متغیرهای CSS برندینگ + @font-face خودکار فونت‌های فارسی آپلودی */
function fr_brand_css(): string
{
    $g = fn(string $k, string $d) => e((string)DB::setting($k, $d));
    $css = '';
    /* اسکن پوشهٔ فونت‌ها: اگر فایل {Name}.woff2 یا {Name}.woff موجود باشد، @font-face تولید می‌شود */
    $fontDir = FR_ROOT . '/assets/fonts';
    $urls    = SITE_URL . '/assets/fonts';
    foreach (array_unique([$g('font_main','Vazirmatn'), $g('font_headings','Vazirmatn')]) as $fam) {
        $famClean = preg_replace('/[^A-Za-z0-9 _\-]/', '', $fam);
        // نسخهٔ ۱٫۶: پشتیبانی از وزن‌های جداگانه (Name.woff2 → ۴۰۰، Name-Medium.woff2 → ۵۰۰ …)
        $weights = ['' => '400', '-Medium' => '500', '-SemiBold' => '600', '-Bold' => '700'];
        $found   = false;
        foreach ($weights as $suf => $wt) {
            $file = $famClean . $suf . '.woff2';
            if (is_file($fontDir . '/' . $file)) {
                $css .= "@font-face{font-family:'$famClean';src:url('$urls/$file') format('woff2');font-weight:$wt;font-display:swap}";
                $found = true;
            }
        }
        if (!$found) {
            // سازگاری با فایل‌های تکیِ قدیمی (woff/woff2.woff/ttf)
            foreach (["$famClean.woff", "$famClean.woff2.woff", "$famClean.ttf"] as $file) {
                if (is_file($fontDir . '/' . $file)) {
                    $fmt = str_ends_with($file, 'ttf') ? 'truetype' : 'woff';
                    $css .= "@font-face{font-family:'$famClean';src:url('$urls/$file') format('$fmt');font-display:swap}";
                    break;
                }
            }
        }
    }
    return '<style>' . $css . ':root{'
        . '--c-primary:' . $g('color_primary', '#1e3a6e') . ';'
        . '--c-secondary:' . $g('color_secondary', '#3e5f9e') . ';'
        . '--font-main:\'' . $g('font_main', 'Vazirmatn') . '\',Tahoma,sans-serif;'
        . '}</style>';
}

/** سربرگ HTML */
function render_header(string $title, array $opts = []): void
{
    $siteTitle = (string)DB::setting('site_title', 'فریا روان');
    $full = $title ? ($title . ' | ' . $siteTitle) : $siteTitle;
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/svg+xml" href="<?= SITE_URL ?>/assets/img/favicon.svg">
<meta name="csrf" content="<?= csrf_token() ?>">
<meta name="app-url" content="<?= e(SITE_URL) ?>">
<?php if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/panel/') || (function_exists('fr_panel_slug') && str_contains($_SERVER['REQUEST_URI'] ?? '', '/' . fr_panel_slug()))): ?>
<base href="<?= e(fr_panel_url()) ?>">
<?php endif; ?>
<title><?= e($full) ?></title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/feria.css?v=<?= FR_VERSION ?>">
<?php foreach (($opts['css'] ?? []) as $extraCss): /* استایل‌های تکمیلی هر صفحه */ ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/<?= e($extraCss) ?>?v=<?= FR_VERSION ?>">
<?php endforeach; ?>
<?= fr_brand_css() ?>
</head>
<body>
<script>window.CSRF='<?= csrf_token() ?>';</script>
    <?php
}

/** زیربرگ HTML */
function render_footer(): void
{
    ?>
<script src="<?= SITE_URL ?>/assets/js/feria.js?v=<?= FR_VERSION ?>"></script>
</body>
</html>
    <?php
}

/** آیا یک بخش از پنل کاربری از تنظیمات فعال است؟ (۱٫۷ — شخصی‌سازی مدیر) */
function fr_panel_visible(string $section): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (['wallet','payments','history','tickets'] as $k) {
            $cache[$k] = DB::setting('panel_menu_' . $k, '1') !== '0';
        }
    }
    return $cache[$section] ?? true;
}

/** محافظ صفحهٔ پنل در برابر غیرفعال‌شدن بخش از سمت مدیر (۱٫۷) */
function fr_panel_guard(string $section): void
{
    if (!fr_panel_visible($section)) { redirect(fr_panel_url('?off=' . $section)); }
}

/* ---------------------------------------------------------------------------
 | چیدمان پنل کاربری (سایدبار راست — منطبق بر طراحی)
 | نسخهٔ ۱٫۷: بخش‌ها بر اساس تنظیمات مدیر نمایش/پنهان می‌شوند (تب «پنل کاربری» تنظیمات)
 * -------------------------------------------------------------------------*/
function panel_layout(array $user, string $active): void
{
    $u      = $user;
    $name   = user_fullname($u);
    $avatar = $u['avatar'] ?: (SITE_URL . '/assets/img/avatar.svg');
    $items  = [
        ['داشبورد', 'dashboard', '🏠', fr_panel_url()],
        ['تست‌های روانشناسی', 'tests', '🧠', fr_panel_url('tests.php')],
        /* نسخهٔ ۲٫۲: تفکیک کامل کارگاه‌ها و دوره‌ها به دو گزینهٔ مجزا */
        ['کارگاه‌ها', 'workshops', '🛠', fr_panel_url('workshops.php')],
        ['دوره‌های آموزشی', 'courses', '📚', fr_panel_url('courses.php')],
        ['نوبت‌های مشاوره', 'consult', '🛋', fr_panel_url('consult.php')],
        ['برنامهٔ روزانه', 'planner', '🗓', fr_panel_url('planner.php')],
        ['کیف پول و عودت وجه', 'wallet', '👛', fr_panel_url('wallet.php')],
        ['تاریخچه تست‌های روانشناسی', 'history', '📋', fr_panel_url('history.php')],
        ['تراکنش‌ها', 'payments', '💳', fr_panel_url('payments.php')],
        ['پروفایل', 'profile', '👤', fr_panel_url('profile.php')],
        ['تیکت پشتیبانی', 'tickets', '🎫', fr_panel_url('tickets.php')],
        ['خروج', 'logout', '🚪', fr_panel_url('logout.php')],
    ];
    // حذف بخش‌های غیرفعال‌شده توسط مدیر (داشبورد/پروفایل/خروج همیشه می‌مانند)
    $items = array_values(array_filter($items, fn($it) =>
        !in_array($it[1], ['wallet','payments','history','tickets'], true) || fr_panel_visible($it[1])));
    // نسخهٔ ۲٫۱: برنامهٔ روزانه با تنظیم مدیر کنترل می‌شود
    $items = array_values(array_filter($items, fn($it) =>
        $it[1] !== 'planner' || (string)DB::setting('planner_enabled','1') === '1'));
    ?>
<div class="fr-app">
  <aside class="fr-sidebar" id="frSidebar">
    <div class="fr-brand">
      <?= fr_logo_html('mark') ?>
      <div><b><?= e(DB::setting('site_title','فریا روان')) ?></b>
      <small><?= e(DB::setting('site_slogan','')) ?></small></div>
    </div>
    <nav class="fr-nav">
      <?php foreach ($items as [$label,$key,$icon,$url]): ?>
      <a href="<?= $url ?>" class="<?= $active === $key ? 'active' : '' ?>">
        <span class="icn"><?= $icon ?></span><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <?php $banner = DB::one("SELECT * FROM " . DB::t('banners') .
          " WHERE active=1 AND placement='sidebar' AND (starts_at IS NULL OR starts_at<=NOW())
            AND (expires_at IS NULL OR expires_at>=NOW()) ORDER BY sort_order LIMIT 1");
        if ($banner): ?>
        <a class="fr-upgrade" href="<?= e($banner['link'] ?: '#') ?>" style="display:block">
          <img src="<?= e($banner['image']) ?>" alt="<?= e($banner['title']) ?>" style="border-radius:12px">
          <div style="margin-top:8px;font-weight:700"><?= e($banner['title']) ?></div>
        </a>
      <?php endif; ?>
      <?php
      /* یادداشت کوچک سایدبار از تنظیمات مدیر (تب «پنل کاربری») — ۱٫۷ */
      if (($sideNote = trim((string)DB::setting('panel_sidebar_note',''))) !== ''): ?>
      <div style="margin-top:10px;font-size:11.5px;color:var(--muted);line-height:1.9;text-align:center;background:#f7f4ff;border:1px dashed #e2e9f5;border-radius:12px;padding:8px 10px">
        <?= e($sideNote) ?>
      </div>
      <?php endif; ?>
    </div>
  </aside>
  <div class="fr-overlay" id="frOverlay"></div>

  <main class="fr-main">
    <div class="fr-topbar">
      <button class="fr-hamburger icon-btn" id="frBurger" style="width:42px;height:42px;border-radius:12px;border:1.5px solid var(--line);background:var(--panel)">☰</button>
      <div class="fr-search">
        <span class="ic">🔎</span>
        <input type="text" id="globalSearch" placeholder="جستجو در تست‌های روانشناسی...">
      </div>
      <div class="fr-userchip">
        <?php
        /* اگر مدیر در همین مرورگر به پیشخوان (از صفحهٔ /admin/) وارد شده باشد،
           لینک مستقیم به پیشخوان نشان داده می‌شود؛ ورود کاربر به‌تنهایی این دسترسی را نمی‌سازد */
        if (!function_exists('current_admin')) { require_once dirname(__FILE__) . '/admin-auth.php'; }
        if (current_admin()): ?>
        <a href="<?= SITE_URL ?>/admin/dashboard.php" class="fr-btn sm"
           style="background:linear-gradient(135deg,#1c2740,#3b2b86);box-shadow:0 4px 14px rgba(34,28,70,.30)"
           title="شما مدیر سایت هستید — ورود به پیشخوان مدیریت">🛡 پیشخوان مدیریت</a>
        <?php endif; ?>
        <img class="ava" src="<?= e($avatar) ?>" alt="">
        <div><b style="font-size:14px"><?= e($name) ?></b><br>
        <small style="color:var(--muted)"><?= fa_num(jdate('l j F Y')) ?></small></div>
      </div>
    </div>
    <?php
}

/** بستن چیدمان پنل */
function panel_layout_end(): void
{
    echo '</main></div>';
}

/* ---------------------------------------------------------------------------
 | کارت آمار کوچک داشبورد
 * -------------------------------------------------------------------------*/
function stat_card(string $icon, string $label, string $value, string $color = ''): void
{
    ?>
    <div class="fr-card" style="display:flex;align-items:center;gap:16px;margin-bottom:0">
      <span style="width:52px;height:52px;border-radius:14px;background:<?= $color ?: '#eaf0f8' ?>;display:flex;align-items:center;justify-content:center;font-size:24px"><?= $icon ?></span>
      <div><div style="font-size:22px;font-weight:800"><?= $value ?></div>
      <div style="color:var(--muted);font-size:13px"><?= e($label) ?></div></div>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 | نسخهٔ ۲٫۰ — بارکد Code39 به‌صورت SVG خالص (بدون وابستگی — جایگزینِ QR چاپی)
 | از panel/barcode.php به این‌جا منتقل شد تا هر دو صفحه از یک منبع استفاده کنند.
 * -------------------------------------------------------------------------*/
function barcode39_svg(string $code, int $h = 70): string
{
    static $map = ['0'=>'111331311','1'=>'311311113','2'=>'113311113','3'=>'313311111',
      '4'=>'111331113','5'=>'311331111','6'=>'113331111','7'=>'111311313','8'=>'311311311',
      '9'=>'113311311','A'=>'311113113','B'=>'113113113','C'=>'313113111','D'=>'111133113',
      'E'=>'311133111','F'=>'113133111','G'=>'111113313','H'=>'311113311','I'=>'113113311',
      'J'=>'111133311','K'=>'311111133','L'=>'113111133','M'=>'313111131','N'=>'111131133',
      'O'=>'311131131','P'=>'113131131','Q'=>'111111333','R'=>'311111331','S'=>'113111331',
      'T'=>'111131331','U'=>'331111113','V'=>'133111113','W'=>'333111111','X'=>'131131113',
      'Y'=>'331131111','Z'=>'133131111','-'=>'131111313','.'=>'331111311',' '=>'133111311','*'=>'131111131'];
    $code = '*' . strtoupper(preg_replace('/[^0-9A-Z\-. ]/', '', $code)) . '*';
    $x = 0; $bars = '';
    for ($i = 0; $i < strlen($code); $i++) {
        $pat = $map[$code[$i]] ?? $map['*'];
        for ($b = 0; $b < 9; $b++) {
            $w = $pat[$b] === '3' ? 3 : 1;
            if ($b % 2 === 0) $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $h . '" fill="#1c2740"/>';
            $x += $w;
        }
        $x += 1;
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $x . '" height="' . ($h + 22) . '">'
         . $bars . '<text x="' . ($x/2) . '" y="' . ($h + 16) . '" text-anchor="middle" font-size="13" font-family="monospace">' . e($code) . '</text></svg>';
}
