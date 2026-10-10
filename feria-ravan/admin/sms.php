<?php
/**
 * ============================================================================
 *  قالب‌های پیامک — شخصی‌سازی کامل متن‌ها + پترن کاوه‌نگار (نسخهٔ ۳٫۴٫۶)
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin','manager']);

Kavenegar::ensureTemplatesSchema();
$kv = new Kavenegar();

/* ---------- پردازش فرم تست یا ذخیره ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act', '');

    // تست ارسال پیامک از صفحهٔ قالب‌ها
    if ($act === 'test_sms') {
        $mob = clean_mobile((string)post('test_mobile', ''));
        $testType = (string)post('test_type', 'direct');
        $msg = trim((string)post('test_message', ''));
        $pattern = trim((string)post('test_pattern', ''));
        $tokens = array_map('trim', explode(',', (string)post('test_tokens', '')));

        if (!valid_mobile($mob)) {
            flash('شماره موبایل گیرنده معتبر نیست (مثال: ۰۹۱۲۳۴۵۶۷۸۹).', 'err');
        } elseif (!$kv->isEnabled()) {
            flash('سامانه پیامک فعال نیست یا کلید API در تنظیمات ثبت نشده است.', 'err');
        } else {
            if ($testType === 'pattern') {
                $ok = $kv->sendPattern($mob, $pattern ?: 'otp', $tokens ?: ['12345'], 'admin_test');
                flash($ok ? "پیامک پترن با موفقیت به {$mob} ارسال شد ✅" : "ارسال پترن ناموفق بود. کد پترن یا وضعیت کاوه‌نگار را بررسی کنید.", $ok ? 'ok' : 'err');
            } else {
                $ok = $kv->sendTest($mob, $msg ?: 'تست درگاه پیامک فریا روان 🦢');
                flash($ok ? "پیامک تستی به {$mob} ارسال شد ✅" : "ارسال پیامک تستی ناموفق بود. اعتبار یا خط اختصاصی را بررسی کنید.", $ok ? 'ok' : 'err');
            }
        }
        redirect(SITE_URL . '/admin/sms.php');
    }

    // ذخیرهٔ قالب‌های پیامک
    foreach ((array)post('tpl', []) as $event => $t) {
        DB::update('sms_templates', [
            'enabled'      => !empty($t['enabled']) ? 1 : 0,
            'use_pattern'  => !empty($t['use_pattern']) ? 1 : 0,
            'pattern_code' => trim((string)($t['pattern_code'] ?? '')) ?: null,
            'body'         => (string)($t['body'] ?? ''),
        ], 'event=?', [(string)$event]);
    }
    log_activity('admin_sms_tpl');
    flash('قالب‌های پیامک با موفقیت ذخیره شدند ✅');
    redirect(SITE_URL . '/admin/sms.php');
}

$rows = DB::all('SELECT * FROM ' . DB::t('sms_templates') . ' ORDER BY id');
$eventsFa = [
    'otp'                  => '📩 کد یک‌بارمصرف ورود و ثبت‌نام',
    'register'             => '👋 خوش‌آمد پس از ثبت‌نام',
    'payment_ok'           => '✅ پس از پرداخت موفق (قالب پیش‌فرض)',
    'test_done'            => '🧠 پس از تکمیل تست روانشناسی',
    'report_free'          => '📄 پس از صدور تحلیل رایگان',
    'report_full'          => '⭐ پس از صدور تحلیل ویژه',
    'admin_new_order'      => '🔔 اطلاع ثبت‌نام/خرید جدید به مدیران',
    'refund_request_user'  => '↩️ تأیید دریافت درخواست عودت به کاربر',
    'refund_request_admin' => '↩️ اطلاع درخواست عودت وجه به مدیران/حسابداری',
    'test_nudge'           => '⏰ یادآوری آزمون نیمه‌تمام (Nudge)',
];

admin_layout($adm, 'sms', 'قالب‌های پیامک و اعلانات');
echo flash();
?>
<div class="fr-card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="font-size:17px;margin:0 0 4px">سامانه پیامک کاوه‌نگار 📩</h2>
      <p style="margin:0;font-size:12.5px;color:var(--muted)">
        وضعیت اتصال:
        <?php if ($kv->isEnabled()): ?>
          <b style="color:#059669">فعال و آمادهٔ ارسال ✅</b> (ارسال با پترن VerifyLookup و پیامک عادی)
        <?php else: ?>
          <b style="color:#dc2626">غیرفعال یا فاقد کلید API ❌</b> — جهت تنظیم به <a href="settings.php?tab=sms" style="text-decoration:underline">تنظیمات پیامک</a> بروید.
        <?php endif; ?>
      </p>
    </div>
    <a class="fr-btn sm ghost" href="settings.php?tab=sms">⚙️ پیکربندی درگاه پیامک</a>
  </div>
</div>

<form method="post">
<?= csrf_field() ?>
<div class="fr-alert info" style="font-size:13px;line-height:2">
  💡 <b>راهنمای متغیرها:</b>
  <code>%name%</code> نام کاربر ·
  <code>%mobile%</code> شماره موبایل ·
  <code>%code%</code> کد OTP ·
  <code>%test%</code> عنوان آزمون ·
  <code>%amount%</code> مبلغ (تومان) ·
  <code>%refid%</code> کد رهگیری پرداخت ·
  <code>%link%</code> پیوند گزارش ·
  <code>%site%</code> عنوان سایت ·
  <code>%date%</code> تاریخ و زمان<br>
  ⚡ در صورت فعال‌سازی <b>«ارسال با پترن»</b>، پیامک بدون فیلتر بلک‌لیست مخابرات و با سرعت بالا توسط وب‌سرویس اعتبارسنجی (VerifyLookup) کاوه‌نگار ارسال می‌شود.
</div>

<?php foreach ($rows as $r): ?>
<div class="fr-card" style="margin-bottom:16px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h3 style="margin:0;font-size:15px"><?= $eventsFa[$r['event']] ?? ($r['title'] ?: $r['event']) ?></h3>
    <div style="display:flex;gap:6px;align-items:center">
      <?php if (!empty($r['use_pattern'])): ?>
        <span class="fr-chip ok" style="font-size:11px">⚡ پترن فعال (<?= e($r['pattern_code'] ?: 'پیش‌فرض') ?>)</span>
      <?php else: ?>
        <span class="fr-chip warn" style="font-size:11px">متنی عادی</span>
      <?php endif; ?>
      <span class="fr-chip <?= $r['enabled'] ? 'ok' : 'err' ?>"><?= $r['enabled'] ? 'فعال' : 'غیرفعال' ?></span>
    </div>
  </div>

  <div class="fr-grid cols-2" style="gap:16px">
    <div>
      <div class="fr-field">
        <label>متن پیامک (ارسال مستقیم یا فال‌بک در صورت قطعی پترن)</label>
        <textarea class="fr-textarea" name="tpl[<?= e($r['event']) ?>][body]" rows="3"><?= e($r['body']) ?></textarea>
      </div>
      <div style="display:flex;gap:20px;align-items:center;margin-top:8px">
        <label class="tog"><input type="checkbox" name="tpl[<?= e($r['event']) ?>][enabled]" <?= $r['enabled'] ? 'checked' : '' ?>> فعال بودن ارسال این رویداد</label>
        <label class="tog"><input type="checkbox" name="tpl[<?= e($r['event']) ?>][use_pattern]" <?= !empty($r['use_pattern']) ? 'checked' : '' ?>> اولویت با پترن کاوه‌نگار</label>
      </div>
    </div>
    <div>
      <div class="fr-field">
        <label>کد پترن (الگوی تعریف‌شده در پنل کاوه‌نگار)</label>
        <input class="fr-input" name="tpl[<?= e($r['event']) ?>][pattern_code]" value="<?= e($r['pattern_code'] ?? '') ?>" style="direction:ltr" placeholder="مثال: <?= $r['event'] === 'otp' ? 'otp' : 'notice' ?>">
        <div class="hint" style="margin-top:6px;font-size:11.5px;color:var(--muted)">
          توکن‌های پترن به ترتیبِ متغیرها به عنوان <code>token</code>، <code>token2</code>، <code>token3</code> و... به کاوه‌نگار ارسال می‌شوند. برای کد یک‌بارمصرف، توکن اول همان کد عددی خواهد بود.
        </div>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<button class="fr-btn" style="margin-bottom:28px">💾 ذخیره تغییرات همهٔ قالب‌ها</button>
</form>

<!-- تست سریع ارسال پیامک -->
<div class="fr-card">
  <h3 style="margin-top:0">🧪 تست سریع ارسال پیامک</h3>
  <p style="font-size:12.5px;color:var(--muted)">جهت اطمینان از برقراری ارتباط با وب‌سرویس کاوه‌نگار و بررسی تحویل به خط همراه شما:</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="test_sms">
    <div class="fr-grid cols-3" style="gap:12px">
      <div class="fr-field">
        <label>شماره موبایل گیرنده</label>
        <input class="fr-input" name="test_mobile" placeholder="۰۹۱۲۳۴۵۶۷۸۹" style="direction:ltr;text-align:center" required>
      </div>
      <div class="fr-field">
        <label>نوع تست</label>
        <select class="fr-select" name="test_type" id="testTypeSelect" onchange="toggleTestFields(this.value)">
          <option value="direct">پیامک متنی عادی (send)</option>
          <option value="pattern">ارسال با پترن / اعتبارسنجی (verify/lookup)</option>
        </select>
      </div>
      <div class="fr-field" id="fDirectMsg">
        <label>متن پیام آزمایشی</label>
        <input class="fr-input" name="test_message" value="تست اتصال درگاه پیامک فریا روان 🦢">
      </div>
      <div class="fr-field" id="fPatternCode" style="display:none">
        <label>نام الگوی کاوه‌نگار</label>
        <input class="fr-input" name="test_pattern" placeholder="otp" value="otp" style="direction:ltr">
      </div>
      <div class="fr-field" id="fPatternTokens" style="display:none">
        <label>توکن‌های الگو (با ویرگول انگلیسی)</label>
        <input class="fr-input" name="test_tokens" value="12345" style="direction:ltr" placeholder="12345,فریا روان">
      </div>
    </div>
    <button class="fr-btn ghost sm" style="margin-top:8px">ارسال پیامک آزمایشی 🚀</button>
  </form>
</div>

<script>
function toggleTestFields(val){
  const isPat = (val === 'pattern');
  document.getElementById('fDirectMsg').style.display = isPat ? 'none' : 'block';
  document.getElementById('fPatternCode').style.display = isPat ? 'block' : 'none';
  document.getElementById('fPatternTokens').style.display = isPat ? 'block' : 'none';
}
</script>

<?php admin_layout_end(); ?>
