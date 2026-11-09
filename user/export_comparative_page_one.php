<?php
/**
 * export_comparative_page_one.php
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
    echo '<p><a href="comparative_report_original_page_one.php?' . htmlspecialchars(http_build_query($_GET)) . '">Go back</a></p>';
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
        $parts     = explode('-', $transaction_month);
        $year_val  = $parts[0];
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

    // REVENUES (sort_order 1 to 20)
    $rev_keys = [];
    for ($i = 1; $i <= 20; $i++) {
        foreach (array_keys($gl_mapping) as $k) {
            if (strpos($k, "{$i}|") === 0) $rev_keys[] = $k;
        }
    }
    $rev = $sum_keys($rev_keys);

    // LESS: COST OF SALES (sort_order 21)
    $cos = $sum_prefix('21|');

    // GROSS PROFIT
    $gp_lncr   = $rev['lncr']   - $cos['lncr'];
    $gp_vismin = $rev['vismin'] - $cos['vismin'];
    $gp_ho     = $rev['headoffice'] - $cos['headoffice'];

    // SELLING & ADMIN EXPENSES (22 + 23)
    $sa_22     = $sum_prefix('22|');
    $sa_23     = $sum_prefix('23|');
    $sa_lncr   = $sa_22['lncr']   + $sa_23['lncr'];
    $sa_vismin = $sa_22['vismin'] + $sa_23['vismin'];
    $sa_ho     = $sa_22['headoffice'] + $sa_23['headoffice'];

    // EBITDA
    $ebitda_lncr   = $gp_lncr   - $sa_lncr;
    $ebitda_vismin = $gp_vismin - $sa_vismin;
    $ebitda_ho     = $gp_ho     - $sa_ho;

    // Depreciation & Amortization
    $dep   = $sum_keys(['24|1']);
    $amort = $sum_keys(['24|2']);

    // EBIT
    $ebit_lncr   = $ebitda_lncr   - $dep['lncr']       - $amort['lncr'];
    $ebit_vismin = $ebitda_vismin - $dep['vismin']     - $amort['vismin'];
    $ebit_ho     = $ebitda_ho     - $dep['headoffice'] - $amort['headoffice'];

    // Interest
    $interest = $sum_keys(['25|1']);

    // EBT
    $ebt_lncr   = $ebit_lncr   - $interest['lncr'];
    $ebt_vismin = $ebit_vismin - $interest['vismin'];
    $ebt_ho     = $ebit_ho     - $interest['headoffice'];

    // Tax
    $tax = $sum_keys(['26|1']);

    // NET INCOME
    $net_lncr   = $ebt_lncr   - $tax['lncr'];
    $net_vismin = $ebt_vismin - $tax['vismin'];
    $net_ho     = $ebt_ho     - $tax['headoffice'];

    // Build final rows – labels and spacing match the web report exactly
    $final_table_rows = [];

    $add_row = function($label, $data = null, $is_highlight = false) use (&$final_table_rows) {
        $lncr   = $data['lncr']       ?? 0;
        $vismin = $data['vismin']     ?? 0;
        $ho     = $data['headoffice'] ?? 0;
        $final_table_rows[] = [
            'label'        => $label,
            'lncr'         => $data ? $lncr   : null,
            'vismin'       => $data ? $vismin : null,
            'headoffice'   => $data ? $ho     : null,
            'nationwide'   => $data ? ($lncr + $vismin + $ho) : null,
            'is_highlight' => $is_highlight
        ];
    };

    $add_spacer = function() use (&$final_table_rows) {
        $final_table_rows[] = ['is_manual_spacer' => true];
    };

    // --- Rows (identical sequence to comparative_report_original_page_one.php) ---
    $add_row('REVENUES', $rev, false);
    $add_row('LESS: COST OF SALES', $cos, false);
    $add_spacer();

    $add_row('GROSS PROFIT', ['lncr' => $gp_lncr, 'vismin' => $gp_vismin, 'headoffice' => $gp_ho], true);
    $add_spacer(); // spacer after Gross Profit (matches web page)

    $add_row('Selling & Admin Expenses', ['lncr' => $sa_lncr, 'vismin' => $sa_vismin, 'headoffice' => $sa_ho], false);
    $add_row('LESS: TOTAL SELLING AND ADMIN EXPENSES', ['lncr' => $sa_lncr, 'vismin' => $sa_vismin, 'headoffice' => $sa_ho], true);
    $add_spacer();

    $add_row("EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT", ['lncr' => $ebitda_lncr, 'vismin' => $ebitda_vismin, 'headoffice' => $ebitda_ho], true);
    $add_row('Less: Depreciation Expense', $dep, false);
    $add_row('Amortization Expense', $amort, false);
    $add_spacer();

    $add_row('EARNINGS BEFORE INTEREST & TAXES', ['lncr' => $ebit_lncr, 'vismin' => $ebit_vismin, 'headoffice' => $ebit_ho], true);
    $add_row('Less: Interest Expense', $interest, false);
    $add_spacer();

    $add_row('EARNINGS BEFORE TAXES', ['lncr' => $ebt_lncr, 'vismin' => $ebt_vismin, 'headoffice' => $ebt_ho], true);
    $add_row('Less: Provision on Income Tax', $tax, false);
    $add_spacer();

    $add_row('TOTAL NET INCOME', ['lncr' => $net_lncr, 'vismin' => $net_vismin, 'headoffice' => $net_ho], true);

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
$rev_ho     = 0;
$rev_nation = 0;
foreach ($table_rows as $r) {
    if (($r['label'] ?? '') === 'REVENUES') {
        $rev_lncr   = $r['lncr']       ?? 0;
        $rev_vismin = $r['vismin']     ?? 0;
        $rev_ho     = $r['headoffice'] ?? 0;
        $rev_nation = $r['nationwide'] ?? 0;
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
    $lastDay = date('t', $ts);
    $periodLabel = strtoupper(date('F', $ts)) . ' ' . $lastDay . ', ' . date('Y', $ts);
} else {
    $periodLabel = '(No Period Selected)';
}

// ---- Styles ----
$headerBgFill = [
    'fillType'   => Fill::FILL_SOLID,
    'startColor' => ['rgb' => 'F4B183'] // Light orange for row 8
];
$headerDarkFill = [
    'fillType'   => Fill::FILL_SOLID,
    'startColor' => ['rgb' => 'E67E22'] // Darker orange for row 7
];
$headerFontDark = [
    'bold'  => true,
    'color' => ['rgb' => '000000'],
    'size'  => 11,
    'name'  => 'Arial'
];
$headerFontLight = [
    'bold'  => true,
    'color' => ['rgb' => '000000'],
    'size'  => 10,
    'name'  => 'Arial'
];
$boldFont = ['bold' => true, 'name' => 'Arial', 'size' => 10];
$normalFont = ['name' => 'Arial', 'size' => 10];
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

// ---- Column widths ----
$sheet->getColumnDimension('A')->setWidth(52);  // Label
$sheet->getColumnDimension('B')->setWidth(18);  // LUZON/NCR amount
$sheet->getColumnDimension('C')->setWidth(9);   // %
$sheet->getColumnDimension('D')->setWidth(18);  // VISMIN amount
$sheet->getColumnDimension('E')->setWidth(9);   // %
$sheet->getColumnDimension('F')->setWidth(18);  // HEADOFFICE amount (displayed blank)
$sheet->getColumnDimension('G')->setWidth(9);   // %
$sheet->getColumnDimension('H')->setWidth(18);  // NATIONWIDE amount
$sheet->getColumnDimension('I')->setWidth(9);   // %

// ---- Logo ----
$logoPath = __DIR__ . '/../images/weblogo.png';
if (file_exists($logoPath)) {
    $drawing = new Drawing();
    $drawing->setName('M Lhuillier Logo');
    $drawing->setDescription('M Lhuillier');
    $drawing->setPath($logoPath);
    $drawing->setHeight(55);
    $drawing->setCoordinates('B1');
    $drawing->setOffsetX(0);
    $drawing->setWorksheet($sheet);
    $sheet->getRowDimension(1)->setRowHeight(50);
} else {
    $sheet->mergeCells('A1:I1');
    $sheet->setCellValue('A1', 'M LHUILLIER');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20)->getColor()->setRGB('C00000');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(40);
}

// ---- Title rows ----
$sheet->mergeCells('A3:I3');
$sheet->setCellValue('A3', 'PROFIT & LOSS STATEMENT (NATIONWIDE)');
$sheet->getStyle('A3')->applyFromArray([
    'font' => ['bold' => true, 'size' => 12, 'name' => 'Arial'],
    'alignment' => $centerAlign
]);
$sheet->getRowDimension(3)->setRowHeight(20);

$sheet->mergeCells('A4:I4');
$sheet->setCellValue('A4', 'FOR THE MONTH ENDED ' . $periodLabel);
$sheet->getStyle('A4')->applyFromArray([
    'font' => ['bold' => true, 'size' => 11, 'name' => 'Arial'],
    'alignment' => $centerAlign
]);
$sheet->getRowDimension(4)->setRowHeight(18);

// Blank row 5 & 6
$sheet->getRowDimension(5)->setRowHeight(8);
$sheet->getRowDimension(6)->setRowHeight(8);

// ---- Header rows 7 & 8 ----
// Row 7: Merged "MLFSI & JEWELERS"
$sheet->mergeCells('B7:I7');
$sheet->setCellValue('B7', 'MLFSI & JEWELERS');
$sheet->getStyle('B7:I7')->applyFromArray([
    'font'      => $headerFontDark,
    'fill'      => $headerDarkFill,
    'alignment' => $centerAlign,
]);
$sheet->getStyle('B7:I7')->getBorders()->getTop()->setBorderStyle(Border::BORDER_THICK);
$sheet->getStyle('B7:I7')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
$sheet->getRowDimension(7)->setRowHeight(20);

// Row 8: Individual Column Headers
$headers = [
    'A8' => '',
    'B8' => "LUZON/NCR",
    'C8' => "%",
    'D8' => "VISMIN",
    'E8' => "%",
    'F8' => "HEAD OFFICE",
    'G8' => "%",
    'H8' => "NATIONWIDE",
    'I8' => "%"
];
foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
}

// Apply styling to Row 8
$sheet->getStyle('B8:I8')->applyFromArray([
    'font'      => $headerFontLight,
    'fill'      => $headerBgFill,
    'alignment' => $centerAlign,
]);
$sheet->getStyle('B8:I8')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK);
$sheet->getStyle('B8:I8')->getBorders()->getBottom()->getColor()->setRGB('000000');

$sheet->getRowDimension(8)->setRowHeight(22);

// // ---- Data rows starting at row 10 (row 9 left blank like screenshot) ----
// $sheet->getRowDimension(9)->setRowHeight(6);

$rowNum = 9;
foreach ($table_rows as $index => $row) {
    if (isset($row['is_manual_spacer']) && $row['is_manual_spacer']) {
        $sheet->getRowDimension($rowNum)->setRowHeight(10);
        $rowNum++;
        continue;
    }

    $label       = $row['label'] ?? '';
    $lncr        = $row['lncr']       ?? 0;
    $vismin      = $row['vismin']     ?? 0;
    $ho          = $row['headoffice'] ?? 0;
    $nation      = $row['nationwide'] ?? 0;
    $isHighlight = !empty($row['is_highlight']);

    $pct_lncr   = $rev_lncr   != 0 ? ($lncr   / $rev_lncr)   * 100 : 0;
    $pct_vismin = $rev_vismin != 0 ? ($vismin / $rev_vismin) * 100 : 0;
    $pct_ho     = $rev_ho     != 0 ? ($ho     / $rev_ho)     * 100 : 0;
    $pct_nation = $rev_nation != 0 ? ($nation / $rev_nation) * 100 : 0;

    $sheet->setCellValue('A' . $rowNum, $label);
    $sheet->setCellValue('B' . $rowNum, $lncr);
    $sheet->setCellValue('C' . $rowNum, round($pct_lncr, 2));
    $sheet->setCellValue('D' . $rowNum, $vismin);
    $sheet->setCellValue('E' . $rowNum, round($pct_vismin, 2));
    // HEADOFFICE column: intentionally left BLANK (matches report page)
    // F and G cells are not populated.
    $sheet->setCellValue('H' . $rowNum, $nation);
    $sheet->setCellValue('I' . $rowNum, round($pct_nation, 2));

    // Number formats
    $sheet->getStyle('B' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"0.00"');
    $sheet->getStyle('D' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"0.00"');
    $sheet->getStyle('H' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"0.00"');
    $sheet->getStyle('C' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('E' . $rowNum)->getNumberFormat()->setFormatCode('0.00');
    $sheet->getStyle('I' . $rowNum)->getNumberFormat()->setFormatCode('0.00');

    // Alignment
    $sheet->getStyle('A' . $rowNum)->applyFromArray(['font' => $normalFont, 'alignment' => $leftAlign]);
    $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray([
        'font'      => $normalFont,
        'alignment' => $rightAlign
    ]);

    // Highlight key summary rows (bold text only, NO fill)
    if ($isHighlight) {
        $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->applyFromArray([
            'font' => $boldFont
        ]);
    }

    // ---- Special row border rules ----
    $upperLabel = strtoupper($label);

    $thinTopRows = [
        'GROSS PROFIT',
        "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
        'EARNINGS BEFORE INTEREST & TAXES',
        'EARNINGS BEFORE TAXES',
        "LESS: TOTAL SELLING AND ADMIN EXPENSES"

    ];
    if (in_array($upperLabel, $thinTopRows, true)) {
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getBorders()->getTop()->getColor()->setRGB('000000');
    }

    // TOTAL NET INCOME: border-top + double border-bottom, orangish fill on amount cells
    if ($upperLabel === 'TOTAL NET INCOME') {
        $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->getFont()->setBold(true);

        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getBorders()->getTop()->getColor()->setRGB('000000');

        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->getBorders()->getBottom()->getColor()->setRGB('000000');

        $orangeAmountFill = [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'F4B183']
        ];
        $sheet->getStyle('B' . $rowNum . ':I' . $rowNum)->applyFromArray([
            'fill' => $orangeAmountFill
        ]);
    }

    // Negative values in red (columns that hold numeric amounts)
    foreach (['B', 'D', 'H'] as $col) {
        $val = $sheet->getCell($col . $rowNum)->getValue();
        if (is_numeric($val) && $val < 0) {
            $sheet->getStyle($col . $rowNum)->getFont()->getColor()->setRGB('FF0000');
        }
    }

    $rowNum++;
}

// Freeze panes below header (now row 9)
$sheet->freezePane('A9');

// Print settings
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