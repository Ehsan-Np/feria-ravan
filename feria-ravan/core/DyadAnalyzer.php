<?php
/**
 * ============================================================================
 *  موتور سنجش تطابق دونفره و زوجین فریا روان (Dyadic & Couples Synergy Engine)
 *  ─ تحلیل ماتریس سازگاری، نقاط قوت هم‌افزا و کانون‌های اصطکاک رابطه
 *  ─ پروتکل گفت‌وگوی امن اختصاصی متناسب با تیپ رفتاری و شخصیتی دو طرف
 *  ─ پوشش آزمون‌های DISC، NEO، گاردنر و طرحواره‌های یانگ
 * ============================================================================
 */
declare(strict_types=1);

final class DyadAnalyzer
{
    /** عناوین فارسی نوع رابطه */
    public static array $REL_TYPES = [
        'couple'   => 'پارتنر عاطفی / نامزد',
        'spouse'   => 'همسر / زندگی مشترک',
        'coworker' => 'همکار / هم‌تیمی',
        'business' => 'شریک تجاری / کاری',
        'friend'   => 'دوست صمیمی / رفیق',
    ];

    /**
     * ایجاد پیوند دونفره جدید و تولید توکن دعوت
     */
    public static function createDyad(int $userId, int $attemptId, string $relType = 'couple'): array
    {
        $at = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE id=? AND user_id=? AND status="completed"', [$attemptId, $userId]);
        if (!$at) {
            return ['ok' => false, 'message' => 'جلسهٔ تست تکمیل‌شده یافت نشد.'];
        }

        $rel = array_key_exists($relType, self::$REL_TYPES) ? $relType : 'couple';
        $token = fr_token(16);

        $dyadId = (int)DB::insert('dyads', [
            'token'       => $token,
            'test_id'     => $at['test_id'],
            'user1_id'    => $userId,
            'attempt1_id' => $attemptId,
            'rel_type'    => $rel,
            'status'      => 'pending',
        ]);

        return [
            'ok'        => true,
            'dyad_id'   => $dyadId,
            'token'     => $token,
            'invite_url'=> SITE_URL . '/panel/dyad.php?token=' . $token,
        ];
    }

    /**
     * اتصال طرف دوم به پیوند با توکن
     */
    public static function joinDyad(string $token, int $user2Id, ?int $attempt2Id = null): array
    {
        $token = trim($token);
        $dyad = DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE token=?', [$token]);
        if (!$dyad) {
            return ['ok' => false, 'message' => 'پیوند دعوت نامعتبر یا منقضی شده است.'];
        }

        if ((int)$dyad['user1_id'] === $user2Id) {
            return ['ok' => false, 'message' => 'نمی‌توانید با حساب کاربری خودتان پیوند تطابق بسازید.'];
        }

        // اگر شناسه آزمون مشخص نشده، آخرین تلاش تکمیل‌شده طرف دوم برای همین تست را برمی‌داریم
        if (!$attempt2Id) {
            $lastAt = DB::one('SELECT id FROM ' . DB::t('attempts') .
                ' WHERE user_id=? AND test_id=? AND status="completed" ORDER BY id DESC LIMIT 1',
                [$user2Id, $dyad['test_id']]);
            if ($lastAt) {
                $attempt2Id = (int)$lastAt['id'];
            }
        }

        if (!$attempt2Id) {
            // طرف دوم باید ابتدا آزمون را انجام دهد
            DB::update('dyads', ['user2_id' => $user2Id], 'id=?', [$dyad['id']]);
            return [
                'ok'          => true,
                'status'      => 'needs_test',
                'test_id'     => (int)$dyad['test_id'],
                'message'     => 'ابتدا آزمون را تکمیل کنید تا گزارش تطابق آماده شود.',
                'redirect_url'=> SITE_URL . '/panel/test-start.php?id=' . $dyad['test_id'] . '&dyad_token=' . $token,
            ];
        }

        DB::update('dyads', [
            'user2_id'    => $user2Id,
            'attempt2_id' => $attempt2Id,
            'status'      => 'ready',
        ], 'id=?', [$dyad['id']]);

        // تحلیل و محاسبهٔ فوری شاخص‌های تطابق
        self::analyze((int)$dyad['id']);

        return [
            'ok'        => true,
            'status'    => 'ready',
            'dyad_id'   => (int)$dyad['id'],
            'report_url'=> SITE_URL . '/panel/dyad-report.php?id=' . $dyad['id'],
        ];
    }

    /**
     * اجرای کامل تحلیل روان‌شناختی تطابق دونفره
     */
    public static function analyze(int $dyadId): ?array
    {
        $d = DB::one('SELECT d.*, t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine
                      FROM ' . DB::t('dyads') . ' d
                      JOIN ' . DB::t('tests') . ' t ON t.id=d.test_id
                      WHERE d.id=?', [$dyadId]);
        if (!$d || empty($d['attempt1_id']) || empty($d['attempt2_id'])) return null;

        $u1 = DB::one('SELECT id, first_name, last_name, mobile FROM ' . DB::t('users') . ' WHERE id=?', [$d['user1_id']]);
        $u2 = DB::one('SELECT id, first_name, last_name, mobile FROM ' . DB::t('users') . ' WHERE id=?', [$d['user2_id']]);

        $at1 = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE id=?', [$d['attempt1_id']]);
        $at2 = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE id=?', [$d['attempt2_id']]);

        $res1 = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$d['attempt1_id']]);
        $res2 = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$d['attempt2_id']]);

        $map1 = []; foreach ($res1 as $r) { $map1[$r['scale_code']] = $r; }
        $map2 = []; foreach ($res2 as $r) { $map2[$r['scale_code']] = $r; }

        $engine = (string)$d['test_engine'];
        $analysis = match ($engine) {
            'disc'  => self::analyzeDiscDyad($d, $u1, $u2, $at1, $at2, $map1, $map2),
            default => self::analyzeGenericDyad($d, $u1, $u2, $at1, $at2, $map1, $map2),
        };

        // ذخیره شاخص و خلاصه در دیتابیس
        DB::update('dyads', [
            'synergy_score' => $analysis['synergy_score'],
            'summary_json'  => json_encode($analysis, JSON_UNESCAPED_UNICODE),
            'status'        => 'ready',
        ], 'id=?', [$dyadId]);

        return $analysis;
    }

    /**
     * تحلیل تخصصی تطابق دونفره برای تست رفتارشناسی DISC
     */
    private static function analyzeDiscDyad(array $d, array $u1, array $u2, array $at1, array $at2, array $m1, array $m2): array
    {
        $dims = ['D' => 'سلطه‌گری و قاطعیت', 'I' => 'تأثیرگذاری و ارتباط', 'S' => 'ثبات و صبوری', 'C' => 'دقت و ساختاریافتگی'];
        $deltaMatrix = [];
        $totalDelta = 0;

        foreach ($dims as $code => $title) {
            $p1 = (int)round($m1[$code]['percent'] ?? 50);
            $p2 = (int)round($m2[$code]['percent'] ?? 50);
            $diff = abs($p1 - $p2);
            $totalDelta += $diff;

            $nature = match (true) {
                $diff <= 14 => 'هم‌پوشانی و درک شهودی مشترک (احساس راحتی متقابل)',
                $diff <= 32 => 'مکمل و تعادل‌بخش (پوشش نقاط کور یکدیگر)',
                default     => 'تضاد ادراکی و نیاز به پل ارتباطی آگاهانه',
            };

            $deltaMatrix[$code] = [
                'code'   => $code,
                'title'  => $title,
                'p1'     => $p1,
                'p2'     => $p2,
                'diff'   => $diff,
                'nature' => $nature,
            ];
        }

        // استخراج تیپ و الگوی دو طرف
        $pat1 = self::extractPattern($at1['context'] ?? '', $m1);
        $pat2 = self::extractPattern($at2['context'] ?? '', $m2);

        // محاسبهٔ نمره هم‌افزایی (Synergy Score 0-100)
        // همبستگی مثبت میانگین تعادل با جریمهٔ تصادم‌های پرخطر
        $avgDiff = $totalDelta / 4;
        $baseScore = 95 - ($avgDiff * 0.7);

        // تصادم‌های خاص DISC
        $clashFriction = 0;
        $synergyBonus  = 0;
        $frictionPoints = [];
        $synergyStrengths = [];

        $d1 = $deltaMatrix['D']['p1']; $d2 = $deltaMatrix['D']['p2'];
        $i1 = $deltaMatrix['I']['p1']; $i2 = $deltaMatrix['I']['p2'];
        $s1 = $deltaMatrix['S']['p1']; $s2 = $deltaMatrix['S']['p2'];
        $c1 = $deltaMatrix['C']['p1']; $c2 = $deltaMatrix['C']['p2'];

        // ۱. جنگ قدرت (Dual High D)
        if ($d1 >= 65 && $d2 >= 65) {
            $clashFriction += 12;
            $frictionPoints[] = [
                'area'  => 'کنترل و تصمیم‌گیری سریع',
                'title' => 'چالش بر سر سکان هدایت (Dual Dominance)',
                'desc'  => 'هر دو طرف تمایل ذاتی به تصمیم‌گیری سریع، قاطع و هدایت اوضاع دارند. در مواقع تنش ممکن است گفت‌وگو به میدان رقابت یا برد-باخت تبدیل شود.',
            ];
        } elseif (($d1 >= 65 && $s2 >= 65) || ($d2 >= 65 && $s1 >= 65)) {
            $synergyBonus += 10;
            $synergyStrengths[] = [
                'area'  => 'هدایت و آرامش',
                'title' => 'هم‌افزایی قاطعیت و پایداری (D + S)',
                'desc'  => 'یکی پیشران اقدام، حل مسئله و شجاعت تغییر است و دیگری سنگر آرامش، وفاداری و تثبیت دستاوردها؛ ترکیبی بسیار مقاوم در برابر بحران‌ها.',
            ];
            $frictionPoints[] = [
                'area'  => 'ریتم و سرعت اقدام',
                'title' => 'تفاوت گام حرکتی (عجله در برابر تأمل)',
                'desc'  => 'طرف با D بالا ممکن است صبوری طرف S را به «تعلل» تعبیر کند؛ در حالی که طرف S قاطعیت طرف D را گاهی «سخت‌گیرانه و تند» احساس می‌کند.',
            ];
        }

        // ۲. هیجان و جزئیات (High I + High C)
        if (($i1 >= 65 && $c2 >= 65) || ($i2 >= 65 && $c1 >= 65)) {
            $frictionPoints[] = [
                'area'  => 'سبک نگاه به واقعیت',
                'title' => 'شور و کل‌نگری در برابر منطق و جزئیات (I + C)',
                'desc'  => 'طرف I بر مبنای احساس، ارتباط و تصویر بزرگ سخن می‌گوید، اما طرف C شواهد منطقی، عدد و داده‌های دقیق می‌طلبد. نیاز به درک ارزش هر دو رویکرد وجود دارد.',
            ];
            $synergyStrengths[] = [
                'area'  => 'ایده و اجرا',
                'title' => 'خلاقیت در کنار کیفیت و دقت ساختاری',
                'desc'  => 'طرف برون‌گرا روابط و ایده‌های جدید می‌سازد و طرف دقیق، برنامه‌ریزی بی‌نقص و پایش بودجه و کیفیت را مدیریت می‌کند.',
            ];
        }

        // ۳. ارتباط اجتماعی بالا (Dual High I)
        if ($i1 >= 60 && $i2 >= 60) {
            $synergyStrengths[] = [
                'area'  => 'شادی و گرمای رابطه',
                'title' => 'شور، نشاط و لذت مشترک از همراهی',
                'desc'  => 'هر دو طرف روحیه‌ای اجتماعی، پرانرژی و مشتاق دارند. خانه یا فضای مشترک شما معمولاً سرشار از حس زندگی و مراودات اجتماعی است.',
            ];
        }

        // ۴. نظم و دقت مشترک (Dual High C)
        if ($c1 >= 60 && $c2 >= 60) {
            $synergyStrengths[] = [
                'area'  => 'استاندارد و پیش‌بینی‌پذیری',
                'title' => 'پایهٔ عقلانی و مدیریت منظم امور',
                'desc'  => 'تصمیم‌گیری‌های مالی و اداری با حداقل ریسک و خطای محاسباتی مدیریت می‌شوند و هیچ‌یک از دو طرف اهل اقدامات نسنجیده نیستند.',
            ];
        }

        if (empty($synergyStrengths)) {
            $synergyStrengths[] = [
                'area'  => 'توازن عمومی',
                'title' => 'سازگاری انعطاف‌پذیر و متعادل',
                'desc'  => 'شدت قطبی در ابعاد شما وجود ندارد؛ این امر انعطاف‌پذیری بالایی برای گفت‌وگو و سازش در موقعیت‌های مختلف فراهم می‌کند.',
            ];
        }

        if (empty($frictionPoints)) {
            $frictionPoints[] = [
                'area'  => 'فرسایش تدریجی',
                'title' => 'سکوت دربارهٔ خواسته‌های فردی',
                'desc'  => 'به‌دلیل نبود اصطکاک شدید، ممکن است دلخوری‌های کوچک بازگو نشوند و در طول زمان به فاصله‌گیری خاموش منجر گردند.',
            ];
        }

        $synergyScore = (int)round(max(48, min(95, $baseScore - $clashFriction + $synergyBonus)));

        $ratingFa = match (true) {
            $synergyScore >= 85 => 'هم‌افزایی بسیار عالی و هماهنگی کم‌نظیر',
            $synergyScore >= 72 => 'سازگاری بالا با ظرفیت تعادل‌بخشی قدرتمند',
            $synergyScore >= 60 => 'سازگاری متوسط؛ نیازمند مهارت‌های ارتباطی آگاهانه',
            default             => 'تفاوت‌های بنیادی سبک رفتاری؛ نیازمند توافق‌نامه‌های ساختاریافته',
        };

        // پروتکل گفت‌وگوی امن دونفره
        $safeProtocols = [
            'to_p1' => "هنگام گفت‌وگو با {$u2['first_name']}: " . self::discCommAdvice($pat2, $u2['first_name']),
            'to_p2' => "هنگام گفت‌وگو با {$u1['first_name']}: " . self::discCommAdvice($pat1, $u1['first_name']),
            'rules' => [
                'قانون توقف ۵ ثانیه‌ای: پیش از پاسخ‌گویی در لحظهٔ عصبانیت، ۳ تنفس عمیق شکمی بکشید.',
                'بیان بر مبنای حس خود: به‌جای «تو همیشه…» از عبارت «من در این لحظه این احساس را دارم که…» استفاده کنید.',
                'تفکیک نیاز به شنیدن از حل مسئله: ابتدا مشخص کنید «آیا فقط همدلی و شنیده‌شدن می‌خواهید یا راهکار عملی؟»',
                'جلسهٔ هفتگی ۲۰ دقیقه‌ای «تخلیهٔ امن»: بدون قضاوت و سرزنش، هر طرف دو نیاز محقق‌نشدهٔ خود را بیان کند.',
            ],
        ];

        return [
            'synergy_score'   => $synergyScore,
            'rating_fa'       => $ratingFa,
            'rel_type_fa'     => self::$REL_TYPES[$d['rel_type']] ?? 'رابطه دونفره',
            'user1'           => ['id' => $u1['id'], 'name' => trim($u1['first_name'] . ' ' . $u1['last_name']), 'pattern' => $pat1],
            'user2'           => ['id' => $u2['id'], 'name' => trim($u2['first_name'] . ' ' . $u2['last_name']), 'pattern' => $pat2],
            'delta_matrix'    => $deltaMatrix,
            'synergy_strengths'=> $synergyStrengths,
            'friction_points' => $frictionPoints,
            'safe_protocols'  => $safeProtocols,
            'growth_contract' => [
                'week1' => 'شناسایی و نام‌گذاری کانون تعارض اصلی در یک مکالمهٔ آرام ۱۰ دقیقه‌ای',
                'week2' => 'تمرین تفویض دو تصمیم مهم خانوادگی/کاری به طرف مکمل',
                'week3' => 'تمرین بازخورد مثبت روزانه (قدردانی صریح از یک ویژگی شخصیتی همسر)',
            ],
        ];
    }

    /**
     * تحلیل تطابق عمومی (برای سایر تست‌های چندعاملی مانند NEO و گاردنر)
     */
    private static function analyzeGenericDyad(array $d, array $u1, array $u2, array $at1, array $at2, array $m1, array $m2): array
    {
        $allCodes = array_unique(array_merge(array_keys($m1), array_keys($m2)));
        $deltaMatrix = [];
        $totalDelta = 0;
        $cnt = 0;

        foreach ($allCodes as $code) {
            if ($code === 'overall') continue;
            $p1 = (int)round($m1[$code]['percent'] ?? 50);
            $p2 = (int)round($m2[$code]['percent'] ?? 50);
            $diff = abs($p1 - $p2);
            $totalDelta += $diff;
            $cnt++;

            $deltaMatrix[$code] = [
                'code'   => $code,
                'title'  => $code,
                'p1'     => $p1,
                'p2'     => $p2,
                'diff'   => $diff,
                'nature' => $diff <= 15 ? 'همسویی بالا' : ($diff <= 30 ? 'تکمیل‌کننده' : 'تفاوت معنادار'),
            ];
        }

        $avgDiff = $cnt > 0 ? $totalDelta / $cnt : 20;
        $score = (int)round(max(50, min(94, 92 - ($avgDiff * 0.8))));

        return [
            'synergy_score'    => $score,
            'rating_fa'        => $score >= 75 ? 'سازگاری بسیار خوب و همسویی مطلوب' : 'سازگاری نیازمند تنظیم انتظارات متقابل',
            'rel_type_fa'      => self::$REL_TYPES[$d['rel_type']] ?? 'رابطه دونفره',
            'user1'            => ['id' => $u1['id'], 'name' => trim($u1['first_name'] . ' ' . $u1['last_name']), 'pattern' => ''],
            'user2'            => ['id' => $u2['id'], 'name' => trim($u2['first_name'] . ' ' . $u2['last_name']), 'pattern' => ''],
            'delta_matrix'     => $deltaMatrix,
            'synergy_strengths' => [
                ['area' => 'همدلی', 'title' => 'همپوشانی در ابعاد کلیدی', 'desc' => 'در بخش بزرگی از مولفه‌های شخصیتی، تفاوت‌های عمیق بازدارنده مشاهده نمی‌شود.'],
            ],
            'friction_points'  => [
                ['area' => 'تفاوت نگاه', 'title' => 'نقاط نیازمند هماهنگی', 'desc' => 'تفاوت در ابعاد با اختلاف نمره بالای ۳۰ نیازمند شفاف‌سازی آگاهانه انتظارات است.'],
            ],
            'safe_protocols'   => [
                'to_p1' => "شنیدن صبورانه و پرهیز از نتیجه‌گیری شتاب‌زده دربارهٔ انگیزه‌های {$u2['first_name']}.",
                'to_p2' => "ابراز شفاف نیازها بدون سرزنش یا برچسب‌زنی به {$u1['first_name']}.",
                'rules' => ['گوش دادن فعال بدون قطع کلام طرف مقابل', 'قرار گذاشتن برای صحبت در زمان آرامش و سیری'],
            ],
            'growth_contract'  => [
                'week1' => 'گفت‌وگوی شفاف دربارهٔ نقاط اشتراک و افتراق این کارنامه',
                'week2' => 'تدوین یک قانون طلایی دونفره برای پیشگیری از اصطکاک',
            ],
        ];
    }

    /** استخراج کد الگوی DISC */
    private static function extractPattern(string $context, array $map): string
    {
        if (preg_match('/pattern:([A-Z]+)/', $context, $m)) {
            return $m[1];
        }
        $scores = [
            'D' => (float)($map['D']['percent'] ?? 0),
            'I' => (float)($map['I']['percent'] ?? 0),
            'S' => (float)($map['S']['percent'] ?? 0),
            'C' => (float)($map['C']['percent'] ?? 0),
        ];
        arsort($scores);
        return implode('', array_slice(array_keys($scores), 0, 2));
    }

    /** توصیه‌های اختصاصی تعامل بر اساس تیپ DISC */
    private static function discCommAdvice(string $pat, string $name): string
    {
        $lead = substr($pat, 0, 1);
        return match ($lead) {
            'D' => "مستقیم به اصل مطلب بروید، از حاشیه‌روی بپرهیزید، روی نتایج تمرکز کنید و گزینه‌های شفاف با حق انتخاب در اختیار {$name} بگذارید.",
            'I' => "با لحن گرم، صمیمانه و مثبت آغاز کنید، به احساساتش توجه نشان دهید و اجازه دهید ایده‌هایش را با اشتیاق به اشتراک بگذارد.",
            'S' => "آرام و بدون شتاب صحبت کنید، امنیت و احترام متقابل را تضمین نمایید و از تحمیل تغییرات ناگهانی و غافلگیرکننده بپرهیزید.",
            'C' => "با استدلال منطقی و شواهد صحبت کنید، از اغراق هیجانی دوری نمایید و به جزئیات و استانداردهای مدنظر {$name} احترام بگذارید.",
            default => "با لحنی محترمانه و شنوا، زمینه را برای درک دوجانبه فراهم آورید.",
        };
    }
}
