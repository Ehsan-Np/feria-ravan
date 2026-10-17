<?php
/**
 * ============================================================================
 *  نمایش عمومی برگه‌های سفارشی فریا روان (Public Page Renderer)
 *  ─ نگارش ۳٫۶٫۰: رندر بلادرنگ برگه‌های طراحی‌شده با صفحه‌ساز دیداری
 *  ─ حل خودکار و بلادرنگ ویجت‌های تست‌ها، دوره‌ها، کارگاه‌ها و مشاوره
 * ============================================================================
 */
declare(strict_types=1);

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install/');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/public-layout.php';
require_once __DIR__ . '/includes/event-pricing.php';
require_once __DIR__ . '/includes/consult.php';
require_once __DIR__ . '/core/PageBuilder.php';

fr_bootstrap();
PageBuilder::ensureSchema();

$slug = trim((string)($_GET['slug'] ?? ''));
$id   = (int)($_GET['id'] ?? 0);

if ($slug !== '') {
    $page = PageBuilder::getPage($slug);
} elseif ($id > 0) {
    $page = PageBuilder::getPage($id);
} else {
    $page = null;
}

// اگر برگه یافت نشد
if (!$page) {
    http_response_code(404);
    public_header(['active' => ''], 'برگه یافت نشد (۴۰۴)');
    ?>
    <div style="padding:80px 20px;text-align:center;max-width:600px;margin:0 auto">
      <div style="font-size:64px;margin-bottom:16px">🔍</div>
      <h1 style="font-size:24px;font-weight:800;color:#0f172a;margin:0 0 10px">برگه موردنظر یافت نشد!</h1>
      <p style="font-size:14px;color:#64748b;line-height:1.8;margin:0 0 24px">
        متأسفانه برگه‌ای که به دنبال آن بودید پیدا نشد؛ ممکن است حذف شده یا آدرس آن تغییر کرده باشد.
      </p>
      <a href="<?= SITE_URL ?>/" class="fr-btn primary" style="text-decoration:none;padding:10px 24px">
        ← بازگشت به صفحه اصلی
      </a>
    </div>
    <?php
    public_footer();
    exit;
}

// اگر برگه پیش‌نویس است و کاربر مدیر نیست
$adm = current_admin();
if (empty($page['active']) && !$adm) {
    http_response_code(404);
    public_header(['active' => ''], 'برگه در دسترس نیست');
    ?>
    <div style="padding:80px 20px;text-align:center;max-width:600px;margin:0 auto">
      <div style="font-size:64px;margin-bottom:16px">🔒</div>
      <h1 style="font-size:24px;font-weight:800;color:#0f172a;margin:0 0 10px">این برگه هنوز منتشر نشده است</h1>
      <p style="font-size:14px;color:#64748b;line-height:1.8;margin:0 0 24px">
        این صفحه در وضعیت پیش‌نویس قرار دارد و در حال حاضر در دسترس عموم نیست.
      </p>
      <a href="<?= SITE_URL ?>/" class="fr-btn primary" style="text-decoration:none;padding:10px 24px">
        ← بازگشت به صفحه اصلی
      </a>
    </div>
    <?php
    public_footer();
    exit;
}

// رندر صفحه
PageBuilder::renderPage($page, empty($_GET['preview']));
