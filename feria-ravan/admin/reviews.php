<?php
/**
 * ============================================================================
 *  مدیریت دیدگاهها و امتیازها — نسخهٔ ۲٫۰
 *  ─ تب فهرست: تأیید/رد/ویرایش/حذف + فیلتر کانتکست/وضعیت + جست‌وجو
 *  ─ تب ثبت دستی و ورود فله از اکسل/CSV (قالب: context|item|name|mobile|rating|body|label)
 *  ─ تب لیبل‌ها: شناسه‌های قابل‌شخصی‌سازی کنار نام نویسنده (عنوان + رنگ)
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin','manager']);
require_once __DIR__ . '/../includes/reviews.php';

$CTXS = FR_REVIEW_CTXS;
$tabRaw = (string)($_GET['tab'] ?? 'list');
$tab  = in_array($tabRaw, ['list','add','labels'], true) ? $tabRaw : 'list';
$back = fn(array $extra = []): string => SITE_URL . '/admin/reviews.php?' .
    http_build_query(array_merge(['tab' => $tab], array_filter($_GET, fn($k) => !in_array($k, ['edit','ok'], true), ARRAY_FILTER_USE_KEY), $extra));

/** انتخاب‌گر مورد برای فرم‌ها */
function fr_review_item_opts(string $ctx): array
{
    if ($ctx === 'test') return DB::all('SELECT id, title FROM ' . DB::t('tests') . " WHERE status<>'trash' ORDER BY sort_order");
    return DB::all('SELECT id, title FROM ' . DB::t('events') . ' WHERE kind=? ORDER BY id DESC', [$ctx]);
}

/* ════════════════════════ عملیات POST ════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');

    /* ── تغییر وضعیت/حذف ── */
    if (in_array($act, ['approve','reject','delete'], true)) {
        $id = (int)post('id');
        $r  = DB::one('SELECT * FROM ' . DB::t('reviews') . ' WHERE id=?', [$id]);
        if (!$r) flash('دیدگاه یافت نشد.', 'err');
        else {
            if ($act === 'delete') {
                DB::run('DELETE FROM ' . DB::t('reviews') . ' WHERE id=?', [$id]);
                log_activity('review_del', $r['context'], (int)$r['ref_id'], ['review' => $id]);
                flash('دیدگاه حذف شد 🗑');
            } else {
                DB::update('reviews', ['status' => $act === 'approve' ? 'approved' : 'rejected'], 'id=?', [$id]);
                log_activity('review_' . $act, $r['context'], (int)$r['ref_id'], ['review' => $id]);
                flash($act === 'approve' ? 'دیدگاه تأیید و منتشر شد ✅' : 'دیدگاه رد شد.');
            }
        }
        redirect($back());
    }

    /* ── ۲٫۲: پاسخ مدیریت به دیدگاه/پرسش — عموماً زیر همان نوشته نمایش داده می‌شود ── */
    if ($act === 'reply') {
        $id = (int)post('id');
        $r  = DB::one('SELECT * FROM ' . DB::t('reviews') . ' WHERE id=?', [$id]);
        if (!$r) { flash('دیدگاه یافت نشد.', 'err'); redirect($back()); }
        $reply = mb_substr(trim((string)post('admin_reply')), 0, 2000);
        DB::update('reviews', [
            'admin_reply' => $reply !== '' ? $reply : null,
            'replied_at'  => $reply !== '' ? date('Y-m-d H:i:s') : null,
        ], 'id=?', [$id]);
        log_activity('review_reply', $r['context'], (int)$r['ref_id'], ['review' => $id]);
        flash($reply !== '' ? 'پاسخ مدیریت ذخیره شد و عموماً نمایش داده می‌شود ✅' : 'پاسخ مدیریت پاک شد.');
        redirect($back());
    }

    /* ── ویرایش دیدگاه (مدیر) ── */
    if ($act === 'save') {
        $id = (int)post('id');
        $r  = DB::one('SELECT * FROM ' . DB::t('reviews') . ' WHERE id=?', [$id]);
        if (!$r) { flash('دیدگاه یافت نشد.', 'err'); redirect($back()); }
        /* ۲٫۲: برای پرسش‌ها امتیاز NULL می‌ماند */
        $isQ   = ($r['kind'] ?? 'review') === 'question';
        $rating = $isQ ? null : max(1, min(5, (int)post('rating')));
        $labTxt = trim((string)post('label_text'));
        $labId  = (int)post('label_id') ?: null;
        DB::update('reviews', [
            'author_name' => mb_substr(trim((string)post('author_name')) ?: $r['author_name'], 0, 150),
            'rating'      => $rating,
            'body'        => mb_substr(trim((string)post('body')) ?: $r['body'], 0, 2000),
            'label_id'    => $labId, 'label_text' => $labTxt !== '' ? mb_substr($labTxt, 0, 120) : null,
        ], 'id=?', [$id]);
        log_activity('review_edit', $r['context'], (int)$r['ref_id'], ['review' => $id]);
        flash('دیدگاه به‌روزرسانی شد ✅');
        redirect($back());
    }

    /* ── ثبت دستی توسط مدیر ── */
    if ($act === 'add') {
        $ctx  = (string)post('context');
        $ref  = (int)post('ref_id');
        $name = mb_substr(trim((string)post('author_name')), 0, 150);
        $body = mb_substr(trim((string)post('body')), 0, 2000);
        if (!isset(FR_REVIEW_CTXS[$ctx]) || !$ref) { flash('کانتکست یا مورد معتبر نیست.', 'err'); redirect($back(['tab' => 'add'])); }
        if ($name === '' || $body === '')         { flash('نام و متن دیدگاه اجباری است.', 'err'); redirect($back(['tab' => 'add'])); }
        $mob = en_num(trim((string)post('author_mobile')));
        $uid = ($mob !== '' && valid_mobile($mob))
            ? (int)(DB::val('SELECT id FROM ' . DB::t('users') . ' WHERE mobile=?', [$mob]) ?: 0) ?: null
            : null;
        DB::insert('reviews', [
            'context' => $ctx, 'ref_id' => $ref, 'user_id' => $uid,
            'author_name' => $name, 'author_mobile' => valid_mobile($mob) ? $mob : null,
            'rating' => max(1, min(5, (int)post('rating', 5))),
            'body' => $body,
            'label_id' => (int)post('label_id') ?: null,
            'label_text' => trim((string)post('label_text')) !== '' ? mb_substr(trim((string)post('label_text')), 0, 120) : null,
            'badge_auto' => $uid && fr_review_participated($ctx, $ref, $uid) ? 'participant' : null,
            'source' => 'admin',
            'status' => post('status') === 'pending' ? 'pending' : 'approved',
        ]);
        log_activity('review_add', $ctx, $ref, ['by' => $adm['username']]);
        flash('دیدگاه دستی ثبت شد ✅');
        redirect($back(['tab' => 'list']));
    }

    /* ── ورود فله از اکسل/CSV ── */
    if ($act === 'import') {
        $st = post('status') === 'pending' ? 'pending' : 'approved';
        if (empty($_FILES['sheet']) || !is_uploaded_file($_FILES['sheet']['tmp_name'] ?? '')) {
            flash('فایلی انتخاب نشده است.', 'err'); redirect($back(['tab' => 'add']));
        }
        $tmp  = (string)$_FILES['sheet']['tmp_name'];
        $ext  = strtolower(pathinfo((string)$_FILES['sheet']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','xlsx','xls'], true)) { flash('فقط فایل CSV یا XLSX پذیرفته می‌شود.', 'err'); redirect($back(['tab' => 'add'])); }

        $imp = fr_import_rows($tmp, $ext);
        if (!$imp['ok']) { flash('خطا در خواندن فایل: ' . $imp['error'], 'err'); redirect($back(['tab' => 'add'])); }

        $okN = 0; $errs = [];
        $labelsByTitle = [];
        foreach (fr_review_labels() as $l) $labelsByTitle[$l['title']] = (int)$l['id'];

        foreach ($imp['rows'] as $i => $row) {
            $p = fr_import_parse_row($row);
            /* سرستون: ردیف اول اگر پردازش‌نشدنی و شبیه تیتر بود، بی‌صدا رد می‌شود (۲٫۰٫۱) */
            if ($i === 0 && !$p['ok']
                && preg_match('/context|item|بخش|مورد|نام|امتیاز|متن|لیبل|تست روانشناسی|کارگاه|دوره/u', implode(' ', $row))) continue;
            if (!$p['ok']) { $errs[] = 'ردیف ' . fa_num($i + 1) . ': ' . $p['error']; continue; }
            $d = $p['data'];
            $ref = fr_review_resolve_ref($d['context'], $d['item_key']);
            if (!$ref) { $errs[] = 'ردیف ' . fa_num($i + 1) . ': مورد «' . $d['item_key'] . '» یافت نشد.'; continue; }
            $uid = $d['mobile'] !== '' ? (int)(DB::val('SELECT id FROM ' . DB::t('users') . ' WHERE mobile=?', [$d['mobile']]) ?: 0) ?: null : null;
            $labId = $d['label'] !== '' && isset($labelsByTitle[$d['label']]) ? $labelsByTitle[$d['label']] : null;
            $labTx = $d['label'] !== '' && $labId === null ? mb_substr($d['label'], 0, 120) : null;
            /* تکراریِ دقیق (همان متن/نام/مورد) دوباره درج نشود — idempotent */
            $dup = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('reviews') .
                ' WHERE context=? AND ref_id=? AND author_name=? AND body=? AND COALESCE(author_mobile,\'\')=?',
                [$d['context'], $ref, $d['name'], $d['body'], $d['mobile']]);
            if ($dup) continue;
            DB::insert('reviews', [
                'context' => $d['context'], 'ref_id' => $ref, 'user_id' => $uid,
                'author_name' => $d['name'], 'author_mobile' => $d['mobile'] !== '' ? $d['mobile'] : null,
                'rating' => $d['rating'], 'body' => $d['body'],
                'label_id' => $labId, 'label_text' => $labTx,
                'badge_auto' => $uid && fr_review_participated($d['context'], $ref, $uid) ? 'participant' : null,
                'source' => 'excel', 'status' => $st,
            ]);
            $okN++;
        }
        log_activity('review_import', 'review', 0, ['rows' => $okN, 'errors' => count($errs)]);
        /* ۲٫۰٫۱: خلاصهٔ ورود فله همیشه دیده شود — خطاها داخل همان یک پیام خلاصه می‌شوند
           (قبلاً هر خطا flash جدا می‌خورد و پیام خلاصه را بازنویسی می‌کرد) */
        $impMsg = "ورود فله: " . fa_num($okN) . " دیدگاه ثبت شد ✅"
                . ($errs ? ' — ' . fa_num(count($errs)) . ' ردیف خطا داشت: ' . implode(' | ', array_slice($errs, 0, 3)) : '');
        flash($impMsg, $errs ? 'warn' : 'ok');
        redirect($back(['tab' => 'list']));
    }

    /* ── لیبل‌ها ── */
    if ($act === 'label_save') {
        $id    = (int)post('id');
        $title = mb_substr(trim((string)post('title')), 0, 120);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)post('color')) ? (string)post('color') : '#0c7a4d';
        $bg    = preg_match('/^#[0-9a-fA-F]{6}$/', (string)post('bg')) ? (string)post('bg') : '#e9f9f1';
        if ($title === '') { flash('عنوان لیبل اجباری است.', 'err'); redirect($back(['tab' => 'labels'])); }
        $data = ['title' => $title, 'color' => $color, 'bg' => $bg, 'sort_order' => (int)post('sort_order', 0)];
        if ($id) { DB::update('review_labels', $data, 'id=?', [$id]); flash('لیبل به‌روزرسانی شد ✅'); }
        else     { DB::insert('review_labels', $data); flash('لیبل جدید ساخته شد ✅'); }
        redirect($back(['tab' => 'labels']));
    }
    if ($act === 'label_del') {
        $id = (int)post('id');
        DB::run('UPDATE ' . DB::t('reviews') . ' SET label_id=NULL WHERE label_id=?', [$id]);
        DB::run('DELETE FROM ' . DB::t('review_labels') . ' WHERE id=?', [$id]);
        flash('لیبل حذف شد (دیدگاه‌هایش بدون لیبل مانع نیستند).');
        redirect($back(['tab' => 'labels']));
    }
}

/* ════════════════════════ داده‌های نمایش ════════════════════════ */
$fCtx = in_array(($_GET['ctx'] ?? ''), array_keys($CTXS), true) ? (string)$_GET['ctx'] : '';
$fSt  = in_array(($_GET['status'] ?? ''), ['pending','approved','rejected'], true) ? (string)$_GET['status'] : '';
$fRef = (int)($_GET['ref_id'] ?? 0);
$fq   = trim((string)($_GET['q'] ?? ''));

$rows = []; $sum = [];
if ($tab === 'list') {
    $w = '1=1'; $p = [];
    if ($fCtx) { $w .= ' AND r.context=?'; $p[] = $fCtx; }
    if ($fRef) { $w .= ' AND r.ref_id=?';  $p[] = $fRef; }
    if ($fSt)  { $w .= ' AND r.status=?';  $p[] = $fSt; }
    if ($fq !== '') { $w .= ' AND (r.author_name LIKE ? OR r.body LIKE ? OR r.author_mobile LIKE ?)';
                      $like = '%' . $fq . '%'; $p = array_merge($p, [$like, $like, $like]); }
    $rows = DB::all('SELECT r.*, l.title AS lbl_title, l.color AS lbl_color, l.bg AS lbl_bg
                     FROM ' . DB::t('reviews') . ' r LEFT JOIN ' . DB::t('review_labels') . ' l ON l.id=r.label_id
                     WHERE ' . $w . ' ORDER BY r.id DESC LIMIT 300', $p);
    $sum = [
        'pending'  => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('reviews') . " WHERE status='pending'"),
        'approved' => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('reviews') . " WHERE status='approved'"),
        'avg'      => round((float)DB::val("SELECT COALESCE(AVG(rating),0) FROM " . DB::t('reviews') . " WHERE status='approved' AND kind='review' AND rating IS NOT NULL"), 1),
        'today'    => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('reviews') . " WHERE DATE(created_at)=CURDATE()"),
        'openq'    => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('reviews') . " WHERE kind='question' AND status='approved' AND (admin_reply IS NULL OR admin_reply='')"),
    ];
}
$editId = (int)($_GET['edit'] ?? 0);
$eRv = $editId ? DB::one('SELECT * FROM ' . DB::t('reviews') . ' WHERE id=?', [$editId]) : null;

admin_layout($adm, 'reviews', 'مدیریت دیدگاه‌ها');
echo flash();
?>
<div class="fr-card">
  <h3>⭐ دیدگاه‌ها و امتیازها <small style="color:var(--muted);font-weight:400">(ثبت توسط شرکت‌کنندگان + ورود فله از اکسل + لیبل‌های سفارشی — ۲٫۰)</small></h3>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px">
    <a class="fr-chip <?= $tab === 'list' ? 'purple' : 'info' ?>" href="?tab=list" style="text-decoration:none">📋 فهرست دیدگاه‌ها</a>
    <a class="fr-chip <?= $tab === 'add' ? 'purple' : 'info' ?>" href="?tab=add" style="text-decoration:none">➕ ثبت دستی / ورود اکسل</a>
    <a class="fr-chip <?= $tab === 'labels' ? 'purple' : 'info' ?>" href="?tab=labels" style="text-decoration:none">🏷 لیبل‌های افراد</a>
  </div>
  <div class="fr-alert info" style="font-size:12.5px;line-height:2">
    نحوهٔ کار (۲٫۲): <b>همهٔ کاربران واردشده</b> می‌توانند روی صفحهٔ عمومی هر مورد نظر ⭐ یا پرسش ❓ ثبت کنند —
    شرکت‌کننده‌ها برچسب «شرکت‌کننده» و بقیه «کاربر مهمان» می‌گیرند. به پرسش‌ها از ستون «پاسخ» جواب بدهید تا زیر همان پرسش
    عموماً نمایش داده شود. کنار نام هر نفر، <b>لیبل</b> او نمایش داده می‌شود —
    «شرکت‌کننده» برای مشارکت‌کنندگان واقعی، «مدیر سایت» برای دیدگاه‌های شما، یا هر لیبل سفارشی که در تب
    «🏷 لیبل‌های افراد» بسازید. وضعیت انتشار خودکار: <b><?= DB::setting('reviews_auto_approve','1') === '1' ? 'روشن (بلافاصله منتشر می‌شود)' : 'خاموش (تأیید مدیر لازم است)' ?></b>
    — از <a href="settings.php?tab=reviews">تنظیمات ← دیدگاه‌ها</a> قابل تغییر است.
  </div>
</div>

<?php if ($tab === 'list'): ?>
<div class="fr-grid cols-4" style="margin-bottom:10px">
  <?php stat_card('⏳', 'در انتظار تأیید', fa_num($sum['pending']), '#fff6e6'); ?>
  <?php stat_card('✅', 'منتشرشده', fa_num($sum['approved']), '#ecfbf4'); ?>
  <?php stat_card('⭐', 'میانگین کلی امتیاز', fa_num($sum['avg']), '#f6f1ff'); ?>
  <?php stat_card('🆕', 'ثبت امروز', fa_num($sum['today']), '#eaf4ff'); ?>
  <?php stat_card('❓', 'پرسش‌های بی‌پاسخ', fa_num($sum['openq']), '#fff3d6'); ?>
</div>

<?php if ($eRv): ?>
<div class="fr-card" style="border:2px solid var(--c-primary)">
  <h3>✏️ ویرایش دیدگاه #<?= fa_num((int)$eRv['id']) ?> — <?= e($eRv['author_name']) ?></h3>
  <form method="post" class="fr-grid cols-2">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)$eRv['id'] ?>">
    <div>
      <div class="fr-field"><label>نام نویسنده</label><input class="fr-input" name="author_name" value="<?= e($eRv['author_name']) ?>"></div>
      <div class="fr-field"><label>امتیاز</label>
        <?php if (($eRv['kind'] ?? 'review') === 'question'): ?>
          <div class="fr-alert info" style="font-size:12px;margin:0">این نوشته «پرسش» است و امتیاز ندارد.</div>
        <?php else: ?>
        <select class="fr-select" name="rating">
          <?php for ($i = 5; $i >= 1; $i--): ?>
          <option value="<?= $i ?>" <?= (int)$eRv['rating'] === $i ? 'selected' : '' ?>><?= fa_num($i) ?> ستاره <?= str_repeat('★', $i) ?></option>
          <?php endfor; ?>
        </select>
        <?php endif; ?>
      </div>
      <div class="fr-field"><label>متن دیدگاه</label><textarea class="fr-textarea" name="body" rows="4"><?= e($eRv['body']) ?></textarea></div>
    </div>
    <div>
      <div class="fr-field"><label>لیبل از فهرست</label>
        <select class="fr-select" name="label_id">
          <option value="0">— خودکار (شرکت‌کننده/مدیر) —</option>
          <?php foreach (fr_review_labels() as $l): ?>
          <option value="<?= (int)$l['id'] ?>" <?= (int)$eRv['label_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['title']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fr-field"><label>لیبل دست‌نویس (اگر پر شود، بر فهرست می‌برد)</label>
        <input class="fr-input" name="label_text" value="<?= e($eRv['label_text'] ?? '') ?>" placeholder="مثلاً: سرمایه‌گذار دوره"></div>
      <button class="fr-btn">ذخیره ✅</button>
      <a class="fr-btn ghost" href="<?= e($back(['edit' => null])) ?>">انصراف</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="fr-card">
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
    <h3 style="margin:0">فهرست دیدگاه‌ها</h3>
    <form method="get" action="reviews.php" style="display:flex;gap:6px;flex-wrap:wrap;margin-right:auto">
      <input type="hidden" name="tab" value="list">
      <select class="fr-select" name="ctx" style="width:auto">
        <option value="">همهٔ بخش‌ها</option>
        <?php foreach ($CTXS as $k => $v): ?><option value="<?= $k ?>" <?= $fCtx === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
      <select class="fr-select" name="status" style="width:auto">
        <option value="">همهٔ وضعیت‌ها</option>
        <option value="pending" <?= $fSt === 'pending' ? 'selected' : '' ?>>⏳ در انتظار</option>
        <option value="approved" <?= $fSt === 'approved' ? 'selected' : '' ?>>✅ منتشرشده</option>
        <option value="rejected" <?= $fSt === 'rejected' ? 'selected' : '' ?>>🚫 ردشده</option>
      </select>
      <input class="fr-input" name="q" style="width:180px" placeholder="جست‌وجوی نام/متن/موبایل" value="<?= e($fq) ?>">
      <button class="fr-btn sm ghost">🔎</button>
    </form>
  </div>
  <div style="overflow-x:auto"><table class="fr-table">
    <thead><tr><th>#</th><th>نویسنده</th><th>مورد/بخش</th><th>نوع</th><th>امتیاز</th><th>متن</th><th>لیبل</th><th>پاسخ مدیریت</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px 0">دیدگاهی یافت نشد.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $lab = fr_review_label($r); ?>
    <tr>
      <td><?= fa_num((int)$r['id']) ?></td>
      <td><b><?= e($r['author_name']) ?></b><?= $r['author_mobile'] ? '<br><small dir="ltr" style="color:var(--muted)">' . e(fa_num($r['author_mobile'])) . '</small>' : '' ?></td>
      <td style="font-size:12px"><span class="fr-chip <?= $r['context'] === 'test' ? 'purple' : 'info' ?>"><?= e($CTXS[$r['context']] ?? $r['context']) ?></span><br>
        <b><?= e(fr_review_item_title($r['context'], (int)$r['ref_id'])) ?></b></td>
      <td><?= ($r['kind'] ?? 'review') === 'question' ? '<span class="fr-chip warn" style="font-size:11px">❓ پرسش</span>' : '<span class="fr-chip" style="font-size:11px">⭐ نظر</span>' ?><br>
        <small style="color:var(--muted)"><?= ['user' => '🙋 کاربر', 'admin' => '🛡 مدیر', 'excel' => '📊 اکسل'][$r['source']] ?></small></td>
      <td style="white-space:nowrap"><?= $r['rating'] !== null && ($r['kind'] ?? 'review') !== 'question' ? fr_stars_html((float)$r['rating'], '13px') : '<span style="color:var(--muted);font-size:12px">—</span>' ?></td>
      <td style="max-width:240px;font-size:12.5px;line-height:1.9"><?= e(mb_strimwidth($r['body'], 0, 110, '…')) ?></td>
      <td><span class="fr-chip" style="background:<?= e($lab['bg']) ?>;color:<?= e($lab['color']) ?>"><?= e($lab['title']) ?></span></td>
      <td style="min-width:170px">
        <?php $hasReply = trim((string)($r['admin_reply'] ?? '')) !== ''; ?>
        <?php if ($hasReply): ?>
        <div style="font-size:11.5px;background:#f6f8ff;border:1px dashed #c9d4ff;border-radius:8px;padding:5px 8px;margin-bottom:5px;max-width:210px">
          <?= e(mb_strimwidth((string)$r['admin_reply'], 0, 90, '…')) ?>
        </div>
        <?php endif; ?>
        <button type="button" class="fr-btn sm ghost" style="font-size:11px"
          onclick="var f=document.getElementById('rpl-<?= (int)$r['id'] ?>');f.style.display=f.style.display==='none'?'block':'none';return false">
          <?= $hasReply ? '✏️ ویرایش پاسخ' : '💬 پاسخ' ?></button>
        <form method="post" id="rpl-<?= (int)$r['id'] ?>" style="display:none;margin-top:5px">
          <?= csrf_field() ?><input type="hidden" name="act" value="reply"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <textarea class="fr-textarea" name="admin_reply" rows="2" maxlength="2000" placeholder="پاسخ مدیریت — عموماً زیر همین نوشته دیده می‌شود" style="font-size:11.5px;min-width:200px"><?= e((string)($r['admin_reply'] ?? '')) ?></textarea>
          <div style="display:flex;gap:5px;margin-top:4px"><button class="fr-btn sm accent" style="font-size:11px">ذخیره پاسخ</button>
          <?php if ($hasReply): ?><button class="fr-btn sm ghost" style="font-size:11px" name="clear_reply" value="1" onclick="this.form.admin_reply.value=''">پاک‌سازی</button><?php endif; ?></div>
        </form>
      </td>
      <td><span class="fr-chip <?= $r['status'] === 'approved' ? 'ok' : ($r['status'] === 'pending' ? 'warn' : 'err') ?>">
        <?= ['approved' => 'منتشرشده', 'pending' => 'در انتظار', 'rejected' => 'ردشده'][$r['status']] ?></span></td>
      <td><?= fa_num(jdate('j F', strtotime($r['created_at']))) ?></td>
      <td class="actions" style="white-space:nowrap">
        <?php if ($r['status'] !== 'approved'): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="approve"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="fr-btn sm ok" title="تأیید و انتشار">✓</button></form>
        <?php else: ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="reject"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="fr-btn sm ghost" title="رد/پایین‌کشیدن">✕</button></form>
        <?php endif; ?>
        <a class="fr-btn sm ghost" href="<?= e($back(['edit' => (int)$r['id']])) ?>">ویرایش</a>
        <form method="post" style="display:inline" onsubmit="return confirm('حذف این دیدگاه؟')">
          <?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="fr-btn sm danger">حذف</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 300): ?><small class="hint">نمایش ۳۰۰ رکورد آخر — از فیلترها استفاده کنید.</small><?php endif; ?>
</div>

<?php elseif ($tab === 'add'): ?>
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3>➕ ثبت دیدگاه دستی</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="add">
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>بخش</label>
          <select class="fr-select" name="context" id="addCtx" onchange="frFillItems()">
            <?php foreach ($CTXS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
          </select></div>
        <div class="fr-field"><label>مورد</label>
          <select class="fr-select" name="ref_id" id="addRef"></select></div>
        <div class="fr-field"><label>نام نویسنده *</label><input class="fr-input" name="author_name" required></div>
        <div class="fr-field"><label>موبایل (اختیاری — اگر کاربر سایت باشد متصل می‌شود)</label>
          <input class="fr-input" name="author_mobile" inputmode="numeric" maxlength="11" style="direction:ltr" placeholder="09xxxxxxxxx"></div>
        <div class="fr-field"><label>امتیاز</label>
          <select class="fr-select" name="rating">
            <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= fa_num($i) ?> ★</option><?php endfor; ?>
          </select></div>
        <div class="fr-field"><label>وضعیت انتشار</label>
          <select class="fr-select" name="status"><option value="approved">✅ بلافاصله منتشر شود</option><option value="pending">⏳ در انتظار تأیید</option></select></div>
      </div>
      <div class="fr-field"><label>متن دیدگاه *</label><textarea class="fr-textarea" name="body" rows="4" required></textarea></div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>لیبل</label>
          <select class="fr-select" name="label_id">
            <option value="0">— خودکار (شرکت‌کننده/مدیر) —</option>
            <?php foreach (fr_review_labels() as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['title']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="fr-field"><label>لیبل دست‌نویس (بر فهرست می‌برد)</label>
          <input class="fr-input" name="label_text" placeholder="مثلاً: مشاور برنامه"></div>
      </div>
      <button class="fr-btn">ثبت دیدگاه ✅</button>
    </form>
  </div>
  <div class="fr-card" style="margin:0">
    <h3>📊 ورود فله از اکسل / CSV</h3>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="act" value="import">
      <div class="fr-field"><label>فایل (CSV یا XLSX)</label>
        <input class="fr-input" type="file" name="sheet" accept=".csv,.xlsx,.xls" required></div>
      <div class="fr-field"><label>وضعیت رکوردهای ورودی</label>
        <select class="fr-select" name="status"><option value="approved">✅ بلافاصله منتشر شوند</option><option value="pending">⏳ در انتظار تأیید (بعداً تکی تأیید کنید)</option></select></div>
      <button class="fr-btn">شروع ورود فله 📥</button>
    </form>
    <hr style="border:none;border-top:1.5px dashed #d4dcec;margin:16px 0">
    <h4 style="margin:6px 0">قالب ستون‌های فایل (۶ ستون + سرستون دلخواه):</h4>
    <div style="overflow-x:auto"><table class="fr-table" style="font-size:12px">
      <tr><th>۱. بخش</th><td>test / workshop / course (یا فارسی: تست روانشناسی, کارگاه, دوره — برچسب قدیمی «آزمون» هم شناخته می‌شود)</td></tr>
      <tr><th>۲. مورد</th><td>اسلاگ یا شناسه — مثل <code dir="ltr">gardner-mi</code> یا <code dir="ltr">12</code></td></tr>
      <tr><th>۳. نام نویسنده</th><td>نام و نام خانوادگی</td></tr>
      <tr><th>۴. موبایل</th><td>اختیاری — برای اتصال به حساب کاربر</td></tr>
      <tr><th>۵. امتیاز</th><td>عدد ۱ تا ۵</td></tr>
      <tr><th>۶. متن دیدگاه</th><td>تا ۲۰۰۰ نویسه</td></tr>
      <tr><th>۷. لیبل</th><td>اختیاری — عنوان یکی از لیبل‌ها یا متن آزاد جدید</td></tr>
    </table></div>
    <p class="hint">ردیف‌های تکراریِ دقیق (همان مورد + نام + متن) خودکار رد می‌شوند تا اجرای دوبارهٔ فایل دوبله نسازد.
      <a href="data:text/csv;charset=utf-8,%EF%BB%BF<?= rawurlencode("context,item,name,mobile,rating,body,label\ntest,gardner-mi,سارا محمدی,09120001111,5,فوق‌العاده بود، تحلیل‌ها عمیق و کاربردی,شرکت‌کننده\nworkshop,3,علی رضایی,09120002222,4,کارگاه عالی و کاربردی بود,شرکت‌کننده") ?>">⬇️ دانلود نمونهٔ آماده CSV</a></p>
  </div>
</div>
<script>
var FR_ITEMS = <?= json_encode(array_map(fn($k) => fr_review_item_opts($k), array_keys($CTXS)), JSON_UNESCAPED_UNICODE) ?>;
function frFillItems(){
  var ctx = document.getElementById('addCtx').value;
  var sel = document.getElementById('addRef'); sel.innerHTML = '';
  (FR_ITEMS[ctx] || []).forEach(function(it){
    var o = document.createElement('option'); o.value = it.id; o.textContent = it.title; sel.appendChild(o);
  });
}
frFillItems();
</script>

<?php else: /* ── تب لیبل‌ها ── */ ?>
<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3>🏷 لیبل‌های کنار نام افراد <small style="color:var(--muted);font-weight:400">(کاملاً قابل شخصی‌سازی)</small></h3>
    <table class="fr-table">
      <thead><tr><th>عنوان</th><th>نمایش</th><th>رنگ متن</th><th>پس‌زمینه</th><th>ترتیب</th><th></th></tr></thead>
      <tbody>
      <?php foreach (fr_review_labels() as $l): ?>
      <tr>
        <td><b><?= e($l['title']) ?></b></td>
        <td><span class="fr-chip" style="background:<?= e($l['bg']) ?>;color:<?= e($l['color']) ?>"><?= e($l['title']) ?></span></td>
        <td><code dir="ltr"><?= e($l['color']) ?></code></td>
        <td><code dir="ltr"><?= e($l['bg']) ?></code></td>
        <td><?= fa_num($l['sort_order']) ?></td>
        <td class="actions" style="white-space:nowrap">
          <details style="display:inline-block">
            <summary class="fr-btn sm ghost" style="cursor:pointer;list-style:none">ویرایش</summary>
            <form method="post" style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;align-items:center">
              <?= csrf_field() ?><input type="hidden" name="act" value="label_save"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <input class="fr-input" name="title" value="<?= e($l['title']) ?>" style="width:120px">
              <input class="fr-input" name="color" value="<?= e($l['color']) ?>" style="width:80px;direction:ltr" title="رنگ متن">
              <input class="fr-input" name="bg" value="<?= e($l['bg']) ?>" style="width:80px;direction:ltr" title="رنگ پس‌زمینه">
              <input class="fr-input" name="sort_order" value="<?= fa_num($l['sort_order']) ?>" style="width:54px" title="ترتیب">
              <button class="fr-btn sm">✓</button>
            </form>
          </details>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف لیبل؟ دیدگاه‌هایش به حالت خودکار برمی‌گردند.')">
            <?= csrf_field() ?><input type="hidden" name="act" value="label_del"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="fr-btn sm danger">حذف</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="fr-card" style="margin:0">
    <h3>➕ لیبل جدید</h3>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="label_save">
      <div class="fr-field"><label>عنوان لیبل *</label><input class="fr-input" name="title" placeholder="مثلاً: مدیر مجموعه، دانشجوی دوره…" required></div>
      <div class="fr-grid cols-3" style="gap:12px">
        <div class="fr-field"><label>رنگ متن</label><input class="fr-input" type="color" name="color" value="#0c7a4d" style="height:42px;padding:4px"></div>
        <div class="fr-field"><label>رنگ پس‌زمینه</label><input class="fr-input" type="color" name="bg" value="#e9f9f1" style="height:42px;padding:4px"></div>
        <div class="fr-field"><label>ترتیب نمایش</label><input class="fr-input" name="sort_order" value="۱۰"></div>
      </div>
      <button class="fr-btn">ساخت لیبل ✅</button>
      <p class="hint" style="margin-top:14px">برچسب‌های «شرکت‌کننده» و «مدیر سایت» به‌صورت خودکار بر اساس مشارکت واقعی نفر محاسبه می‌شوند؛
      لیبل‌های این‌جا برای دسته‌بندی‌های دستی شماست (تکی در فرم ثبت دیدگاه، یا فله‌ای از ستون «لیبل» فایل اکسل).</p>
    </form>
  </div>
</div>
<?php endif; ?>
<?php admin_layout_end(); ?>
