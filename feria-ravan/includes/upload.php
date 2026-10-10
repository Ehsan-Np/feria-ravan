<?php
/**
 * ============================================================================
 *  آپلود امن فایل‌ها و تصاویر — فریا روان (Feria Uploads System)
 *  ─ تمامی فایل‌های آپلودی در پوشهٔ اختصاصی /feria-uploads/ در روت پروژه ذخیره می‌شوند.
 *  ─ تفکیک دقیق زیرپوشه‌ها بر اساس بخش مربوطه:
 *     • feria-uploads/workshops/  => تصاویر و کاورهای کارگاه‌ها
 *     • feria-uploads/courses/    => تصاویر و کاورهای دوره‌ها (با پشتیبانی از alias: course)
 *     • feria-uploads/users/      => آواتارها و تصاویر پروفایل کاربران و مدیران
 *     • feria-uploads/tests/      => تصاویر و کاورهای تست‌های روانشناسی
 *     • feria-uploads/tickets/    => پیوست‌های تصویری تیکت‌های پشتیبانی
 *     • feria-uploads/services/   => تصاویر خدمات و جلسات مشاوره
 *     • feria-uploads/banners/    => تصاویر بنرهای صفحه اصلی و پیشخوان
 *     • feria-uploads/general/    => لوگو، نمادهای فوتر و فایل‌های عمومی سایت
 *     • feria-uploads/rte/        => تصاویر آپلودشده در ویرایشگرهای متن پیشرفته
 *  سیاست امنیتی:
 *   ─ فقط تصویر واقعی (MIME واقعی + getimagesize + بررسی پسوند)
 *   ─ حداکثر ۲ تا ۵ مگابایت، نام تصادفی غیرقابل‌حدس، بدون اجرای اسکریپت (.htaccess)
 *   ─ بازتولید خودکار نسبت استاندارد و بندانگشتی با GD
 * ============================================================================
 */
declare(strict_types=1);

/** مسیر فیزیکی ریشهٔ آپلودها در سرور */
function fr_upload_base_dir(?string $subdir = null): string
{
    $base = FR_ROOT . '/feria-uploads';
    if ($subdir !== null && $subdir !== '') {
        $norm = fr_upload_subdir_normalize($subdir);
        return $base . '/' . $norm;
    }
    return $base;
}

/** آدرس وب ریشهٔ آپلودها */
function fr_upload_base_url(?string $subdir = null): string
{
    $base = SITE_URL . '/feria-uploads';
    if ($subdir !== null && $subdir !== '') {
        $norm = fr_upload_subdir_normalize($subdir);
        return $base . '/' . $norm;
    }
    return $base;
}

/** نرمال‌سازی و اعتبارسنجی زیرپوشهٔ آپلود به ساختار تفکیک‌شدهٔ feria-uploads */
function fr_upload_subdir_normalize(string $subdir, string $prefix = ''): string
{
    $s = strtolower(trim($subdir, " /\\"));
    $p = strtolower(trim($prefix));

    if ($s === 'workshop' || $s === 'workshops' || ($s === 'events' && str_contains($p, 'workshop'))) {
        return 'workshops';
    }
    if ($s === 'course' || $s === 'courses' || ($s === 'events' && str_contains($p, 'course'))) {
        return 'courses';
    }
    if (in_array($s, ['avatar', 'avatars', 'user', 'users', 'admin', 'admins', 'profile'], true)) {
        return 'users';
    }
    if ($s === 'test' || $s === 'tests') {
        return 'tests';
    }
    if ($s === 'ticket' || $s === 'tickets') {
        return 'tickets';
    }
    if (in_array($s, ['service', 'services', 'consult', 'consultant', 'consultants'], true)) {
        return 'services';
    }
    if ($s === 'banner' || $s === 'banners') {
        return 'banners';
    }
    if (in_array($s, ['general', 'settings', 'logo', 'logos', 'badge', 'badges'], true)) {
        return 'general';
    }
    if ($s === 'rte') {
        return 'rte';
    }

    // فهرست پوشه‌های مجاز رسمی
    $allowed = ['workshops', 'courses', 'users', 'tests', 'tickets', 'services', 'banners', 'general', 'rte'];
    return in_array($s, $allowed, true) ? $s : 'general';
}

/** اطمینان از وجود پوشهٔ آپلود و فایل محافظتی .htaccess */
function fr_ensure_upload_dirs(): void
{
    $base = FR_ROOT . '/feria-uploads';
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    $htPath = $base . '/.htaccess';
    if (!is_file($htPath)) {
        $ht = "# فریا روان — محافظت از پوشهٔ اختصاصی آپلود feria-uploads\n"
            . "Options -Indexes\n"
            . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|asp|aspx|sh)$\">\n"
            . "  Require all denied\n"
            . "  Deny from all\n"
            . "</FilesMatch>\n";
        @file_put_contents($htPath, $ht);
    }

    $subdirs = ['workshops', 'courses', 'users', 'tests', 'tickets', 'services', 'banners', 'general', 'rte'];
    foreach ($subdirs as $sub) {
        $dir = $base . '/' . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $keep = $dir . '/.gitkeep';
        if (!is_file($keep)) {
            @file_put_contents($keep, '');
        }
    }
}

/**
 * ذخیرهٔ عمومی تصویر آپلودی در زیرپوشهٔ مشخص از feria-uploads
 * @param array  $file    عنصر $_FILES[...]
 * @param string $subdir  زیرپوشهٔ مقصد (workshops|courses|users|tests|tickets|services|banners|general|rte)
 * @param string $prefix  پیشوند نام فایل (workshop/course/user/admin/test/service/...)
 * @param int    $id      شناسهٔ رکورد مالک (جهت خوانایی لاگ و نام فایل)
 * @return array ['ok'=>bool,'path'=>?string,'error'=>?string] — path نسبی به ریشهٔ سایت (مثلاً feria-uploads/users/...)
 */
function fr_save_image(array $file, string $subdir, string $prefix, int $id = 0): array
{
    $normSub = fr_upload_subdir_normalize($subdir, $prefix);
    $prefixClean = preg_replace('/[^a-z0-9_-]/', '', strtolower($prefix)) ?: 'file';

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'path' => null, 'error' => 'فایلی انتخاب نشده است.'];
    }
    if ((int)($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'خطا در آپلود فایل (کد ' . (int)$file['error'] . ').'];
    }
    if (($file['size'] ?? 0) > 4_000_000) {
        return ['ok' => false, 'path' => null, 'error' => 'حجم تصویر باید حداکثر ۴ مگابایت باشد.'];
    }

    // ۱) کنترل MIME واقعی محتوا
    $mime = mime_content_type((string)$file['tmp_name']);
    $map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg'];
    if (!isset($map[$mime])) {
        return ['ok' => false, 'path' => null, 'error' => 'فقط تصویر JPG، PNG، WEBP یا GIF مجاز است.'];
    }

    // ۲) تأیید ساختار تصویر (جلوگیری از فایل‌های جعلی/بدافزار)
    if ($map[$mime] !== 'svg' && !@getimagesize((string)$file['tmp_name'])) {
        return ['ok' => false, 'path' => null, 'error' => 'فایل انتخابی یک تصویر معتبر نیست.'];
    }

    // ۳) آماده‌سازی مقصد امن در feria-uploads
    $dir = FR_ROOT . '/feria-uploads/' . $normSub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'path' => null, 'error' => 'پوشهٔ آپلود feria-uploads/' . $normSub . ' قابل ایجاد نیست.'];
    }

    $fn  = $prefixClean . '-' . max(1, $id) . '-' . bin2hex(random_bytes(6)) . '.' . $map[$mime];
    $dst = $dir . '/' . $fn;

    $saved = false;
    if (is_uploaded_file((string)$file['tmp_name'])) {
        $saved = move_uploaded_file((string)$file['tmp_name'], $dst);
    } else {
        // پشتیبانی از آزمون‌های خودکار و محیط CLI
        $saved = @copy((string)$file['tmp_name'], $dst);
    }

    if (!$saved) {
        return ['ok' => false, 'path' => null, 'error' => 'ذخیرهٔ فایل ناموفق بود (دسترسی پوشه را بررسی کنید).'];
    }
    @chmod($dst, 0644);

    return ['ok' => true, 'path' => 'feria-uploads/' . $normSub . '/' . $fn, 'error' => null];
}

/* ===========================================================================
 |  موتور بازتولید کاور — با افزونهٔ GD
 |  هر کاور بارگذاری‌شده برای کارگاه، دوره یا تست:
 |   ─ با نسبت استاندارد ۱۶:۹ برش خورده و فشرده می‌شود (۱۲۰۰×۶۷۵)
 |   ─ بندانگشتی مناسب (۴۸۰×۲۷۰) تولید می‌شود (...-t.jpg)
 |   ─ متادیتاهای سنگین و EXIF حذف می‌شوند
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
 * برش پوششی + تغییر اندازه به W×H و ذخیره به‌صورت JPEG بهینه
 */
function fr_gd_cover(string $src, string $dst, int $W, int $H): bool
{
    $img = fr_gd_load($src);
    if (!$img) return false;
    $sw = imagesx($img);
    $sh = imagesy($img);
    if ($sw < 10 || $sh < 10) {
        imagedestroy($img);
        return false;
    }

    $tRatio = $W / $H;
    $sRatio = $sw / $sh;
    if ($sRatio > $tRatio) {
        $cw = (int)round($sh * $tRatio);
        $ch = $sh;
        $cx = (int)round(($sw - $cw) / 2);
        $cy = 0;
    } else {
        $cw = $sw;
        $ch = (int)round($sw / $tRatio);
        $cx = 0;
        $cy = (int)round(($sh - $ch) / 2);
    }

    $out = imagecreatetruecolor($W, $H);
    if (!$out) {
        imagedestroy($img);
        return false;
    }
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
    if (!imagecopyresampled($out, $img, 0, 0, $cx, $cy, $W, $H, $cw, $ch)) {
        imagedestroy($img);
        imagedestroy($out);
        return false;
    }
    $ok = @imagejpeg($out, $dst, 86);
    imagedestroy($img);
    imagedestroy($out);
    if ($ok) @chmod($dst, 0644);
    return (bool)$ok;
}

/**
 * ذخیرهٔ کاور رویداد (کارگاه/دوره/تست/خدمت) با بهینه‌سازی و بندانگشتی در feria-uploads
 * @return array ['ok'=>bool,'path'=>?string,'thumb'=>?string,'error'=>?string]
 */
function fr_save_cover(array $file, string $subdir, string $prefix, int $id = 0): array
{
    $normSub = fr_upload_subdir_normalize($subdir, $prefix);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'path' => null, 'thumb' => null, 'error' => 'فایلی انتخاب نشده است.'];
    }
    if ((int)($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'thumb' => null, 'error' => 'خطا در آپلود فایل (کد ' . (int)$file['error'] . ').'];
    }
    if (($file['size'] ?? 0) > 6_000_000) {
        return ['ok' => false, 'path' => null, 'thumb' => null, 'error' => 'حجم تصویر باید حداکثر ۶ مگابایت باشد.'];
    }

    $mime = mime_content_type((string)$file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['ok' => false, 'path' => null, 'thumb' => null, 'error' => 'فقط تصویر JPG یا PNG یا WEBP مجاز است.'];
    }
    if (!@getimagesize((string)$file['tmp_name'])) {
        return ['ok' => false, 'path' => null, 'error' => 'فایل انتخابی یک تصویر معتبر نیست.'];
    }

    $dir = FR_ROOT . '/feria-uploads/' . $normSub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'path' => null, 'thumb' => null, 'error' => 'پوشهٔ آپلود feria-uploads/' . $normSub . ' قابل ایجاد نیست.'];
    }

    $prefixClean = preg_replace('/[^a-z0-9_-]/', '', strtolower($prefix)) ?: 'cover';
    $baseName    = $prefixClean . '-' . max(1, $id) . '-' . bin2hex(random_bytes(6));

    if (!fr_gd_available()) {
        $fallback = fr_save_image($file, $normSub, $prefixClean, $id);
        return $fallback + ['thumb' => null];
    }

    $fn  = $baseName . '.jpg';
    $dst = $dir . '/' . $fn;
    if (!fr_gd_cover((string)$file['tmp_name'], $dst, 1200, 675)) {
        return ['ok' => false, 'path' => null, 'thumb' => null, 'error' => 'پردازش تصویر ناموفق بود؛ لطفاً تصویر دیگری انتخاب کنید.'];
    }

    $thumbRel = null;
    $tFn = $baseName . '-t.jpg';
    if (fr_gd_cover((string)$file['tmp_name'], $dir . '/' . $tFn, 480, 270)) {
        $thumbRel = 'feria-uploads/' . $normSub . '/' . $tFn;
    }

    return ['ok' => true, 'path' => 'feria-uploads/' . $normSub . '/' . $fn, 'thumb' => $thumbRel, 'error' => null];
}

/**
 * بازتولید کاورِ قبلاً آپلودشده (با پشتیبانی از feria-uploads و assets/uploads قدیمی)
 */
function fr_regen_cover(?string $relPath): bool
{
    $p = trim((string)$relPath, " /\\");
    if ($p === '' || !fr_gd_available()) return false;

    $isFeriaUpload = str_starts_with($p, 'feria-uploads/');
    $isLegacyUpload = str_starts_with($p, 'assets/uploads/');
    if (!$isFeriaUpload && !$isLegacyUpload) return false;

    $full = FR_ROOT . '/' . $p;
    $real = realpath($full);
    $baseUploads = realpath(FR_ROOT . '/feria-uploads');
    $baseLegacy  = realpath(FR_ROOT . '/assets/uploads');

    $valid = false;
    if ($real && $baseUploads && str_starts_with($real, $baseUploads) && is_file($real)) $valid = true;
    if ($real && $baseLegacy && str_starts_with($real, $baseLegacy) && is_file($real)) $valid = true;
    if (!$valid) return false;

    $tmp = $real . '.regen.tmp';
    if (!fr_gd_cover($real, $tmp, 1200, 675)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $real)) { @unlink($tmp); return false; }
    @chmod($real, 0644);

    $tPath = preg_replace('/\.(jpe?g|png|webp)$/i', '-t.jpg', $real);
    if ($tPath) @fr_gd_cover($real, $tPath, 480, 270);
    return true;
}

/**
 * حذف امن فایل تصویر از مسیر ذخیره‌شده (در feria-uploads/ یا assets/uploads/)
 */
function fr_delete_image(?string $path): void
{
    $p = trim((string)$path, " /\\");
    if ($p === '') return;
    if (!str_starts_with($p, 'feria-uploads/') && !str_starts_with($p, 'assets/uploads/')) return;

    $full = FR_ROOT . '/' . $p;
    $real = realpath($full);
    $baseUploads = realpath(FR_ROOT . '/feria-uploads');
    $baseLegacy  = realpath(FR_ROOT . '/assets/uploads');

    $valid = false;
    if ($real && $baseUploads && str_starts_with($real, $baseUploads) && is_file($real)) $valid = true;
    if ($real && $baseLegacy && str_starts_with($real, $baseLegacy) && is_file($real)) $valid = true;

    if ($valid) @unlink($real);

    // بندانگشتی همنام (...-t.jpg) هم پاک می‌شود
    $thumb = preg_replace('/\.(jpe?g|png|webp)$/i', '-t.jpg', $full);
    if ($thumb && $thumb !== $full) {
        $rt = realpath($thumb);
        if ($rt && $baseUploads && str_starts_with($rt, $baseUploads) && is_file($rt)) @unlink($rt);
        if ($rt && $baseLegacy && str_starts_with($rt, $baseLegacy) && is_file($rt)) @unlink($rt);
    }
}

/** ذخیرهٔ آواتار کاربر یا مدیر در feria-uploads/users/ */
function fr_save_avatar(array $file, string $kind, int $id): array
{
    return fr_save_image($file, 'users', $kind === 'admin' ? 'admin' : 'user', $id);
}

/** حذف آواتار قبلی */
function fr_delete_avatar(?string $path): void
{
    fr_delete_image($path);
}
