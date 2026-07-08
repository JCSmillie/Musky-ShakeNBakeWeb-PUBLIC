<?php
// ============================================================================
// PANDA_ChargeDecision_AutoSave.php
// ----------------------------------------------------------------------------
// Autosave handler for PANDA_ChargeDecision.php
//
// Accepts POST:
//   • ChargeID
//   • field (column to update)
//   • value (new value)
//
// Security:
//   Only admins with PANDA roles may use this.
//
// Logs all changes as ActionType="comment" in panda_charge_status_history.
// ============================================================================

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/PANDA_Functions.php';   // includes panda_log_charge_comment()

header('Content-Type: application/json');

panda_require_charges_enabled('json');

// -----------------------------------------------------------------------------
// Auth
// -----------------------------------------------------------------------------
$prefs   = musky_get_logged_in_user_prefs();
$allowed = $prefs['allowed_tools'] ?? [];
$actor   = $prefs['email'] ?? 'unknown@musky';
$isHoldCoordinator = panda_user_is_hold_coordinator($allowed);

panda_require_charge_manage_access($allowed, 'json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !musky_csrf_is_valid('panda_charge_decision')) {
    http_response_code(403);
    echo json_encode([
        'status' => 'ERR',
        'error' => 'Invalid or missing CSRF token.',
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// Input validation
// -----------------------------------------------------------------------------
$chargeId = (int)($_POST['ChargeID'] ?? 0);
$field    = trim($_POST['field'] ?? '');
$value    = $_POST['value'] ?? '';

if ($chargeId <= 0 || $field === '') {
    echo json_encode(["status"=>"ERR","error"=>"Missing ChargeID or field"]);
    exit;
}

$allowedFields = [
    'TicketID',
    'Quantity',
    'SchoolYear',
    'HasInsurance',
    'PartReplacedWhy',
    'AdminNote'
];

if (!in_array($field, $allowedFields, true)) {
    echo json_encode(["status"=>"ERR","error"=>"Invalid field"]);
    exit;
}

// -----------------------------------------------------------------------------
// DB connection
// -----------------------------------------------------------------------------
$pdo = nora_connect();

// -----------------------------------------------------------------------------
// Load charge for recalculation logic if needed
// -----------------------------------------------------------------------------
$st = $pdo->prepare("SELECT * FROM panda_charges WHERE ChargeID = ?");
$st->execute([$chargeId]);
$charge = $st->fetch(PDO::FETCH_ASSOC);

if (!$charge) {
    echo json_encode(["status"=>"ERR","error"=>"Charge not found"]);
    exit;
}

$currentStatus = strtolower(trim((string)($charge['Status'] ?? '')));
if (!in_array($currentStatus, ['submitted', 'hold'], true)) {
    echo json_encode([
        "status" => "ERR",
        "error"  => "Charge status '{$charge['Status']}' is locked. Only submitted or hold charges can be edited."
    ]);
    exit;
}

if ($isHoldCoordinator) {
    if ($currentStatus !== 'hold') {
        echo json_encode([
            "status" => "ERR",
            "error"  => "Supervisor mode is read-only unless the charge is in HOLD."
        ]);
        exit;
    }
    if ($field !== 'AdminNote') {
        echo json_encode([
            "status" => "ERR",
            "error"  => "Supervisor mode allows editing AdminNote only."
        ]);
        exit;
    }
}

function panda_autosave_normalize_value(string $field, $value): string
{
    switch ($field) {
        case 'Quantity':
            return (string)(int)$value;
        case 'HasInsurance':
            return strtoupper(trim((string)$value));
        case 'SchoolYear':
        case 'PartReplacedWhy':
        case 'TicketID':
            return trim((string)$value);
        case 'AdminNote':
            return trim(str_replace("\r\n", "\n", (string)$value));
        default:
            return (string)$value;
    }
}

function panda_autosave_log_preview(string $field, $value): string
{
    $text = panda_autosave_normalize_value($field, $value);
    if ($field === 'AdminNote' && strlen($text) > 80) {
        return substr($text, 0, 80) . '...';
    }
    if (strlen($text) > 120) {
        return substr($text, 0, 120) . '...';
    }
    return $text;
}

// -----------------------------------------------------------------------------
// Field-specific validation
// -----------------------------------------------------------------------------
switch ($field) {
    case 'Quantity':
        $value = (int)$value;
        if ($value < 1) $value = 1;
        break;

    case 'HasInsurance':
        $value = strtoupper($value);
        if (!in_array($value, ['YES','NO','UNKNOWN'], true)) {
            echo json_encode(["status"=>"ERR","error"=>"Invalid HasInsurance value"]);
            exit;
        }
        break;

    case 'PartReplacedWhy':
        $valid = ['Vandalism','Accidental','OEM Failure','Other'];
        if (!in_array($value, $valid, true)) {
            echo json_encode(["status"=>"ERR","error"=>"Invalid PartReplacedWhy"]);
            exit;
        }
        break;

    case 'SchoolYear':
        // Light validation — allow formats: 25-26, 2025-26, 2025-2026
        if (!preg_match('/^\d{2}-\d{2}$/', $value) &&
            !preg_match('/^\d{4}-\d{2}$/', $value) &&
            !preg_match('/^\d{4}-\d{4}$/', $value)) {

            echo json_encode(["status"=>"ERR","error"=>"Invalid SchoolYear"]);
            exit;
        }
        break;

    // TicketID + AdminNote allowed freeform
}

// -----------------------------------------------------------------------------
// Perform update
// -----------------------------------------------------------------------------
try {
    $oldValueRaw = $charge[$field] ?? '';
    $oldValueNorm = panda_autosave_normalize_value($field, $oldValueRaw);
    $newValueNorm = panda_autosave_normalize_value($field, $value);

    if ($oldValueNorm === $newValueNorm) {
        echo json_encode(["status" => "OK", "noop" => true]);
        exit;
    }

    $pdo->beginTransaction();

    // Build update query
    $sql = "UPDATE panda_charges SET {$field} = :val, UpdatedAt = NOW() WHERE ChargeID = :cid";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':val' => $value,
        ':cid' => $chargeId,
    ]);

    // If quantity changed → recalc TotalCost
    if ($field === 'Quantity') {
        $newQty = (int)$value;
        $unitCost = (float)$charge['UnitCost'];
        $newTotal = $newQty * $unitCost;

        $st2 = $pdo->prepare("
            UPDATE panda_charges
            SET TotalCost = :tc, UpdatedAt = NOW()
            WHERE ChargeID = :cid
        ");
        $st2->execute([
            ':tc'  => $newTotal,
            ':cid' => $chargeId,
        ]);
    }

    // Log structured field edits, but skip noisy free-typing fields.
    if (!in_array($field, ['AdminNote', 'TicketID'], true)) {
        $oldPreview = panda_autosave_log_preview($field, $oldValueRaw);
        $newPreview = panda_autosave_log_preview($field, $value);
        $note = "AutoSave updated {$field}: '{$oldPreview}' -> '{$newPreview}'";
        panda_log_charge_comment($pdo, $chargeId, $actor, $note);
    }

    $pdo->commit();

    echo json_encode(["status"=>"OK"]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[PANDA_ChargeDecision_AutoSave] ' . $e->getMessage());
    echo json_encode([
        "status"=>"ERR",
        "error"=>"Unable to save changes right now."
    ]);
}
