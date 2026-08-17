<?php
/**
 * ============================================================================
 *  مدیریت پیوندهای یکتا (سبک وردپرس) — نسخهٔ ۱٫۷
 *  ─ نشانی کوتاه: کارگاه /w/اسلاگ · دوره /c/اسلاگ · تست روانشناسی /t/اسلاگ
 *  ─ ویرایش اسلاگ همهٔ صفحات + بازتولید خودکار .htaccess / robots.txt
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once FR_ROOT . '/includes/sanitize.php';
require_once FR_ROOT . '/includes/seo.php';
$adm = require_admin(['superadmin','manager']);

$kindFa = ['workshop'=>'کارگاه','course'=>'دوره','test'=>'تست روانشناسی'];

/* ---------------------------------------------------------------------------
 | ذخیرهٔ اسلاگ‌ها — هر ردیف به‌صورت مستقل، سپس بازتولید خودکار .htaccess
 * -------------------------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    $changed = 0;
    foreach ((array)($_POST['ev'] ?? []) as $id => $slug) {
        $id = (int)$id;
        $new = fr_unique_slug('events', fr_slugify((string)$slug), 'slug', $id);
        $old = (string)DB::val('SELECT slug FROM ' . DB::t('events') . ' WHERE id=?', [$id]);
        if ($new !== $old) { DB::update('events', ['slug'=>$new], 'id=?', [$id]); $changed++; }
    }
    foreach ((array)($_POST['tt'] ?? []) as $id => $slug) {
        $id = (int)$id;
        $new = fr_unique_slug('tests', fr_slugify((string)$slug), 'slug', $id);
        $old = (string)DB::val('SELECT slug FROM ' . DB::t('tests') . ' WHERE id=?', [$id]);
        if ($new !== $old) { DB::update('tests', ['slug'=>$new], 'id=?', [$id]); $changed++; }
    }
    $fl = fr_permalinks_flush();
    log_activity('admin_permalinks', null, null, ['changed'=>$changed,'rules'=>$fl['count']]);
    flash($changed > 0
        ? "✅ $changed پیوند به‌روز شد و فایل .htaccess با «{$fl['count']} قانون» به‌صورت خودکار بازتولید شد."
        : ".htaccess با «{$fl['count']} قانون» بازتولید شد؛ تغییری در اسلاگ‌ها نبود.");
    redirect(SITE_URL . '/admin/permalinks.php');
}

$events = DB::all('SELECT id,kind,title,slug,status FROM ' . DB::t('events') . ' ORDER BY kind, id DESC');
$tests  = DB::all('SELECT id,title,slug,status FROM ' . DB::t('tests') . ' ORDER BY sort_order');

admin_layout($adm, 'permalinks', 'پیوندهای یکتا');
echo flash();
?>
<div class="fr-alert info" style="font-size:13px;line-height:2">
  <b>پیوند کوتاه و زیبا</b> — فقط اسلاگ (بخش پایانی نشانی) را می‌توانید ویرایش کنید؛ پیشوندِ مسیر به‌صورت خودکار است:<br>
  🛠 کارگاه‌ها: <code class="ltr"><?= SITE_URL ?>/w/<b>اسلاگ</b></code> &nbsp;|&nbsp;
  📚 دوره‌ها: <code class="ltr"><?= SITE_URL ?>/c/<b>اسلاگ</b></code> &nbsp;|&nbsp;
  🧠 تست‌های روانشناسی: <code class="ltr"><?= SITE_URL ?>/t/<b>اسلاگ</b></code><br>
  پس از هر ذخیره، فایل‌های <code>.htaccess</code> و <code>robots.txt</code> به‌صورت خودکار با قوانین تازه بازسازی می‌شوند — دقیقاً مثل «بازسازی پیوندهای یکتا» در وردپرس، بدون نیاز به هیچ کار دستی.
</div>

<form method="post">
<?= csrf_field() ?><input type="hidden" name="act" value="save">

<div class="fr-card">
  <h3>🛠 کارگاه‌ها &nbsp;|&nbsp; 📚 دوره‌ها</h3>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead><tr><th>نوع</th><th>عنوان</th><th>اسلاگ (بخش قابل ویرایش نشانی)</th><th>نشانی نهایی</th></tr></thead>
    <tbody>
    <?php foreach ($events as $e): $url = fr_event_url($e); ?>
    <tr style="<?= $e['status']==='active'?'':'opacity:.5' ?>">
      <td style="font-size:11.5px;white-space:nowrap"><?= $kindFa[$e['kind']] ?? $e['kind'] ?></td>
      <td style="font-size:12.5px;min-width:160px"><?= e($e['title']) ?></td>
      <td style="min-width:230px">
        <div style="display:flex;align-items:center;gap:4px">
          <span style="font-size:12px;color:var(--muted);white-space:nowrap;direction:ltr"><?= SITE_URL . '/' . ($e['kind']==='course'?'c':'w') ?>/</span>
          <input class="fr-input sm" name="ev[<?= (int)$e['id'] ?>]" value="<?= e($e['slug'] ?? '') ?>"
                 style="direction:ltr;text-align:left;font-size:12px;padding:7px 10px" placeholder="my-slug">
        </div>
      </td>
      <td style="font-size:11.5px"><a href="<?= e($url) ?>" target="_blank" rel="noopener">🔗 مشاهده</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="fr-card">
  <h3>🧠 تست‌های روانشناسی</h3>
  <div style="overflow-x:auto">
  <table class="fr-table">
    <thead><tr><th>عنوان تست روانشناسی</th><th>اسلاگ</th><th>نشانی نهایی</th></tr></thead>
    <tbody>
    <?php foreach ($tests as $t): $url = fr_test_url($t); ?>
    <tr style="<?= $t['status']==='active'?'':'opacity:.5' ?>">
      <td style="font-size:12.5px;min-width:200px"><?= e($t['title']) ?></td>
      <td style="min-width:230px">
        <div style="display:flex;align-items:center;gap:4px">
          <span style="font-size:12px;color:var(--muted);white-space:nowrap;direction:ltr"><?= SITE_URL ?>/t/</span>
          <input class="fr-input sm" name="tt[<?= (int)$t['id'] ?>]" value="<?= e($t['slug']) ?>"
                 style="direction:ltr;text-align:left;font-size:12px;padding:7px 10px">
        </div>
      </td>
      <td style="font-size:11.5px"><a href="<?= e($url) ?>" target="_blank" rel="noopener">🔗 مشاهده</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<button class="fr-btn">💾 ذخیرهٔ پیوندها + بازتولید خودکار .htaccess</button>
</form>

<div class="fr-card" style="margin-top:20px">
  <h3>📍 نقشهٔ سایت و موتورهای جستجو</h3>
  <ul style="font-size:13px;line-height:2.4;margin:0;padding-right:18px;color:#2c3a5c">
    <li>نقشهٔ سایت شما به‌صورت خودکار در نشانی <code class="ltr"><a href="<?= SITE_URL ?>/sitemap.xml" target="_blank" rel="noopener"><?= SITE_URL ?>/sitemap.xml</a></code> منتشر می‌شود — آدرس را در <b>Google Search Console</b> ثبت کنید.</li>
    <li>فایل <code>robots.txt</code> هم هنگام ذخیرهٔ پیوندها با ارجاع نقشهٔ سایت بازسازی می‌شود.</li>
    <li>تیتر و توضیح سئوی هر کارگاه/دوره/تست روانشناسی را از فرم ویرایش همان صفحه (بخش «سئو») شخصی‌سازی کنید.</li>
  </ul>
</div>
<?php admin_layout_end(); ?>
