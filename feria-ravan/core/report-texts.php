<?php
/**
 * ============================================================================
 *  آداپتر بانک متون تحلیل برای گزارش‌ساز — نسخهٔ ۱٫۶
 *  ─ نگاشت یکنواخت: (موتور، مقیاس، باند، اسلاگ تست روانشناسی) → متن تحلیل فارسی
 *  ─ داده‌ها از core/analysis-texts.php می‌آیند (مستخرج از سورس مادر کاربر)
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/analysis-texts.php';


/** تبدیل متن خام بانک به HTML امنِ آمادهٔ چاپ (متون دارای تگ، همان‌طور چاپ می‌شوند) */
function fr_as_html($t): ?string
{
    if (empty($t) || !is_string($t)) return null;
    return str_contains($t, '<') ? $t : '<p>' . e($t) . '</p>';
}

/* باندهای ما → کلیدهای باندهای بالینی مخزن (BAI/BDI-II) */
function fr_clinical_band_key(string $band): string
{
    return ['very_low'=>'minimal','low'=>'mild','medium'=>'moderate','high'=>'moderately_severe','very_high'=>'severe'][$band] ?? 'mild';
}

/** کلید بالینی از روی اسلاگ تست روانشناسی (anxiety/depression) */
function fr_clinical_slug(string $slug): string
{
    return str_contains($slug, 'depression') ? 'depression' : 'anxiety';
}

/**
 * متن «خلاصه» (نسخهٔ رایگان) برای یک مقیاس
 */
function fr_text_brief(string $engine, string $slug, string $scaleCode, string $band): ?string
{
    // ۱. ارجحیت تحلیل تخصصی روان‌سنجی با تفکیک دقیق باندها
    if (function_exists('fr_get_specialized_scale_analysis')) {
        $spec = fr_get_specialized_scale_analysis($engine, $slug, $scaleCode, $band, 'general', false);
        if ($spec && !empty($spec['body'])) {
            return $spec['body'];
        }
    }

    switch ($engine) {
        case 'disc':  return fr_as_html(fr_disc_brief($scaleCode));
        case 'neo':   { $b = fr_txt('FERIA_NEO_BRIEF'); return fr_as_html($b[$scaleCode] ?? null); }
        case 'ysq':   { $b = fr_txt('FERIA_YSQ_BRIEF'); return fr_as_html($b[$scaleCode] ?? null); }
        case 'screen':{
            $b = fr_txt('FERIA_CLINICAL_BRIEF');
            $k = fr_clinical_slug($slug);
            return fr_as_html($b[$k][fr_clinical_band_key($band)] ?? null);
        }
        case 'jobsat':{
            $b = fr_txt('FERIA_JOBSAT_BRIEF');
            $lvl = in_array($band, ['high','very_high'], true) ? 'high' : ($band === 'medium' ? 'mid' : 'low');
            return fr_as_html($b[$lvl] ?? null);
        }
    }
    return null;
}

/**
 * متن «عمیق و تخصصی» (نسخهٔ ویژه) برای یک مقیاس
 */
function fr_text_full(string $engine, string $slug, string $scaleCode, string $band, string $ctx = 'family'): ?string
{
    // ۱. ارجحیت تحلیل مونوگراف عمیق و تخصصی روان‌سنجی (با تفکیک دقیق باندها و ابعاد بالینی)
    if (function_exists('fr_get_specialized_scale_analysis')) {
        $spec = fr_get_specialized_scale_analysis($engine, $slug, $scaleCode, $band, $ctx, true);
        if ($spec && !empty($spec['body'])) {
            return $spec['body'];
        }
    }

    switch ($engine) {
        case 'disc':  return fr_as_html(fr_disc_full($scaleCode, $ctx === 'manager' ? 'manager' : 'family'));
        case 'neo':   { $f = fr_txt('FERIA_NEO_FULL'); return fr_as_html($f[$scaleCode] ?? null); }
        case 'ysq':   { $f = fr_txt('FERIA_YSQ_FULL'); return fr_as_html($f[$scaleCode] ?? null); }
        case 'screen':{ $f = fr_txt('FERIA_CLINICAL_FULL'); return fr_as_html($f[fr_clinical_slug($slug)] ?? null); }
        case 'jobsat':{
            // تحلیل عمیق رضایت شغلی: بریف سطح + تفسیر وجوه ضعیف (FACETS)
            $br = fr_text_brief('jobsat', $slug, $scaleCode, $band);
            $fx = fr_txt('FERIA_JOBSAT_FACETS') ?: [];
            $parts = $br ? [e($br)] : [];
            foreach (($fx[$scaleCode][fr_clinical_band_key($band)] ?? $fx[$scaleCode] ?? []) as $k => $v) {
                if (is_string($v)) $parts[] = e($v);
            }
            return $parts ? implode("<br><br>", $parts) : null;
        }
    }
    return null;
}

/** مسیر رشد فازبندی (نسخهٔ ویژه) */
function fr_text_growth(string $engine, string $slug, string $scaleCode): array
{
    switch ($engine) {
        case 'neo':   { $g = fr_txt('FERIA_NEO_GROWTH'); return (array)($g[$scaleCode] ?? []); }
        case 'ysq':   { $g = fr_txt('FERIA_YSQ_GROWTH'); return (array)($g[$scaleCode] ?? []); }
        case 'screen':{ $g = fr_txt('FERIA_CLINICAL_GROWTH'); return (array)($g[fr_clinical_slug($slug)] ?? []); }
        case 'jobsat':{ $g = fr_txt('FERIA_JOBSAT_GROWTH'); return (array)($g[$scaleCode] ?? []); }
    }
    return [];
}

/** دامنه‌های مادر طرحواره‌ها + مشاورهٔ کلی یانگ */
function fr_ysq_domains(): array  { return (array)(fr_txt('FERIA_YSQ_DOMAINS') ?: []); }
function fr_ysq_advice(): ?string
{
    $a = fr_txt('FERIA_YSQ_GENERAL_ADVICE');
    return is_string($a) ? $a : (is_array($a) ? implode("<br>", array_map('strval', $a)) : null);
}
