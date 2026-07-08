<?php
// ============================================================================
// MUSKY - fetch_filters.php (Secure + Enhanced for ClassExplorer Spoof + Sorting)
// ----------------------------------------------------------------------------
// Provides dropdown data for Class Explorer:
//   ?mode=buildings, ?mode=teachers, ?mode=classes, ?mode=teacher, ?mode=all_teachers
// Adds:
//   • Proper school sorting (Elementary → Middle → High)
//   • HOMEROOM prioritization
//   • Admin-only teacher spoof support using configured teacher email domain
//   • Enforces Musky session + permission check
// Logs to $LOG_PATH or /tmp/musky_class_debug.log if undefined.
// ============================================================================

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

function class_explorer_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function class_explorer_is_elevated(array $allowed): bool {
    return (bool)array_intersect($allowed, [
        'ALL_TOOLS',
        'ADMIN_PANEL',
        'DEVICE_MANAGER',
        'HDK-SUPERVISOR-LII',
        'HDK-SUPERVISOR-ADMIN',
    ]);
}

function class_explorer_username_from_email(string $email): string {
    $email = strtolower(trim($email));
    return strpos($email, '@') !== false ? strtok($email, '@') : $email;
}

function class_explorer_valid_username(string $username): bool {
    return preg_match('/\A[a-z0-9._-]{1,80}\z/i', $username) === 1;
}

function class_explorer_session_username(): string {
    $email = (string)($_SESSION['musky_user']['email'] ?? $_SESSION['username'] ?? '');
    return class_explorer_username_from_email($email);
}

function class_explorer_clean_param(?string $value, int $maxLen = 120): string {
    $value = trim((string)$value);
    $value = str_replace(["\r", "\n"], ' ', $value);
    return mb_substr($value, 0, $maxLen);
}

function class_explorer_teacher_email_domain_sql(PDO $pdo): string {
    return $pdo->quote(musky_identity_teacher_email_domain());
}

// -----------------------------------------------------------------------------
// Access enforcement
// -----------------------------------------------------------------------------
$allowed = musky_require_general_admin_access(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['CLASS_MANAGER'],
    [
        'response' => 'json',
        'status' => 403,
        'message' => 'Missing required Musky permissions.',
        'payload' => ['error' => 'access_denied', 'detail' => 'Missing required Musky permissions.'],
    ]
);
$isElevated = class_explorer_is_elevated($allowed);

// -----------------------------------------------------------------------------
// Logging helper
// -----------------------------------------------------------------------------
$LOGFILE = (isset($LOG_PATH) && $LOG_PATH)
    ? rtrim($LOG_PATH, '/').'/musky_class_debug.log'
    : '/tmp/musky_class_debug.log';

function cflog(string $msg): void {
    global $LOGFILE;
    $msg = str_replace(["\r", "\n"], ' ', $msg);
    file_put_contents($LOGFILE, "[" . date('Y-m-d H:i:s') . "] <fetch_filters> $msg\n", FILE_APPEND);
}

// -----------------------------------------------------------------------------
try {
    cflog("Attempting DB connection...");
    $pdo = nora_connect();
    cflog("✅ Connected to Nora");
    $teacherDomainSql = class_explorer_teacher_email_domain_sql($pdo);

    $mode = $_GET['mode'] ?? '';
    cflog("Mode=$mode");

    // ------------------------------------------------------------------------
    // BUILDINGS (sorted Elementary → Middle → High)
    // ------------------------------------------------------------------------
    if ($mode === 'buildings') {
        if (!$isElevated) {
            class_explorer_json(['error' => 'forbidden'], 403);
        }

        $sql = "SELECT DISTINCT TRIM(location) AS location
                FROM nora_classes
                WHERE location IS NOT NULL AND TRIM(location) <> ''
                ORDER BY location";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $rows = array_filter($rows, fn($x) => trim($x) !== ''); // 🩹 Patch 1 — remove blanks
        $rows = array_values($rows);

        // Sort manually: Elementary first, Middle second, High last
        usort($rows, function($a, $b) {
            $rank = function($name) {
                $name = strtolower($name);
                if (str_contains($name, 'high')) return 3;
                if (str_contains($name, 'middle')) return 2;
                return 1; // Elementary default
            };
            $ra = $rank($a);
            $rb = $rank($b);
            return ($ra === $rb) ? strcmp($a, $b) : $ra <=> $rb;
        });

        cflog("buildings → ".count($rows)." found");
        echo json_encode(['buildings' => $rows]);
        exit;
    }

    // ------------------------------------------------------------------------
    // ALL TEACHERS (for Spoof dropdown)
    // ------------------------------------------------------------------------
    if ($mode === 'all_teachers') {
        if (!$isElevated) {
            class_explorer_json(['error' => 'forbidden'], 403);
        }

        $sql = "
            SELECT DISTINCT
                t.teacher_username,
                COALESCE(o.full_name, t.teacher_name, t.teacher_username) AS teacher_name
            FROM nora_class_teachers t
            LEFT JOIN owners o
              ON LOWER(o.email COLLATE utf8mb4_general_ci) =
                 CONCAT(LOWER(t.teacher_username COLLATE utf8mb4_general_ci), {$teacherDomainSql})
            ORDER BY teacher_name
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        cflog("all_teachers → ".count($rows)." total");
        echo json_encode(['teachers' => $rows]);
        exit;
    }

    // ------------------------------------------------------------------------
    // TEACHERS BY BUILDING
    // ------------------------------------------------------------------------
    if ($mode === 'teachers') {
        if (!$isElevated) {
            class_explorer_json(['error' => 'forbidden'], 403);
        }

        $b = class_explorer_clean_param($_GET['building'] ?? '');
        if ($b === '') {
            class_explorer_json(['teachers' => []]);
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                t.teacher_username,
                COALESCE(o.full_name, t.teacher_name, t.teacher_username) AS teacher_name
            FROM nora_class_teachers t
            JOIN nora_classes c ON c.class_id=t.class_id
            LEFT JOIN owners o
              ON LOWER(o.email COLLATE utf8mb4_general_ci) =
                 CONCAT(LOWER(t.teacher_username COLLATE utf8mb4_general_ci), {$teacherDomainSql})
            WHERE c.location=:b
            ORDER BY teacher_name
        ");
        $stmt->execute([':b' => $b]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cflog("teachers → ".count($rows)." for building '$b'");
        echo json_encode(['teachers' => $rows]);
        exit;
    }

    // ------------------------------------------------------------------------
    // CLASSES FOR TEACHER + BUILDING
    // ------------------------------------------------------------------------
    if ($mode === 'classes') {
        if (!$isElevated) {
            class_explorer_json(['error' => 'forbidden'], 403);
        }

        $b = class_explorer_clean_param($_GET['building'] ?? '');
        $t = class_explorer_username_from_email((string)($_GET['teacher'] ?? ''));
        if ($b === '' || !class_explorer_valid_username($t)) {
            class_explorer_json(['classes' => []]);
        }

        $stmt = $pdo->prepare("
            SELECT
                c.class_id,
                c.class_name,
                c.location,
                COALESCE(o.full_name, t.teacher_name, t.teacher_username) AS teacher_fullname
            FROM nora_classes c
            JOIN nora_class_teachers t ON t.class_id=c.class_id
            LEFT JOIN owners o
              ON LOWER(o.email COLLATE utf8mb4_general_ci) =
                 CONCAT(LOWER(t.teacher_username COLLATE utf8mb4_general_ci), {$teacherDomainSql})
            WHERE c.location=:b AND LOWER(t.teacher_username)=LOWER(:t)
            ORDER BY CASE WHEN c.class_name LIKE 'HOMEROOM%' THEN 0 ELSE 1 END, c.class_name
        ");
        $stmt->execute([':b' => $b, ':t' => $t]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        cflog("classes → ".count($rows)." for $b / $t");
        echo json_encode(['classes' => $rows]);
        exit;
    }
    // ------------------------------------------------------------------------
    // CLASSES FOR CURRENT LOGGED-IN TEACHER (Spoof-aware)
    // ------------------------------------------------------------------------
    if ($mode === 'teacher') {

        $sessionEmail = $_SESSION['musky_user']['email'] ?? '';
        $spoofEmail   = $_GET['as'] ?? '';

        if ($spoofEmail && !$isElevated) {
            cflog("teacher mode → denied spoof attempt");
            class_explorer_json(['error' => 'forbidden'], 403);
        }

        $effectiveEmail = (string)($spoofEmail ?: $sessionEmail);
        $teacherUsername = class_explorer_username_from_email($effectiveEmail);

        if ($teacherUsername === '' || !class_explorer_valid_username($teacherUsername)) {
            cflog("teacher mode → no effective email");
            echo json_encode(['classes' => []]);
            exit;
        }

        cflog("teacher mode → username='$teacherUsername' (spoof=" . ($spoofEmail ? 'yes' : 'no') . ")");

        $stmt = $pdo->prepare("
            SELECT
                c.class_id,
                c.class_name,
                c.location,
                COALESCE(o.full_name, t.teacher_name, t.teacher_username) AS teacher_fullname
            FROM nora_classes c
            JOIN nora_class_teachers t ON t.class_id=c.class_id
            LEFT JOIN owners o
              ON LOWER(o.email COLLATE utf8mb4_general_ci) =
                 CONCAT(LOWER(t.teacher_username COLLATE utf8mb4_general_ci), {$teacherDomainSql})
            WHERE LOWER(t.teacher_username)=:u
            ORDER BY CASE WHEN c.class_name LIKE 'HOMEROOM%' THEN 0 ELSE 1 END, c.class_name
        ");
        $stmt->execute([':u' => $teacherUsername]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        cflog("teacher mode → ".count($rows)." classes found");

        echo json_encode(['classes' => $rows]);
        exit;
    }

    // ------------------------------------------------------------------------
    // Default fallback
    // ------------------------------------------------------------------------
    cflog("unknown mode '$mode'");
    echo json_encode([]);
    exit;

} catch (Throwable $e) {
    cflog("❌ Exception: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
    exit;
}
