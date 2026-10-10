<?php
/**
 * ============================================================================
 *  پروندهٔ جامع بالینی و تشخیصی مراجع — ویژه مشاوران و کادر درمان (نسخهٔ ۳٫۲)
 *  ─ محرمانه: فقط برای مدیر ارشد و مشاوران کلینیک فریا روان
 *  ─ هشدارهای بالینی (Red Flags)، فرضیه‌سازی تشخیصی، مقاومت‌ها و راهنمای اتاق درمان
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/core/ClinicalAnalyzer.php';
$adm = require_admin(['superadmin','manager']);

$attemptId = (int)($_GET['attempt'] ?? 0);
$summary = ClinicalAnalyzer::generateSummary($attemptId);
if (!$summary) {
    flash('جلسهٔ آزمون موردنظر یافت نشد.', 'err');
    redirect(SITE_URL . '/admin/users.php');
}

$u = $summary['user'] ?? [];

// ثبت یادداشت بالینی مشاور
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save_note') {
    check_csrf();
    $diag = mb_substr(trim((string)post('diagnosis')), 0, 250);
    $notes = trim((string)post('notes'));
    if ($notes !== '') {
        DB::insert('clinical_notes', [
            'attempt_id' => $attemptId,
            'user_id'    => $u['id'],
            'admin_id'   => $adm['id'],
            'diagnosis'  => $diag,
            'notes'      => $notes,
        ]);
        flash('یادداشت بالینی با موفقیت در پرونده ثبت شد ✅');
        redirect("clinical-case.php?attempt={$attemptId}");
    }
}

$clinicalNotes = DB::all('SELECT n.*, a.display_name AS admin_name FROM ' . DB::t('clinical_notes') . ' n
                          JOIN ' . DB::t('admins') . ' a ON a.id=n.admin_id
                          WHERE n.attempt_id=? ORDER BY n.id DESC', [$attemptId]);

admin_layout($adm, 'consult', 'خلاصه پرونده بالینی — ' . user_fullname($u));
?>
<div style="max-width:980px;margin:0 auto">

  <!-- نوار ابزار و محرمانگی -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div style="display:flex;align-items:center;gap:10px">
      <span class="fr-badge danger" style="font-size:12px;padding:6px 12px">🔒 محرمانه بالینی</span>
      <span style="font-size:13px;color:var(--muted)">مخصوص مشاور و روان‌درمانگر پرونده</span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="fr-btn ghost sm" href="attempt-answers.php?attempt=<?= $attemptId ?>" target="_blank" style="color:#7c3aed;border-color:#c4b5fd">📝 مشاهده و ویرایش پاسخ‌ها ↗</a>
      <button class="fr-btn sm" onclick="window.print()">🖨️ چاپ برگه پرونده فیزیکی</button>
      <a class="fr-btn ghost sm" href="user-view.php?id=<?= $u['id'] ?>">→ بازگشت به پرونده مراجع</a>
    </div>
  </div>

  <!-- کارت مشخصات هویتی و آزمون -->
  <div class="fr-card" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:14px;padding:20px;margin-bottom:24px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;font-size:13px">
      <div>👤 نام مراجع: <b><?= e(user_fullname($u)) ?></b></div>
      <div>📱 موبایل: <b><?= fa_num($u['mobile']) ?></b></div>
      <div>🧠 عنوان تست: <b><?= e($summary['test_title']) ?></b></div>
      <div>🗓 تاریخ ارزیابی: <b><?= fa_num(jdate('Y/m/d ساعت H:i', strtotime($summary['completed_at']))) ?></b></div>
      <div>⏱ مدت پاسخ‌دهی: <b><?= fa_num((int)(($summary['validity']['duration_sec'] ?? 0) / 60)) ?> دقیقه</b></div>
      <div>🛡️ شاخص اعتبار: <b style="color:#166534"><?= fa_num($summary['validity']['score'] ?? 100) ?>٪ (<?= e($summary['validity']['status_fa'] ?? 'معتبر') ?>)</b></div>
    </div>
  </div>

  <!-- کارت هشدارهای بالینی (Red Flags) -->
  <?php if (!empty($summary['red_flags'])): ?>
  <div class="fr-card" style="background:#fef2f2;border:1.5px solid #f87171;border-radius:14px;margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <span style="font-size:22px">⚠️</span>
      <h3 style="margin:0;font-size:16px;color:#991b1b">هشدارهای بالینی و شاخص‌های نیازمند احتیاط</h3>
    </div>
    <div style="display:grid;gap:10px">
      <?php foreach ($summary['red_flags'] as $rf): ?>
        <div style="background:#fff;border-radius:8px;padding:12px;border:1px solid #fca5a5;font-size:13px;line-height:1.9">
          <b style="color:#b91c1c"><?= e($rf['title']) ?>:</b> <?= e($rf['desc']) ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- جدول نمرات هنجارشده روان‌سنجی (T-Scores & Sten) -->
  <div class="fr-card" style="margin-bottom:24px">
    <h3 style="margin:0 0 16px;font-size:16px">📊 جدول مقادیر هنجارشده روان‌سنجی (جامعهٔ نرمال)</h3>
    <div style="overflow-x:auto">
      <table class="fr-table" style="width:100%;font-size:13px">
        <thead>
          <tr style="background:#f1f5f9;text-align:right">
            <th>کد بُعد</th>
            <th>نمره خام</th>
            <th>درصد</th>
            <th>نمره تراز T</th>
            <th>استن (۱-۱۰)</th>
            <th>رتبه درصدی</th>
            <th>باند بالینی</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary['results_table'] as $row): ?>
          <tr>
            <td><b><?= e($row['scale_code']) ?></b></td>
            <td><?= fa_num($row['raw_score']) ?> / <?= fa_num($row['max_score'] ?: 100) ?></td>
            <td><b><?= fa_num($row['percent']) ?>٪</b></td>
            <td><b style="color:#1e3a8a"><?= fa_num($row['t_score'] ?? '-') ?></b></td>
            <td><?= fa_num($row['sten_score'] ?? '-') ?>/۱۰</td>
            <td><?= fa_num($row['percentile'] ?? '-') ?>٪</td>
            <td><span class="fr-badge info"><?= e($row['band']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- فرضیه‌سازی تشخیصی و مکانیسم‌های دفاعی -->
  <div class="fr-card" style="margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <span style="font-size:22px">🔍</span>
      <h3 style="margin:0;font-size:16px;color:#1e293b">فرضیه‌سازی تشخیصی و دینامیک روانی مراجع</h3>
    </div>
    <div style="display:grid;gap:12px">
      <?php foreach ($summary['hypotheses'] as $hyp): ?>
      <div style="background:#f8fafc;border-right:4px solid #3b82f6;padding:14px;border-radius:8px">
        <b style="color:#1d4ed8;font-size:14px;display:block;margin-bottom:6px"><?= e($hyp['title']) ?></b>
        <div style="font-size:13px;line-height:2;color:#334155"><?= e($hyp['desc']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- مدیریت مقاومت‌های مراجع در اتاق درمان -->
  <?php if (!empty($summary['resistances'])): ?>
  <div class="fr-card" style="margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <span style="font-size:22px">🛡️</span>
      <h3 style="margin:0;font-size:16px;color:#854d0e">پیش‌بینی مقاومت‌های درمانی مراجع و راهنمای واکنش مشاور</h3>
    </div>
    <div style="display:grid;gap:12px">
      <?php foreach ($summary['resistances'] as $res): ?>
      <div style="background:#fefce8;border-right:4px solid #eab308;padding:14px;border-radius:8px">
        <b style="color:#a16207;font-size:13.5px;display:block;margin-bottom:4px">سبک مقاومت احتمالی: <?= e($res['style']) ?></b>
        <div style="font-size:13px;line-height:2;color:#713f12">💡 <b>راهنمای بالینی درمانگر:</b> <?= e($res['guide']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- نقاط ورود مداخله‌ای -->
  <?php if (!empty($summary['interventions'])): ?>
  <div class="fr-card" style="margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <span style="font-size:22px">🎯</span>
      <h3 style="margin:0;font-size:16px;color:#166534">نقاط ورود بالینی و ساختار جلسات پیشنهادی</h3>
    </div>
    <ul style="margin:0;padding-right:20px;font-size:13.5px;line-height:2.2;color:#14532d">
      <?php foreach ($summary['interventions'] as $inte): ?>
        <li><?= e($inte) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <!-- ثبت یادداشت‌های محرمانه جلسه مشاور -->
  <div class="fr-card">
    <h3 style="margin:0 0 16px;font-size:16px">📝 یادداشت‌های محرمانه مشاور برای این مراجع</h3>
    <form method="post" style="margin-bottom:20px">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save_note">
      <div style="margin-bottom:12px">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">تشخیص اولیه / محور ارزیابی:</label>
        <input type="text" name="diagnosis" class="fr-input" placeholder="مثال: تعارض زناشویی ناشی از تفاوت سرعت تصمیم‌گیری / کمال‌گرایی انطباقی">
      </div>
      <div style="margin-bottom:12px">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">یادداشت جلسه، توافق‌ها و تکالیف خانگی:</label>
        <textarea name="notes" class="fr-input" rows="4" required placeholder="یادداشت‌های فنی و مشاهدات رفتاری درمانگر..."></textarea>
      </div>
      <button class="fr-btn sm">ثبت در پرونده مراجع ←</button>
    </form>

    <?php if (!empty($clinicalNotes)): ?>
      <div style="border-top:1px solid var(--border);padding-top:16px">
        <h4 style="font-size:14px;margin:0 0 12px">تاریخچه یادداشت‌های ثبت‌شده:</h4>
        <?php foreach ($clinicalNotes as $cn): ?>
          <div style="background:#f8fafc;border-radius:10px;padding:14px;margin-bottom:10px;font-size:13px;line-height:2">
            <div style="display:flex;justify-content:space-between;color:var(--muted);font-size:11.5px;margin-bottom:6px">
              <span>👤 مشاور: <b><?= e($cn['admin_name']) ?></b></span>
              <span>🗓 ثبت: <?= fa_num(jdate('Y/m/d H:i', strtotime($cn['created_at']))) ?></span>
            </div>
            <?php if (!empty($cn['diagnosis'])): ?>
              <div style="color:#1d4ed8;font-weight:700;margin-bottom:4px">🏷 تشخیص: <?= e($cn['diagnosis']) ?></div>
            <?php endif; ?>
            <div style="color:var(--ink)"><?= nl2br(e($cn['notes'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>
<?php
admin_layout_end();
