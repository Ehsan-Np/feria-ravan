<?php
/**
 * ============================================================================
 *  مدیریت به‌روزرسانی و ارتقای سامانه فریا روان (System Updater)
 *  ─ به‌روزرسانی خودکار با لینک مستقیم فایل زیپ (One-Click URL Update)
 *  ─ آپلود دستی بستهٔ ZIP و اعمال بدون قطعی
 *  ─ حفاظت از فایل‌های پیکربندی و پوشه feria-uploads
 * ============================================================================
 */
declare(strict_types=1);
@ini_set('memory_limit', '256M');
@set_time_limit(300);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../core/ProjectUpdater.php';

$admin = require_admin();
$canUpdate = false;
if (in_array($admin['role'] ?? '', ['superadmin', 'super', 'admin', 'manager'], true)) {
    $canUpdate = true;
} elseif (function_exists('fr_admin_can') && (fr_admin_can($admin, 'settings') || fr_admin_can($admin, 'roles'))) {
    $canUpdate = true;
}
if (!$canUpdate) {
    http_response_code(403);
    exit('دسترسی مجاز نیست — فقط مدیران ارشد سیستم و دارندگان دسترسی تنظیمات می‌توانند سامانه را به‌روزرسانی کنند.');
}

$updateLog = null;
$errorMsg  = null;

// پردازش فرم به‌روزرسانی
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
    csrf_guard();
    $action = (string)post('action');
    $preserveConfig = post('preserve_config') === '1';

    if ($action === 'url_update') {
        $zipUrl = trim((string)post('zip_url'));
        if ($zipUrl === '') {
            $errorMsg = 'لطفاً لینک مستقیم فایل زیپ را وارد کنید.';
        } else {
            $download = ProjectUpdater::downloadZip($zipUrl);
            if (!$download['ok']) {
                $errorMsg = $download['message'];
            } else {
                $res = ProjectUpdater::applyUpdate($download['file_path'], $preserveConfig);
                if ($res['ok']) {
                    $updateLog = $res;
                    flash($res['message'], 'ok');
                } else {
                    $errorMsg = $res['message'];
                }
            }
        }
    } elseif ($action === 'file_update') {
        if (empty($_FILES['zip_file'])) {
            $errorMsg = 'لطفاً فایل زیپ به‌روزرسانی را انتخاب کنید.';
        } else {
            $upload = ProjectUpdater::handleUploadedZip($_FILES['zip_file']);
            if (!$upload['ok']) {
                $errorMsg = $upload['message'];
            } else {
                $res = ProjectUpdater::applyUpdate($upload['file_path'], $preserveConfig);
                if ($res['ok']) {
                    $updateLog = $res;
                    flash($res['message'], 'ok');
                } else {
                    $errorMsg = $res['message'];
                }
            }
        }
    } elseif ($action === 'rerun_pipeline') {
        try {
            require_once FR_ROOT . '/includes/update.php';
            fr_update_pipeline(true);
            flash('پایپ‌لاین مهاجرت دیتابیس با موفقیت مجدداً اجرا شد ✅', 'ok');
        } catch (Throwable $e) {
            $errorMsg = 'خطا در اجرای پایپ‌لاین: ' . $e->getMessage();
        }
    } elseif ($action === 'cleanup_install') {
        try {
            require_once FR_ROOT . '/includes/update.php';
            $cRes = fr_cleanup_install_folder();
            if ($cRes['ok']) {
                flash($cRes['message'], 'ok');
            } else {
                $errorMsg = $cRes['message'];
            }
        } catch (Throwable $e) {
            $errorMsg = 'خطا در پاک‌سازی پوشه نصب: ' . $e->getMessage();
        }
    } elseif ($action === 'run_reseed') {
        try {
            require_once FR_ROOT . '/includes/update.php';
            fr_update_pipeline(true);
            require_once FR_ROOT . '/core/seed-content.php';
            fr_seed_content();
            if (function_exists('fr_seed_mseq_tests')) fr_seed_mseq_tests();
            if (file_exists(FR_ROOT . '/core/seed-comprehensive-tests.php')) {
                require_once FR_ROOT . '/core/seed-comprehensive-tests.php';
                if (function_exists('fr_seed_comprehensive_tests')) fr_seed_comprehensive_tests(true);
            }
            if (file_exists(FR_ROOT . '/core/seed-analyses29.php')) {
                require_once FR_ROOT . '/core/seed-analyses29.php';
                if (function_exists('fr_seed_deep_analyses')) fr_seed_deep_analyses();
            }
            if (class_exists('PageBuilder')) {
                PageBuilder::ensureSchema();
                PageBuilder::seedDefaultPages();
            }
            if (class_exists('MenuBuilder')) {
                MenuBuilder::ensureSchema();
            }
            if (function_exists('fr_texts_seed')) fr_texts_seed();
            if (function_exists('fr_roles_seed')) fr_roles_seed();
            if (function_exists('fr_permalinks_flush')) fr_permalinks_flush();
            flash('بذر مجدد تمامی آزمون‌ها، قالب‌های تحلیل، برگه‌ها و تنظیمات با موفقیت انجام شد ✅', 'ok');
        } catch (Throwable $e) {
            $errorMsg = 'خطا در اجرای بذر محتوا: ' . $e->getMessage();
        }
    }
    } catch (Throwable $e) {
        $errorMsg = 'خطا در پردازش به‌روزرسانی: ' . $e->getMessage();
    }
}

$schemaVer  = (string)DB::setting('schema_version', '1.0');
$lastUpdate = (string)DB::setting('last_update_at', '');

admin_layout($admin, 'updater', '🚀 به‌روزرسانی و ارتقای سامانه');
?>
<div style="max-width:960px;margin:0 auto">
  <?= flash() ?>

  <?php if ($errorMsg): ?>
    <div class="fr-alert err" style="margin-bottom:20px">
      ❌ <b>خطا در به‌روزرسانی:</b> <?= e($errorMsg) ?>
    </div>
  <?php endif; ?>

  <?php if ($updateLog): ?>
    <div class="fr-card" style="background:#f0fdf4;border:2px solid #22c55e;margin-bottom:24px;padding:22px">
      <h3 style="color:#15803d;margin:0 0 10px;font-size:17px">🎉 به‌روزرسانی سامانه با موفقیت به پایان رسید!</h3>
      <p style="font-size:14px;color:#166534;line-height:1.9;margin:0 0 12px">
        سامانه فریا روان از نسخهٔ <b><?= e($updateLog['old_version']) ?></b> به نگارش <b><?= e($updateLog['new_version']) ?></b> ارتقا یافت.<br>
        تعداد فایل‌های جایگزین‌شده: <b><?= fa_num($updateLog['files_count']) ?></b> فایل<br>
        وضعیت پایگاه داده: <b><?= e($updateLog['pipeline_output']) ?></b>
      </p>
      <?php if (!empty($updateLog['warnings'])): ?>
        <div style="margin-top:10px;padding:10px;background:#fef2f2;border-radius:8px;font-size:12px;color:#b91c1c">
          ⚠️ هشدارهای جزئی: <?= implode(' ، ', array_map('htmlspecialchars', $updateLog['warnings'])) ?>
        </div>
      <?php endif; ?>
      <a class="fr-btn sm" href="updater.php" style="margin-top:12px">تازه‌سازی صفحه ←</a>
    </div>
  <?php endif; ?>

  <!-- کارت مشخصات فعلی سیستم -->
  <div class="fr-card" style="margin-bottom:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px">
      <h3 style="margin:0;font-size:16px">ℹ️ اطلاعات وضعیت فعلی سامانه</h3>
      <span class="fr-chip purple" style="font-size:12.5px;padding:4px 12px">
        نگارش فعال: <b><?= e(FR_VERSION) ?></b>
      </span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;font-size:13.5px;line-height:2">
      <div style="background:#f8f9fc;padding:12px;border-radius:10px;border:1px solid var(--line)">
        <span style="color:var(--muted)">نسخه PHP:</span> <b><?= e(PHP_VERSION) ?></b>
      </div>
      <div style="background:#f8f9fc;padding:12px;border-radius:10px;border:1px solid var(--line)">
        <span style="color:var(--muted)">پشتیبانی ZipArchive:</span> <b><?= class_exists('ZipArchive') ? '✅ فعال' : '❌ غیرفعال' ?></b>
      </div>
      <div style="background:#f8f9fc;padding:12px;border-radius:10px;border:1px solid var(--line)">
        <span style="color:var(--muted)">نسخه اسکیما دیتابیس:</span> <b><?= e($schemaVer) ?></b>
      </div>
      <div style="background:#f8f9fc;padding:12px;border-radius:10px;border:1px solid var(--line)">
        <span style="color:var(--muted)">آخرین به‌روزرسانی:</span> <b><?= $lastUpdate !== '' ? fa_num(jdate('Y/m/d H:i', strtotime($lastUpdate))) : 'نامشخص' ?></b>
      </div>
    </div>
    <div class="fr-alert info" style="margin-top:16px;font-size:12.5px;line-height:1.9">
      🛡️ <b>حفاظت خودکار از داده‌ها:</b> هنگام اعمال بستهٔ آپدیت، فایل اتصال دیتابیس (<code>config.php</code>)، کلیدهای امنیتی و تمام فایل‌های آپلودشدهٔ کاربران در پوشهٔ <code>feria-uploads/</code> کاملاً ایمن مانده و هرگز بازنویسی نخواهند شد.
    </div>
  </div>

  <!-- فرم ۱: به‌روزرسانی از طریق لینک مستقیم ZIP -->
  <div class="fr-card" style="margin-bottom:24px">
    <h3 style="margin:0 0 12px;font-size:16px">🌐 به‌روزرسانی خودکار از طریق لینک فایل ZIP (پیشنهادی)</h3>
    <p style="font-size:13px;color:var(--muted);line-height:1.9;margin-top:0">
      لینک دانلود مستقیم بستهٔ ZIP نسخه جدید را وارد کنید. سامانه فایل را دانلود، اعتبارسنجی و به صورت خودکار اعمال می‌کند:
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="url_update">
      <div class="fr-field">
        <label>نشانی مستقیم فایل ZIP:</label>
        <input class="fr-input" name="zip_url" dir="ltr" style="text-align:left;font-family:monospace" placeholder="https://domain.com/downloads/feria-ravan-v3.4.3.zip" required>
        <small class="hint">نشانی باید به یک فایل فشرده <code>.zip</code> معتبر منتهی شود.</small>
      </div>
      <div style="margin-bottom:18px">
        <label class="tog">
          <input type="checkbox" name="preserve_config" value="1" checked>
          <span>ایجاد نسخهٔ پشتیبان از <code>config.php</code> قبل از استخراج (توصیه می‌شود)</span>
        </label>
      </div>
      <button class="fr-btn" style="background:#6d28d9" onclick="return confirm('آیا از اعمال این به‌روزرسانی بر روی سامانه اطمینان دارید؟')">
        🚀 دانلود و اعمال به‌روزرسانی ←
      </button>
    </form>
  </div>

  <!-- فرم ۲: آپلود دستی فایل ZIP -->
  <div class="fr-card" style="margin-bottom:24px">
    <h3 style="margin:0 0 12px;font-size:16px">📦 یا آپلود مستقیم بسته ZIP از سیستم شما</h3>
    <p style="font-size:13px;color:var(--muted);line-height:1.9;margin-top:0">
      در صورتی که فایل پکیج را روی رایانه خود دانلود کرده‌اید، می‌توانید آن را مستقیماً از فرم زیر آپلود و نصب کنید:
    </p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="file_update">
      <div class="fr-field">
        <label>انتخاب فایل زیپ بستهٔ جدید:</label>
        <input type="file" name="zip_file" accept=".zip" class="fr-input" required>
      </div>
      <div style="margin-bottom:18px">
        <label class="tog">
          <input type="checkbox" name="preserve_config" value="1" checked>
          <span>محافظت از فایل پیکربندی <code>config.php</code> فعلی</span>
        </label>
      </div>
      <button class="fr-btn ghost" onclick="return confirm('آیا از آپلود و اعمال این به‌روزرسانی اطمینان دارید؟')">
        📤 آپلود و نصب به‌روزرسانی
      </button>
    </form>
  </div>

  <!-- بخش ۳: ابزارهای نگهداری دیتابیس و پاک‌سازی -->
  <?php
    $hasInstallDir = is_dir(FR_ROOT . '/install');
    $installFilesCnt = 0;
    if ($hasInstallDir) {
        $files = @scandir(FR_ROOT . '/install');
        if (is_array($files)) {
            $installFilesCnt = count(array_filter($files, fn($f) => $f !== '.' && $f !== '..'));
        }
    }
  ?>
  <div class="fr-card" style="margin-bottom:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
      <h3 style="margin:0;font-size:16px">🧹 وضعیت پوشه نصب (install)</h3>
      <?php if ($hasInstallDir && $installFilesCnt > 0): ?>
        <span class="fr-chip danger">⚠️ حاوی <?= fa_num($installFilesCnt) ?> فایل — نیاز به پاکسازی</span>
      <?php else: ?>
        <span class="fr-chip success">✅ کاملاً پاک‌سازی شده و امن</span>
      <?php endif; ?>
    </div>
    <p style="font-size:13px;color:var(--muted);line-height:1.9;margin-top:0">
      پس از نصب اولیه یا آپدیت دستی بستهٔ سامانه، جهت حفظ امنیت حداکثری و جلوگیری از دسترسی غیرمجاز، فایل‌های داخل پوشهٔ <code>install/</code> باید پاک‌سازی شوند.
    </p>
    <?php if ($hasInstallDir && $installFilesCnt > 0): ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cleanup_install">
        <button class="fr-btn sm" style="background:#dc2626" type="submit" onclick="return confirm('آیا از حذف کامل فایل‌های پوشه نصب اطمینان دارید؟')">
          🗑️ پاک‌سازی فوری پوشه و فایل‌های install
        </button>
      </form>
    <?php else: ?>
      <div style="font-size:12.5px;color:#16a34a;font-weight:700">
        هیچ فایل حساسی در پوشه نصب وجود ندارد و امنیت سامانه برقرار است.
      </div>
    <?php endif; ?>
  </div>

  <div class="fr-card">
    <h3 style="margin:0 0 12px;font-size:16px">⚙️ ابزارهای نگهداری و بذر مجدد دیتابیس (Reseed)</h3>
    <p style="font-size:13px;color:var(--muted);line-height:1.9;margin-top:0">
      اگر پس از آپلود دستی فایل‌ها متوجه شدید تغییری در ساختار جداول ایجاد نشده یا می‌خواهید تمامی تست‌های روان‌سنجی، قالب‌های بالینی، برگه‌ها و منوها به حالت کامل و استاندارد به‌روزرسانی شوند:
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rerun_pipeline">
        <button class="fr-btn ghost sm" type="submit">
          🔄 اجرای پایپ‌لاین مهاجرت دیتابیس (fr_update_pipeline)
        </button>
      </form>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run_reseed">
        <button class="fr-btn sm primary" type="submit" onclick="return confirm('آیا از بذر مجدد و همگام‌سازی کامل پایگاه داده اطمینان دارید؟')">
          🌱 اجرای کامل بذر محتوا و تست‌ها (Reseed All)
        </button>
      </form>
    </div>
  </div>
</div>
<?php admin_layout_end(); ?>
