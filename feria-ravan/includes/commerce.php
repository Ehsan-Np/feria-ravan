<?php
/**
 * ============================================================================
 *  لایهٔ تجارت — اعتبارسنجی کد تخفیف با تمام حالت‌ها + ساخت سفارش و پرداخت
 * ============================================================================
 */
declare(strict_types=1);

if (defined('FR_ROOT')) {
    require_once FR_ROOT . '/core/Zarinpal.php';
    require_once FR_ROOT . '/core/Kavenegar.php';
}

/**
 * بررسی و محاسبهٔ کد تخفیف
 * حالت‌ها: درصدی/مبلغی، سقف تخفیف، حداقل خرید، محدود به تست، محدود به رویداد،
 *          محدود به کاربر، بازهٔ زمانی، سقف استفاده کل، سقف استفاده هر کاربر
 * @param int $eventId  شناسهٔ رویداد (۰ = سفارش تست روانشناسی)
 * @return array ['ok'=>bool,'message'=>string,'discount_amt'=>int,'payable'=>int]
 */
function validate_discount(string $code, int $testId, int $userId, int $amount, int $eventId = 0): array
{
    $code = strtoupper(trim(en_num($code)));
    if ($code === '') return ['ok'=>false,'message'=>'کد تخفیف وارد نشده است.','discount_amt'=>0,'payable'=>$amount];

    $d = DB::one('SELECT * FROM ' . DB::t('discounts') . " WHERE code=? AND status='active'", [$code]);
    if (!$d) return ['ok'=>false,'message'=>'کد تخفیف نامعتبر است.','discount_amt'=>0,'payable'=>$amount];

    $now = date('Y-m-d H:i:s');
    if ($d['starts_at']  && $now < $d['starts_at'])  return ['ok'=>false,'message'=>'این کد هنوز فعال نشده است.','discount_amt'=>0,'payable'=>$amount];
    if ($d['expires_at'] && $now > $d['expires_at']) return ['ok'=>false,'message'=>'این کد منقضی شده است.','discount_amt'=>0,'payable'=>$amount];
    if ($d['usage_limit'] !== null && (int)$d['used_count'] >= (int)$d['usage_limit'])
        return ['ok'=>false,'message'=>'ظرفیت استفاده از این کد تکمیل شده است.','discount_amt'=>0,'payable'=>$amount];

    /* حوزهٔ اعمال: تست روانشناسی / رویداد / عمومی */
    if (!empty($d['event_id'])) {
        // کدِ اختصاصیِ یک رویداد
        if ($eventId === 0)
            return ['ok'=>false,'message'=>'این کد فقط برای ثبت‌نام یک رویداد خاص است.','discount_amt'=>0,'payable'=>$amount];
        if ((int)$d['event_id'] !== $eventId)
            return ['ok'=>false,'message'=>'این کد برای این رویداد معتبر نیست.','discount_amt'=>0,'payable'=>$amount];
    } elseif (!empty($d['test_id'])) {
        // کدِ اختصاصیِ یک تست روانشناسی
        if ($eventId > 0 || $testId === 0)
            return ['ok'=>false,'message'=>'این کد فقط برای خرید یک تست روانشناسی خاص است.','discount_amt'=>0,'payable'=>$amount];
        if ((int)$d['test_id'] !== $testId)
            return ['ok'=>false,'message'=>'این کد برای این تست روانشناسی قابل استفاده نیست.','discount_amt'=>0,'payable'=>$amount];
    }
    if ($d['user_id'] && (int)$d['user_id'] !== $userId)
        return ['ok'=>false,'message'=>'این کد مخصوص کاربر دیگری است.','discount_amt'=>0,'payable'=>$amount];

    // سقف استفادهٔ هر کاربر
    $usedByUser = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('discount_usage') . ' WHERE discount_id=? AND user_id=?',
                               [$d['id'], $userId]);
    if ($usedByUser >= (int)$d['per_user'])
        return ['ok'=>false,'message'=>'شما قبلاً از این کد استفاده کرده‌اید.','discount_amt'=>0,'payable'=>$amount];
    if ($d['min_order'] && $amount < (int)$d['min_order'])
        return ['ok'=>false,'message'=>'حداقل مبلغ خرید برای این کد ' . toman((int)$d['min_order']) . ' است.','discount_amt'=>0,'payable'=>$amount];

    // محاسبهٔ مبلغ تخفیف
    $disc = $d['type'] === 'percent'
        ? (int)floor($amount * (int)$d['value'] / 100)
        : (int)$d['value'];
    if ($d['type'] === 'percent' && $d['max_amount']) {
        $disc = min($disc, (int)$d['max_amount']);
    }
    $disc    = min($disc, $amount);
    $payable = $amount - $disc;

    return ['ok'=>true,
            'message'=> $d['type']==='percent'
                ? 'کد اعمال شد: ' . fa_num($d['value']) . '٪ تخفیف 🎉'
                : 'کد اعمال شد: ' . toman($disc) . ' تخفیف 🎉',
            'discount_amt'=>$disc, 'payable'=>$payable, 'discount'=>$d];
}

/**
 * ساخت سفارش + رکورد پرداخت + جلسهٔ تست روانشناسی (نسخهٔ ویژه)
 * پشتیبانی از ارتقای مستقیم آزمونِ تکمیل‌شدهٔ رایگان به نسخهٔ ویژه بدون نیاز به پاسخ‌گویی دوباره
 * @return array ['order'=>array,'payment_url'=>?string,'error'=>?string,'attempt_id'=>?int]
 */
function create_full_order(array $u, array $test, ?string $discountCode, bool $useWallet = false, ?int $upgradeAttemptId = null, ?string $customFieldsJson = null): array
{
    // نسخهٔ ۲٫۸: قیمت نهایی = قیمت با تخفیف در صورت اعتبار، وگرنه قیمت اصلی
    if (!array_key_exists('price_sale', $test)) {
        $test['price_sale'] = DB::one('SELECT price_sale FROM ' . DB::t('tests') . ' WHERE id=?', [(int)($test['id'] ?? 0)])['price_sale'] ?? null;
    }
    $deal    = fr_price_deal((int)$test['price_full'], isset($test['price_sale']) ? (int)$test['price_sale'] : null);
    $amount  = $deal['final'];
    $payable = $amount;
    $discAmt = 0; $d = null;

    if ($discountCode) {
        $v = validate_discount($discountCode, (int)$test['id'], (int)$u['id'], $amount);
        if (!$v['ok']) return ['order'=>[], 'payment_url'=>null, 'error'=>$v['message'], 'attempt_id'=>null];
        $discAmt = $v['discount_amt']; $payable = $v['payable']; $d = $v['discount'];
    }

    // ۱) سفارش
    $orderData = [
        'user_id'=>$u['id'], 'test_id'=>$test['id'], 'kind'=>'test_full', 'amount'=>$amount,
        'discount_code'=> $d['code'] ?? null, 'discount_amt'=>$discAmt, 'payable'=>$payable,
    ];
    if ($customFieldsJson !== null) {
        $orderData['custom_fields_json'] = $customFieldsJson;
    }
    $orderId = DB::insert('orders', $orderData);

    // ۲) جلسهٔ تست روانشناسی: اتصال به تلاش قبلی (برای ارتقا) یا ایجاد تلاش ویژه جدید
    $attemptId = null;
    if ($upgradeAttemptId && $upgradeAttemptId > 0) {
        $existing = DB::one('SELECT id, status, mode FROM ' . DB::t('attempts') .
                           ' WHERE id=? AND user_id=? AND test_id=?', [$upgradeAttemptId, $u['id'], $test['id']]);
        if ($existing) {
            $attemptId = (int)$existing['id'];
            $attUpdate = ['order_id'=>$orderId];
            if ($customFieldsJson !== null) $attUpdate['custom_fields_json'] = $customFieldsJson;
            DB::update('attempts', $attUpdate, 'id=?', [$attemptId]);
        }
    }

    if (!$attemptId) {
        // اگر کاربر قبلاً نسخهٔ رایگان را تکمیل کرده، همان را به سفارش متصل می‌کنیم تا نیازی به آزمون مجدد نباشد
        $lastCompleted = DB::one('SELECT id FROM ' . DB::t('attempts') .
            " WHERE user_id=? AND test_id=? AND mode='free' AND status='completed' ORDER BY id DESC LIMIT 1",
            [$u['id'], $test['id']]);
        if ($lastCompleted) {
            $attemptId = (int)$lastCompleted['id'];
            $attUpdate = ['order_id'=>$orderId];
            if ($customFieldsJson !== null) $attUpdate['custom_fields_json'] = $customFieldsJson;
            DB::update('attempts', $attUpdate, 'id=?', [$attemptId]);
        }
    }

    if (!$attemptId) {
        // اگر تلاشی درحال‌انجام دارد، به آن وصل می‌کنیم
        $lastInProgress = DB::one('SELECT id FROM ' . DB::t('attempts') .
            " WHERE user_id=? AND test_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1",
            [$u['id'], $test['id']]);
        if ($lastInProgress) {
            $attemptId = (int)$lastInProgress['id'];
            $attUpdate = ['order_id'=>$orderId];
            if ($customFieldsJson !== null) $attUpdate['custom_fields_json'] = $customFieldsJson;
            DB::update('attempts', $attUpdate, 'id=?', [$attemptId]);
        }
    }

    if (!$attemptId) {
        // جلسهٔ جدید در حالت ویژه (قبل از پرداخت ساخته می‌شود؛ بعد از پرداخت آزاد می‌شود)
        $newAttData = [
            'user_id'=>$u['id'], 'test_id'=>$test['id'], 'order_id'=>$orderId, 'mode'=>'full',
            'ip'=>client_ip_bin(), 'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ];
        if ($customFieldsJson !== null) {
            $newAttData['custom_fields_json'] = $customFieldsJson;
        }
        $attemptId = DB::insert('attempts', $newAttData);
    }

    // ۳) ثبت استفاده از کد تخفیف (همینجا ظرفیت رزرو می‌شود)
    if ($d) {
        DB::insert('discount_usage', ['discount_id'=>$d['id'], 'user_id'=>$u['id'], 'order_id'=>$orderId]);
        DB::run('UPDATE ' . DB::t('discounts') . ' SET used_count=used_count+1 WHERE id=?', [$d['id']]);
    }

    log_activity('pay_init', 'order', $orderId, ['amount'=>$payable,'test'=>$test['slug']]);

    // ۴) اگر پس از تخفیف صفر شد → پرداخت موفق بدون درگاه
    if ($payable === 0) {
        mark_order_paid($orderId, 'FREE-' . fr_token(8), null);
        return ['order'=>DB::one('SELECT * FROM ' . DB::t('orders') . ' WHERE id=?', [$orderId]),
                'payment_url'=>null, 'error'=>null, 'attempt_id'=>$attemptId];
    }

    // ۴٫۵) پرداخت از کیف پول (کل یا بخشی) — نسخهٔ ۱٫۶
    if ($useWallet) {
        require_once __DIR__ . '/wallet.php';
        $wp = wallet_pay_part($orderId, (int)$u['id'], (int)$payable,
                              'خرید نسخه ویژهٔ تست روانشناسی «' . $test['title'] . '» از کیف پول');
        if ($wp['gateway'] <= 0) {
            return ['order'=>DB::one('SELECT * FROM ' . DB::t('orders') . ' WHERE id=?', [$orderId]),
                    'payment_url'=>null, 'error'=>null, 'attempt_id'=>$attemptId, 'wallet'=>true];
        }
        $payable = $wp['gateway'];       // فقط مانده از درگاه
    }

    // ۵) درخواست درگاه زرین‌پال
    $zp  = new Zarinpal();
    $res = $zp->request($orderId, $payable, 'خرید نسخه ویژهٔ تست روانشناسی «' . $test['title'] . '»', (string)$u['mobile']);
    if (!$res['ok']) {
        // جبران کامل: هر بخشی که از کیف پول رفته، برمی‌گردد تا پول کاربر گم نشود
        require_once __DIR__ . '/wallet.php';
        fr_cancel_dead_order($orderId, 'خطای اتصال به درگاه');
        return ['order'=>[], 'payment_url'=>null, 'error'=>'خطا در اتصال به درگاه: ' . $res['message'], 'attempt_id'=>null];
    }

    DB::insert('payments', [
        'order_id'=>$orderId, 'authority'=>$res['authority'], 'amount'=>$payable,
        'ip'=>client_ip_bin(), 'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    return ['order'=>DB::one('SELECT * FROM ' . DB::t('orders') . ' WHERE id=?', [$orderId]),
            'payment_url'=>$res['url'], 'error'=>null, 'attempt_id'=>$attemptId];
}

/* ---------------------------------------------------------------------------
 | سفارش رویداد (کارگاه/دوره/مشاوره)
 * -------------------------------------------------------------------------*/
/**
 * ثبت‌نام + ساخت سفارش برای رویداد — با همراهان و تخفیف (نسخهٔ ۱٫۲)
 * نسخهٔ ۳٫۰: $payMode='deposit' → فقط بیعانهٔ مدیرُتعریف‌شده الان گرفته می‌شود
 * و مانده بعداً با لینک تصفیه تسویه می‌گردد.
 * @param array $companions  آرایه‌ای از ['first_name','last_name','mobile']
 * @return array ['registration_id'=>int,'payment_url'=>?string,'error'=>?string,'free'=>bool,'quote'=>array]
 */
function create_event_order(array $u, array $event, array $companions = [], ?string $discountCode = null, bool $useWallet = false, string $payMode = 'full'): array
{
    require_once __DIR__ . '/event-pricing.php';

    /* تکراری؟ — یک ثبت‌نام فعال برای هر کاربر در هر رویداد */
    $dup = DB::one('SELECT id,status FROM ' . DB::t('event_registrations') .
                   ' WHERE event_id=? AND user_id=?', [$event['id'], $u['id']]);
    if ($dup && $dup['status'] === 'paid') {
        return ['registration_id'=>(int)$dup['id'],'payment_url'=>null,'error'=>'شما قبلاً در این رویداد ثبت‌نام کرده‌اید.','free'=>true,'quote'=>[]];
    }
    /* ۳٫۰: ثبت‌نام پیش‌پرداخت‌شده باید با «تصفیهٔ حساب» تکمیل شود، نه ثبت‌نام دوباره */
    if ($dup && $dup['status'] === 'deposit') {
        return ['registration_id'=>(int)$dup['id'],'payment_url'=>null,
                'error'=>'پیش‌پرداخت این ثبت‌نام قبلاً انجام شده است؛ برای نهایی‌کردن، از دکمهٔ «💳 تصفیهٔ حساب» در پنل کاربری یا لینک پیامک‌شده برایتان اقدام کنید.',
                'free'=>false,'quote'=>[],'deposit'=>(int)DB::val('SELECT paid_total FROM ' . DB::t('event_registrations') . ' WHERE id=?', [(int)$dup['id']])];
    }

    /* ── اعتبارسنجی همراهان — نسخهٔ ۱٫۷: «نام و نام خانوادگی» یک فیلد + موبایل الزامی ── */
    $compEnabled = (int)($event['companion_enabled'] ?? 0) === 1;
    $validComps  = [];
    if ($compEnabled) {
        foreach ($companions as $c) {
            // ورودی جدید: name تکی — ورودی قدیمی (ناسازگاری صفری): first_name/last_name جدا
            if (isset($c['name']) || isset($c['first_name'])) {
                $rawName = trim((string)($c['name'] ?? ''));
                if ($rawName === '') {
                    $rawName = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));
                }
                [$fn, $ln] = fr_split_fullname($rawName);
            } else { continue; }
            if ($fn === '') continue;                                // ردیف خالی نادیده
            $mob = en_num(trim((string)($c['mobile'] ?? '')));
            $display = $rawName;
            if (mb_strlen($rawName) < 2 || mb_strlen($rawName) > 200) {
                return ['registration_id'=>0,'payment_url'=>null,'error'=>'نام و نام خانوادگی همراه را کامل بنویسید.','free'=>false,'quote'=>[]];
            }
            if (!valid_mobile($mob)) {
                return ['registration_id'=>0,'payment_url'=>null,'error'=>'موبایل همراه «' . $display . '» الزامی است و باید معتبر باشد (۱۱ رقم با ۰۹).','free'=>false,'quote'=>[]];
            }
            if ($mob === en_num((string)$u['mobile'])) {
                return ['registration_id'=>0,'payment_url'=>null,'error'=>'موبایل همراه «' . $display . '» با موبایل خودِ شما یکی است؛ همراه باید شخص دیگری باشد.','free'=>false,'quote'=>[]];
            }
            $validComps[] = ['name'=>$display,'first_name'=>$fn,'last_name'=>$ln,'mobile'=>$mob,
                             'is_free'=>!empty($c['is_free']) ? 1 : 0];   // ۲٫۱: میهمان رایگانِ هدیهٔ پله
        }
        // جلوگیری از تکرار یک موبایل در بین همراهان همین ثبت‌نام
        $mobs = array_column($validComps, 'mobile');
        if (count($mobs) !== count(array_unique($mobs))) {
            return ['registration_id'=>0,'payment_url'=>null,'error'=>'هر همراه باید موبایل مخصوص خودش را داشته باشد؛ یک شماره را دو بار وارد کرده‌اید.','free'=>false,'quote'=>[]];
        }
        /* نسخهٔ ۲٫۱: سقف همراه فقط روی «همراهان پولی» اعمال می‌شود؛
           میهمان‌های رایگان در چارچوب free پلهٔ فعلی کنترل می‌شوند */
        $paidCount = count(array_filter($validComps, fn($c) => empty($c['is_free'])));
        $freeWants = count($validComps) - $paidCount;
        $max = $event['companion_max'] !== null ? (int)$event['companion_max'] : 0;
        if ($max > 0 && $paidCount > $max) {
            return ['registration_id'=>0,'payment_url'=>null,'error'=>'حداکثر ' . fa_num($max) . ' همراه مجاز است.','free'=>false,'quote'=>[]];
        }
        if ($freeWants > 0) {
            $tiers = (string)($event['ws_discount_type'] ?? 'none') === 'tiered'
                   ? ev_parse_tiers($event['ws_discount_tiers'] ?? null) : [];
            $freeAllowed = ev_tier_free($tiers, 1 + $paidCount);
            if ($freeWants > $freeAllowed) {
                return ['registration_id'=>0,'payment_url'=>null,
                        'error'=>'برای این تعداد همراه فقط ' . fa_num($freeAllowed) . ' میهمان رایگان هدیه می‌گیرید؛ تعداد میهمان رایگان را کم کنید.','free'=>false,'quote'=>[]];
            }
        }
    }

    /* ── ظرفیت بر اساس صندلی (نفر اصلی + همراهان پولی + میهمان‌های رایگان) — صندلی همه می‌شود ── */
    $paidComps = array_values(array_filter($validComps, fn($c) => empty($c['is_free'])));
    $freeComps = array_values(array_filter($validComps, fn($c) => !empty($c['is_free'])));
    $needSeats = 1 + count($validComps);
    $left = ev_seats_left($event);
    if ($left !== null && $needSeats > $left) {
        return ['registration_id'=>0,'payment_url'=>null,
                'error'=>'ظرفیت کافی نیست؛ صندلی آزاد: ' . fa_num($left) . ' نفر (شما: ' . fa_num($needSeats) . ' صندلی).','free'=>false,'quote'=>[]];
    }

    /* ── کد تخفیف (اختیاری) ── */
    $d = null;
    if ($discountCode !== null && trim($discountCode) !== '') {
        // محاسبهٔ اولیهٔ جمع برای بررسی حداقل خرید: زیرمجموعه پس از تخفیف رویداد
        $pre = ev_quote($event, count($paidComps), null, count($freeComps));
        $v = validate_discount($discountCode, 0, (int)$u['id'], $pre['subtotal'] - $pre['ws_discount'], (int)$event['id']);
        if (!$v['ok']) return ['registration_id'=>0,'payment_url'=>null,'error'=>$v['message'],'free'=>false,'quote'=>[]];
        $d = $v['discount'];
    }

    /* ── پیش‌فاکتور نهایی (تنها منبع حقیقت قیمت) — پله با همراهان پولی، میهمان رایگان اضافه ── */
    $q = ev_quote($event, count($paidComps), $d, count($freeComps));

    /* ── ایجاد/به‌روزرسانی رکورد ثبت‌نام ── */
    $regData = [
        'companion_count'  => (int)$q['companion_count'] + (int)$q['free_count'],   // مجموع همراهان (صاحب صندلی)
        'companion_amount' => (int)$q['companion_total'],
        'discount_code'    => $d['code'] ?? null,
        'discount_amount'  => (int)$q['discount_amount'],
        'discount_meta'    => json_encode([
            'main_price'=>$q['main_price'], 'companion_unit'=>$q['companion_unit'],
            'companion_total'=>$q['companion_total'], 'subtotal'=>$q['subtotal'],
            'free_allowed'=>$q['free_allowed'], 'free_count'=>$q['free_count'],
            'seats_total'=>$q['seats_total'],
            'ws_discount'=>$q['ws_discount'], 'ws_desc'=>$q['ws_desc'],
            'code_discount'=>$q['code_discount'], 'code_desc'=>$q['code_desc'],
            'payable'=>$q['payable'], 'lines'=>$q['lines'],
        ], JSON_UNESCAPED_UNICODE),
        'amount_base'      => (int)$q['subtotal'],
    ];
    if ($dup) { $regId = (int)$dup['id']; DB::update('event_registrations', $regData, 'id=?', [$regId]); }
    else      { $regId = DB::insert('event_registrations', $regData + ['event_id'=>(int)$event['id'],'user_id'=>(int)$u['id']]); }

    /* ── ذخیرهٔ همراهان ── */
    ev_companions_save($regId, $validComps, $event);

    /* ── مسیر رایگان (رویداد رایگان یا تخفیف ۱۰۰٪) → نهایی فوری ── */
    if ($q['payable'] === 0) {
        DB::update('event_registrations', ['status'=>'paid'], 'id=?', [$regId]);
        log_activity('event_register', 'event', (int)$event['id'], ['reg'=>$regId,'companions'=>count($validComps)]);
        require_once __DIR__ . '/notify.php';
        // نسخهٔ ۱٫۷: متن پیامک اختصاصی رویداد اگر مدیر تعریف کرده باشد، وگرنه قالب پیش‌فرض
        fr_event_user_sms($event, $u, 0, '');
        ev_notify_companions($regId, $event);
        // اطلاع‌رسانی پیامکی ثبت‌نام موفق به مدیران و شماره‌های دلخواه (۱٫۷)
        fr_notify_admins_sms('admin_new_order', [
            'name'   => user_fullname($u),
            'mobile' => $u['mobile'],
            'test'   => 'رویداد «' . $event['title'] . '»',
            'amount' => 0,
            'refid'  => 'رایگان',
        ]);
        return ['registration_id'=>$regId,'payment_url'=>null,'error'=>null,'free'=>true,'quote'=>$q];
    }

    /* ── پرداختی → سفارش و درگاه ── */
    /* ۳٫۰ — حالت پیش‌پرداخت: فقط بیعانهٔ فعلی از درگاه/کیف گرفته می‌شود */
    $depositNow = 0;
    if ($payMode === 'deposit') {
        require_once __DIR__ . '/deposit.php';
        $depositNow = fr_deposit_charge($event, (int)$q['payable']);
    }
    $chargeNow = $depositNow > 0 ? $depositNow : (int)$q['payable'];   // مبلغی که همین حالا پرداخت می‌شود
    $payTitle  = $depositNow > 0 ? 'پیش‌پرداخت ثبت‌نام در رویداد «' . $event['title'] . '»'
                                 : 'ثبت‌نام در رویداد «' . $event['title'] . '»';

    $orderId = DB::insert('orders', [
        'user_id'=>$u['id'], 'test_id'=>null, 'event_id'=>(int)$event['id'], 'kind'=>'event',
        'amount'=>$q['subtotal'], 'discount_code'=>$d['code'] ?? null,
        'discount_amt'=>$q['discount_amount'], 'payable'=>$q['payable'],
        'pay_stage'=> $depositNow > 0 ? 'deposit' : 'full',
    ]);
    DB::update('event_registrations', ['order_id'=>$orderId], 'id=?', [$regId]);
    // رزرو ظرفیت کد تخفیف
    if ($d) {
        DB::insert('discount_usage', ['discount_id'=>$d['id'], 'user_id'=>$u['id'], 'order_id'=>$orderId]);
        DB::run('UPDATE ' . DB::t('discounts') . ' SET used_count=used_count+1 WHERE id=?', [$d['id']]);
    }
    log_activity('pay_init', 'order', $orderId, ['event'=>(int)$event['id'],'amount'=>$chargeNow,'stage'=>$depositNow > 0 ? 'deposit' : 'full']);

    /* ── پرداخت از کیف پول (کل یا بخشی) — نسخهٔ ۱٫۶ ── */
    if ($useWallet) {
        require_once __DIR__ . '/wallet.php';
        $wp = wallet_pay_part($orderId, (int)$u['id'], $chargeNow, $payTitle . ' — پرداخت از کیف پول');
        if ($wp['gateway'] <= 0) {
            // کل مبلغِ فعلی با کیف پول تسویه شد — mark_order_paid مرحله (deposit/full) را جلو برده
            return ['registration_id'=>$regId,'payment_url'=>null,'error'=>null,'free'=>true,'wallet'=>true,'quote'=>$q,'deposit'=>$depositNow];
        }
        $chargeNow = $wp['gateway'];       // فقط مانده از درگاه گرفته می‌شود
        $q['wallet_paid'] = $wp['wallet'];
    }

    $zp  = new Zarinpal();
    $res = $zp->request($orderId, $chargeNow, $payTitle, (string)$u['mobile']);
    if (!$res['ok']) {
        // جبران کامل: کیف پول برگشت، ثبت‌نام و سفارش باطل، ظرفیت تخفیف/صندلی آزاد
        require_once __DIR__ . '/wallet.php';
        fr_cancel_dead_order($orderId, 'خطای اتصال به درگاه');
        return ['registration_id'=>$regId,'payment_url'=>null,'error'=>'خطا در اتصال به درگاه: '.$res['message'],'free'=>false,'quote'=>$q];
    }
    DB::insert('payments', ['order_id'=>$orderId,'authority'=>$res['authority'],'amount'=>$chargeNow,
        'ip'=>client_ip_bin(),'user_agent'=>substr($_SERVER['HTTP_USER_AGENT']??'',0,255)]);
    return ['registration_id'=>$regId,'payment_url'=>$res['url'],'error'=>null,'free'=>false,'quote'=>$q,'deposit'=>$depositNow];
}

/**
 * علامت‌گذاری سفارش به‌عنوان پرداخت‌شده (از کال‌بک یا پرداخت صفر)
 * هم سفارش‌های تست روانشناسی (kind=test_full) و هم رویدادها (kind=event) را پوشش می‌دهد.
 * نسخهٔ ۳٫۰ — مرحلهٔ پرداخت (pay_stage):
 *   full    → رفتار کلاسیک: پرداخت کامل، ثبت‌نام/نوبت نهایی
 *   deposit → جای کاربر با بیعانه قطعی می‌شود (رویداد: status=deposit؛ نوبت: booked)
 *             و پیامک «مانده + لینک تصفیه» می‌رود
 *   settle  → سفارش تصفیهٔ حساب (فرزند سفارش مادر): مانده بسته و ثبت‌نام/نوبت نهایی می‌شود
 */
function mark_order_paid(int $orderId, string $refId, ?string $cardPan): void
{
    $o = DB::one('SELECT o.*, u.mobile, u.first_name, u.last_name,
                         t.title AS test_title, ev.title AS event_title
                  FROM ' . DB::t('orders') . ' o
                  JOIN ' . DB::t('users') . ' u ON u.id=o.user_id
                  LEFT JOIN ' . DB::t('tests') . ' t ON t.id=o.test_id
                  LEFT JOIN ' . DB::t('events') . ' ev ON ev.id=o.event_id
                  WHERE o.id=?', [$orderId]);
    if (!$o || $o['status'] === 'paid') return;

    DB::update('orders', ['status'=>'paid', 'paid_at'=>date('Y-m-d H:i:s')], 'id=?', [$orderId]);
    DB::run('UPDATE ' . DB::t('payments') .
            " SET status='ok', ref_id=?, card_pan=?, verified_at=NOW() WHERE order_id=?", [$refId, $cardPan, $orderId]);

    /* ۳٫۰: مرحلهٔ پرداخت + مجموع تأییدشدهٔ همین سفارش (درگاه + کیف پول) */
    $stage = (string)($o['pay_stage'] ?? 'full');
    $stage = in_array($stage, ['full','deposit','settle'], true) ? $stage : 'full';
    if ($stage !== 'full') require_once __DIR__ . '/deposit.php';
    $paidSum = (int)DB::val('SELECT COALESCE(SUM(amount),0) FROM ' . DB::t('payments') .
                            " WHERE order_id=? AND status='ok'", [$orderId]);

    log_activity('pay_ok', 'order', $orderId, ['ref_id'=>$refId, 'kind'=>$o['kind'], 'stage'=>$stage]);

    // اگر سفارش نوبت مشاوره است ← رزرو قطعی + پیامک مراجع/مشاور
    if ($o['kind'] === 'consult') {
        require_once __DIR__ . '/consult.php';
        // سفارش تصفیه به سفارش مادر (پیش‌پرداخت) اشاره دارد — نوبت روی مادر ثبت است
        $baseOrderId = $stage === 'settle' && (int)($o['parent_order_id'] ?? 0) > 0 ? (int)$o['parent_order_id'] : $orderId;
        $apptId = (int)DB::val('SELECT id FROM ' . DB::t('consult_appointments') . ' WHERE order_id=?', [$baseOrderId]);
        if ($apptId) {
            if ($paidSum > 0) {
                DB::run('UPDATE ' . DB::t('consult_appointments') . ' SET paid_total = paid_total + ? WHERE id=?', [$paidSum, $apptId]);
            }
            consult_confirm_appointment($apptId);
            if ($stage === 'deposit')      fr_deposit_notify_appt($apptId);
            elseif ($stage === 'settle')   fr_settle_notify_appt($apptId, $refId);
        }
    }

    // اگر سفارش رویداد است ← ثبت‌نام نهایی + اطلاع‌رسانی به همراهان
    $evRow = null;
    if ($o['kind'] === 'event' && $o['event_id']) {
        $baseOrderId = $stage === 'settle' && (int)($o['parent_order_id'] ?? 0) > 0 ? (int)$o['parent_order_id'] : $orderId;
        $newRegSt = $stage === 'deposit' ? 'deposit' : 'paid';
        if ($paidSum > 0) {
            DB::run('UPDATE ' . DB::t('event_registrations') .
                    " SET status='" . $newRegSt . "', paid_total = paid_total + ? WHERE order_id=?",
                    [$paidSum, $baseOrderId]);
        } else {
            DB::run('UPDATE ' . DB::t('event_registrations') .
                    " SET status='" . $newRegSt . "' WHERE order_id=?", [$baseOrderId]);
        }
        log_activity('event_register', 'event', (int)$o['event_id']);
        require_once __DIR__ . '/event-pricing.php';
        $reg = DB::one('SELECT * FROM ' . DB::t('event_registrations') . ' WHERE order_id=?', [$baseOrderId]);
        $evRow = DB::one('SELECT * FROM ' . DB::t('events') . ' WHERE id=?', [(int)$o['event_id']]);
        /* پیامک به همراهان فقط وقتی ثبت‌نام به‌طور کامل نهایی شد (پرداخت کامل یا تصفیه) */
        if ($reg && $evRow && $stage !== 'deposit') ev_notify_companions((int)$reg['id'], $evRow);
        if ($reg && $evRow && $stage === 'deposit') fr_deposit_notify_reg((int)$reg['id']);
        if ($reg && $evRow && $stage === 'settle')  fr_settle_notify_reg((int)$reg['id'], $refId);
    }

    // اگر سفارش تست روانشناسی است ← اعطای دسترسی نسخه ویژه و به‌روزرسانی گزارش
    if (!empty($o['test_id'])) {
        $testId = (int)$o['test_id'];
        $userId = (int)$o['user_id'];

        // ۱. تلاشی که مستقیم به این سفارش متصل شده است
        $targetAtt = DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE order_id=?', [$orderId]);

        // ۲. تلاش تکمیل‌شده قبلی کاربر برای این تست
        $completedAtt = DB::one('SELECT * FROM ' . DB::t('attempts') .
            " WHERE user_id=? AND test_id=? AND status='completed' ORDER BY id DESC LIMIT 1",
            [$userId, $testId]);

        // اگر یک تلاش خالی درحال‌انجام به سفارش متصل شده اما کاربر از قبل تستی تمام‌شده دارد،
        // تلاش خالی اضافی را پاک کرده و سفارش را به تلاش تکمیل‌شده پیوند می‌دهیم
        if ($targetAtt && $targetAtt['status'] === 'in_progress' && $completedAtt && (int)$targetAtt['id'] !== (int)$completedAtt['id']) {
            $ansCount = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$targetAtt['id']]);
            if ($ansCount === 0) {
                DB::run('DELETE FROM ' . DB::t('attempts') . ' WHERE id=?', [$targetAtt['id']]);
                $targetAtt = null;
            }
        }

        if (!$targetAtt && $completedAtt) {
            $targetAtt = $completedAtt;
        }

        if ($targetAtt) {
            DB::update('attempts', ['mode' => 'full', 'order_id' => $orderId], 'id=?', [$targetAtt['id']]);

            // اگر آزمون تکمیل شده، نمره‌گذاری و تولید کامل تحلیل ویژه صورت گیرد
            if ($targetAtt['status'] === 'completed') {
                require_once __DIR__ . '/../core/Analyzer.php';
                Analyzer::scoreAttempt((int)$targetAtt['id']);

                $nameForSms = trim($o['first_name'] . ' ' . $o['last_name']) ?: 'کاربر';
                $repLink = fr_panel_url('report.php?attempt=' . (int)$targetAtt['id']);
                (new Kavenegar())->sendEvent('report_full', $o['mobile'], [
                    'name'    => $nameForSms,
                    'test'    => $o['test_title'] ?? 'تست روانشناسی',
                    'link'    => $repLink,
                    '_tokens' => [$nameForSms, $o['test_title'] ?? 'تست روانشناسی', $repLink],
                ]);
            }
        }

        // ۳٫۴: به‌روزرسانی پیوند تطابق دونفره و زوجین در صورت پرداخت هدیه یا خرید همراه
        try {
            $dyad1 = DB::one('SELECT d.*, t.title AS test_title FROM ' . DB::t('dyads') . ' d
                             JOIN ' . DB::t('tests') . ' t ON t.id=d.test_id
                             WHERE d.order1_id=?', [$orderId]);
            if ($dyad1) {
                DB::update('dyads', ['partner_paid' => 1, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$dyad1['id']]);
                if (!empty($dyad1['partner_mobile'])) {
                    $u1 = DB::one('SELECT first_name, last_name FROM ' . DB::t('users') . ' WHERE id=?', [(int)$dyad1['user1_id']]);
                    $u1Name = trim(($u1['first_name'] ?? '') . ' ' . ($u1['last_name'] ?? '')) ?: 'همراه شما';
                    $testTitle = $dyad1['test_title'] ?? 'سنجش تطابق';
                    $inviteUrl = fr_panel_url('dyad.php?token=' . urlencode($dyad1['token']));
                    $pName = $dyad1['partner_name'] ?: 'همراه گرامی';

                    require_once FR_ROOT . '/core/Kavenegar.php';
                    $sms = new Kavenegar();
                    $smsText = "{$pName} عزیز، {$u1Name} هزینهٔ نسخهٔ تخصصی آزمون سنجش تطابق ({$testTitle}) را برای شما هدیه و پرداخت کرده است.\nمشاهده و شروع آزمون هدیه:\n{$inviteUrl}";
                    $sms->sendDirect($dyad1['partner_mobile'], $smsText, 'dyad_invite');
                }
            }

            $targetAttId = $targetAtt ? (int)$targetAtt['id'] : 0;
            $dyad2 = DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE order2_id=? OR (attempt2_id IS NOT NULL AND attempt2_id=?)', [$orderId, $targetAttId]);
            if ($dyad2) {
                DB::update('dyads', [
                    'order2_id' => $orderId,
                    'status'    => 'ready',
                    'updated_at'=> date('Y-m-d H:i:s')
                ], 'id=?', [$dyad2['id']]);
                require_once FR_ROOT . '/core/DyadAnalyzer.php';
                DyadAnalyzer::analyze((int)$dyad2['id']);
            }
        } catch (Throwable $e) {
            error_log('Dyad mark_order_paid error: ' . $e->getMessage());
        }
    }

    $itemTitle = $o['kind'] === 'event' ? ($o['event_title'] ?? 'رویداد') : ($o['test_title'] ?? 'تست روانشناسی');
    $userName  = trim($o['first_name'] . ' ' . $o['last_name']) ?: 'کاربر';
    require_once __DIR__ . '/notify.php';

    /* پیامک پرداخت موفق/ثبت‌نام — نسخهٔ ۱٫۷: برای رویدادها، متن اختصاصی رویداد اولویت دارد.
       ۳٫۰: مراحل deposit/settle پیامک اختصاصی خودشان را (بالا) دریافت کرده‌اند. */
    if ($stage === 'full') {
        if ($o['kind'] === 'event' && $evRow) {
            fr_event_user_sms($evRow, $o, (int)$o['payable'], $refId);
        } else {
            (new Kavenegar())->sendEvent('payment_ok', $o['mobile'], [
                'name'   => $userName,
                'amount' => $o['payable'], 'refid' => $refId, 'test' => $itemTitle,
                '_tokens'=> [$userName, number_format($o['payable']), $refId],
            ]);
        }
    }

    // نسخهٔ ۱٫۷: اطلاع‌رسانی «ثبت‌نام موفق جدید» به مدیران + شماره‌های دلخواه (مثل مدیر حسابداری)
    $kindLabel = $o['kind'] === 'consult' ? 'رزرو مشاوره «' . $itemTitle . '»'
               : ($o['kind'] === 'event'  ? 'رویداد «' . $itemTitle . '»'
               : 'خرید تست روانشناسی «' . $itemTitle . '»');
    if ($stage === 'deposit')    $kindLabel .= ' (پیش‌پرداخت 💰)';
    elseif ($stage === 'settle') $kindLabel .= ' (تصفیهٔ حساب ✅)';
    fr_notify_admins_sms('admin_new_order', [
        'name'   => $userName,
        'mobile' => $o['mobile'],
        'test'   => $kindLabel,
        'amount' => $o['payable'],
        'refid'  => $refId,
    ]);
}
