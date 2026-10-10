<?php
/** مدیریت کاربران — جستجو، مسدودسازی، ویرایش، سطل زباله، خروجی Excel/CSV فارسی */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../core/TrashManager.php';
$adm = require_admin();
TrashManager::ensureSchema();

$viewMode = (string)($_GET['view'] ?? 'active');
$q = trim((string)($_GET['q'] ?? ''));

$where = ($viewMode === 'trash') ? "(status='deleted' OR deleted_at IS NOT NULL)" : "(status!='deleted' AND deleted_at IS NULL)";
$params = [];
if ($q !== '') { $where .= " AND (mobile LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR national_code LIKE ? OR username LIKE ?)"; $p = "%$q%"; $params = [$p,$p,$p,$p,$p]; }

$activeCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE status!='deleted' AND deleted_at IS NULL");
$trashCount  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE status='deleted' OR deleted_at IS NOT NULL");

/* خروجی اکسل/CSV */
if (isset($_GET['export'])) {
    $rows = DB::all("SELECT * FROM " . DB::t('users') . " WHERE $where ORDER BY id DESC LIMIT 5000", $params);
    $out = [];
    foreach ($rows as $u) {
        $out[] = [
            $u['id'], trim(($u['first_name']??'').' '.($u['last_name']??'')), $u['mobile'],
            $u['national_code'], $u['birth_date'] ? fa_num(jdate('Y/m/d', strtotime($u['birth_date']))) : '',
            $u['job_title'], $u['status'], fa_num(jdate('Y/m/d', strtotime($u['created_at']))),
            $u['last_login_at'] ? fa_num(jdate('Y/m/d H:i', strtotime($u['last_login_at']))) : '',
            $u['last_login_ip'] ? inet_ntop($u['last_login_ip']) : '',
        ];
    }
    $headers = ['شناسه','نام','موبایل','کد ملی','تاریخ تولد','شغل','وضعیت','تاریخ عضویت','آخرین ورود','IP آخرین ورود'];
    $nm = 'users-' . date('Ymd-Hi');
    if ($_GET['export'] === 'xls') export_xls($nm, 'فهرست کاربران — ' . DB::setting('site_title','فریا روان'), $headers, $out);
    else export_csv($nm, $headers, $out);
}

/* نسخهٔ ۲٫۲ — ساخت کاربر جدید از پیشخوان */
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');

    if ($act === 'add_user') {
        $first = mb_substr(trim((string)post('first_name')), 0, 100);
        $last  = mb_substr(trim((string)post('last_name')), 0, 100);
        $mob   = en_num(trim((string)post('mobile')));
        $nc    = en_num(trim((string)post('national_code')));
        $un    = mb_substr(trim((string)post('username')), 0, 60);
        $pass  = (string)($_POST['password'] ?? '');
        $gen   = '';

        if ($first === '' && $last === '') {
            flash('نام یا نام خانوادگی کاربر را بنویسید.', 'err');
        } elseif (!valid_mobile($mob)) {
            flash('شمارهٔ موبایل معتبر نیست (مثل 0912…).', 'err');
        } elseif (find_user_by($mob)) {
            flash('کاربری با این موبایل از قبل وجود دارد؛ جستجو کنید یا مسدودش کنید.', 'err');
        } elseif ($nc !== '' && !valid_national_code($nc)) {
            flash('کد ملی معتبر نیست (اگر نمی‌خواهید خالی بگذارید).', 'err');
        } elseif ($nc !== '' && (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE national_code=? AND status!='deleted'", [$nc]) > 0) {
            flash('این کد ملی قبلاً ثبت شده است.', 'err');
        } elseif ($un !== '' && (!valid_fr_username($un) || fr_username_taken($un))) {
            flash('نام کاربری باید لاتین و یکتا باشد (حرف آغازین، ۴ تا ۳۰ نویسه).', 'err');
        } elseif ($pass !== '' && strlen($pass) < 8) {
            flash('رمز باید حداقل ۸ کاراکتر باشد (یا خالی بگذارید تا خودکار ساخته شود).', 'err');
        } else {
            if ($pass === '') { $gen = 'Fr' . random_int(100000, 999999); $pass = $gen; }
            $newId = DB::insert('users', [
                'first_name'      => $first !== '' ? $first : null,
                'last_name'       => $last !== '' ? $last : null,
                'mobile'          => $mob,
                'national_code'   => $nc !== '' ? $nc : null,
                'username'        => $un !== '' ? $un : null,
                'password_hash'   => password_hash($pass, PASSWORD_ARGON2ID),
                'mobile_verified' => 1,
                'status'          => 'active',
            ]);
            log_activity('admin_user_add', 'user', (int)$newId, ['mobile' => $mob]);
            /* ۲٫۷ — ثبت خودکار در مخاطبین */
            try {
                require_once __DIR__ . '/../includes/contacts.php';
                fr_contact_sync_user(['id' => (int)$newId, 'mobile' => $mob, 'first_name' => $first, 'last_name' => $last]);
            } catch (Throwable $e) { /* بی‌صدا */ }
            flash('کاربر «' . trim($first . ' ' . $last) . '» ساخته شد ✅' .
                  ($gen ? ' — رمز یک‌بارمصرف نمایشی: ' . $gen . ' (فقط همین‌جا دیده می‌شود؛ برای کاربر بفرستید)' : ' — رمز تعیین‌شده برای کاربر فعال است.'));
        }
        redirect(SITE_URL . '/admin/users.php' . ($q ? '?q='.urlencode($q) : ''));
    }

    if ($act === 'edit_user') {
        $id    = (int)post('id');
        $first = mb_substr(trim((string)post('first_name')), 0, 100);
        $last  = mb_substr(trim((string)post('last_name')), 0, 100);
        $mob   = en_num(trim((string)post('mobile')));
        $nc    = en_num(trim((string)post('national_code')));
        $un    = mb_substr(trim((string)post('username')), 0, 60);
        $job   = mb_substr(trim((string)post('job_title')), 0, 100);
        $st    = in_array(post('status'), ['active', 'blocked', 'banned'], true) ? post('status') : 'active';
        $pass  = (string)($_POST['password'] ?? '');

        if (!valid_mobile($mob)) {
            flash('شماره موبایل واردشده معتبر نیست.', 'err');
        } else {
            $dupMob = (int)DB::val("SELECT id FROM " . DB::t('users') . " WHERE mobile=? AND id<>?", [$mob, $id]);
            if ($dupMob > 0) {
                flash('این شماره موبایل به کاربر دیگری تعلق دارد.', 'err');
            } else {
                $upData = [
                    'first_name'    => $first !== '' ? $first : null,
                    'last_name'     => $last !== '' ? $last : null,
                    'mobile'        => $mob,
                    'national_code' => $nc !== '' ? $nc : null,
                    'username'      => $un !== '' ? $un : null,
                    'job_title'     => $job !== '' ? $job : null,
                    'status'        => $st,
                ];
                if ($pass !== '') {
                    if (strlen($pass) < 6) {
                        flash('رمز عبور باید حداقل ۶ کاراکتر باشد.', 'err');
                        redirect(SITE_URL . '/admin/users.php?edit_u=' . $id);
                    }
                    $upData['password_hash'] = password_hash($pass, PASSWORD_ARGON2ID);
                }
                DB::update('users', $upData, 'id=?', [$id]);
                log_activity('admin_user_edit', 'user', $id);
                flash('اطلاعات کاربر با موفقیت ویرایش و ذخیره شد ✅', 'ok');
                redirect(SITE_URL . '/admin/users.php');
            }
        }
        redirect(SITE_URL . '/admin/users.php?edit_u=' . $id);
    }

    if ($act === 'toggle_block') {
        $id = (int)post('id');
        $row = DB::one("SELECT status FROM " . DB::t('users') . " WHERE id=?", [$id]);
        $new = $row['status'] === 'active' ? 'blocked' : 'active';
        DB::update('users', ['status'=>$new], 'id=?', [$id]);
        log_activity('admin_user_'.($new==='blocked'?'block':'unblock'), 'user', $id);
        flash($new==='blocked' ? 'کاربر مسدود شد.' : 'کاربر رفع‌مسدود شد ✅');
        redirect(SITE_URL . '/admin/users.php' . ($q ? '?q='.urlencode($q) : ''));
    }

    if ($act === 'reset_pass') {
        $id = (int)post('id');
        $newPass = 'Fr' . random_int(100000, 999999);
        DB::update('users', ['password_hash'=>password_hash($newPass, PASSWORD_ARGON2ID)], 'id=?', [$id]);
        log_activity('admin_reset_pass', 'user', $id);
        flash('رمز جدید کاربر: ' . $newPass . ' (یک‌بار نمایش داده شد — برای کاربر ارسال کنید)', 'warn');
        redirect(SITE_URL . '/admin/users.php' . ($q ? '?q='.urlencode($q) : ''));
    }

    /* ── انتقال به سطل زباله ── */
    if ($act === 'trash_user') {
        $id = (int)post('id');
        $urow = DB::one("SELECT first_name, last_name, mobile FROM " . DB::t('users') . " WHERE id=?", [$id]);
        if ($urow && TrashManager::trash('user', $id, (int)($adm['id'] ?? 0))) {
            $nm = trim(($urow['first_name'] ?? '') . ' ' . ($urow['last_name'] ?? '')) ?: (string)$urow['mobile'];
            flash('کاربر «' . $nm . '» به سطل زباله منتقل گردید 🗑 (امکان بازیابی در سطل زباله وجود دارد)', 'ok');
        } else {
            flash('خطا در انتقال کاربر به سطل زباله.', 'err');
        }
        redirect(SITE_URL . '/admin/users.php' . ($viewMode === 'trash' ? '?view=trash' : ''));
    }

    /* ── بازیابی کاربر از سطل زباله ── */
    if ($act === 'restore_user') {
        $id = (int)post('id');
        $urow = DB::one("SELECT first_name, last_name, mobile FROM " . DB::t('users') . " WHERE id=?", [$id]);
        if ($urow && TrashManager::restore('user', $id)) {
            $nm = trim(($urow['first_name'] ?? '') . ' ' . ($urow['last_name'] ?? '')) ?: (string)$urow['mobile'];
            flash('کاربر «' . $nm . '» با موفقیت از سطل زباله بازیابی و فعال گردید ♻️', 'ok');
        } else {
            flash('خطا در بازیابی کاربر.', 'err');
        }
        redirect(SITE_URL . '/admin/users.php?view=trash');
    }

    /* ── حذف کامل و دائمی ── */
    if ($act === 'del_user' || $act === 'purge_user') {
        $id = (int)post('id');
        $urow = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$id]);
        if (!$urow) {
            flash('کاربر یافت نشد.', 'err');
        } elseif ((int)DB::val("SELECT COUNT(*) FROM " . DB::t('admins') . " WHERE user_id=?", [$id]) > 0) {
            flash('این کاربر حساب مدیر دارد؛ ابتدا از صفحهٔ «نقش‌ها و مدیران» تنزیلش کنید، بعد حذف نمایید.', 'err');
        } else {
            $nm = trim(($urow['first_name'] ?? '') . ' ' . ($urow['last_name'] ?? '')) ?: (string)$urow['mobile'];
            TrashManager::purge('user', $id);
            flash('کاربر «' . $nm . '» و کلِ داده‌هایش به‌طور کامل و برای همیشه از سیستم حذف گردید ❌');
        }
        redirect(SITE_URL . '/admin/users.php' . ($viewMode === 'trash' ? '?view=trash' : ''));
    }
}

/**
 * حذف کامل کاربر از همهٔ جدول‌ها — نسخهٔ ۲٫۳
 */
function fr_admin_delete_user_fully(int $uid, string $mobile): void
{
    $T = fn(string $t) => DB::t($t);
    try { DB::run("SET FOREIGN_KEY_CHECKS=0"); } catch (Throwable $e) {}
    try {
        $oids = array_map('intval', array_column(DB::all("SELECT id FROM {$T('orders')} WHERE user_id=?", [$uid]), 'id'));
        $aids = array_map('intval', array_column(DB::all("SELECT id FROM {$T('attempts')} WHERE user_id=?", [$uid]), 'id'));
        $rids = array_map('intval', array_column(DB::all("SELECT id FROM {$T('event_registrations')} WHERE user_id=?", [$uid]), 'id'));
        $tids = array_map('intval', array_column(DB::all("SELECT id FROM {$T('tickets')} WHERE user_id=?", [$uid]), 'id'));
        $in = fn(array $ids) => $ids ? '(' . implode(',', $ids) . ')' : '(0)';
        $run = function (string $sql, array $p = []) { try { DB::run($sql, $p); } catch (Throwable $e) { error_log('feria: del_user: ' . $e->getMessage()); } };

        $run("DELETE FROM {$T('payments')} WHERE order_id IN " . $in($oids));
        $run("DELETE FROM {$T('discount_usage')} WHERE order_id IN " . $in($oids) . " OR user_id=?", [$uid]);
        $run("DELETE FROM {$T('answers')} WHERE attempt_id IN " . $in($aids));
        $run("DELETE FROM {$T('results')} WHERE attempt_id IN " . $in($aids));
        $run("DELETE FROM {$T('reports')} WHERE attempt_id IN " . $in($aids));
        $run("DELETE FROM {$T('clinical_notes')} WHERE attempt_id IN " . $in($aids));
        $run("DELETE FROM {$T('attempts')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('orders')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('wallet_transactions')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('refund_requests')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('consult_appointments')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('event_companions')} WHERE user_id=? OR reg_id IN " . $in($rids), [$uid]);
        $run("DELETE FROM {$T('event_registrations')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('reviews')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('planner_tasks')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('planner_rewards')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('planner_recur')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('social_links')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('notify_log')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('ticket_replies')} WHERE ticket_id IN " . $in($tids));
        $run("DELETE FROM {$T('tickets')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('otps')} WHERE user_id=? OR mobile=?", [$uid, $mobile]);
        $run("DELETE FROM {$T('newsletter')} WHERE mobile=?", [$mobile]);
        $run("DELETE FROM {$T('sessions')} WHERE user_id=?", [$uid]);
        $run("DELETE FROM {$T('users')} WHERE id=?", [$uid]);
    } finally {
        try { DB::run("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $e) {}
    }
}

$editUserId = (int)($_GET['edit_u'] ?? 0);
$editUser = $editUserId > 0 ? DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$editUserId]) : null;

$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;
$rows   = DB::all("SELECT * FROM " . DB::t('users') . " WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page-1)*$per), $params);
$total  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE $where", $params);
$pages  = max(1, (int)ceil($total/$per));

admin_layout($adm, 'users', ($viewMode === 'trash' ? '🗑 سطل زباله کاربران' : 'کاربران'));
echo flash();
?>

<?php if ($editUser): ?>
<!-- ── کارت ویرایش کاربر ── -->
<div class="fr-card" style="margin-bottom:20px;border-top:3px solid var(--c-primary)">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:10px">
    <h3 style="margin:0">✏️ ویرایش مشخصات کاربر: <?= e(trim(($editUser['first_name'] ?? '') . ' ' . ($editUser['last_name'] ?? '')) ?: $editUser['mobile']) ?> (#<?= fa_num($editUser['id']) ?>)</h3>
    <a class="fr-btn sm ghost" href="users.php">← بازگشت به لیست</a>
  </div>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="edit_user">
    <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
    <div class="fr-grid cols-3" style="gap:12px">
      <div class="fr-field">
        <label>نام</label>
        <input class="fr-input" name="first_name" value="<?= e($editUser['first_name'] ?? '') ?>" maxlength="100">
      </div>
      <div class="fr-field">
        <label>نام خانوادگی</label>
        <input class="fr-input" name="last_name" value="<?= e($editUser['last_name'] ?? '') ?>" maxlength="100">
      </div>
      <div class="fr-field">
        <label>شماره موبایل *</label>
        <input class="fr-input" name="mobile" value="<?= e($editUser['mobile'] ?? '') ?>" style="direction:ltr;text-align:center" required maxlength="11">
      </div>
      <div class="fr-field">
        <label>کد ملی</label>
        <input class="fr-input" name="national_code" value="<?= e($editUser['national_code'] ?? '') ?>" style="direction:ltr;text-align:center" maxlength="10">
      </div>
      <div class="fr-field">
        <label>نام کاربری / آیدی</label>
        <input class="fr-input" name="username" value="<?= e($editUser['username'] ?? '') ?>" style="direction:ltr" maxlength="60">
      </div>
      <div class="fr-field">
        <label>شغل / تخصص</label>
        <input class="fr-input" name="job_title" value="<?= e($editUser['job_title'] ?? '') ?>" maxlength="100">
      </div>
      <div class="fr-field">
        <label>وضعیت حساب</label>
        <select class="fr-select" name="status">
          <option value="active" <?= $editUser['status']==='active'?'selected':'' ?>>فعال 🟢</option>
          <option value="blocked" <?= in_array($editUser['status'],['blocked','banned'],true)?'selected':'' ?>>مسدود ⛔</option>
        </select>
      </div>
      <div class="fr-field">
        <label>رمز عبور جدید (اختیاری — فقط در صورت تغییر وارد کنید)</label>
        <input class="fr-input" name="password" style="direction:ltr" placeholder="حداقل ۶ کاراکتر" maxlength="64" autocomplete="new-password">
      </div>
    </div>
    <div style="margin-top:14px;display:flex;gap:8px">
      <button class="fr-btn" type="submit">💾 ذخیره تغییرات کاربر</button>
      <a class="fr-btn ghost" href="users.php">انصراف</a>
      <a class="fr-btn sm" style="margin-right:auto;background:#4338ca;color:#fff" href="user-view.php?id=<?= $editUser['id'] ?>" target="_blank">مشاهده پرونده کامل کاربر ↗</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ۲٫۲ — افزودن کاربر جدید از پیشخوان -->
<?php if (!$editUser && $viewMode !== 'trash'): ?>
<details class="fr-card" style="cursor:default" id="addUserCard">
  <summary style="cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;font-weight:800;font-size:15px">
    <span style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--c-primary),var(--c-secondary));color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:18px">＋</span>
    افزودن کاربر جدید
    <small style="color:var(--muted);font-weight:400">(بدون نیاز به ثبت‌نام پیامکی — موبایل فوراً تأییدشده ساخته می‌شود)</small>
  </summary>
  <form method="post" style="margin-top:14px;border-top:1.5px dashed var(--line);padding-top:14px" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="act" value="add_user">
    <div class="fr-grid cols-3">
      <div class="fr-field"><label>نام *</label><input class="fr-input" name="first_name" maxlength="100" placeholder="مثلاً سارا"></div>
      <div class="fr-field"><label>نام خانوادگی</label><input class="fr-input" name="last_name" maxlength="100" placeholder="مثلاً محمدی"></div>
      <div class="fr-field"><label>موبایل *</label><input class="fr-input" name="mobile" style="direction:ltr;text-align:center" maxlength="11" inputmode="numeric" placeholder="09xxxxxxxxx"></div>
      <div class="fr-field"><label>کد ملی (اختیاری)</label><input class="fr-input" name="national_code" style="direction:ltr;text-align:center" maxlength="10" inputmode="numeric"></div>
      <div class="fr-field"><label>نام کاربری لاتین (اختیاری)</label><input class="fr-input" name="username" style="direction:ltr" maxlength="30" placeholder="sara.mohammadi"></div>
      <div class="fr-field"><label>رمز عبور (اختیاری — خالی = خودکار)</label><input class="fr-input" name="password" style="direction:ltr" maxlength="64" autocomplete="new-password" placeholder="حداقل ۸ کاراکتر"></div>
    </div>
    <button class="fr-btn">➕ ساخت کاربر</button>
  </form>
</details>
<?php endif; ?>

<div class="fr-card">
  <!-- نوار تغییر نما و خروجی -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <a class="fr-chip <?= $viewMode==='active'?'purple':'info' ?>" href="users.php?view=active" style="text-decoration:none">
        کاربران فعال (<?= fa_num($activeCount) ?>)
      </a>
      <a class="fr-chip <?= $viewMode==='trash'?'purple':'info' ?>" href="users.php?view=trash" style="text-decoration:none">
        🗑 سطل زباله (<?= fa_num($trashCount) ?>)
      </a>
      <a class="fr-chip ghost" href="<?= SITE_URL ?>/admin/trash.php?tab=users" style="text-decoration:none">
        مدیریت سطل زباله سیستم ↗
      </a>
    </div>

    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="view" value="<?= e($viewMode) ?>">
      <input class="fr-input" name="q" value="<?= e($q) ?>" placeholder="جستجو: نام / موبایل / کد ملی" style="max-width:240px">
      <button class="fr-btn sm">جستجو</button>
      <a class="fr-btn sm accent" href="?export=xls&view=<?= $viewMode ?><?= $q?'&q='.urlencode($q):'' ?>">📊 Excel</a>
      <a class="fr-btn sm ghost" href="?export=csv&view=<?= $viewMode ?><?= $q?'&q='.urlencode($q):'' ?>">⬇ CSV</a>
    </form>
  </div>

  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>#</th><th>نام</th><th>موبایل</th><th>کد ملی</th><th>شغل</th><th>عضویت</th><th>آخرین ورود</th><th>وضعیت</th><th style="min-width:220px">اقدامات</th></tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr>
        <td colspan="9" style="text-align:center;padding:32px 0;color:var(--muted)">
          <?= $viewMode === 'trash' ? 'هیچ کاربری در سطل زباله وجود ندارد.' : 'کاربری یافت نشد.' ?>
        </td>
      </tr>
    <?php endif; ?>

    <?php foreach ($rows as $u): ?>
    <tr style="<?= $u['status']==='active'?'':'opacity:.7' ?>">
      <td><?= fa_num($u['id']) ?></td>
      <td><b><?= e(trim($u['first_name'].' '.$u['last_name']) ?: '—') ?></b></td>
      <td style="direction:ltr"><?= fa_num($u['mobile']) ?><?= !empty($u['username']) ? '<br><small style="color:var(--muted)">@' . e($u['username']) . '</small>' : '' ?></td>
      <td style="direction:ltr"><?= e($u['national_code'] ?? '—') ?></td>
      <td style="font-size:12px"><?= e($u['job_title'] ?? '—') ?></td>
      <td style="font-size:12px"><?= fa_num(jdate('j F Y', strtotime($u['created_at']))) ?></td>
      <td style="font-size:12px"><?= $u['last_login_at'] ? fa_num(jdate('j F Y - H:i', strtotime($u['last_login_at']))) : '—' ?></td>
      <td>
        <?php if ($viewMode === 'trash'): ?>
          <span class="fr-chip err">در سطل زباله</span>
        <?php elseif ($u['status']==='active'): ?>
          <span class="fr-chip ok">فعال</span>
        <?php else: ?>
          <span class="fr-chip err">مسدود</span>
        <?php endif; ?>
      </td>
      <td class="actions" style="white-space:nowrap">
        <?php if ($viewMode === 'trash'): ?>
          <!-- دکمه بازیابی -->
          <form method="post" style="display:inline" onsubmit="return confirm('آیا از بازیابی این کاربر و بازگرداندن آن به لیست فعال اطمینان دارید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="restore_user">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="fr-btn sm ok" style="background:#059669;color:#fff" type="submit">♻️ بازیابی</button>
          </form>

          <!-- دکمه حذف دائمی -->
          <form method="post" style="display:inline" onsubmit="return confirm('⚠️ حذف نهایی و قطعی:\n\nآیا از حذف کامل و غیرقابل‌بازگشت این کاربر و همه سوابقش اطمینان دارید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="purge_user">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="fr-btn sm danger" type="submit">❌ حذف قطعی</button>
          </form>
        <?php else: ?>
          <a class="fr-btn sm ghost" href="?edit_u=<?= $u['id'] ?>" title="ویرایش اطلاعات کاربر">✏️ ویرایش</a>
          <a class="fr-btn sm" href="user-view.php?id=<?= $u['id'] ?>" title="مشاهده پرونده کاربر">📁 پرونده</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="act" value="toggle_block">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="fr-btn sm <?= $u['status']==='active'?'danger':'accent' ?>"><?= $u['status']==='active'?'مسدود':'فعال‌سازی' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('رمز جدید ساخته شود؟')">
            <?= csrf_field() ?><input type="hidden" name="act" value="reset_pass">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="fr-btn sm ghost">ریست رمز</button>
          </form>

          <?php if (($adm['role'] ?? '') === 'superadmin'): ?>
          <a class="fr-btn sm ghost" href="admins.php?from_user=<?= (int)$u['id'] ?>" title="ساخت حساب مدیر برای این کاربر">🛂 مدیر کردن</a>
          <?php endif; ?>

          <!-- دکمه انتقال به سطل زباله بدون وابستگی به جاوااسکریپت نامطمئن -->
          <form method="post" style="display:inline" onsubmit="return confirm('انتقال کاربر «<?= htmlspecialchars(user_fullname($u), ENT_QUOTES, 'UTF-8') ?>» به سطل زباله؟\n(امکان بازیابی در سطل زباله وجود دارد)');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="trash_user">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button type="submit" class="fr-btn sm danger" title="انتقال کاربر به سطل زباله">🗑 حذف</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages>1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:14px">
    <?php for($p=1;$p<=$pages;$p++): ?>
      <a class="fr-btn sm <?= $p===$page?'':'ghost' ?>" href="?page=<?= $p ?>&view=<?= $viewMode ?><?= $q?'&q='.urlencode($q):'' ?>"><?= fa_num($p) ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_layout_end(); ?>
