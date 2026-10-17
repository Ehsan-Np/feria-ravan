<?php
/**
 * =============================================================================
 *  پروفایل کامل کاربر — نسخهٔ ۱٫۳
 *  ─ چک‌لیست تکمیل حساب با درصد پیشرفت (مانند سورس مرجع)
 *  ─ آواتار (آپلود امن)    ─ موبایل فقط با OTP قابل تغییر است (امنیت حساب)
 *  ─ فیلدهای داینامیک پروفایل (از پنل ادمین قابل تنظیم)    ─ رمز عبور
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../core/Kavenegar.php';
fr_bootstrap();

$u      = require_user();
$errors = [];
$okMsg  = '';
$infoMsg = '';

/* فیلدهای داینامیک فعال — موبایل از این حلقه مستثنی می‌شود (فقط با OTP تغییر می‌کند) */
$fields = array_values(array_filter(
    DB::all('SELECT * FROM ' . DB::t('profile_fields') . ' WHERE active=1 AND show_in_profile=1 ORDER BY sort_order'),
    fn($f) => $f['name'] !== 'mobile'
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? 'save_profile');

    /* ── ۱) ذخیرهٔ اطلاعات پایه + فیلدهای داینامیک ── */
    if ($act === 'save_profile') {
        // نسخهٔ ۱٫۷: «نام و نام خانوادگی» یک فیلد است و سمت سرور به دو بخش دیتابیس می‌شکند
        if (array_key_exists('full_name', $_POST)) {
            [$pfn, $pln] = fr_split_fullname((string)$_POST['full_name']);
            $_POST['first_name'] = $pfn;
            $_POST['last_name']  = $pln;
        }
        $data = validate_profile_input($fields, $_POST, $errors);
        // یکتایی کد ملی
        if (!empty($data['national_code'])) {
            $dup = DB::val('SELECT id FROM ' . DB::t('users') . ' WHERE national_code=? AND id!=?',
                           [$data['national_code'], $u['id']]);
            if ($dup) $errors['national_code'] = 'این کد ملی قبلاً برای حساب دیگری ثبت شده است.';
        }
        if (!$errors) {
            DB::update('users', $data, 'id=?', [$u['id']]);
            $okMsg = 'اطلاعات حساب با موفقیت به‌روزرسانی شد ✨';
            log_activity('profile_update', 'user', (int)$u['id']);
            $u = current_user() ?: $u;
            if (isset($_GET['welcome']) && !empty($data['first_name'])) redirect(fr_panel_url());
        }
    }

    /* ── ۲) آپلود/حذف آواتار ── */
    if ($act === 'avatar') {
        $r = fr_save_avatar($_FILES['avatar'] ?? [], 'user', (int)$u['id']);
        if (!$r['ok']) $errors['avatar'] = $r['error'];
        else {
            fr_delete_avatar($u['avatar'] ?? null);
            DB::update('users', ['avatar' => $r['path']], 'id=?', [$u['id']]);
            $u = current_user() ?: $u;
            $okMsg = 'تصویر پروفایل به‌روزرسانی شد 📷';
            log_activity('avatar_update', 'user', (int)$u['id']);
        }
    }
    if ($act === 'avatar_remove') {
        fr_delete_avatar($u['avatar'] ?? null);
        DB::update('users', ['avatar' => null], 'id=?', [$u['id']]);
        $u = current_user() ?: $u;
        $okMsg = 'تصویر پروفایل حذف شد.';
    }

    /* ── ۳) تغییر موبایل — مرحلهٔ درخواست OTP ── */
    if ($act === 'mobile_request') {
        $mob = en_num(preg_replace('/\D/', '', (string)($_POST['mobile'] ?? '')));
        if (str_starts_with($mob, '98')) $mob = '0' . substr($mob, 2);
        if ($mob === $u['mobile'])            $errors['mobile'] = 'این شماره، موبایل فعلی شماست.';
        elseif (!valid_mobile($mob))          $errors['mobile'] = 'شماره موبایل معتبر نیست (۱۱ رقم با ۰۹).';
        elseif (DB::val('SELECT id FROM ' . DB::t('users') . ' WHERE mobile=? AND id!=?', [$mob, $u['id']]))
            $errors['mobile'] = 'این شماره متعلق به حساب دیگری است.';
        else {
            $r = otp_send($mob, 'mobile_change');
            if ($r['ok']) {
                $_SESSION['mobchg'] = ['m' => $mob, 't' => time()];
                $infoMsg = $r['message'] . ($r['debug_code'] ?? false ? ' (حالت آزمایشی: ' . fa_num($r['debug_code']) . ')' : '');
            } else $errors['mobile'] = $r['message'];
        }
    }

    /* ── ۴) تغییر موبایل — تأیید کد و ثبت نهایی ── */
    if ($act === 'mobile_verify') {
        $st   = $_SESSION['mobchg'] ?? null;
        $code = preg_replace('/\D/', '', en_num((string)($_POST['code'] ?? '')));
        if (!$st || time() - (int)$st['t'] > 600)      $errors['mobile'] = 'نشست تأیید منقضی شده؛ دوباره درخواست کد بدهید.';
        else {
            $v = otp_verify($st['m'], $code, 'mobile_change');
            if (!$v['ok']) $errors['mobile'] = $v['message'];
            else {
                DB::update('users', ['mobile' => $st['m'], 'mobile_verified' => 1], 'id=?', [$u['id']]);
                unset($_SESSION['mobchg']);
                $u = current_user() ?: $u;
                log_activity('mobile_changed', 'user', (int)$u['id'], ['mobile' => $st['m']]);
                $okMsg = 'موبایل شما تأیید و ثبت شد 🎉 از این پس با شمارهٔ جدید وارد شوید.';
            }
        }
    }
    if ($act === 'mobile_cancel') unset($_SESSION['mobchg']);

    /* ── ۶) ترجیح‌های اطلاع‌رسانی (۱٫۷) — JSON در ستون notif_prefs ── */
    if ($act === 'save_notifs') {
        $prefs = [
            'remind' => !empty($_POST['notif_remind']) ? 1 : 0,   // یادآوری‌های نوبت/رویداد
            'offers' => !empty($_POST['notif_offers']) ? 1 : 0,   // اطلاع‌رسانی تخفیف و رویدادهای تازه
        ];
        DB::update('users', ['notif_prefs' => json_encode($prefs, JSON_UNESCAPED_UNICODE)], 'id=?', [$u['id']]);
        $okMsg = 'ترجیح‌های اطلاع‌رسانی ذخیره شد ✅';
        log_activity('notif_prefs_update', 'user', (int)$u['id']);
        $u = current_user() ?: $u;
    }

    /* ── ۶الف) اتصال شبکه‌های اجتماعی با نام کاربری (۲٫۳) — تلگرام/بله ── */
    if (in_array($act, ['social_connect','social_disconnect','social_test'], true)) {
        require_once __DIR__ . '/../includes/snotify.php';
        $ch  = (string)($_POST['channel'] ?? 'telegram');
        if (!isset(fr_snotify_channels()[$ch])) $ch = 'telegram';
        if ($act === 'social_connect') {
            $r = fr_snotify_link_start((int)$u['id'], $ch, (string)($_POST['username'] ?? ''));
            if (!empty($r['ok'])) {
                $okMsg = 'نام کاربری ثبت شد ✅ حالا در ' . (fr_snotify_channels()[$ch]) . ' به ربات ' .
                         ($r['bot'] !== '' ? ('@' . $r['bot']) : 'ما') .
                         ' پیام /start (یا همین کد ' . $r['code'] . ') را بفرستید تا اتصال خودکار کامل شود؛' .
                         (!empty($r['deeplink']) ? ' نشانی مستقیم ربات: ' . $r['deeplink'] : '');
            } else {
                $errors['social'] = (string)($r['message'] ?? 'خطا در ثبت نام کاربری.');
            }
        } elseif ($act === 'social_disconnect') {
            fr_snotify_disconnect((int)$u['id'], $ch);
            $okMsg = 'اتصال ' . fr_snotify_channels()[$ch] . ' قطع شد؛ دیگر اطلاع‌رسانی این کانال برایتان ارسال نمی‌شود.';
        } else {
            $r = fr_snotify_send_test((int)$u['id'], $ch);
            if (!empty($r['ok'])) $okMsg = (string)$r['message'];
            else $errors['social'] = (string)($r['message'] ?? 'ارسال تست ناموفق بود.');
        }
        log_activity('social_' . $act, 'user', (int)$u['id'], ['channel' => $ch]);
        $u = current_user() ?: $u;
    }

    /* ── ۵) تنظیم/تغییر نام کاربری و رمز عبور (۲٫۲ — ورود با نام کاربری هم شدنی شد) ── */
    if ($act === 'set_password') {
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        $un = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 60);
        $save = []; $whatChanged = [];

        /* نام کاربری — خالی یعنی بدون تغییر؛ پر کردنِ همان مقدار فعلی هم بی‌اثر است */
        $curUn = (string)($u['username'] ?? '');
        if ($un !== '' && strcasecmp($un, $curUn) !== 0) {
            if (!valid_fr_username($un)) {
                $errors['password'] = 'نام کاربری باید لاتین باشد، با حرف شروع شود و ۴ تا ۳۰ نویسه (حروف، عدد، نقطه یا زیرخط) داشته باشد.';
            } elseif (fr_username_taken($un, (int)$u['id'])) {
                $errors['password'] = 'این نام کاربری قبلاً گرفته شده؛ یکی دیگر انتخاب کنید.';
            } else {
                $save['username'] = $un; $whatChanged[] = 'نام کاربری';
            }
        }
        if (!isset($errors['password']) && ($p1 !== '' || $p2 !== '')) {
            if (strlen($p1) < 8)            $errors['password'] = 'رمز باید حداقل ۸ کاراکتر باشد.';
            elseif ($p1 !== $p2)            $errors['password'] = 'تکرار رمز مطابقت ندارد.';
            else { $save['password_hash'] = password_hash($p1, PASSWORD_ARGON2ID); $whatChanged[] = 'رمز عبور'; }
        }
        if (!isset($errors['password'])) {
            if ($save) {
                DB::update('users', $save, 'id=?', [$u['id']]);
                $okMsg = implode(' و ', $whatChanged) . ' با موفقیت ثبت شد ✅ از این پس می‌توانید با نام کاربری، موبایل یا کد ملی و رمز ثابت وارد شوید.';
                log_activity('credentials_set', 'user', (int)$u['id'], ['username' => !empty($save['username']), 'password' => !empty($save['password_hash'])]);
                $u = current_user() ?: $u;
            } else {
                $okMsg = 'موردی برای تغییر وارد نشده بود؛ نام کاربری یا رمز جدید بنویسید.';
            }
        }
    }
}

/* ---------------------------------------------------------------------------
 | چک‌لیست تکمیل حساب — درصد پیشرفت و وضعیت هر بخش
 * -------------------------------------------------------------------------*/
$tasks = [
    ['نام و نام خانوادگی', trim((string)$u['first_name'] . (string)$u['last_name']) !== ''],
    ['تأیید موبایل',        (int)$u['mobile_verified'] === 1],
    ['ثبت کد ملی',           !empty($u['national_code'])],
    ['تاریخ تولد',           !empty($u['birth_date'])],
    ['ایمیل',                !empty($u['email'])],
    ['تصویر پروفایل',        !empty($u['avatar'])],
    ['تنظیم نام کاربری',     !empty($u['username'])],
    ['تنظیم رمز عبور',       !empty($u['password_hash'])],
];
$doneCnt = count(array_filter(array_column($tasks, 1)));
$pct     = (int)round($doneCnt / count($tasks) * 100);
$mobchg  = $_SESSION['mobchg'] ?? null;
$avatarUrl = !empty($u['avatar']) ? SITE_URL . '/' . ltrim((string)$u['avatar'], '/') : null;

render_header('پروفایل');
panel_layout($u, 'profile');

if (isset($_GET['welcome'])) echo '<div class="fr-alert info">🦢 برای تکمیل ثبت‌نام، اطلاعات پروفایل خود را وارد کنید.</div>';
if ($okMsg)   echo '<div class="fr-alert ok">' . e($okMsg) . '</div>';
if ($infoMsg) echo '<div class="fr-alert info">' . e($infoMsg) . '</div>';
?>
<style>
/* کارت‌های پروفایل */
.pf-ava{width:76px;height:76px;border-radius:50%;object-fit:cover;border:3px solid #e6dfff}
.pf-ava-ph{width:76px;height:76px;border-radius:50%;background:linear-gradient(135deg,#eaf0f8,#dcd2fb);display:flex;align-items:center;justify-content:center;font-size:30px;border:3px solid #e6dfff}
.pf-bar{height:10px;border-radius:8px;background:#eee9fb;overflow:hidden}
.pf-bar>i{display:block;height:100%;border-radius:8px;background:linear-gradient(270deg,#7c5cf0,#4e35c4);transition:width .5s}
.pf-task{display:flex;align-items:center;gap:8px;font-size:12.5px;padding:6px 0;color:#57507e}
.pf-task b.ok{color:#0c7a4d}
</style>

<!-- ── چک‌لیست تکمیل حساب ──────────────────────────────────── -->
<div class="fr-card">
  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <div style="display:flex;gap:14px;align-items:center">
      <?php if ($avatarUrl): ?><img class="pf-ava" src="<?= e($avatarUrl) ?>" alt="آواتار">
      <?php else: ?><div class="pf-ava-ph">👤</div><?php endif; ?>
      <div>
        <b style="font-size:16px"><?= e(user_fullname($u) ?: ('کاربر ' . fr_brand_short())) ?></b><br>
        <small style="color:var(--muted)" dir="ltr"><?= fa_num($u['mobile']) ?></small>
        <?= (int)$u['mobile_verified'] ? ' <span class="fr-chip ok" style="font-size:10px">تأییدشده ✓</span>' : '' ?>
      </div>
    </div>
    <div style="flex:1;min-width:200px">
      <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px">
        <b>تکمیل حساب کاربری</b><b style="color:var(--c-primary)"><?= fa_num($pct) ?>٪</b>
      </div>
      <div class="pf-bar"><i style="width:<?= $pct ?>%"></i></div>
    </div>
  </div>
  <div style="display:flex;flex-wrap:wrap;column-gap:24px;margin-top:10px">
    <?php foreach ($tasks as [$label, $done]): ?>
    <div class="pf-task"><?= $done ? '<b class="ok">✓</b>' : '<b style="color:#c9c1e6">○</b>' ?> <?= e($label) ?></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="fr-grid cols-2">
  <!-- ── فرم اطلاعات حساب (داینامیک) ── -->
  <div class="fr-card" style="margin:0">
    <h3>اطلاعات حساب</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save_profile">
      <?php
      // اگر هر دو فیلد نام/نام‌خانوادگی فعال‌اند، آن‌ها را به‌صورت «یک فیلدِ نام و نام خانوادگی» می‌نماییم (۱٫۷)
      $hasFn = (bool)array_filter($fields, fn($x) => $x['name'] === 'first_name');
      $hasLn = (bool)array_filter($fields, fn($x) => $x['name'] === 'last_name');
      $mergedRendered = false;
      foreach ($fields as $f):
        $val = $u[$f['name']] ?? '';
        if ($f['type'] === 'date' && $val) {
            [$y,$m,$d] = explode('-', $val); $j = g2j((int)$y,(int)$m,(int)$d);
            $val = fa_num(sprintf('%d/%02d/%02d', $j[0], $j[1], $j[2]));
        } elseif (in_array($f['type'], ['mobile','nationalcode']) && $val) {
            $val = fa_num($val);
        }
        if ($hasFn && $hasLn && $f['name'] === 'last_name') continue;          // با نام، یک‌جا آمده
        if ($hasFn && $hasLn && $f['name'] === 'first_name'):
            $mergedRendered = true;
            $reqMark = ($f['required'] ?? 0) ? ' <span class="req">*</span>' : '';
      ?>
      <div class="fr-field <?= isset($errors['first_name']) || isset($errors['last_name']) ? 'invalid' : '' ?>">
        <label>نام و نام خانوادگی<?= $reqMark ?></label>
        <input class="fr-input" name="full_name" value="<?= e(trim((string)$u['first_name'] . ' ' . (string)$u['last_name'])) ?>"
               maxlength="200" placeholder="مثل: سارا محمدی" autocomplete="name">
        <small style="display:block;color:var(--muted);font-size:11.5px;margin-top:6px">نام و نام خانوادگی را با هم و با فاصله بنویسید.</small>
        <?php if (isset($errors['first_name'])): ?><div class="err" style="display:block"><?= e($errors['first_name']) ?></div><?php endif; ?>
        <?php if (isset($errors['last_name']) && !isset($errors['first_name'])): ?><div class="err" style="display:block"><?= e($errors['last_name']) ?></div><?php endif; ?>
      </div>
      <?php continue; endif; ?>
      <div class="fr-field <?= isset($errors[$f['name']]) ? 'invalid' : '' ?>">
        <label><?= e($f['label']) ?><?= $f['required'] ? ' <span class="req">*</span>' : '' ?></label>
        <?php if ($f['type'] === 'textarea'): ?>
          <textarea class="fr-textarea" name="<?= e($f['name']) ?>" placeholder="<?= e($f['placeholder'] ?? '') ?>"><?= e($val) ?></textarea>
        <?php elseif ($f['type'] === 'select'): ?>
          <select class="fr-select" name="<?= e($f['name']) ?>">
            <option value="">انتخاب کنید...</option>
            <?php foreach ((json_decode($f['options'] ?? '[]', true) ?: []) as $opt): ?>
              <option value="<?= e($opt) ?>" <?= $val === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input class="fr-input" name="<?= e($f['name']) ?>" value="<?= e((string)$val) ?>"
                 placeholder="<?= e($f['placeholder'] ?? '') ?>"
                 <?= in_array($f['type'], ['mobile','nationalcode','number']) ? 'inputmode="numeric" style="direction:ltr;text-align:right"' : '' ?>>
        <?php endif; ?>
        <?php if (isset($errors[$f['name']])): ?><div class="err" style="display:block"><?= e($errors[$f['name']]) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <button class="fr-btn">ذخیره اطلاعات</button>
    </form>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <!-- ── تصویر پروفایل ── -->
    <div class="fr-card" style="margin:0">
      <h3>تصویر پروفایل</h3>
      <div style="display:flex;gap:14px;align-items:center">
        <?php if ($avatarUrl): ?><img class="pf-ava" src="<?= e($avatarUrl) ?>?v=<?= time() ?>" alt="آواتار">
        <?php else: ?><div class="pf-ava-ph">👤</div><?php endif; ?>
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

    <!-- ── تغییر موبایل با OTP ── -->
    <div class="fr-card" style="margin:0">
      <h3>شماره موبایل</h3>
      <?php if (isset($errors['mobile'])) echo '<div class="fr-alert err">' . e($errors['mobile']) . '</div>'; ?>
      <div style="font-size:13px;margin-bottom:10px">
        موبایل فعلی: <b dir="ltr"><?= fa_num($u['mobile']) ?></b>
        <?= (int)$u['mobile_verified'] ? '<span class="fr-chip ok" style="font-size:10px">تأییدشده ✓</span>' : '<span class="fr-chip warn" style="font-size:10px">تأییدنشده</span>' ?>
      </div>
      <?php if (!$mobchg): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="mobile_request">
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <input class="fr-input" name="mobile" placeholder="موبایل جدید 09xxxxxxxxx"
                 style="direction:ltr;flex:1;min-width:180px;text-align:right" inputmode="numeric">
          <button class="fr-btn ghost">ارسال کد تأیید</button>
        </div>
        <small style="display:block;color:var(--muted);margin-top:6px">به‌خاطر امنیت حساب، موبایل فقط با کد پیامکی قابل تغییر است.</small>
      </form>
      <?php else: ?>
      <div class="fr-alert info" style="font-size:12.5px">کد تأیید به <b dir="ltr"><?= fa_num($mobchg['m']) ?></b> ارسال شد (۱۰ دقیقه مهلت).</div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="mobile_verify">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input class="fr-input" name="code" placeholder="کد تأیید" style="direction:ltr;max-width:140px;text-align:center;letter-spacing:4px" inputmode="numeric">
          <button class="fr-btn">تأیید و ثبت</button>
          <button class="fr-btn ghost danger" name="act" value="mobile_cancel" formnovalidate>لغو</button>
        </div>
      </form>
      <?php endif; ?>
    </div>

    <!-- ── ترجیح‌های اطلاع‌رسانی (۱٫۷) ── -->
    <div class="fr-card" style="margin:0">
      <h3>🔔 اطلاع‌رسانی‌های پیامکی</h3>
      <?php
      $prefs = json_decode((string)($u['notif_prefs'] ?? ''), true) ?: [];
      $pRemind = array_key_exists('remind', $prefs) ? (int)$prefs['remind'] : 1;
      $pOffers = array_key_exists('offers', $prefs) ? (int)$prefs['offers'] : 1;
      ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save_notifs">
        <label class="tog" style="display:flex;gap:8px;align-items:flex-start;margin-bottom:10px">
          <input type="checkbox" name="notif_remind" value="1" <?= $pRemind ? 'checked' : '' ?>>
          <span style="font-size:12.5px"><b>یادآوری‌ها</b><br><small style="color:var(--muted)">پیامک یادآوری نزدیک‌شدن نوبت مشاوره یا رویداد</small></span>
        </label>
        <label class="tog" style="display:flex;gap:8px;align-items:flex-start;margin-bottom:12px">
          <input type="checkbox" name="notif_offers" value="1" <?= $pOffers ? 'checked' : '' ?>>
          <span style="font-size:12.5px"><b>خبرها و تخفیف‌ها</b><br><small style="color:var(--muted)">اطلاع از رویدادهای تازه، تخفیف‌ها و جشنواره‌ها</small></span>
        </label>
        <button class="fr-btn sm ghost">ذخیره ترجیح‌ها</button>
        <small style="display:block;color:var(--muted);margin-top:8px;line-height:1.9">پیامک‌های حساس (تأیید رزرو، لغو، عودت وجه) برای امنیت حساب همیشه ارسال می‌شوند.</small>
      </form>
    </div>

    <!-- ── اتصال شبکه‌های اجتماعی + اطلاع‌رسانی هوشمند (۲٫۳) ── -->
    <?php
      require_once __DIR__ . '/../includes/snotify.php';
      $SOC = fr_snotify_status((int)$u['id']);
      $SLOTS = fr_snotify_slots();
      $myNotifs = DB::all("SELECT channel,msg_kind,status,created_at FROM " . DB::t('notify_log') . " WHERE user_id=? ORDER BY id DESC LIMIT 6", [(int)$u['id']]);
      $kindFa = ['slot' => 'اطلاع‌رسانی روزانه', 'test' => 'پیام تست', 'connected' => 'اتصال', 'bulk' => 'اطلاع‌رسانی مجموعه', 'planner' => 'یادآور برنامه', 'campaign' => 'اطلاعیه و پیشنهاد ویژه', 'event' => 'یادآوری رویداد'];
      $chFa   = fr_snotify_channels();
    ?>
    <div class="fr-card" style="margin:0">
      <h3>📣 اطلاع‌رسانی در تلگرام و بله</h3>
      <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:2">
        فقط <b>نام کاربری اکانت</b> خود را بنویسید؛ ما به‌صورت خودکار آن را به شناسهٔ عددی تبدیل می‌کنیم.
        پس از ثبت نام کاربری، به ربات پیام <b dir="ltr">/start</b> (یا کد نمایشی) را بفرستید تا اتصال کامل شود — همین! 🦢
      </p>
      <?php if (isset($errors['social'])) echo '<div class="fr-alert err">' . e($errors['social']) . '</div>'; ?>
      <?php foreach ($SOC as $ch => $st): ?>
      <div class="soc-card">
        <div class="soc-head">
          <b><?= e($st['label']) ?></b>
          <?php if ($st['connected']): ?>
            <span class="soc-chip ok">✅ متصل — @<?= e($st['username'] ?: '—') ?> <small dir="ltr">(ID: <?= e(preg_replace('/(\d{3})\d+(\d{2})/', '$1…$2', $st['chat_id'])) ?>)</small></span>
          <?php elseif (!empty($st['pending'])): ?>
            <span class="soc-chip wait">🕓 در انتظار فعال‌سازی — کد: <b dir="ltr"><?= e($st['pending']['connect_code']) ?></b></span>
          <?php else: ?>
            <span class="soc-chip">⚪ متصل نیست</span>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px">
          <?php if (!$st['connected']): ?>
          <form method="post" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center;margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="social_connect"><input type="hidden" name="channel" value="<?= e($ch) ?>">
            <input class="fr-input" name="username" dir="ltr" style="flex:1;min-width:180px;text-align:left" maxlength="32"
                   value="<?= e($st['username'] ?: ($st['pending']['username'] ?? '')) ?>" placeholder="نام کاربری شما — مثل ali_mohammadi">
            <button class="fr-btn sm">ثبت و اتصال 🔗</button>
          </form>
          <?php else: ?>
          <form method="post" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="social_test"><input type="hidden" name="channel" value="<?= e($ch) ?>">
            <button class="fr-btn sm ghost">📩 تست اتصال</button>
          </form>
          <form method="post" style="margin:0" onsubmit="return confirm('اتصال <?= e($st['label']) ?> قطع شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="social_disconnect"><input type="hidden" name="channel" value="<?= e($ch) ?>">
            <button class="fr-btn sm danger ghost">قطع اتصال</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="soc-sched">
        <b>🕑 نوبت‌های اطلاع‌رسانی امروز (قالب ۲۴ ساعته):</b>
        <?php foreach ($SLOTS as $i => $t): ?><span class="soc-time"><?= fa_num($t) ?></span><?php endforeach; ?>
        <small style="color:var(--muted)">— مجموعاً <?= fa_num(count($SLOTS)) ?> اطلاع‌رسانی در روز، توسط مدیر مجموعه تنظیم می‌شود.</small>
      </div>

      <?php if ($myNotifs): ?>
      <details style="margin-top:10px" class="soc-hist">
        <summary style="cursor:pointer;font-size:12.5px;font-weight:700">🗒 آخرین اطلاع‌رسانی‌های ارسال‌شده برای من</summary>
        <div style="margin-top:8px;display:grid;gap:5px">
          <?php foreach ($myNotifs as $n): ?>
          <div style="display:flex;justify-content:space-between;gap:8px;font-size:12px;background:var(--bg);border-radius:10px;padding:6px 10px">
            <span><?= e($chFa[$n['channel']] ?? $n['channel']) ?> — <?= e($kindFa[$n['msg_kind']] ?? $n['msg_kind']) ?></span>
            <span style="color:<?= $n['status'] === 'sent' ? 'var(--c-accent)' : 'var(--c-danger)' ?>"><?= $n['status'] === 'sent' ? '✓ ارسال شد' : '✗ ناموفق' ?></span>
            <span style="color:var(--muted)"><?= fa_num(jdate('m/d H:i', strtotime($n['created_at']))) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
    </div>

    <!-- ── نام کاربری و رمز عبور (۲٫۲) ── -->
    <div class="fr-card" style="margin:0">
      <h3>🔐 نام کاربری و رمز عبور</h3>
      <p style="font-size:13px;color:var(--muted);margin-bottom:14px">
        با تنظیم نام کاربری و رمز، می‌توانید بدون دریافت پیامک و با <b>نام کاربری، موبایل یا کد ملی</b> به‌همراه رمز ثابت وارد شوید.
        <?php if (!empty($u['username'])): ?>نام کاربری فعلی شما: <b dir="ltr" style="letter-spacing:.5px"><?= e($u['username']) ?></b><?php endif; ?>
      </p>
      <?php if (isset($errors['password'])) echo '<div class="fr-alert err">' . e($errors['password']) . '</div>'; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="set_password">
        <div class="fr-field"><label>نام کاربری لاتین (اختیاری، یکتا)</label>
          <input class="fr-input" name="username" value="<?= e((string)($u['username'] ?? '')) ?>"
                 style="direction:ltr" maxlength="30" autocomplete="username"
                 placeholder="مثلاً sara.ahmadi — حروف، عدد، نقطه یا زیرخط"></div>
        <div class="fr-field"><label>رمز <?= $u['password_hash'] ? 'جدید' : '' ?> (حداقل ۸ کاراکتر)</label>
          <input class="fr-input" type="password" name="password" style="direction:ltr" autocomplete="new-password"></div>
        <div class="fr-field"><label>تکرار رمز</label>
          <input class="fr-input" type="password" name="password2" style="direction:ltr" autocomplete="new-password"></div>
        <button class="fr-btn">ذخیره نام کاربری و رمز</button>
        <small style="display:block;color:var(--muted);margin-top:8px">برای تغییر فقط یکی، همان را بنویسید و دیگری را خالی بگذارید.</small>
      </form>
    </div>

    <!-- اطلاعات حساب -->
    <div style="font-size:12.5px;color:var(--muted);padding:0 4px">
      📅 عضویت: <?= fa_num(jdate('j F Y', strtotime($u['created_at']))) ?> ·
      🕑 آخرین ورود: <?= $u['last_login_at'] ? fa_num(jdate('j F Y - H:i', strtotime($u['last_login_at']))) : '—' ?>
    </div>
  </div>
</div>
<?php panel_layout_end(); render_footer(); ?>
