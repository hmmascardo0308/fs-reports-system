<?php
// lock_period.php
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

// Validate date format (should be YYYY-MM)
if ($selected_month && !preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

// Optimize Date Filter: Use BETWEEN instead of DATE_FORMAT to utilize indexes
$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Fetch filtered data with void and reported information
$query = "SELECT 
            mainzone, 
            zone, 
            transaction_type, 
            MAX(uploaded_date) as uploaded_date,
            COUNT(*) as record_count,
            COUNT(DISTINCT CASE WHEN status_void = 'Void' THEN CONCAT(region, '-', area) END) as void_region_count,
            GROUP_CONCAT(DISTINCT CASE WHEN status_void = 'Void' THEN CONCAT(region, '-', area) END SEPARATOR ', ') as void_details,
            MAX(voided_by) as voided_by,
            MAX(voided_at) as voided_at,
            MAX(reported_status) as reported_status,
            MAX(reported_status_by) as reported_status_by,
            MAX(reported_status_date) as reported_status_date
          FROM comparative_report 
          WHERE transaction_month BETWEEN ? AND ?
          GROUP BY mainzone, zone, transaction_type
          ORDER BY mainzone, zone";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $start_date, $end_date);
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
        /* Note styling */
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

        /* General Info Box */
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
    </style>
</head>
<body>

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
                    
                    <div class="filter-group action-buttons">
                        <button type="submit"><i class="fas fa-filter"></i> Apply Filter</button>
                        <a href="lock_period.php" class="reset-btn" style="padding: 8px 20px; text-decoration: none; color: white; border-radius: 4px;"><i class="fa-solid fa-rotate"></i> Clear</a>
                    </div>
                </form>
            </div>

            <!-- Info Note about Void Status -->
            <?php 
            $has_void_records = false;
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0);
                while ($row = $result->fetch_assoc()) {
                    if ($row['void_region_count'] > 0) {
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
            $unique_mainzones = [];
            $unique_zones = [];
            $unique_types = [];
            $total_void_region_areas = 0;
            $groups_with_void = 0;
            
            if ($result && $result->num_rows > 0) {
                $result->data_seek(0); // Reset pointer
                while ($row = $result->fetch_assoc()) {
                    $total_records += $row['record_count'];
                    $unique_mainzones[$row['mainzone']] = true;
                    $unique_zones[$row['zone']] = true;
                    $unique_types[$row['transaction_type']] = true;
                    
                    // Count void region-areas
                    if ($row['void_region_count'] > 0) {
                        $total_void_region_areas += $row['void_region_count'];
                        $groups_with_void++;
                    }
                }
                $result->data_seek(0); // Reset pointer again for display
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
                    <div class="stat-label">Branch Types</div>
                    <div class="stat-value"><?php echo count($unique_types); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Void Region-Areas</div>
                    <div class="stat-value" style="color: #dc3545;"><?php echo number_format($total_void_region_areas); ?></div>
                </div>
            </div>


            <div class="action-buttons-container">
                    <a href="mark_reported.php" class="btn-report" style="text-decoration: none;"><i class="fas fa-check-circle"></i> Mark as Reported</a>
                    <a href="unlock_period.php" class="btn-unlock" style="text-decoration: none;"><i class="fa-solid fa-ban"></i> Unvoid</a>

                </div>

            <!-- Data Table -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><i class="fa-solid fa-sitemap"></i> Main Zone</th>
                            <th><i class="fa-solid fa-map-location-dot"></i> Zone</th>
                            <th><i class="fa-solid fa-building"></i> Branch Type</th>
                            <th><i class="fa-solid fa-upload"></i> Uploaded Date</th>
                            <th><i class="fa-solid fa-table-list"></i> Record Count</th>
                            <th><i class="fa-solid fa-clipboard-check"></i> Void Status</th>
                            <th><i class="fa-solid fa-ban"></i> Voided By / Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td style="text-align: center;"><span class="badge badge-primary"><?php echo htmlspecialchars($row['mainzone'] ?: 'N/A'); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['zone'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['transaction_type'] ?: 'N/A'); ?></td>
                                    <td><?php echo date('F d, Y h:i:s A', strtotime($row['uploaded_date'])); ?></td>
                                    <td style="text-align: center;"><strong><?php echo number_format($row['record_count']); ?></strong></td>
                                    <td style="text-align: center;">
                                        <?php 
                                        if ($row['void_region_count'] > 0) {
                                            echo '<span class="badge-void" style="color: red; font-weight: bold;">Void Region - Area: ' . $row['void_region_count'] . '</span>';
                                        } else {
                                            echo '<span>-</span>';
                                        }
                                        ?>
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
                                <td colspan="7" class="no-data">
                                    <i class="fas fa-inbox" style="font-size: 48px; color: #dee2e6; margin-bottom: 10px;"></i>
                                    <br>
                                    No uploaded reports found for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        // Month validation
        document.getElementById('transaction_month').addEventListener('change', function() {
            const selectedDate = new Date(this.value + '-01');
            const today = new Date();
            if (selectedDate > today) {
                alert('Please select a month that is not in the future.');
                this.value = '<?php echo date('Y-m'); ?>';
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