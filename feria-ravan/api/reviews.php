<?php
/**
 * ============================================================================
 *  API دیدگاه و امتیاز (AJAX) — نسخهٔ ۲٫۰
 *  ─ submit: ثبت/ویرایش دیدگاه کاربرِ شرکت‌کننده (۱..۵ ستاره + متن)
 *  ─ own: واکشی دیدگاه فعلی کاربر برای بازنمایش در فرم
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/reviews.php';
fr_bootstrap();

$in     = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($in['action'] ?? '');
$ctx    = (string)($in['context'] ?? '');
$refId  = (int)($in['ref_id'] ?? 0);

if (!isset(FR_REVIEW_CTXS[$ctx]) || $refId <= 0) {
    json_out(['ok' => false, 'message' => 'درخواست نامعتبر است.']);
}

$u = current_user();
if (!$u) {
    json_out(['ok' => false, 'login' => true, 'message' => 'برای ثبت دیدگاه ابتدا وارد حساب شوید.']);
}

switch ($action) {

    /* ---------- ثبت/ویرایش دیدگاه یا پرسش (۲٫۲ — برای همهٔ کاربران واردشده) ---------- */
    case 'submit': {
        $kind   = (string)($in['kind'] ?? 'review') === 'question' ? 'question' : 'review';
        $rating = (int)($in['rating'] ?? 0);
        if ($kind !== 'question' && ($rating < 1 || $rating > 5)) {
            json_out(['ok' => false, 'message' => 'لطفاً امتیاز ۱ تا ۵ ستاره را انتخاب کنید؛ یا نوع «پرسش» را بزنید.']);
        }
        $r = fr_review_submit($ctx, $refId, $u, $rating, (string)($in['body'] ?? ''), $kind);
        if ($r['ok']) {
            $r['stats'] = fr_review_stats($ctx, $refId);
            $r['name']  = fr_review_form_name($u);
        }
        json_out($r);
    }

    /* ---------- دیدگاه فعلی خودم ---------- */
    case 'own': {
        $r = DB::one('SELECT rating, body, status, kind FROM ' . DB::t('reviews') .
                     ' WHERE context=? AND ref_id=? AND user_id=?', [$ctx, $refId, (int)$u['id']]);
        json_out(['ok' => true,
                  'review' => $r ?: null,
                  'allowed' => fr_review_participated($ctx, $refId, (int)$u['id'])]);
    }

    default:
        http_response_code(400);
        json_out(['ok' => false, 'message' => 'درخواست نامعتبر است.']);
}
