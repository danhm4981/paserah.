<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}
date_default_timezone_set('Asia/Riyadh');
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const OVERDUE_MINUTES = 15; // المهلة قبل تنبيه التابع

$depId = (int) ($_SESSION['user_id'] ?? 0);
if (!$depId || ($_SESSION['account_type'] ?? '') !== 'dependent') {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

function t12($ts) {
    return date('g:i', $ts) . ' ' . (date('G', $ts) >= 12 ? 'م' : 'ص');
}
function doseTxt($amount, $unit) {
    return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.') . ' ' . $unit;
}

// ===== تم الاطلاع =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($in['id'] ?? 0);
    $st = $pdo->prepare("UPDATE notifications SET read_at = ?
        WHERE notification_id = ? AND user_id = ? AND notification_type = 'dependent_alert'");
    $st->execute([date('Y-m-d H:i:s'), $id, $depId]);
    echo json_encode(['ok' => true]);
    exit;
}

try {
    $st = $pdo->prepare("SELECT u.user_id, u.username
        FROM patient_dependents pd
        JOIN users u ON u.user_id = pd.patient_user_id
        WHERE pd.dependent_user_id = ? AND pd.status = 'active' LIMIT 1");
    $st->execute([$depId]);
    $patient = $st->fetch();
    $pname = $patient['username'] ?? '';

    if ($patient) {
        $pid    = (int) $patient['user_id'];
        $today  = date('Y-m-d');
        $nowTs  = time();
        $nowStr = date('Y-m-d H:i:s');

        $st = $pdo->prepare("SELECT ds.schedule_id, ds.dosage_time, ds.dose_amount, ds.dose_unit, m.medication_name
            FROM dosage_schedules ds
            JOIN medications m ON m.medication_id = ds.medication_id
            WHERE m.user_id = ? AND ds.start_date <= ?
              AND (ds.end_date IS NULL OR ds.end_date >= ?)");
        $st->execute([$pid, $today, $today]);
        $schedules = $st->fetchAll();

        $st = $pdo->prepare("SELECT schedule_id, dose_response FROM notifications
            WHERE user_id = ? AND dose_response IS NOT NULL AND DATE(notification_time) = ?
              AND (notification_type IS NULL OR notification_type <> 'dependent_alert')");
        $st->execute([$pid, $today]);
        $resp = [];
        foreach ($st->fetchAll() as $r) {
            $resp[(int) $r['schedule_id']] = $r['dose_response'];
        }

        $st = $pdo->prepare("SELECT notification_id, schedule_id, dose_response FROM notifications
            WHERE user_id = ? AND notification_type = 'dependent_alert' AND dose_date = ?");
        $st->execute([$depId, $today]);
        $alerts = [];
        foreach ($st->fetchAll() as $a) {
            $alerts[(int) $a['schedule_id']] = $a;
        }

        $ins = $pdo->prepare("INSERT INTO notifications
            (user_id, schedule_id, notification_type, notification_text, notification_time,
             dose_response, dose_date, in_site_status)
            VALUES (?, ?, 'dependent_alert', ?, ?, ?, ?, 'unread')");
        $upd = $pdo->prepare("UPDATE notifications
            SET notification_text = ?, dose_response = ?, notification_time = ?, read_at = NULL
            WHERE notification_id = ?");

        foreach ($schedules as $s) {
            $sid = (int) $s['schedule_id'];
            $due = strtotime($today . ' ' . $s['dosage_time']);
            if ($nowTs < $due + OVERDUE_MINUTES * 60) continue;

            $label = $s['medication_name'] . ' - الجرعة ' . doseTxt($s['dose_amount'], $s['dose_unit']) . ' - الساعة ' . t12($due);
            $r     = $resp[$sid] ?? null;
            $alert = $alerts[$sid] ?? null;

            if ($r === 'taken') {
                if ($alert && $alert['dose_response'] !== 'taken') {
                    $upd->execute(["المريض $pname تناول الدواء متأخرًا: $label", 'taken', $nowStr, $alert['notification_id']]);
                }
            } elseif ($r === 'missed') {
                $text = "تنبيه: المريض $pname فوّت الدواء: $label";
                if (!$alert) {
                    $ins->execute([$depId, $sid, $text, $nowStr, 'missed', $today]);
                } elseif ($alert['dose_response'] !== 'missed') {
                    $upd->execute([$text, 'missed', $nowStr, $alert['notification_id']]);
                }
            } elseif (!$alert) {
                $text = "تنبيه: المريض $pname فوّت الدواء ولم يرد على التنبيه: $label";
                $ins->execute([$depId, $sid, $text, $nowStr, null, $today]);
            }
        }
    }

    // التنبيهات غير المقروءة مع تفاصيل الدواء
    $st = $pdo->prepare("SELECT n.notification_id, n.notification_text, n.notification_time, n.dose_response,
            m.medication_name, ds.dose_amount, ds.dose_unit, ds.dosage_time
        FROM notifications n
        LEFT JOIN dosage_schedules ds ON ds.schedule_id = n.schedule_id
        LEFT JOIN medications m ON m.medication_id = ds.medication_id
        WHERE n.user_id = ? AND n.notification_type = 'dependent_alert' AND n.read_at IS NULL
        ORDER BY n.notification_time DESC LIMIT 20");
    $st->execute([$depId]);
    $out = [];
    foreach ($st->fetchAll() as $a) {
        $out[] = [
            'id'      => (int) $a['notification_id'],
            'med'     => $a['medication_name'] ?? '',
            'dose'    => $a['dose_amount'] !== null ? doseTxt($a['dose_amount'], $a['dose_unit']) : '',
            'dueTime' => $a['dosage_time'] ? t12(strtotime($a['dosage_time'])) : '',
            'text'    => $a['notification_text'],
            'time'    => t12(strtotime($a['notification_time'])),
            'kind'    => $a['dose_response'] === 'taken' ? 'late_taken' : ($a['dose_response'] === 'missed' ? 'missed' : 'noresp'),
        ];
    }
    echo json_encode(['ok' => true, 'patient' => $pname, 'alerts' => $out], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
