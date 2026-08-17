<?php
/**
 * ============================================================================
 *  بذر تحلیل‌های عمیق نسخهٔ ویژه (فریا روان ۲٫۹)
 *  ─ برای هر آزمون فعال: مونوگراف بالینی بانددار هر مقیاس + تحلیل نمرهٔ کلی
 *    + فصل پایانی تخصصی — همه از پایگاه دانش core/analysis-deep/<engine>.php
 *  ─ کاملاً idempotent: هر بار اجرا، قالب‌ها به‌روز/ایجاد می‌شوند (بدون افزونگی)
 *  ─ فقط سطح full (نسخهٔ ویژه) را بازنویسی می‌کند؛ قالب‌های free دست نمی‌خورند
 *  ─ منابع: Young 2003؛ Marston 1928؛ Costa & McCrae 1992؛ Spitzer GAD-7 2006؛
 *    Kroenke PHQ-9 2001؛ Spector JSS 1985؛ Gardner 1983؛ Schutte 1998؛ Deci & Ryan
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/analysis-deep.php';

/** بدنهٔ deep-band بدون پاراگراف باند (برای قالب‌های band='any' غربالگرها) */
function fr_deep_any_body(string $key, string $code, string $ctx): ?array
{
    $kb = fr_deep_kb($key);
    $sc = $kb['scales'][$code] ?? null;
    if (!$sc) return null;
    $fa   = (string)($sc['fa'] ?? $code);
    $mgr  = $ctx === 'manager' ? (string)($sc['mgr'] ?? '') : '';
    $fam  = $ctx === 'family'  ? (string)($sc['fam'] ?? '') : '';
    $body  = '<p><b>شناخت علمی سازه:</b> ' . (string)$sc['def'] . '</p>';
    if (!empty($sc['mech']))   $body .= '<p><b>سازوکار روان‌شناختی:</b> ' . (string)$sc['mech'] . '</p>';
    if (!empty($sc['manif']))  $body .= '<div class="deep-manif"><b>تظاهرات بالینی و روزمره:</b>' . (string)$sc['manif'] . '</div>';
    if ($mgr !== '') $body .= '<p class="deep-ctx"><b>🔹 در نقش مدیریت و رهبری:</b> ' . $mgr . '</p>';
    if ($fam !== '') $body .= '<p class="deep-ctx"><b>🔹 در زندگی خانوادگی و زناشویی:</b> ' . $fam . '</p>';
    if (!empty($sc['interv'])) $body .= '<div class="deep-interv"><b>راهنمای مداخلهٔ شواهدمحور:</b>' . (string)$sc['interv'] . '</div>';
    $body .= '<p class="deep-ref">📚 <i>' . (string)($sc['ref'] ?? '') . '</i></p>';
    return ['title' => 'تحلیل بالینیِ موشکافانهٔ «' . $fa . '»', 'body' => $body];
}

/** درج یا به‌روزرسانی یک قالب تحلیل (فریا — جدول analysis_templates) */
function fr_deep_upsert_tpl(int $testId, ?string $scale, string $band, string $title, string $body, int $sort): string
{
    $T = DB::t('analysis_templates');
    if ($scale === null) {
        $row = DB::one("SELECT id, body FROM $T WHERE test_id=? AND scale_code IS NULL AND band=? AND level='full' LIMIT 1", [$testId, $band]);
    } else {
        $row = DB::one("SELECT id, body FROM $T WHERE test_id=? AND scale_code=? AND band=? AND level='full' LIMIT 1", [$testId, $scale, $band]);
    }
    if ($row) {
        DB::run("UPDATE $T SET title=?, body=?, sort_order=? WHERE id=?", [$title, $body, $sort, (int)$row['id']]);
        return 'updated';
    }
    DB::run("INSERT INTO $T (test_id, scale_code, band, level, title, body, sort_order) VALUES (?,?,?,'full',?,?,?)",
        [$testId, $scale, $band, $title, $body, $sort]);
    return 'inserted';
}

/**
 * اجرای کامل بذر تحلیل‌های عمیق برای همهٔ آزمون‌های فعال.
 * @return array آمار اجرا (برای لاگ/تست)
 */
function fr_seed_deep_analyses(?callable $log = null): array
{
    $logFn = $log ?: function (string $m): void {};
    $stats = ['tests' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];

    $tests = DB::all("SELECT id, slug, audience, status FROM " . DB::t('tests') . " WHERE status='active' ORDER BY id");
    $Tsc   = DB::t('test_scales');
    $bandW = ['very_low' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'very_high' => 5, 'any' => 6, 'overall' => 7];

    foreach ($tests as $t) {
        $slug = (string)$t['slug'];
        $key  = fr_deep_key_for_slug($slug);
        if ($key === '') { $stats['skipped']++; continue; }
        $kb = fr_deep_kb($key);
        if (empty($kb['scales'])) { $stats['skipped']++; continue; }

        /* هم‌تراز با includes/report.php: مدیر→مدیر / بقیه→خانواده */
        $ctx = ($t['audience'] ?? 'general') === 'manager' ? 'manager' : 'family';
        $tid = (int)$t['id'];
        $stats['tests']++;

        $scales = DB::all("SELECT code FROM $Tsc WHERE test_id=? ORDER BY sort_order, id", [$tid]);
        $hasOverall = false;

        foreach ($scales as $s) {
            $code = (string)$s['code'];
            if ($code === 'overall') { $hasOverall = true; continue; }

            /* ۱) قالب‌های بانددار عمیق هر مقیاس (۵ باند) */
            foreach (FR_DEEP_BANDS as $band) {
                $r = fr_deep_scale($key, $code, $band, $ctx);
                if (!$r) continue;
                $res = fr_deep_upsert_tpl($tid, $code, $band, $r['title'], $r['body'], 10 + ($bandW[$band] ?? 9));
                $stats[$res]++;
            }

            /* ۲) قالب مرجع band='any' (سازگاری با غربالگرها و جست‌وجوی سه‌مرحله‌ای report) */
            $any = fr_deep_any_body($key, $code, $ctx);
            if ($any) {
                $res = fr_deep_upsert_tpl($tid, $code, 'any', $any['title'], $any['body'], 16);
                $stats[$res]++;
            }
        }

        /* ۳) قالب‌های بانددار «نمرهٔ کلی» (فقط آزمون‌های دارای مقیاس overall) */
        if ($hasOverall) {
            foreach (FR_DEEP_BANDS as $band) {
                $r = fr_deep_overall($key, $band, $ctx);
                if (!$r) continue;
                /* اگر بدنهٔ قبلی اختصاصی و غنی بود، حفظش می‌کنیم (پیوستِ پایین) — یک‌بار */
                $T = DB::t('analysis_templates');
                $old = DB::one("SELECT body FROM $T WHERE test_id=? AND scale_code='overall' AND band=? AND level='full' LIMIT 1", [$tid, $band]);
                $body = '<div class="deep29-overall">' . $r['body'] . '</div>';
                if ($old && !str_contains((string)$old['body'], 'deep29-overall')) {
                    $legacy = str_replace('\n', "\n", (string)$old['body']);
                    if (mb_strlen(strip_tags($legacy)) > 900) {
                        $body .= '<details class="deep29-legacy" style="margin-top:10px;border-top:1px solid #e5e1f7;padding-top:8px">'
                              .  '<summary style="cursor:pointer;color:#5b54a6;font-size:12.5px">📖 مبانی علمی تفصیلی این سطح (مرجع آموزشی کامل)</summary>'
                              .  '<div style="margin-top:8px">' . $legacy . '</div></details>';
                    }
                }
                $res = fr_deep_upsert_tpl($tid, 'overall', $band, $r['title'], $body, 20 + ($bandW[$band] ?? 9));
                $stats[$res]++;
            }
        }

        /* ۴) فصل پایانی تخصصی (قالب بدون مقیاس — *|overall) */
        $fin = fr_deep_finale($key, $ctx);
        if ($fin) {
            $res = fr_deep_upsert_tpl($tid, null, 'overall', $fin['title'], $fin['body'], 90);
            $stats[$res]++;
        }

        $logFn("deep-seed: $slug ✔");
    }

    /* نشانگر نسخهٔ بذر — برای رهگیری و QA */
    DB::setSetting('deep_analyses_v', '2.9.0');
    $logFn("deep-seed: done — tests={$stats['tests']} inserted={$stats['inserted']} updated={$stats['updated']} skipped={$stats['skipped']}");
    return $stats;
}
