<?php
session_start();
require_once __DIR__ . '/../../config/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../welcome.php");
    exit;
}

$user_id = (int)($_POST['user_id'] ?? 0);
$message = trim($_POST['message'] ?? '');

if ($user_id && $message) {
    $admin_name = $_SESSION['full_name'] ?? 'Admin';
    $stmt = $conn->prepare("
        INSERT INTO fs_admin_messages (user_id, username, full_name, message, is_from_admin, is_read)
        VALUES (?, ?, ?, ?, 1, 1)
    ");
    $admin_user = 'ADMIN';
    $stmt->bind_param("isss", $user_id, $admin_user, $admin_name, $message);
    $stmt->execute();
}

header("Location: ../admin_messages.php?user_id=" . $user_id);
exit;