<?php
/**
 * ============================================================================
 *  API موتور انجام تست روانشناسی (AJAX)
 *  actions: state / questions / save / finish
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/core/Kavenegar.php';
require_once FR_ROOT . '/core/Analyzer.php';
fr_bootstrap();

$u = current_user();
if (!$u) { http_response_code(401); json_out(['ok'=>false,'message'=>'نشست منقضی شده؛ دوباره وارد شوید.']); }

$in      = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$action  = (string)($in['action'] ?? '');
$attemptId = (int)($in['attempt'] ?? 0);

/* جلسهٔ تست روانشناسی معتبرِ همین کاربر */
$at = DB::one('SELECT a.*, t.title, t.slug, t.engine, t.question_count, o.status AS order_status
               FROM ' . DB::t('attempts') . ' a
               JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
               LEFT JOIN ' . DB::t('orders') . ' o ON o.id=a.order_id
               WHERE a.id=? AND a.user_id=?', [$attemptId, $u['id']]);
if (!$at) { http_response_code(404); json_out(['ok'=>false,'message'=>'جلسهٔ تست روانشناسی یافت نشد.']); }

/* نسخهٔ ویژه باید پرداخت‌شده باشد */
if ($at['mode'] === 'full' && $at['order_status'] !== 'paid') {
    json_out(['ok'=>false,'message'=>'abandon_pay','redirect'=>fr_panel_url('test-start.php?id=' . $at['test_id'])]);
}
if ($at['status'] !== 'in_progress' && $action !== 'state') {
    json_out(['ok'=>false,'message'=>'این تست روانشناسی تکمیل شده است.','redirect'=>fr_panel_url('report.php?attempt=' . $attemptId)]);
}

switch ($action) {

    /* ---------- وضعیت جلسه ---------- */
    case 'state': {
        $total    = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1', [$at['test_id']]);
        $answered = fr_attempt_answered($attemptId);
        $firstUnanswered = (int)DB::val(
            'SELECT q.number FROM ' . DB::t('questions') . ' q
             LEFT JOIN ' . DB::t('answers') . ' a ON a.question_id=q.id AND a.attempt_id=?
             WHERE q.test_id=? AND q.active=1
               AND NOT (a.value IS NOT NULL OR (a.most_idx IS NOT NULL AND a.least_idx IS NOT NULL))
             ORDER BY q.number LIMIT 1',
            [$attemptId, $at['test_id']]
        );
        json_out(['ok'=>true,'status'=>$at['status'],'progress'=>$answered,
                  'total'=>$total,'engine'=>$at['engine'],'title'=>$at['title'],
                  'first_unanswered'=>$firstUnanswered]);
    }

    /* ---------- گرفتن دسته‌ای از سؤال‌ها ---------- */
    case 'questions': {
        $offset = max(0, (int)($in['offset'] ?? 0));
        $limit  = min(20, max(1, (int)($in['limit'] ?? 10)));
        $qs = DB::all('SELECT id, number, text, format, options, labels, min_label, max_label, scale_min, scale_max
                       FROM ' . DB::t('questions') .
                      ' WHERE test_id=? AND active=1 ORDER BY number LIMIT ? OFFSET ?',
                      [$at['test_id'], $limit, $offset]);
        // پاسخ‌های قبلی همین دسته (برای ادامهٔ تست روانشناسی)
        $ids = array_column($qs, 'id');
        $prev = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::all('SELECT question_id, value, most_idx, least_idx FROM ' . DB::t('answers') .
                             " WHERE attempt_id=? AND question_id IN ($ph)",
                             array_merge([$attemptId], $ids)) as $a) {
                $prev[$a['question_id']] = $a;
            }
        }
        foreach ($qs as &$q) {
            $q['id']        = (int)$q['id'];
            $q['number']    = (int)$q['number'];
            $q['scale_min'] = (int)$q['scale_min'];
            $q['scale_max'] = (int)$q['scale_max'];
            $q['options']   = !empty($q['options']) ? (is_array($q['options']) ? $q['options'] : json_decode($q['options'], true)) : null;
            $q['labels']    = !empty($q['labels']) ? (is_array($q['labels']) ? $q['labels'] : json_decode($q['labels'], true)) : null; // برچسب اختصاصی هر درجهٔ لیکرت
            $q['prev']      = $prev[$q['id']] ?? null;
        }
        unset($q);
        json_out(['ok'=>true,'questions'=>$qs,'offset'=>$offset]);
    }

    /* ---------- ذخیرهٔ یک پاسخ (خودکار) ---------- */
    case 'save': {
        $qid = (int)($in['question_id'] ?? 0);
        $q = DB::one('SELECT * FROM ' . DB::t('questions') . ' WHERE id=? AND test_id=? AND active=1', [$qid, $at['test_id']]);
        if (!$q) json_out(['ok'=>false,'message'=>'سؤال نامعتبر است.']);

        if ($q['format'] === 'mostleast') {
            $most = isset($in['most_idx']) ? (int)$in['most_idx'] : null;
            $least = isset($in['least_idx']) ? (int)$in['least_idx'] : null;
            if ($most !== null && $least !== null && $most === $least) {
                json_out(['ok'=>false,'message'=>'«بیشترین» و «کمترین» نمی‌توانند یکی باشند.']);
            }
            DB::run('INSERT INTO ' . DB::t('answers') . ' (attempt_id,question_id,most_idx,least_idx)
                     VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE most_idx=VALUES(most_idx), least_idx=VALUES(least_idx)',
                    [$attemptId, $qid, $most, $least]);
        } else {
            $value = en_num((string)($in['value'] ?? ''));
            if (!is_numeric($value) || $value < $q['scale_min'] || $value > $q['scale_max']) {
                json_out(['ok'=>false,'message'=>'پاسخ نامعتبر است.']);
            }
            DB::run('INSERT INTO ' . DB::t('answers') . ' (attempt_id,question_id,value)
                     VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)',
                    [$attemptId, $qid, $value]);
        }
        $answered = fr_attempt_answered($attemptId);
        DB::update('attempts', ['progress'=>$answered], 'id=?', [$attemptId]);
        json_out(['ok'=>true,'progress'=>$answered]);
    }

    /* ---------- پایان تست روانشناسی: بررسی هوشمند بی‌پاسخ‌ها + نمرهگذاری ---------- */
    case 'finish': {
        $total  = (int)DB::val('SELECT COUNT(*) FROM ' . DB::t('questions') . ' WHERE test_id=? AND active=1', [$at['test_id']]);
        $missed = fr_attempt_missing($attemptId, $at['test_id']);
        if ($missed) {
            $n = count($missed);
            json_out(['ok'=>false,
                      'message'=>'هنوز ' . fa_num($n) . ' سؤال بی‌پاسخ مانده؛ به‌ترتیب بهتان نشانش می‌دهیم. 🦢',
                      'missing'=>$missed]);
        }
        if ($total === 0) json_out(['ok'=>false,'message'=>'سؤالی برای این تست روانشناسی فعال نیست.']);

        $duration = (int)($in['duration'] ?? 0);
        if ($duration > 0) {
            DB::update('attempts', ['duration_sec' => $duration], 'id=?', [$attemptId]);
        }

        Analyzer::scoreAttempt($attemptId);
        log_activity('test_done', 'attempt', $attemptId, ['mode'=>$at['mode'],'test'=>$at['slug']]);

        // بررسی و اتصال خودکار به پیوند تطابق دونفره معلق (فاز ۲ و فاز ۳ مدل‌های مالی)
        try {
            $pendingDyad = null;
            $dyadToken = (string)($_SESSION['active_dyad_token'] ?? '');
            if ($dyadToken !== '') {
                $pendingDyad = DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE token=? AND status="pending"', [$dyadToken]);
            }
            if (!$pendingDyad) {
                $cleanMob = clean_mobile((string)$u['mobile']);
                $pendingDyad = DB::one('SELECT * FROM ' . DB::t('dyads') .
                    ' WHERE (user2_id=? OR (partner_mobile IS NOT NULL AND partner_mobile=?)) AND test_id=? AND status="pending" ORDER BY id DESC LIMIT 1',
                    [$u['id'], $cleanMob, $at['test_id']]);
            }
            if ($pendingDyad) {
                $targetMode = $pendingDyad['target_mode'] ?? 'free';
                $payMode    = $pendingDyad['pay_mode'] ?? 'free';
                $isGiftPaid = ($payMode === 'self_paid' && (int)$pendingDyad['partner_paid'] === 1);
                $isFullAtt  = ($at['mode'] === 'full');

                if ($targetMode === 'full' && !$isFullAtt && $isGiftPaid) {
                    // ارتقای خودکار تلاش هدیه به full
                    DB::update('attempts', ['mode' => 'full', 'order_id' => $pendingDyad['order1_id']], 'id=?', [$attemptId]);
                    Analyzer::scoreAttempt($attemptId);
                    $isFullAtt = true;
                }

                if ($targetMode !== 'full' || $isFullAtt) {
                    require_once FR_ROOT . '/core/DyadAnalyzer.php';
                    DB::update('dyads', [
                        'user2_id'    => $u['id'],
                        'attempt2_id' => $attemptId,
                        'status'      => 'ready',
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ], 'id=?', [$pendingDyad['id']]);
                    DyadAnalyzer::analyze((int)$pendingDyad['id']);
                    unset($_SESSION['active_dyad_token']);
                }
            }
        } catch (Throwable $e) {
            error_log('dyad auto-link error: ' . $e->getMessage());
        }

        $name = user_fullname($u);
        $link = fr_panel_url('report.php?attempt=' . $attemptId);
        $sms  = new Kavenegar();
        $sms->sendEvent('test_done', $u['mobile'], ['name'=>$name,'test'=>$at['title'],'link'=>$link,'_tokens'=>[$name,$at['title']]]);
        $sms->sendEvent($at['mode'] === 'full' ? 'report_full' : 'report_free', $u['mobile'],
                        ['name'=>$name,'test'=>$at['title'],'link'=>$link,'_tokens'=>[$name,$at['title'],$link]]);

        json_out(['ok'=>true,'redirect'=>$link]);
    }

    default:
        http_response_code(400);
        json_out(['ok'=>false,'message'=>'درخواست نامعتبر است.']);
}
