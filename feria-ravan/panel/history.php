<?php
/** تاریخچه تست‌های روانشناسی — مشاهده گزارش، فاکتور، PDF، بارکد و سطل زباله */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../core/TrashManager.php';
fr_bootstrap();
$u = require_user();
fr_panel_guard('history');
TrashManager::ensureSchema();

/* ── عملیات POST مربوط به سطل زباله، بازیابی و حذف ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');
    $attId = (int)post('attempt_id');

    if ($act === 'trash_attempt') {
        $check = DB::one("SELECT id FROM " . DB::t('attempts') . " WHERE id=? AND user_id=?", [$attId, $u['id']]);
        if ($check && TrashManager::trash('attempt', $attId, (int)$u['id'])) {
            flash('آزمون انتخابی با موفقیت به سطل زباله منتقل گردید 🗑 (امکان بازیابی از سطل زباله وجود دارد)', 'ok');
        } else {
            flash('خطا در انتقال آزمون به سطل زباله.', 'err');
        }
        redirect(fr_panel_url('history.php'));
    }

    if ($act === 'restore_attempt') {
        if ($attId > 0 && TrashManager::restore('attempt', $attId, (int)$u['id'])) {
            flash('آزمون انتخابی با موفقیت بازیابی و فعال گردید ♻️', 'ok');
        } else {
            flash('خطا در بازیابی آزمون.', 'err');
        }
        redirect(fr_panel_url('history.php?view=trash'));
    }

    if ($act === 'purge_attempt') {
        if ($attId > 0 && TrashManager::purge('attempt', $attId, (int)$u['id'])) {
            flash('آزمون برای همیشه و به صورت قطعی از حساب شما پاکسازی گردید ❌', 'ok');
        } else {
            flash('خطا در حذف قطعی آزمون.', 'err');
        }
        redirect(fr_panel_url('history.php?view=trash'));
    }

    if ($act === 'empty_trash') {
        $cnt = TrashManager::emptyTrash('attempt', (int)$u['id']);
        flash('سطل زباله آزمون‌های شما پاکسازی شد (' . fa_num($cnt) . ' مورد حذف گردید) 🗑', 'ok');
        redirect(fr_panel_url('history.php?view=trash'));
    }
}

$viewMode = (string)($_GET['view'] ?? 'active');
$whereSql = ($viewMode === 'trash') ? "a.user_id=? AND a.deleted_at IS NOT NULL" : "a.user_id=? AND a.deleted_at IS NULL";

$rows = DB::all("SELECT a.*, t.title AS test_title, o.id AS order_id, o.status AS order_status
                 FROM " . DB::t('attempts') . " a
                 JOIN " . DB::t('tests') . " t ON t.id=a.test_id
                 LEFT JOIN " . DB::t('orders') . " o ON o.id=a.order_id
                 WHERE {$whereSql} ORDER BY " . ($viewMode === 'trash' ? "a.deleted_at DESC" : "a.id DESC"), [$u['id']]);

$activeCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE user_id=? AND deleted_at IS NULL", [$u['id']]);
$trashCount  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE user_id=? AND deleted_at IS NOT NULL", [$u['id']]);

// همگام‌سازی دسترسی‌های پرداخت‌شده: اگر کاربری هزینهٔ تست را پرداخت کرده، تلاشش به حالت ویژه ارتقا یابد
if ($viewMode !== 'trash') {
    foreach ($rows as &$r) {
        if ($r['mode'] === 'free' && $r['status'] !== 'in_progress') {
            $paidOrder = null;
            if (!empty($r['order_id'])) {
                $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') . " WHERE id=? AND status='paid'", [(int)$r['order_id']]);
            }
            if (!$paidOrder) {
                $paidOrder = DB::one("SELECT id FROM " . DB::t('orders') .
                                     " WHERE user_id=? AND test_id=? AND status='paid' ORDER BY id DESC LIMIT 1",
                                     [$u['id'], $r['test_id']]);
            }
            if ($paidOrder) {
                DB::update('attempts', ['mode' => 'full', 'order_id' => $paidOrder['id']], 'id=?', [$r['id']]);
                $r['mode'] = 'full';
                $r['order_id'] = $paidOrder['id'];
                $r['order_status'] = 'paid';
            }
        }
    }
    unset($r);
}

render_header('تاریخچه تست‌های روانشناسی');
panel_layout($u, 'history');
?>
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <h3 style="margin:0">تاریخچه تست‌های روانشناسی</h3>
      <a class="fr-chip <?= $viewMode==='active'?'purple':'info' ?>" href="<?= fr_panel_url('history.php?view=active') ?>" style="text-decoration:none">
        تست‌های فعال (<?= fa_num($activeCount) ?>)
      </a>
      <a class="fr-chip <?= $viewMode==='trash'?'purple':'info' ?>" href="<?= fr_panel_url('history.php?view=trash') ?>" style="text-decoration:none">
        🗑 سطل زباله (<?= fa_num($trashCount) ?>)
      </a>
    </div>

    <?php if ($viewMode === 'trash' && !empty($rows)): ?>
      <form method="post" onsubmit="return confirm('⚠️ آیا از خالی کردن کامل سطل زباله آزمون‌ها اطمینان دارید؟ تمام آزمون‌های سطل زباله برای همیشه حذف خواهند شد.');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="empty_trash">
        <button class="fr-btn sm danger" type="submit">🗑 خالی کردن سطل زباله</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <p class="fr-alert info" style="margin:0">
      <?= $viewMode === 'trash' ? 'سطل زباله آزمون‌های شما خالی است.' : 'هنوز تستی ثبت نشده است.' ?>
    </p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead>
      <tr>
        <th>تست روانشناسی</th>
        <th>نوع</th>
        <th>وضعیت</th>
        <?php if ($viewMode === 'trash'): ?>
          <th>تاریخ حذف</th>
        <?php else: ?>
          <th>پیشرفت</th>
          <th>تاریخ انجام</th>
        <?php endif; ?>
        <th style="min-width:180px">اقدامات</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $totalQ = (int)DB::val('SELECT question_count FROM ' . DB::t('tests') . ' WHERE id=?', [$r['test_id']]);
      $pct = $totalQ ? min(100, round($r['progress'] / $totalQ * 100)) : 0;
    ?>
    <tr>
      <td><b><?= e($r['test_title']) ?></b></td>
      <td><span class="fr-chip <?= $r['mode']==='full'?'purple':'info' ?>"><?= $r['mode']==='full'?'ویژه':'رایگان' ?></span></td>
      <td>
        <?php if ($viewMode === 'trash'): ?>
          <span class="fr-chip err">در سطل زباله</span>
        <?php else: ?>
          <?= $r['status']==='in_progress' ? '<span class="fr-chip warn">درحال انجام</span>' : '<span class="fr-chip ok">تکمیل‌شده</span>' ?>
        <?php endif; ?>
      </td>

      <?php if ($viewMode === 'trash'): ?>
        <td style="font-size:12px"><?= $r['deleted_at'] ? fa_num(jdate('j F Y - H:i', strtotime((string)$r['deleted_at']))) : '—' ?></td>
      <?php else: ?>
        <td style="min-width:120px">
          <div class="fr-progress"><i style="width:<?= $pct ?>%"></i></div>
          <small style="color:var(--muted)"><?= fa_num($pct) ?>٪</small>
        </td>
        <td><?= fa_num(jdate('j F Y', strtotime($r['started_at']))) ?></td>
      <?php endif; ?>

      <td class="actions" style="white-space:nowrap">
        <?php if ($viewMode === 'trash'): ?>
          <!-- بازیابی آزمون -->
          <form method="post" style="display:inline" onsubmit="return confirm('آیا از بازیابی این آزمون به لیست فعال اطمینان دارید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="restore_attempt">
            <input type="hidden" name="attempt_id" value="<?= (int)$r['id'] ?>">
            <button class="fr-btn sm ok" style="background:#059669;color:#fff" type="submit">♻️ بازیابی</button>
          </form>

          <!-- حذف قطعی و دائمی -->
          <form method="post" style="display:inline" onsubmit="return confirm('⚠️ حذف نهایی:\n\nآیا از حذف کامل و همیشگی این آزمون از حسابتان اطمینان دارید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="purge_attempt">
            <input type="hidden" name="attempt_id" value="<?= (int)$r['id'] ?>">
            <button class="fr-btn sm danger" type="submit">❌ حذف قطعی</button>
          </form>
        <?php else: ?>
          <?php if ($r['status']==='in_progress'): ?>
            <a class="fr-btn sm" href="<?= e(fr_panel_url('take.php?attempt=' . (int)$r['id'])) ?>">ادامه</a>
          <?php else: ?>
            <a class="fr-btn sm" href="<?= e(fr_panel_url('report.php?attempt=' . (int)$r['id'])) ?>">گزارش</a>
            <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('dyad.php?action=new&attempt=' . (int)$r['id'])) ?>" title="سنجش تطابق دونفره">👥 تطابق</a>
            <?php if ($r['mode'] === 'free'): ?>
            <a class="fr-btn sm accent" href="<?= e(fr_panel_url('test-start.php?id=' . (int)$r['test_id'] . '&upgrade_attempt=' . (int)$r['id'])) ?>" title="ارتقا به ویژه">⭐ ویژه</a>
            <?php endif; ?>
            <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . (int)$r['id'] . '&auto=1')) ?>" target="_blank" title="دریافت نسخه چاپی و PDF کارنامه">PDF 📄</a>
            <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('barcode.php?attempt=' . (int)$r['id'])) ?>" title="بارکد گزارش">▮▮▮</a>
          <?php endif; ?>
          <?php if ($r['order_id'] && $r['order_status']==='paid'): ?>
            <a class="fr-btn sm ghost" href="invoice.php?order=<?= (int)$r['order_id'] ?>">فاکتور</a>
          <?php endif; ?>

          <!-- دکمه انتقال به سطل زباله کاربر -->
          <form method="post" style="display:inline" onsubmit="return confirm('آیا این آزمون به سطل زباله منتقل شود؟\n(بعداً می‌توانید آن را بازیابی کنید)');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="trash_attempt">
            <input type="hidden" name="attempt_id" value="<?= (int)$r['id'] ?>">
            <button class="fr-btn sm danger" type="submit" title="انتقال به سطل زباله">🗑 حذف</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php panel_layout_end(); render_footer(); ?>
