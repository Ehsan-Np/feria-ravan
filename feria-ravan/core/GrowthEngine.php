<?php
/**
 * ============================================================================
 *  موتور برنامهٔ عمل پویا و تمرین‌های رشد فریا روان (Growth Engine)
 *  ─ تبدیل نتایج آزمون به برنامهٔ عملیاتی ۴ تا ۸ هفته‌ای در پلنر روزانه
 *  ─ پایش پیشرفت و محاسبهٔ نمودار دلتا (Retest & Delta Tracking) در طول زمان
 * ============================================================================
 */
declare(strict_types=1);

final class GrowthEngine
{
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

        $engine = (string)$at['test_engine'];
        $tasks = match ($engine) {
            'disc'  => self::buildDiscGrowthTasks($map),
            'neo'   => self::buildNeoGrowthTasks($map),
            default => self::buildGenericGrowthTasks($map),
        };

        return [
            'attempt_id'     => $attemptId,
            'test_title'     => $at['test_title'],
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
        $plan = self::generatePlan($attemptId);
        if (empty($plan['tasks'])) {
            return ['ok' => false, 'message' => 'برنامهٔ رشدی برای این آزمون یافت نشد.'];
        }

        // ایجاد دسته «تمرین رشد کارنامه» با رنگ بنفش در پلنر کاربر
        $catName = 'تمرین رشد کارنامه';
        $catExists = DB::one('SELECT id FROM ' . DB::t('planner_cats') . ' WHERE user_id=? AND name=?', [$userId, $catName]);
        if (!$catExists) {
            DB::insert('planner_cats', [
                'user_id' => $userId,
                'name'    => $catName,
                'color'   => '#7c3aed',
                'sort'    => 1,
            ]);
        }

        // حذف تسک‌های قبلی همین آزمون (برای جلوگیری از تکرار در صورت تزریق مجدد)
        DB::run('DELETE FROM ' . DB::t('planner_tasks') . ' WHERE user_id=? AND ref_attempt_id=?', [$userId, $attemptId]);

        $baseDate = time();
        $injectedCount = 0;

        foreach ($plan['tasks'] as $t) {
            $dayOffset = (int)($t['day_offset'] ?? 1);
            $taskDate = date('Y-m-d', $baseDate + ($dayOffset * 86400));
            $remindTime = $t['time_note'] ?: '09:00';
            $remindAt = $taskDate . ' ' . $remindTime . ':00';

            DB::insert('planner_tasks', [
                'user_id'        => $userId,
                'ref_attempt_id' => $attemptId,
                'day'            => $taskDate,
                'title'          => $t['title'],
                'time_note'      => $t['time_note'] ?? '09:00',
                'priority'       => $t['priority'] ?? 'high',
                'category'       => $catName,
                'done'           => 0,
                'note'           => $t['desc'] ?? '',
                'remind_at'      => $remindAt,
            ]);
            $injectedCount++;
        }

        DB::update('attempts', ['growth_injected' => 1], 'id=?', [$attemptId]);

        return [
            'ok'             => true,
            'injected_count' => $injectedCount,
            'inserted'       => $injectedCount,
            'message'        => fa_num($injectedCount) . ' تمرین رشد تخصصی با موفقیت به برنامهٔ روزانهٔ شما اضافه شد! 🎯',
        ];
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

        $scales = [];
        $totalImprovement = 0;
        $cnt = 0;

        foreach ($m2 as $code => $r2) {
            if ($code === 'overall') continue;
            $p1 = (float)($m1[$code]['percent'] ?? 50);
            $p2 = (float)($r2['percent'] ?? 50);
            $delta = round($p2 - $p1, 1);
            $totalImprovement += abs($delta);
            $cnt++;

            $scales[$code] = [
                'code'         => $code,
                'title'        => $scaleTitles[$code] ?? $code,
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

    /** تمرین‌های رشد بر مبنای DISC */
    private static function buildDiscGrowthTasks(array $map): array
    {
        $d = (float)($map['D']['percent'] ?? 50);
        $i = (float)($map['I']['percent'] ?? 50);
        $s = (float)($map['S']['percent'] ?? 50);
        $c = (float)($map['C']['percent'] ?? 50);

        $tasks = [];

        // تمرین‌های مربوط به بعد D (قاطعیت و اقدام)
        if ($d < 45) {
            $tasks[] = [
                'day_offset' => 2, 'time_note' => '09:30', 'priority' => 'high',
                'title'      => '🎯 تمرین قاطعیت: «نه» محترمانه به یک درخواست غیرضروری',
                'desc'       => 'امروز به یک درخواست اضافه که در اولویت شما نیست با آرامش بگویید: «در حال حاضر امکان انجامش را ندارم». حس بعد از آن را یادداشت کنید.',
            ];
            $tasks[] = [
                'day_offset' => 9, 'time_note' => '11:00', 'priority' => 'high',
                'title'      => '🎯 تمرین ابراز صریح هدف در شروع گفت‌وگو',
                'desc'       => 'در یک تماس یا جلسهٔ کاری، در ۳۰ ثانیهٔ اول بدون مقدمه‌چینی اضافه اصل درخواست و خروجی موردنظرتان را بیان کنید.',
            ];
        } elseif ($d > 70) {
            $tasks[] = [
                'day_offset' => 3, 'time_note' => '14:00', 'priority' => 'normal',
                'title'      => '🧘 تمرین توقف و سکوت ۳۰ ثانیه‌ای در موقعیت چالش',
                'desc'       => 'پیش از آنکه در یک تعارض سریعاً واکنش نشان دهید، ۳ تنفس عمیق شکمی بکشید و بپرسید: «طرف مقابل دقیقاً چه حسی دارد؟».',
            ];
            $tasks[] = [
                'day_offset' => 10, 'time_note' => '10:00', 'priority' => 'normal',
                'title'      => '🤝 تمرین تفویض کامل یک تصمیم به دیگری',
                'desc'       => 'یکی از تصمیم‌های خانه یا تیم را کاملاً به فرد دیگر بسپارید و بدون دخالت اجازه دهید طبق سلیقه او پیش برود.',
            ];
        }

        // تمرین‌های مربوط به بعد S (ثبات، آرامش و صبوری)
        if ($s < 45) {
            $tasks[] = [
                'day_offset' => 4, 'time_note' => '08:00', 'priority' => 'high',
                'title'      => '🌿 تمرین ذهن‌آگاهی و تنفس آگاهانه ۵ دقیقه‌ای',
                'desc'       => 'صبح امروز بدون نگاه به گوشی، ۵ دقیقه در سکوت بنشینید و تنها به ریتم تنفس خود توجه کنید تا ریتم درونی‌تان آرام گیرد.',
            ];
            $tasks[] = [
                'day_offset' => 12, 'time_note' => '17:00', 'priority' => 'normal',
                'title'      => '👂 تمرین گوش‌دادن فعال (بدون قطع کلام)',
                'desc'       => 'در مکالمه امروز با یک دوست یا همسر، حداقل ۳ دقیقه تماماً بشنوید بدون آنکه بین حرفش بپرید یا راه‌حل ارائه دهید.',
            ];
        }

        // تمرین‌های مربوط به بعد C (نظم، دقت و ساختاریافتگی)
        if ($c < 45) {
            $tasks[] = [
                'day_offset' => 5, 'time_note' => '08:30', 'priority' => 'high',
                'title'      => '📋 ثبت مکتوب ۳ اولویت شفاف کاری امروز',
                'desc'       => 'پیش از شروع روز، ۳ کار اصلی را بنویسید و تا اولی تمام نشده به سراغ کارهای دیگر نروید.',
            ];
            $tasks[] = [
                'day_offset' => 14, 'time_note' => '16:00', 'priority' => 'normal',
                'title'      => '🔍 بازبینی نهایی پیش از ارسال پیام یا گزارش مهم',
                'desc'       => 'پیش از فشردن دکمه ارسال، ۲ دقیقه متن را مرور کنید تا خطاها و ابهامات آن اصلاح شوند.',
            ];
        }

        // تمرین‌های مربوط به بعد I (ارتباط و گرمای اجتماعی)
        if ($i < 45) {
            $tasks[] = [
                'day_offset' => 6, 'time_note' => '12:00', 'priority' => 'normal',
                'title'      => '💬 ارسال یک پیام قدردانی بی‌بهانه به دوست یا همکار',
                'desc'       => 'یک پیام صمیمانه برای تشکر از ویژگی یا کمکی که فردی به شما کرده بفرستید تا پل ارتباطی تقویت شود.',
            ];
        }

        // تمرین‌های عمومی تثبیت
        $tasks[] = [
            'day_offset' => 18, 'time_note' => '19:00', 'priority' => 'high',
            'title'      => '✍️ مرور تغییرات دو هفته اخیر و ثبت دستاوردها',
            'desc'       => 'یک برگه بردارید و بنویسید در کدام موقعیت‌ها توانستید واکنش متفاوت و بالغ‌تری نسبت به گذشته نشان دهید.',
        ];
        $tasks[] = [
            'day_offset' => 24, 'time_note' => '11:00', 'priority' => 'normal',
            'title'      => '🎯 آمادگی برای آزمون پیگیری (Follow-up Retest)',
            'desc'       => 'با ورود به بخش تست‌های من در فریا، مجدداً آزمون را انجام دهید تا نمودار دلتا و پیشرفت رفتاری‌تان ترسیم شود.',
        ];

        return $tasks;
    }

    /** تمرین‌های رشد بر مبنای NEO */
    private static function buildNeoGrowthTasks(array $map): array
    {
        $n = (float)($map['N']['percent'] ?? 50);
        $e = (float)($map['E']['percent'] ?? 50);
        $c = (float)($map['C']['percent'] ?? 50);

        $tasks = [];
        if ($n > 60) {
            $tasks[] = [
                'day_offset' => 2, 'time_note' => '18:00', 'priority' => 'high',
                'title'      => '🧠 تمرین CBT: تفکیک فاجعه‌سازی از واقعیت عینی',
                'desc'       => 'وقتی اضطراب بالا آمد، روی کاغذ بنویسید: «بدترین اتفاق ممکن چیست؟ احتمال وقوعش چقدر است؟ راه مقابله‌ام چیست؟».',
            ];
        }
        if ($e < 40) {
            $tasks[] = [
                'day_offset' => 5, 'time_note' => '12:30', 'priority' => 'normal',
                'title'      => '☕ تجربهٔ یک گپ کوتاه ۵ دقیقه‌ای با فرد جدید',
                'desc'       => 'در محیط کار یا کافه، یک مکالمهٔ کوتاه و دوستانه دربارهٔ موضوعی ساده آغاز کنید.',
            ];
        }
        if ($c < 45) {
            $tasks[] = [
                'day_offset' => 4, 'time_note' => '09:00', 'priority' => 'high',
                'title'      => '⏱ اجرای یک بلوک ۲۵ دقیقه‌ای تمرکز پومودورو',
                'desc'       => 'یک کار مهم را بدون هیچ حواس‌پرتی و نوتیفیکیشنی دقیقاً ۲۵ دقیقه پیش ببرید.',
            ];
        }
        $tasks[] = [
            'day_offset' => 15, 'time_note' => '20:00', 'priority' => 'normal',
            'title'      => '📊 ثبت دفترچهٔ سپاسگزاری ۳ موردی در پایان روز',
            'desc'       => 'سه نکتهٔ مثبت کوچک امروز را قبل از خواب یادداشت کنید.',
        ];
        return $tasks;
    }

    /** تمرین‌های عمومی */
    private static function buildGenericGrowthTasks(array $map): array
    {
        return [
            [
                'day_offset' => 2, 'time_note' => '09:00', 'priority' => 'high',
                'title'      => '🎯 بازخوانی خلاصهٔ کارنامه و انتخاب یک رفتار هدف',
                'desc'       => 'یک ویژگی کلیدی از کارنامه را که مایلید روی آن کار کنید مشخص نمایید.',
            ],
            [
                'day_offset' => 7, 'time_note' => '15:00', 'priority' => 'normal',
                'title'      => '🌿 تمرین گفت‌وگوی امن و بدون قضاوت در خانواده/محیط کار',
                'desc'       => 'در یکی از موقعیت‌های چالش‌برانگیز این هفته، از تکنیک بیان احساسات با زبان «من» استفاده کنید.',
            ],
            [
                'day_offset' => 14, 'time_note' => '18:00', 'priority' => 'normal',
                'title'      => '📈 ارزیابی دوهفته‌ای تغییرات و یادداشت نشانه‌های پیشرفت',
                'desc'       => 'آیا در موقعیت‌های استرس‌زا توانسته‌اید خونسردی یا شفافیت بیشتری داشته باشید؟',
            ],
        ];
    }
}
