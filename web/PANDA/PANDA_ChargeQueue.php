<?php
// ============================================================================
// PANDA_ChargeQueue.php
// ----------------------------------------------------------------------------
// Display PANDA charges for review.
// - Standard managers see active decision queue statuses (submitted/hold).
// - HOLD coordinators can research pending/completed statuses, but may only act on HOLD in the decision page.
//
// NEW IN THIS VERSION:
//   • AUTO-RECHECK SKYWARD INSURANCE FOR ANY CHARGE NOT MARKED "YES"
//     (Same logic as ChargeDecision Stage B)
//   • Immediately updates panda_charges.HasInsurance on page load
//
// This ensures ALL queued charges always display the correct,
// up-to-date insurance information.
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/PANDA_Functions.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

panda_require_charges_enabled(isset($_GET['api']) ? 'json' : 'html');

// -----------------------------------------------------------------------------
// User prefs
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$theme   = $prefs['theme'];
$allowed = $prefs['allowed_tools'];
$email   = $prefs['email'];
$csrfToken = musky_csrf_token('panda_charge_queue');
$isHoldCoordinator = panda_user_is_hold_coordinator($allowed);
$previewHoldCoordinator = !$isHoldCoordinator
    && panda_user_can_manage_charges($allowed)
    && (($_GET['preview_hold_mode'] ?? '') === '1');
$isEffectiveHoldCoordinator = $isHoldCoordinator || $previewHoldCoordinator;

// -----------------------------------------------------------------------------
// Access control
// -----------------------------------------------------------------------------
if (isset($_GET['api'])) {
    panda_require_charge_manage_access($allowed, 'json');
    if ($isEffectiveHoldCoordinator) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'HOLD coordinators cannot run PANDA queue bulk APIs.'
        ]);
        exit;
    }
}

panda_require_charge_view_access($allowed, 'html');

$canManageCharges = panda_user_can_manage_charges($allowed);
$canAdminCharges = panda_user_can_admin_charges($allowed);
$canBulkQueueActions = $canManageCharges && !$isEffectiveHoldCoordinator;
$columnCount = $canBulkQueueActions ? 16 : 15;
$queueStatusSql = $isEffectiveHoldCoordinator
    ? "'submitted','hold','approved','other_approved','claim_approved','waived','denied','rejected','cancelled'"
    : "'submitted','hold'";

// -----------------------------------------------------------------------------
// DB connection
// -----------------------------------------------------------------------------
$pdo = panda_db();

function panda_queue_build_charge_comment(array $charge, string $why): string {
    $ticket = $charge['TicketID'] ?: 'N/A';
    $asset  = $charge['DeviceAssetTag'] ?: 'N/A';
    $part   = $charge['PartDescription'];
    $code   = $charge['PartCode'];
    $adminNote = trim((string)($charge['AdminNote'] ?? ''));

    $middle = '';
    if ($why === 'Vandalism') {
        $middle = "In cases of abuse/vandalism, student is charged 100% of repair.";
    } elseif ($why === 'OEM Failure') {
        $middle = "OEM failure—no student liability.";
    }

    $comment =
        "Ticket: {$ticket} / GSD Asset Tag: {$asset}\n" .
        "Part Used: {$part} ({$code})\n" .
        "Use Code: {$why}\n\n";

    if ($middle !== '') {
        $comment .= $middle . "\n\n";
    }

    if ($adminNote !== '') {
        $comment .= "Admin Note: {$adminNote}";
    }

    return $comment;
}

function panda_queue_build_coverage_context(PDO $pdo, array $charge, array &$skywardCache = []): array {
    $ctx = ['skyward_row' => null];
    $hasInsurance = strtoupper(trim((string)($charge['HasInsurance'] ?? 'UNKNOWN')));
    if ($hasInsurance !== 'YES') {
        return $ctx;
    }

    $ownerId = (int)($charge['OwnerRowID'] ?? ($charge['OwnerID'] ?? 0));
    $coverageYear = panda_schoolyear_to_coverage_year($charge['SchoolYear'] ?? null);
    if ($ownerId <= 0 || !$coverageYear) {
        return $ctx;
    }

    $cacheKey = $ownerId . '|' . $coverageYear;
    if (!array_key_exists($cacheKey, $skywardCache)) {
        $st = $pdo->prepare("
            SELECT id, CoverageYear, HasCoverage, CoveragePaid, created_at
            FROM Skyward_ThisnThat
            WHERE owner_id = ? AND CoverageYear = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$ownerId, (string)$coverageYear]);
        $skywardCache[$cacheKey] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $ctx['skyward_row'] = $skywardCache[$cacheKey];
    return $ctx;
}

function panda_queue_estimate_approve_amount(PDO $pdo, array $charge, array &$skywardCache = []): array {
    $ctx = panda_queue_build_coverage_context($pdo, $charge, $skywardCache);
    $quote = panda_compute_coverage_for_charge($pdo, $charge, $ctx);
    $amount = round((float)($quote['totalChargeAmount'] ?? (float)($charge['TotalCost'] ?? 0)), 2);
    return [
        'amount' => $amount,
        'quote'  => $quote,
    ];
}

function panda_queue_bulk_approve_vandal_charge(PDO $pdo, array $charge, string $actorEmail): array {
    $chargeId = (int)($charge['ChargeID'] ?? 0);
    $ownerEmail = strtolower(trim((string)($charge['OwnerEmail'] ?? '')));
    $why = trim((string)($charge['PartReplacedWhy'] ?? ''));
    $districtId = trim((string)($charge['OwnerDistrictId'] ?? ''));
    $qty = (int)($charge['Quantity'] ?? 0);
    $existingErrand = $charge['SkywardErrandID'] ?? null;
    $status = trim((string)($charge['Status'] ?? ''));

    if ($chargeId <= 0) {
        return ['status' => 'skipped', 'detail' => 'Invalid charge ID'];
    }
    if ($why !== 'Vandalism') {
        return ['status' => 'skipped', 'detail' => 'Charge is not marked Vandalism'];
    }
    if ($districtId === '') {
        return ['status' => 'skipped', 'detail' => 'Owner missing District ID'];
    }
    if ($qty !== 1) {
        return ['status' => 'skipped', 'detail' => 'Quantity must equal 1'];
    }
    if ($ownerEmail !== '' && musky_email_is_staff($ownerEmail)) {
        return ['status' => 'skipped', 'detail' => 'Staff charges are not auto-approved'];
    }
    if (!in_array($status, ['submitted', 'hold'], true)) {
        return ['status' => 'skipped', 'detail' => "Status {$status} is not eligible"];
    }

    if (!panda_charge_lock_acquire($pdo, $chargeId, 1)) {
        return ['status' => 'skipped', 'detail' => 'Charge is already being processed by another request'];
    }

    try {
        $charge = panda_load_charge_full($pdo, $chargeId);
        if (!$charge) {
            return ['status' => 'skipped', 'detail' => 'Charge not found'];
        }

        $ownerEmail = strtolower(trim((string)($charge['OwnerEmail'] ?? '')));
        $why = trim((string)($charge['PartReplacedWhy'] ?? ''));
        $districtId = trim((string)($charge['OwnerDistrictId'] ?? ''));
        $qty = (int)($charge['Quantity'] ?? 0);
        $existingErrand = $charge['SkywardErrandID'] ?? null;
        $status = trim((string)($charge['Status'] ?? ''));

        if ($why !== 'Vandalism') {
            return ['status' => 'skipped', 'detail' => 'Charge is not marked Vandalism'];
        }
        if ($districtId === '') {
            return ['status' => 'skipped', 'detail' => 'Owner missing District ID'];
        }
        if ($qty !== 1) {
            return ['status' => 'skipped', 'detail' => 'Quantity must equal 1'];
        }
        if ($ownerEmail !== '' && musky_email_is_staff($ownerEmail)) {
            return ['status' => 'skipped', 'detail' => 'Staff charges are not auto-approved'];
        }
        if (!in_array($status, ['submitted', 'hold'], true)) {
            return ['status' => 'skipped', 'detail' => "Status {$status} is not eligible"];
        }

        if ($existingErrand) {
            $statusResp = musky_nora_api_get_json("/errand/status/{$existingErrand}");
            $curr = musky_nora_extract_status($statusResp);

            if ($curr === 'complete') {
                $pdo->prepare("
                    UPDATE panda_charges
                       SET Status='approved',
                           DecidedAt=NOW(),
                           DecidedBy=?
                     WHERE ChargeID=?
                ")->execute([$actorEmail, $chargeId]);

                panda_log_charge_status_change(
                    $pdo,
                    $chargeId,
                    $charge['Status'],
                    'approved',
                    $actorEmail,
                    "Existing Skyward errand {$existingErrand} completed — auto-approved from queue."
                );

                return [
                    'status' => 'approved_existing',
                    'detail' => "Existing errand {$existingErrand} already complete",
                    'errand_id' => $existingErrand,
                ];
            }

            if (in_array($curr, ['failed','rejected','cancelled','unknown'], true)) {
                return [
                    'status' => 'skipped',
                    'detail' => "Existing errand {$existingErrand} status is {$curr}",
                    'errand_id' => $existingErrand,
                ];
            }

            return [
                'status' => 'skipped',
                'detail' => "Existing errand {$existingErrand} still pending ({$curr})",
                'errand_id' => $existingErrand,
            ];
        }

        $oldStatus = $charge['Status'];
        $pdo->prepare("
            UPDATE panda_charges
               SET Status = 'approved',
                   DecidedAt = NOW(),
                   DecidedBy = ?
             WHERE ChargeID = ?
        ")->execute([$actorEmail, $chargeId]);

        $hasInsuranceSnapshot = strtoupper(trim((string)($charge['HasInsurance'] ?? 'UNKNOWN')));
        panda_log_charge_status_change(
            $pdo,
            $chargeId,
            $oldStatus,
            'approved',
            $actorEmail,
            "Charge moved to approved from queue bulk vandalism approval. [INSURANCE SNAPSHOT] HasInsurance={$hasInsuranceSnapshot} at decision time."
        );

        $payload = [
            "serial"    => "N/A",
            "udid"      => "N/A",
            "submitter" => $actorEmail,
            "priority"  => 5,
            "custom"    => "SkywardFees",
            "extra1"    => "create",
            "extra2"    => [
                "StudentNumber" => $districtId,
                "FeeCode"       => "TECHABUS",
                "Amount"        => (float)$charge['TotalCost'],
                "Comment"       => panda_queue_build_charge_comment($charge, $why),
            ],
        ];

        $resp = musky_nora_api_post_json('/errand/create', $payload);
        $errandId = musky_nora_extract_errand_id($resp);
        if (!is_array($resp) || !$errandId) {
            $pdo->prepare("UPDATE panda_charges SET Status='hold' WHERE ChargeID=?")
                ->execute([$chargeId]);

            panda_log_charge_status_change(
                $pdo,
                $chargeId,
                'approved',
                'hold',
                $actorEmail,
                "Skyward Fee API failed during queue bulk approval — auto HOLD. Nora error: " . (is_array($resp) && isset($resp['error']) ? (string)$resp['error'] : 'unknown')
            );

            return [
                'status' => 'hold',
                'detail' => 'Skyward fee API failed; charge moved to HOLD',
            ];
        }

        $pdo->prepare("
            UPDATE panda_charges
               SET SkywardErrandID=?
             WHERE ChargeID=?
        ")->execute([$errandId, $chargeId]);

        return [
            'status' => 'approved',
            'detail' => 'Approved and Skyward fee errand created',
            'errand_id' => $errandId,
        ];
    } finally {
        panda_charge_lock_release($pdo, $chargeId);
    }
}

function panda_queue_bulk_approve_zero_charge(PDO $pdo, array $charge, string $actorEmail, array &$skywardCache = []): array {
    $chargeId = (int)($charge['ChargeID'] ?? 0);
    $ownerEmail = strtolower(trim((string)($charge['OwnerEmail'] ?? '')));
    $why = trim((string)($charge['PartReplacedWhy'] ?? ''));
    $districtId = trim((string)($charge['OwnerDistrictId'] ?? ''));
    $qty = (int)($charge['Quantity'] ?? 0);
    $existingErrand = $charge['SkywardErrandID'] ?? null;
    $status = trim((string)($charge['Status'] ?? ''));
    $isStaff = ($ownerEmail !== '' && musky_email_is_staff($ownerEmail));
    $isOem = ($why === 'OEM Failure');

    if ($chargeId <= 0) {
        return ['status' => 'skipped', 'detail' => 'Invalid charge ID'];
    }
    if ($qty !== 1) {
        return ['status' => 'skipped', 'detail' => 'Quantity must equal 1'];
    }
    if (!in_array($status, ['submitted', 'hold'], true)) {
        return ['status' => 'skipped', 'detail' => "Status {$status} is not eligible"];
    }
    if ($isOem) {
        return ['status' => 'skipped', 'detail' => 'OEM Failure must use waiver path, not approve'];
    }
    if ($isStaff) {
        return ['status' => 'skipped', 'detail' => 'Staff charges are not approvable'];
    }
    if ($districtId === '') {
        return ['status' => 'skipped', 'detail' => 'Owner missing District ID'];
    }

    $estimate = panda_queue_estimate_approve_amount($pdo, $charge, $skywardCache);
    $finalAmount = (float)($estimate['amount'] ?? 0.0);
    if (abs($finalAmount) > 0.0001) {
        return ['status' => 'skipped', 'detail' => 'Estimated final charge is not $0.00', 'amount' => $finalAmount];
    }

    if (!panda_charge_lock_acquire($pdo, $chargeId, 1)) {
        return ['status' => 'skipped', 'detail' => 'Charge is already being processed by another request', 'amount' => $finalAmount];
    }

    try {
        $charge = panda_load_charge_full($pdo, $chargeId);
        if (!$charge) {
            return ['status' => 'skipped', 'detail' => 'Charge not found', 'amount' => $finalAmount];
        }

        $ownerEmail = strtolower(trim((string)($charge['OwnerEmail'] ?? '')));
        $why = trim((string)($charge['PartReplacedWhy'] ?? ''));
        $districtId = trim((string)($charge['OwnerDistrictId'] ?? ''));
        $qty = (int)($charge['Quantity'] ?? 0);
        $existingErrand = $charge['SkywardErrandID'] ?? null;
        $status = trim((string)($charge['Status'] ?? ''));
        $isStaff = ($ownerEmail !== '' && musky_email_is_staff($ownerEmail));
        $isOem = ($why === 'OEM Failure');

        if ($qty !== 1) {
            return ['status' => 'skipped', 'detail' => 'Quantity must equal 1', 'amount' => $finalAmount];
        }
        if (!in_array($status, ['submitted', 'hold'], true)) {
            return ['status' => 'skipped', 'detail' => "Status {$status} is not eligible", 'amount' => $finalAmount];
        }
        if ($isOem) {
            return ['status' => 'skipped', 'detail' => 'OEM Failure must use waiver path, not approve', 'amount' => $finalAmount];
        }
        if ($isStaff) {
            return ['status' => 'skipped', 'detail' => 'Staff charges are not approvable', 'amount' => $finalAmount];
        }
        if ($districtId === '') {
            return ['status' => 'skipped', 'detail' => 'Owner missing District ID', 'amount' => $finalAmount];
        }

        $estimate = panda_queue_estimate_approve_amount($pdo, $charge, $skywardCache);
        $finalAmount = (float)($estimate['amount'] ?? 0.0);
        if (abs($finalAmount) > 0.0001) {
            return ['status' => 'skipped', 'detail' => 'Estimated final charge is not $0.00', 'amount' => $finalAmount];
        }

        if ($existingErrand) {
            $statusResp = musky_nora_api_get_json("/errand/status/{$existingErrand}");
            $curr = musky_nora_extract_status($statusResp);

            if ($curr === 'complete') {
                $pdo->prepare("
                    UPDATE panda_charges
                       SET Status='approved',
                           DecidedAt=NOW(),
                           DecidedBy=?
                     WHERE ChargeID=?
                ")->execute([$actorEmail, $chargeId]);

                panda_log_charge_status_change(
                    $pdo,
                    $chargeId,
                    $charge['Status'],
                    'approved',
                    $actorEmail,
                    "Existing Skyward errand {$existingErrand} completed — auto-approved from queue zero-dollar bulk action."
                );

                return [
                    'status' => 'approved_existing',
                    'detail' => "Existing errand {$existingErrand} already complete",
                    'errand_id' => $existingErrand,
                    'amount' => $finalAmount,
                ];
            }

            if (in_array($curr, ['failed','rejected','cancelled','unknown'], true)) {
                return [
                    'status' => 'skipped',
                    'detail' => "Existing errand {$existingErrand} status is {$curr}",
                    'errand_id' => $existingErrand,
                ];
            }

            return [
                'status' => 'skipped',
                'detail' => "Existing errand {$existingErrand} still pending ({$curr})",
                'errand_id' => $existingErrand,
            ];
        }

        $oldStatus = $charge['Status'];
        $pdo->prepare("
            UPDATE panda_charges
               SET Status = 'approved',
                   DecidedAt = NOW(),
                   DecidedBy = ?
             WHERE ChargeID = ?
        ")->execute([$actorEmail, $chargeId]);

        $hasInsuranceSnapshot = strtoupper(trim((string)($charge['HasInsurance'] ?? 'UNKNOWN')));
        panda_log_charge_status_change(
            $pdo,
            $chargeId,
            $oldStatus,
            'approved',
            $actorEmail,
            "Charge moved to approved from queue zero-dollar bulk approval. FinalAmount={$finalAmount}. [INSURANCE SNAPSHOT] HasInsurance={$hasInsuranceSnapshot} at decision time."
        );

        $feeCode = ($why === 'Vandalism') ? 'TECHABUS' : 'TECHDAMA';
        $payload = [
            "serial"    => "N/A",
            "udid"      => "N/A",
            "submitter" => $actorEmail,
            "priority"  => 5,
            "custom"    => "SkywardFees",
            "extra1"    => "create",
            "extra2"    => [
                "StudentNumber" => $districtId,
                "FeeCode"       => $feeCode,
                "Amount"        => $finalAmount,
                "Comment"       => panda_queue_build_charge_comment($charge, $why),
            ],
        ];

        $resp = musky_nora_api_post_json('/errand/create', $payload);
        $errandId = musky_nora_extract_errand_id($resp);
        if (!is_array($resp) || !$errandId) {
            $pdo->prepare("UPDATE panda_charges SET Status='hold' WHERE ChargeID=?")
                ->execute([$chargeId]);

            panda_log_charge_status_change(
                $pdo,
                $chargeId,
                'approved',
                'hold',
                $actorEmail,
                "Skyward Fee API failed during queue zero-dollar bulk approval — auto HOLD. Nora error: " . (is_array($resp) && isset($resp['error']) ? (string)$resp['error'] : 'unknown')
            );

            return [
                'status' => 'hold',
                'detail' => 'Skyward fee API failed; charge moved to HOLD',
                'amount' => $finalAmount,
            ];
        }

        $pdo->prepare("
            UPDATE panda_charges
               SET SkywardErrandID=?
             WHERE ChargeID=?
        ")->execute([$errandId, $chargeId]);

        return [
            'status' => 'approved',
            'detail' => 'Approved and Skyward fee errand created',
            'errand_id' => $errandId,
            'amount' => $finalAmount,
        ];
    } finally {
        panda_charge_lock_release($pdo, $chargeId);
    }
}

function panda_queue_bulk_waive_oem_charge(PDO $pdo, array $charge, string $actorEmail): array {
    $chargeId = (int)($charge['ChargeID'] ?? 0);
    $why = trim((string)($charge['PartReplacedWhy'] ?? ''));
    $districtId = trim((string)($charge['OwnerDistrictId'] ?? ''));
    $existingErrand = $charge['SkywardErrandID'] ?? null;
    $status = trim((string)($charge['Status'] ?? ''));

    if ($chargeId <= 0) {
        return ['status' => 'skipped', 'detail' => 'Invalid charge ID'];
    }
    if ($why !== 'OEM Failure') {
        return ['status' => 'skipped', 'detail' => 'Charge is not marked OEM Failure'];
    }
    if ($districtId === '') {
        return ['status' => 'skipped', 'detail' => 'Owner missing District ID'];
    }
    if (!in_array($status, ['submitted', 'hold'], true)) {
        return ['status' => 'skipped', 'detail' => "Status {$status} is not eligible"];
    }

    if (!panda_charge_lock_acquire($pdo, $chargeId, 1)) {
        return ['status' => 'skipped', 'detail' => 'Charge is already being processed by another request'];
    }

    try {
        $charge = panda_load_charge_full($pdo, $chargeId);
        if (!$charge) {
            return ['status' => 'skipped', 'detail' => 'Charge not found'];
        }

        $why = trim((string)($charge['PartReplacedWhy'] ?? ''));
        $districtId = trim((string)($charge['OwnerDistrictId'] ?? ''));
        $existingErrand = $charge['SkywardErrandID'] ?? null;
        $status = trim((string)($charge['Status'] ?? ''));

        if ($why !== 'OEM Failure') {
            return ['status' => 'skipped', 'detail' => 'Charge is not marked OEM Failure'];
        }
        if ($districtId === '') {
            return ['status' => 'skipped', 'detail' => 'Owner missing District ID'];
        }
        if (!in_array($status, ['submitted', 'hold'], true)) {
            return ['status' => 'skipped', 'detail' => "Status {$status} is not eligible"];
        }

        if ($existingErrand) {
            $statusResp = musky_nora_api_get_json("/errand/status/{$existingErrand}");
            $curr = musky_nora_extract_status($statusResp);

            if ($curr === 'complete') {
                return [
                    'status' => 'skipped',
                    'detail' => "Existing errand {$existingErrand} already completed; cannot bulk-waive cleanly",
                    'errand_id' => $existingErrand,
                ];
            }

            return [
                'status' => 'skipped',
                'detail' => "Existing errand {$existingErrand} present ({$curr}); not waived",
                'errand_id' => $existingErrand,
            ];
        }

        $oldStatus = $charge['Status'];
        $pdo->prepare("
            UPDATE panda_charges
               SET Status = 'waived',
                   DecidedAt = NOW(),
                   DecidedBy = ?
             WHERE ChargeID = ?
        ")->execute([$actorEmail, $chargeId]);

        $hasInsuranceSnapshot = strtoupper(trim((string)($charge['HasInsurance'] ?? 'UNKNOWN')));
        panda_log_charge_status_change(
            $pdo,
            $chargeId,
            $oldStatus,
            'waived',
            $actorEmail,
            "Charge waived from queue bulk OEM Failure action. [INSURANCE SNAPSHOT] HasInsurance={$hasInsuranceSnapshot} at decision time. No Skyward fee submitted."
        );

        return [
            'status' => 'waived',
            'detail' => 'Waived with history/note; no Skyward charge submitted',
        ];
    } finally {
        panda_charge_lock_release($pdo, $chargeId);
    }
}

// -----------------------------------------------------------------------------
// Inline API: bulk district ID lookup
// -----------------------------------------------------------------------------
if (isset($_GET['api']) && $_GET['api'] === 'bulk_district_lookup') {
    header('Content-Type: application/json');
    musky_csrf_require('panda_charge_queue');

    $input = json_decode(file_get_contents('php://input'), true);
    $chargeIds = $input['charge_ids'] ?? [];
    if (!is_array($chargeIds)) {
        $chargeIds = [];
    }

    $chargeIds = array_values(array_unique(array_filter(array_map('intval', $chargeIds), static fn($v) => $v > 0)));
    if (!$chargeIds) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'No valid charge IDs were provided.'
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($chargeIds), '?'));
    $st = $pdo->prepare("
        SELECT
            c.ChargeID,
            c.OwnerID,
            o.email       AS OwnerEmail,
            o.full_name   AS OwnerName,
            o.district_id AS OwnerDistrictId
        FROM panda_charges c
        JOIN owners o ON c.OwnerID = o.id
        WHERE c.ChargeID IN ($placeholders)
    ");
    $st->execute($chargeIds);
    $selectedRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    $seenOwners = [];
    $submitted = 0;
    $alreadyOnFile = 0;
    $missingEmail = 0;
    $failed = 0;

    foreach ($selectedRows as $row) {
        $chargeId = (int)($row['ChargeID'] ?? 0);
        $ownerId = (int)($row['OwnerID'] ?? 0);
        $ownerEmail = trim((string)($row['OwnerEmail'] ?? ''));
        $districtId = trim((string)($row['OwnerDistrictId'] ?? ''));

        if ($districtId !== '') {
            $alreadyOnFile++;
            $results[] = [
                'charge_id' => $chargeId,
                'owner_email' => $ownerEmail,
                'status' => 'on_file',
                'detail' => $districtId,
            ];
            continue;
        }

        if ($ownerEmail === '') {
            $missingEmail++;
            $results[] = [
                'charge_id' => $chargeId,
                'owner_email' => '',
                'status' => 'missing_email',
                'detail' => 'Owner email missing',
            ];
            continue;
        }

        $ownerKey = $ownerId > 0 ? "owner:$ownerId" : "email:" . strtolower($ownerEmail);
        if (isset($seenOwners[$ownerKey])) {
            $results[] = [
                'charge_id' => $chargeId,
                'owner_email' => $ownerEmail,
                'status' => 'duplicate_owner',
                'detail' => 'Owner already queued in this bulk request',
                'errand_id' => $seenOwners[$ownerKey]['errand_id'] ?? null,
            ];
            continue;
        }

        $payload = [
            'serial'    => 'N/A',
            'udid'      => 'SYSTEM_TASK',
            'submitter' => $email ?: 'PANDA_ChargeQueue',
            'nora'      => 'TRUE',
            'priority'  => 5,
            'extra1'    => 'IIQUSERSYNC',
            'extra2'    => $ownerEmail,
        ];

        $resp = musky_nora_api_post_json('/errand/create', $payload);
        $errandId = musky_nora_extract_errand_id($resp);

        if ($errandId) {
            $submitted++;
            $seenOwners[$ownerKey] = [
                'errand_id' => $errandId,
                'owner_email' => $ownerEmail,
            ];
            $results[] = [
                'charge_id' => $chargeId,
                'owner_email' => $ownerEmail,
                'status' => 'submitted',
                'detail' => 'IIQUSERSYNC submitted',
                'errand_id' => $errandId,
            ];
        } else {
            $failed++;
            $results[] = [
                'charge_id' => $chargeId,
                'owner_email' => $ownerEmail,
                'status' => 'failed',
                'detail' => is_array($resp)
                    ? ('Nora error: ' . (string)($resp['error'] ?? 'unknown'))
                    : 'No usable API response',
            ];
        }
    }

    musky_activity_log([
        'event_type' => 'ACTION',
        'action_name' => 'PANDA_BULK_IIQUSERSYNC',
        'page_path' => '/PANDA/PANDA_ChargeQueue.php',
        'extra' => [
            'charge_ids' => $chargeIds,
            'submitted' => $submitted,
            'already_on_file' => $alreadyOnFile,
            'missing_email' => $missingEmail,
            'failed' => $failed,
        ],
    ]);

    echo json_encode([
        'ok' => true,
        'summary' => [
            'selected' => count($chargeIds),
            'submitted' => $submitted,
            'already_on_file' => $alreadyOnFile,
            'missing_email' => $missingEmail,
            'failed' => $failed,
        ],
        'results' => $results,
    ]);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'bulk_approve_vandalism') {
    header('Content-Type: application/json');
    musky_csrf_require('panda_charge_queue');

    $input = json_decode(file_get_contents('php://input'), true);
    $chargeIds = $input['charge_ids'] ?? [];
    if (!is_array($chargeIds)) {
        $chargeIds = [];
    }

    $chargeIds = array_values(array_unique(array_filter(array_map('intval', $chargeIds), static fn($v) => $v > 0)));
    if (!$chargeIds) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'No valid charge IDs were provided.'
        ]);
        exit;
    }

    $results = [];
    $summary = [
        'selected' => count($chargeIds),
        'approved' => 0,
        'approved_existing' => 0,
        'hold' => 0,
        'skipped' => 0,
    ];

    foreach ($chargeIds as $chargeId) {
        $charge = panda_load_charge_full($pdo, $chargeId);
        if (!$charge) {
            $summary['skipped']++;
            $results[] = [
                'charge_id' => $chargeId,
                'status' => 'skipped',
                'detail' => 'Charge not found',
            ];
            continue;
        }

        $res = panda_queue_bulk_approve_vandal_charge($pdo, $charge, $email ?: 'PANDA_ChargeQueue');
        $res['charge_id'] = $chargeId;
        $res['owner_email'] = $charge['OwnerEmail'] ?? '';
        $results[] = $res;

        if (($res['status'] ?? '') === 'approved') {
            $summary['approved']++;
        } elseif (($res['status'] ?? '') === 'approved_existing') {
            $summary['approved_existing']++;
        } elseif (($res['status'] ?? '') === 'hold') {
            $summary['hold']++;
        } else {
            $summary['skipped']++;
        }
    }

    musky_activity_log([
        'event_type' => 'ACTION',
        'action_name' => 'PANDA_BULK_APPROVE_VANDALISM',
        'page_path' => '/PANDA/PANDA_ChargeQueue.php',
        'extra' => [
            'charge_ids' => $chargeIds,
            'summary' => $summary,
        ],
    ]);

    echo json_encode([
        'ok' => true,
        'summary' => $summary,
        'results' => $results,
    ]);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'bulk_approve_zero_dollar') {
    header('Content-Type: application/json');
    musky_csrf_require('panda_charge_queue');

    $input = json_decode(file_get_contents('php://input'), true);
    $chargeIds = $input['charge_ids'] ?? [];
    if (!is_array($chargeIds)) {
        $chargeIds = [];
    }

    $chargeIds = array_values(array_unique(array_filter(array_map('intval', $chargeIds), static fn($v) => $v > 0)));
    if (!$chargeIds) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'No valid charge IDs were provided.'
        ]);
        exit;
    }

    $results = [];
    $summary = [
        'selected' => count($chargeIds),
        'approved' => 0,
        'approved_existing' => 0,
        'hold' => 0,
        'skipped' => 0,
    ];
    $skywardCache = [];

    foreach ($chargeIds as $chargeId) {
        $charge = panda_load_charge_full($pdo, $chargeId);
        if (!$charge) {
            $summary['skipped']++;
            $results[] = [
                'charge_id' => $chargeId,
                'status' => 'skipped',
                'detail' => 'Charge not found',
            ];
            continue;
        }

        $res = panda_queue_bulk_approve_zero_charge($pdo, $charge, $email ?: 'PANDA_ChargeQueue', $skywardCache);
        $res['charge_id'] = $chargeId;
        $res['owner_email'] = $charge['OwnerEmail'] ?? '';
        $results[] = $res;

        if (($res['status'] ?? '') === 'approved') {
            $summary['approved']++;
        } elseif (($res['status'] ?? '') === 'approved_existing') {
            $summary['approved_existing']++;
        } elseif (($res['status'] ?? '') === 'hold') {
            $summary['hold']++;
        } else {
            $summary['skipped']++;
        }
    }

    musky_activity_log([
        'event_type' => 'ACTION',
        'action_name' => 'PANDA_BULK_APPROVE_ZERO_DOLLAR',
        'page_path' => '/PANDA/PANDA_ChargeQueue.php',
        'extra' => [
            'charge_ids' => $chargeIds,
            'summary' => $summary,
        ],
    ]);

    echo json_encode([
        'ok' => true,
        'summary' => $summary,
        'results' => $results,
    ]);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'bulk_waive_oem') {
    header('Content-Type: application/json');
    musky_csrf_require('panda_charge_queue');

    $input = json_decode(file_get_contents('php://input'), true);
    $chargeIds = $input['charge_ids'] ?? [];
    if (!is_array($chargeIds)) {
        $chargeIds = [];
    }

    $chargeIds = array_values(array_unique(array_filter(array_map('intval', $chargeIds), static fn($v) => $v > 0)));
    if (!$chargeIds) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'No valid charge IDs were provided.'
        ]);
        exit;
    }

    $results = [];
    $summary = [
        'selected' => count($chargeIds),
        'waived' => 0,
        'skipped' => 0,
    ];

    foreach ($chargeIds as $chargeId) {
        $charge = panda_load_charge_full($pdo, $chargeId);
        if (!$charge) {
            $summary['skipped']++;
            $results[] = [
                'charge_id' => $chargeId,
                'status' => 'skipped',
                'detail' => 'Charge not found',
            ];
            continue;
        }

        $res = panda_queue_bulk_waive_oem_charge($pdo, $charge, $email ?: 'PANDA_ChargeQueue');
        $res['charge_id'] = $chargeId;
        $res['owner_email'] = $charge['OwnerEmail'] ?? '';
        $results[] = $res;

        if (($res['status'] ?? '') === 'waived') {
            $summary['waived']++;
        } else {
            $summary['skipped']++;
        }
    }

    musky_activity_log([
        'event_type' => 'ACTION',
        'action_name' => 'PANDA_BULK_WAIVE_OEM',
        'page_path' => '/PANDA/PANDA_ChargeQueue.php',
        'extra' => [
            'charge_ids' => $chargeIds,
            'summary' => $summary,
        ],
    ]);

    echo json_encode([
        'ok' => true,
        'summary' => $summary,
        'results' => $results,
    ]);
    exit;
}

// ============================================================================
// AUTO-RECHECK INSURANCE FOR ALL CHARGES IN QUEUE
// (Same logic used in ChargeDecision; corrects UNKNOWN/NO → YES when applicable)
// ============================================================================

if ($canBulkQueueActions) {
    $recheckSql = "
        SELECT 
            c.ChargeID,
            c.OwnerID,
            c.SchoolYear,
            c.HasInsurance,
            o.email
        FROM panda_charges c
        JOIN owners o ON c.OwnerID = o.id
        WHERE c.Status IN ($queueStatusSql)
          AND c.HasInsurance <> 'YES'
    ";
    $recheck = $pdo->query($recheckSql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recheck as $r) {

        $ownerId    = (int)$r['OwnerID'];
        $chargeID   = (int)$r['ChargeID'];
        $schoolYear = trim($r['SchoolYear']);

        // normalize school year (ALWAYS returns YY-YY now)
        $coverageYear = panda_schoolyear_to_coverage_year($schoolYear);
        if (!$coverageYear) continue;

        // lookup Skyward row for this owner/year
        $st = $pdo->prepare("
            SELECT HasCoverage
            FROM Skyward_ThisnThat
            WHERE owner_id = ? AND CoverageYear = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$ownerId, $coverageYear]);
        $sky = $st->fetch(PDO::FETCH_ASSOC);

        if ($sky) {
            $hasCoverage = ((int)$sky['HasCoverage'] === 1);

            if ($hasCoverage && $r['HasInsurance'] !== 'YES') {
                // Promote to YES
                $pdo->prepare("UPDATE panda_charges SET HasInsurance='YES' WHERE ChargeID=?")
                    ->execute([$chargeID]);
            }
            elseif (!$hasCoverage && $r['HasInsurance'] !== 'NO') {
                // Demote to NO
                $pdo->prepare("UPDATE panda_charges SET HasInsurance='NO' WHERE ChargeID=?")
                    ->execute([$chargeID]);
            }

        } else {
            // No Skyward entry → absolutely no coverage
            if ($r['HasInsurance'] !== 'NO') {
                $pdo->prepare("UPDATE panda_charges SET HasInsurance='NO' WHERE ChargeID=?")
                    ->execute([$chargeID]);
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Reload queue data AFTER fixing insurance values
// -----------------------------------------------------------------------------
$sql = "
    SELECT
        c.*,
        d.serial_number AS DeviceSerial,
        d.device_model  AS DeviceName,
        o.email         AS OwnerEmail,
        o.full_name     AS OwnerName,
        o.district_id   AS OwnerDistrictId
    FROM panda_charges c
    JOIN devices d ON c.DeviceID = d.id
    JOIN owners  o ON c.OwnerID = o.id
    WHERE c.Status IN ($queueStatusSql)
    ORDER BY c.CreatedAt ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// Coverage estimate (probable final charge on approve/tpp path)
// -----------------------------------------------------------------------------
$chargeEstimate = [];
$skywardCache = [];

foreach ($rows as $row) {
    $chargeId = (int)($row['ChargeID'] ?? 0);
    if ($chargeId <= 0) {
        continue;
    }

    $iiqContext = ['skyward_row' => null];
    $hasInsurance = strtoupper(trim((string)($row['HasInsurance'] ?? 'UNKNOWN')));

    if ($hasInsurance === 'YES') {
        $ownerId = (int)($row['OwnerID'] ?? 0);
        $coverageYear = panda_schoolyear_to_coverage_year($row['SchoolYear'] ?? null);
        if ($ownerId > 0 && $coverageYear) {
            $cacheKey = $ownerId . '|' . $coverageYear;
            if (!array_key_exists($cacheKey, $skywardCache)) {
                $st = $pdo->prepare("
                    SELECT id, CoverageYear, HasCoverage, CoveragePaid, created_at
                    FROM Skyward_ThisnThat
                    WHERE owner_id = ? AND CoverageYear = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $st->execute([$ownerId, (string)$coverageYear]);
                $skywardCache[$cacheKey] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $iiqContext['skyward_row'] = $skywardCache[$cacheKey];
        }
    }

    $quote = panda_compute_coverage_for_charge($pdo, $row, $iiqContext);
    $amount = round((float)($quote['totalChargeAmount'] ?? (float)($row['TotalCost'] ?? 0)), 2);

    $freeQty = 0;
    $discountQty = 0;
    $fullQty = 0;
    foreach (($quote['perUnitBreakdown'] ?? []) as $unit) {
        $type = strtoupper((string)($unit['type'] ?? 'FULL'));
        if ($type === 'FREE') {
            $freeQty++;
        } elseif ($type === 'DISCOUNT') {
            $discountQty++;
        } else {
            $fullQty++;
        }
    }

    $why = '';
    if (!empty($quote['composite']['components']) && is_array($quote['composite']['components'])) {
        $componentBits = [];
        foreach ($quote['composite']['components'] as $component) {
            $componentCode = (string)($component['part_code'] ?? 'UNKNOWN');
            $cFree = (int)($component['this_charge']['free'] ?? 0);
            $cDisc = (int)($component['this_charge']['discount'] ?? 0);
            $cFull = (int)($component['this_charge']['full'] ?? 0);
            $componentBits[] = "{$componentCode}: {$cFree} free, {$cDisc} disc, {$cFull} full";
        }
        $why = 'Composite (' . ((string)($quote['composite']['label'] ?? 'multiple parts')) . '): ' . implode(' | ', $componentBits);
    } elseif (!empty($quote['rule'])) {
        $why = trim("{$freeQty} free, {$discountQty} discounted, {$fullQty} full");
    } else {
        $why = 'No active rule; full price';
    }

    $chargeEstimate[$chargeId] = [
        'amount' => $amount,
        'why'    => $why,
        'tier'   => (string)($quote['tier'] ?? 'UNKNOWN'),
    ];
}

// -----------------------------------------------------------------------------
// Badge rendering helpers
// -----------------------------------------------------------------------------
function badge_status($s) {
    return "<span class='badge bg-secondary'>".htmlspecialchars($s)."</span>";
}
function badge_insurance($s) {
    $s = strtoupper($s);
    if ($s === 'YES') return "<span class='badge bg-success'>Covered</span>";
    if ($s === 'NO')  return "<span class='badge bg-danger'>No Coverage</span>";
    return "<span class='badge bg-warning text-dark'>Unknown</span>";
}
function badge_why($why) {
    switch ($why) {
        case 'Vandalism':   return "<span class='badge bg-primary'>Vandalism</span>";
        case 'Accidental':  return "<span class='badge bg-warning text-dark'>Accidental</span>";
        case 'OEM Failure': return "<span class='badge bg-info text-dark'>OEM Failure</span>";
        default:            return "<span class='badge bg-secondary'>Other</span>";
    }
}
function badge_estimate(array $est): string {
    $amount = number_format((float)($est['amount'] ?? 0), 2);
    $tier = htmlspecialchars((string)($est['tier'] ?? 'UNKNOWN'));
    $why = htmlspecialchars((string)($est['why'] ?? ''));
    return "<strong>$" . $amount . "</strong><br><small title=\"{$why}\">{$tier}</small>";
}
function badge_district_id($districtId) {
    $districtId = trim((string)$districtId);
    if ($districtId !== '') {
        return
            "<span class='badge bg-success'>On File</span><br>" .
            "<small>" . htmlspecialchars($districtId) . "</small>";
    }
    return "<span class='badge bg-warning text-dark'>Needs Lookup</span>";
}

// -----------------------------------------------------------------------------
// Row highlight logic
// -----------------------------------------------------------------------------
function row_class($r) {
    if (strtoupper($r['HasInsurance']) === 'NO') {
        return "table-danger";   // red
    }
    if ($r['PartReplacedWhy'] === 'Vandalism') {
        return "table-primary";  // blue
    }
    if ($r['PartReplacedWhy'] === 'Accidental') {
        return "table-warning";  // yellow
    }
    if ($r['PartReplacedWhy'] === 'OEM Failure') {
        return "table-info";     // teal
    }
    return "";
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>PANDA — Charge Queue</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

<style>
body {
  background-color: var(--background-color);
  color: var(--text-color);
}
.table-wrapper {
  max-height: 75vh;
  overflow-y: auto;
}
#queueTable th {
  position: sticky;
  top: 0;
  background: var(--table-header-bg, #f8f9fa);
  z-index: 10;
}
.queue-row {
  cursor: pointer;
}
.queue-row:hover {
  filter: brightness(1.05);
}
.queue-checkbox-cell {
  width: 42px;
}
#bulkStatus {
  white-space: pre-wrap;
}
#queueTable th.sortable {
  cursor: pointer;
  user-select: none;
}
#queueTable th.sortable::after {
  content: " ⇅";
  opacity: 0.45;
  font-size: 0.85em;
}
</style>
</head>

<body class="<?= htmlspecialchars($theme) ?>">
<div class="container-fluid mt-3">

  <!-- HEADER -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <button class="btn btn-secondary" onclick="window.location='../index.php'">⬅ Back</button>
      <a href="PANDA_ChargeHistory.php"  class="btn btn-outline-primary">History</a>
      <?php if ($canAdminCharges): ?>
        <a href="PANDA_PartPricing.php" class="btn btn-outline-primary">Part Pricing</a>
      <?php endif; ?>
      <?php if ($canManageCharges && !$isEffectiveHoldCoordinator): ?>
        <a href="../HelperPagesV2/MuskyMakeTicketCharges.php" class="btn btn-outline-primary">Create Charge</a>
      <?php endif; ?>
    </div>
    <h3>PANDA — Charge Queue</h3>
    <div>
      <?php if ($canManageCharges && !$isHoldCoordinator): ?>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="previewHoldToggle" <?= $previewHoldCoordinator ? 'checked' : '' ?>>
          <label class="form-check-label small" for="previewHoldToggle">Preview Limited Mode</label>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CARD -->
  <div class="card">
    <div class="card-header">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <?= $isEffectiveHoldCoordinator ? 'Charge Research View' : 'Charges Awaiting Decision' ?> (<?= count($rows) ?>)
          <small class="text-muted ms-2">Click any row to open details.</small>
          <?php if ($isEffectiveHoldCoordinator): ?>
            <span class="badge bg-warning text-dark ms-2">All statuses (HOLD editable only)</span>
          <?php endif; ?>
          <?php if ($previewHoldCoordinator): ?>
            <span class="badge bg-info text-dark ms-2">Preview</span>
          <?php endif; ?>
          <?php if (!$canManageCharges): ?>
            <span class="badge bg-secondary ms-2">View only</span>
          <?php endif; ?>
        </div>
        <?php if ($canBulkQueueActions): ?>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span id="selectedCount" class="text-muted">0 selected</span>
            <button type="button" id="bulkDistrictLookupBtn" class="btn btn-sm btn-outline-primary">
              🪪 District ID Lookup
            </button>
            <button type="button" id="bulkApproveVandalBtn" class="btn btn-sm btn-outline-danger">
              ✅ Approve Vandalism
            </button>
            <button type="button" id="bulkApproveZeroBtn" class="btn btn-sm btn-outline-success">
              ✅ Approve $0
            </button>
            <button type="button" id="bulkWaiveOemBtn" class="btn btn-sm btn-outline-secondary">
              🧾 Waive OEM Failure
            </button>
          </div>
        <?php else: ?>
          <div class="text-muted small">
            <?= $isEffectiveHoldCoordinator
                ? ($previewHoldCoordinator
                    ? 'Preview limited mode: bulk actions are disabled; all statuses are visible, but only HOLD can be changed (Waive/Reject/Escalate).'
                    : 'Supervisor mode: bulk actions are disabled; all statuses are visible, but only HOLD can be changed (Waive/Reject/Escalate).')
                : 'Read-only access: bulk actions are disabled for this account.' ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-body p-0">
      <div id="bulkStatus" class="small px-3 pt-3 text-muted"></div>
      <div class="table-wrapper">
        <table class="table table-hover table-sm" id="queueTable">
          <thead class="table-light">
            <tr>
              <?php if ($canBulkQueueActions): ?>
                <th class="queue-checkbox-cell">
                  <input type="checkbox" id="selectAllCharges" title="Select all visible charges">
                </th>
              <?php endif; ?>
              <th class="sortable" data-sort-type="number">ID</th>
              <th class="sortable">Status</th>
              <th class="sortable">Created</th>
              <th class="sortable">Ticket</th>
              <th class="sortable">Device</th>
              <th class="sortable">Owner</th>
              <th class="sortable">District ID</th>
              <th class="sortable">Part</th>
              <th class="sortable" data-sort-type="number">Qty</th>
              <th class="sortable" data-sort-type="number">Total</th>
              <th class="sortable" data-sort-type="number">Est Final</th>
              <th class="sortable">Why</th>
              <th class="sortable">Insurance</th>
              <th class="sortable">Vand</th>
              <th class="sortable">Year</th>
            </tr>
          </thead>

          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="<?= $columnCount ?>" class="text-center text-muted">No charges waiting.</td></tr>

          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $chargeId = (int)$r['ChargeID'];
                $rowClass = row_class($r);
              ?>
              <tr class="queue-row <?= $rowClass ?>"
                  data-charge-id="<?= $chargeId ?>"
                  title="Review charge #<?= $chargeId ?>">

                <?php if ($canBulkQueueActions): ?>
                  <td class="queue-checkbox-cell">
                    <input
                      type="checkbox"
                      class="charge-check"
                      value="<?= $chargeId ?>"
                      data-owner-email="<?= htmlspecialchars($r['OwnerEmail'] ?? '', ENT_QUOTES) ?>"
                      data-district-id="<?= htmlspecialchars($r['OwnerDistrictId'] ?? '', ENT_QUOTES) ?>"
                      onclick="event.stopPropagation();">
                  </td>
                <?php endif; ?>

                <td data-sort-value="<?= $chargeId ?>">#<?= $chargeId ?></td>
                <td data-sort-value="<?= htmlspecialchars($r['Status'], ENT_QUOTES) ?>"><?= badge_status($r['Status']) ?></td>
                <td data-sort-value="<?= htmlspecialchars($r['CreatedAt'], ENT_QUOTES) ?>"><?= htmlspecialchars($r['CreatedAt']) ?></td>

                <td data-sort-value="<?= htmlspecialchars((string)$r['TicketID'], ENT_QUOTES) ?>">
                  IIQ<br>
                  <small><?= htmlspecialchars($r['TicketID']) ?></small>
                </td>

                <td data-sort-value="<?= htmlspecialchars(($r['DeviceSerial'] ?? '') . ' ' . ($r['DeviceName'] ?? ''), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($r['DeviceSerial']) ?><br>
                  <small><?= htmlspecialchars($r['DeviceName']) ?></small>
                </td>

                <td data-sort-value="<?= htmlspecialchars(($r['OwnerEmail'] ?? '') . ' ' . ($r['OwnerName'] ?? ''), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($r['OwnerEmail']) ?><br>
                  <small><?= htmlspecialchars($r['OwnerName']) ?></small>
                </td>

                <td data-sort-value="<?= htmlspecialchars((string)($r['OwnerDistrictId'] ?? ''), ENT_QUOTES) ?>"><?= badge_district_id($r['OwnerDistrictId'] ?? '') ?></td>

                <td data-sort-value="<?= htmlspecialchars(($r['PartCode'] ?? '') . ' ' . ($r['PartDescription'] ?? ''), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($r['PartCode']) ?><br>
                  <small><?= htmlspecialchars($r['PartDescription']) ?></small>
                </td>

                <td data-sort-value="<?= (int)$r['Quantity'] ?>"><?= (int)$r['Quantity'] ?></td>
                <td data-sort-value="<?= number_format((float)$r['TotalCost'], 2, '.', '') ?>">$<?= number_format((float)$r['TotalCost'], 2) ?></td>
                <?php
                  $est = $chargeEstimate[$chargeId] ?? ['amount' => (float)$r['TotalCost'], 'why' => 'No estimate', 'tier' => 'UNKNOWN'];
                  $estAmountSort = number_format((float)$est['amount'], 2, '.', '');
                ?>
                <td data-sort-value="<?= htmlspecialchars($estAmountSort, ENT_QUOTES) ?>"><?= badge_estimate($est) ?></td>

                <td data-sort-value="<?= htmlspecialchars((string)$r['PartReplacedWhy'], ENT_QUOTES) ?>"><?= badge_why($r['PartReplacedWhy']) ?></td>

                <td data-sort-value="<?= htmlspecialchars((string)$r['HasInsurance'], ENT_QUOTES) ?>"><?= badge_insurance($r['HasInsurance']) ?></td>

                <td data-sort-value="<?= htmlspecialchars((string)$r['IsVandalism'], ENT_QUOTES) ?>"><?= htmlspecialchars($r['IsVandalism']) ?></td>

                <td data-sort-value="<?= htmlspecialchars((string)$r['SchoolYear'], ENT_QUOTES) ?>"><?= htmlspecialchars($r['SchoolYear']) ?></td>

              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>

        </table>
      </div>
    </div>
  </div>

  <?= panda_render_charge_permission_footer($allowed) ?>

</div>

<script>
const csrfToken = <?= json_encode($csrfToken) ?>;
const canManageCharges = <?= json_encode($canBulkQueueActions) ?>;
const previewHoldCoordinator = <?= json_encode($previewHoldCoordinator) ?>;
const canUsePreviewToggle = <?= json_encode($canManageCharges && !$isHoldCoordinator) ?>;

function getSelectedChargeIds() {
  return Array.from(document.querySelectorAll('.charge-check:checked'))
    .map(cb => parseInt(cb.value, 10))
    .filter(v => Number.isFinite(v) && v > 0);
}

function updateSelectedCount() {
  const count = getSelectedChargeIds().length;
  const el = document.getElementById('selectedCount');
  if (el) {
    el.textContent = `${count} selected`;
  }
}

function getCellSortValue(cell) {
  if (!cell) return '';
  return (cell.getAttribute('data-sort-value') ?? cell.textContent ?? '').trim();
}

// Row click → open decision page
document.addEventListener('DOMContentLoaded', function () {
  const table = document.getElementById('queueTable');
  const selectAll = document.getElementById('selectAllCharges');
  const bulkBtn = document.getElementById('bulkDistrictLookupBtn');
  const bulkApproveBtn = document.getElementById('bulkApproveVandalBtn');
  const bulkApproveZeroBtn = document.getElementById('bulkApproveZeroBtn');
  const bulkWaiveOemBtn = document.getElementById('bulkWaiveOemBtn');
  const bulkStatus = document.getElementById('bulkStatus');
  const previewToggle = document.getElementById('previewHoldToggle');
  if (!table) return;

  if (canUsePreviewToggle && previewToggle) {
    previewToggle.addEventListener('change', function () {
      const url = new URL(window.location.href);
      if (previewToggle.checked) {
        url.searchParams.set('preview_hold_mode', '1');
      } else {
        url.searchParams.delete('preview_hold_mode');
      }
      window.location.href = url.toString();
    });
  }

  if (canManageCharges) {
    table.querySelectorAll('.charge-check').forEach(cb => {
      cb.addEventListener('change', updateSelectedCount);
    });
  }

  if (canManageCharges && selectAll) {
    selectAll.addEventListener('change', function () {
      const checks = table.querySelectorAll('.charge-check');
      checks.forEach(cb => {
        cb.checked = selectAll.checked;
      });
      updateSelectedCount();
    });
  }

  if (canManageCharges && bulkBtn) {
    bulkBtn.addEventListener('click', async function (ev) {
      ev.preventDefault();
      ev.stopPropagation();

      const chargeIds = getSelectedChargeIds();
      if (!chargeIds.length) {
        alert('Select at least one charge first.');
        return;
      }

      if (!window.confirm(`Run District ID lookup for ${chargeIds.length} selected charge(s)?`)) {
        return;
      }

      bulkBtn.disabled = true;
      if (bulkStatus) {
        bulkStatus.className = 'small px-3 pt-3 text-muted';
        bulkStatus.textContent = 'Submitting IIQUSERSYNC errands...';
      }

      try {
        const resp = await fetch('PANDA_ChargeQueue.php?api=bulk_district_lookup', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ charge_ids: chargeIds })
        });
        const data = await resp.json();

        if (!data.ok) {
          throw new Error(data.error || 'Bulk lookup failed');
        }

        const s = data.summary || {};
        const lines = [
          `Submitted: ${s.submitted ?? 0}`,
          `Already on file: ${s.already_on_file ?? 0}`,
          `Missing owner email: ${s.missing_email ?? 0}`,
          `Failed: ${s.failed ?? 0}`
        ];

        if (bulkStatus) {
          bulkStatus.className = 'small px-3 pt-3 text-success';
          bulkStatus.textContent = lines.join(' | ');
        }
      } catch (err) {
        if (bulkStatus) {
          bulkStatus.className = 'small px-3 pt-3 text-danger';
          bulkStatus.textContent = err.message || 'Bulk lookup failed.';
        }
      } finally {
        bulkBtn.disabled = false;
      }
    });
  }

  if (canManageCharges && bulkApproveBtn) {
    bulkApproveBtn.addEventListener('click', async function (ev) {
      ev.preventDefault();
      ev.stopPropagation();

      const chargeIds = getSelectedChargeIds();
      if (!chargeIds.length) {
        alert('Select at least one charge first.');
        return;
      }

      const msg =
        `Approve ${chargeIds.length} selected charge(s) as VANDALISM?\n\n` +
        `This uses the same approval/finalization path as the single-charge decision flow.\n` +
        `Rows without Vandalism, without District ID, or blocked by current rules will be skipped.`;
      if (!window.confirm(msg)) {
        return;
      }

      bulkApproveBtn.disabled = true;
      if (bulkStatus) {
        bulkStatus.className = 'small px-3 pt-3 text-muted';
        bulkStatus.textContent = 'Applying bulk vandalism approval...';
      }

      try {
        const resp = await fetch('PANDA_ChargeQueue.php?api=bulk_approve_vandalism', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ charge_ids: chargeIds })
        });
        const data = await resp.json();

        if (!data.ok) {
          throw new Error(data.error || 'Bulk approval failed');
        }

        const s = data.summary || {};
        const lines = [
          `Approved: ${s.approved ?? 0}`,
          `Approved via existing errand: ${s.approved_existing ?? 0}`,
          `Moved to HOLD: ${s.hold ?? 0}`,
          `Skipped: ${s.skipped ?? 0}`
        ];

        if (bulkStatus) {
          bulkStatus.className = 'small px-3 pt-3 text-success';
          bulkStatus.textContent = lines.join(' | ') + ' | Reloading...';
        }

        window.setTimeout(() => window.location.reload(), 1200);
      } catch (err) {
        if (bulkStatus) {
          bulkStatus.className = 'small px-3 pt-3 text-danger';
          bulkStatus.textContent = err.message || 'Bulk approval failed.';
        }
      } finally {
        bulkApproveBtn.disabled = false;
      }
    });
  }

  if (canManageCharges && bulkWaiveOemBtn) {
    bulkWaiveOemBtn.addEventListener('click', async function (ev) {
      ev.preventDefault();
      ev.stopPropagation();

      const chargeIds = getSelectedChargeIds();
      if (!chargeIds.length) {
        alert('Select at least one charge first.');
        return;
      }

      const msg =
        `Waive ${chargeIds.length} selected charge(s) as OEM Failure?\n\n` +
        `This records the waiver/finalization path without creating a Skyward fee.\n` +
        `Rows without OEM Failure, without District ID, or blocked by current state will be skipped.`;
      if (!window.confirm(msg)) {
        return;
      }

      bulkWaiveOemBtn.disabled = true;
      if (bulkStatus) {
        bulkStatus.className = 'small px-3 pt-3 text-muted';
        bulkStatus.textContent = 'Applying bulk OEM Failure waivers...';
      }

      try {
        const resp = await fetch('PANDA_ChargeQueue.php?api=bulk_waive_oem', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ charge_ids: chargeIds })
        });
        const data = await resp.json();

        if (!data.ok) {
          throw new Error(data.error || 'Bulk OEM waiver failed');
        }

        const s = data.summary || {};
        const lines = [
          `Waived: ${s.waived ?? 0}`,
          `Skipped: ${s.skipped ?? 0}`
        ];

        if (bulkStatus) {
          bulkStatus.className = 'small px-3 pt-3 text-success';
          bulkStatus.textContent = lines.join(' | ') + ' | Reloading...';
        }

        window.setTimeout(() => window.location.reload(), 1200);
      } catch (err) {
        if (bulkStatus) {
          bulkStatus.className = 'small px-3 pt-3 text-danger';
          bulkStatus.textContent = err.message || 'Bulk OEM waiver failed.';
        }
      } finally {
        bulkWaiveOemBtn.disabled = false;
      }
    });
  }

  if (canManageCharges && bulkApproveZeroBtn) {
    bulkApproveZeroBtn.addEventListener('click', async function (ev) {
      ev.preventDefault();
      ev.stopPropagation();

      const chargeIds = getSelectedChargeIds();
      if (!chargeIds.length) {
        alert('Select at least one charge first.');
        return;
      }

      const msg =
        `Approve ${chargeIds.length} selected charge(s) with estimated final amount of $0.00?\n\n` +
        `Only rows that currently estimate to $0.00 are approved.\n` +
        `Rows estimated above $0.00 or blocked by rule/state are skipped.`;
      if (!window.confirm(msg)) {
        return;
      }

      bulkApproveZeroBtn.disabled = true;
      if (bulkStatus) {
        bulkStatus.className = 'small px-3 pt-3 text-muted';
        bulkStatus.textContent = 'Applying bulk $0 approval...';
      }

      try {
        const resp = await fetch('PANDA_ChargeQueue.php?api=bulk_approve_zero_dollar', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ charge_ids: chargeIds })
        });
        const data = await resp.json();

        if (!data.ok) {
          throw new Error(data.error || 'Bulk $0 approval failed');
        }

        const s = data.summary || {};
        const lines = [
          `Approved: ${s.approved ?? 0}`,
          `Approved via existing errand: ${s.approved_existing ?? 0}`,
          `Moved to HOLD: ${s.hold ?? 0}`,
          `Skipped: ${s.skipped ?? 0}`
        ];

        if (bulkStatus) {
          bulkStatus.className = 'small px-3 pt-3 text-success';
          bulkStatus.textContent = lines.join(' | ') + ' | Reloading...';
        }

        window.setTimeout(() => window.location.reload(), 1200);
      } catch (err) {
        if (bulkStatus) {
          bulkStatus.className = 'small px-3 pt-3 text-danger';
          bulkStatus.textContent = err.message || 'Bulk $0 approval failed.';
        }
      } finally {
        bulkApproveZeroBtn.disabled = false;
      }
    });
  }

  const headers = table.querySelectorAll('thead th.sortable');
  headers.forEach((th, index) => {
    th.addEventListener('click', function () {
      const allHeaders = Array.from(headers);
      const colIndex = Array.from(th.parentElement.children).indexOf(th);
      const tbody = table.querySelector('tbody');
      const rows = Array.from(tbody.querySelectorAll('tr.queue-row'));
      const currentDir = th.dataset.sortDir === 'asc' ? 'asc' : 'desc';
      const nextDir = currentDir === 'asc' ? 'desc' : 'asc';
      const sortType = th.dataset.sortType || 'text';

      allHeaders.forEach(h => {
        if (h !== th) delete h.dataset.sortDir;
      });
      th.dataset.sortDir = nextDir;

      rows.sort((a, b) => {
        const aValRaw = getCellSortValue(a.children[colIndex]);
        const bValRaw = getCellSortValue(b.children[colIndex]);

        let cmp = 0;
        if (sortType === 'number') {
          const aNum = parseFloat(aValRaw || '0');
          const bNum = parseFloat(bValRaw || '0');
          cmp = aNum === bNum ? 0 : (aNum < bNum ? -1 : 1);
        } else {
          cmp = aValRaw.localeCompare(bValRaw, undefined, { numeric: true, sensitivity: 'base' });
        }

        return nextDir === 'asc' ? cmp : -cmp;
      });

      rows.forEach(row => tbody.appendChild(row));
    });
  });

  table.addEventListener('click', function (ev) {
    if (ev.target.closest('input, button, a, label')) {
      return;
    }
    let tr = ev.target;
    while (tr && tr !== table && !tr.dataset.chargeId) {
      tr = tr.parentElement;
    }
    if (!tr || !tr.dataset.chargeId) return;

    const url = 'PANDA_ChargeDecision.php?ChargeID='
      + encodeURIComponent(tr.dataset.chargeId)
      + (previewHoldCoordinator ? '&preview_hold_mode=1' : '');
    window.open(url, '_blank', 'noopener,noreferrer');
  });

  updateSelectedCount();
});
</script>

</body>
</html>
