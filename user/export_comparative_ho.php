<?php

// export_comparative_ho.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit;
}

$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period   = $_GET['primary_period']   ?? '';
$previous_period  = $_GET['previous_period']  ?? '';
$third_period     = $_GET['third_period']     ?? '';
$gl_code_mode     = $_GET['gl_code_mode']     ?? 'old';
$gl_code_mode     = in_array($gl_code_mode, ['old', 'new', 'mixed'], true) ? $gl_code_mode : 'old';

// ── helpers ───────────────────────────────────────────────────────────────────

function compareMonths(string $a, string $b): int {
    return strtotime($a . '-01') - strtotime($b . '-01');
}

function isMarch2026OrEarlier(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') <= strtotime('2026-03-01');
}

function isApril2026OrLater(string $month): bool {
    if (empty($month)) return true;
    return strtotime($month . '-01') >= strtotime('2026-04-01');
}

function colLetter(int $col): string {
    return Coordinate::stringFromColumnIndex($col);
}

// ── validate filters (mirrors comparative_report_original_ho.php exactly) ──

$valid_filters = false;
$error_message = '';

// Check for valid period selection combination
if (!empty($third_period) && (empty($primary_period) || empty($previous_period))) {
    $error_message = 'To use Period 3, both Primary and Previous periods must be selected.';
    $show_error = true;
} elseif (!empty($previous_period) && empty($primary_period)) {
    $error_message = 'Primary period is required when selecting a Previous period.';
    $show_error = true;
} else {
    $show_error = false;
}

// Only validate if both periods are provided
if (!$show_error && !empty($primary_period) && !empty($previous_period)) {
    
    // Primary period must be greater than previous period
    if (compareMonths($primary_period, $previous_period) <= 0) {
        $error_message = 'Primary period must be later than the Previous period.';
        $show_error = true;
    }
    
    // Primary period must be greater than Period 3
    if (!$show_error && !empty($third_period) && compareMonths($primary_period, $third_period) <= 0) {
        $error_message = 'Primary period must be later than Period 3.';
        $show_error = true;
    }
    
    // GL code mode restrictions
    if (!$show_error) {
        if ($gl_code_mode === 'old') {
            if (
                !isMarch2026OrEarlier($primary_period) ||
                !isMarch2026OrEarlier($previous_period) ||
                (!empty($third_period) && !isMarch2026OrEarlier($third_period))
            ) {
                $error_message = 'Old GL Code is only available for March 2026 and earlier. Both selected periods must be March 2026 or earlier.';
                $show_error = true;
            }
        } elseif ($gl_code_mode === 'new') {
            if (
                !isApril2026OrLater($primary_period) ||
                !isApril2026OrLater($previous_period) ||
                (!empty($third_period) && !isApril2026OrLater($third_period))
            ) {
                $error_message = 'New GL Code is only available for April 2026 onwards. Both selected periods must be April 2026 or later.';
                $show_error = true;
            }
        }
        // Mixed mode allows periods from both sides of the cutoff
    }
    
    if (!$show_error) {
        $valid_filters = true;
    }
}

// ── GL mapping (identical to original) ───────────────────────────────────────

$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];
$gl_id_by_key = [];

// ============================================================
// MIXED MODE LOOKUPS (identical to original)
// ============================================================

$old_gl_id_to_codes = [];
$mixed_id_map = [];

if ($gl_code_mode === 'mixed') {
    
    // Load all old codes
    $res = mysqli_query(
        $conn,
        "
        SELECT gl_id, gl_code
        FROM fs_reports.gl_codes_ho
        WHERE gl_code IS NOT NULL
          AND gl_code != ''
        "
    );
    
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $old_gl_id_to_codes[$row['gl_id']][] = trim($row['gl_code']);
        }
    }
    
    // New GL ID => old GL IDs
    $mixed_id_map = [
        'INS-1' => ['INS-28', 'INS-29', 'INS-30', 'INS-31', 'INS-34', 'INS-39'],
        'INS-2' => ['INS-25', 'INS-26', 'INS-44', 'INS-47'],
        'INS-3' => ['INS-32', 'INS-33', 'INS-42', 'INS-43', 'INS-45'],
        'INS-4' => ['INS-27', 'INS-46'],
        'INS-5' => ['INS-20', 'INS-21', 'INS-22', 'INS-23', 'INS-24', 'INS-37', 'INS-41'],
        'INS-6' => ['INS-1', 'INS-2', 'INS-3', 'INS-4', 'INS-5', 'INS-6', 'INS-7', 'INS-8', 'INS-9', 'INS-10', 'INS-11', 'INS-12', 'INS-13', 'INS-14', 'INS-35', 'INS-36', 'INS-40'],
        'INS-7' => ['INS-15', 'INS-16', 'INS-17', 'INS-18', 'INS-19'],
        'INS-10' => ['INS-38'],
        'INS-11' => ['INS-48'],
        'INS-12' => ['INS-49'],
        'INJ-1' => ['INJ-1'],
        'INJ-2' => ['INJ-2'],
        'INJ-3' => ['INJ-3'],
        'INJ-4' => ['INJ-4'],
        'INJ-5' => ['INJ-5'],
        'INJ-6' => ['INJ-6'],
        'INJ-7' => ['INJ-7'],
        'INJ-8' => ['INJ-8'],
        'INJ-9' => ['INJ-9'],
        'INJ-10' => ['INJ-10'],
        'INJ-11' => ['INJ-11'],
        'INJ-12' => ['INJ-12'],
        'INJ-13' => ['INJ-13'],
        'INJ-14' => ['INJ-14'],
        'INJ-15' => ['INJ-15'],
        'INJ-16' => ['INJ-16'],
        'INJ-17' => ['INJ-17'],
        'INJ-18' => ['INJ-18'],
        'INJ-19' => ['INJ-19'],
        'INJ-20' => ['INJ-20'],
        'INJ-21' => ['INJ-21'],
        'INJ-22' => ['INJ-22'],
        'INJ-23' => ['INJ-23'],
        'INJ-24' => ['INJ-24'],
        'INJ-25' => ['INJ-25'],
        'INJ-26' => ['INJ-26'],
        'INJ-27' => ['INJ-27'],
        'INJ-28' => ['INJ-28'],
        'INJ-29' => ['INJ-29'],
        'INJ-30' => ['INJ-30'],
        'INJ-31' => ['INJ-31'],
        'INJ-32' => ['INJ-32'],
        'INJ-33' => ['INJ-33'],
        'INJ-34' => ['INJ-34'],
        'INJ-35' => ['INJ-35'],
        'INJ-36' => ['INJ-36'],
        'INJ-37' => ['INJ-37'],
        'INJ-38' => ['INJ-38'],
        'INJ-39' => ['INJ-39'],
        'INJ-40' => ['INJ-40'],
        'INJ-41' => ['INJ-41'],
        'INJ-42' => ['INJ-42'],
        'INJ-43' => ['INJ-43'],
        'INJ-44' => ['INJ-44'],
        'INJ-45' => ['INJ-45'],
        'INJ-46' => ['INJ-46'],
        'INJ-47' => ['INJ-47'],
        'INJ-48' => ['INJ-48'],
        'INJ-49' => ['INJ-49']
    ];
}

// ============================================================
// DETERMINE GL STRUCTURE TABLE (identical to original)
// ============================================================

$table_name = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes_ho' : 'fs_reports.new_gl_codes_ho';

$gl_structure_query = "
    SELECT DISTINCT
        sort_order,
        sub_order,
        gl_id,
        gl_code,
        gl_description_comparative,
        description
    FROM {$table_name}
    WHERE sort_order IS NOT NULL
      AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";

$gl_structure_result = mysqli_query($conn, $gl_structure_query);

if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key = $row['sort_order'] . '|' . $row['sub_order'];
        $gl_id = $row['gl_id'] ?? '';
        
        // Save GL ID for this row
        $gl_id_by_key[$key] = $gl_id;
        
        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }
        
        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = ['old' => [], 'new' => []];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }
        
        $current_code = trim((string)($row['gl_code'] ?? ''));
        
        if ($gl_code_mode === 'mixed') {
            // New code
            if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['new'], true)) {
                $gl_mapping[$key]['new'][] = $current_code;
            }
            
            // Old codes
            $target_old_ids = $mixed_id_map[$gl_id] ?? [$gl_id];
            foreach ($target_old_ids as $oid) {
                if (isset($old_gl_id_to_codes[$oid])) {
                    foreach ($old_gl_id_to_codes[$oid] as $oc) {
                        if (!in_array($oc, $gl_mapping[$key]['old'], true)) {
                            $gl_mapping[$key]['old'][] = $oc;
                        }
                    }
                }
            }
        } else {
            if ($gl_code_mode === 'old') {
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['old'], true)) {
                    $gl_mapping[$key]['old'][] = $current_code;
                }
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['new'], true)) {
                    $gl_mapping[$key]['new'][] = $current_code;
                }
            } else {
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['new'], true)) {
                    $gl_mapping[$key]['new'][] = $current_code;
                }
                if ($current_code !== '' && !in_array($current_code, $gl_mapping[$key]['old'], true)) {
                    $gl_mapping[$key]['old'][] = $current_code;
                }
            }
        }
        
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

// ============================================================
// HELPER: GET TOTAL FOR A SPECIFIC GL ID (identical to original)
// ============================================================

function get_gl_id_total(
    string $gl_id,
    array $gl_mapping,
    array $gl_id_by_key,
    array $data,
    string $mode
): float {
    $total = 0.0;
    
    foreach ($gl_mapping as $key => $codes_detailed) {
        if (($gl_id_by_key[$key] ?? '') !== $gl_id) {
            continue;
        }
        $codes = $codes_detailed[$mode] ?? [];
        foreach ($codes as $gl_code) {
            if (isset($data[$gl_code])) {
                $total += (float)$data[$gl_code];
            }
        }
    }
    return $total;
}

// ── compute rows (mirrors compute_table_rows_for_region_area exactly) ──────

function compute_table_rows_for_export(
    mysqli $conn,
    string $transaction_year,
    string $primary_period,
    string $previous_period,
    string $third_period,
    string $gl_code_mode,
    array  $gl_mapping,
    array  $gl_descriptions,
    array  $special_keys,
    array  $sort_order_descriptions,
    array  $gl_id_by_key,
    bool   $use_real_data
): array {

    // ========================================================
    // BUILD WHERE CLAUSE (identical to original)
    // ========================================================
    
    $where_conditions = [];
    $params = [];
    $types = "";
    
    if (!empty($transaction_year)) {
        $where_conditions[] = "transaction_year = ?";
        $params[] = $transaction_year;
        $types .= "s";
    }
    
    $base_where = !empty($where_conditions)
        ? "WHERE " . implode(" AND ", $where_conditions)
        : "WHERE 1=1";
    
    // Exclude voided transactions
    $base_where .= " AND (status_void IS NULL OR status_void != 'Void')";
    
    // ========================================================
    // FETCH PERIOD DATA (identical to original)
    // ========================================================
    
    $fetch_period = function (string $period) use ($conn, $base_where, $params, $types, $use_real_data): array {
        if (!$use_real_data || empty($period)) return [];
        
        $parts = explode('-', $period);
        $year_val = $parts[0];
        $month_val = $period . '-01';
        
        $sql = "
            SELECT gl_code, SUM(amount) AS total_amount
            FROM fs_reports.comparative_report
            $base_where
            AND transaction_year = ? AND transaction_month = ?
            AND gl_code IS NOT NULL AND gl_code != ''
            GROUP BY gl_code
        ";
        
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return [];
        
        $bind = array_merge($params, [$year_val, $month_val]);
        mysqli_stmt_bind_param($stmt, $types . 'ss', ...$bind);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $data = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $data[$r['gl_code']] = floatval($r['total_amount']);
        }
        mysqli_stmt_close($stmt);
        return $data;
    };
    
    $primary_data  = $fetch_period($primary_period);
    $previous_data = $fetch_period($previous_period);
    $third_data    = $fetch_period($third_period);
    
    // ========================================================
    // BUILD TABLE ROWS (identical to original)
    // ========================================================
    
    $table_rows = [];
    
    foreach ($gl_mapping as $key => $codes_detailed) {
        [$sort_order, $sub_order] = explode('|', $key);
        $gl_description = $gl_descriptions[$key] ?? '';
        $is_inj2 = in_array($key, $special_keys, true);
        $current_gl_id = $gl_id_by_key[$key] ?? '';
        
        // Determine mode per period
        $p_mode = $gl_code_mode;
        $prev_mode = $gl_code_mode;
        $t_mode = $gl_code_mode;
        
        if ($gl_code_mode === 'mixed') {
            $p_mode = isApril2026OrLater($primary_period) ? 'new' : 'old';
            $prev_mode = isApril2026OrLater($previous_period) ? 'new' : 'old';
            $t_mode = (!empty($third_period) && isApril2026OrLater($third_period)) ? 'new' : 'old';
        }
        
        $p_codes = $codes_detailed[$p_mode] ?? [];
        $prev_codes = $codes_detailed[$prev_mode] ?? [];
        $t_codes = $codes_detailed[$t_mode] ?? [];
        
        $primary_total = 0.0;
        $previous_total = 0.0;
        $third_total = 0.0;
        
        // Normal GL calculation
        foreach ($p_codes as $gl_code) {
            if (isset($primary_data[$gl_code])) {
                $primary_total += $primary_data[$gl_code];
            }
        }
        foreach ($prev_codes as $gl_code) {
            if (isset($previous_data[$gl_code])) {
                $previous_total += $previous_data[$gl_code];
            }
        }
        foreach ($t_codes as $gl_code) {
            if (isset($third_data[$gl_code])) {
                $third_total += $third_data[$gl_code];
            }
        }
        
        // ====================================================
        // SPECIAL CALCULATION: INJ-3 (identical to original)
        // ====================================================
        
        if ((int)$sort_order === 18 && $current_gl_id === 'INJ-3') {
            $primary_total = 0.0;
            $previous_total = 0.0;
            $third_total = 0.0;
            
            // Process INJ-1 through INJ-49
            for ($inj_number = 1; $inj_number <= 49; $inj_number++) {
                // INJ-3 is the calculated result, so never include its actual amount
                if ($inj_number === 3) continue;
                
                $inj_id = 'INJ-' . $inj_number;
                
                $inj_primary = get_gl_id_total(
                    $inj_id,
                    $GLOBALS['gl_mapping'],
                    $GLOBALS['gl_id_by_key'],
                    $primary_data,
                    $p_mode
                );
                
                $inj_previous = get_gl_id_total(
                    $inj_id,
                    $GLOBALS['gl_mapping'],
                    $GLOBALS['gl_id_by_key'],
                    $previous_data,
                    $prev_mode
                );
                
                $inj_third = get_gl_id_total(
                    $inj_id,
                    $GLOBALS['gl_mapping'],
                    $GLOBALS['gl_id_by_key'],
                    $third_data,
                    $t_mode
                );
                
                // INJ-2: use the display value (sign-flipped raw)
                if ($inj_number === 2) {
                    $inj_primary_display = -$inj_primary;
                    $inj_previous_display = -$inj_previous;
                    $inj_third_display = -$inj_third;
                    
                    $primary_total -= $inj_primary_display;
                    $previous_total -= $inj_previous_display;
                    $third_total -= $inj_third_display;
                } else {
                    $primary_total += $inj_primary;
                    $previous_total += $inj_previous;
                    $third_total += $inj_third;
                }
            }
        }
        
        // INJ-2 DISPLAY SIGN FLIP (identical to original)
        if ($is_inj2) {
            $primary_total = -$primary_total;
            $previous_total = -$previous_total;
            $third_total = -$third_total;
        }
        
        $table_rows[] = [
            'sort_order' => $sort_order,
            'sub_order' => $sub_order,
            'gl_id' => $current_gl_id,
            'gl_description' => $gl_description,
            'is_section_header' => false,
            'is_summary_row' => false,
            'primary_total' => $primary_total,
            'previous_total' => $previous_total,
            'third_total' => $third_total,
            'is_inj2' => $is_inj2
        ];
    }
    
    // ========================================================
    // GROUP BY SORT ORDER (identical to original)
    // ========================================================
    
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $sort_order = $row['sort_order'];
        if (!isset($grouped_rows[$sort_order])) {
            $grouped_rows[$sort_order] = [];
        }
        $grouped_rows[$sort_order][] = $row;
    }
    
    $final_table_rows = [];
    
    // ========================================================
    // CUMULATIVE TOTALS (identical to original)
    // ========================================================
    
    $rev_tot_p = 0;
    $rev_tot_prev = 0;
    $rev_tot_third = 0;
    $sa_tot_p = 0;
    $sa_tot_prev = 0;
    $sa_tot_third = 0;
    $gp_tot_p = 0;
    $gp_tot_prev = 0;
    $gp_tot_third = 0;
    $ebitda_tot_p = 0;
    $ebitda_tot_prev = 0;
    $ebitda_tot_third = 0;
    $ebit_tot_p = 0;
    $ebit_tot_prev = 0;
    $ebit_tot_third = 0;
    $ebt_tot_p = 0;
    $ebt_tot_prev = 0;
    $ebt_tot_third = 0;
    $net_tot_p = 0;
    $net_tot_prev = 0;
    $net_tot_third = 0;
    
    // ========================================================
    // BUILD SUMMARY ROWS (identical to original)
    // ========================================================
    
    foreach ($grouped_rows as $sort_order => $rows) {
        
        // Hide details for 11 and 14
        if (!in_array((int)$sort_order, [11, 14])) {
            foreach ($rows as $row) {
                $final_table_rows[] = $row;
            }
        }
        
        // For sort order 18 (Income from Jewelry), INJ-3 is already the full net
        if ((int)$sort_order === 18) {
            $total_primary_total = 0.0;
            $total_previous_total = 0.0;
            $total_third_total = 0.0;
            
            foreach ($rows as $row) {
                if (($row['gl_id'] ?? '') === 'INJ-3') {
                    $total_primary_total = (float)($row['primary_total'] ?? 0);
                    $total_previous_total = (float)($row['previous_total'] ?? 0);
                    $total_third_total = (float)($row['third_total'] ?? 0);
                    break;
                }
            }
        } else {
            $total_primary_total = array_sum(array_column($rows, 'primary_total'));
            $total_previous_total = array_sum(array_column($rows, 'previous_total'));
            $total_third_total = array_sum(array_column($rows, 'third_total'));
        }
        
        // Total revenues (sort 1-23)
        if ((int)$sort_order >= 1 && (int)$sort_order <= 23) {
            $rev_tot_p += $total_primary_total;
            $rev_tot_prev += $total_previous_total;
            $rev_tot_third += $total_third_total;
        }
        
        // Selling & Admin (sort 25-26)
        if ((int)$sort_order === 25 || (int)$sort_order === 26) {
            $sa_tot_p += $total_primary_total;
            $sa_tot_prev += $total_previous_total;
            $sa_tot_third += $total_third_total;
        }
        
        $inc_dec = $total_primary_total - $total_previous_total;
        $percentage = 0;
        if ($total_previous_total != 0) {
            $percentage = ($inc_dec / $total_previous_total) * 100;
        } elseif ($total_primary_total != 0) {
            $percentage = 100;
        }
        
        $inc_dec_third = $total_primary_total - $total_third_total;
        $percentage_third = (!empty($third_period) && $total_third_total != 0)
            ? ($inc_dec_third / abs($total_third_total)) * 100
            : (!empty($third_period) && $total_primary_total != 0 ? 100 : 0);
        
        $description = isset($sort_order_descriptions[$sort_order])
            ? $sort_order_descriptions[$sort_order]
            : "Total for Sort Order " . $sort_order;
        
        // Hide summary for 27,28,29
        if (!in_array((int)$sort_order, [27, 28, 29])) {
            $final_table_rows[] = [
                'sort_order' => $sort_order,
                'sub_order' => '',
                'gl_description' => $description,
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => $total_primary_total,
                'previous_total' => $total_previous_total,
                'third_total' => $total_third_total,
                'inc_dec' => $inc_dec,
                'percentage' => $percentage,
                'inc_dec_third' => $inc_dec_third,
                'percentage_third' => $percentage_third
            ];
        }
        
        // ====================================================
        // TOTAL REVENUES (identical to original)
        // ====================================================
        
        if ((int)$sort_order === 23) {
            $inc_dec_rev = $rev_tot_p - $rev_tot_prev;
            $pct_rev = $rev_tot_prev != 0
                ? ($inc_dec_rev / abs($rev_tot_prev)) * 100
                : ($rev_tot_p != 0 ? 100 : 0);
            
            $inc_dec_rev_third = $rev_tot_p - $rev_tot_third;
            $pct_rev_third = (!empty($third_period) && $rev_tot_third != 0)
                ? ($inc_dec_rev_third / abs($rev_tot_third)) * 100
                : (!empty($third_period) && $rev_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'sort_order' => 'TOTAL REVENUES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => $rev_tot_p,
                'previous_total' => $rev_tot_prev,
                'third_total' => $rev_tot_third,
                'inc_dec' => $inc_dec_rev,
                'percentage' => $pct_rev,
                'inc_dec_third' => $inc_dec_rev_third,
                'percentage_third' => $pct_rev_third
            ];
            
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
            
            $final_table_rows[] = [
                'sort_order' => '',
                'sub_order' => 'Cost of Sales/Service',
                'gl_description' => '',
                'is_section_header' => true,
                'is_summary_row' => true,
                'primary_total' => null,
                'previous_total' => null,
                'third_total' => null,
                'inc_dec' => null,
                'percentage' => null,
                'inc_dec_third' => null,
                'percentage_third' => null
            ];
        }
        
        // ====================================================
        // GROSS PROFIT (identical to original)
        // ====================================================
        
        if ((int)$sort_order === 24) {
            $gp_tot_p = $rev_tot_p - $total_primary_total;
            $gp_tot_prev = $rev_tot_prev - $total_previous_total;
            $gp_tot_third = $rev_tot_third - $total_third_total;
            
            $inc_dec_gp = $gp_tot_p - $gp_tot_prev;
            $pct_gp = $gp_tot_prev != 0
                ? ($inc_dec_gp / abs($gp_tot_prev)) * 100
                : ($gp_tot_p != 0 ? 100 : 0);
            
            $inc_dec_gp_third = $gp_tot_p - $gp_tot_third;
            $pct_gp_third = (!empty($third_period) && $gp_tot_third != 0)
                ? ($inc_dec_gp_third / abs($gp_tot_third)) * 100
                : (!empty($third_period) && $gp_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
            
            $final_table_rows[] = [
                'sort_order' => 'GROSS PROFIT',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => $gp_tot_p,
                'previous_total' => $gp_tot_prev,
                'third_total' => $gp_tot_third,
                'inc_dec' => $inc_dec_gp,
                'percentage' => $pct_gp,
                'inc_dec_third' => $inc_dec_gp_third,
                'percentage_third' => $pct_gp_third
            ];
            
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
            
            $final_table_rows[] = [
                'sort_order' => 'SELLING & ADMIN EXPENSE',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => true,
                'is_summary_row' => true,
                'primary_total' => null,
                'previous_total' => null,
                'third_total' => null,
                'inc_dec' => null,
                'percentage' => null,
                'inc_dec_third' => null,
                'percentage_third' => null
            ];
        }
        
        // Add spacer after sort order 25
        if ((int)$sort_order === 25) {
            $final_table_rows[] = ['is_manual_spacer' => true];
        }
        
        // ====================================================
        // TOTAL SELLING AND ADMIN EXPENSES (identical to original)
        // ====================================================
        
        if ((int)$sort_order === 26) {
            $inc_dec_sa = $sa_tot_p - $sa_tot_prev;
            $pct_sa = $sa_tot_prev != 0
                ? ($inc_dec_sa / abs($sa_tot_prev)) * 100
                : ($sa_tot_p != 0 ? 100 : 0);
            
            $inc_dec_sa_third = $sa_tot_p - $sa_tot_third;
            $pct_sa_third = (!empty($third_period) && $sa_tot_third != 0)
                ? ($inc_dec_sa_third / abs($sa_tot_third)) * 100
                : (!empty($third_period) && $sa_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
            
            $final_table_rows[] = [
                'sort_order' => 'TOTAL SELLING AND ADMIN EXPENSES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'primary_total' => $sa_tot_p,
                'previous_total' => $sa_tot_prev,
                'third_total' => $sa_tot_third,
                'inc_dec' => $inc_dec_sa,
                'percentage' => $pct_sa,
                'inc_dec_third' => $inc_dec_sa_third,
                'percentage_third' => $pct_sa_third
            ];
            
            // EBITDA
            $ebitda_tot_p = $gp_tot_p - $sa_tot_p;
            $ebitda_tot_prev = $gp_tot_prev - $sa_tot_prev;
            $ebitda_tot_third = $gp_tot_third - $sa_tot_third;
            
            $inc_dec_ebitda = $ebitda_tot_p - $ebitda_tot_prev;
            $pct_ebitda = $ebitda_tot_prev != 0
                ? ($inc_dec_ebitda / abs($ebitda_tot_prev)) * 100
                : ($ebitda_tot_p != 0 ? 100 : 0);
            
            $inc_dec_ebitda_third = $ebitda_tot_p - $ebitda_tot_third;
            $pct_ebitda_third = (!empty($third_period) && $ebitda_tot_third != 0)
                ? ($inc_dec_ebitda_third / abs($ebitda_tot_third)) * 100
                : (!empty($third_period) && $ebitda_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
            
            $final_table_rows[] = [
                'sort_order' => "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $ebitda_tot_p,
                'previous_total' => $ebitda_tot_prev,
                'third_total' => $ebitda_tot_third,
                'inc_dec' => $inc_dec_ebitda,
                'percentage' => $pct_ebitda,
                'inc_dec_third' => $inc_dec_ebitda_third,
                'percentage_third' => $pct_ebitda_third
            ];
        }
        
        // ====================================================
        // EBIT (identical to original)
        // ====================================================
        
        if ((int)$sort_order === 27) {
            $ebit_tot_p = $ebitda_tot_p - $total_primary_total;
            $ebit_tot_prev = $ebitda_tot_prev - $total_previous_total;
            $ebit_tot_third = $ebitda_tot_third - $total_third_total;
            
            $inc_dec_ebit = $ebit_tot_p - $ebit_tot_prev;
            $pct_ebit = $ebit_tot_prev != 0
                ? ($inc_dec_ebit / abs($ebit_tot_prev)) * 100
                : ($ebit_tot_p != 0 ? 100 : 0);
            
            $inc_dec_ebit_third = $ebit_tot_p - $ebit_tot_third;
            $pct_ebit_third = (!empty($third_period) && $ebit_tot_third != 0)
                ? ($inc_dec_ebit_third / abs($ebit_tot_third)) * 100
                : (!empty($third_period) && $ebit_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
            
            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE INTEREST & TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $ebit_tot_p,
                'previous_total' => $ebit_tot_prev,
                'third_total' => $ebit_tot_third,
                'inc_dec' => $inc_dec_ebit,
                'percentage' => $pct_ebit,
                'inc_dec_third' => $inc_dec_ebit_third,
                'percentage_third' => $pct_ebit_third
            ];
        }
        
        // ====================================================
        // EBT (identical to original)
        // ====================================================
        
        if ((int)$sort_order === 28) {
            $ebt_tot_p = $ebit_tot_p - $total_primary_total;
            $ebt_tot_prev = $ebit_tot_prev - $total_previous_total;
            $ebt_tot_third = $ebit_tot_third - $total_third_total;
            
            $inc_dec_ebt = $ebt_tot_p - $ebt_tot_prev;
            $pct_ebt = $ebt_tot_prev != 0
                ? ($inc_dec_ebt / abs($ebt_tot_prev)) * 100
                : ($ebt_tot_p != 0 ? 100 : 0);
            
            $inc_dec_ebt_third = $ebt_tot_p - $ebt_tot_third;
            $pct_ebt_third = (!empty($third_period) && $ebt_tot_third != 0)
                ? ($inc_dec_ebt_third / abs($ebt_tot_third)) * 100
                : (!empty($third_period) && $ebt_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
            
            $final_table_rows[] = [
                'sort_order' => 'EARNINGS BEFORE TAXES',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $ebt_tot_p,
                'previous_total' => $ebt_tot_prev,
                'third_total' => $ebt_tot_third,
                'inc_dec' => $inc_dec_ebt,
                'percentage' => $pct_ebt,
                'inc_dec_third' => $inc_dec_ebt_third,
                'percentage_third' => $pct_ebt_third
            ];
        }
        
        // ====================================================
        // NET INCOME (identical to original)
        // ====================================================
        
        if ((int)$sort_order === 29) {
            $net_tot_p = $ebt_tot_p - $total_primary_total;
            $net_tot_prev = $ebt_tot_prev - $total_previous_total;
            $net_tot_third = $ebt_tot_third - $total_third_total;
            
            $inc_dec_net = $net_tot_p - $net_tot_prev;
            $pct_net = $net_tot_prev != 0
                ? ($inc_dec_net / abs($net_tot_prev)) * 100
                : ($net_tot_p != 0 ? 100 : 0);
            
            $inc_dec_net_third = $net_tot_p - $net_tot_third;
            $pct_net_third = (!empty($third_period) && $net_tot_third != 0)
                ? ($inc_dec_net_third / abs($net_tot_third)) * 100
                : (!empty($third_period) && $net_tot_p != 0 ? 100 : 0);
            
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
            
            $final_table_rows[] = [
                'sort_order' => 'TOTAL NET INCOME/LOSS',
                'sub_order' => '',
                'gl_description' => '',
                'is_section_header' => false,
                'is_summary_row' => true,
                'skip_spacer' => true,
                'primary_total' => $net_tot_p,
                'previous_total' => $net_tot_prev,
                'third_total' => $net_tot_third,
                'inc_dec' => $inc_dec_net,
                'percentage' => $pct_net,
                'inc_dec_third' => $inc_dec_net_third,
                'percentage_third' => $pct_net_third
            ];
        }
    }
    
    return $final_table_rows;
}

// ── Build spreadsheet ─────────────────────────────────────────────────────────

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Head Office Comparative');
$sheet->freezePane('A10');

// Column widths
$widths = [2, 3, 50, 1, 20, 20, 20, 2, 20, 15, 20, 15, 1, 1, 1];
foreach ($widths as $idx => $w) {
    $sheet->getColumnDimension(colLetter($idx + 1))->setWidth($w);
}

$row = 1;

// Logo
$logo_path = __DIR__ . '/../images/mlhuillier.jpg';
if (file_exists($logo_path)) {
    $sheet->getRowDimension($row)->setRowHeight(50);
    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $drawing->setPath($logo_path);
    $drawing->setHeight(60);
    $drawing->setCoordinates('E1');
    $drawing->setOffsetX(20);
    $drawing->setWorksheet($sheet);
}
$row++;

// Title
$sheet->setCellValue("A$row", 'COMPARATIVE PROFIT & LOSS STATEMENT - w/ ALLOCATED HEAD OFFICE');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// Period line
$p_disp = !empty($primary_period) ? date('F Y', strtotime($primary_period . '-01')) : '(Primary)';
$prev_disp = !empty($previous_period) ? date('F Y', strtotime($previous_period . '-01')) : '(Previous)';
$third_disp = !empty($third_period) ? date('F Y', strtotime($third_period . '-01')) : '(Period 3)';
$period_line = strtoupper($p_disp . ' VS ' . $prev_disp . ' VS ' . $third_disp);

$sheet->setCellValue("A$row", $period_line);
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

$row += 2; // rows 4-5 empty

// INCREASE/DECREASE header with red "DECREASE"
$richText = new RichText();
$rt1 = $richText->createTextRun('INCREASE/');
$rt1->getFont()->setBold(true)->setColor(new Color(Color::COLOR_BLACK));
$rt2 = $richText->createTextRun('DECREASE');
$rt2->getFont()->setBold(true)->setColor(new Color(Color::COLOR_RED));

$sheet->setCellValue("I$row", $richText);
$sheet->mergeCells("I$row:L$row");
$sheet->getStyle("I$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("I$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFDBAC');
$row++;

// Column sub-headers
$p_hdr = strtoupper(!empty($primary_period) ? date('F Y', strtotime($primary_period . '-01')) : '(Primary Period)');
$prev_hdr = strtoupper(!empty($previous_period) ? date('F Y', strtotime($previous_period . '-01')) : '(Previous Period)');
$t_hdr = strtoupper(!empty($third_period) ? date('F Y', strtotime($third_period . '-01')) : '(Period 3)');

$sheet->setCellValue("E$row", $p_hdr);
$sheet->setCellValue("F$row", $prev_hdr);
$sheet->setCellValue("G$row", $t_hdr);
$sheet->setCellValue("I$row", 'PREVIOUS MONTH');
$sheet->setCellValue("J$row", '%');
$sheet->setCellValue("K$row", 'PREVIOUS YEAR');
$sheet->setCellValue("L$row", '%');

foreach (['E', 'F', 'G', 'I', 'J', 'K', 'L'] as $c) {
    $sheet->getStyle($c . $row)->getFont()->setBold(true);
    $sheet->getStyle($c . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($c . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF9E5C');
}
$row++;

$row += 2; // rows 8-9 empty

// REVENUES section header row (always present, same as original)
$sheet->setCellValue("A$row", 'REVENUES');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF7F29');
$row++;

// ── Write data rows ───────────────────────────────────────────────────────────

$data_rows = compute_table_rows_for_export(
    $conn,
    $transaction_year,
    $primary_period,
    $previous_period,
    $third_period,
    $gl_code_mode,
    $gl_mapping,
    $gl_descriptions,
    $special_keys,
    $sort_order_descriptions,
    $gl_id_by_key,
    $valid_filters
);

$highlight_labels = [
    'TOTAL REVENUES',
    'GROSS PROFIT',
    'TOTAL SELLING AND ADMIN EXPENSES',
    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
    'EARNINGS BEFORE INTEREST & TAXES',
    'EARNINGS BEFORE TAXES',
    'TOTAL NET INCOME/LOSS',
];

foreach ($data_rows as $item) {
    
    // Manual spacer
    if (isset($item['is_manual_spacer']) && $item['is_manual_spacer']) {
        $sheet->getRowDimension($row)->setRowHeight(15);
        $row++;
        continue;
    }
    
    // Section header
    if (!empty($item['is_section_header'])) {
        $label = $item['sub_order'] ?: $item['sort_order'];
        $sheet->setCellValue("A$row", $label);
        $sheet->mergeCells("A$row:N$row");
        $sheet->getStyle("A$row")->getFont()->setBold(true);
        $sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFA973');
        $row++;
        continue;
    }
    
    $is_sum = $item['is_summary_row'] ?? false;
    
    if ($is_sum) {
        $label = $item['sort_order'];
        
        // Col A: numeric sort orders ≤ 26 are blank (description goes in B), specials go in A
        $sheet->setCellValue("A$row", (is_numeric($label) && (int)$label <= 26) ? '' : $label);
        $sheet->setCellValue("B$row", $item['gl_description'] ?? '');
        
        // Set amount values
        $sheet->setCellValue("E$row", $item['primary_total']);
        $sheet->setCellValue("F$row", $item['previous_total']);
        $sheet->setCellValue("G$row", $item['third_total']);
        $sheet->setCellValue("I$row", $item['inc_dec']);
        $sheet->setCellValue("K$row", $item['inc_dec_third']);
        
        // Percentage columns - store as decimal for Excel percentage formatting
        foreach ([['J', 'percentage'], ['L', 'percentage_third']] as [$col, $field]) {
            $pct = $item[$field] ?? 0;
            if (abs($pct) >= 1000) {
                $sheet->setCellValue($col . $row, 'mat');
            } else {
                $sheet->setCellValue($col . $row, $pct);
            }
        }
        
        // Apply amount formatting (comma separated, 2 decimals)
        foreach (['E', 'F', 'G', 'I', 'K'] as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $val = $sheet->getCell($col . $row)->getValue();
            if (is_numeric($val) && $val < 0) {
                $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
            }
        }
        
        // Apply percentage formatting
        foreach (['J', 'L'] as $col) {
            $val = $sheet->getCell($col . $row)->getValue();
            if ($val === 'mat') {
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } else {
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                if (is_numeric($val) && $val < 0) {
                    $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
                }
            }
        }
        
        $sheet->getStyle("A$row:N$row")->getFont()->setBold(true);
        
        // Background
        if (in_array($label, $highlight_labels, true)) {
            $bg = 'FFFFA973';
        } elseif (is_numeric($label) && (int)$label % 2 != 0 && (int)$label <= 23) {
            $bg = null; // odd revenue rows: no background
        } else {
            $bg = 'FFFDE9D9';
        }
        if ($bg) {
            $sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
        }
        
        // Borders
        if ($label == '24') {
            $sheet->getStyle("E$row:L$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        }
        if (in_array($label, ["EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", 'EARNINGS BEFORE INTEREST & TAXES', 'EARNINGS BEFORE TAXES'], true)) {
            $sheet->getStyle("E$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        }
        if ($label === 'TOTAL NET INCOME/LOSS') {
            $sheet->getStyle("E$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("E$row:L$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        }
        
        // Outline grouping for revenue summary rows (sort 1-23)
        if (is_numeric($label) && (int)$label >= 1 && (int)$label <= 23) {
            $row++;
            $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
            $row++;
            continue;
        }
        
    } else {
        // Detail row
        if ((int)$item['sort_order'] === 18 && in_array((int)$item['sub_order'], [3, 4, 5, 6])) {
            $sheet->setCellValue("B$row", $item['gl_description'] ?? '');
        } else {
            $sheet->setCellValue("C$row", $item['gl_description'] ?? '');
        }
        $sheet->setCellValue("E$row", $item['primary_total']);
        $sheet->setCellValue("F$row", $item['previous_total']);
        $sheet->setCellValue("G$row", $item['third_total']);
        
        // Variance computed from stored (already INJ-2-flipped) values
        $p = floatval($item['primary_total']);
        $prev = floatval($item['previous_total']);
        $t = floatval($item['third_total']);
        
        $diff1 = $p - $prev;
        $pct1 = ($prev != 0) ? ($diff1 / abs($prev)) * 100 : ($p != 0 ? 100 : 0);
        $sheet->setCellValue("I$row", $diff1);
        if (abs($pct1) >= 1000) {
            $sheet->setCellValue("J$row", 'mat');
        } else {
            $sheet->setCellValue("J$row", $pct1);
        }
        
        $diff2 = $p - $t;
        $pct2 = (!empty($third_period) && $t != 0) ? ($diff2 / abs($t)) * 100 : (!empty($third_period) && $p != 0 ? 100 : 0);
        $sheet->setCellValue("K$row", $diff2);
        if (abs($pct2) >= 1000) {
            $sheet->setCellValue("L$row", 'mat');
        } else {
            $sheet->setCellValue("L$row", $pct2);
        }
        
        // Apply amount formatting for detail rows
        foreach (['E', 'F', 'G', 'I', 'K'] as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $val = $sheet->getCell($col . $row)->getValue();
            if (is_numeric($val) && $val < 0) {
                $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
            }
        }
        
        // Apply percentage formatting for detail rows
        foreach (['J', 'L'] as $col) {
            $val = $sheet->getCell($col . $row)->getValue();
            if ($val === 'mat') {
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } else {
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
                if (is_numeric($val) && $val < 0) {
                    $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
                }
            }
        }
        
        // Detail rows for sort 1-23 are grouped/hidden
        if (is_numeric($item['sort_order']) && (int)$item['sort_order'] >= 1 && (int)$item['sort_order'] <= 23) {
            $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
        }
    }
    
    $row++;
}

// ── Output ────────────────────────────────────────────────────────────────────

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Comparative_Report_With_HO_Allocated_' . date('Y-m-d') . '.xlsx"');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;