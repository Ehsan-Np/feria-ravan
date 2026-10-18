<?php
/**
 * فریا روان — صفحهٔ فرود فعال‌سازی و اتصال به ربات‌های پیام‌رسان
 * اتصال هوشمند یکپارچه تلگرام، بله و روبیکا
 * نسخه ۴٫۹٫۴
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/includes/snotify.php';

fr_bootstrap();

$token = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$channelFilter = trim((string)($_GET['ch'] ?? ''));

$user = null;
$tokenRow = null;

if ($token !== '') {
    $tokenRow = DB::one("SELECT * FROM " . DB::t('bot_connect_tokens') . " WHERE token=? LIMIT 1", [$token]);
    if ($tokenRow && !empty($tokenRow['user_id'])) {
        $user = DB::one("SELECT * FROM " . DB::t('users') . " WHERE id=?", [(int)$tokenRow['user_id']]);
    }
}

if (!$user) {
    $cur = current_user();
    if ($cur) {
        $user = $cur;
        $token = fr_bot_generate_token((int)$cur['id']);
    }
}

$siteTitle = (string)DB::setting('site_title', 'فریا روان');
$siteLogo = (string)DB::setting('site_logo', '');
$tgBot = ltrim((string)DB::setting('telegram_bot_username', ''), '@');
$baleBot = ltrim((string)DB::setting('bale_bot_username', ''), '@');
$rubikaBot = ltrim((string)DB::setting('rubika_bot_username', ''), '@');

$userName = $user ? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) : '';
if ($userName === '') $userName = 'کاربر گرامی';

$isTgConnected = !empty($user['telegram_chat_id']);
$isBaleConnected = !empty($user['bale_chat_id']);
$isRubikaConnected = !empty($user['rubika_chat_id']);
$hasAnyConnected = $isTgConnected || $isBaleConnected || $isRubikaConnected;

// پیوندهای مستقیم به ربات‌ها
$tgLink = $token !== '' ? fr_bot_get_channel_deep_link('telegram', $token) : ($tgBot !== '' ? 'https://t.me/' . $tgBot : '');
$baleLink = $token !== '' ? fr_bot_get_channel_deep_link('bale', $token) : ($baleBot !== '' ? 'https://ble.ir/' . $baleBot : '');
$rubikaLink = $token !== '' ? fr_bot_get_channel_deep_link('rubika', $token) : ($rubikaBot !== '' ? 'https://rubika.ir/' . $rubikaBot : '');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>اتصال به ربات اطلاع‌رسانی | <?= e($siteTitle) ?></title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css">
  <style>
    :root {
      --primary: #4338ca;
      --primary-light: #6366f1;
      --tg: #229ED9;
      --bale: #10B981;
      --rubika: #8B5CF6;
      --bg: #f8fafc;
      --card-bg: #ffffff;
      --text: #1e293b;
      --muted: #64748b;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Sahel, Vazirmatn, system-ui, -apple-system, sans-serif;
      background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 20px 14px;
      line-height: 1.6;
    }
    .connect-card {
      background: var(--card-bg);
      border-radius: 24px;
      box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.08), 0 0 1px 1px rgba(0,0,0,0.03);
      width: 100%;
      max-width: 520px;
      padding: 32px 26px;
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    .connect-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 6px;
      background: linear-gradient(90deg, #6366f1, #3b82f6, #10b981, #8b5cf6);
    }
    .logo-box {
      margin-bottom: 16px;
    }
    .logo-box img {
      max-height: 54px;
      object-fit: contain;
    }
    .logo-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 68px;
      height: 68px;
      background: #eef2ff;
      color: var(--primary);
      border-radius: 50%;
      font-size: 32px;
      margin-bottom: 14px;
      box-shadow: 0 8px 16px -4px rgba(67, 56, 202, 0.15);
    }
    h1 {
      font-size: 1.45rem;
      font-weight: 800;
      color: #0f172a;
      margin-bottom: 6px;
    }
    p.subtitle {
      font-size: 0.95rem;
      color: var(--muted);
      margin-bottom: 22px;
    }
    .user-greeting {
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      border-radius: 12px;
      padding: 10px 14px;
      font-size: 0.92rem;
      color: #334155;
      margin-bottom: 24px;
    }
    .benefits-list {
      text-align: right;
      background: #f8fafc;
      border-radius: 14px;
      padding: 14px 18px;
      margin-bottom: 24px;
      font-size: 0.88rem;
    }
    .benefits-list li {
      list-style: none;
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 8px;
      color: #334155;
    }
    .benefits-list li:last-child { margin-bottom: 0; }
    .channel-btn {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      padding: 15px 20px;
      margin-bottom: 12px;
      border-radius: 14px;
      text-decoration: none;
      font-weight: 700;
      font-size: 1rem;
      color: #ffffff;
      transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .channel-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(0,0,0,0.12);
      color: #ffffff;
    }
    .channel-btn .btn-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .channel-btn svg {
      width: 24px;
      height: 24px;
      fill: currentColor;
    }
    .btn-tg { background: linear-gradient(135deg, #229ED9 0%, #1e87bb 100%); }
    .btn-bale { background: linear-gradient(135deg, #10B981 0%, #059669 100%); }
    .btn-rubika { background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%); }
    .badge-status {
      font-size: 0.76rem;
      padding: 3px 8px;
      border-radius: 20px;
      background: rgba(255,255,255,0.22);
      font-weight: 600;
    }
    .badge-active {
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 700;
    }
    .code-box {
      margin-top: 22px;
      background: #f1f5f9;
      border-radius: 12px;
      padding: 14px;
      text-align: center;
    }
    .code-label {
      font-size: 0.82rem;
      color: #64748b;
      margin-bottom: 6px;
    }
    .code-val {
      font-family: monospace;
      font-size: 1.3rem;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: 2px;
      background: #ffffff;
      padding: 6px 16px;
      border-radius: 8px;
      display: inline-block;
      border: 1px solid #cbd5e1;
      margin-bottom: 8px;
    }
    .copy-btn {
      background: #3b82f6;
      color: #fff;
      border: none;
      padding: 6px 16px;
      border-radius: 8px;
      font-size: 0.84rem;
      cursor: pointer;
      font-family: inherit;
      font-weight: 600;
    }
    .copy-btn:hover { background: #2563eb; }
    .live-status {
      margin-top: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-size: 0.84rem;
      color: #64748b;
    }
    .live-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #3b82f6;
      animation: pulse 1.5s infinite;
    }
    @keyframes pulse {
      0% { transform: scale(0.95); opacity: 0.7; }
      50% { transform: scale(1.3); opacity: 1; }
      100% { transform: scale(0.95); opacity: 0.7; }
    }
    .success-alert {
      display: none;
      background: #ecfdf5;
      border: 1.5px solid #10b981;
      border-radius: 14px;
      padding: 20px;
      margin-bottom: 20px;
      color: #065f46;
      text-align: center;
    }
    .success-alert h3 { font-size: 1.15rem; margin-bottom: 6px; }
  </style>
</head>
<body>

<div class="connect-card">
  <?php if ($siteLogo !== ''): ?>
    <div class="logo-box">
      <img src="<?= e(SITE_URL . '/' . ltrim($siteLogo, '/')) ?>" alt="<?= e($siteTitle) ?>">
    </div>
  <?php else: ?>
    <div class="logo-badge">🦢</div>
  <?php endif; ?>

  <h1>اتصال به ربات اطلاع‌رسانی</h1>
  <p class="subtitle"><?= e($siteTitle) ?></p>

  <div id="successBox" class="success-alert" <?= $hasAnyConnected ? 'style="display:block;"' : '' ?>>
    <div style="font-size: 38px; margin-bottom: 8px;">🎉</div>
    <h3>اتصال شما با موفقیت فعال شد!</h3>
    <p style="font-size:0.9rem; margin-bottom:14px;">از این لحظه، نتایج آزمون‌ها، یادآورهای پلنر و پیام‌های اختصاصی برای شما ارسال می‌گردد.</p>
    <a href="<?= SITE_URL ?>/panel/" class="channel-btn" style="background:#059669; justify-content:center;">ورود به پنل کاربری</a>
  </div>

  <div id="connectFlow" <?= $hasAnyConnected ? 'style="display:none;"' : '' ?>>
    <?php if ($user): ?>
      <div class="user-greeting">
        سلام <strong><?= e($userName) ?></strong> عزیز! لطفاً پیام‌رسان دلخواه خود را لمس فرمایید تا اتصال برقرار گردد:
      </div>
    <?php else: ?>
      <div class="user-greeting">
        برای اتصال خودکار، لطفاً روی پیام‌رسان مدنظر کلیک کرده یا در صفحه بعد وارد شوید.
      </div>
    <?php endif; ?>

    <ul class="benefits-list">
      <li><span>📥</span> دریافت مستقیم و آنی فایل PDF کارنامه و نتیجه آزمون‌ها</li>
      <li><span>⏰</span> یادآوری‌های هوشمند تکالیف و عادات روزانهٔ پلنر</li>
      <li><span>🔔</span> اطلاعیه‌های مشاوره‌ها، کارگاه‌ها و دوره‌ها سر وقت</li>
    </ul>

    <!-- دکمه تلگرام -->
    <?php if ($tgLink !== ''): ?>
      <a href="<?= e($tgLink) ?>" class="channel-btn btn-tg" target="_blank" rel="noopener">
        <div class="btn-left">
          <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.75-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
          <span>اتصال از طریق تلگرام</span>
        </div>
        <span class="badge-status">ورود و Start</span>
      </a>
    <?php endif; ?>

    <!-- دکمه بله -->
    <?php if ($baleLink !== ''): ?>
      <a href="<?= e($baleLink) ?>" class="channel-btn btn-bale" target="_blank" rel="noopener">
        <div class="btn-left">
          <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
          <span>اتصال از طریق پیام‌رسان بله</span>
        </div>
        <span class="badge-status">ورود و Start</span>
      </a>
    <?php endif; ?>

    <!-- دکمه روبیکا -->
    <?php if ($rubikaLink !== ''): ?>
      <a href="<?= e($rubikaLink) ?>" class="channel-btn btn-rubika" target="_blank" rel="noopener">
        <div class="btn-left">
          <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          <span>اتصال از طریق روبیکا</span>
        </div>
        <span class="badge-status">ورود و Start</span>
      </a>
    <?php endif; ?>

    <?php if ($token !== ''): ?>
      <div class="code-box">
        <div class="code-label">یا در صورت لزوم، کد اختصاصی زیر را در ربات بفرستید:</div>
        <div class="code-val" id="userTokenCode"><?= e($token) ?></div>
        <div>
          <button type="button" class="copy-btn" onclick="copyToken()">📋 کپی کد فعال‌سازی</button>
        </div>
      </div>
    <?php endif; ?>

    <div class="live-status">
      <div class="live-dot"></div>
      <span id="pollStatusText">در انتظار اتصال شما...</span>
    </div>
  </div>

  <div style="margin-top:22px; font-size:0.82rem; color:#94a3b8;">
    <a href="<?= SITE_URL ?>" style="color:#64748b; text-decoration:none;">بازگشت به صفحهٔ اصلی فریا روان</a>
  </div>
</div>

<script>
function copyToken() {
  const code = document.getElementById('userTokenCode').innerText.trim();
  navigator.clipboard.writeText(code).then(() => {
    alert('کد اتصال کپی شد: ' + code);
  }).catch(() => {
    prompt('کد اتصال شما:', code);
  });
}

// بررسی وضعیت زنده اتصال هر ۳ ثانیه
const token = <?= json_encode($token) ?>;
let pollTimer = null;

function checkStatus() {
  if (!token) return;
  fetch('<?= SITE_URL ?>/api/bot-status.php?token=' + encodeURIComponent(token) + '&poll=1')
    .then(r => r.json())
    .then(d => {
      if (d.ok && d.connected) {
        clearInterval(pollTimer);
        document.getElementById('connectFlow').style.display = 'none';
        document.getElementById('successBox').style.display = 'block';
      }
    })
    .catch(() => {});
}

if (token && !<?= $hasAnyConnected ? 'true' : 'false' ?>) {
  pollTimer = setInterval(checkStatus, 3000);
}
</script>

</body>
</html>
