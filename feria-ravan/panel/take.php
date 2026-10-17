<?php
/**
 * ============================================================================
 *  اسکرین انجام تست روانشناسی — نسخهٔ ۲٫۱ (مقاوم، بدون معطلی و همگام)
 *  ─ حل ریشه‌ای باگ نمایش سؤال ۱ در بدو ورود بدون نیاز به ناوبری به سؤال ۲ و بازگشت
 *  ─ واکشی و تعبیهٔ مستقیم بستهٔ اولیهٔ سؤالات (INIT_QUESTIONS) سمت سرور (Zero-Latency)
 *  ─ کش دوگانه بر مبنای ایندکس ترتیبی (offset + i) و شمارهٔ سؤال (q.number - 1)
 *  ─ تشخیص هوشمند و دقیق اولین سؤال بی‌پاسخ جهت ادامهٔ تست روانشناسی
 *  ─ مدیریت جامع وضعیت لودینگ، عدم بازگشت ساکت در رندر، و دکمهٔ تلاش دوباره در خطا
 *  ─ پاسخ‌گویی هوشمند: مرور سؤال‌های بی‌پاسخ به‌ترتیب و ذخیرهٔ خودکار
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../core/TestFields.php';
fr_bootstrap();

TestFields::ensureSchema();

$u = require_user();
$attemptId = (int)($_GET['attempt'] ?? 0);
$at = DB::one('SELECT a.*, t.title, t.engine, t.question_count, t.instructions, o.status AS os
               FROM ' . DB::t('attempts') . ' a
               JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
               LEFT JOIN ' . DB::t('orders') . ' o ON o.id=a.order_id
               WHERE a.id=? AND a.user_id=?', [$attemptId, $u['id']]);
if (!$at) redirect(fr_panel_url());
if ($at['mode'] === 'full' && $at['os'] !== 'paid') redirect(fr_panel_url('test-start.php?id=' . $at['test_id']));
if ($at['status'] !== 'in_progress') redirect(fr_panel_url('report.php?attempt=' . $attemptId));

$postTestFields = TestFields::getPostTestFields((int)$at['test_id'], (string)$at['mode']);
$existingCustomFields = !empty($at['custom_fields_json']) ? json_decode((string)$at['custom_fields_json'], true) : [];
if (!is_array($existingCustomFields)) $existingCustomFields = [];

/* برچسب‌های طیف لیکرت (فارسی) */
$likertLabels = [
    6 => ['۱- اصلاً در مورد من صادق نیست','۲- بیشتر اوقات صادق نیست','۳- تا حدی صادق است','۴- نسبتاً صادق است','۵- بیشتر اوقات صادق است','۶- کاملاً در مورد من صادق است'],
    5 => ['۱- کاملاً مخالفم','۲- مخالفم','۳- نظری ندارم','۴- موافقم','۵- کاملاً موافقم'],
];

// تعداد دقیق کل سوالات فعال این آزمون
$totalQuestions = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1', [$at['test_id']]);
if ($totalQuestions === 0 && !empty($at['question_count'])) {
    $totalQuestions = (int)$at['question_count'];
}

// تعداد پاسخ‌های ثبت شده
$answeredCount = fr_attempt_answered($attemptId);

// یافتن شماره اولین سؤال بی‌پاسخ جهت ادامهٔ دقیق
$firstUnansweredNumber = (int)DB::val(
    'SELECT q.number FROM ' . DB::t('questions') . ' q
     LEFT JOIN ' . DB::t('answers') . ' a ON a.question_id=q.id AND a.attempt_id=?
     WHERE q.test_id=? AND q.active=1
       AND NOT (a.value IS NOT NULL OR (a.most_idx IS NOT NULL AND a.least_idx IS NOT NULL))
     ORDER BY q.number LIMIT 1',
    [$attemptId, $at['test_id']]
);

// ایندکس ترتیبی سؤال شروع (0-مبنا)
$startIdx = 0;
if ($firstUnansweredNumber > 0) {
    $startIdx = (int)DB::val(
        'SELECT COUNT(*) FROM ' . DB::t('questions') .
        ' WHERE test_id=? AND active=1 AND number < ?',
        [$at['test_id'], $firstUnansweredNumber]
    );
} elseif ($answeredCount > 0 && $totalQuestions > 0) {
    // همه پاسخ داده شده‌اند
    $startIdx = 0;
}
if ($startIdx >= $totalQuestions && $totalQuestions > 0) {
    $startIdx = 0;
}

// واکشی دستهٔ اولیهٔ سوالات (۱۰ سؤال از محل شروع) در سمت سرور برای نمایش بدون معطلی
$initOffset = (int)(floor($startIdx / 10) * 10);
$initBatch = DB::all(
    'SELECT id, number, text, format, options, labels, min_label, max_label, scale_min, scale_max
     FROM ' . DB::t('questions') . '
     WHERE test_id=? AND active=1 ORDER BY number LIMIT 10 OFFSET ?',
    [$at['test_id'], $initOffset]
);

$qIds = array_column($initBatch, 'id');
$initPrev = [];
if ($qIds) {
    $ph = implode(',', array_fill(0, count($qIds), '?'));
    foreach (DB::all('SELECT question_id, value, most_idx, least_idx FROM ' . DB::t('answers') .
                     " WHERE attempt_id=? AND question_id IN ($ph)",
                     array_merge([$attemptId], $qIds)) as $a) {
        $initPrev[(int)$a['question_id']] = $a;
    }
}

foreach ($initBatch as &$q) {
    $q['id']        = (int)$q['id'];
    $q['number']    = (int)$q['number'];
    $q['scale_min'] = (int)$q['scale_min'];
    $q['scale_max'] = (int)$q['scale_max'];
    $q['options']   = !empty($q['options']) ? (is_array($q['options']) ? $q['options'] : json_decode($q['options'], true)) : null;
    $q['labels']    = !empty($q['labels']) ? (is_array($q['labels']) ? $q['labels'] : json_decode($q['labels'], true)) : null;
    $q['prev']      = $initPrev[$q['id']] ?? null;
}
unset($q);

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
        <a class="fr-btn ghost sm" href="<?= e(fr_panel_url()) ?>" onclick="return confirm('پیشرفت ذخیره می‌شود و می‌توانید بعداً ادامه دهید. خروج؟')">خروج موقت</a>
      </div>
    </div>

    <!-- کارت مرور سؤال‌های بی‌پاسخ (در پایان تست روانشناسی یا با دکمهٔ مرور نمایش داده می‌شود) -->
    <div id="reviewBox" style="display:none;background:#fff7ed;border:1.5px dashed #f0b26b;border-radius:16px;padding:14px 16px;margin-bottom:14px">
      <b style="font-size:13.5px;color:#9a5b12">⏳ این سؤال‌ها هنوز بی‌پاسخ‌اند — روی هرکدام بزنید تا مستقیم بروید سراغش:</b>
      <div id="reviewChips" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px"></div>
      <small style="display:block;color:#9a5b12;font-size:11.5px;margin-top:8px">بعد از هر پاسخ، خودکار به سؤال بی‌پاسخ بعدی می‌روید. 🦢</small>
    </div>

    <div class="fr-qcard">
      <div class="fr-qnum">سؤال <span id="qNum">۱</span> از <span id="qTotal"><?= fa_num($totalQuestions > 0 ? $totalQuestions : $at['question_count']) ?></span></div>
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
        <button type="button" class="fr-btn ghost" id="btnPrev">→ سؤال قبل</button>
        <span id="autoSave" style="font-size:12px;color:var(--muted);align-self:center"></span>
        <button type="button" class="fr-btn" id="btnNext">سؤال بعد ←</button>
        <button type="button" class="fr-btn accent" id="btnFinish">پایان تست روانشناسی و مشاهدهٔ تحلیل 🎯</button>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($postTestFields)): ?>
<!-- مودال / مرحله پرسش‌های پایانی و مشاوره رایگان پس از اتمام تست -->
<div id="postTestModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.8);z-index:99999;overflow-y:auto;padding:24px 14px;backdrop-filter:blur(6px);align-items:center;justify-content:center">
  <div class="fr-card" style="max-width:680px;width:100%;margin:auto;border-radius:18px;border:2px solid #cbd5e1;box-shadow:0 25px 50px -12px rgba(0,0,0,0.3);background:#fff;padding:26px">
    <div style="text-align:center;padding:6px 0 16px;border-bottom:1px solid #e2e8f0;margin-bottom:20px">
      <div style="font-size:42px;line-height:1">🎉</div>
      <h3 style="margin:10px 0 6px;font-size:20px;color:#1e293b;font-weight:900">پاسخ‌گویی به سؤالات با موفقیت به پایان رسید!</h3>
      <p style="margin:0;font-size:13.5px;color:#64748b">لطفاً پیش از صدور کارنامه و تحلیل نهایی، به پرسش‌های پایانی زیر پاسخ دهید:</p>
    </div>

    <form id="postTestForm" method="post">
      <?= TestFields::renderFormFields($postTestFields, $existingCustomFields, 'postTestFieldsBox') ?>

      <div style="margin-top:24px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-top:1px solid #e2e8f0;padding-top:18px">
        <button type="button" class="fr-btn sm ghost" id="btnBackToQuestions" style="color:#64748b">
          ← بازگشت به مرور سؤال‌ها
        </button>
        <button type="submit" class="fr-btn primary" id="btnSubmitPostFinal" style="padding:12px 28px;font-size:15px;font-weight:800;background:linear-gradient(135deg, #2563eb, #1d4ed8)">
          ثبت نهایی و مشاهده تحلیل کارنامه 🎯
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="<?= SITE_URL ?>/assets/js/feria.js?v=<?= FR_VERSION ?>"></script>
<script>
(function(){
  'use strict';

  const ATTEMPT    = <?= $attemptId ?>;
  const API        = '<?= SITE_URL ?>/api/attempt.php';
  const LABELS     = <?= json_encode($likertLabels, JSON_UNESCAPED_UNICODE) ?>;
  const START_TIME = Date.now();
  const HAS_POST_FIELDS = <?= json_encode(!empty($postTestFields)) ?>;

  let TOTAL        = <?= max(1, $totalQuestions) ?>;
  let questions    = [];        // کش سؤال‌ها (پشتیبانی توأم از ایندکس ترتیبی و q.number-1)
  let idx          = <?= $startIdx ?>;
  let saving       = false;
  let missing      = [];        // شمارهٔ سؤال‌های بی‌پاسخ

  const INIT_DATA  = <?= json_encode($initBatch, JSON_UNESCAPED_UNICODE) ?>;
  const INIT_OFFSET= <?= $initOffset ?>;
  const INIT_PROG  = <?= $answeredCount ?>;

  const $id = x => document.getElementById(x);
  const toFa = n => (window.faNum ? window.faNum(n) : String(n));

  /* ذخیره‌سازی دستهٔ سوالات در کش محلی با هر دو نوع نگاشت */
  function cacheQuestions(list, offset) {
    if (!Array.isArray(list)) return;
    offset = Math.max(0, parseInt(offset, 10) || 0);
    list.forEach((q, i) => {
      if (!q) return;
      const seqIdx = offset + i;
      questions[seqIdx] = q;

      const num = parseInt(q.number, 10);
      if (!isNaN(num) && num >= 1) {
        if (!questions[num - 1]) {
          questions[num - 1] = q;
        }
      }
    });
  }

  /* سؤال از منظر محلی «کامل» است؟ */
  function isComplete(q){
    if (!q || !q.prev) return false;
    if (q.format === 'mostleast')
      return q.prev.most_idx !== null && q.prev.most_idx !== undefined &&
             q.prev.least_idx !== null && q.prev.least_idx !== undefined;
    return q.prev.value !== null && q.prev.value !== undefined;
  }

  /* فهرست بی‌پاسخ‌های محلی */
  function localMissing(){
    const out = [];
    for (let i = 0; i < TOTAL; i++) {
      if (!questions[i] || !isComplete(questions[i])) {
        const num = (questions[i] && questions[i].number) ? questions[i].number : (i + 1);
        out.push(num);
      }
    }
    return out;
  }

  /* بارگذاری دسته‌ای سؤال‌ها از سرور با گارد قفل و خطا */
  let activeLoads = {};
  async function loadMore(offset){
    offset = Math.max(0, parseInt(offset, 10) || 0);
    if (activeLoads[offset]) return await activeLoads[offset];

    activeLoads[offset] = (async () => {
      try {
        const fnApi = window.api || (typeof api === 'function' ? api : null);
        if (!fnApi) {
          console.error('API client unavailable');
          return false;
        }
        const r = await fnApi(API, {action:'questions', attempt:ATTEMPT, offset, limit:10});
        if (!r || !r.ok) {
          if (r && r.redirect) { location.href = r.redirect; return false; }
          if (window.toast) toast((r && r.message) || 'خطا در دریافت سؤال‌ها', 'err');
          return false;
        }
        if (Array.isArray(r.questions)) {
          cacheQuestions(r.questions, offset);
        }
        return true;
      } catch (err) {
        console.error('loadMore error', err);
        return false;
      } finally {
        delete activeLoads[offset];
      }
    })();

    return await activeLoads[offset];
  }

  /* اطمینان صددرصدی از موجود بودن سؤال در کش */
  async function ensure(i){
    if (questions[i]) return true;
    const batch = Math.floor(i / 10) * 10;
    await loadMore(batch);
    return !!questions[i];
  }

  /* رندر سؤال جاری — با مدیریت وضعیت‌های لودینگ/خطا و بدون بازگشت ساکت */
  async function render(){
    let q = questions[idx];
    if (!q) {
      $id('qText').innerHTML = '<span class="spin"></span> درحال بارگذاری سؤال…';
      $id('qBody').innerHTML = '';
      const ok = await ensure(idx);
      if (!ok || !questions[idx]) {
        $id('qText').textContent = 'دریافت این سؤال با تأخیر مواجه شد.';
        $id('qBody').innerHTML = '<div style="text-align:center;padding:20px"><button type="button" class="fr-btn sm" id="btnRetryQ">تلاش دوباره 🔄</button></div>';
        const retryBtn = $id('btnRetryQ');
        if (retryBtn) retryBtn.onclick = () => render();
        updateNav();
        return;
      }
      q = questions[idx];
    }

    const dispNum = (q.number && !isNaN(q.number)) ? q.number : (idx + 1);
    $id('qNum').textContent = toFa(dispNum);
    $id('qTotal').textContent = toFa(TOTAL);
    $id('qText').textContent = q.text || '';

    const body = $id('qBody'), disc = $id('discArea'), disc4 = $id('disc4');
    body.innerHTML = ''; disc.style.display = 'none'; disc4.innerHTML = '';

    if (q.format === 'mostleast') {
      disc.style.display = 'block';
      (q.options || []).forEach((opt, i) => {
        const el = document.createElement('div');
        el.className = 'opt';
        el.dataset.i = i;
        el.innerHTML = '◈ ' + opt + '<small></small>';
        el.onclick = () => pickDisc(q, i, el);
        disc4.appendChild(el);
      });
      paintDisc(q);
    } else {
      const wrap = document.createElement('div');
      wrap.className = 'fr-likert';
      const labels = (q.labels && q.labels.length) ? q.labels : (LABELS[q.scale_max] || LABELS[5]);
      for (let v = q.scale_min; v <= q.scale_max; v++) {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = (q.options && q.options[v] !== undefined) ? q.options[v] : (labels[v - 1] || ('گزینه ' + toFa(v)));
        b.dataset.v = v;
        if (q.prev && String(q.prev.value) === String(v)) b.classList.add('sel');
        b.onclick = () => pickLikert(q, v, b);
        wrap.appendChild(b);
      }
      body.appendChild(wrap);
    }
    updateNav();
  }

  /* پیشرفت هوشمند پس از پاسخ */
  function advanceSmart(){
    const reviewActive = $id('reviewBox').style.display !== 'none';
    if (reviewActive) {
      paintReview();
      const nextNum = missing.find(n => {
        const candidate = questions.find(q => q && q.number === n);
        return !isComplete(candidate);
      });
      if (nextNum !== undefined) {
        const nextIdx = questions.findIndex(q => q && q.number === nextNum);
        if (nextIdx >= 0) {
          setTimeout(() => go(nextIdx), 240);
          return;
        }
      }
      $id('reviewBox').style.display = 'none';
      const f = $id('btnFinish');
      f.classList.add('pulse');
      if (window.toast) toast('همهٔ سؤال‌ها کامل شد؛ حالا «پایان تست روانشناسی» را بزنید 🎯', 'ok');
      return;
    }
    setTimeout(() => { if (idx + 1 < TOTAL) go(idx + 1); }, 220);
  }

  /* انتخاب لیکرت + ذخیره خودکار */
  async function pickLikert(q, v, btn){
    if (saving) return;
    [...btn.parentNode.children].forEach(x => x.classList.remove('sel'));
    btn.classList.add('sel');
    q.prev = {value: v};
    await save({question_id: q.id, value: v});
    advanceSmart();
  }

  /* انتخاب DISC */
  async function pickDisc(q, i, el){
    if (saving) return;
    q.prev = q.prev || {};
    if (q.prev.most_idx === null || q.prev.most_idx === undefined) {
      if (q.prev.least_idx === i) {
        if (window.toast) toast('نمی‌توانید همزمان کمترین و بیشترین باشد.', 'warn');
        return;
      }
      q.prev.most_idx = i;
      el.classList.add('most');
    } else if (q.prev.most_idx === i) {
      q.prev.most_idx = null;
      el.classList.remove('most');
      await save({question_id: q.id, most_idx: null, least_idx: q.prev.least_idx ?? null});
      paintDisc(q);
      return;
    } else if (q.prev.least_idx === null || q.prev.least_idx === undefined) {
      q.prev.least_idx = i;
      el.classList.add('least');
    } else if (q.prev.least_idx === i) {
      q.prev.least_idx = null;
      el.classList.remove('least');
      await save({question_id: q.id, most_idx: q.prev.most_idx, least_idx: null});
      paintDisc(q);
      return;
    }
    await save({question_id: q.id, most_idx: q.prev.most_idx ?? null, least_idx: q.prev.least_idx ?? null});
    paintDisc(q);
    if (isComplete(q)) advanceSmart();
  }

  function paintDisc(q){
    document.querySelectorAll('#disc4 .opt').forEach(el => {
      const i = +el.dataset.i;
      el.classList.toggle('most',  q.prev && q.prev.most_idx === i);
      el.classList.toggle('least', q.prev && q.prev.least_idx === i);
    });
  }

  /* ذخیره در سرور */
  async function save(payload){
    saving = true;
    const as = $id('autoSave');
    if (as) as.textContent = 'درحال ذخیره...';
    try {
      const fnApi = window.api || (typeof api === 'function' ? api : null);
      if (!fnApi) return;
      const r = await fnApi(API, Object.assign({action:'save', attempt:ATTEMPT}, payload));
      if (!r || !r.ok) {
        if (window.toast) toast((r && r.message) || 'خطا در ذخیرهٔ پاسخ', 'err');
      } else {
        if (typeof r.progress === 'number') setProg(r.progress);
        if (as) as.textContent = '✓ ذخیره شد';
      }
    } finally {
      saving = false;
      setTimeout(() => { if (as) as.textContent = ''; }, 1500);
    }
  }

  /* نوار پیشرفت */
  function setProg(n){
    const pct = TOTAL > 0 ? Math.round(n / TOTAL * 100) : 0;
    const pb = $id('progBar'), pp = $id('progPct');
    if (pb) pb.style.width = pct + '%';
    if (pp) pp.textContent = toFa(pct) + '٪';
  }

  /* ناوبری به سؤال شماره n */
  async function go(n){
    if (n < 0 || n >= TOTAL) return;
    idx = n;
    await ensure(idx);
    render();
    window.scrollTo({top:0, behavior:'smooth'});
  }

  function updateNav(){
    const bp = $id('btnPrev'), bn = $id('btnNext'), bf = $id('btnFinish');
    if (bp) bp.disabled = (idx === 0);
    if (bn) {
      bn.disabled = (idx >= TOTAL - 1);
      bn.style.display = (idx >= TOTAL - 1) ? 'none' : 'inline-flex';
    }
    if (bf) bf.style.display = 'inline-flex';
  }

  $id('btnPrev').onclick = () => go(idx - 1);
  $id('btnNext').onclick = () => go(idx + 1);

  /* کارت مرور بی‌پاسخ‌ها */
  function paintReview(){
    const box = $id('reviewChips');
    if (!box) return;
    box.innerHTML = '';
    missing.forEach(n => {
      const q = questions.find(item => item && item.number === n);
      if (q && isComplete(q)) return;
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'fr-btn sm ghost';
      b.style.cssText = 'border-color:#f0b26b;color:#9a5b12;border-radius:12px';
      b.textContent = 'سؤال ' + toFa(n);
      b.onclick = () => {
        const targetIdx = questions.findIndex(item => item && item.number === n);
        if (targetIdx >= 0) go(targetIdx);
        else go(n - 1);
      };
      box.appendChild(b);
    });
    if (!box.children.length) $id('reviewBox').style.display = 'none';
  }

  $id('btnReview').onclick = async (e) => {
    e.preventDefault();
    for (let i = 0; i < TOTAL; i += 10) await loadMore(i);
    missing = localMissing();
    if (!missing.length) {
      if (window.toast) toast('همهٔ سؤال‌ها پاسخ داده شده‌اند ✅', 'ok');
      return;
    }
    $id('reviewBox').style.display = 'block';
    paintReview();
    const firstMiss = missing[0];
    const firstIdx = questions.findIndex(item => item && item.number === firstMiss);
    go(firstIdx >= 0 ? firstIdx : firstMiss - 1);
  };

  /* پایان آزمون با پشتیبانی از گام پرسش‌های تکمیلی و مشاوره رایگان */
  $id('btnFinish').onclick = async () => {
    const btn = $id('btnFinish');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> درحال بررسی پاسخ‌ها...';

    for (let i = 0; i < TOTAL; i += 10) await loadMore(i);
    missing = localMissing();
    if (missing.length > 0) {
      btn.disabled = false;
      btn.innerHTML = 'پایان تست روانشناسی و مشاهدهٔ تحلیل 🎯';
      if (window.toast) toast('هنوز ' + toFa(missing.length) + ' سؤال بی‌پاسخ مانده است.', 'warn');
      $id('reviewBox').style.display = 'block';
      paintReview();
      const fMiss = missing[0];
      const fIdx = questions.findIndex(q => q && q.number === fMiss);
      go(fIdx >= 0 ? fIdx : fMiss - 1);
      return;
    }

    if (HAS_POST_FIELDS) {
      btn.disabled = false;
      btn.innerHTML = 'پایان تست روانشناسی و مشاهدهٔ تحلیل 🎯';
      const modal = $id('postTestModal');
      if (modal) {
        modal.style.display = 'flex';
        modal.scrollIntoView({ behavior: 'smooth' });
        return;
      }
    }

    await executeFinishSubmit({});
  };

  async function executeFinishSubmit(customFields) {
    const btn = $id('btnFinish');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> درحال تحلیل پاسخ‌ها...';

    const durationSec = Math.max(1, Math.round((Date.now() - START_TIME) / 1000));
    const fnApi = window.api || (typeof api === 'function' ? api : null);
    const postPayload = {
      action: 'finish',
      attempt: ATTEMPT,
      duration: durationSec,
      custom_fields: customFields
    };

    const r = fnApi ? await fnApi(API, postPayload) : null;
    if (!r || !r.ok) {
      btn.disabled = false;
      btn.innerHTML = 'پایان تست روانشناسی و مشاهدهٔ تحلیل 🎯';
      const btnPost = $id('btnSubmitPostFinal');
      if (btnPost) {
        btnPost.disabled = false;
        btnPost.innerHTML = 'ثبت نهایی و مشاهده تحلیل کارنامه 🎯';
      }

      if (r && r.redirect) { location.href = r.redirect; return; }
      if (r && Array.isArray(r.missing) && r.missing.length) {
        if ($id('postTestModal')) $id('postTestModal').style.display = 'none';
        if (window.toast) toast(r.message, 'warn');
        missing = r.missing.map(Number);
        for (const n of missing) {
          const qIdx = questions.findIndex(q => q && q.number === n);
          await ensure(qIdx >= 0 ? qIdx : n - 1);
        }
        $id('reviewBox').style.display = 'block';
        paintReview();
        const fMiss = missing[0];
        const fIdx = questions.findIndex(q => q && q.number === fMiss);
        go(fIdx >= 0 ? fIdx : fMiss - 1);
      } else {
        if (window.toast) toast((r && r.message) || 'خطا در ثبت نهایی آزمون', 'err');
      }
    } else {
      location.href = r.redirect;
    }
  }

  const ptForm = $id('postTestForm');
  if (ptForm) {
    ptForm.onsubmit = async (e) => {
      e.preventDefault();
      const btnSubmit = $id('btnSubmitPostFinal');
      btnSubmit.disabled = true;
      btnSubmit.innerHTML = '<span class="spin"></span> درحال ثبت و تحلیل کارنامه...';

      const customData = {};
      const fd = new FormData(ptForm);
      for (const [rawKey, val] of fd.entries()) {
        const m = rawKey.match(/^test_custom_fields\[(.*?)\](\[\])?$/);
        if (m) {
          const fieldKey = m[1];
          const isArr = !!m[2];
          if (isArr) {
            if (!customData[fieldKey]) customData[fieldKey] = [];
            customData[fieldKey].push(val);
          } else {
            customData[fieldKey] = val;
          }
        }
      }

      await executeFinishSubmit(customData);
    };

    const btnBack = $id('btnBackToQuestions');
    if (btnBack) {
      btnBack.onclick = () => {
        $id('postTestModal').style.display = 'none';
      };
    }
  }

  /* =========================================================================
   | شروع سریع و مقاوم (Immediate Zero-Latency Startup)
   | ۱) کش با داده‌های اولیهٔ تعبیه‌شده در سرور بدون هیچ معطلی پر می‌شود
   | ۲) سؤال ۱ (یا سؤال ازسرگیری) بلافاصله در لحظهٔ لود رندر می‌شود
   | ۳) همگام‌سازی ناهمگام با سرور در پس‌زمینه صورت می‌گیرد
   * =======================================================================*/
  if (Array.isArray(INIT_DATA) && INIT_DATA.length > 0) {
    cacheQuestions(INIT_DATA, INIT_OFFSET);
  }
  setProg(INIT_PROG);
  render();

  // همگام‌سازی پس‌زمینه برای شمارش دقیق سرور و پیشرفت
  (async () => {
    try {
      const fnApi = window.api || (typeof api === 'function' ? api : null);
      if (!fnApi) return;
      const s = await fnApi(API, {action:'state', attempt:ATTEMPT});
      if (s && s.ok) {
        if (s.total > 0 && s.total !== TOTAL) {
          TOTAL = s.total;
          $id('qTotal').textContent = toFa(TOTAL);
        }
        if (typeof s.progress === 'number') {
          setProg(s.progress);
        }
      }
    } catch (e) {
      // عدم تأثیر خطای پس‌زمینه بر نمایش سؤال جاری
      console.warn('Background state sync error', e);
    }
  })();

})();
</script>
<style>
/* تکان ظریف روی دکمهٔ پایان وقتی همه پاسخ‌ها کامل شد */
.fr-btn.pulse{animation:frPulse 1s ease-in-out 3}
@keyframes frPulse{50%{transform:scale(1.04);box-shadow:0 0 0 6px rgba(124,58,237,.18)}}
</style>
<?php render_footer(); ?>
