<?php
$__base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$__assistantUrl = $__base . '/assistant.php';
$__ttsUrl = $__base . '/tts.php';
?>
<style>/* ===== زر المساعد: دائري 104×104 شبه شفاف (مناسب لكبار السن) ===== */
#astBtn{
  position:fixed;
  bottom:20px;
  left:20px;
  z-index:9998;
  width:104px;
  height:104px;
  min-width:0;
  padding:0;
  margin:0;
  box-sizing:border-box;
  border-radius:50%;
  border:3px solid #008080;
  background:rgba(0,128,128,.18);
  -webkit-backdrop-filter:blur(4px);
  backdrop-filter:blur(4px);
  color:#006666;
  cursor:pointer;
  box-shadow:0 6px 22px rgba(0,0,0,.25);
  display:flex;
  align-items:center;
  justify-content:center;
  -webkit-tap-highlight-color:transparent;
  transition:background .2s, border-color .2s;
}
#astBtn:active{background:rgba(0,128,128,.32)}
#astBtn:focus-visible{outline:4px solid #ffbf00;outline-offset:3px}

/* الأيقونة 50px */
#astBtnIc{
  font-size:50px;
  line-height:1;
  display:block;
}

/* النص مخفي بصريًا ومقروء لقارئات الشاشة (الـ JavaScript يحدّثه) */
#astBtnTx{
  position:absolute;
  width:1px;
  height:1px;
  margin:-1px;
  padding:0;
  overflow:hidden;
  clip:rect(0,0,0,0);
  white-space:nowrap;
  border:0;
}

/* حالة الاستماع */
#astBtn.listening{
  background:rgba(217,48,37,.2);
  border-color:#d93025;
  animation:astP 1s infinite;
}
@keyframes astP{50%{transform:scale(1.08)}}
#astPanel{position:fixed;
bottom:140px;   /* الزر ينتهي عند 124px، فيبقى فراغ 16px */
left:20px;
z-index:9998;
width:min(94vw,440px); 
max-height:calc(100vh - 140px);
overflow-y:auto;
background:#fff;
border-radius:22px;
padding:20px;
box-shadow:0 10px 30px rgba(0,0,0,.28);
display:none;
direction:rtl;
text-align:right;
font-family:'Tajawal',Tahoma,Arial,sans-serif}
#astPanel.show{display:block}
#astHead{display:flex;justify-content:space-between;align-items:center;font-weight:800;font-size:22px;color:#006666;margin-bottom:10px}
#astClose{border:0;background:#f1f1f1;border-radius:50%;width:44px;height:44px;font-size:22px;cursor:pointer;color:#444}
#astState{font-size:18px;font-weight:700;color:#444;min-height:26px;margin-bottom:10px}
#astYou{font-size:20px;color:#1f2d2c;background:#f4f7f7;border-radius:14px;padding:12px 14px;margin-bottom:10px;min-height:24px}
#astAns{font-size:26px;line-height:1.9;font-weight:600;color:#111;background:#e6f6f6;border:2px solid #b9e0e0;border-radius:16px;padding:14px;min-height:40px}
#astAns b{color:#006666;background:#fff6c9;border-radius:6px;padding:0 4px}
#astAns small{display:block;font-size:13px;color:#9a6700;margin-top:6px;font-weight:400}
#astRepeat{display:none;width:100%;margin-top:10px;padding:14px;border:2px solid #008080;border-radius:14px;background:#fff;color:#008080;font-family:inherit;font-size:22px;font-weight:800;cursor:pointer}
#astConfirm{display:none;gap:12px;margin-top:12px}
#astConfirm button{flex:1;height:76px;border:0;border-radius:16px;color:#fff;font-family:inherit;font-size:28px;font-weight:800;cursor:pointer}
#astYes{background:#2e9e4f}
#astNo{background:#d93025}
#astTools{display:flex;gap:8px;align-items:center;margin-top:12px}
#astSlow{flex:1;padding:12px;border:2px solid #ccc;border-radius:12px;background:#fff;color:#333;font-family:inherit;font-size:18px;font-weight:700;cursor:pointer}
#astSlow.on{border-color:#008080;background:#e6f6f6;color:#006666}
#astForm{display:flex;gap:8px;margin-top:12px}
#astText{flex:1;padding:12px;border:1px solid #ccc;border-radius:12px;font-family:inherit;font-size:18px}
#astSend{border:0;border-radius:12px;padding:12px 18px;background:#008080;color:#fff;font-family:inherit;font-size:18px;font-weight:700;cursor:pointer}
</style>

<button id="astBtn" type="button" aria-label="المساعد الصوتي"><span id="astBtnIc">🎤</span><span id="astBtnTx">اضغط وتكلم</span></button>

<div id="astPanel">
  <div id="astHead"><span>🤖 مساعد بصيرة</span><button id="astClose" type="button" aria-label="إغلاق">✕</button></div>
  <div id="astState">اضغط الزر الأخضر وتكلم</div>
  <div id="astYou"></div>
  <div id="astAns"></div>
  <button id="astRepeat" type="button">🔊 أعد الكلام</button>
  <div id="astConfirm">
    <button id="astNo" type="button">✕ لا</button>
    <button id="astYes" type="button">✓ نعم</button>
  </div>
  <div id="astTools"><button id="astSlow" type="button" aria-pressed="false">🐢 أبطئ الصوت</button></div>
  <div id="astForm">
    <input id="astText" type="text" placeholder="أو اكتب سؤالك هنا">
    <button id="astSend" type="button">إرسال</button>
  </div>
</div>

<script>
(function () {
    var URL_ = <?= json_encode($__assistantUrl) ?>;
    var TTS_ = <?= json_encode($__ttsUrl) ?>;
    var SILENCE_MS = 2800;   // مهلة الصمت قبل اعتبار المريض أنهى كلامه
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var $ = function (id) { return document.getElementById(id); };
    var btn = $('astBtn'), btnIc = $('astBtnIc'), btnTx = $('astBtnTx'), panel = $('astPanel');
    var stateEl = $('astState'), youEl = $('astYou'), ansEl = $('astAns');
    var repeatBtn = $('astRepeat'), confirmBox = $('astConfirm'), slowBtn = $('astSlow'), input = $('astText');
    var rec = null, listening = false, handled = false, heard = '', silenceT = null;
    var history = [], busy = false, curAudio = null, sid = 0, lastAnswer = '', lastSay = null;

    // ===== سرعة النطق =====
    // الافتراضي دائمًا سرعة طبيعية. التبطيء اختيار من المستخدم لهذه الجلسة فقط.
    var RATE_NORMAL = 1.0;
    var RATE_SLOW   = 0.75;
    var slow = false;
    try { localStorage.removeItem('astSlow'); } catch (e) {}   // تنظيف الإعداد القديم الذي كان يُبطّئ تلقائيًا
    function curRate() { return slow ? RATE_SLOW : RATE_NORMAL; }

    function setState(t) { stateEl.textContent = t; }
    function setBtn(on) {
        btn.classList.toggle('listening', on);
        btnIc.textContent = on ? '👂' : '🎤';
        btnTx.textContent = on ? 'أسمعك... اضغط للإنهاء' : 'اضغط وتكلم';
    }

    function stopSpeak() {
        sid++;
        if (curAudio) { try { curAudio.pause(); } catch (e) {} curAudio = null; }
        if ('speechSynthesis' in window) speechSynthesis.cancel();
    }

    function browserSpeak(text, done, id) {
        if (!('speechSynthesis' in window)) { done && done(); return; }
        try {
            var voices = speechSynthesis.getVoices().filter(function (v) {
                return v.lang.toLowerCase().replace('_', '-').indexOf('ar') === 0;
            });
            var u = new SpeechSynthesisUtterance(text);
            u.lang = 'ar-SA';
            u.rate = curRate();
            if (voices.length) u.voice = voices[0];
            u.onend = function () { if (id === sid) done && done(); };
            u.onerror = u.onend;
            speechSynthesis.cancel();
            setTimeout(function () { speechSynthesis.speak(u); }, 200);
        } catch (e) { done && done(); }
    }

    var AUDIO_WAIT_MS = 9000;   // إن تأخر الصوت الطبيعي أكثر من هذا ننطق بصوت المتصفح

    function fetchClip(txt) {
        return fetch(TTS_, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: txt, src: 'assistant' })
        }).then(function (r) { if (!r.ok) throw new Error('tts ' + r.status); return r.blob(); });
    }

    // يشغّل المقاطع الصوتية بالترتيب. فشل أول مقطع = ننطق الرد كله بصوت المتصفح
    function playClips(blobs, i, text, done, id) {
        if (id !== sid) return;
        if (i >= blobs.length) { curAudio = null; done && done(); return; }
        var a = new Audio(URL.createObjectURL(blobs[i]));
        a.preservesPitch = true;          // يبقي نبرة الصوت طبيعية عند التبطيء
        a.playbackRate = curRate();       // التبطيء على الملف المخزَّن بدل توليد نسخة جديدة
        curAudio = a;
        function bad() {
            if (id !== sid) return;
            if (i === 0) browserSpeak(text, done, id);
            else playClips(blobs, i + 1, text, done, id);
        }
        a.onended = function () { playClips(blobs, i + 1, text, done, id); };
        a.onerror = bad;
        var p = a.play();
        if (p && p.catch) p.catch(bad);
    }

    // chunks = جمل قصيرة من السيرفر (يُخزَّن صوتها). بدونها أو عند أي فشل: صوت المتصفح
    function speak(text, done, chunks) {
        stopSpeak();
        var id = sid;
        if (!chunks || !chunks.length) { setState('🔊 أتكلم...'); browserSpeak(text, done, id); return; }
        setState('⏳ أجهّز الصوت...');
        var finished = false;
        function fallback() {
            if (finished || id !== sid) return;
            finished = true;
            setState('🔊 أتكلم...');
            browserSpeak(text, done, id);
        }
        var timer = setTimeout(fallback, AUDIO_WAIT_MS);
        Promise.all(chunks.map(fetchClip)).then(function (blobs) {
            if (finished || id !== sid) return;
            finished = true;
            clearTimeout(timer);
            setState('🔊 أتكلم...');
            playClips(blobs, 0, text, done, id);
        }).catch(function () { clearTimeout(timer); fallback(); });
    }

    // يعرض الرد مع إبراز أسماء الأدوية والأوقات
    function esc(s) { return s.replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function showAnswer(text, names) {
        var h = esc(text);
        (names || []).forEach(function (n) {
            if (!n) return;
            h = h.split(esc(n)).join('<b>' + esc(n) + '</b>');
        });
        h = h.replace(/(\d{1,2}(?::\d{2})?\s(?:صباحًا|ظهرًا|عصرًا|مساءً|ليلًا))/g, '<b>$1</b>');
        ansEl.innerHTML = h;
    }

    function ask(text) {
        text = (text || '').trim();
        if (!text || busy) return;
        busy = true;
        confirmBox.style.display = 'none';
        repeatBtn.style.display = 'none';
        youEl.textContent = '🗣️ ' + text;
        ansEl.textContent = '';
        setState('⏳ أفكر...');
        fetch(URL_, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text, history: history })
        })
        .then(function (r) { return r.text(); })
        .then(function (raw) {
            var j;
            try { j = JSON.parse(raw); } catch (e) { throw new Error('رد غير متوقع: ' + raw.substring(0, 120)); }
            if (!j.ok) throw new Error(j.error || 'خطأ');
            lastAnswer = j.answer;
            lastSay = j.say || null;
            showAnswer(j.answer, j.highlight);
            if (j.debug) {
                var s = document.createElement('small');
                s.textContent = 'وضع احتياطي: ' + j.debug;
                ansEl.appendChild(s);
            }
            repeatBtn.style.display = 'block';
            if (j.confirm) confirmBox.style.display = 'flex';
            history.push({ role: 'user', text: text }, { role: 'model', text: j.answer });
            history = history.slice(-10);
            speak(j.answer, function () {
                if (j.saved) { setTimeout(function () { location.reload(); }, 800); return; }
                if (j.expect_reply && SR) startListen();
                else setState(j.confirm ? 'اضغط «نعم» أو «لا»، أو قلها بصوتك' : 'اضغط الزر وتكلم مرة ثانية');
            }, j.say);
        })
        .catch(function (e) {
            ansEl.textContent = 'ما قدرت أجاوب الحين، جرّب مرة ثانية.';
            setState('⚠️ ' + e.message);
        })
        .then(function () { busy = false; });
    }

    function stopListen() { try { rec && rec.stop(); } catch (e) {} }

    // ينهي الاستماع ويرسل ما سمعه
    function finishListen() {
        clearTimeout(silenceT);
        if (handled) return;
        handled = true;
        stopListen();
        if (heard.trim()) ask(heard);
        else setState('ما سمعتك، قرّب من الجهاز واضغط وتكلم');
    }

    function startListen() {
        stopSpeak();
        if (!SR) { setState('هذا المتصفح لا يدعم الميكروفون، اكتب سؤالك أو افتح Chrome'); return; }
        handled = false; heard = '';
        rec = new SR();
        rec.lang = 'ar-SA';
        rec.interimResults = true;
        rec.continuous = true;   // لا يقفل عند أول صمت قصير
        rec.onstart = function () { listening = true; setBtn(true); setState('👂 أسمعك... خذ راحتك'); youEl.textContent = ''; };
        rec.onresult = function (e) {
            var t = '';
            for (var i = 0; i < e.results.length; i++) t += e.results[i][0].transcript + ' ';
            heard = t.trim();
            youEl.textContent = '🗣️ ' + heard;
            clearTimeout(silenceT);
            silenceT = setTimeout(finishListen, SILENCE_MS);   // ننتظر صمتًا طويلًا قبل الإرسال
        };
        rec.onerror = function (e) {
            var m = { 'not-allowed': 'اسمح للمتصفح باستخدام الميكروفون (أيقونة القفل بجانب الرابط)',
                      'no-speech': 'ما سمعت صوتًا، اضغط الزر وحاول مرة ثانية',
                      'network': 'التعرف على الصوت يحتاج إنترنت',
                      'audio-capture': 'لا يوجد ميكروفون في جهازك' };
            setState('⚠️ ' + (m[e.error] || e.error));
        };
        rec.onend = function () {
            listening = false; setBtn(false);
            clearTimeout(silenceT);
            if (!handled) {
                if (heard.trim()) { handled = true; ask(heard); }
                else if (stateEl.textContent.indexOf('⚠️') !== 0) setState('ما سمعتك، اضغط الزر وحاول مرة ثانية');
            }
        };
        try { rec.start(); } catch (e) {}
    }

    btn.onclick = function () {
        panel.classList.add('show');
        if (listening) { if (heard.trim()) finishListen(); else { handled = true; stopListen(); } }
        else startListen();
    };
    $('astClose').onclick = function () {
        handled = true; clearTimeout(silenceT); stopListen(); stopSpeak(); panel.classList.remove('show');
    };
    repeatBtn.onclick = function () {
        if (!lastAnswer) return;
        speak(lastAnswer, function () { setState('اضغط الزر وتكلم مرة ثانية'); }, lastSay);
    };
    function answerBtn(word) {
        handled = true; clearTimeout(silenceT); stopListen(); stopSpeak();
        ask(word);
    }
    $('astYes').onclick = function () { answerBtn('نعم'); };
    $('astNo').onclick = function () { answerBtn('لا'); };

    // زر التبطيء: يبدّل بين السرعة الطبيعية والبطيئة، ويطبّق فورًا على المقطع الجاري
    slowBtn.onclick = function () {
        slow = !slow;
        slowBtn.classList.toggle('on', slow);
        slowBtn.setAttribute('aria-pressed', slow ? 'true' : 'false');
        slowBtn.textContent = slow ? '🐢 الصوت بطيء (اضغط للعادي)' : '🐢 أبطئ الصوت';
        if (curAudio) { try { curAudio.playbackRate = curRate(); } catch (e) {} }
    };

    $('astSend').onclick = function () { var v = input.value; input.value = ''; ask(v); };
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { var v = input.value; input.value = ''; ask(v); } });
    if ('speechSynthesis' in window) speechSynthesis.getVoices();
})();
</script>
