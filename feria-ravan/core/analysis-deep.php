<?php
/**
 * ============================================================================
 *  موتور محتوای تحلیل عمیق نسخهٔ ویژه — نسخهٔ ۲٫۹ (دکتری روانشناسی بالینی)
 *  ─ پایگاه دانش تخصصی هر موتور در core/analysis-deep/<engine>.php
 *  ─ هر مقیاس: مونوگراف علمی + سازوکار + تظاهرات + مداخلات شواهدمحور + منابع
 *  ─ خروجی: بدنهٔ HTML کامل و حرفه‌ای برای قالب‌های تحلیل (level=full)
 *  ─ منابع مرجع (آکادمیک): Marston 1928؛ Young, Klosko & Weishaar 2003؛
 *    Costa & McCrae 1992؛ Goldberg IPIP؛ Spitzer GAD-7 2006؛ Kroenke PHQ-9 2001؛
 *    Spector JSS 1985؛ Gardner 1983؛ Schutte 1998؛ Deci & Ryan SDT؛ Barlow؛
 *    Beck CBT؛ Wells metacognition؛ Dimidjian Behavioral Activation؛ APA.
 * ============================================================================
 */
declare(strict_types=1);

$GLOBALS['FR_DEEP_CACHE'] = $GLOBALS['FR_DEEP_CACHE'] ?? [];

/** بارگذاری پایگاه دانش یک موتور (یک‌بار در هر درخواست) */
function fr_deep_kb(string $engine): array
{
    if (isset($GLOBALS['FR_DEEP_CACHE'][$engine])) return $GLOBALS['FR_DEEP_CACHE'][$engine];
    $f = __DIR__ . '/analysis-deep/' . $engine . '.php';
    $kb = is_file($f) ? (require $f) : [];
    return $GLOBALS['FR_DEEP_CACHE'][$engine] = (is_array($kb) ? $kb : []);
}

const FR_DEEP_BANDS = ['very_low', 'low', 'medium', 'high', 'very_high'];
const FR_DEEP_BAND_FA = [
    'very_low'  => 'خیلی پایین', 'low' => 'پایین', 'medium' => 'متعادل',
    'high'      => 'بالا', 'very_high' => 'خیلی بالا',
];

/** نگاشت اسلاگ آزمون → کلید پایگاه دانش */
function fr_deep_key_for_slug(string $slug): string
{
    return match (true) {
        str_starts_with($slug, 'disc')              => 'disc',
        str_starts_with($slug, 'ysq')               => 'ysq',
        str_starts_with($slug, 'neo')               => 'neo',
        str_starts_with($slug, 'anxiety')           => 'anx',
        str_starts_with($slug, 'depression')        => 'dep',
        str_starts_with($slug, 'jobsat')            => 'jobsat',
        str_starts_with($slug, 'gardner')           => 'gardner',
        str_starts_with($slug, 'eq-')               => 'eq',
        str_starts_with($slug, 'baron')             => 'baron',
        str_starts_with($slug, 'mbti')              => 'mbti',
        str_starts_with($slug, 'holland')           => 'holland',
        str_starts_with($slug, 'mmpi')              => 'mmpi',
        str_starts_with($slug, 'raven')             => 'raven',
        str_starts_with($slug, 'wais')              => 'wais',
        str_starts_with($slug, 'learning-motivation') => 'motiv',
        default                                     => '',
    };
}

/**
 * واکشی تحلیل کاملاً تخصصی، عمیق و غیرتکراری برای هر بُعد و هر مقیاس
 */
function fr_get_specialized_scale_analysis(string $engine, string $slug, string $code, string $band, string $ctx = 'general', bool $isFull = true, array $r = []): ?array
{
    $key = fr_deep_key_for_slug($slug);
    if ($key === '') {
        $key = fr_deep_key_for_slug($engine);
    }
    if ($key === '') {
        $key = $engine;
    }

    if ($key !== '') {
        $res = fr_deep_scale($key, $code, $band, $ctx, $isFull);
        if ($res && !empty($res['body'])) {
            return $res;
        }
    }

    // تولید تحلیل تخصصی پویا برای مقیاس‌های اختصاصی یا فاقد فایل ایستا
    $title = (string)($r['title_fa'] ?? $code);
    $percent = (float)($r['percent'] ?? 50);
    $sten = (float)($r['sten_score'] ?? 5);
    $percentile = (float)($r['percentile'] ?? 50);

    $bandFa = FR_DEEP_BAND_FA[$band] ?? $band;

    $analysisMap = [
        'very_low' => [
            'interp' => "نمرهٔ مراجع در سازهٔ «{$title}» در پایین‌ترین دهک جامعهٔ هنجار قرار دارد (درصد نسبی: {$percent}٪؛ رتبه درصدی: {$percentile}٪). این میزان نشان‌دهندهٔ عدم فعالیت یا مهار شدید این ویژگی در سبک شناختی و الگوهای رفتاری روزمره است. در بافت بالینی، نمرهٔ خیلی پایین می‌تواند نشان‌دهندهٔ واپس‌رانی دفاعی، فقدان انگیزش در این حوزه یا جبران افراطی از طریق سایر مؤلفه‌های شخصیتی باشد.",
            'manif'  => "اجتناب آشکار از موقعیت‌های درگیرکنندهٔ این سازه، احساس بیگانگی با الگوهای رفتاری مرتبط، و اتکای کامل بر سایر ابعاد شخصیتی برای حل تعارضات.",
            'interv' => "بررسی ریشه‌های شکل‌گیری این عدم تعادل در تاریخچهٔ فردی، تکنیک‌های فعال‌سازی گام‌به‌گام و تقویت ظرفیت‌های پذیرش روانی در این بُعد.",
        ],
        'low' => [
            'interp' => "نمرهٔ مراجع در سازهٔ «{$title}» پایین‌تر از میانگین جامعه نرمال ارزیابی شده است (نمره تراز: {$percent}٪؛ استن: {$sten} از ۱۰). این الگو بیانگر آن است که ویژگی مذکور در سلسله‌مراتب ترجیحات روانی فرد نقش فرعی دارد و معمولاً تنها در صورت ضرورت محیطی فراخوانده می‌شود.",
            'manif'  => "تمایل به روش‌های جایگزین، صرف انرژی روانی اندک در این زمینه و احساس خستگی زودهنگام هنگام الزام به حفظ این رفتار در بلندمدت.",
            'interv' => "آموزش مهارت‌های مکمل، تنظیم انتظارات متناسب با توان واقعی فرد و پیشگیری از فشار بیش‌ازحد محیطی در موقعیت‌های مرتبط.",
        ],
        'medium' => [
            'interp' => "نمرهٔ مراجع در مؤلفهٔ «{$title}» در محدودهٔ بهنجار و متعادل جامعه قرار دارد (استن {$sten} از ۱۰؛ رتبه درصدی: {$percentile}٪). این سطح نشان‌دهندهٔ تعادل انطباقی، انعطاف‌پذیری کارآمد و توانایی فراخوانی این ویژگی متناسب با مقتضیات موقعیتی است بدون آن‌که به منبعی برای تعارض یا فرسودگی تبدیل شود.",
            'manif'  => "تعدیل رفتاری هوشمندانه؛ بروز این ویژگی در زمان نیاز و عقب‌نشینی انعطاف‌پذیر هنگام ضرورتِ به‌کارگیری رفتارهای مکمل.",
            'interv' => "تثبیت الگوهای انطباقی فعلی، پایش حفظ تعادل در زمان استرس‌های مضاعف و به‌کارگیری این بُعد به عنوان لنگرگاه ثبات روانی.",
        ],
        'high' => [
            'interp' => "نمرهٔ مراجع در سازهٔ «{$title}» بالاتر از سطح میانگین و در دامنهٔ برجستهٔ نیمرخ ارزیابی می‌شود (نمره: {$percent}٪؛ رتبه درصدی جامعه: {$percentile}٪). این ویژگی یکی از پیشران‌های اصلی انگیزش، تصمیم‌گیری و سبک ارتباطی مراجع را تشکیل می‌دهد و تأثیر محسوسی بر رفتار مشهود او دارد.",
            'manif'  => "پیش‌دستی در ابراز این مؤلفه، ترجیح محیط‌ها و وظایفی که میدان بروز این سازه را فراهم می‌کنند، و رضایت خاطر عمیق از تجربهٔ رفتارهای هم‌راستا.",
            'interv' => "هدایت انرژی حاصل از این قوت روان‌شناختی به سمت اهداف راهبردی، مهار افراط‌های ناخواسته و تقویت هوشیاری نسبت به پیامدهای بین‌فردی آن.",
        ],
        'very_high' => [
            'interp' => "نمرهٔ مراجع در مؤلفهٔ «{$title}» در بالاترین چارک نیمرخ هنجار بالینی قرار گرفته است (نمره: {$percent}٪؛ استن {$sten} از ۱۰). این بُعد یک تمایل مسلط و محوری (Cardinal Disposition) در سازمان روانی فرد است که لنز ادراکی او به جهان پیرامون را رنگ‌آمیزی می‌کند. در کنار نقاط قوت درخشان، نیازمند مراقبت دقیق در برابر سایه‌های روان‌شناختی و آسیب‌پذیری‌های ناشی از یکه‌تازی این بُعد است.",
            'manif'  => "تسلط غالب بر تعاملات، ناتوانی در مهار این گرایش حتی در شرایطی که کارایی ندارد، و احتمال بروز اصطکاک با افرادی که سبک متفاوتی دارند.",
            'interv' => "تکنیک‌های تنظیم هیجان و ذهن‌آگاهی، بازداری عمدی پاسخ‌های خودکار، تمرین فضای تنفس پیش از عمل و تقویت عامدانهٔ ابعاد مکمل شخصیت.",
        ],
    ];

    $item = $analysisMap[$band] ?? $analysisMap['medium'];

    $body  = '<p class="deep-band" style="font-size:14px;line-height:2.1;color:#1e293b;background:#f8fafc;padding:12px 14px;border-radius:8px;border-right:3px solid #7c3aed"><b>تفسیر بالینی تخصصی:</b> ' . $item['interp'] . '</p>';
    $body .= '<div class="deep-manif" style="margin-top:10px;line-height:2"><b>🔍 تظاهرات رفتاری در بافت فردی و بین‌فردی:</b> ' . $item['manif'] . '</div>';

    if ($isFull) {
        $body .= '<p style="margin-top:10px;line-height:2"><b>⚙️ تحلیل روان‌سنجی سازه:</b> این سازه از طریق شاخص‌های ترکیبی و مقایسه با جامعهٔ مرجع استانداردسازی شده و در پیش‌بینی سازگاری هیجانی و عملکرد پایدار مراجع واجد روایی سازه‌ای بالاست.</p>';
        $isAdmin = (bool)(function_exists('current_admin') && current_admin())
                || (class_exists('ReportBuilder', false) && method_exists('ReportBuilder', 'isAdmin') && ReportBuilder::isAdmin());
        if ($isAdmin && !empty($item['interv'])) {
            $body .= '<div class="deep-interv" style="margin-top:12px;padding:12px 14px;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0;line-height:2.1;color:#166534">'
                  . '<div style="display:inline-block;padding:2px 8px;border-radius:6px;background:#166534;color:#fff;font-size:11px;font-weight:bold;margin-bottom:6px">🔒 راهنمای بالینی اختصاصی مشاور و مدیر سامانه</div><br>'
                  . '<b>🎯 راهنمای مداخله و پیشنهاد بالینی:</b> ' . $item['interv'] . '</div>';
        }
    }

    return [
        'title' => 'تحلیل تخصصی «' . $title . '» — وضعیت: ' . $bandFa,
        'body'  => $body,
    ];
}

/**
 * بدنهٔ عمیق یک مقیاس: تعریف + تظاهر + سازوکار + بافت + مداخله
 */
function fr_deep_scale(string $key, string $code, string $band, string $ctx = 'general', bool $isFull = true): ?array
{
    $kb = fr_deep_kb($key);
    $sc = $kb['scales'][$code] ?? null;
    if (!$sc) return null;

    $collapse = ['very_low' => 'low', 'very_high' => 'high'];
    $bandTxt = $sc['bands'][$band] ?? ($sc['bands'][$collapse[$band] ?? ''] ?? ($sc['bands']['medium'] ?? ''));
    if ($bandTxt === '') return null;

    $fa    = (string)($sc['fa'] ?? $code);
    $bandFa= FR_DEEP_BAND_FA[$band] ?? $band;
    $mgr   = $ctx === 'manager' ? (string)($sc['mgr'] ?? '') : '';
    $fam   = $ctx === 'family'  ? (string)($sc['fam'] ?? '') : '';

    $body  = '<p class="deep-band" style="font-size:14px;line-height:2.1;color:#1e293b;background:#f8fafc;padding:12px 14px;border-radius:8px;border-right:3px solid #7c3aed"><b>تفسیر بالینی وضعیت ' . e($bandFa) . ':</b> ' . $bandTxt . '</p>';
    $body .= '<p style="margin-top:10px;line-height:2"><b>🧠 شناخت علمی سازه:</b> ' . (string)$sc['def'] . '</p>';
    if (!empty($sc['manif'])) $body .= '<div class="deep-manif" style="margin-top:10px;line-height:2"><b>🔍 تظاهرات رفتاری و نشانگان عینی:</b>' . (string)$sc['manif'] . '</div>';

    if ($isFull) {
        $isAdmin = (bool)(function_exists('current_admin') && current_admin())
                || (class_exists('ReportBuilder', false) && method_exists('ReportBuilder', 'isAdmin') && ReportBuilder::isAdmin());
        if (!empty($sc['mech'])) $body .= '<p style="margin-top:10px;line-height:2"><b>⚙️ سازوکار روان‌شناختی و عصب‌شناختی:</b> ' . (string)$sc['mech'] . '</p>';
        if ($mgr !== '') $body .= '<p class="deep-ctx" style="margin-top:10px;line-height:2"><b>🔹 در نقش مدیریت و تصمیم‌گیری حرفه‌ای:</b> ' . $mgr . '</p>';
        if ($fam !== '') $body .= '<p class="deep-ctx" style="margin-top:10px;line-height:2"><b>🔹 در روابط عاطفی و خانواده:</b> ' . $fam . '</p>';
        if (!empty($sc['interv']) && $isAdmin) {
            $body .= '<div class="deep-interv" style="margin-top:12px;padding:12px 14px;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0;line-height:2.1;color:#166534">'
                  . '<div style="display:inline-block;padding:2px 8px;border-radius:6px;background:#166534;color:#fff;font-size:11px;font-weight:bold;margin-bottom:6px">🔒 راهنمای بالینی اختصاصی مشاور و مدیر سامانه</div><br>'
                  . '<b>🎯 راهنمای مداخلهٔ بالینی و پروتکل شواهدمحور:</b>' . (string)$sc['interv'] . '</div>';
        }
        if (!empty($sc['ref'])) $body .= '<p class="deep-ref" style="margin-top:10px;font-size:11.5px;color:#64748b">📚 <i>مرجع استاندارد: ' . (string)$sc['ref'] . '</i></p>';
    }

    return [
        'title' => 'تحلیل تخصصی «' . $fa . '» — وضعیت: ' . $bandFa,
        'body'  => $body,
    ];
}

/**
 * بدنهٔ عمیق «نمرهٔ کلی» بانددار (مقیاس overall نتایج)
 */
function fr_deep_overall(string $key, string $band, string $ctx = 'general'): ?array
{
    $kb = fr_deep_kb($key);
    $txt = $kb['overall_bands'][$band] ?? '';
    if ($txt === '') {
        /* fallback هوشمند: نزدیک‌ترین باندِ تعریف‌شده */
        $order = FR_DEEP_BANDS;
        $i = array_search($band, $order, true);
        if ($i !== false) {
            foreach ([$i-1, $i+1, $i-2, $i+2] as $j) {
                if (isset($order[$j]) && ($kb['overall_bands'][$order[$j]] ?? '') !== '') { $txt = $kb['overall_bands'][$order[$j]]; break; }
            }
        }
    }
    if ($txt === '') return null;
    $extra = $ctx === 'manager' ? (string)($kb['overall_mgr'] ?? '') : '';
    return [
        'title' => 'تحلیل جامع نمرهٔ کلی — سطح ' . (FR_DEEP_BAND_FA[$band] ?? $band),
        'body'  => '<p>' . $txt . '</p>' . ($extra !== '' ? '<p class="deep-ctx">' . $extra . '</p>' : ''),
    ];
}

/**
 * فصل پایانی جامع نسخهٔ ویژه (قالب بدون مقیاس/بدون باند — *|overall)
 */
function fr_deep_finale(string $key, string $ctx = 'general'): ?array
{
    $kb = fr_deep_kb($key);
    $fin = (string)($kb['finale'] ?? '');
    if ($fin === '') return null;
    $extra = $ctx === 'manager' ? (string)($kb['finale_mgr'] ?? '') : '';
    return [
        'title' => (string)($kb['finale_title'] ?? 'جمع‌بندی تخصصی و نقشهٔ رشد'),
        'body'  => $fin . $extra,
    ];
}


