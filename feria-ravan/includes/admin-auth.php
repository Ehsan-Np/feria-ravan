<?php
/**
 * ============================================================================
 *  احراز هویت مدیران — جدا از سشن کاربران، با Rate-Limit و لاگ کامل
 *  ─ ورود با رمز عبور (نام کاربری / موبایل / ایمیل)
 *  ─ ورود با رمز یک‌بارمصرف پیامکی (Admin OTP Login)
 *  ─ بازیابی و تغییر رمز عبور مدیران با پیامک (Admin Password Reset)
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** مدیر جاری */
function current_admin(): ?array
{
    static $cached = null;
    static $cachedId = null;
    if (empty($_SESSION['admin_id'])) {
        $cached = null;
        $cachedId = null;
        return null;
    }
    $adminId = (int)$_SESSION['admin_id'];
    if ($cachedId === $adminId && $cached !== null) {
        return $cached;
    }
    try {
        $row = DB::one('SELECT * FROM ' . DB::t('admins') . ' WHERE id=?', [$adminId]);
        if ($row) {
            // بررسی وضعیت فعال بودن حساب مدیر
            if (isset($row['status']) && $row['status'] !== 'active') {
                $cached = null;
                $cachedId = null;
            } else {
                if (!isset($row['role_id'])) $row['role_id'] = 0;
                if (!isset($row['status'])) $row['status'] = 'active';
                $cached = $row;
                $cachedId = $adminId;
            }
        } else {
            $cached = null;
            $cachedId = null;
        }
    } catch (Throwable $e) {
        $cached = null;
        $cachedId = null;
    }
    if (!$cached) unset($_SESSION['admin_id']);
    return $cached;
}

/**
 * اجبار لاگین مدیر — نسخهٔ ۲٫۱: علاوه‌بر نقش‌های قدیمی (legacy)،
 * نقش‌های سفارشی با capها بر اساس «فایل ادمینِ در حال اجرا» کنترل می‌شوند.
 */
function require_admin(array $roles = ['superadmin','super','manager','admin','support']): array
{
    $a = current_admin();
    if (!$a) { redirect(SITE_URL . '/admin/login.php?back=' . urlencode($_SERVER['REQUEST_URI'] ?? '')); }
    /* نقش سفارشی (۲٫۱): capها بر نقش قدیمی اولویت دارند */
    if (!empty($a['role_id'])) {
        require_once __DIR__ . '/roles.php';
        if (fr_admin_page_allowed($a, $_SERVER['SCRIPT_NAME'] ?? '')) return $a;
        redirect(SITE_URL . '/admin/login.php?back=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    /* نقش قدیمی (legacy) — رفتار قبلی دقیقاً حفظ می‌شود */
    if (in_array($a['role'], $roles, true)) return $a;
    redirect(SITE_URL . '/admin/login.php?back=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
}

/** یافتن رکورد مدیر بر اساس نام کاربری، موبایل یا ایمیل */
function admin_find_by_identity(string $identity): ?array
{
    $clean = trim($identity);
    if ($clean === '') return null;
    $cleanMob = clean_mobile($clean);
    try {
        return DB::one(
            'SELECT * FROM ' . DB::t('admins') . '
             WHERE (username = ?
                OR (mobile = ? AND mobile IS NOT NULL AND mobile != "")
                OR (email = ? AND email IS NOT NULL AND email != ""))
             ORDER BY (status = "active") DESC, id ASC LIMIT 1',
            [$clean, $cleanMob, $clean]
        );
    } catch (Throwable $e) {
        return null;
    }
}

/** برقراری نشست مدیریت و ثبت لاگ‌های امنیتی */
function do_admin_login(array $a, string $method = 'password'): void
{
    if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$a['id'];
    try {
        DB::insert('sessions', [
            'id'         => session_id(),
            'admin_id'   => $a['id'],
            'ip'         => client_ip_bin(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'device'     => client_device(),
        ]);
    } catch (Throwable $e) {}
    try {
        DB::run('UPDATE ' . DB::t('admins') . ' SET last_login_at=NOW() WHERE id=?', [$a['id']]);
    } catch (Throwable $e) {
        try { DB::run('UPDATE ' . DB::t('admins') . ' SET last_login=NOW() WHERE id=?', [$a['id']]); } catch (Throwable $e2) {}
    }
    // اتصال خودکار حساب کاربری متناظر به نشست مدیر جهت دسترسی به پنل کاربری
    try {
        if (!empty($a['user_id'])) {
            $uRow = DB::one('SELECT id FROM ' . DB::t('users') . " WHERE id=? AND status='active'", [$a['user_id']]);
            if ($uRow) $_SESSION['user_id'] = (int)$uRow['id'];
        } elseif (!empty($a['mobile'])) {
            $uRow = DB::one('SELECT id FROM ' . DB::t('users') . " WHERE mobile=? AND status='active'", [$a['mobile']]);
            if ($uRow) {
                $_SESSION['user_id'] = (int)$uRow['id'];
                DB::update('admins', ['user_id' => $uRow['id']], 'id=?', [$a['id']]);
            }
        }
    } catch (Throwable $e) {}
    log_activity($method === 'otp' ? 'admin_login_otp' : 'admin_login', 'admin', (int)$a['id']);
}

/** لاگین مدیر با نام کاربری، موبایل یا ایمیل و رمز عبور */
function admin_login(string $identity, string $password): array
{
    $cleanIdent = trim($identity);
    if (!rate_limit('admin_login:' . $cleanIdent, 8, 900)) {
        return ['ok' => false, 'message' => 'تلاش‌های ناموفق زیاد؛ ۱۵ دقیقه بعد تلاش کنید.'];
    }
    $a = admin_find_by_identity($cleanIdent);
    if (!$a || (isset($a['status']) && $a['status'] !== 'active')) {
        log_activity('admin_login_fail', null, null, ['user' => $cleanIdent]);
        return ['ok' => false, 'message' => 'نام کاربری یا رمز اشتباه است.'];
    }
    $hash = (string)($a['password_hash'] ?? ($a['password'] ?? ''));
    if ($hash === '' || !password_verify($password, $hash)) {
        log_activity('admin_login_fail', null, null, ['user' => $cleanIdent]);
        return ['ok' => false, 'message' => 'نام کاربری یا رمز اشتباه است.'];
    }
    do_admin_login($a, 'password');
    return ['ok' => true, 'redirect' => SITE_URL . '/admin/dashboard.php'];
}

/** ارسال کد تأیید ورود پیامکی مدیر (گام ۱ ورود OTP) */
function admin_otp_login_send(string $identity): array
{
    $identity = trim($identity);
    if ($identity === '') {
        return ['ok' => false, 'message' => 'لطفاً شماره موبایل یا نام کاربری مدیر را وارد فرمایید.'];
    }
    $a = admin_find_by_identity($identity);
    if (!$a) {
        return ['ok' => false, 'message' => 'مدیری با این مشخصات یافت نشد.'];
    }
    if (isset($a['status']) && $a['status'] !== 'active') {
        return ['ok' => false, 'message' => 'حساب این مدیر غیرفعال یا مسدود است؛ با مدیر ارشد تماس بگیرید.'];
    }
    $mobile = clean_mobile((string)($a['mobile'] ?? ''));
    if (!valid_mobile($mobile)) {
        return ['ok' => false, 'message' => 'شماره موبایل برای این حساب کاربری ثبت نشده است؛ جهت ورود با نام کاربری و رمز اقدام نمایید.'];
    }
    if (!rate_limit('admin_otp_send:' . $mobile, 5, 600)) {
        return ['ok' => false, 'message' => 'تعداد درخواست‌های کد بیش از حد مجاز است؛ لطفاً چند دقیقه دیگر تلاش کنید.'];
    }

    $res = otp_send($mobile, 'admin_login');
    if (!$res['ok']) {
        return $res;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['pending_admin_otp_login'] = [
        'admin_id' => (int)$a['id'],
        'mobile'   => $mobile,
        'exp'      => time() + 300,
    ];

    $masked = substr($mobile, 0, 4) . '***' . substr($mobile, -4);
    return [
        'ok'            => true,
        'step'          => 2,
        'mobile'        => $mobile,
        'masked_mobile' => $masked,
        'ttl'           => $res['ttl'] ?? 120,
        'debug_code'    => $res['debug_code'] ?? null,
        'message'       => 'کد تأیید ورود مدیریت به شمارهٔ ' . $masked . ' پیامک شد 📩',
    ];
}

/** تأیید کد و ورود مدیر (گام ۲ ورود OTP) */
function admin_otp_login_verify(string $code, ?string $mobile = null): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $pending = $_SESSION['pending_admin_otp_login'] ?? null;
    $targetMobile = $mobile ? clean_mobile($mobile) : clean_mobile((string)($pending['mobile'] ?? ''));

    if (!$targetMobile || !valid_mobile($targetMobile)) {
        return ['ok' => false, 'message' => 'نشست ورود منقضی شده است؛ لطفاً مجدداً درخواست کد ارسال فرمایید.'];
    }

    if (!rate_limit('admin_otp_verify:' . $targetMobile, 8, 600)) {
        return ['ok' => false, 'message' => 'تلاش‌های ناموفق بیش از حد مجاز است؛ لطفاً کمی بعد تلاش کنید.'];
    }

    $v = otp_verify($targetMobile, $code, 'admin_login');
    if (!$v['ok']) {
        return $v;
    }

    $adminId = (int)($pending['admin_id'] ?? 0);
    $admin = null;
    if ($adminId > 0) {
        $admin = DB::one('SELECT * FROM ' . DB::t('admins') . ' WHERE id=? AND status="active"', [$adminId]);
    }
    if (!$admin) {
        $admin = DB::one('SELECT * FROM ' . DB::t('admins') . ' WHERE mobile=? AND status="active" ORDER BY id ASC LIMIT 1', [$targetMobile]);
    }
    if (!$admin) {
        return ['ok' => false, 'message' => 'حساب کاربری فعال مدیر یافت نشد.'];
    }

    unset($_SESSION['pending_admin_otp_login']);
    do_admin_login($admin, 'otp');

    return [
        'ok'       => true,
        'message'  => 'ورود با موفقیت انجام شد ✅',
        'redirect' => SITE_URL . '/admin/dashboard.php',
    ];
}

/** ارسال کد تأیید بازیابی رمز عبور مدیر (گام ۱ ریست پسورد) */
function admin_password_reset_send(string $identity): array
{
    $identity = trim($identity);
    if ($identity === '') {
        return ['ok' => false, 'message' => 'لطفاً نام کاربری، شماره موبایل یا ایمیل مدیر را وارد فرمایید.'];
    }

    $a = admin_find_by_identity($identity);
    if (!$a) {
        return ['ok' => false, 'message' => 'حساب مدیر فعالی با این مشخصات یافت نشد.'];
    }
    if (isset($a['status']) && $a['status'] !== 'active') {
        return ['ok' => false, 'message' => 'حساب این مدیر غیرفعال یا مسدود است؛ لطفاً با مدیر ارشد تماس بگیرید.'];
    }

    $mobile = clean_mobile((string)($a['mobile'] ?? ''));
    if (!valid_mobile($mobile)) {
        return ['ok' => false, 'message' => 'شماره موبایل برای این حساب مدیر ثبت نشده است؛ جهت بازنشانی رمز با مدیر ارشد تماس بگیرید.'];
    }

    if (!rate_limit('admin_pwd_reset_send:' . $mobile, 5, 600)) {
        return ['ok' => false, 'message' => 'تعداد درخواست‌های بازیابی بیش از حد مجاز است؛ لطفاً چند دقیقه دیگر تلاش فرمایید.'];
    }

    $res = otp_send($mobile, 'admin_reset');
    if (!$res['ok']) {
        return $res;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['pending_admin_pwd_reset'] = [
        'admin_id' => (int)$a['id'],
        'mobile'   => $mobile,
        'exp'      => time() + 600,
    ];

    $masked = substr($mobile, 0, 4) . '***' . substr($mobile, -4);
    return [
        'ok'            => true,
        'step'          => 2,
        'mobile'        => $mobile,
        'masked_mobile' => $masked,
        'ttl'           => $res['ttl'] ?? 120,
        'debug_code'    => $res['debug_code'] ?? null,
        'message'       => 'کد تأیید بازیابی رمز مدیر به شمارهٔ ' . $masked . ' پیامک شد 📩',
    ];
}

/** تأیید کد و تغییر رمز عبور مدیر (گام ۲ ریست پسورد) */
function admin_password_reset_verify(string $code, string $newPassword, string $newPasswordConfirm, ?string $identity = null): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $pending = $_SESSION['pending_admin_pwd_reset'] ?? null;

    $adminId = 0;
    $mobile = '';

    if ($pending && ($pending['exp'] ?? 0) >= time()) {
        $adminId = (int)($pending['admin_id'] ?? 0);
        $mobile = (string)($pending['mobile'] ?? '');
    }

    // در صورت نبود سشن یا افت کوکی، امکان اعتبارسنجی با شناسه مدیر
    if ((!$adminId || !$mobile) && !empty($identity)) {
        $a = admin_find_by_identity($identity);
        if ($a && isset($a['status']) && $a['status'] === 'active' && !empty($a['mobile'])) {
            $adminId = (int)$a['id'];
            $mobile = (string)$a['mobile'];
        }
    }

    if (!$adminId || !$mobile) {
        return ['ok' => false, 'message' => 'مهلت درخواست بازیابی به پایان رسیده است؛ لطفاً مجدداً درخواست کد دهید.'];
    }

    if (mb_strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.'];
    }
    if ($newPassword !== $newPasswordConfirm) {
        return ['ok' => false, 'message' => 'رمز عبور جدید با تکرار آن یکسان نیست.'];
    }

    if (!rate_limit('admin_reset_verify:' . $mobile, 8, 600)) {
        return ['ok' => false, 'message' => 'تلاش‌های ناموفق بیش از حد مجاز است؛ لطفاً کمی بعد تلاش فرمایید.'];
    }

    $v = otp_verify($mobile, $code, 'admin_reset');
    if (!$v['ok']) {
        return $v;
    }

    $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
    DB::update('admins', [
        'password_hash' => $hash,
        'password'      => $hash,
    ], 'id=?', [$adminId]);

    unset($_SESSION['pending_admin_pwd_reset']);
    log_activity('admin_password_reset', 'admin', $adminId);

    return [
        'ok'       => true,
        'message'  => 'رمز عبور مدیر با موفقیت به‌روزرسانی شد ✅ اکنون می‌توانید با رمز جدید وارد شوید.',
        'redirect' => SITE_URL . '/admin/login.php',
    ];
}

/** خروج مدیر */
function admin_logout(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['admin_id'])) {
            log_activity('admin_logout', 'admin', (int)$_SESSION['admin_id']);
            DB::run('UPDATE ' . DB::t('sessions') . ' SET logout_at=NOW() WHERE id=? AND logout_at IS NULL', [session_id()]);
        }
        $_SESSION = [];
        @session_destroy();
    }
}

/**
 * نگهبان دسترسی مدیر (Guard) — بررسی لاگین و دسترسیِ اختیاری با Cap
 * در صورت عدم لاگین ریدایرکت، و در صورت عدم صلاحیت ۴۰۳ صادر می‌کند.
 */
function admin_guard(?string $cap = null): array
{
    $adm = require_admin();
    if ($cap !== null && function_exists('fr_admin_can') && !fr_admin_can($adm, $cap)) {
        http_response_code(403);
        exit('دسترسی مجاز نیست — شما مجوز دسترسی به این بخش را ندارید.');
    }
    return $adm;
}

/* ---------------------------------------------------------------------------
 | خروجی Excel (میان‌برهای فارسی‌ساز)
 * -------------------------------------------------------------------------*/
/**
 * دانلود CSV با BOM (در Excel فارسی، راست‌چین و بدون به‌هم‌ریختگی باز می‌شود)
 */
function export_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM برای Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $r) { fputcsv($out, $r); }
    fclose($out);
    exit;
}

/**
 * دانلود با فرمت HTML-Excel (.xls) — جدول راست‌چین فارسی با استایل
 */
function export_xls(string $filename, string $title, array $headers, array $rows): never
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"></head>'
       . '<body><table border="1" dir="rtl">';
    echo '<tr><td colspan="' . count($headers) . '" style="background:#1e3a6e;color:#fff;font-weight:bold">' . e($title) . '</td></tr>';
    echo '<tr style="background:#eaf0f8;font-weight:bold">';
    foreach ($headers as $h) echo '<th>' . e((string)$h) . '</th>';
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $c) echo '<td>' . e((string)$c) . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

