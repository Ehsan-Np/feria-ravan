<?php
/** مدیریت کاربران — جستجو، مسدودسازی، خروجی Excel/CSV فارسی */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin();

$q = trim((string)($_GET['q'] ?? ''));
$where = "status!='deleted'";
$params = [];
if ($q !== '') { $where .= " AND (mobile LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR national_code LIKE ? OR username LIKE ?)"; $p = "%$q%"; $params = [$p,$p,$p,$p,$p]; }

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

    if ($act === 'toggle_block') {
        $id = (int)post('id');
        $row = DB::one("SELECT status FROM " . DB::t('users') . " WHERE id=?", [$id]);
        $new = $row['status'] === 'active' ? 'blocked' : 'active';
        DB::update('users', ['status'=>$new], 'id=?', [$id]);
        log_activity('admin_user_'.($new==='blocked'?'block':'unblock'), 'user', $id);
        flash($new==='blocked' ? 'کاربر مسدود شد.' : 'کاربر رفع‌مسدود شد ✅');
    }
    if ($act === 'reset_pass') {
        $id = (int)post('id');
        $newPass = 'Fr' . random_int(100000, 999999);
        DB::update('users', ['password_hash'=>password_hash($newPass, PASSWORD_ARGON2ID)], 'id=?', [$id]);
        log_activity('admin_reset_pass', 'user', $id);
        flash('رمز جدید کاربر: ' . $newPass . ' (یک‌بار نمایش داده شد — برای کاربر ارسال کنید)', 'warn');
    }
    /* ── ۲٫۳: حذف کامل کاربر و همهٔ داده‌هایش ── */
    if ($act === 'del_user') {
        $id = (int)post('id');
        $urow = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [$id]);
        if (!$urow) {
            flash('کاربر یافت نشد (شاید قبلاً حذف شده).', 'err');
        } elseif ((int)DB::val("SELECT COUNT(*) FROM " . DB::t('admins') . " WHERE user_id=?", [$id]) > 0) {
            flash('این کاربر هنوز حساب مدیر دارد؛ ابتدا از صفحهٔ «نقش‌ها و مدیران» تنزیلش کنید، بعد حذف نمایید.', 'err');
        } else {
            $confirmMob = trim(en_num((string)post('confirm_mobile')));
            if ($confirmMob !== (string)$urow['mobile']) {
                flash('حذف انجام نشد: برای تأیید باید موبایل کاربر را دقیق وارد کنید.', 'err');
            } else {
                $nm = trim(($urow['first_name'] ?? '') . ' ' . ($urow['last_name'] ?? '')) ?: (string)$urow['mobile'];
                fr_admin_delete_user_fully($id, (string)$urow['mobile']);
                log_activity('admin_user_delete', 'user', $id, ['mobile' => (string)$urow['mobile'], 'name' => $nm]);
                flash('کاربر «' . $nm . '» و کلِ داده‌هایش (تاریخچهٔ تست‌ها، ثبت‌نام‌ها، سفارش‌ها، دیدگاه‌ها و…) به‌طور کامل و غیرقابل‌بازگشت حذف شد. 🗑');
            }
        }
    }
    redirect(SITE_URL . '/admin/users.php' . ($q ? '?q='.urlencode($q) : ''));
}

/**
 * حذف کامل کاربر از همهٔ جدول‌ها — نسخهٔ ۲٫۳
 * (با خاموش‌کردن موقت بررسی کلیدهای خارجی تا ترتیب حذف مهم نباشد؛ هر جدول جداگانه و امن پاک می‌شود)
 */
function fr_admin_delete_user_fully(int $uid, string $mobile): void
{
    $T = fn(string $t) => DB::t($t);
    try { DB::run("SET FOREIGN_KEY_CHECKS=0"); } catch (Throwable $e) {}
    try {
        /* مجموعه‌های وابسته » سفارش‌ها و تلاش‌های تست */
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

$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;
$rows   = DB::all("SELECT * FROM " . DB::t('users') . " WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page-1)*$per), $params);
$total  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE $where", $params);
$pages  = max(1, (int)ceil($total/$per));

admin_layout($adm, 'users', 'کاربران');
echo flash();
?>
<!-- ۲٫۲ — افزودن کاربر جدید از پیشخوان -->
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
<div class="fr-card">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input class="fr-input" name="q" value="<?= e($q) ?>" placeholder="جستجو: نام / موبایل / کد ملی / نام کاربری" style="max-width:320px">
    <button class="fr-btn sm">جستجو</button>
    <span class="fr-chip info"><?= fa_num($total) ?> کاربر</span>
    <a class="fr-btn sm accent" href="?export=xls<?= $q?'&q='.urlencode($q):'' ?>">📊 خروجی Excel</a>
    <a class="fr-btn sm ghost" href="?export=csv<?= $q?'&q='.urlencode($q):'' ?>">⬇ خروجی CSV</a>
  </form>
</div>
<div class="fr-card">
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>#</th><th>نام</th><th>موبایل</th><th>کد ملی</th><th>شغل</th><th>عضویت</th><th>آخرین ورود</th><th>وضعیت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
    <tr style="<?= $u['status']==='active'?'':'opacity:.5' ?>">
      <td><?= fa_num($u['id']) ?></td>
      <td><b><?= e(trim($u['first_name'].' '.$u['last_name']) ?: '—') ?></b></td>
      <td style="direction:ltr"><?= fa_num($u['mobile']) ?><?= !empty($u['username']) ? '<br><small style="color:var(--muted)">@' . e($u['username']) . '</small>' : '' ?></td>
      <td style="direction:ltr"><?= e($u['national_code'] ?? '—') ?></td>
      <td style="font-size:12px"><?= e($u['job_title'] ?? '—') ?></td>
      <td style="font-size:12px"><?= fa_num(jdate('j F Y', strtotime($u['created_at']))) ?></td>
      <td style="font-size:12px"><?= $u['last_login_at'] ? fa_num(jdate('j F Y - H:i', strtotime($u['last_login_at']))) : '—' ?></td>
      <td><?= $u['status']==='active' ? '<span class="fr-chip ok">فعال</span>' : '<span class="fr-chip err">مسدود</span>' ?></td>
      <td class="actions" style="white-space:nowrap">
        <a class="fr-btn sm" href="user-view.php?id=<?= $u['id'] ?>">پرونده</a>
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
        <?php /* نسخهٔ ۲٫۱: تبدیل کاربر به مدیر با نقش دلخواه — فقط مدیر ارشد */ ?>
        <?php if (($adm['role'] ?? '') === 'superadmin'): ?>
        <a class="fr-btn sm ghost" href="admins.php?from_user=<?= (int)$u['id'] ?>" title="ساخت حساب مدیر برای این کاربر">🛂 مدیر کردن</a>
        <?php endif; ?>
        <?php /* نسخهٔ ۲٫۳: حذف کامل و غیرقابل‌بازگشت کاربر — با تأیید دومرحله‌ای (تایپ موبایل) */ ?>
        <form method="post" style="display:inline" data-deluser data-mobile="<?= e($u['mobile']) ?>" data-name="<?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?>">
          <?= csrf_field() ?><input type="hidden" name="act" value="del_user">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <input type="hidden" name="confirm_mobile" value="">
          <button type="button" class="fr-btn sm danger" title="حذف کامل کاربر و همهٔ داده‌هایش">🗑 حذف کامل</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages>1): ?>
  <div style="display:flex;gap:6px;justify-content:center;margin-top:14px">
    <?php for($p=1;$p<=$pages;$p++): ?>
      <a class="fr-btn sm <?= $p===$page?'':'ghost' ?>" href="?page=<?= $p ?><?= $q?'&q='.urlencode($q):'' ?>"><?= fa_num($p) ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<script>
/* حذف کامل کاربر — تأیید دومرحله‌ای: تایپ موبایل + تأیید نهایی */
document.querySelectorAll('form[data-deluser]').forEach(function(f){
  f.querySelector('button').addEventListener('click', function(){
    var mob = f.dataset.mobile, name = f.dataset.name || 'این کاربر';
    var typed = prompt('⚠️ حذف کامل و غیرقابل‌بازگشت «' + name + '»\nهمهٔ داده‌ها (تست‌ها، ثبت‌نام‌ها، سفارش‌ها، دیدگاه‌ها، پلنر و…) حذف می‌شود.\nبرای تأیید، موبایل کاربر را وارد کنید:');
    if (typed === null) return;
    if (typed.trim() !== mob) { alert('موبایل واردشده مطابق نیست؛ حذف انجام نشد.'); return; }
    if (!confirm('آخرین تأیید: حذف کامل ' + name + ' قطعی شود؟')) return;
    f.querySelector('input[name="confirm_mobile"]').value = typed.trim();
    f.submit();
  });
});
</script>
<?php admin_layout_end(); ?>
