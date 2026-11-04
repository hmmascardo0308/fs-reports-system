<?php
// comparative_report_original_ho_with_past_and_adjustment_allocate_amount.php
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

// ============================================================
// SAVE HANDLER: comparative_report_with_ho
// ============================================================
$__save_raw = null;
$__save_payload = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $__save_raw = file_get_contents('php://input');
    if ($__save_raw !== false && $__save_raw !== '') {
        $__tmp = json_decode($__save_raw, true);
        if (is_array($__tmp)) {
            $__save_payload = $__tmp;
        }
    }
    if ($__save_payload === null && !empty($_POST['payload'])) {
        $__tmp = json_decode($_POST['payload'], true);
        if (is_array($__tmp)) {
            $__save_payload = $__tmp;
        }
    }
}

$__is_save_action = (
    $_SERVER['REQUEST_METHOD'] === 'POST' && (
        (isset($_POST['action']) && $_POST['action'] === 'save_comparative') ||
        (is_array($__save_payload) && ($__save_payload['action'] ?? '') === 'save_comparative')
    )
);

if ($__is_save_action) {
    header('Content-Type: application/json; charset=utf-8');

    $payload = $__save_payload;
    if (!is_array($payload) || empty($payload['rows']) || !is_array($payload['rows'])) {
        echo json_encode([
            'success' => false,
            'message' => 'No rows to save.'
        ]);
        exit;
    }

    $save_primary  = trim((string)($payload['primary_period'] ?? ''));
    $save_previous = trim((string)($payload['previous_period'] ?? ''));
    $save_third    = trim((string)($payload['third_period'] ?? ''));
    $save_gl_mode  = trim((string)($payload['gl_code_logic'] ?? 'old'));
    if (!in_array($save_gl_mode, ['old', 'new', 'mixed'], true)) {
        $save_gl_mode = 'old';
    }

    if ($save_primary === '' || $save_previous === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Primary and Previous periods are required to save.'
        ]);
        exit;
    }

    // Convert YYYY-MM -> YYYY-MM-01 for DATE columns
    $toDate = static function (string $ym): ?string {
        $ym = trim($ym);
        if ($ym === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
            return $ym . '-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ym)) {
            return $ym;
        }
        return null;
    };

    $primary_date  = $toDate($save_primary);
    $previous_date = $toDate($save_previous);
    $third_date    = $toDate($save_third);

    if ($primary_date === null || $previous_date === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid period format.'
        ]);
        exit;
    }

    $adjusted_by = $username;
    // Asia/Manila local time
    try {
        $dt = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $adjusted_at = $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $adjusted_at = date('Y-m-d H:i:s');
    }

    // Replace existing snapshot for this period set + GL mode
    $del_third = $third_date !== null ? $third_date : '';
    $del_sql = "
        DELETE FROM fs_reports.comparative_report_with_ho
        WHERE primary_period = ?
          AND previous_period = ?
          AND gl_code_logic = ?
          AND (
                (? = '' AND third_period IS NULL)
             OR third_period = NULLIF(?, '')
          )
    ";
    $del_stmt = mysqli_prepare($conn, $del_sql);
    if (!$del_stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Prepare delete failed: ' . mysqli_error($conn)
        ]);
        exit;
    }
    mysqli_stmt_bind_param(
        $del_stmt,
        'sssss',
        $primary_date,
        $previous_date,
        $save_gl_mode,
        $del_third,
        $del_third
    );
    if (!mysqli_stmt_execute($del_stmt)) {
        $err = mysqli_stmt_error($del_stmt);
        mysqli_stmt_close($del_stmt);
        echo json_encode([
            'success' => false,
            'message' => 'Delete failed: ' . $err
        ]);
        exit;
    }
    mysqli_stmt_close($del_stmt);

    // NULLIF converts empty string to SQL NULL for optional third_period
    $ins_sql = "
        INSERT INTO fs_reports.comparative_report_with_ho (
            gl_id,
            sort_order,
            description,
            sub_order,
            gl_description_comparative,
            primary_period,
            primary_period_amount,
            previous_period,
            previous_period_amount,
            third_period,
            third_period_amount,
            gl_code_logic,
            adjusted_by,
            adjusted_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            NULLIF(?, ''), ?,
            ?, ?, ?
        )
    ";
    $ins_stmt = mysqli_prepare($conn, $ins_sql);
    if (!$ins_stmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Prepare insert failed: ' . mysqli_error($conn)
        ]);
        exit;
    }

    $inserted = 0;
    $errors = [];
    $bind_third_date = $third_date !== null ? $third_date : '';

    foreach ($payload['rows'] as $r) {
        $gl_id = trim((string)($r['gl_id'] ?? ''));
        if ($gl_id === '') {
            continue;
        }

        $sort_order  = (int)($r['sort_order'] ?? 0);
        $description = trim((string)($r['description'] ?? ''));
        $sub_order   = (int)($r['sub_order'] ?? 0);
        $gl_desc     = trim((string)($r['gl_description_comparative'] ?? ''));
        $p_amt       = (float)($r['primary_period_amount'] ?? 0);
        $prev_amt    = (float)($r['previous_period_amount'] ?? 0);
        $t_amt       = (float)($r['third_period_amount'] ?? 0);

        // types: s i s i s | s d | s d | s d | s s s  = 14 params
        mysqli_stmt_bind_param(
            $ins_stmt,
            'sisissdsdsdsss',
            $gl_id,
            $sort_order,
            $description,
            $sub_order,
            $gl_desc,
            $primary_date,
            $p_amt,
            $previous_date,
            $prev_amt,
            $bind_third_date,
            $t_amt,
            $save_gl_mode,
            $adjusted_by,
            $adjusted_at
        );

        if (!mysqli_stmt_execute($ins_stmt)) {
            $errors[] = $gl_id . ': ' . mysqli_stmt_error($ins_stmt);
            continue;
        }
        $inserted++;
    }

    mysqli_stmt_close($ins_stmt);

    if ($inserted === 0 && !empty($errors)) {
        echo json_encode([
            'success' => false,
            'message' => 'Insert failed: ' . implode('; ', array_slice($errors, 0, 5)),
            'inserted' => 0
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => "Saved {$inserted} row(s) to comparative_report_with_ho.",
        'inserted' => $inserted,
        'adjusted_by' => $adjusted_by,
        'adjusted_at' => $adjusted_at,
        'errors' => $errors
    ]);
    exit;
}

// Filters
$transaction_year = $_GET['transaction_year'] ?? '';
$primary_period = $_GET['primary_period'] ?? '';
$previous_period = $_GET['previous_period'] ?? '';
$third_period = $_GET['third_period'] ?? '';
$gl_code_mode = $_GET['gl_code_mode'] ?? 'old';

$gl_code_mode = in_array(
    $gl_code_mode,
    ['old', 'new', 'mixed'],
    true
) ? $gl_code_mode : 'old';

// Determine INJ sort order based on GL mode
$inj_sort_order = ($gl_code_mode === 'old') ? 17 : 18;

// INJ section total is INJ-3 + INJ-4 + INJ-5 + INJ-6
// (INJ-3 is already INJ-1 - INJ-2, so INJ-2 must NOT be added again)
$inj_section_ids = ['INJ-3', 'INJ-4', 'INJ-5', 'INJ-6'];

// Determine COS (Cost of Sales/Service) sort order based on GL mode
// old: sort_order 23, new/mixed: sort_order 24
$cos_sort_order = ($gl_code_mode === 'old') ? 23 : 24;

// COS section total is COS-4 + COS-5 + COS-6 + COS-7 + COS-8 + COS-9
// (COS-4 is already COS-1 + COS-2 + COS-3, so COS-1/2/3 must NOT be added again)
$cos_section_ids = ['COS-4', 'COS-5', 'COS-6', 'COS-7', 'COS-8', 'COS-9'];

// Error messages for validation
$error_message = '';

// Helper function to compare months
function compareMonths(string $month1, string $month2): int
{
    return strtotime($month1 . '-01') - strtotime($month2 . '-01');
}

// Helper function to check if a month is 2025 or earlier (for Period 3)
function is2025OrEarlier(string $month): bool
{
    if (empty($month)) {
        return true;
    }

    $cutoff = strtotime('2025-12-01');
    $month_time = strtotime($month . '-01');

    return $month_time <= $cutoff;
}

// Helper function to check if a month is March 2026 or earlier (for Old GL)
function isMarch2026OrEarlier(string $month): bool
{
    if (empty($month)) {
        return true;
    }

    $cutoff = strtotime('2026-03-01');
    $month_time = strtotime($month . '-01');

    return $month_time <= $cutoff;
}

// Helper function to check if a month is April 2026 or later (for New GL)
function isApril2026OrLater(string $month): bool
{
    if (empty($month)) {
        return true;
    }

    $cutoff = strtotime('2026-04-01');
    $month_time = strtotime($month . '-01');

    return $month_time >= $cutoff;
}

// Helper function to get sort order ranges based on GL code mode
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
        // New GL or Mixed (Mixed will determine per period)
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

// ============================================================
// HARDCODED GL MAPPING FOR OLD GL CODES
// ============================================================

// Mapping from past_transaction gl_id to old gl_codes_ho gl_id
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
    'TAE-23' => null, // none
    
    // TOI mappings
    'TOI-18' => null, // none
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'MLE-2',
    'TOI-22' => 'INJ-5',
    'TOI-23' => 'INJ-4',
    'TOI-24' => null, // none
    
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

// Scalar mappings: manual_adjustment/past_transaction SOURCE GL ID -> new_gl_codes_ho TARGET GL ID.
// Array mappings (e.g. INS): TARGET GL ID -> SOURCE GL IDs.
// Used when gl_code_mode === 'new'
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
    'TAE-23' => null, // none
    
    // TOI mappings
    'TOI-18' => null, // none
    'TOI-19' => 'TOI-18',
    'TOI-20' => 'COS-8',  // Changed from MLE-2 to COS-8
    'TOI-22' => null, // none
    'TOI-23' => null, // none
    'TOI-24' => null, // none
    
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
    'INS-10' => ['INS-38'],
    'INS-11' => ['INS-48'],
    'INS-12' => ['INS-49'],
    'INS-13' => [],
    'INS-14' => [],
];

// Validate periods and GL code mode
$show_error = false;
$valid_filters = false;

// Check for valid period selection combination
if (!empty($third_period) && (empty($primary_period) || empty($previous_period))) {

    $error_message = 'To use Period 3, both Primary and Previous periods must be selected.';
    $show_error = true;

} elseif (!empty($previous_period) && empty($primary_period)) {

    $error_message = 'Primary period is required when selecting a Previous period.';
    $show_error = true;
}

// Only validate if both periods are provided
if (
    !$show_error &&
    !empty($primary_period) &&
    !empty($previous_period)
) {

    // Primary period must be greater than previous period
    if (compareMonths($primary_period, $previous_period) <= 0) {

        $error_message = 'Primary period must be later than the Previous period.';
        $show_error = true;
    }

    // Primary period must be greater than Period 3
    if (
        !$show_error &&
        !empty($third_period) &&
        compareMonths($primary_period, $third_period) <= 0
    ) {

        $error_message = 'Primary period must be later than Period 3.';
        $show_error = true;
    }

    // Period 3 must be 2025 or earlier
    if (
        !$show_error &&
        !empty($third_period) &&
        !is2025OrEarlier($third_period)
    ) {

        $error_message = 'Period 3 must be 2025 or earlier.';
        $show_error = true;
    }

    // GL code mode restrictions (for Primary and Previous periods only)
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

        } elseif ($gl_code_mode === 'mixed') {

            // Mixed mode is only allowed when the Primary and Previous
            // periods straddle the old/new GL cutoff (one is March 2026
            // or earlier, the other is April 2026 or later).
            $primary_is_old   = isMarch2026OrEarlier($primary_period);
            $primary_is_new   = isApril2026OrLater($primary_period);
            $previous_is_old  = isMarch2026OrEarlier($previous_period);
            $previous_is_new  = isApril2026OrLater($previous_period);

            $straddles_cutoff = (
                ($primary_is_old && $previous_is_new) ||
                ($primary_is_new && $previous_is_old)
            );

            if (!$straddles_cutoff) {

                $error_message =
                    'Mix GL Code is only available when the Primary and Previous periods overlap the old/new GL cutoff. ' .
                    'One period must be March 2026 or earlier and the other must be April 2026 or later.';

                $show_error = true;
            }
        }
    }

    if (!$show_error) {
        $valid_filters = true;
    }
}

if (
    isset($_GET['reset']) &&
    $_GET['reset'] === '1'
) {
    header("Location: comparative_report_original_ho_with_past_and_adjustment.php");
    exit;
}

// ============================================================
// DROPDOWN OPTIONS
// ============================================================

$distinct_years = [];

$years_query = "
    SELECT DISTINCT transaction_year FROM (
        SELECT transaction_year FROM fs_reports.manual_adjustment
        WHERE transaction_year IS NOT NULL
        UNION
        SELECT transaction_year FROM fs_reports.past_transaction
        WHERE transaction_year IS NOT NULL
    ) AS years
    ORDER BY transaction_year DESC
";

$years_res = mysqli_query($conn, $years_query);

if ($years_res) {

    while ($y = mysqli_fetch_assoc($years_res)) {

        $val = trim(
            (string)($y['transaction_year'] ?? '')
        );

        if (
            $val !== '' &&
            !in_array($val, $distinct_years, true)
        ) {
            $distinct_years[] = $val;
        }
    }
}

// ============================================================
// GET GL MAPPING BASED ON GL CODE MODE
// ============================================================

$gl_mapping = [];
$gl_descriptions = [];
$sort_order_descriptions = [];
$special_keys = [];

/*
 * NEW:
 * Keep track of which GL ID belongs to each
 * sort_order|sub_order combination.
 */
$gl_id_by_key = [];

// ============================================================
// MIXED MODE LOOKUPS
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

            $old_gl_id_to_codes[$row['gl_id']][] =
                trim($row['gl_code']);
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
    'VEH-7' => ['VEH-5'],    // Application Fee ← old VEH-5
    'VEH-8' => ['VEH-6'],    // Appraisal Fee ← old VEH-6
    'VEH-9' => ['VEH-7'],    // Penalty & Other ← old VEH-7
    'VEH-10' => ['VEH-8'],   // Chattel Mortgage ← old VEH-8
    'VEH-11' => ['VEH-9'],   // Notarial Income ← old VEH-9

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
    'MLE-2' => ['TOI-20'],   // ← old TOI-20 (Income from Water Sold)
    'MLE-3' => ['MLE-2'],    // ← old MLE-2 (Franchise Fee)

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
    'TOI-18' => ['TOI-19'],  // ← old TOI-19
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
    'TOI-33' => ['TOI-31'],  // ← old TOI-31
    'TOI-34' => ['TOI-32'],  // ← old TOI-32

    // ============================================================
    // COS - Cost of Sales
    // ============================================================
    'COS-1' => ['COS-1'],
    'COS-2' => [],           // new-only (ML Shop - Jewelry)
    'COS-3' => [],           // new-only (Online Live Selling)
    'COS-4' => [],           // new-only (Jewelry - Cost of Sales)
    'COS-5' => ['COS-2'],    // ← old COS-2 (Special Products)
    'COS-6' => ['COS-3'],    // ← old COS-3 (Telecommunication)
    'COS-7' => ['COS-4'],    // ← old COS-4 (ML Kargo)
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
    'TAE-16' => ['TAE-15'],  // ← old TAE-15 (Rep. & Entertainment)
    'TAE-17' => ['TAE-16'],  // ← old TAE-16 (Store, Appraisal & Cleaning)
    'TAE-18' => ['TAE-17'],  // ← old TAE-17 (Finder's Fee)
    'TAE-19' => ['TAE-18'],  // ← old TAE-18 (Insurance Incentive)
    'TAE-20' => ['TAE-19'],  // ← old TAE-19 (Insurance Expense)
    'TAE-21' => ['TAE-20'],  // ← old TAE-20 (Miscellaneous)
    'TAE-22' => ['TAE-21'],  // ← old TAE-21 (Software Maintenance)
    'TAE-23' => ['TAE-22'],  // ← old TAE-22 (Loss on Robbery)
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

$table_name =
    ($gl_code_mode === 'old')
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

$gl_structure_result =
    mysqli_query($conn, $gl_structure_query);

if ($gl_structure_result) {

    while ($row = mysqli_fetch_assoc($gl_structure_result)) {

        $key =
            $row['sort_order'] .
            '|' .
            $row['sub_order'];

        $gl_id = $row['gl_id'] ?? '';

        /*
         * NEW:
         * Save GL ID for this row.
         */
        $gl_id_by_key[$key] = $gl_id;

        if ($gl_id === 'INJ-2') {
            $special_keys[] = $key;
        }

        if (!isset($gl_mapping[$key])) {

            $gl_mapping[$key] = [
                'old' => [],
                'new' => []
            ];

            $gl_descriptions[$key] =
                $row['gl_description_comparative'] ?? '';
        }

        $current_code =
            trim((string)($row['gl_code'] ?? ''));

        if ($gl_code_mode === 'mixed') {

            // New code
            if (
                $current_code !== '' &&
                !in_array(
                    $current_code,
                    $gl_mapping[$key]['new'],
                    true
                )
            ) {

                $gl_mapping[$key]['new'][] =
                    $current_code;
            }

            // Old codes
            $target_old_ids =
                $mixed_id_map[$gl_id] ?? [$gl_id];

            foreach ($target_old_ids as $oid) {

                if (isset($old_gl_id_to_codes[$oid])) {

                    foreach (
                        $old_gl_id_to_codes[$oid]
                        as $oc
                    ) {

                        if (
                            !in_array(
                                $oc,
                                $gl_mapping[$key]['old'],
                                true
                            )
                        ) {

                            $gl_mapping[$key]['old'][] =
                                $oc;
                        }
                    }
                }
            }

        } else {

            if ($gl_code_mode === 'old') {

                if (
                    $current_code !== '' &&
                    !in_array(
                        $current_code,
                        $gl_mapping[$key]['old'],
                        true
                    )
                ) {

                    $gl_mapping[$key]['old'][] =
                        $current_code;
                }

                if (
                    $current_code !== '' &&
                    !in_array(
                        $current_code,
                        $gl_mapping[$key]['new'],
                        true
                    )
                ) {

                    $gl_mapping[$key]['new'][] =
                        $current_code;
                }

            } else {

                if (
                    $current_code !== '' &&
                    !in_array(
                        $current_code,
                        $gl_mapping[$key]['new'],
                        true
                    )
                ) {

                    $gl_mapping[$key]['new'][] =
                        $current_code;
                }

                if (
                    $current_code !== '' &&
                    !in_array(
                        $current_code,
                        $gl_mapping[$key]['old'],
                        true
                    )
                ) {

                    $gl_mapping[$key]['old'][] =
                        $current_code;
                }
            }
        }

        if (
            !isset(
                $sort_order_descriptions[
                    $row['sort_order']
                ]
            ) &&
            !empty($row['description'])
        ) {

            $sort_order_descriptions[
                $row['sort_order']
            ] = $row['description'];
        }
    }
}

// ============================================================
// HELPER:
// GET TOTAL FOR A SPECIFIC GL ID
// ============================================================

function get_gl_id_total(
    string $gl_id,
    array $gl_mapping,
    array $gl_id_by_key,
    array $data,
    string $mode
): float {

    // past_transaction data is already keyed by gl_id;
    // comparative_report data is keyed by numerical gl_code.
    if (isset($data[$gl_id])) {
        return (float)$data[$gl_id];
    }

    $total = 0.0;

    foreach ($gl_mapping as $key => $codes_detailed) {

        if (
            ($gl_id_by_key[$key] ?? '') !== $gl_id
        ) {
            continue;
        }

        $codes =
            $codes_detailed[$mode] ?? [];

        foreach ($codes as $gl_code) {

            if (isset($data[$gl_code])) {

                $total +=
                    (float)$data[$gl_code];
            }
        }
    }

    return $total;
}

// ============================================================
// HELPER: GET DATA FROM MANUAL ADJUSTMENT TABLE (2026+)
// ============================================================
// Example (old GL, MON-5):
//   SELECT SUM(mlfsi + jewelers) AS total
//   FROM fs_reports.manual_adjustment
//   WHERE gl_id = 'MON-5' AND transaction_month = '2026-02-01'
// Data is always keyed by gl_id.

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

    // ========================================================
    // IMPORTANT:
    // $new_gl_mapping has TWO supported forms:
    //
    // 1. Scalar mapping = SOURCE GL -> TARGET GL
    //      'VEH-5' => 'VEH-7'
    //
    // 2. Array mapping = TARGET GL -> SOURCE GLs
    //      'INS-1' => ['INS-28', 'INS-29', ...]
    //
    // Do not reverse scalar mappings.  The previous implementation
    // treated 'VEH-5' => 'VEH-7' as TARGET => SOURCE, which caused
    // July 2025 / VEH amounts to appear on the wrong rows.
    // ========================================================

    $gl_ids_to_query = [];

    if ($gl_code_mode === 'old') {

        // manual_adjustment source IDs for OLD GL mode.
        $gl_ids_to_query = array_unique(
            array_filter(array_values($gl_id_by_key))
        );

        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') {
                $gl_ids_to_query[] = $src_id;
            }
        }

    } elseif ($gl_code_mode === 'new') {

        // NEW mode:
        // - scalar: query the LEFT/SOURCE side
        // - array: query the array SOURCE IDs
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
                // Scalar mapping is SOURCE -> TARGET.
                if ($key !== '') {
                    $gl_ids_to_query[] = $key;
                }
            }
        }

    } else {

        // MIXED: query new GL ids, mixed_id_map sources, and
        // scalar mapping sources (e.g. COS-2 for COS-5).
        foreach (
            array_unique(
                array_filter(array_values($gl_id_by_key))
            ) as $new_gid
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

    // ========================================================
    // QUERY MANUAL ADJUSTMENT
    // ========================================================

    $placeholders = implode(
        ',',
        array_fill(0, count($gl_ids_to_query), '?')
    );

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

    $params = array_merge(
        [$year_val, $month_val],
        $gl_ids_to_query
    );

    $types = 'ss' . str_repeat(
        's',
        count($gl_ids_to_query)
    );

    $stmt = mysqli_prepare($conn, $sql);
    $raw_data = [];

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            $types,
            ...$params
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $raw_data[$row['gl_id']] =
                (float)$row['total_amount'];
        }

        mysqli_stmt_close($stmt);
    }

    // ========================================================
    // MAP RESULT TO REPORT GL IDs
    // ========================================================

    if ($gl_code_mode === 'old') {

        // OLD mapping is SOURCE -> TARGET.
        foreach ($raw_data as $src_gl_id => $amount) {

            if (array_key_exists($src_gl_id, $old_gl_mapping)) {

                $mapped_gl_id =
                    $old_gl_mapping[$src_gl_id];

                if ($mapped_gl_id !== null && $mapped_gl_id !== '') {
                    if (!isset($data[$mapped_gl_id])) {
                        $data[$mapped_gl_id] = 0.0;
                    }

                    $data[$mapped_gl_id] += $amount;
                }

            } else {

                // No mapping means direct match.
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }

                $data[$src_gl_id] += $amount;
            }
        }

    } elseif ($gl_code_mode === 'new') {

        // ====================================================
        // NEW MAPPING
        // ====================================================
        // Scalar mapping:
        //     SOURCE => TARGET
        //
        // Array mapping:
        //     TARGET => [SOURCE1, SOURCE2, ...]
        //
        // For array mappings (e.g. INS): prefer a direct amount
        // already stored under the TARGET id (post-April 2026
        // new GL rows). Only fall back to summing historical
        // SOURCE ids when the target itself has no direct data.
        // ====================================================

        foreach ($new_gl_mapping as $key => $mapping) {

            if (is_array($mapping)) {

                // Array-based aggregation (TARGET -> SOURCES).
                $target_gl_id = $key;

                // Hybrid: post-April 2026 rows store amounts under
                // the new TARGET ids directly. Pre-April / historical
                // rows store amounts under the old SOURCE ids and
                // often still have zero placeholders under TARGET.
                // Prefer a non-zero direct TARGET amount; otherwise
                // fall back to summing the mapped SOURCE ids.
                $direct = array_key_exists($target_gl_id, $raw_data)
                    ? (float)$raw_data[$target_gl_id]
                    : null;

                $total = 0.0;
                foreach ($mapping as $src_gl_id) {
                    if (
                        $src_gl_id !== '' &&
                        isset($raw_data[$src_gl_id])
                    ) {
                        $total +=
                            (float)$raw_data[$src_gl_id];
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

                // Scalar mapping is SOURCE -> TARGET.
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

                    $data[$target_gl_id] +=
                        (float)$raw_data[$src_gl_id];
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
                $data[$gl_id] =
                    (float)$raw_data[$gl_id];
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
            array_unique(
                array_filter(array_values($gl_id_by_key))
            ) as $new_gid
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
            $old_ids =
                $mixed_id_map[$new_gid] ?? [$new_gid];

            foreach ($old_ids as $oid) {
                if (
                    $oid !== '' &&
                    isset($raw_data[$oid])
                ) {
                    $total +=
                        (float)$raw_data[$oid];
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
// HELPER: GET DATA FROM PAST TRANSACTION TABLE (UPDATED)
// ============================================================
// Past transaction stores amounts under gl_id (e.g. 'MON-5').
// For a given gl_id the comparative_report may have multiple
// numerical gl_codes; past_transaction aggregates under the gl_id.
// Example (old GL):
//   gl_codes for MON-5 -> 4500004, 4040003
//   comparative_report 2026-02: SUM(amount) WHERE gl_code IN (...) = 33400
//   past_transaction    2025-02: SUM(amount) WHERE gl_id = 'MON-5'   = 37400
// In mixed mode we map new gl_ids back to their old gl_ids and sum.

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

    // ========================================================
    // IMPORTANT:
    // past_transaction is historical data and its gl_id values
    // are the historical/OLD GL IDs.
    //
    // Therefore, NEW GL display mode must NOT query the RIGHT side
    // of $new_gl_mapping.  It must query the historical source IDs
    // and then map SOURCE -> NEW TARGET.
    //
    // Example from July 2025:
    //
    // past_transaction:
    // VEH-5 = Application Fee       257,450.00
    // VEH-6 = Appraisal Fee          10,500.00
    // VEH-7 = Penalty & Other       401,997.53
    // VEH-8 = Chattel Mortgage      18,000.00
    // VEH-9 = Notarial Income       18,000.00
    //
    // NEW report:
    // VEH-7 = Application Fee      257,450.00
    // VEH-8 = Appraisal Fee         10,500.00
    // VEH-9 = Penalty & Other      401,997.53
    // VEH-10 = Chattel Mortgage     18,000.00
    // VEH-11 = Notarial Income      18,000.00
    // ========================================================

    $gl_ids_to_query = [];

    if ($gl_code_mode === 'mixed') {

        // Mixed mode explicitly maps NEW GL IDs to their
        // underlying historical OLD GL IDs.
        foreach (
            array_unique(
                array_filter(array_values($gl_id_by_key))
            ) as $new_gid
        ) {

            $old_ids =
                $mixed_id_map[$new_gid] ?? [$new_gid];

            foreach ($old_ids as $oid) {
                if ($oid !== '') {
                    $gl_ids_to_query[] = $oid;
                }
            }
        }

    } else {

        // OLD mode and NEW mode both start from historical
        // past_transaction GL IDs.
        //
        // Start with mapping SOURCE IDs so every historical
        // source can be mapped exactly once.
        foreach (array_keys($old_gl_mapping) as $src_id) {
            if ($src_id !== '') {
                $gl_ids_to_query[] = $src_id;
            }
        }

        // Also include structure IDs that are direct matches or
        // are not present in the hardcoded mapping.
        foreach (
            array_unique(
                array_filter(array_values($gl_id_by_key))
            ) as $gid
        ) {
            if ($gid !== '') {
                $gl_ids_to_query[] = $gid;
            }
        }

        // NEW scalar mappings are SOURCE -> TARGET, so the LEFT
        // side is also a historical source that may need querying.
        // Array mappings are TARGET -> SOURCES, so query their
        // array values as well.
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

    // ========================================================
    // QUERY PAST TRANSACTION
    // ========================================================

    $placeholders = implode(
        ',',
        array_fill(0, count($gl_ids_to_query), '?')
    );

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

    $params = array_merge(
        [$year_val, $month_val],
        $gl_ids_to_query
    );

    $types = 'ss' . str_repeat(
        's',
        count($gl_ids_to_query)
    );

    $stmt = mysqli_prepare($conn, $sql);
    $raw_data = [];

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            $types,
            ...$params
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $raw_data[$row['gl_id']] =
                (float)$row['total_amount'];
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

    // ========================================================
    // OLD GL MODE
    // ========================================================

    if ($gl_code_mode === 'old') {

        // Historical source -> OLD report GL ID.
        foreach ($raw_data as $src_gl_id => $amount) {

            if (array_key_exists($src_gl_id, $old_gl_mapping)) {

                $mapped_gl_id =
                    $old_gl_mapping[$src_gl_id];

                if (
                    $mapped_gl_id !== null &&
                    $mapped_gl_id !== ''
                ) {
                    if (!isset($data[$mapped_gl_id])) {
                        $data[$mapped_gl_id] = 0.0;
                    }

                    $data[$mapped_gl_id] += $amount;
                }

            } else {

                // Direct match.
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }

                $data[$src_gl_id] += $amount;
            }
        }

    // ========================================================
    // NEW GL MODE
    // ========================================================

    } elseif ($gl_code_mode === 'new') {

        // ====================================================
        // Scalar mappings are SOURCE -> TARGET.
        // Array mappings are TARGET -> SOURCES.
        // ====================================================

        foreach ($new_gl_mapping as $key => $mapping) {

            if (is_array($mapping)) {

                // TARGET -> multiple historical SOURCES.
                $target_gl_id = $key;
                $total = 0.0;

                foreach ($mapping as $src_gl_id) {
                    if (
                        $src_gl_id !== '' &&
                        isset($raw_data[$src_gl_id])
                    ) {
                        $total +=
                            (float)$raw_data[$src_gl_id];
                    }
                }

                if ($total != 0.0) {
                    $data[$target_gl_id] = $total;
                }

            } else {

                // SOURCE -> TARGET.
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

                    $data[$target_gl_id] +=
                        (float)$raw_data[$src_gl_id];
                }
            }
        }

        // Direct-match historical GL IDs that are not explicitly
        // represented by a new mapping.
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
                isset($gl_id_by_key) &&
                in_array(
                    $src_gl_id,
                    $gl_id_by_key,
                    true
                )
            ) {
                if (!isset($data[$src_gl_id])) {
                    $data[$src_gl_id] = 0.0;
                }

                $data[$src_gl_id] += $amount;
            }
        }

    // ========================================================
    // MIXED GL MODE
    // ========================================================

    } else {

        // Aggregate historical OLD amounts under each current
        // NEW GL ID using the explicit mixed mapping.
        foreach (
            array_unique(
                array_filter(array_values($gl_id_by_key))
            ) as $new_gid
        ) {

            $total = 0.0;
            $old_ids =
                $mixed_id_map[$new_gid] ?? [$new_gid];

            foreach ($old_ids as $oid) {
                if (
                    $oid !== '' &&
                    isset($raw_data[$oid])
                ) {
                    $total +=
                        (float)$raw_data[$oid];
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

function compute_table_rows_for_region_area(
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

    // ========================================================
    // BUILD WHERE CLAUSE
    // ========================================================

    $where_conditions = [];
    $params = [];
    $types = "";

    if (!empty($transaction_year)) {

        $where_conditions[] =
            "transaction_year = ?";

        $params[] =
            $transaction_year;

        $types .= "s";
    }

    // ========================================================
    // Helper: choose data source for a period
    // 2026 and later → manual_adjustment (SUM(mlfsi + jewelers) by gl_id)
    // 2025 and earlier → past_transaction (SUM(amount) by gl_id)
    // ========================================================
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

        // Historical (≤ 2025)
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

    // ========================================================
    // PRIMARY / PREVIOUS / THIRD PERIOD DATA
    // ========================================================

    $primary_data  = $fetch_period_data($primary_period);
    $previous_data = $fetch_period_data($previous_period);
    $third_data    = $fetch_period_data($third_period);

    // All three sources are now keyed by gl_id
    $third_is_old_gl = true; // used only for the total-calc branch below

    // ========================================================
    // BUILD TABLE ROWS
    // ========================================================

    $table_rows = [];

    foreach (
        $gl_mapping as $key => $codes_detailed
    ) {

        [$sort_order, $sub_order] =
            explode('|', $key);

        $gl_description =
            $gl_descriptions[$key] ?? '';

        $is_inj2 =
            in_array(
                $key,
                $special_keys,
                true
            );

        $current_gl_id =
            $gl_id_by_key[$key] ?? '';

        // Determine mode per period
        $p_mode = $gl_code_mode;
        $prev_mode = $gl_code_mode;
        $t_mode = $gl_code_mode;

        if ($gl_code_mode === 'mixed') {

            $p_mode =
                isApril2026OrLater($primary_period)
                    ? 'new'
                    : 'old';

            $prev_mode =
                isApril2026OrLater($previous_period)
                    ? 'new'
                    : 'old';

            $t_mode =
                (
                    !empty($third_period) &&
                    isApril2026OrLater($third_period)
                )
                    ? 'new'
                    : 'old';
        }

        // Modes kept for special INJ-3 calc (get_gl_id_total still accepts them)
        $p_codes   = $codes_detailed[$p_mode] ?? [];
        $prev_codes = $codes_detailed[$prev_mode] ?? [];
        $t_codes   = $codes_detailed[$t_mode] ?? [];

        // ====================================================
        // NORMAL GL CALCULATION
        // Both manual_adjustment (2026+) and past_transaction (≤2025)
        // are keyed by gl_id, so we look up directly by current_gl_id.
        // ====================================================

        $primary_total  = isset($primary_data[$current_gl_id])
            ? (float)$primary_data[$current_gl_id]
            : 0.0;

        $previous_total = isset($previous_data[$current_gl_id])
            ? (float)$previous_data[$current_gl_id]
            : 0.0;

        $third_total    = isset($third_data[$current_gl_id])
            ? (float)$third_data[$current_gl_id]
            : 0.0;

        // ====================================================
        // SPECIAL CALCULATION: INJ-3
        // ====================================================

        // Determine which sort_order to use for INJ-3 special calculation
        $inj3_sort_order = ($gl_code_mode === 'old') ? 17 : 18;

        // Period 3 uses past_transaction for 2025 and earlier.
        // For this historical source, INJ-3 must follow the normal
        // accounting calculation: INJ-1 - INJ-2.
        // This applies in BOTH old and new GL code modes, and no
        // INJ-2 sign flipping is performed for this calculation.
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

            // ----------------------------------------------------
            // PRIMARY / PREVIOUS
            // ----------------------------------------------------
            // Keep the existing calculation for Primary and Previous.
            // These periods may use either past_transaction (<=2025)
            // or manual_adjustment (>=2026), depending on the period.
            // ----------------------------------------------------
            for ($inj_number = 1; $inj_number <= 49; $inj_number++) {

                // INJ-3 is the calculated result,
                // so never include its actual amount.
                if ($inj_number === 3) {
                    continue;
                }

                $inj_id =
                    'INJ-' . $inj_number;

                // Determine the total for Primary
                $inj_primary =
                    get_gl_id_total(
                        $inj_id,
                        $gl_mapping,
                        $gl_id_by_key,
                        $primary_data,
                        $p_mode
                    );

                // Determine the total for Previous
                $inj_previous =
                    get_gl_id_total(
                        $inj_id,
                        $gl_mapping,
                        $gl_id_by_key,
                        $previous_data,
                        $prev_mode
                    );

                // INJ-2: use the existing display-value behavior
                // for Primary and Previous.
                if ($inj_number === 2) {

                    $inj_primary_display = -$inj_primary;
                    $inj_previous_display = -$inj_previous;

                    $primary_total -=
                        $inj_primary_display;

                    $previous_total -=
                        $inj_previous_display;

                } else {

                    // INJ-1 and INJ-4 onward are added (raw = display).
                    $primary_total +=
                        $inj_primary;

                    $previous_total +=
                        $inj_previous;
                }
            }

            // ----------------------------------------------------
            // PERIOD 3 / THIRD PERIOD
            // ----------------------------------------------------
            if ($third_is_historical) {

                // IMPORTANT:
                // 2025 and earlier Period 3 data comes from
                // fs_reports.past_transaction. Do NOT apply the
                // INJ-2 display sign flip here.
                //
                // Historical calculation:
                //     INJ-3 = INJ-1 - INJ-2
                // ------------------------------------------------
                $inj1_third =
                    get_gl_id_total(
                        'INJ-1',
                        $gl_mapping,
                        $gl_id_by_key,
                        $third_data,
                        $t_mode
                    );

                $inj2_third =
                    get_gl_id_total(
                        'INJ-2',
                        $gl_mapping,
                        $gl_id_by_key,
                        $third_data,
                        $t_mode
                    );

                $third_total =
                    $inj1_third - $inj2_third;

            } else {

                // ------------------------------------------------
                // 2026+ / CURRENT THIRD-PERIOD LOGIC
                // ------------------------------------------------
                // Keep the existing INJ-1 through INJ-49 calculation
                // for Period 3 when it is not historical.
                // ------------------------------------------------
                for ($inj_number = 1; $inj_number <= 49; $inj_number++) {

                    // INJ-3 is the calculated result,
                    // so never include its actual amount.
                    if ($inj_number === 3) {
                        continue;
                    }

                    $inj_id =
                        'INJ-' . $inj_number;

                    $inj_third =
                        get_gl_id_total(
                            $inj_id,
                            $gl_mapping,
                            $gl_id_by_key,
                            $third_data,
                            $t_mode
                        );

                    // INJ-2 keeps the existing display sign behavior
                    // for 2026+ Period 3.
                    if ($inj_number === 2) {

                        $inj_third_display = -$inj_third;

                        $third_total -=
                            $inj_third_display;

                    } else {

                        $third_total +=
                            $inj_third;
                    }
                }
            }
        }

        // ====================================================
        // INJ-2 DISPLAY SIGN FLIP
        // ====================================================

        if ($is_inj2) {
            $primary_total = -$primary_total;
            $previous_total = -$previous_total;
            // $third_total = -$third_total;
        }

        // ====================================================
        // ADD DETAIL ROW
        // ====================================================

        $table_rows[] = [

            'sort_order' =>
                $sort_order,

            'sub_order' =>
                $sub_order,

            'gl_id' =>
                $current_gl_id,

            'gl_description' =>
                $gl_description,

            'is_section_header' =>
                false,

            'is_summary_row' =>
                false,

            'primary_total' =>
                $primary_total,

            'previous_total' =>
                $previous_total,

            'third_total' =>
                $third_total,

            'is_inj2' =>
                $is_inj2
        ];
    }

    // ========================================================
    // GROUP BY SORT ORDER
    // ========================================================

    $grouped_rows = [];

    foreach ($table_rows as $row) {

        $sort_order =
            $row['sort_order'];

        if (!isset(
            $grouped_rows[$sort_order]
        )) {

            $grouped_rows[$sort_order] = [];
        }

        $grouped_rows[$sort_order][] =
            $row;
    }

    $final_table_rows = [];

    // ========================================================
    // CUMULATIVE TOTALS
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
    // GET SORT ORDER RANGES BASED ON GL CODE MODE
    // ========================================================

    $ranges = getSortOrderRanges($gl_code_mode);

    // ========================================================
    // BUILD SUMMARY ROWS
    // ========================================================

    foreach (
        $grouped_rows as $sort_order => $rows
    ) {

        // Hide details visually for certain sort orders (old: 10,13 / new: 11,14)
        // but STILL emit the detail rows so Save can persist their amounts.
        $hide_details = ($gl_code_mode === 'old') ? [10, 13] : [11, 14];
        $hide_this_section = in_array((int)$sort_order, $hide_details, true);

        foreach ($rows as $row) {
            if ($hide_this_section) {
                $row['is_hidden_detail'] = true;
            }
            $final_table_rows[] = $row;
        }

        // ====================================================
        // SPECIAL SECTION TOTALS (INJ + COS)
        // ====================================================
        // INJ sort order: section total is
        //     INJ-3 + INJ-4 + INJ-5 + INJ-6
        // (INJ-3 already contains INJ-1 - INJ-2, so INJ-2 must
        //  NOT be added again. In old GL / historical Period 3,
        //  INJ-4..INJ-6 are 0 so the total falls back to INJ-3.)
        //
        // COS (Cost of Sales/Service) sort order: section total is
        //     COS-4 + COS-5 + COS-6 + COS-7 + COS-8 + COS-9
        // (COS-4 already equals COS-1 + COS-2 + COS-3, so those
        //  three lines must NOT be added again.)
        // ====================================================

        $inj3_sort_order = ($gl_code_mode === 'old') ? 17 : 18;
        $inj_section_ids = ['INJ-3', 'INJ-4', 'INJ-5', 'INJ-6'];

        $cos_sort_order_local = ($gl_code_mode === 'old') ? 23 : 24;
        $cos_section_ids = ['COS-4', 'COS-5', 'COS-6', 'COS-7', 'COS-8', 'COS-9'];

        if ((int)$sort_order === $inj3_sort_order) {

            $total_primary_total  = 0.0;
            $total_previous_total = 0.0;
            $total_third_total    = 0.0;

            foreach ($rows as $row) {

                if (
                    in_array(
                        ($row['gl_id'] ?? ''),
                        $inj_section_ids,
                        true
                    )
                ) {
                    $total_primary_total  +=
                        (float)($row['primary_total']  ?? 0);

                    $total_previous_total +=
                        (float)($row['previous_total'] ?? 0);

                    $total_third_total    +=
                        (float)($row['third_total']    ?? 0);
                }
            }

        } elseif ((int)$sort_order === $cos_sort_order_local) {

            $total_primary_total  = 0.0;
            $total_previous_total = 0.0;
            $total_third_total    = 0.0;

            foreach ($rows as $row) {

                if (
                    in_array(
                        ($row['gl_id'] ?? ''),
                        $cos_section_ids,
                        true
                    )
                ) {
                    $total_primary_total  +=
                        (float)($row['primary_total']  ?? 0);

                    $total_previous_total +=
                        (float)($row['previous_total'] ?? 0);

                    $total_third_total    +=
                        (float)($row['third_total']    ?? 0);
                }
            }

        } else {

            $total_primary_total =
                array_sum(
                    array_column(
                        $rows,
                        'primary_total'
                    )
                );

            $total_previous_total =
                array_sum(
                    array_column(
                        $rows,
                        'previous_total'
                    )
                );

            $total_third_total =
                array_sum(
                    array_column(
                        $rows,
                        'third_total'
                    )
                );
        }

        // ====================================================
        // TOTAL REVENUES - UPDATED FOR OLD GL
        // ====================================================
        
        $revenue_start = $ranges['revenue_start'];
        $revenue_end = $ranges['revenue_end'];
        
        if (
            (int)$sort_order >= $revenue_start &&
            (int)$sort_order <= $revenue_end
        ) {

            $rev_tot_p +=
                $total_primary_total;

            $rev_tot_prev +=
                $total_previous_total;

            $rev_tot_third +=
                $total_third_total;
        }

        // ====================================================
        // SELLING & ADMIN - UPDATED FOR OLD GL
        // ====================================================
        
        $sa_start = $ranges['sa_start'];
        $sa_end = $ranges['sa_end'];

        if (
            (int)$sort_order === $sa_start ||
            (int)$sort_order === $sa_end
        ) {

            $sa_tot_p +=
                $total_primary_total;

            $sa_tot_prev +=
                $total_previous_total;

            $sa_tot_third +=
                $total_third_total;
        }

        $inc_dec =
            $total_primary_total -
            $total_previous_total;

        $percentage = 0;

        if ($total_previous_total != 0) {

            $percentage =
                (
                    $inc_dec /
                    $total_previous_total
                ) * 100;

        } elseif ($total_primary_total != 0) {

            $percentage = 100;
        }

        $inc_dec_third =
            $total_primary_total -
            $total_third_total;

        $percentage_third =
            (
                !empty($third_period) &&
                $total_third_total != 0
            )
                ? (
                    $inc_dec_third /
                    abs($total_third_total)
                ) * 100
                : (
                    !empty($third_period) &&
                    $total_primary_total != 0
                        ? 100
                        : 0
                );

        $description =
            isset(
                $sort_order_descriptions[
                    $sort_order
                ]
            )
                ? $sort_order_descriptions[
                    $sort_order
                ]
                : "Total for Sort Order " .
                    $sort_order;

        // Hide summary for certain sort orders
        $hide_summary = ($gl_code_mode === 'old') ? [26, 27, 28] : [27, 28, 29];
        
        if (
            !in_array(
                (int)$sort_order,
                $hide_summary,
                true
            )
        ) {

            $final_table_rows[] = [

                'sort_order' =>
                    $sort_order,

                'sub_order' =>
                    '',

                'gl_description' =>
                    $description,

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'primary_total' =>
                    $total_primary_total,

                'previous_total' =>
                    $total_previous_total,

                'third_total' =>
                    $total_third_total,

                'inc_dec' =>
                    $inc_dec,

                'percentage' =>
                    $percentage,

                'inc_dec_third' =>
                    $inc_dec_third,

                'percentage_third' =>
                    $percentage_third
            ];
        }

        // ====================================================
        // TOTAL REVENUES
        // Old GL: emit after sort_order 22 (revenue_end) so collapse
        //         group is 1–22 followed immediately by TOTAL REVENUES
        // New/Mixed: emit after cost_of_sales (existing behaviour)
        // ====================================================

        $revenue_end   = $ranges['revenue_end'];
        $cost_of_sales = $ranges['cost_of_sales'];

        $emit_total_revenues = ((int)$sort_order === $revenue_end);

        if ($emit_total_revenues) {

            $inc_dec_rev =
                $rev_tot_p -
                $rev_tot_prev;

            $pct_rev =
                $rev_tot_prev != 0
                    ? (
                        $inc_dec_rev /
                        abs($rev_tot_prev)
                    ) * 100
                    : (
                        $rev_tot_p != 0
                            ? 100
                            : 0
                    );

            $inc_dec_rev_third =
                $rev_tot_p -
                $rev_tot_third;

            $pct_rev_third =
                (
                    !empty($third_period) &&
                    $rev_tot_third != 0
                )
                    ? (
                        $inc_dec_rev_third /
                        abs($rev_tot_third)
                    ) * 100
                    : (
                        !empty($third_period) &&
                        $rev_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'sort_order' =>
                    'TOTAL REVENUES',

                'sub_order' =>
                    '',

                'gl_description' =>
                    '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'primary_total' =>
                    $rev_tot_p,

                'previous_total' =>
                    $rev_tot_prev,

                'third_total' =>
                    $rev_tot_third,

                'inc_dec' =>
                    $inc_dec_rev,

                'percentage' =>
                    $pct_rev,

                'inc_dec_third' =>
                    $inc_dec_rev_third,

                'percentage_third' =>
                    $pct_rev_third
            ];

            if ($gl_code_mode === 'old') {
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
        }

        // ====================================================
        // GROSS PROFIT - UPDATED FOR OLD GL
        // ====================================================
        
        $cost_of_sales = $ranges['cost_of_sales'];

        if ((int)$sort_order === $cost_of_sales) {

            $gp_tot_p =
                $rev_tot_p -
                $total_primary_total;

            $gp_tot_prev =
                $rev_tot_prev -
                $total_previous_total;

            $gp_tot_third =
                $rev_tot_third -
                $total_third_total;

            $inc_dec_gp =
                $gp_tot_p -
                $gp_tot_prev;

            $pct_gp =
                $gp_tot_prev != 0
                    ? (
                        $inc_dec_gp /
                        abs($gp_tot_prev)
                    ) * 100
                    : (
                        $gp_tot_p != 0
                            ? 100
                            : 0
                    );

            $inc_dec_gp_third =
                $gp_tot_p -
                $gp_tot_third;

            $pct_gp_third =
                (
                    !empty($third_period) &&
                    $gp_tot_third != 0
                )
                    ? (
                        $inc_dec_gp_third /
                        abs($gp_tot_third)
                    ) * 100
                    : (
                        !empty($third_period) &&
                        $gp_tot_p != 0
                            ? 100
                            : 0
                    );

            // Add spacer above GROSS PROFIT
            $final_table_rows[] = [
                'is_manual_spacer' => true
            ];

            $final_table_rows[] = [

                'sort_order' =>
                    'GROSS PROFIT',

                'sub_order' =>
                    '',

                'gl_description' =>
                    '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'primary_total' =>
                    $gp_tot_p,

                'previous_total' =>
                    $gp_tot_prev,

                'third_total' =>
                    $gp_tot_third,

                'inc_dec' =>
                    $inc_dec_gp,

                'percentage' =>
                    $pct_gp,

                'inc_dec_third' =>
                    $inc_dec_gp_third,

                'percentage_third' =>
                    $pct_gp_third
            ];

            $final_table_rows[] = [

                'sort_order' =>
                    'SELLING & ADMIN EXPENSE',

                'sub_order' =>
                    '',

                'gl_description' =>
                    '',

                'is_section_header' =>
                    true,

                'is_summary_row' =>
                    true,

                'primary_total' =>
                    null,

                'previous_total' =>
                    null,

                'third_total' =>
                    null,

                'inc_dec' =>
                    null,

                'percentage' =>
                    null,

                'inc_dec_third' =>
                    null,

                'percentage_third' =>
                    null
            ];
        }

        // ====================================================
        // TOTAL SELLING AND ADMIN EXPENSES - UPDATED FOR OLD GL
        // ====================================================
        
        $sa_end = $ranges['sa_end'];

        if ((int)$sort_order === $sa_end) {

            $inc_dec_sa =
                $sa_tot_p -
                $sa_tot_prev;

            $pct_sa =
                $sa_tot_prev != 0
                    ? (
                        $inc_dec_sa /
                        abs($sa_tot_prev)
                    ) * 100
                    : (
                        $sa_tot_p != 0
                            ? 100
                            : 0
                    );

            $inc_dec_sa_third =
                $sa_tot_p -
                $sa_tot_third;

            $pct_sa_third =
                (
                    !empty($third_period) &&
                    $sa_tot_third != 0
                )
                    ? (
                        $inc_dec_sa_third /
                        abs($sa_tot_third)
                    ) * 100
                    : (
                        !empty($third_period) &&
                        $sa_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'sort_order' =>
                    'TOTAL SELLING AND ADMIN EXPENSES',

                'sub_order' =>
                    '',

                'gl_description' =>
                    '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'primary_total' =>
                    $sa_tot_p,

                'previous_total' =>
                    $sa_tot_prev,

                'third_total' =>
                    $sa_tot_third,

                'inc_dec' =>
                    $inc_dec_sa,

                'percentage' =>
                    $pct_sa,

                'inc_dec_third' =>
                    $inc_dec_sa_third,

                'percentage_third' =>
                    $pct_sa_third
            ];

            // EBITDA
            $ebitda_tot_p =
                $gp_tot_p -
                $sa_tot_p;

            $ebitda_tot_prev =
                $gp_tot_prev -
                $sa_tot_prev;

            $ebitda_tot_third =
                $gp_tot_third -
                $sa_tot_third;

            $inc_dec_ebitda =
                $ebitda_tot_p -
                $ebitda_tot_prev;

            $pct_ebitda =
                $ebitda_tot_prev != 0
                    ? (
                        $inc_dec_ebitda /
                        abs($ebitda_tot_prev)
                    ) * 100
                    : (
                        $ebitda_tot_p != 0
                            ? 100
                            : 0
                    );

            $inc_dec_ebitda_third =
                $ebitda_tot_p -
                $ebitda_tot_third;

            $pct_ebitda_third =
                (
                    !empty($third_period) &&
                    $ebitda_tot_third != 0
                )
                    ? (
                        $inc_dec_ebitda_third /
                        abs($ebitda_tot_third)
                    ) * 100
                    : (
                        !empty($third_period) &&
                        $ebitda_tot_p != 0
                            ? 100
                            : 0
                    );

            $final_table_rows[] = [

                'sort_order' =>
                    "EARNINGS BEFORE INTEREST, TAXES, DEP'N, & AMORT",

                'sub_order' =>
                    '',

                'gl_description' =>
                    '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'skip_spacer' =>
                    true,

                'primary_total' =>
                    $ebitda_tot_p,

                'previous_total' =>
                    $ebitda_tot_prev,

                'third_total' =>
                    $ebitda_tot_third,

                'inc_dec' =>
                    $inc_dec_ebitda,

                'percentage' =>
                    $pct_ebitda,

                'inc_dec_third' =>
                    $inc_dec_ebitda_third,

                'percentage_third' =>
                    $pct_ebitda_third
            ];
        }

        // ====================================================
        // EBIT - UPDATED FOR OLD GL
        // ====================================================
        
        $depreciation = $ranges['depreciation'];

        if ((int)$sort_order === $depreciation) {

            $ebit_tot_p =
                $ebitda_tot_p -
                $total_primary_total;

            $ebit_tot_prev =
                $ebitda_tot_prev -
                $total_previous_total;

            $ebit_tot_third =
                $ebitda_tot_third -
                $total_third_total;

            $inc_dec_ebit =
                $ebit_tot_p -
                $ebit_tot_prev;

            $pct_ebit =
                $ebit_tot_prev != 0
                    ? (
                        $inc_dec_ebit /
                        abs($ebit_tot_prev)
                    ) * 100
                    : (
                        $ebit_tot_p != 0
                            ? 100
                            : 0
                    );

            $inc_dec_ebit_third =
                $ebit_tot_p -
                $ebit_tot_third;

            $pct_ebit_third =
                (
                    !empty($third_period) &&
                    $ebit_tot_third != 0
                )
                    ? (
                        $inc_dec_ebit_third /
                        abs($ebit_tot_third)
                    ) * 100
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

                'sort_order' =>
                    'EARNINGS BEFORE INTEREST & TAXES',

                'sub_order' =>
                    '',

                'gl_description' =>
                    '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'skip_spacer' =>
                    true,

                'primary_total' =>
                    $ebit_tot_p,

                'previous_total' =>
                    $ebit_tot_prev,

                'third_total' =>
                    $ebit_tot_third,

                'inc_dec' =>
                    $inc_dec_ebit,

                'percentage' =>
                    $pct_ebit,

                'inc_dec_third' =>
                    $inc_dec_ebit_third,

                'percentage_third' =>
                    $pct_ebit_third
            ];
        }

        // ====================================================
        // EBT - UPDATED FOR OLD GL
        // ====================================================
        
        $interest = $ranges['interest'];

        if ((int)$sort_order === $interest) {

            $ebt_tot_p =
                $ebit_tot_p -
                $total_primary_total;

            $ebt_tot_prev =
                $ebit_tot_prev -
                $total_previous_total;

            $ebt_tot_third =
                $ebit_tot_third -
                $total_third_total;

            $inc_dec_ebt =
                $ebt_tot_p -
                $ebt_tot_prev;

            $pct_ebt =
                $ebt_tot_prev != 0
                    ? (
                        $inc_dec_ebt /
                        abs($ebt_tot_prev)
                    ) * 100
                    : (
                        $ebt_tot_p != 0
                            ? 100
                            : 0
                    );

            $inc_dec_ebt_third =
                $ebt_tot_p -
                $ebt_tot_third;

            $pct_ebt_third =
                (
                    !empty($third_period) &&
                    $ebt_tot_third != 0
                )
                    ? (
                        $inc_dec_ebt_third /
                        abs($ebt_tot_third)
                    ) * 100
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

                'sort_order' =>
                    'EARNINGS BEFORE TAXES',

                'sub_order' =>
                    '',

                'gl_description' =>
                    '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'skip_spacer' =>
                    true,

                'primary_total' =>
                    $ebt_tot_p,

                'previous_total' =>
                    $ebt_tot_prev,

                'third_total' =>
                    $ebt_tot_third,

                'inc_dec' =>
                    $inc_dec_ebt,

                'percentage' =>
                    $pct_ebt,

                'inc_dec_third' =>
                    $inc_dec_ebt_third,

                'percentage_third' =>
                    $pct_ebt_third
            ];
        }

        // ====================================================
        // NET INCOME - UPDATED FOR OLD GL
        // ====================================================
        
        $tax = $ranges['tax'];

        if ((int)$sort_order === $tax) {

            $net_tot_p =
                $ebt_tot_p -
                $total_primary_total;

            $net_tot_prev =
                $ebt_tot_prev -
                $total_previous_total;

            $net_tot_third =
                $ebt_tot_third -
                $total_third_total;

            $inc_dec_net =
                $net_tot_p -
                $net_tot_prev;

            $pct_net =
                $net_tot_prev != 0
                    ? (
                        $inc_dec_net /
                        abs($net_tot_prev)
                    ) * 100
                    : (
                        $net_tot_p != 0
                            ? 100
                            : 0
                    );

            $inc_dec_net_third =
                $net_tot_p -
                $net_tot_third;

            $pct_net_third =
                (
                    !empty($third_period) &&
                    $net_tot_third != 0
                )
                    ? (
                        $inc_dec_net_third /
                        abs($net_tot_third)
                    ) * 100
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

                'sort_order' =>
                    'TOTAL NET INCOME/LOSS',

                'sub_order' =>
                    '',

                'gl_description' =>
                    '',

                'is_section_header' =>
                    false,

                'is_summary_row' =>
                    true,

                'skip_spacer' =>
                    true,

                'primary_total' =>
                    $net_tot_p,

                'previous_total' =>
                    $net_tot_prev,

                'third_total' =>
                    $net_tot_third,

                'inc_dec' =>
                    $inc_dec_net,

                'percentage' =>
                    $pct_net,

                'inc_dec_third' =>
                    $inc_dec_net_third,

                'percentage_third' =>
                    $pct_net_third
            ];
        }
    }

    return $final_table_rows;
}

// ============================================================
// BUILD SINGLE TABLE FOR ALL DATA
// ============================================================

$table_rows =
    compute_table_rows_for_region_area(
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

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Comparative Report Original Data Adjustment
    </title>

    <link
        rel="icon"
        href="../images/MLW%20Logo.png"
        type="image/png"
    />

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="css/comparative_original.css?v=<?= time(); ?>"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
    >

</head>

<body>

<main class="main-content">

    <header class="top-bar">

        <h2>
            <a
                href="comparative_report_original_ho_with_past_and_adjustment.php"
                style="font-size: 16px; text-decoration: none;"
            >
                ⬅ Back
            </a>
        </h2>

        <div class="user-badge">

            <span>
                <?= htmlspecialchars($username); ?>
                (<?= htmlspecialchars($user_type); ?>)
            </span>

            <div class="avatar">
                <?= strtoupper(
                    substr($full_name, 0, 1)
                ); ?>
            </div>

        </div>

    </header>

    <div class="content-wrapper">

        <div class="page-title">
            Comparative Report Original Data Adjustment
        </div>

        <?php if (
            $show_error &&
            !empty($error_message)
        ): ?>

            <div class="error-banner">

                <i class="fa-solid fa-circle-exclamation"></i>

                <span>
                    <?= htmlspecialchars($error_message); ?>
                </span>

            </div>

        <?php endif; ?>

        <!-- FILTER FORM -->

        <form
            method="GET"
            class="filter-form"
            id="filterForm"
            onsubmit="return validateForm()"
        >

            <div class="filter-group">

                <label>
                    Transaction Year
                </label>

                <select
                    name="transaction_year"
                    id="yearSelect"
                >

                    <option value="">
                        All Years
                    </option>

                    <?php foreach (
                        $distinct_years as $yr
                    ): ?>

                        <option
                            value="<?= htmlspecialchars($yr) ?>"
                            <?= $transaction_year === $yr
                                ? 'selected'
                                : '' ?>
                        >
                            <?= htmlspecialchars($yr) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="filter-group filter-group--gl-mode">

                <label>
                    GL Code
                </label>

                <div
                    class="radio-group"
                    role="radiogroup"
                    aria-label="GL Code Mode"
                >

                    <label class="radio-option">

                        <input
                            type="radio"
                            name="gl_code_mode"
                            value="old"
                            id="glOldRadio"
                            <?= $gl_code_mode === 'old'
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            Old GL Code
                        </span>

                    </label>

                    <label class="radio-option">

                        <input
                            type="radio"
                            name="gl_code_mode"
                            value="new"
                            id="glNewRadio"
                            <?= $gl_code_mode === 'new'
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            New GL Code
                        </span>

                    </label>

                    <label class="radio-option">

                        <input
                            type="radio"
                            name="gl_code_mode"
                            value="mixed"
                            id="glMixedRadio"
                            <?= $gl_code_mode === 'mixed'
                                ? 'checked'
                                : '' ?>
                        >

                        <span>
                            Mix
                        </span>

                    </label>

                </div>

            </div>

            <div class="filter-group">

                <label>
                    Primary Period
                </label>

                <input
                    type="month"
                    name="primary_period"
                    id="primaryPeriodSelect"
                    value="<?= htmlspecialchars($primary_period) ?>"
                >

            </div>

            <p style="color:red; font-weight:bold;">
                VS
            </p>

            <div class="filter-group">

                <label>
                    Previous Period
                </label>

                <input
                    type="month"
                    name="previous_period"
                    id="previousPeriodSelect"
                    value="<?= htmlspecialchars($previous_period) ?>"
                >

            </div>

            <p style="color:red; font-weight:bold;">
                VS
            </p>

            <div class="filter-group">

                <label>
                    Period 3
                </label>

                <input
                    type="month"
                    name="third_period"
                    id="thirdPeriodSelect"
                    value="<?= htmlspecialchars($third_period) ?>"
                >

            </div>

            <div class="filter-actions">

                <button
                    type="submit"
                    class="btn-filter"
                >
                    <i class="fa-solid fa-filter"></i>
                    Filter
                </button>

                <button
                    type="button"
                    class="btn-collapse"
                    id="collapseBtn"
                >
                    <i class="fa-solid fa-compress"></i>
                    Collapse
                </button>

                <button
                    type="button"
                    class="btn-save"
                    id="saveReportBtn"
                    title="Save current amounts to comparative_report_with_ho"
                    style="background:#059669;color:#fff;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:600;"
                >
                    <i class="fa-solid fa-floppy-disk"></i>
                    Save
                </button>

                <a
                    href="generate_comparative_with_ho_allocate.php"
                    class="btn-report"
                >
                    <i class="fa-solid fa-file"></i>
                    Generate Report
                </a>


                <a
                    href="?reset=1"
                    class="btn-reset"
                >
                    <i class="fa-solid fa-rotate-left"></i>
                    Clear
                </a>

            </div>

        </form>

        <!-- TABLE -->

        <div class="region-block">
            <div class="tables-scroll">
                <div class="tables-grid">
                    <div class="table-container">
 
                        <table class="data-table">

                            <thead>

                                <tr>

                                    <th colspan="4">
                                        Comparative Report
                                    </th>

                                    <th colspan="11">
                                        Nationwide
                                    </th>

                                </tr>

                                <tr>

                                    <th colspan="4"></th>

                                    <th colspan="1">
                                        <?= !empty($primary_period)
                                            ? strtoupper(
                                                date(
                                                    'F Y',
                                                    strtotime(
                                                        $primary_period .
                                                        '-01'
                                                    )
                                                )
                                            )
                                            : '(Primary Period)' ?>
                                    </th>

                                    <th></th>

                                    <th colspan="1">
                                        <?= !empty($previous_period)
                                            ? strtoupper(
                                                date(
                                                    'F Y',
                                                    strtotime(
                                                        $previous_period .
                                                        '-01'
                                                    )
                                                )
                                            )
                                            : '(Previous Period)' ?>
                                    </th>

                                    <th></th>

                                    <th colspan="1">
                                        <?= !empty($third_period)
                                            ? strtoupper(
                                                date(
                                                    'F Y',
                                                    strtotime(
                                                        $third_period .
                                                        '-01'
                                                    )
                                                )
                                            )
                                            : '(Period 3)' ?>
                                    </th>

                                    <th></th>

                                    <th colspan="4">
                                        INCREASE / DECREASE
                                    </th>

                                    <th></th>

                                </tr>

                                <tr>

                                    <th colspan="4"></th>

                                    <th colspan="6"></th>

                                    <th style="text-align:center;">
                                        Previous Month
                                    </th>

                                    <th style="text-align:center;">
                                        %
                                    </th>

                                    <th style="text-align:center;">
                                        Previous Year
                                    </th>

                                    <th style="text-align:center;">
                                        %
                                    </th>

                                    <th></th>

                                </tr>

                            </thead>

                            <tbody class="report-tbody">

                                <tr class="initial-spacer">
                                    <td colspan="15"></td>
                                </tr>

                                <tr class="revenues-header-row">

                                    <td
                                        style="
                                            background-color:#ff7f29;
                                            font-weight:bold;
                                        "
                                    >
                                        REVENUES
                                    </td>

                                    <td
                                        colspan="14"
                                        style="
                                            background-color:#ff7f29;
                                            font-weight:bold;
                                        "
                                    ></td>

                                </tr>

                                <?php if (
                                    empty($table_rows)
                                ): ?>

                                    <tr>

                                        <td
                                            colspan="15"
                                            style="text-align:center;"
                                        >
                                            No data structure available
                                        </td>

                                    </tr>

                                <?php else: ?>

                                    <?php foreach (
                                        $table_rows as $row
                                    ):

                                        if (
                                            isset(
                                                $row[
                                                    'is_manual_spacer'
                                                ]
                                            ) &&
                                            $row[
                                                'is_manual_spacer'
                                            ]
                                        ) {

                                            echo '
                                                <tr
                                                    class="spacer-row"
                                                    style="height:20px;"
                                                >
                                                    <td colspan="15"></td>
                                                </tr>
                                            ';

                                            continue;
                                        }

                                        $is_summary_row =
                                            isset(
                                                $row[
                                                    'is_summary_row'
                                                ]
                                            ) &&
                                            $row[
                                                'is_summary_row'
                                            ] === true;

                                        $is_header =
                                            !empty(
                                                $row[
                                                    'is_section_header'
                                                ]
                                            );

                                        $primary_total =
                                            $row[
                                                'primary_total'
                                            ] ?? 0;

                                        $previous_total =
                                            $row[
                                                'previous_total'
                                            ] ?? 0;

                                        $third_total =
                                            $row[
                                                'third_total'
                                            ] ?? 0;

                                        $has_third =
                                            !empty(
                                                $third_period
                                            );

                                        // INJ-2 sign flip is already applied when
                                        // building table_rows. For historical Period 3
                                        // (2025 and earlier), INJ-3 is calculated directly
                                        // as INJ-1 - INJ-2 from past_transaction, with no
                                        // sign flipping in that calculation.

                                        $inc_dec =
                                            isset(
                                                $row['inc_dec']
                                            )
                                                ? $row['inc_dec']
                                                : (
                                                    $primary_total -
                                                    $previous_total
                                                );

                                        if (
                                            isset(
                                                $row['percentage']
                                            )
                                        ) {

                                            $percentage =
                                                $row['percentage'];

                                        } else {

                                            $percentage =
                                                $previous_total != 0
                                                    ? (
                                                        $inc_dec /
                                                        abs(
                                                            $previous_total
                                                        )
                                                    ) * 100
                                                    : (
                                                        $primary_total != 0
                                                            ? 100
                                                            : 0
                                                    );
                                        }

                                        $inc_dec_class =
                                            $inc_dec > 0
                                                ? 'positive'
                                                : (
                                                    $inc_dec < 0
                                                        ? 'negative'
                                                        : ''
                                                );

                                        $percentage_class =
                                            $percentage > 0
                                                ? 'positive'
                                                : (
                                                    $percentage < 0
                                                        ? 'negative'
                                                        : ''
                                                );

                                        $inc_dec_third =
                                            array_key_exists(
                                                'inc_dec_third',
                                                $row
                                            )
                                                ? $row[
                                                    'inc_dec_third'
                                                ]
                                                : (
                                                    $primary_total -
                                                    $third_total
                                                );

                                        if (
                                            array_key_exists(
                                                'percentage_third',
                                                $row
                                            )
                                        ) {

                                            $percentage_third =
                                                $row[
                                                    'percentage_third'
                                                ];

                                        } else {

                                            $percentage_third =
                                                (
                                                    $has_third &&
                                                    $third_total != 0
                                                )
                                                    ? (
                                                        $inc_dec_third /
                                                        abs(
                                                            $third_total
                                                        )
                                                    ) * 100
                                                    : (
                                                        $has_third &&
                                                        $primary_total != 0
                                                            ? 100
                                                            : 0
                                                    );
                                        }

                                        $inc_dec_third_class =
                                            (
                                                $inc_dec_third !== null &&
                                                $inc_dec_third > 0
                                            )
                                                ? 'positive'
                                                : (
                                                    (
                                                        $inc_dec_third !== null &&
                                                        $inc_dec_third < 0
                                                    )
                                                        ? 'negative'
                                                        : ''
                                                );

                                        $percentage_third_class =
                                            (
                                                $percentage_third !== null &&
                                                $percentage_third > 0
                                            )
                                                ? 'positive'
                                                : (
                                                    $percentage_third < 0
                                                        ? 'negative'
                                                        : ''
                                                );

                                        $row_gl_id =
                                            $row['gl_id'] ?? '';

                                        $row_sub_order =
                                            $row['sub_order'] ?? '';

                                        $row_gl_desc =
                                            $row['gl_description'] ?? '';

                                        $row_section_desc =
                                            $sort_order_descriptions[
                                                $row['sort_order'] ?? ''
                                            ] ?? (
                                                $sort_order_descriptions[
                                                    (int)($row['sort_order'] ?? 0)
                                                ] ?? ''
                                            );

                                    ?>

                                    <?php
                                        $is_hidden_detail = !empty($row['is_hidden_detail']);
                                    ?>
                                    <tr
                                        class="<?= $is_summary_row
                                            ? 'summary-row'
                                            : 'data-row' ?><?= $is_hidden_detail ? ' hidden-detail-row' : '' ?>"
                                        data-sort-order="<?= htmlspecialchars(
                                            (string)($row['sort_order'] ?? '')
                                        ) ?>"
                                        data-gl-id="<?= htmlspecialchars(
                                            $row_gl_id
                                        ) ?>"
                                        <?php if (!$is_summary_row): ?>
                                            data-is-detail="true"
                                            data-sub-order="<?= htmlspecialchars(
                                                (string)$row_sub_order
                                            ) ?>"
                                            data-gl-description="<?= htmlspecialchars(
                                                $row_gl_desc
                                            ) ?>"
                                            data-description="<?= htmlspecialchars(
                                                $row_section_desc
                                            ) ?>"
                                        <?php endif; ?>
                                        <?php if ($is_hidden_detail): ?>
                                            style="display:none;"
                                            data-hidden-detail="true"
                                        <?php endif; ?>
                                    >

                                        <td
                                            class="<?= $is_summary_row
                                                ? 'summary-cell'
                                                : '' ?>"
                                        >
                                            <?= $is_summary_row
                                                ? htmlspecialchars(
                                                    $row['sort_order']
                                                )
                                                : '' ?>
                                        </td>

                                        <td
                                            class="<?= $is_summary_row
                                                ? 'summary-cell'
                                                : '' ?>"
                                        >

                                            <?php if ($is_header): ?>

                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $row['sub_order']
                                                    ) ?>
                                                </strong>

                                            <?php elseif (
                                                $is_summary_row
                                            ): ?>

                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $row['gl_description']
                                                    ) ?>
                                                </strong>

                                            <?php elseif (
                                                (int)(
                                                    $row['sort_order'] ?? 0
                                                ) === $inj_sort_order &&
                                                in_array(
                                                    (int)(
                                                        $row['sub_order'] ?? 0
                                                    ),
                                                    [3, 4, 5, 6],
                                                    true
                                                )
                                            ): ?>

                                                <?= htmlspecialchars(
                                                    $row['gl_description']
                                                ) ?>

                                            <?php endif; ?>

                                        </td>

                                        <td
                                            class="<?= $is_summary_row
                                                ? 'summary-cell summary-description'
                                                : '' ?>"
                                        >

                                            <?php

                                            if (!$is_summary_row) {

                                                if (
                                                    (int)(
                                                        $row['sort_order'] ?? 0
                                                    ) === $inj_sort_order &&
                                                    in_array(
                                                        (int)(
                                                            $row['sub_order'] ?? 0
                                                        ),
                                                        [3, 4, 5, 6],
                                                        true
                                                    )
                                                ) {

                                                    echo '';

                                                } else {

                                                    echo htmlspecialchars(
                                                        $row[
                                                            'gl_description'
                                                        ]
                                                    );
                                                }
                                            }

                                            ?>

                                        </td>

                                        <td
                                            class="<?= $is_summary_row
                                                ? 'summary-cell'
                                                : '' ?>"
                                        ></td>

                                        <!-- PRIMARY -->

                                        <td
                                            class="
                                                numeric-cell
                                                <?= $is_summary_row
                                                    ? 'summary-cell'
                                                    : 'editable-amount' ?>
                                            "
                                            style="<?= $primary_total < 0
                                                ? 'color:red;'
                                                : '' ?>"
                                            <?php if (!$is_summary_row && !$is_header): ?>
                                                data-editable="true"
                                                data-period="primary"
                                                data-value="<?= htmlspecialchars((string)$primary_total) ?>"
                                                title="Double-click to edit"
                                            <?php endif; ?>
                                        >

                                            <?= $is_header
                                                ? ''
                                                : (
                                                    $is_summary_row
                                                        ? '<strong>' .
                                                            number_format(
                                                                $primary_total,
                                                                2
                                                            ) .
                                                          '</strong>'
                                                        : number_format(
                                                            $primary_total,
                                                            2
                                                        )
                                                ) ?>

                                        </td>

                                        <td
                                            class="<?= $is_summary_row
                                                ? 'summary-cell'
                                                : '' ?>"
                                        ></td>

                                        <!-- PREVIOUS -->

                                        <td
                                            class="
                                                numeric-cell
                                                <?= $is_summary_row
                                                    ? 'summary-cell'
                                                    : 'editable-amount' ?>
                                            "
                                            style="<?= $previous_total < 0
                                                ? 'color:red;'
                                                : '' ?>"
                                            <?php if (!$is_summary_row && !$is_header): ?>
                                                data-editable="true"
                                                data-period="previous"
                                                data-value="<?= htmlspecialchars((string)$previous_total) ?>"
                                                title="Double-click to edit"
                                            <?php endif; ?>
                                        >

                                            <?= $is_header
                                                ? ''
                                                : (
                                                    $is_summary_row
                                                        ? '<strong>' .
                                                            number_format(
                                                                $previous_total,
                                                                2
                                                            ) .
                                                          '</strong>'
                                                        : number_format(
                                                            $previous_total,
                                                            2
                                                        )
                                                ) ?>

                                        </td>

                                        <td
                                            class="<?= $is_summary_row
                                                ? 'summary-cell'
                                                : '' ?>"
                                        ></td>

                                        <!-- THIRD -->

                                        <td
                                            class="
                                                numeric-cell
                                                <?= $is_summary_row
                                                    ? 'summary-cell'
                                                    : 'editable-amount' ?>
                                            "
                                            style="<?= $third_total < 0
                                                ? 'color:red;'
                                                : '' ?>"
                                            <?php if (!$is_summary_row && !$is_header): ?>
                                                data-editable="true"
                                                data-period="third"
                                                data-value="<?= htmlspecialchars((string)$third_total) ?>"
                                                title="Double-click to edit"
                                            <?php endif; ?>
                                        >

                                            <?php

                                            if ($is_header) {

                                                echo '';

                                            } else {

                                                echo $is_summary_row
                                                    ? '<strong>' .
                                                        number_format(
                                                            $third_total,
                                                            2
                                                        ) .
                                                      '</strong>'
                                                    : number_format(
                                                        $third_total,
                                                        2
                                                    );
                                            }

                                            ?>

                                        </td>

                                        <td
                                            class="<?= $is_summary_row
                                                ? 'summary-cell'
                                                : '' ?>"
                                        ></td>

                                        <!-- INCREASE / DECREASE -->

                                        <td
                                            class="
                                                numeric-cell
                                                <?= $inc_dec_class ?>
                                                <?= $is_summary_row
                                                    ? 'summary-cell'
                                                    : '' ?>
                                            "
                                            style="<?= $inc_dec < 0
                                                ? 'color:red;'
                                                : '' ?>"
                                        >

                                            <?= $is_header
                                                ? ''
                                                : number_format(
                                                    $inc_dec,
                                                    2
                                                ) ?>

                                        </td>

                                        <!-- PERCENTAGE -->

                                        <td
                                            class="
                                                percentage-cell
                                                <?= $percentage_class ?>
                                                <?= $is_summary_row
                                                    ? 'summary-cell'
                                                    : '' ?>
                                            "
                                            style="<?= $percentage < 0
                                                ? 'color:red;'
                                                : '' ?>"
                                        >

                                            <?php

                                            if ($is_header) {

                                                echo '';

                                            } elseif (
                                                $percentage >= 1000 ||
                                                $percentage <= -1000
                                            ) {

                                                echo 'mat';

                                            } else {

                                                echo number_format(
                                                    $percentage,
                                                    2
                                                ) . '%';
                                            }

                                            ?>

                                        </td>

                                        <!-- THIRD DIFFERENCE -->

                                        <td
                                            class="
                                                numeric-cell
                                                <?= $inc_dec_third_class ?>
                                                <?= $is_summary_row
                                                    ? 'summary-cell'
                                                    : '' ?>
                                            "
                                            style="<?= $inc_dec_third < 0
                                                ? 'color:red;'
                                                : '' ?>"
                                        >

                                            <?= $is_header
                                                ? ''
                                                : number_format(
                                                    (float)$inc_dec_third,
                                                    2
                                                ) ?>

                                        </td>

                                        <!-- THIRD PERCENTAGE -->

                                        <td
                                            class="
                                                percentage-cell
                                                <?= $percentage_third_class ?>
                                                <?= $is_summary_row
                                                    ? 'summary-cell'
                                                    : '' ?>
                                            "
                                            style="<?= $percentage_third < 0
                                                ? 'color:red;'
                                                : '' ?>"
                                        >

                                            <?php

                                            if ($is_header) {

                                                echo '';

                                            } elseif (
                                                $percentage_third >= 1000 ||
                                                $percentage_third <= -1000
                                            ) {

                                                echo 'mat';

                                            } else {

                                                echo number_format(
                                                    (float)$percentage_third,
                                                    2
                                                ) . '%';
                                            }

                                            ?>

                                        </td>

                                        <td
                                            class="<?= $is_summary_row
                                                ? 'summary-cell'
                                                : '' ?>"
                                        ></td>

                                    </tr>

                                    <?php

                                    if (
                                        $is_summary_row &&
                                        !$is_header &&
                                        empty(
                                            $row['skip_spacer']
                                        )
                                    ):

                                    ?>

                                        <tr
                                            class="spacer-row"
                                            data-spacer-for="<?= htmlspecialchars(
                                                $row['sort_order'] ?? ''
                                            ) ?>"
                                            style="height:20px;"
                                        >

                                            <td colspan="15"></td>

                                        </tr>

                                    <?php endif; ?>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </div>

    </div>

</main>

<script>

// ============================================================
// MONTH VALIDATION
// ============================================================

function compareMonths(month1, month2) {

    if (!month1 || !month2) {
        return 0;
    }

    return new Date(
        month1 + '-01'
    ) - new Date(
        month2 + '-01'
    );
}

function is2025OrEarlier(month) {

    if (!month) {
        return true;
    }

    const cutoff =
        new Date('2025-12-01');

    const monthDate =
        new Date(month + '-01');

    return monthDate <= cutoff;
}

function isMarch2026OrEarlier(month) {

    if (!month) {
        return true;
    }

    const cutoff =
        new Date('2026-03-01');

    const monthDate =
        new Date(month + '-01');

    return monthDate <= cutoff;
}

function isApril2026OrLater(month) {

    if (!month) {
        return true;
    }

    const cutoff =
        new Date('2026-04-01');

    const monthDate =
        new Date(month + '-01');

    return monthDate >= cutoff;
}

// ============================================================
// MODAL
// ============================================================

let activeModal = null;

function showModal(message) {

    if (activeModal) {
        activeModal.remove();
    }

    const modalOverlay =
        document.createElement('div');

    modalOverlay.className =
        'modal-overlay';

    modalOverlay.innerHTML = `
        <div class="modal-container">

            <div class="modal-header">

                <h3>
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    Validation Error
                </h3>

            </div>

            <div class="modal-body">

                <p>
                    ${escapeHtml(message)}
                </p>

            </div>

            <div class="modal-footer">

                <button onclick="closeModal()">
                    OK
                </button>

            </div>

        </div>
    `;

    document.body.appendChild(
        modalOverlay
    );

    activeModal =
        modalOverlay;
}

window.closeModal = function () {

    if (activeModal) {

        activeModal.remove();

        activeModal = null;
    }
};

function escapeHtml(text) {

    const div =
        document.createElement('div');

    div.textContent =
        text;

    return div.innerHTML;
}

// ============================================================
// FORM VALIDATION
// ============================================================

function validateForm() {

    const primaryPeriod =
        document.getElementById(
            'primaryPeriodSelect'
        ).value;

    const previousPeriod =
        document.getElementById(
            'previousPeriodSelect'
        ).value;

    const thirdPeriodEl =
        document.getElementById(
            'thirdPeriodSelect'
        );

    const thirdPeriod =
        thirdPeriodEl
            ? thirdPeriodEl.value
            : '';

    const glOldRadio =
        document.getElementById(
            'glOldRadio'
        );

    const glNewRadio =
        document.getElementById(
            'glNewRadio'
        );

    const glMixedRadio =
        document.getElementById(
            'glMixedRadio'
        );

    const glCodeMode =
        glOldRadio.checked
            ? 'old'
            : (
                glNewRadio.checked
                    ? 'new'
                    : (
                        glMixedRadio.checked
                            ? 'mixed'
                            : 'old'
                    )
            );

    if (
        thirdPeriod &&
        (!primaryPeriod || !previousPeriod)
    ) {

        showModal(
            'To use Period 3, both Primary and Previous periods must be selected.'
        );

        return false;
    }

    if (
        previousPeriod &&
        !primaryPeriod
    ) {

        showModal(
            'Primary period is required when selecting a Previous period.'
        );

        return false;
    }

    if (
        !primaryPeriod ||
        !previousPeriod
    ) {

        return true;
    }

    if (
        compareMonths(
            primaryPeriod,
            previousPeriod
        ) <= 0
    ) {

        showModal(
            'Primary period must be later than the Previous period.'
        );

        return false;
    }

    if (
        thirdPeriod &&
        compareMonths(
            primaryPeriod,
            thirdPeriod
        ) <= 0
    ) {

        showModal(
            'Primary period must be later than Period 3.'
        );

        return false;
    }

    // Period 3 must be 2025 or earlier
    if (
        thirdPeriod &&
        !is2025OrEarlier(thirdPeriod)
    ) {

        showModal(
            'Period 3 must be 2025 or earlier.'
        );

        return false;
    }

    // GL code mode restrictions (for Primary and Previous periods only)
    if (glCodeMode === 'old') {

        if (
            !isMarch2026OrEarlier(
                primaryPeriod
            ) ||
            !isMarch2026OrEarlier(
                previousPeriod
            )
        ) {

            showModal(
                'Old GL Code is only available for March 2026 and earlier. Both Primary and Previous periods must be March 2026 or earlier.'
            );

            return false;
        }

    } else if (glCodeMode === 'new') {

        if (
            !isApril2026OrLater(
                primaryPeriod
            ) ||
            !isApril2026OrLater(
                previousPeriod
            )
        ) {

            showModal(
                'New GL Code is only available for April 2026 onwards. Both Primary and Previous periods must be April 2026 or later.'
            );

            return false;
        }

    } else if (glCodeMode === 'mixed') {

        // Mixed mode is only allowed when the Primary and Previous
        // periods straddle the old/new GL cutoff.
        const primaryIsOld   = isMarch2026OrEarlier(primaryPeriod);
        const primaryIsNew   = isApril2026OrLater(primaryPeriod);
        const previousIsOld  = isMarch2026OrEarlier(previousPeriod);
        const previousIsNew  = isApril2026OrLater(previousPeriod);

        const straddlesCutoff =
            (primaryIsOld && previousIsNew) ||
            (primaryIsNew && previousIsOld);

        if (!straddlesCutoff) {

            showModal(
                'Mix GL Code is only available when the Primary and Previous periods overlap the old/new GL cutoff. One period must be March 2026 or earlier and the other must be April 2026 or later.'
            );

            return false;
        }
    }

    return true;
}

// ============================================================
// COLLAPSE / UNCOLLAPSE
// ============================================================

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const collapseBtn =
            document.getElementById(
                'collapseBtn'
            );

        let isCollapsed = false;

        if (collapseBtn) {
        collapseBtn.addEventListener(
            'click',
            function () {

                isCollapsed =
                    !isCollapsed;

                const tbody =
                    document.querySelector(
                        '.report-tbody'
                    );

                if (tbody) {

                    const rows =
                        Array.from(
                            tbody.rows
                        );

                    // Get the current GL code mode
                    const glCodeMode = document.querySelector('input[name="gl_code_mode"]:checked');
                    const mode = glCodeMode ? glCodeMode.value : 'new';
                    
                    // Set revenue end based on mode
                    // For old: collapse sort_order 1–22, then keep TOTAL REVENUES visible
                    // For new/mixed: collapse up to 24 (includes Cost of Sales/Service)
                    let revenueEnd;
                    let hideDetails;
                    
                    if (mode === 'old') {
                        revenueEnd = 22;  // sort_order 1–22; TOTAL REVENUES stays visible after them
                        hideDetails = [10, 13];
                    } else {
                        // New or Mixed mode
                        revenueEnd = 23;  // sort_order 1–23; TOTAL REVENUES stays visible after them
                        hideDetails = [11, 14];
                    }

                    rows.forEach(
                        row => {

                            const sortOrder =
                                row.getAttribute(
                                    'data-sort-order'
                                );

                            const isDetail =
                                row.getAttribute(
                                    'data-is-detail'
                                ) === 'true';

                            const spacerFor =
                                row.getAttribute(
                                    'data-spacer-for'
                                );

                            const sortNum =
                                parseInt(
                                    sortOrder
                                );

                            // Determine if this is in the collapse range
                            const isInCollapseRange =
                                !isNaN(sortNum) &&
                                sortNum >= 1 &&
                                sortNum <= revenueEnd;

                            // Handle detail rows (sub-rows under each sort order)
                            if (
                                isInCollapseRange &&
                                isDetail
                            ) {
                                row.style.display =
                                    isCollapsed
                                        ? 'none'
                                        : '';
                            }

                            // Handle spacer rows
                            if (spacerFor) {

                                const spacerNum =
                                    parseInt(
                                        spacerFor
                                    );

                                if (
                                    !isNaN(spacerNum) &&
                                    spacerNum >= 1 &&
                                    spacerNum <= revenueEnd
                                ) {

                                    row.style.display =
                                        isCollapsed
                                            ? 'none'
                                            : '';
                                }
                            }

                            // Handle revenue header row
                            if (
                                row.classList.contains(
                                    'revenues-header-row'
                                )
                            ) {
                                row.style.display =
                                    isCollapsed
                                        ? 'none'
                                        : '';
                            }

                            // Handle initial spacer
                            if (
                                row.classList.contains(
                                    'initial-spacer'
                                )
                            ) {
                                row.style.display =
                                    isCollapsed
                                        ? 'none'
                                        : '';
                            }

                            // Handle summary rows in the collapse range
                            if (
                                isInCollapseRange &&
                                !isDetail &&
                                !row.classList.contains('revenues-header-row') &&
                                !row.classList.contains('initial-spacer')
                            ) {
                                // For Cost of Sales/Service row, hide it when collapsed
                                const firstTd = row.querySelector('td');
                                if (firstTd && firstTd.textContent.trim() === 'Cost of Sales/Service') {
                                    row.style.display =
                                        isCollapsed
                                            ? 'none'
                                            : '';
                                } else {
                                    // Keep other summary rows (like TOTAL REVENUES) visible
                                    row.style.display = '';
                                }
                            }

                            // Hide detail rows for specific sort orders that shouldn't be collapsed
                            if (
                                isDetail &&
                                hideDetails.includes(sortNum)
                            ) {
                                if (!isCollapsed) {
                                    row.style.display = '';
                                } else {
                                    row.style.display = 'none';
                                }
                            }
                        }
                    );
                }

                collapseBtn.innerHTML =
                    isCollapsed
                        ? '<i class="fa-solid fa-expand"></i> Uncollapse'
                        : '<i class="fa-solid fa-compress"></i> Collapse';

                collapseBtn.style.backgroundColor =
                    isCollapsed
                        ? '#1f2937'
                        : '#4b5563';
            }
        );
        } // end if (collapseBtn)

        // ============================================================
        // DOUBLE-CLICK EDIT AMOUNTS (detail rows only)
        // ============================================================

        const REPORT_GL_MODE = <?= json_encode($gl_code_mode) ?>;
        const REPORT_RANGES = <?= json_encode(getSortOrderRanges($gl_code_mode)) ?>;
        const REPORT_INJ_SORT_ORDER = <?= json_encode($inj_sort_order) ?>;
        const REPORT_INJ_SECTION_IDS = <?= json_encode($inj_section_ids) ?>;
        const REPORT_COS_SORT_ORDER = <?= json_encode($cos_sort_order) ?>;
        const REPORT_COS_SECTION_IDS = <?= json_encode($cos_section_ids) ?>;
        const HAS_THIRD_PERIOD = <?= json_encode(!empty($third_period)) ?>;

        // Track unsaved client-side edits (no server save yet)
        let hasUnsavedEdits = false;

        function markUnsaved() {
            if (hasUnsavedEdits) return;
            hasUnsavedEdits = true;
            showUnsavedBanner();
        }

        function showUnsavedBanner() {
            if (document.getElementById('unsavedEditsBanner')) return;

            const banner = document.createElement('div');
            banner.id = 'unsavedEditsBanner';
            banner.setAttribute('role', 'status');
            banner.style.cssText =
                'position:fixed;top:0;left:0;right:0;z-index:99999;' +
                'background:#b45309;color:#fff;padding:10px 16px;' +
                'font-family:Inter,system-ui,sans-serif;font-size:14px;' +
                'font-weight:600;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.2);';
            banner.innerHTML =
                '<i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;"></i>' +
                'You have unsaved amount changes. Refreshing or leaving this page will discard them. ';
            document.body.appendChild(banner);
            document.body.style.paddingTop = '44px';
        }

        window.addEventListener('beforeunload', function (e) {
            if (!hasUnsavedEdits) return;
            // Standard browser confirmation for refresh / close / navigate away
            e.preventDefault();
            e.returnValue =
                'You have unsaved amount changes. If you refresh or leave, they will not be saved.';
            return e.returnValue;
        });

        function parseAmount(text) {
            if (text == null) return 0;
            const cleaned = String(text)
                .replace(/,/g, '')
                .replace(/[^\d.\-]/g, '')
                .trim();
            const n = parseFloat(cleaned);
            return isNaN(n) ? 0 : n;
        }

        function formatAmount(n) {
            const num = Number(n) || 0;
            return num.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function formatPct(n) {
            if (n >= 1000 || n <= -1000) return 'mat';
            return (Number(n) || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + '%';
        }

        function setCellAmount(td, value, isStrong) {
            const num = Number(value) || 0;
            td.dataset.value = String(num);
            td.style.color = num < 0 ? 'red' : '';
            const formatted = formatAmount(num);
            td.innerHTML = isStrong ? '<strong>' + formatted + '</strong>' : formatted;
        }

        function setCellVariance(td, value, isPct) {
            const num = Number(value) || 0;
            td.style.color = num < 0 ? 'red' : '';
            td.classList.remove('positive', 'negative');
            if (num > 0) td.classList.add('positive');
            else if (num < 0) td.classList.add('negative');
            td.textContent = isPct ? formatPct(num) : formatAmount(num);
        }

        function calcVariance(primary, previous) {
            const inc = primary - previous;
            let pct = 0;
            if (previous != 0) {
                pct = (inc / Math.abs(previous)) * 100;
            } else if (primary != 0) {
                pct = 100;
            }
            return { inc, pct };
        }

        function getRowAmounts(tr) {
            const cells = tr.querySelectorAll('td');
            const primaryTd = cells[4];
            const previousTd = cells[6];
            const thirdTd = cells[8];
            return {
                cells,
                primaryTd,
                previousTd,
                thirdTd,
                primary: primaryTd
                    ? parseAmount(primaryTd.dataset.value != null
                        ? primaryTd.dataset.value
                        : primaryTd.textContent)
                    : 0,
                previous: previousTd
                    ? parseAmount(previousTd.dataset.value != null
                        ? previousTd.dataset.value
                        : previousTd.textContent)
                    : 0,
                third: thirdTd
                    ? parseAmount(thirdTd.dataset.value != null
                        ? thirdTd.dataset.value
                        : thirdTd.textContent)
                    : 0
            };
        }

        function updateRowVariances(tr) {
            const { cells, primary, previous, third } = getRowAmounts(tr);
            const v1 = calcVariance(primary, previous);
            const v3 = calcVariance(primary, third);
            if (cells[10]) setCellVariance(cells[10], v1.inc, false);
            if (cells[11]) setCellVariance(cells[11], v1.pct, true);
            if (cells[12]) setCellVariance(cells[12], v3.inc, false);
            if (cells[13]) setCellVariance(cells[13], v3.pct, true);
        }

        function findSummaryRowForSortOrder(tbody, sortOrder) {
            const rows = Array.from(tbody.querySelectorAll('tr'));
            for (const row of rows) {
                if (row.classList.contains('summary-row') &&
                    row.getAttribute('data-sort-order') === String(sortOrder)) {
                    const first = (row.querySelector('td') || {}).textContent || '';
                    if (String(sortOrder) === first.trim() || first.trim() === String(sortOrder)) {
                        return row;
                    }
                }
            }
            for (const row of rows) {
                if (row.classList.contains('summary-row') &&
                    row.getAttribute('data-sort-order') === String(sortOrder)) {
                    return row;
                }
            }
            return null;
        }

        function getRowGlId(tr) {
            // Detail rows now carry their gl_id via data-gl-id attribute.
            return tr.getAttribute('data-gl-id') || '';
        }

        function sumDetailsForSortOrder(tbody, sortOrder) {
            let primary = 0, previous = 0, third = 0;
            const rows = Array.from(tbody.querySelectorAll(
                'tr.data-row[data-is-detail="true"][data-sort-order="' + sortOrder + '"]'
            ));
            rows.forEach(function (row) {
                const a = getRowAmounts(row);
                primary += a.primary;
                previous += a.previous;
                third += a.third;
            });
            return { primary, previous, third };
        }

        // ============================================================
        // INJ-SPECIFIC SUM: INJ-3 + INJ-4 + INJ-5 + INJ-6
        // ============================================================
        // The INJ sort order's summary row uses a different rule than
        // other summary rows. INJ-3 is already INJ-1 - INJ-2, so we
        // must NOT double-count INJ-2. The section total is:
        //
        //     INJ-3 + INJ-4 + INJ-5 + INJ-6
        //
        // In old GL mode (and historical Period 3), INJ-4 to INJ-6
        // will be absent; the total then correctly reduces to INJ-3.
        //
        // NOTE: This function relies on data-gl-id being present on
        // the detail <tr> elements. That attribute is emitted by PHP.
        // ============================================================
        function sumDetailsForInjSortOrder(tbody, sortOrder) {
            let primary = 0, previous = 0, third = 0;
            const rows = Array.from(tbody.querySelectorAll(
                'tr.data-row[data-is-detail="true"][data-sort-order="' + sortOrder + '"]'
            ));
            rows.forEach(function (row) {
                const glId = row.getAttribute('data-gl-id') || '';
                if (REPORT_INJ_SECTION_IDS.indexOf(glId) !== -1) {
                    const a = getRowAmounts(row);
                    primary += a.primary;
                    previous += a.previous;
                    third += a.third;
                }
            });
            return { primary, previous, third };
        }

        // ============================================================
        // COS-SPECIFIC SUM: COS-4 + COS-5 + COS-6 + COS-7 + COS-8 + COS-9
        // ============================================================
        // The Cost of Sales/Service sort order's summary row uses a
        // different rule than other summary rows. COS-4 is already
        // COS-1 + COS-2 + COS-3, so those three lines must NOT be
        // added again. The section total is:
        //
        //     COS-4 + COS-5 + COS-6 + COS-7 + COS-8 + COS-9
        //
        // NOTE: This function relies on data-gl-id being present on
        // the detail <tr> elements. That attribute is emitted by PHP.
        // ============================================================
        function sumDetailsForCosSortOrder(tbody, sortOrder) {
            let primary = 0, previous = 0, third = 0;
            const rows = Array.from(tbody.querySelectorAll(
                'tr.data-row[data-is-detail="true"][data-sort-order="' + sortOrder + '"]'
            ));
            rows.forEach(function (row) {
                const glId = row.getAttribute('data-gl-id') || '';
                if (REPORT_COS_SECTION_IDS.indexOf(glId) !== -1) {
                    const a = getRowAmounts(row);
                    primary += a.primary;
                    previous += a.previous;
                    third += a.third;
                }
            });
            return { primary, previous, third };
        }

        function updateSummaryRow(tr, amounts) {
            if (!tr) return;
            const { cells } = getRowAmounts(tr);
            if (cells[4]) setCellAmount(cells[4], amounts.primary, true);
            if (cells[6]) setCellAmount(cells[6], amounts.previous, true);
            if (cells[8]) setCellAmount(cells[8], amounts.third, true);
            updateRowVariances(tr);
        }

        function findNamedSummaryRow(tbody, nameContains) {
            const rows = Array.from(tbody.querySelectorAll('tr.summary-row'));
            const needle = nameContains.toUpperCase();
            for (const row of rows) {
                const tds = row.querySelectorAll('td');
                for (let i = 0; i < Math.min(3, tds.length); i++) {
                    const txt = (tds[i].textContent || '').trim().toUpperCase();
                    if (txt.indexOf(needle) !== -1) {
                        return row;
                    }
                }
            }
            return null;
        }

        function getSummaryAmountBySortOrder(tbody, sortOrder) {
            const sumRow = findSummaryRowForSortOrder(tbody, sortOrder);
            if (!sumRow) {
                // Fall back to detail sum, applying special rules when needed
                if (parseInt(sortOrder) === parseInt(REPORT_INJ_SORT_ORDER)) {
                    return sumDetailsForInjSortOrder(tbody, sortOrder);
                }
                if (parseInt(sortOrder) === parseInt(REPORT_COS_SORT_ORDER)) {
                    return sumDetailsForCosSortOrder(tbody, sortOrder);
                }
                return sumDetailsForSortOrder(tbody, sortOrder);
            }
            return {
                primary: getRowAmounts(sumRow).primary,
                previous: getRowAmounts(sumRow).previous,
                third: getRowAmounts(sumRow).third
            };
        }

        function recalculateHigherTotals(tbody) {
            const ranges = REPORT_RANGES;
            const revStart = ranges.revenue_start;
            const revEnd = ranges.revenue_end;
            const cos = ranges.cost_of_sales;
            const saStart = ranges.sa_start;
            const saEnd = ranges.sa_end;
            const depreciation = ranges.depreciation;
            const interest = ranges.interest;
            const tax = ranges.tax;

            let rev = { primary: 0, previous: 0, third: 0 };
            for (let so = revStart; so <= revEnd; so++) {
                const a = getSummaryAmountBySortOrder(tbody, so);
                rev.primary += a.primary;
                rev.previous += a.previous;
                rev.third += a.third;
            }

            const totalRevRow = findNamedSummaryRow(tbody, 'TOTAL REVENUES');
            if (totalRevRow) updateSummaryRow(totalRevRow, rev);

            const cosAmt = getSummaryAmountBySortOrder(tbody, cos);

            const gp = {
                primary: rev.primary - cosAmt.primary,
                previous: rev.previous - cosAmt.previous,
                third: rev.third - cosAmt.third
            };
            const gpRow = findNamedSummaryRow(tbody, 'GROSS PROFIT');
            if (gpRow) updateSummaryRow(gpRow, gp);

            let sa = { primary: 0, previous: 0, third: 0 };
            for (let so = saStart; so <= saEnd; so++) {
                const a = getSummaryAmountBySortOrder(tbody, so);
                sa.primary += a.primary;
                sa.previous += a.previous;
                sa.third += a.third;
            }
            // Only update the TOTAL row — not the section header
            // "SELLING & ADMIN EXPENSE", which must stay blank.
            const saRow = findNamedSummaryRow(tbody, 'TOTAL SELLING AND ADMIN');
            if (saRow) {
                updateSummaryRow(saRow, sa);
            } else {
                // Fallback for shorter label variants
                const saRowFallback = findNamedSummaryRow(tbody, 'TOTAL SELLING');
                if (saRowFallback) updateSummaryRow(saRowFallback, sa);
            }

            const ebitda = {
                primary: gp.primary - sa.primary,
                previous: gp.previous - sa.previous,
                third: gp.third - sa.third
            };
            const ebitdaRow = findNamedSummaryRow(tbody, 'EARNINGS BEFORE INTEREST, TAXES, DEP');
            if (ebitdaRow) updateSummaryRow(ebitdaRow, ebitda);

            const depAmt = getSummaryAmountBySortOrder(tbody, depreciation);

            const ebit = {
                primary: ebitda.primary - depAmt.primary,
                previous: ebitda.previous - depAmt.previous,
                third: ebitda.third - depAmt.third
            };
            const ebitRow = findNamedSummaryRow(tbody, 'EARNINGS BEFORE INTEREST & TAXES');
            if (ebitRow) updateSummaryRow(ebitRow, ebit);

            const intAmt = getSummaryAmountBySortOrder(tbody, interest);

            const ebt = {
                primary: ebit.primary - intAmt.primary,
                previous: ebit.previous - intAmt.previous,
                third: ebit.third - intAmt.third
            };
            const ebtRow = findNamedSummaryRow(tbody, 'EARNINGS BEFORE TAXES');
            if (ebtRow) updateSummaryRow(ebtRow, ebt);

            const taxAmt = getSummaryAmountBySortOrder(tbody, tax);

            const net = {
                primary: ebt.primary - taxAmt.primary,
                previous: ebt.previous - taxAmt.previous,
                third: ebt.third - taxAmt.third
            };
            const netRow = findNamedSummaryRow(tbody, 'TOTAL NET INCOME');
            if (netRow) updateSummaryRow(netRow, net);
        }

        // ============================================================
        // COS-4 AUTO-CALC: COS-4 = COS-1 + COS-2 + COS-3
        // ============================================================
        // When any of COS-1 / COS-2 / COS-3 is edited, COS-4 is
        // recalculated for the same period column(s) so it always
        // reflects the sum of the three component lines.
        // ============================================================
        function findDetailRowByGlId(tbody, sortOrder, glId) {
            return tbody.querySelector(
                'tr.data-row[data-is-detail="true"][data-sort-order="' +
                sortOrder + '"][data-gl-id="' + glId + '"]'
            );
        }

        function recalcCos4FromComponents(tbody, sortOrder) {
            if (parseInt(sortOrder) !== parseInt(REPORT_COS_SORT_ORDER)) {
                return;
            }

            const cos1 = findDetailRowByGlId(tbody, sortOrder, 'COS-1');
            const cos2 = findDetailRowByGlId(tbody, sortOrder, 'COS-2');
            const cos3 = findDetailRowByGlId(tbody, sortOrder, 'COS-3');
            const cos4 = findDetailRowByGlId(tbody, sortOrder, 'COS-4');

            if (!cos4) return;

            const a1 = cos1 ? getRowAmounts(cos1) : { primary: 0, previous: 0, third: 0 };
            const a2 = cos2 ? getRowAmounts(cos2) : { primary: 0, previous: 0, third: 0 };
            const a3 = cos3 ? getRowAmounts(cos3) : { primary: 0, previous: 0, third: 0 };

            const sum = {
                primary:  a1.primary  + a2.primary  + a3.primary,
                previous: a1.previous + a2.previous + a3.previous,
                third:    a1.third    + a2.third    + a3.third
            };

            const { cells } = getRowAmounts(cos4);
            if (cells[4]) setCellAmount(cells[4], sum.primary, false);
            if (cells[6]) setCellAmount(cells[6], sum.previous, false);
            if (cells[8]) setCellAmount(cells[8], sum.third, false);
            updateRowVariances(cos4);
        }

        function onAmountCommitted(td) {
            const tr = td.closest('tr');
            if (!tr || !tr.classList.contains('data-row')) return;

            updateRowVariances(tr);

            const sortOrder = tr.getAttribute('data-sort-order');
            const tbody = tr.closest('tbody');
            if (!tbody || !sortOrder) return;

            // If COS-1 / COS-2 / COS-3 was edited, auto-update COS-4
            const editedGlId = tr.getAttribute('data-gl-id') || '';
            if (
                editedGlId === 'COS-1' ||
                editedGlId === 'COS-2' ||
                editedGlId === 'COS-3'
            ) {
                recalcCos4FromComponents(tbody, sortOrder);
            }

            // Special section-total rules:
            // INJ: INJ-3 + INJ-4 + INJ-5 + INJ-6
            // COS: COS-4 + COS-5 + COS-6 + COS-7 + COS-8 + COS-9
            let sectionTotals;
            if (parseInt(sortOrder) === parseInt(REPORT_INJ_SORT_ORDER)) {
                sectionTotals = sumDetailsForInjSortOrder(tbody, sortOrder);
            } else if (parseInt(sortOrder) === parseInt(REPORT_COS_SORT_ORDER)) {
                sectionTotals = sumDetailsForCosSortOrder(tbody, sortOrder);
            } else {
                sectionTotals = sumDetailsForSortOrder(tbody, sortOrder);
            }

            const summaryRow = findSummaryRowForSortOrder(tbody, sortOrder);
            if (summaryRow) {
                updateSummaryRow(summaryRow, sectionTotals);
            }

            recalculateHigherTotals(tbody);
            markUnsaved();
        }

        function startEdit(td) {
            if (td.querySelector('input.amount-edit-input')) return;

            const current = parseAmount(
                td.dataset.value != null ? td.dataset.value : td.textContent
            );

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'amount-edit-input';
            input.value = current.toFixed(2);
            input.style.cssText =
                'width:100%;box-sizing:border-box;text-align:right;' +
                'font:inherit;padding:2px 4px;border:2px solid #2563eb;' +
                'border-radius:3px;outline:none;background:#fff;color:#111;';

            const originalHtml = td.innerHTML;
            td.innerHTML = '';
            td.appendChild(input);
            input.focus();
            input.select();

            let committed = false;

            function commit() {
                if (committed) return;
                committed = true;

                let raw = input.value.trim().replace(/,/g, '');
                if (raw === '' || raw === '-' || raw === '.') {
                    raw = '0';
                }
                const num = parseFloat(raw);
                if (isNaN(num)) {
                    td.innerHTML = originalHtml;
                    return;
                }

                setCellAmount(td, num, false);
                onAmountCommitted(td);
            }

            function cancel() {
                if (committed) return;
                committed = true;
                td.innerHTML = originalHtml;
            }

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    commit();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancel();
                }
            });

            input.addEventListener('blur', function () {
                commit();
            });
        }

        const reportTbody = document.querySelector('.report-tbody');
        if (reportTbody) {
            reportTbody.addEventListener('dblclick', function (e) {
                const td = e.target.closest('td[data-editable="true"]');
                if (!td) return;
                const tr = td.closest('tr');
                if (!tr || tr.classList.contains('summary-row')) return;
                e.preventDefault();
                startEdit(td);
            });

            const style = document.createElement('style');
            style.textContent =
                'td.editable-amount { cursor: pointer; }' +
                'td.editable-amount:hover { background-color: #ffd6d6; outline: 1px dashed #f50b0b; }' +
                'td.editable-amount input.amount-edit-input { cursor: text; }';
            document.head.appendChild(style);
        }

        // ============================================================
        // SAVE TO comparative_report_with_ho
        // ============================================================
        const REPORT_PRIMARY_PERIOD  = <?= json_encode($primary_period) ?>;
        const REPORT_PREVIOUS_PERIOD = <?= json_encode($previous_period) ?>;
        const REPORT_THIRD_PERIOD    = <?= json_encode($third_period) ?>;
        const REPORT_USERNAME        = <?= json_encode($username) ?>;

        function collectDetailRowsForSave(tbody) {
            const rows = [];
            const detailRows = tbody.querySelectorAll(
                'tr.data-row[data-is-detail="true"][data-gl-id]'
            );
            detailRows.forEach(function (tr) {
                const glId = (tr.getAttribute('data-gl-id') || '').trim();
                if (!glId) return;

                const amounts = getRowAmounts(tr);
                rows.push({
                    gl_id: glId,
                    sort_order: parseInt(tr.getAttribute('data-sort-order') || '0', 10) || 0,
                    sub_order: parseInt(tr.getAttribute('data-sub-order') || '0', 10) || 0,
                    description: tr.getAttribute('data-description') || '',
                    gl_description_comparative: tr.getAttribute('data-gl-description') || '',
                    primary_period_amount: amounts.primary,
                    previous_period_amount: amounts.previous,
                    third_period_amount: amounts.third
                });
            });
            return rows;
        }

        function showSaveToast(message, isError) {
            let toast = document.getElementById('saveReportToast');
            if (toast) toast.remove();

            toast = document.createElement('div');
            toast.id = 'saveReportToast';
            toast.setAttribute('role', 'status');
            toast.style.cssText =
                'position:fixed;bottom:24px;right:24px;z-index:99999;' +
                'max-width:420px;padding:14px 18px;border-radius:8px;' +
                'font-family:Inter,system-ui,sans-serif;font-size:14px;font-weight:600;' +
                'box-shadow:0 4px 16px rgba(0,0,0,.2);color:#fff;' +
                (isError ? 'background:#b91c1c;' : 'background:#059669;');
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(function () {
                if (toast && toast.parentNode) toast.remove();
            }, 5000);
        }

        const saveBtn = document.getElementById('saveReportBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                const tbody = document.querySelector('.report-tbody');
                if (!tbody) {
                    showSaveToast('No report data to save.', true);
                    return;
                }
                if (!REPORT_PRIMARY_PERIOD || !REPORT_PREVIOUS_PERIOD) {
                    showSaveToast('Primary and Previous periods are required before saving.', true);
                    return;
                }

                const rows = collectDetailRowsForSave(tbody);
                if (rows.length === 0) {
                    showSaveToast('No detail rows found to save.', true);
                    return;
                }

                const payload = {
                    primary_period: REPORT_PRIMARY_PERIOD,
                    previous_period: REPORT_PREVIOUS_PERIOD,
                    third_period: REPORT_THIRD_PERIOD || '',
                    gl_code_logic: REPORT_GL_MODE,
                    rows: rows
                };

                saveBtn.disabled = true;
                saveBtn.innerHTML =
                    '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

                fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(
                        Object.assign({ action: 'save_comparative' }, payload)
                    )
                })
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (result.data && result.data.success) {
                        hasUnsavedEdits = false;
                        const banner = document.getElementById('unsavedEditsBanner');
                        if (banner) {
                            banner.remove();
                            document.body.style.paddingTop = '';
                        }
                        showSaveToast(
                            result.data.message ||
                            ('Saved ' + (result.data.inserted || 0) + ' row(s).'),
                            false
                        );
                    } else {
                        showSaveToast(
                            (result.data && result.data.message) ||
                            'Save failed.',
                            true
                        );
                    }
                })
                .catch(function (err) {
                    showSaveToast('Save failed: ' + (err && err.message ? err.message : 'network error'), true);
                })
                .finally(function () {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML =
                        '<i class="fa-solid fa-floppy-disk"></i> Save';
                });
            });
        }

        // Warn when using Filter / Clear while there are unsaved edits
        function confirmDiscardIfDirty() {
            if (!hasUnsavedEdits) return true;
            return window.confirm(
                'You have unsaved amount changes. Filtering or clearing will discard them and reload the page.\n\n' +
                'Continue without saving?'
            );
        }

        const filterForm = document.getElementById('filterForm');
        if (filterForm) {
            filterForm.addEventListener('submit', function (e) {
                if (!confirmDiscardIfDirty()) {
                    e.preventDefault();
                    return false;
                }
                // Allow navigation — clear flag so beforeunload does not also fire
                hasUnsavedEdits = false;
            });
        }

        document.querySelectorAll('a.btn-reset, a[href*="reset=1"]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (!confirmDiscardIfDirty()) {
                    e.preventDefault();
                    return false;
                }
                hasUnsavedEdits = false;
            });
        });
    }
);

</script>

<?php include '../footer.php'; ?>

</body>

</html>

<?php

$conn->close();

?>