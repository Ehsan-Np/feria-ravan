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
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/consult.php';
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
        ['menus','📋 فهرست‌ها و منوها','/admin/menus.php'],
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
        ['id' => 'grp-content',  'label' => '🖼 محتوا و سئو',            'keys' => ['pages', 'menus', 'banners', 'texts', 'permalinks']],
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

  /* ==========================================================================
     ویرایشگر متنی استاندارد وردپرس (دیداری / متن - کد HTML)
     ========================================================================== */
  .fr-wp-editor {
    border: 1.5px solid #cbd5e1;
    border-radius: 12px;
    background: #fff;
    margin-bottom: 14px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    transition: border-color .2s;
  }
  .fr-wp-editor:focus-within {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.12);
  }
  .fr-wp-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f1f5f9;
    padding: 6px 12px 0 12px;
    border-bottom: 1px solid #cbd5e1;
    user-select: none;
  }
  .fr-wp-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: -1px;
  }
  .fr-wp-tab {
    padding: 6px 14px;
    font-size: 12.5px;
    font-weight: 600;
    border: 1px solid #cbd5e1;
    border-bottom: none;
    border-radius: 8px 8px 0 0;
    background: #e2e8f0;
    color: #475569;
    cursor: pointer;
    transition: all .15s;
    outline: none;
  }
  .fr-wp-tab:hover {
    background: #f8fafc;
    color: #0f172a;
  }
  .fr-wp-tab.active {
    background: #fff;
    color: #1e40af;
    font-weight: 800;
    border-bottom: 1px solid #fff;
    box-shadow: 0 -2px 4px rgba(0,0,0,0.03);
  }
  .fr-wp-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
    padding: 8px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
  }
  .fr-wp-toolbar button, .fr-wp-quicktags button {
    border: 1px solid #cbd5e1;
    background: #fff;
    border-radius: 6px;
    height: 30px;
    min-width: 30px;
    padding: 0 6px;
    font-size: 12.5px;
    color: #1e293b;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .12s;
  }
  .fr-wp-toolbar button:hover, .fr-wp-quicktags button:hover {
    background: #eff6ff;
    border-color: #93c5fd;
    color: #1d4ed8;
  }
  .fr-wp-select {
    height: 30px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #fff;
    font-size: 12px;
    padding: 0 8px;
    color: #1e293b;
    outline: none;
  }
  .fr-wp-sep {
    width: 1px;
    height: 20px;
    background: #cbd5e1;
    margin: 0 4px;
  }
  .fr-wp-quicktags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 8px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
  }
  .fr-wp-quicktags button {
    font-family: monospace;
    font-size: 12px;
    font-weight: 700;
  }
  .fr-wp-visual-canvas {
    min-height: 220px;
    max-height: 600px;
    overflow-y: auto;
    padding: 14px 18px;
    font-size: 14px;
    line-height: 2.2;
    color: #1e293b;
    outline: none;
    background: #fff;
    direction: rtl;
    text-align: right;
  }
  .fr-wp-code-area {
    display: block;
    width: 100%;
    min-height: 220px;
    max-height: 600px;
    padding: 14px 18px;
    font-family: Consolas, Monaco, "Courier New", monospace;
    font-size: 13.5px;
    line-height: 1.8;
    color: #f8fafc;
    background: #0f172a;
    border: none;
    border-radius: 0;
    direction: ltr;
    text-align: left;
    tab-size: 2;
    resize: vertical;
    outline: none;
    box-sizing: border-box;
  }
  .fr-rich-content, .fr-wp-visual-canvas {
    font-size: 14px;
    line-height: 2.2;
  }
  .fr-rich-content h2, .fr-wp-visual-canvas h2 { font-size: 18px; font-weight: 800; margin: 12px 0 6px; color: #0f172a; }
  .fr-rich-content h3, .fr-wp-visual-canvas h3 { font-size: 16px; font-weight: 800; margin: 10px 0 5px; color: #1e293b; }
  .fr-rich-content h4, .fr-wp-visual-canvas h4 { font-size: 14.5px; font-weight: 700; margin: 8px 0 4px; color: #334155; }
  .fr-rich-content p, .fr-wp-visual-canvas p { margin: 6px 0; }
  .fr-rich-content ul, .fr-wp-visual-canvas ul { list-style: disc; padding-right: 22px; margin: 8px 0; }
  .fr-rich-content ol, .fr-wp-visual-canvas ol { list-style: decimal; padding-right: 22px; margin: 8px 0; }
  .fr-rich-content li, .fr-wp-visual-canvas li { margin-bottom: 4px; }
  .fr-rich-content blockquote, .fr-wp-visual-canvas blockquote {
    margin: 10px 0;
    padding: 10px 16px;
    border-right: 4px solid #3b82f6;
    background: #eff6ff;
    border-radius: 0 10px 10px 0;
    color: #1e40af;
  }
  .fr-callout {
    margin: 12px 0;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13.5px;
    line-height: 2;
  }
  .fr-callout.info { background: #eff6ff; border: 1.5px solid #bfdbfe; color: #1e40af; }
  .fr-callout.success { background: #f0fdf4; border: 1.5px solid #bbf7d0; color: #166534; }
  .fr-callout.warn { background: #fffbeb; border: 1.5px solid #fde68a; color: #92400e; }
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
    echo '</main></div>';
    echo '<script src="' . SITE_URL . '/assets/js/feria.js?v=' . FR_VERSION . '"></script>';
    echo '<script>if(window.initWpEditors) window.initWpEditors();</script>';
    echo '</body></html>';
}

/**
 * ساخت ویرایشگر متنی پیشرفته و استاندارد وردپرس (دیداری / متن - کد HTML)
 * با پشتیبانی کامل از تگ‌های HTML، کوئیک‌تگز، قالب‌بندی پاراگراف و کادرهای راهنما
 */
if (!function_exists('fr_wp_editor')) {
    function fr_wp_editor(string $name, ?string $value, string $id, string $label = '', string $hint = '', int $minHeight = 220): void
    {
        $val = (string)($value ?? '');
        ?>
        <div class="fr-wp-editor" id="wrap-<?= e($id) ?>" data-editor-id="<?= e($id) ?>">
          <div class="fr-wp-header">
            <span style="font-size:13px;font-weight:800;color:#0f172a;display:inline-flex;align-items:center;gap:6px">
              <?= e($label ?: $name) ?>
            </span>
            <div class="fr-wp-tabs">
              <button type="button" class="fr-wp-tab active" data-tab-btn="visual" data-for="<?= e($id) ?>" title="ویرایش دیداری">دیداری</button>
              <button type="button" class="fr-wp-tab" data-tab-btn="code" data-for="<?= e($id) ?>" title="ویرایش کد منبع HTML">متن (HTML)</button>
            </div>
          </div>

          <!-- تولبار حالت دیداری (Visual Toolbar) -->
          <div class="fr-wp-toolbar" id="tb-<?= e($id) ?>">
            <button type="button" data-cmd="bold" title="پررنگ (Ctrl+B)"><b>B</b></button>
            <button type="button" data-cmd="italic" title="کج (Ctrl+I)"><i>I</i></button>
            <button type="button" data-cmd="underline" title="زیرخط (Ctrl+U)"><u>U</u></button>
            <button type="button" data-cmd="strikeThrough" title="خط‌خورده"><s>S</s></button>
            <span class="fr-wp-sep"></span>
            <select class="fr-wp-select" data-cmd="formatBlock" title="قالب بند">
              <option value="<p>">پاراگراف (متن عادی)</option>
              <option value="<h2>">تیتر بزرگ (H2)</option>
              <option value="<h3>">تیتر متوسط (H3)</option>
              <option value="<h4>">تیتر کوچک (H4)</option>
            </select>
            <span class="fr-wp-sep"></span>
            <button type="button" data-cmd="insertUnorderedList" title="لیست نشانه‌دار">•≡</button>
            <button type="button" data-cmd="insertOrderedList" title="لیست شماره‌دار">۱≡</button>
            <button type="button" data-cmd="formatBlock|blockquote" title="نقل‌قول">❝</button>
            <span class="fr-wp-sep"></span>
            <button type="button" data-cmd="justifyRight" title="راست‌چین">⫷</button>
            <button type="button" data-cmd="justifyCenter" title="وسط‌چین">≡</button>
            <button type="button" data-cmd="justifyLeft" title="چپ‌چین">⫸</button>
            <span class="fr-wp-sep"></span>
            <button type="button" data-cmd="__link" title="درج پیوند">🔗</button>
            <button type="button" data-cmd="unlink" title="حذف پیوند">⛓️‍💥</button>
            <button type="button" data-cmd="__image" title="آپلود و درج تصویر">🖼️</button>
            <span class="fr-wp-sep"></span>
            <button type="button" data-cmd="__box_info" title="کادر راهنمای آبی" style="font-size:11.5px">ℹ️ راهنما</button>
            <button type="button" data-cmd="__box_success" title="کادر نکته سبز" style="font-size:11.5px">✅ نکته</button>
            <button type="button" data-cmd="__box_warn" title="کادر هشدار زرد" style="font-size:11.5px">⚠️ هشدار</button>
            <span class="fr-wp-sep"></span>
            <button type="button" data-cmd="removeFormat" title="پاک‌سازی فرمت‌بندی">🧹</button>
            <button type="button" data-cmd="undo" title="بازگشت (Undo)">↩</button>
            <button type="button" data-cmd="redo" title="تکرار (Redo)">↪</button>
          </div>

          <!-- تولبار کوئیک‌تگز در حالت کد (مشابه ویرایشگر متنی وردپرس) -->
          <div class="fr-wp-quicktags" id="qt-<?= e($id) ?>" style="display:none">
            <button type="button" data-tag="b">b</button>
            <button type="button" data-tag="i">i</button>
            <button type="button" data-tag="u">u</button>
            <button type="button" data-tag="link">link</button>
            <button type="button" data-tag="b-quote">b-quote</button>
            <button type="button" data-tag="del">del</button>
            <button type="button" data-tag="ins">ins</button>
            <button type="button" data-tag="img">img</button>
            <button type="button" data-tag="ul">ul</button>
            <button type="button" data-tag="ol">ol</button>
            <button type="button" data-tag="li">li</button>
            <button type="button" data-tag="code">code</button>
            <button type="button" data-tag="h2">h2</button>
            <button type="button" data-tag="h3">h3</button>
            <button type="button" data-tag="box-info">کادر راهنما</button>
            <button type="button" data-tag="box-warn">کادر هشدار</button>
            <button type="button" data-tag="close">بستن تگ‌ها</button>
          </div>

          <!-- بوم ویرایش دیداری (WYSIWYG) -->
          <div class="fr-wp-visual-canvas rte-area" id="rte-<?= e($id) ?>" contenteditable="true" style="min-height:<?= (int)$minHeight ?>px"><?= $val ?></div>

          <!-- کادر متنی ویرایش کد منبع HTML -->
          <textarea class="fr-wp-code-area" id="code-<?= e($id) ?>" style="display:none;min-height:<?= (int)$minHeight ?>px" placeholder="کدهای HTML را اینجا وارد یا ویرایش کنید..."><?= htmlspecialchars($val) ?></textarea>

          <!-- فیلد مخفی برای ارسال استاندارد فرم به سرور -->
          <input type="hidden" name="<?= e($name) ?>" id="hd-<?= e($id) ?>" value="<?= htmlspecialchars($val) ?>">

          <?php if ($hint): ?>
            <div style="background:#f8fafc;padding:7px 14px;border-top:1px solid #f1f5f9;font-size:11.5px;color:#64748b">
              💡 <?= e($hint) ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }
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
