<?php
/**
 * ============================================================================
 *  صفحهٔ تکی عمومی رویداد (کارگاه یا دوره) — بدون نیاز به ورود (نسخهٔ ۱٫۴)
 *  ─ کاور بزرگ، باکس ثبت‌نام چسبان، محتوای HTML امن‌شده، رویدادهای مرتبط
 *  ─ دکمهٔ ثبت‌نام → پنل کاربر (اگر مهمان باشد با بازگشت به همین صفحه وارد می‌شود)
 *  استفاده: workshop.php و course.php با تنظیم $PUBLIC_KIND پیش از include
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/public-layout.php';
require_once __DIR__ . '/sanitize.php';
require_once __DIR__ . '/event-pricing.php';
require_once __DIR__ . '/reviews-ui.php';
require_once __DIR__ . '/../core/Kavenegar.php';
require_once __DIR__ . '/../core/Zarinpal.php';
fr_bootstrap();

$kind     = $PUBLIC_KIND === 'course' ? 'course' : 'workshop';
$pageName = $kind === 'course' ? 'course' : 'workshop';
$kindFa   = $kind === 'course' ? 'دوره' : 'کارگاه';

/* پیدا کردن رویداد با اسلاگ یا شناسه */
$ev = null;
if (($s = trim((string)($_GET['s'] ?? ''))) !== '') {
    $ev = DB::one('SELECT * FROM ' . DB::t('events') . " WHERE kind=? AND status='active' AND slug=?", [$kind, $s]);
} elseif (($id = (int)($_GET['id'] ?? 0)) > 0) {
    $ev = DB::one('SELECT * FROM ' . DB::t('events') . " WHERE kind=? AND status='active' AND id=?", [$kind, $id]);
    // هدایت به نشانی زیبای اسلاگی برای خوانایی/سئو (۱٫۷)
    if ($ev && $ev['slug']) redirect(fr_event_url($ev));
}

if (!$ev) {
    http_response_code(404);
    public_header(['active'=>$kind . 's', 'noindex'=>true, 'tone'=>$kind === 'course' ? 'tone-course' : ''], 'یافت نشد');
    echo '<div class="pb-wrap"><div class="pb-empty"><span class="ic">🔍</span>' .
         'این ' . $kindFa . ' یافت نشد یا غیرفعال شده است.<br><br>' .
         '<a class="fr-btn sm" href="' . SITE_URL . '/' . $pageName . 's.php">بازگشت به فهرست ' . $kindFa . '‌ها</a></div></div>';
    public_footer(); exit;
}

/* شمارش بازدید — یک‌بار در هر نشست برای هر رویداد */
$vkey = 'ev_view_' . (int)$ev['id'];
if (empty($_SESSION[$vkey])) {
    $_SESSION[$vkey] = 1;
    DB::run('UPDATE ' . DB::t('events') . ' SET views=views+1 WHERE id=?', [(int)$ev['id']]);
}

$seatsLeft  = ev_seats_left($ev);
$full       = $seatsLeft !== null && $seatsLeft <= 0;
$past       = $ev['starts_at'] && strtotime($ev['starts_at']) < time();
$closed     = !(int)$ev['reg_open'] || $full || $past;
$cover      = fr_event_cover($ev);
// نسخهٔ ۱٫۷: نشانی کانونیکالِ زیبای کوتاه
$pageUrl    = fr_event_url($ev);
$isCourse   = $kind === 'course';
$emoji      = $isCourse ? '📚' : '🛠';

/* نسخهٔ ۱٫۷: متادیتای سئو از فیلدهای Yoast-like خود رویداد */
$seoTitle = trim((string)($ev['seo_title'] ?? '')) !== '' ? (string)$ev['seo_title'] : (string)$ev['title'];
$seoDesc  = trim((string)($ev['seo_desc'] ?? '')) !== '' ? (string)$ev['seo_desc']
          : (string)($ev['short_desc'] ?: mb_substr(trim(strip_tags((string)$ev['description'])), 0, 150));

/* کاربر جاری — دکمهٔ ثبت‌نام برای مهمان به ورود با بازگشت می‌رود */
$me      = current_user();
$regUrl  = fr_panel_url('event-register.php?id=' . (int)$ev['id']);
$myReg   = $me ? DB::val('SELECT id FROM ' . DB::t('event_registrations') .
                         " WHERE event_id=? AND user_id=? AND status='paid'", [(int)$ev['id'], (int)$me['id']]) : null;

/* رویدادهای مرتبط (همان نوع، به‌جز خودش، آینده‌نگر) */
$rel = DB::all('SELECT e.*, (SELECT COALESCE(SUM(1 + COALESCE(r.companion_count,0)),0)
                  FROM ' . DB::t('event_registrations') . ' r
                 WHERE r.event_id=e.id AND r.status=\'paid\') AS seats
                FROM ' . DB::t('events') . " e
                WHERE e.kind=? AND e.status='active' AND e.id<>?
                ORDER BY (e.starts_at IS NULL), (e.starts_at >= NOW()) DESC, e.starts_at ASC LIMIT 3",
               [$kind, (int)$ev['id']]);

public_header([
    'active'    => $kind . 's',
    'tone'      => $isCourse ? 'tone-course' : '',
    'desc'      => $seoDesc,
    'kw'        => (string)($ev['seo_kw'] ?? ''),
    'canonical' => $pageUrl,
    'noindex'   => !empty($ev['seo_noindex']),
    'image'     => $cover,
    'type'      => 'article',
    'jsonld'    => fr_event_jsonld($ev, $pageUrl),
], $seoTitle);
?>
<!-- مسیر راهنما -->
<nav class="pb-crumbs" aria-label="مسیر صفحه">
  <a href="<?= SITE_URL ?>/">خانه</a> ←
  <a href="<?= SITE_URL ?>/<?= $pageName ?>s.php"><?= $kindFa ?>‌ها</a> ←
  <b><?= e($ev['title']) ?></b>
</nav>

<div class="pb-wrap" style="padding-top:16px">
<div class="ev-single">

  <!-- ═══════════ ستون اصلی: کاور + محتوا ═══════════ -->
  <div class="ev-main">
    <div class="ev-hero-cover">
      <?php if ($cover): ?>
      <img src="<?= e($cover) ?>" alt="<?= e($ev['title']) ?>" fetchpriority="high">
      <?php else: ?>
      <span class="ev-cover-ph" aria-hidden="true"><i><?= $emoji ?></i></span>
      <?php endif; ?>
      <span class="hero-grad"></span>
      <div class="ev-hero-title">
        <h1><?= e($ev['title']) ?></h1>
        <div class="ev-hero-chips">
          <?php $peDeal = fr_price_deal((int)$ev['price'], isset($ev['sale_price']) ? (int)$ev['sale_price'] : null); ?>
          <?php if ($peDeal['has_sale']): ?><span class="ev-chipline" style="background:#fef2f2;color:#b91c1c">٪ <?= fa_num($peDeal['percent']) ?> تخفیف قیمت‌گذاری</span><?php endif; ?>
          <?php if ($ev['ws_discount_type'] !== 'none'): ?><span class="ev-chipline">٪ تخفیف ویژهٔ این رویداد</span><?php endif; ?>
          <?php if ((int)$ev['companion_enabled']): ?><span class="ev-chipline">👥 امکان ثبت‌نام همراه</span><?php endif; ?>
          <?php if (!(int)$ev['price']): ?><span class="ev-chipline">رایگان</span><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="ev-pad">
      <?php if ($ev['short_desc']): ?>
      <p style="font-size:15px;color:#38446a;line-height:2.1;border-right:4px solid var(--tone);padding-right:14px;font-weight:600;background:var(--tone-soft);border-radius:12px;padding-top:10px;padding-bottom:10px">
        <?= e($ev['short_desc']) ?>
      </p>
      <?php endif; ?>

      <div class="ev-content">
        <?= fr_sanitize_html((string)($ev['description'] ?? '')) /* خروجی امن: پاک‌سازی دومرحله‌ای */ ?>
      </div>
    </div>
  </div>

  <!-- ═══════════ ستون کناری: باکس ثبت‌نام ═══════════ -->
  <aside class="ev-side">
    <div class="ev-box">
      <div class="pr">
        <?php if ((int)$ev['price'] > 0): ?>
        <?php if ($peDeal['has_sale']): ?><del style="color:#94a3b8;font-size:14px;font-weight:500;display:block"><?= toman($peDeal['list']) ?></del>
        <b style="color:#b91c1c"><?= toman($peDeal['final']) ?></b><small><?= $isCourse ? 'شهریهٔ دوره' : 'شهریهٔ کارگاه' ?> — با <?= fa_num($peDeal['percent']) ?>٪ تخفیف</small>
        <?php else: ?>
        <b><?= toman((int)$ev['price']) ?></b><small><?= $isCourse ? 'شهریهٔ دوره' : 'شهریهٔ کارگاه' ?></small>
        <?php endif; ?>
        <?php else: ?>
        <b style="color:var(--c-accent)">رایگان</b>
        <?php endif; ?>
        <?php if ($ev['ws_discount_type'] !== 'none'): ?><span class="st">+ تخفیف ویژه در فاکتور</span><?php endif; ?>
      </div>

      <ul class="ev-rows">
        <?php if ($ev['teacher']): ?><li><span class="ic">👤</span><span>مدرس: <b><?= e($ev['teacher']) ?></b></span></li><?php endif; ?>
        <?php if ($ev['starts_at']): ?>
        <li><span class="ic">🗓</span><span><?= $isCourse ? 'شروع دوره' : 'تاریخ برگزاری' ?>: <b><?= fa_num(jdate('l j F Y', strtotime($ev['starts_at']))) ?></b>
          <br><small style="color:var(--muted)">ساعت <?= fa_num(jdate('H:i', strtotime($ev['starts_at']))) ?><?= $ev['ends_at'] ? ' تا ' . fa_num(jdate('H:i', strtotime($ev['ends_at']))) : '' ?></small></span></li>
        <?php endif; ?>
        <?php if ($ev['schedule_text']): ?><li><span class="ic">🕐</span><span>برنامهٔ زمانی: <b><?= e($ev['schedule_text']) ?></b></span></li><?php endif; ?>
        <?php if ($ev['location']): ?><li><span class="ic">📍</span><span><?= e($ev['location']) ?></span></li><?php endif; ?>
        <?php if ((int)$ev['companion_enabled']): ?>
        <li><span class="ic">👥</span><span>ثبت‌نام همراه فعال است
          <?= $ev['companion_max'] ? ' (تا ' . fa_num((int)$ev['companion_max']) . ' نفر)' : '' ?> —
          هر همراه <b><?= toman(ev_companion_unit($ev)) ?></b></span></li>
        <?php endif; ?>
        <li><span class="ic">👁</span><span><?= fa_num((int)$ev['views'] + 1) ?> بازدید از این صفحه</span></li>
      </ul>

      <?php
      /* نسخهٔ ۱٫۷: دکمه‌های مسیریاب (گوگل‌مپ/نشان/بلد) — فقط نقشه‌هایی که لینک دارند */
      $maps = [];
      if (trim((string)($ev['map_google'] ?? '')) !== '')  $maps[] = ['🧭','گوگل مپ',   $ev['map_google'],  '#4285F4'];
      if (trim((string)($ev['map_neshan'] ?? '')) !== '')  $maps[] = ['🗺','نشان',      $ev['map_neshan'],  '#0aa2ff'];
      if (trim((string)($ev['map_balad'] ?? '')) !== '')   $maps[] = ['📍','بلد',       $ev['map_balad'],   '#e6007e'];
      if ($maps): ?>
      <div class="ev-maps">
        <small style="color:var(--muted);font-size:11.5px">مسیریابی محل برگزاری با:</small>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <?php foreach ($maps as [$mi,$ml,$mu,$mc]): ?>
          <a class="ev-mapbtn" href="<?= e($mu) ?>" target="_blank" rel="noopener nofollow" style="--mc:<?= e($mc) ?>">
            <span><?= $mi ?></span> <?= e($ml) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($ev['capacity'] !== null): ?>
      <div class="ev-seats">
        <?php
        $cap  = (int)$ev['capacity'];
        $used = max(0, $cap - max(0, (int)$seatsLeft));
        $pct  = $cap > 0 ? min(100, (int)round($used * 100 / $cap)) : 0;
        ?>
        <small style="color:var(--muted)">
          <?= $full ? '⛔ ظرفیت تکمیل شد' : '🪑 ' . fa_num(max(0, (int)$seatsLeft)) . ' صندلی از ' . fa_num($cap) . ' آزاد است' ?>
        </small>
        <div class="fr-progress"><i style="width:<?= $pct ?>%;<?= $pct >= 85 ? 'background:linear-gradient(90deg,#e0393f,#f5a623)' : '' ?>"></i></div>
      </div>
      <?php endif; ?>

      <?php if ($myReg): ?>
        <div class="fr-alert ok" style="margin:0 0 4px">✅ شما در این <?= $kindFa ?> ثبت‌نام کرده‌اید.</div>
        <a class="fr-btn ghost" style="width:100%;justify-content:center" href="<?= SITE_URL ?><?= $ev['kind'] === 'course' ? '/panel/courses.php' : '/panel/workshops.php' ?>">مشاهده در پنل من</a>
      <?php elseif ($closed): ?>
        <div class="fr-alert warn" style="margin:0">
          <?= $past ? ('مهلت ثبت‌نام این ' . $kindFa . ' به پایان رسیده است.') : ($full ? ('ظرفیت این ' . $kindFa . ' تکمیل شده است.') : ('ثبت‌نام این ' . $kindFa . ' فعلاً بسته است.')) ?>
        </div>
      <?php else: ?>
        <a class="fr-btn <?= (int)$ev['price'] ? '' : 'ev-cta-free' ?>" style="width:100%;justify-content:center;font-size:15px" href="<?= e($regUrl) ?>">
          <?= (int)$ev['price'] ? 'ثبت‌نام و پرداخت آنلاین' : 'ثبت‌نام رایگان' ?> ←
        </a>
        <?php if (!$me): ?>
        <p class="ev-note">برای ثبت‌نام وارد حساب می‌شوید و به همین صفحه برمی‌گردید؛<br>نیازی به ورود مجدد اطلاعات نیست 🦢</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if ($ev['teacher']): ?>
    <div class="ev-box">
      <div class="ev-teacher">
        <span class="av">🎓</span>
        <div><b><?= e($ev['teacher']) ?></b><small>مدرس این <?= $kindFa ?></small></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="ev-box">
      <b style="font-size:13.5px">اشتراک‌گذاری این <?= $kindFa ?></b>
      <div class="ev-share" style="margin-top:10px">
        <input class="fr-input" id="evLink" type="text" readonly value="<?= e($pageUrl) ?>" aria-label="نشانی صفحه">
        <button class="fr-btn sm ghost" type="button" data-copy="evLink">📋 کپی</button>
      </div>
    </div>
  </aside>
</div>

<?php fr_reviews_section($kind, (int)$ev['id']); ?>

<?php if ($rel): ?>
<h2 class="ev-rel-title"><?= $kindFa ?>‌های دیگر</h2>
<div class="ev-grid">
  <?php foreach ($rel as $r): fr_event_card($r, ev_seats_left($r), $pageName); endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php public_footer(); ?>
