<?php
declare(strict_types=1);
/* ════════════════════════════════════════════════════════════════════════════
 * QA نسخهٔ ۲٫۷ — بستهٔ پیام‌رسانی: مخاطبین حرفه‌ای + گروه‌ها + کمپین چندکاناله با
 * رهگیری کلیک + گزارش جامع + پلنر پیشرفته (دسته/یادآور/جست‌وجو) + مدیریت خبرنامه
 * ════════════════════════════════════════════════════════════════════════════ */
const BASE     = 'http://localhost:8899';
const A_COOKIE = '/tmp/qa27-admin.jar';
const U_COOKIE = '/tmp/qa27-user.jar';

$APP = '/tmp/feria-smoke';
require $APP.'/config.php';
require $APP.'/includes/version.php';
require FR_ROOT.'/includes/db.php';
require FR_ROOT.'/includes/functions.php';
require FR_ROOT.'/includes/security.php';
require FR_ROOT.'/includes/contacts.php';
require FR_ROOT.'/includes/campaigns.php';
require FR_ROOT.'/includes/planner.php';
require FR_ROOT.'/core/Bale.php';
require FR_ROOT.'/core/Kavenegar.php';
require FR_ROOT.'/core/MsgGateway.php';

$fail = 0; $pass = 0;
function ok(bool $c, string $label): void {
    global $fail, $pass;
    echo ($c ? '✅' : '❌') . " $label\n";
    $c ? $pass++ : $fail++;
}
function http(string $method, string $path, array|string $data = [], string $jar = A_COOKIE): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
    ]);
    if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$code, substr($raw, 0, $hlen), substr($raw, $hlen)];
}
function getx(string $path, string $jar = A_COOKIE): array {
    [$c, $h, $b] = http('GET', $path, [], $jar);
    for ($i = 0; $i < 3 && in_array($c, [301,302,303,307,308], true); $i++) {
        if (!preg_match('/^Location:\\s*(\\S+)\\s*$/mi', $h, $m)) break;
        [$c, $h, $b] = http('GET', trim($m[1]), [], $jar);
    }
    return [$c, $b];
}

$tc = DB::t('contacts'); $tcg = DB::t('contact_groups'); $tcgm = DB::t('contact_group_members'); $tk = DB::t('campaigns'); $tkm = DB::t('campaign_messages'); $tkl = DB::t('campaign_links'); $tlk = DB::t('link_clicks'); $tpt = DB::t('planner_tasks'); $tu = DB::t('users');

/* پاک‌سازی داده‌های باقی‌مانده از اجراهای قبلی این سوییت (idempotent) */
function qa27_clean(): void {
    DB::run("DELETE FROM fr_contact_groups WHERE name LIKE 'qa27%'");
    DB::run("DELETE FROM fr_contacts WHERE mobile IN ('09123450001','09120000041','09120000042') OR mobile LIKE '091255500%'");
    DB::run("DELETE FROM fr_users WHERE mobile LIKE '091255500%'");
    DB::run("DELETE FROM fr_campaigns WHERE title LIKE 'qa27%'");
    DB::run("DELETE FROM fr_campaign_messages WHERE campaign_id NOT IN (SELECT id FROM fr_campaigns)");
    DB::run("DELETE FROM fr_campaign_links WHERE campaign_id NOT IN (SELECT id FROM fr_campaigns)");
    DB::run("DELETE FROM fr_link_clicks WHERE campaign_id NOT IN (SELECT id FROM fr_campaigns)");
    DB::run("DELETE FROM fr_planner_cats WHERE name LIKE 'qa27%'");
    DB::run("DELETE FROM fr_planner_tasks WHERE title LIKE '%تست ۲۷%' OR title LIKE 'کار تست%' OR title LIKE '%qa27%'");
}
qa27_clean();

echo "\n══════ ۰) پیش‌نیاز — نسخه + ورود ══════\n";
$ver = defined('FR_RELEASE') ? FR_RELEASE : (defined('FR_VERSION') ? FR_VERSION : '?');
ok(version_compare($ver, '2.7.0', '>='), "نسخهٔ کد ≥ ۲٫۷٫۰ است (FR_RELEASE=$ver)");
ok(version_compare((string)DB::setting('fr_version',''), '2.7.0', '>='), 'پایپ‌لاین ارتقا نسخهٔ دیتابیس را ≥۲٫۷٫۰ ثبت کرده', (string)DB::setting('fr_version',''));
/* کش تنظیمات پس از setSetting هم‌راستا می‌شود (رفع باگ ۲٫۷) */
DB::setSetting('qa27_cache_probe', 'val1');
ok(DB::setting('qa27_cache_probe','-') === 'val1', 'کش تنظیمات پس از نوشتن تازه است (بدون stale read)');
DB::run("DELETE FROM ".DB::t('settings')." WHERE `key`='qa27_cache_probe'");

http('GET', '/shot-login.php?as=admin&to=/admin/index.php');
[, $admBody] = getx('/admin/index.php');
ok(str_contains($admBody, 'پیشخوان') || str_contains($admBody, 'مدیریت'), 'ورود مدیر برای تست صفحات مدیریت');
http('GET', '/shot-login.php?as=user&to=/panel/index.php', [], U_COOKIE);
[, $usrBody] = getx('/panel/index.php', U_COOKIE);
ok(str_contains($usrBody, 'کاربر') || str_contains($usrBody, 'پنل') || str_contains($usrBody, 'حساب'), 'ورود کاربر برای تست پنل');

echo "\n══════ ۱) مخاطبین — گروه‌ها / تولد / ثبت خودکار ══════\n";
$g1 = fr_contact_group_add('qa27-گروه‌آلفا', '#aa1122', 'گروه آزمایشی ۱');
$g2 = fr_contact_group_add('qa27-گروه‌بتا',  '#00aa55', 'گروه آزمایشی ۲');
ok($g1 > 0 && $g2 > 0 && $g1 !== $g2, "ایجاد دو گروه مخاطبین (id: $g1,$g2)");
ok(fr_contact_group_add('qa27-گروه‌آلفا') === 0, 'نام تکراری گروه پذیرفته نمی‌شود (۰ برمی‌گردد)');
ok(fr_contact_group_update($g2, 'qa27-گروه‌بتا-۲', '#0066cc', '') === true, 'ویرایش نام/رنگ گروه');
$gNames = array_column(fr_contact_groups(), 'name');
ok(in_array('qa27-گروه‌آلفا', $gNames, true) && in_array('qa27-گروه‌بتا-۲', $gNames, true), 'فهرست گروه‌ها به‌روز است');

$bd = fr_contact_birthdate('1372/05/12');
ok($bd === '1993-08-03', "تبدیل تولد شمسی به میلادی صحیح (۱۳۷۲/۰۵/۱۲ → " . ($bd ?? 'NULL') . ")");
ok(fr_contact_birthdate('1993-08-03') === '1993-08-03', 'تولد میلادی هم پذیرفته می‌شود');
ok(fr_contact_birthdate('not-a-date') === null, 'ورودی نامعتبر تولد null می‌دهد');

$r = fr_contact_add('مخاطب آزمایشی', '09123450001', '', 'manual', 'QA27', [
    'birth_date' => '1372/05/12', 'tg_username' => 'qatest_tg', 'groups' => [$g1, $g2],
]);
$cid = (int)($r[0] ?? 0);
ok($cid > 0, 'ایجاد مخاطب با تولد/آیدی/گروه');
$cRow = DB::one("SELECT * FROM $tc WHERE id=?", [$cid]);
ok(($cRow['birth_date'] ?? '') === '1993-08-03', 'birth_date درست در DB ثبت شد');
ok($cRow['tg_username'] === 'qatest_tg', 'آیدی تلگرام مخاطب ذخیره شد');
$cidGs = array_map('intval', array_column((array)fr_contact_group_of($cid), 'id'));
sort($cidGs);
ok($cidGs === [$g1, $g2], 'عضویت در هر دو گروه ثبت شد');

ok(fr_contact_update($cid, ['mobile' => '09123450001', 'name' => 'مخاطب ویرایش‌شده', 'note' => 'X'], [$g2]) === true, 'ویرایش مخاطب (تابع)');
$cRow = DB::one("SELECT name, note FROM $tc WHERE id=?", [$cid]);
ok($cRow['name'] === 'مخاطب ویرایش‌شده', 'نام مخاطب ویرایش شد');
$cidGs2 = array_map('intval', array_column((array)fr_contact_group_of($cid), 'id'));
ok($cidGs2 === [$g2], 'بازتخصیص گروه‌ها فقط به بتا-۲');

/* ثبت خودکار مخاطب هنگام ثبت‌نام کاربر */
$mobNew = '091255500' . random_int(10, 99);
$uid = (int)DB::insert('users', ['mobile' => $mobNew, 'password_hash' => password_hash('Qa!12345', PASSWORD_DEFAULT), 'first_name' => 'کیو', 'last_name' => 'ای27', 'status' => 'active']);
fr_contact_sync_user(['id' => $uid, 'mobile' => $mobNew, 'first_name' => 'کیو', 'last_name' => 'ای27']);
$syn = DB::one("SELECT * FROM $tc WHERE mobile=?", [$mobNew]);
ok($syn !== null && ($syn['source'] ?? '') === 'register', 'ثبت‌نام کاربر → مخاطب خودکار ساخته شد (source=register)');
ok((int)$syn['user_id'] === $uid, 'شناسهٔ کاربر روی مخاطب اتصال یافت');
fr_contact_sync_user(['id' => $uid, 'mobile' => $mobNew, 'first_name' => 'کیو', 'last_name' => 'ای27']);
ok((int)DB::val("SELECT COUNT(*) FROM $tc WHERE mobile=?", [$mobNew]) === 1, 'سینک دوبارهٔ همان کاربر، مخاطب تکراری نمی‌سازد');

/* بررسی وضعیت کانال‌ها بر اساس اتصال کاربر */
DB::run("UPDATE $tu SET telegram_chat_id='777000111' WHERE id=?", [$uid]);
$chk = fr_contact_check((int)$syn['id']);
ok(($chk['telegram'] ?? '') === 'connected', 'چک وضعیت تلگرام مخاطب ثبت‌نام‌کرده = connected');
ok(in_array($chk['bale'] ?? 'unknown', ['unknown','needs_start','bot_off'], true), 'وضعیت بله یکی از حالت‌های معتبر است');
ok(DB::val("SELECT checked_at IS NOT NULL FROM $tc WHERE id=?", [(int)$syn['id']]) == 1, 'checked_at پس از چک ثبت شد');

/* لینک دعوت و کد */
$lnk = fr_contact_invite_link($syn, 'telegram');
ok($lnk === '' || str_contains($lnk, '?start='), 'لینک دعوت تلگرام یا خالی است (بات خاموش) یا deep-link معتبر');
$set = DB::val("SELECT invite_code FROM $tc WHERE id=?", [(int)$syn['id']]);
ok(is_string($set) && strlen((string)$set) >= 4, 'invite_code برای مخاطب ساخته و ذخیره شده');

/* عملیات گروهی وضعیت */
$a1 = (int)(fr_contact_add('bulk1', '09120000041', '', 'manual')[0] ?? 0);
$a2 = (int)(fr_contact_add('bulk2', '09120000042', '', 'manual')[0] ?? 0);
$n = fr_contacts_bulk_status([$a1, $a2], 'block');
ok($n === 2 && (int)DB::val("SELECT COUNT(*) FROM $tc WHERE id IN ($a1,$a2) AND status='blocked'") === 2, 'مسدودسازی گروهی دو مخاطب');
fr_contacts_bulk_status([$a1], 'activate');
ok(DB::val("SELECT status FROM $tc WHERE id=?", [$a1]) === 'active', 'فعال‌سازی مجدد یک مخاطب');

echo "\n══════ ۲) کمپین — چندکاناله / ویرایش / صف / رهگیری / گزارش ══════\n";
$body = '<b>سلام {name}</b> عزیز! لینک ما: <a href="https://example.com/landing">مشاهده</a> و <a href="https://example.com/second">دوم</a>';
$kamp = fr_campaign_create('qa27-کمپین', $body, 'both', 'contacts', '', 0, 1, [
    'channels' => ['sms', 'telegram'], 'batch_size' => 5, 'delay_ms' => 10, 'track_links' => 1,
]);
ok($kamp > 0, "ایجاد کمپین چندکاناله (id=$kamp)");
$kRow = DB::one("SELECT * FROM $tk WHERE id=?", [$kamp]);
ok($kRow['channels'] === 'sms,telegram', 'کانال‌ها به‌صورت CSV ذخیره شد');
ok((int)$kRow['batch_size'] === 5 && (int)$kRow['delay_ms'] === 10, 'batch/delay ذخیره شد');

/* ویرایش روی پیش‌نویس (قبل از صف) */
ok(fr_campaign_edit($kamp, ['title' => 'qa27-کمپین-ویرایش', 'body' => $body, 'audience' => 'contacts',
    'channels' => ['sms', 'telegram'], 'batch_size' => 5, 'delay_ms' => 10, 'track_links' => 1]) === true, 'ویرایش کمپین (پیش‌نویس)');
ok(DB::val("SELECT status FROM $tk WHERE id=?", [$kamp]) === 'edited', 'وضعیت پس از ویرایش = edited');
ok(DB::val("SELECT title FROM $tk WHERE id=?", [$kamp]) === 'qa27-کمپین-ویرایش', 'عنوان جدید ذخیره شد');
$dupid = fr_campaign_duplicate($kamp, 1);
ok($dupid > 0, "کپی کمپین (id=$dupid)");
ok(DB::val("SELECT status FROM $tk WHERE id=?", [$dupid]) === 'draft', 'کپی با وضعیت پیش‌نویس ساخته شد');
ok(!fr_campaign_edit($kamp + 99999, ['title' => 'x', 'body' => 'y']), 'ویرایش کمپین ناموجود false می‌دهد');

/* صف‌سازی */
$q = fr_campaign_queue($kamp);
ok(!empty($q['ok']), 'صف‌سازی کمپین موفق' . (empty($q['ok']) ? (' — ' . ($q['message'] ?? '')) : ''));
$tot = (int)DB::val("SELECT COUNT(*) FROM $tkm WHERE campaign_id=?", [$kamp]);
$chs = array_column(DB::all("SELECT DISTINCT channel FROM $tkm WHERE campaign_id=?", [$kamp]), 'channel');
ok($tot > 0, "پیام‌ها در صف ثبت شدند ($tot پیام)");
ok(in_array('sms', $chs, true) && in_array('telegram', $chs, true), 'هر دو کانال sms و telegram در صف هستند');
$tracked = DB::one("SELECT m.id, m.body FROM $tkm m WHERE m.campaign_id=? AND m.body LIKE '%__FRGO%' LIMIT 1", [$kamp]);
$tracked2 = DB::one("SELECT m.id, m.body FROM $tkm m WHERE m.campaign_id=? AND m.body LIKE '%go.php%' LIMIT 1", [$kamp]);
ok($tracked !== null || $tracked2 !== null, 'لینک‌های بدنه به مسیر رهگیری بازنویسی شدند');
$mid = (int)($tracked2['id'] ?? 0);
$lnkCnt = (int)DB::val("SELECT COUNT(*) FROM $tkl WHERE campaign_id=?", [$kamp]);
ok($lnkCnt >= 2, "رکوردهای رهگیری لینک ساخته شدند ($lnkCnt لاین)");
$kRow = DB::one("SELECT status,total FROM $tk WHERE id=?", [$kamp]);
ok($kRow['status'] === 'queued' && (int)$kRow['total'] === $tot, 'وضعیت کمپین queued + total درست');
ok($mid > 0, 'پیام با لینک نهایی go.php یافت شد');

/* امضای لینک go.php */
$sig0 = fr_campaign_link_sig($mid, 0);
[$codeOk, $hOk] = http('GET', "/go.php?m=$mid&i=0&s=$sig0");
$locOk = '';
if (preg_match('/^Location:\\s*(\\S+)\\s*$/mi', $hOk, $lm)) $locOk = trim($lm[1]);
ok($codeOk === 302 && str_starts_with($locOk, 'https://example.com'), 'go.php با امضای درست → 302 به مقصد واقعی');
ok((int)DB::val("SELECT clicks FROM $tkl WHERE message_id=? AND idx=0", [$mid]) === 1, 'شمارندهٔ کلیک لینک +۱ شد');
ok((int)DB::val("SELECT COUNT(*) FROM $tlk WHERE message_id=?", [$mid]) === 1, 'ردیف خام کلیک ثبت شد');
ok(DB::val("SELECT clicked_at IS NOT NULL FROM $tkm WHERE id=?", [$mid]) == 1, 'clicked_at پیام ثبت شد');
[$codeBad, $hBad] = http('GET', "/go.php?m=$mid&i=0&s=wrongsig00");
$locBad = '';
if (preg_match('/^Location:\\s*(\\S+)\\s*$/mi', $hBad, $lm2)) $locBad = trim($lm2[1]);
ok($codeBad === 302 && !str_starts_with($locBad, 'https://example.com'), 'امضای غلط → هدایت به خانه (نه مقصد)');
ok((int)DB::val("SELECT COUNT(*) FROM $tlk WHERE message_id=?", [$mid]) === 1, 'امضای غلط کلیکی ثبت نکرد');

/* همگام‌سازی تحویل پیامک بدون کاوه‌نگار فعال */
ok(fr_campaign_sms_sync($kamp) === 0, 'sms_sync بدون کاوه‌نگار = ۰ (بدون خطا)');

/* زمان‌دار: آینده → scheduled ؛ سررسیده → queued */
$sid = fr_campaign_create('qa27-زمان‌دار', 'سلام زمان‌دار', 'both', 'contacts', 'بدون_گروه_خاص_qa27', 0, 1,
    ['channels' => ['sms'], 'scheduled_at' => date('Y-m-d H:i:s', time() + 7200)]);
$sq = fr_campaign_queue($sid);
ok(DB::val("SELECT status FROM $tk WHERE id=?", [$sid]) === 'scheduled', 'صف با موعد آینده → وضعیت scheduled');
DB::run("UPDATE $tk SET scheduled_at=? WHERE id=?", [date('Y-m-d H:i:s', time() - 60), $sid]);
$prom = fr_campaign_promote_scheduled();
ok($prom >= 1 && DB::val("SELECT status FROM $tk WHERE id=?", [$sid]) === 'queued', "زمان‌دارِ سررسیده به queued ارتقا یافت (promote=$prom)");

/* آنالیتیکس */
$an = fr_campaign_analytics($kamp);
$needKeys = ['total','pending','sent','failed','skipped','delivered','clicked','replied','ctr','delivery_rate','by_channel','top_links','daily','clicks_daily','total_clicks'];
$missing = array_diff($needKeys, array_keys($an));
ok($missing === [], 'کلیدهای گزارش جامع کمپین کامل‌اند' . ($missing ? (' — جامانده: ' . implode(',', $missing)) : ''));
ok($an['clicked'] >= 1 && $an['total_clicks'] >= 1, "کلیک در گزارش لحاظ شد (clicked={$an['clicked']}, total_clicks={$an['total_clicks']})");
$st = fr_campaign_stats();
ok(isset($st['clicked'], $st['delivered'], $st['replied']), 'آمار سراسری کمپین‌ها کلیدهای ۲٫۷ را دارد');

echo "\n══════ ۳) پلنر — دسته‌ها / جست‌وجو / هفته / گروهی / یادآور ══════\n";
$pu = (int)DB::val("SELECT id FROM $tu WHERE mobile='09121112233'");
ok($pu > 0, 'کاربر فیکسچر پنل موجود است');
$catId = fr_planner_cat_add($pu, 'qa27-دسته', '#ff6600');
ok($catId > 0, "افزودن دستهٔ سفارشی (id=$catId)");
$map = fr_planner_cat_map($pu);
$myCat = null;
foreach ($map as $k => $v) { if ($k === 'qa27-دسته' || (is_array($v) && ($v[0] ?? '') === 'qa27-دسته')) $myCat = $v; }
ok(is_array($myCat) && in_array('#ff6600', (array)$myCat, true), 'نقشهٔ دسته‌ها رنگ دستهٔ جدید را دارد');
ok(is_array($map) && count($map) >= 5, 'دسته‌های پیش‌فرض حاضرند');

$day = date('Y-m-d');
$t1 = fr_planner_add($pu, $day, 'کار تست ۲۷ الف', '09:30', 'high', 'qa27-دسته', 'یادداشت QA', date('Y-m-d H:i:s', time() - 30));
$t2 = fr_planner_add($pu, $day, 'کار تست ۲۷ ب', '', 'low', 'personal');
ok($t1 > 0 && $t2 > 0, "افزودن دو کار با/بدون یادآور (t1=$t1,t2=$t2)");
$tr = DB::one("SELECT note,remind_at FROM $tpt WHERE id=?", [$t1]);
ok(($tr['note'] ?? '') === 'یادداشت QA' && !empty($tr['remind_at']), 'یادداشت و remind_at در DB ثبت شد');

ok(fr_planner_update($pu, $t1, ['title' => 'کار تست ویرایش', 'day' => $day, 'priority' => 'high',
    'category' => 'qa27-دسته', 'note' => 'یادداشت QA', 'time_note' => '09:30',
    'remind_at' => date('Y-m-d H:i:s', time() - 30)]) === true, 'ویرایش کار (تابع)');
ok(DB::val("SELECT title FROM $tpt WHERE id=?", [$t1]) === 'کار تست ویرایش', 'عنوان کار ویرایش شد');
ok(DB::val("SELECT remind_at IS NOT NULL FROM $tpt WHERE id=?", [$t1]) == 1, 'یادآور پس از ویرایش حفظ شد');

$sr = fr_planner_search($pu, ['q' => 'تست ۲۷', 'cat' => '', 'pri' => 'low'], 1, 10);
ok(isset($sr['rows'], $sr['total'], $sr['pages'], $sr['page']), 'خروجی جست‌وجو ساختار صفحه‌بندی دارد');
ok($sr['total'] >= 1, 'جست‌وجوی ترکیبی کار را می‌یابد');
$sr2 = fr_planner_search($pu, ['q' => 'کار تست ویرایش'], 1, 10);
ok($sr2['total'] >= 1, 'جست‌وجوی عنوان ویرایش‌شده');

$wv = fr_planner_week_view($pu, 0);
ok(isset($wv['start'], $wv['end']) && !empty($wv), 'نمای هفته ساختار برمی‌گرداند');
ok(date('N', strtotime($wv['start'])) === '6', 'شروع هفته = شنبه است');

$bn = fr_planner_bulk($pu, [$t2], 'done');
ok($bn >= 1 && (int)DB::val("SELECT done FROM $tpt WHERE id=?", [$t2]) === 1, 'انجام گروهی کارها');
fr_planner_bulk($pu, [$t2], 'undone');
ok((int)DB::val("SELECT done FROM $tpt WHERE id=?", [$t2]) === 0, 'برگشت به انجام‌نشده');

/* کانال‌های یادآور */
ok(fr_planner_notify_channels() === ['telegram', 'bale'], 'کانال‌های پیش‌فرض یادآور = telegram,bale');
DB::setSetting('planner_notify_channels', 'telegram,bale,sms');
ok(in_array('sms', fr_planner_notify_channels(), true), 'پیامک هم قابل فعال‌شدن در کانال‌هاست');
DB::setSetting('planner_notify_channels', 'telegram,bale');
$reminded = fr_planner_remind_due(20);
ok($reminded >= 0, "اجتکان remind_due بدون خطا اجرا شد (sent=$reminded)");
ok(DB::val("SELECT reminded_at IS NOT NULL FROM $tpt WHERE id=?", [$t1]) == 1, 'reminded_at برای کار سررسیده ثبت شد');
ok(fr_planner_remind_due(20) >= 0, 'اجرای دوبارهٔ remind_due قفل/تکرار ندارد');

echo "\n══════ ۴) صفحات مدیریت (HTTP) ══════\n";
[, $bContacts] = getx('/admin/contacts.php');
ok(str_contains($bContacts, 'qa27-گروه‌آلفا') || str_contains($bContacts, '09123450001'), 'صفحهٔ مخاطبین داده‌ها را نشان می‌دهد');
ok(str_contains($bContacts, '</html>'), 'صفحهٔ مخاطبین کامل رندر می‌شود (بدون قطع نیمه‌کاره)');
[, $bGroups] = getx('/admin/contacts.php?v=groups');
ok(str_contains($bGroups, 'qa27-گروه‌بتا-۲'), 'مدیر گروه‌ها گروه ویرایش‌شده را نشان می‌دهد');
[, $bCEdit] = getx("/admin/contacts.php?v=edit&id=$cid");
ok(str_contains($bCEdit, 'اتصال کانال‌های پیام‌رسان') && str_contains($bCEdit, 'ذخیرهٔ مخاطب') && str_contains($bCEdit, '</html>'), 'صفحهٔ ویرایش تکی مخاطب کامل رندر می‌شود (کارت کانال‌ها + ذخیره)');
[, $bCamp] = getx('/admin/campaigns.php');
ok(str_contains($bCamp, 'qa27-کمپین-ویرایش'), 'لیست کمپین‌ها کمپین ویرایش‌شده را دارد');
[, $bNew] = getx('/admin/campaigns.php?v=new');
ok(str_contains($bNew, 'sms-prev') || str_contains($bNew, 'پیش‌نمایش'), 'پیش‌نمایش دوگانه در فرم کمپین حاضر است');
ok(str_contains($bNew, 'batch_size') && str_contains($bNew, 'scheduled_at'), 'کنترل‌های حجم/زمان‌بندی در فرم هستند');
[, $bEditCamp] = getx("/admin/campaigns.php?v=edit&id=$dupid");
ok(str_contains($bEditCamp, 'qa27-کمپین-ویرایش') || str_contains($bEditCamp, 'کپی'), 'صفحهٔ ویرایش کمپین (کپی) باز می‌شود');
[, $bDet] = getx("/admin/campaigns.php?v=detail&id=$kamp");
ok(str_contains($bDet, 'کل پیام‌های صف') && str_contains($bDet, 'تحویل'), 'صفحهٔ جزئیات کمپین قیف/گزارش تعامل را نشان می‌دهد');
[, $bNews] = getx('/admin/newsletter.php');
ok(str_contains($bNews, 'حذف') && (str_contains($bNews, 'ویرایش') || str_contains($bNews, 'bulk')), 'خبرنامه ابزار ویرایش/حذف دارد');
[, $bPlAdm] = getx('/admin/planner.php');
ok(str_contains($bPlAdm, 'pn_ch') || str_contains($bPlAdm, 'کانال'), 'کارت انتخاب کانال یادآور در مدیریت پلنر');
[, $bNotif] = getx('/admin/notifications.php');
ok(str_contains($bNotif, 'bale_gateway_url') || str_contains($bNotif, 'درگاه'), 'کارت درگاه پیام‌رسان بله در صفحهٔ اطلاع‌رسانی‌ها');

echo "\n══════ ۵) صفحات کاربر (HTTP با نشست کاربر) ══════\n";
[, $bPl] = getx('/panel/planner.php', U_COOKIE);
ok(str_contains($bPl, 'برنامه'), 'برنامهٔ روزانهٔ کاربر باز می‌شود');
[, $bWk] = getx('/panel/planner.php?t=week', U_COOKIE);
ok(str_contains($bWk, 'هفته'), 'برگهٔ نمای هفته');
[, $bAll] = getx('/panel/planner.php?t=all', U_COOKIE);
ok(str_contains($bAll, 'جست') || str_contains($bAll, 'فیلتر') || str_contains($bAll, 'موردی') || str_contains($bAll, 'کار'), 'برگهٔ همهٔ کارها با جست‌وجو');
[, $bCats] = getx('/panel/planner.php?t=cats', U_COOKIE);
ok(str_contains($bCats, 'دسته'), 'برگهٔ مدیریت دسته‌ها');
[, $bEdit] = getx("/panel/planner.php?t=today&edit=$t1", U_COOKIE);
ok(str_contains($bEdit, 'ویرایش کار') || str_contains($bEdit, 'pl-modal'), 'مودال ویرایش کار رندر می‌شود');

echo "\n──── پاک‌سازی نهایی…\n";
qa27_clean();
echo "\n──── نتیجهٔ QA ۲٫۷: $pass پاس / $fail خطا ────\n";
exit($fail > 0 ? 1 : 0);
