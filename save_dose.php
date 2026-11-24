<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}
date_default_timezone_set('Asia/Riyadh');
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }

$in = json_decode(file_get_contents('php://input'), true);
$scheduleId = (int)($in['schedule_id'] ?? 0);
$resp = $in['response'] ?? '';
if (!$scheduleId || !in_array($resp, ['taken','missed'], true)) {
    http_response_code(400); echo json_encode(['ok'=>false]); exit;
}

// التأكد أن الجرعة تخص المستخدم الحالي
$st = $pdo->prepare("SELECT m.medication_name
    FROM dosage_schedules s
    JOIN medications m ON m.medication_id = s.medication_id
    WHERE s.schedule_id = ? AND m.user_id = ?");
$st->execute([$scheduleId, $userId]);
$med = $st->fetch();
if (!$med) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }

// منع التكرار في نفس اليوم
$today = date('Y-m-d');
$chk = $pdo->prepare("SELECT notification_id FROM notifications
    WHERE user_id = ? AND schedule_id = ? AND DATE(notification_time) = ? AND dose_response IS NOT NULL");
$chk->execute([$userId, $scheduleId, $today]);
if ($chk->fetch()) { echo json_encode(['ok'=>true,'duplicate'=>true]); exit; }

$text = ($resp === 'taken' ? 'تم تناول جرعة ' : 'لم يتم تناول جرعة ') . $med['medication_name'];
$now  = date('Y-m-d H:i:s');

$ins = $pdo->prepare("INSERT INTO notifications
    (user_id, schedule_id, notification_type, notification_text, notification_time, dose_response, read_at)
    VALUES (?, ?, 'dose_reminder', ?, ?, ?, ?)");
$ins->execute([$userId, $scheduleId, $text, $now, $resp, $now]);

echo json_encode(['ok'=>true]);