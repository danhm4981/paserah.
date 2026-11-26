<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}
date_default_timezone_set('Asia/Riyadh');
require_once __DIR__ . '/db.php';

// المستخدم الحالي من الجلسة
$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!$userId) { header('Location: login.php'); exit; }

$today = date('Y-m-d');
$nowTs = time();

// الوقت بصيغة 12 ساعة بالعربي (ص / م)
function fmt12($time) {
    $ts = strtotime($time);
    return (((int) date('G', $ts)) % 12 ?: 12) . ':' . date('i', $ts) . ' ' . (date('G', $ts) >= 12 ? 'م' : 'ص');
}

function doseName($n) {
    $names = [1 => 'الجرعة الأولى', 2 => 'الجرعة الثانية', 3 => 'الجرعة الثالثة', 4 => 'الجرعة الرابعة'];
    return $names[(int)$n] ?? 'الجرعة ' . (int)$n;
}

$error = '';
$scheduled = [];
$taken = [];
$missed = [];
$pendingDoses = [];

try {
    // جرعات المستخدم الحالي الفعّالة اليوم (مع صورة الدواء ووصفه)
    $st = $pdo->prepare("
        SELECT s.schedule_id, s.dosage_time, s.dose_amount, s.dose_unit,
               m.medication_name, m.medication_image, m.medication_description,
               (SELECT COUNT(*) + 1 FROM dosage_schedules s2
                 WHERE s2.medication_id = s.medication_id
                   AND s2.dosage_time < s.dosage_time) AS dose_number
        FROM dosage_schedules s
        JOIN medications m ON m.medication_id = s.medication_id
        WHERE m.user_id = ?
          AND s.start_date <= ?
          AND (s.end_date IS NULL OR s.end_date >= ?)
        ORDER BY s.dosage_time ASC
    ");
    $st->execute([$userId, $today, $today]);
    $rows = $st->fetchAll();

    // ردود اليوم المحفوظة (taken / missed) من جدول التنبيهات
    $st = $pdo->prepare("
        SELECT schedule_id, dose_response
        FROM notifications
        WHERE user_id = ? AND dose_response IS NOT NULL AND DATE(notification_time) = ?
    ");
    $st->execute([$userId, $today]);
    $resp = [];
    foreach ($st->fetchAll() as $r) {
        $resp[(int) $r['schedule_id']] = $r['dose_response'];
    }

    foreach ($rows as $r) {
        $sid  = (int) $r['schedule_id'];
        $r['today_status'] = $resp[$sid] ?? null;
        $due  = strtotime($today . ' ' . $r['dosage_time']);

        if ($r['today_status'] === 'taken') {
            $taken[] = $r;
        } elseif ($r['today_status'] === 'missed' || $due <= $nowTs) {
            $missed[] = $r;      // انتهى وقتها ولم تؤخذ
        } else {
            $scheduled[] = $r;   // لسه ما جا وقتها
        }

        // النافذة المنبثقة: جرعة بلا رد، موعدها لم يحن أو مضى عليه دقيقتان على الأكثر
        if ($r['today_status'] === null && $nowTs - $due <= 120) {
            $pendingDoses[] = [
                'id'    => $sid,
                'name'  => $r['medication_name'],
                'dose'  => doseName($r['dose_number']) . ' • '
                           . rtrim(rtrim(number_format((float) $r['dose_amount'], 2, '.', ''), '0'), '.')
                           . ' ' . ($r['dose_unit'] ?: 'حبة'),
                'desc'  => $r['medication_description'] ?: '',
                'time'  => $r['dosage_time'],
                'label' => fmt12($r['dosage_time']),
                'img'   => !empty($r['medication_image']) ? 'uploads/' . rawurlencode(basename($r['medication_image'])) : '',
            ];
        }
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'تعذر تحميل التنبيهات.';
}

include __DIR__ . '/includes/header.php';

// يرسم كارد واحد (مع صورة الدواء)
function renderCard($title, $icon, $cls, $items, $emptyText) { ?>
    <div class="notif-box <?= $cls ?>">
        <div class="notif-box-head">
            <span><?= $icon ?> <?= $title ?></span>
            <span class="notif-count"><?= count($items) ?></span>
        </div>
        <?php if (!$items): ?>
            <p class="notif-empty"><?= $emptyText ?></p>
        <?php endif; ?>
        <?php foreach ($items as $r): ?>
            <?php
                $img_file = !empty($r['medication_image']) ? basename($r['medication_image']) : '';
                $img_path = ($img_file !== '' && file_exists(__DIR__ . '/uploads/' . $img_file))
                    ? 'uploads/' . rawurlencode($img_file) : '';
            ?>
            <div class="notif-item">
                <div class="notif-thumb">
                    <?php if ($img_path !== ''): ?>
                        <img src="<?= htmlspecialchars($img_path, ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <?php else: ?>💊<?php endif; ?>
                </div>
                <div class="notif-text">
                    <div class="notif-name"><?= htmlspecialchars($r['medication_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="notif-meta">
                        <?= doseName($r['dose_number']) ?> • الساعة <?= fmt12($r['dosage_time']) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php }
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
        align-items: stretch;
        min-height: calc(100vh - 140px);
        width: 100%;
    }
    .notif-content { flex: 1; padding: 24px; }
    .notif-content h2 { margin: 0 0 6px; }
    .notif-sub { color: #666; font-size: 14px; margin: 0 0 18px; }
    .notif-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        align-items: start;
    }
    @media (max-width: 900px) { .notif-grid { grid-template-columns: 1fr; } }

    .notif-box {
        background: #fff; border-radius: 14px; padding: 14px;
        box-shadow: 0 1px 6px rgba(0,0,0,.08);
        border-top: 5px solid #9aa0a6;
    }
    .notif-box.taken  { border-top-color: #2e9e4f; }
    .notif-box.missed { border-top-color: #d93025; }
    .notif-box-head {
        display: flex; justify-content: space-between; align-items: center;
        font-weight: bold; font-size: 17px; margin-bottom: 12px;
    }
    .notif-count {
        background: #9aa0a6; color: #fff; border-radius: 20px;
        padding: 2px 12px; font-size: 14px;
    }
    .notif-box.taken  .notif-count { background: #2e9e4f; }
    .notif-box.missed .notif-count { background: #d93025; }

    .notif-item {
        background: #f6f7f9; border-radius: 10px; padding: 10px 12px; margin-bottom: 8px;
        display: flex; align-items: center; gap: 12px;
    }
    .notif-thumb {
        width: 48px; height: 48px; border-radius: 10px; background: #fff;
        display: grid; place-items: center; font-size: 24px; overflow: hidden; flex: none;
    }
    .notif-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .notif-text { flex: 1; min-width: 0; }
    .notif-name { font-weight: bold; font-size: 16px; }
    .notif-meta { color: #666; font-size: 13px; margin-top: 3px; }
    .notif-empty { text-align: center; color: #999; font-size: 14px; margin: 14px 0; }
</style>

<div class="dashboard-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="notif-content">
        <h2>🔔 التنبيهات</h2>
        <p class="notif-sub">جرعات اليوم: القادمة هي الجرعات التي لم يحن موعدها بعد خلال اليوم</p>

        <?php if ($error): ?>
            <p class="notif-empty"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <div class="notif-grid">
                <?php
                renderCard('القادمة اليوم', '⏳', 'upcoming', $scheduled, 'لا توجد أدوية قادمة لبقية اليوم');
                renderCard('تم أخذها', '✅', 'taken', $taken, 'لم يتم أخذ أي دواء بعد');
                renderCard('لم يتم أخذها', '❌', 'missed', $missed, 'لا توجد أدوية فائتة');
                ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<style>
    #dose-popups {
        position: fixed; top: 90px; left: 50%; transform: translateX(-50%);
        z-index: 9999; display: flex; flex-direction: column; gap: 12px;
        width: min(92vw, 380px);
    }
    .dose-popup {
        background: #fff; border-radius: 14px; padding: 16px;
        box-shadow: 0 8px 28px rgba(0,0,0,.25); border-top: 6px solid #f5a623;
        animation: dpIn .35s ease; direction: rtl; text-align: center;
    }
    @keyframes dpIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: none; } }
    .dose-popup .dp-title { color: #f5a623; font-weight: bold; font-size: 14px; margin-bottom: 8px; }
    .dose-popup .dp-img   { width: 120px; height: 120px; border-radius: 16px; margin: 0 auto 10px; background: #f6f7f9; display: grid; place-items: center; font-size: 52px; overflow: hidden; }
    .dose-popup .dp-img img { width: 100%; height: 100%; object-fit: cover; }
    .dose-popup .dp-name  { font-size: 20px; font-weight: bold; }
    .dose-popup .dp-dose  { color: #555; margin: 6px 0 14px; font-size: 15px; }
    .dose-popup .dp-btns  { display: flex; gap: 10px; }
    .dose-popup button {
        flex: 1; border: 0; color: #fff; padding: 11px 6px; border-radius: 10px;
        font-size: 15px; font-weight: bold; cursor: pointer; font-family: inherit;
    }
    .dose-popup .dp-missed { background: #d93025; }
    .dose-popup .dp-taken  { background: #2e9e4f; }
    .dose-popup button:disabled { opacity: .6; cursor: wait; }
</style>

<div id="dose-popups"></div>
<script>
(function () {
    var doses = <?= json_encode($pendingDoses, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var HIDE_AFTER_SEC = 10;   // بعد انتهاء النطق تختفي النافذة تلقائيًا
    var box = document.getElementById('dose-popups');
    var ac, curAudio = null;

    // الجرعات التي انتهت نافذتها اليوم (تبقى بعد إعادة التحميل فلا تظهر النافذة مرة ثانية)
    var KEY = 'shownDoses_' + <?= json_encode($today) ?>;
    var done = {}, active = {};
    try { done = JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch (e) {}
    function markDone(id) {
        done[id] = 1;
        try { sessionStorage.setItem(KEY, JSON.stringify(done)); } catch (e) {}
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

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

    // الاحتياطي: صوت المتصفح
    function browserSpeak(text, go) {
        if (!('speechSynthesis' in window)) { setTimeout(go, 900); return; }
        try {
            var voices = speechSynthesis.getVoices().filter(function (v) {
                return v.lang.toLowerCase().replace('_', '-').indexOf('ar') === 0;
            });
            var u = new SpeechSynthesisUtterance(text);
            u.lang = 'ar-SA'; u.rate = .9;
            if (voices.length) u.voice = voices[0];
            u.onend = go; u.onerror = go;
            speechSynthesis.cancel();
            setTimeout(function () { speechSynthesis.speak(u); }, 350);
            setTimeout(go, 25000);
        } catch (e) { setTimeout(go, 900); }
    }

    // نطق التنبيه بصوت Gemini، وعند أي فشل يرجع لصوت المتصفح، ثم ينفذ cb
    // isDead(): ترجع true إذا أُغلقت النافذة، فلا نشغّل أي صوت
    function speak(text, cb, isDead) {
        var fired = false, fellBack = false, t0 = Date.now();
        function go() { if (!fired) { fired = true; cb(); } }
        function fallback() {
            if (fellBack || fired || isDead()) return;
            fellBack = true;
            browserSpeak(text, go);
        }
        beep();
        setTimeout(go, 45000);   // أمان: لا ننتظر أكثر من هذا

        fetch('tts.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text, src: 'reminder' })
        })
        .then(function (r) { if (!r.ok) throw new Error('tts ' + r.status); return r.blob(); })
        .then(function (b) {
            if (isDead()) return;
            var a = new Audio(URL.createObjectURL(b));
            curAudio = a;
            a.onended = go;
            a.onerror = fallback;
            // ننتظر انتهاء نغمة التنبيه حتى لا يتداخل الصوتان
            var wait = Math.max(0, 750 - (Date.now() - t0));
            return new Promise(function (res) { setTimeout(res, wait); })
                .then(function () { if (!isDead()) return a.play(); });
        })
        .catch(function (e) { console.warn('TTS fallback:', e); fallback(); });
    }

    function stopAudio() {
        if ('speechSynthesis' in window) speechSynthesis.cancel();
        if (curAudio) { try { curAudio.pause(); } catch (e) {} curAudio = null; }
    }

    function send(d, status, el) {
        el._dead = true;
        if (el._timer) clearTimeout(el._timer);
        stopAudio();
        el.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        fetch('save_dose.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ schedule_id: d.id, response: status })
        })
        .then(function (r) { return r.text(); })
        .then(function (txt) {
            var j;
            try { j = JSON.parse(txt); } catch (e) { j = null; }
            if (j && j.ok) {
                markDone(d.id);
                el.remove();
                location.reload();   // يتحدّث الكارد المناسب
            } else {
                el._dead = false;
                el.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
                alert((j && j.error) || 'تعذر حفظ الاختيار، حاول مرة ثانية');
            }
        })
        .catch(function () {
            el._dead = false;
            el.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
            alert('تعذر الاتصال بالخادم، حاول مرة ثانية');
        });
    }

    function show(d) {
        var el = document.createElement('div');
        el.className = 'dose-popup';

        var t = document.createElement('div'); t.className = 'dp-title';
        t.textContent = '⏰ حان وقت جرعتك (' + d.label + ')';

        var im = document.createElement('div'); im.className = 'dp-img';
        if (d.img) {
            var i = document.createElement('img');
            i.src = d.img; i.alt = '';
            i.onerror = function () { im.textContent = '💊'; i.remove(); };
            im.appendChild(i);
        } else {
            im.textContent = '💊';
        }

        var n = document.createElement('div'); n.className = 'dp-name'; n.textContent = d.name;
        var s = document.createElement('div'); s.className = 'dp-dose'; s.textContent = d.dose;

        var btns = document.createElement('div'); btns.className = 'dp-btns';
        var no = document.createElement('button'); no.className = 'dp-missed'; no.textContent = 'لم يتم أخذها';
        var yes = document.createElement('button'); yes.className = 'dp-taken'; yes.textContent = 'تم أخذها';
        no.onclick  = function () { send(d, 'missed', el); };
        yes.onclick = function () { send(d, 'taken', el); };
        btns.appendChild(no); btns.appendChild(yes);

        el.appendChild(t); el.appendChild(im); el.appendChild(n); el.appendChild(s); el.appendChild(btns);
        box.appendChild(el);

        // بعد انتهاء النطق تختفي النافذة بعد 10 ثوانٍ (بدون رد: لا يُحفظ شيء)
        speak('حان موعد الجرعة. ' + d.name + '، ' + d.dose + '. الساعة ' + d.label, function () {
            if (el._dead) return;
            el._timer = setTimeout(function () {
                if (el.parentNode && !el._dead) {
                    el._dead = true;
                    stopAudio();
                    markDone(d.id);   // لا تظهر النافذة مرة ثانية بعد إعادة التحميل
                    el.remove();
                    location.reload();
                }
            }, HIDE_AFTER_SEC * 1000);
        }, function () { return !!el._dead; });
    }

    function check() {
        var now = new Date();
        var nowStr = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        doses.forEach(function (d) {
            if (!done[d.id] && !active[d.id] && d.time <= nowStr) {
                active[d.id] = true;
                show(d);
            }
        });
    }

    check();
    setInterval(check, 10000);   // يفحص كل 10 ثواني
})();
</script>
