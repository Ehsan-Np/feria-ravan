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
if (!$at) redirect(fr_panel_url());
if ($at['status'] === 'in_progress') redirect(fr_panel_url('take.php?attempt=' . $attemptId));

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (post('act') === 'inject_growth' || ($_POST['act'] ?? '') === 'inject_growth')) {
    check_csrf();
    require_once FR_ROOT . '/core/GrowthEngine.php';
    try {
        $res = GrowthEngine::injectToPlanner((int)$u['id'], $attemptId);
        if (!empty($res['ok'])) {
            flash($res['message'], 'ok');
            redirect(fr_panel_url('planner.php'));
        } else {
            flash($res['message'] ?? 'خطا در افزودن تمرین‌ها به پلنر.', 'err');
            redirect(fr_panel_url('report.php?attempt=' . $attemptId));
        }
    } catch (Throwable $e) {
        error_log('feria inject_growth: ' . $e->getMessage());
        flash('خطا در ثبت تمرین‌ها: ' . $e->getMessage(), 'err');
        redirect(fr_panel_url('report.php?attempt=' . $attemptId));
    }
}

render_header('گزارش تست روانشناسی');
panel_layout($u, 'history');
?>
<style>
@media print {
  body, html { background: #fff !important; color: #000 !important; font-size: 11pt !important; }
  .panel-sidebar, .fr-sidebar, .panel-nav, .fr-topbar, .site-header, .site-footer, .no-print,
  .fr-report-actions, .fr-alert, a.fr-btn, button.fr-btn { display: none !important; }
  .panel-main, .panel-content, .container, main { margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; box-shadow: none !important; }
  .fr-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; break-inside: avoid !important; page-break-inside: avoid !important; margin-bottom: 16px !important; }
}
</style>
<?php
$assocDyad = DB::one('SELECT id FROM ' . DB::t('dyads') . ' WHERE (attempt1_id=? OR attempt2_id=?) AND status="ready" ORDER BY id DESC LIMIT 1', [$attemptId, $attemptId]);
if ($assocDyad): ?>
<div class="fr-alert ok no-print" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <span>✨ <b>تحلیل تطابق دونفره و زوجین</b> شما و همراهتان آماده مشاهده است!</span>
  <a class="fr-btn sm" style="background:#6d28d9;color:#fff" href="dyad-report.php?id=<?= (int)$assocDyad['id'] ?>">مشاهده گزارش تطابق دونفره ←</a>
</div>
<?php endif; ?>
<div class="fr-report-actions no-print" style="display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;align-items:center">
  <a class="fr-btn ghost sm" href="history.php">→ بازگشت</a>
  <a class="fr-btn sm" style="background:#1e3a6e;color:#fff;font-weight:600" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $attemptId . '&print=1')) ?>" target="_blank" title="مشاهده و دریافت نسخه چاپی رسمی کارنامه در ابعاد استاندارد A4">🖨️ دریافت نسخه چاپی کارنامه (A4)</a>
  <a class="fr-btn sm" style="background:#059669;color:#fff" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $attemptId . '&download=1')) ?>" target="_blank" title="دانلود مستقیم فایل PDF کارنامه">📥 دانلود فایل PDF</a>
  <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $attemptId)) ?>" target="_blank" title="مشاهده سند رسمی کارنامه A4">📄 مشاهده نسخه A4</a>
  <button class="fr-btn sm ghost" onclick="window.print()" title="چاپ مستقیم این صفحه">🖨️ چاپ سریع صفحه</button>
  <a class="fr-btn sm" style="background:#6d28d9;color:#fff" href="dyad.php?action=new&attempt=<?= $attemptId ?>">👥 سنجش تطابق دونفره / زوجین</a>
  <a class="fr-btn ghost sm" href="barcode.php?attempt=<?= $attemptId ?>" target="_blank">▮▮▮ بارکد گزارش</a>
  <?php if ($at['mode'] === 'free'): ?>
  <a class="fr-btn sm accent" href="test-start.php?id=<?= (int)$at['test_id'] ?>&upgrade_attempt=<?= $attemptId ?>">⭐ ارتقا به نسخه ویژه</a>
  <?php endif; ?>
</div>
<?php
if (!empty($at['custom_fields_json'])) {
    require_once FR_ROOT . '/core/TestFields.php';
    $cfData = json_decode((string)$at['custom_fields_json'], true);
    if (is_array($cfData)) {
        $hasConsultation = false;
        if (isset($cfData['free_consultation'])) {
            $cVal = $cfData['free_consultation']['value'] ?? '';
            $cRaw = $cfData['free_consultation']['raw'] ?? '';
            if (is_array($cRaw)) {
                foreach ($cRaw as $crItem) {
                    if (str_contains(mb_strtolower((string)$crItem), 'yes')) {
                        $hasConsultation = true;
                        break;
                    }
                }
            } else {
                $hasConsultation = str_contains((string)$cVal, 'بله') || str_contains(mb_strtolower((string)$cRaw), 'yes');
            }
        }
        if ($hasConsultation): ?>
          <div class="fr-alert ok" style="margin-bottom:18px;background:#ecfdf5;border:1.5px solid #a7f3d0;border-radius:14px;padding:14px 18px">
            <div style="display:flex;align-items:center;gap:10px">
              <span style="font-size:24px">🎉</span>
              <div>
                <b style="color:#065f46;font-size:14px">درخواست جلسه مشاوره رایگان تخصصی شما با موفقیت ثبت شد!</b>
                <div style="font-size:12.5px;color:#047857;margin-top:3px">کارشناسان روان‌شناسی <?= e(fr_brand_title()) ?> بر اساس نتایج این آزمون و ترجیح زمانی ثبت‌شده، به‌زودی جهت هماهنگی جلسه با شما تماس خواهند گرفت.</div>
              </div>
            </div>
          </div>
        <?php endif;
        echo TestFields::renderDisplayCard($at['custom_fields_json'], '📋 پاسخ‌ها و درخواست‌های ثبت‌شده شما (مشاوره رایگان و گزینه‌ها)');
    }
}
?>
<?php
// در پنل کاربری، پروتکل‌های بالینی مداخله همواره از دسترس کاربران عادی پنهان می‌مانند
$isViewerAdmin = (bool)(function_exists('current_admin') && current_admin());
echo ReportBuilder::build($at, $u, (string)($at['mode'] ?? 'full'), ['isAdmin' => $isViewerAdmin]);
?>

<!-- نوار دریافت نسخه چاپی و دانلود در انتهای کارنامه -->
<div class="fr-card no-print" style="margin-top:24px;background:#f8fafc;border:1.5px solid #cbd5e1;padding:18px 22px;border-radius:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px">
  <div>
    <b style="font-size:15px;color:#0f172a">🖨️ دریافت و چاپ کارنامه رسمی آزمون</b>
    <div style="font-size:12.5px;color:#64748b;margin-top:3px">شما می‌توانید کارنامه رسمی ارزیابی روان‌شناختی خود را با سربرگ معتبر در ابعاد استاندارد A4 دریافت، دانلود یا چاپ نمایید.</div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <a class="fr-btn sm" style="background:#1e3a6e;color:#fff;font-weight:600" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $attemptId . '&print=1')) ?>" target="_blank" title="مشاهده و دریافت نسخه چاپی کارنامه رسمی در ابعاد استاندارد A4">🖨️ دریافت نسخه چاپی (A4)</a>
    <a class="fr-btn sm" style="background:#059669;color:#fff" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $attemptId . '&download=1')) ?>" target="_blank" title="دانلود مستقیم فایل PDF کارنامه">📥 دانلود فایل PDF</a>
    <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $attemptId)) ?>" target="_blank" title="مشاهده نسخه استاندارد A4 کارنامه">📄 مشاهده برگه A4</a>
    <button class="fr-btn sm ghost" onclick="window.print()" title="چاپ مستقیم این صفحه">🖨️ چاپ سریع صفحه</button>
  </div>
</div>

<?php
panel_layout_end();
render_footer();
