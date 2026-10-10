<?php
/**
 * ============================================================================
 *  توابع کمکی عمومی — تقویم شمسی، اعداد فارسی، امنیت خروجی، ابزارها
 * ============================================================================
 */
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 | امنیت خروجی
 * -------------------------------------------------------------------------*/
/** امن‌سازی خروجی HTML (ضد XSS) */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** هدایت به آدرس دیگر */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** پاسخ JSON استاندارد برای AJAX */
function json_out(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 | اعداد فارسی و قالب‌بندی
 * -------------------------------------------------------------------------*/
const FR_DIGITS_FA = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
const FR_DIGITS_EN = ['0','1','2','3','4','5','6','7','8','9'];

/** تبدیل ارقام انگلیسی به فارسی */
function fa_num($n): string
{
    return str_replace(FR_DIGITS_EN, FR_DIGITS_FA, (string)$n);
}
/** تبدیل ارقام فارسی/عربی به انگلیسی (پردازش ورودی کاربر) */
function en_num($n): string
{
    return str_replace(
        array_merge(FR_DIGITS_FA, ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩']),
        FR_DIGITS_EN,
        (string)$n
    );
}

/**
 * نسخهٔ ۲٫۸: محاسبهٔ مرکزی «قیمت اصلی / قیمت با تخفیف»
 * ورودی: قیمت اصلی (list) و قیمت تخفیف‌خورده (sale؛ ممکن است null/۰ باشد)
 * خروجی: ['list'=>int,'final'=>int,'has_sale'=>bool,'percent'=>int,'save'=>int]
 * قاعده: تخفیف فقط وقتی معتبر است که ۰ < sale < list باشد.
 */
function fr_price_deal(int $list, ?int $sale): array
{
    $list = max(0, $list);
    $sale = (int)($sale ?? 0);
    $has  = ($sale > 0 && $list > 0 && $sale < $list);
    $final = $has ? $sale : $list;
    $save  = $has ? ($list - $sale) : 0;
    $pct   = ($has && $list > 0) ? (int)round(100 * $save / $list) : 0;
    return ['list'=>$list, 'final'=>$final, 'has_sale'=>$has, 'percent'=>$pct, 'save'=>$save];
}

/** قالب پولی: 150000 → «۱۵۰٬۰۰۰ تومان» */
function toman(int $n): string
{
    // جداکنندهٔ هزارگان فارسی «٬»
    return fa_num(str_replace(',', '٬', number_format($n))) . ' ' . (DB::setting('currency_label', 'تومان'));
}

/* ---------------------------------------------------------------------------
 | تقویم شمسی (الگوریتم رسمی خیام — دقیق تا سال ۳۷۹۷)
 * -------------------------------------------------------------------------*/
/** میلادی → جلالی */
function g2j(int $gy, int $gm, int $gd): array
{
    $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2  = ($gm > 2) ? $gy + 1 : $gy;
    $days = 355666 + (365*$gy) + intdiv($gy2+3,4) - intdiv($gy2+99,100)
          + intdiv($gy2+399,400) + $gd + $gdm[$gm-1];
    $jy = -1595 + 33*intdiv($days,12053);
    $days %= 12053;
    $jy += 4*intdiv($days,1461);
    $days %= 1461;
    if ($days > 365) { $jy += intdiv($days-1,365); $days = ($days-1)%365; }
    if ($days < 186) { $jm = 1 + intdiv($days,31); $jd = 1 + ($days%31); }
    else             { $jm = 7 + intdiv($days-186,30); $jd = 1 + (($days-186)%30); }
    return [$jy, $jm, $jd];
}
/** جلالی → میلادی */
function j2g(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365*$jy) + intdiv($jy,33)*8 + intdiv(($jy%33)+3,4)
          + $jd + (($jm < 7) ? ($jm-1)*31 : (($jm-7)*30)+186);
    $gy = 400*intdiv($days,146097);
    $days %= 146097;
    if ($days > 36524) { $gy += 100*intdiv(--$days,36524); $days %= 36524; if ($days >= 365) $days++; }
    $gy += 4*intdiv($days,1461);
    $days %= 1461;
    if ($days > 365) { $gy += intdiv($days-1,365); $days = ($days-1)%365; }
    $gd = $days + 1;
    $sal_a = [0,31,(($gy%4==0&&$gy%100!=0)||($gy%400==0))?29:28,31,30,31,30,31,31,30,31,30,31];
    for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) { $gd -= $sal_a[$gm]; }
    return [$gy, $gm, $gd];
}

const FR_JMONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

/** خروجی تاریخ شمسی آمادهٔ نمایش: «پنجشنبه ۱۶ مرداد ۱۴۰۵» */
function jdate(string $format = 'j F Y', $ts = null): string
{
    $ts     = $ts ?? time();
    [$jy,$jm,$jd] = g2j((int)date('Y',$ts),(int)date('m',$ts),(int)date('d',$ts));
    $week   = ['یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه','شنبه'][(int)date('w',$ts)];
    $weekD  = ['ی','د','س','چ','پ','ج','ش'][(int)date('w',$ts)]; // نام کوتاه روز هفته (۲٫۲)
    $out = strtr($format, [
        'Y'=>$jy,'m'=>str_pad((string)$jm,2,'0',STR_PAD_LEFT),'d'=>str_pad((string)$jd,2,'0',STR_PAD_LEFT),
        'j'=>$jd,'n'=>$jm,'F'=>FR_JMONTHS[$jm-1],'l'=>$week,'D'=>$weekD,'H'=>date('H',$ts),'i'=>date('i',$ts),
    ]);
    return fa_num($out);
}

/** ورودی شمسی «۱۳۷۰/۰۵/۱۶» → تاریخ میلادی برای ذخیره در DB */
function jalali_to_mysql(string $j): ?string
{
    $j = str_replace(['-','.'], '/', en_num(trim($j)));
    if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $j, $m)) return null;
    [$gy,$gm,$gd] = j2g((int)$m[1],(int)$m[2],(int)$m[3]);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** ورودی ساعت کاربر (فارسی/انگلیسی/با یا بدون ثانیه) → «HH:MM» امن برای MySQL؛ در غیر این‌صورت مقدار پیش‌فرض */
function fr_valid_time(string $t, string $fallback = '10:00'): string
{
    $t = en_num(trim($t));
    if (!preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $t, $m)) return $fallback;
    $h = (int)$m[1]; $i = (int)$m[2];
    if ($h > 23 || $i > 59) return $fallback;
    return sprintf('%02d:%02d', $h, $i);
}

/* ---------------------------------------------------------------------------
 | اعتبارسنجی‌های ایرانی
 * -------------------------------------------------------------------------*/
/** صحت کد ملی ایران */
function valid_national_code(string $code): bool
{
    $code = en_num($code);
    if (!preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) return false;
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += (int)$code[$i] * (10-$i);
    $r = $sum % 11;
    $c = (int)$code[9];
    return ($r < 2 && $c === $r) || ($r >= 2 && $c === 11-$r);
}
/** پاک‌سازی و استانداردسازی شماره موبایل ایران (تمامی اپراتورها: همراه اول، ایرانسل، رایتل، آپتل، سامان‌تل، شاتل و...) */
function clean_mobile(string $m): string
{
    $m = en_num(trim($m));
    // حذف هرگونه کاراکتر غیر از ارقام و علامت +
    $m = (string)preg_replace('/[^\d\+]/', '', $m);
    if (str_starts_with($m, '+98')) {
        $m = substr($m, 3);
    } elseif (str_starts_with($m, '0098')) {
        $m = substr($m, 4);
    } elseif (str_starts_with($m, '98') && strlen($m) >= 12) {
        $m = substr($m, 2);
    }
    // حذف صفر یا صفرهای اضافی پس از پیش‌شماره بین‌المللی
    $m = ltrim($m, '0');
    // شماره استاندارد ایران پس از حذف صفر، ۱۰ رقم با ۹ شروع می‌شود
    if (str_starts_with($m, '9') && strlen($m) === 10) {
        $m = '0' . $m;
    } elseif (str_starts_with($m, '09') && strlen($m) === 11) {
        // در صورت وجود، دست‌نخورده باقی بماند
    }
    return $m;
}

/** صحت موبایل ایران (پشتیبانی کامل از تمامی سرشماره‌های ۱۱ رقمی 09xxxxxxxxx) */
function valid_mobile(string $m): bool
{
    return (bool)preg_match('/^09\d{9}$/', clean_mobile($m));
}

/**
 * تشخیص نام و نوع اپراتور تلفن همراه بر اساس سرشماره
 * پشتیبانی از: همراه اول، ایرانسل، رایتل، آپتل، سامان‌تل، شاتل موبایل، تالیا، تله‌کیش، لوتوس‌تل و سایر MVNOها
 * @return array{valid:bool, name:string, slug:string, type:string, prefix:string}
 */
function fr_mobile_operator(string $m): array
{
    $m = clean_mobile($m);
    if (!valid_mobile($m)) {
        return ['valid' => false, 'name' => 'نامعتبر', 'slug' => 'invalid', 'type' => 'unknown', 'prefix' => ''];
    }

    $pre3 = substr($m, 0, 4); // 09xx
    $pre4 = substr($m, 0, 5); // 09xxx
    $pre5 = substr($m, 0, 6); // 09xxxx

    // ۱. آپتل (Aptel - نگین ارتباطات همراه / نقش اول کیفیت)
    if ($pre4 === '09991') {
        return ['valid' => true, 'name' => 'آپتل (Aptel)', 'slug' => 'aptel', 'type' => 'mvno', 'prefix' => $pre4];
    }

    // ۲. سامان‌تل (SamanTel - کیش سل پارس)
    if (in_array($pre4, ['09999', '09996'], true)) {
        return ['valid' => true, 'name' => 'سامان‌تل (SamanTel)', 'slug' => 'samantel', 'type' => 'mvno', 'prefix' => $pre4];
    }

    // ۳. شاتل موبایل (Shatel Mobile)
    if ($pre3 === '0998') {
        return ['valid' => true, 'name' => 'شاتل موبایل (Shatel Mobile)', 'slug' => 'shatel', 'type' => 'mvno', 'prefix' => $pre3];
    }

    // ۴. لوتوس تل (LotusTel - پارسیان همراه لوتوس)
    if ($pre4 === '09990') {
        return ['valid' => true, 'name' => 'لوتوس‌تل (LotusTel)', 'slug' => 'lotustel', 'type' => 'mvno', 'prefix' => $pre4];
    }

    // ۵. آرین‌تل (ArianTel)
    if ($pre4 === '09998') {
        return ['valid' => true, 'name' => 'آرین‌تل (ArianTel)', 'slug' => 'ariantel', 'type' => 'mvno', 'prefix' => $pre4];
    }

    // ۶. فناپ موبایل (Fanap Mobile / پادین)
    if ($pre4 === '09997') {
        return ['valid' => true, 'name' => 'فناپ موبایل (Fanap)', 'slug' => 'fanap', 'type' => 'mvno', 'prefix' => $pre4];
    }

    // ۷. تالیا (Taliya)
    if ($pre3 === '0932') {
        return ['valid' => true, 'name' => 'تالیا (Taliya)', 'slug' => 'taliya', 'type' => 'mno', 'prefix' => $pre3];
    }

    // ۸. تله‌کیش (TeleKish)
    if ($pre3 === '0934') {
        return ['valid' => true, 'name' => 'تله‌کیش (TeleKish)', 'slug' => 'telekish', 'type' => 'mno', 'prefix' => $pre3];
    }

    // ۹. اسپادان (MTCE)
    if ($pre3 === '0931') {
        return ['valid' => true, 'name' => 'اسپادان (MTCE)', 'slug' => 'mtce', 'type' => 'mno', 'prefix' => $pre3];
    }

    // ۱۰. رایتل (Rightel)
    if (in_array($pre3, ['0920', '0921', '0922', '0923'], true)) {
        return ['valid' => true, 'name' => 'رایتل (Rightel)', 'slug' => 'rightel', 'type' => 'mno', 'prefix' => $pre3];
    }

    // ۱۱. ایرانسل (MTN Irancell)
    if (in_array($pre3, ['0930', '0933', '0935', '0936', '0937', '0938', '0939', '0900', '0901', '0902', '0903', '0904', '0905', '0941'], true)) {
        return ['valid' => true, 'name' => 'ایرانسل (Irancell)', 'slug' => 'irancell', 'type' => 'mno', 'prefix' => $pre3];
    }

    // ۱۲. همراه اول (MCI - ارتباطات سیار ایران)
    if (in_array($pre3, ['0910', '0911', '0912', '0913', '0914', '0915', '0916', '0917', '0918', '0919', '0990', '0991', '0992', '0993', '0994', '0996'], true)) {
        $extra = ($pre3 === '0994') ? ' (سیم‌کارت انارستان)' : '';
        return ['valid' => true, 'name' => 'همراه اول (MCI)' . $extra, 'slug' => 'mci', 'type' => 'mno', 'prefix' => $pre3];
    }

    return ['valid' => true, 'name' => 'اپراتور همراه ایران', 'slug' => 'other_iran', 'type' => 'mno', 'prefix' => $pre3];
}

/* ---------------------------------------------------------------------------
 | تشخیص دستگاه + IP
 * -------------------------------------------------------------------------*/
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function client_ip_bin(): string
{
    return (string)@inet_pton(client_ip());
}
function client_device(): string
{
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return match (true) {
        str_contains($ua,'mobile') || str_contains($ua,'android') => 'موبایل',
        str_contains($ua,'tablet') || str_contains($ua,'ipad')    => 'تبلت',
        $ua === ''                                               => 'نامشخص',
        default                                                  => 'رایانه',
    };
}

/* ---------------------------------------------------------------------------
 | رمزنگاری متقارن (برای داده‌های حساس مثل API key ها در آینده)
 * -------------------------------------------------------------------------*/
function fr_encrypt(string $plain): string
{
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', FR_CRYPT_KEY, true), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $enc);
}
function fr_decrypt(string $blob): ?string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 33) return null;
    [$iv,$tag,$enc] = [substr($raw,0,16), substr($raw,16,16), substr($raw,32)];
    $dec = openssl_decrypt($enc, 'aes-256-gcm', hash('sha256', FR_CRYPT_KEY, true), OPENSSL_RAW_DATA, $iv, $tag);
    return $dec === false ? null : $dec;
}

/* ---------------------------------------------------------------------------
 | متغیرگذاری متن (پترن پیامک و قالب‌ها)
 |  جایگذاری %name% و ... با امنیت کامل
 * -------------------------------------------------------------------------*/
function tpl_vars(string $tpl, array $vars): string
{
    foreach ($vars as $k => $v) {
        if (is_array($v)) {
            $v = implode('، ', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v));
        } elseif (is_object($v)) {
            $v = method_exists($v, '__toString') ? (string)$v : '';
        }
        $tpl = str_replace('%' . $k . '%', (string)$v, $tpl);
    }
    return $tpl;
}

/** تولید شناسهٔ یکتای عمومی (برای کد رهگیری گزارش) */
function fr_token(int $len = 12): string
{
    return strtoupper(bin2hex(random_bytes((int)ceil($len/2))));
}

/** اعتبارسنجی نشانی بازگشت (back) — فقط مسیر داخلی سایت؛ در غیر اینصورت رشتهٔ خالی */
/** تبدیل نشانی نسبی یا ناقص به نشانی کامل با توجه به SITE_URL */
/** دریافت عنوان سفارشی‌شدهٔ پنل کاربری */
function fr_panel_title(): string
{
    $title = trim((string)DB::setting('panel_title', ''));
    if ($title !== '') return $title;
    return function_exists('T') ? T('nav.panel') : 'پنل کاربری';
}

/** دریافت اسلاگ تمیز پنل کاربری (پیش‌فرض: panel) */
function fr_panel_slug(): string
{
    $raw = trim((string)DB::setting('panel_url', '/panel/'));
    if ($raw === '') return 'panel';
    if (preg_match('#^https?://#i', $raw)) {
        $path = (string)parse_url($raw, PHP_URL_PATH);
    } else {
        $path = $raw;
    }
    $path = trim($path, '/');
    if (str_ends_with($path, '.php')) {
        $path = dirname($path);
        if ($path === '.') $path = '';
    }
    $slug = trim($path, '/');
    if ($slug !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
        return strtolower($slug);
    }
    return 'panel';
}

/** دریافت نشانی سفارشی‌شدهٔ پنل کاربری با پشتیبانی از زیرمسیر */
function fr_panel_url(?string $subpath = null): string
{
    $slug = fr_panel_slug();
    $base = rtrim(SITE_URL, '/') . '/' . $slug . '/';
    if ($subpath === null || $subpath === '' || $subpath === '/') {
        return $base;
    }
    if (str_starts_with($subpath, '?')) {
        return rtrim($base, '/') . '/' . $subpath;
    }
    return $base . ltrim($subpath, '/');
}

function fr_format_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '' || $url === '#') return '#';
    if (preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
        return $url;
    }
    return rtrim(SITE_URL, '/') . '/' . ltrim($url, '/');
}

function fr_safe_back(?string $back): string
{
    $back = trim((string)$back);
    if ($back === '' || strlen($back) > 300) return '';
    // جلوگیری از تزریق کنترل‌کاراکتر در هدر Location
    if (preg_match('/[\x00-\x1F\x7F]/', $back)) return '';
    if (str_starts_with($back, '//')) return '';                         // protocol-relative خارجی
    if (preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $back, $m)) {         // آدرس مطلق؟ فقط http(s) خودمان
        $base = parse_url(SITE_URL, PHP_URL_HOST) ?: '';
        $host = parse_url($back, PHP_URL_HOST) ?: '';
        if (!in_array(strtolower($m[1]), ['http', 'https'], true)) return ''; // اسکیمای خطرناک مثل javascript:
        return ($base !== '' && strcasecmp($host, $base) === 0) ? $back : '';
    }
    return str_starts_with($back, '/') ? $back : '';
}

/** نام کامل کاربر از ردیف کاربر */
function user_fullname(array $u): string
{
    return trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر گرامی';
}

/**
 * شکستن «نام و نام خانوادگی» تک‌فیلدی به دو بخش ذخیرهٔ دیتابیس — نسخهٔ ۱٫۷
 * قانون: اولین واژه ← نام؛ باقی واژه‌ها ← نام خانوادگی (که می‌تواند چندبخشی باشد).
 * @return array{0:string,1:string} [first_name, last_name]
 */
function fr_split_fullname(string $full): array
{
    $full = trim(preg_replace('/\s+/u', ' ', $full));
    if ($full === '') return ['', ''];
    $parts = explode(' ', $full, 2);
    return [mb_substr($parts[0], 0, 100), mb_substr((string)($parts[1] ?? ''), 0, 100)];
}

/* ---------------------------------------------------------------------------
 | خط اعتباری پانوشت/گزارش‌ها — نسخهٔ ۱٫۶: از تنظیمات خوانده می‌شود و مدیر
 | می‌تواند متن و لینک آن را ویرایش کند (تب «برند و ظاهر» در تنظیمات)
 * -------------------------------------------------------------------------*/
function fr_credit_text(): string
{
    $t = trim((string)DB::setting('footer_credit_text', ''));
    return $t !== '' ? $t : 'طراحی و کد نویسی : احسان نادری پناه – 09024561001 | فریا';
}

/** لینک اعتبار — فقط http(s) معتبر؛ مقدار خالی/نامعتبر یعنی بدون لینک */
function fr_credit_url(): string
{
    $u = trim((string)DB::setting('footer_credit_url', 'https://feria.ir'));
    if ($u === '') return '';
    if (!preg_match('#^https?://#i', $u)) return '';
    return $u;
}

/** رندر امن خط اعتباری (متن کاملاً خروجی‌گرفته می‌شود؛ لینک اختیاری به کل متن ضمیمه است) */
function fr_credit_html(bool $linked = true): string
{
    $txt = fr_credit_text();
    $url = $linked ? fr_credit_url() : '';
    if ($url !== '') {
        return '<a href="' . e($url) . '" target="_blank" rel="noopener" style="color:inherit;font-weight:700;text-decoration:none;border-bottom:1px dashed currentColor">'
             . e($txt) . '</a>';
    }
    return '<span>' . e($txt) . '</span>';
}

/* ---------------------------------------------------------------------------
 | لوگوی سایت — نسخهٔ ۱٫۷: اگر لوگو آپلود شده، <img> با alt قابل‌ویرایش برمی‌گرداند
 |  وگرنه نشانهٔ ایموجی پیش‌فرض نمایش داده می‌شود.
 * -------------------------------------------------------------------------*/

/** آدرس لوگو (خالی = بدون لوگو) */
function fr_logo_url(): string
{
    $url = trim((string)DB::setting('logo', ''));
    if ($url === '') return '';
    return str_starts_with($url, 'http') ? $url : SITE_URL . '/' . ltrim($url, '/');
}

/** متن alt لوگو — قابل ویرایش در تنظیمات (تب برند) */
function fr_logo_alt(): string
{
    $alt = trim((string)DB::setting('logo_alt', ''));
    return $alt !== '' ? $alt : (string)DB::setting('site_title', 'فریا روان');
}

/**
 * ذخیرهٔ امن پیوستِ تصویری تیکت (۱٫۷) — فقط تصویر، حداکثر ۲ مگابایت
 * @return array ['ok'=>bool,'path'=>?string,'error'=>?string]  path نسبی یا null اگر فایلی نبود
 */
function fr_ticket_attach_save(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok'=>true,'path'=>null,'error'=>null];      // پیوست اختیاری است
    }
    require_once __DIR__ . '/upload.php';
    $r = fr_save_image($file, 'tickets', 'tick', (int)($_SESSION['user_id'] ?? 0));
    return $r;
}

/** رندر خروجی امن پیوست تیکت — تصویر با لینک بزرگ‌نمایی */
function fr_ticket_attach_html(?string $path): string
{
    $p = trim((string)$path, " /\\");
    if ($p === '') return '';
    if (!str_starts_with($p, 'feria-uploads/tickets/') && !str_starts_with($p, 'assets/uploads/tickets/')) return '';
    $url = SITE_URL . '/' . ltrim($p, '/');
    return '<a href="' . e($url) . '" target="_blank" rel="noopener" title="بزرگ‌نمایی تصویر">'
         . '<img src="' . e($url) . '" alt="پیوست تصویری تیکت" loading="lazy" '
         . 'style="max-width:230px;max-height:170px;border-radius:12px;border:1.5px solid rgba(30,58,110,.25);display:block;margin-top:8px;cursor:zoom-in"></a>';
}

/** تبدیل مسیر نسبی دیتابیس به آدرس URL کامل تصویر (با پشتیبانی کامل از feria-uploads) */
function fr_img_url(?string $path, string $fallback = ''): string
{
    $p = trim((string)$path);
    if ($p === '') return $fallback;
    if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://') || str_starts_with($p, 'data:')) return $p;
    return SITE_URL . '/' . ltrim($p, '/');
}

/**
 * رندر لوگو — اگر فایل آپلود شده باشد تصویر واقعی با alt می‌آید، وگرنه ایموجی
 * @param string $cls  کلاس CSS نشانه (برای سایز از بیرون کنترل می‌شود)
 */
function fr_logo_html(string $cls = 'pb-brand-mark'): string
{
    $url = fr_logo_url();
    if ($url !== '') {
        return '<img class="' . e($cls) . ' fr-logo" src="' . e($url) . '" alt="' . e(fr_logo_alt()) . '" loading="lazy" decoding="async">';
    }
    return '<span class="' . e($cls) . '" aria-hidden="true">🧠</span>';
}


/* ---------------------------------------------------------------------------
 | موتور تست روانشناسی — شمارش پاسخ‌های «کامل» و فهرست سؤال‌های بی‌پاسخ (نسخهٔ ۱٫۶)
 | سؤال لیکرت با مقدار، و سؤال چهاروصفی DISC فقط با هر دو «بیشترین/کمترین» کامل است
 * -------------------------------------------------------------------------*/
function fr_attempt_answered(int $attemptId): int
{
    return (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('answers') . ' WHERE attempt_id=?
                         AND (value IS NOT NULL OR (most_idx IS NOT NULL AND least_idx IS NOT NULL))',
                        [$attemptId]);
}

/** شمارهٔ سؤال‌های بی‌پاسخ (به‌ترتیب) — برای هدایت هوشمند کاربر در پایان تست روانشناسی */
function fr_attempt_missing(int $attemptId, int $testId): array
{
    $rows = DB::all('SELECT q.number FROM ' . DB::t('questions') . ' q
                     LEFT JOIN ' . DB::t('answers') . ' a ON a.question_id=q.id AND a.attempt_id=?
                     WHERE q.test_id=? AND q.active=1
                       AND NOT (a.value IS NOT NULL OR (a.most_idx IS NOT NULL AND a.least_idx IS NOT NULL))
                     ORDER BY q.number', [$attemptId, $testId]);
    return array_map('intval', array_column($rows, 'number'));
}

/* ---------------------------------------------------------------------------
 | نسخهٔ ۱٫۸ — آیکن و برچسب نوع هر تست روانشناسی (اسلاگ‌محور با فال‌بک موتور)
 | در همهٔ لایه‌ها (عمومی/پنل/ادمین) قابل استفاده است
 * -------------------------------------------------------------------------*/
function fr_test_icon(array $t): string
{
    $bySlug = [
        'gardner-mi' => '🧠',     // گاردنر ۹ هوش
        'eq-shutte'  => '💗',     // هوش هیجانی شوت
    ];
    $s = (string)($t['slug'] ?? '');
    if (isset($bySlug[$s])) return $bySlug[$s];
    return [
        'disc'     => '🎭',
        'ysq'      => '🧩',
        'neo'      => '🌟',
        'screen'   => '🩺',
        'jobsat'   => '💼',
        'mslikert' => '🧠',
    ][(string)($t['engine'] ?? '')] ?? '🧠';
}

/** برچسب کوتاهِ نوع تست روانشناسی — برای کارت حرفه‌ای (۱٫۸) */
function fr_test_kind_fa(array $t): string
{
    $bySlug = [
        'gardner-mi' => 'نقشهٔ هوش‌های چندگانه',
        'eq-shutte'  => 'سنجش هوش هیجانی',
    ];
    $s = (string)($t['slug'] ?? '');
    if (isset($bySlug[$s])) return $bySlug[$s];
    return [
        'disc'   => 'تحلیل سبک‌های رفتاری',
        'ysq'    => 'واکاوی عمیق طرحواره‌ها',
        'neo'    => 'نقشهٔ پنج عامل شخصیت',
        'screen' => 'غربالگری آگاهانه',
        'jobsat' => 'سنجش سازمانی', 'mbti' => 'تیپ‌شناسی ۱۶گانه مایرز-بریگز', 'holland' => 'رغبت‌سنجی شغلی و تحصیلی', 'baron' => 'ارزیابی هوش هیجانی بار-آن', 'mmpi' => 'غربالگری آسیب‌شناسی بالینی', 'raven' => 'سنجش هوش عمومی و سیال', 'wais' => 'شاخص هوش شناختی وکسلر',
    ][(string)($t['engine'] ?? '')] ?? 'تست روانشناسی';
}

/**
 * پاکسازی کامل و حذف نهایی یک تست روانشناسی و تمام داده‌های وابستهٔ آن
 */

function fr_test_category_fa(string $cat): string
{
    return match ($cat) {
        'clinical'     => 'بالینی و سلامت روان',
        'personality'  => 'شخصیت و خودشناسی',
        'intelligence' => 'هوش، استعداد و پیشرفت شغلی',
        default        => 'شخصیت و خودشناسی',
    };
}

function fr_purge_test(int $tid): bool
{
    $row = DB::one('SELECT id, title FROM ' . DB::t('tests') . ' WHERE id=?', [$tid]);
    if (!$row) return false;

    DB::pdo()->beginTransaction();
    try {
        $attempts = DB::all('SELECT id FROM ' . DB::t('attempts') . ' WHERE test_id=?', [$tid]);
        if ($attempts) {
            $aids = array_map('intval', array_column($attempts, 'id'));
            $inClause = implode(',', $aids);
            try { DB::run('DELETE FROM ' . DB::t('clinical_notes') . " WHERE attempt_id IN ($inClause)"); } catch (Throwable $e) {}
            try { DB::run('DELETE FROM ' . DB::t('answers') . " WHERE attempt_id IN ($inClause)"); } catch (Throwable $e) {}
            try { DB::run('DELETE FROM ' . DB::t('results') . " WHERE attempt_id IN ($inClause)"); } catch (Throwable $e) {}
            try { DB::run('DELETE FROM ' . DB::t('reports') . " WHERE attempt_id IN ($inClause)"); } catch (Throwable $e) {}
            try { DB::run('UPDATE ' . DB::t('planner_tasks') . " SET ref_attempt_id=NULL WHERE ref_attempt_id IN ($inClause)"); } catch (Throwable $e) {}
            try { DB::run('DELETE FROM ' . DB::t('dyads') . " WHERE test_id=? OR attempt1_id IN ($inClause) OR attempt2_id IN ($inClause)", [$tid]); } catch (Throwable $e) {}
            DB::run('DELETE FROM ' . DB::t('attempts') . ' WHERE test_id=?', [$tid]);
        }
        try { DB::run('UPDATE ' . DB::t('orders') . ' SET test_id=NULL WHERE test_id=?', [$tid]); } catch (Throwable $e) {}
        try { DB::run('UPDATE ' . DB::t('discount_codes') . ' SET test_id=NULL WHERE test_id=?', [$tid]); } catch (Throwable $e) {}
        try { DB::run('DELETE FROM ' . DB::t('analysis_templates') . ' WHERE test_id=?', [$tid]); } catch (Throwable $e) {}
        try { DB::run('DELETE FROM ' . DB::t('scoring_keys') . ' WHERE test_id=?', [$tid]); } catch (Throwable $e) {}
        try { DB::run('DELETE FROM ' . DB::t('questions') . ' WHERE test_id=?', [$tid]); } catch (Throwable $e) {}
        try { DB::run('DELETE FROM ' . DB::t('test_scales') . ' WHERE test_id=?', [$tid]); } catch (Throwable $e) {}
        DB::run('DELETE FROM ' . DB::t('tests') . ' WHERE id=?', [$tid]);
        DB::pdo()->commit();
        log_activity('test_delete', 'test', $tid, ['title' => $row['title']]);
        return true;
    } catch (Throwable $e) {
        if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
        return false;
    }
}
