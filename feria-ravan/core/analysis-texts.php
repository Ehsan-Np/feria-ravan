<?php
/**
 * ============================================================================
 *  دسترسی به بانک متون تحلیل (نسخهٔ ۱٫۶) — لودر تنبل + آداپترهای گزارش
 *  ─ fr_txt('FERIA_DISC_FULL') → آرایهٔ متن‌ها
 *  ─ متن‌ها در analysis-texts-data.php تعریف می‌شوند (متغیرهای FERIA_*)
 * ============================================================================
 */
declare(strict_types=1);

/** خواندن یک کلید از بانک متون تحلیل (با کش داخلی) */
function fr_txt(string $key)
{
    static $bag = null;
    if ($bag === null) {
        // توابع محاسباتی دیسک در صورت وجود قبلی دوباره تعریف نشوند
        if (!function_exists('feria_disc_detect_pattern')) {
            include __DIR__ . '/analysis-texts-data.php';
            $bag = get_defined_vars();
            // توابع داخل فایل داده (مثل feria_disc_tendencies) با global به آرایه‌های
            // FERIA_* دسترسی دارند؛ چون include این‌جا داخل scope تابع است، آن‌ها را
            // صراحتاً به سراسری منتقل می‌کنیم — وگرنه مقدارشان null می‌ماند.
            foreach ($bag as $k => $v) {
                if (str_starts_with((string)$k, 'FERIA_') && !array_key_exists($k, $GLOBALS)) {
                    $GLOBALS[$k] = $v;
                }
            }
        } else {
            $bag = $GLOBALS;
        }
    }
    return $bag[$key] ?? ($GLOBALS[$key] ?? null);
}

/* ---------------------------------------------------------------------------
 | میان‌برهای پرکاربرد برای گزارش‌ساز
 * -------------------------------------------------------------------------*/

/** متن مختصر یک بُعد DISC (برای تحلیل رایگان) */
function fr_disc_brief(string $dim): string
{
    $b = fr_txt('FERIA_DISC_BRIEF');
    return (string)($b[$dim] ?? '');
}

/** تحلیل عمیق بُعد DISC در بستر خانواده/مدیریت */
function fr_disc_full(string $dim, string $ctx): string
{
    $f = fr_txt('FERIA_DISC_FULL');
    $k = $ctx === 'manager' ? 'mgr' : 'fam';            // mgr=مدیریت | fam=خانواده/شخصی
    return (string)($f[$dim][$k] ?? $f[$dim]['fam'] ?? '');
}

/** متن ترکیب دوبعدی DISC (الگوی ترکیبی) */
function fr_disc_pair_text(string $pair): string
{
    $pairs = fr_txt('FERIA_DISC_PAIRS');
    if (isset($pairs[$pair])) return (string)$pairs[$pair];
    $rev = strrev($pair);
    return (string)($pairs[$rev] ?? '');
}

/** توضیح سطح یک بُعد DISC */
function fr_disc_level_hint(string $level): string
{
    $h = fr_txt('FERIA_DISC_LEVEL_HINT');
    return (string)($h[$level] ?? '');
}

/**
 * دادهٔ کامل الگوی دیسک از کتابخانهٔ PATTERNS سورس مادر
 * خروجی: ردیف آرایه با کلیدهای name/tagline/summary/motivation/work_style/
 * attitude/decision/strengths/risks/improve/success_keys/skills/family/management/jobs
 * اگر کلید دوحرفی نبود، به تک‌حرف غالب سقوط می‌کند؛ الگوی متعادل 'BA' هم پوشش دارد.
 */
function fr_disc_pattern_row(string $patternKey, bool $preferFirst = true): array
{
    $p = fr_txt('FERIA_DISC_PATTERNS') ?: [];
    if (isset($p[$patternKey]) && is_array($p[$patternKey])) return $p[$patternKey];
    // الگوی دوحرفی که در کتابخانه به ترتیب دیگری تعریف شده (مثلاً ID در برابر DI)
    $rev = strrev($patternKey);
    if (isset($p[$rev]) && is_array($p[$rev]) && !$preferFirst) return $p[$rev];
    if ($patternKey !== 'BA' && strlen($patternKey) > 1) {
        $first = substr($patternKey, 0, 1);
        if (isset($p[$first])) return $p[$first];
    }
    return ['name' => $patternKey, 'tagline' => '', 'summary' => ''];
}

/** متن «اوقات فراغت» بر اساس بُعد غالب DISC */
function fr_disc_leisure(string $dim): string
{
    $l = fr_txt('FERIA_DISC_LEISURE');
    return (string)($l[$dim] ?? '');
}

/** متن‌های آموزشی مدل DISC (فصل معرفی مدل در انتهای گزارش ویژه) */
function fr_disc_model_texts(): array
{
    return (array)(fr_txt('FERIA_DISC_MODEL_TEXT') ?: []);
}
