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
$transaction_month = $_GET['transaction_month'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-03-01');
    $month_time = strtotime($month . '-01');
    return $month_time <= $cutoff;
}

function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    $cutoff = strtotime('2026-04-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

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
// GET GL MAPPING
// ============================================================
$gl_mapping = [];
$gl_descriptions = [];

$exclude_code = ($gl_code_mode === 'old') ? '6000001' : '611101';
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
// COMPUTE TABLE ROWS
// ============================================================
function compute_table_rows(
    mysqli $conn,
    string $transaction_month,
    array $gl_mapping,
    bool $use_real_data = true
): array {
    $period_data = [];
    if ($use_real_data && !empty($transaction_month)) {
        $parts = explode('-', $transaction_month);
        $year_val = $parts[0];
        $month_val = $transaction_month . '-01';

        // NOTE: if your DB labels Head Office differently (e.g. 'HO', 'HEAD_OFFICE'),
        // change the mainzone value below accordingly.
        $sql = "
            SELECT
                gl_code,
                SUM(CASE WHEN mainzone = 'LNCR'       THEN amount ELSE 0 END) as lncr_mlfsi,
                SUM(CASE WHEN mainzone = 'VISMIN'     THEN amount ELSE 0 END) as vismin_mlfsi,
                SUM(CASE WHEN mainzone = 'HEADOFFICE' THEN amount ELSE 0 END) as headoffice_mlfsi
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
                    'lncr_mlfsi'       => floatval($row['lncr_mlfsi']),
                    'vismin_mlfsi'     => floatval($row['vismin_mlfsi']),
                    'headoffice_mlfsi' => floatval($row['headoffice_mlfsi'])
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }

    $sum_keys = function(array $keys) use ($gl_mapping, $period_data) {
        $lncr = 0; $vismin = 0; $ho = 0;
        foreach ($keys as $key) {
            if (isset($gl_mapping[$key])) {
                foreach ($gl_mapping[$key]['codes'] as $code) {
                    if (isset($period_data[$code])) {
                        $lncr   += $period_data[$code]['lncr_mlfsi'];
                        $vismin += $period_data[$code]['vismin_mlfsi'];
                        $ho     += $period_data[$code]['headoffice_mlfsi'];
                    }
                }
            }
        }
        return ['lncr' => $lncr, 'vismin' => $vismin, 'headoffice' => $ho];
    };

    $sum_prefix = function(string $prefix) use ($gl_mapping, $period_data) {
        $lncr = 0; $vismin = 0; $ho = 0;
        foreach ($gl_mapping as $key => $data) {
            if (strpos($key, $prefix) === 0) {
                foreach ($data['codes'] as $code) {
                    if (isset($period_data[$code])) {
                        $lncr   += $period_data[$code]['lncr_mlfsi'];
                        $vismin += $period_data[$code]['vismin_mlfsi'];
                        $ho     += $period_data[$code]['headoffice_mlfsi'];
                    }
                }
            }
        }
        return ['lncr' => $lncr, 'vismin' => $vismin, 'headoffice' => $ho];
    };

    // REVENUES (sort_order 1..20)
    $rev_keys = [];
    for ($i = 1; $i <= 20; $i++) {
        foreach (array_keys($gl_mapping) as $k) {
            if (strpos($k, "{$i}|") === 0) $rev_keys[] = $k;
        }
    }
    $rev = $sum_keys($rev_keys);

    // Cost of Sales (21)
    $cos = $sum_prefix('21|');

    // Gross Profit
    $gp_lncr   = $rev['lncr']   - $cos['lncr'];
    $gp_vismin = $rev['vismin'] - $cos['vismin'];
    $gp_ho     = $rev['headoffice'] - $cos['headoffice'];

    // Selling & Admin (22 + 23)
    $sa_22 = $sum_prefix('22|');
    $sa_23 = $sum_prefix('23|');
    $sa_lncr   = $sa_22['lncr']   + $sa_23['lncr'];
    $sa_vismin = $sa_22['vismin'] + $sa_23['vismin'];
    $sa_ho     = $sa_22['headoffice'] + $sa_23['headoffice'];

    // EBITDA
    $ebitda_lncr   = $gp_lncr   - $sa_lncr;
    $ebitda_vismin = $gp_vismin - $sa_vismin;
    $ebitda_ho     = $gp_ho     - $sa_ho;

    // Depreciation / Amortization
    $dep   = $sum_keys(['24|1']);
    $amort = $sum_keys(['24|2']);

    // EBIT
    $ebit_lncr   = $ebitda_lncr   - $dep['lncr']   - $amort['lncr'];
    $ebit_vismin = $ebitda_vismin - $dep['vismin'] - $amort['vismin'];
    $ebit_ho     = $ebitda_ho     - $dep['headoffice'] - $amort['headoffice'];

    // Interest (25|1)
    $interest = $sum_keys(['25|1']);

    // EBT
    $ebt_lncr   = $ebit_lncr   - $interest['lncr'];
    $ebt_vismin = $ebit_vismin - $interest['vismin'];
    $ebt_ho     = $ebit_ho     - $interest['headoffice'];

    // Tax (26|1)
    $tax = $sum_keys(['26|1']);

    // Net
    $net_lncr   = $ebt_lncr   - $tax['lncr'];
    $net_vismin = $ebt_vismin - $tax['vismin'];
    $net_ho     = $ebt_ho     - $tax['headoffice'];

    // ============================================================
    // BUILD ROWS (nationwide = lncr + vismin + headoffice)
    // ============================================================
    $final_table_rows = [];

    $add_row = function($label, $is_header, $data = null) use (&$final_table_rows) {
        $lncr   = $data['lncr']       ?? 0;
        $vismin = $data['vismin']     ?? 0;
        $ho     = $data['headoffice'] ?? 0;
        $final_table_rows[] = [
            'label'             => $label,
            'is_section_header' => $is_header,
            'lncr'              => $data ? $lncr   : null,
            'vismin'            => $data ? $vismin : null,
            'headoffice'        => $data ? $ho     : null,
            'nationwide'        => $data ? ($lncr + $vismin + $ho) : null,
            'is_summary_row'    => true
        ];
    };

    $add_spacer = function() use (&$final_table_rows) {
        $final_table_rows[] = ['is_manual_spacer' => true];
    };

    $add_row('REVENUES', false, $rev);
    $add_row('LESS: COST OF SALES', false, $cos);
    $add_spacer();

    $add_row('GROSS PROFIT', false, ['lncr' => $gp_lncr, 'vismin' => $gp_vismin, 'headoffice' => $gp_ho]);
    $add_spacer(); 

    $add_row('Selling & Admin Expenses', false, ['lncr' => $sa_lncr, 'vismin' => $sa_vismin, 'headoffice' => $sa_ho]);
    $add_row('LESS: TOTAL SELLING AND ADMIN EXPENSES', false, ['lncr' => $sa_lncr, 'vismin' => $sa_vismin, 'headoffice' => $sa_ho]);
    $add_spacer();

    $add_row("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", false, ['lncr' => $ebitda_lncr, 'vismin' => $ebitda_vismin, 'headoffice' => $ebitda_ho]);
    $add_row('Less: Depreciation Expense', false, $dep);
    $add_row('Amortization Expense', false, $amort);
    $add_spacer();

    $add_row('EARNINGS BEFORE INTEREST & TAXES', false, ['lncr' => $ebit_lncr, 'vismin' => $ebit_vismin, 'headoffice' => $ebit_ho]);
    $add_row('Less: Interest Expense', false, $interest);
    $add_spacer();

    $add_row('EARNINGS BEFORE TAXES', false, ['lncr' => $ebt_lncr, 'vismin' => $ebt_vismin, 'headoffice' => $ebt_ho]);
    $add_row('Less: Provision on Income Tax', false, $tax);
    $add_spacer();

    $add_row('TOTAL NET INCOME', false, ['lncr' => $net_lncr, 'vismin' => $net_vismin, 'headoffice' => $net_ho]);

    return $final_table_rows;
}

$table_rows = compute_table_rows($conn, $transaction_month, $gl_mapping, $valid_filters);

// Revenue bases for % (including nationwide)
$rev_lncr = 0; $rev_vismin = 0; $rev_ho = 0; $rev_nation = 0;
foreach ($table_rows as $r) {
    if (($r['label'] ?? '') === 'REVENUES') {
        $rev_lncr   = $r['lncr'];
        $rev_vismin = $r['vismin'];
        $rev_ho     = $r['headoffice'];
        $rev_nation = $r['nationwide'];
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
        .pct-cell { font-size: 0.85em; color: #555; }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="top-bar">
            <h2><a href="user_dashboard.php" style="font-size: 16px; text-decoration: none;">⬅ Back</a></h2>
            <div class="user-badge">
                <span><?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars($user_type); ?>)</span>
                <div class="avatar"><?php echo strtoupper(substr($full_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-title">PROFIT & LOSS STATEMENT (NATIONWIDE)</div>

            <?php if ($show_error && !empty($error_message)): ?>
                <div class="error-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

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
                    <a href="export_comparative_page_one.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
                    <a href="?reset=1" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                </div>
            </form>

            <div class="region-block">
                <div class="tables-scroll">
                    <div class="tables-grid">
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th colspan="11">Period: <?php echo !empty($transaction_month) ? strtoupper(date('F Y', strtotime($transaction_month . '-01'))) : '(No Period Selected)'; ?> MLFSI & JEWELERS</th>
                                    </tr>
                                    <tr>
                                        <th></th>
                                        <th></th>
                                        <th></th>
                                        <th>LUZON/NCR</th>
                                        <th>%</th>
                                        <th>VISMIN</th>
                                        <th>%</th>
                                        <th>HEADOFFICE</th>
                                        <th>%</th>
                                        <th>NATIONWIDE</th>
                                        <th>%</th>
                                    </tr>
                                </thead>
                                <tbody class="report-tbody">

                                <tr class="initial-spacer">
                                    <td colspan="11"></td>
                                </tr>
                                    <?php if (empty($table_rows)): ?>
                                        <tr>
                                            <td colspan="11" style="text-align: center;">No data structure available</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($table_rows as $row):
                                            if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
                                                echo '<tr class="spacer-row" style="height: 20px;"><td colspan="11"></td></tr>';
                                                continue;
                                            }

                                            $is_header = !empty($row['is_section_header']);
                                            $label = $row['label'] ?? '';

                                            $lncr      = $row['lncr']      ?? 0;
                                            $vismin    = $row['vismin']    ?? 0;
                                            $ho        = $row['headoffice']?? 0;
                                            $nation    = $row['nationwide']?? 0;

                                            $pct_lncr   = $rev_lncr   != 0 ? ($lncr   / $rev_lncr)   * 100 : 0;
                                            $pct_vismin = $rev_vismin != 0 ? ($vismin / $rev_vismin) * 100 : 0;
                                            $pct_ho     = $rev_ho     != 0 ? ($ho     / $rev_ho)     * 100 : 0;
                                            $pct_nation = $rev_nation != 0 ? ($nation / $rev_nation) * 100 : 0;
                                        ?>
                                            <tr class="summary-row">
                                                <td></td>
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

                                                <!-- HEADOFFICE: displayed empty as requested -->
                                                <td class="numeric-cell summary-cell"></td>
                                                <td class="numeric-cell pct-cell summary-cell"></td>

                                                <td class="numeric-cell summary-cell" style="<?= ($nation < 0) ? 'color: red;' : '' ?>">
                                                    <strong><?= $is_header ? '' : number_format($nation, 2) ?></strong>
                                                </td>
                                                <td class="numeric-cell pct-cell summary-cell">
                                                    <?= $is_header ? '' : number_format($pct_nation, 2) . '%' ?>
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
                function isMarch2026OrEarlier(month) {
                    if (!month) return true;
                    const cutoff = new Date('2026-03-01');
                    const monthDate = new Date(month + '-01');
                    return monthDate <= cutoff;
                }
                function isApril2026OrLater(month) {
                    if (!month) return true;
                    const cutoff = new Date('2026-04-01');
                    const monthDate = new Date(month + '-01');
                    return monthDate >= cutoff;
                }

                let activeModal = null;
                function showModal(message) {
                    if (activeModal) activeModal.remove();
                    const modalOverlay = document.createElement('div');
                    modalOverlay.className = 'modal-overlay';
                    modalOverlay.innerHTML = `
                        <div class="modal-container">
                            <div class="modal-header">
                                <h3><i class="fa-solid fa-triangle-exclamation"></i> Validation Error</h3>
                            </div>
                            <div class="modal-body"><p>${escapeHtml(message)}</p></div>
                            <div class="modal-footer"><button onclick="closeModal()">OK</button></div>
                        </div>`;
                    document.body.appendChild(modalOverlay);
                    activeModal = modalOverlay;
                }
                window.closeModal = function() {
                    if (activeModal) { activeModal.remove(); activeModal = null; }
                };
                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }
                function validateForm() {
                    const transactionMonth = document.getElementById('transactionMonthSelect').value;
                    const glOldRadio = document.getElementById('glOldRadio');
                    const glCodeMode = glOldRadio.checked ? 'old' : 'new';
                    if (!transactionMonth) return true;
                    if (glCodeMode === 'old') {
                        if (!isMarch2026OrEarlier(transactionMonth)) {
                            showModal('Old GL Code is only available for March 2026 and earlier. The selected month must be March 2026 or earlier.');
                            return false;
                        }
                    } else {
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