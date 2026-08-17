<?php
/**
 * ============================================================================
 *  صفحهٔ عمومی عضویت در خبرنامه — نسخهٔ ۲٫۸
 *  ─ لینک مجزا: مخاطبان بدون ورود به سایت، نام + موبایل + آیدی تلگرام/بله ثبت می‌کنند
 *  ─ هر ثبت: عضویت در خبرنامهٔ پیامکی + افزودن/به‌روزرسانی در «مخاطبین» (source=newsletter)
 *  ─ پس از ثبت، پیامک دعوت حاوی لینک‌های استارت خودکار ربات تلگرام و بله ارسال می‌شود
 *    تا اطلاعیه‌ها مستقیم در اکانت بله و تلگرام مخاطب برسد؛ خود لینک‌ها هم روی
 *    صفحهٔ موفقیت نمایش داده می‌شوند (اگر پیامک‌رسان خاموش بود).
 * ============================================================================
 */
declare(strict_types=1);
if (!file_exists(__DIR__ . '/config.php')) { header('Location: install/'); exit; }
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/view.php';
require_once __DIR__ . '/includes/public-layout.php';
require_once __DIR__ . '/includes/contacts.php';
fr_bootstrap();

$enabled = (string)DB::setting('newsletter_page_enabled', '1') === '1';
$intro   = trim((string)DB::setting('newsletter_page_intro', ''));

$errors = [];
$done   = false;
$sentOk = false;
$links  = ['telegram' => '', 'bale' => ''];
$old    = ['name' => '', 'mobile' => '', 'tg' => '', 'bale' => ''];

if ($enabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();

    /* ریت‌لیمیت ضد اسپم — هر IP چند ثانیه یک‌بار */
    $ip = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16);
    if (!rate_limit('nl_page:' . $ip, 6, 300)) {
        $errors[] = 'کمی آرام‌تر! چند دقیقهٔ دیگر دوباره تلاش کنید.';
    }

    $name   = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    $mobile = en_num(trim((string)($_POST['mobile'] ?? '')));
    $tg     = ltrim(mb_substr(trim((string)($_POST['tg_username'] ?? '')), 0, 60), '@');
    $bale   = ltrim(mb_substr(trim((string)($_POST['bale_username'] ?? '')), 0, 60), '@');
    $old    = ['name' => $name, 'mobile' => $mobile, 'tg' => $tg, 'bale' => $bale];

    if (!$errors) {
        if (mb_strlen($name) < 3)                 $errors[] = 'نام و نام خانوادگی را کامل بنویسید (مثلاً: سارا احمدی).';
        if (!valid_mobile($mobile))               $errors[] = 'شمارهٔ موبایل معتبر نیست (مثال: 09123456789).';
        foreach (['تلگرام' => $tg, 'بله' => $bale] as $label => $un) {
            if ($un !== '' && !preg_match('/^[A-Za-z0-9_]{3,32}$/', $un)) {
                $errors[] = 'آیدی ' . $label . ' معتبر نیست (فقط حروف انگلیسی، عدد و آندرلاین؛ بدون @).';
            }
        }
    }

    if (!$errors) {
        try {
            /* ۱) خبرنامهٔ پیامکی (upsert — مثل API فوتر) */
            $nl = DB::one('SELECT id,status FROM ' . DB::t('newsletter') . ' WHERE mobile=?', [$mobile]);
            if ($nl) {
                DB::run("UPDATE " . DB::t('newsletter') . " SET status='active', unsub_at=NULL, name=COALESCE(NULLIF(?,''),name), source='public_page' WHERE id=?",
                        [$name, (int)$nl['id']]);
                log_activity($nl['status'] === 'active' ? 'newsletter_sync' : 'newsletter_resub', 'user', null, ['mobile' => $mobile, 'via' => 'public_page']);
            } else {
                DB::insert('newsletter', ['mobile' => $mobile, 'name' => $name, 'status' => 'active', 'source' => 'public_page']);
                log_activity('newsletter_sub', 'user', null, ['mobile' => $mobile, 'via' => 'public_page']);
            }

            /* ۲) مخاطبین (با آیدی تلگرام/بله) — dedupe بر اساس موبایل */
            [$cid, $created] = fr_contact_add($name, $mobile, 'خبرنامه', 'newsletter',
                'عضویت از صفحهٔ عمومی خبرنامه',
                ['tg_username' => $tg, 'bale_username' => $bale]);
            if (!$created && $cid > 0) {
                /* مخاطبِ قبلی: آیدی‌های تازه را هم به‌روزرسانی کن */
                $fix = [];
                if ($tg !== '')   $fix['tg_username'] = $tg;
                if ($bale !== '') $fix['bale_username'] = $bale;
                if ($fix) DB::update('contacts', $fix, 'id=?', [$cid]);
            }

            /* ۳) بررسی وضعیت اتصال + پیامک دعوت به استارت ربات‌ها */
            if ($cid > 0) {
                fr_contact_check($cid);
                $contact = DB::one('SELECT * FROM ' . DB::t('contacts') . ' WHERE id=?', [$cid]) ?: [];
                $links['telegram'] = $contact ? fr_contact_invite_link($contact, 'telegram') : '';
                $links['bale']     = $contact ? fr_contact_invite_link($contact, 'bale') : '';
                try {
                    $inv = fr_contact_send_invite($cid);
                    $sentOk = !empty($inv['ok']);
                } catch (Throwable $e) { $sentOk = false; /* پیامک‌رسان خاموش — لینک‌ها روی صفحه هستند */ }
            }
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'ثبت اطلاعات با خطا روبه‌رو شد؛ لطفاً چند لحظهٔ دیگر دوباره تلاش کنید.';
        }
    }
}

public_header([
    'active' => 'newsletter',
    'title'  => 'عضویت در خبرنامه',
    'desc'   => 'عضویت در خبرنامهٔ ' . (string)DB::setting('site_title', 'فریا روان') . ' — دریافت اطلاعیه‌ها در پیامک، بله و تلگرام',
], 'عضویت در خبرنامه');
?>
<section class="pb-hero">
  <span class="pb-hero-ic" aria-hidden="true">💌</span>
  <div class="pb-hero-in">
    <h1>عضویت در خبرنامهٔ <?= e((string)DB::setting('site_title', 'فریا روان')) ?></h1>
    <p>از جدیدترین تست‌های روانشناسی، کارگاه‌ها، دوره‌ها و تخفیف‌ها زودتر از همه باخبر شوید — در پیامک، بله و تلگرام.</p>
  </div>
</section>

<div class="pb-wrap" style="max-width:640px">
<?php if (!$enabled): ?>
  <div class="fr-card"><div class="fr-alert warn" style="margin:0">صفحهٔ عمومی عضویت در خبرنامه فعلاً غیرفعال است.</div></div>
<?php elseif ($done): ?>
  <div class="fr-card" style="text-align:center;border:2px solid #19a974">
    <div style="font-size:44px;line-height:1">🎉</div>
    <h2 style="margin:10px 0 6px">عضویت شما ثبت شد!</h2>
    <p style="color:var(--muted);font-size:14px;line-height:2">
      اطلاعیه‌ها برای <?= e($old['mobile'] !== '' ? $old['mobile'] : 'شما') ?> پیامک می‌شود.
      <?php if ($sentOk): ?>
        <br>✅ پیامک حاوی لینک‌های فعال‌سازی بله و تلگرام برایتان ارسال شد — با لمس هر لینک و زدن دکمهٔ «استارت»، اطلاعیه‌ها مستقیم در اکانت شما می‌آید.
      <?php endif; ?>
    </p>
    <?php if ($links['telegram'] !== '' || $links['bale'] !== ''): ?>
    <div style="background:#f6f4ff;border:1px dashed #c9bcf5;border-radius:14px;padding:14px;margin-top:14px">
      <b style="font-size:13.5px">📣 دریافت اطلاعیه در پیام‌رسان‌ها:</b>
      <p style="font-size:12.5px;color:var(--muted);margin:6px 0 12px">روی لینک بزنید و «استارت» را بزنید تا اعلان‌های ما در اکانت <?= $links['bale'] !== '' && $links['telegram'] !== '' ? 'بله و تلگرام' : ($links['bale'] !== '' ? 'بله' : 'تلگرام') ?> شما بیاید:</p>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <?php if ($links['telegram'] !== ''): ?><a class="fr-btn sm" href="<?= e($links['telegram']) ?>" target="_blank" rel="noopener">✈️ استارت ربات تلگرام</a><?php endif; ?>
        <?php if ($links['bale'] !== ''): ?><a class="fr-btn sm" href="<?= e($links['bale']) ?>" target="_blank" rel="noopener">🟣 استارت ربات بله</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="fr-card">
    <?php if ($intro !== ''): ?><div class="ev-content" style="margin-bottom:14px"><?= fr_sanitize_html($intro) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="fr-alert err">⚠️ <?= e($err) ?></div><?php endforeach; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="fr-field"><label>نام و نام خانوادگی *</label>
        <input class="fr-input" name="name" required maxlength="120" value="<?= e($old['name']) ?>" placeholder="مثلاً: سارا احمدی"></div>
      <div class="fr-field"><label>شمارهٔ موبایل *</label>
        <input class="fr-input" name="mobile" required inputmode="numeric" maxlength="11" value="<?= e($old['mobile']) ?>" placeholder="09xxxxxxxxx" style="direction:ltr;text-align:right">
        <small class="hint">اطلاعیه‌ها به این شماره پیامک می‌شود و لینک فعال‌سازی ربات‌ها هم به همین شماره ارسال خواهد شد.</small></div>
      <div class="fr-grid cols-2" style="gap:12px">
        <div class="fr-field"><label>آیدی تلگرام (اختیاری)</label>
          <input class="fr-input" name="tg_username" maxlength="60" value="<?= e($old['tg']) ?>" placeholder="@username" style="direction:ltr;text-align:right"></div>
        <div class="fr-field"><label>آیدی بله (اختیاری)</label>
          <input class="fr-input" name="bale_username" maxlength="60" value="<?= e($old['bale']) ?>" placeholder="@username" style="direction:ltr;text-align:right"></div>
      </div>
      <small class="hint" style="display:block;margin-bottom:12px">
        🔒 اطلاعات شما فقط برای ارسال اطلاعیه‌های <?= e((string)DB::setting('site_title', 'فریا روان')) ?> استفاده می‌شود و هر زمان بخواهید می‌توانید لغو عضویت کنید.
      </small>
      <button class="fr-btn" style="width:100%;justify-content:center;font-size:15px">💌 عضویت در خبرنامه</button>
    </form>
  </div>
<?php endif; ?>
</div>
<?php public_footer(); ?>
