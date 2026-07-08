<?php
// ============================================================================
// MUSKY - fetch_loaner_devices.php
// ----------------------------------------------------------------------------
// Returns enriched loaner device rows for a given pool.
//  - Read-only
//  - Safe JSON parsing
//  - Defensive error handling & logging
// ============================================================================

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ----------------------------------------------------------------------------
// Access enforcement
// ----------------------------------------------------------------------------
$allowed = musky_require_general_admin_access(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['LOANER_MGMT', 'LOANER_EXPLORER', 'CLASS_MANAGER'],
    [
        'response' => 'json',
        'status' => 403,
        'message' => 'Insufficient Musky permissions',
        'payload' => ['error' => 'forbidden', 'detail' => 'Insufficient Musky permissions'],
    ]
);

// ----------------------------------------------------------------------------
// Logging helper
// ----------------------------------------------------------------------------
$LOGFILE = (isset($LOG_PATH) && $LOG_PATH)
    ? rtrim($LOG_PATH, '/') . '/musky_loaner_debug.log'
    : '/tmp/musky_loaner_debug.log';

function ldevlog(string $msg, array $ctx = []): void {
    global $LOGFILE;
    $line = "[" . date('Y-m-d H:i:s') . "] <fetch_loaner_devices> $msg";
    if (!empty($ctx)) $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES);
    @file_put_contents($LOGFILE, $line . "\n", FILE_APPEND);
}

// ----------------------------------------------------------------------------
// Input validation
// ----------------------------------------------------------------------------
$pool = trim($_GET['pool'] ?? '');

if ($pool === '') {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request', 'detail' => 'Missing ?pool parameter']);
    exit;
}

global $pdo;
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['error' => 'server', 'detail' => 'Missing NORA PDO handle']);
    exit;
}

try {
    // ------------------------------------------------------------------------
    // Main SQL query
    // ------------------------------------------------------------------------
    $sql = "
        SELECT
            l.asset_id,
            l.serial_number,
            l.pool_name,
            l.issue_date,
            l.ticket_id,
            l.iiq_owner_email,
            l.owner_id           AS iiq_owner_id,
            l.device_id          AS loaner_device_id,

            d.id                 AS device_id,
            d.asset_tag          AS asset_tag,
            d.open_direct_device_link,
            d.owner_id           AS mosyle_owner_id,
            d.extra_data         AS device_extra_data,

            o_i.full_name        AS iiq_owner_name,
            o_i.email            AS iiq_owner_email_db,

            o_m.full_name        AS mosyle_owner_name,
            o_m.email            AS mosyle_owner_email,

            dh.tags,
            dh.last_seen,
            dh.os_version,
            dh.lostmode_status,
            dh.last_ip_beat,
            dh.total_disk,
            dh.extra_data        AS history_extra_data

        FROM iiq_loaners l

        LEFT JOIN (
            SELECT d1.*
            FROM devices d1
            INNER JOIN (
                SELECT serial_number, MAX(id) AS max_id
                FROM devices
                GROUP BY serial_number
            ) latest_device
              ON latest_device.serial_number = d1.serial_number
             AND latest_device.max_id = d1.id
        ) d
          ON d.serial_number = l.serial_number

        LEFT JOIN owners o_i
          ON o_i.id = l.owner_id

        LEFT JOIN owners o_m
          ON o_m.id = d.owner_id

        LEFT JOIN (
            SELECT
                dh1.serial_number,
                dh1.tags,
                dh1.last_seen,
                dh1.os_version,
                dh1.lostmode_status,
                dh1.last_ip_beat,
                dh1.total_disk,
                dh1.extra_data,
                dh1.snapshot_time
            FROM device_history dh1
            INNER JOIN (
                SELECT serial_number, MAX(snapshot_time) AS snapshot_time
                FROM device_history
                GROUP BY serial_number
            ) latest
              ON latest.serial_number = dh1.serial_number
             AND latest.snapshot_time = dh1.snapshot_time
        ) dh
          ON dh.serial_number = l.serial_number

        WHERE l.pool_name = :pool
        ORDER BY l.ticket_id IS NULL, l.serial_number
    ";

    ldevlog("Executing SQL", ['pool' => $pool]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pool' => $pool]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    ldevlog("Rows fetched", [
        'count' => count($rows),
        'sample_serial' => $rows[0]['serial_number'] ?? null,
        'sample_asset'  => $rows[0]['asset_id'] ?? null
    ]);

    // ------------------------------------------------------------------------
    // Normalize each row & safely parse JSON
    // ------------------------------------------------------------------------
    foreach ($rows as &$r) {
        // Canonical serial
        $r['serial'] = $r['serial_number'] ?? null;

        // Loaned?
        $r['loaned'] = !empty($r['ticket_id']);

        // Prefer explicit IIQ email from loaner table
        if (!empty($r['iiq_owner_email'])) {
            // keep it
        } elseif (!empty($r['iiq_owner_email_db'])) {
            $r['iiq_owner_email'] = $r['iiq_owner_email_db'];
        } else {
            $r['iiq_owner_email'] = null;
        }

        // Normalize tags
        $tags = [];
        if (!empty($r['tags'])) {
            $tags = array_map('trim', explode(',', $r['tags']));
            $tags = array_filter($tags, fn($t) => $t !== "");
        }
        $r['tags'] = array_values($tags);

        // --------------------------------------------------------------------
        // Safely decode device.extra_data
        // --------------------------------------------------------------------
        $devExtra = null;
        if (!empty($r['device_extra_data'])) {
            $j = json_decode($r['device_extra_data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $devExtra = $j;
            } else {
                ldevlog('device_extra_data JSON decode error', [
                    'serial' => $r['serial'],
                    'error'  => json_last_error_msg()
                ]);
            }
        }
        $r['device_extra_parsed'] = $devExtra;

        $r['mosyle_current_userid'] = null;
        $r['mosyle_current_username'] = null;
        $r['mosyle_current_useremail'] = null;

        if (is_array($devExtra)) {
            $hasMosyleIdentityKeys =
                array_key_exists('userid', $devExtra) ||
                array_key_exists('username', $devExtra) ||
                array_key_exists('useremail', $devExtra);

            $userid = trim((string)($devExtra['userid'] ?? ''));
            $username = trim((string)($devExtra['username'] ?? ''));
            $useremail = trim((string)($devExtra['useremail'] ?? ''));

            $r['mosyle_current_userid'] = ($userid !== '') ? $userid : null;
            $r['mosyle_current_username'] = ($username !== '') ? $username : null;
            $r['mosyle_current_useremail'] = ($useremail !== '') ? $useremail : null;

            // IMPORTANT:
            // Blank userid/username/useremail fields do NOT reliably mean the
            // device is unassigned in Mosyle. Some devices still carry a valid
            // owner_id in Nora/devices even when the current payload does not
            // expose login-style identity fields. DeviceVitals treats owner_id as
            // the source of truth for current assignment, and Loaner Explorer
            // needs to do the same so both screens stay aligned.
            //
            // We still expose mosyle_current_* for debug purposes, but we do not
            // zero out the joined devices.owner_id ownership fields just because
            // the current payload has blank user/login fields.
        }

        // --------------------------------------------------------------------
        // Safely decode device_history.extra_data
        // --------------------------------------------------------------------
        $histExtra = null;
        if (!empty($r['history_extra_data'])) {
            $j = json_decode($r['history_extra_data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $histExtra = $j;
            } else {
                ldevlog('history_extra_data JSON decode error', [
                    'serial' => $r['serial'],
                    'error'  => json_last_error_msg()
                ]);
            }
        }
        $r['history_extra_parsed'] = $histExtra;

        // Derive needsosupdate from parsed JSON.
        // This legacy numeric field is only a convenience flag. The richer
        // source of truth is the target-version string in `needosupdate`, which
        // may live in device or history extra_data and is read directly by the
        // UI. We prefer the current device payload when present.
        $r['needsosupdate'] = 0;
        if (is_array($devExtra) && array_key_exists('needsosupdate', $devExtra)) {
            $r['needsosupdate'] = (int)$devExtra['needsosupdate'];
        } elseif (is_array($histExtra) && array_key_exists('needsosupdate', $histExtra)) {
            $r['needsosupdate'] = (int)$histExtra['needsosupdate'];
        }

        // --------------------------------------------------------------------
        // Loan Status Classification
        // --------------------------------------------------------------------
        $hasTicket     = !empty($r['ticket_id']);
        $iiqOwnerId    = $r['iiq_owner_id'] ?? null;
        $mosyleOwnerId = $r['mosyle_owner_id'] ?? null;

        if ($hasTicket && $mosyleOwnerId && $iiqOwnerId && (string)$mosyleOwnerId === (string)$iiqOwnerId) {
            $status = 'GOOD_LOAN_OUT';
        } elseif ($hasTicket && $iiqOwnerId && !$mosyleOwnerId) {
            $status = 'NEEDS_ASSIGN';
        } elseif ($hasTicket && $mosyleOwnerId && $iiqOwnerId && (string)$mosyleOwnerId !== (string)$iiqOwnerId) {
            $status = 'BAD_MISMATCH';
        } elseif ($mosyleOwnerId && !$hasTicket) {
            $status = 'BAD_NO_TICKET';
        } else {
            $status = 'IDLE';
        }

        $r['loan_status'] = $status;
    }
    unset($r);

    echo json_encode([
        'pool_name' => $pool,
        'rows'      => $rows
    ], JSON_PRETTY_PRINT);
    exit;

} catch (Throwable $e) {
    ldevlog("EXCEPTION", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'error'  => 'server',
        'detail' => 'Loaner device data unavailable.'
    ]);
    exit;
}
