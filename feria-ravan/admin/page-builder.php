<?php
/**
 * ============================================================================
 *  محیط بصری و زندهٔ صفحه‌ساز دیداری فریا روان (Visual Drag & Drop PageBuilder)
 *  ─ معماری PHPageBuilder بر پایه GrapesJS
 *  ─ ویجت‌های اختصاصی: تست‌ها، دوره‌ها، کارگاه‌ها، مشاوره، خبرنامه، دیدگاه‌ها و...
 *  ─ پشتیبانی کامل از زبان فارسی (RTL)، فونت وزیرمتن و ذخیره‌سازی بلادرنگ AJAX
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/core/PageBuilder.php';

$adm = require_admin(['superadmin', 'manager']);
PageBuilder::ensureSchema();

$pageId = (int)($_GET['id'] ?? 0);
$page   = $pageId > 0 ? PageBuilder::getPage($pageId) : null;

if (!$page) {
    flash('برگه موردنظر یافت نشد.', 'err');
    redirect(SITE_URL . '/admin/pages.php');
}

$widgets = PageBuilder::getAvailableWidgets();
$isHome  = !empty($page['is_home']);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>صفحه‌ساز: <?= e($page['title']) ?> | فریا روان</title>
  <link rel="icon" type="image/svg+xml" href="<?= SITE_URL ?>/assets/img/favicon.svg">
  
  <!-- استایل‌های رسمی GrapesJS (فایل محلی با پشتیبانی آفلاین) -->
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/grapesjs/grapes.min.css">
  
  <style>
    @font-face {
      font-family: 'Vazirmatn';
      src: url('<?= SITE_URL ?>/assets/fonts/Vazirmatn.woff2') format('woff2');
      font-weight: 100 900;
      font-style: normal;
      font-display: swap;
    }

    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      height: 100%;
      overflow: hidden;
      font-family: 'Vazirmatn', system-ui, -apple-system, sans-serif;
      background: #0f172a;
      color: #f8fafc;
      direction: rtl;
    }

    /* نوار ابزار بالای صفحه‌ساز */
    #pb-topbar {
      height: 54px;
      background: #1e293b;
      border-bottom: 1px solid #334155;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 16px;
      z-index: 1000;
      position: relative;
    }

    .pb-brand-box {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .pb-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      font-size: 12.5px;
      font-weight: 700;
      border-radius: 8px;
      cursor: pointer;
      text-decoration: none;
      border: 1px solid transparent;
      transition: all 0.18s ease;
      font-family: inherit;
    }

    .pb-btn.primary {
      background: #3b82f6;
      color: #fff;
    }
    .pb-btn.primary:hover {
      background: #2563eb;
    }

    .pb-btn.ghost {
      background: rgba(255,255,255,0.08);
      color: #e2e8f0;
      border-color: rgba(255,255,255,0.12);
    }
    .pb-btn.ghost:hover {
      background: rgba(255,255,255,0.15);
      color: #fff;
    }

    .pb-device-group {
      display: flex;
      background: #0f172a;
      border: 1px solid #334155;
      border-radius: 8px;
      overflow: hidden;
    }

    .pb-device-btn {
      background: transparent;
      border: none;
      color: #94a3b8;
      padding: 6px 12px;
      cursor: pointer;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 4px;
      transition: all 0.15s;
    }
    .pb-device-btn.active, .pb-device-btn:hover {
      background: #334155;
      color: #fff;
    }

    /* ناحیه کار اصلی: سایدبار ابزارها + بوم طراحی */
    #pb-workspace {
      display: flex;
      height: calc(100% - 54px);
      width: 100%;
      position: relative;
    }

    /* سایدبار ابزارها و بلوک‌ها (در سمت راست) */
    #pb-sidebar {
      width: 320px;
      min-width: 320px;
      background: #1e293b;
      border-left: 1px solid #334155;
      display: flex;
      flex-direction: column;
      height: 100%;
      z-index: 100;
    }

    .pb-tab-headers {
      display: flex;
      background: #0f172a;
      border-bottom: 1px solid #334155;
    }

    .pb-tab-btn {
      flex: 1;
      padding: 10px 4px;
      background: transparent;
      border: none;
      color: #94a3b8;
      font-family: inherit;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      border-bottom: 2px solid transparent;
      transition: all 0.18s;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 3px;
    }
    .pb-tab-btn.active {
      color: #38bdf8;
      border-bottom-color: #38bdf8;
      background: rgba(56, 189, 248, 0.06);
    }

    .pb-tab-content {
      flex: 1;
      overflow-y: auto;
      padding: 14px;
      display: none;
    }
    .pb-tab-content.active {
      display: block;
    }

    /* بوم طراحی (GrapesJS Canvas) */
    #pb-canvas-wrap {
      flex: 1;
      height: 100%;
      position: relative;
      background: #090d16;
    }

    #gjs {
      height: 100%;
      width: 100%;
    }

    /* استایل‌های اختصاصی سفارشی‌سازی GrapesJS به تم تاریک و فارسی */
    .gjs-one-bg { background-color: #1e293b; }
    .gjs-two-color { color: #94a3b8; }
    .gjs-three-bg { background-color: #0f172a; color: #fff; }
    .gjs-four-color, .gjs-four-color-h:hover { color: #38bdf8; }

    .gjs-block {
      width: 100% !important;
      min-height: 52px !important;
      margin: 0 0 10px 0 !important;
      border-radius: 10px !important;
      background: #0f172a !important;
      border: 1px solid #334155 !important;
      color: #e2e8f0 !important;
      font-family: 'Vazirmatn', sans-serif !important;
      padding: 10px 14px !important;
      box-shadow: 0 2px 4px rgba(0,0,0,0.15) !important;
      display: flex !important;
      align-items: center !important;
      text-align: right !important;
      gap: 10px !important;
      transition: all 0.2s ease !important;
    }
    .gjs-block:hover {
      border-color: #38bdf8 !important;
      color: #38bdf8 !important;
      transform: translateY(-1px);
    }
    .gjs-block-category .gjs-title {
      font-family: 'Vazirmatn', sans-serif !important;
      font-size: 13px !important;
      font-weight: 800 !important;
      color: #38bdf8 !important;
      background: transparent !important;
      padding: 10px 4px !important;
      border-bottom: 1px solid #334155 !important;
      margin-bottom: 8px !important;
    }

    /* پیام Toast اطلاع‌رسانی */
    #pb-toast {
      position: fixed;
      bottom: 24px;
      left: 24px;
      background: #10b981;
      color: #fff;
      padding: 12px 22px;
      border-radius: 12px;
      font-size: 13.5px;
      font-weight: 700;
      box-shadow: 0 10px 25px rgba(0,0,0,0.3);
      z-index: 99999;
      display: none;
      align-items: center;
      gap: 8px;
    }
    #pb-toast.err { background: #ef4444; }
  </style>
</head>
<body>

  <!-- نوار ابزار بالای ویرایشگر -->
  <?php
    $pType = $page['page_type'] ?? 'page';
    $backTab = in_array($pType, ['header', 'footer'], true) ? '?tab=' . $pType : '';
    $typeLabel = ($pType === 'header') ? '🔝 سربرگ: ' : (($pType === 'footer') ? '🔻 پاورقی: ' : '📄 ');
  ?>
  <header id="pb-topbar">
    <div class="pb-brand-box">
      <a href="pages.php<?= $backTab ?>" class="pb-btn ghost" style="padding:5px 12px">
        ← بازگشت
      </a>
      <div style="font-size:14px;font-weight:700;color:#f8fafc">
        <?= $typeLabel . e($page['title']) ?>
        <?php if ($isHome): ?>
          <span style="background:#f59e0b;color:#0f172a;font-size:10px;font-weight:800;padding:2px 6px;border-radius:4px;margin-right:6px">⭐️ صفحه اصلی</span>
        <?php endif; ?>
        <?php if (!empty($page['is_default'])): ?>
          <span style="background:#10b981;color:#fff;font-size:10px;font-weight:800;padding:2px 6px;border-radius:4px;margin-right:6px">⭐️ قالب فعال سراسری</span>
        <?php endif; ?>
      </div>
      <?php if ($pType === 'page'): ?>
        <a href="<?= $isHome ? SITE_URL . '/' : SITE_URL . '/page.php?slug=' . urlencode((string)$page['slug']) ?>" target="_blank" class="pb-btn ghost" style="font-size:11px;padding:4px 8px" title="مشاهده پیش‌نمایش در تب جدید">
          👁️ نمایش زنده
        </a>
      <?php endif; ?>
    </div>

    <!-- سوئیچ نمایش دستگاه‌ها -->
    <div class="pb-device-group">
      <button class="pb-device-btn active" id="btnDevDesktop" title="نمای دسکتاپ" onclick="setDevice('Desktop')">
        💻 دسکتاپ
      </button>
      <button class="pb-device-btn" id="btnDevTablet" title="نمای تبلت" onclick="setDevice('Tablet')">
        📱 تبلت
      </button>
      <button class="pb-device-btn" id="btnDevMobile" title="نمای موبایل" onclick="setDevice('Mobile')">
        📲 موبایل
      </button>
    </div>

    <!-- دکمه‌های عملیاتی -->
    <div style="display:flex;align-items:center;gap:8px">
      <button class="pb-btn ghost" onclick="editor.UndoManager.undo()" title="واگرد (Ctrl+Z)">
        ↩
      </button>
      <button class="pb-btn ghost" onclick="editor.UndoManager.redo()" title="ازنو (Ctrl+Y)">
        ↪
      </button>
      <button class="pb-btn ghost" onclick="togglePreview()" id="btnPreview" title="حالت پیش‌نمایش">
        👁️ پیش‌نمایش
      </button>
      <button class="pb-btn ghost" onclick="openCodeModal()" title="مشاهده کد HTML و CSS">
        &lt;/&gt; کد
      </button>
      <button class="pb-btn primary" id="btnSavePage" onclick="savePageData()">
        💾 ذخیره تغییرات برگه
      </button>
    </div>
  </header>

  <!-- فضای کار صفحه‌ساز -->
  <main id="pb-workspace">
    <!-- بوم اصلی طراحی -->
    <section id="pb-canvas-wrap">
      <div id="gjs"></div>
    </section>

    <!-- سایدبار ابزارها و پنل‌های تنظیمات -->
    <aside id="pb-sidebar">
      <div class="pb-tab-headers">
        <button class="pb-tab-btn active" onclick="switchSidebarTab('blocks')">
          <span style="font-size:16px">🧩</span>
          <span>بلوک‌ها و ویجت‌ها</span>
        </button>
        <button class="pb-tab-btn" onclick="switchSidebarTab('styles')">
          <span style="font-size:16px">🎨</span>
          <span>استایل و رنگ</span>
        </button>
        <button class="pb-tab-btn" onclick="switchSidebarTab('layers')">
          <span style="font-size:16px">📑</span>
          <span>لایه‌ها</span>
        </button>
        <button class="pb-tab-btn" onclick="switchSidebarTab('traits')">
          <span style="font-size:16px">⚙️</span>
          <span>ویژگی‌ها</span>
        </button>
      </div>

      <!-- تب ۱: بلوک‌ها -->
      <div class="pb-tab-content active" id="tab-blocks">
        <div style="font-size:12px;color:#94a3b8;margin-bottom:12px;line-height:1.6">
          بلوک‌ها و ویجت‌های دلخواه خود را بکشید و در هر کجای صفحه رها کنید:
        </div>
        <div id="blocks-container"></div>
      </div>

      <!-- تب ۲: استایل‌ها -->
      <div class="pb-tab-content" id="tab-styles">
        <div id="styles-container"></div>
      </div>

      <!-- تب ۳: لایه‌ها -->
      <div class="pb-tab-content" id="tab-layers">
        <div id="layers-container"></div>
      </div>

      <!-- تب ۴: ویژگی‌ها -->
      <div class="pb-tab-content" id="tab-traits">
        <div id="traits-container"></div>
      </div>
    </aside>
  </main>

  <!-- مودال مشاهده و ویرایش کد منبع -->
  <div id="modalCode" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.7);z-index:99999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px)">
    <div style="width:100%;max-width:850px;background:#1e293b;border:1px solid #334155;border-radius:16px;padding:20px;display:flex;flex-direction:column;max-height:85vh">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #334155;padding-bottom:10px">
        <h3 style="margin:0;font-size:16px;color:#f8fafc">&lt;/&gt; کدهای خروجی برگه (HTML و CSS)</h3>
        <button onclick="document.getElementById('modalCode').style.display='none'" style="background:transparent;border:none;color:#94a3b8;font-size:22px;cursor:pointer">&times;</button>
      </div>
      <div style="margin-bottom:12px;flex:1;overflow:hidden;display:flex;flex-direction:column">
        <label style="font-size:12px;color:#94a3b8;margin-bottom:4px">کد HTML خروجی:</label>
        <textarea id="txtHtmlCode" style="flex:1;height:180px;background:#0f172a;color:#38bdf8;border:1px solid #334155;border-radius:8px;font-family:monospace;direction:ltr;padding:10px;font-size:12px" readonly></textarea>
      </div>
      <div style="margin-bottom:16px;flex:1;overflow:hidden;display:flex;flex-direction:column">
        <label style="font-size:12px;color:#94a3b8;margin-bottom:4px">کد CSS اختصاصی:</label>
        <textarea id="txtCssCode" style="flex:1;height:120px;background:#0f172a;color:#a78bfa;border:1px solid #334155;border-radius:8px;font-family:monospace;direction:ltr;padding:10px;font-size:12px" readonly></textarea>
      </div>
      <div style="display:flex;justify-content:flex-end">
        <button class="pb-btn ghost" onclick="document.getElementById('modalCode').style.display='none'">بستن</button>
      </div>
    </div>
  </div>

  <div id="pb-toast"></div>

  <!-- اسکریپت رسمی GrapesJS (فایل محلی با پشتیبانی آفلاین کامل) -->
  <script src="<?= SITE_URL ?>/assets/vendor/grapesjs/grapes.min.js"></script>

  <script>
  let editor;

  document.addEventListener('DOMContentLoaded', function() {
      // داده‌های اولیه برگه
      const initialHtml = <?= json_encode($page['content_html'] ?: '<div class="fr-page-container"><p style="text-align:center;padding:50px;color:#64748b;font-family:inherit">یک بلوک یا ویجت را از منوی سمت راست به این ناحیه بکشید...</p></div>', JSON_UNESCAPED_UNICODE) ?>;
      const initialCss  = <?= json_encode($page['content_css'] ?: '', JSON_UNESCAPED_UNICODE) ?>;
      const initialJson = <?= json_encode($page['builder_json'] ?: null, JSON_UNESCAPED_UNICODE) ?>;

      // ۱. راه‌اندازی GrapesJS بر اساس معماری PHPageBuilder
      editor = grapesjs.init({
          container: '#gjs',
          fromElement: false,
          height: '100%',
          width: 'auto',
          storageManager: false,
          showOffsets: true,
          noticeOnUnload: false,
          canvas: {
              styles: [
                  '<?= SITE_URL ?>/assets/css/site.css',
                  '<?= SITE_URL ?>/assets/css/feria.css'
              ]
          },
          deviceManager: {
              devices: [
                  { name: 'Desktop', width: '' },
                  { name: 'Tablet', width: '768px', widthMedia: '992px' },
                  { name: 'Mobile', width: '375px', widthMedia: '480px' },
              ]
          },
          blockManager: {
              appendTo: '#blocks-container',
          },
          styleManager: {
              appendTo: '#styles-container',
              sectors: [
                  {
                      name: 'ابعاد و فواصل (Dimension)',
                      open: false,
                      buildProps: ['width', 'min-width', 'max-width', 'height', 'padding', 'margin'],
                  },
                  {
                      name: 'تایپوگرافی و فونت (Typography)',
                      open: false,
                      buildProps: ['font-family', 'font-size', 'font-weight', 'letter-spacing', 'color', 'line-height', 'text-align'],
                  },
                  {
                      name: 'تزیینات و حاشیه (Decorations)',
                      open: false,
                      buildProps: ['background-color', 'background-image', 'border-radius', 'border', 'box-shadow', 'opacity'],
                  },
                  {
                      name: 'چیدمان پیشرفته (Flexbox)',
                      open: false,
                      buildProps: ['display', 'flex-direction', 'justify-content', 'align-items', 'gap'],
                  }
              ]
          },
          layerManager: {
              appendTo: '#layers-container',
          },
          traitManager: {
              appendTo: '#traits-container',
          },
          assetManager: {
              upload: '<?= SITE_URL ?>/api/page-builder.php?act=upload_asset',
              uploadName: 'files',
              headers: {
                  'X-Requested-With': 'XMLHttpRequest'
              },
              params: {
                  'csrf_token': '<?= csrf_token() ?>'
              }
          }
      });

      // ۲. تزریق فونت وزیرمتن و تنظیمات RTL به فریم بوم
      editor.on('load', function() {
          const doc = editor.Canvas.getDocument();
          if (doc) {
              const head = doc.head;
              const linkFont = doc.createElement('style');
              linkFont.innerHTML = `
                @font-face {
                  font-family: 'Vazirmatn';
                  src: url('<?= SITE_URL ?>/assets/fonts/Vazirmatn.woff2') format('woff2');
                  font-weight: 100 900;
                  font-style: normal;
                }
                body, html {
                  direction: rtl;
                  text-align: right;
                  font-family: 'Vazirmatn', system-ui, sans-serif !important;
                }
              `;
              head.appendChild(linkFont);
          }
      });

      // ۳. ثبت ویجت‌های اختصاصی فریا روان در BlockManager
      const bm = editor.BlockManager;

      // الف) ثبت ویجت‌های تخصصی سامانه
      <?php foreach ($widgets as $w): ?>
        bm.add(<?= json_encode($w['id']) ?>, {
            label: <?= json_encode('<span>' . $w['icon'] . '</span> <span>' . $w['title'] . '</span>') ?>,
            category: <?= json_encode($w['category']) ?>,
            content: <?= json_encode($w['content']) ?>,
            attributes: { class: 'fr-gjs-widget-block', title: <?= json_encode($w['description']) ?> }
        });
      <?php endforeach; ?>

      // ب) ثبت بلوک‌های پایه چیدمان و ساختار (Layout)
      bm.add('section-box', {
          label: '<span>🔳</span> <span>بخش محتوا (Section)</span>',
          category: 'ساختار و چیدمان',
          content: '<section style="padding:40px 16px;margin:10px auto;max-width:1200px"><h2 style="font-size:22px;font-weight:700;margin-bottom:12px">عنوان بخش</h2><p style="color:#64748b;line-height:1.8">متن توضیحات این بخش را می‌توانید در این قسمت ویرایش کنید.</p></section>',
      });

      bm.add('grid-2-cols', {
          label: '<span>🔲</span> <span>دو ستونه (۵۰/۵۰)</span>',
          category: 'ساختار و چیدمان',
          content: '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;padding:16px 0"><div style="padding:20px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0"><p>ستون راست</p></div><div style="padding:20px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0"><p>ستون چپ</p></div></div>',
      });

      bm.add('grid-3-cols', {
          label: '<span>🔲</span> <span>سه ستونه (۳۳/۳۳/۳۳)</span>',
          category: 'ساختار و چیدمان',
          content: '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));gap:20px;padding:16px 0"><div style="padding:20px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0"><p>ستون اول</p></div><div style="padding:20px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0"><p>ستون دوم</p></div><div style="padding:20px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0"><p>ستون سوم</p></div></div>',
      });

      bm.add('divider-line', {
          label: '<span>➖</span> <span>خط جداکننده (Divider)</span>',
          category: 'ساختار و چیدمان',
          content: '<hr style="border:none;border-top:1px solid #e2e8f0;margin:30px 0" />',
      });

      // ج) اجزای متنی و پایه
      bm.add('heading-h1', {
          label: '<span>🔤</span> <span>تیتر درشت (H1)</span>',
          category: 'متن و المان‌های پایه',
          content: '<h1 style="font-size:28px;font-weight:900;color:#0f172a;line-height:1.5">تیتر بزرگ اصلی را اینجا بنویسید</h1>',
      });

      bm.add('heading-h2', {
          label: '<span>🔤</span> <span>سربرگ بخش (H2)</span>',
          category: 'متن و المان‌های پایه',
          content: '<h2 style="font-size:22px;font-weight:800;color:#1e293b;line-height:1.5">عنوان فرعی بخش</h2>',
      });

      bm.add('paragraph-text', {
          label: '<span>📝</span> <span>پاراگراف متن</span>',
          category: 'متن و المان‌های پایه',
          content: '<p style="font-size:14px;color:#475569;line-height:1.8;margin:0 0 12px">متن توضیحی، مقاله یا پیام بالینی خود را در این قسمت درج نمایید. با دو بار کلیک روی هر متن، می‌توانید آن را به سادگی بازنویسی کنید.</p>',
      });

      bm.add('cta-button', {
          label: '<span>🔘</span> <span>دکمه فراخوان (Button)</span>',
          category: 'متن و المان‌های پایه',
          content: '<a href="#" style="display:inline-block;padding:10px 24px;background:#3b82f6;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;font-size:13.5px">کلیک کنید ←</a>',
      });

      bm.add('img-box', {
          label: '<span>🖼️</span> <span>کادر تصویر</span>',
          category: 'متن و المان‌های پایه',
          content: '<div style="text-align:center;margin:16px 0"><img src="<?= SITE_URL ?>/assets/img/consultant-1.jpg" alt="تصویر" style="max-width:100%;height:auto;border-radius:14px;box-shadow:0 4px 14px rgba(0,0,0,0.06)" /></div>',
      });

      // ۴. بارگذاری محتوای برگه
      if (initialJson) {
          try {
              editor.loadProjectData(JSON.parse(initialJson));
          } catch (e) {
              editor.setComponents(initialHtml);
              editor.setStyle(initialCss);
          }
      } else {
          editor.setComponents(initialHtml);
          editor.setStyle(initialCss);
      }
  });

  // کنترل تغییر نماهای دستگاه
  function setDevice(device) {
      document.querySelectorAll('.pb-device-btn').forEach(b => b.classList.remove('active'));
      if (device === 'Desktop') document.getElementById('btnDevDesktop').classList.add('active');
      if (device === 'Tablet') document.getElementById('btnDevTablet').classList.add('active');
      if (device === 'Mobile') document.getElementById('btnDevMobile').classList.add('active');
      editor.setDevice(device);
  }

  // سوئیچ تب‌های سایدبار
  function switchSidebarTab(tab) {
      document.querySelectorAll('.pb-tab-btn').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.pb-tab-content').forEach(c => c.classList.remove('active'));

      event.currentTarget.classList.add('active');
      document.getElementById('tab-' + tab).classList.add('active');
  }

  // تغییر حالت پیش‌نمایش
  function togglePreview() {
      const isPrev = editor.Commands.isActive('preview');
      if (isPrev) {
          editor.stopCommand('preview');
          document.getElementById('btnPreview').classList.remove('active');
      } else {
          editor.runCommand('preview');
          document.getElementById('btnPreview').classList.add('active');
      }
  }

  // نمایش مودال کدهای HTML/CSS
  function openCodeModal() {
      document.getElementById('txtHtmlCode').value = editor.getHtml();
      document.getElementById('txtCssCode').value  = editor.getCss();
      document.getElementById('modalCode').style.display = 'flex';
  }

  // ذخیره برگه با فراخوانی AJAX
  function savePageData() {
      const btn = document.getElementById('btnSavePage');
      btn.disabled = true;
      btn.innerText = 'در حال ذخیره‌سازی...';

      const html = editor.getHtml();
      const css  = editor.getCss();
      const json = JSON.stringify(editor.getProjectData());

      const body = new URLSearchParams();
      body.append('act', 'save');
      body.append('page_id', '<?= (int)$page['id'] ?>');
      body.append('csrf_token', '<?= csrf_token() ?>');
      body.append('content_html', html);
      body.append('content_css', css);
      body.append('builder_json', json);

      fetch('<?= SITE_URL ?>/api/page-builder.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
      })
      .then(r => r.json())
      .then(data => {
          btn.disabled = false;
          btn.innerText = '💾 ذخیره تغییرات برگه';
          if (data.ok) {
              showToast(data.message || 'برگه با موفقیت ذخیره شد ✅', false);
          } else {
              showToast(data.error || 'خطا در ذخیره برگه.', true);
          }
      })
      .catch(err => {
          btn.disabled = false;
          btn.innerText = '💾 ذخیره تغییرات برگه';
          showToast('خطای ارتباط با سرور: ' + err.message, true);
      });
  }

  function showToast(msg, isErr) {
      const t = document.getElementById('pb-toast');
      t.innerText = msg;
      t.className = isErr ? 'err' : '';
      t.style.display = 'flex';
      setTimeout(() => { t.style.display = 'none'; }, 4000);
  }
  </script>
</body>
</html>
