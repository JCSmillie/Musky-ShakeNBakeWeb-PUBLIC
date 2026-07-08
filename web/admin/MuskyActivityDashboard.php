<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';

date_default_timezone_set('America/New_York');

$allowedRaw = $_SESSION['musky_user']['allowed_tools'] ?? '';
$allowed = is_array($allowedRaw)
    ? array_filter(array_map('trim', $allowedRaw))
    : array_filter(array_map('trim', explode(',', (string)$allowedRaw)));

$allowed = array_values(array_filter(array_map(static function ($value) {
    return strtoupper(trim((string)$value));
}, $allowed), static function ($value) {
    return $value !== '';
}));

$isAdmin = in_array('ADMIN_PANEL', $allowed, true) || in_array('ALL_TOOLS', $allowed, true);
if (!$isAdmin) {
    http_response_code(403);
    echo 'Access Denied: Missing Required Tool ADMIN_PANEL.';
    exit;
}

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$displayTimeZone = new DateTimeZone('America/New_York');
$displayTimeZoneLabel = $displayTimeZone->getName() . ' (' . (new DateTime('now', $displayTimeZone))->format('T') . ')';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function activity_api_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => $message,
    ]);
    exit;
}

function activity_format_local_time($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '';
    }

    try {
        $dt = DateTime::createFromFormat('!Y-m-d H:i:s', (string)$value, new DateTimeZone('America/New_York'));
        if (!$dt) {
            $dt = new DateTime((string)$value, new DateTimeZone('America/New_York'));
        }
        $dt->setTimezone(new DateTimeZone('America/New_York'));
        return $dt->format('Y-m-d g:i:s A T');
    } catch (Throwable $t) {
        return (string)$value;
    }
}

function activity_pretty_json($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '';
    }

    $decoded = json_decode((string)$value, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return (string)$value;
}

function musky_activity_dashboard_db(): ?PDO
{
    return musky_activity_db();
}

function activity_run_summary(PDO $db, string $whereSql, array $params): array
{
    $sql = "
        SELECT
            COUNT(*) AS total,
            COUNT(DISTINCT CASE WHEN TRIM(COALESCE(user_email, '')) <> '' THEN LOWER(TRIM(user_email)) END) AS unique_users,
            COUNT(DISTINCT CASE WHEN TRIM(COALESCE(page_path, '')) <> '' THEN page_path END) AS unique_pages,
            SUM(CASE WHEN event_type = 'LOGIN' THEN 1 ELSE 0 END) AS login_count,
            SUM(CASE WHEN event_type = 'PAGE_VIEW' THEN 1 ELSE 0 END) AS page_view_count,
            SUM(CASE WHEN event_type = 'ACTION' THEN 1 ELSE 0 END) AS action_count,
            SUM(CASE WHEN event_type = 'LOGOUT' THEN 1 ELSE 0 END) AS logout_count
        FROM musky_activity_log
        {$whereSql}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int)($row['total'] ?? 0),
        'unique_users' => (int)($row['unique_users'] ?? 0),
        'unique_pages' => (int)($row['unique_pages'] ?? 0),
        'logins' => (int)($row['login_count'] ?? 0),
        'page_views' => (int)($row['page_view_count'] ?? 0),
        'actions' => (int)($row['action_count'] ?? 0),
        'logouts' => (int)($row['logout_count'] ?? 0),
    ];
}

if (isset($_GET['api']) && $_GET['api'] === 'list') {
    header('Content-Type: application/json');

    try {
        $db = musky_activity_dashboard_db();
        if (!$db) {
            throw new RuntimeException('Activity database unavailable.');
        }

        $q = trim((string)($_GET['q'] ?? ''));
        $type = strtoupper(trim((string)($_GET['type'] ?? 'any')));
        $email = trim((string)($_GET['email'] ?? ''));
        $user = trim((string)($_GET['user'] ?? ''));
        $page = trim((string)($_GET['page'] ?? ''));
        $action = trim((string)($_GET['action'] ?? ''));
        $serial = trim((string)($_GET['serial'] ?? ''));
        $asset = trim((string)($_GET['asset'] ?? ''));
        $ip = trim((string)($_GET['ip'] ?? ''));
        $sessionId = trim((string)($_GET['session'] ?? ''));
        $method = strtoupper(trim((string)($_GET['method'] ?? 'any')));

        $sinceHoursRaw = trim((string)($_GET['since_hours'] ?? '1'));
        $sinceHours = ($sinceHoursRaw === '' ? 1 : (int)$sinceHoursRaw);
        if ($sinceHours < 0) {
            $sinceHours = 1;
        }
        if ($sinceHours > 24 * 90) {
            $sinceHours = 24 * 90;
        }

        $limit = (int)($_GET['limit'] ?? 250);
        $limit = max(50, min(1000, $limit));

        $where = [];
        $params = [];

        if ($sinceHours > 0) {
            $cutoff = (new DateTime('now', new DateTimeZone('America/New_York')))
                ->sub(new DateInterval('PT' . $sinceHours . 'H'))
                ->format('Y-m-d H:i:s');
            $where[] = 'event_time >= :since_cutoff';
            $params[':since_cutoff'] = $cutoff;
        }

        if ($type !== '' && $type !== 'ANY') {
            $where[] = 'event_type = :event_type';
            $params[':event_type'] = $type;
        }

        if ($method !== '' && $method !== 'ANY') {
            $where[] = 'UPPER(COALESCE(request_method, "")) = :request_method';
            $params[':request_method'] = $method;
        }

        if ($email !== '') {
            $where[] = 'user_email LIKE :email';
            $params[':email'] = '%' . $email . '%';
        }

        if ($user !== '') {
            $where[] = 'user_name LIKE :user_name';
            $params[':user_name'] = '%' . $user . '%';
        }

        if ($page !== '') {
            $where[] = 'page_path LIKE :page';
            $params[':page'] = '%' . $page . '%';
        }

        if ($action !== '') {
            $where[] = 'action_name LIKE :action_name';
            $params[':action_name'] = '%' . $action . '%';
        }

        if ($serial !== '') {
            $where[] = 'target_serials LIKE :target_serials';
            $params[':target_serials'] = '%' . $serial . '%';
        }

        if ($asset !== '') {
            $where[] = 'target_asset_tags LIKE :target_asset_tags';
            $params[':target_asset_tags'] = '%' . $asset . '%';
        }

        if ($ip !== '') {
            $where[] = 'ip_address LIKE :ip_address';
            $params[':ip_address'] = '%' . $ip . '%';
        }

        if ($sessionId !== '') {
            $where[] = 'session_id LIKE :session_id';
            $params[':session_id'] = '%' . $sessionId . '%';
        }

        if ($q !== '') {
            $where[] = "(
                CAST(id AS TEXT) LIKE :q OR
                event_time LIKE :q OR
                event_type LIKE :q OR
                user_email LIKE :q OR
                user_name LIKE :q OR
                page_path LIKE :q OR
                action_name LIKE :q OR
                target_serials LIKE :q OR
                target_asset_tags LIKE :q OR
                request_method LIKE :q OR
                ip_address LIKE :q OR
                session_id LIKE :q OR
                extra_json LIKE :q
            )";
            $params[':q'] = '%' . $q . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $filteredSummary = activity_run_summary($db, $whereSql, $params);

        $global24Cutoff = (new DateTime('now', new DateTimeZone('America/New_York')))
            ->sub(new DateInterval('PT24H'))
            ->format('Y-m-d H:i:s');
        $global24Summary = activity_run_summary(
            $db,
            'WHERE event_time >= :cutoff24',
            [':cutoff24' => $global24Cutoff]
        );

        $listSql = "
            SELECT
                id,
                event_time,
                event_type,
                user_email,
                user_name,
                page_path,
                action_name,
                target_serials,
                target_asset_tags,
                request_method,
                ip_address,
                user_agent,
                session_id,
                extra_json
            FROM musky_activity_log
            {$whereSql}
            ORDER BY event_time DESC, id DESC
            LIMIT {$limit}
        ";

        $stmt = $db->prepare($listSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['event_time_local'] = activity_format_local_time($row['event_time'] ?? null);
            $row['extra_json_pretty'] = activity_pretty_json($row['extra_json'] ?? null);
            $row['event_type'] = strtoupper(trim((string)($row['event_type'] ?? '')));
            $row['request_method'] = strtoupper(trim((string)($row['request_method'] ?? '')));
        }
        unset($row);

        echo json_encode([
            'ok' => true,
            'meta' => [
                'total' => $filteredSummary['total'],
                'limit' => $limit,
                'since_hours' => $sinceHours,
                'timezone' => 'America/New_York',
            ],
            'summary' => $filteredSummary,
            'summary_24h' => $global24Summary,
            'rows' => $rows,
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('[MuskyActivityDashboard] load failed: ' . $e->getMessage());
        activity_api_fail('Failed to load activity.', 500);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Musky Activity Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../theme.css?theme=<?= h($theme) ?>">
  <style>
    :root {
      --dash-surface: rgba(255, 255, 255, 0.78);
      --dash-surface-soft: rgba(255, 255, 255, 0.62);
      --dash-soft: rgba(0, 0, 0, 0.05);
      --dash-border: rgba(0, 0, 0, 0.14);
      --dash-shadow: 0 14px 34px rgba(0, 0, 0, 0.10);
      --dash-thead-bg: rgba(248, 249, 250, 0.96);
      --dash-row-hover: rgba(13, 110, 253, 0.08);
      --dash-row-active: rgba(13, 110, 253, 0.14);
      --dash-input-bg: rgba(255, 255, 255, 0.92);
    }

    body {
      margin: 0;
      min-height: 100vh;
    }

    @supports (background: color-mix(in srgb, black, white)) {
      body {
        --dash-surface: color-mix(in srgb, currentColor 12%, transparent);
        --dash-surface-soft: color-mix(in srgb, currentColor 8%, transparent);
        --dash-soft: color-mix(in srgb, currentColor 10%, transparent);
        --dash-border: color-mix(in srgb, currentColor 24%, transparent);
        --dash-thead-bg: color-mix(in srgb, currentColor 14%, transparent);
        --dash-row-hover: color-mix(in srgb, currentColor 16%, transparent);
        --dash-row-active: color-mix(in srgb, currentColor 24%, transparent);
        --dash-input-bg: color-mix(in srgb, currentColor 10%, transparent);
      }
    }

    .page-wrap {
      padding: 16px;
      display: grid;
      gap: 14px;
    }

    .glass {
      border: 1px solid var(--dash-border);
      border-radius: 16px;
      background: var(--dash-surface);
      box-shadow: var(--dash-shadow);
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
      border: 1px solid var(--dash-border);
      border-radius: 12px;
      padding: 10px;
      background: var(--dash-surface-soft);
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
      grid-template-columns: 2fr 1fr 1fr 1fr;
    }

    .filter-grid-extra {
      display: grid;
      gap: 10px;
      grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
    }

    .filter-grid-extra-2 {
      display: grid;
      gap: 10px;
      grid-template-columns: 1fr 1fr 1fr 1fr;
    }

    .filter-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
    }

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
      border-bottom: 1px solid var(--dash-border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      background: var(--dash-soft);
    }

    .table {
      color: inherit;
      border-color: var(--dash-border);
    }

    .table > :not(caption) > * > * {
      border-color: var(--dash-border);
    }

    .table-scroll {
      max-height: 68vh;
      overflow: auto;
    }

    .table thead th {
      position: sticky;
      top: 0;
      z-index: 3;
      background: var(--dash-thead-bg);
      border-bottom-width: 2px;
      white-space: nowrap;
    }

    .table td,
    .table th {
      vertical-align: top;
      white-space: nowrap;
    }

    .table td.wrap {
      white-space: normal;
      min-width: 180px;
      max-width: 360px;
    }

    .table tbody tr:hover {
      background: var(--dash-row-hover);
    }

    .table tbody tr.active-row {
      background: var(--dash-row-active);
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
      border: 1px solid var(--dash-border);
      border-radius: 10px;
      padding: 8px;
      background: var(--dash-surface-soft);
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

    .code-block {
      border: 1px solid var(--dash-border);
      border-radius: 10px;
      overflow: hidden;
      background: var(--dash-surface-soft);
    }

    .code-block .label {
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      padding: 8px 10px;
      border-bottom: 1px solid var(--dash-border);
      background: var(--dash-soft);
    }

    .code-block pre {
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

    .pill {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 999px;
      font-size: 0.74rem;
      font-weight: 700;
      border: 1px solid transparent;
    }

    .pill-login { background: rgba(25, 135, 84, 0.16); border-color: rgba(25, 135, 84, 0.28); }
    .pill-page { background: rgba(13, 110, 253, 0.16); border-color: rgba(13, 110, 253, 0.28); }
    .pill-action { background: rgba(255, 193, 7, 0.22); border-color: rgba(255, 193, 7, 0.34); }
    .pill-logout { background: rgba(108, 117, 125, 0.22); border-color: rgba(108, 117, 125, 0.34); }
    .pill-other { background: rgba(102, 16, 242, 0.16); border-color: rgba(102, 16, 242, 0.30); }

    .form-control,
    .form-select {
      background-color: var(--dash-input-bg);
      color: inherit;
      border-color: var(--dash-border);
    }

    .form-control:focus,
    .form-select:focus {
      background-color: var(--dash-input-bg);
      color: inherit;
      border-color: var(--dash-border);
      box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.22);
    }

    .form-floating > label {
      color: inherit;
      opacity: 0.75;
    }

    .muted { opacity: 0.72; }

    @media (max-width: 1600px) {
      .filter-grid-main { grid-template-columns: 1.6fr 1fr 1fr 1fr; }
      .filter-grid-extra { grid-template-columns: 1fr 1fr 1fr 1fr; }
      .filter-grid-extra-2 { grid-template-columns: 1fr 1fr 1fr; }
    }

    @media (max-width: 1300px) {
      .workspace-grid { grid-template-columns: 1fr; }
      .table-scroll,
      .detail-body { max-height: none; }
      .metric-grid { grid-template-columns: repeat(3, minmax(120px, 1fr)); }
      .filter-grid-main { grid-template-columns: 1fr 1fr; }
      .filter-grid-extra,
      .filter-grid-extra-2 { grid-template-columns: 1fr 1fr; }
    }

    @media (max-width: 760px) {
      .metric-grid { grid-template-columns: repeat(2, minmax(120px, 1fr)); }
      .filter-grid-main,
      .filter-grid-extra,
      .filter-grid-extra-2 { grid-template-columns: 1fr; }
      .detail-meta { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body class="<?= h($theme) ?>">
  <div class="page-wrap">
    <section class="glass">
      <div class="headbar">
        <a href="../index.php" class="btn btn-outline-secondary">Back</a>
        <div class="head-title">
          <h1>Musky Activity Dashboard</h1>
          <div class="sub">Modern activity log view. Default startup scope is last 1 hour. Times shown in <?= h($displayTimeZoneLabel) ?>.</div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <button id="refreshBtn" class="btn btn-primary">Refresh</button>
          <button id="autoBtn" data-on="1" class="btn btn-outline-primary">Auto Refresh: On</button>
          <span id="lastRefresh" class="small muted">Never refreshed</span>
        </div>
      </div>
      <div class="metric-grid">
        <div class="metric"><div id="mTotal" class="v">0</div><div class="k">Matches</div></div>
        <div class="metric"><div id="mUsers" class="v">0</div><div class="k">Unique Users</div></div>
        <div class="metric"><div id="mPages" class="v">0</div><div class="k">Page Views</div></div>
        <div class="metric"><div id="mActions" class="v">0</div><div class="k">Actions</div></div>
        <div class="metric"><div id="mLogins" class="v">0</div><div class="k">Logins</div></div>
        <div class="metric"><div id="mLogouts" class="v">0</div><div class="k">Logouts</div></div>
      </div>
    </section>

    <section class="glass filter-wrap">
      <div class="filter-grid-main">
        <div class="form-floating">
          <input id="f_q" class="form-control" type="search" placeholder="Search everything">
          <label for="f_q">Global Search (user, page, action, serial, IP, session, JSON)</label>
        </div>

        <div class="form-floating">
          <select id="f_type" class="form-select">
            <option value="any" selected>Any event</option>
            <option value="LOGIN">Login</option>
            <option value="PAGE_VIEW">Page View</option>
            <option value="ACTION">Action</option>
            <option value="LOGOUT">Logout</option>
          </select>
          <label for="f_type">Event Type</label>
        </div>

        <div class="form-floating">
          <select id="f_since" class="form-select">
            <option value="1" selected>Last 1 hour (default)</option>
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
          <select id="f_limit" class="form-select">
            <option value="100">100 rows</option>
            <option value="250" selected>250 rows</option>
            <option value="500">500 rows</option>
            <option value="1000">1000 rows</option>
          </select>
          <label for="f_limit">Row Limit</label>
        </div>
      </div>

      <div class="filter-grid-extra">
        <div class="form-floating">
          <input id="f_email" class="form-control" type="text" placeholder="Email contains">
          <label for="f_email">Email</label>
        </div>

        <div class="form-floating">
          <input id="f_user" class="form-control" type="text" placeholder="User name contains">
          <label for="f_user">User Name</label>
        </div>

        <div class="form-floating">
          <input id="f_page" class="form-control" type="text" placeholder="Page path contains">
          <label for="f_page">Page Path</label>
        </div>

        <div class="form-floating">
          <input id="f_action" class="form-control" type="text" placeholder="Action contains">
          <label for="f_action">Action</label>
        </div>

        <div class="form-floating">
          <input id="f_method" class="form-control" type="text" placeholder="GET / POST">
          <label for="f_method">Method</label>
        </div>
      </div>

      <div class="filter-grid-extra-2">
        <div class="form-floating">
          <input id="f_serial" class="form-control" type="text" placeholder="Serial contains">
          <label for="f_serial">Target Serials</label>
        </div>

        <div class="form-floating">
          <input id="f_asset" class="form-control" type="text" placeholder="Asset tag contains">
          <label for="f_asset">Target Asset Tags</label>
        </div>

        <div class="form-floating">
          <input id="f_ip" class="form-control" type="text" placeholder="IP contains">
          <label for="f_ip">IP Address</label>
        </div>

        <div class="form-floating">
          <input id="f_session" class="form-control" type="text" placeholder="Session contains">
          <label for="f_session">Session ID</label>
        </div>
      </div>

      <div class="filter-actions">
        <div class="small muted" id="filterMeta">No data yet</div>
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
            <strong>Activity Rows</strong>
            <span id="tableMeta" class="small muted ms-2">No data yet</span>
          </div>
          <div id="loading" class="small muted"></div>
        </div>

        <div class="table-scroll">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>When</th>
                <th>Type</th>
                <th>User</th>
                <th>Page</th>
                <th>Action</th>
                <th class="wrap">Targets</th>
                <th>IP</th>
                <th>Method</th>
                <th class="wrap">Session</th>
              </tr>
            </thead>
            <tbody id="rows">
              <tr><td colspan="9" class="text-center py-4">Loading activity...</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <aside class="glass detail-card">
        <div class="detail-head">
          <div><strong>Event Detail</strong></div>
          <span id="detailHint" class="small muted">Select a row to inspect.</span>
        </div>
        <div class="detail-body" id="detailPane">
          <div class="muted">No event selected yet.</div>
        </div>
      </aside>
    </section>
  </div>

  <script>
    const state = {
      rows: [],
      selectedId: null,
      autoTimer: null,
      loading: false,
    };

    const elements = {
      mTotal: document.getElementById('mTotal'),
      mUsers: document.getElementById('mUsers'),
      mPages: document.getElementById('mPages'),
      mActions: document.getElementById('mActions'),
      mLogins: document.getElementById('mLogins'),
      mLogouts: document.getElementById('mLogouts'),
      rows: document.getElementById('rows'),
      tableMeta: document.getElementById('tableMeta'),
      filterMeta: document.getElementById('filterMeta'),
      loading: document.getElementById('loading'),
      detailPane: document.getElementById('detailPane'),
      detailHint: document.getElementById('detailHint'),
      refreshBtn: document.getElementById('refreshBtn'),
      autoBtn: document.getElementById('autoBtn'),
      lastRefresh: document.getElementById('lastRefresh'),
      applyBtn: document.getElementById('applyBtn'),
      resetBtn: document.getElementById('resetBtn'),
    };

    function esc(value) {
      return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
      }[ch]));
    }

    function shortText(value, max = 72) {
      const text = String(value ?? '').trim();
      if (!text) return '—';
      return text.length > max ? `${esc(text.slice(0, max))}...` : esc(text);
    }

    function getFilterValue(id) {
      const el = document.getElementById(id);
      return el ? el.value.trim() : '';
    }

    function eventPillClass(type) {
      const v = String(type || '').toUpperCase();
      if (v === 'LOGIN') return 'pill pill-login';
      if (v === 'PAGE_VIEW') return 'pill pill-page';
      if (v === 'ACTION') return 'pill pill-action';
      if (v === 'LOGOUT') return 'pill pill-logout';
      return 'pill pill-other';
    }

    function buildUrl() {
      const u = new URL(window.location.href);
      u.searchParams.set('api', 'list');
      u.searchParams.set('q', getFilterValue('f_q'));
      u.searchParams.set('type', getFilterValue('f_type'));
      u.searchParams.set('since_hours', getFilterValue('f_since'));
      u.searchParams.set('limit', getFilterValue('f_limit'));
      u.searchParams.set('email', getFilterValue('f_email'));
      u.searchParams.set('user', getFilterValue('f_user'));
      u.searchParams.set('page', getFilterValue('f_page'));
      u.searchParams.set('action', getFilterValue('f_action'));
      u.searchParams.set('method', getFilterValue('f_method'));
      u.searchParams.set('serial', getFilterValue('f_serial'));
      u.searchParams.set('asset', getFilterValue('f_asset'));
      u.searchParams.set('ip', getFilterValue('f_ip'));
      u.searchParams.set('session', getFilterValue('f_session'));
      return u;
    }

    function setFiltersToDefaults() {
      document.getElementById('f_q').value = '';
      document.getElementById('f_type').value = 'any';
      document.getElementById('f_since').value = '1';
      document.getElementById('f_limit').value = '250';
      document.getElementById('f_email').value = '';
      document.getElementById('f_user').value = '';
      document.getElementById('f_page').value = '';
      document.getElementById('f_action').value = '';
      document.getElementById('f_method').value = '';
      document.getElementById('f_serial').value = '';
      document.getElementById('f_asset').value = '';
      document.getElementById('f_ip').value = '';
      document.getElementById('f_session').value = '';
    }

    function findRowById(id) {
      return state.rows.find((row) => String(row.id) === String(id));
    }

    function renderDetail(row) {
      if (!row) {
        elements.detailHint.textContent = 'Select a row to inspect.';
        elements.detailPane.innerHTML = '<div class="muted">No event selected yet.</div>';
        return;
      }

      elements.detailHint.textContent = `Event #${row.id}`;

      const tiles = [
        ['Event ID', row.id],
        ['When', row.event_time_local || row.event_time || '—'],
        ['Event Type', row.event_type || '—'],
        ['User Email', row.user_email || '—'],
        ['User Name', row.user_name || '—'],
        ['Page Path', row.page_path || '—'],
        ['Action', row.action_name || '—'],
        ['Serials', row.target_serials || '—'],
        ['Asset Tags', row.target_asset_tags || '—'],
        ['Method', row.request_method || '—'],
        ['IP Address', row.ip_address || '—'],
        ['Session ID', row.session_id || '—'],
      ];

      const tileHtml = tiles.map(([k, v]) => `
        <div class="tile">
          <div class="k">${esc(k)}</div>
          <div class="v">${esc(v ?? '—')}</div>
        </div>
      `).join('');

      elements.detailPane.innerHTML = `
        <div class="detail-meta">${tileHtml}</div>
        <div class="code-block">
          <div class="label">User Agent</div>
          <pre>${esc(row.user_agent || '—')}</pre>
        </div>
        <div class="code-block">
          <div class="label">Extra JSON</div>
          <pre>${esc(row.extra_json_pretty || row.extra_json || '—')}</pre>
        </div>
      `;
    }

    function renderRows(rows) {
      if (!rows.length) {
        elements.rows.innerHTML = '<tr><td colspan="9" class="text-center py-4">No activity rows matched the current filters.</td></tr>';
        return;
      }

      elements.rows.innerHTML = rows.map((row) => {
        const isActive = String(state.selectedId) === String(row.id);

        return `
          <tr data-id="${esc(row.id)}" class="${isActive ? 'active-row' : ''}">
            <td>${esc(row.event_time_local || row.event_time || '—')}</td>
            <td><span class="${eventPillClass(row.event_type)}">${esc(row.event_type || '—')}</span></td>
            <td><div>${shortText(row.user_name, 28)}</div><div class="small muted">${shortText(row.user_email, 34)}</div></td>
            <td>${shortText(row.page_path, 38)}</td>
            <td>${shortText(row.action_name, 28)}</td>
            <td class="wrap"><div>${shortText(row.target_serials, 52)}</div><div class="small muted">${shortText(row.target_asset_tags, 52)}</div></td>
            <td>${shortText(row.ip_address, 22)}</td>
            <td>${shortText(row.request_method || '—', 12)}</td>
            <td class="wrap">${shortText(row.session_id, 38)}</td>
          </tr>
        `;
      }).join('');

      elements.rows.querySelectorAll('tr').forEach((tr) => {
        tr.addEventListener('click', () => {
          const id = tr.getAttribute('data-id');
          state.selectedId = id;
          renderRows(state.rows);
          renderDetail(findRowById(id));
        });
      });
    }

    function renderMetrics(summary) {
      elements.mTotal.textContent = String(summary.total ?? 0);
      elements.mUsers.textContent = String(summary.unique_users ?? 0);
      elements.mPages.textContent = String(summary.page_views ?? 0);
      elements.mActions.textContent = String(summary.actions ?? 0);
      elements.mLogins.textContent = String(summary.logins ?? 0);
      elements.mLogouts.textContent = String(summary.logouts ?? 0);
    }

    async function loadActivity() {
      if (state.loading) {
        return;
      }
      state.loading = true;
      elements.loading.textContent = 'Loading...';
      elements.tableMeta.textContent = 'Loading...';

      try {
        const res = await fetch(buildUrl().toString(), {
          credentials: 'same-origin',
          cache: 'no-store',
        });
        const data = await res.json();

        if (!data.ok) {
          throw new Error(data.error || 'Failed to load activity');
        }

        state.rows = Array.isArray(data.rows) ? data.rows : [];
        renderMetrics(data.summary || {});
        renderRows(state.rows);

        elements.tableMeta.textContent = `${data.meta?.total ?? 0} match(es), showing ${state.rows.length}`;
        elements.filterMeta.textContent = `24h baseline: ${data.summary_24h?.total ?? 0} events, ${data.summary_24h?.unique_users ?? 0} users`;

        if (state.selectedId) {
          const match = findRowById(state.selectedId);
          if (match) {
            renderDetail(match);
          } else if (state.rows.length) {
            state.selectedId = String(state.rows[0].id);
            renderRows(state.rows);
            renderDetail(state.rows[0]);
          } else {
            state.selectedId = null;
            renderDetail(null);
          }
        } else if (state.rows.length) {
          state.selectedId = String(state.rows[0].id);
          renderRows(state.rows);
          renderDetail(state.rows[0]);
        } else {
          renderDetail(null);
        }

        elements.lastRefresh.textContent = `Last refresh: ${new Date().toLocaleTimeString()}`;
      } catch (error) {
        elements.rows.innerHTML = `<tr><td colspan="9" class="text-danger text-center py-4">${esc(error.message || error)}</td></tr>`;
        elements.tableMeta.textContent = 'Load failed';
        elements.filterMeta.textContent = 'Load failed';
        renderDetail(null);
      } finally {
        elements.loading.textContent = '';
        state.loading = false;
      }
    }

    function applyFilters() {
      state.selectedId = null;
      loadActivity();
    }

    function resetFilters() {
      setFiltersToDefaults();
      applyFilters();
    }

    function syncAutoRefresh() {
      const on = elements.autoBtn.dataset.on === '1';
      elements.autoBtn.textContent = `Auto Refresh: ${on ? 'On' : 'Off'}`;

      if (state.autoTimer) {
        clearInterval(state.autoTimer);
        state.autoTimer = null;
      }

      if (on) {
        state.autoTimer = setInterval(loadActivity, 30000);
      }
    }

    elements.applyBtn.addEventListener('click', applyFilters);
    elements.resetBtn.addEventListener('click', resetFilters);
    elements.refreshBtn.addEventListener('click', loadActivity);

    elements.autoBtn.addEventListener('click', () => {
      elements.autoBtn.dataset.on = elements.autoBtn.dataset.on === '1' ? '0' : '1';
      syncAutoRefresh();
    });

    [
      'f_q', 'f_email', 'f_user', 'f_page', 'f_action', 'f_method', 'f_serial', 'f_asset', 'f_ip', 'f_session'
    ].forEach((id) => {
      const el = document.getElementById(id);
      el.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          applyFilters();
        }
      });
    });

    ['f_type', 'f_since', 'f_limit'].forEach((id) => {
      const el = document.getElementById(id);
      el.addEventListener('change', applyFilters);
    });

    // Force startup defaults so browser-restored form values do not hide all rows.
    setFiltersToDefaults();
    syncAutoRefresh();
    loadActivity();
  </script>
</body>
</html>
