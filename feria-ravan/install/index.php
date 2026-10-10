<?php
/**
 * ============================================================================
 *  فریا روان — نصب‌کننده مرحله‌ای (به سبک وردپرس)
 *  مراحل: خوش‌آمد → بررسی پیش‌نیازها → اطلاعات دیتابیس → نصب جداول
 *         → تنظیمات سایت و مدیر → پایان
 * ============================================================================
 */
declare(strict_types=1);
session_start();

define('FR_ROOT', dirname(__DIR__));
define('FR_INSTALL_LOCK', FR_ROOT . '/storage/installed.lock');

/* اگر قبلاً نصب شده، ورود به نصب‌کننده مسدود است */
$installed = file_exists(FR_INSTALL_LOCK) && file_exists(FR_ROOT . '/config.php');

$step = (int)($_GET['step'] ?? 0);
if ($installed && $step !== 6) { $step = 6; } // فقط صفحه «نصب‌شده»

$errors  = [];
$success = [];

/* ---------------------------------------------------------------------------
 | توابع کمکی نصب
 * -------------------------------------------------------------------------*/
/** بررسی افزونه‌های موردنیاز PHP */
function fr_requirements(): array
{
    return [
        ['نسخه PHP بالای 8.0',        PHP_VERSION_ID >= 80000, PHP_VERSION],
        ['افزونه PDO MySQL',          extension_loaded('pdo_mysql'), 'pdo_mysql'],
        ['افزونه cURL (زرین‌پال/پیامک)', extension_loaded('curl'), 'curl'],
        ['افزونه mbstring',           extension_loaded('mbstring'), 'mbstring'],
        ['افزونه JSON',               extension_loaded('json'), 'json'],
        ['افزونه GD (بارکد/تصویر)',    extension_loaded('gd'), 'gd'],
        ['قابل نوشتن: /storage',      is_writable(FR_ROOT . '/storage'), 'chmod 755'],
        ['قابل نوشتن: ریشه (config.php)', is_writable(FR_ROOT), 'chmod 755'],
    ];
}

/** اجرای چنددستوری SQL روی دیتابیس (با جایگذاری پیشوند جداول) */
function fr_run_sql(PDO $pdo, string $sql, string $prefix): void
{
    $sql = str_replace('fr_', $prefix, $sql);
    // حذف خطوط کامنت SQL پیش از تفکیک دستورها
    $sql = preg_replace('/^[ \t]*--[^\r\n]*$/m', '', $sql);
    // تفکیک دستورها بر اساس سمی‌کالن انتهای خط
    foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql))) as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

/** تولید فایل config.php */
function fr_write_config(array $c): bool
{
    $tpl = file_get_contents(FR_ROOT . '/includes/config.sample.php');
    $out = strtr($tpl, [
        '{{DB_HOST}}'     => addslashes($c['db_host']),
        '{{DB_NAME}}'     => addslashes($c['db_name']),
        '{{DB_USER}}'     => addslashes($c['db_user']),
        '{{DB_PASS}}'     => addslashes($c['db_pass']),
        '{{DB_PREFIX}}'   => addslashes($c['db_prefix']),
        '{{SITE_URL}}'    => addslashes(rtrim($c['site_url'], '/')),
        '{{ENCRYPT_KEY}}' => bin2hex(random_bytes(32)),
    ]);
    return file_put_contents(FR_ROOT . '/config.php', $out, LOCK_EX) !== false;
}

/* ---------------------------------------------------------------------------
 | پردازش مراحل (POST)
 * -------------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {

    /* مرحله ۲ → ۳: اتصال به دیتابیس و نصب جداول */
    if ($step === 2) {
        $_SESSION['db'] = [
            'db_host'   => trim($_POST['db_host'] ?? 'localhost'),
            'db_name'   => trim($_POST['db_name'] ?? ''),
            'db_user'   => trim($_POST['db_user'] ?? ''),
            'db_pass'   => (string)($_POST['db_pass'] ?? ''),
            'db_prefix' => preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['db_prefix'] ?? 'fr_'),
            'site_url'  => rtrim(trim($_POST['site_url'] ?? ''), '/'),
        ];
        $d = $_SESSION['db'];
        if ($d['db_name'] === '' || $d['db_user'] === '') {
            $errors[] = 'نام دیتابیس و نام کاربری الزامی است.';
        } else {
            try {
                $pdo = new PDO(
                    "mysql:host={$d['db_host']};dbname={$d['db_name']};charset=utf8mb4",
                    $d['db_user'], $d['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                /* پاک‌سازی خودکار: همهٔ جداول قدیمی با همین پیشوند حذف می‌شوند تا نصب کاملاً تازه انجام شود
                   (درخواست صریح مالک سایت — دیتابیس باید هنگام نصب ریست شود) */
                $wiped = 0;
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($pdo->query('SHOW TABLES LIKE ' . $pdo->quote($d['db_prefix'] . '%'))->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $t) . '`');
                    $wiped++;
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                if ($wiped > 0) $_SESSION['db_wiped'] = $wiped;

                // نصب اسکیما + داده‌های اولیه
                fr_run_sql($pdo, file_get_contents(__DIR__ . '/schema.sql'), $d['db_prefix']);
                fr_run_sql($pdo, file_get_contents(__DIR__ . '/seed.sql'),   $d['db_prefix']);
                $_SESSION['db_ok'] = true;
                $step = 4;
            } catch (Throwable $e) {
                $errors[] = 'خطا در اتصال به دیتابیس: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    /* مرحله ۴ → ۵: ساخت مدیر و تنظیمات سایت، نوشتن config و قفل نصب */
    if ($step === 4 && !empty($_SESSION['db_ok'])) {
        $siteTitle = trim($_POST['site_title'] ?? 'فریا روان');
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        $adminName = trim($_POST['admin_name'] ?? 'مدیر سایت');
        $adminMob  = preg_replace('/\D/', '', $_POST['admin_mobile'] ?? '');

        if ($adminUser === '' || strlen($adminPass) < 8) {
            $errors[] = 'نام کاربری مدیر الزامی و رمز عبور باید حداقل ۸ کاراکتر باشد.';
        } else {
            $d = $_SESSION['db'];
            $d['site_url'] = $d['site_url'] ?: ((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/install'));
            try {
                $pdo = new PDO(
                    "mysql:host={$d['db_host']};dbname={$d['db_name']};charset=utf8mb4",
                    $d['db_user'], $d['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $p = $d['db_prefix'];
                // ایجاد مدیر ارشد
                $hash = password_hash($adminPass, PASSWORD_ARGON2ID);
                $stmt = $pdo->prepare("INSERT INTO `{$p}admins`(username,password,password_hash,display_name,mobile,role,status) VALUES(?,?,?,?,?,'superadmin','active')");
                $stmt->execute([$adminUser, $hash, $hash, $adminName, $adminMob]);
                // حساب کاربری مدیر هم از ابتدا ساخته می‌شود تا «پنل کاربری من» برایش فعال باشد (ورود به پیشخوان فقط از /admin و با احراز هویت مدیر)
                if (preg_match('/^09\d{9}$/', $adminMob)) {
                    $parts = preg_split('/\s+/u', $adminName);
                    $fn = $parts[0] ?: 'مدیر';
                    $ln = trim(implode(' ', array_slice($parts, 1))) ?: 'سایت';
                    $pdo->prepare("INSERT INTO `{$p}users`(mobile,first_name,last_name,password_hash,mobile_verified)
                                   VALUES(?,?,?,?,1)
                                   ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)")
                        ->execute([$adminMob, $fn, $ln, $hash]);
                }
                // ذخیره عنوان سایت
                $pdo->prepare("UPDATE `{$p}settings` SET `value`=? WHERE `key`='site_title'")->execute([$siteTitle]);
                // نوشتن config و قفل نصب
                if (!fr_write_config($d)) {
                    $errors[] = 'امکان نوشتن config.php نیست؛ دسترسی پوشه را بررسی کنید.';
                } else {
                    file_put_contents(FR_INSTALL_LOCK, date('c') . '|' . $d['site_url'], LOCK_EX);
                    // بذر بانک سؤالات و قالب‌های تحلیل (یک‌بار)
                    try {
                        require_once FR_ROOT . '/config.php';
                        require_once FR_ROOT . '/includes/db.php';
                        require_once FR_ROOT . '/core/seed-content.php';
                        fr_seed_content();
                    } catch (Throwable $ignored) {}
                    // نسخهٔ ۱٫۷: بازتولید نخستین لینک‌های زیبای .htaccess + robots.txt
                    try {
                        require_once FR_ROOT . '/includes/seo.php';
                        fr_permalinks_flush();
                    } catch (Throwable $ignored) {}
                    session_destroy();
                    $step = 5;
                }
            } catch (Throwable $e) {
                $errors[] = 'خطا در ساخت حساب مدیر: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

$reqs = fr_requirements();
$allOk = array_reduce($reqs, fn($c, $r) => $c && $r[1], true);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نصب فریا روان | Feria Ravan</title>
<style>
/* --- استایل سبک و مستقل نصب‌کننده (قلب سبک‌ترین حالت ممکن) --- */
*{box-sizing:border-box;margin:0;padding:0}
@font-face{font-family:Vazirmatn;src:local('Vazirmatn')}
body{font-family:Vazirmatn,Tahoma,sans-serif;background:#f4f6fa;color:#1c2740;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:32px 12px}
.box{background:#fff;border-radius:20px;box-shadow:0 8px 40px rgba(30,58,110,.10);max-width:640px;width:100%;padding:36px 30px;border:1px solid #e8ecf4}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:22px}
.logo .mark{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#6d4ae0,#3e5f9e);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px}
.logo h1{font-size:20px;color:#3b2b86}
.logo small{color:#8a84ab;font-size:12px;display:block}
h2{font-size:17px;margin:18px 0 14px;color:#3b2b86}
p.lead{color:#655e8f;font-size:14px;line-height:2}
.btn{display:inline-block;background:#1e3a6e;color:#fff;border:none;border-radius:12px;padding:12px 26px;font-size:15px;font-family:inherit;cursor:pointer;text-decoration:none;margin-top:20px;transition:.2s}
.btn:hover{background:#122850}
.btn.ghost{background:#eaf0f8;color:#1e3a6e}
table{width:100%;border-collapse:collapse;font-size:13.5px}
td{padding:9px 6px;border-bottom:1px solid #f1effc}
.ok{color:#19a974;font-weight:700}.bad{color:#e0393f;font-weight:700}
label{display:block;font-size:13px;color:#655e8f;margin:14px 0 6px}
input[type=text],input[type=password]{width:100%;border:1.5px solid #e2e9f5;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;background:#fafbfd}
input:focus{outline:none;border-color:#1e3a6e}
.alert{background:#fdecec;color:#b0262c;border-radius:10px;padding:12px 16px;font-size:13.5px;margin:12px 0;line-height:1.9}
.steps{display:flex;gap:6px;margin:0 0 22px}
.steps i{flex:1;height:5px;border-radius:3px;background:#e9e6fb}
.steps i.on{background:#1e3a6e}
.done{text-align:center;padding:30px 0}
.done .big{font-size:52px}
.foot{margin-top:26px;font-size:11.5px;color:#a29cc4;text-align:center}
</style>
</head>
<body>
<div class="box">
  <div class="logo">
    <span class="mark">🧠</span>
    <div><h1>فریا روان <small>Feria Ravan — نصب‌کننده</small></h1></div>
  </div>

  <div class="steps">
    <?php for ($i=0;$i<5;$i++) echo '<i class="'.($i <= min($step,5)-0 && $i < $step || $step===5 ? "on" : "").'"></i>'; ?>
  </div>

  <?php foreach ($errors as $e) echo '<div class="alert">'.$e.'</div>'; ?>

  <?php switch ($step):

  /* ----------------------------------- خوش‌آمد */
  case 0: ?>
    <h2>به نصب‌کننده فریا روان خوش آمدید 👋</h2>
    <p class="lead">
      فریا روان، سامانه جامع تست‌های روانشناسی، کارگاه، دوره و رزرو مشاوره است.
      این نصب‌کننده در چند گام ساده، دیتابیس و تنظیمات را آماده می‌کند — درست مثل وردپرس.
      <br>قبل از شروع، اطلاعات دیتابیس MySQL میزبان خود را آماده کنید.
    </p>
    <a class="btn" href="?step=1">شروع نصب ←</a>
  <?php break;

  /* ----------------------------------- پیش‌نیازها */
  case 1: ?>
    <h2>گام ۱: بررسی پیش‌نیازهای سرور</h2>
    <table>
      <?php foreach ($reqs as [$name,$ok,$hint]): ?>
      <tr><td><?= $name ?></td>
          <td><?= $ok ? '<span class="ok">✔</span>' : '<span class="bad">✘ ('.$hint.')</span>' ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if ($allOk): ?>
      <a class="btn" href="?step=2">ادامه ←</a>
    <?php else: ?>
      <a class="btn ghost" href="?step=1">بازبینی مجدد</a>
    <?php endif; ?>
  <?php break;

  /* ----------------------------------- دیتابیس */
  case 2: ?>
    <h2>گام ۲: اطلاعات دیتابیس MySQL</h2>
    <p class="lead">این اطلاعات را از کنترل‌پنل هاست (cPanel/DirectAdmin) دریافت کنید.</p>
    <div class="alert" style="background:#fff6e6;color:#9a6200">
      ⚠️ توجه: اگر از قبل جداولی با همین پیشوند در دیتابیس وجود داشته باشد،
      <b>به‌صورت خودکار پاک شده</b> و نصب کاملاً تازه انجام می‌شود. از دیتابیس درست مطمئن شوید.
    </div>
    <form method="post" action="?step=2">
      <label>میزبان دیتابیس</label>
      <input type="text" name="db_host" value="<?= htmlspecialchars($_SESSION['db']['db_host'] ?? 'localhost') ?>" required>
      <label>نام دیتابیس</label>
      <input type="text" name="db_name" value="<?= htmlspecialchars($_SESSION['db']['db_name'] ?? '') ?>" required>
      <label>نام کاربری دیتابیس</label>
      <input type="text" name="db_user" value="<?= htmlspecialchars($_SESSION['db']['db_user'] ?? '') ?>" required>
      <label>رمز عبور دیتابیس</label>
      <input type="password" name="db_pass">
      <label>پیشوند جداول (برای امنیت بهتر تغییر دهید)</label>
      <input type="text" name="db_prefix" value="<?= htmlspecialchars($_SESSION['db']['db_prefix'] ?? 'fr_') ?>">
      <label>آدرس سایت (بدون / انتها)</label>
      <input type="text" name="site_url" value="<?= htmlspecialchars($_SESSION['db']['site_url'] ?? '') ?>" placeholder="https://example.com">
      <button class="btn">نصب جداول دیتابیس ←</button>
    </form>
  <?php break;

  /* ----------------------------------- سایت و مدیر */
  case 4: ?>
    <h2>گام ۳: تنظیمات سایت و حساب مدیر</h2>
    <p class="lead">جداول با موفقیت نصب شدند ✅<?= !empty($_SESSION['db_wiped']) ? ' (' . (int)$_SESSION['db_wiped'] . ' جدول قدیمی پاک و بازسازی شد)' : '' ?> — حالا حساب مدیر ارشد را بسازید.</p>
    <p class="lead" style="font-size:13px">💡 با موبایل مدیر، یک حساب کاربری هم ساخته می‌شود؛ پس از نصب می‌توانید در صفحهٔ ورود سایت با <b>همین موبایل (پیامک) یا همان رمزِ خودتان</b> وارد <b>پنل کاربری</b> شوید. ورود به <b>پیشخوان مدیریت</b> فقط از صفحهٔ مخصوص مدیر (/admin/) و با نام کاربری و رمز مدیریتی انجام می‌شود.</p>
    <form method="post" action="?step=4">
      <label>عنوان سایت</label>
      <input type="text" name="site_title" value="فریا روان" required>
      <label>نام کاربری مدیر</label>
      <input type="text" name="admin_user" required>
      <label>رمز عبور مدیر (حداقل ۸ کاراکتر)</label>
      <input type="password" name="admin_pass" minlength="8" required>
      <label>نام نمایشی مدیر</label>
      <input type="text" name="admin_name" value="مدیر سایت">
      <label>موبایل مدیر (اختیاری)</label>
      <input type="text" name="admin_mobile" placeholder="09xxxxxxxxx">
      <button class="btn">ساخت حساب و پایان نصب ←</button>
    </form>
  <?php break;

  /* ----------------------------------- پایان */
  case 5: ?>
    <div class="done">
      <div class="big">🎉</div>
      <h2>نصب با موفقیت انجام شد!</h2>
      <p class="lead">فایل <code>config.php</code> ساخته شد و نصب قفل گردید.<br>
      برای امنیت، پوشه‌ی <code>/install</code> را از سرور حذف کنید.</p>
      <a class="btn" href="../">ورود به سایت ←</a>
      <a class="btn ghost" href="../admin/">ورود به پیشخوان مدیریت</a>
    </div>
  <?php break;

  /* ----------------------------------- قبلاً نصب شده */
  case 6: default: ?>
    <h2>فریا روان قبلاً نصب شده است 🔒</h2>
    <p class="lead">برای نصب مجدد، فایل‌های <code>config.php</code> و <code>/storage/installed.lock</code> را حذف کنید.</p>
    <a class="btn" href="../">نمایش سایت</a>
  <?php break; endswitch; ?>

  <div class="foot">فریا روان — Feria Ravan • نسخهٔ ۱٫۷</div>
</div>
</body>
</html>
