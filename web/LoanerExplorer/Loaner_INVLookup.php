<?php
// ============================================================================
// Loaner_INVLookup.php
// ----------------------------------------------------------------------------
// Starts INV_LOOKUP errand via Nora API
// ============================================================================

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$allowed = musky_require_general_admin_access(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['LOANER_MGMT', 'LOANER_EXPLORER', 'CLASS_MANAGER'],
    [
        'response' => 'json',
        'status' => 403,
        'message' => 'Missing required Musky tool rights.',
        'payload' => [
            'error' => 'forbidden',
            'detail' => 'Missing required Musky tool rights.',
        ],
    ]
);

// ----------------------------------------------------------------------------
// CONFIG
// ----------------------------------------------------------------------------
$LOG_FILE = musky_root_config_string('debug.loaner_invlookup_log', '/tmp/nora_api_invlookup.log');

// ----------------------------------------------------------------------------
// INPUT
// ----------------------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);
$serials = $input['serials'] ?? [];

if (empty($serials) || !is_array($serials)) {
    echo json_encode([
        'error' => 'invalid_input',
        'detail' => 'No serials provided'
    ]);
    exit;
}

$owner = $_SESSION['musky_user']['email']
    ?? $_SESSION['musky_user']['username']
    ?? '';

// ----------------------------------------------------------------------------
// BUILD PAYLOAD
// ----------------------------------------------------------------------------
$payload = [
    'priority'   => 5,
    'serial'     => 'NONE',
    'udid'       => 'BULK-INVLOOKUP',
    'submitter'  => 'LOANER_EXPLORER',
    'owner'      => $owner,
    'nora'   => 'TRUE',
    'extra1'   => 'INV_LOOKUP',
    'extra2'     => json_encode([
        'serials' => $serials
    ])
];

// ----------------------------------------------------------------------------
// API CALL
// ----------------------------------------------------------------------------
$data = musky_nora_api_post_json('/errand/create', $payload, 30);
$errandId = musky_nora_extract_errand_id($data);

if ($LOG_FILE !== '') {
    @file_put_contents(
        $LOG_FILE,
        date('c') . " helper_submit=" . (is_array($data) ? 'ok' : 'failed') .
        " serial_count=" . count($serials) . "\n",
        FILE_APPEND
    );
}

if (!is_array($data)) {
    echo json_encode([
        'error' => 'nora_api_error'
    ]);
    exit;
}

if (!$errandId) {
    echo json_encode([
        'error'  => 'inv_lookup_failed',
        'detail' => $data
    ]);
    exit;
}

// ----------------------------------------------------------------------------
// SUCCESS
// ----------------------------------------------------------------------------
echo json_encode([
    'success'   => true,
    'errand_id' => $errandId
]);
musky_activity_log([
    'event_type'     => 'ACTION',
    'action_name'    => 'INV_LOOKUP',
    'page_path'      => '/LoanerExplorer/Loaner_INVLookup.php',
    'target_serials' => $serials,
    'extra'          => [
        'errand_id' => $errandId,
        'submitter' => $payload['submitter'] ?? '',
        'owner'     => $owner,
    ],
]);
exit;
