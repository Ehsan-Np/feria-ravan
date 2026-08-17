<?php
/**
 * مرکز اطلاع‌رسانی اجتماعی (تلگرام / بله) — نسخهٔ ۲٫۵
 * ─ تنظیم ربات‌ها • زمان‌بندی روزانهٔ ۲۴ساعته (تعداد دلخواه) • قالب پیام تست اتصال
 * ─ ارسال تست برای کاربر مشخص • ارسال گروهی به کاربران متصل • لاگ ارسال‌ها • راهنمای کرون cPanel
 * ─ ۲٫۵: تنظیمات یادآوری‌های اجتماعی رویدادها (جلسهٔ مشاوره، کارگاه/دوره، تست ناتمام)
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/snotify.php';
$adm = require_admin(['superadmin','manager']);
csrf_guard();

$act = (string)($_POST['act'] ?? '');
$canSettings = fr_admin_can($adm, 'settings');

/* ── ذخیرهٔ تنظیمات ربات‌ها + زمان‌بندی + قالب پیام تست ── */
if ($act === 'save') {
    if (!$canSettings) { flash('شما مجوز ویرایش تنظیمات را ندارید.', 'err'); redirect(SITE_URL . '/admin/notifications.php'); }
    DB::setSetting('telegram_bot_token', trim((string)($_POST['telegram_bot_token'] ?? '')));
    DB::setSetting('telegram_bot_username', ltrim(trim((string)($_POST['telegram_bot_username'] ?? '')), '@'));
    DB::setSetting('bale_bot_token', trim((string)($_POST['bale_bot_token'] ?? '')));
    DB::setSetting('bale_bot_username', ltrim(trim((string)($_POST['bale_bot_username'] ?? '')), '@'));
    $count = max(1, min(6, (int)($_POST['notif_daily_count'] ?? 3)));
    DB::setSetting('notif_daily_count', (string)$count);
    $times = [];
    foreach ((array)($_POST['notif_times'] ?? []) as $t) {
        $t = fr_valid_time((string)$t, '');
        if ($t !== '') $times[] = $t;   // فرمت ۲۴ ساعته HH:MM
    }
    sort($times);
    if (!$times) $times = ['08:30'];
    DB::setSetting('notif_times', json_encode(array_values(array_slice($times, 0, $count)), JSON_UNESCAPED_UNICODE));
    $msg = trim((string)($_POST['notif_test_msg'] ?? ''));
    if ($msg !== '') DB::setSetting('notif_test_msg', mb_substr($msg, 0, 600));
    log_activity('notif_settings_save', 'admin', (int)$adm['id'], ['count' => $count, 'times' => $times]);
    flash('تنظیمات اطلاع‌رسانی ذخیره شد ✅');
    redirect(SITE_URL . '/admin/notifications.php');
}

/* ── ۲٫۵: ذخیرهٔ تنظیمات یادآوری‌های اجتماعی رویدادها ── */
if ($act === 'save_events') {
    if (!$canSettings) { flash('شما مجوز ویرایش تنظیمات را ندارید.', 'err'); redirect(SITE_URL . '/admin/notifications.php'); }
    DB::setSetting('social_event_remind', !empty($_POST['social_event_remind']) ? '1' : '0');
    DB::setSetting('consult_social_hours', (string)max(1, min(168, (int)($_POST['consult_social_hours'] ?? 24))));
    DB::setSetting('event_social_hours',  (string)max(1, min(240, (int)($_POST['event_social_hours'] ?? 24))));
    DB::setSetting('test_nudge_days',     (string)max(1, min(30,  (int)($_POST['test_nudge_days'] ?? 2))));
    log_activity('notif_event_remind_save', 'admin', (int)$adm['id'], ['on' => !empty($_POST['social_event_remind'])]);
    flash('تنظیمات یادآوری رویدادها ذخیره شد ✅');
    redirect(SITE_URL . '/admin/notifications.php#evr');
}

/* ── ۲٫۷: ذخیرهٔ تنظیمات درگاه سازمانی پیام‌رسان (بله) ── */
if ($act === 'save_gateway') {
    if (!$canSettings) { flash('شما مجوز ویرایش تنظیمات را ندارید.', 'err'); redirect(SITE_URL . '/admin/notifications.php#gw'); }
    DB::setSetting('bale_gateway_enabled', !empty($_POST['bale_gateway_enabled']) ? '1' : '0');
    DB::setSetting('bale_gateway_url', trim((string)($_POST['bale_gateway_url'] ?? '')));
    DB::setSetting('bale_gateway_token', trim((string)($_POST['bale_gateway_token'] ?? '')));
    log_activity('notif_gateway_save', 'admin', (int)$adm['id'], ['on' => !empty($_POST['bale_gateway_enabled'])]);
    flash('تنظیمات درگاه پیام‌رسان ذخیره شد ✅');
    redirect(SITE_URL . '/admin/notifications.php#gw');
}

/* ── تولید مجدد کلید کرون ── */
if ($act === 'regen_cron') {
    if (!$canSettings) { flash('شما مجوز این کار را ندارید.', 'err'); redirect(SITE_URL . '/admin/notifications.php'); }
    DB::setSetting('cron_secret', bin2hex(random_bytes(16)));
    flash('کلید مخفی کرون تازه شد 🔑 لینک‌های کرون قبلی از کار افتادند — نشانی جدید را در cPanel به‌روزرسانی کنید.');
    redirect(SITE_URL . '/admin/notifications.php#cron');
}

/* ── ارسال پیام تست برای کاربر مشخص ── */
if ($act === 'test_send') {
    $mob = trim(en_num((string)($_POST['mobile'] ?? '')));
    $ch  = (string)($_POST['channel'] ?? 'telegram');
    $uid = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE mobile=? LIMIT 1", [$mob]);
    if ($uid <= 0) {
        flash('کاربری با این موبایل یافت نشد.', 'err');
    } else {
        $r = fr_snotify_send_test($uid, $ch);
        flash(!empty($r['ok']) ? '✅ پیام تست ارسال شد.' : ('❌ ' . (string)($r['message'] ?? 'خطا')), !empty($r['ok']) ? 'ok' : 'err');
    }
    redirect(SITE_URL . '/admin/notifications.php');
}

/* ── ارسال گروهی ── */
if ($act === 'bulk_send') {
    $text = trim((string)($_POST['message'] ?? ''));
    $ch   = in_array(($_POST['channel'] ?? 'both'), ['both','telegram','bale'], true) ? (string)$_POST['channel'] : 'both';
    if ($text === '') {
        flash('متن پیام گروهی خالی است.', 'err');
    } else {
        $text = mb_substr($text, 0, 900);
        [$sent, $fail] = fr_snotify_bulk($ch, $text);
        log_activity('notif_bulk_send', 'admin', (int)$adm['id'], ['channel' => $ch, 'sent' => $sent, 'failed' => $fail]);
        flash("📣 ارسال گروهی انجام شد — موفق: " . fa_num($sent) . ($fail ? "، ناموفق: " . fa_num($fail) : ''), $fail && !$sent ? 'err' : 'ok');
    }
    redirect(SITE_URL . '/admin/notifications.php#bulk');
}

/* ── داده‌ها ── */
$s = fn(string $k, string $d = '') => (string)DB::setting($k, $d);
$slots  = fr_snotify_slots();
$count  = count($slots);
$stats  = [
    'tg'        => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE COALESCE(telegram_chat_id,'')<>''"),
    'bale'      => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE COALESCE(bale_chat_id,'')<>''"),
    'pending'   => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('social_links') . " WHERE status='pending'"),
    'sentToday' => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('notify_log') . " WHERE DATE(created_at)=CURDATE() AND status='sent'"),
    'failToday' => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('notify_log') . " WHERE DATE(created_at)=CURDATE() AND status='failed'"),
];
$recent = DB::all("SELECT n.*, u.first_name, u.last_name, u.mobile FROM " . DB::t('notify_log') . " n LEFT JOIN " . DB::t('users') . " u ON u.id=n.user_id ORDER BY n.id DESC LIMIT 40");
$cronKey = $s('cron_secret');
$cronUrl = SITE_URL . '/cron/notify.php?key=' . $cronKey;
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$cliPath = $docRoot !== '' ? $docRoot . '/cron/notify.php' : '/home/USER/public_html/cron/notify.php';
$chFa = fr_snotify_channels();
$kindFa = ['slot' => 'نوبت روزانه', 'test' => 'تست اتصال', 'connected' => 'اتصال موفق', 'bulk' => 'گروهی', 'planner' => 'یادآور پلنر', 'campaign' => 'کمپین تبلیغاتی', 'event' => 'یادآور رویداد'];
$evOn   = $s('social_event_remind', '1') === '1';
$remUrl = SITE_URL . '/cron/reminders.php?key=' . $cronKey;
$remCli = $docRoot !== '' ? $docRoot . '/cron/reminders.php' : '/home/USER/public_html/cron/reminders.php';

admin_layout($adm, 'notifications', '📣 اطلاع‌رسانی اجتماعی');
echo flash();
?>

<div class="fr-grid cols-4" style="margin-bottom:6px">
  <?php stat_card('🟦', 'متصل‌شدهٔ تلگرام', fa_num($stats['tg']), '#eaf4ff'); ?>
  <?php stat_card('🟩', 'متصل‌شدهٔ بله', fa_num($stats['bale']), '#ecfbf4'); ?>
  <?php stat_card('🕓', 'اتصال در انتظار', fa_num($stats['pending']), '#fff6e6'); ?>
  <?php stat_card('📤', 'ارسال امروز / ناموفق', fa_num($stats['sentToday']) . ' / ' . fa_num($stats['failToday']), '#f6f1ff'); ?>
</div>

<div class="fr-card">
  <h3>📣 اطلاع‌رسانی شبکه‌های اجتماعی <small style="color:var(--muted);font-weight:400">(تلگرام + بله — اتصال با نام کاربری، تست اتصال، زمان‌بندی ۲۴ساعته و ارسال گروهی — نسخهٔ ۲٫۳)</small></h3>
  <div class="fr-alert info" style="font-size:12.5px;line-height:2">
    <b>روش اتصال کاربران:</b> هر کاربر در پروفایلش <b>نام کاربری</b> اکانتش را می‌نویسد؛ بعد به ربات پیام /start (یا کد نمایشی‌اش) را می‌فرستد.
    کرونِ اطلاع‌رسانی هر ۵ دقیقه پیام‌های ورودی ربات را می‌خواند و <b>نام کاربری را به‌صورت خودکار به شناسهٔ عددی (Chat ID) تبدیل می‌کند</b> و اتصال را با پیام خوش‌آمد (همان قالب «پیام تست») تأیید می‌کند.
  </div>
</div>

<?php if ($canSettings): ?>
<!-- ═══ تنظیمات ربات‌ها + زمان‌بندی + قالب پیام تست ═══ -->
<div class="fr-card">
  <h3>⚙️ تنظیمات ربات‌ها، زمان‌بندی و پیام تست</h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="fr-grid cols-2">
      <div>
        <h4 style="margin:4px 0 8px;font-size:13.5px">🟦 ربات تلگرام</h4>
        <div class="fr-field"><label>توکن ربات (از BotFather@)</label><input class="fr-input" name="telegram_bot_token" dir="ltr" value="<?= e($s('telegram_bot_token')) ?>" placeholder="123456:ABC-DEF…"></div>
        <div class="fr-field"><label>نام کاربری ربات</label><input class="fr-input" name="telegram_bot_username" dir="ltr" value="<?= e($s('telegram_bot_username')) ?>" placeholder="FeriaRavanBot"></div>
      </div>
      <div>
        <h4 style="margin:4px 0 8px;font-size:13.5px">🟩 ربات بله</h4>
        <div class="fr-field"><label>توکن ربات (از bale.ai@)</label><input class="fr-input" name="bale_bot_token" dir="ltr" value="<?= e($s('bale_bot_token')) ?>" placeholder="123456:ABC-DEF…"></div>
        <div class="fr-field"><label>نام کاربری ربات بله</label><input class="fr-input" name="bale_bot_username" dir="ltr" value="<?= e($s('bale_bot_username')) ?>" placeholder="FeriaRavanBot"></div>
      </div>
    </div>
    <div style="border-top:1px dashed var(--line);margin:12px 0"></div>
    <div class="fr-grid cols-2">
      <div>
        <h4 style="margin:4px 0 8px;font-size:13.5px">🕑 زمان‌بندی روزانه <small style="color:var(--muted)">(قالب ساعت ۲۴ ساعته)</small></h4>
        <div class="fr-field"><label>تعداد اطلاع‌رسانی در روز</label>
          <select class="fr-select" name="notif_daily_count" id="ntCount">
            <?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>" <?= $count === $i ? 'selected' : '' ?>><?= fa_num($i) ?> نوبت در روز</option><?php endfor; ?>
          </select>
        </div>
        <div class="fr-grid cols-3" id="ntTimes" data-t24-wrap data-t24-count="#ntCount">
          <?php for ($i = 0; $i < 6; $i++): ?>
          <div class="fr-field nt-t" data-t24-item data-i="<?= $i ?>" style="margin:0<?= $i >= $count ? ';display:none' : '' ?>">
            <label>نوبت <?= fa_num($i + 1) ?> <small>(۲۴ ساعته)</small></label>
            <input class="fr-input" name="notif_times[]" dir="ltr" type="text" data-t24 inputmode="numeric" maxlength="5"
                   pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="08:30" style="text-align:center" value="<?= e($slots[$i] ?? '') ?>">
          </div>
          <?php endfor; ?>
        </div>
        <small class="hint">هر نوبت دقیقاً یک‌بار در روز و سر ساعتِ تعیین‌شده (با تلرانس ۳۰ دقیقهٔ کرون) برای همهٔ کاربران متصل ارسال می‌شود. محتوای نوبت‌ها خودکار است: خلاصهٔ برنامهٔ امروز، نکتهٔ انگیزشی و معرفی تازه‌ترین محتوا.</small>
      </div>
      <div>
        <h4 style="margin:4px 0 8px;font-size:13.5px">📩 قالب «پیام تست اتصال» <small style="color:var(--muted)">(همان پیام خوش‌آمد اتصال)</small></h4>
        <div class="fr-field">
          <textarea class="fr-input" name="notif_test_msg" rows="5" style="line-height:2"><?= e($s('notif_test_msg')) ?></textarea>
          <small class="hint">متغیرها: <code>{site}</code> نام سایت، <code>{name}</code> نام کاربر، <code>{bot}</code> نام کاربری ربات، <code>{channel}</code> نام پیام‌رسان. این پیام هنگام «تست اتصال» (از پنل کاربر یا همین‌جا) و لحظهٔ اتصال موفق ارسال می‌شود.</small>
        </div>
      </div>
    </div>
    <button class="fr-btn">ذخیرهٔ تنظیمات</button>
  </form>
</div>

<!-- ═══ ۲٫۵: یادآوری‌های اجتماعی رویدادها ═══ -->
<div class="fr-card" id="evr" style="border:1.5px dashed var(--c-primary)">
  <h3>⏰ یادآوری‌های اجتماعی رویدادها <small style="color:var(--muted);font-weight:400">(نسخهٔ ۲٫۵ — جدا از پیامک، از تلگرام و بله)</small></h3>
  <div class="fr-alert info" style="font-size:12.5px;line-height:2;margin-bottom:10px">
    این یادآوری‌ها <b>خودکار و فقط از طریق تلگرام/بله</b> (بدون هزینهٔ پیامک) در لحظهٔ مناسب ارسال می‌شوند:
    <b>جلسهٔ مشاورهٔ رزروشده</b> قبل از شروع، <b>کارگاه/دورهٔ ثبت‌نام‌شده</b> قبل از آغاز، و <b>تستِ ناتمام</b> پس از چند روز.
    هر یادآوری فقط <b>یک‌بار</b> برای هر مورد فرستاده می‌شود و کاربرانی که در پروفایلشان یادآوری را خاموش کرده‌اند دریافت نمی‌کنند.
    اجرای آن به کرون <code dir="ltr">reminders.php</code> (راهنمای کرون پایین صفحه) وابسته است. ساعت‌ها همه‌جا به‌صورت کامل <b>۲۴ ساعته</b> نمایش داده می‌شوند.
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save_events">
    <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;margin-bottom:12px;cursor:pointer">
      <input type="checkbox" name="social_event_remind" value="1" <?= $evOn ? 'checked' : '' ?>>
      <b>یادآوریٔ اجتماعی رویدادها فعال باشد</b>
    </label>
    <div class="fr-grid cols-3">
      <div class="fr-field"><label>یادآوری جلسهٔ مشاوره <small>(چند ساعت قبل)</small></label>
        <input class="fr-input" type="number" min="1" max="168" name="consult_social_hours" value="<?= e($s('consult_social_hours', '24')) ?>" dir="ltr" style="text-align:center"></div>
      <div class="fr-field"><label>یادآوری کارگاه / دوره <small>(چند ساعت قبل)</small></label>
        <input class="fr-input" type="number" min="1" max="240" name="event_social_hours" value="<?= e($s('event_social_hours', '24')) ?>" dir="ltr" style="text-align:center"></div>
      <div class="fr-field"><label>یادآوری تست ناتمام <small>(پس از چند روز)</small></label>
        <input class="fr-input" type="number" min="1" max="30" name="test_nudge_days" value="<?= e($s('test_nudge_days', '2')) ?>" dir="ltr" style="text-align:center"></div>
    </div>
    <button class="fr-btn">ذخیرهٔ تنظیمات یادآوری رویدادها</button>
  </form>
</div>
<?php endif; ?>

<!-- ═══ ۲٫۷: درگاه سازمانی پیام‌رسان بله (سرویس حقوقی نوین/کاوه‌نگار…) ═══ -->
<?php if ($canSettings): ?>
<div class="fr-card" id="gw" style="border:1.5px solid #0f766e">
  <h3>🌐 درگاه پیام‌رسان «بله» (ارسال سازمانی — نسخهٔ ۲٫۷)</h3>
  <div class="fr-alert info" style="font-size:12.5px;line-height:2.1">
    <b>نتیجهٔ بررسی فنی ما (شفاف):</b> در مستندات عمومی وب‌سرویس کاوه‌نگار (<span dir="ltr">github.com/kavenegar</span> و rest.html) تا این نگارش،
    <b>Endpoint عمومی برای ارسال خودکار در پیام‌رسان بله وجود ندارد</b>؛ بله خودش از مسیر
    <a href="https://bale.ai/org" target="_blank" rel="noopener">bale.ai/org</a> سرویس «ارسال پیام انبوه سازمانی» (ارزان‌تر از پیامک، بدون محدودیت کاراکتر)
    و OTP رایگان ارائه می‌دهد که فعال‌سازی‌اش تجاری است (ارتباط با <span dir="ltr">@businessbale</span>).
    <br><b>راه‌حل پیاده‌سازی‌شده:</b> همین‌جا یک «درگاه عمومی» آماده است — هرگاه هر ارائه‌دهنده‌ای (بله سازمانی، کاوه‌نگار یا…)
    نشانی وب‌سرویس ارسال در بله به شما داد، نشانی و کلید را وارد کنید؛ سیستم تمام ارسال‌های بله (کمپین‌ها، یادآورها، تست‌ها) را
    <b>به‌صورت خودکار از همان درگاه</b> عبور می‌دهد — بدون نیاز به تأیید کاربر در نیست. تا وقتی خاموش است، ربات رسمی tapi.bale.ai استفاده می‌شود.
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save_gateway">
    <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;margin-bottom:12px;cursor:pointer">
      <input type="checkbox" name="bale_gateway_enabled" value="1" <?= $s('bale_gateway_enabled','0') === '1' ? 'checked' : '' ?>>
      <b>ارسال‌های بله از «درگاه سازمانی» عبور کند (به‌جای ربات رسمی)</b>
    </label>
    <div class="fr-grid cols-2">
      <div class="fr-field"><label>نشانی Endpoint وب‌سرویس <small>(جای‌نگهدار {token} پشتیبانی می‌شود)</small></label>
        <input class="fr-input" name="bale_gateway_url" dir="ltr" value="<?= e($s('bale_gateway_url')) ?>" placeholder="https://api.example.com/v1/{token}/bale/sendMessage"></div>
      <div class="fr-field"><label>کلید/API‌Key درگاه</label>
        <input class="fr-input" name="bale_gateway_token" dir="ltr" value="<?= e($s('bale_gateway_token')) ?>" placeholder="API-KEY…"></div>
    </div>
    <button class="fr-btn">💾 ذخیرهٔ درگاه</button>
    <small class="hint" style="display:block;margin-top:8px">قرارداد: <code dir="ltr">POST JSON {"chat_id","text","token"}</code> به نشانی بالا؛ پاسخ‌های Bot API (<code dir="ltr">{ok:true}</code>) و قالب کاوه‌نگاری (<code dir="ltr">{return:{status:200}}</code>) هر دو پذیرفته می‌شوند.</small>
  </form>
</div>
<?php endif; ?>

<!-- ═══ تست اتصال برای کاربر مشخص ═══ -->
<div class="fr-card">
  <h3>🧪 ارسال پیام تست برای کاربر</h3>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <?= csrf_field() ?><input type="hidden" name="act" value="test_send">
    <div class="fr-field" style="min-width:200px;margin:0"><label>موبایل کاربر</label><input class="fr-input" name="mobile" dir="ltr" inputmode="numeric" maxlength="11" placeholder="09xxxxxxxxx" required></div>
    <div class="fr-field" style="min-width:160px;margin:0"><label>کانال</label>
      <select class="fr-select" name="channel"><?php foreach ($chFa as $k => $lbl): ?><option value="<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?></select>
    </div>
    <button class="fr-btn">ارسال پیام تست 📩</button>
  </form>
  <small class="hint">متنِ ارسالی دقیقاً قالبِ «پیام تست اتصال» بالاست — با این مضمون که ارتباط کاربر با ربات با موفقیت انجام شده و از این لحظه اطلاع‌رسانی‌ها کامل برایش ارسال می‌شود.</small>
</div>

<!-- ═══ ارسال گروهی ═══ -->
<div class="fr-card" id="bulk">
  <h3>📢 ارسال گروهی به کاربران متصل</h3>
  <p style="font-size:12.5px;color:var(--muted);margin:0 0 10px;line-height:2">
    پیام مجموعه (اطلاعیه، جشنواره، رویداد تازه…) برای همهٔ کاربرانی که در تلگرام/بله متصل‌اند ارسال می‌شود.
    تعداد گیرندهٔ فعلی: <b><?= fa_num($stats['tg'] + $stats['bale']) ?></b> اتصال.
  </p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="bulk_send">
    <div class="fr-field"><label>متن پیام</label><textarea class="fr-input" name="message" rows="4" maxlength="900" required placeholder="مثلاً: 📣 جشنوارهٔ تابستانهٔ تست‌های روانشناسی آغاز شد…" style="line-height:2"></textarea></div>
    <div class="fr-field" style="max-width:220px"><label>کانال ارسال</label>
      <select class="fr-select" name="channel">
        <option value="both">هر دو (تلگرام + بله)</option>
        <option value="telegram">فقط تلگرام</option>
        <option value="bale">فقط بله</option>
      </select>
    </div>
    <button class="fr-btn">ارسال گروهی 📢</button>
  </form>
</div>

<!-- ═══ راهنمای کرون cPanel ═══ -->
<div class="fr-card" id="cron" style="border:2px solid var(--c-primary)">
  <h3>⏰ راهنمای تنظیم کرون‌جاب در هاست cPanel</h3>
  <div style="font-size:13px;line-height:2.2">
    <b>برای اینکه اتصال کاربران خودکار شود و اطلاع‌رسانی‌های روزانه سرِ ساعت برسند، در هاست cPanel این کار را بکنید:</b>
    <ol style="padding-inline-start:20px">
      <li>در cPanel بخش <b>Cron Jobs</b> را باز کنید (گروه Advanced).</li>
      <li>روی <b>Add New Cron Job</b> بزنید و زمان‌بندی <b>هر ۵ دقیقه</b> را انتخاب کنید:
        <div dir="ltr" style="background:rgba(30,58,110,.06);border:1px solid var(--line);border-radius:10px;padding:8px 12px;font-family:monospace;margin:6px 0">*/5 * * * *</div>
        (Minute=*/5 ، Hour=* ، Day=* ، Month=* ، Weekday=*)</li>
      <li>یکی از دو روش زیر را در کادر <b>Command</b> قرار دهید (دو کرون‌جاب جدا — هر دو هر ۵ دقیقه):</li>
    </ol>
    <p style="margin:6px 0"><b>کرون ۱ — اتصال کاربران و اطلاع‌رسانی روزانه و کمپین‌ها <code dir="ltr">(notify.php)</code>؛ روش ۱ — اجرای فایل با PHP (پیشنهادی):</b></p>
    <div dir="ltr" style="background:#101f3d;color:#d7e3ff;border-radius:10px;padding:10px 14px;font-family:monospace;font-size:13px;word-break:break-all">/usr/bin/php -q <?= e($cliPath) ?> <?= e($cronKey) ?></div>
    <p style="margin:8px 0 4px"><b>روش ۲ — فراخوانی نشانی (اگر PHP CLI در هاست نیست):</b></p>
    <div dir="ltr" style="background:#101f3d;color:#d7e3ff;border-radius:10px;padding:10px 14px;font-family:monospace;font-size:13px;word-break:break-all">curl -s "<?= e($cronUrl) ?>" > /dev/null 2>&1</div>
    <p style="margin:10px 0 4px"><b>کرون ۲ — یادآوری پلنر و رویدادها (مشاوره/کارگاه/تست) <code dir="ltr">(reminders.php)</code>:</b></p>
    <div dir="ltr" style="background:#101f3d;color:#d7e3ff;border-radius:10px;padding:10px 14px;font-family:monospace;font-size:13px;word-break:break-all">/usr/bin/php -q <?= e($remCli) ?> <?= e($cronKey) ?></div>
    <p style="margin:8px 0 4px"><b>یا با نشانی:</b></p>
    <div dir="ltr" style="background:#101f3d;color:#d7e3ff;border-radius:10px;padding:10px 14px;font-family:monospace;font-size:13px;word-break:break-all">curl -s "<?= e($remUrl) ?>" > /dev/null 2>&1</div>
    <p style="margin:8px 0 0">
      <b>نکته‌ها:</b> کلید مخفی کرون به‌صورت خودکار ساخته شده و در نشانی بالا آمده است؛ آن را محرمانه نگه دارید.
      برای تست دستی می‌توانید <a href="<?= e($cronUrl) ?>&force=1" target="_blank" rel="noopener" style="word-break:break-all">همین نشانی را با force=1 در مرورگر باز کنید ↗</a> — خروجی متنی وضعیت هر نوبت را نشان می‌دهد (ولی فقط ارسال واقعیِ موعد‌دار انجام می‌دهد).
      <form method="post" style="display:inline" onsubmit="return confirm('کلید کرون تازه شود؟ لینک‌های قبلی از کار می‌افتند.')">
        <?= csrf_field() ?><input type="hidden" name="act" value="regen_cron">
        <button class="fr-btn sm ghost">تولید مجدد کلید 🔑</button>
      </form>
    </p>
  </div>
</div>

<!-- ═══ لاگ اخیر ═══ -->
<div class="fr-card">
  <h3>🗒 آخرین ارسال‌ها</h3>
  <?php if (!$recent): ?><p style="font-size:12.5px;color:var(--muted)">هنوز ارسالی ثبت نشده است.</p>
  <?php else: ?>
  <table class="fr-table" style="font-size:13px">
    <tr><th>#</th><th>کاربر</th><th>کانال</th><th>نوع</th><th>وضعیت</th><th>زمان</th></tr>
    <?php foreach ($recent as $n): ?>
    <tr>
      <td><?= fa_num($n['id']) ?></td>
      <td><?= e(trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? '')) ?: ($n['mobile'] ?? '—')) ?></td>
      <td><?= e($chFa[$n['channel']] ?? $n['channel']) ?></td>
      <td><?= e($kindFa[$n['msg_kind']] ?? $n['msg_kind']) ?><?= $n['slot_idx'] !== null ? ' — نوبت ' . fa_num((int)$n['slot_idx'] + 1) : '' ?></td>
      <td><?= $n['status'] === 'sent' ? '<span style="color:var(--c-accent)">✓</span>' : ('<span style="color:var(--c-danger)" title="' . e((string)$n['error']) . '">✗</span>') ?></td>
      <td style="white-space:nowrap"><?= fa_num(jdate('m/d H:i', strtotime($n['created_at']))) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

