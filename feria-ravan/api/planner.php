<?php
/**
 * فریا روان — وب‌سرویس و API برنامهٔ روزانه — نسخهٔ ۴٫۹٫۰ (۱۴۰۵)
 * ─ تیک زدن سریع کارها
 * ─ ثبت حس و حال روانی و انرژی روزانه (Mood Check-in)
 * ─ بدون رفرش صفحه با بازخورد آنی JSON
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/planner.php';
fr_bootstrap();

$u = current_user();
if (!$u) json_out(['ok' => false, 'message' => 'لطفاً ابتدا وارد حساب کاربری خود شوید.']);
if (!fr_planner_enabled()) json_out(['ok' => false, 'message' => 'برنامهٔ روزانه در حال حاضر غیرفعال است.']);

$jin = json_decode((string)file_get_contents('php://input'), true);
$src = is_array($jin) && $jin !== [] ? $jin : $_POST;
$act = (string)($src['act'] ?? ($src['action'] ?? ''));

// ۱) تیک زدن سریع کار
if ($act === 'toggle') {
    $id = (int)($src['id'] ?? ($src['task_id'] ?? 0));
    $target = max(1, (int)DB::setting('planner_streak_target', '7'));
    $before = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_rewards') . " WHERE user_id=?", [(int)$u['id']]);

    $done = fr_planner_toggle((int)$u['id'], $id);
    if ($done === null) json_out(['ok' => false, 'message' => 'کار مورد نظر یافت نشد.']);

    $after = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_rewards') . " WHERE user_id=?", [(int)$u['id']]);
    $streak = fr_planner_current_streak((int)$u['id']);

    json_out([
        'ok'     => true,
        'done'   => $done,
        'streak' => $streak,
        'target' => $target,
        'reward' => $after > $before,
    ]);
}

// ۲) ثبت حس و حال روانی و سطح انرژی روزانه
if ($act === 'mood') {
    $day = (string)($src['day'] ?? fr_planner_today());
    $mood = $src['mood'] ?? 'good';
    $energy = (int)($src['energy'] ?? 3);
    $reflection = (string)($src['reflection'] ?? ($src['notes'] ?? ''));

    $ok = fr_planner_mood_save((int)$u['id'], $day, $mood, $energy, $reflection);
    json_out([
        'ok'      => $ok,
        'mood'    => is_numeric($mood) ? (int)$mood : $mood,
        'message' => $ok ? 'حس و حال و ارزیابی روانی امروز شما با موفقیت ثبت شد 🌿' : 'خطا در ثبت حس و حال.',
    ]);
}

json_out(['ok' => false, 'message' => 'درخواست ارسالی نامعتبر است.']);
