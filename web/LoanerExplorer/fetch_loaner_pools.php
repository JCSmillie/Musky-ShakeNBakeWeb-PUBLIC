<?php
// ============================================================================
// MUSKY - fetch_loaner_pools.php
// ----------------------------------------------------------------------------
// Returns a list of all pool_name values from iiq_loaners with counts.
// This version performs *no* errand creation or Nora tasks.
// ============================================================================

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ----------------------------------------------------------------------------
// Access enforcement
// ----------------------------------------------------------------------------
$allowed = musky_require_general_admin_access(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['LOANER_MGMT', 'LOANER_EXPLORER', 'CLASS_MANAGER'],
    [
        'response' => 'json',
        'status' => 403,
        'message' => 'Access denied.',
        'payload' => ['error' => 'forbidden'],
    ]
);

// ----------------------------------------------------------------------------
// Logging helper (same as fetch_loaner_devices)
// ----------------------------------------------------------------------------
$LOGFILE = "/tmp/musky_loaner_debug.log";

function lp_log(string $msg, array $ctx = []): void {
    global $LOGFILE;
    $line = "[" . date('Y-m-d H:i:s') . "] <fetch_loaner_pools> $msg";
    if (!empty($ctx)) $line .= " " . json_encode($ctx, JSON_UNESCAPED_SLASHES);
    @file_put_contents($LOGFILE, $line . "\n", FILE_APPEND);
}

// ----------------------------------------------------------------------------
// Query NORA for distinct pools & counts
// ----------------------------------------------------------------------------
global $pdo;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['error' => 'server', 'detail' => 'Bad NORA DB handle']);
    exit;
}

$sql = "
    SELECT
        pool_name,
        COUNT(*) AS device_count,
        SUM(CASE WHEN ticket_id IS NOT NULL THEN 1 ELSE 0 END) AS loaned_count
    FROM iiq_loaners
    GROUP BY pool_name
    ORDER BY pool_name
";

lp_log("Fetching pools");

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

lp_log("Pools fetched", ['count' => count($rows)]);

echo json_encode([
    'pools' => $rows
], JSON_PRETTY_PRINT);
