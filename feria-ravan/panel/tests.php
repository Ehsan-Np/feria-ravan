<?php
/**
 * =============================================================================
 *  فهرست تست‌های روانشناسی — دو بخش کاملاً جدا از هم (نسخهٔ ۱٫۸)
 *  ۱) تست‌های روانشناسی عمومی   ۲) تست‌های روانشناسی ویژهٔ مدیران و کسب‌وکار
 *  ─ کارت حرفه‌ای pc-card + نوار ابزار نمایش (شبکه‌ای/فهرستی با حافظهٔ کاربر)
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/panel-cards.php';   // نسخهٔ ۲٫۰: کارت مشترک پنل
fr_bootstrap();

$u = require_user();

// ستون audience ممکن است در نصب‌های خیلی قدیمی نباشد → سازگار
$groups = [];
try {
    foreach (['general','manager'] as $a) {
        $groups[$a] = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' AND audience=? ORDER BY sort_order", [$a]);
    }
} catch (Throwable $e) {
    $all = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' ORDER BY sort_order");
    $groups = ['general' => $all, 'manager' => []];
}

// نسخهٔ ۲٫۰: کارت تست روانشناسی پنل به includes/panel-cards.php منتقل شد (یک طراحی واحد در همهٔ پنل)
$pcCard = fn(array $t) => fr_panel_test_card($t);

// نوار ابزار محلی پنل (نسخهٔ سبک fr_pc_toolbar بدون لینک خارجی)
$pcBar = function(string $id, int $count, string $unit): void {
    ?>
<div class="pc-toolbar">
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
<!-- ── بخش اول: تست‌های روانشناسی عمومی ─────────────────────────── -->
<div class="fr-card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
  <div style="width:44px;height:44px;border-radius:14px;background:var(--p1);display:flex;align-items:center;justify-content:center;font-size:22px">👥</div>
  <div style="flex:1;min-width:220px">
    <b style="font-size:16px">تست‌های روانشناسی عمومی</b>
    <div style="font-size:12.5px;color:var(--muted);margin-top:3px;line-height:1.9">
      تست‌های روانشناسی استاندارد برای همه؛ نقطهٔ شروع خودشناسی و رشد فردی — با تحلیل روان، عمیق و قابل‌اعتماد.
    </div>
  </div>
  <span class="fr-chip purple"><?= fa_num(count($groups['general'])) ?> تست روانشناسی</span>
</div>
<?php if (!$groups['general']): ?>
<div class="fr-card" style="margin:0"><p class="fr-alert info" style="margin:0">فعلاً تست روانشناسی فعالی در این بخش نیست.</p></div>
<?php else: $pcBar('panel-tests-g', count($groups['general']), 'تست روانشناسی'); ?>
  <?php foreach ($groups['general'] as $t) $pcCard($t); ?>
</div>
<?php endif; ?>

<!-- ── بخش دوم: تست‌های روانشناسی ویژهٔ مدیران ───────────────────── -->
<div class="fr-card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:linear-gradient(135deg,#eef4ff,#e7edff);border-color:#dfe8ff">
  <div style="width:44px;height:44px;border-radius:14px;background:#dbe7ff;display:flex;align-items:center;justify-content:center;font-size:22px">💼</div>
  <div style="flex:1;min-width:220px">
    <b style="font-size:16px">تست‌های روانشناسی ویژهٔ مدیران و کسب‌وکار</b>
    <div style="font-size:12.5px;color:var(--muted);margin-top:3px;line-height:1.9">
      طراحی‌شده با زبان و تحلیل مخصوص صاحبان کسب‌وکار، مدیران و تیم‌سازها؛ تمرکزشان روی تصمیم‌گیری،
      رهبری، تعامل با تیم و فشارهای مدیریتی است — کاملاً جدا از نسخه‌های عمومی.
    </div>
  </div>
  <span class="fr-chip purple"><?= fa_num(count($groups['manager'])) ?> تست روانشناسی</span>
</div>
<?php if (!$groups['manager']): ?>
<div class="fr-card" style="margin:0"><p class="fr-alert info" style="margin:0">فعلاً تست روانشناسی فعالی در این بخش نیست.</p></div>
<?php else: $pcBar('panel-tests-m', count($groups['manager']), 'تست روانشناسی'); ?>
  <?php foreach ($groups['manager'] as $t) $pcCard($t); ?>
</div>
<?php endif; ?>
<?php panel_layout_end(); render_footer(); ?>
