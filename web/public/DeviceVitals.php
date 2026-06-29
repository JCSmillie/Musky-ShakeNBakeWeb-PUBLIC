<?php
// ============================================================================
// MUSKY - Public DeviceVitals Viewer
// ----------------------------------------------------------------------------
// PURPOSE
//   This page lives outside the normal authenticated Musky scope and allows a
//   viewer to look up a single device by serial number.
//
// DESIGN RULES
//   1. Anonymous visitors may view core device vitals only.
//   2. Anonymous visitors may trigger a refresh (INV_LOOKUP) but do not see
//      private owner/IP information.
//   3. Authenticated viewers get the familiar MyDevices-style action sidebar
//      (minus power tools) plus richer assignment/IP visibility.
//   4. The page follows the user's selected Musky theme when authenticated.
//
// NOTES
//   - This file is intentionally self-contained so it can safely sit in
//     /web/public without inheriting check_access redirect behavior.
//   - We reuse Nora connection + API patterns already used elsewhere in Musky.
// ============================================================================

declare(strict_types=1);

date_default_timezone_set('America/New_York');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ----------------------------------------------------------------------------
// Search Engine Discouragement
// ----------------------------------------------------------------------------
// This page is intentionally public-facing for direct support links, but we do
// not want search engines indexing it or showing snippets/cached copies.
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
if (!defined('NORA_CONNECT_NO_AUTO')) {
    define('NORA_CONNECT_NO_AUTO', true);
}
if (!defined('NORA_CONNECT_THROW')) {
    define('NORA_CONNECT_THROW', true);
}
require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/MuskyDeviceIcons.php';

// ----------------------------------------------------------------------------
// Constants
// ----------------------------------------------------------------------------
const PUBLIC_DEVICE_VITALS_LOG = '/tmp/public_device_vitals.log';
const PUBLIC_DEVICE_VITALS_GATEWAY_SUBNET = '65.254.21.';
const PUBLIC_DEVICE_VITALS_REFRESH_COOLDOWN_SECONDS = 25;

/**
 * Small debug logger for this public page.
 */
function public_device_view_log(string $message): void
{
    @file_put_contents(
        PUBLIC_DEVICE_VITALS_LOG,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Normalize serial input so all lookups behave consistently.
 */
function public_device_view_normalize_serial(?string $serial): string
{
    return strtoupper(trim((string)$serial));
}

/**
 * Keep public lookups constrained to normal device serial shapes.
 */
function public_device_view_is_valid_serial(string $serial): bool
{
    return preg_match('/\A[A-Z0-9][A-Z0-9-]{4,31}\z/', $serial) === 1;
}

/**
 * Emit a small JSON response and stop.
 */
function public_device_view_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Session-backed cooldown for the public refresh button.
 */
function public_device_view_refresh_cooldown_remaining(string $serial): int
{
    $key = 'public_device_vitals_refresh_' . hash('sha256', $serial);
    $lastRefreshAt = (int)($_SESSION[$key] ?? 0);
    if ($lastRefreshAt <= 0) {
        return 0;
    }

    $remaining = PUBLIC_DEVICE_VITALS_REFRESH_COOLDOWN_SECONDS - (time() - $lastRefreshAt);
    return max(0, $remaining);
}

function public_device_view_mark_refresh(string $serial): void
{
    $_SESSION['public_device_vitals_refresh_' . hash('sha256', $serial)] = time();
}

function public_device_view_remember_errand(int $errandId, string $serial): void
{
    if ($errandId <= 0) {
        return;
    }

    if (!isset($_SESSION['public_device_vitals_errands']) || !is_array($_SESSION['public_device_vitals_errands'])) {
        $_SESSION['public_device_vitals_errands'] = [];
    }

    $_SESSION['public_device_vitals_errands'][(string)$errandId] = [
        'serial' => $serial,
        'created_at' => time(),
    ];
}

function public_device_view_session_owns_errand(int $errandId, string $serial): bool
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

/**
 * Public viewers may know whether an asset is assigned, but not to whom.
 */
function public_device_view_public_assignment_label(?string $name, ?string $email): string
{
    return trim((string)$name) !== '' || trim((string)$email) !== ''
        ? 'Assigned'
        : 'Unassigned';
}

/**
 * Determine whether the current viewer already has a valid Musky session.
 *
 * We intentionally do NOT use check_access.php here because this page is meant
 * to remain public. We only detect an existing login and enrich the UI if it is
 * already present.
 */
function public_device_view_is_authenticated(): bool
{
    $legacyLogin = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    $ssoLogin = !empty($_SESSION['musky_user']['email'])
        && filter_var($_SESSION['musky_user']['email'], FILTER_VALIDATE_EMAIL);
    $host = explode(':', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')), 2)[0];
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $localDev = str_ends_with($host, musky_dev_host_suffix())
        && in_array($remoteAddr, ['127.0.0.1', '::1'], true);

    return $legacyLogin || $ssoLogin || $localDev;
}

/**
 * Build the theme/user preference map for authenticated sessions.
 *
 * In local dev, LoggedInUserPrefs will activate the normal dev harness so this
 * public page still feels like the rest of Musky while you test it.
 */
function public_device_view_prefs(): array
{
    if (!public_device_view_is_authenticated()) {
        return [
            'email' => '',
            'display_name' => '',
            'theme' => 'musky-mode',
            'allowed_tools' => [],
        ];
    }

    return musky_get_logged_in_user_prefs();
}

/**
 * Human-friendly "time ago" text used in cards/history.
 */
function public_device_view_time_ago(?string $ts): string
{
    if (!$ts) {
        return 'Unknown';
    }

    $unix = strtotime($ts);
    if (!$unix) {
        return $ts;
    }

    $delta = time() - $unix;
    if ($delta < 60) {
        return $delta . ' sec ago';
    }
    if ($delta < 3600) {
        return floor($delta / 60) . ' min ago';
    }
    if ($delta < 86400) {
        return floor($delta / 3600) . ' hr ago';
    }
    return floor($delta / 86400) . ' day' . (floor($delta / 86400) === 1 ? '' : 's') . ' ago';
}

/**
 * Format disk space in GB.
 */
function public_device_view_format_disk(?float $value): string
{
    if ($value === null) {
        return 'Unknown';
    }
    return rtrim(rtrim(number_format($value, 1), '0'), '.') . ' GB';
}

/**
 * Format a decimal percentage like 0.73 into "73%".
 */
function public_device_view_format_percent($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return 'Unknown';
    }
    return (string)round(((float)$value) * 100) . '%';
}

/**
 * Return the first non-empty string from a list of candidates.
 */
function public_device_view_first_present(...$values): string
{
    foreach ($values as $value) {
        $text = trim((string)$value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

/**
 * Return whichever timestamp is newer.
 *
 * This is used when the page has both:
 * - a direct Nora errand timestamp
 * - a newer device_history snapshot/import timestamp
 *
 * If Nora imported a fresher row after an older explicit INV_LOOKUP errand,
 * the page should report the newer Nora-side activity instead of implying Nora
 * has not touched the device since the older errand.
 */
function public_device_view_latest_timestamp(?string $a, ?string $b): string
{
    $a = trim((string)$a);
    $b = trim((string)$b);

    if ($a === '') {
        return $b;
    }
    if ($b === '') {
        return $a;
    }

    $aTs = strtotime($a);
    $bTs = strtotime($b);

    if ($aTs === false) {
        return $b;
    }
    if ($bTs === false) {
        return $a;
    }

    return $bTs > $aTs ? $b : $a;
}

/**
 * Map device model identifiers to the same Musky icon style used elsewhere.
 */
function public_device_view_model_visual(?string $deviceModel, ?string $modelName = null): array
{
    $visual = musky_device_icon_profile($deviceModel, $modelName);

    return [
        'icon' => $visual['icon'],
        'generation_label' => $visual['label'],
    ];
}

/**
 * Compute campus wording in the same general spirit as ClassExplorer.
 *
 * Anonymous viewers only get "On Campus" / "Off Campus" / "Never Seen".
 * Authenticated viewers still get the same label, but we also return a more
 * detailed note they can read alongside the IP history section.
 */
function public_device_view_campus_state(?string $lastIp, ?string $lastSeen, bool $authenticated): array
{
    $lastIp = trim((string)$lastIp);
    $lastSeen = trim((string)$lastSeen);
    $lastSeenTs = $lastSeen !== '' ? strtotime($lastSeen) : false;
    $today = date('Y-m-d');
    $beatDate = $lastSeenTs ? date('Y-m-d', $lastSeenTs) : '';
    $onCampusToday = ($lastIp !== '')
        && str_starts_with($lastIp, PUBLIC_DEVICE_VITALS_GATEWAY_SUBNET)
        && ($beatDate === $today);

    if (!$lastSeenTs) {
        return [
            'label' => 'Never Seen',
            'note'  => 'No recent network check-in is on file.',
        ];
    }

    if ($onCampusToday) {
        return [
            'label' => 'On Campus',
            'note'  => $authenticated
                ? 'Campus IP seen today: ' . $lastIp
                : 'Seen on campus today.',
        ];
    }

    return [
        'label' => 'Off Campus',
        'note'  => $authenticated
            ? 'Most recent check-in was off campus from ' . ($lastIp !== '' ? $lastIp : 'an unknown IP')
            : 'Most recent check-in was off campus.',
    ];
}

/**
 * Build lightweight health flags from the latest device_history row.
 *
 * We intentionally mirror the same thresholds used in fetch_device_health.php
 * so the public page speaks the same language as the rest of Musky.
 */
function public_device_view_health_flags(array $row): array
{
    $flags = [];
    $issues = [];

    $battery = isset($row['battery']) && $row['battery'] !== '' ? (float)$row['battery'] : null;
    $percentDisk = isset($row['percent_disk']) && $row['percent_disk'] !== '' ? (float)$row['percent_disk'] : null;
    $availableDisk = isset($row['available_disk']) && $row['available_disk'] !== '' ? (float)$row['available_disk'] : null;
    $totalDisk = isset($row['total_disk']) && $row['total_disk'] !== '' ? (float)$row['total_disk'] : null;
    $lostMode = strtoupper(trim((string)($row['lostmode_status'] ?? '')));
    $isMuted = (int)($row['is_muted'] ?? 0);
    $osVersion = trim((string)($row['os_version'] ?? ''));
    $needUpdate = trim((string)($row['needosupdate'] ?? ''));

    if ($battery !== null && $battery < 0.30) {
        $flags[] = 'low_battery';
        $issues[] = 'Low battery';
    }

    if ($percentDisk !== null && $percentDisk >= 0.85) {
        $flags[] = 'disk_full';
        $issues[] = 'Disk nearly full';
    } elseif ($percentDisk !== null && $percentDisk > 0 && $percentDisk <= 0.50 && $availableDisk !== null && $totalDisk !== null && $totalDisk > 0) {
        $percentUsed = 1 - $percentDisk;
        if ($percentUsed >= 0.85 || $percentDisk <= 0.30) {
            $flags[] = 'disk_full';
            $issues[] = 'Disk nearly full';
        }
    } elseif ($availableDisk !== null && $totalDisk !== null && $totalDisk > 0) {
        $percentFree = $availableDisk / $totalDisk;
        if ($percentFree < 0.15) {
            $flags[] = 'disk_full';
            $issues[] = 'Disk nearly full';
        }
    }

    if (in_array($lostMode, ['ENABLED', 'PENDING', 'PENDING_TO_ENABLE'], true)) {
        $flags[] = 'lost_mode';
        $issues[] = 'Lost mode active';
    }

    if ($isMuted === 1) {
        $flags[] = 'muted';
        $issues[] = 'Muted';
    }

    if ($needUpdate !== '' && $osVersion !== '' && version_compare($needUpdate, $osVersion, '>')) {
        $flags[] = 'needs_update';
        $issues[] = 'OS update available';
    }

    return [
        'flags' => array_values(array_unique($flags)),
        'issues' => array_values(array_unique($issues)),
    ];
}

/**
 * Get the current device row plus the latest history snapshot.
 *
 * We fetch "current device" and "latest history" separately so the page still
 * works if one exists without the other.
 */
function public_device_view_fetch_device(PDO $pdo, string $serial, bool $authenticated): array
{
    $deviceStmt = $pdo->prepare("
        SELECT
            d.serial_number,
            d.asset_tag,
            d.model,
            d.device_model,
            d.os_version,
            d.enrollment_type,
            d.owner_id,
            o.full_name AS owner_name,
            o.email     AS owner_email
        FROM devices d
        LEFT JOIN owners o
          ON o.id = d.owner_id
        WHERE d.serial_number = :serial
        LIMIT 1
    ");
    $deviceStmt->execute([':serial' => $serial]);
    $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $historyStmt = $pdo->prepare("
        SELECT *
        FROM device_history
        WHERE serial_number = :serial
        ORDER BY last_seen DESC
        LIMIT 1
    ");
    $historyStmt->execute([':serial' => $serial]);
    $historyRow = $historyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $noraStmt = $pdo->prepare("
        SELECT
            ErrandID,
            Status,
            Submitter,
            SubmissionDateTime,
            CompleteDateTime
        FROM nora_errands
        WHERE ExtraDataField01 = 'INV_LOOKUP'
          AND (
                DeviceSerial = :serial_exact
             OR ExtraDataField02 LIKE :serial_like
          )
        ORDER BY SubmissionDateTime DESC, ErrandID DESC
        LIMIT 1
    ");
    $noraStmt->execute([
        ':serial_exact' => $serial,
        ':serial_like'  => '%' . $serial . '%',
    ]);
    $latestNoraLookup = $noraStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if (!$deviceRow && !$historyRow) {
        return [
            'found' => false,
            'serial_number' => $serial,
        ];
    }

    $merged = array_merge($historyRow, $deviceRow);
    $merged['serial_number'] = $serial;

    $displayModel = trim((string)($merged['device_model'] ?? $merged['model'] ?? ''));
    $visual = public_device_view_model_visual(
        (string)($merged['device_model'] ?? ''),
        (string)($merged['model'] ?? '')
    );
    $campus = public_device_view_campus_state(
        $historyRow['last_ip_beat'] ?? null,
        $historyRow['last_seen'] ?? null,
        $authenticated
    );
    $health = public_device_view_health_flags($merged);

    $ipHistory = [];
    if ($authenticated) {
        $ipStmt = $pdo->prepare("
            SELECT
                last_ip_beat,
                MAX(last_seen) AS last_seen,
                COUNT(*) AS hit_count
            FROM device_history FORCE INDEX (idx_hist_serial_ip_lastseen)
            WHERE serial_number = :serial
              AND last_ip_beat IS NOT NULL
              AND last_ip_beat <> ''
            GROUP BY last_ip_beat
            ORDER BY MAX(last_seen) DESC
            LIMIT 8
        ");
        $ipStmt->execute([':serial' => $serial]);
        $ipRows = $ipStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($ipRows as $ipRow) {
            $ipHistory[] = [
                'ip' => $ipRow['last_ip_beat'],
                'last_seen' => $ipRow['last_seen'],
                'time_ago' => public_device_view_time_ago($ipRow['last_seen']),
                'campus_label' => str_starts_with((string)$ipRow['last_ip_beat'], PUBLIC_DEVICE_VITALS_GATEWAY_SUBNET)
                    ? 'On Campus'
                    : 'Off Campus',
                'hit_count' => (int)($ipRow['hit_count'] ?? 0),
            ];
        }
    }

    $ownerName = trim((string)($merged['owner_name'] ?? ''));
    $ownerEmail = trim((string)($merged['owner_email'] ?? ''));
    $dataSnapshot = public_device_view_first_present(
        $historyRow['snapshot_time'] ?? '',
        $historyRow['imported_at'] ?? '',
        $historyRow['import_time'] ?? '',
        $historyRow['updated_at'] ?? '',
        $historyRow['created_at'] ?? '',
        $historyRow['last_seen'] ?? ''
    );
    $explicitNoraLookupDate = public_device_view_first_present(
        $latestNoraLookup['SubmissionDateTime'] ?? '',
        $latestNoraLookup['CompleteDateTime'] ?? ''
    );
    $lastNoraDate = public_device_view_latest_timestamp($explicitNoraLookupDate, $dataSnapshot);
    $lastNoraStatus = '';
    if ($lastNoraDate !== '') {
        if ($explicitNoraLookupDate !== '' && $lastNoraDate === $explicitNoraLookupDate) {
            $lastNoraStatus = trim((string)($latestNoraLookup['Status'] ?? ''));
        } elseif ($dataSnapshot !== '' && $lastNoraDate === $dataSnapshot) {
            $lastNoraStatus = 'Snapshot Import';
        }
    }

    $device = [
        'found' => true,
        'serial_number' => $serial,
        'asset_tag' => trim((string)($merged['asset_tag'] ?? '')),
        'model' => trim((string)($merged['model'] ?? '')),
        'device_model' => $displayModel,
        'os_version' => trim((string)($merged['os_version'] ?? '')),
        'available_disk_value' => isset($merged['available_disk']) && $merged['available_disk'] !== '' ? (float)$merged['available_disk'] : null,
        'battery_percent' => isset($merged['battery']) && $merged['battery'] !== '' ? public_device_view_format_percent($merged['battery']) : 'Unknown',
        'total_disk' => isset($merged['total_disk']) && $merged['total_disk'] !== '' ? public_device_view_format_disk((float)$merged['total_disk']) : 'Unknown',
        'available_disk' => isset($merged['available_disk']) && $merged['available_disk'] !== '' ? public_device_view_format_disk((float)$merged['available_disk']) : 'Unknown',
        'disk_usage' => isset($merged['percent_disk']) && $merged['percent_disk'] !== '' ? public_device_view_format_percent($merged['percent_disk']) : 'Unknown',
        'last_seen' => $historyRow['last_seen'] ?? '',
        'last_seen_ago' => public_device_view_time_ago($historyRow['last_seen'] ?? null),
        'data_snapshot' => $dataSnapshot,
        'data_snapshot_ago' => public_device_view_time_ago($dataSnapshot !== '' ? $dataSnapshot : null),
        'last_nora_data_date' => $lastNoraDate,
        'last_nora_data_date_ago' => public_device_view_time_ago($lastNoraDate !== '' ? $lastNoraDate : null),
        'last_nora_status' => $lastNoraStatus,
        'last_nora_submitter' => $authenticated ? trim((string)($latestNoraLookup['Submitter'] ?? '')) : '',
        'lostmode_status' => trim((string)($merged['lostmode_status'] ?? 'UNKNOWN')),
        'needosupdate' => trim((string)($merged['needosupdate'] ?? '')),
        'update_needed' => (
            trim((string)($merged['needosupdate'] ?? '')) !== ''
            && trim((string)($merged['os_version'] ?? '')) !== ''
            && version_compare(trim((string)($merged['needosupdate'] ?? '')), trim((string)($merged['os_version'] ?? '')), '>')
        ),
        'campus_label' => $campus['label'],
        'campus_note' => $campus['note'],
        'owner_display' => $authenticated
            ? ($ownerName !== '' ? $ownerName : 'Unassigned')
            : public_device_view_public_assignment_label($ownerName, $ownerEmail),
        'owner_email' => $authenticated ? $ownerEmail : '',
        'icon_path' => $visual['icon'],
        'generation_label' => $visual['generation_label'],
        'open_direct_device_link' => $authenticated ? trim((string)($historyRow['open_direct_device_link'] ?? '')) : '',
        'tags' => $authenticated ? trim((string)($merged['tags'] ?? '')) : '',
        'health_flags' => $health['flags'],
        'health_issues' => $health['issues'],
        'ip_history' => $ipHistory,
    ];

    if ($authenticated) {
        $device['raw'] = $merged;
    }

    return $device;
}

function public_device_view_device_exists(PDO $pdo, string $serial): bool
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

function public_device_view_errand_has_serial(array $row, string $serial): bool
{
    $payload = json_decode((string)($row['ExtraDataField02'] ?? ''), true);
    if (!is_array($payload)) {
        return false;
    }

    $serials = $payload['serials'] ?? [];
    if (!is_array($serials)) {
        return false;
    }

    return in_array($serial, array_map('strval', $serials), true);
}

function public_device_view_status_message(string $normalized, string $rawStatus): string
{
    if ($normalized === 'complete') {
        return 'INV_LOOKUP complete.';
    }

    if (in_array($normalized, ['failed', 'cancelled', 'rejected'], true)) {
        return 'INV_LOOKUP failed with status ' . $rawStatus . '.';
    }

    if ($normalized === 'processing') {
        return 'INV_LOOKUP is still running.';
    }

    return 'INV_LOOKUP is queued.';
}

/**
 * Submit INV_LOOKUP for one serial.
 *
 * This public page does not have a stable Nora bearer token source in
 * the root nora_config.json token path, so we create the errand directly in
 * nora_errands using
 * the same shape NoraAPI would have written. The browser then polls the row
 * until the Nora worker finishes it.
 */
function public_device_view_submit_inv_lookup(PDO $pdo, string $serial, string $viewerEmail): array
{
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
        'DeviceOwner'       => $viewerEmail !== '' ? $viewerEmail : null,
        'ExtraDataField01'  => 'INV_LOOKUP',
        'ExtraDataField02'  => json_encode(['serials' => [$serial]], JSON_UNESCAPED_SLASHES),
        'ExtraDataField03'  => null,
        'ExtraDataField04'  => 'Public device refresh requested.',
        'ExtraDataField05'  => null,
        'ExtraDataField06'  => null,
    ];

    try {
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
        $errandId = (int)$pdo->lastInsertId();
        public_device_view_remember_errand($errandId, $serial);
        public_device_view_log("Created INV_LOOKUP errand #{$errandId} for serial {$serial}");
    } catch (Throwable $e) {
        public_device_view_log("Failed to create INV_LOOKUP errand for {$serial}: " . $e->getMessage());
        return [
            'ok' => false,
            'message' => 'Could not create INV_LOOKUP errand.',
        ];
    }

    return [
        'ok' => true,
        'errand_id' => $errandId,
        'status' => 'queued',
        'message' => 'INV_LOOKUP submitted. Waiting for Nora to finish.',
    ];
}

/**
 * Fetch one Nora errand status and normalize it for the browser.
 */
function public_device_view_check_inv_lookup(PDO $pdo, int $errandId, string $serial): array
{
    if (!public_device_view_session_owns_errand($errandId, $serial)) {
        return [
            'ok' => false,
            'errand_id' => $errandId,
            'status' => 'unknown',
            'message' => 'Could not read INV_LOOKUP status for this session.',
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            ErrandID,
            Status,
            Submitter,
            UDID,
            ExtraDataField01,
            ExtraDataField02,
            SubmissionDateTime,
            CompleteDateTime
        FROM nora_errands
        WHERE ErrandID = :id
          AND Submitter = 'PUBLIC_DEVICE_VIEW'
          AND UDID = 'PUBLIC-INVLOOKUP'
          AND ExtraDataField01 = 'INV_LOOKUP'
        LIMIT 1
    ");
    $stmt->execute([':id' => $errandId]);
    $statusResp = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$statusResp || !public_device_view_errand_has_serial($statusResp, $serial)) {
        return [
            'ok' => false,
            'errand_id' => $errandId,
            'status' => 'unknown',
            'message' => 'Could not read INV_LOOKUP status from Nora.',
        ];
    }

    $rawStatus = strtoupper(trim((string)($statusResp['Status'] ?? 'UNKNOWN')));

    $normalized = 'queued';
    $ok = true;

    if ($rawStatus === 'COMPLETE') {
        $normalized = 'complete';
    } elseif (in_array($rawStatus, ['FAILED', 'CANCELLED', 'REJECTED'], true)) {
        $normalized = strtolower($rawStatus);
        $ok = false;
    } elseif (in_array($rawStatus, ['PROCESSING', 'RUNNING', 'IN_PROGRESS'], true)) {
        $normalized = 'processing';
    }

    return [
        'ok' => $ok,
        'errand_id' => $errandId,
        'status' => $normalized,
        'message' => public_device_view_status_message($normalized, $rawStatus),
    ];
}

// ----------------------------------------------------------------------------
// Session / Theme / DB bootstrap
// ----------------------------------------------------------------------------
$prefs = public_device_view_prefs();
$isAuthenticated = public_device_view_is_authenticated();
$theme = $prefs['theme'] ?? 'light-mode';
$viewerEmail = trim((string)($prefs['email'] ?? ($_SESSION['username'] ?? '')));

try {
    $pdo = nora_connect();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to connect to Nora.';
    exit;
}

// ----------------------------------------------------------------------------
// AJAX: Refresh workflow
// ----------------------------------------------------------------------------
if (isset($_POST['api']) && $_POST['api'] === 'refresh_start') {
    header('Content-Type: application/json; charset=utf-8');

    if (!musky_csrf_is_valid('public_device_vitals_refresh')) {
        public_device_view_json([
            'ok' => false,
            'message' => 'Invalid or missing CSRF token.',
        ], 403);
    }

    $serial = public_device_view_normalize_serial($_POST['serial'] ?? '');
    if ($serial === '' || !public_device_view_is_valid_serial($serial)) {
        public_device_view_json([
            'ok' => false,
            'message' => 'Enter a valid serial number.',
        ], 400);
    }

    $remaining = public_device_view_refresh_cooldown_remaining($serial);
    if ($remaining > 0) {
        public_device_view_json([
            'ok' => false,
            'message' => "Please wait {$remaining} more second" . ($remaining === 1 ? '' : 's') . " before refreshing again.",
            'cooldown_remaining' => $remaining,
            'cooldown_seconds' => PUBLIC_DEVICE_VITALS_REFRESH_COOLDOWN_SECONDS,
        ], 429);
    }

    if (!public_device_view_device_exists($pdo, $serial)) {
        public_device_view_json([
            'ok' => false,
            'message' => 'No device record was found for that serial number.',
        ], 404);
    }

    $refresh = public_device_view_submit_inv_lookup($pdo, $serial, $viewerEmail);
    if (!empty($refresh['ok'])) {
        public_device_view_mark_refresh($serial);
        $refresh['cooldown_seconds'] = PUBLIC_DEVICE_VITALS_REFRESH_COOLDOWN_SECONDS;
    }
    $device = public_device_view_fetch_device($pdo, $serial, $isAuthenticated);

    public_device_view_json([
        'ok' => $refresh['ok'],
        'device' => $device,
        'refresh' => $refresh,
    ]);
}

if (isset($_GET['api']) && $_GET['api'] === 'refresh_status') {
    header('Content-Type: application/json; charset=utf-8');

    if (!musky_csrf_is_valid('public_device_vitals_refresh')) {
        public_device_view_json([
            'ok' => false,
            'message' => 'Invalid or missing CSRF token.',
        ], 403);
    }

    $serial = public_device_view_normalize_serial($_GET['serial'] ?? '');
    $errandId = (int)($_GET['errand_id'] ?? 0);

    if ($serial === '' || !public_device_view_is_valid_serial($serial) || $errandId <= 0) {
        public_device_view_json([
            'ok' => false,
            'message' => 'Serial and errand_id are required.',
        ], 400);
    }

    $refresh = public_device_view_check_inv_lookup($pdo, $errandId, $serial);
    $device = public_device_view_fetch_device($pdo, $serial, $isAuthenticated);

    public_device_view_json([
        'ok' => true,
        'device' => $device,
        'refresh' => $refresh,
    ]);
}

if (isset($_GET['api']) && $_GET['api'] === 'device_snapshot') {
    header('Content-Type: application/json; charset=utf-8');

    $serial = public_device_view_normalize_serial($_GET['serial'] ?? '');
    if ($serial === '' || !public_device_view_is_valid_serial($serial)) {
        public_device_view_json([
            'ok' => false,
            'message' => 'Enter a valid serial number.',
        ], 400);
    }

    public_device_view_json([
        'ok' => true,
        'device' => public_device_view_fetch_device($pdo, $serial, $isAuthenticated),
    ]);
}

// ----------------------------------------------------------------------------
// Initial page state
// ----------------------------------------------------------------------------
$requestedSerial = public_device_view_normalize_serial($_GET['serial'] ?? '');
$initialDevice = $requestedSerial !== '' && public_device_view_is_valid_serial($requestedSerial)
    ? public_device_view_fetch_device($pdo, $requestedSerial, $isAuthenticated)
    : null;
$csrfToken = musky_csrf_token('public_device_vitals_refresh');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Device Vitals</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<style>
body {
    margin: 0;
    min-height: 100vh;
}

body.light-mode {
    --pdv-panel: #ffffff;
    --pdv-panel-border: #d8dee8;
    --pdv-soft: #eef4ff;
    --pdv-text: #1f2937;
    --pdv-muted: #6b7280;
    --pdv-accent: #2563eb;
    --pdv-success: #16a34a;
    --pdv-warning: #d97706;
    --pdv-danger: #dc2626;
}

body.dark-mode {
    --pdv-panel: #1f2937;
    --pdv-panel-border: #374151;
    --pdv-soft: #111827;
    --pdv-text: #f3f4f6;
    --pdv-muted: #9ca3af;
    --pdv-accent: #60a5fa;
    --pdv-success: #4ade80;
    --pdv-warning: #f59e0b;
    --pdv-danger: #f87171;
}

body.musky-mode {
    --pdv-panel: #163424;
    --pdv-panel-border: #2d5b43;
    --pdv-soft: rgba(255, 136, 0, 0.10);
    --pdv-text: #edf8ef;
    --pdv-muted: #bed5c3;
    --pdv-accent: #ff8800;
    --pdv-success: #7ee787;
    --pdv-warning: #ffb454;
    --pdv-danger: #ff8a80;
}

body.gator-time-mode {
    --pdv-panel: #111111;
    --pdv-panel-border: #4a4326;
    --pdv-soft: rgba(197, 179, 88, 0.10);
    --pdv-text: #f5ecba;
    --pdv-muted: #c6b983;
    --pdv-accent: #c5b358;
    --pdv-success: #d7d05f;
    --pdv-warning: #d9a441;
    --pdv-danger: #ff7b72;
}

.page-shell {
    width: min(1160px, 94vw);
    margin: 0 auto;
    padding: 20px 0 32px;
    color: var(--pdv-text);
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    margin-bottom: 14px;
}

.back-link {
    color: var(--pdv-text);
    text-decoration: none;
    font-weight: 700;
}

.lookup-card,
.compliance-card,
.viewer-main,
.viewer-sidebar {
    background: var(--pdv-panel);
    border: 1px solid var(--pdv-panel-border);
    border-radius: 18px;
    box-shadow: 0 14px 28px rgba(0, 0, 0, 0.10);
}

.lookup-card {
    padding: 18px 20px;
    margin-bottom: 16px;
}

.compliance-card {
    padding: 18px 20px;
    margin-bottom: 16px;
}

.compliance-card.is-hidden {
    display: none;
}

.compliance-card h2 {
    margin: 0 0 10px;
    font-size: 1.05rem;
}

.compliance-card p {
    margin: 0 0 10px;
    line-height: 1.45;
}

.compliance-card ul {
    margin: 0 0 12px 18px;
    padding: 0;
}

.compliance-card li {
    margin-bottom: 8px;
    line-height: 1.4;
}

.compliance-callout {
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid var(--pdv-panel-border);
    background: var(--pdv-soft);
    margin-bottom: 12px;
}

.compliance-summary {
    cursor: pointer;
    list-style: none;
    font-weight: 700;
    outline: none;
}

.compliance-summary::-webkit-details-marker {
    display: none;
}

.compliance-summary::after {
    content: 'Show More';
    display: inline-block;
    margin-left: 10px;
    font-size: 0.84rem;
    font-weight: 600;
    color: var(--pdv-accent);
}

.compliance-card details[open] .compliance-summary::after {
    content: 'Show Less';
}

.compliance-detail-body {
    margin-top: 12px;
}

.lookup-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-top: 12px;
}

.lookup-row input {
    flex: 1 1 280px;
    min-width: 220px;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid var(--pdv-panel-border);
    background: transparent;
    color: var(--pdv-text);
    font-size: 1rem;
}

.lookup-row button,
.sidebar-btn {
    border: 0;
    border-radius: 12px;
    padding: 11px 15px;
    background: var(--pdv-accent);
    color: #fff;
    font-weight: 700;
    cursor: pointer;
}

.lookup-row button:hover,
.sidebar-btn:hover {
    opacity: 0.94;
}

.status-banner {
    margin-top: 12px;
    padding: 11px 13px;
    border-radius: 12px;
    border: 1px solid var(--pdv-panel-border);
    background: var(--pdv-soft);
    color: var(--pdv-text);
    display: none;
}

.status-banner.is-visible {
    display: block;
}

.status-banner.success {
    border-color: var(--pdv-success);
}

.status-banner.warning {
    border-color: var(--pdv-warning);
}

.status-banner.error {
    border-color: var(--pdv-danger);
}

.viewer-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 210px;
    gap: 16px;
    align-items: start;
}

.viewer-main {
    padding: 20px;
}

.viewer-sidebar {
    position: sticky;
    top: 16px;
    padding: 18px 16px;
}

.device-hero {
    display: grid;
    grid-template-columns: 140px minmax(0, 1fr);
    gap: 18px;
    align-items: start;
}

.device-visual {
    text-align: center;
}

.device-visual img {
    width: 118px;
    border-radius: 16px;
    box-shadow: 0 10px 22px rgba(0, 0, 0, 0.22);
}

.gen-badge {
    display: inline-block;
    margin-top: 8px;
    border-radius: 999px;
    padding: 6px 12px;
    background: var(--pdv-accent);
    color: #fff;
    font-size: 0.82rem;
    font-weight: 700;
}

.device-title h1 {
    margin: 0 0 6px;
    font-size: 1.6rem;
}

.device-subline {
    color: var(--pdv-muted);
    margin-bottom: 10px;
}

.pill-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 7px 11px;
    background: transparent;
    border: 1px solid var(--pdv-panel-border);
    font-size: 0.84rem;
}

.pill.good {
    border-color: var(--pdv-success);
}

.pill.warn {
    border-color: var(--pdv-warning);
}

.pill.bad {
    border-color: var(--pdv-danger);
}

.vitals-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px;
    margin-top: 18px;
}

.vital-box {
    border: 1px solid var(--pdv-panel-border);
    border-radius: 14px;
    padding: 13px 14px;
    background: transparent;
}

.vital-box.tone-green {
    background: rgba(22, 163, 74, 0.22);
    border-color: var(--pdv-success);
}

.vital-box.tone-green-soft {
    background: rgba(22, 163, 74, 0.12);
    border-color: rgba(22, 163, 74, 0.45);
}

.vital-box.tone-yellow {
    background: rgba(217, 119, 6, 0.16);
    border-color: rgba(217, 119, 6, 0.58);
}

.vital-box.tone-orange {
    background: rgba(245, 119, 0, 0.18);
    border-color: rgba(245, 119, 0, 0.68);
}

.vital-box.tone-red {
    background: rgba(220, 38, 38, 0.18);
    border-color: var(--pdv-danger);
}

.vital-box .label {
    color: var(--pdv-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-size: 0.74rem;
    margin-bottom: 5px;
}

.vital-box .value {
    font-size: 1rem;
    font-weight: 700;
}

.section-block {
    margin-top: 18px;
    border-top: 1px solid var(--pdv-panel-border);
    padding-top: 16px;
}

.section-block h2 {
    margin: 0 0 10px;
    font-size: 1rem;
}

.section-block p {
    margin: 0 0 8px;
}

.ip-history {
    display: grid;
    gap: 8px;
}

.ip-entry {
    border: 1px solid var(--pdv-panel-border);
    border-radius: 12px;
    padding: 10px 12px;
    background: transparent;
}

.ip-entry strong {
    display: block;
    margin-bottom: 4px;
}

.sidebar-title {
    margin: 0 0 10px;
    font-size: 1rem;
}

.sidebar-stack {
    display: grid;
    gap: 8px;
}

.sidebar-btn.secondary {
    background: transparent;
    border: 1px solid var(--pdv-panel-border);
    color: var(--pdv-text);
}

.sidebar-btn[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
}

.viewer-empty {
    padding: 26px 20px;
    text-align: center;
    color: var(--pdv-muted);
}

.overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.42);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
}

.overlay.is-visible {
    display: flex;
}

.overlay-card {
    width: min(420px, 90vw);
    padding: 22px 20px;
    border-radius: 18px;
    background: var(--pdv-panel);
    border: 1px solid var(--pdv-panel-border);
    color: var(--pdv-text);
    text-align: center;
}

.corner-logo {
    position: fixed;
    right: 14px;
    bottom: 14px;
    width: 96px;
    z-index: 400;
    opacity: 0.96;
    pointer-events: none;
    filter: drop-shadow(0 8px 12px rgba(0, 0, 0, 0.24));
}

.spinner {
    width: 48px;
    height: 48px;
    margin: 0 auto 12px;
    border-radius: 50%;
    border: 4px solid rgba(255, 255, 255, 0.18);
    border-top-color: var(--pdv-accent);
    animation: spin 1s linear infinite;
}

.muted {
    color: var(--pdv-muted);
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@media (max-width: 960px) {
    .viewer-grid {
        grid-template-columns: 1fr;
    }

    .viewer-sidebar {
        position: static;
    }

    .device-hero {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
<div class="page-shell">
    <div class="topbar">
        <?php if ($isAuthenticated): ?>
            <a class="back-link" href="../index.php">← Musky Main</a>
        <?php else: ?>
            <div></div>
        <?php endif; ?>
        <div class="muted"><?= $isAuthenticated ? 'Authenticated Musky view' : 'Public device view' ?></div>
    </div>

    <section class="lookup-card">
        <?php if ($isAuthenticated): ?>
            <div class="muted">Enter a device serial number to view the latest vitals.</div>
            <form class="lookup-row" method="get" action="">
                <input
                    type="text"
                    name="serial"
                    id="serialInput"
                    value="<?= htmlspecialchars($requestedSerial) ?>"
                    placeholder="Enter Serial Number"
                    autocomplete="off"
                >
                <button type="submit">View Device</button>
            </form>
        <?php else: ?>
            <div class="muted">
                Public device view is link-driven. Open this page with a device serial already in the URL to view vitals.
            </div>
        <?php endif; ?>
        <div class="status-banner" id="statusBanner"></div>
    </section>

    <section class="compliance-card" id="complianceHelpCard">
        <h2>Compliance Help</h2>
        <details id="complianceHelpDetails">
            <summary class="compliance-callout compliance-summary">
                If you were sent here by a compliance ticket, focus first on <strong>Last Check-In</strong>. If that box is not green, the device is not checking in.
            </summary>
            <div class="compliance-detail-body">
                <p>
                    Before doing anything else, check the GSD tag on the back of the iPad and make sure it matches the asset tag shown on this page.
                    If it does match, complete the steps below to restore device check-in.
                </p>
                <ul>
                    <li>Restart the iPad</li>
                    <li>Ensure the iPad is charged to at least 40%</li>
                    <li>Connect the iPad to a Wi-Fi network, especially onsite GSD Wi-Fi when available</li>
                    <li>Open the <strong>Mosyle School</strong> app and leave the device connected for a few minutes</li>
                </ul>
                <p>
                    After completing a step, click <strong>Refresh</strong> to see whether the issue has corrected itself.
                    After a reboot, the iPad may take up to <strong>240 seconds</strong> to check in again.
                </p>
                <p>
                    Compliance does <strong>not</strong> depend on iOS version or current update status.
                    That only matters when the OS version panel turns red.
                    Location services also have no effect on compliance.
                </p>
                <p class="muted">
                    Per GSD Technology Procedures, all student iPads must check in at least once every 7 days.
                    A check-in happens when the iPad is powered on, connected to Wi-Fi, and able to communicate with GSD management services.
                </p>
            </div>
        </details>
    </section>

    <section class="viewer-grid">
        <div class="viewer-main" id="viewerMain">
            <div class="viewer-empty">
                <?= $isAuthenticated
                    ? 'Enter a serial number above to view device details.'
                    : 'Open this page with a device serial in the URL to view device details.' ?>
            </div>
        </div>

        <aside class="viewer-sidebar">
            <h2 class="sidebar-title">Actions</h2>
            <div class="sidebar-stack">
                <?php if ($isAuthenticated): ?>
                    <button type="button" class="sidebar-btn" id="btnReport" onclick="openMakeTicket()" disabled>Report Problem</button>
                    <button type="button" class="sidebar-btn" id="btnSoftReboot" onclick="attemptSoftReboot()" disabled>Soft Reboot</button>
                    <button type="button" class="sidebar-btn" id="btnDeviceReport" onclick="openDeviceReport()" disabled>Device Report</button>
                    <button type="button" class="sidebar-btn secondary" id="btnRefresh" onclick="refreshDevice()" disabled>Refresh</button>
                    <button type="button" class="sidebar-btn secondary" id="btnDebug" onclick="showDebug()" disabled>Debug</button>
                <?php else: ?>
                    <button type="button" class="sidebar-btn" id="btnRefresh" onclick="refreshDevice()" disabled>Refresh</button>
                <?php endif; ?>
            </div>
        </aside>
    </section>
</div>

<img src="../mascot.png" alt="Musky" class="corner-logo">

<div class="overlay" id="workingOverlay" aria-hidden="true">
    <div class="overlay-card">
        <div class="spinner"></div>
        <h2 style="margin:0 0 6px;">Refreshing Device</h2>
        <div class="muted">Musky is asking Nora to run an inventory lookup and refresh the vitals.</div>
    </div>
</div>

<script>
(function() {
    const IS_AUTHENTICATED = <?= $isAuthenticated ? 'true' : 'false' ?>;
    const INITIAL_DEVICE = <?= json_encode($initialDevice, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const viewerMain = document.getElementById('viewerMain');
    const statusBanner = document.getElementById('statusBanner');
    const overlay = document.getElementById('workingOverlay');
    const serialInput = document.getElementById('serialInput');
    const refreshButton = document.getElementById('btnRefresh');
    const complianceHelpCard = document.getElementById('complianceHelpCard');
    const actionButtons = Array.from(document.querySelectorAll('#btnReport, #btnSoftReboot, #btnDeviceReport, #btnDebug'));

    let currentDevice = INITIAL_DEVICE;
    let refreshPollTimer = null;
    let cooldownTimer = null;
    let refreshCooldownRemaining = 0;
    const refreshDefaultLabel = refreshButton ? refreshButton.textContent : 'Refresh';

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function(ch) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[ch];
        });
    }

    function setActionState(deviceFound) {
        const hasRefreshTarget = !!(
            (currentDevice && currentDevice.serial_number) ||
            (serialInput && serialInput.value.trim())
        );

        if (refreshButton && refreshCooldownRemaining <= 0) {
            refreshButton.disabled = !hasRefreshTarget;
        }

        actionButtons.forEach(function(btn) {
            if (btn) btn.disabled = !deviceFound;
        });

        applyRefreshButtonLabel();
    }

    function setBanner(message, tone) {
        if (!statusBanner) return;
        if (!message) {
            statusBanner.className = 'status-banner';
            statusBanner.textContent = '';
            return;
        }
        statusBanner.className = 'status-banner is-visible ' + (tone || 'warning');
        statusBanner.textContent = message;
    }

    function setOverlay(visible) {
        if (!overlay) return;
        overlay.classList.toggle('is-visible', !!visible);
        overlay.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function buildRefreshOutcomeMessage(device, fallbackMessage) {
        if (!device || !device.found) {
            return fallbackMessage || 'Device refreshed.';
        }

        const snapshotAge = device.data_snapshot_ago || 'unknown';
        const snapshotTime = device.data_snapshot || 'unknown';

        if (!device.last_seen) {
            return 'Current snapshot loaded from ' + snapshotAge + ' ago (' + snapshotTime + '). The device still has no network check-in on file.';
        }

        if (device.campus_label === 'Off Campus' || device.campus_label === 'Never Seen') {
            return 'Current snapshot loaded from ' + snapshotAge + ' ago (' + snapshotTime + '), but the device still is not checking in on campus. Last check-in remains ' + (device.last_seen_ago || 'unknown') + '.';
        }

        return 'Current snapshot loaded from ' + snapshotAge + ' ago (' + snapshotTime + '). Last device check-in is ' + (device.last_seen_ago || 'unknown') + '.';
    }

    function stopRefreshPolling() {
        if (refreshPollTimer !== null) {
            window.clearTimeout(refreshPollTimer);
            refreshPollTimer = null;
        }
    }

    function stopRefreshCooldown() {
        if (cooldownTimer !== null) {
            window.clearInterval(cooldownTimer);
            cooldownTimer = null;
        }
    }

    function applyRefreshButtonLabel() {
        if (!refreshButton) return;

        if (refreshCooldownRemaining > 0) {
            refreshButton.textContent = refreshDefaultLabel + ' (' + refreshCooldownRemaining + 's)';
            refreshButton.disabled = true;
            return;
        }

        refreshButton.textContent = refreshDefaultLabel;
    }

    function startRefreshCooldown(seconds) {
        stopRefreshCooldown();
        refreshCooldownRemaining = Math.max(0, Number(seconds) || 0);
        applyRefreshButtonLabel();

        if (refreshCooldownRemaining <= 0) {
            setActionState(!!(currentDevice && currentDevice.found));
            return;
        }

        cooldownTimer = window.setInterval(function() {
            refreshCooldownRemaining -= 1;
            if (refreshCooldownRemaining <= 0) {
                refreshCooldownRemaining = 0;
                stopRefreshCooldown();
                setActionState(!!(currentDevice && currentDevice.found));
            } else {
                applyRefreshButtonLabel();
            }
        }, 1000);
    }

    function issuePills(device) {
        const pills = [];
        pills.push('<span class="pill ' + (device.campus_label === 'On Campus' ? 'good' : (device.campus_label === 'Never Seen' ? 'warn' : 'warn')) + '">' + escapeHtml(device.campus_label) + '</span>');

        (device.health_issues || []).forEach(function(issue) {
            const tone = /lost|full|update/i.test(issue) ? 'bad' : 'warn';
            pills.push('<span class="pill ' + tone + '">' + escapeHtml(issue) + '</span>');
        });

        if (!pills.length) {
            pills.push('<span class="pill good">Healthy</span>');
        }

        return pills.join('');
    }

    function parseMuskyDate(value) {
        if (!value) return null;
        const normalized = String(value).trim().replace(' ', 'T');
        const parsed = Date.parse(normalized);
        return Number.isNaN(parsed) ? null : parsed;
    }

    function getLastCheckinTone(device) {
        const seenMs = parseMuskyDate(device && device.last_seen);
        if (!seenMs) return '';

        const mins = Math.floor((Date.now() - seenMs) / 60000);
        if (mins <= 60) return 'tone-green';
        if (mins <= 720) return 'tone-green-soft';
        if (mins > 2880) return 'tone-red';
        if (mins > 1440) return 'tone-orange';
        if (mins > 720) return 'tone-yellow';
        return '';
    }

    function getOsVersionTone(device) {
        if (!device || !device.update_needed) return '';
        const availableDisk = device.available_disk_value;
        if (typeof availableDisk === 'number' && availableDisk < 8) {
            return 'tone-red';
        }
        return 'tone-orange';
    }

    function buildOsVersionValue(device) {
        const current = device && device.os_version ? String(device.os_version) : 'Unknown';
        if (device && device.update_needed && device.needosupdate) {
            return current + ' → ' + String(device.needosupdate);
        }
        return current;
    }

    function buildVitalBox(label, value, toneClass) {
        const cls = toneClass ? 'vital-box ' + toneClass : 'vital-box';
        return '<div class="' + cls + '"><div class="label">' + escapeHtml(label) + '</div><div class="value">' + value + '</div></div>';
    }

    function renderIpHistory(device) {
        if (!IS_AUTHENTICATED || !Array.isArray(device.ip_history) || !device.ip_history.length) {
            return '';
        }

        return '' +
            '<div class="section-block">' +
                '<h2>Recent IP History</h2>' +
                '<div class="ip-history">' +
                    device.ip_history.map(function(row) {
                        return '' +
                            '<div class="ip-entry">' +
                                '<strong>' + escapeHtml(row.ip) + '</strong>' +
                                '<div>' + escapeHtml(row.campus_label) + ' • ' + escapeHtml(row.time_ago) + '</div>' +
                                '<div class="muted">Last seen ' + escapeHtml(row.last_seen) + ' • ' + escapeHtml(row.hit_count) + ' check-in(s)</div>' +
                            '</div>';
                    }).join('') +
                '</div>' +
            '</div>';
    }

    function syncComplianceHelp(device) {
        if (!complianceHelpCard) return;

        const seenMs = parseMuskyDate(device && device.last_seen);
        if (!device || !device.found || !seenMs) {
            complianceHelpCard.classList.remove('is-hidden');
            return;
        }

        const ageDays = (Date.now() - seenMs) / 86400000;
        complianceHelpCard.classList.toggle('is-hidden', ageDays < 7);
    }

    function renderDevice(device) {
        if (!viewerMain) return;

        if (!device || !device.found) {
            viewerMain.innerHTML = '<div class="viewer-empty">No device record was found for that serial number.</div>';
            setActionState(false);
            syncComplianceHelp(device);
            return;
        }

        const assignedLine = IS_AUTHENTICATED && device.owner_email
            ? escapeHtml(device.owner_display) + ' <span class="muted">(' + escapeHtml(device.owner_email) + ')</span>'
            : escapeHtml(device.owner_display || 'Unassigned');

        const mosyleLink = IS_AUTHENTICATED && device.open_direct_device_link
            ? '<p><a href="' + escapeHtml(device.open_direct_device_link) + '" target="_blank" rel="noopener noreferrer">Open in Mosyle</a></p>'
            : '';

        viewerMain.innerHTML = '' +
            '<div class="device-hero">' +
                '<div class="device-visual">' +
                    '<img src="' + escapeHtml(device.icon_path) + '" alt="Device">' +
                    '<div class="gen-badge">' + escapeHtml(device.generation_label || 'Device') + '</div>' +
                '</div>' +
                '<div class="device-title">' +
                    '<h1>' + escapeHtml(device.serial_number) + '</h1>' +
                    '<div class="device-subline">Asset ' + escapeHtml(device.asset_tag || 'Unknown') + ' • ' + escapeHtml(device.model || device.device_model || 'Unknown Model') + '</div>' +
                    '<div class="pill-row">' + issuePills(device) + '</div>' +
                '</div>' +
            '</div>' +
            '<div class="vitals-grid">' +
                buildVitalBox('Assigned To', assignedLine, '') +
                buildVitalBox('OS Version', escapeHtml(buildOsVersionValue(device)), getOsVersionTone(device)) +
                buildVitalBox('Battery', escapeHtml(device.battery_percent || 'Unknown'), '') +
                buildVitalBox('Disk Used', escapeHtml(device.disk_usage || 'Unknown'), '') +
                buildVitalBox('Available Disk', escapeHtml(device.available_disk || 'Unknown'), '') +
                buildVitalBox('Current Snapshot Age', escapeHtml(device.data_snapshot_ago || 'Unknown'), '') +
                buildVitalBox('Last Nora Lookup', escapeHtml(device.last_nora_data_date_ago || 'Unknown'), '') +
                buildVitalBox('Last Check-In', escapeHtml(device.last_seen_ago || 'Unknown'), getLastCheckinTone(device)) +
            '</div>' +
            '<div class="section-block">' +
                '<h2>Current Status</h2>' +
                '<p><strong>Campus:</strong> ' + escapeHtml(device.campus_label || 'Unknown') + '</p>' +
                '<p class="muted">' + escapeHtml(device.campus_note || '') + '</p>' +
                '<p><strong>Current Snapshot:</strong> ' + escapeHtml(device.data_snapshot || 'Unknown') + '</p>' +
                '<p><strong>Last Nora Lookup:</strong> ' + escapeHtml(device.last_nora_data_date || 'Unknown') + '</p>' +
                (device.last_nora_status ? '<p><strong>Last Nora Status:</strong> ' + escapeHtml(device.last_nora_status) + '</p>' : '') +
                '<p><strong>Lost Mode:</strong> ' + escapeHtml(device.lostmode_status || 'UNKNOWN') + '</p>' +
                '<p><strong>Total Disk:</strong> ' + escapeHtml(device.total_disk || 'Unknown') + '</p>' +
                '<p><strong>Latest Check-In:</strong> ' + escapeHtml(device.last_seen || 'Unknown') + '</p>' +
                (device.tags ? '<p><strong>Tags:</strong> ' + escapeHtml(device.tags) + '</p>' : '') +
                mosyleLink +
            '</div>' +
            renderIpHistory(device);

        setActionState(true);
        syncComplianceHelp(device);
    }

    window.openMakeTicket = function() {
        if (!currentDevice || !currentDevice.found) return;
        window.open('../HelperPages/MuskyMakeATicket_iPad.php?serial=' + encodeURIComponent(currentDevice.serial_number), '_blank', 'width=900,height=800');
    };

    window.attemptSoftReboot = function() {
        if (!currentDevice || !currentDevice.found) return;
        window.open('../HelperPages/Musky_Reboot_iPad.php?serial=' + encodeURIComponent(currentDevice.serial_number), '_blank', 'width=900,height=800');
    };

    window.openDeviceReport = function() {
        if (!currentDevice || !currentDevice.found) return;
        window.open('../DeviceManager/device_report.php?serial=' + encodeURIComponent(currentDevice.serial_number), '_blank', 'width=900,height=800');
    };

    window.showDebug = function() {
        if (!currentDevice || !currentDevice.raw) return;
        const win = window.open('', '_blank');
        if (!win) return;
        const pretty = JSON.stringify(currentDevice.raw, null, 2).replace(/[<>&]/g, function(ch) {
            return ({'<': '&lt;', '>': '&gt;', '&': '&amp;'})[ch];
        });
        win.document.write('<pre style="white-space:pre-wrap;font-family:monospace;">' + pretty + '</pre>');
        win.document.close();
    };

    window.refreshDevice = async function() {
        const serial = (currentDevice && currentDevice.serial_number) || (serialInput ? serialInput.value.trim() : '');
        if (!serial) {
            setBanner('Enter a serial number first.', 'error');
            return;
        }

        stopRefreshPolling();
        setOverlay(true);
        setBanner('Submitting INV_LOOKUP to Nora...', 'warning');

        try {
            const resp = await fetch('DeviceVitals_INVLookup.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ serial, csrf_token: CSRF_TOKEN })
            });

            const data = await resp.json();
            if (!resp.ok || !data.success) {
                if (data.cooldown_remaining) {
                    startRefreshCooldown(data.cooldown_remaining);
                }
                throw new Error(data.detail || data.error || 'Refresh failed.');
            }

            if (!data.errand_id) {
                throw new Error('INV_LOOKUP did not return an errand id.');
            }

            startRefreshCooldown(data.cooldown_seconds || 25);
            setBanner('INV_LOOKUP submitted. Waiting for Nora to finish.', 'warning');
            pollRefreshStatus(serial, data.errand_id);
        } catch (err) {
            setBanner('Refresh failed: ' + err.message, 'error');
            setOverlay(false);
        }
    };

    async function pollRefreshStatus(serial, errandId) {
        try {
            const url = new URL('DeviceVitals_INVLookupStatus.php', window.location.href);
            url.searchParams.set('id', String(errandId));
            url.searchParams.set('serial', serial);

            const resp = await fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-CSRF-Token': CSRF_TOKEN
                }
            });

            const data = await resp.json();
            if (!resp.ok || data.ok === false) {
                throw new Error(data.message || 'Status poll failed.');
            }

            const status = String(data.status || '').toLowerCase();

            if (status === 'complete') {
                await loadDeviceSnapshot(serial);
                setBanner(buildRefreshOutcomeMessage(currentDevice, data.message || 'Device refreshed.'), 'success');
                setOverlay(false);
                stopRefreshPolling();
                return;
            }

            if (status === 'failed' || status === 'cancelled' || status === 'rejected') {
                setBanner(data.message || 'INV_LOOKUP failed.', 'error');
                setOverlay(false);
                stopRefreshPolling();
                return;
            }

            setBanner(data.message || 'Still waiting on Nora...', 'warning');
            refreshPollTimer = window.setTimeout(function() {
                pollRefreshStatus(serial, errandId);
            }, 2200);
        } catch (err) {
            setBanner('Refresh status failed: ' + err.message, 'error');
            setOverlay(false);
            stopRefreshPolling();
        }
    }

    async function loadDeviceSnapshot(serial) {
        const url = new URL(window.location.href);
        url.searchParams.set('api', 'device_snapshot');
        url.searchParams.set('serial', serial);

        const resp = await fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store'
        });

        const data = await resp.json();
        if (!resp.ok || !data.ok) {
            throw new Error(data.message || 'Could not reload device data.');
        }

        currentDevice = data.device || currentDevice;
        renderDevice(currentDevice);
    }

    if (serialInput) {
        serialInput.addEventListener('input', function() {
            setActionState(!!(currentDevice && currentDevice.found));
        });
    }

    renderDevice(currentDevice);
})();
</script>
</body>
</html>
