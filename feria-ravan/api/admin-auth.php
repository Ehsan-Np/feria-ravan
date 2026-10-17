<?php
/**
 * ============================================================================
 *  API احراز هویت مدیریت (AJAX): ورود با رمز عبور، ورود پیامکی OTP و بازیابی رمز
 * ============================================================================
 */
declare(strict_types=1);

// پاک‌سازی بافر خروجی برای تضمین بازگشت پاسخ خالص JSON
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
    require_once FR_ROOT . '/includes/admin-auth.php';
    require_once FR_ROOT . '/core/Kavenegar.php';

    // خواندن ورودی JSON یا POST فرم
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

        /* ---------- ۱. ورود استاندارد مدیر با رمز عبور ---------- */
        case 'login':
        case 'password_login': {
            $identity = trim((string)($in['identity'] ?? ($in['username'] ?? '')));
            $password = (string)($in['password'] ?? '');

            if ($identity === '' || $password === '') {
                json_out(['ok' => false, 'message' => 'نام کاربری و رمز عبور الزامی است.']);
            }

            $res = admin_login($identity, $password);
            json_out($res);
        }

        /* ---------- ۲. ارسال کد ورود یک‌بارمصرف پیامکی مدیر (OTP) ---------- */
        case 'otp_send': {
            $identity = trim((string)($in['identity'] ?? ($in['mobile'] ?? '')));
            if ($identity === '') {
                json_out(['ok' => false, 'message' => 'لطفاً شماره موبایل یا نام کاربری حساب مدیریت را وارد نمایید.']);
            }

            $res = admin_otp_login_send($identity);
            json_out($res);
        }

        /* ---------- ۳. بررسی کد ورود پیامکی و اتصال مدیر به پیشخوان ---------- */
        case 'otp_verify': {
            $code = trim(en_num((string)($in['code'] ?? '')));
            $mobile = !empty($in['mobile']) ? trim(en_num((string)$in['mobile'])) : null;

            if ($code === '') {
                json_out(['ok' => false, 'message' => 'لطفاً کد تأیید پیامک‌شده را وارد نمایید.']);
            }

            $res = admin_otp_login_verify($code, $mobile);
            json_out($res);
        }

        /* ---------- ۴. ارسال کد بازیابی رمز عبور مدیر (گام ۱) ---------- */
        case 'reset_send': {
            $identity = trim((string)($in['identity'] ?? ''));
            if ($identity === '') {
                json_out(['ok' => false, 'message' => 'لطفاً نام کاربری، شماره موبایل یا ایمیل حساب مدیریت را وارد نمایید.']);
            }

            $res = admin_password_reset_send($identity);
            json_out($res);
        }

        /* ---------- ۵. بررسی کد بازیابی و تنظیم رمز عبور جدید مدیر (گام ۲) ---------- */
        case 'reset_verify': {
            $code = trim(en_num((string)($in['code'] ?? '')));
            $newPass = (string)($in['new_password'] ?? '');
            $newPassConfirm = (string)($in['new_password_confirm'] ?? '');
            $identity = !empty($in['identity']) ? trim((string)$in['identity']) : (!empty($in['mobile']) ? trim((string)$in['mobile']) : null);

            if ($code === '') {
                json_out(['ok' => false, 'message' => 'لطفاً کد تأیید پیامک‌شده را وارد نمایید.']);
            }
            if ($newPass === '') {
                json_out(['ok' => false, 'message' => 'لطفاً رمز عبور جدید را وارد فرمایید.']);
            }
            if (mb_strlen($newPass) < 8) {
                json_out(['ok' => false, 'message' => 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.']);
            }
            if ($newPass !== $newPassConfirm) {
                json_out(['ok' => false, 'message' => 'رمز عبور جدید با تکرار آن یکسان نیست.']);
            }

            $res = admin_password_reset_verify($code, $newPass, $newPassConfirm, $identity);
            json_out($res);
        }

        default:
            json_out(['ok' => false, 'message' => 'عملیات درخواستی نامعتبر است: ' . $action]);
    }

} catch (Throwable $e) {
    error_log('feria: admin-auth API error: ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'خطای سرور: ' . $e->getMessage()]);
}
