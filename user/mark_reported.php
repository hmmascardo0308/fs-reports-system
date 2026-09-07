<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// Set timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

// Session Management
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

$username = $_SESSION['username'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

// Get filter parameters
$selected_month = isset($_GET['transaction_month']) ? $_GET['transaction_month'] : date('Y-m');

// Validate date format (should be YYYY-MM)
if ($selected_month && !preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

// Optimize Date Filter: Use BETWEEN instead of DATE_FORMAT to utilize indexes
$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

$message = '';
$message_type = '';

// Handle Report Action - Mark ALL zones for the month
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type'])) {
    if ($_POST['action_type'] === 'report') {
        // Get all mainzone and zone combinations for the selected month
        $fetch_query = "SELECT DISTINCT mainzone, zone 
                        FROM comparative_report 
                        WHERE transaction_month BETWEEN ? AND ?";
        $fetch_stmt = $conn->prepare($fetch_query);
        $fetch_stmt->bind_param("ss", $start_date, $end_date);
        $fetch_stmt->execute();
        $fetch_result = $fetch_stmt->get_result();
        
        $groups = [];
        while ($row = $fetch_result->fetch_assoc()) {
            $groups[] = $row;
        }
        $fetch_stmt->close();
        
        if (count($groups) > 0) {
            $success_count = 0;
            $current_datetime = date('Y-m-d H:i:s');
            $new_status = 'Reported';
            
            $update_stmt = $conn->prepare("UPDATE comparative_report SET reported_status = ?, reported_status_by = ?, reported_status_date = ? WHERE mainzone <=> ? AND zone <=> ? AND transaction_month BETWEEN ? AND ?");
            
            foreach ($groups as $group) {
                $update_stmt->bind_param("sssssss", 
                    $new_status,           // reported_status
                    $username,             // by
                    $current_datetime,     // date
                    $group['mainzone'],    // mainzone
                    $group['zone'],        // zone
                    $start_date,
                    $end_date
                );
                
                if ($update_stmt->execute()) {
                    $success_count++;
                }
            }
            $update_stmt->close();
            
            if ($success_count > 0) {
                $message = "All $success_count zone(s) marked as reported successfully for " . date('F Y', strtotime($selected_month . '-01')) . ".";
                $message_type = "success";
            } else {
                $message = "No records updated.";
                $message_type = "error";
            }
        } else {
            $message = "No records found for the selected month.";
            $message_type = "error";
        }
    }
}

// Fetch filtered data with report information and row count
// Use MAX() to comply with ONLY_FULL_GROUP_BY
$query = "SELECT 
            mainzone, 
            zone, 
            MAX(reported_status) as reported_status,
            MAX(reported_status_by) as reported_status_by,
            MAX(reported_status_date) as reported_status_date,
            MAX(uploaded_date) as uploaded_date,
            COUNT(*) as record_count
          FROM comparative_report 
          WHERE transaction_month BETWEEN ? AND ?
          GROUP BY mainzone, zone
          ORDER BY mainzone, zone";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// Define the four required zones
$required_zones = ['LZN', 'NCR', 'MIN', 'VIS'];

// Get all zones present in the result
$present_zones = [];
$all_reported = true;
$missing_zones = [];

if ($result && $result->num_rows > 0) {
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        if (!in_array($row['zone'], $present_zones)) {
            $present_zones[] = $row['zone'];
        }
        if ($row['reported_status'] !== 'Reported') {
            $all_reported = false;
        }
    }
    $result->data_seek(0);
    
    // Find missing zones
    foreach ($required_zones as $zone) {
        if (!in_array($zone, $present_zones)) {
            $missing_zones[] = $zone;
        }
    }
}

// Check if all four zones are present
$all_zones_present = count(array_intersect($required_zones, $present_zones)) === 4;

// Check if all are already reported
$all_reported_status = $all_reported && $result->num_rows > 0;

// Get zone list for warning message
$zone_list_string = implode(', ', $present_zones);
$missing_zones_string = implode(', ', $missing_zones);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mark as Reported</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/lock_unlock.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* Custom Modal for Missing Zones Warning */
        .modal-warning-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .modal-warning-overlay.active {
            display: flex;
        }
        .modal-warning-box {
            background: white;
            border-radius: 12px;
            padding: 30px 40px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .modal-warning-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        .modal-warning-header .icon {
            font-size: 40px;
            color: #ff0707;
        }
        .modal-warning-header .icon-success {
            font-size: 40px;
            color: #28a745;
        }
        .modal-warning-header h2 {
            margin: 0;
            font-size: 20px;
            color: #333;
        }
        .modal-warning-body {
            color: #555;
            line-height: 1.6;
            margin-bottom: 25px;
        }
        .modal-warning-body .zone-list {
            background: #d4edda;
            border: 1px solid #28a745;
            border-radius: 6px;
            padding: 10px 15px;
            margin: 10px 0;
            font-weight: 600;
            color: #155724;
        }
        .modal-warning-body .missing-zone-list {
            background: #ffe3e3;
            border: 1px solid #ff0707;
            border-radius: 6px;
            padding: 10px 15px;
            margin: 10px 0;
            font-weight: 600;
            color: #770000;
        }
        .modal-warning-body .present-zone-list {
            background: #d4edda;
            border: 1px solid #28a745;
            border-radius: 6px;
            padding: 10px 15px;
            margin: 10px 0;
            font-weight: 600;
            color: #155724;
        }
        .modal-warning-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .modal-warning-footer button {
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .modal-warning-footer .btn-cancel {
            background: #e9ecef;
            color: #495057;
        }
        .modal-warning-footer .btn-cancel:hover {
            background: #dee2e6;
        }
        .modal-warning-footer .btn-proceed {
            background: #28a745;
            color: #ffffff;
        }
        .modal-warning-footer .btn-proceed:hover {
            background: #218838;
        }
        .modal-warning-footer .btn-proceed-danger {
            background: #ff0707;
            color: #ffffff;
        }
        .modal-warning-footer .btn-proceed-danger:hover {
            background: #fc2f2f;
        }
    </style>
</head>
<body>

    <?php if (!empty($message)): ?>
        <div id="statusModal" class="modal-overlay">
            <div class="modal-box <?php echo $message_type; ?>">
                <div class="modal-icon">
                    <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                </div>
                <div class="modal-message"><?php echo htmlspecialchars($message); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Custom Warning Modal for Missing Zones -->
    <div id="warningModal" class="modal-warning-overlay">
        <div class="modal-warning-box">
            <div class="modal-warning-header">
                <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
                <h2>Missing Zones Detected</h2>
            </div>
            <div class="modal-warning-body">
                <p>You are about to mark all zones as <strong>Reported</strong> for <strong><?php echo date('F Y', strtotime($selected_month . '-01')); ?></strong>.</p>
                
                <?php if (!empty($missing_zones)): ?>
                    <p><strong>The following zone(s) are NOT present in this report:</strong></p>
                    <div class="missing-zone-list">
                        <i class="fas fa-times-circle" style="color: #dc3545;"></i> 
                        <?php echo $missing_zones_string; ?>
                    </div>
                <?php endif; ?>
                
                <p><strong>Present zone(s):</strong></p>
                <div class="present-zone-list">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i> 
                    <?php echo $zone_list_string; ?>
                </div>
                
                <p style="margin-top: 15px; color: #535353; font-weight: 500;">
                    <i class="fas fa-info-circle"></i> Proceeding will mark all <?php echo $result ? $result->num_rows : 0; ?> zone(s) as reported.
                </p>
            </div>
            <div class="modal-warning-footer">
                <button class="btn-cancel" onclick="closeWarningModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn-proceed-danger" onclick="proceedWithReport()">
                    <i class="fas fa-check"></i> Proceed Anyway
                </button>
            </div>
        </div>
    </div>

    <!-- Custom Confirmation Modal for All Zones Present -->
    <div id="confirmModal" class="modal-warning-overlay">
        <div class="modal-warning-box">
            <div class="modal-warning-header">
                <span class="icon-success"><i class="fas fa-check-circle"></i></span>
                <h2>Mark Transaction as Reported</h2>
            </div>
            <div class="modal-warning-body">
                <p>Are you sure you want to mark <strong>ALL</strong> zones as <strong>Reported</strong> for <strong><?php echo date('F Y', strtotime($selected_month . '-01')); ?></strong>?</p>
                
                <p><strong>Zones to be marked:</strong></p>
                <div class="zone-list">
                    <i class="fas fa-check-circle" style="color: #28a745;"></i> 
                    <?php echo $zone_list_string; ?>
                </div>
                
                <p style="margin-top: 15px; color: #535353; font-weight: 500;">
                    <i class="fas fa-info-circle"></i> This will mark all <?php echo $result ? $result->num_rows : 0; ?> zone(s) as reported.
                </p>
                
             
            </div>
            <div class="modal-warning-footer">
                <button class="btn-cancel" onclick="closeConfirmModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn-proceed" onclick="proceedWithConfirm()">
                    <i class="fas fa-check"></i> Yes, Proceed
                </button>
            </div>
        </div>
    </div>

    <main class="main-content">
        <header class="top-bar">
            <h2><a href="user_dashboard.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge" style="font-weight: bold;">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-header">
                <h1 style="font-size: 20px; text-align: center;"> Mark as Reported</h1>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <label for="transaction_month"><i class="fas fa-calendar"></i> Transaction Month</label>
                        <input 
                            type="month" 
                            name="transaction_month" 
                            id="transaction_month" 
                            value="<?php echo htmlspecialchars($selected_month); ?>"
                            required
                            max="<?php echo date('Y-m'); ?>"
                        >
                    </div>
                    
                    <div class="filter-group action-buttons">
                        <button type="submit"><i class="fas fa-filter"></i> Apply Filter</button>
                        <a href="mark_reported.php" class="reset-btn" style="padding: 8px 20px; text-decoration: none; color: white; border-radius: 4px;"><i class="fa-solid fa-rotate"></i> Clear</a>
                    </div>
                </form>
            </div>

            <!-- Zone Status Indicator -->
            <div class="zone-status-indicator" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #dee2e6;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <?php foreach ($required_zones as $zone): ?>
                            <span style="display: flex; align-items: center; gap: 5px;">
                                <?php if (in_array($zone, $present_zones)): ?>
                                    <i class="fas fa-check-circle" style="color: #28a745;"></i>
                                    <span style="font-weight: 600; color: #28a745;"><?php echo $zone; ?></span>
                                    <span style="color: #6c757d; font-size: 12px;">✓</span>
                                <?php else: ?>
                                    <i class="fas fa-times-circle" style="color: #dc3545;"></i>
                                    <span style="font-weight: 600; color: #dc3545;"><?php echo $zone; ?></span>
                                    <span style="color: #dc3545; font-size: 12px;">✗</span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <?php if ($result->num_rows > 0): ?>
                            <!-- <span style="background: #e9ecef; color: #495057; padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                                <i class="fas fa-info-circle"></i> <?php echo count($present_zones); ?>/4 Zones Present
                            </span> -->
                        <?php else: ?>
                            <span style="background: #6c757d; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                                <i class="fas fa-info-circle"></i> No Data
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <?php 
            $total_records = 0;
            $unique_mainzones = [];
            $unique_zones = [];
            $reported_count = 0;
            $pending_count = 0;
            
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0);
                while ($row = $result->fetch_assoc()) {
                    $total_records += $row['record_count'];
                    $unique_mainzones[$row['mainzone']] = true;
                    $unique_zones[$row['zone']] = true;
                    
                    if ($row['reported_status'] === 'Reported') {
                        $reported_count++;
                    } else {
                        $pending_count++;
                    }
                }
                $result->data_seek(0);
            }
            ?>
            
            <div class="summary-stats">
                <div class="stat-item">
                    <div class="stat-label">Total Records</div>
                    <div class="stat-value"><?php echo number_format($total_records); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Main Zones</div>
                    <div class="stat-value"><?php echo count($unique_mainzones); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Zones</div>
                    <div class="stat-value"><?php echo count($unique_zones); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Reported Groups</div>
                    <div class="stat-value" style="color: #28a745;"><?php echo $reported_count; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Pending Groups</div>
                    <div class="stat-value" style="color: #ffc107;"><?php echo $pending_count; ?></div>
                </div>
            </div>

            <form method="POST" id="actionForm">
                <div class="action-buttons-container">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span style="font-size: 14px; color: #6c757d;">
                            <i class="fas fa-info-circle"></i> 
                            <?php if ($result && $result->num_rows > 0): ?>
                                Marking as reported will update all <?php echo $result->num_rows; ?> zone(s) for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>
                            <?php else: ?>
                                No data available for this month
                            <?php endif; ?>
                        </span>
                    </div>
                    <button type="button" name="action_type" class="btn-report" 
                            onclick="handleReportClick()"
                            <?php echo ($result->num_rows == 0 || $all_reported_status) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''; ?>>
                        <i class="fas fa-check-circle"></i> Mark as Reported
                    </button>
                    <a href="unlock_period.php" class="btn-unlock" style="text-decoration: none;"><i class="fa-solid fa-ban"></i> Unvoid</a>

                    <input type="hidden" id="action_type_hidden" name="action_type" value="">
                </div>

                <?php if ($all_reported_status && $result->num_rows > 0): ?>
                    <div style="background: #d4edda; border: 1px solid #28a745; color: #155724; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px;">
                        <i class="fas fa-check-circle"></i> 
                        <strong>Notice:</strong> All zones are already marked as reported for this month.
                    </div>
                <?php endif; ?>

                <?php if ($result->num_rows == 0): ?>
                    <div style="background: #f8d7da; border: 1px solid #dc3545; color: #721c24; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Notice:</strong> No data available to mark as reported.
                    </div>
                <?php endif; ?>

                <!-- Data Table -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fa-solid fa-sitemap"></i> Main Zone</th>
                                <th><i class="fa-solid fa-map-location-dot"></i> Zone</th>
                                <th style="text-align: center;"><i class="fa-solid fa-hashtag"></i> Row Count</th>
                                <th><i class="fa-solid fa-circle-check"></i> Reported Status</th>
                                <th><i class="fa-solid fa-user-check"></i> Reported By / Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td style="text-align: center;"><span class="badge badge-primary"><?php echo htmlspecialchars($row['mainzone'] ?: 'N/A'); ?></span></td>
                                        <td><strong><?php echo htmlspecialchars($row['zone'] ?: 'N/A'); ?></strong></td>
                                        <td style="text-align: center;">
                                            <span class="badge badge-info" style="font-size: 14px;">
                                                <?php echo number_format($row['record_count']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($row['reported_status'] === 'Reported'): ?>
                                                <span class="badge-reported" style="background: #28a745; color: white; padding: 5px 12px; border-radius: 20px; font-weight: bold; font-size: 12px;">
                                                    <i class="fas fa-check-circle"></i> Reported
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #3f3f3f; font-style:italic; font-size: 14px;">
                                                    —
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['reported_status_by']) && !empty($row['reported_status_date'])): ?>
                                                <span class="audit-info">
                                                    <i class="fas fa-user-check"></i> <?php echo htmlspecialchars($row['reported_status_by']); ?><br>
                                                    <small><?php echo date('M d, Y h:i A', strtotime($row['reported_status_date'])); ?></small>
                                                </span>
                                            <?php else: ?>
                                                <span class="audit-info text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="no-data">
                                        <i class="fas fa-inbox" style="font-size: 48px; color: #dee2e6; margin-bottom: 10px;"></i>
                                        <br>
                                        No uploaded reports found for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Auto-hide modal after 3 seconds and redirect
        <?php if (!empty($message)): ?>
        setTimeout(function() {
            const modal = document.getElementById('statusModal');
            if (modal) {
                modal.style.transition = 'opacity 0.5s ease';
                modal.style.opacity = '0';
                setTimeout(function() {
                    window.location.href = window.location.pathname + window.location.search;
                }, 500);
            }
        }, 3000);
        <?php endif; ?>
        
        // Month validation
        document.getElementById('transaction_month').addEventListener('change', function() {
            const selectedDate = new Date(this.value + '-01');
            const today = new Date();
            if (selectedDate > today) {
                alert('Please select a month that is not in the future.');
                this.value = '<?php echo date('Y-m'); ?>';
            }
        });

        // Handle Report Click - Check for missing zones
        function handleReportClick() {
            const missingZones = <?php echo json_encode($missing_zones); ?>;
            const allZonesPresent = <?php echo $all_zones_present ? 'true' : 'false'; ?>;
            const zoneCount = <?php echo $result ? $result->num_rows : 0; ?>;
            const monthName = '<?php echo date('F Y', strtotime($selected_month . '-01')); ?>';
            
            if (zoneCount === 0) {
                alert('No data available to mark as reported.');
                return false;
            }
            
            <?php if ($all_reported_status): ?>
                alert('All zones are already marked as reported for ' + monthName + '.');
                return false;
            <?php endif; ?>
            
            // If not all zones are present, show the warning modal
            if (!allZonesPresent && missingZones.length > 0) {
                showWarningModal();
            } else {
                // All zones present, show custom confirmation modal
                showConfirmModal();
            }
        }

        // Show the warning modal (for missing zones)
        function showWarningModal() {
            document.getElementById('warningModal').classList.add('active');
        }

        // Close the warning modal
        function closeWarningModal() {
            document.getElementById('warningModal').classList.remove('active');
        }

        // Show the confirmation modal (for all zones present)
        function showConfirmModal() {
            document.getElementById('confirmModal').classList.add('active');
        }

        // Close the confirmation modal
        function closeConfirmModal() {
            document.getElementById('confirmModal').classList.remove('active');
        }

        // Proceed with report despite missing zones
        function proceedWithReport() {
            closeWarningModal();
            // Directly submit the form - the warning modal already provided all necessary information
            document.getElementById('action_type_hidden').value = 'report';
            document.getElementById('actionForm').submit();
        }

        // Proceed with report when all zones are present
        function proceedWithConfirm() {
            closeConfirmModal();
            document.getElementById('action_type_hidden').value = 'report';
            document.getElementById('actionForm').submit();
        }

        // Close modals when clicking outside
        document.getElementById('warningModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeWarningModal();
            }
        });

        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeConfirmModal();
            }
        });
    </script>

<?php include '../footer.php'; ?>
    
</body>
</html>
<?php 
if (isset($stmt)) $stmt->close();
if (isset($conn)) $conn->close(); 
?>