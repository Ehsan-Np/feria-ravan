<?php
/**
 * ============================================================================
 *  API احراز هویت (AJAX): ارسال OTP / تأیید OTP / ورود با رمز
 * ============================================================================
 */
declare(strict_types=1);

// پاک‌سازی کامل هرگونه بافر برای اطمینان ۱۰۰٪ از ارسال خروجی JSON تمیز
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

        /* ---------- ارسال کد یک‌بارمصرف ---------- */
        case 'send_otp': {
            $mobile = clean_mobile((string)($in['mobile'] ?? ''));
            if (!valid_mobile($mobile)) {
                json_out(['ok' => false, 'message' => 'شماره موبایل واردشده نامعتبر است (مثال: ۰۹۱۲۳۴۵۶۷۸۹).', 'ttl' => 0]);
            }
            /* اگر عضویت بسته است و موبایل قبلاً حساب ندارد، هزینهٔ پیامک بیهوده نرواند */
            if (!find_user_by($mobile) && !fr_register_enabled()) {
                json_out(['ok' => false, 'message' => 'این شماره حسابی ندارد و عضویت جدید فعلاً غیرفعال است؛ با پشتیبانی در تماس باشید.', 'ttl' => 0]);
            }
            $res = otp_send($mobile, 'login');
            json_out($res);
        }

        /* ---------- تأیید کد → ورود یا شروع ثبت‌نام سبک ---------- */
        case 'verify_otp': {
            $mobile = clean_mobile((string)($in['mobile'] ?? ''));
            $code   = trim(en_num((string)($in['code'] ?? '')));
            $res = otp_verify($mobile, $code, 'login');
            if (!$res['ok']) json_out($res);

            $user = find_user_by($mobile);
            if (!$user) {
                /* مسیر ثبت‌نام: به‌صورت پیش‌فرض فعال است؛ فقط نام/نام‌خانوادگی پرسیده می‌شود (مرحلهٔ بعد) */
                if (!fr_register_enabled()) {
                    json_out(['ok'=>false,'message'=>'در حال حاضر عضویت کاربران جدید غیرفعال است؛ با پشتیبانی در تماس باشید.']);
                }
                $_SESSION['pending_register'] = ['mobile'=>$mobile, 'exp'=>time()+600];
                json_out(['ok'=>true,'needs_register'=>true,'mobile'=>$mobile,
                          'message'=>'حسابی با این موبایل نیست؛ برای ساخت حساب، نام خود را وارد کنید.']);
            }

            DB::update('users', ['mobile_verified' => 1], 'id=?', [$user['id']]);
            unset($_SESSION['pending_register']);
            do_user_login($user, 'otp');

            $back = fr_safe_back((string)($in['back'] ?? ''));
            json_out(['ok'=>true,'message'=>user_fullname($user) . ' عزیز، خوش آمدید 🦢',
                      'redirect'=>$back !== '' ? $back : fr_panel_url()]);
        }

        /* ---------- تکمیل ثبت‌نام سبک — فقط «نام و نام خانوادگی» یک فیلد (۱٫۷) ---------- */
        case 'register_name': {
            $pend = $_SESSION['pending_register'] ?? null;
            if (!$pend || ($pend['exp'] ?? 0) < time()) {
                json_out(['ok'=>false,'message'=>'مهلت ثبت‌نام گذشته است؛ دوباره کد دریافت کنید.']);
            }
            if (!fr_register_enabled()) {
                json_out(['ok'=>false,'message'=>'عضویت کاربران جدید غیرفعال است.']);
            }
            // فیلد یکپارچهٔ جدید؛ با فیلدهای جدای قدیمی هم سازگار است
            $full = trim((string)($in['full_name'] ?? ''));
            if ($full === '') $full = trim((string)($in['first_name'] ?? '') . ' ' . (string)($in['last_name'] ?? ''));
            $full = trim(preg_replace('/\s+/u', ' ', $full));
            if (mb_strlen($full) < 3 || mb_strlen($full) > 200) {
                json_out(['ok'=>false,'message'=>'نام و نام خانوادگی را کامل و صحیح بنویسید (مثل: سارا محمدی).']);
            }
            if (!preg_match('/\s/u', $full)) {
                json_out(['ok'=>false,'message'=>'لطفاً نام خانوادگی را هم وارد کنید (نام و نام خانوادگی در یک فیلد).']);
            }
            [$fn, $ln] = fr_split_fullname($full);
            if ($fn === '' || $ln === '') json_out(['ok'=>false,'message'=>'نام و نام خانوادگی را کامل بنویسید.']);

            $mobile = (string)$pend['mobile'];
            if (($u = find_user_by($mobile))) {                 // مسابقه: کاربر هم‌زمان ساخته شده
                unset($_SESSION['pending_register']);
                do_user_login($u, 'otp');
                json_out(['ok'=>true,'message'=>'خوش آمدید 🦢','redirect'=>fr_panel_url()]);
            }

            $id = DB::insert('users', [
                'mobile'=>$mobile, 'first_name'=>mb_substr($fn,0,100), 'last_name'=>mb_substr($ln,0,100),
                'mobile_verified'=>1,
            ]);
            unset($_SESSION['pending_register']);
            $user = ['id'=>$id, 'mobile'=>$mobile, 'first_name'=>$fn, 'last_name'=>$ln];
            do_user_login($user, 'otp');

            /* ۲٫۷ — ثبت خودکار در دفترچهٔ مخاطبین (مدیر بر اساس کانال‌ها می‌تواند برایش پیام بفرستد) */
            try {
                require_once __DIR__ . '/../includes/contacts.php';
                fr_contact_sync_user(['id'=>$id, 'mobile'=>$mobile, 'first_name'=>$fn, 'last_name'=>$ln]);
            } catch (Throwable $e) { /* بی‌صدا */ }

            try {
                (new Kavenegar())->sendEvent('register', $mobile, ['name' => $fn]);
            } catch (Throwable $e) {}
            log_activity('register', 'user', $id, ['method'=>'otp_min']);

            $back = fr_safe_back((string)($in['back'] ?? ''));
            json_out(['ok'=>true,'message'=>$fn . ' عزیز، حساب شما ساخته شد 🎉',
                      'redirect'=>$back !== '' ? $back : fr_panel_url('?welcome=1')]);
        }

        /* ---------- ورود با رمز عبور (موبایل یا کد ملی) ---------- */
        case 'password_login': {
            $res = password_login((string)($in['identity'] ?? ''), (string)($in['password'] ?? ''));
            if ($res['ok']) {
                $u = find_user_by(en_num((string)($in['identity'] ?? '')));
                if (!$u && valid_national_code((string)($in['identity'] ?? ''))) {
                    $u = DB::one('SELECT * FROM ' . DB::t('users') . " WHERE national_code=? AND status='active'", [en_num((string)$in['identity'])]);
                }
                $back = fr_safe_back((string)($in['back'] ?? ''));
                $res['redirect'] = $back !== '' ? $back : fr_panel_url();
            }
            json_out($res);
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
