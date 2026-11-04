<?php
// export_comparative_ho_with_past_and_adjustment.php page 5
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

$gl_code_mode = in_array(
    $gl_code_mode,
    ['old', 'new', 'mixed'],
    true
) ? $gl_code_mode : 'old';

// Determine INJ sort order based on GL mode
$inj_sort_order = ($gl_code_mode === 'old') ? 17 : 18;

// ── helpers ───────────────────────────────────────────────────────────────────

function compareMonths(string $month1, string $month2): int
{
    return strtotime($month1 . '-01') - strtotime($month2 . '-01');
}

function is2025OrEarlier(string $month): bool
{
    if (empty($month)) {
        return true;
    }
    $cutoff = strtotime('2025-12-01');
    $month_time = strtotime($month . '-01');
    return $month_time <= $cutoff;
}

function isMarch2026OrEarlier(string $month): bool
{
    if (empty($month)) {
        return true;
    }
    $cutoff = strtotime('2026-03-01');
    $month_time = strtotime($month . '-01');
    return $month_time <= $cutoff;
}

function isApril2026OrLater(string $month): bool
{
    if (empty($month)) {
        return true;
    }
    $cutoff = strtotime('2026-04-01');
    $month_time = strtotime($month . '-01');
    return $month_time >= $cutoff;
}

function getSortOrderRanges(string $gl_code_mode): array
{
    if ($gl_code_mode === 'old') {
        return [
            'revenue_start' => 1,
            'revenue_end' => 22,
            'cost_of_sales' => 23,
            'sa_start' => 24,
            'sa_end' => 25,
            'depreciation' => 26,
            'interest' => 27,
            'tax' => 28
        ];
    } else {
        // New GL or Mixed
        return [
            'revenue_start' => 1,
            'revenue_end' => 23,
            'cost_of_sales' => 24,
            'sa_start' => 25,
            'sa_end' => 26,
            'depreciation' => 27,
            'interest' => 28,
            'tax' => 29
        ];
    }
}

function colLetter(int $col): string
{
    return Coordinate::stringFromColumnIndex($col);
}

// ============================================================
// HARDCODED GL MAPPING FOR OLD GL CODES
// ============================================================

$old_gl_mapping = [
    // COS (Cost of Sales) mappings
    'COS-2' => 'COS-5',
    'COS-3' => 'COS-6',
    'COS-4' => 'COS-7',

    // MLE mappings
    'MLE-2' => 'MLE-3',

    // TAE mappings
    'TAE-15' => 'TAE-16',
    'TAE-16' => 'TAE-17',
    'TAE-17' => 'TAE-18',
    'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20',
    'TAE-20' => 'TAE-21',
    'TAE-21' => 'TAE-22',
    'TAE-22' => 'TAE-23',
    'TAE-23' => null,

    // TOI mappings
    'TOI-18' => null,
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'MLE-2',
    'TOI-22' => 'INJ-5',
    'TOI-23' => 'INJ-4',
    'TOI-24' => null,

    // VEH mappings
    'VEH-5' => 'VEH-6',
    'VEH-6' => 'VEH-7',
    'VEH-7' => 'VEH-8',
    'VEH-8' => 'VEH-9',
    'VEH-9' => 'VEH-10',
];

// ============================================================
// HARDCODED GL MAPPING FOR NEW GL CODES (April 2026 onwards)
// ============================================================

$new_gl_mapping = [
    // COS (Cost of Sales) mappings
    'COS-2' => 'COS-5',
    'COS-3' => 'COS-6',
    'COS-4' => 'COS-7',

    // TAE mappings
    'TAE-15' => 'TAE-16',
    'TAE-16' => 'TAE-17',
    'TAE-17' => 'TAE-18',
    'TAE-18' => 'TAE-19',
    'TAE-19' => 'TAE-20',
    'TAE-20' => 'TAE-21',
    'TAE-21' => 'TAE-22',
    'TAE-22' => 'TAE-23',
    'TAE-23' => null,

    // TOI mappings
    'TOI-18' => null,
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'COS-8',
    'TOI-22' => null,
    'TOI-23' => null,
    'TOI-24' => null,

    // VEH mappings
    'VEH-5' => 'VEH-7',
    'VEH-6' => 'VEH-8',
    'VEH-7' => 'VEH-9',
    'VEH-8' => 'VEH-10',
    'VEH-9' => 'VEH-11',

    // INS (Insurance) mappings - NEW GL IDs aggregate multiple old GL IDs
    // Array form: TARGET (new) => [SOURCE old GL IDs]
    // Used when reading historical/old-source data into the new structure.
    // For post-April 2026 manual_adjustment rows that already use new IDs,
    // direct matches take precedence over these aggregations.
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

// ── validate filters ─────────────────────────────────────────────────────────

$valid_filters = false;
$error_message = '';
$show_error = false;

if (!empty($third_period) && (empty($primary_period) || empty($previous_period))) {
    $error_message = 'To use Period 3, both Primary and Previous periods must be selected.';
    $show_error = true;
} elseif (!empty($previous_period) && empty($primary_period)) {
    $error_message = 'Primary period is required when selecting a Previous period.';
    $show_error = true;
}

if (
    !$show_error &&
    !empty($primary_period) &&
    !empty($previous_period)
) {
    if (compareMonths($primary_period, $previous_period) <= 0) {
        $error_message = 'Primary period must be later than the Previous period.';
        $show_error = true;
    }

    if (
        !$show_error &&
        !empty($third_period) &&
        compareMonths($primary_period, $third_period) <= 0
    ) {
        $error_message = 'Primary period must be later than Period 3.';
        $show_error = true;
    }

    if (
        !$show_error &&
        !empty($third_period) &&
        !is2025OrEarlier($third_period)
    ) {
        $error_message = 'Period 3 must be 2025 or earlier.';
        $show_error = true;
    }

    if (!$show_error) {
        if ($gl_code_mode === 'old') {
            if (
                !isMarch2026OrEarlier($primary_period) ||
                !isMarch2026OrEarlier($previous_period)
            ) {
                $error_message =
                    'Old GL Code is only available for March 2026 and earlier. ' .
                    'Both Primary and Previous periods must be March 2026 or earlier.';
                $show_error = true;
            }
        } elseif ($gl_code_mode === 'new') {
            if (
                !isApril2026OrLater($primary_period) ||
                !isApril2026OrLater($previous_period)
            ) {
                $error_message =
                    'New GL Code is only available for April 2026 onwards. ' .
                    'Both Primary and Previous periods must be April 2026 or later.';
                $show_error = true;
            }
        }
    }

    if (!$show_error) {
        $valid_filters = true;
    }
}

// ============================================================
// GET GL MAPPING BASED ON GL CODE MODE
// ============================================================

$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];
$gl_id_by_key = [];

// ============================================================
// MIXED MODE LOOKUPS
// ============================================================

$old_gl_id_to_codes = [];
$mixed_id_map = [];

if ($gl_code_mode === 'mixed') {
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

    // New GL ID => old GL IDs (for historical / pre-new-GL source data)
    $mixed_id_map = [
        // ============================================================
        // MON - Monetary (1:1)
        // ============================================================
        'MON-1' => ['MON-1'],
        'MON-2' => ['MON-2'],
        'MON-3' => ['MON-3'],
        'MON-4' => ['MON-4'],
        'MON-5' => ['MON-5'],
        'MON-6' => ['MON-6'],
        'MON-7' => ['MON-7'],

        // ============================================================
        // VEH - Vehicle (SHIFTED mapping)
        // ============================================================
        'VEH-1' => ['VEH-1'],
        'VEH-2' => ['VEH-2'],
        'VEH-3' => ['VEH-3'],
        'VEH-4' => ['VEH-4'],
        'VEH-5' => [],           // new-only (Truck Loan - H.O)
        'VEH-6' => [],           // new-only (Yadea Sales - H.O)
        'VEH-7' => ['VEH-5'],    // Application Fee <- old VEH-5
        'VEH-8' => ['VEH-6'],    // Appraisal Fee <- old VEH-6
        'VEH-9' => ['VEH-7'],    // Penalty & Other <- old VEH-7
        'VEH-10' => ['VEH-8'],   // Chattel Mortgage <- old VEH-8
        'VEH-11' => ['VEH-9'],   // Notarial Income <- old VEH-9

        // ============================================================
        // HOM - Home Loan (1:1)
        // ============================================================
        'HOM-1' => ['HOM-1'],
        'HOM-2' => ['HOM-2'],
        'HOM-3' => ['HOM-3'],
        'HOM-4' => ['HOM-4'],
        'HOM-5' => ['HOM-5'],
        'HOM-6' => ['HOM-6'],

        // ============================================================
        // COM - Commercial (1:1)
        // ============================================================
        'COM-1' => ['COM-1'],
        'COM-2' => ['COM-2'],
        'COM-3' => ['COM-3'],
        'COM-4' => ['COM-4'],
        'COM-5' => ['COM-5'],

        // ============================================================
        // SAL, AUT, YAD - new-only (no old equivalent)
        // ============================================================
        'SAL-1' => [],
        'SAL-2' => [],
        'AUT-1' => [],
        'YAD-1' => [],

        // ============================================================
        // KWA - Kwarta Padala (1:1)
        // ============================================================
        'KWA-1' => ['KWA-1'],
        'KWA-2' => ['KWA-2'],
        'KWA-3' => ['KWA-3'],
        'KWA-4' => ['KWA-4'],
        'KWA-5' => ['KWA-5'],
        'KWA-6' => ['KWA-6'],

        // ============================================================
        // PAY - Payment Solution
        // ============================================================
        'PAY-1' => ['PAY-1'],
        'PAY-2' => [],           // new-only (Business Pro - H.O)

        // ============================================================
        // DOM - Domestic (1:1)
        // ============================================================
        'DOM-1' => ['DOM-1'],
        'DOM-2' => ['DOM-2'],
        'DOM-3' => ['DOM-3'],
        'DOM-4' => ['DOM-4'],
        'DOM-5' => ['DOM-5'],
        'DOM-6' => ['DOM-6'],
        'DOM-7' => ['DOM-7'],
        'DOM-8' => ['DOM-8'],

        // ============================================================
        // POS, MCA, EPA, COR, BIL (1:1)
        // ============================================================
        'POS-1' => ['POS-1'],
        'MCA-1' => ['MCA-1'],
        'MCA-2' => ['MCA-2'],
        'EPA-1' => ['EPA-1'],
        'COR-1' => ['COR-1', 'COR-3'],   // COR-1 displays COR-1 + COR-3 from past_transaction
        'COR-2' => ['COR-2'],
        'BIL-1' => ['BIL-1', 'BIL-3'],   // BIL-1 displays BIL-1 + BIL-3 from past_transaction
        'BIL-2' => ['BIL-2'],

        // ============================================================
        // MLE - ML Express (SHIFTED)
        // ============================================================
        'MLE-1' => ['MLE-1'],
        'MLE-2' => ['TOI-20'],   // <- old TOI-20 (Income from Water Sold)
        'MLE-3' => ['MLE-2'],    // <- old MLE-2 (Franchise Fee)

        // ============================================================
        // INS - Insurance (AGGREGATED)
        // ============================================================
        'INS-1' => ['INS-28', 'INS-29', 'INS-30', 'INS-31', 'INS-34', 'INS-39'],
        'INS-2' => ['INS-25', 'INS-26', 'INS-44', 'INS-47'],
        'INS-3' => ['INS-32', 'INS-33', 'INS-42', 'INS-43', 'INS-45'],
        'INS-4' => ['INS-27', 'INS-46'],
        'INS-5' => ['INS-20', 'INS-21', 'INS-22', 'INS-23', 'INS-24', 'INS-37', 'INS-41'],
        'INS-6' => ['INS-1', 'INS-2', 'INS-3', 'INS-4', 'INS-5', 'INS-6', 'INS-7',
                    'INS-8', 'INS-9', 'INS-10', 'INS-11', 'INS-12', 'INS-13', 'INS-14',
                    'INS-35', 'INS-36', 'INS-40'],
        'INS-7' => ['INS-15', 'INS-16', 'INS-17', 'INS-18', 'INS-19'],
        'INS-8' => [],           // new-only (FPG)
        'INS-9' => [],           // new-only (PhilBrit)
        'INS-10' => ['INS-38'],
        'INS-11' => ['INS-48'],
        'INS-12' => ['INS-49'],
        'INS-13' => [],          // new-only (MAA Insurance Commission - H.O)
        'INS-14' => [],          // new-only (ISPD Income - H.O)

        // ============================================================
        // INJ - Jewelry (1:1 for INJ-1, INJ-2)
        // ============================================================
        'INJ-1' => ['INJ-1'],
        'INJ-2' => ['INJ-2'],
        'INJ-3' => [],           // calculated (not sourced)
        'INJ-4' => [],           // new-only
        'INJ-5' => [],           // new-only
        'INJ-6' => [],           // new-only

        // ============================================================
        // INP, INT, INO, INK (1:1)
        // ============================================================
        'INP-1' => ['INP-1'],
        'INP-2' => ['INP-2'],
        'INT-1' => ['INT-1'],
        'INT-2' => ['INT-2'],
        'INO-1' => ['INO-1'],
        'INO-2' => ['INO-2'],
        'INO-3' => ['INO-3'],
        'INK-1' => ['INK-1'],
        'INK-2' => ['INK-2'],

        // ============================================================
        // TOI - Total Other Income
        // ============================================================
        'TOI-1' => ['TOI-1'],
        'TOI-2' => ['TOI-2'],
        'TOI-3' => ['TOI-3'],
        'TOI-4' => ['TOI-4'],
        'TOI-5' => ['TOI-5'],
        'TOI-6' => ['TOI-6'],
        'TOI-7' => ['TOI-7'],
        'TOI-8' => ['TOI-8'],
        'TOI-9' => ['TOI-9'],
        'TOI-10' => ['TOI-10'],
        'TOI-11' => ['TOI-11'],
        'TOI-12' => ['TOI-12'],
        'TOI-13' => ['TOI-13'],
        'TOI-14' => ['TOI-14'],
        'TOI-15' => ['TOI-15'],
        'TOI-16' => ['TOI-16'],
        'TOI-17' => ['TOI-17'],
        'TOI-18' => ['TOI-19'],  // <- old TOI-19
        'TOI-19' => [],          // new-only
        'TOI-20' => [],          // new-only
        'TOI-21' => ['TOI-21'],
        'TOI-22' => ['TOI-22'],
        'TOI-23' => ['TOI-23'],
        'TOI-24' => ['TOI-24'],
        'TOI-25' => [],          // new-only
        'TOI-26' => [],          // new-only
        'TOI-27' => [],          // new-only
        'TOI-28' => [],          // new-only
        'TOI-29' => [],          // new-only
        'TOI-30' => [],          // new-only
        'TOI-31' => [],          // new-only
        'TOI-32' => [],          // new-only
        'TOI-33' => ['TOI-31'],  // <- old TOI-31
        'TOI-34' => ['TOI-32'],  // <- old TOI-32

        // ============================================================
        // COS - Cost of Sales
        // ============================================================
        'COS-1' => ['COS-1'],
        'COS-2' => [],           // new-only (ML Shop - Jewelry)
        'COS-3' => [],           // new-only (Online Live Selling)
        'COS-4' => [],           // new-only (Jewelry - Cost of Sales)
        'COS-5' => ['COS-2'],    // <- old COS-2 (Special Products)
        'COS-6' => ['COS-3'],    // <- old COS-3 (Telecommunication)
        'COS-7' => ['COS-4'],    // <- old COS-4 (ML Kargo)
        'COS-8' => [],           // new-only
        'COS-9' => [],           // new-only

        // ============================================================
        // TPE - Personnel Expense (1:1)
        // ============================================================
        'TPE-1' => ['TPE-1'],
        'TPE-2' => ['TPE-2'],
        'TPE-3' => ['TPE-3'],
        'TPE-4' => ['TPE-4'],
        'TPE-5' => ['TPE-5'],
        'TPE-6' => ['TPE-6'],
        'TPE-7' => ['TPE-7'],

        // ============================================================
        // TAE - Admin Expense (SHIFTED for TAE-15 onwards)
        // ============================================================
        'TAE-1' => ['TAE-1'],
        'TAE-2' => ['TAE-2'],
        'TAE-3' => ['TAE-3'],
        'TAE-4' => ['TAE-4'],
        'TAE-5' => ['TAE-5'],
        'TAE-6' => ['TAE-6'],
        'TAE-7' => ['TAE-7'],
        'TAE-8' => ['TAE-8'],
        'TAE-9' => ['TAE-9'],
        'TAE-10' => ['TAE-10'],
        'TAE-11' => ['TAE-11'],
        'TAE-12' => ['TAE-12'],
        'TAE-13' => ['TAE-13'],
        'TAE-14' => ['TAE-14'],
        'TAE-15' => [],          // new-only (Dues and Membership)
        'TAE-16' => ['TAE-15'],  // <- old TAE-15 (Rep. & Entertainment)
        'TAE-17' => ['TAE-16'],  // <- old TAE-16 (Store, Appraisal & Cleaning)
        'TAE-18' => ['TAE-17'],  // <- old TAE-17 (Finder's Fee)
        'TAE-19' => ['TAE-18'],  // <- old TAE-18 (Insurance Incentive)
        'TAE-20' => ['TAE-19'],  // <- old TAE-19 (Insurance Expense)
        'TAE-21' => ['TAE-20'],  // <- old TAE-20 (Miscellaneous)
        'TAE-22' => ['TAE-21'],  // <- old TAE-21 (Software Maintenance)
        'TAE-23' => ['TAE-22'],  // <- old TAE-22 (Loss on Robbery)
        'TAE-24' => ['TAE-24'],
        'TAE-25' => ['TAE-25'],

        // ============================================================
        // DAE, IEX, PIT (1:1)
        // ============================================================
        'DAE-1' => ['DAE-1'],
        'DAE-2' => ['DAE-2'],
        'IEX-1' => ['IEX-1'],
        'PIT-1' => ['PIT-1'],
    ];
}

// ============================================================
// DETERMINE GL STRUCTURE TABLE
// ============================================================

$table_name = ($gl_code_mode === 'old')
    ? 'fs_reports.gl_codes_ho'
    : 'fs_reports.new_gl_codes_ho';

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

        $gl_id_by_key[$key] = $gl_id;

        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }

        if (!isset($gl_mapping[$key])) {
            $gl_mapping[$key] = [
                'old' => [],
                'new' => []
            ];
            // Use gl_description_comparative, fallback to description or gl_code
            $gl_descriptions[$key] = $row['gl_description_comparative'] ?? $row['description'] ?? $row['gl_code'] ?? '';
        }

        $current_code = trim((string)($row['gl_code'] ?? ''));

        if ($gl_code_mode === 'mixed') {
            if (
                $current_code !== '' &&
                !in_array($current_code, $gl_mapping[$key]['new'], true)
            ) {
                $gl_mapping[$key]['new'][] = $current_code;
            }

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
                if (
                    $current_code !== '' &&
                    !in_array($current_code, $gl_mapping[$key]['old'], true)
                ) {
                    $gl_mapping[$key]['old'][] = $current_code;
                }
                if (
                    $current_code !== '' &&
                    !in_array($current_code, $gl_mapping[$key]['new'], true)
                ) {
                    $gl_mapping[$key]['new'][] = $current_code;
                }
            } else {
                if (
                    $current_code !== '' &&
                    !in_array($current_code, $gl_mapping[$key]['new'], true)
                ) {
                    $gl_mapping[$key]['new'][] = $current_code;
                }
                if (
                    $current_code !== '' &&
                    !in_array($current_code, $gl_mapping[$key]['old'], true)
                ) {
                    $gl_mapping[$key]['old'][] = $current_code;
                }
            }
        }

        if (
            !isset($sort_order_descriptions[$row['sort_order']]) &&
            !empty($row['description'])
        ) {
            $sort_order_descriptions[$row['sort_order']] = $row['description'];
        }
    }
}

// ============================================================
// HELPER: GET TOTAL FOR A SPECIFIC GL ID
// ============================================================

function get_gl_id_total(
    string $gl_id,
    array $gl_mapping,
    array $gl_id_by_key,
    array $data,
    string $mode
): float {
    if (isset($data[$gl_id])) {
        return (float)$data[$gl_id];
    }

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

// ============================================================
// HELPER: GET DATA FROM MANUAL ADJUSTMENT TABLE (2026+)
// ============================================================

function get_manual_adjustment_data(
    mysqli $conn,
    string $period,
    array $gl_id_by_key,
    string $gl_code_mode,
    array $mixed_id_map = [],
    array $old_gl_mapping = [],
    array $new_gl_mapping = []
): array {
    $data = [];

    if (empty($period)) {
        return $data;
    }

    $parts = explode('-', $period);
    $year_val = $parts[0];
    $month_val = $period . '-01';

    $gl_ids_to_query = [];

    if ($gl_code_mode === 'old') {
        $gl_ids_to_query = array_unique(
            array_filter(array_values($gl_id_by_key))
        );
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') {
                $gl_ids_to_query[] = $src_id;
            }
        }
    } elseif ($gl_code_mode === 'new') {
        $gl_ids_to_query = array_unique(
            array_filter(array_values($gl_id_by_key))
        );
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_id) {
                    if ($src_id !== '') {
                        $gl_ids_to_query[] = $src_id;
                    }
                }
            } else {
                if ($key !== '') {
                    $gl_ids_to_query[] = $key;
                }
            }
        }
    } else {
        // MIXED: query new GL ids, mixed_id_map sources, and
        // scalar mapping sources (e.g. COS-2 for COS-5).
        foreach (
            array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid
        ) {
            if ($new_gid !== '') {
                $gl_ids_to_query[] = $new_gid;
            }
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '') {
                    $gl_ids_to_query[] = $oid;
                }
            }
        }
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '') {
                $gl_ids_to_query[] = $key;
            } elseif (is_array($mapping)) {
                foreach ($mapping as $src_id) {
                    if ($src_id !== '') {
                        $gl_ids_to_query[] = $src_id;
                    }
                }
            }
        }
    }

    $gl_ids_to_query = array_values(array_unique(
        array_filter($gl_ids_to_query, function ($id) {
            return $id !== null && $id !== '';
        })
    ));

    if (empty($gl_ids_to_query)) {
        return $data;
    }

    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));

    $sql = "
        SELECT
            gl_id,
            SUM(
                COALESCE(mlfsi, 0) +
                COALESCE(jewelers, 0)
            ) AS total_amount
        FROM fs_reports.manual_adjustment
        WHERE transaction_year = ?
          AND transaction_month = ?
          AND gl_id IN ({$placeholders})
          AND gl_id IS NOT NULL
          AND gl_id != ''
        GROUP BY gl_id
    ";

    $params = array_merge([$year_val, $month_val], $gl_ids_to_query);
    $types = 'ss' . str_repeat('s', count($gl_ids_to_query));

    $stmt = mysqli_prepare($conn, $sql);
    $raw_data = [];

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $raw_data[$row['gl_id']] = (float)$row['total_amount'];
        }
        mysqli_stmt_close($stmt);
    }

    if ($gl_code_mode === 'old') {
        foreach ($raw_data as $src_gl_id => $amount) {
            if (array_key_exists($src_gl_id, $old_gl_mapping)) {
                $mapped_gl_id = $old_gl_mapping[$src_gl_id];
                if ($mapped_gl_id !== null && $mapped_gl_id !== '') {
                    if (!isset($data[$mapped_gl_id])) {
                        $data[$mapped_gl_id] = 0.0;
                    }
                    $data[$mapped_gl_id] += $amount;
                }
            } else {
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }
                $data[$src_gl_id] += $amount;
            }
        }
    } elseif ($gl_code_mode === 'new') {
        // Scalar: SOURCE => TARGET
        // Array:  TARGET => [SOURCE1, SOURCE2, ...]
        // Hybrid for array mappings: prefer non-zero direct TARGET
        // amount (post-April 2026); otherwise sum historical SOURCES.
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                $target_gl_id = $key;

                $direct = array_key_exists($target_gl_id, $raw_data)
                    ? (float)$raw_data[$target_gl_id]
                    : null;

                $total = 0.0;
                foreach ($mapping as $src_gl_id) {
                    if ($src_gl_id !== '' && isset($raw_data[$src_gl_id])) {
                        $total += (float)$raw_data[$src_gl_id];
                    }
                }

                if ($direct !== null && $direct != 0.0) {
                    $data[$target_gl_id] = $direct;
                } elseif ($total != 0.0) {
                    $data[$target_gl_id] = $total;
                } elseif ($direct !== null) {
                    $data[$target_gl_id] = $direct;
                }
            } else {
                $src_gl_id = $key;
                $target_gl_id = $mapping;
                if (
                    $src_gl_id !== '' &&
                    $target_gl_id !== null &&
                    $target_gl_id !== '' &&
                    isset($raw_data[$src_gl_id])
                ) {
                    if (!isset($data[$target_gl_id])) {
                        $data[$target_gl_id] = 0.0;
                    }
                    $data[$target_gl_id] += (float)$raw_data[$src_gl_id];
                }
            }
        }

        // Direct-match only for GL IDs that were not used as a
        // SCALAR mapping SOURCE (those amounts already moved to
        // their TARGET). Without this, COS-2/3/4 amounts appear
        // both on the old source row and on COS-5/6/7.
        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '') {
                $scalar_source_ids[$key] = true;
            }
        }

        foreach ($gl_id_by_key as $key => $gl_id) {
            if (
                $gl_id !== '' &&
                !isset($data[$gl_id]) &&
                isset($raw_data[$gl_id]) &&
                !isset($scalar_source_ids[$gl_id])
            ) {
                $data[$gl_id] = (float)$raw_data[$gl_id];
            }
        }
    } else {
        // MIXED mode:
        // 1) Apply scalar SOURCE -> TARGET from $new_gl_mapping
        //    (e.g. COS-2 -> COS-5) so old-encoded amounts land
        //    on the correct new rows.
        // 2) For remaining new GL ids, hybrid: prefer non-zero
        //    direct amount, else sum mixed_id_map sources.
        // Scalar SOURCE ids must not keep a direct amount on
        // themselves (avoids COS-2 showing Special Products).

        $scalar_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (!is_array($mapping) && $key !== '' && $mapping !== null && $mapping !== '') {
                $scalar_source_ids[$key] = true;
                if (isset($raw_data[$key])) {
                    if (!isset($data[$mapping])) {
                        $data[$mapping] = 0.0;
                    }
                    $data[$mapping] += (float)$raw_data[$key];
                }
            }
        }

        foreach (
            array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid
        ) {
            if (isset($data[$new_gid])) {
                continue; // already filled by scalar mapping
            }

            // Scalar sources that were remapped must stay 0 here.
            if (isset($scalar_source_ids[$new_gid])) {
                $data[$new_gid] = 0.0;
                continue;
            }

            $direct = array_key_exists($new_gid, $raw_data)
                ? (float)$raw_data[$new_gid]
                : null;

            $total = 0.0;
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '' && isset($raw_data[$oid])) {
                    $total += (float)$raw_data[$oid];
                }
            }

            if ($direct !== null && $direct != 0.0) {
                $data[$new_gid] = $direct;
            } elseif ($total != 0.0) {
                $data[$new_gid] = $total;
            } else {
                $data[$new_gid] = $direct !== null ? $direct : 0.0;
            }
        }
    }

    return $data;
}

// ============================================================
// HELPER: GET DATA FROM PAST TRANSACTION TABLE
// ============================================================

function get_past_transaction_data(
    mysqli $conn,
    string $period,
    array $gl_id_by_key,
    string $gl_code_mode,
    array $mixed_id_map = [],
    array $old_gl_mapping = [],
    array $new_gl_mapping = []
): array {
    $data = [];

    if (empty($period)) {
        return $data;
    }

    $parts = explode('-', $period);
    $year_val = $parts[0];
    $month_val = $period . '-01';

    $gl_ids_to_query = [];

    if ($gl_code_mode === 'mixed') {
        foreach (
            array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid
        ) {
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '') {
                    $gl_ids_to_query[] = $oid;
                }
            }
        }
    } else {
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') {
                $gl_ids_to_query[] = $src_id;
            }
        }

        foreach (
            array_unique(array_filter(array_values($gl_id_by_key))) as $gid
        ) {
            if ($gid !== '') {
                $gl_ids_to_query[] = $gid;
            }
        }

        if ($gl_code_mode === 'new') {
            foreach ($new_gl_mapping as $key => $mapping) {
                if (is_array($mapping)) {
                    foreach ($mapping as $src_id) {
                        if ($src_id !== '') {
                            $gl_ids_to_query[] = $src_id;
                        }
                    }
                } elseif ($key !== '') {
                    $gl_ids_to_query[] = $key;
                }
            }
        }
    }

    $gl_ids_to_query = array_values(array_unique(
        array_filter($gl_ids_to_query, function ($id) {
            return $id !== null && $id !== '';
        })
    ));

    // ========================================================
    // COR-3 and BIL-3 must be queried so their amounts can be
    // merged into COR-1 and BIL-1 respectively.
    // ========================================================
    if (!in_array('COR-3', $gl_ids_to_query, true)) {
        $gl_ids_to_query[] = 'COR-3';
    }

    if (!in_array('BIL-3', $gl_ids_to_query, true)) {
        $gl_ids_to_query[] = 'BIL-3';
    }

    if (empty($gl_ids_to_query)) {
        return $data;
    }

    $placeholders = implode(',', array_fill(0, count($gl_ids_to_query), '?'));

    $sql = "
        SELECT
            gl_id,
            SUM(amount) AS total_amount
        FROM fs_reports.past_transaction
        WHERE transaction_year = ?
          AND transaction_month = ?
          AND gl_id IN ({$placeholders})
          AND gl_id IS NOT NULL
          AND gl_id != ''
        GROUP BY gl_id
    ";

    $params = array_merge([$year_val, $month_val], $gl_ids_to_query);
    $types = 'ss' . str_repeat('s', count($gl_ids_to_query));

    $stmt = mysqli_prepare($conn, $sql);
    $raw_data = [];

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $raw_data[$row['gl_id']] = (float)$row['total_amount'];
        }
        mysqli_stmt_close($stmt);
    }

    // ========================================================
    // MERGE COR-3 INTO COR-1 (past_transaction only)
    // ========================================================
    // COR-1 displays the combined amount of COR-1 + COR-3 from
    // fs_reports.past_transaction.  COR-3 is removed after merging
    // so it cannot be mapped/displayed as a separate line.
    // ========================================================
    if (
        isset($raw_data['COR-3']) &&
        $raw_data['COR-3'] != 0.0
    ) {
        if (!isset($raw_data['COR-1'])) {
            $raw_data['COR-1'] = 0.0;
        }

        $raw_data['COR-1'] += (float)$raw_data['COR-3'];

        unset($raw_data['COR-3']);
    }

    // ========================================================
    // MERGE BIL-3 INTO BIL-1 (past_transaction only)
    // ========================================================
    // BIL-1 displays the combined amount of BIL-1 + BIL-3 from
    // fs_reports.past_transaction.  BIL-3 is removed after merging
    // so it cannot be mapped/displayed as a separate line.
    // ========================================================
    if (
        isset($raw_data['BIL-3']) &&
        $raw_data['BIL-3'] != 0.0
    ) {
        if (!isset($raw_data['BIL-1'])) {
            $raw_data['BIL-1'] = 0.0;
        }

        $raw_data['BIL-1'] += (float)$raw_data['BIL-3'];

        unset($raw_data['BIL-3']);
    }

    if ($gl_code_mode === 'old') {
        foreach ($raw_data as $src_gl_id => $amount) {
            if (array_key_exists($src_gl_id, $old_gl_mapping)) {
                $mapped_gl_id = $old_gl_mapping[$src_gl_id];
                if ($mapped_gl_id !== null && $mapped_gl_id !== '') {
                    if (!isset($data[$mapped_gl_id])) {
                        $data[$mapped_gl_id] = 0.0;
                    }
                    $data[$mapped_gl_id] += $amount;
                }
            } else {
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }
                $data[$src_gl_id] += $amount;
            }
        }
    } elseif ($gl_code_mode === 'new') {
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                $target_gl_id = $key;
                $total = 0.0;
                foreach ($mapping as $src_gl_id) {
                    if ($src_gl_id !== '' && isset($raw_data[$src_gl_id])) {
                        $total += (float)$raw_data[$src_gl_id];
                    }
                }
                if ($total != 0.0) {
                    $data[$target_gl_id] = $total;
                }
            } else {
                $src_gl_id = $key;
                $target_gl_id = $mapping;
                if (
                    $src_gl_id !== '' &&
                    $target_gl_id !== null &&
                    $target_gl_id !== '' &&
                    isset($raw_data[$src_gl_id])
                ) {
                    if (!isset($data[$target_gl_id])) {
                        $data[$target_gl_id] = 0.0;
                    }
                    $data[$target_gl_id] += (float)$raw_data[$src_gl_id];
                }
            }
        }

        $mapped_source_ids = [];
        foreach ($new_gl_mapping as $key => $mapping) {
            if (is_array($mapping)) {
                foreach ($mapping as $src_gl_id) {
                    if ($src_gl_id !== '') {
                        $mapped_source_ids[$src_gl_id] = true;
                    }
                }
            } elseif ($key !== '') {
                $mapped_source_ids[$key] = true;
            }
        }

        foreach ($raw_data as $src_gl_id => $amount) {
            if (
                !isset($mapped_source_ids[$src_gl_id]) &&
                in_array($src_gl_id, $gl_id_by_key, true)
            ) {
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }
                $data[$src_gl_id] += $amount;
            }
        }
    } else {
        foreach (
            array_unique(array_filter(array_values($gl_id_by_key))) as $new_gid
        ) {
            $total = 0.0;
            $old_ids = $mixed_id_map[$new_gid] ?? [$new_gid];
            foreach ($old_ids as $oid) {
                if ($oid !== '' && isset($raw_data[$oid])) {
                    $total += (float)$raw_data[$oid];
                }
            }
            $data[$new_gid] = $total;
        }
    }

    return $data;
}

// ============================================================
// MAIN TABLE CALCULATION
// ============================================================

function compute_table_rows_for_export(
    mysqli $conn,
    string $transaction_year,
    string $primary_period,
    string $previous_period,
    string $third_period,
    string $gl_code_mode,
    array $gl_mapping,
    array $gl_descriptions,
    array $special_keys,
    array $sort_order_descriptions,
    array $gl_id_by_key,
    array $mixed_id_map = [],
    array $old_gl_mapping = [],
    array $new_gl_mapping = [],
    bool $use_real_data = true
): array {
    $fetch_period_data = function (string $period) use (
        $conn,
        $gl_id_by_key,
        $gl_code_mode,
        $mixed_id_map,
        $old_gl_mapping,
        $new_gl_mapping,
        $use_real_data
    ): array {
        if (!$use_real_data || empty($period)) {
            return [];
        }

        $year = (int)explode('-', $period)[0];

        if ($year >= 2026) {
            return get_manual_adjustment_data(
                $conn,
                $period,
                $gl_id_by_key,
                $gl_code_mode,
                $mixed_id_map,
                $old_gl_mapping,
                $new_gl_mapping
            );
        }

        return get_past_transaction_data(
            $conn,
            $period,
            $gl_id_by_key,
            $gl_code_mode,
            $mixed_id_map,
            $old_gl_mapping,
            $new_gl_mapping
        );
    };

    $primary_data  = $fetch_period_data($primary_period);
    $previous_data = $fetch_period_data($previous_period);
    $third_data    = $fetch_period_data($third_period);

    $table_rows = [];

    foreach ($gl_mapping as $key => $codes_detailed) {
        [$sort_order, $sub_order] = explode('|', $key);

        $gl_description = $gl_descriptions[$key] ?? '';
        $is_inj2 = in_array($key, $special_keys, true);
        $current_gl_id = $gl_id_by_key[$key] ?? '';

        $p_mode = $gl_code_mode;
        $prev_mode = $gl_code_mode;
        $t_mode = $gl_code_mode;

        if ($gl_code_mode === 'mixed') {
            $p_mode = isApril2026OrLater($primary_period) ? 'new' : 'old';
            $prev_mode = isApril2026OrLater($previous_period) ? 'new' : 'old';
            $t_mode = (
                !empty($third_period) &&
                isApril2026OrLater($third_period)
            ) ? 'new' : 'old';
        }

        $primary_total  = isset($primary_data[$current_gl_id])
            ? (float)$primary_data[$current_gl_id]
            : 0.0;
        $previous_total = isset($previous_data[$current_gl_id])
            ? (float)$previous_data[$current_gl_id]
            : 0.0;
        $third_total    = isset($third_data[$current_gl_id])
            ? (float)$third_data[$current_gl_id]
            : 0.0;

        // SPECIAL CALCULATION: INJ-3
        $inj3_sort_order = ($gl_code_mode === 'old') ? 17 : 18;

        $third_is_historical =
            !empty($third_period) &&
            is2025OrEarlier($third_period);

        if (
            (int)$sort_order === $inj3_sort_order &&
            $current_gl_id === 'INJ-3'
        ) {
            $primary_total = 0.0;
            $previous_total = 0.0;
            $third_total = 0.0;

            for ($inj_number = 1; $inj_number <= 49; $inj_number++) {
                if ($inj_number === 3) {
                    continue;
                }

                $inj_id = 'INJ-' . $inj_number;

                $inj_primary = get_gl_id_total(
                    $inj_id,
                    $gl_mapping,
                    $gl_id_by_key,
                    $primary_data,
                    $p_mode
                );

                $inj_previous = get_gl_id_total(
                    $inj_id,
                    $gl_mapping,
                    $gl_id_by_key,
                    $previous_data,
                    $prev_mode
                );

                if ($inj_number === 2) {
                    $inj_primary_display = -$inj_primary;
                    $inj_previous_display = -$inj_previous;
                    $primary_total -= $inj_primary_display;
                    $previous_total -= $inj_previous_display;
                } else {
                    $primary_total += $inj_primary;
                    $previous_total += $inj_previous;
                }
            }

            if ($third_is_historical) {
                $inj1_third = get_gl_id_total(
                    'INJ-1',
                    $gl_mapping,
                    $gl_id_by_key,
                    $third_data,
                    $t_mode
                );
                $inj2_third = get_gl_id_total(
                    'INJ-2',
                    $gl_mapping,
                    $gl_id_by_key,
                    $third_data,
                    $t_mode
                );
                $third_total = $inj1_third - $inj2_third;
            } else {
                for ($inj_number = 1; $inj_number <= 49; $inj_number++) {
                    if ($inj_number === 3) {
                        continue;
                    }

                    $inj_id = 'INJ-' . $inj_number;
                    $inj_third = get_gl_id_total(
                        $inj_id,
                        $gl_mapping,
                        $gl_id_by_key,
                        $third_data,
                        $t_mode
                    );

                    if ($inj_number === 2) {
                        $inj_third_display = -$inj_third;
                        $third_total -= $inj_third_display;
                    } else {
                        $third_total += $inj_third;
                    }
                }
            }
        }

        // INJ-2 DISPLAY SIGN FLIP
        if ($is_inj2) {
            $primary_total = -$primary_total;
            $previous_total = -$previous_total;
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

    // GROUP BY SORT ORDER
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $sort_order = $row['sort_order'];
        if (!isset($grouped_rows[$sort_order])) {
            $grouped_rows[$sort_order] = [];
        }
        $grouped_rows[$sort_order][] = $row;
    }

    $final_table_rows = [];

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

    $ranges = getSortOrderRanges($gl_code_mode);

    foreach ($grouped_rows as $sort_order => $rows) {
        $hide_details = ($gl_code_mode === 'old') ? [10, 13] : [11, 14];

        if (!in_array((int)$sort_order, $hide_details, true)) {
            foreach ($rows as $row) {
                $final_table_rows[] = $row;
            }
        }

        $inj3_sort_order = ($gl_code_mode === 'old') ? 17 : 18;

        if ((int)$sort_order === $inj3_sort_order) {
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

        $revenue_start = $ranges['revenue_start'];
        $revenue_end = $ranges['revenue_end'];

        if (
            (int)$sort_order >= $revenue_start &&
            (int)$sort_order <= $revenue_end
        ) {
            $rev_tot_p += $total_primary_total;
            $rev_tot_prev += $total_previous_total;
            $rev_tot_third += $total_third_total;
        }

        $sa_start = $ranges['sa_start'];
        $sa_end = $ranges['sa_end'];

        if (
            (int)$sort_order === $sa_start ||
            (int)$sort_order === $sa_end
        ) {
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
        $percentage_third = (
            !empty($third_period) &&
            $total_third_total != 0
        )
            ? ($inc_dec_third / abs($total_third_total)) * 100
            : (
                !empty($third_period) &&
                $total_primary_total != 0
                    ? 100
                    : 0
            );

        $description = isset($sort_order_descriptions[$sort_order])
            ? $sort_order_descriptions[$sort_order]
            : "Total for Sort Order " . $sort_order;

        $hide_summary = ($gl_code_mode === 'old') ? [26, 27, 28] : [27, 28, 29];

        if (!in_array((int)$sort_order, $hide_summary, true)) {
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

        // Add one empty spacer row after the final S&A sort order:
        // old GL = sort_order 25
        // new/mixed GL = sort_order 26
        $sa_end = $ranges['sa_end'];
        if ((int)$sort_order === $sa_end) {
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
        }

        // TOTAL REVENUES
        $revenue_end = $ranges['revenue_end'];
        $cost_of_sales = $ranges['cost_of_sales'];
        $emit_total_revenues = ((int)$sort_order === $revenue_end);

        if ($emit_total_revenues) {
            $inc_dec_rev = $rev_tot_p - $rev_tot_prev;
            $pct_rev = $rev_tot_prev != 0
                ? ($inc_dec_rev / abs($rev_tot_prev)) * 100
                : ($rev_tot_p != 0 ? 100 : 0);

            $inc_dec_rev_third = $rev_tot_p - $rev_tot_third;
            $pct_rev_third = (
                !empty($third_period) &&
                $rev_tot_third != 0
            )
                ? ($inc_dec_rev_third / abs($rev_tot_third)) * 100
                : (
                    !empty($third_period) &&
                    $rev_tot_p != 0
                        ? 100
                        : 0
                );

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

            // Add spacer after TOTAL REVENUES
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];

            // Cost of Sales/Service header in column B
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

        // GROSS PROFIT
        $cost_of_sales = $ranges['cost_of_sales'];

        if ((int)$sort_order === $cost_of_sales) {
            $gp_tot_p = $rev_tot_p - $total_primary_total;
            $gp_tot_prev = $rev_tot_prev - $total_previous_total;
            $gp_tot_third = $rev_tot_third - $total_third_total;

            $inc_dec_gp = $gp_tot_p - $gp_tot_prev;
            $pct_gp = $gp_tot_prev != 0
                ? ($inc_dec_gp / abs($gp_tot_prev)) * 100
                : ($gp_tot_p != 0 ? 100 : 0);

            $inc_dec_gp_third = $gp_tot_p - $gp_tot_third;
            $pct_gp_third = (
                !empty($third_period) &&
                $gp_tot_third != 0
            )
                ? ($inc_dec_gp_third / abs($gp_tot_third)) * 100
                : (
                    !empty($third_period) &&
                    $gp_tot_p != 0
                        ? 100
                        : 0
                );

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

            // Add spacer after GROSS PROFIT
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

        // Add spacer before TOTAL SELLING AND ADMIN EXPENSES
        $sa_end = $ranges['sa_end'];

        if ((int)$sort_order === ($sa_end - 1)) {
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];
        }

        // TOTAL SELLING AND ADMIN EXPENSES + EBITDA
        if ((int)$sort_order === $sa_end) {
            $inc_dec_sa = $sa_tot_p - $sa_tot_prev;
            $pct_sa = $sa_tot_prev != 0
                ? ($inc_dec_sa / abs($sa_tot_prev)) * 100
                : ($sa_tot_p != 0 ? 100 : 0);

            $inc_dec_sa_third = $sa_tot_p - $sa_tot_third;
            $pct_sa_third = (
                !empty($third_period) &&
                $sa_tot_third != 0
            )
                ? ($inc_dec_sa_third / abs($sa_tot_third)) * 100
                : (
                    !empty($third_period) &&
                    $sa_tot_p != 0
                        ? 100
                        : 0
                );

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


            // Empty spacer row after TOTAL SELLING AND ADMIN EXPENSES
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];

            $ebitda_tot_p = $gp_tot_p - $sa_tot_p;
            $ebitda_tot_prev = $gp_tot_prev - $sa_tot_prev;
            $ebitda_tot_third = $gp_tot_third - $sa_tot_third;

            $inc_dec_ebitda = $ebitda_tot_p - $ebitda_tot_prev;
            $pct_ebitda = $ebitda_tot_prev != 0
                ? ($inc_dec_ebitda / abs($ebitda_tot_prev)) * 100
                : ($ebitda_tot_p != 0 ? 100 : 0);

            $inc_dec_ebitda_third = $ebitda_tot_p - $ebitda_tot_third;
            $pct_ebitda_third = (
                !empty($third_period) &&
                $ebitda_tot_third != 0
            )
                ? ($inc_dec_ebitda_third / abs($ebitda_tot_third)) * 100
                : (
                    !empty($third_period) &&
                    $ebitda_tot_p != 0
                        ? 100
                        : 0
                );

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

        // EBIT
        $depreciation = $ranges['depreciation'];

        if ((int)$sort_order === $depreciation) {
            $ebit_tot_p = $ebitda_tot_p - $total_primary_total;
            $ebit_tot_prev = $ebitda_tot_prev - $total_previous_total;
            $ebit_tot_third = $ebitda_tot_third - $total_third_total;

            $inc_dec_ebit = $ebit_tot_p - $ebit_tot_prev;
            $pct_ebit = $ebit_tot_prev != 0
                ? ($inc_dec_ebit / abs($ebit_tot_prev)) * 100
                : ($ebit_tot_p != 0 ? 100 : 0);

            $inc_dec_ebit_third = $ebit_tot_p - $ebit_tot_third;
            $pct_ebit_third = (
                !empty($third_period) &&
                $ebit_tot_third != 0
            )
                ? ($inc_dec_ebit_third / abs($ebit_tot_third)) * 100
                : (
                    !empty($third_period) &&
                    $ebit_tot_p != 0
                        ? 100
                        : 0
                );

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

        // EBT
        $interest = $ranges['interest'];

        if ((int)$sort_order === $interest) {
            $ebt_tot_p = $ebit_tot_p - $total_primary_total;
            $ebt_tot_prev = $ebit_tot_prev - $total_previous_total;
            $ebt_tot_third = $ebit_tot_third - $total_third_total;

            $inc_dec_ebt = $ebt_tot_p - $ebt_tot_prev;
            $pct_ebt = $ebt_tot_prev != 0
                ? ($inc_dec_ebt / abs($ebt_tot_prev)) * 100
                : ($ebt_tot_p != 0 ? 100 : 0);

            $inc_dec_ebt_third = $ebt_tot_p - $ebt_tot_third;
            $pct_ebt_third = (
                !empty($third_period) &&
                $ebt_tot_third != 0
            )
                ? ($inc_dec_ebt_third / abs($ebt_tot_third)) * 100
                : (
                    !empty($third_period) &&
                    $ebt_tot_p != 0
                        ? 100
                        : 0
                );

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

        // NET INCOME
        $tax = $ranges['tax'];

        if ((int)$sort_order === $tax) {
            $net_tot_p = $ebt_tot_p - $total_primary_total;
            $net_tot_prev = $ebt_tot_prev - $total_previous_total;
            $net_tot_third = $ebt_tot_third - $total_third_total;

            $inc_dec_net = $net_tot_p - $net_tot_prev;
            $pct_net = $net_tot_prev != 0
                ? ($inc_dec_net / abs($net_tot_prev)) * 100
                : ($net_tot_p != 0 ? 100 : 0);

            $inc_dec_net_third = $net_tot_p - $net_tot_third;
            $pct_net_third = (
                !empty($third_period) &&
                $net_tot_third != 0
            )
                ? ($inc_dec_net_third / abs($net_tot_third)) * 100
                : (
                    !empty($third_period) &&
                    $net_tot_p != 0
                        ? 100
                        : 0
                );

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

// REVENUES section header row
$sheet->setCellValue("A$row", 'REVENUES');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF7F29');
$row++;

// ── Write data rows ─────────────────────────────────────────────────────────

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
    $mixed_id_map,
    $old_gl_mapping,
    $new_gl_mapping,
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

$ranges = getSortOrderRanges($gl_code_mode);
$revenue_end_for_outline = $ranges['revenue_end'];
$cost_of_sales_sort = (string)$ranges['cost_of_sales'];

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
        // Put section headers in column A
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

        // Col A:
        // - Revenue sort orders are blank as before.
        // - Hide specific sort-order numbers requested for each GL logic.
        $hidden_sort_orders = ($gl_code_mode === 'old') ? [23, 24, 25, 34, 35] : [24, 25, 26];
        $display_label = (
            is_numeric($label) &&
            (
                (int)$label <= $revenue_end_for_outline ||
                in_array((int)$label, $hidden_sort_orders, true)
            )
        ) ? '' : $label;

        $sheet->setCellValue("A$row", $display_label);
        $sheet->setCellValue("B$row", $item['gl_description'] ?? '');

        // Set amount values
        $sheet->setCellValue("E$row", $item['primary_total'] ?? 0);
        $sheet->setCellValue("F$row", $item['previous_total'] ?? 0);
        $sheet->setCellValue("G$row", $item['third_total'] ?? 0);
        $sheet->setCellValue("I$row", $item['inc_dec'] ?? 0);
        $sheet->setCellValue("K$row", $item['inc_dec_third'] ?? 0);

        // Percentage columns
        foreach ([['J', 'percentage'], ['L', 'percentage_third']] as [$col, $field]) {
            $pct = $item[$field] ?? 0;
            if (abs($pct) >= 1000) {
                $sheet->setCellValue($col . $row, 'mat');
            } else {
                $sheet->setCellValue($col . $row, $pct);
            }
        }

        // Amount formatting
        foreach (['E', 'F', 'G', 'I', 'K'] as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $val = $sheet->getCell($col . $row)->getValue();
            if (is_numeric($val) && $val < 0) {
                $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
            }
        }

        // Percentage formatting
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
        } elseif (is_numeric($label) && (int)$label % 2 != 0 && (int)$label <= $revenue_end_for_outline) {
            $bg = null;
        } else {
            $bg = 'FFFDE9D9';
        }
        if ($bg) {
            $sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bg);
        }

        // Borders
        if ($label == $cost_of_sales_sort) {
            $sheet->getStyle("E$row:L$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        }
        if (in_array($label, [
            "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",
            'EARNINGS BEFORE INTEREST & TAXES',
            'EARNINGS BEFORE TAXES'
        ], true)) {
            $sheet->getStyle("E$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        }
        if ($label === 'TOTAL NET INCOME/LOSS') {
            $sheet->getStyle("E$row:L$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("E$row:L$row")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        }

        // Outline grouping for revenue summary rows
        if (is_numeric($label) && (int)$label >= 1 && (int)$label <= $revenue_end_for_outline) {
            $row++;
            $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
            $row++;
            continue;
        }
    } else {
        // Detail row
        if (
            (int)($item['sort_order'] ?? 0) === $inj_sort_order &&
            in_array((int)($item['sub_order'] ?? 0), [3, 4, 5, 6], true)
        ) {
            $sheet->setCellValue("B$row", $item['gl_description'] ?? '');
        } else {
            $sheet->setCellValue("C$row", $item['gl_description'] ?? '');
        }

        $sheet->setCellValue("E$row", $item['primary_total'] ?? 0);
        $sheet->setCellValue("F$row", $item['previous_total'] ?? 0);
        $sheet->setCellValue("G$row", $item['third_total'] ?? 0);

        $p = floatval($item['primary_total'] ?? 0);
        $prev = floatval($item['previous_total'] ?? 0);
        $t = floatval($item['third_total'] ?? 0);

        $diff1 = $p - $prev;
        $pct1 = ($prev != 0) ? ($diff1 / abs($prev)) * 100 : ($p != 0 ? 100 : 0);
        $sheet->setCellValue("I$row", $diff1);
        if (abs($pct1) >= 1000) {
            $sheet->setCellValue("J$row", 'mat');
        } else {
            $sheet->setCellValue("J$row", $pct1);
        }

        $diff2 = $p - $t;
        $pct2 = (!empty($third_period) && $t != 0)
            ? ($diff2 / abs($t)) * 100
            : (!empty($third_period) && $p != 0 ? 100 : 0);
        $sheet->setCellValue("K$row", $diff2);
        if (abs($pct2) >= 1000) {
            $sheet->setCellValue("L$row", 'mat');
        } else {
            $sheet->setCellValue("L$row", $pct2);
        }

        // Amount formatting for detail rows
        foreach (['E', 'F', 'G', 'I', 'K'] as $col) {
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $val = $sheet->getCell($col . $row)->getValue();
            if (is_numeric($val) && $val < 0) {
                $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB(Color::COLOR_RED);
            }
        }

        // Percentage formatting for detail rows
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

        // Detail rows for revenue sort orders are grouped/hidden
        if (
            is_numeric($item['sort_order']) &&
            (int)$item['sort_order'] >= 1 &&
            (int)$item['sort_order'] <= $revenue_end_for_outline
        ) {
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

$conn->close();
exit;