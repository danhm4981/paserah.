<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}
date_default_timezone_set('Asia/Riyadh');
require_once __DIR__ . '/db.php';

// التحقق من تسجيل الدخول
$patient_id = (int) ($_SESSION['user_id'] ?? 0);
if (!$patient_id) {
    if (isset($_GET['ajax'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false]);
        exit;
    }
    header('Location: login.php');
    exit;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// الوقت بصيغة 12 ساعة بالعربي (ص / م)
function fmt12($time) {
    $ts = strtotime($time);
    $hr = (int) date('G', $ts);
    $p  = $hr >= 12 ? 'م' : 'ص';
    $hr = $hr % 12 ?: 12;
    return $hr . ':' . date('i', $ts) . ' ' . $p;
}

// ===== أيام الأسبوع (يبدأ من السبت) =====
$today      = date('Y-m-d');
$nowTs      = time();
$since_sat  = ((int) date('w') + 1) % 7;
$week_start = date('Y-m-d', strtotime("-$since_sat days"));
$week_end   = date('Y-m-d', strtotime("$week_start +6 days"));
$day_names  = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
$week_days  = [];
for ($i = 0; $i < 7; $i++) {
    $week_days[] = date('Y-m-d', strtotime("$week_start +$i days"));
}

// المهلة بعد موعد الجرعة قبل اعتبارها «لم يرد» (بالثواني)
const NORESP_GRACE = 120;

$by_day = [];   // الجرعات لكل يوم
$states = [];   // "scheduleId|YYYY-MM-DD" => taken | missed | noresp | pending

try {
    // الجرعات الفعّالة خلال الأسبوع للمستخدم الحالي فقط
    $stmt = $pdo->prepare("
        SELECT ds.schedule_id, ds.start_date, ds.end_date, ds.dose_amount, ds.dose_unit,
               ds.dosage_time, m.medication_id, m.medication_name
        FROM dosage_schedules ds
        JOIN medications m ON ds.medication_id = m.medication_id
        WHERE m.user_id = ?
          AND ds.start_date <= ?
          AND (ds.end_date IS NULL OR ds.end_date >= ?)
        ORDER BY ds.dosage_time ASC, m.medication_name ASC
    ");
    $stmt->execute([$patient_id, $week_end, $week_start]);
    $schedules = $stmt->fetchAll();

    // ردود المستخدم خلال الأسبوع (من جدول التنبيهات، لكل يوم على حدة)
    $stmt = $pdo->prepare("
        SELECT schedule_id, dose_response, notification_time
        FROM notifications
        WHERE user_id = ?
          AND dose_response IS NOT NULL
          AND notification_time >= ?
          AND notification_time < ?
        ORDER BY notification_time ASC
    ");
    $stmt->execute([
        $patient_id,
        $week_start . ' 00:00:00',
        date('Y-m-d', strtotime("$week_end +1 day")) . ' 00:00:00'
    ]);
    $responses = [];
    foreach ($stmt->fetchAll() as $r) {
        $responses[$r['schedule_id'] . '|' . substr($r['notification_time'], 0, 10)] = $r['dose_response'];
    }

    foreach ($week_days as $d) {
        $by_day[$d] = [];
    }
    foreach ($schedules as $row) {
        foreach ($week_days as $d) {
            if ($row['start_date'] <= $d && ($row['end_date'] === null || $row['end_date'] >= $d)) {
                $key = $row['schedule_id'] . '|' . $d;
                if (isset($responses[$key])) {
                    $state = $responses[$key];
                } elseif ($d > $today) {
                    $state = 'pending';
                } else {
                    $due   = strtotime($d . ' ' . $row['dosage_time']);
                    $state = ($nowTs >= $due + NORESP_GRACE) ? 'noresp' : 'pending';
                }
                $row['state'] = $state;
                $row['key']   = $key;
                $by_day[$d][] = $row;
                $states[$key] = $state;
            }
        }
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    foreach ($week_days as $d) {
        if (!isset($by_day[$d])) $by_day[$d] = [];
    }
}

// طلب التحديث التلقائي: يرجع الحالات فقط
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'states' => (object)$states], JSON_UNESCAPED_UNICODE);
    exit;
}

include __DIR__ . '/includes/header.php';

// جرعات اليوم مجمّعة حسب الدواء
$by_med = [];
foreach ($by_day[$today] ?? [] as $row) {
    $id = $row['medication_id'];
    if (!isset($by_med[$id])) {
        $by_med[$id] = ['name' => $row['medication_name'], 'doses' => []];
    }
    $by_med[$id]['doses'][] = $row;
}
$meds_in_week = [];
foreach ($by_day as $rows) {
    foreach ($rows as $r) {
        $meds_in_week[$r['medication_id']] = true;
    }
}
$sym = ['taken' => '✓', 'missed' => '✕', 'noresp' => '!', 'pending' => '🕒'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بصيرة - لوحة المريض</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">

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
        .card-box {
            background: var(--white, #fff);
            border-radius: 15px;
            padding: 22px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }
        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            color: var(--text-color, #222);
            margin-bottom: 18px;
        }
        .card-title .count {
            color: var(--primary-color, #008080);
            font-weight: 700;
        }

        /* التبديل بين اليوم والأسبوع */
        .view-tabs {
            display: inline-flex;
            background: #eef2f3;
            border-radius: 999px;
            padding: 4px;
            margin: 0 auto 22px;
        }
        .view-tabs button {
            border: 0;
            background: transparent;
            padding: 10px 34px;
            border-radius: 999px;
            font-family: inherit;
            font-size: 16px;
            cursor: pointer;
            color: var(--text-muted, #667);
        }
        .view-tabs button.active {
            background: var(--primary-color, #008080);
            color: #fff;
            font-weight: 700;
        }
        .tabs-wrap { text-align: center; }
        .view-panel { display: none; }
        .view-panel.active { display: block; }

        /* جدول اليوم */
        .meds-table { width: 100%; border-collapse: collapse; text-align: right; }
        .meds-table th {
            background: #f4f7f7;
            padding: 12px 16px;
            font-weight: 500;
            color: var(--text-muted, #667);
            font-size: 14px;
        }
        .meds-table td {
            padding: 16px;
            border-top: 1px solid #eee;
            vertical-align: top;
        }
        .med-name { font-size: 17px; font-weight: 700; color: var(--text-color, #222); }
        .med-sub  { font-size: 13px; color: var(--text-muted, #667); margin-top: 4px; }
        .doses-row { display: flex; flex-wrap: wrap; gap: 18px; }
        .dose-item { display: flex; flex-direction: column; align-items: center; gap: 6px; min-width: 70px; }
        .dose-circle {
            width: 52px; height: 52px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 700;
        }
        .dose-circle.taken   { background: #e3f6e3; color: #1e9e2f; }
        .dose-circle.missed  { background: #fdebd3; color: #e08a00; }
        .dose-circle.noresp  { background: #fde4e2; color: #d93025; }
        .dose-circle.pending { background: #eceff0; color: #667; }
        .dose-time { font-size: 13px; color: var(--text-muted, #667); }

        /* جدول الأسبوع */
        .week-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 12px; }
        .week-col { display: flex; flex-direction: column; gap: 8px; }
        .week-cell {
            height: 38px; border-radius: 10px; background: #eceff0;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; color: #fff;
        }
        .week-cell.taken   { background: #17a82a; }
        .week-cell.pending { background: #eceff0; color: #667; }
        .week-cell.missed  { background: #e5772d; }
        .week-cell.noresp  { background: #d93025; }
        .week-cell.empty   { background: transparent; border: 1px dashed #dde3e4; }
        .week-label { text-align: center; font-size: 14px; margin-top: 4px; color: var(--text-color, #222); }
        .week-label.today { color: var(--primary-color, #008080); font-weight: 800; }
        .legend { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; margin-top: 18px; font-size: 13px; color: var(--text-muted, #667); }
        .legend span::before {
            content: ""; display: inline-block; width: 12px; height: 12px;
            border-radius: 4px; margin-left: 6px; vertical-align: middle;
        }
        .legend .l-taken::before   { background: #17a82a; }
        .legend .l-missed::before  { background: #e5772d; }
        .legend .l-noresp::before  { background: #d93025; }

        .empty-notice { padding: 30px; text-align: center; color: var(--text-muted, #667); }

        @media (max-width: 700px) {
            .week-grid { gap: 6px; }
            .week-cell { height: 30px; font-size: 9px; }
            .view-tabs button { padding: 9px 22px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main-content">

            <header class="welcome-header" style="margin-bottom: 25px;">
                <h1 style="font-size: 24px; color: var(--text-color);">مرحبًا بك في بصيرة 👋</h1>
                <p style="color: var(--text-muted);">تابع أدويتك وجرعاتك بسهولة</p>
            </header>

            <!-- ===== جدول الجرعات (اليوم / الأسبوع) ===== -->
            <section class="card-box">
                <div class="tabs-wrap">
                    <div class="view-tabs">
                        <button type="button" class="active" data-view="today">اليوم</button>
                        <button type="button" data-view="week">الأسبوع</button>
                    </div>
                </div>

                <!-- عرض اليوم -->
                <div class="view-panel active" id="view-today">
                    <div class="card-title">📅 <span>جرعات اليوم</span></div>

                    <?php if (empty($by_med)): ?>
                        <div class="empty-notice">لا توجد جرعات مجدولة لهذا اليوم.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                        <table class="meds-table">
                            <thead>
                                <tr><th>الدواء</th><th>جرعات اليوم</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($by_med as $med): ?>
                                <?php
                                    $total = count($med['doses']);
                                    $done  = 0;
                                    foreach ($med['doses'] as $d) {
                                        if ($d['state'] === 'taken') $done++;
                                    }
                                ?>
                                <tr>
                                    <td style="width:30%;">
                                        <div class="med-name"><?= h($med['name']) ?></div>
                                        <div class="med-sub"><?= $done ?> من <?= $total ?></div>
                                    </td>
                                    <td>
                                        <div class="doses-row">
                                        <?php foreach ($med['doses'] as $d): ?>
                                            <div class="dose-item">
                                                <div class="dose-circle <?= h($d['state']) ?>" data-key="<?= h($d['key']) ?>"><?= $sym[$d['state']] ?></div>
                                                <div class="dose-time"><?= h(fmt12($d['dosage_time'])) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- عرض الأسبوع -->
                <div class="view-panel" id="view-week">
                    <div class="card-title">🗓️ <span>جدول جرعات الأسبوع</span> <span class="count">(<?= count($meds_in_week) ?>)</span></div>

                    <div class="week-grid">
                        <?php foreach ($week_days as $i => $d): ?>
                            <div class="week-col">
                                <?php if (empty($by_day[$d])): ?>
                                    <div class="week-cell empty"></div>
                                <?php else: ?>
                                    <?php foreach ($by_day[$d] as $r): ?>
                                        <div class="week-cell <?= h($r['state']) ?>"
                                             data-key="<?= h($r['key']) ?>"
                                             data-time="<?= h(date('h:i', strtotime($r['dosage_time']))) ?>"
                                             title="<?= h($r['medication_name'] . ' - ' . fmt12($r['dosage_time'])) ?>">
                                            <?= $r['state'] === 'pending' ? '🕒 ' : '' ?><?= h(date('h:i', strtotime($r['dosage_time']))) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="week-label <?= $d === $today ? 'today' : '' ?>"><?= h($day_names[$i]) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="legend">
                        <span class="l-taken">تم أخذها</span>
                        <span class="l-missed">لم تؤخذ</span>
                        <span class="l-noresp">لم يرد</span>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <script>
        // التبديل بين اليوم والأسبوع
        document.querySelectorAll('.view-tabs button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.view-tabs button').forEach(function (b) { b.classList.remove('active'); });
                document.querySelectorAll('.view-panel').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                document.getElementById('view-' + btn.dataset.view).classList.add('active');
            });
        });

        // ===== التحديث التلقائي كل 30 ثانية =====
        var SYM = { taken: '✓', missed: '✕', noresp: '!', pending: '🕒' };

        function applyStates(states) {
            document.querySelectorAll('[data-key]').forEach(function (el) {
                var s = states[el.dataset.key] || 'pending';
                if (el.classList.contains('dose-circle')) {
                    el.className = 'dose-circle ' + s;
                    el.textContent = SYM[s];
                } else {
                    el.className = 'week-cell ' + s;
                    el.textContent = (s === 'pending' ? '🕒 ' : '') + el.dataset.time;
                }
            });
            // تحديث عدّاد «x من y» لكل دواء
            document.querySelectorAll('.meds-table tbody tr').forEach(function (tr) {
                var sub = tr.querySelector('.med-sub');
                if (!sub) return;
                var done  = tr.querySelectorAll('.dose-circle.taken').length;
                var total = tr.querySelectorAll('.dose-circle').length;
                sub.textContent = done + ' من ' + total;
            });
        }

        function refreshStates() {
            fetch(location.pathname + '?ajax=1', { cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) { if (data && data.ok) applyStates(data.states); })
                .catch(function () {});
        }

        setInterval(refreshStates, 30000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) refreshStates();
        });
    </script>

    <?php include __DIR__ . '/assistant_widget.php'; 
     include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
