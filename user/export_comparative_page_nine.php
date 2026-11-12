<?php
// export_comparative_page_nine.php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

$transaction_year = $_GET['transaction_year'] ?? '';
$selected_period = $_GET['selected_period'] ?? '';
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

function isAugust2026OrLater(string $month): bool {
    if (empty($month)) return false;
    $cutoff = strtotime('2026-08-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

$show_error = false;
$valid_filters = false;

if (!empty($selected_period)) {
    if ($gl_code_mode === 'old') {
        if (!isMarch2026OrEarlier($selected_period)) $show_error = true;
    } elseif ($gl_code_mode === 'new') {
        if (!isApril2026OrLater($selected_period)) $show_error = true;
    }
    if (!$show_error) $valid_filters = true;
}

$ml_properties_group_active = (
    $gl_code_mode === 'new' &&
    isAugust2026OrLater($selected_period)
);

$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];
$gl_id_by_key = [];

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
        $gl_id_by_key[$key] = $gl_id;

        if (($gl_id === 'MLP-1' || (int)($row['sort_order'] ?? 0) === 30) && !$ml_properties_group_active) {
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
        $code = trim((string)($row['gl_code'] ?? ''));
        if ($code !== '' && !in_array($code, $gl_mapping[$key], true)) {
            $gl_mapping[$key][] = $code;
        }
        if (!isset($sort_order_descriptions[$row['sort_order']]) && !empty($row['description'])) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

$old_gl_mapping = [
    'COS-2' => 'COS-5', 'COS-3' => 'COS-6', 'COS-4' => 'COS-7',
    'MLE-2' => 'MLE-3',
    'TAE-15' => 'TAE-16', 'TAE-16' => 'TAE-17', 'TAE-17' => 'TAE-18', 'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20', 'TAE-20' => 'TAE-21', 'TAE-21' => 'TAE-22', 'TAE-22' => 'TAE-23', 'TAE-23' => null,
    'TOI-18' => null, 'TOI-19' => 'TOI-18', 'TOI-20' => 'MLE-2', 'TOI-22' => 'INJ-5', 'TOI-23' => 'INJ-4', 'TOI-24' => null,
    'VEH-5' => 'VEH-6', 'VEH-6' => 'VEH-7', 'VEH-7' => 'VEH-8', 'VEH-8' => 'VEH-9', 'VEH-9' => 'VEH-10',
];

$new_gl_mapping = [
    'COS-2' => 'COS-5', 'COS-3' => 'COS-6', 'COS-4' => 'COS-7',
    'TAE-15' => 'TAE-16', 'TAE-16' => 'TAE-17', 'TAE-17' => 'TAE-18', 'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20', 'TAE-20' => 'TAE-21', 'TAE-21' => 'TAE-22', 'TAE-22' => 'TAE-23', 'TAE-23' => null,
    'TOI-18' => null, 'TOI-19' => 'TOI-18', 'TOI-20' => 'COS-8', 'TOI-22' => null, 'TOI-23' => null, 'TOI-24' => null,
    'VEH-5' => 'VEH-7', 'VEH-6' => 'VEH-8', 'VEH-7' => 'VEH-9', 'VEH-8' => 'VEH-10', 'VEH-9' => 'VEH-11',
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

function get_head_office_manual_adjustment(mysqli $conn, string $mainzone, string $selected_period): float {
    if (empty($selected_period)) return 0.0;
    $transaction_month = $selected_period . '-01';

    if ($mainzone === '' || strtoupper($mainzone) === 'NATIONWIDE') {
        $sql = "SELECT SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total FROM fs_reports.manual_adjustment WHERE transaction_month = ? AND sort_order = 26 AND sub_order = 1 AND UPPER(TRIM(mainzone)) IN ('LNCR', 'VISMIN')";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return 0.0;
        mysqli_stmt_bind_param($stmt, "s", $transaction_month);
    } else {
        $sql = "SELECT SUM(COALESCE(mlfsi, 0) + COALESCE(jewelers, 0)) AS total FROM fs_reports.manual_adjustment WHERE transaction_month = ? AND sort_order = 26 AND sub_order = 1 AND LOWER(TRIM(mainzone)) = LOWER(?)";
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

function get_manual_adjustment_period_data(
    mysqli $conn, string $mainzone, string $selected_period, array $gl_id_by_key,
    string $gl_code_mode = 'old', array $old_gl_mapping = [], array $new_gl_mapping = []
): array {
    $data = [];
    if (empty($selected_period)) return $data;

    $parts = explode('-', $selected_period);
    $year_val = $parts[0];
    $month_val = $selected_period . '-01';

    $gl_ids_to_query = array_values(array_unique(array_filter(array_values($gl_id_by_key))));

    if ($gl_code_mode === 'old') {
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') $gl_ids_to_query[] = $src_id;
        }
    } else {
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_id) {
                    if ($src_id !== '') $gl_ids_to_query[] = $src_id;
                }
            } elseif ($key !== '') {
                $gl_ids_to_query[] = $key;
            }
        }
    }

    $gl_ids_to_query = array_values(array_unique(array_filter($gl_ids_to_query, function ($id) {
        return $id !== null && $id !== '';
    })));

    if (empty($gl_ids_to_query)) return $data;

    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));
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

    $sql = "SELECT gl_id, SUM(COALESCE(mlfsi, 0)) AS mlfsi_amount, SUM(COALESCE(jewelers, 0)) AS jewelers_amount FROM fs_reports.manual_adjustment WHERE transaction_year = ? AND transaction_month = ? AND gl_id IN ({$placeholders}) AND gl_id IS NOT NULL AND gl_id != '' {$where_mainzone} GROUP BY gl_id";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return $data;

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $raw = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $raw[$row['gl_id']] = [
            'mlfsi'    => floatval($row['mlfsi_amount']),
            'jewelers' => floatval($row['jewelers_amount']),
        ];
    }
    mysqli_stmt_close($stmt);

    $add_to = function (string $target, float $mlfsi, float $jewelers) use (&$data) {
        if ($target === '') return;
        if (!isset($data[$target])) {
            $data[$target] = ['mlfsi' => 0.0, 'jewelers' => 0.0, 'total' => 0.0];
        }
        $data[$target]['mlfsi']    += $mlfsi;
        $data[$target]['jewelers'] += $jewelers;
        $data[$target]['total']     = $data[$target]['mlfsi'] + $data[$target]['jewelers'];
    };

    if ($gl_code_mode === 'old') {
        foreach ($raw as $src_id => $amt) {
            if (array_key_exists($src_id, $old_gl_mapping)) {
                $target = $old_gl_mapping[$src_id];
                if ($target !== null && $target !== '') {
                    $add_to($target, $amt['mlfsi'], $amt['jewelers']);
                }
            } else {
                $add_to($src_id, $amt['mlfsi'], $amt['jewelers']);
            }
        }
    } else {
        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                $target = $key;
                $direct_mlfsi = isset($raw[$target]) ? $raw[$target]['mlfsi'] : null;
                $direct_jew   = isset($raw[$target]) ? $raw[$target]['jewelers'] : null;
                $sum_mlfsi = 0.0; $sum_jew = 0.0;
                foreach ($mapping as $src_id) {
                    if ($src_id !== '' && isset($raw[$src_id])) {
                        $sum_mlfsi += $raw[$src_id]['mlfsi'];
                        $sum_jew   += $raw[$src_id]['jewelers'];
                    }
                }
                if ($direct_mlfsi !== null && ($direct_mlfsi != 0.0 || $direct_jew != 0.0)) {
                    $add_to($target, (float)$direct_mlfsi, (float)$direct_jew);
                } elseif ($sum_mlfsi != 0.0 || $sum_jew != 0.0) {
                    $add_to($target, $sum_mlfsi, $sum_jew);
                } elseif ($direct_mlfsi !== null) {
                    $add_to($target, (float)$direct_mlfsi, (float)$direct_jew);
                }
            } else {
                if ($key !== '') $scalar_source_ids[$key] = true;
                $target = $mapping;
                if ($key !== '' && $target !== null && $target !== '' && isset($raw[$key])) {
                    $add_to($target, $raw[$key]['mlfsi'], $raw[$key]['jewelers']);
                }
            }
        }
        foreach ($gl_id_by_key as $gid) {
            if ($gid !== '' && !isset($data[$gid]) && isset($raw[$gid]) && !isset($scalar_source_ids[$gid])) {
                $add_to($gid, $raw[$gid]['mlfsi'], $raw[$gid]['jewelers']);
            }
        }
    }
    return $data;
}

function compute_table_rows_for_mainzone(
    mysqli $conn, string $mainzone, string $transaction_year, string $selected_period,
    string $gl_code_mode, array $gl_mapping, array $gl_descriptions, array $special_keys,
    array $sort_order_descriptions, array $gl_id_by_key, array $old_gl_mapping = [],
    array $new_gl_mapping = [], bool $use_real_data = true, bool $ml_properties_group = false
): array {
    $period_data = [];
    if ($use_real_data && !empty($selected_period)) {
        $period_data = get_manual_adjustment_period_data($conn, $mainzone, $selected_period, $gl_id_by_key, $gl_code_mode, $old_gl_mapping, $new_gl_mapping);
    }

    if ($ml_properties_group) {
        $mlp_src = $period_data['MLP-1'] ?? ['mlfsi' => 0.0, 'jewelers' => 0.0];
        $toi_src = $period_data['TOI-32'] ?? ['mlfsi' => 0.0, 'jewelers' => 0.0];
        $mlp_mlfsi = (float)$mlp_src['mlfsi'] + (float)$toi_src['mlfsi'];
        $mlp_jew   = (float)$mlp_src['jewelers'] + (float)$toi_src['jewelers'];
        $period_data['MLP-1'] = ['mlfsi' => $mlp_mlfsi, 'jewelers' => $mlp_jew, 'total' => $mlp_mlfsi + $mlp_jew];
        unset($period_data['TOI-32']);
    }

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

        $period_mlfsi = 0.0; $period_jewelers = 0.0;
        if ($current_gl_id !== '' && isset($period_data[$current_gl_id])) {
            $period_mlfsi = $period_data[$current_gl_id]['mlfsi'];
            $period_jewelers = $period_data[$current_gl_id]['jewelers'];
        }

        $head_office = 0.0;
        $is_tax_head_office_row = (
            ((string)$sort_order === '28' && (string)$sub_order === '1' && $gl_code_mode === 'old') ||
            ((string)$sort_order === '29' && (string)$sub_order === '1' && $gl_code_mode === 'new')
        );
        if ($is_tax_head_office_row) {
            $tax_total = $head_office_tax;
            if ($tax_total == 0.0) $tax_total = $period_mlfsi + $period_jewelers;
            $period_mlfsi = 0.0; $period_jewelers = 0.0; $head_office = $tax_total;
        }
        
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
        if (!isset($grouped_rows[$sort_order])) $grouped_rows[$sort_order] = [];
        $grouped_rows[$sort_order][] = $row;
    }

    if (!$ml_properties_group && isset($grouped_rows[30])) unset($grouped_rows[30]);

    if ($ml_properties_group && isset($grouped_rows[30])) {
        $reordered_groups = [];
        foreach ($grouped_rows as $group_sort_order => $group_rows) {
            if ((int)$group_sort_order === 30) continue;
            $reordered_groups[$group_sort_order] = $group_rows;
            if ((int)$group_sort_order === 7) {
                $reordered_groups[30] = $grouped_rows[30];
            }
        }
        if (!isset($reordered_groups[30])) $reordered_groups[30] = $grouped_rows[30];
        $grouped_rows = $reordered_groups;
    }

    $revenue_max_sort_order = ($gl_code_mode === 'old') ? 22 : 23;
    $final_table_rows = [];

    foreach ($grouped_rows as $sort_order => $rows) {
        $is_revenue_group_step = (
            ((int)$sort_order >= 1 && (int)$sort_order <= $revenue_max_sort_order) ||
            ($ml_properties_group && (int)$sort_order === 30)
        );

        if ($is_revenue_group_step) {
            $hide_details = false;
            if ($gl_code_mode === 'old' && ((int)$sort_order == 10 || (int)$sort_order == 13)) $hide_details = true;
            elseif ($gl_code_mode === 'new' && ((int)$sort_order == 11 || (int)$sort_order == 14)) $hide_details = true;
            
            $total_period_mlfsi = array_sum(array_column($rows, 'period_mlfsi'));
            $total_period_jewelers = array_sum(array_column($rows, 'period_jewelers'));
            $total_head_office = array_sum(array_column($rows, 'head_office'));
            $group_total_with_head = $total_period_mlfsi + $total_period_jewelers + $total_head_office;
            
            $description = isset($sort_order_descriptions[$sort_order]) ? $sort_order_descriptions[$sort_order] : "Total for Sort Order " . $sort_order;
            
            $final_table_rows[] = [
                'sort_order' => $sort_order,
                'sub_order' => '',
                'gl_description' => $description,
                'is_section_header' => false,
                'is_summary_row' => true,
                'is_sort_order_summary' => true,
                'period_mlfsi' => $total_period_mlfsi,
                'period_jewelers' => $total_period_jewelers,
                'period_total' => $group_total_with_head,
                'head_office' => $total_head_office
            ];

            if (!$hide_details) {
                foreach ($rows as $row) {
                    $final_table_rows[] = $row;
                }
            }
            $final_table_rows[] = ['is_spacer' => true];
        }
    }

    return $final_table_rows;
}

// ============================================================
// BUILD THE THREE DATASETS
// ============================================================
$tables = [];
$fixed_mainzones = [
    'NATIONWIDE' => '',
    'LNCR'       => 'LNCR',
    'VISMIN'     => 'VISMIN',
];

foreach ($fixed_mainzones as $display_name => $filter_mainzone) {
    $tables[] = [
        'mainzone' => $display_name,
        'rows' => compute_table_rows_for_mainzone(
            $conn, $filter_mainzone, $transaction_year, $selected_period, $gl_code_mode,
            $gl_mapping, $gl_descriptions, $special_keys, $sort_order_descriptions, $gl_id_by_key,
            $old_gl_mapping, $new_gl_mapping, $valid_filters, $ml_properties_group_active
        ),
    ];
}

// PRE-CALCULATE TOTAL REVENUES
$total_revenues_by_table = [];
foreach ($tables as $table_index => $table) {
    $total_revenues = 0;
    foreach ($table['rows'] as $row) {
        if (isset($row['is_summary_row']) && $row['is_summary_row'] === true && isset($row['sort_order']) && $row['sort_order'] === 'TOTAL REVENUES') {
            $total_revenues = $row['period_total'] ?? 0;
            break;
        }
    }
    if ($total_revenues == 0) {
        foreach ($table['rows'] as $row) {
            if (!isset($row['is_summary_row']) || $row['is_summary_row'] !== true) {
                $total_revenues += abs($row['period_total'] ?? 0);
            }
        }
    }
    $total_revenues_by_table[$table_index] = $total_revenues;
}

// ============================================================
// MERGE INTO ONE TABLE
// ============================================================
$row_lookup_key = function (array $r): string {
    return ($r['sort_order'] ?? '') . '|' . ($r['sub_order'] ?? '') . '|' . (!empty($r['is_summary_row']) ? '1' : '0');
};

$lncr_lookup = [];
foreach (($tables[1]['rows'] ?? []) as $r) $lncr_lookup[$row_lookup_key($r)] = $r;
$vismin_lookup = [];
foreach (($tables[2]['rows'] ?? []) as $r) $vismin_lookup[$row_lookup_key($r)] = $r;

$merged_rows = [];
foreach (($tables[0]['rows'] ?? []) as $n) {
    $k = $row_lookup_key($n);
    $l = $lncr_lookup[$k]   ?? [];
    $v = $vismin_lookup[$k] ?? [];

    $nationwide_total = (float)($n['period_total'] ?? 0);
    $lncr_total       = (float)($l['period_total'] ?? 0);
    $vismin_total     = (float)($v['period_total'] ?? 0);
    $ho_total         = (float)($n['head_office'] ?? 0);

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
        'is_sort_order_summary' => !empty($n['is_sort_order_summary']),
        'is_spacer'         => !empty($n['is_spacer']),
        'nationwide_total'  => $nationwide_total,
        'lncr_total'        => $lncr_total,
        'vismin_total'      => $vismin_total,
        'ho_total'          => $ho_total,
    ];
}

// ============================================================
// CREATE EXCEL FILE
// ============================================================
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Grouping settings: Summary rows must be ABOVE the detail rows
$sheet->setShowSummaryBelow(false);

// Column setup matching the exact layout
$column_widths = [
    'A' => 5,  // Increased slightly to fit the counter numbers
    'B' => 25, // Summary Description Column
    'C' => 40, // Detail Description Column
    'D' => 3,
    'G' => 16, // Nationwide
    'H' => 16, // LNCR
    'I' => 10, // %
    'K' => 16, // VISMIN
    'L' => 10, // %
    'N' => 16, // HO
    'O' => 10, // %
];

foreach ($column_widths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// Hide spacer columns
foreach (['D', 'E', 'F', 'J', 'M'] as $col) {
    $sheet->getColumnDimension($col)->setVisible(false);
}

$sheet->getRowDimension(1)->setRowHeight(30);

// Titles
$sheet->mergeCells('A1:O1');
$logo_path = __DIR__ . '/../images/mlhuillier.jpg';
if (file_exists($logo_path)) {
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setDescription('MLhuillier Logo');
    $drawing->setPath($logo_path);
    $drawing->setHeight(40);
    $drawing->setCoordinates('F1');
    $drawing->setWorksheet($sheet);
}

$sheet->mergeCells('A2:O2');
$sheet->setCellValue('A2', 'REVENUE CONTRIBUTION PERCENTAGE REPORT');
$sheet->getStyle('A2')->applyFromArray(['font' => ['bold' => true, 'size' => 12], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$sheet->mergeCells('A3:O3');
$sheet->setCellValue('A3', 'NATIONWIDE vs LNCR vs VISMIN vs HEAD OFFICE');
$sheet->getStyle('A3')->applyFromArray(['font' => ['bold' => true, 'size' => 11], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

$period_display = '';
if (!empty($selected_period)) {
    $date = DateTime::createFromFormat('Y-m', $selected_period);
    if ($date) {
        $lastDay = $date->format('t');
        $period_display = 'FOR THE MONTH ENDED ' . strtoupper($date->format('F ')) . $lastDay . strtoupper($date->format(', Y'));
    }
}
$sheet->mergeCells('A4:O4');
$sheet->setCellValue('A4', $period_display);
$sheet->getStyle('A4')->applyFromArray(['font' => ['italic' => true, 'size' => 11], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

// Row 8: Headers
$sheet->getRowDimension(8)->setRowHeight(25);
$sheet->setCellValue('C8', 'REVENUE');
$sheet->setCellValue('G8', 'NATIONWIDE');
$sheet->setCellValue('H8', 'LNCR');
$sheet->setCellValue('I8', '%');
$sheet->setCellValue('K8', 'VISMIN');
$sheet->setCellValue('L8', '%');
$sheet->setCellValue('N8', 'HO');
$sheet->setCellValue('O8', '%');

$header_style = [
    'font' => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FABF8F']]
];

foreach (['C8','G8','H8','I8','K8','L8','N8','O8'] as $cell) {
    $sheet->getStyle($cell)->applyFromArray($header_style);
}

$current_row = 10;

$revenue_summary_counter = 0;
$summary_row_counter = 0; 

// Array to store row ranges for grouping [startRow, endRow]
$group_ranges = [];
$current_group_start = null;

foreach ($merged_rows as $row) {
    $is_summary = !empty($row['is_summary_row']);
    $is_sort_order_summary = !empty($row['is_sort_order_summary']);
    $is_header = !empty($row['is_section_header']);
    $is_spacer = !empty($row['is_spacer']);

    // If we hit a spacer row, close the active group before processing the spacer
    if ($is_spacer) {
        if ($current_group_start !== null) {
            if ($current_row - 1 >= $current_group_start) {
                $group_ranges[] = [$current_group_start, $current_row - 1];
            }
            $current_group_start = null;
        }
        
        // Increment the row so the spacer remains empty and ungrouped
        $current_row++;
        continue;
    }

    // If we hit a new summary and there was an active group, close it
    if ($is_summary && $current_group_start !== null) {
        if ($current_row - 1 >= $current_group_start) {
            $group_ranges[] = [$current_group_start, $current_row - 1];
        }
        $current_group_start = null;
    }

    // If we hit a detail row and no group has started, mark the start
    if (!$is_summary && $current_group_start === null) {
        $current_group_start = $current_row;
    }

    $nationwide = $row['nationwide_total'];
    $lncr = $row['lncr_total'];
    $vismin = $row['vismin_total'];
    $ho = $row['ho_total'];

    // Calculations for percentages
    $lncr_pct = ($nationwide != 0 && $lncr != 0) ? ($lncr / $nationwide) * 100 : 0;
    $vismin_pct = ($nationwide != 0 && $vismin != 0) ? ($vismin / $nationwide) * 100 : 0;
    $ho_pct = ($nationwide != 0 && $ho != 0) ? ($ho / $nationwide) * 100 : 0;

    if ($is_summary) {
        $summary_row_counter++;
        $sheet->setCellValue('A' . $current_row, $summary_row_counter);
        $sheet->setCellValue('B' . $current_row, $row['gl_description'] ?? '');
    } elseif ($is_header) {
        $sheet->setCellValue('B' . $current_row, $row['sub_order']);
    } else {
        $sheet->setCellValue('C' . $current_row, $row['gl_description']);
    }

    // Amounts
    $sheet->setCellValue('G' . $current_row, $nationwide);
    $sheet->setCellValue('H' . $current_row, $lncr);
    $sheet->setCellValue('I' . $current_row, $lncr_pct);
    $sheet->setCellValue('K' . $current_row, $vismin);
    $sheet->setCellValue('L' . $current_row, $vismin_pct);
    $sheet->setCellValue('N' . $current_row, $ho);
    $sheet->setCellValue('O' . $current_row, $ho_pct);

    // Format Numbers
    foreach (['G', 'H', 'K', 'N'] as $col) {
        $sheet->getStyle($col . $current_row)->getNumberFormat()->setFormatCode('#,##0.00');
        if ($sheet->getCell($col . $current_row)->getValue() < 0) {
            $sheet->getStyle($col . $current_row)->getFont()->setColor(new Color('FF0000'));
        }
    }
    foreach (['I', 'L', 'O'] as $col) {
        $sheet->getStyle($col . $current_row)->getNumberFormat()->setFormatCode('0.00');
    }

    // Styling
    if ($is_summary) {
        // Apply background color to ALL summary rows
        $sheet->getStyle('A' . $current_row . ':O' . $current_row)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DE7138']]
        ]);
        
        $sheet->getStyle('A' . $current_row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Optional: Increment counter if you still need it for other logic, 
        // but the alternating color logic has been removed.
        if ($is_sort_order_summary) {
            $revenue_summary_counter++;
        }
    }

    $current_row++;
}

// Catch any remaining details at the very end of the sheet
if ($current_group_start !== null) {
    if ($current_row - 1 >= $current_group_start) {
        $group_ranges[] = [$current_group_start, $current_row - 1];
    }
}

// Apply the grouping/outlining to the sheet
foreach ($group_ranges as $range) {
    for ($r = $range[0]; $r <= $range[1]; $r++) {
        $sheet->getRowDimension($r)->setOutlineLevel(1);
        $sheet->getRowDimension($r)->setVisible(true);
    }
}

// Freeze panes under headers
$sheet->freezePane('A10');

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Revenue_Contribution_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$conn->close();
exit;
?>