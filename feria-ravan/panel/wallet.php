<?php
/**
 * =============================================================================
 *  کیف پول و عودت وجه (پنل کاربر) — نسخهٔ ۱٫۶
 *  ─ موجودی، تاریخچهٔ تراکنش‌ها، کارت بانکی و درخواست‌های عودت وجه
 *  ─ عودت وجه به‌صورت واریز دستی توسط مدیر یا شارژ کیف پول انجام می‌شود
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/wallet.php';
fr_bootstrap();
$u = require_user();
fr_panel_guard('wallet');

$okMsg = ''; $errMsg = '';

/* ثبت کارت بانکی برای واریز عودت وجه — نسخهٔ ۱٫۷: کارت «یک‌بار» ثبت می‌شود و پس از آن
   به‌خاطر امنیت حساب، فقط از طریق تیکت پشتیبانی (با احراز مدیرکل) قابل تغییر است. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['act'] ?? '') === 'save_card') {
    if (fr_has_bank_card($u)) {
        $errMsg = 'شماره کارت شما قبلاً ثبت شده است؛ برای تغییر آن لطفاً از «تیکت پشتیبانی» درخواست دهید.';
    } else {
        $r = fr_set_bank_card((int)$u['id'], (string)($_POST['bank_card'] ?? ''));
        if ($r['ok']) { $okMsg = 'شماره کارت با موفقیت ثبت شد ✅'; log_activity('bank_card_set', 'user', (int)$u['id']); }
        else          { $errMsg = $r['message']; }
        $u = current_user() ?: $u;
    }
}

$bal  = wallet_balance((int)$u['id']);
$rows = wallet_transactions((int)$u['id']);
$reqs = DB::all('SELECT * FROM ' . DB::t('refund_requests') . ' WHERE user_id=? ORDER BY id DESC', [$u['id']]);

$kindFa = ['credit'=>['ok','شارژ'],'debit'=>['warn','برداشت'],'refund'=>['info','عودت وجه'],
           'gift'=>['ok','هدیه 🎁'],'adjustment'=>['info','تعدیل توسط مدیر']];
$stMap  = ['pending'=>['warn','در انتظار بررسی'],'approved'=>['info','تأیید شده — در انتظار واریز'],
           'rejected'=>['err','رد شده'],'paid'=>['ok','واریز شد ✅']];

render_header('کیف پول');
panel_layout($u, 'wallet');
?>
<?php if ($okMsg): ?><div class="fr-alert ok" style="margin-bottom:14px"><?= e($okMsg) ?></div><?php endif; ?>
<?php if ($errMsg): ?><div class="fr-alert err" style="margin-bottom:14px"><?= e($errMsg) ?></div><?php endif; ?>

<div class="wl-grid">
  <div>
    <div class="fr-card" style="text-align:center;background:linear-gradient(135deg,#faf8ff,#f3efff)">
      <small style="color:var(--muted)">💰 موجودی قابل استفاده</small>
      <div style="font-size:30px;font-weight:900;color:var(--c-primary);margin-top:6px"><?= toman($bal) ?></div>
      <p style="font-size:12px;color:var(--muted);line-height:1.9;margin:12px 0 0">
        هر مبلغی که به کیف پولتان برگردد (مثل عودت وجه یا هدیهٔ مدیر)، در خرید بعدی
        می‌توانید با همان موجودی، کل یا بخشی از هزینه را بپردازید.
      </p>
    </div>

    <div class="fr-card">
      <h3 style="font-size:14px">💳 کارت بانکی برای واریز عودت وجه</h3>
      <?php if (!empty($u['bank_card_last4'])): ?>
      <!-- کارت قفل‌شده — نسخهٔ ۱٫۷: ویرایش فقط از مسیر تیکت با تأیید مدیرکل -->
      <p style="font-size:13px;margin:0 0 10px">کارت ثبت‌شده: <b style="direction:ltr;unicode-bidi:embed">**** **** **** <?= e($u['bank_card_last4']) ?></b>
        <span class="fr-chip ok" style="font-size:10px">قفل امنیتی 🔒</span></p>
      <div class="fr-alert info" style="font-size:12px;margin:0;line-height:2">
        برای حفاظت از حساب شما، شماره کارت پس از ثبت قابل‌ویرایش یا حذف نیست.
        اگر نیاز به تغییرش دارید، از «تیکت پشتیبانی» درخواست دهید تا پس از احراز هویت توسط مدیرکل انجام شود.
      </div>
      <a class="fr-btn ghost sm" style="margin-top:10px"
         href="tickets.php?subject=<?= urlencode('درخواست تغییر کارت بانکی') ?>&body=<?= urlencode('سلام، درخواست تغییر شماره کارت بانکی‌ام (عودت وجه) را دارم. شماره کارت جدید:') ?>">
        🎫 درخواست تغییر کارت از طریق تیکت ←</a>
      <?php else: ?>
      <p class="fr-alert warn" style="font-size:12px;margin:0 0 10px">هنوز کارتی ثبت نشده؛ برای درخواست عودت وجه لازم است.</p>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save_card">
        <input class="fr-input" name="bank_card" inputmode="numeric" maxlength="19" placeholder="شماره کارت ۱۶ رقمی (به نام خودتان)" style="flex:1;min-width:160px;direction:ltr;text-align:left" required>
        <button class="fr-btn sm" onclick="return confirm('⚠️ توجه: شماره کارت پس از ثبت قابل تغییر/حذف نیست (فقط از شرط تیکت با تأیید مدیرکل). مطمئن هستید؟')">ثبت کارت (یک‌بار)</button>
      </form>
      <small style="color:var(--muted);font-size:11.5px;line-height:1.8;display:block;margin-top:8px">
        🔒 شماره کارت رمزنگاری‌شده نزد ما می‌ماند و فقط چهار رقم آخرش دیده می‌شود.
        پس از ثبت، به‌خاطر امنیت حسابتان دیگر خودتان نمی‌توانید آن را ویرایش یا حذف کنید.
      </small>
      <?php endif; ?>
    </div>

    <?php if ($reqs): ?>
    <div class="fr-card">
      <h3 style="font-size:14px">↩️ درخواست‌های عودت وجه من</h3>
      <?php foreach ($reqs as $r): [$cls,$lbl] = $stMap[$r['status']] ?? ['info',$r['status']]; ?>
      <div style="border-bottom:1px dashed #eee;padding:9px 0;font-size:12.5px">
        <b><?= toman((int)$r['amount']) ?></b>
        <span class="fr-chip <?= $cls ?>" style="font-size:11px"><?= $lbl ?></span><br>
        <small style="color:var(--muted)">
          <?= $r['context'] === 'event' ? 'ثبت‌نام رویداد' : 'نوبت مشاوره' ?> · <?= fa_num(jdate('j F Y', strtotime($r['created_at']))) ?>
          <?php if ($r['refund_ref']): ?> · کد پیگیری: <bdi dir="ltr"><?= e($r['refund_ref']) ?></bdi><?php endif; ?>
          <?php if ($r['admin_note']): ?><br>پاسخ مدیر: <?= e($r['admin_note']) ?><?php endif; ?>
        </small>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="fr-card">
    <h3>تاریخچهٔ تراکنش‌های کیف پول</h3>
    <?php if (!$rows): ?>
    <p class="fr-alert info" style="margin:0">هنوز تراکنشی در کیف پولتان ثبت نشده است.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="fr-table">
      <thead><tr><th>شرح</th><th>نوع</th><th>مبلغ</th><th>تاریخ</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): [$cls,$lbl] = $kindFa[$r['kind']] ?? ['info',$r['kind']]; ?>
      <tr>
        <td style="font-size:12.5px"><?= e($r['description'] ?: '—') ?></td>
        <td><span class="fr-chip <?= $cls ?>" style="font-size:11px"><?= $lbl ?></span></td>
        <td style="font-weight:800;color:<?= (int)$r['amount'] >= 0 ? '#0c7a4d' : '#b3261e' ?>">
          <?= (int)$r['amount'] >= 0 ? '+' : '−' ?><?= toman(abs((int)$r['amount'])) ?></td>
        <td style="font-size:11.5px;color:var(--muted)"><?= fa_num(jdate('j F Y - H:i', strtotime($r['created_at']))) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php panel_layout_end(); render_footer(); ?>
