<?php
/**
 * ============================================================================
 *  فریا روان — صفحهٔ اصلی (نسخهٔ ۱٫۸)
 *  ─ مهمان: هیرو + نوار آماری + فهرست کارت حرفه‌ای تست‌های روانشناسی (شبکه‌ای|فهرستی)
 *          + سکشن لوکس ویژهٔ مدیران + پیش‌نمایش کارگاه/دوره/مشاوره
 *  ─ واردشده: خانهٔ شخصی سه‌ستونه + فهرست کامل تست‌های روانشناسی با نوار ابزار
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
require_once __DIR__ . '/includes/consult.php';
require_once __DIR__ . '/core/Kavenegar.php';
require_once __DIR__ . '/core/Zarinpal.php';
require_once __DIR__ . '/core/Analyzer.php';
require_once __DIR__ . '/core/PageBuilder.php';
fr_bootstrap();

/* ---------------------------------------------------------------------------
 | برگه صفحه اصلی سفارشی طراحی‌شده با صفحه‌ساز دیداری فریا روان (نگارش ۳٫۶٫۰)
 * -------------------------------------------------------------------------*/
if (empty($_GET['classic']) && empty($_GET['default_home'])) {
    PageBuilder::ensureSchema();
    $customHome = PageBuilder::getHomepage();
    if ($customHome && !empty($customHome['active'])) {
        PageBuilder::renderPage($customHome, true);
        exit;
    }
}

/* ---------------------------------------------------------------------------
 | مسیریابی هوشمند پنل کاربری در صورت تنظیم اسلاگ اختصاصی (جلوگیری قطعی از ۴۰۴)
 * -------------------------------------------------------------------------*/
$pSlug = function_exists('fr_panel_slug') ? fr_panel_slug() : 'panel';
if ($pSlug !== 'panel') {
    $reqPath = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($reqPath === $pSlug || str_starts_with($reqPath, $pSlug . '/')) {
        $sub = substr($reqPath, strlen($pSlug));
        $sub = trim((string)$sub, '/');
        if ($sub === '' || $sub === false) $sub = 'index.php';
        $targetFile = __DIR__ . '/panel/' . $sub;
        if (file_exists($targetFile) && is_file($targetFile)) {
            require $targetFile;
            exit;
        }
    }
}

/* تست‌های روانشناسی — دو گروه کاملاً جدا: عمومی و ویژهٔ مدیران */
try {
    $testsG = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' AND audience='general' ORDER BY sort_order");
    $testsM = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' AND audience='manager' ORDER BY sort_order");
} catch (Throwable $e) {
    $testsG = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' ORDER BY sort_order");
    $testsM = [];
}
$testIco = ['disc'=>'🎭','ysq'=>'🧩','neo'=>'🌟','screen'=>'🩺','jobsat'=>'💼','mslikert'=>'🧠'];
$user  = current_user();
$site  = (string)DB::setting('site_title', 'فریا روان');

/* پیش‌نمایش کارگاه‌ها و دوره‌ها (هر کدام ۳ موردِ نزدیک) */
$peek = function (string $kind): array {
    return DB::all("SELECT e.*, (SELECT COALESCE(SUM(1 + COALESCE(r.companion_count,0)),0)
                       FROM " . DB::t('event_registrations') . " r WHERE r.event_id=e.id AND r.status='paid') AS seats
                    FROM " . DB::t('events') . " e
                    WHERE e.kind=? AND e.status='active' AND (e.starts_at IS NULL OR e.starts_at >= NOW())
                    ORDER BY e.starts_at LIMIT 3", [$kind]);
};
$workshops = $peek('workshop');
$courses   = $peek('course');
$services  = consult_services_catalog();

/* آمار واقعی برای نوار هیرو (۱٫۸) — فقط اعدادِ قطعیِ بازار */
$statTests = count($testsG) + count($testsM);
$statWS    = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('events') . " WHERE kind='workshop' AND status='active'");
$statCR    = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('events') . " WHERE kind='course' AND status='active'");
$statSV    = count($services);

/* ── کارت فشردهٔ یک رویداد برای ستون‌های خانهٔ شخصی ── */
$miniEv = function(array $ev): void {
    $url = fr_event_url($ev); ?>
    <a class="hm-item" href="<?= e($url) ?>">
      <span class="hm-item-ic"><?= $ev['kind'] === 'course' ? '📚' : '🛠' ?></span>
      <span class="hm-item-tx">
        <b><?= e($ev['title']) ?></b>
        <small><?= $ev['starts_at'] ? fa_num(jdate('j F Y - H:i', strtotime($ev['starts_at']))) : 'زمان‌بندی به‌زودی' ?>
          · <?= (int)$ev['price'] ? toman((int)$ev['price']) : 'رایگان' ?></small>
      </span>
      <span class="hm-item-go">←</span>
    </a>
    <?php
};
/* ── کارت فشردهٔ یک تست روانشناسی برای ستون‌های خانهٔ شخصی ── */
$miniTest = function(array $t): void {
    $url = fr_test_url($t); ?>
    <a class="hm-item" href="<?= e($url) ?>">
      <span class="hm-item-ic"><?= fr_test_icon($t) ?></span>
      <span class="hm-item-tx">
        <b><?= e($t['title']) ?></b>
        <?php $mDeal = fr_price_deal((int)$t['price_full'], isset($t['price_sale']) ? (int)$t['price_sale'] : null); ?>
        <small>📝 <?= fa_num($t['question_count']) ?> سؤال · <?php if (!$t['price_full']): ?>رایگان<?php elseif ($mDeal['has_sale']): ?>نسخهٔ ویژه: <del style="opacity:.65"><?= toman($mDeal['list']) ?></del> <b style="color:#b91c1c"><?= toman($mDeal['final']) ?></b> (٪<?= fa_num($mDeal['percent']) ?> تخفیف)<?php else: ?>نسخهٔ ویژه: <?= toman((int)$t['price_full']) ?><?php endif; ?></small>
      </span>
      <span class="hm-item-go">←</span>
    </a>
    <?php
};

public_header(['active' => 'home',
               'canonical' => SITE_URL . '/',
               'desc' => (string)DB::setting('seo_home_desc', DB::setting('site_slogan',''))], '');

/* ============================================================================
 |  بخش لوکس «ویژهٔ مدیران و کسب‌وکار» (مشترک بین مهمان/واردشده)
 * ==========================================================================*/
$premiumSection = function(array $testsM): void {
    if (!$testsM) return; ?>
<section class="pb-wrap prem-wrap">
  <div class="prem">
    <span class="prem-glow" aria-hidden="true"></span>
    <div class="prem-head">
      <span class="prem-badge">👑 کلاس رهبری فریا</span>
      <h2>تست‌های روانشناسی ویژهٔ مدیران و کسب‌وکار</h2>
      <p>
        اگر تیم و کسب‌وکار را به‌دست دارید، این‌ها دقیقاً برای شما طراحی شده‌اند:
        زبانِ مدیران، سؤال‌های دنیای واقعی سازمان و تحلیل‌هایی که بلافاصله می‌شود
        در تصمیم‌های روزانه به‌کار بست.
      </p>
    </div>
    <div class="prem-grid">
      <?php foreach ($testsM as $t): ?>
      <a class="prem-card" href="<?= e(fr_test_url($t)) ?>">
        <span class="prem-card-ic"><?= fr_test_icon($t) ?></span>
        <b><?= e($t['title']) ?></b>
        <p><?= e($t['short_desc']) ?></p>
        <span class="prem-meta">
          <span>📝 <?= fa_num($t['question_count']) ?> سؤال</span>
          <span>⏱ <?= fa_num($t['est_minutes']) ?> دقیقه</span>
        </span>
        <span class="prem-cta">شروع تست روانشناسی اختصاصی ←</span>
        <span class="prem-shine" aria-hidden="true"></span>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="prem-feats">
      <span>🏆 گزارش مدیریتی سطح بالا</span>
      <span>🧭 تمرکز بر رهبری و تصمیم‌گیری</span>
      <span>📉 قابل استفاده برای ارزیابی تیم</span>
    </div>
  </div>
</section>
<?php };

/* سکشن فهرست حرفه‌ای تست‌های روانشناسی عمومی (مشترک؛ تفاوت: حالت شبکه‌ای/فهرستی + نمایش همه) */
$testsShowcase = function(array $testsG) use ($user): void { ?>
<section id="tests" class="pb-wrap" style="padding-top:10px;padding-bottom:24px">
  <div class="pb-sec-title">
    <h2>تست‌های روانشناسی</h2>
    <span class="cnt"><?= fa_num(count($testsG)) ?> تست روانشناسی</span>
    <a class="pc-all-link" style="margin-right:auto" href="<?= SITE_URL ?>/tests.php">نمایش تمام گزینه‌ها ←</a>
  </div>
  <p class="pb-sec-note">
    هر تست روانشناسی با تحلیل رایگان شروع می‌شود؛ اگر عمیق‌تر خواستید، نسخهٔ ویژه واکاوی کامل، پژوهش‌محور و بومیِ ایران می‌دهد —
    چیدمان را هم دلخواه خودتان کنید (شبکه‌ای یا فهرستی).
  </p>
  <?php if (!$testsG): ?>
    <div class="pb-empty"><span class="ic">🧠</span>به‌زودی تست‌های روانشناسی تازه اینجا قرار می‌گیرند.</div>
  <?php else: ?>
    <?php fr_pc_toolbar($user ? 'home-tests-in' : 'home-tests-out', count($testsG), 'تست روانشناسی', SITE_URL . '/tests.php'); ?>
    <?php foreach ($testsG as $t): fr_test_pc_card($t); endforeach; ?>
    <?php fr_pc_toolbar_end(); ?>
  <?php endif; ?>
</section>
<?php };

/* سکشن فهرست حرفه‌ای کارگاه‌ها + دوره‌ها — همان طرح و چیدمان تست‌های روانشناسی (pc-card + نوار ابزار)
   مشترک بین مهمان و واردشده (مثل سکشن تست‌های روانشناسی) */
$eventsShowcase = function(array $workshops, array $courses) use ($user): void { ?>
<?php if ($workshops): ?>
<section class="pb-wrap" style="padding-top:16px;padding-bottom:24px">
  <div class="pb-sec-title">
    <h2>کارگاه‌های پیش‌رو</h2>
    <span class="cnt"><?= fa_num(count($workshops)) ?> کارگاه</span>
    <a class="pc-all-link" style="margin-right:auto" href="<?= SITE_URL ?>/workshops.php">نمایش تمام گزینه‌ها ←</a>
  </div>
  <p class="pb-sec-note">
    کارگاه‌های کوتاه‌مدت و عملی؛ چند ساعت تمرکز روی یک مهارت واقعی با تمرین‌هایی که همان‌جا انجام می‌دهید —
    چیدمان را هم دلخواه خودتان کنید (شبکه‌ای یا فهرستی).
  </p>
  <?php fr_pc_toolbar($user ? 'home-ws-in' : 'home-ws-out', count($workshops), 'کارگاه', SITE_URL . '/workshops.php'); ?>
  <?php foreach ($workshops as $ev): fr_event_pc_card($ev, ev_seats_left($ev)); endforeach; ?>
  <?php fr_pc_toolbar_end(); ?>
</section>
<?php endif; ?>
<?php if ($courses): ?>
<section class="pb-wrap" style="padding-top:6px;padding-bottom:24px">
  <div class="pb-sec-title">
    <h2>دوره‌های آموزشی</h2>
    <span class="cnt"><?= fa_num(count($courses)) ?> دوره</span>
    <a class="pc-all-link" style="margin-right:auto" href="<?= SITE_URL ?>/courses.php">نمایش تمام گزینه‌ها ←</a>
  </div>
  <p class="pb-sec-note">
    مسیرهای چندجلسه‌ای و قدم‌به‌قدم برای یادگیری عمیق؛ سرفصل شفاف، مدرس مشخص و پشتیبانی کامل —
    چیدمان را هم دلخواه خودتان کنید (شبکه‌ای یا فهرستی).
  </p>
  <?php fr_pc_toolbar($user ? 'home-cr-in' : 'home-cr-out', count($courses), 'دوره', SITE_URL . '/courses.php'); ?>
  <?php foreach ($courses as $ev): fr_event_pc_card($ev, ev_seats_left($ev)); endforeach; ?>
  <?php fr_pc_toolbar_end(); ?>
</section>
<?php endif; ?>
<?php };

/* ============================================================================
 |  حالت کاربر واردشده — خانهٔ شخصی
 * ==========================================================================*/
if ($user): ?>

<section class="pb-hero hm-hero">
  <span class="pb-hero-ic" aria-hidden="true">🦢</span>
  <div class="pb-hero-in">
    <h1>سلام <?= e($user['first_name'] ?: 'دوست') ?> عزیز، خوش آمدی 👋</h1>
    <p>این‌جا خانهٔ توست! از سه ستون زیر مستقیم سرِ کاری برو که دنبالش هستی؛ هر بخش سه موردِ پیش‌رو را نشانت می‌دهد و با «نمایش تمام گزینه‌ها» همۀ گزینه‌ها دمِ دستت است.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
      <a class="fr-btn white" href="<?= SITE_URL ?>/panel/">🏠 رفتن به پنل کاربری</a>
      <a class="fr-btn ghost" style="background:rgba(255,255,255,.18);color:#fff" href="<?= SITE_URL ?>/consult.php">🛋 رزرو مشاوره</a>
    </div>
  </div>
</section>

<!-- ── سه ستون اصلی: تست‌های روانشناسی | دوره‌ها | کارگاه‌ها ── -->
<section class="pb-wrap">
  <div class="hm3">
    <div class="hm-col">
      <div class="hm-col-head">
        <span class="hm-col-ic" style="background:linear-gradient(135deg,#eaf0f8,#ded4fb)">🧠</span>
        <div><b>تست‌های روانشناسی</b><small>خودت را بهتر بشناس</small></div>
      </div>
      <div class="hm-items">
        <?php if (!$testsG): ?><div class="hm-empty">به‌زودی تست‌های روانشناسی تازه می‌آیند ✨</div><?php endif; ?>
        <?php foreach (array_slice($testsG, 0, 3) as $t) $miniTest($t); ?>
      </div>
      <a class="hm-more" href="<?= SITE_URL ?>/tests.php">نمایش تمام گزینه‌ها ←</a>
    </div>
    <div class="hm-col">
      <div class="hm-col-head">
        <span class="hm-col-ic" style="background:linear-gradient(135deg,#d9fbef,#c1f2df)">📚</span>
        <div><b>دوره‌های آموزشی</b><small>یادگیری عمیق و چندجلسه‌ای</small></div>
      </div>
      <div class="hm-items">
        <?php if (!$courses): ?><div class="hm-empty">به‌زودی دوره‌های تازه می‌آیند ✨</div><?php endif; ?>
        <?php foreach ($courses as $ev) $miniEv($ev); ?>
      </div>
      <a class="hm-more" href="<?= SITE_URL ?>/courses.php">مشاهدهٔ همهٔ دوره‌ها ↓</a>
    </div>
    <div class="hm-col">
      <div class="hm-col-head">
        <span class="hm-col-ic" style="background:linear-gradient(135deg,#ffe9d9,#fdddc2)">🛠</span>
        <div><b>کارگاه‌های عملی</b><small>مهارتِ کاربردی در چند ساعت</small></div>
      </div>
      <div class="hm-items">
        <?php if (!$workshops): ?><div class="hm-empty">به‌زودی کارگاه‌های تازه می‌آیند ✨</div><?php endif; ?>
        <?php foreach ($workshops as $ev) $miniEv($ev); ?>
      </div>
      <a class="hm-more" href="<?= SITE_URL ?>/workshops.php">مشاهدهٔ همهٔ کارگاه‌ها ↓</a>
    </div>
  </div>
</section>

<?php $premiumSection($testsM); ?>

<!-- ── فهرست کامل تست‌های روانشناسی عمومی (کارت حرفه‌ای + نوار ابزار) ── -->
<?php $testsShowcase($testsG); ?>

<!-- ── فهرست کامل کارگاه‌ها و دوره‌ها (همان طرح کارت تست‌های روانشناسی) ── -->
<?php $eventsShowcase($workshops, $courses); ?>

<!-- ── دعوت به مشاوره ── -->
<?php if ($services): ?>
<section class="pb-wrap" style="padding-top:6px">
  <div class="bk-sum" style="display:flex;gap:24px;align-items:center;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
      <h3 style="font-size:19px;margin-bottom:8px">🛋 نیاز به گفت‌وگو دارید؟</h3>
      <p style="font-size:13.5px;line-height:2;opacity:.95;margin:0">
        <?= fa_num(count($services)) ?> خدمت مشاورهٔ تخصصی فعال است؛ متخصص را انتخاب کنید،
        روز و ساعت را از تقویم شمسی ببینید و در چند دقیقه نوبت‌تان را قطعی کنید.
      </p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
      <?php foreach (array_slice($services, 0, 2) as $sv): ?>
      <div style="background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);border-radius:16px;padding:12px 16px;min-width:180px">
        <b style="font-size:13.5px;display:block"><?= e($sv['title']) ?></b>
        <small style="opacity:.85;font-size:11.5px"><?= e($sv['consultant_name']) ?> · <?= fa_num($sv['duration_min']) ?> دقیقه · <?= (int)$sv['price'] ? toman((int)$sv['price']) : 'رایگان' ?></small>
      </div>
      <?php endforeach; ?>
      <a class="fr-btn white" href="<?= SITE_URL ?>/consult.php">رزرو نوبت ←</a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php
/* ============================================================================
 |  حالت مهمان — صفحهٔ بازاریابی کامل (طراحی تازهٔ ۱٫۸)
 * ==========================================================================*/
else: ?>
<section class="pb-hero">
  <span class="pb-hero-ic" aria-hidden="true">🧠</span>
  <div class="pb-hero-in">
    <h1><?= e(DB::setting('site_slogan', 'مسیر رشد فردی شما از اینجا آغاز می‌شود!')) ?> 👋</h1>
    <p>اینجا جایی است که می‌توانید خودتان را بهتر بشناسید: تست‌های روانشناسی علمی و بومی را در چند دقیقه انجام دهید، واکاوی عمیق‌شان را بخوانید، در کارگاه‌ها و دوره‌ها مهارت تازه بسازید و هر وقت به گفت‌وگو نیاز داشتید، سرِ وقت راحت نوبت مشاوره‌تان را رزرو کنید.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px">
      <a class="fr-btn white" href="<?= SITE_URL ?>/tests.php">شروع تست روانشناسی ←</a>
      <a class="fr-btn ghost" style="background:rgba(255,255,255,.18);color:#fff" href="<?= SITE_URL ?>/workshops.php">🛠 کارگاه‌ها</a>
      <a class="fr-btn ghost" style="background:rgba(255,255,255,.18);color:#fff" href="<?= SITE_URL ?>/consult.php">🛋 رزرو مشاوره</a>
    </div>
    <!-- نوار آماریِ واقعی سایت (۱٫۸) -->
    <div class="hm-stats">
      <div class="hm-stat"><b>🧠 <?= fa_num($statTests) ?> تست روانشناسی</b><small>علمی و بومی‌شده برای ایران</small></div>
      <div class="hm-stat"><b>🛠 <?= fa_num($statWS) ?> کارگاه</b><small>عملی و مهارت‌محور</small></div>
      <div class="hm-stat"><b>📚 <?= fa_num($statCR) ?> دوره</b><small>آموزشیِ چندجلسه‌ای</small></div>
      <div class="hm-stat"><b>🛋 <?= fa_num($statSV) ?> خدمت مشاوره</b><small>با رزرو آنی و تقویم شمسی</small></div>
    </div>
  </div>
</section>

<!-- ── فهرست کارت حرفه‌ای تست‌های روانشناسی (با نوار ابزار + نمایش همه) ── -->
<?php $testsShowcase($testsG); ?>

<?php $premiumSection($testsM); ?>

<?php $eventsShowcase($workshops, $courses); ?>

<?php if ($services): ?>
<section class="pb-wrap" style="padding-top:6px">
  <div class="bk-sum" style="display:flex;gap:24px;align-items:center;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
      <h3 style="font-size:19px;margin-bottom:8px">🛋 نیاز به گفت‌وگو دارید؟</h3>
      <p style="font-size:13.5px;line-height:2;opacity:.95;margin:0">
        <?= fa_num(count($services)) ?> خدمت مشاورهٔ تخصصی فعال است؛ متخصص را انتخاب کنید،
        روز و ساعت را از تقویم شمسی ببینید و در چند دقیقه نوبت‌تان را قطعی کنید.
      </p>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
      <?php foreach (array_slice($services, 0, 2) as $sv): ?>
      <div style="background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);border-radius:16px;padding:12px 16px;min-width:180px">
        <b style="font-size:13.5px;display:block"><?= e($sv['title']) ?></b>
        <small style="opacity:.85;font-size:11.5px"><?= e($sv['consultant_name']) ?> · <?= fa_num($sv['duration_min']) ?> دقیقه · <?= (int)$sv['price'] ? toman((int)$sv['price']) : 'رایگان' ?></small>
      </div>
      <?php endforeach; ?>
      <a class="fr-btn white" href="<?= SITE_URL ?>/consult.php">رزرو نوبت ←</a>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="pb-wrap">
  <div class="fr-grid cols-4">
    <?php
    $feats = [
      ['📊','تحلیل علمی و واکاوانه','بر پایهٔ پژوهش‌های معتبر و بومی‌سازی برای ایران'],
      ['📄','گزارش PDF راست‌چین','با لوگو و برند شما، آمادهٔ دانلود و چاپ'],
      ['🔐','امنیت بالا','پرداخت امن زرین‌پال و حریم خصوصی کامل'],
      ['📱','ریسپانسیو کامل','روی موبایل، تبلت و رایانه بدون نقص'],
    ];
    foreach ($feats as [$i,$t,$d]): ?>
    <div class="fr-card" style="margin:0;text-align:center">
      <div style="font-size:34px;margin-bottom:10px"><?= $i ?></div>
      <b style="font-size:15px"><?= $t ?></b>
      <p style="font-size:13px;color:var(--muted);margin-top:6px"><?= $d ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php public_footer(); ?>
