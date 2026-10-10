<?php
/**
 * ============================================================================
 *  مرکز تطابق دونفره و زوجین فریا روان (Couples & Dyadic Hub)
 *  ─ ایجاد پیوند جدید با ۳ مدل تجاری و مالی (رایگان، هدیه پرداخت‌شده توسط شما، پرداخت با همراه)
 *  ─ ارسال پیامک خودکار هوشمند، درگاه پرداخت زرین‌پال و کیف پول، اتصال خودکار
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
require_once __DIR__ . '/../core/DyadAnalyzer.php';
fr_bootstrap();

$u = require_user();
fr_panel_guard('history');

$action = (string)($_GET['action'] ?? '');
$token  = trim((string)($_GET['token'] ?? ''));

// ورود مستقیم با توکن دعوت
if ($token !== '') {
    $res = DyadAnalyzer::joinDyad($token, (int)$u['id']);
    if ($res['ok']) {
        if ($res['status'] === 'ready') {
            redirect(fr_panel_url('dyad-report.php?id=' . $res['dyad_id']));
        } elseif ($res['status'] === 'waiting_partner') {
            flash('شما ایجادکننده این پیوند هستید؛ این پیوند در انتظار تکمیل آزمون توسط همراه شما است ⏳', 'info');
            redirect(fr_panel_url('dyad.php'));
        } elseif (!empty($res['redirect_url'])) {
            redirect($res['redirect_url']);
        }
    } else {
        flash($res['message'] ?? 'خطا در اتصال به پیوند دعوت.', 'err');
        redirect(fr_panel_url('dyad.php'));
    }
}

// ارسال مجدد پیامک دعوت
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'resend_sms') {
    csrf_guard();
    $dyadId = (int)post('dyad_id');
    $dy = DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE id=? AND user1_id=?', [$dyadId, $u['id']]);
    if ($dy && !empty($dy['partner_mobile'])) {
        try {
            $test = DB::one('SELECT title FROM ' . DB::t('tests') . ' WHERE id=?', [$dy['test_id']]);
            $u1Name = user_fullname($u);
            $pName = $dy['partner_name'] ?: 'همراه گرامی';
            $inviteUrl = fr_panel_url('dyad.php?token=' . $dy['token']);

            $sms = new Kavenegar();
            if ($dy['pay_mode'] === 'self_paid') {
                $smsText = "{$pName} عزیز، یادآوری آزمون سنجش تطابق دونفره ({$test['title']}). هزینه نسخه تخصصی توسط {$u1Name} برای شما هدیه و پرداخت شده است.\nشروع آزمون هدیه:\n{$inviteUrl}";
            } elseif ($dy['pay_mode'] === 'partner_paid') {
                $smsText = "{$pName} عزیز، یادآوری دعوت {$u1Name} به آزمون سنجش تطابق دونفره ({$test['title']} - نسخهٔ تخصصی) در فریا روان.\nمشاهده و شروع:\n{$inviteUrl}";
            } else {
                $smsText = "{$pName} عزیز، یادآوری دعوت {$u1Name} برای سنجش تطابق دونفره ({$test['title']}) در فریا روان.\nمشاهده و شروع:\n{$inviteUrl}";
            }
            $sms->sendDirect($dy['partner_mobile'], $smsText);
            flash('پیامک یادآوری دعوت مجدداً ارسال شد ✅', 'ok');
        } catch (Throwable $e) {
            flash('خطا در ارسال پیامک: ' . $e->getMessage(), 'err');
        }
    } else {
        flash('شماره همراه ثبت‌نشده یا پیوند نامعتبر است.', 'err');
    }
    redirect(fr_panel_url('dyad.php'));
}

// لغو / حذف دعوت‌نامه معلق
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'cancel_dyad') {
    csrf_guard();
    $dyadId = (int)post('dyad_id');
    $dy = DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE id=? AND user1_id=? AND status="pending"', [$dyadId, $u['id']]);
    if ($dy) {
        if (!empty($dy['order1_id'])) {
            fr_cancel_dead_order((int)$dy['order1_id'], 'لغو توسط کاربر');
        }
        DB::run('DELETE FROM ' . DB::t('dyads') . ' WHERE id=?', [$dyadId]);
        flash('دعوت‌نامه معلق با موفقیت لغو و حذف گردید.', 'ok');
    } else {
        flash('امکان لغو این دعوت وجود ندارد.', 'err');
    }
    redirect(fr_panel_url('dyad.php'));
}

// پرداخت مجدد هدیه سنجش تطابق
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'pay_dyad_gift') {
    csrf_guard();
    $dyadId = (int)post('dyad_id');
    $dy = DB::one('SELECT d.*, t.title AS test_title, t.slug AS test_slug, t.price_full, t.price_sale
                   FROM ' . DB::t('dyads') . ' d
                   JOIN ' . DB::t('tests') . ' t ON t.id=d.test_id
                   WHERE d.id=? AND d.user1_id=? AND d.pay_mode="self_paid" AND d.partner_paid=0', [$dyadId, $u['id']]);
    if ($dy) {
        $deal = fr_price_deal((int)$dy['price_full'], isset($dy['price_sale']) ? (int)$dy['price_sale'] : null);
        $amount = $deal['final'];

        $orderId = (int)$dy['order1_id'];
        if (!$orderId) {
            $orderId = (int)DB::insert('orders', [
                'user_id'    => $u['id'],
                'test_id'    => $dy['test_id'],
                'kind'       => 'test_full',
                'amount'     => $amount,
                'payable'    => $amount,
                'status'     => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            DB::update('dyads', ['order1_id' => $orderId], 'id=?', [$dyadId]);
        }

        $useWallet = !empty($_POST['use_wallet']);
        $payable = $amount;

        if ($useWallet) {
            $wp = wallet_pay_part($orderId, (int)$u['id'], $payable, 'هدیه نسخه تخصصی سنجش تطابق به ' . ($dy['partner_name'] ?: 'همراه'));
            if ($wp['gateway'] <= 0) {
                mark_order_paid($orderId, 'WALLET-' . fr_token(8), null);
                flash('هزینه نسخه تخصصی همراه با موفقیت از کیف پول پرداخت شد و دعوت‌نامه ارسال گردید 🎁', 'ok');
                redirect(fr_panel_url('dyad.php'));
            }
            $payable = $wp['gateway'];
        }

        $zp = new Zarinpal();
        $desc = 'هدیه نسخه تخصصی سنجش تطابق برای ' . ($dy['partner_name'] ?: 'همراه') . ' (' . $dy['test_title'] . ')';
        $zpRes = $zp->request($orderId, $payable, $desc, (string)$u['mobile']);
        if (!$zpRes['ok']) {
            flash('خطا در اتصال به درگاه پرداخت: ' . $zpRes['message'], 'err');
            redirect(fr_panel_url('dyad.php'));
        }

        DB::insert('payments', [
            'order_id'   => $orderId,
            'authority'  => $zpRes['authority'],
            'amount'     => $payable,
            'ip'         => client_ip_bin(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
        redirect($zpRes['url']);
    } else {
        flash('پیوند مورد نظر یافت نشد یا قبلاً پرداخت شده است.', 'err');
        redirect(fr_panel_url('dyad.php'));
    }
}

// ساخت پیوند جدید
$createdDyad = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create_dyad') {
    csrf_guard();
    $attemptId     = (int)post('attempt_id');
    $relType       = (string)post('rel_type');
    $partnerName   = trim((string)post('partner_name'));
    $partnerMobile = clean_mobile((string)post('partner_mobile'));
    $payMode       = (string)post('pay_mode');
    if (!in_array($payMode, ['free', 'self_paid', 'partner_paid'], true)) {
        $payMode = 'free';
    }

    if ($partnerName === '') {
        flash('لطفاً نام همراه (همسر / پارتنر / همکار) را وارد نمایید.', 'err');
    } elseif ($partnerMobile !== '' && !valid_mobile($partnerMobile)) {
        flash('شماره همراه وارد شده نامعتبر است (باید ۱۱ رقم و با ۰۹ شروع شود).', 'err');
    } else {
        $at = DB::one('SELECT a.*, t.title AS test_title, t.price_full, t.price_sale, t.slug
                       FROM ' . DB::t('attempts') . ' a
                       JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
                       WHERE a.id=? AND a.user_id=? AND a.status="completed"', [$attemptId, $u['id']]);
        if (!$at) {
            flash('جلسهٔ آزمون انتخاب‌شده نامعتبر است.', 'err');
        } else {
            if ($payMode === 'self_paid') {
                $deal = fr_price_deal((int)$at['price_full'], isset($at['price_sale']) ? (int)$at['price_sale'] : null);
                $amount = $deal['final'];

                if ($amount === 0) {
                    $res = DyadAnalyzer::createDyad((int)$u['id'], $attemptId, $relType, $partnerName, $partnerMobile, 'self_paid', null);
                    DB::update('dyads', ['partner_paid' => 1], 'id=?', [$res['dyad_id']]);
                    flash('دعوت‌نامه با نسخهٔ تخصصی (رایگان) با موفقیت صادر شد و پیامک برای همراه ارسال گردید 🎁', 'ok');
                    $createdDyad = $res;
                } else {
                    $useWallet = !empty($_POST['use_wallet']);
                    $payable = $amount;
                    $orderId = (int)DB::insert('orders', [
                        'user_id'    => $u['id'],
                        'test_id'    => $at['test_id'],
                        'kind'       => 'test_full',
                        'amount'     => $amount,
                        'payable'    => $payable,
                        'status'     => 'pending',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);

                    $res = DyadAnalyzer::createDyad((int)$u['id'], $attemptId, $relType, $partnerName, $partnerMobile, 'self_paid', $orderId);

                    if ($useWallet) {
                        $wp = wallet_pay_part($orderId, (int)$u['id'], $payable, 'هدیه نسخه تخصصی سنجش تطابق به ' . $partnerName);
                        if ($wp['gateway'] <= 0) {
                            mark_order_paid($orderId, 'WALLET-' . fr_token(8), null);
                            flash('هزینه نسخه تخصصی همراه با موفقیت از کیف پول پرداخت شد و دعوت‌نامه ارسال گردید 🎁', 'ok');
                            redirect(fr_panel_url('dyad.php'));
                        }
                        $payable = $wp['gateway'];
                    }

                    // هدایت به درگاه زرین‌پال
                    $zp = new Zarinpal();
                    $desc = 'هدیه نسخه تخصصی سنجش تطابق برای ' . $partnerName . ' (' . $at['test_title'] . ')';
                    $zpRes = $zp->request($orderId, $payable, $desc, (string)$u['mobile']);
                    if (!$zpRes['ok']) {
                        fr_cancel_dead_order($orderId, 'خطای اتصال به درگاه');
                        flash('خطا در اتصال به درگاه پرداخت: ' . $zpRes['message'], 'err');
                    } else {
                        DB::insert('payments', [
                            'order_id'   => $orderId,
                            'authority'  => $zpRes['authority'],
                            'amount'     => $payable,
                            'ip'         => client_ip_bin(),
                            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                        ]);
                        redirect($zpRes['url']);
                    }
                }
            } else {
                // free یا partner_paid
                $res = DyadAnalyzer::createDyad((int)$u['id'], $attemptId, $relType, $partnerName, $partnerMobile, $payMode, null);
                if ($res['ok']) {
                    if ($payMode === 'partner_paid') {
                        $msg = 'پیوند دعوت تخصصی با موفقیت ایجاد شد! همراه شما پس از ورود به لینک، به ارتقا و انجام نسخهٔ تخصصی هدایت می‌گردد.';
                    } else {
                        $msg = 'پیوند دعوت تطابق با موفقیت ایجاد شد!';
                    }
                    if (!empty($partnerMobile)) {
                        $msg .= ' پیامک دعوت نیز برای همراه شما ارسال گردید ✉️';
                    }
                    flash($msg, 'ok');
                    $createdDyad = $res;
                } else {
                    flash($res['message'] ?? 'خطا در ایجاد پیوند تطابق.', 'err');
                }
            }
        }
    }
}

// فهرست آزمون‌های تکمیل‌شدهٔ کاربر
$completedAttempts = DB::all("
    SELECT a.id, a.test_id, a.mode, a.completed_at, t.title AS test_title, t.slug, t.engine, t.price_full, t.price_sale
    FROM " . DB::t('attempts') . " a
    JOIN " . DB::t('tests') . " t ON t.id=a.test_id
    WHERE a.user_id=? AND a.status='completed'
    ORDER BY a.id DESC
", [$u['id']]);

// پیوندهای دونفرهٔ کاربر
$myDyads = DB::all("
    SELECT d.*, t.title AS test_title,
           u1.first_name AS u1_fn, u1.last_name AS u1_ln, u1.mobile AS u1_mobile,
           u2.first_name AS u2_fn, u2.last_name AS u2_ln, u2.mobile AS u2_mobile
    FROM " . DB::t('dyads') . " d
    JOIN " . DB::t('tests') . " t ON t.id=d.test_id
    LEFT JOIN " . DB::t('users') . " u1 ON u1.id=d.user1_id
    LEFT JOIN " . DB::t('users') . " u2 ON u2.id=d.user2_id
    WHERE d.user1_id=? OR d.user2_id=?
    ORDER BY d.id DESC
", [$u['id'], $u['id']]);

$reqAttemptId = (int)($_GET['attempt'] ?? 0);
$wBal = wallet_balance((int)$u['id']);

render_header('تحلیل تطابق دونفره و زوجین');
panel_layout($u, 'history');
?>
<div style="max-width:920px;margin:0 auto">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="font-size:22px;margin:0;color:var(--ink)">👥 سنجش تطابق دونفره و زوجین</h2>
      <p style="font-size:13.5px;color:var(--muted);margin:4px 0 0">
        مقایسه عمیق شخصیت، شناسایی کانون‌های هم‌افزایی، ریشه‌یابی اصطکاک‌ها و پروتکل گفت‌وگوی امن
      </p>
    </div>
    <a class="fr-btn ghost sm" href="history.php">→ بازگشت به تاریخچه تست‌ها</a>
  </div>

  <?php if ($createdDyad): ?>
  <div class="fr-card" style="background:#f5f3ff;border:2px dashed #8b5cf6;margin-bottom:28px;text-align:center;padding:24px">
    <div style="font-size:36px;margin-bottom:8px">💌</div>
    <h3 style="margin:0 0 8px;color:#5b21b6">لینک دعوت شما برای «<?= e($createdDyad['partner_name'] ?: 'همراه شما') ?>» آماده است!</h3>
    <p style="font-size:13.5px;color:#4c1d95;margin-bottom:16px">
      <?php if (($createdDyad['pay_mode'] ?? '') === 'self_paid'): ?>
        ✨ هزینهٔ نسخهٔ تخصصی برای همراه شما پرداخت گردید. همراه شما بدون پرداخت هیچ هزینه‌ای می‌تواند آزمون کامل را انجام دهد.
      <?php elseif (($createdDyad['pay_mode'] ?? '') === 'partner_paid'): ?>
        ⭐ دعوت‌نامهٔ نسخهٔ تخصصی صادر شد؛ همراه شما با باز کردن لینک می‌تواند نسخهٔ کامل را تهیه نماید.
      <?php else: ?>
        لینک اختصاصی زیر را کپی کرده و در پیام‌رسان‌ها برای ایشان ارسال کنید:
      <?php endif; ?>
    </p>
    <div style="display:flex;gap:8px;max-width:540px;margin:0 auto 16px">
      <input type="text" readonly id="dyadLinkInput" value="<?= e($createdDyad['invite_url']) ?>" class="fr-input" style="direction:ltr;text-align:left;font-family:monospace;background:#fff">
      <button class="fr-btn" type="button" onclick="navigator.clipboard.writeText(document.getElementById('dyadLinkInput').value);toast('لینک دعوت با موفقیت کپی شد! ✅','ok')">کپی لینک</button>
    </div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <a class="fr-btn sm ghost" target="_blank" href="https://api.whatsapp.com/send?text=<?= urlencode("سلام " . ($createdDyad['partner_name'] ?: '') . "! من آزمون روانشناسی را در فریا روان انجام دادم. با این لینک بیا تا سازگاری و تطابق شخصیتمان را بسنجیم:
" . $createdDyad['invite_url']) ?>">ارسال در واتس‌اپ 💬</a>
      <a class="fr-btn sm ghost" target="_blank" href="https://t.me/share/url?url=<?= urlencode($createdDyad['invite_url']) ?>&text=<?= urlencode("سلام! بیا با این لینک سازگاری و تطابق رفتاری‌مان را در فریا روان بسنجیم 🦢") ?>">ارسال در تلگرام ✈️</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- فرم ایجاد پیوند جدید همراه با دریافت نام، موبایل و انتخاب مدل مالی -->
  <div class="fr-card" style="margin-bottom:32px">
    <h3 style="margin:0 0 16px;font-size:16px">➕ ایجاد پیوند تطابق جدید</h3>
    <?php if (empty($completedAttempts)): ?>
      <p style="color:var(--muted);font-size:13.5px">شما هنوز هیچ آزمون تکمیل‌شده‌ای ندارید. ابتدا یکی از آزمون‌ها را انجام دهید.</p>
      <a class="fr-btn sm" href="index.php">مشاهدهٔ آزمون‌ها ←</a>
    <?php else: ?>
      <form method="post" id="dyadCreateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="create_dyad">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;margin-bottom:18px">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">آزمون مبنای مقایسه:</label>
            <select name="attempt_id" id="attemptSelect" class="fr-input" required onchange="updateDyadPricing()">
              <?php foreach ($completedAttempts as $ca):
                $pDeal = fr_price_deal((int)$ca['price_full'], isset($ca['price_sale']) ? (int)$ca['price_sale'] : null);
              ?>
                <option value="<?= $ca['id'] ?>" data-price="<?= (int)$pDeal['final'] ?>" <?= ($reqAttemptId === (int)$ca['id']) ? 'selected' : '' ?>>
                  <?= e($ca['test_title']) ?> (ثبت: <?= fa_num(jdate('Y/m/d', strtotime($ca['completed_at'] ?? 'now'))) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">نوع رابطه و هدف ارزیابی:</label>
            <select name="rel_type" class="fr-input" required>
              <?php foreach (DyadAnalyzer::$REL_TYPES as $k => $v): ?>
                <option value="<?= $k ?>"><?= e($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">نام و نام خانوادگی همراه (همسر / پارتنر / همکار): <span style="color:red">*</span></label>
            <input type="text" name="partner_name" class="fr-input" placeholder="مثلاً: سارا محمدی" required>
          </div>
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">شماره موبایل همراه (جهت ارسال پیامک دعوت):</label>
            <input type="tel" name="partner_mobile" class="fr-input" dir="ltr" style="text-align:left" placeholder="۰۹۱۲۳۴۵۶۷۸۹">
            <small style="color:var(--muted);font-size:11.5px;display:block;margin-top:3px">اختیاری؛ در صورت ورود، لینک دعوت اختصاصی فوراً پیامک می‌شود.</small>
          </div>
        </div>

        <!-- انتخاب مدل مالی و نوع طرح سنجش تطابق -->
        <div style="margin-bottom:20px">
          <label style="display:block;font-size:14px;font-weight:700;margin-bottom:10px;color:var(--ink)">
            💳 شیوه پرداخت و مدل ارزیابی سنجش تطابق:
          </label>
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:14px">
            <!-- گزینه ۱: رایگان -->
            <label style="display:block;border:2px solid var(--border);border-radius:12px;padding:16px;cursor:pointer;background:#fff;transition:border-color 0.2s" class="pay-card">
              <div style="display:flex;align-items:flex-start;gap:10px">
                <input type="radio" name="pay_mode" value="free" checked style="margin-top:4px" onchange="onPayModeChange()">
                <div style="flex:1">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <b style="font-size:14px;color:var(--ink)">🆓 سنجش پایه و رایگان</b>
                    <span class="fr-badge info" style="font-size:11px">۰ تومان</span>
                  </div>
                  <p style="font-size:12.5px;color:var(--muted);margin:0;line-height:1.7">
                    همراه نسخه رایگان آزمون را انجام می‌دهد و گزارش خلاصهٔ تطابق دونفره بدون هزینه تولید می‌گردد.
                  </p>
                </div>
              </div>
            </label>

            <!-- گزینه ۲: پرداخت هزینه توسط شما به عنوان هدیه -->
            <label style="display:block;border:2px solid #8b5cf6;border-radius:12px;padding:16px;cursor:pointer;background:#faf5ff;transition:border-color 0.2s" class="pay-card">
              <div style="display:flex;align-items:flex-start;gap:10px">
                <input type="radio" name="pay_mode" value="self_paid" style="margin-top:4px" onchange="onPayModeChange()">
                <div style="flex:1">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <b style="font-size:14px;color:#6d28d9">🎁 هدیه نسخه تخصصی توسط شما</b>
                    <span class="fr-badge ok" id="giftPriceBadge" style="font-size:11px">هدیه ویژه</span>
                  </div>
                  <p style="font-size:12.5px;color:#5b21b6;margin:0;line-height:1.7">
                    شما هزینهٔ نسخهٔ تخصصی همراهتان را پرداخت می‌کنید؛ همراه بدون پرداخت وجه نسخه کامل را انجام داده و کارنامه جامع تطابق آزاد می‌شود.
                  </p>
                </div>
              </div>
            </label>

            <!-- گزینه ۳: پرداخت نسخه تخصصی توسط همراه -->
            <label style="display:block;border:2px solid var(--border);border-radius:12px;padding:16px;cursor:pointer;background:#fff;transition:border-color 0.2s" class="pay-card">
              <div style="display:flex;align-items:flex-start;gap:10px">
                <input type="radio" name="pay_mode" value="partner_paid" style="margin-top:4px" onchange="onPayModeChange()">
                <div style="flex:1">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <b style="font-size:14px;color:var(--ink)">⭐ نسخه تخصصی (پرداخت با همراه)</b>
                    <span class="fr-badge warn" style="font-size:11px">پرداخت طرف دوم</span>
                  </div>
                  <p style="font-size:12.5px;color:var(--muted);margin:0;line-height:1.7">
                    دعوت‌نامه نسخه تخصصی ارسال می‌شود و همراه شما پس از باز کردن لینک، هزینهٔ نسخهٔ تخصصی آزمون خود را پرداخت می‌نماید.
                  </p>
                </div>
              </div>
            </label>
          </div>

          <!-- بخش پرداخت از کیف پول در صورت انتخاب هدیه -->
          <div id="walletGiftOption" style="display:none;margin-top:14px;background:#f8fafc;border:1px dashed #cbd5e1;padding:12px 16px;border-radius:10px">
            <?php if ($wBal > 0): ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin:0">
                <input type="checkbox" name="use_wallet" value="1" style="width:17px;height:17px">
                <span>👛 پرداخت هزینه هدیه از موجودی کیف پول (موجودی فعلی: <b><?= toman($wBal) ?></b>)</span>
              </label>
            <?php else: ?>
              <span style="font-size:12.5px;color:var(--muted)">موجودی کیف پول شما صفر است؛ پس از ثبت به درگاه بانکی منتقل خواهید شد.</span>
            <?php endif; ?>
          </div>
        </div>

        <button class="fr-btn" id="submitDyadBtn" style="background:#6d28d9">ایجاد و ارسال دعوت‌نامه تطابق ←</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- فهرست پیوندهای فعال و آماده -->
  <div class="fr-card">
    <h3 style="margin:0 0 16px;font-size:16px">📋 گزارش‌های تطابق من</h3>
    <?php if (empty($myDyads)): ?>
      <p style="color:var(--muted);font-size:13.5px;margin:0">هنوز هیچ پیوند تطابقی ثبت نشده است.</p>
    <?php else: ?>
      <div style="display:grid;gap:12px">
        <?php foreach ($myDyads as $dy):
          $p1Name = trim(($dy['u1_fn'] ?? '') . ' ' . ($dy['u1_ln'] ?? '')) ?: 'کاربر ۱';
          $p2Name = trim(($dy['u2_fn'] ?? '') . ' ' . ($dy['u2_ln'] ?? ''));
          if ($p2Name === '') {
              $p2Name = !empty($dy['partner_name']) ? ($dy['partner_name'] . ' (در انتظار شرکت)') : 'در انتظار پاسخ همراه...';
          }
          $isReady = $dy['status'] === 'ready';
          $isOwner = (int)$dy['user1_id'] === (int)$u['id'];
          $payMode = $dy['pay_mode'] ?? 'free';
          $partnerPaid = (int)($dy['partner_paid'] ?? 0);
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px;border:1px solid var(--border);border-radius:12px;flex-wrap:wrap;gap:12px;background:<?= $isReady ? '#faf5ff' : '#fff' ?>">
          <div>
            <div style="font-weight:700;font-size:15px;color:var(--ink)">
              <?= e($dy['test_title']) ?>
              <span style="font-size:11.5px;font-weight:400;background:var(--border);padding:3px 8px;border-radius:6px;margin-right:6px">
                <?= e(DyadAnalyzer::$REL_TYPES[$dy['rel_type']] ?? 'دونفره') ?>
              </span>
              <!-- برچسب مدل مالی -->
              <?php if ($payMode === 'self_paid'): ?>
                <?php if ($partnerPaid === 1): ?>
                  <span class="fr-badge ok" style="font-size:11px;margin-right:6px">🎁 هدیه پرداخت‌شده</span>
                <?php else: ?>
                  <span class="fr-badge warn" style="font-size:11px;margin-right:6px">🎁 هدیه (در انتظار پرداخت)</span>
                <?php endif; ?>
              <?php elseif ($payMode === 'partner_paid'): ?>
                <span class="fr-badge" style="background:#e0e7ff;color:#3730a3;font-size:11px;margin-right:6px">⭐ تخصصی (پرداخت با همراه)</span>
              <?php else: ?>
                <span class="fr-badge info" style="font-size:11px;margin-right:6px">نسخه پایه</span>
              <?php endif; ?>
            </div>
            <div style="font-size:13px;color:var(--muted);margin-top:6px">
              👤 <?= e($p1Name) ?> &nbsp; ↔ &nbsp; 👥 <?= e($p2Name) ?>
              <?php if (!empty($dy['partner_mobile'])): ?>
                &nbsp;·&nbsp; 📱 <?= fa_num($dy['partner_mobile']) ?>
              <?php endif; ?>
            </div>
            <?php if ($isReady && $dy['synergy_score']): ?>
              <div style="margin-top:6px;font-size:12.5px;color:#6d28d9;font-weight:700">
                ✨ شاخص هم‌افزایی رابطه: <?= fa_num($dy['synergy_score']) ?>٪
              </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?php if ($isReady): ?>
              <a class="fr-btn sm" style="background:#6d28d9" href="dyad-report.php?id=<?= $dy['id'] ?>">مشاهده تحلیل تطابق ←</a>
            <?php else: ?>
              <?php if ($isOwner && $payMode === 'self_paid' && $partnerPaid === 0): ?>
                <!-- پرداخت نشده هدیه -->
                <form method="post" style="margin:0;display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="pay_dyad_gift">
                  <input type="hidden" name="dyad_id" value="<?= (int)$dy['id'] ?>">
                  <button class="fr-btn sm ok" type="submit">تکمیل پرداخت هدیه (درگاه) 💳</button>
                </form>
              <?php else: ?>
                <button class="fr-btn sm ghost" type="button" onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/panel/dyad.php?token=<?= $dy['token'] ?>');toast('لینک دعوت کپی شد! ✅','ok')">کپی لینک دعوت</button>
                <?php if ($isOwner && !empty($dy['partner_mobile'])): ?>
                  <form method="post" style="margin:0;display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="resend_sms">
                    <input type="hidden" name="dyad_id" value="<?= (int)$dy['id'] ?>">
                    <button class="fr-btn sm ghost" type="submit">ارسال مجدد پیامک ✉️</button>
                  </form>
                <?php endif; ?>
                <span class="fr-badge warn" style="font-size:12px;padding:6px 10px">در انتظار همراه</span>
              <?php endif; ?>

              <?php if ($isOwner): ?>
                <form method="post" style="margin:0;display:inline" onsubmit="return confirm('آیا از لغو و حذف این پیوند دعوت اطمینان دارید؟')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="cancel_dyad">
                  <input type="hidden" name="dyad_id" value="<?= (int)$dy['id'] ?>">
                  <button class="fr-btn sm ghost" style="color:#b91c1c;border-color:#fca5a5" type="submit">لغو</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function updateDyadPricing() {
  const sel = document.getElementById('attemptSelect');
  if (!sel) return;
  const opt = sel.options[sel.selectedIndex];
  const price = opt ? parseInt(opt.getAttribute('data-price') || '0', 10) : 0;
  const badge = document.getElementById('giftPriceBadge');
  if (badge) {
    if (price > 0) {
      badge.textContent = price.toLocaleString('fa-IR') + ' تومان';
    } else {
      badge.textContent = 'رایگان';
    }
  }
}

function onPayModeChange() {
  const radios = document.getElementsByName('pay_mode');
  let selected = 'free';
  for (let r of radios) {
    if (r.checked) selected = r.value;
  }
  const walletDiv = document.getElementById('walletGiftOption');
  const btn = document.getElementById('submitDyadBtn');
  if (walletDiv) {
    walletDiv.style.display = (selected === 'self_paid') ? 'block' : 'none';
  }
  if (btn) {
    if (selected === 'self_paid') {
      btn.textContent = 'پرداخت هدیه و ارسال دعوت‌نامه ←';
      btn.style.background = '#16a34a';
    } else {
      btn.textContent = 'ایجاد و ارسال دعوت‌نامه تطابق ←';
      btn.style.background = '#6d28d9';
    }
  }
}

document.addEventListener('DOMContentLoaded', function() {
  updateDyadPricing();
  onPayModeChange();
});
</script>
<?php
panel_layout_end();
render_footer();
