<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PASERAH_HACKATHON_SESSION');
    session_start();
}  include __DIR__ . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التنبيهات - بصيرة</title>
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
          </style>
</head>
<body>

<div class="dashboard-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>



</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
