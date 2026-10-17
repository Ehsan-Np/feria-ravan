<?php
/**
 * ============================================================================
 *  مدیریت برگه‌ها و صفحه‌ساز دیداری (Pages & PageBuilder Management)
 *  ─ نگارش ۳٫۶٫۰: مدیریت ساختار و چیدمان صفحه اصلی و برگه‌های دلخواه
 *  ─ اتصال به ویرایشگر درگ اند دراپ PHPageBuilder / GrapesJS
 *  ─ ویجت‌های اختصاصی: تست‌ها، دوره‌ها، کارگاه‌ها، مشاوره، خبرنامه و...
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/core/PageBuilder.php';

$adm = require_admin(['superadmin', 'manager']);
PageBuilder::ensureSchema();

// پردازش عملیات‌های POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');

    if ($act === 'save') {
        $id    = (int)post('id');
        $title = trim((string)post('title'));
        $name  = trim((string)post('name')) ?: $title;
        $slug  = trim((string)post('slug'));

        if ($title === '') {
            flash('عنوان برگه الزامی است.', 'err');
            redirect(SITE_URL . '/admin/pages.php');
        }

        $data = [
            'name'        => $name,
            'title'       => $title,
            'slug'        => $slug,
            'layout'      => (string)post('layout', 'default'),
            'seo_title'   => (string)post('seo_title'),
            'meta_desc'   => (string)post('meta_desc'),
            'noindex'     => !empty(post('noindex')) ? 1 : 0,
            'is_home'     => !empty(post('is_home')) ? 1 : 0,
            'active'      => !empty(post('active')) ? 1 : 0,
        ];

        $savedId = PageBuilder::savePage($data, $id > 0 ? $id : null);
        if ($id > 0) {
            flash('مشخصات برگه با موفقیت به‌روزرسانی شد ✅', 'ok');
        } else {
            flash('برگه جدید با موفقیت ایجاد شد 🎉 اکنون می‌توانید با صفحه‌ساز آن را طراحی کنید.', 'ok');
            redirect(SITE_URL . '/admin/page-builder.php?id=' . $savedId);
        }
        redirect(SITE_URL . '/admin/pages.php');
    }

    if ($act === 'set_home') {
        $pageId = (int)post('id');
        if (PageBuilder::setHomepage($pageId)) {
            $p = PageBuilder::getPage($pageId);
            flash('برگه «' . e($p['title']) . '» به عنوان صفحه اصلی سایت تنظیم گردید ⭐️', 'ok');
        }
        redirect(SITE_URL . '/admin/pages.php');
    }

    if ($act === 'toggle_active') {
        $pageId = (int)post('id');
        $p = PageBuilder::getPage($pageId);
        if ($p) {
            $newSt = (int)$p['active'] === 1 ? 0 : 1;
            DB::update('site_pages', ['active' => $newSt], 'id=?', [$pageId]);
            flash('وضعیت انتشار برگه تغییر یافت.', 'ok');
        }
        redirect(SITE_URL . '/admin/pages.php');
    }

    if ($act === 'duplicate') {
        $pageId = (int)post('id');
        $newId = PageBuilder::duplicatePage($pageId);
        if ($newId) {
            flash('نسخه کپی با موفقیت ایجاد شد (پیش‌نویس) 📋', 'ok');
        }
        redirect(SITE_URL . '/admin/pages.php');
    }

    if ($act === 'delete') {
        $pageId = (int)post('id');
        if (PageBuilder::deletePage($pageId)) {
            flash('برگه با موفقیت حذف گردید.', 'ok');
        } else {
            flash('امکان حذف برگه فعال صفحه اصلی وجود ندارد؛ ابتدا برگه دیگری را به عنوان صفحه اصلی برگزینید.', 'err');
        }
        redirect(SITE_URL . '/admin/pages.php');
    }
}

$pages = PageBuilder::getPages();
$homePage = PageBuilder::getHomepage();

$editId = (int)($_GET['edit'] ?? 0);
$editPage = $editId > 0 ? PageBuilder::getPage($editId) : null;

admin_layout($adm, 'pages', 'مدیریت برگه‌ها و صفحه‌ساز');
echo flash();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
  <div>
    <h2 style="margin:0;font-size:22px;color:#0f172a">📄 مدیریت برگه‌ها و صفحه‌ساز دیداری (Page Builder)</h2>
    <p style="margin:4px 0 0;font-size:13px;color:#64748b">
      امکان طراحی و شخصی‌سازی چیدمان صفحه اصلی و تمام صفحات با ویرایشگر زنده کشیدن و رها کردن و ویجت‌های اختصاصی فریا روان.
    </p>
  </div>
  <div style="display:flex;gap:10px">
    <?php if ($homePage): ?>
      <a class="fr-btn ghost sm" href="page-builder.php?id=<?= (int)$homePage['id'] ?>" target="_blank" style="background:#fef3c7;border-color:#f59e0b;color:#92400e;font-weight:700">
        🎨 طراحی زنده صفحه اصلی (<?= e($homePage['title']) ?>)
      </a>
    <?php endif; ?>
    <button type="button" class="fr-btn sm primary" onclick="document.getElementById('modalAddPage').style.display='flex'">
      + ساخت برگه جدید
    </button>
  </div>
</div>

<!-- کارت‌های آمار برگه‌ها -->
<div class="fr-grid cols-4" style="margin-bottom:24px">
  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#e0e7ff;color:#4338ca;font-size:22px;display:flex;align-items:center;justify-content:center">
      📄
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">کل برگه‌ها</div>
      <b style="font-size:18px;color:#0f172a"><?= fa_num(count($pages)) ?> برگه</b>
    </div>
  </div>

  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#fef3c7;color:#d97706;font-size:22px;display:flex;align-items:center;justify-content:center">
      ⭐️
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">صفحه اصلی فعال</div>
      <b style="font-size:14px;color:#b45309"><?= $homePage ? e(mb_strimwidth($homePage['title'], 0, 22, '...')) : 'صفحه پیش‌فرض' ?></b>
    </div>
  </div>

  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#dcfce7;color:#15803d;font-size:22px;display:flex;align-items:center;justify-content:center">
      ✓
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">برگه‌های منتشرشده</div>
      <b style="font-size:18px;color:#15803d">
        <?= fa_num(count(array_filter($pages, fn($p) => !empty($p['active'])))) ?>
      </b>
    </div>
  </div>

  <div class="fr-card" style="margin:0;padding:16px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:12px;background:#f1f5f9;color:#64748b;font-size:22px;display:flex;align-items:center;justify-content:center">
      👁️
    </div>
    <div>
      <div style="font-size:12px;color:#64748b">مجموع بازدید برگه‌ها</div>
      <b style="font-size:18px;color:#0f172a">
        <?= fa_num(array_sum(array_column($pages, 'view_count'))) ?>
      </b>
    </div>
  </div>
</div>

<!-- جدول برگه‌ها -->
<div class="fr-card" style="padding:0;overflow:hidden">
  <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center">
    <b style="font-size:15px;color:#1e293b">📋 فهرست برگه‌ها و صفحات اختصاصی سایت</b>
    <span class="fr-chip info sm">پشتیبانی از ویجت‌های داینامیک تست‌ها، دوره‌ها و مشاوره</span>
  </div>

  <?php if (empty($pages)): ?>
    <div style="padding:40px;text-align:center;color:#64748b">
      هنوز برگه‌ای تعریف نشده است. می‌توانید با کلیک بر روی دکمهٔ «ساخت برگه جدید» اولین برگه خود را ایجاد نمایید.
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="fr-table" style="width:100%;font-size:13px">
        <thead>
          <tr style="background:#f1f5f9;text-align:right">
            <th>عنوان و نشانی برگه</th>
            <th style="text-align:center">صفحه اصلی</th>
            <th style="text-align:center">قالب</th>
            <th style="text-align:center">وضعیت</th>
            <th style="text-align:center">بازدید</th>
            <th style="text-align:center">آخرین ویرایش</th>
            <th style="text-align:left;width:260px">عملیات و طراحی</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pages as $p):
              $isHome = !empty($p['is_home']);
              $isPub  = !empty($p['active']);
              $pageUrl = $isHome ? SITE_URL . '/' : (SITE_URL . '/page.php?slug=' . urlencode((string)$p['slug']));
          ?>
            <tr style="<?= $isHome ? 'background:#fffdf5' : '' ?>">
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <b style="font-size:14px;color:#0f172a"><?= e($p['title']) ?></b>
                  <?php if ($isHome): ?>
                    <span class="fr-chip sm warn" style="font-size:11px;font-weight:700">⭐️ صفحه اصلی سایت</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:12px;color:#64748b;font-family:monospace;margin-top:3px;direction:ltr;text-align:right">
                  <a href="<?= e($pageUrl) ?>" target="_blank" style="color:#0284c7;text-decoration:none">
                    <?= $isHome ? '/' : ('/page.php?slug=' . e($p['slug'])) ?> ↗
                  </a>
                </div>
              </td>

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

                  <a href="<?= e($pageUrl) ?>" target="_blank" class="fr-btn sm ghost" title="مشاهده برگه" style="padding:5px 8px">
                    👁️
                  </a>

                  <button type="button" class="fr-btn sm ghost" title="ویرایش تنظیمات" style="padding:5px 8px"
                          onclick='openEditModal(<?= json_encode($p, JSON_UNESCAPED_UNICODE) ?>)'>
                    ⚙️
                  </button>

                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="duplicate">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="fr-btn sm ghost" title="کپی و تکثیر برگه" style="padding:5px 8px">
                      📋
                    </button>
                  </form>

                  <?php if (!$isHome): ?>
                    <form method="post" onsubmit="return confirm('آیا از حذف این برگه اطمینان دارید؟');" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="act" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="fr-btn sm ghost" style="color:#ef4444;padding:5px 8px" title="حذف برگه">
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

<!-- مودال ساخت / ویرایش برگه -->
<div id="modalAddPage" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)">
  <div class="fr-card" style="width:100%;max-width:560px;margin:0;max-height:90vh;overflow-y:auto;border-radius:18px;box-shadow:0 20px 40px rgba(0,0,0,0.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:14px;margin-bottom:16px">
      <h3 id="modalTitle" style="margin:0;font-size:17px;color:#0f172a">➕ ایجاد برگه جدید</h3>
      <button type="button" onclick="closeModal()" style="border:none;background:transparent;font-size:20px;cursor:pointer;color:#64748b">&times;</button>
    </div>

    <form method="post" id="pageForm">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="f_id" value="0">

      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:700;margin-bottom:6px">عنوان برگه: <span style="color:#ef4444">*</span></label>
        <input type="text" name="title" id="f_title" class="fr-input" style="width:100%" required placeholder="مثال: درباره ما یا جشنواره پاییزه">
      </div>

      <div class="fr-grid cols-2" style="margin-bottom:14px">
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">نام اداری (اختیاری):</label>
          <input type="text" name="name" id="f_name" class="fr-input" style="width:100%" placeholder="نام برگه در پنل">
        </div>
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px">اسلاگ پیوند یکتا (Slug):</label>
          <input type="text" name="slug" id="f_slug" class="fr-input" style="width:100%;direction:ltr;text-align:left" placeholder="about-us">
        </div>
      </div>

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

      <!-- تنظیمات سئو -->
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

      <div style="display:flex;justify-content:flex-end;gap:10px">
        <button type="button" class="fr-btn ghost sm" onclick="closeModal()">انصراف</button>
        <button type="submit" class="fr-btn primary sm" style="padding:8px 20px">ذخیره برگه</button>
      </div>
    </form>
  </div>
</div>

<script>
function closeModal() {
    document.getElementById('modalAddPage').style.display = 'none';
    document.getElementById('pageForm').reset();
    document.getElementById('f_id').value = '0';
    document.getElementById('modalTitle').innerText = '➕ ایجاد برگه جدید';
}

function openEditModal(page) {
    document.getElementById('modalTitle').innerText = '✏️ ویرایش برگه: «' + page.title + '»';
    document.getElementById('f_id').value = page.id || 0;
    document.getElementById('f_title').value = page.title || '';
    document.getElementById('f_name').value = page.name || '';
    document.getElementById('f_slug').value = page.slug || '';
    document.getElementById('f_layout').value = page.layout || 'default';
    document.getElementById('f_active').value = page.active ? '1' : '0';
    document.getElementById('f_is_home').checked = page.is_home == 1;
    document.getElementById('f_seo_title').value = page.seo_title || '';
    document.getElementById('f_meta_desc').value = page.meta_desc || '';
    document.getElementById('f_noindex').checked = page.noindex == 1;

    document.getElementById('modalAddPage').style.display = 'flex';
}
</script>

<?php admin_layout_end(); ?>
