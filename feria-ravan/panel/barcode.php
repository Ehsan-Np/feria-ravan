<?php
/**
 * بارکد گزارش — کد ۳۹ (Code39) به‌صورت SVG خالص، سبک و بدون وابستگی
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
fr_bootstrap();

$u = require_user();
$at = DB::one('SELECT a.*, t.title FROM ' . DB::t('attempts') . ' a JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
               WHERE a.id=? AND a.user_id=?', [(int)($_GET['attempt'] ?? 0), $u['id']]);
if (!$at) redirect(fr_panel_url());

/* نسخهٔ ۲٫۰: تابع barcode39_svg() به includes/view.php منتقل شده است
   تا در «نسخهٔ چاپی گزارش» (report-pdf.php) نیز به‌صورت جایگزینِ QR استفاده شود. */

$code = 'FR' . str_pad((string)$at['id'], 8, '0', STR_PAD_LEFT);
render_header('بارکد گزارش');
?>
<div style="max-width:520px;margin:50px auto" class="fr-card" style="text-align:center">
  <h3 style="justify-content:center">بارکد پیگیری گزارش</h3>
  <div style="overflow-x:auto;padding:16px 0"><?= barcode39_svg($code) ?></div>
  <p style="color:var(--muted);font-size:13px">
    تست روانشناسی: <?= e($at['title']) ?><br>تست‌دهنده: <?= e(user_fullname($u)) ?><br>
    کد: <b style="direction:ltr"><?= e($code) ?></b>
  </p>
  <button class="fr-btn sm" onclick="window.print()">🖨 چاپ</button>
  <a class="fr-btn ghost sm" href="report.php?attempt=<?= (int)$at['id'] ?>">بازگشت به گزارش</a>
</div>
<?php render_footer(); ?>
