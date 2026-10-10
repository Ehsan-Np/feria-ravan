<?php
/**
 * ============================================================================
 *  صفحهٔ ورود / ثبت‌نام تفکیک‌شده و بازیابی رمز عبور — فریا روان (نسخه ۳٫۴٫۷)
 *  ─ تفکیک کامل و مشخص فرم ورود از فرم ثبت‌نام
 *  ─ ثبت‌نام با نام، موبایل، آیدی (نام کاربری) و رمز عبور دلخواه
 *  ─ سیستم هوشمند هدایت کاربر ثبت‌نام‌نشده به فرم عضویت
 *  ─ بازیابی کامل رمز عبور با شماره تلفن، نام کاربری یا کد ملی
 * ============================================================================
 */
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install/'); exit; }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/core/Kavenegar.php';
fr_bootstrap();

$back = fr_safe_back((string)($_GET['back'] ?? ''));
if (current_user()) redirect($back !== '' ? $back : fr_panel_url());

$initialTab = trim((string)($_GET['tab'] ?? 'login'));
if (!in_array($initialTab, ['login', 'register', 'forgot'], true)) {
    $initialTab = 'login';
}

$siteTitle = (string)DB::setting('site_title', 'فریا روان');
$slogan    = (string)DB::setting('site_slogan', 'مسیر رشد فردی شما از اینجا آغاز می‌شود!');
$otpLen    = (int)DB::setting('otp_length', 5);

render_header('ورود / ثبت‌نام / بازیابی رمز');
?>
<style>
/* ==========================================================================
   استایل اختصاصی صفحهٔ احراز هویت فریا — تفکیک ورود، ثبت‌نام و بازیابی رمز
   ========================================================================== */
.au-wrap{min-height:100vh;display:flex;align-items:stretch;background:
  radial-gradient(1200px 600px at 100% -10%,rgba(143,108,245,.22),transparent 60%),
  radial-gradient(900px 500px at -10% 110%,rgba(30,58,110,.16),transparent 55%),#f4f2ff}
.au-shell{margin:auto;width:100%;max-width:1060px;display:grid;grid-template-columns:1.08fr 1fr;
  gap:26px;padding:26px;align-items:stretch}
.au-side{position:relative;overflow:hidden;border-radius:26px;padding:42px 36px;color:#fff;
  background:linear-gradient(155deg,#1e3a6e,#3e5f9e 55%,#6a4fd8);box-shadow:0 24px 60px rgba(30,58,110,.35);
  display:flex;flex-direction:column;justify-content:center}
.au-side::after{content:"";position:absolute;inset:auto -80px -90px auto;width:280px;height:280px;border-radius:50%;
  background:rgba(255,255,255,.14)}
.au-side::before{content:"";position:absolute;top:-70px;right:-60px;width:200px;height:200px;border-radius:50%;
  background:rgba(255,255,255,.1)}
.au-side .au-brand{display:flex;align-items:center;gap:12px;position:relative}
.au-side .au-brand .mark{width:52px;height:52px;border-radius:16px;background:rgba(255,255,255,.18);
  display:flex;align-items:center;justify-content:center;font-size:27px;flex:0 0 auto}
.au-side .au-brand img.fr-logo{width:52px;height:52px;border-radius:16px;object-fit:contain;background:#fff;padding:6px}
.au-side h1{font-size:23px;margin:22px 0 10px;position:relative;line-height:1.6}
.au-side p{font-size:13.5px;line-height:2.1;opacity:.94;margin:0;position:relative}
.au-side ul{list-style:none;padding:0;margin:24px 0 0;display:flex;flex-direction:column;gap:12px;position:relative}
.au-side li{display:flex;gap:10px;align-items:center;font-size:13px;font-weight:600}
.au-side li i{width:30px;height:30px;border-radius:10px;background:rgba(255,255,255,.2);display:inline-flex;
  align-items:center;justify-content:center;font-style:normal;flex:0 0 auto}

.au-box{background:var(--panel);border:1px solid var(--line);border-radius:26px;box-shadow:var(--shadow);
  padding:34px 32px;display:flex;flex-direction:column;justify-content:center}
.au-box .au-head{text-align:center;margin-bottom:18px}
.au-box .au-head .ico{width:58px;height:58px;border-radius:18px;margin:0 auto 10px;font-size:28px;
  background:linear-gradient(135deg,#eaf0f8,#e2d8fa);display:flex;align-items:center;justify-content:center}
.au-box .au-head h2{font-size:19px;color:var(--c-primary);margin:0 0 6px}
.au-box .au-head p{color:var(--muted);font-size:13px;margin:0}

/* تب‌های تفکیک اصلی (ورود / ثبت‌نام) */
.au-main-tabs{display:flex;background:#eef2f9;border-radius:16px;padding:5px;margin-bottom:20px;gap:6px}
.au-main-tabs button{flex:1;border:none;background:transparent;padding:11px 10px;border-radius:12px;font-family:inherit;
  font-size:14px;font-weight:700;cursor:pointer;color:#50596b;transition:.2s;display:flex;align-items:center;justify-content:center;gap:6px}
.au-main-tabs button.on{background:#fff;color:#1e3a6e;box-shadow:0 3px 14px rgba(30,58,110,.14)}

/* زیرتب‌های روش ورود (پیامک / رمز) */
.au-sub-tabs{display:flex;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:4px;margin-bottom:18px;gap:4px}
.au-sub-tabs button{flex:1;border:none;background:transparent;padding:9px 6px;border-radius:9px;font-family:inherit;
  font-size:12.5px;font-weight:600;cursor:pointer;color:#64748b;transition:.2s}
.au-sub-tabs button.on{background:#fff;color:#6366f1;box-shadow:0 2px 8px rgba(0,0,0,.06)}

.au-view{display:none}
.au-view.on{display:block;animation:auIn .25s ease}
@keyframes auIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

.au-sub-pane{display:none}
.au-sub-pane.on{display:block;animation:auIn .25s ease}

.au-btn{width:100%;justify-content:center;margin-top:4px;padding:13px;font-size:14.5px}
.au-steps{display:flex;justify-content:center;gap:6px;margin-bottom:16px}
.au-steps i{width:26px;height:5px;border-radius:6px;background:#e4defa;transition:.25s}
.au-steps i.on{background:var(--c-primary)}

.au-hint{font-size:11.5px;color:var(--muted);text-align:center;line-height:1.9;margin-top:12px}
.au-foot-link{text-align:center;margin-top:18px;font-size:13px;color:#64748b}
.au-foot-link a{color:#6366f1;font-weight:700;text-decoration:none}
.au-foot-link a:hover{text-decoration:underline}

.au-forgot-link{display:inline-block;color:#6366f1;font-size:12.5px;font-weight:600;text-decoration:none;margin-top:10px}
.au-forgot-link:hover{text-decoration:underline}

input.au-code{letter-spacing:10px;font-size:21px;text-align:center;direction:ltr;font-weight:800}

/* موبایل */
@media (max-width:880px){
  .au-shell{grid-template-columns:1fr;padding:14px;max-width:480px}
  .au-side{order:-1;padding:26px 22px}
  .au-side h1{font-size:19px;margin:16px 0 8px}
  .au-side ul{display:none}
  .au-box{padding:26px 20px;border-radius:22px}
}
@media (max-width:420px){.au-shell{padding:10px}}
</style>

<div class="au-wrap">
  <main class="au-shell">

    <!-- کارت فرم اصلی -->
    <section class="au-box" id="authBox" data-back="<?= e($back) ?>">
      <div class="au-head">
        <div class="ico">🦢</div>
        <h2 id="auTitle"><?= e($siteTitle) ?></h2>
        <p id="auSubtitle">ورود به حساب کاربری یا عضویت جدید در سامانه</p>
      </div>

      <!-- تب‌های تفکیک اصلی: ورود / ثبت‌نام -->
      <div class="au-main-tabs" role="tablist" id="mainTabContainer">
        <button id="btnMainLogin" class="<?= $initialTab === 'login' ? 'on' : '' ?>" type="button">
          <span>🔐</span> ورود به حساب
        </button>
        <button id="btnMainRegister" class="<?= $initialTab === 'register' ? 'on' : '' ?>" type="button">
          <span>📝</span> ثبت‌نام جدید
        </button>
      </div>

      <!-- ================================================================== -->
      <!-- ویو ۱: ورود به حساب کاربری -->
      <!-- ================================================================== -->
      <div class="au-view <?= $initialTab === 'login' ? 'on' : '' ?>" id="viewLogin">
        <!-- زیرتب‌های روش ورود -->
        <div class="au-sub-tabs" role="tablist">
          <button id="subTabOtp" class="on" type="button">کد پیامکی یک‌بارمصرف 📩</button>
          <button id="subTabPwd" type="button">رمز عبور 🔑</button>
        </div>

        <!-- روش ۱: ورود با کد یک‌بارمصرف -->
        <div class="au-sub-pane on" id="subPaneOtp">
          <!-- گام ۱: شماره موبایل -->
          <div id="loginOtpStep1">
            <div class="fr-field">
              <label>شماره موبایل حساب شما</label>
              <input class="fr-input" id="loginMobile" inputmode="tel" maxlength="20"
                     placeholder="مثلاً ۰۹۱۲۳۴۵۶۷۸۹ یا ۰۹۹۹۱..." autocomplete="tel" style="direction:ltr;text-align:center">
              <div id="loginOpBadge" style="display:none;font-size:12px;margin-top:6px;text-align:center;color:#475569"></div>
            </div>
            <button class="fr-btn au-btn" id="btnLoginSendOtp">ارسال کد تأیید ورود</button>
            <div style="text-align:center;margin-top:10px">
              <a href="javascript:void(0)" onclick="const m=$('loginMobile').value.trim(); if(m){ $('loginSentTo').textContent=m; setLoginOtpStep(2); } else { toast('ابتدا شماره موبایل را وارد نمایید.','warn'); $('loginMobile').focus(); }" style="font-size:12px;color:#6366f1;text-decoration:none">کد ورود را از قبل دارید؟ ورود کد ↵</a>
            </div>
            <p class="au-hint">کد <?= fa_num($otpLen) ?> رقمی برای شما پیامک می‌شود و بدون نیاز به رمز وارد می‌شوید.</p>
          </div>

          <!-- گام ۲: ورود کد -->
          <div id="loginOtpStep2" style="display:none">
            <p class="fr-alert info" style="font-size:13px">کد تأیید ورود به <b id="loginSentTo" style="direction:ltr;display:inline-block"></b> ارسال شد.</p>
            <div id="loginOtpDebugBox" class="fr-alert warn" style="display:none;font-size:13.5px;line-height:2;text-align:center;margin-bottom:14px"></div>
            <div class="fr-field">
              <label>کد تأیید <?= fa_num($otpLen) ?> رقمی</label>
              <input class="fr-input au-code" id="loginOtpCode" inputmode="numeric" maxlength="<?= (int)$otpLen ?>"
                     placeholder="– – – – –" autocomplete="one-time-code">
            </div>
            <button class="fr-btn au-btn" id="btnLoginVerifyOtp">تأیید و ورود به پنل ✨</button>
            <button class="fr-btn ghost sm" style="width:100%;justify-content:center;margin-top:10px" id="btnLoginResend" disabled>
              ارسال مجدد (<span id="loginResendTimer">۱۲۰</span>)
            </button>
            <button type="button" class="fr-btn ghost xs" style="width:100%;margin-top:8px;font-size:12px" onclick="setLoginOtpStep(1);$('loginMobile').focus();">
              ✏️ ویرایش شماره موبایل
            </button>
          </div>
        </div>

        <!-- روش ۲: ورود با رمز عبور -->
        <div class="au-sub-pane" id="subPanePwd">
          <div class="fr-field">
            <label>شناسه کاربری (شماره همراه، نام کاربری یا کد ملی)</label>
            <input class="fr-input" id="pwdIdentity" inputmode="text" autocomplete="username" style="direction:ltr;text-align:center"
                   placeholder="09xxxxxxxxx / آیدی / کد ملی">
          </div>
          <div class="fr-field">
            <label>رمز عبور</label>
            <input class="fr-input" id="pwdPassword" type="password" autocomplete="current-password" style="direction:ltr">
          </div>
          <button class="fr-btn au-btn" id="btnPwdLogin">ورود به حساب کاربری ✨</button>
          
          <div style="text-align:center;margin-top:14px">
            <a href="javascript:void(0)" onclick="switchMainTab('forgot')" class="au-forgot-link">🔑 رمز عبور خود را فراموش کرده‌اید؟ بازیابی رمز</a>
          </div>
        </div>

        <div class="au-foot-link">
          حساب کاربری ندارید؟ <a href="javascript:void(0)" onclick="switchMainTab('register')">ثبت‌نام جدید در سامانه</a>
        </div>
      </div>

      <!-- ================================================================== -->
      <!-- ویو ۲: ثبت‌نام مجزا و کامل -->
      <!-- ================================================================== -->
      <div class="au-view <?= $initialTab === 'register' ? 'on' : '' ?>" id="viewRegister">
        <!-- گام ۱: تکمیل اطلاعات عضویت -->
        <div id="regStep1">
          <div class="fr-field">
            <label>نام و نام خانوادگی <span class="req">*</span></label>
            <input class="fr-input" id="regFullName" maxlength="150" placeholder="مثل: سارا محمدی" autocomplete="name" style="text-align:center">
            <small style="display:block;color:var(--muted);font-size:11.5px;margin-top:4px">نام و نام خانوادگی را با فاصله وارد نمایید.</small>
          </div>

          <div class="fr-field">
            <label>شماره تلفن همراه <span class="req">*</span></label>
            <input class="fr-input" id="regMobile" inputmode="tel" maxlength="20"
                   placeholder="مثلاً ۰۹۱۲۳۴۵۶۷۸۹ یا ۰۹۹۹۱..." autocomplete="tel" style="direction:ltr;text-align:center">
            <div id="regOpBadge" style="display:none;font-size:12px;margin-top:6px;text-align:center;color:#475569"></div>
          </div>

          <div class="fr-field">
            <label>آیدی / نام کاربری دلخواه <small style="color:var(--muted);font-weight:normal">(اختیاری — جهت ورود آسان)</small></label>
            <input class="fr-input" id="regUsername" maxlength="30" placeholder="حروف لاتین مثلاً sara_m" autocomplete="username" style="direction:ltr;text-align:center">
            <small style="display:block;color:var(--muted);font-size:11.5px;margin-top:4px">حروف انگلیسی، عدد یا نقطه (حداقل ۴ کاراکتر)</small>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="fr-field">
              <label>رمز عبور <span class="req">*</span></label>
              <input class="fr-input" id="regPassword" type="password" maxlength="60" placeholder="حداقل ۶ نویسه" autocomplete="new-password" style="direction:ltr">
            </div>
            <div class="fr-field">
              <label>تکرار رمز عبور <span class="req">*</span></label>
              <input class="fr-input" id="regPasswordConfirm" type="password" maxlength="60" placeholder="تکرار رمز" autocomplete="new-password" style="direction:ltr">
            </div>
          </div>

          <div class="fr-field">
            <label>کد ملی ۱۰ رقمی <small style="color:var(--muted);font-weight:normal">(اختیاری — جهت صدور دقیق گواهی و آزمون‌ها)</small></label>
            <input class="fr-input" id="regNationalCode" inputmode="numeric" maxlength="10" placeholder="– – – – – – – – – –" autocomplete="off" style="direction:ltr;text-align:center">
          </div>

          <button class="fr-btn au-btn" id="btnRegStart">ثبت‌نام و دریافت کد تأیید 📩</button>
        </div>

        <!-- گام ۲: تأیید شماره موبایل ثبت‌نام -->
        <div id="regStep2" style="display:none">
          <p class="fr-alert info" style="font-size:13px">کد تأیید عضویت به <b id="regSentTo" style="direction:ltr;display:inline-block"></b> پیامک شد.</p>
          <div id="regDebugBox" class="fr-alert warn" style="display:none;font-size:13.5px;line-height:2;text-align:center;margin-bottom:14px"></div>
          <div class="fr-field">
            <label>کد تأیید <?= fa_num($otpLen) ?> رقمی</label>
            <input class="fr-input au-code" id="regOtpCode" inputmode="numeric" maxlength="<?= (int)$otpLen ?>"
                   placeholder="– – – – –" autocomplete="one-time-code">
          </div>
          <button class="fr-btn au-btn" id="btnRegComplete">تأیید نهایی و ساخت حساب 🎉</button>
          <button class="fr-btn ghost sm" style="width:100%;justify-content:center;margin-top:10px" id="btnRegResend" disabled>
            ارسال مجدد (<span id="regResendTimer">۱۲۰</span>)
          </button>
          <button type="button" class="fr-btn ghost xs" style="width:100%;margin-top:8px;font-size:12px" onclick="setRegStep(1);">
            ✏️ بازگشت و ویرایش مشخصات
          </button>
        </div>

        <div class="au-foot-link">
          قبلاً در سایت حساب کاربری ساخته‌اید؟ <a href="javascript:void(0)" onclick="switchMainTab('login')">ورود به حساب</a>
        </div>
      </div>

      <!-- ================================================================== -->
      <!-- ویو ۳: بازیابی رمز عبور (شماره موبایل، نام کاربری یا کد ملی) -->
      <!-- ================================================================== -->
      <div class="au-view <?= $initialTab === 'forgot' ? 'on' : '' ?>" id="viewForgot">
        <!-- گام ۱: وارد کردن هویت -->
        <div id="forgotStep1">
          <div class="fr-alert info" style="font-size:12.5px;line-height:2;margin-bottom:18px">
            با وارد کردن هر یک از شناسه‌های حساب خود (شماره تلفن همراه، نام کاربری یا کد ملی)، کد تأیید پیامک می‌شود و می‌توانید رمز عبور جدید تعیین کنید.
          </div>
          <div class="fr-field">
            <label>شماره تلفن همراه، نام کاربری یا کد ملی <span class="req">*</span></label>
            <input class="fr-input" id="forgotIdentity" inputmode="text" style="direction:ltr;text-align:center"
                   placeholder="09xxxxxxxxx / آیدی کاربری / کد ملی">
          </div>
          <button class="fr-btn au-btn" id="btnForgotSend">ارسال کد تأیید بازیابی 📩</button>
        </div>

        <!-- گام ۲: ورود کد و رمز عبور جدید -->
        <div id="forgotStep2" style="display:none">
          <p class="fr-alert info" style="font-size:13px">کد تأیید بازیابی به شمارهٔ <b id="forgotMaskedMobile" style="direction:ltr;display:inline-block"></b> پیامک شد.</p>
          <div id="forgotDebugBox" class="fr-alert warn" style="display:none;font-size:13.5px;line-height:2;text-align:center;margin-bottom:14px"></div>

          <div class="fr-field">
            <label>کد تأیید <?= fa_num($otpLen) ?> رقمی پیامک‌شده <span class="req">*</span></label>
            <input class="fr-input au-code" id="forgotCode" inputmode="numeric" maxlength="<?= (int)$otpLen ?>"
                   placeholder="– – – – –" autocomplete="one-time-code">
          </div>

          <div class="fr-field">
            <label>رمز عبور جدید <span class="req">*</span></label>
            <input class="fr-input" id="forgotNewPass" type="password" maxlength="60" placeholder="حداقل ۶ نویسه" autocomplete="new-password" style="direction:ltr">
          </div>

          <div class="fr-field">
            <label>تکرار رمز عبور جدید <span class="req">*</span></label>
            <input class="fr-input" id="forgotNewPassConfirm" type="password" maxlength="60" placeholder="تکرار رمز جدید" autocomplete="new-password" style="direction:ltr">
          </div>

          <button class="fr-btn au-btn" id="btnForgotVerify">ثبت رمز جدید و ورود به حساب ✨</button>

          <button class="fr-btn ghost sm" style="width:100%;justify-content:center;margin-top:10px" id="btnForgotResend" disabled>
            ارسال مجدد (<span id="forgotResendTimer">۱۲۰</span>)
          </button>
          <button type="button" class="fr-btn ghost xs" style="width:100%;margin-top:8px;font-size:12px" onclick="setForgotStep(1);">
            ✏️ تغییر شناسه / شماره تلفن
          </button>
        </div>

        <div class="au-foot-link">
          <a href="javascript:void(0)" onclick="switchMainTab('login')">← بازگشت به فرم ورود</a>
        </div>
      </div>

      <a class="au-link" href="<?= SITE_URL ?>/" style="display:block;text-align:center;margin-top:20px;font-size:13px;color:#64748b;text-decoration:none">
        → بازگشت به صفحهٔ اصلی وب‌سایت
      </a>
    </section>

    <!-- پنل برند -->
    <aside class="au-side">
      <div class="au-brand">
        <?= fr_logo_html('mark') ?>
        <div>
          <b style="font-size:16.5px;display:block"><?= e($siteTitle) ?></b>
          <small style="opacity:.9"><?= e($slogan) ?></small>
        </div>
      </div>
      <h1>از همین‌جا خودشناسی شروع می‌شود 🧠</h1>
      <p>
        با عضویت یا ورود به حسابتان، تست‌های روانشناسی، کارگاه‌ها، دوره‌ها و نوبت‌های مشاوره
        همه در یک پنل یکپارچه در اختیارتان است؛ نتایج و تحلیل‌ها همیشه ماندگار خواهند بود.
      </p>
      <ul>
        <li><i>📩</i> ورود سریع با پیامک بدون نیاز به حفظ رمز</li>
        <li><i>🔑</i> امکان تعیین رمز عبور دلخواه و نام کاربری اختصاصی</li>
        <li><i>🛡️</i> بازیابی آسان رمز با شماره موبایل، آیدی یا کد ملی</li>
        <li><i>🔐</i> تضمین حریم خصوصی و امنیت کامل داده‌های شما</li>
      </ul>
    </aside>
  </main>
</div>

<script>
const $=id=>document.getElementById(id);
const FR_BACK=($('authBox')||{getAttribute:()=>''}).getAttribute('data-back')||'';
const FR_AUTH_API = '<?= rtrim(SITE_URL, "/") ?>/api/auth.php';

/* ==========================================================================
   مدیریت تب‌های اصلی (ورود / ثبت‌نام / بازیابی رمز)
   ========================================================================== */
function switchMainTab(tab){
  const views = { login: $('viewLogin'), register: $('viewRegister'), forgot: $('viewForgot') };
  const tabContainer = $('mainTabContainer');
  
  if (tab === 'forgot') {
    tabContainer.style.display = 'none';
    $('auTitle').textContent = 'بازیابی رمز عبور';
    $('auSubtitle').textContent = 'تغییر رمز عبور با شماره همراه، نام کاربری یا کد ملی';
  } else {
    tabContainer.style.display = 'flex';
    $('auTitle').textContent = '<?= e($siteTitle) ?>';
    $('auSubtitle').textContent = tab === 'login' ? 'ورود به حساب کاربری' : 'ثبت‌نام جدید و ساخت حساب در سامانه';
  }

  $('btnMainLogin').classList.toggle('on', tab === 'login');
  $('btnMainRegister').classList.toggle('on', tab === 'register');

  for (let k in views) {
    if (views[k]) views[k].classList.toggle('on', k === tab);
  }
}

$('btnMainLogin').onclick = () => switchMainTab('login');
$('btnMainRegister').onclick = () => switchMainTab('register');

/* ==========================================================================
   زیرتب‌های روش ورود (پیامک / رمز)
   ========================================================================== */
function selectLoginSubTab(sub){
  $('subTabOtp').classList.toggle('on', sub === 'otp');
  $('subTabPwd').classList.toggle('on', sub === 'pwd');
  $('subPaneOtp').classList.toggle('on', sub === 'otp');
  $('subPanePwd').classList.toggle('on', sub === 'pwd');
}
$('subTabOtp').onclick = () => selectLoginSubTab('otp');
$('subTabPwd').onclick = () => selectLoginSubTab('pwd');

/* ==========================================================================
   تشخیص هوشمند اپراتور موبایل ایران
   ========================================================================== */
function detectOpJs(m){
  m = (m||'').replace(/[۰-۹]/g, d => "۰۱۲۳۴۵۶۷۸۹".indexOf(d)).replace(/[^\d\+]/g, '');
  if (m.startsWith('+98')) m = m.substring(3);
  else if (m.startsWith('0098')) m = m.substring(4);
  else if (m.startsWith('98') && m.length >= 12) m = m.substring(2);
  m = m.replace(/^0+/, '');
  if (m.startsWith('9')) m = '0' + m;

  if (m.length < 4) return null;
  const p3 = m.substring(0, 4);
  const p4 = m.substring(0, 5);

  if (p4 === '09991') return { name: 'آپتل (Aptel)', color: '#0284c7' };
  if (p4 === '09999' || p4 === '09996') return { name: 'سامان‌تل (SamanTel)', color: '#0d9488' };
  if (p3 === '0998') return { name: 'شاتل موبایل', color: '#d97706' };
  if (p4 === '09990') return { name: 'لوتوس‌تل', color: '#6366f1' };
  if (p4 === '09998') return { name: 'آرین‌تل', color: '#8b5cf6' };
  if (p4 === '09997') return { name: 'فناپ موبایل', color: '#ec4899' };
  if (p3 === '0932') return { name: 'تالیا (Taliya)', color: '#ea580c' };
  if (['0920','0921','0922','0923'].includes(p3)) return { name: 'رایتل (Rightel)', color: '#7c3aed' };
  if (['0930','0933','0935','0936','0937','0938','0939','0900','0901','0902','0903','0904','0905','0941'].includes(p3)) {
    return { name: 'ایرانسل (Irancell)', color: '#ca8a04' };
  }
  if (['0910','0911','0912','0913','0914','0915','0916','0917','0918','0919','0990','0991','0992','0993','0994','0996'].includes(p3)) {
    return { name: p3 === '0994' ? 'همراه اول (سیم‌کارت انارستان)' : 'همراه اول (ارتباطات سیار)', color: '#0284c7' };
  }
  if (m.startsWith('09') && m.length >= 4) return { name: 'اپراتور همراه ایران', color: '#475569' };
  return null;
}

function attachOpBadge(inputId, badgeId){
  const inp = $(inputId);
  const badge = $(badgeId);
  if (!inp || !badge) return;
  inp.addEventListener('input', e => {
    const op = detectOpJs(e.target.value.trim());
    if (op) {
      badge.style.display = 'block';
      badge.innerHTML = '<span style="display:inline-block;padding:2px 10px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;color:' + op.color + ';font-weight:700">📱 اپراتور شناسایی‌شده: ' + op.name + '</span>';
    } else {
      badge.style.display = 'none';
    }
  });
}
attachOpBadge('loginMobile', 'loginOpBadge');
attachOpBadge('regMobile', 'regOpBadge');

/* ==========================================================================
   ۱. بخش ورود با کد یک‌بارمصرف (OTP Login)
   ========================================================================== */
function setLoginOtpStep(step){
  $('loginOtpStep1').style.display = step === 1 ? 'block' : 'none';
  $('loginOtpStep2').style.display = step === 2 ? 'block' : 'none';
  if (step === 1) $('loginMobile').focus(); else $('loginOtpCode').focus();
}

let loginTimer = null;
function startLoginTimer(sec){
  clearInterval(loginTimer);
  $('btnLoginResend').disabled = true;
  $('loginResendTimer').textContent = faNum(sec);
  let left = sec;
  loginTimer = setInterval(() => {
    left--;
    $('loginResendTimer').textContent = faNum(Math.max(left, 0));
    if (left <= 0) {
      clearInterval(loginTimer);
      $('btnLoginResend').disabled = false;
      $('btnLoginResend').innerHTML = 'ارسال مجدد کد ورود';
    }
  }, 1000);
}

async function doSendLoginOtp(){
  const mobile = $('loginMobile').value.trim();
  if (!mobile) { toast('لطفاً شماره موبایل خود را وارد کنید.', 'err'); $('loginMobile').focus(); return; }
  const btn = $('btnLoginSendOtp');
  btn.disabled = true;
  try {
    const r = await api(FR_AUTH_API, { action: 'send_otp', mobile, purpose: 'login' });
    if (!r.ok) {
      // اگر شماره ثبت‌نام نشده بود، انتقال خودکار و راهنما به تب ثبت‌نام
      if (r.not_registered) {
        toast(r.message, 'warn');
        switchMainTab('register');
        $('regMobile').value = mobile;
        attachOpBadge('regMobile', 'regOpBadge');
        const badge = $('regOpBadge');
        const op = detectOpJs(mobile);
        if (op && badge) {
          badge.style.display = 'block';
          badge.innerHTML = '<span style="display:inline-block;padding:2px 10px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;color:' + op.color + ';font-weight:700">📱 اپراتور شناسایی‌شده: ' + op.name + '</span>';
        }
        $('regFullName').focus();
        return;
      }
      toast(r.message, 'err');
    } else {
      toast(r.message);
      const dbg = $('loginOtpDebugBox');
      if (r.debug_code) {
        if (dbg) {
          dbg.style.display = 'block';
          dbg.innerHTML = 'کد ورود شما (آزمایشی): <b style="font-size:18px;letter-spacing:4px;color:#5b21b6;direction:ltr;display:inline-block">' + r.debug_code + '</b>';
        }
        $('loginOtpCode').value = r.debug_code;
      } else {
        if (dbg) dbg.style.display = 'none';
        $('loginOtpCode').value = '';
      }
      $('loginSentTo').textContent = mobile;
      setLoginOtpStep(2);
      startLoginTimer(r.ttl || 120);
      $('loginOtpCode').focus();
    }
  } catch(e) {
    toast('خطایی در ارتباط با سرور رخ داد؛ لطفاً دوباره تلاش کنید.', 'err');
  } finally {
    btn.disabled = false;
  }
}
$('btnLoginSendOtp').onclick = doSendLoginOtp;
$('btnLoginResend').onclick = doSendLoginOtp;
$('loginMobile').addEventListener('keydown', e => { if (e.key === 'Enter') doSendLoginOtp(); });

async function doVerifyLoginOtp(){
  const mobile = $('loginMobile').value.trim();
  const code = $('loginOtpCode').value.trim();
  if (!code) { toast('لطفاً کد تأیید را وارد کنید.', 'err'); $('loginOtpCode').focus(); return; }
  const btn = $('btnLoginVerifyOtp');
  btn.disabled = true;
  try {
    const r = await api(FR_AUTH_API, { action: 'verify_otp', mobile, code, purpose: 'login', back: FR_BACK });
    if (!r.ok) {
      toast(r.message, 'err');
    } else {
      toast(r.message || 'خوش آمدید 🦢');
      location.href = r.redirect || 'panel/';
    }
  } finally {
    btn.disabled = false;
  }
}
$('btnLoginVerifyOtp').onclick = doVerifyLoginOtp;
$('loginOtpCode').addEventListener('keydown', e => { if (e.key === 'Enter') doVerifyLoginOtp(); });
$('loginOtpCode').addEventListener('input', e => {
  if (e.target.value.trim().length === <?= (int)$otpLen ?>) doVerifyLoginOtp();
});

/* ==========================================================================
   ۲. بخش ورود با رمز عبور
   ========================================================================== */
async function doPwdLogin(){
  const identity = $('pwdIdentity').value.trim();
  const password = $('pwdPassword').value;
  if (!identity) { toast('شماره تلفن، نام کاربری یا کد ملی را وارد کنید.', 'err'); $('pwdIdentity').focus(); return; }
  if (!password) { toast('رمز عبور را وارد کنید.', 'err'); $('pwdPassword').focus(); return; }
  const btn = $('btnPwdLogin');
  btn.disabled = true;
  try {
    const r = await api(FR_AUTH_API, { action: 'password_login', identity, password, back: FR_BACK });
    if (!r.ok) {
      toast(r.message, 'err');
      if (r.no_password) {
        // هدایت سریع به کد پیامکی
        selectLoginSubTab('otp');
        if (r.mobile) {
          $('loginMobile').value = r.mobile;
          doSendLoginOtp();
        }
      }
    } else {
      toast('خوش آمدید 🦢');
      location.href = r.redirect || 'panel/';
    }
  } finally {
    btn.disabled = false;
  }
}
$('btnPwdLogin').onclick = doPwdLogin;
$('pwdPassword').addEventListener('keydown', e => { if (e.key === 'Enter') doPwdLogin(); });
$('pwdIdentity').addEventListener('keydown', e => { if (e.key === 'Enter') $('pwdPassword').focus(); });

/* ==========================================================================
   ۳. بخش ثبت‌نام کامل و مجزا (Full Registration)
   ========================================================================== */
function setRegStep(step){
  $('regStep1').style.display = step === 1 ? 'block' : 'none';
  $('regStep2').style.display = step === 2 ? 'block' : 'none';
  if (step === 1) $('regFullName').focus(); else $('regOtpCode').focus();
}

let regTimer = null;
function startRegTimer(sec){
  clearInterval(regTimer);
  $('btnRegResend').disabled = true;
  $('regResendTimer').textContent = faNum(sec);
  let left = sec;
  regTimer = setInterval(() => {
    left--;
    $('regResendTimer').textContent = faNum(Math.max(left, 0));
    if (left <= 0) {
      clearInterval(regTimer);
      $('btnRegResend').disabled = false;
      $('btnRegResend').innerHTML = 'ارسال مجدد کد';
    }
  }, 1000);
}

async function doRegStart(){
  const full_name = $('regFullName').value.trim();
  const mobile = $('regMobile').value.trim();
  const username = $('regUsername').value.trim();
  const password = $('regPassword').value;
  const password_confirm = $('regPasswordConfirm').value;
  const national_code = $('regNationalCode').value.trim();

  if (!full_name) { toast('لطفاً نام و نام خانوادگی را وارد کنید.', 'err'); $('regFullName').focus(); return; }
  if (!mobile) { toast('شماره تلفن همراه الزامی است.', 'err'); $('regMobile').focus(); return; }
  if (!password) { toast('رمز عبور الزامی است.', 'err'); $('regPassword').focus(); return; }
  if (password.length < 6) { toast('رمز عبور باید حداقل ۶ کاراکتر باشد.', 'err'); $('regPassword').focus(); return; }
  if (password !== password_confirm) { toast('رمز عبور و تکرار آن یکسان نیستند.', 'err'); $('regPasswordConfirm').focus(); return; }

  const btn = $('btnRegStart');
  btn.disabled = true;
  try {
    const r = await api(FR_AUTH_API, {
      action: 'register_start',
      full_name, mobile, username, password, password_confirm, national_code
    });
    if (!r.ok) {
      if (r.already_registered) {
        toast(r.message, 'warn');
        switchMainTab('login');
        $('loginMobile').value = mobile;
        selectLoginSubTab('otp');
        doSendLoginOtp();
        return;
      }
      toast(r.message, 'err');
    } else {
      toast(r.message);
      const dbg = $('regDebugBox');
      if (r.debug_code) {
        if (dbg) {
          dbg.style.display = 'block';
          dbg.innerHTML = 'کد تأیید عضویت شما: <b style="font-size:18px;letter-spacing:4px;color:#5b21b6;direction:ltr;display:inline-block">' + r.debug_code + '</b>';
        }
        $('regOtpCode').value = r.debug_code;
      } else {
        if (dbg) dbg.style.display = 'none';
        $('regOtpCode').value = '';
      }
      $('regSentTo').textContent = mobile;
      setRegStep(2);
      startRegTimer(r.ttl || 120);
      $('regOtpCode').focus();
    }
  } catch(e) {
    toast('خطایی در برقراری ارتباط رخ داد.', 'err');
  } finally {
    btn.disabled = false;
  }
}
$('btnRegStart').onclick = doRegStart;
$('btnRegResend').onclick = doRegStart;

async function doRegComplete(){
  const mobile = $('regMobile').value.trim();
  const code = $('regOtpCode').value.trim();
  if (!code) { toast('کد تأیید پیامک‌شده را وارد نمایید.', 'err'); $('regOtpCode').focus(); return; }
  const btn = $('btnRegComplete');
  btn.disabled = true;
  try {
    const r = await api(FR_AUTH_API, { action: 'register_complete', mobile, code, back: FR_BACK });
    if (!r.ok) {
      toast(r.message, 'err');
    } else {
      toast(r.message);
      location.href = r.redirect || 'panel/?welcome=1';
    }
  } finally {
    btn.disabled = false;
  }
}
$('btnRegComplete').onclick = doRegComplete;
$('regOtpCode').addEventListener('keydown', e => { if (e.key === 'Enter') doRegComplete(); });
$('regOtpCode').addEventListener('input', e => {
  if (e.target.value.trim().length === <?= (int)$otpLen ?>) doRegComplete();
});

/* ==========================================================================
   ۴. بخش بازیابی رمز عبور (Password Recovery)
   ========================================================================== */
function setForgotStep(step){
  $('forgotStep1').style.display = step === 1 ? 'block' : 'none';
  $('forgotStep2').style.display = step === 2 ? 'block' : 'none';
  if (step === 1) $('forgotIdentity').focus(); else $('forgotCode').focus();
}

let forgotTimer = null;
let currentForgotMobile = '';
function startForgotTimer(sec){
  clearInterval(forgotTimer);
  $('btnForgotResend').disabled = true;
  $('forgotResendTimer').textContent = faNum(sec);
  let left = sec;
  forgotTimer = setInterval(() => {
    left--;
    $('forgotResendTimer').textContent = faNum(Math.max(left, 0));
    if (left <= 0) {
      clearInterval(forgotTimer);
      $('btnForgotResend').disabled = false;
      $('btnForgotResend').innerHTML = 'ارسال مجدد کد بازیابی';
    }
  }, 1000);
}

async function doForgotSend(){
  const identity = $('forgotIdentity').value.trim();
  if (!identity) {
    toast('لطفاً شماره تلفن همراه، نام کاربری یا کد ملی خود را وارد نمایید.', 'err');
    $('forgotIdentity').focus();
    return;
  }
  const btn = $('btnForgotSend');
  btn.disabled = true;
  try {
    const r = await api(FR_AUTH_API, { action: 'forgot_password_send', identity });
    if (!r.ok) {
      toast(r.message, 'err');
    } else {
      toast(r.message);
      currentForgotMobile = r.mobile || '';
      $('forgotMaskedMobile').textContent = r.masked_mobile || r.mobile;
      const dbg = $('forgotDebugBox');
      if (r.debug_code) {
        if (dbg) {
          dbg.style.display = 'block';
          dbg.innerHTML = 'کد بازیابی رمز (آزمایشی): <b style="font-size:18px;letter-spacing:4px;color:#5b21b6;direction:ltr;display:inline-block">' + r.debug_code + '</b>';
        }
        $('forgotCode').value = r.debug_code;
      } else {
        if (dbg) dbg.style.display = 'none';
        $('forgotCode').value = '';
      }
      setForgotStep(2);
      startForgotTimer(r.ttl || 120);
      $('forgotCode').focus();
    }
  } catch(e) {
    toast('خطایی در ارتباط با سرور رخ داد.', 'err');
  } finally {
    btn.disabled = false;
  }
}
$('btnForgotSend').onclick = doForgotSend;
$('btnForgotResend').onclick = doForgotSend;
$('forgotIdentity').addEventListener('keydown', e => { if (e.key === 'Enter') doForgotSend(); });

async function doForgotVerify(){
  const code = $('forgotCode').value.trim();
  const new_password = $('forgotNewPass').value;
  const new_password_confirm = $('forgotNewPassConfirm').value;

  if (!code) { toast('کد تأیید پیامک‌شده را وارد کنید.', 'err'); $('forgotCode').focus(); return; }
  if (!new_password) { toast('رمز عبور جدید را وارد کنید.', 'err'); $('forgotNewPass').focus(); return; }
  if (new_password.length < 6) { toast('رمز عبور باید حداقل ۶ کاراکتر باشد.', 'err'); $('forgotNewPass').focus(); return; }
  if (new_password !== new_password_confirm) { toast('رمز جدید با تکرار آن همخوانی ندارد.', 'err'); $('forgotNewPassConfirm').focus(); return; }

  const btn = $('btnForgotVerify');
  btn.disabled = true;
  try {
    const r = await api(FR_AUTH_API, {
      action: 'forgot_password_verify',
      mobile: currentForgotMobile,
      code,
      new_password,
      new_password_confirm,
      back: FR_BACK
    });
    if (!r.ok) {
      toast(r.message, 'err');
    } else {
      toast(r.message);
      location.href = r.redirect || 'panel/';
    }
  } finally {
    btn.disabled = false;
  }
}
$('btnForgotVerify').onclick = doForgotVerify;
$('forgotNewPassConfirm').addEventListener('keydown', e => { if (e.key === 'Enter') doForgotVerify(); });
</script>
<?php render_footer(); ?>
