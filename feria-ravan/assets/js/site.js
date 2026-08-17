/* ==========================================================================
   فریا روان — جاوااسکریپت صفحات عمومی (نسخهٔ ۱٫۴)
   ─ منوی موبایل، ویزارد بوکینگ مشاوره (تقویم شمسی + اسلات + درگاه)
   ─ سازگار با ES2017 (بدون ?. و ??) برای پوشش کامل مرورگرهای موبایل/دسکتاپ
   ========================================================================== */
(function () {
  'use strict';

  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function fa(n) { return window.faNum ? window.faNum(n) : String(n); }

  /* ======================================================================
     منوی موبایل عمومی
     ====================================================================== */
  var burger = $('#pbBurger'), links = $('#pbLinks');
  if (burger && links) {
    burger.addEventListener('click', function () {
      var open = links.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      if (!links.classList.contains('open')) return;
      if (links.contains(e.target) || burger.contains(e.target)) return;
      links.classList.remove('open');
    });
  }

  /* ======================================================================
     کپی لینک صفحهٔ رویداد (اشتراک‌گذاری)
     ====================================================================== */
  $$('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var inp = document.getElementById(btn.getAttribute('data-copy'));
      if (!inp) return;
      inp.select();
      try { inp.setSelectionRange(0, 9999); } catch (e) {}
      var done = false;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(inp.value).then(function () { window.toast && window.toast('لینک کپی شد 🔗'); done = true; });
        return;
      }
      try { done = document.execCommand('copy'); } catch (e) { done = false; }
      window.toast && window.toast(done ? 'لینک کپی شد 🔗' : 'کپی نشد؛ دستی انتخاب کنید', done ? 'ok' : 'warn');
    });
  });

  /* ======================================================================
     ویزارد رزرو مشاوره
     ساختار DOM مورد انتظار را صفحهٔ consult.php رندر می‌کند:
       #bkApp[data-service][data-login][data-resume]
       #bkCal (تقویم)، #bkSlots (ساعت‌ها)، [#bkMode]، #bkSum (جمع‌بندی)
     ====================================================================== */
  var app = $('#bkApp');
  if (app) { initBooking(app); }

  function initBooking(app) {
    var serviceId = parseInt(app.getAttribute('data-service'), 10) || 0;
    var loggedIn  = app.getAttribute('data-login') === '1';
    var loginUrl  = app.getAttribute('data-login-url') || '/auth.php';

    var state = { jy: 0, jm: 0, curJy: 0, curJm: 0, day: null, gday: null, slot: null, mode: null };

    var calBox   = $('#bkCal'), slotsBox = $('#bkSlots'),
        sumBox   = $('#bkSum'), modeBox  = $('#bkModeBox'), noteInp = $('#bkNote'),
        goBtn    = $('#bkGo'), stepEls   = $$('.bk-steps span');

    /* ---------- تبدیل جلالی ← میلادی سمت کلاینت ----------
       پورت «دقیق» الگوریتم jdf شامل functions.php تا خروجی JS و PHP همیشه یکی باشد */
    function j2g(jy, jm, jd) {
      jy += 1595;
      var days = -355668 + (365 * jy) + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4)
               + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
      var gy = 400 * Math.floor(days / 146097);
      days = days % 146097;
      if (days > 36524) {
        days--; gy += 100 * Math.floor(days / 36524); days = days % 36524;
        if (days >= 365) days++;
      }
      gy += 4 * Math.floor(days / 1461);
      days = days % 1461;
      if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
      var gd = days + 1;
      var leap = (gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0);
      var sal_a = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
      var gm = 1;
      for (; gm <= 12 && gd > sal_a[gm]; gm++) { gd -= sal_a[gm]; }
      return [gy, gm, gd];
    }
    function gStr(a) { return a[0] + '-' + ('0' + a[1]).slice(-2) + '-' + ('0' + a[2]).slice(-2); }

    /* ---------- گام‌ها ---------- */
    function setStep(n) {
      stepEls.forEach(function (el, i) {
        el.classList.toggle('on', i === n - 1);
        el.classList.toggle('done', i < n - 1);
      });
    }

    /* ---------- واکشی تقویم ماه ---------- */
    function loadMonth(jy, jm) {
      calBox.classList.add('ld');
      fetch(app.getAttribute('data-api') + '?action=month&service=' + serviceId + '&jy=' + jy + '&jm=' + jm)
        .then(function (r) { return r.json(); })
        .then(function (d) {
          calBox.classList.remove('ld');
          if (!d.ok) { window.toast && window.toast(d.message || 'خطا در بارگذاری تقویم', 'err'); return; }
          state.jy = d.jy; state.jm = d.jm;
          if (!state.curJy) { state.curJy = d.cur_jy; state.curJm = d.cur_jm; }
          renderCal(d);
        })
        .catch(function () { calBox.classList.remove('ld'); window.toast && window.toast('خطای ارتباط؛ دوباره تلاش کنید', 'err'); });
    }

    function renderCal(d) {
      var wd = ['ش','ی','د','س','چ','پ','ج'];  // ترتیب نمایش از شنبه
      var order = [6, 0, 1, 2, 3, 4, 5];        // map ستون به date('w')
      document.getElementById('bkCalTitle').textContent = d.month + ' ' + fa(d.jy);

      /* گشتن ماه — ممنوعیت رفتن به قبل از ماه جاری */
      var pBtn = document.getElementById('bkPrev'), nBtn = document.getElementById('bkNext');
      pBtn.disabled = (d.jy < state.curJy || (d.jy === state.curJy && d.jm <= state.curJm));
      pBtn.onclick = function () { if (!pBtn.disabled) loadMonth(d.prev[0], d.prev[1]); };
      nBtn.onclick = function () { loadMonth(d.next[0], d.next[1]); };

      var html = '';
      for (var i = 0; i < 7; i++) html += '<span class="wd">' + wd[i] + '</span>';
      /* آفست شروع: start_weekday بر اساس date('w'); در گرید ما شنبه ستون ۱ است */
      var offset = order.indexOf(d.start_weekday);
      for (var k = 0; k < offset; k++) html += '<span></span>';
      d.days.forEach(function (day) {
        var cls = 'bk-day' + (day.holiday ? ' holiday' : '');
        var inner = fa(day.d);
        if (!day.off && day.n > 0) inner += '<span class="n free">' + fa(day.n) + ' نوبت</span>';
        else if (!day.off) inner += '<span class="n">—</span>';
        html += '<button type="button" class="' + cls + '" data-d="' + day.d + '"' +
                ((day.off || day.n === 0) ? ' disabled' : '') + '>' + inner + '</button>';
      });
      calBox.querySelector('.bk-cal-grid').innerHTML = html;

      $$('.bk-day', calBox).forEach(function (b) {
        if (b.disabled) return;
        b.addEventListener('click', function () {
          $$('.bk-day', calBox).forEach(function (x) { x.classList.remove('sel'); });
          b.classList.add('sel');
          pickDay(parseInt(b.getAttribute('data-d'), 10));
        });
      });
    }

    /* ---------- انتخاب روز → اسلات‌ها ---------- */
    function pickDay(d) {
      state.day = d; state.slot = null;
      var g = j2g(state.jy, state.jm, d); state.gday = gStr(g);
      setStep(2);
      slotsBox.innerHTML = '<div class="bk-empty">در حال بارگذاری ساعت‌ها…</div>';
      fetch(app.getAttribute('data-api') + '?action=slots&service=' + serviceId + '&date=' + state.gday)
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) { slotsBox.innerHTML = '<div class="bk-empty">' + (res.message || 'خطا') + '</div>'; return; }
          if (!res.slots.length) { slotsBox.innerHTML = '<div class="bk-empty">برای این روز نوبت بازی نیست؛ روز دیگری را انتخاب کنید.</div>'; refreshSum(); return; }
          var html = '<h3>🕐 ساعت‌های آزادِ ' + fa(state.jy) + '/' + fa(state.jm) + '/' + fa(state.day) + '</h3><div class="bk-slot-grid">';
          res.slots.forEach(function (s) {
            html += '<button type="button" class="bk-slot" data-id="' + s.id + '" data-t="' + s.time + '">' + s.label + '</button>';
          });
          slotsBox.innerHTML = html + '</div>';
          $$('.bk-slot', slotsBox).forEach(function (b) {
            b.addEventListener('click', function () {
              $$('.bk-slot', slotsBox).forEach(function (x) { x.classList.remove('sel'); });
              b.classList.add('sel');
              state.slot = { id: b.getAttribute('data-id'), t: b.getAttribute('data-t') };
              setStep(3);
              refreshSum();
            });
          });
          refreshSum();
        })
        .catch(function () { slotsBox.innerHTML = '<div class="bk-empty">خطای ارتباط؛ دوباره تلاش کنید.</div>'; });
    }

    /* ---------- حالت جلسه ---------- */
    if (modeBox) {
      $$('.bk-mode', modeBox).forEach(function (b) {
        b.addEventListener('click', function () {
          $$('.bk-mode', modeBox).forEach(function (x) { x.classList.remove('sel'); });
          b.classList.add('sel');
          state.mode = b.getAttribute('data-mode');
          refreshSum();
        });
      });
      var first = $('.bk-mode', modeBox);
      if (first) { first.classList.add('sel'); state.mode = first.getAttribute('data-mode'); }
    }

    /* ---------- جمع‌بندی ---------- */
    function refreshSum() {
      var dateEl = $('#sumDate'), timeEl = $('#sumTime'), modeEl = $('#sumMode');
      if (dateEl) dateEl.textContent = state.day ? (fa(state.jy) + '/' + fa(state.jm) + '/' + fa(state.day)) : '—';
      if (timeEl) timeEl.textContent = state.slot ? fa(state.slot.t) : '—';
      if (modeEl && state.mode) modeEl.textContent = state.mode === 'online' ? 'آنلاین' : 'حضوری';
      if (goBtn) goBtn.disabled = !state.slot;
    }

    /* ---------- نهایی‌سازی ---------- */
    if (goBtn) {
      goBtn.addEventListener('click', function () {
        if (!state.slot) return;
        if (!loggedIn) {
          /* میهمان → ورود با بازگشت به همین نقطه */
          var back = app.getAttribute('data-resume-base') + '?s=' + app.getAttribute('data-slug') +
                     '&slot=' + state.slot.id + (state.mode ? '&mode=' + state.mode : '');
          window.location.href = loginUrl + (loginUrl.indexOf('?') > -1 ? '&' : '?') + 'back=' + encodeURIComponent(back);
          return;
        }
        goBtn.disabled = true;
        goBtn.innerHTML = '<span class="spin"></span> در حال اتصال به درگاه…';
        window.api(app.getAttribute('data-api'), {
          action: 'book', service: serviceId, slot: state.slot.id,
          mode: state.mode || 'inperson', note: noteInp ? noteInp.value : '',
          discount: state.discount || '',
          wallet: (function(){ var w = document.getElementById('bkWallet'); return !!(w && w.checked); })(),
          pay_mode: (function(){ var dp = document.getElementById('bkDep'); return (dp && dp.checked) ? 'deposit' : 'full'; })()
        }).then(function (d) {
          if (!d.ok) {
            window.toast && window.toast(d.message || 'رزرو ناموفق بود', 'err');
            goBtn.disabled = false; goBtn.textContent = 'تأیید و پرداخت';
            if (d.refresh) loadMonth(state.jy, state.jm);
            return;
          }
          if (d.payment_url) { window.location.href = d.payment_url; return; }
          /* رایگان → نمایش کارت موفقیت آماده + پنهان‌سازی ویزارد */
          var box = $('#bkSuccess');
          if (box) { box.style.display = 'block'; }
          app.style.display = 'none';
          window.scrollTo(0, 0);
        }).catch(function () {
          window.toast && window.toast('خطای ارتباط؛ دوباره تلاش کنید', 'err');
          goBtn.disabled = false; goBtn.textContent = 'تأیید و پرداخت';
        });
      });
    }

    /* ---------- کد تخفیف عمومی (۱٫۷) — بررسی زنده و اعمال در قیمت ---------- */
    state.discount = '';
    var priceEl = document.getElementById('bkPrice');
    var basePrice = priceEl ? priceEl.textContent : '';
    var discBtn = document.getElementById('bkDiscBtn');
    if (discBtn) {
      discBtn.addEventListener('click', function () {
        var inp = document.getElementById('bkDisc'), msg = document.getElementById('bkDiscMsg'),
            line = document.getElementById('bkDiscLine'), sumDisc = document.getElementById('sumDisc');
        var code = inp ? inp.value.trim() : '';
        if (!code) { if (msg) { msg.textContent = 'ابتدا کد را بنویسید.'; } return; }
        discBtn.disabled = true;
        window.api(app.getAttribute('data-api'), { action: 'check_discount', service: serviceId, code: code })
          .then(function (r) {
            discBtn.disabled = false;
            if (!r.ok) {
              state.discount = '';
              if (line) line.style.display = 'none';
              if (priceEl) priceEl.textContent = basePrice;
              if (msg) { msg.textContent = '⚠️ ' + (r.message || 'کد معتبر نیست.'); }
              if (window.toast) toast(r.message || 'کد معتبر نیست.', 'err');
              return;
            }
            state.discount = code;
            if (sumDisc) sumDisc.textContent = r.discount_f;
            if (line) line.style.display = 'flex';
            if (priceEl) priceEl.innerHTML = r.payable_f + (r.discount > 0
              ? ' <small style="text-decoration:line-through;opacity:.75;font-weight:400">' + basePrice + '</small>' : '');
            if (msg) { msg.textContent = '✅ ' + r.message; }
            if (window.toast) toast(r.message || 'کد اعمال شد 🎉');
          })
          .catch(function () { discBtn.disabled = false; if (window.toast) toast('خطای ارتباط؛ دوباره تلاش کنید', 'err'); });
      });
    }

    /* ---------- رزومه: بازگشت از صفحهٔ ورود با انتخاب قبلی ---------- */
    var rs = app.getAttribute('data-resume');
    if (rs) {
      try {
        var r = JSON.parse(rs);
        state.slot = { id: r.slot, t: r.time };
        state.day = r.jd; state.jy = r.jy; state.jm = r.jm; state.gday = r.gday;
        if (r.mode && modeBox) {
          state.mode = r.mode;
          $$('.bk-mode', modeBox).forEach(function (x) {
            x.classList.toggle('sel', x.getAttribute('data-mode') === r.mode);
          });
        }
        loadMonth(r.jy, r.jm);
        setStep(3); refreshSum();
        return;                                     // رزومه فعال شد؛ مسیر عادی لازم نیست
      } catch (e) { /* JSON نامعتبر → مسیر عادی */ }
    }

    /* شروع با ماه جاری شمسی */
    var jn = (app.getAttribute('data-jnow') || '').split('/');
    if (jn.length === 3) loadMonth(parseInt(jn[0], 10), parseInt(jn[1], 10));
    setStep(1); refreshSum();
  }

  /* ======================================================================
     رزومهٔ لغو نوبت (پنل کاربر) — دکمه‌های data-ap-cancel
     ====================================================================== */
  $$('[data-ap-cancel]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!window.confirm('آیا از لغو این نوبت مطمئنید؟')) return;
      btn.disabled = true;
      window.api(btn.getAttribute('data-api'), { action: 'cancel', appointment: btn.getAttribute('data-ap-cancel') })
        .then(function (d) {
          window.toast && window.toast(d.message || '', d.ok ? 'ok' : 'err');
          if (d.ok) setTimeout(function () { window.location.reload(); }, 900);
          else btn.disabled = false;
        })
        .catch(function () { btn.disabled = false; window.toast && window.toast('خطای ارتباط', 'err'); });
    });
  });
})();
