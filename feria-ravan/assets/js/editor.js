/* ==========================================================================
   فریا روان — ویرایشگر WYSIWYG اختصاصی «FR-Editor» (نسخهٔ ۱٫۴)
   سبک، بدون وابستگی، RTL-محور، خروجی HTML تمیز برای پاک‌ساز سرور
   استفاده: <div class="fre" data-fre="hiddenInputId">...</div>
            یا FrEditor.attach(areaId, hiddenId)
   ========================================================================== */
(function () {
  'use strict';

  function $(s, c) { return (c || document).querySelector(s); }

  /* پالت رنگ‌های برند + خنثی — خروجی به‌صورت span style شده و سرور صحتش را می‌سنجد */
  var COLORS = ['#221c46', '#5b3fd6', '#0d9488', '#c2417c', '#b0262c', '#9a6200', '#19a974', '#7b759c'];

  /* ساخت نوارابزار برای یک ناحیهٔ ویرایش */
  function buildToolbar(area, hidden) {
    var bar = document.createElement('div');
    bar.className = 'fre-bar';
    bar.setAttribute('dir', 'rtl');

    /* تعریف دکمه‌ها: [دستور execCommand، عنوان فارسی، آیکن/HTML، مقدار] */
    var btns = [
      ['bold', 'پررنگ (Ctrl+B)', '<b>B</b>'],
      ['italic', 'کج (Ctrl+I)', '<i>I</i>'],
      ['underline', 'زیرخط (Ctrl+U)', '<u>U</u>'],
      ['strikeThrough', 'خط‌خورده', '<s>S</s>'],
      ['|'],
      ['formatBlock:h2', 'تیتر بزرگ', 'H2'],
      ['formatBlock:h3', 'تیتر', 'H3'],
      ['formatBlock:p', 'پاراگراف', '¶'],
      ['formatBlock:blockquote', 'نقل‌قول', '❝'],
      ['|'],
      ['insertUnorderedList', 'فهرست نقطه‌ای', '•≡'],
      ['insertOrderedList', 'فهرست شماره‌دار', '۱≡'],
      ['|'],
      ['justifyRight', 'راست‌چین', '⫷'],
      ['justifyCenter', 'وسط‌چین', '≣'],
      ['justifyLeft', 'چپ‌چین', '⫸'],
      ['|'],
      ['createLink', 'درج لینک', '🔗'],
      ['unlink', 'حذف لینک', '⛓'],
      ['insertImage', 'درج تصویر (آپلود)', '🖼'],
      ['removeFormat', 'پاک‌سازی قالب', '⨯'],
      ['|'],
      ['undo', 'برگشت (Ctrl+Z)', '↶'],
      ['redo', 'ازنو (Ctrl+Y)', '↷'],
    ];

    btns.forEach(function (def) {
      if (def[0] === '|') { var sp = document.createElement('span'); sp.className = 'fre-sep'; bar.appendChild(sp); return; }
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'fre-btn'; b.innerHTML = def[2]; b.title = def[1];
      b.setAttribute('aria-label', def[1]);
      b.addEventListener('mousedown', function (e) {
        e.preventDefault();                       // فوکوس ناحیه حفظ شود
        run(area, def[0], null, hidden);
        sync(area, hidden);
      });
      bar.appendChild(b);
    });

    /* پالت رنگ متن */
    var pal = document.createElement('span');
    pal.className = 'fre-pal';
    COLORS.forEach(function (c) {
      var s = document.createElement('button');
      s.type = 'button'; s.className = 'fre-color'; s.title = 'رنگ متن'; s.style.background = c;
      s.addEventListener('mousedown', function (e) { e.preventDefault(); run(area, 'foreColor', c, hidden); sync(area, hidden); });
      pal.appendChild(s);
    });
    bar.appendChild(pal);
    return bar;
  }

  /* ---- آپلود تصویر داخل ویرایشگر (۱٫۷) — API سرور خودمان، بدون هیچ سرویس بیرونی ---- */
  function pickImage(area, hidden, done) {
    var meta = document.querySelector('meta[name=app-url]');
    var base = meta ? meta.content : '';
    var csrfEl = document.querySelector('meta[name=csrf]');
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/jpeg,image/png,image/webp';
    inp.addEventListener('change', function () {
      if (!inp.files || !inp.files[0]) return;
      var f = inp.files[0];
      if (f.size > 2 * 1024 * 1024) { if (window.toast) toast('حجم تصویر باید حداکثر ۲ مگابایت باشد.', 'err'); return; }
      var fd = new FormData();
      fd.append('file', f);
      var btnState = function (t) { if (window.toast) toast(t); };
      btnState('⏳ در حال آپلود تصویر…');
      fetch(base + '/api/rte-upload.php', {
        method: 'POST',
        headers: { 'X-CSRF': (csrfEl && csrfEl.content) || window.CSRF || '' },
        body: fd,
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.ok && j.url) {
          area.focus();
          try {
            document.execCommand('insertImage', false, j.url);
          } catch (e) {
            area.innerHTML += '<img src="' + j.url + '" alt="">';
          }
          sync(area, hidden);
          if (done) done();
          if (window.toast) toast('تصویر درج شد 🖼');
        } else if (window.toast) toast(j.message || 'آپلود ناموفق بود.', 'err');
      }).catch(function () { if (window.toast) toast('خطای ارتباط در آپلود تصویر.', 'err'); });
    });
    inp.click();
  }

  /* اجرای دستور روی ناحیه */
  function run(area, cmd, val, hidden) {
    area.focus();
    try {
      if (cmd === 'createLink') {
        var url = window.prompt('نشانی لینک را وارد کنید:', 'https://');
        if (!url || url === 'https://') return;
        document.execCommand('createLink', false, url);
      } else if (cmd === 'insertImage') {
        pickImage(area, hidden);
        return;
      } else if (cmd.indexOf('formatBlock:') === 0) {
        document.execCommand('formatBlock', false, '<' + cmd.split(':')[1] + '>');
      } else if (cmd === 'insertOrderedList' || cmd === 'insertUnorderedList') {
        document.execCommand(cmd, false, null);
      } else {
        document.execCommand(cmd, false, val || null);
      }
    } catch (e) { /* برخی مرورگرها دستوری را پشتیبانی نمی‌کنند — بی‌صدا رد شو */ }
  }

  /* همگام‌سازی مقدار hidden با HTML ناحیه */
  function sync(area, hidden) { if (hidden) hidden.value = area.innerHTML; }

  /* اتصال ویرایشگر به یک contenteditable + hidden input */
  window.FrEditor = {
    attach: function (areaId, hiddenId) {
      var area = typeof areaId === 'string' ? document.getElementById(areaId) : areaId;
      var hidden = hiddenId ? (typeof hiddenId === 'string' ? document.getElementById(hiddenId) : hiddenId) : null;
      if (!area) return null;

      area.setAttribute('contenteditable', 'true');
      area.setAttribute('dir', 'rtl');
      area.classList.add('fre-area');

      var bar = buildToolbar(area, hidden);
      area.parentNode.insertBefore(bar, area);

      /* سبک CSSمحور برای خروجی تمیز (span style به‌جای <font>) */
      try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
      try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}

      /* چسباندن به‌صورت متن ساده — جلوگیری از ورود HTML کثیف ورد/وب */
      area.addEventListener('paste', function (e) {
        e.preventDefault();
        var txt = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
        var html = txt.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                      .replace(/\r?\n/g, '<br>');
        area.focus();
        try { document.execCommand('insertHTML', false, html); }
        catch (err) { area.innerHTML += html; }
        sync(area, hidden);
      });

      /* همگام‌سازی هنگام تایپ/تغییر و پیش از ارسال فرم */
      ['input', 'keyup', 'mouseup', 'blur'].forEach(function (ev) {
        area.addEventListener(ev, function () { sync(area, hidden); });
      });
      var form = area.closest ? area.closest('form') : null;
      if (form) form.addEventListener('submit', function () { sync(area, hidden); });

      sync(area, hidden);
      return { area: area, bar: bar };
    },
  };

  /* اتصال خودکار: هر <div class="fre" data-fre="hiddenId"> */
  document.addEventListener('DOMContentLoaded', function () {
    var nodes = document.querySelectorAll('.fre[data-fre]');
    Array.prototype.forEach.call(nodes, function (el) {
      window.FrEditor.attach(el, document.getElementById(el.getAttribute('data-fre')));
    });
  });
})();
