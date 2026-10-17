<?php
/**
 * ============================================================================
 *  شروع تست روانشناسی — انتخاب نسخهٔ رایگان (تحلیل خلاصه) یا ویژه (تحلیل کامل)
 *  + ورود کد تخفیف + هدایت به درگاه زرین‌پال
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
require_once __DIR__ . '/../includes/commerce.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../core/Kavenegar.php';
require_once __DIR__ . '/../core/Zarinpal.php';
require_once __DIR__ . '/../core/TestFields.php';
fr_bootstrap();

TestFields::ensureSchema();

$u    = require_user();
$activeDyadToken = trim((string)($_GET['dyad_token'] ?? ($_SESSION['active_dyad_token'] ?? '')));
if ($activeDyadToken !== '') {
    $_SESSION['active_dyad_token'] = $activeDyadToken;
}

$dyadContext = null;
if ($activeDyadToken !== '') {
    $dyadContext = DB::one('SELECT d.*, u1.first_name AS u1_fn, u1.last_name AS u1_ln FROM ' . DB::t('dyads') . ' d JOIN ' . DB::t('users') . ' u1 ON u1.id=d.user1_id WHERE d.token=?', [$activeDyadToken]);
}
$isDyadGift = ($dyadContext && $dyadContext['pay_mode'] === 'self_paid' && (int)$dyadContext['partner_paid'] === 1);

$test = DB::one('SELECT * FROM ' . DB::t('tests') . " WHERE id=? AND status='active'", [(int)($_GET['id'] ?? 0)]);
if (!$test) redirect(fr_panel_url('tests.php'));
$err = '';

/* ادامهٔ سفارش پرداخت‌نشده */
if (isset($_GET['resume'])) {
    $pend = DB::one('SELECT * FROM ' . DB::t('orders') . " WHERE user_id=? AND test_id=? AND status='pending' ORDER BY id DESC", [$u['id'], $test['id']]);
    if ($pend) {
        $pay = DB::one('SELECT * FROM ' . DB::t('payments') . " WHERE order_id=? AND status='init' ORDER BY id DESC", [$pend['id']]);
        if ($pay) {
            $zp  = new Zarinpal();
            $res = $zp->request((int)$pend['id'], (int)$pend['payable'], 'ادامه پرداخت: ' . $test['title'], (string)$u['mobile']);
            if ($res['ok']) {
                DB::insert('payments', ['order_id'=>$pend['id'], 'authority'=>$res['authority'], 'amount'=>$pend['payable'],
                    'ip'=>client_ip_bin(), 'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
                redirect($res['url']);
            }
        }
    }
    redirect(fr_panel_url('test-start.php?id=' . $test['id']));
}

/* آیا جلسهٔ ناتمام دارد؟ */
$open = DB::one("SELECT a.*, o.status AS os FROM " . DB::t('attempts') . " a
                 LEFT JOIN " . DB::t('orders') . " o ON o.id=a.order_id
                 WHERE a.user_id=? AND a.test_id=? AND a.status='in_progress'
                 ORDER BY a.id DESC LIMIT 1", [$u['id'], $test['id']]);

/* آیا قبلاً این تست را انجام داده است؟ */
$completedFree = DB::one("SELECT * FROM " . DB::t('attempts') .
    " WHERE user_id=? AND test_id=? AND status='completed' ORDER BY id DESC LIMIT 1", [$u['id'], $test['id']]);

// اگر کاربر قبلاً نسخهٔ ویژه را خریداری و تکمیل کرده، مستقیماً به گزارش برود
if ($completedFree && $completedFree['mode'] === 'full') {
    if ($dyadContext && $dyadContext['status'] === 'pending' && (int)$dyadContext['user1_id'] !== (int)$u['id']) {
        require_once FR_ROOT . '/core/DyadAnalyzer.php';
        DyadAnalyzer::joinDyad($activeDyadToken, (int)$u['id'], (int)$completedFree['id']);
        flash('شما قبلاً این آزمون را کامل انجام داده بودید؛ کارنامه تطابق دونفره بر مبنای همان آزمون آماده شد 🎉', 'ok');
        redirect(fr_panel_url('dyad-report.php?id=' . $dyadContext['id']));
    }
    redirect(fr_panel_url('report.php?attempt=' . (int)$completedFree['id']));
}

// خودترمیمی: اگر جلسهٔ ناتمام خالی ناشی از پرداخت قبلی وجود دارد درحالی که تستی را تمام کرده، مستقیماً ارتقا و انتقال به گزارش
if ($open && $open['os'] === 'paid' && $completedFree) {
    $ansCount = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('answers') . " WHERE attempt_id=?", [$open['id']]);
    if ($ansCount === 0) {
        DB::run("DELETE FROM " . DB::t('attempts') . " WHERE id=?", [$open['id']]);
        DB::update('attempts', ['mode' => 'full', 'order_id' => $open['order_id']], 'id=?', [$completedFree['id']]);
        require_once __DIR__ . '/../core/Analyzer.php';
        Analyzer::scoreAttempt((int)$completedFree['id']);
        redirect(fr_panel_url('report.php?attempt=' . (int)$completedFree['id']));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string)($_POST['mode'] ?? 'free');

    // اعتبارسنجی فیلدهای سفارشی پیش از آزمون (pre_test) و اجرای منطق شرطی
    $tfValidation = TestFields::validateAndProcess((int)$test['id'], $mode, $_POST, 'pre_test');
    if (!$tfValidation['ok']) {
        $err = implode('<br>', $tfValidation['errors']);
    } else {
        $customFieldsJson = $tfValidation['json'];

        // استفاده از هدیه پرداخت‌شده توسط همراه
        if ($isDyadGift && ($mode === 'gift' || $mode === 'full')) {
            if ($completedFree) {
                $upData = ['mode' => 'full', 'order_id' => $dyadContext['order1_id']];
                if ($customFieldsJson !== null) $upData['custom_fields_json'] = $customFieldsJson;
                DB::update('attempts', $upData, 'id=?', [$completedFree['id']]);
                require_once __DIR__ . '/../core/Analyzer.php';
                Analyzer::scoreAttempt((int)$completedFree['id']);
                require_once __DIR__ . '/../core/DyadAnalyzer.php';
                DyadAnalyzer::joinDyad($activeDyadToken, (int)$u['id'], (int)$completedFree['id']);
                flash('آزمون شما با استفاده از هدیهٔ همراهتان به نسخه تخصصی ارتقا یافت! گزارش تطابق آماده است 🎉', 'ok');
                redirect(fr_panel_url('dyad-report.php?id=' . $dyadContext['id']));
            } else {
                $aid = DB::insert('attempts', [
                    'user_id'            => $u['id'],
                    'test_id'            => $test['id'],
                    'mode'               => 'full',
                    'order_id'           => $dyadContext['order1_id'],
                    'custom_fields_json' => $customFieldsJson,
                    'ip'                 => client_ip_bin(),
                    'user_agent'         => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
                log_activity('test_start', 'attempt', $aid, ['mode' => 'full', 'test' => $test['slug'], 'dyad_gift' => 1]);
                redirect(fr_panel_url('take.php?attempt=' . $aid));
            }
        }

        if ($mode === 'free') {
            if (!$test['free_enabled']) $err = 'نسخهٔ رایگان این تست روانشناسی غیرفعال است.';
            else {
                // هر کاربر فقط یک نسخهٔ رایگان از هر تست روانشناسی
                $hadFree = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') .
                    " WHERE user_id=? AND test_id=? AND mode='free'", [$u['id'], $test['id']]);
                if ($hadFree) $err = 'نسخهٔ رایگان هر تست روانشناسی فقط یک‌بار قابل انجام است؛ نسخهٔ ویژه را انتخاب کنید.';
                else {
                    $aid = DB::insert('attempts', [
                        'user_id'            => $u['id'],
                        'test_id'            => $test['id'],
                        'mode'               => 'free',
                        'custom_fields_json' => $customFieldsJson,
                        'ip'                 => client_ip_bin(),
                        'user_agent'         => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                    log_activity('test_start', 'attempt', $aid, ['mode'=>'free','test'=>$test['slug']]);
                    redirect(fr_panel_url('take.php?attempt=' . $aid));
                }
            }
        } else {
            $submittedCode  = trim((string)($_POST['discount_code'] ?? ''));
            $rawEnteredCode = trim((string)($_POST['raw_discount_code'] ?? ''));
            $code           = '';

            // بررسی دقیق الزام فشردن دکمه «اعمال» برای کد تخفیف
            if ($submittedCode !== '' || $rawEnteredCode !== '') {
                $targetCode = $submittedCode !== '' ? $submittedCode : $rawEnteredCode;
                $sessDiscount = $_SESSION['applied_discounts']['test_' . (int)$test['id']] ?? null;

                if (
                    !$sessDiscount ||
                    mb_strtolower((string)$sessDiscount['code']) !== mb_strtolower($targetCode) ||
                    (int)($sessDiscount['test_id'] ?? 0) !== (int)$test['id'] ||
                    (time() - (int)($sessDiscount['time'] ?? 0)) > 7200
                ) {
                    $err = 'برای استفاده از تخفیف، حتماً باید دکمهٔ «اعمال» کد تخفیف را بزنید.';
                } else {
                    $code = (string)$sessDiscount['code'];
                }
            }

            if (!$err) {
                $useWallet = !empty($_POST['use_wallet']);
                $upgradeAttemptId = (int)($_POST['upgrade_attempt'] ?? ($_GET['upgrade_attempt'] ?? 0));
                if ($upgradeAttemptId <= 0 && $completedFree) {
                    $upgradeAttemptId = (int)$completedFree['id'];
                }
                $r = create_full_order($u, $test, $code !== '' ? $code : null, $useWallet, $upgradeAttemptId > 0 ? $upgradeAttemptId : null, $customFieldsJson);
                if ($r['error']) $err = $r['error'];
                else {
                    unset($_SESSION['applied_discounts']['test_' . (int)$test['id']]);
                    if ($dyadContext && !empty($r['order']['id'])) {
                        DB::update('dyads', ['order2_id' => $r['order']['id']], 'id=?', [$dyadContext['id']]);
                    }
                    if ($r['payment_url']) {
                        redirect($r['payment_url']);          // → درگاه
                    } else {
                        if ($dyadContext && !empty($r['attempt_id'])) {
                            require_once __DIR__ . '/../core/DyadAnalyzer.php';
                            DyadAnalyzer::joinDyad($activeDyadToken, (int)$u['id'], (int)$r['attempt_id']);
                        }
                        $attRow = DB::one('SELECT status FROM ' . DB::t('attempts') . ' WHERE id=?', [(int)$r['attempt_id']]);
                        if ($attRow && $attRow['status'] === 'completed') {
                            if ($dyadContext) {
                                redirect(fr_panel_url('dyad-report.php?id=' . $dyadContext['id']));
                            }
                            redirect(fr_panel_url('report.php?attempt=' . (int)$r['attempt_id']));
                        } else {
                            redirect(fr_panel_url('take.php?attempt=' . (int)$r['attempt_id']));
                        }
                    }
                }
            }
        }
    }
}

/* آیا قبلاً نسخهٔ رایگان انجام داده؟ */
$freeDone = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') .
    " WHERE user_id=? AND test_id=? AND mode='free'", [$u['id'], $test['id']]);

render_header('شروع تست روانشناسی: ' . $test['title']);
panel_layout($u, 'tests');
if ($err) echo '<div class="fr-alert err">' . e($err) . '</div>';

/* بنر هنگام پر کردن فرم */
$bnn = DB::one("SELECT * FROM " . DB::t('banners') . " WHERE active=1 AND placement='test_form'
  AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW()) ORDER BY sort_order LIMIT 1");
?>
<?php if (!empty($_SESSION['active_dyad_token'])): ?>
<div class="fr-alert info" style="margin-bottom:16px">
  👥 <b>شرکت در سنجش تطابق دونفره:</b> پس از پاسخ به سوالات این آزمون، کارنامهٔ تطابق دونفرهٔ شما و همراهتان به صورت خودکار صادر خواهد شد.
</div>
<?php endif; ?>
<?php if ($open): ?>
<div class="fr-alert warn">
  شما یک جلسهٔ ناتمام از این تست روانشناسی دارید.
  <?php if ($open['mode']==='full' && $open['os']!=='paid'): ?>
    — <a href="<?= e(fr_panel_url('test-start.php?id=' . (int)$test['id'] . '&resume=1')) ?>"><b>💳 پرداخت سفارش معلق ←</b></a>
    | <a href="<?= e(fr_panel_url('abandon.php?attempt=' . (int)$open['id'] . '&back=' . urlencode($_SERVER['REQUEST_URI'] ?? ''))) ?>">انصراف و لغو</a>
  <?php else: ?>
    <a href="<?= e(fr_panel_url('take.php?attempt=' . (int)$open['id'])) ?>"><b>ادامه از سؤال <?= fa_num($open['progress'] + 1) ?> ←</b></a>
  | <a href="<?= e(fr_panel_url('abandon.php?attempt=' . (int)$open['id'] . '&back=' . urlencode($_SERVER['REQUEST_URI'] ?? ''))) ?>">انصراف و شروع مجدد</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($dyadContext):
  $inviterName = trim(($dyadContext['u1_fn'] ?? '') . ' ' . ($dyadContext['u1_ln'] ?? '')) ?: 'همراه شما';
?>
<div class="fr-card" style="background:<?= $isDyadGift ? '#f0fdf4' : '#faf5ff' ?>;border:2px solid <?= $isDyadGift ? '#22c55e' : '#8b5cf6' ?>;margin-bottom:20px;padding:16px 20px">
  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="font-size:36px"><?= $isDyadGift ? '🎁' : '👥' ?></div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:15.5px;color:<?= $isDyadGift ? '#166534' : '#5b21b6' ?>">
        <?= $isDyadGift ? 'هدیه ویژه آزمون از طرف «' . e($inviterName) . '»' : 'دعوت به سنجش تطابق دونفره توسط «' . e($inviterName) . '»' ?>
      </div>
      <p style="margin:4px 0 0;font-size:13px;color:<?= $isDyadGift ? '#15803d' : '#6b21a8' ?>;line-height:1.8">
        <?php if ($isDyadGift): ?>
          هزینهٔ نسخهٔ تخصصی و جامع این آزمون توسط همراه شما پرداخت شده است! شما می‌توانید بدون پرداخت هیچ هزینه‌ای، آزمون تخصصی را شروع کنید.
        <?php elseif ($dyadContext['pay_mode'] === 'partner_paid'): ?>
          همراه شما این آزمون را انجام داده و شما را به <b>نسخهٔ تخصصی و کامل</b> دعوت کرده است. با تهیهٔ نسخهٔ تخصصی، کارنامه تطابق دونفره فعال خواهد شد.
        <?php else: ?>
          شما به سنجش تطابق دونفره دعوت شده‌اید. با تکمیل نسخهٔ رایگان یا ویژه، تحلیل تطابق شما دو نفر آماده می‌شود.
        <?php endif; ?>
      </p>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="fr-card">
  <h3><?= e($test['title']) ?></h3>

  <?php
  $testCustomFields = TestFields::getPreTestFields((int)$test['id'], 'all');
  if (!empty($testCustomFields)):
  ?>
  <div class="fr-test-custom-fields-wrapper" style="margin-bottom:24px;padding:20px 22px;background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:14px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
      <span style="font-size:22px">📋</span>
      <div>
        <b style="font-size:15px;color:#1e3a8a">اطلاعات و گزینه‌های پیش از آزمون</b>
        <div style="font-size:12px;color:#64748b;margin-top:2px">لطفاً پیش از ثبت‌نام نهایی و شروع آزمون، گزینه‌های زیر را تعیین فرمایید:</div>
      </div>
    </div>
    <?= TestFields::renderFormFields($testCustomFields, (array)($_POST['test_custom_fields'] ?? [])) ?>
  </div>
  <?php endif; ?>

  <div class="fr-grid cols-2" style="margin-bottom:0">
    <div>
      <p style="font-size:14px;color:#2c3a5c;line-height:2"><?= nl2br(e($test['short_desc'] ?? '')) ?></p>
      <?php if (!empty($test['description'])): ?>
      <div style="font-size:13px;color:var(--muted);line-height:2.1;border-right:3px solid var(--line);padding-right:12px;margin:10px 0">
        <?= nl2br(e(trim(strip_tags((string)$test['description'])))) ?></div>
      <?php endif; ?>
      <p style="font-size:13px;color:var(--muted)">📝 <?= fa_num($test['question_count']) ?> سؤال · ⏱ حدود <?= fa_num($test['est_minutes']) ?> دقیقه</p>
      <?php if ($test['instructions']): ?>
      <div class="fr-alert info" style="font-size:13px"><?= nl2br(e($test['instructions'])) ?></div>
      <?php endif; ?>
      <?php if ($bnn): ?>
      <a href="<?= e($bnn['link'] ?: '#') ?>" style="display:block;margin-top:14px">
        <img src="<?= e($bnn['image']) ?>" alt="<?= e($bnn['title']) ?>" style="border-radius:14px">
      </a>
      <?php endif; ?>
    </div>

    <div>
      <!-- نسخه رایگان -->
      <div style="border:2px solid var(--line);border-radius:16px;padding:20px;margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <b>🎁 نسخه رایگان</b><span class="fr-chip info">تحلیل خلاصه</span>
        </div>
        <p style="font-size:13px;color:var(--muted);line-height:2;margin:10px 0">
          <?= nl2br(e(trim((string)($test['free_teaser'] ?? '')) !== ''
              ? $test['free_teaser']
              : 'همین حالا رایگان شروع کن و نسبت به خودت تصویر روشنی پیدا کن. این نسخه یک چشم‌انداز کلی به تو می‌دهد؛ ولی جادوی اصلی در نسخهٔ ویژه است: تحلیل عمیق همهٔ ابعاد، نمودارهای دقیق، نقشهٔ رشد قدم‌به‌قدم و گزارش کاملِ قابل چاپ.')) ?>
        </p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="mode" value="free">
          <button class="fr-btn ghost" style="width:100%;justify-content:center"
              <?= (!$test['free_enabled'] || $freeDone) ? 'disabled' : '' ?>>
            <?= $freeDone ? 'قبلاً انجام شده' : ($test['free_enabled'] ? 'شروع رایگان' : 'غیرفعال') ?>
          </button>
        </form>
      </div>

      <!-- نسخه ویژه -->
      <?php $pDeal = fr_price_deal((int)$test['price_full'], isset($test['price_sale']) ? (int)$test['price_sale'] : null); ?>
      <div style="border:2px solid var(--c-primary);border-radius:16px;padding:20px;background:linear-gradient(180deg,#faf8ff,#fff)">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <b>⭐ نسخه ویژه</b><span class="fr-chip purple">تحلیل کامل و تخصصی</span>
        </div>
        <ul style="font-size:13px;color:var(--muted);padding-right:18px;margin:10px 0">
          <li>تحلیل عمیق همهٔ ابعاد + الگوهای ترکیبی</li><li>گزارش PDF راست‌چین با برند شما</li>
          <li>پیشنهادات کاربردی و نقشه رشد</li><li>نسخهٔ چاپی + بارکد پیگیری</li>
        </ul>
        <div style="font-size:20px;font-weight:800;color:var(--c-primary);margin-bottom:12px" id="priceBox">
          <?php if ($pDeal['has_sale']): ?>
            <span style="display:inline-block;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800;margin-left:8px"><?= fa_num($pDeal['percent']) ?>٪ تخفیف</span>
            <del style="color:#94a3b8;font-size:14px;font-weight:500"><?= toman($pDeal['list']) ?></del>
          <?php endif; ?>
          <?= toman($pDeal['final']) ?>
        </div>
        <?php if ($isDyadGift): ?>
        <div class="fr-alert ok" style="font-size:13.5px;margin:12px 0;line-height:1.9;background:#dcfce7;border-color:#86efac;color:#166534">
          🎁 <b>هدیه فعال:</b> هزینه این نسخه توسط همراه شما پرداخت شده است.
        </div>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="mode" value="gift">
          <button class="fr-btn" style="width:100%;justify-content:center;background:#16a34a">
            <?= $completedFree ? '🎁 ارتقای رایگان به نسخه تخصصی و مشاهده تحلیل تطابق ←' : '🎁 شروع رایگان نسخه تخصصی (هدیه‌داده‌شده) ←' ?>
          </button>
        </form>
        <?php else: ?>
        <form method="post" id="fullForm">
          <?= csrf_field() ?>
          <input type="hidden" name="mode" value="full">
          <?php if ($completedFree): ?>
          <div class="fr-alert ok" style="font-size:12.5px;margin-bottom:12px;line-height:1.9">
            🎉 شما قبلاً این تست روانشناسی را انجام داده‌اید. با خرید نسخه ویژه، بلافاصله به <b>تحلیل کامل و تخصصی</b> تمام پاسخ‌هایتان دسترسی خواهید داشت (بدون نیاز به پاسخ‌گویی دوباره).
          </div>
          <input type="hidden" name="upgrade_attempt" value="<?= (int)$completedFree['id'] ?>">
          <?php endif; ?>
          <div style="background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:12px;padding:12px;margin-bottom:12px">
            <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:6px">🎟️ کد تخفیف:</label>
            <div style="display:flex;gap:8px">
              <input class="fr-input" id="dcCode" name="raw_discount_code" placeholder="کد تخفیف (اختیاری)" style="direction:ltr;text-align:right;flex:1" autocomplete="off">
              <input type="hidden" name="discount_code" id="appliedDiscountCode" value="">
              <button class="fr-btn ghost sm" type="button" id="btnDc" style="min-width:75px">اعمال</button>
            </div>
            <div id="dcMsg" style="font-size:12px;margin-top:8px"></div>
          </div>
          <?php $wBal = wallet_balance((int)$u['id']); if ($wBal > 0 && $pDeal['final'] > 0): ?>
          <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer;background:#fff;border:1.5px dashed #d4dcec;border-radius:12px;padding:9px 11px;margin-bottom:12px">
            <input type="checkbox" name="use_wallet" value="1" style="width:17px;height:17px">
            <span>👛 پرداخت با کیف پول (موجودی: <b style="color:var(--c-primary)"><?= toman($wBal) ?></b>)</span>
          </label>
          <?php endif; ?>
          <button class="fr-btn" style="width:100%;justify-content:center">
            <?= $pDeal['final'] === 0
                ? ($completedFree ? 'دریافت رایگان تحلیل ویژه' : 'شروع نسخه ویژه (رایگان)')
                : ($completedFree ? 'پرداخت و دریافت تحلیل ویژه ←' : 'پرداخت و شروع ←') ?>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
/* بررسی زنده و الزام فشردن دکمه «اعمال» کد تخفیف */
const dcInp = document.getElementById('dcCode');
const appliedDcInp = document.getElementById('appliedDiscountCode');
const btnDc = document.getElementById('btnDc');
const dcMsg = document.getElementById('dcMsg');
const priceBox = document.getElementById('priceBox');
let appliedCode = '';

if (dcInp && btnDc) {
  // اینتر داخل کادر تخفیف -> فعال‌سازی دکمه اعمال به‌جای سابمیت ناخواسته فرم
  dcInp.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      btnDc.click();
    }
  });

  // در صورت ویرایش متن کد پس از اعمال، وضعیت تخفیف لغو و پیام هشدار نمایش می‌یابد
  dcInp.addEventListener('input', () => {
    const cur = dcInp.value.trim();
    if (appliedCode && cur !== appliedCode) {
      appliedCode = '';
      appliedDcInp.value = '';
      dcMsg.innerHTML = '<span style="color:#d97706">⚠️ متن کد تغییر کرد؛ برای محاسبهٔ تخفیف حتماً دکمه «اعمال» را بزنید.</span>';
      if (priceBox && priceBox.dataset.original) {
        priceBox.innerHTML = priceBox.dataset.original;
      }
    } else if (!cur) {
      appliedCode = '';
      appliedDcInp.value = '';
      dcMsg.innerHTML = '';
      if (priceBox && priceBox.dataset.original) {
        priceBox.innerHTML = priceBox.dataset.original;
      }
    }
  });

  // ذخیره حالت اولیه باکس قیمت
  if (priceBox && !priceBox.dataset.original) {
    priceBox.dataset.original = priceBox.innerHTML;
  }

  btnDc.onclick = async () => {
    const code = dcInp.value.trim();
    if (!code) {
      appliedCode = '';
      appliedDcInp.value = '';
      dcMsg.innerHTML = '<span style="color:#64748b">لطفاً کد تخفیف را وارد کنید و دکمه «اعمال» را بزنید.</span>';
      if (priceBox && priceBox.dataset.original) priceBox.innerHTML = priceBox.dataset.original;
      await api('../api/discount.php', {code: '', test_id: <?= (int)$test['id'] ?>, act: 'clear'});
      return;
    }
    btnDc.disabled = true;
    dcMsg.innerHTML = '<span style="color:#64748b">⏳ در حال اعتبارسنجی کد تخفیف...</span>';
    try {
      const r = await api('../api/discount.php', {code, test_id: <?= (int)$test['id'] ?>});
      if (r.ok) {
        appliedCode = code;
        appliedDcInp.value = code;
        dcMsg.innerHTML = '<span style="color:#0c7a4d;font-weight:700">✅ ' + (r.message || 'کد تخفیف اعمال شد.') + '</span>';
        if (priceBox) {
          priceBox.innerHTML = '<s style="color:#a49ec7;font-size:15px">' + r.amount_f + '</s> &nbsp; <span style="color:#0c7a4d;font-weight:800">' + r.payable_f + '</span>';
        }
      } else {
        appliedCode = '';
        appliedDcInp.value = '';
        dcMsg.innerHTML = '<span style="color:#b0262c;font-weight:700">❌ ' + (r.message || 'کد تخفیف نامعتبر است.') + '</span>';
        if (priceBox && priceBox.dataset.original) priceBox.innerHTML = priceBox.dataset.original;
      }
    } catch (e) {
      dcMsg.innerHTML = '<span style="color:#b0262c">خطا در ارتباط با سرور.</span>';
    } finally {
      btnDc.disabled = false;
    }
  };
}

// جلوگیری قطعی از ثبت فرم در صورتی که کاربر کد را تایپ کرده ولی دکمه اعمال را نزده باشد
document.getElementById('fullForm')?.addEventListener('submit', (e) => {
  if (dcInp) {
    const typed = dcInp.value.trim();
    if (typed !== '' && appliedCode === '') {
      e.preventDefault();
      dcMsg.innerHTML = '<span style="display:inline-block;background:#fee2e2;color:#991b1b;padding:5px 9px;border-radius:6px;font-weight:700">⚠️ شما کد تخفیف را وارد کرده‌اید اما دکمه «اعمال» را انتخاب نکرده‌اید! جهت بهره‌مندی از تخفیف ابتدا دکمه «اعمال» را بزنید یا کادر را پاک کنید.</span>';
      btnDc.focus();
      dcInp.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }
  }
});

// همگام‌سازی و تزریق داده‌های فیلدهای پیش از آزمون در هنگام ثبت هر یک از فرم‌ها (رایگان / ویژه / هدیه)
document.querySelectorAll('form').forEach(f => {
  f.addEventListener('submit', function() {
    const box = document.getElementById('testCustomFieldsBox');
    if (!box || f.contains(box)) return;
    f.querySelectorAll('.tf-injected-field').forEach(el => el.remove());
    box.querySelectorAll('input, select, textarea').forEach(inp => {
      if (inp.disabled) return;
      if ((inp.type === 'checkbox' || inp.type === 'radio') && !inp.checked) return;
      const h = document.createElement('input');
      h.type = 'hidden';
      h.className = 'tf-injected-field';
      h.name = inp.name;
      h.value = inp.value;
      f.appendChild(h);
    });
  });
});
</script>
<?php panel_layout_end(); render_footer(); ?>
