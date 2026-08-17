<?php
/**
 * ============================================================================
 *  موتور دیدگاه و امتیاز (نسخهٔ ۲٫۰) — دوره، کارگاه، تست روانشناسی
 *  ─ ثبت توسط کاربرِ شرکت‌کننده (۱ نظر در هر مورد؛ ثبت دوباره = ویرایش)
 *  ─ میانگین/شمارش عمومی، برچسب‌های قابل‌شخصی‌سازی، ورود فله از اکسل/CSV
 * ============================================================================
 */
declare(strict_types=1);

const FR_REVIEW_CTXS = ['test' => 'تست روانشناسی', 'workshop' => 'کارگاه', 'course' => 'دوره'];

/** عنوان موردِ دیدگاه برای نمایش در ادمین/لاگ */
function fr_review_item_title(string $ctx, int $refId): string
{
    try {
        if ($ctx === 'test') {
            return (string)DB::val('SELECT title FROM ' . DB::t('tests') . ' WHERE id=?', [$refId]) ?: "تست روانشناسی #$refId";
        }
        return (string)DB::val('SELECT title FROM ' . DB::t('events') . ' WHERE id=?', [$refId]) ?: "رویداد #$refId";
    } catch (Throwable $e) { return "#$refId"; }
}

/** slug/id را برای هر کانتکست به ref_id معتبر تبدیل می‌کند (برای ورود اکسل) */
function fr_review_resolve_ref(string $ctx, string $key): ?int
{
    $key = trim($key);
    if ($key === '') return null;
    try {
        if ($ctx === 'test') {
            if (ctype_digit($key) && DB::val('SELECT COUNT(*) FROM ' . DB::t('tests') . ' WHERE id=?', [(int)$key])) return (int)$key;
            $id = DB::val('SELECT id FROM ' . DB::t('tests') . ' WHERE slug=?', [$key]);
            return $id ? (int)$id : null;
        }
        $table = DB::t('events');
        if (ctype_digit($key)) {
            $id = DB::val("SELECT id FROM $table WHERE id=? AND kind=?", [(int)$key, $ctx]);
        } else {
            $id = DB::val("SELECT id FROM $table WHERE slug=? AND kind=?", [$key, $ctx]);
        }
        return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}

/** آیا کاربر حق ثبت دیدگاه دارد؟ (فقط شرکت‌کنندگان: ثبت‌نام نهایی یا تست روانشناسی انجام‌شده) */
function fr_review_participated(string $ctx, int $refId, int $userId): bool
{
    if ($userId <= 0) return false;
    if ($ctx === 'test') {
        return (bool)DB::val('SELECT COUNT(*) FROM ' . DB::t('attempts') .
            " WHERE user_id=? AND test_id=? AND status<>'in_progress'", [$userId, $refId]);
    }
    return (bool)DB::val('SELECT COUNT(*) FROM ' . DB::t('event_registrations') . ' r
        JOIN ' . DB::t('events') . ' e ON e.id=r.event_id
        WHERE r.user_id=? AND r.event_id=? AND r.status=\'paid\' AND e.kind=?', [$userId, $refId, $ctx]);
}

/** برچسب پیش‌فرض بر اساس نقش نویسنده */
function fr_review_auto_label(string $badgeAuto): array
{
    return match ($badgeAuto) {
        'admin'       => ['title' => 'مدیر سایت', 'color' => '#1e3a6e', 'bg' => '#eaf0f8'],
        'guest'       => ['title' => 'کاربر مهمان', 'color' => '#5f5a7a', 'bg' => '#f2f0fa'],
        default       => ['title' => 'شرکت‌کننده', 'color' => '#0c7a4d', 'bg' => '#e9f9f1'],
    };
}

/** فهرست لیبل‌های قابل‌انتخاب (کش‌شده) */
function fr_review_labels(): array
{
    static $c = null;
    if ($c !== null) return $c;
    try {
        $c = DB::all('SELECT * FROM ' . DB::t('review_labels') . ' ORDER BY sort_order, id');
    } catch (Throwable $e) { $c = []; }
    return $c;
}

/** برچسبِ نهایی قابل‌نمایش یک دیدگاه: label_text ← label_id ← badge_auto */
function fr_review_label(array $r): array
{
    if (trim((string)($r['label_text'] ?? '')) !== '') {
        return ['title' => trim((string)$r['label_text']), 'color' => '#3b2a7a', 'bg' => '#eaf0f8'];
    }
    if (!empty($r['label_id'])) {
        foreach (fr_review_labels() as $l) {
            if ((int)$l['id'] === (int)$r['label_id']) {
                return ['title' => $l['title'], 'color' => $l['color'], 'bg' => $l['bg']];
            }
        }
    }
    return fr_review_auto_label((string)($r['badge_auto'] ?? 'participant'));
}

/** بذر لیبل‌های پیش‌فرض (idempotent) */
function fr_review_seed_labels(): void
{
    try {
        DB::run('INSERT IGNORE INTO ' . DB::t('settings') . " (`key`,`value`) VALUES ('review_labels_seeded','0')");
        if (DB::setting('review_labels_seeded', '0') === '1') return;
        $rows = [
            ['شرکت‌کننده', '#0c7a4d', '#e9f9f1', 1],
            ['مدیر سایت', '#1e3a6e', '#eaf0f8', 2],
            ['خریدار نسخه ویژه', '#9a6b00', '#fff4d6', 3],
            ['کاربر مهمان', '#5f5a7a', '#f2f0fa', 4],
            ['همراه شرکت‌کننده', '#0b6fa4', '#e5f3fb', 5],
        ];
        foreach ($rows as $r) {
            DB::insert('review_labels', ['title' => $r[0], 'color' => $r[1], 'bg' => $r[2], 'sort_order' => $r[3]]);
        }
        DB::setSetting('review_labels_seeded', '1');
    } catch (Throwable $e) { /* جدول هنوز نیست */ }
}

/**
 * ثبت/به‌روزرسانی دیدگاه کاربر — یک دیدگاه برای هر کاربر در هر مورد
 * نسخهٔ ۲٫۲: دیدگاه/پرسش برای همهٔ کاربران واردشده باز است (حتی غیرشرکت‌کننده‌ها)؛
 * شرکت‌کننده‌ها برچسب «شرکت‌کننده» و بقیه «کاربر مهمان» می‌گیرند.
 * $kind: review (با امتیاز) | question (بدون امتیاز — مدیر می‌تواند پاسخ دهد)
 */
function fr_review_submit(string $ctx, int $refId, array $u, int $rating, string $body, string $kind = 'review'): array
{
    $kind   = $kind === 'question' ? 'question' : 'review';
    $rating = $kind === 'question' ? 0 : max(1, min(5, $rating));
    $body   = trim(preg_replace('/\s+/u', ' ', $body));
    $ctxFa  = FR_REVIEW_CTXS[$ctx] ?? 'مورد';
    if (mb_strlen($body) < 3)   return ['ok' => false, 'message' => $kind === 'question' ? 'متن پرسش خیلی کوتاه است؛ کمی بیشتر بنویسید.' : 'متن دیدگاه خیلی کوتاه است؛ کمی بیشتر بنویسید.'];
    if (mb_strlen($body) > 2000) return ['ok' => false, 'message' => 'متن نباید بیش از ۲۰۰۰ نویسه باشد.'];
    if ($kind === 'review' && $rating < 1) return ['ok' => false, 'message' => 'لطفاً امتیاز ۱ تا ۵ ستاره را هم انتخاب کنید (برای پرسش، نوع «پرسش» را بزنید).'];

    $isParticipant = fr_review_participated($ctx, $refId, (int)$u['id']);
    /* ۲٫۲: دیدگاه و پرسشِ «رویدادها» (کارگاه/دوره) برای همهٔ کاربران باز است — حتی غیرشرکت‌کنندگان؛
       برای «تست روانشناسی» ثبتِ نظر همچنان ویژهٔ شرکت‌کنندگان می‌ماند تا اعتبار میانگین امتیازها حفظ شود (پرسش برای همه آزاد است) */
    if ($ctx === 'test' && $kind === 'review' && !$isParticipant) {
        return ['ok' => false, 'message' => 'ثبت نظر برای تست‌های روانشناسی ویژهٔ شرکت‌کنندگان آن تست است؛ اگر سؤالی دارید نوع «پرسش» را انتخاب کنید.'];
    }
    $name = trim(((string)($u['first_name'] ?? '')) . ' ' . ((string)($u['last_name'] ?? ''))) ?: 'کاربر';
    $status = DB::setting('reviews_auto_approve', '1') === '1' ? 'approved' : 'pending';
    $data = [
        'author_name' => mb_substr($name, 0, 150),
        'author_mobile' => (string)($u['mobile'] ?? '') ?: null,
        'rating' => $kind === 'question' ? null : $rating,
        'kind' => $kind,
        'body' => $body,
        'badge_auto' => $isParticipant ? 'participant' : 'guest',
    ];
    $noun = $kind === 'question' ? 'پرسش' : 'دیدگاه';
    $data['status'] = $status;
    $old = DB::one('SELECT id FROM ' . DB::t('reviews') .
                   ' WHERE context=? AND ref_id=? AND user_id=?', [$ctx, $refId, (int)$u['id']]);
    if ($old) {
        DB::update('reviews', $data + ['source' => 'user'], 'id=?', [(int)$old['id']]);
        log_activity('review_update', $ctx, $refId, ['review' => (int)$old['id'], 'kind' => $kind]);
        return ['ok' => true, 'updated' => true, 'status' => $status,
                'message' => $status === 'approved' ? $noun . ' شما به‌روزرسانی و منتشر شد ✅' : $noun . ' شما به‌روزرسانی شد و پس از تأیید مدیر منتشر می‌شود.'];
    }
    $id = DB::insert('reviews', $data + [
        'context' => $ctx, 'ref_id' => $refId, 'user_id' => (int)$u['id'], 'source' => 'user',
    ]);
    log_activity('review_new', $ctx, $refId, ['review' => $id, 'rating' => $rating, 'kind' => $kind, 'participant' => $isParticipant]);
    return ['ok' => true, 'updated' => false, 'status' => $status,
            'message' => $status === 'approved'
                ? ($kind === 'question' ? 'پرسش شما منتشر شد؛ پاسخ مدیریت زیر همین‌جا نمایش داده می‌شود ✅' : 'دیدگاه شما با موفقیت منتشر شد؛ ممنون! 🌟')
                : ($noun . ' شما ثبت شد و پس از تأیید مدیر منتشر می‌شود.')];
}

/** آمار عمومی دیدگاه یک مورد: میانگین و تعداد تأییدشده (۲٫۲: فقط «نظر»ها، نه پرسش‌ها) */
function fr_review_stats(string $ctx, int $refId): array
{
    try {
        $row = DB::one('SELECT COUNT(*) AS n, COALESCE(AVG(rating),0) AS avg_r FROM ' . DB::t('reviews') .
                       " WHERE context=? AND ref_id=? AND status='approved' AND kind='review' AND rating IS NOT NULL", [$ctx, $refId]);
        return ['count' => (int)($row['n'] ?? 0), 'avg' => round((float)($row['avg_r'] ?? 0), 1)];
    } catch (Throwable $e) { return ['count' => 0, 'avg' => 0.0]; }
}

/** دیدگاه‌های تأییدشدهٔ یک مورد (جدیدترین‌ها اول) + تلفیق برچسب */
function fr_reviews_public(string $ctx, int $refId, int $limit = 12): array
{
    try {
        $rows = DB::all('SELECT * FROM ' . DB::t('reviews') .
                        " WHERE context=? AND ref_id=? AND status='approved' ORDER BY id DESC LIMIT " . max(1, $limit),
                        [$ctx, $refId]);
        foreach ($rows as &$r) $r['label'] = fr_review_label($r);
        return $rows;
    } catch (Throwable $e) { return []; }
}

/** نمایش ستاره‌ها با HTML خالص (نمره اعشاری با ستارهٔ نیمه) */
function fr_stars_html(float $rating, string $size = '14px'): string
{
    $out = '<span class="rv-stars" style="font-size:' . e($size) . '" title="' . e(fa_num($rating)) . ' از ۵">';
    for ($i = 1; $i <= 5; $i++) {
        $r = $rating - $i + 1;
        $out .= $r >= 1 ? '<i class="on">★</i>' : ($r >= 0.5 ? '<i class="half">★</i>' : '<i>★</i>');
    }
    return $out . '</span>';
}

/** نام کاربر فعلی برای پیش‌نمایش فرم دیدگاه */
function fr_review_form_name(array $u): string
{
    return trim(((string)($u['first_name'] ?? '')) . ' ' . ((string)($u['last_name'] ?? ''))) ?: 'کاربر';
}

/* ---------------------------------------------------------------------------
 |  خواندن ردیف‌های فایل ورودی دیدگاه‌ها — CSV (با تشخیص جداکننده + BOM)
 |  و XLSX (زیپ + sharedStrings؛ ستون‌های A..H) — خروجی: آرایهٔ آرایه‌های مقداری
 * -------------------------------------------------------------------------*/
function fr_import_rows(string $file, string $ext = ''): array
{
    $ext = strtolower($ext !== '' ? $ext : pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'xlsx' || $ext === 'xls') return fr_import_rows_xlsx($file);

    $raw = (string)file_get_contents($file);
    if ($raw === '') return ['ok' => false, 'rows' => [], 'error' => 'فایل خالی است.'];
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);               // BOM
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $dl  = substr_count($raw, ';') > substr_count($raw, ',') ? ';' : (str_contains($raw, "\t") ? "\t" : ',');
    $out = [];
    $fh  = fopen('php://memory', 'rb+'); fwrite($fh, $raw); rewind($fh);
    while (($row = fgetcsv($fh, 0, $dl)) !== false) {
        $row = array_map(fn($x) => trim((string)$x), $row);
        if (implode('', $row) !== '') $out[] = $row;
    }
    fclose($fh);
    return ['ok' => true, 'rows' => $out, 'error' => null];
}

/** XLSX مینیمال عمومی (همهٔ ستون‌های A..H هر ردیف) */
function fr_import_rows_xlsx(string $file): array
{
    if (!class_exists('ZipArchive')) return ['ok' => false, 'rows' => [], 'error' => 'افزونهٔ Zip روی PHP فعال نیست؛ از CSV استفاده کنید.'];
    $zip = new ZipArchive();
    if (@$zip->open($file) !== true) return ['ok' => false, 'rows' => [], 'error' => 'فایل اکسل قابل باز شدن نیست.'];

    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false && ($sx = @simplexml_load_string($xml))) {
        foreach ($sx->xpath('//*[local-name()="si"]') ?: [] as $si) {
            $txt = '';
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) $txt .= (string)$t;
            $shared[] = $txt;
        }
    }
    $sheetXml = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if ($n && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheetXml = $zip->getFromName($n); break; }
    }
    if ($sheetXml === false) { $zip->close(); return ['ok' => false, 'rows' => [], 'error' => 'هیچ ورقی در فایل اکسل پیدا نشد.']; }
    $sx = @simplexml_load_string($sheetXml);
    $zip->close();
    if (!$sx) return ['ok' => false, 'rows' => [], 'error' => 'ساختار ورق اکسل خوانا نیست.'];

    $out = [];
    foreach ($sx->xpath('//*[local-name()="row"]') ?: [] as $row) {
        $cells = [];
        $maxIdx = -1;
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $c) {
            $ref  = (string)($c['r'] ?? '');
            $colL = strtoupper(preg_replace('/\d/', '', $ref));
            $idx  = 0;
            for ($i = 0, $n = strlen($colL); $i < $n; $i++) { $idx = $idx * 26 + (ord($colL[$i]) - 64); }
            $idx--; if ($idx > 7 || $idx < 0) continue;      // فقط A..H
            $t = (string)($c['t'] ?? '');
            $v = '';
            if ($t === 'inlineStr') {
                foreach ($c->xpath('.//*[local-name()="t"]') ?: [] as $tt) $v .= (string)$tt;
            } else {
                $v = (string)($c->xpath('./*[local-name()="v"]')[0] ?? '');
                if ($t === 's' && $v !== '' && isset($shared[(int)$v])) $v = $shared[(int)$v];
            }
            $cells[$idx] = trim($v);
            $maxIdx = max($maxIdx, $idx);
        }
        if ($maxIdx >= 0) $out[] = array_values(array_replace(array_fill(0, $maxIdx + 1, ''), $cells));
    }
    if (!$out) return ['ok' => false, 'rows' => [], 'error' => 'ردیفی در فایل پیدا نشد.'];
    return ['ok' => true, 'rows' => $out, 'error' => null];
}

/**
 * تفسیر ردیف ورودی دیدگاه به ساختار استاندارد
 *  قالب ستون‌ها: context | item(slug|id) | name | mobile? | rating | body | label?
 *  سازگار با زبانهٔ میان‌بر: «test|gardner-mi|سارا|0912..|5|عالی بود|شرکت‌کننده»
 */
function fr_import_parse_row(array $r): array
{
    $r = array_values(array_map(fn($x) => trim((string)$x), $r));
    $ctxRaw = mb_strtolower(en_num((string)($r[0] ?? '')));
    $map = [
        'test' => 'test', 'تست' => 'test', 'تست روانشناسی' => 'test', 'تست‌های روانشناسی' => 'test',
        /* سازگاری با فایل‌های اکسل قبل از نسخهٔ ۲٫۲ */
        'آزمون' => 'test', 'آزمون‌ها' => 'test', 'روانشناسی' => 'test',
        'workshop' => 'workshop', 'کارگاه' => 'workshop', 'کارگاه‌ها' => 'workshop',
        'course' => 'course', 'دوره' => 'course', 'دورهٔ' => 'course', 'دوره‌ها' => 'course',
    ];
    $ctx  = $map[$ctxRaw] ?? null;
    $name = (string)($r[2] ?? '');
    $mob  = en_num((string)($r[3] ?? ''));
    $rate = (int)preg_replace('/\D/', '', en_num((string)($r[4] ?? '')));
    $body = (string)($r[5] ?? '');
    if (!$ctx || trim((string)($r[1] ?? '')) === '' || $name === '' || $body === '') {
        return ['ok' => false, 'error' => 'ستون‌های اجباری ناقص است'];
    }
    if ($rate < 1 || $rate > 5) return ['ok' => false, 'error' => 'امتیاز باید ۱ تا ۵ باشد'];
    return ['ok' => true, 'data' => [
        'context' => $ctx, 'item_key' => (string)$r[1],
        'name' => mb_substr($name, 0, 150),
        'mobile' => valid_mobile($mob) ? $mob : '',
        'rating' => $rate, 'body' => mb_substr($body, 0, 2000),
        'label' => trim((string)($r[6] ?? '')),
    ]];
}
