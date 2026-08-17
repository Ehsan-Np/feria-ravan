<?php
/**
 * ============================================================================
 *  گزارش‌ساز عمیق DISC — نسخهٔ ۲٫۶ (گزارش شش‌فصلی حرفه‌ای)
 *  ─ ساختار هم‌راستا با گزارش‌های مرجع جهانی (Thomas PPA / Everything DiSC):
 *    فصل۱: نمودارها و الگوی رفتاری | فصل۲: ابعاد و شاخص‌ها | فصل۳: تشریح الگو
 *    فصل۴: پیشنهادات و توصیه‌ها   | فصل۵: تشریح مدل DISC | فصل۶: مشاغل
 *  ─ فقط در سطح «ویژه» (full) رندر می‌شود؛ نسخهٔ رایگان همان تیزر سبک می‌ماند.
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/Analyzer.php';
require_once __DIR__ . '/analysis-texts.php';

/** نام فارسی فصول به ترتیب */
function fr_disc_chapter_title(int $n): string
{
    return [
        1 => 'نمودارها و الگوی رفتاری شما',
        2 => 'ابعاد و توصیف ویژگی‌های رفتاری',
        3 => 'تشریح الگوی رفتاری شما',
        4 => 'پیشنهادات و توصیه‌ها',
        5 => 'تشریح مدل DISC',
        6 => 'مشاغل پیشنهادی متناسب با الگوی شما',
    ][$n] ?? '';
}

/** سربرگ فصل (کارت افتتاحی فصل) */
function fr_disc_chapter_head(int $n, string $sub = ''): void
{
    static $fa = [1 => 'اول', 2 => 'دوم', 3 => 'سوم', 4 => 'چهارم', 5 => 'پنجم', 6 => 'ششم'];
    ?>
  <div class="fr-chapter-head" id="fr-ch<?= $n ?>" style="margin:34px 0 0;padding:16px 20px;border-radius:18px;
       background:linear-gradient(135deg,#1e3a6e,#3e5f9e);color:#fff;box-shadow:0 8px 24px rgba(30,58,110,.18);
       display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <span style="background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:12px;
          padding:4px 14px;font-size:12px;font-weight:700;white-space:nowrap">فصل <?= e($fa[$n] ?? (string)$n) ?></span>
    <b style="font-size:16.5px;letter-spacing:.2px"><?= e(fr_disc_chapter_title($n)) ?></b>
    <?php if ($sub): ?><span style="font-size:12px;opacity:.85"><?= e($sub) ?></span><?php endif; ?>
  </div>
    <?php
}

/** عنوان بخش داخل فصل */
function fr_disc_section_head(string $numFa, string $title, string $note = ''): void
{
    ?>
  <h4 style="margin:24px 0 10px;padding-bottom:8px;border-bottom:1.5px dashed #d8cff2;font-size:15px;color:var(--c-primary,#7c3aed);
     display:flex;align-items:baseline;gap:8px;flex-wrap:wrap">
    <span style="background:#f3efff;border-radius:999px;padding:2px 12px;font-size:11px;color:#5b4f8a"><?= e($numFa) ?></span>
    <?= e($title) ?>
    <?php if ($note): ?><small style="font-size:11.5px;color:var(--muted);font-weight:400"><?= e($note) ?></small><?php endif; ?>
  </h4>
    <?php
}

/**
 * برگرداندن سؤال‌ها و پاسخ‌های تبدیل‌شده به حروف D/I/S/C برای محاسبهٔ شاخص‌ها
 * @return array [questions, answers(qid => ['most'=>'D','least'=>'S'])]
 */
function fr_disc_qa_letters(int $attemptId, int $testId): array
{
    $qs = DB::all('SELECT id, number FROM ' . DB::t('questions') . ' WHERE test_id=? ORDER BY number', [$testId]);
    $keys = [];
    foreach (DB::all('SELECT question_id, meta FROM ' . DB::t('scoring_keys') . ' WHERE test_id=?', [$testId]) as $k) {
        $keys[$k['question_id']] = json_decode($k['meta'] ?? '{}', true)['letters'] ?? null;
    }
    $ans = [];
    foreach (DB::all('SELECT * FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$attemptId]) as $a) {
        $letters = $keys[$a['question_id']] ?? null;
        if (!$letters) continue;
        $ans[$a['question_id']] = [
            'most'  => (string)($letters[$a['most_idx']]  ?? ''),
            'least' => (string)($letters[$a['least_idx']] ?? ''),
        ];
    }
    return [$qs, $ans];
}

/** سبک فرعی Everything DiSC بر اساس دو بُعد برتر و فاصلهٔ آن‌ها */
function fr_disc_substyle_of(array $combined): string
{
    $dims = ['D','I','S','C'];
    $s = [];
    foreach ($dims as $d) $s[$d] = (int)($combined[$d] ?? 0);
    arsort($s);
    $keys = array_keys($s); $vals = array_values($s);
    $t = $keys[0]; $u = $keys[1];
    if ($vals[0] - $vals[1] >= 20) return $t;                       // غلبهٔ روشن یک بُعد
    $pair = [$t, $u];
    usort($pair, fn($a, $b) => strpos('DISC', $a) <=> strpos('DISC', $b));
    $canon = implode('', $pair);
    $leadFirst = ($t === $pair[0]);
    // نگاشت به ۱۲ سبک مرجع Everything DiSC
    switch ($canon) {
        case 'DI': return ($vals[0] - $vals[1] >= 8) ? ($leadFirst ? 'Di' : 'iD') : ($leadFirst ? 'Di' : 'iD');
        case 'DC': return $leadFirst ? 'DC' : 'CD';
        case 'DS': return 'D';
        case 'IS': return $leadFirst ? 'IS' : 'Si';
        case 'IC': return $leadFirst ? 'I' : 'C';
        case 'SC': return $leadFirst ? 'SC' : 'CS';
    }
    return $t;
}

/**
 * رندر شش فصل گزارش عمیق DISC
 * @param array $attempt  ردیف تلاش (باید context داشته باشد)
 * @param array $test     ردیف تست
 * @param array $results  نتایج (۴ ردیف D/I/S/C)
 * @param bool  $printMode حالت چاپ (کنواس‌ها ساده‌سازی می‌شوند)
 */
function fr_disc_deep_chapters(array $attempt, array $test, array $results, bool $printMode = false): void
{
    $graph    = Analyzer::discGraphData((int)$attempt['id']);
    $combined = $graph['combined'];
    $mostPct  = $graph['most'];   // تطبیق‌یافته (بیشترین)
    $leastPct = $graph['least'];  // طبیعی (کمترین)

    [$pattern, $code4] = ReportBuilder::discPattern($attempt, $combined);
    $row   = fr_disc_pattern_row($pattern);
    $ctx   = (($test['audience'] ?? 'general') === 'manager') ? 'manager' : 'family';

    $DIMS  = (array)fr_txt('FERIA_DISC_DIMS');
    $TRAITMAP = (array)fr_txt('FERIA_DISC_TRAITS');
    $FULL  = (array)fr_txt('FERIA_DISC_FULL');
    $LATIN = (array)fr_txt('FERIA_DISC_LATIN');
    $ATL15 = (array)fr_txt('FERIA_DISC_CLASSIC15');
    $MOVE  = (array)fr_txt('FERIA_DISC_MOVE');
    $DMODES= (array)fr_txt('FERIA_DISC_DECISION_MODES');
    $DEEP  = (array)fr_txt('FERIA_DISC_PATTERN_DEEP');
    $ADJ   = (array)fr_txt('FERIA_DISC_ADJUST');
    $GROW  = (array)fr_txt('FERIA_DISC_GROWTH');
    $INTER = (array)fr_txt('FERIA_DISC_INTERACTIONS');
    $PRIO  = (array)fr_txt('FERIA_DISC_PRIORITIES');
    $SUBST = (array)fr_txt('FERIA_DISC_SUBSTYLES');
    $segLbl= (array)fr_txt('FERIA_DISC_SEGMENT_LABELS');

    /* بانک تکمیلیٔ عمیق (۲٫۶) */
    $STY   = (array)fr_txt('FERIA_DISC_PRO_STYLE');
    $PDP   = (array)fr_txt('FERIA_DISC_PRO_DIM_DEEP');
    $PPRO  = (array)fr_txt('FERIA_DISC_PRO_PATTERN');
    $IDX   = (array)fr_txt('FERIA_DISC_PRO_INTERACTION');

    $faDim   = ['D' => 'غلبه (Dominance)', 'I' => 'تأثیرگذاری (Influence)', 'S' => 'ثبات (Steadiness)', 'C' => 'وظیفه‌شناسی (Conscientiousness)'];
    $dimShort= ['D' => 'غلبه‌گرایی', 'I' => 'تأثیرگذاری', 'S' => 'ثبات', 'C' => 'وظیفه‌شناسی'];
    $dimColor= ['D' => '#ef4444', 'I' => '#f59e0b', 'S' => '#10b981', 'C' => '#3b82f6'];

    // مرتب‌سازی بُعدها بر اساس نمرهٔ تلفیقی (نزولی)
    $dims = ['D','I','S','C'];
    usort($dims, fn($a, $b) => (int)($combined[$b] ?? 0) <=> (int)($combined[$a] ?? 0));
    $top   = $dims[0]; $second = $dims[1];
    $weakest = array_slice($dims, -2);

    // داده‌های الگو
    $pDeep = $DEEP[$pattern] ?? $DEEP[$top] ?? [];
    $substyleKey = fr_disc_substyle_of($combined);
    $substylePhrases = (array)($SUBST[$substyleKey] ?? []);

    // شاخص‌های ابعاد (از پاسخ‌های واقعی)
    [$qs, $ansLetters] = fr_disc_qa_letters((int)$attempt['id'], (int)$test['id']);
    $traitScores = function_exists('feria_disc_trait_scores')
        ? feria_disc_trait_scores($qs, $ansLetters, $combined, []) : [];

    $P = static function (string $txt, string $extra = ''): void {
        echo '<p style="font-size:13.5px;line-height:2.3;color:#3a3360;margin:0 0 12px;text-align:justify' . $extra . '">' . e($txt) . '</p>';
    };
    ?>
  <!-- ================= TOC گزارش عمیق ================= -->
  <div class="fr-card fr-toc" style="background:#fcfbff;border:1.5px solid #eee8fb">
    <h3>🗂 فهرست فصول این گزارش</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:2">
      این گزارش در شش فصل مرتب شده است؛ مانند یک جلسهٔ کامل بازخورد روان‌شناختی: از نمودارها و الگوی رفتاری شروع می‌کنیم، به سراغ ابعاد و شاخص‌های دقیق می‌رویم، الگوی شما را تشریح و سپس راهکارهای عملی می‌دهیم و در پایان مدل DISC و مشاغل هم‌راستا را می‌بینیم.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php for ($i = 1; $i <= 6; $i++): ?>
      <a href="#fr-ch<?= $i ?>" style="text-decoration:none"><span class="fr-chip purple" style="cursor:pointer">فصل <?= ['اول','دوم','سوم','چهارم','پنجم','ششم'][$i-1] ?> · <?= e(fr_disc_chapter_title($i)) ?></span></a>
      <?php endfor; ?>
    </div>
  </div>

  <!-- ================= نقشهٔ یک‌نگاه (خلاصهٔ اجرایی) ================= -->
  <?php
    $__shift0 = function_exists('feria_disc_shift') ? feria_disc_shift($mostPct, $leastPct) : null;
    $__mode0  = function_exists('feria_disc_decision_mode') ? feria_disc_decision_mode($combined) : '';
    $__modeT  = (string)($DMODES[$__mode0]['title'] ?? '');
  ?>
  <div class="fr-card" style="background:linear-gradient(135deg,#f6f2ff,#eef3ff);border:1.5px solid #d8cff2">
    <h3 style="margin-bottom:4px">🧭 نقشهٔ یک‌نگاه — خلاصهٔ اجرایی پروفایل شما</h3>
    <p style="font-size:12px;color:var(--muted);margin:0 0 12px">تمام نتایج این گزارش در یک نگاه؛ جزئیات هر مورد در فصل مربوطه آمده است.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px">
      <div style="background:#fff;border-radius:12px;padding:12px 14px;border:1px solid #eee8fb">
        <small style="color:var(--muted);font-size:11px">الگوی رفتاری (کلاسیک)</small><br>
        <b style="font-size:15px;color:var(--c-primary)"><?= e($row['name'] ?? $pattern) ?></b>
        <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
          <span class="fr-chip purple" style="font-size:10.5px">کد <bdi dir="ltr"><?= e($pattern) ?></bdi></span>
          <?php if ($code4): ?><span class="fr-chip info" style="font-size:10.5px">شناسهٔ عددی <bdi dir="ltr"><?= e($code4) ?></bdi></span><?php endif; ?>
        </div>
      </div>
      <div style="background:#fff;border-radius:12px;padding:12px 14px;border:1px solid #eee8fb">
        <small style="color:var(--muted);font-size:11px">دو بُعد برتر شما</small><br>
        <b style="font-size:13.5px;color:<?= $dimColor[$top] ?>"><?= e($dimShort[$top]) ?> <?= fa_num((int)($combined[$top] ?? 0)) ?>٪</b>
        <span style="color:var(--muted)"> + </span>
        <b style="font-size:13.5px;color:<?= $dimColor[$second] ?>"><?= e($dimShort[$second]) ?> <?= fa_num((int)($combined[$second] ?? 0)) ?>٪</b>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">محرک اصلی رفتار در محیطِ تست</div>
      </div>
      <div style="background:#fff;border-radius:12px;padding:12px 14px;border:1px solid #eee8fb">
        <small style="color:var(--muted);font-size:11px">سبک فرعی Everything DiSC</small><br>
        <b style="font-size:15px"><bdi dir="ltr"><?= e($substyleKey) ?></bdi></b>
        <?php if ($substylePhrases): ?><div style="font-size:11px;color:var(--muted);margin-top:6px"><?= e(implode(' · ', array_slice($substylePhrases, 0, 3))) ?></div><?php endif; ?>
      </div>
      <div style="background:#fff;border-radius:12px;padding:12px 14px;border:1px solid #eee8fb">
        <small style="color:var(--muted);font-size:11px">تطابق دو نمودار</small><br>
        <?php if ($__shift0): ?>
        <b style="font-size:13.5px;color:<?= $__shift0['ok'] ? '#0c7a4d' : '#b3261e' ?>"><?= e($__shift0['title']) ?></b>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">شاخص جابه‌جایی: <?= fa_num($__shift0['idx']) ?> از ۱۰۰</div>
        <?php else: ?><b>—</b><?php endif; ?>
      </div>
      <?php if ($__modeT): ?>
      <div style="background:#fff;border-radius:12px;padding:12px 14px;border:1px solid #eee8fb">
        <small style="color:var(--muted);font-size:11px">شکلِ غالب تصمیم‌گیری</small><br>
        <b style="font-size:13.5px"><?= e($__modeT) ?></b>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">تشریح کامل در فصل سوم</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ==================== فصل اول ==================== -->
  <?php fr_disc_chapter_head(1, 'نمودارهای چهاربعدی + دو چهرهٔ طبیعی/تطبیق‌یافته + تمایلات + الگوهای جهانی'); ?>

  <div class="fr-card">
    <?php fr_disc_section_head('بخش اول', 'نمودارهای DISC و توضیحات', 'نمرهٔ هر بُعد بین ۰ تا ۱۰۰ و روی سگمنت‌های ۱ تا ۷'); ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:6px">
      <thead>
        <tr style="color:var(--muted);font-size:12px">
          <th style="text-align:right;padding:8px 10px;border-bottom:1.5px solid #eee8fb">بُعد رفتاری</th>
          <th style="text-align:center;padding:8px 6px;border-bottom:1.5px solid #eee8fb">درصد</th>
          <th style="text-align:center;padding:8px 6px;border-bottom:1.5px solid #eee8fb">سگمنت<br><small style="font-weight:400">(۱ از ۷)</small></th>
          <th style="text-align:center;padding:8px 10px;border-bottom:1.5px solid #eee8fb">سطح برجستگی</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (['D','I','S','C'] as $d):
            $pct = (int)($combined[$d] ?? 0);
            $seg = feria_disc_segment($pct);
            [$lv, $lvColor] = feria_disc_dim_level($pct);
        ?>
        <tr<?= $d === $top ? ' style="background:#faf8ff"' : '' ?>>
          <td style="padding:9px 10px;border-bottom:1px solid #f4f1fc">
            <b style="color:<?= $dimColor[$d] ?>"><?= e($faDim[$d]) ?></b>
            <?php if ($d === $top): ?><span class="fr-chip purple" style="font-size:10px;margin-right:6px">غالب</span><?php endif; ?>
            <?php if ($d === $second && mb_strlen($pattern) === 2): ?><span class="fr-chip info" style="font-size:10px;margin-right:6px">دوم</span><?php endif; ?>
          </td>
          <td style="text-align:center;padding:9px 6px;border-bottom:1px solid #f4f1fc"><b><?= fa_num($pct) ?>٪</b></td>
          <td style="text-align:center;padding:9px 6px;border-bottom:1px solid #f4f1fc">
            <span class="fr-chip info" style="font-size:11px" title="<?= e($segLbl[$seg] ?? '') ?>"><?= fa_num($seg) ?></span>
            <small style="color:var(--muted)"><?= e($segLbl[$seg] ?? '') ?></small>
          </td>
          <td style="text-align:center;padding:9px 10px;border-bottom:1px solid #f4f1fc"><b style="color:<?= $lvColor ?>"><?= e($lv) ?></b></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($code4): ?>
    <p style="font-size:12.5px;color:var(--muted);margin:8px 0 0">
      شناسهٔ عددی الگوی شما: <bdi dir="ltr" style="font-weight:800;color:var(--c-primary)"><?= e($code4) ?></bdi>
      (چهار رقم = سگمنتِ هر بُعد به ترتیب D,I,S,C — همان قالب گزارش‌های مرجع جهانی)
    </p>
    <?php endif; ?>
  </div>

  <div class="fr-card">
    <?php fr_disc_section_head('ادامهٔ بخش اول', 'دو چهرهٔ رفتاری شما: طبیعی و تطبیق‌یافته'); ?>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 10px;line-height:2">
      نمودار <b style="color:var(--c-primary)">توأمان</b> دو چیز را نشان می‌دهد:
      <b>تطبیق‌یافته</b> (چهره‌ای که فکر می‌کنید محیط از شما می‌خواهد — انتخاب‌های «بیشترین شبیه من»)
      و <b>طبیعی</b> (خویِ اصیل — ویژگی‌هایی که تمایل شدیدی به انتخاب‌شان ندارید).
      در گزارش‌های مرجع، نمودار «بیشترین» تصویرِ کار/محیط و نمودار «کمترین» تصویرِ فطری نامیده می‌شود.
    </p>
    <?php if (!$printMode):
        $dual = [];
        foreach (['D','I','S','C'] as $d) {
            $dual[] = ['label'=>$d, 'value'=>(float)($mostPct[$d] ?? 0), 'value2'=>(float)($leastPct[$d] ?? 0), 'color'=>$dimColor[$d]];
        }
        ?>
    <canvas class="fr-chart" data-chart='<?= e(json_encode($dual, JSON_UNESCAPED_UNICODE)) ?>'
            data-opts='{"height":250,"dual":true,"s1":"تطبیق‌یافته","s2":"طبیعی"}' style="height:250px"></canvas>
    <div style="display:flex;gap:14px;justify-content:center;font-size:12px;color:var(--muted);margin-top:6px">
      <span><i style="display:inline-block;width:12px;height:12px;background:#1e3a6e;border-radius:3px;margin-left:4px"></i>تطبیق‌یافته (بیشترین)</span>
      <span><i style="display:inline-block;width:12px;height:12px;background:#c9c2e8;border-radius:3px;margin-left:4px"></i>طبیعی (کمترین)</span>
    </div>
    <?php else: ?>
    <table style="width:100%;max-width:480px;margin:0 auto;border-collapse:collapse;font-size:12.5px">
      <tr style="color:var(--muted)"><td style="padding:6px">بُعد</td><td style="padding:6px;text-align:center">تطبیق‌یافته</td><td style="padding:6px;text-align:center">طبیعی</td></tr>
      <?php foreach (['D','I','S','C'] as $d): ?>
      <tr><td style="padding:6px;border-top:1px solid #f0ecfa"><b style="color:<?= $dimColor[$d] ?>"><?= e($faDim[$d]) ?></b></td>
          <td style="padding:6px;border-top:1px solid #f0ecfa;text-align:center"><?= fa_num((int)($mostPct[$d] ?? 0)) ?>٪</td>
          <td style="padding:6px;border-top:1px solid #f0ecfa;text-align:center"><?= fa_num((int)($leastPct[$d] ?? 0)) ?>٪</td></tr>
      <?php endforeach; ?>
    </table>
    <p style="font-size:12px;color:var(--muted);margin:6px 0 0">📈 نمودار خطی هر سه چهره را در ادامهٔ همین گزارش (کارت نمودار خطی نتایج DISC) ببینید.</p>
    <?php endif; ?>

    <?php
    /* شاخص جابه‌جایی رفتاری (تطابق دو نمودار) */
    $shift = feria_disc_shift($mostPct, $leastPct);
    $shiftColor = $shift['ok'] ? '#0c7a4d' : '#b3261e';
    $shiftBg    = $shift['ok'] ? '#f0fdf6' : '#fff7f7';
    ?>
    <div style="background:<?= $shiftBg ?>;border:1.5px solid <?= $shift['ok'] ? '#c9ecd9' : '#f5d3d3' ?>;border-radius:14px;padding:14px 16px;margin-top:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <b style="font-size:13.5px;color:<?= $shiftColor ?>">🔄 شاخص تطابق دو نمودار (جابه‌جایی رفتاری): <?= e($shift['title']) ?></b>
        <span class="fr-chip <?= $shift['ok'] ? 'ok' : 'err' ?>" style="font-size:11px"><?= fa_num($shift['idx']) ?> از <?= fa_num(100) ?></span>
      </div>
      <div class="fr-scalebar" style="margin:10px 0 8px">
        <span class="track"><span class="fill" data-w="<?= (int)$shift['idx'] ?>%" style="width:0;background:<?= $shift['ok'] ? 'linear-gradient(90deg,#19a974,#0c7a4d)' : 'linear-gradient(90deg,#f59e0b,#e0393f)' ?>"></span></span>
      </div>
      <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0"><?= e($shift['text']) ?></p>
    </div>

    <?php
    /* حرکت ابعاد بین دو نمودار */
    $moves = [];
    foreach (['D','I','S','C'] as $d) {
        $delta = (int)($mostPct[$d] ?? 0) - (int)($leastPct[$d] ?? 0);
        if (abs($delta) >= 12) $moves[$d] = $delta > 0 ? 'up' : 'down';
    }
    ?>
    <h4 style="font-size:14px;color:var(--c-primary);margin:18px 0 8px">◍ حرکت هر بُعد بین دو نمودار</h4>
    <?php if ($moves): ?>
      <?php foreach ($moves as $d => $dir): ?>
      <div style="border-right:3px solid <?= $dimColor[$d] ?>;padding-right:12px;margin-bottom:12px">
        <b style="font-size:13px"><?= e($faDim[$d]) ?> — <?= $dir === 'up' ? 'نمایشِ بیشتر در محیط ⬆' : 'نمایشِ کمتر در محیط ⬇' ?></b>
        <p style="font-size:12.5px;line-height:2.1;color:#3a3360;margin:4px 0 0"><?= e($MOVE[$d][$dir] ?? '') ?></p>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p style="font-size:12.5px;line-height:2.1;color:#3a3360;margin:0">
        هیچ‌کدام از چهار بُعد شما بین دو نمودار حرکتِ چشمگیر (۱۲ درجه یا بیشتر) ندارند؛ یعنی جایگاهِ هر انرژی در خویِ طبیعی و در محیطِ کنونی شما تقریباً یکی است. این نشانهٔ سازگاریِ رفتاریِ پایدار است.
      </p>
    <?php endif; ?>
  </div>

  <div class="fr-card">
    <?php fr_disc_section_head('ادامهٔ بخش اول', 'میزان تمایل رفتاری شما', '۳۲ شاخص دوقطبی ـ از ترکیب وزنی نمرات چهار بُعد'); ?>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;line-height:2">
      هر شاخص یک خط‌کش دوقطبی است؛ مارک بنفش جایگاهِ شما را روی آن نشان می‌دهد (هرچه به قطبِ راستِ هر مقیاس نزدیک‌تر باشید، امتیاز بالاتر است):
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px 18px">
      <?php $tendAll = function_exists('feria_disc_tendencies') ? feria_disc_tendencies($combined) : [];
      foreach ($tendAll as $t): ?>
      <div style="background:#fcfbff;border:1px solid #f0ecfa;border-radius:14px;padding:10px 12px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
          <b style="font-size:12px"><?= e($t['title']) ?></b>
          <span class="fr-chip <?= ['red'=>'err','premium'=>'purple','purple'=>'info','blue'=>'info','green'=>'ok'][$t['chip']] ?? 'info' ?>" style="font-size:10px;white-space:nowrap"><?= fa_num($t['pct']) ?>٪ <?= e($t['level']) ?></span>
        </div>
        <div style="position:relative;height:8px;background:linear-gradient(90deg,#e8e4f6,#c9c2e8);border-radius:999px;margin:10px 0 4px">
          <span style="position:absolute;top:-3px;width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid var(--c-primary,#7c3aed);
                right:calc(<?= (int)$t['pct'] ?>% - 7px);box-shadow:0 1px 4px rgba(0,0,0,.2)"></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--muted)">
          <span><?= e($t['left']) ?></span><span><?= e($t['right']) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php
    /* خوانش تخصصی تمایلات بحرانی: شاخص‌هایی با امتیاز «خیلی بالا» یا «خیلی پایین» — فصل ۲٫۶ */
    $extremes = array_values(array_filter($tendAll, fn($t) => in_array($t['level'], ['خیلی بالا','خیلی پایین'], true)));
    if ($extremes):
    ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:22px 0 8px">◍ خوانش تخصصی تمایلات بحرانی شما</h4>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 10px;line-height:2">
      شاخص‌هایی که در شما در دو سرِ خط‌کش‌اند («خیلی بالا» یا «خیلی پایین») امضای رفتاری شما را بیش از همه تعیین می‌کنند؛
      در جلسهٔ بازخورد حرفه‌ای، همین نکات آغازِ گفت‌وگوی عمیق‌اند:
    </p>
    <?php foreach ($extremes as $t):
        $isHi = $t['level'] === 'خیلی بالا';
        $pole = $isHi ? $t['right'] : $t['left'];
        $opp  = $isHi ? $t['left']  : $t['right'];
    ?>
    <div style="border-right:3px solid <?= $isHi ? '#e0393f' : '#19a974' ?>;padding-right:12px;margin-bottom:12px">
      <b style="font-size:13px"><?= e($t['title']) ?>: تمایل به «<?= e($pole) ?>» (<?= fa_num($t['pct']) ?>٪)</b>
      <p style="font-size:12.5px;line-height:2.1;color:#3a3360;margin:4px 0 0">
        شما در این محور تقریباً همیشه به سمت «<b><?= e($pole) ?></b>» می‌روید و این آگاهی برای شما یک اهرم است:
        کنترلِ این قطب، کلیدِ «<?= e($t['title']) ?>» در کار و رابطه را در دست شما می‌گذارد.
        مراقبتِ تخصصی: قطبِ مقابل («<?= e($opp) ?>») در برخی موقعیت‌ها آپشنِ لازم است، نه تهدید —
        موقعیتی را شناسایی کنید که سبکِ «<?= e($opp) ?>» به نتیجهٔ بهتر می‌رسد و آن را به‌شکلِ محدودِ آگاهانه تمرین کنید.
      </p>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="fr-card" style="background:#fcfbff">
    <?php fr_disc_section_head('بخش دوم', 'الگوی رفتاری شما در میان ۱۵ الگوی متداول جهانی DISC'); ?>
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0 0 12px">
      مدل کلاسیک DISC، فضای رفتاری آدم‌ها را به ۱۵ الگوی متداول (چهار الگوی خالص و ترکیب‌های دو‌بُعدی آن‌ها) نقشه‌برداری می‌کند.
      الگوی شما با قاب بنفش مشخص شده است؛ خواندنِ الگوهای دیگر به شما کمک می‌کند سبک آدم‌های پیرامون‌تان — همکار، همسر، مدیر یا فرزند — را هم سریع‌تر تشخیص دهید:
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px">
      <?php foreach ($ATL15 as $code => $pt):
        $mine = ($code === $pattern) || (mb_strlen($pattern) === 2 && $code === strrev($pattern));
        ?>
      <div style="border-radius:14px;padding:12px 14px;<?= $mine
            ? 'background:linear-gradient(135deg,#f3efff,#e9e2fb);border:1.5px solid var(--c-primary,#7c3aed);box-shadow:0 4px 14px rgba(124,58,237,.12)'
            : 'background:#fff;border:1px solid #eee8fb' ?>">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="direction:ltr;display:inline-block;background:<?= $mine ? 'var(--c-primary,#7c3aed)' : '#eee8fb' ?>;
                color:<?= $mine ? '#fff' : '#5b4f8a' ?>;font-weight:800;border-radius:8px;padding:1px 9px;font-size:12px;letter-spacing:1px"><?= e($code) ?></span>
          <b style="font-size:13px"><?= e($pt['name']) ?></b>
          <?php if ($mine): ?><span class="fr-chip purple" style="font-size:10px">الگوی شما ⭐</span><?php endif; ?>
        </div>
        <p style="font-size:11.5px;line-height:1.95;color:#525d7e;margin:8px 0 0"><?= e($pt['blurb']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="fr-card" style="background:linear-gradient(135deg,#faf8ff,#f4f0ff)">
    <?php fr_disc_section_head('بخش سوم', 'معرفی الگوی رفتاری شما'); ?>
    <div style="text-align:center;padding:8px 0 4px">
      <div style="font-size:28px;font-weight:900;color:var(--c-primary);letter-spacing:1px"><?= e($row['name'] ?? $pattern) ?></div>
      <?php if (!empty($row['tagline'])): ?><div style="font-size:13.5px;color:#5b4f8a;margin-top:2px"><?= e($row['tagline']) ?></div><?php endif; ?>
      <div style="display:flex;justify-content:center;gap:8px;margin-top:10px;flex-wrap:wrap">
        <span class="fr-chip purple">کد الگو: <bdi dir="ltr"><?= e($pattern) ?></bdi></span>
        <?php if ($code4): ?><span class="fr-chip info">شناسهٔ عددی: <bdi dir="ltr"><?= e($code4) ?></bdi></span><?php endif; ?>
        <?php if ($substylePhrases): ?><span class="fr-chip ok">سبک فرعی Everything DiSC: <bdi dir="ltr"><?= e($substyleKey) ?></bdi></span><?php endif; ?>
      </div>
      <?php if ($substylePhrases): ?>
      <div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin-top:8px">
        <?php foreach ($substylePhrases as $ph): ?><span class="fr-chip info" style="font-size:11px"><?= e($ph) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="margin-top:14px">
      <?php foreach ((array)($pDeep['deep'] ?? []) as $para) $P($para); ?>
      <?php if (empty($pDeep['deep']) && !empty($row['summary'])) $P($row['summary']); ?>
      <p style="font-size:11.5px;color:var(--muted);margin:4px 0 0;border-top:1px dashed #e6e1f7;padding-top:10px">
        📌 یادآوری علمی: در مدل جهانی DISC هیچ الگویی خوب یا بد، درست یا غلط نیست؛ هر الگو در جای خود لازم و ارزشمند است.
        بخش‌هایی از این گزارش که با شما تطابقِ بیشتری دارد پررنگ‌تر ببینید و آنچه کمتر می‌خورد طبیعی بدانید.
      </p>
    </div>
  </div>

  <!-- ==================== فصل دوم ==================== -->
  <?php fr_disc_chapter_head(2, 'ابعاد اصلی + شاخص‌های هر بُعد + توصیف شاخص‌ها'); ?>

  <div class="fr-card">
    <?php fr_disc_section_head('بخش اول', 'ابعاد اصلی DISC شما', 'هر چهار بُعد به ترتیب غلبه در شما'); ?>
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0 0 12px">
      هر انسان ترکیبی یکتا از هر چهار انرژی D، I، S و C است. در ادامه هر بُعد بر اساس شدتِ نمایشش در شما توصیف می‌شود —
      از برجسته‌ترین بُعدتان تا آرام‌ترین‌شان:
    </p>
    <?php foreach ($dims as $rank => $d): $dd = $DIMS[$d] ?? null; if (!$dd) continue; ?>
    <div style="border-right:3px solid <?= $dimColor[$d] ?>;padding-right:14px;margin-bottom:18px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <b style="font-size:14.5px"><?= e($dd['title']) ?></b>
        <span class="fr-chip <?= $rank === 0 ? 'purple' : ($rank === 1 ? 'info' : '') ?>" style="font-size:10.5px">
          <?= fa_num((int)($combined[$d] ?? 0)) ?>٪ · رتبهٔ <?= ['اول','دوم','سوم','چهارم'][$rank] ?> در شما</span>
      </div>
      <div style="font-size:12px;color:var(--muted);margin:2px 0 8px"><?= e($dd['subtitle']) ?></div>
      <?php $P($dd['desc'] ?? ''); ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-top:4px">
        <div style="background:#f0fdf6;border-radius:12px;padding:10px 12px">
          <b style="font-size:12px;color:#0c7a4d">🌿 محیط مطلوب این بُعد</b>
          <p style="font-size:12px;line-height:2;color:#29543e;margin:6px 0 0"><?= e($dd['env'] ?? '') ?></p>
        </div>
        <div style="background:#fafbfd;border:1px dashed #dbe3ea;border-radius:12px;padding:10px 12px">
          <b style="font-size:12px;color:#52606d">🧩 نیازهای این بُعد برای اثربخشی</b>
          <p style="font-size:12px;line-height:2;color:#4a4370;margin:6px 0 0"><?= e($dd['needs'] ?? '') ?></p>
        </div>
        <div style="background:#fffdf5;border:1.5px solid #ffe9c2;border-radius:12px;padding:10px 12px">
          <b style="font-size:12px;color:#8a5b00">🎯 کانونِ رشد</b>
          <p style="font-size:12px;line-height:2;color:#5c4a1e;margin:6px 0 0"><?= e($dd['focus'] ?? '') ?></p>
        </div>
      </div>

      <?php /* کارت سبک (الگوی Everything DiSC): اولویت‌ها / انگیزاننده‌ها / تنش‌زاها */ ?>
      <?php $__sty = $STY[$d] ?? null; if ($__sty): ?>
      <div style="background:#fff;border:1.5px solid #eee8fb;border-radius:14px;padding:14px 16px;margin-top:12px">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">
          <b style="font-size:13px;color:<?= $dimColor[$d] ?>">🪪 کارت سبک این بُعد</b>
          <span style="font-size:12px;color:#5b4f8a;font-style:italic">«<?= e((string)($__sty['quote'] ?? '')) ?>»</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px">
          <div>
            <b style="font-size:11.5px;color:#0c7a4d">اولویت‌های کاری</b>
            <ul style="font-size:12px;line-height:2;padding-right:16px;margin:5px 0 0;color:#29543e">
              <?php foreach ((array)($__sty['priorities'] ?? []) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
            </ul>
          </div>
          <div>
            <b style="font-size:11.5px;color:#3d5aac">انگیزاننده‌ها</b>
            <ul style="font-size:12px;line-height:2;padding-right:16px;margin:5px 0 0;color:#2c3f63">
              <?php foreach ((array)($__sty['motivators'] ?? []) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
            </ul>
          </div>
          <div>
            <b style="font-size:11.5px;color:#b3261e">تنش‌زاهای اصلی</b>
            <ul style="font-size:12px;line-height:2;padding-right:16px;margin:5px 0 0;color:#6b3737">
              <?php foreach ((array)($__sty['stressors'] ?? []) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php /* برش‌های تحلیلی عمیق: برای دو بُعد برتر همهٔ ۱۴ برش، برای دو بعدِ بعدی چهار برش کلیدی */ ?>
      <?php
        $__sections = (array)($PDP[$d] ?? []);
        if ($__sections):
          $__idx = ($rank <= 1) ? array_keys($__sections) : [0, 2, 3, 13];
          $__idx = array_values(array_filter($__idx, fn($i) => isset($__sections[$i])));
      ?>
      <details class="fr-deep-details" open style="margin-top:14px;background:#fcfbff;border:1.5px dashed #d8cff2;border-radius:14px;padding:12px 16px">
        <summary style="cursor:pointer;font-size:13px;font-weight:700;color:<?= $dimColor[$d] ?>;list-style:none">
          🔬 برش‌های تحلیلی عمیق بُعد <?= e($faDim[$d]) ?>
          <small style="font-weight:400;color:var(--muted)">(<?= fa_num(count($__idx)) ?> برش تخصصی — کادر را باز/بسته کنید)</small>
        </summary>
        <div style="margin-top:10px">
          <?php foreach ($__idx as $si): [$st, $sb] = $__sections[$si]; ?>
          <div style="border-right:2px solid <?= $dimColor[$d] ?>;padding-right:12px;margin-bottom:12px">
            <b style="font-size:12.5px"><?= e($st) ?></b>
            <p style="font-size:12.5px;line-height:2.15;color:#3a3360;margin:5px 0 0;text-align:justify"><?= e($sb) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="fr-card">
    <?php fr_disc_section_head('بخش دوم', 'شاخص ابعاد', 'هفت شاخص کلیدی هر بُعد + شدت آن‌ها در شما'); ?>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;line-height:2">
      هر بُعد DISC از هفت شاخص رفتاری ساخته می‌شود. شدت هر شاخص مستقیماً از پاسخ‌های واقعی شما (و لنگرِ نمرهٔ همان بُعد) محاسبه شده است:
    </p>
    <?php foreach ($dims as $d): $list = $traitScores[$d] ?? []; if (!$list) continue; ?>
    <h4 style="font-size:14px;color:<?= $dimColor[$d] ?>;margin:18px 0 8px">◍ شاخص‌های بُعد <?= e($faDim[$d]) ?></h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px">
      <?php foreach ($list as $tr): ?>
      <div style="background:#fcfbff;border:1px solid #f0ecfa;border-radius:12px;padding:10px 12px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:6px">
          <b style="font-size:12.5px"><?= e($tr['name']) ?></b>
          <span class="fr-chip <?= ['red'=>'err','premium'=>'purple','purple'=>'info','blue'=>'info','green'=>'ok'][$tr['chip']] ?? 'info' ?>" style="font-size:10px"><?= fa_num($tr['pct']) ?>٪</span>
        </div>
        <div style="height:6px;background:#eee8fb;border-radius:999px;margin-top:8px;overflow:hidden">
          <span style="display:block;height:100%;width:<?= (int)$tr['pct'] ?>%;background:<?= $dimColor[$d] ?>;border-radius:999px"></span>
        </div>
        <div style="font-size:10.5px;color:var(--muted);margin-top:5px"><?= e($tr['level']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="fr-card" style="background:#fcfbff">
    <?php fr_disc_section_head('بخش سوم', 'توصیف شاخص ابعاد', 'خوانش تخصصی هر شاخص در دو بُعد غالب شما'); ?>
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0 0 12px">
      در ادامه، هر هفت شاخصِ هر چهار بُعد — از برجسته‌ترین بُعدتان (<b style="color:<?= $dimColor[$top] ?>"><?= e($dimShort[$top]) ?></b>) تا آرام‌ترین‌شان —
      با دو روایت می‌آید: <b style="color:#0c7a4d">نقطهٔ قوت آن در شما</b> و <b style="color:#b3261e">خطرِ افراطش</b> —
      نکته‌ای که در جلسات بازخورد حرفه‌ای DISC حتماً مطرح می‌شود. بخش‌هایی که با شما تطابقِ بیشتری دارد پررنگ‌تر ببینید:
    </p>
    <?php foreach ($dims as $rank => $d): $list = $traitScores[$d] ?? []; if (!$list) continue; ?>
    <h4 style="font-size:14.5px;color:<?= $dimColor[$d] ?>;margin:18px 0 8px">◍ شاخص‌های بُعد <?= e($faDim[$d]) ?>
      <span class="fr-chip <?= $rank === 0 ? 'purple' : ($rank === 1 ? 'info' : '') ?>" style="font-size:10.5px"><?= ['غالب','دوم','سوم','آرام'][$rank] ?> در شما</span></h4>
    <?php foreach ($list as $tr): ?>
      <div style="border-right:3px solid <?= $dimColor[$d] ?>;padding-right:12px;margin-bottom:12px">
        <b style="font-size:13px"><?= e($tr['name']) ?></b>
        <span class="fr-chip <?= ['red'=>'err','premium'=>'purple','purple'=>'info','blue'=>'info','green'=>'ok'][$tr['chip']] ?? 'info' ?>" style="font-size:10px;margin-right:6px"><?= fa_num($tr['pct']) ?>٪ · <?= e($tr['level']) ?></span>
        <p style="font-size:12.5px;line-height:2.1;color:#3a3360;margin:4px 0 0"><?= e($tr['text']) ?></p>
      </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <!-- ==================== فصل سوم ==================== -->
  <?php fr_disc_chapter_head(3, 'انگیزه + عادات کاری + دیدگاه‌ها + تصمیم‌گیری + رمز موفقیت'); ?>

  <div class="fr-card">
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0 0 6px">
      در این فصل، الگوی «<?= e($row['name'] ?? $pattern) ?>» از چند زاویهٔ تخصصی تشریح می‌شود تا نقشهٔ عملیاتیِ به‌کارگیریِ آن را در کار، خانه و رابطه‌ها داشته باشید:
    </p>
    <?php
    $secs = [
        'summary'    => 'نگاه کلی',
        'motivation' => 'انگیزهٔ شما — چه چیزی چرخ‌های شما را می‌چرخاند',
        'work_style' => 'عادت‌های کاری شما',
        'attitude'   => 'دیدگاه‌های شما به دنیا و آدم‌ها',
        'decision'   => 'تصمیم‌گیری در موقعیت‌های حساس',
    ];
    foreach ($secs as $k => $ttl): if (empty($row[$k])) continue; ?>
      <h4 style="font-size:14.5px;color:var(--c-primary);margin:18px 0 6px">◍ <?= e($ttl) ?></h4>
      <?php $P($row[$k]); ?>
    <?php endforeach; ?>

    <?php /* برش‌های عمیق الگو (بانک تکمیلی ۲٫۶) */ ?>
    <?php $ppro = $PPRO[$pattern] ?? $PPRO[$top] ?? []; if ($ppro): ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:20px 0 6px">◍ جهان‌بینی رفتاری شما</h4>
    <?php if (!empty($ppro['worldview'])) $P($ppro['worldview']); ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:18px 0 6px">◍ عادت‌های کاری عمیق — آنچه هر روز تکرار می‌کنید</h4>
    <?php if (!empty($ppro['work_habits'])) $P($ppro['work_habits']); ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:18px 0 6px">◍ شما در روابط — الگوی پیوند و نیازها</h4>
    <?php if (!empty($ppro['relationships'])) $P($ppro['relationships']); ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:18px 0 6px">◍ مدیریت فشار و تخلیهٔ انرژی</h4>
    <?php if (!empty($ppro['stress_management'])) $P($ppro['stress_management']); ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:18px 0 6px">◍ اوقات فراغتِ هم‌خوان با خوی شما</h4>
    <?php if (!empty($ppro['leisure_note'])) $P($ppro['leisure_note']); ?>
    <?php endif; ?>

    <?php /* سه الگوی تصمیم‌گیری — انتخاب خودکار بر اساس نسبت D/C */ ?>
    <?php $modeKey = feria_disc_decision_mode($combined); $mode = $DMODES[$modeKey] ?? null; if ($mode): ?>
    <div style="background:#fafbfd;border:1.5px dashed #e6e1f7;border-radius:14px;padding:14px 16px;margin-top:6px">
      <b style="font-size:13.5px;color:var(--c-primary)">⚖️ شکلِ غالب تصمیم‌گیری شما: <?= e($mode['title']) ?></b>
      <p style="font-size:13px;line-height:2.2;color:#3a3360;margin:8px 0 0"><?= e($mode['text']) ?></p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;font-size:11px;color:var(--muted)">
        <?php foreach ($DMODES as $mk => $mm): ?>
        <span class="fr-chip <?= $mk === $modeKey ? 'purple' : 'info' ?>" style="font-size:10.5px"><?= e($mm['title']) ?><?= $mk === $modeKey ? ' ← شما' : '' ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php /* سه سناریوی تصمیم‌گیری بر اساس نسبت غلبهٔ ابعاد */ ?>
    <?php $__scen = (array)($ppro['decision_scenarios'] ?? []); if ($__scen): ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:20px 0 8px">◍ سه سناریوی تصمیم‌گیری شما</h4>
    <p style="font-size:12px;color:var(--muted);margin:0 0 10px;line-height:2">
      شدتِ نسبیِ ابعاد در هر نفر متفاوت است و تصمیم‌گیری شما بسته به این‌که کدام بُعد جلوتر باشد، سه چهره پیدا می‌کند؛
      در هر سه حالت، توصیهٔ اختصاصی متخصص برایتان آمده است:
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px">
      <div style="background:#fff;border:1.5px solid #eee8fb;border-radius:14px;padding:13px 15px">
        <b style="font-size:12.5px;color:<?= $dimColor[$top] ?>">سناریوی ۱ · غلبهٔ روشنِ <?= e($dimShort[$top]) ?></b>
        <p style="font-size:12.5px;line-height:2.1;color:#3a3360;margin:7px 0 0"><?= e((string)($__scen['first'] ?? '')) ?></p>
      </div>
      <div style="background:#fff;border:1.5px solid #eee8fb;border-radius:14px;padding:13px 15px">
        <b style="font-size:12.5px;color:<?= $dimColor[$second] ?>">سناریوی ۲ · فعال‌شدنِ بُعد دوم (<?= e($dimShort[$second]) ?>)</b>
        <p style="font-size:12.5px;line-height:2.1;color:#3a3360;margin:7px 0 0"><?= e((string)($__scen['second'] ?? '')) ?></p>
      </div>
      <div style="background:linear-gradient(135deg,#f6f2ff,#eef3ff);border:1.5px solid #d8cff2;border-radius:14px;padding:13px 15px">
        <b style="font-size:12.5px;color:var(--c-primary)">سناریوی ۳ · تعادلِ دو بُعد ⭐</b>
        <p style="font-size:12.5px;line-height:2.1;color:#3a3360;margin:7px 0 0"><?= e((string)($__scen['balance'] ?? '')) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($row['strengths']) || !empty($row['risks'])): ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:20px 0 8px">◍ نقاط قوت و قابل‌بهبود</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px">
      <div style="background:#f0fdf6;border-radius:12px;padding:14px">
        <b style="font-size:13px;color:#0c7a4d">💪 نقاط قوت شما</b>
        <ul style="font-size:12.5px;line-height:2.1;padding-right:18px;margin:8px 0 0;color:#29543e">
          <?php foreach ((array)($row['strengths'] ?? []) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
        </ul>
      </div>
      <div style="background:#fff7f7;border-radius:12px;padding:14px">
        <b style="font-size:13px;color:#b3261e">⚠️ نقاط قابل‌بهبود (مراقبت‌ها)</b>
        <ul style="font-size:12.5px;line-height:2.1;padding-right:18px;margin:8px 0 0;color:#6b3737">
          <?php foreach ((array)($row['risks'] ?? []) as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($row['improve'])): ?>
      <h4 style="font-size:14.5px;color:var(--c-primary);margin:20px 0 6px">◍ پیشنهادات بهبود عملکرد</h4>
      <ul style="font-size:13px;line-height:2.25;padding-right:20px;margin:0;color:#3a3360;columns:auto">
        <?php foreach ((array)$row['improve'] as $i => $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!empty($row['success_keys'])): ?>
      <h4 style="font-size:14.5px;color:var(--c-primary);margin:20px 0 6px">◍ رمز موفقیت شما</h4>
      <ul style="font-size:13px;line-height:2.25;padding-right:20px;margin:0;color:#3a3360">
        <?php foreach ((array)$row['success_keys'] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!empty($row['skills'])): ?>
      <h4 style="font-size:14.5px;color:var(--c-primary);margin:20px 0 6px">◍ دانش و مهارت‌های آموزشی موردنیاز برای بهبود عملکرد</h4>
      <p style="font-size:12.5px;color:var(--muted);margin:0 0 8px">این مهارت‌ها بیشترین اهرمِ رشد را روی الگوی شما دارند — دنبال دوره یا مربیِ همین عناوین باشید:</p>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ((array)$row['skills'] as $x): ?><span class="fr-chip info"><?= e($x) ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ==================== فصل چهارم ==================== -->
  <?php fr_disc_chapter_head(4, 'تعامل با دیگر الگوها + تعدیل لایه‌های ضعیف + برنامهٔ هشت‌هفته'); ?>

  <div class="fr-card">
    <?php fr_disc_section_head('بخش اول', 'سبک ارتباط و گفت‌وگوی شما'); ?>
    <?php if (!empty($pDeep['communication'])) $P($pDeep['communication']); ?>

    <h4 style="font-size:14.5px;color:var(--c-primary);margin:20px 0 8px">◍ شما تحت فشار و استرس</h4>
    <?php if (!empty($pDeep['stress'])) $P($pDeep['stress']); ?>

    <h4 style="font-size:14.5px;color:var(--c-primary);margin:20px 0 8px">◍ چگونه با چهار الگوی رفتاری دیگر تعامل کنید</h4>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 10px;line-height:2">
      از زاویهٔ دید شما (با بُعد غالب <?= e($dimShort[$top]) ?>)، کلیدِ همکاریِ مؤثر با هر یک از چهار الگو:
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px">
      <?php foreach ((array)($INTER[$top] ?? []) as $od => $tip): ?>
      <div style="background:#fcfbff;border:1px solid #eee8fb;border-radius:12px;padding:12px 14px">
        <b style="font-size:12.5px;color:<?= $dimColor[$od] ?? '#5b4f8a' ?>">تعامل با الگوی <?= e($faDim[$od] ?? $od) ?></b>
        <p style="font-size:12px;line-height:2.05;color:#4a4370;margin:6px 0 0"><?= e($tip) ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <h4 style="font-size:14.5px;color:var(--c-primary);margin:24px 0 8px">◍ نقشهٔ کامل تعامل عمیق بین الگوها</h4>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:2">
      هر جفت الگو، سه لایه دارد: نقطهٔ <b style="color:#0c7a4d">هم‌افزایی</b> (کجا با هم نتیجهٔ فوق‌العاده می‌سازند)،
      <b style="color:#b3261e">کانون تنش</b> (کجا بی‌آن‌که بخواهند یکدیگر را فرسوده می‌کنند) و <b style="color:#8a5b00">نکتهٔ طلایی</b>
      (رفتاری مشخص که کل معادله را اصلاح می‌کند). این نقشه برای مدیریت تیم، همکاری دو‌نفره و حتی زندگی مشترک کاربرد دارد:
    </p>
    <?php foreach (['D','I','S','C'] as $r1): ?>
    <div style="margin-bottom:14px">
      <div style="font-size:12px;font-weight:800;color:<?= $dimColor[$r1] ?>;margin-bottom:8px">
        ● الگوی <?= e($dimShort[$r1]) ?> در برابر دیگران <?= $r1 === $top ? '<span class="fr-chip purple" style="font-size:10px">ردیفِ شما</span>' : '' ?>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(285px,1fr));gap:10px">
        <?php foreach (['D','I','S','C'] as $r2): $__cell = $IDX[$r1][$r2] ?? null; if (!$__cell) continue; ?>
        <div style="background:#fff;border:1.5px solid <?= ($r1 === $top || $r2 === $top) ? '#d8cff2' : '#eee8fb' ?>;border-radius:14px;padding:12px 14px">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
            <span style="font-size:11px;font-weight:800;color:#fff;background:<?= $dimColor[$r1] ?>;border-radius:7px;padding:2px 8px"><?= e($dimShort[$r1]) ?></span>
            <span style="color:var(--muted);font-size:11px">×</span>
            <span style="font-size:11px;font-weight:800;color:#fff;background:<?= $dimColor[$r2] ?>;border-radius:7px;padding:2px 8px"><?= e($dimShort[$r2]) ?></span>
          </div>
          <p style="font-size:11.5px;line-height:1.95;color:#29543e;margin:0 0 6px"><b style="color:#0c7a4d">هم‌افزایی:</b> <?= e((string)($__cell['harmony'] ?? '')) ?></p>
          <p style="font-size:11.5px;line-height:1.95;color:#6b3737;margin:0 0 6px"><b style="color:#b3261e">کانون تنش:</b> <?= e((string)($__cell['conflict'] ?? '')) ?></p>
          <p style="font-size:11.5px;line-height:1.95;color:#5c4a1e;margin:0"><b style="color:#8a5b00">نکتهٔ طلایی:</b> <?= e((string)($__cell['golden'] ?? '')) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="fr-card" style="background:#fcfbff">
    <?php fr_disc_section_head('بخش دوم', 'تعدیل و تقویت لایه‌های ضعیف شما'); ?>
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0 0 12px">
      در مدل DISC هیچ لایه‌ای «کمدیون» نیست که باید حذف شود؛ لایهٔ آرام‌تر شما فقط کمتر ورزش دیده است.
      برای دو لایهٔ کم‌نمایش‌تر شما (<b style="color:<?= $dimColor[$weakest[0]] ?>"><?= e($dimShort[$weakest[0]]) ?></b> و
      <b style="color:<?= $dimColor[$weakest[1]] ?>"><?= e($dimShort[$weakest[1]]) ?></b>)،
      این واژگان و جملاتِ تعدیل‌کننده را در گفت‌وگوهای روزانه به‌کار ببرید تا عضلهٔ آن لایه‌ها هم شکل بگیرد:
    </p>
    <?php
    $weakWords = [
        'D' => ['مدارا', 'صبر', 'شنیدن', 'همدلی'],
        'I' => ['دقت', 'تعهد', 'پیگیری', 'سکوتِ شنونده'],
        'S' => ['قدم‌اولیَ', 'نه‌گفتنِ محترمانه', 'بیانِ نیاز', 'پذیرشِ تغییر'],
        'C' => ['حس', 'صمیمیت', 'ریسکِ محاسبه‌شده', 'شادی'],
    ];
    foreach ($weakest as $wd): ?>
    <div style="border:1.5px dashed #d8cff2;border-radius:14px;padding:14px 16px;margin-bottom:12px;background:#fff">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">
        <b style="font-size:13.5px;color:<?= $dimColor[$wd] ?>">لایهٔ <?= e($faDim[$wd]) ?></b>
        <?php foreach ((array)($weakWords[$wd] ?? []) as $w): ?><span class="fr-chip info" style="font-size:11px"><?= e($w) ?></span><?php endforeach; ?>
      </div>
      <p style="font-size:12px;color:var(--muted);margin:0 0 8px">از این جملات — با صدای بلند یا در ذهن — در موقعیت‌های مرتبط استفاده کنید:</p>
      <ul style="font-size:13px;line-height:2.3;padding-right:20px;margin:0;color:#3a3360">
        <?php foreach ((array)($ADJ[$wd] ?? []) as $ph): ?><li><?= e($ph) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="fr-card">
    <?php fr_disc_section_head('بخش سوم', 'پیشنهادهای کاربردی در خانه و محیط کار'); ?>
    <?php if ($ctx === 'manager' && !empty($row['management'])): ?>
      <h4 style="font-size:14.5px;color:var(--c-primary);margin:6px 0 6px">◍ شما در نقش مدیر</h4>
      <?php $P($row['management']); ?>
    <?php elseif (!empty($row['family'])): ?>
      <h4 style="font-size:14.5px;color:var(--c-primary);margin:6px 0 6px">◍ شما در خانواده و روابط نزدیک</h4>
      <?php $P($row['family']); ?>
    <?php endif; ?>
    <?php if ($ctx === 'manager' && !empty($row['family'])): ?>
      <h4 style="font-size:14.5px;color:var(--c-primary);margin:16px 0 6px">◍ و در روابط نزدیک شخصی</h4>
      <?php $P($row['family']); ?>
    <?php elseif ($ctx !== 'manager' && !empty($row['management'])): ?>
      <h4 style="font-size:14.5px;color:var(--c-primary);margin:16px 0 6px">◍ و اگر تیمی را مدیریت می‌کنید</h4>
      <?php $P($row['management']); ?>
    <?php endif; ?>
  </div>

  <?php $gw = (array)($GROW[$top] ?? []); if ($gw): ?>
  <div class="fr-card" style="background:#fcfbff">
    <?php fr_disc_section_head('بخش چهارم', 'برنامهٔ هشت‌هفتهٔ تقویت الگوی شما', 'چهار فاز دوهفته‌ای — از آگاهی تا یکپارچگی'); ?>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:2">
      این برنامه بر اساس بُعد غالب شما (<?= e($dimShort[$top]) ?>) طراحی شده است؛ هر فاز فقط روی یک تمرین متمرکز است —
      بر اساس پژوهش‌های شکل‌گیری عادت، تمرکزِ تک‌هدفه شانسِ ریشه‌گرفتن تغییر را چند برابر می‌کند:
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">
      <?php foreach ($gw as [$pt, $pw, $pd]): ?>
      <div style="border:1.5px solid #eee8fb;border-radius:14px;padding:14px;background:#fff">
        <b style="font-size:12.5px;color:var(--c-primary)"><?= e($pt) ?></b>
        <span class="fr-chip info" style="font-size:10px;margin-right:6px"><?= e($pw) ?></span>
        <p style="font-size:12px;line-height:2.1;color:#4a4370;margin:8px 0 0"><?= e($pd) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ==================== فصل پنجم ==================== -->
  <?php fr_disc_chapter_head(5, 'قانون اصلی مدل + چهار گروه رفتاری + اولویت‌ها و سبک‌های فرعی'); ?>

  <div class="fr-card" style="background:#fcfbff">
    <?php fr_disc_section_head('بخش اول', 'مدل DISC چیست؟'); ?>
    <?php foreach ((array)fr_disc_model_texts() as $k => $txt): if (!is_string($txt) || $txt === '') continue; $P($txt); endforeach; ?>

    <h4 style="font-size:14.5px;color:var(--c-primary);margin:18px 0 8px">◍ ریشه‌های واژگانی چهار بُعد</h4>
    <ul style="font-size:12.5px;line-height:2.15;padding-right:20px;margin:0;color:#3a3360">
      <?php foreach (['D','I','S','C'] as $d): if (empty($LATIN[$d])) continue; ?>
      <li><b style="color:<?= $dimColor[$d] ?>"><?= e($faDim[$d]) ?>:</b> <?= e($LATIN[$d]) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="fr-card">
    <?php fr_disc_section_head('بخش دوم', 'اولویت‌های کاری هر بُعد در چارچوب Everything DiSC'); ?>
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0 0 12px">
      در چارچوب جهانی Everything DiSC، هر بُعد چند «اولویت» دارد — حوزه‌هایی که افراد به‌صورت طبیعی به آن‌ها انرژی و توجه می‌دهند.
      با دانستن اولویت‌های بُعدهای برجسته‌تان می‌فهمید چرا بعضی کارها برایتان جذاب و بعضی‌ها خسته‌کننده است:
    </p>
    <?php foreach ($dims as $d): $items = (array)($PRIO[$d] ?? []); if (!$items) continue; ?>
    <h4 style="font-size:14px;color:<?= $dimColor[$d] ?>;margin:16px 0 8px">◍ بُعد <?= e($faDim[$d]) ?> <?= $d === $top ? '<span class="fr-chip purple" style="font-size:10px">غالب شما</span>' : '' ?></h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px">
      <?php foreach ($items as $it): if (!is_array($it)) continue; ?>
      <div style="background:#fcfbff;border:1px solid #f0ecfa;border-radius:12px;padding:10px 12px">
        <b style="font-size:12.5px"><?= e((string)($it['icon'] ?? '')) ?> <?= e((string)($it['title'] ?? '')) ?></b>
        <p style="font-size:11.5px;line-height:1.95;color:#525d7e;margin:5px 0 0"><?= e((string)($it['desc'] ?? '')) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="fr-card" style="background:#fcfbff">
    <?php fr_disc_section_head('بخش سوم', 'دوازده سبک فرعی Everything DiSC'); ?>
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0 0 12px">
      در نسخهٔ جدید جهانی این مدل، جایگاهِ هر نفر روی دایرهٔ DISC با یکی از ۱۲ سبک فرعی مشخص می‌شود.
      سبک شما — بر اساس دو بُعد قوی‌تر و فاصلهٔ آن‌ها — <b style="color:var(--c-primary)"><bdi dir="ltr"><?= e($substyleKey) ?></bdi></b> تشخیص داده شده است:
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px">
      <?php foreach ($SUBST as $code => $phrases): $mine = ($code === $substyleKey); ?>
      <div style="border-radius:12px;padding:10px 12px;<?= $mine
          ? 'background:linear-gradient(135deg,#f3efff,#e9e2fb);border:1.5px solid var(--c-primary,#7c3aed)'
          : 'background:#fff;border:1px solid #eee8fb' ?>">
        <b style="font-size:12.5px"><bdi dir="ltr"><?= e($code) ?></bdi><?= $mine ? ' ⭐' : '' ?></b>
        <p style="font-size:11px;line-height:1.9;color:#525d7e;margin:5px 0 0"><?= e(implode(' · ', (array)$phrases)) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ==================== فصل ششم ==================== -->
  <?php fr_disc_chapter_head(6, 'مسیرهای شغلی هم‌راستا + اوقات فراغت سازگار با خوی'); ?>

  <div class="fr-card">
    <?php fr_disc_section_head('بخش اول', 'مشاغل پیشنهادی متناسب با الگوی رفتاری شما'); ?>
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:0 0 12px">
      مشاغل زیر بر اساس الگوی «<?= e($row['name'] ?? $pattern) ?>» و بدون درنظرگرفتن آزمون رغبت و سایر عوامل معرفی شده‌اند تا
      دیدی کلی از مسیری که در آن کمترین اصطکاک را با خویِ رفتاری‌تان دارید بدهد.
      برای انتخاب شغلِ دقیق، نتیجهٔ این گزارش را کنار آزمون رغبت (علایق شغلی هالند) و ویژگی‌های فردی‌تان بگذارید
      و در صورت نیاز با مشاور مسیر شغلی مطالعه کنید:
    </p>
    <?php if (!empty($row['jobs'])): ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach ((array)$row['jobs'] as $j): ?><span class="fr-chip ok" style="font-size:12px;padding:6px 14px"><?= e($j) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php $lei = fr_disc_leisure($top); if ($lei): ?>
    <h4 style="font-size:14.5px;color:var(--c-primary);margin:22px 0 6px">◍ یاورهای شما در اوقات فراغت</h4>
    <p style="font-size:13px;line-height:2.1;color:#3a3360;margin:0"><?= e($lei) ?></p>
    <?php endif; ?>

    <div style="background:#fafbfd;border:1.5px dashed #dbe3ea;border-radius:14px;padding:14px 16px;margin-top:18px">
      <b style="font-size:13px;color:#52606d">🧭 قدمِ بعدی در مسیر خودشناسی شما</b>
      <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:6px 0 0">
        این گزارش نقشهٔ الگوی <i>رفتاری</i> شماست. تصویرِ کامل شخصیت وقتی روشن می‌شود که آن را کنار نقشه‌های دیگر
        بگذارید: آزمون پنج‌عاملیِ شخصیت (NEO) برای عمقِ روانی، آزمون رغبت شغلی برای مسیرِ حرفه‌ای، و گفت‌وگوی تخصصی با مشاور
        برای ترکیبِ همهٔ این‌ها با داستانِ زندگیِ شما. از سایت ما می‌توانید جلسهٔ بازخورد اختصاصی DISC رزرو کنید.
      </p>
    </div>
  </div>
    <?php
}
