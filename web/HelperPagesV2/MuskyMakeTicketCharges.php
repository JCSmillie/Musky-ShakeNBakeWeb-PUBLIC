<?php
// ============================================================================
// HelperPagesV2/MuskyMakeTicketCharges.php
// ----------------------------------------------------------------------------
// FULL RESTORE BUILD
// + Lookup Orchestration (IIQTICKETSYNC, IIQUSERSYNC, INV_LOOKUP)
// + Confirm button locked until errands complete
// + Auto refresh when errands finish
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../PANDA/PANDA_Functions.php';
require_once __DIR__ . '/ChargeServices.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';
require_once __DIR__ . '/../_tool_guard.php';

date_default_timezone_set('America/New_York');

panda_require_charges_enabled(isset($_GET['api']) ? 'json' : 'html');

// -----------------------------------------------------------------------------
// USER PREFS + ACCESS
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$theme   = $prefs['theme'];
$email   = $prefs['email'];

$tool_required_variants = [
    'PANDA_CASHIER',
    'PANDA_ADMIN',
    'PANDA_MANAGER'
];

$allowed = musky_require_general_admin_access(
    $prefs['allowed_tools'] ?? [],
    $tool_required_variants,
    [
        'response' => 'html',
        'status' => 403,
        'message' => '⛔ Access Denied — Missing Required Tool Variant.',
    ]
);

$pdo = panda_db();

// -----------------------------------------------------------------------------
// INITIAL STATE
// -----------------------------------------------------------------------------
$errors = [];
$device_info = null;
$owner_info  = null;

$ticket_context = null;
$ticket_row     = null;
$ticket_info    = null;

$parts          = [];
$price_by_id    = [];
$part_history   = [];
$charge_history = [];
$owner_history  = [];
$owner_choices  = [];
$recent_duplicate_matches = [];

$staged       = [];
$total_amount = 0.0;

$lookup_errand_ids = $_SESSION['lookup_errands'] ?? [];
$editing_parts = isset($_POST['edit_parts']) && $_POST['edit_parts'] === '1';
$review_cache_key = 'musky_make_ticket_charge_review';

$step = $_POST['step'] ?? ($_GET['step'] ?? 'lookup');

$asset_tag          = trim($_POST['asset_tag'] ?? ($_GET['asset_tag'] ?? ''));
$ticket_id          = trim($_POST['ticket_id'] ?? ($_GET['ticket_id'] ?? ''));
$school_year        = trim($_POST['school_year'] ?? '2025-26');
$submitter          = trim($_POST['submitter'] ?? $email);
$selected_owner_id  = (int)($_POST['selected_owner_id'] ?? ($_GET['selected_owner_id'] ?? 0));
$posted_parts       = [];
$posted_refurb_parts = [];
$refurb_used        = strtoupper(trim((string)($_POST['refurb_used'] ?? '')));
$confirm_recent_duplicate = isset($_POST['confirm_recent_duplicate'])
    && $_POST['confirm_recent_duplicate'] === '1';
$after_actions_window_name = trim((string)($_POST['after_actions_window_name'] ?? ''));

if (!empty($_POST['parts']) && is_array($_POST['parts'])) {
    $posted_parts = $_POST['parts'];
}

if (!empty($_POST['refurb_parts']) && is_array($_POST['refurb_parts'])) {
    $posted_refurb_parts = $_POST['refurb_parts'];
}

if (!$posted_parts
    && $step === 'review'
    && isset($_GET['refresh']) && $_GET['refresh'] == 1
    && !empty($_SESSION[$review_cache_key])
    && is_array($_SESSION[$review_cache_key])) {

    $cachedReview = $_SESSION[$review_cache_key];
    $cachedAsset  = (string)($cachedReview['asset_tag'] ?? '');
    $cachedTicket = (string)($cachedReview['ticket_id'] ?? '');
    $cachedParts  = $cachedReview['parts'] ?? [];

    if ($cachedAsset === $asset_tag
        && $cachedTicket === $ticket_id
        && is_array($cachedParts)) {
        $posted_parts = $cachedParts;
    }
}

function v2_device_model_string(?array $device_info): string {
    if (!$device_info) return '';
    $m = trim((string)($device_info['device_model'] ?? ''));
    if ($m !== '') return $m;
    $m = trim((string)($device_info['model'] ?? ''));
    if ($m !== '') return $m;
    $m = trim((string)($device_info['ProductName'] ?? ''));
    return $m;
}

function v2_part_group_from_code(string $partCode): string {
    $partCode = strtoupper(trim($partCode));
    if ($partCode === '') return 'standard';
    if (str_starts_with($partCode, 'AGI-')) return 'agi';
    if (str_starts_with($partCode, 'GATOR-')) return 'gator';
    return 'standard';
}

function v2_refurb_note_text(string $ticketId): string {
    $ticketId = trim($ticketId);
    if ($ticketId === '') {
        return 'Part Need in IIQ # UNKNOWN to put device back inservice.';
    }
    return 'Part Need in IIQ # ' . $ticketId . ' to put device back inservice.';
}

function v2_build_charge_owner_choices(PDO $pdo, ?array $deviceInfo, array $ownerHistory): array {
    $choices = [];
    $seen    = [];

    $currentOwnerId = (int)($deviceInfo['owner_id'] ?? 0);
    if ($currentOwnerId > 0) {
        $currentOwner = v2_lookup_owner($pdo, $currentOwnerId);
        if ($currentOwner) {
            $choices[] = [
                'id'            => $currentOwnerId,
                'name'          => trim((string)($currentOwner['full_name'] ?? '')),
                'email'         => trim((string)($currentOwner['email'] ?? '')),
                'grade'         => trim((string)($currentOwner['grade'] ?? '')),
                'district_id'   => trim((string)($currentOwner['district_id'] ?? '')),
                'snapshot_time' => '',
                'is_current'    => true,
            ];
            $seen[$currentOwnerId] = true;
        }
    }

    foreach ($ownerHistory as $row) {
        $ownerId = (int)($row['owner_id'] ?? 0);
        if ($ownerId <= 0 || isset($seen[$ownerId])) {
            continue;
        }

        $ownerRecord = v2_lookup_owner($pdo, $ownerId);
        if (!$ownerRecord) {
            continue;
        }

        $choices[] = [
            'id'            => $ownerId,
            'name'          => trim((string)($ownerRecord['full_name'] ?? $row['owner_name'] ?? '')),
            'email'         => trim((string)($ownerRecord['email'] ?? $row['owner_email'] ?? '')),
            'grade'         => trim((string)($ownerRecord['grade'] ?? '')),
            'district_id'   => trim((string)($ownerRecord['district_id'] ?? $row['owner_userid'] ?? '')),
            'snapshot_time' => trim((string)($row['snapshot_time'] ?? '')),
            'is_current'    => false,
        ];
        $seen[$ownerId] = true;
    }

    return $choices;
}

function v2_format_charge_owner_choice(array $choice): string {
    $parts = [];

    $name = trim((string)($choice['name'] ?? ''));
    $email = trim((string)($choice['email'] ?? ''));
    $districtId = trim((string)($choice['district_id'] ?? ''));
    $grade = trim((string)($choice['grade'] ?? ''));
    $snapshotTime = trim((string)($choice['snapshot_time'] ?? ''));
    $isCurrent = !empty($choice['is_current']);

    $parts[] = $name !== '' ? $name : ('Owner #' . (int)($choice['id'] ?? 0));
    if ($email !== '') {
        $parts[] = $email;
    }
    if ($districtId !== '') {
        $parts[] = 'ID ' . $districtId;
    }
    if ($grade !== '') {
        $parts[] = 'Grade ' . $grade;
    }

    $suffix = $isCurrent ? 'Current owner' : 'Former owner';
    if (!$isCurrent && $snapshotTime !== '') {
        $ts = strtotime($snapshotTime);
        if ($ts !== false) {
            $suffix .= ' as of ' . date('Y-m-d', $ts);
        }
    }

    return implode(' — ', $parts) . ' [' . $suffix . ']';
}

function v2_ticket_validation_message(array $ticketContext, int $selectedOwnerKey): string {
    $reason = trim((string)($ticketContext['reason'] ?? ''));

    switch ($reason) {
        case 'ticket_not_found':
            return 'Ticket validation failed because IncidentIQ ticket data could not be found yet. Try lookup again in a moment.';
        case 'ticket_email_mismatch':
            return 'Ticket validation failed because the selected charge owner did not match the ticket owner/for emails. If needed, choose a different former owner from device history.';
        case 'ticket_device_mismatch':
            return 'Ticket validation failed because the ticket is not currently linked to this device in IncidentIQ.';
        default:
            return 'Ticket validation failed for the selected owner.'
                . ($selectedOwnerKey > 0 ? ' Selected owner key: ' . $selectedOwnerKey . '.' : '');
    }
}

// -----------------------------------------------------------------------------
// Helper: normalize the two ticket identities we now care about
// -----------------------------------------------------------------------------
// Musky users usually type the human ticket number (example: 75478), but Nora's
// IIQ follow-up work needs the real IncidentIQ TicketId GUID for activity/note
// endpoints. We keep both values together so the UI can stay human-friendly
// while the after-actions use the machine-safe identifier.
// -----------------------------------------------------------------------------
function v2_resolve_ticket_identity(string $enteredTicketId, ?array $ticket_context, ?array $ticket_row): array {
    $enteredTicketId = trim($enteredTicketId);

    $ticketGuid = trim((string)($ticket_row['TicketId'] ?? ''));
    if ($ticketGuid === '' && !ctype_digit($enteredTicketId)) {
        // If the user already typed a GUID directly, keep using it.
        $ticketGuid = $enteredTicketId;
    }

    $ticketNumber = trim((string)($ticket_row['TicketNumber'] ?? ''));
    if ($ticketNumber === '') {
        $ticketNumber = trim((string)($ticket_context['ticket_number'] ?? ''));
    }
    if ($ticketNumber === '' && ctype_digit($enteredTicketId)) {
        $ticketNumber = $enteredTicketId;
    }

    return [
        'ticket_guid'          => $ticketGuid,
        'ticket_number'        => $ticketNumber,
        'ticket_lookup_input'  => $enteredTicketId,
    ];
}

function v2_get_lookup_gate_state(array $errandIds): array {
    $items = [];
    $allComplete = !empty($errandIds);
    $hasFailure = false;

    foreach ($errandIds as $errandId) {
        $id = (int)$errandId;
        if ($id <= 0) {
            continue;
        }

        $status = 'unknown';
        $raw = musky_nora_api_get_json('/errand/status/' . $id);
        if (is_array($raw) && !empty($raw['Status'])) {
            $status = strtolower(trim((string)$raw['Status']));
        }

        $isComplete = ($status === 'complete');
        if (!$isComplete) {
            $allComplete = false;
        }

        if (!in_array($status, ['complete', 'submitted', 'queued', 'processing'], true)) {
            $hasFailure = true;
        }

        $items[] = [
            'id'          => $id,
            'status'      => $status,
            'is_complete' => $isComplete,
        ];
    }

    if (empty($items)) {
        $allComplete = true;
    }

    $blockers = [];
    if (!$allComplete) {
        foreach ($items as $item) {
            if (!$item['is_complete']) {
                $blockers[] = sprintf(
                    'Errand #%d is still %s.',
                    (int)$item['id'],
                    strtoupper((string)$item['status'])
                );
            }
        }
    }

    return [
        'items'        => $items,
        'all_complete' => $allComplete,
        'has_failure'  => $hasFailure,
        'blockers'     => $blockers,
    ];
}

if (isset($_GET['api']) && $_GET['api'] === 'errand_statuses') {
    header('Content-Type: application/json');

    $requestedIds = $_GET['ids'] ?? [];
    if (!is_array($requestedIds)) {
        $requestedIds = [$requestedIds];
    }

    echo json_encode(v2_get_lookup_gate_state($requestedIds), JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================================
// STEP 1 — LOOKUP + ERRAND ORCHESTRATION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'lookup') {

    unset($_SESSION[$review_cache_key]);

    if ($asset_tag === '') $errors[] = "Asset Tag is required.";
    if ($ticket_id === '') $errors[] = "Ticket ID is required.";

    if (!$errors) {
        $device_info = v2_lookup_device($pdo, $asset_tag);
        if (!$device_info) {
            $errors[] = "No device found with that Asset Tag.";
        }
    }

    if (!$errors && $device_info) {

        $owner_history = v2_load_owner_history($pdo, $asset_tag);

        if ($selected_owner_id <= 0) {
            $selected_owner_id = (int)($device_info['owner_id'] ?? 0);
        }

        if ($selected_owner_id > 0) {
            $owner_info = v2_lookup_owner_from_selection($pdo, $selected_owner_id);
        }

        if (!$owner_info) {
            $errors[] = "NO OWNER SELECTED.";
        }
    }

    if (!$errors && $device_info && $owner_info) {

        $ticket_context = panda_validate_ticket_for_charge(
            $pdo,
            $ticket_id,
            $device_info,
            $owner_info,
            $submitter
        );

        if (!$ticket_context['ok']) {
            $errors[] = v2_ticket_validation_message($ticket_context, $selected_owner_id);
        } else {

            $ticket_info = [
                'ticket_id'     => $ticket_id,
                'ticket_number' => $ticket_context['ticket_number'] ?? null,
                'owner_email'   => $ticket_context['owner_email'] ?? null,
                'for_email'     => $ticket_context['for_email'] ?? null
            ];

            $lookup_errand_ids = [];

            // IIQTICKETSYNC
            $resp = musky_nora_api_post_json('/errand/create', [
                "IIQRequest" => "TRUE",
                "AssetTag"   => $asset_tag,
                "ExtraDataField02" => $ticket_id,
                "ExtraDataField05" => "IIQTICKETSYNC"
            ]);
            if (!empty($resp['ErrandID'])) {
                $lookup_errand_ids[] = (int)$resp['ErrandID'];
            }

            // IIQUSERSYNC
            $emails = array_unique(array_filter(array_merge(
                v2_load_owner_candidate_emails($pdo, $device_info, $owner_info),
                [
                    panda_norm_email($ticket_info['owner_email'] ?? ''),
                    panda_norm_email($ticket_info['for_email'] ?? '')
                ]
            )));

            foreach ($emails as $email_sync) {
                $resp = musky_nora_api_post_json('/errand/create', [
                    "IIQRequest" => "TRUE",
                    "DeviceOwner" => $email_sync,
                    "ExtraDataField02" => $email_sync,
                    "ExtraDataField05" => "IIQUSERSYNC"
                ]);
                if (!empty($resp['ErrandID'])) {
                    $lookup_errand_ids[] = (int)$resp['ErrandID'];
                }
            }

            // INV_LOOKUP
            $resp = musky_nora_api_post_json('/errand/create', [
                "NoraRequest" => "TRUE",
                "DeviceSerial" => $device_info['serial_number'] ?? '',
                "ExtraDataField05" => "INV_LOOKUP"
            ]);
            if (!empty($resp['ErrandID'])) {
                $lookup_errand_ids[] = (int)$resp['ErrandID'];
            }

            $_SESSION['lookup_errands'] = $lookup_errand_ids;
            $step = 'review';
        }
    }
}
// ============================================================================
// AUTO REFRESH SUPPORT (after errands complete)
// ============================================================================
if (isset($_GET['refresh']) && $_GET['refresh'] == 1) {

    if ($asset_tag && $ticket_id) {

        $device_info = v2_lookup_device($pdo, $asset_tag);

        if ($device_info) {
            if ($selected_owner_id <= 0) {
                $selected_owner_id = (int)($device_info['owner_id'] ?? 0);
            }
            $owner_info = v2_lookup_owner_from_selection($pdo, $selected_owner_id);
            $owner_history = v2_load_owner_history($pdo, $asset_tag);
        }

        if ($device_info && $owner_info) {

            $ticket_context = panda_validate_ticket_for_charge(
                $pdo,
                $ticket_id,
                $device_info,
                $owner_info,
                $submitter
            );

            if ($ticket_context['ok']) {
                if (function_exists('panda_iiq_lookup_ticket')) {
                    $ticket_row = panda_iiq_lookup_ticket($pdo, $ticket_id);
                    if (!$ticket_row && !empty($ticket_context['ticket_number'])) {
                        $ticket_row = panda_iiq_lookup_ticket(
                            $pdo,
                            (string)$ticket_context['ticket_number']
                        );
                    }
                }
            }
        }

        $step = 'review';
    }
}

// ============================================================================
// STEP 2 — REVIEW
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'review') {

    $device_info = v2_lookup_device($pdo, $asset_tag);

    if ($device_info && $selected_owner_id <= 0) {
        $selected_owner_id = (int)($device_info['owner_id'] ?? 0);
    }

    $owner_info = ($selected_owner_id > 0)
        ? v2_lookup_owner_from_selection($pdo, $selected_owner_id)
        : null;

    if (!$device_info || !$owner_info) {
        $errors[] = "Device or Owner missing.";
    }

    if (!$errors) {
        $owner_history = v2_load_owner_history($pdo, $asset_tag);
    }

    if (!$errors) {

        $ticket_context = panda_validate_ticket_for_charge(
            $pdo,
            $ticket_id,
            $device_info,
            $owner_info,
            $submitter
        );

        $ticket_info = [
            'ticket_id'     => $ticket_id,
            'ticket_number' => $ticket_context['ticket_number'] ?? null,
            'owner_email'   => $ticket_context['owner_email'] ?? null,
            'for_email'     => $ticket_context['for_email'] ?? null,
        ];

        if (function_exists('panda_iiq_lookup_ticket')) {
            $ticket_row = panda_iiq_lookup_ticket($pdo, $ticket_id);
            if (!$ticket_row && !empty($ticket_context['ticket_number'])) {
                $ticket_row = panda_iiq_lookup_ticket(
                    $pdo,
                    (string)$ticket_context['ticket_number']
                );
            }
        }

        if (!$ticket_context['ok']) {
            $errors[] = "Ticket validation failed: "
                . ($ticket_context['reason'] ?? 'unknown');
        }
    }

    if (!$errors) {
        [$parts, $price_by_id] =
            v2_load_parts_and_pricing($pdo, $school_year);

        $part_history =
            v2_load_part_history($pdo, (int)$device_info['id']);
    }

    if (!$errors && $posted_parts) {
        $_SESSION[$review_cache_key] = [
            'asset_tag' => $asset_tag,
            'ticket_id' => $ticket_id,
            'parts'     => $posted_parts,
        ];
    }

    // Build staged list if parts posted
    if (!$errors && !$editing_parts && !empty($posted_parts)) {

        $staged = [];
        $total_amount = 0.0;

        foreach ($posted_parts as $pid => $row) {

            if (!isset($row['use']) || $row['use'] !== '1') {
                continue;
            }

            $partId   = (int)$pid;
            $unitCost =
                (float)($price_by_id[$partId]['CostFull'] ?? 0);

            if ($unitCost <= 0) continue;

            if (!function_exists('panda_normalize_reason_and_note')) {
                $errors[] =
                    "Missing function panda_normalize_reason_and_note().";
                break;
            }

            [$enumReason, $finalNote, $isVandal] =
                panda_normalize_reason_and_note(
                    $row['reason'] ?? '',
                    $row['note'] ?? ''
                );

            if ($enumReason === '') continue;

            $staged[] = [
                'PartID'          => $partId,
                'UnitCost'        => $unitCost,
                'Quantity'        => 1,
                'TotalCost'       => $unitCost,
                'PartReplacedWhy' => $enumReason,
                'Note'            => $finalNote,
                'IsVandalism'     => $isVandal,
            ];

            $total_amount += $unitCost;
        }

        if (!$errors && !$staged) {
            $errors[] = "Select at least one part.";
        }
    }
}

// ============================================================================
// STEP 3 — COMMIT
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'commit') {

    $device_info = v2_lookup_device($pdo, $asset_tag);

    if ($device_info && $selected_owner_id <= 0) {
        $selected_owner_id = (int)($device_info['owner_id'] ?? 0);
    }

    $owner_info = ($selected_owner_id > 0)
        ? v2_lookup_owner_from_selection($pdo, $selected_owner_id)
        : null;

    if (!$device_info || !$owner_info) {
        $errors[] = "Device or Owner missing.";
    }

    if (!$errors) {

        $ticket_context = panda_validate_ticket_for_charge(
            $pdo,
            $ticket_id,
            $device_info,
            $owner_info,
            $submitter
        );

        if (!$ticket_context['ok']) {
            $errors[] = "Ticket validation failed: "
                . ($ticket_context['reason'] ?? 'unknown');
        } else {
            $ticket_info = [
                'ticket_id'     => $ticket_id,
                'ticket_number' => $ticket_context['ticket_number'] ?? null,
                'owner_email'   => $ticket_context['owner_email'] ?? null,
                'for_email'     => $ticket_context['for_email'] ?? null,
            ];

            if (function_exists('panda_iiq_lookup_ticket')) {
                $ticket_row =
                    panda_iiq_lookup_ticket($pdo, $ticket_id);
                if (!$ticket_row
                    && !empty($ticket_context['ticket_number'])) {
                    $ticket_row =
                        panda_iiq_lookup_ticket(
                            $pdo,
                            (string)$ticket_context['ticket_number']
                        );
                }
            }
        }
    }

    if (!$errors) {

        [$parts, $price_by_id] =
            v2_load_parts_and_pricing($pdo, $school_year);

        if (!function_exists('panda_normalize_reason_and_note')) {
            $errors[] =
                "Missing function panda_normalize_reason_and_note().";
        }
    }

    if (!$errors) {
        $staged = [];
        $total_amount = 0.0;
        $selectedPartIds = [];

        foreach ($posted_parts as $pid => $row) {

            if (!isset($row['use']) || $row['use'] !== '1') continue;

            $partId   = (int)$pid;
            $unitCost = (float)($price_by_id[$partId]['CostFull'] ?? 0);
            if ($unitCost <= 0) continue;

            [$enumReason, $finalNote, $isVandal] =
                panda_normalize_reason_and_note(
                    $row['reason'] ?? '',
                    $row['note'] ?? ''
                );

            if ($enumReason === '') continue;

            $staged[] = [
                'PartID'          => $partId,
                'UnitCost'        => $unitCost,
                'Quantity'        => 1,
                'TotalCost'       => $unitCost,
                'PartReplacedWhy' => $enumReason,
                'Note'            => $finalNote,
                'IsVandalism'     => $isVandal,
            ];

            $selectedPartIds[$partId] = $partId;
            $total_amount += $unitCost;
        }

        if ($device_info && $selectedPartIds) {
            $recent_duplicate_matches = panda_load_recent_matching_device_charges(
                $pdo,
                (int)$device_info['id'],
                array_values($selectedPartIds),
                30
            );
        }
    }

    if (!$errors) {

        if ($recent_duplicate_matches && !$confirm_recent_duplicate) {
            $errors[] = "Confirm that you intend to create charges for parts already installed on this device within the last 30 days.";
        }

        if ($refurb_used !== 'YES' && $refurb_used !== 'NO') {
            $errors[] = "Answer the refurbishment parts question before submitting.";
        }

        if ($refurb_used === 'YES') {
            $hasRefurbSelection = false;
            foreach ($posted_refurb_parts as $row) {
                if (isset($row['use']) && $row['use'] === '1') {
                    $hasRefurbSelection = true;
                    break;
                }
            }

            if (!$hasRefurbSelection) {
                $errors[] = "Choose at least one refurbishment part, or select No.";
            }
        }
    }

    if (!$errors) {

        try {

            $part_meta_by_id = [];
            foreach ($parts as $pm) {
                $pid = (int)($pm['PartID'] ?? 0);
                if ($pid > 0) {
                    $part_meta_by_id[$pid] = $pm;
                }
            }

            $pdo->beginTransaction();

            $insert = $pdo->prepare("
                INSERT INTO panda_charges
                (
                    DeviceID,
                    OwnerID,
                    PartID,
                    PartCode,
                    PartDescription,
                    Quantity,
                    TicketID,
                    Submitter,
                    Status,
                    CostType,
                    UnitCost,
                    TotalCost,
                    SchoolYear,
                    IsVandalism,
                    PartReplacedWhy,
                    CreatedAt,
                    UpdatedAt
                )
                VALUES
                (
                    :device,
                    :owner,
                    :part,
                    :pcode,
                    :pdesc,
                    :qty,
                    :ticket,
                    :submitter,
                    'submitted',
                    'FULL',
                    :unit,
                    :total,
                    :sy,
                    :vand,
                    :why,
                    NOW(),
                    NOW()
                )
            ");

            $insertHistory = $pdo->prepare("
                INSERT INTO panda_charge_status_history
                (
                    ChargeID,
                    OldStatus,
                    NewStatus,
                    ActorUser,
                    ActionType,
                    NoteText,
                    CreatedAt
                )
                VALUES
                (
                    :charge_id,
                    NULL,
                    'submitted',
                    :actor,
                    'create',
                    :note_text,
                    NOW()
                )
            ");

            $rowsToInsert = [];
            $createdCharges = [];

            $total_amount = 0.0;
            foreach ($posted_parts as $pid => $row) {

                if (!isset($row['use']) || $row['use'] !== '1') continue;

                $partId   = (int)$pid;
                $unitCost = (float)($price_by_id[$partId]['CostFull'] ?? 0);
                if ($unitCost <= 0) continue;

                [$enumReason, $finalNote, $isVandal] =
                    panda_normalize_reason_and_note(
                        $row['reason'] ?? '',
                        $row['note'] ?? ''
                    );

                if ($enumReason === '') continue;

                $meta  = $part_meta_by_id[$partId] ?? [];
                $pcode = (string)($meta['PartCode'] ?? '');
                $pdesc = (string)($meta['PartName'] ?? '');

                if ($pcode === '') $pcode = 'UNKNOWN';
                if ($pdesc === '') $pdesc = 'Unknown Part';

                $rowsToInsert[] = [
                    'part_id'    => $partId,
                    'part_code'  => $pcode,
                    'part_desc'  => $pdesc,
                    'unit_cost'  => $unitCost,
                    'total_cost' => $unitCost,
                    'is_vandal'  => $isVandal,
                    'why'        => $enumReason,
                    'note'       => $finalNote,
                ];

                $total_amount += $unitCost;
            }

            if ($refurb_used === 'YES') {
                foreach ($posted_refurb_parts as $pid => $row) {

                    if (!isset($row['use']) || $row['use'] !== '1') continue;

                    $partId = (int)$pid;
                    $meta   = $part_meta_by_id[$partId] ?? [];
                    $pcode  = (string)($meta['PartCode'] ?? '');
                    $pdesc  = (string)($meta['PartName'] ?? '');

                    if (v2_part_group_from_code($pcode) !== 'standard') {
                        continue;
                    }

                    if ($pcode === '') $pcode = 'UNKNOWN';
                    if ($pdesc === '') $pdesc = 'Unknown Part';

                    [$enumReason, $finalNote, $isVandal] =
                        panda_normalize_reason_and_note(
                            'REFURB',
                            $refurbNoteTemplate
                        );

                    $rowsToInsert[] = [
                        'part_id'    => $partId,
                        'part_code'  => $pcode,
                        'part_desc'  => $pdesc,
                        'unit_cost'  => 0.00,
                        'total_cost' => 0.00,
                        'is_vandal'  => $isVandal,
                        'why'        => $enumReason,
                        'note'       => $finalNote,
                    ];
                }
            }

            foreach ($rowsToInsert as $entry) {
                $insert->execute([
                    ':device'    => (int)$device_info['id'],
                    ':owner'     => (int)$owner_info['id'],
                    ':part'      => (int)$entry['part_id'],
                    ':pcode'     => $entry['part_code'],
                    ':pdesc'     => $entry['part_desc'],
                    ':qty'       => 1,
                    ':ticket'    => $ticket_id,
                    ':submitter' => $submitter,
                    ':unit'      => $entry['unit_cost'],
                    ':total'     => $entry['total_cost'],
                    ':sy'        => $school_year,
                    ':vand'      => $entry['is_vandal'],
                    ':why'       => $entry['why'],
                ]);

                $chargeId = (int)$pdo->lastInsertId();

                $insertHistory->execute([
                    ':charge_id' => $chargeId,
                    ':actor'     => $submitter,
                    ':note_text' => $entry['note'] !== ''
                        ? $entry['note']
                        : ('Created via MuskyMakeTicketCharges for part ' . $entry['part_code']),
                ]);

                $createdCharges[] = [
                    'charge_id'         => $chargeId,
                    'part_id'           => (int)$entry['part_id'],
                    'part_code'         => (string)$entry['part_code'],
                    'part_description'  => (string)$entry['part_desc'],
                    'possible_amount'   => (float)$entry['total_cost'],
                    'reason'            => (string)$entry['why'],
                    'note'              => (string)$entry['note'],
                ];
            }

            $pdo->commit();

            unset($_SESSION['lookup_errands']);
            unset($_SESSION[$review_cache_key]);
            if (!isset($_SESSION['musky_make_ticket_charge_after_actions'])
                || !is_array($_SESSION['musky_make_ticket_charge_after_actions'])) {
                $_SESSION['musky_make_ticket_charge_after_actions'] = [];
            }

            // Resolve both the human-friendly ticket number and the IIQ GUID
            // before handing control to the after-actions window. The window
            // should display the readable ticket number, but Nora errands that
            // write back into IIQ must receive the GUID.
            $ticketIdentity = v2_resolve_ticket_identity($ticket_id, $ticket_context, $ticket_row);

            $afterActionToken = bin2hex(random_bytes(16));
            $_SESSION['musky_make_ticket_charge_after_actions'][$afterActionToken] = [
                'created_at'         => date('c'),
                'ticket_id'          => $ticketIdentity['ticket_guid'] !== ''
                    ? $ticketIdentity['ticket_guid']
                    : $ticket_id,
                'ticket_guid'        => $ticketIdentity['ticket_guid'],
                'ticket_number'      => $ticketIdentity['ticket_number'],
                'ticket_lookup_input'=> $ticketIdentity['ticket_lookup_input'],
                'asset_tag'          => $asset_tag,
                'device_id'          => (int)$device_info['id'],
                'device_serial'      => (string)($device_info['serial_number'] ?? ''),
                'device_model'       => v2_device_model_string($device_info),
                'owner_id'           => (int)$owner_info['id'],
                'owner_name'         => (string)($owner_info['full_name'] ?? ''),
                'owner_email'        => (string)($owner_info['email'] ?? ''),
                'owner_district_id'  => (string)($owner_info['district_id'] ?? ''),
                'submitter'          => $submitter,
                'school_year'        => $school_year,
                'possible_total'     => (float)$total_amount,
                'charges'            => $createdCharges,
                'inventory_action'   => [
                    'status' => 'waiting_details',
                    'detail' => 'Reserved for future implementation.'
                ],
            ];

            $afterActionsUrl = 'MuskyMakeTicketCharges.AfterActions.php?token='
                . rawurlencode($afterActionToken);
            $popupTarget = $after_actions_window_name !== ''
                ? $after_actions_window_name
                : 'muskyAfterActions_' . $afterActionToken;

            ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Launching After Actions</title>
<style>
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: radial-gradient(circle at top, #1f6aa5 0%, #0d1f33 55%, #08131f 100%);
    color: #f4f8fb;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.launch-card {
    width: min(640px, 92vw);
    background: rgba(9, 21, 34, 0.86);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 20px;
    padding: 28px 30px;
    box-shadow: 0 24px 80px rgba(0,0,0,0.35);
}
.launch-ring {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    border: 6px solid rgba(255,255,255,0.14);
    border-top-color: #7fd1ff;
    animation: spin 1.1s linear infinite;
    margin-bottom: 18px;
}
.launch-card a {
    color: #7fd1ff;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
</head>
<body>
<div class="launch-card">
  <div class="launch-ring"></div>
  <h2 style="margin:0 0 10px 0;">Charges Created</h2>
  <p style="margin:0 0 12px 0;">
    PANDA charge rows were created successfully. Musky is opening the after-actions window now.
  </p>
  <p style="margin:0 0 18px 0; opacity:.88;">
    That window will handle Slack, IncidentIQ updates, user sync, inventory lookup,
    and the future Inventory Action workflow from one place.
  </p>
  <p style="margin:0;">
    If the window does not open automatically,
    <a id="openAfterActionsLink" href="<?= htmlspecialchars($afterActionsUrl) ?>" target="<?= htmlspecialchars($popupTarget) ?>">click here</a>.
  </p>
</div>

<script>
const afterActionsUrl = <?= json_encode($afterActionsUrl) ?>;
const popupTarget = <?= json_encode($popupTarget) ?>;

try {
  const popup = window.open(afterActionsUrl, popupTarget);
  if (popup) {
    popup.focus();
  }
} catch (e) {
  // Ignore popup failures here; the manual link stays on screen as fallback.
}

setTimeout(function() {
  window.location.href = '../index.php';
}, 1800);
</script>
</body>
</html>
<?php
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[MuskyMakeTicketCharges] commit failed: ' . $e->getMessage());
            $errors[] = "Commit failed. Check the server logs for details.";
        }
    }

    $step = 'review';
}

// -----------------------------------------------------------------------------
// Load prior charge history
// -----------------------------------------------------------------------------
if ($device_info && $owner_info) {
    $charge_history = panda_load_recent_charges_for_device_owner(
        $pdo,
        (int)$device_info['id'],
        (int)$owner_info['id']
    );
}

if ($step === 'review' && $device_info && !$recent_duplicate_matches) {
    $selectedPartIds = [];
    foreach ($posted_parts as $pid => $row) {
        if (isset($row['use']) && $row['use'] === '1') {
            $selectedPartIds[(int)$pid] = (int)$pid;
        }
    }

    if ($selectedPartIds) {
        $recent_duplicate_matches = panda_load_recent_matching_device_charges(
            $pdo,
            (int)$device_info['id'],
            array_values($selectedPartIds),
            30
        );
    }
}

$deviceModel = v2_device_model_string($device_info);
$hidePartsSelection = ($step === 'review' && $staged && !$errors);
$lookupGateState = ['items' => [], 'all_complete' => true, 'has_failure' => false, 'blockers' => []];
$lookupSubmitLocked = false;
$refurbNoteTemplate = v2_refurb_note_text($ticket_id);

if ($step === 'review' && !empty($lookup_errand_ids)) {
    $lookupGateState = v2_get_lookup_gate_state($lookup_errand_ids);
    $lookupSubmitLocked = !$lookupGateState['all_complete'];
}

if ($device_info && $asset_tag !== '') {
    if (!$owner_history) {
        $owner_history = v2_load_owner_history($pdo, $asset_tag);
    }
    $owner_choices = v2_build_charge_owner_choices($pdo, $device_info, $owner_history);
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>PANDA — Add Charges</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

<style>
.table-wrapper { max-height: 55vh; overflow-y: auto; }
#partsTable th { position: sticky; top: 0; background: var(--table-header-bg,#f8f9fa); z-index: 3; }
.total-bar { font-weight: bold; font-size: 1.1em; padding-top: 0.5rem; }
.note-input { width: 100%; }

.errand-dot {
    width:12px;
    height:12px;
    border-radius:50%;
    display:inline-block;
    margin-right:8px;
}

.errand-submitted { background:#ffc107; }
.errand-processing { background:#0dcaf0; }
.errand-complete { background:#198754; }
.errand-failed { background:#dc3545; }

#confirmSubmitBtn:disabled {
    opacity:.6;
    cursor:not-allowed;
}

.part-tabs {
    border-bottom: 1px solid rgba(0,0,0,.12);
    gap: .5rem;
}

.part-tab-btn {
    border: 0;
    border-bottom: 3px solid transparent;
    background: transparent;
    padding: .55rem .9rem;
    font-weight: 700;
    color: inherit;
    opacity: .75;
}

.part-tab-btn.active {
    opacity: 1;
    border-bottom-color: var(--bs-primary, #0d6efd);
}

.parts-empty-state {
    display: none;
    border: 1px dashed rgba(0,0,0,.15);
    border-radius: .75rem;
    padding: 1rem;
    background: rgba(0,0,0,.02);
}
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
<div class="container-fluid mt-4">

<h3>GSD IT Charges Form -- V2</h3>

<?php if ($errors): ?>
<div class="alert alert-danger">
<?php foreach ($errors as $e): ?>
<div><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<!-- ========================================================= -->
<!-- STEP 1 UI — LOOKUP -->
<!-- ========================================================= -->
<?php if ($step === 'lookup'): ?>

<div class="card mb-4">
  <div class="card-header">1️⃣ Identify Device & Ticket</div>
  <div class="card-body">

    <form method="post">
      <input type="hidden" name="step" value="lookup">

      <div class="row g-3">

        <div class="col-md-3">
          <label class="form-label">Asset Tag</label>
          <input type="text" name="asset_tag" class="form-control"
                 value="<?= htmlspecialchars($asset_tag) ?>" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Ticket ID</label>
          <input type="text" name="ticket_id" class="form-control"
                 value="<?= htmlspecialchars($ticket_id) ?>" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Submitter</label>
          <input type="text" name="submitter" class="form-control"
                 value="<?= htmlspecialchars($submitter) ?>" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">School Year</label>
          <input type="text" name="school_year" class="form-control"
                 value="<?= htmlspecialchars($school_year) ?>">
        </div>

        <?php if ($device_info): ?>
        <div class="col-12">
          <div class="border rounded p-3 bg-body-tertiary">
            <div class="row g-3 align-items-end">
              <div class="col-md-5">
                <div class="small text-muted mb-1">Device Found</div>
                <div class="fw-semibold">
                  <?= htmlspecialchars((string)($device_info['asset_tag'] ?? '')) ?>
                  <?php if (!empty($device_info['serial_number'])): ?>
                    / <?= htmlspecialchars((string)$device_info['serial_number']) ?>
                  <?php endif; ?>
                </div>
                <div class="small text-muted">
                  <?= htmlspecialchars($deviceModel !== '' ? $deviceModel : 'Unknown model') ?>
                </div>
              </div>

              <div class="col-md-7">
                <label class="form-label">Charge Owner</label>
                <?php if ($owner_choices): ?>
                <select name="selected_owner_id" class="form-select" required>
                  <?php foreach ($owner_choices as $choice): ?>
                  <option value="<?= (int)$choice['id'] ?>"
                          <?= (int)$selected_owner_id === (int)$choice['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(v2_format_charge_owner_choice($choice)) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">
                  Use the current owner or choose a former owner from device history when the device was already wiped, repaired, or reassigned.
                </div>
                <?php else: ?>
                <input type="hidden" name="selected_owner_id" value="<?= (int)$selected_owner_id ?>">
                <div class="alert alert-warning mb-0 py-2">
                  No chargeable owners were found in device history for this asset tag.
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <div class="mt-3">
        <button class="btn btn-primary"><?= $device_info ? 'Lookup With Selected Owner' : 'Lookup' ?></button>
      </div>
    </form>

  </div>
</div>

<?php endif; ?>

<!-- ========================================================= -->
<!-- STEP 2 UI — REVIEW -->
<!-- ========================================================= -->
<?php if ($step === 'review' && $device_info && $owner_info): ?>

<?php
  // For UI: make sure we have a consistent ticket row if possible
  // (after refresh=1, this will be the newly-synced data)
?>

<?php if (!empty($lookup_errand_ids)): ?>
<div class="card mb-4">
  <div class="card-header">0️⃣ Lookup Tasks (must finish before Submit)</div>
  <div class="card-body">
    <div id="errandGateSummary"
         class="alert <?= $lookupSubmitLocked ? ($lookupGateState['has_failure'] ? 'alert-danger' : 'alert-warning') : 'alert-success' ?> py-2">
      <?php if ($lookupSubmitLocked): ?>
        <div class="fw-semibold">Confirm & Submit is still locked.</div>
        <div class="small">These tasks must finish first:</div>
        <ul class="mb-0 mt-1 small">
          <?php foreach ($lookupGateState['blockers'] as $blocker): ?>
            <li><?= htmlspecialchars($blocker) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="fw-semibold">All lookup tasks are complete.</div>
        <div class="small">Confirm & Submit is ready.</div>
      <?php endif; ?>
    </div>
    <div id="errandStatusPanel" class="small"></div>
    <div class="text-muted small mt-2">
      Submit will unlock automatically when all tasks are <strong>complete</strong>.
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Device / Owner / Ticket Summary Block -->
<div class="card mb-4">
  <div class="card-header">2️⃣ Device / Owner / Ticket Summary</div>
  <div class="card-body">

    <?php if ((int)$selected_owner_id > 0 && (int)$selected_owner_id !== (int)($device_info['owner_id'] ?? 0)): ?>
    <div class="alert alert-warning py-2">
      Charges on this page will be attached to the selected former owner from device history, not the device's current assignment.
    </div>
    <?php endif; ?>

    <div class="row">
      <div class="col-md-4">
        <h6>Device</h6>
        <ul class="mb-0">
          <li><strong>ID:</strong> <?= (int)$device_info['id'] ?></li>
          <li><strong>Serial:</strong> <?= htmlspecialchars($device_info['serial_number'] ?? '') ?></li>
          <li><strong>Asset Tag:</strong> <?= htmlspecialchars($device_info['asset_tag'] ?? '') ?></li>
          <li><strong>Model:</strong> <?= htmlspecialchars($deviceModel) ?></li>
        </ul>
      </div>

      <div class="col-md-4">
        <h6>Owner</h6>
        <ul class="mb-0">
          <li><strong>ID:</strong> <?= (int)$owner_info['id'] ?></li>
          <li><strong>Name:</strong> <?= htmlspecialchars($owner_info['full_name'] ?? '') ?></li>
          <li><strong>Email:</strong> <?= htmlspecialchars($owner_info['email'] ?? '') ?></li>
          <li><strong>Grade:</strong> <?= htmlspecialchars($owner_info['grade'] ?? '') ?></li>
        </ul>
      </div>

      <div class="col-md-4">
        <h6>Ticket</h6>
        <?php if ($ticket_row): ?>
          <ul class="mb-0">
            <li><strong>TicketId:</strong> <?= htmlspecialchars($ticket_row['TicketId'] ?? '') ?></li>
            <li><strong>TicketNumber:</strong> <?= htmlspecialchars($ticket_row['TicketNumber'] ?? '') ?></li>
            <li><strong>OwnerEmail:</strong> <?= htmlspecialchars($ticket_row['OwnerEmail'] ?? '') ?></li>
            <li><strong>ForEmail:</strong> <?= htmlspecialchars($ticket_row['ForEmail'] ?? '') ?></li>
          </ul>
        <?php elseif ($ticket_info): ?>
          <ul class="mb-0">
            <li><strong>Ticket:</strong> <?= htmlspecialchars($ticket_info['ticket_id'] ?? '') ?></li>
            <li><strong>TicketNumber:</strong> <?= htmlspecialchars((string)($ticket_info['ticket_number'] ?? '')) ?></li>
            <li><strong>OwnerEmail:</strong> <?= htmlspecialchars((string)($ticket_info['owner_email'] ?? '')) ?></li>
            <li><strong>ForEmail:</strong> <?= htmlspecialchars((string)($ticket_info['for_email'] ?? '')) ?></li>
          </ul>
        <?php else: ?>
          <div class="text-muted">Ticket data not found.</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php if ($hidePartsSelection): ?>
<div class="card mb-4">
  <div class="card-header">3️⃣ Parts Selection Locked For Review</div>
  <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="fw-semibold">Your selected parts are staged below for final review.</div>
      <div class="text-muted small">Use Edit Parts if you want to change the list before submitting charges.</div>
    </div>
    <form method="post" class="m-0">
      <input type="hidden" name="step" value="review">
      <input type="hidden" name="edit_parts" value="1">
      <input type="hidden" name="asset_tag" value="<?= htmlspecialchars($asset_tag) ?>">
      <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket_id) ?>">
      <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
      <input type="hidden" name="submitter" value="<?= htmlspecialchars($submitter) ?>">
      <input type="hidden" name="selected_owner_id" value="<?= (int)$selected_owner_id ?>">
      <?php foreach ($posted_parts as $pid => $row): ?>
        <?php if (!isset($row['use']) || $row['use'] !== '1') continue; ?>
        <input type="hidden" name="parts[<?= (int)$pid ?>][use]" value="1">
        <input type="hidden" name="parts[<?= (int)$pid ?>][reason]" value="<?= htmlspecialchars($row['reason'] ?? '') ?>">
        <input type="hidden" name="parts[<?= (int)$pid ?>][note]" value="<?= htmlspecialchars($row['note'] ?? '') ?>">
      <?php endforeach; ?>
      <input type="hidden" name="refurb_used" value="<?= htmlspecialchars($refurb_used) ?>">
      <?php foreach ($posted_refurb_parts as $pid => $row): ?>
        <?php if (!isset($row['use']) || $row['use'] !== '1') continue; ?>
        <input type="hidden" name="refurb_parts[<?= (int)$pid ?>][use]" value="1">
      <?php endforeach; ?>
      <button class="btn btn-outline-primary">Edit Parts</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Parts Selection Table -->
<?php if (!$hidePartsSelection): ?>
<div class="card mb-4">
  <div class="card-header">3️⃣ Select Parts</div>
  <div class="card-body">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <div class="d-flex flex-wrap align-items-center part-tabs" role="tablist" aria-label="Part groups">
        <button type="button" class="part-tab-btn active" data-part-group="standard">
          Student / Standard Repair
        </button>
        <button type="button" class="part-tab-btn" data-part-group="agi">
          AGI Repair
        </button>
        <button type="button" class="part-tab-btn" data-part-group="gator">
          Gator Repair
        </button>
      </div>

      <?php if (!empty($posted_parts)): ?>
      <div class="small text-muted">
        Previous part selections are still loaded below.
      </div>
      <?php endif; ?>
    </div>

    <div class="mb-2 d-flex align-items-center">
      <div class="btn-group btn-group-sm" role="group" aria-label="Part filter">
        <button type="button" class="btn btn-primary" id="filterRecommendedBtn">
          Recommended for this model
        </button>
        <button type="button" class="btn btn-outline-secondary" id="filterAllBtn">
          Show all parts
        </button>
      </div>
      <small class="text-muted ms-2">
        Recommended is based on panda_parts.AppliesToModels containing this device model (<?= htmlspecialchars($deviceModel ?: 'unknown') ?>).
      </small>
    </div>

    <form method="post">
      <input type="hidden" name="step" value="review">
      <input type="hidden" name="asset_tag" value="<?= htmlspecialchars($asset_tag) ?>">
      <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket_id) ?>">
      <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
      <input type="hidden" name="submitter" value="<?= htmlspecialchars($submitter) ?>">
      <input type="hidden" name="selected_owner_id" value="<?= (int)$selected_owner_id ?>">
      <input type="hidden" name="refurb_used" value="<?= htmlspecialchars($refurb_used) ?>">
      <?php foreach ($posted_refurb_parts as $pid => $row): ?>
        <?php if (!isset($row['use']) || $row['use'] !== '1') continue; ?>
        <input type="hidden" name="refurb_parts[<?= (int)$pid ?>][use]" value="1">
      <?php endforeach; ?>

      <div class="table-wrapper mb-2">
        <table class="table table-bordered table-sm table-hover align-middle" id="partsTable">
          <thead>
            <tr>
              <th style="width:70px;">Use</th>
              <th>Part Code</th>
              <th>Name</th>
              <th style="width:130px;">Cost (FULL)</th>
              <th style="width:180px;">Why Replaced?</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($parts as $p):
              $pid  = (int)$p['PartID'];
              $cost = (float)($price_by_id[$pid]['CostFull'] ?? 0);
              $partCode = (string)($p['PartCode'] ?? '');
              $partGroup = v2_part_group_from_code($partCode);

              $recommended = true;
              $modelsJson  = (string)($p['AppliesToModels'] ?? '[]');
              $list        = json_decode($modelsJson, true);
              if (!is_array($list)) $list = [];

              if ($deviceModel !== '' && $list) {
                  $recommended = in_array($deviceModel, $list, true);
              }

              $recFlag = $recommended ? '1' : '0';
              $postedPart = $posted_parts[$pid] ?? [];
              $isChecked = isset($postedPart['use']) && $postedPart['use'] === '1';
              $selectedReason = (string)($postedPart['reason'] ?? '');
              $postedNote = (string)($postedPart['note'] ?? '');
            ?>
              <tr class="<?= $recommended ? 'recommended-row' : 'other-row' ?>"
                  data-recommended="<?= $recFlag ?>"
                  data-part-group="<?= htmlspecialchars($partGroup) ?>">
                <td>
                  <input type="checkbox"
                         class="form-check-input part-check"
                         name="parts[<?= $pid ?>][use]"
                         value="1"
                         data-price="<?= htmlspecialchars((string)$cost) ?>"
                         <?= $isChecked ? 'checked' : '' ?>>
                </td>

                <td><?= htmlspecialchars($partCode) ?></td>
                <td><?= htmlspecialchars($p['PartName'] ?? '') ?></td>
                <td>$<?= number_format($cost, 2) ?></td>

                <td>
                  <select name="parts[<?= $pid ?>][reason]"
                          class="form-select form-select-sm replacement-reason"
                          data-pid="<?= $pid ?>">
                    <option value="" <?= $selectedReason === '' ? 'selected' : '' ?>>Select</option>
                    <option value="Vandalism" <?= $selectedReason === 'Vandalism' ? 'selected' : '' ?>>Vandalism</option>
                    <option value="Accidental" <?= $selectedReason === 'Accidental' ? 'selected' : '' ?>>Accidental</option>
                    <option value="OEM Failure" <?= $selectedReason === 'OEM Failure' ? 'selected' : '' ?>>OEM Failure</option>
                    <option value="Other" <?= $selectedReason === 'Other' ? 'selected' : '' ?>>Other</option>
                    <option value="REFURB" <?= $selectedReason === 'REFURB' ? 'selected' : '' ?>>Refurbishment</option>
                  </select>
                </td>

                <td>
                  <input type="text"
                         id="note_<?= $pid ?>"
                         name="parts[<?= $pid ?>][note]"
                         class="form-control form-control-sm note-input"
                         placeholder="Optional note for this part"
                         value="<?= htmlspecialchars($postedNote) ?>">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="parts-empty-state mb-2" id="partsEmptyState">
        No parts are currently assigned to this tab yet.
      </div>

      <div class="d-flex justify-content-between align-items-center total-bar">
        <div>
          TOTAL (FULL cost, 1x each selected part):
          <span id="totalDisplay">$0.00</span>
        </div>
        <button class="btn btn-success">Review Charges</button>
      </div>

    </form>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($step === 'review' && $staged && !$errors): ?>

<!-- Review Charges -->
<div class="card mb-4">
  <div class="card-header">4️⃣ Review Charges</div>
  <div class="card-body">

    <div class="table-responsive">
      <table class="table table-bordered table-sm">
        <thead>
          <tr>
            <th>Part ID</th>
            <th>Unit Cost</th>
            <th>Qty</th>
            <th>Total</th>
            <th>Reason</th>
            <th>Vandalism?</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staged as $s): ?>
            <tr>
              <td><?= (int)$s['PartID'] ?></td>
              <td>$<?= number_format((float)$s['UnitCost'], 2) ?></td>
              <td><?= (int)$s['Quantity'] ?></td>
              <td>$<?= number_format((float)$s['TotalCost'], 2) ?></td>
              <td><?= htmlspecialchars($s['PartReplacedWhy']) ?></td>
              <td><?= htmlspecialchars($s['IsVandalism']) ?></td>
              <td><?= htmlspecialchars($s['Note']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
      <div>
        <strong>Total:</strong>
        $<?= number_format((float)$total_amount, 2) ?>
      </div>

      <div class="d-flex gap-2">
        <form method="post" class="m-0">
          <input type="hidden" name="step" value="review">
          <input type="hidden" name="edit_parts" value="1">
          <input type="hidden" name="asset_tag" value="<?= htmlspecialchars($asset_tag) ?>">
          <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket_id) ?>">
          <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
          <input type="hidden" name="submitter" value="<?= htmlspecialchars($submitter) ?>">
          <input type="hidden" name="selected_owner_id" value="<?= (int)$selected_owner_id ?>">
          <?php foreach ($posted_parts as $pid => $row): ?>
            <?php if (!isset($row['use']) || $row['use'] !== '1') continue; ?>
            <input type="hidden" name="parts[<?= (int)$pid ?>][use]" value="1">
            <input type="hidden" name="parts[<?= (int)$pid ?>][reason]" value="<?= htmlspecialchars($row['reason'] ?? '') ?>">
            <input type="hidden" name="parts[<?= (int)$pid ?>][note]" value="<?= htmlspecialchars($row['note'] ?? '') ?>">
          <?php endforeach; ?>
          <input type="hidden" name="refurb_used" value="<?= htmlspecialchars($refurb_used) ?>">
          <?php foreach ($posted_refurb_parts as $pid => $row): ?>
            <?php if (!isset($row['use']) || $row['use'] !== '1') continue; ?>
            <input type="hidden" name="refurb_parts[<?= (int)$pid ?>][use]" value="1">
          <?php endforeach; ?>
          <button class="btn btn-outline-primary">Edit Parts</button>
        </form>

      <form method="post" id="commitForm">
        <input type="hidden" name="step" value="commit">
        <input type="hidden" name="asset_tag" value="<?= htmlspecialchars($asset_tag) ?>">
        <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket_id) ?>">
        <input type="hidden" name="school_year" value="<?= htmlspecialchars($school_year) ?>">
        <input type="hidden" name="submitter" value="<?= htmlspecialchars($submitter) ?>">
        <input type="hidden" name="selected_owner_id" value="<?= (int)$selected_owner_id ?>">
        <input type="hidden" name="after_actions_window_name" id="afterActionsWindowName" value="">

        <?php foreach ($posted_parts as $pid => $row): ?>
          <?php if (!isset($row['use']) || $row['use'] !== '1') continue; ?>
          <input type="hidden" name="parts[<?= (int)$pid ?>][use]" value="1">
          <input type="hidden" name="parts[<?= (int)$pid ?>][reason]" value="<?= htmlspecialchars($row['reason'] ?? '') ?>">
          <input type="hidden" name="parts[<?= (int)$pid ?>][note]" value="<?= htmlspecialchars($row['note'] ?? '') ?>">
        <?php endforeach; ?>

        <?php if ($recent_duplicate_matches): ?>
        <div class="alert alert-warning small mb-3">
          <div class="fw-semibold">Possible duplicate charge detected</div>
          <div class="mb-2">A matching part was already installed on this device within the last 30 days.</div>
          <ul class="mb-2">
            <?php foreach ($recent_duplicate_matches as $match): ?>
              <li>
                <?= htmlspecialchars((string)($match['PartCode'] ?? '')) ?>
                on <?= htmlspecialchars(date('Y-m-d', strtotime((string)($match['CreatedAt'] ?? 'now')))) ?>
                via ticket <?= htmlspecialchars((string)($match['TicketID'] ?? '')) ?>
                [<?= htmlspecialchars((string)($match['Status'] ?? '')) ?>]
              </li>
            <?php endforeach; ?>
          </ul>
          <div class="form-check">
            <input class="form-check-input"
                   type="checkbox"
                   name="confirm_recent_duplicate"
                   id="confirm_recent_duplicate"
                   value="1"
                   <?= $confirm_recent_duplicate ? 'checked' : '' ?>>
            <label class="form-check-label" for="confirm_recent_duplicate">
              I confirmed this is not an accidental double charge.
            </label>
          </div>
        </div>
        <?php endif; ?>

        <div class="border rounded p-3 mb-3" style="min-width: 420px; max-width: 720px;">
          <div class="fw-semibold mb-2">One more question before submit</div>
          <div class="mb-2 d-flex align-items-center gap-2">
            <span>Was any part used for refurbishment?</span>
            <span class="badge rounded-pill text-bg-info"
                  title="Refurbishment means no-charge parts used to get the device ready for handout again, like tempered glass or a case after AGi repair.">
              ?
            </span>
          </div>

          <div class="d-flex flex-wrap gap-3 mb-3">
            <div class="form-check">
              <input class="form-check-input refurb-used-toggle"
                     type="radio"
                     name="refurb_used"
                     id="refurb_used_no"
                     value="NO"
                     required
                     <?= $refurb_used === 'NO' ? 'checked' : '' ?>>
              <label class="form-check-label" for="refurb_used_no">No</label>
            </div>

            <div class="form-check">
              <input class="form-check-input refurb-used-toggle"
                     type="radio"
                     name="refurb_used"
                     id="refurb_used_yes"
                     value="YES"
                     required
                     <?= $refurb_used === 'YES' ? 'checked' : '' ?>>
              <label class="form-check-label" for="refurb_used_yes">Yes</label>
            </div>
          </div>

          <div id="refurbPartsWrap" class="<?= $refurb_used === 'YES' ? '' : 'd-none' ?>">
            <div class="alert alert-info py-2 small">
              Refurbishment parts are recorded at <strong>$0.00</strong> and noted as:
              <code><?= htmlspecialchars($refurbNoteTemplate) ?></code>
            </div>

            <div class="small fw-semibold mb-2">Select any student / standard repair parts used to put this device back in service.</div>

            <div class="table-responsive">
              <table class="table table-bordered table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width:70px;">Use</th>
                    <th>Part Code</th>
                    <th>Name</th>
                    <th style="width:130px;">Normal Cost</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($parts as $p): ?>
                    <?php
                      $pid = (int)($p['PartID'] ?? 0);
                      $partCode = (string)($p['PartCode'] ?? '');
                      if (v2_part_group_from_code($partCode) !== 'standard') {
                          continue;
                      }
                      $refurbChecked = isset($posted_refurb_parts[$pid]['use']) && $posted_refurb_parts[$pid]['use'] === '1';
                      $normalCost = (float)($price_by_id[$pid]['CostFull'] ?? 0);
                    ?>
                    <tr>
                      <td>
                        <input type="checkbox"
                               class="form-check-input refurb-part-check"
                               name="refurb_parts[<?= $pid ?>][use]"
                               value="1"
                               <?= $refurbChecked ? 'checked' : '' ?>>
                      </td>
                      <td><?= htmlspecialchars($partCode) ?></td>
                      <td><?= htmlspecialchars((string)($p['PartName'] ?? '')) ?></td>
                      <td>$<?= number_format($normalCost, 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

          <button id="confirmSubmitBtn"
                  class="btn btn-danger"
                  data-lookup-locked="<?= $lookupSubmitLocked ? '1' : '0' ?>"
                  data-duplicate-needed="<?= $recent_duplicate_matches ? '1' : '0' ?>"
                  <?= $lookupSubmitLocked ? 'disabled' : '' ?>
                  onclick="return confirm('Submit these charges?');">
            Confirm & Submit
          </button>
      </form>
      </div>
    </div>

  </div>
</div>

<?php endif; ?>

<?php if ($device_info && $owner_info && $charge_history): ?>

<!-- Prior Charge History -->
<div class="card mt-5 mb-4">
  <div class="card-header">5️⃣ Prior Charges (Device + Owner)</div>
  <div class="card-body">

    <div class="table-responsive">
      <table class="table table-sm table-striped">
        <thead>
          <tr>
            <th>Date</th>
            <th>Ticket</th>
            <th>Part</th>
            <th>Total</th>
            <th>Reason</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($charge_history as $row): ?>
            <tr>
              <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['CreatedAt'] ?? 'now'))) ?></td>
              <td><?= htmlspecialchars((string)($row['TicketID'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($row['PartCode'] ?? '') . ' — ' . (string)($row['PartDescription'] ?? '')) ?></td>
              <td>$<?= number_format((float)($row['TotalCost'] ?? 0), 2) ?></td>
              <td><?= htmlspecialchars((string)($row['PartReplacedWhy'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($row['Status'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<?php endif; ?>

</div>
<?php if ($step === 'review'): ?>
<script>
// -----------------------------------------------------------------------------
// JS: totals, model filter toggling, reason behavior
// -----------------------------------------------------------------------------
(function(){
  const table = document.getElementById('partsTable');
  if (!table) return;

  const rows  = table.querySelectorAll('tbody tr');
  const checks = table.querySelectorAll('.part-check');
  const totalDisplay = document.getElementById('totalDisplay');
  const emptyState = document.getElementById('partsEmptyState');

  const filterRecommendedBtn = document.getElementById('filterRecommendedBtn');
  const filterAllBtn = document.getElementById('filterAllBtn');
  const tabButtons = document.querySelectorAll('.part-tab-btn');
  const refurbNoteTemplate = <?= json_encode($refurbNoteTemplate) ?>;

  let showRecommendedOnly = true;
  let activePartGroup = 'standard';

  function recompute() {
    let total = 0;
    checks.forEach(cb => {
      if (cb.checked) {
        const price = parseFloat(cb.dataset.price || '0');
        if (!isNaN(price)) total += price;
      }
    });
    if (totalDisplay) totalDisplay.textContent = '$' + total.toFixed(2);
  }

  function applyFilter() {
    let visibleCount = 0;
    rows.forEach(row => {
      const rec = row.dataset.recommended === '1';
      const partGroup = row.dataset.partGroup || 'standard';
      const groupMismatch = partGroup !== activePartGroup;
      const recommendationMismatch = showRecommendedOnly && !rec;

      if (groupMismatch || recommendationMismatch) {
        row.style.display = 'none';
      } else {
        row.style.display = '';
        visibleCount += 1;
      }
    });

    if (emptyState) {
      emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
    }
  }

  checks.forEach(cb => cb.addEventListener('change', recompute));
  recompute();

  if (filterRecommendedBtn && filterAllBtn) {

    showRecommendedOnly = true;
    applyFilter();

    filterRecommendedBtn.classList.add('btn-primary');
    filterRecommendedBtn.classList.remove('btn-outline-secondary');
    filterAllBtn.classList.remove('btn-primary');
    filterAllBtn.classList.add('btn-outline-secondary');

    filterRecommendedBtn.addEventListener('click', function() {
      showRecommendedOnly = true;
      applyFilter();
      filterRecommendedBtn.classList.add('btn-primary');
      filterRecommendedBtn.classList.remove('btn-outline-secondary');
      filterAllBtn.classList.remove('btn-primary');
      filterAllBtn.classList.add('btn-outline-secondary');
    });

    filterAllBtn.addEventListener('click', function() {
      showRecommendedOnly = false;
      applyFilter();
      filterAllBtn.classList.add('btn-primary');
      filterAllBtn.classList.remove('btn-outline-secondary');
      filterRecommendedBtn.classList.remove('btn-primary');
      filterRecommendedBtn.classList.add('btn-outline-secondary');
    });
  }

  if (tabButtons.length) {
    tabButtons.forEach(btn => {
      btn.addEventListener('click', function() {
        activePartGroup = this.dataset.partGroup || 'standard';
        tabButtons.forEach(other => other.classList.remove('active'));
        this.classList.add('active');
        applyFilter();
      });
    });
  }

  const reasonSelects = table.querySelectorAll('.replacement-reason');
  reasonSelects.forEach(select => {
    const syncReasonState = function() {
      const pid = this.dataset.pid;
      const row = this.closest('tr');
      const useBox = row ? row.querySelector('.part-check') : null;
      const noteBox = document.getElementById('note_' + pid);

      if (this.value && useBox && !useBox.checked) {
        useBox.checked = true;
        recompute();
      }

      if (!noteBox) return;

      if (this.value === 'REFURB') {
        noteBox.value = refurbNoteTemplate;
        noteBox.readOnly = true;
      } else {
        noteBox.readOnly = false;
        noteBox.placeholder = this.value === 'Other'
            ? "Describe 'Other' reason"
            : "Optional note for this part";
      }
    };

    select.addEventListener('change', syncReasonState);
    syncReasonState.call(select);
  });

})();
</script>
<?php endif; ?>

<?php if ($step === 'review' && $staged && !$errors): ?>
<script>
(function() {
  const confirmBtn = document.getElementById('confirmSubmitBtn');
  const duplicateConfirm = document.getElementById('confirm_recent_duplicate');
  const commitForm = document.getElementById('commitForm');
  const popupNameField = document.getElementById('afterActionsWindowName');

  window.muskyUpdateConfirmState = function() {
    if (!confirmBtn) return;

    const lookupLocked = confirmBtn.dataset.lookupLocked === '1';
    const duplicateNeeded = confirmBtn.dataset.duplicateNeeded === '1';
    const duplicateOk = !duplicateNeeded || (duplicateConfirm && duplicateConfirm.checked);

    confirmBtn.disabled = lookupLocked || !duplicateOk;
  };

  if (duplicateConfirm) {
    duplicateConfirm.addEventListener('change', window.muskyUpdateConfirmState);
  }

  if (commitForm && popupNameField) {
    commitForm.addEventListener('submit', function() {
      if (popupNameField.value) return;

      const popupName = 'muskyAfterActions_' + Date.now();

      try {
        const popup = window.open('', popupName, 'width=1180,height=860,resizable=yes,scrollbars=yes');
        if (popup) {
          popup.document.write(
            '<!doctype html><html><head><title>Preparing After Actions</title></head>' +
            '<body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0f2236;color:#f4f8fb;font-family:Arial,sans-serif;">' +
            '<div style="text-align:center;padding:24px;">Opening PANDA after actions...</div>' +
            '</body></html>'
          );
          popup.document.close();
          popupNameField.value = popupName;
        }
      } catch (e) {
        // A blocked popup is fine here. The server response provides a manual link.
      }
    });
  }

  window.muskyUpdateConfirmState();
})();
</script>
<?php endif; ?>

<?php if ($step === 'review' && $staged && !$errors): ?>
<script>
(function() {
  const toggles = document.querySelectorAll('.refurb-used-toggle');
  const wrap = document.getElementById('refurbPartsWrap');

  if (!toggles.length || !wrap) return;

  function syncRefurbSection() {
    let selected = '';
    toggles.forEach(toggle => {
      if (toggle.checked) {
        selected = toggle.value;
      }
    });

    if (selected === 'YES') {
      wrap.classList.remove('d-none');
    } else {
      wrap.classList.add('d-none');
    }
  }

  toggles.forEach(toggle => {
    toggle.addEventListener('change', syncRefurbSection);
  });

  syncRefurbSection();
})();
</script>
<?php endif; ?>


<?php if ($step === 'review' && !empty($lookup_errand_ids)): ?>
<script>

// -----------------------------------------------------------------------------
// ERRAND POLLING + AUTO REFRESH + SUBMIT UNLOCK
// -----------------------------------------------------------------------------

const errandIds = <?= json_encode($lookup_errand_ids) ?>;
const statusPanel = document.getElementById('errandStatusPanel');
const confirmBtn = document.getElementById('confirmSubmitBtn');
const gateSummary = document.getElementById('errandGateSummary');

let errandState = <?= json_encode(array_column($lookupGateState['items'] ?? [], 'status', 'id')) ?>;
let allComplete = <?= $lookupGateState['all_complete'] ? 'true' : 'false' ?>;

function renderStatus() {

    if (!statusPanel) return;

    statusPanel.innerHTML = '';

    let completeCount = 0;
    const blockers = [];

    errandIds.forEach(id => {

        const state = (errandState[id] || 'submitted').toLowerCase();

        const row = document.createElement('div');

        const dot = document.createElement('span');
        dot.classList.add('errand-dot');

        if (state === 'submitted' || state === 'queued') {
            dot.classList.add('errand-submitted');
        }
        else if (state === 'processing') {
            dot.classList.add('errand-processing');
        }
        else if (state === 'complete') {
            dot.classList.add('errand-complete');
            completeCount++;
        }
        else {
            dot.classList.add('errand-failed');
        }

        if (state !== 'complete') {
            blockers.push('Errand #' + id + ' is still ' + state.toUpperCase() + '.');
        }

        row.appendChild(dot);
        row.appendChild(
            document.createTextNode("Errand #" + id + " — " + state)
        );

        statusPanel.appendChild(row);
    });

    if (gateSummary) {
        if (completeCount === errandIds.length) {
            gateSummary.className = 'alert alert-success py-2';
            gateSummary.innerHTML =
                '<div class="fw-semibold">All lookup tasks are complete.</div>' +
                '<div class="small">Confirm & Submit is ready.</div>';
        } else {
            const tone = blockers.some(msg => msg.includes('FAILED') || msg.includes('UNKNOWN'))
                ? 'alert alert-danger py-2'
                : 'alert alert-warning py-2';
            gateSummary.className = tone;
            gateSummary.innerHTML =
                '<div class="fw-semibold">Confirm & Submit is still locked.</div>' +
                '<div class="small">These tasks must finish first:</div>' +
                '<ul class="mb-0 mt-1 small">' +
                blockers.map(msg => '<li>' + msg + '</li>').join('') +
                '</ul>';
        }
    }

    if (completeCount === errandIds.length) {

        allComplete = true;

        if (confirmBtn) {
            confirmBtn.dataset.lookupLocked = '0';
            if (typeof window.muskyUpdateConfirmState === 'function') {
                window.muskyUpdateConfirmState();
            } else {
                confirmBtn.disabled = false;
            }
        }

        // Soft refresh once to pull fresh IIQ + device data
        if (!window.location.search.includes('refresh=1')) {
            const params = new URLSearchParams(window.location.search);
            params.set('refresh','1');
            params.set('asset_tag','<?= htmlspecialchars($asset_tag) ?>');
            params.set('ticket_id','<?= htmlspecialchars($ticket_id) ?>');
            params.set('selected_owner_id','<?= (int)$selected_owner_id ?>');
            params.set('step','review');
            window.location.search = params.toString();
        }
    }
}

async function pollErrands() {

    const params = new URLSearchParams();
    errandIds.forEach(id => params.append('ids[]', String(id)));

    try {
        const resp = await fetch(
            '<?= basename(__FILE__) ?>?api=errand_statuses&' + params.toString(),
            { credentials: 'same-origin' }
        );

        const data = await resp.json();

        if (data && Array.isArray(data.items)) {
            data.items.forEach(item => {
                if (item && item.id) {
                    errandState[item.id] = (item.status || 'unknown').toLowerCase();
                }
            });
            allComplete = !!data.all_complete;
        } else {
            errandIds.forEach(id => {
                if (!errandState[id]) {
                    errandState[id] = 'unknown';
                }
            });
        }

    } catch(e) {
        errandIds.forEach(id => {
            errandState[id] = 'failed';
        });
    }

    renderStatus();

    if (confirmBtn) {
        confirmBtn.dataset.lookupLocked = allComplete ? '0' : '1';
        if (typeof window.muskyUpdateConfirmState === 'function') {
            window.muskyUpdateConfirmState();
        }
    }

    if (!allComplete) {
        setTimeout(pollErrands, 2000);
    }
}

renderStatus();
pollErrands();

</script>
<?php endif; ?>

</body>
</html>
