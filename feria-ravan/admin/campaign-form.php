<?php
/**
 * فرم مشترک ساخت/ویرایش کمپین — نسخهٔ ۲٫۷ (توسط admin/campaigns.php include می‌شود)
 * متغیرهای لازم: $cRows (مقادیر فعلی)، $formAct، $formTitle، $formBtn، $segmentChoices
 */
declare(strict_types=1);
$selCh = array_map('strval', (array)($cRows['channels'] ?? ['telegram','bale']));
?>
<div class="fr-card">
  <h3><?= $formTitle ?> <a class="fr-btn ghost sm" style="float:left" href="?v=list">↩ فهرست</a></h3>
  <form method="post" id="cmpForm">
    <?= csrf_field() ?><input type="hidden" name="act" value="<?= e($formAct) ?>">
    <?php if ($formAct === 'edit_save'): ?><input type="hidden" name="id" value="<?= (int)($cRows['id'] ?? 0) ?>"><?php endif; ?>
    <input type="hidden" name="body_html" id="bodyHtml">

    <div class="fr-grid cols-2">
      <div class="fr-field"><label>عنوان داخلی کمپین *</label><input class="fr-input" name="title" maxlength="190" required value="<?= e((string)($cRows['title'] ?? '')) ?>" placeholder="مثلاً تخفیف ویژهٔ مهر — کارگاه مدیران"></div>
      <div class="fr-field"><label>کانال‌های ارسال * <small style="color:var(--muted)">(هرکدام را تیک بزن؛ ترکیب آزاد)</small></label>
        <div style="display:flex;gap:8px;flex-wrap:wrap" id="chTogs">
          <label class="ch-tog"><input type="checkbox" name="channels[]" value="sms" <?= in_array('sms', $selCh, true) ? 'checked' : '' ?>> 📩 پیامک</label>
          <label class="ch-tog"><input type="checkbox" name="channels[]" value="telegram" <?= in_array('telegram', $selCh, true) ? 'checked' : '' ?>> 🟦 تلگرام</label>
          <label class="ch-tog"><input type="checkbox" name="channels[]" value="bale" <?= in_array('bale', $selCh, true) ? 'checked' : '' ?>> 🟩 بله</label>
        </div>
        <small class="hint">برای هر گیرنده به‌ازای هر کانالِ تیک‌خورده یک پیام ساخته می‌شود؛ اگر گیرنده در پیام‌رسانی لینک نباشد آن کانال برایش رد می‌شود.</small>
      </div>
      <div class="fr-field"><label>مخاطبین</label>
        <select class="fr-select" name="audience" id="audSel">
          <option value="contacts" <?= ($cRows['audience'] ?? 'contacts') === 'contacts' ? 'selected' : '' ?>>📇 مخاطبین (دفترچه/اکسل/دستی/ثبت‌نام سایت)</option>
          <option value="users" <?= ($cRows['audience'] ?? '') === 'users' ? 'selected' : '' ?>>👥 کاربران سایت</option>
          <option value="both" <?= ($cRows['audience'] ?? '') === 'both' ? 'selected' : '' ?>>📇+👥 هردو</option>
        </select></div>
      <div class="fr-field"><label>گروه/برچسب مخاطبین <small>(خالی = همه؛ چند مورد با «،» — نام گروه یا برچسب قدیمی)</small></label>
        <input class="fr-input" name="segment" list="segList" maxlength="120" value="<?= e((string)($cRows['segment'] ?? '')) ?>" placeholder="مثلاً مدیران تهران، ویژه">
        <datalist id="segList"><?php foreach ($segmentChoices as $sg): ?><option value="<?= e($sg) ?>"><?php endforeach; ?></datalist>
        <small class="hint"><a href="<?= SITE_URL ?>/admin/contacts.php?v=groups">مدیریت گروه‌ها ↗</a></small></div>
    </div>

    <!-- کنترل ارسال -->
    <div class="fr-grid cols-4" style="background:#f8fafd;border:1.5px dashed var(--line);border-radius:14px;padding:12px 12px 0">
      <div class="fr-field"><label>حجم هر دستهٔ ارسال</label>
        <input class="fr-input" type="number" name="batch_size" min="1" max="500" dir="ltr" value="<?= (int)($cRows['batch_size'] ?? 15) ?>">
        <small class="hint">کرون در هر اجرا تا این تعداد می‌فرستد.</small></div>
      <div class="fr-field"><label>وقفهٔ بین پیام‌ها (میلی‌ثانیه)</label>
        <input class="fr-input" type="number" name="delay_ms" min="0" max="5000" dir="ltr" value="<?= (int)($cRows['delay_ms'] ?? 150) ?>">
        <small class="hint">احتیاط برای محدودیت نرخ ربات/اپراتور.</small></div>
      <div class="fr-field"><label>زمان‌بندی شروع (اختیاری)</label>
        <input class="fr-input" type="datetime-local" name="scheduled_at" dir="ltr"
               value="<?= e(!empty($cRows['scheduled_at']) ? str_replace(' ', 'T', substr((string)$cRows['scheduled_at'], 0, 16)) : '') ?>">
        <small class="hint">خالی = فوری.</small></div>
      <div class="fr-field"><label>گزینه‌های تکمیلی</label>
        <label class="tog" style="display:flex;margin-bottom:6px"><input type="checkbox" name="track_links" value="1" <?= (int)($cRows['track_links'] ?? 1) ? 'checked' : '' ?>> 🔗 رهگیری کلیک لینک‌ها</label>
        <label class="tog" style="display:flex"><input type="checkbox" name="sms_invite" value="1" <?= (int)($cRows['sms_invite'] ?? 1) ? 'checked' : '' ?>> 📲 پیامک دعوت برای پیش‌استارت‌ها</label>
      </div>
    </div>

    <div class="fr-grid cols-2" style="align-items:start;margin-top:14px">
      <div>
        <label style="font-weight:700;font-size:13px">متن پیام (ویرایشگر کامل — با تلگرام/بله سازگار است):</label>
        <div class="rte-bar" id="rteBar">
          <button type="button" data-cmd="bold" title="پررنگ"><b>B</b></button>
          <button type="button" data-cmd="italic" title="کج"><i>I</i></button>
          <button type="button" data-cmd="underline" title="زیرخط"><u>U</u></button>
          <button type="button" data-cmd="strikeThrough" title="خط‌خورده"><s>S</s></button>
          <span class="split"></span>
          <button type="button" data-cmd="insertUnorderedList" title="فهرست">• ⋮</button>
          <button type="button" data-cmd="link" title="پیوند">🔗</button>
          <button type="button" data-cmd="code" title="کد/مونو">&lt;/&gt;</button>
          <button type="button" data-cmd="removeFormat" title="پاک‌سازی قالب">⌫</button>
          <span class="split"></span>
          <?php foreach (['🎉','✅','🔥','💎','🎁','📣','🦢','⭐'] as $em): ?><button type="button" class="emo"><?= $em ?></button><?php endforeach; ?>
          <span class="split"></span>
          <button type="button" class="ptn">{name}</button>
          <button type="button" class="ptn">{mobile}</button>
          <button type="button" class="ptn">{site}</button>
          <button type="button" class="ptn">{date}</button>
        </div>
        <div id="rteBox" contenteditable="true" data-ph="سلام {name} عزیز! 🎉&#10;تخفیف ویژهٔ این هفته…"></div>
        <small class="hint"><span id="rteCnt">۰</span>/۴۰۹۶ نویسه — قالب‌ها: پررنگ، کج، زیرخط، خط‌خورده، پیوند، کد، فهرست؛ الگوها برای هر نفر با اطلاعات خودش پر می‌شوند.</small>
      </div>
      <div>
        <label style="font-weight:700;font-size:13px">پیش‌نمایش زندهٔ پیام‌رسان (تلگرام/بله):</label>
        <div class="rte-prev" id="rtePrev"></div>
        <label style="font-weight:700;font-size:13px;display:block;margin-top:12px">پیش‌نمایش زندهٔ <span style="color:#8a5a00">پیامک</span> (اگر کانال پیامک تیک باشد):</label>
        <div class="sms-prev" id="smsPrev"></div>
        <small class="hint" id="smsCnt">۰ کاراکتر — ≈ ۱ جزء پیامک فارسی</small>
        <div class="fr-alert" style="margin-top:10px;font-size:12.5px">
          🧲 <b>مخاطب پیش از استارت ربات:</b> محدودیت فنی تلگرام/بله اجازهٔ پیام مستقیم پیش از استارت نمی‌دهد؛
          با تیک «پیامک دعوت»، متن + لینک start خودکار به‌صورت پیامک می‌رود و به‌محض اتصال، کمپین‌های بعدی مستقیم در پیام‌رسان می‌رسد.
        </div>
      </div>
    </div>
    <button class="fr-btn" style="margin-top:12px"><?= $formBtn ?></button>
  </form>
</div>
<script>
(function(){
  var box=document.getElementById('rteBox'),prev=document.getElementById('rtePrev'),smsPrev=document.getElementById('smsPrev'),
      cnt=document.getElementById('rteCnt'),smsCnt=document.getElementById('smsCnt'),hid=document.getElementById('bodyHtml');
  var FA='۰۱۲۳۴۵۶۷۸۹';
  function fa(n){return String(n).replace(/[0-9]/g,function(d){return FA[+d]})}
  function clean(html){   /* HTML امن سازگار با تلگرام (سمت سرور هم پاکسازی می‌شود) */
    var s=html.replace(/<(b|strong)>/gi,'<b>').replace(/<\/(b|strong)>/gi,'</b>')
              .replace(/<(i|em)>/gi,'<i>').replace(/<\/(i|em)>/gi,'</i>')
              .replace(/<(strike|del)>/gi,'<s>').replace(/<\/(strike|del)>/gi,'</s>');
    s=s.replace(/<(ul|ol)>/gi,'').replace(/<\/(ul|ol)>/gi,'\n').replace(/<li>/gi,'• ').replace(/<\/li>/gi,'\n')
       .replace(/<div><br\s*\/?>(\s*)<\/div>/gi,'\n').replace(/<br\s*\/?>/gi,'\n')
       .replace(/<\/(div|p)>/gi,'\n').replace(/<(div|p)[^>]*>/gi,'').replace(/&nbsp;/g,' ');
    s=s.replace(/<(?!\/?(b|i|u|s|a|code|pre)\b)[^>]+>/gi,'');
    return s.replace(/\n{3,}/g,'\n\n').trim();
  }
  function smsText(s){  /* نسخهٔ متنیِ پیامک — لینک‌ها به‌صورت «متن (نشانی)» */
    return s.replace(/<a\s+href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/gi,'$2 ($1)')
            .replace(/<[^>]+>/g,'').replace(/\n{3,}/g,'\n\n');
  }
  function fillDemo(s){
    return s.replace(/\{name\}/g,'علی رضایی').replace(/\{mobile\}/g,'09121112233')
            .replace(/\{site\}/g,'<?= e(DB::setting('site_title','فریا روان')) ?>').replace(/\{date\}/g,'امروز');
  }
  function sync(){
    var s=clean(box.innerHTML);
    hid.value=s;
    prev.innerHTML=(s?fillDemo(s):'<span style="color:#93a9c9">متن پیام این‌جا پیش‌نمایش می‌شود…</span>').replace(/\n/g,'<br>')
      +'<span class="cap">💬 پیش‌نمایش پیام‌رسان — {name} با «علی رضایی» و {mobile} با ۰۹۱۲۱۱۱۲۲۳۳ جایگذاری شد</span>';
    var st=fillDemo(smsText(s));
    smsPrev.textContent=st||'متن پیامک این‌جا پیش‌نمایش می‌شود…';
    var L=st.length;
    smsCnt.textContent=fa(L)+' کاراکتر — ≈ '+fa(Math.max(1,Math.ceil(L/70)))+' جزء پیامک فارسی';
    cnt.textContent=fa(box.textContent.length);
  }
  document.getElementById('rteBar').addEventListener('click',function(e){
    var b=e.target.closest('button'); if(!b)return; e.preventDefault(); box.focus();
    if(b.classList.contains('emo')||b.classList.contains('ptn')){document.execCommand('insertText',false,b.textContent);sync();return;}
    var cmd=b.dataset.cmd; if(!cmd)return;
    if(cmd==='link'){var u=prompt('نشانی پیوند (https://…) — با فعال‌بودن «رهگیری کلیک» خودکار به لینک آماری تبدیل می‌شود');if(u)document.execCommand('createLink',false,u);sync();return;}
    if(cmd==='code'){document.execCommand('insertHTML',false,'<code>'+ (window.getSelection().toString()||'کد') +'</code>');sync();return;}
    document.execCommand(cmd,false,null); sync();
  });
  box.addEventListener('input',sync);
  box.addEventListener('paste',function(e){e.preventDefault();var t=(e.clipboardData||window.clipboardData).getData('text/plain');document.execCommand('insertText',false,t);sync();});
  document.getElementById('cmpForm').addEventListener('submit',function(e){
    if(box.textContent.trim()===''){e.preventDefault();alert('متن پیام را بنویسید.');return;}
    var any=false;document.querySelectorAll('#chTogs input[type=checkbox]').forEach(function(c){if(c.checked)any=true;});
    if(!any){e.preventDefault();alert('حداقل یک کانال ارسال (پیامک/تلگرام/بله) را تیک بزنید.');}
  });
  /* حالت ویرایش: مقدار فعلی */
  var cur = <?= json_encode((string)($cRows['body'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
  if(cur){ box.innerHTML = cur; }
  sync();
})();
</script>
