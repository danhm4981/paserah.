<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}
date_default_timezone_set('Asia/Riyadh');
require_once __DIR__ . '/db.php';

// المستخدم الحالي من الجلسة
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { header('Location: login.php'); exit; }

$today = date('Y-m-d');

// جرعات المستخدم الحالي الفعّالة اليوم
$st = $pdo->prepare("SELECT s.schedule_id, s.dose_amount, s.dose_unit, s.dosage_time,
        m.medication_id, m.medication_name, m.medication_image, m.medication_description
    FROM dosage_schedules s
    JOIN medications m ON m.medication_id = s.medication_id
    WHERE m.user_id = ?
      AND s.start_date <= ?
      AND (s.end_date IS NULL OR s.end_date >= ?)
    ORDER BY s.dosage_time ASC");
$st->execute([$userId, $today, $today]);
$rows = $st->fetchAll();

// تجميع الجرعات حسب الدواء
$palette = ['#fbe1d8','#fdf3c8','#fbdcdc','#dcefe9','#e4e0fb'];
$medsMap = [];
$schedMap = [];
foreach ($rows as $r) {
    $mid = (int)$r['medication_id'];
    $t = substr($r['dosage_time'], 0, 5);
    if (!isset($medsMap[$mid])) {
        $amount = rtrim(rtrim(number_format((float)$r['dose_amount'], 2, '.', ''), '0'), '.');
        $medsMap[$mid] = [
            'id'    => $mid,
            'name'  => $r['medication_name'],
            'dose'  => $amount . ' ' . $r['dose_unit'],
            'desc'  => $r['medication_description'] ?: '',
            'icon'  => '💊',
            'img'   => $r['medication_image'] ? 'uploads/' . rawurlencode(basename($r['medication_image'])) : '',
            'color' => $palette[count($medsMap) % count($palette)],
            'times' => []
        ];
    }
    $medsMap[$mid]['times'][] = $t;
    $schedMap[$mid . '|' . $t] = (int)$r['schedule_id'];
}
$medsList = array_values($medsMap);

// ردود اليوم المسجلة سابقًا (taken / missed)
$st = $pdo->prepare("SELECT n.dose_response, s.dosage_time, m.medication_id
    FROM notifications n
    JOIN dosage_schedules s ON s.schedule_id = n.schedule_id
    JOIN medications m ON m.medication_id = s.medication_id
    WHERE n.user_id = ? AND DATE(n.notification_time) = ? AND n.dose_response IS NOT NULL");
$st->execute([$userId, $today]);
$initStatus = [];
foreach ($st->fetchAll() as $r) {
    $initStatus[$r['medication_id'] . '|' . substr($r['dosage_time'], 0, 5)] = $r['dose_response'];
}
$J = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

include __DIR__ . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>بصيرة | التنبيهات</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">

<style>
    .dashboard-layout {
            display: flex;
            flex-direction: row;
            justify-content: flex-start;
            align-items: flex-start;
            min-height: calc(100vh - 140px);
            width: 100%;
            max-width: 100%;
            margin: 0;
        }

:root{--bg:#f2f7f6;--card:#fff;--ink:#1f2d2c;--mute:#6b7b79;--line:#e3ecea;--pri:#2f817a;--pri-d:#255f5a;--ok:#2e7d32;--okbg:#e4f3e5;--warn:#9a6700;--warnbg:#fdecc8;--bad:#d93025;--badbg:#fde4e2;--nr:#6d3fc0;--nrbg:#ece4fb;box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px);color-scheme:light}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#101a19;--card:#182524;--ink:#e8f1f0;--mute:#9fb3b0;--line:#27403d;--pri:#4fb3aa;--pri-d:#3a8f87;--okbg:#1d3a20;--ok:#7fd183;--warnbg:#3d3213;--warn:#f0c15a;--badbg:#42201d;--bad:#ff8a80;--nr:#c3a3ff;--nrbg:#2d2447;color-scheme:dark}}
:root[data-theme="dark"]{--bg:#101a19;--card:#182524;--ink:#e8f1f0;--mute:#9fb3b0;--line:#27403d;--pri:#4fb3aa;--pri-d:#3a8f87;--okbg:#1d3a20;--ok:#7fd183;--warnbg:#3d3213;--warn:#f0c15a;--badbg:#42201d;--bad:#ff8a80;--nr:#c3a3ff;--nrbg:#2d2447;color-scheme:dark}
*{box-sizing:border-box}html{scroll-padding-top:env(safe-area-inset-top,0px)}
body{margin:0;background:var(--bg);color:var(--ink);font-family:'Tajawal',Tahoma,Arial,sans-serif;line-height:1.6}
.brand{font-weight:700;font-size:22px;color:var(--pri)}
nav ul{display:flex;gap:22px;list-style:none;margin:0;padding:0;font-size:14px;color:var(--mute)}
main{max-width:980px;margin:0 auto;padding:24px 5vw 60px;display:grid;gap:18px}
.card{background:var(--card);border-radius:20px;padding:22px;box-shadow:0 2px 10px rgba(0,0,0,.05)}
h1,h2{margin:0 0 6px;color:var(--pri)}h1{font-size:26px}h2{font-size:20px}
.sub{color:var(--mute);font-size:14px;margin:0 0 14px}
.btns{display:flex;gap:10px;flex-wrap:wrap}
button,select{font-family:inherit;font-size:15px;border-radius:12px;padding:10px 20px;cursor:pointer;border:1px solid var(--line);background:var(--card);color:var(--ink)}
select{padding:8px 12px;max-width:100%}
button.pri{background:var(--pri);color:#fff;border-color:var(--pri)}
button.on{background:var(--pri-d);color:#fff}
.note{font-size:12px;color:var(--mute);margin-top:10px}
.vsel{display:none;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;font-size:14px;color:var(--mute)}
.row{display:flex;align-items:center;gap:14px;padding:14px 0;border-top:1px solid var(--line)}
.row:first-of-type{border-top:0}
.pill{width:52px;height:52px;border-radius:14px;display:grid;place-items:center;font-size:26px;flex:none}
.info{flex:1;min-width:0}.info b{display:block}.info small{color:var(--mute)}
.doses{display:flex;gap:14px;flex-wrap:wrap}
.dose{display:flex;flex-direction:column;align-items:center;font-size:12px;color:var(--mute);gap:4px;min-width:56px}
.dot{width:40px;height:40px;border-radius:50%;border:2.5px solid var(--line);display:grid;place-items:center;font-size:18px}
.dot.taken{border-color:var(--ok);background:var(--okbg);color:var(--ok)}
.dot.pending{border-color:#e0a030;background:var(--warnbg)}
.dot.noresp{border-color:var(--nr);background:var(--nrbg);color:var(--nr)}
.dot.missed{border-color:var(--bad);background:var(--badbg);color:var(--bad)}
.empty{color:var(--mute);text-align:center;padding:16px}
.ov{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;place-items:center;z-index:20;padding:16px}
.ov.show{display:grid}
.modal{background:var(--card);border-radius:24px;padding:22px;width:min(360px,100%);text-align:center}
.when{display:inline-block;background:var(--warnbg);color:var(--warn);border-radius:99px;padding:4px 16px;font-size:14px;margin-bottom:14px}
.big{width:150px;height:150px;border-radius:22px;margin:0 auto 12px;display:grid;place-items:center;font-size:70px}
.modal h3{margin:4px 0;font-size:24px}
.desc{background:var(--bg);border-radius:10px;padding:8px;margin:12px 0;font-size:14px;color:var(--mute)}
.acts{display:flex;gap:10px;margin-top:14px}.acts button{flex:1;color:#fff;border:0;font-weight:700}
.g{background:#43a047}.r{background:var(--bad)}
.pulse{animation:p 1s infinite}@keyframes p{50%{transform:scale(1.04)}}
@media(max-width:640px){nav ul{display:none}}
</style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
<main>
  <section class="card">
    <h1>🔔 التنبيهات</h1>
    <p class="sub">تنبيهات صوتية ومرئية في موعد كل جرعة</p>
    <h2>تفعيل التنبيهات الصوتية</h2>
    <div class="btns">
      <button id="voiceBtn" class="pri">تفعيل الصوت</button>
    </div>
    <div class="vsel" id="vsel"><label for="vSel">الصوت:</label><select id="vSel"></select></div>
    <div class="note" id="voiceNote">المتصفح يمنع تشغيل الصوت تلقائيًا، اضغط «تفعيل الصوت» مرة واحدة بعد فتح الصفحة.</div>
  </section>

  <section class="card">
    <h2>سجل التنبيهات</h2>
    <div id="today"></div>
  </section>
</main>

<div class="ov" id="ov">
  <div class="modal">
    <div class="when" id="mWhen"></div>
    <div class="big pulse" id="mImg"></div>
    <h3 id="mName"></h3>
    <div style="color:var(--mute)" id="mDose"></div>
    <div class="desc" id="mDesc"></div>
    <button id="repeat">🔊 إعادة النطق</button>
    <div class="acts">
      <button class="r" id="noBtn">✕ لم أتناول الدواء</button>
      <button class="g" id="yesBtn">✓ تناولت الدواء</button>
    </div>
  </div>
</div>

<script>
// ===== البيانات من قاعدة البيانات (المستخدم الحالي فقط) =====
const meds=<?= json_encode($medsList, $J) ?>;
const schedMap=<?= json_encode($schedMap, $J) ?>; // "medId|HH:MM" => schedule_id
const status=<?= json_encode($initStatus ?: new stdClass(), $J) ?>;
const HIDE_AFTER_SEC=10; // بعد انتهاء النطق تختفي النافذة تلقائيًا بعد 10 ثوانٍ
let voiceOn=false, current=null, noRespTimer=null, chosenURI='';
const $=id=>document.getElementById(id);

const fmt=t=>{let[h,m]=t.split(':').map(Number);const p=h>=12?'م':'ص';h=h%12||12;return `${h}:${String(m).padStart(2,'0')} ${p}`};
const nowHM=()=>{const d=new Date();return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')};
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
// صورة الدواء إن وُجدت وإلا الأيقونة
const iconHTML=m=>m.img?`<img src="${esc(m.img)}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit" onerror="this.replaceWith(document.createTextNode('${m.icon}'))">`:m.icon;

// ===== الصوت (يُستخدم داخل التنبيه فقط) =====
let ac;
function beep(){try{ac=ac||new (window.AudioContext||window.webkitAudioContext)();if(ac.state==='suspended')ac.resume();[0,.35].forEach(d=>{const o=ac.createOscillator(),g=ac.createGain();o.frequency.value=880;o.connect(g);g.connect(ac.destination);g.gain.setValueAtTime(.25,ac.currentTime+d);g.gain.exponentialRampToValueAtTime(.001,ac.currentTime+d+.3);o.start(ac.currentTime+d);o.stop(ac.currentTime+d+.3)})}catch(e){}}
const hasTTS='speechSynthesis' in window;
function arVoices(){return hasTTS?speechSynthesis.getVoices().filter(v=>v.lang.toLowerCase().replace('_','-').startsWith('ar')):[]}
const PREF=['zariyah','hoda','salma','laila','hamed','shakir','google','naayf'];
function pickVoice(){
  const vs=arVoices();if(!vs.length)return null;
  const c=vs.find(v=>v.voiceURI===chosenURI);if(c)return c;
  for(const p of PREF){const f=vs.find(v=>v.name.toLowerCase().includes(p));if(f)return f;}
  return vs[0];
}
function fillVoices(){
  const vs=arVoices(),sel=$('vSel'),cur=pickVoice();
  $('vsel').style.display=vs.length>1?'flex':'none';
  sel.innerHTML=vs.map(v=>`<option value="${v.voiceURI}"${cur&&cur.voiceURI===v.voiceURI?' selected':''}>${v.name}</option>`).join('');
}
$('vSel').onchange=e=>{chosenURI=e.target.value;showVoiceNote();};
function showVoiceNote(){
  if(!hasTTS){$('voiceNote').textContent='⚠️ هذا المتصفح لا يدعم النطق الصوتي، ستسمع النغمة فقط.';return;}
  const v=pickVoice();
  $('voiceNote').textContent=v?('✓ الصوت العربي جاهز: '+v.name):'⚠️ لا يوجد صوت عربي مثبّت على جهازك، لذلك تسمع النغمة فقط. افتح الصفحة في متصفح Edge أو ثبّت صوتًا عربيًا من إعدادات النظام.';
}
let spId=0;
// cb تُنفَّذ بعد انتهاء النطق (أو فورًا إذا الصوت غير مفعّل)
function speak(text,cb){
  const id=++spId;let fired=false;
  const go=()=>{if(!fired&&id===spId){fired=true;cb&&cb();}};
  if(!voiceOn){go();return;}
  beep();
  if(!hasTTS){showVoiceNote();setTimeout(go,900);return;}
  fillVoices();showVoiceNote();
  const v=pickVoice();
  speechSynthesis.cancel();
  setTimeout(()=>{
    const u=new SpeechSynthesisUtterance(text);u.lang='ar-SA';u.rate=.9;u.volume=1;
    if(v)u.voice=v;
    u.onend=go;u.onerror=go;
    speechSynthesis.resume();speechSynthesis.speak(u);
    setTimeout(go,25000);
  },350);
}
const msg=(m,t)=>`حان موعد الجرعة. ${m.name}، الجرعة ${m.dose.replace('mg','ملغ')}، ${m.desc}. الساعة ${fmt(t)}. إذا تناولت الدواء فاضغط على الزر الأخضر، وإذا لم تتناوله فاضغط على الزر الأحمر.`;

$('voiceBtn').onclick=()=>{
  voiceOn=!voiceOn;
  $('voiceBtn').textContent=voiceOn?'✓ الصوت مفعّل':'تفعيل الصوت';
  $('voiceBtn').className=voiceOn?'on':'pri';
  if(voiceOn){fillVoices();showVoiceNote();}
};

// ===== النافذة المنبثقة =====
function trigger(m,t){
  current={m,t};
  $('mWhen').textContent='موعد الجرعة: '+fmt(t);
  $('mImg').innerHTML=iconHTML(m);$('mImg').style.background=m.color;$('mImg').style.overflow='hidden';
  $('mName').textContent=m.name;$('mDose').textContent='الجرعة: '+m.dose;$('mDesc').textContent=m.desc;
  $('ov').classList.add('show');
  status[m.id+'|'+t]='pending';
  render();
  clearTimeout(noRespTimer);
  const cur=current;
  speak(msg(m,t),()=>startTimer(cur)); // العدّ التنازلي يبدأ بعد انتهاء النطق
}
function startTimer(cur){
  if(current!==cur)return;
  clearTimeout(noRespTimer);
  noRespTimer=setTimeout(()=>finish('noresp'),HIDE_AFTER_SEC*1000);
}
$('repeat').onclick=()=>{if(!current)return;const cur=current;clearTimeout(noRespTimer);speak(msg(cur.m,cur.t),()=>startTimer(cur));};
$('yesBtn').onclick=()=>finish('taken');
$('noBtn').onclick=()=>finish('missed');

// حفظ الرد في قاعدة البيانات (taken / missed فقط)
function saveResponse(m,t,state){
  const sid=schedMap[m.id+'|'+t];
  if(!sid)return;
  fetch('save_dose.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({schedule_id:sid,response:state})}).catch(()=>{});
}

function finish(state){
  if(!current)return;
  clearTimeout(noRespTimer);spId++;hasTTS&&speechSynthesis.cancel();
  const{m,t}=current;
  status[m.id+'|'+t]=state; // noresp لا يُحفظ في القاعدة، ويمنع تكرار النافذة أثناء نفس الجلسة
  if(state!=='noresp')saveResponse(m,t,state);
  $('ov').classList.remove('show');current=null;render();
}

// ===== العرض =====
function dotsHTML(m){return m.times.map(t=>{const s=status[m.id+'|'+t]||'';
  return `<div class="dose"><div class="dot ${s}">${s==='taken'?'✓':s==='pending'?'⏰':s==='missed'?'✕':s==='noresp'?'❔':''}</div>${fmt(t)}</div>`}).join('')}

function render(){
  $('today').innerHTML=meds.length?meds.map(m=>`<div class="row">
    <div class="pill" style="background:${m.color};overflow:hidden">${iconHTML(m)}</div>
    <div class="info"><b>${esc(m.name)} (${esc(m.dose)})</b><small>${esc(m.desc)}</small></div>
    <div class="doses">${dotsHTML(m)}</div>
  </div>`).join(''):'<div class="empty">لا توجد جرعات مجدولة اليوم</div>';
}

// ===== فحص المواعيد كل 15 ثانية =====
function checkDue(){
  if(current)return;
  const n=nowHM();
  for(const m of meds)for(const t of m.times)
    if(t===n&&!status[m.id+'|'+t]){trigger(m,t);return;}
}
setInterval(checkDue,15000);

if(hasTTS)speechSynthesis.onvoiceschanged=()=>{if(voiceOn){fillVoices();showVoiceNote();}};
render();
checkDue();
</script>
</div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
