<?php
/** تنظیمات کلی — برندینگ، فونت، درگاه، پیامک، گزارش + تست پنل پیامک */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/planner.php';
$adm = require_admin(['superadmin']);
$tab = (string)($_GET['tab'] ?? 'brand');

/* ذخیره تنظیمات */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    /* ۲٫۴: نوبت‌های یادآوری پلنر (JSON چندنوبتی) پیش از حلقهٔ عمومی هندل می‌شود */
    if (isset($_POST['planner_remind_times']) || isset($_POST['planner_remind_count'])) {
        fr_planner_remind_save((int)($_POST['planner_remind_count'] ?? 1), (array)($_POST['planner_remind_times'] ?? []));
    } elseif (isset($_POST['planner_remind_time'])) {   // سازگاری با فرم تک‌نوبتی قدیمی
        fr_planner_remind_save(1, [(string)$_POST['planner_remind_time']]);
    }
    $keys = [
        'site_title','site_slogan','logo','logo_alt','footer_text','report_footer','currency_label',  // نسخهٔ ۱٫۷: logo_alt
        'font_main','font_headings','color_primary','color_secondary','color_accent',
        'zarinpal_merchant','zarinpal_sandbox',
        'kavenegar_apikey','kavenegar_sender','kavenegar_enabled',
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
        'panel_dashboard_note','panel_sidebar_note',
        // نسخهٔ ۲٫۰: دیدگاهها + بارکد QR گزارش چاپی
        'reviews_auto_approve',
        'report_qr_link','report_qr_show',
        // نسخهٔ ۲٫۱: برند فوتر + نمادها + برنامه‌ریز روزانه + پیام‌رسان‌ها + خبرنامه
        'logo_desc','footer_links_json','footer_links2_json',
        'footer_badge1_img','footer_badge1_url','footer_badge1_alt','footer_badge1_html',
        'footer_badge2_img','footer_badge2_url','footer_badge2_alt','footer_badge2_html',
        'footer_badge3_img','footer_badge3_url','footer_badge3_alt','footer_badge3_html',
        'footer_badge4_img','footer_badge4_url','footer_badge4_alt','footer_badge4_html',
        'newsletter_title','newsletter_desc',
        'planner_enabled','planner_streak_target','planner_reward_type','planner_reward_percent','planner_reward_message',
        'telegram_bot_token','telegram_bot_username','bale_bot_token','bale_bot_username',
    ];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            $v = (string)$_POST[$k];
            if (in_array($k, ['color_primary','color_secondary','color_accent'], true)) {
                $v = preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : '#1e3a6e';
            }
            if ($k === 'register_enabled') { $v = $v === '1' ? '1' : '0'; }
            if (in_array($k, ['reviews_auto_approve','report_qr_show'], true)) { $v = $v === '1' ? '1' : '0'; }
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
    /* آپلود لوگو */
    if (!empty($_FILES['logo_file']['tmp_name'])) {
        $mime = mime_content_type($_FILES['logo_file']['tmp_name']);
        $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/svg+xml'=>'svg','image/webp'=>'webp'];
        if (isset($map[$mime]) && $_FILES['logo_file']['size'] < 1_500_000) {
            $fn = 'logo.' . $map[$mime];
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], FR_ROOT . '/assets/uploads/' . $fn)) {
                DB::setSetting('logo', SITE_URL . '/assets/uploads/' . $fn);
            }
        }
    }
    /* نسخهٔ ۲٫۱: آپلود تصویر نمادهای فوتر */
    $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/svg+xml'=>'svg','image/webp'=>'webp','image/gif'=>'gif'];
    for ($bi = 1; $bi <= 4; $bi++) {
        $fld = 'badge' . $bi . '_file';
        if (!empty($_FILES[$fld]['tmp_name']) && is_uploaded_file($_FILES[$fld]['tmp_name'])) {
            $mime = mime_content_type($_FILES[$fld]['tmp_name']);
            if (isset($map[$mime]) && $_FILES[$fld]['size'] < 1_000_000) {
                $fn = 'badge-' . $bi . '-' . date('YmdHis') . '.' . $map[$mime];
                if (move_uploaded_file($_FILES[$fld]['tmp_name'], FR_ROOT . '/assets/uploads/' . $fn)) {
                    DB::setSetting('footer_badge' . $bi . '_img', SITE_URL . '/assets/uploads/' . $fn);
                }
            }
        }
    }
    log_activity('admin_settings_save');
    flash('تنظیمات ذخیره شدند ✅');
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
$tabs = [['brand','🎨 برند و ظاهر'],['footer','🦶 فوتر و نمادها'],['fonts','🔤 فونت‌ها'],['texts','📝 متن‌های صفحات'],['panel','🧩 پنل کاربری'],['payment','💳 زرین‌پال'],['sms','📩 کاوه‌نگار'],['report','📄 گزارش'],['reviews','⭐ دیدگاه‌ها'],['consult','🛋 مشاوره'],['planner','🗓 برنامه‌ریز روزانه'],['contact','📞 تماس']];
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
  <h3>هویت سایت</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>عنوان سایت</label>
      <input class="fr-input" name="site_title" value="<?= e($s('site_title','فریا روان')) ?>"></div>
    <div class="fr-field"><label>شعار</label>
      <input class="fr-input" name="site_slogan" value="<?= e($s('site_slogan')) ?>"></div>
    <div class="fr-field"><label>لوگو (آدرس یا آپلود)</label>
      <input class="fr-input" name="logo" value="<?= e($s('logo')) ?>" style="direction:ltr">
      <input type="file" name="logo_file" accept="image/*" style="margin-top:6px;font-size:12px">
      <?php if (fr_logo_url() !== ''): ?>
      <div style="display:flex;align-items:center;gap:10px;margin-top:8px">
        <img src="<?= e(fr_logo_url()) ?>" alt="<?= e(fr_logo_alt()) ?>" style="height:40px;max-width:160px;border-radius:10px;border:1px solid var(--line);object-fit:contain;background:#fff;padding:4px">
        <small style="color:var(--muted)">لوگو در ناوبری، فوتر، سایدبار پنل و پیشخوان مدیریت نمایش داده می‌شود.</small>
      </div>
      <?php endif; ?></div>
    <div class="fr-field"><label>متن alt لوگو (برای سئو و دسترس‌پذیری)</label>
      <input class="fr-input" name="logo_alt" value="<?= e($s('logo_alt')) ?>" maxlength="190"
             placeholder="مثلاً لوگوی فریا روان — سامانه تست روانشناسی و مشاوره"><small class="hint">اگر خالی بماند، همان عنوان سایت استفاده می‌شود.</small></div>
    <div class="fr-field"><label>واحد پول</label>
      <input class="fr-input" name="currency_label" value="<?= e($s('currency_label','تومان')) ?>"></div>
    <div class="fr-field"><label>👥 عضویت کاربران جدید</label>
      <select class="fr-select" name="register_enabled">
        <option value="1" <?= $s('register_enabled','1')==='1'?'selected':'' ?>>فعال — ثبت‌نام فقط با نام و موبایل</option>
        <option value="0" <?= $s('register_enabled','1')==='0'?'selected':'' ?>>غیرفعال — فقط کاربران موجود وارد شوند</option>
      </select>
      <small class="hint">در حالت فعال، هر موبایل جدید فقط با واردکردن «نام و نام خانوادگی» ثبت‌نام می‌کند و بقیهٔ اطلاعات را بعداً در پنل تکمیل می‌کند.</small></div>
  </div>
  <div class="fr-grid cols-3">
    <?php foreach ([['color_primary','رنگ اصلی'],['color_secondary','رنگ دوم'],['color_accent','رنگ تاکید']] as [$k,$lbl]): ?>
    <div class="fr-field"><label><?= $lbl ?></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" name="<?= $k ?>" value="<?= e($s($k,'#1e3a6e')) ?>" style="width:46px;height:40px;border:none;border-radius:10px;cursor:pointer">
        <code style="font-size:12px"><?= e($s($k,'#1e3a6e')) ?></code>
      </div></div>
    <?php endforeach; ?>
  </div>
  <div class="fr-field"><label>متن فوتر سایت (قابل شخصی‌سازی)</label>
    <div class="rte-toolbar" data-rte="foot"></div>
    <div class="rte-area" id="rte-foot" contenteditable="true" style="min-height:90px"><?= e($s('footer_text')) ?></div>
    <input type="hidden" name="footer_text" id="hd-foot"></div>
</div>

<div class="fr-card" style="border:1.5px dashed #d4dcec;background:#fafbfd">
  <h3>✒️ خط اعتباری توسعه‌دهنده (پانوشت سایت و انتهای گزارش‌ها)</h3>
  <p style="font-size:12.5px;color:var(--muted);margin-top:0">
    این خط در پایین صفحات سایت و انتهای همهٔ گزارش‌های تست روانشناسی نمایش داده می‌شود؛ متن و لینکش را می‌توانید دلخواه بنویسید.
    اگر لینک را خالی بگذارید، خط فقط به‌صورت متن ساده نمایش داده می‌شود.
  </p>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>متن خط اعتبار</label>
      <input class="fr-input" name="footer_credit_text" value="<?= e($s('footer_credit_text','طراحی و کد نویسی : احسان نادری پناه – 09024561001 | فریا')) ?>" maxlength="300"></div>
    <div class="fr-field"><label>لینک خط اعتبار (اختیاری)</label>
      <input class="fr-input" name="footer_credit_url" value="<?= e($s('footer_credit_url','https://feria.ir')) ?>" style="direction:ltr" placeholder="https://…" maxlength="255"></div>
  </div>
  <small class="hint">پیش‌نمایش: <span style="border-bottom:1px dashed #999"><?= e(fr_credit_text()) ?></span></small>
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
  <h3>🏅 نمادهای فوتر (۴ جایگاه هم‌اندازه <span class="chip" style="background:#eef2ff">۲٫۲</span>)</h3>
  <p style="font-size:12.5px;color:var(--muted);margin-top:0">مثل نماد اعتماد الکترونیکی (اینماد)، نشان ملی ثبت رسانه‌های دیجیتال (ساماندهی)، درگاه پرداخت امن زرین‌پال و انجمن روان‌شناسی. برای هر جایگاه می‌توانید «تصویر + لینک» بگذارید یا «کد HTML خام» (مثل کد رسمی اینماد)؛ اگر کد HTML پر باشد، جای تصویر قرار می‌گیرد و <b>دقیقاً همان‌طور که هست</b> در فوتر چاپ می‌شود. جایگاه خالی هم در فوتر با قاب نقطه‌چین نمایش داده می‌شود تا چیدمان به‌هم نریزد.</p>
  <div class="fr-grid cols-2">
    <?php for ($bi = 1; $bi <= 4; $bi++):
        $bimg = $s('footer_badge'.$bi.'_img'); $burl = $s('footer_badge'.$bi.'_url'); $balt = $s('footer_badge'.$bi.'_alt'); $bhtm = $s('footer_badge'.$bi.'_html');
        $phTitle = [1=>'نماد اعتماد الکترونیک (اینماد)', 2=>'نشان ملی ثبت (ساماندهی)', 3=>'درگاه پرداخت امن زرین‌پال', 4=>'انجمن روان‌شناسی'][$bi]; ?>
    <div class="fr-card" style="margin:0;background:#fafbfd">
      <h4 style="margin-top:0">نماد <?= fa_num($bi) ?> <small style="color:var(--muted);font-weight:400">— <?= e($phTitle) ?></small></h4>
      <div class="fr-field"><label>عنوان/alt</label>
        <input class="fr-input" name="footer_badge<?= $bi ?>_alt" value="<?= e($balt) ?>" maxlength="120" placeholder="<?= e($phTitle) ?>"></div>
      <div class="fr-field"><label>تصویر (آدرس یا آپلود)</label>
        <input class="fr-input" name="footer_badge<?= $bi ?>_img" value="<?= e($bimg) ?>" style="direction:ltr" placeholder="https://…">
        <input type="file" name="badge<?= $bi ?>_file" accept="image/*" style="margin-top:6px;font-size:12px"></div>
      <div class="fr-field"><label>لینک نماد (اختیاری)</label>
        <input class="fr-input" name="footer_badge<?= $bi ?>_url" value="<?= e($burl) ?>" style="direction:ltr" placeholder="https://trustseal.enamad.ir/…"></div>
      <div class="fr-field"><label>یا کد HTML خام نماد (اختیاری — جایگزین تصویر می‌شود)</label>
        <textarea class="fr-input" name="footer_badge<?= $bi ?>_html" rows="4" style="direction:ltr;font-family:monospace;font-size:11px" placeholder="&lt;img …&gt; / کد رسمی اینماد &lt;a&gt;&lt;img&gt;…"><?= e($bhtm) ?></textarea>
        <small class="hint">کد HTML <b>بدون هیچ دست‌کاری</b> ذخیره و چاپ می‌شود (همین که برای سایت اینماد لازم است).</small></div>
      <!-- ۲٫۲: پیش‌نمایش زندهٔ همان چیزی که در فوتر رندر می‌شود (مشابه جایگاه فوتر) -->
      <div class="fr-field" style="margin-bottom:0"><label>پیش‌نمایش ذخیره‌شدهٔ فعلی</label>
        <div style="width:96px;height:104px;border:1.5px dashed #c3cee2;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#fff;overflow:hidden;padding:6px">
          <?php if ($bhtm !== ''): ?>
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center"><?= $bhtm /* پیش‌نمایش کد خام */ ?></div>
          <?php elseif ($bimg !== ''): ?>
            <img src="<?= e($bimg) ?>" alt="" style="max-width:100%;max-height:88px;object-fit:contain">
          <?php else: ?>
            <div style="font-size:11px;color:#9aa2c7;text-align:center;line-height:1.8">＋<br>خالی است</div>
          <?php endif; ?>
        </div>
        <small class="hint">اگر این‌جا خالی/ناخوانا دیدی، لینک/کد را بررسی کن — همان چیزی می‌شود که بازدیدکننده در فوتر می‌بیند.</small></div>
    </div>
    <?php endfor; ?>
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
</div>

<?php elseif ($tab === 'sms'): ?>
<div class="fr-card">
  <h3>پنل پیامک کاوه‌نگار</h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>کلید API</label>
      <input class="fr-input" name="kavenegar_apikey" value="<?= e($s('kavenegar_apikey')) ?>" style="direction:ltr">
      <div class="hint">از پنل کاوه‌نگار → حساب کاربری دریافت می‌شود.</div></div>
    <div class="fr-field"><label>خط ارسال (شماره سرویس)</label>
      <input class="fr-input" name="kavenegar_sender" value="<?= e($s('kavenegar_sender')) ?>" style="direction:ltr"></div>
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
<div class="fr-card">
  <h3>تنظیمات گزارش‌ها</h3>
  <div class="fr-field"><label>پانوشت سفارشی گزارش (قابل شخصی‌سازی — بالای کارت اعتبار نمایش داده می‌شود)</label>
    <div class="rte-toolbar" data-rte="rfoot"></div>
    <div class="rte-area" id="rte-rfoot" contenteditable="true" style="min-height:90px"><?= e($s('report_footer')) ?></div>
    <input type="hidden" name="report_footer" id="hd-rfoot"></div>
  <div class="fr-alert info" style="font-size:13px">
    💡 کارت اعتبار توسعه‌دهنده (متن و لینک «طراحی و کدنویسی») را می‌توانید از تب
    <a href="?tab=brand">🎨 برند و ظاهر</a> ویرایش کنید؛ همین متن در پانوشت عمومی سایت و گزارش‌ها دیده می‌شود.
  </div>
</div>

<!-- ── بارکد QR گزارش چاپی (نسخهٔ ۲٫۰) ── -->
<div class="fr-card">
  <h3>▣ بارکد QR گزارش چاپی <span class="badge">۲٫۰</span></h3>
  <div class="fr-grid cols-2">
    <div class="fr-field"><label>لینک دلخواه داخل بارکد</label>
      <input class="fr-input" name="report_qr_link" value="<?= e($s('report_qr_link')) ?>" style="direction:ltr" placeholder="<?= SITE_URL ?>" maxlength="255">
      <small class="hint">این لینک داخل بارکد QR انتهای گزارش قرار می‌گیرد (پیش‌فرض: نشانی سایت). زیر بارکد عنوان «Feria.ir» چاپ می‌شود.</small></div>
    <div class="fr-field"><label>نمایش بارکد در گزارش</label>
      <select class="fr-select" name="report_qr_show">
        <option value="1" <?= $s('report_qr_show','1')==='1'?'selected':'' ?>>نمایش داده شود ✅</option>
        <option value="0" <?= $s('report_qr_show','1')==='0'?'selected':'' ?>>پنهان شود</option>
      </select></div>
  </div>
  <div class="fr-alert info" style="font-size:13px;margin-bottom:0">
    💡 بارکد با سرویس نمودار گوگل (Google Chart) ساخته می‌شود؛ اگر اتصال برقرار نبود به‌صورت خودکار با بارکد داخلی جایگزین می‌شود تا چاپ هرگز خراب نشود.
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
