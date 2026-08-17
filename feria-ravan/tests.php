<?php
/**
 * ============================================================================
 *  آرشیو عمومی تست‌های روانشناسی — بدون نیاز به ورود (نسخهٔ ۱٫۸)
 *  ─ تب‌های مخاطب (همه / عمومی / ویژهٔ مدیران) + نمایش شبکه‌ای|فهرستی
 *  ─ کارت حرفه‌ای با کاور، نشان مخاطب، متا و قیمت نسخهٔ ویژه
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
fr_bootstrap();

/* فیلتر مخاطب — معتبرسازی سفت */
$aud = (string)($_GET['a'] ?? 'all');
if (!in_array($aud, ['all', 'general', 'manager'], true)) $aud = 'all';

try {
    if ($aud === 'all') {
        $rows = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' ORDER BY sort_order");
    } else {
        $rows = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' AND audience=? ORDER BY sort_order", [$aud]);
    }
    $cntG = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active' AND audience='general'");
    $cntM = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='active' AND audience='manager'");
} catch (Throwable $e) {
    $rows = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' ORDER BY sort_order");
    $cntG = count($rows); $cntM = 0;
}

$hTitle = trim((string)DB::setting('tests_hero_title', '')) !== ''
        ? (string)DB::setting('tests_hero_title', '') : 'تست‌های روانشناسی فریا';
$hDesc  = trim((string)DB::setting('tests_hero_desc', '')) !== ''
        ? (string)DB::setting('tests_hero_desc', '')
        : 'مجموعهٔ کامل تست‌های روانشناسی علمی فریا را اینجا پیدا می‌کنید: از نقشهٔ هوش‌های چندگانه و هوش هیجانی تا شخصیت‌شناسی، طرحواره‌ها و غربالگرهای بالینی. هر تست روانشناسی با تحلیل رایگان شروع می‌شود و نسخهٔ ویژه‌اش واکاوی عمیق و پژوهش‌محور می‌دهد.';

public_header(['active' => 'tests',
               'canonical' => SITE_URL . '/tests.php' . ($aud !== 'all' ? '?a=' . $aud : ''),
               'desc' => $hDesc], 'تست‌های روانشناسی');
?>
<!-- هیروی آرشیو تست‌های روانشناسی (۱٫۸) -->
<section class="pb-hero">
  <span class="pb-hero-ic" aria-hidden="true">🧠</span>
  <div class="pb-hero-in">
    <h1><?= e($hTitle) ?></h1>
    <p><?= e($hDesc) ?></p>
  </div>
</section>

<div class="pb-wrap">
  <!-- تب‌های مخاطب -->
  <nav class="pc-tabs" aria-label="فیلتر مخاطب تست‌های روانشناسی">
    <a href="<?= SITE_URL ?>/tests.php" class="<?= $aud === 'all' ? 'on' : '' ?>">همه (<?= fa_num($cntG + $cntM) ?>)</a>
    <a href="<?= SITE_URL ?>/tests.php?a=general" class="<?= $aud === 'general' ? 'on' : '' ?>">👥 عمومی (<?= fa_num($cntG) ?>)</a>
    <a href="<?= SITE_URL ?>/tests.php?a=manager" class="<?= $aud === 'manager' ? 'on' : '' ?>">👑 ویژهٔ مدیران (<?= fa_num($cntM) ?>)</a>
  </nav>

  <?php if (!$rows): ?>
  <div class="pb-empty"><span class="ic">🧠</span>فعلاً تست روانشناسی فعالی در این بخش ثبت نشده است؛ به‌زودی تست‌های روانشناسی تازه می‌آیند.</div>
  <?php else: ?>
    <?php fr_pc_toolbar('tests-archive', count($rows), 'تست روانشناسی'); ?>
    <?php foreach ($rows as $t): fr_test_pc_card($t); endforeach; ?>
    <?php fr_pc_toolbar_end(); ?>
  <?php endif; ?>

  <!-- دعوت متقابل -->
  <div style="margin-top:34px;text-align:center;display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
    <a class="fr-btn ghost" href="<?= SITE_URL ?>/workshops.php">🛠 کارگاه‌های عملی ←</a>
    <a class="fr-btn ghost tone-course" href="<?= SITE_URL ?>/courses.php">📚 دوره‌های آموزشی ←</a>
  </div>
</div>
<?php public_footer(); ?>
