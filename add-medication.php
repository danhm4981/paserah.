<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}

date_default_timezone_set('Asia/Riyadh');
require_once __DIR__ . '/db.php';

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) {
    header("Location: login.php");
    exit;
}
$user_role = strtolower(trim($_SESSION['account_type'] ?? $_SESSION['role'] ?? 'patient'));
// التابع يضيف الدواء لحساب المريض المرتبط به
if ($user_role === 'dependent') {
    $stmt_p = $pdo->prepare("
        SELECT patient_user_id
        FROM patient_dependents
        WHERE dependent_user_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt_p->execute([$current_user_id]);
    $relation = $stmt_p->fetch();

    $target_user_id = $relation['patient_user_id'] ?? $current_user_id;
} else {
    $target_user_id = $current_user_id;
}

$SCHEDULE_OPTIONS = [
    'once' => [
        'label' => 'مرة واحدة',
        'doses' => 1,
        'interval_hours' => 0,
        'default_start' => '08:00',
        'note' => 'تمت جدولتها تلقائياً لجرعة واحدة، ويمكنك تخصيص الوقت.',
    ],
    'twice' => [
        'label' => 'مرتين',
        'doses' => 2,
        'interval_hours' => 12,
        'default_start' => '08:00',
        'note' => 'تمت جدولتها تلقائياً على كل 12 ساعة، ويمكنك تخصيص الوقت.',
    ],
    'thrice' => [
        'label' => 'ثلاث مرات',
        'doses' => 3,
        'interval_hours' => 8,
        'default_start' => '08:00',
        'note' => 'تمت جدولتها تلقائياً على كل 8 ساعات، ويمكنك تخصيص الوقت.',
    ],
    'custom' => [
        'label' => 'مخصص',
        'doses' => null,
        'interval_hours' => null,
        'default_start' => '08:00',
        'note' => 'خصّص عدد الجرعات والتوقيت كما يناسبك.',
    ],
];

$DURATION_OPTIONS = [
    'ongoing'      => 'مستمر (دائم)',
    '5'            => 'لمدة 5 أيام',
    '7'            => 'لمدة 7 أيام (أسبوع)',
    '10'           => 'لمدة 10 أيام',
    '14'           => 'لمدة 14 يوماً (أسبوعين)',
    '30'           => 'لمدة 30 يوماً (شهر)',
    'custom_dates' => 'تحديد تاريخ بداية ونهاية',
];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function valid_time(string $t): bool
{
    return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $t);
}

function normalize_time(string $t): string
{
    $t = trim($t);
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $t)) {
        return $t . ':00';
    }
    return $t;
}

function calculate_schedule_times(string $start_time, int $doses_count, float $interval_value, string $unit): array
{
    $dt = DateTime::createFromFormat('H:i', substr($start_time, 0, 5));
    if (!$dt) {
        return [];
    }

    $minutes_step = ($unit === 'minutes')
        ? (int) round($interval_value)
        : (int) round($interval_value * 60);

    $times = [];
    for ($i = 0; $i < $doses_count; $i++) {
        $current = clone $dt;
        if ($i > 0 && $minutes_step > 0) {
            $current->modify('+' . ($i * $minutes_step) . ' minutes');
        }
        $times[] = $current->format('H:i');
    }

    return $times;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $medication_name = trim($_POST['medication_name'] ?? '');
    $schedule_choice = trim($_POST['schedule_choice'] ?? 'once');
    $duration_choice = trim($_POST['duration_choice'] ?? 'ongoing');
    $notes = trim($_POST['notes'] ?? '');
    $posted_times = $_POST['dose_times'] ?? [];

    $custom_count = (int) ($_POST['custom_doses_count'] ?? 1);
    $custom_start = trim($_POST['custom_first_time'] ?? '08:00');
    $custom_interval = (float) ($_POST['custom_interval_value'] ?? 1);
    $custom_unit = ($_POST['custom_interval_unit'] ?? 'hours') === 'minutes' ? 'minutes' : 'hours';

    $start_date = date('Y-m-d');
    $end_date = null;

    if (!isset($DURATION_OPTIONS[$duration_choice])) {
        $error = "يرجى اختيار مدة استخدام الدواء";
    } elseif ($duration_choice === 'custom_dates') {
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        if (!valid_date($start_date) || !valid_date($end_date)) {
            $error = "يرجى تحديد تاريخ بداية ونهاية صحيحين";
        } elseif ($end_date < $start_date) {
            $error = "تاريخ النهاية يجب أن يكون بعد تاريخ البداية";
        }
    } elseif ($duration_choice !== 'ongoing') {
        $days = (int) $duration_choice;
        $end_date = date('Y-m-d', strtotime('+' . max(0, $days - 1) . ' days'));
    }

    $final_times = [];

    if ($error === '') {
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            $error = "انتهت صلاحية الصفحة، أعد تحميلها وحاول مرة أخرى";
        } elseif ($medication_name === '') {
            $error = "يرجى إدخال اسم الدواء";
        } elseif (mb_strlen($medication_name) > 100) {
            $error = "اسم الدواء طويل (الحد الأقصى 100 حرف)";
        } elseif (!isset($SCHEDULE_OPTIONS[$schedule_choice])) {
            $error = "يرجى اختيار نظام الجرعات";
        } else {
            if (is_array($posted_times) && count($posted_times) > 0) {
                foreach ($posted_times as $pt) {
                    $pt = trim((string) $pt);
                    if ($pt === '') {
                        continue;
                    }
                    if (!valid_time($pt)) {
                        $error = "أحد أوقات الجرعات غير صحيح";
                        break;
                    }
                    $final_times[] = normalize_time($pt);
                }
            }

            if ($error === '' && empty($final_times)) {
                if ($schedule_choice === 'custom') {
                    if ($custom_count < 1) {
                        $error = "يرجى إدخال عدد جرعات صحيح";
                    } elseif (!valid_time($custom_start)) {
                        $error = "وقت البداية غير صحيح";
                    } elseif ($custom_count > 1 && $custom_interval <= 0) {
                        $error = "يرجى تحديد الفترة الزمنية للتكرار";
                    } else {
                        $calc = calculate_schedule_times($custom_start, $custom_count, $custom_interval, $custom_unit);
                        foreach ($calc as $c) {
                            $final_times[] = normalize_time($c);
                        }
                    }
                } else {
                    $opt = $SCHEDULE_OPTIONS[$schedule_choice];
                    $calc = calculate_schedule_times($opt['default_start'], (int) $opt['doses'], (float) $opt['interval_hours'], 'hours');
                    foreach ($calc as $c) {
                        $final_times[] = normalize_time($c);
                    }
                }
            }

            if ($error === '' && empty($final_times)) {
                $error = "يرجى تحديد وقت جرعة واحد على الأقل";
            }
        }
    }

    if ($error === '') {
        $image_name = '';
        $file = $_FILES['medication_image'] ?? null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "تعذّر رفع الصورة، جرّب صورة أصغر";
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error = "حجم الصورة كبير (الحد الأقصى 5 ميغابايت)";
            } else {
                $info = @getimagesize($file['tmp_name']);
                $exts = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                ];
                $ext = $exts[$info['mime'] ?? ''] ?? null;
                if (!$ext) {
                    $error = "صيغة الصورة غير مدعومة (JPG أو PNG أو WEBP أو GIF)";
                } else {
                    $upload_dir = __DIR__ . '/uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $image_name = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $image_name)) {
                        $image_name = '';
                        $error = "تعذّر حفظ الصورة على الخادم";
                    }
                }
            }
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO medications (user_id, medication_name, medication_image, medication_description)
                VALUES (:user_id, :medication_name, :medication_image, :medication_description)
            ");
            $stmt->execute([
                'user_id' => $target_user_id,
                'medication_name' => $medication_name,
                'medication_image' => $image_name !== '' ? $image_name : null,
                'medication_description' => $notes !== '' ? $notes : null,
            ]);
            $medication_id = (int) $pdo->lastInsertId();

            $stmt2 = $pdo->prepare("
                INSERT INTO dosage_schedules
                    (medication_id, dose_amount, dose_unit, dosage_time, start_date, end_date, status)
                VALUES
                    (:medication_id, 1, 'حبة', :dosage_time, :start_date, :end_date, 'pending')
            ");

            foreach ($final_times as $t) {
                $stmt2->execute([
                    'medication_id' => $medication_id,
                    'dosage_time' => $t,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                ]);
            }

            $pdo->commit();
            $message = "✅ تم حفظ الدواء ومواعيد جرعاته ومدته بنجاح";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($image_name !== '') {
                @unlink(__DIR__ . '/uploads/' . $image_name);
            }
            error_log($e->getMessage());
            $error = "تعذّر حفظ الدواء، حاول مرة أخرى";
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إضافة دواء جديد | بصيرة</title>
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

  .container {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 40px;
  }

  .card {
    width: 720px;
    max-width: 100%;
    background: #fff;
    border-radius: 20px;
    padding: 35px;
    box-shadow: 0 10px 25px rgba(0,0,0,.05);
  }

  .page-header {
    background: #fff;
    border-radius: 20px;
    padding: 25px;
    text-align: center;
    margin-bottom: 25px;
    box-shadow: 0 8px 20px rgba(0,0,0,.08);
  }

  .page-title {
    text-align: center;
    color: var(--primary-color, #008080);
    margin: 0;
  }

  .success-message {
    background: #d4edda;
    color: #155724;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: bold;
  }

  .success-message a {
    color: #155724;
    text-decoration: underline;
  }

  .error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: bold;
  }

  .form-group {
    margin-bottom: 22px;
  }

  label.field-label {
    display: inline-block;
    background: #e9f8f8;
    color: #008080;
    padding: 7px 14px;
    border-radius: 10px;
    font-weight: 700;
    margin-bottom: 10px;
  }

  input[type="text"],
  input[type="number"],
  input[type="file"],
  input[type="time"],
  input[type="date"],
  select,
  textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 10px;
    font-family: inherit;
    font-size: 15px;
  }

  select {
    height: 50px;
    background-color: #fff;
  }

  textarea {
    min-height: 105px;
    resize: vertical;
  }

  input:focus-visible,
  select:focus-visible,
  textarea:focus-visible {
    outline: 3px solid #008080;
    outline-offset: 2px;
  }

  .auto-note {
    margin-top: 10px;
    font-size: 14px;
    font-weight: 600;
    color: #006666;
    background: #f2fafa;
    padding: 9px 13px;
    border-radius: 10px;
    border-right: 3px solid #008080;
  }

  .sub-panel {
    display: none;
    margin-top: 14px;
    padding: 16px;
    border-radius: 14px;
    background: #f4fafa;
    border: 1px dashed #9fd0d0;
  }

  .sub-panel.active {
    display: block;
  }

  .custom-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
  }

  .dates-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
  }

  .custom-row label,
  .dates-row label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #2b3e42;
    margin-bottom: 6px;
  }

  .interval-wrap {
    display: flex;
    gap: 6px;
  }

  .interval-wrap select {
    width: 96px;
    flex-shrink: 0;
  }

  .times-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-top: 14px;
  }

  .time-item {
    background: #f8fcfc;
    border: 1px solid #e0ecec;
    border-radius: 12px;
    padding: 12px;
  }

  .time-item span {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-color, #2b3e42);
    margin-bottom: 6px;
  }

  #imgPreview {
    display: none;
    margin-top: 10px;
    max-width: 140px;
    max-height: 140px;
    border-radius: 12px;
    border: 1px solid #ddd;
    object-fit: cover;
  }

  .actions {
    margin-top: 24px;
    display: flex;
    gap: 12px;
  }

  .save-btn {
    flex: 1;
    background: var(--primary-color, #008080);
    color: white;
    border: none;
    border-radius: 10px;
    padding: 13px;
    cursor: pointer;
    font-family: inherit;
    font-weight: 700;
  }

  .cancel-btn {
    flex: 1;
    background: white;
    color: var(--primary-color, #008080);
    border: 2px solid var(--primary-color, #008080);
    border-radius: 10px;
    padding: 13px;
    cursor: pointer;
    font-family: inherit;
    font-weight: 700;
  }

  @media (max-width: 768px) {
    .custom-row,
    .dates-row,
    .times-grid {
      grid-template-columns: 1fr;
    }
    .container {
      padding: 20px;
    }
  }
</style>
</head>
<body>
<div class="dashboard-layout">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="container">
        <div class="card">

            <div class="page-header">
                <h1 class="page-title">إضافة دواء جديد</h1>
            </div>

            <?php if (!empty($message)): ?>
                <div class="success-message">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    <br><a href="notifications.php">الذهاب إلى التنبيهات</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="medication_name" class="field-label">اسم الدواء</label>
                    <input
                        type="text"
                        id="medication_name"
                        name="medication_name"
                        maxlength="100"
                        required
                        placeholder="مثال: أموكسيسيلين">
                </div>

                <div class="form-group">
                    <label for="medication_image" class="field-label">صورة الدواء</label>
                    <input type="file" id="medication_image" name="medication_image" accept="image/*">
                    <img id="imgPreview" alt="معاينة صورة الدواء">
                </div>

                <div class="form-group">
                    <label for="schedule_choice" class="field-label">الجرعات والتوقيت</label>
                    <select id="schedule_choice" name="schedule_choice" required>
                        <?php foreach ($SCHEDULE_OPTIONS as $key => $opt): ?>
                            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="autoScheduleNote" class="auto-note">
                        تمت جدولتها تلقائياً لجرعة واحدة، ويمكنك تخصيص الوقت.
                    </div>

                    <div id="customPanel" class="sub-panel">
                        <div class="custom-row">
                            <div>
                                <label for="custom_doses_count">عدد الجرعات</label>
                                <input type="number" id="custom_doses_count" name="custom_doses_count" min="1" step="1" value="2">
                            </div>
                            <div>
                                <label for="custom_first_time">أول جرعة</label>
                                <input type="time" id="custom_first_time" name="custom_first_time" value="08:00">
                            </div>
                            <div>
                                <label for="custom_interval_value">كل كم؟</label>
                                <div class="interval-wrap">
                                    <input type="number" id="custom_interval_value" name="custom_interval_value" min="1" step="any" value="1">
                                    <select id="custom_interval_unit" name="custom_interval_unit">
                                        <option value="hours" selected>ساعة</option>
                                        <option value="minutes">دقيقة</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="doseTimesContainer" class="times-grid"></div>
                </div>

                <div class="form-group">
                    <label for="duration_choice" class="field-label">مدة استخدام الدواء</label>
                    <select id="duration_choice" name="duration_choice" required>
                        <?php foreach ($DURATION_OPTIONS as $dKey => $dLabel): ?>
                            <option value="<?= htmlspecialchars($dKey, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($dLabel, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="customDatesPanel" class="sub-panel">
                        <div class="dates-row">
                            <div>
                                <label for="start_date">من تاريخ</label>
                                <input type="date" id="start_date" name="start_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div>
                                <label for="end_date">إلى تاريخ</label>
                                <input type="date" id="end_date" name="end_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes" class="field-label">ملاحظات للدواء</label>
                    <textarea
                        id="notes"
                        name="notes"
                        placeholder="مثال: يُؤخذ بعد الأكل أو مع كوب ماء كامل"></textarea>
                </div>

                <div class="actions">
                    <button type="submit" class="save-btn">حفظ الدواء</button>
                    <button type="reset" id="resetBtn" class="cancel-btn">إلغاء</button>
                </div>
            </form>

        </div>
    </div>

</div>

<script>
(function () {
    var OPTIONS = <?= json_encode($SCHEDULE_OPTIONS, JSON_UNESCAPED_UNICODE) ?>;
    var scheduleSelect = document.getElementById('schedule_choice');
    var autoScheduleNote = document.getElementById('autoScheduleNote');

    var customPanel = document.getElementById('customPanel');
    var customCount = document.getElementById('custom_doses_count');
    var customStart = document.getElementById('custom_first_time');
    var customInterval = document.getElementById('custom_interval_value');
    var customUnit = document.getElementById('custom_interval_unit');
    var timesContainer = document.getElementById('doseTimesContainer');

    var durationSelect = document.getElementById('duration_choice');
    var customDatesPanel = document.getElementById('customDatesPanel');
    var startDateInput = document.getElementById('start_date');
    var endDateInput = document.getElementById('end_date');

    var fileInput = document.getElementById('medication_image');
    var preview = document.getElementById('imgPreview');
    var resetBtn = document.getElementById('resetBtn');

    function formatTime24(dateObj) {
        var h = String(dateObj.getHours()).padStart(2, '0');
        var m = String(dateObj.getMinutes()).padStart(2, '0');
        return h + ':' + m;
    }

    function formatTime12Arabic(hhmm) {
        var parts = hhmm.split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1];
        var period = h < 12 ? 'ص' : 'م';
        var h12 = h % 12 || 12;
        return h12 + ':' + m + ' ' + period;
    }

    function generateTimes(startHHMM, count, stepMinutes) {
        var parts = (startHHMM || '08:00').split(':');
        var base = new Date(2026, 0, 1, parseInt(parts[0], 10) || 8, parseInt(parts[1], 10) || 0, 0);
        var result = [];
        for (var i = 0; i < count; i++) {
            var d = new Date(base.getTime() + (i * stepMinutes * 60000));
            result.push(formatTime24(d));
        }
        return result;
    }

    function buildTimesList() {
        var choice = scheduleSelect.value;
        var opt = OPTIONS[choice] || OPTIONS.once;
        var times = [];

        if (choice === 'custom') {
            autoScheduleNote.style.display = 'none';
            customPanel.classList.add('active');
            var count = Math.max(1, parseInt(customCount.value, 10) || 1);
            var intervalVal = Math.max(0, parseFloat(customInterval.value) || 0);
            var stepMinutes = (customUnit.value === 'minutes')
                ? Math.round(intervalVal)
                : Math.round(intervalVal * 60);
            times = generateTimes(customStart.value, count, stepMinutes);
        } else {
            autoScheduleNote.style.display = 'block';
            autoScheduleNote.textContent = opt.note;
            customPanel.classList.remove('active');
            times = generateTimes(
                opt.default_start || '08:00',
                Number(opt.doses) || 1,
                (Number(opt.interval_hours) || 0) * 60
            );
        }

        timesContainer.innerHTML = '';
        times.forEach(function (t, idx) {
            var box = document.createElement('div');
            box.className = 'time-item';

            var title = document.createElement('span');
            title.textContent = 'الجرعة ' + (idx + 1) + ' (' + formatTime12Arabic(t) + ')';

            var input = document.createElement('input');
            input.type = 'time';
            input.name = 'dose_times[]';
            input.value = t;
            input.required = true;

            input.addEventListener('change', function () {
                if (input.value) {
                    title.textContent = 'الجرعة ' + (idx + 1) + ' (' + formatTime12Arabic(input.value) + ')';
                }
            });

            box.appendChild(title);
            box.appendChild(input);
            timesContainer.appendChild(box);
        });
    }

    function toggleCustomDates() {
        if (durationSelect.value === 'custom_dates') {
            customDatesPanel.classList.add('active');
        } else {
            customDatesPanel.classList.remove('active');
        }
    }

    scheduleSelect.addEventListener('change', buildTimesList);
    durationSelect.addEventListener('change', toggleCustomDates);

    startDateInput.addEventListener('change', function () {
        endDateInput.min = startDateInput.value;
        if (endDateInput.value && endDateInput.value < startDateInput.value) {
            endDateInput.value = startDateInput.value;
        }
    });

    [customCount, customStart, customInterval, customUnit].forEach(function (el) {
        el.addEventListener('input', buildTimesList);
        el.addEventListener('change', buildTimesList);
    });

    fileInput.addEventListener('change', function () {
        var f = fileInput.files[0];
        if (!f) {
            preview.style.display = 'none';
            return;
        }
        preview.src = URL.createObjectURL(f);
        preview.style.display = 'block';
    });

    resetBtn.addEventListener('click', function () {
        preview.style.display = 'none';
        setTimeout(function () {
            buildTimesList();
            toggleCustomDates();
        }, 0);
    });

    buildTimesList();
    toggleCustomDates();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
