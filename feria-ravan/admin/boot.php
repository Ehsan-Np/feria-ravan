<?php
/**
 * ============================================================================
 *  بوت مشترک پنل مدیریت — include در تمام صفحات ادمین + چیدمان
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../core/Kavenegar.php';
require_once __DIR__ . '/../core/Zarinpal.php';
require_once __DIR__ . '/../core/Analyzer.php';
fr_bootstrap();

/** چیدمان ادمین (سایدبار) */
function admin_layout(array $admin, string $active, string $title): void
{
    $menu = [
        ['dashboard','📊 داشبورد','/admin/dashboard.php'],
        ['profile','👤 پروفایل من','/admin/profile.php'],
        // نسخهٔ ۱٫۵: دسترسی سریع مدیر به پنل کاربری خودش (سشن کاربر در ورود مدیر ساخته می‌شود)
        // لینک پنل کاربری (اگر مدیر هنوز از صفحهٔ ورودِ سایت وارد نشده باشد، خود صفحهٔ کاربر احراز می‌خواهد)
        ['userpanel','🦢 ' . (function_exists('fr_panel_title') ? fr_panel_title() : 'پنل کاربری'), (function_exists('fr_panel_url') ? fr_panel_url() : '/panel/')],
        ['tests','🧠 تست‌های روانشناسی','/admin/tests.php'],
        ['questions','❓ سوالات','/admin/questions.php'],
        ['templates','📝 قالب‌های تحلیل','/admin/templates.php'],
        ['attempts','📊 نتایج و تحلیل مراجعین','/admin/attempts.php'],
        ['test-fields','📋 فیلدهای سفارشی تست‌ها','/admin/test-fields.php'],
        ['users','👥 کاربران','/admin/users.php'],
        ['fields','🧩 فیلدهای ثبت‌نام','/admin/fields.php'],
        ['discounts','🏷 کدهای تخفیف','/admin/discounts.php'],
        ['orders','💳 سفارش‌ها','/admin/orders.php'],
        ['refunds','↩️ عودت وجه','/admin/refunds.php'],
        ['registrants','🗂 ثبت‌نام‌شدگان','/admin/registrants.php'],
        ['reviews','⭐ دیدگاه‌ها','/admin/reviews.php'],
        ['workshops','🛠 کارگاه‌ها','/admin/workshops.php'],
        ['courses','📚 دوره‌ها','/admin/courses.php'],
        ['consult','🛋 مشاوره: خدمات','/admin/consult.php'],
        ['consult-schedule','🗓 زمان‌بندی مشاوره','/admin/consult-schedule.php'],
        ['consult-bookings','📋 نوبت‌های مشاوره','/admin/consult-bookings.php'],
        ['tickets','🎫 تیکت‌ها','/admin/tickets.php'],
        ['pages','📄 برگه‌ها و صفحه‌ساز','/admin/pages.php'],
        ['permalinks','🔗 پیوندهای یکتا','/admin/permalinks.php'],
        ['banners','🖼 بنرها','/admin/banners.php'],
        ['newsletter','✉️ خبرنامه پیامکی','/admin/newsletter.php'],
        ['notifications','📣 اطلاع‌رسانی اجتماعی','/admin/notifications.php'],
        ['contacts','📇 مخاطبین','/admin/contacts.php'],
        ['campaigns','🎯 کمپین‌های تبلیغاتی','/admin/campaigns.php'],
        ['planner','🗓 برنامهٔ روزانهٔ کاربران','/admin/planner.php'],
        ['sms','📩 قالب‌های پیامک','/admin/sms.php'],
        ['monitor','🛡 رصد فعالیت','/admin/monitor.php'],
        ['texts','✏️ متن‌های سایت','/admin/texts.php'],
        ['roles','🛂 نقش‌ها و مدیران','/admin/roles.php'],
        ['trash','🗑 سطل زباله','/admin/trash.php'],
        ['settings','⚙️ تنظیمات','/admin/settings.php'],
        ['updater','🚀 به‌روزرسانی سامانه','/admin/updater.php'],
    ];
    /* نسخهٔ ۲٫۱: منو بر اساس capهای مؤثر نقشِ مدیر فیلتر می‌شود */
    require_once __DIR__ . '/../includes/roles.php';
    $capOf = fr_page_cap_map();
    $menu = array_values(array_filter($menu, function ($m) use ($admin, $capOf) {
        $f = basename($m[2]);
        $cap = $capOf[$f] ?? null;
        return fr_admin_can($admin, $cap);
    }));
    $openTickets = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE status IN('open','user_reply')");

    /* ══════════════════════════════════════════════════════════════════
       نسخهٔ ۲٫۸: گروه‌بندی اصولی سایدبار — بخش‌های مرتبط زیر هم (accordion)
       ─ هر گروه فقط وقتی نمایش داده می‌شود که حداقل یک زیرشاخهٔ مجاز داشته باشد
       ─ گروهی که صفحهٔ فعال در آن است، به‌صورت خودکار باز می‌ماند
       ─ کنترل دسترسی همان فیلتر قبلیِ روی آیتم‌های تکی است (تغییری نکرده)
     * ══════════════════════════════════════════════════════════════════ */
    $byKey = [];
    foreach ($menu as $m) $byKey[$m[0]] = $m;

    $singlesTop = ['dashboard', 'profile', 'userpanel'];      // آیتم‌های تکی بالای منو
    $groupDefs  = [
        ['id' => 'grp-tests',    'label' => '🧠 تست‌های روانشناسی',      'keys' => ['tests', 'questions', 'templates', 'attempts', 'test-fields']],
        ['id' => 'grp-users',    'label' => '👥 کاربران و ثبت‌نام',      'keys' => ['users', 'fields', 'registrants', 'reviews']],
        ['id' => 'grp-events',   'label' => '🎓 رویدادها',               'keys' => ['workshops', 'courses']],
        ['id' => 'grp-consult',  'label' => '🛋 مشاوره',                 'keys' => ['consult', 'consult-schedule', 'consult-bookings']],
        ['id' => 'grp-sales',    'label' => '💳 فروش و مالی',            'keys' => ['orders', 'discounts', 'refunds', 'tickets']],
        ['id' => 'grp-marketing','label' => '📨 بازاریابی و اطلاع‌رسانی', 'keys' => ['newsletter', 'contacts', 'campaigns', 'notifications', 'planner', 'sms']],
        ['id' => 'grp-content',  'label' => '🖼 محتوا و سئو',            'keys' => ['pages', 'banners', 'texts', 'permalinks']],
        ['id' => 'grp-system',   'label' => '⚙️ سیستم',                  'keys' => ['monitor', 'roles', 'trash', 'settings', 'updater']],
    ];

    /* هر کلیدِ باقی‌مانده (تعریف‌نشده در گروه‌ها) به‌صورت تکی نمایش داده می‌شود */
    $usedKeys = $singlesTop;
    foreach ($groupDefs as $g) $usedKeys = array_merge($usedKeys, $g['keys']);
    $leftover = array_values(array_diff(array_keys($byKey), $usedKeys));

    $renderItem = function (array $m) use ($active, $openTickets): string {
        [$key, $label, $url] = $m;
        $cnt = $key === 'tickets' && $openTickets ? '<span class="cnt">' . fa_num($openTickets) . '</span>' : '';
        $finalUrl = (preg_match('#^https?://#i', $url)) ? $url : rtrim(SITE_URL, '/') . '/' . ltrim($url, '/');
        $target = ($key === 'userpanel') ? ' target="_blank"' : '';
        return '<a class="adm-side ' . ($active === $key ? 'active' : '') . '" href="' . e($finalUrl) . '"' . $target . '>' . $label . $cnt . '</a>';
    };
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= csrf_token() ?>">
<meta name="app-url" content="<?= e(SITE_URL) ?>">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> | مدیریت <?= e(DB::setting('site_title','فریا روان')) ?></title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/feria.css?v=<?= FR_VERSION ?>">
<?= fr_brand_css() ?>
<style>
  .adm-side a .cnt,.adm-side .cnt{margin-right:auto;background:var(--c-danger);color:#fff;border-radius:9px;padding:0 7px;font-size:11px;min-width:18px;text-align:center}
  /* ۲٫۸: گروه‌بندی سایدبار (accordion) */
  .fr-nav details.adm-grp{margin:1px 0}
  .fr-nav details.adm-grp>summary{display:flex;align-items:center;gap:6px;list-style:none;cursor:pointer;padding:9px 12px;border-radius:11px;font-size:13px;font-weight:800;color:#3a3360;user-select:none;transition:background .15s}
  .fr-nav details.adm-grp>summary::-webkit-details-marker{display:none}
  .fr-nav details.adm-grp>summary:hover{background:#ece7fb}
  .fr-nav details.adm-grp>summary .adm-grp-t{flex:1}
  .fr-nav details.adm-grp>summary .adm-grp-caret{font-size:11px;opacity:.55;transition:transform .18s}
  .fr-nav details.adm-grp[open]>summary .adm-grp-caret{transform:rotate(180deg)}
  .fr-nav details.adm-grp .adm-grp-items{padding:2px 16px 6px 4px;display:flex;flex-direction:column;gap:1px;border-right:2px solid #efeafb;margin-right:14px}
  .fr-nav details.adm-grp .adm-grp-items .adm-side{padding:7px 12px;font-size:12.5px}
  .rte-toolbar{display:flex;gap:4px;flex-wrap:wrap;border:1.5px solid #e2e9f5;border-radius:10px 10px 0 0;padding:6px;background:#f8f6ff}
  .rte-toolbar button{border:none;background:#fff;border-radius:8px;width:30px;height:30px;cursor:pointer;font-size:13px}
  .rte-toolbar button:hover{background:#eaf0f8}
  .rte-area{min-height:180px;border:1.5px solid #e2e9f5;border-top:none;border-radius:0 0 10px 10px;padding:12px;background:#fff;font-size:14px;line-height:2;outline:none}
  label.tog{display:inline-flex;align-items:center;gap:8px;font-size:13px;cursor:pointer}
  input[type=checkbox]{accent-color:var(--c-primary);width:17px;height:17px}
</style>
</head>
<body>
<script>window.CSRF='<?= csrf_token() ?>';</script>
<div class="fr-app">
  <aside class="fr-sidebar">
    <div class="fr-brand"><?= fr_logo_html('mark') ?>
      <div><b>پیشخوان مدیریت</b><small><a href="<?= SITE_URL ?>/admin/profile.php" style="color:inherit"><?= e($admin['display_name'] ?? $admin['username']) ?></a> (<?= e($admin['role']) ?>)</small></div>
    </div>
    <nav class="fr-nav">
      <?php foreach ($singlesTop as $k): if (isset($byKey[$k])) echo $renderItem($byKey[$k]); endforeach; ?>

      <?php foreach ($groupDefs as $g):
        $items = [];
        foreach ($g['keys'] as $k) if (isset($byKey[$k])) $items[] = $byKey[$k];
        if (!$items) continue;                                  // گروه خالی (بدون دسترسی) نمایش داده نمی‌شود
        $hasActive = false;
        foreach ($items as $it) if ($it[0] === $active) { $hasActive = true; break; }
        ?>
      <details class="adm-grp" id="<?= e($g['id']) ?>"<?= $hasActive ? ' open' : '' ?>>
        <summary><span class="adm-grp-t"><?= e($g['label']) ?></span><span class="adm-grp-caret" aria-hidden="true">▾</span></summary>
        <div class="adm-grp-items">
          <?php foreach ($items as $it) echo $renderItem($it); ?>
        </div>
      </details>
      <?php endforeach; ?>

      <?php foreach ($leftover as $k): echo $renderItem($byKey[$k]); endforeach; ?>

      <a class="adm-side" href="<?= SITE_URL ?>/" target="_blank">🌐 نمایش سایت</a>
      <a class="adm-side" href="<?= SITE_URL ?>/admin/logout.php">🚪 خروج</a>
    </nav>
  </aside>
  <div class="fr-overlay"></div>
  <main class="fr-main">
    <div class="fr-topbar">
      <button class="fr-hamburger icon-btn" style="width:42px;height:42px;border-radius:12px;border:1.5px solid var(--line);background:var(--panel)">☰</button>
      <h2 style="font-size:17px;margin:0"><?= e($title) ?></h2>
    </div>
    <?php if (!empty($_SESSION['flash'])) echo flash(); ?>
    <?php
}
function admin_layout_end(): void
{
    echo '</main></div><script src="' . SITE_URL . '/assets/js/feria.js?v=' . FR_VERSION . '"></script></body></html>';
}

/** پیام flash ساده با سشن */
if (!function_exists('flash')) {
    function flash(?string $msg = null, string $type = 'ok'): ?string
    {
        if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
        if (!empty($_SESSION['flash'])) {
            [$m,$t] = $_SESSION['flash']; unset($_SESSION['flash']);
            return '<div class="fr-alert ' . e($t) . '" style="margin-bottom:16px">' . e($m) . '</div>';
        }
        return '';
    }
}

/** خواندن مقدار POST بولی/رشته‌ای امن */
if (!function_exists('post')) {
    function post(string $k, $default = '') { return $_POST[$k] ?? $default; }
}
