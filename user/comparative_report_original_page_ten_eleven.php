<?php
// comparative_report_original_page_ten_eleven.php
// Same merged layout as page_nine (NATIONWIDE | LNCR | % | VISMIN | % | HO | %)
// but only shows from SELLING & ADMIN EXPENSE downward.
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

// Filters (no mainzone filter — always show NATIONWIDE, LNCR, VISMIN)
$transaction_year = $_GET['transaction_year'] ?? '';
$selected_period = $_GET['selected_period'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

// Error messages for validation
$error_message = '';

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

// Helper function to check if a month is August 2026 or later.
// From August 2026, ML Properties ( Realty & Brokerage ) is shown as its
// own group (sort_order 30 / MLP-1) instead of inside Total Other Income
// (sort_order 23 / TOI-32).
function isAugust2026OrLater(string $month): bool {
    if (empty($month)) return false;
    $cutoff = strtotime('2026-08-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

// Validate GL code mode
$show_error = false;
$valid_filters = false;

// Only validate if period is provided
if (!empty($selected_period)) {
    if ($gl_code_mode === 'old') {
        if (!isMarch2026OrEarlier($selected_period)) {
            $error_message = 'Old GL Code is only available for March 2026 and earlier. Selected period must be March 2026 or earlier.';
            $show_error = true;
        }
    } elseif ($gl_code_mode === 'new') {
        if (!isApril2026OrLater($selected_period)) {
            $error_message = 'New GL Code is only available for April 2026 onwards. Selected period must be April 2026 or later.';
            $show_error = true;
        }
    }
    
    if (!$show_error) {
        $valid_filters = true;
    }
}

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    header("Location: comparative_report_original_page_ten_eleven.php");
    exit;
}

// ============================================================
// ML PROPERTIES GROUP FLAG
// ============================================================
// When the selected period is August 2026 or later (New GL):
//   - TOI-32 "ML Properties ( Realty & Brokerage )" is HIDDEN from
//     sort_order 23 (Total Other Income)
//   - MLP-1 (sort_order 30, "ML Properties") is SHOWN as its own group
//     right after sort_order 7, and is included in TOTAL REVENUES
//   - TOI-32 amounts are folded into MLP-1
// Otherwise ML Properties stays inside Total Other Income and the
// sort_order 30 group is never shown.
// ============================================================
$ml_properties_group_active = (
    $gl_code_mode === 'new' &&
    isAugust2026OrLater($selected_period)
);

// Years dropdown (no mainzone filter)
$distinct_years = [];

$years_query = "
    SELECT DISTINCT transaction_year
    FROM fs_reports.manual_adjustment
    WHERE transaction_year IS NOT NULL
    ORDER BY transaction_year DESC
";
$years_res = mysqli_query($conn, $years_query);
if ($years_res) {
    while ($y = mysqli_fetch_assoc($years_res)) {
        $val = trim((string)($y['transaction_year'] ?? ''));
        if ($val !== '' && !in_array($val, $distinct_years, true)) $distinct_years[] = $val;
    }
}

// ============================================================
// GET GL MAPPING based on GL Code Mode
// ============================================================
$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];
$gl_id_by_key = [];   // sort_order|sub_order => gl_id

$table_name = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes_ho' : 'fs_reports.new_gl_codes_ho';

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
        $gl_id = trim((string)($row['gl_id'] ?? ''));

        // Kept for ALL rows (incl. the hidden MLP-1 / TOI-32 row) so both
        // IDs are still queried by the data function.
        $gl_id_by_key[$key] = $gl_id;

        // ML Properties ( Realty & Brokerage ) must only be shown in ONE place:
        //  - Not active: inside Total Other Income (TOI-32 / sort_order 23).
        //    MLP-1 / sort_order 30 is hidden.
        //  - Active (New GL, August 2026+): as its own group
        //    (MLP-1 / sort_order 30). TOI-32 is hidden from sort_order 23.
        if (
            ($gl_id === 'MLP-1' || (int)($row['sort_order'] ?? 0) === 30) &&
            !$ml_properties_group_active
        ) {
            continue;
        }

        if ($gl_id === 'TOI-32' && $ml_properties_group_active) {
            continue;
        }

        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = [];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }

        // Keep numerical gl_code for any residual lookups (rarely used now)
        $code = trim((string)($row['gl_code'] ?? ''));
        if ($code !== '' && !in_array($code, $gl_mapping[$key], true)) {
            $gl_mapping[$key][] = $code;
        }
        
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

// ============================================================
// HARDCODED GL ID MAPPINGS (same as HO comparative report)
// SOURCE gl_id in manual_adjustment → TARGET gl_id on the report
// No mixed mode — only old or new depending on gl_code_mode
// ============================================================

$old_gl_mapping = [
    // COS (Cost of Sales)
    'COS-2' => 'COS-5',
    'COS-3' => 'COS-6',
    'COS-4' => 'COS-7',

    // MLE
    'MLE-2' => 'MLE-3',

    // TAE
    'TAE-15' => 'TAE-16',
    'TAE-16' => 'TAE-17',
    'TAE-17' => 'TAE-18',
    'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20',
    'TAE-20' => 'TAE-21',
    'TAE-21' => 'TAE-22',
    'TAE-22' => 'TAE-23',
    'TAE-23' => null,

    // TOI
    'TOI-18' => null,
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'MLE-2',
    'TOI-22' => 'INJ-5',
    'TOI-23' => 'INJ-4',
    'TOI-24' => null,

    // VEH
    'VEH-5' => 'VEH-6',
    'VEH-6' => 'VEH-7',
    'VEH-7' => 'VEH-8',
    'VEH-8' => 'VEH-9',
    'VEH-9' => 'VEH-10',
];

$new_gl_mapping = [
    // COS
    'COS-2' => 'COS-5',
    'COS-3' => 'COS-6',
    'COS-4' => 'COS-7',

    // TAE
    'TAE-15' => 'TAE-16',
    'TAE-16' => 'TAE-17',
    'TAE-17' => 'TAE-18',
    'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20',
    'TAE-20' => 'TAE-21',
    'TAE-21' => 'TAE-22',
    'TAE-22' => 'TAE-23',
    'TAE-23' => null,

    // TOI
    'TOI-18' => null,
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'COS-8',
    'TOI-22' => null,
    'TOI-23' => null,
    'TOI-24' => null,

    // VEH
    'VEH-5' => 'VEH-7',
    'VEH-6' => 'VEH-8',
    'VEH-7' => 'VEH-9',
    'VEH-8' => 'VEH-10',
    'VEH-9' => 'VEH-11',

    // INS — TARGET => list of SOURCE gl_ids
    'INS-1'  => ['INS-28', 'INS-29', 'INS-30', 'INS-31', 'INS-34', 'INS-39'],
    'INS-2'  => ['INS-25', 'INS-26', 'INS-44', 'INS-47'],
    'INS-3'  => ['INS-32', 'INS-33', 'INS-42', 'INS-43', 'INS-45'],
    'INS-4'  => ['INS-27', 'INS-46'],
    'INS-5'  => ['INS-20', 'INS-21', 'INS-22', 'INS-23', 'INS-24', 'INS-37', 'INS-41'],
    'INS-6'  => ['INS-1', 'INS-2', 'INS-3', 'INS-4', 'INS-5', 'INS-6', 'INS-7', 'INS-8', 'INS-9', 'INS-10', 'INS-11', 'INS-12', 'INS-13', 'INS-14', 'INS-35', 'INS-36', 'INS-40'],
    'INS-7'  => ['INS-15', 'INS-16', 'INS-17', 'INS-18', 'INS-19'],
    'INS-10'  => ['INS-38'],
    'INS-11'  => ['INS-48'],
    'INS-12' => ['INS-49'],
    'INS-13' => [],
    'INS-14' => [],
];

/**
 * Fetch Head Office (manual adjustment) value for sort_order=26, sub_order=1
 * for a given mainzone and period.
 * - NATIONWIDE / empty => sum of LNCR + VISMIN only
 * - Specific mainzone  => that mainzone only
 */
function get_head_office_manual_adjustment(mysqli $conn, string $mainzone, string $selected_period): float {
    if (empty($selected_period)) {
        return 0.0;
    }

    $transaction_month = $selected_period . '-01';

    if ($mainzone === '' || strtoupper($mainzone) === 'NATIONWIDE') {
        // NATIONWIDE = LNCR + VISMIN only
        $sql = "
            SELECT SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total
            FROM fs_reports.manual_adjustment
            WHERE transaction_month = ?
              AND sort_order = 26
              AND sub_order = 1
              AND UPPER(TRIM(mainzone)) IN ('LNCR', 'VISMIN')
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return 0.0;
        mysqli_stmt_bind_param($stmt, "s", $transaction_month);
    } else {
        // Specific mainzone (case-insensitive, trimmed match)
        $sql = "
            SELECT SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total
            FROM fs_reports.manual_adjustment
            WHERE transaction_month = ?
              AND sort_order = 26
              AND sub_order = 1
              AND LOWER(TRIM(mainzone)) = LOWER(?)
        ";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return 0.0;
        mysqli_stmt_bind_param($stmt, "ss", $transaction_month, $mainzone);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $row && $row['total'] !== null ? floatval($row['total']) : 0.0;
}

/**
 * Fetch period data from manual_adjustment, applying SOURCE→TARGET gl_id mapping
 * (same logic as HO comparative report, but keeps mlfsi / jewelers separate).
 *
 * Returns [ target_gl_id => ['mlfsi' => float, 'jewelers' => float, 'total' => float] ]
 */
function get_manual_adjustment_period_data(
    mysqli $conn,
    string $mainzone,
    string $selected_period,
    array $gl_id_by_key,
    string $gl_code_mode = 'old',
    array $old_gl_mapping = [],
    array $new_gl_mapping = []
): array {
    $data = [];

    if (empty($selected_period)) {
        return $data;
    }

    $parts = explode('-', $selected_period);
    $year_val = $parts[0];
    $month_val = $selected_period . '-01';

    // --------------------------------------------------------
    // Build list of gl_ids to query (structure IDs + mapping sources)
    // --------------------------------------------------------
    $gl_ids_to_query = array_values(array_unique(array_filter(array_values($gl_id_by_key))));

    if ($gl_code_mode === 'old') {
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') {
                $gl_ids_to_query[] = $src_id;
            }
        }
    } else {
        // new mode
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_id) {
                    if ($src_id !== '') {
                        $gl_ids_to_query[] = $src_id;
                    }
                }
            } elseif ($key !== '') {
                // Scalar: SOURCE => TARGET — query the SOURCE
                $gl_ids_to_query[] = $key;
            }
        }
    }

    $gl_ids_to_query = array_values(array_unique(array_filter($gl_ids_to_query, function ($id) {
        return $id !== null && $id !== '';
    })));

    if (empty($gl_ids_to_query)) {
        return $data;
    }

    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));

    // Params order matches SQL: year, month, gl_ids..., [mainzone]
    $params = [$year_val, $month_val];
    $types  = 'ss';
    $params = array_merge($params, $gl_ids_to_query);
    $types .= str_repeat('s', count($gl_ids_to_query));

    $where_mainzone = '';
    if ($mainzone === '' || strtoupper($mainzone) === 'NATIONWIDE') {
        $where_mainzone = " AND UPPER(TRIM(mainzone)) IN ('LNCR', 'VISMIN')";
    } else {
        $where_mainzone = ' AND LOWER(TRIM(mainzone)) = LOWER(?)';
        $params[] = $mainzone;
        $types .= 's';
    }

    $sql = "
        SELECT
            gl_id,
            SUM(COALESCE(mlfsi, 0)) AS mlfsi_amount,
            SUM(COALESCE(jewelers, 0)) AS jewelers_amount
        FROM fs_reports.manual_adjustment
        WHERE transaction_year = ?
          AND transaction_month = ?
          AND gl_id IN ({$placeholders})
          AND gl_id IS NOT NULL
          AND gl_id != ''
          {$where_mainzone}
        GROUP BY gl_id
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $data;
    }

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $raw = []; // gl_id => [mlfsi, jewelers]
    while ($row = mysqli_fetch_assoc($result)) {
        $raw[$row['gl_id']] = [
            'mlfsi'    => floatval($row['mlfsi_amount']),
            'jewelers' => floatval($row['jewelers_amount']),
        ];
    }
    mysqli_stmt_close($stmt);

    // --------------------------------------------------------
    // Map raw SOURCE amounts onto TARGET report gl_ids
    // --------------------------------------------------------
    $add_to = function (string $target, float $mlfsi, float $jewelers) use (&$data) {
        if ($target === '') {
            return;
        }
        if (!isset($data[$target])) {
            $data[$target] = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
        }
        $data[$target]['mlfsi']    += $mlfsi;
        $data[$target]['jewelers'] += $jewelers;
        $data[$target]['total']     = $data[$target]['mlfsi'] + $data[$target]['jewelers'];
    };

    if ($gl_code_mode === 'old') {
        // SOURCE => TARGET (or direct match)
        foreach ($raw as $src_id => $amt) {
            if (array_key_exists($src_id, $old_gl_mapping)) {
                $target = $old_gl_mapping[$src_id];
                if ($target !== null && $target !== '') {
                    $add_to($target, $amt['mlfsi'], $amt['jewelers']);
                }
                // null target = intentionally dropped
            } else {
                $add_to($src_id, $amt['mlfsi'], $amt['jewelers']);
            }
        }
    } else {
        // NEW mode
        // 1) Array mappings: TARGET => [SOURCES]
        // 2) Scalar mappings: SOURCE => TARGET
        // 3) Direct match for everything else (not a scalar source)

        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                $target = $key;
                $direct_mlfsi = isset($raw[$target]) ? $raw[$target]['mlfsi'] : null;
                $direct_jew   = isset($raw[$target]) ? $raw[$target]['jewelers'] : null;

                $sum_mlfsi = 0.0;
                $sum_jew   = 0.0;
                foreach ($mapping as $src_id) {
                    if ($src_id !== '' && isset($raw[$src_id])) {
                        $sum_mlfsi += $raw[$src_id]['mlfsi'];
                        $sum_jew   += $raw[$src_id]['jewelers'];
                    }
                }

                // Prefer non-zero direct TARGET amount (post-April new-ID rows)
                if ($direct_mlfsi !== null && ($direct_mlfsi != 0.0 || $direct_jew != 0.0)) {
                    $add_to($target, (float)$direct_mlfsi, (float)$direct_jew);
                } elseif ($sum_mlfsi != 0.0 || $sum_jew != 0.0) {
                    $add_to($target, $sum_mlfsi, $sum_jew);
                } elseif ($direct_mlfsi !== null) {
                    $add_to($target, (float)$direct_mlfsi, (float)$direct_jew);
                }
            } else {
                // Scalar SOURCE => TARGET
                if ($key !== '') {
                    $scalar_source_ids[$key] = true;
                }
                $target = $mapping;
                if (
                    $key !== '' &&
                    $target !== null &&
                    $target !== '' &&
                    isset($raw[$key])
                ) {
                    $add_to($target, $raw[$key]['mlfsi'], $raw[$key]['jewelers']);
                }
            }
        }

        // Direct-match remaining structure IDs that were not scalar sources
        foreach ($gl_id_by_key as $gid) {
            if (
                $gid !== '' &&
                !isset($data[$gid]) &&
                isset($raw[$gid]) &&
                !isset($scalar_source_ids[$gid])
            ) {
                $add_to($gid, $raw[$gid]['mlfsi'], $raw[$gid]['jewelers']);
            }
        }
    }

    return $data;
}

function compute_table_rows_for_mainzone(
    mysqli $conn,
    string $mainzone,
    string $transaction_year,
    string $selected_period,
    string $gl_code_mode,
    array $gl_mapping,
    array $gl_descriptions,
    array $special_keys,
    array $sort_order_descriptions,
    array $gl_id_by_key,
    array $old_gl_mapping = [],
    array $new_gl_mapping = [],
    bool $use_real_data = true,
    bool $ml_properties_group = false
): array {
    $period_data = [];
    if ($use_real_data && !empty($selected_period)) {
        $period_data = get_manual_adjustment_period_data(
            $conn,
            $mainzone,
            $selected_period,
            $gl_id_by_key,
            $gl_code_mode,
            $old_gl_mapping,
            $new_gl_mapping
        );
    }

    // ML PROPERTIES: fold TOI-32 into MLP-1 so the amount is shown in the
    // separate ML Properties group and NOT inside Total Other Income.
    if ($ml_properties_group) {
        $mlp_src = $period_data['MLP-1'] ?? ['mlfsi' => 0.0, 'jewelers' => 0.0];
        $toi_src = $period_data['TOI-32'] ?? ['mlfsi' => 0.0, 'jewelers' => 0.0];

        $mlp_mlfsi = (float)$mlp_src['mlfsi'] + (float)$toi_src['mlfsi'];
        $mlp_jew   = (float)$mlp_src['jewelers'] + (float)$toi_src['jewelers'];

        $period_data['MLP-1'] = [
            'mlfsi'    => $mlp_mlfsi,
            'jewelers' => $mlp_jew,
            'total'    => $mlp_mlfsi + $mlp_jew,
        ];
        unset($period_data['TOI-32']);
    }

    // Fetch Head Office value for sort_order=28 / sub_order=1 (old GL) or sort_order=29 / sub_order=1 (new GL)
    $head_office_tax = 0.0;
    if ($use_real_data && !empty($selected_period)) {
        $head_office_tax = get_head_office_manual_adjustment($conn, $mainzone, $selected_period);
    }

    $table_rows = [];

    foreach ($gl_mapping as $key => $codes) {
        [$sort_order, $sub_order] = explode('|', $key);

        $gl_description = $gl_descriptions[$key] ?? '';
        $is_inj2 = in_array($key, $special_keys);
        $current_gl_id = $gl_id_by_key[$key] ?? '';

        $period_mlfsi = 0.0;
        $period_jewelers = 0.0;

        // Primary lookup by gl_id (correct source for manual_adjustment)
        if ($current_gl_id !== '' && isset($period_data[$current_gl_id])) {
            $period_mlfsi = $period_data[$current_gl_id]['mlfsi'];
            $period_jewelers = $period_data[$current_gl_id]['jewelers'];
        }

        // Income Tax (old: sort 28/1, new: sort 29/1): put the FULL amount in HEAD OFFICE only.
        // Do not break it down into MLFSI / JEWELERS.
        $head_office = 0.0;
        $is_tax_head_office_row = (
            ((string)$sort_order === '28' && (string)$sub_order === '1' && $gl_code_mode === 'old') ||
            ((string)$sort_order === '29' && (string)$sub_order === '1' && $gl_code_mode === 'new')
        );
        if ($is_tax_head_office_row) {
            // Prefer the dedicated HO adjustment (sort_order 26 / sub_order 1);
            // if that is zero, fall back to the row's own mlfsi+jewelers total.
            $tax_total = $head_office_tax;
            if ($tax_total == 0.0) {
                $tax_total = $period_mlfsi + $period_jewelers;
            }
            $period_mlfsi    = 0.0;
            $period_jewelers = 0.0;
            $head_office     = $tax_total;
        }
        
        // TOTAL = MLFSI + JEWELERS + HEAD OFFICE
        $period_total = $period_mlfsi + $period_jewelers + $head_office;
        
        $table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => $sub_order,
            'gl_description' => $gl_description,
            'is_section_header' => false,
            'is_summary_row' => false,
            'period_mlfsi' => $period_mlfsi,
            'period_jewelers' => $period_jewelers,
            'period_total' => $period_total,
            'head_office' => $head_office,
            'is_inj2' => $is_inj2
        ];
    }

    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $sort_order = $row['sort_order'];
        if (!isset($grouped_rows[$sort_order])) {
            $grouped_rows[$sort_order] = [];
        }
        $grouped_rows[$sort_order][] = $row;
    }

    // ML Properties group (sort_order 30) only exists when it is active.
    // Safety net: never show it (e.g. at the bottom) otherwise.
    if (!$ml_properties_group && isset($grouped_rows[30])) {
        unset($grouped_rows[30]);
    }

    // ML PROPERTIES: place sort_order 30 right after sort_order 7
    // (it is the last number in the structure, so by default it would
    // be rendered at the very bottom).
    if ($ml_properties_group && isset($grouped_rows[30])) {
        $reordered_groups = [];

        foreach ($grouped_rows as $group_sort_order => $group_rows) {
            if ((int)$group_sort_order === 30) {
                continue;
            }

            $reordered_groups[$group_sort_order] = $group_rows;

            if ((int)$group_sort_order === 7) {
                $reordered_groups[30] = $grouped_rows[30];
            }
        }

        // Safety: if sort_order 7 was not found, keep 30 at the end.
        if (!isset($reordered_groups[30])) {
            $reordered_groups[30] = $grouped_rows[30];
        }

        $grouped_rows = $reordered_groups;
    }

    // ============================================================
    // Special Cost of Sales calculation (old: sort_order 23, new: 24)
    // COS-4 (sub_order 4) = COS-1 + COS-2 + COS-3
    // Section total = COS-4 + COS-5 + COS-6 + COS-7 + COS-8 + COS-9
    // (i.e. do not double-count COS-1/2/3 in the total)
    // ============================================================
    $cost_of_sales_sort_order_early = ($gl_code_mode === 'old') ? 23 : 24;
    if (isset($grouped_rows[$cost_of_sales_sort_order_early])) {
        $cos_rows = &$grouped_rows[$cost_of_sales_sort_order_early];

        // Index rows by sub_order for easy lookup
        $by_sub = [];
        foreach ($cos_rows as $idx => $r) {
            $by_sub[(string)$r['sub_order']] = $idx;
        }

        // Sum COS-1 + COS-2 + COS-3 (sub_orders 1,2,3)
        $sum1_3_mlfsi = 0.0;
        $sum1_3_jew   = 0.0;
        $sum1_3_head  = 0.0;
        foreach (['1', '2', '3'] as $so) {
            if (isset($by_sub[$so])) {
                $r = $cos_rows[$by_sub[$so]];
                $sum1_3_mlfsi += (float)($r['period_mlfsi'] ?? 0);
                $sum1_3_jew   += (float)($r['period_jewelers'] ?? 0);
                $sum1_3_head  += (float)($r['head_office'] ?? 0);
            }
        }
        $sum1_3_total = $sum1_3_mlfsi + $sum1_3_jew + $sum1_3_head;

        // Override COS-4 (sub_order 4) with the sum of 1+2+3
        if (isset($by_sub['4'])) {
            $idx4 = $by_sub['4'];
            $cos_rows[$idx4]['period_mlfsi']    = $sum1_3_mlfsi;
            $cos_rows[$idx4]['period_jewelers'] = $sum1_3_jew;
            $cos_rows[$idx4]['head_office']    = $sum1_3_head;
            $cos_rows[$idx4]['period_total']   = $sum1_3_total;
        }

        unset($cos_rows); // break reference
    }

    $final_table_rows = [];
    $rev_mlfsi = 0; $rev_jew = 0; $rev_tot = 0; $rev_head = 0;
    $sa_mlfsi = 0; $sa_jew = 0; $sa_tot = 0; $sa_head = 0;
    $gp_mlfsi = 0; $gp_jew = 0; $gp_tot = 0; $gp_head = 0;
    $ebitda_mlfsi = 0; $ebitda_jew = 0; $ebitda_tot = 0; $ebitda_head = 0;
    $ebit_mlfsi = 0; $ebit_jew = 0; $ebit_tot = 0; $ebit_head = 0;
    $ebt_mlfsi = 0; $ebt_jew = 0; $ebt_tot = 0; $ebt_head = 0;
    $net_mlfsi = 0; $net_jew = 0; $net_tot = 0; $net_head = 0;
    $cos_mlfsi = 0; $cos_jew = 0; $cos_tot = 0; $cos_head = 0;
    $other_income_mlfsi = 0; $other_income_jew = 0; $other_income_tot = 0; $other_income_head = 0;
    
    // Variables for expenses after TOTAL REVENUES
    $personnel_mlfsi = 0; $personnel_jew = 0; $personnel_tot = 0; $personnel_head = 0;
    $admin_mlfsi = 0; $admin_jew = 0; $admin_tot = 0; $admin_head = 0;
    $dep_mlfsi = 0; $dep_jew = 0; $dep_tot = 0; $dep_head = 0;
    $interest_mlfsi = 0; $interest_jew = 0; $interest_tot = 0; $interest_head = 0;
    $tax_mlfsi = 0; $tax_jew = 0; $tax_tot = 0; $tax_head = 0;

    // Determine the max sort_order for revenue based on GL code mode
    // Old GL: 1-22, New GL: 1-23
    $revenue_max_sort_order = ($gl_code_mode === 'old') ? 22 : 23;
    
    // Determine the sort_order for Cost of Sales based on GL code mode
    // Old GL: 23, New GL: 24
    $cost_of_sales_sort_order = ($gl_code_mode === 'old') ? 23 : 24;
    
    // Determine the sort_order for Personnel Expenses based on GL code mode
    // Old GL: 24, New GL: 25
    $personnel_sort_order = ($gl_code_mode === 'old') ? 24 : 25;
    
    // Determine the sort_order for Administrative Expenses based on GL code mode
    // Old GL: 25, New GL: 26
    $admin_sort_order = ($gl_code_mode === 'old') ? 25 : 26;

    // First pass: collect all detail rows and calculate totals
    foreach ($grouped_rows as $sort_order => $rows) {
        $total_period_mlfsi = array_sum(array_column($rows, 'period_mlfsi'));
        $total_period_jewelers = array_sum(array_column($rows, 'period_jewelers'));
        $total_period_total = array_sum(array_column($rows, 'period_total'));
        $total_head_office = array_sum(array_column($rows, 'head_office'));
        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;

        // Revenue detail rows (1 to revenue_max_sort_order)
        // ML Properties (sort_order 30) is a revenue group when active.
        $is_revenue_group = (
            ((int)$sort_order >= 1 && (int)$sort_order <= $revenue_max_sort_order) ||
            ($ml_properties_group && (int)$sort_order === 30)
        );
        if ($is_revenue_group) {
            $rev_mlfsi += $total_period_mlfsi;
            $rev_jew += $total_period_jewelers;
            $rev_tot += $group_total_with_head;
            $rev_head += $total_head_office;
        }
        
        // Cost of Sales — total = COS-4 + COS-5 + ... + COS-9 only
        // (COS-4 already holds the sum of COS-1+2+3, so exclude 1/2/3 to avoid double-counting)
        if ((int)$sort_order == $cost_of_sales_sort_order) {
            $cos_mlfsi = 0.0;
            $cos_jew   = 0.0;
            $cos_head  = 0.0;
            foreach ($rows as $r) {
                $sub = (string)($r['sub_order'] ?? '');
                if ($sub === '1' || $sub === '2' || $sub === '3') {
                    continue; // skip the detail lines that are already rolled into COS-4
                }
                $cos_mlfsi += (float)($r['period_mlfsi'] ?? 0);
                $cos_jew   += (float)($r['period_jewelers'] ?? 0);
                $cos_head  += (float)($r['head_office'] ?? 0);
            }
            $cos_tot = $cos_mlfsi + $cos_jew + $cos_head;
        }
        
        // Other Income (sort_order 22 for old, 23 for new)
        if ($gl_code_mode === 'old') {
            if ((int)$sort_order == 22) {
                $other_income_mlfsi = $total_period_mlfsi;
                $other_income_jew = $total_period_jewelers;
                $other_income_tot = $group_total_with_head;
                $other_income_head = $total_head_office;
            }
        } else {
            if ((int)$sort_order == 22) {
                $other_income_mlfsi = $total_period_mlfsi;
                $other_income_jew = $total_period_jewelers;
                $other_income_tot = $group_total_with_head;
                $other_income_head = $total_head_office;
            }
        }
        
        // Personnel Expenses
        if ((int)$sort_order == $personnel_sort_order) {
            $personnel_mlfsi = $total_period_mlfsi;
            $personnel_jew = $total_period_jewelers;
            $personnel_tot = $group_total_with_head;
            $personnel_head = $total_head_office;
            // Add to S&A total
            $sa_mlfsi += $total_period_mlfsi;
            $sa_jew += $total_period_jewelers;
            $sa_tot += $group_total_with_head;
            $sa_head += $total_head_office;
        }
        
        // Administrative Expenses
        if ((int)$sort_order == $admin_sort_order) {
            $admin_mlfsi = $total_period_mlfsi;
            $admin_jew = $total_period_jewelers;
            $admin_tot = $group_total_with_head;
            $admin_head = $total_head_office;
            // Add to S&A total
            $sa_mlfsi += $total_period_mlfsi;
            $sa_jew += $total_period_jewelers;
            $sa_tot += $group_total_with_head;
            $sa_head += $total_head_office;
        }
        
        // Depreciation (sort_order 26 for old, 27 for new)
        $dep_sort_order = ($gl_code_mode === 'old') ? 26 : 27;
        if ((int)$sort_order == $dep_sort_order) {
            $dep_mlfsi = $total_period_mlfsi;
            $dep_jew = $total_period_jewelers;
            $dep_tot = $group_total_with_head;
            $dep_head = $total_head_office;
        }
        
        // Interest Expense (sort_order 27 for old, 28 for new)
        $interest_sort_order = ($gl_code_mode === 'old') ? 27 : 28;
        if ((int)$sort_order == $interest_sort_order) {
            $interest_mlfsi = $total_period_mlfsi;
            $interest_jew = $total_period_jewelers;
            $interest_tot = $group_total_with_head;
            $interest_head = $total_head_office;
        }
        
        // Income Tax (sort_order 28 for old, 29 for new)
        $tax_sort_order = ($gl_code_mode === 'old') ? 28 : 29;
        if ((int)$sort_order == $tax_sort_order) {
            $tax_mlfsi = $total_period_mlfsi;
            $tax_jew = $total_period_jewelers;
            $tax_tot = $group_total_with_head;
            $tax_head = $total_head_office;
        }
    }

    // GROSS PROFIT = Revenues - Cost of Sales
    $gp_mlfsi = $rev_mlfsi - $cos_mlfsi;
    $gp_jew = $rev_jew - $cos_jew;
    $gp_tot = $rev_tot - $cos_tot;
    $gp_head = $rev_head - $cos_head;

    // EBITDA = Gross Profit - Selling & Admin + Other Income
    $ebitda_mlfsi = $gp_mlfsi - $sa_mlfsi;
    $ebitda_jew = $gp_jew - $sa_jew;
    $ebitda_tot = $gp_tot - $sa_tot;
    $ebitda_head = $gp_head - $sa_head;

    // EBIT = EBITDA - Depreciation
    $ebit_mlfsi = $ebitda_mlfsi - $dep_mlfsi;
    $ebit_jew = $ebitda_jew - $dep_jew;
    $ebit_tot = $ebitda_tot - $dep_tot;
    $ebit_head = $ebitda_head - $dep_head;

    // EBT = EBIT - Interest
    $ebt_mlfsi = $ebit_mlfsi - $interest_mlfsi;
    $ebt_jew = $ebit_jew - $interest_jew;
    $ebt_tot = $ebit_tot - $interest_tot;
    $ebt_head = $ebit_head - $interest_head;

    // NET INCOME = EBT - Tax
    $net_mlfsi = $ebt_mlfsi - $tax_mlfsi;
    $net_jew = $ebt_jew - $tax_jew;
    $net_tot = $ebt_tot - $tax_tot;
    $net_head = $ebt_head - $tax_head;

    // ============================================================
    // BUILD FINAL TABLE IN CORRECT ORDER
    // (Full P&L is still computed above so lower totals stay correct.
    //  We only *emit* rows starting from SELLING & ADMIN EXPENSE.)
    // ============================================================
    
    // STEP 1: Revenue detail rows (sort_order 1 to revenue_max_sort_order)
    // (+ ML Properties, sort_order 30, which was moved right after sort_order 7)
    // NOTE: These are computed for the totals but NOT added to $final_table_rows
    // because this page only shows from SELLING & ADMIN EXPENSE downward.
    foreach ($grouped_rows as $sort_order => $rows) {
        $is_revenue_group_step = (
            ((int)$sort_order >= 1 && (int)$sort_order <= $revenue_max_sort_order) ||
            ($ml_properties_group && (int)$sort_order === 30)
        );

        if ($is_revenue_group_step) {
            // (intentionally not pushing revenue rows)
        }
    }

    // STEP 2–8: TOTAL REVENUES / COS / GP — computed above, not emitted

    // STEP 9: "SELLING & ADMIN EXPENSE" header  ← START OF WHAT THIS PAGE SHOWS
    $final_table_rows[] = [
        'sort_order' => 'SELLING & ADMIN EXPENSE',
        'sub_order' => '',
        'gl_description' => '',
        'is_section_header' => true,
        'is_summary_row' => true,
        'period_mlfsi' => null,
        'period_jewelers' => null,
        'period_total' => null,
        'head_office' => null
    ];

    // STEP 10: Personnel Expenses detail rows with total
    if (isset($grouped_rows[$personnel_sort_order])) {
        foreach ($grouped_rows[$personnel_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
        // Add total row for Personnel Expenses
        $total_period_mlfsi = array_sum(array_column($grouped_rows[$personnel_sort_order], 'period_mlfsi'));
        $total_period_jewelers = array_sum(array_column($grouped_rows[$personnel_sort_order], 'period_jewelers'));
        $total_period_total = array_sum(array_column($grouped_rows[$personnel_sort_order], 'period_total'));
        $total_head_office = array_sum(array_column($grouped_rows[$personnel_sort_order], 'head_office'));
        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;
        
        $final_table_rows[] = [
            'sort_order' => $personnel_sort_order,
            'sub_order' => '',
            'gl_description' => 'Total Personnel Expenses',
            'is_section_header' => false,
            'is_summary_row' => true,
            'period_mlfsi' => $total_period_mlfsi,
            'period_jewelers' => $total_period_jewelers,
            'period_total' => $group_total_with_head,
            'head_office' => $total_head_office
        ];
    }

    // STEP 11: Empty spacer after Personnel Expenses
    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 12: Administrative Expenses detail rows with total
    if (isset($grouped_rows[$admin_sort_order])) {
        foreach ($grouped_rows[$admin_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
        // Add total row for Administrative Expenses
        $total_period_mlfsi = array_sum(array_column($grouped_rows[$admin_sort_order], 'period_mlfsi'));
        $total_period_jewelers = array_sum(array_column($grouped_rows[$admin_sort_order], 'period_jewelers'));
        $total_period_total = array_sum(array_column($grouped_rows[$admin_sort_order], 'period_total'));
        $total_head_office = array_sum(array_column($grouped_rows[$admin_sort_order], 'head_office'));
        $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;
        
        $final_table_rows[] = [
            'sort_order' => $admin_sort_order,
            'sub_order' => '',
            'gl_description' => 'Total Administrative Expenses',
            'is_section_header' => false,
            'is_summary_row' => true,
            'period_mlfsi' => $total_period_mlfsi,
            'period_jewelers' => $total_period_jewelers,
            'period_total' => $group_total_with_head,
            'head_office' => $total_head_office
        ];
    }

    // STEP 13: Empty spacer after Administrative Expenses
    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 14: "TOTAL SELLING AND ADMIN EXPENSES" = Personnel + Administrative
    $final_table_rows[] = [
        'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
        'sub_order' => '',
        'gl_description' => '',
        'is_section_header' => false,
        'is_summary_row' => true,
        'period_mlfsi' => $sa_mlfsi,
        'period_jewelers' => $sa_jew,
        'period_total' => $sa_tot,
        'head_office' => $sa_head
    ];

    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 15: "EBITDA"
    $final_table_rows[] = [
        'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
        'sub_order' => '',
        'gl_description' => '',
        'is_section_header' => false,
        'is_summary_row' => true,
        'skip_spacer' => true,
        'period_mlfsi' => $ebitda_mlfsi,
        'period_jewelers' => $ebitda_jew,
        'period_total' => $ebitda_tot,
        'head_office' => $ebitda_head
    ];

    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 16: Depreciation detail rows (sort_order 26 for old, 27 for new)
    // No header row, just the detail rows
    $dep_sort_order = ($gl_code_mode === 'old') ? 26 : 27;
    if (isset($grouped_rows[$dep_sort_order])) {
        foreach ($grouped_rows[$dep_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
    }

    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 17: "EARNINGS BEFORE INTEREST & TAXES"
    $final_table_rows[] = [
        'sort_order' => 'EARNINGS BEFORE INTEREST & TAXES',
        'sub_order' => '',
        'gl_description' => '',
        'is_section_header' => false,
        'is_summary_row' => true,
        'skip_spacer' => true,
        'period_mlfsi' => $ebit_mlfsi,
        'period_jewelers' => $ebit_jew,
        'period_total' => $ebit_tot,
        'head_office' => $ebit_head
    ];

    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 18: Interest Expense detail rows (sort_order 27 for old, 28 for new)
    // No header row, just the detail rows
    $interest_sort_order = ($gl_code_mode === 'old') ? 27 : 28;
    if (isset($grouped_rows[$interest_sort_order])) {
        foreach ($grouped_rows[$interest_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
    }

    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 19: "EARNINGS BEFORE TAXES"
    $final_table_rows[] = [
        'sort_order' => 'EARNINGS BEFORE TAXES',
        'sub_order' => '',
        'gl_description' => '',
        'is_section_header' => false,
        'is_summary_row' => true,
        'skip_spacer' => true,
        'period_mlfsi' => $ebt_mlfsi,
        'period_jewelers' => $ebt_jew,
        'period_total' => $ebt_tot,
        'head_office' => $ebt_head
    ];

    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 20: Income Tax detail rows (sort_order 28 for old, 29 for new)
    // No header row, just the detail rows
    $tax_sort_order = ($gl_code_mode === 'old') ? 28 : 29;
    if (isset($grouped_rows[$tax_sort_order])) {
        foreach ($grouped_rows[$tax_sort_order] as $row) {
            $final_table_rows[] = $row;
        }
    }

    $final_table_rows[] = ['is_manual_spacer' => true];

    // STEP 21: "TOTAL NET INCOME/LOSS"
    $final_table_rows[] = [
        'sort_order' => 'TOTAL NET INCOME/LOSS',
        'sub_order' => '',
        'gl_description' => '',
        'is_section_header' => false,
        'is_summary_row' => true,
        'skip_spacer' => true,
        'period_mlfsi' => $net_mlfsi,
        'period_jewelers' => $net_jew,
        'period_total' => $net_tot,
        'head_office' => $net_head
    ];

    return $final_table_rows;
}

// ============================================================
// BUILD THE THREE DATASETS: NATIONWIDE (= LNCR + VISMIN), LNCR, VISMIN
// (they are computed separately, then MERGED into ONE table below)
// ============================================================
$tables = [];

// Fixed order: NATIONWIDE (sum of LNCR+VISMIN), LNCR, VISMIN
$fixed_mainzones = [
    'NATIONWIDE' => '',      // empty => LNCR + VISMIN only (see data helpers)
    'LNCR'       => 'LNCR',
    'VISMIN'     => 'VISMIN',
];

foreach ($fixed_mainzones as $display_name => $filter_mainzone) {
    $tables[] = [
        'mainzone' => $display_name,
        'rows' => compute_table_rows_for_mainzone(
            $conn,
            $filter_mainzone,
            $transaction_year,
            $selected_period,
            $gl_code_mode,
            $gl_mapping,
            $gl_descriptions,
            $special_keys,
            $sort_order_descriptions,
            $gl_id_by_key,
            $old_gl_mapping,
            $new_gl_mapping,
            $valid_filters,
            $ml_properties_group_active
        ),
    ];
}

// ============================================================
// MERGE INTO ONE TABLE
//   NATIONWIDE | LNCR | % | VISMIN | % | HO | %
//
// - NATIONWIDE rows are the base (same structure for all 3 datasets).
// - LNCR / VISMIN rows are matched by sort_order|sub_order|summary flag.
// - HO = the HEAD OFFICE column of the NATIONWIDE dataset.
// - Each % = (column amount / NATIONWIDE amount of the SAME row) * 100.
// ============================================================
$row_lookup_key = function (array $r): string {
    return ($r['sort_order'] ?? '') . '|' . ($r['sub_order'] ?? '') . '|' . (!empty($r['is_summary_row']) ? '1' : '0');
};

$lncr_lookup = [];
foreach (($tables[1]['rows'] ?? []) as $r) {
    $lncr_lookup[$row_lookup_key($r)] = $r;
}
$vismin_lookup = [];
foreach (($tables[2]['rows'] ?? []) as $r) {
    $vismin_lookup[$row_lookup_key($r)] = $r;
}

$merged_rows = [];
foreach (($tables[0]['rows'] ?? []) as $n) {
    $k = $row_lookup_key($n);
    $l = $lncr_lookup[$k]   ?? [];
    $v = $vismin_lookup[$k] ?? [];

    $nationwide_total = (float)($n['period_total'] ?? 0);
    $lncr_total       = (float)($l['period_total'] ?? 0);
    $vismin_total     = (float)($v['period_total'] ?? 0);
    $ho_total         = (float)($n['head_office'] ?? 0);

    // INJ-2 detail rows are shown with the opposite sign (same as before)
    if (empty($n['is_summary_row']) && !empty($n['is_inj2'])) {
        $nationwide_total = -$nationwide_total;
        $lncr_total       = -$lncr_total;
        $vismin_total     = -$vismin_total;
        $ho_total         = -$ho_total;
    }

    $merged_rows[] = [
        'sort_order'        => $n['sort_order'] ?? '',
        'sub_order'         => $n['sub_order'] ?? '',
        'gl_description'    => $n['gl_description'] ?? '',
        'is_section_header' => !empty($n['is_section_header']),
        'is_summary_row'    => !empty($n['is_summary_row']),
        'skip_spacer'       => !empty($n['skip_spacer']),
        'is_manual_spacer'  => !empty($n['is_manual_spacer']),
        'nationwide_total'  => $nationwide_total,
        'lncr_total'        => $lncr_total,
        'vismin_total'      => $vismin_total,
        'ho_total'          => $ho_total,
    ];
}

/** Percentage of a column amount against the NATIONWIDE amount on the same row. */
function pct_of(float $amount, float $nationwide): float {
    return ($nationwide != 0 && $amount != 0) ? ($amount / $nationwide) * 100 : 0.0;
}

/** Render one numeric <td>. */
function amount_cell(float $value, bool $is_summary, bool $blank, bool $is_percent = false): string {
    $cls   = 'numeric-cell' . ($is_summary ? ' summary-cell' : '');
    $style = (!$is_percent && $value < 0) ? ' style="color: red;"' : '';
    $text  = '';
    if (!$blank) {
        $text = number_format($value, 2);
        if ($is_summary) {
            $text = '<strong>' . $text . '</strong>';
        }
    }
    return '<td class="' . $cls . '"' . $style . '>' . $text . '</td>';
}

// Predefined colors for the table header title
$header_colors = [
    '#ad1111'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EXPENSE CONTRIBUTION PERCENTAGE REPORT</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/comparative_original.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Single combined table layout (same as page_nine) */
        .three-tables-scroll {
            width: 99.5%;
            overflow-x: auto;
            overflow-y: auto;
            padding-bottom: 10px;
        }

        .three-tables-grid {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            flex-wrap: nowrap;
            width: 99.5%;
            padding: 4px 2px 10px 2px;
        }

        .three-tables-grid .table-container {
            flex: 1 1 99.5%;
            width: 99.5%;
            max-height: 60vh;
            display: flex;
            flex-direction: column;
        }

        .three-tables-grid .data-table {
            width: 99.5%;
            min-width: 1100px;
            border-collapse: collapse;
        }

        /* Sticky header container */
        .table-sticky-header {
            position: sticky;
            top: 0;
            z-index: 20;
            background: white;
        }

        /* Table header title - sticky */
        .table-header-title {
            padding: 10px 15px;
            font-weight: 700;
            font-size: 14px;
            text-align: center;
            border-bottom: 2px solid #e0e0e0;
            letter-spacing: 0.5px;
            color: white !important;
            position: sticky;
            top: 0;
            z-index: 25;
        }

        /* Table header row - sticky */
        .data-table thead {
            position: sticky;
            top: 38px; /* Height of the title bar */
            z-index: 20;
        }

        .data-table thead th {
            padding: 8px 10px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            background: linear-gradient(45deg, #ff170f, #b50006);
            white-space: nowrap;
        }

        .three-tables-grid .table-container::-webkit-scrollbar {
            height: 8px;
            width: 8px;
        }

        .three-tables-grid .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .three-tables-grid .table-container::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .three-tables-grid .table-container::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .data-table td:first-child {
            text-align: center;
        }

        .data-table td:nth-child(4) {
            text-align: center;
        }

        /* Compact table */
        .three-tables-grid .data-table th,
        .three-tables-grid .data-table td {
            padding: 4px 8px;
            font-size: 12px;
        }

        .three-tables-grid .data-table .summary-row td {
            font-size: 12px;
        }

        /* Visual separator between the column groups */
        .data-table .group-start {
            border-left: 2px solid #d1d5db;
        }

        @media (max-width: 1024px) {
            .three-tables-grid .data-table {
                min-width: 900px;
            }
            .three-tables-grid .data-table th,
            .three-tables-grid .data-table td {
                padding: 3px 5px;
                font-size: 10px;
            }
            .data-table thead {
                top: 38px; /* Smaller title height on mobile */
            }
        }

        .btn-collapse-three {
            background: linear-gradient(135deg, #5f43ff 0%, #002a79 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            margin-left: auto;
        }
        .btn-collapse-three:hover {
            transform: translateY(-2px);
            background-color: #001f50;
        }

        .filter-actions-three {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        /* Remove mainzone filter group */
        .filter-group--hidden {
            display: none !important;
        }

        /* Style for section header rows (e.g. SELLING & ADMIN EXPENSE) */
        .cos-header-row td {
            background-color: #ffddba !important;
            font-weight: bold !important;
            color: black !important;
        }
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
            <div class="page-title">EXPENSE CONTRIBUTION PERCENTAGE REPORT</div>
            <div style="text-align: center; color: #666; font-size: 14px; margin-bottom: 20px;">
                <i class="fa-solid fa-layer-group"></i> Showing: NATIONWIDE &bull; LNCR &bull; VISMIN &bull; HEAD OFFICE
            </div>

            <!-- Error Banner for validation issues -->
            <?php if ($show_error && !empty($error_message)): ?>
                <div class="error-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <!-- Filter Form (no mainzone filter — always shows NATIONWIDE, LNCR, VISMIN) -->
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
                    <label>Period</label>
                    <input type="month" name="selected_period" id="selectedPeriodSelect" value="<?= htmlspecialchars($selected_period) ?>">
                </div>

                <div class="filter-actions-three">
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    <button type="button" class="btn-collapse-three" id="collapseBtnThree">
                        <i class="fa-solid fa-compress"></i> Collapse
                    </button>
                    <a href="export_comparative_page_ten_eleven.php?<?= htmlspecialchars(http_build_query($_GET)) ?>" class="btn-export"><i class="fa-solid fa-file-excel"></i> Export Excel</a>

                    <a href="?reset=1" class="btn-reset"><i class="fa-solid fa-rotate-left"></i> Clear</a>

                </div>
            </form>

            <!-- Single Combined Table (same layout as page_nine) -->
            <div class="three-tables-scroll">
                <div class="three-tables-grid">
                    <?php $color = $header_colors[0]; ?>
                    <div class="table-container">
                        <!-- Sticky Title -->
                        <div class="table-header-title" style="background: <?= $color ?> !important;">
                            <i class="fa-solid fa-building"></i> NATIONWIDE &bull; LNCR &bull; VISMIN &bull; HO
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th colspan="4">EXPENSES</th>
                                    <th>NATIONWIDE</th>
                                    <th class="group-start">LNCR</th>
                                    <th>%</th>
                                    <th class="group-start">VISMIN</th>
                                    <th>%</th>
                                    <th class="group-start">HO</th>
                                    <th>%</th>
                                </tr>
                            </thead>
                            <tbody class="report-tbody">
                                <?php if (empty($merged_rows)): ?>
                                    <tr>
                                        <td colspan="11" style="text-align: center;">No data structure available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($merged_rows as $row): 
                                        if (!empty($row['is_manual_spacer'])) {
                                            echo '<tr class="spacer-row" style="height: 15px;"><td colspan="11"></td></tr>';
                                            continue;
                                        }
                                        $is_summary_row = $row['is_summary_row'];
                                        $is_header      = $row['is_section_header'];

                                        // Section header rows (e.g. SELLING & ADMIN EXPENSE) — no amounts
                                        $is_section_header_row = (
                                            $row['sort_order'] === 'SELLING & ADMIN EXPENSE' ||
                                            $row['sort_order'] === 'COST OF SALES/SERVICE'
                                        );
                                        $blank = ($is_header || $is_section_header_row);

                                        $nationwide_total = $row['nationwide_total'];
                                        $lncr_total       = $row['lncr_total'];
                                        $vismin_total     = $row['vismin_total'];
                                        $ho_total         = $row['ho_total'];

                                        // % = column amount / NATIONWIDE amount of the SAME row
                                        $lncr_pct   = pct_of($lncr_total,   $nationwide_total);
                                        $vismin_pct = pct_of($vismin_total, $nationwide_total);
                                        $ho_pct     = pct_of($ho_total,     $nationwide_total);

                                        $row_class = $is_summary_row ? 'summary-row' : 'data-row';
                                        if ($is_section_header_row) {
                                            $row_class .= ' cos-header-row';
                                        }
                                    ?>
                                        <!-- Spacer row ABOVE the summary row -->
                                        <?php if ($is_summary_row && !$is_header && !$is_section_header_row && empty($row['skip_spacer'])): ?>
                                            <tr class="spacer-row" data-spacer-for="<?= htmlspecialchars((string)$row['sort_order']) ?>" style="height: 15px;"><td colspan="11"></td></tr>
                                        <?php endif; ?>

                                        <tr class="<?= $row_class ?>"
                                            data-sort-order="<?= htmlspecialchars((string)$row['sort_order']) ?>"
                                            <?php if (!$is_summary_row): ?>
                                                data-is-detail="true"
                                            <?php endif; ?>>
                                            <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"><?= '' ?></td>
                                            <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>">
                                                <?php if ($is_section_header_row): ?>
                                                    <strong><?= htmlspecialchars((string)$row['sort_order']) ?></strong>
                                                <?php elseif ($is_header): ?>
                                                    <strong><?= htmlspecialchars((string)$row['sub_order']) ?></strong>
                                                <?php elseif ($is_summary_row): ?>
                                                    <strong><?= htmlspecialchars($row['gl_description'] ?: (string)$row['sort_order']) ?></strong>
                                                <?php endif; ?>
                                            </td>
                                            <td class="<?= $is_summary_row ? 'summary-cell summary-description' : '' ?>"><?= !$is_summary_row ? htmlspecialchars($row['gl_description']) : '' ?></td>
                                            <td class="<?= $is_summary_row ? 'summary-cell' : '' ?>"></td>

                                            <?= amount_cell($nationwide_total, $is_summary_row, $blank) ?>

                                            <?= amount_cell($lncr_total, $is_summary_row, $blank) ?>
                                            <?= amount_cell($lncr_pct, $is_summary_row, $blank, true) ?>

                                            <?= amount_cell($vismin_total, $is_summary_row, $blank) ?>
                                            <?= amount_cell($vismin_pct, $is_summary_row, $blank, true) ?>

                                            <?= amount_cell($ho_total, $is_summary_row, $blank) ?>
                                            <?= amount_cell($ho_pct, $is_summary_row, $blank, true) ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <script>
                // Helper functions
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
                    const selectedPeriod = document.getElementById('selectedPeriodSelect').value;
                    const glOldRadio = document.getElementById('glOldRadio');
                    const glNewRadio = document.getElementById('glNewRadio');
                    const glCodeMode = glOldRadio.checked ? 'old' : (glNewRadio.checked ? 'new' : 'old');

                    if (!selectedPeriod) {
                        return true;
                    }

                    if (glCodeMode === 'old') {
                        if (!isMarch2026OrEarlier(selectedPeriod)) {
                            showModal('Old GL Code is only available for March 2026 and earlier. Selected period must be March 2026 or earlier.');
                            return false;
                        }
                    } else if (glCodeMode === 'new') {
                        if (!isApril2026OrLater(selectedPeriod)) {
                            showModal('New GL Code is only available for April 2026 onwards. Selected period must be April 2026 or later.');
                            return false;
                        }
                    }

                    return true;
                }

                document.addEventListener('DOMContentLoaded', function() {
                    const collapseBtn = document.getElementById('collapseBtnThree');
                    let isCollapsed = false;

                    if (collapseBtn) {
                        collapseBtn.addEventListener('click', function() {
                            isCollapsed = !isCollapsed;
                            
                            const tbodies = document.querySelectorAll('.report-tbody');
                            tbodies.forEach(tbody => {
                                const rows = Array.from(tbody.rows);
                                rows.forEach(row => {
                                    const isDetail = row.getAttribute('data-is-detail') === 'true';
                                    const spacerFor = row.getAttribute('data-spacer-for');
                                    
                                    // Collapse all detail rows (Personnel / Admin / Dep / Interest / Tax lines)
                                    if (isDetail) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }
                                    
                                    // Also hide spacers that belong to collapsed sections
                                    if (spacerFor) {
                                        row.style.display = isCollapsed ? 'none' : '';
                                    }
                                });
                            });

                            collapseBtn.innerHTML = isCollapsed 
                                ? '<i class="fa-solid fa-expand"></i> Uncollapse' 
                                : '<i class="fa-solid fa-compress"></i> Collapse';
                            
                            collapseBtn.style.backgroundColor = isCollapsed ? '#1f2937' : '#4b5563';
                        });
                    }
                });
            </script>

            <!-- Modal Styles (included in page) -->
            <style>
                .modal-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.5);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 1000;
                }
                .modal-container {
                    background: white;
                    border-radius: 12px;
                    width: 90%;
                    max-width: 450px;
                    box-shadow: 0 20px 35px rgba(0,0,0,0.2);
                    animation: modalSlideIn 0.2s ease-out;
                }
                @keyframes modalSlideIn {
                    from { opacity: 0; transform: translateY(-20px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .modal-header {
                    padding: 20px 24px 12px 24px;
                    border-bottom: 1px solid #e5e7eb;
                }
                .modal-header h3 {
                    margin: 0;
                    font-size: 1.25rem;
                    font-weight: 600;
                    color: #dc2626;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .modal-header h3 i {
                    font-size: 1.5rem;
                }
                .modal-body {
                    padding: 20px 24px;
                    color: #374151;
                    font-size: 0.95rem;
                    line-height: 1.5;
                }
                .modal-footer {
                    padding: 12px 24px 20px 24px;
                    display: flex;
                    justify-content: flex-end;
                    border-top: 1px solid #e5e7eb;
                }
                .modal-footer button {
                    background: #dc2626;
                    border: none;
                    padding: 8px 20px;
                    border-radius: 6px;
                    color: white;
                    font-weight: 500;
                    cursor: pointer;
                    transition: background 0.2s;
                }
                .modal-footer button:hover {
                    background: #b91c1c;
                }
                .error-banner {
                    background: #fee2e2;
                    border: 1px solid #fecaca;
                    border-radius: 8px;
                    padding: 12px 16px;
                    margin-bottom: 20px;
                    color: #991b1b;
                    font-size: 0.9rem;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                .error-banner i {
                    font-size: 1.2rem;
                }
            </style>

        </div>
    </main>

    <?php include '../footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>
