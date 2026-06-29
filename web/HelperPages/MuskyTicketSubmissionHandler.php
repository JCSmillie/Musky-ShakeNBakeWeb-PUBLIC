<?php
// ======================================================================
// MUSKY - MuskyTicketSubmissionHandler.php  (Bridge v1.3 – ExtraWorkCalls Support)
// ----------------------------------------------------------------------
// PURPOSE
//   • Accept payload(s) from Ticket Maker and create one nora_errands row per device
//     to trigger an IIQ CREATE.
//   • Ensure ExtraDataField05 always contains "CREATE" for IIQ errands.
//   • Preserve and pass through ExtraWorkCalls (if present) for later processing
//     by NoraSubHandler_IIQ.php or other subsystems.
//   • Write inbound payload to /tmp for debugging.
// ----------------------------------------------------------------------

date_default_timezone_set('America/New_York');
header('Content-Type: application/json');

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

// ----------------------------------------------------------------------
// Bootstrap config + DB
// ----------------------------------------------------------------------
$rootDir = __DIR__ . '/..';
require_once $rootDir . '/bootstrap.php';
// ----------------------------------------------------------------------
// SECURITY BASELINE (MANDATORY):
//   Every direct web entrypoint under HelperPages must bootstrap the shared
//   Musky access middleware. This guarantees:
//     1) The caller is authenticated (or redirected to login).
//     2) Session/user context is normalized the same way site-wide.
//     3) Access helper functions (like musky_is_admin and tool checks) are
//        available to this request path.
// ----------------------------------------------------------------------
require_once $rootDir . '/check_access.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed.', 405);
}

try {
    $pdo = nora_connect();
} catch (Exception $e) {
    error_log('[MuskyTicketSubmissionHandler] Nora connect failed: ' . $e->getMessage());
    fail('Ticket submission backend unavailable.', 500);
}

// ----------------------------------------------------------------------
// Read JSON body + dump for debug
// ----------------------------------------------------------------------
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    fail('Empty request body.');
}

// 🔍 Debug dump
$debugDumpPath = '/tmp/musky_ticket_submit_' . date('Ymd_His') . '.json';
file_put_contents($debugDumpPath, $raw);

$in = json_decode($raw, true);
if (!is_array($in)) {
    fail('Invalid JSON body.');
}

$csrfToken = null;
if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (isset($in['csrf_token']) && is_string($in['csrf_token'])) {
    $csrfToken = $in['csrf_token'];
}

if (!musky_csrf_is_valid('musky_ticket_submission', $csrfToken)) {
    error_log('[MuskyTicketSubmissionHandler] Invalid or missing CSRF token.');
    fail('Invalid or missing CSRF token.', 403);
}

// Allow either wrapper or raw array
$payloads = $in;
if (isset($in['payloads']) && is_array($in['payloads'])) {
    $payloads = $in['payloads'];
}
if (!is_array($payloads) || empty($payloads)) {
    fail('No payloads found.');
}

// ----------------------------------------------------------------------
// Prepare INSERT
// ----------------------------------------------------------------------
$sql = "
INSERT INTO nora_errands
(TaskPriority, TaskRepeat, TaskRepeatHowMany, Status,
 MOSBasicRequest, SlackRequest, IIQRequest, NoraRequest, CustomRequest,
 UDID, DeviceSerial, AssetTag, Submitter, DeviceOwner,
 ExtraDataField01, ExtraDataField02, ExtraDataField03, ExtraDataField04,
 ExtraDataField05, ExtraDataField06)
VALUES
(:TaskPriority, :TaskRepeat, :TaskRepeatHowMany, 'submitted',
 'FALSE','FALSE',:IIQRequest,'FALSE','FALSE',
 :UDID, :DeviceSerial, :AssetTag, :Submitter, :DeviceOwner,
 :ExtraDataField01, :ExtraDataField02, :ExtraDataField03, :ExtraDataField04,
 :ExtraDataField05, :ExtraDataField06)
";
$stmt = $pdo->prepare($sql);

// ----------------------------------------------------------------------
// Validate + Insert each payload
// ----------------------------------------------------------------------
$ids = [];
foreach ($payloads as $idx => $p) {

    // Normalize core flags
    $iiq = strtoupper((string)($p['IIQRequest'] ?? 'FALSE')) === 'TRUE' ? 'TRUE' : 'FALSE';

    $taskPriority = (string)($p['Priority'] ?? '5');
    $taskPriority = ctype_digit($taskPriority) ? (int)$taskPriority : 5;
    if ($taskPriority < -1 || $taskPriority > 5) $taskPriority = 5;

    $taskRepeat = (string)($p['Repeat'] ?? 'FALSE');
    $allowedRepeats = ['FALSE','HOUR','OneMin','FiveMin','15Min','30Min'];
    if (!in_array($taskRepeat, $allowedRepeats, true)) $taskRepeat = 'FALSE';

    $repeatHowMany = $p['TaskRepeatHowMany'] ?? null;
    if ($repeatHowMany !== null) {
        $repeatHowMany = (int)$repeatHowMany;
        if ($repeatHowMany < 1 || $repeatHowMany > 60) $repeatHowMany = null;
    }

    $udid         = trim((string)($p['UDID'] ?? ''));
    $serial       = trim((string)($p['DeviceSerial'] ?? ''));
    $asset        = trim((string)($p['AssetTag'] ?? ''));
    $submitter    = trim((string)($p['Submitter'] ?? 'unknown'));
    $deviceOwner  = trim((string)($p['DeviceOwner'] ?? ''));

    // Extra fields (allow any structure for 01)
    $extra01 = $p['ExtraDataField01'] ?? null;
    $extra02 = $p['ExtraDataField02'] ?? null;
    $extra03 = $p['ExtraDataField03'] ?? null;
    $extra04 = $p['ExtraDataField04'] ?? null;
    $extra05 = $p['ExtraDataField05'] ?? null;
    $extra06 = $p['ExtraDataField06'] ?? null;

    // ------------------------------------------------------------------
    // 🧩 NEW: Support for ExtraWorkCalls
    // ------------------------------------------------------------------
    $extraWork = $p['ExtraWorkCalls'] ?? null;
    if ($extraWork !== null) {
        // We append it to ExtraDataField03 if it isn’t already set,
        // since 01/02/04/05/06 have specific purposes.
        if (empty($extra03)) {
            $extra03 = json_encode($extraWork, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } else {
            // Merge/attach for visibility if something’s already in 03
            $extra03 = json_encode(['existing'=>$extra03, 'ExtraWorkCalls'=>$extraWork],
                                   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }

    // ------------------------------------------------------------------
    // 🧩 Ensure ExtraDataField05 contains "CREATE" for IIQ errands
    // ------------------------------------------------------------------
    if ($iiq === 'TRUE') {
        if (empty($extra05) || stripos($extra05, 'CREATE') === false) {
            $extra05 = 'CREATE';
        }
    }

    // Skip invalid payloads
    if ($udid === '' || $serial === '' || $iiq !== 'TRUE') {
        continue;
    }

    // ------------------------------------------------------------------
    // Execute INSERT
    // ------------------------------------------------------------------
    $stmt->execute([
        ':TaskPriority'      => $taskPriority,
        ':TaskRepeat'        => $taskRepeat,
        ':TaskRepeatHowMany' => $repeatHowMany,
        ':IIQRequest'        => $iiq,
        ':UDID'              => $udid,
        ':DeviceSerial'      => $serial,
        ':AssetTag'          => $asset !== '' ? $asset : null,
        ':Submitter'         => $submitter,
        ':DeviceOwner'       => $deviceOwner !== '' ? $deviceOwner : null,
        ':ExtraDataField01'  => $extra01 !== null ? json_encode($extra01, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '{"note":"None"}',
        ':ExtraDataField02'  => $extra02 !== null ? (is_string($extra02) ? $extra02 : json_encode($extra02, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) : null,
        ':ExtraDataField03'  => $extra03 !== null ? (is_string($extra03) ? $extra03 : json_encode($extra03, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) : null,
        ':ExtraDataField04'  => $extra04 !== null ? (is_string($extra04) ? $extra04 : json_encode($extra04, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) : null,
        ':ExtraDataField05'  => is_string($extra05) ? $extra05 : json_encode($extra05, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ':ExtraDataField06'  => $extra06 !== null ? (is_string($extra06) ? $extra06 : json_encode($extra06, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) : null,
    ]);

    $ids[] = (int)$pdo->lastInsertId();
}

// ----------------------------------------------------------------------
// Return created IDs or error
// ----------------------------------------------------------------------
if (!$ids) {
    fail('No errands were created (validate payloads).', 422);
}

echo json_encode(['ids' => $ids], JSON_UNESCAPED_SLASHES);
exit;
