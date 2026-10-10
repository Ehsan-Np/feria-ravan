/* ==========================================================================
   فریا روان — لایهٔ جاوااسکریپت (سبک، بدون وابستگی، AJAX مبتنی بر fetch)
   ========================================================================== */
(function () {
  'use strict';

  if (window.__FERIA_JS_LOADED__) return;
  window.__FERIA_JS_LOADED__ = true;

  /* ---------- ابزارهای پایه ---------- */
  const $  = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

  /* اعداد فارسی */
  const FA = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  window.faNum = n => String(n).replace(/\d/g, d => FA[d]);

  /* ── سازگاری ۱: توکن CSRF را در صورت مسدودبودن اسکریپت inline (سیاست CSP در حالت تولید)
        از متا تگ بخوان — رفع باگ محیط production ── */
  if (!window.CSRF) {
    const meta = document.querySelector('meta[name="csrf"]');
    window.CSRF = meta ? meta.content : '';
  }

  /* ── سازگاری ۲: پلی‌فیل Canvas.roundRect برای مرورگرهای قدیمی‌تر (Safari<16, Chrome<99) ── */
  if (window.CanvasRenderingContext2D && !CanvasRenderingContext2D.prototype.roundRect) {
    CanvasRenderingContext2D.prototype.roundRect = function (x, y, w, h, r) {
      let radii = typeof r === 'number' ? [r, r, r, r] : (Array.isArray(r) ? r : [0, 0, 0, 0]);
      if (radii.length === 2) radii = [radii[0], radii[1], 0, 0];
      while (radii.length < 4) radii.push(0);
      const [tl, tr, br, bl] = radii.map(v => Math.min(Math.abs(v) || 0, w / 2, h / 2));
      this.moveTo(x + tl, y);
      this.arcTo(x + w, y, x + w, y + h, tr);
      this.arcTo(x + w, y + h, x, y + h, br);
      this.arcTo(x, y + h, x, y, bl);
      this.arcTo(x, y, x + w, y, tl);
      this.closePath();
      return this;
    };
  }

  /* ── سازگاری ۳: اگر مرورگر fetch/Promise ندارد (بسیار قدیمی)، AJAX با XMLHttpRequest ── */
  if (!window.fetch || !window.Promise) {
    window.fetch = function (url, opts) {
      return new Promise(function (resolve, reject) {
        try {
          const xhr = new XMLHttpRequest();
          xhr.open((opts && opts.method) || 'GET', url, true);
          const headers = (opts && opts.headers) || {};
          Object.keys(headers).forEach(k => xhr.setRequestHeader(k, headers[k]));
          xhr.onload  = () => resolve({ ok: xhr.status < 400, status: xhr.status, json: () => Promise.resolve(JSON.parse(xhr.responseText || '{}')), text: () => Promise.resolve(xhr.responseText) });
          xhr.onerror = () => reject(new Error('network'));
          xhr.send(opts && opts.body);
        } catch (e) { reject(e); }
      });
    };
  }

  /* فراخوانی AJAX استاندارد (همراه CSRF) */
  /* نسخهٔ ۲٫۰ — لایهٔ ارتباطی مقاوم:
     ─ هرگز Exception بیرون نمی‌دهد (پاسخ غیرJSON/خطای شبکه → پیام فارسی تمیز)
     ─ پاسخ 419 (نشست منقضی‌شده) → تازه‌سازی خودکارِ یک‌بارهٔ صفحه
     ─ رفع خطای ثبت‌نام در کروم دسکتاپ ۱۵۱ (bfcache + توکن کهنه) */
  window.api = async function (url, data) {
    let res, txt = '';
    try {
      res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': window.CSRF || '' },
        body: JSON.stringify(data || {})
      });
    } catch (netErr) {
      return { ok: false, message: 'خطای ارتباط با سرور؛ اتصال اینترنت را بررسی کرده و دوباره تلاش کنید.' };
    }
    try { txt = await res.text(); } catch (e) { txt = ''; }
    let j = null;
    try { j = JSON.parse(txt); } catch (e) { j = null; }
    if (res.status === 419 || (j && j.csrf_expired)) {
      const out = { ok: false, csrf_expired: true, message: (j && j.message) || 'نشست امنیتی منقضی شده است؛ صفحه به‌صورت خودکار تازه‌سازی می‌شود…' };
      try {
        if (!sessionStorage.getItem('fr_csrf_reload_once')) {
          sessionStorage.setItem('fr_csrf_reload_once', '1');
          if (window.toast) toast(out.message, 'warn');
          setTimeout(() => location.reload(), 900);
        }
      } catch (e2) { setTimeout(() => location.reload(), 900); }
      return out;
    }
    if (!j) return { ok: false, message: 'پاسخ نامعتبری از سرور دریافت شد؛ لطفاً چند لحظهٔ دیگر دوباره تلاش کنید.' };
    try { sessionStorage.removeItem('fr_csrf_reload_once'); } catch (e3) {}
    return j;
  };

  /* برگشت از حافظهٔ مرورگر (back/forward cache کروم/فایرفاکس/سافاری):
     توکن امنیتی کهنه است → یک بار تازه‌سازی کن تا فرم‌ها سالم بمانند */
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    try {
      var last = parseInt(sessionStorage.getItem('fr_bfcache_reload') || '0', 10);
      if (Date.now() - last < 8000) return;                 // جلوگیری از حلقهٔ تازه‌سازی
      sessionStorage.setItem('fr_bfcache_reload', String(Date.now()));
    } catch (e) { /* حافظهٔ نشست بسته است */ }
    location.reload();
  });

  /* نمایش پیام شناور */
  window.toast = function (msg, type) {
    let box = $('#fr-toasts');
    if (!box) {
      box = document.createElement('div');
      box.id = 'fr-toasts';
      box.style.cssText = 'position:fixed;bottom:20px;left:20px;z-index:999;display:flex;flex-direction:column;gap:10px';
      document.body.appendChild(box);
    }
    const t = document.createElement('div');
    t.style.cssText = 'padding:12px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 6px 20px rgba(0,0,0,.15);background:' +
      (type === 'err' ? '#e0393f' : type === 'warn' ? '#f5a623' : '#19a974');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3800);
  };

  /* ---------- منوی موبایل ---------- */
  function initMobileMenu() {
    const burger = $('.fr-hamburger'), sidebar = $('.fr-sidebar'), overlay = $('.fr-overlay');
    if (burger && sidebar) {
      const toggle = on => { sidebar.classList.toggle('open', on); overlay && overlay.classList.toggle('on', on); };
      burger.addEventListener('click', () => toggle(!sidebar.classList.contains('open')));
      overlay && overlay.addEventListener('click', () => toggle(false));
    }
  }

  /* ======================================================================
     نمودار میله‌ای/خطی Canvas سبک (برای پنل کاربری و گزارش‌ها)
     ====================================================================== */
  window.drawBarChart = function (canvas, data, opts) {
    if (!canvas || !canvas.getContext) return;
    opts = opts || {};
    const ctx = canvas.getContext('2d');
    const W = canvas.width = canvas.offsetWidth * 2;   /* رتینا */
    const H = canvas.height = (opts.height || 220) * 2;
    ctx.scale(2, 2);
    const w = W / 2, h = H / 2, pad = 30;
    const isDual = !!opts.dual;
    const max = Math.max(...data.map(d => Math.max(d.value || 0, d.value2 || 0)), 100);
    const rr = (x, y, bw, bh, r) => {          // roundRect با fallback قدیمی
      if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x, y, bw, bh, [r, r, 0, 0]); }
      else { ctx.beginPath(); ctx.rect(x, y, bw, bh); }
    };

    ctx.clearRect(0, 0, w, h);
    /* خطوط راهنما + درصدها */
    ctx.strokeStyle = '#efecff'; ctx.lineWidth = 1;
    ctx.fillStyle = '#b9b2d8'; ctx.font = '9px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
    for (let i = 0; i <= 4; i++) {
      const y = pad + (h - pad * 2) * i / 4;
      ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(w - pad, y); ctx.stroke();
      ctx.fillText(String(100 - i * 25) + '٪', 6, y + 3);
    }
    const slot = (w - pad * 2) / data.length;
    const faX = n => String(Math.round(n)).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);

    data.forEach((d, i) => {
      const col = d.color || '#5b3fd6';
      if (isDual) {
        /* دو میلهٔ کنارهم: اصلی (تطبیق‌یافته) + روشن (طبیعی) */
        const bwBar = slot * 0.26, gap = slot * 0.05, x0 = pad + slot * i + slot * 0.24;
        const bh1 = (h - pad * 2) * ((d.value || 0) / max), bh2 = (h - pad * 2) * ((d.value2 || 0) / max);
        let g = ctx.createLinearGradient(0, h - pad - bh1, 0, h - pad);
        g.addColorStop(0, col); g.addColorStop(1, col + 'cc');
        ctx.fillStyle = g; rr(x0, h - pad - bh1, bwBar, Math.max(2, bh1), 5); ctx.fill();
        ctx.fillStyle = col + '42'; rr(x0 + bwBar + gap, h - pad - bh2, bwBar, Math.max(2, bh2), 5); ctx.fill();
        /* مقدار روی هر میله */
        ctx.fillStyle = '#5b547f'; ctx.font = 'bold 10px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
        ctx.fillText(faX(d.value || 0), x0 + bwBar / 2, h - pad - bh1 - 6);
        ctx.fillText(faX(d.value2 || 0), x0 + bwBar + gap + bwBar / 2, h - pad - bh2 - 6);
        ctx.fillStyle = '#7b759c'; ctx.font = '11px Vazirmatn, Tahoma';
        ctx.fillText(d.label, x0 + bwBar + gap / 2, h - pad + 16);
      } else {
        const bh = (h - pad * 2) * (d.value / max);
        const x = pad + slot * i + slot * 0.22, y = h - pad - bh;
        const g = ctx.createLinearGradient(0, y, 0, h - pad);
        g.addColorStop(0, col); g.addColorStop(1, col);
        ctx.fillStyle = g;
        const bw2 = slot * 0.56;
        rr(x, y, bw2, bh, 5); ctx.fill();
        /* مقدار درصدی بالای میله */
        ctx.fillStyle = '#5b547f'; ctx.font = 'bold 10px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
        ctx.fillText(faX(d.value) + '٪', x + bw2 / 2, y - 6);
        ctx.fillStyle = '#7b759c'; ctx.font = '11px Vazirmatn, Tahoma';
        ctx.fillText(d.label, x + bw2 / 2, h - pad + 16);
      }
    });
  };

  /* نمودار میله‌ای افقی برای نتایج (افکت انیمیشنی) */
  window.animateScaleBars = function () {
    $$('.fr-scalebar .fill').forEach(el => {
      const w = el.dataset.w || '0%';
      requestAnimationFrame(() => setTimeout(() => { el.style.width = w; }, 120));
    });
  };

  /* ======================================================================
     ویرایشگر متن سبک داخلی (بدون وابستگی) — bold/italic/lists/h2/dir/color
     هر دکمه با data-cmd و محتوای contenteditable جفت می‌شود
     ====================================================================== */
  window.initRte = function () {
    $$('.rte-toolbar[data-rte]').forEach(bar => {
      const id = 'rte-' + bar.dataset.rte;
      const area = document.getElementById(id);
      if (!area) return;
      const btns = [
        ['bold', '<b>B</b>', 'پررنگ'], ['italic', '<i>I</i>', 'کج'],
        ['insertUnorderedList', '•≡', 'لیست'], ['insertOrderedList', '۱≡', 'لیست شماره‌دار'],
        ['formatBlock|<h2>', 'س', 'تیتر'], ['formatBlock|<p>', 'پ', 'پاراگراف'],
        ['justifyRight', '⫷', 'راست'], ['justifyCenter', '≡', 'وسط'],
        ['__image', '🖼', 'درج تصویر (آپلود)'],
        ['removeFormat', '⨯', 'حذف قالب'],
      ];
      btns.forEach(([cmd, label, title]) => {
        const b = document.createElement('button');
        b.type = 'button'; b.innerHTML = label; b.title = title;
        b.onmousedown = e => { /* جلوگیری از ازدست دادن فوکوس */
          e.preventDefault();
          if (cmd === '__image') {
            /* آپلود تصویر داخل ویرایشگر (۱٫۷) — فایلِ سرور خودمان، بدون هیچ سرویس بیرونی */
            const meta = document.querySelector('meta[name=app-url]');
            const base = meta ? meta.content : '';
            const inp = document.createElement('input');
            inp.type = 'file'; inp.accept = 'image/jpeg,image/png,image/webp';
            inp.onchange = () => {
              if (!inp.files || !inp.files[0]) return;
              const f = inp.files[0];
              if (f.size > 2 * 1024 * 1024) { toast('حجم تصویر باید حداکثر ۲ مگابایت باشد.','err'); return; }
              const fd = new FormData(); fd.append('file', f);
              toast('⏳ در حال آپلود تصویر…');
              fetch(base + '/api/rte-upload.php', { method:'POST', headers:{ 'X-CSRF': window.CSRF || '' }, body: fd })
                .then(r => r.json()).then(j => {
                  if (j.ok && j.url) {
                    area.focus();
                    try { document.execCommand('insertImage', false, j.url); }
                    catch (err) { area.innerHTML += '<img src="' + j.url + '" alt="">'; }
                    toast('تصویر درج شد 🖼');
                  } else toast(j.message || 'آپلود ناموفق بود.','err');
                }).catch(() => toast('خطای ارتباط در آپلود تصویر.','err'));
            };
            inp.click();
            return;
          }
          if (cmd.startsWith('formatBlock|')) document.execCommand('formatBlock', false, cmd.split('|')[1]);
          else document.execCommand(cmd, false, null);
          area.focus();
        };
        bar.appendChild(b);
      });
    });
  };

  function initPcCanvas() {
    $$('.pc-canvas').forEach(function (canvas) {
      var cid = canvas.getAttribute('data-pc-id') || 'default';
      var key = 'fr_pc_view_' + cid;
      var btns = $$('[data-pc-view][data-pc-for="' + cid + '"]');
      function apply(mode) {
        canvas.classList.toggle('as-list', mode === 'list');
        btns.forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-pc-view') === mode); });
        try { localStorage.setItem(key, mode); } catch (e) {}
      }
      var saved = null;
      try { saved = localStorage.getItem(key); } catch (e) {}
      apply(saved === 'list' ? 'list' : 'grid');
      btns.forEach(function (b) {
        b.addEventListener('click', function (e) { e.preventDefault(); apply(b.getAttribute('data-pc-view')); });
      });
    });
  }

  function onDomReady() {
    initMobileMenu();
    initRte();
    animateScaleBars();
    initPcCanvas();
    $$('canvas[data-chart]').forEach(cnv => {
      try { drawBarChart(cnv, JSON.parse(cnv.dataset.chart), JSON.parse(cnv.dataset.opts || '{}')); }
      catch (e) { /* نادیده */ }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onDomReady);
  } else {
    onDomReady();
  }

  window.addEventListener('resize', () => {
    $$('canvas[data-chart]').forEach(cnv => {
      try { drawBarChart(cnv, JSON.parse(cnv.dataset.chart), JSON.parse(cnv.dataset.opts || '{}')); } catch (e) {}
    });
  });

})();

/* ============================================================================
 | نسخهٔ ۲٫۴ — ساعت‌های ۲۴ ساعته (t24): ماسک خودکار HH:MM مستقل از لوکیل مرورگر
 | ورودی: <input data-t24> — نمایش نوبت‌ها: [data-t24-wrap][data-t24-count="#idSelect"]
 * ========================================================================== */
(function () {
  'use strict';
  var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
  function toEn(s) {
    return String(s == null ? '' : s).replace(/[۰-۹٠-٩]/g, function (ch) {
      var i = FA.indexOf(ch); if (i > -1) return String(i);
      i = AR.indexOf(ch); return i > -1 ? String(i) : ch;
    });
  }
  function initT24() {
    document.querySelectorAll('input[data-t24]').forEach(function (inp) {
      function clean(v, final) {
        v = toEn(v).replace(/[^0-9]/g, '').slice(0, 4);
        var h = v.slice(0, 2), m = v.slice(2);
        if (h.length === 2 && +h > 23) h = '23';
        if (m.length === 2 && +m > 59) m = '59';
        if (final && h.length === 1) h = '0' + h;
        if (final && m.length === 1) m = m + '0';
        return h + (v.length > 2 || (final && h && m) ? ':' + m : (v.length === 2 && !final ? ':' : ''));
      }
      inp.addEventListener('input', function () {
        var p = this.selectionStart;
        this.value = clean(this.value, false);
        try { this.setSelectionRange(this.value.length, this.value.length); } catch (e) {}
      });
      inp.addEventListener('blur', function () { this.value = clean(this.value, true); });
    });
    document.querySelectorAll('[data-t24-wrap]').forEach(function (wrap) {
      var sel = document.querySelector(wrap.getAttribute('data-t24-count'));
      if (!sel) return;
      var items = wrap.querySelectorAll('[data-t24-item]');
      function sync() {
        var n = Math.max(1, Math.min(items.length, parseInt(sel.value, 10) || 1));
        items.forEach(function (it, i) { it.style.display = i < n ? '' : 'none'; });
      }
      sel.addEventListener('change', sync); sync();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initT24);
  } else {
    initT24();
  }

  /* ── دکمهٔ خودکار و هوشمند بازگشت به بالای صفحه (Back to Top) ── */
  (function initBackToTop() {
    function setup() {
      if (document.getElementById('frBackToTop')) return;
      var btn = document.createElement('button');
      btn.id = 'frBackToTop';
      btn.className = 'fr-back-to-top';
      btn.setAttribute('type', 'button');
      btn.setAttribute('aria-label', 'بازگشت به بالای صفحه');
      btn.setAttribute('title', 'بازگشت به بالای صفحه');
      btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 15l-6-6-6 6"/></svg>';
      document.body.appendChild(btn);

      var timer = null;
      var checkScroll = function () {
        var top = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
        if (top > 240) {
          btn.classList.add('show');
        } else {
          btn.classList.remove('show');
        }
      };

      window.addEventListener('scroll', function () {
        if (!timer) {
          timer = setTimeout(function () {
            timer = null;
            checkScroll();
          }, 35);
        }
      }, { passive: true });

      checkScroll();

      btn.addEventListener('click', function (e) {
        e.preventDefault();
        window.scrollTo({
          top: 0,
          behavior: 'smooth'
        });
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', setup);
    } else {
      setup();
    }
  })();
})();
