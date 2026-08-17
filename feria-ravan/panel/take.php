<?php
/**
 * ============================================================================
 *  اسکرین انجام تست روانشناسی — نسخهٔ ۱٫۶
 *  ─ پاسخ‌گویی هوشمند: در پایان، سؤال‌های بی‌پاسخ به‌ترتیب به کاربر نشان داده
 *    می‌شود و با هر پاسخ، خودکار به سؤال بی‌پاسخ بعدی می‌رویم
 *  ─ ذخیرهٔ خودکار هر پاسخ + ادامه از محل قطع + دکمهٔ «پایان» همیشه در دسترس
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
fr_bootstrap();

$u = require_user();
$attemptId = (int)($_GET['attempt'] ?? 0);
$at = DB::one('SELECT a.*, t.title, t.engine, t.question_count, t.instructions, o.status AS os
               FROM ' . DB::t('attempts') . ' a
               JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
               LEFT JOIN ' . DB::t('orders') . ' o ON o.id=a.order_id
               WHERE a.id=? AND a.user_id=?', [$attemptId, $u['id']]);
if (!$at) redirect(SITE_URL . '/panel/');
if ($at['mode'] === 'full' && $at['os'] !== 'paid') redirect(SITE_URL . '/panel/test-start.php?id=' . $at['test_id']);
if ($at['status'] !== 'in_progress') redirect(SITE_URL . '/panel/report.php?attempt=' . $attemptId);

/* برچسب‌های طیف لیکرت (فارسی) */
$likertLabels = [
    6 => ['۱- اصلاً در مورد من صادق نیست','۲- بیشتر اوقات صادق نیست','۳- تا حدی صادق است','۴- نسبتاً صادق است','۵- بیشتر اوقات صادق است','۶- کاملاً در مورد من صادق است'],
    5 => ['۱- کاملاً مخالفم','۲- مخالفم','۳- نظری ندارم','۴- موافقم','۵- کاملاً موافقم'],
];

render_header('درحال انجام: ' . $at['title']);
?>
<div class="fr-app" style="max-width:900px;margin:0 auto;display:block;padding:70px 16px 40px">
  <div class="fr-test-wrap">
    <div class="fr-test-head">
      <div class="fr-progress"><i id="progBar" style="width:0%"></i></div>
      <div class="pct" id="progPct">۰٪</div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:10px;flex-wrap:wrap">
      <b style="font-size:15px"><?= e($at['title']) ?></b>
      <div style="display:flex;gap:8px;align-items:center">
        <a class="fr-btn ghost sm" href="#" id="btnReview" title="نمایش سؤال‌هایی که هنوز بی‌پاسخ‌اند">📋 مرور بی‌پاسخ‌ها</a>
        <a class="fr-btn ghost sm" href="index.php" onclick="return confirm('پیشرفت ذخیره می‌شود و می‌توانید بعداً ادامه دهید. خروج؟')">خروج موقت</a>
      </div>
    </div>

    <!-- کارت مرور سؤال‌های بی‌پاسخ (در پایان تست روانشناسی یا با دکمهٔ مرور نمایش داده می‌شود) -->
    <div id="reviewBox" style="display:none;background:#fff7ed;border:1.5px dashed #f0b26b;border-radius:16px;padding:14px 16px;margin-bottom:14px">
      <b style="font-size:13.5px;color:#9a5b12">⏳ این سؤال‌ها هنوز بی‌پاسخ‌اند — روی هرکدام بزنید تا مستقیم بروید سراغش:</b>
      <div id="reviewChips" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px"></div>
      <small style="display:block;color:#9a5b12;font-size:11.5px;margin-top:8px">بعد از هر پاسخ، خودکار به سؤال بی‌پاسخ بعدی می‌روید. 🦢</small>
    </div>

    <div class="fr-qcard">
      <div class="fr-qnum">سؤال <span id="qNum">۱</span> از <span id="qTotal"><?= fa_num($at['question_count']) ?></span></div>
      <div class="fr-qtext" id="qText">درحال بارگذاری...</div>
      <div id="qBody"></div>

      <!-- قالب چهاروصفی DISC -->
      <div id="discArea" style="display:none">
        <div class="fr-disc4" id="disc4"></div>
        <p style="font-size:12px;color:var(--muted);margin-top:12px">
          ابتدا روی توصیفی که <b style="color:#0c7a4d">بیشترین</b> شباهت را به شما دارد کلیک کنید، سپس توصیف با <b style="color:#b0262c">کمترین</b> شباهت.
        </p>
      </div>

      <div class="fr-test-nav">
        <button class="fr-btn ghost" id="btnPrev">→ سؤال قبل</button>
        <span id="autoSave" style="font-size:12px;color:var(--muted);align-self:center"></span>
        <button class="fr-btn" id="btnNext">سؤال بعد ←</button>
        <button class="fr-btn accent" id="btnFinish">پایان تست روانشناسی و مشاهدهٔ تحلیل 🎯</button>
      </div>
    </div>
  </div>
</div>
<script>
const ATTEMPT = <?= $attemptId ?>;
const API     = '../api/attempt.php';
const LABELS  = <?= json_encode($likertLabels, JSON_UNESCAPED_UNICODE) ?>;

let TOTAL   = <?= max(1, (int)$at['question_count']) ?>;   // با state سرور سینک می‌شود
let questions = [];        // کش سؤال‌ها (کلید: ایندکس = number-1)
let idx = <?= max(0, (int)$at['progress'] - 1) ?>;
let saving = false;
let missing = [];          // شمارهٔ سؤال‌های بی‌پاسخ (از سرور یا محاسبهٔ محلی)

const $id = x => document.getElementById(x);

/* سؤال از منظر محلی «کامل» است؟ */
function isComplete(q){
  if (!q || !q.prev) return false;
  if (q.format === 'mostleast')
    return q.prev.most_idx !== null && q.prev.most_idx !== undefined &&
           q.prev.least_idx !== null && q.prev.least_idx !== undefined;
  return q.prev.value !== null && q.prev.value !== undefined;
}

/* فهرست بی‌پاسخ‌های محلی (وقتی همهٔ سؤال‌ها در کش هستند) */
function localMissing(){
  const out = [];
  for (let i=0;i<TOTAL;i++) if (!isComplete(questions[i])) out.push(i+1);
  return out;
}

/* بارگذاری دسته‌ای سؤال‌ها از سرور */
async function loadMore(offset){
  const r = await api(API, {action:'questions', attempt:ATTEMPT, offset, limit:10});
  if (!r.ok) { toast(r.message||'خطا در دریافت سؤال‌ها','err'); if(r.redirect) location.href=r.redirect; return false; }
  r.questions.forEach(q => questions[q.number-1] = q);
  return true;
}

/* اطمینان از موجودبودن سؤال در کش */
async function ensure(i){
  if (questions[i]) return true;
  return loadMore(Math.floor(i/10)*10);
}

/* رندر سؤال جاری */
function render(){
  const q = questions[idx];
  if (!q) return;
  $id('qNum').textContent = faNum(q.number);
  $id('qTotal').textContent = faNum(TOTAL);
  $id('qText').textContent = q.text;

  const body = $id('qBody'), disc = $id('discArea'), disc4 = $id('disc4');
  body.innerHTML=''; disc.style.display='none'; disc4.innerHTML='';

  if (q.format === 'mostleast') {
    disc.style.display='block';
    (q.options||[]).forEach((opt,i)=>{
      const el=document.createElement('div');
      el.className='opt';
      el.dataset.i=i;
      el.innerHTML = '◈ ' + opt + '<small></small>';
      el.onclick=()=>pickDisc(q,i,el);
      disc4.appendChild(el);
    });
    paintDisc(q);
  } else {
    const wrap=document.createElement('div'); wrap.className='fr-likert';
    const labels = (q.labels && q.labels.length) ? q.labels : (LABELS[q.scale_max] || LABELS[5]);
    for(let v=q.scale_min; v<=q.scale_max; v++){
      const b=document.createElement('button');
      b.textContent = labels[v-1] || ('گزینه '+faNum(v));
      b.dataset.v=v;
      if (q.prev && String(q.prev.value)===String(v)) b.classList.add('sel');
      b.onclick=()=>pickLikert(q,v,b);
      wrap.appendChild(b);
    }
    body.appendChild(wrap);
  }
  updateNav();
}

/* بعد از پاسخ: در «حالت مرور» به سؤال بی‌پاسخ بعدی برو، وگرنه سؤال بعد */
function advanceSmart(){
  missing = localMissing().length && missing.length ? missing.filter(n => questions[n-1] && !isComplete(questions[n-1])) : missing;
  const reviewActive = $id('reviewBox').style.display !== 'none';
  if (reviewActive) {
    paintReview();
    const next = missing.find(n => questions[n-1] && !isComplete(questions[n-1]));
    if (next !== undefined) { setTimeout(()=>go(next-1), 240); return; }
    // همه پاسخ داده شد — مرور را ببند و دکمهٔ پایان را برجسته کن
    $id('reviewBox').style.display = 'none';
    const f = $id('btnFinish');
    f.classList.add('pulse');
    toast('همهٔ سؤال‌ها کامل شد؛ حالا «پایان تست روانشناسی» را بزنید 🎯','ok');
    return;
  }
  setTimeout(()=>{ if(idx+1<TOTAL) go(idx+1); }, 220);
}

/* انتخاب لیکرت + ذخیرهٔ خودکار */
async function pickLikert(q,v,btn){
  if (saving) return;
  [...btn.parentNode.children].forEach(x=>x.classList.remove('sel'));
  btn.classList.add('sel');
  q.prev = {value:v};
  await save({question_id:q.id, value:v});
  advanceSmart();
}

/* انتخاب بیشترین/کمترین در DISC */
async function pickDisc(q,i,el){
  if (saving) return;
  q.prev = q.prev||{};
  const opts=[...document.querySelectorAll('#disc4 .opt')];
  if (q.prev.most_idx===null || q.prev.most_idx===undefined) {
    if (q.prev.least_idx===i) { toast('نمی‌توانید همزمان کمترین و بیشترین باشد.','warn'); return; }
    q.prev.most_idx=i; el.classList.add('most');
  } else if (q.prev.most_idx===i) {
    q.prev.most_idx=null; el.classList.remove('most');
    await save({question_id:q.id, most_idx:null, least_idx:q.prev.least_idx??null}); paintDisc(q); return;
  } else if (q.prev.least_idx===null || q.prev.least_idx===undefined) {
    q.prev.least_idx=i; el.classList.add('least');
  } else if (q.prev.least_idx===i) {
    q.prev.least_idx=null; el.classList.remove('least');
    await save({question_id:q.id, most_idx:q.prev.most_idx, least_idx:null}); paintDisc(q); return;
  }
  await save({question_id:q.id, most_idx:q.prev.most_idx??null, least_idx:q.prev.least_idx??null});
  paintDisc(q);
  if (isComplete(q)) advanceSmart();
}
function paintDisc(q){
  document.querySelectorAll('#disc4 .opt').forEach(el=>{
    const i=+el.dataset.i;
    el.classList.toggle('most',  q.prev && q.prev.most_idx===i);
    el.classList.toggle('least', q.prev && q.prev.least_idx===i);
  });
}

/* ذخیرهٔ پاسخ در سرور */
async function save(payload){
  saving=true; $id('autoSave').textContent='درحال ذخیره...';
  try{
    const r=await api(API, Object.assign({action:'save', attempt:ATTEMPT}, payload));
    if(!r.ok){ toast(r.message,'err'); }
    else{ setProg(r.progress); $id('autoSave').textContent='✓ ذخیره شد'; }
  } finally { saving=false; setTimeout(()=>$id('autoSave').textContent='',1500); }
}

/* پیشرفت */
function setProg(n){
  const pct=TOTAL>0?Math.round(n/TOTAL*100):0;
  $id('progBar').style.width=pct+'%';
  $id('progPct').textContent=faNum(pct)+'٪';
}

/* ناوبری */
async function go(n){
  if (n<0||n>=TOTAL) return;
  idx=n;
  if(!await ensure(idx)) return;
  render();
  window.scrollTo({top:0, behavior:'smooth'});
}
function updateNav(){
  $id('btnPrev').disabled = idx===0;
  $id('btnNext').disabled = idx===TOTAL-1;
  $id('btnNext').style.display = idx===TOTAL-1 ? 'none' : 'inline-flex';
  $id('btnFinish').style.display = 'inline-flex';      // پایان همیشه در دسترس — نسخهٔ ۱٫۶
}
$id('btnPrev').onclick=()=>go(idx-1);
$id('btnNext').onclick=()=>go(idx+1);

/* کارت مرور بی‌پاسخ‌ها — رندر چیپ‌ها */
function paintReview(){
  const box=$id('reviewChips'); box.innerHTML='';
  missing.forEach(n=>{
    const done = questions[n-1] && isComplete(questions[n-1]);
    if (done) return;
    const b=document.createElement('button');
    b.className='fr-btn sm ghost';
    b.style.cssText='border-color:#f0b26b;color:#9a5b12;border-radius:12px';
    b.textContent='سؤال '+faNum(n);
    b.onclick=()=>go(n-1);
    box.appendChild(b);
  });
  if (!box.children.length) $id('reviewBox').style.display='none';
}

/* دکمهٔ مرور دستی بی‌پاسخ‌ها */
$id('btnReview').onclick = async (e)=>{
  e.preventDefault();
  for (let i=0;i<TOTAL;i++) await ensure(i);       // کش کامل برای دقت فهرست
  missing = localMissing();
  if (!missing.length) { toast('همهٔ سؤال‌ها پاسخ داده شده‌اند ✅','ok'); return; }
  $id('reviewBox').style.display='block';
  paintReview();
  go(missing[0]-1);
};

/* پایان */
$id('btnFinish').onclick=async()=>{
  const btn=$id('btnFinish'); btn.disabled=true; btn.innerHTML='<span class="spin"></span> درحال بررسی پاسخ‌ها...';
  const r=await api(API,{action:'finish',attempt:ATTEMPT});
  if(!r.ok){
    btn.disabled=false; btn.innerHTML='پایان تست روانشناسی و مشاهدهٔ تحلیل 🎯';
    if (r.redirect){ location.href=r.redirect; return; }
    /* سؤال‌های بی‌پاسخ — به‌ترتیب به کاربر نشان بده (نسخهٔ ۱٫۶) */
    if (Array.isArray(r.missing) && r.missing.length){
      toast(r.message,'warn');
      missing = r.missing.map(Number);
      for (const n of missing) await ensure(n-1);
      $id('reviewBox').style.display='block';
      paintReview();
      go(missing[0]-1);
    } else {
      toast(r.message||'خطا در پایان تست روانشناسی','err');
    }
  } else {
    btn.innerHTML='<span class="spin"></span> درحال تحلیل پاسخ‌ها...';
    location.href=r.redirect;
  }
};

/* شروع */
(async()=>{
  try{
    const s=await api(API,{action:'state',attempt:ATTEMPT});
    if(s.ok && s.total>0){ TOTAL=s.total; }
    if(s.ok) setProg(s.progress);
    if (idx >= TOTAL) idx = 0;
    await loadMore(Math.floor(idx/10)*10);
    render();
  }catch(e){
    $id('qText').textContent='در بارگذاری سؤال‌ها مشکلی پیش آمد؛ صفحه را تازه کنید.';
  }
})();
</script>
<style>
/* تکان ظریف روی دکمهٔ پایان وقتی همه پاسخ‌ها کامل شد */
.fr-btn.pulse{animation:frPulse 1s ease-in-out 3}
@keyframes frPulse{50%{transform:scale(1.04);box-shadow:0 0 0 6px rgba(124,58,237,.18)}}
</style>
<?php render_footer(); ?>
