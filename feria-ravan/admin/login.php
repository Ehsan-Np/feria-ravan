<?php
/**
 * ============================================================================
 *  صفحهٔ ورود و بازیابی رمز عبور مدیریت — فریا روان
 *  ─ ورود با نام کاربری، موبایل یا ایمیل و کلمه عبور
 *  ─ ورود امن با رمز یک‌بارمصرف پیامکی (Admin OTP Login)
 *  ─ بازیابی و تغییر رمز عبور مدیران با پیامک (Admin Password Reset)
 *  ─ پشتیبانی دوگانه از تعامل بلادرنگ AJAX و ارسال فرم سنتی (بدون جاوااسکریپت)
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

if (current_admin()) {
    redirect(SITE_URL . '/admin/dashboard.php');
}

$siteTitle = (string)DB::setting('site_title', 'فریا روان');
$otpLen    = (int)DB::setting('otp_length', 5);

$initialTab = trim((string)($_GET['tab'] ?? 'password'));
if (!in_array($initialTab, ['password', 'otp', 'reset', 'forgot'], true)) {
    $initialTab = 'password';
}
if ($initialTab === 'forgot') {
    $initialTab = 'reset';
}

$err = '';
$okMsg = '';
$postStep = 1;
$maskedMobile = '';
$debugCode = null;

// پردازش فرم سنتی (در صورت غیرفعال بودن جاوااسکریپت یا ارسال مستقیم POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = trim((string)($_POST['act'] ?? 'password_login'));

    if ($act === 'password_login') {
        $identity = trim((string)($_POST['identity'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $r = admin_login($identity, $password);
        if ($r['ok']) {
            redirect(SITE_URL . '/admin/dashboard.php');
        }
        $err = $r['message'];
        $initialTab = 'password';
    } elseif ($act === 'otp_send') {
        $identity = trim((string)($_POST['otp_identity'] ?? ''));
        $r = admin_otp_login_send($identity);
        if ($r['ok']) {
            $postStep = 2;
            $maskedMobile = $r['masked_mobile'] ?? '';
            $debugCode = $r['debug_code'] ?? null;
            $initialTab = 'otp';
        } else {
            $err = $r['message'];
            $initialTab = 'otp';
        }
    } elseif ($act === 'otp_verify') {
        $code = trim(en_num((string)($_POST['otp_code'] ?? '')));
        $r = admin_otp_login_verify($code);
        if ($r['ok']) {
            redirect(SITE_URL . '/admin/dashboard.php');
        }
        $err = $r['message'];
        $postStep = 2;
        $initialTab = 'otp';
    } elseif ($act === 'reset_send') {
        $identity = trim((string)($_POST['reset_identity'] ?? ''));
        $r = admin_password_reset_send($identity);
        if ($r['ok']) {
            $postStep = 2;
            $maskedMobile = $r['masked_mobile'] ?? '';
            $debugCode = $r['debug_code'] ?? null;
            $initialTab = 'reset';
        } else {
            $err = $r['message'];
            $initialTab = 'reset';
        }
    } elseif ($act === 'reset_verify') {
        $code = trim(en_num((string)($_POST['reset_code'] ?? '')));
        $p1   = (string)($_POST['reset_password'] ?? '');
        $p2   = (string)($_POST['reset_password_confirm'] ?? '');
        $ident = trim((string)($_POST['reset_identity'] ?? ''));
        $r = admin_password_reset_verify($code, $p1, $p2, $ident ?: null);
        if ($r['ok']) {
            $okMsg = $r['message'];
            $initialTab = 'password';
        } else {
            $err = $r['message'];
            $postStep = 2;
            $initialTab = 'reset';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>ورود به پیشخوان مدیریت | <?= e($siteTitle) ?></title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/feria.css?v=<?= FR_VERSION ?>">
<style>
/* ==========================================================================
   استایل اختصاصی و متمرکز درگاه احراز هویت مدیریت فریا روان
   ========================================================================== */
.adm-auth-wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
  background:
    radial-gradient(1100px 500px at 100% 0%, rgba(106, 79, 216, 0.2), transparent 55%),
    radial-gradient(900px 500px at 0% 100%, rgba(30, 58, 110, 0.16), transparent 50%),
    #f4f6fa;
}
.adm-card {
  width: 100%;
  max-width: 440px;
  background: #ffffff;
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 24px;
  box-shadow: 0 20px 50px rgba(30, 58, 110, 0.08), 0 4px 16px rgba(0, 0, 0, 0.03);
  padding: 32px 28px;
  position: relative;
  overflow: hidden;
}
.adm-card::before {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 5px;
  background: linear-gradient(90deg, #1e3a6e, #6a4fd8, #8f6cf5);
}
.adm-head {
  text-align: center;
  margin-bottom: 22px;
}
.adm-badge-ico {
  width: 58px;
  height: 58px;
  border-radius: 18px;
  margin: 0 auto 12px;
  font-size: 27px;
  background: linear-gradient(135deg, #eaf0f8, #e7e0fb);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 14px rgba(106, 79, 216, 0.12);
}
.adm-head h2 {
  font-size: 19px;
  font-weight: 800;
  color: #1e3a6e;
  margin: 0 0 6px;
}
.adm-head p {
  color: #64748b;
  font-size: 13px;
  margin: 0;
}

/* تب‌سوئیچر اصلی روش‌های ورود */
.adm-tabs {
  display: flex;
  background: #f1f5f9;
  border-radius: 14px;
  padding: 4px;
  margin-bottom: 22px;
  gap: 4px;
}
.adm-tab-btn {
  flex: 1;
  border: none;
  background: transparent;
  padding: 9.5px 8px;
  border-radius: 10px;
  font-family: inherit;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  color: #64748b;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.adm-tab-btn.active {
  background: #ffffff;
  color: #1e3a6e;
  box-shadow: 0 2px 10px rgba(30, 58, 110, 0.09);
}

/* پنل‌های نمایشی */
.adm-view {
  display: none;
}
.adm-view.active {
  display: block;
  animation: admFadeIn 0.22s ease;
}
@keyframes admFadeIn {
  from { opacity: 0; transform: translateY(5px); }
  to   { opacity: 1; transform: none; }
}

/* ورودی کد پیامکی */
input.adm-code-input {
  letter-spacing: 10px;
  font-size: 21px;
  text-align: center;
  direction: ltr;
  font-weight: 800;
  background: #fbfbfe;
  border: 2px solid #e2e8f0;
}
input.adm-code-input:focus {
  border-color: #6a4fd8;
  background: #ffffff;
}

/* فیلد رمز عبور با قابلیت نمایش چشم */
.adm-pass-wrap {
  position: relative;
}
.adm-pass-wrap input {
  padding-left: 42px;
}
.adm-toggle-pass {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: 16px;
  color: #94a3b8;
  padding: 4px;
  line-height: 1;
}
.adm-toggle-pass:hover {
  color: #475569;
}

.adm-action-btn {
  width: 100%;
  justify-content: center;
  padding: 12px;
  font-size: 14.5px;
  font-weight: 700;
  margin-top: 6px;
  border-radius: 12px;
}
.adm-sub-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 14px;
  font-size: 12.5px;
}
.adm-sub-actions a {
  color: #6366f1;
  font-weight: 600;
  text-decoration: none;
  transition: 0.15s;
}
.adm-sub-actions a:hover {
  text-decoration: underline;
  color: #4f46e5;
}

.adm-back-site {
  text-align: center;
  margin-top: 22px;
  font-size: 13px;
  color: #64748b;
}
.adm-back-site a {
  color: #1e3a6e;
  font-weight: 700;
  text-decoration: none;
}
.adm-back-site a:hover {
  text-decoration: underline;
}

/* جعبه توست خطا و پیام */
.adm-msg {
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 13px;
  line-height: 1.8;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.adm-msg.err {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
}
.adm-msg.ok {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #166534;
}
.adm-msg.info {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  color: #1e40af;
}
.adm-msg.warn {
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #92400e;
}
</style>
</head>
<body class="adm-auth-wrap">

<div class="adm-card">
  <div class="adm-head">
    <div class="adm-badge-ico">🛡️</div>
    <h2>پیشخوان مدیریت فریا روان</h2>
    <p>ورود امن مدیران، سرپرستان و پشتیبانان کلینیک</p>
  </div>

  <!-- پیام‌های سراسری سرور -->
  <div id="admJsAlert" style="display:none"></div>
  <?php if ($err): ?>
    <div class="adm-msg err"><?= e($err) ?></div>
  <?php endif; ?>
  <?php if ($okMsg): ?>
    <div class="adm-msg ok"><?= e($okMsg) ?></div>
  <?php endif; ?>

  <!-- نوار تب‌ها (ورود با کلمه عبور / ورود با پیامک) -->
  <div class="adm-tabs" id="admMainTabs">
    <button type="button" class="adm-tab-btn <?= $initialTab === 'password' ? 'active' : '' ?>" id="tabBtnPassword" onclick="switchAdmTab('password')">
      🔐 کلمه عبور
    </button>
    <button type="button" class="adm-tab-btn <?= $initialTab === 'otp' ? 'active' : '' ?>" id="tabBtnOtp" onclick="switchAdmTab('otp')">
      📲 رمز یک‌بارمصرف (OTP)
    </button>
  </div>

  <!-- ====================================================================
       ۱. فرم ورود با کلمه عبور (Password Login)
       ==================================================================== -->
  <div class="adm-view <?= $initialTab === 'password' ? 'active' : '' ?>" id="viewPassword">
    <form method="post" id="formPassword" onsubmit="handlePasswordSubmit(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="password_login">

      <div class="fr-field">
        <label>نام کاربری، شماره موبایل یا ایمیل</label>
        <input class="fr-input" name="identity" id="passIdentity" required
               style="direction:ltr;text-align:right" autocomplete="username"
               placeholder="admin یا 0912xxxxxxx">
      </div>

      <div class="fr-field">
        <label>رمز عبور</label>
        <div class="adm-pass-wrap">
          <input class="fr-input" type="password" name="password" id="passInput" required
                 style="direction:ltr" autocomplete="current-password" placeholder="••••••••">
          <button type="button" class="adm-toggle-pass" onclick="togglePassVis('passInput')">👁️</button>
        </div>
      </div>

      <button type="submit" class="fr-btn adm-action-btn" id="btnPassSubmit">
        ورود به پیشخوان ←
      </button>

      <div class="adm-sub-actions">
        <a href="javascript:void(0)" onclick="switchAdmTab('reset')">🔑 فراموشی و بازیابی رمز عبور</a>
        <a href="javascript:void(0)" onclick="switchAdmTab('otp')">ورود سریع پیامکی</a>
      </div>
    </form>
  </div>

  <!-- ====================================================================
       ۲. فرم ورود با رمز یک‌بارمصرف پیامکی (OTP Login)
       ==================================================================== -->
  <div class="adm-view <?= $initialTab === 'otp' ? 'active' : '' ?>" id="viewOtp">
    <!-- گام ۱: وارد کردن نام کاربری یا موبایل مدیر -->
    <div id="otpStep1" style="display:<?= $postStep === 1 ? 'block' : 'none' ?>">
      <form method="post" id="formOtpStep1" onsubmit="handleOtpSend(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="otp_send">
        <div class="fr-field">
          <label>شماره موبایل یا نام کاربری حساب مدیر</label>
          <input class="fr-input" name="otp_identity" id="otpIdentity" required
                 style="direction:ltr;text-align:right" inputmode="text"
                 placeholder="مثال: 09123456789 یا admin">
          <small style="color:#64748b;font-size:12px;margin-top:5px;display:block">
            کد تأیید ورود به شماره موبایل ثبت‌شدهٔ مدیر ارسال خواهد شد.
          </small>
        </div>
        <button type="submit" class="fr-btn adm-action-btn" id="btnOtpSend">
          ارسال کد تأیید پیامکی 📩
        </button>
        <div class="adm-sub-actions" style="justify-content:center">
          <a href="javascript:void(0)" onclick="switchAdmTab('password')">← بازگشت به ورود با کلمه عبور</a>
        </div>
      </form>
    </div>

    <!-- گام ۲: وارد کردن کد تأیید ۵ رقمی -->
    <div id="otpStep2" style="display:<?= $postStep === 2 && $initialTab === 'otp' ? 'block' : 'none' ?>">
      <form method="post" id="formOtpStep2" onsubmit="handleOtpVerify(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="otp_verify">
        <div class="adm-msg info" style="font-size:12.5px">
          کد تأیید به شمارهٔ <b id="otpMaskedDisplay" style="direction:ltr;margin:0 4px"><?= e($maskedMobile) ?></b> پیامک شد.
        </div>
        <div id="otpDebugBox" class="adm-msg warn" style="display:<?= $debugCode ? 'block' : 'none' ?>;font-size:13px;text-align:center">
          کد ورود تستی: <b><?= e($debugCode) ?></b>
        </div>
        <div class="fr-field">
          <label style="text-align:center;display:block">کد تأیید <?= (int)$otpLen ?> رقمی</label>
          <input class="fr-input adm-code-input" name="otp_code" id="otpCodeInput" required
                 maxlength="<?= (int)$otpLen ?>" inputmode="numeric" placeholder="• • • • •" autocomplete="one-time-code">
        </div>
        <button type="submit" class="fr-btn adm-action-btn" id="btnOtpVerify">
          تأیید و ورود به پیشخوان ←
        </button>
        <div class="adm-sub-actions">
          <button type="button" class="fr-btn ghost sm" id="btnOtpResend" onclick="handleOtpResend()" style="font-size:12px" disabled>
            ارسال مجدد (<span id="otpTimerText">۱۲۰</span>)
          </button>
          <a href="javascript:void(0)" onclick="setOtpStep(1)">✏️ ویرایش شماره / بازگشت</a>
        </div>
      </form>
    </div>
  </div>

  <!-- ====================================================================
       ۳. فرم بازیابی و تغییر رمز عبور مدیر (Password Reset)
       ==================================================================== -->
  <div class="adm-view <?= $initialTab === 'reset' ? 'active' : '' ?>" id="viewReset">
    <!-- گام ۱: وارد کردن هویت مدیر جهت دریافت کد بازیابی -->
    <div id="resetStep1" style="display:<?= $postStep === 1 ? 'block' : 'none' ?>">
      <form method="post" id="formResetStep1" onsubmit="handleResetSend(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="reset_send">
        <div class="adm-msg info" style="font-size:12.5px">
          جهت بازیابی رمز عبور، شناسه یا شماره موبایل ثبت‌شدهٔ خود در پیشخوان مدیریت را وارد کنید.
        </div>
        <div class="fr-field">
          <label>نام کاربری، شماره موبایل یا ایمیل مدیر</label>
          <input class="fr-input" name="reset_identity" id="resetIdentity" required
                 style="direction:ltr;text-align:right" placeholder="admin یا 0912xxxxxxx">
        </div>
        <button type="submit" class="fr-btn adm-action-btn" id="btnResetSend">
          ارسال کد بازیابی پیامکی 📩
        </button>
        <div class="adm-sub-actions" style="justify-content:center">
          <a href="javascript:void(0)" onclick="switchAdmTab('password')">← بازگشت به صفحهٔ ورود</a>
        </div>
      </form>
    </div>

    <!-- گام ۲: وارد کردن کد تأیید و رمز عبور جدید -->
    <div id="resetStep2" style="display:<?= $postStep === 2 && $initialTab === 'reset' ? 'block' : 'none' ?>">
      <form method="post" id="formResetStep2" onsubmit="handleResetVerify(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="reset_verify">
        <input type="hidden" name="reset_identity" id="resetHiddenIdentity">
        <div class="adm-msg info" style="font-size:12.5px">
          کد بازیابی به شمارهٔ <b id="resetMaskedDisplay" style="direction:ltr;margin:0 4px"><?= e($maskedMobile) ?></b> ارسال گردید.
        </div>
        <div id="resetDebugBox" class="adm-msg warn" style="display:<?= $debugCode ? 'block' : 'none' ?>;font-size:13px;text-align:center">
          کد بازیابی تستی: <b><?= e($debugCode) ?></b>
        </div>
        <div class="fr-field">
          <label style="text-align:center;display:block">کد تأیید پیامک‌شده</label>
          <input class="fr-input adm-code-input" name="reset_code" id="resetCodeInput" required
                 maxlength="<?= (int)$otpLen ?>" inputmode="numeric" placeholder="• • • • •" autocomplete="one-time-code">
        </div>
        <div class="fr-field">
          <label>رمز عبور جدید (حداقل ۸ نویسه)</label>
          <div class="adm-pass-wrap">
            <input class="fr-input" type="password" name="reset_password" id="resetPass1" required
                   style="direction:ltr" minlength="8" placeholder="رمز جدید با حروف و ارقام">
            <button type="button" class="adm-toggle-pass" onclick="togglePassVis('resetPass1')">👁️</button>
          </div>
        </div>
        <div class="fr-field">
          <label>تکرار رمز عبور جدید</label>
          <div class="adm-pass-wrap">
            <input class="fr-input" type="password" name="reset_password_confirm" id="resetPass2" required
                   style="direction:ltr" minlength="8" placeholder="تکرار رمز جدید">
            <button type="button" class="adm-toggle-pass" onclick="togglePassVis('resetPass2')">👁️</button>
          </div>
        </div>
        <button type="submit" class="fr-btn adm-action-btn" id="btnResetVerify">
          ثبت رمز عبور جدید و ورود ←
        </button>
        <div class="adm-sub-actions">
          <button type="button" class="fr-btn ghost sm" id="btnResetResend" onclick="handleResetResend()" style="font-size:12px" disabled>
            ارسال مجدد (<span id="resetTimerText">۱۲۰</span>)
          </button>
          <a href="javascript:void(0)" onclick="setResetStep(1)">✏️ ویرایش شناسه / بازگشت</a>
        </div>
      </form>
    </div>
  </div>

  <div class="adm-back-site">
    <a href="<?= SITE_URL ?>/">← بازگشت به سایت <?= e($siteTitle) ?></a>
  </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const API_URL = <?= json_encode(SITE_URL . '/api/admin-auth.php') ?>;
const DASHBOARD_URL = <?= json_encode(SITE_URL . '/admin/dashboard.php') ?>;

function $(id) { return document.getElementById(id); }

function showAlert(msg, type = 'err') {
  const el = $('admJsAlert');
  if (!el) return;
  el.className = 'adm-msg ' + type;
  el.textContent = msg;
  el.style.display = 'flex';
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearAlert() {
  const el = $('admJsAlert');
  if (el) el.style.display = 'none';
}

function togglePassVis(id) {
  const el = $(id);
  if (!el) return;
  el.type = el.type === 'password' ? 'text' : 'password';
}

function switchAdmTab(tab) {
  clearAlert();
  $('viewPassword').classList.remove('active');
  $('viewOtp').classList.remove('active');
  $('viewReset').classList.remove('active');

  $('tabBtnPassword').classList.remove('active');
  $('tabBtnOtp').classList.remove('active');

  if (tab === 'password') {
    $('viewPassword').classList.add('active');
    $('tabBtnPassword').classList.add('active');
    $('admMainTabs').style.display = 'flex';
    setTimeout(() => $('passIdentity')?.focus(), 50);
  } else if (tab === 'otp') {
    $('viewOtp').classList.add('active');
    $('tabBtnOtp').classList.add('active');
    $('admMainTabs').style.display = 'flex';
    setTimeout(() => $('otpIdentity')?.focus(), 50);
  } else if (tab === 'reset') {
    $('viewReset').classList.add('active');
    $('admMainTabs').style.display = 'none'; // در حالت فراموشی رمز، تب‌ها پنهان می‌شوند
    setResetStep(1);
    setTimeout(() => $('resetIdentity')?.focus(), 50);
  }
}

function setOtpStep(step) {
  $('otpStep1').style.display = step === 1 ? 'block' : 'none';
  $('otpStep2').style.display = step === 2 ? 'block' : 'none';
  if (step === 1) {
    $('otpIdentity')?.focus();
  } else {
    $('otpCodeInput')?.focus();
  }
}

function setResetStep(step) {
  $('resetStep1').style.display = step === 1 ? 'block' : 'none';
  $('resetStep2').style.display = step === 2 ? 'block' : 'none';
  if (step === 1) {
    $('resetIdentity')?.focus();
  } else {
    $('resetCodeInput')?.focus();
  }
}

// تایمر ارسال مجدد OTP
let otpTimer = null;
function startOtpTimer(seconds) {
  clearInterval(otpTimer);
  const btn = $('btnOtpResend');
  const txt = $('otpTimerText');
  if (!btn || !txt) return;
  btn.disabled = true;
  let remaining = seconds;
  txt.textContent = remaining;
  otpTimer = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
      clearInterval(otpTimer);
      btn.disabled = false;
      txt.textContent = 'امکان ارسال';
    } else {
      txt.textContent = remaining;
    }
  }, 1000);
}

// تایمر ارسال مجدد بازیابی
let resetTimer = null;
function startResetTimer(seconds) {
  clearInterval(resetTimer);
  const btn = $('btnResetResend');
  const txt = $('resetTimerText');
  if (!btn || !txt) return;
  btn.disabled = true;
  let remaining = seconds;
  txt.textContent = remaining;
  resetTimer = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
      clearInterval(resetTimer);
      btn.disabled = false;
      txt.textContent = 'امکان ارسال';
    } else {
      txt.textContent = remaining;
    }
  }, 1000);
}

async function postApi(action, data = {}) {
  data.action = action;
  data._csrf = CSRF_TOKEN;
  const res = await fetch(API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify(data)
  });
  return await res.json();
}

// ۱. ارسال فرم رمز عبور
async function handlePasswordSubmit(e) {
  e.preventDefault();
  clearAlert();
  const identity = $('passIdentity').value.trim();
  const password = $('passInput').value;
  if (!identity || !password) {
    showAlert('نام کاربری و رمز عبور را وارد نمایید.', 'err');
    return;
  }
  const btn = $('btnPassSubmit');
  btn.disabled = true;
  btn.textContent = 'در حال احراز هویت...';
  try {
    const res = await postApi('login', { identity, password });
    if (res.ok) {
      showAlert('ورود موفقیت‌آمیز بود؛ در حال انتقال...', 'ok');
      window.location.href = res.redirect || DASHBOARD_URL;
    } else {
      showAlert(res.message || 'نام کاربری یا رمز اشتباه است.', 'err');
      btn.disabled = false;
      btn.textContent = 'ورود به پیشخوان ←';
      $('passInput').focus();
    }
  } catch (err) {
    showAlert('خطای برقراری ارتباط با سرور. لطفاً مجدداً تلاش کنید.', 'err');
    btn.disabled = false;
    btn.textContent = 'ورود به پیشخوان ←';
  }
}

// ۲. درخواست کد OTP برای ورود
let currentOtpIdentity = '';
async function handleOtpSend(e) {
  e.preventDefault();
  clearAlert();
  const identity = $('otpIdentity').value.trim();
  if (!identity) {
    showAlert('شماره موبایل یا نام کاربری مدیر را وارد کنید.', 'err');
    return;
  }
  currentOtpIdentity = identity;
  const btn = $('btnOtpSend');
  btn.disabled = true;
  btn.textContent = 'در حال ارسال کد...';
  try {
    const res = await postApi('otp_send', { identity });
    btn.disabled = false;
    btn.textContent = 'ارسال کد تأیید پیامکی 📩';
    if (res.ok) {
      $('otpMaskedDisplay').textContent = res.masked_mobile || res.mobile;
      if (res.debug_code) {
        const dbg = $('otpDebugBox');
        dbg.style.display = 'block';
        dbg.innerHTML = 'کد ورود تستی (حالت شبیه‌ساز): <b>' + res.debug_code + '</b>';
        $('otpCodeInput').value = res.debug_code;
      } else {
        $('otpDebugBox').style.display = 'none';
        $('otpCodeInput').value = '';
      }
      setOtpStep(2);
      startOtpTimer(res.ttl || 120);
      showAlert(res.message || 'کد تأیید به شماره شما ارسال شد.', 'info');
    } else {
      showAlert(res.message || 'خطا در ارسال کد تأیید.', 'err');
    }
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'ارسال کد تأیید پیامکی 📩';
    showAlert('خطای برقراری ارتباط با سرور.', 'err');
  }
}

async function handleOtpResend() {
  if (!currentOtpIdentity) return;
  await handleOtpSend({ preventDefault: () => {} });
}

// ۳. بررسی کد OTP و ورود
async function handleOtpVerify(e) {
  e.preventDefault();
  clearAlert();
  const code = $('otpCodeInput').value.trim();
  if (!code) {
    showAlert('کد تأیید را وارد نمایید.', 'err');
    return;
  }
  const btn = $('btnOtpVerify');
  btn.disabled = true;
  btn.textContent = 'در حال تأیید کد...';
  try {
    const res = await postApi('otp_verify', { code });
    if (res.ok) {
      showAlert('ورود موفقیت‌آمیز بود؛ در حال انتقال...', 'ok');
      window.location.href = res.redirect || DASHBOARD_URL;
    } else {
      showAlert(res.message || 'کد واردشده نامعتبر یا منقضی است.', 'err');
      btn.disabled = false;
      btn.textContent = 'تأیید و ورود به پیشخوان ←';
      $('otpCodeInput').focus();
    }
  } catch (err) {
    showAlert('خطای برقراری ارتباط با سرور.', 'err');
    btn.disabled = false;
    btn.textContent = 'تأیید و ورود به پیشخوان ←';
  }
}

// ۴. درخواست کد بازیابی رمز عبور
let currentResetIdentity = '';
async function handleResetSend(e) {
  e.preventDefault();
  clearAlert();
  const identity = $('resetIdentity').value.trim();
  if (!identity) {
    showAlert('نام کاربری، شماره موبایل یا ایمیل مدیر را وارد فرمایید.', 'err');
    return;
  }
  currentResetIdentity = identity;
  const btn = $('btnResetSend');
  btn.disabled = true;
  btn.textContent = 'در حال ارسال کد بازیابی...';
  try {
    const res = await postApi('reset_send', { identity });
    btn.disabled = false;
    btn.textContent = 'ارسال کد بازیابی پیامکی 📩';
    if (res.ok) {
      $('resetMaskedDisplay').textContent = res.masked_mobile || res.mobile;
      if ($('resetHiddenIdentity')) $('resetHiddenIdentity').value = identity;
      if (res.debug_code) {
        const dbg = $('resetDebugBox');
        dbg.style.display = 'block';
        dbg.innerHTML = 'کد بازیابی تستی: <b>' + res.debug_code + '</b>';
        $('resetCodeInput').value = res.debug_code;
      } else {
        $('resetDebugBox').style.display = 'none';
        $('resetCodeInput').value = '';
      }
      setResetStep(2);
      startResetTimer(res.ttl || 120);
      showAlert(res.message || 'کد تأیید بازیابی به شماره شما پیامک شد.', 'info');
    } else {
      showAlert(res.message || 'خطا در درخواست بازیابی رمز.', 'err');
    }
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'ارسال کد بازیابی پیامکی 📩';
    showAlert('خطای برقراری ارتباط با سرور.', 'err');
  }
}

async function handleResetResend() {
  if (!currentResetIdentity) return;
  await handleResetSend({ preventDefault: () => {} });
}

// ۵. تأیید کد و تغییر رمز عبور جدید
async function handleResetVerify(e) {
  e.preventDefault();
  clearAlert();
  const code = $('resetCodeInput').value.trim();
  const newPass = $('resetPass1').value;
  const newPassConfirm = $('resetPass2').value;
  if (!code) {
    showAlert('کد تأیید پیامک‌شده را وارد نمایید.', 'err');
    return;
  }
  if (newPass.length < 8) {
    showAlert('رمز عبور جدید باید حداقل ۸ کاراکتر باشد.', 'err');
    $('resetPass1').focus();
    return;
  }
  if (newPass !== newPassConfirm) {
    showAlert('رمز عبور جدید با تکرار آن یکسان نیست.', 'err');
    $('resetPass2').focus();
    return;
  }

  const btn = $('btnResetVerify');
  btn.disabled = true;
  btn.textContent = 'در حال ثبت رمز جدید...';
  try {
    const res = await postApi('reset_verify', {
      code,
      new_password: newPass,
      new_password_confirm: newPassConfirm,
      identity: currentResetIdentity
    });
    btn.disabled = false;
    btn.textContent = 'ثبت رمز عبور جدید و ورود ←';
    if (res.ok) {
      showAlert(res.message || 'رمز عبور با موفقیت تغییر کرد.', 'ok');
      setTimeout(() => {
        switchAdmTab('password');
        $('passIdentity').value = currentResetIdentity;
        $('passInput').value = '';
        $('passInput').focus();
      }, 1500);
    } else {
      showAlert(res.message || 'خطا در تغییر رمز عبور.', 'err');
    }
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'ثبت رمز عبور جدید و ورود ←';
    showAlert('خطای برقراری ارتباط با سرور.', 'err');
  }
}
</script>
</body>
</html>
