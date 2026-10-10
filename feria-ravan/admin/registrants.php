<?php
/**
 * ============================================================================
 *  مدیریت کامل ثبت‌نام‌شدگان — نسخهٔ ۱٫۹
 *  ─ کارگاه‌ها/دوره‌ها: لیست اعضای هر رویداد + افزودن دستی (رایگان/با رکورد مالی)
 *    + ویرایش (نام/موبایل/وضعیت/تعداد همراه) + حذفِ امن (گارد سوابق مالی)
 *  ─ تست‌های روانشناسی: لیست شرکت‌کنندگان هر تست روانشناسی + اعطای دسترسی دستی (رایگان/ویژه)
 *    + ویرایش + حذف تلاش (کاسکید پاسخ/نتیجه/گزارش)
 *  ─ قاعدهٔ طلایی: حذف فیزیکی فقط وقتی هیچ رد مالیِ پرداخت‌شده‌ای در کار نباشد؛
 *    درآمدِ ثبت‌شده در سفارش‌ها هرگز پاک نمی‌شود.
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin','manager']);
require_once __DIR__ . '/../includes/event-pricing.php';
require_once __DIR__ . '/../includes/deposit.php';

$CTXS = ['workshop' => '🛠 کارگاه‌ها', 'course' => '📚 دوره‌ها', 'test' => '🧠 تست‌های روانشناسی'];
/* ۲٫۳ — رفع باگ: وقتی ctx در نشانی نیست، پیش‌فرض واقعی «workshop» اعمال شود (قبلاً رشتهٔ خالی می‌شد و فهرست انتخاب‌گر خالی و اطلاعات ناپیدا می‌ماند) */
$ctxRaw = (string)($_GET['ctx'] ?? 'workshop');
$ctx  = in_array($ctxRaw, array_keys($CTXS), true) ? $ctxRaw : 'workshop';
/* انتخاب یکپارچه از سلکتور گروه‌بندی‌شده: pick=kind|id */
if (!empty($_GET['pick']) && str_contains((string)$_GET['pick'], '|')) {
    [$pk, $pid] = explode('|', (string)$_GET['pick'], 2);
    if (isset($CTXS[$pk])) { $ctx = $pk; $_GET['ctx'] = $pk; $_GET['id'] = (int)$pid; }
}
$id   = (int)($_GET['id'] ?? 0);

/** آدرس کمکی حفظ کانتکست — مقدار null در $extra یعنی حذف آن پارامتر */
$back = function (array $extra = []) use ($ctx, $id): string {
    $qs = array_merge(['ctx' => $ctx, 'id' => $id], $_GET);
    foreach ($extra as $k => $v) { if ($v === null) unset($qs[$k]); else $qs[$k] = $v; }
    return SITE_URL . '/admin/registrants.php?' . http_build_query($qs);
};

/* ───────────────────────── تجزیهٔ متن همراهان ─────────────────────────
   هر خط: «نام نام‌خانوادگی 09xxxxxxxxx» — موبایل با الگو استخراج می‌شود؛
   قانون سایت (۱٫۷): همراهِ بدون موبایل معتبر پذیرفته نیست. */
function fr_parse_companion_lines(string $txt, int $max = 0): array
{
    $out = []; $bad = [];
    foreach (preg_split('/\R/u', $txt) as $line) {
        $line = trim(str_replace(['،', ','], ' ', (string)$line));
        if ($line === '') continue;
        if (!preg_match('/09\d{9}/u', en_num($line), $m)) { $bad[] = $line; continue; }
        $mob  = $m[0];
        $name = trim(preg_replace('/09\d{9}/u', '', en_num($line)));
        if ($name === '') { $bad[] = $line . ' (بدون نام)'; continue; }
        [$fn, $ln] = fr_split_fullname($name);
        if ($max > 0 && count($out) >= $max) { $bad[] = $line . ' (بیش از سقف همراه)'; continue; }
        $out[] = ['first_name' => $fn, 'last_name' => $ln, 'mobile' => $mob];
    }
    return [$out, $bad];
}

/* ════════════════════════ عملیات POST ════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');

    /* ─────────── رویداد: افزودن دستی عضو ─────────── */
    if ($act === 'reg_add') {
        $eid = (int)post('event_id');
        $ev  = DB::one('SELECT * FROM ' . DB::t('events') . " WHERE id=? AND kind IN('workshop','course')", [$eid]);
        $mob = en_num(trim((string)post('mobile')));
        $fn  = trim((string)post('first_name')); $ln = trim((string)post('last_name'));
        if (!$ev)                       { flash('رویداد یافت نشد.', 'err'); redirect($back(['id' => $eid])); }
        if (!valid_mobile($mob))        { flash('شمارهٔ موبایل معتبر نیست.', 'err'); redirect($back()); }
        $uid = fr_companion_user_upsert($mob, $fn, $ln);
        if (!$uid)                      { flash('خطا در ساخت حساب کاربر.', 'err'); redirect($back()); }
        if (DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') . ' WHERE event_id=? AND user_id=?', [$eid, $uid])) {
            flash('⚠️ این کاربر قبلاً در این رویداد ثبت‌نام شده است؛ از «ویرایش» استفاده کنید.', 'err');
            redirect($back());
        }

        /* همراهان */
        $maxC = (int)$ev['companion_enabled'] === 1 ? ($ev['companion_max'] !== null ? (int)$ev['companion_max'] : 0) : -1;
        [$comps, $badLines] = fr_parse_companion_lines((string)post('companions_text'), max(0, $maxC));
        if ($maxC === -1 && (trim((string)post('companions_text')) !== '')) {
            $badLines[] = 'ثبت‌نام همراه برای این رویداد غیرفعال است.';
            $comps = [];
        }

        /* قیمت‌گذاری با موتور رسمی (بدون کد تخفیف؛ مدرک دستی در متادیتا ثبت می‌شود) */
        $q       = ev_quote($ev, count($comps), null);
        $paidRec = post('pay_type') === 'paid';

        try {
            DB::pdo()->beginTransaction();
            $regData = [
                'event_id'          => (int)$ev['id'],
                'user_id'           => $uid,
                'companion_count'   => (int)$q['companion_count'],
                'companion_amount'  => (int)$q['companion_total'],
                'amount_base'       => (int)$q['subtotal'],
                'discount_meta'     => json_encode($q + ['manual_add' => true, 'by' => $adm['username']], JSON_UNESCAPED_UNICODE),
                'status'            => 'paid',
                'paid_total'        => $paidRec ? (int)$q['payable'] : 0,   // ۳٫۰: رهگیری مبلغ واقعی پرداخت‌شده
            ];
            $oid = null;
            if ($paidRec) {
                $oid = DB::insert('orders', [
                    'user_id' => $uid, 'event_id' => (int)$ev['id'], 'kind' => 'event',
                    'amount' => (int)$q['subtotal'], 'discount_amt' => (int)$q['discount_amount'],
                    'payable' => (int)$q['payable'], 'status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'),
                ]);
                $manRef = 'MANUAL-' . date('Ymd') . '-' . $oid;
                DB::insert('payments', [
                    'order_id' => $oid, 'gateway' => 'manual', 'authority' => $manRef, 'ref_id' => $manRef,
                    'amount' => (int)$q['payable'], 'status' => 'ok', 'verified_at' => date('Y-m-d H:i:s'),
                    'ip' => client_ip_bin(), 'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
                $regData['order_id'] = $oid;
            } else {
                $regData['discount_code']   = 'MANUAL-FREE';
                $regData['discount_amount'] = (int)$q['subtotal'];    // کل مبلغ بخشیده شد
            }
            $regId = DB::insert('event_registrations', $regData);
            ev_companions_save($regId, $comps, $ev);
            DB::pdo()->commit();
            log_activity('admin_reg_add', 'event', (int)$ev['id'], ['reg' => $regId, 'user' => $uid, 'paid_record' => $paidRec ? $oid : null]);
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
            flash('خطا در ثبت عضو: ' . $e->getMessage(), 'err');
            redirect($back());
        }

        if (post('send_sms')) {
            try {
                require_once __DIR__ . '/../includes/notify.php';
                $uRow = DB::one('SELECT * FROM ' . DB::t('users') . ' WHERE id=?', [$uid]);
                fr_event_user_sms($ev, $uRow, $paidRec ? (int)$q['payable'] : 0, $paidRec ? 'MANUAL' : '');
            } catch (Throwable $e) { /* پیامک هرگز نباید ثبت را خراب کند */ }
        }
        $msg = 'عضو جدید ثبت شد ✅ (' . ($paidRec ? 'با رکورد پرداخت ' . toman((int)$q['payable']) : 'ثبت دستی/رایگان') . ')';
        if ($badLines) $msg .= ' — خطوط نادیده‌گرفته‌شده همراه: ' . implode(' ؛ ', $badLines);
        flash($msg, $badLines ? 'warn' : 'ok');
        redirect($back());
    }

    /* ─────────── رویداد: ویرایش عضو ─────────── */
    if ($act === 'reg_save') {
        $rid = (int)post('reg_id');
        $r   = DB::one('SELECT * FROM ' . DB::t('event_registrations') . ' WHERE id=?', [$rid]);
        if (!$r) { flash('ثبت‌نام یافت نشد.', 'err'); redirect($back()); }
        $fn  = trim((string)post('first_name')); $ln = trim((string)post('last_name'));
        $mob = en_num(trim((string)post('mobile')));
        $st  = in_array(post('status'), ['pending','deposit','paid','canceled'], true) ? (string)post('status') : $r['status'];
        $cc  = max(0, min(200, (int)en_num((string)post('companion_count', (string)$r['companion_count']))));
        if (!valid_mobile($mob)) { flash('شمارهٔ موبایل معتبر نیست.', 'err'); redirect($back(['edit_reg' => $rid])); }

        $uid = (int)$r['user_id'];
        $cur = DB::one('SELECT mobile FROM ' . DB::t('users') . ' WHERE id=?', [$uid]);
        if ($cur && (string)$cur['mobile'] !== $mob) {
            /* تغییر موبایل = اتصال ثبت‌نام به حسابِ آن موبایل (در صورت نبود، ساخته می‌شود) */
            $newUid = fr_companion_user_upsert($mob, $fn, $ln);
            if (DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') . ' WHERE event_id=? AND user_id=? AND id<>?',
                         [$r['event_id'], $newUid, $rid])) {
                flash('⚠️ این موبایل از قبل یک ثبت‌نامِ دیگر در همین رویداد دارد؛ امکان ادغام نیست.', 'err');
                redirect($back(['edit_reg' => $rid]));
            }
            $uid = (int)$newUid;
        }
        DB::update('users', ['first_name' => mb_substr($fn, 0, 100), 'last_name' => $ln !== '' ? mb_substr($ln, 0, 100) : null], 'id=?', [$uid]);
        DB::update('event_registrations', [
            'user_id' => $uid, 'status' => $st, 'companion_count' => $cc,
        ], 'id=?', [$rid]);
        log_activity('admin_reg_save', 'event', (int)$r['event_id'], ['reg' => $rid, 'status' => $st]);
        flash('عضو به‌روزرسانی شد ✅ (مبالغ سفارش‌های قبلی به‌صورت اسنپ‌شات حفظ می‌شوند)');
        redirect($back());
    }

    /* ─────────── رویداد: حذف عضو (امن) ─────────── */
    if ($act === 'reg_del') {
        $rid = (int)post('reg_id');
        $r   = DB::one('SELECT r.*, u.first_name, u.last_name FROM ' . DB::t('event_registrations') . ' r
                        JOIN ' . DB::t('users') . ' u ON u.id=r.user_id WHERE r.id=?', [$rid]);
        if (!$r) { flash('ثبت‌نام یافت نشد.', 'err'); redirect($back()); }
        /* گارد سوابق مالی: درخواست عودتِ باز/ثبت‌شده یا سفارش پرداخت‌شده → حذف فیزیکی ممنوع */
        $refs = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('refund_requests') .
                    " WHERE context='event' AND reference_id=?", [$rid])
              + (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('refund_requests') .
                    " WHERE context='companion' AND reference_id IN (SELECT id FROM " . DB::t('event_companions') . ' WHERE reg_id=?)', [$rid]);
        if ($refs > 0) {
            flash('⛔ برای این ثبت‌نام سابقهٔ درخواست عودت وجه وجود دارد؛ ابتدا از صفحهٔ «عودت وجه» رسیدگی کنید، سپس می‌توانید وضعیت را «لغوشده» کنید.', 'err');
            redirect($back());
        }
        $paidOrder = $r['order_id'] && DB::val('SELECT status FROM ' . DB::t('orders') . ' WHERE id=?', [(int)$r['order_id']]) === 'paid';
        if ($paidOrder) {
            flash('⛔ این ثبت‌نام سفارشِ پرداخت‌شده دارد؛ حذف فیزیکی ممنوع است. مسیر درست: «عودت وجه» یا تغییر وضعیت به «لغوشده».', 'err');
            redirect($back());
        }
        try {
            DB::pdo()->beginTransaction();
            if ($r['order_id']) {   // سفارش معلق/ناموفقِ مرتبط، لغو می‌شود تا سفارش یتیم نماند
                DB::run('UPDATE ' . DB::t('orders') . " SET status='canceled' WHERE id=? AND status<>'paid'", [(int)$r['order_id']]);
            }
            DB::run('DELETE FROM ' . DB::t('event_companions') . ' WHERE reg_id=?', [$rid]);
            DB::run('DELETE FROM ' . DB::t('event_registrations') . ' WHERE id=?', [$rid]);
            DB::pdo()->commit();
            log_activity('admin_reg_del', 'event', (int)$r['event_id'], ['reg' => $rid, 'name' => trim($r['first_name'] . ' ' . $r['last_name'])]);
            flash('عضو حذف شد 🗑 (سفارش معلق مرتبط نیز لغو شد)');
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
            flash('خطا در حذف: ' . $e->getMessage(), 'err');
        }
        redirect($back());
    }

    /* ─────────── تست روانشناسی: افزودن دستی شرکت‌کننده ─────────── */
    if ($act === 'att_add') {
        $tid = (int)post('test_id');
        $t   = DB::one('SELECT * FROM ' . DB::t('tests') . ' WHERE id=?', [$tid]);
        $mob = en_num(trim((string)post('mobile')));
        $fn  = trim((string)post('first_name')); $ln = trim((string)post('last_name'));
        $mode = post('mode') === 'full' ? 'full' : 'free';
        if (!$t)             { flash('تست روانشناسی یافت نشد.', 'err'); redirect($back(['id' => $tid])); }
        if (!valid_mobile($mob)) { flash('شمارهٔ موبایل معتبر نیست.', 'err'); redirect($back()); }
        $uid = fr_companion_user_upsert($mob, $fn, $ln);
        if (DB::val('SELECT COUNT(*) FROM ' . DB::t('attempts') . " WHERE user_id=? AND test_id=? AND status='in_progress'", [$uid, $tid])) {
            flash('⚠️ این کاربر یک جلسهٔ ناتمام از همین تست روانشناسی دارد؛ ابتدا آن را حذف/ویرایش کنید.', 'err');
            redirect($back());
        }
        if ($mode === 'free' && DB::val('SELECT COUNT(*) FROM ' . DB::t('attempts') . " WHERE user_id=? AND test_id=? AND mode='free'", [$uid, $tid])) {
            flash('⚠️ نسخهٔ رایگان هر تست روانشناسی فقط یک‌بار به هر کاربر داده می‌شود.', 'err');
            redirect($back());
        }
        $oid = null;
        try {
            DB::pdo()->beginTransaction();
            if ($mode === 'full' && post('pay_record')) {
                $price = fr_price_deal((int)$t['price_full'], isset($t['price_sale']) ? (int)$t['price_sale'] : null)['final']; // ۲٫۸: قیمت نهایی
                $oid = DB::insert('orders', [
                    'user_id' => $uid, 'test_id' => $tid, 'kind' => 'test_full',
                    'amount' => $price, 'payable' => $price, 'status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'),
                ]);
                $manRef = 'MANUAL-' . date('Ymd') . '-' . $oid;
                DB::insert('payments', [
                    'order_id' => $oid, 'gateway' => 'manual', 'authority' => $manRef, 'ref_id' => $manRef,
                    'amount' => $price, 'status' => 'ok', 'verified_at' => date('Y-m-d H:i:s'),
                    'ip' => client_ip_bin(), 'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            }
            $aid = DB::insert('attempts', [
                'user_id' => $uid, 'test_id' => $tid, 'order_id' => $oid, 'mode' => $mode,
                'ip' => client_ip_bin(), 'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
            DB::pdo()->commit();
            log_activity('admin_att_add', 'test', $tid, ['attempt' => $aid, 'user' => $uid, 'mode' => $mode]);
            flash('دسترسی اعطا شد ✅ — کاربر از «پنل کاربری ← تست‌های روانشناسی» می‌تواند شروع کند.' .
                  ($mode === 'full' ? ($oid ? ' (نسخهٔ ویژه + رکورد پرداخت ' . toman($price) . ')' : ' (نسخهٔ ویژه، بدون رکورد مالی)') : ' (نسخهٔ رایگان)'));
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
            flash('خطا در اعطای دسترسی: ' . $e->getMessage(), 'err');
        }
        redirect($back());
    }

    /* ─────────── تست روانشناسی: ویرایش تلاش ─────────── */
    if ($act === 'att_save') {
        $aid = (int)post('attempt_id');
        $a   = DB::one('SELECT a.*, u.mobile AS umobile FROM ' . DB::t('attempts') . ' a
                        JOIN ' . DB::t('users') . ' u ON u.id=a.user_id WHERE a.id=?', [$aid]);
        if (!$a) { flash('تلاش یافت نشد.', 'err'); redirect($back()); }
        $fn  = trim((string)post('first_name')); $ln = trim((string)post('last_name'));
        $mob = en_num(trim((string)post('mobile')));
        $mode = post('mode') === 'full' ? 'full' : 'free';
        $st   = in_array(post('status'), ['in_progress','completed','analyzed'], true) ? (string)post('status') : $a['status'];
        if (!valid_mobile($mob)) { flash('شمارهٔ موبایل معتبر نیست.', 'err'); redirect($back(['edit_att' => $aid])); }
        $uid = (int)$a['user_id'];
        if ((string)$a['umobile'] !== $mob) {
            $uid = (int)fr_companion_user_upsert($mob, $fn, $ln);     // اتصال به حساب موبایل جدید
        }
        DB::update('users', ['first_name' => mb_substr($fn, 0, 100), 'last_name' => $ln !== '' ? mb_substr($ln, 0, 100) : null], 'id=?', [$uid]);
        $done = in_array($st, ['completed','analyzed'], true);
        DB::update('attempts', [
            'user_id' => $uid, 'mode' => $mode, 'status' => $st,
            'completed_at' => $done ? ($a['completed_at'] ?: date('Y-m-d H:i:s')) : null,
        ], 'id=?', [$aid]);
        log_activity('admin_att_save', 'test', (int)$a['test_id'], ['attempt' => $aid, 'mode' => $mode, 'status' => $st]);
        flash('تلاش به‌روزرسانی شد ✅');
        redirect($back());
    }

    /* ─────────── تست روانشناسی: ارسال پیامک یادآوری تلاش ناتمام ─────────── */
    if ($act === 'att_nudge') {
        $aid = (int)post('attempt_id');
        $a   = DB::one('SELECT a.*, u.first_name, u.last_name, u.mobile, t.title AS ttitle FROM ' . DB::t('attempts') . ' a
                        JOIN ' . DB::t('users') . ' u ON u.id=a.user_id
                        JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
                        WHERE a.id=?', [$aid]);
        if (!$a) { flash('تلاش یافت نشد.', 'err'); redirect($back()); }
        $link = fr_panel_url('take.php?attempt=' . $aid);
        $name = trim($a['first_name'] . ' ' . $a['last_name']) ?: 'کاربر گرامی';
        require_once FR_ROOT . '/core/Kavenegar.php';
        $kv = new Kavenegar();
        $ok = $kv->sendEvent('test_nudge', $a['mobile'], [
            'name'    => $name,
            'test'    => $a['ttitle'],
            'link'    => $link,
            '_tokens' => [$name, $a['ttitle'], $link],
        ]);
        if (!$ok) {
            $site = DB::setting('site_title', 'فریا روان');
            $msg = "سلام {$name} عزیز\nپاسخ‌گویی به آزمون «{$a['ttitle']}» شما نیمه‌کاره است. جهت تکمیل سوالات و دریافت کارنامه، روی لینک زیر بزنید:\n{$link}\n{$site}";
            $ok = $kv->send($a['mobile'], $msg, 'test_nudge');
        }
        if ($ok) {
            DB::update('attempts', ['nudge_sent' => 1], 'id=?', [$aid]);
            flash('پیامک یادآوری به همراه لینک ادامهٔ آزمون برای کاربر ارسال شد ✅', 'ok');
            log_activity('admin_sms_nudge', 'attempt', $aid);
        } else {
            flash('ارسال پیامک با خطا مواجه شد.', 'err');
        }
        redirect($back());
    }

    /* ─────────── تست روانشناسی: حذف تلاش (کاسکید پاسخ/نتیجه/گزارش) ─────────── */
    if ($act === 'att_del') {
        $aid = (int)post('attempt_id');
        $a   = DB::one('SELECT a.*, u.first_name, u.last_name, o.status AS os FROM ' . DB::t('attempts') . ' a
                        JOIN ' . DB::t('users') . ' u ON u.id=a.user_id
                        LEFT JOIN ' . DB::t('orders') . ' o ON o.id=a.order_id WHERE a.id=?', [$aid]);
        if (!$a) { flash('تلاش یافت نشد.', 'err'); redirect($back()); }
        if ($a['os'] === 'paid') {
            flash('⛔ این تلاش سفارشِ پرداخت‌شده دارد؛ حذف فیزیکی ممنوع است. ابتدا از «سفارش‌ها/عودت وجه» رسیدگی کنید.', 'err');
            redirect($back());
        }
        try {
            DB::pdo()->beginTransaction();
            /* بسیاری از نصب‌ها FK کاسکید دارند؛ برای اطمینانِ کامل صریح پاک می‌کنیم */
            DB::run('DELETE FROM ' . DB::t('reports') . ' WHERE attempt_id=?', [$aid]);
            DB::run('DELETE FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$aid]);
            DB::run('DELETE FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$aid]);
            DB::run('DELETE FROM ' . DB::t('attempts') . ' WHERE id=?', [$aid]);
            DB::pdo()->commit();
            log_activity('admin_att_del', 'test', (int)$a['test_id'], ['attempt' => $aid, 'name' => trim($a['first_name'] . ' ' . $a['last_name'])]);
            flash('تلاش و تمام پاسخ‌ها/نتایج/گزارش‌هایش حذف شد 🗑');
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
            flash('خطا در حذف: ' . $e->getMessage(), 'err');
        }
        redirect($back());
    }
}

/* ════════════════════════ داده‌های نمایش ════════════════════════ */
$fStatus = (string)($_GET['status'] ?? '');
$q       = trim((string)($_GET['q'] ?? ''));

$item = null; $rows = []; $sum = [];
if ($ctx === 'test') {
    $item = $id ? DB::one('SELECT * FROM ' . DB::t('tests') . ' WHERE id=?', [$id]) : null;
} else {
    $item = $id ? DB::one('SELECT * FROM ' . DB::t('events') . ' WHERE id=? AND kind=?', [$id, $ctx]) : null;
}

if ($ctx === 'test') {
    if ($item) {
        $w = 'a.test_id=?'; $p = [(int)$item['id']];
        if (in_array($fStatus, ['in_progress','completed','analyzed'], true)) { $w .= ' AND a.status=?'; $p[] = $fStatus; }
        if (in_array($fStatus, ['free','full'], true))                        { $w .= ' AND a.mode=?';   $p[] = $fStatus; }
        if ($q !== '') { $w .= ' AND (u.mobile LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
                         $like = '%' . $q . '%'; $p = array_merge($p, [$like, $like, $like]); }
        $rows = DB::all("SELECT a.*, u.first_name, u.last_name, u.mobile,
                                o.payable AS opay, o.status AS ostatus, o.id AS oid
                         FROM " . DB::t('attempts') . " a
                         JOIN " . DB::t('users') . " u ON u.id=a.user_id
                         LEFT JOIN " . DB::t('orders') . " o ON o.id=a.order_id
                         WHERE $w ORDER BY a.id DESC LIMIT 300", $p);
        $sum = [
            'total'  => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('attempts') . ' WHERE test_id=?', [(int)$item['id']]),
            'open'   => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('attempts') . " WHERE test_id=? AND status='in_progress'", [(int)$item['id']]),
            'done'   => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('attempts') . " WHERE test_id=? AND status<>'in_progress'", [(int)$item['id']]),
            'full'   => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('attempts') . " WHERE test_id=? AND mode='full'", [(int)$item['id']]),
            'rev'    => (int)DB::val('SELECT COALESCE(SUM(payable),0) FROM ' . DB::t('orders') . " WHERE test_id=? AND status='paid'", [(int)$item['id']]),
            'ref'    => (int)DB::val('SELECT COALESCE(SUM(payable),0) FROM ' . DB::t('orders') . " WHERE test_id=? AND status='refunded'", [(int)$item['id']]),
        ];
    }
    $picker = DB::all('SELECT id, title, status FROM ' . DB::t('tests') . " WHERE status<>'trash' ORDER BY sort_order");
} else {
    if ($item) {
        $w = 'r.event_id=?'; $p = [(int)$item['id']];
        if (in_array($fStatus, ['pending','deposit','paid','canceled'], true)) { $w .= ' AND r.status=?'; $p[] = $fStatus; }
        if ($q !== '') { $w .= ' AND (u.mobile LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
                         $like = '%' . $q . '%'; $p = array_merge($p, [$like, $like, $like]); }
        $rows = DB::all("SELECT r.*, u.first_name, u.last_name, u.mobile,
                                o.payable AS opay, o.status AS ostatus, o.id AS oid,
                                (SELECT COUNT(*) FROM " . DB::t('event_companions') . " c WHERE c.reg_id=r.id AND c.status='active') AS comps_active,
                                (SELECT GROUP_CONCAT(CONCAT(c.first_name,' ',IFNULL(c.last_name,'')) SEPARATOR '، ')
                                   FROM " . DB::t('event_companions') . " c WHERE c.reg_id=r.id AND c.status='active') AS comps_names
                         FROM " . DB::t('event_registrations') . " r
                         JOIN " . DB::t('users') . " u ON u.id=r.user_id
                         LEFT JOIN " . DB::t('orders') . " o ON o.id=r.order_id
                         WHERE $w ORDER BY r.id DESC LIMIT 300", $p);
        $sum = [
            'total'    => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') . ' WHERE event_id=?', [(int)$item['id']]),
            'paid'     => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') . " WHERE event_id=? AND status='paid'", [(int)$item['id']]),
            'pending'  => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') . " WHERE event_id=? AND status='pending'", [(int)$item['id']]),
            'deposit'  => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') . " WHERE event_id=? AND status='deposit'", [(int)$item['id']]),
            'dep_remain' => (int)DB::val('SELECT COALESCE(SUM(GREATEST(0, COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.discount_meta,\'$.payable\')) AS UNSIGNED), r.amount_base - r.discount_amount) - r.paid_total)),0) FROM ' . DB::t('event_registrations') . " r WHERE r.event_id=? AND r.status='deposit'", [(int)$item['id']]),
            'canceled' => (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') . " WHERE event_id=? AND status='canceled'", [(int)$item['id']]),
            'seats'    => ev_paid_seats((int)$item['id']),
            'rev'      => (int)DB::val('SELECT COALESCE(SUM(payable),0) FROM ' . DB::t('orders') . " WHERE event_id=? AND status='paid'", [(int)$item['id']]),
            'ref'      => (int)DB::val('SELECT COALESCE(SUM(payable),0) FROM ' . DB::t('orders') . " WHERE event_id=? AND status='refunded'", [(int)$item['id']]),
        ];
    }
    $picker = DB::all('SELECT id, title, status FROM ' . DB::t('events') . ' WHERE kind=? ORDER BY id DESC', [$ctx]);
}

/* ── ۲٫۳: فهرست یکپارچهٔ همهٔ موارد برای سلکتور گروه‌بندی‌شده + نمای کلی ── */
$pickerAll = [
    'test'     => DB::all('SELECT id, title, status FROM ' . DB::t('tests') . " WHERE status<>'trash' ORDER BY sort_order"),
    'workshop' => DB::all("SELECT id, title, status FROM " . DB::t('events') . " WHERE kind='workshop' ORDER BY id DESC"),
    'course'   => DB::all("SELECT id, title, status FROM " . DB::t('events') . " WHERE kind='course' ORDER BY id DESC"),
];
/* نمای کلی: تعداد ثبت‌نام/تلاش هر مورد (برای وقتی هنوز چیزی انتخاب نشده) */
$overview = [];
if (!$item) {
    foreach (DB::all("SELECT t.id, t.title,
                            (SELECT COUNT(*) FROM " . DB::t('attempts') . " a WHERE a.test_id=t.id AND a.status<>'in_progress') AS cnt,
                            (SELECT COUNT(*) FROM " . DB::t('attempts') . " a WHERE a.test_id=t.id AND a.mode='full' AND a.status<>'in_progress') AS paid,
                            (SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " o WHERE o.test_id=t.id AND o.status='paid') AS rev
                     FROM " . DB::t('tests') . " t WHERE t.status<>'trash' ORDER BY cnt DESC LIMIT 50") as $r) {
        $r['_k'] = 'test'; $overview[] = $r;
    }
    foreach (DB::all("SELECT e.id, e.kind, e.title,
                            (SELECT COUNT(*) FROM " . DB::t('event_registrations') . " r WHERE r.event_id=e.id) AS cnt,
                            (SELECT COUNT(*) FROM " . DB::t('event_registrations') . " r WHERE r.event_id=e.id AND r.status='paid') AS paid,
                            (SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " o WHERE o.event_id=e.id AND o.status='paid') AS rev
                     FROM " . DB::t('events') . " e ORDER BY e.id DESC LIMIT 100") as $r) {
        $r['_k'] = $r['kind']; $overview[] = $r;
    }
}

$editReg = (int)($_GET['edit_reg'] ?? 0);
$editAtt = (int)($_GET['edit_att'] ?? 0);
$eReg = $editReg && $ctx !== 'test'
    ? DB::one('SELECT r.*, u.first_name, u.last_name, u.mobile FROM ' . DB::t('event_registrations') . ' r
               JOIN ' . DB::t('users') . " u ON u.id=r.user_id WHERE r.id=? AND r.event_id=?", [$editReg, $id])
    : null;
$eAtt = $editAtt && $ctx === 'test'
    ? DB::one('SELECT a.*, u.first_name, u.last_name, u.mobile FROM ' . DB::t('attempts') . ' a
               JOIN ' . DB::t('users') . " u ON u.id=a.user_id WHERE a.id=? AND a.test_id=?", [$editAtt, $id])
    : null;

admin_layout($adm, 'registrants', 'مدیریت ثبت‌نام‌شدگان');
echo flash();
?>
<div class="fr-card">
  <h3>🗂 مدیریت ثبت‌نام‌شدگان <small style="color:var(--muted);font-weight:400">(دیدن لیست اعضا + افزودن/ویرایش/حذف دستی — ۱٫۹)</small></h3>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <?php foreach ($CTXS as $k => $lbl): ?>
    <a class="fr-chip <?= $ctx === $k ? 'purple' : 'info' ?>" href="?ctx=<?= $k ?>" style="text-decoration:none"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" action="registrants.php" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div class="fr-field" style="flex:1;min-width:300px;margin:0">
      <label>انتخاب تست روانشناسی، کارگاه یا دوره <small style="color:var(--muted)">(گروه‌بندی‌شده — ۲٫۳)</small></label>
      <select class="fr-select" name="pick" onchange="this.form.submit()">
        <option value="">— انتخاب کنید —</option>
        <?php foreach ($pickerAll as $k => $list): if (!$list) continue; ?>
        <optgroup label="<?= e($CTXS[$k]) ?>">
          <?php foreach ($list as $p): ?>
          <option value="<?= e($k) ?>|<?= (int)$p['id'] ?>" <?= ($ctx === $k && (int)$p['id'] === $id) ? 'selected' : '' ?>>
            <?= e($p['title']) ?><?= $p['status'] !== 'active' ? ' (' . e($p['status']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </optgroup>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<?php if (!$item): ?>
<!-- ۲٫۳: نمای کلی همهٔ موارد در یک نگاه — دیگر اطلاعات «ناپیدا» نیست -->
<div class="fr-card">
  <h3>🗂 نمای کلی ثبت‌نام‌ها در یک نگاه</h3>
  <?php if (!$overview): ?>
  <p style="font-size:12.5px;color:var(--muted)">هنوز تست روانشناسی، کارگاه یا دوره‌ای ساخته نشده است.</p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="fr-table" style="font-size:13px">
    <tr><th>بخش</th><th>عنوان</th><th>شرکت‌کننده/ثبت‌نام</th><th>نهایی (paid)</th><th>درآمد نهایی</th><th></th></tr>
    <?php foreach ($overview as $ov): ?>
    <tr>
      <td><span class="fr-chip <?= $ov['_k'] === 'test' ? 'info' : 'ok' ?>" style="font-size:11px"><?= e($CTXS[$ov['_k']] ?? $ov['_k']) ?></span></td>
      <td><b><?= e($ov['title']) ?></b></td>
      <td><?= fa_num((int)$ov['cnt']) ?></td>
      <td><?= isset($ov['paid']) ? fa_num((int)$ov['paid']) : '—' ?></td>
      <td><?= (int)$ov['rev'] ? toman((int)$ov['rev']) : '—' ?></td>
      <td><a class="fr-btn sm ghost" href="?ctx=<?= e($ov['_k']) ?>&id=<?= (int)$ov['id'] ?>">مشاهده اعضا ⬅</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php else: ?>

<?php if ($ctx !== 'test'): ?>
<!-- ════════════ خلاصهٔ رویداد ════════════ -->
<div class="fr-grid cols-4" style="margin-bottom:10px">
  <?php stat_card('👥', 'ثبت‌نام نهایی (paid)', fa_num($sum['paid']), '#ecfbf4'); ?>
  <?php stat_card('💳', 'پیش‌پرداخت (منتظر تصفیه)', fa_num($sum['deposit']) . ($sum['dep_remain'] > 0 ? ' — ماندهٔ ' . toman($sum['dep_remain']) : ''), '#e8f3ff'); ?>
  <?php stat_card('⏳', 'در انتظار پرداخت', fa_num($sum['pending']), '#fff6e6'); ?>
  <?php stat_card('🚫', 'لغوشده', fa_num($sum['canceled']), '#fdeded'); ?>
</div>
<div class="fr-grid cols-4" style="margin-bottom:10px">
  <?php stat_card('🪑', 'صندلی‌های اشغال', fa_num($sum['seats']) . ($item['capacity'] ? ' / ' . fa_num((int)$item['capacity']) : ''), '#eaf4ff'); ?>
  <?php stat_card('💰', 'درآمد خالص این رویداد', toman($sum['rev']), '#fff6e6'); ?>
  <?php stat_card('↩️', 'مبالغ برگشتی این رویداد', toman($sum['ref']), '#fdeded'); ?>
</div>

<!-- ════════════ افزودن دستی عضو ════════════ -->
<div class="fr-card">
  <h3>➕ افزودن دستی عضو به «<?= e($item['title']) ?>»</h3>
  <form method="post" class="fr-grid cols-2">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="reg_add"><input type="hidden" name="event_id" value="<?= (int)$item['id'] ?>">
    <div>
      <div class="fr-field"><label>موبایل عضو *</label><input class="fr-input" name="mobile" inputmode="numeric" maxlength="11" placeholder="09xxxxxxxxx" required></div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>نام</label><input class="fr-input" name="first_name" placeholder="نام"></div>
        <div class="fr-field"><label>نام خانوادگی</label><input class="fr-input" name="last_name" placeholder="نام خانوادگی"></div>
      </div>
      <div class="fr-field"><label>نوع ثبت</label>
        <select class="fr-select" name="pay_type">
          <option value="free">کیفی/دعوتی — بدون رکورد مالی (کل مبلغ بخشیده می‌شود)</option>
          <option value="paid">پرداخت حضوری/کارت‌به‌کارت — سفارش paid + درآمد ثبت شود</option>
        </select>
        <small class="hint">گزینهٔ دوم، سفارش «دستی (manual)» می‌سازد تا دقیقاً در گزارش‌های درآمد داشبورد لحاظ شود.</small>
      </div>
      <label class="tog"><input type="checkbox" name="send_sms" value="1"> پیامک «ثبت‌نام موفق» به عضو ارسال شود</label>
    </div>
    <div>
      <div class="fr-field"><label>همراهان (اختیاری) — هر خط یک نفر: نام و نام‌خانوادگی + موبایل</label>
        <textarea class="fr-textarea" name="companions_text" rows="5" placeholder="سارا محمدی 09123334455&#10;علی رضایی 09221112233" <?= (int)$item['companion_enabled'] ? '' : 'disabled' ?>></textarea>
        <small class="hint">
          <?php if ((int)$item['companion_enabled']): ?>
            همراه فعال است<?= $item['companion_max'] !== null ? ' — سقف ' . fa_num((int)$item['companion_max']) . ' نفر' : '' ?>؛
            قیمت هر همراه: <?= toman(ev_companion_unit($item)) ?>.
          <?php else: ?>
            ثبت‌نام همراه برای این رویداد غیرفعال است.
          <?php endif; ?>
          شهریهٔ نفر اصلی: <?= toman((int)$item['price']) ?>
        </small>
      </div>
      <button class="fr-btn">ثبت عضو ✅</button>
    </div>
  </form>
</div>

<!-- ════════════ پنل ویرایش اختصاصی عضو (در صورت انتخاب) ════════════ -->
<?php if ($eReg): ?>
<div class="fr-card" style="margin:0;border:2px solid var(--c-primary)">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
    <h3 style="margin:0">✏️ ویرایش عضو #<?= fa_num((int)$eReg['id']) ?> — <?= e(trim($eReg['first_name'] . ' ' . $eReg['last_name']) ?: 'بدون نام') ?></h3>
    <a class="fr-btn ghost sm" href="<?= e($back(['edit_reg' => null])) ?>">← بازگشت به لیست اعضا</a>
  </div>
  <form method="post" class="fr-grid cols-2">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="reg_save"><input type="hidden" name="reg_id" value="<?= (int)$eReg['id'] ?>">
    <div>
      <div class="fr-field"><label>موبایل *</label><input class="fr-input" name="mobile" maxlength="11" value="<?= e($eReg['mobile']) ?>" required>
        <small class="hint">تغییر موبایل، ثبت‌نام را به حسابِ موبایل جدید منتقل می‌کند.</small></div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>نام</label><input class="fr-input" name="first_name" value="<?= e($eReg['first_name'] ?? '') ?>"></div>
        <div class="fr-field"><label>نام خانوادگی</label><input class="fr-input" name="last_name" value="<?= e($eReg['last_name'] ?? '') ?>"></div>
      </div>
    </div>
    <div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>وضعیت ثبت‌نام</label>
          <select class="fr-select" name="status">
            <option value="pending" <?= $eReg['status'] === 'pending' ? 'selected' : '' ?>>⏳ در انتظار</option>
            <option value="deposit" <?= $eReg['status'] === 'deposit' ? 'selected' : '' ?>>💳 پیش‌پرداخت (منتظر تصفیه)</option>
            <option value="paid" <?= $eReg['status'] === 'paid' ? 'selected' : '' ?>>✅ نهایی (paid)</option>
            <option value="canceled" <?= $eReg['status'] === 'canceled' ? 'selected' : '' ?>>🚫 لغوشده</option>
          </select></div>
        <div class="fr-field"><label>تعداد همراه</label>
          <input class="fr-input" name="companion_count" inputmode="numeric" value="<?= fa_num($eReg['companion_count']) ?>">
          <small class="hint">مبلغ سفارش‌های قبلی به‌صورت اسنپ‌شات حفظ می‌شود و تغییر نمی‌کند.</small></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap">
        <button class="fr-btn">ذخیره تغییرات ✅</button>
        <a class="fr-btn ghost" href="<?= e($back(['edit_reg' => null])) ?>">انصراف و بازگشت</a>
      </div>
    </div>
  </form>
</div>
<?php
// در حالت ویرایش عضو رویداد، فقط فرم ویرایش نمایش داده می‌شود
admin_layout_end();
exit;
endif;
?>

<!-- ════════════ لیست اعضای رویداد ════════════ -->
<div class="fr-card">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
    <h3 style="margin:0">لیست اعضای «<?= e($item['title']) ?>»</h3>
    <form method="get" action="registrants.php" style="display:flex;gap:6px;flex-wrap:wrap;margin-right:auto">
      <input type="hidden" name="ctx" value="<?= e($ctx) ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
      <select class="fr-select" name="status" style="width:auto">
        <option value="">همهٔ وضعیت‌ها</option>
        <option value="paid" <?= $fStatus === 'paid' ? 'selected' : '' ?>>نهایی</option>
        <option value="deposit" <?= $fStatus === 'deposit' ? 'selected' : '' ?>>💳 پیش‌پرداخت (منتظر تصفیه)</option>
        <option value="pending" <?= $fStatus === 'pending' ? 'selected' : '' ?>>در انتظار</option>
        <option value="canceled" <?= $fStatus === 'canceled' ? 'selected' : '' ?>>لغوشده</option>
      </select>
      <input class="fr-input" name="q" style="width:170px" placeholder="جست‌وجوی نام/موبایل" value="<?= e($q) ?>">
      <button class="fr-btn sm ghost">🔎</button>
    </form>
  </div>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>#</th><th>عضو</th><th>موبایل</th><th>همراهان</th><th>وضعیت</th><th>مبلغ/سفارش</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px 0">عضوی یافت نشد.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= fa_num((int)$r['id']) ?></td>
      <td><b><?= e(trim($r['first_name'] . ' ' . $r['last_name']) ?: '—') ?></b></td>
      <td dir="ltr"><?= e(fa_num($r['mobile'])) ?></td>
      <td>
        <?= (int)$r['comps_active'] > 0 ? '<span class="fr-chip info" title="' . e((string)$r['comps_names']) . '">+' . fa_num($r['comps_active']) . '</span>' : '—' ?>
      </td>
      <td><span class="fr-chip <?= ['paid'=>'ok','deposit'=>'info','pending'=>'warn','canceled'=>'err'][$r['status']] ?? 'info' ?>">
        <?= ['paid' => 'نهایی', 'deposit' => '💳 پیش‌پرداخت', 'pending' => 'در انتظار', 'canceled' => 'لغوشده'][$r['status']] ?? e((string)$r['status']) ?></span>
        <?php if ($r['status'] === 'deposit'): ?>
          <br><small style="color:#8a5a2e;font-size:10.5px">پرداخت‌شده: <?= toman((int)($r['paid_total'] ?? 0)) ?><?php $mRem = fr_reg_remaining($r); if ($mRem > 0): ?> · مانده: <b style="color:#b3261e"><?= toman($mRem) ?></b><?php endif; ?></small>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($r['oid']): ?>
          <?= toman((int)$r['opay']) ?><br>
          <small style="color:var(--muted)">سفارش #<?= fa_num((int)$r['oid']) ?>
            (<?= ['paid' => 'موفق', 'pending' => 'معلق', 'failed' => 'ناموفق', 'canceled' => 'لغو', 'refunded' => 'برگشت'][$r['ostatus']] ?? e((string)$r['ostatus']) ?>)</small>
        <?php elseif ($r['status'] === 'paid'): ?>
          <span class="fr-chip purple">دستی/رایگان</span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td><?= fa_num(jdate('j F Y', strtotime($r['created_at']))) ?></td>
      <td class="actions">
        <a class="fr-btn sm ghost" href="<?= e($back(['edit_reg' => (int)$r['id']])) ?>">ویرایش</a>
        <form method="post" style="display:inline" onsubmit="return confirm('حذف این عضو؟\n\nفقط اگر سفارشِ پرداخت‌شده یا سابقهٔ عودت وجه نداشته باشد انجام می‌شود؛ همراهان‌اش هم حذف می‌شوند.')">
          <?= csrf_field() ?><input type="hidden" name="act" value="reg_del"><input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
          <button class="fr-btn sm danger">حذف</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 300): ?><small class="hint">نمایش ۳۰۰ رکورد آخر — برای دیدن دقیق‌تر از جست‌وجو و فیلتر استفاده کنید.</small><?php endif; ?>
</div>

<?php else: /* ─────────── کانتکست تست‌های روانشناسی ─────────── */ ?>

<div class="fr-grid cols-4" style="margin-bottom:10px">
  <?php stat_card('🧠', 'کل تلاش‌ها', fa_num($sum['total'])); ?>
  <?php stat_card('⏳', 'ناتمام', fa_num($sum['open']), '#fff6e6'); ?>
  <?php stat_card('✅', 'تکمیل‌شده', fa_num($sum['done']), '#ecfbf4'); ?>
  <?php stat_card('⭐', 'دارای نسخهٔ ویژه', fa_num($sum['full']), '#f6f1ff'); ?>
</div>
<div class="fr-grid cols-2" style="margin-bottom:10px">
  <?php stat_card('💰', 'درآمد خالص این تست روانشناسی', toman($sum['rev']), '#fff6e6'); ?>
  <?php stat_card('↩️', 'مبالغ برگشتی این تست روانشناسی', toman($sum['ref']), '#fdeded'); ?>
</div>

<div class="fr-card">
  <h3>➕ اعطای دسترسی دستی به «<?= e($item['title']) ?>»</h3>
  <form method="post" class="fr-grid cols-2">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="att_add"><input type="hidden" name="test_id" value="<?= (int)$item['id'] ?>">
    <div>
      <div class="fr-field"><label>موبایل کاربر *</label><input class="fr-input" name="mobile" inputmode="numeric" maxlength="11" placeholder="09xxxxxxxxx" required></div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>نام</label><input class="fr-input" name="first_name"></div>
        <div class="fr-field"><label>نام خانوادگی</label><input class="fr-input" name="last_name"></div>
      </div>
    </div>
    <div>
      <div class="fr-field"><label>نوع دسترسی</label>
        <select class="fr-select" name="mode">
          <option value="free">نسخهٔ رایگان (تحلیل خلاصه — هر کاربر فقط یک‌بار)</option>
          <option value="full">نسخهٔ ویژه (گزارش کامل)</option>
        </select></div>
      <label class="tog"><input type="checkbox" name="pay_record" value="1">
        رکورد پرداخت هم ثبت شود (سفارش دستی «manual» به مبلغ <?= toman(fr_price_deal((int)$item['price_full'], isset($item['price_sale']) ? (int)$item['price_sale'] : null)['final']) ?>) — فقط برای نسخهٔ ویژه</label>
      <small class="hint">با ثبت رکورد، مبلغ دقیقاً در «خالص درآمد» داشبورد و گزارش‌ها محاسبه می‌شود؛ بدون تیک = اهدا/کیفی (بدون اثر مالی). پس از ثبت، کاربر از پنل خودش تست روانشناسی را شروع می‌کند.</small><br><br>
      <button class="fr-btn">اعطای دسترسی ✅</button>
    </div>
  </form>
</div>

<?php if ($eAtt): ?>
<div class="fr-card" style="border:2px solid var(--c-primary)">
  <h3>✏️ ویرایش تلاش #<?= fa_num((int)$eAtt['id']) ?> — <?= e(trim($eAtt['first_name'] . ' ' . $eAtt['last_name']) ?: 'بدون نام') ?></h3>
  <form method="post" class="fr-grid cols-2">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="att_save"><input type="hidden" name="attempt_id" value="<?= (int)$eAtt['id'] ?>">
    <div>
      <div class="fr-field"><label>موبایل *</label><input class="fr-input" name="mobile" maxlength="11" value="<?= e($eAtt['mobile']) ?>" required></div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>نام</label><input class="fr-input" name="first_name" value="<?= e($eAtt['first_name'] ?? '') ?>"></div>
        <div class="fr-field"><label>نام خانوادگی</label><input class="fr-input" name="last_name" value="<?= e($eAtt['last_name'] ?? '') ?>"></div>
      </div>
    </div>
    <div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>نوع (mode)</label>
          <select class="fr-select" name="mode">
            <option value="free" <?= $eAtt['mode'] === 'free' ? 'selected' : '' ?>>رایگان</option>
            <option value="full" <?= $eAtt['mode'] === 'full' ? 'selected' : '' ?>>ویژه</option>
          </select></div>
        <div class="fr-field"><label>وضعیت</label>
          <select class="fr-select" name="status">
            <option value="in_progress" <?= $eAtt['status'] === 'in_progress' ? 'selected' : '' ?>>⏳ ناتمام</option>
            <option value="completed" <?= $eAtt['status'] === 'completed' ? 'selected' : '' ?>>✅ تکمیل‌شده</option>
            <option value="analyzed" <?= $eAtt['status'] === 'analyzed' ? 'selected' : '' ?>>📊 تحلیل‌شده</option>
          </select></div>
      </div>
      <button class="fr-btn">ذخیره تغییرات ✅</button>
      <a class="fr-btn ghost" href="<?= e($back(['edit_att' => null])) ?>">انصراف</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="fr-card">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
    <h3 style="margin:0">شرکت‌کنندگان «<?= e($item['title']) ?>»</h3>
    <form method="get" action="registrants.php" style="display:flex;gap:6px;flex-wrap:wrap;margin-right:auto">
      <input type="hidden" name="ctx" value="test"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
      <select class="fr-select" name="status" style="width:auto">
        <option value="">همه</option>
        <option value="in_progress" <?= $fStatus === 'in_progress' ? 'selected' : '' ?>>ناتمام</option>
        <option value="completed" <?= $fStatus === 'completed' ? 'selected' : '' ?>>تکمیل‌شده</option>
        <option value="analyzed" <?= $fStatus === 'analyzed' ? 'selected' : '' ?>>تحلیل‌شده</option>
        <option value="free" <?= $fStatus === 'free' ? 'selected' : '' ?>>نسخهٔ رایگان</option>
        <option value="full" <?= $fStatus === 'full' ? 'selected' : '' ?>>نسخهٔ ویژه</option>
      </select>
      <input class="fr-input" name="q" style="width:170px" placeholder="جست‌وجوی نام/موبایل" value="<?= e($q) ?>">
      <button class="fr-btn sm ghost">🔎</button>
    </form>
  </div>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>#</th><th>کاربر</th><th>موبایل</th><th>نسخه</th><th>وضعیت</th><th>پیشرفت</th><th>سفارش</th><th>شروع</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px 0">شرکت‌کننده‌ای یافت نشد.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= fa_num((int)$r['id']) ?></td>
      <td><b><?= e(trim($r['first_name'] . ' ' . $r['last_name']) ?: '—') ?></b></td>
      <td dir="ltr"><?= e(fa_num($r['mobile'])) ?></td>
      <td><span class="fr-chip <?= $r['mode'] === 'full' ? 'purple' : 'info' ?>"><?= $r['mode'] === 'full' ? 'ویژه' : 'رایگان' ?></span></td>
      <td><span class="fr-chip <?= $r['status'] === 'in_progress' ? 'warn' : 'ok' ?>">
        <?= ['in_progress' => 'ناتمام', 'completed' => 'تکمیل‌شده', 'analyzed' => 'تحلیل‌شده'][$r['status']] ?></span></td>
      <td><?= fa_num((int)$r['progress']) ?></td>
      <td>
        <?php if ($r['oid']): ?>
          <?= toman((int)$r['opay']) ?><br><small style="color:var(--muted)">#<?= fa_num((int)$r['oid']) ?>
          (<?= ['paid' => 'موفق', 'pending' => 'معلق', 'failed' => 'ناموفق', 'canceled' => 'لغو', 'refunded' => 'برگشت'][$r['ostatus']] ?? e((string)$r['ostatus']) ?>)</small>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td><?= fa_num(jdate('j F Y', strtotime($r['started_at']))) ?></td>
      <td class="actions" style="white-space:nowrap">
        <?php if ($r['status'] !== 'in_progress'): ?>
        <a class="fr-btn sm" style="background:#6d28d9;color:#fff" href="attempts.php?report=<?= (int)$r['id'] ?>" target="_blank" title="مشاهده کارنامه و تحلیل کامل روان‌سنجی">👁 کارنامه و تحلیل</a>
        <?php endif; ?>
        <a class="fr-btn sm ghost" href="attempt-answers.php?attempt=<?= (int)$r['id'] ?>" title="مشاهده و ویرایش سؤالات و پاسخ‌های کاربر">📝 پاسخ‌ها</a>
        <a class="fr-btn sm ghost" href="<?= e($back(['edit_att' => (int)$r['id']])) ?>">ویرایش</a>
        <?php if ($r['status'] === 'in_progress'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('پیامک یادآوری به همراه لینک ادامهٔ آزمون ارسال شود؟')">
          <?= csrf_field() ?><input type="hidden" name="act" value="att_nudge"><input type="hidden" name="attempt_id" value="<?= (int)$r['id'] ?>">
          <button class="fr-btn sm" style="background:#f59e0b;color:#fff" title="ارسال پیامک یادآوری با لینک تست">📲 یادآوری</button>
        </form>
        <?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('حذف این تلاش؟\nتمام پاسخ‌ها، نتایج و گزارش‌هایش هم حذف می‌شود.\nتلاشِ دارای سفارشِ پرداخت‌شده قابل حذف نیست.')">
          <?= csrf_field() ?><input type="hidden" name="act" value="att_del"><input type="hidden" name="attempt_id" value="<?= (int)$r['id'] ?>">
          <button class="fr-btn sm danger">حذف</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 300): ?><small class="hint">نمایش ۳۰۰ رکورد آخر — برای دیدن دقیق‌تر از جست‌وجو و فیلتر استفاده کنید.</small><?php endif; ?>
</div>

<?php endif; /* ctx */ ?>
<?php endif; /* item */ ?>
<?php admin_layout_end(); ?>
