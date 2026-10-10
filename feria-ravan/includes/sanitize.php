<?php
/**
 * ============================================================================
 *  پاک‌سازی امن HTML خروجی ویرایشگر WYSIWYG + ساخت اسلاگ یکتا (نسخهٔ ۱٫۴)
 *  سیاست امنیتی:
 *   ─ لیست سفید تگ‌ها؛ هر تگ دیگر حذف می‌شود (script/style/iframe... با محتوا)
 *   ─ لیست سفید صفت‌ها per-tag؛ صفت‌های on* و style خطرناک حذف می‌شوند
 *   ─ href/src فقط به نشانی‌های امن (http/https/nسبی/tel/mailto) محدود می‌شوند
 *   ─ src تصویر فقط از مسیر آپلود خود سایت یا http(s) معتبر پذیرفته می‌شود
 * ============================================================================
 */
declare(strict_types=1);

/** لیست سفید تگ‌های مجاز در توضیحات رویدادها/خدمات */
function fr_allowed_tags(): array
{
    return ['p','br','strong','b','em','i','u','s','span','h2','h3','h4',
            'ul','ol','li','a','blockquote','img','figure','figcaption','hr',
            'table','thead','tbody','tr','th','td'];
}

/** بررسی امن‌بودن یک URL (برای href/src) */
function fr_safe_url(string $url, bool $isImg = false): ?string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) return null;
    // حذف کاراکترهای کنترلی/فضای خالی پنهان که سناریوهای bypass می‌سازند
    $url = preg_replace('/[\x00-\x20\x7F]+/', '', $url);
    $low = strtolower($url);
    if (str_starts_with($low, 'javascript:') || str_starts_with($low, 'vbscript:') ||
        str_starts_with($low, 'data:') || str_starts_with($low, 'file:')) return null;
    if ($isImg) {
        // تصویر: فقط آپلود داخلی در feria-uploads (یا assets/uploads قدیمی)
        if (str_starts_with($url, SITE_URL . '/feria-uploads/') || str_starts_with($url, SITE_URL . '/assets/uploads/')) return $url;
        if (str_starts_with($url, '/feria-uploads/') || str_starts_with($url, 'feria-uploads/') ||
            str_starts_with($url, '/assets/uploads/') || str_starts_with($url, 'assets/uploads/')) return $url;
        if (preg_match('#^https?://#i', $url)) return null; // تصویر خارجی غیرمجاز (حریم/سرعت)
        return null;
    }
    // لینک: http(s)، نسبی، لنگر(#)، تلفن و ایمیل مجاز
    if (preg_match('#^(https?://|/|\.{0,2}/|\#|tel:|mailto:)#i', $url)) return $url;
    return null;
}

/**
 * پاک‌سازی HTML — خروجی امن برای نمایش عمومی
 */
function fr_sanitize_html(string $html): string
{
    $html = str_replace("\0", '', $html);

    // ۱) حذف تگ‌های خطرناک به‌همراه کل محتوایشان
    $html = preg_replace(
        '#<(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base|svg|math|video|audio|source)\b[^>]*>.*?</\1\s*>#is',
        '', (string)$html);
    // ۲) تگ‌های خودبستهٔ خطرناک
    $html = preg_replace(
        '#<(script|style|iframe|object|embed|form|input|link|meta|base|svg|math)\b[^>]*/?\s*>#is',
        '', (string)$html);

    // ۳) نگه‌داشتن فقط تگ‌های لیست سفید
    $allow = '<' . implode('><', fr_allowed_tags()) . '>';
    $html  = strip_tags($html, $allow);

    // ۴) بازسازی امن صفت‌ها برای هر تگ باقی‌مانده
    $html = preg_replace_callback(
        '#<([a-zA-Z][a-zA-Z0-9]{0,10})((?:\s[^<>]*)*?)\s*(/?)>#s',
        function (array $m): string {
            $tag   = strtolower($m[1]);
            $attrs = (string)$m[2];
            $self  = $m[3] === '/' ? ' /' : '';

            // استخراج صفت‌ها به‌صورت name="value" | name='value' | name=value
            preg_match_all('#([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#s', $attrs, $am, PREG_SET_ORDER);

            // لیست سفید صفت به‌ازای تگ
            $perTag = [
                'a'    => ['href'],
                'img'  => ['src','alt','width','height','title'],
                'td'   => ['colspan','rowspan'],
                'th'   => ['colspan','rowspan'],
            ];
            $globalOk = ['span','p','h2','h3','h4','blockquote','li','figure','td','th','table','ul','ol']; // style محدود مجاز
            $keep = [];
            foreach ($am as $a) {
                $name  = strtolower($a[1]);
                // مقدار صفت: از گروه نقل‌قول‌دار یا بدون‌نقل‌قول (بدون هشدار PHP 8)
                $value = (string)(($a[3] ?? '') !== '' ? $a[3] : (($a[4] ?? '') !== '' ? $a[4] : ($a[5] ?? '')));
                if (str_starts_with($name, 'on')) continue;                    // رویدادها ممنوع
                if ($name === 'style') {
                    if (!in_array($tag, $globalOk, true)) continue;
                    $safe = fr_sanitize_style($value);
                    if ($safe !== '') $keep[] = 'style="' . e($safe) . '"';
                    continue;
                }
                $allowedFor = $perTag[$tag] ?? [];
                if (!in_array($name, $allowedFor, true)) continue;
                if ($name === 'href') { $v = fr_safe_url($value); if (!$v) continue; $keep[] = 'href="' . e($v) . '"'; continue; }
                if ($name === 'src')  { $v = fr_safe_url($value, true); if (!$v) continue; $keep[] = 'src="' . e($v) . '"'; continue; }
                if (in_array($name, ['colspan','rowspan'], true)) { $v = max(1, min(20, (int)$value)); $keep[] = $name . '="' . $v . '"'; continue; }
                if (in_array($name, ['width','height'], true))    { if (preg_match('/^\d{1,4}$/', $value)) $keep[] = $name . '="' . $value . '"'; continue; }
                // alt/title — متن ساده
                $keep[] = $name . '="' . e(mb_substr($value, 0, 300)) . '"';
            }
            // نکته: لینک‌ها در همان تب باز می‌شوند تا تجربهٔ RTL یکدست بماند
            return '<' . $tag . ($keep ? ' ' . implode(' ', $keep) : '') . $self . '>';
        },
        (string)$html);

    return trim((string)$html);
}

/**
 * پاک‌سازی style inline — فقط text-align و color امن نگه داشته می‌شوند
 */
function fr_sanitize_style(string $style): string
{
    $out = [];
    foreach (explode(';', $style) as $decl) {
        if (!str_contains($decl, ':')) continue;
        [$prop, $val] = array_map('trim', explode(':', $decl, 2));
        $prop = strtolower($prop);
        if ($prop === 'text-align' && in_array(strtolower($val), ['right','left','center','justify'], true)) {
            $out[] = 'text-align:' . strtolower($val);
        } elseif ($prop === 'color' || $prop === 'background-color') {
            // فقط مقدار رنگ ساده: #hex / rgb()/rgba() با اعداد — بدون url/expression
            if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\))$/', $val)) {
                $out[] = $prop . ':' . $val;
            }
        }
    }
    return implode(';', $out);
}

/* ---------------------------------------------------------------------------
 | اسلاگ — آدرس یکتای فارسی‌پسند برای صفحات عمومی
 * -------------------------------------------------------------------------*/
/** تبدیل عنوان به اسلاگ تمیز (حروف/اعداد فارسی و لاتین، جداکنندهٔ خط‌تیره) */
function fr_slugify(string $title, int $maxLen = 80): string
{
    $s = trim(en_num($title));
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);          // کنترل‌کاراکتر
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);          // هرچیز غیر حرف/عدد → خط‌تیره
    $s = trim((string)$s, '-');
    $s = preg_replace('/-{2,}/', '-', $s);
    if ($s === '' || $s === null) $s = 'item';
    return mb_substr($s, 0, $maxLen);
}

/** تولید اسلاگ یکتا در یک جدول (با افزودن پسوند عددی در صورت تکرار) */
function fr_unique_slug(string $table, string $slug, string $col, int $excludeId = 0): string
{
    $base = $slug; $n = 1;
    while (true) {
        $row = DB::one('SELECT id FROM ' . DB::t($table) . ' WHERE ' . $col . '=?', [$slug]);
        if (!$row || ($excludeId && (int)$row['id'] === $excludeId)) return $slug;
        $slug = mb_substr($base, 0, 76) . '-' . (++$n);
        if ($n > 50) return $base . '-' . fr_token(4);         // حاشیهٔ امن
    }
}
