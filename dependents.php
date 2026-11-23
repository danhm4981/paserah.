<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}

require_once __DIR__ . '/db.php';

$patient_id = (int) ($_SESSION['user_id'] ?? 1);

$message = "";
$error = "";

// 1. معالجة قبول أو رفض طلبات الربط الواردة من التابعين
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_action'])) {
    $request_id = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['link_action'];

    if (in_array($action, ['active', 'rejected'], true)) {
        $stmt = $pdo->prepare("
            UPDATE patient_dependents
            SET status = ?
            WHERE id = ?
              AND patient_user_id = ?
              AND status = 'pending'
        ");
        $stmt->execute([$action, $request_id, $patient_id]);

        if ($stmt->rowCount() > 0) {
            $message = ($action === 'active') ? 'تم قبول طلب الربط بنجاح.' : 'تم رفض طلب الربط.';
        } else {
            $error = 'تعذر تحديث طلب الربط.';
        }
    }
}

// 2. جلب طلبات الربط المعلقة (الواردة)
$stmt_req = $pdo->prepare("
    SELECT pd.id, pd.relationship, u.username, u.email
    FROM patient_dependents pd
    JOIN users u ON u.user_id = pd.dependent_user_id
    WHERE pd.patient_user_id = ?
      AND pd.status = 'pending'
");
$stmt_req->execute([$patient_id]);
$link_requests = $stmt_req->fetchAll();

// 3. جلب قائمة التوابع المربوطين
$query = "SELECT pd.id, pd.relationship, pd.status, u.username, u.email, u.phone 
          FROM patient_dependents pd 
          JOIN users u ON pd.dependent_user_id = u.user_id 
          WHERE pd.patient_user_id = ?";
$stmt_list = $pdo->prepare($query);
$stmt_list->execute([$patient_id]);
$dependents = $stmt_list->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة التوابع - بصيرة</title>
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
            max-width: 1000px;
            width: 100%;
            margin: 40px auto;
            padding: 0 20px;
        }
        .card {
            background: var(--white, #fff);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }
        .card h2 {
            color: var(--primary-color, #008080);
            margin-bottom: 20px;
            font-size: 22px;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .request-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .request-item:last-child {
            border-bottom: none;
        }
        .btn-accept {
            background: #008080;
            color: white;
            padding: 9px 18px;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-weight: 600;
        }
        .btn-reject {
            background: #b42318;
            color: white;
            padding: 9px 18px;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        table th, table td {
            padding: 12px 15px;
            text-align: right;
            border-bottom: 1px solid #eee;
        }
        table th {
            background-color: var(--bg-light, #f4f7f7);
            color: var(--primary-color, #008080);
        }
        .badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 13px;
            font-weight: 600;
        }
        .badge-active { background-color: #e6f4ea; color: #1e7e34; }
        .badge-pending { background-color: #fff3cd; color: #856404; }
        .badge-rejected { background-color: #fde8e8; color: #b42318; }
    </style>
</head>
<body>

<div class="dashboard-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div class="container">

        <?php if ($message): ?>
            <div class="alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <!-- قسم طلبات ربط التابعين -->
        <div class="card">
            <h2>طلبات ربط التابعين</h2>
            <?php if (empty($link_requests)): ?>
                <p style="color: var(--text-muted, #667);">لا توجد طلبات ربط جديدة.</p>
            <?php else: ?>
                <?php foreach ($link_requests as $request): ?>
                    <div class="request-item">
                        <div>
                            <p style="margin-bottom: 4px; font-weight: 700;">
                                التابع: <?= htmlspecialchars($request['username'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <p style="font-size: 14px; color: var(--text-muted, #667);">
                                البريد الإلكتروني: <?= htmlspecialchars($request['email'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($request['relationship'])): ?>
                                    — صلة القرابة: <?= htmlspecialchars($request['relationship'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <form method="POST" style="display:flex;gap:10px;">
                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                            <button type="submit" name="link_action" value="active" class="btn-accept">قبول</button>
                            <button type="submit" name="link_action" value="rejected" class="btn-reject">رفض</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- جدول التوابع المربوطين -->
        <div class="card">
            <h2>قائمة التوابع المربوطين</h2>
            <table>
                <thead>
                    <tr>
                        <th>اسم التابع</th>
                        <th>البريد الإلكتروني</th>
                        <th>صلة القرابة</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($dependents) > 0): ?>
                        <?php foreach ($dependents as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo htmlspecialchars($row['relationship']); ?></td>
                                <td>
                                    <?php if ($row['status'] == 'active'): ?>
                                        <span class="badge badge-active">نشط</span>
                                    <?php elseif ($row['status'] == 'rejected'): ?>
                                        <span class="badge badge-rejected">مرفوض</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">قيد الانتظار</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center;">لا يوجد توابع مرتبطون حالياً.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
