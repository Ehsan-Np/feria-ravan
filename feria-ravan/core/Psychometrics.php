<?php
/**
 * ============================================================================
 *  موتور روان‌سنجی پیشرفته فریا روان (Psychometrics & Validity Engine)
 *  ─ هنجاریابی جامعه نرمال: محاسبهٔ T-Score (میانگین ۵۰، انحراف معیار ۱۰)
 *  ─ نمرات اشل ده‌درجه‌ای (Sten Score ۱ تا ۱۰) و رتبهٔ درصدی جامعه (Percentile)
 *  ─ سنجش اعتبار روان‌سنجی و شاخص‌های تحریف (Validity & Careless Responding)
 * ============================================================================
 */
declare(strict_types=1);

final class Psychometrics
{
    /** هنجارهای پایهٔ ابعاد برای جامعهٔ نرمال (میانگین و انحراف معیار نظری/بومی) */
    private static array $NORMS = [
        // DISC: D, I, S, C (نمرات نرمالیزه ۰ تا ۱۰۰)
        'disc' => [
            'D' => ['m' => 48.0, 'sd' => 16.0, 'fa' => 'سلطه‌گری و قاطعیت (D)'],
            'I' => ['m' => 52.0, 'sd' => 15.0, 'fa' => 'تأثیرگذاری و ارتباط (I)'],
            'S' => ['m' => 54.0, 'sd' => 14.0, 'fa' => 'ثبات و شکیبایی (S)'],
            'C' => ['m' => 50.0, 'sd' => 15.0, 'fa' => 'دقت و وظیفه‌شناسی (C)'],
        ],
        // NEO-FFI پنج عامل اصلی
        'neo' => [
            'N' => ['m' => 48.0, 'sd' => 15.0, 'fa' => 'روان‌رنجورخویی (N)'],
            'E' => ['m' => 52.0, 'sd' => 14.0, 'fa' => 'برون‌گرایی (E)'],
            'O' => ['m' => 54.0, 'sd' => 13.0, 'fa' => 'گشودگی به تجربه (O)'],
            'A' => ['m' => 58.0, 'sd' => 12.0, 'fa' => 'سازگاری و همدلی (A)'],
            'C' => ['m' => 56.0, 'sd' => 14.0, 'fa' => 'وظیفه‌شناسی (C)'],
        ],
        // هوش‌های چندگانه گاردنر
        'gardner-mi' => [
            'ling'   => ['m' => 55.0, 'sd' => 15.0, 'fa' => 'هوش زبانی و کلامی'],
            'logic'  => ['m' => 52.0, 'sd' => 16.0, 'fa' => 'هوش ریاضی و منطقی'],
            'spatial'=> ['m' => 50.0, 'sd' => 15.0, 'fa' => 'هوش فضایی و تصویری'],
            'body'   => ['m' => 48.0, 'sd' => 16.0, 'fa' => 'هوش حرکتی و بدنی'],
            'musical'=> ['m' => 46.0, 'sd' => 17.0, 'fa' => 'هوش موسیقیایی'],
            'inter'  => ['m' => 58.0, 'sd' => 14.0, 'fa' => 'هوش بین‌فردی'],
            'intra'  => ['m' => 56.0, 'sd' => 14.0, 'fa' => 'هوش درون‌فردی'],
            'nature' => ['m' => 47.0, 'sd' => 16.0, 'fa' => 'هوش طبیعت‌گرایی'],
            'exist'  => ['m' => 53.0, 'sd' => 15.0, 'fa' => 'هوش وجودی'],
        ],
    ];

    /**
     * استانداردسازی هنجاری نتایج آزمون (محاسبه T-Score، Sten و Percentile)
     */
    public static function standardizeResults(int $attemptId, string $engine, string $slug): void
    {
        $rows = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$attemptId]);
        if (!$rows) return;

        foreach ($rows as $r) {
            $code = (string)$r['scale_code'];
            if ($code === 'overall') {
                $m = 50.0; $sd = 15.0;
            } else {
                $norm = self::$NORMS[$slug][$code] ?? self::$NORMS[$engine][$code] ?? null;
                $m  = (float)($norm['m'] ?? 50.0);
                $sd = (float)($norm['sd'] ?? 15.0);
            }
            if ($sd <= 0.1) $sd = 15.0;

            $pct = (float)$r['percent'];
            $z   = ($pct - $m) / $sd;

            // نمره T: میانگین ۵۰، انحراف معیار ۱۰ (برش خورده بین ۲۰ تا ۸۰)
            $tScore = round(max(20.0, min(80.0, 50.0 + (10.0 * $z))), 1);

            // نمره Sten: بازهٔ ۱ تا ۱۰ (میانگین ۵٫۵، انحراف معیار ۲)
            $sten = (int)max(1, min(10, (int)round(5.5 + (2.0 * $z))));

            // رتبه درصدی نرمال (Percentile)
            $p = 0.5 * (1.0 + self::erf($z / sqrt(2.0))) * 100.0;
            $percentile = round(max(1.0, min(99.0, $p)), 1);

            DB::update('results', [
                't_score'    => $tScore,
                'sten_score' => $sten,
                'percentile' => $percentile,
            ], 'id=?', [$r['id']]);
        }
    }

    /**
     * سنجش اعتبار روان‌سنجی آزمون (سرعت پاسخ‌دهی، یکنواختی، سبک افراطی، تسلیم)
     */
    public static function evaluateValidity(array $at, array $answers, int $questionCount): array
    {
        $penalties = 0;
        $flags     = [];

        // ۱. سرعت و گام پاسخ‌دهی (Pacing & Speed)
        $duration = (int)($at['duration_sec'] ?? 0);
        if ($duration <= 0 && !empty($at['started_at']) && !empty($at['completed_at'])) {
            $duration = max(0, strtotime((string)$at['completed_at']) - strtotime((string)$at['started_at']));
        }
        $qCount = max(1, $questionCount ?: count($answers));
        $secPerQ = $duration > 0 ? round($duration / $qCount, 1) : 0;

        $pacingStatus = 'normal';
        if ($duration > 0) {
            if ($secPerQ < 1.3 && $qCount >= 10) {
                $penalties += 25;
                $pacingStatus = 'rush';
                $flags[] = [
                    'code'  => 'rush',
                    'level' => 'warn',
                    'title' => 'پاسخ‌دهی پرشتاب',
                    'desc'  => 'میانگین زمان برای هر پرسش کمتر از ۱٫۵ ثانیه بوده است؛ احتمال عدم تمرکز کافی روی عبارات وجود دارد.',
                ];
            } elseif ($secPerQ > 45 && $qCount >= 10) {
                $pacingStatus = 'slow';
                $flags[] = [
                    'code'  => 'slow',
                    'level' => 'info',
                    'title' => 'تأمل طولانی',
                    'desc'  => 'میانگین زمان پاسخ‌دهی نشان‌دهندهٔ تفکر بسیار عمیق یا توقف‌های متعدد بین سؤالات است.',
                ];
            }
        }

        // ۲. تنوع پاسخ‌ها و الگوی خطی (Straight-Lining & Low Variance)
        $values = [];
        $extremes = 0;
        $highChoice = 0;

        foreach ($answers as $a) {
            if ($a['value'] !== null && is_numeric($a['value'])) {
                $v = (float)$a['value'];
                $values[] = $v;
                if ($v === 1.0 || $v === 5.0 || $v === 6.0) $extremes++;
                if ($v >= 4.0) $highChoice++;
            }
        }

        $vCount = count($values);
        if ($vCount >= 12) {
            $mean = array_sum($values) / $vCount;
            $variance = 0.0;
            foreach ($values as $v) { $variance += pow($v - $mean, 2); }
            $sd = sqrt($variance / $vCount);

            // یکنواختی مطلق (انتخاب پشت سر هم یک گزینه)
            if ($sd < 0.28) {
                $penalties += 40;
                $flags[] = [
                    'code'  => 'straight_line',
                    'level' => 'danger',
                    'title' => 'الگوی پاسخ‌دهی یکدست (بدون تمایز)',
                    'desc'  => 'تقریباً تمامی پرسش‌ها با گزینه‌ای کاملاً مشابه پاسخ داده شده‌اند؛ تنوع پاسخ‌ها از نظر روان‌سنجی بسیار پایین است.',
                ];
            }

            // سبک پاسخ‌دهی افراطی (Extreme Response Style)
            $extremeRatio = $extremes / $vCount;
            if ($extremeRatio > 0.85 && $penalties < 30) {
                $penalties += 15;
                $flags[] = [
                    'code'  => 'extreme_style',
                    'level' => 'warn',
                    'title' => 'گرایش به پاسخ‌های قطبی و شدید',
                    'desc'  => 'بیش از ۸۵٪ انتخاب‌ها در دو انتهای طیف نمره‌گذاری ثبت شده‌اند که نشان‌دهندهٔ سبک ارزیابی صفر و صدی است.',
                ];
            }

            // سوگیری موافقت (Acquiescence)
            $highRatio = $highChoice / $vCount;
            if ($highRatio > 0.85 && $sd >= 0.28) {
                $penalties += 15;
                $flags[] = [
                    'code'  => 'acquiescence',
                    'level' => 'info',
                    'title' => 'سوگیری موافقت افراطی (Yea-Saying)',
                    'desc'  => 'تمایل بالا به تأیید تمامی گزینه‌ها در حیطه‌های مختلف مشاهده می‌شود.',
                ];
            }
        }

        // نمره نهایی اعتبار (۰ تا ۱۰۰)
        $validityScore = max(20, min(100, 100 - $penalties));
        $validityStatus = match (true) {
            $validityScore >= 80 => 'high',
            $validityScore >= 60 => 'acceptable',
            default              => 'caution',
        };

        $statusFa = match ($validityStatus) {
            'high'       => 'اعتبار کامل (پاسخ‌های هوشیارانه و پایا)',
            'acceptable' => 'اعتبار قابل قبول (تفسیر استاندارد)',
            default      => 'نیازمند بررسی محتاطانه بالینی',
        };

        $result = [
            'score'       => $validityScore,
            'status'      => $validityStatus,
            'status_fa'   => $statusFa,
            'duration_sec'=> $duration,
            'sec_per_q'   => $secPerQ,
            'pacing'      => $pacingStatus,
            'flags'       => $flags,
            'evaluated_at'=> date('Y-m-d H:i:s'),
        ];

        // ذخیره در جدول attempts
        DB::update('attempts', [
            'validity_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
        ], 'id=?', [$at['id']]);

        return $result;
    }

    /** تقریب ریاضی خطای گوس (erf) */
    private static function erf(float $x): float
    {
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;

        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);
        return $sign * $y;
    }
}
