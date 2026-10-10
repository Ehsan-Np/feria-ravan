<?php
/**
 * ============================================================================
 *  کارت‌های مشترک پنل کاربری (نسخهٔ ۲٫۰)
 *  ─ fr_panel_test_card(): کارت حرفه‌ای تست روانشناسی در پنل (کاور گرادیان + متا + CTA)
 *  ─ fr_panel_event_card(): کارت رویداد (کارگاه/دوره) با وضعیت ثبت‌نام کاربر
 *  قبلاً یک کلوژر محلی داخل panel/tests.php بود؛ حالا بین داشبورد و صفحه‌ی
 *  تست‌های روانشناسی به‌اشتراک گذاشته می‌شود تا همه‌جا یک طراحی واحد دیده شود.
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/seo.php';          // fr_test_url / fr_event_url

/**
 * کارت حرفه‌ای تست روانشناسی در پنل کاربری — هم‌خانواده با کارت‌های عمومی سایت (v1.8)
 * @param string $mode 'start' = دکمهٔ شروع (پنل) | 'view' = مشاهدهٔ عمومی
 */
function fr_panel_test_card(array $t, string $mode = 'start'): void
{
    $ico   = fr_test_icon($t);
    $isMgr = (string)($t['audience'] ?? 'general') === 'manager';
    $grads = [
        'disc'     => ['#6366f1', '#3e5f9e'],
        'ysq'      => ['#3e5f9e', '#c084fc'],
        'neo'      => ['#3b82f6', '#60a5fa'],
        'screen'   => ['#0ea5e9', '#38bdf8'],
        'jobsat'   => ['#f59e0b', '#fbbf24'],
        'mslikert' => ['#7c5cf0', '#a78bfa'],
    ];
    $g    = $grads[(string)($t['engine'] ?? '')] ?? ['#7c5cf0', '#a78bfa'];
    $grad = $isMgr ? 'linear-gradient(135deg,#17122e,#3b2a7a)'
                   : 'linear-gradient(135deg,' . $g[0] . ',' . $g[1] . ')';
    $coverStyle = 'background:' . $grad;
    if (!empty($t['image'])) {
        $coverImg = str_starts_with($t['image'], 'http') ? $t['image'] : SITE_URL . '/' . ltrim($t['image'], '/');
        $coverStyle .= ';background-image:url(' . str_replace(["'", ')', '('], '', $coverImg) . ');background-size:cover;background-position:center';
    }
    $isNew = !empty($t['created_at']) && strtotime((string)$t['created_at']) > time() - 75 * 86400;
    $url   = $mode === 'start'
        ? fr_panel_url('test-start.php?id=' . (int)$t['id'])
        : fr_test_url($t);
    ?>
<article class="pc-card" style="margin:0">
  <a class="pc-cover" href="<?= e($url) ?>" style="<?= e($coverStyle) ?>" aria-label="<?= e($t['title']) ?>">
    <?php if (empty($t['image'])): ?><span class="pc-cover-ic" aria-hidden="true"><?= $ico ?></span><?php endif; ?>
    <span class="pc-badge <?= $isMgr ? 'manager' : '' ?>"><?= $isMgr ? '👑 ویژهٔ مدیران' : 'عمومی' ?></span>
    <?php if ($isNew): ?><span class="pc-badge new">✨ جدید</span><?php endif; ?>
    <?php $pcDeal = fr_price_deal((int)$t['price_full'], isset($t['price_sale']) ? (int)$t['price_sale'] : null); ?>
    <?php if ($pcDeal['has_sale']): ?><span class="pc-badge off">٪ <?= fa_num($pcDeal['percent']) ?> تخفیف</span><?php endif; ?>
  </a>
  <div class="pc-body">
    <span class="pc-kind"><?= e(fr_test_kind_fa($t)) ?></span>
    <h3 class="pc-title"><a href="<?= e($url) ?>"><?= e($t['title']) ?></a></h3>
    <p class="pc-desc"><?= e($t['short_desc']) ?></p>
    <div class="pc-meta">
      <span>📝 <?= fa_num($t['question_count']) ?> سؤال</span>
      <span>⏱ <?= fa_num($t['est_minutes']) ?> دقیقه</span>
      <span><?php if ((int)$t['price_full'] <= 0): ?>🎁 کاملاً رایگان<?php elseif ($pcDeal['has_sale']): ?>⭐ ویژه: <del style="opacity:.65"><?= toman($pcDeal['list']) ?></del> <b style="color:#b91c1c"><?= toman($pcDeal['final']) ?></b><?php else: ?>⭐ ویژه: <?= toman((int)$t['price_full']) ?><?php endif; ?></span>
    </div>
    <div class="pc-cta">
      <a class="fr-btn" href="<?= e($url) ?>"><?= $mode === 'start' ? 'انتخاب و شروع ←' : 'مشاهدهٔ تست روانشناسی ←' ?></a>
    </div>
  </div>
</article>
    <?php
}

/**
 * کارت رویداد در پنل (کارگاه/دوره) — بر پایهٔ کارت عمومی با نشان «ثبت‌نام کرده‌اید»
 * @param array $opts ['registered'=>bool, 'cta'=>string, 'link'=>string]
 */
function fr_panel_event_card(array $ev, array $opts = []): void
{
    $seats = null;
    if (function_exists('ev_seats_left')) { try { $seats = ev_seats_left($ev); } catch (Throwable $e) {} }
    fr_event_pc_card($ev, $seats, $opts);
}
