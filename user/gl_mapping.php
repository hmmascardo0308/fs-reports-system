<?php
// gl_mapping.php
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

$username  = $_SESSION['username'] ?? 'unknown';
$full_name = $_SESSION['full_name'] ?? 'unknown';
$user_type = $_SESSION['user_type'] ?? 'unknown';

// Whitelist of allowed tables to prevent SQL injection.
// 'has_mode' = whether the table has a mode column.
// 'note'     = static reference note for the table (used when has_mode = false).
$allowedTables = [
    'page_three'              => ['label' => 'P&L Comp 3',           'has_mode' => true],
    'page_four'               => ['label' => 'WHO 4',                'has_mode' => true],
    'page_five'               => ['label' => 'WHO Pryr 5',           'has_mode' => true],
    'comparative_raw_gl_codes'=> ['label' => 'Comparative Raw GL',   'has_mode' => false],
];

// Determine active table
$activeTable = $_GET['table'] ?? 'page_three';
if (!array_key_exists($activeTable, $allowedTables)) {
    $activeTable = 'page_three';
}
$hasMode = $allowedTables[$activeTable]['has_mode'];

// Handle delete
if (isset($_GET['delete']) && ctype_digit((string)$_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM `$activeTable` WHERE id = ?");
    $stmt->bind_param('i', $delId);
    $stmt->execute();
    $stmt->close();
    header("Location: gl_mapping.php?table=" . urlencode($activeTable));
    exit;
}

// Handle add / update
$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $source_gl_id        = trim($_POST['source_gl_id'] ?? '');
    $corresponding_gl_id = trim($_POST['corresponding_gl_id'] ?? '');
    $editId              = $_POST['edit_id'] ?? '';
    $mode                = $hasMode ? trim($_POST['mode'] ?? '') : '';

    $missing = ($source_gl_id === '' || $corresponding_gl_id === '');
    if ($hasMode && $mode === '') {
        $missing = true;
    }

    if ($missing) {
        $formError = 'All fields are required.';
    } else {
        if ($editId !== '' && ctype_digit((string)$editId)) {
            if ($hasMode) {
                $stmt = $conn->prepare("UPDATE `$activeTable` SET mode = ?, source_gl_id = ?, corresponding_gl_id = ? WHERE id = ?");
                $stmt->bind_param('sssi', $mode, $source_gl_id, $corresponding_gl_id, $editId);
            } else {
                $stmt = $conn->prepare("UPDATE `$activeTable` SET source_gl_id = ?, corresponding_gl_id = ? WHERE id = ?");
                $stmt->bind_param('ssi', $source_gl_id, $corresponding_gl_id, $editId);
            }
        } else {
            if ($hasMode) {
                $stmt = $conn->prepare("INSERT INTO `$activeTable` (mode, source_gl_id, corresponding_gl_id) VALUES (?, ?, ?)");
                $stmt->bind_param('sss', $mode, $source_gl_id, $corresponding_gl_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO `$activeTable` (source_gl_id, corresponding_gl_id) VALUES (?, ?)");
                $stmt->bind_param('ss', $source_gl_id, $corresponding_gl_id);
            }
        }
        $stmt->execute();
        $stmt->close();
        header("Location: gl_mapping.php?table=" . urlencode($activeTable));
        exit;
    }
}

// Fetch rows for active table (with optional search)
$search = trim($_GET['search'] ?? '');
if ($hasMode) {
    $selectCols = "id, mode, source_gl_id, corresponding_gl_id";
} else {
    $selectCols = "id, source_gl_id, corresponding_gl_id";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    if ($hasMode) {
        $stmt = $conn->prepare("SELECT $selectCols FROM `$activeTable`
                                WHERE mode LIKE ? OR source_gl_id LIKE ? OR corresponding_gl_id LIKE ?
                                ORDER BY id ASC");
        $stmt->bind_param('sss', $like, $like, $like);
    } else {
        $stmt = $conn->prepare("SELECT $selectCols FROM `$activeTable`
                                WHERE source_gl_id LIKE ? OR corresponding_gl_id LIKE ?
                                ORDER BY id ASC");
        $stmt->bind_param('ss', $like, $like);
    }
} else {
    $stmt = $conn->prepare("SELECT $selectCols FROM `$activeTable` ORDER BY id ASC");
}
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Preferred mode order: Old → New → Mix, then anything else alphabetically
$preferredOrder = ['old' => 0, 'new' => 1, 'mix' => 2];

// Group rows by mode (only if table has a mode column)
$groupedRows = [];
if ($hasMode) {
    foreach ($rows as $row) {
        $groupedRows[$row['mode']][] = $row;
    }
    uksort($groupedRows, function ($a, $b) use ($preferredOrder) {
        $aRank = $preferredOrder[strtolower(trim($a))] ?? 100;
        $bRank = $preferredOrder[strtolower(trim($b))] ?? 100;
        if ($aRank !== $bRank) {
            return $aRank <=> $bRank;
        }
        return strcasecmp($a, $b);
    });
} else {
    // No mode — single ungrouped list
    $groupedRows['__none__'] = $rows;
}

// Build the source reference note per mode (based on active table)
$modeNotes = [];

if (!$hasMode) {
    // Static note for tables without mode
    $modeNotes[] = [
        'label'         => 'Comparative',
        'source'        => 'New GL Codes',
        'corresponding' => 'GL Codes',
        'color'         => 'note-comp',
    ];
} else {
    foreach (array_keys($groupedRows) as $modeKey) {
        $key = strtolower(trim($modeKey));
        if ($key === 'old') {
            $modeNotes[$modeKey] = [
                'label'         => 'Old',
                'source'        => 'Past Transaction / Manual Adjustment table',
                'corresponding' => 'HO GL Codes table',
                'color'         => 'note-old',
            ];
        } elseif ($key === 'new') {
            $modeNotes[$modeKey] = [
                'label'         => 'New',
                'source'        => 'Past Transaction / Manual Adjustment (mostly from manual adjustment) table',
                'corresponding' => 'New HO GL Codes table',
                'color'         => 'note-new',
            ];
        } elseif ($key === 'mix') {
            $modeNotes[$modeKey] = [
                'label'         => 'Mix',
                'source'        => 'New HO GL Codes',
                'corresponding' => 'HO GL Codes',
                'color'         => 'note-mix',
            ];
        }
    }
}

// Count rows per table for tab badges
$tableCounts = [];
foreach (array_keys($allowedTables) as $tbl) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM `$tbl`");
    $tableCounts[$tbl] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}

// Edit row prefill
$editRow = null;
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    if ($hasMode) {
        $stmt = $conn->prepare("SELECT id, mode, source_gl_id, corresponding_gl_id FROM `$activeTable` WHERE id = ?");
    } else {
        $stmt = $conn->prepare("SELECT id, source_gl_id, corresponding_gl_id FROM `$activeTable` WHERE id = ?");
    }
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GL Mapping for not the same GL ID</title>
    <link rel="icon" href="../images/MLW%20Logo.png" type="image/png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/gl_mapping.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style> 
      .modal-overlay {
            display: <?= $editRow || $formError ? 'flex' : 'none' ?>;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        } </style>
 
</head>
<body>
<main class="main-content">
    <header class="top-bar">
        <h2><a href="user_dashboard.php" style="font-size:16px;text-decoration:none;">⬅ Back</a></h2>
        <div class="user-badge">
            <span><?= htmlspecialchars($username); ?> (<?= htmlspecialchars($user_type); ?>)</span>
            <div class="avatar"><?= strtoupper(substr($full_name, 0, 1)); ?></div>
        </div>
    </header>

    <div class="content-wrapper">
        <div class="page-title">GL Mapping for not the same GL ID</div>

        <!-- Table Switcher Tabs -->
        <div class="tabs">
            <?php foreach ($allowedTables as $key => $meta): ?>
                <a href="?table=<?= urlencode($key) ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>"
                   class="tab <?= $activeTable === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($meta['label']) ?>
                    <span class="badge"><?= $tableCounts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Source Reference Notes -->
        <?php if (!empty($modeNotes)): ?>
            <div class="source-notes">
                <?php foreach ($modeNotes as $note): ?>
                    <div class="source-note <?= htmlspecialchars($note['color']) ?>">
                        <i class="fas fa-info-circle note-icon"></i>
                        <div>
                            <span class="note-label"><?= htmlspecialchars($note['label']) ?></span>
                            <strong>Source GL ID</strong> comes from the
                            <span class="note-table"><?= htmlspecialchars($note['source']) ?></span>,
                            and <strong>Corresponding GL ID</strong> comes from the
                            <span class="note-table"><?= htmlspecialchars($note['corresponding']) ?></span>.
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="toolbar">
            <form class="search-box" method="get">
                <input type="hidden" name="table" value="<?= htmlspecialchars($activeTable) ?>">
                <input type="text" name="search" placeholder="Search source or corresponding GL…"
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit"><i class="fas fa-search"></i> Search</button>
                <?php if ($search !== ''): ?>
                    <a href="?table=<?= urlencode($activeTable) ?>" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </form>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add Mapping
            </button>
        </div>

        <!-- Data Table -->
        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox" style="font-size:32px;margin-bottom:10px;display:block;"></i>
                No mappings found in <strong><?= htmlspecialchars($allowedTables[$activeTable]['label']) ?></strong>.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <?php if ($hasMode): ?><th>Mode</th><?php endif; ?>
                            <th>Source GL ID</th>
                            <th>Corresponding GL ID</th>
                            <th style="width:140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $groupKeys = array_keys($groupedRows);
                        $lastGroupIndex = count($groupKeys) - 1;
                        $counter = 1;
                        foreach ($groupKeys as $i => $modeKey):
                            foreach ($groupedRows[$modeKey] as $row):
                        ?>
                            <tr>
                                <td><?= $counter++ ?></td>
                                <?php if ($hasMode): ?>
                                    <td><span class="mode-pill"><?= htmlspecialchars($row['mode']) ?></span></td>
                                <?php endif; ?>
                                <td><span class="gl-id"><?= htmlspecialchars($row['source_gl_id']) ?></span></td>
                                <td><span class="gl-id"><?= htmlspecialchars($row['corresponding_gl_id']) ?></span></td>
                                <td class="actions">
                                    <a href="?table=<?= urlencode($activeTable) ?>&edit=<?= (int)$row['id'] ?>"
                                       class="btn btn-sm btn-secondary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?table=<?= urlencode($activeTable) ?>&delete=<?= (int)$row['id'] ?>"
                                       class="btn btn-sm btn-danger"
                                       onclick="return confirm('Delete this mapping?');" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php
                            endforeach;
                            // Spacer between groups (not after last group, only when has mode)
                            if ($hasMode && $i < $lastGroupIndex):
                        ?>
                            <tr class="spacer-row">
                                <td colspan="<?= $hasMode ? 5 : 4 ?>"></td>
                            </tr>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- Add / Edit Modal -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <h3><?= $editRow ? 'Edit Mapping' : 'Add Mapping' ?></h3>
        <?php if ($formError): ?>
            <div class="form-error"><?= htmlspecialchars($formError) ?></div>
        <?php endif; ?>
        <form method="post" action="?table=<?= urlencode($activeTable) ?>">
            <input type="hidden" name="edit_id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
            <?php if ($hasMode): ?>
                <div class="form-group">
                    <label>Mode</label>
                    <input type="text" name="mode" required maxlength="45"
                           value="<?= htmlspecialchars($editRow['mode'] ?? '') ?>"
                           placeholder="e.g. Old, New, Mix">
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label>Source GL ID</label>
                <input type="text" name="source_gl_id" required maxlength="45"
                       value="<?= htmlspecialchars($editRow['source_gl_id'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Corresponding GL ID</label>
                <input type="text" name="corresponding_gl_id" required maxlength="45"
                       value="<?= htmlspecialchars($editRow['corresponding_gl_id'] ?? '') ?>">
            </div>
            <div class="modal-actions">
                <a href="?table=<?= urlencode($activeTable) ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $editRow ? 'Update' : 'Save' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddModal() {
        window.location.href = '?table=<?= urlencode($activeTable) ?>&add=1';
    }

    <?php if (isset($_GET['add'])): ?>
        document.getElementById('modalOverlay').style.display = 'flex';
    <?php endif; ?>

    // Close modal on overlay click
    document.getElementById('modalOverlay').addEventListener('click', function(e) {
        if (e.target === this) {
            window.location.href = '?table=<?= urlencode($activeTable) ?>';
        }
    });
</script>

<?php include '../footer.php'; ?>
</body>
</html>
<?php $conn->close(); ?>