<?php
// ============================================================================
// NORA - NoraWeb.Dashboard.php
// ----------------------------------------------------------------------------
// Rebuilt to reflect the tables and subsystems that are actually active in
// modern NORA rather than the older November-era assumptions.
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';

date_default_timezone_set('America/New_York');

if (!isset($_SESSION['musky_user'])) {
    http_response_code(403);
    echo "⛔ Access Denied — Not logged in.";
    exit;
}

$toolRequired = 'ADMIN_PANEL';
$allowedTools = explode(',', $_SESSION['musky_user']['allowed_tools'] ?? '');
if (!in_array($toolRequired, $allowedTools, true) && !in_array('ALL_TOOLS', $allowedTools, true)) {
    http_response_code(403);
    echo "⛔ Access Denied — Missing Required Tool: {$toolRequired}";
    exit;
}

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$email = $prefs['email'] ?? ($_SESSION['musky_user']['email'] ?? '');
$isFullMode = (isset($_GET['full']) && $_GET['full'] === '1');

$configPath = dirname(__DIR__, 2) . '/nora_config.json';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo "<h1>❌ Missing nora_config.json</h1>";
    exit;
}

$config = json_decode(file_get_contents($configPath), true);
if (!$config || !isset($config['host'], $config['user'], $config['pass'], $config['name'])) {
    http_response_code(500);
    echo "<h1>❌ Invalid nora_config.json</h1>";
    exit;
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'] ?? 3306,
        $config['name']
    );

    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);

    try {
        $pdo->exec('SET SESSION max_statement_time = 5');
    } catch (Throwable $e) {
        try {
            $pdo->exec('SET SESSION max_execution_time = 5000');
        } catch (Throwable $inner) {
        }
    }
} catch (Throwable $t) {
    error_log('[NoraWeb.Dashboard] DB connection failed: ' . $t->getMessage());
    http_response_code(500);
    echo "<h1>❌ DB connection failed.</h1><p>Check the server logs for details.</p>";
    exit;
}

// ============================================================================
// Helpers
// ============================================================================

function db_scalar(PDO $pdo, string $sql, array $params = [], $default = null) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function safe_int($value, int $default = 0): int
{
    return is_numeric($value) ? (int)$value : $default;
}

function fmt_num($value): string
{
    if ($value === null || $value === '') {
        return '–';
    }

    if (is_numeric($value)) {
        return number_format((float)$value);
    }

    return htmlspecialchars((string)$value);
}

function fmt_dt(?string $value): string
{
    return $value ? htmlspecialchars($value) : '–';
}

function fmt_ago(?string $value): string
{
    if (!$value) {
        return '–';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return '–';
    }

    $delta = time() - $ts;
    if ($delta < 60) {
        return $delta . 's ago';
    }

    $minutes = (int)floor($delta / 60);
    if ($minutes < 60) {
        return $minutes . 'm ago';
    }

    $hours = (int)floor($minutes / 60);
    if ($hours < 24) {
        return $hours . 'h ago';
    }

    $days = (int)floor($hours / 24);
    return $days . 'd ago';
}

function freshness_badge(?string $value, int $freshHours, int $warnHours): array
{
    if (!$value) {
        return ['secondary', 'Unknown'];
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return ['secondary', 'Unknown'];
    }

    $ageHours = (time() - $ts) / 3600;
    if ($ageHours <= $freshHours) {
        return ['success', 'Fresh'];
    }
    if ($ageHours <= $warnHours) {
        return ['warning', 'Aging'];
    }
    return ['danger', 'Stale'];
}

function read_last_lines(string $file, int $lines = 20): array
{
    if (!is_file($file) || $lines <= 0) {
        return [];
    }

    $fp = fopen($file, 'rb');
    if (!$fp) {
        return [];
    }

    $buffer = '';
    $chunkSize = 4096;
    fseek($fp, 0, SEEK_END);

    while (ftell($fp) > 0 && substr_count($buffer, "\n") <= $lines) {
        $seek = max(ftell($fp) - $chunkSize, 0);
        $read = ftell($fp) - $seek;
        fseek($fp, $seek, SEEK_SET);
        $buffer = fread($fp, $read) . $buffer;
        fseek($fp, $seek, SEEK_SET);
        if ($seek === 0) {
            break;
        }
    }

    fclose($fp);
    $rows = explode("\n", trim($buffer));
    return array_slice($rows, -$lines);
}

function jsonl_tail_messages(string $file, int $lines = 10): array
{
    $out = [];
    foreach (read_last_lines($file, $lines * 3) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $json = json_decode($line, true);
        if (!is_array($json)) {
            $out[] = $line;
            continue;
        }

        $stamp = isset($json['timestamp']) ? date('m-d H:i:s', strtotime((string)$json['timestamp'])) : '--';
        $level = strtoupper((string)($json['level'] ?? 'INFO'));
        $message = (string)($json['message'] ?? json_encode($json, JSON_UNESCAPED_SLASHES));
        $out[] = "[{$stamp}] {$level} {$message}";
    }

    return array_slice($out, -$lines);
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $cache[strtolower((string)$name)] = true;
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }

    return isset($cache[strtolower($table)]);
}

function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $key = strtolower($table);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $cache[$key] = [];
    if (!table_exists($pdo, $table)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['Field'])) {
                $cache[$key][strtolower((string)$row['Field'])] = true;
            }
        }
    } catch (Throwable $e) {
        $cache[$key] = [];
    }

    return $cache[$key];
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    return isset(table_columns($pdo, $table)[strtolower($column)]);
}

function first_available_column(PDO $pdo, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (column_exists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function table_row_estimates(PDO $pdo): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    try {
        $stmt = $pdo->query("
            SELECT table_name, table_rows, update_time, create_time
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[strtolower((string)$row['table_name'])] = $row;
        }
    } catch (Throwable $e) {
        $cache = [];
    }

    return $cache;
}

function exact_table_count(PDO $pdo, string $table): ?int
{
    static $cache = [];
    $key = strtolower($table);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!table_exists($pdo, $table)) {
        $cache[$key] = null;
        return null;
    }

    $cache[$key] = safe_int(db_scalar($pdo, "SELECT COUNT(*) FROM `{$table}`", [], null), 0);
    return $cache[$key];
}

function estimated_table_count(PDO $pdo, string $table): ?int
{
    $meta = table_row_estimates($pdo);
    $key = strtolower($table);

    if (!isset($meta[$key])) {
        return null;
    }

    return isset($meta[$key]['table_rows']) ? (int)$meta[$key]['table_rows'] : null;
}

function table_latest_activity(PDO $pdo, string $table, array $candidates = []): ?string
{
    static $cache = [];

    $key = strtolower($table) . '|' . implode(',', array_map('strtolower', $candidates));
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $cache[$key] = null;
    if (!table_exists($pdo, $table)) {
        return null;
    }

    $column = first_available_column($pdo, $table, $candidates);
    if ($column) {
        $value = db_scalar($pdo, "SELECT MAX(`{$column}`) FROM `{$table}`", [], null);
        if ($value) {
            $cache[$key] = (string)$value;
            return $cache[$key];
        }
    }

    $meta = table_row_estimates($pdo);
    $tableKey = strtolower($table);
    if (isset($meta[$tableKey])) {
        $cache[$key] = $meta[$tableKey]['update_time'] ?: ($meta[$tableKey]['create_time'] ?? null);
    }

    return $cache[$key];
}

function metric_row(string $label, $value, ?string $sub = null): array
{
    return [
        'label' => $label,
        'value' => $value,
        'sub' => $sub,
    ];
}

function card_spec(
    string $title,
    string $accent,
    array $metrics,
    ?string $stamp = null,
    ?array $badge = null,
    ?string $foot = null
): array {
    return compact('title', 'accent', 'metrics', 'stamp', 'badge', 'foot');
}

// ============================================================================
// Quick actions
// ============================================================================

$actionNotice = null;
if (!empty($_GET['action']) && table_exists($pdo, 'nora_errands')) {
    $extra = null;
    $action = $_GET['action'];
    if ($action === 'run_import') {
        $extra = 'REQUEST=RUN_IMPORTER NOW';
    } elseif ($action === 'run_maint_recent') {
        $extra = 'REQUEST=RUN_MAINTENANCE MODE=RECENT';
    } elseif ($action === 'run_maint_full') {
        $extra = 'REQUEST=RUN_MAINTENANCE MODE=FULL_DB';
    }

    if ($extra !== null) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO nora_errands
                  (TaskPriority, TaskRepeat, TaskRepeatHowMany, Status,
                   SubmissionDateTime, MOSBasicRequest, SlackRequest, IIQRequest, NoraRequest, CustomRequest,
                   UDID, DeviceSerial, AssetTag, Submitter, DeviceOwner, ExtraDataField01)
                VALUES
                  (5, 'FALSE', 0, 'submitted',
                   :submitted_at, 'FALSE', 'FALSE', 'FALSE', 'TRUE', 'FALSE',
                   'SYSTEM', 'SYSTEM', NULL, :submitter, NULL, :extra)
            ");
            $stmt->execute([
                ':submitted_at' => date('Y-m-d H:i:s'),
                ':submitter' => $email ?: 'unknown',
                ':extra' => $extra,
            ]);
            $actionNotice = [true, "Queued: {$extra}"];
        } catch (Throwable $e) {
            error_log('[NoraWeb.Dashboard] quick action failed: ' . $e->getMessage());
            $actionNotice = [false, 'Failed to queue action.'];
        }
    } else {
        $actionNotice = [false, 'Unknown quick action.'];
    }
}

// ============================================================================
// Top-level state
// ============================================================================

$dbSizeMB = db_scalar(
    $pdo,
    "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
     FROM information_schema.tables
     WHERE table_schema = DATABASE()",
    [],
    null
);
$tableMeta = table_row_estimates($pdo);
$tableCount = count($tableMeta);

$devicesCount = exact_table_count($pdo, 'devices');
$ownersCount = exact_table_count($pdo, 'owners');
$importsCount = exact_table_count($pdo, 'imports');
$deviceTagsCount = exact_table_count($pdo, 'device_tags');
$deviceHistoryCount = estimated_table_count($pdo, 'device_history');
$deviceHistoryLast = table_latest_activity($pdo, 'device_history', ['last_seen', 'snapshot_time', 'imported_at', 'import_time']);
$importsLast = table_latest_activity($pdo, 'imports', ['imported_at', 'created_at']);
$ownersLast = table_latest_activity($pdo, 'owners', ['updated_at', 'last_seen', 'created_at']);

$active48h = null;
$orphanDevices = null;
$modelBreakdown = [];

if (table_exists($pdo, 'devices')) {
    $deviceDateCol = first_available_column($pdo, 'devices', ['last_seen', 'updated_at', 'created_at']);
    if ($deviceDateCol) {
        $active48h = safe_int(
            db_scalar($pdo, "SELECT COUNT(*) FROM devices WHERE `{$deviceDateCol}` >= NOW() - INTERVAL 48 HOUR", [], null),
            0
        );
    }

    if (column_exists($pdo, 'devices', 'owner_id')) {
        $orphanDevices = safe_int(
            db_scalar(
                $pdo,
                "SELECT COUNT(*)
                 FROM devices d
                 LEFT JOIN owners o ON o.id = d.owner_id
                 WHERE d.owner_id IS NULL OR o.id IS NULL",
                [],
                null
            ),
            0
        );
    }

    if ($isFullMode && column_exists($pdo, 'devices', 'model')) {
        try {
            $rows = $pdo->query("
                SELECT COALESCE(model, 'Unknown model') AS model_name, COUNT(*) AS row_count
                FROM devices
                GROUP BY model_name
                ORDER BY row_count DESC, model_name ASC
            ")->fetchAll();
            foreach ($rows as $row) {
                $modelBreakdown[(string)$row['model_name']] = (int)$row['row_count'];
            }
        } catch (Throwable $e) {
            $modelBreakdown = [];
        }
    }
}

$ipEventsCount = exact_table_count($pdo, 'ip_events');
$geoEventsCount = exact_table_count($pdo, 'geo_events');
$distinctKnownIps = table_exists($pdo, 'ip_events')
    ? safe_int(db_scalar($pdo, 'SELECT COUNT(DISTINCT ip_address) FROM ip_events', [], null), 0)
    : null;
$distinctGeoIps = table_exists($pdo, 'geo_events') && column_exists($pdo, 'geo_events', 'ip_address')
    ? safe_int(db_scalar($pdo, "SELECT COUNT(DISTINCT ip_address) FROM geo_events WHERE ip_address IS NOT NULL AND ip_address <> ''", [], null), 0)
    : null;
$ipEventsLast = table_latest_activity($pdo, 'ip_events', ['ip_last_seen', 'created_at']);
$geoEventsLast = table_latest_activity($pdo, 'geo_events', ['event_time', 'created_at']);
$recentGeo24h = table_exists($pdo, 'geo_events')
    ? safe_int(db_scalar($pdo, 'SELECT COUNT(*) FROM geo_events WHERE event_time >= NOW() - INTERVAL 24 HOUR', [], null), 0)
    : null;
$recentIp24h = table_exists($pdo, 'ip_events')
    ? safe_int(db_scalar($pdo, 'SELECT COUNT(*) FROM ip_events WHERE ip_last_seen >= NOW() - INTERVAL 24 HOUR', [], null), 0)
    : null;

$classesCount = exact_table_count($pdo, 'nora_classes');
$classStudentsCount = exact_table_count($pdo, 'nora_class_students');
$classTeachersCount = exact_table_count($pdo, 'nora_class_teachers');
$classesLast = table_latest_activity($pdo, 'nora_classes', ['imported_at', 'updated_at', 'created_at']);

$ticketTidbitsCount = exact_table_count($pdo, 'IIQTicketTidBits');
$ticketAssetsCount = exact_table_count($pdo, 'IIQTicketTidBitsAssets');
$loanersCount = exact_table_count($pdo, 'iiq_loaners');
$iiqLast = table_latest_activity($pdo, 'iiq_loaners', ['updated_at', 'created_at', 'issue_date']);

$pandaChargesCount = exact_table_count($pdo, 'panda_charges');
$pandaStatusCount = exact_table_count($pdo, 'panda_charge_status_history');
$skywardCoverageCount = exact_table_count($pdo, 'Skyward_ThisnThat');
$pandaLast = table_latest_activity($pdo, 'panda_charges', ['UpdatedAt', 'updated_at', 'OccurredAt', 'CreatedAt', 'created_at']);

$inventoryStockCount = exact_table_count($pdo, 'inventory_stock');
$inventoryTxCount = exact_table_count($pdo, 'inventory_transactions');
$inventoryLocationsCount = exact_table_count($pdo, 'inventory_locations');
$inventoryLast = table_latest_activity($pdo, 'inventory_transactions', ['updated_at', 'created_at', 'OccurredAt', 'TransactionDate']);

$apiLogsCount = exact_table_count($pdo, 'nora_api_logs');
$apiClientsCount = exact_table_count($pdo, 'nora_api_clients');
$apiTokensCount = exact_table_count($pdo, 'nora_api_tokens');
$apiLogsLast = table_latest_activity($pdo, 'nora_api_logs', ['timestamp', 'created_at']);
$apiTokenLast = table_latest_activity($pdo, 'nora_api_tokens', ['last_updated', 'created_at']);

$muskyUsersCount = exact_table_count($pdo, 'musky_users');
$muskyGroupCount = exact_table_count($pdo, 'musky_group_access');
$muskyUsersLast = table_latest_activity($pdo, 'musky_users', ['updated_at', 'last_login_at', 'created_at']);

$errandsTotal = exact_table_count($pdo, 'nora_errands');
$errandsByStatus = [];
$failureRatio = null;
$avgCompletionMin = null;
$requestMix = ['MOSBasic' => 0, 'Slack' => 0, 'IIQ' => 0, 'Nora' => 0, 'Custom' => 0];

if (table_exists($pdo, 'nora_errands')) {
    try {
        $rows = $pdo->query('SELECT Status, COUNT(*) AS c FROM nora_errands GROUP BY Status')->fetchAll();
        foreach ($rows as $row) {
            $errandsByStatus[(string)$row['Status']] = (int)$row['c'];
        }
    } catch (Throwable $e) {
        $errandsByStatus = [];
    }

    try {
        $recent = $pdo->query("
            SELECT SUM(Status = 'Complete') AS ok_rows, SUM(Status = 'Failed') AS fail_rows
            FROM (SELECT Status FROM nora_errands ORDER BY ErrandID DESC LIMIT 100) t
        ")->fetch();
        if ($recent) {
            $okRows = safe_int($recent['ok_rows'] ?? 0, 0);
            $failRows = safe_int($recent['fail_rows'] ?? 0, 0);
            if (($okRows + $failRows) > 0) {
                $failureRatio = round(($failRows / ($okRows + $failRows)) * 100, 1);
            }
        }
    } catch (Throwable $e) {
    }

    try {
        $avgSec = db_scalar(
            $pdo,
            "SELECT AVG(TIMESTAMPDIFF(SECOND, SubmissionDateTime, COALESCE(CompleteDateTime, LastAttemptDateTime)))
             FROM (
                SELECT SubmissionDateTime, CompleteDateTime, LastAttemptDateTime
                FROM nora_errands
                WHERE Status = 'Complete' AND CompleteDateTime IS NOT NULL
                ORDER BY ErrandID DESC
                LIMIT 25
             ) x",
            [],
            null
        );
        if ($avgSec !== null && $avgSec !== false) {
            $avgCompletionMin = round(((int)$avgSec) / 60, 1);
        }
    } catch (Throwable $e) {
    }

    try {
        $mix = $pdo->query("
            SELECT
                SUM(MOSBasicRequest <> 'FALSE') AS mos,
                SUM(SlackRequest = 'TRUE') AS slk,
                SUM(IIQRequest = 'TRUE') AS iiq,
                SUM(NoraRequest = 'TRUE') AS nor,
                SUM(CustomRequest = 'TRUE') AS cus
            FROM (
                SELECT MOSBasicRequest, SlackRequest, IIQRequest, NoraRequest, CustomRequest
                FROM nora_errands
                ORDER BY ErrandID DESC
                LIMIT 500
            ) t
        ")->fetch();
        if ($mix) {
            $requestMix = [
                'MOSBasic' => safe_int($mix['mos'] ?? 0, 0),
                'Slack' => safe_int($mix['slk'] ?? 0, 0),
                'IIQ' => safe_int($mix['iiq'] ?? 0, 0),
                'Nora' => safe_int($mix['nor'] ?? 0, 0),
                'Custom' => safe_int($mix['cus'] ?? 0, 0),
            ];
        }
    } catch (Throwable $e) {
    }
}

$lastImportAt = $importsLast ?: $deviceHistoryLast;
$lastImportSource = $importsLast ? 'imports.imported_at' : ($deviceHistoryLast ? 'device_history.last_seen' : null);

$daemonStatus = ['label' => 'Unknown', 'since' => null, 'detail' => null, 'ok' => false];
$daemonTail = [];
$importTail = [];
$maintenanceTail = [];

$logDir = !empty($config['log_path']) ? rtrim($config['log_path'], '/') : null;
$daemonCandidates = [];
if ($logDir) {
    $daemonCandidates = [
        $logDir . '/nora_worker.log',
        $logDir . '/NoraWork.Daemon.log',
    ];
}

if (table_exists($pdo, 'nora_daemon_heartbeat') && column_exists($pdo, 'nora_daemon_heartbeat', 'last_seen')) {
    $daemonSeen = db_scalar($pdo, 'SELECT MAX(last_seen) FROM nora_daemon_heartbeat', [], null);
    if ($daemonSeen) {
        $daemonStatus = [
            'label' => 'Heartbeat',
            'since' => $daemonSeen,
            'detail' => 'nora_daemon_heartbeat',
            'ok' => (strtotime((string)$daemonSeen) > time() - 300),
        ];
    }
}

if (!$daemonStatus['since']) {
    foreach ($daemonCandidates as $candidate) {
        if (is_file($candidate)) {
            $daemonStatus = [
                'label' => 'Log activity',
                'since' => date('Y-m-d H:i:s', filemtime($candidate)),
                'detail' => basename($candidate),
                'ok' => filemtime($candidate) > time() - 300,
            ];
            $daemonTail = read_last_lines($candidate, 18);
            break;
        }
    }
}

if ($daemonTail === []) {
    foreach ($daemonCandidates as $candidate) {
        if (is_file($candidate)) {
            $daemonTail = read_last_lines($candidate, 18);
            break;
        }
    }
}

if ($logDir) {
    $importJsonl = $logDir . '/NoraFeed.Import.jsonl';
    $maintenanceJsonl = $logDir . '/NoraClean.Maintenance.jsonl';
    if (is_file($importJsonl)) {
        $importTail = jsonl_tail_messages($importJsonl, 10);
    }
    if (is_file($maintenanceJsonl)) {
        $maintenanceTail = jsonl_tail_messages($maintenanceJsonl, 10);
    }
}

$seriesLabels = [];
$seriesValues = [];
if (table_exists($pdo, 'nora_errands')) {
    try {
        $rows = $pdo->query("
            SELECT DATE_FORMAT(SubmissionDateTime, '%Y-%m-%d %H:00:00') AS bucket, COUNT(*) AS c
            FROM nora_errands
            WHERE SubmissionDateTime >= NOW() - INTERVAL 24 HOUR
            GROUP BY bucket
            ORDER BY bucket ASC
        ")->fetchAll();
        foreach ($rows as $row) {
            $seriesLabels[] = (string)$row['bucket'];
            $seriesValues[] = (int)$row['c'];
        }
    } catch (Throwable $e) {
        $seriesLabels = [];
        $seriesValues = [];
    }
}

$importLabels = [];
$importCounts = [];
if (table_exists($pdo, 'imports')) {
    try {
        $rows = $pdo->query("
            SELECT imported_at, record_count, filename
            FROM imports
            ORDER BY imported_at DESC
            LIMIT 12
        ")->fetchAll();
        $rows = array_reverse($rows);
        foreach ($rows as $row) {
            $importLabels[] = substr((string)$row['imported_at'], 5, 11);
            $importCounts[] = (int)$row['record_count'];
        }
    } catch (Throwable $e) {
        $importLabels = [];
        $importCounts = [];
    }
}

$dormantCandidates = [
    'locations',
    'api_logs',
];

$legacyCandidates = [
    'api_clients',
    'users',
];

$dormantTables = [];
foreach ($dormantCandidates as $table) {
    if (!table_exists($pdo, $table)) {
        continue;
    }
    $count = exact_table_count($pdo, $table);
    $dormantTables[] = [
        'name' => $table,
        'rows' => $count,
        'empty' => ($count === 0),
    ];
}

$legacyTables = [];
foreach ($legacyCandidates as $table) {
    if (!table_exists($pdo, $table)) {
        continue;
    }
    $legacyTables[] = [
        'name' => $table,
        'rows' => exact_table_count($pdo, $table),
        'last' => table_latest_activity($pdo, $table, ['updated_at', 'created_at', 'last_used']),
    ];
}

$cards = [
    card_spec(
        'Core Archive',
        'archive',
        [
            metric_row('Devices', fmt_num($devicesCount), $active48h !== null ? fmt_num($active48h) . ' active in 48h' : null),
            metric_row('Owners', fmt_num($ownersCount), $orphanDevices !== null ? fmt_num($orphanDevices) . ' orphan devices' : null),
            metric_row('History rows', $deviceHistoryCount !== null ? '~' . fmt_num($deviceHistoryCount) : '–', 'information_schema estimate'),
            metric_row('Imports', fmt_num($importsCount), $lastImportSource),
            metric_row('Tag links', fmt_num($deviceTagsCount), null),
        ],
        $deviceHistoryLast ?: $importsLast,
        freshness_badge($deviceHistoryLast ?: $importsLast, 6, 24),
        'This is the historical spine of NORA: devices, owners, imports, tags, and the big device_history archive.'
    ),
    card_spec(
        'Network Signals',
        'network',
        [
            metric_row('IP events', fmt_num($ipEventsCount), $recentIp24h !== null ? fmt_num($recentIp24h) . ' touched in 24h' : null),
            metric_row('Distinct IPs', fmt_num($distinctKnownIps), null),
            metric_row('Geo events', fmt_num($geoEventsCount), $recentGeo24h !== null ? fmt_num($recentGeo24h) . ' in 24h' : null),
            metric_row('Geo rows with IP', fmt_num($distinctGeoIps), 'distinct IPs represented in geo_events'),
        ],
        max($ipEventsLast ?: '0000-00-00 00:00:00', $geoEventsLast ?: '0000-00-00 00:00:00') !== '0000-00-00 00:00:00'
            ? max($ipEventsLast ?: '0000-00-00 00:00:00', $geoEventsLast ?: '0000-00-00 00:00:00')
            : null,
        freshness_badge($ipEventsLast ?: $geoEventsLast, 12, 48),
        'This section finally reflects the new ip_events / geo_events pipeline instead of leaving those tables invisible.'
    ),
    card_spec(
        'Workflow Engine',
        'workflow',
        [
            metric_row('Errands total', fmt_num($errandsTotal), null),
            metric_row('Submitted', fmt_num($errandsByStatus['submitted'] ?? 0), null),
            metric_row('Processing', fmt_num($errandsByStatus['Processing'] ?? 0), null),
            metric_row('Failed (last 100)', $failureRatio !== null ? $failureRatio . '%' : '–', null),
            metric_row('Avg completion', $avgCompletionMin !== null ? number_format($avgCompletionMin, 1) . ' min' : '–', null),
        ],
        table_latest_activity($pdo, 'nora_errands', ['SubmissionDateTime', 'LastAttemptDateTime', 'CompleteDateTime']),
        freshness_badge(table_latest_activity($pdo, 'nora_errands', ['SubmissionDateTime', 'LastAttemptDateTime', 'CompleteDateTime']), 2, 12),
        'NORA now does more than imports. This card keeps the Musky/Nora work queue front and center.'
    ),
    card_spec(
        'Classrooms',
        'classes',
        [
            metric_row('Classes', fmt_num($classesCount), null),
            metric_row('Student links', fmt_num($classStudentsCount), null),
            metric_row('Teacher links', fmt_num($classTeachersCount), null),
        ],
        $classesLast,
        freshness_badge($classesLast, 168, 336),
        'Class mapping became its own real subsystem, so it deserves a dashboard lane instead of being buried in docs.'
    ),
    card_spec(
        'IIQ Cache',
        'iiq',
        [
            metric_row('Loaners', fmt_num($loanersCount), null),
            metric_row('Ticket tidbits', fmt_num($ticketTidbitsCount), null),
            metric_row('Ticket assets', fmt_num($ticketAssetsCount), null),
        ],
        $iiqLast ?: table_latest_activity($pdo, 'IIQTicketTidBits', ['updated_at', 'created_at', 'imported_at']),
        freshness_badge($iiqLast ?: table_latest_activity($pdo, 'IIQTicketTidBits', ['updated_at', 'created_at', 'imported_at']), 168, 720),
        'IncidentIQ is now visibly represented through cached tickets, assets, and the loaner-device mirror.'
    ),
    card_spec(
        'PANDA + Inventory',
        'finance',
        [
            metric_row('PANDA charges', fmt_num($pandaChargesCount), null),
            metric_row('Charge status rows', fmt_num($pandaStatusCount), null),
            metric_row('Skyward coverage rows', fmt_num($skywardCoverageCount), null),
            metric_row('Inventory transactions', fmt_num($inventoryTxCount), fmt_num($inventoryStockCount) . ' stock rows'),
            metric_row('Inventory locations', fmt_num($inventoryLocationsCount), null),
        ],
        $pandaLast ?: $inventoryLast,
        freshness_badge($pandaLast ?: $inventoryLast, 168, 720),
        'Charges, fee history, and the small inventory tables are active enough now to justify a dedicated financial / stock card.'
    ),
    card_spec(
        'API + Access',
        'api',
        [
            metric_row('API log rows', fmt_num($apiLogsCount), null),
            metric_row('API clients', fmt_num($apiClientsCount), null),
            metric_row('API tokens', fmt_num($apiTokensCount), $apiTokenLast ? 'token refreshed ' . fmt_ago($apiTokenLast) : null),
            metric_row('Musky users (MariaDB)', fmt_num($muskyUsersCount), null),
            metric_row('Group access rows', fmt_num($muskyGroupCount), null),
        ],
        $apiLogsLast ?: $apiTokenLast ?: $muskyUsersLast,
        freshness_badge($apiLogsLast ?: $apiTokenLast ?: $muskyUsersLast, 24, 168),
        'This card makes the newer API/auth tables visible and separates them from the older api_* leftovers.'
    ),
];

$overviewBadges = [
    [
        'label' => 'Archive',
        'badge' => freshness_badge($deviceHistoryLast ?: $importsLast, 6, 24),
        'detail' => $deviceHistoryLast ? fmt_ago($deviceHistoryLast) : 'no snapshot',
    ],
    [
        'label' => 'Daemon',
        'badge' => $daemonStatus['ok'] ? ['success', 'Healthy'] : ['warning', 'Quiet'],
        'detail' => $daemonStatus['since'] ? fmt_ago($daemonStatus['since']) : 'no heartbeat',
    ],
    [
        'label' => 'Network',
        'badge' => freshness_badge($ipEventsLast ?: $geoEventsLast, 12, 48),
        'detail' => $ipEventsLast || $geoEventsLast ? fmt_ago($ipEventsLast ?: $geoEventsLast) : 'not flowing',
    ],
    [
        'label' => 'Classes',
        'badge' => freshness_badge($classesLast, 168, 336),
        'detail' => $classesLast ? fmt_ago($classesLast) : 'no class sync',
    ],
    [
        'label' => 'IIQ',
        'badge' => freshness_badge($iiqLast, 168, 720),
        'detail' => $iiqLast ? fmt_ago($iiqLast) : 'quiet',
    ],
    [
        'label' => 'API Token',
        'badge' => freshness_badge($apiTokenLast, 168, 720),
        'detail' => $apiTokenLast ? fmt_ago($apiTokenLast) : 'missing',
    ],
];

$fullTableMap = [];
if ($isFullMode) {
    $allTables = array_keys($tableMeta);
    sort($allTables, SORT_NATURAL | SORT_FLAG_CASE);

    $noteMap = [
        'locations' => 'retired placeholder',
        'repair_sessions' => 'future HDK repair subsystem',
        'repair_events' => 'future HDK repair subsystem',
        'repair_photos' => 'future HDK repair subsystem',
        'repair_evidence' => 'future HDK repair subsystem',
        'repair_location_audit' => 'future HDK repair subsystem',
        'repair_location_denials' => 'future HDK repair subsystem',
        'api_logs' => 'retired legacy API logging',
        'api_clients' => 'backing table for nora_api_clients view',
        'users' => 'legacy Musky local-login store',
    ];

    $genericCandidates = [
        'updated_at',
        'last_seen',
        'ip_last_seen',
        'event_time',
        'imported_at',
        'created_at',
        'timestamp',
        'last_updated',
        'SubmissionDateTime',
        'CompleteDateTime',
        'RunStart',
        'OccurredAt',
        'issue_date',
    ];

    foreach ($allTables as $table) {
        $exact = null;
        if ($table !== 'device_history') {
            $exact = exact_table_count($pdo, $table);
        }

        $approx = estimated_table_count($pdo, $table);
        $latest = table_latest_activity($pdo, $table, $genericCandidates);

        $fullTableMap[] = [
            'table' => $table,
            'rows' => $exact !== null ? (string)$exact : ($approx !== null ? '~' . $approx : '–'),
            'latest' => $latest,
            'note' => $noteMap[$table] ?? '',
        ];
    }
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>NORA — Operations Board</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../theme.css?theme=<?= htmlspecialchars($theme) ?>" rel="stylesheet">
<style>
body {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.main-scroll {
  flex: 1 1 auto;
  overflow: auto;
  padding-bottom: 24px;
}

.sticky-top-toolbar {
  position: sticky;
  top: 0;
  z-index: 1030;
}

.countdown {
  font-variant-numeric: tabular-nums;
}

.hero-wrap {
  position: relative;
  overflow: hidden;
  border-radius: 20px;
  background:
    radial-gradient(circle at top right, rgba(255, 255, 255, 0.16), transparent 32%),
    linear-gradient(135deg, #173157 0%, #284b79 45%, #a35614 100%);
  color: #fff;
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
}

.hero-wrap::after {
  content: "";
  position: absolute;
  inset: 0;
  background:
    linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px),
    linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px);
  background-size: 18px 18px;
  pointer-events: none;
  opacity: .35;
}

.hero-body {
  position: relative;
  z-index: 1;
}

.hero-kicker {
  letter-spacing: .12em;
  text-transform: uppercase;
  font-size: .75rem;
  opacity: .82;
}

.hero-title {
  font-size: clamp(1.8rem, 3vw, 2.8rem);
  line-height: 1;
  margin: .35rem 0 .75rem;
}

.hero-sub {
  max-width: 860px;
  color: rgba(255,255,255,.86);
}

.status-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: .75rem;
}

.status-pill {
  border-radius: 16px;
  background: rgba(255,255,255,.12);
  border: 1px solid rgba(255,255,255,.16);
  padding: .85rem .95rem;
  backdrop-filter: blur(8px);
}

.status-pill .label {
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: rgba(255,255,255,.76);
}

.status-pill .value {
  display: flex;
  align-items: center;
  gap: .45rem;
  margin-top: .35rem;
  font-weight: 700;
}

.badge-soft {
  border-radius: 999px;
  padding: .35rem .7rem;
  font-size: .78rem;
}

.panel-card {
  height: 100%;
  border: 0;
  border-radius: 18px;
  overflow: hidden;
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
}

.panel-card .card-body {
  padding: 1.15rem 1.15rem 1rem;
}

.panel-top {
  height: 6px;
}

.accent-archive { background: linear-gradient(90deg, #ff8f1f, #ffd35a); }
.accent-network { background: linear-gradient(90deg, #0d9488, #67e8f9); }
.accent-workflow { background: linear-gradient(90deg, #7c3aed, #c084fc); }
.accent-classes { background: linear-gradient(90deg, #16a34a, #86efac); }
.accent-iiq { background: linear-gradient(90deg, #2563eb, #93c5fd); }
.accent-finance { background: linear-gradient(90deg, #b45309, #facc15); }
.accent-api { background: linear-gradient(90deg, #111827, #6b7280); }
.accent-dormant { background: linear-gradient(90deg, #991b1b, #f87171); }

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .75rem;
}

.panel-title {
  margin: 0;
  font-size: 1.05rem;
}

.panel-sub {
  margin-top: .75rem;
  color: var(--bs-secondary-color);
  font-size: .92rem;
}

.metric-list {
  margin-top: .9rem;
  display: grid;
  gap: .55rem;
}

.metric-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: .85rem;
  padding-bottom: .45rem;
  border-bottom: 1px solid rgba(0,0,0,.06);
}

.metric-row:last-child {
  border-bottom: 0;
  padding-bottom: 0;
}

.metric-label {
  color: var(--bs-secondary-color);
}

.metric-value {
  text-align: right;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-weight: 700;
}

.metric-sub {
  display: block;
  color: var(--bs-secondary-color);
  font-size: .77rem;
  font-family: inherit;
  font-weight: 400;
}

.micro-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
  gap: .75rem;
}

.micro-tile {
  border-radius: 14px;
  background: rgba(0,0,0,.04);
  padding: .8rem .85rem;
}

.micro-tile .k {
  color: var(--bs-secondary-color);
  font-size: .8rem;
  margin-bottom: .35rem;
}

.micro-tile .v {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-weight: 700;
}

.logbox {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  white-space: pre-wrap;
  background: rgba(0,0,0,.045);
  border-radius: 12px;
  padding: .85rem;
  max-height: 260px;
  overflow: auto;
  font-size: .88rem;
}

.pill-cloud {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem;
}

.pill-cloud .pill {
  border-radius: 999px;
  border: 1px solid rgba(0,0,0,.08);
  background: rgba(0,0,0,.04);
  padding: .35rem .75rem;
  font-size: .88rem;
}

.model-list {
  column-count: 3;
  column-gap: 1rem;
}

.model-item {
  break-inside: avoid;
  display: flex;
  justify-content: space-between;
  gap: .75rem;
  padding: .25rem 0;
  border-bottom: 1px dotted rgba(0,0,0,.1);
}

.table-map {
  border-radius: 16px;
  overflow: hidden;
}

.table-map table {
  margin-bottom: 0;
}

@media (max-width: 1200px) {
  .model-list {
    column-count: 2;
  }
}

@media (max-width: 576px) {
  .model-list {
    column-count: 1;
  }
}
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">

<div class="sticky-top-toolbar bg-white shadow-sm py-3 px-3" id="topBar">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <a href="../index.php" class="btn btn-outline-secondary me-2">← Back</a>
    <h3 class="m-0 me-auto">NORA Operations Board</h3>
    <a href="NoraWeb.ImpactBoard.php" class="btn btn-outline-warning">Impact Board</a>
    <a href="NoraWeb.ErrandsList.php" class="btn btn-outline-primary">Errands Console</a>
    <?php if ($isFullMode): ?>
      <a href="NoraWeb.Dashboard.php" class="btn btn-outline-warning">Safe Mode</a>
    <?php else: ?>
      <a href="NoraWeb.Dashboard.php?full=1" class="btn btn-outline-secondary">Full Diagnostics</a>
    <?php endif; ?>
    <div class="form-check ms-2">
      <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
      <label class="form-check-label" for="autoRefresh">Auto-refresh (120s)</label>
      <span id="countdown" class="countdown ms-1 fw-bold">120</span>
    </div>
    <button class="btn btn-outline-primary" id="refreshBtn">Refresh</button>
  </div>
</div>

<div class="container-fluid main-scroll">
  <?php if ($actionNotice): ?>
    <div class="alert alert-<?= $actionNotice[0] ? 'success' : 'warning' ?> mt-3">
      <?= htmlspecialchars($actionNotice[1]) ?>
    </div>
  <?php endif; ?>

  <div class="hero-wrap mt-3">
    <div class="hero-body p-4 p-lg-5">
      <div class="hero-kicker">Nora + Musky Admin</div>
      <div class="hero-title">State Of The Archive</div>
      <div class="hero-sub">
        This board is wired to the Nora tables that are actually active today: archive history, network events, classroom sync, IIQ mirrors, PANDA charges, APIs, inventory, and the Nora work queue.
      </div>

      <div class="micro-grid mt-4">
        <div class="micro-tile">
          <div class="k">Database</div>
          <div class="v"><?= $dbSizeMB !== null ? number_format((float)$dbSizeMB, 2) . ' MB' : '–' ?></div>
        </div>
        <div class="micro-tile">
          <div class="k">Tables</div>
          <div class="v"><?= fmt_num($tableCount) ?></div>
        </div>
        <div class="micro-tile">
          <div class="k">Devices</div>
          <div class="v"><?= fmt_num($devicesCount) ?></div>
        </div>
        <div class="micro-tile">
          <div class="k">IP Events</div>
          <div class="v"><?= fmt_num($ipEventsCount) ?></div>
        </div>
        <div class="micro-tile">
          <div class="k">Errands</div>
          <div class="v"><?= fmt_num($errandsTotal) ?></div>
        </div>
        <div class="micro-tile">
          <div class="k">Last Import</div>
          <div class="v"><?= $lastImportAt ? fmt_ago($lastImportAt) : '–' ?></div>
        </div>
      </div>

      <div class="status-strip mt-4">
        <?php foreach ($overviewBadges as $item): ?>
          <div class="status-pill">
            <div class="label"><?= htmlspecialchars($item['label']) ?></div>
            <div class="value">
              <span class="badge bg-<?= htmlspecialchars($item['badge'][0]) ?> badge-soft"><?= htmlspecialchars($item['badge'][1]) ?></span>
              <span><?= htmlspecialchars($item['detail']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if (!$isFullMode): ?>
    <div class="alert alert-info mt-3 mb-0">
      Safe Mode keeps the page light. Full Diagnostics adds the model inventory and a full table map of the Nora schema.
    </div>
  <?php endif; ?>

  <div class="row g-3 mt-1">
    <?php foreach ($cards as $card): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card panel-card">
          <div class="panel-top accent-<?= htmlspecialchars($card['accent']) ?>"></div>
          <div class="card-body">
            <div class="panel-header">
              <h5 class="panel-title"><?= htmlspecialchars($card['title']) ?></h5>
              <?php if ($card['badge']): ?>
                <span class="badge bg-<?= htmlspecialchars($card['badge'][0]) ?>"><?= htmlspecialchars($card['badge'][1]) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($card['stamp']): ?>
              <div class="text-muted small mt-1">Latest activity: <?= fmt_dt($card['stamp']) ?> · <?= htmlspecialchars(fmt_ago($card['stamp'])) ?></div>
            <?php endif; ?>
            <div class="metric-list">
              <?php foreach ($card['metrics'] as $metric): ?>
                <div class="metric-row">
                  <div class="metric-label"><?= htmlspecialchars($metric['label']) ?></div>
                  <div class="metric-value">
                    <?= is_string($metric['value']) ? htmlspecialchars($metric['value']) : $metric['value'] ?>
                    <?php if (!empty($metric['sub'])): ?>
                      <span class="metric-sub"><?= htmlspecialchars((string)$metric['sub']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (!empty($card['foot'])): ?>
              <div class="panel-sub"><?= htmlspecialchars($card['foot']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12 col-xl-7">
      <div class="card panel-card">
        <div class="panel-top accent-workflow"></div>
        <div class="card-body">
          <div class="panel-header">
            <h5 class="panel-title">Errands In The Last 24 Hours</h5>
            <div class="btn-group">
              <a class="btn btn-sm btn-outline-secondary" href="NoraWeb.ErrandsList.php">Open Errands</a>
              <button class="btn btn-sm btn-outline-primary" id="expandChartBtn">Expand</button>
            </div>
          </div>
          <div class="pill-cloud mt-3">
            <?php
              $statusOrder = ['submitted', 'Processing', 'Queued', 'Failed', 'Complete', 'Unknown', 'Rejected', 'Cancelled', 'Hold'];
              foreach ($statusOrder as $statusName):
                $count = (int)($errandsByStatus[$statusName] ?? 0);
                if ($count === 0 && !in_array($statusName, ['submitted', 'Processing', 'Failed', 'Complete'], true)) {
                    continue;
                }
            ?>
              <span class="pill"><?= htmlspecialchars($statusName) ?>: <?= $count ?></span>
            <?php endforeach; ?>
          </div>
          <hr class="my-3">
          <?php if ($seriesLabels && $seriesValues): ?>
            <div style="height: 310px;">
              <canvas id="chart24h"></canvas>
            </div>
          <?php else: ?>
            <div class="text-muted">No errands activity detected in the last 24 hours.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-5">
      <div class="card panel-card h-100">
        <div class="panel-top accent-archive"></div>
        <div class="card-body">
          <div class="panel-header">
            <h5 class="panel-title">Import Rhythm + Quick Actions</h5>
            <span class="badge bg-<?= freshness_badge($lastImportAt, 6, 24)[0] ?>"><?= freshness_badge($lastImportAt, 6, 24)[1] ?></span>
          </div>
          <div class="metric-list mt-3">
            <div class="metric-row">
              <div class="metric-label">Last import</div>
              <div class="metric-value"><?= $lastImportAt ? fmt_dt($lastImportAt) : '–' ?><span class="metric-sub"><?= $lastImportAt ? fmt_ago($lastImportAt) : 'unknown' ?></span></div>
            </div>
            <div class="metric-row">
              <div class="metric-label">Import source</div>
              <div class="metric-value"><?= htmlspecialchars($lastImportSource ?? '–') ?></div>
            </div>
            <div class="metric-row">
              <div class="metric-label">Device history latest</div>
              <div class="metric-value"><?= $deviceHistoryLast ? fmt_dt($deviceHistoryLast) : '–' ?></div>
            </div>
            <div class="metric-row">
              <div class="metric-label">Daemon heartbeat</div>
              <div class="metric-value"><?= $daemonStatus['since'] ? fmt_dt((string)$daemonStatus['since']) : '–' ?><span class="metric-sub"><?= htmlspecialchars($daemonStatus['detail'] ?? 'none') ?></span></div>
            </div>
          </div>

          <?php if ($importLabels && $importCounts): ?>
            <hr class="my-3">
            <div class="text-muted small mb-2">Last 12 imports by record_count</div>
            <div style="height: 130px;">
              <canvas id="importSparkline"></canvas>
            </div>
          <?php endif; ?>

          <hr class="my-3">
          <div class="d-flex flex-wrap gap-2">
            <a href="?action=run_import" class="btn btn-outline-primary">Run Importer</a>
            <a href="?action=run_maint_recent" class="btn btn-outline-success">Run Maintenance (recent)</a>
            <a href="?action=run_maint_full" class="btn btn-outline-danger">Run Maintenance (full)</a>
          </div>
          <div class="panel-sub mt-3">These buttons enqueue Nora errands instead of trying to shell out from the dashboard.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12 col-xl-6">
      <div class="card panel-card h-100">
        <div class="panel-top accent-dormant"></div>
        <div class="card-body">
          <div class="panel-header">
            <h5 class="panel-title">Dormant + Legacy Tables</h5>
            <span class="badge bg-secondary"><?= count($dormantTables) + count($legacyTables) ?> flagged</span>
          </div>

          <div class="mt-3">
            <div class="text-muted small mb-2">Dormant / unfulfilled</div>
            <div class="pill-cloud">
              <?php foreach ($dormantTables as $row): ?>
                <span class="pill"><?= htmlspecialchars($row['name']) ?>: <?= fmt_num($row['rows']) ?></span>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="mt-4">
            <div class="text-muted small mb-2">Legacy / likely superseded</div>
            <div class="pill-cloud">
              <?php foreach ($legacyTables as $row): ?>
                <span class="pill"><?= htmlspecialchars($row['name']) ?>: <?= fmt_num($row['rows']) ?></span>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="panel-sub mt-3">
            This panel is intentionally opinionated. It calls out the tables that look empty, scaffolded, or superseded so they stop hiding in plain sight.
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-6">
      <div class="card panel-card h-100">
        <div class="panel-top accent-api"></div>
        <div class="card-body">
          <div class="panel-header">
            <h5 class="panel-title">Request Mix + Live Nora Signals</h5>
            <span class="badge bg-<?= $daemonStatus['ok'] ? 'success' : 'warning' ?>"><?= htmlspecialchars($daemonStatus['label']) ?></span>
          </div>
          <div class="pill-cloud mt-3">
            <?php foreach ($requestMix as $label => $value): ?>
              <span class="pill"><?= htmlspecialchars($label) ?>: <?= (int)$value ?></span>
            <?php endforeach; ?>
          </div>
          <div class="metric-list mt-3">
            <div class="metric-row">
              <div class="metric-label">Latest IP event</div>
              <div class="metric-value"><?= $ipEventsLast ? fmt_dt($ipEventsLast) : '–' ?><span class="metric-sub"><?= $ipEventsLast ? fmt_ago($ipEventsLast) : 'not available' ?></span></div>
            </div>
            <div class="metric-row">
              <div class="metric-label">Latest geo event</div>
              <div class="metric-value"><?= $geoEventsLast ? fmt_dt($geoEventsLast) : '–' ?><span class="metric-sub"><?= $geoEventsLast ? fmt_ago($geoEventsLast) : 'not available' ?></span></div>
            </div>
            <div class="metric-row">
              <div class="metric-label">API token refreshed</div>
              <div class="metric-value"><?= $apiTokenLast ? fmt_dt($apiTokenLast) : '–' ?><span class="metric-sub"><?= $apiTokenLast ? fmt_ago($apiTokenLast) : 'missing' ?></span></div>
            </div>
            <div class="metric-row">
              <div class="metric-label">MariaDB musky_users</div>
              <div class="metric-value"><?= fmt_num($muskyUsersCount) ?><span class="metric-sub"><?= $muskyUsersLast ? 'latest ' . fmt_ago($muskyUsersLast) : 'no user timestamp' ?></span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12 col-xl-4">
      <div class="card panel-card h-100">
        <div class="panel-top accent-workflow"></div>
        <div class="card-body">
          <h5 class="panel-title">Daemon Tail</h5>
          <hr class="my-2">
          <?php if ($daemonTail): ?>
            <div class="logbox"><?php echo htmlspecialchars(implode("\n", $daemonTail)); ?></div>
          <?php else: ?>
            <div class="text-muted">No daemon log activity detected.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="card panel-card h-100">
        <div class="panel-top accent-archive"></div>
        <div class="card-body">
          <h5 class="panel-title">Importer JSONL</h5>
          <hr class="my-2">
          <?php if ($importTail): ?>
            <div class="logbox"><?php echo htmlspecialchars(implode("\n", $importTail)); ?></div>
          <?php else: ?>
            <div class="text-muted">No importer JSONL available.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="card panel-card h-100">
        <div class="panel-top accent-finance"></div>
        <div class="card-body">
          <h5 class="panel-title">Maintenance JSONL</h5>
          <hr class="my-2">
          <?php if ($maintenanceTail): ?>
            <div class="logbox"><?php echo htmlspecialchars(implode("\n", $maintenanceTail)); ?></div>
          <?php else: ?>
            <div class="text-muted">No maintenance JSONL available.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($isFullMode): ?>
    <div class="row g-3 mt-1">
      <div class="col-12 col-xxl-5">
        <div class="card panel-card h-100">
          <div class="panel-top accent-classes"></div>
          <div class="card-body">
            <h5 class="panel-title">Model Inventory</h5>
            <hr class="my-2">
            <?php if ($modelBreakdown): ?>
              <div class="model-list">
                <?php foreach ($modelBreakdown as $modelName => $count): ?>
                  <div class="model-item">
                    <span><?= htmlspecialchars($modelName) ?></span>
                    <strong><?= (int)$count ?></strong>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-muted">No model breakdown available.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12 col-xxl-7">
        <div class="card panel-card h-100">
          <div class="panel-top accent-dormant"></div>
          <div class="card-body">
            <div class="panel-header">
              <h5 class="panel-title">Full Nora Table Map</h5>
              <span class="badge bg-secondary"><?= fmt_num(count($fullTableMap)) ?> tables</span>
            </div>
            <div class="text-muted small mt-1">Large tables may use information_schema estimates, so rows prefixed with “~” are approximate.</div>
            <div class="table-responsive table-map mt-3">
              <table class="table table-sm align-middle">
                <thead class="table-light">
                  <tr>
                    <th>Table</th>
                    <th>Rows</th>
                    <th>Latest Activity</th>
                    <th>Note</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($fullTableMap as $row): ?>
                    <tr>
                      <td><code><?= htmlspecialchars($row['table']) ?></code></td>
                      <td><?= htmlspecialchars((string)$row['rows']) ?></td>
                      <td><?= $row['latest'] ? htmlspecialchars($row['latest']) . ' · ' . htmlspecialchars(fmt_ago($row['latest'])) : '–' ?></td>
                      <td class="text-muted"><?= htmlspecialchars($row['note']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="chartModal" tabindex="-1" aria-labelledby="chartModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="chartModalLabel">Errands Activity</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="height:70vh;">
        <canvas id="chart24hFull"></canvas>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (($seriesLabels && $seriesValues) || ($importLabels && $importCounts)): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php endif; ?>
<?php if ($seriesLabels && $seriesValues): ?>
<script>
(() => {
  const labels = <?= json_encode($seriesLabels) ?>;
  const dataVals = <?= json_encode($seriesValues) ?>;
  const config = {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Errands / hour',
        data: dataVals,
        borderColor: '#f97316',
        backgroundColor: 'rgba(249, 115, 22, 0.12)',
        fill: true,
        tension: 0.28,
        pointRadius: 2.5
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
        y: { beginAtZero: true, precision: 0 }
      }
    }
  };

  new Chart(document.getElementById('chart24h'), config);
  let fullChart = null;
  document.getElementById('expandChartBtn').addEventListener('click', () => {
    const modal = new bootstrap.Modal(document.getElementById('chartModal'));
    modal.show();
    setTimeout(() => {
      if (fullChart) fullChart.destroy();
      fullChart = new Chart(document.getElementById('chart24hFull'), config);
    }, 350);
  });
})();
</script>
<?php endif; ?>
<?php if ($importLabels && $importCounts): ?>
<script>
(() => {
  new Chart(document.getElementById('importSparkline'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($importLabels) ?>,
      datasets: [{
        label: 'Records / import',
        data: <?= json_encode($importCounts) ?>,
        backgroundColor: '#2563eb',
        borderRadius: 4
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { display: false },
        y: { beginAtZero: true, ticks: { display: false } }
      }
    }
  });
})();
</script>
<?php endif; ?>
<script>
const autoRefresh = document.getElementById('autoRefresh');
const countdown = document.getElementById('countdown');
const refreshBtn = document.getElementById('refreshBtn');
let t = 120;
countdown.textContent = t;
refreshBtn.addEventListener('click', () => location.reload());
setInterval(() => {
  if (autoRefresh.checked) {
    t -= 1;
    if (t <= 0) {
      location.reload();
    } else {
      countdown.textContent = t;
    }
  } else {
    countdown.textContent = '⏸';
  }
}, 1000);
</script>
</body>
</html>
