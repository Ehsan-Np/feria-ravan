<?php
/** مدیریت تست‌های روانشناسی — قیمت، وضعیت، دستورالعمل + عملیات گروهی و زباله‌دان (۱٫۹) */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin','manager','support']);

/* ══════════════════════════════════════════════════════════════════════════
   ۰) حذف تکی تست روانشناسی (سریع یا نهایی)
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'delete_single') {
    csrf_guard();
    $tid = (int)post('id');
    $force = (int)post('force') === 1;
    if ($tid > 0) {
        $row = DB::one('SELECT id, title FROM ' . DB::t('tests') . ' WHERE id=?', [$tid]);
        if ($row) {
            if ($force) {
                if (fr_purge_test($tid)) {
                    require_once __DIR__ . '/../includes/seo.php';
                    fr_permalinks_flush();
                    flash('تست روانشناسی «' . $row['title'] . '» و تمامی سوالات و داده‌های آن با موفقیت پاکسازی و حذف شد 🗑', 'ok');
                } else {
                    flash('خطا در پاکسازی تست روانشناسی.', 'err');
                }
            } else {
                DB::update('tests', ['status' => 'trash'], 'id=?', [$tid]);
                log_activity('test_trash', 'test', $tid);
                flash('تست روانشناسی «' . $row['title'] . '» به زباله‌دان منتقل شد.', 'ok');
            }
        }
    }
    redirect(SITE_URL . '/admin/tests.php?view=' . (string)post('view', 'active'));
}

/* ══════════════════════════════════════════════════════════════════════════
   ۱) عملیات گروهی (۱٫۹): فعال/غیرفعال/زباله‌دان/بازگردانی/حذف نهایی
   ─ مهم: این پردازشگر باید پیش از پردازشگر تک‌تست روانشناسی (پایین) باشد تا
     POSTهای گروهی به‌اشتباه به‌عنوان «ذخیرهٔ تک‌تست روانشناسی» تفسیر نشوند.
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'bulk') {
    $view = post('view') === 'trash' ? 'trash' : 'active';
    $ids  = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
    $do   = (string)post('do');
    if (!$ids) { flash('هیچ تستی انتخاب نشده است.', 'warn'); redirect(SITE_URL . '/admin/tests.php?view=' . $view); }

    $done = 0; $blocked = [];
    foreach ($ids as $tid) {
        $row = DB::one('SELECT id, title, status FROM ' . DB::t('tests') . ' WHERE id=?', [$tid]);
        if (!$row) continue;
        if ($do === 'activate')        { DB::update('tests', ['status'=>'active'],   'id=?', [$tid]); $done++; }
        elseif ($do === 'deactivate')  { DB::update('tests', ['status'=>'inactive'], 'id=?', [$tid]); $done++; }
        elseif ($do === 'trash')  {
            DB::update('tests', ['status'=>'trash'], 'id=?', [$tid]);
            $done++; log_activity('test_trash', 'test', $tid);
        }
        elseif ($do === 'restore') {
            DB::update('tests', ['status'=>'draft'], 'id=?', [$tid]);
            $done++; log_activity('test_restore', 'test', $tid);
        }
        elseif ($do === 'delete') {
            if (fr_purge_test($tid)) {
                $done++;
            } else {
                $blocked[] = $row['title'] . ' (خطا در حذف)';
            }
        }
    }
    require_once __DIR__ . '/../includes/seo.php';
    fr_permalinks_flush();      // صفحهٔ /t/اسلاگ حذف‌شده فوراً از پیوندها پاک شود
    $lbl = ['activate'=>'فعال‌سازی','deactivate'=>'غیرفعال‌سازی','trash'=>'انتقال به زباله‌دان','restore'=>'بازگردانی از زباله‌دان','delete'=>'حذف نهایی'][$do] ?? $do;
    flash($done > 0 ? "$lbl برای " . fa_num($done) . " تست روانشناسی با موفقیت انجام شد ✅" : 'موردی انجام نشد.', $blocked ? 'warn' : 'ok');
    if ($blocked) flash('⛔ خطا در حذف این موارد: ' . implode(' ؛ ', $blocked), 'err');
    redirect(SITE_URL . '/admin/tests.php?view=' . (in_array($do, ['delete','restore'], true) ? 'trash' : $view));
}

/* ══════════════════════════════════════════════════════════════════════════
   ۲) ذخیرهٔ تک‌تست روانشناسی (فرم ویرایش)
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)post('id');
    // مخاطب تست روانشناسی (در صورت وجود ستون نسخهٔ ۱٫۲) — مستقل از نتیجهٔ به‌روزرسانی اصلی
    if ($id && in_array(post('audience'), ['general','manager'], true)) {
        try { DB::update('tests', ['audience' => post('audience')], 'id=?', [$id]); } catch (Throwable $e) {}
    }
    if ($id && in_array(post('category'), ['clinical','personality','intelligence'], true)) {
        try { DB::update('tests', ['category' => post('category')], 'id=?', [$id]); } catch (Throwable $e) {}
    }
    // اسلاگ + سئو (۱٫۷): بازتولید خودکار پیوندهای یکتا در صورت تغییر اسلاگ
    $oldSlug = $id ? (string)DB::val('SELECT slug FROM ' . DB::t('tests') . ' WHERE id=?', [$id]) : '';
    require_once __DIR__ . '/../includes/sanitize.php';
    $newSlug = fr_unique_slug('tests', fr_slugify(trim((string)post('slug')) !== ''
        ? (string)post('slug') : (string)post('title')), 'slug', $id);
    // ۱٫۹: 'trash' فقط وقتی پذیرفته می‌شود که تست روانشناسی هم‌اکنون در زباله‌دان باشد (ویرایشِ درونِ زباله‌دان)
    $curStatus = $id ? (string)DB::val('SELECT status FROM ' . DB::t('tests') . ' WHERE id=?', [$id]) : '';
    $allowSt   = ['active','inactive','draft'] + ($curStatus === 'trash' ? [3=>'trash'] : []);

    // مدیریت کاور تست روانشناسی (ذخیره در feria-uploads/tests/)
    $oldTest = $id ? DB::one('SELECT * FROM ' . DB::t('tests') . ' WHERE id=?', [$id]) : null;
    if ((int)post('remove_image') === 1 && !empty($oldTest['image'])) {
        require_once __DIR__ . '/../includes/upload.php';
        fr_delete_image($oldTest['image']);
        DB::update('tests', ['image' => null], 'id=?', [$id]);
    }
    if (!empty($_FILES['image']) && (int)($_FILES['image']['error'] ?? 4) !== 4) {
        require_once __DIR__ . '/../includes/upload.php';
        $up = fr_save_cover($_FILES['image'], 'tests', 'test', $id);
        if ($up['ok']) {
            if (!empty($oldTest['image'])) fr_delete_image($oldTest['image']);
            DB::update('tests', ['image' => $up['path']], 'id=?', [$id]);
        } else {
            flash('خطا در آپلود کاور تست: ' . $up['error'], 'warn');
        }
    }

    if ($id && DB::update('tests', [
        'title'         => trim((string)post('title')),
        'slug'          => $newSlug,
        'short_desc'    => trim((string)post('short_desc')),
        'description'   => (string)post('description'),
        'instructions'  => (string)post('instructions'),
        'free_teaser'   => (string)post('free_teaser'),
        'seo_title'     => (($v = trim((string)post('seo_title'))) !== '' ? mb_substr($v, 0, 200) : null),
        'seo_desc'      => (($v = trim((string)post('seo_desc'))) !== '' ? mb_substr($v, 0, 320) : null),
        'seo_kw'        => (($v = trim((string)post('seo_kw'))) !== '' ? mb_substr($v, 0, 120) : null),
        'seo_noindex'   => (int)post('seo_noindex') === 1 ? 1 : 0,
        'price_full'    => (int)preg_replace('/\D/','', en_num((string)post('price_full','0'))),
        /* ۲٫۸: قیمت با تخفیف — فقط اگر کمتر از قیمت اصلی باشد */
        'price_sale'    => (function(){ $sp = (int)preg_replace('/\D/','', en_num((string)post('price_sale',''))); $pr = (int)preg_replace('/\D/','', en_num((string)post('price_full','0'))); return ($sp > 0 && $sp < $pr) ? $sp : null; })(),
        'free_enabled'  => post('free_enabled') ? 1 : 0,
        'est_minutes'   => (int)en_num((string)post('est_minutes','10')),
        'status'        => in_array(post('status'), $allowSt, true) ? post('status') : 'active',
        'sort_order'    => (int)en_num((string)post('sort_order','0')),
    ], 'id=?', [$id])) {
        log_activity('admin_test_update', 'test', $id);
        if ($oldSlug !== $newSlug) {
            require_once __DIR__ . '/../includes/seo.php';
            $fl = fr_permalinks_flush();
            if (!$fl['ok']) flash('فایل .htaccess قابل نوشتن نیست؛ پیوندها را از صفحهٔ «پیوندهای یکتا» بازتولید کنید.', 'warn');
        }
        flash('تنظیمات تست روانشناسی ذخیره شد ✅');
    } else {
        flash('تغییری اعمال نشد.', 'warn');
    }
    $backView = in_array(post('status'), ['trash'], true) ? 'trash' : 'active';
    redirect(SITE_URL . '/admin/tests.php' . (post('edit_page') ? '?edit=' . $id . '&view=' . $backView : '?view=' . $backView));
}

$edit = (int)($_GET['edit'] ?? 0);

/* ── تب «فعال‌ها» (غیرِ زباله‌دان) و «زباله‌دان» (۱٫۹) ── */
$view = (string)($_GET['view'] ?? 'active') === 'trash' ? 'trash' : 'active';
$filterCat = (string)($_GET['cat'] ?? 'all');
$whereSql = "t.status " . ($view === 'trash' ? "='trash'" : "<>'trash'");
$params = [];
if (in_array($filterCat, ['clinical','personality','intelligence'], true)) {
    $whereSql .= " AND t.category=?";
    $params[] = $filterCat;
}
$tests = DB::all("SELECT t.*, (SELECT COUNT(*) FROM " . DB::t('questions') . " q WHERE q.test_id=t.id) AS qc,
                         (SELECT COUNT(*) FROM " . DB::t('attempts') . " a WHERE a.test_id=t.id AND a.status!='in_progress') AS done_count
                  FROM " . DB::t('tests') . " t
                  WHERE {$whereSql}
                  ORDER BY t.sort_order", $params);
$activeCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status<>'trash'");
$trashCount  = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tests') . " WHERE status='trash'");
admin_layout($adm, 'tests', 'مدیریت تست‌های روانشناسی');
echo flash();
?>
<?php if ($edit && $t = DB::one('SELECT * FROM ' . DB::t('tests') . ' WHERE id=?', [$edit])): ?>
<div class="fr-card" style="margin:0">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:12px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
    <h3 style="margin:0">✏️ ویرایش تست روانشناسی: <?= e($t['title']) ?> <?= $t['status']==='trash' ? '<span class="fr-chip warn">در زباله‌دان</span>' : '' ?></h3>
    <a class="fr-btn ghost sm" href="tests.php<?= $t['status']==='trash' ? '?view=trash' : '' ?>">← بازگشت به فهرست تست‌ها</a>
  </div>
  <form method="post" enctype="multipart/form-data" class="fr-grid cols-2">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="edit_page" value="1">
    <div>
      <div class="fr-field"><label>عنوان تست روانشناسی</label><input class="fr-input" name="title" id="tTitle" value="<?= e($t['title']) ?>"></div>
      <div class="fr-field"><label>توضیح کوتاه</label><input class="fr-input" name="short_desc" id="tShort" value="<?= e($t['short_desc'] ?? '') ?>"></div>
      <div class="fr-field"><label>کاور تست روانشناسی</label>
        <?php if (!empty($t['image'])): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
          <img src="<?= e(SITE_URL . '/' . ltrim($t['image'], '/')) ?>" alt="" style="width:120px;height:68px;object-fit:cover;border-radius:12px;border:1px solid var(--line)">
          <label style="font-size:12px;color:var(--err);display:inline-flex;align-items:center;gap:4px;cursor:pointer">
            <input type="checkbox" name="remove_image" value="1"> حذف کاور فعلی
          </label>
        </div>
        <?php endif; ?>
        <input class="fr-input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
        <small class="hint">فرمت‌های مجاز: JPG, PNG, WEBP (ذخیرهٔ خودکار در feria-uploads/tests/)</small>
      </div>
      <div class="fr-field"><label>اسلاگ صفحهٔ عمومی (بدون تغییر بمانید = خودکار)</label>
        <input class="fr-input" name="slug" id="tSlug" dir="ltr" style="text-align:left" value="<?= e($t['slug'] ?? '') ?>" placeholder="mbti-test">
        <?php if (!empty($t['slug'])): ?>
        <small class="hint">نشانی زیبا: <a href="<?= SITE_URL ?>/t/<?= urlencode($t['slug']) ?>" target="_blank" rel="noopener" dir="ltr">/t/<?= e($t['slug']) ?> ↗</a></small>
        <?php endif; ?>
      </div>
      <div class="fr-field"><label>توضیحات کامل (HTML)</label>
        <div class="rte-toolbar" data-rte="desc"></div>
        <div class="rte-area" id="rte-desc" contenteditable="true"><?= $t['description'] ?? '' ?></div>
        <input type="hidden" name="description" id="hd-desc"></div>
    </div>
    <div>
      <div class="fr-field"><label>دستورالعمل قبل از شروع (HTML)</label>
        <div class="rte-toolbar" data-rte="inst"></div>
        <div class="rte-area" id="rte-inst" contenteditable="true"><?= $t['instructions'] ?? '' ?></div>
        <input type="hidden" name="instructions" id="hd-inst"></div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>قیمت نسخه ویژه (تومان)</label>
          <input class="fr-input" name="price_full" value="<?= fa_num(number_format($t['price_full'])) ?>" style="direction:ltr;text-align:right"></div>
        <div class="fr-field"><label>قیمت با تخفیف (تومان — اختیاری؛ کمتر از قیمت اصلی)</label>
          <input class="fr-input" name="price_sale" value="<?= !empty($t['price_sale']) ? fa_num(number_format((int)$t['price_sale'])) : '' ?>" style="direction:ltr;text-align:right" placeholder="مثلاً ۱۸۹٬۰۰۰">
          <small class="hint">اگر معتبر باشد، درصد تخفیف به‌صورت خودکار روی کارت تست روانشناسی نمایش داده می‌شود.</small></div>
        <div class="fr-field"><label>زمان تقریبی (دقیقه)</label>
          <input class="fr-input" name="est_minutes" value="<?= fa_num($t['est_minutes']) ?>"></div>
        <div class="fr-field"><label>دسته‌بندی روان‌سنجی</label>
          <select class="fr-select" name="category">
            <option value="clinical" <?= ($t['category'] ?? '')==='clinical'?'selected':'' ?>>🩺 بالینی و سلامت روان</option>
            <option value="personality" <?= ($t['category'] ?? 'personality')==='personality'?'selected':'' ?>>🎭 شخصیت و خودشناسی</option>
            <option value="intelligence" <?= ($t['category'] ?? '')==='intelligence'?'selected':'' ?>>💡 هوش، استعداد و پیشرفت شغلی</option>
          </select></div>
        <div class="fr-field"><label>مخاطب</label>
          <select class="fr-select" name="audience">
            <option value="general" <?= ($t['audience'] ?? 'general')==='general'?'selected':'' ?>>👥 افراد عادی</option>
            <option value="manager" <?= ($t['audience'] ?? '')==='manager'?'selected':'' ?>>💼 مدیران کسب‌وکار</option>
          </select></div>
        <div class="fr-field"><label>وضعیت</label>
          <select class="fr-select" name="status">
            <option value="active" <?= $t['status']==='active'?'selected':'' ?>>فعال</option>
            <option value="inactive" <?= $t['status']==='inactive'?'selected':'' ?>>غیرفعال</option>
            <option value="draft" <?= $t['status']==='draft'?'selected':'' ?>>پیش‌نویس</option>
            <?php if ($t['status']==='trash'): ?><option value="trash" selected>زباله‌دان</option><?php endif; ?>
          </select></div>
        <div class="fr-field"><label>ترتیب نمایش</label>
          <input class="fr-input" name="sort_order" value="<?= fa_num($t['sort_order']) ?>"></div>
      </div>
      <label class="tog"><input type="checkbox" name="free_enabled" <?= $t['free_enabled']?'checked':'' ?>> نسخه رایگان (تحلیل خلاصه) فعال باشد</label>

      <hr style="border:none;border-top:1.5px dashed #d4dcec;margin:16px 0">
      <h3 style="font-size:14px">🔎 سئوی صفحهٔ عمومی تست روانشناسی (۱٫۷) — /t/<?= e($t['slug'] ?? '…') ?></h3>
      <div style="direction:ltr;text-align:left;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:12px;box-shadow:0 3px 14px rgba(80,60,160,.06)">
        <div id="pvTitle" style="color:#1a0dab;font-size:16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($t['seo_title'] ?: $t['title']) ?></div>
        <div style="color:#006621;font-size:12px;direction:ltr"><?= e(SITE_URL) ?>/t/<?= e($t['slug'] ?? 'slug') ?></div>
        <div id="pvDesc" style="color:#545454;font-size:12.5px;line-height:1.6"><?= e($t['seo_desc'] ?: ($t['short_desc'] ?? '')) ?></div>
      </div>
      <div class="fr-field"><label>عنوان سئو</label>
        <input class="fr-input" name="seo_title" id="tSeoTitle" maxlength="200" value="<?= e($t['seo_title'] ?? '') ?>" placeholder="خالی = عنوان تست روانشناسی"></div>
      <div class="fr-field"><label>توضیحات متا</label>
        <textarea class="fr-textarea" name="seo_desc" id="tSeoDesc" rows="2" maxlength="320" placeholder="خالی = توضیح کوتاه تست روانشناسی"><?= e($t['seo_desc'] ?? '') ?></textarea></div>
      <div class="fr-field"><label>کلمات کلیدی (با ویرگول)</label>
        <input class="fr-input" name="seo_kw" maxlength="120" value="<?= e($t['seo_kw'] ?? '') ?>" placeholder="مثلاً تست شخصیت, روانشناسی"></div>
      <label class="tog"><input type="checkbox" name="seo_noindex" value="1" <?= !empty($t['seo_noindex'])?'checked':'' ?>> صفحهٔ عمومی این تست روانشناسی در جست‌وجو نمایش داده نشود (noindex)</label>
      <script>
      (function(){ // پیش‌نمایش زندهٔ ریچ‌اسنیپت سئو
        var t=document.getElementById('tSeoTitle'),d=document.getElementById('tSeoDesc'),
            tt=document.getElementById('tTitle'),sh=document.getElementById('tShort'),
            pt=document.getElementById('pvTitle'),pd=document.getElementById('pvDesc');
        function pv(){ if(pt) pt.textContent=(t&&t.value.trim())||(tt&&tt.value.trim())||'عنوان تست روانشناسی';
                       if(pd) pd.textContent=(d&&d.value.trim())||(sh&&sh.value.trim())||'توضیح کوتاه…'; }
        [t,d,tt,sh].forEach(function(x){ if(x) x.addEventListener('input',pv); }); pv();
      })();
      </script>
      <div class="fr-field" style="margin-top:12px"><label>متن تشویقی پایان نسخهٔ رایگان (HTML) — دلخواه و قابل‌ویرایش</label>
        <div class="rte-toolbar" data-rte="teaser"></div>
        <div class="rte-area" id="rte-teaser" contenteditable="true"><?= $t['free_teaser'] ?? '' ?></div>
        <input type="hidden" name="free_teaser" id="hd-teaser">
        <small style="color:var(--muted)">این متن ته گزارش رایگان نشان داده می‌شود تا کاربر را به خرید نسخهٔ ویژه ترغیب کند. اگر خالی بماند، متن پیش‌فرض سیستم چاپ می‌شود.</small></div>
      <div style="display:flex;gap:10px;align-items:center;margin-top:18px;flex-wrap:wrap">
        <button class="fr-btn" type="submit" onclick="syncRte()">ذخیره تغییرات تست</button>
        <a class="fr-btn ghost" href="tests.php<?= $t['status']==='trash' ? '?view=trash' : '' ?>">انصراف و بازگشت</a>
        <button type="button" class="fr-btn danger" onclick="frDeleteTest(<?= (int)$t['id'] ?>, '<?= e(addslashes($t['title'])) ?>')" style="margin-right:auto">🗑 حذف این تست</button>
      </div>
    </div>
  </form>
</div>
<script>function syncRte(){['desc','inst','teaser'].forEach(function(k){document.getElementById('hd-'+k).value=document.getElementById('rte-'+k).innerHTML;});}</script>
<?php
// حالت ویرایش اختصاصی: وقتی کاربر در حال ویرایش تست است، فقط فرم ویرایش نمایش داده می‌شود و جدول سایر تست‌ها مخفی می‌ماند
admin_layout_end();
exit;
endif;
?>

<div class="fr-card">
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <h3 style="margin:0">تست‌های روانشناسی سیستم</h3>
    <a class="fr-chip <?= $view==='active'?'purple':'info' ?>" href="?view=active" style="text-decoration:none">لیست اصلی (<?= fa_num($activeCount) ?>)</a>
    <a class="fr-chip <?= $view==='trash'?'purple':'info' ?>" href="?view=trash" style="text-decoration:none">🗑 زباله‌دان (<?= fa_num($trashCount) ?>)</a>
    <span style="border-right:1px solid var(--line);margin:0 4px;height:16px;display:inline-block"></span>
    <a class="fr-chip <?= $filterCat==='all'?'purple':'info' ?>" href="?view=<?= $view ?>&cat=all" style="text-decoration:none">همه دسته‌ها</a>
    <a class="fr-chip <?= $filterCat==='clinical'?'purple':'info' ?>" href="?view=<?= $view ?>&cat=clinical" style="text-decoration:none">🩺 بالینی</a>
    <a class="fr-chip <?= $filterCat==='personality'?'purple':'info' ?>" href="?view=<?= $view ?>&cat=personality" style="text-decoration:none">🎭 شخصیت</a>
    <a class="fr-chip <?= $filterCat==='intelligence'?'purple':'info' ?>" href="?view=<?= $view ?>&cat=intelligence" style="text-decoration:none">💡 هوش و شغل</a>
    <small style="color:var(--muted);margin-right:auto">
      <?= $view==='trash'
          ? 'بازگردانی → پیش‌نویس می‌شود؛ حذف نهایی فقط برای تست‌های روانشناسی بدون سابقهٔ کاربر/مالی'
          : 'برای عملیات گروهی، چک‌باکس تست‌های روانشناسی را تیک بزنید و عملیات را انتخاب کنید' ?>
    </small>
  </div>
  <form method="post" id="bulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="bulk">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;align-items:center">
      <select class="fr-select" name="do" id="bulkDo" style="width:auto">
        <?php if ($view === 'active'): ?>
        <option value="activate">✅ فعال‌سازی</option>
        <option value="deactivate">⛔ غیرفعال‌سازی</option>
        <option value="trash">🗑 انتقال به زباله‌دان</option>
        <option value="delete">❌ حذف نهایی و پاکسازی کامل</option>
        <?php else: ?>
        <option value="restore">♻️ بازگردانی از زباله‌دان</option>
        <option value="delete">❌ حذف نهایی و پاکسازی کامل</option>
        <?php endif; ?>
      </select>
      <button class="fr-btn sm" onclick="return frBulkGo()">اعمال روی انتخاب‌شده‌ها</button>
      <span class="hint" id="selCount"></span>
    </div>
    <div style="overflow-x:auto"><table class="fr-table">
      <thead><tr><th style="width:36px"><input type="checkbox" id="allChk" title="انتخاب همه"></th><th>تست روانشناسی</th><th>دسته‌بندی</th><th>موتور</th><th>سؤال‌ها</th><th>انجام‌شده</th><th>قیمت ویژه</th><th>رایگان</th><th>وضعیت</th><th></th></tr></thead>
      <tbody>
      <?php if (!$tests): ?>
      <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:26px 0"><?= $view==='trash' ? 'زباله‌دان خالی است 🎉' : 'هنوز تستی ثبت نشده است.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($tests as $t): ?>
      <tr>
        <td><input type="checkbox" class="tid" name="ids[]" value="<?= (int)$t['id'] ?>"></td>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <?php if (!empty($t['image'])): ?>
            <img src="<?= e(SITE_URL . '/' . ltrim($t['image'], '/')) ?>" alt="" style="width:50px;height:32px;object-fit:cover;border-radius:6px;border:1px solid var(--line);flex:0 0 auto">
            <?php endif; ?>
            <div>
              <b><?= e($t['title']) ?></b><br>
              <small style="color:var(--muted)"><?= ($t['audience'] ?? 'general')==='manager' ? '💼 مدیران' : '👥 عمومی' ?></small>
            </div>
          </div>
        </td>
        <td>
          <?php
          $c = $t['category'] ?? 'personality';
          echo match($c) {
              'clinical'     => '<span class="fr-chip" style="background:#ffe4e6;color:#be123c;font-size:11px;font-weight:600">🩺 بالینی</span>',
              'personality'  => '<span class="fr-chip" style="background:#ede9fe;color:#6d28d9;font-size:11px;font-weight:600">🎭 شخصیت</span>',
              'intelligence' => '<span class="fr-chip" style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:600">💡 هوش/شغل</span>',
              default        => '<span class="fr-chip">' . e($c) . '</span>',
          };
          ?>
        </td>
        <td><span class="fr-chip purple"><?= e($t['engine']) ?></span></td>
        <td><?= fa_num($t['qc']) ?></td>
        <td><a href="attempts.php?test_id=<?= $t['id'] ?>" title="مشاهده تحلیل‌ها و کارنامه‌های این تست" style="font-weight:700;color:var(--c-primary)"><?= fa_num($t['done_count']) ?></a></td>
        <td style="white-space:nowrap"><?php $tDeal = fr_price_deal((int)$t['price_full'], isset($t['price_sale']) ? (int)$t['price_sale'] : null);
          if ($tDeal['has_sale']): ?><del style="color:#94a3b8;font-size:11.5px"><?= toman($tDeal['list']) ?></del><br><b style="color:#b91c1c"><?= toman($tDeal['final']) ?></b> <span class="fr-chip" style="background:#fef2f2;color:#b91c1c;font-size:10px">٪<?= fa_num($tDeal['percent']) ?></span><?php else: ?><?= toman((int)$t['price_full']) ?><?php endif; ?></td>
        <td><?= $t['free_enabled'] ? '<span class="fr-chip ok">فعال</span>' : '<span class="fr-chip err">غیرفعال</span>' ?></td>
        <td><?= $t['status']==='active' ? '<span class="fr-chip ok">فعال</span>'
              : ($t['status']==='trash' ? '<span class="fr-chip warn">زباله‌دان</span>'
              : '<span class="fr-chip warn">'.e($t['status']).'</span>') ?></td>
        <td class="actions" style="white-space:nowrap">
          <a class="fr-btn sm ghost" href="?edit=<?= $t['id'] ?><?= $view==='trash'?'&view=trash':'' ?>" title="ویرایش تست روانشناسی">ویرایش</a>
          <a class="fr-btn sm" href="questions.php?test=<?= $t['id'] ?>" title="مدیریت سوالات">سوالات</a>
          <a class="fr-btn sm ghost" href="templates.php?test=<?= $t['id'] ?>" title="قالب‌های تحلیل">الگوها</a>
          <a class="fr-btn sm" style="background:#6d28d9;color:#fff" href="attempts.php?test_id=<?= $t['id'] ?>" title="مشاهده نتایج و تحلیل‌های مراجعین">📊 تحلیل مراجعین</a>
          <a class="fr-btn sm ghost" href="registrants.php?ctx=test&id=<?= $t['id'] ?>" title="مدیریت شرکت‌کنندگان">👥</a>
          <button type="button" class="fr-btn sm danger" onclick="frDeleteTest(<?= (int)$t['id'] ?>, '<?= e(addslashes($t['title'])) ?>')" title="حذف این تست روانشناسی">🗑</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </form>
</div>

<!-- فرم مجزای حذف تکی تست -->
<form method="post" id="testSingleDelForm" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="delete_single">
  <input type="hidden" name="id" id="singleDelId" value="">
  <input type="hidden" name="force" id="singleDelForce" value="1">
  <input type="hidden" name="view" value="<?= e($view) ?>">
</form>

<script>
(function(){
  var all=document.getElementById('allChk');
  function boxes(){ return Array.prototype.slice.call(document.querySelectorAll('.tid')); }
  function up(){
    var n=boxes().filter(function(c){return c.checked;}).length;
    var el=document.getElementById('selCount');
    if(el) el.textContent = n ? ('انتخاب‌شده: '+n.toLocaleString('fa-IR')) : '';
  }
  if(all) all.addEventListener('change',function(){ boxes().forEach(function(c){c.checked=all.checked;}); up(); });
  boxes().forEach(function(c){ c.addEventListener('change',up); });
})();

function frDeleteTest(id, title) {
  if (confirm('آیا از حذف تست روانشناسی «' + title + '» اطمینان دارید؟\n\nبا تایید شما، این تست و تمام سوالات، پاسخ‌ها و رکوردهای مربوطه به صورت کامل پاکسازی و حذف خواهند شد.')) {
    document.getElementById('singleDelId').value = id;
    document.getElementById('singleDelForce').value = '1';
    document.getElementById('testSingleDelForm').submit();
  }
}

function frBulkGo(){
  var n=document.querySelectorAll('.tid:checked').length;
  if(!n){ alert('هیچ تستی انتخاب نشده است. ابتدا چک‌باکس موارد دلخواه را تیک بزنید.'); return false; }
  var d=document.getElementById('bulkDo').value;
  if(d==='delete')
    return confirm('آیا از حذف نهایی و پاکسازی '+n+' تست روانشناسی انتخاب‌شده اطمینان دارید؟\nاین عملیات تست‌ها و سؤالات مربوطه را پاکسازی می‌کند و غیرقابل بازگشت است.');
  if(d==='trash')
    return confirm('انتقال '+n+' تست روانشناسی به زباله‌دان؟\nتست‌های روانشناسی زباله‌دانی در سایت نمایش داده نمی‌شوند ولی بعداً قابل بازگردانی هستند.');
  return true;
}
</script>
<?php admin_layout_end(); ?>
