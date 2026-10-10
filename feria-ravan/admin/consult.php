<?php
/**
 * =============================================================================
 *  کلینیک مشاوره — مدیریت مشاوران و خدمات (نسخهٔ ۱٫۴)
 *  ─ مشاور: نام، عنوان تخصصی، موبایل (اطلاع‌رسانی رزروها)، بیو، آواتار
 *  ─ خدمت: اسلاگ عمومی، قیمت/مدت/شیوه، توضیحات WYSIWYG، تصویر، خلاصه
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/includes/upload.php';
require_once FR_ROOT . '/includes/sanitize.php';
require_once FR_ROOT . '/includes/consult.php';
$adm = require_admin(['superadmin','manager']);

/* ============================================================================
 | اقدامات POST
 * ==========================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');

    /* ── مشاور ── */
    if ($act === 'save_consultant') {
        $id = (int)post('id');
        $data = [
            'name'   => trim((string)post('name')),
            'title'  => trim((string)post('title')) ?: null,
            'mobile' => en_num(trim((string)post('mobile'))) ?: null,
            'bio'    => trim((string)post('bio')) ?: null,
            'sort'   => (int)en_num((string)post('sort','0')),
            'active' => (int)post('active') === 1 ? 1 : 0,
        ];
        if ($data['mobile'] && !valid_mobile($data['mobile'])) {
            flash('موبایل مشاور معتبر نیست (الگوی 09xxxxxxxxx).', 'err'); redirect(SITE_URL . '/admin/consult.php?tab=consultants');
        }
        if ($id) DB::update('consultants', $data, 'id=?', [$id]);
        else     $id = DB::insert('consultants', $data);

        if (!empty($_FILES['avatar']) && (int)($_FILES['avatar']['error'] ?? 4) !== 4) {
            $up = fr_save_image($_FILES['avatar'], 'avatars', 'consultant', $id);
            if ($up['ok']) {
                $old = DB::one('SELECT avatar FROM ' . DB::t('consultants') . ' WHERE id=?', [$id]);
                if ($old && $old['avatar'] && $old['avatar'] !== $up['path']) fr_delete_image($old['avatar']);
                DB::update('consultants', ['avatar'=>$up['path']], 'id=?', [$id]);
            } else flash('مشاور ذخیره شد؛ آواتار آپلود نشد: ' . $up['error'], 'warn');
        }
        log_activity('admin_consultant_save', 'consultant', $id);
        flash('مشاور ذخیره شد ✅'); redirect(SITE_URL . '/admin/consult.php?tab=consultants');
    }
    if ($act === 'del_consultant') {
        // حذف مشاور → خدمات و اسلات‌هایش با FK آبشاری حذف می‌شوند
        $id = (int)post('id');
        $used = DB::val('SELECT COUNT(*) FROM ' . DB::t('consult_appointments') . ' WHERE consultant_id=?', [$id]);
        if ($used) { flash('برای این مشاور نوبت ثبت شده است؛ به‌جای حذف، غیرفعالش کنید.', 'err'); }
        else {
            $row = DB::one('SELECT avatar FROM ' . DB::t('consultants') . ' WHERE id=?', [$id]);
            if ($row && $row['avatar']) fr_delete_image($row['avatar']);
            DB::run('DELETE FROM ' . DB::t('consultants') . ' WHERE id=?', [$id]);
            flash('مشاور حذف شد.');
        }
        redirect(SITE_URL . '/admin/consult.php?tab=consultants');
    }

    /* ── خدمت ── */
    if ($act === 'save_service') {
        $id  = (int)post('id');
        $cid = (int)post('consultant_id');
        if (!DB::val('SELECT id FROM ' . DB::t('consultants') . ' WHERE id=?', [$cid])) {
            flash('مشاور انتخاب‌شده معتبر نیست.', 'err'); redirect(SITE_URL . '/admin/consult.php?tab=services');
        }
        $title = trim((string)post('title'));
        $slug  = fr_unique_slug('consult_services', fr_slugify((string)post('slug') !== '' ? (string)post('slug') : $title), 'slug', $id);
        $mode  = in_array(post('mode'), ['online','inperson','both'], true) ? post('mode') : 'inperson';
        $data  = [
            'consultant_id' => $cid,
            'slug'          => $slug,
            'title'         => $title,
            'short_desc'    => mb_substr(trim((string)post('short_desc')), 0, 250),
            'description'   => fr_sanitize_html((string)post('description')),
            'price'         => (int)preg_replace('/\D/','', en_num((string)post('price','0'))),
            'duration_min'  => max(15, min(240, (int)en_num((string)post('duration_min','45')))),
            'mode'          => $mode,
            'meeting_hint'  => mb_substr(trim((string)post('meeting_hint')), 0, 250) ?: null,
            'sort'          => (int)en_num((string)post('sort','0')),
            'active'        => (int)post('active') === 1 ? 1 : 0,
        ];
        // تأخیر/استراحت بین جلسات (نسخهٔ ۱٫۶) — اگر ستون هنوز ارتقا نیافته باشد نادیده گرفته می‌شود
        $gap = max(0, min(180, (int)en_num((string)post('gap_minutes','0'))));
        try {
            if (DB::val("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='gap_minutes'", [DB::t('consult_services')])) {
                $data['gap_minutes'] = $gap;
            }
        } catch (Throwable $e) {}
        /* ۳٫۰: پیش‌پرداخت (بیعانه) — فقط وقتی ذخیره می‌شود که مثبت و از قیمت کمتر باشد */
        try {
            $depEn  = (int)post('deposit_enabled') === 1 ? 1 : 0;
            $depAmt = (int)preg_replace('/\D/', '', en_num((string)post('deposit_amount', '')));
            $data['deposit_enabled'] = ($depEn === 1 && $depAmt > 0 && (int)$data['price'] > 0 && $depAmt < (int)$data['price']) ? 1 : 0;
            $data['deposit_amount']  = $data['deposit_enabled'] === 1 ? $depAmt : null;
        } catch (Throwable $e) {}
        if ($id) DB::update('consult_services', $data, 'id=?', [$id]);
        else     $id = DB::insert('consult_services', $data);

        if ((int)post('remove_image') === 1) {
            $old = DB::one('SELECT image FROM ' . DB::t('consult_services') . ' WHERE id=?', [$id]);
            if ($old && $old['image']) { fr_delete_image($old['image']); DB::update('consult_services', ['image'=>null], 'id=?', [$id]); }
        }
        if (!empty($_FILES['image']) && (int)($_FILES['image']['error'] ?? 4) !== 4) {
            $up = fr_save_image($_FILES['image'], 'services', 'service', $id);
            if ($up['ok']) {
                $old = DB::one('SELECT image FROM ' . DB::t('consult_services') . ' WHERE id=?', [$id]);
                if ($old && $old['image']) fr_delete_image($old['image']);
                DB::update('consult_services', ['image'=>$up['path']], 'id=?', [$id]);
            } else flash('خدمت ذخیره شد؛ تصویر آپلود نشد: ' . $up['error'], 'warn');
        }
        log_activity('admin_service_save', 'consult_service', $id);
        flash('خدمت ذخیره شد ✅'); redirect(SITE_URL . '/admin/consult.php?tab=services');
    }
    if ($act === 'del_service') {
        $id   = (int)post('id');
        $used = DB::val('SELECT COUNT(*) FROM ' . DB::t('consult_appointments') . ' WHERE service_id=?', [$id]);
        if ($used) { flash('برای این خدمت نوبت ثبت شده است؛ به‌جای حذف، غیرفعالش کنید.', 'err'); }
        else {
            $row = DB::one('SELECT image FROM ' . DB::t('consult_services') . ' WHERE id=?', [$id]);
            if ($row && $row['image']) fr_delete_image($row['image']);
            DB::run('DELETE FROM ' . DB::t('consult_services') . ' WHERE id=?', [$id]);
            flash('خدمت حذف شد.');
        }
        redirect(SITE_URL . '/admin/consult.php?tab=services');
    }
}

/* ============================================================================
 | داده‌ها + رندر
 * ==========================================================================*/
$tab = (string)($_GET['tab'] ?? 'services');
if (!in_array($tab, ['services','consultants'], true)) $tab = 'services';

$consultants = DB::all('SELECT c.*, (SELECT COUNT(*) FROM ' . DB::t('consult_services') . ' s WHERE s.consultant_id=c.id) AS services
                        FROM ' . DB::t('consultants') . ' c ORDER BY c.sort, c.id');
$services    = DB::all('SELECT s.*, c.name AS cname,
                           (SELECT COUNT(*) FROM ' . DB::t('consult_slots') . ' sl WHERE sl.service_id=s.id AND sl.status=\'open\' AND sl.starts_at>NOW()) AS open_slots,
                           (SELECT COUNT(*) FROM ' . DB::t('consult_appointments') . ' a WHERE a.service_id=s.id AND a.status=\'booked\') AS booked
                        FROM ' . DB::t('consult_services') . ' s
                        JOIN ' . DB::t('consultants') . ' c ON c.id=s.consultant_id
                        ORDER BY s.sort, s.id');

$editC = ($e = (int)($_GET['edit_c'] ?? 0)) ? DB::one('SELECT * FROM ' . DB::t('consultants') . ' WHERE id=?', [$e]) : null;
$editS = ($e = (int)($_GET['edit_s'] ?? 0)) ? DB::one('SELECT * FROM ' . DB::t('consult_services') . ' WHERE id=?', [$e]) : null;
$modeFa = ['online'=>'💻 آنلاین','inperson'=>'🏢 حضوری','both'=>'💻/🏢 هر دو'];

admin_layout($adm, 'consult', 'کلینیک مشاوره — خدمات و مشاوران');
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/editor.css?v=<?= FR_VERSION ?>">
<?= flash() ?>

<!-- ── تب‌ها ─────────────────────────────────────────────────────── -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
  <a class="fr-btn sm <?= $tab==='services' ? '' : 'ghost' ?>" href="?tab=services">🛋 خدمات مشاوره (<?= fa_num(count($services)) ?>)</a>
  <a class="fr-btn sm <?= $tab==='consultants' ? '' : 'ghost' ?>" href="?tab=consultants">👤 مشاوران (<?= fa_num(count($consultants)) ?>)</a>
  <a class="fr-btn sm ghost" href="consult-schedule.php">🗓 زمان‌بندی و تعطیلات</a>
  <a class="fr-btn sm ghost" href="consult-bookings.php">📋 نوبت‌ها</a>
  <a class="fr-btn sm ghost" href="<?= SITE_URL ?>/consult.php" target="_blank" rel="noopener">🌐 صفحهٔ عمومی رزرو</a>
</div>

<?php if ($tab === 'consultants'): ?>
<!-- ═══════════════ بخش مشاوران ═══════════════ -->
<?php if ($editC): ?>
  <div class="fr-card" style="margin:0">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
      <h3 style="margin:0">✏️ ویرایش مشاور: «<?= e($editC['name']) ?>»</h3>
      <a class="fr-btn ghost sm" href="?tab=consultants">← بازگشت به فهرست مشاوران</a>
    </div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save_consultant">
      <input type="hidden" name="id" value="<?= (int)($editC['id'] ?? 0) ?>">
      <div class="fr-field"><label>نام و نام خانوادگی <span class="req">*</span></label>
        <input class="fr-input" name="name" value="<?= e($editC['name'] ?? '') ?>" required></div>
      <div class="fr-field"><label>عنوان تخصصی</label>
        <input class="fr-input" name="title" value="<?= e($editC['title'] ?? '') ?>" placeholder="روانشناس بالینی — عضو سازمان نظام"></div>
      <div class="fr-field"><label>موبایل (برای پیامک اطلاع رزروها — اختیاری)</label>
        <input class="fr-input" name="mobile" value="<?= fa_num($editC['mobile'] ?? '') ?>" placeholder="۰۹۱۲xxxxxxx" style="direction:ltr;text-align:left"></div>
      <div class="fr-field"><label>بیو کوتاه</label>
        <textarea class="fr-textarea" name="bio" rows="3"><?= e($editC['bio'] ?? '') ?></textarea></div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>ترتیب نمایش</label>
          <input class="fr-input" name="sort" inputmode="numeric" value="<?= fa_num($editC['sort'] ?? 0) ?>"></div>
        <div class="fr-field"><label>وضعیت</label>
          <select class="fr-select" name="active">
            <option value="1" <?= ($editC['active'] ?? 1)==1?'selected':'' ?>>فعال</option>
            <option value="0" <?= ($editC && !(int)$editC['active'])?'selected':'' ?>>غیرفعال</option>
          </select></div>
      </div>
      <div class="fr-field"><label>آواتار (اختیاری)</label>
        <?php if (!empty($editC['avatar'])): ?>
        <img src="<?= e(SITE_URL . '/' . $editC['avatar']) ?>" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;display:block;margin-bottom:8px">
        <?php endif; ?>
        <input class="fr-input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></div>
      <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap">
        <button class="fr-btn sm" type="submit">ذخیره تغییرات مشاور</button>
        <a class="fr-btn ghost sm" href="?tab=consultants">انصراف و بازگشت</a>
      </div>
    </form>
  </div>
<?php
  admin_layout_end();
  exit;
endif;
?>

<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0;align-self:start">
    <h3>افزودن مشاور جدید</h3>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save_consultant">
      <input type="hidden" name="id" value="0">
      <div class="fr-field"><label>نام و نام خانوادگی <span class="req">*</span></label>
        <input class="fr-input" name="name" value="" required></div>
      <div class="fr-field"><label>عنوان تخصصی</label>
        <input class="fr-input" name="title" value="" placeholder="روانشناس بالینی — عضو سازمان نظام"></div>
      <div class="fr-field"><label>موبایل (برای پیامک اطلاع رزروها — اختیاری)</label>
        <input class="fr-input" name="mobile" value="" placeholder="۰۹۱۲xxxxxxx" style="direction:ltr;text-align:left"></div>
      <div class="fr-field"><label>بیو کوتاه</label>
        <textarea class="fr-textarea" name="bio" rows="3"></textarea></div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>ترتیب نمایش</label>
          <input class="fr-input" name="sort" inputmode="numeric" value="0"></div>
        <div class="fr-field"><label>وضعیت</label>
          <select class="fr-select" name="active">
            <option value="1" selected>فعال</option>
            <option value="0">غیرفعال</option>
          </select></div>
      </div>
      <div class="fr-field"><label>آواتار (اختیاری)</label>
        <input class="fr-input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></div>
      <div style="margin-top:14px"><button class="fr-btn sm" type="submit">ذخیرهٔ مشاور</button></div>
    </form>
  </div>

  <div class="fr-card" style="margin:0">
    <h3>مشاوران (<?= fa_num(count($consultants)) ?>)</h3>
    <table class="fr-table">
      <thead><tr><th>مشاور</th><th>خدمات</th><th>موبایل</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($consultants as $c): ?>
      <tr style="<?= (int)$c['active'] ? '' : 'opacity:.5' ?>">
        <td>
          <div style="display:flex;gap:10px;align-items:center">
            <?php if ($c['avatar']): ?>
            <img src="<?= e(SITE_URL . '/' . $c['avatar']) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover">
            <?php else: ?><span style="width:40px;height:40px;border-radius:50%;background:#eaf0f8;display:inline-flex;align-items:center;justify-content:center">🛋</span><?php endif; ?>
            <div><b style="font-size:13.5px"><?= e($c['name']) ?></b><br><small style="color:var(--muted)"><?= e($c['title'] ?? '') ?></small></div>
          </div>
        </td>
        <td><?= fa_num($c['services']) ?></td>
        <td style="font-size:12px"><?= $c['mobile'] ? fa_num($c['mobile']) : '—' ?></td>
        <td class="actions">
          <a class="fr-btn sm ghost" href="?tab=consultants&edit_c=<?= $c['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف مشاور؟ خدمات و اسلات‌هایش هم حذف می‌شوند.')">
            <?= csrf_field() ?><input type="hidden" name="act" value="del_consultant">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="fr-btn sm danger">حذف</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════ بخش خدمات ═══════════════ -->
<?php if ($editS || isset($_GET['edit_s'])): ?>
  <div class="fr-card" style="margin:0">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
      <h3 style="margin:0"><?= $editS ? '✏️ ویرایش خدمت: «' . e($editS['title']) . '»' : '➕ تعریف خدمت مشاورهٔ جدید' ?></h3>
      <a class="fr-btn ghost sm" href="?tab=services">← بازگشت به فهرست خدمات</a>
    </div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save_service">
      <input type="hidden" name="id" value="<?= (int)($editS['id'] ?? 0) ?>">
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>مشاور <span class="req">*</span></label>
          <select class="fr-select" name="consultant_id" required>
            <option value="">— انتخاب —</option>
            <?php foreach ($consultants as $c): if (!(int)$c['active']) continue; ?>
            <option value="<?= $c['id'] ?>" <?= ($editS['consultant_id'] ?? 0)==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fr-field"><label>شیوهٔ جلسه</label>
          <select class="fr-select" name="mode">
            <?php foreach ($modeFa as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editS['mode'] ?? 'inperson')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="fr-field"><label>عنوان خدمت <span class="req">*</span></label>
        <input class="fr-input" name="title" value="<?= e($editS['title'] ?? '') ?>" required placeholder="مشاورهٔ فردی (۴۵ دقیقه)"></div>
      <div class="fr-field"><label>اسلاگ (خالی = خودکار)</label>
        <input class="fr-input" name="slug" dir="ltr" style="text-align:left" value="<?= e($editS['slug'] ?? '') ?>" placeholder="individual-45"></div>
      <div class="fr-field"><label>خلاصهٔ کوتاه</label>
        <input class="fr-input" name="short_desc" maxlength="250" value="<?= e($editS['short_desc'] ?? '') ?>"></div>
      <div class="fr-field"><label>توضیحات کامل — ویرایشگر پیشرفته</label>
        <div class="fre" id="freSvc" data-ph="شرح خدمت، موارد کاربرد، روش جلسات…"><?= $editS ? fr_sanitize_html((string)$editS['description']) : '' ?></div>
        <input type="hidden" name="description" id="hdSvc"></div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>قیمت هر جلسه (تومان — صفر = رایگان)</label>
          <input class="fr-input" name="price" id="svcPrice" inputmode="numeric" value="<?= $editS ? fa_num(number_format($editS['price'])) : '' ?>"></div>
        <div class="fr-field"><label>مدت جلسه (دقیقه)</label>
          <input class="fr-input" name="duration_min" inputmode="numeric" value="<?= fa_num($editS['duration_min'] ?? 45) ?>"></div>
        <div class="fr-field"><label>استراحت بین جلسات (دقیقه) ⏸</label>
          <input class="fr-input" name="gap_minutes" inputmode="numeric" value="<?= fa_num((int)($editS['gap_minutes'] ?? DB::setting('consult_gap_default','0') ?: 0)) ?>">
          <small class="hint">فاصلهٔ خالی بین دو نوبت پشت سر هم (مثلاً ۱۵ دقیقه)؛ در تولید اسلات لحاظ می‌شود.</small></div>
        <div class="fr-field"><label>ترتیب نمایش</label>
          <input class="fr-input" name="sort" inputmode="numeric" value="<?= fa_num($editS['sort'] ?? 0) ?>"></div>
        <div class="fr-field"><label>وضعیت</label>
          <select class="fr-select" name="active">
            <option value="1" <?= ($editS['active'] ?? 1)==1?'selected':'' ?>>فعال</option>
            <option value="0" <?= ($editS && !(int)$editS['active'])?'selected':'' ?>>غیرفعال</option>
          </select></div>
      </div>

      <!-- ۳٫۰: پیش‌پرداخت (بیعانه) برای رزرو نوبت -->
      <div style="background:#f6fbf9;border:1.5px solid #cdeeda;border-radius:14px;padding:12px 14px;margin:4px 0 12px">
        <b style="font-size:13px;display:block;margin-bottom:8px">💳 پیش‌پرداخت (بیعانه) — نسخهٔ ۳٫۰</b>
        <div class="fr-grid cols-2" style="gap:10px">
          <div class="fr-field" style="margin:0"><label>پرداخت بیعانه در رزرو</label>
            <select class="fr-select" name="deposit_enabled" id="svcDepEnabled">
              <option value="0" <?= ($editS && !(int)($editS['deposit_enabled'] ?? 0)) ? 'selected' : '' ?>>غیرفعال — فقط پرداخت کامل</option>
              <option value="1" <?= ($editS && (int)($editS['deposit_enabled'] ?? 0)) ? 'selected' : '' ?>>فعال — مراجع می‌تواند بیعانه بدهد</option>
            </select></div>
          <div class="fr-field" style="margin:0"><label>مبلغ پیش‌پرداخت (تومان)</label>
            <input class="fr-input" name="deposit_amount" id="svcDepAmount" inputmode="numeric"
                   value="<?= $editS && !empty($editS['deposit_amount']) ? fa_num(number_format((int)$editS['deposit_amount'])) : '' ?>" placeholder="مثلاً ۲۰۰٬۰۰۰">
            <small class="hint" id="svcDepHint" style="font-weight:700"></small></div>
        </div>
        <small style="display:block;color:var(--muted);line-height:2;margin-top:8px">
          با فعال‌سازی، مراجع می‌تواند فقط بیعانه را بپردازد تا نوبتش قطعی شود؛ مانده را «حداکثر تا ۳ روز مانده به جلسه»
          با یادآور خودکار (پیامک + لینک اختصاصی تصفیه) تسویه می‌کند. بیعانه باید از قیمت جلسه کمتر باشد.
        </small>
      </div>
      <div class="fr-field"><label>نشانی/لینک ثابت جلسه (در پیامک تأیید ارسال می‌شود)</label>
        <input class="fr-input" name="meeting_hint" value="<?= e($editS['meeting_hint'] ?? '') ?>" placeholder="https://vroom.example/feria یا نشانی مرکز"></div>
      <div class="fr-field"><label>تصویر خدمت (اختیاری)</label>
        <?php if (!empty($editS['image'])): ?>
        <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">
          <img src="<?= e(SITE_URL . '/' . $editS['image']) ?>" alt="" style="width:110px;height:62px;object-fit:cover;border-radius:12px">
          <label class="tog"><input type="checkbox" name="remove_image" value="1"> حذف تصویر</label>
        </div>
        <?php endif; ?>
        <input class="fr-input" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
      <div style="display:flex;gap:10px;align-items:center;margin-top:18px;flex-wrap:wrap">
        <button class="fr-btn sm" type="submit">ذخیرهٔ خدمت</button>
        <a class="fr-btn ghost sm" href="?tab=services">انصراف و بازگشت</a>
      </div>
    </form>
  </div>
<?php
  // در حالت ویرایش خدمت، جدول خدمات مخفی می‌ماند
  admin_layout_end();
  exit;
  else:
?>
  <div style="margin-bottom:14px"><a class="fr-btn sm" href="?tab=services&edit_s=new">➕ تعریف خدمت جدید</a></div>
<?php endif; ?>

  <?php if (!$editS && !isset($_GET['edit_s'])): ?>
  <div class="fr-card" style="margin:0">
    <h3>خدمات مشاوره (<?= fa_num(count($services)) ?>)</h3>
    <div style="overflow-x:auto">
    <table class="fr-table">
      <thead><tr><th>خدمت</th><th>مشاور</th><th>قیمت</th><th>نوبت آزاد</th><th>رزرو شده</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($services as $s): ?>
      <tr style="<?= (int)$s['active'] ? '' : 'opacity:.5' ?>">
        <td style="font-size:12.5px">
          <b><?= e($s['title']) ?></b><br>
          <small style="color:var(--muted)"><?= $modeFa[$s['mode']] ?> · <?= fa_num($s['duration_min']) ?> دقیقه</small>
        </td>
        <td style="font-size:12px"><?= e($s['cname']) ?></td>
        <td style="font-size:12px"><?= $s['price'] ? toman((int)$s['price']) : 'رایگان' ?>
          <?= !empty($s['deposit_enabled']) && (int)($s['deposit_amount'] ?? 0) > 0 ? '<br><small style="color:#0c7a4d">💳 بیعانه: ' . toman((int)$s['deposit_amount']) . '</small>' : '' ?></td>
        <td><?= fa_num($s['open_slots']) ?></td>
        <td><?= fa_num($s['booked']) ?></td>
        <td class="actions" style="white-space:nowrap">
          <a class="fr-btn sm ghost" href="consult-schedule.php?service=<?= $s['id'] ?>" title="زمان‌بندی این خدمت">🗓</a>
          <a class="fr-btn sm ghost" href="<?= SITE_URL ?>/consult.php?s=<?= urlencode($s['slug']) ?>" target="_blank" rel="noopener" title="صفحهٔ عمومی">🌐</a>
          <a class="fr-btn sm ghost" href="?tab=services&edit_s=<?= $s['id'] ?>">ویرایش</a>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف خدمت؟')">
            <?= csrf_field() ?><input type="hidden" name="act" value="del_service">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button class="fr-btn sm danger">حذف</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="<?= SITE_URL ?>/assets/js/editor.js?v=<?= FR_VERSION ?>"></script>
<script>
(function(){
  var area = document.getElementById('freSvc');
  if (area && window.FrEditor) window.FrEditor.attach(area, document.getElementById('hdSvc'));

  /* ۳٫۰: اعتبارسنجی زندهٔ مبلغ پیش‌پرداخت خدمت */
  var dSel = document.getElementById('svcDepEnabled'), dIn = document.getElementById('svcDepAmount'),
      dHint = document.getElementById('svcDepHint'), pIn = document.getElementById('svcPrice');
  if (dIn && dHint) {
    var dig = function(v){ return parseInt(String(v).replace(/[۰-۹٠-٩]/g, function(ch){ var i='۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(ch); return i>=0 ? String(i%10) : ch; }).replace(/\D/g,'')) || 0; };
    var faD = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    var fmt = function(n){ return String(n.toLocaleString('en-US')).replace(/[0-9]/g, function(d){ return faD[+d]; }).replace(/,/g,'٬'); };
    var calc = function(){
      var p = pIn ? dig(pIn.value) : 0, d = dig(dIn.value);
      if (!dSel || dSel.value !== '1' || d === 0) { dHint.textContent = ''; return; }
      if (p > 0 && d < p) {
        dHint.style.color = '#0c7a4d';
        dHint.textContent = '✓ معتبر است — مراجع با بیعانهٔ ' + fmt(d) + ' تومان نوبتش قطعی می‌شود و ماندهٔ ' + fmt(p - d) + ' تومان را بعداً تسویه می‌کند.';
      } else {
        dHint.style.color = '#b91c1c';
        dHint.textContent = '⚠️ بیعانه باید کمتر از قیمت جلسه (' + fmt(p) + ' تومان) باشد؛ در غیر این صورت هنگام ذخیره نادیده گرفته می‌شود.';
      }
    };
    [dIn, pIn].forEach(function(x){ if (x) x.addEventListener('input', calc); });
    if (dSel) dSel.addEventListener('change', calc);
    calc();
  }
})();
</script>
<?php endif; ?>
<?php admin_layout_end(); ?>
