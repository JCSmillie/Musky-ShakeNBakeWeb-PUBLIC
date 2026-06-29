<?php
declare(strict_types=1);

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/NewHardware.php';

if (!defined('NORA_CONNECT_NO_AUTO')) {
    define('NORA_CONNECT_NO_AUTO', true);
}
if (!defined('NORA_CONNECT_THROW')) {
    define('NORA_CONNECT_THROW', true);
}
require_once __DIR__ . '/../../Functions/nora_connect.php';

function new_hardware_admin_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function new_hardware_admin_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$wantsJson = isset($_GET['api']) || (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_GET['api'])
);

$allowed = musky_require_any_tool(
    $_SESSION['musky_user']['allowed_tools'] ?? '',
    ['ADMIN_PANEL'],
    [
        'response' => $wantsJson ? 'json' : 'html',
        'status' => 403,
        'message' => 'Missing required Musky permissions.',
        'payload' => ['error' => 'access_denied', 'detail' => 'Missing required Musky permissions.'],
    ]
);

try {
    $pdo = nora_connect();
} catch (Throwable $e) {
    if ($wantsJson) {
        new_hardware_admin_json(['ok' => false, 'error' => 'db_unavailable'], 500);
    }
    http_response_code(500);
    echo 'Nora database connection is unavailable.';
    exit;
}

musky_new_hardware_ensure_schema($pdo);

$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$userEmail = $prefs['email'] ?? musky_new_hardware_actor_email();
$csrfToken = musky_csrf_token('new_hardware_admin');
$flash = null;
$error = null;

if (isset($_GET['api']) && $_GET['api'] === 'status') {
    $status = trim((string)($_GET['status'] ?? 'ALL'));
    $query = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 300);

    new_hardware_admin_json([
        'ok' => true,
        'counts' => musky_new_hardware_fetch_counts($pdo),
        'rows' => musky_new_hardware_fetch_units($pdo, $status, $query, $limit),
        'batches' => musky_new_hardware_fetch_batches($pdo, 10),
        'server_time' => musky_new_hardware_now(),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['api']) && $_GET['api'] === 'release') {
    musky_csrf_require('new_hardware_admin');
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $unitId = (int)($input['unit_id'] ?? 0);
    $result = musky_new_hardware_release_claim($pdo, $unitId, (string)$userEmail, true);
    if ($result['ok']) {
        musky_activity_log([
            'event_type' => 'ACTION',
            'action_name' => 'NEW_HARDWARE_ADMIN_RELEASE',
            'target_serials' => '',
            'extra' => ['unit_id' => $unitId],
        ]);
        new_hardware_admin_json(['ok' => true, 'message' => $result['message']]);
    }

    new_hardware_admin_json(['ok' => false, 'message' => $result['message']], 400);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'upload_batch') {
    musky_csrf_require('new_hardware_admin');

    $batchLabel = trim((string)($_POST['batch_label'] ?? ''));
    $sourceNote = trim((string)($_POST['source_note'] ?? ''));
    $serialBlob = (string)($_POST['serial_blob'] ?? '');
    $sourceFilename = '';
    $fileParseError = null;

    if (!empty($_FILES['serial_file']['tmp_name']) && is_uploaded_file($_FILES['serial_file']['tmp_name'])) {
        $sourceFilename = trim((string)($_FILES['serial_file']['name'] ?? ''));
        $parsedFile = musky_new_hardware_parse_uploaded_serial_file($_FILES['serial_file']);
        if (!$parsedFile['ok']) {
            $fileParseError = $parsedFile['message'];
        } else {
            $fileSerials = trim((string)($parsedFile['serial_blob'] ?? ''));
            if ($fileSerials !== '') {
                $serialBlob = trim($serialBlob . "\n" . $fileSerials);
            }
        }
    }

    if ($fileParseError !== null) {
        $result = [
            'ok' => false,
            'message' => $fileParseError,
        ];
    } else {
        $result = musky_new_hardware_create_batch(
            $pdo,
            $batchLabel,
            $serialBlob,
            $sourceFilename,
            $sourceNote,
            (string)$userEmail
        );
    }

    if ($result['ok']) {
        $flash = $result['message'];
        musky_activity_log([
            'event_type' => 'ACTION',
            'action_name' => 'NEW_HARDWARE_UPLOAD',
            'extra' => [
                'batch_id' => $result['batch_id'] ?? null,
                'accepted_count' => $result['accepted_count'] ?? 0,
                'duplicate_count' => $result['duplicate_count'] ?? 0,
                'already_queued_count' => $result['already_queued_count'] ?? 0,
                'invalid_count' => $result['invalid_count'] ?? 0,
            ],
        ]);
    } else {
        $error = $result['message'];
    }
}

$counts = musky_new_hardware_fetch_counts($pdo);
$rows = musky_new_hardware_fetch_units($pdo, 'ALL', '', 250);
$batches = musky_new_hardware_fetch_batches($pdo, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>New Hardware Admin</title>
  <link rel="stylesheet" href="../theme.css?theme=<?= new_hardware_admin_h($theme) ?>">
  <style>
    body {
      margin: 0;
      font-family: sans-serif;
      background: #eef3f8;
      color: #123;
    }
    body.dark-mode {
      background: #141a22;
      color: #ebf1f7;
    }
    body.musky-mode {
      background: #0c2742;
      color: #eef6ff;
    }
    .page-shell {
      max-width: 1320px;
      margin: 0 auto;
      padding: 24px;
    }
    .hero,
    .panel {
      background: rgba(255,255,255,0.9);
      border: 1px solid rgba(18,34,51,0.10);
      border-radius: 20px;
      box-shadow: 0 16px 36px rgba(18,34,51,0.08);
      padding: 22px;
      margin-bottom: 20px;
    }
    body.dark-mode .hero,
    body.dark-mode .panel,
    body.musky-mode .hero,
    body.musky-mode .panel {
      background: rgba(9,17,26,0.88);
      border-color: rgba(255,255,255,0.08);
      box-shadow: 0 16px 36px rgba(0,0,0,0.32);
    }
    .hero-top {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      align-items: flex-start;
      flex-wrap: wrap;
    }
    .hero h1,
    .panel h2,
    .panel h3 {
      margin-top: 0;
    }
    .hero p,
    .panel p,
    .mini-note {
      opacity: 0.84;
    }
    .button-row,
    .stats-grid,
    .filters,
    .split-grid,
    .batch-grid {
      display: grid;
      gap: 14px;
    }
    .button-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
    }
    .stats-grid {
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
    .split-grid {
      grid-template-columns: minmax(340px, 1fr) minmax(420px, 1.35fr);
      align-items: start;
    }
    .batch-grid {
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    }
    .stat-card {
      border-radius: 16px;
      padding: 16px;
      background: linear-gradient(145deg, rgba(255,255,255,0.95), rgba(240,246,252,0.92));
      border: 1px solid rgba(18,34,51,0.08);
    }
    body.dark-mode .stat-card,
    body.musky-mode .stat-card {
      background: linear-gradient(145deg, rgba(20,29,39,0.98), rgba(10,15,22,0.96));
      border-color: rgba(255,255,255,0.08);
    }
    .stat-value {
      font-size: 2rem;
      font-weight: 800;
      margin-bottom: 6px;
    }
    form {
      margin: 0;
    }
    label {
      display: block;
      font-weight: 700;
      margin-bottom: 6px;
    }
    input[type="text"],
    input[type="search"],
    textarea,
    select {
      width: 100%;
      box-sizing: border-box;
      padding: 12px 13px;
      border-radius: 12px;
      border: 1px solid rgba(18,34,51,0.16);
      background: rgba(255,255,255,0.96);
      color: inherit;
      font: inherit;
    }
    body.dark-mode input[type="text"],
    body.dark-mode input[type="search"],
    body.dark-mode textarea,
    body.dark-mode select,
    body.musky-mode input[type="text"],
    body.musky-mode input[type="search"],
    body.musky-mode textarea,
    body.musky-mode select {
      background: rgba(20,29,39,0.96);
      border-color: rgba(255,255,255,0.12);
      color: inherit;
    }
    textarea {
      min-height: 220px;
      resize: vertical;
    }
    .primary-btn,
    .secondary-btn,
    .danger-btn,
    .ghost-link {
      appearance: none;
      border: none;
      border-radius: 999px;
      padding: 11px 18px;
      font: inherit;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .primary-btn {
      background: #216fdb;
      color: #fff;
    }
    .secondary-btn {
      background: rgba(33,111,219,0.12);
      color: inherit;
      border: 1px solid rgba(33,111,219,0.22);
    }
    .danger-btn {
      background: #c53b30;
      color: #fff;
    }
    .ghost-link {
      background: rgba(18,34,51,0.08);
      color: inherit;
    }
    .flash,
    .error {
      border-radius: 14px;
      padding: 14px 16px;
      margin-bottom: 16px;
      font-weight: 700;
    }
    .flash {
      background: rgba(28,149,84,0.12);
      border: 1px solid rgba(28,149,84,0.22);
      color: #0a6335;
    }
    .error {
      background: rgba(197,59,48,0.12);
      border: 1px solid rgba(197,59,48,0.22);
      color: #8d2118;
    }
    body.dark-mode .flash,
    body.musky-mode .flash {
      color: #aee7bf;
    }
    body.dark-mode .error,
    body.musky-mode .error {
      color: #ffb6b0;
    }
    .filters {
      grid-template-columns: minmax(180px, 220px) minmax(220px, 1fr) auto;
      align-items: end;
      margin-bottom: 16px;
    }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 0.85rem;
      font-weight: 800;
      letter-spacing: 0.03em;
    }
    .status-available {
      background: rgba(28,149,84,0.14);
      color: #0a6335;
    }
    .status-in-progress {
      background: rgba(207,126,15,0.16);
      color: #8d5400;
    }
    .status-completed {
      background: rgba(33,111,219,0.14);
      color: #0f4f9f;
    }
    body.dark-mode .status-available,
    body.musky-mode .status-available {
      color: #abf0c0;
    }
    body.dark-mode .status-in-progress,
    body.musky-mode .status-in-progress {
      color: #ffd48f;
    }
    body.dark-mode .status-completed,
    body.musky-mode .status-completed {
      color: #aaccff;
    }
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th,
    td {
      text-align: left;
      padding: 12px 10px;
      border-bottom: 1px solid rgba(18,34,51,0.10);
      vertical-align: top;
      font-size: 0.94rem;
    }
    body.dark-mode th,
    body.dark-mode td,
    body.musky-mode th,
    body.musky-mode td {
      border-bottom-color: rgba(255,255,255,0.08);
    }
    th {
      font-size: 0.84rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      opacity: 0.76;
    }
    .muted {
      opacity: 0.72;
      font-size: 0.88rem;
    }
    .table-wrap {
      overflow-x: auto;
    }
    .batch-card {
      border-radius: 16px;
      padding: 16px;
      border: 1px solid rgba(18,34,51,0.10);
      background: rgba(255,255,255,0.75);
    }
    body.dark-mode .batch-card,
    body.musky-mode .batch-card {
      background: rgba(255,255,255,0.03);
      border-color: rgba(255,255,255,0.08);
    }
    .batch-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 14px;
      font-size: 0.9rem;
      opacity: 0.84;
      margin-top: 8px;
    }
    .note-block {
      margin-top: 10px;
      padding: 10px 12px;
      border-radius: 12px;
      background: rgba(18,34,51,0.05);
      font-size: 0.9rem;
    }
    body.dark-mode .note-block,
    body.musky-mode .note-block {
      background: rgba(255,255,255,0.04);
    }
    @media (max-width: 980px) {
      .split-grid,
      .filters {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="<?= new_hardware_admin_h($theme) ?>">
  <div class="page-shell">
    <section class="hero">
      <div class="hero-top">
        <div>
          <div class="mini-note">Admin Control Surface</div>
          <h1>New Hardware Intake Admin</h1>
          <p>Upload Apple serial lists, watch helpers claim machines in real time, and release stalled scans back to the pool if someone gets stuck.</p>
        </div>
        <div class="button-row">
          <a class="ghost-link" href="../index.php">Back to Musky Hub</a>
          <a class="secondary-btn" href="../NewHardware/index.php">Open Intake Screen</a>
        </div>
      </div>
    </section>

    <?php if ($flash): ?>
      <div class="flash"><?= new_hardware_admin_h($flash) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="error"><?= new_hardware_admin_h($error) ?></div>
    <?php endif; ?>

    <div class="split-grid">
      <section class="panel">
        <h2>Bulk Upload</h2>
        <p>Paste serials, drop a text or CSV file, or do both. Musky normalizes the Apple scan prefix so the intake side can match the box barcode cleanly.</p>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= new_hardware_admin_h($csrfToken) ?>">
          <input type="hidden" name="action" value="upload_batch">

          <div style="margin-bottom:14px;">
            <label for="batch_label">Batch Label</label>
            <input id="batch_label" type="text" name="batch_label" placeholder="June 2026 Apple Drop">
          </div>

          <div style="margin-bottom:14px;">
            <label for="source_note">Source Note</label>
            <input id="source_note" type="text" name="source_note" placeholder="Optional note about vendor sheet, room, or shipment">
          </div>

          <div style="margin-bottom:14px;">
            <label for="serial_file">Optional File Upload</label>
            <input id="serial_file" type="file" name="serial_file" accept=".txt,.csv,.tsv,.log">
            <div class="mini-note" style="margin-top:8px;">
              TXT: one serial per line. CSV/TSV: must contain a <strong>SERIAL</strong> or <strong>SERIAL_NO</strong> column.
            </div>
          </div>

          <div style="margin-bottom:16px;">
            <label for="serial_blob">Paste a Lot of Serials</label>
            <textarea id="serial_blob" name="serial_blob" placeholder="Paste serials here, one per line or separated by spaces/commas"></textarea>
          </div>

          <button class="primary-btn" type="submit">Import Hardware Pool</button>
        </form>
      </section>

      <section class="panel">
        <h2>Pool Snapshot</h2>
        <p class="mini-note">This panel refreshes automatically every 12 seconds.</p>
        <div class="stats-grid" id="statsGrid">
          <div class="stat-card">
            <div class="stat-value" id="statTotal"><?= (int)$counts['total'] ?></div>
            <div>Total Serials</div>
          </div>
          <div class="stat-card">
            <div class="stat-value" id="statAvailable"><?= (int)$counts['available'] ?></div>
            <div>Available</div>
          </div>
          <div class="stat-card">
            <div class="stat-value" id="statProgress"><?= (int)$counts['in_progress'] ?></div>
            <div>In Progress</div>
          </div>
          <div class="stat-card">
            <div class="stat-value" id="statCompleted"><?= (int)$counts['completed'] ?></div>
            <div>Completed</div>
          </div>
          <div class="stat-card">
            <div class="stat-value" id="statAlreadyInNora"><?= (int)($counts['already_in_nora'] ?? 0) ?></div>
            <div>Already In Nora</div>
          </div>
        </div>
      </section>
    </div>

    <section class="panel">
      <h2>Live Hardware Status</h2>
      <div class="filters">
        <div>
          <label for="filterStatus">Status</label>
          <select id="filterStatus">
            <option value="ALL">All Rows</option>
            <option value="AVAILABLE">Available</option>
            <option value="IN_PROGRESS">In Progress</option>
            <option value="COMPLETED">Completed</option>
            <option value="ALREADY_IN_NORA">Already In Nora</option>
          </select>
        </div>
        <div>
          <label for="filterQuery">Search</label>
          <input id="filterQuery" type="search" placeholder="Serial, asset tag, owner, batch, or processor">
        </div>
        <div class="button-row">
          <button class="secondary-btn" type="button" id="refreshButton">Refresh Now</button>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Serial</th>
              <th>Status</th>
              <th>Batch</th>
              <th>Operator</th>
              <th>Asset / Assignment</th>
              <th>Owner</th>
              <th>Timing</th>
              <th>Note</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="statusTableBody">
            <?php foreach ($rows as $row): ?>
              <?php
                $statusLabel = (string)($row['workflow_state'] ?? $row['status']);
                $statusClass = match ($statusLabel) {
                    'AVAILABLE' => 'status-available',
                    'IN_PROGRESS' => 'status-in-progress',
                    'COMPLETED' => 'status-completed',
                    'ALREADY_IN_NORA' => 'status-completed',
                    default => '',
                };
              ?>
              <tr>
                <td>
                  <strong><?= new_hardware_admin_h($row['serial_match_key']) ?></strong>
                  <div class="muted"><?= new_hardware_admin_h($row['serial_import_raw']) ?></div>
                </td>
                <td><span class="status-pill <?= new_hardware_admin_h($statusClass) ?>"><?= new_hardware_admin_h($statusLabel) ?></span></td>
                <td><?= new_hardware_admin_h($row['batch_label']) ?></td>
                <td>
                  <div><?= new_hardware_admin_h($row['claimed_by'] ?: $row['completed_by'] ?: 'Unclaimed') ?></div>
                  <?php if (!empty($row['completed_by']) && !empty($row['claimed_by']) && strcasecmp((string)$row['completed_by'], (string)$row['claimed_by']) !== 0): ?>
                    <div class="muted">Completed by <?= new_hardware_admin_h($row['completed_by']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div><?= new_hardware_admin_h($row['asset_tag'] ?: 'Pending asset tag') ?></div>
                  <div class="muted"><?= new_hardware_admin_h($row['assignment_input'] ?: 'Pending assignment') ?></div>
                </td>
                <td>
                  <div><?= new_hardware_admin_h($row['owner_name'] ?: 'Not confirmed yet') ?></div>
                  <div class="muted"><?= new_hardware_admin_h($row['owner_email'] ?: '') ?></div>
                  <?php if (!empty($row['device_exists_serial']) && ($row['workflow_state'] ?? '') === 'ALREADY_IN_NORA'): ?>
                    <div class="muted">Existing device row: <?= new_hardware_admin_h((string)$row['device_exists_serial']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="muted">Claimed: <?= new_hardware_admin_h($row['claim_started_at'] ?: 'Not yet') ?></div>
                  <div class="muted">Completed: <?= new_hardware_admin_h($row['completed_at'] ?: 'Not yet') ?></div>
                </td>
                <td><?= new_hardware_admin_h((string)($row['workflow_note'] ?? $row['last_note'] ?? '')) ?></td>
                <td>
                  <?php if (($row['status'] ?? '') === 'IN_PROGRESS' && empty($row['device_exists_in_nora'])): ?>
                    <button class="danger-btn" type="button" onclick="releaseUnit(<?= (int)$row['id'] ?>)">Release</button>
                  <?php else: ?>
                    <span class="muted">None</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <h2>Recent Batches</h2>
      <div class="batch-grid" id="batchGrid">
        <?php foreach ($batches as $batch): ?>
          <div class="batch-card">
            <h3><?= new_hardware_admin_h($batch['batch_label']) ?></h3>
            <div class="batch-meta">
              <span>Uploaded by <?= new_hardware_admin_h($batch['uploaded_by']) ?></span>
              <span><?= new_hardware_admin_h($batch['created_at']) ?></span>
            </div>
            <div class="batch-meta">
              <span>Accepted <?= (int)$batch['accepted_count'] ?></span>
              <span>Duplicates <?= (int)$batch['duplicate_count'] ?></span>
              <span>Raw <?= (int)$batch['raw_count'] ?></span>
            </div>
            <?php if (!empty($batch['source_filename'])): ?>
              <div class="note-block">File: <?= new_hardware_admin_h($batch['source_filename']) ?></div>
            <?php endif; ?>
            <?php if (!empty($batch['source_note'])): ?>
              <div class="note-block"><?= new_hardware_admin_h($batch['source_note']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <script>
    const statusTableBody = document.getElementById('statusTableBody');
    const batchGrid = document.getElementById('batchGrid');
    const filterStatus = document.getElementById('filterStatus');
    const filterQuery = document.getElementById('filterQuery');
    const refreshButton = document.getElementById('refreshButton');
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    function h(value) {
      const div = document.createElement('div');
      div.textContent = value == null ? '' : String(value);
      return div.innerHTML;
    }

    function statusClass(status) {
      switch (String(status || '').toUpperCase()) {
        case 'AVAILABLE': return 'status-available';
        case 'IN_PROGRESS': return 'status-in-progress';
        case 'COMPLETED': return 'status-completed';
        case 'ALREADY_IN_NORA': return 'status-completed';
        default: return '';
      }
    }

    function renderCounts(counts) {
      document.getElementById('statTotal').textContent = counts.total || 0;
      document.getElementById('statAvailable').textContent = counts.available || 0;
      document.getElementById('statProgress').textContent = counts.in_progress || 0;
      document.getElementById('statCompleted').textContent = counts.completed || 0;
      document.getElementById('statAlreadyInNora').textContent = counts.already_in_nora || 0;
    }

    function renderRows(rows) {
      if (!Array.isArray(rows) || !rows.length) {
        statusTableBody.innerHTML = '<tr><td colspan="9" class="muted">No rows match the current filter.</td></tr>';
        return;
      }

      statusTableBody.innerHTML = rows.map((row) => {
        const label = row.workflow_state || row.status || '';
        const claimed = row.claimed_by || row.completed_by || 'Unclaimed';
        const completedBy = row.completed_by && row.claimed_by && String(row.completed_by).toLowerCase() !== String(row.claimed_by).toLowerCase()
          ? `<div class="muted">Completed by ${h(row.completed_by)}</div>`
          : '';
        const deviceLine = row.workflow_state === 'ALREADY_IN_NORA' && row.device_exists_serial
          ? `<div class="muted">Existing device row: ${h(row.device_exists_serial)}</div>`
          : '';
        const action = row.status === 'IN_PROGRESS' && !Number(row.device_exists_in_nora || 0)
          ? `<button class="danger-btn" type="button" onclick="releaseUnit(${Number(row.id)})">Release</button>`
          : '<span class="muted">None</span>';
        return `
          <tr>
            <td>
              <strong>${h(row.serial_match_key || '')}</strong>
              <div class="muted">${h(row.serial_import_raw || '')}</div>
            </td>
            <td><span class="status-pill ${statusClass(label)}">${h(label)}</span></td>
            <td>${h(row.batch_label || '')}</td>
            <td>
              <div>${h(claimed)}</div>
              ${completedBy}
            </td>
            <td>
              <div>${h(row.asset_tag || 'Pending asset tag')}</div>
              <div class="muted">${h(row.assignment_input || 'Pending assignment')}</div>
            </td>
            <td>
              <div>${h(row.owner_name || 'Not confirmed yet')}</div>
              <div class="muted">${h(row.owner_email || '')}</div>
              ${deviceLine}
            </td>
            <td>
              <div class="muted">Claimed: ${h(row.claim_started_at || 'Not yet')}</div>
              <div class="muted">Completed: ${h(row.completed_at || 'Not yet')}</div>
            </td>
            <td>${h(row.workflow_note || row.last_note || '')}</td>
            <td>${action}</td>
          </tr>
        `;
      }).join('');
    }

    function renderBatches(batches) {
      if (!Array.isArray(batches) || !batches.length) {
        batchGrid.innerHTML = '<div class="muted">No new hardware batches yet.</div>';
        return;
      }

      batchGrid.innerHTML = batches.map((batch) => `
        <div class="batch-card">
          <h3>${h(batch.batch_label || '')}</h3>
          <div class="batch-meta">
            <span>Uploaded by ${h(batch.uploaded_by || '')}</span>
            <span>${h(batch.created_at || '')}</span>
          </div>
          <div class="batch-meta">
            <span>Accepted ${Number(batch.accepted_count || 0)}</span>
            <span>Duplicates ${Number(batch.duplicate_count || 0)}</span>
            <span>Raw ${Number(batch.raw_count || 0)}</span>
          </div>
          ${batch.source_filename ? `<div class="note-block">File: ${h(batch.source_filename)}</div>` : ''}
          ${batch.source_note ? `<div class="note-block">${h(batch.source_note)}</div>` : ''}
        </div>
      `).join('');
    }

    async function fetchStatus() {
      const params = new URLSearchParams({
        api: 'status',
        status: filterStatus.value,
        q: filterQuery.value
      });
      const res = await fetch(`NewHardwareAdmin.php?${params.toString()}`, {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.detail || data.message || 'Could not refresh status.');
      }
      renderCounts(data.counts || {});
      renderRows(data.rows || []);
      renderBatches(data.batches || []);
    }

    async function releaseUnit(unitId) {
      if (!window.confirm('Release this in-progress machine back to available?')) {
        return;
      }

      const res = await fetch('NewHardwareAdmin.php?api=release', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
          unit_id: unitId,
          csrf_token: csrfToken
        })
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        window.alert(data.message || 'Could not release that machine.');
        return;
      }
      await fetchStatus();
    }

    let refreshTimer = null;
    function scheduleRefresh() {
      if (refreshTimer) {
        clearInterval(refreshTimer);
      }
      refreshTimer = setInterval(() => {
        fetchStatus().catch(() => {});
      }, 12000);
    }

    refreshButton.addEventListener('click', () => {
      fetchStatus().catch((err) => window.alert(err.message));
    });
    filterStatus.addEventListener('change', () => {
      fetchStatus().catch(() => {});
    });
    filterQuery.addEventListener('input', () => {
      window.clearTimeout(filterQuery._timer);
      filterQuery._timer = window.setTimeout(() => {
        fetchStatus().catch(() => {});
      }, 250);
    });

    scheduleRefresh();
  </script>
</body>
</html>
