<?php
// ============================================================================
// Public Device Vitals - INV_LOOKUP Status Helper
// ----------------------------------------------------------------------------
// Polls only the public refresh errand created in this visitor's session and
// returns a sanitized status shape.
// ============================================================================

declare(strict_types=1);

date_default_timezone_set('America/New_York');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

const PUBLIC_DEVICE_VITALS_STATUS_LOG = '/tmp/public_device_vitals_invlookup_status.log';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

function public_vitals_status_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function public_vitals_status_normalize_serial(?string $serial): string
{
    return strtoupper(trim((string)$serial));
}

function public_vitals_status_is_valid_serial(string $serial): bool
{
    return preg_match('/\A[A-Z0-9][A-Z0-9-]{4,31}\z/', $serial) === 1;
}

function public_vitals_status_session_owns_errand(int $errandId, string $serial): bool
{
    $entry = $_SESSION['public_device_vitals_errands'][(string)$errandId] ?? null;
    if (!is_array($entry)) {
        return false;
    }

    $createdAt = (int)($entry['created_at'] ?? 0);
    if ($createdAt > 0 && $createdAt < time() - 3600) {
        unset($_SESSION['public_device_vitals_errands'][(string)$errandId]);
        return false;
    }

    return hash_equals((string)($entry['serial'] ?? ''), $serial);
}

function public_vitals_status_errand_has_serial(array $row, string $serial): bool
{
    $payload = json_decode((string)($row['ExtraDataField02'] ?? ''), true);
    if (!is_array($payload) || !is_array($payload['serials'] ?? null)) {
        return false;
    }

    return in_array($serial, array_map('strval', $payload['serials']), true);
}

function public_vitals_status_message(string $normalized, string $rawStatus): string
{
    if ($normalized === 'complete') {
        return 'INV_LOOKUP complete.';
    }

    if ($normalized === 'failed') {
        return 'INV_LOOKUP failed with status ' . $rawStatus . '.';
    }

    return $normalized === 'running'
        ? 'INV_LOOKUP is still running.'
        : 'INV_LOOKUP is queued.';
}

if (!musky_csrf_is_valid('public_device_vitals_refresh')) {
    public_vitals_status_json([
        'error' => 'forbidden',
        'message' => 'Invalid or missing CSRF token.',
    ], 403);
}

$errandIdRaw = trim((string)($_GET['id'] ?? ($_GET['errand_id'] ?? '')));
$serial = public_vitals_status_normalize_serial($_GET['serial'] ?? '');

if ($errandIdRaw === '' || !ctype_digit($errandIdRaw) || $serial === '' || !public_vitals_status_is_valid_serial($serial)) {
    public_vitals_status_json([
        'error' => 'bad_request',
        'message' => 'Serial and errand_id are required.',
    ], 400);
}

$errandId = (int)$errandIdRaw;
if (!public_vitals_status_session_owns_errand($errandId, $serial)) {
    public_vitals_status_json([
        'error' => 'forbidden',
        'message' => 'Could not read INV_LOOKUP status for this session.',
    ], 403);
}

try {
    if (!defined('NORA_CONNECT_NO_AUTO')) {
        define('NORA_CONNECT_NO_AUTO', true);
    }
    if (!defined('NORA_CONNECT_THROW')) {
        define('NORA_CONNECT_THROW', true);
    }
    require_once __DIR__ . '/../../Functions/nora_connect.php';
    $pdo = nora_connect();
    $stmt = $pdo->prepare("
        SELECT
            ErrandID,
            Status,
            Submitter,
            UDID,
            ExtraDataField01,
            ExtraDataField02
        FROM nora_errands
        WHERE ErrandID = :id
          AND Submitter = 'PUBLIC_DEVICE_VIEW'
          AND UDID = 'PUBLIC-INVLOOKUP'
          AND ExtraDataField01 = 'INV_LOOKUP'
        LIMIT 1
    ");
    $stmt->execute([':id' => $errandId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    @file_put_contents(
        PUBLIC_DEVICE_VITALS_STATUS_LOG,
        date('c') . " lookup_failed errand={$errandId} error=" . $e->getMessage() . "\n",
        FILE_APPEND
    );
    public_vitals_status_json([
        'error' => 'nora_status_failed',
        'message' => 'Could not read INV_LOOKUP status from Nora.',
    ], 500);
}

if (!$row || !public_vitals_status_errand_has_serial($row, $serial)) {
    public_vitals_status_json([
        'error' => 'not_found',
        'message' => 'Could not read INV_LOOKUP status for this serial.',
    ], 404);
}

$rawStatus = strtoupper(trim((string)($row['Status'] ?? 'UNKNOWN')));
$status = 'queued';

if ($rawStatus === 'COMPLETE') {
    $status = 'complete';
} elseif (in_array($rawStatus, ['FAILED', 'CANCELLED', 'REJECTED'], true)) {
    $status = 'failed';
} elseif (in_array($rawStatus, ['PROCESSING', 'RUNNING', 'IN_PROGRESS'], true)) {
    $status = 'running';
}

public_vitals_status_json([
    'errand_id' => $errandId,
    'status' => $status,
    'message' => public_vitals_status_message($status, $rawStatus),
]);
