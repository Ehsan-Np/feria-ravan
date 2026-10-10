<?php
/**
 * ============================================================================
 *  موتور برنامهٔ عمل پویا و تمرین‌های رشد فریا روان (Growth Engine)
 *  ─ تبدیل نتایج آزمون به برنامهٔ عملیاتی ۴ تا ۸ هفته‌ای در پلنر روزانه
 *  ─ تولید تمرین‌های علمی، مبتنی بر شواهد و شخصی‌سازی‌شده بر اساس آزمون و نمرات کاربر
 *  ─ تضمین خودکار ساختار جداول پلنر (Self-healing schema guard) جهت پیشگیری از خطای تزریق
 *  ─ پایش پیشرفت و محاسبهٔ نمودار دلتا (Retest & Delta Tracking) در طول زمان
 * ============================================================================
 */
declare(strict_types=1);

final class GrowthEngine
{
    /**
     * تضمین خودکار وجود جداول و فیلدهای لازم در پایگاه داده جهت جلوگیری از هرگونه خطای تزریق
     */
    public static function ensurePlannerSchema(): void
    {
        try {
            // ۱. جدول دسته‌بندی‌های پلنر
            DB::run("CREATE TABLE IF NOT EXISTS " . DB::t('planner_cats') . " (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `name` VARCHAR(60) NOT NULL,
                `color` VARCHAR(20) NOT NULL DEFAULT '#1e3a6e',
                `sort` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_name` (`user_id`,`name`),
                KEY `ix_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            error_log('GrowthEngine ensurePlannerSchema planner_cats: ' . $e->getMessage());
        }

        try {
            // ۲. فیلدهای موردنیاز در جدول کارهای پلنر
            $taskCols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('planner_tasks')), 'Field');

            if (!in_array('note', $taskCols, true)) {
                DB::run("ALTER TABLE " . DB::t('planner_tasks') . " ADD COLUMN `note` VARCHAR(500) NOT NULL DEFAULT '' AFTER `category`");
            }
            if (!in_array('remind_at', $taskCols, true)) {
                DB::run("ALTER TABLE " . DB::t('planner_tasks') . " ADD COLUMN `remind_at` DATETIME DEFAULT NULL AFTER `done_at`");
            }
            if (!in_array('reminded_at', $taskCols, true)) {
                DB::run("ALTER TABLE " . DB::t('planner_tasks') . " ADD COLUMN `reminded_at` DATETIME DEFAULT NULL AFTER `remind_at`");
            }
            if (!in_array('recur_id', $taskCols, true)) {
                DB::run("ALTER TABLE " . DB::t('planner_tasks') . " ADD COLUMN `recur_id` BIGINT UNSIGNED DEFAULT NULL AFTER `note`");
            }
            if (!in_array('ref_attempt_id', $taskCols, true)) {
                DB::run("ALTER TABLE " . DB::t('planner_tasks') . " ADD COLUMN `ref_attempt_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user_id`");
            }

            // بررسی ایندکس‌ها
            $taskIndexes = array_column(DB::all("SHOW INDEX FROM " . DB::t('planner_tasks')), 'Key_name');
            if (!in_array('ix_remind', $taskIndexes, true)) {
                try { DB::run("ALTER TABLE " . DB::t('planner_tasks') . " ADD KEY `ix_remind` (`reminded_at`,`remind_at`)"); } catch (Throwable $e) {}
            }
            if (!in_array('ix_ref_att', $taskIndexes, true)) {
                try { DB::run("ALTER TABLE " . DB::t('planner_tasks') . " ADD KEY `ix_ref_att` (`ref_attempt_id`)"); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            error_log('GrowthEngine ensurePlannerSchema planner_tasks cols: ' . $e->getMessage());
        }

        try {
            // ۳. فیلد growth_injected در جدول attempts
            $attCols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t('attempts')), 'Field');
            if (!in_array('growth_injected', $attCols, true)) {
                DB::run("ALTER TABLE " . DB::t('attempts') . " ADD COLUMN `growth_injected` TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Throwable $e) {
            error_log('GrowthEngine ensurePlannerSchema attempts cols: ' . $e->getMessage());
        }
    }

    /**
     * تولید برنامهٔ عمل و تمرین‌های رشد اختصاصی متناسب با کارنامهٔ کاربر
     */
    public static function generatePlan(int $attemptId): array
    {
        $at = DB::one('SELECT a.*, t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine
                       FROM ' . DB::t('attempts') . ' a
                       JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
                       WHERE a.id=?', [$attemptId]);
        if (!$at) return [];

        $results = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$attemptId]);
        $map = []; foreach ($results as $r) { $map[$r['scale_code']] = $r; }

        $engine = strtolower((string)($at['test_engine'] ?? ''));
        $slug   = strtolower((string)($at['test_slug'] ?? ''));

        // تشخیص هوشمند نوع آزمون و انتخاب تخصصی‌ترین موتور تولید تمرین
        $tasks = match (true) {
            $engine === 'disc' || str_contains($slug, 'disc')
                => self::buildDiscGrowthTasks($map, $at),

            str_contains($slug, 'anxiety') || str_contains($slug, 'gad')
                => self::buildAnxietyGrowthTasks($map, $at),

            str_contains($slug, 'depression') || str_contains($slug, 'phq')
                => self::buildDepressionGrowthTasks($map, $at),

            $engine === 'jobsat' || str_contains($slug, 'jobsat') || str_contains($slug, 'job-sat')
                => self::buildJobSatGrowthTasks($map, $at),

            str_contains($slug, 'gardner') || str_contains($slug, 'mi')
                => self::buildGardnerGrowthTasks($map, $at),

            str_contains($slug, 'eq') || str_contains($slug, 'baron') || str_contains($slug, 'shutte')
                => self::buildEqGrowthTasks($map, $at),

            $engine === 'mbti' || str_contains($slug, 'mbti')
                => self::buildMbtiGrowthTasks($map, $at),

            $engine === 'holland' || str_contains($slug, 'holland') || str_contains($slug, 'riasec')
                => self::buildHollandGrowthTasks($map, $at),

            $engine === 'ysq' || str_contains($slug, 'ysq') || str_contains($slug, 'schema')
                => self::buildYsqGrowthTasks($map, $at),

            $engine === 'mmpi' || str_contains($slug, 'mmpi')
                => self::buildMmpiGrowthTasks($map, $at),

            $engine === 'raven' || $engine === 'wais' || str_contains($slug, 'raven') || str_contains($slug, 'wais')
                => self::buildCognitiveGrowthTasks($map, $at),

            $engine === 'neo' || str_contains($slug, 'neo') || (isset($map['N']) && isset($map['E']) && isset($map['C']))
                => self::buildNeoGrowthTasks($map, $at),

            default
                => self::buildGenericGrowthTasks($map, (string)($at['test_title'] ?? 'آزمون روانشناختی')),
        };

        // مرتب‌سازی تمرین‌ها بر اساس روز اجرا (day_offset)
        usort($tasks, fn($a, $b) => ($a['day_offset'] ?? 1) <=> ($b['day_offset'] ?? 1));

        return [
            'attempt_id'     => $attemptId,
            'test_title'     => $at['test_title'],
            'test_slug'      => $at['test_slug'],
            'duration_weeks' => 4,
            'task_count'     => count($tasks),
            'tasks'          => $tasks,
        ];
    }

    /**
     * تزریق مستقیم تمرین‌های رشد به پلنر روزانهٔ کاربر (planner_tasks)
     */
    public static function injectIntoPlanner(int $userId, int $attemptId): array
    {
        return self::injectToPlanner($userId, $attemptId);
    }

    public static function injectToPlanner(int $userId, int $attemptId): array
    {
        try {
            // تضمین صحت و ترمیم خودکار ساختار دیتابیس
            self::ensurePlannerSchema();

            $plan = self::generatePlan($attemptId);
            if (empty($plan['tasks'])) {
                return ['ok' => false, 'message' => 'برنامهٔ رشدی برای این آزمون یافت نشد.'];
            }

            // ایجاد دسته «تمرین رشد کارنامه» با رنگ بنفش در پلنر کاربر
            $catName = 'تمرین رشد کارنامه';
            $catExists = DB::one('SELECT id FROM ' . DB::t('planner_cats') . ' WHERE user_id=? AND name=?', [$userId, $catName]);
            if (!$catExists) {
                try {
                    DB::insert('planner_cats', [
                        'user_id' => $userId,
                        'name'    => $catName,
                        'color'   => '#7c3aed',
                        'sort'    => 1,
                    ]);
                } catch (Throwable $e) {
                    error_log('GrowthEngine insert cat ignored: ' . $e->getMessage());
                }
            }

            // حذف تسک‌های قبلی همین آزمون (برای جلوگیری از تکرار در صورت تزریق مجدد)
            DB::run('DELETE FROM ' . DB::t('planner_tasks') . ' WHERE user_id=? AND ref_attempt_id=?', [$userId, $attemptId]);

            $baseDate = time();
            $injectedCount = 0;

            foreach ($plan['tasks'] as $t) {
                $dayOffset = max(1, (int)($t['day_offset'] ?? 1));
                $taskDate = date('Y-m-d', $baseDate + ($dayOffset * 86400));
                $remindTime = !empty($t['time_note']) ? (string)$t['time_note'] : '09:00';
                $remindAt = $taskDate . ' ' . $remindTime . ':00';

                DB::insert('planner_tasks', [
                    'user_id'        => $userId,
                    'ref_attempt_id' => $attemptId,
                    'day'            => $taskDate,
                    'title'          => (string)$t['title'],
                    'time_note'      => $remindTime,
                    'priority'       => (string)($t['priority'] ?? 'normal'),
                    'category'       => $catName,
                    'done'           => 0,
                    'note'           => (string)($t['desc'] ?? ''),
                    'remind_at'      => $remindAt,
                ]);
                $injectedCount++;
            }

            try {
                DB::update('attempts', ['growth_injected' => 1], 'id=?', [$attemptId]);
            } catch (Throwable $e) {}

            return [
                'ok'             => true,
                'injected_count' => $injectedCount,
                'inserted'       => $injectedCount,
                'message'        => fa_num($injectedCount) . ' تمرین رشد تخصصی با موفقیت به برنامهٔ روزانهٔ شما اضافه شد! 🎯',
            ];
        } catch (Throwable $e) {
            error_log('GrowthEngine injectToPlanner exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
                'ok'      => false,
                'message' => 'خطا در ثبت تمرین‌های رشد در پلنر: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * محاسبهٔ پایش پیشرفت و نمودار دلتا (مقایسهٔ آزمون قبلی و جدید)
     */
    public static function getDeltaComparison(int $userId, int $testId): array
    {
        $attempts = DB::all("
            SELECT id, mode, started_at, completed_at
            FROM " . DB::t('attempts') . "
            WHERE user_id=? AND test_id=? AND status='completed'
            ORDER BY id ASC
        ", [$userId, $testId]);

        if (count($attempts) < 2) {
            return [
                'has_comparison' => false,
                'total_attempts' => count($attempts),
                'scales'         => [],
            ];
        }

        $firstAt = $attempts[0];
        $latestAt = end($attempts);

        $res1 = DB::all('SELECT scale_code, percent, t_score FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$firstAt['id']]);
        $res2 = DB::all('SELECT scale_code, percent, t_score FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$latestAt['id']]);

        $m1 = []; foreach ($res1 as $r) { $m1[$r['scale_code']] = $r; }
        $m2 = []; foreach ($res2 as $r) { $m2[$r['scale_code']] = $r; }

        $scaleTitles = [];
        $stRows = DB::all('SELECT code, title_fa FROM ' . DB::t('test_scales') . ' WHERE test_id=?', [$testId]);
        foreach ($stRows as $st) {
            $scaleTitles[$st['code']] = $st['title_fa'];
        }

        // عناوین فارسی پیش‌فرض برای مقیاس‌های استاندارد
        $defaultTitles = [
            'D' => 'قاطعیت و تسلط (D)',
            'I' => 'نفوذ و ارتباط (I)',
            'S' => 'ثبات و صبوری (S)',
            'C' => 'دقت و انطباق (C)',
            'N' => 'روان‌رنجورخویی / تنش هیجانی (N)',
            'E' => 'برون‌گرایی و انرژی اجتماعی (E)',
            'O' => 'گشودگی به تجربه (O)',
            'A' => 'سازگاری و همدلی (A)',
            'C_NEO' => 'باوجدانی و مسئولیت‌پذیری (C)',
            'cognitive' => 'حیطهٔ شناختی (افکار نگران‌کننده)',
            'affective' => 'حیطهٔ هیجانی / عاطفی',
            'somatic' => 'حیطهٔ جسمانی / نشانگان بدنی',
            'perception' => 'ادراک و ارزیابی هیجان',
            'regulation' => 'تنظیم و مدیریت هیجان',
            'utilization' => 'به‌کارگیری سازندهٔ هیجان',
            'linguistic' => 'هوش زبانی-کلامی',
            'logical' => 'هوش منطقی-ریاضی',
            'spatial' => 'هوش دیداری-فضایی',
            'bodily' => 'هوش بدنی-جنبشی',
            'musical' => 'هوش موسیقیایی',
            'interpersonal' => 'هوش بین‌فردی',
            'intrapersonal' => 'هوش درون‌فردی',
            'naturalist' => 'هوش طبیعت‌گرایانه',
            'R' => 'واقع‌گرا (R)',
            'I_HOL' => 'جستجوگر (I)',
            'A_HOL' => 'هنری (A)',
            'S_HOL' => 'اجتماعی (S)',
            'E_HOL' => 'متهور / سازمانی (E)',
            'C_HOL' => 'قراردادی / ساختاریافته (C)',
        ];

        $scales = [];
        $totalImprovement = 0;
        $cnt = 0;

        foreach ($m2 as $code => $r2) {
            if ($code === 'overall' || $code === 'profile') continue;
            $p1 = (float)($m1[$code]['percent'] ?? 50);
            $p2 = (float)($r2['percent'] ?? 50);
            $delta = round($p2 - $p1, 1);
            $totalImprovement += abs($delta);
            $cnt++;

            $title = $scaleTitles[$code] ?? ($defaultTitles[$code] ?? $code);

            $scales[$code] = [
                'code'         => $code,
                'title'        => $title,
                'first_score'  => $p1,
                'latest_score' => $p2,
                'before'       => $p1,
                'after'        => $p2,
                'delta'        => $delta,
                'trend'        => $delta > 0 ? 'increased' : ($delta < 0 ? 'decreased' : 'stable'),
                'direction'    => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'same'),
                't_before'     => $m1[$code]['t_score'] ?? null,
                't_after'      => $r2['t_score'] ?? null,
            ];
        }

        $t1 = !empty($firstAt['completed_at']) ? strtotime($firstAt['completed_at']) : strtotime($firstAt['started_at']);
        $t2 = !empty($latestAt['completed_at']) ? strtotime($latestAt['completed_at']) : strtotime($latestAt['started_at']);
        $daysBetween = max(1, (int)round(($t2 - $t1) / 86400));

        return [
            'has_comparison'  => true,
            'total_attempts'  => count($attempts),
            'first_attempt'   => $firstAt,
            'latest_attempt'  => $latestAt,
            'days_between'    => $daysBetween,
            'scale_count'     => $cnt,
            'scales'          => $scales,
            'avg_delta'       => $cnt > 0 ? round($totalImprovement / $cnt, 1) : 0,
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای DISC (تکالیف رفتاری ۴ هفته‌ای اختصاصی)
     */
    private static function buildDiscGrowthTasks(array $map, array $at = []): array
    {
        $d = (float)($map['D']['percent'] ?? 50);
        $i = (float)($map['I']['percent'] ?? 50);
        $s = (float)($map['S']['percent'] ?? 50);
        $c = (float)($map['C']['percent'] ?? 50);

        $tasks = [];

        // مؤلفه D (قاطعیت و اقدام)
        if ($d < 45) {
            $tasks[] = [
                'day_offset' => 2, 'time_note' => '09:30', 'priority' => 'high',
                'title'      => '🎯 تمرین قاطعیت: «نه» محترمانه به یک درخواست غیرضروری',
                'desc'       => 'امروز به یک درخواست کاری یا شخصی که در اولویت اصلی‌تان نیست با آرامش و احترام بگویید: «در حال حاضر امکان انجامش را ندارم». حس بعد از آن را در دفتر یادداشت کنید.',
            ];
            $tasks[] = [
                'day_offset' => 9, 'time_note' => '11:00', 'priority' => 'high',
                'title'      => '🎯 تمرین ابراز صریح هدف در ۳۰ ثانیهٔ اول گفت‌وگو',
                'desc'       => 'در یک تماس تلفنی یا جلسه کاری، بدون مقدمه‌چینی اضافه اصل درخواست و خروجی موردنظرتان را با لحنی محکم و شفاف بیان کنید.',
            ];
        } elseif ($d > 65) {
            $tasks[] = [
                'day_offset' => 3, 'time_note' => '14:00', 'priority' => 'normal',
                'title'      => '🧘 تمرین مکث ۳۰ ثانیه‌ای و سکوت در موقعیت چالش',
                'desc'       => 'پیش از آنکه در یک تعارض سریعاً واکنش قاطع یا تند نشان دهید، ۳ تنفس عمیق شکمی بکشید و بپرسید: «طرف مقابل دقیقاً چه احساسی دارد؟».',
            ];
            $tasks[] = [
                'day_offset' => 10, 'time_note' => '10:00', 'priority' => 'normal',
                'title'      => '🤝 تمرین تفویض کامل یک تصمیم به دیگری',
                'desc'       => 'یکی از تصمیم‌های خانه یا تیم کاری را کاملاً به فرد دیگری بسپارید و بدون اعمال سلیقه، اجازه دهید طبق نظر خودش پیش برود.',
            ];
        } else {
            $tasks[] = [
                'day_offset' => 3, 'time_note' => '10:00', 'priority' => 'normal',
                'title'      => '🎯 تمرین تصمیم‌گیری چابک: اقدام هدفمند بدون معطلی',
                'desc'       => 'برای یکی از تصمیم‌های بلاتکلیف امروز زمانی حداکثر ۱۰ دقیقه‌ای بگذارید؛ تصمیم را نهایی و گام اول اقدام را آغاز کنید.',
            ];
        }

        // مؤلفه I (ارتباط و تعامل اجتماعی)
        if ($i < 45) {
            $tasks[] = [
                'day_offset' => 4, 'time_note' => '12:00', 'priority' => 'normal',
                'title'      => '💬 ارسال یک پیام قدردانی بی‌بهانه به همکار یا دوست',
                'desc'       => 'یک پیام صمیمانه برای تشکر از ویژگی مثبت یا کمکی که فردی به شما کرده بفرستید تا پل ارتباط عاطفی تقویت شود.',
            ];
            $tasks[] = [
                'day_offset' => 12, 'time_note' => '13:00', 'priority' => 'normal',
                'title'      => '☕ گپ کوتاه ۵ دقیقه‌ای حضوری در وقت استراحت',
                'desc'       => 'در زمان استراحت روز، در یک گفت‌وگوی غیرکاری کوتاه با یکی از همکاران یا آشنایان شرکت کنید و شنونده باشید.',
            ];
        } elseif ($i > 65) {
            $tasks[] = [
                'day_offset' => 4, 'time_note' => '15:00', 'priority' => 'high',
                'title'      => '👂 تمرین گوش‌دادن فعال کامل (بدون قطع کلام دیگران)',
                'desc'       => 'در گفت‌وگوی امروز حداقل ۵ دقیقه تماماً بشنوید، میان کلام گوینده وارد نشوید و پیش از پاسخ دادن، خلاصه حرف او را بازگو کنید.',
            ];
            $tasks[] = [
                'day_offset' => 12, 'time_note' => '17:30', 'priority' => 'normal',
                'title'      => '📝 ثبت مکتوب تعهدات گفتاری و چک‌لیست اقدام',
                'desc'       => 'قرارهایی که در مکالمات شفاهی امروز گذاشته‌اید را یادداشت کنید تا به‌خاطر هیجان گفتگو، پیگیری عملی فراموش نشود.',
            ];
        } else {
            $tasks[] = [
                'day_offset' => 5, 'time_note' => '13:30', 'priority' => 'normal',
                'title'      => '💬 تمرین ارتباط مؤثر: ابراز یک تحسین صادقانه',
                'desc'       => 'از تلاش یا دستاورد یکی از نزدیکان یا همکاران در جمع یا به شکل اختصاصی با لحنی گرم قدردانی نمایید.',
            ];
        }

        // مؤلفه S (ثبات، صبوری و آرامش)
        if ($s < 45) {
            $tasks[] = [
                'day_offset' => 6, 'time_note' => '08:00', 'priority' => 'high',
                'title'      => '🌿 تمرین ذهن‌آگاهی و تنفس آگاهانه ۵ دقیقه‌ای',
                'desc'       => 'صبح امروز بدون نگاه به گوشی، ۵ دقیقه در سکوت بنشینید و تنها به ریتم دم و بازدم توجه کنید تا شتاب‌زدگی درونی فروکش کند.',
            ];
            $tasks[] = [
                'day_offset' => 15, 'time_note' => '16:00', 'priority' => 'normal',
                'title'      => '⏳ تمرین پذیرش تأخیرها بدون بی‌قراری',
                'desc'       => 'در ترافیک، صف یا هنگام تأخیر پاسخ دیگران، نفس عمیق بکشید و از این زمان برای آرام‌سازی عضلات شانه و گردن استفاده کنید.',
            ];
        } elseif ($s > 65) {
            $tasks[] = [
                'day_offset' => 6, 'time_note' => '11:00', 'priority' => 'high',
                'title'      => '🚀 استقبال از یک تغییر کوچک در روتین روزمره',
                'desc'       => 'مسیر همیشگی، ترتیب انجام وظایف یا یکی از عادات روزمره را عمداً تغییر دهید تا انعطاف‌پذیری ذهنی در برابر تغییرات تقویت شود.',
            ];
            $tasks[] = [
                'day_offset' => 15, 'time_note' => '18:00', 'priority' => 'normal',
                'title'      => '🗣 ابراز یک نظر مخالف به جای سکوت مصلحتی',
                'desc'       => 'در جلسه‌ای یا صحبتی که نظری متفاوت دارید، برای جلوگیری از تعارض سکوت نکنید و دیدگاه خود را با آرامش و متانت مطرح نمایید.',
            ];
        } else {
            $tasks[] = [
                'day_offset' => 7, 'time_note' => '17:00', 'priority' => 'normal',
                'title'      => '🌿 تنظیم ریتم کار و استراحت: ۱۰ دقیقه وقفهٔ آرامش‌بخش',
                'desc'       => 'میان دو بلوک کاری پرفشار، ۱۰ دقیقه استراحت کامل بدون گوشی داشته باشید تا انرژی روانی‌تان پایدار بماند.',
            ];
        }

        // مؤلفه C (نظم، دقت و کیفیت)
        if ($c < 45) {
            $tasks[] = [
                'day_offset' => 8, 'time_note' => '08:30', 'priority' => 'high',
                'title'      => '📋 ثبت مکتوب ۳ اولویت شفاف کاری امروز',
                'desc'       => 'پیش از شروع فعالیت‌ها، ۳ کار اصلی روز را بنویسید و تا نخستین مورد تمام نشده به سراغ کارهای دیگر نروید.',
            ];
            $tasks[] = [
                'day_offset' => 18, 'time_note' => '16:00', 'priority' => 'normal',
                'title'      => '🔍 بازبینی نهایی پیش از ارسال پیام یا گزارش مهم',
                'desc'       => 'پیش از ارسال یک گزارش، ایمیل یا پیام مهم، ۲ دقیقه متن را مرور کنید تا خطاها و ابهامات احتمالی برطرف شوند.',
            ];
        } elseif ($c > 65) {
            $tasks[] = [
                'day_offset' => 8, 'time_note' => '14:00', 'priority' => 'high',
                'title'      => '✨ تمرین استاندارد «به اندازه کافی خوب» (کاهش کمال‌گرایی)',
                'desc'       => 'یکی از کارهای امروز را پس از رسیدن به سطح مطلوب متوقف کنید و اجازه ندهید وسواس بازبینی بیش از حد، زمان شما را هدر دهد.',
            ];
            $tasks[] = [
                'day_offset' => 18, 'time_note' => '19:00', 'priority' => 'normal',
                'title'      => '🕊 تمرین نادیده‌گرفتن یک نقص جزئی بدون اصلاح فوری',
                'desc'       => 'یک ناهماهنگی یا بی‌نظمی بسیار کوچک را ببینید اما در همان لحظه برای اصلاحش اقدام نکنید تا ظرفیت مدارای ذهنی افزایش یابد.',
            ];
        } else {
            $tasks[] = [
                'day_offset' => 8, 'time_note' => '08:30', 'priority' => 'normal',
                'title'      => '📋 سازماندهی فضای کاری و پاک‌سازی میز کار',
                'desc'       => 'ابزارها و فایل‌های کاری را دسته‌بندی کنید تا بار شناختی محیط کاهش یافته و تمرکز افزایش یابد.',
            ];
        }

        // تثبیت و ارزیابی نهایی
        $tasks[] = [
            'day_offset' => 22, 'time_note' => '20:00', 'priority' => 'high',
            'title'      => '✍️ مرور تغییرات دو هفته اخیر و ثبت رفتارهای نو',
            'desc'       => 'در دفترچه یادداشت خود بنویسید در کدام موقعیت‌ها توانستید واکنشی آگاهانه‌تر و منطبق با الگوی رفتاری مطلوب بروز دهید.',
        ];
        $tasks[] = [
            'day_offset' => 28, 'time_note' => '11:00', 'priority' => 'high',
            'title'      => '🎯 آزمون مجدد پیگیری (Retest) و مقایسهٔ دلتای رشد DISC',
            'desc'       => 'با ورود به بخش «تست‌های من» در پنل، مجدداً در آزمون شرکت کنید تا در برگه «پایش رشد آزمون‌ها» تغییرات شاخص‌های رفتاری‌تان سنجیده شود.',
        ];

        return $tasks;
    }

    /**
     * تمرین‌های رشد بر مبنای اضطراب (پروتکل علمی GAD-7 و CBT)
     */
    private static function buildAnxietyGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 1, 'time_note' => '08:30', 'priority' => 'high',
                'title'      => '📝 ثبت و شناسایی الگوی اضطراب (دفتر خودپایی)',
                'desc'       => 'امروز هرگاه دلشوره یا نگرانی آمد، در دفترچه بنویسید: محرک اولیه چیست؟ چه فکری در ذهن گذشت؟ چه حسی در بدن ایجاد شد؟ شدت از ۰ تا ۱۰ چقدر است؟',
            ],
            [
                'day_offset' => 3, 'time_note' => '21:00', 'priority' => 'high',
                'title'      => '🫁 تنفس دیافراگمی ۴-۷-۸ برای تنظیم فوری عصب واگ',
                'desc'       => 'به مدت ۵ دقیقه تمرین کنید: ۴ ثانیه دم آرام از بینی، ۷ ثانیه حبس نفس، ۸ ثانیه بازدم ملایم با صدای «هو» از دهان. این تمرین ضربان قلب را کاهش می‌دهد.',
            ],
            [
                'day_offset' => 5, 'time_note' => '18:30', 'priority' => 'normal',
                'title'      => '⏳ تکنیک «زمان مشخص نگرانی» (Worry Time)',
                'desc'       => 'یک بازهٔ ۱۵ دقیقه‌ای (مثلاً ۱۸:۳۰ تا ۱۸:۴۵) را به نگرانی اختصاص دهید. در طول روز هر فکری آمد بنویسید و به خود بگویید: «در زمان نگرانی بررسی‌اش می‌کنم».',
            ],
            [
                'day_offset' => 8, 'time_note' => '09:00', 'priority' => 'high',
                'title'      => '🧘 آرام‌سازی پیشرونده عضلانی جاکوبسن (PMR)',
                'desc'       => 'عضلات شانه، دست‌ها و فک را ۵ ثانیه منقبض و سپس ۱۰ ثانیه کاملاً رها کنید. تفاوت حس انقباض و آرامش عمیق را با دقت لمس کنید.',
            ],
            [
                'day_offset' => 12, 'time_note' => '17:00', 'priority' => 'high',
                'title'      => '🧠 چالش با فاجعه‌سازی و آزمون شواهد واقعی (CBT)',
                'desc'       => 'وقتی نگران پیشامدی هستید بنویسید: ۱. بدترین حالت ممکن چیست؟ ۲. بهترین حالت کدام است؟ ۳. واقع‌بینانه‌ترین حالت چیست و چطور مدیریتش می‌کنم؟',
            ],
            [
                'day_offset' => 16, 'time_note' => '19:30', 'priority' => 'normal',
                'title'      => '🌿 ذهن‌آگاهی و پذیرش هیجان با تکنیک RAIN',
                'desc'       => 'شناخت احساس تنش (Recognize) + اجازه دادن به حضور آن بدون سرکوب (Allow) + کاوش حسی در بدن (Investigate) + برخورد مهربان با خود (Nurture).',
            ],
            [
                'day_offset' => 20, 'time_note' => '11:30', 'priority' => 'high',
                'title'      => '🧗 تمرین مواجههٔ تدریجی با موقعیت اجتناب‌شده',
                'desc'       => 'یک کار یا موقعیت کوچک که از سر اضطراب به تعویق می‌انداختید انتخاب کنید؛ وارد آن شوید و بمانید تا موج هیجان فروکش کند.',
            ],
            [
                'day_offset' => 24, 'time_note' => '21:30', 'priority' => 'normal',
                'title'      => '📵 سم‌زدایی دیجیتال ۱ ساعته پیش از خواب',
                'desc'       => '۶۰ دقیقه قبل از خواب، تمامی صفحات نمایش و اخبار را خاموش کنید و با نور ملایم، مطالعه سبک یا دوش آب گرم بدن را برای استراحت آماده سازید.',
            ],
            [
                'day_offset' => 28, 'time_note' => '16:00', 'priority' => 'high',
                'title'      => '🎯 ارزیابی دوره و آزمون مجدد پیگیری (Retest اضطراب)',
                'desc'       => 'آزمون اضطراب را مجدداً تکمیل کنید تا در برگه «پایش رشد آزمون‌ها» میزان کاهش علائم شناختی و جسمانی خود را مشاهده کنید.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای افسردگی (پروتکل علمی فعال‌سازی رفتاری BA و PHQ-9)
     */
    private static function buildDepressionGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 1, 'time_note' => '08:00', 'priority' => 'high',
                'title'      => '🌅 فعال‌سازی رفتاری: پیاده‌روی ۱۵ دقیقه‌ای زیر نور صبحگاهی',
                'desc'       => 'حتی اگر هیچ میلی به حرکت ندارید، کفش بپوشید و ۱۵ دقیقه زیر نور طبیعی آفتاب راه بروید. ترشح دوپامین و تنظیم ملاتونین از حرکت آغاز می‌شود.',
            ],
            [
                'day_offset' => 3, 'time_note' => '12:30', 'priority' => 'high',
                'title'      => '🎯 تکلیف خرد کفایت (Mastery Task): انجام یک کار معوق کوچک',
                'desc'       => 'یک کار بسیار ساده مثل مرتب‌کردن یک کشو، پاسخ به یک پیام یا آب‌دادن به گلدان‌ها را انجام دهید و تیک انجام آن را ثبت کنید.',
            ],
            [
                'day_offset' => 5, 'time_note' => '17:00', 'priority' => 'normal',
                'title'      => '☕ تکلیف لذت‌بخش آگاهانه (Pleasure Task)',
                'desc'       => 'یک فنجان چای، شنیدن یک قطعه موسیقی دلنشین یا تماشای غروب را با حضور کامل ذهن تجربه کنید؛ بدون اینکه خود را بابت کاری سرزنش نمایید.',
            ],
            [
                'day_offset' => 8, 'time_note' => '22:30', 'priority' => 'high',
                'title'      => '🌙 تثبیت ساعت بیداری و بهداشت خواب',
                'desc'       => 'برای فردا ساعت بیداری مشخصی تنظیم کنید و به‌محض به صدا درآمدن زنگ، تختخواب را ترک نمایید تا چرخه رخوت شکسته شود.',
            ],
            [
                'day_offset' => 12, 'time_note' => '20:00', 'priority' => 'normal',
                'title'      => '✍️ ثبت مکتوب «سه اتفاق خوب روز» (Three Good Things)',
                'desc'       => 'سه رخداد مثبت یا نکتهٔ ساده که امروز حس خوبی ساختند (ولو بسیار کوچک) را یادداشت کنید و بنویسید چرا رخ دادند.',
            ],
            [
                'day_offset' => 16, 'time_note' => '16:00', 'priority' => 'high',
                'title'      => '🧠 بازسازی شناختی: شناسایی افکار «همه یا هیچ»',
                'desc'       => 'اگر فکری مثل «من در همه‌چیز ناموفقم» آمد، جلویش بنویسید: «من در حال حاضر تحت فشارم، اما کارهای موفق زیادی هم انجام داده‌ام».',
            ],
            [
                'day_offset' => 20, 'time_note' => '15:00', 'priority' => 'normal',
                'title'      => '🤝 شکستن چرخهٔ انزوا: گفت‌وگوی کوتاه با فرد امن',
                'desc'       => 'به یک دوست صمیمی، مربی یا فرد مورد اعتماد تماس بگیرید یا پیام دهید؛ نیاز نیست تظاهر به خوشحالی کنید، فقط ارتباط را وصل نگه دارید.',
            ],
            [
                'day_offset' => 24, 'time_note' => '11:00', 'priority' => 'normal',
                'title'      => '🎨 اقدام ارزش‌محور: گامی در مسیر یادگیری یا محبت',
                'desc'       => 'کاری انجام دهید که با یکی از ارزش‌های عمیق شما (کمک به دیگران، خلق اثر، یادگیری) همسو باشد؛ پژوهش‌ها نشان می‌دهد معنا خلق را ارتقا می‌دهد.',
            ],
            [
                'day_offset' => 28, 'time_note' => '12:00', 'priority' => 'high',
                'title'      => '🎯 آزمون مجدد پیگیری خلق (Retest افسردگی) و سنجش دلتا',
                'desc'       => 'آزمون را مجدداً تکمیل کنید تا در برگه «پایش رشد آزمون‌ها» تغییرات سطح انرژی، انگیزه و خلق خود را طی این ۴ هفته ارزیابی کنید.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای رضایت شغلی (Job Satisfaction Survey)
     */
    private static function buildJobSatGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '09:00', 'priority' => 'high',
                'title'      => '🔍 تحلیل کارنامه و شفاف‌سازی انگیزه‌های اصلی شغلی',
                'desc'       => 'سه مؤلفه‌ای از کارنامه که پایین‌ترین نمره را داشتند مرور کنید و بنویسید دقیقاً چه انتظاری در آن بخش‌ها برآورده نشده است.',
            ],
            [
                'day_offset' => 5, 'time_note' => '11:30', 'priority' => 'normal',
                'title'      => '🛠 تمرین بازطراحی خرد شغل (Job Crafting)',
                'desc'       => 'یکی از وظایف روزمره را به شکلی تغییر دهید که با نقاط قوت و علایق شما تناسب بیشتری پیدا کند؛ حتی در نحوهٔ اجرا یا زمان‌بندی.',
            ],
            [
                'day_offset' => 9, 'time_note' => '14:00', 'priority' => 'high',
                'title'      => '🗣 تنظیم جلسه یا گفت‌وگوی شفاف با مدیر/سرپرست',
                'desc'       => 'سه موضوع کلیدی مرتبط با انتظارات متقابل و اولویت‌های کاری را با رویکرد بازخورد سازنده و دوجانبه با مدیر خود مطرح نمایید.',
            ],
            [
                'day_offset' => 13, 'time_note' => '16:30', 'priority' => 'normal',
                'title'      => '📈 مستندسازی کمی ۳ دستاورد اخیر کاری',
                'desc'       => 'سه خروجی مثبت، پروژه پایان‌یافته یا حل مسئله اخیرتان را با آمار و ارقام مستند کنید تا ارزش‌آفرینی‌تان ملموس و مستند باشد.',
            ],
            [
                'day_offset' => 17, 'time_note' => '12:30', 'priority' => 'normal',
                'title'      => '🤝 تقویت سرمایهٔ اجتماعی: گفت‌وگوی دوستانه با یک همکار',
                'desc'       => 'یک وقت ناهار یا چای کاری با همکاران ترتیب دهید و پیرامون موضوعات موردعلاقه یا تجارب تیمی هم‌فکری کنید.',
            ],
            [
                'day_offset' => 21, 'time_note' => '18:00', 'priority' => 'high',
                'title'      => '🚧 تعیین مرزهای حرفه‌ای سالم میان کار و زندگی شخصی',
                'desc'       => 'ساعات کاری را معین کنید و پس از آن پیام‌ها یا ایمیل‌های غیرفوری را بررسی نکنید تا فرسودگی شغلی مهار شود.',
            ],
            [
                'day_offset' => 25, 'time_note' => '10:00', 'priority' => 'normal',
                'title'      => '🧭 تدوین نقشهٔ توسعه مهارت‌های تخصصی ۶ ماهه',
                'desc'       => 'دو مهارتی که می‌تواند رتبه یا کارایی شما را در حرفه‌تان جهش دهد مشخص و یک ساعت در هفته را برای یادگیری‌اش مسدود کنید.',
            ],
            [
                'day_offset' => 28, 'time_note' => '15:00', 'priority' => 'high',
                'title'      => '🎯 آزمون مجدد پیگیری رضایت شغلی و سنجش دلتای ارتقا',
                'desc'       => 'مجدداً تست رضایت شغلی را تکمیل کنید تا اثربخشی تمرین‌های ارتباطی و بازطراحی کارتان در نمودار دلتا سنجیده شود.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای استعدادهای چندگانه گاردنر (Multiple Intelligences)
     */
    private static function buildGardnerGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '10:00', 'priority' => 'high',
                'title'      => '🌟 به‌کارگیری هوش برتر در یک چالش روزمره',
                'desc'       => 'حیطهٔ هوشی که بالاترین نمره را در کارنامه داشته مشخص کنید و امروز یک مسئله کاری یا تحصیلی را با ابزارهای آن حیطه حل کنید.',
            ],
            [
                'day_offset' => 5, 'time_note' => '17:30', 'priority' => 'normal',
                'title'      => '🧩 تحریک هوش تحلیلی-منطقی: حل مسئله ساختاریافته',
                'desc'       => 'یک چالش یا تصمیم پیچیده را به متغیرهای کوچک‌تر بشکنید، جدول مزایا-معایب تشکیل دهید و وزن هر گزینه را ارزیابی نمایید.',
            ],
            [
                'day_offset' => 9, 'time_note' => '12:00', 'priority' => 'high',
                'title'      => '👥 تقویت هوش بین‌فردی: گفت‌وگوی عمیق و همدلانه',
                'desc'       => 'در ارتباط با فردی از اعضای خانواده یا همکاران، احساسات و زبان بدن او را دقیق مشاهده کنید و نشان دهید متوجه حال او شده‌اید.',
            ],
            [
                'day_offset' => 13, 'time_note' => '20:30', 'priority' => 'normal',
                'title'      => '🧘 تقویت هوش درون‌فردی: ثبت بازتاب شخصی ۱۰ دقیقه‌ای',
                'desc'       => 'در سکوت بنشینید و انگیزه‌ها، ترس‌ها و اهداف قلبی هفتهٔ اخیر خود را مکتوب کنید تا خودآگاهی درونی عمیق‌تر گردد.',
            ],
            [
                'day_offset' => 17, 'time_note' => '16:00', 'priority' => 'normal',
                'title'      => '🌿 تقویت هوش بدنی-طبیعت‌گرایانه: فعالیت فیزیکی در فضای باز',
                'desc'       => 'حداقل ۳۰ دقیقه فعالیت حرکتی، ورزش یا راهپیمایی در پارک یا طبیعت داشته باشید و به بوها، رنگ‌ها و بافت‌های طبیعی توجه کنید.',
            ],
            [
                'day_offset' => 21, 'time_note' => '19:00', 'priority' => 'normal',
                'title'      => '📚 تقویت هوش زبانی-کلامی: نگارش خلاصه یا روایت یک تجربه',
                'desc'       => 'یک ایده، داستان یا درس مهم زندگی را در قالب چند بند منسجم و جذاب بنویسید یا برای فردی دیگر تعریف کنید.',
            ],
            [
                'day_offset' => 25, 'time_note' => '15:00', 'priority' => 'high',
                'title'      => '🔄 تلفیق دو هوش متفاوت در انجام یک پروژه',
                'desc'       => 'یک کار را با ترکیب دو بعد هوشی (مثلاً دیداری-فضایی همراه با کلامی، یا منطقی با موسیقی) با روشی نوین به سرانجام برسانید.',
            ],
            [
                'day_offset' => 28, 'time_note' => '11:00', 'priority' => 'high',
                'title'      => '🎯 آزمون مجدد پیگیری استعدادها (Retest گاردنر)',
                'desc'       => 'آزمون استعدادهای چندگانه را تکرار کنید تا فعال‌شدن مسیرهای عصبی تازه و رشد همه‌جانبهٔ توانمندی‌هایتان در پلنر ثبت گردد.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای هوش هیجانی (EQ Shutte / Bar-On)
     */
    private static function buildEqGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 1, 'time_note' => '09:00', 'priority' => 'high',
                'title'      => '🏷 برچسب‌گذاری دقیق هیجان‌ها (Name It to Tame It)',
                'desc'       => 'امروز به جای گفتن «حالم بده»، هیجان دقیق خود را مشخص کنید: آیا غمگین هستید، مضطربید، احساس تنهایی می‌کنید یا خشمگین‌اید؟ برچسب‌زدن بار هیجان را سبک می‌کند.',
            ],
            [
                'day_offset' => 4, 'time_note' => '13:30', 'priority' => 'high',
                'title'      => '⏸ تمرین مکث ۳۰ ثانیه‌ای میان محرک و واکنش',
                'desc'       => 'هنگام مواجهه با یک خبر، پیام ناخوشایند یا انتقاد، ۳۰ ثانیه هیچ عملی انجام ندهید؛ فقط به تپش قلب و انقباض عضلات نگاه کنید و سپس پاسخ دهید.',
            ],
            [
                'day_offset' => 8, 'time_note' => '17:00', 'priority' => 'normal',
                'title'      => '👂 گوش‌دادن همدلانه و بازتاب احساسات گوینده',
                'desc'       => 'در یک گفت‌وگو، جملهٔ طرف مقابل را با زبان خود بازگو کنید: «به نظر می‌رسد از این موضوع خیلی ناراحت شده‌ای؛ درست متوجه شدم؟».',
            ],
            [
                'day_offset' => 12, 'time_note' => '20:00', 'priority' => 'high',
                'title'      => '🌊 تنظیم هیجان: تکنیک بازتنظیم شناختی در تنش',
                'desc'       => 'وقتی از دست فردی عصبانی شدید، از خود بپرسید: «آیا او عمداً این کار را کرد یا خودش هم تحت فشار است؟ زاویه دید او چیست؟».',
            ],
            [
                'day_offset' => 16, 'time_note' => '11:00', 'priority' => 'normal',
                'title'      => '🧭 هدایت آگاهانه هیجان مثبت برای ایجاد انگیزه',
                'desc'       => 'قبل از شروع یک کار سنگین، خاطرهٔ یک پیروزی یا لحظهٔ خوشایند را به یاد آورید تا انرژی و تمرکز بالاتری به دست آورید.',
            ],
            [
                'day_offset' => 20, 'time_note' => '16:30', 'priority' => 'high',
                'title'      => '🤝 تمرین قاطعیت همدلانه: ابراز احترام‌آمیز مرزها',
                'desc'       => 'نیاز یا ناراحتی خود را با الگوی استاندارد بیان کنید: «وقتی [رفتار] اتفاق می‌افتد، من احساس [هیجان] می‌کنم، و ترجیح می‌دهم [درخواست]».',
            ],
            [
                'day_offset' => 25, 'time_note' => '21:00', 'priority' => 'normal',
                'title'      => '✍️ رصد چرخهٔ هیجانات و محرک‌های تکرارشونده',
                'desc'       => 'در پایان هفته مرور کنید کدام اتفاق‌ها بیشترین نوسان خلقی را در شما ایجاد کردند و چطور می‌توانید واکنش‌های بهتری طراحی کنید.',
            ],
            [
                'day_offset' => 28, 'time_note' => '14:00', 'priority' => 'high',
                'title'      => '🎯 آزمون مجدد پیگیری هوش هیجانی (Retest EQ)',
                'desc'       => 'با تکرار آزمون هوش هیجانی، میزان ارتقای خودآگاهی، همدلی و مهارت‌های تنظیم هیجان را در گذر زمان بسنجید.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای طرحواره‌های یانگ (Young Schema Questionnaire - YSQ)
     */
    private static function buildYsqGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '19:00', 'priority' => 'high',
                'title'      => '🔍 ثبت دفترچهٔ طرحواره (Schema Diary)',
                'desc'       => 'محرکی که امروز حس ناخوشایند قدیمی را زنده کرد یادداشت کنید: چه اتفاقی افتاد؟ صدای کدام باور کهنه در سرتان پیچید؟',
            ],
            [
                'day_offset' => 6, 'time_note' => '10:00', 'priority' => 'high',
                'title'      => '🛡 فعال‌سازی حالت بزرگسال سالم (Healthy Adult Mode)',
                'desc'       => 'در برابر والد سرزنش‌گر درونی با قاطعیت بایستید و به خود یادآوری کنید: «من امروز انسانی مستقل، توانا و بالغ هستم و خطاهایم ارزش وجودی‌ام را کم نمی‌کند».',
            ],
            [
                'day_offset' => 10, 'time_note' => '15:30', 'priority' => 'high',
                'title'      => '🚧 تعیین یک مرز ارتباطی شفاف و سالم',
                'desc'       => 'اگر درگیر ایثار یا اطاعت افراطی هستید، امروز به درخواستی که فراتر از توان شماست با احترام نه بگویید یا خواسته خود را صریحاً طلب کنید.',
            ],
            [
                'day_offset' => 14, 'time_note' => '21:00', 'priority' => 'normal',
                'title'      => '💔 تمرین نگارش نامه به بخش آسیب‌پذیر وجود',
                'desc'       => 'یک نامهٔ مهربان به خودتان بنویسید (صرفاً برای بازخوانی شخصی) و به احساس تنهایی، نقص یا ترس‌های کودکی‌تان توجه و شفقت نثار کنید.',
            ],
            [
                'day_offset' => 18, 'time_note' => '12:00', 'priority' => 'high',
                'title'      => '🔄 شکستن یک الگوی رفتاری خودتخریب‌گر در عمل',
                'desc'       => 'یک رفتار شرطی‌شده (مانند اجتناب از صحبت، کنترل‌گری افراطی یا سکوت پر از خشم) را در یک موقعیت واقعی با رفتاری بالغانه جایگزین کنید.',
            ],
            [
                'day_offset' => 22, 'time_note' => '17:30', 'priority' => 'normal',
                'title'      => '🤝 طلب صریح حمایت عاطفی از فردی مطمئن',
                'desc'       => 'بدون انتظار اینکه دیگران ذهن شما را بخوانند، مستقیماً بگویید: «امروز نیاز دارم کمی با تو حرف بزنم یا همراهی‌ام کنی».',
            ],
            [
                'day_offset' => 25, 'time_note' => '20:00', 'priority' => 'normal',
                'title'      => '🌿 مرور شواهد نقض‌کننده باورهای طرحواره‌ای',
                'desc'       => 'حداقل ۵ رویداد واقعی از سال‌های اخیر بنویسید که نشان می‌دهند شما فردی ارزشمند، دوست‌داشتنی و توانا هستید.',
            ],
            [
                'day_offset' => 28, 'time_note' => '16:30', 'priority' => 'high',
                'title'      => '🎯 آزمون مجدد پیگیری طرحواره‌ها (Retest YSQ)',
                'desc'       => 'آزمون طرحواره‌های ناسازگار اولیه را تکرار کنید تا کاهش شدت برانگیختگی طرحواره‌ها و رشد بخش بزرگسال سالم‌تان را رصد کنید.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای MBTI (شخصیت‌شناسی مایرز-بریگز)
     */
    private static function buildMbtiGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '10:00', 'priority' => 'high',
                'title'      => '🧭 خودآگاهی تیپ شخصیتی و سبک ترجیحی تصمیم‌گیری',
                'desc'       => 'نقاط قوت تیپ شخصیتی خود را مرور کنید و بنویسید در کدام بخش‌های زندگی از این ترجیحات بهترین استفاده را می‌برید.',
            ],
            [
                'day_offset' => 6, 'time_note' => '14:30', 'priority' => 'normal',
                'title'      => '🤝 درک زاویهٔ دید فردی با تیپ متضاد',
                'desc'       => 'در یک موضوع کاری یا خانوادگی، مسئله را از نگاه فردی با ترجیحات شخصیتی متفاوت تحلیل کنید و ارزش دیدگاه او را تصدیق نمایید.',
            ],
            [
                'day_offset' => 10, 'time_note' => '18:00', 'priority' => 'high',
                'title'      => '⚖️ تعادل میان عقلانیت و ملاحظات انسانی (T/F)',
                'desc'       => 'در تصمیم‌گیری امروز علاوه بر معیارهای منطقی، تأثیر تصمیم بر احساسات و روابط انسانی افراد درگیر را نیز لحاظ کنید.',
            ],
            [
                'day_offset' => 15, 'time_note' => '09:30', 'priority' => 'normal',
                'title'      => '📋 تمرین انعطاف در کنار ساختارمندی (J/P)',
                'desc'       => 'اگر ساختارگرا هستید، فضایی برای رویدادهای غیرمنتظره باز بگذارید؛ و اگر منعطف هستید، یک کار را با زمان‌بندی دقیق تا پایان پیش ببرید.',
            ],
            [
                'day_offset' => 20, 'time_note' => '16:00', 'priority' => 'normal',
                'title'      => '🔍 توجه آگاهانه به واقعیات ملموس در برابر فرضیات (S/N)',
                'desc'       => 'به جای اکتفا به حدس و شهود ذهنی، جزئیات عینی، داده‌ها و مشاهدات مستقیم را ثبت و بررسی نمایید.',
            ],
            [
                'day_offset' => 25, 'time_note' => '20:30', 'priority' => 'normal',
                'title'      => '✍️ یادداشت تعارضات ناشی از تفاوت تیپ‌ها و راه‌حل‌ها',
                'desc'       => 'یک سوءتفاهم اخیر را بازبینی کرده و ریشهٔ آن در تفاوت سبک پردازش اطلاعات و تعامل را تفکیک کنید.',
            ],
            [
                'day_offset' => 28, 'time_note' => '11:00', 'priority' => 'high',
                'title'      => '🎯 آزمون مجدد پیگیری تیپ‌شناسی (Retest MBTI)',
                'desc'       => 'آزمون را در پلنر تکرار کنید تا میزان تعادل‌بخشی به ابعاد چهارگانه شخصیت خود را پایش نمایید.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای رغبت‌سنجی شغلی هالند (Holland RIASEC)
     */
    private static function buildHollandGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '11:00', 'priority' => 'high',
                'title'      => '🔍 تطبیق شرح وظایف فعلی با کدهای سه‌گانه هالند',
                'desc'       => 'سه رغبت غالب خود را یادداشت کرده و ببینید چند درصد از فعالیت‌های هفتگی شما با این حوزه‌ها منطبق است.',
            ],
            [
                'day_offset' => 6, 'time_note' => '16:00', 'priority' => 'normal',
                'title'      => '🚀 اجرای یک فعالیت آزمایشی ۱ ساعته در حوزهٔ رغبت اول',
                'desc'       => 'اگر رغبت اول هنری، تحقیقی یا سازمانی است، یک ریزپروژه کوتاه مرتبط با آن حوزه را به‌صورت داوطلبانه تجربه کنید.',
            ],
            [
                'day_offset' => 11, 'time_note' => '13:00', 'priority' => 'normal',
                'title'      => '👥 گفت‌وگوی اکتشافی با یک شاغل موفق در حیطهٔ موردعلاقه',
                'desc'       => 'با فردی که در رغبت غالب شما کار می‌کند گفت‌وگو کنید و از فرصت‌ها و چالش‌های روزمره کارش بپرسید.',
            ],
            [
                'day_offset' => 16, 'time_note' => '19:00', 'priority' => 'high',
                'title'      => '📚 یادگیری یک مهارت جدید همسو با رغبت شغلی',
                'desc'       => 'یک دوره کوتاه، کتاب یا نرم‌افزار کلیدی در حوزه رغبت شغلی‌تان انتخاب کرده و فصل اول را آغاز نمایید.',
            ],
            [
                'day_offset' => 21, 'time_note' => '15:00', 'priority' => 'normal',
                'title'      => '🧭 تدوین ماتریس تطابق شغلی و گزینه‌های تغییر',
                'desc'       => 'سه مسیر شغلی احتمالی را مقایسه کنید و موانع و گام‌های عملی ورود به هرکدام را روی کاغذ بیاورید.',
            ],
            [
                'day_offset' => 25, 'time_note' => '18:00', 'priority' => 'normal',
                'title'      => '🤝 عضویت در یک گروه یا انجمن تخصصی حرفه‌ای',
                'desc'       => 'در شبکه‌های حرفه‌ای، وبینارها یا انجمن‌های تخصصی حوزه رغبت‌تان حضور یابید و دایره ارتباطات کاری را گسترش دهید.',
            ],
            [
                'day_offset' => 28, 'time_note' => '12:00', 'priority' => 'high',
                'title'      => '🎯 آزمون مجدد رغبت‌سنجی شغلی (Retest هالند)',
                'desc'       => 'با شرکت مجدد در آزمون هالند، وضوح علایق و انطباق مسیر تحصیلی/شغلی خود را در پلنر ارزیابی کنید.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد بر مبنای ۵ عاملی شخصیت NEO-FFI
     */
    private static function buildNeoGrowthTasks(array $map, array $at = []): array
    {
        $n = (float)($map['N']['percent'] ?? 50);
        $e = (float)($map['E']['percent'] ?? 50);
        $o = (float)($map['O']['percent'] ?? 50);
        $a = (float)($map['A']['percent'] ?? 50);
        $c = (float)($map['C']['percent'] ?? 50);

        $tasks = [];

        // بعد N (روان‌رنجورخویی / حساسیت به استرس)
        if ($n > 55) {
            $tasks[] = [
                'day_offset' => 2, 'time_note' => '18:00', 'priority' => 'high',
                'title'      => '🧠 تمرین CBT: تفکیک فاجعه‌سازی از واقعیت عینی',
                'desc'       => 'وقتی اضطراب یا افکار منفی بالا آمد بنویسید: «بدترین حالت چیست؟ احتمال واقعی آن چقدر است؟ راه مقابله‌ام چیست؟».',
            ];
            $tasks[] = [
                'day_offset' => 10, 'time_note' => '21:00', 'priority' => 'high',
                'title'      => '🫁 تنفس عمیق و آرام‌سازی هنگام افت خلق یا خشم',
                'desc'       => '۵ دقیقه تنفس آرام با بازدم‌های طولانی داشته باشید تا برانگیختگی فیزیولوژیک مغز فروکش کند.',
            ];
        } else {
            $tasks[] = [
                'day_offset' => 2, 'time_note' => '09:00', 'priority' => 'normal',
                'title'      => '🛡 تقویت تاب‌آوری در برابر ابهامات جدید',
                'desc'       => 'از آرامش و ثبات هیجانی خود برای مدیریت موقعیت‌های پیش‌بینی‌نشده این هفته استفاده کنید.',
            ];
        }

        // بعد E (برون‌گرایی و انرژی اجتماعی)
        if ($e < 45) {
            $tasks[] = [
                'day_offset' => 5, 'time_note' => '12:30', 'priority' => 'normal',
                'title'      => '☕ تجربهٔ یک گپ کوتاه ۵ دقیقه‌ای با فرد جدید',
                'desc'       => 'در محیط کار، تحصیل یا مکان‌های عمومی، یک مکالمهٔ کوتاه و دوستانه دربارهٔ موضوعی ساده آغاز کنید.',
            ];
            $tasks[] = [
                'day_offset' => 14, 'time_note' => '19:00', 'priority' => 'normal',
                'title'      => '👥 حضور فعال در یک جمع صمیمی بدون انزوا',
                'desc'       => 'در یک دورهمی دوستانه شرکت کرده و یک تجربه شخصی یا نظر خود را با دیگران به اشتراک بگذارید.',
            ];
        } else {
            $tasks[] = [
                'day_offset' => 5, 'time_note' => '20:00', 'priority' => 'normal',
                'title'      => '🧘 تمرین ۳۰ دقیقه خلوت درونی و سکوت بدون گوشی',
                'desc'       => 'برای بازیابی انرژی درونی، نیم ساعت در آرامش به مطالعه یا بازتاب شخصی بپردازید.',
            ];
        }

        // بعد C (باوجدانی، مسئولیت‌پذیری و تمرکز)
        if ($c < 45) {
            $tasks[] = [
                'day_offset' => 4, 'time_note' => '09:00', 'priority' => 'high',
                'title'      => '⏱ اجرای دو بلوک ۲۵ دقیقه‌ای تمرکز عمیق (پومودورو)',
                'desc'       => 'یک کار مهم را بدون هیچ حواس‌پرتی یا نگاه به شبکه‌های اجتماعی دقیقاً ۲۵ دقیقه پیش ببرید.',
            ];
            $tasks[] = [
                'day_offset' => 17, 'time_note' => '08:30', 'priority' => 'high',
                'title'      => '📋 ثبت ۳ اولویت غیرقابل‌مذاکرهٔ امروز',
                'desc'       => 'پیش از پراکنده‌شدن ذهن، سه دستاورد اصلی روز را مشخص کرده و تا اولی تمام نشده سراغ بعدی نروید.',
            ];
        } else {
            $tasks[] = [
                'day_offset' => 4, 'time_note' => '17:00', 'priority' => 'normal',
                'title'      => '✨ تمرین خودشفقت‌ورزی و رهایی از سرزنش بابت تأخیرها',
                'desc'       => 'اگر کاری دقیقاً طبق برنامه پیش نرفت، به جای انتقاد شدید به خود فرصت بازسازی و استراحت بدهید.',
            ];
        }

        // بعد A (سازگاری و همدلی)
        if ($a < 45) {
            $tasks[] = [
                'day_offset' => 8, 'time_note' => '13:00', 'priority' => 'normal',
                'title'      => '👂 تمرین دیدگاه‌گیری همدلانه در یک اختلاف نظر',
                'desc'       => 'حق را حداقل برای ۵ دقیقه به طرف مقابل بدهید و سعی کنید دنیا را از عینک نیازهای او تماشا کنید.',
            ];
        } else {
            $tasks[] = [
                'day_offset' => 8, 'time_note' => '16:00', 'priority' => 'normal',
                'title'      => '🚧 حفاظت از مرزهای شخصی در برابر سوءاستفاده',
                'desc'       => 'مراقب باشید سازگاری بالا مانع دفاع از حقوق و خواسته‌های برحق خودتان نشود.',
            ];
        }

        // بعد O (گشودگی به تجربه)
        if ($o < 45) {
            $tasks[] = [
                'day_offset' => 12, 'time_note' => '15:30', 'priority' => 'normal',
                'title'      => '💡 تجربهٔ یک غذای جدید، موسیقی نو یا مسیر تازه',
                'desc'       => 'یکی از الگوهای روزمره را عمداً بشکنید و یک تجربه حسی تازه را امتحان کنید.',
            ];
        }

        // تثبیت و ارزیابی
        $tasks[] = [
            'day_offset' => 22, 'time_note' => '20:00', 'priority' => 'normal',
            'title'      => '📊 ثبت دفترچهٔ سپاسگزاری و مرور تعادل شخصیتی',
            'desc'       => 'سه نکته مثبت و پیشرفت‌های رفتاری هفته‌های اخیر را یادداشت نمایید.',
        ];
        $tasks[] = [
            'day_offset' => 28, 'time_note' => '11:00', 'priority' => 'high',
            'title'      => '🎯 آزمون مجدد پیگیری شخصیت نئو (Retest NEO)',
            'desc'       => 'با شرکت مجدد در تست نئو، میزان تغییرات شاخص‌های ۵گانه را در برگه پایش رشد آزمون‌ها مقایسه کنید.',
        ];

        return $tasks;
    }

    /**
     * تمرین‌های رشد بالینی MMPI
     */
    private static function buildMmpiGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '09:00', 'priority' => 'high',
                'title'      => '🧘 پایش سطح اضطراب و آرام‌سازی تنفسی صبحگاهی',
                'desc'       => '۵ دقیقه تمرین تنفس آرام شکمی برای تثبیت ریتم ضربان قلب و آرام‌سازی سیستم عصبی مرکزی.',
            ],
            [
                'day_offset' => 6, 'time_note' => '14:00', 'priority' => 'normal',
                'title'      => '📝 ثبت الگوهای فکری پرتنش در موقعیت‌های بین‌فردی',
                'desc'       => 'در تعارض‌ها یا احساس فشار، افکار خودکار اولیه را بدون قضاوت یادداشت کنید.',
            ],
            [
                'day_offset' => 11, 'time_note' => '17:30', 'priority' => 'high',
                'title'      => '🌿 تقویت انعطاف شناختی در برابر سناریوهای منفی',
                'desc'       => 'برای موقعیت‌های مبهم حداقل دو تفسیر مثبت یا خنثی در نظر بگیرید تا از سوگیری منفی کاسته شود.',
            ],
            [
                'day_offset' => 16, 'time_note' => '20:30', 'priority' => 'normal',
                'title'      => '🤝 گفت‌وگوی امن و دریافت بازخورد حمایتی از مشاور یا فرد معتمد',
                'desc'       => 'درباره فشارهای اخیر با یک همراه امن و امین گفت‌وگو کنید تا بار هیجانی ایزوله نشود.',
            ],
            [
                'day_offset' => 21, 'time_note' => '11:00', 'priority' => 'high',
                'title'      => '🚧 تثبیت مرزهای روانی و خودپایی در فشارهای محیطی',
                'desc'       => 'از ورود به تنش‌های غیرضروری اطرافیان دوری کرده و زمان استراحت فردی خود را محفوظ نگه دارید.',
            ],
            [
                'day_offset' => 28, 'time_note' => '15:00', 'priority' => 'high',
                'title'      => '🎯 پایش روند شاخص‌های بالینی و ارزیابی مجدد پیگیری',
                'desc'       => 'ارزیابی پیگیری را تکمیل کنید تا نمودار تغییرات پروفایل شخصیتی شما در پلنر به‌روزرسانی شود.',
            ],
        ];
    }

    /**
     * تمرین‌های رشد شناختی (Raven / WAIS)
     */
    private static function buildCognitiveGrowthTasks(array $map, array $at = []): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '10:00', 'priority' => 'high',
                'title'      => '🧩 تمرین حل پازل و استدلال تحلیلی (Brain Gym)',
                'desc'       => '۱۵ دقیقه زمان برای حل معماهای منطقی، پترن‌های بصری یا سودوکو اختصاص دهید تا سیالی تفکر تقویت شود.',
            ],
            [
                'day_offset' => 6, 'time_note' => '15:00', 'priority' => 'normal',
                'title'      => '🧠 تمرین حافظهٔ کاری و تمرکز بدون وقفه',
                'desc'       => 'یک فهرست ۷ آیتمی را به خاطر بسپارید و پس از ۳۰ دقیقه فعالیت دیگر، آن را بازخوانی کنید.',
            ],
            [
                'day_offset' => 11, 'time_note' => '18:00', 'priority' => 'normal',
                'title'      => '💡 بازطراحی روش حل یک مسئلهٔ روزمره با الگوهای نو',
                'desc'       => 'یک کار تکراری را با یک روش خلاقانه و کاملاً متفاوت از گذشته بازآرایی و انجام دهید.',
            ],
            [
                'day_offset' => 17, 'time_note' => '11:30', 'priority' => 'high',
                'title'      => '📚 مطالعه تحلیلی یک مقاله عمیق و خلاصه‌برداری مفهومی',
                'desc'       => 'یک متن تخصصی یا فلسفی را بخوانید و ارتباط اجزای آن را در قالب نقشه ذهنی (Mind Map) ترسیم کنید.',
            ],
            [
                'day_offset' => 23, 'time_note' => '16:00', 'priority' => 'normal',
                'title'      => '🏃 تمرین هوازی برای ارتقای نوروژنز و خون‌رسانی مغزی',
                'desc'       => 'حداقل ۲۵ دقیقه ورزش هوازی با شدت متوسط انجام دهید تا فاکتور BDNF برای رشد مدارهای عصبی آزاد شود.',
            ],
            [
                'day_offset' => 28, 'time_note' => '12:00', 'priority' => 'high',
                'title'      => '🎯 ارزیابی مجدد سرعت و دقت شناختی در آزمون پیگیری',
                'desc'       => 'با تکرار آزمون استدلال و هوش، تغییرات سرعت پردازش و دقت الگوها را پایش کنید.',
            ],
        ];
    }

    /**
     * تمرین‌های عمومی جامع برای سایر آزمون‌های روانشناختی
     */
    private static function buildGenericGrowthTasks(array $map, string $testTitle = 'آزمون روانشناختی'): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '09:00', 'priority' => 'high',
                'title'      => '🎯 تحلیل کارنامهٔ «' . $testTitle . '» و انتخاب یک رفتار هدف',
                'desc'       => 'نتایج و توصیه‌های کارنامه را مرور کرده و یک نقطه بهبود ملموس برای تمرین در این ماه مشخص کنید.',
            ],
            [
                'day_offset' => 5, 'time_note' => '12:00', 'priority' => 'normal',
                'title'      => '🌿 تمرین ذهن‌آگاهی تنفس و تثبیت آرامش درونی',
                'desc'       => 'روزی ۵ دقیقه در سکوت به جریان طبیعی تنفس خود توجه کنید تا استرس کاهش یافته و تمرکز افزایش یابد.',
            ],
            [
                'day_offset' => 9, 'time_note' => '15:30', 'priority' => 'normal',
                'title'      => '💬 گفت‌وگوی امن و بدون قضاوت با زبان «من»',
                'desc'       => 'در یک گفت‌وگوی چالش‌برانگیز این هفته، به جای سرزنش دیگران احساس و نیاز مستقیم خود را بیان کنید.',
            ],
            [
                'day_offset' => 14, 'time_note' => '18:00', 'priority' => 'high',
                'title'      => '📈 ارزیابی دوهفته‌ای تغییرات و یادداشت نشانه‌های پیشرفت',
                'desc'       => 'یک برگه بردارید و موقعیت‌هایی را که توانسته‌اید خونسردی، تصمیم‌گیری بهتر یا قاطعیت نشان دهید ثبت کنید.',
            ],
            [
                'day_offset' => 19, 'time_note' => '20:30', 'priority' => 'normal',
                'title'      => '🤝 بازسازی شبکهٔ روابط و تبادل بازخورد مثبت',
                'desc'       => 'از یک فرد مورد اعتماد نظر بخواهید که چه تغییر مثبتی در رفتار یا خلق شما طی روزهای اخیر دیده است.',
            ],
            [
                'day_offset' => 24, 'time_note' => '10:00', 'priority' => 'normal',
                'title'      => '🧭 تنظیم برنامه‌ریزی شخصی هم‌راستا با ارزش‌های اصیل',
                'desc'       => 'برنامه کاری و فردی هفته پیش رو را به گونه‌ای بچینید که سلامت روان و رشد شخصی در اولویت باشد.',
            ],
            [
                'day_offset' => 28, 'time_note' => '14:00', 'priority' => 'high',
                'title'      => '🎯 آزمون مجدد پیگیری (Retest) و مشاهدهٔ نمودار دلتای رشد',
                'desc'       => 'با شرکت مجدد در آزمون در برگه «پایش رشد آزمون‌ها»، میزان پیشرفت و تثبیت تغییرات رفتاری‌تان را رصد نمایید.',
            ],
        ];
    }
}
