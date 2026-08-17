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
    static $u = null; static $loaded = false;
    if ($loaded) return $u;
    $loaded = true;
    if (empty($_SESSION['user_id'])) return null;
    $u = DB::one('SELECT * FROM ' . DB::t('users') . " WHERE id=? AND status='active'",
                 [$_SESSION['user_id']]);
    if (!$u) { unset($_SESSION['user_id']); }
    // به‌روزرسانی آخرین بازدید نشست
    DB::run('UPDATE ' . DB::t('sessions') . ' SET last_seen=NOW() WHERE id=?', [session_id()]);
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
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    DB::insert('sessions', [
        'id' => session_id(), 'user_id' => $user['id'],
        'ip' => client_ip_bin(), 'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        'device' => client_device(),
    ]);
    DB::update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip_bin()],
               'id=?', [$user['id']]);
    log_activity('login', 'user', (int)$user['id'], ['method' => $method]);
}

/** خروج کاربر و بستن نشست در DB (ثبت ساعت خروج) */
function do_user_logout(): void
{
    if (!empty($_SESSION['user_id'])) {
        log_activity('logout', 'user', (int)$_SESSION['user_id']);
        DB::run('UPDATE ' . DB::t('sessions') . ' SET logout_at=NOW() WHERE id=? AND logout_at IS NULL',
                [session_id()]);
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
/**
 * ارسال OTP
 * @return array ['ok'=>bool,'message'=>string,'ttl'=>int,'debug_code'=>?string]
 */
function otp_send(string $mobile, string $purpose): array
{
    $mobile = en_num($mobile);
    if (!valid_mobile($mobile))            return ['ok'=>false,'message'=>'شماره موبایل معتبر نیست.','ttl'=>0];
    if (!rate_limit('otp:' . $mobile, 5, 600))
        return ['ok'=>false,'message'=>'تعداد درخواست‌های شما بیش از حد مجاز است؛ چند دقیقه دیگر تلاش کنید.','ttl'=>0];

    $len = (int)DB::setting('otp_length', '5');
    $ttl = (int)DB::setting('otp_ttl', '120');
    $code = (string)random_int(10 ** ($len - 1), 10 ** $len - 1);

    // باطل‌سازی کدهای قبلی
    DB::run('UPDATE ' . DB::t('otps') . ' SET used_at=NOW() WHERE mobile=? AND purpose=? AND used_at IS NULL',
            [$mobile, $purpose]);
    DB::insert('otps', [
        'mobile' => $mobile, 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'purpose' => $purpose, 'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        'ip' => client_ip_bin(),
    ]);

    $sms = new Kavenegar();
    $sent = $sms->sendEvent('otp', $mobile, ['code' => $code, '_tokens' => [$code]]);
    log_activity('otp_sent', 'otp', null, ['mobile' => $mobile, 'purpose' => $purpose, 'delivered' => $sent]);

    // اگر پنل پیامک فعال نبود، کد در پاسخ برگردد تا در حالت توسعه تست شود
    $resp = ['ok'=>true, 'message'=> $sent ? 'کد تأیید پیامک شد.' : 'کد تأیید صادر شد.', 'ttl'=>$ttl];
    if (!$sent && FR_ENV !== 'production') { $resp['debug_code'] = $code; }
    if (!$sent && DB::setting('kavenegar_enabled','0')!=='1') { $resp['debug_code'] = $code; }
    return $resp;
}

/**
 * تأیید OTP
 * @return array ['ok'=>bool,'message'=>string,'mobile'=>?string]
 */
function otp_verify(string $mobile, string $code, string $purpose): array
{
    $mobile = en_num($mobile); $code = en_num(trim($code));
    if (!rate_limit('otp_verify:' . $mobile, 8, 600))
        return ['ok'=>false,'message'=>'تلاش‌های ناموفق زیاد؛ کمی بعد تلاش کنید.'];

    $row = DB::one('SELECT * FROM ' . DB::t('otps') .
                   ' WHERE mobile=? AND purpose=? AND used_at IS NULL AND expires_at>NOW()
                     ORDER BY id DESC LIMIT 1', [$mobile, $purpose]);
    if (!$row) return ['ok'=>false,'message'=>'کد منقضی شده است؛ کد جدید دریافت کنید.'];
    if ((int)$row['tries'] >= 5) {
        DB::run('UPDATE ' . DB::t('otps') . ' SET used_at=NOW() WHERE id=?', [$row['id']]);
        return ['ok'=>false,'message'=>'کد مسدود شد؛ کد جدید دریافت کنید.'];
    }
    if (!password_verify($code, $row['code_hash'])) {
        DB::run('UPDATE ' . DB::t('otps') . ' SET tries=tries+1 WHERE id=?', [$row['id']]);
        return ['ok'=>false,'message'=>'کد واردشده صحیح نیست.'];
    }
    DB::run('UPDATE ' . DB::t('otps') . ' SET used_at=NOW() WHERE id=?', [$row['id']]);
    return ['ok'=>true,'message'=>'تأیید شد.','mobile'=>$mobile];
}

/* ---------------------------------------------------------------------------
 | یافتن/ساختن کاربر + ورود با رمز
 * -------------------------------------------------------------------------*/
function find_user_by(string $mobile): ?array
{
    return DB::one('SELECT * FROM ' . DB::t('users') . " WHERE mobile=? AND status!='deleted'", [$mobile]);
}

/** ورود با رمز عبور (نام کاربری یا موبایل یا کد ملی — ۲٫۲) */
function password_login(string $identity, string $password): array
{
    $identity = en_num(trim($identity));
    if (!rate_limit('login', 10, 600)) return ['ok'=>false,'message'=>'تلاش‌های زیاد؛ کمی بعد تلاش کنید.'];

    $u = DB::one('SELECT * FROM ' . DB::t('users') .
                 " WHERE (mobile=? OR national_code=? OR (username IS NOT NULL AND username<>'' AND username=?)) AND status='active'",
                 [$identity, $identity, $identity]);
    if (!$u || empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
        return ['ok'=>false,'message'=>'نام کاربری یا رمز عبور نادرست است.'];
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
