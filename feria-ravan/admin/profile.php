<?php
/**
 * =============================================================================
 *  پروفایل مدیر (نسخهٔ ۱٫۳) — ویرایش کامل حساب مدیر سایت
 *  ─ نام کاربری، نام نمایشی، موبایل، ایمیل، آواتار
 *  ─ تغییر رمز عبور فقط با ارائهٔ رمز فعلی (امنیت)
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/upload.php';
$adm = require_admin(['superadmin','manager','support']);

/* ردیف کامل مدیر (با ستون‌های جدید — در صورت نبودِ ستون graceful می‌شود) */
try   { $me = DB::one('SELECT * FROM ' . DB::t('admins') . ' WHERE id=?', [$adm['id']]) ?: $adm; }
catch (Throwable $e) { $me = $adm; }

$errors = []; $okMsg = '';
$roleFa = ['superadmin' => 'مدیر ارشد', 'manager' => 'مدیر', 'support' => 'پشتیبان'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');

    /* ── ۱) ویرایش اطلاعات پایه ── */
    if ($act === 'save') {
        $data = [
            'username'     => trim((string)post('username')),
            'display_name' => trim((string)post('display_name')) ?: null,
            'mobile'       => en_num(trim((string)post('mobile'))) ?: null,
            'email'        => trim((string)post('email')) ?: null,
        ];
        if ($data['username'] === '' || !preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $data['username']))
            $errors['username'] = 'نام کاربری باید بین ۳ تا ۶۰ نویسهٔ لاتین/عدد باشد.';
        elseif (($dup = DB::val('SELECT id FROM ' . DB::t('admins') . ' WHERE username=? AND id!=?', [$data['username'], $adm['id']])))
            $errors['username'] = 'این نام کاربری برای مدیر دیگری ثبت شده است.';
        if ($data['mobile'] && !valid_mobile($data['mobile']))
            $errors['mobile'] = 'شماره موبایل معتبر نیست.';
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
            $errors['email'] = 'ایمیل معتبر نیست.';
        if (!$errors) {
            DB::update('admins', $data, 'id=?', [$adm['id']]);
            log_activity('admin_profile_update', 'admin', (int)$adm['id']);
            $okMsg = 'پروفایل مدیر به‌روزرسانی شد ✅';
            $me = array_merge($me, $data);
        }
    }

    /* ── ۲) آواتار ── */
    if ($act === 'avatar') {
        $r = fr_save_avatar($_FILES['avatar'] ?? [], 'admin', (int)$adm['id']);
        if (!$r['ok']) $errors['avatar'] = $r['error'];
        else {
            fr_delete_avatar($me['avatar'] ?? null);
            DB::update('admins', ['avatar' => $r['path']], 'id=?', [$adm['id']]);
            $me['avatar'] = $r['path'];
            log_activity('admin_avatar_update', 'admin', (int)$adm['id']);
            $okMsg = 'تصویر پروفایل به‌روزرسانی شد 📷';
        }
    }
    if ($act === 'avatar_remove') {
        fr_delete_avatar($me['avatar'] ?? null);
        DB::update('admins', ['avatar' => null], 'id=?', [$adm['id']]);
        $me['avatar'] = null;
        $okMsg = 'تصویر پروفایل حذف شد.';
    }

    /* ── ۳) تغییر رمز عبور (فقط با رمز فعلی) ── */
    if ($act === 'set_password') {
        $cur = (string)post('current_password');
        $p1  = (string)post('password'); $p2 = (string)post('password2');
        $row = DB::one('SELECT password_hash FROM ' . DB::t('admins') . ' WHERE id=?', [$adm['id']]);
        if (!$row || !password_verify($cur, (string)$row['password_hash']))
            $errors['password'] = 'رمز فعلی صحیح نیست.';
        elseif (strlen($p1) < 8) $errors['password'] = 'رمز جدید باید حداقل ۸ کاراکتر باشد.';
        elseif ($p1 !== $p2)     $errors['password'] = 'تکرار رمز مطابقت ندارد.';
        elseif ($p1 === $cur)    $errors['password'] = 'رمز جدید باید با رمز فعلی متفاوت باشد.';
        else {
            DB::update('admins', ['password_hash' => password_hash($p1, PASSWORD_ARGON2ID)], 'id=?', [$adm['id']]);
            log_activity('admin_password_set', 'admin', (int)$adm['id']);
            $okMsg = 'رمز عبور مدیر تغییر کرد؛ از ورود بعدی با رمز جدید وارد شوید.';
        }
    }
}

$avatarUrl = !empty($me['avatar']) ? SITE_URL . '/' . ltrim((string)$me['avatar'], '/') : null;

admin_layout($adm, 'profile', 'پروفایل من — حساب مدیر');
echo flash();
if ($okMsg) echo '<div class="fr-alert ok">' . e($okMsg) . '</div>';
?>
<style>
.pf-ava{width:84px;height:84px;border-radius:50%;object-fit:cover;border:3px solid #e6dfff}
.pf-ava-ph{width:84px;height:84px;border-radius:50%;background:linear-gradient(135deg,#eaf0f8,#dcd2fb);display:flex;align-items:center;justify-content:center;font-size:34px;border:3px solid #e6dfff}
</style>

<!-- کارت هویت مدیر -->
<div class="fr-card">
  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <?php if ($avatarUrl): ?><img class="pf-ava" src="<?= e($avatarUrl) ?>?v=<?= time() ?>" alt="آواتار مدیر">
    <?php else: ?><div class="pf-ava-ph">🛡</div><?php endif; ?>
    <div>
      <b style="font-size:17px"><?= e($me['display_name'] ?? $me['username']) ?></b>
      <span class="fr-chip purple"><?= $roleFa[$me['role']] ?? $me['role'] ?></span><br>
      <small style="color:var(--muted)">
        <?= !empty($me['mobile']) ? '📱 ' . fa_num($me['mobile']) . ' · ' : '' ?>
        <?= !empty($me['email']) ? '✉️ ' . e($me['email']) : '' ?>
      </small>
    </div>
  </div>
</div>

<div class="fr-grid cols-2">
  <!-- فرم اطلاعات مدیر -->
  <div class="fr-card" style="margin:0">
    <h3>ویرایش اطلاعات مدیر</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <div class="fr-field <?= isset($errors['username']) ? 'invalid' : '' ?>">
        <label>نام کاربری (ورود) *</label>
        <input class="fr-input" name="username" value="<?= e($me['username'] ?? '') ?>" style="direction:ltr;text-align:right">
        <?php if (isset($errors['username'])): ?><div class="err" style="display:block"><?= e($errors['username']) ?></div><?php endif; ?>
      </div>
      <div class="fr-field"><label>نام نمایشی (در هدر پنل)</label>
        <input class="fr-input" name="display_name" value="<?= e($me['display_name'] ?? '') ?>"></div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field <?= isset($errors['mobile']) ? 'invalid' : '' ?>"><label>موبایل</label>
          <input class="fr-input" name="mobile" value="<?= fa_num($me['mobile'] ?? '') ?>" style="direction:ltr;text-align:right" inputmode="numeric" placeholder="09xxxxxxxxx">
          <?php if (isset($errors['mobile'])): ?><div class="err" style="display:block"><?= e($errors['mobile']) ?></div><?php endif; ?>
        </div>
        <div class="fr-field <?= isset($errors['email']) ? 'invalid' : '' ?>"><label>ایمیل</label>
          <input class="fr-input" name="email" value="<?= e($me['email'] ?? '') ?>" style="direction:ltr;text-align:right" placeholder="admin@feria.ir">
          <?php if (isset($errors['email'])): ?><div class="err" style="display:block"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>
      </div>
      <button class="fr-btn">ذخیره اطلاعات مدیر</button>
    </form>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <!-- آواتار مدیر -->
    <div class="fr-card" style="margin:0">
      <h3>تصویر پروفایل مدیر</h3>
      <div style="display:flex;gap:14px;align-items:center">
        <?php if ($avatarUrl): ?><img class="pf-ava" src="<?= e($avatarUrl) ?>?v=<?= time() ?>" alt="">
        <?php else: ?><div class="pf-ava-ph">🛡</div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" style="flex:1">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="avatar">
          <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" style="font-size:12px">
          <div style="display:flex;gap:8px;margin-top:8px">
            <button class="fr-btn sm">آپلود تصویر</button>
            <?php if ($avatarUrl): ?>
            <button class="fr-btn ghost sm danger" name="act" value="avatar_remove" formnovalidate
                    onclick="return confirm('حذف تصویر پروفایل؟')">حذف</button>
            <?php endif; ?>
          </div>
          <small style="display:block;color:var(--muted);margin-top:6px">JPG / PNG / WEBP — حداکثر ۲ مگابایت</small>
          <?php if (isset($errors['avatar'])): ?><div class="err" style="display:block"><?= e($errors['avatar']) ?></div><?php endif; ?>
        </form>
      </div>
    </div>

    <!-- تغییر رمز عبور مدیر -->
    <div class="fr-card" style="margin:0">
      <h3>تغییر رمز عبور</h3>
      <?php if (isset($errors['password'])) echo '<div class="fr-alert err">' . e($errors['password']) . '</div>'; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="set_password">
        <div class="fr-field"><label>رمز فعلی *</label>
          <input class="fr-input" type="password" name="current_password" style="direction:ltr" required></div>
        <div class="fr-field"><label>رمز جدید (حداقل ۸ کاراکتر)</label>
          <input class="fr-input" type="password" name="password" style="direction:ltr"></div>
        <div class="fr-field"><label>تکرار رمز جدید</label>
          <input class="fr-input" type="password" name="password2" style="direction:ltr"></div>
        <button class="fr-btn">تغییر رمز</button>
      </form>
    </div>
  </div>
</div>
<?php admin_layout_end(); ?>
