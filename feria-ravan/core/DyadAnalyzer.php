<?php
/**
 * ============================================================================
 *  موتور سنجش تطابق دونفره و زوجین فریا روان (Dyadic & Couples Synergy Engine)
 *  ─ تحلیل ماتریس سازگاری، نقاط قوت هم‌افزا و کانون‌های اصطکاک رابطه
 *  ─ پروتکل گفت‌وگوی امن اختصاصی متناسب با تیپ رفتاری و شخصیتی دو طرف
 *  ─ دریافت مشخصات و موبایل همراه + ارسال پیامک خودکار دعوت
 *  ─ پوشش جامع آزمون‌های DISC، MBTI، NEO، گاردنر، بار-آن و طرحواره‌های یانگ
 * ============================================================================
 */
declare(strict_types=1);

final class DyadAnalyzer
{
    /** عناوین فارسی نوع رابطه */
    public static array $REL_TYPES = [
        'couple'   => 'پارتنر عاطفی / نامزد',
        'spouse'   => 'همسر / زندگی مشترک',
        'coworker' => 'همکار / هم‌تیمی',
        'business' => 'شریک تجاری / کاری',
        'friend'   => 'دوست صمیمی / رفیق',
    ];

    /**
     * تضمین خودکار ساختار جدول پیوندهای دونفره و ستون‌های مالی و تحلیلی (Self-healing schema guard)
     */
    public static function ensureDyadSchema(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $p = DB_PREFIX;
            DB::run("CREATE TABLE IF NOT EXISTS `{$p}dyads` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `token` VARCHAR(32) NOT NULL UNIQUE,
              `test_id` INT UNSIGNED NOT NULL,
              `user1_id` BIGINT UNSIGNED NOT NULL,
              `attempt1_id` BIGINT UNSIGNED NOT NULL,
              `order1_id` BIGINT UNSIGNED NULL,
              `user2_id` BIGINT UNSIGNED NULL,
              `attempt2_id` BIGINT UNSIGNED NULL,
              `order2_id` BIGINT UNSIGNED NULL,
              `rel_type` ENUM('couple','spouse','coworker','friend','business') NOT NULL DEFAULT 'couple',
              `pay_mode` ENUM('free','self_paid','partner_paid') NOT NULL DEFAULT 'free',
              `target_mode` ENUM('free','full') NOT NULL DEFAULT 'free',
              `partner_paid` TINYINT(1) NOT NULL DEFAULT 0,
              `partner_name` VARCHAR(150) NULL,
              `partner_mobile` VARCHAR(20) NULL,
              `status` ENUM('pending','ready','archived') NOT NULL DEFAULT 'pending',
              `synergy_score` INT UNSIGNED NULL,
              `summary_json` MEDIUMTEXT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
              KEY `ix_u1` (`user1_id`),
              KEY `ix_u2` (`user2_id`),
              KEY `ix_att1` (`attempt1_id`),
              KEY `ix_att2` (`attempt2_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $cols = array_column(DB::all("SHOW COLUMNS FROM `{$p}dyads`"), 'Field');
            if (!in_array('pay_mode', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `pay_mode` ENUM('free','self_paid','partner_paid') NOT NULL DEFAULT 'free' AFTER `rel_type`");
            }
            if (!in_array('target_mode', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `target_mode` ENUM('free','full') NOT NULL DEFAULT 'free' AFTER `pay_mode`");
            }
            if (!in_array('partner_paid', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `partner_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `target_mode`");
            }
            if (!in_array('order1_id', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `order1_id` BIGINT UNSIGNED NULL AFTER `attempt1_id`");
            }
            if (!in_array('order2_id', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `order2_id` BIGINT UNSIGNED NULL AFTER `attempt2_id`");
            }
            if (!in_array('partner_name', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `partner_name` VARCHAR(150) NULL AFTER `partner_paid`");
            }
            if (!in_array('partner_mobile', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `partner_mobile` VARCHAR(20) NULL AFTER `partner_name`");
            }
            if (!in_array('synergy_score', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `synergy_score` INT UNSIGNED NULL AFTER `status`");
            }
            if (!in_array('summary_json', $cols, true)) {
                DB::run("ALTER TABLE `{$p}dyads` ADD COLUMN `summary_json` MEDIUMTEXT NULL AFTER `synergy_score`");
            }
        } catch (Throwable $e) {
            error_log('ensureDyadSchema error: ' . $e->getMessage());
        }
    }

    /**
     * ایجاد پیوند دونفره جدید با پشتیبانی از مدل‌های سه‌گانه مالی و تجاری
     * pay_mode: 'free' | 'self_paid' | 'partner_paid'
     */
    public static function createDyad(
        int $userId,
        int $attemptId,
        string $relType = 'couple',
        string $partnerName = '',
        string $partnerMobile = '',
        string $payMode = 'free',
        ?int $orderId = null
    ): array {
        self::ensureDyadSchema();
        $at = DB::one('SELECT a.*, t.title AS test_title, t.price_full, t.price_sale FROM ' . DB::t('attempts') . ' a
                       JOIN ' . DB::t('tests') . ' t ON t.id=a.test_id
                       WHERE a.id=? AND a.user_id=? AND a.status="completed"', [$attemptId, $userId]);
        if (!$at) {
            return ['ok' => false, 'message' => 'جلسهٔ تست تکمیل‌شده یافت نشد.'];
        }

        $rel = array_key_exists($relType, self::$REL_TYPES) ? $relType : 'couple';
        $partnerName = trim($partnerName);
        $partnerMobile = clean_mobile($partnerMobile);

        if ($partnerName === '') {
            return ['ok' => false, 'message' => 'لطفاً نام و نام خانوادگی همراه را وارد نمایید.'];
        }

        if ($partnerMobile !== '' && !valid_mobile($partnerMobile)) {
            return ['ok' => false, 'message' => 'شماره موبایل همراه واردشده معتبر نیست (باید ۱۱ رقمی و با ۰۹ شروع شود).'];
        }

        if (!in_array($payMode, ['free', 'self_paid', 'partner_paid'], true)) {
            $payMode = 'free';
        }

        $targetMode = in_array($payMode, ['self_paid', 'partner_paid'], true) ? 'full' : 'free';
        $partnerPaid = ($payMode === 'free') ? 1 : (($payMode === 'self_paid' && $orderId) ? 0 : 0);

        // اگر قیمت تست صفر باشد در حالت self_paid مستقیماً پرداخت‌شده تلقی می‌شود
        if ($payMode === 'self_paid') {
            $deal = fr_price_deal((int)$at['price_full'], isset($at['price_sale']) ? (int)$at['price_sale'] : null);
            if ($deal['final'] === 0) {
                $partnerPaid = 1;
            }
        }

        $token = fr_token(16);

        $dyadId = (int)DB::insert('dyads', [
            'token'          => $token,
            'test_id'        => $at['test_id'],
            'user1_id'       => $userId,
            'attempt1_id'    => $attemptId,
            'order1_id'      => $orderId,
            'rel_type'       => $rel,
            'pay_mode'       => $payMode,
            'target_mode'    => $targetMode,
            'partner_paid'   => $partnerPaid,
            'partner_name'   => $partnerName !== '' ? $partnerName : null,
            'partner_mobile' => $partnerMobile !== '' ? $partnerMobile : null,
            'status'         => 'pending',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $inviteUrl = fr_panel_url('dyad.php?token=' . $token);

        // پیامک فقط زمانی که آزمون پرداخت‌شده (هدیه قطعی یا رایگان) یا پرداخت بر عهده همراه است ارسال می‌شود
        if ($partnerMobile !== '' && ($partnerPaid === 1 || $payMode === 'partner_paid')) {
            try {
                $user1 = DB::one('SELECT first_name, last_name FROM ' . DB::t('users') . ' WHERE id=?', [$userId]);
                $u1Name = trim(($user1['first_name'] ?? '') . ' ' . ($user1['last_name'] ?? '')) ?: 'همراه شما';
                $pName = $partnerName !== '' ? $partnerName : 'همراه گرامی';

                require_once FR_ROOT . '/core/Kavenegar.php';
                $sms = new Kavenegar();
                if ($payMode === 'self_paid') {
                    $smsText = "{$pName} عزیز، {$u1Name} هزینهٔ نسخهٔ تخصصی آزمون سنجش تطابق ({$at['test_title']}) را برای شما هدیه و پرداخت کرده است.\nمشاهده و شروع آزمون هدیه:\n{$inviteUrl}";
                } elseif ($payMode === 'partner_paid') {
                    $smsText = "{$pName} عزیز، {$u1Name} شما را به آزمون سنجش تطابق دونفره ({$at['test_title']} - نسخهٔ تخصصی) در فریا روان دعوت کرده است.\nمشاهده و شروع:\n{$inviteUrl}";
                } else {
                    $smsText = "{$pName} عزیز، {$u1Name} شما را به آزمون سنجش تطابق دونفره ({$at['test_title']}) در فریا روان دعوت کرده است.\nمشاهده و شروع:\n{$inviteUrl}";
                }
                $sms->sendDirect($partnerMobile, $smsText, 'dyad_invite');
            } catch (Throwable $e) {
                error_log('feria dyad sms error: ' . $e->getMessage());
            }
        }

        return [
            'ok'             => true,
            'dyad_id'        => $dyadId,
            'token'          => $token,
            'invite_url'     => $inviteUrl,
            'partner_name'   => $partnerName,
            'partner_mobile' => $partnerMobile,
            'pay_mode'       => $payMode,
            'target_mode'    => $targetMode,
            'partner_paid'   => $partnerPaid,
        ];
    }

    /**
     * اتصال طرف دوم به پیوند با توکن دعوت و بررسی مدل پرداخت
     */
    public static function joinDyad(string $token, int $user2Id, ?int $attempt2Id = null): array
    {
        self::ensureDyadSchema();
        $token = trim($token);
        $dyad = DB::one('SELECT * FROM ' . DB::t('dyads') . ' WHERE token=?', [$token]);
        if (!$dyad) {
            return ['ok' => false, 'message' => 'پیوند دعوت نامعتبر یا منقضی شده است.'];
        }

        // اگر خود سازنده پیوند روی لینک کلیک کرد:
        if ((int)$dyad['user1_id'] === $user2Id) {
            if ($dyad['status'] === 'ready') {
                return [
                    'ok'         => true,
                    'status'     => 'ready',
                    'dyad_id'    => (int)$dyad['id'],
                    'report_url' => fr_panel_url('dyad-report.php?id=' . $dyad['id']),
                ];
            }
            return [
                'ok'         => true,
                'status'     => 'waiting_partner',
                'is_owner'   => true,
                'dyad_id'    => (int)$dyad['id'],
                'message'    => 'این پیوند دعوت اختصاصی شماست و در انتظار تکمیل تست توسط همراه شما است.',
                'invite_url' => fr_panel_url('dyad.php?token=' . $token),
            ];
        }

        // ذخیره توکن در نشست برای ردیابی در پایان آزمون
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['active_dyad_token'] = $token;
            $_SESSION['dyad_id'] = (int)$dyad['id'];
        }

        if (empty($dyad['user2_id'])) {
            DB::update('dyads', ['user2_id' => $user2Id], 'id=?', [$dyad['id']]);
            $dyad['user2_id'] = $user2Id;
        }

        // وضعیت آماده بودن پیوند از قبل
        if ($dyad['status'] === 'ready' && !empty($dyad['attempt2_id'])) {
            return [
                'ok'         => true,
                'status'     => 'ready',
                'dyad_id'    => (int)$dyad['id'],
                'report_url' => fr_panel_url('dyad-report.php?id=' . $dyad['id']),
            ];
        }

        $targetMode = $dyad['target_mode'] ?? 'free';
        $payMode    = $dyad['pay_mode'] ?? 'free';
        $isGiftPaid = ($payMode === 'self_paid' && (int)$dyad['partner_paid'] === 1);

        // ۱. اگر attempt2Id مشخص نشده، آزمون‌های تکمیل‌شده قبلی کاربر بررسی شود
        if (!$attempt2Id) {
            if ($targetMode === 'full') {
                // الف) تلاش تخصصی تکمیل‌شده موجود
                $fullAt = DB::one('SELECT id FROM ' . DB::t('attempts') .
                    ' WHERE user_id=? AND test_id=? AND mode="full" AND status="completed" ORDER BY id DESC LIMIT 1',
                    [$user2Id, $dyad['test_id']]);
                if ($fullAt) {
                    $attempt2Id = (int)$fullAt['id'];
                } elseif ($isGiftPaid) {
                    // ب) هدیه پرداخت شده است؛ اگر تلاش رایگان دارد به full ارتقا دهیم
                    $freeAt = DB::one('SELECT id FROM ' . DB::t('attempts') .
                        ' WHERE user_id=? AND test_id=? AND status="completed" ORDER BY id DESC LIMIT 1',
                        [$user2Id, $dyad['test_id']]);
                    if ($freeAt) {
                        $attempt2Id = (int)$freeAt['id'];
                        DB::update('attempts', ['mode' => 'full', 'order_id' => $dyad['order1_id']], 'id=?', [$attempt2Id]);
                        require_once FR_ROOT . '/core/Analyzer.php';
                        Analyzer::scoreAttempt($attempt2Id);
                    }
                }
            } else {
                // حالت رایگان: هر آزمون تکمیل‌شده‌ای قابل قبول است
                $anyAt = DB::one('SELECT id FROM ' . DB::t('attempts') .
                    ' WHERE user_id=? AND test_id=? AND status="completed" ORDER BY id DESC LIMIT 1',
                    [$user2Id, $dyad['test_id']]);
                if ($anyAt) {
                    $attempt2Id = (int)$anyAt['id'];
                }
            }
        }

        // اگر هنوز تستی انجام نداده یا برای partner_paid نیاز به ارتقا/خرید دارد:
        if (!$attempt2Id) {
            $hasFree = DB::one('SELECT id FROM ' . DB::t('attempts') .
                ' WHERE user_id=? AND test_id=? AND status="completed" ORDER BY id DESC LIMIT 1',
                [$user2Id, $dyad['test_id']]);

            $redirectUrl = fr_panel_url('test-start.php?id=' . $dyad['test_id']) . '&dyad_token=' . urlencode($token);
            if ($hasFree && $targetMode === 'full' && $payMode === 'partner_paid') {
                $redirectUrl .= '&upgrade_attempt=' . (int)$hasFree['id'];
            }

            return [
                'ok'          => true,
                'status'      => 'needs_test',
                'target_mode' => $targetMode,
                'pay_mode'    => $payMode,
                'test_id'     => (int)$dyad['test_id'],
                'dyad_id'     => (int)$dyad['id'],
                'message'     => ($targetMode === 'full' && $payMode === 'partner_paid')
                    ? 'شما به نسخهٔ تخصصی سنجش تطابق دعوت شدید. لطفاً نسخهٔ تخصصی را تهیه فرمایید.'
                    : 'شما به سنجش تطابق دونفره متصل شدید. لطفاً ابتدا آزمون را تکمیل کنید.',
                'redirect_url'=> $redirectUrl,
            ];
        }

        // پیوند آزمون با موفقیت
        DB::update('dyads', [
            'user2_id'    => $user2Id,
            'attempt2_id' => $attempt2Id,
            'status'      => 'ready',
            'updated_at'  => date('Y-m-d H:i:s'),
        ], 'id=?', [$dyad['id']]);

        // تحلیل و محاسبهٔ فوری شاخص‌های تطابق
        self::analyze((int)$dyad['id']);

        // اطلاع‌رسانی به سازنده دعوت (کاربر ۱)
        try {
            $u1 = DB::one('SELECT mobile, first_name FROM ' . DB::t('users') . ' WHERE id=?', [(int)$dyad['user1_id']]);
            if ($u1 && !empty($u1['mobile'])) {
                $user2 = DB::one('SELECT first_name, last_name FROM ' . DB::t('users') . ' WHERE id=?', [$user2Id]);
                $pName = trim(($user2['first_name'] ?? '') . ' ' . ($user2['last_name'] ?? '')) ?: ($dyad['partner_name'] ?: 'همراه شما');
                $dyadRepUrl = fr_panel_url('dyad-report.php?id=' . $dyad['id']);
                require_once FR_ROOT . '/core/Kavenegar.php';
                $sms = new Kavenegar();
                $sms->sendDirect((string)$u1['mobile'], "{$u1['first_name']} عزیز، {$pName} در آزمون سنجش تطابق شرکت کرد و کارنامهٔ تطابق دونفرهٔ شما آماده شد 🕊\nمشاهده کارنامه:\n{$dyadRepUrl}", 'dyad_ready');
            }
        } catch (Throwable $e) {}

        return [
            'ok'        => true,
            'status'    => 'ready',
            'dyad_id'   => (int)$dyad['id'],
            'report_url'=> fr_panel_url('dyad-report.php?id=' . $dyad['id']),
        ];
    }

    /**
     * اجرای کامل تحلیل روان‌شناختی تطابق دونفره با گارد امنیتی
     */
    public static function analyze(int $dyadId): ?array
    {
        $d = DB::one('SELECT d.*, t.title AS test_title, t.slug AS test_slug, t.engine AS test_engine
                      FROM ' . DB::t('dyads') . ' d
                      JOIN ' . DB::t('tests') . ' t ON t.id=d.test_id
                      WHERE d.id=?', [$dyadId]);
        if (!$d) return null;

        $u1 = DB::one('SELECT id, first_name, last_name, mobile FROM ' . DB::t('users') . ' WHERE id=?', [$d['user1_id']]) ?: [];
        $u2 = !empty($d['user2_id']) ? (DB::one('SELECT id, first_name, last_name, mobile FROM ' . DB::t('users') . ' WHERE id=?', [$d['user2_id']]) ?: []) : [];

        // استفاده از نام همسر/همراه ذخیره‌شده در صورت نبود کاربر ۲
        if (empty($u2['first_name']) && !empty($d['partner_name'])) {
            $u2['first_name'] = $d['partner_name'];
            $u2['last_name']  = '';
            $u2['mobile']     = $d['partner_mobile'] ?? '';
        }

        $at1 = !empty($d['attempt1_id']) ? (DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE id=?', [$d['attempt1_id']]) ?: []) : [];
        $at2 = !empty($d['attempt2_id']) ? (DB::one('SELECT * FROM ' . DB::t('attempts') . ' WHERE id=?', [$d['attempt2_id']]) ?: []) : [];

        if (empty($at1) || empty($at2)) {
            return null; // هر دو آزمون باید تکمیل شده باشند
        }

        $res1 = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$d['attempt1_id']]);
        $res2 = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$d['attempt2_id']]);

        if (empty($res1)) {
            require_once FR_ROOT . '/core/Analyzer.php';
            Analyzer::scoreAttempt((int)$d['attempt1_id']);
            $res1 = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$d['attempt1_id']]);
        }
        if (empty($res2)) {
            require_once FR_ROOT . '/core/Analyzer.php';
            Analyzer::scoreAttempt((int)$d['attempt2_id']);
            $res2 = DB::all('SELECT * FROM ' . DB::t('results') . ' WHERE attempt_id=?', [$d['attempt2_id']]);
        }

        $map1 = []; foreach ($res1 as $r) { $map1[$r['scale_code']] = $r; }
        $map2 = []; foreach ($res2 as $r) { $map2[$r['scale_code']] = $r; }

        $engine = strtolower((string)($d['test_engine'] ?? 'disc'));
        $analysis = match ($engine) {
            'disc'  => self::analyzeDiscDyad($d, $u1, $u2, $at1, $at2, $map1, $map2),
            default => self::analyzeGenericDyad($d, $u1, $u2, $at1, $at2, $map1, $map2),
        };

        // ذخیره شاخص و خلاصه در دیتابیس
        DB::update('dyads', [
            'synergy_score' => $analysis['synergy_score'],
            'summary_json'  => json_encode($analysis, JSON_UNESCAPED_UNICODE),
            'status'        => 'ready',
            'updated_at'    => date('Y-m-d H:i:s'),
        ], 'id=?', [$dyadId]);

        return $analysis;
    }

    /**
     * تحلیل تخصصی تطابق دونفره برای تست رفتارشناسی DISC
     */
    private static function analyzeDiscDyad(array $d, array $u1, array $u2, array $at1, array $at2, array $m1, array $m2): array
    {
        $dims = ['D' => 'سلطه‌گری و قاطعیت', 'I' => 'تأثیرگذاری و ارتباط', 'S' => 'ثبات و صبوری', 'C' => 'دقت و ساختاریافتگی'];
        $deltaMatrix = [];
        $totalDelta = 0;

        foreach ($dims as $code => $title) {
            $r1 = $m1[$code] ?? ($m1[strtolower($code)] ?? null);
            $r2 = $m2[$code] ?? ($m2[strtolower($code)] ?? null);
            $p1 = (int)round((float)($r1['percent'] ?? 50));
            $p2 = (int)round((float)($r2['percent'] ?? 50));
            $diff = abs($p1 - $p2);
            $totalDelta += $diff;

            $nature = match (true) {
                $diff <= 14 => 'هم‌پوشانی و درک شهودی مشترک (احساس راحتی متقابل)',
                $diff <= 32 => 'مکمل و تعادل‌بخش (پوشش نقاط کور یکدیگر)',
                default     => 'تضاد ادراکی و نیاز به پل ارتباطی آگاهانه',
            };

            $deltaMatrix[$code] = [
                'code'   => $code,
                'title'  => $title,
                'p1'     => $p1,
                'p2'     => $p2,
                'diff'   => $diff,
                'nature' => $nature,
            ];
        }

        $pat1 = self::extractPattern((string)($at1['context'] ?? ''), $m1);
        $pat2 = self::extractPattern((string)($at2['context'] ?? ''), $m2);

        $avgDiff = $totalDelta / 4;
        $baseScore = 95 - ($avgDiff * 0.7);

        $clashFriction = 0;
        $synergyBonus  = 0;
        $frictionPoints = [];
        $synergyStrengths = [];

        $d1 = (int)($deltaMatrix['D']['p1'] ?? 50); $d2 = (int)($deltaMatrix['D']['p2'] ?? 50);
        $i1 = (int)($deltaMatrix['I']['p1'] ?? 50); $i2 = (int)($deltaMatrix['I']['p2'] ?? 50);
        $s1 = (int)($deltaMatrix['S']['p1'] ?? 50); $s2 = (int)($deltaMatrix['S']['p2'] ?? 50);
        $c1 = (int)($deltaMatrix['C']['p1'] ?? 50); $c2 = (int)($deltaMatrix['C']['p2'] ?? 50);

        if ($d1 >= 65 && $d2 >= 65) {
            $clashFriction += 12;
            $frictionPoints[] = [
                'area'  => 'کنترل و تصمیم‌گیری سریع',
                'title' => 'چالش بر سر سکان هدایت (Dual Dominance)',
                'desc'  => 'هر دو طرف تمایل ذاتی به تصمیم‌گیری سریع، قاطع و هدایت اوضاع دارند. در مواقع تنش ممکن است گفت‌وگو به میدان رقابت تبدیل شود.',
            ];
        } elseif (($d1 >= 65 && $s2 >= 65) || ($d2 >= 65 && $s1 >= 65)) {
            $synergyBonus += 10;
            $synergyStrengths[] = [
                'area'  => 'هدایت و آرامش',
                'title' => 'هم‌افزایی قاطعیت و پایداری (D + S)',
                'desc'  => 'یکی پیشران اقدام، حل مسئله و شجاعت تغییر است و دیگری سنگر آرامش، وفاداری و تثبیت دستاوردها؛ ترکیبی بسیار مقاوم در برابر بحران‌ها.',
            ];
            $frictionPoints[] = [
                'area'  => 'ریتم و سرعت اقدام',
                'title' => 'تفاوت گام حرکتی (عجله در برابر تأمل)',
                'desc'  => 'طرف با D بالا ممکن است صبوری طرف S را به «تعلل» تعبیر کند؛ در حالی که طرف S قاطعیت طرف D را گاهی «سخت‌گیرانه» احساس می‌کند.',
            ];
        }

        if (($i1 >= 65 && $c2 >= 65) || ($i2 >= 65 && $c1 >= 65)) {
            $frictionPoints[] = [
                'area'  => 'سبک نگاه به واقعیت',
                'title' => 'شور و کل‌نگری در برابر منطق و جزئیات (I + C)',
                'desc'  => 'طرف I بر مبنای احساس و ارتباط سخن می‌گوید، اما طرف C شواهد منطقی و داده‌های دقیق می‌طلبد.',
            ];
            $synergyStrengths[] = [
                'area'  => 'ایده و اجرا',
                'title' => 'خلاقیت در کنار کیفیت و دقت ساختاری',
                'desc'  => 'طرف برون‌گرا روابط و ایده‌های جدید می‌سازد و طرف دقیق، برنامه‌ریزی بی‌نقص و پایش بودجه و کیفیت را مدیریت می‌کند.',
            ];
        }

        if ($i1 >= 60 && $i2 >= 60) {
            $synergyStrengths[] = [
                'area'  => 'شادی و گرمای رابطه',
                'title' => 'شور، نشاط و لذت مشترک از همراهی',
                'desc'  => 'هر دو طرف روحیه‌ای اجتماعی، پرانرژی و مشتاق دارند. فضای مشترک شما معمولاً سرشار از حس زندگی و مراودات اجتماعی است.',
            ];
        }

        if ($c1 >= 60 && $c2 >= 60) {
            $synergyStrengths[] = [
                'area'  => 'استاندارد و پیش‌بینی‌پذیری',
                'title' => 'پایهٔ عقلانی و مدیریت منظم امور',
                'desc'  => 'تصمیم‌گیری‌های مالی و اداری با حداقل ریسک مدیریت می‌شوند و هیچ‌یک از دو طرف اهل اقدامات نسنجیده نیستند.',
            ];
        }

        if (empty($synergyStrengths)) {
            $synergyStrengths[] = [
                'area'  => 'توازن عمومی',
                'title' => 'سازگاری انعطاف‌پذیر و متعادل',
                'desc'  => 'شدت قطبی در ابعاد شما وجود ندارد؛ این امر انعطاف‌پذیری بالایی برای گفت‌وگو و سازش فراهم می‌کند.',
            ];
        }

        if (empty($frictionPoints)) {
            $frictionPoints[] = [
                'area'  => 'فرسایش تدریجی',
                'title' => 'سکوت دربارهٔ خواسته‌های فردی',
                'desc'  => 'به‌دلیل نبود اصطکاک شدید، ممکن است دلخوری‌های کوچک بازگو نشوند و در طول زمان به فاصله‌گیری خاموش منجر گردند.',
            ];
        }

        $synergyScore = (int)round(max(48, min(95, $baseScore - $clashFriction + $synergyBonus)));

        $ratingFa = match (true) {
            $synergyScore >= 85 => 'هم‌افزایی بسیار عالی و هماهنگی کم‌نظیر',
            $synergyScore >= 72 => 'سازگاری بالا با ظرفیت تعادل‌بخشی قدرتمند',
            $synergyScore >= 60 => 'سازگاری متوسط؛ نیازمند مهارت‌های ارتباطی آگاهانه',
            default             => 'تفاوت‌های بنیادی سبک رفتاری؛ نیازمند توافق‌نامه‌های ساختاریافته',
        };

        $p1Name = trim(($u1['first_name'] ?? '') . ' ' . ($u1['last_name'] ?? '')) ?: 'کاربر ۱';
        $p2Name = trim(($u2['first_name'] ?? '') . ' ' . ($u2['last_name'] ?? '')) ?: ($d['partner_name'] ?: 'کاربر ۲');

        $safeProtocols = [
            'to_p1' => "هنگام گفت‌وگو با {$p2Name}: " . self::discCommAdvice($pat2, $p2Name),
            'to_p2' => "هنگام گفت‌وگو با {$p1Name}: " . self::discCommAdvice($pat1, $p1Name),
            'rules' => [
                'قانون توقف ۵ ثانیه‌ای: پیش از پاسخ‌گویی در لحظهٔ عصبانیت، ۳ تنفس عمیق شکمی بکشید.',
                'بیان بر مبنای حس خود: به‌جای «تو همیشه…» از عبارت «من در این لحظه این احساس را دارم که…» استفاده کنید.',
                'تفکیک نیاز به شنیدن از حل مسئله: ابتدا مشخص کنید «آیا فقط همدلی می‌خواهید یا راهکار عملی؟»',
                'جلسهٔ هفتگی ۲۰ دقیقه‌ای «تخلیهٔ امن»: بدون قضاوت و سرزنش، هر طرف دو نیاز محقق‌نشدهٔ خود را بیان کند.',
            ],
        ];

        return [
            'synergy_score'   => $synergyScore,
            'rating_fa'       => $ratingFa,
            'rel_type_fa'     => self::$REL_TYPES[$d['rel_type']] ?? 'رابطه دونفره',
            'user1'           => ['id' => $u1['id'] ?? 0, 'name' => $p1Name, 'pattern' => $pat1],
            'user2'           => ['id' => $u2['id'] ?? 0, 'name' => $p2Name, 'pattern' => $pat2],
            'delta_matrix'    => $deltaMatrix,
            'synergy_strengths'=> $synergyStrengths,
            'friction_points' => $frictionPoints,
            'safe_protocols'  => $safeProtocols,
            'growth_contract' => [
                'week1' => 'شناسایی و نام‌گذاری کانون تعارض اصلی در یک مکالمهٔ آرام ۱۰ دقیقه‌ای',
                'week2' => 'تمرین تفویض دو تصمیم مهم خانوادگی/کاری به طرف مکمل',
                'week3' => 'تمرین بازخورد مثبت روزانه (قدردانی صریح از یک ویژگی شخصیتی همراه)',
            ],
        ];
    }

    /**
     * تحلیل تطابق عمومی (برای سایر تست‌های چندعاملی مانند MBTI، NEO، گاردنر و ...)
     */
    private static function analyzeGenericDyad(array $d, array $u1, array $u2, array $at1, array $at2, array $m1, array $m2): array
    {
        $allCodes = array_unique(array_merge(array_keys($m1), array_keys($m2)));
        $deltaMatrix = [];
        $totalDelta = 0;
        $cnt = 0;

        foreach ($allCodes as $code) {
            if ($code === 'overall' || in_array($code, ['profile','FK'], true)) continue;
            $p1 = (int)round((float)($m1[$code]['percent'] ?? 50));
            $p2 = (int)round((float)($m2[$code]['percent'] ?? 50));
            $diff = abs($p1 - $p2);
            $totalDelta += $diff;
            $cnt++;

            $title = $m1[$code]['title_fa'] ?? ($m2[$code]['title_fa'] ?? $code);

            $deltaMatrix[$code] = [
                'code'   => $code,
                'title'  => $title,
                'p1'     => $p1,
                'p2'     => $p2,
                'diff'   => $diff,
                'nature' => $diff <= 15 ? 'همسویی بالا' : ($diff <= 30 ? 'تکمیل‌کننده' : 'تفاوت معنادار'),
            ];
        }

        $avgDiff = $cnt > 0 ? $totalDelta / $cnt : 20;
        $score = (int)round(max(50, min(94, 92 - ($avgDiff * 0.8))));

        $p1Name = trim(($u1['first_name'] ?? '') . ' ' . ($u1['last_name'] ?? '')) ?: 'کاربر ۱';
        $p2Name = trim(($u2['first_name'] ?? '') . ' ' . ($u2['last_name'] ?? '')) ?: ($d['partner_name'] ?: 'کاربر ۲');

        return [
            'synergy_score'    => $score,
            'rating_fa'        => $score >= 75 ? 'سازگاری بسیار خوب و همسویی مطلوب' : 'سازگاری نیازمند تنظیم انتظارات متقابل',
            'rel_type_fa'      => self::$REL_TYPES[$d['rel_type']] ?? 'رابطه دونفره',
            'user1'            => ['id' => $u1['id'] ?? 0, 'name' => $p1Name, 'pattern' => ''],
            'user2'            => ['id' => $u2['id'] ?? 0, 'name' => $p2Name, 'pattern' => ''],
            'delta_matrix'     => $deltaMatrix,
            'synergy_strengths' => [
                ['area' => 'همدلی و اشتراک', 'title' => 'همپوشانی در ابعاد کلیدی', 'desc' => 'در بخش بزرگی از مؤلفه‌ها، تفاوت‌های عمیق بازدارنده مشاهده نمی‌شود.'],
            ],
            'friction_points'  => [
                ['area' => 'تفاوت نگاه', 'title' => 'نقاط نیازمند هماهنگی', 'desc' => 'تفاوت در ابعاد با اختلاف نمره بالاتر نیازمند شفاف‌سازی آگاهانه انتظارات است.'],
            ],
            'safe_protocols'   => [
                'to_p1' => "شنیدن صبورانه و پرهیز از نتیجه‌گیری شتاب‌زده دربارهٔ انگیزه‌های {$p2Name}.",
                'to_p2' => "ابراز شفاف نیازها بدون سرزنش یا برچسب‌زنی به {$p1Name}.",
                'rules' => ['گوش دادن فعال بدون قطع کلام طرف مقابل', 'قرار گذاشتن برای صحبت در زمان آرامش و بدون خستگی'],
            ],
            'growth_contract'  => [
                'week1' => 'گفت‌وگوی شفاف دربارهٔ نقاط اشتراک و تفاوت‌های این کارنامه',
                'week2' => 'تدوین یک قانون طلایی دونفره برای پیشگیری از اصطکاک',
            ],
        ];
    }

    /** استخراج کد الگوی DISC */
    private static function extractPattern(string $context, array $map): string
    {
        if (preg_match('/pattern:([A-Z]+)/', $context, $m)) {
            return $m[1];
        }
        $scores = [
            'D' => (float)($map['D']['percent'] ?? 0),
            'I' => (float)($map['I']['percent'] ?? 0),
            'S' => (float)($map['S']['percent'] ?? 0),
            'C' => (float)($map['C']['percent'] ?? 0),
        ];
        arsort($scores);
        return implode('', array_slice(array_keys($scores), 0, 2));
    }

    /** توصیه‌های اختصاصی تعامل بر اساس تیپ DISC */
    private static function discCommAdvice(string $pat, string $name): string
    {
        $lead = substr($pat, 0, 1);
        return match ($lead) {
            'D' => "مستقیم به اصل مطلب بروید، از حاشیه‌روی بپرهیزید، روی نتایج تمرکز کنید و گزینه‌های شفاف در اختیار {$name} بگذارید.",
            'I' => "با لحن گرم و صمیمانه آغاز کنید، به احساساتش توجه نشان دهید و اجازه دهید ایده‌هایش را با اشتیاق بگوید.",
            'S' => "آرام و بدون شتاب صحبت کنید، امنیت و احترام متقابل را تضمین نمایید و از تحمیل تغییرات ناگهانی بپرهیزید.",
            'C' => "با استدلال منطقی و شواهد صحبت کنید، از اغراق هیجانی دوری نمایید و به استانداردهای مدنظر {$name} احترام بگذارید.",
            default => "با لحنی محترمانه و شنوا، زمینه را برای درک دوجانبه فراهم آورید.",
        };
    }
}
