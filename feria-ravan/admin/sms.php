<?php
/** قالب‌های پیامک — شخصی‌سازی کامل متن‌ها + پترن کاوه‌نگار */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin','manager']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ((array)post('tpl', []) as $event => $t) {
        DB::update('sms_templates', [
            'enabled'      => !empty($t['enabled']) ? 1 : 0,
            'use_pattern'  => !empty($t['use_pattern']) ? 1 : 0,
            'pattern_code' => trim((string)($t['pattern_code'] ?? '')) ?: null,
            'body'         => (string)($t['body'] ?? ''),
        ], 'event=?', [$event]);
    }
    log_activity('admin_sms_tpl');
    flash('قالب‌های پیامک ذخیره شدند ✅');
    redirect(SITE_URL . '/admin/sms.php');
}

$rows = DB::all('SELECT * FROM ' . DB::t('sms_templates') . ' ORDER BY id');
$eventsFa = [
    'otp'                  => '📩 کد یک‌بارمصرف ورود',
    'register'             => '👋 خوش‌آمد پس از ثبت‌نام',
    'payment_ok'           => '✅ پس از پرداخت موفق (قالب پیش‌فرض)',
    'test_done'            => '🧠 پس از تکمیل تست روانشناسی',
    'report_free'          => '📄 پس از صدور تحلیل رایگان',
    'report_full'          => '⭐ پس از صدور تحلیل ویژه',
    // نسخهٔ ۱٫۷
    'admin_new_order'      => '🔔 اطلاع ثبت‌نام/خرید جدید به مدیران و شماره‌های دلخواه',
    'refund_request_user'  => '↩️ تأیید دریافت درخواست عودت به کاربر',
    'refund_request_admin' => '↩️ اطلاع درخواست عودت وجه به مدیران/حسابداری',
];

admin_layout($adm, 'sms', 'قالب‌های پیامک');
echo flash();
?>
<form method="post">
<?= csrf_field() ?>
<div class="fr-alert info" style="font-size:13px">
  متغیرهای قابل استفاده: <code>%name%</code> نام، <code>%mobile%</code> موبایل، <code>%code%</code> کد OTP،
  <code>%test%</code> عنوان تست روانشناسی، <code>%amount%</code> مبلغ، <code>%refid%</code> کد رهگیری،
  <code>%link%</code> لینک گزارش، <code>%site%</code> نام سایت، <code>%date%</code> تاریخ
</div>
<?php foreach ($rows as $r): ?>
<div class="fr-card">
  <h3><?= $eventsFa[$r['event']] ?? $r['event'] ?>
    <span class="fr-chip <?= $r['enabled']?'ok':'err' ?>"><?= $r['enabled']?'فعال':'غیرفعال' ?></span></h3>
  <div class="fr-grid cols-2" style="gap:16px">
    <div>
      <div class="fr-field"><label>متن پیامک (در صورت عدم استفاده از پترن)</label>
        <textarea class="fr-textarea" name="tpl[<?= e($r['event']) ?>][body]" rows="3" onkeyup="updateVars(this)"><?= e($r['body']) ?></textarea></div>
      <div style="display:flex;gap:20px">
        <label class="tog"><input type="checkbox" name="tpl[<?= e($r['event']) ?>][enabled]" <?= $r['enabled']?'checked':'' ?>> فعال</label>
        <label class="tog"><input type="checkbox" name="tpl[<?= e($r['event']) ?>][use_pattern]" <?= $r['use_pattern']?'checked':'' ?>> ارسال با پترن</label>
      </div>
    </div>
    <div class="fr-field"><label>کد پترن کاوه‌نگار (در صورت ارسال پترنی)</label>
      <input class="fr-input" name="tpl[<?= e($r['event']) ?>][pattern_code]" value="<?= e($r['pattern_code'] ?? '') ?>" style="direction:ltr" placeholder="pattern-code">
      <div class="hint">توکن‌های پترن به همان ترتیب متغیرهای %...% ارسال می‌شوند.</div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<button class="fr-btn">ذخیره همهٔ قالب‌ها</button>
</form>
<?php admin_layout_end(); ?>
