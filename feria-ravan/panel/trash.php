<?php
/**
 * ============================================================================
 *  سطل زباله پنل کاربری فریا روان (User Trash & Recycle Bin)
 *  ─ مشاهده آزمون‌ها، تیکت‌ها و موارد حذف‌شده توسط کاربر
 *  ─ امکان بازیابی فوری یا حذف کامل و دائمی از حساب کاربری
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../core/TrashManager.php';
fr_bootstrap();
$u = require_user();
TrashManager::ensureSchema();

/* ── عملیات POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act  = (string)post('act');
    $type = (string)post('type');
    $id   = (int)post('id');
    $tab  = (string)post('tab', 'all');

    if ($act === 'restore' && $id > 0) {
        if (TrashManager::restore($type, $id, (int)$u['id'])) {
            flash('مورد انتخابی با موفقیت بازیابی شد ♻️', 'ok');
        } else {
            flash('خطا در بازیابی مورد انتخابی.', 'err');
        }
        redirect(fr_panel_url('trash.php?tab=' . urlencode($tab)));
    }

    if ($act === 'purge' && $id > 0) {
        if (TrashManager::purge($type, $id, (int)$u['id'])) {
            flash('مورد انتخابی برای همیشه از حساب شما حذف گردید ❌', 'ok');
        } else {
            flash('خطا در حذف قطعی مورد.', 'err');
        }
        redirect(fr_panel_url('trash.php?tab=' . urlencode($tab)));
    }

    if ($act === 'empty_trash') {
        $emptyType = ($tab === 'all') ? 'all' : $tab;
        $cnt = TrashManager::emptyTrash($emptyType, (int)$u['id']);
        flash('سطل زباله حساب شما پاکسازی شد (' . fa_num($cnt) . ' مورد برای همیشه حذف گردید) 🗑', 'ok');
        redirect(fr_panel_url('trash.php?tab=' . urlencode($tab)));
    }
}

$tab = (string)($_GET['tab'] ?? 'all');
if (!in_array($tab, ['all', 'attempts', 'tickets', 'registrations'], true)) {
    $tab = 'all';
}

$stats = TrashManager::getStats((int)$u['id']);
$items = TrashManager::getList($tab, 100, 0, (int)$u['id']);

render_header('سطل زباله حساب کاربری');
panel_layout($u, 'trash');
?>
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
      <h3 style="margin:0">🗑 سطل زباله حساب شما</h3>
      <a class="fr-chip <?= $tab==='all'?'purple':'info' ?>" href="<?= fr_panel_url('trash.php?tab=all') ?>" style="text-decoration:none">
        همه (<?= fa_num($stats['total']) ?>)
      </a>
      <a class="fr-chip <?= $tab==='attempts'?'purple':'info' ?>" href="<?= fr_panel_url('trash.php?tab=attempts') ?>" style="text-decoration:none">
        🧠 آزمون‌ها (<?= fa_num($stats['attempts']) ?>)
      </a>
      <a class="fr-chip <?= $tab==='tickets'?'purple':'info' ?>" href="<?= fr_panel_url('trash.php?tab=tickets') ?>" style="text-decoration:none">
        🎫 تیکت‌ها (<?= fa_num($stats['tickets']) ?>)
      </a>
    </div>

    <?php if (!empty($items)): ?>
      <form method="post" onsubmit="return confirm('⚠️ آیا از خالی کردن کامل سطل زباله و حذف همیشگی این موارد اطمینان دارید؟');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="empty_trash">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <button class="fr-btn sm danger" type="submit">🗑 خالی کردن سطل زباله</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (empty($items)): ?>
    <div style="text-align:center;padding:40px 0;color:var(--muted)">
      <div style="font-size:36px;margin-bottom:8px">🎉</div>
      <b style="font-size:14px;color:var(--text)">سطل زباله خالی است</b>
      <p style="margin:4px 0 0;font-size:12px">هیچ موردی در سطل زباله حساب شما وجود ندارد.</p>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead>
      <tr>
        <th style="width:40px">#</th>
        <th style="width:110px">نوع</th>
        <th>عنوان</th>
        <th>جزئیات</th>
        <th>تاریخ حذف</th>
        <th style="min-width:160px;text-align:center">عملیات</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td>#<?= fa_num($it['id']) ?></td>
        <td>
          <span class="fr-chip" style="font-size:11px">
            <?= $it['type_icon'] ?> <?= e($it['type_label']) ?>
          </span>
        </td>
        <td><b><?= e($it['title']) ?></b></td>
        <td style="font-size:12px;color:var(--muted)"><?= e($it['meta']) ?></td>
        <td style="font-size:12px;white-space:nowrap"><?= $it['deleted_at'] ? fa_num(jdate('j F Y - H:i', strtotime((string)$it['deleted_at']))) : '—' ?></td>
        <td style="text-align:center;white-space:nowrap">
          <form method="post" style="display:inline" onsubmit="return confirm('آیا از بازیابی این مورد اطمینان دارید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="restore">
            <input type="hidden" name="type" value="<?= e($it['type']) ?>">
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <button class="fr-btn sm ok" style="background:#059669;color:#fff" type="submit">♻️ بازیابی</button>
          </form>

          <form method="post" style="display:inline" onsubmit="return confirm('⚠️ حذف همیشگی این مورد از حسابتان؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="purge">
            <input type="hidden" name="type" value="<?= e($it['type']) ?>">
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <button class="fr-btn sm danger" type="submit">❌ حذف قطعی</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php panel_layout_end(); render_footer(); ?>