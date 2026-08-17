<?php
/**
 * =============================================================================
 *  متن‌های سایت (نسخهٔ ۲٫۱ — ری‌برندینگ کامل)
 *  ─ هر متنی که در سایت/پنل آمده با کلید شناخته می‌شود؛ مدیر می‌تواند متن
 *    دلخواهش را جایگزین کند. بازنشانی = فیلد را خالی بگذارید و ذخیره کنید.
 *  ─ افزودن کلید سفارشی برای استفاده در قالب‌ها/کد
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/texts.php';
$adm = require_admin(['superadmin']);

$back = function (array $q = []) {
    return SITE_URL . '/admin/texts.php' . ($q ? '?' . http_build_query($q) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)post('act');

    if ($act === 'save') {
        $key = (string)post('k');
        $txt = trim((string)post('txt'));
        $reg = fr_texts_registry();
        if (!isset($reg[$key])) { flash('کلید نامعتبر است.', 'err'); redirect($back()); }
        if ($txt === '' || $txt === $reg[$key][0]) {
            DB::run('DELETE FROM ' . DB::t('texts') . ' WHERE k=?', [$key]);   // برگشت به پیش‌فرض
        } else {
            DB::run('INSERT INTO ' . DB::t('texts') . ' (k, txt) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE txt=VALUES(txt)', [$key, $txt]);
        }
        log_activity('text_save', 'text', 0, ['k' => $key]);
        flash('متن «' . $key . '» ذخیره شد ✅');
        redirect($back(['sec' => (string)post('sec'), 'q' => (string)post('q')]));
    }

    if ($act === 'add') {
        $key = strtolower((string)post('new_key'));
        $def = trim((string)post('new_default'));
        $sec = trim((string)post('new_section')) ?: 'سفارشی';
        if (fr_texts_add_key($key, $def, $sec)) flash('کلید جدید «' . e($key) . '» افزوده شد ✅');
        else flash('افزودن کلید ناموفق بود؛ کلید را بررسی کنید (فقط حروف/عدد/نقطه).', 'err');
        redirect($back(['sec' => $sec]));
    }

    if ($act === 'reset_all') {
        $sec = (string)post('sec');
        $n = 0;
        $reg = fr_texts_registry();
        foreach (fr_texts_list() as $k => $row) {
            if ($row['custom'] && ($sec === '' || $row['section'] === $sec)) {
                DB::run('DELETE FROM ' . DB::t('texts') . ' WHERE k=?', [$k]); $n++;
            }
        }
        log_activity('text_reset_bulk', 'text', 0, ['sec' => $sec, 'n' => $n]);
        flash('بازنشانی گروهی انجام شد (' . fa_num($n) . ' متن به پیش‌فرض برگشت).');
        redirect($back(['sec' => $sec]));
    }
}

$secF  = (string)($_GET['sec'] ?? '');
$q     = trim((string)($_GET['q'] ?? ''));
$rows  = fr_texts_list();
$secs  = array_values(array_unique(array_column($rows, 'section')));
if ($secF !== '') $rows = array_filter($rows, fn($r) => $r['section'] === $secF);
if ($q !== '') {
    $rows = array_filter($rows, fn($r, $k) =>
        mb_strpos($r['current'], $q) !== false || mb_strpos($r['default'], $q) !== false || mb_strpos($k, $q) !== false,
        ARRAY_FILTER_USE_BOTH);
}
$customN = count(array_filter($rows, fn($r) => $r['custom']));

admin_layout($adm, 'texts', 'متن‌های سایت');
echo flash(); ?>
<div class="fr-card">
  <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
    <div style="flex:1;min-width:260px">
      <h3 style="margin:0 0 6px">✍️ شخصی‌سازی متن‌های سایت — ری‌برندینگ</h3>
      <p style="font-size:12.5px;color:var(--muted);margin:0;line-height:2">
        هر متنی که کاربر در هر نقطهٔ سایت می‌بیند ازاینجا قابل بازنویسی است (منوها، فوتر، پنل، دکمه‌ها…).
        فیلد را خالی بگذارید تا به <b>متن پیش‌فرض</b> برگردد. تغییرات بلافاصله در کل سایت اعمال می‌شود.
      </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span class="fr-chip purple"><?= fa_num($customN) ?> متن سفارشی</span>
      <span class="fr-chip info"><?= fa_num(count($rows)) ?> کلید</span>
    </div>
  </div>
</div>

<div class="fr-card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <?php foreach (array_merge(['' => 'همهٔ بخش‌ها'], array_combine($secs, $secs)) as $k => $v): ?>
  <a class="fr-btn sm <?= $secF === (string)$k ? '' : 'ghost' ?>" href="?<?= http_build_query(['sec' => $k, 'q' => $q]) ?>"><?= e($v) ?></a>
  <?php endforeach; ?>
  <form method="get" style="margin-inline-start:auto;display:flex;gap:8px">
    <?php if ($secF !== ''): ?><input type="hidden" name="sec" value="<?= e($secF) ?>"><?php endif; ?>
    <input class="fr-input sm" type="search" name="q" value="<?= e($q) ?>" placeholder="جست‌وجو در متن‌ها یا کلید…" style="min-width:200px">
    <button class="fr-btn sm ghost">🔍</button>
  </form>
</div>

<?php if (!$rows): ?>
<div class="fr-card"><p class="fr-alert info" style="margin:0">موردی مطابق فیلتر یافت نشد.</p></div>
<?php else: ?>
<div class="fr-card" style="padding:8px 16px">
  <?php foreach ($rows as $k => $r): ?>
  <form method="post" style="display:flex;gap:12px;align-items:flex-start;padding:14px 0;border-bottom:1px solid #f1eef9;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="k" value="<?= e($k) ?>">
    <input type="hidden" name="sec" value="<?= e($secF) ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
    <div style="flex:1;min-width:250px">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <code style="font-size:11px;background:#f2f0fa;border-radius:8px;padding:2px 10px;direction:ltr"><?= e($k) ?></code>
        <span class="fr-chip info" style="font-size:10px"><?= e($r['section']) ?></span>
        <?php if ($r['custom']): ?><span class="fr-chip ok" style="font-size:10px">سفارشی‌شده ✓</span><?php endif; ?>
      </div>
      <small style="display:block;color:var(--muted);margin:6px 0 4px">پیش‌فرض: <?= e($r['default']) ?></small>
      <input class="fr-input" name="txt" value="<?= e($r['custom'] ? $r['current'] : '') ?>"
             placeholder="<?= e($r['default']) ?>" style="font-size:13px">
    </div>
    <div style="padding-top:34px">
      <button class="fr-btn sm">💾</button>
    </div>
  </form>
  <?php endforeach; ?>
</div>

<?php if ($customN > 0): ?>
<form method="post" onsubmit="return confirm('همهٔ متن‌های سفارشی این بخش به پیش‌فرض برگردانده شود؟')" style="margin:10px 0">
  <?= csrf_field() ?><input type="hidden" name="act" value="reset_all"><input type="hidden" name="sec" value="<?= e($secF) ?>">
  <button class="fr-btn danger sm">↩ بازنشانی <?= $secF !== '' ? 'متن‌های این بخش' : 'همهٔ متن‌ها' ?> به پیش‌فرض</button>
</form>
<?php endif; ?>
<?php endif; ?>

<div class="fr-card" style="border:1.5px dashed var(--c-primary);background:#fafbfd">
  <h3 style="margin:0 0 8px">➕ افزودن کلید متن سفارشی</h3>
  <p style="font-size:12px;color:var(--muted);margin:0 0 10px">
    برای متن‌های تازهٔ قالب/کد. بعداً در کد با <code dir="ltr">T('your.key')</code> خوانده می‌شود و از همین صفحه قابل‌ویرایش است.
  </p>
  <form method="post" class="fr-grid cols-3" style="align-items:end">
    <?= csrf_field() ?><input type="hidden" name="act" value="add">
    <div class="fr-field"><label>کلید (انگلیسی، مثل footer.slogan2)</label>
      <input class="fr-input" name="new_key" dir="ltr" pattern="[a-z0-9_.-]+" required placeholder="my.custom.key"></div>
    <div class="fr-field"><label>متن پیش‌فرض</label>
      <input class="fr-input" name="new_default" required placeholder="متن اولیه…"></div>
    <div class="fr-field"><label>بخش</label>
      <input class="fr-input" name="new_section" placeholder="سفارشی"></div>
    <button class="fr-btn">افزودن کلید ✚</button>
  </form>
</div>
<?php admin_layout_end(); ?>
