<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}
date_default_timezone_set('Asia/Riyadh');
require_once __DIR__ . '/db.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['account_type'] ?? '') !== 'patient'
) {
    header('Location: login.php');
    exit;
}

$patient_id = (int) $_SESSION['user_id'];

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ===== تم أخذ الجرعة / لم آخذها =====
$dose_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dose_action'])) {
    $schedule_id = (int) ($_POST['schedule_id'] ?? 0);
    $new_status  = $_POST['dose_action'];

    if (in_array($new_status, ['taken', 'missed'], true)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE dosage_schedules ds
                JOIN medications m ON ds.medication_id = m.medication_id
                SET ds.status = ?
                WHERE ds.schedule_id = ? AND m.user_id = ?
            ");
            $stmt->execute([$new_status, $schedule_id, $patient_id]);
            $dose_message = $stmt->rowCount() > 0 ? 'تم تحديث حالة الجرعة.' : 'تعذر تحديث الجرعة.';
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $dose_message = 'حدث خطأ أثناء تحديث الجرعة.';
        }
    }
}

include __DIR__ . '/includes/header.php';

// ===== أيام الأسبوع (يبدأ من السبت) =====
$today       = date('Y-m-d');
$since_sat   = ((int) date('w') + 1) % 7;
$week_start  = date('Y-m-d', strtotime("-$since_sat days"));
$week_end    = date('Y-m-d', strtotime("$week_start +6 days"));
$day_names   = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
$week_days   = [];
for ($i = 0; $i < 7; $i++) {
    $week_days[] = date('Y-m-d', strtotime("$week_start +$i days"));
}

$by_day = [];
foreach ($week_days as $d) {
    $by_day[$d] = [];
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM dosage_schedules")->fetchAll(PDO::FETCH_COLUMN);
    $has_range = in_array('start_date', $cols, true) && in_array('end_date', $cols, true);

    if ($has_range) {
        $stmt = $pdo->prepare("
            SELECT ds.schedule_id, ds.start_date, ds.end_date, ds.dose_amount, ds.dose_unit,
                   ds.dosage_time, ds.status, m.medication_id, m.medication_name
            FROM dosage_schedules ds
            JOIN medications m ON ds.medication_id = m.medication_id
            WHERE m.user_id = ?
              AND ds.start_date <= ?
              AND (ds.end_date IS NULL OR ds.end_date >= ?)
            ORDER BY ds.dosage_time ASC
        ");
        $stmt->execute([$patient_id, $week_end, $week_start]);
        foreach ($stmt->fetchAll() as $row) {
            foreach ($week_days as $d) {
                if ($row['start_date'] <= $d && ($row['end_date'] === null || $row['end_date'] >= $d)) {
                    $by_day[$d][] = $row;
                }
            }
        }
    } else {
        $stmt = $pdo->prepare("
            SELECT ds.schedule_id, ds.dosage_date AS d, ds.dose_amount, ds.dose_unit,
                   ds.dosage_time, ds.status, m.medication_id, m.medication_name
            FROM dosage_schedules ds
            JOIN medications m ON ds.medication_id = m.medication_id
            WHERE m.user_id = ?
              AND ds.dosage_date BETWEEN ? AND ?
            ORDER BY ds.dosage_time ASC
        ");
        $stmt->execute([$patient_id, $week_start, $week_end]);
        foreach ($stmt->fetchAll() as $row) {
            if (isset($by_day[$row['d']])) {
                $by_day[$row['d']][] = $row;
            }
        }
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
}

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
        .dose-circle.pending { background: #eceff0; color: #667; }
        .dose-time { font-size: 13px; color: var(--text-muted, #667); }
        .dose-actions { display: flex; gap: 4px; }
        .dose-actions button {
            border: 0; border-radius: 12px; padding: 4px 9px;
            font-family: inherit; font-size: 12px; cursor: pointer;
        }
        .btn-take { background: var(--primary-color, #008080); color: #fff; }
        .btn-miss { background: #fdebd3; color: #b45f00; }

        /* جدول الأسبوع */
        .week-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 12px; }
        .week-col { display: flex; flex-direction: column; gap: 8px; }
        .week-cell {
            height: 38px; border-radius: 10px; background: #eceff0;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; color: #fff;
        }
        .week-cell.taken   { background: #17a82a; }
        .week-cell.pending { background: #f0c020; color: #5a4600; }
        .week-cell.missed  { background: #e5772d; }
        .week-cell.empty   { background: transparent; border: 1px dashed #dde3e4; }
        .week-label { text-align: center; font-size: 14px; margin-top: 4px; color: var(--text-color, #222); }
        .week-label.today { color: var(--primary-color, #008080); font-weight: 800; }
        .legend { display: flex; gap: 16px; justify-content: center; margin-top: 18px; font-size: 13px; color: var(--text-muted, #667); }
        .legend span::before {
            content: ""; display: inline-block; width: 12px; height: 12px;
            border-radius: 4px; margin-left: 6px; vertical-align: middle;
        }
        .legend .l-taken::before   { background: #17a82a; }
        .legend .l-pending::before { background: #f0c020; }
        .legend .l-missed::before  { background: #e5772d; }

        .empty-notice { padding: 30px; text-align: center; color: var(--text-muted, #667); }
        .flash { margin-bottom: 14px; color: var(--primary-color, #008080); }

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

                <?php if ($dose_message): ?>
                    <p class="flash"><?= h($dose_message) ?></p>
                <?php endif; ?>

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
                                        if ($d['status'] === 'taken') $done++;
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
                                                <?php if ($d['status'] === 'taken'): ?>
                                                    <div class="dose-circle taken">✓</div>
                                                <?php elseif ($d['status'] === 'missed'): ?>
                                                    <div class="dose-circle missed">✕</div>
                                                <?php else: ?>
                                                    <div class="dose-circle pending">🕒</div>
                                                <?php endif; ?>

                                                <div class="dose-time"><?= h(date('h:i A', strtotime($d['dosage_time']))) ?></div>

                                                <?php if ($d['status'] === 'pending'): ?>
                                                    <form method="POST" class="dose-actions">
                                                        <input type="hidden" name="schedule_id" value="<?= (int)$d['schedule_id'] ?>">
                                                        <button type="submit" name="dose_action" value="taken" class="btn-take">تم أخذها</button>
                                                        <button type="submit" name="dose_action" value="missed" class="btn-miss">لم آخذها</button>
                                                    </form>
                                                <?php endif; ?>
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
                                        <?php
                                            $cls = in_array($r['status'], ['taken', 'missed'], true) ? $r['status'] : 'pending';
                                        ?>
                                        <div class="week-cell <?= h($cls) ?>"
                                             title="<?= h($r['medication_name'] . ' - ' . date('h:i A', strtotime($r['dosage_time']))) ?>">
                                            <?= h(date('h:i', strtotime($r['dosage_time']))) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="week-label <?= $d === $today ? 'today' : '' ?>"><?= h($day_names[$i]) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="legend">
                        <span class="l-taken">تم أخذها</span>
                        <span class="l-pending">بانتظار الموعد</span>
                        <span class="l-missed">لم تؤخذ</span>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <script>
        document.querySelectorAll('.view-tabs button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.view-tabs button').forEach(function (b) { b.classList.remove('active'); });
                document.querySelectorAll('.view-panel').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                document.getElementById('view-' + btn.dataset.view).classList.add('active');
            });
        });
    </script>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
