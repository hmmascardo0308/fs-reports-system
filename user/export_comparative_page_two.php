<?php
/**
 * export_comparative_page_two.php
 * Excel export for Comparative Report (No Manual Adjustment) - Single Period
 * Format matches the official P&L Statement layout
 */

session_start();
require_once __DIR__ . '/../config/config.php';

// PhpSpreadsheet
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'unknown';
    $_SESSION['full_name'] = 'unknown';
    $_SESSION['user_type'] = 'unknown';
}

// Filters (same as report page)
$transaction_month = $_GET['transaction_month'] ?? '';
$gl_code_mode      = $_GET['gl_code_mode'] ?? 'old';
$gl_code_mode      = in_array($gl_code_mode, ['old', 'new'], true) ? $gl_code_mode : 'old';

// Helper functions (identical to report page)
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

// Validate filters
$show_error    = false;
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

// If validation fails, show a simple error page instead of exporting
if ($show_error) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Export Error</title></head><body>';
    echo '<h2>Export Error</h2>';
    echo '<p>' . htmlspecialchars($error_message) . '</p>';
    echo '<p><a href="comparative_report_original_page_two.php?' . htmlspecialchars(http_build_query($_GET)) . '">Go back</a></p>';
    echo '</body></html>';
    exit;
}

// ============================================================
// GET GL MAPPING (identical to report page)
// ============================================================
$gl_mapping      = [];
$gl_descriptions = [];

$exclude_code = ($gl_code_mode === 'old') ? '6000001' : '611101';
$table_name   = ($gl_code_mode === 'old') ? 'fs_reports.gl_codes' : 'fs_reports.new_gl_codes';

$gl_structure_query = "
    SELECT DISTINCT sort_order, sub_order, gl_id, gl_code, gl_description_comparative, description
    FROM {$table_name}
    WHERE sort_order IS NOT NULL AND sub_order IS NOT NULL
    ORDER BY sort_order ASC, sub_order ASC
";

$gl_structure_result = mysqli_query($conn, $gl_structure_query);
if ($gl_structure_result) {
    while ($row = mysqli_fetch_assoc($gl_structure_result)) {
        $key  = $row['sort_order'] . '|' . $row['sub_order'];
        $code = trim((string)($row['gl_code'] ?? ''));

        if ($code === $exclude_code) {
            continue;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key]      = ['codes' => []];
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? '';
        }

        if ($code !== '' && !in_array($code, $gl_mapping[$key]['codes'], true)) {
            $gl_mapping[$key]['codes'][] = $code;
        }
    }
}

// ============================================================
// COMPUTE TABLE ROWS (identical logic)
// ============================================================
function compute_table_rows(
    mysqli $conn,
    string $transaction_month,
    array $gl_mapping,
    bool $use_real_data = true
): array {
    $period_data = [];
    if ($use_real_data && !empty($transaction_month)) {
        $parts     = explode('-', $transaction_month);
        $year_val  = $parts[0];
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
                    'lncr_mlfsi'   => floatval($row['lncr_mlfsi']),
                    'vismin_mlfsi' => floatval($row['vismin_mlfsi']),
                    'jewelers_nw'  => floatval($row['jewelers_nw'])
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }

    $sum_keys = function(array $keys) use ($gl_mapping, $period_data) {
        $lncr = 0; $vismin = 0; $jew = 0;
        foreach ($keys as $key) {
            if (isset($gl_mapping[$key])) {
                foreach ($gl_mapping[$key]['codes'] as $code) {
                    if (isset($period_data[$code])) {
                        $lncr   += $period_data[$code]['lncr_mlfsi'];
                        $vismin += $period_data[$code]['vismin_mlfsi'];
                        $jew    += $period_data[$code]['jewelers_nw'];
                    }
                }
            }
        }
        return ['lncr' => $lncr, 'vismin' => $vismin, 'jew' => $jew];
    };

    $sum_prefix = function(string $prefix) use ($gl_mapping, $period_data) {
        $lncr = 0; $vismin = 0; $jew = 0;
        foreach ($gl_mapping as $key => $data) {
            if (strpos($key, $prefix) === 0) {
                foreach ($data['codes'] as $code) {
                    if (isset($period_data[$code])) {
                        $lncr   += $period_data[$code]['lncr_mlfsi'];
                        $vismin += $period_data[$code]['vismin_mlfsi'];
                        $jew    += $period_data[$code]['jewelers_nw'];
                    }
                }
            }
        }
        return ['lncr' => $lncr, 'vismin' => $vismin, 'jew' => $jew];
    };

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

    // GROSS PROFIT
    $gp_lncr   = $rev['lncr'] - $cos['lncr'];
    $gp_vismin = $rev['vismin'] - $cos['vismin'];
    $gp_jew    = $rev['jew'] - $cos['jew'];

    // SELLING & ADMIN EXPENSES (22 + 23)
    $sa_22     = $sum_prefix('22|');
    $sa_23     = $sum_prefix('23|');
    $sa_lncr   = $sa_22['lncr'] + $sa_23['lncr'];
    $sa_vismin = $sa_22['vismin'] + $sa_23['vismin'];
    $sa_jew    = $sa_22['jew'] + $sa_23['jew'];

    // EBITDA
    $ebitda_lncr   = $gp_lncr - $sa_lncr;
    $ebitda_vismin = $gp_vismin - $sa_vismin;
    $ebitda_jew    = $gp_jew - $sa_jew;

    // Depreciation & Amortization
    $dep   = $sum_keys(['24|1']);
    $amort = $sum_keys(['24|2']);

    // EBIT
    $ebit_lncr   = $ebitda_lncr - $dep['lncr'] - $amort['lncr'];
    $ebit_vismin = $ebitda_vismin - $dep['vismin'] - $amort['vismin'];
    $ebit_jew    = $ebitda_jew - $dep['jew'] - $amort['jew'];

    // Interest
    $interest = $sum_keys(['25|1']);

    // EBT
    $ebt_lncr   = $ebit_lncr - $interest['lncr'];
    $ebt_vismin = $ebit_vismin - $interest['vismin'];
    $ebt_jew    = $ebit_jew - $interest['jew'];

    // Tax
    $tax = $sum_keys(['26|1']);

    // NET INCOME
    $net_lncr   = $ebt_lncr - $tax['lncr'];
    $net_vismin = $ebt_vismin - $tax['vismin'];
    $net_jew    = $ebt_jew - $tax['jew'];

    // Build final rows – labels match the official P&L layout
    $final_table_rows = [];

    $add_row = function($label, $data = null, $is_highlight = false) use (&$final_table_rows) {
        $final_table_rows[] = [
            'label'        => $label,
            'lncr'         => $data ? $data['lncr'] : null,
            'vismin'       => $data ? $data['vismin'] : null,
            'jew'          => $data ? $data['jew'] : null,
            'is_highlight' => $is_highlight
        ];
    };

    $add_spacer = function() use (&$final_table_rows) {
        $final_table_rows[] = ['is_manual_spacer' => true];
    };

    $add_row('REVENUES', $rev, false);
    $add_row('COST OF SALES', $cos, false);
    $add_spacer();
    $add_row('GROSS PROFIT', ['lncr' => $gp_lncr, 'vismin' => $gp_vismin, 'jew' => $gp_jew], true);
    $add_row('SELLING & ADMIN EXPENSES', ['lncr' => $sa_lncr, 'vismin' => $sa_vismin, 'jew' => $sa_jew], false);
    $add_spacer();
    $add_row("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", ['lncr' => $ebitda_lncr, 'vismin' => $ebitda_vismin, 'jew' => $ebitda_jew], true);
    $add_row('Depreciation Expense', $dep, false);
    $add_row('Amortization Expense', $amort, false);
    $add_spacer();
    $add_row('EARNINGS BEFORE INTEREST & TAXES', ['lncr' => $ebit_lncr, 'vismin' => $ebit_vismin, 'jew' => $ebit_jew], true);
    $add_row('Interest Expense', $interest, false);
    $add_spacer();
    $add_row('EARNINGS BEFORE TAXES', ['lncr' => $ebt_lncr, 'vismin' => $ebt_vismin, 'jew' => $ebt_jew], true);
    $add_row('Provision on Income Tax', $tax, false);
    $add_spacer();
    $add_row('NET INCOME', ['lncr' => $net_lncr, 'vismin' => $net_vismin, 'jew' => $net_jew], true);

    return $final_table_rows;
}

$table_rows = compute_table_rows(
    $conn,
    $transaction_month,
    $gl_mapping,
    $valid_filters
);

// Extract REVENUES for percentage base
$rev_lncr   = 0;
$rev_vismin = 0;
$rev_jew    = 0;
foreach ($table_rows as $r) {
    if (($r['label'] ?? '') === 'REVENUES') {
        $rev_lncr   = $r['lncr'] ?? 0;
        $rev_vismin = $r['vismin'] ?? 0;
        $rev_jew    = $r['jew'] ?? 0;
        break;
    }
}

// ============================================================
// BUILD EXCEL – matching official P&L format
// ============================================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('P&L Statement');

// Period label (e.g. JULY 31, 2026)
$periodLabel = '';
if (!empty($transaction_month)) {
    $ts = strtotime($transaction_month . '-01');
    // Last day of the month
    $lastDay = date('t', $ts);
    $periodLabel = strtoupper(date('F', $ts)) . ' ' . $lastDay . ', ' . date('Y', $ts);
} else {
    $periodLabel = '(No Period Selected)';
}

// ---- Styles ----
$orangeFill = [
    'fillType'   => Fill::FILL_SOLID,
    'startColor' => ['rgb' => 'E67E22']   // matches screenshot header
];
$headerFont = [
    'bold'  => true,
    'color' => ['rgb' => 'FFFFFF'],
    'size'  => 10,
    'name'  => 'Arial'
];
$boldFont = [
    'bold' => true,
    'name' => 'Arial',
    'size' => 10
];
$normalFont = [
    'name' => 'Arial',
    'size' => 10
];
$centerAlign = [
    'horizontal' => Alignment::HORIZONTAL_CENTER,
    'vertical'   => Alignment::VERTICAL_CENTER,
    'wrapText'   => true
];
$rightAlign = [
    'horizontal' => Alignment::HORIZONTAL_RIGHT,
    'vertical'   => Alignment::VERTICAL_CENTER
];
$leftAlign = [
    'horizontal' => Alignment::HORIZONTAL_LEFT,
    'vertical'   => Alignment::VERTICAL_CENTER
];

// Column widths (removed first empty column, now starting at A)
$sheet->getColumnDimension('A')->setWidth(52);  // Label
$sheet->getColumnDimension('B')->setWidth(18);  // LNCR amount
$sheet->getColumnDimension('C')->setWidth(9);   // %
$sheet->getColumnDimension('D')->setWidth(18);  // VISMIN amount
$sheet->getColumnDimension('E')->setWidth(9);   // %
$sheet->getColumnDimension('F')->setWidth(18);  // JEW amount
$sheet->getColumnDimension('G')->setWidth(9);   // %

// ---- Logo ----
$logoPath = __DIR__ . '/../images/weblogo.png';
if (file_exists($logoPath)) {
    $drawing = new Drawing();
    $drawing->setName('M Lhuillier Logo');
    $drawing->setDescription('M Lhuillier');
    $drawing->setPath($logoPath);
    $drawing->setHeight(55);
    $drawing->setCoordinates('B1');
    $drawing->setOffsetX(0);   // roughly center over the title area
    $drawing->setWorksheet($sheet);
    $sheet->getRowDimension(1)->setRowHeight(50);
} else {
    // Fallback text if logo file is missing
    $sheet->mergeCells('A1:G1');
    $sheet->setCellValue('A1', 'M LHUILLIER');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20)->getColor()->setRGB('C00000');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(40);
}

// ---- Title rows ----
$sheet->mergeCells('A3:G3');
$sheet->setCellValue('A3', 'PROFIT & LOSS STATEMENT - OPERATIONS (without HEAD OFFICE EXPENSE & INCOME)');
$sheet->getStyle('A3')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 12,
        'name' => 'Arial'
    ],
    'alignment' => $centerAlign
]);
$sheet->getRowDimension(3)->setRowHeight(20);

$sheet->mergeCells('A4:G4');
$sheet->setCellValue('A4', 'FOR THE MONTH ENDED ' . $periodLabel);
$sheet->getStyle('A4')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 11,
        'name' => 'Arial'
    ],
    'alignment' => $centerAlign
]);
$sheet->getRowDimension(4)->setRowHeight(18);

// Blank row 5 & 6
$sheet->getRowDimension(5)->setRowHeight(8);
$sheet->getRowDimension(6)->setRowHeight(8);

// ---- Header row 7 ----
$headers = [
    'A7' => '',
    'B7' => "LUZON/NCR -\nMLFSI",
    'C7' => '%',
    'D7' => "VISMIN - MLFSI",
    'E7' => '%',
    'F7' => "JEWELERS - NW",
    'G7' => '%'
];
foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
}

// Apply header styling to B7:G7 only (A7 has no fill/color)
$sheet->getStyle('B7:G7')->applyFromArray([
    'font'      => $headerFont,
    'fill'      => $orangeFill,
    'alignment' => $centerAlign,
]);
// A7 alignment (no fill, no font color)
$sheet->getStyle('A7')->applyFromArray([
    'alignment' => $centerAlign,
]);

// Header: only top and bottom thick borders for B7:G7 (excluding the first cell A7)
$sheet->getStyle('B7:G7')->getBorders()->getTop()->setBorderStyle(Border::BORDER_THICK);
$sheet->getStyle('B7:G7')->getBorders()->getTop()->getColor()->setRGB('000000');
$sheet->getStyle('B7:G7')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK);
$sheet->getStyle('B7:G7')->getBorders()->getBottom()->getColor()->setRGB('000000');

$sheet->getRowDimension(7)->setRowHeight(32);

// ---- Data rows starting at row 9 (row 8 left blank like screenshot) ----
$sheet->getRowDimension(8)->setRowHeight(6);

$rowNum = 9;
foreach ($table_rows as $index => $row) {
    if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
        $sheet->getRowDimension($rowNum)->setRowHeight(10);
        $rowNum++;
        continue;
    }

    $label  = $row['label'] ?? '';
    $lncr   = $row['lncr'] ?? 0;
    $vismin = $row['vismin'] ?? 0;
    $jew    = $row['jew'] ?? 0;
    $isHighlight = !empty($row['is_highlight']);

    $pct_lncr   = $rev_lncr   != 0 ? ($lncr / $rev_lncr) * 100 : 0;
    $pct_vismin = $rev_vismin != 0 ? ($vismin / $rev_vismin) * 100 : 0;
    $pct_jew    = $rev_jew    != 0 ? ($jew / $rev_jew) * 100 : 0;

    $sheet->setCellValue('A' . $rowNum, $label);
    $sheet->setCellValue('B' . $rowNum, $lncr);
    $sheet->setCellValue('C' . $rowNum, round($pct_lncr, 2));
    $sheet->setCellValue('D' . $rowNum, $vismin);
    $sheet->setCellValue('E' . $rowNum, round($pct_vismin, 2));
    $sheet->setCellValue('F' . $rowNum, $jew);
    $sheet->setCellValue('G' . $rowNum, round($pct_jew, 2));

    // Number formats (plain 2-decimal + percentage as number with 2 decimals)
    $sheet->getStyle('B' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"0.00"');
    $sheet->getStyle('D' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"0.00"');
    $sheet->getStyle('F' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"0.00"');
    $sheet->getStyle('C' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('E' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('G' . $rowNum)->getNumberFormat()->setFormatCode('0.00');

    // Alignment – label column left, amounts right
    $sheet->getStyle('A' . $rowNum)->applyFromArray(['font' => $normalFont, 'alignment' => $leftAlign]);
    $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->applyFromArray([
        'font'      => $normalFont,
        'alignment' => $rightAlign
    ]);

    // Highlight key summary rows (bold text only, NO fill)
    if ($isHighlight) {
        $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->applyFromArray([
            'font' => $boldFont
        ]);
    }

    // ---- Special row border rules ----
    $upperLabel = strtoupper($label);
    
    // Put border-top for these summary lines, thin
    $thinTopRows = [
        'GROSS PROFIT',
        "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
        'EARNINGS BEFORE INTEREST & TAXES',
        'EARNINGS BEFORE TAXES'
    ];
    if (in_array($upperLabel, $thinTopRows, true)) {
        $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->getBorders()->getTop()->getColor()->setRGB('000000');
    }

    // NET INCOME: border-top + double border-bottom, orangish fill on amounts
    if ($upperLabel === 'NET INCOME') {
        $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->getFont()->setBold(true);
        
        // Border top thin
        $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->getBorders()->getTop()->getColor()->setRGB('000000');
        
        // Border bottom double
        $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->getBorders()->getBottom()->getColor()->setRGB('000000');
        
        // Orangish fill on amount & percentage cells
        $orangeAmountFill = [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'F4B183']   // orangish
        ];
        $sheet->getStyle('B' . $rowNum . ':G' . $rowNum)->applyFromArray([
            'fill' => $orangeAmountFill
        ]);
    }

    // Negative values in red
    foreach (['B', 'D', 'F'] as $col) {
        $val = $sheet->getCell($col . $rowNum)->getValue();
        if (is_numeric($val) && $val < 0) {
            $sheet->getStyle($col . $rowNum)->getFont()->getColor()->setRGB('FF0000');
        }
    }

    $rowNum++;
}

// Freeze panes below header
$sheet->freezePane('A9');

// Print settings (optional niceties)
$sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.4);
$sheet->getPageMargins()->setBottom(0.4);
$sheet->getPageMargins()->setLeft(0.3);
$sheet->getPageMargins()->setRight(0.3);

// Output
$filename = 'PnL_Statement_' . (!empty($transaction_month) ? str_replace('-', '', $transaction_month) : 'NoPeriod') . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$conn->close();
exit;