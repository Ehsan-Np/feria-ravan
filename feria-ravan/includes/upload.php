<?php
/**
 * ============================================================================
 *  آپلود امن تصویر — آواتار (کاربران/مدیران) + کاور رویدادها و خدمات مشاوره
 *  سیاست امنیتی:
 *   ─ فقط تصویر واقعی (سه‌گانه: پسوند + MIME واقعی + getimagesize)
 *   ─ حداکثر ۲ مگابایت، نام تصادفی غیرقابل‌حدس، بدون اجرای اسکریپت (.htaccess)
 *   ─ هر آپلود در زیرپوشهٔ مجاز خودش؛ حذف فقط در داخل assets/uploads/
 * ============================================================================
 */
declare(strict_types=1);

/**
 * ذخیرهٔ عمومی تصویر آپلودی در یکی از زیرپوشه‌های مجاز assets/uploads
 * @param array  $file    عنصر $_FILES[...]
 * @param string $subdir  زیرپوشهٔ مجاز: avatars | events | services
 * @param string $prefix  پیشوند نام فایل (user/admin/event/service)
 * @param int    $id      شناسهٔ مالک (برای خوانایی نام فایل)
 * @return array ['ok'=>bool,'path'=>?string,'error'=>?string] — path نسبی به ریشهٔ سایت
 */
function fr_save_image(array $file, string $subdir, string $prefix, int $id = 0): array
{
    // زیرپوشهٔ سفید — جلوگیری از path traversal
    // ۱٫۷: rte (تصاویر داخل ویرایشگرها) و tickets (پیوست تیکت) هم افزوده شد
    if (!in_array($subdir, ['avatars','events','services','rte','tickets'], true))
        return ['ok'=>false,'path'=>null,'error'=>'مسیر آپلود نامعتبر است.'];
    $prefix = preg_replace('/[^a-z]/', '', strtolower($prefix)) ?: 'img';

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
        return ['ok'=>false,'path'=>null,'error'=>'فایلی انتخاب نشده است.'];
    if ((int)($file['error'] ?? 1) !== UPLOAD_ERR_OK)
        return ['ok'=>false,'path'=>null,'error'=>'خطا در آپلود فایل (کد ' . (int)$file['error'] . ').'];
    if (($file['size'] ?? 0) > 2_000_000)
        return ['ok'=>false,'path'=>null,'error'=>'حجم تصویر باید حداکثر ۲ مگابایت باشد.'];

    // ۱) کنترل MIME واقعی محتوا
    $mime = mime_content_type((string)$file['tmp_name']);
    $map  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($map[$mime]))
        return ['ok'=>false,'path'=>null,'error'=>'فقط تصویر JPG یا PNG یا WEBP مجاز است.'];

    // ۲) تأیید ساختار تصویر (جلوگیری از فایل‌های جعلی/بدافزار)
    if (!@getimagesize((string)$file['tmp_name']))
        return ['ok'=>false,'path'=>null,'error'=>'فایل انتخابی یک تصویر معتبر نیست.'];

    // ۳) مقصد امن
    $dir = FR_ROOT . '/assets/uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true))
        return ['ok'=>false,'path'=>null,'error'=>'پوشهٔ آپلود قابل ایجاد نیست.'];

    $fn  = $prefix . '-' . max(1, $id) . '-' . bin2hex(random_bytes(6)) . '.' . $map[$mime];
    $dst = $dir . '/' . $fn;
    if (!move_uploaded_file((string)$file['tmp_name'], $dst))
        return ['ok'=>false,'path'=>null,'error'=>'ذخیرهٔ فایل ناموفق بود (دسترسی پوشه را بررسی کنید).'];
    @chmod($dst, 0644);

    return ['ok'=>true,'path'=>'assets/uploads/' . $subdir . '/' . $fn,'error'=>null];
}

/* ===========================================================================
 |  موتور بازتولید کاور (نسخهٔ ۱٫۶) — با افزونهٔ GD
 |  هر تصویری که برای رویداد/خدمت آپلود شود به‌صورت خودکار:
 |   ─ به نسبت ۱۶:۹ «برشِ پوششی» از مرکز می‌خورد و به ۱۲۰۰×۶۷۵ می‌رسد
 |   ─ یک بندانگشتی ۴۸۰×۲۷۰ هم ساخته می‌شود
 |   ─ داده‌های EXIF به‌خاطر بازنویسی کامل حذف و فایل بهینه می‌شود
 |  اگر GD روی هاست فعال نباشد، تصویر اصلی همان‌طور که هست ذخیره می‌شود.
 * =========================================================================*/

/** آیا افزونهٔ GD در دسترس است؟ */
function fr_gd_available(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatetruecolor');
}

/** بارگذاری امن تصویر از روی دیسک به حافظهٔ GD (بر اساس MIME واقعی) */
function fr_gd_load(string $file): ?GdImage
{
    $mime = @mime_content_type($file);
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file) ?: null,
        'image/png'  => @imagecreatefrompng($file)  ?: null,
        'image/webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($file) ?: null) : null,
        default      => null,
    };
}

/**
 * برش پوششی + تغییر اندازه به W×H و ذخیره به‌صورت JPEG بهینه (بدون EXIF)
 * «پوششی» یعنی نسبت ابعاد حفظ می‌شود و تصویر طوری برش می‌خورد که کل قاب را بپوشاند.
 */
function fr_gd_cover(string $src, string $dst, int $W, int $H): bool
{
    $img = fr_gd_load($src);
    if (!$img) return false;
    $sw = imagesx($img); $sh = imagesy($img);
    if ($sw < 10 || $sh < 10) { imagedestroy($img); return false; }

    // نسبت برش از مرکز
    $tRatio = $W / $H; $sRatio = $sw / $sh;
    if ($sRatio > $tRatio) {          // مبدا پهن‌تر است → از ارتفاع کامل، از پهنا کم کن
        $cw = (int)round($sh * $tRatio); $ch = $sh;
        $cx = (int)round(($sw - $cw) / 2); $cy = 0;
    } else {                          // مبدا بلندتر است → از پهنای کامل، از ارتفاع کم کن
        $cw = $sw; $ch = (int)round($sw / $tRatio);
        $cx = 0; $cy = (int)round(($sh - $ch) / 2);
    }

    $out = imagecreatetruecolor($W, $H);
    if (!$out) { imagedestroy($img); return false; }
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);    // زمینهٔ سفید برای PNGهای شفاف
    if (!imagecopyresampled($out, $img, 0, 0, $cx, $cy, $W, $H, $cw, $ch)) {
        imagedestroy($img); imagedestroy($out); return false;
    }
    $ok = @imagejpeg($out, $dst, 86); // کیفیت ۸۶: تعادل خوب وضوح و حجم
    imagedestroy($img); imagedestroy($out);
    if ($ok) @chmod($dst, 0644);
    return (bool)$ok;
}

/**
 * ذخیرهٔ کاور رویداد/خدمت با بازتولید خودکار اندازه استاندارد.
 * خروجی هم‌ساختار fr_save_image + کلید thumb (مسیر بندانگشتی یا null)
 */
function fr_save_cover(array $file, string $subdir, string $prefix, int $id = 0): array
{
    if (!in_array($subdir, ['events','services'], true))
        return ['ok'=>false,'path'=>null,'thumb'=>null,'error'=>'مسیر آپلود نامعتبر است.'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
        return ['ok'=>false,'path'=>null,'thumb'=>null,'error'=>'فایلی انتخاب نشده است.'];
    if ((int)($file['error'] ?? 1) !== UPLOAD_ERR_OK)
        return ['ok'=>false,'path'=>null,'thumb'=>null,'error'=>'خطا در آپلود فایل (کد ' . (int)$file['error'] . ').'];
    // چون خروجی نهایی فشرده می‌شود، اصل فایل تا ۵ مگابایت پذیرفته است
    if (($file['size'] ?? 0) > 5_000_000)
        return ['ok'=>false,'path'=>null,'thumb'=>null,'error'=>'حجم تصویر باید حداکثر ۵ مگابایت باشد.'];

    $mime = mime_content_type((string)$file['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true))
        return ['ok'=>false,'path'=>null,'thumb'=>null,'error'=>'فقط تصویر JPG یا PNG یا WEBP مجاز است.'];
    if (!@getimagesize((string)$file['tmp_name']))
        return ['ok'=>false,'path'=>null,'thumb'=>null,'error'=>'فایل انتخابی یک تصویر معتبر نیست.'];

    $dir = FR_ROOT . '/assets/uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true))
        return ['ok'=>false,'path'=>null,'thumb'=>null,'error'=>'پوشهٔ آپلود قابل ایجاد نیست.'];

    $prefix  = preg_replace('/[^a-z]/', '', strtolower($prefix)) ?: 'cover';
    $base    = $prefix . '-' . max(1, $id) . '-' . bin2hex(random_bytes(6));

    // بدون GD → سیاست قبلی: ذخیرهٔ خامِ تصویر اصلی
    if (!fr_gd_available()) {
        $fallback = fr_save_image($file, $subdir, $prefix, $id);
        return $fallback + ['thumb' => null];
    }

    $fn  = $base . '.jpg';
    $dst = $dir . '/' . $fn;
    if (!fr_gd_cover((string)$file['tmp_name'], $dst, 1200, 675))
        return ['ok'=>false,'path'=>null,'thumb'=>null,'error'=>'پردازش تصویر ناموفق بود؛ تصویر دیگری انتخاب کنید.'];

    $thumbRel = null;
    $tFn = $base . '-t.jpg';
    if (fr_gd_cover((string)$file['tmp_name'], $dir . '/' . $tFn, 480, 270)) {
        $thumbRel = 'assets/uploads/' . $subdir . '/' . $tFn;
    }
    return ['ok'=>true,'path'=>'assets/uploads/' . $subdir . '/' . $fn,'thumb'=>$thumbRel,'error'=>null];
}

/**
 * بازتولید کاورِ قبلاً آپلودشده — مثلاً بعد از تغییر اندازهٔ استاندارد.
 * روی همان فایل بازنویسی می‌کند (بندانگشتی هم تازه می‌شود).
 */
function fr_regen_cover(?string $relPath): bool
{
    $p = (string)$relPath;
    if ($p === '' || !str_starts_with($p, 'assets/uploads/') || !fr_gd_available()) return false;
    $full = FR_ROOT . '/' . $p;
    $real = realpath($full);
    $base = realpath(FR_ROOT . '/assets/uploads');
    if (!$real || !$base || !str_starts_with($real, $base) || !is_file($real)) return false;

    $sub = basename(dirname($p));
    if (!in_array($sub, ['events','services'], true)) return false;

    // ابتدا در یک فایل موقت پردازش می‌کنیم تا در صورت خطا اصل فایل سالم بماند
    $tmp = $real . '.regen.tmp';
    if (!fr_gd_cover($real, $tmp, 1200, 675)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $real)) { @unlink($tmp); return false; }
    @chmod($real, 0644);

    // بندانگشتی هم‌نام (…-t.jpg) تازه می‌شود
    $tPath = preg_replace('/\.(jpe?g|png|webp)$/i', '-t.jpg', $real);
    if ($tPath) @fr_gd_cover($real, $tPath, 480, 270);
    return true;
}

/** حذف امن فایل تصویر از مسیر ذخیره‌شده (فقط داخل assets/uploads/) */
function fr_delete_image(?string $path): void
{
    $p = (string)$path;
    if ($p === '' || !str_starts_with($p, 'assets/uploads/')) return;
    $full = FR_ROOT . '/' . $p;
    // جلوگیری از symlink/path traversal — مسیر واقعی باید داخل uploads بماند
    $real = realpath($full);
    $base = realpath(FR_ROOT . '/assets/uploads');
    if ($real && $base && str_starts_with($real, $base) && is_file($real)) @unlink($real);
    // بندانگشتی همنام (…-t.jpg) هم — اگر وجود دارد — پاک می‌شود
    $thumb = preg_replace('/\.(jpe?g|png|webp)$/i', '-t.jpg', $full);
    if ($thumb && $thumb !== $full) {
        $rt = realpath($thumb);
        if ($rt && $base && str_starts_with($rt, $base) && is_file($rt)) @unlink($rt);
    }
}

/** سازگاری با نسخهٔ ۱٫۳: ذخیرهٔ آواتار (کاربر/مدیر) */
function fr_save_avatar(array $file, string $kind, int $id): array
{
    return fr_save_image($file, 'avatars', $kind === 'admin' ? 'admin' : 'user', $id);
}

/** سازگاری با نسخهٔ ۱٫۳: حذف آواتار قبلی */
function fr_delete_avatar(?string $path): void
{
    fr_delete_image($path);
}
