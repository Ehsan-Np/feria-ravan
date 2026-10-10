<?php
/**
 * فریا روان — مشاهده و ویرایش تخصصی پاسخ‌های آزمون مراجع
 * امکان ویرایش تعاملی با AJAX، پایش سؤالات پاسخ‌داده‌شده و بی‌پاسخ،
 * ارسال پیامک یادآوری ادامهٔ آزمون و محاسبهٔ مجدد نمرات و کارنامه
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin(['superadmin', 'manager', 'support']);

$attemptId = (int)($_GET['attempt'] ?? ($_GET['id'] ?? ($_POST['attempt_id'] ?? 0)));
if ($attemptId <= 0) {
    flash('شناسهٔ آزمون نامعتبر است.', 'err');
    redirect(SITE_URL . '/admin/tests.php');
}

$at = DB::one('SELECT a.*, t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine,
                      t.question_count AS test_qc, u.first_name, u.last_name, u.mobile, u.email,
                      u.national_code
               FROM ' . DB::t('attempts') . ' a
               JOIN ' . DB::t('tests') . ' t ON t.id = a.test_id
               JOIN ' . DB::t('users') . ' u ON u.id = a.user_id
               WHERE a.id = ?', [$attemptId]);

if (!$at) {
    flash('جلسهٔ آزمون موردنظر یافت نشد.', 'err');
    redirect(SITE_URL . '/admin/tests.php');
}

/* ══════════════════════════════════════════════════════════════════════════
   پردازش درخواست‌های AJAX
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    csrf_guard();
    $action = (string)$_POST['action'];

    /* ۱. ذخیره یا تغییر پاسخ یک سؤال */
    if ($action === 'save_answer') {
        $qid   = (int)($_POST['question_id'] ?? 0);
        $val   = isset($_POST['value']) ? trim((string)$_POST['value']) : null;
        $mIdx  = isset($_POST['most_idx']) && $_POST['most_idx'] !== '' ? (int)$_POST['most_idx'] : null;
        $lIdx  = isset($_POST['least_idx']) && $_POST['least_idx'] !== '' ? (int)$_POST['least_idx'] : null;

        if ($qid <= 0) {
            echo json_encode(['ok' => false, 'message' => 'سؤال نامعتبر است.']);
            exit;
        }

        $q = DB::one('SELECT id, format FROM ' . DB::t('questions') . ' WHERE id=? AND test_id=?', [$qid, (int)$at['test_id']]);
        if (!$q) {
            echo json_encode(['ok' => false, 'message' => 'سؤال متعلق به این آزمون نیست.']);
            exit;
        }

        $exists = DB::one('SELECT id FROM ' . DB::t('answers') . ' WHERE attempt_id=? AND question_id=?', [$attemptId, $qid]);

        $data = [
            'value'     => $val,
            'most_idx'  => $mIdx,
            'least_idx' => $lIdx,
        ];

        if ($exists) {
            DB::update('answers', $data, 'id=?', [$exists['id']]);
        } else {
            $data['attempt_id']  = $attemptId;
            $data['question_id'] = $qid;
            DB::insert('answers', $data);
        }

        // به‌روزرسانی پیشرفت در جدول attempts
        $ansCount = (int)DB::val('SELECT COUNT(DISTINCT question_id) FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$attemptId]);
        $totalQ   = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1', [(int)$at['test_id']]);
        
        $newStatus = $at['status'];
        if ($ansCount >= $totalQ && $at['status'] === 'in_progress') {
            $newStatus = 'completed';
            DB::update('attempts', [
                'progress'     => $ansCount,
                'status'       => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ], 'id=?', [$attemptId]);
        } else {
            DB::update('attempts', ['progress' => $ansCount], 'id=?', [$attemptId]);
        }

        echo json_encode([
            'ok'               => true,
            'message'          => 'پاسخ ذخیره شد ✅',
            'answered_count'   => $ansCount,
            'unanswered_count' => max(0, $totalQ - $ansCount),
            'progress_percent' => $totalQ > 0 ? round(($ansCount / $totalQ) * 100) : 0,
            'status'           => $newStatus,
        ]);
        exit;
    }

    /* ۲. محاسبهٔ مجدد نمرات و به‌روزرسانی تحلیل کارنامه */
    if ($action === 'recalculate') {
        require_once FR_ROOT . '/core/Analyzer.php';
        try {
            $scoreOk = Analyzer::scoreAttempt($attemptId);
            echo json_encode([
                'ok'      => true,
                'message' => 'نمرات، مقیاس‌های T-Score، Sten و کارنامهٔ تحلیلی با موفقیت به‌روزرسانی شدند 🎯',
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'ok'      => false,
                'message' => 'خطا در محاسبهٔ نمرات: ' . $e->getMessage(),
            ]);
        }
        exit;
    }

    /* ۳. ارسال پیامک یادآوری ادامهٔ آزمون نیمه‌کاره */
    if ($action === 'send_nudge') {
        require_once FR_ROOT . '/core/Kavenegar.php';
        $resumeLink = fr_panel_url('take.php?attempt=' . $attemptId);
        $name = trim($at['first_name'] . ' ' . $at['last_name']) ?: 'کاربر گرامی';
        
        $kv = new Kavenegar();
        $sent = $kv->sendEvent('test_nudge', (string)$at['mobile'], [
            'name'    => $name,
            'test'    => $at['test_title'],
            'link'    => $resumeLink,
            '_tokens' => [$name, $at['test_title'], $resumeLink],
        ]);

        if (!$sent) {
            $site = DB::setting('site_title', 'فریا روان');
            $msg = "سلام {$name} عزیز\nپاسخ‌گویی به آزمون «{$at['test_title']}» شما نیمه‌کاره باقی مانده است. جهت تکمیل و دریافت کارنامه، روی لینک زیر بزنید:\n{$resumeLink}\n{$site}";
            $sent = $kv->send((string)$at['mobile'], $msg, 'test_nudge');
        }

        if ($sent) {
            DB::update('attempts', ['nudge_sent' => 1], 'id=?', [$attemptId]);
            log_activity('sms_nudge', 'attempt', $attemptId, ['mobile' => $at['mobile'], 'name' => $name]);
            echo json_encode([
                'ok'      => true,
                'message' => 'پیامک یادآوری به همراه لینک ادامهٔ آزمون با موفقیت به ' . fa_num($at['mobile']) . ' ارسال شد 📨',
            ]);
        } else {
            echo json_encode([
                'ok'      => false,
                'message' => 'ارسال پیامک با خطا مواجه شد. لطفاً تنظیمات درگاه پیامک را بررسی کنید.',
            ]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'عملیات ناشناخته.']);
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════
   واکشی سؤالات آزمون و پاسخ‌های فعلی کاربر
   ══════════════════════════════════════════════════════════════════════════ */
$questions = DB::all('SELECT * FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1 ORDER BY number ASC, id ASC', [(int)$at['test_id']]);
$rawAnswers = DB::all('SELECT * FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$attemptId]);

$answersMap = [];
foreach ($rawAnswers as $ans) {
    $answersMap[(int)$ans['question_id']] = $ans;
}

$totalQuestions = count($questions);
$answeredQuestions = 0;
foreach ($questions as $q) {
    if (isset($answersMap[(int)$q['id']])) {
        $a = $answersMap[(int)$q['id']];
        if ($a['value'] !== null || $a['most_idx'] !== null || $a['least_idx'] !== null) {
            $answeredQuestions++;
        }
    }
}
$unansweredQuestions = max(0, $totalQuestions - $answeredQuestions);
$progressPercent = $totalQuestions > 0 ? (int)round(($answeredQuestions / $totalQuestions) * 100) : 0;

admin_layout($adm, 'tests', 'مشاهده و ویرایش پاسخ‌های آزمون #' . fa_num($attemptId));
echo flash();
?>

<style>
/* استایل‌های اختصاصی صفحهٔ مشاهده و ویرایش پاسخ‌ها با انیمیشن */
.ans-hero {
  background: linear-gradient(135deg, #2e1065 0%, #4c1d95 60%, #5b21b6 100%);
  color: #fff;
  border-radius: 16px;
  padding: 24px;
  margin-bottom: 20px;
  box-shadow: 0 10px 25px -5px rgba(91, 33, 182, 0.25);
  position: relative;
  overflow: hidden;
}
.ans-hero::after {
  content: "🧠";
  position: absolute;
  left: 20px;
  bottom: -15px;
  font-size: 110px;
  opacity: 0.12;
  pointer-events: none;
}
.ans-stats-bar {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px;
  margin-bottom: 20px;
}
.ans-stat-card {
  background: #fff;
  border-radius: 14px;
  padding: 18px;
  border: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.03);
  transition: transform .2s ease, box-shadow .2s ease;
}
.ans-stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 16px rgba(0,0,0,0.06);
}
.ans-stat-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.ans-filter-bar {
  background: #fff;
  border-radius: 14px;
  padding: 12px 18px;
  border: 1px solid #e2e8f0;
  margin-bottom: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.ans-tab-btn {
  background: #f1f5f9;
  border: 1px solid transparent;
  color: #475569;
  padding: 8px 16px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all .2s ease;
}
.ans-tab-btn.active, .ans-tab-btn:hover {
  background: #7c3aed;
  color: #fff;
}
.ans-q-card {
  background: #fff;
  border-radius: 14px;
  padding: 20px;
  margin-bottom: 16px;
  border: 1.5px solid #e2e8f0;
  transition: all .25s ease;
  position: relative;
}
.ans-q-card.is-answered {
  border-right: 6px solid #10b981;
}
.ans-q-card.is-unanswered {
  border-right: 6px solid #f59e0b;
  background: #fffdfa;
}
.ans-q-card.just-saved {
  animation: pulseHighlight 1.2s ease-out;
}
@keyframes pulseHighlight {
  0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); }
  50% { transform: scale(1.01); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
  100% { transform: scale(1); }
}
.ans-likert-group {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 14px;
}
.ans-likert-btn {
  flex: 1;
  min-width: 100px;
  padding: 10px 14px;
  border-radius: 10px;
  border: 1.5px solid #cbd5e1;
  background: #f8fafc;
  color: #334155;
  font-size: 12.5px;
  text-align: center;
  cursor: pointer;
  transition: all .15s ease;
  user-select: none;
}
.ans-likert-btn:hover {
  border-color: #7c3aed;
  background: #f5f3ff;
  color: #6d28d9;
}
.ans-likert-btn.selected {
  background: #7c3aed;
  border-color: #6d28d9;
  color: #fff;
  font-weight: bold;
  box-shadow: 0 4px 10px rgba(124, 58, 237, 0.3);
}
.ans-disc-group {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  margin-top: 14px;
}
.ans-disc-item {
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 12px;
  background: #fafafa;
}
.ans-disc-item:hover {
  background: #f5f3ff;
  border-color: #c4b5fd;
}
.ans-save-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11.5px;
  color: #059669;
  opacity: 0;
  transition: opacity .3s ease;
}
.ans-save-badge.show {
  opacity: 1;
}
.ans-toast {
  position: fixed;
  bottom: 24px;
  left: 24px;
  z-index: 9999;
  background: #1e1b4b;
  color: #fff;
  padding: 14px 22px;
  border-radius: 12px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.3);
  display: flex;
  align-items: center;
  gap: 10px;
  transform: translateY(100px);
  opacity: 0;
  transition: all .3s cubic-bezier(0.16, 1, 0.3, 1);
}
.ans-toast.show {
  transform: translateY(0);
  opacity: 1;
}
</style>

<div class="ans-hero">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="background:rgba(255,255,255,0.2);padding:4px 10px;border-radius:8px;font-size:12px">جلسهٔ آزمون #<?= fa_num($attemptId) ?></span>
        <span class="fr-chip <?= $at['mode'] === 'full' ? 'purple' : 'info' ?>" style="background:rgba(255,255,255,0.25);color:#fff">
          <?= $at['mode'] === 'full' ? '⭐ نسخه ویژه' : '🌱 نسخه رایگان' ?>
        </span>
        <span class="fr-chip" id="attemptStatusBadge" style="background:<?= $at['status'] === 'in_progress' ? '#f59e0b' : '#10b981' ?>;color:#fff">
          <?= $at['status'] === 'in_progress' ? '⏳ ناتمام' : '✅ تکمیل‌شده' ?>
        </span>
      </div>
      <h2 style="margin:0 0 8px 0;font-size:22px"><?= e($at['test_title']) ?></h2>
      <p style="margin:0;font-size:14px;opacity:0.9">
        مراجع: <b><?= e(trim($at['first_name'] . ' ' . $at['last_name']) ?: 'کاربر بدون نام') ?></b>
        (<?= fa_num($at['mobile']) ?>)
        <?= !empty($at['national_code']) ? ' · کد ملی: ' . e($at['national_code']) : '' ?>
      </p>
    </div>
    
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <?php if ($at['status'] !== 'in_progress'): ?>
      <a href="<?= SITE_URL ?>/admin/user-view.php?id=<?= $at['user_id'] ?>&report=<?= $attemptId ?>" target="_blank" class="fr-btn sm" style="background:#fff;color:#5b21b6">
        👁 کارنامه تحلیلی مراجع ↗
      </a>
      <a href="clinical-case.php?attempt=<?= $attemptId ?>" target="_blank" class="fr-btn sm" style="background:#4338ca;color:#fff">
        📋 پرونده بالینی مشاور ↗
      </a>
      <?php endif; ?>

      <?php if ($at['status'] === 'in_progress'): ?>
      <button type="button" class="fr-btn sm" id="btnSendNudge" style="background:#f59e0b;color:#fff" onclick="sendNudgeSms()">
        📲 پیامک یادآوری ادامه آزمون
      </button>
      <?php endif; ?>

      <button type="button" class="fr-btn sm" id="btnRecalculate" style="background:#10b981;color:#fff" onclick="recalculateScores()">
        🔄 محاسبه مجدد نمرات و کارنامه
      </button>
    </div>
  </div>

  <div style="margin-top:20px;background:rgba(255,255,255,0.15);border-radius:10px;padding:12px 16px">
    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px">
      <span>پیشرفت پاسخ‌گویی به سؤالات</span>
      <b id="progressPercentLabel"><?= fa_num($progressPercent) ?>٪</b>
    </div>
    <div style="background:rgba(255,255,255,0.25);height:8px;border-radius:6px;overflow:hidden">
      <div id="progressBarFill" style="width:<?= $progressPercent ?>%;background:#10b981;height:100%;transition:width .4s ease"></div>
    </div>
  </div>
</div>

<div class="ans-stats-bar">
  <div class="ans-stat-card">
    <div class="ans-stat-icon" style="background:#f5f3ff;color:#7c3aed">📋</div>
    <div>
      <small style="color:var(--muted)">تعداد کل سؤالات</small>
      <div style="font-size:20px;font-weight:bold"><?= fa_num($totalQuestions) ?> سؤال</div>
    </div>
  </div>

  <div class="ans-stat-card">
    <div class="ans-stat-icon" style="background:#ecfdf5;color:#10b981">✅</div>
    <div>
      <small style="color:var(--muted)">پاسخ‌داده‌شده</small>
      <div style="font-size:20px;font-weight:bold;color:#10b981" id="statAnsweredCount"><?= fa_num($answeredQuestions) ?> سؤال</div>
    </div>
  </div>

  <div class="ans-stat-card">
    <div class="ans-stat-icon" style="background:#fffbeb;color:#f59e0b">⚠️</div>
    <div>
      <small style="color:var(--muted)">بدون پاسخ (مانده)</small>
      <div style="font-size:20px;font-weight:bold;color:#f59e0b" id="statUnansweredCount"><?= fa_num($unansweredQuestions) ?> سؤال</div>
    </div>
  </div>

  <div class="ans-stat-card">
    <div class="ans-stat-icon" style="background:#f1f5f9;color:#475569">⏱</div>
    <div>
      <small style="color:var(--muted)">تاریخ شروع</small>
      <div style="font-size:13.5px;font-weight:bold"><?= fa_num(jdate('j F Y - H:i', strtotime($at['started_at']))) ?></div>
    </div>
  </div>
</div>

<div class="ans-filter-bar">
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" class="ans-tab-btn active" onclick="filterQuestions('all', this)">همه سؤالات (<?= fa_num($totalQuestions) ?>)</button>
    <button type="button" class="ans-tab-btn" onclick="filterQuestions('answered', this)">✅ فقط پاسخ‌داده‌شده (<?= fa_num($answeredQuestions) ?>)</button>
    <button type="button" class="ans-tab-btn" onclick="filterQuestions('unanswered', this)">⚠️ فقط بدون پاسخ (<?= fa_num($unansweredQuestions) ?>)</button>
  </div>
  
  <div style="display:flex;gap:8px;align-items:center">
    <input type="text" class="fr-input" id="searchQ" placeholder="🔎 جست‌وجوی شماره یا متن سؤال..." style="font-size:12.5px;padding:6px 12px;width:240px" oninput="searchQuestions(this.value)">
  </div>
</div>

<!-- فهرست کارت‌های سوالات -->
<div id="questionsContainer">
  <?php foreach ($questions as $idx => $q): 
    $qid = (int)$q['id'];
    $ans = $answersMap[$qid] ?? null;
    $hasAnswer = ($ans && ($ans['value'] !== null || $ans['most_idx'] !== null || $ans['least_idx'] !== null));
    $format = $q['format'] ?? 'likert';
    $scaleLabels = ['۱: کاملاً مخالفم', '۲: مخالفم', '۳: نظری ندارم', '۴: موافقم', '۵: کاملاً موافقم'];
  ?>
  <div class="ans-q-card <?= $hasAnswer ? 'is-answered' : 'is-unanswered' ?>" id="qcard-<?= $qid ?>" data-qid="<?= $qid ?>" data-status="<?= $hasAnswer ? 'answered' : 'unanswered' ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:8px">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="background:#ede9fe;color:#5b21b6;font-weight:bold;width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px">
          <?= fa_num($q['number']) ?>
        </span>
        <b style="font-size:14.5px;color:#1e1b4b"><?= e($q['text']) ?></b>
      </div>
      
      <div style="display:flex;align-items:center;gap:8px">
        <span class="ans-save-badge" id="saveBadge-<?= $qid ?>">✓ ذخیره شد</span>
        <span class="fr-chip <?= $hasAnswer ? 'ok' : 'warn' ?>" id="statusBadge-<?= $qid ?>" style="font-size:11px">
          <?= $hasAnswer ? '✅ پاسخ داده شده' : '⚠️ بدون پاسخ' ?>
        </span>
      </div>
    </div>

    <?php if ($format === 'likert'): ?>
      <!-- سوالات مقیاس ۵ گزینه‌ای لیکرت -->
      <div class="ans-likert-group">
        <?php for ($v = 1; $v <= 5; $v++): 
          $isSelected = ($ans && (int)$ans['value'] === $v);
        ?>
        <div class="ans-likert-btn <?= $isSelected ? 'selected' : '' ?>" 
             data-val="<?= $v ?>" 
             onclick="selectLikert(<?= $qid ?>, <?= $v ?>, this)">
          <?= fa_num($v) ?>. <?= $scaleLabels[$v - 1] ?>
        </div>
        <?php endfor; ?>
      </div>

    <?php elseif ($format === 'mostleast'): 
      // آزمون‌های تیپ رفتارشناسی DISC با گزینه‌های بیشترین/کمترین
      $opts = !empty($q['options']) ? json_decode((string)$q['options'], true) : [];
      if (!$opts && !empty($q['text'])) {
        $opts = array_map('trim', explode("\n", $q['text']));
      }
    ?>
      <div style="font-size:12px;color:var(--muted);margin:8px 0">برای هر گزینه، شاخص بیشترین (Most) یا کمترین (Least) شباهت را انتخاب کنید:</div>
      <div class="ans-disc-group">
        <?php foreach ($opts as $oIdx => $optText): 
          $isMost = ($ans && $ans['most_idx'] !== null && (int)$ans['most_idx'] === $oIdx);
          $isLeast = ($ans && $ans['least_idx'] !== null && (int)$ans['least_idx'] === $oIdx);
        ?>
        <div class="ans-disc-item">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px"><?= e($optText) ?></div>
          <div style="display:flex;gap:6px">
            <button type="button" class="fr-btn sm <?= $isMost ? 'ok' : 'ghost' ?>" 
                    style="flex:1;font-size:11.5px;padding:4px"
                    onclick="selectDisc(<?= $qid ?>, <?= $oIdx ?>, 'most', this)">
              <?= $isMost ? '★ بیشترین (Most)' : 'بیشترین' ?>
            </button>
            <button type="button" class="fr-btn sm <?= $isLeast ? 'danger' : 'ghost' ?>" 
                    style="flex:1;font-size:11.5px;padding:4px"
                    onclick="selectDisc(<?= $qid ?>, <?= $oIdx ?>, 'least', this)">
              <?= $isLeast ? '▼ کمترین (Least)' : 'کمترین' ?>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <!-- انواع دیگر سؤالات -->
      <div style="display:flex;gap:8px;align-items:center;margin-top:10px">
        <input type="text" class="fr-input" id="inputOther-<?= $qid ?>" 
               value="<?= e($ans['value'] ?? '') ?>" 
               placeholder="مقدار یا متن پاسخ..." style="max-width:300px">
        <button type="button" class="fr-btn sm" onclick="saveCustomAnswer(<?= $qid ?>)">ثبت پاسخ</button>
      </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- توست پیام AJAX -->
<div class="ans-toast" id="ansToast">
  <span id="toastIcon">✨</span>
  <span id="toastMsg">عملیات با موفقیت انجام شد</span>
</div>

<script>
const ATTEMPT_ID = <?= $attemptId ?>;
const CSRF_TOKEN = '<?= csrf_token() ?>';

/* نمایش توست زیبا */
function showToast(msg, icon = '✨') {
  const t = document.getElementById('ansToast');
  document.getElementById('toastMsg').textContent = msg;
  document.getElementById('toastIcon').textContent = icon;
  t.classList.add('show');
  setTimeout(() => { t.classList.remove('show'); }, 3500);
}

/* به‌روزرسانی نوار پیشرفت و کارت‌های آماری */
function updateProgressUI(data) {
  if (data.answered_count !== undefined) {
    document.getElementById('statAnsweredCount').textContent = data.answered_count.toLocaleString('fa-IR') + ' سؤال';
    document.getElementById('statUnansweredCount').textContent = data.unanswered_count.toLocaleString('fa-IR') + ' سؤال';
    document.getElementById('progressPercentLabel').textContent = data.progress_percent.toLocaleString('fa-IR') + '٪';
    document.getElementById('progressBarFill').style.width = data.progress_percent + '%';

    if (data.status === 'completed') {
      const b = document.getElementById('attemptStatusBadge');
      if (b) { b.textContent = '✅ تکمیل‌شده'; b.style.background = '#10b981'; }
    }
  }
}

/* انتخاب و ذخیره پاسخ لیکرت با AJAX */
async function selectLikert(qid, val, btnEl) {
  const card = document.getElementById('qcard-' + qid);
  const btns = card.querySelectorAll('.ans-likert-btn');
  btns.forEach(b => b.classList.remove('selected'));
  btnEl.classList.add('selected');

  const fd = new FormData();
  fd.append('action', 'save_answer');
  fd.append('attempt_id', ATTEMPT_ID);
  fd.append('question_id', qid);
  fd.append('value', val);
  fd.append('_csrf', CSRF_TOKEN);

  try {
    const res = await fetch('attempt-answers.php?attempt=' + ATTEMPT_ID, {
      method: 'POST',
      body: fd
    });
    const json = await res.json();
    if (json.ok) {
      card.classList.remove('is-unanswered');
      card.classList.add('is-answered');
      card.dataset.status = 'answered';

      const sBadge = document.getElementById('statusBadge-' + qid);
      sBadge.className = 'fr-chip ok';
      sBadge.textContent = '✅ پاسخ داده شده';

      const saveB = document.getElementById('saveBadge-' + qid);
      saveB.classList.add('show');
      setTimeout(() => saveB.classList.remove('show'), 2000);

      card.classList.add('just-saved');
      setTimeout(() => card.classList.remove('just-saved'), 1200);

      updateProgressUI(json);
    } else {
      showToast(json.message || 'خطا در ثبت پاسخ', '⚠️');
    }
  } catch (err) {
    showToast('خطای شبکه در ذخیره پاسخ', '❌');
  }
}

/* انتخاب گزینه‌های DISC */
async function selectDisc(qid, optIdx, type, btnEl) {
  const card = document.getElementById('qcard-' + qid);
  const fd = new FormData();
  fd.append('action', 'save_answer');
  fd.append('attempt_id', ATTEMPT_ID);
  fd.append('question_id', qid);
  fd.append(type === 'most' ? 'most_idx' : 'least_idx', optIdx);
  fd.append('_csrf', CSRF_TOKEN);

  try {
    const res = await fetch('attempt-answers.php?attempt=' + ATTEMPT_ID, { method: 'POST', body: fd });
    const json = await res.json();
    if (json.ok) {
      showToast('پاسخ DISC ثبت گردید', '✓');
      location.reload();
    }
  } catch(e) {
    showToast('خطا در ذخیره', '❌');
  }
}

/* محاسبه مجدد نمرات کارنامه با AJAX */
async function recalculateScores() {
  const btn = document.getElementById('btnRecalculate');
  btn.disabled = true;
  btn.textContent = '⏳ در حال محاسبهٔ نمرات...';

  const fd = new FormData();
  fd.append('action', 'recalculate');
  fd.append('attempt_id', ATTEMPT_ID);
  fd.append('_csrf', CSRF_TOKEN);

  try {
    const res = await fetch('attempt-answers.php?attempt=' + ATTEMPT_ID, { method: 'POST', body: fd });
    const json = await res.json();
    if (json.ok) {
      showToast(json.message, '🎯');
    } else {
      showToast(json.message || 'خطا در محاسبه', '⚠️');
    }
  } catch (e) {
    showToast('خطای ارتباط با سرور', '❌');
  }
  btn.disabled = false;
  btn.textContent = '🔄 محاسبه مجدد نمرات و کارنامه';
}

/* ارسال پیامک یادآوری ادامه آزمون */
async function sendNudgeSms() {
  if (!confirm('آیا پیامک یادآوری به همراه لینک ادامهٔ آزمون برای مراجع ارسال شود؟')) return;

  const btn = document.getElementById('btnSendNudge');
  btn.disabled = true;
  btn.textContent = '⏳ در حال ارسال پیامک...';

  const fd = new FormData();
  fd.append('action', 'send_nudge');
  fd.append('attempt_id', ATTEMPT_ID);
  fd.append('_csrf', CSRF_TOKEN);

  try {
    const res = await fetch('attempt-answers.php?attempt=' + ATTEMPT_ID, { method: 'POST', body: fd });
    const json = await res.json();
    if (json.ok) {
      showToast(json.message, '📨');
      btn.textContent = '✓ پیامک یادآوری ارسال شد';
      btn.style.background = '#059669';
    } else {
      showToast(json.message || 'خطا در ارسال پیامک', '⚠️');
      btn.disabled = false;
      btn.textContent = '📲 پیامک یادآوری ادامه آزمون';
    }
  } catch (e) {
    showToast('خطای ارتباط با سرور', '❌');
    btn.disabled = false;
    btn.textContent = '📲 پیامک یادآوری ادامه آزمون';
  }
}

/* فیلتر کردن سوالات (همه / پاسخ‌داده‌شده / بدون پاسخ) */
function filterQuestions(type, btn) {
  document.querySelectorAll('.ans-tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const cards = document.querySelectorAll('.ans-q-card');
  cards.forEach(card => {
    if (type === 'all') {
      card.style.display = 'block';
    } else if (type === 'answered') {
      card.style.display = (card.dataset.status === 'answered') ? 'block' : 'none';
    } else if (type === 'unanswered') {
      card.style.display = (card.dataset.status === 'unanswered') ? 'block' : 'none';
    }
  });
}

/* جست‌وجوی زنده در سؤالات */
function searchQuestions(q) {
  q = q.trim().toLowerCase();
  const cards = document.querySelectorAll('.ans-q-card');
  cards.forEach(card => {
    if (!q) { card.style.display = 'block'; return; }
    const text = card.textContent.toLowerCase();
    card.style.display = text.includes(q) ? 'block' : 'none';
  });
}
</script>

<?php admin_layout_end(); ?>
