<?php
/**
 * ============================================================================
 *  موتور تحلیل بالینی و تشخیصی مشاور فریا روان (Clinical Analyzer)
 *  ─ تولید خلاصه پرونده محرمانهٔ روان‌شناس / روان‌درمانگر
 *  ─ ارزیابی شاخص‌های ریسک (Red Flags)، فرضیه‌سازی تشخیصی و مکانیسم‌های دفاعی
 *  ─ نقاط ورود به اتاق درمان، مدیریت مقاومت‌های مراجع و تکالیف خانگی
 * ============================================================================
 */
declare(strict_types=1);

final class ClinicalAnalyzer
{
    /**
     * تولید خلاصه پرونده جامع بالینی برای مشاور و درمانگر
     */
    public static function generateCaseSummary(int $attemptId): ?array
    {
        return self::generateSummary($attemptId);
    }

    public static function generateSummary(int $attemptId): ?array
    {
        $at = DB::one('SELECT a.*, t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine
                       FROM ' . DB::t('attempts') . ' a
                       JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
                       WHERE a.id=?', [$attemptId]);
        if (!$at) return null;

        $user = DB::one('SELECT id, first_name, last_name, mobile, email, created_at FROM ' . DB::t('users') . ' WHERE id=?', [$at['user_id']]);
        $results = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$attemptId]);
        $map = []; foreach ($results as $r) { $map[$r['scale_code']] = $r; }

        $validity = json_decode((string)($at['validity_json'] ?? ''), true) ?: [];

        $engine = (string)$at['test_engine'];
        $redFlags = [];
        $hypotheses = [];
        $resistances = [];
        $interventions = [];

        // ۱. ارزیابی شاخص‌های ریسک و خطوط قرمز (Risk Assessment & Red Flags)
        if (!empty($validity['flags'])) {
            foreach ($validity['flags'] as $vf) {
                if ($vf['code'] === 'rush') {
                    $redFlags[] = [
                        'level' => 'warn',
                        'title' => 'پاسخ‌دهی شتاب‌زده (احتمال کم‌توجهی یا بیش‌فعالی)',
                        'desc'  => 'مراجع تست را در زمانی کمتر از استاندارد انجام داده است؛ در جلسه اول لازم است صحت پاسخ‌ها با پرسش‌های کاوشگرانه سنجیده شود.',
                    ];
                } elseif ($vf['code'] === 'straight_line') {
                    $redFlags[] = [
                        'level' => 'danger',
                        'title' => 'عدم تمایز پاسخ‌ها (الگوی خطی)',
                        'desc'  => 'احتمال مقاومت در برابر سنجش یا بی‌انگیزگی شدید؛ نیازمند ارزیابی کیفی چهره‌به‌چهره.',
                    ];
                }
            }
        }

        // ۲. ارزیابی بالینی تخصصی بر اساس موتور
        if ($engine === 'disc') {
            $d = (float)($map['D']['percent'] ?? 50);
            $i = (float)($map['I']['percent'] ?? 50);
            $s = (float)($map['S']['percent'] ?? 50);
            $c = (float)($map['C']['percent'] ?? 50);

            // فرضیه‌سازی دینامیک روانی
            if ($d >= 70 && $s <= 40) {
                $hypotheses[] = [
                    'title' => 'الگوی کنترل‌گری و ترس از انفعال (Dominance-Driven)',
                    'desc'  => 'مراجع انگیزهٔ بالایی برای تغییر محیط دارد اما در تحمل موقعیت‌های خارج از کنترل خود دچار تنش و تحریک‌پذیری شدید می‌شود. ممکن است نیازهای عاطفی را به نفع پیشبرد کارها واپس‌رانی کند.',
                ];
                $resistances[] = [
                    'style' => 'چالش بر سر صلاحیت درمانگر یا کنترل روند جلسه',
                    'guide' => 'از موضع بالادستی صحبت نکنید؛ درمان را به عنوان یک همکاری مشترک و مراجع را تصمیم‌گیرنده نهایی روند درمان تلقی کنید.',
                ];
            }

            if ($s >= 70 && $d <= 35) {
                $hypotheses[] = [
                    'title' => 'الگوی واپس‌رانی خشم و سازگاری بیمارگون (Suppressed Assertion)',
                    'desc'  => 'مراجع به دلیل ترس عمیق از تعارض یا طرد، خشم و نظرات واقعی خود را پنهان می‌کند. این موضوع معمولاً به سردردهای تنشی، خستگی مزمن یا رفتارهای نافرمانی انفعالی منجر می‌شود.',
                ];
                $resistances[] = [
                    'style' => 'موافقت کاذب و لبخندهای دفاعی برای راضی نگه‌داشتن درمانگر',
                    'guide' => 'فضای امن ایجاد کنید و به مراجع بگویید حق دارد با نظرات شما مخالفت کند؛ خشم او را پاداش دهید و تخلیهٔ امن را تمرین کنید.',
                ];
            }

            if ($c >= 70) {
                $hypotheses[] = [
                    'title' => 'کمال‌گرایی انطباقی/ناسازگار و مکانیسم عقلانی‌سازی (Intellectualization)',
                    'desc'  => 'مراجع برای گریز از تجربهٔ هیجانات ناخوشایند، وقایع زندگی را تماماً با منطق و استدلال ریاضی فرمول‌بندی می‌کند.',
                ];
                $resistances[] = [
                    'style' => 'بحث‌های فلسفی طولانی و درخواست شواهد برای فرار از لمس هیجان',
                    'guide' => 'از تحلیل ذهنی به سمت حس بدنی هدایتش کنید: «وقتی این ماجرا را تعریف می‌کنی، در قفسه سینه یا گلویت چه حسی داری؟».',
                ];
            }

            if ($i >= 70 && $c <= 40) {
                $hypotheses[] = [
                    'title' => 'نیاز مبرم به تایید اجتماعی و جذابیت بین‌فردی',
                    'desc'  => 'مراجع بخش عمده عزت‌نفس خود را از بازخورد دیگران دریافت می‌کند و تنهایی یا طرد اجتماعی برایش دردناک است.',
                ];
                $resistances[] = [
                    'style' => 'تبدیل جلسه به گپ و گفت خودمانی و شیرین‌زبانی برای خشنودسازی مشاور',
                    'guide' => 'چارچوب حرفه‌ای جلسه را محکم نگه دارید و به آرامی مراجع را به موضوعات اصلی برگردانید.',
                ];
            }

            $interventions = [
                'جلسه ۱: بازخورددهی نتایج این آزمون به عنوان نقطه شروع خنثی و ایجاد اتحاد درمانی (Therapeutic Alliance)',
                'جلسه ۲: ترسیم چرخهٔ تعارض بین‌فردی مراجع بر اساس سبک رفتاری غالب او در روابط نزدیک',
                'جلسه ۳–۴: آموزش رفتارهای جایگزین و تکنیک‌های تنظیم هیجان متناسب با سبک شناختی',
            ];
        } else {
            // تحلیل عمومی
            $hypotheses[] = [
                'title' => 'ارزیابی یکپارچه ابعاد شخصیتی',
                'desc'  => 'نتایج آزمون نشان‌دهندهٔ ساختار کلی و ظرفیت‌های شناختی مراجع است که می‌تواند به عنوان زمینهٔ گفت‌وگوی جلسه اول استفاده شود.',
            ];
            $interventions = [
                'استفاده از نتایج برای هدف‌گذاری مشترک در جلسه اول ارزیابی',
                'ارزیابی باورهای بنیادین و سبک‌های مقابله‌ای مراجع',
            ];
        }

        $therapyEntryPoints = [
            'interventions'   => $interventions,
            'session_openers' => [
                '«در نتایج آزمون شما، تعادل میان مهارت‌ها و انرژی درونی برجسته است؛ در زندگی روزمره این موضوع چقدر به شما حس کنترل یا خستگی می‌دهد؟»',
                '«آیا موقعیت‌هایی بوده که احساس کنید برای پیشبرد امور ناچارید فشار روانی زیادی را به تنهایی تحمل کنید؟»',
            ],
        ];

        $defenseMechanisms = [
            'primary'   => 'عقلانی‌سازی و کنترل شناختی (Intellectualization / Rationalization)',
            'secondary' => 'واپس‌رانی خستگی روانی و اجتناب از ابراز آسیب‌پذیری',
        ];

        return [
            'attempt_id'           => $attemptId,
            'user'                 => $user,
            'test_title'           => $at['test_title'],
            'completed_at'         => $at['completed_at'] ?: $at['started_at'],
            'validity'             => $validity,
            'red_flags'            => $redFlags,
            'hypotheses'           => $hypotheses,
            'resistances'          => $resistances,
            'resistance_handling'  => $resistances,
            'interventions'        => $interventions,
            'therapy_entry_points' => $therapyEntryPoints,
            'defense_mechanisms'   => $defenseMechanisms,
            'results_table'        => array_values($map),
        ];
    }
}
