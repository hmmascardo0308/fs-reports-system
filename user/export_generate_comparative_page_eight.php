<?php
// export_generate_comparative_page_eight.php
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

$primary_period  = $_GET['primary_period']   ?? '';
$previous_period = $_GET['previous_period']  ?? '';
$third_period    = $_GET['third_period']     ?? '';
$gl_code_mode    = $_GET['gl_code_mode']     ?? 'old';

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

// From August 2026, ML Properties ( Realty & Brokerage ) is shown as its
// own group (sort_order 30 / MLP-1) instead of inside Total Other Income
// (sort_order 23 / TOI-32).
function isAugust2026OrLater(string $month): bool
{
    if (empty($month)) {
        return false;
    }
    $cutoff = strtotime('2026-08-01');
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
        } elseif ($gl_code_mode === 'mixed') {
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

// ============================================================
// ML PROPERTIES GROUP FLAG
// ============================================================
// When the Primary OR Previous period is August 2026 or later (New / Mix GL):
//   - ML Properties is its own group: MLP-1 / sort_order 30, shown right
//     after sort_order 7 and counted in TOTAL REVENUES.
//   - TOI-32 is hidden from Total Other Income (sort_order 23) and any
//     TOI-32 amount in the snapshot is folded into MLP-1.
// Otherwise ML Properties stays inside Total Other Income and the
// sort_order 30 group is never shown.
// ============================================================

$ml_properties_group_active = (
    $gl_code_mode !== 'old' &&
    (
        isAugust2026OrLater($primary_period) ||
        isAugust2026OrLater($previous_period)
    )
);

// ── Fetch saved snapshot from fs_reports.comparative_report_with_ho ──────────

function toDateParam(string $ym): ?string
{
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
}

$table_rows = [];
$sort_order_descriptions = [];

if ($valid_filters) {
    $primary_date  = toDateParam($primary_period);
    $previous_date = toDateParam($previous_period);
    $third_date    = toDateParam($third_period);

    if ($primary_date !== null && $previous_date !== null) {
        $del_third = $third_date !== null ? $third_date : '';

        $sql = "
            SELECT
                gl_id,
                sort_order,
                description,
                sub_order,
                gl_description_comparative,
                primary_period_amount,
                previous_period_amount,
                third_period_amount
            FROM fs_reports.comparative_report_with_ho
            WHERE primary_period = ?
              AND previous_period = ?
              AND gl_code_logic = ?
              AND (
                    (? = '' AND third_period IS NULL)
                 OR third_period = NULLIF(?, '')
              )
            ORDER BY sort_order ASC, sub_order ASC
        ";

        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sssss',
                $primary_date,
                $previous_date,
                $gl_code_mode,
                $del_third,
                $del_third
            );

            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($result) {
                while ($r = mysqli_fetch_assoc($result)) {
                    $gl_id = trim((string)($r['gl_id'] ?? ''));
                    if ($gl_id === '') {
                        continue;
                    }

                    $sort_order = (int)($r['sort_order'] ?? 0);
                    $sub_order  = (int)($r['sub_order'] ?? 0);

                    $gl_description = trim((string)(
                        $r['gl_description_comparative'] ?? ''
                    ));

                    if (
                        !isset($sort_order_descriptions[$sort_order]) &&
                        !empty($r['description'])
                    ) {
                        $sort_order_descriptions[$sort_order] =
                            $r['description'];
                    }

                    $table_rows[] = [
                        'sort_order' => $sort_order,
                        'sub_order' => $sub_order,
                        'gl_id' => $gl_id,
                        'gl_description' => $gl_description,
                        'is_section_header' => false,
                        'is_summary_row' => false,
                        'primary_total' => (float)($r['primary_period_amount'] ?? 0),
                        'previous_total' => (float)($r['previous_period_amount'] ?? 0),
                        'third_total' => (float)($r['third_period_amount'] ?? 0),
                        'is_inj2' => ($gl_id === 'INJ-2'),
                    ];
                }
            }

            mysqli_stmt_close($stmt);
        }
    }
}

// ── ML Properties ( Realty & Brokerage ) on the saved snapshot ───────────────

if ($ml_properties_group_active) {

    $toi32_p = 0.0;
    $toi32_prev = 0.0;
    $toi32_third = 0.0;
    $has_toi32 = false;
    $kept_rows = [];

    foreach ($table_rows as $r) {
        if (($r['gl_id'] ?? '') === 'TOI-32') {
            $toi32_p += (float)$r['primary_total'];
            $toi32_prev += (float)$r['previous_total'];
            $toi32_third += (float)$r['third_total'];
            $has_toi32 = true;
            continue;
        }
        $kept_rows[] = $r;
    }

    $table_rows = $kept_rows;

    if ($has_toi32) {

        $mlp_index = null;

        foreach ($table_rows as $i => $r) {
            if (($r['gl_id'] ?? '') === 'MLP-1') {
                $mlp_index = $i;
                break;
            }
        }

        if ($mlp_index === null) {
            $table_rows[] = [
                'sort_order' => 30,
                'sub_order' => 1,
                'gl_id' => 'MLP-1',
                'gl_description' => 'ML Properties ( Realty & Brokerage )',
                'is_section_header' => false,
                'is_summary_row' => false,
                'primary_total' => $toi32_p,
                'previous_total' => $toi32_prev,
                'third_total' => $toi32_third,
                'is_inj2' => false,
            ];

            if (!isset($sort_order_descriptions[30])) {
                $sort_order_descriptions[30] = 'ML Properties';
            }
        } else {
            $table_rows[$mlp_index]['primary_total'] += $toi32_p;
            $table_rows[$mlp_index]['previous_total'] += $toi32_prev;
            $table_rows[$mlp_index]['third_total'] += $toi32_third;
        }
    }

} else {

    // Not active: never show the ML Properties group.
    $table_rows = array_values(array_filter(
        $table_rows,
        static function ($r) {
            return !(
                ($r['gl_id'] ?? '') === 'MLP-1' ||
                (int)($r['sort_order'] ?? 0) === 30
            );
        }
    ));
}

// ── Group, summarize, and emit section/summary rows ─────────────────────────

$final_table_rows = [];

if (!empty($table_rows)) {
    $grouped_rows = [];
    foreach ($table_rows as $row) {
        $sort_order = $row['sort_order'];
        if (!isset($grouped_rows[$sort_order])) {
            $grouped_rows[$sort_order] = [];
        }
        $grouped_rows[$sort_order][] = $row;
    }

    // ML Properties group (sort_order 30) only exists when it is active.
    if (!$ml_properties_group_active && isset($grouped_rows[30])) {
        unset($grouped_rows[30]);
    }

    // ML PROPERTIES: place sort_order 30 right after sort_order 7
    // (it is the last number in the structure, so by default it would
    // be rendered at the very bottom).
    if ($ml_properties_group_active && isset($grouped_rows[30])) {
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

    $rev_tot_p = 0; $rev_tot_prev = 0; $rev_tot_third = 0;
    $sa_tot_p = 0;  $sa_tot_prev = 0;  $sa_tot_third = 0;
    $gp_tot_p = 0;  $gp_tot_prev = 0;  $gp_tot_third = 0;
    $ebitda_tot_p = 0; $ebitda_tot_prev = 0; $ebitda_tot_third = 0;
    $ebit_tot_p = 0;   $ebit_tot_prev = 0;   $ebit_tot_third = 0;
    $ebt_tot_p = 0;    $ebt_tot_prev = 0;    $ebt_tot_third = 0;
    $net_tot_p = 0;    $net_tot_prev = 0;    $net_tot_third = 0;

    $ranges = getSortOrderRanges($gl_code_mode);

    $inj_section_ids = ['INJ-3', 'INJ-4', 'INJ-5', 'INJ-6'];
    $cos_sort_order_local = ($gl_code_mode === 'old') ? 23 : 24;
    $cos_section_ids = ['COS-4', 'COS-5', 'COS-6', 'COS-7', 'COS-8', 'COS-9'];

    foreach ($grouped_rows as $sort_order => $rows) {
        $hide_details = ($gl_code_mode === 'old') ? [10, 13] : [11, 14];

        if (!in_array((int)$sort_order, $hide_details, true)) {
            foreach ($rows as $row) {
                $final_table_rows[] = $row;
            }
        }

        // Section totals for INJ / COS
        if ((int)$sort_order === $inj_sort_order) {
            $total_primary_total  = 0.0;
            $total_previous_total = 0.0;
            $total_third_total    = 0.0;

            foreach ($rows as $row) {
                if (in_array(($row['gl_id'] ?? ''), $inj_section_ids, true)) {
                    $total_primary_total  += (float)($row['primary_total']  ?? 0);
                    $total_previous_total += (float)($row['previous_total'] ?? 0);
                    $total_third_total    += (float)($row['third_total']    ?? 0);
                }
            }
        } elseif ((int)$sort_order === $cos_sort_order_local) {
            $total_primary_total  = 0.0;
            $total_previous_total = 0.0;
            $total_third_total    = 0.0;

            foreach ($rows as $row) {
                if (in_array(($row['gl_id'] ?? ''), $cos_section_ids, true)) {
                    $total_primary_total  += (float)($row['primary_total']  ?? 0);
                    $total_previous_total += (float)($row['previous_total'] ?? 0);
                    $total_third_total    += (float)($row['third_total']    ?? 0);
                }
            }
        } else {
            $total_primary_total  = array_sum(array_column($rows, 'primary_total'));
            $total_previous_total = array_sum(array_column($rows, 'previous_total'));
            $total_third_total    = array_sum(array_column($rows, 'third_total'));
        }

        // Revenue accumulation (ML Properties, sort_order 30, is a revenue
        // group when it is shown on its own)
        $is_revenue_group =
            (
                (int)$sort_order >= $ranges['revenue_start'] &&
                (int)$sort_order <= $ranges['revenue_end']
            ) ||
            (
                $ml_properties_group_active &&
                (int)$sort_order === 30
            );

        if ($is_revenue_group) {
            $rev_tot_p     += $total_primary_total;
            $rev_tot_prev  += $total_previous_total;
            $rev_tot_third += $total_third_total;
        }

        // S&A accumulation
        if (
            (int)$sort_order === $ranges['sa_start'] ||
            (int)$sort_order === $ranges['sa_end']
        ) {
            $sa_tot_p     += $total_primary_total;
            $sa_tot_prev  += $total_previous_total;
            $sa_tot_third += $total_third_total;
        }

        $inc_dec = $total_primary_total - $total_previous_total;
        $percentage = ($total_previous_total != 0)
            ? ($inc_dec / abs($total_previous_total)) * 100
            : ($total_primary_total != 0 ? 100 : 0);

        $inc_dec_third = $total_primary_total - $total_third_total;
        $percentage_third = (!empty($third_period) && $total_third_total != 0)
            ? ($inc_dec_third / abs($total_third_total)) * 100
            : ((!empty($third_period) && $total_primary_total != 0) ? 100 : 0);

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
                'percentage_third' => $percentage_third,
            ];
        }

        // Spacer after final S&A sort order
        $sa_end = $ranges['sa_end'];
        if ((int)$sort_order === $sa_end) {
            $final_table_rows[] = ['is_manual_spacer' => true];
        }

        // TOTAL REVENUES
        $revenue_end = $ranges['revenue_end'];
        if ((int)$sort_order === $revenue_end) {
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

            $final_table_rows[] = ['is_manual_spacer' => true];

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
            $gp_tot_p     = $rev_tot_p     - $total_primary_total;
            $gp_tot_prev  = $rev_tot_prev  - $total_previous_total;
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

            $final_table_rows[] = ['is_manual_spacer' => true];

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

            $final_table_rows[] = ['is_manual_spacer' => true];

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

        // Spacer before TOTAL SELLING AND ADMIN EXPENSES
        $sa_end = $ranges['sa_end'];
        if ((int)$sort_order === ($sa_end - 1)) {
            $final_table_rows[] = ['is_manual_spacer' => true];
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

            $final_table_rows[] = ['is_manual_spacer' => true];

            $ebitda_tot_p     = $gp_tot_p     - $sa_tot_p;
            $ebitda_tot_prev  = $gp_tot_prev  - $sa_tot_prev;
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
            $ebit_tot_p     = $ebitda_tot_p     - $total_primary_total;
            $ebit_tot_prev  = $ebitda_tot_prev  - $total_previous_total;
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

            $final_table_rows[] = ['is_manual_spacer' => true];

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
            $ebt_tot_p     = $ebit_tot_p     - $total_primary_total;
            $ebt_tot_prev  = $ebit_tot_prev  - $total_previous_total;
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

            $final_table_rows[] = ['is_manual_spacer' => true];

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
            $net_tot_p     = $ebt_tot_p     - $total_primary_total;
            $net_tot_prev  = $ebt_tot_prev  - $total_previous_total;
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

            $final_table_rows[] = ['is_manual_spacer' => true];

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
}

// ── Build spreadsheet ─────────────────────────────────────────────────────────

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Head Office Comparative');
$sheet->freezePane('A12');

// Column widths
$widths = [2, 3, 50, 1, 20, 20, 20, 2, 20, 15, 20, 15, 1, 1, 1];
foreach ($widths as $idx => $w) {
    $sheet->getColumnDimension(colLetter($idx + 1))->setWidth($w);
}

$row = 1;

// ── Row 1: Logo ──────────────────────────────────────────────────────────────
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
$row++; // 2

// ── Row 2: NATIONWIDE (MLFSI & JEWELERS) - DETAILED ──────────────────────────
$sheet->setCellValue("A$row", 'NATIONWIDE (MLFSI & JEWELERS) - DETAILED');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++; // 3

// ── Row 3: COMPARATIVE PROFIT & LOSS STATEMENT ───────────────────────────────
$sheet->setCellValue("A$row", 'COMPARATIVE PROFIT & LOSS STATEMENT');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++; // 4

// ── Row 4: Period line ───────────────────────────────────────────────────────
$p_disp = !empty($primary_period) ? date('F Y', strtotime($primary_period . '-01')) : '(Primary)';
$prev_disp = !empty($previous_period) ? date('F Y', strtotime($previous_period . '-01')) : '(Previous)';
$third_disp = !empty($third_period) ? date('F Y', strtotime($third_period . '-01')) : '(Period 3)';
$period_line = strtoupper($p_disp . ' VS ' . $prev_disp . ' VS ' . $third_disp);

$sheet->setCellValue("A$row", $period_line);
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++; // 5

// ── Rows 5-7: empty spacer rows ──────────────────────────────────────────────
$row += 3; // 5 -> 8

// ── Row 8: INCREASE/DECREASE header with red "DECREASE" ──────────────────────
$richText = new RichText();
$rt1 = $richText->createTextRun('INCREASE/');
$rt1->getFont()->setBold(true)->setColor(new Color(Color::COLOR_BLACK));
$rt2 = $richText->createTextRun('DECREASE');
$rt2->getFont()->setBold(true)->setColor(new Color(Color::COLOR_RED));

$sheet->setCellValue("I$row", $richText);
$sheet->mergeCells("I$row:L$row");
$sheet->getStyle("I$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("I$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFDBAC');
$row++; // 9

// ── Row 9: Column sub-headers ────────────────────────────────────────────────
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
$row++; // 10

// ── Row 10: empty spacer ─────────────────────────────────────────────────────
$row++; // 11

// ── Row 11: REVENUES section header ──────────────────────────────────────────
$sheet->setCellValue("A$row", 'REVENUES');
$sheet->mergeCells("A$row:N$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$sheet->getStyle("A$row:N$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFF7F29');
$row++; // 12 — first data row

// ── Write data rows ─────────────────────────────────────────────────────────

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

// A sort_order belongs to the (collapsible) revenue area if it is
// 1..revenue_end, OR it is sort_order 30 (ML Properties) when that
// group is shown on its own.
$is_revenue_sort_order = static function ($sort_order) use (
    $revenue_end_for_outline,
    $ml_properties_group_active
): bool {
    if (!is_numeric($sort_order)) {
        return false;
    }
    $n = (int)$sort_order;
    return ($n >= 1 && $n <= $revenue_end_for_outline)
        || ($ml_properties_group_active && $n === 30);
};

// Alternate shading follows the DISPLAY position of each revenue group
// (not the sort_order number), so ML Properties moved below sort_order 7
// does not break the pattern. Without ML Properties this is identical to
// the old odd/even sort_order behaviour.
$revenue_group_position = 0;

if (empty($final_table_rows)) {
    // No saved data message
    $sheet->setCellValue("A$row", 'No saved adjustments for the selected periods and GL mode. Save an adjustment from the Original HO Allocate page first.');
    $sheet->mergeCells("A$row:N$row");
    $sheet->getStyle("A$row")->getFont()->setItalic(true);
    $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;
}

foreach ($final_table_rows as $item) {
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
        $is_revenue_summary = $is_revenue_sort_order($label);

        if ($is_revenue_summary) {
            $revenue_group_position++;
        }

        // Col A:
        // - Revenue sort orders are blank as before.
        // - Hide specific sort-order numbers requested for each GL logic.
        $hidden_sort_orders = ($gl_code_mode === 'old') ? [23, 24, 25, 34, 35] : [24, 25, 26];
        $display_label = (
            is_numeric($label) &&
            (
                $is_revenue_summary ||
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
        } elseif ($is_revenue_summary && $revenue_group_position % 2 != 0) {
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
        if ($is_revenue_summary) {
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
        if ($is_revenue_sort_order($item['sort_order'] ?? null)) {
            $sheet->getRowDimension($row)->setOutlineLevel(1)->setVisible(false);
        }
    }

    $row++;
}

// ── Output ────────────────────────────────────────────────────────────────────

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Comparative_Report_Saved_HO_Allocate_' . date('Y-m-d') . '.xlsx"');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$conn->close();
exit;