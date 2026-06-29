<?php
// ============================================================================
// MUSKY - Inventory/index.php
// ----------------------------------------------------------------------------
// Inventory Dashboard (ADMIN ONLY)
// ----------------------------------------------------------------------------
// • Uses panda_parts as authoritative parts list
// • Displays stock for GHS_HDK + GMS_HDK
// • NONINV is virtual (transaction log only)
// • Uses ../../Functions/Inventory.php
// • Hides AGI-* and IPadDeviceVandalism
// • Low stock highlighting + sorting
// • Auto-generates transaction IDs
// • Includes live transaction panel
// • Includes Reconciliation (Fix) system
// • Proper Musky theme support
// ============================================================================

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../../Functions/Inventory.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';

$pdo = nora_connect();
$LOW_STOCK_THRESHOLD = 5;
$message = null;
$error = null;

// ============================================================
// ACCESS CONTROL — ADMIN_PANEL or ALL_TOOLS REQUIRED
// ============================================================

$tool_required = 'ADMIN_PANEL';
$allowed = explode(',', $_SESSION['musky_user']['allowed_tools'] ?? []);

if (!in_array($tool_required, $allowed) && !in_array('ALL_TOOLS', $allowed)) {
    http_response_code(403);
    echo "⛔ Access Denied — Missing Required Tool: {$tool_required}";
    exit;
}

if (!isset($_SESSION['musky_user'])) {
    http_response_code(403);
    echo "⛔ Access Denied — Not logged in.";
    exit;
}
// ============================================================
// MUSKY THEME LOAD (Correct Pattern)
// ============================================================

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$userEmail = $prefs['email'] ?? ($_SESSION['musky_user']['email'] ?? null);
$csrfToken = musky_csrf_token('inventory_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    musky_csrf_require('inventory_admin');
}

// ============================================================
// PROCESS ADD
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    try {
        $result = inventory_adjust(
            $pdo,
            $_POST['location'],
            intval($_POST['part_id']),
            intval($_POST['qty']),
            null,
            'ADD',
            null,
            $userEmail
        );
        $message = "Inventory Added. Transaction ID: " . $result['external_transaction_id'];
    } catch (Exception $e) {
        error_log('[Inventory] add failed: ' . $e->getMessage());
        $error = 'Could not add inventory.';
    }
}

// ============================================================
// PROCESS TRANSFER
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transfer') {
    try {
        $result = inventory_transfer(
            $pdo,
            $_POST['from_location'],
            $_POST['to_location'],
            intval($_POST['part_id']),
            intval($_POST['qty']),
            null,
            null,
            $userEmail
        );
        $message = "Transfer Complete. Transaction ID: " . $result['external_transaction_id'];
    } catch (Exception $e) {
        error_log('[Inventory] transfer failed: ' . $e->getMessage());
        $error = 'Could not transfer inventory.';
    }
}

// ============================================================
// PROCESS RECONCILE
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reconcile') {
    try {
        $partId   = intval($_POST['part_id']);
        $location = $_POST['location'];
        $newQty   = intval($_POST['new_qty']);
        $reason   = trim($_POST['reason']);

        if ($reason === '') {
            throw new Exception("Reason is required for reconciliation.");
        }

        $currentQty = inventory_get_stock_qty($pdo, $partId, $location);
        $delta = $newQty - $currentQty;

        if ($delta != 0) {
            $result = inventory_adjust(
                $pdo,
                $location,
                $partId,
                $delta,
                null,
                'RECONCILE',
                $reason,
                $userEmail
            );
            $message = "Reconciliation Complete. Transaction ID: " . $result['external_transaction_id'];
        } else {
            $message = "No change required. Quantity already correct.";
        }

    } catch (Exception $e) {
        error_log('[Inventory] reconcile failed: ' . $e->getMessage());
        $error = 'Could not reconcile inventory.';
    }
}

// ============================================================
// INVENTORY SUMMARY
// ============================================================

$sql = "
SELECT *
FROM (
    SELECT 
        p.PartID,
        p.PartCode,
        p.PartName,
        COALESCE(SUM(CASE WHEN s.location_code='GHS_HDK' THEN s.qty END),0) AS GHS,
        COALESCE(SUM(CASE WHEN s.location_code='GMS_HDK' THEN s.qty END),0) AS GMS,
        (
            COALESCE(SUM(CASE WHEN s.location_code='GHS_HDK' THEN s.qty END),0) +
            COALESCE(SUM(CASE WHEN s.location_code='GMS_HDK' THEN s.qty END),0)
        ) AS TOTAL_STOCK
    FROM panda_parts p
    LEFT JOIN inventory_stock s ON p.PartID = s.part_id
    WHERE 
        p.IsActive = 'YES'
        AND p.PartCode NOT LIKE 'AGI-%'
        AND p.PartCode <> 'IPadDeviceVandalism'
    GROUP BY p.PartID
) inv
ORDER BY 
    (TOTAL_STOCK <= {$LOW_STOCK_THRESHOLD}) DESC,
    TOTAL_STOCK ASC,
    PartName ASC
";

$parts = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 30 DAY USAGE
// ============================================================

$usageSql = "
SELECT part_id,
       SUM(
           CASE 
               WHEN delta_to < 0 THEN ABS(delta_to)
               WHEN delta_from < 0 THEN ABS(delta_from)
               ELSE 0
           END
       ) AS used_30d
FROM inventory_transactions
WHERE created_at >= (NOW() - INTERVAL 30 DAY)
GROUP BY part_id
";

$usageRows = $pdo->query($usageSql)->fetchAll(PDO::FETCH_ASSOC);
$usageMap = [];
foreach ($usageRows as $u) {
    $usageMap[$u['part_id']] = intval($u['used_30d']);
}

// ============================================================
// DROPDOWN PART LIST
// ============================================================

$dropdownSql = "
SELECT PartID, PartCode, PartName
FROM panda_parts
WHERE 
    IsActive = 'YES'
    AND PartCode NOT LIKE 'AGI-%'
    AND PartCode <> 'IPadDeviceVandalism'
ORDER BY PartName ASC
";

$partsDropdown = $pdo->query($dropdownSql)->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// RECENT TRANSACTIONS
// ============================================================

$txnSql = "
SELECT 
    t.created_at,
    t.action,
    t.note,
    p.PartCode,
    p.PartName,
    t.from_location_code,
    t.to_location_code,
    t.actor,
    t.external_transaction_id
FROM inventory_transactions t
LEFT JOIN panda_parts p ON t.part_id = p.PartID
ORDER BY t.id DESC
LIMIT 50
";

$transactions = $pdo->query($txnSql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Musky Inventory (Admin)</title>
<style>
body { font-family: system-ui, Arial, sans-serif; margin:0; background:#f3f4f6; }
.topbar { background:#111827; color:white; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; }
.wrap { padding:20px; }
.card { background:white; border-radius:12px; padding:18px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
.section-title { font-weight:600; margin-bottom:14px; }
table { width:100%; border-collapse:collapse; }
th, td { padding:10px; border-bottom:1px solid #e5e7eb; font-size:13px; }
th { background:#f9fafb; text-align:left; }
td.num { text-align:right; }
.low-stock { background:#fff3f3; border-left:4px solid #dc2626; }
.low-number { color:#b91c1c; font-weight:600; }
.btn { padding:6px 10px; border-radius:6px; border:1px solid #ccc; background:white; cursor:pointer; }
input, select { padding:6px; border-radius:6px; border:1px solid #ccc; }
.message { padding:10px; border-radius:6px; margin-bottom:15px; }
.success { background:#e6fffa; border:1px solid #10b981; }
.error { background:#fee2e2; border:1px solid #dc2626; }
.small { font-size:12px; color:#666; }
.txn-action-pill {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    background: #eef2ff;
    color: #3730a3;
    font-weight: 600;
    font-size: 12px;
}
.txn-action-pill.panda {
    background: #dcfce7;
    color: #166534;
}
.txn-note {
    font-size: 12px;
    color: #4b5563;
    margin-top: 4px;
    max-width: 420px;
}
</style>
</head>

<body class="<?php echo htmlspecialchars($theme); ?>">

<div class="topbar">
    <h1>Inventory Dashboard (Admin)</h1>
    <button onclick="window.location.href='../index.php'" class="btn">Return to Main</button>
</div>

<div class="wrap">

<?php if ($message): ?><div class="message success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="message error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- Inventory Table -->
<div class="card">
<div class="section-title">Current Inventory Totals</div>
<table>
<tr>
<th>Part</th><th>GHS</th><th>GMS</th><th>Total</th><th>Used (30d)</th>
</tr>
<?php foreach ($parts as $p):
$isLow = ($p['TOTAL_STOCK'] <= $LOW_STOCK_THRESHOLD);
?>
<tr class="<?php echo $isLow ? 'low-stock' : ''; ?>">
<td><?php echo htmlspecialchars($p['PartCode']." — ".$p['PartName']); ?></td>
<td class="num"><?php echo $p['GHS']; ?></td>
<td class="num"><?php echo $p['GMS']; ?></td>
<td class="num <?php echo $isLow?'low-number':''; ?>"><?php echo $p['TOTAL_STOCK']; ?></td>
<td class="num"><?php echo $usageMap[$p['PartID']] ?? 0; ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

<!-- Add -->
<div class="card">
<div class="section-title">Add Inventory</div>
<form method="post">
<input type="hidden" name="action" value="add">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
<select name="part_id" required>
<option value="">Select Part</option>
<?php foreach ($partsDropdown as $pd): ?>
<option value="<?php echo $pd['PartID']; ?>">
<?php echo htmlspecialchars($pd['PartCode']." — ".$pd['PartName']); ?>
</option>
<?php endforeach; ?>
</select>
<select name="location">
<option value="GHS_HDK">GHS HDK</option>
<option value="GMS_HDK">GMS HDK</option>
</select>
<input type="number" name="qty" min="1" placeholder="Quantity" required>
<button class="btn">Add</button>
</form>
</div>

<!-- Transfer -->
<div class="card">
<div class="section-title">Transfer Inventory</div>
<form method="post">
<input type="hidden" name="action" value="transfer">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
<select name="part_id" required>
<option value="">Select Part</option>
<?php foreach ($partsDropdown as $pd): ?>
<option value="<?php echo $pd['PartID']; ?>">
<?php echo htmlspecialchars($pd['PartCode']." — ".$pd['PartName']); ?>
</option>
<?php endforeach; ?>
</select>
<select name="from_location">
<option value="GHS_HDK">GHS HDK</option>
<option value="GMS_HDK">GMS HDK</option>
</select>
<select name="to_location">
<option value="GMS_HDK">GMS HDK</option>
<option value="GHS_HDK">GHS HDK</option>
</select>
<input type="number" name="qty" min="1" required>
<button class="btn">Transfer</button>
</form>
</div>

<!-- Reconcile -->
<div class="card">
<div class="section-title">Reconcile / Fix Inventory</div>
<form method="post">
<input type="hidden" name="action" value="reconcile">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
<select name="part_id" required>
<option value="">Select Part</option>
<?php foreach ($partsDropdown as $pd): ?>
<option value="<?php echo $pd['PartID']; ?>">
<?php echo htmlspecialchars($pd['PartCode']." — ".$pd['PartName']); ?>
</option>
<?php endforeach; ?>
</select>
<select name="location" required>
<option value="GHS_HDK">GHS HDK</option>
<option value="GMS_HDK">GMS HDK</option>
</select>
<input type="number" name="new_qty" min="0" placeholder="Correct Quantity" required>
<input type="text" name="reason" placeholder="Reason (required)" required>
<button class="btn">Apply Reconciliation</button>
</form>
<div class="small">Logs a RECONCILE transaction. Fully auditable.</div>
</div>

<!-- Transactions -->
<div class="card">
<div class="section-title">Recent Transactions (Last 50)</div>
<table>
<tr>
<th>Date</th><th>Action</th><th>Part</th><th>Movement</th><th>Actor</th><th>Txn ID</th>
</tr>
<?php foreach ($transactions as $t): ?>
<?php
    $rawAction = (string)($t['action'] ?? '');
    $rawNote = (string)($t['note'] ?? '');
    $actionLabel = $rawAction;
    $actionClass = 'txn-action-pill';

    if ($rawAction === 'USE' && str_contains($rawNote, '[PANDA Stock Pull]')) {
        $actionLabel = 'PANDA Stock Pull';
        $actionClass .= ' panda';
    }
?>
<tr>
<td><?php echo $t['created_at']; ?></td>
<td>
  <span class="<?php echo htmlspecialchars($actionClass); ?>">
    <?php echo htmlspecialchars($actionLabel); ?>
  </span>
  <?php if (!empty($t['note'])): ?>
    <div class="txn-note"><?php echo htmlspecialchars($t['note']); ?></div>
  <?php endif; ?>
</td>
<td><?php echo htmlspecialchars(($t['PartCode'] ?? '') . (!empty($t['PartName']) ? ' — ' . $t['PartName'] : '')); ?></td>
<td><?php echo ($t['from_location_code'] ?? '') . " → " . ($t['to_location_code'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($t['actor']); ?></td>
<td><?php echo htmlspecialchars($t['external_transaction_id']); ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

</div>
</body>
</html>
