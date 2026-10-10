<?php
/**
 * ============================================================================
 *  احراز هویت کاربران — ورود با موبایل/کدملی، OTP پیامکی، رمز عبور اختیاری
 *  ─ نسخهٔ ۱٫۵: ثبت‌نام پیش‌فرض فعال (فقط نام/نام‌خانوادگی/موبایل) + سنجش تکمیل
 *    پروفایل + پیوند دوطرفهٔ سشن مدیر↔کاربر (ادمین همان پنل کاربری را هم دارد)
 * ============================================================================
 */
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 | سیاست عضویت — به‌صورت پیش‌فرض فعال؛ از تنظیمات قابل خاموش‌کردن است
 * -------------------------------------------------------------------------*/
function fr_register_enabled(): bool
{
    return (string)DB::setting('register_enabled', '1') === '1';
}

/* ---------------------------------------------------------------------------
 | درصد تکمیل پروفایل — مبنای «اطلاعیهٔ تکمیل پروفایل» در پنل کاربر
 | فیلدهای اصلی: نام، نام‌خانوادگی، کد ملی، ایمیل، تاریخ تولد، عنوان شغلی
 * -------------------------------------------------------------------------*/
/** @return array{percent:int, missing:string[]} */
function fr_profile_completion(array $u): array
{
    $fields = [
        'first_name'    => 'نام',
        'last_name'     => 'نام خانوادگی',
        'national_code' => 'کد ملی',
        'email'         => 'ایمیل',
        'birth_date'    => 'تاریخ تولد',
        'job_title'     => 'عنوان شغلی',
    ];
    $missing = [];
    $filled  = 0;
    foreach ($fields as $col => $label) {
        $v = $u[$col] ?? null;
        if ($v === null || $v === '') $missing[] = $label; else $filled++;
    }
    $total = count($fields);
    return ['percent' => (int)round($filled * 100 / $total), 'missing' => $missing];
}

/* ---------------------------------------------------------------------------
 | نسخهٔ ۱٫۶ — جداسازی کامل سشن‌ها:
 | ورود کاربر (OTP/رمز) هرگز سشن مدیر نمی‌سازد و ورود مدیر در /admin/ هم
 | سشن کاربر نمی‌سازد. مدیر سایت برای استفاده از امکانات کاربری، با همان
 | موبایلِ خودش (ثبت‌شده در نصب‌کننده) و پیامک/رمز، مثل هر کاربر دیگری وارد می‌شود.
 * -------------------------------------------------------------------------*/

/* ---------------------------------------------------------------------------
 | وضعیت کاربر جاری
 * -------------------------------------------------------------------------*/
/** کاربر لاگین‌شده (با کش درون‌درخواستی) */
function current_user(): ?array
{
    static $u = null;
    static $cachedUid = null;

    if (empty($_SESSION['user_id'])) {
        // دسترسی یکپارچه: اگر مدیر وارد پیشخوان شده باشد، حساب کاربری متناظر به‌صورت خودکار متصل می‌شود
        if (!empty($_SESSION['admin_id'])) {
            try {
                $adm = DB::one('SELECT * FROM ' . DB::t('admins') . ' WHERE id=?', [$_SESSION['admin_id']]);
                if ($adm && (!isset($adm['status']) || $adm['status'] === 'active')) {
                    $user = null;
                    if (!empty($adm['user_id'])) {
                        $user = DB::one('SELECT * FROM ' . DB::t('users') . " WHERE id=? AND status='active'", [$adm['user_id']]);
                    }
                    if (!$user && !empty($adm['mobile'])) {
                        $user = DB::one('SELECT * FROM ' . DB::t('users') . " WHERE mobile=? AND status='active'", [$adm['mobile']]);
                    }
                    if (!$user) {
                        $fn = !empty($adm['display_name']) ? $adm['display_name'] : $adm['username'];
                        $mob = (!empty($adm['mobile']) && preg_match('/^09\d{9}$/', $adm['mobile']))
                            ? $adm['mobile']
                            : ('0999' . str_pad((string)$adm['id'], 7, '0', STR_PAD_LEFT));
                        $existingUser = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE mobile=?', [$mob]);
                        if ($existingUser) {
                            $user = $existingUser;
                            DB::update('admins', ['user_id' => $user['id']], 'id=?', [$adm['id']]);
                        } else {
                            $userData = [
                                'mobile' => $mob,
                                'first_name' => $fn,
                                'last_name' => 'مدیریت',
                                'status' => 'active',
                                'mobile_verified' => 1
                            ];
                            $passHash = $adm['password_hash'] ?? ($adm['password'] ?? null);
                            if (!empty($passHash)) $userData['password_hash'] = $passHash;
                            $newUid = (int)DB::insert('users', $userData);
                            DB::update('admins', ['user_id' => $newUid], 'id=?', [$adm['id']]);
                            $user = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [$newUid]);
                        }
                    }
                    if ($user) {
                        $_SESSION['user_id'] = (int)$user['id'];
                        $cachedUid = (int)$user['id'];
                        $u = $user;
                        try {
                            DB::run("INSERT INTO " . DB::t('sessions') . " (id, user_id, ip, user_agent, device, login_at, last_seen)
                                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                                     ON DUPLICATE KEY UPDATE last_seen=NOW(), user_id=VALUES(user_id)",
                                    [session_id(), $u['id'], client_ip_bin(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), client_device()]);
                        } catch (Throwable $e) {}
                        return $u;
                    }
                }
            } catch (Throwable $e) {}
        }
        $u = null;
        $cachedUid = null;
        return null;
    }

    $uid = (int)$_SESSION['user_id'];
    if ($cachedUid === $uid && $u !== null) {
        return $u;
    }

    try {
        $u = DB::one('SELECT * FROM ' . DB::t('users') . " WHERE id=? AND status='active'", [$uid]);
        if (!$u) {
            unset($_SESSION['user_id']);
            $cachedUid = null;
            return null;
        }
        $cachedUid = $uid;
        // ثبت یا به‌روزرسانی ایمن آخرین بازدید نشست کاربر
        try {
            DB::run("INSERT INTO " . DB::t('sessions') . " (id, user_id, ip, user_agent, device, login_at, last_seen)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE last_seen=NOW(), user_id=VALUES(user_id)",
                    [session_id(), $u['id'], client_ip_bin(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), client_device()]);
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
        $u = null;
        $cachedUid = null;
    }

    return $u;
}

/** اجبار به لاگین — در غیر این صورت هدایت به صفحه ورود */
function require_user(): array
{
    $u = current_user();
    if (!$u) {
        $back = urlencode($_SERVER['REQUEST_URI'] ?? '/panel/');
        redirect(SITE_URL . '/auth.php?back=' . $back);
    }
    return $u;
}

/* ---------------------------------------------------------------------------
 | ورود/خروج
 * -------------------------------------------------------------------------*/
/** ثبت نشست کاربر (پس از احراز موفق) */
function do_user_login(array $user, string $method = 'otp'): void
{
    if (session_status() === PHP_SESSION_ACTIVE) @session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    try {
        DB::insert('sessions', [
            'id' => session_id(), 'user_id' => $user['id'],
            'ip' => client_ip_bin(), 'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'device' => client_device(),
        ]);
    } catch (Throwable $e) {}
    DB::update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip_bin()],
               'id=?', [$user['id']]);
    log_activity('login', 'user', (int)$user['id'], ['method' => $method]);
}

/** خروج کاربر و بستن نشست در DB (ثبت ساعت خروج) */
function do_user_logout(): void
{
    if (!empty($_SESSION['user_id'])) {
        log_activity('logout', 'user', (int)$_SESSION['user_id']);
        try {
            DB::run('UPDATE ' . DB::t('sessions') . ' SET logout_at=NOW() WHERE id=? AND logout_at IS NULL',
                    [session_id()]);
        } catch (Throwable $e) {}
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------------------------------------------------------------------------
 | OTP — ارسال/تأیید کد یک‌بارمصرف پیامکی
 * -------------------------------------------------------------------------*/
/** تضمین خودکار وجود جدول otps با ساختار مقاوم */
function ensure_otps_table(): void
{
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        $p = DB_PREFIX;
        DB::run("CREATE TABLE IF NOT EXISTS `{$p}otps` (
          `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `mobile` VARCHAR(20) NOT NULL,
          `code` VARCHAR(10) NOT NULL,
          `code_hash` VARCHAR(255) NULL,
          `purpose` VARCHAR(30) NOT NULL DEFAULT 'login',
          `expires_at` DATETIME NOT NULL,
          `used_at` DATETIME NULL,
          `tries` TINYINT UNSIGNED NOT NULL DEFAULT 0,
          `ip` VARBINARY(16) NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY `ix_mobile_purpose` (`mobile`, `purpose`),
          KEY `ix_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
}

/**
 * ارسال OTP
 * @return array ['ok'=>bool,'message'=>string,'ttl'=>int,'debug_code'=>?string,'sms_sent'=>bool]
 */
function otp_send(string $mobile, string $purpose = 'login'): array
{
    $mobile = clean_mobile($mobile);
    if (!valid_mobile($mobile)) {
        return ['ok' => false, 'message' => 'شماره موبایل معتبر نیست (مثال: ۰۹۱۲۳۴۵۶۷۸۹).', 'ttl' => 0];
    }
    if (!rate_limit('otp:' . $mobile, 5, 600)) {
        return ['ok' => false, 'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است؛ لطفاً چند دقیقه دیگر تلاش کنید.', 'ttl' => 0];
    }

    ensure_otps_table();

    $len = (int)DB::setting('otp_length', '5');
    if ($len < 4 || $len > 6) $len = 5;
    $ttl = (int)DB::setting('otp_ttl', '120');
    if ($ttl < 60) $ttl = 120;

    $code = (string)random_int(10 ** ($len - 1), 10 ** $len - 1);

    // باطل‌سازی کدهای قبلی
    DB::run('UPDATE ' . DB::t('otps') . ' SET used_at=NOW() WHERE mobile=? AND purpose=? AND used_at IS NULL',
            [$mobile, $purpose]);

    // نگاشت منعطف فیلدهای ذخیره‌سازی OTP متناسب با ستون‌های موجود در جدول
    static $otpCols = null;
    if ($otpCols === null) {
        try {
            $otpCols = array_column(DB::all('SHOW COLUMNS FROM ' . DB::t('otps')), 'Field');
        } catch (Throwable $e) {
            $otpCols = ['mobile', 'code', 'purpose', 'expires_at', 'ip'];
        }
    }

    $otpData = [
        'mobile'     => $mobile,
        'purpose'    => $purpose,
        'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        'ip'         => client_ip_bin(),
    ];
    if (in_array('code', $otpCols, true)) {
        $otpData['code'] = $code;
    }
    if (in_array('code_hash', $otpCols, true)) {
        $otpData['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
    }

    DB::insert('otps', $otpData);

    $sms = new Kavenegar();
    $isSmsConfigured = $sms->isEnabled();

    $sent = false;
    if ($isSmsConfigured) {
        $sent = $sms->sendEvent('otp', $mobile, ['code' => $code, '_tokens' => [$code]]);
    }

    log_activity('otp_sent', 'otp', null, [
        'mobile'          => $mobile,
        'purpose'         => $purpose,
        'delivered'       => $sent,
        'gateway_enabled' => $isSmsConfigured,
    ]);

    if ($sent) {
        return [
            'ok'       => true,
            'message'  => 'کد تأیید به شمارهٔ شما پیامک شد 📩',
            'ttl'      => $ttl,
            'sms_sent' => true,
        ];
    }

    // اگر پیامک به دلیل عدم پیکربندی یا خطای موقت ارائه‌دهنده ارسال نشد
    $resp = [
        'ok'       => true,
        'ttl'      => $ttl,
        'sms_sent' => false,
    ];

    if (!$isSmsConfigured) {
        $resp['message']    = 'سامانهٔ پیامک هنوز فعال نشده است؛ از کد آزمایشی نمایش‌داده‌شده برای ورود استفاده کنید.';
        $resp['debug_code'] = $code;
    } else {
        $resp['message']    = 'ارسال پیامک با کندی مواجه شد؛ کد تأیید ورود برای شما صادر شد.';
        $resp['debug_code'] = $code;
    }

    return $resp;
}

/**
 * تأیید OTP
 * @return array ['ok'=>bool,'message'=>string,'mobile'=>?string]
 */
function otp_verify(string $mobile, string $code, string $purpose = 'login'): array
{
    $mobile = clean_mobile($mobile);
    $code   = trim(en_num($code));
    if (!rate_limit('otp_verify:' . $mobile, 8, 600)) {
        return ['ok' => false, 'message' => 'تلاش‌های ناموفق بیش از حد مجاز است؛ کمی بعد تلاش کنید.'];
    }

    ensure_otps_table();

    $row = DB::one('SELECT * FROM ' . DB::t('otps') .
                   ' WHERE mobile=? AND purpose=? AND used_at IS NULL AND expires_at>NOW()
                     ORDER BY id DESC LIMIT 1', [$mobile, $purpose]);
    if (!$row) {
        return ['ok' => false, 'message' => 'کد منقضی شده یا نامعتبر است؛ مجدداً درخواست کد دهید.'];
    }

    if ((int)$row['tries'] >= 5) {
        DB::run('UPDATE ' . DB::t('otps') . ' SET used_at=NOW() WHERE id=?', [$row['id']]);
        return ['ok' => false, 'message' => 'کد منقضی شد؛ بیش از ۵ بار اشتباه وارد شده است. لطفاً کد جدید دریافت نمایید.'];
    }

    $valid = false;
    if (!empty($row['code_hash'])) {
        $valid = password_verify($code, (string)$row['code_hash']);
    }
    if (!$valid && isset($row['code'])) {
        $valid = hash_equals((string)$row['code'], $code);
    }

    if (!$valid) {
        DB::run('UPDATE ' . DB::t('otps') . ' SET tries=tries+1 WHERE id=?', [$row['id']]);
        return ['ok' => false, 'message' => 'کد واردشده صحیح نیست. لطفاً مجدداً بررسی کنید.'];
    }

    DB::run('UPDATE ' . DB::t('otps') . ' SET used_at=NOW() WHERE id=?', [$row['id']]);
    return ['ok' => true, 'message' => 'تأیید شد.', 'mobile' => $mobile];
}

/* ---------------------------------------------------------------------------
 | یافتن/ساختن کاربر + ورود با رمز
 * -------------------------------------------------------------------------*/
function find_user_by(string $mobile): ?array
{
    $mobile = clean_mobile($mobile);
    return DB::one('SELECT * FROM ' . DB::t('users') . " WHERE mobile=? AND status!='deleted'", [$mobile]);
}

/**
 * یافتن کاربر با هر یک از شناسه‌های هویتی: شماره موبایل، کد ملی یا نام کاربری (آیدی)
 */
function find_user_by_identity(string $identity): ?array
{
    $clean = en_num(trim($identity));
    if ($clean === '') return null;

    // ۱. بررسی بر مبنای شماره موبایل
    $mob = clean_mobile($clean);
    if (valid_mobile($mob)) {
        $u = DB::one('SELECT * FROM ' . DB::t('users') . " WHERE mobile=? AND status!='deleted'", [$mob]);
        if ($u) return $u;
    }

    // ۲. بررسی بر مبنای کد ملی ۱۰ رقمی
    if (valid_national_code($clean)) {
        $u = DB::one('SELECT * FROM ' . DB::t('users') . " WHERE national_code=? AND status!='deleted'", [$clean]);
        if ($u) return $u;
    }

    // ۳. بررسی بر مبنای نام کاربری / آیدی
    $u = DB::one('SELECT * FROM ' . DB::t('users') . " WHERE LOWER(username)=LOWER(?) AND status!='deleted'", [$clean]);
    if ($u) return $u;

    // ۴. جستجوی عمومی بر روی فیلدهای شناسایی
    return DB::one('SELECT * FROM ' . DB::t('users') . " WHERE (mobile=? OR national_code=? OR username=?) AND status!='deleted'", [$clean, $clean, $clean]);
}

/** ورود با رمز عبور (نام کاربری یا موبایل یا کد ملی — ۲٫۲) */
function password_login(string $identity, string $password): array
{
    $identity = en_num(trim($identity));
    if (!rate_limit('login', 10, 600)) return ['ok'=>false,'message'=>'تعداد تلاش‌های ناموفق بیش از حد مجاز است؛ لطفاً کمی بعد تلاش کنید.'];

    $u = find_user_by_identity($identity);
    if (!$u) {
        return ['ok'=>false,'message'=>'حسابی با این مشخصات یافت نشد؛ لطفاً ثبت‌نام کنید.'];
    }
    if ($u['status'] !== 'active') {
        return ['ok'=>false,'message'=>'حساب کاربری شما مسدود یا غیرفعال است؛ با پشتیبانی در تماس باشید.'];
    }
    if (empty($u['password_hash'])) {
        return [
            'ok'          => false,
            'no_password' => true,
            'mobile'      => $u['mobile'],
            'message'     => 'برای این حساب هنوز رمز عبوری تعیین نشده است؛ لطفاً با «کد یک‌بارمصرف پیامکی» وارد شوید یا از گزینه «بازیابی رمز» استفاده نمایید.'
        ];
    }
    if (!password_verify($password, $u['password_hash'])) {
        return ['ok'=>false,'message'=>'نام کاربری، شماره تلفن یا رمز عبور نادرست است.'];
    }
    do_user_login($u, 'password');
    return ['ok'=>true,'message'=>'خوش آمدید 🦢'];
}

/**
 * اعتبارسنجی نام کاربری (۲٫۲) — لاتین، با حرف شروع شود، حروف/عدد/نقطه/زیرخط، ۴ تا ۳۰ نویسه
 */
function valid_fr_username(string $uname): bool
{
    return (bool)preg_match('/^[A-Za-z][A-Za-z0-9._]{3,29}$/', $uname);
}

/** آیا نام کاربری برای این کاربرِ دیگر تکراری است؟ (۲٫۲) */
function fr_username_taken(string $uname, int $exceptUserId = 0): bool
{
    $id = (int)DB::val('SELECT id FROM ' . DB::t('users') . " WHERE username=?", [$uname]);
    return $id > 0 && $id !== $exceptUserId;
}

/** فیلدهای ثبت‌نام فعال و مرتب */
function register_fields(): array
{
    return DB::all('SELECT * FROM ' . DB::t('profile_fields') .
                   ' WHERE active=1 AND show_in_register=1 ORDER BY sort_order');
}

/** اعتبارسنجی و ساخت آرایهٔ save برای ثبت/ویرایش پروفایل */
function validate_profile_input(array $fields, array $input, array &$errors): array
{
    $data = [];
    foreach ($fields as $f) {
        $val = trim((string)($input[$f['name']] ?? ''));
        $val = en_num($val) ? (in_array($f['type'], ['number','mobile','nationalcode']) ? en_num($val) : $val) : $val;
        if ($f['required'] && $val === '') { $errors[$f['name']] = $f['label'] . ' الزامی است.'; continue; }
        if ($val !== '') {
            if ($f['validation'] && !preg_match('/' . $f['validation'] . '/', $val)) {
                $errors[$f['name']] = $f['label'] . ' معتبر نیست.'; continue;
            }
            if ($f['type'] === 'mobile' && !valid_mobile($val)) { $errors[$f['name']] = 'موبایل معتبر نیست.'; continue; }
            if ($f['type'] === 'nationalcode' && !valid_national_code($val)) { $errors[$f['name']] = 'کد ملی معتبر نیست.'; continue; }
        }
        if ($f['type'] === 'date') {
            $mysql = jalali_to_mysql($val);
            if ($val !== '' && !$mysql) { $errors[$f['name']] = 'فرمت تاریخ صحیح نیست (مثلاً ۱۳۷۰/۰۵/۱۶).'; continue; }
            $val = $mysql ?: null;
        }
        $data[$f['name']] = $val === '' ? null : $val;
    }
    return $data;
}
