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
if (!in_array($curTab, ['page', 'header', 'footer'], true)) {
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

        // محتوای اولیه در صورت ساخت هدر یا فوتر جدید
        if ($id <= 0) {
            if ($pageType === 'header') {
                $data['content_html'] = '<header class="fr-custom-header" style="background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px"><div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px"><div data-widget="site_logo"></div><div data-widget="menu" data-location="primary"></div><div style="display:flex;align-items:center;gap:10px"><div data-widget="user_auth"></div><div data-widget="header_cta"></div></div></div></header>';
            } elseif ($pageType === 'footer') {
                $data['content_html'] = '<footer class="fr-custom-footer" style="background:#0f172a;color:#f8fafc;padding:50px 24px 20px;border-top:3px solid #3b82f6"><div style="max-width:1200px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:32px;margin-bottom:30px"><div data-widget="footer_brand"></div><div><h3 style="font-size:15px;font-weight:700;color:#38bdf8;margin:0 0 16px">فهرست و دسترسی سریع</h3><div data-widget="footer_menu" data-location="footer"></div></div><div data-widget="footer_contact"></div><div data-widget="footer_badges"></div></div><div style="border-top:1px solid #1e293b;padding-top:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;font-size:12.5px;color:#94a3b8"><div data-widget="copyright"></div><div data-widget="social_icons"></div></div></footer>';
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
$pages    = array_values(array_filter($allPages, fn($p) => ($p['page_type'] ?? 'page') === $curTab));
$homePage = PageBuilder::getHomepage();
$activeHeader = PageBuilder::getActiveHeader();
$activeFooter = PageBuilder::getActiveFooter();

admin_layout($adm, 'pages', 'مدیریت برگه‌ها، سربرگ و پاورقی');
echo flash();
?>

<!-- هدر و نوار ابزار اصلی -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0;font-size:22px;color:#0f172a">📄 مدیریت برگه‌ها، سربرگ و پاورقی (Header & Footer Builder)</h2>
    <p style="margin:4px 0 0;font-size:13px;color:#64748b">
      طراحی کامل سربرگ، پاورقی، صفحه اصلی و تمام برگه‌های سایت با صفحه‌ساز دیداری و ویجت‌های اختصاصی فریا روان.
    </p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="fr-btn sm ghost" href="menus.php" style="text-decoration:none;background:#f1f5f9;border-color:#cbd5e1;font-weight:700">
      📋 فهرست‌ساز و منوها (Menu Builder)
    </a>
    <?php if ($curTab === 'page'): ?>
      <button type="button" class="fr-btn sm primary" onclick="openNewModal('page')">
        + ساخت برگه جدید
      </button>
    <?php elseif ($curTab === 'header'): ?>
      <button type="button" class="fr-btn sm primary" onclick="openNewModal('header')">
        + ساخت سربرگ جدید (Header)
      </button>
    <?php elseif ($curTab === 'footer'): ?>
      <button type="button" class="fr-btn sm primary" onclick="openNewModal('footer')">
        + ساخت پاورقی جدید (Footer)
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- تب‌های ناوبری بخش‌ها -->
<div style="display:flex;gap:8px;border-bottom:2px solid #e2e8f0;margin-bottom:24px">
  <a href="pages.php?tab=page" class="fr-btn ghost sm" style="border-radius:10px 10px 0 0;margin-bottom:-2px;border:none;border-bottom:3px solid <?= ($curTab === 'page') ? '#3b82f6' : 'transparent' ?>;color:<?= ($curTab === 'page') ? '#2563eb' : '#64748b' ?>;font-weight:800;background:<?= ($curTab === 'page') ? '#fff' : 'transparent' ?>;padding:10px 18px;text-decoration:none">
    📄 برگه‌های سایت (<?= fa_num(count(array_filter($allPages, fn($p) => ($p['page_type'] ?? 'page') === 'page'))) ?>)
  </a>
  <a href="pages.php?tab=header" class="fr-btn ghost sm" style="border-radius:10px 10px 0 0;margin-bottom:-2px;border:none;border-bottom:3px solid <?= ($curTab === 'header') ? '#3b82f6' : 'transparent' ?>;color:<?= ($curTab === 'header') ? '#2563eb' : '#64748b' ?>;font-weight:800;background:<?= ($curTab === 'header') ? '#fff' : 'transparent' ?>;padding:10px 18px;text-decoration:none">
    🔝 سربرگ‌ها / Headers (<?= fa_num(count(array_filter($allPages, fn($p) => ($p['page_type'] ?? 'page') === 'header'))) ?>)
    <?php if ($activeHeader): ?>
      <span style="font-size:10px;background:#10b981;color:#fff;padding:2px 6px;border-radius:6px;margin-right:4px">فعال</span>
    <?php endif; ?>
  </a>
  <a href="pages.php?tab=footer" class="fr-btn ghost sm" style="border-radius:10px 10px 0 0;margin-bottom:-2px;border:none;border-bottom:3px solid <?= ($curTab === 'footer') ? '#3b82f6' : 'transparent' ?>;color:<?= ($curTab === 'footer') ? '#2563eb' : '#64748b' ?>;font-weight:800;background:<?= ($curTab === 'footer') ? '#fff' : 'transparent' ?>;padding:10px 18px;text-decoration:none">
    🔻 پاورقی‌ها / Footers (<?= fa_num(count(array_filter($allPages, fn($p) => ($p['page_type'] ?? 'page') === 'footer'))) ?>)
    <?php if ($activeFooter): ?>
      <span style="font-size:10px;background:#10b981;color:#fff;padding:2px 6px;border-radius:6px;margin-right:4px">فعال</span>
    <?php endif; ?>
  </a>
</div>

<!-- کارت‌های آمار و وضعیت -->
<div class="fr-grid cols-4" style="margin-bottom:24px">
  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#e0e7ff;color:#4338ca;font-size:22px;display:flex;align-items:center;justify-content:center">
      📄
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">تعداد در این بخش</div>
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
      <?= $curTab === 'page' ? '📋 فهرست برگه‌های سایت' : ($curTab === 'header' ? '🔝 فهرست قالب‌های سربرگ (Header Templates)' : '🔻 فهرست قالب‌های پاورقی (Footer Templates)') ?>
    </b>
    <span class="fr-chip info sm">طراحی با صفحه‌ساز دیداری PHPageBuilder / GrapesJS</span>
  </div>

  <?php if (empty($pages)): ?>
    <div style="padding:40px;text-align:center;color:#64748b">
      هنوز موردی در این بخش تعریف نشده است. می‌توانید با کلیک بر روی دکمهٔ «ساخت جدید» اولین مورد خود را ایجاد نمایید.
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="fr-table" style="width:100%;font-size:13px">
        <thead>
          <tr style="background:#f1f5f9;text-align:right">
            <th>عنوان و شناسه</th>
            <?php if ($curTab === 'page'): ?>
              <th style="text-align:center">صفحه اصلی</th>
              <th style="text-align:center">قالب</th>
            <?php else: ?>
              <th style="text-align:center">وضعیت پیش‌فرض سراسری</th>
            <?php endif; ?>
            <th style="text-align:center">وضعیت</th>
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
              $pageUrl = $isHome ? SITE_URL . '/' : (SITE_URL . '/page.php?slug=' . urlencode((string)$p['slug']));
          ?>
            <tr style="<?= ($isHome || $isDef) ? 'background:#fffdf5' : '' ?>">
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <b style="font-size:14px;color:#0f172a"><?= e($p['title']) ?></b>
                  <?php if ($isHome): ?>
                    <span class="fr-chip sm warn" style="font-size:11px;font-weight:700">⭐️ صفحه اصلی سایت</span>
                  <?php endif; ?>
                  <?php if ($isDef): ?>
                    <span class="fr-chip sm ok" style="font-size:11px;font-weight:700">⭐️ فعال در تمام صفحات</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:12px;color:#64748b;font-family:monospace;margin-top:3px;direction:ltr;text-align:right">
                  <?= e($p['slug']) ?>
                </div>
              </td>

              <?php if ($curTab === 'page'): ?>
                <td style="text-align:center">
                  <?php if ($isHome): ?>
                    <span style="color:#f59e0b;font-size:18px" title="صفحه اصلی فعال">⭐️</span>
                  <?php else: ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="set_home">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="fr-btn sm ghost" style="font-size:11px;padding:4px 8px;border-color:#e2e8f0" title="تنظیم این برگه به عنوان صفحه اصلی سایت">
                        انتخاب به عنوان خانه
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <span class="fr-chip sm" style="font-size:11px;background:#f1f5f9;color:#475569">
                    <?= $p['layout'] === 'blank' ? 'خام / Canvas' : ($p['layout'] === 'landing' ? 'لندینگ پیج' : 'پیش‌فرض (با هدر/فوتر)') ?>
                  </span>
                </td>
              <?php else: ?>
                <td style="text-align:center">
                  <?php if ($isDef): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="unset_default_template">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="type" value="<?= e($curTab) ?>">
                      <button type="submit" class="fr-chip sm ok" style="border:none;cursor:pointer;font-size:11px" title="کلیک کنید تا به هدر/فوتر کلاسیک بازگردد">
                        ⭐️ قالب فعال پیش‌فرض
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="set_default_template">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="type" value="<?= e($curTab) ?>">
                      <button type="submit" class="fr-btn sm ghost" style="font-size:11px;padding:4px 8px;border-color:#e2e8f0">
                        تنظیم به عنوان قالب فعال سایت
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endif; ?>

              <td style="text-align:center">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="fr-chip sm <?= $isPub ? 'ok' : 'err' ?>" style="border:none;cursor:pointer;font-size:11px">
                    <?= $isPub ? '✓ منتشرشده' : '✕ پیش‌نویس' ?>
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

                  <?php if ($curTab === 'page'): ?>
                    <a href="<?= e($pageUrl) ?>" target="_blank" class="fr-btn sm ghost" title="مشاهده برگه" style="padding:5px 8px">
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

<!-- مودال ساخت / ویرایش برگه، هدر یا فوتر -->
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
      <input type="hidden" name="page_type" id="f_page_type" value="<?= e($curTab) ?>">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">عنوان: <span style="color:#ef4444">*</span></label>
        <input type="text" name="title" id="f_title" class="fr-input" style="width:100%" required placeholder="مثال: سربرگ مدرن یا درباره ما">
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

      <?php if ($curTab === 'page'): ?>
        <div class="fr-grid cols-2" style="margin-bottom:14px">
          <div>
            <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">قالب برگه (Layout):</label>
            <select name="layout" id="f_layout" class="fr-input fr-select" style="width:100%">
              <option value="default">پیش‌فرض (با هدر و فوتر سایت)</option>
              <option value="landing">تمام‌صفحه (لندینگ پیج)</option>
              <option value="blank">خام (Canvas مستقل بدون هدر/فوتر)</option>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">وضعیت انتشار:</label>
            <select name="active" id="f_active" class="fr-input fr-select" style="width:100%">
              <option value="1">✓ منتشرشده</option>
              <option value="0">✕ پیش‌نویس</option>
            </select>
          </div>
        </div>

        <div style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:16px">
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
            <input type="checkbox" name="is_home" id="f_is_home" value="1" style="width:16px;height:16px">
            <span><b>⭐️ تنظیم به عنوان صفحه اصلی سایت:</b> محتوای این برگه جایگزین صفحه اصلی (/) شود.</span>
          </label>
        </div>
      <?php else: ?>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">وضعیت انتشار:</label>
          <select name="active" id="f_active" class="fr-input fr-select" style="width:100%">
            <option value="1">✓ فعال و منتشرشده</option>
            <option value="0">✕ پیش‌نویس</option>
          </select>
        </div>

        <div style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:16px">
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
            <input type="checkbox" name="is_default" id="f_is_default" value="1" style="width:16px;height:16px">
            <span><b>⭐️ تنظیم به عنوان قالب پیش‌فرض سایت:</b> به صورت سراسری در تمام صفحات جایگزین شود.</span>
          </label>
        </div>
      <?php endif; ?>

      <!-- تنظیمات سئو -->
      <?php if ($curTab === 'page'): ?>
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
      <?php endif; ?>

      <div style="display:flex;justify-content:flex-end;gap:10px">
        <button type="button" class="fr-btn ghost sm" onclick="closeModal()">انصراف</button>
        <button type="submit" class="fr-btn primary sm" style="padding:8px 20px">ذخیره</button>
      </div>
    </form>
  </div>
</div>

<script>
function closeModal() {
    document.getElementById('modalAddPage').style.display = 'none';
    document.getElementById('pageForm').reset();
    document.getElementById('f_id').value = '0';
}

function openNewModal(type) {
    document.getElementById('f_id').value = '0';
    document.getElementById('f_page_type').value = type;
    const label = type === 'header' ? 'سربرگ جدید (Header)' : (type === 'footer' ? 'پاورقی جدید (Footer)' : 'برگه جدید');
    document.getElementById('modalTitle').innerText = '➕ ایجاد ' + label;
    document.getElementById('modalAddPage').style.display = 'flex';
}

function openEditModal(page) {
    document.getElementById('modalTitle').innerText = '✏️ ویرایش: «' + page.title + '»';
    document.getElementById('f_id').value = page.id || 0;
    document.getElementById('f_page_type').value = page.page_type || 'page';
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
