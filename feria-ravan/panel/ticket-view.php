<?php
/** مشاهده و پاسخ به یک تیکت — نسخهٔ ۱٫۷: پیوست تصویر در هر پاسخ */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
fr_bootstrap();
$u = require_user();
fr_panel_guard('tickets');

$t = DB::one('SELECT * FROM ' . DB::t('tickets') . ' WHERE id=? AND user_id=?', [(int)($_GET['id'] ?? 0), $u['id']]);
if (!$t) redirect(fr_panel_url('tickets.php'));

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $t['status'] !== 'closed') {
    $body = trim((string)($_POST['body'] ?? ''));
    $attach = fr_ticket_attach_save($_FILES['attach'] ?? []);
    if (!$attach['ok']) {
        $err = $attach['error'] ?? 'آپلود پیوست ناموفق بود.';
    } elseif ($body === '' && !$attach['path']) {
        $err = 'متن پاسخ یا تصویرِ پیوست، حداقل یکی لازم است.';
    } else {
        DB::insert('ticket_replies', ['ticket_id' => $t['id'], 'author_id' => $u['id'], 'body' => $body,
            'attachment' => $attach['path']]);
        DB::update('tickets', ['status' => 'user_reply'], 'id=?', [$t['id']]);
        redirect(fr_panel_url('ticket-view.php?id=' . $t['id']));
    }
}

$replies = DB::all('SELECT r.*, IF(r.is_admin=1,"پشتیبانی",?) AS author FROM ' . DB::t('ticket_replies') .
                   ' r WHERE r.ticket_id=? ORDER BY r.id', [user_fullname($u), $t['id']]);

render_header('تیکت: ' . $t['subject']);
panel_layout($u, 'tickets');
?>
<div class="fr-card">
  <h3><?= e($t['subject']) ?>
    <span class="fr-chip <?= ['open'=>'info','answered'=>'ok','user_reply'=>'warn','closed'=>'err'][$t['status']] ?>">
      <?= ['open'=>'باز','answered'=>'پاسخ داده شد','user_reply'=>'در انتظار پاسخ','closed'=>'بسته'][$t['status']] ?></span></h3>
  <?php if ($err): ?><div class="fr-alert err"><?= e($err) ?></div><?php endif; ?>
  <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px">
    <?php foreach ($replies as $r): ?>
    <div style="max-width:82%;background:<?= $r['is_admin']?'#eaf0f8':'#ecfbf4' ?>;border-radius:16px;padding:14px 18px;
         align-self:<?= $r['is_admin']?'flex-start':'flex-end' ?>">
      <div style="font-size:12px;color:var(--muted);margin-bottom:4px">
        <?= e($r['author']) ?> · <?= fa_num(jdate('j F Y - H:i', strtotime($r['created_at']))) ?></div>
      <?php if (trim((string)$r['body']) !== ''): ?><div style="font-size:14px"><?= nl2br(e($r['body'])) ?></div><?php endif; ?>
      <?= fr_ticket_attach_html($r['attachment'] ?? null) ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($t['status'] !== 'closed'): ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="fr-field"><textarea class="fr-textarea" name="body" rows="4" placeholder="پاسخ شما..."></textarea></div>
    <div class="fr-field" style="margin-top:-6px">
      <label style="font-size:12px;color:var(--muted)">🖼 پیوست تصویر (اختیاری)</label>
      <input class="fr-input" type="file" name="attach" accept="image/jpeg,image/png,image/webp" style="font-size:12px">
    </div>
    <button class="fr-btn">ارسال پاسخ</button>
    <a class="fr-btn ghost" href="tickets.php">بازگشت</a>
  </form>
  <?php else: ?>
    <a class="fr-btn ghost" href="tickets.php">بازگشت</a>
  <?php endif; ?>
</div>
<?php panel_layout_end(); render_footer(); ?>
