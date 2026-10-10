<?php
/**
 * =============================================================================
 *  صفحهٔ ثبت‌نام در رویداد (کارگاه/دوره/مشاوره) — نسخهٔ ۱٫۲
 *  ─ هویت کاربر از پروفایل خوانده می‌شود؛ هیچ اطلاعات شخصی دوباره پرسیده نمی‌شود
 *  ─ همراهان: افزودن/حذف پویا (نام، نام خانوادگی، موبایل اختیاری)
 *  ─ کد تخفیف + تخفیف ویژهٔ رویداد + پیش‌نمایش زندهٔ فاکتور (AJAX)
 *  ─ ظرفیت بر اساس صندلی (۱ نفر اصلی + هر همراه = ۱ صندلی)
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/commerce.php';
require_once __DIR__ . '/../includes/event-pricing.php';
require_once __DIR__ . '/../includes/deposit.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../core/Kavenegar.php';
require_once __DIR__ . '/../core/Zarinpal.php';
fr_bootstrap();

$u  = require_user();
$id = (int)($_GET['id'] ?? 0);
$ev = DB::one('SELECT * FROM ' . DB::t('events') . " WHERE id=? AND status='active'", [$id]);
/* نسخهٔ ۲٫۲: بازگشت به برگهٔ تفکیک‌شدهٔ درست بر اساس نوع رویداد */
$evHome = fr_panel_url(isset($ev['kind']) && $ev['kind'] === 'course' ? 'courses.php' : 'workshops.php');
if (!$ev) redirect(fr_panel_url('workshops.php'));
if (!(int)$ev['reg_open']) redirect($evHome);

$kindFa = ['workshop' => 'کارگاه', 'course' => 'دوره', 'consult' => 'نوبت مشاوره'];
$cmpEnabled = (int)$ev['companion_enabled'] === 1;
$cmpMax     = $ev['companion_max'] !== null ? (int)$ev['companion_max'] : 0;
$cmpUnit    = ev_companion_unit($ev);
$left       = ev_seats_left($ev);
$initQuote  = ev_quote($ev, 0, null);
$err = '';

/* ---------------------------------------------------------------------------
 | پردازش ارسال فرم (PRG)
 * -------------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // جمع‌آوری همراهان از آرایه‌های POST — نسخهٔ ۱٫۷: «نام و نام خانوادگی» یک فیلد + موبایل الزامی
    $comps = [];
    $nms = (array)($_POST['cmp_name'] ?? []); $mbs = (array)($_POST['cmp_mob'] ?? []);
    foreach ($nms as $i => $nm) {
        $comps[] = ['name'   => (string)$nm,
                    'mobile' => (string)($mbs[$i] ?? '')];
    }
    /* نسخهٔ ۲٫۱: میهمان‌های رایگانِ هدیهٔ پله — فیلدهای جدا با پرچم is_free */
    $fns = (array)($_POST['free_name'] ?? []); $fms = (array)($_POST['free_mob'] ?? []);
    foreach ($fns as $i => $nm) {
        if (trim((string)$nm) === '' && trim((string)($fms[$i] ?? '')) === '') continue; // ردیف کاملاً خالی نادیده
        $comps[] = ['name' => (string)$nm, 'mobile' => (string)($fms[$i] ?? ''), 'is_free' => 1];
    }
    $code = trim((string)($_POST['discount_code'] ?? ''));
    $useWallet = !empty($_POST['use_wallet']);          // پرداخت با موجودی کیف پول (۱٫۶)
    /* ۳٫۰: انتخاب کاربر — پرداخت کامل یا پیش‌پرداخت (بیعانه) */
    $payMode = (string)($_POST['pay_mode'] ?? 'full') === 'deposit' ? 'deposit' : 'full';

    $r = create_event_order($u, $ev, $comps, $code !== '' ? $code : null, $useWallet, $payMode);
    if ($r['error']) {
        $err = $r['message'] ?? $r['error'];
        $initQuote = $r['quote'] ?: $initQuote;
    } elseif ($r['payment_url']) {
        redirect($r['payment_url']);                                  // به درگاه
    } else {
        $depDone = (int)($r['deposit'] ?? 0) > 0;
        redirect($evHome . '?ok=' . urlencode($depDone
            ? 'پیش‌پرداخت شما در «' . $ev['title'] . '» ثبت شد و جایتان قطعی است 🎉 — مانده را با لینک پیامکی یا دکمهٔ «تصفیهٔ حساب» همین پنل پرداخت کنید.'
            : (!empty($r['wallet'])
                ? 'ثبت‌نام شما در «' . $ev['title'] . '» با کیف پول قطعی شد 🎉👛'
                : 'ثبت‌نام شما در «' . $ev['title'] . '» با موفقیت انجام شد 🎉')));
    }
}

render_header('ثبت‌نام: ' . $ev['title']);
panel_layout($u, $ev['kind'] === 'course' ? 'courses' : 'workshops');
?>
<div style="max-width:760px;margin:0 auto">

  <?php if ($err): ?><div class="fr-alert err"><?= e($err) ?></div><?php endif; ?>

  <!-- نوار خلاصهٔ رویداد — متن و توضیحات کامل فقط در صفحهٔ عمومی رویداد نمایش داده می‌شود (نسخهٔ ۱٫۶);
       این‌جا کاربر مستقیم سراغ انتخاب همراه و پرداخت می‌رود -->
  <div class="fr-card" style="padding:16px 18px">
    <div style="display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;font-size:13px;color:var(--muted)">
      <span class="fr-chip purple" style="flex:0 0 auto"><?= $kindFa[$ev['kind']] ?? 'رویداد' ?></span>
      <b style="color:var(--ink);font-size:14.5px"><?= e($ev['title']) ?></b>
      <?php if ($ev['starts_at']): ?><span>📅 <?= fa_num(jdate('l j F Y ساعت H:i', strtotime($ev['starts_at']))) ?></span><?php endif; ?>
      <?php if ($ev['teacher']): ?><span>👤 <?= e($ev['teacher']) ?></span><?php endif; ?>
      <span><?= $ev['capacity'] !== null ? '🪑 ' . fa_num((int)$left) . ' صندلی مانده' : '🪑 ظرفیت باز' ?></span>
    </div>
  </div>

  <form method="post" id="evForm">
    <?= csrf_field() ?>

    <!-- هویت کاربر از پروفایل (بدون پرسش مجدد) -->
    <div class="fr-card" style="background:#faf8ff;border:1.5px solid #eee7ff">
      <div style="display:flex;gap:12px;align-items:center">
        <span style="font-size:26px">🪪</span>
        <div>
          <b>ثبت‌نام با حساب شما</b><br>
          <small style="color:var(--muted)">
            <?= e(user_fullname($u)) ?> · <?= fa_num($u['mobile']) ?>
            — اطلاعات شما از پروفایل خوانده می‌شود و نیازی به ورود مجدد نیست.
            <a href="profile.php" style="color:var(--c-primary)">ویرایش پروفایل</a>
          </small>
        </div>
      </div>
    </div>

    <?php if ($cmpEnabled): ?>
    <!-- ── همراهان ─────────────────────────────────────────── -->
    <div class="fr-card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
        <div>
          <h3 style="margin-bottom:4px">👥 همراهان شما</h3>
          <small style="color:var(--muted)">
            اعضای خانواده یا همکارانتان را همین‌جا ثبت کنید؛ «نام و نام خانوادگی» و «موبایل» هر همراه الزامی است.
            برای هر همراه به‌صورت خودکار حساب کاربری مخصوص خودش ساخته می‌شود و پیامک تأیید + لینک پنل دریافت می‌کند
            (بتواند ثبت‌نامش را ببیند یا لغو کند).
            <?= $cmpMax > 0 ? 'سقف ' . fa_num($cmpMax) . ' همراه.' : 'بدون سقف تعداد.' ?>
          </small>
        </div>
        <div style="text-align:center;background:#f4f0ff;border-radius:12px;padding:8px 14px">
          <small style="color:var(--muted)">هزینهٔ هر همراه</small><br><b style="color:var(--c-primary)"><?= toman($cmpUnit) ?></b>
        </div>
      </div>

      <div id="cmpRows" style="margin-top:14px"></div>
      <button type="button" class="fr-btn ghost sm" id="cmpAdd" style="margin-top:4px">➕ افزودن همراه</button>

      <!-- نسخهٔ ۲٫۱: میهمان‌های رایگانِ هدیهٔ پله — به‌محض رسیدن همراهان به آستانه نمایش داده می‌شود -->
      <div id="freeBox" style="display:none;margin-top:16px;border:2px dashed #d8c9ff;border-radius:16px;padding:14px;background:linear-gradient(180deg,#fdfbff,#f8f4ff)">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
          <span style="font-size:20px">🎁</span>
          <div>
            <b style="font-size:14px">میهمان‌های رایگان — هدیهٔ گروه</b><br>
            <small style="color:var(--muted)">به آستانهٔ تخفیف گروهی رسیدید! این میهمانان رایگان‌اند ولی صندلی مصرف می‌کنند؛ نام و موبایلشان الزامی است.</small>
          </div>
        </div>
        <div id="freeRows"></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── کد تخفیف ────────────────────────────────────────── -->
    <div class="fr-card">
      <h3 style="margin-bottom:10px">🎟 کد تخفیف</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <input class="fr-input" id="dcCode" name="discount_code" placeholder="کد تخفیف (اختیاری)"
               style="direction:ltr;text-align:right;flex:1;min-width:180px"
               value="<?= e($_POST['discount_code'] ?? '') ?>">
        <button type="button" class="fr-btn ghost" id="dcApply">اعمال کد</button>
      </div>
      <div id="dcMsg" style="font-size:12.5px;margin-top:8px"></div>
    </div>

    <!-- ── پیش‌فاکتور زنده ─────────────────────────────────── -->
    <div class="fr-card" style="border:1.5px solid var(--c-primary)">
      <h3 style="margin-bottom:12px">🧾 پیش‌فاکتور</h3>
      <div id="qLines" style="font-size:13.5px;line-height:2.2;color:#2c3a5c"></div>
      <div style="display:flex;justify-content:space-between;align-items:center;border-top:1.5px dashed #d4dcec;margin-top:10px;padding-top:12px">
        <b>مبلغ قابل پرداخت</b>
        <b id="qPayable" style="font-size:19px;color:var(--c-primary)"><?= toman($initQuote['payable']) ?></b>
      </div>

      <?php
      /* ۳٫۰ — انتخاب نحوهٔ پرداخت: کامل یا پیش‌پرداخت (بیعانه) */
      $depOk  = $initQuote['payable'] > 0 && fr_deposit_available($ev);
      $depAmt = $depOk ? (int)$ev['deposit_amount'] : 0;
      if ($depOk): ?>
      <div style="margin-top:14px;background:linear-gradient(140deg,#f6fbf9,#fdfbf5);border:1.5px solid #cdeeda;border-radius:14px;padding:12px 14px">
        <b style="font-size:13px;display:block;margin-bottom:8px">💳 نحوهٔ پرداخت را انتخاب کنید</b>
        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;font-size:13px;padding:8px 10px;border-radius:10px;background:#fff;border:1.5px solid #e5ecf5;margin-bottom:8px">
          <input type="radio" name="pay_mode" value="full" checked style="width:18px;height:18px;margin-top:2px">
          <span><b>پرداخت کامل</b> — کل مبلغ فاکتور همین حالا پرداخت می‌شود و کار تمام است. ✅</span>
        </label>
        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;font-size:13px;padding:8px 10px;border-radius:10px;background:#fff;border:1.5px solid #cdeeda">
          <input type="radio" name="pay_mode" value="deposit" style="width:18px;height:18px;margin-top:2px">
          <span><b>پیش‌پرداخت (بیعانهٔ <?= toman($depAmt) ?>)</b> — با پرداخت بیعانه، جای شما <b>همین حالا قطعی</b> می‌شود؛
          مانده را <b>حداکثر تا ۳ روز مانده به برگزاری</b> با لینک پیامکی یا از همین پنل تسویه می‌کنید. 🦢</span>
        </label>
      </div>
      <?php endif; ?>

      <?php $wBal = wallet_balance((int)$u['id']); if ($wBal > 0): ?>
      <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;cursor:pointer;background:#f7f3ff;border:1.5px dashed #d4dcec;border-radius:12px;padding:10px 12px">
        <input type="checkbox" name="use_wallet" value="1" style="width:18px;height:18px">
        <span>👛 پرداخت با کیف پول — موجودی شما: <b style="color:var(--c-primary)"><?= toman($wBal) ?></b>
          <small style="display:block;color:var(--muted);font-size:11.5px">موجودی کیف ابتدا کم می‌شود و اگر مانده‌ای ماند، همان مانده از درگاه پرداخت می‌شود.</small>
        </span>
      </label>
      <?php endif; ?>
      <button class="fr-btn" style="width:100%;justify-content:center;margin-top:14px;font-size:15px" id="submitBtn">
        <?= $initQuote['payable'] > 0 ? '💳 پرداخت و نهایی‌سازی ثبت‌نام' : '✅ نهایی‌سازی ثبت‌نام (رایگان)' ?>
      </button>
      <small style="display:block;text-align:center;color:var(--muted);margin-top:8px">
        ظرفیت فقط پس از پرداخت موفق قطعی می‌شود.
      </small>
    </div>
  </form>
</div>

<script>
/* قیمت‌گذاری زنده + همراهان پویا */
const EVENT_ID   = <?= (int)$ev['id'] ?>;
const CMP_ENABLED = <?= $cmpEnabled ? 'true' : 'false' ?>;
const CMP_MAX    = <?= $cmpMax ?>;
const CMP_UNIT   = <?= $cmpUnit ?>;
const CODE_API   = '../api/discount.php';
const DEP_AMT    = <?= (int)($depAmt ?? 0) ?>;   /* ۳٫۰: مبلغ بیعانه (۰ = غیرفعال) */
let   LAST_QUOTE = {payable: <?= (int)$initQuote['payable'] ?>};

/* ۳٫۰: متن دکمهٔ نهایی بر اساس نحوهٔ پرداخت (کامل / پیش‌پرداخت) */
function payModeSel(){
  const r = document.querySelector('input[name=pay_mode]:checked');
  return r ? r.value : 'full';
}
function updateSubmitBtn(){
  const btn = document.getElementById('submitBtn');
  if (!btn) return;
  const pay = (LAST_QUOTE && typeof LAST_QUOTE.payable !== 'undefined') ? Number(LAST_QUOTE.payable) : 0;
  if (pay <= 0) { btn.textContent = '✅ نهایی‌سازی ثبت‌نام (رایگان)'; return; }
  if (DEP_AMT > 0 && payModeSel() === 'deposit') {
    btn.textContent = '💳 پرداخت بیعانهٔ ' + tm(DEP_AMT) + ' تومان و قطعی‌سازی جای شما';
  } else {
    btn.textContent = '💳 پرداخت و نهایی‌سازی ثبت‌نام — ' + tm(pay) + ' تومان';
  }
}

function fa(n){ return String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }
function tm(n){ return fa(Number(n).toLocaleString('en-US').replace(/,/g,'٬')); }

function companionCount(){
  if (!CMP_ENABLED) return 0;
  return document.querySelectorAll('#cmpRows .cmp-row').length;
}

/* ردیف همراه — نسخهٔ ۱٫۷: «نام و نام خانوادگی» در یک فیلد + موبایل الزامی */
function addCmpRow(nm, mob){
  if (!CMP_ENABLED) return;
  if (CMP_MAX > 0 && companionCount() >= CMP_MAX) { toast('حداکثر ' + fa(CMP_MAX) + ' همراه مجاز است.','err'); return; }
  const rows = document.getElementById('cmpRows');
  const n = rows.children.length + 1;
  const div = document.createElement('div');
  div.className = 'cmp-row';   // استایل کاملاً واکنش‌گرا در feria.css
  div.innerHTML =
    '<input class="fr-input" name="cmp_name[]" placeholder="نام و نام خانوادگی همراه ' + fa(n) + ' (اجباری)" value="' + (nm||'') + '" required autocomplete="off">' +
    '<input class="fr-input" name="cmp_mob[]" placeholder="موبایل همراه (اجباری)" style="direction:ltr" inputmode="numeric" maxlength="11" value="' + (mob||'') + '" required>' +
    '<button type="button" class="fr-btn danger sm" title="حذف">✕</button>';
  div.querySelector('button').onclick = function(){ div.remove(); refreshQuote(); };
  rows.appendChild(div);
  refreshQuote();
}

/* نسخهٔ ۲٫۱ — تعداد میهمان‌های رایگانِ فعلی فرم */
function freeCount(){
  return document.querySelectorAll('#freeRows .cmp-row.free').length;
}

/* ساخت/حذف ردیف‌های میهمان رایگان تا سقفِ free_allowed (با حفظ ورودی کاربر) */
function syncFreeRows(allowed){
  const box = document.getElementById('freeBox');
  const rows = document.getElementById('freeRows');
  if (!box || !rows) return;
  allowed = Math.max(0, Number(allowed) || 0);
  while (rows.children.length < allowed) {
    const n = rows.children.length + 1;
    const div = document.createElement('div');
    div.className = 'cmp-row free';
    div.innerHTML =
      '<input class="fr-input" name="free_name[]" placeholder="نام و نام خانوادگی میهمان رایگان ' + fa(n) + ' (اجباری)" required autocomplete="off">' +
      '<input class="fr-input" name="free_mob[]" placeholder="موبایل میهمان (اجباری)" style="direction:ltr" inputmode="numeric" maxlength="11" required>' +
      '<span class="fr-btn ghost sm" style="pointer-events:none;border-color:#0c7a4d;color:#0c7a4d">رایگان 🎁</span>';
    div.querySelectorAll('input').forEach(function(inp){ inp.addEventListener('input', refreshQuote); });
    rows.appendChild(div);
  }
  while (rows.children.length > allowed) rows.removeChild(rows.lastElementChild);
  box.style.display = allowed > 0 ? '' : 'none';
}

/* نمایش خطوط پیش‌فاکتور */
function renderQuote(q){
  const box = document.getElementById('qLines');
  let h = '';
  (q.lines || []).forEach(function(l){
    const neg = l.amount < 0;
    const zero = l.amount === 0 && l.free;
    const was = (typeof l.was !== 'undefined' && l.was > l.amount)
      ? '<del style="color:#94a3b8;font-weight:400;margin-left:6px">' + tm(l.was) + '</del>' : '';
    h += '<div style="display:flex;justify-content:space-between;' + (zero ? 'color:#0c7a4d;font-weight:700' : (neg ? 'color:#0c7a4d' : '')) + '">'
       + '<span>' + l.label + '</span><span>' + was + (zero ? 'رایگان 🎁' : ((neg ? '− ' : '') + tm(Math.abs(l.amount)) + ' تومان')) + '</span></div>';
  });
  box.innerHTML = h;
  document.getElementById('qPayable').textContent = tm(q.payable) + ' تومان';
  LAST_QUOTE = q; updateSubmitBtn();
  /* آستانهٔ پله → نمایش/پنهان‌سازی فیلدهای میهمان رایگان (بدون حلقهٔ بی‌نهایت) */
  if (CMP_ENABLED && typeof q.free_allowed !== 'undefined') syncFreeRows(q.free_allowed);
}

/* درخواست قیمت از سرور (تنها منبع حقیقت) */
async function refreshQuote(){
  const code = document.getElementById('dcCode').value.trim();
  try {
    const r = await api(CODE_API, {event_id: EVENT_ID, cmp: companionCount(), free: freeCount(), code: code});
    if (r.ok && r.quote) renderQuote(r.quote);
    return r;
  } catch(e) { /* خطای شبکه — قیمت سمت سرور در ثبت نهایی دوباره محاسبه می‌شود */ }
}

/* اعمال کد تخفیف */
document.getElementById('dcApply').onclick = async function(){
  const msg = document.getElementById('dcMsg');
  msg.innerHTML = '⏳ درحال بررسی...';
  const r = await refreshQuote();
  if (r) {
    msg.style.color = r.code_ok ? '#0c7a4d' : '#b0262c';
    msg.innerHTML = r.message ? (r.code_ok ? '✅ ' : '⚠️ ') + r.message : '';
  }
};

document.getElementById('cmpAdd') && document.getElementById('cmpAdd').addEventListener('click',function(){ addCmpRow('',''); });

/* ۳٫۰: تغییر نحوهٔ پرداخت → به‌روزرسانی دکمهٔ نهایی */
document.querySelectorAll('input[name=pay_mode]').forEach(function(r){
  r.addEventListener('change', updateSubmitBtn);
});

/* بازسازی همراهان پس از خطای POST (حفظ ورودی کاربر) */
<?php
if (!empty($_POST['cmp_name']) && is_array($_POST['cmp_name'])) {
    foreach ($_POST['cmp_name'] as $i => $nm) {
        $nm2 = trim((string)$nm);
        if ($nm2 === '') continue;
        echo 'addCmpRow(' . json_encode($nm2, JSON_UNESCAPED_UNICODE) . ','
             . json_encode((string)($_POST['cmp_mob'][$i] ?? ''), JSON_UNESCAPED_UNICODE) . ");\n";
    }
}
?>
renderQuote(<?= json_encode($initQuote, JSON_UNESCAPED_UNICODE) ?>);
<?php
/* نسخهٔ ۲٫۱: بازسازی مقدار فیلدهای میهمان رایگان پس از خطای POST */
if (!empty($_POST['free_name']) && is_array($_POST['free_name'])) {
    $pairs = [];
    foreach ($_POST['free_name'] as $i => $nm) {
        $nm2 = trim((string)$nm); $mb2 = trim((string)($_POST['free_mob'][$i] ?? ''));
        if ($nm2 === '' && $mb2 === '') continue;
        $pairs[] = [$nm2, $mb2];
    }
    if ($pairs) {
        echo '(function(){var FR=' . json_encode($pairs, JSON_UNESCAPED_UNICODE) . ";\n"
           . 'var rows=document.getElementById("freeRows");if(!rows)return;' . "\n"
           . 'for(var i=0;i<rows.children.length&&i<FR.length;i++){var inp=rows.children[i].querySelectorAll("input");'
           . 'if(inp.length>=2){inp[0].value=FR[i][0];inp[1].value=FR[i][1];}}})();' . "\n";
    }
}
?>
</script>
<?php panel_layout_end(); render_footer(); ?>
