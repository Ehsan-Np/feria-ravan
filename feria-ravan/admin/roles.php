<?php
/**
 * مدیریت نقش‌ها و سطح دسترسی (نسخهٔ ۲٫۱)
 * ─ ساخت/ویرایش/حذف نقش با ماتریس cap + مشاهدهٔ مدیرانِ هر نقش
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/roles.php';
$adm = require_admin(['superadmin']);
csrf_guard();

$act = (string)($_POST['act'] ?? '');

if ($act === 'save') {
    $id    = (int)($_POST['id'] ?? 0);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120);
    $caps  = array_values(array_intersect((array)($_POST['caps'] ?? []), array_keys(fr_caps_catalog())));
    if ($title === '') { flash('عنوان نقش را بنویسید.', 'err'); redirect(SITE_URL . '/admin/roles.php'); }
    $json = json_encode($caps, JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        DB::run("UPDATE " . DB::t('admin_roles') . " SET title=?, caps=? WHERE id=?", [$title, $json, $id]);
        flash('نقش به‌روزرسانی شد ✅');
    } else {
        DB::run("INSERT INTO " . DB::t('admin_roles') . " (title, caps, is_system, created_at) VALUES (?,?,0,NOW())", [$title, $json]);
        flash('نقش جدید ساخته شد ✅');
    }
    log_activity('admin_role_save', 'admin', (int)$adm['id'], ['title' => $title]);
    redirect(SITE_URL . '/admin/roles.php');
}

if ($act === 'del') {
    $id = (int)($_POST['id'] ?? 0);
    $inUse = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('admins') . " WHERE role_id=?", [$id]);
    $sys   = (int)DB::val("SELECT is_system FROM " . DB::t('admin_roles') . " WHERE id=?", [$id]);
    if ($inUse > 0)      { flash('این نقش به ' . fa_num($inUse) . ' مدیر متصل است؛ ابتدا نقش آن‌ها را عوض کنید.', 'err'); }
    elseif ($sys === 1)  { flash('نقش سیستمی قابل حذف نیست (می‌توانید ویرایشش کنید).', 'err'); }
    else { DB::run("DELETE FROM " . DB::t('admin_roles') . " WHERE id=?", [$id]); flash('نقش حذف شد.'); }
    redirect(SITE_URL . '/admin/roles.php');
}

/* ────────────────────────────────────────────────────────────────────────────
 * ۲٫۲ — ارتقا/تنزیل مستقیم کاربران سایت به نقش‌های مدیریتی، از همین صفحه
 * -------------------------------------------------------------------------*/
if ($act === 'user_promote') {
    $pick   = trim((string)($_POST['user_pick'] ?? ''));
    // مقدار دیتالیست «موبایل — نام» است؛ موبایل ۱۱ رقمیِ آغازین را استخراج کن
    $mob    = '';
    if (preg_match('/09\\d{9}/', en_num($pick), $mm)) $mob = $mm[0];
    $roleSel = (string)($_POST['role_pick'] ?? '');      // legacy:support | role:{id}
    $pass   = (string)($_POST['password'] ?? '');
    $gen    = '';

    $usr = $mob !== '' ? DB::one("SELECT * FROM " . DB::t('users') . " WHERE mobile=? AND status='active'", [$mob]) : null;
    if (!$usr) { flash('کاربری با این موبایل پیدا نشد؛ از فهرست کاربران سایت انتخاب کنید.', 'err'); redirect(SITE_URL . '/admin/roles.php#promote'); }

    $legacy = 'support'; $rid = 0;
    if (str_starts_with($roleSel, 'legacy:')) { $legacy = substr($roleSel, 7); }
    elseif (str_starts_with($roleSel, 'role:')) { $rid = (int)substr($roleSel, 5); $legacy = 'manager'; }
    if (!in_array($legacy, ['superadmin','manager','support'], true)) $legacy = 'support';
    if ($rid > 0 && !DB::val("SELECT id FROM " . DB::t('admin_roles') . " WHERE id=?", [$rid])) $rid = 0;

    $fullName = trim((string)$usr['first_name'] . ' ' . (string)$usr['last_name']);
    $existing = DB::one("SELECT * FROM " . DB::t('admins') . " WHERE user_id=?", [(int)$usr['id']]);
    if ($existing) {
        /* کاربر از قبل مدیر است → فقط نقشش به‌روز می‌شود (ارتقا/تنزیل بین نقش‌ها) */
        DB::run("UPDATE " . DB::t('admins') . " SET role=?, role_id=?, status='active' WHERE id=?",
                [$legacy, $rid ?: null, (int)$existing['id']]);
        if (mb_strlen($pass) >= 8) {
            DB::run("UPDATE " . DB::t('admins') . " SET password_hash=? WHERE id=?",
                    [password_hash($pass, PASSWORD_ARGON2ID), (int)$existing['id']]);
        }
        log_activity('admin_promote_update', 'admin', (int)$adm['id'], ['user_id' => (int)$usr['id'], 'role' => $legacy, 'role_id' => $rid]);
        flash('نقش مدیریتی «' . ($fullName !== '' ? $fullName : $usr['mobile']) . '» به‌روزرسانی شد ✅' . (mb_strlen($pass) >= 8 ? ' (رمز هم تغییر کرد)' : ''));
        redirect(SITE_URL . '/admin/roles.php#promote');
    }

    /* ساخت حساب مدیر جدید مرتبط با همین کاربر */
    $base = (string)($usr['username'] ?? '') !== '' ? preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$usr['username']) : $mob;
    if (strlen($base) < 3) $base = 'u' . $mob;
    $uname = $base; $i = 1;
    while (DB::val("SELECT id FROM " . DB::t('admins') . " WHERE username=?", [$uname])) { $uname = mb_substr($base, 0, 54) . ($i++); }
    if ($pass === '') { $gen = 'Adm' . random_int(100000, 999999); $pass = $gen; }
    if (mb_strlen($pass) < 8) { flash('رمز باید حداقل ۸ کاراکتر باشد (یا خالی بگذارید تا خودکار ساخته شود).', 'err'); redirect(SITE_URL . '/admin/roles.php#promote'); }

    DB::run("INSERT INTO " . DB::t('admins') . " (user_id,username,password_hash,display_name,mobile,email,avatar,role,role_id,totp_secret,status)
             VALUES (?,?,?,?,?,NULL,NULL,?,?,NULL,'active')",
            [(int)$usr['id'], $uname, password_hash($pass, PASSWORD_ARGON2ID), $fullName !== '' ? $fullName : $uname, $usr['mobile'], $legacy, $rid ?: null]);
    log_activity('admin_promote_user', 'admin', (int)$adm['id'], ['user_id' => (int)$usr['id'], 'admin_user' => $uname, 'role' => $legacy, 'role_id' => $rid]);
    flash('«' . ($fullName !== '' ? $fullName : $usr['mobile']) . '» به مدریت ارتقاء یافت ✅ — نام کاربری پیشخوان: ' . $uname .
          ($gen !== '' ? ' — رمز نمایشی یک‌باره: ' . $gen : ''), 'ok');
    redirect(SITE_URL . '/admin/roles.php#promote');
}

if ($act === 'demote_admin') {
    $aid = (int)($_POST['id'] ?? 0);
    $row = DB::one("SELECT * FROM " . DB::t('admins') . " WHERE id=?", [$aid]);
    if (!$row) { flash('مدیر پیدا نشد.', 'err'); redirect(SITE_URL . '/admin/roles.php#promote'); }
    if ($aid === (int)$adm['id']) { flash('نمی‌توانید حساب خودتان را تنزیل کنید.', 'err'); redirect(SITE_URL . '/admin/roles.php#promote'); }
    if ($row['role'] === 'superadmin') {
        $others = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('admins') . " WHERE role='superadmin' AND status='active' AND id<>?", [$aid]);
        if ($others === 0) { flash('آخرین مدیر ارشد فعال را نمی‌توان تنزیل کرد.', 'err'); redirect(SITE_URL . '/admin/roles.php#promote'); }
    }
    DB::run("DELETE FROM " . DB::t('admins') . " WHERE id=?", [$aid]);
    log_activity('admin_demote', 'admin', (int)$adm['id'], ['admin_user' => $row['username'], 'linked_user' => (int)($row['user_id'] ?? 0)]);
    flash('«' . (string)$row['username'] . '» به کاربر عادی تنزیل یافت (حساب پیشخوانش حذف شد؛ حساب کاربری‌اش در سایت دست‌نخورده ماند).');
    redirect(SITE_URL . '/admin/roles.php#promote');
}

$roles = DB::all("SELECT r.*, (SELECT COUNT(*) FROM " . DB::t('admins') . " a WHERE a.role_id=r.id) AS admins_count
                  FROM " . DB::t('admin_roles') . " r ORDER BY r.is_system DESC, r.id");
$edit  = null;
if (isset($_GET['edit'])) {
    $edit = DB::one("SELECT * FROM " . DB::t('admin_roles') . " WHERE id=?", [(int)$_GET['edit']]);
}
$editCaps = $edit ? (json_decode((string)$edit['caps'], true) ?: []) : [];

/* داده‌های بخش ارتقا/تنزیل — ۲٫۲ */
$siteUsers = DB::all("SELECT id, first_name, last_name, mobile, username FROM " . DB::t('users') . " WHERE status='active' ORDER BY id DESC LIMIT 2000");
$adminsNow = DB::all("SELECT a.*, r.title AS role_title,
                             CONCAT_WS(' ', u.first_name, u.last_name) AS linked_name, u.mobile AS linked_mobile
                      FROM " . DB::t('admins') . " a
                      LEFT JOIN " . DB::t('admin_roles') . " r ON r.id = a.role_id
                      LEFT JOIN " . DB::t('users') . " u ON u.id = a.user_id
                      ORDER BY a.id");
$legacyTitles = ['superadmin' => 'مدیر ارشد', 'manager' => 'مدیر اجرایی', 'support' => 'پشتیبان'];

admin_layout($adm, 'roles', 'نقش‌ها و سطح دسترسی مدیران');
echo flash();
?>
<?php if ($edit): ?>
<div class="fr-card" style="margin:0 max-width:800px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
    <h3 style="margin:0">✏️ ویرایش نقش: «<?= e($edit['title']) ?>»</h3>
    <a class="fr-btn ghost sm" href="roles.php">← بازگشت به فهرست نقش‌ها</a>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="fr-field">
      <label>عنوان نقش</label>
      <input class="fr-input" name="title" value="<?= e($edit['title']) ?>" required>
    </div>
    <div class="fr-field">
      <label>دسترسی‌ها (Capabilities)</label>
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:8px;max-height:360px;overflow-y:auto;padding:8px;border:1px solid var(--line);border-radius:10px;background:#fbfaff">
        <?php foreach (fr_caps_catalog() as $cap => $cLabel): ?>
        <label class="tog" style="font-size:12.5px">
          <input type="checkbox" name="caps[]" value="<?= e($cap) ?>" <?= in_array($cap, $editCaps, true) ? 'checked' : '' ?>>
          <span><?= e($cLabel) ?> <small style="color:var(--muted)">(<?= e($cap) ?>)</small></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:18px;flex-wrap:wrap">
      <button class="fr-btn sm" type="submit">ذخیره تغییرات نقش</button>
      <a class="fr-btn ghost sm" href="roles.php">انصراف و بازگشت</a>
    </div>
  </form>
</div>
<?php
admin_layout_end();
exit;
endif;
?>

<div class="fr-grid" style="grid-template-columns:1.2fr .8fr;gap:16px;align-items:start">
  <div class="fr-card">
    <h3>نقش‌های تعریف‌شده</h3>
    <table class="fr-table">
      <thead><tr><th>عنوان نقش</th><th>دسترسی‌ها</th><th>مدیران</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($roles as $r):
          $caps = json_decode((string)$r['caps'], true) ?: []; $catalog = fr_caps_catalog(); ?>
      <tr>
        <td><b><?= e($r['title']) ?></b> <?= $r['is_system'] ? '<span class="chip">سیستمی</span>' : '' ?></td>
        <td style="font-size:12px;color:var(--muted);line-height:1.9">
          <?php foreach ($caps as $c): ?><span class="chip" style="margin:1px"><?= e($catalog[$c] ?? $c) ?></span><?php endforeach; ?>
          <?= $caps ? '' : '<span style="color:var(--c-danger)">هیچ دسترسی‌ای — فقط داشبورد و پروفایل</span>' ?>
        </td>
        <td><?= fa_num((int)$r['admins_count']) ?></td>
        <td style="white-space:nowrap">
          <a class="fr-btn sm ghost" href="?edit=<?= (int)$r['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline" onsubmit="return confirm('این نقش حذف شود؟')">
            <?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="fr-btn sm danger">حذف</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <small class="hint" style="display:block;margin-top:8px">مدیرانِ دارای نقش سفارشی فقط به برگه‌هایی دسترسی دارند که capشان اینجا تیک خورده باشد؛ سایر برگه‌ها برایشان پنهان و مسدود است. اتصال نقش به مدیر از مسیر <a href="<?= SITE_URL ?>/admin/admins.php">مدیریت مدیران</a> انجام می‌شود.</small>
  </div>

  <div class="fr-card" style="position:sticky;top:12px">
    <h3><?= $edit ? 'ویرایش نقش: ' . e($edit['title']) : 'ساخت نقش جدید' ?></h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="fr-field"><label>عنوان نقش</label>
        <input class="fr-input" name="title" value="<?= e($edit['title'] ?? '') ?>" maxlength="120" placeholder="مثلاً مدیر محتوا"></div>
      <div class="fr-field"><label>سطح دسترسی (capها)</label>
        <div style="display:flex;flex-direction:column;gap:6px;max-height:340px;overflow:auto;border:1.5px solid var(--line);border-radius:12px;padding:10px">
          <?php foreach (fr_caps_catalog() as $cap => $label): ?>
          <label class="tog"><input type="checkbox" name="caps[]" value="<?= e($cap) ?>" <?= in_array($cap, $editCaps, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
        </div>
        <small class="hint">داشبورد و «پروفایل من» برای همهٔ مدیران همیشه باز است.</small></div>
      <button class="fr-btn"><?= $edit ? 'ذخیرهٔ تغییرات ✅' : 'ساخت نقش ✅' ?></button>
      <?php if ($edit): ?><a class="fr-btn ghost" href="roles.php">انصراف</a><?php endif; ?>
    </form>
  </div>
</div>

<!-- ═══ ۲٫۲ — ارتقای کاربر سایت به مدیر و تنزیل ═══ -->
<div class="fr-grid" style="grid-template-columns:.9fr 1.1fr;gap:16px;align-items:start" id="promote">
  <div class="fr-card" style="border-top:3px solid #6d4bd0">
    <h3 style="margin-top:0">⬆️ ارتقای کاربر سایت به مدیر</h3>
    <p style="font-size:12.5px;color:var(--muted);margin-top:0">
      از بین کاربران ثبت‌نام‌شدهٔ سایت انتخاب کن و نقش مدیریتی بده. اگر کاربر از قبل مدیر باشد، فقط نقشش به‌روز می‌شود.
    </p>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="act" value="user_promote">
      <div class="fr-field"><label>کاربر سایت (جستجو با نام / موبایل / نام کاربری)</label>
        <input class="fr-input" name="user_pick" list="siteUsersList" required placeholder="تایپ کن تا فیلتر شود…">
        <datalist id="siteUsersList">
          <?php foreach ($siteUsers as $su):
              $sn = trim((string)$su['first_name'] . ' ' . (string)$su['last_name']); ?>
          <option value="<?= e($su['mobile'] . ' — ' . ($sn !== '' ? $sn : 'بدون نام') . (!empty($su['username']) ? ' (@' . $su['username'] . ')' : '')) ?>">
          <?php endforeach; ?>
        </datalist>
        <small class="hint"><?= fa_num(count($siteUsers)) ?> کاربر فعال در فهرست است.</small>
      </div>
      <div class="fr-field"><label>نقش مدیریتی</label>
        <select class="fr-select" name="role_pick">
          <optgroup label="نقش‌های پایه">
            <option value="legacy:support">پشتیبان</option>
            <option value="legacy:manager">مدیر اجرایی</option>
            <option value="legacy:superadmin">مدیر ارشد</option>
          </optgroup>
          <?php if ($roles): ?>
          <optgroup label="نقش‌های سفارشی">
            <?php foreach ($roles as $r): ?><option value="role:<?= (int)$r['id'] ?>"><?= e($r['title']) ?></option><?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select></div>
      <div class="fr-field"><label>رمز عبور پیشخوان (خالی = خودکار و نمایش یک‌باره)</label>
        <input class="fr-input" name="password" style="direction:ltr" maxlength="64" placeholder="حداقل ۸ کاراکتر"></div>
      <button class="fr-btn">🛂 ارتقا به مدیر ✅</button>
    </form>
  </div>

  <div class="fr-card" style="margin:0;border-top:3px solid #e0393f">
    <h3 style="margin-top:0">⬇️ تنزیل مدیران به کاربر عادی</h3>
    <p style="font-size:12.5px;color:var(--muted);margin-top:0">
      تنزیل فقط «حساب پیشخوان» را حذف می‌کند؛ حساب کاربری فرد در سایت کاملاً می‌ماند.
    </p>
    <div style="overflow-x:auto"><table class="fr-table">
      <thead><tr><th>نام کاربری پیشخوان</th><th>نام</th><th>کاربر مرتبط</th><th>نقش</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($adminsNow as $a): ?>
      <tr>
        <td><code><?= e($a['username']) ?></code></td>
        <td><?= e($a['display_name'] ?? '—') ?></td>
        <td style="font-size:12px">
          <?= !empty($a['linked_mobile']) ? e(($a['linked_name'] !== '' ? $a['linked_name'] . ' — ' : '') . $a['linked_mobile']) : '<span style="color:var(--muted)">—</span>' ?>
        </td>
        <td style="font-size:12px">
          <?= e($a['role_title'] ?? '') ?><small style="color:var(--muted)"><?= $a['role_title'] ? ' (' . e($legacyTitles[$a['role']] ?? $a['role']) . ')' : e($legacyTitles[$a['role']] ?? $a['role']) ?></small>
        </td>
        <td><?= $a['status'] === 'active' ? '<span class="chip ok">فعال</span>' : '<span class="chip err">مسدود</span>' ?></td>
        <td>
          <?php if ((int)$a['id'] !== (int)$adm['id']): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('«<?= e($a['username']) ?>» به کاربر عادی تنزیل شود؟ (حساب پیشخوانش حذف می‌شود)')">
            <?= csrf_field() ?><input type="hidden" name="act" value="demote_admin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="fr-btn sm danger">تنزیل</button>
          </form>
          <?php else: ?><span class="chip">شما</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php admin_layout_end(); ?>
