<?php
/**
 * ============================================================================
 *  دفترچهٔ مخاطبین اطلاع‌رسانی (نسخهٔ ۲٫۷) — مدیریت کاملاً حرفه‌ای
 *  ─ مخاطب: نام، موبایل، تاریخ تولد (شمسی در فرم / میلادی در DB)، یادداشت،
 *    گروه‌بندی واقعی چندبه‌چند (contact_groups) + برچسب قدیمی segment (سازگاری)
 *  ─ کانال‌های پیام‌رسان: ثبت دستی username یا chat_id عددی برای تلگرام/بله،
 *    بررسی خودکار به‌محض ثبت (تطبیق با کاربران سایت + getChat)، کد دعوت
 *    دیپ‌لینک «start خودکار» و پیامک دعوت؛ به‌محض استارت مخاطب، همان‌جا متصل می‌شود
 *  ─ همگام‌سازی خودکار: هر کاربری که در سایت ثبت‌نام می‌کند به‌صورت خودکار
 *    در مخاطبین (source='register') ثبت/به‌روزرسانی می‌شود
 * ─ حقیقت فنی (صادقانه در UI هم آمده): API ربات تلگرام/بله هیچ راهی برای
 *    «یافتن حساب از روی شمارهٔ موبایل» یا «پیام پیش از start» ندارد؛ آنچه
 *    خودکار می‌کنیم راه‌حل استاندارد صنعت است: دیپ‌لینک دعوت + اشتراک شماره +
 *    getChat با username ذخیره‌شده + ورود دستی chat_id.
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/xlsx.php';

/** نرمال‌سازی موبایل ایرانی؛ نامعتبر = '' */
function fr_contact_mobile(string $m): string
{
    $m = en_num(trim($m));
    $m = preg_replace('/[^\d]/', '', $m);
    if (str_starts_with($m, '0098')) $m = '0' . substr($m, 4);
    elseif (str_starts_with($m, '98') && strlen($m) === 12) $m = '0' . substr($m, 2);
    if (strlen($m) === 10 && str_starts_with($m, '9')) $m = '0' . $m;
    return preg_match('/^09\d{9}$/', $m) ? $m : '';
}

/* ══════════════════════════ گروه‌های مخاطبین ══════════════════════════ */

/** همهٔ گروه‌ها + شمارش اعضا */
function fr_contact_groups(): array
{
    try {
        return DB::all("SELECT g.*, (SELECT COUNT(*) FROM " . DB::t('contact_group_members') . " m WHERE m.group_id=g.id) AS members
                        FROM " . DB::t('contact_groups') . " g ORDER BY g.name");
    } catch (Throwable $e) { return []; }
}

/** ساخت گروه؛ خروجی id (۰ در صورت نام تکراری/نامعتبر) */
function fr_contact_group_add(string $name, string $color = '#1e3a6e', string $note = ''): int
{
    $name = mb_substr(trim($name), 0, 80);
    if ($name === '') return 0;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#1e3a6e';
    try {
        return DB::insert('contact_groups', ['name' => $name, 'color' => $color, 'note' => mb_substr(trim($note), 0, 190)]);
    } catch (Throwable $e) { return 0; }   // نام تکراری → 0
}

/** ویرایش گروه */
function fr_contact_group_update(int $gid, string $name, string $color, string $note): bool
{
    $name = mb_substr(trim($name), 0, 80);
    if ($gid <= 0 || $name === '') return false;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#1e3a6e';
    try {
        DB::update('contact_groups', ['name' => $name, 'color' => $color, 'note' => mb_substr(trim($note), 0, 190)], 'id=?', [$gid]);
        return true;
    } catch (Throwable $e) { return false; }
}

/** حذف گروه (عضویت‌ها با FK حذف می‌شوند؛ خود مخاطبین می‌مانند) */
function fr_contact_group_delete(int $gid): bool
{
    if ($gid <= 0) return false;
    DB::run("DELETE FROM " . DB::t('contact_group_members') . " WHERE group_id=?", [$gid]);
    DB::run("DELETE FROM " . DB::t('contact_groups') . " WHERE id=?", [$gid]);
    return true;
}

/** گروه‌های یک مخاطب: آرایه‌ای از ردیف‌ها */
function fr_contact_group_of(int $contactId): array
{
    try {
        return DB::all("SELECT g.id,g.name,g.color FROM " . DB::t('contact_groups') . " g
                        JOIN " . DB::t('contact_group_members') . " m ON m.group_id=g.id
                        WHERE m.contact_id=? ORDER BY g.name", [$contactId]);
    } catch (Throwable $e) { return []; }
}

/** جایگذاری کل مجموعهٔ گروه‌های یک مخاطب (از فرم ویرایش) */
function fr_contact_set_groups(int $contactId, array $groupIds): void
{
    $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
    try {
        DB::run("DELETE FROM " . DB::t('contact_group_members') . " WHERE contact_id=?", [$contactId]);
        foreach ($groupIds as $gid) {
            if ($gid <= 0) continue;
            DB::run("INSERT IGNORE INTO " . DB::t('contact_group_members') . " (contact_id,group_id) VALUES (?,?)", [$contactId, $gid]);
        }
    } catch (Throwable $e) { /* جداول تازه‌اند */ }
}

/** افزودن گروهی مخاطبین به یک گروه — تعداد واقعی افزوده‌شده */
function fr_contacts_group_assign(array $contactIds, int $gid): int
{
    $n = 0;
    foreach (array_map('intval', $contactIds) as $cid) {
        if ($cid <= 0 || $gid <= 0) continue;
        try {
            DB::run("INSERT IGNORE INTO " . DB::t('contact_group_members') . " (contact_id,group_id) VALUES (?,?)", [$cid, $gid]);
            $n += (int)DB::val("SELECT ROW_COUNT()");
        } catch (Throwable $e) { }
    }
    return $n;
}

/** حذف گروهی مخاطبین از یک گروه */
function fr_contacts_group_detach(array $contactIds, int $gid): int
{
    $ids = array_values(array_filter(array_map('intval', $contactIds)));
    if (!$ids || $gid <= 0) return 0;
    $in = implode(',', $ids);
    return (int)DB::run("DELETE FROM " . DB::t('contact_group_members') . " WHERE group_id=? AND contact_id IN ($in)", [$gid])->rowCount();
}

/* ══════════════════════════ CRUD مخاطب ══════════════════════════ */

/** تاریخ تولد: ورودی شمسی یا میلادی → Y-m-d یا null */
function fr_contact_birthdate(string $input): ?string
{
    $input = en_num(trim($input));
    if ($input === '') return null;
    $input = str_replace(['-', '.'], '/', $input);
    if (preg_match('#^(\d{3,4})/(\d{1,2})/(\d{1,2})$#', $input, $m)) {
        $y = (int)$m[1];
        if ($y > 1900) return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);   // میلادی
        $g = jalali_to_mysql($input);   // شمسی
        return $g;
    }
    return null;
}

/**
 * افزودن/به‌روزرسانی مخاطب (dedupe بر اساس موبایل). خروجی: [id, created]
 * $extra: ['birth_date'=>?, 'note'=>?, 'tg_username'=>?, 'bale_username'=>?, 'tg_chat_id'=>?, 'bale_chat_id'=>?, 'groups'=>[ids]]
 */
function fr_contact_add(string $name, string $mobile, string $segment = '', string $source = 'manual', string $note = '', array $extra = []): array
{
    $mobile = fr_contact_mobile($mobile);
    if ($mobile === '') return [0, false];
    $name    = mb_substr(trim($name), 0, 120);
    $segment = mb_substr(trim($segment), 0, 60);
    $note    = mb_substr(trim($note), 0, 190);
    $t = DB::t('contacts');
    $birth = fr_contact_birthdate((string)($extra['birth_date'] ?? ''));

    $row = DB::one("SELECT id FROM $t WHERE mobile=?", [$mobile]);
    if ($row) {
        $cid = (int)$row['id'];
        $set = "updated_at=NOW()";
        $pr  = [];
        if ($name !== '')    { $set .= ", name=?";    $pr[] = $name; }
        if ($segment !== '') { $set .= ", segment=?"; $pr[] = $segment; }
        if ($birth)          { $set .= ", birth_date=?"; $pr[] = $birth; }
        DB::run("UPDATE $t SET $set WHERE id=?", array_merge($pr, [$cid]));
        if (isset($extra['groups'])) fr_contact_set_groups($cid, (array)$extra['groups']);
        fr_contact_check($cid);
        return [$cid, false];
    }
    $data = [
        'name' => $name, 'mobile' => $mobile, 'segment' => $segment,
        'source' => mb_substr($source, 0, 30), 'note' => $note, 'status' => 'active',
        'birth_date' => $birth,
    ];
    foreach (['tg_username', 'bale_username'] as $k) {
        if (!empty($extra[$k])) $data[$k] = ltrim(mb_substr(trim((string)$extra[$k]), 0, 60), '@');
    }
    foreach (['tg_chat_id', 'bale_chat_id'] as $k) {
        if (!empty($extra[$k])) $data[$k] = mb_substr(trim((string)$extra[$k]), 0, 60);
    }
    if (($data['tg_chat_id'] ?? '') !== '')   $data['tg_ok'] = 1;
    if (($data['bale_chat_id'] ?? '') !== '') $data['bale_ok'] = 1;
    /* قبلاً در سایت ثبت‌نام کرده؟ اتصال‌ها را همان لحظه میراث می‌گیرد */
    $u = DB::one("SELECT id,telegram_chat_id,bale_chat_id,tg_username,bale_username,birth_date FROM " . DB::t('users') . " WHERE mobile=? LIMIT 1", [$mobile]);
    if ($u) {
        $data['user_id'] = (int)$u['id'];
        if (($data['tg_chat_id'] ?? '') === '' && trim((string)$u['telegram_chat_id']) !== '') {
            $data['tg_chat_id'] = (string)$u['telegram_chat_id']; $data['tg_ok'] = 1;
        }
        if (($data['bale_chat_id'] ?? '') === '' && trim((string)$u['bale_chat_id']) !== '') {
            $data['bale_chat_id'] = (string)$u['bale_chat_id']; $data['bale_ok'] = 1;
        }
        if (empty($data['tg_username']) && trim((string)($u['tg_username'] ?? '')) !== '')   $data['tg_username'] = (string)$u['tg_username'];
        if (empty($data['bale_username']) && trim((string)($u['bale_username'] ?? '')) !== '') $data['bale_username'] = (string)$u['bale_username'];
        if (!$birth && !empty($u['birth_date'])) $data['birth_date'] = (string)$u['birth_date'];
    }
    $data['invite_code'] = strtoupper(bin2hex(random_bytes(4)));   // کد دیپ‌لینک اختصاصی
    $data['checked_at']  = date('Y-m-d H:i:s');
    $id = DB::insert('contacts', $data);
    if (isset($extra['groups'])) fr_contact_set_groups($id, (array)$extra['groups']);
    return [$id, true];
}

/** ویرایش کامل یک مخاطب (فرم ویرایش تکی) */
function fr_contact_update(int $cid, array $fields, array $groupIds): bool
{
    $c = DB::one("SELECT * FROM " . DB::t('contacts') . " WHERE id=?", [$cid]);
    if (!$c) return false;
    $mobile = fr_contact_mobile((string)($fields['mobile'] ?? ''));
    if ($mobile === '') return false;
    /* یکتایی موبایل */
    $dup = (int)DB::val("SELECT id FROM " . DB::t('contacts') . " WHERE mobile=? AND id<>? LIMIT 1", [$mobile, $cid]);
    if ($dup > 0) return false;
    $data = [
        'name'    => mb_substr(trim((string)($fields['name'] ?? '')), 0, 120),
        'mobile'  => $mobile,
        'segment' => mb_substr(trim((string)($fields['segment'] ?? '')), 0, 60),
        'note'    => mb_substr(trim((string)($fields['note'] ?? '')), 0, 190),
        'birth_date' => fr_contact_birthdate((string)($fields['birth_date'] ?? '')),
        'tg_username'   => ltrim(mb_substr(trim((string)($fields['tg_username'] ?? '')), 0, 60), '@') ?: null,
        'bale_username' => ltrim(mb_substr(trim((string)($fields['bale_username'] ?? '')), 0, 60), '@') ?: null,
        'tg_chat_id'    => trim((string)($fields['tg_chat_id'] ?? '')) !== '' ? mb_substr(trim((string)$fields['tg_chat_id']), 0, 60) : null,
        'bale_chat_id'  => trim((string)($fields['bale_chat_id'] ?? '')) !== '' ? mb_substr(trim((string)$fields['bale_chat_id']), 0, 60) : null,
    ];
    /* وضعیت ok را از روی دادهٔ واقعی بازمحاسبه می‌کنیم */
    $data['tg_ok']   = ($data['tg_chat_id'] ?? '') !== '' ? 1 : 0;
    $data['bale_ok'] = ($data['bale_chat_id'] ?? '') !== '' ? 1 : 0;
    DB::update('contacts', $data, 'id=?', [$cid]);
    fr_contact_set_groups($cid, $groupIds);
    fr_contact_check($cid);
    return true;
}

/**
 * بررسی وضعیت کانال‌های یک مخاطب (به‌محض ثبت/ویرایش — بند ۴ و ۷ درخواست):
 *  ۱) اگر موبایلش کاربر سایت است و ربات را استارت کرده ← chat_id را میراث می‌برد (اتصال آماده)
 *  ۲) اگر username ذخیره شده ← getChat به ربات (آیا ربات را استارت کرده؟)
 *  ۳) chat_id ذخیره‌شدهٔ دستی ← getChat صحت‌سنجی می‌شود
 * نکتهٔ فنی صادقانه: API ربات نمی‌تواند «حساب از روی شماره» پیدا کند؛
 * بنابراین نتیجه هر بررسی یا «متصل» است یا «نیازمند استارت».
 */
function fr_contact_check(int $cid): array
{
    $res = ['telegram' => 'unknown', 'bale' => 'unknown'];
    try {
        $c = DB::one("SELECT * FROM " . DB::t('contacts') . " WHERE id=?", [$cid]);
        if (!$c) return $res;
        $u = DB::one("SELECT id,telegram_chat_id,bale_chat_id,tg_username,bale_username FROM " . DB::t('users') . " WHERE mobile=? AND status='active' LIMIT 1", [(string)$c['mobile']]);
        if ($u) {
            $fix = [];
            if (trim((string)$u['telegram_chat_id']) !== '') { $fix['tg_chat_id'] = (string)$u['telegram_chat_id']; $fix['tg_ok'] = 1; $res['telegram'] = 'connected'; }
            if (trim((string)$u['bale_chat_id']) !== '')     { $fix['bale_chat_id'] = (string)$u['bale_chat_id']; $fix['bale_ok'] = 1; $res['bale'] = 'connected'; }
            if (trim((string)($u['tg_username'] ?? '')) !== '' && trim((string)$c['tg_username']) === '') $fix['tg_username'] = (string)$u['tg_username'];
            if (trim((string)($u['bale_username'] ?? '')) !== '' && trim((string)$c['bale_username']) === '') $fix['bale_username'] = (string)$u['bale_username'];
            if ($fix) { $fix['user_id'] = (int)$u['id']; DB::update('contacts', $fix, 'id=?', [$cid]); $c = array_merge($c, $fix); }
        }
        /* getChat برای usernameهای ذخیره‌شده (فقط وقتی ربات فعال است و هنوز chat_id نداریم) */
        foreach (['telegram' => ['tg_chat_id', 'tg_username', 'Telegram'], 'bale' => ['bale_chat_id', 'bale_username', 'Bale']] as $ch => [$cidCol, $unCol, $klass]) {
            if ($res[$ch] === 'connected') continue;
            if (trim((string)($c[$cidCol] ?? '')) !== '') { $res[$ch] = 'connected'; continue; }
            $un = trim((string)($c[$unCol] ?? ''));
            if ($un === '') { $res[$ch] = 'needs_start'; continue; }
            require_once __DIR__ . '/../core/' . $klass . '.php';
            $enabled = $klass === 'Telegram' ? Telegram::enabled() : Bale::enabled();
            if (!$enabled) { $res[$ch] = 'bot_off'; continue; }
            [$ok, $resp] = $klass::call('getChat', ['chat_id' => '@' . $un]);
            if ($ok && !empty($resp['result']['id'])) {
                DB::update('contacts', [$cidCol => (string)$resp['result']['id'], $ch === 'telegram' ? 'tg_ok' : 'bale_ok' => 1], 'id=?', [$cid]);
                $res[$ch] = 'connected';
            } else {
                /* chat not found یعنی یا هنوز ربات را استارت نکرده یا ربات به او دسترسی ندارد */
                $res[$ch] = 'needs_start';
            }
        }
        DB::update('contacts', ['checked_at' => date('Y-m-d H:i:s')], 'id=?', [$cid]);
    } catch (Throwable $e) { /* بی‌صدا — جداول تازه‌اند */ }
    return $res;
}

/** لینک دعوت دیپ‌لینک (start خودکار) برای یک مخاطب روی یک کانال */
function fr_contact_invite_link(array $contact, string $channel): string
{
    $code = trim((string)($contact['invite_code'] ?? ''));
    if ($code === '') {
        $code = strtoupper(bin2hex(random_bytes(4)));
        DB::update('contacts', ['invite_code' => $code], 'id=?', [(int)$contact['id']]);
    }
    if (function_exists('fr_snotify_driver')) {
        [, , $bot] = fr_snotify_driver($channel);
    } else {
        require_once __DIR__ . '/snotify.php';
        [, , $bot] = fr_snotify_driver($channel);
    }
    $bot = ltrim(trim((string)$bot), '@');
    if ($bot === '') return '';
    $base = $channel === 'telegram' ? 'https://t.me/' : 'https://ble.ir/';
    return $base . $bot . '?start=' . $code;
}

/** ارسال پیامک دعوت برای مخاطب (متن + لینک استارت خودکار) — همان «تأیید start از راه دورِ» بند ۷ */
function fr_contact_send_invite(int $cid, string $bodyText = ''): array
{
    $c = DB::one("SELECT * FROM " . DB::t('contacts') . " WHERE id=?", [$cid]);
    if (!$c) return ['ok' => false, 'message' => 'مخاطب یافت نشد.'];
    $link = fr_contact_invite_link($c, 'telegram');
    $linkBale = fr_contact_invite_link($c, 'bale');
    if ($link === '' && $linkBale === '') return ['ok' => false, 'message' => 'ابتدا نام کاربری ربات تلگرام/بله را در «اطلاع‌رسانی اجتماعی» تنظیم کنید.'];
    require_once __DIR__ . '/../core/Kavenegar.php';
    $site = (string)DB::setting('site_title', 'فریا روان');
    $msg = $bodyText !== '' ? $bodyText
        : ($c['name'] !== '' ? $c['name'] . " عزیز،" : '') . "سلام! برای دریافت پیام‌های «{$site}» در پیام‌رسان، روی لینک زیر بزنید (دکمهٔ استارت خودش ثبت می‌شود):" . "\n"
        . ($link !== '' ? "تلگرام: {$link}\n" : '') . ($linkBale !== '' ? "بله: {$linkBale}" : '');
    $ok = (new Kavenegar())->send((string)$c['mobile'], $msg, 'contact_invite');
    if ($ok) DB::update('contacts', ['invite_sent_at' => date('Y-m-d H:i:s')], 'id=?', [$cid]);
    return ['ok' => $ok, 'message' => $ok ? 'پیامک دعوت ارسال شد؛ با لمس لینک، استارت ربات خودکار ثبت می‌شود.' : 'ارسال پیامک ناموفق — تنظیمات کاوه‌نگار را بررسی کنید.'];
}

/**
 * همگام‌سازی خودکار از کاربر سایت → مخاطبین (بند ۳: هر ثبت‌نامی در مخاطبین ثبت شود).
 * هم در لحظهٔ ساخت کاربر و هم در به‌روزرسانی پروفایل صدا زده می‌شود؛ idempotent.
 */
function fr_contact_sync_user(array $user): void
{
    $mob = fr_contact_mobile((string)($user['mobile'] ?? ''));
    if ($mob === '') return;
    try {
        $name = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        $birth = !empty($user['birth_date']) ? (string)$user['birth_date'] : null;
        $row = DB::one("SELECT id,birth_date FROM " . DB::t('contacts') . " WHERE mobile=?", [$mob]);
        $fix = [
            'user_id'   => (int)($user['id'] ?? 0) ?: null,
            'checked_at'=> date('Y-m-d H:i:s'),
        ];
        if (trim((string)($user['telegram_chat_id'] ?? '')) !== '') { $fix['tg_chat_id'] = (string)$user['telegram_chat_id']; $fix['tg_ok'] = 1; }
        if (trim((string)($user['bale_chat_id'] ?? '')) !== '')     { $fix['bale_chat_id'] = (string)$user['bale_chat_id']; $fix['bale_ok'] = 1; }
        if (trim((string)($user['tg_username'] ?? '')) !== '') $fix['tg_username'] = (string)$user['tg_username'];
        if (trim((string)($user['bale_username'] ?? '')) !== '') $fix['bale_username'] = (string)$user['bale_username'];
        if ($row) {
            if ($name !== '') $fix['name'] = mb_substr($name, 0, 120);
            if ($birth && empty($row['birth_date'])) $fix['birth_date'] = $birth;
            DB::update('contacts', $fix, 'id=?', [(int)$row['id']]);
        } else {
            $fix += [
                'name' => mb_substr($name, 0, 120), 'mobile' => $mob, 'segment' => '',
                'source' => 'register', 'status' => 'active',
                'birth_date' => $birth,
                'invite_code' => strtoupper(bin2hex(random_bytes(4))),
            ];
            DB::insert('contacts', $fix);
        }
    } catch (Throwable $e) { /* جداول ۲٫۷ هنوز نیامده‌اند */ }
}

/* ══════════════════════════ درون‌ریزی ══════════════════════════ */

/**
 * درون‌ریزی گروهی از فایل اکسل/CSV یا متن چسبانده‌شده.
 * جهت ستون‌ها خودکار: شبیه موبایل = موبایل؛ شبیه تاریخ = تولد؛ ستون دیگر = نام.
 * خروجی: ['ok','added','merged','invalid','samples'=>[…], 'error']
 */
function fr_contacts_import(array $file, string $segment, string $pasted = ''): array
{
    $rows = [];
    $err  = null;
    if (trim($pasted) !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $pasted) as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $rows[] = preg_split('/[,\t;،]+/u', $ln);
        }
    } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $r = fr_table_upload_rows($file);
        if (!$r['ok']) $err = (string)($r['error'] ?? 'خطا در خواندن فایل.');
        else $rows = $r['rows'];
    } else {
        $err = 'فایلی انتخاب نشده است؛ فایل CSV/XLSX بارگذاری کنید یا شماره‌ها را در کادر بچسبانید.';
    }
    $out = ['ok' => $err === null, 'added' => 0, 'merged' => 0, 'invalid' => 0, 'samples' => [], 'error' => $err];
    if ($err !== null) return $out;

    $first = true;
    foreach ($rows as $cols) {
        $cols = array_map(fn($x) => trim((string)$x), array_values($cols));
        $mobile = ''; $birth = null; $names = [];
        foreach ($cols as $cell) {
            if ($cell === '') continue;
            $m = fr_contact_mobile($cell);
            if ($m !== '') { if ($mobile === '') $mobile = $m; continue; }
            $b = fr_contact_birthdate($cell);
            if ($b !== null) { $birth ??= $b; continue; }
            $names[] = $cell;
        }
        if ($mobile === '') {
            if ($first) { $first = false; continue; }   // سربرگ
            $out['invalid']++;
            if (count($out['samples']) < 6) $out['samples'][] = mb_substr(implode(' ', $cols), 0, 60);
            continue;
        }
        $first = false;
        $name = mb_substr(implode(' ', $names), 0, 120);
        [$id, $created] = fr_contact_add($name, $mobile, $segment, 'import', '', ['birth_date' => $birth ?? '']);
        $id > 0 && $created ? $out['added']++ : ($id > 0 ? $out['merged']++ : $out['invalid']++);
    }
    fr_contacts_match_social();
    return $out;
}

/* ══════════════════════════ تطبیق و آمار ══════════════════════════ */

/** علامت‌گذاری/میراث کامل مخاطبین از کاربران متصل سایت (chat_id/username/wiring) */
function fr_contacts_match_social(): void
{
    try {
        DB::run("UPDATE " . DB::t('contacts') . " c JOIN " . DB::t('users') . " u ON u.mobile=c.mobile
                 SET c.tg_ok   = (COALESCE(c.tg_chat_id,'')<>'' OR COALESCE(u.telegram_chat_id,'') <> ''),
                     c.bale_ok = (COALESCE(c.bale_chat_id,'')<>'' OR COALESCE(u.bale_chat_id,'') <> ''),
                     c.tg_chat_id   = COALESCE(NULLIF(c.tg_chat_id,''),   NULLIF(u.telegram_chat_id,'')),
                     c.bale_chat_id = COALESCE(NULLIF(c.bale_chat_id,''), NULLIF(u.bale_chat_id,'')),
                     c.tg_username   = COALESCE(NULLIF(c.tg_username,''),   NULLIF(u.tg_username,'')),
                     c.bale_username = COALESCE(NULLIF(c.bale_username,''), NULLIF(u.bale_username,'')),
                     c.user_id = COALESCE(c.user_id, u.id),
                     c.updated_at = NOW()");
    } catch (Throwable $e) { /* جداول تازه ساخته می‌شوند */ }
}

/** فهرست برچسب‌های قدیمی (segment) با شمارش — برای سازگاری با فرم‌های قبلی */
function fr_contacts_segments(): array
{
    return DB::all("SELECT segment, COUNT(*) AS n, SUM(tg_ok) AS tg, SUM(bale_ok) AS bl
                    FROM " . DB::t('contacts') . " GROUP BY segment ORDER BY n DESC");
}

/** عملیات گروهی روی انتخاب‌شده‌ها: [deleted|blocked|activated] */
function fr_contacts_bulk_status(array $ids, string $mode): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return 0;
    $in = implode(',', $ids);
    if ($mode === 'delete') {
        try { DB::run("DELETE FROM " . DB::t('contact_group_members') . " WHERE contact_id IN ($in)"); } catch (Throwable $e) { }
        return (int)DB::run("DELETE FROM " . DB::t('contacts') . " WHERE id IN ($in)")->rowCount();
    }
    $st = $mode === 'block' ? 'blocked' : 'active';
    return (int)DB::run("UPDATE " . DB::t('contacts') . " SET status=? WHERE id IN ($in)", [$st])->rowCount();
}
