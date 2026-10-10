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

// بررسی خودکار دسترسی ویژه: اگر تلاش رایگان است اما کاربر برای این تست سفارش پرداخت‌شده دارد
if ($at['mode'] === 'free') {
    $paidOrder = null;
    if (!empty($at['order_id'])) {
        $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') . " WHERE id=? AND status='paid'", [(int)$at['order_id']]);
    }
    if (!$paidOrder) {
        $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') .
                             " WHERE user_id=? AND test_id=? AND status='paid' ORDER BY id DESC LIMIT 1",
                             [$u['id'], $at['test_id']]);
    }
    if ($paidOrder) {
        DB::update('attempts', ['mode' => 'full', 'order_id' => $paidOrder['id']], 'id=?', [$attemptId]);
        $at['mode'] = 'full';
        $at['order_id'] = $paidOrder['id'];
        Analyzer::scoreAttempt($attemptId);
        // پاک‌سازی تلاش خالی معلق احتمالی
        DB::run("DELETE FROM " . DB::t('attempts') .
                " WHERE user_id=? AND test_id=? AND id<>? AND status='in_progress' AND progress=0 AND order_id=?",
                [$u['id'], $at['test_id'], $attemptId, $paidOrder['id']]);
    }
}

log_activity('report_view', 'attempt', $attemptId);

// اقدام افزودن تمرین‌های رشد کارنامه به پلنر روزانه (فاز ۳)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'inject_growth') {
    check_csrf();
    require_once FR_ROOT . '/core/GrowthEngine.php';
    $res = GrowthEngine::injectToPlanner((int)$u['id'], $attemptId);
    if ($res['ok']) {
        flash($res['message'], 'ok');
        redirect(SITE_URL . '/panel/planner.php');
    } else {
        flash($res['message'] ?? 'خطا در افزودن تمرین‌ها به پلنر.', 'err');
        redirect(SITE_URL . '/panel/report.php?attempt=' . $attemptId);
    }
}

render_header('گزارش تست روانشناسی');
panel_layout($u, 'history');
?>
<?php
$assocDyad = DB::one('SELECT id FROM ' . DB::t('dyads') . ' WHERE (attempt1_id=? OR attempt2_id=?) AND status="ready" ORDER BY id DESC LIMIT 1', [$attemptId, $attemptId]);
if ($assocDyad): ?>
<div class="fr-alert ok" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <span>✨ <b>تحلیل تطابق دونفره و زوجین</b> شما و همراهتان آماده مشاهده است!</span>
  <a class="fr-btn sm" style="background:#6d28d9;color:#fff" href="dyad-report.php?id=<?= (int)$assocDyad['id'] ?>">مشاهده گزارش تطابق دونفره ←</a>
</div>
<?php endif; ?>
<div style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap">
  <a class="fr-btn ghost sm" href="history.php">→ بازگشت</a>
  <a class="fr-btn sm" href="report-pdf.php?attempt=<?= $attemptId ?>&auto=1" target="_blank">📄 دریافت / چاپ PDF</a>
  <a class="fr-btn sm" style="background:#6d28d9;color:#fff" href="dyad.php?action=new&attempt=<?= $attemptId ?>">👥 سنجش تطابق دونفره / زوجین</a>
  <a class="fr-btn ghost sm" href="barcode.php?attempt=<?= $attemptId ?>" target="_blank">▮▮▮ بارکد گزارش</a>
  <?php if ($at['mode'] === 'free'): ?>
  <a class="fr-btn sm accent" href="test-start.php?id=<?= (int)$at['test_id'] ?>&upgrade_attempt=<?= $attemptId ?>">⭐ ارتقا به نسخه ویژه</a>
  <?php endif; ?>
</div>
<?php
echo ReportBuilder::build($at, $u, $at['mode']);
panel_layout_end();
render_footer();
