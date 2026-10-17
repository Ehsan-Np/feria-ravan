<?php
/**
 * ============================================================================
 *  نسخهٔ چاپی گزارش تست روانشناسی — نسخهٔ ۲٫۰ (بازطراحی حرفه‌ای)
 *  ─ ابعاد دقیق استاندارد ISO A4 (۲۱۰×۲۹۷ میلی‌متر — @page{size:A4;margin:12mm})
 *  ─ قالب رسمی: سربرگ برند + راهنمای نمره‌ها + کارت تأییدیهٔ صدور
 *  ─ نمودار مخصوص هر تست روانشناسی: DISC = نمودار خطی (Line Chart سه‌سری) | بقیه = میله‌ای افقی
 *  ─ دکمهٔ «دانلود PDF» = چاپ مرورگر → Save as PDF — داخل PDF بارکد QR هست
 *  ─ بارکد QR با Google Chart API + کپشن «Feria.ir» + لینک دلخواه مدیر
 *    (تنظیمات ← گزارش: report_qr_link / report_qr_show) + جایگزین خودکار Code39
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../core/Analyzer.php';
fr_bootstrap();

$u = require_user();
$attemptId = (int)($_GET['attempt'] ?? 0);
$at = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE id=? AND user_id=?', [$attemptId, $u['id']]);
if (!$at || $at['status'] === 'in_progress') redirect(fr_panel_url());

$site   = fr_brand_title();
$slogan = fr_brand_slogan();
$logo   = fr_brand_logo();
$rep    = (string)($at['rep_code'] ?? ('FR-' . $at['id']));
$issued = strtotime((string)($at['completed_at'] ?: $at['started_at']));

/* ── بارکد QR: سرویس نمودار گوگل + لینک قابل‌تنظیم مدیر ── */
$qrLink = trim((string)DB::setting('report_qr_link', ''));
if ($qrLink === '') $qrLink = SITE_URL;
$qrShow = DB::setting('report_qr_show', '1') === '1';
$qrSrc  = 'https://chart.googleapis.com/chart?cht=qr&chl=' . rawurlencode($qrLink)
        . '&chs=150x150&choe=UTF-8&chld=L|1';

$docTitle = 'Feria-Report-' . preg_replace('/[^A-Za-z0-9\-]/', '', $rep);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title><?= e($docTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/feria.css?v=<?= FR_VERSION ?>">
<?= fr_brand_css() ?>
<style>
  /* ══ صفحهٔ نمایش (پیش‌نمایش ورق A4 روی مانیتور) ══ */
  body{background:#e9ecf5}
  .pr-bar{position:sticky;top:0;background:linear-gradient(135deg,#2b1650,#1e3a6e);color:#fff;
    padding:13px 22px;display:flex;gap:14px;align-items:center;justify-content:center;
    z-index:9;box-shadow:0 4px 18px rgba(40,20,90,.25);flex-wrap:wrap}
  .pr-bar .fr-btn{border-radius:12px}
  .pr-page{width:210mm;min-height:297mm;margin:10mm auto;background:#fff;box-sizing:border-box;
    padding:12mm;box-shadow:0 14px 46px rgba(30,20,80,.16);border-radius:4px;position:relative}
  /* ══ چاپ: دقیقاً ISO A4، کاغذ سفید بدون سایه ══ */
  @media print {
    @page { size: A4; margin: 12mm; }
    html,body{background:#fff!important}
    .pr-bar{display:none!important}
    .pr-page{width:100%!important;min-height:0;margin:0!important;padding:0!important;
      box-shadow:none!important;border-radius:0}
    .fr-card{box-shadow:none!important;border:1px solid #e8e4f6!important}
    .fr-report .fr-card h3{border-bottom:1px solid #efeafc;padding-bottom:6px}
    a{color:#1c2740!important;text-decoration:none!important}
    .pr-verify{break-inside:avoid}
  }
  /* ══ هدر رسمی (سرلوحه) ══ */
  .pr-head{display:flex;justify-content:space-between;align-items:center;gap:16px;
    background:linear-gradient(135deg,#2b1650 0%,#1e3a6e 100%);color:#fff;border-radius:16px;
    padding:16px 22px;position:relative;overflow:hidden}
  .pr-head::after{content:'';position:absolute;inset-inline-start:-40px;top:-60px;width:190px;height:190px;
    background:radial-gradient(circle,rgba(255,255,255,.16),transparent 65%)}
  .pr-head .pr-brand{display:flex;align-items:center;gap:12px}
  .pr-head img{width:46px;height:46px;border-radius:14px;background:#fff;padding:4px;object-fit:contain}
  .pr-head .pr-badge{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);
    border-radius:12px;padding:6px 14px;font-size:11.5px;text-align:center;line-height:1.8}
  /* ══ کارت تأییدیهٔ صدور (بارکد QR + کپشن Feria.ir) ══ */
  .pr-verify{display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap;
    border:1.5px solid #ddd5f5;border-radius:16px;padding:16px 20px;margin-top:18px;background:#fafbfd}
  .pr-verify .pr-qrbox{text-align:center}
  .pr-verify .pr-qrbox img{width:96px;height:96px;image-rendering:pixelated;border:6px solid #fff;
    border-radius:10px;box-shadow:0 4px 14px rgba(60,40,140,.15)}
  .pr-verify .pr-qrbox .pr-cap{font-size:12.5px;font-weight:800;color:#2b1650;margin-top:5px;letter-spacing:.4px}
  .pr-verify .pr-meta{font-size:12.5px;color:#4a4370;line-height:2.2}
  .pr-verify .pr-code{font-weight:800;color:#2b1650}
  .pr-canvas{display:block;width:100%}
  .pr-legend{display:flex;gap:16px;justify-content:center;font-size:11.5px;color:var(--muted);
    margin-top:8px;flex-wrap:wrap}
  .pr-legend i{display:inline-block;width:22px;height:3px;border-radius:2px;vertical-align:middle;margin-left:5px}
</style>
</head>
<body>

<!-- نوار ابزار (فقط روی مانیتور) -->
<div class="pr-bar">
  <span style="font-size:13px">📄 برای دریافت فایل، روی <b>«دانلود PDF»</b> بزنید و در پنجرهٔ چاپ، مقصد را <b>Save as PDF</b> انتخاب کنید — بارکد و کد پیگیری داخل سند قرار دارد.</span>
  <button class="fr-btn white sm" onclick="window.print()">📥 دانلود PDF</button>
  <a class="fr-btn ghost sm" style="color:#fff;border-color:rgba(255,255,255,.5)" href="report.php?attempt=<?= (int)$at['id'] ?>">بازگشت به گزارش</a>
</div>

<!-- ورق A4 -->
<div class="pr-page">

  <!-- سرلوحهٔ رسمی -->
  <div class="pr-head">
    <div class="pr-brand">
      <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e($site) ?>"><?php endif; ?>
      <div>
        <b style="font-size:17px"><?= e($site) ?></b>
        <?php if ($slogan !== ''): ?><div style="font-size:11px;opacity:.85;margin-top:2px"><?= e($slogan) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="pr-badge">
      📋 گزارش رسمی تست روانشناسی روان‌شناختی<br>
      <span style="opacity:.85">تاریخ صدور: <?= fa_num(jdate('j F Y', $issued)) ?></span>
    </div>
  </div>

  <!-- بدنهٔ گزارش (حالت چاپ: نمودار خطی/افقی مخصوص چاپ) -->
  <?= ReportBuilder::build($at, $u, $at['mode'], ['print' => true]) ?>

  <!-- کارت تأییدیهٔ صدور — بارکد QR + کپشن Feria.ir (۲٫۰) -->
  <?php if ($qrShow): ?>
  <div class="pr-verify" dir="rtl">
    <div class="pr-meta">
      <b style="font-size:14px;color:#2b1650">🔏 تأییدیهٔ صدور گزارش</b><br>
      کد پیگیری گزارش: <span class="pr-code" dir="ltr"><?= e($rep) ?></span><br>
      تاریخ صدور: <?= fa_num(jdate('l j F Y', $issued)) ?><br>
      <span style="font-size:11.5px;color:var(--muted)">برای بررسی صحت و ادعای این گزارش، بارکد را اسکن کنید.</span>
      <div id="frQrNote" style="display:none;font-size:10.5px;color:var(--muted)">بارکد جایگزین (حالت آفلاین)</div>
    </div>
    <div class="pr-qrbox">
      <img id="frQrImg" src="<?= e($qrSrc) ?>" alt="QR" loading="eager" decoding="async"
           data-fallback="1">
      <span id="frQrFallback" style="display:none"><?= barcode39_svg($rep, 52) ?></span>
      <div class="pr-cap"><?= e(fr_brand_name_en() ?: 'Feria.ir') ?></div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script src="<?= SITE_URL ?>/assets/js/feria.js?v=<?= FR_VERSION ?>"></script>
<script>
/* ══════════════════════════════════════════════════════════════════
   نمودارهای مخصوص چاپ (۲٫۰) — رسم روی Canvas بدون هیچ وابستگی
   line  → نمودار خطی چندسریی (مخصوص DISC)
   hbars → میله‌ای افقی با رنگ هر مقیاس و نام باند (سایر تست‌های روانشناسی)
   ══════════════════════════════════════════════════════════════════ */
(function () {
  function faN(n) { return String(Math.round(n)).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function prep(cnv) {
    var ctx = cnv.getContext('2d');
    var cssW = cnv.offsetWidth || 700, cssH = cnv.offsetHeight || 260;
    cnv.width = cssW * 2; cnv.height = cssH * 2; ctx.scale(2, 2);
    return { ctx: ctx, w: cssW, h: cssH };
  }
  var FONT = 'Vazirmatn, Tahoma, sans-serif';

  /* ── نمودار خطی چندسریی: DISC ── */
  function drawLine(cnv, cfg) {
    var p = prep(cnv), ctx = p.ctx, w = p.w, h = p.h;
    var padL = 14, padR = 40, padT = 30, padB = 34;
    var xs = cfg.labels.length, ix = function (i) { return padL + (w - padL - padR) * i / Math.max(1, xs - 1); };
    var iy = function (v) { return h - padB - (h - padT - padB) * Math.max(0, Math.min(100, v)) / 100; };
    /* خطوط شبکه + درصدها (سمت راست، فارسی) */
    ctx.strokeStyle = '#efecf8'; ctx.lineWidth = 1;
    ctx.fillStyle = '#a49ec7'; ctx.font = '10px ' + FONT; ctx.textAlign = 'left';
    for (var gi = 0; gi <= 5; gi++) {
      var y = iy(gi * 20);
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
      ctx.fillText(faN(gi * 20) + '٪', w - padR + 6, y + 3);
    }
    /* لیبل محور افقی */
    ctx.fillStyle = '#4a4370'; ctx.font = 'bold 11px ' + FONT; ctx.textAlign = 'center';
    cfg.labels.forEach(function (lb, i) { ctx.fillText(lb, ix(i), h - padB + 18); });
    /* هر سری: خط + نقطهٔ دولایه + مقدار روی نقطه */
    cfg.series.forEach(function (s) {
      ctx.strokeStyle = s.color; ctx.lineWidth = 2.4; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
      ctx.beginPath();
      s.values.forEach(function (v, i) { i ? ctx.lineTo(ix(i), iy(v)) : ctx.moveTo(ix(i), iy(v)); });
      ctx.stroke();
      s.values.forEach(function (v, i) {
        ctx.beginPath(); ctx.arc(ix(i), iy(v), 4.4, 0, 7); ctx.fillStyle = '#fff'; ctx.fill();
        ctx.beginPath(); ctx.arc(ix(i), iy(v), 3.2, 0, 7); ctx.fillStyle = s.color; ctx.fill();
        ctx.fillStyle = '#3a3360'; ctx.font = 'bold 9.5px ' + FONT; ctx.textAlign = 'center';
        ctx.fillText(faN(v) + '٪', ix(i), iy(v) - 9);
      });
    });
  }

  /* ── میله‌ای افقی: NEO / YSQ / screen / jobsat / mslikert ── */
  function drawHBars(cnv, cfg) {
    var bars = cfg.bars || [];
    /* ارتفاع بومی واکنش‌گرا: برای مقیاس‌های زیاد (YSQ ۱۸تایی) منعطف */
    var rowH = bars.length > 10 ? 21 : 26;
    var needH = bars.length * rowH + 22;
    if (cnv.offsetHeight < needH) cnv.style.height = needH + 'px';
    var p = prep(cnv), ctx = p.ctx, w = p.w, h = p.h;
    var labelW = 150, padR = 14, padL = 46;
    var x0 = labelW, x1 = w - padL - padR;
    bars.forEach(function (b, i) {
      var y = 10 + i * rowH, bh = rowH * 0.52;
      /* عنوان مقیاس (راست‌چین) */
      ctx.fillStyle = '#3a3360'; ctx.font = 'bold 10.5px ' + FONT; ctx.textAlign = 'right';
      ctx.fillText(b.label, labelW - 8, y + bh - 2);
      /* ریل زمینه */
      ctx.fillStyle = '#f2f0fa';
      if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x0, y, x1 - x0, bh, bh / 2); ctx.fill(); }
      else ctx.fillRect(x0, y, x1 - x0, bh);
      /* میلهٔ شاخص */
      var bw = Math.max(3, (x1 - x0) * Math.min(100, b.value) / 100);
      ctx.fillStyle = b.color;
      if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x0, y, bw, bh, bh / 2); ctx.fill(); }
      else ctx.fillRect(x0, y, bw, bh);
      /* مقدار + باند */
      ctx.fillStyle = '#5b547f'; ctx.font = 'bold 9.5px ' + FONT; ctx.textAlign = 'left';
      ctx.fillText(faN(b.value) + '٪' + (b.band ? ' — ' + b.band : ''), x0 + bw + 6, y + bh - 2);
    });
  }

  document.querySelectorAll('canvas[data-pr]').forEach(function (cnv) {
    try {
      var cfg = JSON.parse(cnv.getAttribute('data-pr'));
      if (cfg.type === 'line') drawLine(cnv, cfg); else drawHBars(cnv, cfg);
      /* راهنمای سری‌های نمودار خطی */
      var lg = cnv.parentNode.querySelector('[data-pr-legend]');
      if (lg && cfg.type === 'line') {
        lg.innerHTML = cfg.series.map(function (s) {
          return '<span><i style="background:' + s.color + '"></i>' + s.name + '</span>';
        }).join('');
      }
    } catch (e) { /* نادیده */ }
  });

  /* ── بارکد QR: اگر سرویس گوگل در دسترس نبود → بارکد داخلی Code39 ── */
  var qr = document.getElementById('frQrImg');
  if (qr) {
    var swap = function () {
      qr.style.display = 'none';
      var fb = document.getElementById('frQrFallback'), nt = document.getElementById('frQrNote');
      if (fb) fb.style.display = 'inline-block';
      if (nt) nt.style.display = 'block';
    };
    if (qr.complete && qr.naturalWidth === 0) swap();
    qr.addEventListener('error', swap);
  }

  /* ── چاپ خودکار با ?auto=1 + نام فایل سند از عنوان صفحه ── */
  if (new URLSearchParams(location.search).get('auto') === '1') {
    setTimeout(function () { window.print(); }, 650);
  }
})();
</script>
</body>
</html>
