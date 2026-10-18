<?php
/** پروندهٔ کاربر — اطلاعات، تست‌های روانشناسی، پرداخت‌ها، فعالیت‌ها، مشاهده تحلیل */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../includes/wallet.php';
$adm = require_admin();

$id = (int)($_GET['id'] ?? 0);
$u  = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$id]);
if (!$u) redirect(SITE_URL . '/admin/users.php');

/* ارسال لینک دانلود/مشاهده گزارش با پیامک برای کاربر (بند ۱۶) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'sms_report') {
    $at = DB::one('SELECT a.*, t.title FROM ' . DB::t('attempts') . ' a JOIN ' . DB::t('tests') .
                  ' t ON t.id=a.test_id WHERE a.id=? AND a.user_id=?', [(int)post('attempt_id'), $id]);
    if ($at) {
        $link = fr_panel_url('report.php?attempt=' . $at['id']);
        $ok = (new Kavenegar())->send($u['mobile'],
            'لینک گزارش تست روانشناسی «' . $at['title'] . '» شما:' . "\n" . $link, 'report_link');
        flash($ok ? 'لینک گزارش پیامک شد ✅' : 'ارسال ناموفق (تنظیمات کاوه‌نگار را بررسی کنید).', $ok ? 'ok' : 'err');
        log_activity('admin_sms_report_link', 'attempt', (int)$at['id']);
    }
    redirect(SITE_URL . '/admin/user-view.php?id=' . $id);
}

/* ارسال پیامک یادآوری ادامه آزمون نیمه‌کاره به همراه لینک اختصاصی ادامه */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'sms_nudge') {
    $at = DB::one('SELECT a.*, t.title FROM ' . DB::t('attempts') . ' a JOIN ' . DB::t('tests') .
                  ' t ON t.id=a.test_id WHERE a.id=? AND a.user_id=?', [(int)post('attempt_id'), $id]);
    if ($at) {
        $link = fr_panel_url('take.php?attempt=' . $at['id']);
        $name = trim($u['first_name'] . ' ' . $u['last_name']) ?: 'کاربر گرامی';
        $kv = new Kavenegar();
        $ok = $kv->sendEvent('test_nudge', $u['mobile'], [
            'name'    => $name,
            'test'    => $at['title'],
            'link'    => $link,
            '_tokens' => [$name, $at['title'], $link],
        ]);
        if (!$ok) {
            $site = DB::setting('site_title', 'فریا روان');
            $msg = "سلام {$name} عزیز\nپاسخ‌گویی به آزمون «{$at['title']}» شما نیمه‌کاره است. جهت تکمیل سوالات و دریافت کارنامه، روی لینک زیر بزنید:\n{$link}\n{$site}";
            $ok = $kv->send($u['mobile'], $msg, 'test_nudge');
        }
        if ($ok) {
            DB::update('attempts', ['nudge_sent' => 1], 'id=?', [$at['id']]);
            flash('پیامک یادآوری و لینک ادامهٔ آزمون با موفقیت برای کاربر ارسال شد ✅', 'ok');
            log_activity('admin_sms_nudge', 'attempt', (int)$at['id']);
        } else {
            flash('ارسال پیامک با خطا مواجه شد (تنظیمات کاوه‌نگار را بررسی کنید).', 'err');
        }
    }
    redirect(SITE_URL . '/admin/user-view.php?id=' . $id);
}

/* تغییر/حذف کارت بانکی کاربر — فقط مدیرکل (superadmin)؛ کاربر خودش بعد از ثبت کارت نمی‌تواند تغییرش دهد (۱٫۷) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('act'), ['bank_card_set','bank_card_remove'], true)) {
    if (($adm['role'] ?? '') !== 'superadmin') {
        flash('فقط مدیرکل اجازهٔ تغییر کارت بانکی کاربران را دارد.', 'err');
        redirect(SITE_URL . '/admin/user-view.php?id=' . $id);
    }
    if (post('act') === 'bank_card_remove') {
        DB::update('users', ['bank_card_enc'=>null, 'bank_card_last4'=>null], 'id=?', [$id]);
        log_activity('admin_bank_card_remove', 'user', $id);
        flash('کارت بانکی کاربر حذف شد؛ کاربر می‌تواند دوباره کارت تازه‌ای ثبت کند.');
    } else {
        $r = fr_set_bank_card($id, (string)post('bank_card'));
        if ($r['ok']) {
            log_activity('admin_bank_card_set', 'user', $id, ['last4'=>$r['last4']]);
            flash('کارت بانکی کاربر با موفقیت تغییر کرد ✅ (انتهایی به ' . fa_num($r['last4']) . ')');
        } else {
            flash($r['message'], 'err');
        }
    }
    redirect(SITE_URL . '/admin/user-view.php?id=' . $id);
}

/* تعدیل کیف پول: شارژ/کسر دستی توسط مدیر (هدیه یا تعدیل) — نسخهٔ ۱٫۶ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'wallet_adjust') {
    $amount = (int)en_num((string)post('wallet_amount'));
    $desc   = mb_substr(trim((string)post('wallet_desc')), 0, 255);
    try {
        if ($amount > 0) {
            wallet_credit($id, $amount, 'adjustment', $desc !== '' ? $desc : 'تعدیل توسط مدیر');
            flash('مبلغ ' . toman($amount) . ' به کیف پول کاربر اضافه شد ✅');
        } elseif ($amount < 0) {
            wallet_debit($id, abs($amount), $desc !== '' ? $desc : 'کسر توسط مدیر');
            flash('مبلغ ' . toman(abs($amount)) . ' از کیف پول کاربر کم شد.');
        } else {
            flash('مبلغ معتبر نیست.', 'err');
        }
        log_activity('admin_wallet_adjust', 'user', $id, ['amount'=>$amount]);
    } catch (Throwable $e) { flash('خطا: ' . $e->getMessage(), 'err'); }
    redirect(SITE_URL . '/admin/user-view.php?id=' . $id);
}

/* اگر گزارش خواسته شده، همان صفحهٔ گزارش را به مدیر نشان بده */
if (($view = (int)($_GET['report'] ?? 0))) {
    $at = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE id=? AND user_id=?', [$view, $id]);
    if ($at) {
        if ($at['mode'] === 'free') {
            $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') . " WHERE user_id=? AND test_id=? AND status='paid' ORDER BY id DESC LIMIT 1", [$id, $at['test_id']]);
            if ($paidOrder) {
                DB::update('attempts', ['mode' => 'full', 'order_id' => $paidOrder['id']], 'id=?', [$at['id']]);
                $at['mode'] = 'full';
                require_once __DIR__ . '/../core/Analyzer.php';
                Analyzer::scoreAttempt((int)$at['id']);
            }
        }
        admin_layout($adm, 'users', 'مشاهده تحلیل — ' . user_fullname($u));
        echo '<a class="fr-btn ghost sm" href="user-view.php?id='.$id.'">→ بازگشت به پرونده</a> ';
        echo '<a class="fr-btn sm" style="background:#059669;color:#fff;font-weight:700" href="' . e(SITE_URL . '/admin/report-pdf.php?attempt=' . $at['id'] . '&download=1') . '" target="_blank" title="دانلود و دریافت مستقیم فایل معتبر PDF کارنامه در ابعاد استاندارد A4">📥 دریافت فایل PDF کارنامه</a><br><br>';
        echo ReportBuilder::build($at, $u, (string)($at['mode'] ?? 'full'), ['isAdmin' => true]);
        admin_layout_end(); exit;
    }
}

$attempts = DB::all("SELECT a.*, t.title AS ttitle FROM " . DB::t('attempts') . " a
                     JOIN " . DB::t('tests') . " t ON t.id=a.test_id WHERE a.user_id=? ORDER BY a.id DESC", [$id]);
foreach ($attempts as &$a) {
    if ($a['mode'] === 'free' && $a['status'] !== 'in_progress') {
        $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') . " WHERE user_id=? AND test_id=? AND status='paid' ORDER BY id DESC LIMIT 1", [$id, $a['test_id']]);
        if ($paidOrder) {
            DB::update('attempts', ['mode' => 'full', 'order_id' => $paidOrder['id']], 'id=?', [$a['id']]);
            $a['mode'] = 'full';
        }
    }
}
unset($a);
$orders   = DB::all("SELECT o.*, CASE o.kind WHEN 'event' THEN CONCAT('🛠 ', COALESCE(ev.title,'رویداد'))
                           WHEN 'consult' THEN '🛋 رزرو مشاوره'
                           ELSE CONCAT('🧠 ', COALESCE(t.title,'تست روانشناسی')) END AS ttitle
                     FROM " . DB::t('orders') . " o
                     LEFT JOIN " . DB::t('tests') . " t ON t.id=o.test_id
                     LEFT JOIN " . DB::t('events') . " ev ON ev.id=o.event_id
                     WHERE o.user_id=? ORDER BY o.id DESC", [$id]);
$sessions = DB::all("SELECT * FROM " . DB::t('sessions') . " WHERE user_id=? ORDER BY login_at DESC LIMIT 10", [$id]);
$logs     = DB::all("SELECT * FROM " . DB::t('activity_logs') . " WHERE actor_type='user' AND actor_id=? ORDER BY id DESC LIMIT 15", [$id]);

admin_layout($adm, 'users', 'پرونده: ' . user_fullname($u));
echo flash();
?>
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3>اطلاعات کاربر</h3>
    <table class="fr-table">
      <tr><th>نام</th><td><?= e(user_fullname($u)) ?></td></tr>
      <tr><th>موبایل</th><td style="direction:ltr;text-align:right"><?= fa_num($u['mobile']) ?></td></tr>
      <tr><th>کد ملی</th><td style="direction:ltr;text-align:right"><?= e($u['national_code'] ?? '—') ?></td></tr>
      <tr><th>تاریخ تولد</th><td><?= $u['birth_date'] ? fa_num(jdate('j F Y', strtotime($u['birth_date']))) : '—' ?></td></tr>
      <tr><th>شغل</th><td><?= e($u['job_title'] ?? '—') ?></td></tr>
      <tr><th>👛 کیف پول</th><td><b style="color:#7c3aed"><?= toman(wallet_balance($id)) ?></b></td></tr>
      <tr><th>💳 کارت بانکی (عودت وجه)</th><td style="direction:ltr;text-align:right"><?= fr_has_bank_card($u) ? e(fr_bank_card_full($u)) : '<small style="color:var(--muted)">ثبت نشده</small>' ?>
        <?php if (($adm['role'] ?? '') === 'superadmin'): ?>
        <details style="margin-top:8px;direction:rtl">
          <summary style="font-size:11.5px;color:var(--c-primary);cursor:pointer">✏️ تغییر/حذف کارت (فقط مدیرکل)</summary>
          <form method="post" style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="bank_card_set">
            <input class="fr-input" name="bank_card" inputmode="numeric" maxlength="19" placeholder="شماره کارت جدید ۱۶ رقمی"
                   style="font-size:11.5px;direction:ltr;text-align:left;flex:1;min-width:170px" required>
            <button class="fr-btn sm" onclick="return confirm('کارت بانکی کاربر تغییر کند؟')">ثبت کارت جدید</button>
          </form>
          <?php if (fr_has_bank_card($u)): ?>
          <form method="post" style="margin-top:6px" onsubmit="return confirm('کارت بانکی کاربر حذف شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="bank_card_remove">
            <button class="fr-btn sm danger">🗑 حذف کارت کاربر</button>
          </form>
          <?php endif; ?>
        </details>
        <?php endif; ?></td></tr>
      <tr><th>ایمیل</th><td><?= e($u['email'] ?? '—') ?></td></tr>
      <tr><th>تأیید موبایل</th><td><?= $u['mobile_verified'] ? '✔' : '✘' ?></td></tr>
      <tr><th>عضویت</th><td><?= fa_num(jdate('j F Y - H:i', strtotime($u['created_at']))) ?></td></tr>
      <tr><th>آخرین IP</th><td style="direction:ltr;text-align:right"><?= $u['last_login_ip'] ? e(inet_ntop($u['last_login_ip'])) : '—' ?></td></tr>
    </table>
  </div>
  <div class="fr-card" style="margin:0;align-self:start">
    <h3>نشست‌های اخیر (ورود/خروج)</h3>
    <table class="fr-table">
      <thead><tr><th>ورود</th><th>خروج</th><th>دستگاه</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($sessions as $s): ?>
      <tr><td style="font-size:12px"><?= fa_num(jdate('j F - H:i', strtotime($s['login_at']))) ?></td>
          <td style="font-size:12px"><?= $s['logout_at'] ? fa_num(jdate('H:i', strtotime($s['logout_at']))) : '—' ?></td>
          <td style="font-size:12px"><?= e($s['device']) ?></td>
          <td style="font-size:11px;direction:ltr"><?= $s['ip'] ? e(inet_ntop($s['ip'])) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="fr-card">
  <h3>تست‌های روانشناسی کاربر (<?= fa_num(count($attempts)) ?>)</h3>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>تست روانشناسی</th><th>نوع</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($attempts as $a): ?>
    <tr>
      <td style="font-size:12.5px"><b><?= e($a['ttitle']) ?></b></td>
      <td><span class="fr-chip <?= $a['mode']==='full'?'purple':'info' ?>"><?= $a['mode']==='full'?'ویژه':'رایگان' ?></span></td>
      <td><?= $a['status']==='in_progress' ? '<span class="fr-chip warn">ناتمام ('.fa_num($a['progress']).' سؤال)</span>' : '<span class="fr-chip ok">تکمیل</span>' ?></td>
      <td style="font-size:12px"><?= fa_num(jdate('j F Y', strtotime($a['started_at']))) ?></td>
      <td class="actions" style="white-space:nowrap">
        <a class="fr-btn sm ghost" href="attempt-answers.php?attempt=<?= $a['id'] ?>" target="_blank" title="مشاهده و ویرایش سؤالات و پاسخ‌های کاربر در برگهٔ جدید">📝 پاسخ‌ها ↗</a>
        <?php if ($a['status']!=='in_progress'): ?>
        <a class="fr-btn sm" href="user-view.php?id=<?= $id ?>&report=<?= $a['id'] ?>" target="_blank">👁 کارنامه مراجع</a>
        <a class="fr-btn sm ghost" style="color:#059669;border-color:#059669;font-weight:600" href="<?= SITE_URL ?>/admin/report-pdf.php?attempt=<?= $a['id'] ?>&download=1" target="_blank" title="دانلود و دریافت مستقیم فایل معتبر PDF کارنامه">📥 دریافت فایل PDF</a>
        <a class="fr-btn sm" style="background:#4338ca;color:#fff" href="clinical-case.php?attempt=<?= $a['id'] ?>" target="_blank" title="خلاصه پرونده بالینی و تشخیصی مشاور">📋 پرونده بالینی مشاور</a>
        <form method="post" style="display:inline" onsubmit="return confirm('لینک گزارش برای کاربر پیامک شود؟')">
          <?= csrf_field() ?><input type="hidden" name="act" value="sms_report">
          <input type="hidden" name="attempt_id" value="<?= $a['id'] ?>">
          <button class="fr-btn sm ghost" title="ارسال لینک گزارش با پیامک">📩 لینک</button></form>
        <?php else: ?>
        <form method="post" style="display:inline" onsubmit="return confirm('پیامک یادآوری به همراه لینک ادامهٔ آزمون برای کاربر ارسال شود؟')">
          <?= csrf_field() ?><input type="hidden" name="act" value="sms_nudge">
          <input type="hidden" name="attempt_id" value="<?= $a['id'] ?>">
          <button class="fr-btn sm" style="background:#f59e0b;color:#fff" title="ارسال پیامک یادآوری با لینک ادامه تست">📲 پیامک ادامه</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3>پرداخت‌ها</h3>
    <table class="fr-table">
      <thead><tr><th>تست روانشناسی</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
      <tr><td style="font-size:12px"><?= e($o['ttitle']) ?></td>
          <td><?= fa_num(number_format($o['payable'])) ?></td>
          <td><span class="fr-chip <?= $o['status']==='paid'?'ok':'warn' ?>"><?= ['paid'=>'موفق','pending'=>'انتظار','failed'=>'خطا','canceled'=>'لغو','refunded'=>'برگشت'][$o['status']] ?? $o['status'] ?></span></td>
          <td style="font-size:12px"><?= fa_num(jdate('j F Y', strtotime($o['created_at']))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="fr-card" style="margin:0">
    <h3>آخرین فعالیت‌ها</h3>
    <table class="fr-table">
      <thead><tr><th>رویداد</th><th>زمان</th></tr></thead>
      <tbody>
      <?php $map = ['login'=>'ورود','logout'=>'خروج','otp_sent'=>'ارسال کد','pay_init'=>'اقدام به پرداخت','pay_ok'=>'پرداخت موفق','test_start'=>'شروع تست روانشناسی','test_done'=>'اتمام تست روانشناسی','report_view'=>'مشاهده گزارش','profile_update'=>'ویرایش پروفایل','ticket_new'=>'تیکت جدید','test_abandon'=>'انصراف تست روانشناسی'];
      foreach ($logs as $l): ?>
      <tr><td style="font-size:12px"><?= e($map[$l['action']] ?? $l['action']) ?></td>
          <td style="font-size:12px"><?= fa_num(jdate('j F - H:i', strtotime($l['created_at']))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<a class="fr-btn ghost" href="users.php">→ بازگشت به فهرست کاربران</a>
<div class="fr-card">
  <h3>👛 تعدیل کیف پول</h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="wallet_adjust">
    <div class="fr-field" style="margin:0"><label>مبلغ (تومان) — مثبت=افزایش، منفی=کسر</label>
      <input class="fr-input" name="wallet_amount" inputmode="numeric" placeholder="+ مثل ۲۰۰۰۰۰ یا −۵۰۰۰۰" required></div>
    <div class="fr-field" style="margin:0;flex:1;min-width:200px"><label>شرح</label>
      <input class="fr-input" name="wallet_desc" placeholder="مثلاً هدیهٔ خوش‌آمد یا تعدیل عودتی"></div>
    <button class="fr-btn sm">ثبت تعدیل</button>
  </form>
  <?php
  $wtx = wallet_transactions($id, 15);
  if ($wtx):
  ?>
  <table class="fr-table" style="margin-top:14px">
    <thead><tr><th>شرح</th><th>نوع</th><th>مبلغ</th><th>تاریخ</th></tr></thead>
    <tbody>
    <?php foreach ($wtx as $w): ?>
    <tr>
      <td style="font-size:12px"><?= e($w['description'] ?: '—') ?></td>
      <td style="font-size:11.5px;color:var(--muted)"><?= ['credit'=>'شارژ','debit'=>'برداشت','refund'=>'عودت','gift'=>'هدیه','adjustment'=>'تعدیل'][$w['kind']] ?? $w['kind'] ?></td>
      <td style="font-weight:800;color:<?= (int)$w['amount']>=0?'#0c7a4d':'#b3261e' ?>"><?= (int)$w['amount']>=0?'+':'−' ?><?= toman(abs((int)$w['amount'])) ?></td>
      <td style="font-size:11px;color:var(--muted)"><?= fa_num(jdate('j F Y - H:i', strtotime($w['created_at']))) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php admin_layout_end(); ?>
