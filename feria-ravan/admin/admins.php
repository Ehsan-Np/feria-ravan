<?php
/**
 * مدیریت حساب‌های مدیر (نسخهٔ ۲٫۱)
 * ─ فهرست مدیران + ساخت مدیر جدید + اتصال نقش سفارشی (role_id)
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/roles.php';
$adm = require_admin(['superadmin']);
csrf_guard();

$act = (string)($_POST['act'] ?? '');

if ($act === 'add') {
    $uname = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 60);
    $name  = mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 120);
    $pass  = (string)($_POST['password'] ?? '');
    $rid   = (int)($_POST['role_id'] ?? 0);
    if (!preg_match('/^[a-zA-Z0-9_.\-]{3,60}$/', $uname)) { flash('نام کاربری معتبر نیست (انگلیسی، حداقل ۳ کاراکتر).', 'err'); redirect(SITE_URL . '/admin/admins.php'); }
    if (mb_strlen($pass) < 8) { flash('رمز باید حداقل ۸ کاراکتر باشد.', 'err'); redirect(SITE_URL . '/admin/admins.php'); }
    $dup = DB::val("SELECT id FROM " . DB::t('admins') . " WHERE username=?", [$uname]);
    if ($dup) { flash('این نام کاربری تکراری است.', 'err'); redirect(SITE_URL . '/admin/admins.php'); }
    if ($rid > 0 && !DB::val("SELECT id FROM " . DB::t('admin_roles') . " WHERE id=?", [$rid])) $rid = 0;
    DB::run("INSERT INTO " . DB::t('admins') . " (username,password_hash,display_name,mobile,email,avatar,role,role_id,status)
             VALUES (?,?,?,NULL,NULL,NULL,'support',?, 'active')",
            [$uname, password_hash($pass, PASSWORD_ARGON2ID), $name !== '' ? $name : $uname, $rid ?: null]);
    log_activity('admin_add_admin', 'admin', (int)$adm['id'], ['user' => $uname]);
    flash('مدیر جدید ساخته شد ✅');
    redirect(SITE_URL . '/admin/admins.php');
}

if ($act === 'setrole') {
    $id  = (int)($_POST['id'] ?? 0);
    $rid = (int)($_POST['role_id'] ?? 0);
    $legacy = (string)($_POST['legacy_role'] ?? '');
    if (!in_array($legacy, ['superadmin','manager','support'], true)) $legacy = 'support';
    if ($id === (int)$adm['id'] && $legacy !== 'superadmin') { flash('نمی‌توانید نقش خودتان را از مدیر ارشد خارج کنید.', 'err'); redirect(SITE_URL . '/admin/admins.php'); }
    if ($rid > 0 && !DB::val("SELECT id FROM " . DB::t('admin_roles') . " WHERE id=?", [$rid])) $rid = 0;
    DB::run("UPDATE " . DB::t('admins') . " SET role=?, role_id=? WHERE id=?", [$legacy, $rid ?: null, $id]);
    log_activity('admin_set_role', 'admin', (int)$adm['id'], ['admin' => $id, 'role_id' => $rid]);
    flash('نقش مدیر به‌روزرسانی شد ✅');
    redirect(SITE_URL . '/admin/admins.php');
}

if ($act === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id === (int)$adm['id']) { flash('نمی‌توانید حساب خودتان را غیرفعال کنید.', 'err'); redirect(SITE_URL . '/admin/admins.php'); }
    /* ۲٫۲: enum فقط active|blocked دارد — مقدار disabled ذخیره نمی‌شد (رفع نقص) */
    DB::run("UPDATE " . DB::t('admins') . " SET status = IF(status='active','blocked','active') WHERE id=?", [$id]);
    flash('وضعیت حساب تغییر کرد.');
    redirect(SITE_URL . '/admin/admins.php');
}

if ($act === 'resetpass') {
    $id   = (int)($_POST['id'] ?? 0);
    $pass = (string)($_POST['password'] ?? '');
    if (mb_strlen($pass) < 8) { flash('رمز جدید باید حداقل ۸ کاراکتر باشد.', 'err'); redirect(SITE_URL . '/admin/admins.php'); }
    DB::run("UPDATE " . DB::t('admins') . " SET password_hash=? WHERE id=?", [password_hash($pass, PASSWORD_ARGON2ID), $id]);
    log_activity('admin_reset_pass', 'admin', (int)$adm['id'], ['admin' => $id]);
    flash('رمز مدیر بازنشانی شد ✅');
    redirect(SITE_URL . '/admin/admins.php');
}

$roles   = DB::all("SELECT id,title FROM " . DB::t('admin_roles') . " ORDER BY is_system DESC, id");
$roleMap = array_column($roles, 'title', 'id');
/* تبدیل کاربر سایت به مدیر (لینک از فهرست کاربران) */
$fromUser = null;
if (isset($_GET['from_user'])) {
    $fromUser = DB::one("SELECT id,first_name,last_name,mobile FROM " . DB::t('users') . " WHERE id=?", [(int)$_GET['from_user']]);
}
$legacyTitles = ['superadmin' => 'مدیر ارشد', 'manager' => 'مدیر اجرایی', 'support' => 'پشتیبان'];
$admins  = DB::all("SELECT * FROM " . DB::t('admins') . " ORDER BY id");

admin_layout($adm, 'roles', 'حساب‌های مدیر');
echo flash();
?>
<div class="fr-card">
  <h3>مدیران سایت</h3>
  <table class="fr-table">
    <thead><tr><th>نام کاربری</th><th>نام نمایشی</th><th>نقش قدیمی</th><th>نقش سفارشی (۲٫۱)</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($admins as $a): ?>
    <tr>
      <td><code><?= e($a['username']) ?></code></td>
      <td><?= e($a['display_name'] ?? '—') ?></td>
      <td>
        <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?><input type="hidden" name="act" value="setrole"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <select class="fr-select" name="legacy_role" style="min-width:110px">
            <?php foreach ($legacyTitles as $k => $v): ?><option value="<?= $k ?>" <?= $a['role'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
          </select>
          <select class="fr-select" name="role_id" style="min-width:150px">
            <option value="0">— پیش‌فرض (همان نقش قدیمی) —</option>
            <?php foreach ($roleMap as $rid => $rt): ?><option value="<?= (int)$rid ?>" <?= (int)($a['role_id'] ?? 0) === (int)$rid ? 'selected' : '' ?>><?= e($rt) ?></option><?php endforeach; ?>
          </select>
          <button class="fr-btn sm">ذخیره</button>
        </form>
      </td>
      <td><?= !empty($a['role_id']) ? '<span class="chip" style="background:#eef2ff">' . e($roleMap[(int)$a['role_id']] ?? 'نقش سفارشی') . '</span>' : '<span style="color:var(--muted);font-size:12px">—</span>' ?></td>
      <td><?= $a['status'] === 'active' ? '<span style="color:var(--c-ok)">فعال</span>' : '<span style="color:var(--c-danger)">غیرفعال</span>' ?></td>
      <td style="font-size:12px"><?= !empty($a['last_login_at']) ? e(jdate($a['last_login_at'])) : '—' ?></td>
      <td style="white-space:nowrap">
        <form method="post" style="display:inline-flex;gap:6px;align-items:center">
          <?= csrf_field() ?><input type="hidden" name="act" value="resetpass"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <input class="fr-input sm" name="password" placeholder="رمز جدید" style="width:110px" minlength="8">
          <button class="fr-btn sm ghost" onclick="return confirm('رمز بازنشانی شود؟')">بازنشانی رمز</button>
        </form>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="fr-btn sm <?= $a['status']==='active'?'danger':'' ?>" onclick="return confirm('وضعیت این حساب تغییر کند؟')"><?= $a['status']==='active'?'غیرفعال':'فعال‌سازی' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <small class="hint" style="display:block;margin-top:8px">وقتی «نقش سفارشی» انتخاب شود، دسترسی مدیر فقط طبق همان نقش کنترل می‌شود (حتی اگر نقش قدیمی‌اش متفاوت باشد). سطح دسترسی هر نقش در <a href="<?= SITE_URL ?>/admin/roles.php">صفحهٔ نقش‌ها</a> تعریف می‌شود.</small>
</div>

<div class="fr-card" style="border:1.5px solid #d4dcec;background:linear-gradient(180deg,#fafbfd,#fff)">
  <h3><?= $fromUser ? '🛂 مدیر کردن کاربر: ' . e(trim(($fromUser['first_name'] ?? '') . ' ' . ($fromUser['last_name'] ?? '')) ?: $fromUser['mobile']) : 'افزودن مدیر جدید' ?></h3>
  <?php if ($fromUser): ?>
  <p style="font-size:12.5px;color:var(--muted);margin-top:0">برای این کاربر یک حساب مدیر جدا با رمز دلخواه می‌سازی؛ سپس از جدول بالا نقش سفارشی‌اش را تعیین کن. حساب کاربری‌اش در سایت کاملاً جدا می‌ماند.</p>
  <?php endif; ?>
  <form method="post" class="fr-grid cols-2" style="align-items:end">
    <?= csrf_field() ?><input type="hidden" name="act" value="add">
    <div class="fr-field"><label>نام کاربری (انگلیسی)</label><input class="fr-input" name="username" required style="direction:ltr" maxlength="60" placeholder="<?= $fromUser ? 'مثلاً ' . e($fromUser['mobile']) : '' ?>"></div>
    <div class="fr-field"><label>نام نمایشی</label><input class="fr-input" name="display_name" maxlength="120" value="<?= $fromUser ? e(trim(($fromUser['first_name'] ?? '') . ' ' . ($fromUser['last_name'] ?? ''))) : '' ?>"></div>
    <div class="fr-field"><label>رمز عبور (حداقل ۸ کاراکتر)</label><input class="fr-input" type="password" name="password" required minlength="8"></div>
    <div class="fr-field"><label>نقش</label>
      <select class="fr-select" name="role_id">
        <option value="0">پشتیبان (پیش‌فرض قدیمی)</option>
        <?php foreach ($roleMap as $rid => $rt): ?><option value="<?= (int)$rid ?>"><?= e($rt) ?></option><?php endforeach; ?>
      </select></div>
    <div><button class="fr-btn">ساخت مدیر ✅</button></div>
  </form>
</div>
<?php admin_layout_end(); ?>
