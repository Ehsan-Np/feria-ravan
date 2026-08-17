<?php
/** مشاهدهٔ گزارش تست روانشناسی (تحلیل رایگان خلاصه / ویژه کامل) */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../core/Analyzer.php';
fr_bootstrap();

$u = require_user();
$attemptId = (int)($_GET['attempt'] ?? 0);
$at = DB::one('SELECT a.*, t.title FROM ' . DB::t('attempts') . ' a
               JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
               WHERE a.id=? AND a.user_id=?', [$attemptId, $u['id']]);
if (!$at) redirect(SITE_URL . '/panel/');
if ($at['status'] === 'in_progress') redirect(SITE_URL . '/panel/take.php?attempt=' . $attemptId);

log_activity('report_view', 'attempt', $attemptId);

render_header('گزارش تست روانشناسی');
panel_layout($u, 'history');
?>
<div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
  <a class="fr-btn ghost sm" href="history.php">→ بازگشت</a>
  <a class="fr-btn sm" href="report-pdf.php?attempt=<?= $attemptId ?>&auto=1" target="_blank">📄 دریافت / چاپ PDF</a>
  <a class="fr-btn ghost sm" href="barcode.php?attempt=<?= $attemptId ?>" target="_blank">▮▮▮ بارکد گزارش</a>
  <?php if ($at['mode'] === 'free'): ?>
  <a class="fr-btn sm accent" href="test-start.php?id=<?= (int)$at['test_id'] ?>">⭐ ارتقا به نسخه ویژه</a>
  <?php endif; ?>
</div>
<?php
echo ReportBuilder::build($at, $u, $at['mode']);
panel_layout_end();
render_footer();
