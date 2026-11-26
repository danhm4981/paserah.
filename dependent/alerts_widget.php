<?php
$__base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if (basename($__base) !== 'dependent') {
    $__base .= '/dependent';
}
$__alertsUrl = $__base . '/dep_alerts.php';
?>
<!-- نافذة احتياطية للصفحات التي ليس فيها صندوق التنبيهات -->
<div id="depAlertOv" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px">
  <div id="depAlertModal" style="width:min(400px,100%)"></div>
</div>

<style>
.dep-alert{background:#fff;border-radius:16px;padding:18px 20px;margin-bottom:14px;display:flex;gap:16px;align-items:flex-start;border:1.5px solid #f3c1bc;border-right:6px solid #d93025;box-shadow:0 4px 15px rgba(0,0,0,.05);font-family:'Tajawal',Tahoma,Arial,sans-serif;text-align:right}
.dep-alert.ok{border-color:#bfe3c1;border-right-color:#1e9e2f}
.dep-alert .ic{font-size:38px;line-height:1}
.dep-alert .bd{flex:1;min-width:0}
.dep-alert .tt{font-weight:800;font-size:16px;color:#d93025;margin-bottom:6px}
.dep-alert.ok .tt{color:#1e9e2f}
.dep-alert .md{font-size:20px;font-weight:800;color:#2b3e42}
.dep-alert .ds{font-size:14px;color:#557075;margin-top:4px}
.dep-alert .ms{font-size:13px;color:#667;margin-top:8px}
.dep-alert button{border:0;border-radius:10px;padding:9px 16px;background:#008080;color:#fff;font-family:inherit;font-weight:700;cursor:pointer;white-space:nowrap}
@media(max-width:600px){.dep-alert{flex-wrap:wrap}.dep-alert button{width:100%}}
</style>

<script>
(function () {
    var URL_ = <?= json_encode($__alertsUrl) ?>;
    var seen = {}, ac, first = true;
    var box = document.getElementById('depAlertsBox');   // موجود في لوحة التابع فقط
    var ov = document.getElementById('depAlertOv');
    var modal = document.getElementById('depAlertModal');

    function beep() {
        try {
            ac = ac || new (window.AudioContext || window.webkitAudioContext)();
            if (ac.state === 'suspended') ac.resume();
            [0, .35].forEach(function (d) {
                var o = ac.createOscillator(), g = ac.createGain();
                o.frequency.value = 880; o.connect(g); g.connect(ac.destination);
                g.gain.setValueAtTime(.25, ac.currentTime + d);
                g.gain.exponentialRampToValueAtTime(.001, ac.currentTime + d + .3);
                o.start(ac.currentTime + d); o.stop(ac.currentTime + d + .3);
            });
        } catch (e) {}
    }

    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (txt) e.textContent = txt;
        return e;
    }

    function card(a, patient) {
        var ok = a.kind === 'late_taken';
        var c = el('div', 'dep-alert' + (ok ? ' ok' : ''));
        c.appendChild(el('div', 'ic', ok ? '✅' : '⚠️'));
        var bd = el('div', 'bd');
        bd.appendChild(el('div', 'tt', ok
            ? 'المريض ' + patient + ' تناول الدواء متأخرًا'
            : 'تنبيه: المريض ' + patient + ' فوّت هذا الدواء'));
        bd.appendChild(el('div', 'md', '💊 ' + (a.med || a.text)));
        if (a.dose) {
            bd.appendChild(el('div', 'ds', 'الجرعة: ' + a.dose + (a.dueTime ? ' · موعدها ' + a.dueTime : '')));
        }
        bd.appendChild(el('div', 'ms', (a.kind === 'noresp' ? 'لم يرد على التنبيه · ' : a.kind === 'missed' ? 'ضغط «لم أتناول الدواء» · ' : '') + 'وقت الإشعار: ' + a.time));
        c.appendChild(bd);
        var b = el('button', '', 'تم الاطلاع');
        b.type = 'button';
        b.onclick = function () {
            fetch(URL_, { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: a.id }) }).catch(function () {});
            c.remove();
            if (!box && !modal.children.length) ov.style.display = 'none';
            if (!box) next();
        };
        c.appendChild(b);
        return c;
    }

    var pending = [], patientName = '';
    function next() {
        if (box) return;
        if (modal.children.length || !pending.length) return;
        modal.appendChild(card(pending.shift(), patientName));
        ov.style.display = 'flex';
    }

    function render(d) {
        var fresh = false;
        d.alerts.forEach(function (a) {
            var k = a.id + '|' + a.kind;
            if (!seen[k]) { seen[k] = 1; fresh = true; if (!box) pending.push(a); }
        });
        patientName = d.patient;
        if (box) {
            box.innerHTML = '';
            d.alerts.forEach(function (a) { box.appendChild(card(a, d.patient)); });
        } else {
            next();
        }
        if (fresh) beep();
    }

    function poll() {
        fetch(URL_, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.ok) render(d); })
            .catch(function () {});
    }

    poll();
    setInterval(poll, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
</script>
