<?php
/**
 * ============================================================================
 *  مدیریت برگه‌ها، سربرگ‌ها، پاورقی‌ها و صفحه‌ساز دیداری
 *  ─ نگارش ۳٫۷٫۰: مدیریت ساختار و چیدمان صفحه اصلی، برگه‌ها، سربرگ و پاورقی دلخواه
 *  ─ اتصال به فهرست‌ساز و ویجت‌های اختصاصی هدر و فوتر فریا روان
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/core/PageBuilder.php';
require_once FR_ROOT . '/core/MenuBuilder.php';

$adm = require_admin(['superadmin', 'manager']);
PageBuilder::ensureSchema();
MenuBuilder::ensureSchema();

$curTab = (string)($_GET['tab'] ?? 'page');
$validTabs = ['all', 'page', 'tests', 'courses', 'workshops', 'consult', 'header', 'footer'];
if (!in_array($curTab, $validTabs, true)) {
    $curTab = 'page';
}

// پردازش عملیات‌های POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');

    if ($act === 'save') {
        $id       = (int)post('id');
        $title    = trim((string)post('title'));
        $name     = trim((string)post('name')) ?: $title;
        $slug     = trim((string)post('slug'));
        $pageType = (string)post('page_type', 'page');

        if ($title === '') {
            flash('عنوان الزامی است.', 'err');
            redirect(SITE_URL . '/admin/pages.php?tab=' . $pageType);
        }

        $data = [
            'name'        => $name,
            'title'       => $title,
            'slug'        => $slug,
            'page_type'   => $pageType,
            'layout'      => (string)post('layout', 'default'),
            'seo_title'   => (string)post('seo_title'),
            'meta_desc'   => (string)post('meta_desc'),
            'noindex'     => !empty(post('noindex')) ? 1 : 0,
            'is_home'     => !empty(post('is_home')) ? 1 : 0,
            'is_default'  => !empty(post('is_default')) ? 1 : 0,
            'active'      => !empty(post('active')) ? 1 : 0,
        ];

        // محتوای اولیه در صورت ساخت هدر، فوتر یا بخش‌های اختصاصی جدید
        if ($id <= 0) {
            if ($pageType === 'header') {
                $data['content_html'] = '<header class="fr-custom-header" style="background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px"><div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px"><div data-widget="site_logo"></div><div data-widget="menu" data-location="primary"></div><div style="display:flex;align-items:center;gap:10px"><div data-widget="user_auth"></div><div data-widget="header_cta"></div></div></div></header>';
            } elseif ($pageType === 'footer') {
                $data['content_html'] = '<footer class="fr-custom-footer" style="background:#0f172a;color:#f8fafc;padding:50px 24px 20px;border-top:3px solid #3b82f6"><div style="max-width:1200px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:32px;margin-bottom:30px"><div data-widget="footer_brand"></div><div><h3 style="font-size:15px;font-weight:700;color:#38bdf8;margin:0 0 16px">فهرست و دسترسی سریع</h3><div data-widget="footer_menu" data-location="footer"></div></div><div data-widget="footer_contact"></div><div data-widget="footer_badges"></div></div><div style="border-top:1px solid #1e293b;padding-top:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;font-size:12.5px;color:#94a3b8"><div data-widget="copyright"></div><div data-widget="social_icons"></div></div></footer>';
            } elseif ($pageType === 'tests') {
                $data['content_html'] = PageBuilder::getDefaultTestsHtml();
            } elseif ($pageType === 'courses') {
                $data['content_html'] = PageBuilder::getDefaultCoursesHtml();
            } elseif ($pageType === 'workshops') {
                $data['content_html'] = PageBuilder::getDefaultWorkshopsHtml();
            } elseif ($pageType === 'consult') {
                $data['content_html'] = PageBuilder::getDefaultConsultHtml();
            }
        }

        $savedId = PageBuilder::savePage($data, $id > 0 ? $id : null);
        if ($id > 0) {
            flash('مشخصات با موفقیت به‌روزرسانی شد ✅', 'ok');
            redirect(SITE_URL . '/admin/pages.php?tab=' . $pageType);
        } else {
            flash('مورد جدید با موفقیت ایجاد شد 🎉 اکنون می‌توانید با صفحه‌ساز آن را طراحی کنید.', 'ok');
            redirect(SITE_URL . '/admin/page-builder.php?id=' . $savedId);
        }
    }

    if ($act === 'seed_defaults') {
        PageBuilder::seedDefaultPages();
        flash('قالب‌های پیش‌فرض بخش‌های سامانه (تست‌ها، دوره‌ها، کارگاه‌ها، مشاوره و درباره ما) با موفقیت همگام‌سازی و بارگذاری شدند 🎉', 'ok');
        redirect(SITE_URL . '/admin/pages.php?tab=' . $curTab);
    }

    if ($act === 'set_home') {
        $pageId = (int)post('id');
        if (PageBuilder::setHomepage($pageId)) {
            $p = PageBuilder::getPage($pageId);
            flash('برگه «' . e($p['title']) . '» به عنوان صفحه اصلی سایت تنظیم گردید ⭐️', 'ok');
        }
        redirect(SITE_URL . '/admin/pages.php?tab=page');
    }

    if ($act === 'set_default_template') {
        $id   = (int)post('id');
        $type = (string)post('type');
        if (PageBuilder::setActiveTemplate($id, $type)) {
            $label = ($type === 'header') ? 'سربرگ' : 'پاورقی';
            flash("قالب به عنوان {$label} پیش‌فرض و سراسری سایت تنظیم شد ⭐️", 'ok');
        }
        redirect(SITE_URL . '/admin/pages.php?tab=' . $type);
    }

    if ($act === 'unset_default_template') {
        $id   = (int)post('id');
        $type = (string)post('type');
        PageBuilder::unsetActiveTemplate($id);
        $label = ($type === 'header') ? 'سربرگ' : 'پاورقی';
        flash("قالب {$label} از حالت پیش‌فرض خارج شد (نمایش قالب کلاسیک سایت).", 'ok');
        redirect(SITE_URL . '/admin/pages.php?tab=' . $type);
    }

    if ($act === 'toggle_active') {
        $pageId = (int)post('id');
        $p = PageBuilder::getPage($pageId);
        if ($p) {
            $newSt = (int)$p['active'] === 1 ? 0 : 1;
            DB::update('site_pages', ['active' => $newSt], 'id=?', [$pageId]);
            flash('وضعیت انتشار تغییر یافت.', 'ok');
        }
        redirect(SITE_URL . '/admin/pages.php?tab=' . ($p['page_type'] ?? 'page'));
    }

    if ($act === 'duplicate') {
        $pageId = (int)post('id');
        $newId = PageBuilder::duplicatePage($pageId);
        if ($newId) {
            flash('نسخه کپی با موفقیت ایجاد شد (پیش‌نویس) 📋', 'ok');
        }
        $p = PageBuilder::getPage($pageId);
        redirect(SITE_URL . '/admin/pages.php?tab=' . ($p['page_type'] ?? 'page'));
    }

    if ($act === 'delete') {
        $pageId = (int)post('id');
        $p = PageBuilder::getPage($pageId);
        $type = $p['page_type'] ?? 'page';
        if (PageBuilder::deletePage($pageId)) {
            flash('مورد با موفقیت حذف گردید.', 'ok');
        } else {
            flash('امکان حذف برگه فعال صفحه اصلی وجود ندارد؛ ابتدا برگه دیگری را انتخاب کنید.', 'err');
        }
        redirect(SITE_URL . '/admin/pages.php?tab=' . $type);
    }
}

// واکشی داده‌ها بر اساس تب جاری
$allPages = PageBuilder::getPages();
if ($curTab === 'all') {
    $pages = $allPages;
} else {
    $pages = array_values(array_filter($allPages, fn($p) => ($p['page_type'] ?? 'page') === $curTab));
}

$homePage     = PageBuilder::getHomepage();
$activeHeader = PageBuilder::getActiveHeader();
$activeFooter = PageBuilder::getActiveFooter();

$tabDefs = [
    'all'       => ['title' => 'همه موارد', 'icon' => '📁'],
    'page'      => ['title' => 'برگه‌ها', 'icon' => '📄'],
    'tests'     => ['title' => 'آزمون‌ها', 'icon' => '🧠'],
    'courses'   => ['title' => 'دوره‌ها', 'icon' => '🎓'],
    'workshops' => ['title' => 'کارگاه‌ها', 'icon' => '👥'],
    'consult'   => ['title' => 'مشاوره', 'icon' => '💬'],
    'header'    => ['title' => 'سربرگ‌ها', 'icon' => '🔝'],
    'footer'    => ['title' => 'پاورقی‌ها', 'icon' => '🔻'],
];

admin_layout($adm, 'pages', 'مدیریت برگه‌ها، سربرگ و پاورقی');
echo flash();
?>

<!-- هدر و نوار ابزار اصلی -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0;font-size:22px;color:#0f172a">📄 مدیریت برگه‌ها، سربرگ و پاورقی (Header & Footer Builder)</h2>
    <p style="margin:4px 0 0;font-size:13px;color:#64748b">
      طراحی کامل سربرگ، پاورقی، صفحه اصلی و تمام بخش‌ها (آزمون‌ها، دوره‌ها، کارگاه‌ها، مشاوره) با صفحه‌ساز دیداری فریا روان.
    </p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <form method="post" style="display:inline" onsubmit="return confirm('آیا می‌خواهید صفحات و قالب‌های پیش‌فرض بخش‌های آزمون، دوره، کارگاه، مشاوره و درباره ما بارگذاری و همگام شوند؟');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="seed_defaults">
      <button type="submit" class="fr-btn sm ghost" style="text-decoration:none;background:#f8fafc;border-color:#cbd5e1;font-weight:700">
        ⚡️ بارگذاری / بازنشانی قالب‌های پیش‌فرض
      </button>
    </form>
    <a class="fr-btn sm ghost" href="menus.php" style="text-decoration:none;background:#f1f5f9;border-color:#cbd5e1;font-weight:700">
      📋 فهرست‌ساز و منوها (Menu Builder)
    </a>
    <button type="button" class="fr-btn sm primary" onclick="openNewModal('<?= $curTab === 'all' ? 'page' : e($curTab) ?>')">
      + ساخت مورد جدید
    </button>
  </div>
</div>

<!-- تب‌های ناوبری بخش‌ها -->
<div style="display:flex;gap:6px;border-bottom:2px solid #e2e8f0;margin-bottom:24px;overflow-x:auto;padding-bottom:2px">
  <?php foreach ($tabDefs as $tabKey => $tabInfo):
      $count = $tabKey === 'all' ? count($allPages) : count(array_filter($allPages, fn($p) => ($p['page_type'] ?? 'page') === $tabKey));
      $isActive = ($curTab === $tabKey);
  ?>
    <a href="pages.php?tab=<?= e($tabKey) ?>" class="fr-btn ghost sm" style="white-space:nowrap;border-radius:10px 10px 0 0;margin-bottom:-2px;border:none;border-bottom:3px solid <?= $isActive ? '#3b82f6' : 'transparent' ?>;color:<?= $isActive ? '#2563eb' : '#64748b' ?>;font-weight:800;background:<?= $isActive ? '#fff' : 'transparent' ?>;padding:10px 16px;text-decoration:none">
      <?= $tabInfo['icon'] ?> <?= e($tabInfo['title']) ?> (<?= fa_num($count) ?>)
      <?php if ($tabKey === 'header' && $activeHeader): ?>
        <span style="font-size:10px;background:#10b981;color:#fff;padding:2px 6px;border-radius:6px;margin-right:4px">فعال</span>
      <?php elseif ($tabKey === 'footer' && $activeFooter): ?>
        <span style="font-size:10px;background:#10b981;color:#fff;padding:2px 6px;border-radius:6px;margin-right:4px">فعال</span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- کارت‌های آمار و وضعیت -->
<div class="fr-grid cols-4" style="margin-bottom:24px">
  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#e0e7ff;color:#4338ca;font-size:22px;display:flex;align-items:center;justify-content:center">
      <?= $tabDefs[$curTab]['icon'] ?? '📄' ?>
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">تعداد در تب فعلی</div>
      <b style="font-size:18px;color:#0f172a"><?= fa_num(count($pages)) ?> مورد</b>
    </div>
  </div>

  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#fef3c7;color:#d97706;font-size:22px;display:flex;align-items:center;justify-content:center">
      ⭐️
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">صفحه اصلی فعال</div>
      <b style="font-size:13px;color:#b45309"><?= $homePage ? e(mb_strimwidth($homePage['title'], 0, 20, '...')) : 'صفحه پیش‌فرض' ?></b>
    </div>
  </div>

  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#dcfce7;color:#15803d;font-size:22px;display:flex;align-items:center;justify-content:center">
      🔝
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">سربرگ فعال سایت</div>
      <b style="font-size:13px;color:#15803d"><?= $activeHeader ? e(mb_strimwidth($activeHeader['title'], 0, 20, '...')) : 'سربرگ کلاسیک' ?></b>
    </div>
  </div>

  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#fae8ff;color:#a21caf;font-size:22px;display:flex;align-items:center;justify-content:center">
      🔻
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">پاورقی فعال سایت</div>
      <b style="font-size:13px;color:#a21caf"><?= $activeFooter ? e(mb_strimwidth($activeFooter['title'], 0, 20, '...')) : 'پاورقی کلاسیک' ?></b>
    </div>
  </div>
</div>

<!-- جدول آیتم‌ها -->
<div class="fr-card" style="padding:0;overflow:hidden">
  <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center">
    <b style="font-size:15px;color:#1e293b">
      📋 فهرست موارد: <?= e($tabDefs[$curTab]['title'] ?? 'برگه‌ها') ?>
    </b>
    <span class="fr-chip info sm">طراحی با صفحه‌ساز دیداری PHPageBuilder / GrapesJS</span>
  </div>

  <?php if (empty($pages)): ?>
    <div style="padding:40px;text-align:center;color:#64748b">
      هنوز موردی در این بخش تعریف نشده است. می‌توانید با کلیک بر روی دکمهٔ «ساخت مورد جدید» یا دکمهٔ «بارگذاری قالب‌های پیش‌فرض» موارد مورد نیاز را ایجاد نمایید.
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="fr-table" style="width:100%;font-size:13px">
        <thead>
          <tr style="background:#f1f5f9;text-align:right">
            <th>عنوان و شناسه</th>
            <th style="text-align:center">نوع بخش</th>
            <th style="text-align:center">تنظیمات اصلی</th>
            <th style="text-align:center">وضعیت انتشار</th>
            <th style="text-align:center">بازدید</th>
            <th style="text-align:center">آخرین ویرایش</th>
            <th style="text-align:left;width:260px">عملیات و طراحی</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pages as $p):
              $isHome = !empty($p['is_home']);
              $isDef  = !empty($p['is_default']);
              $isPub  = !empty($p['active']);
              $pType  = (string)($p['page_type'] ?? 'page');

              if ($isHome) {
                  $pageUrl = SITE_URL . '/';
              } elseif ($pType === 'tests') {
                  $pageUrl = SITE_URL . '/tests.php';
              } elseif ($pType === 'courses') {
                  $pageUrl = SITE_URL . '/courses.php';
              } elseif ($pType === 'workshops') {
                  $pageUrl = SITE_URL . '/workshops.php';
              } elseif ($pType === 'consult') {
                  $pageUrl = SITE_URL . '/consult.php';
              } elseif ($pType === 'page') {
                  $pageUrl = SITE_URL . '/page.php?slug=' . urlencode((string)$p['slug']);
              } else {
                  $pageUrl = '';
              }

              $typeLabels = [
                  'page'      => 'برگه',
                  'tests'     => 'آزمون‌ها',
                  'courses'   => 'دوره‌ها',
                  'workshops' => 'کارگاه‌ها',
                  'consult'   => 'مشاوره',
                  'header'    => 'سربرگ',
                  'footer'    => 'پاورقی',
              ];
          ?>
            <tr style="<?= ($isHome || $isDef) ? 'background:#fffdf5' : '' ?>">
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <b style="font-size:14px;color:#0f172a"><?= e($p['title']) ?></b>
                  <?php if ($isHome): ?>
                    <span class="fr-chip sm warn" style="font-size:11px;font-weight:700">⭐️ صفحه اصلی</span>
                  <?php endif; ?>
                  <?php if ($isDef): ?>
                    <span class="fr-chip sm ok" style="font-size:11px;font-weight:700">⭐️ قالب فعال سراسری</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:12px;color:#64748b;font-family:monospace;margin-top:3px;direction:ltr;text-align:right">
                  <?= e($p['slug']) ?>
                </div>
              </td>

              <td style="text-align:center">
                <span class="fr-chip sm" style="font-size:11px;background:#f1f5f9;color:#334155;font-weight:700">
                  <?= $tabDefs[$pType]['icon'] ?? '📄' ?> <?= e($typeLabels[$pType] ?? $pType) ?>
                </span>
              </td>

              <td style="text-align:center">
                <?php if ($pType === 'page'): ?>
                  <?php if ($isHome): ?>
                    <span style="color:#f59e0b;font-size:13px;font-weight:700" title="صفحه اصلی فعال">⭐️ خانه سایت</span>
                  <?php else: ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="set_home">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="fr-btn sm ghost" style="font-size:11px;padding:4px 8px;border-color:#e2e8f0" title="تنظیم این برگه به عنوان صفحه اصلی سایت">
                        تنظیم به عنوان خانه
                      </button>
                    </form>
                  <?php endif; ?>
                <?php elseif ($pType === 'header' || $pType === 'footer'): ?>
                  <?php if ($isDef): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="unset_default_template">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="type" value="<?= e($pType) ?>">
                      <button type="submit" class="fr-chip sm ok" style="border:none;cursor:pointer;font-size:11px" title="کلیک کنید تا به هدر/فوتر کلاسیک بازگردد">
                        ⭐️ قالب فعال پیش‌فرض
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="set_default_template">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="type" value="<?= e($pType) ?>">
                      <button type="submit" class="fr-btn sm ghost" style="font-size:11px;padding:4px 8px;border-color:#e2e8f0">
                        فعال‌سازی سراسری
                      </button>
                    </form>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="fr-chip sm" style="font-size:11px;background:#f8fafc;color:#64748b">
                    <?= $isPub ? 'قالب فعال بخش' : 'پیش‌نویس (طرح آزمایشی)' ?>
                  </span>
                <?php endif; ?>
              </td>

              <td style="text-align:center">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="fr-chip sm <?= $isPub ? 'ok' : 'err' ?>" style="border:none;cursor:pointer;font-size:11px">
                    <?= $isPub ? '✓ فعال / منتشر' : '✕ پیش‌نویس' ?>
                  </button>
                </form>
              </td>

              <td style="text-align:center">
                <span style="font-weight:700;color:#334155"><?= fa_num($p['view_count']) ?></span>
              </td>

              <td style="text-align:center;font-size:12px;color:#64748b">
                <?= fa_num(substr((string)($p['updated_at'] ?: $p['created_at']), 0, 10)) ?>
              </td>

              <td style="text-align:left">
                <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center">
                  <a href="page-builder.php?id=<?= (int)$p['id'] ?>" class="fr-btn sm primary" target="_blank" style="padding:5px 12px;font-size:12px;text-decoration:none">
                    🎨 صفحه‌ساز
                  </a>

                  <?php if ($pageUrl): ?>
                    <a href="<?= e($pageUrl) ?>" target="_blank" class="fr-btn sm ghost" title="مشاهده پیش‌نمایش زنده" style="padding:5px 8px">
                      👁️
                    </a>
                  <?php endif; ?>

                  <button type="button" class="fr-btn sm ghost" title="ویرایش تنظیمات" style="padding:5px 8px"
                          onclick='openEditModal(<?= json_encode($p, JSON_UNESCAPED_UNICODE) ?>)'>
                    ⚙️
                  </button>

                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="duplicate">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="fr-btn sm ghost" title="کپی و تکثیر" style="padding:5px 8px">
                      📋
                    </button>
                  </form>

                  <?php if (!$isHome && !$isDef): ?>
                    <form method="post" onsubmit="return confirm('آیا از حذف این مورد اطمینان دارید؟');" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="fr-btn sm ghost" style="color:#ef4444;padding:5px 8px" title="حذف">
                        🗑
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- مودال ساخت / ویرایش برگه، هدر، فوتر و بخش‌ها -->
<div id="modalAddPage" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)">
  <div class="fr-card" style="width:100%;max-width:560px;margin:0;max-height:90vh;overflow-y:auto;border-radius:18px;box-shadow:0 20px 40px rgba(0,0,0,0.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:14px;margin-bottom:16px">
      <h3 id="modalTitle" style="margin:0;font-size:17px;color:#0f172a">➕ ایجاد مورد جدید</h3>
      <button type="button" onclick="closeModal()" style="border:none;background:transparent;font-size:20px;cursor:pointer;color:#64748b">&times;</button>
    </div>

    <form method="post" id="pageForm">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="f_id" value="0">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">نوع برگه یا قالب: <span style="color:#ef4444">*</span></label>
        <select name="page_type" id="f_page_type" class="fr-input fr-select" style="width:100%" onchange="onPageTypeChange(this.value)">
          <option value="page">📄 برگه استاندارد / عمومی</option>
          <option value="tests">🧠 کاتالوگ و برگه آزمون‌ها</option>
          <option value="courses">🎓 کاتالوگ و برگه دوره‌های آموزشی</option>
          <option value="workshops">👥 کاتالوگ و برگه کارگاه‌ها</option>
          <option value="consult">💬 کاتالوگ و برگه خدمات مشاوره</option>
          <option value="header">🔝 سربرگ سایت (Header Template)</option>
          <option value="footer">🔻 پاورقی سایت (Footer Template)</option>
        </select>
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">عنوان: <span style="color:#ef4444">*</span></label>
        <input type="text" name="title" id="f_title" class="fr-input" style="width:100%" required placeholder="مثال: سربرگ مدرن یا کاتالوگ دوره‌ها">
      </div>

      <div class="fr-grid cols-2" style="margin-bottom:14px">
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">نام اداری (اختیاری):</label>
          <input type="text" name="name" id="f_name" class="fr-input" style="width:100%" placeholder="نام در پنل">
        </div>
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">اسلاگ پیوند یکتا (Slug):</label>
          <input type="text" name="slug" id="f_slug" class="fr-input" style="width:100%;direction:ltr;text-align:left" placeholder="my-slug">
        </div>
      </div>

      <div class="fr-grid cols-2" style="margin-bottom:14px">
        <div id="sec-layout">
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">قالب چیدمان (Layout):</label>
          <select name="layout" id="f_layout" class="fr-input fr-select" style="width:100%">
            <option value="default">پیش‌فرض (همراه هدر و فوتر)</option>
            <option value="landing">لندینگ تمام‌صفحه</option>
            <option value="blank">خام / Canvas مستقل</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">وضعیت انتشار:</label>
          <select name="active" id="f_active" class="fr-input fr-select" style="width:100%">
            <option value="1">✓ فعال و منتشرشده</option>
            <option value="0">✕ پیش‌نویس</option>
          </select>
        </div>
      </div>

      <div id="sec-is-home" style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:16px">
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
          <input type="checkbox" name="is_home" id="f_is_home" value="1" style="width:16px;height:16px">
          <span><b>⭐️ تنظیم به عنوان صفحه اصلی سایت:</b> محتوای این برگه جایگزین صفحه اصلی (/) شود.</span>
        </label>
      </div>

      <div id="sec-is-default" style="display:none;background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:16px">
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
          <input type="checkbox" name="is_default" id="f_is_default" value="1" style="width:16px;height:16px">
          <span><b>⭐️ تنظیم به عنوان قالب پیش‌فرض سایت:</b> به صورت سراسری در تمام صفحات جایگزین شود.</span>
        </label>
      </div>

      <!-- تنظیمات سئو -->
      <div id="sec-seo">
        <details style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin-bottom:18px">
          <summary style="font-size:13px;font-weight:700;color:#334155;cursor:pointer">⚙️ تنظیمات سئو و بهینه‌سازی موتورهای جستجو</summary>
          <div style="margin-top:12px">
            <div style="margin-bottom:10px">
              <label style="display:block;font-size:12px;margin-bottom:4px">عنوان سئو (Title Tag):</label>
              <input type="text" name="seo_title" id="f_seo_title" class="fr-input" style="width:100%">
            </div>
            <div style="margin-bottom:10px">
              <label style="display:block;font-size:12px;margin-bottom:4px">توضیحات متا (Meta Description):</label>
              <textarea name="meta_desc" id="f_meta_desc" class="fr-input" rows="2" style="width:100%"></textarea>
            </div>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
              <input type="checkbox" name="noindex" id="f_noindex" value="1">
              <span>عدم ایندکس توسط موتورهای جستجو (noindex)</span>
            </label>
          </div>
        </details>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px">
        <button type="button" class="fr-btn ghost sm" onclick="closeModal()">انصراف</button>
        <button type="submit" class="fr-btn primary sm" style="padding:8px 20px">ذخیره</button>
      </div>
    </form>
  </div>
</div>

<script>
function onPageTypeChange(type) {
    const isHeaderFooter = (type === 'header' || type === 'footer');
    const isPage = (type === 'page');

    const secIsHome = document.getElementById('sec-is-home');
    const secIsDefault = document.getElementById('sec-is-default');
    const secLayout = document.getElementById('sec-layout');
    const secSeo = document.getElementById('sec-seo');

    if (secIsHome) secIsHome.style.display = isPage ? 'block' : 'none';
    if (secIsDefault) secIsDefault.style.display = isHeaderFooter ? 'block' : 'none';
    if (secLayout) secLayout.style.display = isHeaderFooter ? 'none' : 'block';
    if (secSeo) secSeo.style.display = isHeaderFooter ? 'none' : 'block';
}

function closeModal() {
    document.getElementById('modalAddPage').style.display = 'none';
    document.getElementById('pageForm').reset();
    document.getElementById('f_id').value = '0';
}

function openNewModal(type) {
    document.getElementById('f_id').value = '0';
    if (!type || type === 'all') type = 'page';
    document.getElementById('f_page_type').value = type;
    onPageTypeChange(type);

    const labels = {
        'page': 'برگه جدید',
        'tests': 'برگه جدید آزمون‌ها',
        'courses': 'برگه جدید دوره‌ها',
        'workshops': 'برگه جدید کارگاه‌ها',
        'consult': 'برگه جدید مشاوره',
        'header': 'سربرگ جدید (Header)',
        'footer': 'پاورقی جدید (Footer)'
    };
    document.getElementById('modalTitle').innerText = '➕ ایجاد ' + (labels[type] || 'مورد جدید');
    document.getElementById('modalAddPage').style.display = 'flex';
}

function openEditModal(page) {
    document.getElementById('modalTitle').innerText = '✏️ ویرایش: «' + page.title + '»';
    document.getElementById('f_id').value = page.id || 0;
    const pType = page.page_type || 'page';
    document.getElementById('f_page_type').value = pType;
    onPageTypeChange(pType);

    document.getElementById('f_title').value = page.title || '';
    document.getElementById('f_name').value = page.name || '';
    document.getElementById('f_slug').value = page.slug || '';
    if (document.getElementById('f_layout')) document.getElementById('f_layout').value = page.layout || 'default';
    if (document.getElementById('f_active')) document.getElementById('f_active').value = page.active ? '1' : '0';
    if (document.getElementById('f_is_home')) document.getElementById('f_is_home').checked = page.is_home == 1;
    if (document.getElementById('f_is_default')) document.getElementById('f_is_default').checked = page.is_default == 1;
    if (document.getElementById('f_seo_title')) document.getElementById('f_seo_title').value = page.seo_title || '';
    if (document.getElementById('f_meta_desc')) document.getElementById('f_meta_desc').value = page.meta_desc || '';
    if (document.getElementById('f_noindex')) document.getElementById('f_noindex').checked = page.noindex == 1;

    document.getElementById('modalAddPage').style.display = 'flex';
}
</script>

<?php admin_layout_end(); ?>
