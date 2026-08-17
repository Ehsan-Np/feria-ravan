<?php
/** صفحهٔ ورود مدیریت */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';

if (current_admin()) redirect(SITE_URL . '/admin/dashboard.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = admin_login(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''));
    if ($r['ok']) redirect(SITE_URL . '/admin/dashboard.php');
    $err = $r['message'];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>ورود مدیریت | فریا روان</title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/feria.css?v=<?= FR_VERSION ?>">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(160deg,#f4f6fa,#e7e1ff)">
  <form method="post" class="fr-card" style="max-width:400px;width:100%">
    <?= csrf_field() ?>
    <div style="text-align:center;margin-bottom:22px">
      <span style="font-size:38px">⚙️</span>
      <h3 style="justify-content:center">پیشخوان مدیریت</h3>
      <p style="color:var(--muted);font-size:13px;margin:0">ورود محدود به مدیران مجاز است</p>
    </div>
    <?php if ($err) echo '<div class="fr-alert err">'.e($err).'</div>'; ?>
    <div class="fr-field"><label>نام کاربری</label>
      <input class="fr-input" name="username" required autocomplete="username"></div>
    <div class="fr-field"><label>رمز عبور</label>
      <input class="fr-input" type="password" name="password" required autocomplete="current-password"></div>
    <button class="fr-btn" style="width:100%;justify-content:center">ورود به پیشخوان ←</button>
  </form>
</body>
</html>
