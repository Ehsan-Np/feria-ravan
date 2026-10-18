<?php
declare(strict_types=1);

require_once __DIR__ . '/../feria-ravan/includes/db.php';
require_once __DIR__ . '/../feria-ravan/includes/functions.php';
require_once __DIR__ . '/../feria-ravan/includes/security.php';
require_once __DIR__ . '/../feria-ravan/includes/view.php';
require_once __DIR__ . '/../feria-ravan/includes/consult.php';
require_once __DIR__ . '/../feria-ravan/includes/planner.php';

echo "════════════════════════════════════════════════════════════════\n";
echo " QA SUITE: BARCODE FALLBACK, DIGIT NORMALIZATION & JALALI ENGINE\n";
echo "════════════════════════════════════════════════════════════════\n\n";

$passes = 0;
$total = 0;

function assertTest(string $desc, bool $condition, string $detail = '') {
    global $passes, $total;
    $total++;
    if ($condition) {
        $passes++;
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc} — {$detail}\n";
    }
}

// 1. Check fr_pdate and jdate_en
assertTest("fr_pdate exists and returns non-empty Persian date string",
    function_exists('fr_pdate') && !empty(fr_pdate('Y/m/d')),
    "fr_pdate: " . (function_exists('fr_pdate') ? fr_pdate('Y/m/d') : 'undefined')
);

assertTest("jdate_en returns English digits",
    function_exists('jdate_en') && preg_match('/^\d{4}\/\d{2}\/\d{2}$/', jdate_en('Y/m/d')),
    "jdate_en: " . (function_exists('jdate_en') ? jdate_en('Y/m/d') : 'undefined')
);

// 2. Test digit normalization
assertTest("fr_normalize_chart_digits converts Persian/Arabic numbers to Latin integers/floats",
    fr_normalize_chart_digits('۸۰') === 80 &&
    fr_normalize_chart_digits('۱۲.۵') === 12.5 &&
    fr_normalize_chart_digits(['score' => '۹۵', 'val' => '٠.٥']) === ['score' => 95, 'val' => 0.5],
    "Normalized output test failed"
);

// 3. Test barcode39_svg fallback with Feria.ir caption
$bcSvg = barcode39_svg('FR-1234', 44);
assertTest("barcode39_svg generates valid SVG barcode",
    str_contains($bcSvg, '<svg') && str_contains($bcSvg, '</svg>') && str_contains($bcSvg, 'FR-1234'),
    "SVG barcode failed"
);

// 4. Test j2g and g2j integrity for Jalali 1405
[$gy, $gm, $gd] = j2g(1405, 7, 27);
[$jy, $jm, $jd] = g2j($gy, $gm, $gd);
assertTest("Jalali roundtrip: 1405/07/27 -> Gregorian -> Jalali matches 1405/07/27",
    $jy === 1405 && $jm === 7 && $jd === 27,
    "Roundtrip got: {$jy}/{$jm}/{$jd}"
);

// 5. Test jalali_to_mysql
$mysqlDate = jalali_to_mysql('1405/07/27');
assertTest("jalali_to_mysql correctly converts '1405/07/27' to Gregorian date",
    $mysqlDate === sprintf('%04d-%02d-%02d', $gy, $gm, $gd),
    "Got: {$mysqlDate}"
);

// 6. Test fr_planner_valid_dt with time only and full date
$dt1 = fr_planner_valid_dt('14:30');
$dt2 = fr_planner_valid_dt('1405/07/27 14:30');
assertTest("fr_planner_valid_dt converts Shamsi datetime '1405/07/27 14:30'",
    $dt2 !== null && str_starts_with($dt2, sprintf('%04d-%02d-%02d 14:30', $gy, $gm, $gd)),
    "Got: " . ($dt2 ?? 'null')
);

// 7. Test fr_valid_time
assertTest("fr_valid_time normalizes Persian digits '۱۶:۳۰' to '16:30'",
    fr_valid_time('۱۶:۳۰') === '16:30',
    "Got: " . fr_valid_time('۱۶:۳۰')
);

// 8. Check that no type="date" or type="time" exists in planner or consult schedule
$plannerCode = file_get_contents(__DIR__ . '/../feria-ravan/panel/planner.php');
$schedCode = file_get_contents(__DIR__ . '/../feria-ravan/admin/consult-schedule.php');

assertTest("panel/planner.php has NO native type='datetime-local' or type='date' or type='time'",
    !str_contains($plannerCode, 'type="datetime-local"') &&
    !str_contains($plannerCode, 'type="date"') &&
    !str_contains($plannerCode, 'type="time"'),
    "Native browser pickers still found in planner"
);

assertTest("admin/consult-schedule.php uses data-t24 and data-jdate for schedule inputs",
    str_contains($schedCode, 'data-t24') && str_contains($schedCode, 'data-jdate'),
    "Schedule inputs missing data-t24 or data-jdate"
);

// 9. Test assets/js/feria.js syntax and enNum presence
$jsCode = file_get_contents(__DIR__ . '/../feria-ravan/assets/js/feria.js');
assertTest("assets/js/feria.js exposes window.enNum and binds 24-hour and jdate normalizers",
    str_contains($jsCode, 'window.enNum') &&
    str_contains($jsCode, 'initT24') &&
    str_contains($jsCode, 'initJDateNormalizer') &&
    str_contains($jsCode, 'initFormDigitNormalizer'),
    "JavaScript normalizers missing"
);

// 10. Test admin/consult-bookings.php has Shamsi date range filter and amount calculations
$bookingsCode = file_get_contents(__DIR__ . '/../feria-ravan/admin/consult-bookings.php');
assertTest("admin/consult-bookings.php has Shamsi date filter and total revenue calculation",
    str_contains($bookingsCode, 'jalali_to_mysql') &&
    str_contains($bookingsCode, 'totalPaid') &&
    str_contains($bookingsCode, 'data-jdate'),
    "Bookings view missing date filter or amount calculation"
);

// 11. Test consult.php resume logic uses g2j so jy is integer 1405 (not 0)
$consultCode = file_get_contents(__DIR__ . '/../feria-ravan/consult.php');
assertTest("consult.php resume logic uses g2j and does NOT cast (int)jdate('Y')",
    str_contains($consultCode, 'g2j((int)date(\'Y\', $ts)') &&
    !str_contains($consultCode, '(int)jdate(\'Y\', $ts)'),
    "consult.php still casts (int)jdate"
);

// 12. Test report-pdf.php guarded settings and barcode fallback
$reportPdfCode = file_get_contents(__DIR__ . '/../feria-ravan/panel/report-pdf.php');
assertTest("panel/report-pdf.php contains barcode39_svg fallback with Feria.ir caption and guarded settings",
    str_contains($reportPdfCode, 'barcode39_svg($repCode') &&
    str_contains($reportPdfCode, 'Feria.ir') &&
    str_contains($reportPdfCode, 'report_qr_show') &&
    str_contains($reportPdfCode, 'report_qr_link'),
    "report-pdf.php missing barcode fallback or guarded settings"
);

echo "\n────────────────────────────────────────────────────────────────\n";
echo " RESULTS: {$passes} / {$total} TESTS PASSED (" . round($passes / $total * 100) . "%)\n";
echo "────────────────────────────────────────────────────────────────\n";

if ($passes === $total) {
    exit(0);
} else {
    exit(1);
}
