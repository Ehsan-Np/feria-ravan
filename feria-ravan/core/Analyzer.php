<?php
/**
 * ============================================================================
 *  موتور تحلیل روانشناسی فریا روان
 *  ─ سه موتور نمره‌گذاری: DISC (بیشترین/کمترین)، یانگ-طرحواره (لیکرت ۶)، NEO (لیکرت ۵)
 *  ─ تعیین باند نمره (خیلی‌پایین … خیلی‌بالا)
 *  ─ خروجی نتایج آماده برای گزارش‌ساز (ReportBuilder)
 *  تمامی فرمول‌ها بر اساس آخرین روش‌های نمره‌گذاری رسمی هر ابزار پیاده شده‌اند.
 * ============================================================================
 */
declare(strict_types=1);

final class Analyzer
{
    /* -----------------------------------------------------------------------
     | باندهای کلی نمره (۰–۱۰۰)
     * --------------------------------------------------------------------*/
    public const BANDS = ['very_low','low','medium','high','very_high'];

    public static function bandForPercent(float $p): string
    {
        return match (true) {
            $p < 20 => 'very_low',
            $p < 40 => 'low',
            $p < 60 => 'medium',
            $p < 80 => 'high',
            default => 'very_high',
        };
    }

    /* باندهای یانگ بر اساس میانگین ۱–۶ (منطبق بر کلید طرحواره‌درمانی) */
    public static function bandForYsqMean(float $mean): string
    {
        return match (true) {
            $mean < 1.8 => 'very_low',
            $mean < 2.6 => 'low',
            $mean < 3.4 => 'medium',
            $mean < 4.6 => 'high',
            default     => 'very_high',   // ۴٫۶ به بالا = طرحوارهٔ فعال/بالینی
        };
    }

    /* -----------------------------------------------------------------------
     | نمرهگذاری یک جلسهٔ تست روانشناسی و ذخیره در جدول results
     * --------------------------------------------------------------------*/
    public static function scoreAttempt(int $attemptId): bool
    {
        $at = DB::one('SELECT a.*, t.engine FROM ' . DB::t('attempts') . ' a
                       JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id WHERE a.id=?', [$attemptId]);
        if (!$at) return false;

        $answers = DB::all('SELECT * FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$attemptId]);
        if (!$answers) return false;

        // پاک‌سازی نتایج قبلی (در صورت نمرهگذاری مجدد)
        DB::run('DELETE FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$attemptId]);

        match ($at['engine']) {
            'disc'   => self::scoreDisc($at, $answers),
            'ysq'    => self::scoreLikertScales($at, $answers, 6, true),
            'neo'    => self::scoreLikertScales($at, $answers, 5, false),
            'screen' => self::scoreScreening($at, $answers, false),   // اضطراب/افسردگی (باند ۵سطحی بالینی)
            'jobsat' => self::scoreScreening($at, $answers, true),    // رضایت شغلی (باند ۳سطحی + اقلام معکوس)
            'mslikert' => self::scoreMsLikert($at, $answers),         // گاردنر ۹هوش / هوش هیجانی شوت (نسخهٔ ۱٫۸)
            default => throw new RuntimeException('موتور ناشناخته: ' . $at['engine']),
        };

        // فاز ۱: هنجاریابی روان‌سنجی (T-Scores، Sten، Percentile) + ارزیابی اعتبار پاسخ‌ها
        require_once __DIR__ . '/Psychometrics.php';
        Psychometrics::standardizeResults($attemptId, (string)$at['engine'], (string)($at['slug'] ?? ''));
        Psychometrics::evaluateValidity($at, $answers, (int)($at['question_count'] ?? count($answers)));

        DB::update('attempts', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')],
                   'id=?', [$attemptId]);
        return true;
    }

    /* -----------------------------------------------------------------------
     | DISC — قالب mostleast: هر سوال ۴ وصف؛ انتخاب «بیشترین» و «کمترین»
     |  نمرهٔ هر بُعد = (تعداد بیشترین) − (تعداد کمترین) + جبران
     * --------------------------------------------------------------------*/
    private static function scoreDisc(array $at, array $answers): void
    {
        // کلیدها: فرض meta در fr_scoring_keys: {"letters":["D","I","S","C"]} به ترتیب ۴ گزینه
        $keys = [];
        foreach (DB::all('SELECT question_id, meta FROM ' . DB::t('scoring_keys') . ' WHERE test_id=?', [$at['test_id']]) as $k) {
            $keys[$k['question_id']] = json_decode($k['meta'] ?? '{}', true);
        }

        $most = ['D'=>0,'I'=>0,'S'=>0,'C'=>0];
        $least = $most;
        $n = 0;
        foreach ($answers as $a) {
            $letters = $keys[$a['question_id']]['letters'] ?? null;
            if (!$letters) continue;
            if ($a['most_idx'] !== null && isset($letters[$a['most_idx']]))  { $most[$letters[$a['most_idx']]]++; }
            if ($a['least_idx'] !== null && isset($letters[$a['least_idx']])) { $least[$letters[$a['least_idx']]]++; }
            $n++;
        }

        // نمرهٔ خام هر بُعد: most − least ، نرمالیزه به ۰–۱۰۰
        // + ذخیرهٔ شمارش بیشترین/کمترین برای نمودار دوگانهٔ «طبیعی/تطبیق‌یافته» (نسخهٔ ۱٫۶)
        $pct = [];
        foreach (['D','I','S','C'] as $dim) {
            $raw     = $most[$dim] - $least[$dim];               // بازهٔ نظری: −n … +n
            $percent = $n > 0 ? round((($raw + $n) / (2 * $n)) * 100, 2) : 0.0;
            $pct[$dim] = (int)round($percent);
            DB::insert('results', [
                'attempt_id' => $at['id'], 'scale_code' => $dim,
                'raw_score'  => $raw, 'max_score' => $n, 'percent' => $percent,
                'band'       => self::bandForPercent($percent),
                'mean_score' => $n > 0 ? round($most[$dim] / $n * 5, 3) : 0, // شدت تمایل
                'raw_extra'  => json_encode(['most'=>$most[$dim], 'least'=>$least[$dim], 'n'=>$n], JSON_UNESCAPED_UNICODE),
            ]);
        }

        // کد الگو: با موتور تشخیص الگوی سورس مادر (تک‌بُعدی/دو‌بُعدی/متعادل + کد ۴ رقمی)
        require_once __DIR__ . '/analysis-texts.php';
        // نسخهٔ ۲٫۶: پیش از بررسی function_exists باید بانک متون بارگذاری شده باشد؛
        // وگرنه در مسیر CLI/کرون، تابع تشخیص تعریف‌نشده می‌ماند و همهٔ الگوها «DI» ثبت می‌شد.
        fr_txt('FERIA_DISC_PATTERNS');
        $pattern = function_exists('feria_disc_detect_pattern')
            ? feria_disc_detect_pattern($pct)
            : implode('', array_slice(array_keys($pct + ['D'=>0,'I'=>0,'S'=>0,'C'=>0]), 0, 2));
        // رعایت اولویت: تکیل الگوی معتبر کتابخانه؛ در غیر اینصورت دوبُعد برتر قدیمی
        $patterns  = (array)(fr_txt('FERIA_DISC_PATTERNS') ?: []);
        if ($pattern === 'BA' || !isset($patterns[$pattern])) {
            arsort($most);
            $pattern = implode('', array_slice(array_keys($most), 0, 2));
        }
        $code4 = function_exists('feria_disc_pattern_code') ? feria_disc_pattern_code($pct) : '';
        // محیط پایه را جدا نگه می‌داریم تا اجرای دوبارهٔ نمره‌دهی داده را تکراری اضافه نکند
        $baseCtx = trim(explode('|', (string)$at['context'])[0]) ?: 'work';
        DB::update('attempts', ['context' => $baseCtx . '|pattern:' . $pattern . ($code4 !== '' ? '|code:' . $code4 : '')], 'id=?', [$at['id']]);
    }

    /* -----------------------------------------------------------------------
     | یانگ & NEO — لیکرت با نگاشت سؤال→مقیاس و اقلام معکوس
     |  ysq: میانگینِ هر طرحواره + باند یانگ   |   neo: مجموعِ عامل (۶۰ آیتم)
     * --------------------------------------------------------------------*/
    private static function scoreLikertScales(array $at, array $answers, int $scaleMax, bool $useYsqBands): void
    {
        // کلیدها ممکن است چند ردیف به ازای هر سؤال باشند
        $keyRows = DB::all('SELECT question_id, scale_code, reverse, weight FROM ' . DB::t('scoring_keys') . ' WHERE test_id=?', [$at['test_id']]);
        $map = [];
        foreach ($keyRows as $k) { $map[$k['question_id']][] = $k; }

        $sum = []; $cnt = []; $max = [];
        foreach ($answers as $a) {
            $v = en_num((string)$a['value']);
            if (!is_numeric($v)) continue;
            $v = (float)$v;
            foreach ($map[$a['question_id']] ?? [] as $k) {
                $score = $v;
                if ($k['reverse']) { $score = ($scaleMax + 1) - $v; }          // معکوس: m+1−v
                $score *= (float)$k['weight'];
                $c = $k['scale_code'];
                $sum[$c] = ($sum[$c] ?? 0) + $score;
                $cnt[$c] = ($cnt[$c] ?? 0) + 1;
                $max[$c] = ($max[$c] ?? 0) + ($scaleMax * (float)$k['weight']);
            }
        }

        foreach ($sum as $code => $s) {
            $mean    = $cnt[$code] ? $s / $cnt[$code] : 0.0;
            $percent = $max[$code] ? round($s / $max[$code] * 100, 2) : 0.0;
            DB::insert('results', [
                'attempt_id' => $at['id'], 'scale_code' => $code,
                'raw_score'  => round($s, 3), 'max_score' => $max[$code],
                'percent'    => $percent, 'mean_score' => round($mean, 3),
                'band'       => $useYsqBands ? self::bandForYsqMean($mean) : self::bandForPercent($percent),
            ]);
        }
    }

    /* -----------------------------------------------------------------------
     | غربالگرهای لیکرت ۶درجه‌ای (اضطراب/افسردگی/رضایت شغلی)
     |  ─ درصدِ تنظیم‌شده: (مجموع − تعداد) ÷ (۵×تعداد) → بازهٔ واقعی ۰ تا ۱۰۰
     |  ─ screen: باند ۵ سطحی بالینی (حداقل/خفیف/متوسط/متوسط‌روبه‌شدید/شدید)
     |  ─ jobsat: باند ۳ سطحی (پایین<۵۰٪ / متوسط<۷۰٪ / بالا) + اقلام معکوس
     |  ─ ردیف تجمیعی overall هم محاسبه می‌شود (مبنای سربرگ گزارش)
     * --------------------------------------------------------------------*/
    private static function scoreScreening(array $at, array $answers, bool $threeBand): void
    {
        // نگاشت سؤال → مقیاس‌ها (با جهت معکوس برای jobsat)
        $keyRows = DB::all('SELECT question_id, scale_code, reverse, weight FROM ' . DB::t('scoring_keys') .
                           ' WHERE test_id=?', [$at['test_id']]);
        $map = [];
        foreach ($keyRows as $k) { $map[$k['question_id']][] = $k; }

        $sum = []; $cnt = [];
        $oSum = 0.0; $oCnt = 0;
        foreach ($answers as $a) {
            $v = en_num((string)$a['value']);
            if (!is_numeric($v)) continue;
            $v = (float)$v;
            if ($v < 1 || $v > 6) continue;                    // خارج از طیف = بی‌اعتبار
            foreach ($map[$a['question_id']] ?? [] as $k) {
                $score = $v;
                if ($k['reverse']) { $score = 7 - $v; }        // معکوس لیکرت ۶درجه‌ای
                $score *= (float)$k['weight'];
                $c = $k['scale_code'];
                // امتیاز تنظیم‌شده: کمینهٔ طیف (۱) از هر پاسخ کم می‌شود → ۰..۵
                $adj = ($score - (float)$k['weight']);
                $sum[$c] = ($sum[$c] ?? 0) + $adj;
                $cnt[$c] = ($cnt[$c] ?? 0) + 1;
                $oSum += $adj; $oCnt++;
            }
        }

        $rows = [];
        foreach ($sum as $code => $s) {
            $max = 5.0 * $cnt[$code];                            // سقف تنظیم‌شدهٔ هر مقیاس
            $percent = $max > 0 ? round($s / $max * 100, 2) : 0.0;
            $rows[] = [$code, round($s, 3), $max, $percent,
                       round(($s / max(1, $cnt[$code])) + 1, 3)]; // میانگین خام ۱..۶
        }
        if ($oCnt > 0) {
            $oMax = 5.0 * $oCnt;
            $rows[] = ['overall', round($oSum, 3), $oMax,
                       $oMax > 0 ? round($oSum / $oMax * 100, 2) : 0.0,
                       round($oSum / $oCnt + 1, 3)];
        }

        foreach ($rows as [$code, $raw, $max, $percent, $mean]) {
            $band = $threeBand
                ? ($percent < 50 ? 'very_low' : ($percent < 70 ? 'medium' : 'high'))
                : self::bandForPercent($percent);   // هم‌تراز با آستانه‌های بالینی ۲۰/۴۰/۶۰/۸۰
            DB::insert('results', [
                'attempt_id' => $at['id'], 'scale_code' => $code,
                'raw_score'  => $raw, 'max_score' => $max,
                'percent'    => $percent, 'mean_score' => $mean,
                'band'       => $band,
            ]);
        }
    }

    /* -----------------------------------------------------------------------
     | mslikert (نسخهٔ ۱٫۸) — لیکرت ۵درجه‌ای چندمقیاسی
     |  ─ گاردنر ۹ هوش (۹۰ آیتم) و هوش هیجانی شوت (۳۳ آیتم با اقلام معکوس)
     |  ─ هر مقیاس: مجموع → میانگین → درصد → باند ۵سطحی استاندارد
     |  ─ ردیف تجمیعی overall + سه مقیاس برتر/دو فرصت‌رشد در context جلسه
     * --------------------------------------------------------------------*/
    private static function scoreMsLikert(array $at, array $answers): void
    {
        $keyRows = DB::all('SELECT question_id, scale_code, reverse, weight FROM ' . DB::t('scoring_keys') .
                           ' WHERE test_id=?', [$at['test_id']]);
        $map = [];
        foreach ($keyRows as $k) { $map[$k['question_id']][] = $k; }

        $sum = []; $cnt = []; $max = [];
        foreach ($answers as $a) {
            $v = en_num((string)$a['value']);
            if (!is_numeric($v)) continue;
            $v = (float)$v;
            if ($v < 1 || $v > 5) continue;
            foreach ($map[$a['question_id']] ?? [] as $k) {
                $score = $v;
                if ($k['reverse']) { $score = 6 - $v; }            // معکوس لیکرت ۵درجه‌ای
                $score *= (float)$k['weight'];
                $c = $k['scale_code'];
                $sum[$c] = ($sum[$c] ?? 0) + $score;
                $cnt[$c] = ($cnt[$c] ?? 0) + 1;
                $max[$c] = ($max[$c] ?? 0) + (5 * (float)$k['weight']);
            }
        }

        $scalePct = [];
        foreach ($sum as $code => $s) {
            $mean    = $cnt[$code] ? $s / $cnt[$code] : 0.0;
            $percent = $max[$code] ? round($s / $max[$code] * 100, 2) : 0.0;
            $scalePct[$code] = $percent;
            DB::insert('results', [
                'attempt_id' => $at['id'], 'scale_code' => $code,
                'raw_score'  => round($s, 3), 'max_score' => $max[$code],
                'percent'    => $percent, 'mean_score' => round($mean, 3),
                'band'       => self::bandForPercent($percent),
            ]);
        }

        /* ردیف کلی (میانگین درصد مقیاس‌ها — مبنای سربرگ گزارش) */
        if ($scalePct) {
            $overall = round(array_sum($scalePct) / count($scalePct), 2);
            DB::insert('results', [
                'attempt_id' => $at['id'], 'scale_code' => 'overall',
                'raw_score'  => $overall, 'max_score' => 100,
                'percent'    => $overall, 'mean_score' => round($overall / 20, 3),
                'band'       => self::bandForPercent($overall),
            ]);
        }

        /* سه مقیاس برتر + دو مقیاس فرصت‌رشد — برای واکاوی کلینیکی گزارش */
        arsort($scalePct);
        $allCodes = array_keys($scalePct);
        $top3 = implode(',', array_slice($allCodes, 0, 3));
        $low2 = implode(',', array_slice($allCodes, -2));
        $baseCtx = trim((string)($at['context'] ?? ''), '|');
        DB::update('attempts', ['context' => $baseCtx . '|top:' . $top3 . '|low:' . $low2], 'id=?', [$at['id']]);
    }

    /* -----------------------------------------------------------------------
     | واکشی نتایج آمادهٔ نمایش (مرتب + عنوان فارسی + رنگ)
     * --------------------------------------------------------------------*/
    public static function getResults(int $attemptId): array
    {
        return DB::all('SELECT r.*, s.title_fa, s.color, s.sort_order
                        FROM ' . DB::t('results') . ' r
                        LEFT JOIN ' . DB::t('test_scales') . ' s
                          ON s.test_id = (SELECT test_id FROM ' . DB::t('attempts') . ' WHERE id=r.attempt_id)
                         AND s.code = r.scale_code
                        WHERE r.attempt_id=? ORDER BY s.sort_order', [$attemptId]);
    }

    /** یافتن طرحواره‌های فعال (بالا/خیلی‌بالا) برای تحلیل یانگ */
    public static function activeSchemas(int $attemptId): array
    {
        return array_filter(self::getResults($attemptId),
            fn($r) => in_array($r['band'], ['high','very_high'], true));
    }

    /**
     * دادهٔ نمودار دوگانهٔ DISC — نسخهٔ ۱٫۶
     * ─ نمودار «تطبیق‌یافته» از انتخاب‌های «بیشترین» (چهره‌ای که به محیط نشان می‌دهید)
     * ─ نمودار «طبیعی» از انتخاب‌های «کمترین» (خویِ اصیل — چیزی که کمتر دوست دارید باشید)
     * ابتدا از raw_extra (ذخیره‌شده حین نمرهگذاری) می‌خواند؛ برای نتایج قدیمی از روی پاسخ‌ها بازمحاسبه می‌کند.
     * @return array ['most'=>['D'=>%…], 'least'=>['D'=>%…], 'n'=>int, 'combined'=>['D'=>%…]]
     */
    public static function discGraphData(int $attemptId): array
    {
        $rows = DB::all('SELECT scale_code, percent, raw_extra FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$attemptId]);
        $n = 0; $most = ['D'=>0,'I'=>0,'S'=>0,'C'=>0]; $least = $most; $comb = $most; $have = false;
        foreach ($rows as $r) {
            $c = $r['scale_code'];
            if (!isset($most[$c])) continue;
            $comb[$c] = (int)round((float)$r['percent']);
            $x = $r['raw_extra'] ? json_decode($r['raw_extra'], true) : null;
            if ($x && isset($x['most'], $x['least'], $x['n'])) {
                $most[$c]  = (int)$x['most'];
                $least[$c] = (int)$x['least'];
                $n         = max($n, (int)$x['n']);
                $have      = true;
            }
        }
        if (!$have) {
            // بازمحاسبه برای نتایج قدیمی (پیش از نسخهٔ ۱٫۶)
            $at = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE id=?', [$attemptId]);
            if (!$at) return ['most'=>$most,'least'=>$least,'n'=>0,'combined'=>$comb];
            $keys = [];
            foreach (DB::all('SELECT question_id, meta FROM ' . DB::t('scoring_keys') . ' WHERE test_id=?', [$at['test_id']]) as $k) {
                $keys[$k['question_id']] = json_decode($k['meta'] ?? '{}', true);
            }
            $n = 0;
            foreach (DB::all('SELECT * FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$attemptId]) as $a) {
                $letters = $keys[$a['question_id']]['letters'] ?? null;
                if (!$letters) continue;
                if ($a['most_idx'] !== null && isset($letters[$a['most_idx']]))   $most[$letters[$a['most_idx']]]++;
                if ($a['least_idx'] !== null && isset($letters[$a['least_idx']])) $least[$letters[$a['least_idx']]]++;
                $n++;
            }
        }
        $mp = []; $lp = [];
        foreach (['D','I','S','C'] as $d) {
            $mp[$d] = $n > 0 ? (int)round($most[$d] / max(1,$n) * 100) : 0;
            $lp[$d] = $n > 0 ? (int)round($least[$d] / max(1,$n) * 100) : 0;
        }
        return ['most'=>$mp, 'least'=>$lp, 'n'=>$n, 'combined'=>$comb];
    }
}
