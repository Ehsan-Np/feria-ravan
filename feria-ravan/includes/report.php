<?php
/**
 * ============================================================================
 *  گزارش‌ساز فریا روان — نسخهٔ ۲٫۶
 *  ─ free: نمودار + جدول بُعدها + تحلیل خلاصه + پیشنهاد نسخهٔ ویژه
 *  ─ full: DISC → گزارش کامل شش‌فصلی حرفه‌ای (core/disc-deep.php):
 *            نمودارها/سگمنت ۱–۷ + دو چهرهٔ طبیعی/تطبیق‌یافته + شاخص جابه‌جایی
 *            + ۳۲ تمایل دوقطبی + ۱۵ الگوی جهانی + هفت شاخص هر بُعد + سه سبک
 *            تصمیم‌گیری + تعدیل لایه‌ها + برنامهٔ هشت‌هفته + ۱۲ سبک Everything
 *            DiSC + مشاغل — حدود ۳۰ صفحه در نسخهٔ چاپی
 *          فصول اختصاصی mslikert (گاردنر: سه‌خانواده/برجسته‌ها/فرصت‌رشد |
 *            شوت: مقایسهٔ هنجاری/توازن عوامل/برنامهٔ سه‌هفته‌ای)
 *          + واکاوی ترکیبی کلینیکی در بافت ایران برای همهٔ موتورها (report-deep)
 *          + زنجیرهٔ هوشمند قالب (باند دقیق ← باند تقلیل‌یافته ← any)
 *  ─ اولویت متن: قالب دستی مدیر (DB) ← بانک متون تخصصی ← متن خودکار
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/Analyzer.php';
require_once __DIR__ . '/../core/report-texts.php';
require_once __DIR__ . '/../core/report-deep.php';
require_once __DIR__ . '/../core/analysis-deep.php';
require_once __DIR__ . '/../core/disc-deep.php';

final class ReportBuilder
{
    /** نسخهٔ ۲٫۰ — حالت چاپ: نمودارهای مخصوص چاپ (خطی برای DISC / میله‌ای افقی برای بقیه) */
    private static bool $printMode = false;

    /** نسخهٔ ۴٫۵٫۰ — دسترسی ادمین برای نمایش پروتکل‌های محرمانه مداخله بالینی */
    private static bool $isAdmin = false;

    public static function isAdmin(): bool
    {
        return self::$isAdmin;
    }

    /** کارت اعتباری توسعه‌دهنده — متن/لینک از تنظیمات (footer_credit_text / footer_credit_url) */
    public static function creditBox(): string
    {
        return '<div style="border:1.5px dashed #c9bef7;border-radius:14px;padding:14px 18px;margin-top:26px;'
             . 'font-size:12.5px;color:#6b658e;background:#fafbfd;text-align:center" class="fr-credit">'
             . fr_credit_html() . '</div>';
    }

    /** برچسب فارسی باندها */
    private static array $bandFa = [
        'very_low'=>'خیلی پایین','low'=>'پایین','medium'=>'متوسط','high'=>'بالا','very_high'=>'خیلی بالا',
    ];
    private static array $bandColor = [
        'very_low'=>'#3aa0ff','low'=>'#19a974','medium'=>'#a49ec7','high'=>'#f5a623','very_high'=>'#e0393f',
    ];

    /** برچسب/رنگ ویژهٔ غربالگرهای بالینی (موتور screen) */
    private static function bandMaps(string $engine): array
    {
        if ($engine === 'screen') {
            return [
                ['very_low'=>'حداقل','low'=>'خفیف','medium'=>'متوسط','high'=>'متوسط رو به شدید','very_high'=>'شدید'],
                ['very_low'=>'#10b981','low'=>'#3b82f6','medium'=>'#f59e0b','high'=>'#d97706','very_high'=>'#ef4444'],
            ];
        }
        if ($engine === 'jobsat') {
            return [
                ['very_low'=>'پایین','low'=>'پایین','medium'=>'متوسط','high'=>'بالا','very_high'=>'بالا'],
                ['very_low'=>'#ef4444','low'=>'#f59e0b','medium'=>'#f5a623','high'=>'#10b981','very_high'=>'#10b981'],
            ];
        }
        return [self::$bandFa, self::$bandColor];
    }

    /** متن خودکار اضطراری وقتی نه قالب دستی هست نه بانک متنی */
    private static function fallbackText(array $r): string
    {
        $t = e($r['title_fa'] ?? $r['scale_code']);
        return match ($r['band']) {
            'very_low' => "در بُعد «{$t}» نمرهٔ کمی آورده‌اید؛ یعنی این ویژگی در رفتار روزمره‌تان کمتر خودش را نشان می‌دهد.",
            'low'      => "نمرهٔ «{$t}» شما پایین‌تر از میانگین است و تأثیر محدودی روی الگوی کلی شما دارد.",
            'medium'   => "نمرهٔ «{$t}» شما متعادل است؛ بسته به موقعیت هر دو سوی این ویژگی را از خودتان می‌بینید.",
            'high'     => "نمرهٔ «{$t}» شما بالاست و این بُعد یکی از رنگ‌های اصلی شخصیت شماست.",
            'very_high'=> "نمرهٔ «{$t}» شما خیلی بالاست؛ این بُعد در شما برجسته و غالب است.",
            default    => '',
        };
    }

    /** رندر یک بلوک تحلیل مقیاس (با اولویت: قالب مدیر ← تحلیل عمیق روان‌سنجی ← بانک متن ← خودکار)
     *  ۱٫۸ و ۳٫۵: زنجیرهٔ انتخاب قالب — قالب دستی مدیر ← تحلیل تخصصی چندوجهی بانددار ← متن خودکار */
    private static function scaleBlock(array $r, array $tplMap, string $engine, string $slug,
                                       string $ctx, bool $isFull, string $bandFa, string $bandColor): void
    {
        $code = $r['scale_code'];
        $collapse = ['very_low' => 'low', 'very_high' => 'high'];
        $tpl = $tplMap[$code . '|' . $r['band']][0]
            ?? (isset($collapse[$r['band']]) ? ($tplMap[$code . '|' . $collapse[$r['band']]][0] ?? null) : null)
            ?? ($tplMap[$code . '|any'][0] ?? null);

        // واکشی تحلیل تخصصی و عمیق روان‌سنجی بر مبنای باند دقیق نمره
        $spec = function_exists('fr_get_specialized_scale_analysis')
            ? fr_get_specialized_scale_analysis($engine, $slug, $code, (string)$r['band'], $ctx, $isFull, $r)
            : null;

        if ($tpl && !empty($tpl['body'])) {
            $body = $tpl['body'];
            $title = $tpl['title'] ?? ('تحلیل بُعد «' . ($r['title_fa'] ?? $code) . '»');
        } elseif ($spec && !empty($spec['body'])) {
            $body = $spec['body'];
            $title = $spec['title'] ?? ('تحلیل تخصصی «' . ($r['title_fa'] ?? $code) . '» — وضعیت: ' . $bandFa);
        } else {
            $auto = $isFull ? fr_text_full($engine, $slug, $r['scale_code'], (string)$r['band'], $ctx)
                            : fr_text_brief($engine, $slug, $r['scale_code'], (string)$r['band']);
            $body = $auto ?: ('<p>' . self::fallbackText($r) . '</p>');
            $title = 'تحلیل بُعد «' . ($r['title_fa'] ?? $code) . '»';
        }

        // تأمین و الحاق راهنمای مداخله بالینی منحصراً برای مدیران سامانه (در صورت عدم وجود در قالب باند)
        if ($isFull && self::$isAdmin && !str_contains($body, 'deep-interv')) {
            $anyTpl = $tplMap[$code . '|any'][0] ?? null;
            if ($anyTpl && !empty($anyTpl['body'])) {
                $extr = self::extractDeepInterv($anyTpl['body']);
                if ($extr) $body .= $extr;
            } elseif ($spec && !empty($spec['body'])) {
                $extr = self::extractDeepInterv($spec['body']);
                if ($extr) $body .= $extr;
            }
        }

        // فیلترسازی و پایش پروتکل‌های محرمانه مداخله بالینی بر مبنای سطح دسترسی
        if (function_exists('fr_filter_clinical_interventions')) {
            $body = fr_filter_clinical_interventions($body, self::$isAdmin);
        }
        ?>
    <div style="border-right:3px solid <?= e($r['color'] ?: 'var(--c-primary)') ?>;padding-right:14px;margin-bottom:22px">
      <b style="font-size:15px"><?= e($title) ?></b>
      <div style="font-size:12.5px;color:var(--muted);margin-bottom:8px">
        نمره: <?= fa_num($r['percent']) ?>٪ — وضعیت: <b style="color:<?= $bandColor ?>"><?= $bandFa ?></b>
        <?php if (!empty($r['t_score'])): ?>
          &nbsp;·&nbsp; نمرهٔ تراز T: <b style="direction:ltr;display:inline-block"><?= fa_num($r['t_score']) ?></b>
          &nbsp;·&nbsp; استن: <b><?= fa_num($r['sten_score']) ?>/۱۰</b>
          &nbsp;·&nbsp; رتبه درصدی جامعه: <b><?= fa_num($r['percentile']) ?>٪</b>
        <?php endif; ?>
      </div>
      <div style="font-size:13.5px;line-height:2.15;color:#3a3360"><?= $body ?></div>
    </div>
        <?php
    }

    /**
     * رندر فصول مخصوص DISC در نسخهٔ ویژه
     * نسخهٔ ۲٫۶: گزارش کامل شش‌فصلیِ حرفه‌ای (core/disc-deep.php) با فهرست فصول،
     * سگمنت‌های ۱–۷، شاخص ابعاد، تطابق دو نمودار، ۱۵ الگوی جهانی، سه سبک تصمیم‌گیری،
     * تعدیل لایه‌های ضعیف، اولویت‌ها و ۱۲ سبک Everything DiSC — حدود ۳۰ صفحهٔ چاپ.
     */
    private static function discChapters(array $attempt, array $test, array $results): void
    {
        fr_disc_deep_chapters($attempt, $test, $results, self::$printMode);
    }

    /** خواندن الگو و کد ۴ رقمی — نسخهٔ ۲٫۶: همیشه از نتایج بازمحاسبه می‌شود تا
     *  تلاش‌های قدیمی (که با باگ تشخیص الگو «DI» ذخیره شده‌اند) هم هنگام نمایش اصلاح شوند */
    public static function discPattern(array $attempt, array $combined): array
    {
        $pattern = ''; $code4 = '';
        require_once __DIR__ . '/../core/analysis-texts.php';
        if ($combined) {
            fr_txt('FERIA_DISC_PATTERNS');   // اطمینان از تعریف توابع محاسباتی (۲٫۶)
            if (function_exists('feria_disc_detect_pattern')) {
                $pattern = feria_disc_detect_pattern($combined);
                if ($pattern === 'BA') { $s = $combined; arsort($s); $pattern = implode('', array_slice(array_keys($s), 0, 2)); }
            }
            if (function_exists('feria_disc_pattern_code')) $code4 = feria_disc_pattern_code($combined);
        }
        if ($pattern === '' && preg_match('/pattern:([A-Z]{1,2})/', (string)$attempt['context'], $m)) $pattern = $m[1];
        if ($code4 === ''  && preg_match('/code:(\d{4})/', (string)$attempt['context'], $m)) $code4 = $m[1];
        return [$pattern, $code4];
    }

    /**
     * ساخت HTML گزارش
     * @param string $level  free|full
     */
    /**
     * ساخت HTML گزارش
     * @param array $opts نسخهٔ ۲٫۰: ['print'=>true] → نمودار مخصوص چاپ + بدون کنواس‌های نمایشی
     */
    public static function build(array $attempt, array $user, ?string $level = 'full', array $opts = []): string
    {
        $level = ($level === 'free') ? 'free' : 'full';
        $attempt['mode'] = $level;
        self::$printMode = !empty($opts['print']);
        self::$isAdmin   = isset($opts['isAdmin']) ? (bool)$opts['isAdmin'] : (bool)(function_exists('current_admin') && current_admin());
        $results = Analyzer::getResults((int)$attempt['id']);
        if (!$results && ($attempt['status'] ?? '') === 'completed') {
            Analyzer::scoreAttempt((int)$attempt['id']);
            $results = Analyzer::getResults((int)$attempt['id']);
        }
        if (!$results) return '<div class="fr-alert err">نتایج یافت نشد.</div>';

        $test  = DB::one('SELECT * FROM ' . DB::t('tests') . ' WHERE id=?', [$attempt['test_id']])
               ?: ['id' => (int)($attempt['test_id'] ?? 0), 'title' => 'آزمون روان‌شناختی', 'engine' => 'disc', 'slug' => '', 'audience' => 'general'];
        $site  = fr_brand_title();
        $isFull = $level === 'full';
        $engine = (string)($test['engine'] ?? '');
        $slug   = (string)($test['slug'] ?? '');
        $ctx    = ($test['audience'] ?? 'general') === 'manager' ? 'manager' : 'family';

        /* قالب‌های دستی مدیر (در اولویت) */
        $tplRows = DB::all('SELECT * FROM ' . DB::t('analysis_templates') .
                           ' WHERE test_id=? AND level=? ORDER BY sort_order', [$attempt['test_id'], $level]);
        $tplMap = [];
        foreach ($tplRows as $t) { $tplMap[($t['scale_code'] ?? '*') . '|' . $t['band']][] = $t; }

        $sorted = $results;
        usort($sorted, fn($a,$b) => $b['percent'] <=> $a['percent']);
        [$bf, $bc] = self::bandMaps($engine);

        $chart = array_map(fn($r) => ['label' => $r['scale_code'],
            'value' => (float)$r['percent'], 'color' => $r['color'] ?: '#1e3a6e'], $results);

        $answered = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$attempt['id']]);
        $totalQ   = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1', [$attempt['test_id']]);

        ob_start();
        ?>
<div class="fr-report">
  <!-- سربرگ گزارش -->
  <div class="fr-card" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px">
    <div>
      <h3 style="margin-bottom:6px"><?= e($test['title']) ?>
        <span class="fr-chip <?= $isFull?'purple':'info' ?>"><?= $isFull?'تحلیل ویژه و کامل':'تحلیل رایگان' ?></span></h3>
      <div style="font-size:13px;color:var(--muted)">
        تست‌دهنده: <b><?= e(user_fullname($user)) ?></b> · موبایل: <?= fa_num((string)($user['mobile'] ?? '')) ?><br>
        تاریخ تست روانشناسی: <?= fa_num(jdate('l j F Y ساعت H:i', strtotime($attempt['completed_at'] ?: ($attempt['started_at'] ?? '')) ?: time())) ?><br>
        کد پیگیری گزارش: <b style="direction:ltr"><?= e($attempt['rep_code'] ?? ('FR-' . $attempt['id'])) ?></b>
      </div>
    </div>
    <div style="text-align:center">
      <span style="font-size:34px">🧠</span><br><small style="color:var(--muted)"><?= e($site) ?></small>
    </div>
  </div>

  <?php
  $valJson = json_decode((string)($attempt['validity_json'] ?? ''), true);
  if ($valJson):
      $vScore = (int)($valJson['score'] ?? 100);
      $vStatus = (string)($valJson['status'] ?? 'high');
      $vColor = match($vStatus) { 'high' => '#166534', 'acceptable' => '#854d0e', default => '#991b1b' };
      $vBg = match($vStatus) { 'high' => '#f0fdf4', 'acceptable' => '#fefce8', default => '#fef2f2' };
      $vBorder = match($vStatus) { 'high' => '#86efac', 'acceptable' => '#fde047', default => '#fca5a5' };
      $durSec = (int)($valJson['duration_sec'] ?? 0);
      $durText = $durSec > 0 ? (floor($durSec / 60) . ' دقیقه و ' . ($durSec % 60) . ' ثانیه') : 'ثبت‌شده';
  ?>
  <!-- کارت سنجش اعتبار روان‌سنجی آزمون (فاز ۱) -->
  <div class="fr-card" style="background:<?= $vBg ?>;border:1px solid <?= $vBorder ?>;padding:16px 20px;margin-bottom:18px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:24px"><?= $vStatus === 'high' ? '🛡️' : ($vStatus === 'acceptable' ? '⚖️' : '⚠️') ?></span>
        <div>
          <b style="color:<?= $vColor ?>;font-size:14px"><?= e($valJson['status_fa'] ?? 'اعتبار روان‌سنجی استاندارد') ?></b>
          <div style="font-size:12px;color:<?= $vColor ?>;opacity:.85;margin-top:2px">
            شاخص پایایی پاسخ‌ها: <b><?= fa_num($vScore) ?>٪</b> &nbsp;·&nbsp;
            مدت‌زمان پاسخ‌دهی: <b><?= fa_num($durText) ?></b>
            <?php if (!empty($valJson['sec_per_q'])): ?>
              (میانگین <?= fa_num($valJson['sec_per_q']) ?> ثانیه برای هر سؤال)
            <?php endif; ?>
          </div>
        </div>
      </div>
      <span style="font-size:11.5px;background:rgba(255,255,255,.8);border:1px solid <?= $vBorder ?>;color:<?= $vColor ?>;padding:4px 10px;border-radius:12px;font-weight:600">
        پروتکل استاندارد روان‌سنجی فریا
      </span>
    </div>
    <?php if (!empty($valJson['flags'])): ?>
      <div style="margin-top:10px;padding-top:10px;border-top:1px dashed <?= $vBorder ?>;font-size:12px;color:<?= $vColor ?>">
        <?php foreach ($valJson['flags'] as $vf): ?>
          <div>• <b><?= e($vf['title']) ?>:</b> <?= e($vf['desc']) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($engine === 'disc'): ?>
    <?php if ($isFull) self::discChapters($attempt, $test, $results); else self::discFreePattern($attempt, $results); ?>
  <?php endif; ?>

  <!-- نمودار کلی -->
  <div class="fr-card">
    <h3><?= self::$printMode && $engine === 'disc' ? 'نمودار خطی نتایج DISC' : 'نمودار نتایج' ?></h3>
    <?php if (self::$printMode):
        /* ── نسخهٔ ۲٫۰ — نمودار مخصوص چاپ: DISC = نمودار خطی | بقیه = میله‌ای افقی ── */
        if ($engine === 'disc') {
            $g = Analyzer::discGraphData((int)$attempt['id']);
            $lbl = ['D' => 'D — غلبه', 'I' => 'I — نفوذ', 'S' => 'S — ثبات', 'C' => 'C — درستی'];
            $prChart = ['type' => 'line', 'labels' => array_values($lbl), 'series' => [
                ['name' => 'تطبیق‌یافته (بیشترین)', 'color' => '#7c3aed',
                 'values' => array_map(fn($d) => (float)($g['most'][$d] ?? 0), array_keys($lbl))],
                ['name' => 'طبیعی (کمترین)', 'color' => '#94a3b8',
                 'values' => array_map(fn($d) => (float)($g['least'][$d] ?? 0), array_keys($lbl))],
                ['name' => 'تلفیقی', 'color' => '#f59e0b',
                 'values' => array_map(fn($d) => (float)($g['combined'][$d] ?? 0), array_keys($lbl))],
            ]];
        } else {
            $prChart = ['type' => 'hbars', 'bars' => array_map(fn($r) => [
                'label' => (string)($r['title_fa'] ?? $r['scale_code']),
                'value' => (float)$r['percent'],
                'color' => $r['color'] ?: '#1e3a6e',
                'band'  => $bf[$r['band']] ?? '',
            ], $results)];
        }
    ?>
    <canvas class="pr-canvas" data-pr='<?= e(json_encode($prChart, JSON_UNESCAPED_UNICODE)) ?>'
            style="height:260px"></canvas>
    <div class="pr-legend" data-pr-legend></div>
    <?php else: ?>
    <canvas class="fr-chart" data-chart='<?= e(json_encode($chart, JSON_UNESCAPED_UNICODE)) ?>' data-opts='{"height":230}' style="height:230px"></canvas>
    <?php endif; ?>
    <div style="margin-top:18px">
      <?php foreach ($results as $r): ?>
      <div class="fr-scalebar">
        <span class="lbl"><?= e($r['title_fa'] ?? $r['scale_code']) ?></span>
        <span class="track"><span class="fill" data-w="<?= (float)$r['percent'] ?>%"
              style="width:0;background:<?= e($r['color'] ?: '#1e3a6e') ?>"></span></span>
        <span class="val"><?= fa_num($r['percent']) ?>٪ · <b style="color:<?= $bc[$r['band']] ?? '#888' ?>"><?= $bf[$r['band']] ?? '' ?></b></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- تحلیل‌ها -->
  <div class="fr-card">
    <h3><?= $isFull ? 'تحلیل تخصصی ابعاد' : 'تحلیل نتایج شما' ?></h3>
    <?php
    usort($sorted, function ($a, $b) {
        if ($a['scale_code'] === 'overall') return -1;
        if ($b['scale_code'] === 'overall') return 1;
        return $b['percent'] <=> $a['percent'];
    });
    $show = $isFull ? $sorted : array_slice($sorted, 0, max(1, min(3, count($sorted))));
    foreach ($show as $r) {
        self::scaleBlock($r, $tplMap, $engine, $slug, $ctx, $isFull, $bf[$r['band']] ?? '', $bc[$r['band']] ?? '#888');
    }
    ?>

    <?php if (!$isFull): ?>
    <!-- تیزر نسخهٔ ویژه — متن تشویقیِ قابل ویرایش برای هر تست روانشناسی -->
    <div style="background:linear-gradient(135deg,#f6f3ff,#efeafd);border-radius:14px;padding:22px;text-align:center;border:1.5px dashed #cfc3f5">
      <?php
      $teaser = trim((string)($test['free_teaser'] ?? ''));
      if ($teaser === '') {
          $teaser = '🔒 ' . fa_num(max(0, count($sorted) - count($show))) . ' بُعد دیگر هنوز از شما حرف نزده‌اند! '
              . ($engine === 'disc'
                  ? 'در نسخهٔ ویژه، گزارش کامل شش‌فصلی شما (بیش از ۳۰ صفحهٔ تحلیل تخصصی DISC) آماده می‌شود: دو نمودار طبیعی و تطبیق‌یافته با تحلیل تطابق، ۳۲ شاخص تمایل رفتاری، هفت شاخص هر بُعد، جایگاه‌تان میان ۱۵ الگوی جهانی، سه سبک تصمیم‌گیری، تعدیل لایه‌های ضعیف، برنامه‌ی هشت‌هفته‌ی رشد و مشاغل پیشنهادی — منطبق با استاندارد گزارش‌های مرجع جهانی.'
                  : 'در نسخهٔ ویژه، تحلیل عمیق و کامل همهٔ ابعاد، نمودارهای تخصصی، نقشهٔ رشد قدم‌به‌قدم '
                    . 'و گزارش کاملِ قابل چاپ در اختیار شماست. ') . 'همین حالا ارتقا بدهید و تصویر کامل خودتان را ببینید.';
      }
      ?>
      <p style="font-size:13.5px;line-height:2.2;color:#4a3f7a;margin:0"><?= nl2br(e($teaser)) ?></p>
      <?php $rDeal = fr_price_deal((int)$test['price_full'], isset($test['price_sale']) ? (int)$test['price_sale'] : null); ?>
      <?php if ($rDeal['final'] > 0): ?>
      <a class="fr-btn" style="margin-top:14px" href="test-start.php?id=<?= (int)$test['id'] ?>&upgrade_attempt=<?= (int)$attempt['id'] ?>">⭐ دریافت نسخهٔ ویژه — <?php if ($rDeal['has_sale']): ?><del style="opacity:.75;font-weight:400"><?= toman($rDeal['list']) ?></del> <?php endif; ?><?= toman($rDeal['final']) ?><?= $rDeal['has_sale'] ? ' (' . fa_num($rDeal['percent']) . '٪ تخفیف)' : '' ?></a>
      <?php endif; ?>
    </div>
    <?php else:
        foreach ($tplMap['*|overall'] ?? [] as $t) {
            echo '<h2 style="font-size:16px;margin-top:26px">' . e($t['title'] ?? 'جمع‌بندی تخصصی') . '</h2>'
               . '<div style="font-size:13.5px;line-height:2.15;color:#3a3360">' . $t['body'] . '</div>';
        }
    endif; ?>
  </div>

  <?php if ($isFull): ?>
    <?php if ($engine === 'mslikert'): self::mseqChapters($slug, $sorted, $test); endif; ?>
    <?php self::fullExtras($engine, $slug, $sorted); ?>
    <?php if (function_exists('fr_deep_synthesis')) fr_deep_synthesis($engine, $slug, $sorted, $attempt); ?>
  <?php endif; ?>

  <?php if ($isFull): ?>
  <!-- شفافیت روش — برای اعتماد کاربر تست‌دهنده -->
  <div class="fr-card" style="background:#fafbfc;border:1px dashed #dbe3ea">
    <h3>این گزارش چگونه ساخته شد؟</h3>
    <p style="font-size:12.5px;line-height:2.1;color:#52606d;margin:0">
      این تحلیل بر اساس <b><?= fa_num($answered) ?> پاسخ</b> شما به <?= fa_num($totalQ) ?> سؤال پرسش‌نامه محاسبه شده است
      و با روش استاندارد نمره‌گذاری همین ابزار (<?= e(self::engineName($engine, $slug)) ?>) ساخته شده است.
      نمره‌ها «درصد نسبی» هستند و تفاوت چند درصدی بین ابعاد، معنای بالینی بزرگی ندارد.
      این گزارش یک ابزار خودشناسی است و جایگزین تشخیص یا درمان توسط متخصص نیست؛
      اگر بخشی از نتایج شما را آشوب کرد، مطالعهٔ آن با روانشناس یا مشاور خود بهترین قدم بعدی است.
    </p>
  </div>
  <div class="fr-card">
    <h3>قدم‌های بعدی شما</h3>
    <ul style="font-size:13px;line-height:2.2;color:#3a3360;padding-right:20px;margin:0">
      <li>گزارش را در آرامش بخوانید و جاهایی که بیشتر «خودتان» بودید علامت بزنید.</li>
      <li>یک نفر نزدیک که بهتان اعتماد دارید، خواندن گزارش را با شما همراه کنید.</li>
      <li>برای کار عمیق‌تر روی نقاط رشد، از کارگاه‌ها، دوره‌ها و جلسات مشاورهٔ ما استفاده کنید.</li>
      <li>پیشنهاد می‌کنیم هر ۶ ماه تست روانشناسی را تکرار و تغییراتتان را پایش کنید.</li>
    </ul>
  </div>

  <!-- بخش برنامهٔ رشد پویا در پلنر روزانه (فاز ۳) -->
  <div class="fr-card" style="background:#f5f3ff;border:1.5px solid #c4b5fd;margin-top:20px;border-radius:14px;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
      <div style="flex:1;min-width:280px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
          <span style="font-size:24px">🎯</span>
          <h3 style="margin:0;font-size:16px;color:#5b21b6">برنامهٔ عمل و تمرین‌های رشد اختصاصی در برنامهٔ روزانه (پلنر)</h3>
        </div>
        <p style="font-size:13px;color:#4c1d95;margin:0;line-height:2">
          می‌توانید تکالیف رفتاری و تمرین‌های روان‌شناختی متناسب با نقاط قوت و بهبود این کارنامه را به پلنر روزانهٔ خود اضافه کنید تا با یادآورهای خودکار پیگیری شوند.
        </p>
      </div>
      <div>
        <form method="post" action="<?= fr_panel_url('report.php?attempt=' . (int)$attempt['id']) ?>" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="inject_growth">
          <button class="fr-btn" style="background:#7c3aed">
            <?= !empty($attempt['growth_injected']) ? '🔄 به‌روزرسانی تمرین‌ها در پلنر روزانه ←' : '➕ افزودن تمرین‌های رشد به پلنر من ←' ?>
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($ft = DB::setting('report_footer', '')): ?>
  <div style="font-size:12.5px;color:var(--muted);text-align:center;line-height:1.9"><?= nl2br(e((string)$ft)) ?></div>
  <?php endif; ?>
  <div style="text-align:center;margin:28px 0 16px">
    <button type="button" class="fr-btn ghost sm" onclick="window.scrollTo({top:0,behavior:'smooth'})" style="border-radius:99px;padding:8px 20px">
      ↑ بازگشت به بالای کارنامه
    </button>
  </div>
  <?= self::creditBox() ?>
</div>
        <?php
        $html = (string)ob_get_clean();
        if (function_exists('fr_filter_clinical_interventions')) {
            $html = fr_filter_clinical_interventions($html, self::$isAdmin);
        }
        return $html;
    }

    /**
     * استخراج ایمن بلوک مداخله بالینی با شمارش متوازن تگ‌های باز و بسته
     */
    public static function extractDeepInterv(string $html): ?string
    {
        $pos = stripos($html, 'deep-interv');
        if ($pos === false) return null;
        $start = strrpos(substr($html, 0, $pos), '<div');
        if ($start === false) return null;
        $depth = 0;
        $i = $start;
        $len = strlen($html);
        while ($i < $len) {
            if (substr($html, $i, 4) === '<div') {
                $depth++;
                $i += 4;
            } elseif (substr($html, $i, 6) === '</div>') {
                $depth--;
                $i += 6;
                if ($depth <= 0) {
                    return substr($html, $start, $i - $start);
                }
            } else {
                $i++;
            }
        }
        return null;
    }

    /** نام فارسی موتور برای کادر شفافیت (۱٫۸: تشخیص خانوادهٔ mslikert بر اساس اسلاگ) */
    private static function engineName(string $e, string $slug = ''): string
    {
        if ($e === 'mslikert') {
            return str_starts_with($slug, 'gardner')
                ? 'نظریهٔ هوش‌های چندگانه گاردنر (سنجهٔ ۹۰آیتمی هم‌خانوادهٔ مک‌کنزی)'
                : 'مقیاس هوش هیجانی شوت (SSEIT — Schutte et al., 1998)';
        }
        return ['disc'=>'مدل رفتاری DISC مرستن','neo'=>'مدل پنج عامل بزرگ شخصیت (NEO — Costa & McCrae)',
                'ysq'=>'درمان‌گرایی طرحواره (Young)','screen'=>'غربالگری بالینی (هم‌راستا با BAI/BDI-II)',
                'jobsat'=>'مقیاس رضایت شغلی اسپکتور (JSS)', 'mbti'=>'تیپ‌شناسی شخصیت مایرز-بریگز (MBTI)', 'holland'=>'مدل رغبت شغلی هالند (RIASEC)', 'baron'=>'پرسشنامه هوش هیجانی بار-آن (Bar-On EQ-i)', 'mmpi'=>'پرسشنامه چندوجهی شخصیتی مینه سوتا (MMPI)', 'raven'=>'ماتریس‌های پیش‌رونده ریون (Raven IQ)', 'wais'=>'شاخص هوش وکسلر (WAIS Index)'][$e] ?? $e;
    }

    /* =========================================================================
     | ۱٫۸ — فصول اختصاصی تست‌های روانشناسی mslikert در نسخهٔ ویژه
     |  گاردنر: نقشهٔ سه‌خانواده + هوش‌های برجسته + هوش‌های فرصت‌رشد
     |  شوت: مقایسهٔ هنجاری + توازن عوامل + برنامهٔ تمرینی سه‌هفته‌ای
     * ======================================================================*/
    private static function mseqChapters(string $slug, array $sorted, array $test): void
    {
        require_once __DIR__ . '/../core/mseq-data.php';

        $rows = [];                                   // code => row (بدون overall)
        $overall = null;
        foreach ($sorted as $r) {
            if ($r['scale_code'] === 'overall') { $overall = $r; continue; }
            $rows[$r['scale_code']] = $r;
        }
        if (!$rows) return;
        $icons = fr_mseq_icons();
        $byPct = $rows;
        uasort($byPct, fn($a, $b) => (float)$b['percent'] <=> (float)$a['percent']);
        $codesDesc = array_keys($byPct);

        /* --------------------------------------------------------------------
         | گاردنر — خانواده‌های سه‌گانه + برجسته‌ها + فرصت‌های رشد
         * ------------------------------------------------------------------*/
        if (fr_mseq_family($slug) === 'mi') {
            $families = fr_mi_families();
            $careers  = fr_mi_careers();
            $shadows  = fr_mi_shadows();
            /* میانگین هر خانواده */
            $famStat = [];
            foreach ($families as $fk => $f) {
                $sum = 0; $n = 0;
                foreach ($f['scales'] as $c) { if (isset($rows[$c])) { $sum += (float)$rows[$c]['percent']; $n++; } }
                $famStat[$fk] = $n ? round($sum / $n, 1) : 0;
            }
            arsort($famStat);
            ?>
  <!-- نقشهٔ سه خانوادهٔ هوش -->
  <div class="fr-card" style="background:linear-gradient(135deg,#faf8ff,#f4f0ff)">
    <h3>🧭 نقشهٔ کلی — سه خانوادهٔ هوش‌های شما</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;line-height:2">
      گاردنر نه هوش را در سه خانوادهٔ بزرگ می‌شناسد؛ برتریِ یک خانواده یعنی ذهن شما از آن «دروازه» جهان را می‌فهمد:
    </p>
    <?php foreach ($famStat as $fk => $avg): $f = $families[$fk]; ?>
    <div class="fr-scalebar">
      <span class="lbl" style="min-width:120px">🧬 <?= e($f['title']) ?></span>
      <span class="track"><span class="fill" data-w="<?= (float)$avg ?>%" style="width:0;background:linear-gradient(90deg,#1e3a6e,#3e5f9e)"></span></span>
      <span class="val"><?= fa_num($avg) ?>٪</span>
    </div>
    <p style="font-size:12px;color:#525d7e;margin:2px 0 12px;line-height:1.9"><?= e($f['desc']) ?></p>
    <?php endforeach; ?>
    <p style="font-size:12px;color:var(--muted);border-top:1px dashed #e6e1f7;padding-top:12px;margin:8px 0 0">
      📌 در نظریهٔ گاردنر هیچ پروفایلی «بهتر» از دیگری نیست؛ هدف این تست روانشناسی شناختِ نقشه است نه رتبه‌بندی. هر هوش مانند عضله، با تمرینِ هدفمند رشد می‌کند.
    </p>
  </div>

  <!-- هوش‌های برجسته -->
  <div class="fr-card">
    <h3>🏆 سه هوش برجستهٔ شما — سرمایه‌های آمادهٔ به‌کارگیری</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px">
      این‌ها کانال‌های طبیعی یادگیری و عملکرد شما هستند؛ مسیری که با کمترین اصطکاک، بیشترین بازده را می‌دهد:
    </p>
    <?php foreach (array_slice($codesDesc, 0, 3) as $i => $c): $r = $rows[$c]; ?>
    <div style="border:1.5px solid #eee8fb;border-radius:16px;padding:16px 18px;margin-bottom:12px;background:#fcfbff">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span style="width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;
                     background:linear-gradient(135deg,<?= e($r['color'] ?: '#1e3a6e') ?>22,#fff);border:1.5px solid <?= e($r['color'] ?: '#1e3a6e') ?>44"><?= $icons[$c] ?? '🧠' ?></span>
        <div style="flex:1;min-width:180px">
          <b style="font-size:14.5px"><?= fa_num($i + 1) ?>. <?= e($r['title_fa'] ?? $c) ?></b>
          <div class="fr-scalebar" style="margin:6px 0 0">
            <span class="track"><span class="fill" data-w="<?= (float)$r['percent'] ?>%" style="width:0;background:<?= e($r['color'] ?: '#1e3a6e') ?>"></span></span>
            <span class="val"><?= fa_num($r['percent']) ?>٪</span>
          </div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:12px">
        <div style="background:#f0fdf6;border-radius:12px;padding:12px 14px">
          <b style="font-size:12px;color:#0c7a4d">🎯 سبک یادگیری پیشنهادی برای شما</b>
          <p style="font-size:12px;line-height:2;color:#29543e;margin:6px 0 0"><?= e(fr_mi_learning_style($c)) ?></p>
        </div>
        <div style="background:#fff7f7;border-radius:12px;padding:12px 14px">
          <b style="font-size:12px;color:#b3261e">🌗 سایه‌ای که باید مراقبش باشید</b>
          <p style="font-size:12px;line-height:2;color:#6b3737;margin:6px 0 0"><?= e($shadows[$c] ?? '') ?></p>
        </div>
      </div>
      <div style="margin-top:12px">
        <b style="font-size:12px;color:var(--c-primary)">💼 مشاغل و مسیرهای هم‌راستا در ایران:</b>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
          <?php foreach (array_slice((array)($careers[$c] ?? []), 0, 6) as $job): ?>
          <span class="fr-chip info" style="font-size:11px"><?= e($job) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- هوش‌های فرصت‌رشد -->
  <div class="fr-card">
    <h3>🦢 دو هوش فرصت‌رشد — نقشهٔ پرورشِ هوشمندانه</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;line-height:2">
      نمرهٔ پایین‌تر در این دو هوش «عیب» نیست؛ فقط یعنی کانال‌های یادگیریِ دیگر برایتان طبیعی‌تر است.
      توصیهٔ علمی: رشد این هوش‌ها را <b>از طریق هوش‌های برجسته‌تان</b> شروع کنید، نه با درس‌خواندنِ رسمی آن‌ها:
    </p>
    <?php foreach (array_slice($codesDesc, -2) as $c): $r = $rows[$c]; ?>
    <div style="border:1.5px dashed #d8cff2;border-radius:14px;padding:14px 16px;margin-bottom:10px;background:#fafbfd">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:20px"><?= $icons[$c] ?? '🧠' ?></span>
        <b style="font-size:13.5px"><?= e($r['title_fa'] ?? $c) ?></b>
        <span class="val" style="margin-right:auto;font-size:12.5px"><?= fa_num($r['percent']) ?>٪</span>
      </div>
      <p style="font-size:12.5px;line-height:2;color:#4a4370;margin:8px 0 0">
        <b>مسیر رشد پیشنهادی:</b> <?= e(fr_mi_learning_style($c)) ?> —
        با اتصال آن به هوش برجستهٔ «<?= e($rows[$codesDesc[0]]['title_fa'] ?? $codesDesc[0]) ?>»، تمرین را لذت‌بخش و پایدار کنید.
      </p>
    </div>
    <?php endforeach; ?>
  </div>
            <?php
        }

        /* --------------------------------------------------------------------
         | هوش هیجانی شوت — مقایسهٔ هنجاری + توازن + برنامهٔ تمرین
         * ------------------------------------------------------------------*/
        if (fr_mseq_family($slug) === 'eq') {
            $rawTotal = 0; foreach ($rows as $r) { $rawTotal += (float)$r['raw_score']; }
            $fa = ['E_P' => 'ادراک هیجان', 'E_M' => 'مدیریت هیجان خویشتن', 'E_O' => 'مدیریت هیجان دیگران', 'E_U' => 'بهره‌گیری از هیجان'];
            $zone = $rawTotal >= 137 ? 'بالاتر از محدودهٔ متعارف 🌟' : ($rawTotal <= 111 ? 'پایین‌تر از محدودهٔ متعارف' : 'در محدودهٔ متعارف');
            $zoneColor = $rawTotal >= 137 ? '#19a974' : ($rawTotal <= 111 ? '#e0393f' : '#1e3a6e');
            $gauge = min(100, max(0, round($rawTotal / 165 * 100, 1)));
            $top2 = array_slice($codesDesc, 0, 2);
            $insight = count($top2) === 2 ? fr_eq_balance_insight($top2[0], $top2[1]) : '';
            $programs = fr_eq_programs();
            /* برنامه‌ها از ضعیف‌ترین عامل شروع شوند */
            $asc = $rows; uasort($asc, fn($a, $b) => (float)$a['percent'] <=> (float)$b['percent']);
            ?>
  <!-- مقایسهٔ هنجاری -->
  <div class="fr-card" style="background:linear-gradient(135deg,#faf8ff,#f4f0ff)">
    <h3 style="justify-content:center;text-align:center">📏 نمرهٔ کلی شما در برابر هنجارهای پژوهشی</h3>
    <div style="text-align:center;margin:10px 0 6px">
      <b style="font-size:34px;color:<?= e($zoneColor) ?>"><bdi dir="ltr"><?= fa_num(round($rawTotal)) ?></bdi></b>
      <span style="color:var(--muted);font-size:13px">از <bdi dir="ltr"><?= fa_num(165) ?></bdi></span>
      <div style="font-size:13px;color:<?= e($zoneColor) ?>;margin-top:4px"><?= e($zone) ?></div>
    </div>
    <div class="fr-scalebar" style="max-width:520px;margin:10px auto 0">
      <span class="track" style="position:relative">
        <span class="fill" data-w="<?= $gauge ?>%" style="width:0;background:linear-gradient(90deg,#1e3a6e,#3e5f9e)"></span>
      </span>
    </div>
    <p style="font-size:12.5px;line-height:2.1;color:#4a4370;margin:12px 0 0"><?= e(fr_eq_norm_note()) ?></p>
  </div>

  <!-- توازن عامل‌ها -->
  <div class="fr-card">
    <h3>⚖️ توازن بین چهار عامل هوش هیجانی شما</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:2">
      دو عامل برجستهٔ شما: <b><?= e($fa[$top2[0]] ?? $top2[0]) ?></b> و <b><?= e($fa[$top2[1]] ?? $top2[1]) ?></b>
    </p>
    <?php if ($insight): ?>
    <div style="background:#f0fdf6;border-radius:14px;padding:14px 16px">
      <b style="font-size:13px;color:#0c7a4d">💚 خوانش ترکیب دو عامل برجسته</b>
      <p style="font-size:13px;line-height:2.1;color:#29543e;margin:6px 0 0"><?= e($insight) ?></p>
    </div>
    <?php endif; ?>
    <?php $weak = array_key_first($asc); ?>
    <div style="background:#fff7f7;border-radius:14px;padding:14px 16px;margin-top:12px">
      <b style="font-size:13px;color:#b3261e">🌗 نقطهٔ حساس شما: <?= e($fa[$weak] ?? $weak) ?></b>
      <p style="font-size:13px;line-height:2.1;color:#6b3737;margin:6px 0 0">
        این عامل نسبت به بقیه پایین‌تر است و رشدش بزرگ‌ترین جهش را در نمرهٔ کلی شما می‌سازد.
        برنامهٔ تمرینی آن را از همین هفته شروع کنید — پژوهش‌ها نشان می‌دهد هوش هیجانی پس از چند هفته تمرینِ هدفمند به‌طور معنادار پیشرفت می‌کند.
      </p>
    </div>
  </div>

  <!-- برنامهٔ تمرینی سه‌هفته‌ای -->
  <div class="fr-card">
    <h3>🏋️ برنامهٔ تمرینی سه‌هفته‌ای هر عامل</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;line-height:2">
      برنامه‌ها از ضعیف‌ترین عامل شما مرتب شده‌اند؛ هر هفته فقط روی همان یک تمرین متمرکز شوید:
    </p>
    <?php foreach ($asc as $c => $r): if (!isset($programs[$c])) continue; ?>
    <div style="border:1.5px solid #eee8fb;border-radius:16px;padding:14px 16px;margin-bottom:12px;background:#fcfbff">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:20px"><?= $icons[$c] ?? '💗' ?></span>
        <b style="font-size:14px"><?= e($r['title_fa'] ?? ($fa[$c] ?? $c)) ?></b>
        <span class="fr-chip <?= (float)$r['percent'] >= 60 ? 'ok' : ((float)$r['percent'] >= 40 ? 'info' : 'err') ?>" style="font-size:11px;margin-right:auto"><?= fa_num($r['percent']) ?>٪</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;margin-top:12px">
        <?php foreach ($programs[$c] as [$pt, $pw, $pd]): ?>
        <div style="background:#fafbfd;border:1px dashed #d8cff2;border-radius:12px;padding:12px">
          <b style="font-size:12px;color:var(--c-primary)"><?= e($pw) ?> · <?= e($pt) ?></b>
          <p style="font-size:12px;line-height:2;color:#4a4370;margin:6px 0 0"><?= e($pd) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
            <?php
        }

        /* --------------------------------------------------------------------
         | مراجع علمی — کارت پایانی برای اعتمادسازی
         * ------------------------------------------------------------------*/
        $refs = fr_mseq_references($slug);
        if ($refs):
        ?>
  <div class="fr-card" style="background:#fafbfc;border:1px dashed #dbe3ea">
    <h3>📚 مبانی و منابع علمی این گزارش</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 10px">
      تحلیل شما بر پایهٔ این پژوهش‌های معتبر بین‌المللی و فارسی ساخته شده است:
    </p>
    <ol style="font-size:11.5px;line-height:2.1;color:#52606d;padding-right:18px;margin:0;direction:ltr;text-align:left">
      <?php foreach ($refs as $ref): ?><li><?= $ref ?></li><?php endforeach; ?>
    </ol>
  </div>
        <?php
        endif;
    }

    /** موارد تکمیلی نسخهٔ ویژه برای موتورهای غیر از DISC (نقشه رشد، دامنه‌های یانگ…) */
    private static function fullExtras(string $engine, string $slug, array $sorted): void
    {
        $topCodes = array_column(array_slice($sorted, 0, 3), 'scale_code');

        // نقشهٔ رشد فازبندی برای بُعدهای برجسته
        $plans = [];
        foreach ($topCodes as $c) {
            $g = fr_text_growth($engine, $slug, $c);
            if ($g) $plans[$c] = $g;
        }
        if ($plans):
        ?>
  <div class="fr-card">
    <h3>نقشهٔ رشد قدم‌به‌قدم</h3>
    <?php foreach ($plans as $c => $phases): ?>
      <h4 style="font-size:14px;color:var(--c-primary);margin:16px 0 8px">برنامهٔ بُعد «<?= e($c) ?>»</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px">
      <?php foreach ((array)$phases as $ph): [$pt,$pw,$pd] = array_pad((array)$ph, 3, ''); ?>
        <div style="border:1px solid #e9e4f8;border-radius:12px;padding:12px;background:#fafbfd">
          <b style="font-size:12px;color:var(--c-primary)"><?= e($pt) ?> · <?= e($pw) ?></b>
          <p style="font-size:12px;line-height:2;color:#4a4370;margin:6px 0 0"><?= e($pd) ?></p>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
        <?php
        endif;

        // دامنه‌های یانگ برای نمای ساختاری
        if ($engine === 'ysq'):
            $domains = fr_ysq_domains();
            if ($domains):
            ?>
  <div class="fr-card">
    <h3>نقشهٔ طرحواره‌ها بر اساس حوزه‌ها</h3>
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px">طرحواره‌های یانگ در پنج حوزهٔ بزرگ دسته‌بندی می‌شوند؛ فعال‌بودن طرحواره‌های یک حوزه، داستان بزرگ‌تری از نیازهای برآورده‌نشده روایت می‌کند:</p>
    <?php foreach ($domains as $dTitle => $codes): ?>
      <div style="margin-bottom:12px">
        <b style="font-size:13px"><?= e($dTitle) ?></b>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
          <?php foreach ((array)$codes as $code):
            $active = in_array($code, array_column($sorted, 'scale_code'), true)
                    && in_array('high', array_map(fn($r) => $r['band'], array_filter($sorted, fn($r) => $r['scale_code'] === $code || $r['scale_code'] === 'overall' && false)), true);
            $rowR = null; foreach ($sorted as $r) if ($r['scale_code'] === $code) { $rowR = $r; break; }
            $isHi = $rowR && in_array($rowR['band'], ['high','very_high'], true); ?>
            <span class="fr-chip <?= $isHi ? 'err' : 'info' ?>" style="font-size:11px"><?= e($code) ?><?= $isHi ? ' ●' : '' ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
            <?php
            endif;
            $adv = fr_ysq_advice();
            if ($adv): ?>
  <div class="fr-card">
    <h3>توصیهٔ کلی طرحواره‌درمانی</h3>
    <div style="font-size:13.5px;line-height:2.15;color:#3a3360"><?= $adv ?></div>
  </div>
            <?php
            endif;
        endif;
    }

    /** کارت الگو در نسخهٔ رایگان (سبک — نام الگو + پیش‌نمایش) */
    private static function discFreePattern(array $attempt, array $results): void
    {
        $comb = [];
        foreach ($results as $r) $comb[$r['scale_code']] = (int)round((float)$r['percent']);
        [$pattern] = self::discPattern($attempt, $comb);
        if ($pattern === '') return;
        $row = fr_disc_pattern_row($pattern);
        ?>
  <div class="fr-card" style="text-align:center;background:linear-gradient(135deg,#faf8ff,#f4f0ff)">
    <h3 style="justify-content:center">الگوی رفتاری شما</h3>
    <div style="font-size:26px;font-weight:900;color:var(--c-primary)"><?= e($row['name'] ?? $pattern) ?></div>
    <?php if (!empty($row['tagline'])): ?><div style="font-size:13px;color:#5b4f8a;margin-top:2px"><?= e($row['tagline']) ?></div><?php endif; ?>
    <p style="color:var(--muted);font-size:12px;margin:10px 0 0;line-height:2">
      در نسخهٔ ویژه، گزارش کامل <b>شش‌فصلی</b> (بیش از ۳۰ صفحهٔ تحلیل تخصصی) را می‌بینید: نمودار طبیعی/تطبیق‌یافته با تحلیل تطابق،
      ۳۲ شاخص تمایل رفتاری، هفت شاخص هر بُعد، جایگاه‌تان میان ۱۵ الگوی جهانی، سه سبک تصمیم‌گیری،
      برنامهٔ هشت‌هفتهٔ رشد و فهرست مشاغل پیشنهادی.
    </p>
  </div>
        <?php
    }
}
