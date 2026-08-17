<?php
/**
 * API برنامهٔ روزانه — تیک زدن کارها (AJAX) — نسخهٔ ۲٫۱
 * پاسخ: {ok, done, streak, reward}
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
if (!$u) json_out(['ok'=>false,'message'=>'ابتدا وارد حساب شوید.']);
if (!fr_planner_enabled()) json_out(['ok'=>false,'message'=>'برنامهٔ روزانه غیرفعال است.']);

$jin = json_decode((string)file_get_contents('php://input'), true);
$src = is_array($jin) && $jin !== [] ? $jin : $_POST;
$act = (string)($src['act'] ?? '');
$id  = (int)($src['id'] ?? 0);
$target = max(1, (int)DB::setting('planner_streak_target', '7'));
$before = DB::val("SELECT COUNT(*) FROM " . DB::t('planner_rewards') . " WHERE user_id=?", [(int)$u['id']]);

if ($act === 'toggle') {
    $done = fr_planner_toggle((int)$u['id'], $id);
    if ($done === null) json_out(['ok'=>false,'message'=>'کار پیدا نشد.']);
    $after = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('planner_rewards') . " WHERE user_id=?", [(int)$u['id']]);
    json_out([
        'ok'     => true,
        'done'   => $done,
        'streak' => fr_planner_current_streak((int)$u['id']),
        'target' => $target,
        'reward' => $after > (int)$before,   // همین تیک، پاداش استریک را صادر کرد
    ]);
}
json_out(['ok'=>false,'message'=>'درخواست نامعتبر.']);
