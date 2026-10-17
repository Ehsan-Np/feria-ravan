<?php
/**
 * ============================================================================
 *  آرشیو عمومی کارگاه‌ها — بدون نیاز به ورود (نسخهٔ ۱٫۴)
 *  ─ کارت‌های اختصاصی با کاور، تاریخ جلالی، ظرفیت آزاد و تخفیف ویژه
 *  ─ ثبت‌نام از صفحهٔ تکی → پنل (ورود خودکار با بازگشت)
 * ============================================================================
 */
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install/'); exit; }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/public-layout.php';
require_once __DIR__ . '/includes/event-pricing.php';
require_once __DIR__ . '/core/Kavenegar.php';
require_once __DIR__ . '/core/Zarinpal.php';
require_once __DIR__ . '/core/PageBuilder.php';
fr_bootstrap();

/* ---------------------------------------------------------------------------
 | برگه کارگاه‌های تخصصی سفارشی طراحی‌شده با صفحه‌ساز دیداری فریا روان
 * -------------------------------------------------------------------------*/
if (empty($_GET['classic']) && empty($_GET['default_view']) && class_exists('PageBuilder')) {
    PageBuilder::ensureSchema();
    $customPage = PageBuilder::getSectionPage('workshops');
    if ($customPage && !empty($customPage['active'])) {
        PageBuilder::renderPage($customPage, true);
        exit;
    }
}

/* کارگاه‌های فعال؛ آینده بالاتر و پس‌ازآن گذشته‌ها */
$rows = DB::all("SELECT e.*, (SELECT COALESCE(SUM(1 + COALESCE(r.companion_count,0)),0)
                    FROM " . DB::t('event_registrations') . " r
                   WHERE r.event_id=e.id AND r.status='paid') AS seats
                 FROM " . DB::t('events') . " e
                 WHERE e.kind='workshop' AND e.status='active'
                 ORDER BY (e.starts_at IS NULL), (e.starts_at >= NOW()) DESC, e.starts_at ASC");

/* نسخهٔ ۱٫۷: تیتر و توضیح هیرو از تنظیمات مدیر خوانده می‌شود (تب «متن‌های صفحات») */
$hTitle = trim((string)DB::setting('workshops_hero_title','')) !== ''
        ? (string)DB::setting('workshops_hero_title','') : 'کارگاه‌های روانشناسی فریا';
$hDesc  = trim((string)DB::setting('workshops_hero_desc','')) !== ''
        ? (string)DB::setting('workshops_hero_desc','')
        : 'اینجا کارگاه‌های عملی و یک‌روزه را پیدا می‌کنید؛ آن‌هایی که با تمرین واقعی یاد می‌گیرید، نه فقط حرف. جزئیات هر کدام آزادانه در دسترس است؛ هر وقت آماده شدید، ثبت‌نامش دو دقیقه بیشتر طول نمی‌کشد. اگر با عزیزانتان می‌آیید، قیمت‌های گروهی و نفرهای رایگان هر کارگاه را حتماً ببینید.';

public_header(['active'=>'workshops',
               'canonical'=>SITE_URL . '/workshops.php',
               'desc'=>$hDesc], 'کارگاه‌ها');
?>
<!-- هیروی اختصاصی کارگاه‌ها — متن از تنظیمات (۱٫۷) -->
<section class="pb-hero">
  <span class="pb-hero-ic" aria-hidden="true">🛠</span>
  <div class="pb-hero-in">
    <h1><?= e($hTitle) ?></h1>
    <p><?= e($hDesc) ?></p>
  </div>
</section>

<div class="pb-wrap">
  <div class="pb-sec-title"><h2>کارگاه‌های پیش‌رو</h2><span class="cnt"><?= fa_num(count($rows)) ?> کارگاه</span></div>
  <p class="pb-sec-note">برای ثبت‌نام گروهی (همراه با خانواده یا تیم)، گزینهٔ «ثبت‌نام همراه» و تخفیف‌های پلکانی هر کارگاه را در صفحهٔ جزئیات ببینید.</p>

  <?php if (!$rows): ?>
  <div class="pb-empty"><span class="ic">🛠</span>فعلاً کارگاه فعالی ثبت نشده است؛ به‌زودی برنامه‌های جدید اعلام می‌شود.</div>
  <?php else: ?>
  <!-- ۱٫۸: نوار ابزار نمایش (شبکه‌ای/فهرستی + لینک تمام گزینه‌ها) -->
  <?php fr_pc_toolbar('workshops-archive', count($rows), 'کارگاه', SITE_URL . '/courses.php', 'مشاهدهٔ دوره‌های آموزشی', false); ?>
  <div class="pc-canvas" data-pc-id="workshops-archive">
    <?php foreach ($rows as $ev): ?>
      <?php fr_event_pc_card($ev, ev_seats_left($ev)); ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- دعوت متقابل به دوره‌ها -->
  <div style="margin-top:34px;text-align:center">
    <a class="fr-btn ghost tone-course" href="<?= SITE_URL ?>/courses.php">📚 مشاهدهٔ دوره‌های آموزشی ←</a>
  </div>
</div>
<?php public_footer(); ?>
