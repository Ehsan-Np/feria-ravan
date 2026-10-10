<?php
/**
 * ============================================================================
 *  صفحهٔ ورود/ثبت‌نام — نسخهٔ ۱٫۷ (بازنویسی کامل ظاهری و ساختاری)
 *  ─ دو روش ورود: کد یک‌بارمصرف پیامکی یا رمز عبور
 *  ─ ثبت‌نام تازه‌کارها فقط با یک فیلد: «نام و نام خانوادگی»
 *  ─ کاملاً واکنش‌گرا (موبایل‌محور)، کارت شیشه‌ای با هویت بنفش فریا
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

$siteTitle = (string)DB::setting('site_title', 'فریا روان');
$slogan    = (string)DB::setting('site_slogan', 'مسیر رشد فردی شما از اینجا آغاز می‌شود!');
$otpLen    = (int)DB::setting('otp_length', 5);

render_header('ورود / ثبت‌نام');
?>
<style>
/* ==========================================================================
   استایل اختصاصی صفحهٔ ورود — پیشوند au- (بدون تداخل با پنل/سایت)
   ========================================================================== */
.au-wrap{min-height:100vh;display:flex;align-items:stretch;background:
  radial-gradient(1200px 600px at 100% -10%,rgba(143,108,245,.22),transparent 60%),
  radial-gradient(900px 500px at -10% 110%,rgba(30,58,110,.16),transparent 55%),#f4f2ff}
.au-shell{margin:auto;width:100%;max-width:1020px;display:grid;grid-template-columns:1.05fr 1fr;
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
.au-side h1{font-size:24px;margin:22px 0 10px;position:relative}
.au-side p{font-size:13.5px;line-height:2.1;opacity:.94;margin:0;position:relative}
.au-side ul{list-style:none;padding:0;margin:24px 0 0;display:flex;flex-direction:column;gap:12px;position:relative}
.au-side li{display:flex;gap:10px;align-items:center;font-size:13px;font-weight:600}
.au-side li i{width:30px;height:30px;border-radius:10px;background:rgba(255,255,255,.2);display:inline-flex;
  align-items:center;justify-content:center;font-style:normal;flex:0 0 auto}
.au-box{background:var(--panel);border:1px solid var(--line);border-radius:26px;box-shadow:var(--shadow);
  padding:38px 34px;display:flex;flex-direction:column;justify-content:center}
.au-box .au-head{text-align:center;margin-bottom:22px}
.au-box .au-head .ico{width:62px;height:62px;border-radius:20px;margin:0 auto 12px;font-size:30px;
  background:linear-gradient(135deg,#eaf0f8,#e2d8fa);display:flex;align-items:center;justify-content:center}
.au-box .au-head h2{font-size:19px;color:var(--c-primary);margin:0 0 6px}
.au-box .au-head p{color:var(--muted);font-size:13px;margin:0}
.au-tabs{display:flex;background:#f2efff;border-radius:14px;padding:5px;margin-bottom:22px;gap:5px}
.au-tabs button{flex:1;border:none;background:transparent;padding:11px 8px;border-radius:11px;font-family:inherit;
  font-size:13.5px;font-weight:600;cursor:pointer;color:#6b658e;transition:.2s}
.au-tabs button.on{background:#fff;color:var(--c-primary);box-shadow:0 3px 12px rgba(30,58,110,.18)}
.au-pane{display:none}.au-pane.on{display:block;animation:auIn .25s ease}
@keyframes auIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.au-btn{width:100%;justify-content:center;margin-top:4px;padding:13px;font-size:14.5px}
.au-link{display:block;text-align:center;margin-top:18px;font-size:13px;color:var(--c-primary)}
.au-steps{display:flex;justify-content:center;gap:6px;margin-bottom:18px}
.au-steps i{width:26px;height:5px;border-radius:6px;background:#e4defa;transition:.25s}
.au-steps i.on{background:var(--c-primary)}
.au-hint{font-size:11.5px;color:var(--muted);text-align:center;line-height:1.9;margin-top:12px}
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

    <!-- کارت فرم -->
    <section class="au-box" id="loginBox" data-back="<?= e($back) ?>">
      <div class="au-head">
        <div class="ico">🦢</div>
        <h2><?= $siteTitle ? ($siteTitle) : '' ?> — خوش آمدید</h2>
        <p>با موبایل‌تان وارد شوید یا حساب تازه بسازید؛ فقط چند ثانیه طول می‌کشد.</p>
      </div>

      <div class="au-tabs" role="tablist">
        <button id="tabOtp" class="on" type="button">کد یک‌بارمصرف 📩</button>
        <button id="tabPwd" type="button">رمز عبور 🔑</button>
      </div>

      <!-- روش OTP -->
      <div class="au-pane on" id="paneOtp">
        <div class="au-steps" id="otpSteps"><i class="on"></i><i></i><i></i></div>

        <!-- گام ۱: موبایل -->
        <div id="stepMobile">
          <div class="fr-field">
            <label>شماره موبایل</label>
            <input class="fr-input" id="otpMobile" inputmode="numeric" maxlength="11"
                   placeholder="مثلاً ۰۹۱۲۳۴۵۶۷۸۹" autocomplete="tel" style="direction:ltr;text-align:center">
          </div>
          <button class="fr-btn au-btn" id="btnSendOtp">ارسال کد تأیید</button>
          <p class="au-hint">کد <?= fa_num($otpLen) ?> رقمی برایتان پیامک می‌شود؛ نیازی به رمز عبور نیست.</p>
        </div>

        <!-- گام ۲: کد -->
        <div id="stepCode" style="display:none">
          <p class="fr-alert info" id="otpInfoAlert" style="font-size:13px">کد تأیید به <b id="otpSentTo" style="direction:ltr;display:inline-block"></b> پیامک شد.</p>
          <div id="otpDebugBox" class="fr-alert warn" style="display:none;font-size:13.5px;line-height:2;text-align:center;margin-bottom:14px"></div>
          <div class="fr-field">
            <label>کد <?= fa_num($otpLen) ?> رقمی</label>
            <input class="fr-input au-code" id="otpCode" inputmode="numeric" maxlength="<?= (int)$otpLen ?>"
                   placeholder="– – – – –" autocomplete="one-time-code">
          </div>
          <button class="fr-btn au-btn" id="btnVerifyOtp">تأیید و ورود ✨</button>
          <button class="fr-btn ghost sm" style="width:100%;justify-content:center;margin-top:10px" id="btnResend" disabled>
            ارسال مجدد (<span id="resendTimer">۱۲۰</span>)
          </button>
        </div>

        <!-- گام ۳: نام (فقط کاربران تازه) -->
        <div id="stepName" style="display:none">
          <p class="fr-alert ok" style="font-size:13px">موبایل شما تأیید شد ✅ برای ساخت حساب، فقط نام‌تان را بنویسید.</p>
          <div class="fr-field">
            <label>نام و نام خانوادگی <span class="req">*</span></label>
            <input class="fr-input" id="regFull" maxlength="200" placeholder="مثل: سارا محمدی" autocomplete="name" style="text-align:center">
            <small style="display:block;color:var(--muted);font-size:11.5px;margin-top:6px">نام و نام خانوادگی را با هم و با فاصله بنویسید.</small>
          </div>
          <button class="fr-btn au-btn" id="btnRegister">ساخت حساب و ورود 🎉</button>
          <p class="au-hint">بقیهٔ اطلاعات (تصویر، کد ملی و…) را هر وقت خواستید داخل پنل تکمیل می‌کنید.</p>
        </div>
      </div>

      <!-- روش رمز -->
      <div class="au-pane" id="panePwd">
        <div class="fr-field">
          <label>نام کاربری، موبایل یا کد ملی</label>
          <input class="fr-input" id="pwdIdentity" inputmode="text" autocomplete="username" style="direction:ltr;text-align:center"
                 placeholder="username / 09xxxxxxxxx / کد ملی">
        </div>
        <div class="fr-field">
          <label>رمز عبور</label>
          <input class="fr-input" id="pwdPassword" type="password" autocomplete="current-password" style="direction:ltr">
        </div>
        <button class="fr-btn au-btn" id="btnPwdLogin">ورود</button>
        <p class="au-hint">نام کاربری و رمز را هم می‌توانید از پنل کاربری (برگهٔ پروفایل) تنظیم کنید. رمزتان را ندارید؟ از تب «کد یک‌بارمصرف» وارد شوید.</p>
      </div>

      <a class="au-link" href="<?= SITE_URL ?>/">→ بازگشت به صفحهٔ اصلی</a>
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
        با ورود به حسابتان، تست‌های روانشناسی، کارگاه‌ها، دوره‌ها و نوبت‌های مشاوره
        همه در یک پنلِ تمیز در اختیارتان است؛ تحلیل‌ها همیشه بمانند و هر جا بخواهید دوباره ببینید.
      </p>
      <ul>
        <li><i>📩</i> ورود سریع با پیامک — بدون دردسر رمز</li>
        <li><i>🔐</i> اطلاعات‌تان محرمانه و امن نزد ما می‌ماند</li>
        <li><i>🪪</i> اطلاعات هویتی فقط یک‌بار پرسیده می‌شود</li>
        <li><i>💌</i> نتیجهٔ هر رزرو، پیامک برایتان می‌آید</li>
      </ul>
    </aside>
  </main>
</div>

<script>
const $=id=>document.getElementById(id);
/* مقصد پس از ورود: مسیر داخلی‌ای که سرور تأییدش کرده (رزومهٔ رزرو/ثبت‌نام) */
const FR_BACK=($('loginBox')||{getAttribute:()=>''}).getAttribute('data-back')||'';

/* ---------- تب‌ها ---------- */
function selectTab(t){
  $('tabOtp').classList.toggle('on',t==='otp');
  $('tabPwd').classList.toggle('on',t==='pwd');
  $('paneOtp').classList.toggle('on',t==='otp');
  $('panePwd').classList.toggle('on',t==='pwd');
}
$('tabOtp').onclick=()=>selectTab('otp');
$('tabPwd').onclick=()=>selectTab('pwd');

/* ---------- گام‌نمای OTP ---------- */
function setOtpStep(n){
  const bars=$('otpSteps').children;
  for(let i=0;i<bars.length;i++) bars[i].classList.toggle('on', i<n);
  $('stepMobile').style.display=n===1?'block':'none';
  $('stepCode').style.display=n===2?'block':'none';
  $('stepName').style.display=n===3?'block':'none';
  (n===1?$('otpMobile'):n===2?$('otpCode'):$('regFull')).focus();
}

/* ---------- شمارش معکوس ارسال مجدد ---------- */
let timer=null;
function startTimer(sec){
  clearInterval(timer); $('btnResend').disabled=true; $('resendTimer').textContent=faNum(sec);
  let left=sec;
  timer=setInterval(()=>{left--; $('resendTimer').textContent=faNum(Math.max(left,0));
    if(left<=0){clearInterval(timer);$('btnResend').disabled=false;$('btnResend').innerHTML='ارسال مجدد کد';}},1000);
}

/* ---------- ارسال OTP ---------- */
async function sendOtp(){
  const mobile=$('otpMobile').value.trim();
  if(!mobile){ toast('لطفاً شماره موبایل را وارد کنید.','err'); $('otpMobile').focus(); return; }
  if($('stepName').style.display==='block'){ setOtpStep(1); }
  const btn=$('btnSendOtp'); btn.disabled=true;
  try{
    const r=await api('api/auth.php',{action:'send_otp',mobile});
    if(!r.ok){toast(r.message,'err');}
    else{
      toast(r.message);
      const dbgBox = $('otpDebugBox');
      if(r.debug_code){
        if (dbgBox) {
          dbgBox.style.display = 'block';
          dbgBox.innerHTML = 'کد تأیید ورود شما: <b style="font-size:18px;letter-spacing:4px;color:#5b21b6;direction:ltr;display:inline-block">' + r.debug_code + '</b>';
        }
        $('otpCode').value = r.debug_code;
      } else {
        if (dbgBox) dbgBox.style.display = 'none';
        $('otpCode').value = '';
      }
      $('otpSentTo').textContent=mobile;
      setOtpStep(2);
      startTimer(r.ttl||120);
      $('otpCode').focus();
    }
  }finally{btn.disabled=false;}
}
$('btnSendOtp').onclick=sendOtp;
$('btnResend').onclick=sendOtp;
$('otpMobile').addEventListener('keydown',e=>{if(e.key==='Enter')sendOtp();});

/* ---------- تأیید OTP ---------- */
$('btnVerifyOtp').onclick=async()=>{
  const btn=$('btnVerifyOtp'); btn.disabled=true;
  try{
    const r=await api('api/auth.php',{action:'verify_otp',mobile:$('otpMobile').value.trim(),code:$('otpCode').value.trim(),back:FR_BACK});
    if(!r.ok){ toast(r.message,'err'); }
    else if(r.needs_register){
      toast(r.message);
      setOtpStep(3);
    }
    else{ toast(r.message||'خوش آمدید 🦢'); location.href=r.redirect; }
  }finally{btn.disabled=false;}
};
$('otpCode').addEventListener('keydown',e=>{if(e.key==='Enter')$('btnVerifyOtp').click();});
$('otpCode').addEventListener('input',e=>{
  const val = e.target.value.trim();
  if (val.length === <?= (int)$otpLen ?>) {
    $('btnVerifyOtp').click();
  }
});

/* ---------- تکمیل ثبت‌نام سبک — «نام و نام خانوادگی» یک فیلد ---------- */
$('btnRegister').onclick=async()=>{
  const btn=$('btnRegister'); btn.disabled=true;
  try{
    const r=await api('api/auth.php',{action:'register_name',full_name:$('regFull').value.trim(),back:FR_BACK});
    if(!r.ok){ toast(r.message,'err');
      if(/مهلت/.test(r.message||'')){ setOtpStep(1); }
    }
    else{ toast(r.message); location.href=r.redirect; }
  }finally{btn.disabled=false;}
};
$('regFull').addEventListener('keydown',e=>{if(e.key==='Enter')$('btnRegister').click();});

/* ---------- ورود با رمز ---------- */
$('btnPwdLogin').onclick=async()=>{
  const btn=$('btnPwdLogin'); btn.disabled=true;
  try{
    const r=await api('api/auth.php',{action:'password_login',identity:$('pwdIdentity').value.trim(),password:$('pwdPassword').value,back:FR_BACK});
    if(!r.ok) toast(r.message,'err'); else location.href=r.redirect;
  }finally{btn.disabled=false;}
};
$('pwdPassword').addEventListener('keydown',e=>{if(e.key==='Enter')$('btnPwdLogin').click();});
</script>
<?php render_footer(); ?>
