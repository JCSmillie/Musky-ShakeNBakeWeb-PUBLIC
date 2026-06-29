<?php
// ============================================================================
// MUSKY - Loaner_INVLookupStatus.php
// ============================================================================

date_default_timezone_set('America/New_York');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$LOG_FILE = musky_root_config_string('debug.loaner_invlookup_status_log', '/tmp/nora_api_invlookup_status.log');

// -----------------------------------------------------------------------------
// Permissions
// -----------------------------------------------------------------------------
$allowed = musky_require_general_admin_access(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['LOANER_MGMT', 'LOANER_EXPLORER', 'CLASS_MANAGER'],
    [
        'response' => 'json',
        'status' => 403,
        'message' => 'Missing required Musky tool rights.',
        'payload' => [
            'error'  => 'forbidden',
            'detail' => 'Missing required Musky tool rights.'
        ],
    ]
);

// -----------------------------------------------------------------------------
// Helper
// -----------------------------------------------------------------------------
function loaner_nora_api_get_json(string $path): ?array
{
    global $LOG_FILE;

    $json = musky_nora_api_get_json($path, [], 20);
    @file_put_contents(
        $LOG_FILE,
        sprintf("[%s] helper_get path=%s result=%s\n", date('c'), $path, is_array($json) ? 'ok' : 'failed'),
        FILE_APPEND
    );

    return is_array($json) ? $json : null;
}

// -----------------------------------------------------------------------------
// Input
// -----------------------------------------------------------------------------
$errandId = $_GET['id'] ?? ($_GET['errand_id'] ?? '');
$errandId = trim($errandId);

if ($errandId === '' || !ctype_digit($errandId)) {
    http_response_code(400);
    echo json_encode(['error'=>'bad_request']);
    exit;
}

// -----------------------------------------------------------------------------
// Call Nora
// -----------------------------------------------------------------------------
$resp = loaner_nora_api_get_json('/errand/status/' . urlencode($errandId));

if (!$resp) {
    http_response_code(502);
    echo json_encode([
        'error' => 'nora_api_error',
        'errand_id' => $errandId
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// NORMALIZE STATUS (THIS FIXES YOUR ISSUE)
// -----------------------------------------------------------------------------
$statusRaw = $resp['Status'] ?? $resp['status'] ?? 'Unknown';
$status    = strtoupper(trim($statusRaw));

// -----------------------------------------------------------------------------
// OUTPUT (CLEAN + UI FRIENDLY)
// -----------------------------------------------------------------------------
if ($status === 'COMPLETE') {
    echo json_encode([
        'errand_id' => (int)$errandId,
        'status'    => 'complete',
        'log'       => $resp['ExtraDataField06'] ?? '',
        'raw'       => $resp
    ]);
    exit;
}

if (in_array($status, ['FAILED','REJECTED','CANCELLED'], true)) {
    echo json_encode([
        'errand_id' => (int)$errandId,
        'status'    => 'failed',
        'raw'       => $resp
    ]);
    exit;
}

// still running
echo json_encode([
    'errand_id' => (int)$errandId,
    'status'    => 'running',
    'raw'       => $resp
]);
