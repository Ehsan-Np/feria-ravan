<?php
/**
 * =============================================================================
 *  API بررسی کد تخفیف و محاسبهٔ پیش‌فاکتور (AJAX)
 *  ─ حالت تست روانشناسی: test_id        ─ حالت رویداد: event_id + تعداد همراه (cmp)
 *  پاسخ همواره: payable + تفکیک فارسی برای نمایش زنده در فرم
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/view.php';
require_once FR_ROOT . '/includes/commerce.php';
require_once FR_ROOT . '/includes/event-pricing.php';
fr_bootstrap();

$u = current_user();
if (!$u) json_out(['ok'=>false,'message'=>'ابتدا وارد حساب شوید.']);

$in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;

/* ---------------------------------------------------------------------------
 | حالت رویداد — پیش‌فاکتور کامل با همراهان + تخفیف رویداد + کد تخفیف
 * -------------------------------------------------------------------------*/
if (!empty($in['event_id'])) {
    $eventId = (int)$in['event_id'];
    $ev = DB::one('SELECT * FROM ' . DB::t('events') . " WHERE id=? AND status='active'", [$eventId]);
    if (!$ev) json_out(['ok'=>false,'message'=>'رویداد یافت نشد.']);

    $cmpCount  = max(0, min(50, (int)($in['cmp'] ?? 0)));      // همراهان پولی
    $freeCount = max(0, min(50, (int)($in['free'] ?? 0)));     // میهمان‌های رایگان (۲٫۱)
    $code      = trim((string)($in['code'] ?? ''));

    // مرحلهٔ ۱: فاکتور بدون کد (برای نمایش زنده همواره برگردانده می‌شود)
    $q = ev_quote($ev, $cmpCount, null, $freeCount);
    $out = [
        'ok'               => true,
        'event'            => true,
        'quote'            => [
            'main_price'=>$q['main_price'],'companion_unit'=>$q['companion_unit'],
            'companion_count'=>$q['companion_count'],'companion_total'=>$q['companion_total'],
            'free_allowed'=>$q['free_allowed'],'free_count'=>$q['free_count'],'seats_total'=>$q['seats_total'],
            'subtotal'=>$q['subtotal'],'ws_discount'=>$q['ws_discount'],'ws_desc'=>$q['ws_desc'],
            'discount_amount'=>$q['discount_amount'],'payable'=>$q['payable'],'lines'=>$q['lines'],
        ],
        'subtotal_f'       => toman($q['subtotal']),
        'ws_discount_f'    => toman($q['ws_discount']),
        'discount_f'       => toman($q['discount_amount']),
        'payable_f'        => toman($q['payable']),
        'code_ok'          => false,
        'message'          => '',
    ];

    // مرحلهٔ ۲: اگر کد وارد شده، اعتبارسنجی و بازمحاسبه
    if ($code !== '') {
        $v = validate_discount($code, 0, (int)$u['id'], $q['subtotal'] - $q['ws_discount'], $eventId);
        $out['message'] = $v['message'];
        if ($v['ok']) {
            $q2 = ev_quote($ev, $cmpCount, $v['discount'], $freeCount);
            $out['quote'] = [
                'main_price'=>$q2['main_price'],'companion_unit'=>$q2['companion_unit'],
                'companion_count'=>$q2['companion_count'],'companion_total'=>$q2['companion_total'],
                'free_allowed'=>$q2['free_allowed'],'free_count'=>$q2['free_count'],'seats_total'=>$q2['seats_total'],
                'subtotal'=>$q2['subtotal'],'ws_discount'=>$q2['ws_discount'],'ws_desc'=>$q2['ws_desc'],
                'code_discount'=>$q2['code_discount'],'code_desc'=>$q2['code_desc'],
                'discount_amount'=>$q2['discount_amount'],'payable'=>$q2['payable'],'lines'=>$q2['lines'],
            ];
            $out['code_ok']    = true;
            $out['discount_f'] = toman($q2['discount_amount']);
            $out['payable_f']  = toman($q2['payable']);
        }
    }
    json_out($out);
}

/* ---------------------------------------------------------------------------
 | حالت تست روانشناسی (رفتار قبلی)
 * -------------------------------------------------------------------------*/
$testId = (int)($in['test_id'] ?? 0);
$test   = DB::one('SELECT price_full, price_sale FROM ' . DB::t('tests') . " WHERE id=? AND status='active'", [$testId]);
if (!$test) json_out(['ok'=>false,'message'=>'تست روانشناسی یافت نشد.']);

// نسخهٔ ۲٫۸: کد تخفیف روی «قیمت نهایی» (پس از تخفیف قیمت‌گذاری) اعمال می‌شود
$_deal  = fr_price_deal((int)$test['price_full'], isset($test['price_sale']) ? (int)$test['price_sale'] : null);
$v = validate_discount((string)($in['code'] ?? ''), $testId, (int)$u['id'], $_deal['final']);
$v['amount_f']  = toman($_deal['final']);
$v['payable_f'] = toman($v['payable']);
json_out($v);
