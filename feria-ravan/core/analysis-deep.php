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
        str_starts_with($slug, 'learning-motivation') => 'motiv',
        default                                     => '',
    };
}

/**
 * بدنهٔ عمیق تحلیل یک مقیاس در یک باند مشخص.
 * @param string $key کلید پایگاه دانش (fr_deep_key_for_slug)
 * @return array|null ['title'=>…,'body'=>…]
 */
function fr_deep_scale(string $key, string $code, string $band, string $ctx = 'general'): ?array
{
    $kb = fr_deep_kb($key);
    $sc = $kb['scales'][$code] ?? null;
    if (!$sc) return null;
    $bandTxt = $sc['bands'][$band] ?? '';
    if ($bandTxt === '') return null;

    $fa    = (string)($sc['fa'] ?? $code);
    $bandFa= FR_DEEP_BAND_FA[$band] ?? $band;
    $mgr   = $ctx === 'manager' ? (string)($sc['mgr'] ?? '') : '';
    $fam   = $ctx === 'family'  ? (string)($sc['fam'] ?? '') : '';

    $body  = '<p class="deep-band">' . $bandTxt . '</p>';
    $body .= '<p><b>شناخت علمی سازه:</b> ' . (string)$sc['def'] . '</p>';
    if (!empty($sc['mech'])) $body .= '<p><b>سازوکار روان‌شناختی:</b> ' . (string)$sc['mech'] . '</p>';
    if (!empty($sc['manif'])) $body .= '<div class="deep-manif"><b>تظاهرات بالینی و روزمره:</b>' . (string)$sc['manif'] . '</div>';
    if ($mgr !== '') $body .= '<p class="deep-ctx"><b>🔹 در نقش مدیریت و رهبری:</b> ' . $mgr . '</p>';
    if ($fam !== '') $body .= '<p class="deep-ctx"><b>🔹 در زندگی خانوادگی و زناشویی:</b> ' . $fam . '</p>';
    if (!empty($sc['interv'])) $body .= '<div class="deep-interv"><b>راهنمای مداخلهٔ شواهدمحور:</b>' . (string)$sc['interv'] . '</div>';
    $body .= '<p class="deep-ref">📚 <i>' . (string)($sc['ref'] ?? '') . '</i></p>';

    return [
        'title' => 'تحلیل عمیق «' . $fa . '» — سطح ' . $bandFa,
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


