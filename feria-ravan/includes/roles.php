<?php
/**
 * فریا روان — نقش‌ها و سطح دسترسی مدیران (نسخهٔ ۲٫۱)
 * ─────────────────────────────────────────────────────────────────────────
 * هر نقش = تیتر + مجموعهٔ capها (JSON). هر فایل ادمین به یک cap نگاشت می‌شود.
 * سازگاری با قبل: مدیران قدیمی (ستون role) دقیقاً همان دسترسی قبلی را دارند؛
 * نقش‌های سفارشی فقط روی مدیرانی اثر می‌گذارند که role_id گرفته‌اند.
 */

/** کاتالوگ capها: [cap => برچسب فارسی] */
function fr_caps_catalog(): array
{
    return [
        'tests'       => 'تست‌های روانشناسی و سؤالات و الگوها',
        'events'      => 'کارگاه‌ها و دوره‌ها',
        'registrants' => 'ثبت‌نام‌شدگان رویدادها',
        'consult'     => 'مشاوره و نوبت‌دهی',
        'orders'      => 'سفارش‌ها، پرداخت‌ها و استرداد',
        'discounts'   => 'کدهای تخفیف',
        'users'       => 'کاربران و تیکت‌ها',
        'reviews'     => 'دیدگاه‌ها',
        'banners'     => 'بنرها و اطلاعیه‌ها',
        'pages'       => 'برگه‌ها، صفحه‌ساز دیداری و پیوندها',
        'fields'      => 'فیلدهای دلخواه فرم‌ها',
        'newsletter'  => 'خبرنامهٔ پیامکی',
        'sms'         => 'پیامک‌ها و گزارش ارسال',
        'reports'     => 'مانیتور و گزارش‌ها',
        'settings'    => 'تنظیمات کلی سایت',
        'texts'       => 'شخصی‌سازی متن‌ها (ری‌برندینگ)',
        'roles'       => 'نقش‌ها و مدیران (حساس)',
    ];
}

/**
 * نگاشت «فایل ادمین → cap لازم» (null = همهٔ مدیران واردشده).
 * برگرفته از require_admin فعلی هر فایل — بازتولید دقیق رفتار قبلی.
 */
function fr_page_cap_map(): array
{
    return [
        'index.php' => null, 'dashboard.php' => null, 'profile.php' => null,
        'tests.php' => 'tests', 'questions.php' => 'tests', 'templates.php' => 'tests', 'attempts.php' => 'tests', 'attempt-answers.php' => 'tests', 'test-fields.php' => 'tests',
        'events.php' => 'events', 'workshops.php' => 'events', 'courses.php' => 'events',
        'registrants.php' => 'registrants',
        'consult.php' => 'consult', 'consult-bookings.php' => 'consult', 'consult-schedule.php' => 'consult', 'clinical-case.php' => 'consult',
        'orders.php' => 'orders', 'refunds.php' => 'orders', 'discounts.php' => 'discounts',
        'users.php' => 'users', 'user-view.php' => 'users', 'tickets.php' => 'users',
        'reviews.php' => 'reviews', 'banners.php' => 'banners', 'permalinks.php' => 'pages',
        'pages.php' => 'pages', 'page-builder.php' => 'pages',
        'fields.php' => 'fields', 'newsletter.php' => 'newsletter',
        'sms.php' => 'sms', 'monitor.php' => 'reports', 'planner.php' => 'reports',
        'notifications.php' => 'newsletter',
        'contacts.php' => 'newsletter', 'campaigns.php' => 'newsletter',
        'settings.php' => 'settings', 'texts.php' => 'texts',
        'roles.php' => 'roles', 'admins.php' => 'roles', 'updater.php' => 'settings',
        'trash.php' => null,
    ];
}

/** capهای نقش قدیمی (legacy) — دقیقاً معادل require_admin فعلی هر برگه */
function fr_legacy_role_caps(string $legacyRole): array
{
    $all = array_keys(fr_caps_catalog());
    switch ($legacyRole) {
        case 'superadmin':
        case 'super':
            return $all;                                                    // دسترسی کامل
        case 'manager':
        case 'admin':
            // همه‌چیز جز تنظیمات کلی، متن‌ها و نقش‌ها (معادل require_admin قبلی)
            return array_values(array_diff($all, ['settings','texts','roles']));
        case 'support':
            // فقط برگه‌های بدون محدودیت نقش (معادل require_admin() بدون آرگومان)
            return ['tests','orders','discounts','users','reports'];
        default:
            return [];
    }
}

/** capهای مؤثر یک مدیر — نقش سفارشی (role_id) بر legacy اولویت دارد */
function fr_admin_caps(?array $admin): array
{
    if (!$admin) return [];
    $rid = (int)($admin['role_id'] ?? 0);
    if ($rid > 0) {
        try {
            $caps = DB::val("SELECT caps FROM " . DB::t('admin_roles') . " WHERE id=?", [$rid]);
            if (is_string($caps) && $caps !== '') {
                $arr = json_decode($caps, true);
                if (is_array($arr)) {
                    return array_values(array_intersect(array_map('strval', $arr), array_keys(fr_caps_catalog())));
                }
            }
        } catch (Throwable $e) { /* جدول هنوز ساخته نشده */ }
        return [];
    }
    return fr_legacy_role_caps((string)($admin['role'] ?? ''));
}

/** آیا مدیر cap مشخصی دارد؟ (cap=null یعنی صفحهٔ عمومی پیشخوان) */
function fr_admin_can(?array $admin, ?string $cap): bool
{
    if (!$admin) return false;
    if ($cap === null || $cap === '') return true;
    return in_array($cap, fr_admin_caps($admin), true);
}

/** آیا مدیر به «فایل ادمین» جاری دسترسی دارد؟ */
function fr_admin_page_allowed(array $admin, string $scriptName): bool
{
    $file = basename($scriptName);
    $map  = fr_page_cap_map();
    if (!array_key_exists($file, $map)) return true;                 // فایل ناشناخته → رفتار قبلی
    return fr_admin_can($admin, $map[$file]);
}

/** seed نقش‌های سیستمی (idempotent) — سه نقش پیش‌فرض معادل رفتار قدیمی */
function fr_roles_seed(): void
{
    $defaults = [
        ['مدیر ارشد (دسترسی کامل)', fr_legacy_role_caps('superadmin')],
        ['مدیر اجرایی',             fr_legacy_role_caps('manager')],
        ['پشتیبان سایت',            fr_legacy_role_caps('support')],
    ];
    foreach ($defaults as [$title, $caps]) {
        $has = DB::val("SELECT id FROM " . DB::t('admin_roles') . " WHERE title=?", [$title]);
        if (!$has) {
            DB::run("INSERT INTO " . DB::t('admin_roles') . " (title, caps, is_system, created_at) VALUES (?,?,1,NOW())",
                    [$title, json_encode($caps, JSON_UNESCAPED_UNICODE)]);
        }
    }
}
