<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST ?: [];
}

$eventType = strtoupper(trim((string)($data['event_type'] ?? 'ACTION')));
$actionName = trim((string)($data['action_name'] ?? ''));

if ($eventType === '' || $actionName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_action']);
    exit;
}

$ok = musky_activity_log([
    'event_type'        => $eventType,
    'action_name'       => $actionName,
    'page_path'         => $data['page_path'] ?? ($_SERVER['HTTP_REFERER'] ?? ($_SERVER['REQUEST_URI'] ?? '')),
    'target_serials'    => $data['target_serials'] ?? [],
    'target_asset_tags' => $data['target_asset_tags'] ?? [],
    'extra'             => $data['extra'] ?? [],
]);

echo json_encode(['ok' => $ok]);
