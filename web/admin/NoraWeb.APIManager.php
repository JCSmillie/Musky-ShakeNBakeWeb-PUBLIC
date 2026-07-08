<?php
// ============================================================================
// NORA API MANAGER
// ----------------------------------------------------------------------------
// Web-based Admin UI for managing API clients and viewing usage stats
// Theme + style modeled after NoraWeb.ErrandsList.php
// ============================================================================
// if (!isset($_GET['api'])) {
//    include_once __DIR__ . '/../../Functions/Utility/MuskyTestHarness.PRIVATE.php';
// }

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';

$tool_required = 'ADMIN_PANEL';
$allowed = explode(',', $_SESSION['musky_user']['allowed_tools'] ?? []);
if (!in_array($tool_required, $allowed) && !in_array('ALL_TOOLS', $allowed)) {
    http_response_code(403);
    echo "⛔ Access Denied — Missing Required Tool: {$tool_required}";
    exit;
}

// Load theme from the shared user-preference layer so this page follows
// the same future migration path as the rest of Musky.
$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';

// Define API base path
$apiBase = '/api/NoraAPI.php?path=';

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>🧭 NORA API Manager</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
body { background-color: var(--background-color); color: var(--text-color); }
#clientTable th { position: sticky; top: 0; background: var(--table-header-bg); z-index: 3; }
.table-wrapper { max-height: 65vh; overflow-y: auto; }
.api-log { font-size: 0.8em; color: var(--muted-text); }
.token { font-family: monospace; font-size: 0.85em; background: #222; color: #f8f8f2; padding: 3px 6px; border-radius: 5px; }
.btn-orange { background-color: #ff8c00; color: white; border: none; }
.btn-orange:hover { background-color: #e57a00; }
</style>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
<div class="container-fluid mt-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <button class="btn btn-secondary" onclick="window.location='../index.php'">⬅ Back</button>
      <button class="btn btn-primary" id="refreshBtn">🔄 Refresh</button>
      <button class="btn btn-orange" id="toggleDebug">🐞 Toggle Debug</button>
    </div>
    <h3>🧭 NORA API Manager</h3>
    <div id="lastRefresh" class="text-muted small"></div>
  </div>

  <div id="debug" style="display:none;">
    <pre id="debugOutput" class="p-2 bg-dark text-light" style="max-height:300px;overflow:auto;"></pre>
  </div>

  <div class="table-wrapper">
    <table class="table table-striped table-hover align-middle" id="clientTable">
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>Status</th>
          <th>Token</th>
          <th>Allowed IPs</th>
          <th>Usage</th>
          <th>Last Used</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="clientRows"><tr><td colspan="9">Loading...</td></tr></tbody>
    </table>
  </div>

  <hr>
  <h5>📈 Recent API Activity</h5>
  <div class="table-wrapper">
    <table class="table table-sm table-striped table-hover">
      <thead>
        <tr>
          <th>Client</th>
          <th>Endpoint</th>
          <th>Status</th>
          <th>IP</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody id="logRows"><tr><td colspan="5">Loading...</td></tr></tbody>
    </table>
  </div>
</div>

<script>
let debugEnabled = false;
function logDebug(msg) {
  if (debugEnabled) {
    $('#debug').show();
    $('#debugOutput').append(msg + "\n");
  }
}

function loadClients() {
  $.getJSON("<?= $apiBase ?>"+"/clients", function(data) {
    $('#clientRows').empty();
    data.clients.forEach(c => {
      let row = `<tr>
        <td>${c.id}</td>
        <td>${c.client_name}</td>
        <td>${c.status}</td>
        <td><span class="token">${c.token}</span></td>
        <td>${c.allowed_ips || '-'}</td>
        <td>${c.usage_count}</td>
        <td>${c.last_used || '-'}</td>
        <td>${c.created_at || '-'}</td>
        <td>
          <button class="btn btn-sm btn-warning" onclick="toggleStatus(${c.id},'${c.status}')">Toggle</button>
          <button class="btn btn-sm btn-info" onclick="regenToken(${c.id})">Regen</button>
        </td>
      </tr>`;
      $('#clientRows').append(row);
    });
    logDebug(JSON.stringify(data, null, 2));
  }).fail(function(xhr){
    $('#clientRows').html('<tr><td colspan="9" class="text-danger">Error loading clients</td></tr>');
    logDebug(xhr.responseText);
  });
}

function loadLogs() {
  $.getJSON("<?= $apiBase ?>/logs&limit=10", function(data){
    $('#logRows').empty();
    data.results.forEach(l=>{
      let row = `<tr>
        <td>${l.client_name || '-'}</td>
        <td>${l.endpoint}</td>
        <td>${l.status_code}</td>
        <td>${l.ip_address}</td>
        <td>${l.created_at}</td>
      </tr>`;
      $('#logRows').append(row);
    });
    logDebug(JSON.stringify(data,null,2));
  }).fail(xhr=>{
    $('#logRows').html('<tr><td colspan="5" class="text-danger">Error loading logs</td></tr>');
    logDebug(xhr.responseText);
  });
}

function toggleStatus(id,status){
  const newStatus = (status === 'enabled') ? 'disabled' : 'enabled';
  $.ajax({
    url: "<?= $apiBase ?>"+"/client/update",
    method: "PATCH",
    data: JSON.stringify({ client_id: id, status: newStatus }),
    contentType: "application/json",
    success: function(){ loadClients(); },
    error: function(xhr){ alert("Failed: " + xhr.responseText); }
  });
}

function regenToken(id){
  $.post("<?= $apiBase ?>"+"/client/regenerate", JSON.stringify({ client_id: id }), function(){
    loadClients();
  }).fail(xhr=>alert("Failed: " + xhr.responseText));
}

function updateRefreshTime(){
  const t = new Date().toLocaleTimeString();
  $('#lastRefresh').text("Last refresh: " + t);
}

$('#refreshBtn').click(()=>{ loadClients(); loadLogs(); updateRefreshTime(); });
$('#toggleDebug').click(()=>{ debugEnabled = !debugEnabled; $('#debug').toggle(debugEnabled); });
setInterval(()=>{ loadClients(); loadLogs(); updateRefreshTime(); }, 60000);

$(document).ready(function(){
  loadClients();
  loadLogs();
  updateRefreshTime();
});
</script>
</body>
</html>
