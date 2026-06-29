<?php
// ============================================================================
// NORA - NoraWeb.Action.php
// ----------------------------------------------------------------------------
// Handles AJAX actions for admin bulk edits (priority, status)
// ----------------------------------------------------------------------------
date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$allowed = musky_require_any_tool(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['ADMIN_PANEL'],
    [
        'response' => 'json',
        'status' => 403,
        'message' => 'Access Denied: Missing Required Tool ADMIN_PANEL.',
        'payload' => [
            'ok' => false,
            'msg' => 'Access Denied: Missing Required Tool ADMIN_PANEL.',
        ],
    ]
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit;
}

$pdo = nora_connect();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid JSON body']);
    exit;
}

$csrfToken = null;
if (isset($input['csrf_token']) && is_string($input['csrf_token'])) {
    $csrfToken = $input['csrf_token'];
} elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
}

if (!musky_csrf_is_valid('nora_errands_bulk_update', $csrfToken)) {
    error_log('[NoraWeb.Action] Invalid or missing CSRF token.');
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Invalid or missing CSRF token.']);
    exit;
}

$action = $input['action'] ?? '';
$ids = $input['ids'] ?? [];
$value = $input['value'] ?? '';

if (!is_array($ids) || empty($ids)) {
    echo json_encode(['ok' => false, 'msg' => 'No IDs provided']);
    exit;
}

try {
    switch ($action) {
        case 'priority':
            $stmt = $pdo->prepare("UPDATE nora_errands SET TaskPriority=? WHERE ErrandID=?");
            foreach ($ids as $id) $stmt->execute([$value, $id]);
            echo json_encode(['ok' => true, 'updated' => count($ids)]);
            break;

        case 'status':
            // Adjust date logic based on new status
            $stmt = $pdo->prepare("
                UPDATE nora_errands
                   SET Status=?, 
                       CompleteDateTime = CASE 
                           WHEN ? IN ('Complete','Cancelled','Failed','Rejected') THEN NOW()
                           ELSE NULL END
                 WHERE ErrandID=?");
            foreach ($ids as $id) $stmt->execute([$value, $value, $id]);
            echo json_encode(['ok' => true, 'updated' => count($ids)]);
            break;

        default:
            echo json_encode(['ok' => false, 'msg' => 'Unknown action']);
    }
} catch (Exception $e) {
    error_log('[NoraWeb.Action] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Action failed.']);
}
