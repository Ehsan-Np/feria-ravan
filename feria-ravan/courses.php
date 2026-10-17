<?php
/**
 * ============================================================================
 *  آرشیو عمومی دوره‌ها — بدون نیاز به ورود (نسخهٔ ۱٫۴)
 *  ─ هویت بصری فیروزه‌ای (tone-course) متمایز از کارگاه‌ها
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
 | برگه دوره‌های آموزشی سفارشی طراحی‌شده با صفحه‌ساز دیداری فریا روان
 * -------------------------------------------------------------------------*/
if (empty($_GET['classic']) && empty($_GET['default_view']) && class_exists('PageBuilder')) {
    PageBuilder::ensureSchema();
    $customPage = PageBuilder::getSectionPage('courses');
    if ($customPage && !empty($customPage['active'])) {
        PageBuilder::renderPage($customPage, true);
        exit;
    }
}

$rows = DB::all("SELECT e.*, (SELECT COALESCE(SUM(1 + COALESCE(r.companion_count,0)),0)
                    FROM " . DB::t('event_registrations') . " r
                   WHERE r.event_id=e.id AND r.status='paid') AS seats
                 FROM " . DB::t('events') . " e
                 WHERE e.kind='course' AND e.status='active'
                 ORDER BY (e.starts_at IS NULL), (e.starts_at >= NOW()) DESC, e.starts_at ASC");

/* نسخهٔ ۱٫۷: تیتر و توضیح هیرو از تنظیمات مدیر خوانده می‌شود (تب «متن‌های صفحات») */
$hTitle = trim((string)DB::setting('courses_hero_title','')) !== ''
        ? (string)DB::setting('courses_hero_title','') : 'دوره‌های آموزشی فریا';
$hDesc  = trim((string)DB::setting('courses_hero_desc','')) !== ''
        ? (string)DB::setting('courses_hero_desc','')
        : 'یادگیری عمیق حوصله‌اش را می‌خواهد؛ این دوره‌ها چندجلسه‌ای طراحی شده‌اند تا واقعاً در وجودتان بنشیند. سرفصل، مدرس و برنامهٔ هر دوره را با آرامش بخوانید و با خیال راحت انتخاب کنید.';

public_header(['active'=>'courses', 'tone'=>'tone-course',
               'canonical'=>SITE_URL . '/courses.php',
               'desc'=>$hDesc], 'دوره‌ها');
?>
<!-- هیروی اختصاصی دوره‌ها (فیروزه‌ای) — متن از تنظیمات (۱٫۷) -->
<section class="pb-hero">
  <span class="pb-hero-ic" aria-hidden="true">📚</span>
  <div class="pb-hero-in">
    <h1><?= e($hTitle) ?></h1>
    <p><?= e($hDesc) ?></p>
  </div>
</section>

<div class="pb-wrap">
  <div class="pb-sec-title"><h2>دوره‌های در حال ثبت‌نام</h2><span class="cnt"><?= fa_num(count($rows)) ?> دوره</span></div>
  <p class="pb-sec-note">اکثر دوره‌ها ثبت‌نام همراه (همسر، والدین، هم‌تیمی) با شهریهٔ ویژه و تخفیف خانوادگی/گروهی دارند.</p>

  <?php if (!$rows): ?>
  <div class="pb-empty"><span class="ic">📚</span>فعلاً دورهٔ فعالی ثبت نشده است؛ به‌زودی دوره‌های جدید اعلام می‌شود.</div>
  <?php else: ?>
  <!-- ۱٫۸: نوار ابزار نمایش (شبکه‌ای/فهرستی + لینک تمام گزینه‌ها) -->
  <?php fr_pc_toolbar('courses-archive', count($rows), 'دوره', SITE_URL . '/workshops.php', 'مشاهدهٔ کارگاه‌های عملی', false); ?>
  <div class="pc-canvas" data-pc-id="courses-archive">
    <?php foreach ($rows as $ev): ?>
      <?php fr_event_pc_card($ev, ev_seats_left($ev)); ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div style="margin-top:34px;text-align:center">
    <a class="fr-btn ghost" href="<?= SITE_URL ?>/workshops.php">🛠 مشاهدهٔ کارگاه‌های یک‌روزه ←</a>
  </div>
</div>
<?php public_footer(); ?>
