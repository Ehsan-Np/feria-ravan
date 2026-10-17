<?php
/**
 * ============================================================================
 *  آرشیو عمومی تست‌های روانشناسی — تفکیک سه‌گانه روان‌سنجی (نسخهٔ ۳٫۳)
 *  ─ دسته‌بندی‌ها:
 *     ۱) بالینی و سلامت روان (clinical)
 *     ۲) شخصیت و خودشناسی (personality)
 *     ۳) هوش، استعداد و پیشرفت شغلی (intelligence)
 *  ─ تب‌های مدرن دسته‌بندی + فیلتر مخاطب + نوار ابزار نمایش شبکه‌ای/فهرستی
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
require_once __DIR__ . '/core/PageBuilder.php';
fr_bootstrap();

/* ---------------------------------------------------------------------------
 | برگه تست‌های روان‌شناسی سفارشی طراحی‌شده با صفحه‌ساز دیداری فریا روان
 * -------------------------------------------------------------------------*/
if (empty($_GET['classic']) && empty($_GET['default_view']) && empty($_GET['cat']) && empty($_GET['a']) && empty($_GET['q'])) {
    PageBuilder::ensureSchema();
    $customPage = PageBuilder::getSectionPage('tests');
    if ($customPage && !empty($customPage['active'])) {
        PageBuilder::renderPage($customPage, true);
        exit;
    }
}

$cat = (string)($_GET['cat'] ?? 'all');
if (!in_array($cat, ['all', 'clinical', 'personality', 'intelligence'], true)) {
    $cat = 'all';
}

$aud = (string)($_GET['a'] ?? 'all');
if (!in_array($aud, ['all', 'general', 'manager'], true)) {
    $aud = 'all';
}

$where = ["status='active'"];
$params = [];

if ($cat !== 'all') {
    $where[] = "category=?";
    $params[] = $cat;
}
if ($aud !== 'all') {
    $where[] = "audience=?";
    $params[] = $aud;
}

$sql = "SELECT * FROM " . DB::t('tests') . " WHERE " . implode(' AND ', $where) . " ORDER BY sort_order";
$rows = DB::all($sql, $params);

$cntTotal = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active'");
$cntClin  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active' AND category='clinical'");
$cntPers  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active' AND category='personality'");
$cntIntel = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active' AND category='intelligence'");

$categoryMeta = [
    'clinical' => [
        'title' => 'آزمون‌های بالینی و سلامت روان',
        'icon'  => '🩺',
        'badge' => 'تشخیصی و غربالگری',
        'bg'    => 'linear-gradient(135deg,#fff1f2,#ffe4e6)',
        'color' => '#be123c',
        'desc'  => 'آزمون‌های معتبر روان‌شناسی بالینی و روان‌پزشکی جهت شناسایی تله‌های زندگی (طرحواره‌های یانگ)، غربالگری افسردگی، اضطراب و نیمرخ آسیب‌شناسی شخصیتی MMPI.',
    ],
    'personality' => [
        'title' => 'آزمون‌های شخصیت‌شناسی و خودشناسی',
        'icon'  => '🎭',
        'badge' => 'الگوهای رفتار و هویت',
        'bg'    => 'linear-gradient(135deg,#f5f3ff,#ede9fe)',
        'color' => '#6d28d9',
        'desc'  => 'کشف لایه‌های عمیق شخصیت، کارکردهای شناختی یونگ و سبک‌های ارتباطی با آزمون‌های جهانی MBTI (۱۶ تیپ شخصیتی)، مدل ۵ عاملی نئو (NEO-FFI) و آزمون رفتارشناسی DISC.',
    ],
    'intelligence' => [
        'title' => 'آزمون‌های هوش، استعداد و پیشرفت شغلی',
        'icon'  => '💡',
        'badge' => 'تحصیلی، شغلی و هوش',
        'bg'    => 'linear-gradient(135deg,#f0fdf4,#dcfce7)',
        'color' => '#15803d',
        'desc'  => 'سنجش جامع هوش سیال با ماتریس‌های ریون (Raven IQ)، شاخص‌های چهارگانه هوش وکسلر (WAIS)، هوش هیجانی بار-آن، رغبت‌سنجی شغلی هالند و نقشه هوش‌های ۹گانه گاردنر.',
    ],
];

$hTitle = trim((string)DB::setting('tests_hero_title', '')) !== ''
        ? (string)DB::setting('tests_hero_title', '') : 'سامانه جامع آزمون‌های روان‌سنجی فریا روان';
$hDesc  = trim((string)DB::setting('tests_hero_desc', '')) !== ''
        ? (string)DB::setting('tests_hero_desc', '')
        : 'مجموعه تخصصی و استاندارد آزمون‌های بالینی، تیپ‌شناسی شخصیت، هوش و رغبت‌سنجی شغلی. هر آزمون شامل کارنامهٔ تشخیصی رایگان اولیه و گزارش تفصیلی ۶ فصلی بالینی است.';

public_header(['active' => 'tests',
               'canonical' => SITE_URL . '/tests.php' . ($cat !== 'all' ? '?cat=' . $cat : ''),
               'desc' => $hDesc], 'تست‌های روانشناسی');
?>
<!-- هیروی آرشیو تست‌های روانشناسی -->
<section class="pb-hero">
  <span class="pb-hero-ic" aria-hidden="true">🧠</span>
  <div class="pb-hero-in">
    <h1><?= e($hTitle) ?></h1>
    <p><?= e($hDesc) ?></p>
  </div>
</section>

<div class="pb-wrap">
  <!-- تب‌های دسته‌بندی سه‌گانه روان‌سنجی -->
  <nav class="pc-tabs" aria-label="فیلتر دسته‌بندی تست‌های روانشناسی" style="margin-bottom:14px">
    <a href="<?= SITE_URL ?>/tests.php" class="<?= $cat === 'all' ? 'on' : '' ?>">همه آزمون‌ها (<?= fa_num($cntTotal) ?>)</a>
    <a href="<?= SITE_URL ?>/tests.php?cat=clinical" class="<?= $cat === 'clinical' ? 'on' : '' ?>">🩺 بالینی و سلامت روان (<?= fa_num($cntClin) ?>)</a>
    <a href="<?= SITE_URL ?>/tests.php?cat=personality" class="<?= $cat === 'personality' ? 'on' : '' ?>">🎭 شخصیت و خودشناسی (<?= fa_num($cntPers) ?>)</a>
    <a href="<?= SITE_URL ?>/tests.php?cat=intelligence" class="<?= $cat === 'intelligence' ? 'on' : '' ?>">💡 هوش، استعداد و پیشرفت شغلی (<?= fa_num($cntIntel) ?>)</a>
  </nav>

  <?php if ($cat !== 'all' && isset($categoryMeta[$cat])):
    $meta = $categoryMeta[$cat];
  ?>
  <!-- سربرگ اختصاصی دسته‌بندی انتخاب‌شده -->
  <div class="fr-card" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:<?= $meta['bg'] ?>;border:1px solid rgba(0,0,0,0.06);margin-bottom:20px">
    <div style="width:48px;height:48px;border-radius:14px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 2px 8px rgba(0,0,0,0.05)">
      <?= $meta['icon'] ?>
    </div>
    <div style="flex:1;min-width:240px">
      <div style="display:flex;align-items:center;gap:8px">
        <b style="font-size:16px;color:<?= $meta['color'] ?>"><?= e($meta['title']) ?></b>
        <span class="fr-chip" style="background:#fff;color:<?= $meta['color'] ?>;font-size:11px"><?= e($meta['badge']) ?></span>
      </div>
      <div style="font-size:12.5px;color:var(--text);margin-top:4px;line-height:1.9;opacity:.88">
        <?= e($meta['desc']) ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
  <div class="pb-empty"><span class="ic">🧠</span>فعلاً آزمون فعالی در این بخش ثبت نشده است؛ به‌زودی آزمون‌های جدید بارگذاری خواهند شد.</div>
  <?php else: ?>
    <?php fr_pc_toolbar('tests-archive', count($rows), 'تست تخصصی'); ?>
    <?php foreach ($rows as $t): fr_test_pc_card($t); endforeach; ?>
    <?php fr_pc_toolbar_end(); ?>
  <?php endif; ?>

  <!-- دعوت متقابل -->
  <div style="margin-top:34px;text-align:center;display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
    <a class="fr-btn ghost" href="<?= SITE_URL ?>/workshops.php">🛠 کارگاه‌های عملی روانشناسی ←</a>
    <a class="fr-btn ghost tone-course" href="<?= SITE_URL ?>/courses.php">📚 دوره‌های آموزشی خودشناسی ←</a>
  </div>
</div>
<?php public_footer(); ?>
