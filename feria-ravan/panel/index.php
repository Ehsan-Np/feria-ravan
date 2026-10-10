<?php
/**
 * ============================================================================
 *  پنل کاربری — داشبورد (نسخهٔ ۲٫۰ — بازطراحی کامل)
 *  ─ کارت هویت کاربر: آواتار + نام + موبایل + تاریخ عضویت + درصد تکمیل پروفایل
 *  ─ آمار واقعی: تست‌های روانشناسی (تکمیل/ناتمام)، ثبت‌نام رویدادها، پرداخت‌های موفق
 *  ─ تست‌های روانشناسی موجود با همان کارت حرفه‌ای pc-card نسخهٔ ۱٫۸ (یک طراحی واحد)
 *  ─ ثبت‌نام‌های من در کارگاه‌ها/دوره‌ها + آخرین تست‌های روانشناسی — اطلاعات کامل کاربر
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/panel-cards.php';
require_once __DIR__ . '/../core/Kavenegar.php';
require_once __DIR__ . '/../core/Analyzer.php';
fr_bootstrap();

$u = require_user();

/* ── آمار واقعی کاربر ── */
$stats = [
    'done'    => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE user_id=? AND status!='in_progress'", [$u['id']]),
    'pending' => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE user_id=? AND status='in_progress'",  [$u['id']]),
    'paid'    => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('orders')   . " WHERE user_id=? AND status='paid'",         [$u['id']]),
    'events'  => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('event_registrations') . " WHERE user_id=? AND status='paid'",[$u['id']]),
];

/* آخرین تست‌های روانشناسی کاربر */
$recent = DB::all("SELECT a.*, t.title AS test_title, t.slug, o.status AS order_status, o.id AS order_id
                   FROM " . DB::t('attempts') . " a
                   JOIN " . DB::t('tests') . " t ON t.id=a.test_id
                   LEFT JOIN " . DB::t('orders') . " o ON o.id=a.order_id
                   WHERE a.user_id=? ORDER BY a.id DESC LIMIT 6", [$u['id']]);

// همگام‌سازی دسترسی‌های پرداخت‌شده
foreach ($recent as &$r) {
    if ($r['mode'] === 'free' && $r['status'] !== 'in_progress') {
        $paidOrder = null;
        if (!empty($r['order_id'])) {
            $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') . " WHERE id=? AND status='paid'", [(int)$r['order_id']]);
        }
        if (!$paidOrder) {
            $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') .
                                 " WHERE user_id=? AND test_id=? AND status='paid' ORDER BY id DESC LIMIT 1",
                                 [$u['id'], $r['test_id']]);
        }
        if ($paidOrder) {
            DB::update('attempts', ['mode' => 'full', 'order_id' => $paidOrder['id']], 'id=?', [$r['id']]);
            $r['mode'] = 'full';
            $r['order_id'] = $paidOrder['id'];
        }
    }
}
unset($r);

/* ثبت‌نام‌های من در کارگاه‌ها/دوره‌ها (نسخهٔ ۲٫۰ — همهٔ اطلاعاتِ کاربر در داشبورد) */
$myEvents = DB::all("SELECT r.*, e.title, e.kind, e.starts_at, e.teacher, e.slug AS ev_slug
                     FROM " . DB::t('event_registrations') . " r
                     JOIN " . DB::t('events') . " e ON e.id=r.event_id
                     WHERE r.user_id=? ORDER BY r.id DESC LIMIT 6", [$u['id']]);

/* تست‌های روانشناسی فعال — کارت حرفه‌ای (عمومی + ویژهٔ مدیران، سازگار با نصب‌های قدیمی) */
$tests = [];
try {
    $tests = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' ORDER BY audience DESC, sort_order LIMIT 6");
} catch (Throwable $e) {
    $tests = DB::all("SELECT * FROM " . DB::t('tests') . " WHERE status='active' ORDER BY sort_order LIMIT 6");
}

$pc = fr_profile_completion($u);

render_header('داشبورد');
panel_layout($u, 'dashboard');

/* ── اعلان خاموش‌بودن یک بخش درخواستی (۱٫۷ — شخصی‌سازی پنل توسط مدیر) ── */
if (!empty($_GET['off'])): ?>
<div class="fr-alert warn">این بخش موقتاً توسط مدیر سایت پنهان شده است. 🙏</div>
<?php endif;

/* ── یادداشت دلخواه مدیر بالای داشبورد (۱٫۷) ── */
$dashNote = trim((string)DB::setting('panel_dashboard_note', ''));
if ($dashNote !== ''):
    require_once __DIR__ . '/../includes/sanitize.php'; ?>
<div class="fr-card" style="border-right:4px solid var(--c-primary);background:linear-gradient(120deg,#fafbfd,#fff)">
  <div class="ev-content" style="font-size:13.5px"><?= fr_sanitize_html($dashNote) ?></div>
</div>
<?php endif;

/* ── خوش‌آمد پس از ثبت‌نام سبک ── */
if (isset($_GET['welcome'])): ?>
<div class="fr-alert ok" style="display:flex;gap:10px;align-items:center">
  <span style="font-size:22px">🎉</span>
  <div><b>حساب شما ساخته شد؛ خوش آمدید!</b><br>
  <small>در هر زمان می‌توانید از بخش «پروفایل» اطلاعاتتان را کامل کنید.</small></div>
</div>
<?php endif; ?>

<!-- ══ کارت هویت کاربر (۲٫۰) ══ -->
<?php
$avatar   = $u['avatar'] ?: (SITE_URL . '/assets/img/avatar.svg');
$memberSt = !empty($u['created_at']) ? strtotime((string)$u['created_at']) : time();
?>
<div class="fr-card" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;background:linear-gradient(120deg,#faf8ff,#fff);border:1.5px solid #ebe4ff">
  <div style="position:relative;flex-shrink:0">
    <img src="<?= e($avatar) ?>" alt="" style="width:74px;height:74px;border-radius:24px;object-fit:cover;border:3px solid #fff;box-shadow:0 6px 18px rgba(80,60,180,.18)">
    <span style="position:absolute;bottom:-4px;left:-4px;background:#19a974;color:#fff;border-radius:12px;font-size:10px;padding:2px 8px;border:2px solid #fff">فعال</span>
  </div>
  <div style="flex:1;min-width:230px">
    <h2 style="margin:0;font-size:20px">سلام، <?= e($u['first_name'] ?: 'کاربر') ?> عزیز 👋</h2>
    <div style="font-size:12.5px;color:var(--muted);margin-top:6px;line-height:2">
      📱 <?= fa_num($u['mobile']) ?>
      <?php if (!empty($u['email'])): ?> · ✉️ <?= e($u['email']) ?><?php endif; ?>
      <br>🗓 عضو فریا از <?= fa_num(jdate('j F Y', $memberSt)) ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap">
      <div class="fr-progress" style="flex:1;min-width:150px;max-width:280px"><i style="width:<?= $pc['percent'] ?>%"></i></div>
      <small style="font-size:12px;color:var(--muted)">تکمیل پروفایل: <b><?= fa_num($pc['percent']) ?>٪</b></small>
      <?php if ($pc['percent'] < 100): ?><a href="profile.php" style="font-size:12px;color:var(--c-primary)">تکمیل ←</a><?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="fr-btn" href="<?= fr_panel_url("tests.php") ?>">🧠 شروع تست روانشناسی جدید</a>
    <a class="fr-btn ghost" href="profile.php">👤 پروفایل</a>
  </div>
</div>

<!-- آمار — چهار کارت: تست‌های روانشناسی + رویدادها + پرداخت‌ها -->
<div class="fr-grid cols-3" style="margin-bottom:6px">
  <?php stat_card('✅','تست‌های روانشناسی تکمیل‌شده', fa_num($stats['done'])); ?>
  <?php stat_card('⏳','تست‌های روانشناسی ناتمام', fa_num($stats['pending']), '#fff6e6'); ?>
  <?php stat_card('📅','ثبت‌نام رویدادها', fa_num($stats['events']), '#eef7ff'); ?>
  <?php stat_card('💳','پرداخت‌های موفق', fa_num($stats['paid']), '#ecfbf4'); ?>
</div>

<!-- ادامه مشاهده / آخرین تست‌های روانشناسی -->
<div class="fr-card">
  <h3>تست‌های روانشناسی اخیر شما <a class="more" href="history.php">مشاهده همه ←</a></h3>
  <?php if (!$recent): ?>
    <p class="fr-alert info" style="margin:0">هنوز تستی انجام نداده‌اید؛ اولین تست روانشناسی خود را شروع کنید! 🦢</p>
  <?php else: ?>
  <table class="fr-table">
    <thead><tr><th>تست روانشناسی</th><th>نوع</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <td><b><?= e($r['test_title']) ?></b></td>
        <td><span class="fr-chip <?= $r['mode']==='full'?'purple':'info' ?>"><?= $r['mode']==='full'?'ویژه':'رایگان' ?></span></td>
        <td><?= $r['status']==='in_progress'
              ? '<span class="fr-chip warn">درحال انجام</span>'
              : '<span class="fr-chip ok">تکمیل‌شده</span>' ?></td>
        <td><?= fa_num(jdate('j F Y - H:i', strtotime($r['started_at']))) ?></td>
        <td style="text-align:left">
          <?php if ($r['status']==='in_progress'): ?>
            <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('take.php?attempt=' . (int)$r['id'])) ?>">ادامه تست روانشناسی</a>
          <?php else: ?>
            <a class="fr-btn sm" href="<?= e(fr_panel_url('report.php?attempt=' . (int)$r['id'])) ?>">مشاهده گزارش</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ثبت‌نام‌های من در کارگاه‌ها و دوره‌ها (۲٫۰ — نمایش کامل اطلاعات) -->
<div class="fr-card">
  <h3>کارگاه‌ها و دوره‌های من</h3>
  <?php if (!$myEvents): ?>
    <p class="fr-alert info" style="margin:0">در هیچ کارگاه یا دوره‌ای ثبت‌نام ندارید؛
      از <a href="<?= fr_panel_url("workshops.php") ?>">لیست کارگاه‌ها 🛠</a> یا <a href="<?= fr_panel_url("courses.php") ?>">دوره‌ها 📚</a> انتخاب کنید.</p>
  <?php else: ?>
  <table class="fr-table">
    <thead><tr><th>رویداد</th><th>نوع</th><th>وضعیت</th><th>زمان برگزاری</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($myEvents as $r): ?>
      <tr>
        <td><b><?= e($r['title']) ?></b><?= $r['teacher'] ? '<br><small style="color:var(--muted)">👤 ' . e($r['teacher']) . '</small>' : '' ?></td>
        <td><span class="fr-chip <?= $r['kind']==='course'?'info':'purple' ?>"><?= $r['kind']==='course'?'📚 دوره':'🛠 کارگاه' ?></span></td>
        <td><?php
          $st = (string)$r['status'];
          echo $st === 'paid'     ? '<span class="fr-chip ok">ثبت‌نام نهایی ✅</span>'
            : ($st === 'pending'  ? '<span class="fr-chip warn">در انتظار پرداخت</span>'
            : ($st === 'refunded' ? '<span class="fr-chip err">عودت‌یافته</span>'
            : '<span class="fr-chip">' . e($st) . '</span>'));
        ?></td>
        <td><?= $r['starts_at'] ? fa_num(jdate('j F Y - H:i', strtotime($r['starts_at']))) : '—' ?></td>
        <td style="text-align:left">
          <a class="fr-btn sm ghost" href="<?= e(fr_event_url(['id'=>(int)$r['event_id'],'kind'=>$r['kind'],'slug'=>$r['ev_slug']])) ?>">صفحهٔ رویداد</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- تست‌های روانشناسی موجود — کارت حرفه‌ای pc-card (۲٫۰: جایگزین باکس‌های سادهٔ قدیمی) -->
<div class="fr-card">
  <h3>تست‌های روانشناسی موجود <a class="more" href="tests.php">همهٔ تست‌های روانشناسی ←</a></h3>
  <?php if (!$tests): ?>
    <p class="fr-alert info" style="margin:0">فعلاً تست روانشناسی فعالی نیست.</p>
  <?php else: ?>
  <div class="pc-canvas" data-pc-id="panel-dash-tests" style="margin-top:6px">
    <?php foreach ($tests as $t) fr_panel_test_card($t); ?>
  </div>
  <?php endif; ?>
</div>

<?php
/* بنر داشبورد (تبلیغاتی) */
$bn = DB::one("SELECT * FROM " . DB::t('banners') . " WHERE active=1 AND placement='dashboard'
  AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW()) ORDER BY sort_order LIMIT 1");
if ($bn) echo '<a href="' . e($bn['link'] ?: '#') . '"><img src="' . e($bn['image']) . '" style="border-radius:var(--radius);width:100%" alt=""></a>';

panel_layout_end();
render_footer();
