<?php
/**
 * ============================================================================
 *  موتور قیمت‌گذاری رویدادها (کارگاه/دوره/مشاوره) — نسخهٔ ۱٫۲
 *  ─ همراهان: قیمت هر همراه (ثابت یا درصدی از شهریهٔ اصلی) + سقف تعداد
 *  ─ تخفیف ویژهٔ رویداد: درصدی / مبلغی / پلکانی بر اساس تعداد نفرات
 *  ─ کد تخفیف عمومی/اختصاصی رویداد + تفکیک کامل فاکتور (lines)
 *  ─ قانون طلایی: تنها منبع حقیقت قیمت، همین محاسباتِ سمت سرور است
 * ============================================================================
 */
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 | قیمت هر همراه (تومان)
 * -------------------------------------------------------------------------*/
function ev_companion_unit(array $ev): int
{
    // نسخهٔ ۲٫۸: مبنای محاسبه «قیمت نهایی» رویداد است (تخفیف قیمت‌گذاری لحاظ می‌شود)
    $price = fr_price_deal((int)$ev['price'], isset($ev['sale_price']) ? (int)$ev['sale_price'] : null)['final'];
    if (($ev['companion_price_mode'] ?? 'fixed') === 'percent') {
        $pct = max(0, min(100, (int)($ev['companion_price'] ?? 0)));
        return (int)floor($price * $pct / 100);                 // درصدی از شهریهٔ اصلی
    }
    return $ev['companion_price'] !== null ? max(0, (int)$ev['companion_price']) : $price;
}

/* ---------------------------------------------------------------------------
 | پله‌های تخفیف گروهی — JSON: [{"min":2,"percent":10,"free":0}, ...]
 |  min     : حداقل نفرات «پرداخت‌کننده» (نفر اصلی + همراهانِ پولی) برای فعال‌شدن پله
 |  percent : درصد تخفیف روی مبنای مشخص‌شده
 |  free    : ۲٫۱ — چند «میهمان رایگان» (صفرقیمت ولی دارای صندلی) هدیه داده شود
 * -------------------------------------------------------------------------*/
function ev_parse_tiers(?string $json): array
{
    $t = json_decode((string)$json, true);
    if (!is_array($t)) return [];
    $out = [];
    foreach ($t as $row) {
        if (!is_array($row)) continue;
        $min  = (int)($row['min'] ?? 0);
        $pct  = max(0, min(100, (int)($row['percent'] ?? 0)));
        $free = max(0, (int)($row['free'] ?? 0));
        if ($min >= 1 && ($pct > 0 || $free > 0)) {
            $out[] = ['min' => $min, 'percent' => $pct, 'free' => $free];
        }
    }
    usort($out, fn($a, $b) => $a['min'] <=> $b['min']);
    return $out;
}

/** پلهٔ متناظر با تعداد نفراتِ پرداخت‌کننده (بلندترین پله‌ای که پوشش داده می‌شود) یا null */
function ev_tier_row(array $tiers, int $paidTotal): ?array
{
    $hit = null;
    foreach ($tiers as $t) { if ($paidTotal >= $t['min']) $hit = $t; }
    return $hit;
}

/** درصد پلکانی متناظر با تعداد نفراتِ پرداخت‌کننده */
function ev_tier_percent(array $tiers, int $paidTotal): int
{
    $r = ev_tier_row($tiers, $paidTotal);
    return $r ? (int)$r['percent'] : 0;
}

/** نسخهٔ ۲٫۱: تعداد «میهمان رایگان» مجاز برای یک تعداد نفرات پرداخت‌کننده (۰ اگر به پله نرسیده) */
function ev_tier_free(array $tiers, int $paidTotal): int
{
    $r = ev_tier_row($tiers, $paidTotal);
    return $r ? (int)$r['free'] : 0;
}

/* ---------------------------------------------------------------------------
 | ظرفیت و صندلی‌ها — هر همراه هم یک صندلی اشغال می‌کند
 * -------------------------------------------------------------------------*/
function ev_paid_seats(int $eventId): int
{
    /* ۳٫۰: ثبت‌نام‌های «پیش‌پرداخت‌شده» هم صندلی‌شان قطعی است و در ظرفیت حساب می‌شوند */
    return (int)DB::val("SELECT COALESCE(SUM(1 + COALESCE(companion_count,0)),0)
                         FROM " . DB::t('event_registrations') .
                        " WHERE event_id=? AND status IN ('paid','deposit')", [$eventId]);
}
function ev_seats_left(array $ev): ?int
{
    if ($ev['capacity'] === null) return null;                  // نامحدود
    return max(0, (int)$ev['capacity'] - ev_paid_seats((int)$ev['id']));
}

/* ---------------------------------------------------------------------------
 | پیش‌فاکتور کامل — مبنای نمایش زنده در فرم و ثبت قطعی سفارش
 | نسخهٔ ۲٫۱: «میهمان رایگان» = اسلات‌های اضافهٔ صفرقیمت که صندلی اشغال می‌کنند
 |   ؛ پله با نفراتِ پرداخت‌کننده تعیین می‌شود و میهمانِ رایگان پله را جابه‌جا نمی‌کند
 * -------------------------------------------------------------------------*/
function ev_quote(array $ev, int $companionCount, ?array $discountCode = null, int $freeCount = 0): array
{
    // نسخهٔ ۲٫۸: شهریهٔ نفر اصلی = قیمت نهایی (تخفیف قیمت‌گذاری لحاظ می‌شود)
    $deal = fr_price_deal((int)$ev['price'], isset($ev['sale_price']) ? (int)$ev['sale_price'] : null);
    $main = $deal['final'];
    $unit = ev_companion_unit($ev);
    $enabled = (int)($ev['companion_enabled'] ?? 0) === 1;
    $max = $ev['companion_max'] !== null ? (int)$ev['companion_max'] : 0;   // 0 = نامحدود

    if (!$enabled) { $companionCount = 0; $freeCount = 0; }
    if ($max > 0) $companionCount = min($companionCount, $max);           // سقف همراهِ پولی
    $companionCount = max(0, $companionCount);

    /* پلهٔ تخفیف بر اساس نفرات پرداخت‌کننده (نفر اصلی + همراهان پولی) */
    $tiers      = [];
    $tier       = null;
    $freeAllowed = 0;
    if ((string)($ev['ws_discount_type'] ?? 'none') === 'tiered') {
        $tiers = ev_parse_tiers($ev['ws_discount_tiers'] ?? null);
        $tier  = ev_tier_row($tiers, 1 + $companionCount);
        $freeAllowed = $tier ? (int)$tier['free'] : 0;
    }
    $freeN = max(0, min($freeCount, $freeAllowed));                       // میهمان رایگانِ هدیهٔ پله

    $cTotal   = $unit * $companionCount;
    $subtotal = $main + $cTotal;                                          // میهمان رایگان مبلغی اضافه نمی‌کند

    /* ── تخفیف ویژهٔ رویداد ── */
    $scope     = (string)($ev['ws_discount_scope'] ?? 'all');
    $scopeBase = $scope === 'main' ? $main : ($scope === 'companion' ? $cTotal : $subtotal);
    $wsDisc = 0; $wsDesc = '';
    switch ((string)($ev['ws_discount_type'] ?? 'none')) {
        case 'percent':
            $pct = max(0, min(100, (int)$ev['ws_discount_value']));
            $wsDisc = (int)floor($scopeBase * $pct / 100);
            $wsDesc = 'تخفیف ویژهٔ رویداد (' . fa_num($pct) . '٪)';
            break;
        case 'fixed':
            $wsDisc = min($scopeBase, max(0, (int)$ev['ws_discount_value']));
            $wsDesc = 'تخفیف ویژهٔ رویداد';
            break;
        case 'tiered':
            /* ۲٫۱: درصد تخفیف روی مبنا + اعلام میهمان‌های رایگانِ هدیه (صندلی می‌گیرند ولی مبلغی ندارند) */
            if ($tier) {
                $parts = [];
                if ($tier['percent'] > 0) {
                    $wsDisc = (int)floor($scopeBase * $tier['percent'] / 100);
                    $parts[] = fa_num($tier['percent']) . '٪ تخفیف';
                }
                if ($freeAllowed > 0) $parts[] = fa_num($freeAllowed) . ' میهمان رایگان';
                if ($parts) {
                    $wsDesc = 'تخفیف گروهی (' . implode(' + ', $parts) . ' برای ' . fa_num(1 + $companionCount) . ' نفر پرداخت‌کننده)';
                }
            }
            break;
    }

    /* ── کد تخفیف — روی مابقیِ پس از تخفیف رویداد اعمال می‌شود ── */
    $afterWs  = max(0, $subtotal - $wsDisc);
    $codeDisc = 0; $codeDesc = '';
    if ($discountCode) {
        $codeDisc = $discountCode['type'] === 'percent'
            ? (int)floor($afterWs * (int)$discountCode['value'] / 100)
            : min($afterWs, (int)$discountCode['value']);
        if ($discountCode['type'] === 'percent' && $discountCode['max_amount']) {
            $codeDisc = min($codeDisc, (int)$discountCode['max_amount']);
        }
        $codeDisc = max(0, min($codeDisc, $afterWs));
        $codeDesc = 'کد تخفیف «' . $discountCode['code'] . '»';
    }

    $totalDisc = $wsDisc + $codeDisc;
    $payable   = max(0, $subtotal - $totalDisc);

    /* ── خطوط فاکتور برای نمایش ── */
    $mainLine = ['label' => 'شهریهٔ نفر اصلی', 'amount' => $main];
    if ($deal['has_sale']) {
        // نسخهٔ ۲٫۸: نمایش قیمت اصلیِ خط‌خورده + برچسب درصد تخفیف قیمت‌گذاری
        $mainLine['label'] .= ' (تخفیف ' . fa_num($deal['percent']) . '٪ قیمت‌گذاری)';
        $mainLine['was'] = $deal['list'];
    }
    $lines = [$mainLine];
    if ($companionCount > 0) {
        $lines[] = ['label' => 'همراهان (' . fa_num($companionCount) . ' نفر × ' . toman($unit) . ')', 'amount' => $cTotal];
    }
    if ($freeN > 0) {
        $lines[] = ['label' => 'میهمان رایگان هدیهٔ گروه (' . fa_num($freeN) . ' نفر — رایگان 🎁)', 'amount' => 0, 'free' => true];
    }
    if ($wsDisc > 0)   $lines[] = ['label' => $wsDesc,   'amount' => -$wsDisc];
    if ($codeDisc > 0) $lines[] = ['label' => $codeDesc, 'amount' => -$codeDisc];

    return [
        'main_price'      => $main,
        'list_price'      => $deal['list'],        // ۲٫۸: قیمت اصلی (قبل از تخفیف قیمت‌گذاری)
        'price_deal'      => $deal,                // ۲٫۸: جزئیات تخفیف قیمت‌گذاری
        'companion_unit'  => $unit,
        'companion_count' => $companionCount,
        'companion_total' => $cTotal,
        'free_allowed'    => $freeAllowed,
        'free_count'      => $freeN,
        'seats_total'     => 1 + $companionCount + $freeN,   // میهمان رایگان هم صندلی مصرف می‌کند
        'tier_hit'        => $tier,
        'subtotal'        => $subtotal,
        'ws_discount'     => $wsDisc,
        'ws_desc'         => $wsDesc,
        'code_discount'   => $codeDisc,
        'code_desc'       => $codeDesc,
        'discount_amount' => $totalDisc,
        'payable'         => $payable,
        'lines'           => $lines,
    ];
}

/* ---------------------------------------------------------------------------
 | همراهان یک ثبت‌نام — خواندن / ذخیره (جایگزینی کامل) / اطلاع‌رسانی پیامکی
 * -------------------------------------------------------------------------*/
function ev_companions_of(int $regId): array
{
    return DB::all('SELECT * FROM ' . DB::t('event_companions') . ' WHERE reg_id=? ORDER BY id', [$regId]);
}

/** ذخیرهٔ جایگزین همراهان — ۱٫۷: نام تکی + موبایل الزامی — ۲٫۱: پرچم is_free برای میهمان رایگان */
function ev_companions_save(int $regId, array $list, array $ev): int
{
    DB::run('DELETE FROM ' . DB::t('event_companions') . ' WHERE reg_id=?', [$regId]);
    $max = $ev['companion_max'] !== null ? (int)$ev['companion_max'] : 0;
    /* ۲٫۱: سقف میهمان رایگان همان free پلهٔ فعلی است؛ همراه پولی در سقف max می‌آید */
    $tiers = (string)($ev['ws_discount_type'] ?? 'none') === 'tiered' ? ev_parse_tiers($ev['ws_discount_tiers'] ?? null) : [];
    $n = 0; $paid = 0; $free = 0;
    foreach ($list as $c) {
        $isFree = !empty($c['is_free']) ? 1 : 0;
        if ($max > 0 && !$isFree && $paid >= $max) continue;  // سقف همراه پولی
        $rawName = trim((string)($c['name'] ?? ''));
        if ($rawName === '') $rawName = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));
        [$fn, $ln] = fr_split_fullname($rawName);
        if ($fn === '') continue;                            // ردیف خالی نادیده
        $mob = en_num(trim((string)($c['mobile'] ?? '')));
        if (!valid_mobile($mob)) continue;                   // بدون موبایل معتبر ذخیره نمی‌شود (موبایل الزامی)
        $compUserId = fr_companion_user_upsert($mob, $fn, $ln);
        DB::insert('event_companions', [
            'reg_id'     => $regId,
            'first_name' => mb_substr($fn, 0, 100),
            'last_name'  => $ln !== '' ? mb_substr($ln, 0, 100) : null,
            'mobile'     => $mob,
            'user_id'    => $compUserId,
            'is_free'    => $isFree,
            'status'     => 'active',
        ]);
        $n++; if ($isFree) $free++; else $paid++;
    }
    return $n;
}

/**
 * حساب کاربری خودکار برای هر موبایل همراه — نسخهٔ ۱٫۷
 * قانون سایت: هر موبایلی که در سیستم ثبت شود (خودش یا به‌عنوان همراه) صاحب پنل کاربری است.
 * اگر حساب با آن موبایل هست، همان برگردانده می‌شود (نام خالی فقط در صورت خالی‌بودن قبلی پر می‌شود)؛
 * اگر نیست، حساب جدید ساخته می‌شود تا همراه با OTP وارد شود و ثبت‌نامش را ببیند/لغو کند.
 */
function fr_companion_user_upsert(string $mobile, string $fn, string $ln): ?int
{
    if (!valid_mobile($mobile)) return null;
    $row = DB::one('SELECT id,first_name,last_name FROM ' . DB::t('users') . ' WHERE mobile=?', [$mobile]);
    if ($row) {
        // نام واقعی را با نام اعلام‌شده توسط ثبت‌نام‌کننده بازنویسی نمی‌کنیم؛ فقط خالی را پر می‌کنیم
        if (trim((string)$row['first_name']) === '' && $fn !== '') {
            DB::update('users', ['first_name'=>mb_substr($fn,0,100), 'last_name'=>$ln !== '' ? mb_substr($ln,0,100) : null], 'id=?', [(int)$row['id']]);
        }
        return (int)$row['id'];
    }
    try {
        $id = DB::insert('users', [
            'mobile' => $mobile,
            'first_name' => mb_substr($fn, 0, 100),
            'last_name'  => $ln !== '' ? mb_substr($ln, 0, 100) : null,
            'mobile_verified' => 0,                          // با اولین ورود OTP تأیید می‌شود
        ]);
        log_activity('companion_account', 'user', $id, ['mobile'=>$mobile]);
        /* ۲٫۷ — هر ثبت‌نامی خودکار در «مخاطبین» هم بشیند (source=register) */
        try {
            require_once __DIR__ . '/contacts.php';
            fr_contact_sync_user(['id' => $id, 'mobile' => $mobile, 'first_name' => $fn, 'last_name' => $ln]);
        } catch (Throwable $e) { /* بی‌صدا */ }
        return $id;
    } catch (PDOException $e) {
        // مسابقهٔ هم‌زمان: حساب را هم‌اکنون کسی دیگر ساخته — همان را برگردان
        $row = DB::one('SELECT id FROM ' . DB::t('users') . ' WHERE mobile=?', [$mobile]);
        return $row ? (int)$row['id'] : null;
    }
}

/** پیامک اطلاع‌رسانی ثبت‌نام به هر همراهِ دارای موبایل معتبر — با اشاره به پنل کاربری مخصوص خودش (۱٫۷) */
function ev_notify_companions(int $regId, array $ev): int
{
    $kv = new Kavenegar();
    $n  = 0;
    $when = $ev['starts_at'] ? fa_num(jdate('l j F Y ساعت H:i', strtotime($ev['starts_at']))) : '';
    foreach (ev_companions_of($regId) as $c) {
        if ((string)($c['status'] ?? 'active') !== 'active') continue;
        $mob = (string)($c['mobile'] ?? '');
        if (!valid_mobile($mob)) continue;
        $name = trim($c['first_name'] . ' ' . (string)$c['last_name']);
        $kv->send($mob,
            $name . ' عزیز، ثبت‌نام شما به‌عنوان همراه در «' . $ev['title'] . '»' .
            ($when ? ' برای ' . $when : '') . ' با موفقیت انجام شد. 🦢' . "\n" .
            'برای مشاهده یا لغو، با همین شماره وارد پنل کاربری‌تان شوید: ' . SITE_URL,
            'event_companion');
        $n++;
    }
    return $n;
}
