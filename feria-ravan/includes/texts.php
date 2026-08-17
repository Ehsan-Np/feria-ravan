<?php
/**
 * ============================================================================
 *  فرهنگ متن‌های سایت (نسخهٔ ۲٫۱ — ری‌برندینگ کامل)
 *  ─ T($key, $default): متن را از جدول fr_texts می‌خواند؛ اگر مدیر متن سفارشی
 *    ثبت کرده باشد همان نمایش داده می‌شود، در غیر این صورت متن پیش‌فرض کد.
 *  ─ ویرایش همهٔ متن‌ها از پیشخوان ← «✍️ متن‌های سایت» (admin/texts.php)
 *  ─ طراحی: پیش‌فرض‌ها در رجیستری همین فایل زندگی می‌کنند؛ در دیتابیس فقط
 *    «بازنویسی‌ها» ذخیره می‌شوند → نصب جدید بزرگ نمی‌شود و ارتقا امن است.
 * ============================================================================
 */
declare(strict_types=1);

/* ---------------------------------------------------------------------------
 | رجیستری متن‌ها: key => [پیش‌فرض, بخش]
 * -------------------------------------------------------------------------*/
function fr_texts_registry(): array
{
    static $reg = null;
    if ($reg !== null) return $reg;
    $reg = [
        /* ── ناوبری عمومی ── */
        'nav.home'        => ['خانه', 'ناوبری'],
        'nav.tests'       => ['تست‌های روانشناسی', 'ناوبری'],
        'nav.workshops'   => ['کارگاه‌ها', 'ناوبری'],
        'nav.courses'     => ['دوره‌ها', 'ناوبری'],
        'nav.consult'     => ['رزرو مشاوره', 'ناوبری'],
        'nav.login'       => ['ورود | ثبت‌نام', 'ناوبری'],
        'nav.panel'       => ['پنل کاربری', 'ناوبری'],
        'nav.logout'      => ['خروج', 'ناوبری'],
        'nav.menu'        => ['منو', 'ناوبری'],

        /* ── فوتر ── */
        'footer.links_title'   => ['دسترسی سریع', 'فوتر'],
        'footer.support_title' => ['خدمات کاربران', 'فوتر'],
        'footer.badges_title'  => ['نمادها و اعتماد', 'فوتر'],
        'footer.about_title'   => ['دربارهٔ ما', 'فوتر'],
        'footer.newsletter_title' => ['خبرنامهٔ پیامکی', 'فوتر'],
        'footer.newsletter_desc'  => ['از دوره‌ها، کارگاه‌ها و تست‌های روانشناسی جدید زودتر از همه باخبر شوید.', 'فوتر'],
        'footer.newsletter_btn'   => ['عضویت در خبرنامه', 'فوتر'],
        'footer.newsletter_ok'    => ['عضویت شما در خبرنامه ثبت شد؛ خوش آمدید! 🦢', 'فوتر'],
        'footer.copy'             => ['تمامی حقوق این وب‌سایت محفوظ است.', 'فوتر'],

        /* ── صفحهٔ ورود ── */
        'auth.title'         => ['به فریا خوش آمدید', 'ورود و عضویت'],
        'auth.subtitle'      => ['برای شروع تست‌های روانشناسی، ثبت‌نام در کارگاه‌ها و رزرو مشاوره وارد حسابتان شوید یا در چند ثانیه حساب بسازید.', 'ورود و عضویت'],
        'auth.otp_tab'       => ['ورود با پیامک', 'ورود و عضویت'],
        'auth.pwd_tab'       => ['ورود با رمز', 'ورود و عضویت'],
        'auth.mobile_label'  => ['شماره موبایل', 'ورود و عضویت'],
        'auth.send_code'     => ['ارسال کد تأیید 📩', 'ورود و عضویت'],
        'auth.name_label'    => ['نام و نام خانوادگی', 'ورود و عضویت'],
        'auth.register_btn'  => ['ساخت حساب و ورود 🦢', 'ورود و عضویت'],

        /* ── خانه ── */
        'home.tests_title'     => ['تست‌های روانشناسی', 'خانه'],
        'home.workshops_title' => ['کارگاه‌های آموزشی', 'خانه'],
        'home.courses_title'   => ['دوره‌های آموزشی', 'خانه'],
        'home.all'             => ['همهٔ موارد ←', 'خانه'],

        /* ── صفحات آرشیو ── */
        'archive.all'         => ['مشاهدهٔ همه', 'آرشیوها'],
        'archive.empty'       => ['فعلاً موردی فعال نیست؛ به‌زودی موارد جدید اعلام می‌شود.', 'آرشیوها'],

        /* ── کارت‌ها ── */
        'card.test_cta'      => ['مشاهدهٔ تست روانشناسی ←', 'کارت‌ها'],
        'card.event_cta'     => ['جزئیات و ثبت‌نام ←', 'کارت‌ها'],
        'card.free'          => ['🎁 کاملاً رایگان', 'کارت‌ها'],

        /* ── پنل کاربری (منوی کناری) ── */
        'panel.dashboard'  => ['داشبورد', 'پنل کاربری'],
        'panel.tests'      => ['تست‌های روانشناسی', 'پنل کاربری'],
        'panel.events'     => ['کارگاه‌ها و دوره‌ها', 'پنل کاربری'],
        'panel.consult'    => ['نوبت‌های مشاوره', 'پنل کاربری'],
        'panel.wallet'     => ['کیف پول و عودت وجه', 'پنل کاربری'],
        'panel.history'    => ['تاریخچه تست‌های روانشناسی', 'پنل کاربری'],
        'panel.payments'   => ['تراکنش‌ها', 'پنل کاربری'],
        'panel.profile'    => ['پروفایل', 'پنل کاربری'],
        'panel.tickets'    => ['تیکت پشتیبانی', 'پنل کاربری'],
        'panel.planner'    => ['برنامه‌ریزی روزانه', 'پنل کاربری'],
        'panel.logout'     => ['خروج', 'پنل کاربری'],

        /* ── داشبورد ── */
        'dash.hello'        => ['سلام، %name% عزیز 👋', 'داشبورد'],
        'dash.done'         => ['تست‌های روانشناسی تکمیل‌شده', 'داشبورد'],
        'dash.pending'      => ['تست‌های روانشناسی ناتمام', 'داشبورد'],
        'dash.events'       => ['ثبت‌نام رویدادها', 'داشبورد'],
        'dash.payments'     => ['پرداخت‌های موفق', 'داشبورد'],
        'dash.recent_tests' => ['تست‌های روانشناسی اخیر شما', 'داشبورد'],
        'dash.my_events'    => ['کارگاه‌ها و دوره‌های من', 'داشبورد'],
        'dash.tests_avail'  => ['تست‌های روانشناسی موجود', 'داشبورد'],
        'dash.start_test'   => ['🧠 شروع تست روانشناسی جدید', 'داشبورد'],

        /* ── برنامه‌ریزی روزانه ── */
        'planner.title'       => ['برنامه‌ریزی روزانه', 'برنامه‌ریزی روزانه'],
        'planner.today'       => ['برنامهٔ امروز من', 'برنامه‌ریزی روزانه'],
        'planner.add'         => ['افزودن به برنامهٔ امروز ✚', 'برنامه‌ریزی روزانه'],
        'planner.empty'       => ['برنامهٔ امروزت خالی است؛ با افزودن اولین هدف، مسیر رشدت را شروع کن 🦢', 'برنامه‌ریزی روزانه'],
        'planner.streak'      => ['روزهای متوالی موفق', 'برنامه‌ریزی روزانه'],
        'planner.done_all'    => ['همهٔ برنامه‌های امروزت انجام شد؛ آفرین! 🌟', 'برنامه‌ریزی روزانه'],

        /* ── مشترک ── */
        'common.submit'   => ['ثبت ✅', 'مشترک'],
        'common.cancel'   => ['انصراف', 'مشترک'],
        'common.view_all' => ['مشاهده همه ←', 'مشترک'],
        'common.back'     => ['→ بازگشت', 'مشترک'],
    ];
    /* ادغام کلیدهای سفارشیِ مدیر (ذخیره در includes/texts-custom.php) */
    $fn = __DIR__ . '/texts-custom.php';
    if (file_exists($fn)) {
        $extra = include $fn;
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                if (is_array($v) && isset($v[0], $v[1])) $reg[$k] = [$v[0], $v[1]];
            }
        }
    }
    return $reg;
}

/* ---------------------------------------------------------------------------
 | خواندن یک متن — با کش درون‌درخواستی همهٔ بازنویسی‌ها
 * -------------------------------------------------------------------------*/
function T(string $key, string $default = ''): string
{
    static $over = null;
    if ($over === null) {
        $over = [];
        try {
            foreach (DB::all('SELECT k, txt FROM ' . DB::t('texts')) as $r) {
                $over[(string)$r['k']] = (string)$r['txt'];
            }
        } catch (Throwable $e) { /* جدول هنوز نیست */ }
    }
    if (isset($over[$key]) && trim($over[$key]) !== '') return $over[$key];
    if ($default !== '') return $default;
    $reg = fr_texts_registry();
    return $reg[$key][0] ?? $key;
}

/** بازنشانی کش (پس از ذخیره در همان ریکوئست) */
function fr_texts_flush(): void { /* کشِ T() درون‌درخواستی است؛ صفحهٔ بعد تازه می‌شود */ }

/** فهرست کامل برای ویرایشگر: کلید ← [پیش‌فرض, بخش, متن‌جاری, سفارشی؟] */
function fr_texts_list(): array
{
    $reg = fr_texts_registry();
    $over = [];
    try {
        foreach (DB::all('SELECT k, txt FROM ' . DB::t('texts')) as $r) $over[$r['k']] = (string)$r['txt'];
    } catch (Throwable $e) {}
    $out = [];
    foreach ($reg as $k => [$def, $sec]) {
        $out[$k] = [
            'default' => $def, 'section' => $sec,
            'current' => (isset($over[$k]) && trim($over[$k]) !== '') ? $over[$k] : $def,
            'custom'  => isset($over[$k]) && trim($over[$k]) !== '',
        ];
    }
    return $out;
}

/** افزودن یک کلید دلخواه جدید به رجیستری (از ویرایشگر) */
function fr_texts_add_key(string $key, string $default, string $section = 'سفارشی'): bool
{
    $key = preg_replace('/[^a-z0-9_.-]/', '', strtolower(trim($key)));
    if ($key === '' || strlen($key) > 100) return false;
    $regFn = __DIR__ . '/texts-custom.php';
    $extra = file_exists($regFn) ? (include $regFn) : [];
    if (!is_array($extra)) $extra = [];
    if (!isset($extra[$key]) && !isset(fr_texts_registry()[$key])) {
        $extra[$key] = [$default, $section];
        file_put_contents($regFn, "<?php\n// کلیدهای متن سفارشیِ مدیر (۲٫۱) — از admin/texts.php مدیریت می‌شود\nreturn " . var_export($extra, true) . ";\n");
    }
    if ($default !== '') {
        try { DB::setSetting('text_custom_added', '1'); } catch (Throwable $e) {}
        try {
            DB::run('INSERT INTO ' . DB::t('texts') . ' (k, txt) VALUES (?,?) ON DUPLICATE KEY UPDATE txt=VALUES(txt)',
                [$key, $default]);
        } catch (Throwable $e) { return false; }
    }
    return true;
}

/** بذر اولیهٔ فرهنگ (خنثی — فقط نشانه‌گذاری که فاز اجرا شده) */
function fr_texts_seed(): void
{
    try {
        DB::run('INSERT IGNORE INTO ' . DB::t('settings') . " (`key`,`value`) VALUES ('texts_seeded_v1','1')");
    } catch (Throwable $e) {}
}
