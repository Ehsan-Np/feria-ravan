<?php
/** تیکت‌های پشتیبانی — ثبت و گفت‌وگو (نسخهٔ ۱٫۷: پیوست تصویر + بهبود چیدمان) */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
fr_bootstrap();
$u = require_user();
fr_panel_guard('tickets');
$msg = '';

/* ثبت تیکت جدید — موضوع/متن + پیوست تصویری اختیاری */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'new') {
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
            redirect(SITE_URL . '/panel/ticket-view.php?id=' . $tid);
        }
    }
}

$tickets = DB::all('SELECT * FROM ' . DB::t('tickets') . ' WHERE user_id=? ORDER BY id DESC', [$u['id']]);
$statusMap = ['open'=>['info','باز'],'answered'=>['ok','پاسخ داده شد'],'user_reply'=>['warn','در انتظار پاسخ'],'closed'=>['err','بسته']];

render_header('تیکت پشتیبانی');
panel_layout($u, 'tickets');
echo $msg;
?>
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3>تیکت‌های شما</h3>
    <?php if (!$tickets): ?><p class="fr-alert info" style="margin:0">تیکتی ثبت نشده است.</p>
    <?php else: ?>
    <table class="fr-table">
      <thead><tr><th>#</th><th>موضوع</th><th>وضعیت</th><th>بروزرسانی</th></tr></thead>
      <tbody>
      <?php foreach ($tickets as $t): [$c,$l] = $statusMap[$t['status']]; ?>
        <tr>
          <td><?= fa_num($t['id']) ?></td>
          <td><a href="ticket-view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['subject']) ?></b></a></td>
          <td><span class="fr-chip <?= $c ?>"><?= $l ?></span></td>
          <td><?= fa_num(jdate('j F Y', strtotime($t['updated_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
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
