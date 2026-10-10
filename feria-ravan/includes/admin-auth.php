<?php
/**
 * ============================================================================
 *  احراز هویت مدیران — جدا از سشن کاربران، با Rate-Limit و لاگ کامل
 * ============================================================================
 */
declare(strict_types=1);

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

/** لاگین مدیر */
function admin_login(string $username, string $password): array
{
    if (!rate_limit('admin_login:' . $username, 8, 900)) {
        return ['ok'=>false,'message'=>'تلاش‌های ناموفق زیاد؛ ۱۵ دقیقه بعد تلاش کنید.'];
    }
    try {
        $a = DB::one('SELECT * FROM ' . DB::t('admins') . ' WHERE username=?', [$username]);
    } catch (Throwable $e) {
        $a = null;
    }
    if (!$a || (isset($a['status']) && $a['status'] !== 'active')) {
        log_activity('admin_login_fail', null, null, ['user'=>$username]);
        return ['ok'=>false,'message'=>'نام کاربری یا رمز اشتباه است.'];
    }
    $hash = (string)($a['password_hash'] ?? ($a['password'] ?? ''));
    if ($hash === '' || !password_verify($password, $hash)) {
        log_activity('admin_login_fail', null, null, ['user'=>$username]);
        return ['ok'=>false,'message'=>'نام کاربری یا رمز اشتباه است.'];
    }
    if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$a['id'];
    try {
        DB::insert('sessions', ['id'=>session_id(),'admin_id'=>$a['id'],'ip'=>client_ip_bin(),
            'user_agent'=>substr($_SERVER['HTTP_USER_AGENT']??'',0,255),'device'=>client_device()]);
    } catch (Throwable $e) {}
    try {
        DB::run('UPDATE ' . DB::t('admins') . ' SET last_login_at=NOW() WHERE id=?', [$a['id']]);
    } catch (Throwable $e) {
        try { DB::run('UPDATE ' . DB::t('admins') . ' SET last_login=NOW() WHERE id=?', [$a['id']]); } catch (Throwable $e2) {}
    }
    // نسخهٔ ۱٫۶: پنل مدیریت و پنل کاربری کاملاً جدا هستند — ورود مدیر فقط سشن مدیر می‌سازد
    // و مدیر برای استفاده از امکانات کاربری، جداگانه و با موبایل/رمز خودش وارد سایت می‌شود.
    // اتصال خودکار حساب کاربری متناظر به نشست مدیر جهت دسترسی بدون مانع به پنل کاربری
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
    log_activity('admin_login', 'admin', (int)$a['id']);
    return ['ok'=>true];
}

/** خروج مدیر */
function admin_logout(): void
{
    if (!empty($_SESSION['admin_id'])) {
        log_activity('admin_logout', 'admin', (int)$_SESSION['admin_id']);
        DB::run('UPDATE ' . DB::t('sessions') . ' SET logout_at=NOW() WHERE id=? AND logout_at IS NULL', [session_id()]);
    }
    $_SESSION = [];
    session_destroy();
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
