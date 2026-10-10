<?php
/**
 * =============================================================================
 *  فهرست تست‌های روانشناسی پنل کاربری — تفکیک سه‌گانه روان‌سنجی (نسخهٔ ۳٫۳)
 *  ۱) بالینی و سلامت روان (clinical)
 *  ۲) شخصیت و خودشناسی (personality)
 *  ۳) هوش، استعداد و پیشرفت شغلی (intelligence)
 *  ─ کارت حرفه‌ای pc-card + نوار ابزار نمایش (شبکه‌ای/فهرستی) + تب‌های تفکیک
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/panel-cards.php';
fr_bootstrap();

$u = require_user();

$currentCat = (string)($_GET['cat'] ?? 'all');
if (!in_array($currentCat, ['all', 'clinical', 'personality', 'intelligence'], true)) {
    $currentCat = 'all';
}

$allTests = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' ORDER BY sort_order");

$counts = [
    'all'          => count($allTests),
    'clinical'     => 0,
    'personality'  => 0,
    'intelligence' => 0,
];

$byCat = [
    'clinical'     => [],
    'personality'  => [],
    'intelligence' => [],
];

foreach ($allTests as $t) {
    $c = $t['category'] ?? 'personality';
    if (!isset($byCat[$c])) $c = 'personality';
    $byCat[$c][] = $t;
    $counts[$c]++;
}

$categoryMeta = [
    'clinical' => [
        'title' => 'آزمون‌های بالینی و سلامت روان',
        'icon'  => '🩺',
        'badge' => 'تشخیصی و درمانی',
        'bg'    => 'linear-gradient(135deg,#fff1f2,#ffe4e6)',
        'color' => '#be123c',
        'desc'  => 'آزمون‌های استاندارد تشخیصی جهت سنجش اختلالات خلقی، اضطراب، طرحواره‌ها (تله‌های زندگی) و نیمرخ شخصیتی MMPI زیر نظر متخصصین سلامت روان.',
    ],
    'personality' => [
        'title' => 'آزمون‌های شخصیت‌شناسی و خودشناسی',
        'icon'  => '🎭',
        'badge' => 'رشد و روابط فردی',
        'bg'    => 'linear-gradient(135deg,#f5f3ff,#ede9fe)',
        'color' => '#6d28d9',
        'desc'  => 'شناسایی لایه‌های رفتاری و تیپ‌های شخصیتی با آزمون‌های بین‌المللی MBTI (۱۶ تیپ)، مدل ۵ عاملی نئو (NEO) و مدل رفتارشناسی کاربردی DISC.',
    ],
    'intelligence' => [
        'title' => 'آزمون‌های هوش، استعداد و پیشرفت شغلی',
        'icon'  => '💡',
        'badge' => 'هدایت تحصیلی و شغلی',
        'bg'    => 'linear-gradient(135deg,#f0fdf4,#dcfce7)',
        'color' => '#15803d',
        'desc'  => 'سنجش جامع هوش عمومی ریون (Raven IQ)، شاخص‌های وکسلر (WAIS)، هوش هیجانی بار-آن، رغبت‌سنجی شغلی هالند و هوش‌های چندگانه گاردنر.',
    ],
];

$pcCard = fn(array $t) => fr_panel_test_card($t);

$pcBar = function(string $id, int $count, string $unit): void {
    ?>
<div class="pc-toolbar" style="margin:14px 0">
  <span class="pc-count"><?= fa_num($count) ?> <?= e($unit) ?></span>
  <span class="pc-sp"></span>
  <span class="pc-view-tgl" role="group" aria-label="حالت نمایش فهرست">
    <button type="button" class="on" data-pc-view="grid" data-pc-for="<?= e($id) ?>">▦ شبکه‌ای</button>
    <button type="button" data-pc-view="list" data-pc-for="<?= e($id) ?>">☰ فهرستی</button>
  </span>
</div>
<div class="pc-canvas" data-pc-id="<?= e($id) ?>">
    <?php
};

render_header('تست‌های روانشناسی');
panel_layout($u, 'tests');
?>

<!-- تب‌های دسته‌بندی سه‌گانه روان‌سنجی -->
<div class="fr-card" style="padding:14px 18px;margin-bottom:18px">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="font-size:13px;font-weight:700;color:var(--muted);margin-left:6px">دسته‌بندی تخصصی:</span>
      <a href="?cat=all" class="fr-chip <?= $currentCat==='all'?'purple':'info' ?>" style="text-decoration:none;font-weight:600">
        همه آزمون‌ها (<?= fa_num($counts['all']) ?>)
      </a>
      <a href="?cat=clinical" class="fr-chip <?= $currentCat==='clinical'?'purple':'info' ?>" style="text-decoration:none;font-weight:600">
        🩺 بالینی و سلامت روان (<?= fa_num($counts['clinical']) ?>)
      </a>
      <a href="?cat=personality" class="fr-chip <?= $currentCat==='personality'?'purple':'info' ?>" style="text-decoration:none;font-weight:600">
        🎭 شخصیت و خودشناسی (<?= fa_num($counts['personality']) ?>)
      </a>
      <a href="?cat=intelligence" class="fr-chip <?= $currentCat==='intelligence'?'purple':'info' ?>" style="text-decoration:none;font-weight:600">
        💡 هوش و پیشرفت شغلی (<?= fa_num($counts['intelligence']) ?>)
      </a>
    </div>
  </div>
</div>

<?php
$renderCategories = $currentCat === 'all'
    ? ['clinical', 'personality', 'intelligence']
    : [$currentCat];

foreach ($renderCategories as $catKey):
    $meta = $categoryMeta[$catKey];
    $testsInCat = $byCat[$catKey];
?>
<div class="fr-card" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:<?= $meta['bg'] ?>;border:1px solid rgba(0,0,0,0.06);margin-top:20px">
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
  <span class="fr-chip" style="background:<?= $meta['color'] ?>;color:#fff;font-weight:700">
    <?= fa_num(count($testsInCat)) ?> تست تخصصی
  </span>
</div>

<?php if (!$testsInCat): ?>
  <div class="fr-card" style="margin:0"><p class="fr-alert info" style="margin:0">فعلاً تستی در این دسته‌بندی فعال نشده است.</p></div>
<?php else:
    $pcBar('panel-tests-' . $catKey, count($testsInCat), 'تست تخصصی');
    foreach ($testsInCat as $t) {
        $pcCard($t);
    }
    echo '</div>';
endif;
endforeach;
?>

<?php panel_layout_end(); render_footer(); ?>
