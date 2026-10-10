<?php
/**
 * ============================================================================
 *  پذیرش دعوت سنجش تطابق دونفره و زوجین فریا روان
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/core/DyadAnalyzer.php';
fr_bootstrap();

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    flash('توکن دعوت یافت نشد یا معتبر نیست.', 'err');
    redirect(SITE_URL . '/index.php');
}

// ذخیره توکن در نشست جهت استفاده پس از ورود یا ثبت‌نام
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['active_dyad_token'] = $token;
}

$dyad = DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE token=?', [$token]);
if (!$dyad) {
    flash('پیوند دعوت مورد نظر یافت نشد یا منقضی گردیده است.', 'err');
    redirect(SITE_URL . '/index.php');
}

// اگر کاربر وارد نشده، ابتدا به صفحه ورود/ثبت‌نام می‌رود
if (!is_logged_in()) {
    flash('جهت پذیرش دعوت و شرکت در ارزیابی تطابق دونفره، لطفاً وارد حساب خود شوید یا ثبت‌نام فرمایید.', 'info');
    redirect(SITE_URL . '/login.php?back=' . urlencode(fr_panel_url('dyad.php?token=') . $token));
}

// اگر وارد شده، مستقیماً به کنترلر پنل هدایت می‌شود
redirect(fr_panel_url('dyad.php?token=') . urlencode($token));

