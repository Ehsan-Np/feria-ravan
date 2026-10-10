<?php
/**
 * ============================================================================
 *  مرکز مدیریت سطل زباله و بازیابی سامانه (System Trash & Recycle Bin)
 *  ─ مشاهدهٔ تمام موارد منتقل‌شده به سطل زباله (تست‌ها، کارگاه‌ها، دوره‌ها، کاربران، تیکت‌ها)
 *  ─ امکان بازیابی فوری (Restore) به لیست‌های فعال
 *  ─ امکان حذف قطعی و دائمی (Permanent Purge) به همراه پاک‌سازی داده‌های وابسته
 *  ─ امکان خالی کردن یک‌جای سطل زباله (Empty Trash)
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../core/TrashManager.php';

$adm = require_admin(['superadmin', 'manager', 'support']);
TrashManager::ensureSchema();

/* ══════════════════════════════════════════════════════════════════════════
   ۱) عملیات POST (بازیابی، حذف دائمی، خالی کردن سطل)
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act  = (string)post('act');
    $type = (string)post('type');
    $id   = (int)post('id');
    $tab  = (string)post('tab', 'all');

    if ($act === 'restore') {
        if ($id > 0 && TrashManager::restore($type, $id)) {
            flash('مورد انتخابی با موفقیت از سطل زباله بازیابی و فعال گردید ♻️', 'ok');
        } else {
            flash('خطا در بازیابی مورد انتخابی.', 'err');
        }
        redirect(SITE_URL . '/admin/trash.php?tab=' . urlencode($tab));
    }

    if ($act === 'purge') {
        if ($id > 0 && TrashManager::purge($type, $id)) {
            flash('مورد انتخابی برای همیشه و به صورت کامل از پایگاه داده پاکسازی شد 🗑', 'ok');
        } else {
            flash('خطا در حذف قطعی مورد انتخابی.', 'err');
        }
        redirect(SITE_URL . '/admin/trash.php?tab=' . urlencode($tab));
    }

    if ($act === 'empty_trash') {
        $emptyType = ($tab === 'all') ? 'all' : $tab;
        $purgedCount = TrashManager::emptyTrash($emptyType);
        flash("سطل زباله پاکسازی شد؛ تعداد " . fa_num($purgedCount) . " مورد برای همیشه حذف گردیدند.", 'ok');
        redirect(SITE_URL . '/admin/trash.php?tab=' . urlencode($tab));
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   ۲) خواندن داده‌ها و آمار
   ══════════════════════════════════════════════════════════════════════════ */
$currentTab = (string)($_GET['tab'] ?? 'all');
$validTabs  = ['all', 'attempts', 'tests', 'events', 'users', 'tickets', 'registrations', 'reviews'];
if (!in_array($currentTab, $validTabs, true)) {
    $currentTab = 'all';
}

$stats = TrashManager::getStats();
$items = TrashManager::getList($currentTab, 200);

$searchQ = trim((string)($_GET['q'] ?? ''));
if ($searchQ !== '') {
    $items = array_values(array_filter($items, function ($it) use ($searchQ) {
        return (mb_stripos($it['title'], $searchQ) !== false ||
                mb_stripos($it['meta'], $searchQ) !== false ||
                mb_stripos((string)$it['id'], $searchQ) !== false);
    }));
}

admin_layout($adm, 'trash', '🗑 سطل زباله و مدیریت موارد حذف‌شده');
echo flash();
?>
<div style="max-width:1160px;margin:0 auto">

  <!-- کارت‌های آمار وضعیت سطل زباله -->
  <div class="fr-grid cols-4" style="margin-bottom:16px">
    <?php stat_card('🗑', 'کل موارد در سطل زباله', fa_num($stats['total']), '#fee2e2'); ?>
    <?php stat_card('🧠', 'جلسات و تحلیل‌های آزمون', fa_num($stats['attempts']), '#ede9fe'); ?>
    <?php stat_card('🎓', 'کارگاه‌ها و دوره‌ها', fa_num($stats['events']), '#e0f2fe'); ?>
    <?php stat_card('👥', 'کاربران حذف‌شده', fa_num($stats['users']), '#fef3c7'); ?>
  </div>

  <!-- نوار کنترل تب‌ها و جستجو -->
  <div class="fr-card" style="margin-bottom:16px;padding:14px 18px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="fr-btn sm <?= $currentTab==='all' ? '' : 'ghost' ?>" href="?tab=all">
          🗂 همه (<?= fa_num($stats['total']) ?>)
        </a>
        <a class="fr-btn sm <?= $currentTab==='attempts' ? '' : 'ghost' ?>" href="?tab=attempts">
          🧠 آزمون‌ها (<?= fa_num($stats['attempts']) ?>)
        </a>
        <a class="fr-btn sm <?= $currentTab==='tests' ? '' : 'ghost' ?>" href="?tab=tests">
          📋 تست‌های روانشناسی (<?= fa_num($stats['tests']) ?>)
        </a>
        <a class="fr-btn sm <?= $currentTab==='events' ? '' : 'ghost' ?>" href="?tab=events">
          🎓 رویدادها (<?= fa_num($stats['events']) ?>)
        </a>
        <a class="fr-btn sm <?= $currentTab==='users' ? '' : 'ghost' ?>" href="?tab=users">
          👥 کاربران (<?= fa_num($stats['users']) ?>)
        </a>
        <a class="fr-btn sm <?= $currentTab==='tickets' ? '' : 'ghost' ?>" href="?tab=tickets">
          🎫 تیکت‌ها (<?= fa_num($stats['tickets']) ?>)
        </a>
        <a class="fr-btn sm <?= $currentTab==='registrations' ? '' : 'ghost' ?>" href="?tab=registrations">
          📝 ثبت‌نام‌ها (<?= fa_num($stats['registrations']) ?>)
        </a>
        <a class="fr-btn sm <?= $currentTab==='reviews' ? '' : 'ghost' ?>" href="?tab=reviews">
          💬 دیدگاه‌ها (<?= fa_num($stats['reviews']) ?>)
        </a>
      </div>

      <!-- جستجو در سطل زباله -->
      <form method="get" action="trash.php" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="tab" value="<?= e($currentTab) ?>">
        <input class="fr-input" name="q" value="<?= e($searchQ) ?>" placeholder="جستجو در سطل زباله..." style="max-width:210px;padding:6px 10px;font-size:12.5px">
        <button class="fr-btn sm ghost" type="submit">🔎</button>
        <?php if ($searchQ !== ''): ?>
          <a class="fr-btn sm ghost" href="?tab=<?= e($currentTab) ?>">✕</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- فهرست اقلام موجود در سطل زباله -->
  <div class="fr-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
      <h3 style="margin:0;font-size:16px">
        فهرست موارد در سطل زباله (<?= fa_num(count($items)) ?> مورد)
      </h3>

      <?php if (!empty($items)): ?>
        <form method="post" onsubmit="return confirm('⚠️ اخطار بسیار مهم:\n\nآیا از خالی کردن کامل سطل زباله و حذف دائمی این موارد اطمینان دارید؟\nاین عملیات داده‌ها را برای همیشه پاک می‌کند و غیرقابل بازگشت است.');">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="empty_trash">
          <input type="hidden" name="tab" value="<?= e($currentTab) ?>">
          <button class="fr-btn sm danger" type="submit">
            🗑 خالی کردن سطل زباله (<?= $currentTab === 'all' ? 'همه بخش‌ها' : 'این بخش' ?>)
          </button>
        </form>
      <?php endif; ?>
    </div>

    <div style="overflow-x:auto">
      <table class="fr-table">
        <thead>
          <tr>
            <th style="width:50px">#ID</th>
            <th style="width:130px">بخش / نوع</th>
            <th>عنوان / نام مورد</th>
            <th>توضیحات و جزئیات</th>
            <th style="width:140px">زمان حذف</th>
            <th style="width:180px;text-align:center">عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:40px 0;color:var(--muted)">
                <div style="font-size:36px;margin-bottom:8px">🎉</div>
                <b style="font-size:14px;color:var(--text)">سطل زباله خالی است</b>
                <p style="margin:4px 0 0;font-size:12px">هیچ موردی در این بخش از سطل زباله وجود ندارد.</p>
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($items as $it): ?>
          <tr>
            <td style="font-size:11.5px;color:var(--muted)">#<?= fa_num($it['id']) ?></td>
            <td>
              <span class="fr-chip" style="font-size:11px;padding:3px 8px">
                <?= $it['type_icon'] ?> <?= e($it['type_label']) ?>
              </span>
            </td>
            <td>
              <b style="font-size:13px;color:var(--text)">
                <?= e($it['title']) ?>
              </b>
            </td>
            <td style="font-size:12px;color:var(--muted)">
              <?= e($it['meta']) ?>
            </td>
            <td style="font-size:11.5px;white-space:nowrap" dir="ltr">
              <?= $it['deleted_at'] ? fa_num(jdate('Y/m/d H:i', strtotime((string)$it['deleted_at']))) : '—' ?>
            </td>
            <td style="text-align:center;white-space:nowrap">
              <!-- دکمه بازیابی -->
              <form method="post" style="display:inline" onsubmit="return confirm('آیا از بازیابی این مورد و بازگرداندن آن به لیست فعال اطمینان دارید؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="restore">
                <input type="hidden" name="type" value="<?= e($it['type']) ?>">
                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="tab" value="<?= e($currentTab) ?>">
                <button class="fr-btn sm ok" type="submit" style="background:#059669;color:#fff;padding:5px 10px" title="بازیابی و فعال‌سازی مجدد">
                  ♻️ بازیابی
                </button>
              </form>

              <!-- دکمه حذف دائمی -->
              <form method="post" style="display:inline" onsubmit="return confirm('⚠️ حذف قطعی:\n\nآیا از حذف کامل و غیرقابل‌بازگشت این مورد از پایگاه داده اطمینان دارید؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="purge">
                <input type="hidden" name="type" value="<?= e($it['type']) ?>">
                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="tab" value="<?= e($currentTab) ?>">
                <button class="fr-btn sm danger" type="submit" style="padding:5px 10px" title="حذف دائمی از دیتابیس">
                  ❌ حذف دائمی
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php admin_layout_end(); ?>
