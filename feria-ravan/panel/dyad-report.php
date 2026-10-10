<?php
/**
 * ============================================================================
 *  کارنامهٔ جامع تطابق دونفره و زوجین فریا روان (Couples & Dyadic Synergy Report)
 *  ─ مقایسهٔ رو در رو، ماتریس تفاضلی، کانون‌های هم‌افزایی و پروتکل گفت‌وگوی امن
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../core/DyadAnalyzer.php';
fr_bootstrap();

$u = require_user();
$dyadId = (int)($_GET['id'] ?? 0);

$dyad = DB::one('SELECT d.*, t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine
                 FROM ' . DB::t('dyads') . ' d
                 JOIN ' . DB::t('tests') . ' t ON t.id=d.test_id
                 WHERE d.id=?', [$dyadId]);

if (!$dyad) redirect(SITE_URL . '/panel/dyad.php');

// دسترسی فقط برای دو طرف رابطه یا مدیر
if ((int)$dyad['user1_id'] !== (int)$u['id'] && (int)$dyad['user2_id'] !== (int)$u['id']) {
    redirect(SITE_URL . '/panel/dyad.php');
}

if ($dyad['status'] !== 'ready' || empty($dyad['attempt2_id'])) {
    flash('طرف دوم هنوز آزمون خود را تکمیل نکرده است.', 'warn');
    redirect(SITE_URL . '/panel/dyad.php');
}

// اگر داده‌های تحلیل خالیست یا نیاز به بازسازی دارد
$data = json_decode((string)($dyad['summary_json'] ?? ''), true);
if (!$data || empty($data['delta_matrix'])) {
    $data = DyadAnalyzer::analyze($dyadId);
}

if (!$data) {
    flash('گزارش تطابق هنوز تکمیل نشده یا اطلاعات آزمون دوم پردازش نشده است.', 'warn');
    redirect(SITE_URL . '/panel/dyad.php');
}

$u1 = $data['user1'] ?? [];
$u2 = $data['user2'] ?? [];
$synergy = (int)($data['synergy_score'] ?? 75);
$ratingFa = (string)($data['rating_fa'] ?? 'سازگاری مطلوب');
$deltas = (array)($data['delta_matrix'] ?? []);
$strengths = (array)($data['synergy_strengths'] ?? []);
$frictions = (array)($data['friction_points'] ?? []);
$protocols = (array)($data['safe_protocols'] ?? []);
$growth = (array)($data['growth_contract'] ?? []);

render_header('گزارش تطابق دونفره: ' . $u1['name'] . ' و ' . $u2['name']);
panel_layout($u, 'history');
?>
<div style="max-width:920px;margin:0 auto">

  <!-- نوار ابزار بالا -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <a class="fr-btn ghost sm" href="dyad.php">→ بازگشت به پیوندها</a>
    <div style="display:flex;gap:8px">
      <button class="fr-btn sm" onclick="window.print()">📄 چاپ / ذخیره PDF</button>
      <a class="fr-btn ghost sm" href="history.php">تاریخچه آزمون‌ها</a>
    </div>
  </div>

  <!-- کارت اصلی سربرگ و شاخص هم‌افزایی -->
  <div class="fr-card" style="background:linear-gradient(135deg,#2e1065,#581c87,#6b21a8);color:#fff;border-radius:18px;padding:32px 24px;margin-bottom:28px;box-shadow:0 12px 30px rgba(88,28,135,.25)">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:24px">
      <div style="flex:1;min-width:280px">
        <div style="display:inline-block;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);padding:4px 12px;border-radius:20px;font-size:12px;margin-bottom:12px">
          گزارش تحلیلی تطابق دونفره • <?= e($data['rel_type_fa'] ?? 'زوجین') ?>
        </div>
        <h2 style="font-size:24px;margin:0 0 10px;color:#fff;font-weight:800">
          <?= e($u1['name']) ?> &nbsp; ↔ &nbsp; <?= e($u2['name']) ?>
        </h2>
        <div style="font-size:13.5px;opacity:.9;line-height:2">
          مبنای سنجش: <b><?= e($dyad['test_title']) ?></b>
          <?php if (!empty($u1['pattern']) && !empty($u2['pattern'])): ?>
            <br>الگوی رفتاری: <b><?= e($u1['name']) ?> (<?= e($u1['pattern']) ?>)</b> در برابر <b><?= e($u2['name']) ?> (<?= e($u2['pattern']) ?>)</b>
          <?php endif; ?>
        </div>
      </div>

      <!-- دایره شاخص هم‌افزایی -->
      <div style="text-align:center;background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.25);padding:18px 24px;border-radius:20px;min-width:180px">
        <div style="font-size:12.5px;opacity:.85;margin-bottom:4px">شاخص هم‌افزایی رابطه</div>
        <div style="font-size:42px;font-weight:900;line-height:1;color:#fde047;direction:ltr">
          <?= fa_num($synergy) ?>٪
        </div>
        <div style="font-size:12px;margin-top:8px;font-weight:600;color:#f5f3ff">
          <?= e($ratingFa) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- بخش ۱: نمودار مقایسه‌ای دونفره و ماتریس تفاوت ابعاد -->
  <div class="fr-card" style="margin-bottom:28px">
    <h3 style="margin:0 0 8px;font-size:17px;color:var(--ink)">📊 مقایسه رو در روی ابعاد رفتاری</h3>
    <p style="font-size:13px;color:var(--muted);margin:0 0 20px">
      نمودار زیر میزان شدت هر بُعد را در مقایسه با یکدیگر نشان می‌دهد. تفاوت‌های متوسط (مکمل) نشانهٔ تعادل‌بخشی به زندگی مشترک است.
    </p>

    <div style="display:flex;justify-content:center;gap:24px;margin-bottom:20px;font-size:13px;font-weight:600">
      <span style="display:flex;align-items:center;gap:8px">
        <i style="width:14px;height:14px;background:#7c3aed;border-radius:4px;display:inline-block"></i> <?= e($u1['name']) ?>
      </span>
      <span style="display:flex;align-items:center;gap:8px">
        <i style="width:14px;height:14px;background:#0d9488;border-radius:4px;display:inline-block"></i> <?= e($u2['name']) ?>
      </span>
    </div>

    <div style="display:grid;gap:18px">
      <?php foreach ($deltas as $dRow): ?>
      <div style="background:#fafafa;border:1px solid var(--border);border-radius:12px;padding:14px 18px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
          <span style="font-weight:700;font-size:14px;color:var(--ink)">
            <?= e($dRow['title'] ?? $dRow['code']) ?>
          </span>
          <span style="font-size:11.5px;padding:3px 10px;border-radius:12px;background:#eef2ff;color:#4338ca;font-weight:600">
            <?= e($dRow['nature'] ?? '') ?> (اختلاف: <?= fa_num($dRow['diff']) ?>٪)
          </span>
        </div>

        <!-- میله طرف ۱ -->
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">
          <span style="width:90px;font-size:12px;color:#6b21a8;font-weight:600;text-overflow:ellipsis;overflow:hidden;white-space:nowrap">
            <?= e($u1['name']) ?>:
          </span>
          <div style="flex:1;background:#e9d5ff;height:12px;border-radius:6px;overflow:hidden;position:relative">
            <div style="background:#7c3aed;width:<?= min(100, max(0, (int)$dRow['p1'])) ?>%;height:100%;border-radius:6px"></div>
          </div>
          <span style="width:40px;font-size:12px;font-weight:700;color:#6b21a8;text-align:left;direction:ltr">
            <?= fa_num($dRow['p1']) ?>٪
          </span>
        </div>

        <!-- میله طرف ۲ -->
        <div style="display:flex;align-items:center;gap:12px">
          <span style="width:90px;font-size:12px;color:#0f766e;font-weight:600;text-overflow:ellipsis;overflow:hidden;white-space:nowrap">
            <?= e($u2['name']) ?>:
          </span>
          <div style="flex:1;background:#ccfbf1;height:12px;border-radius:6px;overflow:hidden;position:relative">
            <div style="background:#0d9488;width:<?= min(100, max(0, (int)$dRow['p2'])) ?>%;height:100%;border-radius:6px"></div>
          </div>
          <span style="width:40px;font-size:12px;font-weight:700;color:#0f766e;text-align:left;direction:ltr">
            <?= fa_num($dRow['p2']) ?>٪
          </span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- بخش ۲: نقاط قوت هم‌افزا (Synergy Strengths) -->
  <div class="fr-card" style="margin-bottom:28px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
      <span style="font-size:24px">🌱</span>
      <h3 style="margin:0;font-size:17px;color:#166534">کانون‌های هم‌افزایی و تکیه‌گاه‌های رابطه</h3>
    </div>
    <div style="display:grid;gap:14px">
      <?php foreach ($strengths as $s): ?>
      <div style="background:#f0fdf4;border-right:4px solid #22c55e;padding:14px 18px;border-radius:10px">
        <div style="font-weight:700;color:#15803d;font-size:14.5px;margin-bottom:6px">
          ✨ <?= e($s['title'] ?? '') ?>
        </div>
        <div style="font-size:13.5px;color:#166534;line-height:2">
          <?= e($s['desc'] ?? '') ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- بخش ۳: کانون‌های اصطکاک و سوءتفاهم‌های محتمل (Friction Triggers) -->
  <div class="fr-card" style="margin-bottom:28px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
      <span style="font-size:24px">⚠️</span>
      <h3 style="margin:0;font-size:17px;color:#991b1b">نقاط اصطکاک و هشدارهای تعارض احتمالی</h3>
    </div>
    <div style="display:grid;gap:14px">
      <?php foreach ($frictions as $f): ?>
      <div style="background:#fef2f2;border-right:4px solid #ef4444;padding:14px 18px;border-radius:10px">
        <div style="font-weight:700;color:#b91c1c;font-size:14.5px;margin-bottom:6px">
          ⚡ <?= e($f['title'] ?? '') ?>
        </div>
        <div style="font-size:13.5px;color:#7f1d1d;line-height:2">
          <?= e($f['desc'] ?? '') ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- بخش ۴: پروتکل گفت‌وگوی امن دونفره (Safe Communication Protocol) -->
  <div class="fr-card" style="margin-bottom:28px;background:#faf5ff;border:1px solid #e9d5ff">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
      <span style="font-size:24px">🛡️</span>
      <h3 style="margin:0;font-size:17px;color:#581c87">پروتکل اختصاصی گفت‌وگوی امن دونفره</h3>
    </div>

    <!-- دو باکس راهنمای خطاب به هریک از طرفین -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:20px">
      <div style="background:#fff;border-radius:12px;padding:16px;border:1px solid #ddd6fe">
        <div style="font-weight:700;color:#6b21a8;font-size:13.5px;margin-bottom:8px">
          🗣️ راهنمای <?= e($u1['name']) ?>:
        </div>
        <div style="font-size:13px;color:#4c1d95;line-height:2">
          <?= e($protocols['to_p1'] ?? '') ?>
        </div>
      </div>
      <div style="background:#fff;border-radius:12px;padding:16px;border:1px solid #ccfbf1">
        <div style="font-weight:700;color:#0f766e;font-size:13.5px;margin-bottom:8px">
          🗣️ راهنمای <?= e($u2['name']) ?>:
        </div>
        <div style="font-size:13px;color:#134e4a;line-height:2">
          <?= e($protocols['to_p2'] ?? '') ?>
        </div>
      </div>
    </div>

    <!-- قوانین طلایی گفت‌وگو -->
    <?php if (!empty($protocols['rules'])): ?>
    <div style="background:#fff;border-radius:12px;padding:16px;border:1px dashed #c084fc">
      <div style="font-weight:700;color:#581c87;font-size:13.5px;margin-bottom:10px">
        📌 ۴ قانون طلایی گفت‌وگو برای این دو تیپ شخصیتی:
      </div>
      <ul style="margin:0;padding-right:20px;font-size:13px;color:#4a044e;line-height:2.2">
        <?php foreach ($protocols['rules'] as $rule): ?>
          <li><?= e($rule) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <!-- بخش ۵: قرارداد رفتاری و تمرین هفتگی رشد رابطه -->
  <?php if (!empty($growth)): ?>
  <div class="fr-card" style="margin-bottom:28px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
      <span style="font-size:24px">🎯</span>
      <h3 style="margin:0;font-size:17px;color:var(--ink)">قرارداد رفتاری و برنامهٔ سه‌هفته‌ای رشد مشترک</h3>
    </div>
    <div style="display:grid;gap:12px">
      <div style="display:flex;gap:14px;align-items:flex-start;background:#f8fafc;padding:14px;border-radius:10px">
        <span style="background:#e0e7ff;color:#3730a3;font-weight:800;padding:4px 10px;border-radius:8px;font-size:12px">هفته ۱</span>
        <div style="font-size:13.5px;color:var(--ink);line-height:1.9"><?= e($growth['week1'] ?? '') ?></div>
      </div>
      <div style="display:flex;gap:14px;align-items:flex-start;background:#f8fafc;padding:14px;border-radius:10px">
        <span style="background:#e0e7ff;color:#3730a3;font-weight:800;padding:4px 10px;border-radius:8px;font-size:12px">هفته ۲</span>
        <div style="font-size:13.5px;color:var(--ink);line-height:1.9"><?= e($growth['week2'] ?? '') ?></div>
      </div>
      <div style="display:flex;gap:14px;align-items:flex-start;background:#f8fafc;padding:14px;border-radius:10px">
        <span style="background:#e0e7ff;color:#3730a3;font-weight:800;padding:4px 10px;border-radius:8px;font-size:12px">هفته ۳</span>
        <div style="font-size:13.5px;color:var(--ink);line-height:1.9"><?= e($growth['week3'] ?? '') ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<div style="text-align:center;margin:32px 0 16px">
  <button type="button" class="fr-btn ghost sm" onclick="window.scrollTo({top:0,behavior:'smooth'})" style="border-radius:99px;padding:8px 20px">
    ↑ بازگشت به بالای کارنامه تطابق
  </button>
</div>
<?php
panel_layout_end();
render_footer();
