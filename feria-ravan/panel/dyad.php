<?php
/**
 * ============================================================================
 *  مرکز تطابق دونفره و زوجین فریا روان (Couples & Dyadic Hub)
 *  ─ ایجاد پیوند جدید، دعوت همسر/پارتنر/همکار و پیگیری وضعیت پاسخ‌دهی
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/view.php';
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
            redirect(SITE_URL . '/panel/dyad-report.php?id=' . $res['dyad_id']);
        } elseif (!empty($res['redirect_url'])) {
            redirect($res['redirect_url']);
        }
    } else {
        flash($res['message'] ?? 'خطا در اتصال به پیوند دعوت.', 'err');
        redirect(SITE_URL . '/panel/dyad.php');
    }
}

// ساخت پیوند جدید
$createdDyad = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create_dyad') {
    check_csrf();
    $attemptId = (int)post('attempt_id');
    $relType   = (string)post('rel_type');
    $res = DyadAnalyzer::createDyad((int)$u['id'], $attemptId, $relType);
    if ($res['ok']) {
        flash('پیوند دعوت با موفقیت ایجاد شد! لینک را برای همراه خود بفرستید. 🦢', 'ok');
        $createdDyad = $res;
    } else {
        flash($res['message'] ?? 'خطا در ایجاد پیوند تطابق.', 'err');
    }
}

// فهرست آزمون‌های تکمیل‌شدهٔ کاربر که قابلیت تطابق دونفره دارند
$completedAttempts = DB::all("
    SELECT a.id, a.test_id, a.mode, a.completed_at, t.title AS test_title, t.slug, t.engine
    FROM " . DB::t('attempts') . " a
    JOIN " . DB::t('tests') . " t ON t.id=a.test_id
    WHERE a.user_id=? AND a.status='completed'
    ORDER BY a.id DESC
", [$u['id']]);

// پیوندهای دونفرهٔ این کاربر (چه دعوت‌کننده چه دعوت‌شونده)
$myDyads = DB::all("
    SELECT d.*, t.title AS test_title,
           u1.first_name AS u1_fn, u1.last_name AS u1_ln,
           u2.first_name AS u2_fn, u2.last_name AS u2_ln
    FROM " . DB::t('dyads') . " d
    JOIN " . DB::t('tests') . " t ON t.id=d.test_id
    LEFT JOIN " . DB::t('users') . " u1 ON u1.id=d.user1_id
    LEFT JOIN " . DB::t('users') . " u2 ON u2.id=d.user2_id
    WHERE d.user1_id=? OR d.user2_id=?
    ORDER BY d.id DESC
", [$u['id'], $u['id']]);

$reqAttemptId = (int)($_GET['attempt'] ?? 0);

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
    <h3 style="margin:0 0 8px;color:#5b21b6">لینک دعوت شما با موفقیت ایجاد شد!</h3>
    <p style="font-size:13.5px;color:#4c1d95;margin-bottom:16px">
      این پیوند اختصاصی را کپی کرده و برای همسر، پارتنر یا هم‌تیمی خود ارسال کنید تا پس از تکمیل آزمون، کارنامهٔ تطابق دونفره در اختیارتان قرار گیرد:
    </p>
    <div style="display:flex;gap:8px;max-width:540px;margin:0 auto 16px">
      <input type="text" readonly id="dyadLinkInput" value="<?= e($createdDyad['invite_url']) ?>" class="fr-input" style="direction:ltr;text-align:left;font-family:monospace;background:#fff">
      <button class="fr-btn" type="button" onclick="navigator.clipboard.writeText(document.getElementById('dyadLinkInput').value);toast('لینک دعوت با موفقیت کپی شد! ✅','ok')">کپی لینک</button>
    </div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <a class="fr-btn sm ghost" target="_blank" href="https://api.whatsapp.com/send?text=<?= urlencode("سلام! من تست روانشناسی را در فریا انجام دادم. بیا با هم سازگاری و تطابق شخصیتمان را بسنجیم:\n" . $createdDyad['invite_url']) ?>">ارسال در واتس‌اپ 💬</a>
      <a class="fr-btn sm ghost" target="_blank" href="https://t.me/share/url?url=<?= urlencode($createdDyad['invite_url']) ?>&text=<?= urlencode("سلام! بیا با این لینک سازگاری و تطابق رفتاری‌مان را بسنجیم 🦢") ?>">ارسال در تلگرام ✈️</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- فرم ایجاد پیوند جدید -->
  <div class="fr-card" style="margin-bottom:32px">
    <h3 style="margin:0 0 16px;font-size:16px">➕ ایجاد پیوند تطابق جدید</h3>
    <?php if (empty($completedAttempts)): ?>
      <p style="color:var(--muted);font-size:13.5px">شما هنوز هیچ تست تکمیل‌شده‌ای ندارید. ابتدا یکی از آزمون‌ها را انجام دهید.</p>
      <a class="fr-btn sm" href="index.php">مشاهدهٔ آزمون‌ها ←</a>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="create_dyad">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;margin-bottom:18px">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">آزمون مبنای مقایسه:</label>
            <select name="attempt_id" class="fr-input" required>
              <?php foreach ($completedAttempts as $ca): ?>
                <option value="<?= $ca['id'] ?>" <?= ($reqAttemptId === (int)$ca['id']) ? 'selected' : '' ?>>
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
        </div>
        <button class="fr-btn" style="background:#6d28d9">ایجاد لینک دعوت دونفره ←</button>
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
          $p2Name = trim(($dy['u2_fn'] ?? '') . ' ' . ($dy['u2_ln'] ?? '')) ?: 'در انتظار پاسخ طرف دوم...';
          $isReady = $dy['status'] === 'ready';
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px;border:1px solid var(--border);border-radius:12px;flex-wrap:wrap;gap:12px;background:<?= $isReady ? '#faf5ff' : '#fff' ?>">
          <div>
            <div style="font-weight:700;font-size:15px;color:var(--ink)">
              <?= e($dy['test_title']) ?>
              <span style="font-size:11.5px;font-weight:400;background:var(--border);padding:3px 8px;border-radius:6px;margin-right:6px">
                <?= e(DyadAnalyzer::$REL_TYPES[$dy['rel_type']] ?? 'دونفره') ?>
              </span>
            </div>
            <div style="font-size:13px;color:var(--muted);margin-top:6px">
              👤 <?= e($p1Name) ?> &nbsp; ↔ &nbsp; 👥 <?= e($p2Name) ?>
            </div>
            <?php if ($isReady && $dy['synergy_score']): ?>
              <div style="margin-top:6px;font-size:12.5px;color:#6d28d9;font-weight:700">
                ✨ شاخص هم‌افزایی: <?= fa_num($dy['synergy_score']) ?>٪
              </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px">
            <?php if ($isReady): ?>
              <a class="fr-btn sm" style="background:#6d28d9" href="dyad-report.php?id=<?= $dy['id'] ?>">مشاهده تحلیل تطابق ←</a>
            <?php else: ?>
              <button class="fr-btn sm ghost" type="button" onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/panel/dyad.php?token=<?= $dy['token'] ?>');toast('لینک دعوت کپی شد! ✅','ok')">کپی مجدد لینک</button>
              <span class="fr-badge warn" style="font-size:12px;padding:6px 10px">در انتظار همراه</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
panel_layout_end();
render_footer();
