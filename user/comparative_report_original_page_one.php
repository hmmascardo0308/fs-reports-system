<?php

// comparative_report_original_page_one.php
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

$username  = $_SESSION['username'] ?? "unknown";
$full_name = $_SESSION['full_name'] ?? "unknown";
$user_type = $_SESSION['user_type'] ?? "unknown";

// Filters
$transaction_month = $_GET['transaction_month'] ?? ''; // format: YYYY-MM
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old'; // old|new
$gl_code_mode = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

// Helper function to check if a month is March 2026 or earlier
function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-03-01');
    $month_time = strtotime($month . '-01');
    return $month_time <= $cutoff;
}

// Helper function to check if a month is April 2026 or later
function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-04-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

// Validate GL code mode against the selected month
$show_error = false;
$valid_filters = false;
$error_message = '';

if (!empty($transaction_month)) {
    if ($gl_code_mode === 'old' && !isMarch2026OrEarlier($transaction_month)) {
        $error_message = 'Old GL Code is only available for March 2026 and earlier. The selected month must be March 2026 or earlier.';
        $show_error = true;
    } elseif ($gl_code_mode === 'new' && !isApril2026OrLater($transaction_month)) {
        $error_message = 'New GL Code is only available for April 2026 onwards. The selected month must be April 2026 or later.';
        $show_error = true;
    }

    if (!$show_error) {
        $valid_filters = true;
    }
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: comparative_report_original_page_one.php");
    exit;
}

// ============================================================
// GET GL MAPPING based on GL Code Mode
// ============================================================
$gl_mapping = []; 
$gl_descriptions = []; 

// Define codes to exclude based on mode
$exclude_code = ($gl_code_mode === 'old') ? '6000001' : '611101';

// Determine which table to use for report structure
$table_name = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes' : 'fs_reports.new_gl_codes';

$gl_structure_query = "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, gl_description_comparative, description
    FROM {$table_name}
    WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";

$gl_structure_result = mysqli_query($conn, $gl_structure_query);
if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];
        $code = trim((string)($row['gl_code'] ?? ''));

        // Skip excluded codes
        if ($code === $exclude_code) {
            continue;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = ['codes' => []];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }

        if ($code !== '' && !in_array($code, $gl_mapping[$key]['codes'], true)) {
            $gl_mapping[$key]['codes'][] = $code;
        }
    }
}

// ============================================================
// COMPUTE TABLE ROWS FOR THE SELECTED TRANSACTION MONTH
// ============================================================
function compute_table_rows(
    mysqli $conn,
    string $transaction_month,
    array $gl_mapping,
    bool $use_real_data = true
): array {
    // ============================================================
    // FETCH PERIOD DATA (single month, split by mainzone and type)
    // ============================================================
    $period_data = []; 
    if ($use_real_data && !empty($transaction_month)) {
        $parts = explode('-', $transaction_month);
        $year_val = $parts[0];
        $month_val = $transaction_month . '-01'; 

        $sql = "
            SELECT
                gl_code,
                SUM(CASE WHEN mainzone = 'LNCR' AND transaction_type = 'Branch' THEN amount ELSE 0 END) as lncr_mlfsi,
                SUM(CASE WHEN mainzone = 'VISMIN' AND transaction_type = 'Branch' THEN amount ELSE 0 END) as vismin_mlfsi,
                SUM(CASE WHEN transaction_type = 'Showroom' THEN amount ELSE 0 END) as jewelers_nw
            FROM fs_reports.comparative_report
            WHERE transaction_year = ? AND transaction_month = ?
              AND (status_void IS NULL OR status_void != 'Void')
              AND gl_code IS NOT NULL AND gl_code != ''
            GROUP BY gl_code
        ";

        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ss", $year_val, $month_val);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $period_data[$row['gl_code']] = [
                    'lncr_mlfsi' => floatval($row['lncr_mlfsi']),
                    'vismin_mlfsi' => floatval($row['vismin_mlfsi']),
                    'jewelers_nw' => floatval($row['jewelers_nw'])
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Helper to sum amounts for a list of keys (e.g., ['1|1', '2|1'])
    $sum_keys = function(array $keys) use ($gl_mapping, $period_data) {
        $lncr = 0; $vismin = 0; $jew = 0;
        foreach ($keys as $key) {
            if (isset($gl_mapping[$key])) {
                foreach ($gl_mapping[$key]['codes'] as $code) {
                    if (isset($period_data[$code])) {
                        $lncr += $period_data[$code]['lncr_mlfsi'];
                        $vismin += $period_data[$code]['vismin_mlfsi'];
                        $jew += $period_data[$code]['jewelers_nw'];
                    }
                }
            }
        }
        return ['lncr' => $lncr, 'vismin' => $vismin, 'jew' => $jew];
    };

    // Helper to sum amounts for all keys starting with a specific sort_order prefix (e.g., '1|', '2|')
    $sum_prefix = function(string $prefix) use ($gl_mapping, $period_data) {
        $lncr = 0; $vismin = 0; $jew = 0;
        foreach ($gl_mapping as $key => $data) {
            if (strpos($key, $prefix) === 0) {
                foreach ($data['codes'] as $code) {
                    if (isset($period_data[$code])) {
                        $lncr += $period_data[$code]['lncr_mlfsi'];
                        $vismin += $period_data[$code]['vismin_mlfsi'];
                        $jew += $period_data[$code]['jewelers_nw'];
                    }
                }
            }
        }
        return ['lncr' => $lncr, 'vismin' => $vismin, 'jew' => $jew];
    };

    // ============================================================
    // CALCULATE VALUES BASED ON REQUESTED LAYOUT
    // ============================================================
    
    // REVENUES (sort_order 1 to 20)
    $rev_keys = [];
    for ($i = 1; $i <= 20; $i++) {
        foreach (array_keys($gl_mapping) as $k) {
            if (strpos($k, "{$i}|") === 0) $rev_keys[] = $k;
        }
    }
    $rev = $sum_keys($rev_keys);

    // Cost of Sales/Service (sort_order 21)
    $cos = $sum_prefix('21|');

    // GROSS PROFIT (Revenues - Cost of Sales)
    $gp_lncr = $rev['lncr'] - $cos['lncr'];
    $gp_vismin = $rev['vismin'] - $cos['vismin'];
    $gp_jew = $rev['jew'] - $cos['jew'];

    // SELLING & ADMIN EXPENSES (sort_order 22 and 23)
    $sa_22 = $sum_prefix('22|');
    $sa_23 = $sum_prefix('23|');
    $sa_lncr = $sa_22['lncr'] + $sa_23['lncr'];
    $sa_vismin = $sa_22['vismin'] + $sa_23['vismin'];
    $sa_jew = $sa_22['jew'] + $sa_23['jew'];

    // EBITDA (Gross Profit - Total Selling & Admin)
    $ebitda_lncr = $gp_lncr - $sa_lncr;
    $ebitda_vismin = $gp_vismin - $sa_vismin;
    $ebitda_jew = $gp_jew - $sa_jew;

    // Depreciation (24|1) and Amortization (24|2)
    $dep = $sum_keys(['24|1']);
    $amort = $sum_keys(['24|2']);

    // EBIT (EBITDA - Depreciation - Amortization)
    $ebit_lncr = $ebitda_lncr - $dep['lncr'] - $amort['lncr'];
    $ebit_vismin = $ebitda_vismin - $dep['vismin'] - $amort['vismin'];
    $ebit_jew = $ebitda_jew - $dep['jew'] - $amort['jew'];

    // Interest Expense (25|1)
    $interest = $sum_keys(['25|1']);

    // EBT (EBIT - Interest Expense)
    $ebt_lncr = $ebit_lncr - $interest['lncr'];
    $ebt_vismin = $ebit_vismin - $interest['vismin'];
    $ebt_jew = $ebit_jew - $interest['jew'];

    // Provision on Income Tax (26|1)
    $tax = $sum_keys(['26|1']);

    // NET INCOME (EBT - Tax)
    $net_lncr = $ebt_lncr - $tax['lncr'];
    $net_vismin = $ebt_vismin - $tax['vismin'];
    $net_jew = $ebt_jew - $tax['jew'];

    // ============================================================
    // BUILD FINAL ROWS
    // ============================================================
    $final_table_rows = [];

    $add_row = function($label, $is_header, $data = null) use (&$final_table_rows) {
        $final_table_rows[] = [
            'label' => $label,
            'is_section_header' => $is_header,
            'lncr' => $data ? $data['lncr'] : null,
            'vismin' => $data ? $data['vismin'] : null,
            'jew' => $data ? $data['jew'] : null,
            'is_summary_row' => true
        ];
    };

    $add_spacer = function() use (&$final_table_rows) {
        $final_table_rows[] = ['is_manual_spacer' => true];
    };

    // 1. TOTAL REVENUES
    $add_row('TOTAL REVENUES', false, $rev);

    // 2. Cost of Sales/Service
    $add_row('Cost of Sales/Service', false, $cos);

    $add_spacer();

    // 3. GROSS PROFIT
    $add_row('GROSS PROFIT', false, ['lncr' => $gp_lncr, 'vismin' => $gp_vismin, 'jew' => $gp_jew]);

    // 4. TOTAL SELLING AND ADMIN EXPENSES
    $add_row('TOTAL SELLING AND ADMIN EXPENSES', false, ['lncr' => $sa_lncr, 'vismin' => $sa_vismin, 'jew' => $sa_jew]);

    $add_spacer();

    // 5. EBITDA
    $add_row("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", false, ['lncr' => $ebitda_lncr, 'vismin' => $ebitda_vismin, 'jew' => $ebitda_jew]);

    // 6. Depreciation Expense
    $add_row('Depreciation Expense', false, $dep);

    // 7. Amortization Expense
    $add_row('Amortization Expense', false, $amort);

    $add_spacer();

    // 8. EBIT
    $add_row('EARNINGS BEFORE INTEREST & TAXES', false, ['lncr' => $ebit_lncr, 'vismin' => $ebit_vismin, 'jew' => $ebit_jew]);

    // 9. Interest Expense
    $add_row('Interest Expense', false, $interest);

    $add_spacer();

    // 10. EBT
    $add_row('EARNINGS BEFORE TAXES', false, ['lncr' => $ebt_lncr, 'vismin' => $ebt_vismin, 'jew' => $ebt_jew]);

    // 11. Provision on Income Tax
    $add_row('Provision on Income Tax', false, $tax);

    $add_spacer();

    // 12. NET INCOME
    $add_row('TOTAL NET INCOME/LOSS', false, ['lncr' => $net_lncr, 'vismin' => $net_vismin, 'jew' => $net_jew]);

    return $final_table_rows;
}

// Always build the table structure, even with empty/zero data
$table_rows = compute_table_rows(
    $conn,
    $transaction_month,
    $gl_mapping,
    $valid_filters
);

// Extract total revenues for percentage calculation base
$rev_lncr = 0;
$rev_vismin = 0;
$rev_jew = 0;
foreach ($table_rows as $r) {
    if (($r['label'] ?? '') === 'TOTAL REVENUES') {
        $rev_lncr = $r['lncr'];
        $rev_vismin = $r['vismin'];
        $rev_jew = $r['jew'];
        break;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Report Original Data</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/comparative_original.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Minor CSS tweak to ensure the % columns look good */
        .pct-cell {
            font-size: 0.85em;
            color: #555;
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="settings.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-title">PROFIT & LOSS STATEMENT (NATIONWIDE)</div>

            <!-- Error Banner for validation issues -->
            <?php if ($show_error && !empty($error_message)): ?>
                <div class="error-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <form method="GET" class="filter-form" id="filterForm" onsubmit="return validateForm()">
                <div class="filter-group filter-group--gl-mode">
                    <label>GL Code</label>
                    <div class="radio-group" role="radiogroup" aria-label="GL Code Mode">
                        <label class="radio-option">
                            <input type="radio" name="gl_code_mode" value="old" id="glOldRadio" <?= $gl_code_mode === 'old' ? 'checked' : '' ?>>
                            <span>Old GL Code</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="gl_code_mode" value="new" id="glNewRadio" <?= $gl_code_mode === 'new' ? 'checked' : '' ?>>
                            <span>New GL Code</span>
                        </label>
                    </div>
                </div>

                <div class="filter-group">
                    <label>Transaction Month</label>
                    <input type="month" name="transaction_month" id="transactionMonthSelect" value="<?= htmlspecialchars($transaction_month) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    <a href="export_comparative_page_two.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
                    <a href="?reset=1" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                </div>
            </form>

            <!-- Report Table -->
            <div class="region-block">
                <div class="tables-scroll">
                    <div class="tables-grid">
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th colspan="9">Period: <?php echo !empty($transaction_month) ? strtoupper(date('F Y', strtotime($transaction_month . '-01'))) : '(No Period Selected)'; ?></th>
                                    </tr>
                                    <tr>
                                        <th></th>
                                        <th></th>
                                        <th></th>
                                        <th>LUZON/NCR - MLFSI</th>
                                        <th>%</th>
                                        <th>VISMIN - MLFSI</th>
                                        <th>%</th>
                                        <th>JEWELERS - NW</th>
                                        <th>%</th>
                                    </tr>
                                </thead>
                                <tbody class="report-tbody">

                                <tr class="initial-spacer">
                                    <td colspan="9"></td>
                                </tr>
                                    <?php if (empty($table_rows)): ?>
                                        <tr>
                                            <td colspan="9" style="text-align: center;">No data structure available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($table_rows as $row):
                                            if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
                                                echo '<tr class="spacer-row" style="height: 20px;"><td colspan="9"></td></tr>';
                                                continue;
                                            }
                                            
                                            $is_header = !empty($row['is_section_header']);
                                            $label = $row['label'] ?? '';

                                            $lncr = $row['lncr'] ?? 0;
                                            $vismin = $row['vismin'] ?? 0;
                                            $jew = $row['jew'] ?? 0;
                                            
                                            // Calculate percentages based on TOTAL REVENUES
                                            $pct_lncr = $rev_lncr != 0 ? ($lncr / $rev_lncr) * 100 : 0;
                                            $pct_vismin = $rev_vismin != 0 ? ($vismin / $rev_vismin) * 100 : 0;
                                            $pct_jew = $rev_jew != 0 ? ($jew / $rev_jew) * 100 : 0;
                                        ?>
                                            <tr class="summary-row">
                                                <!-- Empty TD for alignment -->
                                                <td></td>
                                                
                                                <!-- Label spanning 2 cols (like sub_order + gl_description) -->
                                                <td colspan="2" style="text-align: left; font-weight: bold; padding-left: 15px;">
                                                    <?= htmlspecialchars($label) ?>
                                                </td>

                                                <td class="numeric-cell summary-cell" style="<?= ($lncr < 0) ? 'color: red;' : '' ?>">
                                                    <strong><?= $is_header ? '' : number_format($lncr, 2) ?></strong>
                                                </td>
                                                <td class="numeric-cell pct-cell summary-cell">
                                                    <?= $is_header ? '' : number_format($pct_lncr, 2) . '%' ?>
                                                </td>

                                                <td class="numeric-cell summary-cell" style="<?= ($vismin < 0) ? 'color: red;' : '' ?>">
                                                    <strong><?= $is_header ? '' : number_format($vismin, 2) ?></strong>
                                                </td>
                                                <td class="numeric-cell pct-cell summary-cell">
                                                    <?= $is_header ? '' : number_format($pct_vismin, 2) . '%' ?>
                                                </td>

                                                <td class="numeric-cell summary-cell" style="<?= ($jew < 0) ? 'color: red;' : '' ?>">
                                                    <strong><?= $is_header ? '' : number_format($jew, 2) ?></strong>
                                                </td>
                                                <td class="numeric-cell pct-cell summary-cell">
                                                    <?= $is_header ? '' : number_format($pct_jew, 2) . '%' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                // Helper to check if month is March 2026 or earlier
                function isMarch2026OrEarlier(month) {
                    if (!month) return true;
                    const cutoff = new Date('2026-03-01');
                    const monthDate = new Date(month + '-01');
                    return monthDate <= cutoff;
                }

                // Helper to check if month is April 2026 or later
                function isApril2026OrLater(month) {
                    if (!month) return true;
                    const cutoff = new Date('2026-04-01');
                    const monthDate = new Date(month + '-01');
                    return monthDate >= cutoff;
                }

                let activeModal = null;

                function showModal(message) {
                    if (activeModal) {
                        activeModal.remove();
                    }

                    const modalOverlay = document.createElement('div');
                    modalOverlay.className = 'modal-overlay';
                    modalOverlay.innerHTML = `
                        <div class="modal-container">
                            <div class="modal-header">
                                <h3><i class="fa-solid fa-triangle-exclamation"></i> Validation Error</h3>
                            </div>
                            <div class="modal-body">
                                <p>${escapeHtml(message)}</p>
                            </div>
                            <div class="modal-footer">
                                <button onclick="closeModal()">OK</button>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modalOverlay);
                    activeModal = modalOverlay;
                }

                window.closeModal = function() {
                    if (activeModal) {
                        activeModal.remove();
                        activeModal = null;
                    }
                };

                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }

                function validateForm() {
                    const transactionMonth = document.getElementById('transactionMonthSelect').value;
                    const glOldRadio = document.getElementById('glOldRadio');
                    const glNewRadio = document.getElementById('glNewRadio');
                    const glCodeMode = glOldRadio.checked ? 'old' : 'new';

                    if (!transactionMonth) {
                        return true;
                    }

                    if (glCodeMode === 'old') {
                        if (!isMarch2026OrEarlier(transactionMonth)) {
                            showModal('Old GL Code is only available for March 2026 and earlier. The selected month must be March 2026 or earlier.');
                            return false;
                        }
                    } else if (glCodeMode === 'new') {
                        if (!isApril2026OrLater(transactionMonth)) {
                            showModal('New GL Code is only available for April 2026 onwards. The selected month must be April 2026 or later.');
                            return false;
                        }
                    }

                    return true;
                }
            </script>

        </div>

    </main>

<?php include '../footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>