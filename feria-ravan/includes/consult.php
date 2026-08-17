<?php
/**
 * ============================================================================
 *  موتور بوکینگ اختصاصی مشاورهٔ فریا روان (نسخهٔ ۱٫۴)
 *  ─ معماری: مشاور ← خدمت ← قانون هفتگی → اسلات (open/held/booked/blocked)
 *  ─ رزرو اتمیک: UPDATE ... WHERE status='open' (ضد رزرو هم‌زمان)
 *  ─ هولد ۱۰ دقیقه‌ای تا پرداخت؛ آزادسازی خودکار هولدهای منقضی (lazy)
 *  ─ تعطیلات شمسی، سیاست لغو، پیامک به مراجع و مشاور — همه راست‌چین و جلالی
 * ============================================================================
 */
declare(strict_types=1);

/** نام فارسی روز هفته بر اساس date('w') پی‌اچ‌پی (۰=یکشنبه ... ۶=شنبه) */
function fr_weekday_fa(int $w): string
{
    return ['یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه','شنبه'][$w] ?? '';
}

/** ترتیب نمایش هفتهٔ ایرانی (از شنبه) — آرایهٔ مقادیر date('w') */
function fr_week_order(): array { return [6,0,1,2,3,4,5]; }

/** تاریخ جلالی امروز به‌صورت عدد صحیح [سال، ماه، روز] — بدون اعداد فارسی برای منطق */
function fr_jnow(): array
{
    [$jy, $jm, $jd] = g2j((int)date('Y'), (int)date('m'), (int)date('d'));
    return [$jy, $jm, $jd];
}

/** دقایق هولد اسلات تا پرداخت */
function consult_hold_minutes(): int { return max(3, (int)DB::setting('consult_hold_minutes', 10)); }

/** حداقل ساعت باقی‌مانده تا جلسه که کاربر می‌تواند لغو کند */
function consult_cancel_hours(): int { return max(0, (int)DB::setting('consult_cancel_hours', 24)); }

/* ---------------------------------------------------------------------------
 | واکشی موجودیت‌ها
 * -------------------------------------------------------------------------*/
/** خدمت مشاوره بر اساس id یا slug (به‌همراه مشاور) */
function consult_service($idOrSlug, bool $activeOnly = true): ?array
{
    $where = is_numeric($idOrSlug) ? 's.id=?' : 's.slug=?';
    $s = DB::one('SELECT s.*, c.name AS consultant_name, c.title AS consultant_title,
                         c.avatar AS consultant_avatar, c.bio AS consultant_bio, c.mobile AS consultant_mobile
                  FROM ' . DB::t('consult_services') . ' s
                  JOIN ' . DB::t('consultants') . ' c ON c.id=s.consultant_id
                  WHERE ' . $where . ($activeOnly ? ' AND s.active=1 AND c.active=1' : ''), [$idOrSlug]);
    return $s ?: null;
}

/** لیست خدمات فعال برای کاتالوگ عمومی */
function consult_services_catalog(): array
{
    return DB::all('SELECT s.*, c.name AS consultant_name, c.title AS consultant_title, c.avatar AS consultant_avatar,
                       (SELECT COUNT(*) FROM ' . DB::t('consult_slots') . ' sl
                         WHERE sl.service_id=s.id AND sl.status=\'open\' AND sl.starts_at>NOW()) AS open_slots
                    FROM ' . DB::t('consult_services') . ' s
                    JOIN ' . DB::t('consultants') . ' c ON c.id=s.consultant_id
                    WHERE s.active=1 AND c.active=1 ORDER BY s.sort, s.id');
}

/** آیا تاریخ میلادی داده‌شده (Y-m-d) تعطیل/مسدود است؟ */
function consult_is_holiday(string $gYmd): bool
{
    // jdate اعداد فارسی می‌دهد؛ برای مقایسه با مقادیر ASCII ذخیره‌شده حتماً en_num می‌شود
    [$jy, $jm, $jd] = g2j((int)substr($gYmd, 0, 4), (int)substr($gYmd, 5, 2), (int)substr($gYmd, 8, 2));
    $j = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    return (bool)DB::val('SELECT id FROM ' . DB::t('consult_holidays') . ' WHERE jdate=? AND active=1', [$j]);
}

/** نرمال‌سازی تاریخ شمسی ورودی (۱۴۰۵/۶/۵ → ۱۴۰۵/۰۶/۰۵) — خروجی null یعنی نامعتبر */
function consult_normalize_jdate(string $j): ?string
{
    $j = en_num(trim($j));
    if (!preg_match('#^(1[34]\d\d)[-/](\d{1,2})[-/](\d{1,2})$#', $j, $m)) return null;
    $jy = (int)$m[1]; $jm = (int)$m[2]; $jd = (int)$m[3];
    if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > ($jm <= 6 ? 31 : ($jm <= 11 ? 30 : 30))) return null;
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/* ---------------------------------------------------------------------------
 | آزادسازی هولدهای منقضی — قبل از هر خواندن وضعیت اسلات‌ها صدا زده می‌شود
 * -------------------------------------------------------------------------*/
function consult_release_expired_holds(): void
{
    static $done = false; if ($done) return; $done = true;   // یک‌بار در هر درخواست
    $expired = DB::all('SELECT id FROM ' . DB::t('consult_slots') .
                       " WHERE status='held' AND held_until IS NOT NULL AND held_until < NOW()");
    if (!$expired) return;
    foreach ($expired as $sl) {
        DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='open', held_until=NULL WHERE id=? AND status='held'", [$sl['id']]);
        // رزروهای در انتظار پرداختِ این اسلات لغو و سفارششان باطل می‌شود
        $apps = DB::all('SELECT id, order_id FROM ' . DB::t('consult_appointments') .
                        " WHERE slot_id=? AND status='pending_payment'", [$sl['id']]);
        foreach ($apps as $ap) {
            DB::run('UPDATE ' . DB::t('consult_appointments') . " SET status='canceled' WHERE id=?", [$ap['id']]);
            if ($ap['order_id'])
                DB::run('UPDATE ' . DB::t('orders') . " SET status='canceled' WHERE id=? AND status='pending'", [$ap['order_id']]);
        }
    }
}

/* ---------------------------------------------------------------------------
 | تولید اسلات از قوانین هفتگی
 * -------------------------------------------------------------------------*/
/**
 * تولید اسلات برای N هفتهٔ آینده بر اساس قوانین فعال خدمت
 * به‌طور خودکار تعطیلات و زمان‌های گذشته را رد می‌کند؛ با کلید یکتا ضدتکرار است.
 * @return int تعداد اسلات‌های جدید ساخته‌شده
 */
function consult_generate_slots(int $serviceId, int $weeks): int
{
    $weeks   = max(1, min(12, $weeks));
    $service = DB::one('SELECT * FROM ' . DB::t('consult_services') . ' WHERE id=?', [$serviceId]);
    if (!$service) return 0;
    $dur  = max(15, (int)$service['duration_min']);
    $gap  = max(0, (int)($service['gap_minutes'] ?? 0));       // تأخیر/استراحت بین جلسات (۱٫۶)
    $step = ($dur + $gap) * 60;                              // فاصلهٔ شروع هر نوبت از قبلی
    $rules = DB::all('SELECT * FROM ' . DB::t('consult_schedules') . ' WHERE service_id=? AND active=1', [$serviceId]);
    if (!$rules) return 0;

    $byWeekday = [];
    foreach ($rules as $r) $byWeekday[(int)$r['weekday']][] = $r;

    $added = 0;
    $today = strtotime(date('Y-m-d 00:00:00'));
    for ($i = 0; $i < $weeks * 7; $i++) {
        $dayTs = $today + $i * 86400;
        $gYmd  = date('Y-m-d', $dayTs);
        $w     = (int)date('w', $dayTs);
        if (empty($byWeekday[$w])) continue;
        if (consult_is_holiday($gYmd)) continue;
        foreach ($byWeekday[$w] as $rule) {
            $t = strtotime($gYmd . ' ' . substr((string)$rule['start_time'], 0, 5) . ':00');
            $endDay = strtotime($gYmd . ' ' . substr((string)$rule['end_time'], 0, 5) . ':00');
            while ($t + $dur * 60 <= $endDay) {
                $starts = date('Y-m-d H:i:s', $t);
                $ends   = date('Y-m-d H:i:s', $t + $dur * 60);
                if ($t > time()) {   // گذشته تولید نمی‌شود
                    // درج ضدتکرار با کلید یکتای (service_id, starts_at)
                    $st = DB::run('INSERT IGNORE INTO ' . DB::t('consult_slots') .
                                  ' (service_id, starts_at, ends_at) VALUES (?,?,?)',
                                  [$serviceId, $starts, $ends]);
                    $added += $st->rowCount();
                }
                $t += $step;   // مدت جلسه + تأخیر بین جلسات
            }
        }
    }
    return $added;
}

/* ---------------------------------------------------------------------------
 | تقویم ماهانهٔ شمسی + اسلات‌های روز
 * -------------------------------------------------------------------------*/
/**
 * ساخت دادهٔ تقویم یک ماه شمسی برای رندر جاوااسکریپت
 * @return array ['jy','jm','month','start_weekday','days'=>[['d'=>روز,'n'=>تعداد آزاد,'off'=>bool]],'prev'=>[jy,jm],'next'=>[jy,jm]]
 */
function consult_month(int $serviceId, int $jy, int $jm): array
{
    [$jy0, $jm0] = fr_jnow();                      // منطق با اعداد ASCII — نه خروجی fa jdate
    if ($jy < 1300 || $jy > 1500) $jy = $jy0;
    if ($jm < 1 || $jm > 12)      $jm = $jm0;
    $daysInMonth = $jm <= 6 ? 31 : ($jm <= 11 ? 30 : (fr_is_leap($jy) ? 30 : 29));

    // اولین روز ماه → میلادی + روز هفته (برای آفست شروع گرید)
    [$gy, $gm, $gd] = j2g($jy, $jm, 1);
    $startW = (int)date('w', strtotime(sprintf('%04d-%02d-%02d', $gy, $gm, $gd)));

    $todayG = date('Y-m-d');
    $monthNames = ['','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

    $days = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        [$gy2, $gm2, $gd2] = j2g($jy, $jm, $d);
        $gYmd  = sprintf('%04d-%02d-%02d', $gy2, $gm2, $gd2);
        $past  = $gYmd <= $todayG;                       // امروز و قبل‌تر غیرفعال (اسلات گذشته عملاً رزرو نمی‌شود)
        $holi  = !$past && consult_is_holiday($gYmd);
        $count = 0;
        if (!$past && !$holi) {
            $count = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('consult_slots') .
                                  " WHERE service_id=? AND status='open' AND starts_at>=? AND starts_at<?",
                                  [$serviceId, $gYmd . ' 00:00:00', date('Y-m-d', strtotime($gYmd . ' +1 day')) . ' 00:00:00']);
        }
        $days[] = ['d'=>$d, 'n'=>$count, 'off'=>($past || $holi), 'holiday'=>$holi];
    }
    return [
        'jy'=>$jy, 'jm'=>$jm, 'month'=>$monthNames[$jm],
        'start_weekday'=>$startW, 'days'=>$days,
        'prev'=>$jm === 1 ? [$jy - 1, 12] : [$jy, $jm - 1],
        'next'=>$jm === 12 ? [$jy + 1, 1] : [$jy, $jm + 1],
    ];
}

/** سال کبیسهٔ شمسی */
function fr_is_leap(int $jy): bool { return in_array($jy % 33, [1,5,9,13,17,22,26,30], true); }

/** اسلات‌های باز یک روز میلادی (Y-m-d) برای رندر چیپ‌های ساعت */
function consult_day_slots(int $serviceId, string $gYmd): array
{
    consult_release_expired_holds();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gYmd)) return [];
    if ($gYmd <= date('Y-m-d')) { /* امروز هم فقط اسلاتهای آیندهٔ ساعتی برمی‌گردد، پس مجاز */ }
    $rows = DB::all('SELECT id, starts_at, ends_at FROM ' . DB::t('consult_slots') .
                    " WHERE service_id=? AND status='open' AND starts_at>=? AND starts_at<? AND starts_at>NOW()
                      ORDER BY starts_at",
                    [$serviceId, $gYmd . ' 00:00:00', date('Y-m-d', strtotime($gYmd . ' +1 day')) . ' 00:00:00']);
    return array_map(fn($r) => [
        'id'    => (int)$r['id'],
        'time'  => date('H:i', strtotime($r['starts_at'])),
        'end'   => date('H:i', strtotime($r['ends_at'])),
        'label' => fa_num(date('H:i', strtotime($r['starts_at']))),
    ], $rows);
}

/* ---------------------------------------------------------------------------
 | رزرو — اتمیک، بدون نیاز به ورود مجدد اطلاعات (از پروفایل کاربر)
 * -------------------------------------------------------------------------*/
/**
 * رزرو اسلات + ساخت سفارش و درگاه پرداخت
 * نسخهٔ ۳٫۰: $payMode='deposit' → فقط بیعانهٔ تعریف‌شدهٔ مدیر گرفته می‌شود و
 * نوبت قطعی (booked) می‌گردد؛ مانده تا نزدیک جلسه با لینک تصفیه تسویه می‌شود.
 * @param ?string $discountCode  کد تخفیف عمومی (۱٫۷) — کدهای اختصاصی تست روانشناسی/رویداد این‌جا معتبر نیستند
 * @return array ['ok'=>bool,'error'=>?string,'payment_url'=>?string,'appointment_id'=>int,'free'=>bool]
 */
function consult_book(array $u, array $service, int $slotId, string $mode, string $note = '', bool $useWallet = false, ?string $discountCode = null, string $payMode = 'full'): array
{
    consult_release_expired_holds();
    $fail = fn(string $m) => ['ok'=>false,'error'=>$m,'payment_url'=>null,'appointment_id'=>0,'free'=>false];

    // ۱) اسلات معتبر و در آینده؟
    $slot = DB::one('SELECT * FROM ' . DB::t('consult_slots') . ' WHERE id=? AND service_id=?', [$slotId, (int)$service['id']]);
    if (!$slot) return $fail('زمان انتخابی یافت نشد.');
    if (strtotime($slot['starts_at']) <= time()) return $fail('این زمان گذشته است؛ زمان دیگری انتخاب کنید.');

    // ۲) حالت جلسه باید با تعریف خدمت سازگار باشد
    $allowed = ['online','inperson'];
    if (!in_array($mode, $allowed, true)) $mode = 'inperson';
    if ($service['mode'] !== 'both' && $service['mode'] !== $mode) $mode = (string)$service['mode'];

    // ۲٫۵) کد تخفیف (نسخهٔ ۱٫۷) — پیش از گرفتن هولد اعتبارسنجی می‌شود تا اسلات بیهوده قفل نشود
    $amount  = (int)$service['price'];
    $discAmt = 0; $d = null;
    $code = trim((string)$discountCode);
    if ($amount > 0 && $code !== '') {
        require_once __DIR__ . '/commerce.php';
        $v = validate_discount($code, 0, (int)$u['id'], $amount, 0);
        if (!$v['ok']) return $fail($v['message']);
        $d = $v['discount']; $discAmt = $v['discount_amt'];
    }

    // ۳) حساس: قفل اتمیک — فقط یک نفر می‌تواند اسلات باز را بگیرد
    $holdMin = consult_hold_minutes();
    $st = DB::run('UPDATE ' . DB::t('consult_slots') .
                  " SET status='held', held_until=DATE_ADD(NOW(), INTERVAL {$holdMin} MINUTE)
                    WHERE id=? AND status='open' AND starts_at>NOW()", [$slotId]);
    if ($st->rowCount() !== 1) return $fail('این زمان لحظاتی پیش رزرو شد؛ لطفاً زمان دیگری انتخاب کنید.');

    // ۴) عدم تداخل با نوبت فعال دیگرِ همین کاربر (در همان بازهٔ زمانی)
    $overlap = DB::val('SELECT COUNT(*) FROM ' . DB::t('consult_appointments') . ' a
                        JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id
                        WHERE a.user_id=? AND a.status IN (\'pending_payment\',\'booked\')
                          AND s.starts_at<? AND s.ends_at>?',
                       [$u['id'], $slot['ends_at'], $slot['starts_at']]);
    if ($overlap) {
        DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='open', held_until=NULL WHERE id=? AND status='held'", [$slotId]);
        return $fail('شما در همین بازهٔ زمانی نوبت دیگری دارید.');
    }

    // ۵) رزرو (pending_payment) — اطلاعات هویتی از پروفایل کاربر خوانده می‌شود، نه ورودی جدید
    $note = mb_substr(trim($note), 0, 500);
    $apptId = DB::insert('consult_appointments', [
        'slot_id'=>$slotId, 'service_id'=>(int)$service['id'], 'consultant_id'=>(int)$service['consultant_id'],
        'user_id'=>(int)$u['id'], 'mode'=>$mode, 'note'=>$note !== '' ? $note : null,
        'status'=>'pending_payment',
    ]);
    log_activity('consult_hold', 'consult_appointment', $apptId, ['slot'=>$slotId]);

    // ۶) مسیر رایگان — نهایی فوری
    if ((int)$service['price'] === 0) {
        consult_confirm_appointment($apptId);
        require_once __DIR__ . '/notify.php';
        fr_notify_admins_sms('admin_new_order', [
            'name'   => user_fullname($u), 'mobile' => $u['mobile'],
            'test'   => 'رزرو مشاوره «' . $service['title'] . '»', 'amount' => 0, 'refid' => 'رایگان',
        ]);
        return ['ok'=>true,'error'=>null,'payment_url'=>null,'appointment_id'=>$apptId,'free'=>true];
    }

    // ۷) سفارش + درگاه زرین‌پال
    /* ۳٫۰ — حالت پیش‌پرداخت: فقط بیعانه همین حالا گرفته می‌شود؛ نوبت قطعی و مانده بعداً */
    $payable0 = $amount - $discAmt;
    $depositNow = 0;
    if ($payMode === 'deposit') {
        require_once __DIR__ . '/deposit.php';
        $depositNow = fr_deposit_charge($service, $payable0);
    }
    $stage = $depositNow > 0 ? 'deposit' : 'full';

    $orderId = DB::insert('orders', [
        'user_id'=>(int)$u['id'], 'test_id'=>null, 'event_id'=>null, 'kind'=>'consult',
        'amount'=>$amount, 'discount_code'=>$d['code'] ?? null,
        'discount_amt'=>$discAmt, 'payable'=>$payable0,
        'pay_stage'=>$stage,
    ]);
    DB::run('UPDATE ' . DB::t('consult_appointments') . ' SET order_id=? WHERE id=?', [$orderId, $apptId]);

    // رزرو ظرفیت کد تخفیف (همان الگوی تست روانشناسی/رویداد)
    if ($d) {
        DB::insert('discount_usage', ['discount_id'=>$d['id'], 'user_id'=>$u['id'], 'order_id'=>$orderId]);
        DB::run('UPDATE ' . DB::t('discounts') . ' SET used_count=used_count+1 WHERE id=?', [$d['id']]);
    }

    $desc = ($depositNow > 0 ? 'پیش‌پرداخت رزرو مشاوره «' : 'رزرو مشاوره «')
          . $service['title'] . '» — ' . jdate('l j F Y ساعت H:i', strtotime($slot['starts_at']));
    $payable = $payable0;

    // ۷٫۰) مسیر رایگان با تخفیف ۱۰۰٪ — بدون درگاه
    if ($payable === 0) {
        mark_order_paid($orderId, 'FREE-' . fr_token(8), null);
        return ['ok'=>true,'error'=>null,'payment_url'=>null,'appointment_id'=>$apptId,'free'=>true,'discount'=>true];
    }

    /* مبلغی که همین حالا پرداخت می‌شود: بیعانه (در حالت پیش‌پرداخت) یا کل مانده */
    $chargeNow = $depositNow > 0 ? $depositNow : $payable;

    // ۷٫۱) پرداخت از کیف پول (کل یا بخشی) — نسخهٔ ۱٫۶
    if ($useWallet) {
        require_once __DIR__ . '/wallet.php';
        $wp = wallet_pay_part($orderId, (int)$u['id'], $chargeNow, $desc . ' — پرداخت از کیف پول');
        if ($wp['gateway'] <= 0) {
            // کل مبلغِ فعلی (بیعانه یا کل) با کیف پول رفت — mark_order_paid مرحله را جلو برده
            return ['ok'=>true,'error'=>null,'payment_url'=>null,'appointment_id'=>$apptId,'free'=>true,'wallet'=>true,'deposit'=>$depositNow];
        }
        $chargeNow = $wp['gateway'];        // فقط مانده از درگاه
    }

    $zp   = new Zarinpal();
    $res  = $zp->request($orderId, $chargeNow, $desc, (string)$u['mobile']);
    if (!$res['ok']) {
        // بازگردانی کامل: بخش کیف پول به کیف برمی‌گردد، اسلات آزاد، رزرو و سفارش باطل
        require_once __DIR__ . '/wallet.php';
        fr_cancel_dead_order($orderId, 'خطای اتصال به درگاه');
        DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='open', held_until=NULL WHERE id=? AND status='held'", [$slotId]);
        return $fail('خطا در اتصال به درگاه پرداخت: ' . $res['message']);
    }
    DB::insert('payments', [
        'order_id'=>$orderId, 'authority'=>$res['authority'], 'amount'=>$chargeNow,
        'ip'=>client_ip_bin(), 'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    log_activity('pay_init', 'order', $orderId, ['consult'=>(int)$service['id'],'slot'=>$slotId,'stage'=>$stage]);
    return ['ok'=>true,'error'=>null,'payment_url'=>$res['url'],'appointment_id'=>$apptId,'free'=>false,'deposit'=>$depositNow];
}

/**
 * نهایی‌سازی رزرو (پرداخت موفق یا رایگان) — booked + پیامک به مراجع و مشاور
 */
function consult_confirm_appointment(int $apptId): void
{
    $a = DB::one('SELECT a.*, s.starts_at, s.ends_at FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id WHERE a.id=?', [$apptId]);
    if (!$a || $a['status'] === 'booked') return;
    DB::run('UPDATE ' . DB::t('consult_appointments') . " SET status='booked' WHERE id=?", [$apptId]);
    DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='booked', held_until=NULL WHERE id=?", [$a['slot_id']]);
    consult_notify_booking($apptId, 'confirmed');
}

/** پیامک رزرو/لغو به مراجع و مشاور */
function consult_notify_booking(int $apptId, string $event): void
{
    $a = DB::one('SELECT a.*, s.starts_at, sv.title AS service_title, sv.meeting_hint,
                         c.name AS cname, c.mobile AS cmobile, u.mobile AS umobile, u.first_name, u.last_name
                  FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id
                  JOIN ' . DB::t('consult_services') . ' sv ON sv.id=a.service_id
                  JOIN ' . DB::t('consultants') . ' c ON c.id=a.consultant_id
                  JOIN ' . DB::t('users') . ' u ON u.id=a.user_id
                  WHERE a.id=?', [$apptId]);
    if (!$a) return;
    $site = (string)DB::setting('site_title', 'فریا روان');
    $when = jdate('l j F Y ساعت H:i', strtotime($a['starts_at']));
    $modeFa = $a['mode'] === 'online' ? 'آنلاین' : 'حضوری';
    $name = trim($a['first_name'] . ' ' . $a['last_name']) ?: 'مراجع';
    $kv = new Kavenegar();

    if ($event === 'confirmed') {
        $hint = $a['meeting_hint'] ? ("\\n" . ($a['mode'] === 'online' ? 'لینک جلسه: ' : 'نشانی: ') . $a['meeting_hint']) : '';
        $kv->send($a['umobile'],
            consult_sms_render('sms_tpl_consult_book_user',
                '{name} عزیز، نوبت «{service}» ({mode}) شما نزد {consultant} برای {when} ثبت شد. 🦢{hint}',
                $name, $a['service_title'], $modeFa, $a['cname'], $when, $hint) . "\\n" . $site, 'consult_confirmed');
        if (!empty($a['cmobile']) && valid_mobile((string)$a['cmobile'])) {
            $kv->send((string)$a['cmobile'],
                consult_sms_render('sms_tpl_consult_book_cons',
                    'نوبت جدید: «{service}» ({mode}) — {name} — {when}',
                    $name, $a['service_title'], $modeFa, $a['cname'], $when, '') . "\\n" . $site, 'consult_admin');
        }
    } elseif ($event === 'reminded') {
        // یادآوری نوبت (ارسال دستی از پنل مدیر)
        $kv->send($a['umobile'],
            consult_sms_render('sms_tpl_consult_remind',
                '{name} عزیز، یادآوری: نوبت «{service}» شما نزد {consultant} برای {when} است. 🦢',
                $name, $a['service_title'], $modeFa, $a['cname'], $when, '') . "\\n" . $site, 'consult_reminded');
    } elseif ($event === 'canceled') {
        $kv->send($a['umobile'],
            consult_sms_render('sms_tpl_consult_cancel',
                '{name} عزیز، نوبت «{service}» مورخ {when} لغو شد.',
                $name, $a['service_title'], $modeFa, $a['cname'], $when, '') . "\\n" . $site, 'consult_canceled');
        if (!empty($a['cmobile']) && valid_mobile((string)$a['cmobile'])) {
            $kv->send((string)$a['cmobile'],
                consult_sms_render('sms_tpl_consult_cancel',
                    'لغو نوبت: «{service}» — {name} — {when}',
                    $name, $a['service_title'], $modeFa, $a['cname'], $when, '') . "\\n" . $site, 'consult_admin');
        }
    }
}

/**
 * رندر قالب پیامک مشاوره از تنظیمات — نسخهٔ ۱٫۶
 * متغیرها: {name} {service} {mode} {consultant} {when} {hint}
 * اگر مدیر قالب را خالی گذاشته باشد، متن پیش‌فرض امن استفاده می‌شود.
 */
function consult_sms_render(string $settingKey, string $fallback, string $name, string $service, string $mode, string $consultant, string $when, string $hint): string
{
    $tpl = trim((string)DB::setting($settingKey, ''));
    if ($tpl === '') $tpl = $fallback;
    return strtr($tpl, [
        '{name}'       => $name,
        '{service}'    => $service,
        '{mode}'       => $mode,
        '{consultant}' => $consultant,
        '{when}'       => $when,
        '{hint}'       => $hint,
    ]);
}

/** لغو رزرو توسط خود کاربر (رعایت سیاست ساعتی) — اسلات آینده دوباره باز می‌شود */
function consult_cancel_by_user(int $apptId, int $userId): array
{
    $a = DB::one('SELECT a.*, s.starts_at FROM ' . DB::t('consult_appointments') . ' a
                  JOIN ' . DB::t('consult_slots') . ' s ON s.id=a.slot_id
                  WHERE a.id=? AND a.user_id=?', [$apptId, $userId]);
    if (!$a) return ['ok'=>false,'message'=>'نوبت یافت نشد.'];
    if ($a['status'] !== 'booked' && $a['status'] !== 'pending_payment')
        return ['ok'=>false,'message'=>'این نوبت قابل لغو نیست.'];
    $remain = strtotime($a['starts_at']) - time();
    if ($a['status'] === 'booked' && $remain < consult_cancel_hours() * 3600)
        return ['ok'=>false,'message'=>'تا شروع جلسه کمتر از ' . fa_num(consult_cancel_hours()) . ' ساعت مانده و لغو آنلاین ممکن نیست؛ با پشتیبانی در تماس باشید.'];

    DB::run('UPDATE ' . DB::t('consult_appointments') . " SET status='canceled' WHERE id=?", [$apptId]);
    if ($a['order_id'])
        DB::run('UPDATE ' . DB::t('orders') . " SET status='canceled' WHERE id=? AND status='pending'", [$a['order_id']]);
    // آزادسازی اسلات اگر هنوز در آینده است
    if (strtotime($a['starts_at']) > time())
        DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='open', held_until=NULL WHERE id=?", [$a['slot_id']]);
    log_activity('consult_cancel', 'consult_appointment', $apptId);
    if ($a['status'] === 'booked') consult_notify_booking($apptId, 'canceled');
    return ['ok'=>true,'message'=>'نوبت شما لغو شد' . ($a['status'] === 'booked' ? '؛ هماهنگی عودت وجه با پشتیبانی انجام می‌شود.' : '.')];
}

/* ---------------------------------------------------------------------------
 | تولید کاور SVG پیش‌فرض برای خدمات بدون تصویر (سبک اختصاصی فریا)
 * -------------------------------------------------------------------------*/
function consult_mode_fa(string $mode): string
{
    return ['online'=>'آنلاین','inperson'=>'حضوری','both'=>'آنلاین یا حضوری'][$mode] ?? $mode;
}

/* ---------------------------------------------------------------------------
 | تعطیلات پیش‌فرض سالیانه (نسخهٔ ۱٫۶) — فهرست آمادهٔ تعطیلات رسمی
 |  ٭ تاریخ‌های شمسیِ ثابت (نوروز، ۲۲ بهمن و…) قطعی‌اند.
 |  ٭ مناسبت‌های قمری بر اساس اعلام شورای مرکز تقویم آمده‌اند و ممکن است
 |    (مثل هر سال) یک روز جابه‌جا شوند — مدیر می‌تواند ویرایش/حذف کند و یا
 |    فایل‌ اکسل تقویم رسمی را بارگذاری نماید.
 * -------------------------------------------------------------------------*/
function fr_default_holidays(): array
{
    $fixed = function (string $jy): array {
        // مناسبت‌های خورشیدیِ ثابت هر سال
        return [
            ["$jy/01/01", 'عید نوروز'],
            ["$jy/01/02", 'عید نوروز'],
            ["$jy/01/03", 'عید نوروز'],
            ["$jy/01/04", 'عید نوروز'],
            ["$jy/01/12", 'روز جمهوری اسلامی'],
            ["$jy/01/13", 'روز طبیعت (سیزده‌بدر)'],
            ["$jy/06/14", 'رحلت امام خمینی (ره)'],
            ["$jy/06/15", 'قیام ۱۵ خرداد'],
            ["$jy/11/22", 'پیروزی انقلاب اسلامی'],
        ];
    };
    $out = [];
    foreach (['1405','1406'] as $jy) foreach ($fixed($jy) as $r) $out[] = $r;

    // مناسبت‌های قمری ۱۴۰۵ (اعلامی تقویم رسمی)
    foreach ([
        ['1405/03/06','عید سعید قربان'],
        ['1405/03/14','عید سعید غدیر خم'],
        ['1405/04/03','تاسوعای حسینی'],
        ['1405/04/04','عاشورای حسینی'],
        ['1405/05/13','اربعین حسینی'],
        ['1405/05/21','رحلت پیامبر اکرم (ص) و شهادت امام حسن مجتبی (ع)'],
        ['1405/05/22','شهادت امام رضا (ع)'],
        ['1405/05/30','شهادت امام حسن عسکری (ع)'],
        ['1405/12/09','شهادت حضرت علی (ع)'],
        ['1405/12/19','عید سعید فطر'],
        ['1405/12/20','تعطیلی به مناسبت عید فطر'],
        ['1405/12/29','روز ملی شدن صنعت نفت'],
    ] as $r) $out[] = $r;

    // ملی شدن صنعت نفت (خورشیدیِ ثابت: ۲۹ اسفند؟ خیر — ۲۹ اسفند) برای ۱۴۰۶
    $out[] = ['1406/12/29', 'روز ملی شدن صنعت نفت'];

    // حذف دوباره‌کاری احتمالی و مرتب‌سازی
    $uniq = [];
    foreach ($out as [$d,$t]) { $uniq[$d] ??= ''; $uniq[$d] = $uniq[$d] !== '' ? $uniq[$d] . ' / ' . $t : $t; }
    ksort($uniq);
    $res = [];
    foreach ($uniq as $d => $t) $res[] = [$d, $t];
    return $res;
}

/** درج تعطیلات پیش‌فرض (تکراری‌ها رونویسی نمی‌شوند) — تعداد اقلام تازه برمی‌گرداند */
function fr_seed_default_holidays(?array $list = null): int
{
    $list = $list ?? fr_default_holidays();
    $added = 0;
    foreach ($list as [$d, $t]) {
        $j = consult_normalize_jdate((string)$d);
        if (!$j) continue;
        $exists = DB::val('SELECT id FROM ' . DB::t('consult_holidays') . ' WHERE jdate=?', [$j]);
        if ($exists) continue;
        DB::insert('consult_holidays', ['jdate' => $j, 'title' => mb_substr((string)$t, 0, 180) ?: null, 'active' => 1]);
        $added++;
    }
    return $added;
}

/**
 * واردکردن ردیف‌های تعطیلات (از فایل اکسل/CSV) — هر ردیف: [تاریخ شمسی, عنوان]
 * خروجی: ['added'=>int,'skipped'=>int,'bad'=>int]
 */
function fr_import_holiday_rows(array $rows): array
{
    $added = 0; $skipped = 0; $bad = 0;
    foreach ($rows as $row) {
        $date  = (string)($row[0] ?? '');
        $title = mb_substr(trim((string)($row[1] ?? '')), 0, 180) ?: null;
        $j = consult_normalize_jdate($date);
        if (!$j) { $bad++; continue; }
        $exists = DB::val('SELECT id FROM ' . DB::t('consult_holidays') . ' WHERE jdate=?', [$j]);
        if ($exists) { $skipped++; continue; }
        DB::insert('consult_holidays', ['jdate' => $j, 'title' => $title, 'active' => 1]);
        $added++;
    }
    return ['added' => $added, 'skipped' => $skipped, 'bad' => $bad];
}

/**
 * به‌روزرسانی تعطیلات از time.ir (تلاش بر اساس بهترین حدس — اگر سرور دسترسی
 | به اینترنت نداشته باشد یا ساختار سایت عوض شده باشد، مودبانه خطا می‌دهد و
 | مدیر می‌تواند به‌جای آن فایل اکسل بارگذاری کند).
 * خروجی: ['ok'=>bool,'added'=>int,'error'=>?string]
 */
function fr_timeir_sync(int $jyear): array
{
    if ($jyear < 1400 || $jyear > 1410) return ['ok'=>false,'added'=>0,'error'=>'سال نامعتبر است.'];
    $url = 'https://www.time.ir/fa/eventyear-تقویم-سالانه-' . $jyear;
    $html = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FeriaRavan/1.6)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $html = @curl_exec($ch);
        $code = (int)@curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($html)) $html = null;
    } else {
        $html = @file_get_contents($url) ?: null;
    }
    if (!$html) {
        return ['ok'=>false,'added'=>0,
                'error'=>'ارتباط با time.ir برقرار نشد؛ لطفاً با دکمهٔ «بارگذاری فایل اکسل» فهرست رسمی را وارد کنید.'];
    }
    // یافتن جفت‌های «تاریخ + عنوان» در قالب رایج صفحه (بهترین تلاش — بدون شکست سایت در صورت تغییر ساختار)
    $rows = [];
    if (preg_match_all('#(1[34]\d\d)[/-](\d{1,2})[/-](\d{1,2})#u', $html, $dm, PREG_SET_ORDER)) {
        foreach ($dm as $m) $rows[] = [sprintf('%04d/%02d/%02d', (int)$m[1], (int)$m[2], (int)$m[3]), 'تعطیلی رسمی (time.ir)'];
    }
    // عنوان‌های دقیق‌تر از نشانه‌های متداول
    if (preg_match_all('/dateHoliday[^>]*>([^<]{3,120})</u', $html, $tm)) {
        // آدرس‌های هم‌تراز با تاریخ‌ها در عمل سخت است؛ اگر تعداد برابر بود جایگزین می‌کنیم
        if (count($tm[1]) === count($rows) && $rows) {
            foreach ($rows as $i => &$r) $r[1] = trim($tm[1][$i]);
            unset($r);
        }
    }
    if (!$rows) {
        return ['ok'=>false,'added'=>0,
                'error'=>'ساختار صفحهٔ time.ir تغییر کرده؛ لطفاً فایل اکسل تقویم رسمی را بارگذاری کنید.'];
    }
    $st = fr_import_holiday_rows($rows);
    if (($st['added'] + $st['skipped']) === 0) {
        return ['ok'=>false,'added'=>0,'error'=>'تاریخ معتبری در صفحهٔ time.ir پیدا نشد؛ از فایل اکسل استفاده کنید.'];
    }
    return ['ok'=>true,'added'=>$st['added'],'error'=>null];
}

