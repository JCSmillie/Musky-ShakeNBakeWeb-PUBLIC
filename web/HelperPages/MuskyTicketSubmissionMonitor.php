<?php
// ======================================================================
// MUSKY - MuskyTicketSubmissionMonitor.php (Baseline v1.0)
// ----------------------------------------------------------------------
// PURPOSE
//   • If ids are present via ?id= or ?ids=, immediately monitor them.
//   • Else, pull payloads from localStorage (browser), post to
//     MuskyTicketSubmissionHandler.php to create errands, get IDs,
//     then monitor live.
//   • Show Status + ExtraDataField04 (messages) + link to ExtraDataField06 JSON.
//
// API MODE
//   GET ?api=status&ids=1,2,3
//     → returns { items:[ {ErrandID,Status,ExtraDataField04,ExtraDataField06}, ... ] }
//
// DEPENDENCIES
//   • ../bootstrap.php
//   • ../../Functions/nora_connect.php
//   • MuskyTicketSubmissionHandler.php (same folder) for creation step
// ----------------------------------------------------------------------

date_default_timezone_set('America/New_York');

$rootDir = __DIR__ . '/..';
require_once $rootDir . '/bootstrap.php';
// -----------------------------------------------------------------------------
// SECURITY BASELINE (MANDATORY):
//   This monitor can both render HTML and serve API polling responses.
//   We always include the shared access middleware so every request path
//   (including `?api=status`) runs with authenticated session context.
// -----------------------------------------------------------------------------
require_once $rootDir . '/check_access.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

// ----------------------------------------------------------------------
// API: status poller
// ----------------------------------------------------------------------
if (isset($_GET['api']) && $_GET['api'] === 'status') {
    header('Content-Type: application/json');

    $idsParam = $_GET['ids'] ?? '';
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsParam)), fn($v)=>$v>0));
    if (!$ids) {
        echo json_encode(['items' => []], JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $pdo = nora_connect();
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT ErrandID, Status, ExtraDataField04, ExtraDataField06 
                  FROM nora_errands
                 WHERE ErrandID IN ($in)
              ORDER BY FIELD(ErrandID, $in)";
        // Bind twice (for FIELD order)
        $args = array_merge($ids, $ids);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['items' => $rows], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Exception $e) {
        error_log('[MuskyTicketSubmissionMonitor] status poll failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not read errand status right now.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ----------------------------------------------------------------------
// Normal page (HTML + JS)
// ----------------------------------------------------------------------
$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$submissionCsrfToken = musky_csrf_token('musky_ticket_submission');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>🎫 Ticket Submission Monitor</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<style>
  body { font-family: system-ui, sans-serif; background: #f7f7f9; margin: 0; }
  .wrap { max-width: 980px; margin: 20px auto; padding: 16px; }
  .card { background: #fff; border-radius: 14px; box-shadow: 0 6px 18px rgba(0,0,0,0.12); padding: 18px 20px; }
  h1 { margin: 0 0 10px; }
  .controls { display:flex; align-items:center; gap:16px; margin: 6px 0 16px; }
  .ids { color: #666; font-size: 0.95em; }
  .row { display:grid; grid-template-columns: 90px 120px 1fr; gap:10px; border-top:1px solid #eee; padding:10px 0; }
  .row:first-child { border-top: none; }
  .status { font-weight: 700; }
  .status.Processing { color: #B26A00; }
  .status.Complete { color: #1B7E28; }
  .status.Failed, .status.Rejected { color: #B00020; }
  .status.Queued, .status.submitted { color: #005FA3; }
  .msg { white-space: pre-wrap; color:#333; }
  .small { font-size: 0.88em; color:#666; }
  .pill { padding: 2px 8px; border-radius: 999px; background: #eef0f3; display:inline-block; font-size: 0.85em; }
  .muted { color:#999; }
  .success { color:#157a27; }
</style>
<script>
function qs(k, dflt='') {
  const u = new URL(location.href);
  return u.searchParams.get(k) ?? dflt;
}
function setIdsInUrl(ids) {
  const u = new URL(location.href);
  u.searchParams.delete('id');
  u.searchParams.delete('ids');
  if (ids.length === 1) u.searchParams.set('id', ids[0]);
  if (ids.length > 1) u.searchParams.set('ids', ids.join(','));
  history.replaceState({}, '', u.toString());
}
</script>
</head>
<body class="<?= htmlspecialchars($theme) ?>">
<div class="wrap">
  <div class="card">
    <h1>🎫 Ticket Submission Monitor</h1>
    <div class="controls">
      <label><input type="checkbox" id="closeOnComplete" checked> Close when all complete</label>
      <span class="ids" id="idsLine"></span>
    </div>
    <div id="statusArea" class="small muted">Initializing…</div>
    <div id="rows"></div>
  </div>
</div>

<script>
const TICKET_SUBMISSION_CSRF = <?= json_encode($submissionCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

(async () => {
  // Let the opener know we're alive (so MakeATicket page can close itself)
  try { window.opener?.postMessage('MUSKY_MONITOR_READY', '*'); } catch(_) {}

  const rowsEl = document.getElementById('rows');
  const statusEl = document.getElementById('statusArea');
  const idsLine = document.getElementById('idsLine');
  const closeOnComplete = document.getElementById('closeOnComplete');

  // Helper to render one row
  function renderRow(item) {
    const wrap = document.createElement('div');
    wrap.className = 'row';
    const id = document.createElement('div');
    id.textContent = `#${item.ErrandID}`;
    const status = document.createElement('div');
    status.className = 'status ' + (item.Status || '');
    status.textContent = item.Status || 'unknown';

    const right = document.createElement('div');
    const msg = document.createElement('div');
    msg.className = 'msg';
    msg.textContent = item.ExtraDataField04 ? item.ExtraDataField04 :
                      '(no user message yet)';

    const tail = document.createElement('div');
    tail.className = 'small';
    if (item.ExtraDataField06) {
      const btn = document.createElement('a');
      btn.href = '#';
      btn.textContent = 'view result JSON';
      btn.onclick = (e) => {
        e.preventDefault();
        try {
          const pretty = JSON.stringify(JSON.parse(item.ExtraDataField06), null, 2);
          alert(pretty);
        } catch {
          alert(item.ExtraDataField06);
        }
      };
      tail.appendChild(btn);
    } else {
      tail.textContent = 'no result JSON';
    }

    right.appendChild(msg);
    right.appendChild(tail);

    wrap.appendChild(id);
    wrap.appendChild(status);
    wrap.appendChild(right);
    return wrap;
  }

  // State
  let ids = [];
  const idParam  = qs('id', '');
  const idsParam = qs('ids', '');

  if (idParam || idsParam) {
    // IDs provided via URL → monitor immediately
    if (idParam) ids = [parseInt(idParam, 10)].filter(n=>n>0);
    if (idsParam) ids = idsParam.split(',').map(s=>parseInt(s,10)).filter(n=>n>0);
    setIdsInUrl(ids); // normalize
    idsLine.textContent = `Monitoring: ${ids.join(', ')}`;
    statusEl.textContent = 'Live monitoring enabled…';
  } else {
    // No IDs provided → pull payloads from localStorage, call handler
    const raw = localStorage.getItem('musky_ticket_payload');
    if (!raw) {
      statusEl.innerHTML = '<span class="muted">No valid id or ids provided.</span><br>Example: ?id=130 or ?ids=130,131';
      return;
    }
    let payloads;
    try {
      payloads = JSON.parse(raw);
      if (!Array.isArray(payloads) || payloads.length === 0) throw 0;
    } catch {
      statusEl.textContent = 'Invalid local payload; cannot proceed.';
      return;
    }

    statusEl.textContent = 'Submitting payload to handler…';
    const res = await fetch('MuskyTicketSubmissionHandler.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type':'application/json',
        'X-CSRF-Token': TICKET_SUBMISSION_CSRF
      },
      body: JSON.stringify({ payloads })
    });
    const out = await res.json().catch(()=> ({}));
    if (!out || !Array.isArray(out.ids) || out.ids.length === 0) {
      statusEl.textContent = 'Submission failed; no Errand IDs returned.';
      return;
    }
    ids = out.ids.map(n=>parseInt(n,10)).filter(n=>n>0);
    // Normalize URL for refreshability, and show IDs
    setIdsInUrl(ids);
    idsLine.textContent = `Created and monitoring: ${ids.join(', ')}`;
    statusEl.textContent = 'Live monitoring enabled…';
  }

  // Render initial skeleton rows
  rowsEl.innerHTML = '';
  ids.forEach(id => {
    const row = document.createElement('div');
    row.id = 'row-' + id;
    row.className = 'row';
    row.innerHTML = `
      <div>#${id}</div>
      <div class="status">-</div>
      <div class="msg small muted">waiting…</div>
    `;
    rowsEl.appendChild(row);
  });

  // Poll loop
  let allDoneOnce = false;
  async function poll() {
    try {
      const url = new URL(location.href);
      url.searchParams.set('api', 'status');
      url.searchParams.set('ids', ids.join(','));
      const res = await fetch(url.toString(), { cache: 'no-cache' });
      const data = await res.json();

      let doneCount = 0;
      for (const item of (data.items || [])) {
        const row = document.getElementById('row-' + item.ErrandID);
        if (!row) continue;
        const statusDiv = row.children[1];
        const msgDiv = row.children[2];

        statusDiv.className = 'status ' + (item.Status || '');
        statusDiv.textContent = item.Status || 'unknown';
        msgDiv.className = 'msg';
        msgDiv.textContent = item.ExtraDataField04 ? item.ExtraDataField04 : '(no user message yet)';

        if (item.Status === 'Complete' || item.Status === 'Failed' || item.Status === 'Rejected' || item.Status === 'Cancelled') {
          doneCount++;
        }
      }

      if (doneCount === ids.length && !allDoneOnce) {
        allDoneOnce = true;
        statusEl.innerHTML = '<span class="success">All tasks finished.</span>';
        if (closeOnComplete.checked) {
          setTimeout(()=>window.close(), 800);
        }
      } else if (doneCount < ids.length) {
        statusEl.textContent = `Monitoring… ${doneCount}/${ids.length} finished`;
      }
    } catch (e) {
      statusEl.textContent = 'Polling error: ' + (e?.message || e);
    } finally {
      // Keep it live
      setTimeout(poll, 3000);
    }
  }

  poll();
})();
</script>
</body>
</html>
