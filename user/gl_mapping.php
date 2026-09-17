<?php
// comparative_report_original_page_three.php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

$username  = $_SESSION['username'] ?? 'unknown';
$full_name = $_SESSION['full_name'] ?? 'unknown';
$user_type = $_SESSION['user_type'] ?? 'unknown';


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GL Mapping</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/gl_mapping.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

</head>
<body>
<main class="main-content">
    <header class="top-bar">
        <h2><a href="user_dashboard.php" style="font-size:16px;text-decoration:none;">⬅ Back</a></h2>
        <div class="user-badge">
            <span><?= htmlspecialchars($username); ?> (<?= htmlspecialchars($user_type); ?>)</span>
            <div class="avatar"><?= strtoupper(substr($full_name, 0, 1)); ?></div>
        </div>
    </header>

    <div class="content-wrapper">
        <div class="page-title">GL Mapping</div>

    </div>
</main>



<?php include '../footer.php'; ?>
</body>
</html>
<?php $conn->close(); ?>