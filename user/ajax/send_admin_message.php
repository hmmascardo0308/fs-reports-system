<?php
session_start();
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$username  = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? '';

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');

if ($message === '') {
    echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
    exit;
}

// Save user message
$stmt = $conn->prepare("
    INSERT INTO fs_admin_messages (user_id, username, full_name, message, is_from_admin, is_read)
    VALUES (?, ?, ?, ?, 0, 0)
");
$stmt->bind_param("isss", $user_id, $username, $full_name, $message);
$stmt->execute();
$stmt->close();

// --- Rule-based auto replies ---
$auto_reply = null;
$lower = strtolower($message);

if (strpos($lower, 'password') !== false || strpos($lower, 'login') !== false) {
    $auto_reply = "For password issues, click Forgot your password? in the login form.";
} elseif (strpos($lower, 'upload') !== false || strpos($lower, 'report') !== false) {
    $auto_reply = "To upload reports:\n• Per Region-Area → Comparative Report → Upload Per Region-Area\n• Raw Data → Upload Raw Data\nYou can also check Monthly Data to track imported months.";
} elseif (strpos($lower, 'monthly') !== false || strpos($lower, 'data') !== false) {
    $auto_reply = "Go to Comparative Report → Monthly Data to see which months have already been imported.";
} elseif (strpos($lower, 'p&l') !== false || strpos($lower, 'profit') !== false || strpos($lower, 'loss') !== false) {
    $auto_reply = "P&L reports are under Generate Report. Pages 1-6 are available. Page 5 includes allocated HO expenses.";
} elseif (strpos($lower, 'help') !== false || strpos($lower, 'how') !== false) {
    $auto_reply = "I can help with:\n• Uploading reports\n• Password issues\n• Monthly data tracking\n• Generating P&L / Comparative reports\n\nJust type your question, or type \"talk to admin\" to send a message to the admin team.";
} elseif (strpos($lower, 'talk to admin') !== false || strpos($lower, 'admin') !== false || strpos($lower, 'human') !== false) {
    $auto_reply = "Okay, your message has been sent to the admin team. They will reply here as soon as possible.";
}

// Save auto-reply if any
if ($auto_reply) {
    $stmt = $conn->prepare("
        INSERT INTO fs_admin_messages (user_id, username, full_name, message, is_from_admin, is_read)
        VALUES (?, 'SYSTEM', 'FS Bot', ?, 1, 1)
    ");
    $stmt->bind_param("is", $user_id, $auto_reply);
    $stmt->execute();
    $stmt->close();
}

echo json_encode([
    'success' => true,
    'auto_reply' => $auto_reply
]);