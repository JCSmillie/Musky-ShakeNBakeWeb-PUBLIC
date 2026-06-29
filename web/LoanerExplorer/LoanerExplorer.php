<?php
// ============================================================================
// MUSKY — LoanerExplorer.php (Loaner-focused Explorer)
// ----------------------------------------------------------------------------
// Purpose:
//   Explore IIQ loaner pools backed by NORA. UI intentionally mirrors the
//   ClassExplorer layout but filters by loaner pools instead of classes.
//
// Data Source:
//   - NORA tables:
//       • iiq_loaners
//       • devices
//       • owners
//       • device_history
//   - Helper API:
//       • LoanerExplorer/fetch_loaner_pools.php
//       • LoanerExplorer/fetch_loaner_devices.php
//       • HelperPages/fetch_device_health.php (AJAX-only)
//       • LoanerExplorer/Loaner_INVLookup.php        (Nora INV_LOOKUP submit)
//       • LoanerExplorer/Loaner_INVLookupStatus.php  (Nora INV_LOOKUP status)
//
// Permissions (any of):
//   - LOANER_MGMT
//   - LOANER_EXPLORER
//   - CLASS_MANAGER
//   - ADMIN_PANEL
//   - HDK-SUPERVISOR-LII
//   - HDK-SUPERVISOR-ADMIN
//   - ALL_TOOLS
// ============================================================================

date_default_timezone_set('America/New_York');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../_tool_guard.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyUserMariaSync.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../../Functions/nora_connect.php';

// -----------------------------------------------------------------------------
// Load unified Musky preferences (canonical source)
// -----------------------------------------------------------------------------
$prefs = musky_get_logged_in_user_prefs();

$email       = $prefs['email']        ?? ($_SESSION['musky_user']['email'] ?? '');
$firstName   = $prefs['first_name']   ?? '';
$name        = $prefs['display_name'] ?? '';
$pic         = $prefs['photo_url']    ?? '';
$theme       = $prefs['theme']        ?? 'light-mode';
$allowedPref = $prefs['allowed_tools'] ?? [];
$defaultLoanerPool = trim((string)($prefs['default_loaner_pool'] ?? ''));

// Normalize allowed tools from prefs + session, then merge
$allowed_from_session = [];
if (!empty($_SESSION['musky_user']['allowed_tools'])) {
    if (is_string($_SESSION['musky_user']['allowed_tools'])) {
        $allowed_from_session = array_filter(
            array_map('trim', explode(',', $_SESSION['musky_user']['allowed_tools']))
        );
    } elseif (is_array($_SESSION['musky_user']['allowed_tools'])) {
        $allowed_from_session = $_SESSION['musky_user']['allowed_tools'];
    }
}

$allowed = [];
if (is_array($allowedPref)) {
    $allowed = $allowedPref;
} elseif (is_string($allowedPref)) {
    $allowed = array_filter(array_map('trim', explode(',', $allowedPref)));
}
$allowed = array_values(array_unique(array_merge($allowed, $allowed_from_session)));

// -----------------------------------------------------------------------------
// Access gate
// -----------------------------------------------------------------------------
$allowed = musky_require_general_admin_access(
    $allowed,
    ['LOANER_MGMT', 'LOANER_EXPLORER', 'CLASS_MANAGER'],
    [
        'response' => 'html',
        'status' => 403,
        'message' => '⛔ Access Denied — Missing Required Tool Variant.',
    ]
);

$is_admin = in_array('ADMIN_PANEL', $allowed, true) || in_array('ALL_TOOLS', $allowed, true);
$loanerCsrfToken = musky_csrf_token('loaner_explorer_default_pool');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_default_pool') {
    musky_csrf_require('loaner_explorer_default_pool');

    $requestedPool = trim((string)($_POST['default_pool'] ?? ''));
    $saved = musky_user_set_default_loaner_pool($email, $requestedPool !== '' ? $requestedPool : null);

    $_SESSION['loaner_explorer_flash'] = [
        'ok' => $saved,
        'message' => $saved
            ? ($requestedPool !== '' ? "Default loaner pool saved: {$requestedPool}" : 'Default loaner pool cleared.')
            : 'Could not save the default loaner pool.',
    ];

    header('Location: LoanerExplorer.php');
    exit;
}

$loanerFlash = $_SESSION['loaner_explorer_flash'] ?? null;
unset($_SESSION['loaner_explorer_flash']);

function musky_active_config_value(?PDO $pdo, string $group, string $key): ?string {
    static $cache = [];

    if (!$pdo instanceof PDO) {
        return null;
    }

    $cacheKey = strtoupper($group) . '::' . strtoupper($key);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT ConfigValue
              FROM nora_config_store
             WHERE ConfigGroup = ?
               AND ConfigKey = ?
               AND IsActive = 1
             ORDER BY UpdatedAt DESC, ConfigID DESC
             LIMIT 1
        ");
        $stmt->execute([strtoupper($group), strtoupper($key)]);
        $value = $stmt->fetchColumn();
        $cache[$cacheKey] = ($value !== false && $value !== null) ? trim((string)$value) : null;
    } catch (Throwable $e) {
        $cache[$cacheKey] = null;
    }

    return $cache[$cacheKey];
}

$iiqBaseUrlWeb = null;
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $iiqBaseUrlWeb = musky_active_config_value($pdo, 'IIQ', 'BASE_URL_WEB');
        if (!$iiqBaseUrlWeb) {
            $iiqApiBase = musky_active_config_value($pdo, 'IIQ', 'BASE_URL');
            if ($iiqApiBase) {
                $iiqBaseUrlWeb = preg_replace('#/api(?:/v[0-9.]+)?/?$#i', '', rtrim($iiqApiBase, '/'));
            }
        }
    }
} catch (Throwable $e) {
    $iiqBaseUrlWeb = null;
}

$iiqTicketBaseUrl = $iiqBaseUrlWeb ? rtrim($iiqBaseUrlWeb, '/') . '/agent/tickets/' : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Musky — Loaner Pool Explorer</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<style>
body{
    margin:0;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
.header{
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 16px;
    border-bottom:1px solid rgba(0,0,0,0.08);
}
.header h1{
    margin:0;
    font-size:1.25rem;
    font-weight:800;
}
.header .spacer{flex:1;}
.back-btn{
    text-decoration:none;
    font-weight:700;
}
.section{padding:12px 16px;}
.panel{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
    background-color:rgba(0,0,0,0.03);
    padding:10px;
    border-radius:10px;
    margin-bottom:12px;
}

.mainwrap{
    display:flex;
    gap:16px;
    align-items:flex-start;
    padding:0 16px 16px 16px;
}
.main{flex:1;min-width:0;position:relative;}
.sidebar{
    width:180px;
    display:flex;
    flex-direction:column;
    gap:8px;
    align-items:stretch;
    position:sticky;
    top:12px;
}

.card{
    background:#fff;
    border-radius:12px;
    box-shadow:0 2px 7px rgba(0,0,0,0.06);
    padding:12px 14px;
    margin-bottom:12px;
}
.card h2{
    margin:0 0 6px 0;
    font-size:1.05rem;
}
.card p{
    margin:0;
    font-size:0.9rem;
    color:#555;
}

label{font-size:0.9rem;}
select,button{font-size:0.9rem;}
select{
    padding:4px 6px;
    border-radius:6px;
    border:1px solid rgba(0,0,0,0.3);
}
.btn-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:8px 10px;
    border:none;
    border-radius:12px;
    font-size:0.9rem;
    font-weight:700;
    cursor:pointer;
    box-shadow:0 2px 6px rgba(0,0,0,0.1);
    background:linear-gradient(180deg,#2c6ef2 0%,#1e4ec9 100%);
    color:#fff;
}
.btn-pill.gray{
    background:linear-gradient(180deg,#607d8b 0%,#455a64 100%);
}
.btn-pill.orange{
    background:linear-gradient(180deg,#ff9800 0%,#f57c00 100%);
}
.btn-pill:hover{filter:brightness(1.03);}

.btn-mini{
    border:none;
    border-radius:10px;
    padding:3px 7px;
    font-size:0.78rem;
    margin-right:4px;
    cursor:pointer;
    background:#eee;
}
.btn-mini:hover{background:#ddd;}
.ticket-link-btn{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:5px 9px;
    border-radius:999px;
    text-decoration:none;
    font-size:0.76rem;
    font-weight:800;
    letter-spacing:0.01em;
    color:#fff;
    background:linear-gradient(180deg,#00897b 0%,#00695c 100%);
    box-shadow:0 2px 7px rgba(0,105,92,0.24);
    transition:transform 0.12s ease, filter 0.12s ease, box-shadow 0.12s ease;
}
.ticket-link-btn:hover{
    filter:brightness(1.04);
    transform:translateY(-1px);
    box-shadow:0 4px 10px rgba(0,105,92,0.28);
}
.ticket-link-btn:active{
    transform:translateY(0);
}
.ticket-link-inline{
    margin-left:6px;
}
.health-links{
    margin-top:6px;
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    align-items:center;
}
.health-text-link{
    font-size:0.85em;
    text-decoration:none;
    font-weight:700;
}

.results-card{
    background:#fff;
    border-radius:12px;
    box-shadow:0 2px 7px rgba(0,0,0,0.06);
    padding:0;
    margin-top:8px;
    position:relative;
}
.results-header{
    padding:10px 12px;
    border-bottom:1px solid rgba(0,0,0,0.06);
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.results-header .subtitle{
    font-size:0.85rem;
    color:#555;
}
.results-body-wrap{
    max-height:70vh;
    overflow:auto;
}
.results-table{
    width:100%;
    border-collapse:collapse;
    font-size:0.83rem;
}
.results-table th,
.results-table td{
    padding:6px 7px;
    border-bottom:1px solid rgba(0,0,0,0.04);
    text-align:left;
    vertical-align:middle;
}
.results-table th{
    background:rgba(0,0,0,0.02);
    position:sticky;
    top:0;
    z-index:1;
}
tr:nth-child(even){background:rgba(0,0,0,0.01);}

tr.row-good{
    background:rgba(200,230,201,0.4);
}
tr.row-needs-assign{
    background:rgba(255,249,196,0.7);
}
tr.row-bad{
    background:rgba(255,205,210,0.7);
}

.toggle-wrap{
    display:flex;
    align-items:center;
    gap:4px;
    margin-right:12px;
}
.toggle-wrap input{margin:0;}

.legend-box{
    font-size:0.78rem;
    border-radius:10px;
    background:rgba(0,0,0,0.02);
    padding:8px;
    margin-top:4px;
}
.legend-box div{margin-bottom:2px;}

.mascot-wrap{text-align:center;margin-top:12px;}

.unctrl-symbol{
    font-size:1.1rem;
    text-align:center;
}

.device-serial-wrap{
    display:flex;
    align-items:center;
    gap:8px;
}

.device-model-icon{
    width:28px;
    height:28px;
    object-fit:contain;
    border-radius:6px;
    box-shadow:0 2px 5px rgba(0,0,0,0.15);
    background:#fff;
}

.asset-tag-wrap{
    display:flex;
    align-items:center;
    gap:8px;
}

.loading-overlay{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,0.84);
    backdrop-filter:blur(2px);
    border-radius:12px;
    z-index:4;
}

.loading-overlay.hidden{
    display:none;
}

.loading-card{
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:10px;
    padding:22px 24px 20px 24px;
    border-radius:18px;
    background:linear-gradient(180deg,rgba(255,255,255,0.98) 0%,rgba(244,248,255,0.96) 100%);
    box-shadow:0 16px 34px rgba(0,0,0,0.16);
    min-width:280px;
    max-width:360px;
    border:1px solid rgba(44,110,242,0.12);
}

.loading-spinner{
    width:44px;
    height:44px;
    border-radius:50%;
    border:4px solid rgba(44,110,242,0.18);
    border-top-color:#2c6ef2;
    animation:spin 0.9s linear infinite;
}

.loading-text{
    font-size:0.92rem;
    font-weight:700;
    text-align:center;
}

.loading-subtext{
    font-size:0.8rem;
    color:#666;
    text-align:center;
    line-height:1.35;
    min-height:2.2em;
}

.loading-hint{
    font-size:0.78rem;
    color:#446;
    text-align:center;
    line-height:1.35;
    min-height:2.2em;
}

.loading-progress{
    width:100%;
    height:8px;
    border-radius:999px;
    overflow:hidden;
    background:rgba(44,110,242,0.12);
    box-shadow:inset 0 1px 2px rgba(0,0,0,0.08);
}

.loading-progress-bar{
    width:42%;
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#2c6ef2 0%,#6fb3ff 45%,#2c6ef2 100%);
    animation:loadingBar 1.5s ease-in-out infinite;
}

.loading-orbits{
    position:relative;
    width:64px;
    height:64px;
    display:flex;
    align-items:center;
    justify-content:center;
}

.loading-orbit-dot{
    position:absolute;
    width:10px;
    height:10px;
    border-radius:50%;
    background:#2c6ef2;
    box-shadow:0 0 0 4px rgba(44,110,242,0.10);
}

.loading-orbit-dot.one{
    animation:orbitOne 1.8s linear infinite;
}

.loading-orbit-dot.two{
    animation:orbitTwo 1.8s linear infinite;
}

@keyframes spin{
    to{transform:rotate(360deg);}
}

@keyframes loadingBar{
    0%{transform:translateX(-85%);}
    50%{transform:translateX(125%);}
    100%{transform:translateX(-85%);}
}

@keyframes orbitOne{
    0%{transform:translate(0,-24px);}
    25%{transform:translate(24px,0);}
    50%{transform:translate(0,24px);}
    75%{transform:translate(-24px,0);}
    100%{transform:translate(0,-24px);}
}

@keyframes orbitTwo{
    0%{transform:translate(0,24px);}
    25%{transform:translate(-24px,0);}
    50%{transform:translate(0,-24px);}
    75%{transform:translate(24px,0);}
    100%{transform:translate(0,24px);}
}

body.hide-extra .col-lastseen,
body.hide-extra .col-tags,
body.hide-extra .col-extra { display:none; }
body.hide-extra th.col-lastseen,
body.hide-extra th.col-tags,
body.hide-extra th.col-extra { display:none; }

/* Pool selector row */
.pool-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.pool-row select{min-width:260px;}

/* Inventory status bar */
.inv-status{
    margin-top:4px;
    font-size:0.8rem;
    color:#555;
}

.loaner-warning{
    margin-top:12px;
    padding:12px 14px;
    border-radius:12px;
    border:2px solid #b71c1c;
    background:linear-gradient(180deg, rgba(183,28,28,0.14) 0%, rgba(255,235,238,0.88) 100%);
    color:#7f1111;
    font-weight:800;
    letter-spacing:0.02em;
    text-transform:uppercase;
    box-shadow:0 8px 18px rgba(183,28,28,0.12);
}

.default-pool-tools{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:8px;
    margin-top:10px;
}

.default-pool-status{
    font-size:0.82rem;
    color:#555;
}

.flash-note{
    margin-top:10px;
    padding:10px 12px;
    border-radius:10px;
    font-size:0.9rem;
    font-weight:700;
}

.flash-note.ok{
    background:rgba(46,125,50,0.12);
    color:#1f5f29;
    border:1px solid rgba(46,125,50,0.28);
}

.flash-note.error{
    background:rgba(198,40,40,0.10);
    color:#8e1c1c;
    border:1px solid rgba(198,40,40,0.24);
}

/* Fade animation placeholder */
.fade-out{opacity:0.6;transition:opacity 0.2s ease-out;}

</style>
<script src="../DeviceModelIcons.js"></script>
</head>
<body class="<?php echo htmlspecialchars($theme, ENT_QUOTES); ?>">

<div class="header">
  <a class="back-btn" href="../index.php">← Main</a>
  <h1>Loaner Pool Explorer</h1>
  <div class="spacer"></div>
  <div style="font-size:0.85rem;color:#555;">
    Logged in as: <?php echo htmlspecialchars($email ?: 'unknown', ENT_QUOTES); ?>
  </div>
</div>

<div class="section">
  <div class="panel">
    <div class="pool-row">
      <label for="loanerPool"><strong>Loaner Pool:</strong></label>
      <select id="loanerPool">
        <option value="">(Loading pools...)</option>
      </select>
      <button id="loadPoolBtn" class="btn-pill">Load Pool</button>
    </div>
	    <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap;">
	      <label class="toggle-wrap">
	        <input type="checkbox" id="hideUnctrlCB" checked>
        <span>Hide uncontrollable / never seen?</span>
      </label>
      <label class="toggle-wrap">
        <input type="checkbox" id="hideExtraCB" checked>
        <span>Hide extra columns?</span>
      </label>
	    </div>
	    <div id="invStatus" class="inv-status"></div>
	    <form method="post" class="default-pool-tools" id="defaultPoolForm">
	      <input type="hidden" name="action" value="save_default_pool">
	      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($loanerCsrfToken, ENT_QUOTES) ?>">
	      <input type="hidden" name="default_pool" id="defaultPoolInput" value="">
	      <button type="submit" id="saveDefaultPoolBtn" class="btn-pill gray">⭐ Set Selected Pool as Default</button>
	      <span class="default-pool-status" id="defaultPoolStatus">
	        Default pool:
	        <strong><?= htmlspecialchars($defaultLoanerPool !== '' ? $defaultLoanerPool : 'None saved', ENT_QUOTES) ?></strong>
	      </span>
	    </form>
	    <?php if (is_array($loanerFlash) && !empty($loanerFlash['message'])): ?>
	    <div class="flash-note <?= !empty($loanerFlash['ok']) ? 'ok' : 'error' ?>">
	      <?= htmlspecialchars((string)$loanerFlash['message'], ENT_QUOTES) ?>
	    </div>
	    <?php endif; ?>
	    <div class="loaner-warning">
	      LOANERS CAN ONLY BE ISSUED AND RETURNED THROUGH INCIDENT IQ!!!!
	    </div>
	  </div>
	</div>

<div class="mainwrap">
  <main class="main">
    <div class="results-card" id="mainContent">
      <div class="results-header">
        <div>
          <div id="poolTitle" style="font-weight:bold;">No pool selected</div>
          <div id="poolSub" class="subtitle">Pick a pool above to load loaner devices.</div>
        </div>
        <div style="font-size:0.8rem;color:#555;">
          <span id="summaryBadge"></span>
        </div>
      </div>
      <div class="results-body-wrap">
        <table class="results-table">
          <thead>
            <tr>
              <th></th>
              <th class="col-extra">Serial</th>
              <th>Asset Tag</th>
              <th>Mosyle User</th>
              <th>IIQ Loan User</th>
              <th>Loaned?</th>
              <th>Pool</th>
              <th class="col-extra">Ticket</th>
              <th class="col-extra">Issue Date</th>
              <th>Days Assigned</th>
              <th class="col-extra">OS / Status</th>
              <th class="col-lastseen">Last Check-In</th>
              <th class="col-tags">Tags</th>
              <th class="col-extra">JSON</th>
              <th>Health / Links</th>
              <th class="col-extra">Date Info</th>
              <th class="col-extra">OS Version</th>
              <th class="col-extra">Battery</th>
              <th class="col-extra">Total Disk</th>
              <th class="col-extra">% Disk</th>
              <th class="col-extra">Avail Disk</th>
              <th class="col-extra">Avail OS Updates</th>
            </tr>
          </thead>
          <tbody id="resultsBody"></tbody>
        </table>
      </div>
      <div id="loadingOverlay" class="loading-overlay hidden" aria-live="polite" aria-busy="false">
        <div class="loading-card">
          <div class="loading-orbits" aria-hidden="true">
            <div class="loading-spinner"></div>
            <div class="loading-orbit-dot one"></div>
            <div class="loading-orbit-dot two"></div>
          </div>
          <div id="loadingText" class="loading-text">Loading loaner data...</div>
          <div id="loadingSubtext" class="loading-subtext">Please wait while Musky gathers the latest device data.</div>
          <div id="loadingHint" class="loading-hint">Large groups may take a little longer while Nora refreshes inventory and health details.</div>
          <div class="loading-progress" aria-hidden="true">
            <div class="loading-progress-bar"></div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <aside class="sidebar">
    <button id="reportBtn" class="btn-pill orange">📢 Report Problem</button>
    <button id="deviceReportBtn" class="btn-pill gray">📋 Device Report</button>
    <button id="reloadBtn" class="btn-pill">🔁 Look Up Again</button>

    <div class="legend-box">
      <div>💾⚠️ Disk nearly full</div>
      <div>🔋⚠️ Low battery</div>
      <div>🔇 Muted</div>
      <div>🚨❗ Lost Mode</div>
      <div>🟠⬆️ OS update available</div>
      <div>🏫 On campus today</div>
    </div>

    <?php if ($is_admin): ?>
    <div class="legend-box" style="margin-top:12px;">
      <strong>⚡ Power Tools</strong><br>
      <button id="powerRebootBtn" class="btn-pill orange" style="margin-top:8px;width:100%;">⚡ POWER REBOOT</button>
      <button id="powerWashBtn" class="btn-pill" style="margin-top:8px;width:100%;background:linear-gradient(180deg,#c62828 0%,#8e0000 100%);">
        ☣️ POWER WASH &amp; WAX
      </button>
      <button id="debugBtn" class="btn-pill gray" style="margin-top:8px;width:100%;">🐞 Debug</button>
    </div>
    <?php endif; ?>

    <div class="mascot-wrap">
      <a href="../about.php" target="_blank">
        <img src="../mascot.png" width="100" height="100" alt="Musky Mascot">
      </a>
    </div>
  </aside>
</div>

<script>
// ---------------------------------------------------------------------------
// Endpoints + constants
// ---------------------------------------------------------------------------
const IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
const ALL_POOLS_VALUE = '__ALL_POOLS__';
const DEFAULT_LOANER_POOL = <?php echo json_encode($defaultLoanerPool, JSON_UNESCAPED_SLASHES); ?>;
const IIQ_TICKET_BASE_URL = <?php echo json_encode($iiqTicketBaseUrl, JSON_UNESCAPED_SLASHES); ?>;
const ENDPOINTS = {
  pools   : './fetch_loaner_pools.php',
  devices : './fetch_loaner_devices.php',
  health  : '../HelperPages/fetch_device_health.php',
  activity: '../api/musky_activity.php',
  invStart: './Loaner_INVLookup.php',
  invStat : './Loaner_INVLookupStatus.php'
};

const UNCTRL = new Set(['stolen','broken','instorage','out2agi']);

// State
let currentPoolName = '';
let currentPoolValue = '';
let lastRows        = [];
let lastLoadedAt    = null;
let healthCache     = {};
let isSyncing       = false;
let availablePools  = [];
let currentDefaultPool = DEFAULT_LOANER_POOL || '';

// DOM
const poolSelect    = document.getElementById('loanerPool');
const loadPoolBtn   = document.getElementById('loadPoolBtn');
const saveDefaultPoolBtn = document.getElementById('saveDefaultPoolBtn');
const defaultPoolInput = document.getElementById('defaultPoolInput');
const defaultPoolStatus = document.getElementById('defaultPoolStatus');
const hideUnctrlCB  = document.getElementById('hideUnctrlCB');
const hideExtraCB   = document.getElementById('hideExtraCB');
const resultsBody   = document.getElementById('resultsBody');
const poolTitle     = document.getElementById('poolTitle');
const poolSub       = document.getElementById('poolSub');
const summaryBadge  = document.getElementById('summaryBadge');
const invStatus     = document.getElementById('invStatus');
const mainContent   = document.getElementById('mainContent');
const loadingOverlay = document.getElementById('loadingOverlay');
const loadingText    = document.getElementById('loadingText');
const loadingSubtext = document.getElementById('loadingSubtext');
const loadingHint    = document.getElementById('loadingHint');

const LOADING_HINTS = [
  'Large groups may take a little longer while Nora refreshes inventory and health details.',
  'Musky loads baseline rows first so you see useful data even before deeper sync finishes.',
  'Health checks, inventory refresh, and post-sync reads can take a moment on bigger pools.',
  'You are not hung up. Musky is still working through the selected pool.'
];

let loadingHintTimer = null;
let loadingHintIndex = 0;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
async function fetchJSON(url, options = {}){
  const resp = await fetch(url, { credentials:'same-origin', cache:'no-store', ...options });
  const text = await resp.text();
  try { return JSON.parse(text); }
  catch(e){
    console.error('Bad JSON from', url, text);
    return {};
  }
}

function escapeHtml(text){
  return String(text ?? '').replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]));
}

function currentPoolSelectionValue(){
  const opt = poolSelect?.selectedOptions?.[0];
  return opt && opt.value ? opt.value : '';
}

function currentPoolSelectionLabel(){
  const value = currentPoolSelectionValue();
  if (!value) return '';
  return value === ALL_POOLS_VALUE ? 'ALL POOLS' : value;
}

function refreshDefaultPoolUI(){
  const selectedValue = currentPoolSelectionValue();

  if (defaultPoolInput) {
    defaultPoolInput.value = selectedValue;
  }

  if (saveDefaultPoolBtn) {
    saveDefaultPoolBtn.disabled = !selectedValue;
    saveDefaultPoolBtn.textContent = currentDefaultPool && currentDefaultPool === selectedValue
      ? '⭐ Selected Pool Is Default'
      : '⭐ Set Selected Pool as Default';
  }

  if (defaultPoolStatus) {
    const display = currentDefaultPool
      ? (currentDefaultPool === ALL_POOLS_VALUE ? 'ALL POOLS' : currentDefaultPool)
      : 'None saved';
    defaultPoolStatus.innerHTML = `Default pool: <strong>${escapeHtml(display)}</strong>`;
  }
}

function trackActivity(actionName, extra = {}, targetSerials = []){
  fetch(ENDPOINTS.activity, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      event_type: 'ACTION',
      action_name: actionName,
      page_path: '/LoanerExplorer/LoanerExplorer.php',
      target_serials: targetSerials,
      extra
    })
  }).catch(() => {});
}

function timeAgo(d){
  if(!d) return '';
  const ms = Date.now() - new Date(d).getTime();
  const s  = ms / 1000;
  if (s < 60)    return `${Math.floor(s)}s ago`;
  if (s < 3600)  return `${Math.floor(s/60)}m ago`;
  if (s < 86400) return `${Math.floor(s/3600)}h ago`;
  return `${Math.floor(s/86400)}d ago`;
}

function isUnctrl(tags){
  if (!Array.isArray(tags)) return false;
  return tags.some(t => UNCTRL.has(String(t || '').toLowerCase()));
}

function selectedSerials(){
  return Array.from(document.querySelectorAll('.rowcheck:checked'))
    .map(cb => cb.dataset.serial)
    .filter(Boolean);
}

function setHeader(name){
  currentPoolName = name || currentPoolName || '(unknown pool)';
  poolTitle.textContent = `Pool: ${currentPoolName}`;
  poolSub.textContent   = lastLoadedAt
    ? `Last refreshed ${timeAgo(lastLoadedAt)} (${new Date(lastLoadedAt).toLocaleTimeString()})`
    : 'Pick a pool above to load devices.';
}

function setInvStatus(text, mode){
  if (!invStatus) return;
  invStatus.textContent = text || '';
  if (!text) {
    invStatus.style.color = '#555';
    return;
  }
  if (mode === 'error') {
    invStatus.style.color = '#c62828';
  } else if (mode === 'ok') {
    invStatus.style.color = '#2e7d32';
  } else {
    invStatus.style.color = '#555';
  }
}

function cycleLoadingHint(){
  if (!loadingHint) return;
  loadingHint.textContent = LOADING_HINTS[loadingHintIndex % LOADING_HINTS.length];
  loadingHintIndex++;
}

function setLoadingState(active, text = 'Loading loaner data...', detail = ''){
  if (!loadingOverlay) return;
  loadingOverlay.classList.toggle('hidden', !active);
  loadingOverlay.setAttribute('aria-busy', active ? 'true' : 'false');
  if (loadingText) {
    loadingText.textContent = text || 'Loading loaner data...';
  }
  if (loadingSubtext) {
    loadingSubtext.textContent = detail || 'Please wait while Musky gathers the latest device data.';
  }
  if (active) {
    if (loadingHintTimer === null) {
      cycleLoadingHint();
      loadingHintTimer = window.setInterval(cycleLoadingHint, 2600);
    }
  } else if (loadingHintTimer !== null) {
    window.clearInterval(loadingHintTimer);
    loadingHintTimer = null;
    loadingHintIndex = 0;
    if (loadingHint) {
      loadingHint.textContent = LOADING_HINTS[0];
    }
  }
  if (loadPoolBtn) loadPoolBtn.disabled = !!active;
  if (poolSelect)  poolSelect.disabled  = !!active;
}

function buildHealth(row){
  const serial = row?.serial || '';
  const h = healthCache[serial] || {};
  const pills = [];

  if (h.flags?.includes('disk_full'))   pills.push('💾⚠️');
  if (h.flags?.includes('low_battery')) pills.push('🔋⚠️');
  if (h.flags?.includes('muted'))       pills.push('🔇');
  if (h.flags?.includes('lost_mode'))   pills.push('🚨❗');
  if (h.flags?.includes('on_campus'))   pills.push('🏫');
  if (rowNeedsOsUpdate(row)) {
    const availableDisk = rowAvailableDiskValue(row);
    pills.push(availableDisk !== null && availableDisk < 8 ? '🔴⬆️' : '🟠⬆️');
  }
  if (!h.date_last_beat)                pills.push('⚪');

  let note = '';
  if (!h.date_last_beat) {
    note = 'Never seen on network';
  } else if (h.on_campus_today) {
    note = 'On campus today';
  } else if (h.days_since_campus !== null && h.days_since_campus !== undefined) {
    if (h.on_campus_today === false && h.last_ip_beat && h.last_ip_beat !== '65.254.21.222') {
      if (h.days_since_campus === 0) {
        note = 'Active today (off-campus)';
      } else {
        note = `Last seen ${h.days_since_campus} day${h.days_since_campus === 1 ? '' : 's'} ago (off-campus)`;
      }
    } else {
      note = `${h.days_since_campus} day(s) since last seen on campus`;
    }
  }

  if (rowNeedsOsUpdate(row)) {
    const targetVersion = normalizeVersionString(getRowValue(row, 'needosupdate'));
    const availableDisk = rowAvailableDiskValue(row);
    const updateNote = availableDisk !== null && availableDisk < 8
      ? `OS update available (${targetVersion}) and available disk is under 8 GB`
      : `OS update available (${targetVersion})`;
    note = note ? `${note} • ${updateNote}` : updateNote;
  }

  const pillStr = pills.join(' ');
  if (!pillStr && !note) return '';

  const span = document.createElement('span');
  span.textContent = pillStr || '';
  if (note) span.title = note;
  return span.outerHTML;
}

function showJsonWindow(title, payload){
  const win = window.open('', '_blank');
  if (!win) {
    alert('Popup blocked. Please allow popups for Musky.');
    return;
  }

  let obj = payload;
  if (typeof obj === 'string') {
    try { obj = JSON.parse(obj); } catch(e) { /* leave as string */ }
  }

  const pretty = (typeof obj === 'string')
    ? obj
    : JSON.stringify(obj, null, 2);

  const escaped = pretty.replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]));

  win.document.write(
    '<title>' + String(title) + '</title>' +
    '<pre style="font-size:12px;white-space:pre-wrap;word-wrap:break-word;">' +
    escaped +
    '</pre>'
  );
  win.document.close();
}

function firstPresent(...values){
  for (const value of values) {
    if (value === undefined || value === null) continue;
    if (typeof value === 'string' && value.trim() === '') continue;
    return value;
  }
  return null;
}

function getRowValue(r, ...keys){
  const sources = [
    r || {},
    r?.device_extra_parsed || {},
    r?.history_extra_parsed || {}
  ];

  for (const key of keys) {
    for (const src of sources) {
      if (src && Object.prototype.hasOwnProperty.call(src, key)) {
        const value = src[key];
        if (value === undefined || value === null) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        return value;
      }
    }
  }

  return null;
}

function parseJSONish(value){
  if (Array.isArray(value) || (value && typeof value === 'object')) return value;
  if (typeof value !== 'string') return null;

  const trimmed = value.trim();
  if (!trimmed || (!trimmed.startsWith('[') && !trimmed.startsWith('{'))) {
    return null;
  }

  try {
    return JSON.parse(trimmed);
  } catch (e) {
    return null;
  }
}

function formatEpochish(value){
  if (value === undefined || value === null || value === '') return '';

  const num = Number(value);
  if (!Number.isFinite(num)) return String(value);

  const ms = num < 1e12 ? num * 1000 : num;
  const d = new Date(ms);
  if (Number.isNaN(d.getTime())) return String(value);

  return d.toLocaleString();
}

function formatPercent(value){
  if (value === undefined || value === null || value === '') return '';

  let num = Number(value);
  if (!Number.isFinite(num)) return String(value);
  if (num <= 1) num *= 100;

  return `${num.toFixed(1)}%`;
}

function formatDiskGb(value){
  if (value === undefined || value === null || value === '') return '';

  const num = Number(value);
  if (!Number.isFinite(num)) return String(value);

  return `${num.toFixed(1)} GB`;
}

// Mirror DeviceVitals update detection so both pages agree on when an iPad
// needs iOS/iPadOS attention and when low free disk should make that warning
// more urgent.
function normalizeVersionString(value){
  const str = String(value ?? '').trim();
  return str ? str : '';
}

function compareVersionStrings(a, b){
  const aParts = String(a).split('.').map(n => Number.parseInt(n, 10) || 0);
  const bParts = String(b).split('.').map(n => Number.parseInt(n, 10) || 0);
  const maxLen = Math.max(aParts.length, bParts.length);

  for (let i = 0; i < maxLen; i++) {
    const av = aParts[i] ?? 0;
    const bv = bParts[i] ?? 0;
    if (av > bv) return 1;
    if (av < bv) return -1;
  }

  return 0;
}

function rowAvailableDiskValue(r){
  const raw = firstPresent(getRowValue(r, 'available_disk'), getRowValue(r, 'avaliable_disk'));
  const num = Number(raw);
  return Number.isFinite(num) ? num : null;
}

function rowNeedsOsUpdate(r){
  const current = normalizeVersionString(firstPresent(r.os_version, getRowValue(r, 'osversion')));
  const target  = normalizeVersionString(getRowValue(r, 'needosupdate'));

  if (!current || !target) return false;
  return compareVersionStrings(target, current) > 0;
}

function buildOsVersionSummary(r){
  const current = normalizeVersionString(firstPresent(r.os_version, getRowValue(r, 'osversion')));
  const target  = normalizeVersionString(getRowValue(r, 'needosupdate'));

  if (current && target && rowNeedsOsUpdate(r)) {
    return `${current} → ${target}`;
  }

  return current;
}

function getDeviceIconMeta(r){
  return window.muskyDeviceIconMeta({
    deviceModel: firstPresent(
      getRowValue(r, 'device_model'),
      getRowValue(r, 'device_model_name'),
      getRowValue(r, 'model')
    ) || '',
    modelName: firstPresent(
      getRowValue(r, 'model_name'),
      getRowValue(r, 'model'),
      getRowValue(r, 'device_model_name')
    ) || '',
    deviceType: getRowValue(r, 'device_type') || '',
    iconBase: '../icons'
  });
}

function summarizeAvailableUpdates(r){
  const raw = getRowValue(r, 'AvailableOSUpdates');
  const parsed = parseJSONish(raw);

  if (Array.isArray(parsed) && parsed.length) {
    const summary = parsed.map(u => {
      const name = firstPresent(u.HumanReadableName, u.Version, u.ProductKey, 'Update');
      const flags = [];
      if (u.IsSecurityResponse) flags.push('SR');
      if (u.IsCritical) flags.push('Critical');
      return flags.length ? `${name} (${flags.join(', ')})` : String(name);
    });

    return {
      text: summary[0],
      title: summary.join('\n')
    };
  }

  const needed = firstPresent(getRowValue(r, 'needosupdate'), r.needsosupdate ? 'YES' : null);
  if (needed) {
    return {
      text: typeof needed === 'string' ? needed : String(needed),
      title: ''
    };
  }

  return {
    text: 'None',
    title: ''
  };
}

function appendTextCell(tr, text, className = '', title = ''){
  const td = document.createElement('td');
  if (className) td.className = className;
  td.textContent = text || '';
  if (title) td.title = title;
  tr.appendChild(td);
  return td;
}

function buildIiqTicketUrl(ticketId){
  const ticket = String(ticketId || '').trim();
  if (!ticket || !IIQ_TICKET_BASE_URL) return '';
  return `${IIQ_TICKET_BASE_URL}${encodeURIComponent(ticket)}`;
}

function buildTicketLinkHtml(ticketId, extraClass = ''){
  const ticket = String(ticketId || '').trim();
  const ticketUrl = buildIiqTicketUrl(ticket);
  if (!ticket || !ticketUrl) return '';
  const cls = ['ticket-link-btn', extraClass].filter(Boolean).join(' ');
  return `<a href="${ticketUrl}" target="_blank" rel="noopener" class="${cls}" title="Open IIQ ticket ${ticket}">🎫 HAS TICKET</a>`;
}

async function fetchRowsForPools(poolNames, stageLabel = 'Loading pool data'){
  const rows = [];

  for (let i = 0; i < poolNames.length; i++) {
    const poolName = poolNames[i];
    setLoadingState(
      true,
      `${stageLabel} (${i + 1}/${poolNames.length}): ${poolName}`,
      `${rows.length} devices gathered so far. Bigger selections can take a bit.`
    );
    const resp = await fetchJSON(`${ENDPOINTS.devices}?pool=${encodeURIComponent(poolName)}`);
    rows.push(...(resp.rows || []));
  }

  return rows;
}

async function fetchHealthForSerials(serials, stageLabel = 'Loading health data'){
  const uniqueSerials = Array.from(new Set((serials || []).filter(Boolean)));
  if (!uniqueSerials.length) return {};

  const chunkSize = 75;
  const merged = {};

  for (let i = 0; i < uniqueSerials.length; i += chunkSize) {
    const chunk = uniqueSerials.slice(i, i + chunkSize);
    const idx = Math.floor(i / chunkSize) + 1;
    const total = Math.ceil(uniqueSerials.length / chunkSize);
    setLoadingState(
      true,
      `${stageLabel} (${idx}/${total})`,
      `Checking ${chunk.length} devices in this pass (${uniqueSerials.length} total serials).`
    );

    const h = await fetchJSON(
      `${ENDPOINTS.health}?serials=${encodeURIComponent(chunk.join(','))}`
    );
    Object.assign(merged, h.devices || {});
  }

  return merged;
}

function selectedPoolNames(poolValue){
  if (poolValue === ALL_POOLS_VALUE) {
    return availablePools.map(p => p.pool_name).filter(Boolean);
  }
  return poolValue ? [poolValue] : [];
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------
function renderRows(){
  resultsBody.innerHTML = '';

  const hideUnctrl = hideUnctrlCB.checked;

  let loanedCount      = 0;
  let totalCount       = lastRows.length;
  let goodCount        = 0;
  let needsAssignCount = 0;
  let badCount         = 0;

  for (const r of lastRows){
    const h = healthCache[r.serial] || {};

    // Use Nora health ONLY for controllability: never seen OR "bad" tags
    const neverSeen      = !h.date_last_beat;
    const uncontrollable = isUnctrl(r.tags) || neverSeen;

    if (r.loaned) loanedCount++;

    if (hideUnctrl && uncontrollable) {
      continue;
    }

    const tr = document.createElement('tr');

    const status = r.loan_status || 'IDLE';
    if (status === 'GOOD_LOAN_OUT') {
      tr.classList.add('row-good');
      goodCount++;
    } else if (status === 'NEEDS_ASSIGN') {
      tr.classList.add('row-needs-assign');
      needsAssignCount++;
    } else if (status === 'BAD_MISMATCH' || status === 'BAD_NO_TICKET') {
      tr.classList.add('row-bad');
      badCount++;
    }

    // 0: selector or 🚫
    const td0 = document.createElement('td');
    if (uncontrollable) {
      td0.className   = 'unctrl-symbol';
      td0.textContent = '🚫';
      td0.title = neverSeen
        ? 'Uncontrollable (Never seen on network)'
        : 'Uncontrollable (STOLEN/BROKEN/InStorage/Out2AGi)';
    } else {
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'rowcheck';
      cb.dataset.serial = r.serial || '';
      td0.appendChild(cb);
    }
    tr.appendChild(td0);

    // Serial + model icon
    const iconMeta = getDeviceIconMeta(r);
    const tdSerial = document.createElement('td');
    tdSerial.className = 'col-extra';
    const serialText = document.createElement('span');
    serialText.textContent = r.serial || '';
    tdSerial.appendChild(serialText);
    tr.appendChild(tdSerial);

    // Asset Tag
    const tdAsset = document.createElement('td');
    const assetWrap = document.createElement('div');
    assetWrap.className = 'asset-tag-wrap';

    const assetIcon = document.createElement('img');
    assetIcon.className = 'device-model-icon';
    assetIcon.src = iconMeta.icon;
    assetIcon.alt = 'Device Model Icon';
    assetIcon.title = [iconMeta.label, iconMeta.modelID].filter(Boolean).join(' • ');
    assetWrap.appendChild(assetIcon);

    const assetText = document.createElement('span');
    assetText.textContent = r.asset_tag || '';
    assetWrap.appendChild(assetText);

    tdAsset.appendChild(assetWrap);
    tr.appendChild(tdAsset);

    // Mosyle User
    const tdMosyleUser = document.createElement('td');
    // IMPORTANT:
    //   This column must reflect current assignment, not who last logged into
    //   the device. On loaners, the raw Mosyle payload can still include
    //   userid/username/useremail for the last interactive user even when the
    //   device is not assigned in Nora/Mosyle. DeviceVitals is already using
    //   devices.owner_id as the assignment truth, so LoanerExplorer follows the
    //   same rule here.
    if (r.mosyle_owner_id || r.mosyle_owner_email || r.mosyle_owner_name) {
      const idPart   = r.mosyle_owner_id   ? `#${r.mosyle_owner_id}`   : '';
      const namePart = r.mosyle_owner_name || r.mosyle_owner_email || '';
      tdMosyleUser.textContent = [idPart, namePart].filter(Boolean).join(' ');
    } else {
      tdMosyleUser.textContent = '';
    }
    tr.appendChild(tdMosyleUser);

    // IIQ Loan User
    const tdIIQUser = document.createElement('td');
    if (r.iiq_owner_id || r.iiq_owner_email || r.iiq_owner_name) {
      const idPart   = r.iiq_owner_id      ? `#${r.iiq_owner_id}`      : '';
      const namePart = r.iiq_owner_name    || r.iiq_owner_email || '';
      tdIIQUser.textContent = [idPart, namePart].filter(Boolean).join(' ');
    } else {
      tdIIQUser.textContent = '';
    }
    tr.appendChild(tdIIQUser);

    // Loaned?
    appendTextCell(tr, r.loaned ? 'Yes' : 'No');

    // Pool
    appendTextCell(tr, r.pool_name || '');

    // Ticket
    const tdTicket = document.createElement('td');
    tdTicket.className = 'col-extra';
    if (r.ticket_id) {
      const ticketText = document.createElement('span');
      ticketText.textContent = r.ticket_id;
      tdTicket.appendChild(ticketText);

      const ticketLinkHtml = buildTicketLinkHtml(r.ticket_id, 'ticket-link-inline');
      if (ticketLinkHtml) {
        tdTicket.insertAdjacentHTML('beforeend', ticketLinkHtml);
      }
    }
    tr.appendChild(tdTicket);

    // Issue Date
    appendTextCell(tr, r.issue_date || '', 'col-extra');

    // Days Assigned (based on IIQ issue_date)
    const tdDays = document.createElement('td');
    if (r.issue_date) {
      const assigned = new Date(r.issue_date);
      if (!isNaN(assigned)) {
        const diffMs  = Date.now() - assigned.getTime();
        const days    = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        tdDays.textContent = String(days);
        tdDays.title       = `${days} day(s) since issued (${r.issue_date})`;
      } else {
        tdDays.textContent = '';
        tdDays.title       = '';
      }
    } else {
      tdDays.textContent = '';
      tdDays.title       = '';
    }
    tr.appendChild(tdDays);

    // OS / Status column (from NORA device_history snapshot)
    const tdOS = document.createElement('td');
    tdOS.className = 'col-extra';
    const pieces = [];
    const osVersionSummary = buildOsVersionSummary(r);
    if (osVersionSummary)  pieces.push(`OS: ${osVersionSummary}`);
    if (r.lostmode_status) pieces.push(`Lost: ${r.lostmode_status}`);
    if (r.last_ip_beat)    pieces.push(`IP: ${r.last_ip_beat}`);
    const totalDiskSummary = firstPresent(r.total_disk, getRowValue(r, 'total_disk'));
    if (totalDiskSummary)  pieces.push(`Disk: ${formatDiskGb(totalDiskSummary)}`);
    if (rowNeedsOsUpdate(r)) {
      const targetVersion = normalizeVersionString(getRowValue(r, 'needosupdate'));
      pieces.push(`Needs OS Update: YES${targetVersion ? ` (${targetVersion})` : ''}`);
    } else {
      const targetVersion = normalizeVersionString(getRowValue(r, 'needosupdate'));
      if (targetVersion) {
        pieces.push(`Needs OS Update: NO`);
      }
    }
    tdOS.textContent = pieces.join(' • ');
    tr.appendChild(tdOS);

    // Last Check-In (campus-centric, from Nora health)
    const tdSeen = document.createElement('td');
    tdSeen.className = 'col-lastseen';

    if (!h.date_last_beat) {
      tdSeen.textContent = 'Never seen';
      tdSeen.title       = '';
    } else if (h.on_campus_today) {
      tdSeen.textContent = 'On campus today';
      tdSeen.title       = h.date_last_beat;
    } else if (typeof h.days_since_campus === 'number') {
      if (h.days_since_campus === 0) {
        tdSeen.textContent = 'Seen today (off-campus)';
        tdSeen.title       = h.date_last_beat;
      } else if (h.days_since_campus > 0) {
        tdSeen.textContent =
          `${h.days_since_campus} day` +
          (h.days_since_campus === 1 ? '' : 's') +
          ' since campus';
        tdSeen.title = h.date_last_beat;
      } else {
        tdSeen.textContent = timeAgo(h.date_last_beat);
        tdSeen.title       = h.date_last_beat;
      }
    } else {
      tdSeen.textContent = timeAgo(h.date_last_beat);
      tdSeen.title       = h.date_last_beat;
    }
    tr.appendChild(tdSeen);

    // Tags
    const tdTags = document.createElement('td');
    tdTags.className = 'col-tags';
    tdTags.textContent = Array.isArray(r.tags) ? r.tags.join(', ') : '';
    tr.appendChild(tdTags);

    // JSON buttons
    const tdJson = document.createElement('td');
    tdJson.className = 'col-extra';
    const btnHist = document.createElement('button');
    btnHist.className = 'btn-mini';
    btnHist.textContent = 'Hist JSON';
    btnHist.addEventListener('click', () => {
      showJsonWindow(`Device History JSON — ${r.serial || ''}`, r.history_extra_data || '{}');
    });
    tdJson.appendChild(btnHist);

    const btnDev = document.createElement('button');
    btnDev.className = 'btn-mini';
    btnDev.textContent = 'Dev JSON';
    btnDev.addEventListener('click', () => {
      showJsonWindow(`Device JSON — ${r.serial || ''}`, r.device_extra_data || '{}');
    });
    tdJson.appendChild(btnDev);
    tr.appendChild(tdJson);

    // Health + links
    const tdHealth = document.createElement('td');
    tdHealth.innerHTML = buildHealth(r);

    <?php if ($is_admin): ?>
    const links = [];
    if (r.ticket_id) {
      const ticketLinkHtml = buildTicketLinkHtml(r.ticket_id);
      if (ticketLinkHtml) {
        links.push(ticketLinkHtml);
      }
    }
    if (r.open_direct_device_link) {
      links.push(
        `<a href="${r.open_direct_device_link}" target="_blank" rel="noopener" class="health-text-link">📎 Mosyle</a>`
      );
    }
    if (r.asset_tag) {
      if (r.serial) {
        const detailsLink = `../public/DeviceVitals.php?serial=${encodeURIComponent(r.serial)}`;
        links.push(
          `<a href="${detailsLink}" target="_blank" rel="noopener" class="health-text-link">⚡︎ Details</a>`
        );
      }
    }
    if (links.length) {
      tdHealth.innerHTML += `<div class="health-links">${links.join(' ')}</div>`;
    }
    <?php endif; ?>

    tr.appendChild(tdHealth);

    const dateInfo = getRowValue(r, 'date_info');
    appendTextCell(
      tr,
      formatEpochish(dateInfo),
      'col-extra',
      dateInfo !== null && dateInfo !== undefined && dateInfo !== '' ? String(dateInfo) : ''
    );

    const osVersion = buildOsVersionSummary(r);
    appendTextCell(tr, osVersion ? String(osVersion) : '', 'col-extra');

    const battery = getRowValue(r, 'battery');
    appendTextCell(
      tr,
      formatPercent(battery),
      'col-extra',
      battery !== null && battery !== undefined && battery !== '' ? String(battery) : ''
    );

    const totalDisk = firstPresent(r.total_disk, getRowValue(r, 'total_disk'));
    appendTextCell(
      tr,
      formatDiskGb(totalDisk),
      'col-extra',
      totalDisk !== null && totalDisk !== undefined && totalDisk !== '' ? String(totalDisk) : ''
    );

    const percentDisk = getRowValue(r, 'percent_disk');
    appendTextCell(
      tr,
      formatPercent(percentDisk),
      'col-extra',
      percentDisk !== null && percentDisk !== undefined && percentDisk !== '' ? String(percentDisk) : ''
    );

    const availableDisk = firstPresent(getRowValue(r, 'available_disk'), getRowValue(r, 'avaliable_disk'));
    appendTextCell(
      tr,
      formatDiskGb(availableDisk),
      'col-extra',
      availableDisk !== null && availableDisk !== undefined && availableDisk !== '' ? String(availableDisk) : ''
    );

    const updateSummary = summarizeAvailableUpdates(r);
    appendTextCell(tr, updateSummary.text, 'col-extra', updateSummary.title);

    resultsBody.appendChild(tr);
  }

  // Extra-column toggle
  if (hideExtraCB.checked) {
    document.body.classList.add('hide-extra');
  } else {
    document.body.classList.remove('hide-extra');
  }

  const pctLoaned = totalCount ? ((loanedCount / totalCount) * 100).toFixed(1) : '0.0';
  summaryBadge.textContent =
    `${loanedCount}/${totalCount} loaners out (${pctLoaned}%)` +
    ` — GOOD: ${goodCount}, Needs Assign: ${needsAssignCount}, BAD: ${badCount}`;
}

// ---------------------------------------------------------------------------
// Loaders
// ---------------------------------------------------------------------------
async function loadPools(){
  poolSelect.innerHTML = '';
  poolSelect.appendChild(new Option('(Loading...)', ''));

  const j = await fetchJSON(ENDPOINTS.pools);
  poolSelect.innerHTML = '';

  const pools = j.pools || [];
  availablePools = pools;
  if (!pools.length) {
    poolSelect.appendChild(new Option('(No pools found in iiq_loaners)', ''));
    return;
  }

  poolSelect.appendChild(new Option('(Select pool)', ''));
  if (IS_ADMIN) {
    const totalDevices = pools.reduce((sum, p) => sum + Number(p.device_count || 0), 0);
    const totalLoaned  = pools.reduce((sum, p) => sum + Number(p.loaned_count || 0), 0);
    poolSelect.appendChild(
      new Option(`ALL POOLS (${totalDevices} devices, ${totalLoaned} loaned)`, ALL_POOLS_VALUE)
    );
  }
  for (const p of pools){
    const label = p.device_count !== undefined
      ? `${p.pool_name} (${p.device_count} devices, ${p.loaned_count} loaned)`
      : p.pool_name;
    poolSelect.appendChild(new Option(label, p.pool_name));
  }

  const desiredDefault = currentDefaultPool;
  if (desiredDefault && Array.from(poolSelect.options).some(opt => opt.value === desiredDefault)) {
    poolSelect.value = desiredDefault;
    refreshDefaultPoolUI();
    loadPoolDevices(desiredDefault);
    return;
  }

  refreshDefaultPoolUI();
}

// Start INV_LOOKUP via NoraAPI (best-effort, safe)
async function startINVLookupForSerials(serials){
  if (!serials.length) return null;

  try {
    setInvStatus(`Submitting INV_LOOKUP for ${serials.length} device(s)...`, 'info');

    const resp = await fetch(ENDPOINTS.invStart, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify({ serials })
    });

    const txt = await resp.text();
    let j = {};
    try { j = JSON.parse(txt); }
    catch(e){
      console.error('INV_LOOKUP submit: non-JSON response', txt);
      setInvStatus('NORA INV_LOOKUP submit returned invalid JSON; using existing data.', 'error');
      return null;
    }

    if (!resp.ok || !j.errand_id) {
      console.error('INV_LOOKUP submit failed', j);
      setInvStatus('NORA INV_LOOKUP submit failed; using existing data.', 'error');
      return null;
    }

    setInvStatus(`INV_LOOKUP submitted (Errand #${j.errand_id}), waiting for completion...`, 'info');
    return j.errand_id;
  } catch (e) {
    console.error('INV_LOOKUP submit exception', e);
    setInvStatus('Error talking to NORA (INV_LOOKUP); using existing data.', 'error');
    return null;
  }
}

// Poll INV_LOOKUP errand until complete or fail (best-effort)
async function waitForINVLookupComplete(errandId){
  if (!errandId) return false;

  const maxMs    = 120000;
  const interval = 3000;
  const start    = Date.now();

  while (true) {
    const elapsed = Date.now() - start;
    if (elapsed > maxMs) {
      setInvStatus(`INV_LOOKUP timeout after ${Math.round(elapsed/1000)}s; using latest available data.`, 'error');
      return false;
    }

    const url = `${ENDPOINTS.invStat}?id=${encodeURIComponent(errandId)}`;
    const j   = await fetchJSON(url);

    const status = (j && j.status) ? String(j.status) : 'Unknown';
    const up     = status.toUpperCase();

    if (up === 'COMPLETE') {
      setLoadingState(
        true,
        'INV_LOOKUP complete. Refreshing results...',
        'Musky is pulling the freshest device data back into the table.'
      );
      setInvStatus(`NORA inventory synced (Errand #${errandId}).`, 'ok');
      return true;
    }

    if (['FAILED','REJECTED','CANCELLED','UNKNOWN'].includes(up)) {
      setLoadingState(
        true,
        `INV_LOOKUP ended with status: ${status}`,
        'Using the best data currently available.'
      );
      setInvStatus(`INV_LOOKUP ended with status "${status}".`, 'error');
      return false;
    }

    setLoadingState(
      true,
      `Waiting for INV_LOOKUP... ${status}`,
      'Nora is still processing inventory refresh work for this selection.'
    );
    setInvStatus(`INV_LOOKUP status: ${status}...`, 'info');
    await new Promise(r => setTimeout(r, interval));
  }
}

async function loadPoolDevices(poolName){
  if (!poolName || isSyncing) return;
  isSyncing = true;

  try {
    currentPoolValue = poolName;
    currentPoolName = poolName === ALL_POOLS_VALUE ? 'ALL POOLS' : poolName;
    trackActivity('LOAD_POOL', { pool: currentPoolName, pool_value: currentPoolValue });
    const poolsToLoad = selectedPoolNames(poolName);
    if (!poolsToLoad.length) {
      setInvStatus('No pools available to load.', 'error');
      return;
    }

    setHeader(currentPoolName);
    mainContent.classList.add('fade-out');
    setLoadingState(
      true,
      `Loading ${currentPoolName}...`,
      `Preparing ${poolsToLoad.length} pool${poolsToLoad.length === 1 ? '' : 's'} for display.`
    );
    setInvStatus('Loading baseline data...', 'info');

    // ---------------------------------------------------------------------
    // STEP 1: Always load baseline rows
    // ---------------------------------------------------------------------
    const baselineRows = await fetchRowsForPools(poolsToLoad, 'Loading baseline data');
    const serials = Array.from(new Set(
      baselineRows.map(r => r.serial).filter(Boolean)
    ));

    lastRows     = baselineRows;
    lastLoadedAt = Date.now();

    // If no devices at all for this pool, stop here
    if (!serials.length) {
      healthCache = {};
      renderRows();
      setHeader(currentPoolName);
      setInvStatus('No devices found for this selection.', 'info');
      return;
    }

    // ---------------------------------------------------------------------
    // STEP 2: Always pull health for the baseline rows
    // ---------------------------------------------------------------------
    try {
      healthCache = await fetchHealthForSerials(serials, 'Loading baseline health');
    } catch (e) {
      console.error('Health fetch failed (baseline)', e);
      healthCache = {};
    }

    renderRows();
    setHeader(currentPoolName);
    setInvStatus('Baseline data + health loaded. Attempting NORA INV_LOOKUP...', 'info');

    // ---------------------------------------------------------------------
    // STEP 3: Try INV_LOOKUP (best-effort). If it fails, we KEEP baseline.
    // ---------------------------------------------------------------------
    const errandId = await startINVLookupForSerials(serials);
    if (!errandId) {
      // We already have baseline rows + health; just stop here.
      setInvStatus('Using latest health data; INV_LOOKUP did not start.', 'error');
      return;
    }

    // ---------------------------------------------------------------------
    // STEP 4: Wait for INV_LOOKUP completion (best-effort; may timeout)
    // ---------------------------------------------------------------------
    const ok = await waitForINVLookupComplete(errandId);

    // ---------------------------------------------------------------------
    // STEP 5: Fetch refreshed device rows and health (post-sync pass)
    // ---------------------------------------------------------------------
    lastRows = await fetchRowsForPools(poolsToLoad, 'Refreshing pool data');
    lastLoadedAt = Date.now();

    const freshSerials = lastRows.map(r => r.serial).filter(Boolean);
    if (freshSerials.length) {
      try {
        healthCache = await fetchHealthForSerials(freshSerials, 'Refreshing health data');
      } catch (e) {
        console.error('Health fetch failed (post-INV)', e);
        // keep whatever we had from baseline
      }
    }

    renderRows();
    setHeader(currentPoolName);

    if (ok) {
      setInvStatus(`NORA inventory synced ${new Date(lastLoadedAt).toLocaleTimeString()}.`, 'ok');
    } else {
      setInvStatus('Showing latest data available; INV_LOOKUP did not complete cleanly.', 'error');
    }

  } catch (e) {
    console.error('loadPoolDevices exception', e);
    setInvStatus('Error loading pool; see console logs.', 'error');
  } finally {
    setLoadingState(false);
    mainContent.classList.remove('fade-out');
    isSyncing = false;
  }
}

// ---------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------
loadPoolBtn.addEventListener('click', () => {
  const opt = poolSelect.selectedOptions?.[0];
  if (!opt || !opt.value) {
    alert('Pick a pool first.');
    return;
  }
  loadPoolDevices(opt.value);
});

poolSelect.addEventListener('change', () => {
  refreshDefaultPoolUI();
  const opt = poolSelect.selectedOptions?.[0];
  if (opt && opt.value) {
    loadPoolDevices(opt.value);
  }
});

document.getElementById('defaultPoolForm')?.addEventListener('submit', (ev) => {
  const selectedValue = currentPoolSelectionValue();
  if (!selectedValue) {
    ev.preventDefault();
    alert('Pick a pool first.');
    return;
  }
  if (defaultPoolInput) {
    defaultPoolInput.value = selectedValue;
  }
});

hideUnctrlCB.addEventListener('change', renderRows);
hideExtraCB.addEventListener('change', renderRows);

document.getElementById('reloadBtn')?.addEventListener('click', () => {
  if (!currentPoolValue) return;
  trackActivity('RELOAD_POOL', { pool: currentPoolName, pool_value: currentPoolValue });
  loadPoolDevices(currentPoolValue);
});

document.getElementById('reportBtn')?.addEventListener('click', () => {
  const picks = selectedSerials();
  if (!picks.length) {
    alert('Select at least one device.');
    return;
  }
  trackActivity('REPORT_PROBLEM', { pool: currentPoolName, pool_value: currentPoolValue }, picks);
  window.open(
    `../HelperPages/MuskyMakeATicket_iPad.php?serial=${encodeURIComponent(picks.join(','))}`,
    '_blank'
  );
});

document.getElementById('deviceReportBtn')?.addEventListener('click', () => {
  const picks = selectedSerials();
  if (!picks.length) {
    alert('Select at least one device.');
    return;
  }
  trackActivity('DEVICE_REPORT', { pool: currentPoolName, pool_value: currentPoolValue }, picks);
  picks.forEach(s =>
    window.open(`../DeviceManager/device_report.php?serial=${encodeURIComponent(s)}`, '_blank')
  );
});

<?php if ($is_admin): ?>
document.getElementById('debugBtn')?.addEventListener('click', () => {
  const picks = selectedSerials();
  if (picks.length !== 1) {
    alert('Select exactly one device to inspect.');
    return;
  }
  const serial = picks[0];
  trackActivity('DEBUG_ROW', { pool: currentPoolName, pool_value: currentPoolValue }, picks);
  const obj = lastRows.find(r => r.serial === serial);
  if (!obj) {
    alert('No data found for that serial.');
    return;
  }
  showJsonWindow(`LoanerExplorer Row — ${serial}`, obj);
});

document.getElementById('powerRebootBtn')?.addEventListener('click', () => {
  const picks = selectedSerials();
  if (!picks.length) {
    alert('Select at least one device.');
    return;
  }
  trackActivity('POWER_REBOOT', { pool: currentPoolName, pool_value: currentPoolValue }, picks);
  const label = picks.length === 1 ? picks[0] : `${picks.length} selected devices`;
  if (!window.confirm(`Open POWER REBOOT for ${label}?`)) {
    return;
  }
  window.open(
    `../HelperPages/Musky_Reboot_iPad.php?serial=${encodeURIComponent(picks.join(','))}`,
    '_blank',
    'width=900,height=800'
  );
});

document.getElementById('powerWashBtn')?.addEventListener('click', () => {
  const picks = selectedSerials();
  if (!picks.length) {
    alert('Select at least one device.');
    return;
  }
  trackActivity('POWER_WASH_AND_WAX', { pool: currentPoolName, pool_value: currentPoolValue }, picks);
  const label = picks.length === 1 ? picks[0] : `${picks.length} selected devices`;
  const msg =
    `Open POWER WASH & WAX for ${label}?\n\n` +
    `This will launch the wipe helper in STORAGE mode so loaners are wiped without being reassigned.`;
  if (!window.confirm(msg)) {
    return;
  }
  const url =
    `../HelperPages/Musky_Wipe_iPad.php?serial=${encodeURIComponent(picks.join(','))}&no_reassign=1`;
  window.open(url, '_blank', 'width=1200,height=900');
});
<?php endif; ?>

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------
loadPools();
refreshDefaultPoolUI();
</script>
</body>
</html>
