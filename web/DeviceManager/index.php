<?php
if (isset($_GET['api'])) {
    ini_set('display_errors', '0');
    error_reporting(0);
    register_shutdown_function(function (): void {
        $fatal = error_get_last();
        if (!$fatal) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int)($fatal['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        $msg = trim((string)($fatal['message'] ?? 'Fatal error'));
        echo json_encode([
            'ok' => 0,
            'error' => 'Fatal API error: ' . ($msg !== '' ? $msg : 'Unknown fatal'),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    });
}

// ----------------------------------------------------------------------
// Safe Session Start
// ----------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/Musky_API_Helper.php';
require_once __DIR__ . '/../../Functions/MuskyConfig.php';
if (!defined('MUSKY_SIDEBAR_ACTIONS_ONLY')) {
    define('MUSKY_SIDEBAR_ACTIONS_ONLY', true);
}
require_once __DIR__ . '/sidebar.php';

$tool_required = 'DEVICE_MANAGER';
$allowed = explode(',', $_SESSION['musky_user']['allowed_tools'] ?? []);
if (!in_array($tool_required, $allowed) && !in_array('ALL_TOOLS', $allowed)) {
    http_response_code(403);
    echo "⛔ Access Denied — Missing Required Tool: {$tool_required}";
    exit;
}

// -------------------------------
// 1. Theme Loader (shared prefs helper)
// -------------------------------
$prefs = musky_get_logged_in_user_prefs();
$themeRaw = trim((string)($prefs['theme'] ?? 'musky-mode'));
$themeMap = [
    'light' => 'light-mode',
    'light-mode' => 'light-mode',
    'dark' => 'dark-mode',
    'dark-mode' => 'dark-mode',
    'musky' => 'musky-mode',
    'musky-mode' => 'musky-mode',
    'gator' => 'gator-time-mode',
    'gator-time' => 'gator-time-mode',
    'gator-time-mode' => 'gator-time-mode',
];
$theme = $themeMap[strtolower($themeRaw)] ?? 'musky-mode';

// Enable detailed PHP error reporting for normal page loads only.
if (!isset($_GET['api'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// ===================
// Setup Variables
// ===================
$showSidebar = false;
$parsedData = [];
$deviceStolen = false;
$rawInput = '';
$debugOutput = '';
$mainOutput = '';
$lastCommand = '';
$minutesSinceLastCheckIn = 0;
$lookupRow = null;
$locationLinkToOpen = '';

if (dm_is_action_post_request()) {
    musky_csrf_require('device_manager_actions');
}

// ===================
// Setup Logging Function
// ===================
// Logs actions and events with timestamp, IP, and username
function write_log($message) {
    global $LOG_PATH; // Get log path from config
    $logfile = rtrim($LOG_PATH, '/') . '/device_manager_log.txt';
    $timestamp = date('[Y-m-d H:i:s]');
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP';
    $authUser = $_SERVER['REMOTE_USER'] ?? 'UNKNOWN_USER';
    $fullMessage = "{$timestamp} IP: {$ipAddress} User: {$authUser} | {$message}" . PHP_EOL;
    file_put_contents($logfile, $fullMessage, FILE_APPEND | LOCK_EX);
    musky_activity_log([
        'event_type' => 'ACTION',
        'action_name' => 'DEVICE_MANAGER_EVENT',
        'page_path' => '/DeviceManager/index.php',
        'extra' => ['message' => $message],
    ]);
}

function dm_activity_action(string $actionName, array $context = []): void
{
    $payload = [
        'event_type' => 'ACTION',
        'action_name' => $actionName,
        'page_path' => '/DeviceManager/index.php',
    ];

    if (!empty($context['target_serials'])) {
        $payload['target_serials'] = (array)$context['target_serials'];
    }
    if (!empty($context['target_asset_tags'])) {
        $payload['target_asset_tags'] = (array)$context['target_asset_tags'];
    }

    $extra = $context;
    unset($extra['target_serials'], $extra['target_asset_tags']);
    if (!empty($extra)) {
        $payload['extra'] = $extra;
    }

    musky_activity_log($payload);
}

function dm_sidebar_action_prefixes(): array
{
    if (function_exists('musky_sidebar_action_url_prefixes')) {
        $shared = musky_sidebar_action_url_prefixes();
        if (is_array($shared)) {
            return $shared;
        }
    }

    return [
        'ticket'       => '../HelperPages/MuskyMakeATicket_iPad.php?serial=',
        'soft_reboot'  => '../HelperPages/Musky_Reboot_iPad.php?serial=',
        'power_reboot' => '../HelperPages/Musky_Reboot_iPad.php?serial=',
        'power_wash'   => '../HelperPages/Musky_Wipe_iPad.php?serial=',
        'device_report'=> 'device_report.php?serial=',
    ];
}

function dm_sidebar_popup_specs(): array
{
    if (function_exists('musky_sidebar_action_popup_specs')) {
        $shared = musky_sidebar_action_popup_specs();
        if (is_array($shared)) {
            return $shared;
        }
    }

    return [
        'helper' => 'width=900,height=800',
        'report' => 'width=900,height=800',
        'device_report' => 'width=900,height=800',
    ];
}

function dm_sidebar_window_open_script(string $urlPrefix, string $serial, string $popupKey = 'helper'): string
{
    $prefix = trim($urlPrefix);
    $serial = trim($serial);
    if ($prefix === '' || $serial === '') {
        return '';
    }

    $popupSpecs = dm_sidebar_popup_specs();
    $specs = (string)($popupSpecs[$popupKey] ?? $popupSpecs['helper'] ?? 'width=900,height=800');
    $url = $prefix . rawurlencode($serial);

    return "<script>window.open(" . json_encode($url) . ", '_blank', " . json_encode($specs) . ");</script>";
}

function dm_is_action_post_request(): bool
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }

    foreach ([
        'button1',
        'lostmodeoff',
        'lostmodeon',
        'assign_button',
        'restart_button',
        'play_sound',
        'show_location',
        'report_problem_button',
        'power_wash_button',
    ] as $key) {
        if (isset($_POST[$key])) {
            return true;
        }
    }

    return false;
}

function dm_extract_extra_value($extra, array $keys): string {
    if (!is_array($extra)) {
        return '';
    }

    foreach ($keys as $key) {
        if (!array_key_exists($key, $extra)) {
            continue;
        }

        $value = trim((string)$extra[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function dm_extract_location_url($extra): string {
    if (!is_array($extra)) {
        return '';
    }

    $lat = dm_extract_extra_value($extra, ['latitude', 'Latitude']);
    $lng = dm_extract_extra_value($extra, ['longitude', 'Longitude']);

    if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
        return 'https://maps.google.com/?q=' . rawurlencode($lat . ',' . $lng);
    }

    return '';
}

function dm_try_nora_pdo(): ?PDO {
    if (function_exists('musky_config_pdo')) {
        $pdo = musky_config_pdo();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    if (isset($GLOBALS['NORA_PDO']) && $GLOBALS['NORA_PDO'] instanceof PDO) {
        return $GLOBALS['NORA_PDO'];
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    return null;
}

function dm_lookup_device_nora(PDO $pdo, string $term): ?array {
    $term = strtoupper(trim($term));
    if ($term === '') {
        return null;
    }

    $latestHistoryJoin = "
        LEFT JOIN (
            SELECT dh1.*
            FROM device_history dh1
            INNER JOIN (
                SELECT serial_number, MAX(snapshot_time) AS snapshot_time
                FROM device_history
                GROUP BY serial_number
            ) latest
              ON latest.serial_number = dh1.serial_number
             AND latest.snapshot_time = dh1.snapshot_time
        ) h
          ON h.serial_number = d.serial_number
    ";

    $deviceSql = "
        SELECT
            d.serial_number,
            d.deviceudid,
            d.asset_tag,
            d.model,
            d.device_model,
            d.os_version              AS device_os_version,
            d.enrollment_type,
            d.last_seen               AS device_last_seen,
            d.open_direct_device_link AS device_open_direct_device_link,
            d.owner_id,
            o.email                   AS owner_email,
            o.full_name               AS owner_name,
            o.mosyle_id               AS owner_mosyle_id,

            h.asset_tag               AS hist_asset_tag,
            h.last_seen,
            h.snapshot_time,
            h.tags,
            h.lostmode_status,
            h.last_ip_beat,
            h.needosupdate,
            h.os_version              AS hist_os_version,
            h.owner_userid,
            h.owner_name              AS hist_owner_name,
            h.owner_email             AS hist_owner_email,
            ''                       AS hist_open_direct_device_link,
            h.extra_data
        FROM devices d
        LEFT JOIN owners o
          ON o.id = d.owner_id
        {$latestHistoryJoin}
        WHERE UPPER(COALESCE(NULLIF(d.asset_tag, ''), NULLIF(h.asset_tag, ''), '')) = :term1
           OR UPPER(COALESCE(h.asset_tag, '')) = :term2
        ORDER BY
            CASE
                WHEN UPPER(COALESCE(NULLIF(d.asset_tag, ''), NULLIF(h.asset_tag, ''), '')) = :term3 THEN 0
                WHEN UPPER(COALESCE(h.asset_tag, '')) = :term4 THEN 1
                ELSE 2
            END,
            COALESCE(h.last_seen, d.last_seen) DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($deviceSql);
    $stmt->execute([
        ':term1' => $term,
        ':term2' => $term,
        ':term3' => $term,
        ':term4' => $term,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $historySql = "
            SELECT
                h.serial_number,
                d.deviceudid,
                COALESCE(NULLIF(d.asset_tag, ''), NULLIF(h.asset_tag, '')) AS asset_tag,
                d.model,
                d.device_model,
                d.os_version              AS device_os_version,
                d.enrollment_type,
                d.last_seen               AS device_last_seen,
                d.open_direct_device_link AS device_open_direct_device_link,
                d.owner_id,
                o.email                   AS owner_email,
                o.full_name               AS owner_name,
                o.mosyle_id               AS owner_mosyle_id,

                h.asset_tag               AS hist_asset_tag,
                h.last_seen,
                h.snapshot_time,
                h.tags,
                h.lostmode_status,
                h.last_ip_beat,
                h.needosupdate,
                h.os_version              AS hist_os_version,
                h.owner_userid,
                h.owner_name              AS hist_owner_name,
                h.owner_email             AS hist_owner_email,
                ''                       AS hist_open_direct_device_link,
                h.extra_data
            FROM (
                SELECT dh1.*
                FROM device_history dh1
                INNER JOIN (
                    SELECT serial_number, MAX(snapshot_time) AS snapshot_time
                    FROM device_history
                    GROUP BY serial_number
                ) latest
                  ON latest.serial_number = dh1.serial_number
                 AND latest.snapshot_time = dh1.snapshot_time
            ) h
            LEFT JOIN devices d
              ON d.serial_number = h.serial_number
            LEFT JOIN owners o
              ON o.id = d.owner_id
            WHERE UPPER(COALESCE(NULLIF(h.asset_tag, ''), '')) = :termh1
            ORDER BY h.last_seen DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($historySql);
        $stmt->execute([
            ':termh1' => $term,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return null;
    }

    $extra = [];
    if (!empty($row['extra_data'])) {
        $decoded = json_decode((string)$row['extra_data'], true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }

    $row['extra_data_parsed'] = $extra;
    $row['asset_tag_display'] = trim((string)($row['asset_tag'] ?? $row['hist_asset_tag'] ?? ''));
    $row['effective_os_version'] = trim((string)($row['hist_os_version'] ?? $row['device_os_version'] ?? ''));
    $row['effective_owner_email'] = trim((string)($row['owner_email'] ?? $row['hist_owner_email'] ?? ''));
    $row['effective_owner_name'] = trim((string)($row['owner_name'] ?? $row['hist_owner_name'] ?? ''));
    $row['effective_open_link'] = trim((string)($row['device_open_direct_device_link'] ?? $row['hist_open_direct_device_link'] ?? ''));
    $row['location_url'] = dm_extract_location_url($extra);
    $row['device_name'] = dm_extract_extra_value($extra, ['device_name', 'DeviceName', 'name']);

    return $row;
}

function dm_build_legacy_lookup(array $row): array {
    $serial = trim((string)($row['serial_number'] ?? ''));
    $ownerEmail = trim((string)($row['effective_owner_email'] ?? ''));
    $ownerName = trim((string)($row['effective_owner_name'] ?? ''));
    $ownerUserid = trim((string)($row['owner_userid'] ?? ''));

    if ($ownerUserid === '' && $ownerEmail !== '') {
        $ownerUserid = strtolower(strtok($ownerEmail, '@') ?: '');
    }
    if ($ownerUserid === '') {
        $ownerUserid = 'UNASSIGNED';
    }

    $lastSeen = trim((string)($row['last_seen'] ?? $row['device_last_seen'] ?? ''));
    $lastSeenTs = $lastSeen !== '' ? strtotime($lastSeen) : false;
    $assetTag = trim((string)($row['asset_tag_display'] ?? ''));

    return [
        'ASSETTAG'                => $assetTag,
        'DeviceSerialNumber'      => $serial,
        'DeviceSerial'            => $serial,
        'UDID'                    => trim((string)($row['deviceudid'] ?? '')),
        'USERID'                  => $ownerUserid,
        'OWNERNAME'               => $ownerName,
        'OWNEREMAIL'              => $ownerEmail,
        'TAGS'                    => trim((string)($row['tags'] ?? '')),
        'LOSTMODESTATUS'          => trim((string)($row['lostmode_status'] ?? 'UNKNOWN')),
        'LAST_IP_BEAT'            => trim((string)($row['last_ip_beat'] ?? '')),
        'NEEDSOSUPDATE'           => trim((string)($row['needosupdate'] ?? '')),
        'LASTCHECKIN'             => $lastSeen,
        'LASTCHECKINRAW'          => $lastSeenTs ? (string)$lastSeenTs : '',
        'OSVERSION'               => trim((string)($row['effective_os_version'] ?? '')),
        'device_name'             => trim((string)($row['device_name'] ?? '')),
        'open_direct_device_link' => trim((string)($row['effective_open_link'] ?? '')),
        'OWNER_MOSYLE_ID'         => trim((string)($row['owner_mosyle_id'] ?? '')),
        'ENROLLMENT_TYPE'         => trim((string)($row['enrollment_type'] ?? '')),
        'LOCATION_URL'            => trim((string)($row['location_url'] ?? '')),
    ];
}

function dm_build_parsed_lines(array $parsedData): array {
    $lines = [];
    foreach ($parsedData as $key => $value) {
        if (is_array($value) || is_object($value)) {
            continue;
        }
        $lines[] = $key . '=' . (string)$value;
    }
    return $lines;
}

function dm_queue_nora_errand(string $action, array $deviceRow, string $submitter): array {
    if (!function_exists('musky_nora_api_post_json')) {
        return ['ok' => false, 'error' => 'Nora API helper is unavailable.'];
    }

    $serial = trim((string)($deviceRow['serial_number'] ?? ''));
    $udid = trim((string)($deviceRow['deviceudid'] ?? ''));
    $ownerEmail = trim((string)($deviceRow['effective_owner_email'] ?? ''));
    $ownerMosyleId = trim((string)($deviceRow['owner_mosyle_id'] ?? ''));
    $payload = null;

    if ($action === 'lostmodeon') {
        $payload = [
            'serial'    => $serial,
            'udid'      => $udid !== '' ? $udid : 'UNKNOWN',
            'submitter' => $submitter,
            'owner'     => $ownerEmail,
            'mosbasic'  => 'LOSTMODEON',
            'priority'  => 5,
        ];
    } elseif ($action === 'lostmodeoff') {
        $payload = [
            'serial'    => $serial,
            'udid'      => $udid !== '' ? $udid : 'UNKNOWN',
            'submitter' => $submitter,
            'owner'     => $ownerEmail,
            'mosbasic'  => 'LOSTMODEOFF',
            'priority'  => 5,
        ];
    } elseif ($action === 'play_sound') {
        $payload = [
            'serial'    => $serial,
            'udid'      => $udid !== '' ? $udid : 'UNKNOWN',
            'submitter' => $submitter,
            'owner'     => $ownerEmail,
            'mosbasic'  => 'LOSTSOUND',
            'priority'  => 5,
        ];
    } elseif ($action === 'assign_button') {
        if ($ownerMosyleId === '') {
            return ['ok' => false, 'error' => 'Missing owner Mosyle ID for ASSIGNME.'];
        }
        $payload = [
            'serial'    => $serial,
            'udid'      => $udid !== '' ? $udid : 'UNKNOWN',
            'submitter' => $submitter,
            'owner'     => $ownerEmail,
            'mosbasic'  => 'ASSIGNME',
            'priority'  => 5,
            'extra1'    => json_encode([
                'id' => $ownerMosyleId,
                'serial_number' => $serial,
            ], JSON_UNESCAPED_SLASHES),
        ];
    }

    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Unsupported action request.'];
    }

    $resp = musky_nora_api_post_json('/errand/create', $payload);
    if (!is_array($resp)) {
        return ['ok' => false, 'error' => 'No JSON response from Nora API.', 'payload' => $payload];
    }

    if (function_exists('musky_nora_last_response_meta')) {
        $meta = musky_nora_last_response_meta();
        $httpCode = (int)($meta['http_code'] ?? 0);
        if ($httpCode >= 400) {
            $message = trim((string)($resp['message'] ?? $resp['error'] ?? 'Nora API request failed.'));
            return [
                'ok' => false,
                'error' => $message,
                'payload' => $payload,
                'raw' => $resp,
                'http_code' => $httpCode,
            ];
        }
    }

    $errandId = $resp['errand_id'] ?? $resp['ErrandID'] ?? null;
    if (!$errandId) {
        return ['ok' => false, 'error' => 'Nora did not return an errand id.', 'payload' => $payload, 'raw' => $resp];
    }

    return [
        'ok' => true,
        'errand_id' => (int)$errandId,
        'payload' => $payload,
        'raw' => $resp,
    ];
}

function dm_lookup_state_from_term(string $rawInput): array {
    $parsedData = [];
    $showSidebar = false;
    $mainOutput = '';
    $lookupRow = null;

    $lookupTerm = dm_normalize_lookup_term($rawInput);
    if ($lookupTerm === '') {
        return [
            'parsedData' => $parsedData,
            'showSidebar' => $showSidebar,
            'mainOutput' => $mainOutput,
            'lookupRow' => $lookupRow,
        ];
    }

    $noraPdo = dm_try_nora_pdo();
    if ($noraPdo instanceof PDO) {
        $lookupRow = dm_lookup_device_nora($noraPdo, $lookupTerm);
    }

    if ($lookupRow) {
        $parsedData = dm_build_legacy_lookup($lookupRow);
        $lines = dm_build_parsed_lines($parsedData);
        $_SESSION["parsed_lines"] = $lines;
        $mainOutput = implode("\n", $lines);
        $showSidebar = !empty($parsedData['ASSETTAG']) || !empty($parsedData['DeviceSerialNumber']);
    } else {
        $mainOutput = "No NORA device record matched lookup {$lookupTerm}.";
    }

    return [
        'parsedData' => $parsedData,
        'showSidebar' => $showSidebar,
        'mainOutput' => (string)$mainOutput,
        'lookupRow' => $lookupRow,
    ];
}

function dm_render_device_area(array $parsedData, bool $showSidebar, string $rawInput): array {
    $deviceStolen = false;
    $minutesSinceLastCheckIn = 0;

    ob_start();

    if (!empty($parsedData)) {
        echo "<h2>" . htmlspecialchars($parsedData['ASSETTAG'] ?? 'Unknown Asset') . "</h2>";

        if (!empty($parsedData['USERID'])) {
            echo "<p>Username: " . (strtoupper($parsedData['USERID']) === 'UNASSIGNED' ? "&lt;&lt;DEVICE UNASSIGNED&gt;&gt;" : htmlspecialchars($parsedData['USERID'])) . "</p>";
        }

        if (!empty($parsedData['OWNERNAME']) || !empty($parsedData['OWNEREMAIL'])) {
            $ownerName = trim((string)($parsedData['OWNERNAME'] ?? ''));
            if ($ownerName === '') {
                $ownerName = 'Unknown';
            }
            $ownerNameEsc = htmlspecialchars($ownerName);
            $ownerText = $ownerNameEsc;
            if (!empty($parsedData['OWNEREMAIL'])) {
                $ownerText .= ' (' . htmlspecialchars((string)$parsedData['OWNEREMAIL']) . ')';
            }
            echo "<p>Owner: {$ownerText}</p>";
        }

        if (!empty($parsedData['device_name'])) {
            echo "<p>Device Name: " . htmlspecialchars((string)$parsedData['device_name']) . "</p>";
        }

        if (!empty($parsedData['LAST_IP_BEAT'])) {
            echo "<p>Last IP Address: " . ($parsedData['LAST_IP_BEAT'] === '65.254.21.222' ? "On Campus" : "Off Site") . "</p>";
        }

        if (!empty($parsedData['NEEDSOSUPDATE'])) {
            echo "<div class='attention attention-update'>UPDATE REQUIRED: " . htmlspecialchars($parsedData['NEEDSOSUPDATE']) . "</div>";
        } else {
            echo "<p>No Updates Needed.</p>";
        }

        if (!empty($parsedData['LASTCHECKINRAW'])) {
            $minutesSinceLastCheckIn = floor((time() - (int)$parsedData['LASTCHECKINRAW']) / 60);
            if ($minutesSinceLastCheckIn >= 2880) {
                echo "<div class='attention attention-stale'>Device Last Check In: " . floor($minutesSinceLastCheckIn / 60) . " hours ago</div>";
            } elseif ($minutesSinceLastCheckIn >= 60) {
                echo "<p>Device Last Check In: " . floor($minutesSinceLastCheckIn / 60) . " hours ago</p>";
            } else {
                echo "<p>Device Last Check In: " . $minutesSinceLastCheckIn . " minutes ago</p>";
            }
        }

        $tags = explode(',', $parsedData['TAGS'] ?? '');
        $tags = array_map('trim', array_map('strtoupper', $tags));
        if (in_array('STOLEN', $tags, true)) {
            $deviceStolen = true;
        }

        include __DIR__ . '/decode_tags.php';

        echo "<hr><h3>🛠️ 3rd Party Modules</h3>";
        $modFiles = glob(__DIR__ . "/Modules/*.php");
        if (!empty($modFiles)) {
            echo "<ul>";
            foreach ($modFiles as $modPath) {
                $modName = basename($modPath);
                echo "<li><a href='Modules/$modName' target='_blank'>$modName</a></li>";
            }
            echo "</ul>";
        } else {
            echo "<p>No modules found in /Modules.</p>";
        }
    }

    $mainHtml = (string)ob_get_clean();

    ob_start();

    if ($showSidebar) {
        $lostModeStatus = strtoupper(trim($parsedData['LOSTMODESTATUS'] ?? ''));
        ?>
        <!-- Sidebar with action buttons -->
        <div class="sidebar">
        <?php if ($deviceStolen): ?>
        <style>
        .sidebar .action-button {
          background-color: black !important;
          color: white !important;
        }
        </style>
        <?php endif; ?>
        <h3>Actions</h3>

        <!-- Action Form -->
        <form method="post">
          <input type="hidden" name="previous_input" value="<?php echo htmlspecialchars($rawInput); ?>">
          <input type="hidden" name="device_serial" value="<?php echo htmlspecialchars($parsedData['DeviceSerialNumber'] ?? ''); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(musky_csrf_token('device_manager_actions')); ?>">

          <?php if (!$deviceStolen): ?>
          <!-- Report Problem -->
          <button class="action-button" name="report_problem_button">
            Report Problem with this Device
          </button>

        <!-- Wipe Device -->
          <button class="action-button" name="button1" onclick="return confirm('Are you sure you want to Wipe the device?');">
            Wipe Device
          </button>

          <!-- Lost Mode Controls -->
          <?php
          if ($minutesSinceLastCheckIn >= 2880) {
              echo '<button class="action-button orange-button" disabled>Lost Mode (Unavailable)</button>';
          } else {
              if ($lostModeStatus === 'ENABLED') {
                  echo '<button class="action-button" name="lostmodeoff">Disable Lost Mode</button>';
              } elseif ($lostModeStatus === 'DISABLED') {
                  echo '<button class="action-button" name="lostmodeon">Enable Lost Mode</button>';
              } else {
                  echo '<button class="action-button orange-button" disabled>Lost Mode Jammed</button>';
              }
          }
          ?>

          <!-- Assign Device if Unassigned -->
          <?php if (!empty($parsedData['USERID']) && strtoupper($parsedData['USERID']) === 'UNASSIGNED'): ?>
            <button class="action-button" name="assign_button" onclick="return confirm('Assign this device?');">
              Assign Device
            </button>
          <?php endif; ?>

          <!-- Restart Device -->
          <?php
          if ($lostModeStatus === 'ENABLED') {
              echo '<button class="action-button orange-button" disabled>Restart Device (Unavailable)</button>';
          } else {
              echo '<button class="action-button" name="restart_button" onclick="return confirm(\'Are you sure you want to restart this device?\');">
              Restart Device
              </button>';
          }
          ?>

          <!-- Power Wash & Wax -->
          <button class="action-button" name="power_wash_button" onclick="return confirm('Are you sure you want to Power Wash &amp; Wax this device?');">
            Power Wash &amp; Wax
          </button>

          <!-- Device Report Button -->
          <?php
          if (is_readable(dirname(__DIR__, 2) . '/nora_config.json')) {
              $serial = htmlspecialchars($parsedData['DeviceSerialNumber'] ?? '');
              echo '<button class="action-button" type="button" onclick="window.open(\'device_report.php?serial=' . $serial . '\', \'DeviceReport\', \'width=1000,height=800,resizable=yes,scrollbars=yes\');">Device Report</button>';
          }
          ?>

          <!-- Play Sound and Show Location -->
          <?php
          if ($lostModeStatus === 'ENABLED' && $minutesSinceLastCheckIn < 2880) {
              echo '<button class="action-button" name="play_sound">Play Sound</button>';
              echo '<button class="action-button" name="show_location">Show Location</button>';
          }
          ?>

          <?php endif; ?>
        <!-- Refresh Lookup -->
          <button class="action-button" type="button" onclick="forceRefresh()">
            Look Up Again
          </button>

          <!-- Toggle Debug Output -->
          <button class="action-button" type="button" onclick="handleDebugClick()">
            DEBUG
          </button>
        </form>
        <?php if ($deviceStolen): ?>
          <div class="urgent-alert">CONTACT MR. SMILLIE ASAP!!!!</div>
        <?php endif; ?>
        </div> <!-- end sidebar -->
        <?php
    }

    return [
        'main_html' => $mainHtml,
        'sidebar_html' => (string)ob_get_clean(),
    ];
}

function dm_json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        if ($status < 400) {
            http_response_code(500);
        }
        $json = '{"ok":0,"error":"JSON encoding failed."}';
    }
    echo $json;
    exit;
}

function dm_nora_api_post_json_raw(string $path, array $payload): array
{
    if (!function_exists('musky_nora_api_post_json')) {
        return ['ok' => false, 'error' => 'Nora API helper is unavailable.'];
    }

    $normalizedPath = '/' . ltrim($path, '/');
    $resp = musky_nora_api_post_json($normalizedPath, $payload, 30);
    $meta = function_exists('musky_nora_last_response_meta')
        ? musky_nora_last_response_meta()
        : [];
    $httpCode = (int)($meta['http_code'] ?? 0);
    if (!is_array($resp)) {
        return [
            'ok' => false,
            'error' => 'No JSON response from Nora API.',
            'http_code' => $httpCode ?: null,
        ];
    }

    if ($httpCode >= 400) {
        $message = trim((string)($resp['message'] ?? ''));
        $error = trim((string)($resp['error'] ?? ''));
        if ($message === '' && $error !== '') {
            $message = $error;
        }
        if ($message === '') {
            $message = 'Nora API request failed.';
        }

        return [
            'ok' => false,
            'error' => $message,
            'http_code' => $httpCode,
            'raw' => $resp,
        ];
    }

    return ['ok' => true, 'http_code' => $httpCode ?: 200, 'data' => $resp];
}

function dm_nora_api_get_json_raw(string $path): array
{
    if (!function_exists('musky_nora_api_get_json')) {
        return ['ok' => false, 'error' => 'Nora API helper is unavailable.'];
    }

    $normalizedPath = '/' . ltrim($path, '/');
    $resp = musky_nora_api_get_json($normalizedPath, [], 20);
    $meta = function_exists('musky_nora_last_response_meta')
        ? musky_nora_last_response_meta()
        : [];
    $httpCode = (int)($meta['http_code'] ?? 0);
    if (!is_array($resp)) {
        return [
            'ok' => false,
            'error' => 'No JSON response from Nora API.',
            'http_code' => $httpCode ?: null,
        ];
    }

    if ($httpCode >= 400) {
        $message = trim((string)($resp['message'] ?? ''));
        $error = trim((string)($resp['error'] ?? ''));
        if ($message === '' && $error !== '') {
            $message = $error;
        }
        if ($message === '') {
            $message = 'Nora API request failed.';
        }

        return [
            'ok' => false,
            'error' => $message,
            'http_code' => $httpCode,
            'raw' => $resp,
        ];
    }

    return ['ok' => true, 'http_code' => $httpCode ?: 200, 'data' => $resp];
}

function dm_normalize_asset_tag(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{5}$/', $value) ? $value : '';
}

function dm_normalize_lookup_term(string $value): string
{
    return trim($value);
}

function dm_resolve_serial_for_lookup(string $lookup): string
{
    $lookup = dm_normalize_lookup_term($lookup);
    if ($lookup === '') {
        return '';
    }

    // Prefer Nora DB lookup for speed and to avoid shell fallbacks here.
    $pdo = dm_try_nora_pdo();
    if ($pdo instanceof PDO) {
        $row = dm_lookup_device_nora($pdo, $lookup);
        $serial = strtoupper(trim((string)($row['serial_number'] ?? '')));
        if ($serial !== '') {
            return $serial;
        }
    }

    return '';
}

function dm_normalize_inv_status(array $resp): array
{
    $statusRaw = strtoupper(trim((string)($resp['Status'] ?? $resp['status'] ?? 'UNKNOWN')));
    $message = trim((string)($resp['ExtraDataField04'] ?? $resp['message'] ?? ''));

    if ($statusRaw === 'COMPLETE') {
        return ['status' => 'complete', 'status_raw' => $statusRaw, 'message' => ($message !== '' ? $message : 'INV_LOOKUP complete.')];
    }
    if (in_array($statusRaw, ['FAILED', 'CANCELLED', 'REJECTED'], true)) {
        return ['status' => 'failed', 'status_raw' => $statusRaw, 'message' => ($message !== '' ? $message : ('INV_LOOKUP failed with status ' . $statusRaw))];
    }

    return ['status' => 'running', 'status_raw' => $statusRaw, 'message' => ($message !== '' ? $message : ('INV_LOOKUP status: ' . $statusRaw))];
}

if (isset($_GET['api'])) {
    // Keep API responses clean JSON for JS clients (Safari is especially strict).
    ini_set('display_errors', '0');
    error_reporting(0);

    $api = trim((string)$_GET['api']);
    $submitter = trim((string)($prefs['email'] ?? ($_SESSION['musky_user']['email'] ?? 'DEVICE_MANAGER')));
    if ($submitter === '') {
        $submitter = 'DEVICE_MANAGER';
    }

    if ($api === 'inv_lookup_start') {
        $lookup = '';
        try {
            $body = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($body)) {
                $body = [];
            }
            $csrfToken = $body['csrf_token'] ?? null;
            if (!is_string($csrfToken) || !musky_csrf_is_valid('device_manager_inv_lookup', $csrfToken)) {
                dm_json_response(['ok' => 0, 'error' => 'Invalid or missing CSRF token.'], 403);
            }
            $lookup = trim((string)($body['lookup'] ?? ($_GET['lookup'] ?? '')));
            if ($lookup === '') {
                dm_activity_action('INV_LOOKUP_START_BAD_REQUEST', [
                    'api' => 'inv_lookup_start',
                    'error' => 'Missing lookup term.',
                ]);
                dm_json_response(['ok' => 0, 'error' => 'Missing lookup term.'], 400);
            }

            $lookupTerm = dm_normalize_lookup_term($lookup);
            if ($lookupTerm === '') {
                dm_activity_action('INV_LOOKUP_START_BAD_REQUEST', [
                    'api' => 'inv_lookup_start',
                    'lookup' => $lookup,
                    'error' => 'Enter an asset tag or exact Nora lookup value.',
                ]);
                dm_json_response(['ok' => 0, 'error' => 'Enter an asset tag or exact Nora lookup value.'], 400);
            }

            $serial = dm_resolve_serial_for_lookup($lookupTerm);
            if ($serial === '') {
                dm_activity_action('INV_LOOKUP_SERIAL_RESOLVE_FAILED', [
                    'api' => 'inv_lookup_start',
                    'lookup' => $lookupTerm,
                    'target_asset_tags' => [$lookupTerm],
                    'error' => 'No serial could be resolved for lookup term.',
                ]);
                dm_json_response(['ok' => 0, 'error' => 'No serial could be resolved for that lookup.'], 404);
            }

            dm_activity_action('INV_LOOKUP_START_REQUESTED', [
                'api' => 'inv_lookup_start',
                'lookup' => $lookupTerm,
                'target_asset_tags' => [$lookupTerm],
                'target_serials' => [$serial],
            ]);

            $payload = [
                'priority'  => 5,
                'serial'    => 'NONE',
                'udid'      => 'DEVICE_MANAGER',
                'submitter' => 'DEVICE_MANAGER',
                'owner'     => $submitter,
                'nora'      => 'TRUE',
                'extra1'    => 'INV_LOOKUP',
                'extra2'    => json_encode(['serials' => [$serial]], JSON_UNESCAPED_SLASHES),
            ];

            $result = dm_nora_api_post_json_raw('/errand/create', $payload);
            if (!$result['ok']) {
                dm_activity_action('INV_LOOKUP_SUBMIT_FAILED', [
                    'api' => 'inv_lookup_start',
                    'lookup' => $lookupTerm,
                    'target_asset_tags' => [$lookupTerm],
                    'target_serials' => [$serial],
                    'error' => $result['error'] ?? 'Nora API request failed.',
                    'http_code' => $result['http_code'] ?? null,
                ]);
                dm_json_response([
                    'ok' => 0,
                    'error' => $result['error'] ?? 'Nora API request failed.',
                    'http_code' => $result['http_code'] ?? null,
                    'raw' => $result['raw'] ?? null,
                ], 502);
            }

            $respData = $result['data'] ?? [];
            $errandId = $respData['errand_id'] ?? $respData['ErrandID'] ?? null;
            if (!$errandId) {
                dm_activity_action('INV_LOOKUP_SUBMIT_NO_ERRAND_ID', [
                    'api' => 'inv_lookup_start',
                    'lookup' => $lookupTerm,
                    'target_asset_tags' => [$lookupTerm],
                    'target_serials' => [$serial],
                ]);
                dm_json_response([
                    'ok' => 0,
                    'error' => 'Nora API did not return an errand id.',
                    'raw' => $respData,
                ], 502);
            }

            dm_activity_action('INV_LOOKUP_SUBMITTED', [
                'api' => 'inv_lookup_start',
                'lookup' => $lookupTerm,
                'target_asset_tags' => [$lookupTerm],
                'target_serials' => [$serial],
                'errand_id' => (int)$errandId,
            ]);

            dm_json_response([
                'ok' => 1,
                'errand_id' => (int)$errandId,
                'serial' => $serial,
            ]);
        } catch (Throwable $e) {
            dm_activity_action('INV_LOOKUP_START_EXCEPTION', [
                'api' => 'inv_lookup_start',
                'lookup' => $lookup,
                'target_asset_tags' => $lookup !== '' ? [$lookup] : [],
                'error' => $e->getMessage(),
            ]);
            dm_json_response(['ok' => 0, 'error' => 'INV_LOOKUP start failed.'], 500);
        }
    }

    if ($api === 'errand_status') {
        $id = '';
        try {
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '' || !ctype_digit($id)) {
                dm_activity_action('INV_LOOKUP_STATUS_BAD_REQUEST', [
                    'api' => 'errand_status',
                    'error' => 'Missing valid errand id.',
                ]);
                dm_json_response(['ok' => 0, 'error' => 'Missing valid errand id.'], 400);
            }

            $result = dm_nora_api_get_json_raw('/errand/status/' . urlencode($id));
            if (!$result['ok']) {
                dm_activity_action('INV_LOOKUP_STATUS_READ_FAILED', [
                    'api' => 'errand_status',
                    'errand_id' => (int)$id,
                    'error' => $result['error'] ?? 'Could not read errand status.',
                    'http_code' => $result['http_code'] ?? null,
                ]);
                dm_json_response([
                    'ok' => 0,
                    'error' => $result['error'] ?? 'Could not read errand status.',
                    'http_code' => $result['http_code'] ?? null,
                    'raw' => $result['raw'] ?? null,
                ], 502);
            }

            $status = dm_normalize_inv_status($result['data'] ?? []);
            if (($status['status'] ?? '') === 'complete' || ($status['status'] ?? '') === 'failed') {
                dm_activity_action('INV_LOOKUP_STATUS_' . strtoupper((string)$status['status']), [
                    'api' => 'errand_status',
                    'errand_id' => (int)$id,
                    'status_raw' => $status['status_raw'] ?? 'UNKNOWN',
                    'message' => $status['message'] ?? '',
                ]);
            }
            dm_json_response([
                'ok' => 1,
                'status' => $status['status'] ?? 'running',
                'status_raw' => $status['status_raw'] ?? 'UNKNOWN',
                'message' => $status['message'] ?? '',
            ]);
        } catch (Throwable $e) {
            dm_activity_action('INV_LOOKUP_STATUS_EXCEPTION', [
                'api' => 'errand_status',
                'errand_id' => (ctype_digit($id) ? (int)$id : null),
                'error' => $e->getMessage(),
            ]);
            dm_json_response(['ok' => 0, 'error' => 'Errand status check failed.'], 500);
        }
    }

    if ($api === 'device_fragment') {
        $lookup = '';
        try {
            $lookup = trim((string)($_GET['lookup'] ?? ''));
            if ($lookup === '') {
                dm_activity_action('DEVICE_FRAGMENT_BAD_REQUEST', [
                    'api' => 'device_fragment',
                    'error' => 'Missing lookup term.',
                ]);
                dm_json_response(['ok' => 0, 'error' => 'Missing lookup term.'], 400);
            }

            $lookupTerm = dm_normalize_lookup_term($lookup);
            if ($lookupTerm === '') {
                dm_activity_action('DEVICE_FRAGMENT_BAD_REQUEST', [
                    'api' => 'device_fragment',
                    'lookup' => $lookup,
                    'error' => 'Enter an asset tag or exact Nora lookup value.',
                ]);
                dm_json_response(['ok' => 0, 'error' => 'Enter an asset tag or exact Nora lookup value.'], 400);
            }

            $state = dm_lookup_state_from_term($lookupTerm);
            $parsedDataApi = $state['parsedData'];
            $showSidebarApi = (bool)$state['showSidebar'];
            $mainOutputApi = (string)$state['mainOutput'];

            if (!empty($parsedDataApi)) {
                $_SESSION['last_lookup'] = $parsedDataApi;
            }

            $parts = dm_render_device_area($parsedDataApi, $showSidebarApi, $lookupTerm);
            dm_activity_action('DEVICE_FRAGMENT_REFRESH', [
                'api' => 'device_fragment',
                'lookup' => $lookupTerm,
                'target_asset_tags' => [$lookupTerm],
                'target_serials' => !empty($parsedDataApi['DeviceSerialNumber']) ? [(string)$parsedDataApi['DeviceSerialNumber']] : [],
                'found' => !empty($parsedDataApi),
            ]);
            dm_json_response([
                'ok' => 1,
                'found' => !empty($parsedDataApi),
                'main_html' => $parts['main_html'] ?? '',
                'sidebar_html' => $parts['sidebar_html'] ?? '',
                'main_output' => $mainOutputApi,
            ]);
        } catch (Throwable $e) {
            dm_activity_action('DEVICE_FRAGMENT_EXCEPTION', [
                'api' => 'device_fragment',
                'lookup' => $lookup,
                'target_asset_tags' => $lookup !== '' ? [$lookup] : [],
                'error' => $e->getMessage(),
            ]);
            dm_json_response(['ok' => 0, 'error' => 'Device refresh failed.'], 500);
        }
    }

    dm_activity_action('DEVICE_MANAGER_API_UNKNOWN_ROUTE', [
        'api' => $api,
        'error' => 'Unknown API route.',
    ]);
    dm_json_response(['ok' => 0, 'error' => 'Unknown API route.'], 404);
}
?>
<!DOCTYPE html>

<html lang="en">
<head>
<script>
  window.USERID = <?= json_encode($parsedData['USERID'] ?? '') ?>;

function toggleModules() {
    const container = document.getElementById("moduleContainer");
    const output = document.getElementById("moduleOutput");
    const icon = document.getElementById("moduleToggleIcon");

    if (container.style.display === "none") {
        container.style.display = "block";
        icon.textContent = "?";
        if (!modulesLoaded) {
            fetch("load_modules.php")
                .then(res => res.text())
                .then(html => { output.innerHTML = html; modulesLoaded = true; })
                .catch(() => { output.innerHTML = "?? Failed to load modules."; });
        }
    } else {
        container.style.display = "none";
        icon.textContent = "?";
    }
}
let modulesLoaded = false;

// ========== Load Individual Module on Demand ==========
function loadModule(name) {
  const output = document.getElementById("moduleDetails");
  output.innerHTML = "? Loading " + name + "...";

  fetch("run_modules.php?name=" + encodeURIComponent(name))
    .then(res => res.text())
    .then(html => {
      output.innerHTML = html;

      // ? Execute any inline scripts from the loaded module
      const scripts = output.querySelectorAll("script");
      scripts.forEach(script => {
        const newScript = document.createElement("script");
        if (script.src) {
          newScript.src = script.src;
        } else {
          newScript.textContent = script.textContent;
        }
        document.body.appendChild(newScript);
        document.body.removeChild(newScript);
      });
    })
    .catch(() => {
      output.innerHTML = "?? Failed to load module.";
    });
}

</script>
<link rel="icon" type="image/png" href="../musky_favicon.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GSD - MUSKY - Device Manager</title>

<!-- Theme Stylesheet -->
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">

<style>
.urgent-alert {
  background-color: red;
  color: white;
  font-size: 2em;
  font-weight: bold;
  text-align: center;
  padding: 1em;
  margin: 1em 0;
  border: 4px solid black;
  animation: pulse 1s infinite;
}

/* DeviceManager-local attention styling override (prettier than legacy yellow/red dash) */
.main .attention {
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid rgba(120, 120, 120, 0.25);
  border-left-width: 6px;
  font-weight: 600;
  line-height: 1.35;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.main .attention.attention-update {
  background: linear-gradient(180deg, #fff7db 0%, #fff2c8 100%);
  color: #6a3a00;
  border-left-color: #f0ad00;
}

.main .attention.attention-stale {
  background: linear-gradient(180deg, #ffe6e6 0%, #ffdede 100%);
  color: #7a1f1f;
  border-left-color: #d43f3a;
}

body.dark-mode .main .attention {
  border-color: rgba(255, 255, 255, 0.12);
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
}

body.dark-mode .main .attention.attention-update {
  background: linear-gradient(180deg, #3d3311 0%, #4a3d15 100%);
  color: #ffd67b;
}

body.dark-mode .main .attention.attention-stale {
  background: linear-gradient(180deg, #3f1f21 0%, #4a2528 100%);
  color: #ffb6b6;
}

body.musky-mode .main .attention {
  border-color: rgba(255, 136, 0, 0.35);
}
@keyframes pulse {
  0% { transform: scale(1); }
  50% { transform: scale(1.05); }
  100% { transform: scale(1); }
}

</style>
</head>

<body class="<?= htmlspecialchars($theme) ?>">
<div class="top-controls" style="display:flex;justify-content:space-between;align-items:center;margin:1em 0;">
    <button onclick="window.location.href='../index.php'" style="background:#ccc;padding:0.5em 1em;border:none;border-radius:5px;cursor:pointer;">🔙 Return to Launch</button>    
	<div id="refresh-timer" style="font-weight:bold;">Last updated: just now</div>
</div>

<!-- Mascot Icon -->
<img id="mascot" src="../mascot.png" alt="Mascot" class="corner-image">

<!-- Main Content -->
<div class="content">
<div class="main">

<h1>MUSKY Device Manager</h1>

<!-- Search Form -->
<form method="post" id="searchForm">
  <input type="text" name="user_input" id="user_input" placeholder="Enter asset tag or exact Nora lookup"
    value="<?php echo isset($_POST['user_input']) ? htmlspecialchars($_POST['user_input']) : ''; ?>"
    inputmode="text" maxlength="128" title="Enter an asset tag or exact Nora lookup value." required>
  <button type="submit" class="action-button">Search!</button>
</form>
<div id="lookup-status" style="display:none;margin-top:8px;padding:8px 10px;border-radius:6px;font-weight:bold;"></div>

<?php
// ===================
// Process Form or Direct URL Lookup
// ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['assettag'])) {

    if (!empty($_POST['user_input'])) {
        $rawInput = $_POST['user_input'];
    } elseif (!empty($_POST['previous_input'])) {
        $rawInput = $_POST['previous_input'];
    } elseif (!empty($_GET['assettag'])) {
        $rawInput = $_GET['assettag'];
    }

    $rawInput = trim((string)$rawInput);

    if (!empty($rawInput)) {
        $isActionPost = isset($_POST['button1']) || isset($_POST['lostmodeoff']) || isset($_POST['lostmodeon'])
            || isset($_POST['assign_button']) || isset($_POST['restart_button']) || isset($_POST['play_sound'])
            || isset($_POST['show_location']) || isset($_POST['report_problem_button']) || isset($_POST['power_wash_button']);
        $lookupTerm = dm_normalize_lookup_term($rawInput);
        $input = escapeshellarg($lookupTerm !== '' ? $lookupTerm : $rawInput); // Sanitize

        if ($lookupTerm === '' && !$isActionPost) {
            $mainOutput = 'Enter an asset tag or exact Nora lookup value.';
            dm_activity_action('DEVICE_MANAGER_INVALID_ASSET_TAG', [
                'lookup' => $rawInput,
                'error' => 'Enter an asset tag or exact Nora lookup value.',
            ]);
            write_log("Rejected lookup term '{$rawInput}' (empty lookup value).");
        } else {
            if ($lookupTerm !== '') {
                $rawInput = $lookupTerm;
            }

            $submitter = trim((string)($prefs['email'] ?? ($_SESSION['musky_user']['email'] ?? 'DEVICE_MANAGER')));
            if ($submitter === '') {
                $submitter = 'DEVICE_MANAGER';
            }

            try {
                $noraPdo = dm_try_nora_pdo();
                if ($noraPdo instanceof PDO) {
                    $lookupRow = dm_lookup_device_nora($noraPdo, $rawInput);
                } else {
                    $lookupRow = null;
                }
            } catch (Throwable $e) {
                $lookupRow = null;
                write_log("NORA lookup error for {$rawInput}: " . $e->getMessage());
            }

            // Handle Action Buttons (NORA-first, legacy fallback)
            if ($isActionPost) {
                $sidebarActionPrefixes = dm_sidebar_action_prefixes();
                if ($lookupRow) {
                    if (isset($_POST['button1'])) {
                    $serial = trim((string)($lookupRow['serial_number'] ?? ''));
                    $lastCommand = 'SIDEBAR_POWER_WASH: Musky_Wipe_iPad.php';
                    $debugOutput = "Opening sidebar Power Wash & Wax helper for serial {$serial}.";
                    write_log("Button Pressed: Wipe Device | Asset: {$rawInput} | Routed via sidebar Power Wash helper");
                    echo dm_sidebar_window_open_script((string)($sidebarActionPrefixes['power_wash'] ?? ''), $serial, 'helper');
                } elseif (isset($_POST['restart_button'])) {
                    $serial = trim((string)($lookupRow['serial_number'] ?? ''));
                    $lastCommand = 'SIDEBAR_POWER_REBOOT: Musky_Reboot_iPad.php';
                    $debugOutput = "Opening sidebar Power Reboot helper for serial {$serial}.";
                    write_log("Button Pressed: Restart Device | Asset: {$rawInput} | Routed via sidebar Power Reboot helper");
                    echo dm_sidebar_window_open_script((string)($sidebarActionPrefixes['power_reboot'] ?? ''), $serial, 'helper');
                } elseif (isset($_POST['report_problem_button'])) {
                    $serial = trim((string)($lookupRow['serial_number'] ?? ''));
                    $lastCommand = 'SIDEBAR_REPORT_PROBLEM: MuskyMakeATicket_iPad.php';
                    $debugOutput = "Opening sidebar ticket helper for serial {$serial}.";
                    write_log("Button Pressed: Report Problem with this Device | Asset: {$rawInput} | Routed via sidebar ticket helper");
                    echo dm_sidebar_window_open_script((string)($sidebarActionPrefixes['ticket'] ?? ''), $serial, 'report');
                } elseif (isset($_POST['power_wash_button'])) {
                    $serial = trim((string)($lookupRow['serial_number'] ?? ''));
                    $lastCommand = 'SIDEBAR_POWER_WASH: Musky_Wipe_iPad.php';
                    $debugOutput = "Opening sidebar Power Wash & Wax helper for serial {$serial}.";
                    write_log("Button Pressed: Power Wash & Wax | Asset: {$rawInput} | Routed via sidebar Power Wash helper");
                    echo dm_sidebar_window_open_script((string)($sidebarActionPrefixes['power_wash'] ?? ''), $serial, 'helper');
                } elseif (isset($_POST['show_location'])) {
                    $lastCommand = 'NORA_LOOKUP: location_url';
                    $locationLinkToOpen = trim((string)($lookupRow['location_url'] ?? ''));
                    if ($locationLinkToOpen !== '') {
                        $debugOutput = "Opening latest location URL from NORA.";
                        write_log("Button Pressed: Show Location | Asset: {$rawInput} | Source: NORA location_url");
                    } else {
                        $debugOutput = "No NORA location URL was available for this device.";
                        write_log("Button Pressed: Show Location | Asset: {$rawInput} | No NORA location URL available");
                    }
                } else {
                    $actionName = isset($_POST['lostmodeoff']) ? 'lostmodeoff'
                        : (isset($_POST['lostmodeon']) ? 'lostmodeon'
                        : (isset($_POST['assign_button']) ? 'assign_button' : 'play_sound'));

                    $result = dm_queue_nora_errand($actionName, $lookupRow, $submitter);
                    if ($result['ok']) {
                        $lastCommand = 'NORA_ERRAND: ' . strtoupper($actionName);
                        $debugOutput = "Queued Nora Errand #{$result['errand_id']} for {$actionName}.";
                        if (!empty($result['raw'])) {
                            $debugOutput .= "\n" . json_encode($result['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        }
                        write_log("Button Pressed: {$actionName} | Asset: {$rawInput} | ErrandID: {$result['errand_id']}");
                    } elseif ($actionName === 'assign_button' && trim((string)($lookupRow['effective_owner_email'] ?? '')) !== '') {
                        // If owner mapping is missing, request IIQUSERSYNC as a recovery path.
                        $ownerEmail = trim((string)$lookupRow['effective_owner_email']);
                        $syncPayload = [
                            'serial'    => 'N/A',
                            'udid'      => 'SYSTEM_TASK',
                            'submitter' => $submitter,
                            'nora'      => 'TRUE',
                            'priority'  => 5,
                            'extra1'    => 'IIQUSERSYNC',
                            'extra2'    => $ownerEmail,
                        ];
                        $syncResp = function_exists('musky_nora_api_post_json')
                            ? musky_nora_api_post_json('/errand/create', $syncPayload)
                            : null;
                        $syncErrandId = is_array($syncResp) ? ($syncResp['errand_id'] ?? $syncResp['ErrandID'] ?? null) : null;
                        if ($syncErrandId) {
                            $lastCommand = 'NORA_ERRAND: IIQUSERSYNC';
                            $debugOutput = "Assign blocked (missing owner mapping). Queued IIQUSERSYNC Errand #{$syncErrandId}. Refresh and try assign again.";
                            write_log("Button Pressed: assign_button | Asset: {$rawInput} | IIQUSERSYNC ErrandID: {$syncErrandId}");
                        } else {
                            $lastCommand = "$MOSBASIC_PATH iosassign $input";
                            $debugOutput = shell_exec($lastCommand . " 2>&1");
                            write_log("Button Pressed: assign_button | Asset: {$rawInput} | NORA assign failed, fell back to legacy command: {$lastCommand}");
                        }
                    } else {
                        $legacyCommand = '';
                        if ($actionName === 'lostmodeoff') {
                            $legacyCommand = "$MOSBASIC_PATH lostmodeoff $input";
                        } elseif ($actionName === 'lostmodeon') {
                            $legacyCommand = "$MOSBASIC_PATH lostmodeon $input";
                        } elseif ($actionName === 'play_sound') {
                            $legacyCommand = "$MOSBASIC_PATH annoy $input";
                        } elseif ($actionName === 'assign_button') {
                            $legacyCommand = "$MOSBASIC_PATH iosassign $input";
                        }

                        if ($legacyCommand !== '') {
                            $lastCommand = $legacyCommand;
                            $debugOutput = shell_exec($legacyCommand . " 2>&1");
                            write_log("Button Pressed: {$actionName} | Asset: {$rawInput} | NORA failed, fell back to legacy command: {$legacyCommand}");
                        } else {
                            $lastCommand = 'NORA_ERRAND: FAILED';
                            $debugOutput = "Action failed: " . ($result['error'] ?? 'Unknown error.');
                            if (!empty($result['raw'])) {
                                $debugOutput .= "\n" . json_encode($result['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            }
                            write_log("Button Pressed: {$actionName} | Asset: {$rawInput} | Failed: " . ($result['error'] ?? 'Unknown error'));
                        }
                    }
                    }
                } else {
                // If NORA lookup fails, preserve legacy shell behavior.
                    if (isset($_POST['button1'])) {
                    $serial = trim((string)($_POST['device_serial'] ?? ''));
                    if ($serial !== '') {
                        $lastCommand = 'SIDEBAR_POWER_WASH: Musky_Wipe_iPad.php';
                        $debugOutput = "Opening sidebar Power Wash & Wax helper for serial {$serial}.";
                        write_log("Button Pressed: Wipe Device | Asset: {$rawInput} | Routed via sidebar Power Wash helper (fallback serial)");
                        echo dm_sidebar_window_open_script((string)($sidebarActionPrefixes['power_wash'] ?? ''), $serial, 'helper');
                    } else {
                        $lastCommand = "$MOSBASIC_PATH ioswipe $input";
                        $debugOutput = shell_exec($lastCommand . " 2>&1");
                        write_log("Button Pressed: Wipe Device | Asset: {$rawInput} | Command: {$lastCommand} | Output: " . substr((string)$debugOutput, 0, 500));
                    }
                } elseif (isset($_POST['lostmodeoff'])) {
                    $lastCommand = "$MOSBASIC_PATH lostmodeoff $input";
                    $debugOutput = shell_exec($lastCommand . " 2>&1");
                    write_log("Button Pressed: Lost Mode OFF | Asset: {$rawInput} | Command: {$lastCommand}");
                } elseif (isset($_POST['lostmodeon'])) {
                    $lastCommand = "$MOSBASIC_PATH lostmodeon $input";
                    $debugOutput = shell_exec($lastCommand . " 2>&1");
                    write_log("Button Pressed: Lost Mode ON | Asset: {$rawInput} | Command: {$lastCommand}");
                } elseif (isset($_POST['assign_button'])) {
                    $lastCommand = "$MOSBASIC_PATH iosassign $input";
                    $debugOutput = shell_exec($lastCommand . " 2>&1");
                    write_log("Button Pressed: Assign Device | Asset: {$rawInput} | Command: {$lastCommand}");
                } elseif (isset($_POST['restart_button'])) {
                    $serial = trim((string)($_POST['device_serial'] ?? ''));
                    if ($serial !== '') {
                        $lastCommand = 'SIDEBAR_POWER_REBOOT: Musky_Reboot_iPad.php';
                        $debugOutput = "Opening sidebar Power Reboot helper for serial {$serial}.";
                        write_log("Button Pressed: Restart Device | Asset: {$rawInput} | Routed via sidebar Power Reboot helper (fallback serial)");
                        echo dm_sidebar_window_open_script((string)($sidebarActionPrefixes['power_reboot'] ?? ''), $serial, 'helper');
                    } else {
                        $lastCommand = "$MOSBASIC_PATH restart $input";
                        $debugOutput = shell_exec($lastCommand . " 2>&1");
                        write_log("Button Pressed: Restart Device | Asset: {$rawInput} | Command: {$lastCommand}");
                    }
                } elseif (isset($_POST['play_sound'])) {
                    $lastCommand = "$MOSBASIC_PATH annoy $input";
                    $debugOutput = shell_exec($lastCommand . " 2>&1");
                    write_log("Button Pressed: Play Sound | Asset: {$rawInput} | Command: {$lastCommand}");
                } elseif (isset($_POST['report_problem_button'])) {
                    $serial = trim((string)($_POST['device_serial'] ?? ''));
                    if ($serial !== '') {
                        $lastCommand = 'SIDEBAR_REPORT_PROBLEM: MuskyMakeATicket_iPad.php';
                        $debugOutput = "Opening sidebar ticket helper for serial {$serial}.";
                        write_log("Button Pressed: Report Problem with this Device | Asset: {$rawInput} | Routed via sidebar ticket helper (fallback serial)");
                        echo dm_sidebar_window_open_script((string)($sidebarActionPrefixes['ticket'] ?? ''), $serial, 'report');
                    } else {
                        $lastCommand = 'SIDEBAR_REPORT_PROBLEM: missing serial';
                        $debugOutput = "Unable to open Report Problem: no device serial was available.";
                        write_log("Button Pressed: Report Problem with this Device | Asset: {$rawInput} | Failed: missing serial");
                    }
                } elseif (isset($_POST['power_wash_button'])) {
                    $serial = trim((string)($_POST['device_serial'] ?? ''));
                    if ($serial !== '') {
                        $lastCommand = 'SIDEBAR_POWER_WASH: Musky_Wipe_iPad.php';
                        $debugOutput = "Opening sidebar Power Wash & Wax helper for serial {$serial}.";
                        write_log("Button Pressed: Power Wash & Wax | Asset: {$rawInput} | Routed via sidebar Power Wash helper (fallback serial)");
                        echo dm_sidebar_window_open_script((string)($sidebarActionPrefixes['power_wash'] ?? ''), $serial, 'helper');
                    } else {
                        $lastCommand = 'SIDEBAR_POWER_WASH: missing serial';
                        $debugOutput = "Unable to open Power Wash & Wax: no device serial was available.";
                        write_log("Button Pressed: Power Wash & Wax | Asset: {$rawInput} | Failed: missing serial");
                    }
                } elseif (isset($_POST['show_location']) && !empty($_POST['device_serial'])) {
                    $serialInput = escapeshellarg($_POST['device_serial']);
                    $lastCommand = "$LOCATION_SCRIPT_PATH/LocationWebLink.sh $serialInput";
                    $debugOutput = shell_exec($lastCommand . " 2>&1");
                    write_log("Button Pressed: Show Location | Asset: {$rawInput} | Command: {$lastCommand}");
                    if (preg_match('/https?:\/\/\S+/', (string)$debugOutput, $matches)) {
                        $locationLinkToOpen = $matches[0];
                    }
                    }
                }
            }

            // Always refresh device info from NORA only.
            if ($lookupRow) {
                $parsedData = dm_build_legacy_lookup($lookupRow);
                $lines = dm_build_parsed_lines($parsedData);
                $_SESSION["parsed_lines"] = $lines;
                $mainOutput = implode("\n", $lines);
                $showSidebar = !empty($parsedData['ASSETTAG']) || !empty($parsedData['DeviceSerialNumber']);
            } else {
                $parsedData = [];
                $showSidebar = false;
                $mainOutput = "No NORA device record matched lookup {$rawInput}.";
            }
        }
    }
}

if ($locationLinkToOpen !== '') {
    echo "<script>window.open(" . json_encode($locationLinkToOpen) . ", '_blank');</script>";
}

// ===================
// Display Device Info
// ===================
$renderParts = dm_render_device_area($parsedData, (bool)$showSidebar, $rawInput);
if (!empty($parsedData)) {
    $loggedAsset = $parsedData['ASSETTAG'] ?? '';
    $loggedSerial = $parsedData['DeviceSerialNumber'] ?? '';
    write_log("Page Loaded | Asset: {$loggedAsset} | Serial: {$loggedSerial}");
    $_SESSION['last_lookup'] = $parsedData;
}
?>

<div id="deviceInfoArea"><?php echo $renderParts['main_html'] ?? ''; ?></div>

</div> <!-- end main -->
<div id="deviceSidebarArea"><?php echo $renderParts['sidebar_html'] ?? ''; ?></div>
</div> <!-- end content -->

<!-- Hidden Debug Output Pane -->
<div id="debugPane" style="display:none;"></div>

<!-- Pass PHP variables to JavaScript -->
<script>
// Last executed shell command
let lastCommand = <?php echo json_encode($lastCommand); ?>;

// Last debug output (either from command or device info)
let lastDebugOutput = <?php echo json_encode($debugOutput ?: $mainOutput); ?>;

</script>

<!-- JavaScript Section -->
<script>
// ========== Theme Switching ==========
const SERVER_THEME = <?= json_encode((string)$theme) ?>;
const DEVICE_MANAGER_INV_LOOKUP_CSRF = <?= json_encode(musky_csrf_token('device_manager_inv_lookup')) ?>;
const THEME_WHITELIST = ['light-mode', 'dark-mode', 'musky-mode', 'gator-time-mode'];
const THEME_ALIASES = {
  light: 'light-mode',
  'light-mode': 'light-mode',
  dark: 'dark-mode',
  'dark-mode': 'dark-mode',
  musky: 'musky-mode',
  'musky-mode': 'musky-mode',
  gator: 'gator-time-mode',
  'gator-time': 'gator-time-mode',
  'gator-time-mode': 'gator-time-mode',
};

function normalizeTheme(theme) {
  const value = (theme || '').trim().toLowerCase();
  const mapped = THEME_ALIASES[value] || value;
  if (THEME_WHITELIST.includes(mapped)) return mapped;
  return THEME_WHITELIST.includes(SERVER_THEME) ? SERVER_THEME : 'musky-mode';
}

function applyTheme(theme) {
  const resolved = normalizeTheme(theme);
  document.body.classList.remove(...THEME_WHITELIST);
  document.body.classList.add(resolved);
  return resolved;
}

function setLookupStatus(message, type = 'info') {
  const el = document.getElementById('lookup-status');
  if (!el) return;

  let bg = '#d9edf7';
  let fg = '#1f4f66';
  if (type === 'success') { bg = '#dff0d8'; fg = '#2f6627'; }
  if (type === 'warn')    { bg = '#fcf8e3'; fg = '#8a6d3b'; }
  if (type === 'error')   { bg = '#f2dede'; fg = '#a94442'; }

  el.style.display = 'block';
  el.style.background = bg;
  el.style.color = fg;
  el.textContent = message;
}

function normalizeAssetTagInput(value) {
  const trimmed = (value || '').trim();
  return trimmed;
}

function attachSidebarBounceHandlers() {
  const sidebarButtons = document.querySelectorAll('.sidebar .action-button');
  sidebarButtons.forEach(button => {
    button.addEventListener('click', triggerMascotBounce);
  });
}

async function fetchJsonStrict(url, options = {}) {
  const res = await fetch(url, options);
  const raw = await res.text();

  let data = null;
  try {
    data = raw ? JSON.parse(raw) : null;
  } catch (err) {
    const sample = raw ? raw.slice(0, 200).replace(/\s+/g, ' ') : '(empty response)';
    throw new Error(`Server returned non-JSON response (${res.status}). ${sample}`);
  }

  if (!res.ok || !data || data.ok === 0) {
    throw new Error((data && data.error) ? data.error : `Request failed (${res.status}).`);
  }

  return data;
}

async function startInvLookup(lookup) {
  const data = await fetchJsonStrict('index.php?api=inv_lookup_start', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      lookup,
      csrf_token: DEVICE_MANAGER_INV_LOOKUP_CSRF,
    }),
  });
  return data;
}

async function pollInvLookup(errandId) {
  const started = Date.now();
  while (Date.now() - started < 180000) {
    await new Promise(resolve => setTimeout(resolve, 3000));
    const data = await fetchJsonStrict(`index.php?api=errand_status&id=${encodeURIComponent(errandId)}`, { cache: 'no-store' });

    if (data.status === 'complete') {
      return;
    }
    if (data.status === 'failed') {
      throw new Error(`INV_LOOKUP ended with status ${data.status_raw || 'FAILED'}.`);
    }

    setLookupStatus(`INV_LOOKUP running (${data.status_raw || 'running'})...`, 'info');
  }

  throw new Error('INV_LOOKUP timed out after 3 minutes.');
}

async function refreshDeviceAreas(lookup) {
  const data = await fetchJsonStrict(`index.php?api=device_fragment&lookup=${encodeURIComponent(lookup)}`, { cache: 'no-store' });

  const infoArea = document.getElementById('deviceInfoArea');
  if (infoArea) infoArea.innerHTML = data.main_html || '';

  const sidebarArea = document.getElementById('deviceSidebarArea');
  if (sidebarArea) sidebarArea.innerHTML = data.sidebar_html || '';

  if (typeof data.main_output === 'string' && data.main_output.length > 0) {
    lastDebugOutput = data.main_output;
    lastCommand = 'INV_LOOKUP_REFRESH';
  }

  attachSidebarBounceHandlers();
  return data;
}

async function runLookupFlow(lookupOverride = '') {
  const input = document.getElementById('user_input');
  const searchBtn = document.querySelector('#searchForm button[type="submit"]');
  const lookup = (lookupOverride || (input ? input.value : '') || '').trim();
  if (!lookup) return;
  const lookupKey = normalizeAssetTagInput(lookup);
  if (!lookupKey) {
    setLookupStatus('Enter an asset tag or exact Nora lookup value.', 'error');
    if (input) {
      input.focus();
      input.select();
    }
    return;
  }

  if (searchBtn) searchBtn.disabled = true;
  setLookupStatus(`Loading current Nora data for ${lookupKey}...`, 'info');

  let preloaded = false;
  try {
    const initial = await refreshDeviceAreas(lookupKey);
    preloaded = !!(initial && initial.found);
    startRefreshTimer();
    if (preloaded) {
      setLookupStatus('Current data loaded. Submitting INV_LOOKUP for a fresh refresh...', 'info');
    } else {
      setLookupStatus('No cached Nora data found yet. Submitting INV_LOOKUP...', 'warn');
    }
  } catch (preErr) {
    const preMsg = (preErr && preErr.message) ? preErr.message : 'initial data load failed';
    setLookupStatus(`Could not preload current data (${preMsg}). Submitting INV_LOOKUP...`, 'warn');
  }

  try {
    const started = await startInvLookup(lookupKey);
    setLookupStatus(`INV_LOOKUP submitted (Errand #${started.errand_id}). Waiting for completion...`, 'info');
    await pollInvLookup(started.errand_id);
    setLookupStatus('INV_LOOKUP complete. Refreshing device data...', 'success');
    await refreshDeviceAreas(lookupKey);
    setLookupStatus('Device data refreshed.', 'success');
    startRefreshTimer();
  } catch (err) {
    const message = (err && err.message) ? err.message : 'Lookup failed.';
    setLookupStatus(message, 'error');
  } finally {
    if (searchBtn) searchBtn.disabled = false;
  }
}

// ========== Force Page Refresh ==========
function forceRefresh() {
  runLookupFlow();
}

// ========== Toggle Debug Pane ==========
function handleDebugClick() {
  const debugPane = document.getElementById('debugPane');

  if (debugPane.style.display === 'block') {
    debugPane.style.display = 'none';
    debugPane.innerHTML = '';
    localStorage.removeItem('debug-open');
  } else {
    debugPane.style.display = 'block';
    debugPane.innerHTML =
      "<div style='color:red;font-weight:bold;'>COMMAND SENT -> " + lastCommand + "</div>" +
      "<pre>" + lastDebugOutput + "</pre>";
    localStorage.setItem('debug-open', 'yes');
  }

  triggerMascotBounce();
}

// ========== Mascot Bounce Animation ==========
function triggerMascotBounce() {
  const mascot = document.getElementById('mascot');
  mascot.classList.add('bounce');
  setTimeout(() => mascot.classList.remove('bounce'), 500);
}

// ========== Window Load Setup ==========
window.onload = function() {
  applyTheme(SERVER_THEME);

  attachSidebarBounceHandlers();

  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('assettag')) {
    const fromUrl = normalizeAssetTagInput(urlParams.get('assettag'));
    if (fromUrl) {
      document.getElementById('user_input').value = fromUrl;
    } else {
      setLookupStatus('Enter an asset tag or exact Nora lookup value.', 'error');
    }
  }

  if (localStorage.getItem('debug-open') === 'yes' || urlParams.has('debug')) {
    handleDebugClick();
  }

  const searchForm = document.getElementById('searchForm');
  if (searchForm) {
    searchForm.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!searchForm.checkValidity()) {
        searchForm.reportValidity();
        return;
      }
      runLookupFlow();
    });
  }
};
</script>


<script>
let secondsElapsed = 0;
let timerInterval = null;

function updateTimerDisplay() {
    const el = document.getElementById('refresh-timer');
    const minutes = Math.floor(secondsElapsed / 60);
    const seconds = secondsElapsed % 60;
    el.textContent = `Last updated: ${minutes}:${seconds.toString().padStart(2, '0')} ago`;
}

function startRefreshTimer() {
    secondsElapsed = 0;
    updateTimerDisplay();
    clearInterval(timerInterval);
    timerInterval = setInterval(() => {
        secondsElapsed++;
        updateTimerDisplay();
    }, 1000);
}

document.addEventListener('DOMContentLoaded', () => {
    startRefreshTimer();
});
</script>
</body>

</html>
