<?php
// ============================================================================
// PANDA_ChargeDecision.php
// ----------------------------------------------------------------------------
// Detailed review page for a single PANDA charge.
//  • Two-column layout
//      LEFT  = Device, Owner, IIQ User & Insurance context, Prior usage, Admin Notes
//      RIGHT = Charge details (editable)
//      BOTTOM (FULL WIDTH) = Decision bar with all action buttons
//  • Editable fields (autosaved via PANDA_ChargeDecision_AutoSave.php):
//      TicketID, SchoolYear, HasInsurance, PartReplacedWhy, AdminNote
//    (Quantity is now read-only here.)
//  • Part selection is READ-ONLY here (cannot change the part once charged).
//  • IIQUSERSYNC:
//      - If owners.district_id is present, it is used directly.
//      - If missing, submits IIQUSERSYNC errand via Nora API and briefly
//        rechecks owners.district_id.
//  • Insurance context:
//      - Charge.HasInsurance is authoritative for UI, but Stage B will
//        correct it to NO when Skyward definitively shows no coverage.
//      - Skyward_ThisnThat is consulted for current-year coverage context.
//  • Coverage rules (Stage C):
//      - Uses panda_part_coverage_rules + owner history in this SchoolYear
//        to show how many items are free / discounted / full-cost.
//  • Decision buttons post to PANDA_ChargeDecision_Action.php.
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/PANDA_Functions.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php'; // musky_nora_api_post_json()
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

panda_require_charges_enabled();

// -----------------------------------------------------------------------------
// User prefs / access
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$theme   = $prefs['theme'];
$allowed = $prefs['allowed_tools'];
$email   = $prefs['email'] ?? 'PANDA_ChargeDecision';
$canManageCharges = panda_user_can_manage_charges($allowed);
$canAdminCharges = panda_user_can_admin_charges($allowed);
$isHoldCoordinator = panda_user_is_hold_coordinator($allowed);
$previewHoldCoordinator = !$isHoldCoordinator
    && $canManageCharges
    && (($_GET['preview_hold_mode'] ?? '') === '1');
$isEffectiveHoldCoordinator = $isHoldCoordinator || $previewHoldCoordinator;
$isChargeStatusMutable = false;
$canEditChargeFields = false;
$canEditAdminNote = false;
$canTakeDecisionActions = false;
$isReadOnlyChargeView = !$canManageCharges;
$csrfToken = musky_csrf_token('panda_charge_decision');

// Access gate
panda_require_charge_view_access($allowed, 'html');

// -----------------------------------------------------------------------------
// DB + load charge
// -----------------------------------------------------------------------------
$pdo = panda_db();

$chargeId = (int)($_GET['ChargeID'] ?? 0);
if ($chargeId <= 0) {
    http_response_code(400);
    echo "Missing or invalid ChargeID.";
    exit;
}

// Load charge + device + owner
$st = $pdo->prepare("
    SELECT
        c.*,
        d.serial_number AS DeviceSerial,
        d.asset_tag     AS DeviceAssetTag,
        COALESCE(d.device_model, d.model) AS DeviceModel,
        o.id            AS OwnerRowID,
        o.email         AS OwnerEmail,
        o.full_name     AS OwnerName,
        o.grade         AS OwnerGrade,
        o.district_id   AS OwnerDistrictId
    FROM panda_charges c
    JOIN devices d ON c.DeviceID = d.id
    JOIN owners  o ON c.OwnerID = o.id
    WHERE c.ChargeID = ?
    LIMIT 1
");
$st->execute([$chargeId]);
$charge = $st->fetch(PDO::FETCH_ASSOC);

if (!$charge) {
    http_response_code(404);
    echo "Charge #{$chargeId} not found.";
    exit;
}

$chargeStatusLower = strtolower(trim((string)($charge['Status'] ?? '')));
$isHoldStatus = ($chargeStatusLower === 'hold');
$isChargeStatusMutable = in_array($chargeStatusLower, ['submitted', 'hold'], true);
$canEditChargeFields = $canManageCharges && !$isEffectiveHoldCoordinator && $isChargeStatusMutable;
$canEditAdminNote = $canEditChargeFields || ($canManageCharges && $isEffectiveHoldCoordinator && $isHoldStatus);
$canTakeDecisionActions = $canManageCharges && $isChargeStatusMutable;
$isReadOnlyChargeView = !$canManageCharges || !$isChargeStatusMutable || ($isEffectiveHoldCoordinator && !$isHoldStatus);

// -----------------------------------------------------------------------------
// Simple UI helpers
// -----------------------------------------------------------------------------
function panda_badge_status(string $s): string {
    $s = htmlspecialchars($s);
    return "<span class=\"badge bg-secondary\">{$s}</span>";
}

function panda_badge_why(?string $why): string {
    $why = $why ?? '';
    switch ($why) {
        case 'Vandalism':   return "<span class='badge bg-primary'>Vandalism</span>";
        case 'Accidental':  return "<span class='badge bg-warning text-dark'>Accidental</span>";
        case 'OEM Failure': return "<span class='badge bg-info text-dark'>OEM Failure</span>";
        default:            return "<span class='badge bg-secondary'>Other</span>";
    }
}

function panda_badge_insurance(string $s): string {
    $s = strtoupper(trim($s));
    if ($s === 'YES') return "<span class='badge bg-success'>YES</span>";
    if ($s === 'NO')  return "<span class='badge bg-danger'>NO</span>";
    return "<span class='badge bg-warning text-dark'>UNKNOWN</span>";
}

/**
 * IIQ + Insurance context computation
 * Returns an array used by the IIQ card renderer.
 */
function panda_compute_iiq_insurance_context(PDO $pdo, array $charge, string $submitterEmail, bool $allowSync = true): array {
    error_log('[PANDA IIQ CONTEXT] entered');
    $context = [
        'district_id'           => null,
        'district_source'       => null, // 'db', 'iiq_sync', 'none', 'error'
        'iiq_notes'             => [],
        'iiq_errand_id'         => null,
        'skyward_row'           => null,
        'skyward_status_label'  => 'Unknown',
        'skyward_status_detail' => '',
    ];

    // ----- District ID -----
    $ownerId     = (int)($charge['OwnerRowID'] ?? 0);
    $ownerEmail  = trim((string)($charge['OwnerEmail'] ?? ''));
    $districtId  = trim((string)($charge['OwnerDistrictId'] ?? ''));

    if ($districtId !== '') {
        $context['district_id']     = $districtId;
        $context['district_source'] = 'db';
    } else {
        $context['district_source'] = 'none';

        if (!$allowSync) {
            $context['iiq_notes'][] = "View-only access: IIQUSERSYNC was not submitted from this page.";
        } elseif ($ownerEmail !== '' && function_exists('musky_nora_api_post_json')) {

            // Build NORA /errand/create payload for IIQUSERSYNC (NORA-side wrapper)
            $payload = [
                'serial'    => 'N/A',
                'udid'      => 'SYSTEM_TASK',
                'submitter' => $submitterEmail ?: 'PANDA_ChargeDecision',
                'nora'      => 'TRUE',
                'priority'  => 5,
                'extra1'    => 'IIQUSERSYNC',
                'extra2'    => $ownerEmail,
            ];

            // Call Musky → NORA API helper
            $resp = musky_nora_api_post_json('/errand/create', $payload);
            $errandId = function_exists('musky_nora_extract_errand_id')
                ? musky_nora_extract_errand_id($resp)
                : null;

            if ($errandId) {
                $context['iiq_errand_id'] = $errandId;
                $context['iiq_notes'][]   = "IIQUSERSYNC errand submitted (ErrandID {$errandId}). Waiting for NORA to sync district ID.";

                // Briefly recheck owner row a few times
                for ($i = 0; $i < 3; $i++) {
                    usleep(500000); // 0.5s
                    $st = $pdo->prepare("SELECT district_id FROM owners WHERE id = ?");
                    $st->execute([$ownerId]);
                    $newDistrict = trim((string)$st->fetchColumn());
                    if ($newDistrict !== '') {
                        $districtId = $newDistrict;
                        $context['district_id']     = $districtId;
                        $context['district_source'] = 'iiq_sync';
                        $context['iiq_notes'][]     = "District ID synced from IIQ and stored in NORA owners table.";
                        break;
                    }
                }

                if ($districtId === '') {
                    $context['iiq_notes'][] = "District ID not available yet; NORA errand still in progress.";
                }

            } else {
                // We got *something* back, but not an ErrandID.
                $context['district_source'] = 'error';

                if (is_array($resp)) {
                    $errorCode = trim((string)($resp['error'] ?? ''));
                    if ($errorCode !== '') {
                        $context['iiq_notes'][] =
                            "Failed to create IIQUSERSYNC errand. Nora error: {$errorCode}.";
                    } else {
                        $context['iiq_notes'][] =
                            "Failed to create IIQUSERSYNC errand. Nora returned no errand id.";
                    }
                } elseif (is_string($resp)) {
                    $context['iiq_notes'][] =
                        "Failed to create IIQUSERSYNC errand. Nora returned a non-JSON response.";
                } else {
                    $context['iiq_notes'][] =
                        "Failed to create IIQUSERSYNC errand. API returned no usable data.";
                }
            }

        } elseif ($ownerEmail === '') {
            $context['iiq_notes'][] = "Owner email missing; cannot submit IIQUSERSYNC.";
        }
    }

    // ----- Insurance / Skyward coverage (context only, no mutation) -----
    // SchoolYear in charges is "24-25", "25-26", etc.
    // panda_schoolyear_to_coverage_year() now returns the same string ("24-25", "25-26").
    $coverageYear = panda_schoolyear_to_coverage_year($charge['SchoolYear'] ?? null);
    $ownerId      = (int)($charge['OwnerRowID'] ?? 0);

    if ($coverageYear && $ownerId > 0) {
        $st = $pdo->prepare("
            SELECT id, CoverageYear, HasCoverage, CoveragePaid, created_at
            FROM Skyward_ThisnThat
            WHERE owner_id = ? AND CoverageYear = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$ownerId, (string)$coverageYear]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $context['skyward_row'] = $row;
            $hasCoverage  = (int)$row['HasCoverage'] === 1;
            $coveragePaid = trim((string)$row['CoveragePaid']);

            if ($hasCoverage) {
                // NOTE: In Skyward, FeeHasBalanceDue = "True" means there's a balance OUTSTANDING.
                // Our importer stored that into CoveragePaid.
                // So:
                //   CoveragePaid "True"  => coverage exists, but there is BALANCE DUE
                //   CoveragePaid "False" => coverage exists and no balance is due (PAID)
                if (strcasecmp($coveragePaid, 'True') === 0 || $coveragePaid === '1') {
                    $context['skyward_status_label']  = 'Has Coverage (Balance Due)';
                    $context['skyward_status_detail'] = "CoverageYear {$row['CoverageYear']}, Skyward still shows a balance due.";
                } else {
                    $context['skyward_status_label']  = 'Has Coverage (Paid)';
                    $context['skyward_status_detail'] = "CoverageYear {$row['CoverageYear']}, no remaining balance due.";
                }
            } else {
                $context['skyward_status_label']  = 'No Coverage Record';
                $context['skyward_status_detail'] = "Skyward_ThisnThat has a row, but HasCoverage=0 for {$row['CoverageYear']}.";
            }
        } else {
            $context['skyward_status_label']  = 'No Entry for Year';
            $context['skyward_status_detail'] = "No Skyward_ThisnThat entry for owner_id={$ownerId}, CoverageYear={$coverageYear}.";
        }
    }

    return $context;
}

/**
 * Prior-year usage: how many of each part this owner has in this SchoolYear.
 */
function panda_load_prior_part_usage(PDO $pdo, array $charge): array {
    $ownerId    = (int)$charge['OwnerID'];
    $schoolYear = $charge['SchoolYear'] ?? '';
    $chargeId   = (int)$charge['ChargeID'];

    if ($ownerId <= 0 || $schoolYear === '') return [];

    $st = $pdo->prepare("
        SELECT
            ChargeID,
            TicketID,
            PartCode,
            PartDescription,
            Quantity,
            Status,
            CreatedAt,
            DecidedAt,
            DecidedBy
        FROM panda_charges
        WHERE OwnerID = :oid
          AND SchoolYear = :sy
          AND ChargeID <> :cid
          AND Status IN ('submitted','hold','approved','other_approved','waived')
        ORDER BY CreatedAt DESC, ChargeID DESC
    ");
    $st->execute([
        ':oid' => $ownerId,
        ':sy'  => $schoolYear,
        ':cid' => $chargeId,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// -----------------------------------------------------------------------------
// IIQ context
// -----------------------------------------------------------------------------
$iiqContext = panda_compute_iiq_insurance_context($pdo, $charge, $email, $canEditChargeFields);

// -----------------------------------------------------------------------------
// STAGE B — Auto-correct HasInsurance if Skyward shows NO coverage
// -----------------------------------------------------------------------------
$coverageYear = panda_schoolyear_to_coverage_year($charge['SchoolYear'] ?? null);
$ownerId      = (int)$charge['OwnerRowID'];

if ($canEditChargeFields && $coverageYear && $ownerId > 0) {
    $st = $pdo->prepare("
        SELECT HasCoverage
        FROM Skyward_ThisnThat
        WHERE owner_id = ? AND CoverageYear = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$ownerId, (string)$coverageYear]);
    $sky = $st->fetch(PDO::FETCH_ASSOC);

    $skyHasCoverage = ($sky && (int)$sky['HasCoverage'] === 1);

    // If Skyward says NO coverage → NORA backend always wins
    if (!$skyHasCoverage && $charge['HasInsurance'] !== 'NO') {
        // Update in DB
        $upd = $pdo->prepare("
            UPDATE panda_charges
            SET HasInsurance = 'NO'
            WHERE ChargeID = ?
        ");
        $upd->execute([$chargeId]);

        // Log the correction but do NOT alter the charge status
        panda_log_charge_status_change(
            $pdo,
            $chargeId,
            $charge['Status'],         // unchanged
            $charge['Status'],         // unchanged
            $email,                    // admin performing the action
            "Stage B: Skyward shows NO coverage → HasInsurance corrected to NO."
        );

        // Update local copy for UI rendering
        $charge['HasInsurance'] = 'NO';
    }
}

// -----------------------------------------------------------------------------
// STAGE C — Coverage Impact (rules + usage + free/discount/FullCost math)
// -----------------------------------------------------------------------------

// 1) Determine coverage tier for this charge
$coverageTier = 'NO_INS';
if ($charge['HasInsurance'] === 'YES') {
    $skyRow = $iiqContext['skyward_row'] ?? null;
    $paid = false;
    if ($skyRow) {
        $coveragePaid = trim((string)$skyRow['CoveragePaid']);
        // Here, "paid" means there is NO balance due.
        if (!(strcasecmp($coveragePaid, 'True') === 0 || $coveragePaid === '1')) {
            $paid = true;
        }
    }
    $coverageTier = $paid ? 'INS_PAID' : 'INS_BASIC';
}

// 2) Map the real PartCode to the coverage rule PartCode
$coveragePartCode = panda_map_partcode_to_coverage_code($charge['PartCode']);
$coverageRulePartCode = $coveragePartCode ?? trim((string)($charge['PartCode'] ?? ''));
if ($coverageRulePartCode === '') {
    $coverageRulePartCode = null;
}

// 3) Load matching coverage rule (if any)
$coverageRule = null;

if ($coverageRulePartCode !== null) {
    $st = $pdo->prepare("
        SELECT *
        FROM panda_part_coverage_rules
        WHERE PartCode = ? AND Tier = ?
        LIMIT 1
    ");
    $st->execute([$coverageRulePartCode, $coverageTier]);
    $coverageRule = $st->fetch(PDO::FETCH_ASSOC);
}

// Usage this year for this owner + coveragePartCode + year
$usageThisYear = [
    'total_charges' => 0,
    'total_qty'     => 0,
];

$ownerIdForUsage = (int)$charge['OwnerID'];
$schoolYear      = $charge['SchoolYear'] ?? '';

if ($ownerIdForUsage > 0 && $schoolYear !== '' && $coverageRulePartCode !== null) {
    $st = $pdo->prepare("
        SELECT COUNT(*) AS cnt_charges,
               COALESCE(SUM(Quantity),0) AS sum_qty
        FROM panda_charges
        WHERE OwnerID   = ?
          AND SchoolYear = ?
          AND PartCode   = ?
          AND Status IN ('submitted','approved','other_approved','waived','hold')
    ");
    $st->execute([$ownerIdForUsage, $schoolYear, $coverageRulePartCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $usageThisYear['total_charges'] = (int)$row['cnt_charges'];
        $usageThisYear['total_qty']     = (int)$row['sum_qty'];
    }
}

// Compute per-charge free / discount / full-cost breakdown
$coverageImpact = null;

if ($coverageRule && $coverageRulePartCode !== null) {
    $ruleFreeQty      = (int)$coverageRule['FreeQtyPerYear'];
    $ruleDiscQty      = (int)$coverageRule['DiscountQtyPerYear'];
    $alreadyQty       = $usageThisYear['total_qty'];
    $thisQty          = (int)$charge['Quantity'];

    // How many free units remain BEFORE this charge?
    $freeRemainingBefore = max($ruleFreeQty - $alreadyQty, 0);

    // How many "discount eligible" units remain BEFORE this charge?
    $discountPoolStartQty    = max($alreadyQty - $ruleFreeQty, 0);
    $discountRemainingBefore = max($ruleDiscQty - $discountPoolStartQty, 0);

    // Apply freebies first
    $freeApplied = min($thisQty, $freeRemainingBefore);
    $afterFree   = $thisQty - $freeApplied;

    // Then apply discounted units
    $discountApplied = min($afterFree, $discountRemainingBefore);
    $afterDiscount   = $afterFree - $discountApplied;

    // Whatever is left is full cost
    $fullCostQty = max($afterDiscount, 0);

    $coverageImpact = [
        'tier'                => $coverageTier,
        'rule'                => $coverageRule,
        'usage'               => $usageThisYear,
        'free_applied'        => $freeApplied,
        'discount_applied'    => $discountApplied,
        'fullcost_qty'        => $fullCostQty,
        'coverage_part_code'  => $coverageRulePartCode,
        'mapping_part_code'   => $coveragePartCode,
    ];
}

// ---------------------------------------------------------------------------
// Approve quote (exact dollars + simple explanation)
// ---------------------------------------------------------------------------
$coverageQuote = panda_compute_coverage_for_charge($pdo, $charge, $iiqContext);
$coverageComposite = (is_array($coverageQuote['composite'] ?? null) ? $coverageQuote['composite'] : null);
$approveChargeAmount = round((float)($coverageQuote['totalChargeAmount'] ?? (float)$charge['TotalCost']), 2);
$tppChargeAmount = $approveChargeAmount;
$approveWhySimple = '';

$quoteFreeQty = 0;
$quoteDiscountQty = 0;
$quoteFullQty = 0;
foreach (($coverageQuote['perUnitBreakdown'] ?? []) as $unit) {
    $t = strtoupper((string)($unit['type'] ?? 'FULL'));
    if ($t === 'FREE') {
        $quoteFreeQty++;
    } elseif ($t === 'DISCOUNT') {
        $quoteDiscountQty++;
    } else {
        $quoteFullQty++;
    }
}

if ($coverageComposite && !empty($coverageComposite['components'])) {
    $componentNotes = [];
    foreach ($coverageComposite['components'] as $component) {
        $partCode = (string)($component['part_code'] ?? 'UNKNOWN');
        $priorQty = (int)($component['already_used_qty'] ?? 0);
        $thisFree = (int)($component['this_charge']['free'] ?? 0);
        $thisDisc = (int)($component['this_charge']['discount'] ?? 0);
        $thisFull = (int)($component['this_charge']['full'] ?? 0);
        $componentNotes[] = sprintf(
            "%s prior %d -> %d free, %d discounted, %d full",
            $partCode,
            $priorQty,
            $thisFree,
            $thisDisc,
            $thisFull
        );
    }
    $approveWhySimple = sprintf(
        "Composite rule (%s): %s.",
        (string)($coverageComposite['label'] ?? 'multiple parts'),
        implode(' | ', $componentNotes)
    );
} elseif (!empty($coverageQuote['rule'])) {
    $approveWhySimple = sprintf(
        "Tier %s, prior finalized qty %d, this charge qty %d -> %d free, %d discounted, %d full.",
        (string)($coverageQuote['tier'] ?? 'UNKNOWN'),
        (int)($coverageQuote['alreadyUsedQty'] ?? 0),
        (int)($coverageQuote['quantity'] ?? 0),
        $quoteFreeQty,
        $quoteDiscountQty,
        $quoteFullQty
    );
} else {
    $approveWhySimple = sprintf(
        "No active coverage rule for PartCode %s at tier %s, so full price is used.",
        (string)($coverageRulePartCode ?? ($charge['PartCode'] ?? 'UNKNOWN')),
        (string)($coverageQuote['tier'] ?? $coverageTier)
    );
}

// -----------------------------------------------------------------------------
// Determine button availability based on business rules
// -----------------------------------------------------------------------------

// Base flags
$reason        = $charge['PartReplacedWhy'] ?? '';
$isOemFailure  = ($reason === 'OEM Failure');
$ownerEmail    = strtolower(trim((string)($charge['OwnerEmail'] ?? '')));
$isStaff       = musky_email_is_staff($ownerEmail); // staff: cannot be charged
$hasDistrictId = !empty($charge['OwnerDistrictId']);

// 1) Determine if "Use GSD TPP" can ever be considered
$canUseTpp = false;
if ($charge['HasInsurance'] === 'YES') {
    $skyRow = $iiqContext['skyward_row'] ?? null;
    if ($skyRow && (int)$skyRow['HasCoverage'] === 1) {
        $coveragePaid = trim((string)$skyRow['CoveragePaid']);
        // Use GSD TPP ONLY when coverage exists AND there is NO balance due.
        if (!(strcasecmp($coveragePaid, 'True') === 0 || $coveragePaid === '1')) {
            $canUseTpp = true;
        }
    }
}

// 2) Start with broad availability
$canApprove       = true;  // Approve (full charge)
$canWaive         = true;  // Waive (0 cost, still record in Skyward)
$canHold          = true;  // Hold
$canDeny          = true;  // Deny
$canReject        = true;  // Reject
$canEscalateFromHold = false; // Escalate HOLD back into submitted queue flow
$canOtherApproval = true;  // Other Approval (manual price)

// 3) Apply OEM Failure rule
// NOTE: "If reason for replacement is OEM FAILURE then a student should NEVER be charged.
//        Not even a '0' amount. Only non-charging options allowed."
if ($isOemFailure) {
    $canApprove       = false;
    $canUseTpp        = false;
    $canWaive         = false;
    $canOtherApproval = false;
    // Hold / Deny / Reject remain allowed
}

// 4) Apply staff rule (configured staff domains)
// NOTE: Staff may not be charged, but can be WAIVED.
if ($isStaff) {
    $canApprove       = false;
    $canUseTpp        = false;
    $canOtherApproval = false;
    // Waive / Hold / Deny / Reject remain allowed
}

// 5) Apply missing district_id rule for charge actions
// NOTE: "WE CANT CHARGE WITHOUT A district_id", but waiving is still allowed
//       (except for OEM Failure where we already disabled waive above).
if (!$hasDistrictId && !$isStaff && !$isOemFailure) {
    $canApprove       = false;
    $canUseTpp        = false;
    $canOtherApproval = false;
    // Waive / Hold / Deny / Reject remain allowed
}

// 6) Final constraint: Other Approval must only be usable
//    when it will submit a real charge (i.e. it can reach Skyward).
//    So it requires a district_id and must not be staff or OEM failure.
if (!$hasDistrictId || $isStaff || $isOemFailure) {
    $canOtherApproval = false;
}

if (!$canManageCharges) {
    $canApprove = false;
    $canUseTpp = false;
    $canWaive = false;
    $canHold = false;
    $canDeny = false;
    $canReject = false;
    $canEscalateFromHold = false;
    $canOtherApproval = false;
}

if (!$isChargeStatusMutable) {
    $canApprove = false;
    $canUseTpp = false;
    $canWaive = false;
    $canHold = false;
    $canDeny = false;
    $canReject = false;
    $canEscalateFromHold = false;
    $canOtherApproval = false;
}

if ($isEffectiveHoldCoordinator) {
    $canApprove = false;
    $canUseTpp = false;
    $canHold = false;
    $canDeny = false;
    $canOtherApproval = false;

    $canEscalateFromHold = $isHoldStatus;
    $canWaive = $isHoldStatus;
    $canReject = $isHoldStatus;
    $canTakeDecisionActions = $isHoldStatus;
}

// -----------------------------------------------------------------------------
// NEW: Load charge history / notes for this ChargeID
// -----------------------------------------------------------------------------
$historyRows = [];
try {
    $hst = $pdo->prepare("
        SELECT ChargeID, OldStatus, NewStatus, ActorUser, ActionType, NoteText, CreatedAt
        FROM panda_charge_status_history
        WHERE ChargeID = ?
        ORDER BY CreatedAt ASC
    ");
    $hst->execute([$chargeId]);
    $historyRows = $hst->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Fail-safe: don't break the page if history table has issues
    error_log('[PANDA] Failed to load charge history for ChargeID ' . $chargeId . ': ' . $e->getMessage());
}

// -----------------------------------------------------------------------------
// AJAX: partial refresh for IIQ & Insurance context (Stage E)
// -----------------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'iiq_context') {
    // Recompute context fresh so both IIQ + Skyward are up to date
    $ctx = panda_compute_iiq_insurance_context($pdo, $charge, $email, $canEditChargeFields);

    ob_start();
    ?>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>IIQ User &amp; Insurance Context</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshIIQ">
          Refresh
        </button>
      </div>
      <div class="card-body small">
        <div class="mb-2">
          <strong>Owner Email:</strong>
          <?= htmlspecialchars($charge['OwnerEmail'] ?? '') ?>
        </div>

        <div class="mb-2">
          <strong>District ID:</strong>
          <?php if (!empty($ctx['district_id'])): ?>
            <?= htmlspecialchars($ctx['district_id']) ?>
            <small class="text-muted">
              (source: <?= htmlspecialchars($ctx['district_source'] === 'iiq_sync' ? 'IIQ sync' : 'NORA owners') ?>)
            </small>
          <?php else: ?>
            <span class="text-warning">Not available</span>
            <?php if ($ctx['district_source'] === 'error'): ?>
              <small class="text-danger d-block">Error submitting IIQUSERSYNC.</small>
            <?php elseif ($ctx['district_source'] === 'none'): ?>
              <small class="text-muted d-block">IIQUSERSYNC may still be running, or NORA has not written back yet.</small>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if (!empty($ctx['iiq_notes'])): ?>
          <div class="mb-2">
            <strong>IIQ Sync Notes:</strong>
            <ul class="mb-0">
              <?php foreach ($ctx['iiq_notes'] as $n): ?>
                <li><?= htmlspecialchars($n) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <hr>

        <div class="mb-2">
          <strong>Charge HasInsurance (stored):</strong>
          <?= panda_badge_insurance($charge['HasInsurance']) ?>
        </div>

        <div class="mb-2">
          <strong>Skyward Coverage (context):</strong>
          <?= htmlspecialchars($ctx['skyward_status_label'] ?? 'Unknown') ?>
          <?php if (!empty($ctx['skyward_status_detail'])): ?>
            <br><small class="text-muted"><?= htmlspecialchars($ctx['skyward_status_detail']) ?></small>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// -----------------------------------------------------------------------------
// Prior-usage table + school-year dropdown options
// -----------------------------------------------------------------------------
$priorUsage = panda_load_prior_part_usage($pdo, $charge);

// SchoolYear dropdown options (distinct from existing charges)
$schoolYearOptions = [];
$syStmt = $pdo->query("
    SELECT DISTINCT SchoolYear
    FROM panda_charges
    WHERE SchoolYear IS NOT NULL AND SchoolYear <> ''
    ORDER BY SchoolYear DESC
");
$schoolYearOptions = $syStmt->fetchAll(PDO::FETCH_COLUMN);

// For the queue icon: AdminNote presence
$hasAdminNote = trim((string)($charge['AdminNote'] ?? '')) !== '';

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>PANDA — Charge #<?= (int)$charge['ChargeID'] ?> Decision</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
body {
  background-color: var(--background-color);
  color: var(--text-color);
}
.card {
  margin-bottom: 1rem;
}
.autosave-status {
  font-size: 0.8rem;
  min-height: 1em;
}
.autosave-status.saving {
  color: #888;
}
.autosave-status.ok {
  color: #28a745;
}
.autosave-status.err {
  color: #dc3545;
}
.history-note {
  white-space: pre-wrap;
  font-family: var(--bs-font-monospace, monospace);
  font-size: 0.8rem;
}
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">

<div class="container-fluid mt-3">
  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex gap-2">
      <button class="btn btn-secondary" onclick="window.close();">✖ Close</button>
      <a href="PANDA_ChargeQueue.php<?= $previewHoldCoordinator ? '?preview_hold_mode=1' : '' ?>" class="btn btn-outline-primary">Queue</a>
      <a href="PANDA_ChargeHistory.php" class="btn btn-outline-primary">History</a>
      <?php if ($canAdminCharges): ?>
        <a href="PANDA_PartPricing.php" class="btn btn-outline-primary">Part Pricing</a>
      <?php endif; ?>

      <?php if ($canTakeDecisionActions): ?>
        <div class="btn-group">
          <button type="button"
                  class="btn btn-primary dropdown-toggle"
                  data-bs-toggle="dropdown"
                  aria-expanded="false">
            Take Action
          </button>
          <ul class="dropdown-menu">
            <?php if ($isEffectiveHoldCoordinator): ?>
              <li>
                <a class="dropdown-item decision-action<?= $canWaive ? '' : ' disabled' ?>"
                   href="#"
                   data-action="waive"
                   <?= $canWaive ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Waive (Default)
                </a>
              </li>
              <li>
                <a class="dropdown-item decision-action<?= $canReject ? '' : ' disabled' ?>"
                   href="#"
                   data-action="reject"
                   <?= $canReject ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Reject
                </a>
              </li>
              <li>
                <a class="dropdown-item decision-action<?= $canEscalateFromHold ? '' : ' disabled' ?>"
                   href="#"
                   data-action="escalate"
                   <?= $canEscalateFromHold ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Escalate (Submit)
                </a>
              </li>
            <?php else: ?>
              <li>
                <a class="dropdown-item decision-action<?= $canApprove ? '' : ' disabled' ?>"
                   href="#"
                   data-action="approve"
                   <?= $canApprove ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Approve ($<?= number_format($approveChargeAmount, 2) ?>)
                </a>
              </li>
              <li>
                <a class="dropdown-item decision-action<?= $canUseTpp ? '' : ' disabled' ?>"
                   href="#"
                   data-action="claim_approved"
                   <?= $canUseTpp ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Use GSD TPP ($<?= number_format($tppChargeAmount, 2) ?>)
                </a>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item decision-action<?= $canWaive ? '' : ' disabled' ?>"
                   href="#"
                   data-action="waive"
                   <?= $canWaive ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Waive
                </a>
              </li>
              <li>
                <a class="dropdown-item decision-action<?= $canHold ? '' : ' disabled' ?>"
                   href="#"
                   data-action="hold"
                   <?= $canHold ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Hold
                </a>
              </li>
              <li>
                <a class="dropdown-item decision-action<?= $canDeny ? '' : ' disabled' ?>"
                   href="#"
                   data-action="deny"
                   <?= $canDeny ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Deny
                </a>
              </li>
              <li>
                <a class="dropdown-item decision-action<?= $canReject ? '' : ' disabled' ?>"
                   href="#"
                   data-action="reject"
                   <?= $canReject ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Reject
                </a>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item decision-action<?= $canOtherApproval ? '' : ' disabled' ?>"
                   href="#"
                   data-action="other_approved"
                   <?= $canOtherApproval ? '' : 'aria-disabled="true" tabindex="-1"' ?>>
                  Other Approval…
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </div>
      <?php else: ?>
        <span class="badge bg-secondary align-self-center">View only</span>
      <?php endif; ?>
    </div>

    <div class="text-center flex-grow-1">
      <h3 class="mb-0">PANDA — Charge #<?= (int)$charge['ChargeID'] ?> Decision</h3>
      <small class="text-muted">
        Status: <?= panda_badge_status($charge['Status']) ?>
        • School Year: <?= htmlspecialchars($charge['SchoolYear']) ?>
        <?php if ($hasAdminNote): ?>
          • <span title="Admin notes present">🗒️</span>
        <?php endif; ?>
      </small>
    </div>

    <div style="min-width: 220px; text-align: right;">
      <?php if ($canManageCharges && !$isHoldCoordinator): ?>
        <div class="form-check form-switch d-inline-block">
          <input class="form-check-input" type="checkbox" role="switch" id="previewHoldToggle" <?= $previewHoldCoordinator ? 'checked' : '' ?>>
          <label class="form-check-label small" for="previewHoldToggle">Preview Limited Mode</label>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isReadOnlyChargeView): ?>
    <div class="alert alert-secondary">
      <?php if (!$canManageCharges): ?>
        This account has read-only PANDA access. You can review queue/history details, but charge edits and decisions are disabled.
      <?php elseif ($isEffectiveHoldCoordinator): ?>
        Supervisor mode is read-only for this charge status. Only charges in <code>hold</code> may be changed here.
      <?php else: ?>
        This charge is locked because its status is <code><?= htmlspecialchars((string)($charge['Status'] ?? 'UNKNOWN')) ?></code>.
        Only charges in <code>submitted</code> or <code>hold</code> can be changed.
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($isEffectiveHoldCoordinator): ?>
    <div class="alert alert-warning">
      <?= $previewHoldCoordinator
          ? 'Preview limited mode: this page is simulating supervisor restrictions (view all statuses; only HOLD can be changed via Waive, Reject, or Escalate).'
          : 'Supervisor mode: all charges are viewable for research, but only HOLD charges can be changed (Waive, Reject, Escalate).' ?>
    </div>
  <?php endif; ?>

  <div class="row">
    <!-- LEFT COLUMN -->
    <div class="col-lg-6">

      <!-- Device + Owner -->
      <div class="card">
        <div class="card-header">Device & Owner</div>
        <div class="card-body small">
          <div class="row">
            <div class="col-6">
              <h6>Device</h6>
              <ul class="mb-2">
                <li><strong>Serial:</strong> <?= htmlspecialchars($charge['DeviceSerial']) ?></li>
                <li><strong>Asset Tag:</strong> <?= htmlspecialchars($charge['DeviceAssetTag'] ?? '') ?></li>
                <li><strong>Model:</strong> <?= htmlspecialchars($charge['DeviceModel'] ?? '') ?></li>
              </ul>
            </div>
            <div class="col-6">
              <h6>Owner</h6>
              <ul class="mb-2">
                <li><strong>Email:</strong> <?= htmlspecialchars($charge['OwnerEmail'] ?? '') ?></li>
                <li><strong>Name:</strong> <?= htmlspecialchars($charge['OwnerName'] ?? '') ?></li>
                <li><strong>Grade:</strong> <?= htmlspecialchars($charge['OwnerGrade'] ?? '') ?></li>
                <li><strong>District ID:</strong>
                  <?php if (!empty($charge['OwnerDistrictId'])): ?>
                    <?= htmlspecialchars($charge['OwnerDistrictId']) ?>
                  <?php else: ?>
                    <span class="text-warning">Unknown</span>
                  <?php endif; ?>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- IIQ User & Insurance Context (initial render; can be refreshed via AJAX) -->
      <div id="iiqContextWrapper">
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span>IIQ User &amp; Insurance Context</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshIIQ">
              Refresh
            </button>
          </div>
          <div class="card-body small">
            <div class="mb-2">
              <strong>Owner Email:</strong>
              <?= htmlspecialchars($charge['OwnerEmail'] ?? '') ?>
            </div>

            <div class="mb-2">
              <strong>District ID:</strong>
              <?php if (!empty($iiqContext['district_id'])): ?>
                <?= htmlspecialchars($iiqContext['district_id']) ?>
                <small class="text-muted">
                  (source: <?= htmlspecialchars($iiqContext['district_source'] === 'iiq_sync' ? 'IIQ sync' : 'NORA owners') ?>)
                </small>
              <?php else: ?>
                <span class="text-warning">Not available</span>
                <?php if ($iiqContext['district_source'] === 'error'): ?>
                  <small class="text-danger d-block">Error submitting IIQUSERSYNC.</small>
                <?php elseif ($iiqContext['district_source'] === 'none'): ?>
                  <small class="text-muted d-block">IIQUSERSYNC may still be running, or NORA has not written back yet.</small>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <?php if (!empty($iiqContext['iiq_notes'])): ?>
              <div class="mb-2">
                <strong>IIQ Sync Notes:</strong>
                <ul class="mb-0">
                  <?php foreach ($iiqContext['iiq_notes'] as $n): ?>
                    <li><?= htmlspecialchars($n) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <hr>

            <div class="mb-2">
              <strong>Charge HasInsurance (stored):</strong>
              <?= panda_badge_insurance($charge['HasInsurance']) ?>
            </div>

            <div class="mb-2">
              <strong>Skyward Coverage (context):</strong>
              <?= htmlspecialchars($iiqContext['skyward_status_label'] ?? 'Unknown') ?>
              <?php if (!empty($iiqContext['skyward_status_detail'])): ?>
                <br><small class="text-muted"><?= htmlspecialchars($iiqContext['skyward_status_detail']) ?></small>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Prior Usage -->
      <div class="card">
        <div class="card-header">
          Prior Usage This Year (Owner)
        </div>
        <div class="card-body small">
          <p class="mb-2">
            School Year: <strong><?= htmlspecialchars($charge['SchoolYear']) ?></strong>
          </p>
          <?php if (!$priorUsage): ?>
            <p class="text-muted mb-0">No other parts charged to this owner for this year.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-bordered mb-0">
                <thead>
                  <tr>
                    <th>Charge</th>
                    <th>Ticket</th>
                    <th>Part Code</th>
                    <th>Description</th>
                    <th class="text-end">Qty</th>
                    <th>Status</th>
                    <th>Related</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  $currentTicketRef = trim((string)($charge['TicketID'] ?? ''));
                  $currentPartCode = trim((string)($charge['PartCode'] ?? ''));
                ?>
                <?php foreach ($priorUsage as $row): ?>
                  <?php
                    $rowTicketRef = trim((string)($row['TicketID'] ?? ''));
                    $ticketMatch = false;
                    if ($currentTicketRef !== '' && $rowTicketRef !== '') {
                      if (ctype_digit($currentTicketRef) && ctype_digit($rowTicketRef)) {
                        $ticketMatch = ((int)$currentTicketRef === (int)$rowTicketRef);
                      } else {
                        $ticketMatch = (strcasecmp($currentTicketRef, $rowTicketRef) === 0);
                      }
                    }

                    $rowPartCode = trim((string)($row['PartCode'] ?? ''));
                    $partMatch = ($currentPartCode !== '' && strcasecmp($currentPartCode, $rowPartCode) === 0);

                    $relatedBits = [];
                    if ($ticketMatch) $relatedBits[] = 'Same ticket';
                    if ($partMatch) $relatedBits[] = 'Same part';
                    $relatedLabel = $relatedBits ? implode(' + ', $relatedBits) : '';
                  ?>
                  <tr class="<?= $ticketMatch ? 'table-info' : '' ?>">
                    <td>
                      <a href="PANDA_ChargeDecision.php?ChargeID=<?= (int)$row['ChargeID'] ?>"
                         target="_blank"
                         rel="noopener noreferrer">#<?= (int)$row['ChargeID'] ?></a>
                    </td>
                    <td><?= htmlspecialchars((string)($row['TicketID'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($row['PartCode']) ?></td>
                    <td><?= htmlspecialchars($row['PartDescription']) ?></td>
                    <td class="text-end"><?= (int)$row['Quantity'] ?></td>
                    <td>
                      <?= panda_badge_status((string)($row['Status'] ?? 'unknown')) ?>
                      <?php if (!empty($row['DecidedAt'])): ?>
                        <br><small class="text-muted"><?= htmlspecialchars((string)$row['DecidedAt']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($relatedLabel !== ''): ?>
                        <span class="badge bg-info text-dark"><?= htmlspecialchars($relatedLabel) ?></span>
                      <?php else: ?>
                        <span class="text-muted">-</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Admin Notes (editable, autosave) -->
      <div class="card">
        <div class="card-header">Admin Notes</div>
        <div class="card-body small">
          <textarea
            class="form-control panda-autosave"
            id="f_AdminNote"
            data-field="AdminNote"
            rows="4"
            <?= $canEditAdminNote ? '' : 'readonly disabled' ?>
            placeholder="What happened? What doesn't work? What have we fixed by replacing this?"><?= htmlspecialchars($charge['AdminNote'] ?? '') ?></textarea>
          <div class="autosave-status" id="s_AdminNote"></div>
          <small class="text-muted d-block mt-1">
            These notes are stored on the charge and summarized in history entries when edited.
          </small>
        </div>
      </div>

    </div>

    <!-- RIGHT COLUMN -->
    <div class="col-lg-6">

      <!-- Charge Details (editable fields) -->
      <div class="card">
        <div class="card-header">Charge Details</div>
        <div class="card-body small">
          <div class="mb-2">
            <strong>Ticket:</strong>
            <div class="input-group input-group-sm">
              <span class="input-group-text">IIQ #</span>
              <input type="text"
                     id="f_TicketID"
                     class="form-control panda-autosave"
                     data-field="TicketID"
                     <?= $canEditChargeFields ? '' : 'readonly disabled' ?>
                     value="<?= htmlspecialchars($charge['TicketID']) ?>">
            </div>
            <div class="autosave-status" id="s_TicketID"></div>
          </div>

          <div class="mb-2">
            <strong>Part:</strong><br>
            <span><?= htmlspecialchars($charge['PartCode']) ?></span><br>
            <small class="text-muted"><?= htmlspecialchars($charge['PartDescription']) ?></small>
          </div>

          <div class="row">
            <div class="col-4 mb-2">
              <label class="form-label form-label-sm"><strong>Quantity</strong></label>
              <input type="number"
                     min="1"
                     id="f_Quantity"
                     class="form-control form-control-sm"
                     value="<?= (int)$charge['Quantity'] ?>"
                     readonly
                     disabled>
              <div class="autosave-status" id="s_Quantity"></div>
              <small class="text-muted">Quantity is fixed for this charge.</small>
            </div>
            <div class="col-4 mb-2">
              <label class="form-label form-label-sm"><strong>School Year</strong></label>
              <select id="f_SchoolYear"
                      class="form-select form-select-sm panda-autosave"
                      data-field="SchoolYear"
                      <?= $canEditChargeFields ? '' : 'disabled' ?>>
                <?php
                $currentSY = $charge['SchoolYear'];
                if ($currentSY && !in_array($currentSY, $schoolYearOptions, true)) {
                    // Ensure current SchoolYear is present in dropdown
                    array_unshift($schoolYearOptions, $currentSY);
                    $schoolYearOptions = array_values(array_unique($schoolYearOptions));
                }
                foreach ($schoolYearOptions as $syOpt):
                ?>
                  <option value="<?= htmlspecialchars($syOpt) ?>" <?= ($syOpt === $currentSY ? 'selected' : '') ?>>
                    <?= htmlspecialchars($syOpt) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="autosave-status" id="s_SchoolYear"></div>
            </div>
            <div class="col-4 mb-2">
              <label class="form-label form-label-sm"><strong>Has Insurance?</strong></label>
              <select id="f_HasInsurance"
                      class="form-select form-select-sm panda-autosave"
                      data-field="HasInsurance"
                      <?= $canEditChargeFields ? '' : 'disabled' ?>>
                <?php foreach (['UNKNOWN','YES','NO'] as $opt): ?>
                  <option value="<?= $opt ?>" <?= ($charge['HasInsurance'] === $opt ? 'selected' : '') ?>>
                    <?= $opt ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="autosave-status" id="s_HasInsurance"></div>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label form-label-sm"><strong>Why Was the Part Replaced?</strong></label>
            <select id="f_PartReplacedWhy"
                    class="form-select form-select-sm panda-autosave"
                    data-field="PartReplacedWhy"
                    <?= $canEditChargeFields ? '' : 'disabled' ?>>
              <?php
              $whyOptions = ['Accidental','Vandalism','OEM Failure','Other'];
              $currentWhy = $charge['PartReplacedWhy'] ?? 'Accidental';
              foreach ($whyOptions as $opt):
              ?>
                <option value="<?= $opt ?>" <?= ($currentWhy === $opt ? 'selected' : '') ?>>
                  <?= $opt ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="autosave-status" id="s_PartReplacedWhy"></div>
            <small class="text-muted">
              Note: When a charge is created with "Vandalism", IsVandalism is auto-flagged, but that flag is not changed here.
            </small>
          </div>

          <hr>

          <div class="mb-2">
            <strong>Cost Summary</strong><br>
            <span>Unit Cost:</span> $<?= number_format((float)$charge['UnitCost'], 2) ?><br>
            <span>Total Cost:</span> $<?= number_format((float)$charge['TotalCost'], 2) ?><br>
            <span>Cost Type:</span> <?= htmlspecialchars($charge['CostType']) ?>
          </div>

          <div class="mb-2">
            <strong>Status:</strong> <?= panda_badge_status($charge['Status']) ?><br>
            <small class="text-muted">
              Created: <?= htmlspecialchars($charge['CreatedAt']) ?> • Updated: <?= htmlspecialchars($charge['UpdatedAt']) ?>
            </small>
            <?php if (!empty($charge['Submitter'])): ?>
              <br><small class="text-muted">
                Submitted By: <?= htmlspecialchars((string)$charge['Submitter']) ?>
              </small>
            <?php endif; ?>
            <?php if (!empty($charge['DecidedAt']) || !empty($charge['DecidedBy'])): ?>
              <br><small class="text-muted">
                Decided: <?= htmlspecialchars($charge['DecidedAt'] ?? '') ?>
                by <?= htmlspecialchars($charge['DecidedBy'] ?? '') ?>
              </small>
            <?php endif; ?>
          </div>

          <!-- Coverage Impact (Preview) -->
          <div class="card mt-3">
            <div class="card-header">Coverage Impact (Preview)</div>
            <div class="card-body small">
              <?php if ($coverageComposite && !empty($coverageComposite['components'])): ?>
                <p class="mb-1">
                  <strong>Tier:</strong> <code><?= htmlspecialchars((string)($coverageQuote['tier'] ?? $coverageTier)) ?></code><br>
                  <strong>Composite Rule:</strong>
                  <code><?= htmlspecialchars((string)($coverageComposite['label'] ?? 'LCD + Digitizer')) ?></code><br>
                  <strong>Source PartCode:</strong>
                  <code><?= htmlspecialchars((string)($coverageComposite['source_part_code'] ?? ($charge['PartCode'] ?? 'UNKNOWN'))) ?></code>
                </p>
                <hr class="my-2">
                <?php foreach (($coverageComposite['components'] ?? []) as $component): ?>
                  <div class="mb-2">
                    <strong>Component:</strong>
                    <code><?= htmlspecialchars((string)($component['part_code'] ?? 'UNKNOWN')) ?></code><br>
                    <small class="text-muted">
                      Prior finalized qty: <?= (int)($component['already_used_qty'] ?? 0) ?><br>
                      Full unit cost basis: $<?= number_format((float)($component['unit_cost'] ?? 0), 2) ?>
                    </small><br>
                    <?php if (!empty($component['rule']) && is_array($component['rule'])): ?>
                      <small class="text-muted">
                        Limits:
                        Free <?= (int)($component['rule']['FreeQtyPerYear'] ?? 0) ?> •
                        Discount <?= (int)($component['rule']['DiscountQtyPerYear'] ?? 0) ?>
                      </small><br>
                    <?php else: ?>
                      <small class="text-muted">No active component rule; full-cost behavior applies.</small><br>
                    <?php endif; ?>
                    <small class="text-muted">
                      This charge applies:
                      <?= (int)($component['this_charge']['free'] ?? 0) ?> free •
                      <?= (int)($component['this_charge']['discount'] ?? 0) ?> discounted •
                      <?= (int)($component['this_charge']['full'] ?? 0) ?> full
                    </small>
                  </div>
                <?php endforeach; ?>
                <small class="text-muted">
                  Final dollar amount is the sum of the component outcomes above.
                </small>
              <?php elseif (!$coverageImpact): ?>
                <p class="mb-1 text-muted">
                  No coverage rule exists for this part &amp; tier.
                </p>
                <small class="text-muted">
                  Charge PartCode: <code><?= htmlspecialchars($charge['PartCode']) ?></code><br>
                  Coverage Key: <code><?= htmlspecialchars($coverageRulePartCode ?? 'None') ?></code><br>
                  Mapping Key: <code><?= htmlspecialchars($coveragePartCode ?? 'None (using raw PartCode)') ?></code><br>
                  Tier: <code><?= htmlspecialchars($coverageTier) ?></code>
                </small>
              <?php else: ?>
                <p class="mb-1">
                  <strong>Tier:</strong>
                  <code><?= htmlspecialchars($coverageImpact['tier']) ?></code><br>
                  <strong>Rule Part:</strong>
                  <code><?= htmlspecialchars($coverageImpact['coverage_part_code']) ?></code>
                  — <?= htmlspecialchars($coverageImpact['rule']['PartDescription'] ?? '') ?>
                </p>
                <p class="mb-1">
                  <strong>Per-Year Limits:</strong><br>
                  Free: <?= (int)$coverageImpact['rule']['FreeQtyPerYear'] ?> •
                  Discount: <?= (int)$coverageImpact['rule']['DiscountQtyPerYear'] ?>
                </p>
                <p class="mb-1">
                  <strong>Usage This Year (for this part):</strong><br>
                  Charges: <?= (int)$coverageImpact['usage']['total_charges'] ?> •
                  Qty: <?= (int)$coverageImpact['usage']['total_qty'] ?>
                </p>
                <hr class="my-2">
                <p class="mb-1">
                  <strong>This Charge (Qty <?= (int)$charge['Quantity'] ?>):</strong><br>
                  Will be treated as:
                </p>
                <ul class="mb-1">
                  <li><?= (int)$coverageImpact['free_applied'] ?> × free</li>
                  <li><?= (int)$coverageImpact['discount_applied'] ?> × discounted</li>
                  <li><?= (int)$coverageImpact['fullcost_qty'] ?> × full cost</li>
                </ul>
                <small class="text-muted">
                  Final dollar amounts will be computed when you choose an approval action.
                </small>
              <?php endif; ?>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>

  <!-- FULL-WIDTH DECISION BAR -->
  <div class="row mt-3">
    <div class="col-12">
      <div class="card">
        <div class="card-header">Decision</div>
        <div class="card-body small">
          <form method="post" action="PANDA_ChargeDecision_Action.php" id="decisionForm" class="mb-0">
            <input type="hidden" name="ChargeID" value="<?= (int)$charge['ChargeID'] ?>">
            <input type="hidden" name="action" id="decisionAction" value="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

            <?php if ($canTakeDecisionActions): ?>
              <div class="alert alert-light border mb-2 py-2">
                <?php if ($isEffectiveHoldCoordinator): ?>
                  <div><strong>Escalate will submit: $<?= number_format($approveChargeAmount, 2) ?></strong></div>
                  <div class="text-muted">
                    <?= $isOemFailure
                        ? 'Why is OEM Failure, so Escalate automatically converts to Waive ($0.00).'
                        : htmlspecialchars($approveWhySimple) ?>
                  </div>
                  <div class="mt-1"><strong>Waive or Reject ends at: $0.00</strong></div>
                <?php else: ?>
                  <div><strong>Approve will submit: $<?= number_format($approveChargeAmount, 2) ?></strong></div>
                  <div class="text-muted"><?= htmlspecialchars($approveWhySimple) ?></div>
                  <?php if ($canUseTpp): ?>
                    <div class="mt-1"><strong>Use GSD TPP will submit: $<?= number_format($tppChargeAmount, 2) ?></strong></div>
                    <div class="text-muted">Same coverage quote, submitted with the GSD TPP fee path.</div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($canTakeDecisionActions): ?>
              <div class="d-flex flex-wrap gap-2 mb-2">
                <?php if ($isEffectiveHoldCoordinator): ?>
                  <button type="button"
                          class="btn btn-info btn-sm decision-btn"
                          data-action="waive"
                          <?= $canWaive ? '' : 'disabled' ?>>
                    Waive (Default)
                  </button>

                  <button type="button"
                          class="btn btn-danger btn-sm decision-btn"
                          data-action="reject"
                          <?= $canReject ? '' : 'disabled' ?>>
                    Reject
                  </button>

                  <button type="button"
                          class="btn btn-success btn-sm decision-btn"
                          data-action="escalate"
                          <?= $canEscalateFromHold ? '' : 'disabled' ?>>
                    Escalate (Submit)
                  </button>
                <?php else: ?>
                  <!-- Row 1: Approve + Use GSD TPP -->
                  <button type="button"
                          class="btn btn-success btn-sm decision-btn"
                          data-action="approve"
                          <?= $canApprove ? '' : 'disabled' ?>>
                    Approve ($<?= number_format($approveChargeAmount, 2) ?>)
                  </button>

                  <button type="button"
                          class="btn btn-primary btn-sm decision-btn"
                          data-action="claim_approved"
                          <?= $canUseTpp ? '' : 'disabled' ?>
                          title="<?= $canUseTpp ? '' : 'Use GSD TPP is only available when valid, PAID coverage is confirmed and the charge is billable.' ?>">
                    Use GSD TPP ($<?= number_format($tppChargeAmount, 2) ?>)
                  </button>

                  <!-- Row 2: Waive, Hold, Deny, Reject, Other Approval -->
                  <button type="button"
                          class="btn btn-info btn-sm decision-btn"
                          data-action="waive"
                          <?= $canWaive ? '' : 'disabled' ?>>
                    Waive
                  </button>

                  <button type="button"
                          class="btn btn-secondary btn-sm decision-btn"
                          data-action="hold"
                          <?= $canHold ? '' : 'disabled' ?>>
                    Hold
                  </button>

                  <button type="button"
                          class="btn btn-warning btn-sm decision-btn"
                          data-action="deny"
                          <?= $canDeny ? '' : 'disabled' ?>>
                    Deny
                  </button>

                  <button type="button"
                          class="btn btn-danger btn-sm decision-btn"
                          data-action="reject"
                          <?= $canReject ? '' : 'disabled' ?>>
                    Reject
                  </button>

                  <button type="button"
                          class="btn btn-outline-primary btn-sm decision-btn"
                          data-action="other_approved"
                          <?= $canOtherApproval ? '' : 'disabled' ?>>
                    Other Approval…
                  </button>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="alert alert-secondary py-2">
                This charge status is locked. No further decision actions are allowed.
              </div>
            <?php endif; ?>

            <small class="text-muted">
              Decision processing is handled by <code>PANDA_ChargeDecision_Action.php</code>.
            </small>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- NEW: CHARGE HISTORY / NOTES CARD -->
  <div class="row mt-3">
    <div class="col-12">
      <div class="card">
        <div class="card-header">
          Charge History &amp; Notes
        </div>
        <div class="card-body small">
          <?php if (!$historyRows): ?>
            <p class="text-muted mb-0">No history entries recorded yet for this charge.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th style="white-space:nowrap;">When</th>
                    <th>Actor</th>
                    <th>Action</th>
                    <th>Old → New Status</th>
                    <th>Note</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($historyRows as $h): ?>
                    <?php
                      $when  = $h['CreatedAt'] ?? '';
                      $actor = $h['ActorUser'] ?? '';
                      $atype = $h['ActionType'] ?? '';
                      $oldS  = $h['OldStatus'] ?? '';
                      $newS  = $h['NewStatus'] ?? '';
                      $note  = $h['NoteText']  ?? '';

                      // Action badge
                      $atypeLabel = strtoupper($atype ?: 'unknown');
                      $atypeClass = 'bg-secondary';
                      if ($atype === 'create')    $atypeClass = 'bg-primary';
                      elseif ($atype === 'status')   $atypeClass = 'bg-info text-dark';
                      elseif ($atype === 'comment')  $atypeClass = 'bg-success';
                      elseif ($atype === 'system')   $atypeClass = 'bg-dark';

                      $statusText = '';
                      if ($oldS || $newS) {
                          $statusText = htmlspecialchars($oldS ?: '∅') . ' → ' . htmlspecialchars($newS ?: '∅');
                      }
                    ?>
                    <tr>
                      <td style="white-space:nowrap;"><?= htmlspecialchars($when) ?></td>
                      <td><?= htmlspecialchars($actor) ?></td>
                      <td>
                        <span class="badge <?= $atypeClass ?>">
                          <?= htmlspecialchars($atypeLabel) ?>
                        </span>
                      </td>
                      <td><?= $statusText ?: '<span class="text-muted">n/a</span>' ?></td>
                      <td>
                        <?php if ($note !== ''): ?>
                          <div class="history-note"><?= htmlspecialchars($note) ?></div>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?= panda_render_charge_permission_footer($allowed) ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const canEditChargeFields = <?= json_encode($canEditChargeFields) ?>;
const canEditAdminNote = <?= json_encode($canEditAdminNote) ?>;
const canEditAnyAutosaveFields = (canEditChargeFields || canEditAdminNote);
const canTakeDecisionActions = <?= json_encode($canTakeDecisionActions) ?>;
const previewHoldCoordinator = <?= json_encode($previewHoldCoordinator) ?>;
const canUsePreviewToggle = <?= json_encode($canManageCharges && !$isHoldCoordinator) ?>;
// ----------------------------
// IIQ context AJAX refresh
// ----------------------------
document.addEventListener('DOMContentLoaded', function () {
  const wrapper = document.getElementById('iiqContextWrapper');
  const chargeId = <?= (int)$charge['ChargeID'] ?>;
  const previewToggle = document.getElementById('previewHoldToggle');

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

  if (wrapper) {
    wrapper.addEventListener('click', function (ev) {
      const btn = ev.target.closest('#btnRefreshIIQ');
      if (!btn) return;
      btn.disabled = true;
      btn.textContent = 'Refreshing…';

      fetch('PANDA_ChargeDecision.php?ChargeID=' + encodeURIComponent(chargeId) + '&ajax=iiq_context' + (previewHoldCoordinator ? '&preview_hold_mode=1' : ''), {
        credentials: 'same-origin'
      })
        .then(resp => resp.text())
        .then(html => {
          wrapper.innerHTML = html;
        })
        .catch(() => {
          alert('Failed to refresh IIQ context.');
        })
        .finally(() => {
          const newBtn = document.getElementById('btnRefreshIIQ');
          if (newBtn) {
            newBtn.disabled = false;
            newBtn.textContent = 'Refresh';
          }
        });
    });
  }

  // ----------------------------
  // Autosave engine
  // ----------------------------
  const fields = canEditAnyAutosaveFields ? document.querySelectorAll('.panda-autosave') : [];
  const chargeID = <?= (int)$charge['ChargeID'] ?>;
  const decisionCsrfToken = <?= json_encode($csrfToken) ?>;

  function autosave(field, value) {
    const fieldName = field.dataset.field;
    if (!fieldName) return;

    const statusEl = document.getElementById('s_' + fieldName);
    if (statusEl) {
      statusEl.textContent = 'Saving…';
      statusEl.classList.remove('ok', 'err');
      statusEl.classList.add('saving');
    }

    const body = new URLSearchParams();
    body.set('ChargeID', String(chargeID));
    body.set('field', fieldName);
    body.set('value', value);
    body.set('csrf_token', decisionCsrfToken);

    fetch('PANDA_ChargeDecision_AutoSave.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(async (r) => {
        const text = await r.text();
        let data = null;
        try {
          data = JSON.parse(text);
        } catch (e) {}

        if (!r.ok) {
          throw new Error(data && data.error ? data.error : (text || 'Request failed'));
        }

        return data;
      })
      .then(data => {
        if (data && data.status === 'OK') {
          if (statusEl) {
            statusEl.textContent = 'Saved.';
            statusEl.classList.remove('saving', 'err');
            statusEl.classList.add('ok');
          }
        } else {
          throw new Error(data && data.error ? data.error : 'Unknown error');
        }
      })
      .catch(err => {
        if (statusEl) {
          statusEl.textContent = 'Error: ' + err.message;
          statusEl.classList.remove('saving', 'ok');
          statusEl.classList.add('err');
        }
      });
  }

  if (canEditAnyAutosaveFields) {
    fields.forEach((el) => {
      let timeoutId = null;

      const handler = () => {
        const fieldName = el.dataset.field;
        if (!fieldName) return;
        const value = (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT' || el.tagName === 'SELECT')
          ? el.value
          : '';
        const debounceMs = (fieldName === 'AdminNote' || fieldName === 'TicketID') ? 2500 : 400;

        if (timeoutId) {
          clearTimeout(timeoutId);
        }
        timeoutId = setTimeout(() => autosave(el, value), debounceMs);
      };

      if (el.tagName === 'SELECT') {
        el.addEventListener('change', handler);
      } else {
        el.addEventListener('input', handler);
        el.addEventListener('blur', handler);
      }
    });
  }

  // ----------------------------
  // Decision actions (bottom buttons + top dropdown)
  // ----------------------------
  const decisionForm   = document.getElementById('decisionForm');
  const decisionAction = document.getElementById('decisionAction');

  function triggerDecision(action) {
    if (!decisionForm || !decisionAction) return;
    if (previewHoldCoordinator) {
      alert('Preview limited mode is ON. This is a visual simulation only; no decision was saved.');
      return;
    }
    decisionAction.value = action;
    decisionForm.submit();
  }

  // Bottom bar buttons
  if (canTakeDecisionActions) {
    document.querySelectorAll('.decision-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        if (this.disabled) return;
        const action = this.getAttribute('data-action');
        if (!action) return;
        triggerDecision(action);
      });
    });

    // Top dropdown items
    document.querySelectorAll('.decision-action').forEach(link => {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        if (this.classList.contains('disabled')) return;
        const action = this.getAttribute('data-action');
        if (!action) return;
        triggerDecision(action);
      });
    });
  }
});
</script>

</body>
</html>
