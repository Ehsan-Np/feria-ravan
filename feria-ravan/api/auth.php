<?php
/**
 * ============================================================================
 *  API احراز هویت (AJAX): ورود، ثبت‌نام تفکیک‌شده و بازیابی رمز عبور
 * ============================================================================
 */
declare(strict_types=1);

// پاک‌سازی کامل بافر برای تضمین خروجی خالص JSON
while (ob_get_level() > 0) {
    @ob_end_clean();
}
ob_start();

try {
    require_once __DIR__ . '/../config.php';
    require_once FR_ROOT . '/includes/db.php';
    require_once FR_ROOT . '/includes/functions.php';
    require_once FR_ROOT . '/includes/security.php';
    require_once FR_ROOT . '/includes/auth.php';
    require_once FR_ROOT . '/core/Kavenegar.php';

    // خواندن ورودی JSON یا فرم
    $rawInput = (string)file_get_contents('php://input');
    $in = @json_decode($rawInput, true);
    if (!is_array($in)) {
        $in = $_POST;
    }

    if (!empty($in['_csrf']) && is_string($in['_csrf'])) {
        $GLOBALS['FR_JSON_CSRF'] = $in['_csrf'];
    }

    fr_bootstrap();

    $action = (string)($in['action'] ?? '');

    switch ($action) {

        /* ---------- ۱. ارسال کد تأیید ورود پیامکی ---------- */
        case 'send_otp': {
            $mobile = clean_mobile((string)($in['mobile'] ?? ''));
            if (!valid_mobile($mobile)) {
                json_out(['ok' => false, 'message' => 'شماره موبایل واردشده نامعتبر است (مثال: ۰۹۱۲۳۴۵۶۷۸۹).', 'ttl' => 0]);
            }

            $purpose = (string)($in['purpose'] ?? 'login');
            $existingUser = find_user_by($mobile);

            // اگر در فرم ورود است اما شماره ثبت‌نام نشده:
            if ($purpose === 'login' && !$existingUser) {
                json_out([
                    'ok'             => false,
                    'not_registered' => true,
                    'mobile'         => $mobile,
                    'message'        => 'این شماره موبایل در سیستم ثبت‌نام نشده است. لطفاً فرم ثبت‌نام را تکمیل فرمایید.',
                    'ttl'            => 0,
                ]);
            }

            // اگر در فرم ثبت‌نام است و شماره قبلاً وجود دارد:
            if ($purpose === 'register' && $existingUser) {
                json_out([
                    'ok'                 => false,
                    'already_registered' => true,
                    'mobile'             => $mobile,
                    'message'            => 'این شماره موبایل قبلاً ثبت‌نام شده است؛ لطفاً وارد حساب کاربری خود شوید.',
                    'ttl'                => 0,
                ]);
            }

            $res = otp_send($mobile, $purpose);
            json_out($res);
        }

        /* ---------- ۲. تأیید کد پیامکی و ورود به حساب ---------- */
        case 'verify_otp': {
            $mobile  = clean_mobile((string)($in['mobile'] ?? ''));
            $code    = trim(en_num((string)($in['code'] ?? '')));
            $purpose = (string)($in['purpose'] ?? 'login');

            $res = otp_verify($mobile, $code, $purpose);
            if (!$res['ok']) {
                json_out($res);
            }

            $user = find_user_by($mobile);
            if (!$user) {
                json_out([
                    'ok'             => false,
                    'not_registered' => true,
                    'mobile'         => $mobile,
                    'message'        => 'این شماره موبایل در سیستم ثبت نشده است؛ لطفاً ابتدا ثبت‌نام کنید.',
                ]);
            }

            if ($user['status'] !== 'active') {
                json_out(['ok' => false, 'message' => 'حساب کاربری شما غیرفعال یا مسدود است؛ لطفاً با پشتیبانی در تماس باشید.']);
            }

            DB::update('users', ['mobile_verified' => 1], 'id=?', [$user['id']]);
            do_user_login($user, 'otp');

            $back = fr_safe_back((string)($in['back'] ?? ''));
            json_out([
                'ok'       => true,
                'message'  => user_fullname($user) . ' عزیز، خوش آمدید 🦢',
                'redirect' => $back !== '' ? $back : fr_panel_url(),
            ]);
        }

        /* ---------- ۳. شروع ثبت‌نام کامل با آیدی و رمز عبور (گام ۱) ---------- */
        case 'register_start': {
            if (!fr_register_enabled()) {
                json_out(['ok' => false, 'message' => 'در حال حاضر ثبت‌نام کاربران جدید غیرفعال است؛ با پشتیبانی در تماس باشید.']);
            }

            $mobile = clean_mobile((string)($in['mobile'] ?? ''));
            if (!valid_mobile($mobile)) {
                json_out(['ok' => false, 'message' => 'شماره موبایل نامعتبر است (مثال: ۰۹۱۲۳۴۵۶۷۸۹).']);
            }

            // بررسی عدم تکراری بودن شماره
            if (find_user_by($mobile)) {
                json_out([
                    'ok'                 => false,
                    'already_registered' => true,
                    'mobile'             => $mobile,
                    'message'            => 'حسابی با این شماره موبایل قبلاً ثبت شده است؛ لطفاً وارد حساب خود شوید.',
                ]);
            }

            // بررسی نام و نام خانوادگی
            $fullName = trim((string)($in['full_name'] ?? ''));
            $fullName = trim(preg_replace('/\s+/u', ' ', $fullName));
            if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 160) {
                json_out(['ok' => false, 'message' => 'نام و نام خانوادگی را کامل بنویسید (حداقل ۳ حرف).']);
            }
            if (!preg_match('/\s/u', $fullName)) {
                json_out(['ok' => false, 'message' => 'لطفاً نام خانوادگی را نیز وارد کنید (نام و نام خانوادگی با فاصله).']);
            }
            [$fn, $ln] = fr_split_fullname($fullName);

            // بررسی آیدی / نام کاربری (اختیاری یا اجباری)
            $username = trim(en_num((string)($in['username'] ?? '')));
            if ($username !== '') {
                if (!valid_fr_username($username)) {
                    json_out(['ok' => false, 'message' => 'آیدی باید با حرف انگلیسی شروع شده و بین ۴ تا ۳۰ کاراکتر (شامل حروف، ارقام، نقطه یا زیرخط) باشد (مثال: sara_m).']);
                }
                if (fr_username_taken($username)) {
                    json_out(['ok' => false, 'message' => 'این آیدی (نام کاربری) قبلاً انتخاب شده است؛ لطفاً آیدی دیگری برگزینید.']);
                }
            } else {
                $username = null;
            }

            // بررسی رمز عبور و تکرار آن
            $password = (string)($in['password'] ?? '');
            $passwordConfirm = (string)($in['password_confirm'] ?? '');
            if (mb_strlen($password) < 6) {
                json_out(['ok' => false, 'message' => 'رمز عبور باید حداقل ۶ نویسه (کاراکتر) باشد.']);
            }
            if ($password !== $passwordConfirm) {
                json_out(['ok' => false, 'message' => 'رمز عبور با تکرار آن یکسان نیست.']);
            }

            // بررسی کد ملی (اختیاری)
            $nationalCode = trim(en_num((string)($in['national_code'] ?? '')));
            if ($nationalCode !== '') {
                if (!valid_national_code($nationalCode)) {
                    json_out(['ok' => false, 'message' => 'کد ملی ۱۰ رقمی واردشده معتبر نیست.']);
                }
                $ncExists = DB::val('SELECT id FROM ' . DB::t('users') . " WHERE national_code=? AND status!='deleted'", [$nationalCode]);
                if ($ncExists) {
                    json_out(['ok' => false, 'message' => 'این کد ملی قبلاً برای حساب دیگری ثبت شده است.']);
                }
            } else {
                $nationalCode = null;
            }

            // ارسال پیامک کد تأیید OTP
            $otpRes = otp_send($mobile, 'register');
            if (!$otpRes['ok']) {
                json_out($otpRes);
            }

            // نگهداری موقت اطلاعات در سشن امن تا مرحلهٔ بعد
            $_SESSION['pending_full_register'] = [
                'full_name'     => $fullName,
                'first_name'    => $fn,
                'last_name'     => $ln,
                'mobile'        => $mobile,
                'username'      => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'national_code' => $nationalCode,
                'exp'           => time() + 600,
            ];

            json_out([
                'ok'         => true,
                'step'       => 2,
                'mobile'     => $mobile,
                'ttl'        => $otpRes['ttl'] ?? 120,
                'debug_code' => $otpRes['debug_code'] ?? null,
                'message'    => 'کد تأیید عضویت به شمارهٔ ' . $mobile . ' پیامک شد 📩',
            ]);
        }

        /* ---------- ۴. تکمیل نهایی ثبت‌نام پس از ورود کد (گام ۲) ---------- */
        case 'register_complete': {
            $pend = $_SESSION['pending_full_register'] ?? null;
            if (!$pend || ($pend['exp'] ?? 0) < time()) {
                json_out(['ok' => false, 'message' => 'مهلت ثبت‌نام گذشته است؛ لطفاً فرم را دوباره تکمیل فرمایید.']);
            }

            $mobile = (string)$pend['mobile'];
            $code   = trim(en_num((string)($in['code'] ?? '')));

            $res = otp_verify($mobile, $code, 'register');
            if (!$res['ok']) {
                json_out($res);
            }

            // جلوگیری از ثبت تکراری هم‌زمان
            if (($u = find_user_by($mobile))) {
                unset($_SESSION['pending_full_register']);
                do_user_login($u, 'register');
                json_out(['ok' => true, 'message' => 'خوش آمدید 🦢', 'redirect' => fr_panel_url()]);
            }

            $id = DB::insert('users', [
                'first_name'      => mb_substr($pend['first_name'], 0, 80),
                'last_name'       => mb_substr($pend['last_name'], 0, 80),
                'mobile'          => $mobile,
                'username'        => $pend['username'],
                'password_hash'   => $pend['password_hash'],
                'national_code'   => $pend['national_code'],
                'mobile_verified' => 1,
                'status'          => 'active',
            ]);
            unset($_SESSION['pending_full_register']);

            $user = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [$id]);
            do_user_login($user, 'register');

            try {
                require_once __DIR__ . '/../includes/contacts.php';
                fr_contact_sync_user(['id' => $id, 'mobile' => $mobile, 'first_name' => $pend['first_name'], 'last_name' => $pend['last_name']]);
            } catch (Throwable $e) {}

            try {
                (new Kavenegar())->sendEvent('register', $mobile, ['name' => $pend['first_name']]);
            } catch (Throwable $e) {}

            log_activity('register', 'user', $id, ['method' => 'full_with_credentials']);

            $back = fr_safe_back((string)($in['back'] ?? ''));
            json_out([
                'ok'       => true,
                'message'  => $pend['first_name'] . ' عزیز، حساب شما با موفقیت ساخته شد 🎉',
                'redirect' => $back !== '' ? $back : fr_panel_url('?welcome=1'),
            ]);
        }

        /* ---------- ۵. بازیابی رمز عبور: استعلام هویت و ارسال کد (گام ۱) ---------- */
        case 'forgot_password_send': {
            $identity = trim((string)($in['identity'] ?? ''));
            if ($identity === '') {
                json_out(['ok' => false, 'message' => 'لطفاً شماره تلفن، نام کاربری یا کد ملی خود را وارد فرمایید.']);
            }

            if (!rate_limit('pwd_reset_send', 5, 600)) {
                json_out(['ok' => false, 'message' => 'تعداد درخواست‌های بازیابی بیش از حد مجاز است؛ لطفاً چند دقیقه دیگر تلاش کنید.']);
            }

            $user = find_user_by_identity($identity);
            if (!$user) {
                json_out(['ok' => false, 'message' => 'حسابی با این مشخصات (شماره تلفن، نام کاربری یا کد ملی) یافت نشد.']);
            }

            if ($user['status'] !== 'active') {
                json_out(['ok' => false, 'message' => 'حساب کاربری شما غیرفعال یا مسدود است؛ با پشتیبانی در تماس باشید.']);
            }

            $mobile = (string)$user['mobile'];
            $res = otp_send($mobile, 'reset');
            if (!$res['ok']) {
                json_out($res);
            }

            $_SESSION['pending_pwd_reset'] = [
                'user_id' => (int)$user['id'],
                'mobile'  => $mobile,
                'exp'     => time() + 600,
            ];

            $masked = substr($mobile, 0, 4) . '***' . substr($mobile, -4);
            json_out([
                'ok'            => true,
                'step'          => 2,
                'mobile'        => $mobile,
                'masked_mobile' => $masked,
                'ttl'           => $res['ttl'] ?? 120,
                'debug_code'    => $res['debug_code'] ?? null,
                'message'       => 'کد تأیید بازیابی رمز به شمارهٔ ' . $masked . ' پیامک شد 📩',
            ]);
        }

        /* ---------- ۶. بازیابی رمز عبور: اعتبارسنجی کد و تغییر رمز جدید (گام ۲) ---------- */
        case 'forgot_password_verify': {
            $pend = $_SESSION['pending_pwd_reset'] ?? null;
            if (!$pend || ($pend['exp'] ?? 0) < time()) {
                json_out(['ok' => false, 'message' => 'مهلت درخواست بازیابی به پایان رسیده است؛ لطفاً مجدداً درخواست کد دهید.']);
            }

            $mobile = (string)$pend['mobile'];
            $code   = trim(en_num((string)($in['code'] ?? '')));
            $newPass = (string)($in['new_password'] ?? '');
            $newPassConfirm = (string)($in['new_password_confirm'] ?? '');

            if (mb_strlen($newPass) < 6) {
                json_out(['ok' => false, 'message' => 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.']);
            }
            if ($newPass !== $newPassConfirm) {
                json_out(['ok' => false, 'message' => 'رمز عبور جدید با تکرار آن یکسان نیست.']);
            }

            $v = otp_verify($mobile, $code, 'reset');
            if (!$v['ok']) {
                json_out($v);
            }

            $uid = (int)$pend['user_id'];
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            DB::update('users', [
                'password_hash'   => $hash,
                'mobile_verified' => 1,
                'updated_at'      => date('Y-m-d H:i:s'),
            ], 'id=?', [$uid]);
            unset($_SESSION['pending_pwd_reset']);

            log_activity('password_reset', 'user', $uid, ['method' => 'multi_identity_otp']);

            $user = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [$uid]);
            do_user_login($user, 'reset');

            $back = fr_safe_back((string)($in['back'] ?? ''));
            json_out([
                'ok'       => true,
                'message'  => 'رمز عبور شما با موفقیت به‌روزرسانی شد ✨ خوش آمدید 🦢',
                'redirect' => $back !== '' ? $back : fr_panel_url(),
            ]);
        }

        /* ---------- ۷. ورود با رمز عبور (شماره موبایل، نام کاربری یا کد ملی) ---------- */
        case 'password_login': {
            $identity = (string)($in['identity'] ?? '');
            $password = (string)($in['password'] ?? '');
            $res = password_login($identity, $password);
            if ($res['ok']) {
                $back = fr_safe_back((string)($in['back'] ?? ''));
                $res['redirect'] = $back !== '' ? $back : fr_panel_url();
            }
            json_out($res);
        }

        /* ---------- ۸. سازگاری رو به عقب (ثبت‌نام سریع قدیمی) ---------- */
        case 'register_name': {
            $pend = $_SESSION['pending_register'] ?? null;
            if (!$pend || ($pend['exp'] ?? 0) < time()) {
                json_out(['ok'=>false,'message'=>'مهلت ثبت‌نام گذشته است؛ دوباره کد دریافت کنید.']);
            }
            $full = trim((string)($in['full_name'] ?? ''));
            if ($full === '') $full = trim((string)($in['first_name'] ?? '') . ' ' . (string)($in['last_name'] ?? ''));
            [$fn, $ln] = fr_split_fullname($full);
            $mobile = (string)$pend['mobile'];

            $id = DB::insert('users', [
                'mobile'=>$mobile, 'first_name'=>mb_substr($fn,0,80), 'last_name'=>mb_substr($ln,0,80),
                'mobile_verified'=>1,
            ]);
            unset($_SESSION['pending_register']);
            $user = ['id'=>$id, 'mobile'=>$mobile, 'first_name'=>$fn, 'last_name'=>$ln];
            do_user_login($user, 'otp');

            try {
                require_once __DIR__ . '/../includes/contacts.php';
                fr_contact_sync_user(['id'=>$id, 'mobile'=>$mobile, 'first_name'=>$fn, 'last_name'=>$ln]);
            } catch (Throwable $e) {}

            try { (new Kavenegar())->sendEvent('register', $mobile, ['name' => $fn]); } catch (Throwable $e) {}
            log_activity('register', 'user', $id, ['method'=>'otp_min']);

            $back = fr_safe_back((string)($in['back'] ?? ''));
            json_out(['ok'=>true,'message'=>$fn . ' عزیز، خوش آمدید 🎉','redirect'=>$back !== '' ? $back : fr_panel_url()]);
        }

        default:
            json_out(['ok' => false, 'message' => 'درخواست نامعتبر است.']);
    }
} catch (Throwable $e) {
    error_log("Feria API Auth Critical: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    json_out([
        'ok'      => false,
        'message' => 'خطایی در پردازش اطلاعات ورود رخ داده است؛ لطفاً چند لحظه بعد دوباره تلاش کنید.'
    ]);
}
