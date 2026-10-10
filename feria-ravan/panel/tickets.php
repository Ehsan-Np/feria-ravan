<?php
/** تیکت‌های پشتیبانی — ثبت، گفت‌وگو، سطل زباله و بازیابی */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../core/TrashManager.php';
fr_bootstrap();
$u = require_user();
fr_panel_guard('tickets');
TrashManager::ensureSchema();
$msg = '';

/* عملیات POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');
    $tid = (int)post('ticket_id');

    if ($act === 'new') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = trim((string)($_POST['body'] ?? ''));
        if ($subject === '' || $body === '') {
            $msg = '<div class="fr-alert err">موضوع و متن تیکت الزامی است.</div>';
        } else {
            $attach = fr_ticket_attach_save($_FILES['attach'] ?? []);
            if (!$attach['ok']) {
                $msg = '<div class="fr-alert err">' . e($attach['error'] ?? 'آپلود پیوست ناموفق بود.') . '</div>';
            } else {
                $tid = DB::insert('tickets', ['user_id' => $u['id'], 'subject' => mb_substr($subject,0,190),
                    'priority' => in_array($_POST['priority']??'normal',['low','normal','high','urgent'],true) ? $_POST['priority'] : 'normal']);
                DB::insert('ticket_replies', ['ticket_id' => $tid, 'author_id' => $u['id'], 'body' => $body,
                    'attachment' => $attach['path']]);
                log_activity('ticket_new', 'ticket', $tid, ['attach' => $attach['path'] ? 1 : 0]);
                redirect(fr_panel_url('ticket-view.php?id=' . $tid));
            }
        }
    }

    if ($act === 'trash_ticket') {
        $chk = DB::one("SELECT id FROM " . DB::t('tickets') . " WHERE id=? AND user_id=?", [$tid, $u['id']]);
        if ($chk && TrashManager::trash('ticket', $tid, (int)$u['id'])) {
            flash('تیکت با موفقیت به سطل زباله منتقل گردید 🗑 (امکان بازیابی وجود دارد)', 'ok');
        } else {
            flash('خطا در انتقال تیکت به سطل زباله.', 'err');
        }
        redirect(fr_panel_url('tickets.php'));
    }

    if ($act === 'restore_ticket') {
        if ($tid > 0 && TrashManager::restore('ticket', $tid, (int)$u['id'])) {
            flash('تیکت با موفقیت از سطل زباله بازیابی و فعال گردید ♻️', 'ok');
        } else {
            flash('خطا در بازیابی تیکت.', 'err');
        }
        redirect(fr_panel_url('tickets.php?view=trash'));
    }

    if ($act === 'purge_ticket') {
        if ($tid > 0 && TrashManager::purge('ticket', $tid, (int)$u['id'])) {
            flash('تیکت برای همیشه و به صورت قطعی از حسابتان حذف گردید ❌', 'ok');
        } else {
            flash('خطا در حذف قطعی تیکت.', 'err');
        }
        redirect(fr_panel_url('tickets.php?view=trash'));
    }

    if ($act === 'empty_trash') {
        $cnt = TrashManager::emptyTrash('ticket', (int)$u['id']);
        flash('سطل زباله تیکت‌ها پاکسازی شد (' . fa_num($cnt) . ' مورد حذف گردید) 🗑', 'ok');
        redirect(fr_panel_url('tickets.php?view=trash'));
    }
}

$viewMode = (string)($_GET['view'] ?? 'active');
$whereSql = ($viewMode === 'trash') ? "user_id=? AND deleted_at IS NOT NULL" : "user_id=? AND deleted_at IS NULL";

$tickets = DB::all("SELECT * FROM " . DB::t('tickets') . " WHERE {$whereSql} ORDER BY id DESC", [$u['id']]);
$activeTicketsCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE user_id=? AND deleted_at IS NULL", [$u['id']]);
$trashTicketsCount  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE user_id=? AND deleted_at IS NOT NULL", [$u['id']]);

$statusMap = ['open'=>['info','باز'],'answered'=>['ok','پاسخ داده شد'],'user_reply'=>['warn','در انتظار پاسخ'],'closed'=>['err','بسته']];

render_header('تیکت پشتیبانی');
panel_layout($u, 'tickets');
echo $msg;
?>
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <h3 style="margin:0">تیکت‌های شما</h3>
        <a class="fr-chip <?= $viewMode==='active'?'purple':'info' ?>" href="<?= fr_panel_url('tickets.php?view=active') ?>" style="text-decoration:none">
          فعال (<?= fa_num($activeTicketsCount) ?>)
        </a>
        <a class="fr-chip <?= $viewMode==='trash'?'purple':'info' ?>" href="<?= fr_panel_url('tickets.php?view=trash') ?>" style="text-decoration:none">
          🗑 سطل زباله (<?= fa_num($trashTicketsCount) ?>)
        </a>
      </div>

      <?php if ($viewMode === 'trash' && !empty($tickets)): ?>
        <form method="post" onsubmit="return confirm('⚠️ آیا از خالی کردن سطل زباله تیکت‌ها اطمینان دارید؟');">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="empty_trash">
          <button class="fr-btn sm danger" type="submit">🗑 خالی کردن</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$tickets): ?>
      <p class="fr-alert info" style="margin:0">
        <?= $viewMode === 'trash' ? 'سطل زباله تیکت‌ها خالی است.' : 'تیکتی ثبت نشده است.' ?>
      </p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="fr-table">
      <thead>
        <tr>
          <th>#</th>
          <th>موضوع</th>
          <th>وضعیت</th>
          <th>بروزرسانی</th>
          <th style="min-width:120px">اقدامات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tickets as $t): [$c,$l] = $statusMap[$t['status']] ?? ['info','—']; ?>
        <tr>
          <td><?= fa_num($t['id']) ?></td>
          <td>
            <?php if ($viewMode === 'trash'): ?>
              <b><?= e($t['subject']) ?></b>
            <?php else: ?>
              <a href="ticket-view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['subject']) ?></b></a>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($viewMode === 'trash'): ?>
              <span class="fr-chip err">سطل زباله</span>
            <?php else: ?>
              <span class="fr-chip <?= $c ?>"><?= $l ?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px"><?= fa_num(jdate('j F Y', strtotime($t['updated_at']))) ?></td>
          <td class="actions" style="white-space:nowrap">
            <?php if ($viewMode === 'trash'): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('آیا از بازیابی این تیکت اطمینان دارید؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="restore_ticket">
                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                <button class="fr-btn sm ok" style="background:#059669;color:#fff" type="submit">♻️ بازیابی</button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('⚠️ حذف نهایی تیکت؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="purge_ticket">
                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                <button class="fr-btn sm danger" type="submit">❌ حذف</button>
              </form>
            <?php else: ?>
              <a class="fr-btn sm" href="ticket-view.php?id=<?= (int)$t['id'] ?>">مشاهده</a>
              <form method="post" style="display:inline" onsubmit="return confirm('انتقال این تیکت به سطل زباله؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="trash_ticket">
                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                <button class="fr-btn sm danger" type="submit" title="انتقال به سطل زباله">🗑</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p style="font-size:12px;color:var(--muted);line-height:2;margin:14px 2px 0">
      💡 تغییر شماره کارت بانکی، ابطال سفارش‌های خاص و موضوعات مالی فقط از همین مسیر (تیکت پشتیبانی)
      قابل بررسی است تا امنیت حسابتان حفظ شود.
    </p>
  </div>
  <div class="fr-card" style="margin:0;align-self:start">
    <h3>ثبت تیکت جدید</h3>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="new">
      <div class="fr-field"><label>موضوع <span class="req">*</span></label>
        <input class="fr-input" name="subject" maxlength="190"
               value="<?= isset($_GET['subject']) ? e((string)$_GET['subject']) : '' ?>"></div>
      <div class="fr-field"><label>اولویت</label>
        <select class="fr-select" name="priority">
          <option value="low">کم</option><option value="normal" selected>عادی</option>
          <option value="high">زیاد</option><option value="urgent">فوری</option>
        </select></div>
      <div class="fr-field"><label>متن پیام <span class="req">*</span></label>
        <textarea class="fr-textarea" name="body" rows="6"><?= isset($_GET['body']) ? e((string)$_GET['body']) : '' ?></textarea></div>
      <div class="fr-field"><label>🖼 پیوست تصویر (اختیاری — اسکرین‌شات مشکل)</label>
        <input class="fr-input" type="file" name="attach" accept="image/jpeg,image/png,image/webp" style="font-size:12px">
        <small style="display:block;color:var(--muted);margin-top:6px">JPG / PNG / WEBP — حداکثر ۲ مگابایت</small></div>
      <button class="fr-btn">ارسال تیکت</button>
    </form>
  </div>
</div>
<?php panel_layout_end(); render_footer(); ?>
