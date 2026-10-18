<?php
/**
 * ============================================================================
 *  موتور بومی تولید فایل کارنامه روان‌شناختی (PDF Generator Engine — نسخهٔ ۴٫۹٫۱)
 *  ─ تولید مستقیم و سروری فایل معتبر باینری PDF با استاندارد بین‌المللی ISO A4
 *  ─ پشتیبانی بومی از قلم‌های یونیکد و جهت‌گیری راست‌به‌چپ (RTL) زبان فارسی
 *  ─ سربرگ رسمی کلینیک، کادر مشخصات هویتی مراجع، کد رهگیری و تاریخ شمسی
 *  ─ جدول نمرات تراز، نمرات استن، رتبه‌های درصدی و نمودارهای میله‌ای رنگی وکتور
 *  ─ تحلیل جامع روان‌سنجی، تفکیک دسترسی مدیر و حفظ محرمانگی پروتکل‌های بالینی
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
     * @return string بایت‌های خام فایل PDF
     */
    public static function build(int $attemptId, bool $isAdmin = false): string
    {
        try {
            return self::doBuild($attemptId, $isAdmin);
        } catch (\Throwable $e) {
            error_log('CarnetPdfBuilder primary build failed: ' . $e->getMessage());
            return self::buildFallbackPdf($attemptId, $isAdmin, $e->getMessage());
        }
    }

    private static function doBuild(int $attemptId, bool $isAdmin = false): string
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

        // در صورت ناتمام بودن اما داشتن پاسخ، نمره‌گذاری خودکار انجام می‌شود
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
        $completedFa = fa_num(jdate('l j F Y — H:i', $completedTs));
        $issueDateFa = fa_num(jdate('j F Y', $completedTs));

        // اطلاعات مرکز و ناظر علمی از تنظیمات
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

        // استخراج نتایج مقیاس‌ها
        $results = Analyzer::getResults($attemptId);
        if (empty($results) && ($at['status'] ?? '') === 'completed') {
            try {
                Analyzer::scoreAttempt($attemptId);
                $results = Analyzer::getResults($attemptId);
            } catch (\Throwable $e) {}
        }

        // مقداردهی شیء TCPDF
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

        // ۱. سربرگ رسمی سند
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

        // ۲. جدول مشخصات هویتی مراجع و آزمون
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

        // ۳. جدول نمرات تراز و مقیاس‌ها به همراه نوارهای گرافیکی وکتور
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
            <table cellpadding="5" cellspacing="0" border="1" style="width:100%;border-collapse:collapse;border-color:#cbd5e1;font-size:8.5pt;text-align:center;">
              <thead>
                <tr style="background-color:#1e3a6e;color:#ffffff;font-weight:bold;">
                  <th width="8%">ردیف</th>
                  <th width="32%" align="right">مقیاس و سازه روان‌شناختی</th>
                  <th width="12%">نمره / درصد</th>
                  <th width="24%">نمودار میله‌ای تراز</th>
                  <th width="10%">نمره T</th>
                  <th width="14%">سطح ارزیابی</th>
                </tr>
              </thead>
              <tbody>';

            $idx = 1;
            foreach ($results as $res) {
                $pct = max(0, min(100, (int)($res['percent'] ?? 0)));
                $rem = 100 - $pct;
                $bandKey = (string)($res['band'] ?? 'medium');
                $bInfo = $bandMap[$bandKey] ?? ['متعادل', '#475569', '#f1f5f9'];
                $scaleTitle = (string)($res['title_fa'] ?: $res['scale_code']);

                $scalesHtml .= '
                <tr>
                  <td width="8%">' . fa_num($idx++) . '</td>
                  <td width="32%" align="right"><b>' . htmlspecialchars($scaleTitle, ENT_QUOTES) . '</b></td>
                  <td width="12%"><b style="color:' . $bInfo[1] . ';">' . fa_num($pct) . '٪</b></td>
                  <td width="24%" align="center">
                    <table cellpadding="0" cellspacing="0" style="width:100%;height:10px;background-color:#e2e8f0;border-radius:3px;">
                      <tr>
                        ' . ($pct > 0 ? '<td width="' . $pct . '%" bgcolor="' . $bInfo[1] . '" style="height:10px;"></td>' : '') . '
                        ' . ($rem > 0 ? '<td width="' . $rem . '%" bgcolor="#e2e8f0" style="height:10px;"></td>' : '') . '
                      </tr>
                    </table>
                  </td>
                  <td width="10%" dir="ltr">' . (!empty($res['t_score']) ? fa_num((string)$res['t_score']) : '—') . '</td>
                  <td width="14%" bgcolor="' . $bInfo[2] . '" style="color:' . $bInfo[1] . ';font-weight:bold;">' . htmlspecialchars($bInfo[0], ENT_QUOTES) . '</td>
                </tr>';
            }
            $scalesHtml .= '</tbody></table>';
        }

        // ۴. تحلیل تخصصی و روان‌سنجی آزمون
        $analysisHtml = '';
        try {
            $rawRep = ReportBuilder::build($at, $targetUser, 'carnet', [
                'print'   => true,
                'isAdmin' => $isAdmin,
            ]);

            // اعمال فیلتر سخت‌گیرانه برای حذف راهنمای مداخله بالینی در صورت عدم دسترسی مدیر
            $filteredRep = fr_filter_clinical_interventions($rawRep, $isAdmin);

            // تمیزسازی اسکریپت‌ها، تگ‌های canvas و متغیرهای نامناسب برای رندر PDF
            $cleanRep = preg_replace("#<script(.*?)>(.*?)</script>#is", '', $filteredRep);
            $cleanRep = preg_replace("#<canvas(.*?)>(.*?)</canvas>#is", '', $cleanRep);
            $cleanRep = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $cleanRep);

            // استایل‌دهی کارت‌های داخلی برای TCPDF
            $cleanRep = str_replace('class="fr-card"', 'style="background-color:#ffffff;border:1px solid #e2e8f0;padding:10px;margin-bottom:12px;"', $cleanRep);
            $cleanRep = str_replace('class="fr-chip', 'style="display:inline-block;padding:2px 6px;border-radius:4px;font-size:8pt;', $cleanRep);

            $analysisHtml = '
            <br><br>
            <h4 style="font-size:11pt;color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';margin-bottom:6px;">
              تحلیل تخصصی نتایج و تفاسیر روان‌شناختی
            </h4>
            <div style="font-size:9pt;line-height:1.9;color:#1e293b;">
              ' . $cleanRep . '
            </div>';
        } catch (\Throwable $e) {
            error_log('CarnetPdfBuilder analysis error: ' . $e->getMessage());
            $analysisHtml = '<p style="color:#b91c1c;">تفسیر تحلیلی این آزمون در دسترس است.</p>';
        }

        // ۵. تأییدیه، امضا، استعلام اصالت و سلب مسئولیت
        $footerHtml = '
        <br><br>
        <table cellpadding="6" cellspacing="0" style="width:100%;border:1px solid #cbd5e1;background-color:#f8fafc;font-size:8.5pt;">
          <tr>
            <td width="60%" style="line-height:1.8;">
              <b style="color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';font-size:9.5pt;">اصالت‌سنجی رسمی کارنامه</b><br>
              کد رهگیری اختصاصی: <b dir="ltr" style="color:#0f172a;">' . htmlspecialchars($repCode, ENT_QUOTES) . '</b><br>
              سامانه استعلام اصالت: <span dir="ltr" style="color:#2563eb;">' . htmlspecialchars($providerWebsite, ENT_QUOTES) . '</span><br>
              ' . ($providerPhone !== '' ? 'شماره تماس کلینیک: ' . htmlspecialchars($providerPhone, ENT_QUOTES) . '<br>' : '') . '
            </td>
            <td width="40%" align="center" style="border-right:1px dashed #cbd5e1;line-height:1.7;">
              <b style="color:' . htmlspecialchars($headerColor, ENT_QUOTES) . ';">تأییدیه ناظر علمی و روانشناس آزمون</b><br>
              <div style="margin-top:20px;color:#64748b;font-size:8pt;">[امضا و مهر معتبر ناظر بالینی]</div>
              <b>' . htmlspecialchars($doctorName, ENT_QUOTES) . '</b><br>
              <span style="font-size:8pt;color:#64748b;">' . htmlspecialchars($doctorTitle, ENT_QUOTES) . ($doctorLicense !== '' ? ' (نظام: ' . htmlspecialchars($doctorLicense, ENT_QUOTES) . ')' : '') . '</span>
            </td>
          </tr>
        </table>
        ' . ($disclaimer !== '' ? '
        <div style="margin-top:10px;padding:6px 10px;background-color:#fffbeb;border:1px solid #fef3c7;border-radius:4px;font-size:7.5pt;color:#92400e;line-height:1.8;">
          <b>یادداشت بالینی و سلب مسئولیت:</b> ' . htmlspecialchars($disclaimer, ENT_QUOTES) . '
        </div>' : '');

        $fullDoc = $headerHtml . $demographicsHtml . $scalesHtml . $analysisHtml . $footerHtml;

        // فیلتر نویسه‌های ایموجی که در فونت وکتور وجود ندارند تا از هشدار TCPDF پیشگیری شود
        $fullDoc = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $fullDoc);
        $fullDoc = str_replace(['🎯', '📊', '🧠', '🖨️', '📥', '✅', '⚠️', '🛡️', '🔒', '📄', '🔏', '🌟', '🌿', '⚖️', '🥱', '🌪'], '', $fullDoc);

        @$pdf->writeHTML($fullDoc, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

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
