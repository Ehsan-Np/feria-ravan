<?php
/**
 * ============================================================================
 *  رزرو عمومی نوبت مشاوره — کاتالوگ خدمات + ویزارد بوکینگ (نسخهٔ ۱٫۴)
 *  ─ بدون نیاز به ورود: انتخاب خدمت و مشاهدهٔ تقویم برای همه آزاد است
 *  ─ در مرحلهٔ پرداخت: مهمان → ورود با بازگشت به همان اسلات انتخاب‌شده
 *  ─ کاربر واردشده: هویت از پروفایل خوانده می‌شود (بدون ورود مجدد اطلاعات)
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
require_once __DIR__ . '/includes/sanitize.php';
require_once __DIR__ . '/includes/consult.php';
require_once __DIR__ . '/includes/deposit.php';
require_once __DIR__ . '/core/Kavenegar.php';
require_once __DIR__ . '/core/Zarinpal.php';
require_once __DIR__ . '/core/PageBuilder.php';
fr_bootstrap();

consult_release_expired_holds();
$me    = current_user();
$api   = SITE_URL . '/api/consult.php';
$slug  = trim((string)($_GET['s'] ?? ''));

/* ---------------------------------------------------------------------------
 | برگه رزرو مشاوره سفارشی طراحی‌شده با صفحه‌ساز دیداری فریا روان
 * -------------------------------------------------------------------------*/
if ($slug === '' && empty($_GET['classic']) && empty($_GET['default_view']) && class_exists('PageBuilder')) {
    PageBuilder::ensureSchema();
    $customPage = PageBuilder::getSectionPage('consult');
    if ($customPage && !empty($customPage['active'])) {
        PageBuilder::renderPage($customPage, true);
        exit;
    }
}

[$jyNow, $jmNow, $jdNow] = fr_jnow();
$todayJ = $jyNow . '/' . $jmNow . '/' . $jdNow;   // ASCII برای data-jnow (پردازش JS)

/* ============================================================================
 | حالت ۱: کاتالوگ خدمات مشاوره
 * ==========================================================================*/
if ($slug === '') {
    $services = consult_services_catalog();
    /* نسخهٔ ۱٫۷: تیتر/توضیح هیرو از تنظیمات مدیر (تب «متن‌های صفحات») */
    $hTitle = trim((string)DB::setting('consult_hero_title','')) !== ''
            ? (string)DB::setting('consult_hero_title','') : 'رزرو نوبت مشاوره';
    $hDesc  = trim((string)DB::setting('consult_hero_desc','')) !== ''
            ? (string)DB::setting('consult_hero_desc','')
            : 'گاهی یک گفت‌وگوی به‌موقع همه‌چیز را عوض می‌کند. خدمت را انتخاب کنید، از تقویم روز و ساعتی که راحتید پیدا کنید و در همان چند دقیقه نوبت‌تان قطعی است؛ پیامک تأیید هم فوری برای شما و مشاور می‌آید. اگر هم پشیمان شدید، تا پیش از جلسه می‌توانید درخواست عودت وجه بدهید.';
    public_header(['active'=>'consult','tone'=>'tone-consult',
                   'canonical'=>SITE_URL . '/consult.php',
                   'desc'=>$hDesc],
                  'رزرو نوبت مشاوره');
    ?>
<section class="pb-hero">
  <span class="pb-hero-ic" aria-hidden="true">🛋</span>
  <div class="pb-hero-in">
    <h1><?= e($hTitle) ?></h1>
    <p><?= e($hDesc) ?></p>
  </div>
</section>

<div class="pb-wrap">
  <!-- سه گام رزرو -->
  <div class="bk-steps" style="max-width:720px;margin-bottom:30px">
    <span class="on"><i>۱</i> انتخاب خدمت و مشاور</span>
    <span><i>۲</i> انتخاب روز و ساعت</span>
    <span><i>۳</i> پرداخت و تأیید پیامکی</span>
  </div>

  <?php if (!$services): ?>
  <div class="pb-empty"><span class="ic">🛋</span>فعلاً خدمت مشاوره‌ای فعال نیست؛ به‌زودی فعال می‌شود.</div>
  <?php else: ?>
  <div class="cs-grid">
    <?php foreach ($services as $sv): ?>
    <article class="cs-card">
      <div class="cs-top">
        <?php if ($sv['consultant_avatar']): ?>
        <img class="cs-ava" src="<?= e(SITE_URL . '/' . ltrim($sv['consultant_avatar'], '/')) ?>" alt="<?= e($sv['consultant_name']) ?>" loading="lazy">
        <?php else: ?>
        <span class="cs-ava" aria-hidden="true">🛋</span>
        <?php endif; ?>
        <div>
          <b><?= e($sv['consultant_name']) ?></b>
          <small><?= e($sv['consultant_title'] ?? 'مشاور فریا') ?></small>
        </div>
      </div>
      <div class="cs-body">
        <h3><?= e($sv['title']) ?></h3>
        <div class="cs-chips">
          <span>⏱ <?= fa_num($sv['duration_min']) ?> دقیقه</span>
          <span><?= $sv['mode'] === 'online' ? '💻 آنلاین' : ($sv['mode'] === 'inperson' ? '🏢 حضوری' : '💻/🏢 به انتخاب شما') ?></span>
          <span><?= (int)$sv['open_slots'] > 0 ? '🟢 ' . fa_num($sv['open_slots']) . ' نوبت آزاد' : '⏳ به‌زودی' ?></span>
        </div>
        <p class="cs-sum"><?= e($sv['short_desc'] ?: mb_substr(trim(strip_tags((string)$sv['description'])), 0, 120)) ?></p>
        <div class="cs-foot">
          <div class="ev-price">
            <?php if ((int)$sv['price'] > 0): ?><b><?= toman((int)$sv['price']) ?></b><small>هر جلسه</small>
            <?php else: ?><b class="free">رایگان</b><small>بدون هزینه</small><?php endif; ?>
          </div>
          <a class="fr-btn sm" href="<?= SITE_URL ?>/consult.php?s=<?= urlencode($sv['slug']) ?>">مشاهده و رزرو ←</a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- اطمینان‌بخشی -->
  <div class="bk-sum" style="max-width:760px;margin:34px auto 0;text-align:center">
    <h3>چرا رزرو آنلاین فریا؟</h3>
    <p style="font-size:13.5px;line-height:2.1;margin:0;opacity:.95">
      نوبت شما بلافاصله پس از پرداخت قطعی می‌شود و پیامک تأیید دریافت می‌کنید.
      تا <?= fa_num(consult_cancel_hours()) ?> ساعت قبل از جلسه، امکان لغو آنلاین از پنل کاربری را دارید.
      اطلاعات شما محرمانه و نزد مرکز محفوظ است. 🔐
    </p>
  </div>
</div>
    <?php
    public_footer(); exit;
}

/* ============================================================================
 | حالت ۲: ویزارد بوکینگ یک خدمت
 * ==========================================================================*/
$service = consult_service($slug);
if (!$service) {
    redirect(SITE_URL . '/consult.php');   // خدمت نامعتبر → کاتالوگ
}

/* رزومه: پارامترهای slot/mode پس از بازگشت از ورود */
$resume = null;
if (($slotId = (int)($_GET['slot'] ?? 0)) > 0) {
    consult_release_expired_holds();
    $sl = DB::one('SELECT * FROM ' . DB::t('consult_slots') .
                  " WHERE id=? AND service_id=? AND status='open' AND starts_at>NOW()", [$slotId, (int)$service['id']]);
    if ($sl) {
        $ts = strtotime($sl['starts_at']);
        [$jyR, $jmR, $jdR] = g2j((int)date('Y', $ts), (int)date('m', $ts), (int)date('d', $ts));
        $resume = [
            'slot'=>(int)$sl['id'], 'time'=>date('H:i', $ts),
            'jy'=>$jyR, 'jm'=>$jmR, 'jd'=>$jdR,
            'gday'=>date('Y-m-d', $ts),
            'mode'=>in_array(($_GET['mode'] ?? ''), ['online','inperson'], true) ? $_GET['mode'] : null,
        ];
    }
}

$modes = $service['mode'] === 'both' ? ['online','inperson'] : [$service['mode']];
$modeFa = ['online'=>['💻','آنلاین','از طریق سامانهٔ تماس تصویری'], 'inperson'=>['🏢','حضوری','در مرکز مشاورهٔ فریا']];

public_header(['active'=>'consult','tone'=>'tone-consult','noindex'=>true,
               'desc'=>'رزرو نوبت «' . $service['title'] . '» نزد ' . $service['consultant_name'] . '؛ انتخاب روز و ساعت از تقویم شمسی و پرداخت آنلاین.'],
              'رزرو: ' . $service['title']);
?>
<nav class="pb-crumbs">
  <a href="<?= SITE_URL ?>/">خانه</a> ← <a href="<?= SITE_URL ?>/consult.php">رزرو مشاوره</a> ← <b><?= e($service['title']) ?></b>
</nav>

<div class="pb-wrap" style="padding-top:14px">
  <!-- معرفی کوتاه خدمت -->
  <div class="cs-top" style="border-radius:22px;border:1px solid var(--line);background:linear-gradient(140deg,var(--tone-soft),#fff);margin-bottom:18px">
    <?php if ($service['consultant_avatar']): ?>
    <img class="cs-ava" src="<?= e(SITE_URL . '/' . ltrim($service['consultant_avatar'], '/')) ?>" alt="<?= e($service['consultant_name']) ?>">
    <?php else: ?><span class="cs-ava" aria-hidden="true">🛋</span><?php endif; ?>
    <div style="flex:1">
      <b><?= e($service['title']) ?></b>
      <small><?= e($service['consultant_name']) ?> — <?= e($service['consultant_title'] ?? '') ?></small>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <span class="fr-chip purple">⏱ <?= fa_num($service['duration_min']) ?> دقیقه</span>
      <span class="fr-chip purple"><?= e(consult_mode_fa($service['mode'])) ?></span>
    </div>
  </div>

  <div class="bk-steps">
    <span><i>۱</i> انتخاب روز</span>
    <span><i>۲</i> انتخاب ساعت</span>
    <span><i>۳</i> تأیید و پرداخت</span>
  </div>

  <!-- اپ ویزارد -->
  <div id="bkApp"
       data-service="<?= (int)$service['id'] ?>"
       data-slug="<?= e($service['slug']) ?>"
       data-api="<?= e($api) ?>"
       data-jnow="<?= e($todayJ) ?>"
       data-login="<?= $me ? '1' : '0' ?>"
       data-login-url="<?= e(SITE_URL . '/auth.php') ?>"
       data-resume-base="<?= e(SITE_URL . '/consult.php') ?>"
       <?= $resume ? 'data-resume="' . e(json_encode($resume, JSON_UNESCAPED_UNICODE)) . '"' : '' ?>>

    <div class="bk-grid">
      <div>
        <!-- تقویم شمسی -->
        <div class="bk-cal" id="bkCal" style="margin-bottom:16px">
          <div class="bk-cal-head">
            <b id="bkCalTitle">…</b>
            <span class="nv">
              <button type="button" id="bkNext" aria-label="ماه بعد">‹</button>
              <button type="button" id="bkPrev" aria-label="ماه قبل">›</button>
            </span>
          </div>
          <div class="bk-cal-grid"></div>
        </div>
        <!-- اسلات‌های روز -->
        <div class="bk-slots" id="bkSlots">
          <div class="bk-empty">ابتدا از تقویم بالا یک روزِ دارای نوبت (🟢) را انتخاب کنید.</div>
        </div>
      </div>

      <div>
        <!-- جمع‌بندی چسبان -->
        <div class="bk-sum alone" id="bkSum">
          <h3>🧾 پیش‌فاکتور رزرو</h3>
          <?php if ($me): ?>
          <!-- هویت از پروفایل — بدون ورود مجدد اطلاعات -->
          <div class="bk-idcard">
            <img class="av" src="<?= e($me['avatar'] ? (str_starts_with($me['avatar'], 'http') ? $me['avatar'] : SITE_URL . '/' . $me['avatar']) : SITE_URL . '/assets/img/avatar.svg') ?>" alt="">
            <div><b><?= e(user_fullname($me)) ?></b><br><?= fa_num($me['mobile']) ?></div>
          </div>
          <?php endif; ?>
          <div class="rw"><span>خدمت</span><b><?= e($service['title']) ?></b></div>
          <div class="rw"><span>مشاور</span><b><?= e($service['consultant_name']) ?></b></div>
          <div class="rw"><span>تاریخ</span><b id="sumDate">—</b></div>
          <div class="rw"><span>ساعت</span><b id="sumTime">—</b></div>
          <div class="rw"><span>شیوهٔ جلسه</span><b id="sumMode">—</b></div>
          <div class="rw" id="bkDiscLine" style="display:none;color:#0c7a4d"><span>تخفیف کد</span><b id="sumDisc">—</b></div>
          <div class="pr" id="bkPrice"><?= (int)$service['price'] ? toman((int)$service['price']) : 'رایگان' ?></div>

          <?php if ($me && (int)$service['price'] > 0): ?>
          <!-- کد تخفیف عمومی روی رزرو مشاوره — نسخهٔ ۱٫۷ -->
          <div style="margin-bottom:14px;background:rgba(255,255,255,.14);border:1px dashed rgba(255,255,255,.5);border-radius:12px;padding:10px 12px">
            <label style="display:block;font-size:12px;color:#fff;opacity:.9;margin-bottom:6px">🎟 کد تخفیف دارید؟</label>
            <div style="display:flex;gap:8px;align-items:stretch">
              <!-- ۲٫۲: نسبت ۸۰/۲۰ — فیلد بزرگ و خوانا، دکمه جمع‌وجور هم‌اندازه -->
              <input class="fr-input" id="bkDisc" placeholder="کد تخفیف (اختیاری)" style="direction:ltr;text-align:center;flex:4 1 80%;background:rgba(255,255,255,.97);font-size:14px">
              <button type="button" class="fr-btn white sm" id="bkDiscBtn" style="flex:1 1 20%;justify-content:center;white-space:nowrap;padding:7px 10px">اعمال ✅</button>
            </div>
            <small id="bkDiscMsg" style="display:block;margin-top:5px;font-size:11px;color:#fff;opacity:.95"></small>
          </div>
          <?php endif; ?>

          <?php if (count($modes) > 1): ?>
          <div id="bkModeBox" style="margin-bottom:14px">
            <div class="bk-modes">
              <?php foreach ($modes as $m): ?>
              <button type="button" class="bk-mode" data-mode="<?= $m ?>">
                <span class="ic"><?= $modeFa[$m][0] ?></span>
                <span><b><?= $modeFa[$m][1] ?></b><small><?= $modeFa[$m][2] ?></small></span>
              </button>
              <?php endforeach; ?>
            </div>
          </div>
          <?php else: ?>
          <div id="bkModeBox" style="display:none"><button type="button" class="bk-mode sel" data-mode="<?= $modes[0] ?>"></button></div>
          <?php endif; ?>

          <div class="fr-field" style="margin-bottom:14px">
            <label style="color:#fff;opacity:.9">موضوع جلسه (اختیاری — فقط مشاور می‌بیند)</label>
            <textarea class="fr-textarea" id="bkNote" rows="2" maxlength="500" style="background:rgba(255,255,255,.95)"
                      placeholder="مثلاً: اضطراب کاری، مسئلهٔ زناشویی…"></textarea>
          </div>

          <?php
          /* ۳٫۰ — پیش‌پرداخت (بیعانه) برای قطعی شدن نوبت */
          $depOk = (int)$service['price'] > 0 && fr_deposit_available($service);
          if ($me && $depOk): ?>
          <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;font-size:12.5px;cursor:pointer;background:rgba(255,255,255,.16);border:1.5px dashed rgba(255,255,255,.5);border-radius:12px;padding:10px 12px;color:#fff;line-height:1.9">
            <input type="checkbox" id="bkDep" value="1" style="width:18px;height:18px;flex:0 0 auto">
            <span>💳 فقط <b>پیش‌پرداخت (<?= toman((int)$service['deposit_amount']) ?>)</b> را الان می‌پردازم —
            نوبت همین حالا قطعی می‌شود و مانده را <b>حداکثر تا ۳ روز مانده به جلسه</b> با لینک پیامکی یا از پنل کاربری تسویه می‌کنم.</span>
          </label>
          <?php endif; ?>

          <?php
          /* کیف پول در نهایی‌سازی رزرو — نسخهٔ ۱٫۶ */
          $wBal = 0;
          if ($me) { require_once __DIR__ . '/includes/wallet.php'; $wBal = wallet_balance((int)$me['id']); }
          if ($me && $wBal > 0 && (int)$service['price'] > 0): ?>
          <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;font-size:12.5px;cursor:pointer;background:rgba(255,255,255,.16);border:1.5px dashed rgba(255,255,255,.5);border-radius:12px;padding:10px 12px;color:#fff">
            <input type="checkbox" id="bkWallet" value="1" style="width:18px;height:18px">
            <span>👛 پرداخت با کیف پول (موجودی: <b><?= toman($wBal) ?></b> — اگر کفایت کند، بدون درگاه قطعی می‌شود)</span>
          </label>
          <?php endif; ?>

          <button class="fr-btn white" id="bkGo" type="button" disabled>
            <?= $me ? ((int)$service['price'] ? 'تأیید و پرداخت' : 'ثبت نوبت رایگان') : 'ورود و ادامهٔ رزرو' ?>
          </button>
          <p class="bk-note">
            <?= $me
                ? 'نوبت به مدت ' . fa_num(consult_hold_minutes()) . ' دقیقه برای شما رزرو می‌شود تا پرداخت را کامل کنید.'
                : 'در مرحلهٔ بعد با موبایل وارد/ثبت‌نام می‌شوید و مستقیم به همین مرحله برمی‌گردید.' ?>
            <br>تا <?= fa_num(consult_cancel_hours()) ?> ساعت قبل از جلسه، لغو آنلاین از
            <a class="lnk" href="<?= SITE_URL ?>/panel/consult.php">پنل کاربری</a> ممکن است.
          </p>
        </div>
      </div>
    </div>
  </div>

  <!-- پیام موفقیت رزرو رایگان -->
  <div class="bk-success" id="bkSuccess" style="display:none">
    <div class="fr-card">
      <div class="big">🎉</div>
      <h3>نوبت شما با موفقیت ثبت شد</h3>
      <p style="color:var(--muted);font-size:13.5px">پیامک تأیید برایتان ارسال شد؛ جزئیات نوبت در پنل کاربری‌تان است.</p>
      <a class="fr-btn" href="<?= SITE_URL ?>/panel/consult.php">نوبت‌های من ←</a>
    </div>
  </div>

  <?php if (!empty($service['description'])): ?>
  <div class="ev-main" style="margin-top:26px"><div class="ev-pad">
    <div class="ev-content"><?= fr_sanitize_html((string)$service['description']) ?></div>
  </div></div>
  <?php endif; ?>
</div>
<?php public_footer(); ?>
