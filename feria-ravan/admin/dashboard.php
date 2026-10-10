<?php
/** داشبورد مدیریت — آمار و نمودارها */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
$adm = require_admin();

$stat = [
  'users'     => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE status='active'"),
  'attempts'  => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE status!='in_progress'"),
  'fullname'  => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE mode='full' AND status!='in_progress'"),
  /* نسخهٔ ۱٫۹ — درآمد دقیق و شفاف:
     خالص = سفارش‌های paid (بدون برگشت‌شده‌ها)، ناخالص = paid+refunded، برگشتی = refunded */
  'revenue'   => (int)DB::val("SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " WHERE status='paid'"),
  'gross'     => (int)DB::val("SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " WHERE status='paid'")
              +  (int)DB::val("SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " WHERE status='refunded'"),
  'refunds'   => (int)DB::val("SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " WHERE status='refunded'"),
  /* امروز (روز حسابداری): واردهٔ امروز = همهٔ سفارش‌های پولی امروز (حتی آن‌که بعداً برگشت خورد)،
     برگشتی امروز = عودت‌هایی که امروز نهایی شد؛ خالص امروز = واردهٔ امروز − برگشتی امروز */
  'todayLoans'=> (int)DB::val("SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " WHERE status IN('paid','refunded') AND DATE(paid_at)=CURDATE()"),
  'todayPay'  => (int)DB::val("SELECT COALESCE(SUM(payable),0) FROM " . DB::t('orders') . " WHERE status IN('paid','refunded') AND DATE(paid_at)=CURDATE()")
              -  (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM " . DB::t('refund_requests') . " WHERE status='paid' AND DATE(updated_at)=CURDATE()"),
  'todayUsers'=> (int)DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE DATE(created_at)=CURDATE()"),
  'pendingTest'=> (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE status='in_progress'"),
];

/* آمار ۷ روز اخیر برای نمودار (شمسی نمایش داده می‌شود) */
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $days[] = [
      'label' => str_replace(['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'],
                             ['ف','ا','خ','ت','م','ش','م','آ','آ','دی','ب','ا'], fa_num(jdate('j F', strtotime($d)))),
      'value' => (int)DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE DATE(started_at)=?", [$d]),
    ];
}

/* آخرین سفارش‌ها و تست‌های روانشناسی — LEFT JOIN تا رویداد/مشاوره هم نمایش داده شوند (رفع نقص ۱٫۴) */
$lastOrders = DB::all("SELECT o.*, u.first_name, u.last_name,
    CASE o.kind WHEN 'event' THEN CONCAT('🛠 ', COALESCE(ev.title,'رویداد'))
                WHEN 'consult' THEN '🛋 رزرو مشاوره'
                ELSE CONCAT('🧠 ', COALESCE(t.title,'تست روانشناسی')) END AS ttitle
  FROM " . DB::t('orders') . " o
  JOIN " . DB::t('users') . " u ON u.id=o.user_id
  LEFT JOIN " . DB::t('tests') . " t ON t.id=o.test_id
  LEFT JOIN " . DB::t('events') . " ev ON ev.id=o.event_id
  ORDER BY o.id DESC LIMIT 8");
$lastAttempts = DB::all("SELECT a.*, u.first_name, u.last_name, t.title AS ttitle FROM " . DB::t('attempts') . " a
  JOIN " . DB::t('users') . " u ON u.id=a.user_id JOIN " . DB::t('tests') . " t ON t.id=a.test_id
  WHERE a.status!='in_progress' ORDER BY a.id DESC LIMIT 8");

admin_layout($adm, 'dashboard', 'داشبورد');
echo flash();      // مثلاً بنر «🚀 به‌روزرسانی خودکار اعمال شد» همین‌جا دیده می‌شود
?>
<div class="fr-grid cols-4" style="margin-bottom:6px">
  <?php stat_card('👥','کاربران فعال', fa_num($stat['users'])); ?>
  <?php stat_card('🧠','تست روانشناسی تکمیل‌شده', fa_num($stat['attempts']), '#ecfbf4'); ?>
  <?php stat_card('💰','خالص درآمد', toman($stat['revenue']), '#fff6e6'); ?>
  <?php stat_card('📈','فروش امروز (خالص)', toman(max(0,$stat['todayPay'])), '#eaf4ff'); ?>
</div>
<div class="fr-grid cols-2" style="margin-bottom:4px">
  <div class="fr-card" style="margin:0;padding:10px 16px;font-size:12px;color:var(--muted)">
    🧮 گزارش امروز: ورودی <b><?= toman($stat['todayLoans']) ?></b> − برگشتی‌ها <b><?= toman(max(0,$stat['todayLoans']-$stat['todayPay'])) ?></b> = خالص <b><?= toman(max(0,$stat['todayPay'])) ?></b>
  </div>
  <div class="fr-card" style="margin:0;padding:10px 16px;font-size:12px;color:var(--muted)">
    📖 قاعدهٔ محاسبهٔ نسخهٔ ۱٫۹: خالص درآمد = سفارش‌های پرداخت‌شدهٔ بدون برگشت؛ بازیابی وجه دیگر در درآمد خالص حساب نمی‌شود.
  </div>
</div>
<div class="fr-grid cols-4" style="margin-bottom:6px">
  <?php stat_card('🧾','درآمد ناخالص', toman($stat['gross']), '#f6f1ff'); ?>
  <?php stat_card('↩️','مبالغ برگشتی', toman($stat['refunds']), '#fdeded'); ?>
  <?php stat_card('⭐','نسخه ویژه فروخته‌شده', fa_num($stat['fullname'])); ?>
  <?php stat_card('🆕','عضویت امروز', fa_num($stat['todayUsers']), '#ecfbf4'); ?>
  <?php stat_card('⏳','تست روانشناسی ناتمام', fa_num($stat['pendingTest']), '#fdecec'); ?>
</div>

<div class="fr-card">
  <h3>تست‌های روانشناسی ۷ روز اخیر</h3>
  <canvas class="fr-chart" data-chart='<?= e(json_encode($days, JSON_UNESCAPED_UNICODE)) ?>' data-opts='{"height":210}' style="height:210px"></canvas>
</div>

<div class="fr-grid cols-2">
  <div class="fr-card" style="margin:0">
    <h3>آخرین سفارش‌ها <a class="more" href="orders.php">همه ←</a></h3>
    <table class="fr-table">
      <tr><th>کاربر</th><th>تست روانشناسی</th><th>مبلغ</th><th>وضعیت</th></tr>
      <?php foreach ($lastOrders as $o): ?>
      <tr><td><?= e(trim($o['first_name'].' '.$o['last_name']) ?: '—') ?></td>
          <td style="font-size:12px"><?= e($o['ttitle']) ?></td>
          <td><?= fa_num(number_format($o['payable'])) ?></td>
          <td><span class="fr-chip <?= $o['status']==='paid'?'ok':($o['status']==='pending'?'warn':'err') ?>">
            <?= ['paid'=>'موفق','pending'=>'در انتظار','failed'=>'ناموفق','canceled'=>'لغو','refunded'=>'برگشت'][$o['status']] ?></span></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="fr-card" style="margin:0">
    <h3>آخرین تست‌های روانشناسی تکمیل‌شده <a class="more" href="attempts.php">همه ←</a></h3>
    <table class="fr-table">
      <tr><th>کاربر</th><th>تست روانشناسی</th><th>نوع</th><th>تاریخ</th><th></th></tr>
      <?php foreach ($lastAttempts as $a): ?>
      <tr><td><?= e(trim($a['first_name'].' '.$a['last_name']) ?: '—') ?></td>
          <td style="font-size:12px"><?= e($a['ttitle']) ?></td>
          <td><span class="fr-chip <?= $a['mode']==='full'?'purple':'info' ?>"><?= $a['mode']==='full'?'ویژه':'رایگان' ?></span></td>
          <td><?= fa_num(jdate('j F', strtotime($a['completed_at'] ?? $a['started_at']))) ?></td>
          <td><a class="fr-btn sm ghost" style="padding:3px 8px;font-size:11px" href="attempts.php?report=<?= $a['id'] ?>" title="مشاهده تحلیل روان‌سنجی">تحلیل 👁</a></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php admin_layout_end(); ?>
