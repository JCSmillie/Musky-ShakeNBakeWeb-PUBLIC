<?php
// ============================================================================
// Public Device Vitals - INV_LOOKUP Submit Helper
// ----------------------------------------------------------------------------
// Anonymous users may refresh a known device from the public vitals page, but
// this endpoint must not expose Nora tokens or allow arbitrary errand spam.
// ============================================================================

declare(strict_types=1);

date_default_timezone_set('America/New_York');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

const PUBLIC_DEVICE_VITALS_INVLOOKUP_LOG = '/tmp/public_device_vitals_invlookup.log';
const PUBLIC_DEVICE_VITALS_INVLOOKUP_COOLDOWN_SECONDS = 25;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

function public_vitals_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function public_vitals_normalize_serial(?string $serial): string
{
    return strtoupper(trim((string)$serial));
}

function public_vitals_is_valid_serial(string $serial): bool
{
    return preg_match('/\A[A-Z0-9][A-Z0-9-]{4,31}\z/', $serial) === 1;
}

function public_vitals_cooldown_key(string $serial): string
{
    return 'public_device_vitals_refresh_' . hash('sha256', $serial);
}

function public_vitals_cooldown_remaining(string $serial): int
{
    $lastRefreshAt = (int)($_SESSION[public_vitals_cooldown_key($serial)] ?? 0);
    if ($lastRefreshAt <= 0) {
        return 0;
    }

    return max(0, PUBLIC_DEVICE_VITALS_INVLOOKUP_COOLDOWN_SECONDS - (time() - $lastRefreshAt));
}

function public_vitals_mark_refresh(string $serial): void
{
    $_SESSION[public_vitals_cooldown_key($serial)] = time();
}

function public_vitals_remember_errand(int $errandId, string $serial): void
{
    if (!isset($_SESSION['public_device_vitals_errands']) || !is_array($_SESSION['public_device_vitals_errands'])) {
        $_SESSION['public_device_vitals_errands'] = [];
    }

    $_SESSION['public_device_vitals_errands'][(string)$errandId] = [
        'serial' => $serial,
        'created_at' => time(),
    ];
}

function public_vitals_device_exists(PDO $pdo, string $serial): bool
{
    $stmt = $pdo->prepare("
        SELECT
            EXISTS(SELECT 1 FROM devices WHERE serial_number = :devices_serial LIMIT 1) AS in_devices,
            EXISTS(SELECT 1 FROM device_history WHERE serial_number = :history_serial LIMIT 1) AS in_history
    ");
    $stmt->execute([
        ':devices_serial' => $serial,
        ':history_serial' => $serial,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return !empty($row['in_devices']) || !empty($row['in_history']);
}

function public_vitals_create_inv_lookup(PDO $pdo, string $serial): int
{
    $owner = trim((string)(
        $_SESSION['musky_user']['email']
        ?? $_SESSION['musky_user']['username']
        ?? ''
    ));

    $task = [
        'TaskPriority'      => 5,
        'TaskRepeat'        => 'FALSE',
        'TaskRepeatHowMany' => null,
        'Status'            => 'submitted',
        'MOSBasicRequest'   => 'FALSE',
        'SlackRequest'      => 'FALSE',
        'IIQRequest'        => 'FALSE',
        'NoraRequest'       => 'TRUE',
        'CustomRequest'     => 'FALSE',
        'UDID'              => 'PUBLIC-INVLOOKUP',
        'DeviceSerial'      => 'NONE',
        'AssetTag'          => null,
        'Submitter'         => 'PUBLIC_DEVICE_VIEW',
        'DeviceOwner'       => $owner !== '' ? $owner : null,
        'ExtraDataField01'  => 'INV_LOOKUP',
        'ExtraDataField02'  => json_encode(['serials' => [$serial]], JSON_UNESCAPED_SLASHES),
        'ExtraDataField03'  => null,
        'ExtraDataField04'  => 'Public device refresh requested.',
        'ExtraDataField05'  => null,
        'ExtraDataField06'  => null,
    ];

    $sql = "INSERT INTO nora_errands
        (TaskPriority, TaskRepeat, TaskRepeatHowMany, Status,
         MOSBasicRequest, SlackRequest, IIQRequest, NoraRequest, CustomRequest,
         UDID, DeviceSerial, AssetTag, Submitter, DeviceOwner,
         ExtraDataField01, ExtraDataField02, ExtraDataField03, ExtraDataField04,
         ExtraDataField05, ExtraDataField06)
        VALUES
        (:TaskPriority, :TaskRepeat, :TaskRepeatHowMany, :Status,
         :MOSBasicRequest, :SlackRequest, :IIQRequest, :NoraRequest, :CustomRequest,
         :UDID, :DeviceSerial, :AssetTag, :Submitter, :DeviceOwner,
         :ExtraDataField01, :ExtraDataField02, :ExtraDataField03, :ExtraDataField04,
         :ExtraDataField05, :ExtraDataField06)";

    $pdo->prepare($sql)->execute($task);
    return (int)$pdo->lastInsertId();
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    public_vitals_json([
        'error' => 'invalid_input',
        'detail' => 'Expected JSON request body.',
    ], 400);
}

if (!musky_csrf_is_valid('public_device_vitals_refresh', $input['csrf_token'] ?? null)) {
    public_vitals_json([
        'error' => 'forbidden',
        'detail' => 'Invalid or missing CSRF token.',
    ], 403);
}

$serial = public_vitals_normalize_serial($input['serial'] ?? '');
if ($serial === '' || !public_vitals_is_valid_serial($serial)) {
    public_vitals_json([
        'error' => 'invalid_input',
        'detail' => 'Enter a valid serial number.',
    ], 400);
}

$remaining = public_vitals_cooldown_remaining($serial);
if ($remaining > 0) {
    public_vitals_json([
        'error' => 'cooldown',
        'detail' => "Please wait {$remaining} more second" . ($remaining === 1 ? '' : 's') . " before refreshing again.",
        'cooldown_remaining' => $remaining,
        'cooldown_seconds' => PUBLIC_DEVICE_VITALS_INVLOOKUP_COOLDOWN_SECONDS,
    ], 429);
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
    if (!public_vitals_device_exists($pdo, $serial)) {
        public_vitals_json([
            'error' => 'not_found',
            'detail' => 'No device record was found for that serial number.',
        ], 404);
    }

    $errandId = public_vitals_create_inv_lookup($pdo, $serial);
    public_vitals_remember_errand($errandId, $serial);
    public_vitals_mark_refresh($serial);
} catch (Throwable $e) {
    @file_put_contents(
        PUBLIC_DEVICE_VITALS_INVLOOKUP_LOG,
        date('c') . " create_failed serial={$serial} error=" . $e->getMessage() . "\n",
        FILE_APPEND
    );
    public_vitals_json([
        'error' => 'inv_lookup_failed',
        'detail' => 'Could not create INV_LOOKUP errand.',
    ], 500);
}

@file_put_contents(
    PUBLIC_DEVICE_VITALS_INVLOOKUP_LOG,
    date('c') . " created errand={$errandId} serial={$serial}\n",
    FILE_APPEND
);

public_vitals_json([
    'success' => true,
    'errand_id' => $errandId,
    'cooldown_seconds' => PUBLIC_DEVICE_VITALS_INVLOOKUP_COOLDOWN_SECONDS,
]);
