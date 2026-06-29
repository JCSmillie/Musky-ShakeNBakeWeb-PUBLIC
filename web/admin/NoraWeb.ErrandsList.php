<?php
// ============================================================================
// NORA - NoraWeb.ErrandsList.php
// ----------------------------------------------------------------------------
// Modern errands console with server-side filtering, live search, and bulk
// actions while preserving Musky theme support and admin permissions.
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

date_default_timezone_set('America/New_York');

if (!isset($_SESSION['musky_user'])) {
    http_response_code(403);
    echo "Access Denied: Not logged in.";
    exit;
}

$allowedRaw = $_SESSION['musky_user']['allowed_tools'] ?? [];
$allowed = is_array($allowedRaw)
    ? $allowedRaw
    : explode(',', (string)$allowedRaw);
$allowed = array_values(array_filter(array_map(static function ($value) {
    return strtoupper(trim((string)$value));
}, $allowed), static function ($value) {
    return $value !== '';
}));

if (!in_array('ADMIN_PANEL', $allowed, true) && !in_array('ALL_TOOLS', $allowed, true)) {
    http_response_code(403);
    echo "Access Denied: Missing Required Tool ADMIN_PANEL.";
    exit;
}

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$bulkUpdateCsrfToken = musky_csrf_token('nora_errands_bulk_update');
$displayTimeZone = new DateTimeZone('America/New_York');
$displayTimeZoneLabel = $displayTimeZone->getName() . ' (' . (new DateTime('now', $displayTimeZone))->format('T') . ')';

$validStatuses = ['submitted', 'Processing', 'Queued', 'Failed', 'Complete', 'Unknown', 'Rejected', 'Cancelled', 'Hold'];
$validPriority = [-1, 0, 1, 2, 3, 4, 5];
$requestFlagColumns = [
    'mosbasic' => 'MOSBasicRequest',
    'slack' => 'SlackRequest',
    'iiq' => 'IIQRequest',
    'nora' => 'NoraRequest',
    'custom' => 'CustomRequest',
];

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nora_errands_connect(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $configPath = dirname(__DIR__, 2) . '/nora_config.json';
    if (!file_exists($configPath)) {
        throw new RuntimeException('Missing nora_config.json');
    }

    $config = json_decode((string)file_get_contents($configPath), true);
    if (!is_array($config) || !isset($config['host'], $config['user'], $config['pass'], $config['name'])) {
        throw new RuntimeException('Invalid nora_config.json');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'] ?? 3306,
        $config['name']
    );

    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function normalize_flag_filter($raw): string
{
    $value = strtolower(trim((string)$raw));
    if ($value === 'true' || $value === '1' || $value === 'yes') {
        return 'true';
    }
    if ($value === 'false' || $value === '0' || $value === 'no') {
        return 'false';
    }
    return 'any';
}

function normalize_db_flag($raw): string
{
    if ($raw === null) {
        return 'FALSE';
    }

    $value = strtoupper(trim((string)$raw));
    if ($value === '' || $value === 'FALSE' || $value === '0' || $value === 'NO' || $value === 'N' || $value === 'OFF' || $value === 'NULL') {
        return 'FALSE';
    }

    return 'TRUE';
}

function short_text($value, int $max = 84): string
{
    if ($value === null) {
        return '—';
    }

    $text = trim((string)$value);
    if ($text === '') {
        return '—';
    }

    if (mb_strlen($text) > $max) {
        return h(mb_substr($text, 0, $max)) . '...';
    }

    return h($text);
}

function pretty_json_or_raw($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $decoded = json_decode((string)$value, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return (string)$value;
}

function format_local_datetime($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '';
    }

    try {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$value, new DateTimeZone('UTC'));
        if (!$dt) {
            $dt = new DateTime((string)$value, new DateTimeZone('UTC'));
        }
        $dt->setTimezone(new DateTimeZone('America/New_York'));
        return $dt->format('Y-m-d g:i:s A T');
    } catch (Throwable $t) {
        return (string)$value;
    }
}

function status_badge_class(string $status): string
{
    $map = [
        'submitted' => 'secondary',
        'Processing' => 'warning',
        'Queued' => 'info',
        'Failed' => 'danger',
        'Complete' => 'success',
        'Unknown' => 'dark',
        'Rejected' => 'dark',
        'Cancelled' => 'secondary',
        'Hold' => 'secondary',
    ];

    return $map[$status] ?? 'secondary';
}

function priority_badge_class(int $priority): string
{
    $map = [
        -1 => 'secondary',
        0 => 'secondary',
        1 => 'success',
        2 => 'success',
        3 => 'primary',
        4 => 'warning',
        5 => 'danger',
    ];

    return $map[$priority] ?? 'secondary';
}

function api_fail(string $message, int $httpCode = 400): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// -----------------------------------------------------------------------------
// API: Bulk update actions (status / priority)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_GET['api'] ?? '') === 'update')) {
    header('Content-Type: application/json');

    try {
        $pdo = nora_errands_connect();
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            api_fail('Invalid JSON payload.');
        }
        $csrfToken = $payload['csrf_token'] ?? null;
        if (!is_string($csrfToken) || !musky_csrf_is_valid('nora_errands_bulk_update', $csrfToken)) {
            api_fail('Invalid or missing CSRF token.', 403);
        }

        $action = trim((string)($payload['action'] ?? ''));
        $idsRaw = $payload['ids'] ?? [];
        if (!is_array($idsRaw)) {
            api_fail('IDs must be an array.');
        }

        $ids = [];
        foreach ($idsRaw as $rawId) {
            $id = (int)$rawId;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        if (!$ids) {
            api_fail('No valid IDs selected.');
        }

        if (count($ids) > 500) {
            api_fail('Too many IDs selected at once. Max 500.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($action === 'status') {
            global $validStatuses;
            $newStatus = trim((string)($payload['status'] ?? ''));
            if (!in_array($newStatus, $validStatuses, true)) {
                api_fail('Invalid status value.');
            }

            $params = $ids;
            if (in_array($newStatus, ['Complete', 'Cancelled', 'Failed', 'Rejected'], true)) {
                $sql = "UPDATE nora_errands SET Status = ?, CompleteDateTime = NOW() WHERE ErrandID IN ({$placeholders})";
                array_unshift($params, $newStatus);
            } elseif ($newStatus === 'Processing') {
                $sql = "UPDATE nora_errands SET Status = ?, LastAttemptDateTime = NOW() WHERE ErrandID IN ({$placeholders})";
                array_unshift($params, $newStatus);
            } else {
                $sql = "UPDATE nora_errands SET Status = ? WHERE ErrandID IN ({$placeholders})";
                array_unshift($params, $newStatus);
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode([
                'ok' => true,
                'updated' => count($ids),
                'message' => "Updated status to {$newStatus} for " . count($ids) . ' errands.',
            ]);
            exit;
        }

        if ($action === 'priority') {
            global $validPriority;
            $newPriority = (int)($payload['priority'] ?? 0);
            if (!in_array($newPriority, $validPriority, true)) {
                api_fail('Invalid priority value.');
            }

            $params = $ids;
            $sql = "UPDATE nora_errands SET TaskPriority = ? WHERE ErrandID IN ({$placeholders})";
            array_unshift($params, $newPriority);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode([
                'ok' => true,
                'updated' => count($ids),
                'message' => "Updated priority to {$newPriority} for " . count($ids) . ' errands.',
            ]);
            exit;
        }

        api_fail('Unknown action.');
    } catch (Throwable $t) {
        error_log('[NoraWeb.ErrandsList] bulk update failed: ' . $t->getMessage());
        api_fail('Update failed.', 500);
    }
}

// -----------------------------------------------------------------------------
// API: Filtered errand list
// -----------------------------------------------------------------------------
if (($_GET['api'] ?? '') === 'list') {
    header('Content-Type: application/json');

    try {
        $pdo = nora_errands_connect();

        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $priorityRaw = trim((string)($_GET['priority'] ?? ''));
        $submitter = trim((string)($_GET['submitter'] ?? ''));
        $owner = trim((string)($_GET['owner'] ?? ''));
        $serial = trim((string)($_GET['serial'] ?? ''));
        $udid = trim((string)($_GET['udid'] ?? ''));
        $timeField = trim((string)($_GET['time_field'] ?? 'submission'));

        $sinceHoursRaw = trim((string)($_GET['since_hours'] ?? '1'));
        $sinceHours = ($sinceHoursRaw === '' ? 1 : (int)$sinceHoursRaw);
        if ($sinceHours < 0) {
            $sinceHours = 1;
        }
        if ($sinceHours > 24 * 365) {
            $sinceHours = 24 * 365;
        }

        $limit = (int)($_GET['limit'] ?? 250);
        $limit = max(20, min(1000, $limit));

        $where = [];
        $params = [];

        $timeExpr = 'SubmissionDateTime';
        if ($timeField === 'last_attempt') {
            $timeExpr = 'LastAttemptDateTime';
        } elseif ($timeField === 'complete') {
            $timeExpr = 'CompleteDateTime';
        } elseif ($timeField === 'any_activity') {
            $timeExpr = 'GREATEST(COALESCE(SubmissionDateTime, "1970-01-01 00:00:00"), COALESCE(LastAttemptDateTime, "1970-01-01 00:00:00"), COALESCE(CompleteDateTime, "1970-01-01 00:00:00"))';
        }

        if ($sinceHours > 0) {
            $cutoffLocal = (new DateTime('now', new DateTimeZone('UTC')))
                ->sub(new DateInterval('PT' . $sinceHours . 'H'))
                ->format('Y-m-d H:i:s');
            $where[] = "{$timeExpr} >= :since_cutoff";
            $params[':since_cutoff'] = $cutoffLocal;
        }

        if ($status !== '' && strtolower($status) !== 'any') {
            $where[] = 'Status = :status';
            $params[':status'] = $status;
        }

        if ($priorityRaw !== '' && strtolower($priorityRaw) !== 'any') {
            $priority = (int)$priorityRaw;
            $where[] = 'TaskPriority = :priority';
            $params[':priority'] = $priority;
        }

        if ($submitter !== '') {
            $where[] = 'Submitter LIKE :submitter';
            $params[':submitter'] = '%' . $submitter . '%';
        }

        if ($owner !== '') {
            $where[] = 'DeviceOwner LIKE :owner';
            $params[':owner'] = '%' . $owner . '%';
        }

        if ($serial !== '') {
            $where[] = 'DeviceSerial LIKE :serial';
            $params[':serial'] = '%' . $serial . '%';
        }

        if ($udid !== '') {
            $where[] = 'UDID LIKE :udid';
            $params[':udid'] = '%' . $udid . '%';
        }

        foreach ($requestFlagColumns as $filterName => $columnName) {
            $flagFilter = normalize_flag_filter($_GET[$filterName] ?? 'any');
            if ($flagFilter === 'true') {
                $where[] = "UPPER(COALESCE({$columnName}, 'FALSE')) NOT IN ('', 'FALSE', '0', 'NO', 'OFF', 'N', 'NULL')";
            } elseif ($flagFilter === 'false') {
                $where[] = "UPPER(COALESCE({$columnName}, 'FALSE')) IN ('', 'FALSE', '0', 'NO', 'OFF', 'N', 'NULL')";
            }
        }

        if ($q !== '') {
            $where[] = "(
                CAST(ErrandID AS CHAR) LIKE :q OR
                CAST(TaskPriority AS CHAR) LIKE :q OR
                Status LIKE :q OR
                SubmissionDateTime LIKE :q OR
                LastAttemptDateTime LIKE :q OR
                CompleteDateTime LIKE :q OR
                MOSBasicRequest LIKE :q OR
                SlackRequest LIKE :q OR
                IIQRequest LIKE :q OR
                NoraRequest LIKE :q OR
                CustomRequest LIKE :q OR
                UDID LIKE :q OR
                DeviceSerial LIKE :q OR
                Submitter LIKE :q OR
                DeviceOwner LIKE :q OR
                ExtraDataField01 LIKE :q OR
                ExtraDataField02 LIKE :q OR
                ExtraDataField03 LIKE :q OR
                ExtraDataField04 LIKE :q OR
                ExtraDataField05 LIKE :q OR
                ExtraDataField06 LIKE :q
            )";
            $params[':q'] = '%' . $q . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $summarySql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN Status = 'submitted' THEN 1 ELSE 0 END) AS submitted_count,
                SUM(CASE WHEN Status = 'Processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN Status = 'Queued' THEN 1 ELSE 0 END) AS queued_count,
                SUM(CASE WHEN Status = 'Failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN Status = 'Complete' THEN 1 ELSE 0 END) AS complete_count
            FROM nora_errands
            {$whereSql}
        ";
        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute($params);
        $summary = $summaryStmt->fetch() ?: [
            'total' => 0,
            'submitted_count' => 0,
            'processing_count' => 0,
            'queued_count' => 0,
            'failed_count' => 0,
            'complete_count' => 0,
        ];

        $listSql = "
            SELECT
                ErrandID,
                TaskPriority,
                Status,
                SubmissionDateTime,
                LastAttemptDateTime,
                CompleteDateTime,
                MOSBasicRequest,
                SlackRequest,
                IIQRequest,
                NoraRequest,
                CustomRequest,
                UDID,
                DeviceSerial,
                Submitter,
                DeviceOwner,
                ExtraDataField01,
                ExtraDataField02,
                ExtraDataField03,
                ExtraDataField04,
                ExtraDataField05,
                ExtraDataField06
            FROM nora_errands
            {$whereSql}
            ORDER BY SubmissionDateTime DESC, ErrandID DESC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($listSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as &$row) {
            $row['SubmissionDateTimeLocal'] = format_local_datetime($row['SubmissionDateTime'] ?? null);
            $row['LastAttemptDateTimeLocal'] = format_local_datetime($row['LastAttemptDateTime'] ?? null);
            $row['CompleteDateTimeLocal'] = format_local_datetime($row['CompleteDateTime'] ?? null);
            $row['MOSBasicRequestNormalized'] = normalize_db_flag($row['MOSBasicRequest'] ?? null);
            $row['SlackRequestNormalized'] = normalize_db_flag($row['SlackRequest'] ?? null);
            $row['IIQRequestNormalized'] = normalize_db_flag($row['IIQRequest'] ?? null);
            $row['NoraRequestNormalized'] = normalize_db_flag($row['NoraRequest'] ?? null);
            $row['CustomRequestNormalized'] = normalize_db_flag($row['CustomRequest'] ?? null);
            $row['StatusBadge'] = status_badge_class((string)($row['Status'] ?? ''));
            $row['PriorityBadge'] = priority_badge_class((int)($row['TaskPriority'] ?? 0));
            $row['ExtraDataField01Pretty'] = pretty_json_or_raw($row['ExtraDataField01'] ?? null);
            $row['ExtraDataField02Pretty'] = pretty_json_or_raw($row['ExtraDataField02'] ?? null);
            $row['ExtraDataField03Pretty'] = pretty_json_or_raw($row['ExtraDataField03'] ?? null);
            $row['ExtraDataField05Pretty'] = pretty_json_or_raw($row['ExtraDataField05'] ?? null);
            $row['ExtraDataField06Pretty'] = pretty_json_or_raw($row['ExtraDataField06'] ?? null);
        }
        unset($row);

        echo json_encode([
            'ok' => true,
            'meta' => [
                'total' => (int)($summary['total'] ?? 0),
                'limit' => $limit,
                'since_hours' => $sinceHours,
                'time_field' => $timeField,
                'timezone' => 'America/New_York',
            ],
            'summary' => [
                'submitted' => (int)($summary['submitted_count'] ?? 0),
                'processing' => (int)($summary['processing_count'] ?? 0),
                'queued' => (int)($summary['queued_count'] ?? 0),
                'failed' => (int)($summary['failed_count'] ?? 0),
                'complete' => (int)($summary['complete_count'] ?? 0),
            ],
            'rows' => $rows,
        ]);
        exit;
    } catch (Throwable $t) {
        error_log('[NoraWeb.ErrandsList] load failed: ' . $t->getMessage());
        api_fail('Failed to load errands.', 500);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>NORA Errands Console</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../theme.css?theme=<?= h($theme) ?>">
  <style>
    :root {
      --errands-surface: rgba(255, 255, 255, 0.78);
      --errands-surface-soft: rgba(255, 255, 255, 0.62);
      --errands-soft: rgba(0, 0, 0, 0.05);
      --errands-border: rgba(0, 0, 0, 0.14);
      --errands-shadow: 0 14px 34px rgba(0, 0, 0, 0.10);
      --errands-thead-bg: rgba(248, 249, 250, 0.96);
      --errands-row-hover: rgba(13, 110, 253, 0.08);
      --errands-row-active: rgba(13, 110, 253, 0.14);
      --errands-input-bg: rgba(255, 255, 255, 0.92);
    }

    body {
      margin: 0;
      min-height: 100vh;
    }

    @supports (background: color-mix(in srgb, black, white)) {
      body {
        --errands-surface: color-mix(in srgb, currentColor 12%, transparent);
        --errands-surface-soft: color-mix(in srgb, currentColor 8%, transparent);
        --errands-soft: color-mix(in srgb, currentColor 10%, transparent);
        --errands-border: color-mix(in srgb, currentColor 24%, transparent);
        --errands-thead-bg: color-mix(in srgb, currentColor 14%, transparent);
        --errands-row-hover: color-mix(in srgb, currentColor 16%, transparent);
        --errands-row-active: color-mix(in srgb, currentColor 24%, transparent);
        --errands-input-bg: color-mix(in srgb, currentColor 10%, transparent);
      }
    }

    .page-wrap {
      padding: 16px;
      display: grid;
      gap: 14px;
    }

    .glass {
      border: 1px solid var(--errands-border);
      border-radius: 16px;
      background: var(--errands-surface);
      box-shadow: var(--errands-shadow);
      backdrop-filter: blur(8px);
    }

    .headbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
      padding: 14px;
    }

    .head-title {
      min-width: 240px;
      flex: 1;
    }

    .head-title h1 {
      margin: 0;
      font-size: 1.3rem;
      line-height: 1.2;
    }

    .head-title .sub {
      font-size: 0.87rem;
      opacity: 0.78;
      margin-top: 4px;
    }

    .metric-grid {
      display: grid;
      gap: 10px;
      grid-template-columns: repeat(6, minmax(120px, 1fr));
      padding: 0 14px 14px;
    }

    .metric {
      border: 1px solid var(--errands-border);
      border-radius: 12px;
      padding: 10px;
      background: var(--errands-surface-soft);
    }

    .metric .v {
      font-size: 1.24rem;
      font-weight: 800;
      line-height: 1;
      margin-bottom: 4px;
    }

    .metric .k {
      font-size: 0.78rem;
      opacity: 0.76;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .filter-wrap {
      padding: 14px;
      display: grid;
      gap: 10px;
    }

    .filter-grid-main {
      display: grid;
      gap: 10px;
      grid-template-columns: 2.3fr 1fr 1fr 1fr 1fr;
    }

    .filter-grid-extra {
      display: grid;
      gap: 10px;
      grid-template-columns: 1.2fr 1.2fr 1fr 1fr 1fr 1fr 1fr 1fr;
    }

    .filter-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
    }

    .filter-actions .left,
    .filter-actions .right {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
    }

    .workspace-grid {
      display: grid;
      gap: 12px;
      grid-template-columns: minmax(760px, 2fr) minmax(330px, 1fr);
      align-items: start;
    }

    .table-card,
    .detail-card {
      overflow: hidden;
    }

    .table-head,
    .detail-head {
      padding: 12px 14px;
      border-bottom: 1px solid var(--errands-border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      background: var(--errands-soft);
    }

    .table {
      color: inherit;
      border-color: var(--errands-border);
    }

    .table > :not(caption) > * > * {
      border-color: var(--errands-border);
    }

    .table-scroll {
      max-height: 68vh;
      overflow: auto;
    }

    .table thead th {
      position: sticky;
      top: 0;
      z-index: 3;
      background: var(--errands-thead-bg);
      border-bottom-width: 2px;
    }

    .table td,
    .table th {
      vertical-align: top;
      white-space: nowrap;
    }

    .table td.wrap {
      white-space: normal;
      min-width: 180px;
      max-width: 320px;
    }

    .table tbody tr:hover {
      background: var(--errands-row-hover);
    }

    .table tbody tr.active-row {
      background: var(--errands-row-active);
    }

    .detail-body {
      padding: 12px 14px;
      display: grid;
      gap: 10px;
      max-height: 68vh;
      overflow: auto;
    }

    .detail-meta {
      display: grid;
      gap: 8px;
      grid-template-columns: 1fr 1fr;
    }

    .detail-meta .tile {
      border: 1px solid var(--errands-border);
      border-radius: 10px;
      padding: 8px;
      background: var(--errands-surface-soft);
    }

    .detail-meta .tile .k {
      font-size: 0.72rem;
      opacity: 0.70;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 4px;
    }

    .detail-meta .tile .v {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 0.84rem;
      overflow-wrap: anywhere;
    }

    .json-block {
      border: 1px solid var(--errands-border);
      border-radius: 10px;
      overflow: hidden;
      background: var(--errands-surface-soft);
    }

    .json-block .label {
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      padding: 8px 10px;
      border-bottom: 1px solid var(--errands-border);
      background: var(--errands-soft);
    }

    .json-block pre {
      margin: 0;
      padding: 10px;
      white-space: pre-wrap;
      word-break: break-word;
      max-height: 220px;
      overflow: auto;
      background: transparent;
      border: 0;
      font-size: 0.80rem;
      line-height: 1.35;
      color: inherit;
    }

    .muted {
      opacity: 0.72;
    }

    .form-control,
    .form-select {
      background-color: var(--errands-input-bg);
      color: inherit;
      border-color: var(--errands-border);
    }

    .form-control:focus,
    .form-select:focus {
      background-color: var(--errands-input-bg);
      color: inherit;
      border-color: var(--errands-border);
      box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.22);
    }

    .form-floating > label {
      color: inherit;
      opacity: 0.75;
    }

    .modal-content {
      background: var(--errands-surface);
      color: inherit;
      border: 1px solid var(--errands-border);
    }

    .modal-header,
    .modal-footer {
      border-color: var(--errands-border);
    }

    .status-dot {
      display: inline-block;
      width: 9px;
      height: 9px;
      border-radius: 50%;
      margin-right: 6px;
    }

    @media (max-width: 1600px) {
      .filter-grid-main {
        grid-template-columns: 1.7fr 1fr 1fr 1fr 1fr;
      }

      .filter-grid-extra {
        grid-template-columns: 1.1fr 1.1fr 1fr 1fr 1fr 1fr;
      }
    }

    @media (max-width: 1300px) {
      .workspace-grid {
        grid-template-columns: 1fr;
      }

      .table-scroll,
      .detail-body {
        max-height: none;
      }

      .metric-grid {
        grid-template-columns: repeat(3, minmax(120px, 1fr));
      }

      .filter-grid-main {
        grid-template-columns: 1fr 1fr;
      }

      .filter-grid-extra {
        grid-template-columns: 1fr 1fr 1fr;
      }
    }

    @media (max-width: 760px) {
      .metric-grid {
        grid-template-columns: repeat(2, minmax(120px, 1fr));
      }

      .filter-grid-main,
      .filter-grid-extra {
        grid-template-columns: 1fr;
      }

      .detail-meta {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="<?= h($theme) ?>">
  <div class="page-wrap">
    <section class="glass">
      <div class="headbar">
        <a href="../index.php" class="btn btn-outline-secondary">Back</a>
        <div class="head-title">
          <h1>NORA Errands Console</h1>
          <div class="sub">Modern queue view with default last-hour focus and full-depth search filters. All times shown in <?= h($displayTimeZoneLabel) ?>.</div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <button id="refreshBtn" class="btn btn-primary">Refresh</button>
          <button id="autoBtn" data-on="1" class="btn btn-outline-primary">Auto Refresh: On</button>
          <span id="lastRefresh" class="small muted">Never refreshed</span>
        </div>
      </div>
      <div class="metric-grid" id="metricGrid">
        <div class="metric"><div class="v" id="mTotal">0</div><div class="k">Matches</div></div>
        <div class="metric"><div class="v" id="mSubmitted">0</div><div class="k">Submitted</div></div>
        <div class="metric"><div class="v" id="mProcessing">0</div><div class="k">Processing</div></div>
        <div class="metric"><div class="v" id="mQueued">0</div><div class="k">Queued</div></div>
        <div class="metric"><div class="v" id="mFailed">0</div><div class="k">Failed</div></div>
        <div class="metric"><div class="v" id="mComplete">0</div><div class="k">Complete</div></div>
      </div>
    </section>

    <section class="glass filter-wrap">
      <div class="filter-grid-main">
        <div class="form-floating">
          <input id="f_q" class="form-control" type="search" placeholder="Search everything">
          <label for="f_q">Global Search (ID, status, serial, owner, JSON, and more)</label>
        </div>

        <div class="form-floating">
          <select id="f_status" class="form-select">
            <option value="any" selected>Any status</option>
            <?php foreach ($validStatuses as $status): ?>
            <option value="<?= h($status) ?>"><?= h($status) ?></option>
            <?php endforeach; ?>
          </select>
          <label for="f_status">Status</label>
        </div>

        <div class="form-floating">
          <select id="f_priority" class="form-select">
            <option value="any" selected>Any priority</option>
            <?php foreach ($validPriority as $priority): ?>
            <option value="<?= h((string)$priority) ?>">P<?= h((string)$priority) ?></option>
            <?php endforeach; ?>
          </select>
          <label for="f_priority">Priority</label>
        </div>

        <div class="form-floating">
          <select id="f_since" class="form-select">
            <option value="1" selected>Last hour (default)</option>
            <option value="4">Last 4 hours</option>
            <option value="12">Last 12 hours</option>
            <option value="24">Last 24 hours</option>
            <option value="72">Last 72 hours</option>
            <option value="168">Last 7 days</option>
            <option value="720">Last 30 days</option>
            <option value="0">All time</option>
          </select>
          <label for="f_since">Time Window</label>
        </div>

        <div class="form-floating">
          <select id="f_time_field" class="form-select">
            <option value="submission" selected>Submission time</option>
            <option value="last_attempt">Last attempt time</option>
            <option value="complete">Completion time</option>
            <option value="any_activity">Any activity time</option>
          </select>
          <label for="f_time_field">Time Basis</label>
        </div>
      </div>

      <div class="filter-grid-extra">
        <div class="form-floating">
          <input id="f_submitter" class="form-control" type="text" placeholder="Submitter contains">
          <label for="f_submitter">Submitter</label>
        </div>

        <div class="form-floating">
          <input id="f_owner" class="form-control" type="text" placeholder="Owner contains">
          <label for="f_owner">Owner</label>
        </div>

        <div class="form-floating">
          <input id="f_serial" class="form-control" type="text" placeholder="Serial contains">
          <label for="f_serial">Serial</label>
        </div>

        <div class="form-floating">
          <input id="f_udid" class="form-control" type="text" placeholder="UDID contains">
          <label for="f_udid">UDID</label>
        </div>

        <div class="form-floating">
          <select id="f_mosbasic" class="form-select">
            <option value="any" selected>MOSBasic any</option>
            <option value="true">MOSBasic true</option>
            <option value="false">MOSBasic false</option>
          </select>
          <label for="f_mosbasic">MOSBasic</label>
        </div>

        <div class="form-floating">
          <select id="f_slack" class="form-select">
            <option value="any" selected>Slack any</option>
            <option value="true">Slack true</option>
            <option value="false">Slack false</option>
          </select>
          <label for="f_slack">Slack</label>
        </div>

        <div class="form-floating">
          <select id="f_iiq" class="form-select">
            <option value="any" selected>IIQ any</option>
            <option value="true">IIQ true</option>
            <option value="false">IIQ false</option>
          </select>
          <label for="f_iiq">IIQ</label>
        </div>

        <div class="form-floating">
          <select id="f_nora" class="form-select">
            <option value="any" selected>Nora any</option>
            <option value="true">Nora true</option>
            <option value="false">Nora false</option>
          </select>
          <label for="f_nora">Nora</label>
        </div>
      </div>

      <div class="filter-actions">
        <div class="left">
          <div class="form-floating" style="min-width: 110px;">
            <select id="f_custom" class="form-select">
              <option value="any" selected>Any</option>
              <option value="true">True</option>
              <option value="false">False</option>
            </select>
            <label for="f_custom">Custom</label>
          </div>

          <div class="form-floating" style="min-width: 120px;">
            <select id="f_limit" class="form-select">
              <option value="100">100 rows</option>
              <option value="250" selected>250 rows</option>
              <option value="500">500 rows</option>
              <option value="1000">1000 rows</option>
            </select>
            <label for="f_limit">Row Limit</label>
          </div>
        </div>

        <div class="right">
          <button id="resetBtn" class="btn btn-outline-secondary" type="button">Reset Filters</button>
          <button id="applyBtn" class="btn btn-primary" type="button">Apply Filters</button>
        </div>
      </div>
    </section>

    <section class="workspace-grid">
      <div class="glass table-card">
        <div class="table-head">
          <div>
            <strong>Errands</strong>
            <span id="tableMeta" class="small muted ms-2">No data yet</span>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span id="selectedCount" class="small muted">0 selected</span>
            <button id="bulkStatusBtn" class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#statusModal">Change Status</button>
            <button id="bulkPriorityBtn" class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#priorityModal">Change Priority</button>
          </div>
        </div>

        <div class="table-scroll">
          <table class="table table-sm table-hover align-middle mb-0" id="errandsTable">
            <thead>
              <tr>
                <th><input id="checkAll" type="checkbox" class="form-check-input"></th>
                <th>ID</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Last Attempt</th>
                <th>Complete</th>
                <th>MOS</th>
                <th>Slack</th>
                <th>IIQ</th>
                <th>Nora</th>
                <th>Custom</th>
                <th>Serial</th>
                <th>UDID</th>
                <th>Submitter</th>
                <th>Owner</th>
                <th class="wrap">Extra 01</th>
                <th class="wrap">Extra 02</th>
                <th class="wrap">Result (06)</th>
              </tr>
            </thead>
            <tbody id="rows">
              <tr><td colspan="19" class="text-center py-4">Loading errands...</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <aside class="glass detail-card">
        <div class="detail-head">
          <div>
            <strong>Errand Detail</strong>
          </div>
          <span id="detailHint" class="small muted">Select a row to inspect.</span>
        </div>
        <div class="detail-body" id="detailPane">
          <div class="muted">No errand selected yet.</div>
        </div>
      </aside>
    </section>
  </div>

  <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Bulk Status Update</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Selected errands: <strong id="statusSelectedCount">0</strong></p>
          <div class="form-floating">
            <select id="statusChoice" class="form-select">
              <?php foreach ($validStatuses as $status): ?>
              <option value="<?= h($status) ?>"><?= h($status) ?></option>
              <?php endforeach; ?>
            </select>
            <label for="statusChoice">New status</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" id="statusApplyBtn" class="btn btn-primary">Apply</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="priorityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Bulk Priority Update</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">Selected errands: <strong id="prioritySelectedCount">0</strong></p>
          <div class="form-floating">
            <select id="priorityChoice" class="form-select">
              <option value="-1">-1 (top-of-hour)</option>
              <option value="0">0 (on-the-hour)</option>
              <option value="1">1 (15 past)</option>
              <option value="2">2 (30 past)</option>
              <option value="3">3 (45 past)</option>
              <option value="4" selected>4 (every 5m)</option>
              <option value="5">5 (asap)</option>
            </select>
            <label for="priorityChoice">New priority</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" id="priorityApplyBtn" class="btn btn-primary">Apply</button>
        </div>
      </div>
    </div>
  </div>

  <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1080;">
    <div id="noticeToast" class="toast text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body" id="toastBody">Notice</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const bulkUpdateCsrfToken = <?= json_encode($bulkUpdateCsrfToken) ?>;
    const state = {
      rows: [],
      selectedIds: new Set(),
      selectedRowId: null,
      autoTimer: null,
      loading: false,
    };

    const elements = {
      rows: document.getElementById('rows'),
      tableMeta: document.getElementById('tableMeta'),
      selectedCount: document.getElementById('selectedCount'),
      checkAll: document.getElementById('checkAll'),
      detailPane: document.getElementById('detailPane'),
      detailHint: document.getElementById('detailHint'),
      refreshBtn: document.getElementById('refreshBtn'),
      autoBtn: document.getElementById('autoBtn'),
      lastRefresh: document.getElementById('lastRefresh'),
      applyBtn: document.getElementById('applyBtn'),
      resetBtn: document.getElementById('resetBtn'),
      statusApplyBtn: document.getElementById('statusApplyBtn'),
      priorityApplyBtn: document.getElementById('priorityApplyBtn'),
      statusSelectedCount: document.getElementById('statusSelectedCount'),
      prioritySelectedCount: document.getElementById('prioritySelectedCount'),
      statusChoice: document.getElementById('statusChoice'),
      priorityChoice: document.getElementById('priorityChoice'),
      mTotal: document.getElementById('mTotal'),
      mSubmitted: document.getElementById('mSubmitted'),
      mProcessing: document.getElementById('mProcessing'),
      mQueued: document.getElementById('mQueued'),
      mFailed: document.getElementById('mFailed'),
      mComplete: document.getElementById('mComplete'),
    };

    const statusModalEl = document.getElementById('statusModal');
    const priorityModalEl = document.getElementById('priorityModal');
    const statusModal = new bootstrap.Modal(statusModalEl);
    const priorityModal = new bootstrap.Modal(priorityModalEl);
    const toast = new bootstrap.Toast(document.getElementById('noticeToast'));

    function esc(value) {
      return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
      }[ch]));
    }

    function showToast(message) {
      document.getElementById('toastBody').textContent = message;
      toast.show();
    }

    function badgeHtml(text, kind) {
      return `<span class="badge text-bg-${esc(kind)}">${esc(text)}</span>`;
    }

    function pillFlag(value) {
      const normalized = String(value || '').toUpperCase();
      return normalized === 'TRUE'
        ? '<span class="badge text-bg-success">TRUE</span>'
        : '<span class="badge text-bg-secondary">FALSE</span>';
    }

    function shortText(value, max = 84) {
      const text = String(value ?? '').trim();
      if (!text) return '—';
      return text.length > max ? `${esc(text.slice(0, max))}...` : esc(text);
    }

    function getFilterValue(id) {
      const el = document.getElementById(id);
      return el ? el.value.trim() : '';
    }

    function buildUrl() {
      const url = new URL(window.location.href);
      url.searchParams.set('api', 'list');
      url.searchParams.set('q', getFilterValue('f_q'));
      url.searchParams.set('status', getFilterValue('f_status'));
      url.searchParams.set('priority', getFilterValue('f_priority'));
      url.searchParams.set('since_hours', getFilterValue('f_since'));
      url.searchParams.set('time_field', getFilterValue('f_time_field'));
      url.searchParams.set('submitter', getFilterValue('f_submitter'));
      url.searchParams.set('owner', getFilterValue('f_owner'));
      url.searchParams.set('serial', getFilterValue('f_serial'));
      url.searchParams.set('udid', getFilterValue('f_udid'));
      url.searchParams.set('mosbasic', getFilterValue('f_mosbasic'));
      url.searchParams.set('slack', getFilterValue('f_slack'));
      url.searchParams.set('iiq', getFilterValue('f_iiq'));
      url.searchParams.set('nora', getFilterValue('f_nora'));
      url.searchParams.set('custom', getFilterValue('f_custom'));
      url.searchParams.set('limit', getFilterValue('f_limit'));
      return url;
    }

    function updateSelectedCount() {
      const count = state.selectedIds.size;
      elements.selectedCount.textContent = `${count} selected`;
      elements.statusSelectedCount.textContent = String(count);
      elements.prioritySelectedCount.textContent = String(count);
    }

    function renderMetrics(summary, total) {
      elements.mTotal.textContent = String(total ?? 0);
      elements.mSubmitted.textContent = String(summary.submitted ?? 0);
      elements.mProcessing.textContent = String(summary.processing ?? 0);
      elements.mQueued.textContent = String(summary.queued ?? 0);
      elements.mFailed.textContent = String(summary.failed ?? 0);
      elements.mComplete.textContent = String(summary.complete ?? 0);
    }

    function findRowById(id) {
      return state.rows.find((row) => String(row.ErrandID) === String(id));
    }

    function renderDetail(row) {
      if (!row) {
        elements.detailHint.textContent = 'Select a row to inspect.';
        elements.detailPane.innerHTML = '<div class="muted">No errand selected yet.</div>';
        return;
      }

      elements.detailHint.textContent = `Errand #${row.ErrandID}`;

      const metaTiles = [
        ['Errand ID', row.ErrandID],
        ['Status', row.Status],
        ['Priority', row.TaskPriority],
        ['Submitted', row.SubmissionDateTimeLocal || '—'],
        ['Last Attempt', row.LastAttemptDateTimeLocal || '—'],
        ['Complete', row.CompleteDateTimeLocal || '—'],
        ['Serial', row.DeviceSerial || '—'],
        ['UDID', row.UDID || '—'],
        ['Submitter', row.Submitter || '—'],
        ['Owner', row.DeviceOwner || '—'],
      ];

      const tileHtml = metaTiles.map(([key, val]) => `
        <div class="tile">
          <div class="k">${esc(key)}</div>
          <div class="v">${esc(val ?? '—')}</div>
        </div>
      `).join('');

      const jsonBlocks = [
        ['ExtraDataField01', row.ExtraDataField01Pretty || ''],
        ['ExtraDataField02', row.ExtraDataField02Pretty || ''],
        ['ExtraDataField03', row.ExtraDataField03Pretty || ''],
        ['ExtraDataField04', row.ExtraDataField04 || ''],
        ['ExtraDataField05', row.ExtraDataField05Pretty || ''],
        ['ExtraDataField06 (Result)', row.ExtraDataField06Pretty || ''],
      ];

      const jsonHtml = jsonBlocks.map(([label, value]) => `
        <div class="json-block">
          <div class="label">${esc(label)}</div>
          <pre>${esc(value || '—')}</pre>
        </div>
      `).join('');

      elements.detailPane.innerHTML = `
        <div class="detail-meta">${tileHtml}</div>
        ${jsonHtml}
      `;
    }

    function renderRows(rows) {
      if (!rows.length) {
        elements.rows.innerHTML = '<tr><td colspan="19" class="text-center py-4">No errands matched the current filters.</td></tr>';
        return;
      }

      const currentSelected = new Set();
      rows.forEach((row) => {
        if (state.selectedIds.has(String(row.ErrandID))) {
          currentSelected.add(String(row.ErrandID));
        }
      });
      state.selectedIds = currentSelected;
      updateSelectedCount();

      elements.rows.innerHTML = rows.map((row) => {
        const id = String(row.ErrandID);
        const isSelected = state.selectedIds.has(id);
        const isActive = String(state.selectedRowId) === id;

        return `
          <tr data-id="${esc(id)}" class="${isActive ? 'active-row' : ''}">
            <td><input type="checkbox" class="form-check-input row-check" data-id="${esc(id)}" ${isSelected ? 'checked' : ''}></td>
            <td>${esc(row.ErrandID)}</td>
            <td>${badgeHtml('P' + row.TaskPriority, row.PriorityBadge || 'secondary')}</td>
            <td>${badgeHtml(row.Status || '', row.StatusBadge || 'secondary')}</td>
            <td>${esc(row.SubmissionDateTimeLocal || '—')}</td>
            <td>${esc(row.LastAttemptDateTimeLocal || '—')}</td>
            <td>${esc(row.CompleteDateTimeLocal || '—')}</td>
            <td>${pillFlag(row.MOSBasicRequestNormalized)}</td>
            <td>${pillFlag(row.SlackRequestNormalized)}</td>
            <td>${pillFlag(row.IIQRequestNormalized)}</td>
            <td>${pillFlag(row.NoraRequestNormalized)}</td>
            <td>${pillFlag(row.CustomRequestNormalized)}</td>
            <td>${shortText(row.DeviceSerial, 30)}</td>
            <td>${shortText(row.UDID, 30)}</td>
            <td>${shortText(row.Submitter, 28)}</td>
            <td>${shortText(row.DeviceOwner, 28)}</td>
            <td class="wrap">${shortText(row.ExtraDataField01Pretty, 120)}</td>
            <td class="wrap">${shortText(row.ExtraDataField02Pretty, 120)}</td>
            <td class="wrap">${shortText(row.ExtraDataField06Pretty, 120)}</td>
          </tr>
        `;
      }).join('');

      elements.rows.querySelectorAll('tr').forEach((tr) => {
        tr.addEventListener('click', (event) => {
          if (event.target && event.target.classList.contains('row-check')) {
            return;
          }
          const id = tr.getAttribute('data-id');
          state.selectedRowId = id;
          renderRows(state.rows);
          renderDetail(findRowById(id));
        });
      });

      elements.rows.querySelectorAll('.row-check').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
          const id = checkbox.getAttribute('data-id');
          if (checkbox.checked) {
            state.selectedIds.add(id);
          } else {
            state.selectedIds.delete(id);
          }
          updateSelectedCount();
          syncCheckAll();
        });
      });

      syncCheckAll();
    }

    function syncCheckAll() {
      const checkboxes = Array.from(elements.rows.querySelectorAll('.row-check'));
      if (!checkboxes.length) {
        elements.checkAll.checked = false;
        elements.checkAll.indeterminate = false;
        return;
      }

      const checked = checkboxes.filter((cb) => cb.checked).length;
      elements.checkAll.checked = checked > 0 && checked === checkboxes.length;
      elements.checkAll.indeterminate = checked > 0 && checked < checkboxes.length;
    }

    async function loadErrands() {
      if (state.loading) {
        return;
      }
      state.loading = true;
      elements.tableMeta.textContent = 'Loading...';

      try {
        const response = await fetch(buildUrl().toString(), {
          credentials: 'same-origin',
          cache: 'no-store',
        });
        const data = await response.json();

        if (!data.ok) {
          throw new Error(data.error || 'Failed to load errands.');
        }

        state.rows = Array.isArray(data.rows) ? data.rows : [];

        renderMetrics(data.summary || {}, data.meta?.total || 0);
        renderRows(state.rows);

        elements.tableMeta.textContent = `${data.meta?.total ?? 0} match(es), showing ${state.rows.length}`;

        if (state.selectedRowId) {
          const selectedRow = findRowById(state.selectedRowId);
          renderDetail(selectedRow || null);
          if (!selectedRow) {
            state.selectedRowId = null;
          }
        } else if (state.rows.length) {
          state.selectedRowId = String(state.rows[0].ErrandID);
          renderRows(state.rows);
          renderDetail(state.rows[0]);
        } else {
          renderDetail(null);
        }

        elements.lastRefresh.textContent = `Last refresh: ${new Date().toLocaleTimeString()}`;
      } catch (error) {
        elements.rows.innerHTML = `<tr><td colspan="19" class="text-danger text-center py-4">${esc(error.message || error)}</td></tr>`;
        elements.tableMeta.textContent = 'Load failed';
        renderDetail(null);
      } finally {
        state.loading = false;
      }
    }

    async function runBulkUpdate(payload) {
      const response = await fetch('?api=update', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          ...payload,
          csrf_token: bulkUpdateCsrfToken,
        }),
      });
      const data = await response.json();
      if (!data.ok) {
        throw new Error(data.error || 'Update failed');
      }
      showToast(data.message || 'Update applied.');
    }

    function selectedIdsArray() {
      return Array.from(state.selectedIds);
    }

    function requireSelectionForModal(event) {
      if (state.selectedIds.size > 0) {
        updateSelectedCount();
        return;
      }

      event.preventDefault();
      showToast('Select at least one errand first.');
    }

    statusModalEl.addEventListener('show.bs.modal', requireSelectionForModal);
    priorityModalEl.addEventListener('show.bs.modal', requireSelectionForModal);

    elements.statusApplyBtn.addEventListener('click', async () => {
      const ids = selectedIdsArray();
      if (!ids.length) {
        showToast('Select at least one errand first.');
        return;
      }

      try {
        elements.statusApplyBtn.disabled = true;
        await runBulkUpdate({
          action: 'status',
          ids,
          status: elements.statusChoice.value,
        });
        statusModal.hide();
        await loadErrands();
      } catch (error) {
        showToast(error.message || 'Status update failed.');
      } finally {
        elements.statusApplyBtn.disabled = false;
      }
    });

    elements.priorityApplyBtn.addEventListener('click', async () => {
      const ids = selectedIdsArray();
      if (!ids.length) {
        showToast('Select at least one errand first.');
        return;
      }

      try {
        elements.priorityApplyBtn.disabled = true;
        await runBulkUpdate({
          action: 'priority',
          ids,
          priority: elements.priorityChoice.value,
        });
        priorityModal.hide();
        await loadErrands();
      } catch (error) {
        showToast(error.message || 'Priority update failed.');
      } finally {
        elements.priorityApplyBtn.disabled = false;
      }
    });

    elements.checkAll.addEventListener('change', () => {
      const checked = elements.checkAll.checked;
      elements.rows.querySelectorAll('.row-check').forEach((checkbox) => {
        checkbox.checked = checked;
        const id = checkbox.getAttribute('data-id');
        if (checked) {
          state.selectedIds.add(id);
        } else {
          state.selectedIds.delete(id);
        }
      });
      updateSelectedCount();
      syncCheckAll();
    });

    function setFiltersToDefaults() {
      document.getElementById('f_q').value = '';
      document.getElementById('f_status').value = 'any';
      document.getElementById('f_priority').value = 'any';
      document.getElementById('f_since').value = '1';
      document.getElementById('f_time_field').value = 'submission';
      document.getElementById('f_submitter').value = '';
      document.getElementById('f_owner').value = '';
      document.getElementById('f_serial').value = '';
      document.getElementById('f_udid').value = '';
      document.getElementById('f_mosbasic').value = 'any';
      document.getElementById('f_slack').value = 'any';
      document.getElementById('f_iiq').value = 'any';
      document.getElementById('f_nora').value = 'any';
      document.getElementById('f_custom').value = 'any';
      document.getElementById('f_limit').value = '250';
    }

    function applyFilters() {
      state.selectedIds.clear();
      updateSelectedCount();
      loadErrands();
    }

    function resetFilters() {
      setFiltersToDefaults();
      applyFilters();
    }

    function syncAutoRefresh() {
      const enabled = elements.autoBtn.dataset.on === '1';
      elements.autoBtn.textContent = `Auto Refresh: ${enabled ? 'On' : 'Off'}`;

      if (state.autoTimer) {
        clearInterval(state.autoTimer);
        state.autoTimer = null;
      }

      if (enabled) {
        state.autoTimer = setInterval(loadErrands, 30000);
      }
    }

    elements.applyBtn.addEventListener('click', applyFilters);
    elements.resetBtn.addEventListener('click', resetFilters);
    elements.refreshBtn.addEventListener('click', loadErrands);

    elements.autoBtn.addEventListener('click', () => {
      elements.autoBtn.dataset.on = elements.autoBtn.dataset.on === '1' ? '0' : '1';
      syncAutoRefresh();
    });

    [
      'f_q', 'f_submitter', 'f_owner', 'f_serial', 'f_udid'
    ].forEach((id) => {
      const el = document.getElementById(id);
      el.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          applyFilters();
        }
      });
    });

    [
      'f_status', 'f_priority', 'f_since', 'f_time_field', 'f_mosbasic', 'f_slack', 'f_iiq', 'f_nora', 'f_custom', 'f_limit'
    ].forEach((id) => {
      const el = document.getElementById(id);
      el.addEventListener('change', applyFilters);
    });

    // Force default startup scope each page load (last hour + no sticky browser-restored filters).
    setFiltersToDefaults();
    syncAutoRefresh();
    loadErrands();
    updateSelectedCount();
  </script>
</body>
</html>
