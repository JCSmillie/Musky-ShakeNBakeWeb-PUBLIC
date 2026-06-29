<?php
// ============================================================================
// HelpFilesV2/ChargeServices.php
// Centralized business logic for PANDA_AddChargesFromTicket
// Includes:
//   • Device lookup
//   • Owner history
//   • Parts + pricing
//   • Charge history
//   • Combo rules
//   • IIQ Ticket enforcement (IIQTICKETSYNC)
// ============================================================================

// -----------------------------------------------------------------------------
// SECURITY BASELINE FOR LIBRARY-STYLE FILES:
//   This file is a function library and should only be loaded by other pages.
//   If it is accessed directly via URL, enforce shared auth bootstrap first,
//   then deny direct execution.
// -----------------------------------------------------------------------------
$pandaChargeServicesDirectAccess = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__;

if ($pandaChargeServicesDirectAccess) {
    require_once __DIR__ . '/../check_access.php';
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "ChargeServices is a helper library and is not a standalone endpoint.";
    exit;
}

if (!function_exists('panda_norm_email')) {
function panda_norm_email(?string $s): string {
    return strtolower(trim((string)$s));
}
}

if (!function_exists('panda_try_fetch_one')) {
function panda_try_fetch_one(PDO $pdo, string $sql, array $params): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
}

if (!function_exists('panda_try_fetch_all')) {
function panda_try_fetch_all(PDO $pdo, string $sql, array $params): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

// ============================================================================
// DEVICE + OWNER
// ============================================================================

function v2_lookup_device(PDO $pdo, string $asset_tag): ?array {
    $st = $pdo->prepare("SELECT * FROM devices FORCE INDEX (idx_devices_asset_tag_id) WHERE asset_tag = :t ORDER BY id DESC LIMIT 1");
    $st->execute([':t' => $asset_tag]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function v2_load_owner_history(PDO $pdo, string $assetTag, int $limit = 75): array {
    $stmt = $pdo->prepare("
        SELECT owner_id, owner_name, owner_email, owner_userid, snapshot_time
        FROM device_history FORCE INDEX (idx_hist_asset_dup_snapshot_owner)
        WHERE duplicate = 0
          AND asset_tag = :tag
          AND owner_id IS NOT NULL
        ORDER BY snapshot_time DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':tag' => $assetTag]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    $out  = [];

    foreach ($rows as $r) {
        $oid = (int)$r['owner_id'];
        if ($oid <= 0 || isset($seen[$oid])) continue;
        $seen[$oid] = true;
        $out[] = $r;
    }

    return $out;
}

function v2_lookup_owner(PDO $pdo, int $owner_id): ?array {
    $st = $pdo->prepare("SELECT * FROM owners WHERE id = ?");
    $st->execute([$owner_id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function v2_lookup_owner_from_selection(PDO $pdo, int $selectedOwnerKey): ?array {
    $selectedOwnerKey = (int)$selectedOwnerKey;
    if ($selectedOwnerKey <= 0) {
        return null;
    }

    $owner = v2_lookup_owner($pdo, $selectedOwnerKey);
    if ($owner) {
        return $owner;
    }

    $st = $pdo->prepare("
        SELECT *
        FROM owners
        WHERE district_id = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $st->execute([(string)$selectedOwnerKey]);
    $owner = $st->fetch(PDO::FETCH_ASSOC);

    return $owner ?: null;
}

function v2_load_owner_candidate_emails(PDO $pdo, array $deviceInfo, array $ownerInfo, int $limit = 25): array {
    $emails = [];
    $seen   = [];

    $pushEmail = static function (?string $email) use (&$emails, &$seen): void {
        $norm = panda_norm_email($email);
        if ($norm === '' || isset($seen[$norm])) {
            return;
        }
        $seen[$norm] = true;
        $emails[] = $norm;
    };

    $pushEmail((string)($ownerInfo['email'] ?? ''));

    $ownerId = (int)($ownerInfo['id'] ?? 0);
    if ($ownerId <= 0) {
        return $emails;
    }

    $assetTag = trim((string)($deviceInfo['asset_tag'] ?? ''));
    $serial   = trim((string)($deviceInfo['serial_number'] ?? ''));
    $limit    = max(1, (int)$limit);

    if ($assetTag !== '') {
        $stmt = $pdo->prepare("
            SELECT owner_email
            FROM device_history FORCE INDEX (idx_hist_asset_dup_snapshot_owner)
            WHERE duplicate = 0
              AND asset_tag = :tag
              AND owner_id = :owner_id
              AND owner_email IS NOT NULL
              AND owner_email <> ''
            ORDER BY snapshot_time DESC
            LIMIT {$limit}
        ");
        $stmt->execute([
            ':tag'      => $assetTag,
            ':owner_id' => $ownerId,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pushEmail((string)($row['owner_email'] ?? ''));
        }
    } elseif ($serial !== '') {
        $stmt = $pdo->prepare("
            SELECT owner_email
            FROM device_history
            WHERE serial_number = :serial
              AND owner_id = :owner_id
              AND owner_email IS NOT NULL
              AND owner_email <> ''
            ORDER BY snapshot_time DESC
            LIMIT {$limit}
        ");
        $stmt->execute([
            ':serial'   => $serial,
            ':owner_id' => $ownerId,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pushEmail((string)($row['owner_email'] ?? ''));
        }
    }

    return $emails;
}

// ============================================================================
// PARTS + HISTORY
// ============================================================================

function v2_load_parts_and_pricing(PDO $pdo, string $schoolYear): array {
    $parts = $pdo->query("SELECT * FROM panda_parts WHERE IsActive='YES' ORDER BY PartCode")
                 ->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM panda_part_pricing WHERE SchoolYear = :sy");
    $stmt->execute([':sy' => $schoolYear]);
    $pricing = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $priceById = [];
    foreach ($pricing as $p) {
        $priceById[(int)$p['PartID']] = $p;
    }

    return [$parts, $priceById];
}

function v2_load_part_history(PDO $pdo, int $deviceId): array {
    $stmt = $pdo->prepare("
        SELECT PartID, MAX(CreatedAt) AS LastReplaced
        FROM panda_charges
        WHERE DeviceID = :dev
        GROUP BY PartID
    ");
    $stmt->execute([':dev' => $deviceId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['LastReplaced'])) {
            $out[(int)$row['PartID']] = $row['LastReplaced'];
        }
    }
    return $out;
}

function v2_load_recent_charges(PDO $pdo, int $deviceId, int $ownerId): array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM panda_charges
        WHERE (DeviceID = :dev OR OwnerID = :owner)
          AND Status <> 'rejected'
        ORDER BY CreatedAt DESC
    ");
    $stmt->execute([
        ':dev' => $deviceId,
        ':owner' => $ownerId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================================
// IIQ TICKET ENFORCEMENT
// ============================================================================

function panda_iiq_submit_ticket_sync_errand(string $submitterEmail, string $ticketIdOrNumber): ?int {
    if (!function_exists('musky_nora_api_post_json') || !function_exists('musky_nora_extract_errand_id')) return null;

    $payload = [
        'serial'    => 'N/A',
        'udid'      => 'SYSTEM_TASK',
        'submitter' => $submitterEmail ?: 'PANDA_AddChargesFromTicket',
        'nora'      => 'TRUE',
        'priority'  => 5,
        'extra1'    => 'IIQTICKETSYNC',
        'extra2'    => $ticketIdOrNumber,
    ];

    $resp = musky_nora_api_post_json('/errand/create', $payload);
    return musky_nora_extract_errand_id($resp);
}

function panda_iiq_wait_for_errand(int $errandId, int $maxSeconds = 12): bool {
    if (!function_exists('musky_nora_api_get_json') || !function_exists('musky_nora_extract_status')) return false;

    $deadline = microtime(true) + $maxSeconds;

    while (microtime(true) < $deadline) {
        $resp = musky_nora_api_get_json('/errand/status/' . $errandId);
        if (is_array($resp)) {
            $status = musky_nora_extract_status($resp);
            if ($status === 'complete') return true;
            if (in_array($status, ['failed','rejected','cancelled'])) return false;
        }
        usleep(250000);
    }
    return false;
}

function panda_iiq_lookup_ticket(PDO $pdo, string $ticketIdOrNumber): ?array {
    if (ctype_digit($ticketIdOrNumber)) {
        $row = panda_try_fetch_one(
            $pdo,
            "SELECT * FROM IIQTicketTidBits WHERE TicketNumber = ?",
            [(int)$ticketIdOrNumber]
        );
        if ($row) return $row;
    }

    return panda_try_fetch_one(
        $pdo,
        "SELECT * FROM IIQTicketTidBits WHERE TicketId = ?",
        [$ticketIdOrNumber]
    );
}

function panda_validate_ticket_for_charge(PDO $pdo, string $ticketIdOrNumber, array $deviceInfo, array $ownerInfo, string $submitterEmail): array {

    $ctx = [
        'ok' => false,
        'reason' => '',
        'ticket_number' => null,
        'owner_email' => null,
        'for_email' => null,
        'device_match' => false,
        'email_match' => false,
    ];

    $errandId = panda_iiq_submit_ticket_sync_errand($submitterEmail, $ticketIdOrNumber);
    if ($errandId) {
        panda_iiq_wait_for_errand($errandId);
    }

    $row = panda_iiq_lookup_ticket($pdo, $ticketIdOrNumber);
    if (!$row) {
        $ctx['reason'] = 'ticket_not_found';
        return $ctx;
    }

    $ctx['ticket_number'] = $row['TicketNumber'] ?? null;
    $ctx['owner_email'] = $row['OwnerEmail'] ?? null;
    $ctx['for_email'] = $row['ForEmail'] ?? null;

    $ownerEmails = v2_load_owner_candidate_emails($pdo, $deviceInfo, $ownerInfo);
    $tOwner = panda_norm_email($row['OwnerEmail'] ?? '');
    $tFor   = panda_norm_email($row['ForEmail'] ?? '');

    foreach ($ownerEmails as $ownerEmail) {
        if ($ownerEmail === $tOwner || $ownerEmail === $tFor) {
            $ctx['email_match'] = true;
            break;
        }
    }

    if ($ctx['email_match']) {
        $ctx['email_match'] = true;
    } else {
        $ctx['reason'] = 'ticket_email_mismatch';
        return $ctx;
    }

    $serial = trim((string)($deviceInfo['serial_number'] ?? ''));
    $asset  = trim((string)($deviceInfo['asset_tag'] ?? ''));

    $devices = panda_try_fetch_all(
        $pdo,
        "SELECT SerialNumber, AssetTag FROM IIQTicketTidBitsAssets WHERE TicketId = ?",
        [$row['TicketId']]
    );

    foreach ($devices as $d) {
        if (
            ($serial && strcasecmp($serial, $d['SerialNumber'] ?? '') === 0) ||
            ($asset  && strcasecmp($asset,  $d['AssetTag'] ?? '') === 0)
        ) {
            $ctx['device_match'] = true;
            break;
        }
    }

    if (!$ctx['device_match']) {
        $ctx['reason'] = 'ticket_device_mismatch';
        return $ctx;
    }

    $ctx['ok'] = true;
    $ctx['reason'] = 'ok';
    return $ctx;
}

if (!function_exists('panda_load_recent_charges_for_device_owner')) {

    function panda_load_recent_charges_for_device_owner(PDO $pdo, int $deviceId, int $ownerId): array {

        $stmt = $pdo->prepare("
            SELECT
                ChargeID,
                CreatedAt,
                TicketID,
                PartCode,
                PartDescription,
                TotalCost,
                PartReplacedWhy,
                IsVandalism,
                Status
            FROM panda_charges
            WHERE (DeviceID = :dev OR OwnerID = :owner)
              AND Status <> 'rejected'
            ORDER BY CreatedAt DESC
        ");

        $stmt->execute([
            ':dev'   => $deviceId,
            ':owner' => $ownerId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('panda_load_recent_matching_device_charges')) {

    function panda_load_recent_matching_device_charges(PDO $pdo, int $deviceId, array $partIds, int $days = 30): array {

        $deviceId = (int)$deviceId;
        $days = max(1, (int)$days);

        $cleanPartIds = [];
        foreach ($partIds as $partId) {
            $partId = (int)$partId;
            if ($partId > 0) {
                $cleanPartIds[$partId] = $partId;
            }
        }

        if ($deviceId <= 0 || !$cleanPartIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cleanPartIds), '?'));

        $sql = "
            SELECT
                ChargeID,
                PartID,
                PartCode,
                PartDescription,
                TicketID,
                CreatedAt,
                Status,
                TotalCost,
                PartReplacedWhy
            FROM panda_charges
            WHERE DeviceID = ?
              AND PartID IN ($placeholders)
              AND CreatedAt >= (NOW() - INTERVAL {$days} DAY)
              AND Status IN ('submitted','hold','approved','other_approved','claim_approved','waived')
            ORDER BY CreatedAt DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$deviceId], array_values($cleanPartIds)));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('panda_normalize_reason_and_note')) {

function panda_normalize_reason_and_note(?string $reasonRaw, ?string $noteRaw): array {

    $reasonRaw = trim((string)$reasonRaw);
    $noteRaw   = trim((string)$noteRaw);

    if ($reasonRaw === '') {
        return ['', $noteRaw, 'NO'];
    }

    switch ($reasonRaw) {
        case 'Vandalism':
            $enum  = 'Vandalism';
            $label = 'Vandalism';
            break;

        case 'Accidental':
            $enum  = 'Accidental';
            $label = 'Accidental';
            break;

        case 'OEM Failure':
            $enum  = 'OEM Failure';
            $label = 'OEM Failure';
            break;

        case 'Other':
            $enum  = 'Other';
            $label = 'Other';
            break;

        case 'REFURB':
            $enum  = 'Other';
            $label = 'REFURBISHMENT';
            if ($noteRaw === '') {
                $noteRaw = "Part replaced either during Turn In or post AGi Repair";
            }
            break;

        default:
            $enum  = 'Other';
            $label = $reasonRaw;
            break;
    }

    $finalNote = $noteRaw !== ''
        ? "[Reason: {$label}] {$noteRaw}"
        : "[Reason: {$label}]";

    $isVandal = ($enum === 'Vandalism') ? 'YES' : 'NO';

    return [$enum, $finalNote, $isVandal];
}
}

if (!function_exists('panda_apply_combo_rules')) {

function panda_apply_combo_rules(array $staged, array $parts, array $price_by_id): array {

    $comboComponents = [14, 15];
    $comboPartId     = 29;

    if (!$staged) return $staged;

    $selectedIds = [];
    foreach ($staged as $s) {
        $pid = (int)($s['PartID'] ?? 0);
        if ($pid > 0) $selectedIds[$pid] = true;
    }

    foreach ($comboComponents as $cid) {
        if (empty($selectedIds[$cid])) {
            return $staged;
        }
    }

    $comboPart = null;
    foreach ($parts as $p) {
        if ((int)$p['PartID'] === $comboPartId) {
            $comboPart = $p;
            break;
        }
    }

    if (!$comboPart) return $staged;

    $priceRow = $price_by_id[$comboPartId] ?? null;
    if (!$priceRow || !isset($priceRow['CostFull'])) return $staged;

    $unitCost = (float)$priceRow['CostFull'];
    if ($unitCost <= 0) return $staged;

    $reason    = 'Other';
    $note      = '';
    $isVandal  = 'NO';
    $haveSeed  = false;
    $anyVandal = false;

    foreach ($staged as $s) {
        $pid = (int)($s['PartID'] ?? 0);
        if (!in_array($pid, $comboComponents, true)) continue;

        if (!$haveSeed) {
            $reason   = $s['PartReplacedWhy'] ?? $reason;
            $note     = $s['Note'] ?? $note;
            $haveSeed = true;
        }

        if (($s['IsVandalism'] ?? 'NO') === 'YES') {
            $anyVandal = true;
        }
    }

    if ($anyVandal) $isVandal = 'YES';

    $compText = "Components used: PartIDs " . implode(',', $comboComponents);
    if ($note === '') {
        $note = $compText;
    } elseif (strpos($note, $compText) === false) {
        $note .= " [{$compText}]";
    }

    $comboEntry = [
        'PartID'          => $comboPartId,
        'PartCode'        => $comboPart['PartCode'],
        'PartName'        => $comboPart['PartName'],
        'Category'        => $comboPart['PartCategory'] ?? '',
        'UnitCost'        => $unitCost,
        'Quantity'        => 1,
        'TotalCost'       => $unitCost,
        'IsVandalism'     => $isVandal,
        'PartReplacedWhy' => $reason,
        'Note'            => $note,
    ];

    $new = [];
    $addedCombo = false;

    foreach ($staged as $s) {
        $pid = (int)($s['PartID'] ?? 0);

        if (in_array($pid, $comboComponents, true)) {
            if (!$addedCombo) {
                $new[] = $comboEntry;
                $addedCombo = true;
            }
            continue;
        }

        $new[] = $s;
    }

    return $new;
}
}
