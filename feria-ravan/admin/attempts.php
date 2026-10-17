<?php
/**
 * ============================================================================
 *  مرکز تحلیل و کارنامه تست‌های انجام‌شده توسط مراجعین (Client Test Reports & Analyses)
 *  ─ مشاهدهٔ کامل کارنامه‌ها، تحلیل عمیق روان‌شناختی، نمودارها و پیشنهادات مراجع
 *  ─ فیلتر بر اساس آزمون، وضعیت (تکمیل/ناتمام)، نوع نسخه (رایگان/ویژه) و جست‌وجوی مراجع
 *  ─ دسترسی سریع به پرونده بالینی مشاور، ویرایش پاسخ‌ها، چاپ PDF و محاسبه مجدد
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../core/Analyzer.php';
require_once __DIR__ . '/../core/DyadAnalyzer.php';
require_once __DIR__ . '/../core/TrashManager.php';

$adm = require_admin(['superadmin', 'manager', 'support']);
TrashManager::ensureSchema();

/* ══════════════════════════════════════════════════════════════════════════
   ۱) عملیات POST (ارسال پیامک، محاسبه مجدد، ارتقای نسخه، حذف و سطل زباله)
   ══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)post('act');
    $attemptId = (int)post('attempt_id');

    $atRow = DB::one('SELECT a.*, t.title AS ttitle, u.first_name, u.last_name, u.mobile
                      FROM ' . DB::t('attempts') . ' a
                      JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
                      JOIN ' . DB::t('users') . ' u ON u.id=a.user_id
                      WHERE a.id=?', [$attemptId]);

    // محاسبه آدرس بازگشت هوشمند به صفحه و فیلتر دقیق آزمون مراجع
    $resolveReturnUrl = function(?string $defaultView = null) use ($atRow): string {
        $rUrl = trim((string)post('return_url'));
        if ($rUrl !== '') {
            if (str_starts_with($rUrl, 'attempts.php') || str_starts_with($rUrl, '/admin/attempts.php') || str_starts_with($rUrl, SITE_URL . '/admin/attempts.php')) {
                return $rUrl;
            }
        }
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '' && str_contains($ref, 'attempts.php')) {
            return $ref;
        }
        $targetTestId = (int)post('test_id', (int)($atRow['test_id'] ?? 0));
        $params = [];
        if ($targetTestId > 0) $params['test_id'] = $targetTestId;
        if ($defaultView) $params['view'] = $defaultView;
        return SITE_URL . '/admin/attempts.php' . ($params ? '?' . http_build_query($params) : '');
    };

    if (!$atRow && in_array($act, ['sms_report', 'sms_nudge', 'rescore', 'upgrade_mode', 'del_attempt', 'trash_attempt', 'restore_attempt', 'purge_attempt'], true)) {
        flash('جلسهٔ آزمون مورد نظر یافت نشد.', 'err');
        redirect($resolveReturnUrl());
    }

    // انتقال به سطل زباله
    if ($act === 'trash_attempt' || $act === 'del_attempt') {
        if (TrashManager::trash('attempt', $attemptId, (int)($adm['id'] ?? 0))) {
            flash('جلسهٔ آزمون مراجع با موفقیت به سطل زباله منتقل گردید 🗑 (امکان بازیابی در سطل زباله وجود دارد)', 'ok');
        } else {
            flash('خطا در انتقال جلسهٔ آزمون به سطل زباله.', 'err');
        }
        redirect($resolveReturnUrl());
    }

    // بازیابی از سطل زباله
    if ($act === 'restore_attempt') {
        if (TrashManager::restore('attempt', $attemptId)) {
            flash('جلسهٔ آزمون مراجع با موفقیت بازیابی و فعال گردید ♻️', 'ok');
        } else {
            flash('خطا در بازیابی جلسهٔ آزمون.', 'err');
        }
        redirect($resolveReturnUrl('trash'));
    }

    // حذف قطعی و دائمی
    if ($act === 'purge_attempt') {
        if (TrashManager::purge('attempt', $attemptId)) {
            flash('جلسهٔ آزمون و تمامی نتایج آن برای همیشه از سیستم حذف گردید ❌', 'ok');
        } else {
            flash('خطا در حذف قطعی جلسهٔ آزمون.', 'err');
        }
        redirect($resolveReturnUrl('trash'));
    }

    // ارسال لینک کارنامه با پیامک برای مراجع
    if ($act === 'sms_report') {
        $link = fr_panel_url('report.php?attempt=' . $atRow['id']);
        $name = user_fullname($atRow);
        $kv = new Kavenegar();
        $ok = $kv->sendEvent('report_full', $atRow['mobile'], [
            'name'    => $name,
            'test'    => $atRow['ttitle'],
            'link'    => $link,
            '_tokens' => [$name, $atRow['ttitle'], $link],
        ]);
        if (!$ok) {
            $msg = "سلام {$name} عزیز\nگزارش و کارنامه تحلیل آزمون «{$atRow['ttitle']}» شما آماده است:\n{$link}\nفریا روان";
            $ok = $kv->send($atRow['mobile'], $msg, 'report_link');
        }
        flash($ok ? 'لینک کارنامه با موفقیت برای مراجع پیامک شد ✅' : 'ارسال ناموفق؛ تنظیمات درگاه پیامک را بررسی فرمایید.', $ok ? 'ok' : 'err');
        log_activity('admin_sms_report', 'attempt', $atRow['id']);
        redirect(SITE_URL . '/admin/attempts.php' . (!empty($_POST['redirect_view']) ? '?report=' . $attemptId : ''));
    }

    // ارسال پیامک یادآوری تکمیل آزمون ناتمام
    if ($act === 'sms_nudge') {
        $link = fr_panel_url('take.php?attempt=' . $atRow['id']);
        $name = user_fullname($atRow);
        $kv = new Kavenegar();
        $ok = $kv->sendEvent('test_nudge', $atRow['mobile'], [
            'name'    => $name,
            'test'    => $atRow['ttitle'],
            'link'    => $link,
            '_tokens' => [$name, $atRow['ttitle'], $link],
        ]);
        if (!$ok) {
            $msg = "سلام {$name} عزیز\nآزمون «{$atRow['ttitle']}» شما در فریا روان نیمه‌کاره است. جهت تکمیل سوالات و دریافت کارنامه، روی لینک زیر بزنید:\n{$link}";
            $ok = $kv->send($atRow['mobile'], $msg, 'test_nudge');
        }
        if ($ok) {
            DB::update('attempts', ['nudge_sent' => 1], 'id=?', [$atRow['id']]);
            flash('پیامک یادآوری ادامه آزمون برای مراجع ارسال شد ✅', 'ok');
            log_activity('admin_sms_nudge', 'attempt', $atRow['id']);
        } else {
            flash('ارسال پیامک با خطا مواجه شد.', 'err');
        }
        redirect($resolveReturnUrl());
    }

    // محاسبه و تحلیل مجدد نمرات آزمون
    if ($act === 'rescore') {
        try {
            Analyzer::scoreAttempt($attemptId);
            // اگر پیوند زوجین/تطابق وجود دارد آن را هم به‌روز کنیم
            $dyad = DB::one('SELECT id FROM ' . DB::t('dyads') . ' WHERE attempt1_id=? OR attempt2_id=?', [$attemptId, $attemptId]);
            if ($dyad) {
                DyadAnalyzer::analyze((int)$dyad['id']);
            }
            flash('نمرات و کارنامه مراجع با موفقیت مجدداً محاسبه و تحلیل شد 🔄', 'ok');
            log_activity('admin_rescore', 'attempt', $attemptId);
        } catch (Throwable $e) {
            flash('خطا در محاسبه مجدد: ' . $e->getMessage(), 'err');
        }
        redirect(SITE_URL . '/admin/attempts.php' . (!empty($_POST['redirect_view']) ? '?report=' . $attemptId : ''));
    }

    // ارتقای مستقیم نسخه آزمون به نسخه ویژه (کامل) توسط مدیر
    if ($act === 'upgrade_mode') {
        try {
            DB::update('attempts', ['mode' => 'full'], 'id=?', [$attemptId]);
            Analyzer::scoreAttempt($attemptId);
            flash('آزمون مراجع با موفقیت به نسخهٔ کامل و تخصصی ارتقا یافت ⭐', 'ok');
            log_activity('admin_upgrade_attempt', 'attempt', $attemptId);
        } catch (Throwable $e) {
            flash('خطا در ارتقا: ' . $e->getMessage(), 'err');
        }
        redirect(SITE_URL . '/admin/attempts.php' . (!empty($_POST['redirect_view']) ? '?report=' . $attemptId : ''));
    }

    // حذف ایمن آزمون
    if ($act === 'del_attempt') {
        $hasPaid = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('orders') . " WHERE id=? AND status='paid'", [$atRow['order_id'] ?? 0]);
        if ($hasPaid > 0) {
            flash('این جلسهٔ آزمون دارای سفارش پرداخت‌شده است و جهت حفظ شفافیت مالی قابل حذف نیست.', 'err');
        } else {
            DB::run('DELETE FROM ' . DB::t('answers') . ' WHERE attempt_id=?', [$attemptId]);
            DB::run('DELETE FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$attemptId]);
            DB::run('DELETE FROM ' . DB::t('attempts') . ' WHERE id=?', [$attemptId]);
            flash('جلسهٔ آزمون و تمام نتایج آن با موفقیت حذف گردید 🗑', 'ok');
            log_activity('admin_del_attempt', 'attempt', $attemptId);
        }
        redirect($resolveReturnUrl());
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   ۲) نمایش اختصاصی کارنامه و تحلیل مراجع (Detailed Analysis View)
   ══════════════════════════════════════════════════════════════════════════ */
$viewAttemptId = (int)($_GET['report'] ?? ($_GET['view'] ?? 0));
if ($viewAttemptId > 0) {
    $at = DB::one('SELECT a.*, t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine,
                          t.category AS test_category, t.price_full, t.price_sale,
                          u.first_name, u.last_name, u.mobile, u.email, u.national_code,
                          u.birth_date, u.job_title,
                          o.payable AS order_amount, o.status AS order_status, o.id AS order_id
                   FROM ' . DB::t('attempts') . ' a
                   JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
                   JOIN ' . DB::t('users') . ' u ON u.id=a.user_id
                   LEFT JOIN ' . DB::t('orders') . ' o ON o.id=a.order_id
                   WHERE a.id=?', [$viewAttemptId]);

    if (!$at) {
        flash('جلسهٔ آزمون مراجع یافت نشد.', 'err');
        redirect(SITE_URL . '/admin/attempts.php');
    }

    $uObj = [
        'id'            => $at['user_id'],
        'first_name'    => $at['first_name'],
        'last_name'     => $at['last_name'],
        'mobile'        => $at['mobile'],
        'national_code' => $at['national_code'],
        'birth_date'    => $at['birth_date'],
        'job_title'     => $at['job_title'],
    ];

    // مدیر می‌تواند حتی اگر مراجع نسخه رایگان انجام داده باشد، سطح نمایش تحلیل را به ویژه تغییر دهد
    $requestedLevel = (string)($_GET['level'] ?? '');
    $viewLevel = ($requestedLevel === 'free') ? 'free' : 'full';

    admin_layout($adm, 'attempts', 'کارنامه و تحلیل روان‌شناختی: ' . user_fullname($uObj));
    echo flash();
    ?>
    <div style="max-width:1040px;margin:0 auto">
      <!-- نوار ابزار بالای گزارش مراجع -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px">
        <a class="fr-btn ghost sm" href="<?= !empty($at['test_id']) ? 'attempts.php?test_id=' . (int)$at['test_id'] : 'attempts.php' ?>">← بازگشت به فهرست نتایج این آزمون (<?= e($at['test_title'] ?? '') ?>)</a>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="fr-btn sm" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $at['id'] . '&download=1')) ?>" target="_blank">📥 دریافت فایل PDF کارنامه</a>
          <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $at['id'])) ?>" target="_blank" title="مشاهده سند رسمی کارنامه A4">📄 مشاهده نسخه A4</a>
          <a class="fr-btn sm" style="background:#4338ca;color:#fff" href="clinical-case.php?attempt=<?= $at['id'] ?>" target="_blank">📋 پرونده بالینی مشاور</a>
          <a class="fr-btn sm ghost" href="attempt-answers.php?attempt=<?= $at['id'] ?>" target="_blank">📝 مشاهده و ویرایش پاسخ‌ها</a>

          <form method="post" style="display:inline" onsubmit="return confirm('آیا از محاسبه مجدد نمرات و بازسازی تحلیل اطمینان دارید؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="rescore">
            <input type="hidden" name="attempt_id" value="<?= $at['id'] ?>">
            <input type="hidden" name="redirect_view" value="1">
            <button class="fr-btn sm ghost" type="submit" title="محاسبه مجدد نمرات و تولید دوباره گراف‌ها">🔄 تحلیل مجدد</button>
          </form>

          <form method="post" style="display:inline" onsubmit="return confirm('لینک مشاهده کارنامه برای شماره <?= fa_num($at['mobile']) ?> پیامک شود؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="sms_report">
            <input type="hidden" name="attempt_id" value="<?= $at['id'] ?>">
            <input type="hidden" name="redirect_view" value="1">
            <button class="fr-btn sm" style="background:#059669;color:#fff" type="submit">📲 پیامک کارنامه به مراجع</button>
          </form>

          <?php if ($at['mode'] === 'free'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('آیا می‌خواهید دسترسی این آزمون را به نسخه ویژه ارتقا دهید؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="upgrade_mode">
            <input type="hidden" name="attempt_id" value="<?= $at['id'] ?>">
            <input type="hidden" name="redirect_view" value="1">
            <button class="fr-btn sm" style="background:#7c3aed;color:#fff" type="submit">⭐ ارتقا به نسخه ویژه</button>
          </form>
          <?php endif; ?>

          <form method="post" style="display:inline" onsubmit="return confirm('آیا از انتقال این کارنامه آزمون به سطل زباله اطمینان دارید؟\n(امکان بازیابی از سطل زباله وجود دارد)');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="trash_attempt">
            <input type="hidden" name="attempt_id" value="<?= $at['id'] ?>">
            <input type="hidden" name="return_url" value="<?= e('attempts.php?test_id=' . (int)$at['test_id']) ?>">
            <input type="hidden" name="test_id" value="<?= (int)$at['test_id'] ?>">
            <button class="fr-btn sm danger" type="submit" title="انتقال به سطل زباله">🗑 حذف / سطل زباله</button>
          </form>
        </div>
      </div>

      <!-- کارت مشخصات مراجع و جلسه آزمون -->
      <div class="fr-card" style="margin-bottom:20px;padding:18px 22px;background:#f8fafc;border:1px solid #cbd5e1">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px">
          <div>
            <div style="font-size:17px;font-weight:800;color:var(--ink)">
              👤 <?= e(user_fullname($uObj)) ?>
              <a href="user-view.php?id=<?= $at['user_id'] ?>" target="_blank" style="font-size:12px;font-weight:400;color:var(--c-primary);margin-right:8px">(مشاهده پرونده کامل کاربر ↗)</a>
            </div>
            <div style="font-size:13px;color:var(--muted);margin-top:6px;line-height:1.8">
              📱 موبایل: <b><?= fa_num($at['mobile']) ?></b> &nbsp;·&nbsp;
              کد ملی: <b><?= e($at['national_code'] ?: 'ثبت‌نشده') ?></b> &nbsp;·&nbsp;
              شغل: <b><?= e($at['job_title'] ?: 'نامشخص') ?></b>
            </div>
          </div>
          <div style="text-align:left;line-height:1.8">
            <span class="fr-chip <?= $at['mode']==='full'?'purple':'info' ?>" style="font-size:12px;padding:4px 10px">
              <?= $at['mode']==='full' ? '⭐ نسخهٔ ویژه (کامل)' : '🎁 نسخهٔ رایگان' ?>
            </span>
            <span class="fr-chip ok" style="font-size:12px;padding:4px 10px">تکمیل‌شده</span>
            <div style="font-size:12px;color:var(--muted);margin-top:4px">
              تاریخ ثبت: <?= fa_num(jdate('Y/m/d - H:i', strtotime($at['completed_at'] ?? $at['started_at']))) ?>
              <?php if (!empty($at['duration_sec'])): ?>
                (مدت: <?= fa_num((int)round($at['duration_sec'] / 60)) ?> دقیقه)
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- انتخاب حالت نمایش تحلیل برای مدیر -->
        <div style="margin-top:14px;padding-top:12px;border-top:1px dashed #cbd5e1;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
          <span style="font-size:12.5px;color:var(--muted)">
            سطح نمایش تحلیل فعلی: <b style="color:var(--c-primary)"><?= $viewLevel === 'full' ? 'تحلیل جامع و فوق‌تخصصی (نسخه کامل مشاور)' : 'تحلیل خلاصه و اولیه (نسخه رایگان)' ?></b>
          </span>
          <div style="display:flex;gap:6px">
            <a class="fr-btn sm <?= $viewLevel === 'full' ? 'primary' : 'ghost' ?>" href="attempts.php?report=<?= $at['id'] ?>&level=full">نمایش تحلیل کامل مشاور (Full)</a>
            <a class="fr-btn sm <?= $viewLevel === 'free' ? 'primary' : 'ghost' ?>" href="attempts.php?report=<?= $at['id'] ?>&level=free">نمایش نسخه مراجع (Free)</a>
          </div>
        </div>
      </div>

      <!-- اگر پیوند زوجین / تطابق دونفره دارد -->
      <?php
      $assocDyad = DB::one('SELECT d.*, t.title AS dyad_test_title,
                                   u1.first_name AS u1_fn, u1.last_name AS u1_ln,
                                   u2.first_name AS u2_fn, u2.last_name AS u2_ln
                            FROM ' . DB::t('dyads') . ' d
                            JOIN ' . DB::t('tests') . ' t ON t.id=d.test_id
                            LEFT JOIN ' . DB::t('users') . ' u1 ON u1.id=d.user1_id
                            LEFT JOIN ' . DB::t('users') . ' u2 ON u2.id=d.user2_id
                            WHERE d.attempt1_id=? OR d.attempt2_id=? LIMIT 1', [$at['id'], $at['id']]);
      if ($assocDyad):
        $p1 = trim(($assocDyad['u1_fn'] ?? '') . ' ' . ($assocDyad['u1_ln'] ?? '')) ?: 'کاربر ۱';
        $p2 = trim(($assocDyad['u2_fn'] ?? '') . ' ' . ($assocDyad['u2_ln'] ?? '')) ?: ($assocDyad['partner_name'] ?? 'همراه');
      ?>
      <div class="fr-card" style="background:#faf5ff;border:2px solid #8b5cf6;margin-bottom:24px;padding:16px 20px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
          <div>
            <div style="font-weight:700;font-size:14.5px;color:#5b21b6">
              👥 پیوند سنجش تطابق دونفره و زوجین: «<?= e($p1) ?>» ↔ «<?= e($p2) ?>»
            </div>
            <div style="font-size:12.5px;color:#6b21a8;margin-top:4px">
              نوع رابطه: <?= e(DyadAnalyzer::$REL_TYPES[$assocDyad['rel_type']] ?? 'دونفره') ?>
              <?php if (!empty($assocDyad['synergy_score'])): ?>
                &nbsp;·&nbsp; ✨ شاخص هم‌افزایی محاسبه‌شده: <b><?= fa_num($assocDyad['synergy_score']) ?>٪</b>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($assocDyad['status'] === 'ready'): ?>
            <a class="fr-btn sm" style="background:#6d28d9" href="<?= SITE_URL ?>/panel/dyad-report.php?id=<?= $assocDyad['id'] ?>" target="_blank">
              مشاهده کارنامه تطابق دونفره ↗
            </a>
          <?php else: ?>
            <span class="fr-badge warn">در انتظار همراه</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- اطلاعات و گزینه‌های پیش از آزمون مراجع -->
      <?php
      if (!empty($at['custom_fields_json'])):
        require_once FR_ROOT . '/core/TestFields.php';
        echo TestFields::renderDisplayCard($at['custom_fields_json'], '📋 اطلاعات ثبت‌شده پیش از آزمون مراجع (همراه، مشاوره و فیلدهای سفارشی)');
      endif;
      ?>

      <!-- رندر کامل کارنامه و تحلیل روان‌سنجی -->
      <div style="background:#fff;border-radius:18px;border:1px solid var(--border);padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.03)">
        <?= ReportBuilder::build($at, $uObj, $viewLevel, ['isAdmin' => true]) ?>
      </div>
    </div>
    <?php
    admin_layout_end();
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════
   ۳) فهرست جامع آزمون‌های مراجعین (Main List View)
   ══════════════════════════════════════════════════════════════════════════ */
$viewMode       = (string)($_GET['view'] ?? 'active');
$filterTest     = (int)($_GET['test_id'] ?? 0);
$filterStatus   = (string)($_GET['status'] ?? '');
$filterMode     = (string)($_GET['mode'] ?? '');
$filterCategory = (string)($_GET['category'] ?? '');
$searchQ        = trim((string)($_GET['q'] ?? ''));

$whereParts = ($viewMode === 'trash') ? ["a.deleted_at IS NOT NULL"] : ["a.deleted_at IS NULL"];
$params = [];

if ($filterTest > 0) {
    $whereParts[] = "a.test_id = ?";
    $params[] = $filterTest;
}

if ($filterStatus === 'completed') {
    $whereParts[] = "a.status = 'completed'";
} elseif ($filterStatus === 'in_progress') {
    $whereParts[] = "a.status = 'in_progress'";
}

if ($filterMode === 'full') {
    $whereParts[] = "a.mode = 'full'";
} elseif ($filterMode === 'free') {
    $whereParts[] = "a.mode = 'free'";
}

if ($filterCategory !== '' && in_array($filterCategory, ['clinical', 'personality', 'intelligence'], true)) {
    $whereParts[] = "t.category = ?";
    $params[] = $filterCategory;
}

if ($searchQ !== '') {
    $cleanSearch = en_num($searchQ);
    $whereParts[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.mobile LIKE ? OR u.national_code LIKE ? OR a.id = ?)";
    $like = '%' . $searchQ . '%';
    $likeNum = '%' . $cleanSearch . '%';
    $params = array_merge($params, [$like, $like, $likeNum, $likeNum, (int)$cleanSearch]);
}

$whereSql = implode(' AND ', $whereParts);

// دریافت آزمون‌ها
$attempts = DB::all("
    SELECT a.*,
           t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine, t.category AS test_category,
           u.first_name, u.last_name, u.mobile, u.national_code,
           o.payable AS order_amount, o.status AS order_status, o.id AS order_id,
           (SELECT COUNT(*) FROM " . DB::t('answers') . " ans WHERE ans.attempt_id = a.id) AS answers_count,
           (SELECT COUNT(*) FROM " . DB::t('dyads') . " dy WHERE dy.attempt1_id = a.id OR dy.attempt2_id = a.id) AS dyad_count
    FROM " . DB::t('attempts') . " a
    JOIN " . DB::t('tests') . " t ON t.id = a.test_id
    JOIN " . DB::t('users') . " u ON u.id = a.user_id
    LEFT JOIN " . DB::t('orders') . " o ON o.id = a.order_id
    WHERE {$whereSql}
    ORDER BY a.id DESC
    LIMIT 300
", $params);

// آمارهای کلی
$statTotal     = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE deleted_at IS NULL");
$statCompleted = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE deleted_at IS NULL AND status = 'completed'");
$statFull      = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE deleted_at IS NULL AND mode = 'full'");
$statOpen      = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE deleted_at IS NULL AND status = 'in_progress'");
$statTrash     = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE deleted_at IS NOT NULL");

// لیست تست‌ها جهت فیلتر
$allTests = DB::all("SELECT id, title, category FROM " . DB::t('tests') . " WHERE status <> 'trash' ORDER BY sort_order");

admin_layout($adm, 'attempts', ($viewMode === 'trash' ? '🗑 سطل زباله آزمون‌ها' : '📊 نتایج و تحلیل تست‌های انجام‌شده توسط مراجعین'));
echo flash();
?>
<div style="max-width:1160px;margin:0 auto">

  <!-- کارت‌های شاخص‌های کلیدی (KPIs) -->
  <div class="fr-grid cols-4" style="margin-bottom:16px">
    <?php stat_card('🧠', 'کل آزمون‌های انجام‌شده', fa_num($statTotal)); ?>
    <?php stat_card('✅', 'تکمیل و تحلیل‌شده', fa_num($statCompleted), '#ecfbf4'); ?>
    <?php stat_card('⭐', 'دارای تحلیل ویژه و کامل', fa_num($statFull), '#f6f1ff'); ?>
    <?php stat_card('🗑', 'موجود در سطل زباله', fa_num($statTrash), '#fee2e2'); ?>
  </div>

  <!-- نوار تغییر نما و فیلتر -->
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
    <a class="fr-chip <?= $viewMode==='active'?'purple':'info' ?>" href="attempts.php?view=active" style="text-decoration:none">
      لیست آزمون‌های فعال (<?= fa_num($statTotal) ?>)
    </a>
    <a class="fr-chip <?= $viewMode==='trash'?'purple':'info' ?>" href="attempts.php?view=trash" style="text-decoration:none">
      🗑 سطل زباله آزمون‌ها (<?= fa_num($statTrash) ?>)
    </a>
    <a class="fr-chip ghost" href="<?= SITE_URL ?>/admin/trash.php?tab=attempts" style="text-decoration:none;margin-right:auto">
      مدیریت یکپارچه سطل زباله ↗
    </a>
  </div>

  <!-- نوار فیلتر و جست‌وجوی چندمعیاره پیشرفته -->
  <div class="fr-card" style="margin-bottom:20px;padding:16px 20px">
    <form method="get" action="attempts.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:200px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:5px">آزمون روانشناسی:</label>
        <select class="fr-select" name="test_id" style="width:100%">
          <option value="0">همهٔ آزمون‌ها</option>
          <?php foreach ($allTests as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filterTest === (int)$t['id'] ? 'selected' : '' ?>>
              <?= e($t['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="min-width:130px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:5px">وضعیت:</label>
        <select class="fr-select" name="status" style="width:100%">
          <option value="">همهٔ وضعیت‌ها</option>
          <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>✅ تکمیل‌شده</option>
          <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>>⏳ ناتمام</option>
        </select>
      </div>

      <div style="min-width:130px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:5px">نوع نسخه:</label>
        <select class="fr-select" name="mode" style="width:100%">
          <option value="">همهٔ نسخه‌ها</option>
          <option value="full" <?= $filterMode === 'full' ? 'selected' : '' ?>>⭐ ویژه (کامل)</option>
          <option value="free" <?= $filterMode === 'free' ? 'selected' : '' ?>>🎁 رایگان</option>
        </select>
      </div>

      <div style="min-width:140px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:5px">دسته‌بندی:</label>
        <select class="fr-select" name="category" style="width:100%">
          <option value="">همهٔ دسته‌ها</option>
          <option value="clinical" <?= $filterCategory === 'clinical' ? 'selected' : '' ?>>🩺 بالینی و سلامت</option>
          <option value="personality" <?= $filterCategory === 'personality' ? 'selected' : '' ?>>🎭 شخصیت</option>
          <option value="intelligence" <?= $filterCategory === 'intelligence' ? 'selected' : '' ?>>💡 هوش و استعداد</option>
        </select>
      </div>

      <div style="flex:1;min-width:180px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:5px">جست‌وجوی مراجع:</label>
        <input class="fr-input" name="q" placeholder="نام، شماره موبایل یا کدملی" value="<?= e($searchQ) ?>">
      </div>

      <div style="display:flex;gap:6px">
        <button class="fr-btn" type="submit">🔎 اعمال فیلتر</button>
        <?php if ($filterTest || $filterStatus || $filterMode || $filterCategory || $searchQ): ?>
          <a class="fr-btn ghost" href="attempts.php" title="پاک‌سازی فیلترها">✕</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- جدول فهرست مراجعین و آزمون‌ها -->
  <div class="fr-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
      <h3 style="margin:0;font-size:16px">
        📋 نتایج و کارنامه‌های ثبت‌شده (<?= fa_num(count($attempts)) ?> مورد)
      </h3>
      <?php if (count($attempts) >= 300): ?>
        <small class="hint">نمایش ۳۰۰ رکورد اخیر؛ جهت دسترسی به موارد بیشتر فیلتر اعمال نمایید.</small>
      <?php endif; ?>
    </div>

    <div style="overflow-x:auto">
      <table class="fr-table">
        <thead>
          <tr>
            <th style="width:45px">#ID</th>
            <th>مراجع</th>
            <th>موبایل</th>
            <th>آزمون روانشناسی</th>
            <th>نوع</th>
            <th>وضعیت</th>
            <th>شاخص / نتیجه</th>
            <th>تاریخ ثبت</th>
            <th style="min-width:210px">عملیات تحلیلی مشاور</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($attempts)): ?>
            <tr>
              <td colspan="9" style="text-align:center;padding:32px 0;color:var(--muted)">
                هیچ آزمونی با مشخصات انتخابی یافت نشد.
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($attempts as $a):
            $clientName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'مراجع';
            $isCompleted = ($a['status'] === 'completed');
            $isFull = ($a['mode'] === 'full');
          ?>
          <tr>
            <td style="font-size:11.5px;color:var(--muted)">#<?= fa_num((int)$a['id']) ?></td>
            <td>
              <b>
                <a href="user-view.php?id=<?= $a['user_id'] ?>" style="color:inherit" title="مشاهده پرونده مراجع">
                  <?= e($clientName) ?>
                </a>
              </b>
            </td>
            <td dir="ltr" style="font-size:12.5px;font-family:monospace;text-align:right">
              <?= fa_num($a['mobile']) ?>
            </td>
            <td>
              <div style="font-size:13px;font-weight:600">
                <?= e($a['test_title']) ?>
              </div>
              <span class="fr-chip" style="font-size:10px;padding:2px 6px">
                <?= match($a['test_category'] ?? '') {
                    'clinical'     => '🩺 بالینی',
                    'personality'  => '🎭 شخصیت',
                    'intelligence' => '💡 هوش/شغل',
                    default        => e((string)($a['test_engine'] ?? 'روان‌سنجی'))
                } ?>
              </span>
              <?php if ((int)$a['dyad_count'] > 0): ?>
                <span class="fr-chip purple" style="font-size:10px;padding:2px 6px" title="دارای پیوند زوجین/تطابق">👥 تطابق</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="fr-chip <?= $isFull ? 'purple' : 'info' ?>" style="font-size:11px">
                <?= $isFull ? '⭐ ویژه' : 'رایگان' ?>
              </span>
            </td>
            <td>
              <?php if ($isCompleted): ?>
                <span class="fr-chip ok" style="font-size:11.5px">✅ تکمیل</span>
              <?php else: ?>
                <span class="fr-chip warn" style="font-size:11.5px" title="تعداد پاسخ: <?= (int)$a['answers_count'] ?>">
                  ⏳ ناتمام (<?= fa_num((int)$a['answers_count']) ?>)
                </span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px">
              <?php
              // استخراج الگوی شاخص بر اساس موتور
              $contextStr = (string)($a['context'] ?? '');
              $patternVal = '—';
              if (preg_match('/pattern:([A-Za-z\/]+)/', $contextStr, $m)) {
                  $patternVal = '<b style="color:#6d28d9">' . e($m[1]) . '</b>';
              } elseif ($isCompleted) {
                  $patternVal = '<span style="color:#059669">تحلیل‌شده</span>';
              }
              echo $patternVal;
              ?>
            </td>
            <td style="font-size:12px;white-space:nowrap">
              <?= fa_num(jdate('Y/m/d - H:i', strtotime($a['completed_at'] ?? $a['started_at']))) ?>
            </td>
            <td class="actions" style="white-space:nowrap">
              <?php $retUrl = 'attempts.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''); ?>
              <?php if ($viewMode === 'trash'): ?>
                <!-- دکمه‌های حالت سطل زباله -->
                <form method="post" style="display:inline" onsubmit="return confirm('آیا از بازیابی این آزمون و فعال‌سازی مجدد آن اطمینان دارید؟');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="restore_attempt">
                  <input type="hidden" name="attempt_id" value="<?= $a['id'] ?>">
                  <input type="hidden" name="return_url" value="<?= e($retUrl) ?>">
                  <input type="hidden" name="test_id" value="<?= (int)($filterTest ?: ($a['test_id'] ?? 0)) ?>">
                  <button class="fr-btn sm ok" style="background:#059669;color:#fff" type="submit" title="بازیابی آزمون">
                    ♻️ بازیابی
                  </button>
                </form>

                <form method="post" style="display:inline" onsubmit="return confirm('⚠️ حذف نهایی:\n\nآیا از حذف دائمی این آزمون و پاکسازی تمام داده‌های آن اطمینان دارید؟');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="purge_attempt">
                  <input type="hidden" name="attempt_id" value="<?= $a['id'] ?>">
                  <input type="hidden" name="return_url" value="<?= e($retUrl) ?>">
                  <input type="hidden" name="test_id" value="<?= (int)($filterTest ?: ($a['test_id'] ?? 0)) ?>">
                  <button class="fr-btn sm danger" type="submit" title="حذف دائمی">
                    ❌ حذف قطعی
                  </button>
                </form>
              <?php else: ?>
                <!-- دکمه‌های حالت فعال -->
                <?php if ($isCompleted): ?>
                  <a class="fr-btn sm" style="background:#6d28d9;color:#fff" href="attempts.php?report=<?= $a['id'] ?>" title="مشاهده کارنامه و تحلیل کامل روان‌سنجی">
                    👁 تحلیل کارنامه
                  </a>
                  <a class="fr-btn sm" style="background:#4338ca;color:#fff" href="clinical-case.php?attempt=<?= $a['id'] ?>" target="_blank" title="خلاصه پرونده بالینی و تشخیصی مشاور">
                    📋 پرونده
                  </a>
                  <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $a['id'] . '&download=1')) ?>" target="_blank" title="دانلود مستقیم فایل PDF کارنامه">
                    📥 PDF
                  </a>
                  <a class="fr-btn sm ghost" href="<?= e(fr_panel_url('report-pdf.php?attempt=' . $a['id'])) ?>" target="_blank" title="مشاهده نسخه رسمی کارنامه A4">
                    📄 برگه A4
                  </a>
                <?php else: ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('پیامک یادآوری به همراه لینک ادامه برای مراجع ارسال شود؟')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="sms_nudge">
                    <input type="hidden" name="attempt_id" value="<?= $a['id'] ?>">
                    <input type="hidden" name="return_url" value="<?= e($retUrl) ?>">
                    <input type="hidden" name="test_id" value="<?= (int)($filterTest ?: ($a['test_id'] ?? 0)) ?>">
                    <button class="fr-btn sm" style="background:#f59e0b;color:#fff" type="submit" title="ارسال پیامک یادآوری تکمیل">
                      📲 یادآوری
                    </button>
                  </form>
                <?php endif; ?>

                <a class="fr-btn sm ghost" href="attempt-answers.php?attempt=<?= $a['id'] ?>" target="_blank" title="مشاهده و ویرایش سوالات و پاسخ‌ها">
                  📝 پاسخ‌ها
                </a>

                <form method="post" style="display:inline" onsubmit="return confirm('انتقال این جلسه آزمون به سطل زباله؟\n(امکان بازیابی از سطل زباله وجود دارد.)');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="act" value="trash_attempt">
                  <input type="hidden" name="attempt_id" value="<?= $a['id'] ?>">
                  <input type="hidden" name="return_url" value="<?= e($retUrl) ?>">
                  <input type="hidden" name="test_id" value="<?= (int)($filterTest ?: ($a['test_id'] ?? 0)) ?>">
                  <button class="fr-btn sm danger" style="padding:5px 8px" type="submit" title="انتقال به سطل زباله">🗑</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php admin_layout_end(); ?>
