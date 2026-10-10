<?php
/**
 * =============================================================================
 *  مدیریت اختصاصی کارگاه‌ها یا دوره‌ها — موتور مشترک (نسخهٔ ۱٫۴)
 *  ─ تفکیک کامل: هر صفحه (workshops.php / courses.php) فقط یک kind را نشان می‌دهد
 *  ─ کاور (آپلود/حذف)، اسلاگ یکتا، خلاصه، محل، برنامهٔ زمانی، ویرایشگر WYSIWYG
 *  ─ همراهان + تخفیف ویژه (درصدی/مبلغی/پلکانی) + کپی + خروجی اکسل ثبت‌نام‌شدگان
 *  استفاده: require این فایل پس از boot.php و فراخوانی admin_events_page($adm,'workshop')
 * =============================================================================
 */
declare(strict_types=1);
require_once FR_ROOT . '/includes/upload.php';
require_once FR_ROOT . '/includes/sanitize.php';

function admin_events_page(array $adm, string $kind): void
{
    $kind    = $kind === 'course' ? 'course' : 'workshop';
    $kindFa  = $kind === 'course' ? 'دوره' : 'کارگاه';
    $pageFa  = $kind === 'course' ? 'courses' : 'workshops';         // نام فایل صفحهٔ ادمین
    $self    = SITE_URL . '/admin/' . $pageFa . '.php';
    $public  = $kind === 'course' ? 'course' : 'workshop';            // نام فایل صفحهٔ عمومی تکی

    /* =========================================================================
     | اقدامات POST — ذخیره / حذف / کپی
     * ======================================================================*/
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string)post('act');

        /* ───────────────────────── ذخیره (ساخت/ویرایش) ───────────────────────── */
        if ($act === 'save') {
            $id = (int)post('id');

            /* پله‌های تخفیف گروهی → JSON تمیز — هر پله: حداقل نفرات + درصد + تعداد نفر رایگان */
            $tiers = [];
            foreach ((array)($_POST['tier_min'] ?? []) as $i => $m) {
                $m = (int)en_num((string)$m);
                $p = (int)en_num((string)(($_POST['tier_pct']  ?? [])[$i] ?? ''));
                $f = (int)en_num((string)(($_POST['tier_free'] ?? [])[$i] ?? ''));
                if ($m >= 1 && ($p > 0 || $f > 0)) {
                    $tiers[] = ['min'=>$m, 'percent'=>min(100, $p), 'free'=>max(0, $f)];
                }
            }
            usort($tiers, fn($a,$b) => $a['min'] <=> $b['min']);

            $wstype = (string)post('ws_discount_type','none');
            if (!in_array($wstype, ['none','percent','fixed','tiered'], true)) $wstype = 'none';
            $cMode = (string)post('companion_price_mode','fixed');
            if (!in_array($cMode, ['fixed','percent'], true)) $cMode = 'fixed';

            /* اسلاگ: اگر خالی بود از عنوان ساخته و یکتا می‌شود */
            $title = trim((string)post('title'));
            $slug  = trim((string)post('slug'));
            $slug  = fr_unique_slug('events', fr_slugify($slug !== '' ? $slug : $title), 'slug', $id);
            $oldSlug = $id ? (string)DB::val('SELECT slug FROM ' . DB::t('events') . ' WHERE id=?', [$id]) : '';
            $oldStatus = $id ? (string)DB::val('SELECT status FROM ' . DB::t('events') . ' WHERE id=?', [$id]) : '';

            /* لینک نقشه (۱٫۷) — فقط http(s) معتبر یا خالی */
            $mapUrl = function(string $k): ?string {
                $u = trim((string)post($k));
                if ($u === '') return null;
                return preg_match('#^https?://#i', $u) ? mb_substr($u, 0, 500) : null;
            };

            $data = [
                'kind'        => $kind,
                'title'       => $title,
                'slug'        => $slug,
                'short_desc'  => mb_substr(trim((string)post('short_desc')), 0, 250),
                'description' => fr_sanitize_html((string)post('description')),
                'location'    => mb_substr(trim((string)post('location')), 0, 190) ?: null,
                'map_google'  => $mapUrl('map_google'),
                'map_neshan'  => $mapUrl('map_neshan'),
                'map_balad'   => $mapUrl('map_balad'),
                'sms_template' => (($v = trim((string)post('sms_template'))) !== '' ? mb_substr($v, 0, 900) : null),
                'seo_title'   => (($v = trim((string)post('seo_title'))) !== '' ? mb_substr($v, 0, 200) : null),
                'seo_desc'    => (($v = trim((string)post('seo_desc'))) !== '' ? mb_substr($v, 0, 320) : null),
                'seo_kw'      => (($v = trim((string)post('seo_kw'))) !== '' ? mb_substr($v, 0, 120) : null),
                'seo_noindex' => (int)post('seo_noindex') === 1 ? 1 : 0,
                'schedule_text' => mb_substr(trim((string)post('schedule_text')), 0, 190) ?: null,
                'price'       => (int)preg_replace('/\D/','', en_num((string)post('price','0'))),
                /* ۲٫۸: قیمت با تخفیف — باید کمتر از قیمت اصلی باشد وگرنه نادیده گرفته می‌شود */
                'sale_price'  => (function(){ $sp = (int)preg_replace('/\D/','', en_num((string)post('sale_price',''))); $pr = (int)preg_replace('/\D/','', en_num((string)post('price','0'))); return ($sp > 0 && $sp < $pr) ? $sp : null; })(),
                'capacity'    => (($v = en_num((string)post('capacity'))) !== '' ? (int)$v : null),
                'teacher'     => trim((string)post('teacher')) ?: null,
                /* حیاتی: ساعت را نرمالیزه کن — فرم در حالت ویرایش با ارقام فارسی پر می‌شود (۱۸:۰۰)
                   و بدون en_num ورودی datetime نامعتبر → خطای SQL → صفحهٔ سفید. */
                'starts_at'   => ($j = jalali_to_mysql((string)post('starts_at'))) ? $j.' ' . fr_valid_time((string)post('starts_time'), '10:00') . ':00' : null,
                'ends_at'     => ($j = jalali_to_mysql((string)post('ends_at')))   ? $j.' ' . fr_valid_time((string)post('ends_time'),   '12:00') . ':00' : null,
                'status'      => post('status') === 'inactive' ? 'inactive' : 'active',
                'reg_open'    => (int)post('reg_open') === 1 ? 1 : 0,
                'companion_enabled' => (int)post('companion_enabled') === 1 ? 1 : 0,
                'companion_max'     => (($v = en_num((string)post('companion_max'))) !== '' ? max(0,(int)$v) : null),
                'companion_price'   => (($v = preg_replace('/\D/','', en_num((string)post('companion_price','')))) !== '' ? (int)$v : null),
                'companion_price_mode' => $cMode,
                'ws_discount_type'  => $wstype,
                'ws_discount_value' => (($v = preg_replace('/\D/','', en_num((string)post('ws_discount_value','')))) !== '' ? (int)$v : null),
                'ws_discount_tiers' => $tiers ? json_encode($tiers, JSON_UNESCAPED_UNICODE) : null,
                'ws_discount_scope' => in_array(post('ws_discount_scope'), ['all','main','companion'], true) ? post('ws_discount_scope') : 'all',
            ];

            /* ۳٫۰: پیش‌پرداخت (بیعانه) — فقط وقتی ذخیره می‌شود که مثبت و از قیمت نهایی کمتر باشد */
            {
                $depEn  = (int)post('deposit_enabled') === 1 ? 1 : 0;
                $depAmt = (int)preg_replace('/\D/', '', en_num((string)post('deposit_amount', '')));
                $finalP = fr_price_deal((int)$data['price'], $data['sale_price'])['final'];
                $data['deposit_enabled'] = ($depEn === 1 && $depAmt > 0 && $finalP > 0 && $depAmt < $finalP) ? 1 : 0;
                $data['deposit_amount']  = $data['deposit_enabled'] === 1 ? $depAmt : null;
            }

            /* ذخیرهٔ امن: هر خطای DB (مثل تداخل اسلاگ) به‌جای صفحهٔ سفید، پیام فارسی می‌دهد */
            try {
                if ($id) DB::update('events', $data, 'id=?', [$id]);
                else     $id = DB::insert('events', $data);
            } catch (Throwable $e) {
                error_log('Feria event save: ' . $e->getMessage());
                flash('ذخیره با خطا مواجه شد؛ لطفاً ورودی‌ها را بازبینی و دوباره تلاش کنید. (جزئیات در لاگ سرور)', 'err');
                redirect($self . ($id ? '?edit_e=' . $id : '?edit_e=new'));
            }

            /* ── کاور: آپلود جدید یا حذف ── */
            $old = DB::one('SELECT image FROM ' . DB::t('events') . ' WHERE id=?', [$id]);
            if ((int)post('remove_image') === 1 && $old && $old['image']) {
                fr_delete_image($old['image']);
                DB::update('events', ['image'=>null], 'id=?', [$id]);
            }
            if (!empty($_FILES['image']) && (int)($_FILES['image']['error'] ?? 4) !== 4) {
                /* کاور با موتور بازتولید در feria-uploads (تفکیک کارگاه و دوره) */
                $targetSubdir = ($kind === 'course' ? 'courses' : 'workshops');
                $prefix = ($kind === 'course' ? 'course' : 'workshop');
                $up = fr_save_cover($_FILES['image'], $targetSubdir, $prefix, $id);
                if ($up['ok']) {
                    if ($old && $old['image']) fr_delete_image($old['image']);
                    DB::update('events', ['image'=>$up['path']], 'id=?', [$id]);
                    if (!empty($up['thumb'])) {
                        flash('کاور به صورت خودکار به ۱۲۰۰×۶۷۵ برش و بهینه شد 🖼');
                    }
                } else {
                    flash('رویداد ذخیره شد؛ اما آپلود کاور ناموفق بود: ' . $up['error'], 'warn');
                    redirect($self . '?edit_e=' . $id);
                }
            }

            log_activity('admin_event_save', 'event', $id, ['kind'=>$kind]);
            // نسخهٔ ۱٫۷: اگر اسلاگ یا وضعیت تغییر کرده، پیوندهای یکتا + سایت‌مپ بازتولید شود
            if ($oldSlug !== $slug || ($id && $oldStatus !== $data['status'])) {
                require_once FR_ROOT . '/includes/seo.php';
                $fl = fr_permalinks_flush();
                if (!$fl['ok']) flash('روی سرور شما .htaccess قابل نوشتن نیست؛ لینک‌های زیبا از صفحهٔ «پیوندهای یکتا» بازتولید شود.', 'warn');
            }
            flash('«' . $title . '» با موفقیت ذخیره شد ✅');
            redirect($self);
        }

        /* ───────────────────────── عملیات گروهی (۱٫۷) ───────────────────────── */
        if ($act === 'bulk') {
            $op   = (string)post('op');
            $ids  = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
            if (!$ids) { flash('هیچ موردی انتخاب نشده است.', 'warn'); redirect($self); }
            if (!in_array($op, ['activate','deactivate','delete'], true)) { flash('عملیات نامعتبر است.', 'err'); redirect($self); }
            $done = 0; $skipped = 0;
            foreach ($ids as $eid) {
                $row = DB::one('SELECT id,image FROM ' . DB::t('events') . ' WHERE id=? AND kind=?', [$eid, $kind]);
                if (!$row) { $skipped++; continue; }
                if ($op === 'delete') {
                    // حذف فقط وقتی هیچ ثبت‌نام پرداخت‌شده‌ای ندارد (حفاظت از پول کاربران)
                    $paid = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') .
                                         " WHERE event_id=? AND status='paid'", [$eid]);
                    if ($paid > 0) { $skipped++; continue; }
                    if ($row['image']) fr_delete_image($row['image']);
                    DB::run('DELETE FROM ' . DB::t('event_companions') . ' WHERE reg_id IN (SELECT id FROM ' . DB::t('event_registrations') . ' WHERE event_id=?)', [$eid]);
                    DB::run('DELETE FROM ' . DB::t('event_registrations') . " WHERE event_id=? AND status<>'paid'", [$eid]);
                    DB::run('DELETE FROM ' . DB::t('events') . ' WHERE id=?', [$eid]);
                    $done++;
                } else {
                    DB::update('events', ['status' => $op === 'activate' ? 'active' : 'inactive'], 'id=?', [$eid]);
                    $done++;
                }
            }
            log_activity('admin_event_bulk', 'event', 0, ['kind'=>$kind,'op'=>$op,'count'=>$done,'skipped'=>$skipped]);
            require_once FR_ROOT . '/includes/seo.php';
            fr_permalinks_flush();
            $opFa = ['activate'=>'فعال','deactivate'=>'غیرفعال','delete'=>'حذف'][$op];
            flash(fa_num($done) . ' ' . $kindFa . ' با موفقیت ' . $opFa . ' شد' .
                  ($skipped ? ' — ' . fa_num($skipped) . ' مورد به‌خاطر داشتن ثبت‌نام پرداخت‌شده حذف نشد' : '') . ' ✅');
            redirect($self);
        }

        /* ───────────────────── بازتولید کاور (Regenerate) ───────────────────── */
        if ($act === 'regen_image') {
            $id  = (int)post('id');
            $row = DB::one('SELECT image FROM ' . DB::t('events') . ' WHERE id=? AND kind=?', [$id, $kind]);
            if ($row && $row['image'] && fr_regen_cover($row['image'])) {
                log_activity('admin_event_regen_image', 'event', $id, ['kind'=>$kind]);
                flash('کاور با موفقیت بازتولید شد: برش ۱۶:۹، اندازهٔ ۱۲۰۰×۶۷۵ و بندانگشتی تازه 🖼');
            } else {
                flash('بازتولید انجام نشد — یا کاوری ثبت نشده یا افزونهٔ GD روی سرور فعال نیست.', 'warn');
            }
            redirect($self . '?edit_e=' . $id);
        }

        /* ───────────────────────── حذف ───────────────────────── */
        if ($act === 'delete') {
            $id  = (int)post('id');
            $row = DB::one('SELECT image,title FROM ' . DB::t('events') . ' WHERE id=? AND kind=?', [$id, $kind]);
            if ($row) {
                // نسخهٔ ۱٫۷: حذف ناامن مسدود است — رویدادی که ثبت‌نام پرداخت‌شده دارد حذف نمی‌شود
                $paid = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') .
                                     " WHERE event_id=? AND status='paid'", [$id]);
                if ($paid > 0) {
                    flash('«' . $row['title'] . '» دارای ' . fa_num($paid) . ' ثبت‌نام پرداخت‌شده است و حذف نمی‌شود؛ ابتدا آن را غیرفعال (پیش‌نویس) کنید.', 'err');
                    redirect($self);
                }
                if ($row['image']) fr_delete_image($row['image']);
                // پاک‌سازی همراهان و ثبت‌نام‌های ناپرداخت (۱٫۷ — بدون باقی‌گذاشتن دادهٔ یتیم)
                DB::run('DELETE FROM ' . DB::t('event_companions') . ' WHERE reg_id IN (SELECT id FROM ' . DB::t('event_registrations') . ' WHERE event_id=?)', [$id]);
                DB::run('DELETE FROM ' . DB::t('event_registrations') . ' WHERE event_id=?', [$id]);
                DB::run('DELETE FROM ' . DB::t('events') . ' WHERE id=?', [$id]);
                log_activity('admin_event_delete', 'event', $id, ['kind'=>$kind]);
                require_once FR_ROOT . '/includes/seo.php';
                fr_permalinks_flush();
            }
            flash($kindFa . ' حذف شد.');
            redirect($self);
        }

        /* ───────────────────────── کپی با همهٔ تنظیمات ───────────────────────── */
        if ($act === 'copy') {
            $srcId = (int)post('id');
            $src   = DB::one('SELECT * FROM ' . DB::t('events') . ' WHERE id=? AND kind=?', [$srcId, $kind]);
            if ($src) {
                $img = $src['image'];
                unset($src['id'], $src['created_at']);
                $src['title']  = trim((string)$src['title']) . ' (کپی)';
                $src['slug']   = null;                      // اسلاگ تازهٔ یکتا
                $src['status'] = 'inactive';                // کپی ابتدا غیرفعال است
                $src['views']  = 0;
                $src['image']  = null;                      // کاور فایل مشترک نیست؛ مدیر جداگانه می‌گذارد
                try {
                    $newId = DB::insert('events', $src);
                    $slug  = fr_unique_slug('events', fr_slugify($src['title']), 'slug', $newId);
                    DB::update('events', ['slug'=>$slug], 'id=?', [$newId]);
                    // کاور کپی را از مبدا کپی فیزیکی می‌کنیم (فایل مجزا در feria-uploads)
                    if ($img && is_file(FR_ROOT . '/' . $img)) {
                        $targetSubdir = ($src['kind'] === 'course' ? 'courses' : 'workshops');
                        $ext  = pathinfo($img, PATHINFO_EXTENSION);
                        $dir  = FR_ROOT . '/feria-uploads/' . $targetSubdir;
                        if (!is_dir($dir)) @mkdir($dir, 0755, true);
                        $fn = ($src['kind'] === 'course' ? 'course' : 'workshop') . '-' . $newId . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
                        if (@copy(FR_ROOT . '/' . $img, $dir . '/' . $fn)) {
                            DB::update('events', ['image'=>'feria-uploads/' . $targetSubdir . '/' . $fn], 'id=?', [$newId]);
                        }
                    }
                } catch (Throwable $e) {}
                log_activity('admin_event_copy', 'event', $srcId, ['kind'=>$kind]);
                flash('کپی ساخته شد (غیرفعال) — آن را ویرایش و فعال کنید 📋');
            }
            redirect($self);
        }
    }

    /* =========================================================================
     | خروجی اکسل ثبت‌نام‌شدگان (نفر اصلی + همراهان)
     * ======================================================================*/
    if (isset($_GET['export_regs'])) {
        $eid  = (int)$_GET['export_regs'];
        $ev   = DB::one('SELECT id,title FROM ' . DB::t('events') . ' WHERE id=? AND kind=?', [$eid, $kind]);
        if ($ev) {
            $regs = DB::all('SELECT r.*, u.first_name, u.last_name, u.mobile, u.national_code
                             FROM ' . DB::t('event_registrations') . ' r
                             JOIN ' . DB::t('users') . ' u ON u.id=r.user_id WHERE r.event_id=? ORDER BY r.id', [$eid]);
            $out = [];
            foreach ($regs as $r) {
                $meta = $r['discount_meta'] ? json_decode($r['discount_meta'], true) : null;
                $payable = $meta['payable'] ?? ($r['amount_base'] - $r['discount_amount']);
                $out[] = [$r['id'], 'نفر اصلی', trim($r['first_name'].' '.$r['last_name']), $r['mobile'], $r['national_code'] ?? '',
                          toman((int)$payable), $r['status'], fa_num(jdate('Y/m/d H:i', strtotime($r['created_at'])))];
                foreach (DB::all('SELECT * FROM ' . DB::t('event_companions') . ' WHERE reg_id=? ORDER BY id', [(int)$r['id']]) as $c) {
                    $out[] = [$r['id'], 'همراه', trim($c['first_name'].' '.(string)$c['last_name']), $c['mobile'] ?? '—', '', '', '', ''];
                }
            }
            export_xls($kind . '-' . $eid . '-regs', 'ثبت‌نام‌شدگان ' . $kindFa . ' «' . $ev['title'] . '»',
                       ['# ثبت‌نام','نقش','نام','موبایل','کد ملی','مبلغ','وضعیت','تاریخ'], $out);
        }
        redirect($self);
    }

    /* =========================================================================
     | داده‌های صفحه
     * ======================================================================*/
    $rows = DB::all("SELECT e.*, (SELECT COALESCE(SUM(1 + COALESCE(r.companion_count,0)),0)
                        FROM " . DB::t('event_registrations') . " r WHERE r.event_id=e.id AND r.status='paid') AS seats
                     FROM " . DB::t('events') . " e WHERE e.kind=? ORDER BY e.id DESC", [$kind]);
    $editE = ($ee = (int)($_GET['edit_e'] ?? 0))
           ? DB::one('SELECT * FROM ' . DB::t('events') . ' WHERE id=? AND kind=?', [$ee, $kind]) : null;
    $toJ = fn($dt) => $dt ? fa_num(jdate('Y/m/d', strtotime($dt))) : '';
    $tiersNow = $editE && $editE['ws_discount_tiers'] ? json_decode($editE['ws_discount_tiers'], true) : [];
    if (!is_array($tiersNow)) $tiersNow = [];

    admin_layout($adm, $pageFa, ($kind === 'course' ? 'دوره‌ها' : 'کارگاه‌ها') . ' — مدیریت اختصاصی');
    ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/editor.css?v=<?= FR_VERSION ?>">
<?= flash() ?>

<?php if (!$editE): ?>
<!-- ── نوار ابزار بالای لیست ─────────────────────────────────────── -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
  <a class="fr-btn sm" href="<?= $self ?>?edit_e=new">➕ <?= $kindFa ?> جدید</a>
  <a class="fr-btn sm ghost" href="<?= SITE_URL ?>/<?= $pageFa ?>.php" target="_blank" rel="noopener">🌐 صفحهٔ عمومی <?= $kindFa ?>‌ها</a>
  <span style="color:var(--muted);font-size:12.5px"><?= fa_num(count($rows)) ?> <?= $kindFa ?> ثبت شده</span>
</div>
<?php endif; ?>

<div class="fr-grid <?= $editE ? 'cols-2' : '' ?>">
  <?php if ($editE || isset($_GET['edit_e'])): ?>
  <!-- ═══════════════════ فرم ساخت/ویرایش ═══════════════════ -->
  <div class="fr-card" style="margin:0;align-self:start">
    <h3><?= $editE ? 'ویرایش «' . e($editE['title']) . '»' : $kindFa . ' جدید' ?>
      <a class="more" href="<?= $self ?>">بازگشت به فهرست ←</a></h3>

    <form method="post" enctype="multipart/form-data" id="evForm">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)($editE['id'] ?? 0) ?>">

      <div class="fr-field">
        <label>عنوان <?= $kindFa ?> <span class="req">*</span></label>
        <input class="fr-input" name="title" value="<?= e($editE['title'] ?? '') ?>" required>
      </div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field">
          <label>اسلاگ صفحهٔ عمومی (خالی = خودکار از عنوان)</label>
          <input class="fr-input" name="slug" value="<?= e($editE['slug'] ?? '') ?>" dir="ltr" style="text-align:left" placeholder="stress-workshop">
          <?php if ($editE && $editE['slug']): ?>
          <small class="hint"><a href="<?= SITE_URL ?>/<?= $public ?>.php?s=<?= urlencode($editE['slug']) ?>" target="_blank" rel="noopener">مشاهدهٔ صفحهٔ عمومی ↗</a></small>
          <?php endif; ?>
        </div>
        <div class="fr-field">
          <label>مدرس/سخنران</label>
          <input class="fr-input" name="teacher" value="<?= e($editE['teacher'] ?? '') ?>">
        </div>
      </div>
      <div class="fr-field">
        <label>خلاصهٔ کوتاه (در کارت‌ها و سئو نمایش داده می‌شود)</label>
        <input class="fr-input" name="short_desc" maxlength="250" value="<?= e($editE['short_desc'] ?? '') ?>"
               placeholder="یک جملهٔ جذاب دربارهٔ این <?= $kindFa ?>…">
      </div>
      <div class="fr-field">
        <label>توضیحات کامل — ویرایشگر پیشرفته</label>
        <div class="fre" id="freDesc" data-ph="توضیحات کامل <?= $kindFa ?> را این‌جا بنویسید؛ سرفصل‌ها، مخاطبان، پیش‌نیازها…"><?= $editE ? fr_sanitize_html((string)$editE['description']) : '' ?></div>
        <input type="hidden" name="description" id="hdDesc">
      </div>

      <!-- کاور -->
      <div class="fr-field">
        <label>تصویر کاور (JPG/PNG/WEBP — حداکثر ۵ مگابایت)</label>
        <?php if (!empty($editE['image'])): ?>
        <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
          <img src="<?= e(SITE_URL . '/' . $editE['image']) ?>" alt="" style="width:120px;height:68px;object-fit:cover;border-radius:12px;border:1px solid var(--line)">
          <label class="tog"><input type="checkbox" name="remove_image" value="1"> حذف کاور فعلی</label>
          <button class="fr-btn sm ghost" type="submit" name="act" value="regen_image"
                  onclick="return confirm('کاور فعلی دوباره برش و بهینه‌سازی شود؟')">🖼 بازتولید تصویر</button>
        </div>
        <?php endif; ?>
        <input class="fr-input" type="file" name="image" accept="image/jpeg,image/png,image/webp" id="evImg">
        <small class="hint" style="line-height:2">
          📐 مشخصات استاندارد کاور: <b>۱۲۰۰×۶۷۵ پیکسل</b> (نسبت ۱۶:۹) — نگران اندازهٔ عکس نباشید؛
          هر تصویری که بارگذاری کنید به‌صورت خودکار از مرکز برش می‌خورد، به همین اندازه می‌رسد،
          بهینه و بدون داده‌های اضافی (EXIF) ذخیره می‌شود و یک بندانگشتی کوچک هم برای آن ساخته خواهد شد.
          دکمهٔ «بازتولید تصویر» همین پردازش را روی کاور فعلی دوباره انجام می‌دهد.
        </small>
        <img id="evImgPrev" alt="" style="display:none;width:100%;max-height:180px;object-fit:cover;border-radius:14px;margin-top:8px">
      </div>

      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>وضعیت</label>
          <select class="fr-select" name="status">
            <option value="active" <?= ($editE['status'] ?? 'active')==='active'?'selected':'' ?>>فعال (عمومی)</option>
            <option value="inactive" <?= ($editE['status'] ?? '')==='inactive'?'selected':'' ?>>غیرفعال (پیش‌نویس)</option>
          </select></div>
        <div class="fr-field"><label>ثبت‌نام</label>
          <select class="fr-select" name="reg_open">
            <option value="1" <?= ($editE['reg_open'] ?? 1)==1?'selected':'' ?>>باز</option>
            <option value="0" <?= ($editE && !(int)$editE['reg_open'])?'selected':'' ?>>بسته</option>
          </select></div>
        <div class="fr-field"><label>قیمت (تومان — صفر یعنی رایگان)</label>
          <input class="fr-input" name="price" id="evPrice" inputmode="numeric" value="<?= $editE ? fa_num(number_format($editE['price'])) : '' ?>"></div>
        <div class="fr-field"><label>قیمت با تخفیف (تومان — اختیاری؛ کمتر از قیمت اصلی)</label>
          <input class="fr-input" name="sale_price" id="evSalePrice" inputmode="numeric" value="<?= $editE && !empty($editE['sale_price']) ? fa_num(number_format((int)$editE['sale_price'])) : '' ?>" placeholder="مثلاً ۷۰۰٬۰۰۰">
          <small class="hint" id="evSaleHint" style="color:#b91c1c;font-weight:700"></small></div>
        <div class="fr-field"><label>ظرفیت (خالی = نامحدود)</label>
          <input class="fr-input" name="capacity" inputmode="numeric" value="<?= fa_num($editE['capacity'] ?? '') ?>"></div>
        <div class="fr-field"><label>تاریخ شروع (شمسی)</label>
          <input class="fr-input" name="starts_at" value="<?= $toJ($editE['starts_at'] ?? null) ?>" placeholder="۱۴۰۵/۰۶/۰۱"></div>
        <div class="fr-field"><label>ساعت شروع</label>
          <input class="fr-input" name="starts_time" value="<?= $editE && $editE['starts_at'] ? fa_num(date('H:i', strtotime($editE['starts_at']))) : '' ?>" placeholder="۱۸:۰۰"></div>
        <div class="fr-field"><label>تاریخ پایان</label>
          <input class="fr-input" name="ends_at" value="<?= $toJ($editE['ends_at'] ?? null) ?>"></div>
        <div class="fr-field"><label>ساعت پایان</label>
          <input class="fr-input" name="ends_time" value="<?= $editE && $editE['ends_at'] ? fa_num(date('H:i', strtotime($editE['ends_at']))) : '' ?>" placeholder="۲۱:۰۰"></div>
        <div class="fr-field"><label>محل برگزاری / لینک آنلاین</label>
          <input class="fr-input" name="location" value="<?= e($editE['location'] ?? '') ?>" placeholder="تهران، سعادت‌آباد، مرکز فریا"></div>
        <div class="fr-field"><label>برنامهٔ زمانی متنی (برای دوره‌های چندجلسه‌ای)</label>
          <input class="fr-input" name="schedule_text" value="<?= e($editE['schedule_text'] ?? '') ?>" placeholder="شنبه‌ها و چهارشنبه‌ها ۱۷ تا ۱۹"></div>
      </div>

      <hr style="border:none;border-top:1.5px dashed #d4dcec;margin:16px 0">
      <h3 style="font-size:14px">💳 پیش‌پرداخت (بیعانه) — نسخهٔ ۳٫۰</h3>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>پرداخت بیعانه در ثبت‌نام</label>
          <select class="fr-select" name="deposit_enabled" id="depEnabled">
            <option value="0" <?= ($editE && !(int)($editE['deposit_enabled'] ?? 0)) ? 'selected' : '' ?>>غیرفعال — فقط پرداخت کامل</option>
            <option value="1" <?= ($editE && (int)($editE['deposit_enabled'] ?? 0)) ? 'selected' : '' ?>>فعال — کاربر می‌تواند بیعانه بدهد</option>
          </select></div>
        <div class="fr-field"><label>مبلغ پیش‌پرداخت (تومان)</label>
          <input class="fr-input" name="deposit_amount" id="depAmount" inputmode="numeric"
                 value="<?= $editE && !empty($editE['deposit_amount']) ? fa_num(number_format((int)$editE['deposit_amount'])) : '' ?>" placeholder="مثلاً ۳۰۰٬۰۰۰">
          <small class="hint" id="depHint" style="font-weight:700"></small></div>
      </div>
      <small style="display:block;color:var(--muted);line-height:2;margin-top:2px">
        با فعال‌سازی، کاربر در فرم ثبت‌نام می‌تواند به‌جای کل مبلغ فقط <b>بیعانه</b> بپردازد تا جایش همین همان لحظه قطعی شود؛
        ماندهٔ حساب را «حداکثر تا ۳ روز مانده به برگزاری» با یادآور خودکار سیستم (پیامک + تلگرام/بله) و لینک اختصاصی تصفیه تسویه می‌کند.
        مبلغ بیعانه باید از قیمت نهایی (پس از تخفیف قیمت‌گذاری) کمتر باشد.
      </small>

      <hr style="border:none;border-top:1.5px dashed #d4dcec;margin:16px 0">
      <h3 style="font-size:14px">👥 تنظیمات همراهان</h3>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>ثبت‌نام همراه</label>
          <select class="fr-select" name="companion_enabled">
            <option value="0" <?= ($editE && !(int)$editE['companion_enabled'])?'selected':'' ?>>غیرفعال</option>
            <option value="1" <?= ($editE && (int)$editE['companion_enabled'])?'selected':'' ?>>فعال</option>
          </select></div>
        <div class="fr-field"><label>سقف همراه (خالی/۰ = نامحدود)</label>
          <input class="fr-input" name="companion_max" inputmode="numeric" value="<?= fa_num($editE['companion_max'] ?? '') ?>"></div>
        <div class="fr-field"><label>نحوهٔ قیمت همراه</label>
          <select class="fr-select" name="companion_price_mode">
            <option value="fixed" <?= ($editE['companion_price_mode'] ?? 'fixed')==='fixed'?'selected':'' ?>>مبلغ ثابت (تومان)</option>
            <option value="percent" <?= ($editE['companion_price_mode'] ?? '')==='percent'?'selected':'' ?>>درصدی از شهریهٔ اصلی</option>
          </select></div>
        <div class="fr-field"><label>مبلغ/درصد هر همراه (خالی = قیمت اصلی)</label>
          <input class="fr-input" name="companion_price" inputmode="numeric" value="<?= fa_num(number_format((int)($editE['companion_price'] ?? 0))) ?>"></div>
      </div>

      <hr style="border:none;border-top:1.5px dashed #d4dcec;margin:16px 0">
      <h3 style="font-size:14px">٪ تخفیف ویژهٔ این <?= $kindFa ?></h3>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>نوع تخفیف</label>
          <select class="fr-select" name="ws_discount_type" id="wsType">
            <?php foreach (['none'=>'ندارد','percent'=>'درصدی','fixed'=>'مبلغی (تومان)','tiered'=>'پلکانی (بر اساس تعداد نفرات)'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editE['ws_discount_type'] ?? 'none')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fr-field"><label>مقدار (درصد یا تومان)</label>
          <input class="fr-input" name="ws_discount_value" inputmode="numeric" value="<?= fa_num(number_format((int)($editE['ws_discount_value'] ?? 0))) ?>"></div>
        <div class="fr-field"><label>اعمال روی</label>
          <select class="fr-select" name="ws_discount_scope">
            <?php foreach (['all'=>'جمع کل (نفر اصلی + همراهان)','main'=>'فقط نفر اصلی','companion'=>'فقط همراهان'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editE['ws_discount_scope'] ?? 'all')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div id="tiersBox" style="display:none;background:#faf8ff;border:1.5px dashed #d4dcec;border-radius:12px;padding:12px">
        <label style="font-size:12.5px;color:var(--muted);line-height:2">
          پله‌های تخفیف گروهی — «تعداد نفرات» یعنی نفراتِ <b>پرداخت‌کننده</b> (نفر اصلی + همراهان)؛ میهمان رایگان در شمارش پله حساب نمی‌شود.<br>
          در هر پله می‌توانید «درصد تخفیف»، «چند میهمان رایگان» یا هر دو را تعیین کنید.<br>
          مثال: از ۱۱ نفر → ۳۰٪ تخفیف + ۲ میهمان رایگان. به‌محض رسیدن تعداد همراهان به آستانه، فیلدهای نام/موبایلِ میهمان رایگان در فرم ثبت‌نام ظاهر می‌شود؛<br>
          میهمان رایگان هزینه‌ای ندارد ولی صندلی مصرف می‌کند (مثلاً ۱۱ نفر پرداخت‌کننده + ۲ میهمان = ۱۳ صندلی).
        </label>
        <?php for ($i = 0; $i < 5; $i++): ?>
        <div style="display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap">
          <span style="font-size:12px;white-space:nowrap">از نفر</span>
          <input class="fr-input" name="tier_min[]" inputmode="numeric" value="<?= fa_num($tiersNow[$i]['min'] ?? '') ?>" placeholder="۲" style="max-width:70px">
          <span style="font-size:12px;white-space:nowrap">تخفیف</span>
          <input class="fr-input" name="tier_pct[]" inputmode="numeric" value="<?= fa_num($tiersNow[$i]['percent'] ?? '') ?>" placeholder="۲۰" style="max-width:70px">
          <span style="font-size:12px">٪</span>
          <span style="font-size:12px;white-space:nowrap">+</span>
          <input class="fr-input" name="tier_free[]" inputmode="numeric" value="<?= fa_num($tiersNow[$i]['free'] ?? '') ?>" placeholder="۰" style="max-width:70px">
          <span style="font-size:12px;white-space:nowrap">میهمان رایگان 🎁</span>
        </div>
        <?php endfor; ?>
        <small style="display:block;margin-top:8px;font-size:11.5px;color:var(--muted)">
          «میهمان رایگان» صندلی مصرف می‌کند ولی هزینه‌ای روی فاکتور نمی‌گذارد؛ ظرفیت رویداد با مجموع نفراتِ حاضر (پولی + رایگان) کنترل می‌شود. اگر پله‌ای فقط میهمان رایگان دارد، درصد را خالی بگذارید (و برعکس).
        </small>
      </div>

      <hr style="border:none;border-top:1.5px dashed #d4dcec;margin:16px 0">
      <h3 style="font-size:14px">🗺 لینک‌های نقشهٔ محل برگزاری (نسخهٔ ۱٫۷)</h3>
      <small style="display:block;color:var(--muted);line-height:2;margin-bottom:8px">
        دکمهٔ مسیریاب در صفحهٔ عمومی <?= $kindFa ?> فقط برای نقشه‌هایی ساخته می‌شود که لینکشان را این‌جا بگذارید؛ اگر خالی بمانند، هیچ دکمه‌ای نشان داده نمی‌شود.
      </small>
      <div class="fr-grid cols-3" style="gap:10px">
        <div class="fr-field"><label>🧭 گوگل مپ</label>
          <input class="fr-input" name="map_google" dir="ltr" style="text-align:left" value="<?= e($editE['map_google'] ?? '') ?>" placeholder="https://maps.google.com/…"></div>
        <div class="fr-field"><label>🗺 نشان (neshan)</label>
          <input class="fr-input" name="map_neshan" dir="ltr" style="text-align:left" value="<?= e($editE['map_neshan'] ?? '') ?>" placeholder="https://neshan.org/maps/…"></div>
        <div class="fr-field"><label>📍 بلد (balad)</label>
          <input class="fr-input" name="map_balad" dir="ltr" style="text-align:left" value="<?= e($editE['map_balad'] ?? '') ?>" placeholder="https://balad.ir/…"></div>
      </div>

      <hr style="border:none;border-top:1.5px dashed #d4dcec;margin:16px 0">
      <h3 style="font-size:14px">✉️ پیامک اختصاصی پس از ثبت‌نام این <?= $kindFa ?> (نسخهٔ ۱٫۷)</h3>
      <div class="fr-field">
        <label>متن پیامک — خالی = استفاده از قالب پیش‌فرض سیستم</label>
        <textarea class="fr-textarea" name="sms_template" rows="3" maxlength="900" id="evSmsTpl"
                  placeholder="%name% عزیز، ثبت‌نام شما در «%event%» ثبت شد. مبلغ: %amount% تومان — کد رهگیری: %refid%"><?= e($editE['sms_template'] ?? '') ?></textarea>
        <small class="hint" style="line-height:2">
          متغیرهای مجاز: <code>%name%</code> نام کاربر، <code>%event%</code> عنوان <?= $kindFa ?>،
          <code>%amount%</code> مبلغ پرداختی، <code>%refid%</code> کد رهگیری، <code>%site%</code> نام سایت، <code>%date%</code> تاریخ امروز.
          اگر این باکس را خالی بگذارید، قالب پیش‌فرضِ «پس از پرداخت موفق» (قابل ویرایش از صفحهٔ قالب‌های پیامک) ارسال می‌شود.
        </small>
      </div>

      <hr style="border:none;border-top:1.5px dashed #d4dcec;margin:16px 0">
      <h3 style="font-size:14px">🔎 سئوی صفحهٔ عمومی — پیش‌نمایشی شبیه گوگل (نسخهٔ ۱٫۷)</h3>
      <!-- پیش‌نمایش زندهٔ ریچ‌اسنیپت -->
      <div id="seoPv" style="direction:ltr;text-align:left;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:12px;box-shadow:0 3px 14px rgba(80,60,160,.06)">
        <div style="color:#1a0dab;font-size:16px;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" id="seoPvTitle"><?= e($editE['title'] ?? ('عنوان ' . $kindFa)) ?></div>
        <div style="color:#006621;font-size:12px;direction:ltr" id="seoPvUrl"><?= e(SITE_URL) ?>/<?= $public ?>/<?= e($editE['slug'] ?? 'slug') ?></div>
        <div style="color:#545454;font-size:12.5px;line-height:1.6" id="seoPvDesc"><?= e($editE['short_desc'] ?? 'توضیح کوتاه این صفحه…') ?></div>
      </div>
      <div class="fr-grid cols-2" style="gap:10px">
        <div class="fr-field"><label>عنوان سئو <small style="color:var(--muted)">(بیشینهٔ پیشنهادی ۶۰ کاراکتر)</small></label>
          <input class="fr-input" name="seo_title" maxlength="200" id="seoTitle" value="<?= e($editE['seo_title'] ?? '') ?>" placeholder="خالی = عنوان <?= $kindFa ?>">
          <div class="seo-count" data-countfor="seoTitle" style="font-size:11px;color:var(--muted)"></div></div>
        <div class="fr-field"><label>کلمات کلیدی (با ویرگول)</label>
          <input class="fr-input" name="seo_kw" maxlength="120" value="<?= e($editE['seo_kw'] ?? '') ?>" placeholder="مثلاً کارگاه روانشناسی, مدیریت استرس"></div>
      </div>
      <div class="fr-field"><label>توضیحات متا <small style="color:var(--muted)">(بیشینهٔ پیشنهادی ۱۶۰ کاراکتر)</small></label>
        <textarea class="fr-textarea" name="seo_desc" rows="2" maxlength="320" id="seoDesc" placeholder="خالی = خلاصهٔ کوتاه <?= $kindFa ?>"><?= e($editE['seo_desc'] ?? '') ?></textarea>
        <div class="seo-count" data-countfor="seoDesc" style="font-size:11px;color:var(--muted)"></div>
      </div>
      <label class="tog"><input type="checkbox" name="seo_noindex" value="1" <?= !empty($editE['seo_noindex']) ? 'checked' : '' ?>>
        این صفحه در موتورهای جست‌وجو نمایش داده نشود (noindex)</label>

      <button class="fr-btn sm" style="margin-top:16px">ذخیرهٔ <?= $kindFa ?></button>
      <a class="fr-btn ghost sm" href="<?= $self ?>">لغو</a>
    </form>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════ فهرست + عملیات گروهی (۱٫۷) ═══════════════════ -->
  <?php if (!$editE && !isset($_GET['edit_e'])): ?>
  <div class="fr-card" style="margin:0">
    <h3><?= $kindFa ?>‌ها (<?= fa_num(count($rows)) ?>)</h3>
    <form method="post" id="bulkForm">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="bulk">
      <!-- نوار عملیات گروهی -->
      <div class="fr-bulkbar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f7f4ff;border:1px solid #e9e2fb;border-radius:14px;padding:10px 12px;margin-bottom:12px">
        <label class="tog" style="margin:0"><input type="checkbox" id="bulkAll"> انتخاب همه</label>
        <span class="fr-chip purple" id="bulkCount" style="font-size:11px">۰ مورد انتخاب شد</span>
        <select class="fr-select" name="op" style="max-width:230px">
          <option value="deactivate">⏸ غیرفعال کردن انتخاب‌شده‌ها</option>
          <option value="activate">▶ فعال‌سازی انتخاب‌شده‌ها</option>
          <option value="delete">🗑 حذف انتخاب‌شده‌ها (فقط بدون ثبت‌نام پرداخت‌شده)</option>
        </select>
        <button class="fr-btn sm" type="submit">اعمال عملیات گروهی</button>
      </div>
    <div style="overflow-x:auto">
    <table class="fr-table">
      <thead><tr><th style="width:34px"></th><th>عنوان</th><th>قیمت</th><th>ثبت‌نام</th><th>بازدید</th><th>شروع</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $e): ?>
      <tr style="<?= $e['status']==='active'?'':'opacity:.5' ?>">
        <td><input type="checkbox" name="ids[]" value="<?= (int)$e['id'] ?>" class="bulk-cb" style="width:17px;height:17px"></td>
        <td style="font-size:12.5px;min-width:220px">
          <div style="display:flex;gap:10px;align-items:center">
            <?php if ($e['image']): ?>
            <img src="<?= e(SITE_URL . '/' . $e['image']) ?>" alt="" loading="lazy" style="width:52px;height:34px;object-fit:cover;border-radius:9px;flex:0 0 auto">
            <?php else: ?>
            <span style="width:52px;height:34px;border-radius:9px;background:linear-gradient(135deg,#eaf0f8,#e6defc);display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto"><?= $kind === 'course' ? '📚' : '🛠' ?></span>
            <?php endif; ?>
            <div>
              <b><?= e($e['title']) ?></b><br>
              <small style="color:var(--muted)">
                <?= e($e['teacher'] ?? '') ?>
                <?= !(int)$e['reg_open'] ? ' · ⛔ ثبت‌نام بسته' : '' ?>
                <?= (int)$e['companion_enabled'] ? ' · 👥' : '' ?>
                <?= $e['ws_discount_type']!=='none' ? ' · ٪' : '' ?>
                <?= (int)($e['deposit_enabled'] ?? 0) ? ' · 💳 بیعانه' : '' ?>
              </small>
            </div>
          </div>
        </td>
        <td style="font-size:12px;white-space:nowrap"><?php $eDeal = fr_price_deal((int)$e['price'], isset($e['sale_price']) ? (int)$e['sale_price'] : null);
          if (!$e['price']): ?>رایگان<?php elseif ($eDeal['has_sale']): ?><del style="color:#94a3b8"><?= toman($eDeal['list']) ?></del><br><b style="color:#b91c1c"><?= toman($eDeal['final']) ?></b> <span class="fr-chip" style="background:#fef2f2;color:#b91c1c;font-size:10px">٪<?= fa_num($eDeal['percent']) ?></span><?php else: ?><?= toman((int)$e['price']) ?><?php endif; ?></td>
        <td><?= fa_num((int)$e['seats']) ?><?= $e['capacity'] ? '/'.fa_num($e['capacity']) : '' ?></td>
        <td><?= fa_num((int)$e['views']) ?></td>
        <td style="font-size:11.5px;white-space:nowrap"><?= $e['starts_at'] ? fa_num(jdate('j F', strtotime($e['starts_at']))) : '—' ?></td>
        <td class="actions" style="white-space:nowrap">
          <a class="fr-btn sm ghost" href="?edit_e=<?= $e['id'] ?>" target="_blank" title="ویرایش در برگهٔ جدید">ویرایش ↗</a>
          <?php if ($e['slug']): ?>
          <a class="fr-btn sm ghost" href="<?= SITE_URL ?>/<?= $public ?>.php?s=<?= urlencode($e['slug']) ?>" target="_blank" rel="noopener" title="مشاهدهٔ صفحهٔ عمومی">🌐</a>
          <?php endif; ?>
          <a class="fr-btn sm accent" href="?export_regs=<?= $e['id'] ?>" title="خروجی اکسل ثبت‌نام‌شدگان و همراهان">📊</a>
          <a class="fr-btn sm ghost" href="registrants.php?ctx=<?= urlencode($kind) ?>&id=<?= $e['id'] ?>" target="_blank" title="مدیریت ثبت‌نام‌شدگان (افزودن/ویرایش/حذف) — ۱٫۹">👥</a>
          <button type="button" class="fr-btn sm ghost" onclick="frCopyEvent(<?= (int)$e['id'] ?>)" title="کپی کردن با همهٔ تنظیمات">📋</button>
          <button type="button" class="fr-btn sm danger" onclick="frDeleteEvent(<?= (int)$e['id'] ?>, '<?= e(addslashes($e['title'])) ?>')" title="حذف">حذف</button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<!-- فرم مجزای اقدامات تکی سطرهای رویداد (جلوگیری از تداخل فرم‌های تودرتو) -->
<form method="post" id="evRowActionForm" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" id="evRowAct" value="">
  <input type="hidden" name="id" id="evRowId" value="">
</form>

<script src="<?= SITE_URL ?>/assets/js/editor.js?v=<?= FR_VERSION ?>"></script>
<script>
/* اتصال ویرایشگر به فرم (اگر فرم باز است) + نمایش/پنهان‌کردن پله‌ها + پیش‌نمایش کاور */
(function(){
  var area = document.getElementById('freDesc');
  if (area && window.FrEditor) window.FrEditor.attach(area, document.getElementById('hdDesc'));

  var sel = document.getElementById('wsType'), box = document.getElementById('tiersBox');
  if (sel && box) { var t = function(){ box.style.display = sel.value === 'tiered' ? 'block' : 'none'; };
    sel.addEventListener('change', t); t(); }

  var img = document.getElementById('evImg'), prev = document.getElementById('evImgPrev');
  if (img && prev) img.addEventListener('change', function(){
    if (img.files && img.files[0]) { prev.src = URL.createObjectURL(img.files[0]); prev.style.display = 'block'; }
    else { prev.style.display = 'none'; prev.removeAttribute('src'); }
  });

  /* ۲٫۸: پیش‌نمایش زندهٔ درصد تخفیف قیمت‌گذاری */
  var pIn = document.getElementById('evPrice'), sIn = document.getElementById('evSalePrice'), hIn = document.getElementById('evSaleHint');
  if (pIn && sIn && hIn) {
    var faD = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    var calc = function(){
      var dig = function(v){ return parseInt(String(v).replace(/[۰-۹٠-٩]/g, function(ch){ var i='۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(ch); return i>=0 ? String(i%10) : ch; }).replace(/\D/g,'')) || 0; };
      var p = dig(pIn.value), sp = dig(sIn.value);
      if (sp > 0 && p > 0 && sp < p) { hIn.textContent = '٪' + String(Math.round(100*(p-sp)/p)).replace(/\d/g, function(d){ return faD[+d]; }) + ' تخفیف روی قیمت اصلی اعمال و نمایش داده می‌شود.'; }
      else if (sp > 0) { hIn.textContent = '⚠️ قیمت با تخفیف باید کمتر از قیمت اصلی باشد؛ در غیر این صورت ذخیره نمی‌شود.'; }
      else { hIn.textContent = ''; }
    };
    pIn.addEventListener('input', calc); sIn.addEventListener('input', calc); calc();
  }

  /* ۳٫۰: اعتبارسنجی زندهٔ مبلغ پیش‌پرداخت (بیعانه) */
  var dSel = document.getElementById('depEnabled'), dIn = document.getElementById('depAmount'),
      dHint = document.getElementById('depHint');
  if (dIn && dHint) {
    var digD = function(v){ return parseInt(String(v).replace(/[۰-۹٠-٩]/g, function(ch){ var i='۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(ch); return i>=0 ? String(i%10) : ch; }).replace(/\D/g,'')) || 0; };
    var faD2 = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    var fmt = function(n){ return String(n.toLocaleString('en-US')).replace(/[0-9]/g, function(d){ return faD2[+d]; }).replace(/,/g,'٬'); };
    var dcalc = function(){
      var p = pIn ? digD(pIn.value) : 0, sp = sIn ? digD(sIn.value) : 0;
      var fin = (sp > 0 && sp < p) ? sp : p;
      var d = digD(dIn.value);
      if (!dSel || dSel.value !== '1' || d === 0) { dHint.textContent = ''; return; }
      if (fin > 0 && d < fin) {
        dHint.style.color = '#0c7a4d';
        dHint.textContent = '✓ معتبر است — کاربر با پرداخت بیعانهٔ ' + fmt(d) + ' تومان جایش قطعی می‌شود و ماندهٔ ' + fmt(fin - d) + ' تومان را بعداً تسویه می‌کند.';
      } else {
        dHint.style.color = '#b91c1c';
        dHint.textContent = '⚠️ بیعانه باید کمتر از قیمت نهایی (' + fmt(fin) + ' تومان) باشد؛ در غیر این صورت هنگام ذخیره نادیده گرفته می‌شود.';
      }
    };
    [pIn, sIn, dIn].forEach(function(x){ if (x) x.addEventListener('input', dcalc); });
    if (dSel) dSel.addEventListener('change', dcalc);
    dcalc();
  }

  /* ── پیش‌نمایش زندهٔ سئو (شبیه‌سازی ریچ‌اسنیپت) + شمارشگر کاراکتر ── */
  var pvT = document.getElementById('seoPvTitle'), pvU = document.getElementById('seoPvUrl'),
      pvD = document.getElementById('seoPvDesc'),
      inTitle = document.querySelector('input[name=title]'), inSlug = document.querySelector('input[name=slug]'),
      inSeoT = document.getElementById('seoTitle'), inSeoD = document.getElementById('seoDesc'),
      inShort = document.querySelector('input[name=short_desc]');
  function pv(){
    if (!pvT) return;
    pvT.textContent = (inSeoT && inSeoT.value.trim()) || (inTitle && inTitle.value.trim()) || 'عنوان صفحه';
    if (pvU && inSlug) pvU.textContent = pvU.textContent.replace(/\/[^\/]*$/, '/' + (inSlug.value.trim() || 'slug'));
    pvD.textContent = (inSeoD && inSeoD.value.trim()) || (inShort && inShort.value.trim()) || 'توضیح کوتاه این صفحه…';
    document.querySelectorAll('.seo-count').forEach(function(el){
      var inp = document.getElementById(el.getAttribute('data-countfor'));
      if (!inp) return;
      var n = inp.value.trim().length;
      el.textContent = n + ' کاراکتر' + (el.getAttribute('data-countfor') === 'seoTitle'
          ? (n > 60 ? ' ⚠️ بیش از حد پیشنهادی (۶۰)' : ' ✓') : (n > 160 ? ' ⚠️ بیش از حد پیشنهادی (۱۶۰)' : ' ✓'));
      el.style.color = (el.getAttribute('data-countfor') === 'seoTitle' ? n > 60 : n > 160) ? '#b3261e' : 'var(--muted)';
    });
  }
  [inTitle,inSlug,inSeoT,inSeoD,inShort].forEach(function(x){ if(x) x.addEventListener('input', pv); });
  pv();

  /* ── عملیات گروهی: انتخاب همه + شمارش + تأیید پیش از اجرا ── */
  var all = document.getElementById('bulkAll'), cbs = document.querySelectorAll('.bulk-cb'),
      cnt = document.getElementById('bulkCount'), form = document.getElementById('bulkForm'),
      FA = function(n){ return String(n).replace(/\d/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  function recount(){ var n = document.querySelectorAll('.bulk-cb:checked').length;
    if (cnt) cnt.textContent = FA(n) + ' مورد انتخاب شد'; }
  if (all) all.addEventListener('change', function(){
    cbs.forEach(function(cb){ cb.checked = all.checked; }); recount();
  });
  cbs.forEach(function(cb){ cb.addEventListener('change', recount); });
  if (form) form.addEventListener('submit', function(ev){
    var n = document.querySelectorAll('.bulk-cb:checked').length;
    if (n === 0) { ev.preventDefault(); alert('ابتدا دست‌کم یک مورد را علامت بزنید.'); return; }
    var op = form.querySelector('select[name=op]').value;
    var label = {deactivate:'غیرفعال', activate:'فعال‌سازی', delete:'حذف'}[op];
    if (!confirm(label + ' ' + n + ' مورد انتخاب‌شده؟' + (op === 'delete' ? '\n(موارد دارای ثبت‌نام پرداخت‌شده حذف نمی‌شوند.)' : ''))) {
      ev.preventDefault();
    }
  });
})();

function frCopyEvent(id) {
  document.getElementById('evRowAct').value = 'copy';
  document.getElementById('evRowId').value = id;
  document.getElementById('evRowActionForm').submit();
}

function frDeleteEvent(id, title) {
  if (confirm('حذف «' + title + '»؟\n\n(اگر ثبت‌نام پرداخت‌شده داشته باشد، حذف انجام نمی‌شود و باید آن را غیرفعال کنید.)')) {
    document.getElementById('evRowAct').value = 'delete';
    document.getElementById('evRowId').value = id;
    document.getElementById('evRowActionForm').submit();
  }
}
</script>
    <?php
    admin_layout_end();
}
