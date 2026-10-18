<?php
/**
 * ============================================================================
 *  موتور بومی و چندلایهٔ تولید کارنامه روان‌شناختی (PDF Generator Engine — نسخهٔ ۴٫۹٫۵)
 *  ─ بر پایهٔ استاندارد مدرن Dompdf (HTML5/CSS3) به همراه شکل‌دهی بومی خط فارسی (پ، چ، ژ، گ، ک، ی)
 *  ─ خروجی تمام‌رنگی (Full Color) با گرادیان‌ها، پالت‌های انتخابی ادمین و تنظیمات سربرگ و لوگو
 *  ─ سازگاری دقیق با استانداردهای چاپ A4 برگرفته از راهنمای Chalarangelo/30-seconds-of-code
 *  ─ اصلاح ساختار .fr-scalebar و .track با پرشدگی رنگی نوار بر اساس درصد دقیق
 *  ─ سیستم پشتیبان دولایه (Fallback به TCPDF با قلم DejaVu Sans و خروجی اضطراری استاندارد)
 *  ─ تضمین رعایت محرمانگی بالینی: عدم درج راهنمای مداخله برای مراجعین عادی
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/tcpdf/tcpdf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../core/Analyzer.php';

final class CarnetPdfBuilder
{
    /**
     * تولید فایل باینری PDF کارنامه برای یک تلاش آزمون
     * @param int $attemptId شناسه جلسه آزمون
     * @param bool $isAdmin آیا درخواست‌کننده مدیر سیستم است؟
     * @return string بایت‌های خام فایل PDF (%PDF-)
     */
    public static function build(int $attemptId, bool $isAdmin = false): string
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);
        $engine = function_exists('DB::setting') ? (string)DB::setting('report_pdf_engine', 'auto') : 'auto';

        // در صورت انتخاب دستی TCPDF در تنظیمات
        if ($engine === 'tcpdf') {
            try {
                return self::buildWithTcpdf($attemptId, $isAdmin);
            } catch (\Throwable $te) {
                error_log('CarnetPdfBuilder TCPDF primary error: ' . $te->getMessage());
                return self::buildFallbackPdf($attemptId, $isAdmin, $te->getMessage());
            }
        }

        // حالت پیش‌فرض (auto یا dompdf): تولید با Dompdf با قابلیت Fallback به TCPDF
        if (class_exists('\DOMDocument') && class_exists('\DOMXPath')) {
            try {
                return self::buildWithDompdf($attemptId, $isAdmin);
            } catch (\Throwable $de) {
                error_log('CarnetPdfBuilder Dompdf error, falling back to TCPDF: ' . $de->getMessage());
            }
        } else {
            error_log('CarnetPdfBuilder: DOMDocument/XML extension not available on server, falling back to TCPDF.');
        }

        try {
            return self::buildWithTcpdf($attemptId, $isAdmin);
        } catch (\Throwable $te) {
            error_log('CarnetPdfBuilder TCPDF fallback error: ' . $te->getMessage());
            return self::buildFallbackPdf($attemptId, $isAdmin, $te->getMessage());
        }
    }

    /**
     * پاکسازی و تبدیل ایموجی‌ها به نمادهای هندسی استاندارد جهت جلوگیری از Tofu Glyphs در فونت‌های چاپی
     */
    private static function sanitizeEmojis(string $html): string
    {
        $map = [
            '🎯' => '▣', '📋' => '▣', '⚖️' => '▣', '⚖' => '▣',
            '💎' => '◆', '💡' => '◍', '📌' => '▪', '⚡' => '⚡',
            '🧠' => '▣', '🚀' => '▶', '✅' => '✓', '❌' => '✗',
            '🔍' => '◎', '📊' => '▣', '✨' => '★', '🛡️' => '▣',
            '🛡' => '▣', '👤' => '▣', '📄' => '▣', '📥' => '▼',
            '🔄' => '↺', '➕' => '+', '🌿' => '❀', '👑' => '★',
            '🎨' => '▣', '🌊' => '≈', '🔏' => '▣', '🔒' => '▣',
            '🖨️' => '▣', '🖨' => '▣', '💾' => '▣', '⚙️' => '▣',
            '⚙' => '▣', '🏷️' => '▣', '🏷' => '▣', '👁️' => '▣', '👁' => '▣',
        ];
        $html = strtr($html, $map);
        return preg_replace('/[\x{1F300}-\x{1F9FF}]/u', '•', $html) ?? $html;
    }

    /**
     * تولید فایل تمام‌رنگی کارنامه به کمک Dompdf و موتور شکل‌دهی فارسی
     */
    public static function buildWithDompdf(int $attemptId, bool $isAdmin = false): string
    {
        // بارگذاری کتابخانه Dompdf
        $dompdfAutoload = __DIR__ . '/dompdf/autoload.inc.php';
        if (!class_exists('Dompdf\Dompdf')) {
            if (file_exists($dompdfAutoload)) {
                require_once $dompdfAutoload;
            } else {
                throw new \RuntimeException('کتابخانه Dompdf در مسیر core/dompdf یافت نشد.');
            }
        }

        // بارگذاری موتور شکل‌دهی خط فارسی و راست‌به‌چپ (Bidi / Shaper)
        if (!class_exists('MostafaMoknaa\ArabicPdf\Arabic')) {
            require_once __DIR__ . '/dompdf/persian/Text/Bidi.php';
            require_once __DIR__ . '/dompdf/persian/Text/Shaper.php';
            require_once __DIR__ . '/dompdf/persian/Html/HtmlProcessor.php';
            require_once __DIR__ . '/dompdf/persian/Arabic.php';
        }

        $at = DB::one('SELECT a.*, t.title as test_title, t.slug as test_slug, t.engine as test_engine, t.audience as test_audience,
                              u.first_name, u.last_name, u.mobile, u.national_code, u.birth_date, u.gender
                       FROM ' . DB::t('attempts') . ' a
                       LEFT JOIN ' . DB::t('tests') . ' t ON t.id = a.test_id
                       LEFT JOIN ' . DB::t('users') . ' u ON u.id = a.user_id
                       WHERE a.id = ?', [$attemptId]);

        if (!$at) {
            throw new \RuntimeException("جلسه آزمون با شناسه $attemptId یافت نشد.");
        }

        if ($at['status'] === 'in_progress') {
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
                } catch (\Throwable $e) {
                    error_log('CarnetPdfBuilder auto-score error: ' . $e->getMessage());
                }
            }
        }

        $targetUser = [
            'id'            => (int)($at['user_id'] ?? 0),
            'first_name'    => $at['first_name'] ?? '',
            'last_name'     => $at['last_name'] ?? '',
            'mobile'        => $at['mobile'] ?? '',
            'national_code' => $at['national_code'] ?? '',
            'gender'        => $at['gender'] ?? '',
            'birth_date'    => $at['birth_date'] ?? '',
        ];

        $clientName = trim(($at['first_name'] ?? '') . ' ' . ($at['last_name'] ?? '')) ?: 'مراجع گرامی';
        $clientMobile = (string)($at['mobile'] ?? '—');
        $clientNationalCode = (string)($at['national_code'] ?? '—');
        $clientGenderFa = match((string)($at['gender'] ?? '')) { 'female' => 'زن', 'male' => 'مرد', default => 'نامشخص' };
        $clientAge = '—';
        if (!empty($at['birth_date']) && preg_match('/^(\d{4})/', (string)$at['birth_date'], $bm)) {
            $by = (int)$bm[1];
            $cy = (int)jdate('Y');
            if ($by > 1300 && $by <= $cy) {
                $clientAge = (string)($cy - $by) . ' سال';
            }
        }

        $repCode = (string)($at['rep_code'] ?? ('FR-' . $at['id']));
        $completedTs = strtotime((string)($at['completed_at'] ?: ($at['started_at'] ?? ''))) ?: time();
        $completedFa = fa_num(jdate('l j F Y — ساعت H:i', $completedTs));
        $issueDateFa = fa_num(jdate('j F Y', $completedTs));

        // اطلاعات مرکز و ناظر علمی از تنظیمات مدیریت
        $providerName     = function_exists('fr_report_provider_name') ? fr_report_provider_name() : (string)DB::setting('report_provider_name', 'مرکز خدمات روان‌شناختی فریا');
        $providerSubtitle = function_exists('fr_report_provider_subtitle') ? fr_report_provider_subtitle() : (string)DB::setting('report_provider_subtitle', 'سامانه هوشمند و بالینی ارزیابی‌های روان‌شناختی');
        $providerLicense  = function_exists('fr_report_provider_license') ? fr_report_provider_license() : (string)DB::setting('report_provider_license', '');
        $doctorName       = function_exists('fr_report_doctor_name') ? fr_report_doctor_name() : (string)DB::setting('report_doctor_name', 'دکتر روانشناس بالینی');
        $doctorTitle      = function_exists('fr_report_doctor_title') ? fr_report_doctor_title() : (string)DB::setting('report_doctor_title', 'مسئول فنی و ناظر آزمون‌های روان‌سنجی');
        $doctorLicense    = function_exists('fr_report_doctor_license') ? fr_report_doctor_license() : (string)DB::setting('report_doctor_license', '');
        $showSignature    = function_exists('fr_report_show_signature_box') ? fr_report_show_signature_box() : true;
        $providerPhone    = function_exists('fr_report_provider_phone') ? fr_report_provider_phone() : (string)DB::setting('report_provider_phone', '');
        $providerWebsite  = function_exists('fr_report_provider_website') ? fr_report_provider_website() : (string)DB::setting('site_url', SITE_URL);
        $headerColor      = function_exists('fr_report_header_color') ? fr_report_header_color() : '#1e3a6e';
        $accentColor      = function_exists('fr_report_accent_color') ? fr_report_accent_color() : '#7c3aed';
        $disclaimer       = function_exists('fr_report_disclaimer') ? fr_report_disclaimer() : (string)DB::setting('report_disclaimer', 'این کارنامه بر مبنای خودگزارش‌دهی مراجع تدوین گردیده و برای تصمیم‌گیری نهایی تشخیصی و مداخله بالینی باید توسط روانشناس و متخصص ذی‌صلاح بررسی و تفسیر شود.');
        $showQr           = function_exists('fr_report_show_qr') ? fr_report_show_qr() : true;

        // تصویر لوگوی سربرگ و مهر اختصاصی با تبدیل به Data URI جهت رفع خطای لود تصویر
        $rawLogo = function_exists('fr_report_provider_logo') ? fr_report_provider_logo() : '';
        $logoUri = function_exists('fr_image_to_data_uri') ? fr_image_to_data_uri($rawLogo) : '';

        $rawStamp = function_exists('fr_report_provider_stamp') ? fr_report_provider_stamp() : '';
        $stampUri = function_exists('fr_image_to_data_uri') ? fr_image_to_data_uri($rawStamp) : '';

        // بارکد QR اصالت‌سنجی سند
        $customQrLink = trim((string)DB::setting('report_qr_link', ''));
        $verifyUrl = $customQrLink !== '' ? $customQrLink : (SITE_URL . '/panel/report.php?attempt=' . (int)$at['id']);
        $qrSvg = function_exists('fr_qr_svg') ? fr_qr_svg($verifyUrl, 84) : '';
        $qrUri = ($qrSvg !== '') ? ('data:image/svg+xml;base64,' . base64_encode($qrSvg)) : '';

        // ساخت بدنه کامل گزارش با نمرات، نوارهای مقیاس و تفسیرها
        $reportContent = ReportBuilder::build($at, $targetUser, 'full', [
            'print'   => true,
            'pdf'     => true,
            'isAdmin' => $isAdmin,
        ]);

        if (function_exists('fr_filter_clinical_interventions')) {
            $reportContent = fr_filter_clinical_interventions($reportContent, $isAdmin);
        }

        $testTitle = (string)($at['test_title'] ?? 'آزمون روان‌شناختی');
        $audience = (string)($at['test_audience'] ?? 'بزرگسالان');

        // سند HTML استاندارد ISO A4 با استایل‌های دقیق برگرفته از 30-seconds-of-code
        $html = '<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<style>
  @page {
    size: A4 portrait;
    margin: 10mm 12mm 12mm 12mm;
  }
  *, *::before, *::after {
    box-sizing: border-box;
    text-shadow: none !important;
  }
  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 9.5pt;
    line-height: 1.6;
    color: #0f172a;
    background: #fff;
    direction: rtl;
    text-align: right;
    margin: 0;
    padding: 0;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  .pr-header {
    width: 100%;
    border-bottom: 2.5px solid ' . htmlspecialchars($headerColor, ENT_QUOTES) . ';
    padding-bottom: 8px;
    margin-bottom: 12px;
    border-collapse: collapse;
  }
  .pr-header td {
    border: none;
    padding: 3px;
    vertical-align: middle;
  }
  .pr-logo-img {
    height: 52px;
    max-width: 90px;
    object-fit: contain;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 3px;
    background: #fff;
  }
  .pr-demobox {
    width: 100%;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    margin-bottom: 12px;
    border-collapse: collapse;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .pr-demobox td {
    border: 1px solid #e2e8f0;
    padding: 5px 8px;
    font-size: 9pt;
  }
  .fr-card {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 11px;
    background: #fff;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .fr-card h3 {
    color: ' . htmlspecialchars($headerColor, ENT_QUOTES) . ';
    font-size: 11pt;
    margin: 0 0 8px 0;
    page-break-after: avoid;
    break-after: avoid;
  }
  h1, h2, h3, h4, h5, h6 {
    page-break-after: avoid;
    break-after: avoid;
  }
  img {
    max-width: 100%;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  /* نوارهای مقیاس (.fr-scalebar) با چیدمان جدولی جهت تضمین رندر رنگی در Dompdf */
  .fr-scalebar {
    display: table !important;
    width: 100% !important;
    border-collapse: collapse !important;
    margin: 6px 0 !important;
    page-break-inside: avoid !important;
    break-inside: avoid !important;
  }
  .fr-scalebar .lbl {
    display: table-cell !important;
    width: 34% !important;
    vertical-align: middle !important;
    font-size: 9pt !important;
    color: #1e293b !important;
    text-align: right !important;
    padding-left: 6px !important;
  }
  .fr-scalebar .track {
    display: table-cell !important;
    width: 48% !important;
    vertical-align: middle !important;
    height: 15px !important;
    background-color: #e2e8f0 !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 999px !important;
    overflow: hidden !important;
    position: relative !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  .fr-scalebar .fill {
    display: block !important;
    height: 15px !important;
    border-radius: 999px !important;
    min-width: 4px !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  .fr-scalebar .val {
    display: table-cell !important;
    width: 18% !important;
    vertical-align: middle !important;
    font-size: 8.5pt !important;
    color: #475569 !important;
    font-weight: bold !important;
    text-align: left !important;
    padding-right: 6px !important;
    direction: ltr !important;
  }
  table.fr-table {
    width: 100%;
    border-collapse: collapse;
    margin: 8px 0;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  table.fr-table th, table.fr-table td {
    border: 1px solid #cbd5e1;
    padding: 5px 8px;
    font-size: 8.5pt;
    text-align: center;
  }
  table.fr-table th {
    background: #f1f5f9;
    font-weight: bold;
  }
  .pr-attestation {
    margin-top: 18px;
    padding-top: 10px;
    border-top: 2px dashed #cbd5e1;
    width: 100%;
    border-collapse: collapse;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .pr-attestation td {
    border: none;
    vertical-align: middle;
  }
  .pr-disclaimer {
    margin-top: 12px;
    padding: 7px 10px;
    background: #f8fafc;
    border-radius: 6px;
    font-size: 8pt;
    line-height: 1.7;
    color: #64748b;
    text-align: justify;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .no-print, form, .pr-toolbar { display: none !important; }
</style>
</head>
<body>

  <!-- سربرگ رسمی با لوگو و رنگ انتخابی ادمین -->
  <table class="pr-header">
    <tr>
      ' . ($logoUri !== '' ? '<td width="14%" align="right"><img src="' . htmlspecialchars($logoUri, ENT_QUOTES) . '" class="pr-logo-img" alt="لوگو"></td>' : '') . '
      <td width="' . ($logoUri !== '' ? '54%' : '65%') . '">
        <div style="font-size:13.5pt;font-weight:bold;color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';">' . htmlspecialchars($providerName, ENT_QUOTES) . '</div>
        <div style="font-size:8.5pt;color:#64748b;margin-top:2px;">' . htmlspecialchars($providerSubtitle, ENT_QUOTES) . '</div>
        ' . ($providerLicense !== '' ? '<div style="font-size:8pt;color:#475569;margin-top:2px;">پروانه / شماره مجوز: <b>' . htmlspecialchars($providerLicense, ENT_QUOTES) . '</b></div>' : '') . '
      </td>
      <td width="' . ($logoUri !== '' ? '32%' : '35%') . '" align="left" style="font-size:8pt;color:#334155;line-height:1.65;">
        کد رهگیری: <b dir="ltr" style="color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';">' . htmlspecialchars($repCode, ENT_QUOTES) . '</b><br>
        تاریخ صدور: <b>' . htmlspecialchars($issueDateFa, ENT_QUOTES) . '</b><br>
        ' . ($isAdmin ? '<span style="color:#4338ca;font-weight:bold;">[نسخه کامل بالینی مدیریت]</span>' : '<span style="color:#059669;font-weight:bold;">[نسخه معتبر مراجع]</span>') . '
      </td>
    </tr>
  </table>

  <!-- جدول شناسنامه مشخصات هویتی و آزمون -->
  <table class="pr-demobox">
    <tr>
      <td width="20%"><b>نام و نام خانوادگی:</b></td>
      <td width="30%">' . htmlspecialchars($clientName, ENT_QUOTES) . '</td>
      <td width="20%"><b>عنوان آزمون:</b></td>
      <td width="30%"><b style="color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';">' . htmlspecialchars($testTitle, ENT_QUOTES) . '</b></td>
    </tr>
    <tr>
      <td width="20%"><b>کد ملی مراجع:</b></td>
      <td width="30%" dir="ltr">' . htmlspecialchars($clientNationalCode, ENT_QUOTES) . '</td>
      <td width="20%"><b>گروه هدف:</b></td>
      <td width="30%">' . htmlspecialchars($audience, ENT_QUOTES) . '</td>
    </tr>
    <tr>
      <td width="20%"><b>جنسیت و سن:</b></td>
      <td width="30%">' . htmlspecialchars($clientGenderFa . ' (' . $clientAge . ')', ENT_QUOTES) . '</td>
      <td width="20%"><b>شماره تماس:</b></td>
      <td width="30%" dir="ltr">' . htmlspecialchars($clientMobile, ENT_QUOTES) . '</td>
    </tr>
    <tr>
      <td width="20%"><b>روانشناس ناظر:</b></td>
      <td width="30%">' . htmlspecialchars($doctorName, ENT_QUOTES) . '</td>
      <td width="20%"><b>زمان ثبت نهایی:</b></td>
      <td width="30%">' . htmlspecialchars($completedFa, ENT_QUOTES) . '</td>
    </tr>
  </table>

  <!-- بدنه جامع کارنامه و تحلیل‌ها -->
  <div class="pr-report-content">
    ' . $reportContent . '
  </div>

  <!-- بخش تأییدیه، امضا، مهر و بارکد QR اصالت -->
  <table class="pr-attestation">
    <tr>
      ' . ($showQr && $qrUri !== '' ? '
      <td width="40%" align="right">
        <table cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px;">
          <tr>
            <td style="padding-left:8px;"><img src="' . $qrUri . '" style="width:72px;height:72px;" alt="QR"></td>
            <td style="font-size:7.5pt;color:#475569;line-height:1.6;">
              <b>استعلام اصالت کارنامه</b><br>
              جهت صحت‌سنجی بارکد را اسکن کنید.<br>
              <span dir="ltr" style="font-size:7pt;color:#94a3b8;">' . htmlspecialchars($providerWebsite, ENT_QUOTES) . '</span>
            </td>
          </tr>
        </table>
      </td>' : '<td width="40%"></td>') . '
      ' . ($showSignature ? '
      <td width="60%" align="left">
        <div style="background:#fafbfd;border:1px solid #e2e8f0;border-radius:8px;padding:8px 14px;text-align:center;display:inline-block;min-width:240px;">
          <div style="font-size:8.5pt;font-weight:bold;color:#1e293b;">' . htmlspecialchars($doctorName, ENT_QUOTES) . '</div>
          <div style="font-size:7.5pt;color:#64748b;">' . htmlspecialchars($doctorTitle, ENT_QUOTES) . '</div>
          ' . ($doctorLicense !== '' ? '<div style="font-size:7pt;color:#475569;">شماره نظام روانشناسی: ' . htmlspecialchars($doctorLicense, ENT_QUOTES) . '</div>' : '') . '
          ' . ($stampUri !== '' ? '<img src="' . htmlspecialchars($stampUri, ENT_QUOTES) . '" style="height:48px;max-width:130px;margin-top:4px;" alt="مهر">' : '<div style="height:26px;"></div>') . '
          <div style="font-size:7pt;color:#94a3b8;border-top:1px dashed #cbd5e1;margin-top:4px;padding-top:2px;">امضا و مهر رسمی مرکز</div>
        </div>
      </td>' : '<td width="60%"></td>') . '
    </tr>
  </table>

  <!-- متن سلب مسئولیت بالینی و حقوقی -->
  <div class="pr-disclaimer">
    <b>توجه بالینی:</b> ' . htmlspecialchars($disclaimer, ENT_QUOTES) . '
  </div>

</body>
</html>';

        // پاکسازی ایموجی‌ها جهت سازگاری کامل با قلم چاپی یونیکد
        $html = self::sanitizeEmojis($html);

        // شکل‌دهی متون فارسی و پردازش bidi بدون معکوس‌سازی اشتباه ستون‌های جدول
        $persian = new \MostafaMoknaa\ArabicPdf\Arabic([
            'reverse_table_columns' => false,
            'rtl_css'               => false,
        ]);
        $shapedHtml = $persian->html($html);

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [FR_ROOT, sys_get_temp_dir()]);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($shapedHtml, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        if (empty($output) || !str_starts_with($output, '%PDF-')) {
            throw new \RuntimeException('خروجی Dompdf فاقد امضای معتبر باینری PDF است.');
        }

        return $output;
    }

    /**
     * موتور پشتیبان سازگار با ساختار قدیمی (TCPDF Fallback)
     */
    public static function buildWithTcpdf(int $attemptId, bool $isAdmin = false): string
    {
        $at = DB::one('SELECT a.*, t.title as test_title, t.slug as test_slug, t.engine as test_engine, t.audience as test_audience,
                              u.first_name, u.last_name, u.mobile, u.national_code, u.birth_date, u.gender
                       FROM ' . DB::t('attempts') . ' a
                       LEFT JOIN ' . DB::t('tests') . ' t ON t.id = a.test_id
                       LEFT JOIN ' . DB::t('users') . ' u ON u.id = a.user_id
                       WHERE a.id = ?', [$attemptId]);

        if (!$at) {
            throw new \RuntimeException("جلسه آزمون با شناسه $attemptId یافت نشد.");
        }

        if ($at['status'] === 'in_progress') {
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
                } catch (\Throwable $e) {}
            }
        }

        $clientName = trim(($at['first_name'] ?? '') . ' ' . ($at['last_name'] ?? '')) ?: 'مراجع گرامی';
        $clientMobile = (string)($at['mobile'] ?? '—');
        $clientNationalCode = (string)($at['national_code'] ?? '—');
        $clientGenderFa = match((string)($at['gender'] ?? '')) { 'female' => 'زن', 'male' => 'مرد', default => 'نامشخص' };
        $clientAge = '—';
        if (!empty($at['birth_date']) && preg_match('/^(\d{4})/', (string)$at['birth_date'], $bm)) {
            $by = (int)$bm[1];
            $cy = (int)jdate('Y');
            if ($by > 1300 && $by <= $cy) {
                $clientAge = (string)($cy - $by) . ' سال';
            }
        }

        $repCode = (string)($at['rep_code'] ?? ('FR-' . $at['id']));
        $completedTs = strtotime((string)($at['completed_at'] ?: ($at['started_at'] ?? ''))) ?: time();
        $completedFa = fa_num(jdate('l j F Y — H:i', $completedTs));
        $issueDateFa = fa_num(jdate('j F Y', $completedTs));

        $providerName     = function_exists('fr_report_provider_name') ? fr_report_provider_name() : (string)DB::setting('report_provider_name', 'مرکز خدمات روان‌شناختی فریا');
        $providerSubtitle = function_exists('fr_report_provider_subtitle') ? fr_report_provider_subtitle() : (string)DB::setting('report_provider_subtitle', 'سامانه هوشمند و بالینی ارزیابی‌های روان‌شناختی');
        $providerLicense  = function_exists('fr_report_provider_license') ? fr_report_provider_license() : (string)DB::setting('report_provider_license', '');
        $doctorName       = function_exists('fr_report_doctor_name') ? fr_report_doctor_name() : (string)DB::setting('report_doctor_name', 'دکتر روانشناس بالینی');
        $doctorTitle      = function_exists('fr_report_doctor_title') ? fr_report_doctor_title() : (string)DB::setting('report_doctor_title', 'مسئول فنی و ناظر آزمون‌های روان‌سنجی');
        $doctorLicense    = function_exists('fr_report_doctor_license') ? fr_report_doctor_license() : (string)DB::setting('report_doctor_license', '');
        $showSignature    = function_exists('fr_report_show_signature_box') ? fr_report_show_signature_box() : true;
        $providerPhone    = function_exists('fr_report_provider_phone') ? fr_report_provider_phone() : (string)DB::setting('report_provider_phone', '');
        $providerWebsite  = function_exists('fr_report_provider_website') ? fr_report_provider_website() : (string)DB::setting('site_url', SITE_URL);
        $headerColor      = function_exists('fr_report_header_color') ? fr_report_header_color() : '#1e3a6e';
        $disclaimer       = function_exists('fr_report_disclaimer') ? fr_report_disclaimer() : (string)DB::setting('report_disclaimer', 'این کارنامه بر مبنای خودگزارش‌دهی مراجع تدوین گردیده و برای تصمیم‌گیری نهایی تشخیصی و مداخله بالینی باید توسط روانشناس و متخصص ذی‌صلاح بررسی و تفسیر شود.');

        $results = Analyzer::getResults($attemptId);
        if (empty($results) && ($at['status'] ?? '') === 'completed') {
            try {
                Analyzer::scoreAttempt($attemptId);
                $results = Analyzer::getResults($attemptId);
            } catch (\Throwable $e) {}
        }

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('فریا روان — سامانه هوشمند روان‌سنجی');
        $pdf->SetAuthor($providerName);
        $pdf->SetTitle('کارنامه رسمی ' . ($at['test_title'] ?? '') . ' — ' . $clientName);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 14, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->setRTL(true);
        $pdf->SetFont('dejavusans', '', 9.5);
        $pdf->AddPage();

        $headerHtml = '
        <table cellpadding="4" cellspacing="0" style="width:100%;border-bottom:2.5px solid ' . htmlspecialchars($headerColor, ENT_QUOTES) . ';">
          <tr>
            <td width="65%">
              <div style="font-size:14pt;font-weight:bold;color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';">' . htmlspecialchars($providerName, ENT_QUOTES) . '</div>
              <div style="font-size:9pt;color:#64748b;margin-top:2px;">' . htmlspecialchars($providerSubtitle, ENT_QUOTES) . '</div>
              ' . ($providerLicense !== '' ? '<div style="font-size:8pt;color:#475569;">شماره مجوز / پروانه: <b>' . htmlspecialchars($providerLicense, ENT_QUOTES) . '</b></div>' : '') . '
            </td>
            <td width="35%" align="left" style="font-size:8.5pt;color:#334155;line-height:1.6;">
              کد رهگیری کارنامه: <b dir="ltr" style="color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';">' . htmlspecialchars($repCode, ENT_QUOTES) . '</b><br>
              تاریخ انجام آزمون: <b>' . htmlspecialchars($issueDateFa, ENT_QUOTES) . '</b><br>
              ' . ($isAdmin ? '<span style="color:#4338ca;font-weight:bold;">[نسخه کامل بالینی مدیریت]</span>' : '<span style="color:#059669;font-weight:bold;">[نسخه معتبر مراجع]</span>') . '
            </td>
          </tr>
        </table>';

        $demographicsHtml = '
        <br><br>
        <table cellpadding="6" cellspacing="0" style="width:100%;background-color:#f8fafc;border:1px solid #cbd5e1;font-size:9pt;line-height:1.7;">
          <tr style="background-color:#f1f5f9;">
            <td colspan="4" style="font-weight:bold;color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';font-size:10pt;">
              مشخصات مراجع و اطلاعات آزمون روان‌سنجی
            </td>
          </tr>
          <tr>
            <td width="25%"><b>نام و نام خانوادگی:</b></td>
            <td width="30%">' . htmlspecialchars($clientName, ENT_QUOTES) . '</td>
            <td width="20%"><b>عنوان آزمون:</b></td>
            <td width="25%"><b style="color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';">' . htmlspecialchars((string)$at['test_title'], ENT_QUOTES) . '</b></td>
          </tr>
          <tr>
            <td width="25%"><b>کد ملی مراجع:</b></td>
            <td width="30%" dir="ltr">' . htmlspecialchars($clientNationalCode, ENT_QUOTES) . '</td>
            <td width="20%"><b>گروه هدف:</b></td>
            <td width="25%">' . htmlspecialchars((string)($at['test_audience'] ?? 'بزرگسالان'), ENT_QUOTES) . '</td>
          </tr>
          <tr>
            <td width="25%"><b>جنسیت و سن:</b></td>
            <td width="30%">' . htmlspecialchars($clientGenderFa . ' (' . $clientAge . ')', ENT_QUOTES) . '</td>
            <td width="20%"><b>شماره تماس:</b></td>
            <td width="25%" dir="ltr">' . htmlspecialchars($clientMobile, ENT_QUOTES) . '</td>
          </tr>
          <tr>
            <td width="25%"><b>روانشناس ناظر:</b></td>
            <td width="30%">' . htmlspecialchars($doctorName, ENT_QUOTES) . '</td>
            <td width="20%"><b>زمان ثبت نهایی:</b></td>
            <td width="25%">' . htmlspecialchars($completedFa, ENT_QUOTES) . '</td>
          </tr>
        </table>';

        $scalesHtml = '';
        if (!empty($results)) {
            $bandMap = [
                'very_low'  => ['خیلی پایین', '#0284c7', '#e0f2fe'],
                'low'       => ['پایین', '#059669', '#dcfce7'],
                'medium'    => ['متعادل', '#7c3aed', '#f3e8ff'],
                'high'      => ['بالا', '#d97706', '#fef3c7'],
                'very_high' => ['خیلی بالا', '#dc2626', '#fee2e2'],
            ];

            $scalesHtml .= '
            <br><br>
            <h4 style="font-size:11pt;color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';margin-bottom:6px;">
              جدول نمرات تراز، نمرات استن و رتبه‌های درصدی مقیاس‌های آزمون
            </h4>
            <table cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:8.5pt;text-align:center;">
              <tr style="background-color:#f1f5f9;font-weight:bold;color:#1e293b;">
                <th width="32%" style="border:1px solid #cbd5e1;text-align:right;">عنوان مقیاس</th>
                <th width="12%" style="border:1px solid #cbd5e1;">نمره خام</th>
                <th width="12%" style="border:1px solid #cbd5e1;">نمره تراز T</th>
                <th width="12%" style="border:1px solid #cbd5e1;">نمره استن</th>
                <th width="14%" style="border:1px solid #cbd5e1;">درصد</th>
                <th width="18%" style="border:1px solid #cbd5e1;">وضعیت</th>
              </tr>';

            foreach ($results as $r) {
                $bKey = (string)($r['band'] ?? 'medium');
                $bInfo = $bandMap[$bKey] ?? [$bKey, '#64748b', '#f1f5f9'];
                $pct = (float)($r['percent'] ?? 0);

                $scalesHtml .= '
                <tr>
                  <td align="right" style="border:1px solid #e2e8f0;"><b>' . htmlspecialchars((string)($r['title_fa'] ?? $r['scale_code']), ENT_QUOTES) . '</b></td>
                  <td style="border:1px solid #e2e8f0;">' . fa_num((string)($r['raw_score'] ?? '—')) . '</td>
                  <td style="border:1px solid #e2e8f0;">' . fa_num((string)($r['t_score'] ?? '—')) . '</td>
                  <td style="border:1px solid #e2e8f0;">' . fa_num((string)($r['sten_score'] ?? '—')) . '</td>
                  <td style="border:1px solid #e2e8f0;">' . fa_num((string)round($pct)) . '٪</td>
                  <td style="border:1px solid #e2e8f0;background-color:' . $bInfo[2] . ';color:' . $bInfo[1] . ';font-weight:bold;">' . htmlspecialchars($bInfo[0], ENT_QUOTES) . '</td>
                </tr>';
            }
            $scalesHtml .= '</table>';
        }

        $interventionsHtml = '';
        if ($isAdmin && !empty($results)) {
            $interventionsHtml .= '
            <br><br>
            <table cellpadding="8" cellspacing="0" style="width:100%;background-color:#eff6ff;border:1.5px solid #93c5fd;font-size:8.5pt;line-height:1.7;">
              <tr>
                <td style="font-weight:bold;color:#1e3a8a;font-size:10pt;">
                  🛡️ راهنمای مداخلهٔ بالینی و پروتکل شواهدمحور (محرمانه — منحصراً برای ناظر و روانشناس)
                </td>
              </tr>
              <tr>
                <td>
                  این بخش منحصراً در نسخهٔ مدیریتی درج شده و در کارنامهٔ قابل دانلود مراجع پنهان گردیده است. توصیه می‌شود در صورت قرارگیری نمرات در طیف‌های بحرانی (بسیار بالا یا بسیار پایین)، پروتکل‌های مداخله فردی و ارزیابی‌های تشخیصی ساختاریافته (SCID-5) در جلسات حضوری پیگیری شود.
                </td>
              </tr>
            </table>';
        }

        $attestationHtml = '';
        if ($showSignature) {
            $attestationHtml = '
            <br><br>
            <table cellpadding="6" cellspacing="0" style="width:100%;border-top:1.5px dashed #cbd5e1;font-size:8.5pt;line-height:1.6;">
              <tr>
                <td width="60%" style="color:#64748b;">
                  <b>نشانی و اطلاعات تماس مرکز:</b><br>
                  ' . ($providerPhone !== '' ? 'تلفن تماس: ' . htmlspecialchars($providerPhone, ENT_QUOTES) . ' | ' : '') . '
                  سامانه آنلاین: ' . htmlspecialchars($providerWebsite, ENT_QUOTES) . '<br>
                  <i>' . htmlspecialchars($disclaimer, ENT_QUOTES) . '</i>
                </td>
                <td width="40%" align="center" style="border:1px solid #e2e8f0;background-color:#fcfdff;">
                  <div style="font-weight:bold;color:#1e293b;">' . htmlspecialchars($doctorName, ENT_QUOTES) . '</div>
                  <div style="font-size:7.5pt;color:#64748b;">' . htmlspecialchars($doctorTitle, ENT_QUOTES) . '</div>
                  ' . ($doctorLicense !== '' ? '<div style="font-size:7.5pt;color:#475569;">شماره نظام روانشناسی: ' . htmlspecialchars($doctorLicense, ENT_QUOTES) . '</div>' : '') . '
                  <div style="font-size:7.5pt;color:#94a3b8;margin-top:20px;">امضا و مهر رسمی ناظر آزمون</div>
                </td>
              </tr>
            </table>';
        }

        $fullHtml = $headerHtml . $demographicsHtml . $scalesHtml . $interventionsHtml . $attestationHtml;
        @$pdf->writeHTML($fullHtml, true, false, true, false, '');

        $pdfOutput = $pdf->Output('', 'S');
        if (empty($pdfOutput) || !str_starts_with($pdfOutput, '%PDF-')) {
            throw new \RuntimeException('خروجی باینری نامعتبر از موتور TCPDF دریافت شد.');
        }

        return $pdfOutput;
    }

    /**
     * سازوکار اضطراری در صورت عدم دسترسی به هر دو موتور (Fallback)
     */
    private static function buildFallbackPdf(int $attemptId, bool $isAdmin, string $reason): string
    {
        $at = DB::one('SELECT a.*, t.title as test_title, u.first_name, u.last_name, u.mobile, u.national_code
                       FROM ' . DB::t('attempts') . ' a
                       LEFT JOIN ' . DB::t('tests') . ' t ON t.id = a.test_id
                       LEFT JOIN ' . DB::t('users') . ' u ON u.id = a.user_id
                       WHERE a.id = ?', [$attemptId]);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('فریا روان');
        $pdf->SetTitle('کارنامه ارزیابی روان‌شناختی');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->setRTL(true);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->AddPage();

        $clientName = trim(($at['first_name'] ?? '') . ' ' . ($at['last_name'] ?? '')) ?: 'مراجع گرامی';
        $testTitle = (string)($at['test_title'] ?? 'آزمون روان‌شناختی');
        $repCode = (string)($at['rep_code'] ?? ('FR-' . $attemptId));

        $html = '<div style="text-align:center;font-size:16pt;font-weight:bold;color:#1e3a6e;">مرکز خدمات روان‌شناختی فریا</div>'
              . '<div style="text-align:center;font-size:11pt;color:#64748b;margin-top:4px;">کارنامه رسمی ارزیابی روان‌شناختی (نسخه پشتیبان استاندارد A4)</div>'
              . '<hr style="margin:12px 0;border:0;border-top:2px solid #1e3a6e;">'
              . '<table cellpadding="6" style="width:100%;border:1px solid #cbd5e1;background-color:#f8fafc;font-size:10pt;">'
              . '<tr><td><b>نام مراجع:</b> ' . htmlspecialchars($clientName, ENT_QUOTES) . '</td>'
              . '<td><b>عنوان آزمون:</b> ' . htmlspecialchars($testTitle, ENT_QUOTES) . '</td></tr>'
              . '<tr><td><b>کد رهگیری:</b> ' . htmlspecialchars($repCode, ENT_QUOTES) . '</td>'
              . '<td><b>وضعیت:</b> معتبر و ثبت‌شده در سامانه</td></tr>'
              . '</table><br><br>'
              . '<div style="padding:12px;border:1px solid #e2e8f0;background-color:#fff;font-size:9.5pt;line-height:2;">'
              . 'ارزیابی مراجع محترم با موفقیت در سامانه تخصصی فریا روان پردازش گردیده است. برای مشاهده جزئیات ریزمقیاس‌ها و تفسیر بالینی می‌توانید با سامانه آنلاین یا کلینیک در ارتباط باشید.'
              . '</div>';

        @$pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('', 'S');
    }
}
