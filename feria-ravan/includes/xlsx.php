<?php
/**
 * ============================================================================
 *  خواندن فایل‌های جدولی ساده (نسخهٔ ۱٫۶) — واردکردن تعطیلات از اکسل/CSV
 *  ─ CSV: جداکنندهٔ «،» یا «؛» — ستون اول تاریخ شمسی، ستون دوم عنوان
 *  ─ XLSX: پارسر مینیمال با ZipArchive + SimpleXML (ورق اول؛ ستون‌های A و B)
 *  ─ اگر فرمت پشتیبانی نشود، پیام فارسیِ شفاف برمی‌گردد — هرگز Fatal نمی‌دهد
 * ============================================================================
 */
declare(strict_types=1);

/**
 * استخراج ردیف‌های [تاریخ, عنوان] از فایل آپلودی CSV یا XLSX
 * خروجی: ['ok'=>bool, 'rows'=>array<int,array{0:string,1:string}>, 'error'=>?string]
 */
function fr_table_upload_rows(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        return ['ok'=>false,'rows'=>[],'error'=>'فایلی انتخاب نشده است.'];
    if (($file['size'] ?? 0) > 2_000_000)
        return ['ok'=>false,'rows'=>[],'error'=>'حجم فایل باید حداکثر ۲ مگابایت باشد.'];

    $tmp  = (string)$file['tmp_name'];
    $name = strtolower((string)($file['name'] ?? ''));
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $head = @file_get_contents($tmp, false, null, 0, 4);

    // ۱) XLSX واقعی (امضای ZIP)
    if ($head !== false && str_starts_with($head, 'PK') ) {
        return $ext === 'xls'
            ? ['ok'=>false,'rows'=>[],'error'=>'فرمت قدیمی «.xls» پشتیبانی نمی‌شود؛ فایل را در اکسل «Save as» با فرمت xlsx یا CSV ذخیره کنید.']
            : fr_xlsx_rows($tmp);
    }
    // ۲) CSV/متن (با پسوند csv/txt یا هر چیز متنی)
    if (in_array($ext, ['csv','txt','xlsx'], true) || !preg_match('//u', (string)$head)) {
        return fr_csv_rows(file_get_contents($tmp) ?: '');
    }
    return ['ok'=>false,'rows'=>[],'error'=>'فرمت فایل ناشناخته است؛ فایل CSV یا XLSX بارگذاری کنید.'];
}

/* ---------------------------------------------------------------------------
 |  CSV — انعطاف‌پذیر نسبت به BOM، جداکنندهٔ ویرگول/نقطه‌ویرگول و کروکیشن
 * -------------------------------------------------------------------------*/
function fr_csv_rows(string $content): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // حذف BOM
    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $content); rewind($fh);
    $first = true;
    $delimiter = (strpos($content, ';') !== false && strpos($content, ',') === false) ? ';' : ',';
    while (($c = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        $date  = trim((string)($c[0] ?? ''));
        $title = trim((string)($c[1] ?? ''));
        if ($first && $date !== '' && !preg_match('/\d{4}/u', en_num($date))) { $first = false; continue; } // سربرگ
        $first = false;
        if ($date === '') continue;
        $rows[] = [$date, $title];
        if (count($rows) >= 2000) break; // سقف امنیتی
    }
    fclose($fh);
    if (!$rows) return ['ok'=>false,'rows'=>[],'error'=>'هیچ ردیفی با «تاریخ» خوانده نشد؛ ستون اول باید تاریخ شمسی باشد (مثل ۱۴۰۵/۰۷/۰۱).'];
    return ['ok'=>true,'rows'=>$rows,'error'=>null];
}

/* ---------------------------------------------------------------------------
 |  XLSX مینیمال — ورق اول، ستون A → تاریخ، ستون B → عنوان
 |  رشته‌ها از sharedStrings و اعداد/تاریخ‌ها به‌صورت متن خام خوانده می‌شوند
 * -------------------------------------------------------------------------*/
function fr_xlsx_rows(string $file): array
{
    if (!class_exists('ZipArchive'))
        return ['ok'=>false,'rows'=>[],'error'=>'افزونهٔ Zip روی سرور فعال نیست؛ از فایل CSV استفاده کنید.'];
    $zip = new ZipArchive();
    if (@$zip->open($file) !== true)
        return ['ok'=>false,'rows'=>[],'error'=>'فایل اکسل قابل باز شدن نیست.'];

    // رشته‌های مشترک
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = @simplexml_load_string($xml);
        if ($sx) {
            foreach ($sx->xpath('//*[local-name()="si"]') ?: [] as $si) {
                $txt = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) $txt .= (string)$t;
                $shared[] = $txt;
            }
        }
    }

    // نخستین ورق موجود
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheetXml = $zip->getFromName($n); break; }
        }
    }
    if ($sheetXml === false) { $zip->close(); return ['ok'=>false,'rows'=>[],'error'=>'ورقی در فایل اکسل پیدا نشد.']; }

    $sx = @simplexml_load_string($sheetXml);
    $zip->close();
    if (!$sx) return ['ok'=>false,'rows'=>[],'error'=>'ساختار ورق اکسل خوانا نیست؛ فایل را با CSV ذخیره کنید.'];

    $rows = [];
    $first = true;
    foreach ($sx->xpath('//*[local-name()="row"]') ?: [] as $row) {
        $vals = []; // 'A' => value, 'B' => value
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $c) {
            $ref  = (string)($c['r'] ?? '');
            $colL = strtoupper(preg_replace('/\d/', '', $ref));
            if (!in_array($colL, ['A','B'], true)) continue;
            $t = (string)($c['t'] ?? '');
            $v = '';
            if ($t === 'inlineStr') {
                foreach ($c->xpath('.//*[local-name()="t"]') ?: [] as $tt) $v .= (string)$tt;
            } else {
                $v = (string)($c->xpath('./*[local-name()="v"]')[0] ?? '');
                if ($t === 's' && $v !== '' && isset($shared[(int)$v])) $v = $shared[(int)$v];
                elseif ($t === 'str') { /* رشتهٔ فرمولی — همان مقدار */ }
            }
            $vals[$colL] = trim($v);
        }
        $date  = $vals['A'] ?? '';
        $title = $vals['B'] ?? '';
        // تاریخ‌های عددی اکسل (مثل 45852) → تبدیل به میلادی و سپس شمسی نمی‌کنیم؛
        // از کاربر می‌خواهیم تاریخ را «متنی» وارد کند. ردیف عددیِ بزرگ را رد می‌کنیم.
        if ($first && $date !== '' && !preg_match('/\d{4}/u', en_num($date))) { $first = false; continue; }
        $first = false;
        if ($date === '') continue;
        if (preg_match('/^\d{5}(\.\d+)?$/', $date)) continue; // عدد سریال تاریخ اکسل
        $rows[] = [$date, $title];
        if (count($rows) >= 2000) break;
    }
    if (!$rows) return ['ok'=>false,'rows'=>[],'error'=>'در ستون A تاریخ شمسی پیدا نشد (مثل ۱۴۰۵/۰۷/۰۱). اگر تاریخ را به‌صورت «تاریخ اکسل» وارد کرده‌اید، قالب سلول را Text کنید و دوباره ذخیره نمایید.'];
    return ['ok'=>true,'rows'=>$rows,'error'=>null];
}
