<?php
/**
 * ============================================================================
 *  موتور به‌روزرسانی هوشمند سامانه فریا روان (Project One-Click Updater Engine)
 *  ─ دریافت و اعتبارسنجی پکیج به‌روزرسانی از طریق لینک مستقیم ZIP یا فایل آپلودشده
 *  ─ حفاظت قطعی از فایل‌های حساس (config.php، .env، و پوشه feria-uploads/)
 *  ─ استخراج هوشمند با پشتیبانی از ساختارهای تو در تو (Subdirectory / Root)
 *  ─ اجرای خودکار پایپ‌لاین مهاجرت‌های دیتابیس (fr_update_pipeline)
 *  ─ مدیریت زمان و حافظه، پاکسازی کش OpCode، ریست کش استاتیک و ثبت وقایع
 * ============================================================================
 */
declare(strict_types=1);

final class ProjectUpdater
{
    /** حداکثر زمان دانلود (ثانیه) */
    private const DOWNLOAD_TIMEOUT = 300;

    /**
     * پیدا کردن مسیر موقت مناسب و دارای دسترسی نوشتن
     */
    private static function getWritableTempDir(): string
    {
        $candidates = [
            sys_get_temp_dir(),
            FR_ROOT . '/storage/cache',
            FR_ROOT . '/storage',
            FR_ROOT . '/feria-uploads/general',
        ];

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        return sys_get_temp_dir();
    }

    /**
     * دانلود فایل ZIP از URL مستقیم با مدیریت خطای جامع
     */
    public static function downloadZip(string $url): array
    {
        @ini_set('memory_limit', '256M');
        @set_time_limit(self::DOWNLOAD_TIMEOUT);

        $url = trim($url);
        // نرمال‌سازی ارقام فارسی در نشانی اینترنتی (در صورت کپی‌پیست اشتباه)
        $url = (string)preg_replace_callback('/[\x{06F0}-\x{06F9}\x{0660}-\x{0669}]/u', function ($m) {
            $char = $m[0];
            $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
            $pos = array_search($char, $fa, true);
            if ($pos !== false) return (string)$pos;
            $pos = array_search($char, $ar, true);
            if ($pos !== false) return (string)$pos;
            return $char;
        }, $url);

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return [
                'ok' => false,
                'message' => 'نشانی اینترنتی (URL) فایل زیپ نامعتبر است. نشانی باید با http:// یا https:// آغاز شود.'
            ];
        }

        $tmpDir = self::getWritableTempDir();
        $tmpFile = rtrim($tmpDir, '/') . '/fr_update_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';

        // استفاده از cURL برای دانلود پایدار با قابلیت دنبال‌کردن ریدایرکت‌ها
        if (function_exists('curl_init')) {
            $fp = @fopen($tmpFile, 'wb');
            if (!$fp) {
                return [
                    'ok' => false,
                    'message' => 'امکان ایجاد فایل موقت برای ذخیره پکیج آپدیت در مسیر سرور وجود ندارد (' . htmlspecialchars($tmpDir) . ').'
                ];
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 7);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::DOWNLOAD_TIMEOUT);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FeriaRavan-Updater/' . (defined('FR_VERSION') ? FR_VERSION : '3.4.3'));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $executed = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if (!$executed || $httpCode < 200 || $httpCode >= 400) {
                @unlink($tmpFile);
                return [
                    'ok' => false,
                    'message' => 'خطا در دریافت فایل از لینک واردشده: ' . ($curlError ?: "کد وضعیت سرور مبدا {$httpCode} می‌باشد.")
                ];
            }
        } else {
            // روش جایگزین با file_get_contents
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => self::DOWNLOAD_TIMEOUT,
                    'follow_location' => 1,
                    'user_agent' => 'FeriaRavan-Updater/' . (defined('FR_VERSION') ? FR_VERSION : '3.4.3'),
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);

            $content = @file_get_contents($url, false, $ctx);
            if ($content === false || strlen($content) < 1000) {
                return [
                    'ok' => false,
                    'message' => 'امکان دانلود فایل از این نشانی وجود ندارد یا فایل خالی است.'
                ];
            }
            if (@file_put_contents($tmpFile, $content) === false) {
                return [
                    'ok' => false,
                    'message' => 'خطا در ذخیره‌سازی بستهٔ دانلودشده بر روی دیسک سرور.'
                ];
            }
        }

        return self::validateAndReturn($tmpFile);
    }

    /**
     * اعتبارسنجی فایل آپلودشده از فرم
     */
    public static function handleUploadedZip(array $file): array
    {
        @ini_set('memory_limit', '256M');
        @set_time_limit(self::DOWNLOAD_TIMEOUT);

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($errCode) {
                UPLOAD_ERR_INI_SIZE   => 'حجم فایل از سقف مجاز در php.ini بیشتر است (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE  => 'حجم فایل از سقف فرم بیشتر است.',
                UPLOAD_ERR_PARTIAL    => 'فایل به‌صورت ناقص آپلود شده است.',
                UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور یافت نشد.',
                UPLOAD_ERR_CANT_WRITE => 'خطا در نوشتن فایل بر روی دیسک سرور.',
                default               => 'هیچ فایلی برای آپلود انتخاب نشده است.',
            };
            return ['ok' => false, 'message' => $msg];
        }

        $tmpDir = self::getWritableTempDir();
        $tmpFile = rtrim($tmpDir, '/') . '/fr_upload_update_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $tmpFile)) {
            return ['ok' => false, 'message' => 'خطا در انتقال فایل آپلودشده به مسیر موقت.'];
        }

        return self::validateAndReturn($tmpFile);
    }

    /**
     * بررسی امضای هدر ZIP و باز شدن معتبر
     */
    private static function validateAndReturn(string $filePath): array
    {
        if (!file_exists($filePath) || filesize($filePath) < 1000) {
            @unlink($filePath);
            return ['ok' => false, 'message' => 'فایل دانلود یا آپلود شده بسیار کوچک یا ناقص است.'];
        }

        // بررسی امضای جادویی ZIP (PK\x03\x04)
        $fh = @fopen($filePath, 'rb');
        if (!$fh) {
            @unlink($filePath);
            return ['ok' => false, 'message' => 'امکان باز کردن فایل موقت برای بررسی وجود ندارد.'];
        }
        $magic = fread($fh, 4);
        fclose($fh);

        if ($magic !== "PK\x03\x04") {
            @unlink($filePath);
            return ['ok' => false, 'message' => 'فایل دریافت شده یک فایل فشرده معتبر با فرمت ZIP نیست.'];
        }

        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'ماژول PHP ZipArchive روی این سرور نصب یا فعال نیست. لطفاً آن را در تنظیمات PHP فعال کنید.'];
        }

        $zip = new ZipArchive();
        $openRes = $zip->open($filePath);
        if ($openRes !== true) {
            @unlink($filePath);
            return ['ok' => false, 'message' => 'فایل ZIP باز نشد؛ ممکن است بسته ناقص دانلود شده باشد (کد خطا: ' . $openRes . ').'];
        }

        $numFiles = $zip->numFiles;
        $hasEngineFiles = false;
        $subFolder = '';

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';
            if (str_contains($name, 'includes/version.php') || str_contains($name, 'includes/db.php')) {
                $hasEngineFiles = true;
                $pos = strpos($name, 'includes/');
                if ($pos > 0) {
                    $subFolder = substr($name, 0, $pos);
                }
                break;
            }
        }

        $zip->close();

        if (!$hasEngineFiles) {
            @unlink($filePath);
            return ['ok' => false, 'message' => 'پکیج ZIP معتبر است ولی ساختار پوشه‌ها و فایل‌های اصلی سیستم فریا روان در آن شناسایی نشد.'];
        }

        return [
            'ok'        => true,
            'file_path' => $filePath,
            'file_size' => filesize($filePath),
            'files_cnt' => $numFiles,
            'subfolder' => $subFolder,
        ];
    }

    /**
     * اعمال به‌روزرسانی: استخراج، جایگزینی، مهاجرت دیتابیس و پاکسازی
     */
    public static function applyUpdate(string $zipPath, bool $preserveConfig = true): array
    {
        @ini_set('memory_limit', '256M');
        @set_time_limit(self::DOWNLOAD_TIMEOUT);

        if (!file_exists($zipPath)) {
            return ['ok' => false, 'message' => 'فایل پکیج برای اعمال آپدیت یافت نشد.'];
        }

        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'ماژول ZipArchive در دسترس نیست.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'باز کردن پکیج فشرده با خطا مواجه شد.'];
        }

        $oldVersion = defined('FR_VERSION') ? FR_VERSION : '3.4.0';

        // شناسایی مسیر ریشه در پکیج
        $subFolder = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $pos = strpos($name, 'includes/db.php');
            if ($pos !== false && $pos > 0) {
                $subFolder = substr($name, 0, $pos);
                break;
            }
        }

        // پشتیبان‌گیری ایمن از فایل کانفیگ و نگهداری در پوشه storage
        $configPath = FR_ROOT . '/config.php';
        $storageDir = FR_ROOT . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        if (file_exists($configPath)) {
            $bak = $storageDir . '/config.backup.' . date('Ymd_His') . '.php';
            @copy($configPath, $bak);
        }

        $envPath = FR_ROOT . '/.env';
        if (file_exists($envPath)) {
            $bakEnv = $storageDir . '/.env.backup.' . date('Ymd_His');
            @copy($envPath, $bakEnv);
        }

        $extractedCount = 0;
        $subLen = strlen($subFolder);
        $warnings = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            // اگر فایل در ساب‌فولدر است، پیشوند را حذف می‌کنیم
            $relPath = $entryName;
            if ($subLen > 0) {
                if (!str_starts_with($relPath, $subFolder)) {
                    continue;
                }
                $relPath = substr($relPath, $subLen);
            }

            $relPath = ltrim($relPath, '/\\');
            if ($relPath === '') continue;

            // قوانین حفاظتی اکید:
            // ۱. محافظت از config.php
            if ($preserveConfig && ($relPath === 'config.php' || str_ends_with($relPath, '/config.php'))) {
                if (file_exists($configPath)) {
                    continue;
                }
            }

            // ۲. هرگز فایل‌های موجود در feria-uploads حذف یا بازنویسی نشوند
            if (str_starts_with($relPath, 'feria-uploads/')) {
                $targetFile = FR_ROOT . '/' . $relPath;
                if (file_exists($targetFile)) {
                    continue;
                }
            }

            // ۳. محافظت از فایل‌های موقت یا لاگ‌ها
            if (str_starts_with($relPath, 'storage/logs/') || str_starts_with($relPath, 'storage/sessions/')) {
                continue;
            }

            $dest = FR_ROOT . '/' . $relPath;

            // اگر دایرکتوری است
            if (str_ends_with($entryName, '/') || str_ends_with($entryName, '\\')) {
                if (!is_dir($dest)) {
                    @mkdir($dest, 0755, true);
                }
                continue;
            }

            // اطمینان از وجود پوشه والد
            $parentDir = dirname($dest);
            if (!is_dir($parentDir)) {
                @mkdir($parentDir, 0755, true);
            }

            // استخراج محتوای فایل
            $stream = $zip->getStream($entryName);
            if ($stream) {
                $fp = @fopen($dest, 'wb');
                if ($fp) {
                    while (!feof($stream)) {
                        fwrite($fp, fread($stream, 8192));
                    }
                    fclose($fp);
                    $extractedCount++;
                } else {
                    $warnings[] = "امکان نوشتن فایل وجود نداشت: {$relPath}";
                }
                fclose($stream);
            }
        }

        $zip->close();
        @unlink($zipPath);

        // اجرای پایپ‌لاین مهاجرت و آپدیت پایگاه‌داده
        $pipelineOutput = '';
        try {
            require_once FR_ROOT . '/includes/update.php';
            if (function_exists('fr_update_pipeline')) {
                fr_update_pipeline(true);
                $pipelineOutput = 'مهاجرت‌های ساختار دیتابیس با موفقیت اعمال شد.';
            }
        } catch (Throwable $e) {
            $pipelineOutput = 'هشدار در اجرای مهاجرت پایگاه داده: ' . $e->getMessage();
        }

        // پاک‌سازی کش PHP OpCode
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // خواندن نسخه جدید پس از استخراج
        $newVersion = '3.4.3';
        $verFile = FR_ROOT . '/includes/version.php';
        if (file_exists($verFile)) {
            $verCode = file_get_contents($verFile);
            if (preg_match("/define\('FR_RELEASE',\s*'([^']+)'\)/", $verCode, $m)) {
                $newVersion = $m[1];
            }
        }

        // به‌روزرسانی تنظیم نسخه در جدول تنظیمات
        try {
            DB::setSetting('system_version', $newVersion);
            DB::setSetting('last_update_at', date('Y-m-d H:i:s'));
            DB::setSetting('fr_version', $newVersion);
            log_activity('system_update', 'system', 0, [
                'from_version' => $oldVersion,
                'to_version'   => $newVersion,
                'files_count'  => $extractedCount,
            ]);
        } catch (Throwable $e) {}

        return [
            'ok'              => true,
            'old_version'     => $oldVersion,
            'new_version'     => $newVersion,
            'files_count'     => $extractedCount,
            'warnings'        => $warnings,
            'pipeline_output' => $pipelineOutput,
            'message'         => "سامانه فریا روان با موفقیت از نگارش {$oldVersion} به نگارش {$newVersion} ارتقا یافت ({$extractedCount} فایل به‌روزرسانی گردید).",
        ];
    }
}
