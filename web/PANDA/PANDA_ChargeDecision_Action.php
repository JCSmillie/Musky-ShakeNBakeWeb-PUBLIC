<?php
// ============================================================================
// PANDA_ChargeDecision_Action.php
// ----------------------------------------------------------------------------
// Handles all decision actions for a PANDA charge, including:
//   • Status updates
//   • SkywardFees errand submission
//   • HOLD recovery + errand re-checking
//   • Preventing double charges
//   • Insurance logic + vandalism rules
//   • Admin notes + auto-generated comments
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/PANDA_Functions.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

panda_require_charges_enabled();

function panda_action_finish_and_close(int $chargeId, ?string $message = null): void
{
    $redirectUrl = 'PANDA_ChargeDecision.php?ChargeID=' . urlencode((string)$chargeId) . '&decision_saved=1';
    $safeRedirectUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
    $displayMessage = trim((string)$message) !== '' ? trim((string)$message) : 'Decision saved.';
    $safeDisplayMessage = htmlspecialchars($displayMessage, ENT_QUOTES, 'UTF-8');
    $jsRedirectUrl = json_encode($redirectUrl, JSON_UNESCAPED_SLASHES);

    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>PANDA Decision Saved</title>
</head>
<body style="font-family:sans-serif;padding:24px;">
  <p>{$safeDisplayMessage}</p>
  <p>If this tab does not close automatically, you can continue here:</p>
  <p><a href="{$safeRedirectUrl}">Return to charge</a></p>
  <script>
    (function () {
      var redirectUrl = {$jsRedirectUrl};
      try {
        window.close();
      } catch (e) {}

      window.setTimeout(function () {
        window.location.replace(redirectUrl);
      }, 160);
    })();
  </script>
</body>
</html>
HTML;
    exit;
}

function panda_action_extract_errand_id($response): ?int
{
    if (function_exists('musky_nora_extract_errand_id')) {
        return musky_nora_extract_errand_id($response);
    }

    if (!is_array($response)) {
        return null;
    }

    return null;
}

function panda_action_resolve_ticket_guid(PDO $pdo, string $ticketRef): string
{
    $ticketRef = trim($ticketRef);
    if ($ticketRef === '') {
        return '';
    }

    if (ctype_digit($ticketRef)) {
        $st = $pdo->prepare("
            SELECT TicketId
            FROM IIQTicketTidBits
            WHERE TicketNumber = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([(int)$ticketRef]);
        return trim((string)$st->fetchColumn());
    }

    $st = $pdo->prepare("
        SELECT TicketId
        FROM IIQTicketTidBits
        WHERE TicketId = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$ticketRef]);
    $guid = trim((string)$st->fetchColumn());
    if ($guid !== '') {
        return $guid;
    }

    if (preg_match('/^[0-9a-fA-F-]{30,}$/', $ticketRef)) {
        return $ticketRef;
    }

    return '';
}

function panda_action_hold_reject_ticket_note(array $charge, string $actorEmail): string
{
    $chargeId = (int)($charge['ChargeID'] ?? 0);
    $why = trim((string)($charge['PartReplacedWhy'] ?? '')) ?: 'UNKNOWN';
    $partCode = trim((string)($charge['PartCode'] ?? '')) ?: 'UNKNOWN';
    $partDesc = trim((string)($charge['PartDescription'] ?? '')) ?: 'UNKNOWN';
    $adminNote = trim((string)($charge['AdminNote'] ?? ''));

    $lines = [];
    $lines[] = "PANDA charge #{$chargeId} was rejected from HOLD by {$actorEmail}.";
    $lines[] = "Part: {$partCode} ({$partDesc}).";
    $lines[] = "Why: {$why}.";
    $lines[] = "Local HDK coordinator follow-up with the student is required before a new submission.";
    if ($adminNote !== '') {
        $lines[] = "PANDA Admin Note: {$adminNote}";
    }

    return implode("\n", $lines);
}

function panda_action_build_coverage_context(PDO $pdo, array $charge): array
{
    $ctx = ['skyward_row' => null];
    $hasInsurance = strtoupper(trim((string)($charge['HasInsurance'] ?? 'UNKNOWN')));
    if ($hasInsurance !== 'YES') {
        return $ctx;
    }

    $ownerId = (int)($charge['OwnerRowID'] ?? 0);
    $coverageYear = panda_schoolyear_to_coverage_year($charge['SchoolYear'] ?? null);
    if ($ownerId <= 0 || !$coverageYear) {
        return $ctx;
    }

    $st = $pdo->prepare("
        SELECT id, CoverageYear, HasCoverage, CoveragePaid, created_at
        FROM Skyward_ThisnThat
        WHERE owner_id = ? AND CoverageYear = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$ownerId, (string)$coverageYear]);
    $ctx['skyward_row'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    return $ctx;
}

function panda_action_is_final_status(string $status): bool
{
    $status = strtolower(trim($status));
    return in_array($status, [
        'approved',
        'claim_approved',
        'waived',
        'denied',
        'rejected',
        'other_approved',
    ], true);
}

function panda_action_status_label(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'approved'       => 'APPROVED',
        'claim_approved' => 'USE GSD TPP (CLAIM_APPROVED)',
        'waived'         => 'WAIVED',
        'denied'         => 'DENIED',
        'rejected'       => 'REJECTED',
        'other_approved' => 'OTHER APPROVAL',
        'submitted'      => 'SUBMITTED',
        'hold'           => 'HOLD',
        default          => strtoupper($status ?: 'UNKNOWN'),
    };
}

function panda_action_deny(string $message, int $statusCode = 403): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function panda_action_has_paid_coverage(?array $skywardRow): bool
{
    if (!$skywardRow || (int)($skywardRow['HasCoverage'] ?? 0) !== 1) {
        return false;
    }

    $coveragePaid = trim((string)($skywardRow['CoveragePaid'] ?? ''));
    return !(strcasecmp($coveragePaid, 'True') === 0 || $coveragePaid === '1');
}

function panda_action_allowed_actions(
    array $charge,
    string $currentStatus,
    bool $canManageCharges,
    bool $isHoldCoordinator,
    ?array $coverageContext = null
): array {
    $allowed = [
        'approve' => false,
        'claim_approved' => false,
        'waive' => false,
        'hold' => false,
        'deny' => false,
        'reject' => false,
        'other_approved' => false,
        'escalate' => false,
    ];

    $isMutable = in_array($currentStatus, ['submitted', 'hold'], true);
    $isHoldStatus = ($currentStatus === 'hold');
    if (!$canManageCharges || !$isMutable) {
        return $allowed;
    }

    $ownerEmail = strtolower(trim((string)($charge['OwnerEmail'] ?? '')));
    $why = trim((string)($charge['PartReplacedWhy'] ?? ''));
    $isStaff = ($ownerEmail !== '' && musky_email_is_staff($ownerEmail));
    $isOemFailure = ($why === 'OEM Failure');
    $hasDistrictId = trim((string)($charge['OwnerDistrictId'] ?? '')) !== '';

    if ($isHoldCoordinator) {
        if ($isHoldStatus) {
            $allowed['waive'] = true;
            $allowed['reject'] = true;
            $allowed['escalate'] = true;
        }
        return $allowed;
    }

    $allowed['hold'] = true;
    $allowed['deny'] = true;
    $allowed['reject'] = true;

    if (!$isOemFailure) {
        $allowed['waive'] = true;
    }

    $canBill = !$isStaff && !$isOemFailure && $hasDistrictId;
    if ($canBill) {
        $allowed['approve'] = true;
        $allowed['other_approved'] = true;
    }

    $hasInsurance = strtoupper(trim((string)($charge['HasInsurance'] ?? 'UNKNOWN'))) === 'YES';
    if ($canBill && $hasInsurance && panda_action_has_paid_coverage($coverageContext['skyward_row'] ?? null)) {
        $allowed['claim_approved'] = true;
    }

    return $allowed;
}

function panda_action_build_completion_slack_message(
    array $charge,
    string $finalStatus,
    float $possibleTotal,
    float $endingCharge,
    string $resolverEmail
): string {
    $statusLabel = panda_action_status_label($finalStatus);
    $chargeId = (int)($charge['ChargeID'] ?? 0);
    $ticket = trim((string)($charge['TicketID'] ?? '')) ?: 'UNKNOWN';
    $asset = trim((string)($charge['DeviceAssetTag'] ?? '')) ?: 'UNKNOWN';
    $owner = trim((string)($charge['OwnerName'] ?? '')) ?: (trim((string)($charge['OwnerEmail'] ?? '')) ?: 'UNKNOWN');
    $partCode = trim((string)($charge['PartCode'] ?? '')) ?: 'UNKNOWN';
    $partDesc = trim((string)($charge['PartDescription'] ?? ''));
    $partsLabel = $partDesc !== '' ? "{$partCode} ({$partDesc})" : $partCode;

    return implode("\n", [
        "PANDA charge request completed - {$statusLabel}",
        "Charge: #{$chargeId}",
        "Ticket: {$ticket}",
        "Asset: {$asset}",
        "Owner: {$owner}",
        "Possible Total: $" . number_format($possibleTotal, 2, '.', ''),
        "Ending Charges: $" . number_format($endingCharge, 2, '.', ''),
        "Charge Final Status: {$statusLabel}",
        "Resolver of Charge: {$resolverEmail}",
        "Parts: {$partsLabel}",
    ]);
}

function panda_action_send_completion_slack(
    PDO $pdo,
    array $charge,
    string $finalStatus,
    float $possibleTotal,
    float $endingCharge,
    string $resolverEmail
): void {
    if (!panda_action_is_final_status($finalStatus)) {
        return;
    }

    $chargeId = (int)($charge['ChargeID'] ?? 0);
    if ($chargeId <= 0) {
        return;
    }

    $message = panda_action_build_completion_slack_message(
        $charge,
        $finalStatus,
        $possibleTotal,
        $endingCharge,
        $resolverEmail
    );

    $resp = musky_nora_api_post_json('/errand/create', [
        'serial'    => 'N/A',
        'udid'      => 'SYSTEM_TASK',
        'submitter' => $resolverEmail,
        'owner'     => trim((string)($charge['OwnerEmail'] ?? '')) ?: null,
        'priority'  => 3,
        'slack'     => 'TRUE',
        'extra1'    => json_encode(['custom_message' => $message], JSON_UNESCAPED_SLASHES),
    ]);

    $errandId = panda_action_extract_errand_id($resp);
    if ($errandId) {
        panda_log_charge_comment(
            $pdo,
            $chargeId,
            $resolverEmail,
            "Final status Slack notification queued (ErrandID {$errandId})."
        );
    } else {
        panda_log_charge_comment(
            $pdo,
            $chargeId,
            $resolverEmail,
            "Final status Slack notification failed to queue."
        );
    }
}

// ============================================================================
//  USER + CHARGE LOADING
// ============================================================================
$prefs = musky_get_logged_in_user_prefs();
$allowed = $prefs['allowed_tools'] ?? [];
$email = $prefs['email'] ?? 'PANDA_Action';
$isHoldCoordinator = panda_user_is_hold_coordinator($allowed);
$canManageCharges = panda_user_can_manage_charges($allowed);

panda_require_charge_manage_access($allowed, 'html');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    musky_csrf_require('panda_charge_decision');
}

$pdo       = panda_db();
$chargeId  = (int)($_POST['ChargeID'] ?? 0);
$action    = trim($_POST['action'] ?? '');

if ($chargeId <= 0 || $action === '') {
    die("Missing ChargeID or action");
}

if (!panda_charge_lock_acquire($pdo, $chargeId, 3)) {
    panda_action_deny('This charge is already being processed by another request.', 409);
}

register_shutdown_function(static function () use ($pdo, $chargeId): void {
    panda_charge_lock_release($pdo, $chargeId);
});

$charge = panda_load_charge_full($pdo, $chargeId);
if (!$charge) die("Invalid ChargeID");

// ============================================================================
//  BASIC RULES / RESTRICTIONS
// ============================================================================
$ownerEmail      = strtolower($charge['OwnerEmail'] ?? '');
$why             = $charge['PartReplacedWhy'] ?? '';
$districtId      = trim((string)$charge['OwnerDistrictId'] ?? '');
$qty             = (int)$charge['Quantity'];
$existingErrand  = $charge['SkywardErrandID'] ?? null;

$isStaff         = musky_email_is_staff($ownerEmail);
$reasonOEM       = ($why === 'OEM Failure');
$missingDistrict = ($districtId === '' || $districtId === null);

// NEVER charge more than 1
if ($qty !== 1) {
    die("Invalid quantity; cannot charge more than 1 item per transaction.");
}

$currentStatus = strtolower(trim((string)($charge['Status'] ?? '')));
if (!in_array($currentStatus, ['submitted', 'hold'], true)) {
    die("Charge status '{$charge['Status']}' is locked. Only submitted or hold charges can be changed.");
}

if ($isHoldCoordinator) {
    if ($currentStatus !== 'hold') {
        die("HOLD coordinators may only process charges currently in HOLD.");
    }

    $normalizedAction = strtolower(trim((string)$action));
    if ($normalizedAction === 'submit') {
        $normalizedAction = 'escalate';
    }

    if (!in_array($normalizedAction, ['waive', 'reject', 'escalate'], true)) {
        die("HOLD coordinators may only Waive, Reject, or Escalate HOLD charges.");
    }

    $oldStatus = (string)($charge['Status'] ?? 'hold');
    if ($normalizedAction === 'reject') {
        $newStatus = 'rejected';
    } elseif ($normalizedAction === 'waive') {
        $newStatus = 'waived';
    } else {
        $newStatus = $reasonOEM ? 'waived' : 'submitted';
    }

    $pdo->prepare("
        UPDATE panda_charges
           SET Status = :status,
               DecidedAt = NOW(),
               DecidedBy = :actor
         WHERE ChargeID = :cid
    ")->execute([
        ':status' => $newStatus,
        ':actor'  => $email,
        ':cid'    => $chargeId,
    ]);

    if ($normalizedAction === 'escalate' && $reasonOEM) {
        $statusNote = 'HOLD coordinator Escalate converted automatically to WAIVED because Why=OEM Failure.';
    } elseif ($normalizedAction === 'escalate') {
        $statusNote = 'HOLD coordinator escalated charge back to SUBMITTED.';
    } elseif ($normalizedAction === 'waive') {
        $statusNote = 'HOLD coordinator waived charge from HOLD.';
    } else {
        $statusNote = 'HOLD coordinator rejected charge from HOLD.';
    }

    panda_log_charge_status_change(
        $pdo,
        $chargeId,
        $oldStatus,
        $newStatus,
        $email,
        $statusNote
    );

    if ($normalizedAction === 'reject') {
        $ticketRef = trim((string)($charge['TicketID'] ?? ''));
        $ticketGuid = panda_action_resolve_ticket_guid($pdo, $ticketRef);
        $ticketTarget = $ticketGuid !== '' ? $ticketGuid : $ticketRef;
        $ticketNoteErrandId = null;

        if ($ticketTarget !== '') {
            $ticketNoteText = panda_action_hold_reject_ticket_note($charge, $email);
            $ticketResp = musky_nora_api_post_json('/errand/create', [
                'serial'    => 'N/A',
                'udid'      => 'SYSTEM_TASK',
                'submitter' => $email,
                'owner'     => trim((string)($charge['OwnerEmail'] ?? '')) ?: null,
                'priority'  => 5,
                'iiq'       => 'TRUE',
                'extra1'    => $ticketNoteText,
                'extra2'    => $ticketTarget,
                'extra5'    => 'ADDNOTE-CUSTOM',
            ]);
            $ticketNoteErrandId = panda_action_extract_errand_id($ticketResp);
        }

        $workflowNotes = [];
        if ($ticketRef === '') {
            $workflowNotes[] = "Ticket note skipped because Charge.TicketID was blank.";
        } elseif ($ticketGuid === '') {
            $workflowNotes[] = $ticketNoteErrandId
                ? "IIQ ticket note queued (ErrandID {$ticketNoteErrandId}) using unresolved ticket reference '{$ticketRef}'."
                : "IIQ ticket note failed to queue using unresolved ticket reference '{$ticketRef}'.";
        } else {
            $workflowNotes[] = $ticketNoteErrandId
                ? "IIQ ticket note queued (ErrandID {$ticketNoteErrandId}) for TicketId {$ticketGuid}."
                : "IIQ ticket note failed to queue for TicketId {$ticketGuid}.";
        }

        panda_log_charge_comment($pdo, $chargeId, $email, implode(' ', $workflowNotes));
    }

    if (panda_action_is_final_status($newStatus)) {
        $endingCharge = ($newStatus === 'waived' || $newStatus === 'rejected' || $newStatus === 'denied') ? 0.00 : (float)($charge['TotalCost'] ?? 0);
        panda_action_send_completion_slack(
            $pdo,
            $charge,
            $newStatus,
            (float)($charge['TotalCost'] ?? 0),
            $endingCharge,
            $email
        );
    }

    panda_action_finish_and_close($chargeId, 'Decision saved.');
}

// ============================================================================
//  MAP ACTION → SQL ENUM
// ============================================================================
$statusMap = [
    'approve'        => 'approved',
    'claim_approved' => 'claim_approved',
    'waive'          => 'waived',
    'hold'           => 'hold',
    'deny'           => 'denied',
    'reject'         => 'rejected',
    'other_approved' => 'other_approved'
];

if (!isset($statusMap[$action])) {
    die("Unknown action: {$action}");
}

$newStatus = $statusMap[$action];
$coverageContext = panda_action_build_coverage_context($pdo, $charge);
$allowedActions = panda_action_allowed_actions($charge, $currentStatus, $canManageCharges, $isHoldCoordinator, $coverageContext);
if (empty($allowedActions[$action])) {
    panda_action_deny("Action '{$action}' is not allowed for this charge.");
}

// ============================================================================
//  HOLD RECOVERY — ONLY if existing errand is present
// ============================================================================
if ($existingErrand) {

    $statusResp = musky_nora_api_get_json("/errand/status/{$existingErrand}");
    $curr = function_exists('musky_nora_extract_status')
        ? musky_nora_extract_status($statusResp)
        : strtolower((string)($statusResp['Status'] ?? ''));

    if ($curr === 'complete') {

        $pdo->prepare("
            UPDATE panda_charges
               SET Status='approved',
                   DecidedAt=NOW(),
                   DecidedBy=?
             WHERE ChargeID=?
        ")->execute([$email, $chargeId]);

        panda_log_charge_status_change(
            $pdo,
            $chargeId,
            $charge['Status'],
            'approved',
            $email,
            "Existing Skyward errand {$existingErrand} completed — auto-approved."
        );

        $coverageContext = panda_action_build_coverage_context($pdo, $charge);
        $coverageQuote = panda_compute_coverage_for_charge($pdo, $charge, $coverageContext);
        $endingCharge = round((float)($coverageQuote['totalChargeAmount'] ?? (float)$charge['TotalCost']), 2);
        panda_action_send_completion_slack(
            $pdo,
            $charge,
            'approved',
            (float)($charge['TotalCost'] ?? 0),
            $endingCharge,
            $email
        );

        panda_action_finish_and_close($chargeId, 'Charge approved using existing completed errand.');
    }

    // Failed types → remain HOLD, do not re-charge
    if (in_array($curr, ['failed','rejected','cancelled','unknown'])) {
        panda_action_finish_and_close($chargeId, "Existing errand status is {$curr}; charge remains unchanged.");
    }

    // Still pending — leave it in HOLD and exit
    panda_action_finish_and_close($chargeId, "Existing errand is still pending ({$curr}); charge remains unchanged.");
}

// ============================================================================
//  DETERMINE IF CHARGE SHOULD HIT SKYWARD
// ============================================================================
$requiresCharge =
    in_array($action, ['approve', 'claim_approved', 'other_approved']) &&
    !$reasonOEM &&
    !$isStaff &&
    !$missingDistrict;

if ($action === 'claim_approved' || $action === 'approve') {
    $coverageQuote = panda_compute_coverage_for_charge($pdo, $charge, $coverageContext);
    $finalChargeAmount = round((float)($coverageQuote['totalChargeAmount'] ?? (float)$charge['TotalCost']), 2);
}
elseif ($action === 'waive') {
    $finalChargeAmount = 0.00;
}
elseif ($action === 'other_approved') {
    $maxChargeAmount = round(max(0.0, (float)($charge['TotalCost'] ?? 0.0)), 2);
    if (array_key_exists('custom_amount', $_POST)) {
        $rawCustomAmount = $_POST['custom_amount'];
        if (!is_scalar($rawCustomAmount)) {
            panda_action_deny('Custom amount must be a valid number.');
        }
        $rawCustomAmount = trim((string)$rawCustomAmount);
        if ($rawCustomAmount === '' || !is_numeric($rawCustomAmount)) {
            panda_action_deny('Custom amount must be a valid number.');
        }
        $finalChargeAmount = round((float)$rawCustomAmount, 2);
    } else {
        $finalChargeAmount = $maxChargeAmount;
    }

    if ($finalChargeAmount < 0 || $finalChargeAmount > $maxChargeAmount) {
        panda_action_deny(
            'Custom amount must be between $0.00 and $' . number_format($maxChargeAmount, 2, '.', '') . '.'
        );
    }
}
elseif ($action === 'deny' || $action === 'reject' || $action === 'hold') {
    $finalChargeAmount = 0.00;
}
else {
    $finalChargeAmount = (float)$charge['TotalCost'];
}

$isVandal = ($why === 'Vandalism');

// FeeCodes
if ($reasonOEM || $isStaff) {
    $feeCode = null;
} else {
    if ($action === 'claim_approved') {
        $feeCode = 'TECHABUS'; // insurance, always TECHABUS
    } else {
        $feeCode = $isVandal ? 'TECHABUS' : 'TECHDAMA';
    }
}

// ============================================================================
//  BUILD SKYWARD COMMENT
// ============================================================================
function panda_build_charge_comment(array $charge, string $why, float $amount, string $action): string {
    $ticket = $charge['TicketID'] ?: 'N/A';
    $asset  = $charge['DeviceAssetTag'] ?: 'N/A';
    $part   = $charge['PartDescription'];
    $code   = $charge['PartCode'];
    $action = trim(strtolower($action));
    $pathLabel = match ($action) {
        'approve'        => 'APPROVE',
        'claim_approved' => 'GSD_TPP',
        'other_approved' => 'OTHER_APPROVAL',
        'waive'          => 'WAIVE',
        default          => strtoupper($action ?: 'UNKNOWN'),
    };

    $middle = '';

    switch ($why) {
        case 'Vandalism':
            $middle = "In cases of abuse/vandalism, student is charged 100% of repair.";
            break;

        case 'OEM Failure':
            $middle = "OEM failure—no student liability.";
            break;
    }

    if ($action === 'claim_approved') {
        $middle = "Insurance Savings: The GSD TPP covered this repair.";
    }

    $adminNote = trim((string)$charge['AdminNote'] ?? '');

    $comment =
        "Ticket: {$ticket} / GSD Asset Tag: {$asset}\n" .
        "Part Used: {$part} ({$code})\n" .
        "Use Code: {$why}\n" .
        "Decision Path: {$pathLabel}\n" .
        "Charge Amount Submitted: $" . number_format($amount, 2, '.', '') . "\n\n";

    if ($middle !== '') $comment .= $middle . "\n\n";

    if ($adminNote !== '') {
        $comment .= "Admin Note: {$adminNote}";
    }

    return $comment;
}

$skywardComment = panda_build_charge_comment($charge, $why, $finalChargeAmount, $action);

// ============================================================================
//  UPDATE LOCAL PANDA STATUS FIRST
// ============================================================================
// Snapshot insurance state at decision time (for history logging)
$hasInsuranceSnapshot = strtoupper(trim((string)($charge['HasInsurance'] ?? 'UNKNOWN')));

$oldStatus = $charge['Status'];

$upd = $pdo->prepare("
    UPDATE panda_charges
       SET Status = :s,
           DecidedAt = NOW(),
           DecidedBy = :by
     WHERE ChargeID = :cid
");
$upd->execute([
    ':s'   => $newStatus,
    ':by'  => $email,
    ':cid' => $chargeId
]);

panda_log_charge_status_change(
    $pdo,
    $chargeId,
    $oldStatus,
    $newStatus,
    $email,
    "Charge moved to {$newStatus}. FinalAmount={$finalChargeAmount}. [INSURANCE SNAPSHOT] HasInsurance={$hasInsuranceSnapshot} at decision time."
);

// ============================================================================
//  CREATE SKYWARD ERRAND (ONLY IF NEEDED & no existing errand)
// ============================================================================
if ($requiresCharge && $feeCode !== null) {

    $payload = [
        "serial"        => "N/A",
        "udid"          => "N/A",
        "submitter"     => $email,
        "priority"      => 5,

        "custom"        => "SkywardFees",
        "extra1"        => "create",
        "extra2" => [
            "StudentNumber" => $districtId,
            "FeeCode"       => $feeCode,
            "Amount"        => $finalChargeAmount,
            "Comment"       => $skywardComment
        ],
    ];

    $resp = musky_nora_api_post_json('/errand/create', $payload);
    $errandId = panda_action_extract_errand_id($resp);

    // FAIL?
    if (!is_array($resp) || !$errandId) {

        $pdo->prepare("
            UPDATE panda_charges
               SET Status='hold'
             WHERE ChargeID=?
        ")->execute([$chargeId]);

        panda_log_charge_status_change(
            $pdo,
            $chargeId,
            $newStatus,
            'hold',
            $email,
            "Skyward Fee API failed — auto HOLD. Nora error: " . (is_array($resp) && isset($resp['error']) ? (string)$resp['error'] : 'unknown')
        );

        die("<script>alert('Skyward Fee API failed. Charge placed on HOLD.'); window.location='PANDA_ChargeDecision.php?ChargeID={$chargeId}';</script>");
    }

    // SUCCESS → store errand ID to prevent double charges
    $pdo->prepare("
        UPDATE panda_charges
           SET SkywardErrandID=?
         WHERE ChargeID=?
    ")->execute([$errandId, $chargeId]);
}

if (panda_action_is_final_status($newStatus)) {
    if (in_array($newStatus, ['waived', 'denied', 'rejected'], true)) {
        $endingCharge = 0.00;
    } elseif (in_array($newStatus, ['approved', 'claim_approved', 'other_approved'], true)) {
        $endingCharge = ($requiresCharge && $feeCode !== null)
            ? round((float)$finalChargeAmount, 2)
            : 0.00;
    } else {
        $endingCharge = round((float)$finalChargeAmount, 2);
    }
    panda_action_send_completion_slack(
        $pdo,
        $charge,
        $newStatus,
        (float)($charge['TotalCost'] ?? 0),
        $endingCharge,
        $email
    );
}

// ============================================================================
//  DONE
// ============================================================================
panda_action_finish_and_close($chargeId, 'Decision saved.');

?>
