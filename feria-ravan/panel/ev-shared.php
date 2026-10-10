<?php
/**
 * =============================================================================
 *  نمای مشترک «کارگاه‌ها» / «دوره‌ها» (پنل کاربر) — نسخهٔ ۲٫۲
 *  ─ دو برگهٔ کاملاً تفکیک‌شده بر اساس $EV_KIND که پیش از require تنظیم می‌شود
 *  ─ workshops.php و courses.php نگهدارنده‌های نازکِ این فایل‌اند
 *  ─ events.php قدیمی برای سازگاری به این صفحه‌ها هدایت می‌شود
 * =============================================================================
 * @var string $EV_KIND  'workshop' | 'course'
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/deposit.php';
/* کارت حرفه‌ای رویداد در خانوادهٔ pc-card (هم‌طراز با کارت تست‌های روانشناسی) */
require_once __DIR__ . '/../includes/public-layout.php';
require_once __DIR__ . '/../includes/event-pricing.php';
fr_bootstrap();

$EV_KIND = $EV_KIND ?? 'workshop';
if (!in_array($EV_KIND, ['workshop','course'], true)) $EV_KIND = 'workshop';

$isCourse = $EV_KIND === 'course';
$self     = fr_panel_url() . ($isCourse ? 'courses.php' : 'workshops.php');
$pageKey  = $isCourse ? 'courses' : 'workshops';
$pageTitle = $isCourse ? 'دوره‌های جامع' : 'کارگاه‌های آموزشی';
$pageIcon  = $isCourse ? '📚' : '🛠';
$pageDesc  = $isCourse
    ? 'مسیرهای چندجلسه‌ای و قدم‌به‌قدم؛ برای وقتی که می‌خواهید یک موضوع را از پایه و عمیق یاد بگیرید.'
    : 'کارگاه‌های کوتاه‌مدت و عملی؛ چند ساعت تمرکز روی یک مهارت واقعی، با تمرین‌هایی که همان‌جا انجام می‌دهید.';
$noun      = $isCourse ? 'دوره' : 'کارگاه';
$accent    = $isCourse ? '#0e7490' : '#6d4bd0';
$pubPage   = $isCourse ? 'courses.php' : 'workshops.php';

$u   = require_user();
$ok  = (string)($_GET['ok'] ?? '');
$err = (string)($_GET['err'] ?? '');

/* ── ثبت درخواست عودت وجه ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['act'] ?? '') === 'refund') {
    try {
        refund_request_event((int)($_POST['reg'] ?? 0), (int)$u['id'], (string)($_POST['reason'] ?? ''));
        redirect($self . '?ok=' . urlencode('درخواست عودت وجه ثبت شد؛ نتیجهٔ بررسی از همین‌جا و پیامک اطلاع‌رسانی می‌شود. 💌'));
    } catch (Throwable $e) {
        redirect($self . '?err=' . urlencode($e->getMessage()));
    }
}

/* ── ثبت درخواست لغو/عودت «ثبت‌نام همراه» از پنل خودِ همراه ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['act'] ?? '') === 'refund_companion') {
    try {
        refund_request_companion((int)($_POST['comp'] ?? 0), (int)$u['id'], (string)($_POST['reason'] ?? ''));
        redirect($self . '?ok=' . urlencode('درخواست لغو همراهی و عودت وجه ثبت شد؛ پس از بررسی مدیر، نتیجه پیامک می‌شود. 💌'));
    } catch (Throwable $e) {
        redirect($self . '?err=' . urlencode($e->getMessage()));
    }
}

/* رویدادهای فعالِ همین نوع */
$events = DB::all("SELECT e.*,
        (SELECT COALESCE(SUM(1 + COALESCE(r.companion_count,0)),0)
           FROM " . DB::t('event_registrations') . " r
          WHERE r.event_id=e.id AND r.status='paid') AS seats
    FROM " . DB::t('events') . " e
    WHERE e.status='active' AND e.reg_open=1 AND e.kind=?
    ORDER BY e.starts_at", [$EV_KIND]);

/* ثبت‌نام‌های من در همین نوع رویداد */
$mine = DB::all("SELECT r.*, e.title, e.kind, e.starts_at, e.teacher, e.price
                 FROM " . DB::t('event_registrations') . " r
                 JOIN " . DB::t('events') . " e ON e.id=r.event_id
                 WHERE r.user_id=? AND e.kind=? ORDER BY r.id DESC", [$u['id'], $EV_KIND]);

/* رویدادهایی از همین نوع که من به‌عنوان «همراه» ثبت شده‌ام */
$myCompanions = DB::all("SELECT c.*, r.status AS reg_status, r.discount_meta, e.id AS event_id, e.title, e.kind, e.starts_at,
                         CONCAT_WS(' ', wn.first_name, wn.last_name) AS owner_name
                  FROM " . DB::t('event_companions') . " c
                  JOIN " . DB::t('event_registrations') . " r ON r.id=c.reg_id
                  JOIN " . DB::t('events') . " e ON e.id=r.event_id
                  JOIN " . DB::t('users') . " wn ON wn.id=r.user_id
                  WHERE c.user_id=? AND e.kind=? ORDER BY c.id DESC", [$u['id'], $EV_KIND]);

render_header($pageTitle, ['css' => ['site.css']]);
panel_layout($u, $pageKey);
?>
<?php if ($ok): ?><div class="fr-alert ok" style="margin-bottom:14px"><?= e($ok) ?></div><?php endif; ?>
<?php if ($err): ?><div class="fr-alert err" style="margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>

<div class="fr-card" style="border-top:3px solid <?= $accent ?>">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:6px">
    <div>
      <h3 style="margin:0"><?= $pageIcon ?> <?= $pageTitle ?></h3>
      <p style="font-size:12.5px;color:var(--muted);margin:6px 0 0"><?= $pageDesc ?></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="fr-btn sm ghost" href="<?= fr_panel_url($isCourse ? 'workshops.php' : 'courses.php') ?>">
        <?= $isCourse ? '🛠 کارگاه‌ها' : '📚 دوره‌ها' ?></a>
      <a class="fr-btn sm ghost" href="<?= SITE_URL ?>/<?= $pubPage ?>" target="_blank" rel="noopener">🌐 صفحهٔ عمومی</a>
    </div>
  </div>
</div>

<?php if (!$events): ?>
<div class="fr-card" style="margin-bottom:22px"><p class="fr-alert info" style="margin:0">فعلاً <?= $noun ?>ی فعال نیست؛ به‌زودی رویدادهای جدید اعلام می‌شود.</p></div>
<?php else: ?>
<div style="margin-bottom:22px">
<?php
  fr_pc_toolbar('panel-events-' . $EV_KIND, count($events), $noun);
  foreach ($events as $ev):
    $mineReg = (int)DB::val("SELECT id FROM " . DB::t('event_registrations') . " WHERE event_id=? AND user_id=? AND status IN ('paid','deposit')", [$ev['id'], $u['id']]);
    fr_event_pc_card($ev, ev_seats_left($ev), [
        'link'       => 'event-register.php?id=' . (int)$ev['id'],
        'registered' => (bool)$mineReg,
    ]);
  endforeach;
  fr_pc_toolbar_end();
?>
</div>
<?php endif; ?>

<?php if ($myCompanions): ?>
<!-- ثبت‌نام‌های من به‌عنوان همراه (همین نوع رویداد) -->
<div class="fr-card" style="border-right:4px solid #0d9488">
  <h3>👥 ثبت‌نام‌های من به‌عنوان همراه در <?= $noun ?>ها</h3>
  <p style="font-size:12.5px;color:var(--muted);margin-top:0">
    اگر کسی شما را به‌عنوان همراه در <?= $noun ?>ی ثبت کرده باشد، این‌جا می‌بینید و می‌توانید از همین‌جا درخواست لغو و عودت سهم‌هزینه‌تان را بدهید.
  </p>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead><tr><th><?= $noun ?></th><th>ثبت‌شده توسط</th><th>تاریخ</th><th>سهم هزینه</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($myCompanions as $c):
      $meta = $c['discount_meta'] ? json_decode($c['discount_meta'], true) : [];
      $unit = (int)($meta['companion_unit'] ?? 0);
      $future = !empty($c['starts_at']) && strtotime($c['starts_at']) > time();
      $rst = ($c['status'] === 'active') ? refund_status_for('companion', (int)$c['id']) : null;
    ?>
    <tr>
      <td><b><?= e($c['title']) ?></b></td>
      <td style="font-size:12px"><?= e($c['owner_name'] ?: '—') ?></td>
      <td style="font-size:12px"><?= $c['starts_at'] ? fa_num(jdate('j F Y - H:i', strtotime($c['starts_at']))) : '—' ?></td>
      <td style="font-size:12px"><?= $unit > 0 ? toman($unit) : 'رایگان' ?></td>
      <td>
        <?php if ($c['status'] === 'canceled'): ?><span class="fr-chip err" style="font-size:11px">لغوشده</span>
        <?php elseif ($c['reg_status'] === 'paid'): ?><span class="fr-chip ok" style="font-size:11px">ثبت‌نام قطعی ✅</span>
        <?php else: ?><span class="fr-chip warn" style="font-size:11px">در انتظار پرداخت</span><?php endif; ?>
      </td>
      <td style="min-width:170px">
        <?php if ($c['status'] === 'active' && $c['reg_status'] === 'paid'): ?>
          <?php if ($rst !== null): ?>
            <span class="fr-chip <?= ['pending'=>'warn','approved'=>'info','rejected'=>'err','paid'=>'ok'][$rst] ?? 'info' ?>" style="font-size:11px">عودت: <?= refund_status_fa($rst) ?></span>
          <?php elseif ($future && $unit > 0): ?>
            <button class="fr-btn ghost sm" type="button" onclick="document.getElementById('crf-<?= (int)$c['id'] ?>').style.display='block';this.style.display='none'">↩️ لغو همراهی و عودت وجه</button>
            <form method="post" id="crf-<?= (int)$c['id'] ?>" style="display:none;margin-top:6px"
                  onsubmit="return confirm('درخواست لغو همراهی و عودت سهم هزینه (<?= toman($unit) ?>) ثبت شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="refund_companion">
              <input type="hidden" name="comp" value="<?= (int)$c['id'] ?>">
              <input class="fr-input" name="reason" placeholder="دلیل لغو (اختیاری)" maxlength="300" style="font-size:12px;margin-bottom:6px">
              <button class="fr-btn sm accent">ثبت درخواست</button>
            </form>
          <?php elseif ($unit <= 0): ?>
            <small style="color:var(--muted);font-size:11px">رایگان بوده؛ برای لغو با <?= e($c['owner_name'] ?: 'ثبت‌نام‌کننده') ?> هماهنگ کنید.</small>
          <?php else: ?>
            <small style="color:var(--muted);font-size:11px">رویداد برگزار شده — از پشتیبانی پیگیری کنید.</small>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- ثبت‌نام‌های من در همین نوع رویداد + عودت وجه -->
<div class="fr-card">
  <h3>ثبت‌نام‌های من در <?= $noun ?>ها</h3>
  <?php if (!$mine): ?>
  <p class="fr-alert info" style="margin:0">هنوز در <?= $noun ?>ی ثبت‌نام نکرده‌اید.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead><tr><th><?= $noun ?></th><th>تاریخ برگزاری</th><th>فاکتور</th><th>وضعیت</th><th>عودت وجه</th></tr></thead>
    <tbody>
    <?php foreach ($mine as $m):
      $comps = ev_companions_of((int)$m['id']);
      $meta  = $m['discount_meta'] ? json_decode($m['discount_meta'], true) : null;
      $payable = $meta['payable'] ?? ((int)$m['amount_base'] - (int)$m['discount_amount']);
      $future  = !empty($m['starts_at']) && strtotime($m['starts_at']) > time();
      $refundSt = refund_status_for('event', (int)$m['id']);
    ?>
    <tr>
      <td>
        <b><?= e($m['title']) ?></b> <small style="color:var(--muted)"><?= e($m['teacher'] ?? '') ?></small>
        <?php if ($comps): ?>
        <div style="font-size:11.5px;color:var(--muted);margin-top:4px">
          👥 همراهان: <?= e(implode('، ', array_map(fn($c) => trim($c['first_name'] . ' ' . (string)$c['last_name']), $comps))) ?>
        </div>
        <?php endif; ?>
      </td>
      <td style="font-size:12px"><?= $m['starts_at'] ? fa_num(jdate('j F Y - H:i', strtotime($m['starts_at']))) : '—' ?></td>
      <td style="font-size:11.5px;line-height:1.9">
        <?= toman(max(0,(int)$payable)) ?>
        <?= $m['discount_amount'] ? '<br><small style="color:#0c7a4d">تخفیف: ' . toman((int)$m['discount_amount']) . '</small>' : '' ?>
        <?php if ($m['status'] === 'deposit'): ?>
        <br><small style="color:#8a5a2e">پرداخت‌شده: <?= toman((int)$m['paid_total']) ?> · مانده: <b><?= toman(fr_reg_remaining($m)) ?></b></small>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($m['status'] === 'paid'): ?>
          <span class="fr-chip ok">ثبت‌نام نهایی ✅</span>
        <?php elseif ($m['status'] === 'deposit'): ?>
          <span class="fr-chip info">💳 پیش‌پرداخت شده — جایتان قطعی است</span><br>
          <a class="fr-btn sm" style="margin-top:6px" href="<?= e(fr_settle_url_reg($m)) ?>">💳 تصفیهٔ حساب (<?= toman(fr_reg_remaining($m)) ?>)</a>
        <?php else: ?>
          <span class="fr-chip warn">در انتظار پرداخت</span><br><a class="fr-btn ghost sm" style="margin-top:6px" href="event-register.php?id=<?= (int)$m['event_id'] ?>">اصلاح/پرداخت</a>
        <?php endif; ?>
      </td>
      <td style="min-width:150px">
        <?php if (($m['status'] === 'paid' && (int)$payable > 0) || ($m['status'] === 'deposit' && (int)$m['paid_total'] > 0)): ?>
          <?php if ($refundSt !== null): ?>
            <span class="fr-chip <?= ['pending'=>'warn','approved'=>'info','rejected'=>'err','paid'=>'ok'][$refundSt] ?? 'info' ?>" style="font-size:11px"><?= refund_status_fa($refundSt) ?></span>
          <?php elseif ($future): ?>
            <button class="fr-btn ghost sm" type="button" onclick="document.getElementById('rf-<?= (int)$m['id'] ?>').style.display='block';this.style.display='none'">
              ↩️ درخواست عودت وجه</button>
            <form method="post" id="rf-<?= (int)$m['id'] ?>" style="display:none;margin-top:6px"
                  onsubmit="return confirm('درخواست عودت وجه برای «<?= e($m['title']) ?>» ثبت شود؟')">
              <?= csrf_field() ?>
              <input type="hidden" name="act" value="refund">
              <input type="hidden" name="reg" value="<?= (int)$m['id'] ?>">
              <input class="fr-input" name="reason" placeholder="دلیل درخواست (اختیاری)" maxlength="300" style="font-size:12px;margin-bottom:6px">
              <button class="fr-btn sm accent">ثبت درخواست</button>
            </form>
          <?php else: ?>
            <small style="color:var(--muted);font-size:11px">رویداد برگزار شده — از طریق پشتیبانی پیگیری کنید.</small>
          <?php endif; ?>
        <?php elseif ($m['status'] === 'canceled'): ?>
          <span class="fr-chip err" style="font-size:11px">لغوشده</span>
        <?php else: ?>
          <small style="color:var(--muted);font-size:11px">—</small>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php panel_layout_end(); render_footer(); ?>
