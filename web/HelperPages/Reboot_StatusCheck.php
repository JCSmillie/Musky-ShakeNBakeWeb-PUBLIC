<?php
// ============================================================================
// MUSKY - Reboot_StatusCheck.php
// ----------------------------------------------------------------------------
// PURPOSE:
//   Return current status of an errand (by ErrandID) as JSON for Musky_Reboot_iPad.php
// ----------------------------------------------------------------------------
// DEPENDS:
//   • ../../Functions/nora_connect.php
// ----------------------------------------------------------------------------
// AUTHOR: SmillieWare / Gateway Project
// ============================================================================

date_default_timezone_set('America/New_York');
// ----------------------------------------------------------------------------
// SECURITY BASELINE (MANDATORY):
//   Even though this file only returns JSON, it is still a direct endpoint.
//   Load shared access middleware so status polling is tied to authenticated
//   Musky sessions and consistent access context.
// ----------------------------------------------------------------------------
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';

header('Content-Type: application/json');

try {
    $pdo = nora_connect();
} catch (Exception $e) {
    error_log('[Reboot_StatusCheck] DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Database connection failed',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Validate ID
// -----------------------------------------------------------------------------
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => 'Invalid or missing errand ID',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Query NORA errands table
// -----------------------------------------------------------------------------
try {
    $stmt = $pdo->prepare("SELECT Status FROM nora_errands WHERE ErrandID = ? LIMIT 1");
    $stmt->execute([$id]);
    $status = $stmt->fetchColumn() ?: 'Unknown';
} catch (Exception $e) {
    error_log('[Reboot_StatusCheck] Query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Query failed',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Output result
// -----------------------------------------------------------------------------
echo json_encode([
    'errand_id' => $id,
    'status' => ucfirst(strtolower($status)),
    'timestamp' => date('Y-m-d H:i:s')
]);
