<?php
/** مدیریت تیکت‌ها — فهرست، پاسخ، بستن، سطل زباله و بازیابی */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../core/TrashManager.php';
$adm = require_admin();
TrashManager::ensureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');
    $tid = (int)post('ticket_id');

    if ($act === 'reply' && trim((string)post('body')) !== '') {
        $attach = fr_ticket_attach_save($_FILES['attach'] ?? []);
        if (!$attach['ok']) flash($attach['error'] ?? 'آپلود پیوست ناموفق بود.', 'err');
        else {
            DB::insert('ticket_replies', ['ticket_id'=>$tid,'author_id'=>$adm['id'],'is_admin'=>1,'body'=>trim((string)post('body')),'attachment'=>$attach['path']]);
            DB::update('tickets', ['status'=>'answered'], 'id=?', [$tid]);
            $t = DB::one('SELECT user_id FROM ' . DB::t('tickets') . ' WHERE id=?', [$tid]);
            if ($t) {
                $mob = DB::val('SELECT mobile FROM ' . DB::t('users') . ' WHERE id=?', [$t['user_id']]);
                if ($mob) (new Kavenegar())->send($mob, 'پاسخ تیکت شما در ' . DB::setting('site_title','فریا روان') . ' ثبت شد.', 'ticket_answer');
            }
            log_activity('admin_ticket_reply', 'ticket', $tid);
            flash('پاسخ ثبت شد ✅');
        }
    }
    if ($act === 'close') {
        DB::update('tickets', ['status'=>'closed'], 'id=?', [$tid]);
        flash('تیکت بسته شد.');
    }
    if ($act === 'trash_ticket') {
        if ($tid > 0 && TrashManager::trash('ticket', $tid, (int)($adm['id'] ?? 0))) {
            flash('تیکت با موفقیت به سطل زباله منتقل گردید 🗑', 'ok');
        } else {
            flash('خطا در انتقال تیکت به سطل زباله.', 'err');
        }
        redirect(SITE_URL . '/admin/tickets.php');
    }
    if ($act === 'restore_ticket') {
        if ($tid > 0 && TrashManager::restore('ticket', $tid)) {
            flash('تیکت با موفقیت از سطل زباله بازیابی و فعال گردید ♻️', 'ok');
        } else {
            flash('خطا در بازیابی تیکت.', 'err');
        }
        redirect(SITE_URL . '/admin/tickets.php?view_mode=trash');
    }
    if ($act === 'purge_ticket') {
        if ($tid > 0 && TrashManager::purge('ticket', $tid)) {
            flash('تیکت و تمام پیام‌های آن به صورت کامل حذف گردید ❌', 'ok');
        } else {
            flash('خطا در حذف قطعی تیکت.', 'err');
        }
        redirect(SITE_URL . '/admin/tickets.php?view_mode=trash');
    }
    redirect(SITE_URL . '/admin/tickets.php' . (post('keep_view') ? '?view='.$tid : ''));
}

$viewMode = (string)($_GET['view_mode'] ?? 'active');
$view = (int)($_GET['view'] ?? 0);

$whereTicket = ($viewMode === 'trash') ? "t.deleted_at IS NOT NULL" : "t.deleted_at IS NULL";
$rows = DB::all("SELECT t.*, u.first_name, u.last_name FROM " . DB::t('tickets') . " t
                 JOIN " . DB::t('users') . " u ON u.id=t.user_id
                 WHERE {$whereTicket}
                 ORDER BY FIELD(t.status,'open','user_reply','answered','closed'), t.updated_at DESC");

$activeTicketsCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE deleted_at IS NULL");
$trashTicketsCount  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE deleted_at IS NOT NULL");

$statusFa = ['open'=>['info','باز'],'answered'=>['ok','پاسخ داده شد'],'user_reply'=>['warn','پاسخ کاربر'],'closed'=>['err','بسته']];

admin_layout($adm, 'tickets', ($viewMode === 'trash' ? '🗑 سطل زباله تیکت‌ها' : 'تیکت‌های پشتیبانی'));
echo flash();

if ($view && $t = DB::one('SELECT t.*, u.mobile, u.first_name, u.last_name FROM ' . DB::t('tickets') . ' t JOIN ' . DB::t('users') . ' u ON u.id=t.user_id WHERE t.id=?', [$view])):
    $reps = DB::all('SELECT * FROM ' . DB::t('ticket_replies') . ' WHERE ticket_id=? ORDER BY id', [$t['id']]);
?>
<div class="fr-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
    <h3 style="margin:0"><?= e($t['subject']) ?> <small style="font-weight:400;color:var(--muted)">— <?= e(trim($t['first_name'].' '.$t['last_name'])) ?> (<?= fa_num($t['mobile']) ?>)</small></h3>
    <form method="post" style="display:inline" onsubmit="return confirm('آیا از انتقال این تیکت به سطل زباله اطمینان دارید؟');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="trash_ticket">
      <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
      <button class="fr-btn sm danger" type="submit">🗑 انتقال به سطل زباله</button>
    </form>
  </div>
  <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
    <?php foreach ($reps as $r): ?>
    <div style="max-width:80%;background:<?= $r['is_admin']?'#eaf0f8':'#ecfbf4' ?>;border-radius:14px;padding:12px 16px;align-self:<?= $r['is_admin']?'flex-start':'flex-end' ?>">
      <div style="font-size:11.5px;color:var(--muted)"><?= $r['is_admin']?'🛠 پشتیبانی':'👤 کاربر' ?> · <?= fa_num(jdate('j F - H:i', strtotime($r['created_at']))) ?></div>
      <div style="font-size:14px"><?= nl2br(e($r['body'])) ?></div>
      <?= fr_ticket_attach_html($r['attachment'] ?? null) ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($t['status']!=='closed'): ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="reply"><input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
    <input type="hidden" name="keep_view" value="1">
    <div class="fr-field"><textarea class="fr-textarea" name="body" rows="4" placeholder="پاسخ پشتیبانی..."></textarea></div>
    <div class="fr-field" style="margin-top:-6px">
      <label style="font-size:12px;color:var(--muted)">🖼 پیوست تصویر (اختیاری — راهنمای تصویری برای کاربر)</label>
      <input class="fr-input" type="file" name="attach" accept="image/jpeg,image/png,image/webp" style="font-size:12px">
    </div>
    <button class="fr-btn sm">ارسال پاسخ</button>
  </form>
  <form method="post" style="display:inline;margin-top:6px">
    <?= csrf_field() ?><input type="hidden" name="act" value="close"><input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
    <button class="fr-btn sm danger">بستن تیکت</button>
  </form>
  <?php endif; ?>
  <a class="fr-btn ghost sm" href="tickets.php">→ بازگشت</a>
</div>
<?php else: ?>
<div class="fr-card">
  <!-- نوار تغییر نما -->
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
    <a class="fr-chip <?= $viewMode==='active'?'purple':'info' ?>" href="tickets.php?view_mode=active" style="text-decoration:none">
      تیکت‌های فعال (<?= fa_num($activeTicketsCount) ?>)
    </a>
    <a class="fr-chip <?= $viewMode==='trash'?'purple':'info' ?>" href="tickets.php?view_mode=trash" style="text-decoration:none">
      🗑 سطل زباله (<?= fa_num($trashTicketsCount) ?>)
    </a>
    <a class="fr-chip ghost" href="<?= SITE_URL ?>/admin/trash.php?tab=tickets" style="text-decoration:none;margin-right:auto">
      مدیریت سطل زباله سیستم ↗
    </a>
  </div>

  <table class="fr-table">
    <thead><tr><th>#</th><th>کاربر</th><th>موضوع</th><th>اولویت</th><th>وضعیت</th><th>بروزرسانی</th><th style="min-width:130px">اقدامات</th></tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr>
        <td colspan="7" style="text-align:center;padding:32px 0;color:var(--muted)">
          <?= $viewMode === 'trash' ? 'سطل زباله تیکت‌ها خالی است.' : 'هیچ تیکتی یافت نشد.' ?>
        </td>
      </tr>
    <?php endif; ?>

    <?php foreach ($rows as $t): [$c,$l] = $statusFa[$t['status']] ?? ['info','—']; ?>
    <tr>
      <td><?= fa_num($t['id']) ?></td>
      <td style="font-size:12.5px"><?= e(trim($t['first_name'].' '.$t['last_name'])) ?></td>
      <td><b><?= e($t['subject']) ?></b></td>
      <td style="font-size:12px"><?= ['low'=>'کم','normal'=>'عادی','high'=>'زیاد','urgent'=>'فوری'][$t['priority']] ?? 'عادی' ?></td>
      <td><span class="fr-chip <?= $c ?>"><?= $l ?></span></td>
      <td style="font-size:12px"><?= fa_num(jdate('j F - H:i', strtotime($t['updated_at']))) ?></td>
      <td class="actions" style="white-space:nowrap">
        <?php if ($viewMode === 'trash'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('آیا از بازیابی این تیکت اطمینان دارید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="restore_ticket">
            <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
            <button class="fr-btn sm ok" style="background:#059669;color:#fff" type="submit">♻️ بازیابی</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('⚠️ حذف نهایی:\n\nآیا از حذف کامل و قطعی این تیکت و پیام‌های آن اطمینان دارید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="purge_ticket">
            <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
            <button class="fr-btn sm danger" type="submit">❌ حذف قطعی</button>
          </form>
        <?php else: ?>
          <a class="fr-btn sm" href="?view=<?= $t['id'] ?>">مشاهده</a>
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
<?php admin_layout_end(); ?>
