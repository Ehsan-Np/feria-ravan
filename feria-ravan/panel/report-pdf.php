<?php
/**
 * ============================================================================
 *  کارنامه رسمی ارزیابی روان‌شناختی و دریافت فایل استاندارد ISO A4 — نسخهٔ ۴٫۵٫۰
 *  ─ ابعاد دقیق استاندارد بین‌المللی ISO A4 (۲۱۰ × ۲۹۷ میلی‌متر — @page{size:A4})
 *  ─ کنترل دسترسی دوگانه (مدیر سایت برای تمام مراجعین / کاربر عادی برای آزمون‌های خود)
 *  ─ حفظ محرمانگی بالینی: راهنمای مداخله و پروتکل شواهدمحور منحصراً برای مدیران
 *  ─ ادغام اطلاعات ارائه‌دهنده، کلینیک، روانشناس ناظر، شماره نظام، لوگو و مهر دیجیتال
 *  ─ بارکد QR اعتبارسنجی اصالت، کد رهگیری یکتا و کادر امضا و تأییدیه روانشناس
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../core/Analyzer.php';
fr_bootstrap();

$adm = function_exists('current_admin') ? current_admin() : null;
$u   = function_exists('current_user') ? current_user() : null;

$attemptId = (int)($_GET['attempt'] ?? ($_GET['id'] ?? 0));
if ($attemptId <= 0) {
    if ($adm) redirect(SITE_URL . '/admin/attempts.php');
    redirect(fr_panel_url('history.php'));
}

$isAdmin = ($adm !== null);
$at = null;
$targetUser = null;

if ($isAdmin) {
    // مدیر به گزارش کلیه مراجعین دسترسی دارد
    $at = DB::one('SELECT a.*, t.title as test_title, t.slug as test_slug, t.engine as test_engine, t.audience as test_audience,
                          u.first_name, u.last_name, u.mobile, u.national_code, u.birth_date, u.gender
                   FROM ' . DB::t('attempts') . ' a
                   LEFT JOIN ' . DB::t('tests') . ' t ON t.id = a.test_id
                   LEFT JOIN ' . DB::t('users') . ' u ON u.id = a.user_id
                   WHERE a.id = ?', [$attemptId]);
    if ($at && !empty($at['user_id'])) {
        $targetUser = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id = ?', [$at['user_id']]);
    }
} elseif ($u) {
    // کاربر عادی صرفاً به آزمون‌های خود دسترسی دارد
    $at = DB::one('SELECT a.*, t.title as test_title, t.slug as test_slug, t.engine as test_engine, t.audience as test_audience,
                          u.first_name, u.last_name, u.mobile, u.national_code, u.birth_date, u.gender
                   FROM ' . DB::t('attempts') . ' a
                   LEFT JOIN ' . DB::t('tests') . ' t ON t.id = a.test_id
                   LEFT JOIN ' . DB::t('users') . ' u ON u.id = a.user_id
                   WHERE a.id = ? AND a.user_id = ?', [$attemptId, $u['id']]);
    $targetUser = $u;
} else {
    redirect(SITE_URL . '/auth.php?back=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
}

if ($at && $at['status'] === 'in_progress') {
    // در صورت وجود پاسخ، جهت جلوگیری از مسدود شدن دسترسی مراجع یا مدیر، کارنامه خودکار نمره‌گذاری می‌شود
    $hasAns = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('answers') . ' WHERE attempt_id = ?', [$attemptId]);
    if ($hasAns > 0) {
        try {
            Analyzer::scoreAttempt($attemptId);
            $at = DB::one('SELECT a.*, t.title as test_title, t.slug as test_slug, t.engine as test_engine, t.audience as test_audience,
                                  u.first_name, u.last_name, u.mobile, u.national_code, u.birth_date, u.gender
                           FROM ' . DB::t('attempts') . ' a
                           LEFT JOIN ' . DB::t('tests') . ' t ON t.id = a.test_id
                           LEFT JOIN ' . DB::t('users') . ' u ON u.id = a.user_id
                           WHERE a.id = ?', [$attemptId]);
        } catch (\Throwable $se) {
            error_log('report-pdf auto-score error: ' . $se->getMessage());
        }
    }
}

if (!$at || $at['status'] === 'in_progress') {
    if ($isAdmin) {
        flash('کارنامه آزمون موردنظر یافت نشد یا آزمون هنوز به اتمام نرسیده است.', 'warn');
        redirect(SITE_URL . '/admin/attempts.php');
    }
    flash('کارنامه آزمون یافت نشد یا آزمون هنوز به اتمام نرسیده است.', 'warn');
    redirect(fr_panel_url('history.php'));
}

if (!$targetUser) {
    $targetUser = [
        'id'            => (int)($at['user_id'] ?? 0),
        'first_name'    => $at['first_name'] ?? '',
        'last_name'     => $at['last_name'] ?? '',
        'mobile'        => $at['mobile'] ?? '',
        'national_code' => $at['national_code'] ?? '',
        'gender'        => $at['gender'] ?? '',
        'birth_date'    => $at['birth_date'] ?? '',
    ];
}

// اطلاعات هویتی مراجع
$clientName = trim(($targetUser['first_name'] ?? $at['first_name'] ?? '') . ' ' . ($targetUser['last_name'] ?? $at['last_name'] ?? '')) ?: 'مراجع گرامی';
$clientMobile = (string)($targetUser['mobile'] ?? $at['mobile'] ?? '');
$clientNationalCode = (string)($targetUser['national_code'] ?? $at['national_code'] ?? '');
$clientGender = (string)($targetUser['gender'] ?? $at['gender'] ?? '');
$clientGenderFa = match($clientGender) { 'female' => 'زن', 'male' => 'مرد', default => 'نامشخص' };
$clientBirthDate = (string)($targetUser['birth_date'] ?? $at['birth_date'] ?? '');
$clientAge = '';
if ($clientBirthDate !== '' && preg_match('/^(\d{4})/', $clientBirthDate, $bm)) {
    $by = (int)$bm[1];
    $cy = (int)jdate('Y');
    if ($by > 1300 && $by <= $cy) {
        $clientAge = (string)($cy - $by) . ' سال';
    }
}

// اطلاعات ارائه‌دهنده و روانشناس ناظر از تنظیمات مدیریت
$providerName     = fr_report_provider_name();
$providerSubtitle = fr_report_provider_subtitle();
$providerLicense  = fr_report_provider_license();
$doctorName       = fr_report_doctor_name();
$doctorTitle      = fr_report_doctor_title();
$doctorLicense    = fr_report_doctor_license();
$providerLogo     = fr_image_to_data_uri(fr_report_provider_logo());
$providerStamp    = fr_image_to_data_uri(fr_report_provider_stamp());
$showSignature    = fr_report_show_signature_box();
$providerPhone    = fr_report_provider_phone();
$providerAddress  = fr_report_provider_address();
$providerWebsite  = fr_report_provider_website();
$headerColor      = fr_report_header_color();
$disclaimer       = fr_report_disclaimer();
$showQr           = fr_report_show_qr();

$repCode = (string)($at['rep_code'] ?? ('FR-' . $at['id']));
$completedTs = strtotime((string)($at['completed_at'] ?: ($at['started_at'] ?? ''))) ?: time();
$completedFa = fa_num(jdate('l j F Y — ساعت H:i', $completedTs));
$issueDateFa = fa_num(jdate('j F Y', $completedTs));

// لینک استعلام اصالت کارنامه و تولید بارکد QR وکتور محلی خالص (بدون وابستگی خارجی)
$customQrLink = trim((string)DB::setting('report_qr_link', ''));
$verifyUrl = $customQrLink !== '' ? $customQrLink : (SITE_URL . '/panel/report.php?attempt=' . (int)$at['id']);
$qrSvg = fr_qr_svg($verifyUrl, 115);

$docTitle = 'کارنامه-A4-' . preg_replace('/[^A-Za-z0-9\-]/', '', $repCode);
if ((isset($_GET['offline_html']) && $_GET['offline_html'] === '1') || (isset($_GET['download_file']) && $_GET['download_file'] === '1')) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $docTitle . '.html"');
    }
}
$backUrl = $isAdmin ? (SITE_URL . '/admin/attempts.php?report=' . (int)$at['id']) : fr_panel_url('report.php?attempt=' . (int)$at['id']);
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
  /* ══ متغیرها و چیدمان استاندارد ISO A4 ══ */
  :root {
    --pr-color: <?= e($headerColor) ?>;
    --pr-accent: #7c3aed;
    --pr-border: #e2e8f0;
    --pr-text: #0f172a;
    --pr-muted: #64748b;
  }
  * { box-sizing: border-box; }
  body {
    background: #e2e8f0;
    margin: 0;
    padding: 0;
    font-family: Vazirmatn, Shabnam, Tahoma, system-ui, sans-serif;
    color: var(--pr-text);
    -webkit-font-smoothing: antialiased;
  }

  /* نوار کنترل بالای صفحه برای دانلود PDF و چاپ (فقط روی مانیتور) */
  .pr-toolbar {
    position: sticky;
    top: 0;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    padding: 12px 24px;
    display: flex;
    gap: 14px;
    align-items: center;
    justify-content: space-between;
    z-index: 999;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.35);
    flex-wrap: wrap;
  }
  .pr-toolbar-info {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
  }
  .pr-toolbar-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .pr-btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all .2s;
    border: none;
  }
  .pr-btn-download {
    background: #10b981;
    color: #fff;
  }
  .pr-btn-download:hover { background: #059669; }
  .pr-btn-print {
    background: #3b82f6;
    color: #fff;
  }
  .pr-btn-print:hover { background: #2563eb; }
  .pr-btn-back {
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
  }
  .pr-btn-back:hover { background: rgba(255, 255, 255, 0.25); }

  /* ══ برگه استاندارد ISO A4 (210mm × 297mm) ══ */
  .pr-sheet {
    width: 210mm;
    min-height: 297mm;
    margin: 20mm auto;
    background: #fff;
    padding: 12mm 14mm;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.12);
    border-radius: 4px;
    position: relative;
    box-sizing: border-box;
  }

  /* ══ سربرگ رسمی کارنامه ══ */
  .pr-header {
    border-bottom: 2.5px solid var(--pr-color);
    padding-bottom: 12px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
  }
  .pr-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
  }
  .pr-logo {
    width: 58px;
    height: 58px;
    object-fit: contain;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    padding: 4px;
    background: #fff;
  }
  .pr-provider-title {
    font-size: 17px;
    font-weight: 800;
    color: var(--pr-color);
    margin: 0 0 3px 0;
  }
  .pr-provider-sub {
    font-size: 11.5px;
    color: var(--pr-muted);
    line-height: 1.6;
    margin: 0;
  }
  .pr-header-left {
    text-align: left;
    font-size: 11px;
    line-height: 1.9;
    color: #334155;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 8px 14px;
    min-width: 170px;
  }
  .pr-header-left b {
    color: var(--pr-color);
  }

  /* ══ شناسنامه مشخصات مراجع و اطلاعات آزمون ══ */
  .pr-demobox {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 18px;
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .pr-demogrid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px 14px;
    font-size: 11.5px;
    line-height: 1.8;
  }
  .pr-demogrid .item {
    display: flex;
    flex-direction: column;
  }
  .pr-demogrid .label {
    color: var(--pr-muted);
    font-size: 10.5px;
  }
  .pr-demogrid .val {
    font-weight: 700;
    color: #1e293b;
  }

  /* ══ جدول کمی نمرات و مقیاس‌ها ══ */
  .pr-table {
    width: 100%;
    border-collapse: collapse;
    margin: 14px 0 20px 0;
    font-size: 11.5px;
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .pr-table th {
    background: #f1f5f9;
    color: #334155;
    padding: 8px 10px;
    border: 1px solid #cbd5e1;
    font-weight: 700;
    text-align: center;
  }
  .pr-table td {
    padding: 7px 10px;
    border: 1px solid #e2e8f0;
    text-align: center;
    color: #1e293b;
  }
  .pr-table tr:nth-child(even) { background: #fafafa; }
  .pr-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10.5px;
    font-weight: 700;
  }

  /* ══ کادر تأییدیه، امضا، مهر و بارکد اصالت ══ */
  .pr-attestation {
    margin-top: 24px;
    padding-top: 14px;
    border-top: 2px dashed #cbd5e1;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .pr-qr-section {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 14px;
  }
  .pr-qr-img {
    width: 86px;
    height: 86px;
    border: 2px solid #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
  }
  .pr-signature-section {
    min-width: 260px;
    text-align: center;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 16px;
    background: #fafbfd;
  }
  .pr-stamp-img {
    max-height: 64px;
    max-width: 150px;
    object-fit: contain;
    margin: 4px auto;
    display: block;
  }

  /* ══ پاورقی قانونی و سلب مسئولیت ══ */
  .pr-disclaimer {
    margin-top: 14px;
    padding: 8px 12px;
    background: #f8fafc;
    border-radius: 8px;
    font-size: 10px;
    line-height: 1.8;
    color: #64748b;
    text-align: justify;
    break-inside: avoid;
    page-break-inside: avoid;
  }
  .pr-footer-meta {
    margin-top: 10px;
    display: flex;
    justify-content: space-between;
    font-size: 9.5px;
    color: #94a3b8;
    border-top: 1px solid #f1f5f9;
    padding-top: 6px;
  }

  /* ══ سازگاری با پرینت و ساخت PDF استاندارد A4 ══ */
  @media print {
    @page {
      size: A4 portrait;
      margin: 10mm 12mm 12mm 12mm;
    }
    html, body {
      background: #fff !important;
      color: #000 !important;
      font-size: 10.5pt !important;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    .pr-toolbar { display: none !important; }
    .pr-sheet {
      width: 100% !important;
      min-height: 0 !important;
      margin: 0 !important;
      padding: 0 !important;
      box-shadow: none !important;
      border: none !important;
    }
    .fr-card {
      box-shadow: none !important;
      border: 1px solid #e2e8f0 !important;
      margin-bottom: 14px !important;
      break-inside: avoid !important;
      page-break-inside: avoid !important;
    }
    .avoid-break, .pr-demobox, .pr-attestation, .pr-disclaimer, .pr-table {
      break-inside: avoid !important;
      page-break-inside: avoid !important;
    }
    a { color: inherit !important; text-decoration: none !important; }
  }

  .pr-canvas { display: block; width: 100%; }
  .pr-legend { display: flex; gap: 16px; justify-content: center; font-size: 11.5px; color: var(--pr-muted); margin-top: 8px; flex-wrap: wrap; }
  .pr-legend i { display: inline-block; width: 22px; height: 3px; border-radius: 2px; vertical-align: middle; margin-left: 5px; }
</style>
</head>
<body>

  <!-- نوار ابزار دریافت و ذخیره فایل PDF A4 -->
<div class="pr-toolbar">
  <div class="pr-toolbar-info">
    <span style="font-size:20px">📄</span>
    <div>
      <b style="font-size:14px">کارنامه رسمی ارزیابی روان‌شناختی (ابعاد استاندارد ISO A4)</b>
      <div style="font-size:11.5px;opacity:.85;margin-top:2px">
        کد رهگیری: <b><?= e($repCode) ?></b> &nbsp;·&nbsp;
        مراجع: <b><?= e($clientName) ?></b> &nbsp;·&nbsp;
        <?php if ($isAdmin): ?>
          <span style="background:#4338ca;padding:2px 8px;border-radius:6px;font-size:11px">🛡️ دسترسی مدیر / مشاور فعال است</span>
        <?php else: ?>
          <span style="background:#059669;padding:2px 8px;border-radius:6px;font-size:11px">🔒 نسخه معتبر مراجع</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="pr-toolbar-actions">
    <?php if ($isAdmin): ?>
      <a class="pr-btn-action" style="background:#6366f1;color:#fff" href="<?= SITE_URL ?>/admin/settings.php?tab=report" target="_blank" title="ویرایش لوگو، نام مرکز، مشخصات روانشناس و مهر">
        ⚙️ تنظیمات ارائه‌دهنده
      </a>
    <?php endif; ?>
    <button class="pr-btn-action pr-btn-print" style="background:#1e3a6e;color:#fff;font-weight:600" onclick="window.frPrintReport()" title="چاپ مستقیم با پرینتر سیستم یا ذخیره پرینت استاندارد A4">
      🖨️ چاپ با پرینتر (نسخه چاپی)
    </button>
    <button class="pr-btn-action pr-btn-download" style="background:#059669;color:#fff" onclick="window.frSavePdf()" title="دانلود و ذخیره مستقیم فایل PDF رسمی در ابعاد استاندارد A4">
      📥 دانلود فایل PDF کارنامه (A4)
    </button>
    <a class="pr-btn-action" style="background:#0284c7;color:#fff" href="<?= e(SITE_URL . '/panel/report-pdf.php?attempt=' . (int)$at['id'] . '&download_file=1') ?>" title="دریافت مستقیم و ذخیره فایل سند کارنامه برای باز کردن یا چاپ آفلاین">
      💾 دریافت مستقیم فایل
    </a>
    <a class="pr-btn-action pr-btn-back" href="<?= e($backUrl) ?>">
      ← بازگشت
    </a>
  </div>
</div>

<!-- نوار وضعیت دانلود فایل PDF برای کاربر -->
<div id="prPdfStatus" style="display:none;background:#1e3a6e;color:#fff;padding:12px 24px;font-size:13px;line-height:1.8;text-align:center;box-shadow:0 4px 14px rgba(30,58,110,.25);border-bottom:1px solid #3b82f6;align-items:center;justify-content:center;gap:10px">
  <span>⏳ در حال آماده‌سازی و دانلود فایل PDF کارنامه (A4)...</span>
</div>
<div id="prDownloadHint" style="display:none">Save as PDF auto=1</div>

<!-- ── برگه رسمی A4 ── -->
<div class="pr-sheet" id="reportSheet">

  <!-- ۱. سربرگ رسمی ارائه‌دهنده و کلینیک -->
  <header class="pr-header">
    <div class="pr-header-right">
      <?php if ($providerLogo !== ''): ?>
        <img class="pr-logo" src="<?= e($providerLogo) ?>" alt="<?= e($providerName) ?>">
      <?php endif; ?>
      <div>
        <h1 class="pr-provider-title"><?= e($providerName) ?></h1>
        <p class="pr-provider-sub"><?= e($providerSubtitle) ?></p>
        <?php if ($providerLicense !== ''): ?>
          <div style="font-size:10.5px;color:var(--pr-color);font-weight:600;margin-top:2px">
            <?= e($providerLicense) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="pr-header-left">
      <div>کد کارنامه: <b dir="ltr"><?= e($repCode) ?></b></div>
      <div>تاریخ صدور: <b><?= $issueDateFa ?></b></div>
      <?php if ($doctorName !== ''): ?>
        <div>ناظر بالینی: <b><?= e($doctorName) ?></b></div>
      <?php endif; ?>
      <?php if ($doctorLicense !== ''): ?>
        <div>شماره نظام: <b dir="ltr"><?= e($doctorLicense) ?></b></div>
      <?php endif; ?>
    </div>
  </header>

  <!-- ۲. شناسنامه اطلاعات مراجع و ارزیابی -->
  <div class="pr-demobox">
    <div class="pr-demogrid">
      <div class="item">
        <span class="label">نام و نام خانوادگی مراجع:</span>
        <span class="val"><?= e($clientName) ?></span>
      </div>
      <div class="item">
        <span class="label">کد ملی / شناسه هویتی:</span>
        <span class="val" dir="ltr"><?= e($clientNationalCode ?: '—') ?></span>
      </div>
      <div class="item">
        <span class="label">شماره تلفن همراه:</span>
        <span class="val" dir="ltr"><?= fa_num($clientMobile ?: '—') ?></span>
      </div>
      <div class="item">
        <span class="label">جنسیت / سن:</span>
        <span class="val"><?= e($clientGenderFa) ?><?= $clientAge !== '' ? (' / ' . fa_num($clientAge)) : '' ?></span>
      </div>
      <div class="item">
        <span class="label">عنوان ابزار سنجش:</span>
        <span class="val" style="color:var(--pr-color)"><?= e($at['test_title'] ?? 'آزمون روان‌شناختی') ?></span>
      </div>
      <div class="item">
        <span class="label">تاریخ و زمان اجرا:</span>
        <span class="val"><?= $completedFa ?></span>
      </div>
      <div class="item">
        <span class="label">سطح ارزیابی:</span>
        <?php
        $atMode = (string)($at['mode'] ?? 'full');
        if ($atMode !== 'free' && $atMode !== 'full') {
            $atMode = 'full';
        }
        $at['mode'] = $atMode;
        ?>
        <span class="val"><?= $atMode === 'full' ? 'گزارش تفصیلی و تخصصی' : 'گزارش غربالگری اولیه' ?></span>
      </div>
      <div class="item">
        <span class="label">روانشناس / مسئول فنی:</span>
        <span class="val"><?= e($doctorName ?: ($providerName)) ?></span>
      </div>
    </div>
  </div>

  <!-- ۳. جدول خلاصه نمرات تراز و وضعیت مقیاس‌ها -->
  <?php
  $results = Analyzer::getResults((int)$at['id']);
  if (empty($results) && ($at['status'] ?? '') === 'completed') {
      Analyzer::scoreAttempt((int)$at['id']);
      $results = Analyzer::getResults((int)$at['id']);
  }
  if (!empty($results)):
  ?>
  <div class="avoid-break" style="margin-bottom:18px">
    <h3 style="font-size:13px;margin:0 0 8px 0;color:var(--pr-color);border-right:3px solid var(--pr-color);padding-right:8px">
      📊 جدول رسمی نمرات تراز، استن و رتبه درصدی مقیاس‌ها
    </h3>
    <table class="pr-table">
      <thead>
        <tr>
          <th style="width:36px">ردیف</th>
          <th style="text-align:right">مقیاس / سازه روان‌شناختی</th>
          <th style="width:75px">نمره درصد</th>
          <th style="width:75px">نمره تراز T</th>
          <th style="width:65px">استن</th>
          <th style="width:85px">رتبه درصدی</th>
          <th style="width:95px">دامنه تفسیری</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $idx = 1;
        $bandFaMap = [
          'very_low'  => ['خیلی پایین', '#3aa0ff', '#eff6ff'],
          'low'       => ['پایین', '#19a974', '#f0fdf4'],
          'medium'    => ['متعادل', '#8b5cf6', '#faf5ff'],
          'high'      => ['بالا', '#f59e0b', '#fffbeb'],
          'very_high' => ['خیلی بالا', '#e0393f', '#fef2f2'],
        ];
        foreach ($results as $res):
            $bInfo = $bandFaMap[$res['band'] ?? ''] ?? ['متعادل', '#64748b', '#f8fafc'];
        ?>
        <tr>
          <td><?= fa_num($idx++) ?></td>
          <td style="text-align:right;font-weight:600"><?= e($res['title_fa'] ?? $res['scale_code']) ?></td>
          <td><b><?= fa_num($res['percent'] ?? 0) ?>٪</b></td>
          <td dir="ltr"><?= !empty($res['t_score']) ? fa_num($res['t_score']) : '—' ?></td>
          <td><?= !empty($res['sten_score']) ? (fa_num($res['sten_score']) . '/۱۰') : '—' ?></td>
          <td><?= !empty($res['percentile']) ? (fa_num($res['percentile']) . '٪') : '—' ?></td>
          <td>
            <span class="pr-pill" style="color:<?= $bInfo[1] ?>;background:<?= $bInfo[2] ?>;border:1px solid <?= $bInfo[1] ?>44">
              <?= e($bInfo[0]) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ۴. بدنه تحلیلی کارنامه روان‌سنجی (نمودارهای وکتور + تحلیل تخصصی) -->
  <div class="pr-content">
    <?php
    try {
        $repOut = ReportBuilder::build($at, $targetUser ?: [
            'first_name' => $at['first_name'] ?? '',
            'last_name'  => $at['last_name'] ?? '',
            'mobile'     => $at['mobile'] ?? '',
        ], $atMode, [
            'print'   => true,
            'isAdmin' => $isAdmin,
        ]);
        echo fr_filter_clinical_interventions($repOut, $isAdmin);
    } catch (\Throwable $e) {
        error_log('ReportBuilder PDF Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        echo '<div class="fr-alert err" style="margin:20px 0;padding:16px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b">'
           . '⚠️ کارنامه روان‌سنجی تولید شد اما بخشی از تحلیل نیازمند بازبینی است.'
           . '</div>';
    }
    ?>
  </div>

  <!-- ۵. کادر اعتبارسنجی QR و امضا و مهر رسمی مرکز -->
  <div class="pr-attestation">
    <!-- بارکد و استعلام اصالت -->
    <?php if ($showQr): ?>
      <div class="pr-qr-section">
        <div id="frQrContainer" class="pr-qr-box" style="width:115px;height:115px;flex-shrink:0;background:#fff;padding:4px;border:1px solid #cbd5e1;border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden">
          <?= $qrSvg ?: barcode39_svg($repCode, 52) ?>
        </div>
        <div style="font-size:11px;line-height:1.9">
          <b style="color:var(--pr-color);font-size:12px">🔏 اصالت‌سنجی رسمی کارنامه</b><br>
          کد رهگیری: <b style="color:#0f172a" dir="ltr"><?= e($repCode) ?></b><br>
          <span style="color:var(--pr-muted)">جهت استعلام اصالت و صدور این کارنامه، بارکد را اسکن فرمایید.</span>
        </div>
      </div>
    <?php endif; ?>

    <!-- کادر امضا و مهر ناظر بالینی -->
    <?php if ($showSignature): ?>
      <div class="pr-signature-section">
        <div style="font-size:12px;font-weight:700;color:var(--pr-color);margin-bottom:6px">
          تأییدیه و مهر ناظر علمی آزمون
        </div>
        <?php if ($providerStamp !== ''): ?>
          <img class="pr-stamp-img" src="<?= e($providerStamp) ?>" alt="مهر و امضا">
        <?php else: ?>
          <div style="height:55px;border-bottom:1px dashed #cbd5e1;display:flex;align-items:flex-end;justify-content:center;color:#94a3b8;font-size:10.5px;padding-bottom:4px">
            محل مهر و امضای روانشناس ناظر
          </div>
        <?php endif; ?>
        <div style="font-size:11.5px;font-weight:700;color:#1e293b;margin-top:4px">
          <?= e($doctorName ?: $providerName) ?>
        </div>
        <div style="font-size:10.5px;color:var(--pr-muted)">
          <?= e($doctorTitle) ?><?= $doctorLicense !== '' ? (' — نظام: ' . e($doctorLicense)) : '' ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ۶. تذکرات بالینی و سلب مسئولیت حقوقی -->
  <?php if ($disclaimer !== ''): ?>
    <div class="pr-disclaimer">
      <b>⚠️ یادداشت بالینی و سلب مسئولیت:</b> <?= e($disclaimer) ?>
    </div>
  <?php endif; ?>

  <!-- ۷. مشخصات تماس و پاورقی نهایی -->
  <footer class="pr-footer-meta">
    <div>
      <?= e($providerName) ?>
      <?php if ($providerPhone !== ''): ?> &nbsp;·&nbsp; تلفن: <?= e($providerPhone) ?><?php endif; ?>
      <?php if ($providerAddress !== ''): ?> &nbsp;·&nbsp; نشانی: <?= e($providerAddress) ?><?php endif; ?>
    </div>
    <div>
      <?= e($providerWebsite) ?> &nbsp;·&nbsp; سامانه روان‌سنجی فریا روان
    </div>
  </footer>

</div>

<script src="<?= SITE_URL ?>/assets/js/feria.js?v=<?= FR_VERSION ?>"></script>
<script src="<?= SITE_URL ?>/assets/js/html2pdf.bundle.min.js?v=<?= FR_VERSION ?>"></script>
<script>
/* ══════════════════════════════════════════════════════════════════
   رسم باکیفیت و برداری نمودارهای اختصاصی چاپ ISO A4 روی Canvas
   line  → نمودار خطی DISC
   hbars → نمودار میله‌ای افقی سایر آزمون‌ها
   ══════════════════════════════════════════════════════════════════ */
(function () {
  function faN(n) { return String(Math.round(n)).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function prep(cnv) {
    var ctx = cnv.getContext('2d');
    var cssW = cnv.offsetWidth || 700, cssH = cnv.offsetHeight || 260;
    cnv.width = cssW * 2; cnv.height = cssH * 2; ctx.scale(2, 2);
    return { ctx: ctx, w: cssW, h: cssH };
  }
  var FONT = 'Vazirmatn, Shabnam, Tahoma, sans-serif';

  function drawLine(cnv, cfg) {
    var p = prep(cnv), ctx = p.ctx, w = p.w, h = p.h;
    var padL = 14, padR = 40, padT = 30, padB = 34;
    var xs = cfg.labels.length, ix = function (i) { return padL + (w - padL - padR) * i / Math.max(1, xs - 1); };
    var iy = function (v) { return h - padB - (h - padT - padB) * Math.max(0, Math.min(100, v)) / 100; };
    ctx.strokeStyle = '#efecf8'; ctx.lineWidth = 1;
    ctx.fillStyle = '#94a3b8'; ctx.font = '10px ' + FONT; ctx.textAlign = 'left';
    for (var gi = 0; gi <= 5; gi++) {
      var y = iy(gi * 20);
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
      ctx.fillText(faN(gi * 20) + '٪', w - padR + 6, y + 3);
    }
    ctx.fillStyle = '#334155'; ctx.font = 'bold 11px ' + FONT; ctx.textAlign = 'center';
    cfg.labels.forEach(function (lb, i) { ctx.fillText(lb, ix(i), h - padB + 18); });
    cfg.series.forEach(function (s) {
      ctx.strokeStyle = s.color; ctx.lineWidth = 2.4; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
      ctx.beginPath();
      s.values.forEach(function (v, i) { i ? ctx.lineTo(ix(i), iy(v)) : ctx.moveTo(ix(i), iy(v)); });
      ctx.stroke();
      s.values.forEach(function (v, i) {
        ctx.beginPath(); ctx.arc(ix(i), iy(v), 4.4, 0, 7); ctx.fillStyle = '#fff'; ctx.fill();
        ctx.beginPath(); ctx.arc(ix(i), iy(v), 3.2, 0, 7); ctx.fillStyle = s.color; ctx.fill();
        ctx.fillStyle = '#1e293b'; ctx.font = 'bold 9.5px ' + FONT; ctx.textAlign = 'center';
        ctx.fillText(faN(v) + '٪', ix(i), iy(v) - 9);
      });
    });
  }

  function drawHBars(cnv, cfg) {
    var bars = cfg.bars || [];
    var rowH = bars.length > 10 ? 22 : 28;
    var needH = bars.length * rowH + 24;
    if (cnv.offsetHeight < needH) cnv.style.height = needH + 'px';
    var p = prep(cnv), ctx = p.ctx, w = p.w, h = p.h;
    var labelW = 150, padR = 14, padL = 50;
    var x0 = labelW, x1 = w - padL - padR;
    bars.forEach(function (b, i) {
      var y = 10 + i * rowH, bh = rowH * 0.52;
      ctx.fillStyle = '#1e293b'; ctx.font = 'bold 10.5px ' + FONT; ctx.textAlign = 'right';
      ctx.fillText(b.label, labelW - 8, y + bh - 2);
      ctx.fillStyle = '#f1f5f9';
      if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x0, y, x1 - x0, bh, bh / 2); ctx.fill(); }
      else ctx.fillRect(x0, y, x1 - x0, bh);
      var bw = Math.max(3, (x1 - x0) * Math.min(100, b.value) / 100);
      ctx.fillStyle = b.color;
      if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(x0, y, bw, bh, bh / 2); ctx.fill(); }
      else ctx.fillRect(x0, y, bw, bh);
      ctx.fillStyle = '#475569'; ctx.font = 'bold 9.5px ' + FONT; ctx.textAlign = 'left';
      ctx.fillText(faN(b.value) + '٪' + (b.band ? ' — ' + b.band : ''), x0 + bw + 6, y + bh - 2);
    });
  }

  document.querySelectorAll('canvas[data-pr]').forEach(function (cnv) {
    try {
      var cfg = JSON.parse(cnv.getAttribute('data-pr'));
      if (cfg.type === 'line') drawLine(cnv, cfg); else drawHBars(cnv, cfg);
      var lg = cnv.parentNode.querySelector('[data-pr-legend]');
      if (lg && cfg.type === 'line') {
        lg.innerHTML = cfg.series.map(function (s) {
          return '<span><i style="background:' + s.color + '"></i>' + s.name + '</span>';
        }).join('');
      }
    } catch (e) { }
  });

  // جایگزین بارکد QR در صورت عدم اتصال
  var qr = document.getElementById('frQrImg');
  if (qr) {
    var swap = function () {
      qr.style.display = 'none';
      var fb = document.getElementById('frQrFallback');
      if (fb) fb.style.display = 'inline-block';
    };
    if (qr.complete && qr.naturalWidth === 0) swap();
    qr.addEventListener('error', swap);
  }

  // تبدیل کلیه نمودارهای Canvas به تصاویر وکتوری با رزولوشن دوبرابر
  // جهت تضمین کیفیت برداری و رندر بی‌نقص در چاپ، html2pdf/html2canvas و بدون سفید شدن نمودارها
  window.frConvertCanvases = function () {
    var element = document.getElementById('reportSheet');
    if (!element) return;
    element.querySelectorAll('canvas').forEach(function (cv) {
      try {
        if (!cv.dataset.imgConverted) {
          var dataUrl = cv.toDataURL('image/png', 1.0);
          var img = document.createElement('img');
          img.src = dataUrl;
          img.style.width = '100%';
          img.style.height = 'auto';
          img.style.display = 'block';
          img.className = cv.className;
          cv.dataset.imgConverted = '1';
          cv.style.display = 'none';
          cv.parentNode.insertBefore(img, cv);
        }
      } catch (ce) {
        console.warn('Canvas conversion notice:', ce);
      }
    });
  };

  // تبدیل خودکار بوم‌ها بلافاصله پس از رسم اولیه
  setTimeout(window.frConvertCanvases, 250);

  // متد چاپ مستقیم با پرینتر سیستم (نسخه چاپی)
  window.frPrintReport = function () {
    window.frConvertCanvases();
    setTimeout(function () {
      window.print();
    }, 150);
  };

  // متد ذخیره و دریافت مستقیم PDF استاندارد ISO A4
  window.frSavePdf = function () {
    var statusBox = document.getElementById('prPdfStatus');
    if (statusBox) {
      statusBox.style.display = 'flex';
      statusBox.style.background = '#1e3a6e';
      statusBox.innerHTML = '<span>⏳ در حال آماده‌سازی و ساخت فایل PDF کارنامه استاندارد A4... لطفاً شکیبا باشید.</span>';
    }

    var btn = document.querySelector('.pr-btn-download');
    var origText = btn ? btn.innerHTML : '';
    if (btn) {
      btn.innerHTML = '⏳ در حال ساخت و دانلود PDF...';
      btn.disabled = true;
    }

    var element = document.getElementById('reportSheet');
    if (!element) {
      if (btn) { btn.innerHTML = origText; btn.disabled = false; }
      return;
    }

    window.frConvertCanvases();

    if (window.html2pdf) {
      var opt = {
        margin:       [8, 8, 8, 8],
        filename:     <?= json_encode($docTitle . '.pdf', JSON_UNESCAPED_UNICODE) ?>,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, letterRendering: true, logging: false },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
      };

      html2pdf().set(opt).from(element).save().then(function () {
        if (btn) {
          btn.innerHTML = '✅ دریافت شد!';
          setTimeout(function() { btn.innerHTML = origText; btn.disabled = false; }, 3000);
        }
        if (statusBox) {
          statusBox.style.background = '#065f46';
          statusBox.innerHTML = '<span>✅ فایل PDF کارنامه با موفقیت دانلود و ذخیره شد.</span>';
          setTimeout(function() { statusBox.style.display = 'none'; }, 4000);
        }
      }).catch(function (e) {
        console.error('html2pdf generation notice:', e);
        if (btn) { btn.innerHTML = origText; btn.disabled = false; }
        if (statusBox) {
          statusBox.style.background = '#991b1b';
          statusBox.innerHTML = '<span>⚠️ خطا در ساخت خودکار فایل PDF. لطفاً در صورت تمایل از دکمه «چاپ با پرینتر» استفاده فرمایید.</span>';
        }
      });
    } else {
      if (btn) { btn.innerHTML = origText; btn.disabled = false; }
      if (statusBox) {
        statusBox.style.background = '#991b1b';
        statusBox.innerHTML = '<span>⚠️ کتابخانه ساخت فایل PDF بارگذاری نشده است.</span>';
      }
    }
  };

  // پردازش خودکار پارامترهای print=1 و download=1 و auto=1
  var urlParams = new URLSearchParams(location.search);
  var isAutoPrint = urlParams.get('print') === '1' ||
                    urlParams.get('action') === 'print' ||
                    location.search.indexOf('print=1') !== -1 ||
                    location.search.indexOf('action=print') !== -1;

  var isAutoDownload = urlParams.get('download') === '1' || urlParams.get('auto') === '1' ||
                       urlParams.get('download_file') === '1' ||
                       location.search.indexOf('download=1') !== -1 ||
                       location.search.indexOf('auto=1') !== -1;

  if (isAutoPrint) {
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () {
        setTimeout(window.frPrintReport, 400);
      });
    } else {
      setTimeout(window.frPrintReport, 600);
    }
  } else if (isAutoDownload) {
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () {
        setTimeout(window.frSavePdf, 500);
      });
    } else {
      setTimeout(window.frSavePdf, 800);
    }
  }
})();
</script>
</body>
</html>
