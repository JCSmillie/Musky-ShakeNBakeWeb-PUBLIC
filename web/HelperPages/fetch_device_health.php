<?php
// ============================================================================
// MUSKY — fetch_device_health.php (Multi-Serial + Full Health Evaluation)
// ----------------------------------------------------------------------------
// Purpose:
//   Provides standardized health/status evaluation for one or more iPads/devices
//   based on structured NORA device_history data (no longer uses extra_data).
//
// Inputs:
//   ?serial=QWXG9NG09W                → single device
//   ?serials=QWXG9NG09W,QWXG9NG10W    → multiple devices
//
// Behavior:
//   - Fetches latest device_history record per serial_number
//   - Evaluates battery, disk space, lost mode, mute status, and on-campus state
//   - Detects iOS update requirements
//   - Calculates “days since last on-campus”
//   - Converts timestamps to local time
//   - Returns JSON keyed by serial
//
// Dependencies:
//   • ../bootstrap.php
//   • ../../Functions/nora_connect.php
//   • Uses $LOG_PATH for logging if defined
//   • America/New_York timezone
// ============================================================================

date_default_timezone_set('America/New_York');
require_once __DIR__ . '/../bootstrap.php';
// ----------------------------------------------------------------------------
// SECURITY BASELINE (MANDATORY):
//   This JSON helper is callable from browser scripts, so it must include the
//   same centralized auth/access bootstrap as full HTML pages.
// ----------------------------------------------------------------------------
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';

// ----------------------------------------------------------------------------
// Logging Setup
// ----------------------------------------------------------------------------
$LOGFILE = (isset($LOG_PATH) && $LOG_PATH)
    ? rtrim($LOG_PATH, '/') . '/musky_device_health.log'
    : '/tmp/musky_device_health.log';

function hlog(string $msg): void {
    global $LOGFILE;
    @file_put_contents($LOGFILE, "[".date('Y-m-d H:i:s')."] <fetch_device_health> $msg\n", FILE_APPEND);
}

// ----------------------------------------------------------------------------
// Input Validation (accept ?serial or ?serials, comma-separated)
// ----------------------------------------------------------------------------
$serialInput = trim($_GET['serials'] ?? ($_GET['serial'] ?? ''));
if ($serialInput === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_serial']);
    exit;
}
$serials = array_filter(array_map('trim', explode(',', $serialInput)));

// ----------------------------------------------------------------------------
// Main processing
// ----------------------------------------------------------------------------
try {
    $pdo = nora_connect();
    hlog("Checking device health for " . count($serials) . " serial(s): " . implode(',', $serials));

    $output = [];

    // ------------------------------------------------------------------------
    // Fetch all latest device_history rows in one optimized query
    // ------------------------------------------------------------------------
    $placeholders = implode(',', array_fill(0, count($serials), '?'));
    $query = "
        SELECT dh.*
        FROM device_history dh
        INNER JOIN (
            SELECT serial_number, MAX(last_seen) AS latest_seen
            FROM device_history
            WHERE serial_number IN ($placeholders)
            GROUP BY serial_number
        ) latest
        ON dh.serial_number = latest.serial_number
        AND dh.last_seen = latest.latest_seen
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($serials);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        http_response_code(404);
        echo json_encode(['error' => 'no_records_found']);
        exit;
    }

    // Index by serial for quick lookup
    $records = [];
    foreach ($rows as $r) {
        $records[$r['serial_number']] = $r;
    }

    // ------------------------------------------------------------------------
    // Process each requested serial
    // ------------------------------------------------------------------------
    foreach ($serials as $serial) {
        $data = $records[$serial] ?? null;
        if (!$data) {
            $output[$serial] = ['error' => 'not_found'];
            continue;
        }

        // --------------------------------------------------------------------
        // Extract and normalize fields
        // --------------------------------------------------------------------
        $battery       = isset($data['battery']) ? (float)$data['battery'] : -1;
        $percentDisk   = isset($data['percent_disk']) ? (float)$data['percent_disk'] : -1;
        $availableDisk = isset($data['available_disk']) ? (float)$data['available_disk'] : null;
        $totalDisk     = isset($data['total_disk']) ? (float)$data['total_disk'] : null;
        $lostmode      = strtoupper($data['lostmode_status'] ?? 'UNKNOWN');
        $isMuted       = (int)($data['is_muted'] ?? 0);
        $lastIP        = trim($data['last_ip_beat'] ?? '');
        $lastBeatEpoch = $data['last_seen'] ? strtotime($data['last_seen']) : null;
        $openLink      = trim($data['open_direct_device_link'] ?? '');
        $assetTag      = trim($data['asset_tag'] ?? 'UNKNOWN');
        $osVersion     = trim($data['os_version'] ?? '');
        $needUpdate    = trim($data['needosupdate'] ?? '');
        $updateTarget  = $needUpdate && $needUpdate !== $osVersion ? $needUpdate : null;

        // Compute percent_disk if missing (based on available/total)
        if ($percentDisk <= 0 && $availableDisk !== null && $totalDisk > 0) {
            $percentDisk = 1 - ($availableDisk / $totalDisk);
        }

        $lastSeenLocal = $lastBeatEpoch ? date('Y-m-d H:i:s', $lastBeatEpoch) : null;

        // --------------------------------------------------------------------
        // Evaluate health conditions
        // --------------------------------------------------------------------
        $issues = [];
        $icons  = [];
        $flags  = [];

        // 🔋 Battery check
        if ($battery >= 0 && $battery < 0.30) {
            $issues[] = "Low battery (" . round($battery * 100) . "%)";
            $icons[]  = "🔋⚠️";
            $flags[]  = "low_battery";
        }

        // 💾 Disk check (enhanced threshold logic)
        if ($percentDisk >= 0.85) {
            // Normal interpretation — percent used
            $issues[] = "Disk nearly full (" . round($percentDisk * 100) . "% used)";
            $icons[]  = "💾⚠️";
            $flags[]  = "disk_full";
        } elseif ($percentDisk > 0 && $percentDisk <= 0.50 && $availableDisk !== null && $totalDisk !== null) {
            // MOSBasic sometimes reports percent_disk as "percent available"
            $percentUsed = 1 - $percentDisk;
            if ($percentUsed >= 0.85 || $percentDisk <= 0.30) {
                $issues[] = "Disk nearly full (" . round($percentUsed * 100) . "% used)";
                $icons[]  = "💾⚠️";
                $flags[]  = "disk_full";
            }
        } elseif ($availableDisk !== null && $totalDisk !== null) {
            // Fallback purely based on GB math
            $percentFree = $availableDisk / $totalDisk;
            if ($percentFree < 0.15) {
                $issues[] = "Disk nearly full (" . round($percentFree * 100) . "% free)";
                $icons[]  = "💾⚠️";
                $flags[]  = "disk_full";
            }
        }

        // 🚨 Lost mode
        if (in_array($lostmode, ['ENABLED', 'PENDING', 'PENDING_TO_ENABLE'], true)) {
            $issues[] = "Lost Mode active or pending";
            $icons[]  = "🚨❗";
            $flags[]  = "lost_mode";
        }

        // 🔇 Muted
        if ($isMuted === 1) {
            $issues[] = "Device muted";
            $icons[]  = "🔇";
            $flags[]  = "muted";
        }

        // ⚙️ Update required
        $needsUpdate = false;
        if ($updateTarget && version_compare($updateTarget, $osVersion, '>')) {
            $needsUpdate = true;
            $issues[] = "iOS update needed (→ $updateTarget)";
            $icons[]  = "⚙️⬆️";
            $flags[]  = "needs_update";
        }

        // --------------------------------------------------------------------
        // Determine on-campus status
        // --------------------------------------------------------------------
        // --------------------------------------------------------------------
        // Determine ON-CAMPUS TODAY
        // --------------------------------------------------------------------
        $gatewaySubnet = '65.254.21.';   // Add others here if needed
        $onCampusToday = false;
        $daysSinceCampus = null;

        $today = date('Y-m-d');
        $beatDate = date('Y-m-d', $lastBeatEpoch);
        $beatHour = (int)date('G', $lastBeatEpoch);

        // Condition 1: Was it EVER on-campus today?
        $wasOnCampusToday = (
            str_starts_with($lastIP, $gatewaySubnet) &&
            $beatDate === $today
        );

        // Condition 2: Time-of-day logic for determining student presence
        $inSchoolHours = ($beatHour >= 7 && $beatHour < 17);

        // Final determination
        if ($wasOnCampusToday && $inSchoolHours) {
            // Device checked in from campus during school hours — student brought it
            $onCampusToday = true;
            $flags[] = 'on_campus';
        } elseif ($wasOnCampusToday) {
            // Checked in from campus today, but outside hours — still counts as “seen today”
            $onCampusToday = true;
            $flags[] = 'on_campus';
        }

        // Compute days since campus
        if ($wasOnCampusToday) {
            $daysSinceCampus = 0;
        } elseif ($lastBeatEpoch > 0) {
            $daysSinceCampus = floor((time() - $lastBeatEpoch) / 86400);
        }
        // --------------------------------------------------------------------
        // Build standardized result object
        // --------------------------------------------------------------------
        $output[$serial] = [
            'serial'                => $serial,
            'asset_tag'             => $assetTag,
            'battery'               => $battery,
            'percent_disk'          => $percentDisk,
            'lostmode_status'       => $lostmode,
            'is_muted'              => $isMuted,
            'last_ip_beat'          => $lastIP,
            'date_last_beat'        => $lastSeenLocal,
            'on_campus_today'       => $onCampusToday,
            'days_since_campus'     => $daysSinceCampus,
            'issues'                => $issues,
            'icons'                 => $icons,
            'flags'                 => $flags,
            'osversion'             => $osVersion,
            'update_target_version' => $updateTarget,
            'needs_update'          => $needsUpdate,
            'open_link'             => $openLink
        ];

        hlog("Health checked {$serial} — issues=" . count($issues) . "; flags=" . implode(',', $flags));
    }

    // ------------------------------------------------------------------------
    // Output JSON response
    // ------------------------------------------------------------------------
    header('Content-Type: application/json');
    echo json_encode(['devices' => $output], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    hlog("💥 Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server', 'detail' => 'Device health data unavailable.']);
}
?>
