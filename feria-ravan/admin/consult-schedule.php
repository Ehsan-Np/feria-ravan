<?php
/**
 * =============================================================================
 *  کلینیک مشاوره — زمان‌بندی هفتگی، تولید اسلات، تعطیلات و مدیریت اسلات‌ها
 *  (نسخهٔ ۱٫۶)
 *  ─ قانون هفتگی: چه روزهایی، از چه ساعتی تا چه ساعتی (گام = مدت جلسه + استراحت)
 *  ─ تولید خودکار اسلات برای N هفتهٔ آینده (رد تعطیلات و گذشته‌ها، ضدتکرار)
 *  ─ تعطیلات: افزودن دستی + تعطیلات پیش‌فرض سالیانه + به‌روزرسانی از time.ir
 *              + بارگذاری فایل اکسل/CSV
 *  ─ اسلات‌ها: عملیات تک‌تک + انتخاب گروهی (مسدود/آزاد/حذف دسته‌جمعی)
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/includes/consult.php';
require_once FR_ROOT . '/includes/xlsx.php';
$adm = require_admin(['superadmin','manager']);

$services = DB::all('SELECT s.id, s.title, s.duration_min, c.name AS cname
                     FROM ' . DB::t('consult_services') . ' s
                     JOIN ' . DB::t('consultants') . ' c ON c.id=s.consultant_id
                     WHERE s.active=1 ORDER BY s.sort, s.id');
$serviceId = (int)($_GET['service'] ?? ($services[0]['id'] ?? 0));

/* ============================================================================
 | اقدامات POST
 * ==========================================================================*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');

    /* ── افزودن قانون هفتگی ── */
    if ($act === 'add_rule') {
        $sid = (int)post('service_id'); $w = (int)post('weekday');
        $st  = en_num((string)post('start_time')); $en = en_num((string)post('end_time'));
        if (!DB::val('SELECT id FROM ' . DB::t('consult_services') . ' WHERE id=?', [$sid])) { flash('خدمت نامعتبر است.', 'err'); redirect(SITE_URL . '/admin/consult-schedule.php'); }
        if ($w < 0 || $w > 6 || !preg_match('/^\d{1,2}:\d{2}$/', $st) || !preg_match('/^\d{1,2}:\d{2}$/', $en) || $st >= $en) {
            flash('ساعت شروع/پایان معتبر نیست (نمونه: 16:00 تا 20:00).', 'err');
        } else {
            DB::insert('consult_schedules', ['service_id'=>$sid,'weekday'=>$w,'start_time'=>$st . ':00','end_time'=>$en . ':00','active'=>1]);
            log_activity('admin_schedule_rule', 'consult_service', $sid, ['wd'=>$w,'st'=>$st,'en'=>$en]);
            flash('قانون هفتگی اضافه شد؛ برای ساخت نوبت‌ها «تولید اسلات» را بزنید ✅');
        }
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $sid);
    }
    if ($act === 'del_rule') {
        DB::run('DELETE FROM ' . DB::t('consult_schedules') . ' WHERE id=?', [(int)post('id')]);
        flash('قانون حذف شد. (اسلات‌های قبلاً ساخته‌شده حذف نمی‌شوند)');
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }
    if ($act === 'toggle_rule') {
        DB::run('UPDATE ' . DB::t('consult_schedules') . ' SET active=1-active WHERE id=?', [(int)post('id')]);
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }

    /* ── تولید اسلات ── */
    if ($act === 'generate') {
        $sid   = (int)post('service_id');
        $weeks = max(1, min(12, (int)en_num((string)post('weeks','4'))));
        if ((int)post('rebuild') === 1) {
            // حذف اسلات‌های بازِ آینده (رزروشده/هولد هرگز حذف نمی‌شود)
            DB::run('DELETE FROM ' . DB::t('consult_slots') .
                    " WHERE service_id=? AND status='open' AND starts_at>NOW()", [$sid]);
        }
        $added = consult_generate_slots($sid, $weeks);
        log_activity('admin_slots_generate', 'consult_service', $sid, ['weeks'=>$weeks,'added'=>$added]);
        flash(fa_num($added) . ' اسلات جدید برای ' . fa_num($weeks) . ' هفتهٔ آینده ساخته شد ✅');
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $sid);
    }

    /* ── تعطیلات: افزودن دستی ── */
    if ($act === 'add_holiday') {
        $j = consult_normalize_jdate((string)post('jdate'));
        if (!$j) { flash('تاریخ شمسی معتبر نیست (الگو: ۱۴۰۵/۰۶/۱۵).', 'err'); }
        else {
            DB::run('INSERT INTO ' . DB::t('consult_holidays') . ' (jdate,title,active) VALUES (?,?,1)
                     ON DUPLICATE KEY UPDATE title=VALUES(title), active=1',
                    [$j, mb_substr(trim((string)post('title')), 0, 180) ?: null]);
            flash('روز «' . fa_num($j) . '» به تعطیلات اضافه شد ✅');
        }
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }
    if ($act === 'del_holiday') {
        DB::run('DELETE FROM ' . DB::t('consult_holidays') . ' WHERE id=?', [(int)post('id')]);
        flash('تعطیلی حذف شد.');
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }

    /* ── تعطیلات پیش‌فرض سالیانه ── */
    if ($act === 'holidays_seed_default') {
        $added = fr_seed_default_holidays();
        log_activity('admin_holidays_seed', 'consult_holiday', null, ['added'=>$added]);
        flash($added
            ? fa_num($added) . ' تعطیلی رسمی (۱۴۰۵–۱۴۰۶) به فهرست اضافه شد ✅'
            : 'همهٔ تعطیلات پیش‌فرض قبلاً ثبت شده‌اند؛ چیزی برای افزودن نبود.', $added ? 'ok' : 'warn');
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }

    /* ── به‌روزرسانی تعطیلات از time.ir (بهترین تلاش) ── */
    if ($act === 'holidays_timeir') {
        $jy = (int)en_num((string)post('jyear', jdate('Y') ?: '1405'));
        if ($jy < 1400 || $jy > 1410) { flash('سال نامعتبر است.', 'err'); redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId); }
        $r = fr_timeir_sync($jy);
        log_activity('admin_holidays_timeir', 'consult_holiday', null, ['year'=>$jy,'ok'=>$r['ok']]);
        if ($r['ok']) flash(fa_num($r['added']) . ' تعطیلی سال ' . fa_num($jy) . ' از time.ir وارد شد ✅ (موارد تکراری رد شدند)');
        else          flash($r['error'] ?? 'به‌روزرسانی از time.ir ممکن نشد؛ از فایل اکسل استفاده کنید.', 'err');
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId . '&yearupd=1');
    }

    /* ── بارگذاری تعطیلات از فایل اکسل/CSV ── */
    if ($act === 'holidays_import') {
        $p = fr_table_upload_rows((array)($_FILES['hfile'] ?? []));
        if (!$p['ok']) {
            flash('فایل خوانده نشد: ' . $p['error'], 'err');
        } else {
            $st = fr_import_holiday_rows($p['rows']);
            log_activity('admin_holidays_import', 'consult_holiday', null, $st);
            $msg = fa_num($st['added']) . ' تعطیلی جدید ثبت شد ✅';
            if ($st['skipped']) $msg .= ' — ' . fa_num($st['skipped']) . ' مورد تکراری رد شد';
            if ($st['bad'])     $msg .= ' — ' . fa_num($st['bad']) . ' ردیف تاریخ معتبر نداشت';
            flash($msg, $st['added'] ? 'ok' : 'warn');
        }
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }

    /* ── تک‌اسلات: مسدود/آزاد/حذف ── */
    if ($act === 'slot_block') {
        DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='blocked' WHERE id=? AND status='open'", [(int)post('id')]);
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }
    if ($act === 'slot_open') {
        DB::run('UPDATE ' . DB::t('consult_slots') . " SET status='open' WHERE id=? AND status='blocked'", [(int)post('id')]);
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }
    if ($act === 'slot_del') {
        DB::run('DELETE sl FROM ' . DB::t('consult_slots') . ' sl
                 LEFT JOIN ' . DB::t('consult_appointments') . " a ON a.slot_id=sl.id AND a.status IN('pending_payment','booked')
                 WHERE sl.id=? AND sl.status<>'booked' AND a.id IS NULL", [(int)post('id')]);
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }

    /* ── عملیات گروهی روی اسلات‌های انتخاب‌شده (نسخهٔ ۱٫۶) ── */
    if ($act === 'slot_bulk') {
        $op  = (string)post('op');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['sel'] ?? [])))));
        if (!$ids || !in_array($op, ['block','open','delete'], true)) {
            flash('هیچ اسلاتی انتخاب نشده یا عملیات نامعتبر است.', 'warn');
            redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $n  = 0;
        if ($op === 'block') {
            // فقط اسلات‌های باز و بدون رزرو فعال
            $st = DB::run("UPDATE " . DB::t('consult_slots') . " sl
                           LEFT JOIN " . DB::t('consult_appointments') . " a ON a.slot_id=sl.id AND a.status IN('pending_payment','booked')
                           SET sl.status='blocked'
                           WHERE sl.id IN($ph) AND sl.status='open' AND a.id IS NULL", $ids);
        } elseif ($op === 'open') {
            $st = DB::run("UPDATE " . DB::t('consult_slots') . " SET status='open' WHERE id IN($ph) AND status='blocked'", $ids);
        } else { // delete — فقط آزاد/مسدود و بدون رزرو فعال
            $st = DB::run("DELETE sl FROM " . DB::t('consult_slots') . " sl
                           LEFT JOIN " . DB::t('consult_appointments') . " a ON a.slot_id=sl.id AND a.status IN('pending_payment','booked')
                           WHERE sl.id IN($ph) AND sl.status IN('open','blocked') AND a.id IS NULL", $ids);
        }
        log_activity('admin_slots_bulk', 'consult_service', $serviceId, ['op'=>$op,'count'=>count($ids)]);
        $opFa = ['block'=>'مسدود شدند ⛔','open'=>'آزاد شدند 🟢','delete'=>'حذف شدند 🗑'][$op];
        flash('اسلات‌های انتخاب‌شده ' . $opFa . ' (موارد رزروشده دست‌نخورده باقی ماندند)');
        redirect(SITE_URL . '/admin/consult-schedule.php?service=' . $serviceId);
    }
}

/* ============================================================================
 | داده‌ها
 * ==========================================================================*/
consult_release_expired_holds();
$rules = $serviceId ? DB::all('SELECT * FROM ' . DB::t('consult_schedules') . ' WHERE service_id=? ORDER BY weekday, start_time', [$serviceId]) : [];
$holidays = DB::all('SELECT * FROM ' . DB::t('consult_holidays') . ' ORDER BY jdate');
$slots = $serviceId
    ? DB::all('SELECT sl.*, a.id AS appt_id, a.status AS appt_status
               FROM ' . DB::t('consult_slots') . ' sl
               LEFT JOIN ' . DB::t('consult_appointments') . " a ON a.slot_id=sl.id AND a.status IN('pending_payment','booked')
               WHERE sl.service_id=? AND sl.starts_at>DATE_SUB(NOW(), INTERVAL 1 DAY)
               ORDER BY sl.starts_at LIMIT 120", [$serviceId])
    : [];
$wdFa = [6=>'شنبه',0=>'یکشنبه',1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه'];
$slFa = ['open'=>['🟢 باز','info'],'held'=>['🟡 رزرو موقت','warn'],'booked'=>['🔵 قطعی','purple'],'blocked'=>['⛔ مسدود','err']];

/* گپ فعلی خدمت (نمایش راهنما) */
$gapNow = 0;
try { $gapNow = (int)(DB::one('SELECT gap_minutes FROM ' . DB::t('consult_services') . ' WHERE id=?', [$serviceId])['gap_minutes'] ?? 0); } catch (Throwable $e) {}

admin_layout($adm, 'consult-schedule', 'زمان‌بندی مشاوره — اسلات‌ها و تعطیلات');
echo flash();
?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
  <a class="fr-btn sm ghost" href="consult.php">🛋 خدمات و مشاوران</a>
  <a class="fr-btn sm" href="consult-schedule.php">🗓 زمان‌بندی</a>
  <a class="fr-btn sm ghost" href="consult-bookings.php">📋 نوبت‌ها</a>
</div>

<?php if (!$services): ?>
<div class="fr-alert warn">ابتدا از بخش «خدمات و مشاوران» یک خدمت فعال بسازید.</div>
<?php else: ?>

<!-- انتخاب خدمت -->
<div class="fr-card" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
  <b style="font-size:14px">خدمت:</b>
  <?php foreach ($services as $s): ?>
  <a class="fr-btn sm <?= $serviceId===(int)$s['id'] ? '' : 'ghost' ?>" href="?service=<?= $s['id'] ?>">
    <?= e($s['title']) ?> <small style="opacity:.7">(<?= e($s['cname']) ?> · <?= fa_num($s['duration_min']) ?> دقیقه)</small>
  </a>
  <?php endforeach; ?>
</div>

<div class="fr-grid cols-2">
  <!-- ═══════════ قوانین هفتگی + تولید اسلات ═══════════ -->
  <div>
    <div class="fr-card" style="margin:0 0 18px">
      <h3>📆 قوانین هفتگی «<?= e(DB::val('SELECT title FROM ' . DB::t('consult_services') . ' WHERE id=?', [$serviceId]) ?: '—') ?>»</h3>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="add_rule">
        <input type="hidden" name="service_id" value="<?= $serviceId ?>">
        <div class="fr-field" style="margin:0;flex:1;min-width:110px"><label>روز هفته</label>
          <select class="fr-select" name="weekday">
            <?php foreach ($wdFa as $w=>$fa): ?><option value="<?= $w ?>"><?= $fa ?></option><?php endforeach; ?>
          </select></div>
        <div class="fr-field" style="margin:0;width:110px"><label>از ساعت</label>
          <input class="fr-input" name="start_time" placeholder="۱۶:۰۰" style="direction:ltr;text-align:center"></div>
        <div class="fr-field" style="margin:0;width:110px"><label>تا ساعت</label>
          <input class="fr-input" name="end_time" placeholder="۲۰:۰۰" style="direction:ltr;text-align:center"></div>
        <button class="fr-btn sm">➕ افزودن</button>
      </form>
      <?php if (!$rules): ?>
      <p class="fr-alert info" style="margin:0">هنوز قانونی ثبت نشده؛ مثلاً «شنبه ۱۶:۰۰ تا ۲۰:۰۰» اضافه کنید.</p>
      <?php else: ?>
      <table class="fr-table">
        <thead><tr><th>روز</th><th>بازه</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
        <tr style="<?= (int)$r['active'] ? '' : 'opacity:.5' ?>">
          <td><b><?= fr_weekday_fa((int)$r['weekday']) ?></b></td>
          <td style="direction:ltr;text-align:right"><?= fa_num(substr($r['start_time'],0,5)) ?> — <?= fa_num(substr($r['end_time'],0,5)) ?></td>
          <td><span class="fr-chip <?= (int)$r['active'] ? 'ok' : 'err' ?>"><?= (int)$r['active'] ? 'فعال' : 'غیرفعال' ?></span></td>
          <td class="actions">
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="act" value="toggle_rule"><input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="fr-btn sm ghost"><?= (int)$r['active'] ? '⏸' : '▶️' ?></button></form>
            <form method="post" style="display:inline" onsubmit="return confirm('حذف قانون؟')"><?= csrf_field() ?>
              <input type="hidden" name="act" value="del_rule"><input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="fr-btn sm danger">حذف</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- تولید اسلات -->
    <div class="fr-card" style="margin:0">
      <h3>⚙️ تولید خودکار نوبت‌ها</h3>
      <?php if ($gapNow > 0): ?>
      <p class="fr-alert info" style="margin:0 0 12px">
        ⏸ استراحت بین جلسات این خدمت <b><?= fa_num($gapNow) ?> دقیقه</b> است؛ گام تولید =
        <?= fa_num((int)($services[array_search($serviceId, array_column($services,'id'))]['duration_min'] ?? 45) + $gapNow) ?> دقیقه.
        برای تغییرش از بخش «خدمات و مشاوران → ویرایش خدمت» استفاده کنید.
      </p>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="generate">
        <input type="hidden" name="service_id" value="<?= $serviceId ?>">
        <div class="fr-field"><label>افق تولید (هفتهٔ آینده تا …)</label>
          <select class="fr-select" name="weeks">
            <?php foreach ([2,4,6,8,12] as $w): ?><option value="<?= $w ?>" <?= $w===4?'selected':'' ?>><?= fa_num($w) ?> هفته</option><?php endforeach; ?>
          </select></div>
        <label class="tog" style="margin-bottom:12px"><input type="checkbox" name="rebuild" value="1">
          بازتولید: اسلات‌های بازِ آینده حذف و دوباره ساخته شوند (رزروشده‌ها دست نمی‌خورند)</label>
        <button class="fr-btn sm" style="display:block">تولید اسلات‌ها 🚀</button>
        <small class="hint">تعطیلات ثبت‌شده و ساعت‌های گذشته به‌صورت خودکار رد می‌شوند.</small>
      </form>
    </div>
  </div>

  <!-- ═══════════ تعطیلات ═══════════ -->
  <div>
    <div class="fr-card" style="margin:0 0 18px">
      <h3>🚫 تعطیلات و روزهای غیرفعال (شمسی)</h3>

      <!-- ابزارهای گروهی تعطیلات -->
      <div style="background:#faf8ff;border:1.5px dashed #d4dcec;border-radius:14px;padding:12px;margin-bottom:14px;display:flex;flex-direction:column;gap:10px">
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="holidays_seed_default">
          <button class="fr-btn sm ghost">📅 افزودن تعطیلات پیش‌فرض ۱۴۰۵–۱۴۰۶</button>
          <small style="color:var(--muted);font-size:11.5px">فهرست آمادهٔ تعطیلات رسمی (موارد تکراری رد می‌شوند)</small>
        </form>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="holidays_import">
          <input class="fr-input" type="file" name="hfile" accept=".csv,.xlsx,.txt" required style="max-width:190px;font-size:12px">
          <button class="fr-btn sm accent">📥 بارگذاری از فایل اکسل/CSV</button>
          <small style="color:var(--muted);font-size:11.5px">ستون اول: تاریخ شمسی (۱۴۰۵/۰۷/۰۱) — ستون دوم: عنوان</small>
        </form>
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="holidays_timeir">
          <input class="fr-input" name="jyear" inputmode="numeric" value="<?= fa_num(jdate('Y') ?: 1405) ?>" style="max-width:80px;text-align:center">
          <button class="fr-btn sm ghost">🌐 به‌روزرسانی از time.ir</button>
          <small style="color:var(--muted);font-size:11.5px">در صورت دسترسی سرور به اینترنت؛ چنانچه نشد، همان فایل اکسل راه اصلی است</small>
        </form>
      </div>

      <!-- افزودن دستی -->
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="add_holiday">
        <div class="fr-field" style="margin:0;width:140px"><label>تاریخ شمسی</label>
          <input class="fr-input" name="jdate" placeholder="۱۴۰۵/۰۶/۱۵" style="direction:ltr;text-align:center"></div>
        <div class="fr-field" style="margin:0;flex:1;min-width:120px"><label>عنوان (اختیاری)</label>
          <input class="fr-input" name="title" placeholder="تعطیلی رسمی / جلسهٔ داخلی"></div>
        <button class="fr-btn sm">➕ افزودن</button>
      </form>
      <div style="max-height:250px;overflow-y:auto">
      <?php if (!$holidays): ?>
      <p class="fr-alert info" style="margin:0">تعطیلی ثبت نشده است؛ با دکمه‌های بالا فهرست پیش‌فرض را یک‌جا وارد کنید.</p>
      <?php else: ?>
      <table class="fr-table">
        <tbody>
        <?php foreach ($holidays as $h): ?>
        <tr>
          <td><b style="direction:ltr;display:inline-block"><?= fa_num($h['jdate']) ?></b>
            <?= fa_num(jdate('l', strtotime(jalali_to_mysql($h['jdate']) . ' 12:00:00') ?: time())) ?></td>
          <td style="font-size:12px;color:var(--muted)"><?= e($h['title'] ?? '—') ?></td>
          <td class="actions">
            <form method="post" style="display:inline" onsubmit="return confirm('حذف؟')"><?= csrf_field() ?>
              <input type="hidden" name="act" value="del_holiday"><input type="hidden" name="id" value="<?= $h['id'] ?>">
              <button class="fr-btn sm danger">حذف</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════ فهرست اسلات‌های پیش‌رو + عملیات گروهی ═══════════ -->
<div class="fr-card">
  <h3>🕐 اسلات‌های پیش‌رو (<?= fa_num(count($slots)) ?> مورد)</h3>
  <form method="post" id="bulkForm" onsubmit="return bulkConfirm()">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="slot_bulk">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;background:#faf8ff;border:1.5px dashed #d4dcec;border-radius:14px;padding:10px 12px">
      <label class="tog" style="margin:0"><input type="checkbox" id="selAll"> انتخاب همهٔ قابل‌اقدام</label>
      <span style="flex:1"></span>
      <button type="submit" name="op" value="block" class="fr-btn sm ghost">⛔ مسدود کردن گروهی</button>
      <button type="submit" name="op" value="open" class="fr-btn sm accent">🟢 آزادسازی گروهی</button>
      <button type="submit" name="op" value="delete" class="fr-btn sm danger">🗑 حذف گروهی</button>
      <small id="selCount" style="color:var(--muted);font-size:11.5px;width:100%">تیکِ اسلات‌ها را بزنید؛ اسلات‌های رزروشده/قطعی قابل انتخاب نیستند و همیشه سالم می‌مانند.</small>
    </div>
    <div style="overflow-x:auto">
    <table class="fr-table">
      <thead><tr><th style="width:34px"></th><th>تاریخ</th><th>روز</th><th>ساعت</th><th>وضعیت</th><th>رزرو</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($slots as $sl): $st = strtotime($sl['starts_at']); [$chip,$cls] = $slFa[$sl['status']];
        $canBulk = !$sl['appt_id'] && in_array($sl['status'], ['open','blocked'], true); ?>
      <tr>
        <td><?php if ($canBulk): ?><input type="checkbox" class="slotSel" name="sel[]" value="<?= (int)$sl['id'] ?>"><?php endif; ?></td>
        <td style="white-space:nowrap"><?= fa_num(jdate('Y/m/d', $st)) ?></td>
        <td><?= fa_num(jdate('l', $st)) ?></td>
        <td style="direction:ltr;text-align:right"><?= fa_num(date('H:i', $st)) ?> — <?= fa_num(date('H:i', strtotime($sl['ends_at']))) ?></td>
        <td><span class="fr-chip <?= $cls ?>"><?= $chip ?></span></td>
        <td><?= $sl['appt_id'] ? '<a href="consult-bookings.php?appt=' . (int)$sl['appt_id'] . '" class="fr-chip purple">مشاهدهٔ رزرو</a>' : '—' ?></td>
        <td class="actions">
          <?php if ($sl['status'] === 'open'): ?>
          <span data-one>
            <button class="fr-btn sm ghost" type="submit" form="one<?= (int)$sl['id'] ?>" title="مسدود کردن (در تقویم عمومی دیده نمی‌شود)">⛔</button>
          </span>
          <?php elseif ($sl['status'] === 'blocked'): ?>
          <span data-one>
            <button class="fr-btn sm accent" type="submit" form="one<?= (int)$sl['id'] ?>" title="بازکردن">🟢</button>
          </span>
          <?php endif; ?>
          <?php if (!$sl['appt_id'] && $sl['status'] !== 'booked'): ?>
          <span data-one>
            <button class="fr-btn sm danger" type="submit" form="del<?= (int)$sl['id'] ?>" onclick="return confirm('حذف اسلات؟')">حذف</button>
          </span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </form>
  <?php foreach ($slots as $sl): if ($sl['status'] === 'open' || $sl['status'] === 'blocked'): ?>
  <form method="post" id="one<?= (int)$sl['id'] ?>"><?= csrf_field() ?>
    <input type="hidden" name="act" value="<?= $sl['status'] === 'open' ? 'slot_block' : 'slot_open' ?>">
    <input type="hidden" name="id" value="<?= (int)$sl['id'] ?>"></form>
  <?php endif; if (!$sl['appt_id'] && $sl['status'] !== 'booked'): ?>
  <form method="post" id="del<?= (int)$sl['id'] ?>"><?= csrf_field() ?>
    <input type="hidden" name="act" value="slot_del"><input type="hidden" name="id" value="<?= (int)$sl['id'] ?>"></form>
  <?php endif; endforeach; ?>
</div>
<script>
(function(){
  var all=document.getElementById('selAll'), cnt=document.getElementById('selCount'), base=cnt.textContent;
  function boxes(){return Array.prototype.slice.call(document.querySelectorAll('.slotSel'));}
  function refresh(){var n=boxes().filter(function(b){return b.checked;}).length;
    cnt.textContent = n ? n + ' اسلات انتخاب شده است.' : base;}
  if(all){all.addEventListener('change',function(){boxes().forEach(function(b){b.checked=all.checked;});refresh();});}
  boxes().forEach(function(b){b.addEventListener('change',refresh);});
  window.bulkConfirm=function(){
    var n=boxes().filter(function(b){return b.checked;}).length;
    if(!n){alert('هیچ اسلاتی انتخاب نشده است؛ اول تیکِ اسلات‌ها را بزنید.');return false;}
    return true;
  };
})();
</script>
<?php endif; ?>
<?php admin_layout_end(); ?>
