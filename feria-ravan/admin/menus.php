<?php
/**
 * ============================================================================
 *  مدیریت فهرست‌ها و منوهای ناوبری فریا روان (Menu Builder)
 *  ─ نگارش ۳٫۷٫۰: الهام‌گرفته از فهرست‌ساز استاندارد وردپرس (WordPress Menus)
 *  ─ ساخت منوهای چندسطحی، زیرمنوهای آبشاری، آیکون‌ها و نشان‌های برجسته
 *  ─ افزودن آسان برگه‌ها، تست‌های روانشناسی، دوره‌ها، کارگاه‌ها و مشاوره
 *  ─ انتصاب جایگاه‌های نمایش (سربرگ، پاورقی، موبایل)
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/includes/seo.php';
require_once FR_ROOT . '/includes/consult.php';
require_once FR_ROOT . '/core/MenuBuilder.php';
require_once FR_ROOT . '/core/PageBuilder.php';

$adm = require_admin(['superadmin', 'manager', 'admin']);
MenuBuilder::ensureSchema();
PageBuilder::ensureSchema();

$menus = MenuBuilder::getMenus();
$activeMenuId = (int)($_GET['menu'] ?? 0);
if ($activeMenuId <= 0 && !empty($menus)) {
    $activeMenuId = (int)$menus[0]['id'];
}
$isNewMenu = isset($_GET['new']);
$activeMenu = (!$isNewMenu && $activeMenuId > 0) ? MenuBuilder::getMenu($activeMenuId) : null;

// پردازش عملیات‌های POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');

    // ۱. ساخت یا ویرایش مشخصات منو
    if ($act === 'save_menu') {
        $id       = (int)post('menu_id');
        $title    = trim((string)post('title'));
        $slug     = trim((string)post('slug'));
        $location = trim((string)post('location')) ?: null;

        if ($title === '') {
            flash('عنوان فهرست الزامی است.', 'err');
            redirect(SITE_URL . '/admin/menus.php' . ($id > 0 ? '?menu=' . $id : ''));
        }

        $savedId = MenuBuilder::saveMenu([
            'title'    => $title,
            'slug'     => $slug,
            'location' => $location,
        ], $id > 0 ? $id : null);

        flash('فهرست با موفقیت ذخیره شد ✅', 'ok');
        redirect(SITE_URL . '/admin/menus.php?menu=' . $savedId);
    }

    // ۲. حذف منو
    if ($act === 'delete_menu') {
        $id = (int)post('menu_id');
        if ($id > 0) {
            MenuBuilder::deleteMenu($id);
            flash('فهرست و تمامی گزینه‌های آن حذف گردید.', 'ok');
        }
        redirect(SITE_URL . '/admin/menus.php');
    }

    // ۳. افزودن پیوند سفارشی
    if ($act === 'add_custom_link') {
        $menuId = (int)post('menu_id');
        $title  = trim((string)post('link_title'));
        $url    = trim((string)post('link_url'));

        if ($menuId > 0 && $title !== '' && $url !== '') {
            MenuBuilder::saveMenuItem([
                'menu_id' => $menuId,
                'title'   => $title,
                'url'     => $url,
                'type'    => 'custom',
            ]);
            flash('پیوند به فهرست اضافه شد.', 'ok');
        }
        redirect(SITE_URL . '/admin/menus.php?menu=' . $menuId);
    }

    // ۴. افزودن گروهی آیتم‌ها (برگه‌ها، تست‌ها، رویدادها، خدمات)
    if ($act === 'add_batch_items') {
        $menuId = (int)post('menu_id');
        $type   = (string)post('item_type');
        $items  = (array)post('selected_items', []);

        if ($menuId > 0 && !empty($items)) {
            $added = 0;
            foreach ($items as $itemId => $val) {
                $itemId = (int)$itemId;
                $title = '';
                $url   = '';

                if ($type === 'page') {
                    $p = PageBuilder::getPage($itemId);
                    if ($p) {
                        $title = $p['title'];
                        $url = !empty($p['is_home']) ? '/' : ('/page.php?slug=' . urlencode((string)$p['slug']));
                    }
                } elseif ($type === 'test') {
                    $t = DB::one("SELECT id, title, slug FROM " . DB::t('tests') . " WHERE id=?", [$itemId]);
                    if ($t) {
                        $title = $t['title'];
                        $url = fr_test_url($t);
                    }
                } elseif ($type === 'event') {
                    $ev = DB::one("SELECT id, title, kind, slug FROM " . DB::t('events') . " WHERE id=?", [$itemId]);
                    if ($ev) {
                        $title = $ev['title'];
                        $url = fr_event_url($ev);
                    }
                } elseif ($type === 'service') {
                    $srv = DB::one("SELECT id, title FROM " . DB::t('consult_services') . " WHERE id=?", [$itemId]);
                    if ($srv) {
                        $title = 'مشاوره: ' . $srv['title'];
                        $url = '/consult.php?service=' . $srv['id'];
                    }
                }

                if ($title !== '' && $url !== '') {
                    MenuBuilder::saveMenuItem([
                        'menu_id' => $menuId,
                        'title'   => $title,
                        'url'     => $url,
                        'type'    => $type,
                        'type_id' => $itemId,
                    ]);
                    $added++;
                }
            }
            flash("تعداد {$added} مورد به فهرست افزوده شد.", 'ok');
        }
        redirect(SITE_URL . '/admin/menus.php?menu=' . $menuId);
    }

    // ۵. ویرایش یک آیتم از منو
    if ($act === 'save_item') {
        $itemId = (int)post('item_id');
        $menuId = (int)post('menu_id');
        if ($itemId > 0 && $menuId > 0) {
            MenuBuilder::saveMenuItem([
                'menu_id'     => $menuId,
                'parent_id'   => (int)post('parent_id', 0),
                'title'       => (string)post('title'),
                'url'         => (string)post('url'),
                'target'      => (string)post('target', '_self'),
                'icon'        => (string)post('icon'),
                'badge'       => (string)post('badge'),
                'badge_color' => (string)post('badge_color', 'primary'),
                'css_class'   => (string)post('css_class'),
                'sort_order'  => (int)post('sort_order', 0),
            ], $itemId);
            flash('مشخصات گزینه به‌روزرسانی شد.', 'ok');
        }
        redirect(SITE_URL . '/admin/menus.php?menu=' . $menuId);
    }

    // ۶. تغییر سطح آیتم (حرکت به بالا، پایین، زیرمنو شدن یا ارتقا به والد)
    if ($act === 'move_item') {
        $itemId    = (int)post('item_id');
        $menuId    = (int)post('menu_id');
        $direction = (string)post('direction');

        $allItems = MenuBuilder::getMenuItems($menuId, false);
        $idx = -1;
        foreach ($allItems as $i => $it) {
            if ((int)$it['id'] === $itemId) {
                $idx = $i;
                break;
            }
        }

        if ($idx >= 0) {
            $curr = $allItems[$idx];

            if ($direction === 'up' && $idx > 0) {
                $prev = $allItems[$idx - 1];
                DB::run("UPDATE " . MenuBuilder::tableMenuItems() . " SET sort_order=? WHERE id=?", [(int)$prev['sort_order'], (int)$curr['id']]);
                DB::run("UPDATE " . MenuBuilder::tableMenuItems() . " SET sort_order=? WHERE id=?", [(int)$curr['sort_order'] + 1, (int)$prev['id']]);
            } elseif ($direction === 'down' && $idx < count($allItems) - 1) {
                $next = $allItems[$idx + 1];
                DB::run("UPDATE " . MenuBuilder::tableMenuItems() . " SET sort_order=? WHERE id=?", [(int)$next['sort_order'], (int)$curr['id']]);
                DB::run("UPDATE " . MenuBuilder::tableMenuItems() . " SET sort_order=? WHERE id=?", [(int)$curr['sort_order'] - 1, (int)$next['id']]);
            } elseif ($direction === 'indent' && $idx > 0) {
                // تبدیل شدن به فرزند آیتم بالایی
                $prev = $allItems[$idx - 1];
                DB::run("UPDATE " . MenuBuilder::tableMenuItems() . " SET parent_id=? WHERE id=?", [(int)$prev['id'], (int)$curr['id']]);
            } elseif ($direction === 'outdent' && (int)$curr['parent_id'] > 0) {
                // ارتقا به سطح والد
                $parent = DB::one("SELECT parent_id FROM " . MenuBuilder::tableMenuItems() . " WHERE id=?", [(int)$curr['parent_id']]);
                $newParent = $parent ? (int)$parent['parent_id'] : 0;
                DB::run("UPDATE " . MenuBuilder::tableMenuItems() . " SET parent_id=? WHERE id=?", [$newParent, (int)$curr['id']]);
            }
        }
        redirect(SITE_URL . '/admin/menus.php?menu=' . $menuId);
    }

    // ۷. حذف آیتم
    if ($act === 'delete_item') {
        $itemId = (int)post('item_id');
        $menuId = (int)post('menu_id');
        MenuBuilder::deleteMenuItem($itemId);
        flash('گزینه از فهرست حذف شد.', 'ok');
        redirect(SITE_URL . '/admin/menus.php?menu=' . $menuId);
    }
}

$locations = MenuBuilder::getLocations();
$menuItems = $activeMenu ? MenuBuilder::getMenuItems((int)$activeMenu['id'], false) : [];

// اطلاعات کمکی جهت افزودن آیتم‌ها به فهرست
$availPages = PageBuilder::getPages();
$availTests = DB::all("SELECT id, title FROM " . DB::t('tests') . " WHERE status='active' ORDER BY sort_order ASC");
$availEvents = DB::all("SELECT id, title, kind FROM " . DB::t('events') . " WHERE status='active' ORDER BY id DESC LIMIT 20");
$availServices = function_exists('consult_services_catalog') ? consult_services_catalog() : [];

admin_layout($adm, 'menus', 'مدیریت فهرست‌ها و منوها');
echo flash();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0;font-size:22px;color:#0f172a">📋 فهرست‌ساز و مدیریت منوهای ناوبری (Menu Builder)</h2>
    <p style="margin:4px 0 0;font-size:13px;color:#64748b">
      طراحی منوهای چندسطحی، زیرمنوهای آبشاری، آیکون‌ها و جایگاه‌های نمایش سایت با الهام از فهرست‌ساز وردپرس.
    </p>
  </div>
  <div style="display:flex;gap:10px">
    <a class="fr-btn sm ghost" href="pages.php" style="text-decoration:none">
      ← مدیریت برگه‌ها و هدر/فوتر
    </a>
  </div>
</div>

<!-- نوار انتخاب فهرست فعال -->
<div class="fr-card" style="margin-bottom:20px;padding:14px 20px;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <form method="get" style="display:flex;align-items:center;gap:10px;margin:0">
    <label style="font-size:13px;font-weight:700;color:#334155">انتخاب فهرست برای ویرایش:</label>
    <select name="menu" class="fr-input fr-select sm" style="min-width:200px" onchange="this.form.submit()">
      <?php foreach ($menus as $m): ?>
        <option value="<?= (int)$m['id'] ?>" <?= ($activeMenu && (int)$activeMenu['id'] === (int)$m['id']) ? 'selected' : '' ?>>
          <?= e($m['title']) ?> <?= !empty($m['location']) ? ' (' . ($locations[$m['location']] ?? $m['location']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="fr-btn sm ghost">گزینش</button>
  </form>
  <div>
    <a href="menus.php?new=1" class="fr-btn sm primary" style="text-decoration:none">
      + ساخت یک فهرست تازه
    </a>
  </div>
</div>

<?php if ($isNewMenu || !$activeMenu): ?>
  <!-- فرم ساخت فهرست جدید -->
  <div class="fr-card" style="max-width:600px;margin:0 auto">
    <h3 style="margin:0 0 16px;font-size:16px;color:#0f172a">➕ ایجاد فهرست ناوبری تازه</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save_menu">
      <input type="hidden" name="menu_id" value="0">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">نام فهرست: <span style="color:#ef4444">*</span></label>
        <input type="text" name="title" class="fr-input" style="width:100%" placeholder="مثال: منوی بالای سایت" required>
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">اسلاگ پیوند (Slug):</label>
        <input type="text" name="slug" class="fr-input" style="width:100%;direction:ltr;text-align:left" placeholder="top-menu">
      </div>

      <div style="margin-bottom:18px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">جایگاه نمایش در قالب:</label>
        <select name="location" class="fr-input fr-select" style="width:100%">
          <option value="">-- بدون انتصاب به جایگاه پیش‌فرض --</option>
          <?php foreach ($locations as $locKey => $locLabel): ?>
            <option value="<?= e($locKey) ?>"><?= e($locLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px">
        <a href="menus.php" class="fr-btn ghost sm">انصراف</a>
        <button type="submit" class="fr-btn primary sm">ایجاد فهرست</button>
      </div>
    </form>
  </div>
<?php else: ?>

  <!-- ساختار دو ستونه الهام‌گرفته از وردپرس -->
  <div style="display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start">

    <!-- ستون راست (ابزارهای افزودن گزینه به فهرست) -->
    <div style="display:flex;flex-direction:column;gap:12px">

      <!-- ۱. برگه‌ها -->
      <details class="fr-card" style="margin:0;padding:14px" open>
        <summary style="font-size:13.5px;font-weight:700;color:#0f172a;cursor:pointer">📄 برگه‌های سایت</summary>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="add_batch_items">
          <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
          <input type="hidden" name="item_type" value="page">

          <div style="max-height:160px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;margin-bottom:10px;background:#f8fafc">
            <?php foreach ($availPages as $p): ?>
              <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;margin-bottom:6px;cursor:pointer">
                <input type="checkbox" name="selected_items[<?= (int)$p['id'] ?>]" value="1">
                <span><?= e($p['title']) ?> <?= !empty($p['is_home']) ? '⭐️' : '' ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="fr-btn sm ghost" style="width:100%">+ افزودن به فهرست</button>
        </form>
      </details>

      <!-- ۲. تست‌های روانشناسی -->
      <details class="fr-card" style="margin:0;padding:14px">
        <summary style="font-size:13.5px;font-weight:700;color:#0f172a;cursor:pointer">🧠 تست‌های روان‌شناسی</summary>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="add_batch_items">
          <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
          <input type="hidden" name="item_type" value="test">

          <div style="max-height:160px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;margin-bottom:10px;background:#f8fafc">
            <?php foreach ($availTests as $t): ?>
              <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;margin-bottom:6px;cursor:pointer">
                <input type="checkbox" name="selected_items[<?= (int)$t['id'] ?>]" value="1">
                <span><?= e($t['title']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="fr-btn sm ghost" style="width:100%">+ افزودن به فهرست</button>
        </form>
      </details>

      <!-- ۳. دوره‌ها و کارگاه‌ها -->
      <details class="fr-card" style="margin:0;padding:14px">
        <summary style="font-size:13.5px;font-weight:700;color:#0f172a;cursor:pointer">🎓 دوره‌ها و کارگاه‌ها</summary>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="add_batch_items">
          <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
          <input type="hidden" name="item_type" value="event">

          <div style="max-height:160px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;margin-bottom:10px;background:#f8fafc">
            <?php foreach ($availEvents as $ev): ?>
              <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;margin-bottom:6px;cursor:pointer">
                <input type="checkbox" name="selected_items[<?= (int)$ev['id'] ?>]" value="1">
                <span><?= $ev['kind'] === 'course' ? '📚' : '🛠' ?> <?= e($ev['title']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="fr-btn sm ghost" style="width:100%">+ افزودن به فهرست</button>
        </form>
      </details>

      <!-- ۴. خدمات مشاوره -->
      <details class="fr-card" style="margin:0;padding:14px">
        <summary style="font-size:13.5px;font-weight:700;color:#0f172a;cursor:pointer">🛋 خدمات مشاوره</summary>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="add_batch_items">
          <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
          <input type="hidden" name="item_type" value="service">

          <div style="max-height:160px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;margin-bottom:10px;background:#f8fafc">
            <?php foreach ($availServices as $srv): ?>
              <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;margin-bottom:6px;cursor:pointer">
                <input type="checkbox" name="selected_items[<?= (int)$srv['id'] ?>]" value="1">
                <span><?= e($srv['title']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="fr-btn sm ghost" style="width:100%">+ افزودن به فهرست</button>
        </form>
      </details>

      <!-- ۵. پیوند سفارشی -->
      <details class="fr-card" style="margin:0;padding:14px" open>
        <summary style="font-size:13.5px;font-weight:700;color:#0f172a;cursor:pointer">🔗 پیوند دلخواه (Custom Link)</summary>
        <form method="post" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="add_custom_link">
          <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">

          <div style="margin-bottom:10px">
            <label style="display:block;font-size:12px;margin-bottom:4px">نشانی اینترنتی (URL):</label>
            <input type="text" name="link_url" class="fr-input sm" style="width:100%;direction:ltr;text-align:left" value="https://" required>
          </div>
          <div style="margin-bottom:12px">
            <label style="display:block;font-size:12px;margin-bottom:4px">متن پیوند:</label>
            <input type="text" name="link_title" class="fr-input sm" style="width:100%" placeholder="عنوان پیوند" required>
          </div>
          <button type="submit" class="fr-btn sm ghost" style="width:100%">+ افزودن به فهرست</button>
        </form>
      </details>

    </div>

    <!-- ستون چپ (ساختار و چینش گزینه‌های منو) -->
    <div class="fr-card" style="margin:0;padding:20px">
      <!-- مشخصات کلی منو -->
      <form method="post" style="border-bottom:1px solid #e2e8f0;padding-bottom:16px;margin-bottom:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save_menu">
        <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">

        <div class="fr-grid cols-2" style="margin-bottom:14px">
          <div>
            <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">نام فهرست:</label>
            <input type="text" name="title" class="fr-input sm" style="width:100%" value="<?= e($activeMenu['title']) ?>" required>
          </div>
          <div>
            <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">جایگاه نمایش در سایت:</label>
            <select name="location" class="fr-input fr-select sm" style="width:100%">
              <option value="">-- بدون جایگاه اختصاصی --</option>
              <?php foreach ($locations as $locKey => $locLabel): ?>
                <option value="<?= e($locKey) ?>" <?= ($activeMenu['location'] === $locKey) ? 'selected' : '' ?>><?= e($locLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end">
          <button type="submit" class="fr-btn sm primary">ذخیره مشخصات فهرست</button>
        </div>
      </form>

      <!-- ساختار آیتم‌ها -->
      <div>
        <h3 style="margin:0 0 10px;font-size:15px;color:#0f172a">ساختار فهرست</h3>
        <p style="font-size:12.5px;color:#64748b;margin:0 0 16px">
          گزینه‌ها را با دکمه‌های کنترلی مرتب کنید. برای ساخت زیرمنوی آبشاری، روی دکمهٔ «زیرمنو شدن ➡» کلیک کنید.
        </p>

        <?php if (empty($menuItems)): ?>
          <div style="padding:40px;text-align:center;background:#f8fafc;border:2px dashed #e2e8f0;border-radius:12px;color:#64748b">
            این فهرست در حال حاضر خالی است. از ستون سمت راست گزینه‌های مدنظر را اضافه کنید.
          </div>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:8px">
            <?php foreach ($menuItems as $idx => $it):
                $isSub = ((int)$it['parent_id'] > 0);
            ?>
              <div class="fr-menu-item-row" style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;margin-right:<?= $isSub ? '34px' : '0' ?>;border-right:<?= $isSub ? '4px solid #3b82f6' : '1px solid #cbd5e1' ?>;overflow:hidden">
                <div style="padding:10px 14px;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:10px">
                  <div style="display:flex;align-items:center;gap:8px">
                    <?php if (!empty($it['icon'])): ?>
                      <span><?= e($it['icon']) ?></span>
                    <?php endif; ?>
                    <b style="font-size:13.5px;color:#1e293b"><?= e($it['title']) ?></b>
                    <?php if ($isSub): ?>
                      <span class="fr-chip sm info" style="font-size:10px">زیرمنو</span>
                    <?php endif; ?>
                    <?php if (!empty($it['badge'])): ?>
                      <span class="fr-chip sm <?= e($it['badge_color'] ?: 'primary') ?>" style="font-size:10px"><?= e($it['badge']) ?></span>
                    <?php endif; ?>
                    <span style="font-size:11px;color:#94a3b8;font-family:monospace;direction:ltr"><?= e($it['url']) ?></span>
                  </div>

                  <!-- دکمه‌های کنترل و ترتیب -->
                  <div style="display:flex;align-items:center;gap:4px">
                    <!-- بالا / پایین -->
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="move_item">
                      <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
                      <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                      <input type="hidden" name="direction" value="up">
                      <button type="submit" class="fr-btn sm ghost" style="padding:2px 6px" title="حرکت به بالا">⬆</button>
                    </form>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="move_item">
                      <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
                      <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                      <input type="hidden" name="direction" value="down">
                      <button type="submit" class="fr-btn sm ghost" style="padding:2px 6px" title="حرکت به پایین">⬇</button>
                    </form>

                    <!-- زیرمنو شدن / ارتقا به والد -->
                    <?php if (!$isSub && $idx > 0): ?>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="act" value="move_item">
                        <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
                        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                        <input type="hidden" name="direction" value="indent">
                        <button type="submit" class="fr-btn sm ghost" style="padding:2px 6px;color:#2563eb" title="تبدیل به زیرمنوی گزینه قبلی">➡ زیرمنو</button>
                      </form>
                    <?php elseif ($isSub): ?>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="act" value="move_item">
                        <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
                        <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                        <input type="hidden" name="direction" value="outdent">
                        <button type="submit" class="fr-btn sm ghost" style="padding:2px 6px;color:#059669" title="ارتقا به منوی اصلی">⬅ والد</button>
                      </form>
                    <?php endif; ?>

                    <button type="button" class="fr-btn sm ghost" style="padding:2px 8px" onclick="document.getElementById('editItem_<?= (int)$it['id'] ?>').style.display = document.getElementById('editItem_<?= (int)$it['id'] ?>').style.display === 'none' ? 'block' : 'none'" title="ویرایش تنظیمات">
                      ⚙️
                    </button>

                    <form method="post" onsubmit="return confirm('آیا از حذف این گزینه اطمینان دارید؟');" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="delete_item">
                      <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
                      <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                      <button type="submit" class="fr-btn sm ghost" style="color:#ef4444;padding:2px 6px" title="حذف گزینه">🗑</button>
                    </form>
                  </div>
                </div>

                <!-- بخش بازشونده تنظیمات هر آیتم -->
                <div id="editItem_<?= (int)$it['id'] ?>" style="display:none;padding:14px;border-top:1px solid #e2e8f0;background:#fff">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="save_item">
                    <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
                    <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                    <input type="hidden" name="parent_id" value="<?= (int)$it['parent_id'] ?>">
                    <input type="hidden" name="sort_order" value="<?= (int)$it['sort_order'] ?>">

                    <div class="fr-grid cols-2" style="margin-bottom:10px">
                      <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px">متن ناوبری (عنوان):</label>
                        <input type="text" name="title" class="fr-input sm" style="width:100%" value="<?= e($it['title']) ?>" required>
                      </div>
                      <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px">نشانی پیوند (URL):</label>
                        <input type="text" name="url" class="fr-input sm" style="width:100%;direction:ltr;text-align:left" value="<?= e($it['url']) ?>" required>
                      </div>
                    </div>

                    <div class="fr-grid cols-3" style="margin-bottom:12px">
                      <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px">آیکون یا اموجی:</label>
                        <input type="text" name="icon" class="fr-input sm" style="width:100%" value="<?= e($it['icon'] ?? '') ?>" placeholder="مثال: 🧠">
                      </div>
                      <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px">نشان برجسته (Badge):</label>
                        <input type="text" name="badge" class="fr-input sm" style="width:100%" value="<?= e($it['badge'] ?? '') ?>" placeholder="مثال: جدید، رایگان">
                      </div>
                      <div>
                        <label style="display:block;font-size:12px;margin-bottom:4px">رنگ نشان:</label>
                        <select name="badge_color" class="fr-input fr-select sm" style="width:100%">
                          <option value="primary" <?= ($it['badge_color'] === 'primary') ? 'selected' : '' ?>>آبی (Primary)</option>
                          <option value="success" <?= ($it['badge_color'] === 'success') ? 'selected' : '' ?>>سبز (Success)</option>
                          <option value="danger"  <?= ($it['badge_color'] === 'danger') ? 'selected' : '' ?>>قرمز (Danger)</option>
                        </select>
                      </div>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f1f5f9;padding-top:10px">
                      <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
                        <input type="checkbox" name="target" value="_blank" <?= ($it['target'] === '_blank') ? 'checked' : '' ?>>
                        <span>باز شدن پیوند در تب جدید (target="_blank")</span>
                      </label>
                      <button type="submit" class="fr-btn sm primary">ذخیره تغییرات گزینه</button>
                    </div>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- فوتر فرم فهرست -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:24px;border-top:1px solid #e2e8f0;padding-top:16px">
          <form method="post" onsubmit="return confirm('آیا از حذف کامل این فهرست اطمینان دارید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="delete_menu">
            <input type="hidden" name="menu_id" value="<?= (int)$activeMenu['id'] ?>">
            <button type="submit" class="fr-btn sm ghost" style="color:#ef4444">🗑 حذف فهرست</button>
          </form>

          <span class="fr-chip sm info">تعداد کل گزینه‌ها: <?= fa_num(count($menuItems)) ?></span>
        </div>
      </div>
    </div>

  </div>

<?php endif; ?>

<?php admin_layout_end(); ?>
