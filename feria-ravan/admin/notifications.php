<?php
/**
 * مرکز اطلاع‌رسانی اجتماعی و پیام‌رسان‌ها (تلگرام / بله / روبیکا) — نسخهٔ ۴٫۹٫۰ (۱۴۰۵)
 * ─ تنظیم ربات‌ها و درگاه‌های ۳ پیام‌رسان: تلگرام (با پروکسی/آدرس سفارشی)، بله، روبیکا
 * ─ زمان‌بندی روزانهٔ ۲۴ساعته و اجرای هوشمند و خودکار سر وقت مشخص
 * ─ ارسال فوری و تست دستی (Dispatch Now)، ارسال تست برای کاربر، ارسال گروهی
 * ─ یادآوری رویدادها، تصفیه حساب، تست‌های ناتمام و لاگ یکپارچه
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/snotify.php';
$adm = require_admin(['superadmin','manager']);
csrf_guard();

$act = (string)($_POST['act'] ?? '');
$canSettings = fr_admin_can($adm, 'settings');

/* ── ذخیرهٔ تنظیمات پیام‌رسان‌ها + زمان‌بندی + قالب پیام تست ── */
if ($act === 'save') {
    if (!$canSettings) { flash('شما مجوز ویرایش تنظیمات را ندارید.', 'err'); redirect(SITE_URL . '/admin/notifications.php'); }

    // تلگرام
    DB::setSetting('telegram_bot_token', trim((string)($_POST['telegram_bot_token'] ?? '')));
    DB::setSetting('telegram_bot_username', ltrim(trim((string)($_POST['telegram_bot_username'] ?? '')), '@'));
    DB::setSetting('telegram_proxy', trim((string)($_POST['telegram_proxy'] ?? '')));
    DB::setSetting('telegram_api_url', trim((string)($_POST['telegram_api_url'] ?? '')));

    // بله
    DB::setSetting('bale_bot_token', trim((string)($_POST['bale_bot_token'] ?? '')));
    DB::setSetting('bale_bot_username', ltrim(trim((string)($_POST['bale_bot_username'] ?? '')), '@'));
    DB::setSetting('bale_api_url', trim((string)($_POST['bale_api_url'] ?? '')));

    // روبیکا
    DB::setSetting('rubika_bot_token', trim((string)($_POST['rubika_bot_token'] ?? '')));
    DB::setSetting('rubika_bot_username', ltrim(trim((string)($_POST['rubika_bot_username'] ?? '')), '@'));
    DB::setSetting('rubika_api_url', trim((string)($_POST['rubika_api_url'] ?? '')));

    // زمان‌بندی روزانه
    $count = max(1, min(6, (int)($_POST['notif_daily_count'] ?? 3)));
    DB::setSetting('notif_daily_count', (string)$count);
    $times = [];
    foreach ((array)($_POST['notif_times'] ?? []) as $t) {
        $t = fr_valid_time((string)$t, '');
        if ($t !== '') $times[] = $t;
    }
    sort($times);
    if (!$times) $times = ['08:30'];
    DB::setSetting('notif_times', json_encode(array_values(array_slice($times, 0, $count)), JSON_UNESCAPED_UNICODE));

    // قالب پیام تست
    $msg = trim((string)($_POST['notif_test_msg'] ?? ''));
    if ($msg !== '') DB::setSetting('notif_test_msg', mb_substr($msg, 0, 600));

    log_activity('notif_settings_save', 'admin', (int)$adm['id'], ['count' => $count, 'times' => $times]);
    flash('تنظیمات پیام‌رسان‌ها و زمان‌بندی با موفقیت ذخیره شد ✅');
    redirect(SITE_URL . '/admin/notifications.php');
}

/* ── اجرای فوری نوبت‌های زمان‌بندی‌شده (Dispatch Now) ── */
if ($act === 'dispatch_now') {
    $res = fr_scheduled_dispatch_all(true);
    $slotsCount = count($res['slots'] ?? []);
    flash("🚀 بررسی و ارسال آنی انجام شد: " . fa_num($slotsCount) . " نوبت پردازش گردید (یادآور پلنر: " . fa_num($res['planner'] ?? 0) . " | کمپین‌ها: " . fa_num($res['campaigns'] ?? 0) . ") ✅");
    redirect(SITE_URL . '/admin/notifications.php');
}

/* ── دریافت و همگام‌سازی پیام‌ها و اتصال کاربران (Polling) ── */
if ($act === 'poll_updates') {
    $res = fr_snotify_poll_updates();
    $totUpdates = ($res['telegram']['updates'] ?? 0) + ($res['bale']['updates'] ?? 0) + ($res['rubika']['updates'] ?? 0);
    $totBound   = ($res['telegram']['bound'] ?? 0) + ($res['bale']['bound'] ?? 0) + ($res['rubika']['bound'] ?? 0);
    flash("🔄 بررسی دریافت پیام‌ها انجام شد: " . fa_num($totUpdates) . " رویداد پیام خوانده شد و " . fa_num($totBound) . " کاربر جدید متصل شد ✅", 'ok');
    redirect(SITE_URL . '/admin/notifications.php');
}

/* ── ثبت وب‌هوک پیام‌رسان‌ها ── */
if ($act === 'set_webhooks') {
    $hookBase = rtrim(SITE_URL, '/') . '/api/bot-webhook.php';
    $messages = [];
    require_once __DIR__ . '/../core/Telegram.php';
    require_once __DIR__ . '/../core/Bale.php';

    [$okTg, $respTg] = Telegram::call('setWebhook', ['url' => $hookBase . '?channel=telegram']);
    $messages[] = 'تلگرام: ' . ($okTg ? 'وب‌هوک ست شد ✅' : ('خطا: ' . json_encode($respTg, JSON_UNESCAPED_UNICODE)));

    [$okBale, $respBale] = Bale::call('setWebhook', ['url' => $hookBase . '?channel=bale']);
    $messages[] = 'بله: ' . ($okBale ? 'وب‌هوک ست شد ✅' : ('خطا: ' . json_encode($respBale, JSON_UNESCAPED_UNICODE)));

    flash(implode('<br>', $messages), 'ok');
    redirect(SITE_URL . '/admin/notifications.php');
}

/* ── ذخیرهٔ تنظیمات یادآوری‌های اجتماعی رویدادها ── */
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

/* ── ذخیرهٔ درگاه سازمانی ── */
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
    flash('کلید مخفی کرون تازه شد 🔑 نشانی جدید را در cPanel هاست به‌روزرسانی کنید.');
    redirect(SITE_URL . '/admin/notifications.php#cron');
}

/* ── ارسال پیام تست برای کاربر مشخص ── */
if ($act === 'test_send') {
    $mob = trim(en_num((string)($_POST['mobile'] ?? '')));
    $ch  = (string)($_POST['channel'] ?? 'telegram');
    $uid = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE mobile=? LIMIT 1", [$mob]);
    if ($uid <= 0) {
        flash('کاربری با این شماره موبایل در سیستم یافت نشد.', 'err');
    } else {
        $r = fr_snotify_send_test($uid, $ch);
        flash(!empty($r['ok']) ? '✅ پیام تست با موفقیت ارسال شد.' : ('❌ ' . (string)($r['message'] ?? 'خطا در ارسال')), !empty($r['ok']) ? 'ok' : 'err');
    }
    redirect(SITE_URL . '/admin/notifications.php');
}

/* ── ارسال گروهی به پیام‌رسان‌ها ── */
if ($act === 'bulk_send') {
    $text = trim((string)($_POST['message'] ?? ''));
    $ch   = in_array(($_POST['channel'] ?? 'all'), ['all','both','telegram','bale','rubika'], true) ? (string)$_POST['channel'] : 'all';
    if ($text === '') {
        flash('متن پیام گروهی نمی‌تواند خالی باشد.', 'err');
    } else {
        $text = mb_substr($text, 0, 900);
        [$sent, $fail] = fr_snotify_bulk($ch, $text);
        log_activity('notif_bulk_send', 'admin', (int)$adm['id'], ['channel' => $ch, 'sent' => $sent, 'failed' => $fail]);
        flash("📣 ارسال گروهی انجام شد — موفق: " . fa_num($sent) . ($fail ? "، ناموفق: " . fa_num($fail) : ''), $fail && !$sent ? 'err' : 'ok');
    }
    redirect(SITE_URL . '/admin/notifications.php#bulk');
}

/* ── واکشی داده‌ها ── */
$s = fn(string $k, string $d = '') => (string)DB::setting($k, $d);
$slots = fr_snotify_slots();
$count = count($slots);

// تضمین ساختار جدول
fr_snotify_ensure_schema();

$stats = [
    'tg'        => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE COALESCE(telegram_chat_id,'')<>''"),
    'bale'      => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE COALESCE(bale_chat_id,'')<>''"),
    'rubika'    => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE COALESCE(rubika_chat_id,'')<>''"),
    'sentToday' => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('notify_log') . " WHERE DATE(created_at)=CURDATE() AND status='sent'"),
    'failToday' => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('notify_log') . " WHERE DATE(created_at)=CURDATE() AND status='failed'"),
];

$recent = DB::all("SELECT n.*, u.first_name, u.last_name, u.mobile FROM " . DB::t('notify_log') . " n LEFT JOIN " . DB::t('users') . " u ON u.id=n.user_id ORDER BY n.id DESC LIMIT 40");
$cronKey = $s('cron_secret');
if ($cronKey === '') {
    $cronKey = bin2hex(random_bytes(16));
    DB::setSetting('cron_secret', $cronKey);
}
$cronUrl = SITE_URL . '/cron/notify.php?key=' . $cronKey;
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$cliPath = $docRoot !== '' ? $docRoot . '/cron/notify.php' : '/home/USER/public_html/cron/notify.php';
$chFa = fr_snotify_channels();
$kindFa = [
    'slot'      => 'نوبت روزانه',
    'test'      => 'تست اتصال',
    'connected' => 'اتصال موفق',
    'bulk'      => 'ارسال گروهی',
    'planner'   => 'یادآور پلنر ۱۴۰۵',
    'campaign'  => 'کمپین تبلیغاتی',
    'event'     => 'یادآور رویداد',
];
$evOn = $s('social_event_remind', '1') === '1';
$remUrl = SITE_URL . '/cron/reminders.php?key=' . $cronKey;
$remCli = $docRoot !== '' ? $docRoot . '/cron/reminders.php' : '/home/USER/public_html/cron/reminders.php';

admin_layout($adm, 'notifications', '📣 مرکز اطلاع‌رسانی پیام‌رسان‌ها (تلگرام / بله / روبیکا)');
echo flash();
?>

<!-- کارت‌های آمار ۴ گانه پیام‌رسان‌ها -->
<div class="fr-grid cols-4" style="margin-bottom:14px">
  <?php stat_card('🟦', 'متصل تلگرام', fa_num($stats['tg']), '#eaf4ff'); ?>
  <?php stat_card('🟩', 'متصل بله', fa_num($stats['bale']), '#ecfbf4'); ?>
  <?php stat_card('🟪', 'متصل روبیکا', fa_num($stats['rubika']), '#f5f3ff'); ?>
  <?php stat_card('📤', 'ارسال امروز / خطا', fa_num($stats['sentToday']) . ' / ' . fa_num($stats['failToday']), '#fff1f2'); ?>
</div>

<!-- کادر معرفی و اکشن اجرای آنی -->
<div class="fr-card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">
  <div>
    <h3 style="margin:0 0 6px 0">📣 اطلاع‌رسانی خودکار و زمان‌بندی‌شده پیام‌رسان‌ها <small style="color:var(--muted);font-weight:normal">(نگارش ۴٫۹٫۰ — سال ۱۴۰۵)</small></h3>
    <p style="margin:0;font-size:12.5px;color:var(--muted);line-height:1.9">
      پشتیبانی از ۳ پیام‌رسان اصلی: <b>تلگرام</b> (با امکان تنظیم پروکسی ضد فیلتر)، <b>بله</b> و <b>روبیکا</b>.
      ارسال نوبت‌ها در زمان‌های مشخص به صورت خودکار و دوگانه (کرون هاست + اعزام در درخواست‌ها) انجام می‌شود.
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <form method="post" style="margin:0" onsubmit="return confirm('تمامی نوبت‌های روزانه، یادآورهای پلنر و کمپین‌های موعددار همین الان بررسی و ارسال شوند؟')">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="dispatch_now">
      <button class="fr-btn" style="background:#059669;color:#fff">
        🚀 ارسال آنی نوبت‌ها (Dispatch Now)
      </button>
    </form>
    <form method="post" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="poll_updates">
      <button class="fr-btn ghost" style="color:#0284c7;border-color:#0284c7" title="بررسی پیام‌های جدید تلگرام و بله جهت اتصال خودکار">
        🔄 دریافت و اتصال پیام‌ها (Polling)
      </button>
    </form>
    <form method="post" style="margin:0" onsubmit="return confirm('آیا وب‌هوک تلگرام و بله برای دامنه سایت ثبت شوند؟')">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="set_webhooks">
      <button class="fr-btn ghost" style="color:#7c3aed;border-color:#7c3aed" title="تنظیم خودکار نشانی Webhook روی سرور تلگرام و بله">
        🔗 ثبت خودکار Webhook پیام‌رسان‌ها
      </button>
    </form>
  </div>
</div>

<?php if ($canSettings): ?>
<!-- ═══ تنظیمات پیام‌رسان‌ها: تلگرام، بله، روبیکا + زمان‌بندی ۲۴ ساعته ═══ -->
<div class="fr-card" style="margin-bottom:16px">
  <h3>⚙️ تنظیمات ربات‌ها و درگاه‌های پیام‌رسان</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">

    <div class="fr-grid cols-3" style="gap:14px">
      <!-- ستون ۱: تلگرام -->
      <div style="background:#f8fafc;padding:14px;border-radius:12px;border:1px solid #e2e8f0">
        <h4 style="margin:0 0 10px 0;font-size:13.5px;color:#0284c7">🟦 ربات تلگرام (Telegram)</h4>
        <div class="fr-field">
          <label>توکن ربات (BotFather@)</label>
          <input class="fr-input" name="telegram_bot_token" dir="ltr" value="<?= e($s('telegram_bot_token')) ?>" placeholder="123456:ABC-DEF…">
        </div>
        <div class="fr-field">
          <label>نام کاربری ربات</label>
          <input class="fr-input" name="telegram_bot_username" dir="ltr" value="<?= e($s('telegram_bot_username')) ?>" placeholder="FeriaBot">
        </div>
        <div class="fr-field">
          <label>پروکسی (ضد فیلتر سرور ایران)</label>
          <input class="fr-input" name="telegram_proxy" dir="ltr" value="<?= e($s('telegram_proxy')) ?>" placeholder="socks5h://127.0.0.1:1080 یا http://...">
          <small class="hint">در سرورهای داخل کشور برای عبور از فیلترینگ تلگرام</small>
        </div>
        <div class="fr-field">
          <label>آدرس سفارشی API (Mirror / Proxy)</label>
          <input class="fr-input" name="telegram_api_url" dir="ltr" value="<?= e($s('telegram_api_url')) ?>" placeholder="https://api.telegram.org">
        </div>
      </div>

      <!-- ستون ۲: بله -->
      <div style="background:#f8fafc;padding:14px;border-radius:12px;border:1px solid #e2e8f0">
        <h4 style="margin:0 0 10px 0;font-size:13.5px;color:#059669">🟩 ربات بله (Bale)</h4>
        <div class="fr-field">
          <label>توکن ربات (از BotFather در بله)</label>
          <input class="fr-input" name="bale_bot_token" dir="ltr" value="<?= e($s('bale_bot_token')) ?>" placeholder="123456:ABC-DEF…">
        </div>
        <div class="fr-field">
          <label>نام کاربری ربات بله</label>
          <input class="fr-input" name="bale_bot_username" dir="ltr" value="<?= e($s('bale_bot_username')) ?>" placeholder="FeriaBot">
        </div>
        <div class="fr-field">
          <label>نشانی سرور API بله</label>
          <input class="fr-input" name="bale_api_url" dir="ltr" value="<?= e($s('bale_api_url', 'https://tapi.bale.ai')) ?>" placeholder="https://tapi.bale.ai">
        </div>
      </div>

      <!-- ستون ۳: روبیکا -->
      <div style="background:#f8fafc;padding:14px;border-radius:12px;border:1px solid #e2e8f0">
        <h4 style="margin:0 0 10px 0;font-size:13.5px;color:#7c3aed">🟪 ربات و درگاه روبیکا (Rubika)</h4>
        <div class="fr-field">
          <label>توکن / کلید دسترسی ربات روبیکا</label>
          <input class="fr-input" name="rubika_bot_token" dir="ltr" value="<?= e($s('rubika_bot_token')) ?>" placeholder="کلید احراز / Token روبیکا…">
        </div>
        <div class="fr-field">
          <label>شناسه یا نام کاربری ربات روبیکا</label>
          <input class="fr-input" name="rubika_bot_username" dir="ltr" value="<?= e($s('rubika_bot_username')) ?>" placeholder="feria_rubika_bot">
        </div>
        <div class="fr-field">
          <label>نشانی Endpoint وب‌سرویس روبیکا</label>
          <input class="fr-input" name="rubika_api_url" dir="ltr" value="<?= e($s('rubika_api_url', 'https://botapi.rubika.ir/v1')) ?>" placeholder="https://botapi.rubika.ir/v1">
          <small class="hint">پشتیبانی از Bot API، وب‌هوک و وب‌سرویس‌های سازمانی پیام‌رسان</small>
        </div>
      </div>
    </div>

    <!-- تنظیمات زمان‌بندی روزانه ۲۴ ساعته -->
    <div style="border-top:1px dashed var(--line);margin:16px 0"></div>
    <div class="fr-grid cols-2" style="gap:16px">
      <div>
        <h4 style="margin:0 0 8px 0;font-size:13.5px">🕑 زمان‌بندی نوبت‌های اطلاع‌رسانی خودکار روزانه (۲۴ ساعته)</h4>
        <div class="fr-field">
          <label>تعداد نوبت‌های ارسال در طول روز:</label>
          <select class="fr-select" name="notif_daily_count" id="ntCount">
            <?php for ($i = 1; $i <= 6; $i++): ?>
            <option value="<?= $i ?>" <?= $count === $i ? 'selected' : '' ?>><?= fa_num($i) ?> نوبت در روز</option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="fr-grid cols-3" id="ntTimes">
          <?php for ($i = 0; $i < 6; $i++): ?>
          <div class="fr-field" style="margin:0<?= $i >= $count ? ';display:none' : '' ?>">
            <label>نوبت <?= fa_num($i + 1) ?> <small>(ساعت)</small></label>
            <input class="fr-input" name="notif_times[]" dir="ltr" type="text" maxlength="5"
                   pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="08:30" style="text-align:center"
                   value="<?= e($slots[$i] ?? '') ?>">
          </div>
          <?php endfor; ?>
        </div>
        <small class="hint" style="display:block;margin-top:8px">
          پیام‌های هر نوبت شامل تقویم شمسی ۱۴۰۵، یادآوری تمرین‌های باقیماندهٔ پلنر و جملات انگیزشی خودکار است و دقیقاً سر ساعت مقرر برای تمام کاربران متصل فرستاده می‌شود.
        </small>
      </div>

      <div>
        <h4 style="margin:0 0 8px 0;font-size:13.5px">📩 قالب پیام تست اتصال و خوش‌آمد</h4>
        <div class="fr-field">
          <textarea class="fr-input" name="notif_test_msg" rows="5" style="line-height:2"><?= e($s('notif_test_msg')) ?></textarea>
          <small class="hint">متغیرهای مجاز: <code>{site}</code> نام سایت، <code>{name}</code> نام مراجع، <code>{bot}</code> شناسه ربات، <code>{channel}</code> نام پیام‌رسان.</small>
        </div>
      </div>
    </div>

    <button class="fr-btn" style="margin-top:12px">💾 ذخیرهٔ تنظیمات پیام‌رسان‌ها</button>
  </form>
</div>
<?php endif; ?>

<!-- ═══ تست اتصال به کاربر مشخص روی تلگرام، بله، روبیکا ═══ -->
<div class="fr-card" style="margin-bottom:16px">
  <h3>🧪 ارسال پیام تست اتصال به کاربر</h3>
  <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="test_send">
    <div class="fr-field" style="min-width:220px;margin:0">
      <label>شماره موبایل کاربر</label>
      <input class="fr-input" name="mobile" dir="ltr" inputmode="numeric" maxlength="11" placeholder="09xxxxxxxxx" required>
    </div>
    <div class="fr-field" style="min-width:180px;margin:0">
      <label>پیام‌رسان هدف</label>
      <select class="fr-select" name="channel">
        <?php foreach ($chFa as $k => $lbl): ?>
        <option value="<?= $k ?>"><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="fr-btn">ارسال پیام تست 📩</button>
  </form>
</div>

<!-- ═══ ارسال گروهی به تلگرام، بله، روبیکا ═══ -->
<div class="fr-card" id="bulk" style="margin-bottom:16px">
  <h3>📢 ارسال گروهی پیام به کاربران متصل</h3>
  <p style="font-size:12.5px;color:var(--muted);margin:0 0 10px;line-height:2">
    مجموع مراجعین متصل در پیام‌رسان‌ها: <b><?= fa_num($stats['tg'] + $stats['bale'] + $stats['rubika']) ?></b> اتصال فعال.
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="bulk_send">
    <div class="fr-field">
      <label>متن پیام اطلاع‌رسانی</label>
      <textarea class="fr-input" name="message" rows="4" maxlength="900" required placeholder="متن پیام عمومی یا اطلاعیه جشنواره…" style="line-height:2"></textarea>
    </div>
    <div class="fr-field" style="max-width:240px">
      <label>کانال ارسال</label>
      <select class="fr-select" name="channel">
        <option value="all">🌟 همهٔ پیام‌رسان‌ها (تلگرام + بله + روبیکا)</option>
        <option value="telegram">🟦 فقط تلگرام</option>
        <option value="bale">🟩 فقط بله</option>
        <option value="rubika">🟪 فقط روبیکا</option>
      </select>
    </div>
    <button class="fr-btn">ارسال گروهی پیام 📢</button>
  </form>
</div>

<!-- ═══ راهنمای کرون سرور و هاست ═══ -->
<div class="fr-card" id="cron" style="border:2px solid #1e3a6e;border-radius:14px;margin-bottom:16px">
  <h3>⏰ راهنمای پیکربندی کرون‌جاب (Cron Jobs) در cPanel / سرور</h3>
  <div style="font-size:13px;line-height:2.2">
    <p>
      سامانه مجهز به <b>سیستم اعزام خودکار هوشمند (Opportunistic Auto-Dispatch)</b> است که پیام‌های زمان‌بندی‌شده را با بازدیدهای وب نیز اجرا می‌کند.
      با این حال جهت ارسال ۱۰۰٪ دقیق در ثانیه‌ها حتی در زمان‌های بدون بازدید، دستور کرون زیر را در cPanel اضافه کنید:
    </p>
    <div dir="ltr" style="background:#101f3d;color:#d7e3ff;border-radius:10px;padding:12px 16px;font-family:monospace;font-size:13px;word-break:break-all;margin-bottom:8px">
      /usr/bin/php -q <?= e($cliPath) ?> <?= e($cronKey) ?>
    </div>
    <p style="margin:0">
      نشانی وب برای کرون خارجی: <code dir="ltr"><?= e($cronUrl) ?></code>
    </p>
  </div>
</div>

<!-- ═══ جدول لاگ آخرین ارسال‌ها ═══ -->
<div class="fr-card">
  <h3>🗒 گزارش آخرین ارسال‌ها در پیام‌رسان‌ها</h3>
  <?php if (!$recent): ?>
  <p style="font-size:12.5px;color:var(--muted)">هنوز ارسالی در سیستم ثبت نشده است.</p>
  <?php else: ?>
  <div class="tbl-wrap">
    <table class="fr-table" style="font-size:12.5px">
      <thead>
        <tr>
          <th>#</th>
          <th>مراجع / شماره</th>
          <th>پیام‌رسان</th>
          <th>نوع پیام</th>
          <th>وضعیت</th>
          <th>زمان ارسال (۱۴۰۵)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $n): ?>
        <tr>
          <td><?= fa_num($n['id']) ?></td>
          <td><?= e(trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? '')) ?: ($n['mobile'] ?? '—')) ?></td>
          <td><?= e($chFa[$n['channel']] ?? $n['channel']) ?></td>
          <td><?= e($kindFa[$n['msg_kind']] ?? $n['msg_kind']) ?><?= $n['slot_idx'] !== null ? ' (نوبت ' . fa_num((int)$n['slot_idx'] + 1) . ')' : '' ?></td>
          <td><?= $n['status'] === 'sent' ? '<span style="color:#059669;font-weight:bold">✅ موفق</span>' : '<span style="color:#dc2626;font-weight:bold" title="' . e((string)$n['error']) . '">❌ ناموفق</span>' ?></td>
          <td style="white-space:nowrap"><?= fa_num(jdate('j F ۱۴۰۵ — H:i', strtotime($n['created_at']))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
// کنترل نمایش تعداد نوبت‌ها
(function(){
  var cSel = document.getElementById('ntCount');
  if (cSel) {
    cSel.addEventListener('change', function(){
      var val = parseInt(cSel.value, 10);
      var items = document.querySelectorAll('#ntTimes .fr-field');
      items.forEach(function(item, idx){
        item.style.display = idx < val ? 'block' : 'none';
      });
    });
  }
})();
</script>

<?php
admin_layout_end();
