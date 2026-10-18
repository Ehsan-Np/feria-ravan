<?php
/** تنظیمات کلی — برندینگ، فونت، درگاه، پیامک، گزارش + تست پنل پیامک */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/planner.php';
$adm = require_admin(['superadmin']);
$tab = (string)($_GET['tab'] ?? 'brand');

/* ذخیره تنظیمات */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    csrf_guard();
    /* ۲٫۴: نوبت‌های یادآوری پلنر (JSON چندنوبتی) پیش از حلقهٔ عمومی هندل می‌شود */
    if (isset($_POST['planner_remind_times']) || isset($_POST['planner_remind_count'])) {
        fr_planner_remind_save((int)($_POST['planner_remind_count'] ?? 1), (array)($_POST['planner_remind_times'] ?? []));
    } elseif (isset($_POST['planner_remind_time'])) {   // سازگاری با فرم تک‌نوبتی قدیمی
        fr_planner_remind_save(1, [(string)$_POST['planner_remind_time']]);
    }
    $keys = [
        'site_title','site_slogan','logo','logo_alt','footer_text','report_footer','currency_label',  // نسخهٔ ۱٫۷: logo_alt
        'site_title_en','brand_short_name','logo_dark','favicon','copyright_text',                   // نسخهٔ ۴٫۰: White-Label
        'hide_system_credits','support_phone','support_email','support_telegram','support_bale',
        'font_main','font_headings','color_primary','color_secondary','color_accent',
        'zarinpal_merchant','zarinpal_sandbox',
        'kavenegar_apikey','kavenegar_sender','kavenegar_enabled','kavenegar_otp_pattern',
        'otp_length','otp_ttl','telegram','instagram','support_mobile',
        'consult_hold_minutes','consult_cancel_hours',          // بوکینگ مشاوره (۱٫۴)
        'register_enabled',                                     // عضویت کاربران (۱٫۵ — پیش‌فرض فعال)
        'footer_credit_text','footer_credit_url',               // خط اعتباری توسعه‌دهنده (۱٫۶ — قابل ویرایش)
        'sms_tpl_consult_book_user','sms_tpl_consult_book_cons','sms_tpl_consult_cancel','sms_tpl_consult_remind',
        'consult_gap_default',                                  // تأخیر پیش‌فرض بین جلسات (۱٫۶)
        // نسخهٔ ۱٫۷: متن‌های قابل‌ویرایش صفحات عمومی + شماره‌های اطلاع‌رسانی مدیر + سئو
        'tests_hero_title','tests_hero_desc',                      // آرشیو تست‌های روانشناسی (۱٫۸)
        'workshops_hero_title','workshops_hero_desc',
        'courses_hero_title','courses_hero_desc',
        'consult_hero_title','consult_hero_desc',
        'admin_notify_mobiles','seo_home_desc',
        // نسخهٔ ۱٫۷: شخصی‌سازی کامل پنل کاربری (نمایش/پنهان‌سازی بخش‌ها + یادداشت داشبورد)
        'panel_menu_wallet','panel_menu_payments','panel_menu_history','panel_menu_tickets',
        'panel_title','panel_url','panel_slug','panel_dashboard_note','panel_sidebar_note',
        // نسخهٔ ۲٫۰: دیدگاهها + بارکد QR گزارش چاپی
        'reviews_auto_approve',
        'report_qr_link','report_qr_show',
        // نسخهٔ ۴٫۵٫۰ و ۴٫۹٫۴: اطلاعات ارائه‌دهنده، روانشناس ناظر، لوگو، مهر و تمپلیت استاندارد ISO A4 کارنامه و رنگ‌بندی چاپی
        'report_provider_name','report_provider_subtitle','report_provider_license',
        'report_doctor_name','report_doctor_title','report_doctor_license',
        'report_provider_logo','report_provider_stamp','report_show_signature_box',
        'report_provider_phone','report_provider_address','report_provider_website',
        'report_header_color','report_accent_color','report_chart_palette','report_pdf_engine',
        'report_disclaimer','report_show_qr','report_pdf_download_mode',
        // نسخهٔ ۲٫۱: برند فوتر + نمادها + برنامه‌ریز روزانه + پیام‌رسان‌ها + خبرنامه
        'logo_desc','footer_links_json','footer_links2_json',
        'footer_badge1_img','footer_badge1_url','footer_badge1_alt','footer_badge1_html',
        'footer_badge2_img','footer_badge2_url','footer_badge2_alt','footer_badge2_html',
        'footer_badge3_img','footer_badge3_url','footer_badge3_alt','footer_badge3_html',
        'footer_badge4_img','footer_badge4_url','footer_badge4_alt','footer_badge4_html',
        'newsletter_title','newsletter_desc',
        'planner_enabled','planner_streak_target','planner_reward_type','planner_reward_percent','planner_reward_message',
        'telegram_bot_token','telegram_bot_username','bale_bot_token','bale_bot_username',
        'rubika_bot_token','rubika_bot_username',
    ];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            $v = (string)$_POST[$k];
            if (in_array($k, ['color_primary','color_secondary','color_accent'], true)) {
                $v = preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '#1e3a6e';
            }
            if ($k === 'register_enabled') { $v = $v === '1' ? '1' : '0'; }
            if ($k === 'hide_system_credits') { $v = $v === '1' ? '1' : '0'; }
            if (in_array($k, ['reviews_auto_approve','report_qr_show','report_show_signature_box','report_show_qr'], true)) { $v = $v === '1' ? '1' : '0'; }
            if ($k === 'report_header_color') {
                $v = preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '#1e3a6e';
            }
            if ($k === 'report_accent_color') {
                $v = preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '#7c3aed';
            }
            if ($k === 'report_chart_palette') {
                $v = in_array($v, ['default', 'ocean', 'emerald', 'royal'], true) ? $v : 'default';
            }
            if ($k === 'report_pdf_engine') {
                $v = in_array($v, ['auto', 'dompdf', 'tcpdf'], true) ? $v : 'auto';
            }
            if ($k === 'report_qr_link') {                       // فقط لینک http(s) سالم پذیرفته می‌شود
                $v = trim($v);
                $v = ($v !== '' && preg_match('#^https?://[^\x20"\'<>]+$#i', $v)) ? $v : '';
            }
            if ($k === 'footer_credit_url') {                    // فقط لینک http(s) سالم پذیرفته می‌شود
                $v = trim($v);
                $v = ($v !== '' && preg_match('#^https?://[^\x20"\'<>]+$#i', $v)) ? $v : '';
            }
            if ($k === 'footer_credit_text') { $v = mb_substr(trim($v), 0, 300); }
            if ($k === 'consult_gap_default') { $v = (string)max(0, min(120, (int)en_num($v))); }
            DB::setSetting($k, $v);
        }
    }
    /* آپلود لوگو در feria-uploads/general/ */
    if (!empty($_FILES['logo_file']['tmp_name'])) {
        $mime = mime_content_type($_FILES['logo_file']['tmp_name']);
        $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/svg+xml'=>'svg','image/webp'=>'webp'];
        if (isset($map[$mime]) && $_FILES['logo_file']['size'] < 2_000_000) {
            $genDir = FR_ROOT . '/feria-uploads/general';
            if (!is_dir($genDir)) @mkdir($genDir, 0755, true);
            $fn = 'logo.' . $map[$mime];
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $genDir . '/' . $fn)) {
                DB::setSetting('logo', SITE_URL . '/feria-uploads/general/' . $fn);
            }
        }
    }
    /* آپلود لوگوی تیره (Dark Mode) در feria-uploads/general/ */
    if (!empty($_FILES['logo_dark_file']['tmp_name'])) {
        $mime = mime_content_type($_FILES['logo_dark_file']['tmp_name']);
        $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/svg+xml'=>'svg','image/webp'=>'webp'];
        if (isset($map[$mime]) && $_FILES['logo_dark_file']['size'] < 2_000_000) {
            $genDir = FR_ROOT . '/feria-uploads/general';
            if (!is_dir($genDir)) @mkdir($genDir, 0755, true);
            $fn = 'logo-dark.' . $map[$mime];
            if (move_uploaded_file($_FILES['logo_dark_file']['tmp_name'], $genDir . '/' . $fn)) {
                DB::setSetting('logo_dark', SITE_URL . '/feria-uploads/general/' . $fn);
            }
        }
    }
    /* آپلود فاوآیکون در feria-uploads/general/ */
    if (!empty($_FILES['favicon_file']['tmp_name'])) {
        $mime = mime_content_type($_FILES['favicon_file']['tmp_name']);
        $map = ['image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico','image/png'=>'png','image/svg+xml'=>'svg'];
        if (isset($map[$mime]) && $_FILES['favicon_file']['size'] < 1_000_000) {
            $genDir = FR_ROOT . '/feria-uploads/general';
            if (!is_dir($genDir)) @mkdir($genDir, 0755, true);
            $fn = 'favicon.' . $map[$mime];
            if (move_uploaded_file($_FILES['favicon_file']['tmp_name'], $genDir . '/' . $fn)) {
                DB::setSetting('favicon', SITE_URL . '/feria-uploads/general/' . $fn);
            }
        }
    }
    /* نسخهٔ ۲٫۱: آپلود تصویر نمادهای فوتر در feria-uploads/general/ */
    $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/svg+xml'=>'svg','image/webp'=>'webp','image/gif'=>'gif'];
    for ($bi = 1; $bi <= 4; $bi++) {
        $fld = 'badge' . $bi . '_file';
        if (!empty($_FILES[$fld]['tmp_name']) && is_uploaded_file($_FILES[$fld]['tmp_name'])) {
            $mime = mime_content_type($_FILES[$fld]['tmp_name']);
            if (isset($map[$mime]) && $_FILES[$fld]['size'] < 1_500_000) {
                $genDir = FR_ROOT . '/feria-uploads/general';
                if (!is_dir($genDir)) @mkdir($genDir, 0755, true);
                $fn = 'badge-' . $bi . '-' . date('YmdHis') . '.' . $map[$mime];
                if (move_uploaded_file($_FILES[$fld]['tmp_name'], $genDir . '/' . $fn)) {
                    DB::setSetting('footer_badge' . $bi . '_img', SITE_URL . '/feria-uploads/general/' . $fn);
                }
            }
        }
    }
    /* نسخهٔ ۴٫۵٫۰: آپلود لوگوی اختصاصی کارنامه در feria-uploads/general/ */
    if (!empty($_FILES['report_provider_logo_file']['tmp_name']) && is_uploaded_file($_FILES['report_provider_logo_file']['tmp_name'])) {
        $mime = mime_content_type($_FILES['report_provider_logo_file']['tmp_name']);
        if (isset($map[$mime]) && $_FILES['report_provider_logo_file']['size'] < 2_000_000) {
            $genDir = FR_ROOT . '/feria-uploads/general';
            if (!is_dir($genDir)) @mkdir($genDir, 0755, true);
            $fn = 'report-logo-' . date('YmdHis') . '.' . $map[$mime];
            if (move_uploaded_file($_FILES['report_provider_logo_file']['tmp_name'], $genDir . '/' . $fn)) {
                DB::setSetting('report_provider_logo', SITE_URL . '/feria-uploads/general/' . $fn);
            }
        }
    }
    /* نسخهٔ ۴٫۵٫۰: آپلود مهر و امضای دیجیتال متخصص در feria-uploads/general/ */
    if (!empty($_FILES['report_provider_stamp_file']['tmp_name']) && is_uploaded_file($_FILES['report_provider_stamp_file']['tmp_name'])) {
        $mime = mime_content_type($_FILES['report_provider_stamp_file']['tmp_name']);
        if (isset($map[$mime]) && $_FILES['report_provider_stamp_file']['size'] < 2_000_000) {
            $genDir = FR_ROOT . '/feria-uploads/general';
            if (!is_dir($genDir)) @mkdir($genDir, 0755, true);
            $fn = 'report-stamp-' . date('YmdHis') . '.' . $map[$mime];
            if (move_uploaded_file($_FILES['report_provider_stamp_file']['tmp_name'], $genDir . '/' . $fn)) {
                DB::setSetting('report_provider_stamp', SITE_URL . '/feria-uploads/general/' . $fn);
            }
        }
    }
    // تصحیح و نرمال‌سازی هوشمند فیلدهای نمادهای فوتر (در صورت قرار دادن اشتباه کد در فیلد لینک یا تصویر)
    for ($bi = 1; $bi <= 4; $bi++) {
        $hk = 'footer_badge' . $bi . '_html';
        $uk = 'footer_badge' . $bi . '_url';
        $ik = 'footer_badge' . $bi . '_img';
        $hVal = trim((string)($_POST[$hk] ?? ''));
        $uVal = trim((string)($_POST[$uk] ?? ''));
        $iVal = trim((string)($_POST[$ik] ?? ''));

        if ($hVal === '') {
            if (str_contains($uVal, '<') && (str_contains($uVal, '<a') || str_contains($uVal, '<script') || str_contains($uVal, '<img') || str_contains($uVal, '<iframe'))) {
                DB::setSetting($hk, $uVal);
                DB::setSetting($uk, '');
            } elseif (str_contains($iVal, '<') && (str_contains($iVal, '<a') || str_contains($iVal, '<script') || str_contains($iVal, '<img') || str_contains($iVal, '<iframe'))) {
                DB::setSetting($hk, $iVal);
                DB::setSetting($ik, '');
            }
        }
    }
    // همگام‌سازی و بازتولید خودکار قوانین پیوند و .htaccess در صورت تغییر نشانی پنل کاربری یا اسلاگ‌ها
    if (function_exists('fr_permalinks_flush')) {
        try { fr_permalinks_flush(); } catch (Throwable $e) {}
    }
    log_activity('admin_settings_save');
    flash('تنظیمات ذخیره شدند ✅ (پیوندهای سامانه و قوانین وب‌سرور به‌صورت خودکار همگام‌سازی شدند)');
    redirect(SITE_URL . '/admin/settings.php?tab=' . urlencode($tab));
}

/* ارسال پیامک تست */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'sms_test') {
    $mob = en_num((string)post('test_mobile'));
    $msg = trim((string)post('test_message'));
    if (!valid_mobile($mob) || $msg === '') flash('موبایل و متن پیام الزامی است.', 'err');
    else {
        $ok = (new Kavenegar())->sendTest($mob, $msg);
        flash($ok ? 'پیامک تست ارسال شد ✅ (در تاریخچه پیامک قابل مشاهده است)' : 'ارسال ناموفق — کلید API و فعال‌سازی را بررسی کنید.', $ok ? 'ok' : 'err');
    }
    redirect(SITE_URL . '/admin/settings.php?tab=sms');
}

$fonts = ['Vazirmatn'=>'وزیرمتن','Shabnam'=>'شبنم','Estedad'=>'استعداد','Arad'=>'آراد','Yekan Bakh'=>'یکان‌بخ','IRANSans'=>'ایران‌سنس','Sahel'=>'ساحل','Samim'=>'صمیم','Tanha'=>'تنها','Gandom'=>'گندم'];
$s = fn(string $k, string $d='') => (string)DB::setting($k, $d);

admin_layout($adm, 'settings', 'تنظیمات سیستم');
echo flash();
$tabs = [['brand','🎨 برندسازی و White-Label'],['footer','🦶 فوتر و نمادها'],['fonts','🔤 فونت‌ها'],['texts','📝 متن‌های صفحات'],['panel','🧩 پنل کاربری'],['payment','💳 زرین‌پال'],['sms','📩 کاوه‌نگار'],['report','📄 گزارش'],['reviews','⭐ دیدگاه‌ها'],['consult','🛋 مشاوره'],['planner','🗓 برنامه‌ریز روزانه'],['contact','📞 تماس']];
?>
<div class="fr-card">
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($tabs as [$k,$v]): ?>
    <a class="fr-btn sm <?= $tab===$k?'':'ghost' ?>" href="?tab=<?= $k ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </div>
</div>

<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save">

<?php if ($tab === 'brand'): ?>
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h3 style="margin:0">🏷️ هویت برند و نام‌گذاری سامانه (White-Label)</h3>
    <span class="fr-chip info">امکان شخصی‌سازی ۱۰۰٪ نام، لوگو و نشان تجاری</span>
  </div>
  <p style="font-size:13px;color:var(--muted);line-height:1.9;margin-top:0">
    تمامی بخش‌های سامانه (سربرگ، پاورقی، صفحات ارزیابی، کارنامه‌ها، پیامک‌ها و پنل‌ها) به‌صورت خودکار از اطلاعات زیر تبعیت می‌کنند.
  </p>

  <div class="fr-grid cols-3" style="margin-bottom:14px">
    <div class="fr-field">
      <label>نام رسمی سامانه / کلینیک (فارسی):</label>
      <input class="fr-input" name="site_title" value="<?= e($s('site_title','فریا روان')) ?>" placeholder="مثلاً: کلینیک روان‌شناسی مهر">
      <small class="hint">در عنوان صفحات، سربرگ و گزارش‌ها نمایش داده می‌شود.</small>
    </div>
    <div class="fr-field">
      <label>نام کوتاه برند (مونوگرام / ۱ کلمه):</label>
      <input class="fr-input" name="brand_short_name" value="<?= e($s('brand_short_name','فریا')) ?>" placeholder="مثلاً: مهر">
      <small class="hint">در متن‌های پیامک، نشان‌های کوچک و منوها کاربرد دارد.</small>
    </div>
    <div class="fr-field">
      <label>نام انگلیسی برند (Brand Name EN):</label>
      <input class="fr-input" name="site_title_en" value="<?= e($s('site_title_en','Feria Ravan')) ?>" dir="ltr" placeholder="Mehr Clinic">
      <small class="hint">در متاتگ‌های انگلیسی و رسیدها کاربرد دارد.</small>
    </div>
  </div>

  <div class="fr-field" style="margin-bottom:16px">
    <label>شعار سازمانی و معرفی کوتاه:</label>
    <input class="fr-input" name="site_slogan" value="<?= e($s('site_slogan','مرکز تخصصی آزمون‌های روان‌شناختی، کارگاه‌ها و مشاوره')) ?>" placeholder="توضیح کوتاه یا شعار تبلیغاتی کلینیک">
  </div>

  <div class="fr-grid cols-3" style="margin-bottom:16px">
    <!-- لوگوی روشن -->
    <div class="fr-field">
      <label>لوگوی اصلی (زمینه روشن):</label>
      <input class="fr-input" name="logo" value="<?= e($s('logo')) ?>" style="direction:ltr" placeholder="https://… یا خالی">
      <input type="file" name="logo_file" accept="image/*" style="margin-top:6px;font-size:12px">
      <?php if (fr_logo_url() !== ''): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
          <img src="<?= e(fr_logo_url()) ?>" alt="<?= e(fr_logo_alt()) ?>" style="height:38px;max-width:130px;border-radius:8px;border:1px solid var(--line);object-fit:contain;background:#fff;padding:3px">
          <small style="color:var(--muted)">پیش‌نمایش لوگو</small>
        </div>
      <?php endif; ?>
    </div>

    <!-- لوگوی تیره -->
    <div class="fr-field">
      <label>لوگوی نسخه تیره (Dark Mode / فوتر):</label>
      <input class="fr-input" name="logo_dark" value="<?= e($s('logo_dark')) ?>" style="direction:ltr" placeholder="https://… یا خالی">
      <input type="file" name="logo_dark_file" accept="image/*" style="margin-top:6px;font-size:12px">
      <?php if (!empty($s('logo_dark'))): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
          <img src="<?= e(fr_brand_logo(true)) ?>" alt="Dark Logo" style="height:38px;max-width:130px;border-radius:8px;border:1px solid #334155;object-fit:contain;background:#0f172a;padding:3px">
          <small style="color:var(--muted)">پیش‌نمایش لوگوی تیره</small>
        </div>
      <?php endif; ?>
    </div>

    <!-- فاوآیکون -->
    <div class="fr-field">
      <label>فاوآیکون سایت (Favicon):</label>
      <input class="fr-input" name="favicon" value="<?= e($s('favicon')) ?>" style="direction:ltr" placeholder="https://… یا خالی">
      <input type="file" name="favicon_file" accept="image/*" style="margin-top:6px;font-size:12px">
      <?php if (!empty($s('favicon'))): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
          <img src="<?= e(fr_brand_favicon()) ?>" alt="Favicon" style="width:28px;height:28px;border-radius:6px;border:1px solid var(--line);padding:2px">
          <small style="color:var(--muted)">پیش‌نمایش فاوآیکون</small>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="fr-grid cols-2" style="margin-bottom:14px">
    <div class="fr-field">
      <label>متن alt لوگو (برای سئو و موتورهای جستجو):</label>
      <input class="fr-input" name="logo_alt" value="<?= e($s('logo_alt')) ?>" maxlength="190" placeholder="مثلاً: لوگوی رسمی کلینیک روانشناسی مهر">
    </div>
    <div class="fr-field">
      <label>متن کپی‌رایت فوتر سایت:</label>
      <input class="fr-input" name="copyright_text" value="<?= e($s('copyright_text','تمامی حقوق مادی و معنوی متعلق به {site} می‌باشد.')) ?>" placeholder="راهنما: از {site} و {year} برای نام و سال خودکار استفاده کنید">
    </div>
  </div>

  <div class="fr-grid cols-2" style="margin-bottom:14px">
    <div class="fr-field"><label>واحد پول سامانه:</label>
      <input class="fr-input" name="currency_label" value="<?= e($s('currency_label','تومان')) ?>"></div>
    <div class="fr-field"><label>👥 وضعیت عضویت کاربران جدید:</label>
      <select class="fr-select" name="register_enabled">
        <option value="1" <?= $s('register_enabled','1')==='1'?'selected':'' ?>>فعال — ثبت‌نام با نام و شماره موبایل</option>
        <option value="0" <?= $s('register_enabled','1')==='0'?'selected':'' ?>>غیرفعال — فقط کاربران از پیش تعریف‌شده</option>
      </select>
    </div>
  </div>

  <div class="fr-grid cols-3" style="margin-bottom:14px">
    <?php foreach ([['color_primary','رنگ اصلی سازمانی'],['color_secondary','رنگ دوم / ناوبری'],['color_accent','رنگ دکمه‌ها و تأکید']] as [$k,$lbl]): ?>
    <div class="fr-field"><label><?= $lbl ?></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" name="<?= $k ?>" value="<?= e($s($k,'#1e3a6e')) ?>" style="width:46px;height:40px;border:none;border-radius:10px;cursor:pointer">
        <code style="font-size:12px"><?= e($s($k,'#1e3a6e')) ?></code>
      </div></div>
    <?php endforeach; ?>
  </div>

  <div class="fr-field"><label>متن فوتر سایت (درباره ما / معرفی کلینیک):</label>
    <div class="rte-toolbar" data-rte="foot"></div>
    <div class="rte-area" id="rte-foot" contenteditable="true" style="min-height:90px"><?= e($s('footer_text')) ?></div>
    <input type="hidden" name="footer_text" id="hd-foot"></div>
</div>

<!-- کارت تنظیمات اختصاصی White-Label و خط اعتباری -->
<div class="fr-card" style="border:1.5px solid #d4dcec;background:#fafbfd;margin-bottom:20px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
    <h3 style="margin:0;color:#1e3a8a">🛡️ خط اعتباری توسعه‌دهنده و ری‌برندینگ ۱۰۰٪ کامل (White-Label)</h3>
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;cursor:pointer;background:#fff;padding:6px 12px;border-radius:8px;border:1px solid #cbd5e1">
      <input type="checkbox" name="hide_system_credits" value="1" <?= $s('hide_system_credits','0')==='1'?'checked':'' ?>>
      <span>فعال‌سازی حالت White-Label کامل (مخفی‌سازی خط اعتباری در فوتر و گزارش‌ها)</span>
    </label>
  </div>
  <p style="font-size:12.5px;color:var(--muted);line-height:1.9;margin-top:0">
    در صورت فعال بودن خط اعتباری، این متن در انتهای صفحات و کارنامه‌های بالینی نمایش می‌یابد. مدیر سامانه می‌تواند متن و لینک آن را کاملاً به نام سازمان خود بازنویسی کند.
  </p>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>متن خط اعتبار:</label>
      <input class="fr-input" name="footer_credit_text" value="<?= e($s('footer_credit_text','طراحی و کد نویسی : احسان نادری پناه – 09024561001 | فریا')) ?>" maxlength="300"></div>
    <div class="fr-field"><label>لینک خط اعتبار (اختیاری):</label>
      <input class="fr-input" name="footer_credit_url" value="<?= e($s('footer_credit_url','https://feria.ir')) ?>" style="direction:ltr" placeholder="https://…" maxlength="255"></div>
  </div>
  <div style="font-size:12px;color:var(--muted)">
    پیش‌نمایش وضعیت فعلی: <span style="font-weight:700;color:#0f172a"><?= $s('hide_system_credits','0')==='1' ? '<span class="fr-chip danger">مخفی (White-Label کامل)</span>' : e(fr_credit_text()) ?></span>
  </div>
</div>

<!-- کارت اطلاعات تماس و پشتیبانی برند -->
<div class="fr-card" style="border:1px solid #e2e8f0;background:#fff">
  <h3 style="margin:0 0 12px;font-size:16px">📞 اطلاعات تماس و پشتیبانی رسمی برند</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field">
      <label>تلفن پشتیبانی و تماس:</label>
      <input class="fr-input" name="support_phone" value="<?= e($s('support_phone','021-88888888')) ?>" dir="ltr" placeholder="021-88888888">
    </div>
    <div class="fr-field">
      <label>ایمیل پشتیبانی:</label>
      <input class="fr-input" name="support_email" value="<?= e($s('support_email','support@example.com')) ?>" dir="ltr" placeholder="support@domain.com">
    </div>
  </div>
  <div class="fr-grid cols-2">
    <div class="fr-field">
      <label>شناسه یا کانال تلگرام پشتیبانی:</label>
      <input class="fr-input" name="support_telegram" value="<?= e($s('support_telegram')) ?>" dir="ltr" placeholder="@YourChannel">
    </div>
    <div class="fr-field">
      <label>شناسه یا کانال پیام‌رسان بله:</label>
      <input class="fr-input" name="support_bale" value="<?= e($s('support_bale')) ?>" dir="ltr" placeholder="@YourBaleChannel">
    </div>
  </div>
</div>

<?php elseif ($tab === 'footer'): ?>
<div class="fr-card" style="border:1.5px solid #d4dcec;background:linear-gradient(180deg,#fafbfd,#fff)">
  <h3>🦶 برند فوتر (نسخهٔ ۲٫۱)</h3>
  <div class="fr-field"><label>متن توضیح زیر لوگو در فوتر (برای سئو مفید است — HTML مجاز)</label>
    <div class="rte-toolbar" data-rte="ldesc"></div>
    <div class="rte-area" id="rte-ldesc" contenteditable="true" style="min-height:90px"><?= e($s('logo_desc')) ?></div>
    <input type="hidden" name="logo_desc" id="hd-ldesc">
    <small class="hint">اگر خالی بماند، همان «متن فوتر» برگهٔ برند نمایش داده می‌شود.</small></div>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>لینک‌های ستون «دسترسی سریع» — هر خط: <code>عنوان | نشانی</code></label>
      <textarea class="fr-input" name="footer_links_json" rows="6" style="font-family:inherit;line-height:2"
        placeholder="حریم خصوصی | /privacy&#10;قوانین | /terms"><?= e($s('footer_links_json')) ?></textarea>
      <small class="hint">اگر خالی بماند، لینک‌های پیش‌فرض (حریم خصوصی و قوانین) نمایش داده می‌شود. نشانی داخلی مثل <code>/tests</code> یا کامل <code>https://…</code> یا <code>tel:</code>.</small></div>
    <div class="fr-field"><label>لینک‌های ستون «تماس» — هر خط: <code>عنوان | نشانی</code></label>
      <textarea class="fr-input" name="footer_links2_json" rows="6" style="font-family:inherit;line-height:2"
        placeholder="تلگرام | https://t.me/feria_ir&#10;اینستاگرام | https://instagram.com/feria.ir"><?= e($s('footer_links2_json')) ?></textarea>
      <small class="hint">اگر خالی بماند، لینک‌های شبکه‌های اجتماعی و موبایل پشتیبانی (برگهٔ تماس) نشان داده می‌شوند.</small></div>
  </div>
</div>

<div class="fr-card">
  <h3>🏅 نمادهای فوتر (۴ جایگاه هم‌اندازه <span class="chip" style="background:#eef2ff">۳٫۵</span>)</h3>
  <p style="font-size:12.5px;color:var(--muted);margin-top:0">پشتیبانی کامل و هوشمند از کدهای <b>اینماد (Enamad)</b>، <b>نشان ملی ساماندهی</b>، <b>درگاه پرداخت امن زرین‌پال</b> و نمادهای اختصاصی. سامانه به‌طور خودکار کدهای HTML، تگ‌های اسکریپت، لینک‌های مستقیم و الزامات امنیتی (مانند هدرهای CSP و Referrer-Policy) را اعمال می‌کند تا نمادها در تمام مرورگرها به‌درستی نمایش داده شوند.</p>
  
  <div class="fr-grid cols-2">
    <?php
    $previewBadges = function_exists('fr_footer_badges') ? fr_footer_badges() : [];
    for ($bi = 1; $bi <= 4; $bi++):
        $bimg = $s('footer_badge'.$bi.'_img'); $burl = $s('footer_badge'.$bi.'_url'); $balt = $s('footer_badge'.$bi.'_alt'); $bhtm = $s('footer_badge'.$bi.'_html');
        $phTitle = [1=>'نماد اعتماد الکترونیکی (اینماد)', 2=>'نشان ملی ثبت (ساماندهی)', 3=>'درگاه پرداخت امن زرین‌پال', 4=>'انجمن روان‌شناسی'][$bi];
        $pb = $previewBadges[$bi - 1] ?? ['has_badge'=>false,'html'=>'','img'=>'','url'=>'','alt'=>$phTitle];
    ?>
    <div class="fr-card" style="margin:0;background:#fafbfd;border:1px solid #e2e8f0;border-radius:12px">
      <h4 style="margin-top:0">نماد <?= fa_num($bi) ?> <small style="color:var(--muted);font-weight:400">— <?= e($phTitle) ?></small></h4>
      
      <div class="fr-field"><label>عنوان / متن جایگزین (alt)</label>
        <input class="fr-input" name="footer_badge<?= $bi ?>_alt" value="<?= e($balt) ?>" maxlength="120" placeholder="<?= e($phTitle) ?>"></div>
      
      <div class="fr-field"><label>یا کد HTML / اسکریپت رسمی نماد (پیشنهادی برای اینماد و زرین‌پال)</label>
        <textarea class="fr-input" id="fbadge_html_<?= $bi ?>" name="footer_badge<?= $bi ?>_html" rows="4" style="direction:ltr;font-family:monospace;font-size:11px" placeholder="&lt;a referrerpolicy=&quot;origin&quot; target=&quot;_blank&quot; href=&quot;https://trustseal.enamad.ir/...&quot;&gt;&lt;img src=&quot;...&quot;&gt;&lt;/a&gt;"><?= e($bhtm) ?></textarea>
        <div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap">
          <?php if ($bi === 1): ?>
          <button type="button" class="fr-btn secondary" style="padding:2px 8px;font-size:11px" onclick="document.getElementById('fbadge_html_1').value='<a referrerpolicy=\'origin\' target=\'_blank\' href=\'https://trustseal.enamad.ir/?id=123456&Code=sample\' rel=\'noopener\' title=\'نماد اعتماد الکترونیکی\'><img referrerpolicy=\'origin\' src=\'https://trustseal.enamad.ir/logo.aspx?id=123456&Code=sample\' alt=\'نماد اعتماد الکترونیکی اینماد\' style=\'cursor:pointer\'></a>';">نمونه کد اینماد</button>
          <?php elseif ($bi === 2): ?>
          <button type="button" class="fr-btn secondary" style="padding:2px 8px;font-size:11px" onclick="document.getElementById('fbadge_html_2').value='<a target=\'_blank\' href=\'https://logo.samandehi.ir/Verify.aspx?id=123456\' rel=\'noopener\'><img id=\'samandehi-badge\' src=\'<?= SITE_URL ?>/assets/img/badges/samandehi.svg\' alt=\'نشان ملی ثبت رسانه‌های دیجیتال\' style=\'cursor:pointer\'></a>';">نمونه کد ساماندهی</button>
          <?php elseif ($bi === 3): ?>
          <button type="button" class="fr-btn secondary" style="padding:2px 8px;font-size:11px" onclick="document.getElementById('fbadge_html_3').value='<a href=\'https://www.zarinpal.com/trust\' target=\'_blank\' rel=\'noopener\'><img src=\'<?= SITE_URL ?>/assets/img/badges/zarinpal.svg\' alt=\'درگاه پرداخت امن زرین‌پال\'></a>';">نماد پیش‌فرض زرین‌پال</button>
          <button type="button" class="fr-btn secondary" style="padding:2px 8px;font-size:11px" onclick="document.getElementById('fbadge_html_3').value='<script src=\'https://www.zarinpal.com/webservice/TrustCode\' type=\'text/javascript\'><\/script>';">کد TrustCode زرین‌پال</button>
          <?php endif; ?>
        </div>
        <small class="hint">کدهای اختصاصی بدون دست‌کاری ذخیره می‌شوند؛ الزامات امنیتی (referrerpolicy="origin" و دسترسی‌های CSP) به‌صورت خودکار اعمال می‌گردد.</small></div>
      
      <div class="fr-field"><label>نشانی پیوند نماد (اختیاری — در صورت استفاده از تصویر)</label>
        <input class="fr-input" name="footer_badge<?= $bi ?>_url" value="<?= e($burl) ?>" style="direction:ltr" placeholder="https://trustseal.enamad.ir/?id=...">
        <small class="hint">اگر لینک اینماد را این‌جا قرار دهید، سامانه خودکار کد تصویری آن را می‌سازد.</small></div>

      <div class="fr-field"><label>آدرس یا آپلود تصویر (اختیاری)</label>
        <input class="fr-input" name="footer_badge<?= $bi ?>_img" value="<?= e($bimg) ?>" style="direction:ltr" placeholder="https://... یا خالی برای وکتور پیش‌فرض">
        <input type="file" name="badge<?= $bi ?>_file" accept="image/*" style="margin-top:6px;font-size:12px"></div>

      <!-- ۳٫۵: پیش‌نمایش دقیق همان چیزی که در فوتر رندر می‌شود -->
      <div class="fr-field" style="margin-bottom:0"><label>پیش‌نمایش ذخیره‌شدهٔ فوتر</label>
        <div style="width:104px;height:112px;border:1px solid #dcdfe6;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#ffffff;box-shadow:0 3px 10px rgba(0,0,0,0.08);overflow:hidden;padding:6px">
          <?php if (!empty($pb['html'])): ?>
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><?= $pb['html'] ?></div>
          <?php elseif (!empty($pb['img'])): ?>
            <img src="<?= e($pb['img']) ?>" alt="<?= e($pb['alt']) ?>" style="max-width:100%;max-height:88px;object-fit:contain">
          <?php else: ?>
            <div style="font-size:11px;color:#9aa2c7;text-align:center;line-height:1.8">＋<br>جایگاه خالی</div>
          <?php endif; ?>
        </div>
        <small class="hint"><?= $pb['has_badge'] ? '✅ نماد آماده نمایش در فوتر' : 'جایگاه هنوز تنظیم نشده است' ?></small></div>
    </div>
    <?php endfor; ?>
  </div>
  
  <div style="margin-top:16px;padding-top:12px;border-top:1px solid #eef2f7;display:flex;justify-content:flex-end">
    <button type="submit" class="fr-btn" style="min-width:180px">ذخیره تغییرات فوتر و نمادها ✅</button>
  </div>
</div>

<div class="fr-card">
  <h3>✉️ نوار خبرنامهٔ پیامکی فوتر</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>عنوان نوار</label><input class="fr-input" name="newsletter_title" value="<?= e($s('newsletter_title','خبرنامهٔ پیامکی')) ?>" maxlength="120"></div>
    <div class="fr-field"><label>توضیح نوار</label><input class="fr-input" name="newsletter_desc" value="<?= e($s('newsletter_desc','از دوره‌ها، کارگاه‌ها و تست‌های روانشناسی جدید زودتر از همه باخبر شوید.')) ?>" maxlength="300"></div>
  </div>
  <small class="hint">مدیریت مشترک‌ها و ارسال گروهی از مسیر <a href="<?= SITE_URL ?>/admin/newsletter.php">پیشخوان مدیریت ← خبرنامهٔ پیامکی</a>.</small>
</div>

<?php elseif ($tab === 'planner'): ?>
<div class="fr-card" style="border:1.5px solid #d4dcec;background:linear-gradient(180deg,#fafbfd,#fff)">
  <h3>🗓 برنامه‌ریز روزانهٔ کاربر (نسخهٔ ۲٫۱)</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>فعال‌سازی بخش برنامه‌ریز در پنل کاربر</label>
      <select class="fr-select" name="planner_enabled">
        <option value="1" <?= $s('planner_enabled','1')==='1'?'selected':'' ?>>فعال</option>
        <option value="0" <?= $s('planner_enabled','1')==='0'?'selected':'' ?>>غیرفعال</option>
      </select></div>
    <div class="fr-field"><label>هدف استریک (تعداد روز متوالی برای دریافت پاداش)</label>
      <input class="fr-input" name="planner_streak_target" value="<?= e($s('planner_streak_target','7')) ?>" style="direction:ltr;max-width:120px" inputmode="numeric">
      <small class="hint">مثلاً ۷ — کاربری که ۷ روز متوالی همهٔ کارهای روزانه‌اش را بزند، پاداش دریافت می‌کند.</small></div>
    <div class="fr-field"><label>نوع پاداش استریک</label>
      <select class="fr-select" name="planner_reward_type">
        <option value="discount" <?= $s('planner_reward_type','discount')==='discount'?'selected':'' ?>>کد تخفیف خودکار (مخصوص همان کاربر)</option>
        <option value="message" <?= $s('planner_reward_type','discount')==='message'?'selected':'' ?>>فقط پیام تشویقی</option>
      </select>
      <small class="hint">برای جایزه‌های بزرگ‌تر (جلسهٔ مشاوره یا تست روانشناسی رایگان) کد تخفیف ۱۰۰٪ بزنید یا پیام تشویقی را راهنمای تماس بنویسید.</small>
    </div>
    <div class="fr-field"><label>درصد تخفیف پاداش (۰ تا ۱۰۰)</label>
      <input class="fr-input" name="planner_reward_percent" value="<?= e($s('planner_reward_percent','20')) ?>" style="direction:ltr;max-width:120px" inputmode="numeric"></div>
    <div class="fr-field" style="grid-column:1/-1"><label>پیام تشویقی پاداش (اختیاری)</label>
      <input class="fr-input" name="planner_reward_message" value="<?= e($s('planner_reward_message')) ?>" maxlength="300" placeholder="مثلاً آفرین! کد تخفیف شما برای تست روانشناسی بعدی صادر شد."></div>
    <div class="fr-field"><label>تعداد یادآوری در روز</label>
      <select class="fr-select" name="planner_remind_count" id="prCount">
        <?php $prc = max(1, min(6, (int)DB::setting('planner_remind_count', (string)max(1, count(fr_planner_remind_slots()))))); ?>
        <?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>" <?= $prc === $i ? 'selected' : '' ?>><?= fa_num($i) ?> نوبت در روز</option><?php endfor; ?>
      </select>
      <small class="hint">مثلاً ۴ نوبت: صبح، ظهر، عصر و شب — به وقت ایران.</small></div>
    <div class="fr-field" style="grid-column:1/-1"><label>ساعت هر نوبت یادآوری <small>(قالب ۲۴ ساعته کامل)</small></label>
      <div id="prTimes" data-t24-wrap data-t24-count="#prCount" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <?php $prs = fr_planner_remind_slots(); for ($i = 0; $i < 6; $i++): ?>
        <span data-t24-item style="display:inline-flex;align-items:center;gap:6px">
          <small style="color:var(--muted)"><?= fa_num($i + 1) ?>.</small>
          <input class="fr-input" name="planner_remind_times[]" type="text" data-t24 inputmode="numeric" maxlength="5"
                 pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="08:30" dir="ltr" style="max-width:105px;text-align:center"
                 value="<?= e($prs[$i] ?? '') ?>">
        </span>
        <?php endfor; ?>
      </div>
      <small class="hint">ساعت‌ها را ۲۴ ساعته بنویسید (مثل ۰۷:۳۰ یا ۲۱:۱۵)؛ هر نوبت فقط یک‌بار در روز برای کاربران متصل به تلگرام/بله ارسال می‌شود.</small></div>
  </div>
</div>

<div class="fr-card">
  <h3>🤖 ربات‌های یادآور پیام‌رسان</h3>
  <p style="font-size:12.5px;color:var(--muted);margin-top:0">یادآوری روزانهٔ برنامه‌ریز از طریق تلگرام و بله انجام می‌شود. کاربر در پنلش شناسهٔ چت خودش را وارد می‌کند. معماری اتصال‌ها توسعه‌پذیر است (<code>core/Telegram.php</code> و <code>core/Bale.php</code>).</p>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>توکن ربات تلگرام</label>
      <input class="fr-input" name="telegram_bot_token" value="<?= e($s('telegram_bot_token')) ?>" style="direction:ltr" placeholder="123456:ABC-DEF…">
      <small class="hint">از <span dir="ltr">@BotFather</span> بگیرید.</small></div>
    <div class="fr-field"><label>نام کاربری ربات تلگرام (برای راهنمای کاربر)</label>
      <input class="fr-input" name="telegram_bot_username" value="<?= e($s('telegram_bot_username')) ?>" style="direction:ltr" placeholder="@feria_planner_bot"></div>
    <div class="fr-field"><label>توکن ربات بله</label>
      <input class="fr-input" name="bale_bot_token" value="<?= e($s('bale_bot_token')) ?>" style="direction:ltr" placeholder="123456789:abcdef…">
      <small class="hint">مطابق مستندات <code>docs.bale.ai</code> — همان API بات تلگرام با دامنهٔ <code>tapi.bale.ai</code>.</small></div>
    <div class="fr-field"><label>نام کاربری ربات بله</label>
      <input class="fr-input" name="bale_bot_username" value="<?= e($s('bale_bot_username')) ?>" style="direction:ltr" placeholder="@feria_planner_bot"></div>
    <div class="fr-field"><label>توکن / وب‌هوک بات روبیکا</label>
      <input class="fr-input" name="rubika_bot_token" value="<?= e($s('rubika_bot_token')) ?>" style="direction:ltr" placeholder="rubika_auth_token_or_bot_key">
      <small class="hint">توکن احراز هویت بات پلتفرم هوشمند روبیکا جهت ارسال نوتیفیکیشن و یادآور پلنر.</small></div>
    <div class="fr-field"><label>شناسه یا نام کاربری روبیکا</label>
      <input class="fr-input" name="rubika_bot_username" value="<?= e($s('rubika_bot_username')) ?>" style="direction:ltr" placeholder="@feria_planner"></div>
  </div>
  <div class="fr-note" style="font-size:12.5px">
    <b>کرون‌جاب یادآوری:</b> این نشانی را روزی یک‌بار (صبح) با ابزار کرون هاست فراخوانی کنید — یادآور فقط در بازهٔ ساعت تعیین‌شده واقعاً ارسال می‌شود:<br>
    <code style="direction:ltr;display:inline-block;margin-top:6px"><?= e(SITE_URL . '/cron/reminders.php?key=' . DB::setting('cron_secret','')) ?></code>
    <br><small class="hint">کلید مخفی کرون به‌صورت خودکار هنگام به‌روزرسانی ۲٫۱ ساخته شده و فقط همین‌جا نمایش داده می‌شود.</small>
  </div>
</div>

<?php elseif ($tab === 'fonts'): ?>
<div class="fr-card">
  <h3>فونت‌ها <small style="font-weight:400;color:var(--muted)">— فایل‌های woff/woff2 را در پوشهٔ <code>/assets/fonts/</code> قرار دهید</small></h3>
  <p class="fr-alert info" style="font-size:13px">فونت <b>وزیرمتن</b> (۴ وزن) به‌صورت پیش‌فرض همراه سامانه نصب است و بدون هیچ تنظیمی کار می‌کند.
  برای فونت دلخواه، نام فایل باید با نام کلید مطابقت داشته باشد؛ مثل <code>Shabnam.woff2</code> (وزن معمولی) و در صورت داشتنِ وزن‌های جداگانه: <code>Shabnam-Medium.woff2</code> (۵۰۰)، <code>Shabnam-SemiBold.woff2</code> (۶۰۰) و <code>Shabnam-Bold.woff2</code> (۷۰۰).</p>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>فونت اصلی متن‌ها</label>
      <select class="fr-select" name="font_main">
        <?php foreach ($fonts as $k=>$v): ?>
        <option value="<?= e($k) ?>" <?= $s('font_main','Vazirmatn')===$k?'selected':'' ?>><?= $v ?> (<?= $k ?>)</option>
        <?php endforeach; ?>
      </select></div>
    <div class="fr-field"><label>فونت تیترها</label>
      <select class="fr-select" name="font_headings">
        <?php foreach ($fonts as $k=>$v): ?>
        <option value="<?= e($k) ?>" <?= $s('font_headings','Vazirmatn')===$k?'selected':'' ?>><?= $v ?> (<?= $k ?>)</option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <div style="border:1.5px dashed var(--line);border-radius:14px;padding:20px;text-align:center">
    <p style="font-size:24px;font-family:'<?= e($s('font_main','Vazirmatn')) ?>'">پیش‌نمایش فونت: سلام! این یک متن نمونه فارسی است ✍ ۱۲۳۴۵۶۷۸۹۱۰</p>
  </div>
</div>

<?php elseif ($tab === 'texts'): ?>
<!-- ═════════ تب متن‌های صفحات (نسخهٔ ۱٫۷) ═════════ -->
<div class="fr-card">
  <h3>📝 متن‌های قابل‌شخصی‌سازی صفحات عمومی</h3>
  <p style="font-size:13px;color:var(--muted);margin-top:0;line-height:2">
    تیتر و توضیح بالای هر صفحهٔ عمومی را همین‌جا بنویسید؛ با همان کلمات و لحن خودتان در همان لحظه روی سایت می‌نشیند.
    توضیح‌ها در متا تگ‌ها (description صفحه) هم استفاده می‌شود، پس آن‌ها را طبیعی و معنادار بنویسید.
  </p>
  <?php foreach ([
      ['tests','🧠','تست‌های روانشناسی','صفحهٔ tests.php — نسخهٔ ۱٫۸'],
      ['workshops','🛠','کارگاه‌ها','صفحهٔ workshops.php'],
      ['courses','📚','دوره‌ها','صفحهٔ courses.php'],
      ['consult','🛋','رزرو نوبت مشاوره','صفحهٔ consult.php'],
  ] as [$pfx,$ico,$fa,$page]): ?>
  <div style="border:1.5px dashed #e2e9f5;border-radius:14px;padding:16px;margin-bottom:14px">
    <h4 style="margin:0 0 12px;font-size:14px"><?= $ico ?> <?= $fa ?> <small style="font-weight:400;color:var(--muted)">(<?= $page ?>)</small></h4>
    <div class="fr-field"><label>تیتر بالای صفحه</label>
      <input class="fr-input" name="<?= $pfx ?>_hero_title" value="<?= e($s($pfx.'_hero_title')) ?>" maxlength="190"></div>
    <div class="fr-field" style="margin:0"><label>توضیح زیر تیتر</label>
      <textarea class="fr-textarea" name="<?= $pfx ?>_hero_desc" rows="3" maxlength="480"><?= e($s($pfx.'_hero_desc')) ?></textarea></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="fr-card">
  <h3>🔍 سئوی صفحهٔ اصلی</h3>
  <div class="fr-field"><label>توضیح متا (description) صفحهٔ نخست</label>
    <textarea class="fr-textarea" name="seo_home_desc" rows="2" maxlength="320"><?= e($s('seo_home_desc')) ?></textarea>
    <small class="hint">در نتایج گوگل زیر نشانی سایت نمایش داده می‌شود؛ تا حدود ۱۶۰ کاراکتر مؤثر است.</small></div>
</div>

<?php elseif ($tab === 'panel'): ?>
<!-- ═════════ تب شخصی‌سازی پنل کاربری (نسخهٔ ۳٫۴) ═════════ -->
<div class="fr-card">
  <h3>🔗 عنوان و پیوند ورودی پنل کاربری</h3>
  <p style="font-size:13px;color:var(--muted);margin-top:0;line-height:2">
    می‌توانید عنوان دکمه و لینک هدایت کاربر به پنل کاربری را در هدر، فوتر و سایر بخش‌های سایت به دلخواه خود تغییر دهید.
  </p>
  <div class="fr-grid cols-2">
    <div class="fr-field">
      <label>عنوان دکمه / لینک پنل کاربری</label>
      <input class="fr-input" name="panel_title" value="<?= e($s('panel_title', 'پنل کاربری')) ?>" placeholder="مثلاً: پنل کاربری، میز کار، حساب کاربری...">
      <small class="hint">عنوانی که در دکمه بالای سایت و ناوبری فوتر نمایش داده می‌شود.</small>
    </div>
    <div class="fr-field">
      <label>نشانی (لینک) ورودی پنل کاربری</label>
      <input class="fr-input" name="panel_url" value="<?= e($s('panel_url', '/panel/')) ?>" dir="ltr" style="text-align:left" placeholder="/panel/">
      <small class="hint">مسیر دلخواه ورود به پنل کاربری مانند <code>/dashboard/</code> یا <code>/my-account/</code> یا <code>/panel/</code>. قوانین بازنویسی وب‌سرور و پیونددهی بدون هیچ‌گونه خطای ۴۰۴ خودکار اعمال می‌شوند.</small>
    </div>
  </div>
</div>

<!-- ═════════ تب شخصی‌سازی پنل کاربری (نسخهٔ ۱٫۷) ═════════ -->
<div class="fr-card">
  <h3>🧩 نمایش بخش‌های پنل کاربری</h3>
  <p style="font-size:13px;color:var(--muted);margin-top:0;line-height:2">
    هر بخشی را که نمی‌خواهید کاربران ببینند خاموش کنید؛ هم از منوی پنل حذف می‌شود هم صفحه‌اش
    به داشبورد برمی‌گردد. «تست‌های روانشناسی»، «کارگاه‌ها و دوره‌ها»، «نوبت‌های مشاوره» و «پروفایل» همیشه فعال‌اند.
  </p>
  <div class="fr-grid cols-2">
    <?php foreach ([
        ['panel_menu_wallet',  '👛 کیف پول و عودت وجه'],
        ['panel_menu_payments','💳 تراکنش‌ها (فاکتورها)'],
        ['panel_menu_history', '📋 تاریخچه تست‌های روانشناسی'],
        ['panel_menu_tickets', '🎫 تیکت پشتیبانی'],
    ] as [$k,$lbl]): ?>
    <div class="fr-field"><label><?= $lbl ?></label>
      <select class="fr-select" name="<?= $k ?>">
        <option value="1" <?= $s($k,'1')==='1'?'selected':'' ?>>✅ نمایش داده شود</option>
        <option value="0" <?= $s($k,'1')==='0'?'selected':'' ?>>🚫 پنهان شود</option>
      </select></div>
    <?php endforeach; ?>
  </div>
</div>
<div class="fr-card">
  <h3>✉️ یادداشت بالای داشبورد کاربر</h3>
  <p style="font-size:12.5px;color:var(--muted);margin-top:0">متن دلخواهتان (تبریک، اطلاعیه، راهنما…) در بالای داشبورد همهٔ کاربران نمایش داده می‌شود؛ خالی = بدون یادداشت.</p>
  <div class="fr-field" style="margin:0"><label>متن یادداشت (HTML)</label>
    <div class="rte-toolbar" data-rte="pdash"></div>
    <div class="rte-area" id="rte-pdash" contenteditable="true" style="min-height:90px"><?= e($s('panel_dashboard_note')) ?></div>
    <input type="hidden" name="panel_dashboard_note" id="hd-pdash"></div>
</div>
<div class="fr-card">
  <h3>📌 یادداشت کوچک سایدبار پنل</h3>
  <p style="font-size:12.5px;color:var(--muted);margin-top:0">یک خط کوتاه زیر منوی سایدبار کاربران (مثل شماره پشتیبانی یا یک جملهٔ دلگرم‌کننده)؛ خالی = بدون یادداشت.</p>
  <div class="fr-field" style="margin:0"><label>متن (حداکثر ۱۹۰ کاراکتر)</label>
    <input class="fr-input" name="panel_sidebar_note" maxlength="190" value="<?= e($s('panel_sidebar_note')) ?>"
           placeholder="مثلاً پشتیبانی هر روز ۹ تا ۲۱ — ۰۲۱۲۳۴۵۶۷۸۹"></div>
</div>

<?php elseif ($tab === 'payment'): ?>
<div class="fr-card">
  <h3>درگاه پرداخت زرین‌پال</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>Merchant ID (کد مرچنت)</label>
      <input class="fr-input" name="zarinpal_merchant" value="<?= e($s('zarinpal_merchant')) ?>" style="direction:ltr" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
      <div class="hint">از پنل زرین‌پال دریافت می‌شود.</div></div>
    <div class="fr-field"><label>محیط</label>
      <select class="fr-select" name="zarinpal_sandbox">
        <option value="1" <?= $s('zarinpal_sandbox','1')==='1'?'selected':'' ?>>🧪 آزمایشی (Sandbox — برای تست)</option>
        <option value="0" <?= $s('zarinpal_sandbox')==='0'?'selected':'' ?>>💰 اصلی (Production)</option>
      </select>
      <div class="hint">در Sandbox پرداخت واقعی انجام نمی‌شود.</div></div>
  </div>
  <div class="fr-alert warn" style="font-size:13px">
    آدرس کال‌بک (در پنل زرین‌پال نیازی به ثبت نیست): <code style="direction:ltr;display:inline-block"><?= SITE_URL ?>/api/zarinpal-callback.php</code>
  </div>
  <div class="fr-alert info" style="font-size:13px;margin-top:12px">
    🏅 <b>نماد اعتماد زرین‌پال در فوتر:</b>
    برای نمایش نشان رسمی زرین‌پال در فوتر سایت، به برگهٔ <a href="<?= SITE_URL ?>/admin/settings.php?tab=footer" style="font-weight:bold;text-decoration:underline">فوتر و نمادها</a> بروید. جایگاه ۳ به‌طور پیش‌فرض به درگاه پرداخت امن زرین‌پال اختصاص دارد و می‌توانید نشان وکتور یا اسکریپت TrustCode را فعال کنید.
  </div>
</div>

<?php elseif ($tab === 'sms'): ?>
<div class="fr-card">
  <h3>پنل پیامک کاوه‌نگار</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>کلید API</label>
      <input class="fr-input" name="kavenegar_apikey" value="<?= e($s('kavenegar_apikey')) ?>" style="direction:ltr">
      <div class="hint">از پنل کاوه‌نگار → حساب کاربری دریافت می‌شود.</div></div>
    <div class="fr-field"><label>خط ارسال (شماره سرویس)</label>
      <input class="fr-input" name="kavenegar_sender" value="<?= e($s('kavenegar_sender')) ?>" style="direction:ltr" placeholder="مثال: 10008585 یا 2000... (در صورت ارسال پترن می‌تواند خالی باشد)"></div>
    <div class="fr-field"><label>کد پترن پیش‌فرض OTP (وب‌سرویس اعتبارسنجی VerifyLookup)</label>
      <input class="fr-input" name="kavenegar_otp_pattern" value="<?= e($s('kavenegar_otp_pattern', 'otp')) ?>" style="direction:ltr" placeholder="otp">
      <div class="hint">نام پترن ثبت‌شده در پنل کاوه‌نگار (مثلاً <code>otp</code>). پیامک از بلک‌لیست مخابرات عبور کرده و در چند ثانیه تحویل می‌شود.</div></div>
    <div class="fr-field"><label>فعال‌سازی ارسال پیامک</label>
      <select class="fr-select" name="kavenegar_enabled">
        <option value="1" <?= $s('kavenegar_enabled')==='1'?'selected':'' ?>>فعال ✅</option>
        <option value="0" <?= $s('kavenegar_enabled','0')==='0'?'selected':'' ?>>غیرفعال (فقط لاگ)</option>
      </select></div>
    <div class="fr-field"><label>طول کد OTP</label>
      <select class="fr-select" name="otp_length">
        <?php foreach ([4,5,6] as $n): ?>
        <option value="<?= $n ?>" <?= $s('otp_length','5')==$n?'selected':'' ?>><?= fa_num($n) ?> رقمی</option>
        <?php endforeach; ?>
      </select></div>
    <div class="fr-field"><label>📇 شماره‌های اطلاع‌رسانی مدیریت (با ویرگول جدا کنید)</label>
      <input class="fr-input" name="admin_notify_mobiles" value="<?= e($s('admin_notify_mobiles')) ?>"
             style="direction:ltr;text-align:left" placeholder="09120000000, 09361234567" maxlength="500">
      <div class="hint">وقتی ثبت‌نام/خرید یا درخواست عودت وجه جدید ثبت می‌شود، علاوه‌بر مدیرکل، به همهٔ این شماره‌ها (مثلاً مسئول حسابداری) هم پیامک اطلاع می‌رود.</div></div>
  </div>
  <button class="fr-btn">ذخیره تنظیمات پیامک ✅</button>
</div>

<!-- ── قالب‌های پیامک اطلاع‌رسانی رزرو مشاوره (نسخهٔ ۱٫۶) ── -->
<div class="fr-card">
  <h3>✉️ قالب‌های پیامک رزرو مشاوره</h3>
  <div class="fr-alert info" style="font-size:12.5px;line-height:2">
    متن هر پیامک را دلخواه بنویسید؛ جاهای خالی با مقدار واقعی پر می‌شوند. نشانه‌های مجاز:<br>
    <code style="font-size:12px">{name}</code> نام کاربر ·
    <code style="font-size:12px">{service}</code> نام خدمت ·
    <code style="font-size:12px">{mode}</code> آنلاین/حضوری ·
    <code style="font-size:12px">{consultant}</code> نام مشاور ·
    <code style="font-size:12px">{when}</code> تاریخ و ساعت شمسی ·
    <code style="font-size:12px">{hint}</code> نشانی/لینک جلسه (فقط در پیام تأیید) — اگر قالبی را خالی بگذارید، متن پیش‌فرض سیستم ارسال می‌شود.
  </div>
  <div class="fr-field"><label>۱) تأیید رزرو برای کاربر</label>
    <textarea class="fr-input" name="sms_tpl_consult_book_user" rows="2" style="line-height:2"><?= e($s('sms_tpl_consult_book_user','{name} عزیز، نوبت «{service}» ({mode}) شما نزد {consultant} برای {when} ثبت شد. 🦢{hint}')) ?></textarea></div>
  <div class="fr-field"><label>۲) اطلاع رزرو جدید به مشاور</label>
    <textarea class="fr-input" name="sms_tpl_consult_book_cons" rows="2" style="line-height:2"><?= e($s('sms_tpl_consult_book_cons','نوبت جدید: «{service}» ({mode}) — {name} — {when}')) ?></textarea></div>
  <div class="fr-field"><label>۳) لغو نوبت</label>
    <textarea class="fr-input" name="sms_tpl_consult_cancel" rows="2" style="line-height:2"><?= e($s('sms_tpl_consult_cancel','{name} عزیز، نوبت «{service}» مورخ {when} لغو شد.')) ?></textarea></div>
  <div class="fr-field"><label>۴) یادآوری نوبت (ارسال دستی/خودکار)</label>
    <textarea class="fr-input" name="sms_tpl_consult_remind" rows="2" style="line-height:2"><?= e($s('sms_tpl_consult_remind','{name} عزیز، یادآوری: نوبت «{service}» شما نزد {consultant} برای {when} است. 🦢')) ?></textarea></div>
  <button class="fr-btn">ذخیره قالب‌ها ✅</button>
</div>
</form><!-- پایان فرم اصلی تنظیمات -->
<!-- فرم جداگانهٔ ارسال پیامک تست -->
<div class="fr-card">
  <h3>🧪 ارسال پیامک تست</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="sms_test">
    <div class="fr-grid cols-2" style="gap:12px">
      <div class="fr-field"><label>موبایل گیرنده</label>
        <input class="fr-input" name="test_mobile" placeholder="09xxxxxxxxx" style="direction:ltr;text-align:right"></div>
      <div class="fr-field"><label>متن پیام دلخواه</label>
        <input class="fr-input" name="test_message" placeholder="تست ارسال از فریا روان 🦢"></div>
    </div>
    <button class="fr-btn sm">ارسال پیامک تست</button>
    <a class="fr-btn ghost sm" href="<?= SITE_URL ?>/admin/monitor.php?tab=sms">مشاهده تاریخچه ارسال</a>
  </form>
</div>
<?php admin_layout_end(); exit; ?>

<?php elseif ($tab === 'report'): ?>
<!-- ── کارت ۱: مشخصات ارائه‌دهنده و مرکز روانشناسی در کارنامه ── -->
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h3 style="margin:0">🏛️ اطلاعات مرکز و ارائه‌دهنده آزمون (سربرگ کارنامه رسمی)</h3>
    <span class="fr-chip purple">استاندارد کارنامه ISO A4</span>
  </div>
  <p style="font-size:13px;color:var(--muted);line-height:1.9;margin-top:0">
    اطلاعات زیر در سربرگ کارنامه چاپی و فایل دانلودی A4 مراجع و مدیر قرار می‌گیرد.
  </p>

  <div class="fr-grid cols-3" style="margin-bottom:14px">
    <div class="fr-field">
      <label>نام رسمی کلینیک / مرکز ارائه‌دهنده:</label>
      <input class="fr-input" name="report_provider_name" value="<?= e($s('report_provider_name', fr_brand_title())) ?>" placeholder="مثلاً: کلینیک روان‌شناسی و مشاوره فریا">
      <small class="hint">عنوان اصلی بالای سربرگ کارنامه</small>
    </div>
    <div class="fr-field">
      <label>زیرعنوان و تخصص مرکز:</label>
      <input class="fr-input" name="report_provider_subtitle" value="<?= e($s('report_provider_subtitle', fr_brand_slogan() ?: 'مرکز تخصصی ارزیابی‌های روان‌شناختی، روان‌سنجی و مشاوره')) ?>" placeholder="مثلاً: مرکز خدمات روان‌شناختی و مشاوره">
      <small class="hint">توضیح زیر نام کلینیک در سربرگ</small>
    </div>
    <div class="fr-field">
      <label>شماره پروانه / مجوز فعالیت مرکز:</label>
      <input class="fr-input" name="report_provider_license" value="<?= e($s('report_provider_license')) ?>" placeholder="مثلاً: پروانه نظام روانشناسی: ۱۴۰۳/الف/۱۲۹">
      <small class="hint">شماره ثبت قانونی یا پروانه تأسیس مرکز</small>
    </div>
  </div>

  <div class="fr-grid cols-3" style="margin-bottom:14px">
    <div class="fr-field">
      <label>تلفن تماس مرکز در کارنامه:</label>
      <input class="fr-input" name="report_provider_phone" value="<?= e($s('report_provider_phone')) ?>" dir="ltr" placeholder="021-xxxxxxxx">
    </div>
    <div class="fr-field">
      <label>نشانی وب‌سایت در کارنامه:</label>
      <input class="fr-input" name="report_provider_website" value="<?= e($s('report_provider_website', SITE_URL)) ?>" dir="ltr" placeholder="<?= SITE_URL ?>">
    </div>
    <div class="fr-field">
      <label>نشانی پستی یا دفتر کلینیک:</label>
      <input class="fr-input" name="report_provider_address" value="<?= e($s('report_provider_address')) ?>" placeholder="تهران، خیابان ولیعصر...">
    </div>
  </div>
</div>

<!-- ── کارت ۲: مشخصات ناظر علمی و روانشناس مسئول آزمون ── -->
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h3 style="margin:0">🩺 مشخصات روانشناس ناظر و مسئول فنی کارنامه</h3>
    <span class="fr-chip info">امضا و اعتبارسنجی بالینی</span>
  </div>

  <div class="fr-grid cols-3" style="margin-bottom:14px">
    <div class="fr-field">
      <label>نام و نام خانوادگی روانشناس مسئول:</label>
      <input class="fr-input" name="report_doctor_name" value="<?= e($s('report_doctor_name')) ?>" placeholder="مثلاً: دکتر مریم جمالی">
      <small class="hint">در سربرگ و کادر تأییدیه انتهای کارنامه درج می‌شود.</small>
    </div>
    <div class="fr-field">
      <label>عنوان تخصصی روانشناس:</label>
      <input class="fr-input" name="report_doctor_title" value="<?= e($s('report_doctor_title', 'متخصص روان‌شناسی بالینی و روان‌سنج')) ?>" placeholder="متخصص روان‌شناسی بالینی">
      <small class="hint">سمت بالینی ناظر آزمون</small>
    </div>
    <div class="fr-field">
      <label>شماره نظام روان‌شناسی / پروانه اشتغال:</label>
      <input class="fr-input" name="report_doctor_license" value="<?= e($s('report_doctor_license')) ?>" dir="ltr" placeholder="مثلاً: نظام روانشناسی: ۱۲۳۴۵">
      <small class="hint">شماره عضویت یا پروانه فعالیت در سازمان نظام</small>
    </div>
  </div>
</div>

<!-- ── کارت ۳: لوگو، مهر و امضای دیجیتال کارنامه ── -->
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <h3 style="margin:0">🖼️ لوگوی سربرگ و مهر/امضای رسمی کارنامه</h3>
    <span class="fr-chip">فرمت PNG شفاف، SVG یا JPG</span>
  </div>

  <div class="fr-grid cols-2" style="gap:20px;margin-bottom:16px">
    <!-- لوگوی کارنامه -->
    <div class="fr-field" style="border:1px solid var(--line);border-radius:12px;padding:14px;background:#fcfdff">
      <label style="font-weight:bold;margin-bottom:6px;display:block">🏷️ لوگوی اختصاصی سربرگ کارنامه:</label>
      <input class="fr-input" name="report_provider_logo" value="<?= e($s('report_provider_logo')) ?>" dir="ltr" placeholder="آدرس تصویر لوگو یا از کادر زیر آپلود کنید">
      <input type="file" name="report_provider_logo_file" accept="image/*" style="margin-top:8px;font-size:12px;display:block">
      <small class="hint">در صورت خالی بودن، لوگوی اصلی سایت استفاده می‌شود.</small>
      <?php $repLogo = fr_report_provider_logo(); if ($repLogo !== ''): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-top:10px;padding:8px;background:#fff;border-radius:8px;border:1px solid #e2e8f0">
          <img src="<?= e($repLogo) ?>" alt="پیش‌نمایش لوگوی کارنامه" style="height:48px;max-width:120px;object-fit:contain">
          <small style="color:var(--muted)">پیش‌نمایش لوگوی سربرگ کارنامه</small>
        </div>
      <?php endif; ?>
    </div>

    <!-- مهر و امضای دیجیتال -->
    <div class="fr-field" style="border:1px solid var(--line);border-radius:12px;padding:14px;background:#fcfdff">
      <label style="font-weight:bold;margin-bottom:6px;display:block">🔏 تصویر مهر و امضای دیجیتال کلینیک / روانشناس:</label>
      <input class="fr-input" name="report_provider_stamp" value="<?= e($s('report_provider_stamp')) ?>" dir="ltr" placeholder="آدرس تصویر مهر/امضا یا از کادر زیر آپلود کنید">
      <input type="file" name="report_provider_stamp_file" accept="image/*" style="margin-top:8px;font-size:12px;display:block">
      <small class="hint">ترجیحاً تصویر PNG بدون پس‌زمینه (شفاف) جهت قرارگیری در کادر امضای انتهای کارنامه</small>
      <?php $repStamp = fr_report_provider_stamp(); if ($repStamp !== ''): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-top:10px;padding:8px;background:#fff;border-radius:8px;border:1px solid #e2e8f0">
          <img src="<?= e($repStamp) ?>" alt="پیش‌نمایش مهر کارنامه" style="height:55px;max-width:140px;object-fit:contain">
          <small style="color:var(--muted)">پیش‌نمایش مهر/امضا در انتهای سند</small>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="fr-grid cols-2">
    <div class="fr-field">
      <label>نمایش کادر امضا و تأییدیه روانشناس در کارنامه:</label>
      <select class="fr-select" name="report_show_signature_box">
        <option value="1" <?= $s('report_show_signature_box','1')==='1'?'selected':'' ?>>نمایش داده شود ✅ (توصیه‌شده برای کارنامه رسمی)</option>
        <option value="0" <?= $s('report_show_signature_box','1')==='0'?'selected':'' ?>>پنهان شود</option>
      </select>
    </div>
    <div class="fr-field">
      <label>رنگ سازمانی سربرگ کارنامه (Header Color):</label>
      <div style="display:flex;gap:10px;align-items:center">
        <input type="color" name="report_header_color" value="<?= e(fr_report_header_color()) ?>" style="width:48px;height:40px;border:none;border-radius:8px;cursor:pointer;padding:0">
        <input class="fr-input" value="<?= e(fr_report_header_color()) ?>" style="width:110px;direction:ltr" readonly>
        <span style="font-size:12.5px;color:var(--muted)">رنگ پس‌زمینه سربرگ کارنامه و تیترهای اصلی</span>
      </div>
    </div>
    <div class="fr-field">
      <label>رنگ تأکیدی کارنامه (Accent Color):</label>
      <div style="display:flex;gap:10px;align-items:center">
        <input type="color" name="report_accent_color" value="<?= e(fr_report_accent_color()) ?>" style="width:48px;height:40px;border:none;border-radius:8px;cursor:pointer;padding:0">
        <input class="fr-input" value="<?= e(fr_report_accent_color()) ?>" style="width:110px;direction:ltr" readonly>
        <span style="font-size:12.5px;color:var(--muted)">رنگ خطوط برجسته، نشان‌ها و مقیاس‌های شاخص</span>
      </div>
    </div>
    <div class="fr-field">
      <label>پالت رنگی نمودارها و نوارهای مقیاس (.fr-scalebar):</label>
      <select class="fr-select" name="report_chart_palette">
        <option value="default" <?= $s('report_chart_palette','default')==='default'?'selected':'' ?>>پالت استاندارد روان‌شناسی (سرمه‌ای، بنفش، سبز زمردی، نارنجی، سرخ) 🎨</option>
        <option value="ocean" <?= $s('report_chart_palette')==='ocean'?'selected':'' ?>>پالت اقیانوسی (نیلی، فیروزه‌ای و آبی لاجوردی) 🌊</option>
        <option value="emerald" <?= $s('report_chart_palette')==='emerald'?'selected':'' ?>>پالت سلامت و زمردی (سبز تیره، نعنایی و فیروزه‌ای) 🌿</option>
        <option value="royal" <?= $s('report_chart_palette')==='royal'?'selected':'' ?>>پالت سلطنتی و لوکس (ارغوانی، بنفش و طلایی) 👑</option>
      </select>
    </div>
  </div>
</div>

<!-- ── کارت ۴: تنظیمات اعتبارسنجی QR و سلب مسئولیت حقوقی ── -->
<div class="fr-card">
  <h3>▣ اعتبارسنجی بارکد QR، موتور PDF و سلب مسئولیت</h3>
  <div class="fr-grid cols-2" style="margin-bottom:14px">
    <div class="fr-field">
      <label>نمایش بارکد QR در کارنامه:</label>
      <select class="fr-select" name="report_show_qr">
        <option value="1" <?= $s('report_show_qr','1')==='1'?'selected':'' ?>>نمایش داده شود ✅</option>
        <option value="0" <?= $s('report_show_qr','1')==='0'?'selected':'' ?>>پنهان شود</option>
      </select>
      <small class="hint">مراجعین می‌توانند با اسکن بارکد اصالت گزارش را استعلام کنند.</small>
    </div>
    <div class="fr-field">
      <label>موتور رندر و تولید PDF (PDF Engine):</label>
      <select class="fr-select" name="report_pdf_engine">
        <option value="auto" <?= $s('report_pdf_engine','auto')==='auto'?'selected':'' ?>>هوشمند (موتور تمام‌رنگی Dompdf با پشتیبان TCPDF) 🚀</option>
        <option value="dompdf" <?= $s('report_pdf_engine')==='dompdf'?'selected':'' ?>>موتور Dompdf (تمام‌رنگی، CSS3 پیشرفته، A4 استاندارد)</option>
        <option value="tcpdf" <?= $s('report_pdf_engine')==='tcpdf'?'selected':'' ?>>موتور کلاسیک TCPDF</option>
      </select>
      <small class="hint">موتور Dompdf خروجی گرافیکی تمام‌رنگی و مطابق استانداردهای چاپ مدرن تولید می‌کند.</small>
    </div>
    <div class="fr-field">
      <label>نشانی اینترنتی داخل بارکد QR (اختیاری):</label>
      <input class="fr-input" name="report_qr_link" value="<?= e($s('report_qr_link')) ?>" style="direction:ltr" placeholder="<?= SITE_URL ?>" maxlength="255">
      <small class="hint">در صورت خالی بودن، لینک صفحه کارنامه یا سامانه قرار می‌گیرد.</small>
    </div>
    <div class="fr-field">
      <label>حالت پیش‌فرض دکمه دریافت کارنامه:</label>
      <select class="fr-select" name="report_pdf_download_mode">
        <option value="direct_pdf" <?= $s('report_pdf_download_mode','direct_pdf')==='direct_pdf'?'selected':'' ?>>دانلود مستقیم فایل PDF استاندارد A4 (پیش‌فرض) ✅</option>
        <option value="interactive" <?= $s('report_pdf_download_mode')==='interactive'?'selected':'' ?>>مشاهده سند A4 در صفحه جدید با دکمه‌های پرینت و دانلود</option>
      </select>
      <small class="hint">رفتار سیستم هنگام کلیک مراجع یا مدیر روی دکمه دریافت فایل کارنامه</small>
    </div>
  </div>

  <div class="fr-field" style="margin-bottom:14px">
    <label>متن سلب مسئولیت و تذکر بالینی در پاورقی کارنامه:</label>
    <textarea class="fr-input" name="report_disclaimer" rows="3" style="line-height:2"><?= e(fr_report_disclaimer()) ?></textarea>
    <small class="hint">متن حقوقی و اخلاقی انتهای کارنامه در خصوص ارزیابی اولیه و لزوم مشاوره حضوری جهت تشخیص قطعی</small>
  </div>

  <div class="fr-field">
    <label>پانوشت سفارشی اضافی گزارش:</label>
    <div class="rte-toolbar" data-rte="rfoot"></div>
    <div class="rte-area" id="rte-rfoot" contenteditable="true" style="min-height:80px"><?= e($s('report_footer')) ?></div>
    <input type="hidden" name="report_footer" id="hd-rfoot">
  </div>
</div>

<?php elseif ($tab === 'reviews'): ?>
<!-- ── تنظیمات دیدگاهها (نسخهٔ ۲٫۰) ── -->
<div class="fr-card">
  <h3>⭐ تنظیمات دیدگاه و امتیاز (ستاره‌ای)</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>انتشار خودکار دیدگاه‌ها</label>
      <select class="fr-select" name="reviews_auto_approve">
        <option value="1" <?= $s('reviews_auto_approve','1')==='1'?'selected':'' ?>>بله — بلافاصله منتشر شود ✅</option>
        <option value="0" <?= $s('reviews_auto_approve','1')==='0'?'selected':'' ?>>خیر — ابتدا تأیید مدیر ⏳</option>
      </select>
      <small class="hint">اگر «خیر» انتخاب شود، دیدگاه‌ها در <a href="reviews.php">مدیریت دیدگاه‌ها</a> در وضعیت «در انتظار تأیید» می‌مانند.</small></div>
  </div>
  <div class="fr-alert info" style="font-size:13px;margin-bottom:0">
    💬 فهرست دیدگاه‌ها، ثبت دستی و <b>ورود گروهی از فایل اکسل</b> در صفحهٔ
    <a href="reviews.php">⭐ مدیریت دیدگاه‌ها</a> انجام می‌شود؛ لیبل‌های قابل‌شخصی‌سازی نیز در همان‌جا (تب «لیبل‌ها») مدیریت می‌شوند.
  </div>
</div>

<?php elseif ($tab === 'consult'): ?>
<!-- ── تنظیمات بوکینگ مشاوره (نسخهٔ ۱٫۴) ──────────────────────────── -->
<div class="fr-card">
  <h3>تنظیمات سیستم رزرو مشاوره</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>مدت رزرو موقت نوبت تا پرداخت (دقیقه)</label>
      <input class="fr-input" name="consult_hold_minutes" inputmode="numeric" value="<?= fa_num($s('consult_hold_minutes','10')) ?>">
      <small class="hint">کاربر این فرصت را دارد پرداخت را کامل کند؛ بعدش اسلات آزاد می‌شود.</small></div>
    <div class="fr-field"><label>حداقل فاصلهٔ مجاز لغو آنلاین تا جلسه (ساعت)</label>
      <input class="fr-input" name="consult_cancel_hours" inputmode="numeric" value="<?= fa_num($s('consult_cancel_hours','24')) ?>">
      <small class="hint">کمتر از این زمان، کاربر فقط از طریق پشتیبانی می‌تواند لغو کند.</small></div>
    <div class="fr-field"><label>استراحت پیش‌فرض بین جلسات (دقیقه) ⏸</label>
      <input class="fr-input" name="consult_gap_default" inputmode="numeric" value="<?= fa_num($s('consult_gap_default','0')) ?>">
      <small class="hint">هنگام ساخت «خدمت جدید»، این مقدار به‌صورت خودکار در فرم می‌آید؛ برای هر خدمت جداگانه هم قابل تغییر است (خدمات و مشاوران ← ویرایش خدمت).</small></div>
  </div>
  <div class="fr-alert info" style="margin-bottom:0">
    مدیریت مشاوران، خدمات، زمان‌بندی هفتگی، تعطیلات و نوبت‌ها در بخش‌های
    <a href="consult.php">🛋 مشاوره</a>، <a href="consult-schedule.php">🗓 زمان‌بندی</a> و <a href="consult-bookings.php">📋 نوبت‌ها</a> انجام می‌شود.
  </div>
</div>

<?php else: ?>
<div class="fr-card">
  <h3>اطلاعات تماس و شبکه‌های اجتماعی</h3>
  <div class="fr-grid cols-3">
    <div class="fr-field"><label>تلگرام</label>
      <input class="fr-input" name="telegram" value="<?= e($s('telegram')) ?>" style="direction:ltr"></div>
    <div class="fr-field"><label>اینستاگرام</label>
      <input class="fr-input" name="instagram" value="<?= e($s('instagram')) ?>" style="direction:ltr"></div>
    <div class="fr-field"><label>شماره پشتیبانی</label>
      <input class="fr-input" name="support_mobile" value="<?= e($s('support_mobile')) ?>" style="direction:ltr"></div>
  </div>
</div>
<?php endif; ?>

<?php if ($tab !== 'sms'): ?>
<button class="fr-btn" onclick="
  const cb=(id,hd)=>{const a=document.getElementById(id),h=document.getElementById(hd);if(a&&h)h.value=a.innerHTML;};
  cb('rte-foot','hd-foot');cb('rte-rfoot','hd-rfoot');cb('rte-pdash','hd-pdash');cb('rte-ldesc','hd-ldesc');">ذخیره تنظیمات ✅</button>
</form>
<?php endif; ?>
<?php admin_layout_end(); ?>
