<?php
// ============================================================================
// MUSKY - fetch_class_devices.php
// FINAL BUILD — CLEAN, FAST, OWNER_USERID-ONLY VERSION
// ----------------------------------------------------------------------------
// • NO health logic
// • NO extra_data parsing
// • NO device_name extraction
// • NO enrichment of any kind
// • YES tags from device_history.tags (CSV → array)
// • YES open_direct_device_link from devices table
// • YES latest non-null owner_userid snapshot per device
// ============================================================================

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';
if (!defined('NORA_CONNECT_NO_AUTO')) {
    define('NORA_CONNECT_NO_AUTO', true);
}
if (!defined('NORA_CONNECT_THROW')) {
    define('NORA_CONNECT_THROW', true);
}
require_once __DIR__ . '/../../Functions/nora_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function class_explorer_devices_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function class_explorer_devices_is_elevated(array $allowed): bool {
    return (bool)array_intersect($allowed, [
        'ALL_TOOLS',
        'ADMIN_PANEL',
        'DEVICE_MANAGER',
        'HDK-SUPERVISOR-LII',
        'HDK-SUPERVISOR-ADMIN',
    ]);
}

function class_explorer_devices_username_from_email(string $email): string {
    $email = strtolower(trim($email));
    return strpos($email, '@') !== false ? strtok($email, '@') : $email;
}

function class_explorer_devices_session_username(): string {
    $email = (string)($_SESSION['musky_user']['email'] ?? $_SESSION['username'] ?? '');
    return class_explorer_devices_username_from_email($email);
}

function class_explorer_devices_valid_username(string $username): bool {
    return preg_match('/\A[a-z0-9._-]{1,80}\z/i', $username) === 1;
}

function class_explorer_devices_valid_class_id(string $classId): bool {
    return $classId !== ''
        && mb_strlen($classId) <= 128
        && preg_match('/[\x00-\x1F\x7F]/', $classId) !== 1;
}

function class_explorer_devices_can_access_class(PDO $pdo, string $classId, string $teacherUsername, bool $isElevated): bool {
    if ($isElevated) {
        return true;
    }
    if ($teacherUsername === '' || !class_explorer_devices_valid_username($teacherUsername)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM nora_class_teachers
        WHERE class_id = :cid
          AND LOWER(teacher_username) = LOWER(:teacher)
        LIMIT 1
    ");
    $stmt->execute([
        ':cid' => $classId,
        ':teacher' => $teacherUsername,
    ]);

    return (bool)$stmt->fetchColumn();
}

// ----------------------------------------------------------------------------
// Access enforcement
// ----------------------------------------------------------------------------
$allowed = musky_require_general_admin_access(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['CLASS_MANAGER'],
    [
        'response' => 'json',
        'status' => 403,
        'message' => 'Missing required Musky permissions.',
        'payload' => [
            'error' => 'access_denied',
            'detail' => 'Missing required Musky permissions.',
        ],
    ]
);

$is_admin = class_explorer_devices_is_elevated($allowed);

// ----------------------------------------------------------------------------
// Logging
// ----------------------------------------------------------------------------
$LOGFILE = (isset($LOG_PATH) && $LOG_PATH)
    ? rtrim($LOG_PATH, '/') . '/musky_class_debug.log'
    : '/tmp/musky_class_debug.log';

function cflog(string $msg): void {
    global $LOGFILE;
    $msg = str_replace(["\r", "\n"], ' ', $msg);
    @file_put_contents(
        $LOGFILE,
        "[" . date('Y-m-d H:i:s') . "] <fetch_devices> $msg\n",
        FILE_APPEND
    );
}

try {

    // ------------------------------------------------------------------------
    // INPUTS
    // ------------------------------------------------------------------------
    $classId    = trim((string)($_GET['class_id'] ?? ''));
    $debugMode  = (isset($_GET['debug']) && $_GET['debug'] === '1');
    $spoofEmail = trim((string)($_GET['as'] ?? ''));

    if ($debugMode && !$is_admin) {
        class_explorer_devices_json(['error' => 'forbidden'], 403);
    }

    if ($spoofEmail !== '' && !$is_admin) {
        cflog('Denied non-elevated spoof attempt.');
        class_explorer_devices_json(['error' => 'forbidden'], 403);
    }

    if (!class_explorer_devices_valid_class_id($classId)) {
        class_explorer_devices_json(['error' => 'invalid_class_id'], 400);
    }

    // ------------------------------------------------------------------------
    // CONNECT TO DB
    // ------------------------------------------------------------------------
    cflog("Attempting Nora DB connection…");
    $pdo = nora_connect();
    cflog("Connected to Nora; class={$classId} (spoof=" . ($spoofEmail ?: 'none') . ")");

    $effectiveUsername = $spoofEmail !== ''
        ? class_explorer_devices_username_from_email($spoofEmail)
        : class_explorer_devices_session_username();

    if (!class_explorer_devices_can_access_class($pdo, $classId, $effectiveUsername, $is_admin)) {
        cflog("Denied class access class={$classId} user={$effectiveUsername}");
        class_explorer_devices_json(['error' => 'forbidden'], 403);
    }

    // ------------------------------------------------------------------------
    // 1️⃣ LOAD ROSTER
    // ------------------------------------------------------------------------
    $q = $pdo->prepare("
        SELECT DISTINCT student_username AS username
        FROM nora_class_students
        WHERE class_id = :cid
        ORDER BY student_username
    ");
    $q->execute([':cid' => $classId]);
    $students = $q->fetchAll(PDO::FETCH_COLUMN);

    $count = count($students);
    cflog("Class {$classId} → {$count} students found");

    if ($count === 0) {
        echo json_encode([
            'rows'                         => [],
            'students_missing'             => [],
            'students_without_device_count'=> 0
        ]);
        exit;
    }

    $studentsLower = array_values(array_unique(array_map('strtolower', $students)));

    // ------------------------------------------------------------------------
    // 2️⃣ GET DEVICES — LATEST NON-NULL owner_userid
    // ------------------------------------------------------------------------
    // Join through the class roster instead of building a large IN (...) list.
    // We also fall back to devices.asset_tag because the freshest history row
    // is sometimes missing an asset tag even when the device record has one.
    $sql = "
        SELECT
            dh.owner_userid  AS userid,
            dh.owner_name    AS username,
            dh.owner_email   AS useremail,
            dh.serial_number AS serial,
            COALESCE(NULLIF(dh.asset_tag, ''), NULLIF(d.asset_tag, '')) AS asset_tag,
            dh.tags          AS tags,
            dh.last_seen     AS last_seen,
            dh.snapshot_time AS snapshot_time,
            d.open_direct_device_link AS open_direct_device_link,
            d.device_model AS device_model,
            d.model AS model

        FROM device_history dh

        INNER JOIN (
            SELECT
                dh2.serial_number,
                MAX(dh2.snapshot_time) AS snapshot_time
            FROM nora_class_students cs
            INNER JOIN device_history dh2 FORCE INDEX (idx_hist_owneruserid_serial_snapshot)
              ON dh2.owner_userid = (cs.student_username COLLATE utf8mb4_general_ci)
            WHERE cs.class_id = :cid
              AND dh2.owner_userid IS NOT NULL
              AND dh2.owner_userid != ''
            GROUP BY dh2.serial_number
        ) latest
          ON latest.serial_number = dh.serial_number
         AND latest.snapshot_time = dh.snapshot_time

        LEFT JOIN devices d
          ON d.serial_number = dh.serial_number
    ";

    cflog("Executing device lookup. USER_COUNT=" . count($studentsLower));
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $classId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    cflog("Device rows returned: " . count($rows));

    // ------------------------------------------------------------------------
    // 3️⃣ NORMALIZATION ONLY — NO HEALTH LOGIC
    // ------------------------------------------------------------------------
    foreach ($rows as &$r) {

        $tags = [];
        if (!empty($r['tags'])) {
            $tags = array_filter(
                array_map('trim', explode(',', $r['tags']))
            );
        }
        $r['tags'] = array_values($tags);

        unset($r['useremail']);
        if (!$is_admin) {
            unset($r['open_direct_device_link']);
        }

    }
    unset($r);

    // ------------------------------------------------------------------------
    // 4️⃣ MISSING STUDENTS
    // ------------------------------------------------------------------------
    $foundUsernames = [];
    foreach ($rows as $r) {
        $foundUsernames[] = strtolower($r['userid']);
    }

    $missing      = array_values(array_diff($studentsLower, $foundUsernames));
    $missingCount = count($missing);

    // ------------------------------------------------------------------------
    // 5️⃣ DEBUG OUTPUT
    // ------------------------------------------------------------------------
    if ($debugMode) {
        cflog("DEBUG MODE — returning data");
        echo json_encode([
            'debug'                        => true,
            'class_id'                     => $classId,
            'spoof_user'                   => $spoofEmail ?: ($_SESSION['musky_user']['email'] ?? 'unknown'),
            'student_total'                => $count,
            'device_count'                 => count($rows),
            'students_missing'             => $missing,
            'students_without_device_count'=> $missingCount,
            'rows'                         => $rows
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // ------------------------------------------------------------------------
    // 6️⃣ NORMAL OUTPUT
    // ------------------------------------------------------------------------
    cflog("Returned ".count($rows)." device rows; missing ".$missingCount);

    echo json_encode([
        'rows'                         => $rows,
        'students_missing'             => $missing,
        'students_without_device_count'=> $missingCount
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {

    cflog("EXCEPTION: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'error'  => 'server'
    ]);
}
