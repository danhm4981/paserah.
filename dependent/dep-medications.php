<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}
date_default_timezone_set('Asia/Riyadh');
require_once __DIR__ . '/../db.php';

$current_user_id = $_SESSION['user_id'] ?? null;

if (!$current_user_id || ($_SESSION['account_type'] ?? '') !== 'dependent') {
    header("Location: ../login.php");
    exit;
}

$DURATION_OPTIONS = [
    'ongoing'      => 'مستمر (دائم)',
    '5'            => 'لمدة 5 أيام',
    '7'            => 'لمدة 7 أيام (أسبوع)',
    '10'           => 'لمدة 10 أيام',
    '14'           => 'لمدة 14 يوماً (أسبوعين)',
    '30'           => 'لمدة 30 يوماً (شهر)',
    'custom_dates' => 'تحديد تاريخ بداية ونهاية',
];

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function valid_time($t): bool
{
    return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?\z/', $t) === 1;
}

function detect_duration_key(?string $start_date, ?string $end_date): string
{
    if (empty($end_date)) {
        return 'ongoing';
    }
    if (!empty($start_date)) {
        $s = DateTime::createFromFormat('Y-m-d', $start_date);
        $e = DateTime::createFromFormat('Y-m-d', $end_date);
        if ($s && $e) {
            $days = (int) $s->diff($e)->days + 1;
            foreach (['5', '7', '10', '14', '30'] as $presetDays) {
                if ($days === (int) $presetDays) {
                    return $presetDays;
                }
            }
        }
    }
    return 'custom_dates';
}

function format_duration_label(?string $start_date, ?string $end_date): string
{
    if (empty($end_date)) {
        return 'مستمر (دائم)';
    }
    if (empty($start_date)) {
        return 'حتى ' . $end_date;
    }

    $start = DateTime::createFromFormat('Y-m-d', $start_date);
    $end = DateTime::createFromFormat('Y-m-d', $end_date);
    if (!$start || !$end) {
        return $start_date . ' إلى ' . $end_date;
    }

    $days = (int) $start->diff($end)->days + 1;
    $days_text = $days === 1 ? 'يوم واحد' : ($days === 2 ? 'يومان' : ($days <= 10 ? $days . ' أيام' : $days . ' يوماً'));
    return $days_text . ' (من ' . $start_date . ' إلى ' . $end_date . ')';
}

function format_doses_count_label(int $count): string
{
    if ($count <= 0) {
        return 'غير محدد';
    }
    if ($count === 1) {
        return 'مرة واحدة يومياً';
    }
    if ($count === 2) {
        return 'مرتان يومياً';
    }
    if ($count <= 10) {
        return $count . ' مرات يومياً';
    }
    return $count . ' مرة يومياً';
}

function build_times_for_count(int $count, array $existing_times = []): array
{
    $count = max(1, $count);
    $clean = [];
    foreach ($existing_times as $t) {
        $t = trim((string) $t);
        if ($t !== '') {
            $clean[] = (strlen($t) === 5) ? $t . ':00' : $t;
        }
    }

    if (count($clean) >= $count) {
        return array_slice($clean, 0, $count);
    }

    $base = !empty($clean)
        ? DateTime::createFromFormat('H:i:s', substr($clean[0], 0, 5) . ':00')
        : DateTime::createFromFormat('H:i:s', '08:00:00');
    if (!$base) {
        $base = new DateTime('08:00:00');
    }

    $step_minutes = (int) round((24 * 60) / $count);
    $result = [];
    for ($i = 0; $i < $count; $i++) {
        if (isset($clean[$i])) {
            $result[] = $clean[$i];
        } else {
            $dt = clone $base;
            $dt->modify('+' . ($i * $step_minutes) . ' minutes');
            $result[] = $dt->format('H:i:s');
        }
    }
    return $result;
}

$patient = null;
$patient_id = 0;
$link_status = null; // pending / rejected / null
$medications = [];
$dose_times = [];
$error = "";

try {
    // المريض المرتبط بالتابع (بعد موافقة المريض فقط)
    $stmt = $pdo->prepare("
        SELECT u.*
        FROM patient_dependents pd
        JOIN users u ON u.user_id = pd.patient_user_id
        WHERE pd.dependent_user_id = ?
          AND pd.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$current_user_id]);
    $patient = $stmt->fetch() ?: null;

    if (!$patient) {
        $stmt = $pdo->prepare("
            SELECT status
            FROM patient_dependents
            WHERE dependent_user_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$current_user_id]);
        $link_status = $stmt->fetchColumn() ?: null;
    }

    if ($patient) {
        // معرّف المريض يؤخذ دائماً من الربط في القاعدة وليس من النموذج
        $patient_id = (int) $patient['user_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            // حذف دواء المريض
            if (isset($_POST['delete_medicine'])) {
                $id = (int) ($_POST['medication_id'] ?? 0);

                $stmt_img = $pdo->prepare("SELECT medication_image FROM medications WHERE medication_id = ? AND user_id = ?");
                $stmt_img->execute([$id, $patient_id]);
                $img_row = $stmt_img->fetch();

                if ($img_row) {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM dosage_schedules WHERE medication_id = ?")->execute([$id]);
                    $stmt = $pdo->prepare("DELETE FROM medications WHERE medication_id = ? AND user_id = ?");
                    $stmt->execute([$id, $patient_id]);
                    $pdo->commit();

                    if (!empty($img_row['medication_image'])) {
                        @unlink(__DIR__ . '/../uploads/' . basename($img_row['medication_image']));
                    }
                }

                header("Location: dep-medications.php");
                exit;
            }

            // تعديل اسم الدواء وعدد الجرعات والمدة
            if (isset($_POST['update_medicine'])) {
                $id = (int) ($_POST['medication_id'] ?? 0);
                $name = trim($_POST['medication_name'] ?? '');
                $doses_count = (int) ($_POST['doses_count'] ?? 1);
                $duration_choice = trim($_POST['duration_choice'] ?? 'ongoing');

                $stmt_own = $pdo->prepare("SELECT medication_id FROM medications WHERE medication_id = ? AND user_id = ?");
                $stmt_own->execute([$id, $patient_id]);
                $owned = $stmt_own->fetch();

                if ($owned && $name !== '' && $doses_count >= 1 && isset($DURATION_OPTIONS[$duration_choice])) {
                    $start_date = date('Y-m-d');
                    $end_date = null;
                    $dates_valid = true;

                    if ($duration_choice === 'custom_dates') {
                        $start_date = trim($_POST['start_date'] ?? '');
                        $end_date = trim($_POST['end_date'] ?? '');
                        if (!valid_date($start_date) || !valid_date($end_date) || $end_date < $start_date) {
                            $dates_valid = false;
                            $error = "تاريخ البداية أو النهاية غير صحيح.";
                        }
                    } elseif ($duration_choice !== 'ongoing') {
                        $days = (int) $duration_choice;
                        $end_date = date('Y-m-d', strtotime('+' . max(0, $days - 1) . ' days'));
                    }

                    // أوقات الجرعات المرسلة من النموذج
                    $posted_times = array_values((array) ($_POST['dose_times'] ?? []));
                    if ($dates_valid && (count($posted_times) !== $doses_count
                        || count(array_filter($posted_times, 'valid_time')) !== $doses_count)) {
                        $dates_valid = false;
                        $error = "وقت الجرعة غير صحيح.";
                    }

                    if ($dates_valid) {
                        $stmt_old_times = $pdo->prepare("
                            SELECT schedule_id, dosage_time, dose_amount, dose_unit, status
                            FROM dosage_schedules
                            WHERE medication_id = ?
                            ORDER BY dosage_time ASC, schedule_id ASC
                        ");
                        $stmt_old_times->execute([$id]);
                        $old_rows = $stmt_old_times->fetchAll();

                        $new_times = build_times_for_count($doses_count, $posted_times);

                        $pdo->beginTransaction();

                        $stmt_upd_med = $pdo->prepare("
                            UPDATE medications
                            SET medication_name = ?
                            WHERE medication_id = ? AND user_id = ?
                        ");
                        $stmt_upd_med->execute([$name, $id, $patient_id]);

                        // تحديث الجرعة الموجودة بنفس رقمها حتى لا ينقطع ربطها بالتنبيهات
                        $stmt_upd_sched = $pdo->prepare("
                            UPDATE dosage_schedules
                            SET dosage_time = :dosage_time, start_date = :start_date, end_date = :end_date
                            WHERE schedule_id = :schedule_id AND medication_id = :medication_id
                        ");

                        // تعديل وقت التنبيهات غير المقروءة وغير المرسلة (مع بقاء تاريخها)
                        $stmt_upd_notif = $pdo->prepare("
                            UPDATE notifications
                            SET notification_time = TIMESTAMP(DATE(notification_time), :dosage_time)
                            WHERE schedule_id = :schedule_id
                              AND in_site_status = 'unread'
                              AND email_status <> 'sent'
                        ");

                        $stmt_ins_sched = $pdo->prepare("
                            INSERT INTO dosage_schedules
                                (medication_id, dose_amount, dose_unit, dosage_time, start_date, end_date, status)
                            VALUES
                                (:medication_id, :dose_amount, :dose_unit, :dosage_time, :start_date, :end_date, :status)
                        ");

                        foreach ($new_times as $idx => $time_val) {
                            $prev = $old_rows[$idx] ?? null;

                            if ($prev) {
                                $stmt_upd_sched->execute([
                                    'dosage_time'   => $time_val,
                                    'start_date'    => $start_date,
                                    'end_date'      => $end_date,
                                    'schedule_id'   => $prev['schedule_id'],
                                    'medication_id' => $id,
                                ]);
                                if (substr($prev['dosage_time'], 0, 5) !== substr($time_val, 0, 5)) {
                                    $stmt_upd_notif->execute([
                                        'dosage_time' => $time_val,
                                        'schedule_id' => $prev['schedule_id'],
                                    ]);
                                }
                                continue;
                            }

                            $stmt_ins_sched->execute([
                                'medication_id' => $id,
                                'dose_amount'   => $prev['dose_amount'] ?? 1,
                                'dose_unit'     => $prev['dose_unit'] ?? 'حبة',
                                'dosage_time'   => $time_val,
                                'start_date'    => $start_date,
                                'end_date'      => $end_date,
                                'status'        => $prev['status'] ?? 'pending',
                            ]);
                        }

                        // عند تقليل عدد الجرعات: حذف الجرعات الزائدة وتنبيهاتها المنتظرة
                        foreach (array_slice($old_rows, count($new_times)) as $extra) {
                            $pdo->prepare("
                                DELETE FROM notifications
                                WHERE schedule_id = ? AND in_site_status = 'unread' AND email_status <> 'sent'
                            ")->execute([$extra['schedule_id']]);
                            $pdo->prepare("DELETE FROM dosage_schedules WHERE schedule_id = ?")
                                ->execute([$extra['schedule_id']]);
                        }

                        $pdo->commit();
                        header("Location: dep-medications.php");
                        exit;
                    }
                }
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                m.medication_id,
                m.medication_name,
                m.medication_image,
                COUNT(s.schedule_id) AS doses_count,
                MIN(s.start_date) AS start_date,
                MAX(s.end_date) AS end_date
            FROM medications m
            LEFT JOIN dosage_schedules s ON s.medication_id = m.medication_id
            WHERE m.user_id = ?
            GROUP BY m.medication_id, m.medication_name, m.medication_image
            ORDER BY m.medication_id DESC
        ");
        $stmt->execute([$patient_id]);
        $medications = $stmt->fetchAll();

        $stmt_times = $pdo->prepare("
            SELECT s.medication_id, s.dosage_time
            FROM dosage_schedules s
            JOIN medications m ON m.medication_id = s.medication_id
            WHERE m.user_id = ?
            ORDER BY s.dosage_time ASC, s.schedule_id ASC
        ");
        $stmt_times->execute([$patient_id]);
        foreach ($stmt_times->fetchAll() as $row) {
            $dose_times[(int) $row['medication_id']][] = $row['dosage_time'];
        }
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    $error = "حدث خطأ أثناء معالجة البيانات، حاول مرة أخرى لاحقاً.";
}

$pat_name = $patient['username'] ?? ($patient['full_name'] ?? ($patient['name'] ?? ''));

include __DIR__ . '/../includes/header.php';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أدوية المريض | لوحة التابع</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        .dashboard-layout {
            display: flex;
            align-items: stretch;
            min-height: calc(100vh - 140px);
            width: 100%;
        }
        .main-content {
            flex-grow: 1;
            min-width: 0;
            padding: 40px;
        }
        .page-title {
            color: var(--primary-color, #008080);
            margin-bottom: 8px;
            font-size: 24px;
        }
        .page-sub {
            color: var(--text-muted, #557075);
            font-size: 14px;
            margin-bottom: 25px;
        }
        .meds-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }
        .med-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            overflow-wrap: anywhere;
            display: flex;
            flex-direction: column;
        }
        .med-thumb {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 14px;
            border: 1px solid #e6efef;
            background: #f8fcfc;
        }
        .med-card h3 {
            color: var(--text-color, #2b3e42);
            margin-bottom: 12px;
            font-size: 19px;
        }
        .med-info {
            color: var(--text-muted, #557075);
            font-size: 14px;
            margin-bottom: 8px;
            line-height: 1.6;
        }
        .med-info strong {
            color: var(--primary-color, #008080);
        }
        .empty-notice {
            background: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            color: var(--text-muted, #557075);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            grid-column: 1 / -1;
        }
        .med-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: auto;
            padding-top: 16px;
        }
        .med-actions button,
        .edit-form button {
            padding: 8px 16px;
            border-radius: 10px;
            border: 1.5px solid var(--primary-color, #008080);
            background: #e9f8f8;
            color: var(--primary-color, #008080);
            font-family: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .med-actions button[name="delete_medicine"] {
            border-color: #d9534f;
            background: #fdf2f2;
            color: #d9534f;
        }
        .edit-form {
            display: none;
            margin-top: 15px;
            padding-top: 14px;
            border-top: 1px dashed #d5e5e5;
        }
        .edit-form.active {
            display: block;
        }
        .edit-field {
            margin-bottom: 10px;
        }
        .edit-field label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #2b3e42;
            margin-bottom: 4px;
        }
        .edit-form input,
        .edit-form select {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid var(--border-color, #d5e0e2);
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            background: #fff;
        }
        .times-box input {
            margin-bottom: 6px;
        }
        .custom-dates-box {
            display: none;
            margin-bottom: 10px;
            padding: 10px;
            background: #f4fafa;
            border-radius: 10px;
        }
        .custom-dates-box.active {
            display: block;
        }

        @media (max-width: 900px) {
            .meds-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 600px) {
            .meds-grid {
                grid-template-columns: 1fr;
            }
            .main-content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/dep-sidebar.php'; ?>
        <main class="main-content">
            <h1 class="page-title">أدوية المريض</h1>
            <p class="page-sub">
                <?php if ($patient): ?>
                    المريض: <?= htmlspecialchars($pat_name, ENT_QUOTES, 'UTF-8') ?> · عرض الأدوية مع عدد الجرعات والمدة
                <?php else: ?>
                    عرض الأدوية مع عدد الجرعات والمدة
                <?php endif; ?>
            </p>

            <div class="meds-grid">
                <?php if ($error): ?>
                    <div class="empty-notice"><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></div>

                <?php elseif (!$patient && $link_status === 'pending'): ?>
                    <div class="empty-notice"><p>طلب الربط بانتظار موافقة المريض. ستظهر الأدوية بعد القبول.</p></div>

                <?php elseif (!$patient && $link_status === 'rejected'): ?>
                    <div class="empty-notice"><p>تم رفض طلب الربط من قبل المريض.</p></div>

                <?php elseif (!$patient): ?>
                    <div class="empty-notice"><p>حسابك غير مرتبط بمريض حتى الآن.</p></div>

                <?php elseif (!empty($medications)): ?>
                    <?php foreach ($medications as $med): ?>
                        <?php
                            $med_id = (int) $med['medication_id'];
                            $img_file = !empty($med['medication_image']) ? basename($med['medication_image']) : '';
                            $img_path = ($img_file !== '' && file_exists(__DIR__ . '/../uploads/' . $img_file))
                                ? '../uploads/' . rawurlencode($img_file)
                                : '';
                            $current_doses = max(1, (int) ($med['doses_count'] ?? 1));
                            $current_dur_key = detect_duration_key($med['start_date'] ?? null, $med['end_date'] ?? null);
                        ?>
                        <div class="med-card">
                            <?php if ($img_path !== ''): ?>
                                <img src="<?= htmlspecialchars($img_path, ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($med['medication_name'], ENT_QUOTES, 'UTF-8') ?>"
                                     class="med-thumb">
                            <?php endif; ?>

                            <h3>💊 <?= htmlspecialchars($med['medication_name'], ENT_QUOTES, 'UTF-8') ?></h3>

                            <p class="med-info"><strong>عدد الجرعات:</strong> <?= htmlspecialchars(format_doses_count_label((int) $med['doses_count']), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="med-info"><strong>المدة:</strong> <?= htmlspecialchars(format_duration_label($med['start_date'] ?? null, $med['end_date'] ?? null), ENT_QUOTES, 'UTF-8') ?></p>

                            <div class="med-actions">
                                <button type="button" onclick="toggleEdit(<?= $med_id ?>)">تعديل</button>

                                <form method="POST" onsubmit="return confirm('متأكد من الحذف؟');">
                                    <input type="hidden" name="medication_id" value="<?= $med_id ?>">
                                    <button type="submit" name="delete_medicine">حذف</button>
                                </form>
                            </div>

                            <form method="POST" class="edit-form" id="edit-<?= $med_id ?>">
                                <input type="hidden" name="medication_id" value="<?= $med_id ?>">

                                <div class="edit-field">
                                    <label for="name-<?= $med_id ?>">اسم الدواء</label>
                                    <input type="text" id="name-<?= $med_id ?>" name="medication_name"
                                           value="<?= htmlspecialchars($med['medication_name'], ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="اسم الدواء" required>
                                </div>

                                <div class="edit-field">
                                    <label for="doses-<?= $med_id ?>">عدد الجرعات يومياً</label>
                                    <input type="number" id="doses-<?= $med_id ?>" name="doses_count"
                                           min="1" max="<?= max(24, $current_doses) ?>" step="1" value="<?= $current_doses ?>"
                                           oninput="syncTimeInputs(<?= $med_id ?>)" required>
                                </div>

                                <div class="edit-field">
                                    <label>وقت الجرعات</label>
                                    <div class="times-box" id="times-<?= $med_id ?>">
                                        <?php foreach (build_times_for_count($current_doses, $dose_times[$med_id] ?? []) as $t_idx => $t_val): ?>
                                            <input type="time" name="dose_times[]"
                                                   value="<?= htmlspecialchars(substr($t_val, 0, 5), ENT_QUOTES, 'UTF-8') ?>"
                                                   aria-label="وقت الجرعة <?= $t_idx + 1 ?>" required>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="edit-field">
                                    <label for="dur-<?= $med_id ?>">المدة</label>
                                    <select id="dur-<?= $med_id ?>" name="duration_choice"
                                            onchange="toggleCustomDates(<?= $med_id ?>)">
                                        <?php foreach ($DURATION_OPTIONS as $dKey => $dLabel): ?>
                                            <option value="<?= htmlspecialchars($dKey, ENT_QUOTES, 'UTF-8') ?>"
                                                <?= $current_dur_key === $dKey ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($dLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="custom-dates-box <?= $current_dur_key === 'custom_dates' ? 'active' : '' ?>"
                                     id="dates-<?= $med_id ?>">
                                    <div class="edit-field">
                                        <label for="start-<?= $med_id ?>">من تاريخ</label>
                                        <input type="date" id="start-<?= $med_id ?>" name="start_date"
                                               value="<?= htmlspecialchars($med['start_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="edit-field">
                                        <label for="end-<?= $med_id ?>">إلى تاريخ</label>
                                        <input type="date" id="end-<?= $med_id ?>" name="end_date"
                                               value="<?= htmlspecialchars($med['end_date'] ?? date('Y-m-d', strtotime('+7 days')), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>

                                <button type="submit" name="update_medicine">حفظ التعديل</button>
                            </form>
                        </div>
                    <?php endforeach; ?>

                <?php else: ?>
                    <div class="empty-notice">
                        <p>لا توجد أدوية مضافة للمريض حتى الآن.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
    <script>
        function toggleEdit(id) {
            const form = document.getElementById('edit-' + id);
            form.classList.toggle('active');
        }
        // إظهار خانة وقت لكل جرعة حسب عدد الجرعات
        function syncTimeInputs(id) {
            const countInput = document.getElementById('doses-' + id);
            const box = document.getElementById('times-' + id);
            const count = Number(countInput.value);
            if (!Number.isInteger(count) || count < 1 || count > Number(countInput.max)) return;

            const inputs = box.querySelectorAll('input');
            const [h, m] = (inputs[0].value || '08:00').split(':').map(Number);
            const step = Math.round(1440 / count);

            for (let i = inputs.length; i < count; i++) {
                const total = (h * 60 + m + i * step) % 1440;
                const input = inputs[0].cloneNode();
                input.value = String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
                input.setAttribute('aria-label', 'وقت الجرعة ' + (i + 1));
                box.appendChild(input);
            }

            box.querySelectorAll('input').forEach((input, i) => {
                input.hidden = input.disabled = i >= count;
            });
        }
        function toggleCustomDates(id) {
            const sel = document.getElementById('dur-' + id);
            const box = document.getElementById('dates-' + id);
            if (!sel || !box) return;
            if (sel.value === 'custom_dates') {
                box.classList.add('active');
            } else {
                box.classList.remove('active');
            }
        }
    </script>
</body>
</html>
