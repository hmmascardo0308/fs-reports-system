<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// Check for just logged in status
$just_logged_in = false;
if (isset($_SESSION['just_logged_in']) && $_SESSION['just_logged_in'] === true) {
    $just_logged_in = true;
    unset($_SESSION['just_logged_in']);
}

if (!isset($_SESSION['username'])) {
    header("Location: ../welcome.php");
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: ../welcome.php");
    exit;
}

$username   = $_SESSION['username'] ?? "unknown";
$full_name  = $_SESSION['full_name'] ?? "unknown";
$user_type  = $_SESSION['user_type'] ?? "unknown";
$user_id    = $_SESSION['user_id'] ?? 0;

$last_online = null;
$is_default_password = false;

$db_password = null;
if (!empty($username)) {
    $stmt = $conn->prepare("
        SELECT last_online, password 
        FROM fs_user 
        WHERE username = ? 
        LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($last_online, $db_password);
    $stmt->fetch();
    $stmt->close();
    
    if ($db_password !== null && password_verify('Mlinc1234', $db_password)) {
        $is_default_password = true;
    }
}

// Handle password change
$password_error = '';
$password_success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $stored_password = null;
    
    $stmt = $conn->prepare("SELECT password FROM fs_user WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($stored_password);
    $stmt->fetch();
    $stmt->close();
    
    if ($stored_password === null) {
        $password_error = "User account not found.";
    } elseif (!password_verify($current_password, $stored_password)) {
        $password_error = "Current password is incorrect.";
    } elseif (strlen($new_password) < 8) {
        $password_error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $password_error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $password_error = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $password_error = "Password must contain at least one number.";
    } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) {
        $password_error = "Password must contain at least one special character.";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New password and confirm password do not match.";
    } elseif ($new_password === 'Mlinc1234') {
        $password_error = "New password cannot be the default password.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE fs_user SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed_password, $user_id);
        
        if ($update->execute()) {
            $password_success = "Password changed successfully!";
            $is_default_password = false;
            
            $_SESSION['password_change_success'] = true;
            $_SESSION['success_message'] = "Password changed successfully!";
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $password_error = "Failed to update password. Please try again.";
        }
        $update->close();
    }
}

$show_success_message = false;
$success_message = '';
if (isset($_SESSION['password_change_success']) && $_SESSION['password_change_success']) {
    $show_success_message = true;
    $success_message = $_SESSION['success_message'] ?? 'Password changed successfully!';
    unset($_SESSION['password_change_success']);
    unset($_SESSION['success_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css?v=<?= time(); ?>">
    <title>Dashboard | Control Panel</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>

    <style>
        /* Make sidebar dropdown menus scrollable when opened */
        .sidebar .has-dropdown .dropdown-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .sidebar .has-dropdown .dropdown-menu.show {
            max-height: 60vh;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }

        .sidebar .has-dropdown .dropdown-menu::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar .has-dropdown .dropdown-menu::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar .has-dropdown .dropdown-menu::-webkit-scrollbar-thumb {
            background-color: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }

        .sidebar .has-dropdown .dropdown-menu::-webkit-scrollbar-thumb:hover {
            background-color: rgba(255, 255, 255, 0.5);
        }

        .sidebar .has-dropdown .dropdown-menu {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
        }

      /* ========== ADMIN CHAT BOT STYLES (Red Theme) ========== */
.admin-chat-button {
    position: fixed;
    bottom: 25px;
    right: 25px;
    width: 58px;
    height: 58px;
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    cursor: pointer;
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
    z-index: 9998;
    transition: transform 0.2s, box-shadow 0.2s;
}
.admin-chat-button:hover {
    transform: scale(1.08);
    box-shadow: 0 8px 25px rgba(220, 38, 38, 0.5);
}
.chat-unread-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #991b1b;
    color: white;
    font-size: 11px;
    font-weight: 700;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.admin-chat-panel {
    position: fixed;
    bottom: 95px;
    right: 25px;
    width: 370px;
    max-height: 540px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.18);
    display: none;
    flex-direction: column;
    z-index: 9999;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}
.admin-chat-panel.open {
    display: flex;
}

.chat-header {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    color: white;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.chat-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}
.chat-header-left i {
    font-size: 22px;
}
.chat-header-left small {
    display: block;
    opacity: 0.85;
    font-size: 12px;
}
.chat-close-btn {
    background: transparent;
    border: none;
    color: white;
    font-size: 18px;
    cursor: pointer;
    opacity: 0.85;
}
.chat-close-btn:hover { opacity: 1; }

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: #fef2f2;
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-height: 300px;
    max-height: 360px;
}

/* ===== BUBBLES (Red Theme) ===== */
.chat-bubble {
    max-width: 80%;
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 14px;
    line-height: 1.5;
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: pre-wrap;
}

.chat-bubble.user {
    background: #dc2626;
    color: white;
    align-self: flex-end;
    border-bottom-right-radius: 4px;
}

.chat-bubble.bot {
    background: #ffffff;
    color: #1e293b;
    align-self: flex-start;
    border: 1px solid #fecaca;
    border-bottom-left-radius: 4px;
}

.chat-bubble.admin {
    background: #fee2e2;
    color: #7f1d1d;
    align-self: flex-start;
    border: 1px solid #fca5a5;
    border-left: 4px solid #dc2626;
    border-bottom-left-radius: 4px;
}

.chat-meta {
    font-size: 11px;
    opacity: 0.7;
    margin-top: 5px;
    display: block;
}

.chat-bubble.user .chat-meta {
    color: rgba(255,255,255,0.85);
}

.chat-input-area {
    display: flex;
    gap: 8px;
    padding: 12px;
    border-top: 1px solid #e5e7eb;
    background: white;
}
.chat-input-area input {
    flex: 1;
    border: 1px solid #d1d5db;
    border-radius: 20px;
    padding: 10px 16px;
    font-size: 14px;
    outline: none;
}
.chat-input-area input:focus {
    border-color: #dc2626;
}
.chat-input-area button {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    border: none;
    background: #dc2626;
    color: white;
    cursor: pointer;
    font-size: 16px;
}
.chat-input-area button:hover {
    background: #b91c1c;
}

.chat-welcome {
    text-align: center;
    color: #64748b;
    font-size: 13px;
    padding: 20px 10px;
}
.chat-welcome i {
    color: #dc2626 !important;
}
    </style>
</head>
<body>

    <!-- Sign In Success Loading Effect -->
    <?php if ($just_logged_in): ?>
    <div id="signin-overlay" class="signin-overlay">
        <div class="signin-loader">
            <div class="loader-circle">
                <div class="loader-checkmark">
                    <svg class="checkmark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 52">
                        <circle class="checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
                        <path class="checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                    </svg>
                </div>
            </div>
            <h3 class="loader-text">Welcome, <?php echo htmlspecialchars($full_name); ?>!</h3>
            <p class="loader-subtext">Redirecting to dashboard...</p>
        </div>
    </div>
    
    <script>
        setTimeout(function() {
            const overlay = document.getElementById('signin-overlay');
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(function() {
                    window.location.reload();
                }, 500);
            }
        }, 5000);
    </script>
    <?php endif; ?>

    <!-- Password Change Modal -->
    <div id="passwordChangeModal" class="password-modal <?php echo $is_default_password ? 'show' : ''; ?>">
        <div class="password-modal-content">
            <h2>
                <i class="fas fa-shield-alt"></i>
                Change Password Required
            </h2>
            
            <?php if ($is_default_password): ?>
                <div class="warning-text">
                    <i class="fas fa-exclamation-triangle"></i>
                    You are currently using the default password. For security reasons, please change your password immediately.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($password_error)): ?>
                <div class="error-message">
                    <i class="fas fa-times-circle"></i>
                    <?php echo htmlspecialchars($password_error); ?>
                </div>
            <?php endif; ?>
            
            <form method="post" id="passwordChangeForm">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="current_password" name="current_password" required placeholder="Enter your current password">
                        <button type="button" class="toggle-password-btn" onclick="togglePassword('current_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="new_password" name="new_password" required placeholder="Enter new password">
                        <button type="button" class="toggle-password-btn" onclick="togglePassword('new_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm new password">
                        <button type="button" class="toggle-password-btn" onclick="togglePassword('confirm_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="password-requirements">
                    <p><i class="fas fa-list"></i> Password Requirements:</p>
                    <ul>
                        <li id="req-length" class="invalid">
                            <i class="fas fa-times-circle"></i> At least 8 characters
                        </li>
                        <li id="req-uppercase" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one uppercase letter
                        </li>
                        <li id="req-lowercase" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one lowercase letter
                        </li>
                        <li id="req-number" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one number
                        </li>
                        <li id="req-special" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one special character (!@#$%^&*)
                        </li>
                        <li id="req-not-default" class="invalid">
                            <i class="fas fa-times-circle"></i> Not the default password
                        </li>
                    </ul>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="change_password" class="btn btn-primary">
                        <i class="fas fa-save"></i> Change Password
                    </button>
                    <?php if (!$is_default_password): ?>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Later
                        </button>
                    <?php endif; ?>
                </div>
                
                <?php if ($is_default_password): ?>
                    <small style="display: block; text-align: center; margin-top: 15px;">
                        <i class="fas fa-info-circle"></i> 
                        You must change your password before accessing the dashboard.
                    </small>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Success Toast Notification -->
    <?php if ($show_success_message): ?>
    <div id="successToast" class="success-toast">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($success_message); ?></span>
        <span class="close-toast" onclick="this.parentElement.remove()">&times;</span>
    </div>
    <script>
        setTimeout(function() {
            var toast = document.getElementById('successToast');
            if (toast) {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(function() {
                    if (toast) toast.remove();
                }, 300);
            }
        }, 3000);
    </script>
    <?php endif; ?>

    <!-- Professional Sidebar Navigation -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <i class="fas fa-chart-line"></i>
                <span>FS Reports</span>
            </div>
        </div>
        
        <div class="sidebar-user">
            <div class="user-avatar">
                <span><?php echo substr($full_name, 0, 1); ?></span>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                <div class="user-role"><?php echo strtoupper(htmlspecialchars($user_type)); ?></div>
            </div>
        </div>
        
        <ul class="sidebar-menu">
            <li>
                <a href="user_dashboard.php" class="active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <?php if ($user_type === "admin" || $user_type === "reports" || $user_type === "bookkeeper") : ?>
                <li class="has-dropdown">
                    <a href="javascript:void(0)" class="dropdown-trigger">
                        <i class="fas fa-chart-bar"></i>
                        <span>Comparative Report</span>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a href="javascript:void(0)" onclick="openFileUploadModal()">
                                <i class="fas fa-upload"></i> Upload Per Region-Area
                            </a>
                        </li>
                        <li>
                            <a href="upload_raw_data.php">
                                <i class="fa-solid fa-database"></i> Upload Raw Data
                            </a>
                        </li>
                        <li>
                            <a href="per_region_area.php">
                                <i class="fa-regular fa-file-lines"></i> Generate Per Region-Area Report
                            </a>
                        </li>
                        <li>
                            <a href="unlock_period.php">
                                <i class="fa-solid fa-clipboard"></i> Monthly Data
                            </a>
                        </li>
                        <li>
                            <a href="javascript:void(0)" onclick="openReportedTransactionsModal()">
                                <i class="fa-solid fa-check"></i> Reported Transactions
                            </a>
                        </li>
                        <?php if ($user_type === "admin" || $user_type === "reports") : ?>
                            <li>
                                <a href="settings.php">
                                    <i class="fas fa-chart-pie"></i> Report Format (Raw Data)
                                </a>
                            </li>
                            <li>
                                <a href="fs_reports.php">
                                    <i class="fas fa-file-invoice-dollar"></i> Report Format (HO)
                                </a>
                            </li>
                            <li>
                                <a href="upload_previous.php">
                                    <i class="fas fa-history"></i> Previous Report
                                </a>
                            </li>
                        <?php endif; ?>
            
                        <?php if ($user_type === "admin") : ?>
                        <li>
                                <a href="gl_mapping.php">
                                    <i class="fa-solid fa-link"></i> GL Mapping
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- Generate Report Dropdown -->
                <li class="has-dropdown">
                    <a href="javascript:void(0)" class="dropdown-trigger">
                        <i class="fas fa-file-invoice"></i>
                        <span>Generate Report</span>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a href="javascript:void(0)" onclick="openOriginalDataModal()">
                                <i class="fas fa-file-alt"></i> Comparative Report (Original Data)
                            </a>
                        </li>
                        <li>
                            <a href="javascript:void(0)" onclick="openManualAdjustmentModal()">
                                <i class="fas fa-edit"></i> Comparative Report (Manual Adjustment)
                            </a>
                        </li>
                        <li>
                            <a href="consolidated_with_adjustment.php">
                                <i class="fas fa-layer-group"></i> Consolidated Report (Per Zone)
                            </a>
                        </li>
                        <li>
                            <a href="comparative_report_original_page_one.php">
                                <i class="fas fa-file-invoice-dollar"></i> P&L STATEMENT (NATIONWIDE) Page 1
                            </a>
                        </li>
                        <li>
                            <a href="comparative_report_original_page_two.php">
                                <i class="fas fa-file-invoice-dollar"></i> P&L STATEMENT - OPERATIONS (w/out HO EXPENSE & INCOME) Page 2
                            </a>
                        </li>
                        <li>
                            <a href="comparative_report_original_page_three.php">
                                <i class="fas fa-file-invoice-dollar"></i> COMPARATIVE P&L STATEMENT Page 3
                            </a>
                        </li>
                        <li>
                            <a href="comparative_report_original_page_four.php">
                                <i class="fas fa-file-invoice-dollar"></i> CONSOLIDATED P&L STATEMENT (NATIONWIDE, LNCR, VISMIN) Page 4
                            </a>
                        </li>
                        <li>
                            <a href="comparative_report_original_ho_with_past_and_adjustment.php">
                                <i class="fas fa-file-invoice-dollar"></i> COMPARATIVE P&L STATEMENT - w/ ALLOCATED HO Page 5
                            </a>
                        </li>
                        <li>
                            <a href="comparative_report_original_page_six.php">
                                <i class="fas fa-file-invoice-dollar"></i> COMPARATIVE GROSS INCOME REPORT Page 6
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($user_type === "admin") : ?>
                <?php
                $pending_count = 0;
                $count_query = "SELECT COUNT(*) as total FROM fs_reports.password_reset_requests WHERE status = 'Pending'";
                $count_result = $conn->query($count_query);
                if ($count_result && $row = $count_result->fetch_assoc()) {
                    $pending_count = $row['total'];
                }

                // Unread admin messages count
                $unread_msg_count = 0;
                $msg_count_result = $conn->query("SELECT COUNT(*) as total FROM fs_admin_messages WHERE is_from_admin = 0 AND is_read = 0");
                if ($msg_count_result && $row = $msg_count_result->fetch_assoc()) {
                    $unread_msg_count = $row['total'];
                }
                ?>
                <li class="has-dropdown">
                    <a href="javascript:void(0)" class="dropdown-trigger">
                        <i class="fas fa-user-cog"></i>
                        <span>Users</span>
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li>
                            <a href="all_users.php">
                                <i class="fas fa-users"></i> All Users
                            </a>
                        </li>
                        <li>
                            <a href="password_reset_requests.php">
                                <i class="fas fa-key"></i> Password Reset Requests
                                <?php if ($pending_count > 0): ?>
                                    <span class="pending-badge"><?php echo $pending_count; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li>
                            <a href="admin_messages.php">
                                <i class="fas fa-comments"></i> Admin Messages
                                <?php if ($unread_msg_count > 0): ?>
                                    <span class="pending-badge"><?php echo $unread_msg_count; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    </ul>
                </li>
            <?php endif; ?>
            
            <li class="logout-item">
                <a href="?action=logout" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="top-bar">
            <div class="page-title">
                <i class="fas fa-home"></i>
                <h2>Dashboard Overview</h2>
            </div>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?></span>
                <div class="avatar"><?php echo substr($full_name, 0, 1); ?></div>
            </div>
        </header>

        <div class="dashboard-grid">
            <div class="card">
                <div class="card-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
                <h3>Account Information</h3>
                <div class="info-row">
                    <span class="info-label">Full Name:</span>
                    <span><?php echo htmlspecialchars($full_name); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Access Level:</span>
                    <span class="access-level">
                        <?php echo strtoupper(htmlspecialchars($user_type)); ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="status-badge online">
                        <i class="fas fa-circle"></i> Online
                    </span>
                </div>
                <?php if ($is_default_password): ?>
                    <div class="info-row">
                        <span class="info-label">Password Status:</span>
                        <span class="status-badge warning">
                            <i class="fas fa-exclamation-triangle"></i> Default Password
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3>System Overview</h3>
                <div class="last-login">
                    <i class="fas fa-clock"></i>
                    <div>
                        <span class="info-label">Last Login:</span>
                        <?php
                            if (isset($last_online) && !empty($last_online)) {
                                echo date('F j, Y h:i:s A', strtotime($last_online));
                            } else {
                                echo 'N/A';
                            }
                        ?>
                    </div>
                </div>
                <div class="system-stats"></div>
            </div>
        </div>
        
        <?php if ($is_default_password): ?>
            <div class="security-alert">
                <i class="fas fa-shield-alt"></i>
                <div>
                    <strong>Security Alert:</strong> You are currently using the default password. 
                    <a href="javascript:void(0)" onclick="document.getElementById('passwordChangeModal').classList.add('show');">
                        Change your password now
                    </a>.
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- ========== ADMIN STICKY NOTE ========== -->
    <div id="adminStickyNote" class="admin-sticky-note">
        <div class="sticky-header">
            <div class="sticky-title">
                <i class="fas fa-thumbtack"></i> Admin Reminder
            </div>
            <div class="sticky-actions">
                <button class="sticky-btn minimize-btn" title="Minimize" onclick="minimizeSticky()">
                    <i class="fas fa-minus"></i>
                </button>
                <button class="sticky-btn close-btn" title="Close" onclick="closeSticky()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="sticky-body">
• Please upload monthly report <b>(either per region-area or per zone)</b>.
• To track imported data per month, check <b>Monthly Data</b>
• <b>Generating</b> comparative report <b>per branch</b> is already functional <b>(from raw data)</b>. 
• Pages <b>2-5</b> are already updated.
• Manual adjustment for HO allocated is already update <b>(for wHO page 5)</b>.
• New format due to ML Properties will be <b>implemented soon</b>.
<span style="text-decoration: line-through;"> Upload previous year month transactions in <b>Previous Report</b>.</span>
• All 2025 reports are already uploaded.
• For assistance, please contact the admin team.
        </div>
        <div class="sticky-footer">
            — FS Admin
        </div>
    </div>

    <!-- Minimized Bell -->
    <div id="stickyBell" class="sticky-bell" onclick="expandSticky()" title="Open Admin Reminder">
        <i class="fas fa-bell"></i>
        <span class="bell-badge">1</span>
    </div>

    <!-- Upload Report Selection Modal -->
    <div id="uploadFileModal" class="password-modal">
        <div class="password-modal-content" style="max-width: 400px; text-align: center; position: relative;">
            <span onclick="closeUploadFileModal()" style="position: absolute; right: 15px; top: 15px; cursor: pointer; font-size: 24px; color: #888; line-height: 1;">&times;</span>
            <h2 style="margin-top: 10px; margin-bottom: 20px; color: #333; font-size: 1.5rem; text-align: center;">
                <i class="fas fa-file-upload" style="color: #007bff; margin-right: 10px;"></i>Upload Report<br>(Per Region-Area)
            </h2>
            <p style="margin-bottom: 30px; color: #666; font-size: 1rem;">Please select the file format you wish to upload:</p>
            
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <a href="comparative_report.php" class="btn" style="background-color: #28a745; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; transition: background 0.3s; display: block;">
                    <i class="fas fa-file-excel" style="margin-right: 8px;"></i> Upload Excel File (.xlsx, .xls)
                </a>
                <a href="comparative_report_csv.php" class="btn" style="background-color: #17a2b8; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; transition: background 0.3s; display: block;">
                    <i class="fas fa-file-csv" style="margin-right: 8px;"></i> Upload CSV File (.csv)
                </a>
            </div>
        </div>
    </div>

    <!-- Reported Transactions Selection Modal -->
    <div id="reportedTransactionsModal" class="password-modal">
        <div class="password-modal-content" style="max-width: 450px; text-align: center; position: relative;">
            <span onclick="closeReportedTransactionsModal()" style="position: absolute; right: 15px; top: 15px; cursor: pointer; font-size: 24px; color: #888; line-height: 1;">&times;</span>
            <h2 style="margin-top: 10px; margin-bottom: 20px; color: #333; font-size: 1.5rem;">
                <i class="fas fa-check-circle" style="color: #28a745; margin-right: 10px;"></i>Mark Transactions as Reported
            </h2>
            <p style="margin-bottom: 30px; color: #666; font-size: 1rem;">Please select the data source to mark transactions as reported:</p>
            
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <a href="mark_reported.php" class="btn" style="background-color: #28a745; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; transition: background 0.3s; display: block;">
                    <i class="fas fa-file-excel" style="margin-right: 8px;"></i> Mark Transactions as Reported
                </a>
                <a href="#" class="btn" style="background-color: #6f42c1; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; transition: background 0.3s; display: block; cursor: not-allowed; opacity: 0.7;" onclick="event.preventDefault(); alert('This feature is coming soon!');">
                    <i class="fas fa-database" style="margin-right: 8px;"></i> Mark Transactions as Reported (From Raw Data)
                    <span style="font-size: 0.7rem; background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 12px; margin-left: 5px;">Coming Soon</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Comparative Report (Original Data) Selection Modal -->
    <div id="originalDataModal" class="password-modal">
        <div class="password-modal-content" style="max-width: 420px; text-align: center; position: relative;">
            <span onclick="closeOriginalDataModal()" style="position: absolute; right: 15px; top: 15px; cursor: pointer; font-size: 24px; color: #888; line-height: 1;">&times;</span>
            <h2 style="margin-top: 10px; margin-bottom: 20px; color: #333; font-size: 1.4rem;">
                <i class="fas fa-file-alt" style="color: #007bff; margin-right: 10px;"></i>
                Comparative Report (Original Data)
            </h2>
            <p style="margin-bottom: 25px; color: #666; font-size: 0.95rem;">Select the report type:</p>
            
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="comparative_report_original.php" class="btn" style="background-color: #28a745; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; display: block;">
                    <i class="fas fa-map-marked-alt" style="margin-right: 8px;"></i> Per Region-Area
                </a>
                <a href="comparative_report_original_raw.php" class="btn" style="background-color: #17a2b8; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; display: block;">
                    <i class="fas fa-globe" style="margin-right: 8px;"></i> Per Zone
                </a>
            </div>
        </div>
    </div>

    <!-- Comparative Report (Manual Adjustment) Selection Modal -->
    <div id="manualAdjustmentModal" class="password-modal">
        <div class="password-modal-content" style="max-width: 420px; text-align: center; position: relative;">
            <span onclick="closeManualAdjustmentModal()" style="position: absolute; right: 15px; top: 15px; cursor: pointer; font-size: 24px; color: #888; line-height: 1;">&times;</span>
            <h2 style="margin-top: 10px; margin-bottom: 20px; color: #333; font-size: 1.4rem;">
                <i class="fas fa-edit" style="color: #6f42c1; margin-right: 10px;"></i>
                Comparative Report (Manual Adjustment)
            </h2>
            <p style="margin-bottom: 25px; color: #666; font-size: 0.95rem;">Select the report type:</p>
            
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="manual_adjustment_new.php" class="btn" style="background-color: #28a745; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; display: block;">
                    <i class="fas fa-map-marked-alt" style="margin-right: 8px;"></i> Per Region-Area
                </a>
                <a href="manual_adjustment_new_raw.php" class="btn" style="background-color: #17a2b8; color: white; text-decoration: none; padding: 12px; border-radius: 6px; font-weight: 600; display: block;">
                    <i class="fas fa-globe" style="margin-right: 8px;"></i> Per Zone
                </a>
            </div>
        </div>
    </div>

    <!-- ========== ADMIN CHAT BOT ========== -->
    <div id="adminChatButton" class="admin-chat-button" onclick="toggleAdminChat()">
        <i class="fas fa-comments"></i>
        <span id="chatUnreadBadge" class="chat-unread-badge" style="display:none;">0</span>
    </div>

    <div id="adminChatPanel" class="admin-chat-panel">
        <div class="chat-header">
            <div class="chat-header-left">
                <i class="fas fa-robot"></i>
                <div>
                    <strong>FS Assistant</strong>
                    <small>Ask me or talk to admin</small>
                </div>
            </div>
            <button class="chat-close-btn" onclick="toggleAdminChat()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div id="chatMessages" class="chat-messages">
            <!-- messages loaded here -->
        </div>

        <div class="chat-input-area">
            <input type="text" id="chatInput" placeholder="Type a message..." autocomplete="off">
            <button onclick="sendChatMessage()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function closeModal() {
            document.getElementById('passwordChangeModal').classList.remove('show');
        }

        function openFileUploadModal() {
            document.getElementById('uploadFileModal').classList.add('show');
        }

        function closeUploadFileModal() {
            document.getElementById('uploadFileModal').classList.remove('show');
        }

        function openReportedTransactionsModal() {
            document.getElementById('reportedTransactionsModal').classList.add('show');
        }

        function closeReportedTransactionsModal() {
            document.getElementById('reportedTransactionsModal').classList.remove('show');
        }

        function openOriginalDataModal() {
            document.getElementById('originalDataModal').classList.add('show');
        }
        function closeOriginalDataModal() {
            document.getElementById('originalDataModal').classList.remove('show');
        }

        function openManualAdjustmentModal() {
            document.getElementById('manualAdjustmentModal').classList.add('show');
        }
        function closeManualAdjustmentModal() {
            document.getElementById('manualAdjustmentModal').classList.remove('show');
        }

        // Sticky Note Controls
        function minimizeSticky() {
            const sticky = document.getElementById('adminStickyNote');
            const bell = document.getElementById('stickyBell');
            sticky.classList.add('minimized');
            bell.classList.add('show');
        }

        function expandSticky() {
            const sticky = document.getElementById('adminStickyNote');
            const bell = document.getElementById('stickyBell');
            sticky.classList.remove('minimized');
            bell.classList.remove('show');
        }

        function closeSticky() {
            const sticky = document.getElementById('adminStickyNote');
            const bell = document.getElementById('stickyBell');
            sticky.style.display = 'none';
            bell.classList.remove('show');
        }

        // ========== ADMIN CHAT BOT ==========
        let chatOpen = false;

        function toggleAdminChat() {
            const panel = document.getElementById('adminChatPanel');
            chatOpen = !chatOpen;
            panel.classList.toggle('open', chatOpen);
            if (chatOpen) {
                loadChatMessages();
                document.getElementById('chatInput').focus();
            }
        }

        function loadChatMessages() {
            fetch('ajax/get_admin_messages.php')
                .then(r => r.json())
                .then(data => {
                    const container = document.getElementById('chatMessages');
                    container.innerHTML = '';

                    if (!data.messages || data.messages.length === 0) {
                        container.innerHTML = `
                            <div class="chat-welcome">
                                <i class="fas fa-robot" style="font-size:28px;margin-bottom:8px;display:block;color:#0d6efd;"></i>
                                Hi! I'm the FS Assistant.<br>
                                Ask me about reports, uploads, passwords, or type<br>
                                <strong>"talk to admin"</strong> to message the admin team.
                            </div>`;
                        return;
                    }

                    data.messages.forEach(msg => {
                        const isBot = msg.username === 'SYSTEM';
                        const isAdmin = msg.is_from_admin == 1 && !isBot;
                        const bubbleClass = msg.is_from_admin == 1 ? (isBot ? 'bot' : 'admin') : 'user';
                        const senderLabel = isBot ? 'FS Bot' : (isAdmin ? 'Admin' : 'You');

                        const div = document.createElement('div');
                        div.className = `chat-bubble ${bubbleClass}`;
                        div.innerHTML = `
                            <div>${escapeHtml(msg.message)}</div>
                            <div class="chat-meta">${senderLabel} • ${formatTime(msg.created_at)}</div>
                        `;
                        container.appendChild(div);
                    });

                    container.scrollTop = container.scrollHeight;
                })
                .catch(() => {
                    document.getElementById('chatMessages').innerHTML = 
                        '<div class="chat-welcome">Unable to load messages. Please try again.</div>';
                });
        }

        function sendChatMessage() {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            if (!message) return;

            // Optimistic UI
            const container = document.getElementById('chatMessages');
            const welcome = container.querySelector('.chat-welcome');
            if (welcome) welcome.remove();

            const div = document.createElement('div');
            div.className = 'chat-bubble user';
            div.innerHTML = `<div>${escapeHtml(message)}</div><div class="chat-meta">You • just now</div>`;
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
            input.value = '';

            fetch('ajax/send_admin_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    setTimeout(loadChatMessages, 350);
                } else {
                    alert(data.message || 'Failed to send message');
                }
            })
            .catch(() => alert('Network error. Please try again.'));
        }

        // Enter key support
        document.addEventListener('DOMContentLoaded', function() {
            const chatInput = document.getElementById('chatInput');
            if (chatInput) {
                chatInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') sendChatMessage();
                });
            }
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatTime(datetime) {
            const d = new Date(datetime);
            return d.toLocaleString('en-US', {
                month: 'short', day: 'numeric',
                hour: 'numeric', minute: '2-digit'
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Dropdown toggles
            const dropdownTriggers = document.querySelectorAll('.dropdown-trigger');
            
            dropdownTriggers.forEach(trigger => {
                trigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    const parent = this.closest('.has-dropdown');
                    const dropdownMenu = parent.querySelector('.dropdown-menu');
                    const icon = this.querySelector('.dropdown-icon');
                    
                    dropdownTriggers.forEach(otherTrigger => {
                        if (otherTrigger !== trigger) {
                            const otherParent = otherTrigger.closest('.has-dropdown');
                            const otherMenu = otherParent.querySelector('.dropdown-menu');
                            const otherIcon = otherTrigger.querySelector('.dropdown-icon');
                            if (otherMenu.classList.contains('show')) {
                                otherMenu.classList.remove('show');
                                otherIcon.classList.remove('rotate');
                            }
                        }
                    });
                    
                    dropdownMenu.classList.toggle('show');
                    if (icon) {
                        icon.classList.toggle('rotate');
                    }
                });
            });
            
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.has-dropdown')) {
                    document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                        menu.classList.remove('show');
                    });
                    document.querySelectorAll('.dropdown-icon.rotate').forEach(icon => {
                        icon.classList.remove('rotate');
                    });
                }
            });
            
            // Close modals when clicking outside
            const modals = document.querySelectorAll('.password-modal');
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        if (modal.id === 'passwordChangeModal' && <?php echo $is_default_password ? 'true' : 'false'; ?>) {
                            return;
                        }
                        modal.classList.remove('show');
                    }
                });
            });
            
            // Real-time password validation
            const newPassword = document.getElementById('new_password');
            
            if (newPassword) {
                newPassword.addEventListener('input', function() {
                    const password = this.value;
                    
                    const reqLength = document.getElementById('req-length');
                    if (password.length >= 8) {
                        reqLength.className = 'valid';
                        reqLength.innerHTML = '<i class="fas fa-check-circle"></i> At least 8 characters';
                    } else {
                        reqLength.className = 'invalid';
                        reqLength.innerHTML = '<i class="fas fa-times-circle"></i> At least 8 characters';
                    }
                    
                    const reqUppercase = document.getElementById('req-uppercase');
                    if (/[A-Z]/.test(password)) {
                        reqUppercase.className = 'valid';
                        reqUppercase.innerHTML = '<i class="fas fa-check-circle"></i> At least one uppercase letter';
                    } else {
                        reqUppercase.className = 'invalid';
                        reqUppercase.innerHTML = '<i class="fas fa-times-circle"></i> At least one uppercase letter';
                    }
                    
                    const reqLowercase = document.getElementById('req-lowercase');
                    if (/[a-z]/.test(password)) {
                        reqLowercase.className = 'valid';
                        reqLowercase.innerHTML = '<i class="fas fa-check-circle"></i> At least one lowercase letter';
                    } else {
                        reqLowercase.className = 'invalid';
                        reqLowercase.innerHTML = '<i class="fas fa-times-circle"></i> At least one lowercase letter';
                    }
                    
                    const reqNumber = document.getElementById('req-number');
                    if (/[0-9]/.test(password)) {
                        reqNumber.className = 'valid';
                        reqNumber.innerHTML = '<i class="fas fa-check-circle"></i> At least one number';
                    } else {
                        reqNumber.className = 'invalid';
                        reqNumber.innerHTML = '<i class="fas fa-times-circle"></i> At least one number';
                    }
                    
                    const reqSpecial = document.getElementById('req-special');
                    if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                        reqSpecial.className = 'valid';
                        reqSpecial.innerHTML = '<i class="fas fa-check-circle"></i> At least one special character';
                    } else {
                        reqSpecial.className = 'invalid';
                        reqSpecial.innerHTML = '<i class="fas fa-times-circle"></i> At least one special character';
                    }
                    
                    const reqNotDefault = document.getElementById('req-not-default');
                    if (password !== 'Mlinc1234') {
                        reqNotDefault.className = 'valid';
                        reqNotDefault.innerHTML = '<i class="fas fa-check-circle"></i> Not the default password';
                    } else {
                        reqNotDefault.className = 'invalid';
                        reqNotDefault.innerHTML = '<i class="fas fa-times-circle"></i> Not the default password';
                    }
                });
            }
            
            <?php if ($is_default_password): ?>
                const modal = document.getElementById('passwordChangeModal');
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            <?php endif; ?>

            // Admin Sticky Note – Drag
            const sticky = document.getElementById('adminStickyNote');
            if (sticky) {
                const header = sticky.querySelector('.sticky-header');
                let isDragging = false;
                let startX, startY, startLeft, startTop;

                header.addEventListener('mousedown', function (e) {
                    if (e.target.closest('.sticky-btn')) return;

                    isDragging = true;
                    sticky.classList.add('dragging');
                    startX = e.clientX;
                    startY = e.clientY;
                    startLeft = parseInt(sticky.style.left) || sticky.offsetLeft;
                    startTop  = parseInt(sticky.style.top)  || sticky.offsetTop;
                    e.preventDefault();
                });

                document.addEventListener('mousemove', function (e) {
                    if (!isDragging) return;
                    const dx = e.clientX - startX;
                    const dy = e.clientY - startY;
                    sticky.style.left = (startLeft + dx) + 'px';
                    sticky.style.top  = (startTop  + dy) + 'px';
                });

                document.addEventListener('mouseup', function () {
                    if (isDragging) {
                        isDragging = false;
                        sticky.classList.remove('dragging');
                    }
                });
            }
        });
    </script>

<?php include '../footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>