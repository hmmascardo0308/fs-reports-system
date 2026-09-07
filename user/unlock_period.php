<?php
// unlock_period.php
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

$username  = $_SESSION['username'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

// Get filter parameters
$selected_month = isset($_GET['transaction_month']) ? $_GET['transaction_month'] : date('Y-m');
$search_region = isset($_GET['search_region']) ? trim($_GET['search_region']) : '';
$message = '';
$message_type = '';

// Handle Unvoid Action - Show modal instead
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type']) && $_POST['action_type'] === 'unvoid') {
    // Just show the modal - no actual database changes
    $show_unvoid_modal = true;
}

// Validate date format (should be YYYY-MM)
if ($selected_month && !preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

// Optimize Date Filter: Use BETWEEN instead of DATE_FORMAT to utilize indexes
$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Fetch filtered data with lock/unlock information
$query = "SELECT 
            region,
            mainzone, 
            zone, 
            transaction_type,
            uploaded_by, 
            uploaded_date,
            COUNT(*) as record_count,
            MAX(status_void) as status_void,
            MAX(locked_by) as locked_by,
            MAX(locked_date) as locked_date,
            MAX(unlock_by) as unlock_by,
            MAX(unlock_date) as unlock_date,
            MAX(voided_by) as voided_by,
            MAX(voided_at) as voided_at,
            MAX(reported_status) as reported_status,
            MAX(reported_status_by) as reported_status_by,
            MAX(reported_status_date) as reported_status_date,
            COUNT(DISTINCT CASE WHEN status_void = 'Void' THEN CONCAT(region, '|', mainzone, '|', zone) END) as void_distinct_count
          FROM comparative_report 
          WHERE transaction_month BETWEEN ? AND ?";

if (!empty($search_region)) {
    $query .= " AND region LIKE ?";
    $query .= " GROUP BY region, mainzone, zone, transaction_type, uploaded_by, uploaded_date ORDER BY mainzone, zone, region,uploaded_by, uploaded_date";
    $stmt = $conn->prepare($query);
    $search_param = "%" . $search_region . "%";
    $stmt->bind_param("sss", $start_date, $end_date, $search_param);
} else {
    $query .= " GROUP BY region, mainzone, zone, transaction_type, uploaded_by,uploaded_date ORDER BY mainzone, zone, region,uploaded_by, uploaded_date";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $start_date, $end_date);
}

$stmt->execute();
$result = $stmt->get_result();

// Get general reported status info
$general_reported_status = null;
$general_reported_by = null;
$general_reported_date = null;

if ($result && $result->num_rows > 0) {
    // Get the first row to check reported status (assuming all rows have same reported status)
    $first_row = $result->fetch_assoc();
    $general_reported_status = $first_row['reported_status'] ?? null;
    $general_reported_by = $first_row['reported_status_by'] ?? null;
    $general_reported_date = $first_row['reported_status_date'] ?? null;
    
    // Reset pointer to beginning
    $result->data_seek(0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Financial Report Status</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link rel="stylesheet" href="css/lock_unlock.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        .badge-branch {
            background-color: #e0f2fe;
            color: #003450;
            border: 1px solid #00456b;
        }
        .badge-showroom {
            background-color: #fef3c7;
            color: #702b00;
            border: 1px solid #6c5600;
        }
        .badge-reported {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #28a745;
            border-radius: 4px;
            padding: 8px;
            font-size: 12px;
        }
        .badge-not-reported {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #dc3545;
            border-radius: 4px;
            padding: 8px;
            font-size: 12px;
        }
        .badge-reported-blocked {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
        }
        .badge-void {
            background-color: #ffffff;
            color: #721c24;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-void-count {
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 11px;
            margin-left: 4px;
        }
        .void-row {
            background-color: #fff5f5 !important;
        }
        .audit-info {
            font-size: 12px;
            line-height: 1.4;
        }
        .audit-info i {
            margin-right: 3px;
        }
        .text-muted {
            color: #6c757d;
        }
        .general-info-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px 20px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .general-info-box .info-label {
            font-weight: 600;
            color: #495057;
            margin-right: 5px;
        }
        .general-info-box .info-value {
            color: #212529;
        }
        /* Info note styling */
        .info-note {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 8px 18px;
            margin-bottom: 10px;
            border-radius: 4px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            color: #004080;
        }
        .info-note i {
            font-size: 18px;
            color: #0066cc;
            margin-top: 2px;
        }
        .info-note strong {
            color: #003366;
        }

        /* Unvoid Modal Styles */
        .unvoid-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }
        .unvoid-modal-box {
            background: white;
            border-radius: 12px;
            padding: 35px 40px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
            from {
                transform: translateY(-30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .unvoid-modal-icon {
            font-size: 64px;
            color: #696767;
            margin-bottom: 15px;
        }
        .unvoid-modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 12px;
        }
        .unvoid-modal-message {
            font-size: 16px;
            color: #4a4a6a;
            line-height: 1.7;
            margin-bottom: 25px;
            text-align: left;
        }
        .unvoid-modal-message ul {
            margin: 12px 0;
            padding-left: 20px;
        }
        .unvoid-modal-message li {
            margin-bottom: 6px;
        }
        .unvoid-modal-close-btn {
            background: #ee4343;
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .unvoid-modal-close-btn:hover {
            background: #8a0909;
        }
        .unvoid-modal-close-btn i {
            margin-right: 8px;
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

    <!-- Unvoid Information Modal -->
    <?php if (isset($show_unvoid_modal) && $show_unvoid_modal): ?>
    <div id="unvoidModal" class="unvoid-modal-overlay">
        <div class="unvoid-modal-box">
            <div class="unvoid-modal-icon">
                <i class="fas fa-upload"></i>
            </div>
            <div class="unvoid-modal-title">Data Upload Information</div>
            <div class="unvoid-modal-message">
                <p><strong>How Void Status Works:</strong></p>
                <ul>
                    <li><strong>Uploading new data</strong> for the same Region - Area will <strong>automatically void</strong> the previous data.</li>
                    <li>The previous data will be marked as <strong style="color: #dc3545;">"Void"</strong> and replaced with the new upload.</li>
                    <li>Voided data are <strong>not deleted</strong>.</li>
                    <li>To submit updated data, simply <strong>upload a new file</strong> for the same period and region.</li>
                </ul>
                <p style="margin-top: 12px; font-size: 14px; color: #666;">
                    <i class="fas fa-info-circle" style="color: #b30000;"></i>
                    The system automatically handles data versioning with each upload.
                </p>
            </div>
            <button class="unvoid-modal-close-btn" onclick="closeUnvoidModal()">
                <i class="fas fa-check"></i> Got It
            </button>
        </div>
    </div>
    <?php endif; ?>

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
                <h1 style="font-size: 20px; text-align: center;"> Monthly Financial Report Status</h1>
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
                    
                    <div class="filter-group">
                        <label for="search_region"><i class="fas fa-search"></i> Search Region</label>
                        <input 
                            type="text" 
                            name="search_region" 
                            id="search_region" 
                            value="<?php echo htmlspecialchars($search_region); ?>" 
                            placeholder="All Region..."
                        >
                    </div>

                    <div class="filter-group action-buttons">
                        <button type="submit"><i class="fas fa-filter"></i> Apply Filter</button>
                        <a href="unlock_period.php" class="reset-btn" style="padding: 8px 20px; text-decoration: none; color: white; border-radius: 4px;"><i class="fa-solid fa-rotate"></i> Clear</a>
                    </div>
                </form>
            </div>

            <!-- Info Note about Void Status -->
            <?php 
            $has_void_records = false;
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0);
                while ($row = $result->fetch_assoc()) {
                    if ($row['status_void'] === 'Void') {
                        $has_void_records = true;
                        break;
                    }
                }
                $result->data_seek(0);
            }
            
            if ($has_void_records):
            ?>
            <div class="info-note">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Note:</strong> Void statuses indicate previous data replaced by newly uploaded data for the same region or region-area.
                </div>
            </div>
            <?php endif; ?>

            <!-- General Reported Status Info -->
            <?php if ($result && $result->num_rows > 0): ?>
            <div class="general-info-box">
                <div>
                    <span class="info-label"><i class="fas fa-flag"></i> Status:</span>
                    <span class="info-value">
                        <?php if ($general_reported_status === 'Reported'): ?>
                            <span class="badge-reported"><i class="fas fa-check-circle"></i> Reported</span>
                        <?php else: ?>
                            <span class="badge-not-reported"><i class="fas fa-times-circle"></i> Not Reported</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($general_reported_by) && !empty($general_reported_date)): ?>
                <div>
                    <span class="info-label"><i class="fas fa-user-check"></i> Reported By:</span>
                    <span class="info-value"><?php echo htmlspecialchars($general_reported_by); ?></span>
                </div>
                <div>
                    <span class="info-label"><i class="fas fa-calendar-alt"></i> Reported Date:</span>
                    <span class="info-value"><?php echo date('F d, Y h:i A', strtotime($general_reported_date)); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Summary Statistics -->
            <?php 
            $total_records = 0;
            $unique_regions = [];
            $unique_mainzones = [];
            $unique_zones = [];
            $unique_types = [];
            $reported_count = 0;
            $not_reported_count = 0;
            $total_void_count = 0;
            
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0);
                while ($row = $result->fetch_assoc()) {
                    $total_records += $row['record_count'];
                    $unique_regions[$row['region']] = true;
                    $unique_mainzones[$row['mainzone']] = true;
                    $unique_zones[$row['zone']] = true;
                    $unique_types[$row['transaction_type']] = true;
                    
                    if ($row['reported_status'] === 'Reported') {
                        $reported_count++;
                    } else {
                        $not_reported_count++;
                    }
                    
                    if ($row['status_void'] === 'Void') {
                        $total_void_count++;
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
                    <div class="stat-label">Regions</div>
                    <div class="stat-value"><?php echo count($unique_regions); ?></div>
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
                    <div class="stat-label">Branch Types</div>
                    <div class="stat-value"><?php echo count($unique_types); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Voided Groups (Area)</div>
                    <div class="stat-value" style="color: #dc3545;"><?php echo $total_void_count; ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Reported Groups</div>
                    <div class="stat-value" style="color: #155724;"><?php echo $reported_count; ?></div>
                </div>
            </div>

            <form method="POST" id="actionForm">
                <div class="action-buttons-container">
                    <a href="mark_reported.php" class="btn-report" style="text-decoration: none;"><i class="fas fa-check-circle"></i> Mark as Reported</a>

                    <button type="submit" name="action_type" value="unvoid" class="btn-unlock" onclick="return confirmUnvoid()">
                        <i class="fa-solid fa-ban"></i> Unvoid
                    </button>
                </div>
                <!-- Data Table -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fa-solid fa-layer-group"></i> Main Zone</th>
                                <th><i class="fa-solid fa-map"></i> Zone</th>
                                <th><i class="fa-solid fa-earth-asia"></i> Region</th>
                                <th><i class="fa-solid fa-code-branch"></i> Branch Type</th>
                                <th><i class="fa-solid fa-calendar-days"></i> Uploaded Date</th>
                                <th><i class="fa-solid fa-database"></i> Record Count</th>
                                <th><i class="fa-solid fa-user"></i> Uploaded By</th>
                                <th><i class="fa-solid fa-list-check"></i> Void Status</th>
                                <th><i class="fa-solid fa-user-slash"></i> Voided By / Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): 
                                    $is_reported = ($row['reported_status'] === 'Reported');
                                    $is_void = ($row['status_void'] === 'Void');
                                    $void_distinct_count = $row['void_distinct_count'] ?? 0;
                                ?>
                                    <tr class="<?php echo $is_reported ? 'reported-row' : ''; ?> <?php echo $is_void ? 'void-row' : ''; ?>">
                                        <td style="text-align: center;"><span class="badge badge-primary"><?php echo htmlspecialchars($row['mainzone'] ?: 'N/A'); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['zone'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['region'] ?: 'N/A'); ?></td>
                                        <td>
                                            <?php 
                                            $type = $row['transaction_type'] ?? '';
                                            $type_class = ($type === 'Branch') ? 'badge-branch' : (($type === 'Showroom') ? 'badge-showroom' : '');
                                            ?>
                                            <span class="badge <?= $type_class ?>"><?= htmlspecialchars($type ?: 'N/A') ?></span>
                                        </td>
                                        <td><?php echo date('F d, Y h:i:s A', strtotime($row['uploaded_date'])); ?></td>
                                        <td style="text-align: center;"><strong><?php echo number_format($row['record_count']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['uploaded_by'] ?: 'N/A'); ?></td>

                                        <td style="text-align: center;">
                                            <?php if ($is_void): ?>
                                                <span class="badge-void">
                                                    <i class="fas fa-times-circle"></i> Void
                                                    <?php if ($void_distinct_count > 0): ?>
                                                        <span class="badge-void-count"><?php echo $void_distinct_count; ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #6c757d;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['voided_by']) && !empty($row['voided_at'])): ?>
                                                <span class="audit-info">
                                                    <i class="fas fa-user-slash"></i> <?php echo htmlspecialchars($row['voided_by']); ?><br>
                                                    <small><?php echo date('M d, Y h:i A', strtotime($row['voided_at'])); ?></small>
                                                </span>
                                            <?php else: ?>
                                                <span class="audit-info text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="no-data">
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
                setTimeout(() => {
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

        // Close Unvoid Modal
        function closeUnvoidModal() {
            const modal = document.getElementById('unvoidModal');
            if (modal) {
                modal.style.transition = 'opacity 0.3s ease';
                modal.style.opacity = '0';
                setTimeout(() => {
                    window.location.href = window.location.pathname + window.location.search;
                }, 300);
            }
        }

        // Confirm Unvoid - Show modal instead of actual action
        function confirmUnvoid() {
            // Just submit the form to show the modal
            return true;
        }

        // Close modal on click outside
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('unvoidModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeUnvoidModal();
                    }
                });
            }
        });
    </script>
<?php include '../footer.php'; ?>
    
</body>
</html>
<?php 
if (isset($stmt)) $stmt->close();
$conn->close(); 
?>