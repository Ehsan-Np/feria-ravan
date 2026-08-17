<?php
/**
 * ============================================================================
 *  رابط عمومی دیدگاه‌ها و پرسش‌ها (نسخهٔ ۲٫۲) — سکشن آمادهٔ چاپ در صفحات تکی
 *  ─ فرم برای همهٔ کاربران واردشده باز است: نظر ⭐ یا پرسش ❓ (حتی غیرشرکت‌کننده‌ها)
 *  ─ شرکت‌کننده‌ها برچسب «شرکت‌کننده» و بقیه «کاربر مهمان» می‌گیرند
 *  ─ پرسش‌ها بدون ستاره و با چیپ «پرسش» منتشر می‌شوند؛ پاسخ مدیریت زیرشان می‌آید
 *  استفاده:  fr_reviews_section('test', $id) | fr_reviews_section($kind, $id)
 * ============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/reviews.php';

/**
 * سکشن کامل «دیدگاه‌ها و پرسش‌ها» برای یک تست روانشناسی/کارگاه/دوره
 * @param string $ctx   test | workshop | course
 * @param int    $refId شناسهٔ تست روانشناسی یا رویداد
 */
function fr_reviews_section(string $ctx, int $refId): void
{
    static $seq = 0; $seq++;                        // چند سکشن در یک صفحه = شناسهٔ یکتا
    $domId = 'rvBox' . $seq;

    $stats = fr_review_stats($ctx, $refId);
    $rows  = fr_reviews_public($ctx, $refId, 24);
    $me    = function_exists('current_user') ? current_user() : null;
    $isParticipant = $me ? fr_review_participated($ctx, $refId, (int)$me['id']) : false;
    $mine  = $me ? DB::one('SELECT rating, body, status, kind FROM ' . DB::t('reviews') .
        ' WHERE context=? AND ref_id=? AND user_id=?', [$ctx, $refId, (int)$me['id']]) : null;
    $mineKind = $mine && ($mine['kind'] ?? 'review') === 'question' ? 'question' : 'review';
    $ctxFa = FR_REVIEW_CTXS[$ctx] ?? 'مورد';
    ?>
<section class="fr-card rv-box" id="<?= $domId ?>" style="margin-top:18px">
  <div class="rv-head">
    <h3 style="margin:0">💬 دیدگاه‌ها و پرسش‌ها</h3>
    <?php if ($stats['count'] > 0): ?>
    <div class="rv-avg" title="میانگین امتیاز <?= fa_num($stats['count']) ?> دیدگاه ثبت‌شده">
      <?= fr_stars_html($stats['avg'], '17px') ?>
      <b><?= fa_num($stats['avg']) ?></b>
      <small>از <?= fa_num($stats['count']) ?> دیدگاه</small>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <p class="rv-empty">هنوز دیدگاه یا پرسشی ثبت نشده است — اولین نفری باشید که می‌نویسد 🌟</p>
  <?php else: ?>
  <div class="rv-list">
    <?php foreach ($rows as $r): $isQ = ($r['kind'] ?? 'review') === 'question'; ?>
    <article class="rv-item">
      <div class="rv-top">
        <span class="rv-ava" aria-hidden="true"><?= e(mb_substr($r['author_name'], 0, 1)) ?></span>
        <div class="rv-who">
          <b><?= e($r['author_name']) ?></b>
          <span class="rv-label" style="background:<?= e($r['label']['bg']) ?>;color:<?= e($r['label']['color']) ?>"><?= e($r['label']['title']) ?></span>
          <?php if ($isQ): ?><span class="rv-qchip">❓ پرسش</span><?php endif; ?>
        </div>
        <?php if (!$isQ && $r['rating'] !== null): ?>
        <span class="rv-rating"><?= fr_stars_html((float)$r['rating'], '13px') ?></span>
        <?php endif; ?>
      </div>
      <p class="rv-body"><?= nl2br(e($r['body'])) ?></p>
      <?php if (trim((string)($r['admin_reply'] ?? '')) !== ''): ?>
      <div class="rv-reply">
        <b>🛡 پاسخ مدیریت<?= !empty($r['replied_at']) ? ' — ' . fa_num(jdate('j F Y', strtotime($r['replied_at']))) : '' ?>:</b>
        <?= nl2br(e((string)$r['admin_reply'])) ?>
      </div>
      <?php endif; ?>
      <time class="rv-date"><?= fa_num(jdate('j F Y', strtotime($r['created_at']))) ?></time>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($me): ?>
  <!-- فرم ثبت/ویرایش دیدگاه یا پرسش — برای همهٔ کاربران واردشده (۲٫۲) -->
  <div class="rv-form" id="<?= $domId ?>Form">
    <b><?= $mine ? '✏️ نوشتهٔ شما (قابل ویرایش و ثبت دوباره)' : '🌟 نظر یا پرسش‌شما را بنویسید' ?></b>
    <?php if ($mine && $mine['status'] === 'pending'): ?>
      <div class="fr-alert warn" style="font-size:12.5px;margin:8px 0">نوشتهٔ فعلی شما در صف تأیید مدیر است.</div>
    <?php endif; ?>

    <div class="rv-type" role="tablist" aria-label="نوع نوشته">
      <button type="button" class="rv-type-btn<?= $mineKind === 'review' ? ' on' : '' ?>" data-k="review">⭐ نظر و امتیاز</button>
      <button type="button" class="rv-type-btn<?= $mineKind === 'question' ? ' on' : '' ?>" data-k="question">❓ پرسش</button>
    </div>

    <div class="rv-rate" id="<?= $domId ?>Rate" role="radiogroup" aria-label="امتیاز" style="<?= $mineKind === 'question' ? 'display:none' : '' ?>">
      <?php for ($i = 1; $i <= 5; $i++): ?>
      <button type="button" class="rv-star<?= $mine && (int)($mine['rating'] ?? 0) >= $i ? ' on' : '' ?>" data-v="<?= $i ?>" aria-label="<?= fa_num($i) ?> ستاره">★</button>
      <?php endfor; ?>
      <small class="rv-rate-txt"><?= $mine && $mine['rating'] ? 'امتیاز فعلی شما: ' . fa_num((int)$mine['rating']) . ' ستاره' : 'روی ستاره‌ها امتیاز بدهید' ?></small>
    </div>

    <textarea class="fr-textarea" id="<?= $domId ?>Body" rows="3" maxlength="2000"
      placeholder="نظرتان دربارهٔ این <?= e($ctxFa) ?> یا پرسش‌تان را بنویسید…"><?= e($mine['body'] ?? '') ?></textarea>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
      <button class="fr-btn" type="button" id="<?= $domId ?>Send">انتشار ✅</button>
      <small class="hint">با نام «<?= e(fr_review_form_name($me)) ?>» و برچسب «<?= $isParticipant ? 'شرکت‌کننده' : 'کاربر مهمان' ?>» منتشر می‌شود.</small>
    </div>
    <div id="<?= $domId ?>Msg" style="font-size:13px;margin-top:8px"></div>
  </div>
  <?php else: ?>
  <p class="rv-note">برای نوشتن نظر یا پرسش، ابتدا <a href="<?= SITE_URL ?>/auth.php?back=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>">وارد حساب شوید</a> — ثبت‌نام هم با یک پیامک چند ثانیه طول می‌کشد؛ بودن در <?= e($ctxFa) ?> لازم نیست، همه می‌توانند بنویسند 💜</p>
  <?php endif; ?>
</section>

<?php if ($me): ?>
<script>
(function(){
  var box = document.getElementById('<?= $domId ?>Form'); if (!box) return;
  var kind = '<?= $mineKind ?>';
  var cur = <?= $mine ? (int)($mine['rating'] ?? 0) : 0 ?>;
  var stars = box.querySelectorAll('.rv-star');
  var rateRow = document.getElementById('<?= $domId ?>Rate');
  function paint(){
    stars.forEach(function(s){ s.classList.toggle('on', parseInt(s.getAttribute('data-v'),10) <= cur); });
    var t = box.querySelector('.rv-rate-txt');
    if (t) t.textContent = cur ? ('امتیاز شما: ' + cur.toLocaleString('fa-IR') + ' ستاره') : 'روی ستاره‌ها امتیاز بدهید';
  }
  stars.forEach(function(s){ s.addEventListener('click', function(){ cur = parseInt(s.getAttribute('data-v'),10); paint(); }); });
  paint();
  /* سوییچ نوع نوشته: نظر (با ستاره) / پرسش (بدون ستاره) */
  box.querySelectorAll('.rv-type-btn').forEach(function(b){
    b.addEventListener('click', function(){
      kind = b.getAttribute('data-k');
      box.querySelectorAll('.rv-type-btn').forEach(function(x){ x.classList.toggle('on', x === b); });
      if (rateRow) rateRow.style.display = (kind === 'question') ? 'none' : '';
    });
  });
  document.getElementById('<?= $domId ?>Send').addEventListener('click', function(){
    var btn = this, msg = document.getElementById('<?= $domId ?>Msg');
    if (kind !== 'question' && !cur) { msg.innerHTML = '<span style="color:#b0262c">⭐ برای «نظر» ابتدا امتیاز انتخاب کنید؛ یا نوع را روی «پرسش» بگذارید.</span>'; return; }
    btn.disabled = true; msg.textContent = '⏳ درحال ارسال…';
    window.api('<?= SITE_URL ?>/api/reviews.php', {
      action:'submit', context:'<?= $ctx ?>', ref_id:<?= (int)$refId ?>, kind: kind,
      rating: cur, body: document.getElementById('<?= $domId ?>Body').value.trim()
    }).then(function(r){
      btn.disabled = false;
      if (!r || !r.ok) { msg.innerHTML = '<span style="color:#b0262c">' + ((r && r.message) || 'خطا در ارسال؛ دوباره تلاش کنید.') + '</span>'; return; }
      msg.innerHTML = '<span style="color:#0c7a4d">' + (r.message || 'ثبت شد ✅') + '</span>';
      if (r.status === 'approved') setTimeout(function(){ location.reload(); }, 900);
    }).catch(function(){ btn.disabled = false; msg.innerHTML = '<span style="color:#b0262c">خطای ارتباط؛ دوباره تلاش کنید.</span>'; });
  });
})();
</script>
<?php endif; ?>
    <?php
}
