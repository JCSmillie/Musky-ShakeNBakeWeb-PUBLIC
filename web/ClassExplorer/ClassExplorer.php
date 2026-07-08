<?php
// ============================================================================
// MUSKY — ClassExplorer.php (Hide-Toggles Fixed + Correct Student Column)
// ----------------------------------------------------------------------------
// Fixes in this build:
//  • Student column now shows the student's NAME (extra_data.username).
//  • Username column (hidden by default via "Hide Extra Fields?") now shows
//    the LOGIN/ACCOUNT (extra_data.userid).
//  • Both "Hide uncontrollable iPads?" and "Hide Extra Fields?" checkboxes work.
//  • Building → Teacher → Class chain and spoof remain intact.
//  • Health integration stays (via HelperPages/fetch_device_health.php).
//  • Legend + mascot link preserved.
//
// Notes:
//  • Uncontrollable tags: STOLEN, BROKEN, InStorage, Out2AGi
//  • We hide Username + Last Check-In columns when "Hide Extra Fields?" is checked
//    using a CSS class toggle (.hide-extra) for instant, flicker-free UI.
// ============================================================================

// if (!isset($_GET['api'])) {
//   // Harness is useful for dev-spoofing; it must not run during API calls.
//    include_once __DIR__ . '/../../Functions/Utility/MuskyTestHarness.PRIVATE.php';
// }

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../check_access.php';
require_once __DIR__ . '/../../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../../Functions/MuskyCsrf.php';

// -----------------------------------------------------------------------------
// Access gate (same semantics as the rest of Musky tools)
// -----------------------------------------------------------------------------
$tool_required_variants = ['CLASS_MANAGER'];
$allowed_raw = $_SESSION['musky_user']['allowed_tools'] ?? '';
$allowed     = array_filter(array_map('trim', explode(',', $allowed_raw)));
$hasTool     = in_array('ALL_TOOLS', $allowed, true);
if (!$hasTool) {
    foreach ($tool_required_variants as $v) {
        if (in_array($v, $allowed, true)) { $hasTool = true; break; }
    }
}
if (!$hasTool) { http_response_code(403); echo "⛔ Access Denied — Missing Required Tool Variant."; exit; }

$is_admin = in_array('DEVICE_MANAGER', $allowed, true) || in_array('ALL_TOOLS', $allowed, true);
$is_admin = $is_admin
    || in_array('ADMIN_PANEL', $allowed, true)
    || in_array('HDK-SUPERVISOR-LII', $allowed, true)
    || in_array('HDK-SUPERVISOR-ADMIN', $allowed, true);

// -----------------------------------------------------------------------------
// Theme + Log path
// -----------------------------------------------------------------------------
$prefs = musky_get_logged_in_user_prefs();
$theme = $prefs['theme'] ?? 'musky-mode';
$email = $prefs['email'] ?? ($_SESSION['musky_user']['email'] ?? '');

$LOGFILE = (isset($LOG_PATH) && $LOG_PATH)
    ? rtrim($LOG_PATH, '/') . '/musky_class_debug.log'
    : '/tmp/musky_class_debug.log';

function class_explorer_log_clean($value, int $maxLen = 240): string
{
    $value = str_replace(["\r", "\n"], ' ', (string)$value);
    return substr($value, 0, $maxLen);
}

// -----------------------------------------------------------------------------
// Inline API (logging)
// -----------------------------------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $mode = $_GET['api'];
    $data = json_decode(file_get_contents('php://input'), true);
    $by   = $email ?: 'unknown';

    if (in_array($mode, ['log_unctrl','log_reboot','log_report'], true) && is_array($data)) {
        if (!musky_csrf_is_valid('class_explorer', $data['csrf_token'] ?? null)) {
            http_response_code(403);
            echo json_encode(['ok' => 0, 'error' => 'forbidden']);
            exit;
        }

        $action = match ($mode) {
            'log_unctrl' => 'hidden',
            'log_reboot' => 'reboot',
            'log_report' => 'report',
            default      => 'unknown'
        };
        $serialInput = is_array($data['serials'] ?? null) ? $data['serials'] : [];
        $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
        $class = class_explorer_log_clean($data['class_name'] ?? '(unknown class)');
        $serials = implode(',', array_map(static fn($s) => class_explorer_log_clean($s, 80), $serialInput));

        $lines = [];
        if ($mode === 'log_unctrl' && $entries) {
            foreach ($entries as $e) {
                $lines[] = sprintf(
                    "[%s] <ClassExplorer> hidden=%s student=\"%s\" username=%s serial=%s class=\"%s\" by=\"%s\"",
                    date('Y-m-d H:i:s'),
                    class_explorer_log_clean($e['reason'] ?? 'UNKNOWN', 80),
                    class_explorer_log_clean($e['student'] ?? ''),
                    class_explorer_log_clean($e['username'] ?? '', 120),
                    class_explorer_log_clean($e['serial'] ?? '', 80),
                    $class,
                    $by
                );
            }
        } else {
            $lines[] = sprintf(
                "[%s] <ClassExplorer> %s_by=%s class=\"%s\" serials=%s",
                date('Y-m-d H:i:s'), $action, $by, $class, $serials
            );
        }

        @file_put_contents($LOGFILE, implode("\n", $lines) . "\n", FILE_APPEND);
        musky_activity_log([
            'event_type'      => 'ACTION',
            'action_name'     => strtoupper($action),
            'page_path'       => '/ClassExplorer/ClassExplorer.php',
            'target_serials'  => $serialInput,
            'extra'           => [
                'mode'       => $mode,
                'class_name' => $class,
                'entries'    => $entries,
            ],
        ]);
        echo json_encode(['ok' => 1]);
        exit;
    }

    echo json_encode(['ok' => 0]);
    exit;
}

$logged_in_username = $email && str_contains($email,'@') ? strtolower(strtok($email,'@')) : '';
$csrfToken = musky_csrf_token('class_explorer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Musky — Class Explorer</title>
<link rel="stylesheet" href="../theme.css?theme=<?= htmlspecialchars($theme) ?>">
<style>
body{margin:0;}
.header{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid rgba(0,0,0,0.08);}
.header h1{margin:0;font-size:1.25rem;font-weight:800;}
.header .spacer{flex:1;}
.back-btn{text-decoration:none;font-weight:700;}
.section{padding:12px 16px;}
.panel{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:rgba(0,0,0,0.03);padding:10px;border-radius:10px;margin-bottom:12px;}

.mainwrap{display:flex;gap:16px;align-items:flex-start;padding:0 16px 16px 16px;}
.main{flex:1;min-width:0;position:relative;}
.sidebar{width:180px;display:flex;flex-direction:column;gap:8px;align-items:stretch;position:sticky;top:12px;}

.btn-pill{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border:none;border-radius:12px;color:#fff;font-weight:700;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,0.1);background:linear-gradient(180deg,#2c6ef2 0%,#1e4ec9 100%);}
.btn-pill.orange{background:linear-gradient(180deg,#ff9800 0%,#f57c00 100%);}
.btn-pill.gray{background:linear-gradient(180deg,#607d8b 0%,#455a64 100%);}
.btn-pill:hover{filter:brightness(1.03);}

.tablewrap{overflow:auto;border:1px solid #e5e5e5;border-radius:10px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;}
th{background:rgba(0,0,0,0.05);position:sticky;top:0;}

.asset-tag-wrap{display:flex;align-items:center;gap:8px;}
.device-model-icon{width:28px;height:28px;object-fit:contain;border-radius:6px;box-shadow:0 2px 5px rgba(0,0,0,0.15);background:#fff;}

.tag-pill{background:#ff8c00;color:#fff;border-radius:12px;padding:2px 8px;margin:0 3px 3px 0;display:inline-block;font-size:0.85em;white-space:nowrap;}
.health-pill{background:#ff8c00;color:#fff;border-radius:12px;padding:2px 8px;margin-right:6px;display:inline-block;font-size:0.85em;}
.health-note{margin-top:4px;color:#666;font-size:0.85em;}
.unctrl-symbol{font-size:1.2em;color:#d9534f;text-align:center;}
.health-links{margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;}
.health-text-link{font-size:0.85em;text-decoration:none;font-weight:700;}

.legend-box{margin-top:12px;padding:10px;border:1px solid #e0e0e0;border-radius:10px;background:rgba(0,0,0,0.03);font-size:0.9em;}
.mascot-wrap{text-align:center;margin-top:14px;}

#classTitle{margin:0 0 4px 0;font-size:1.1rem;font-weight:800;text-align:center;}
#classSub{margin:0 0 10px 0;color:#666;text-align:center;font-style:italic;font-size:0.95rem;}
#missingBanner{display:none;background:#ffb347;color:#111;padding:8px 12px;border-radius:10px;margin:10px 0;font-weight:700;}
#summaryBadge{font-size:0.9rem;color:#555;text-align:center;margin:0 0 10px 0;font-weight:700;}
#invStatus{margin:8px 0 10px 0;font-size:0.84rem;color:#555;text-align:center;}

.loading-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.84);backdrop-filter:blur(2px);border-radius:12px;z-index:4;}
.loading-overlay.hidden{display:none;}
.loading-card{display:flex;flex-direction:column;align-items:center;gap:10px;padding:22px 24px 20px 24px;border-radius:18px;background:linear-gradient(180deg,rgba(255,255,255,0.98) 0%,rgba(244,248,255,0.96) 100%);box-shadow:0 16px 34px rgba(0,0,0,0.16);min-width:280px;max-width:360px;border:1px solid rgba(44,110,242,0.12);}
.loading-spinner{width:44px;height:44px;border-radius:50%;border:4px solid rgba(44,110,242,0.18);border-top-color:#2c6ef2;animation:spin 0.9s linear infinite;}
.loading-text{font-size:0.92rem;font-weight:700;text-align:center;}
.loading-subtext{font-size:0.8rem;color:#666;text-align:center;line-height:1.35;min-height:2.2em;}
.loading-hint{font-size:0.78rem;color:#446;text-align:center;line-height:1.35;min-height:2.2em;}
.loading-progress{width:100%;height:8px;border-radius:999px;overflow:hidden;background:rgba(44,110,242,0.12);box-shadow:inset 0 1px 2px rgba(0,0,0,0.08);}
.loading-progress-bar{width:42%;height:100%;border-radius:999px;background:linear-gradient(90deg,#2c6ef2 0%,#6fb3ff 45%,#2c6ef2 100%);animation:loadingBar 1.5s ease-in-out infinite;}
.loading-orbits{position:relative;width:64px;height:64px;display:flex;align-items:center;justify-content:center;}
.loading-orbit-dot{position:absolute;width:10px;height:10px;border-radius:50%;background:#2c6ef2;box-shadow:0 0 0 4px rgba(44,110,242,0.10);}
.loading-orbit-dot.one{animation:orbitOne 1.8s linear infinite;}
.loading-orbit-dot.two{animation:orbitTwo 1.8s linear infinite;}

@keyframes spin{to{transform:rotate(360deg);}}
@keyframes loadingBar{0%{transform:translateX(-85%);}50%{transform:translateX(125%);}100%{transform:translateX(-85%);}}
@keyframes orbitOne{0%{transform:translate(0,-24px);}25%{transform:translate(24px,0);}50%{transform:translate(0,24px);}75%{transform:translate(-24px,0);}100%{transform:translate(0,-24px);}}
@keyframes orbitTwo{0%{transform:translate(0,24px);}25%{transform:translate(-24px,0);}50%{transform:translate(0,-24px);}75%{transform:translate(24px,0);}100%{transform:translate(0,24px);}}

/* Hide Extra Fields toggle: username + last seen */
.hide-extra .col-username,
.hide-extra .col-lastseen { display:none; }
</style>
<script src="../DeviceModelIcons.js"></script>
</head>
<body class="<?php echo htmlspecialchars($theme); ?>">

<div class="header">
  <a class="back-btn" href="../index.php">← Main</a>
  <h1>Class Explorer</h1>
  <div class="spacer"></div>
</div>
<?php if ($is_admin): ?>
<div class="section">
  <div class="panel" id="adminPanel">
    <strong>Admin Panel:</strong>
    <label for="adminBuilding">Building</label>
    <select id="adminBuilding"></select>

    <label for="adminTeacher">Teacher</label>
    <select id="adminTeacher"></select>

    <label for="adminClass">Class</label>
    <select id="adminClass"></select>

    <button class="btn" id="adminLoadBtn">Load</button>
    <button class="btn" id="spoofBtn">Spoof Teacher</button>
  </div>
</div>
<?php endif; ?>

<div class="section">
  <div class="panel" id="teacherPanel">
    <label for="teacherClass">Your Classes</label>
    <select id="teacherClass"></select>
    <button class="btn" id="teacherLoadBtn">Load</button>
    <span class="muted">Teacher: <?php echo htmlspecialchars($logged_in_username ?: 'unknown'); ?></span>
  </div>
</div>

<div class="mainwrap">
  <div class="main" id="mainContent">
    <h2 id="classTitle">No class selected</h2>
    <div id="classSub" class="muted">Pick a class above to load devices.</div>
    <div id="summaryBadge"></div>
    <div id="invStatus"></div>
    <div id="missingBanner"></div>

    <!-- ✅ Both toggles wired and working -->
    <label style="display:block;margin:8px 0;">
      <input type="checkbox" id="hideUnctrl" checked> Hide uncontrollable iPads?
    </label>
    <label style="display:block;margin:8px 0;">
      <input type="checkbox" id="hideExtra" checked> Hide Extra Fields?
    </label>

    <div class="tablewrap">
      <table id="resultsTable">
        <thead>
          <tr>
            <th>✓</th>
            <th>Student</th>
            <th class="col-username">Username</th>
            <th>Asset Tag</th>
            <th>Tags</th>
            <th class="col-lastseen">Last Check-In</th>
            <th>Health</th>
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
        <div id="loadingText" class="loading-text">Loading class data...</div>
        <div id="loadingSubtext" class="loading-subtext">Please wait while Musky gathers the latest device data.</div>
        <div id="loadingHint" class="loading-hint">Larger classes may take a little longer while Nora refreshes inventory and health details.</div>
        <div class="loading-progress" aria-hidden="true">
          <div class="loading-progress-bar"></div>
        </div>
      </div>
    </div>
  </div>

  <aside class="sidebar">
    <button id="reportBtn" class="btn-pill orange">📢 Report Problem</button>
    <button id="rebootBtn" class="btn-pill">🔄 Soft Reboot</button>
    <button id="deviceReportBtn" class="btn-pill gray">📋 Device Report</button>
    <button id="reloadBtn" class="btn-pill">🔁 Look Up Again</button>
    <?php if ($is_admin): ?>
      <button id="debugBtn" class="btn-pill gray">🐞 Debug</button>
    <?php endif; ?>

    <div class="legend-box">
      <div>💾⚠️ Disk nearly full</div>
      <div>🔋⚠️ Low battery</div>
      <div>🔇 Muted</div>
      <div>🚨❗ Lost Mode</div>
      <div>🏫 On campus today</div>
    </div>

    <?php if ($is_admin): ?>
    <div class="legend-box" style="margin-top:12px;">
      <strong>⚡ Power Tools</strong><br>
      <button id="powerRebootBtn" class="btn-pill orange" style="margin-top:8px;width:100%;">⚡ POWER REBOOT</button>
      <button id="powerWashBtn" class="btn-pill" style="margin-top:8px;width:100%;background:linear-gradient(180deg,#c62828 0%,#8e0000 100%);">
        ☣️ POWER WASH &amp; WAX
      </button>
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
const ENDPOINTS = {
  filters : './fetch_filters.php',
  devices : './fetch_class_devices.php',
  health  : '../HelperPages/fetch_device_health.php',
  invStart: '../LoanerExplorer/Loaner_INVLookup.php',
  invStat : '../LoanerExplorer/Loaner_INVLookupStatus.php',
  activity: '../api/musky_activity.php',
  log     : './ClassExplorer.php?api=log_unctrl',   // unchanged
  rebootLog: './ClassExplorer.php?api=log_reboot',  // unchanged
  reportLog: './ClassExplorer.php?api=log_report'   // unchanged
};

const UNCTRL = new Set(['stolen','broken','instorage','out2agi']);

const CAN_SPOOF = <?= $is_admin ? 'true' : 'false' ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const SPOOF_AS = CAN_SPOOF ? (new URLSearchParams(window.location.search).get('as') || '') : '';

// State
let currentClassId   = null;
let currentClassName = null;
let lastRows         = [];
let lastMissing      = [];
let lastLoadedAt     = null;
let healthCache      = {};
let isSyncing        = false;

// ✅ NEW: track whether health fetch succeeded (prevents "all uncontrollable" when health is unavailable)
let healthFetchOk    = false;

// DOM
const resultsBody    = document.getElementById('resultsBody');
const classTitle     = document.getElementById('classTitle');
const classSub       = document.getElementById('classSub');
const mainContent    = document.getElementById('mainContent');
const missingBanner  = document.getElementById('missingBanner');
const summaryBadge   = document.getElementById('summaryBadge');
const invStatus      = document.getElementById('invStatus');
const hideUnctrlCB   = document.getElementById('hideUnctrl');
const hideExtraCB    = document.getElementById('hideExtra');
const loadingOverlay = document.getElementById('loadingOverlay');
const loadingText    = document.getElementById('loadingText');
const loadingSubtext = document.getElementById('loadingSubtext');
const loadingHint    = document.getElementById('loadingHint');

const adminBuilding  = document.getElementById('adminBuilding');
const adminTeacher   = document.getElementById('adminTeacher');
const adminClass     = document.getElementById('adminClass');

const LOADING_HINTS = [
  'Larger classes may take a little longer while Nora refreshes inventory and health details.',
  'Musky loads baseline rows first so you see useful data even before deeper sync finishes.',
  'Health checks and inventory refresh can take a moment when a class has many devices.',
  'You are not hung up. Musky is still working through the selected class.'
];

let loadingHintTimer = null;
let loadingHintIndex = 0;
// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
async function fetchJSON(url, options = {}){
  const r = await fetch(url, { credentials:'same-origin', cache:'no-store', ...options });
  const t = await r.text();
  try { return JSON.parse(t); } catch(e){ console.error('Bad JSON', t); return {}; }
}

function trackActivity(actionName, extra = {}, targetSerials = []){
  fetch(ENDPOINTS.activity, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      event_type: 'ACTION',
      action_name: actionName,
      page_path: '/ClassExplorer/ClassExplorer.php',
      target_serials: targetSerials,
      extra
    })
  }).catch(() => {});
}

function timeAgo(d){
  if(!d) return '';
  const s = (Date.now() - new Date(d)) / 1000;
  if (s < 60)   return `${Math.floor(s)}s ago`;
  if (s < 3600) return `${Math.floor(s/60)}m ago`;
  if (s < 86400)return `${Math.floor(s/3600)}h ago`;
  return `${Math.floor(s/86400)}d ago`;
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  })[ch]);
}

function safeHttpUrl(value){
  if (!value) return '';
  try {
    const u = new URL(String(value), window.location.origin);
    return (u.protocol === 'https:' || u.protocol === 'http:') ? u.href : '';
  } catch (e) {
    return '';
  }
}

function renderTags(tags){
  return (!tags || !tags.length) ? '' :
    tags.map(t=>`<span class="tag-pill">${escapeHtml(t)}</span>`).join(' ');
}

function isUnctrl(tags){
  return !!(tags && tags.some(t => UNCTRL.has(String(t).toLowerCase())));
}

function selectedSerials(){
  return Array.from(document.querySelectorAll('.rowcheck:checked'))
    .map(cb => cb.dataset.serial)
    .filter(Boolean);
}

function setHeader(name){
  currentClassName = name || currentClassName || '(unknown class)';
  classTitle.textContent = `Class: ${currentClassName}`;
  classSub.textContent   = lastLoadedAt
    ? `Last refreshed ${timeAgo(lastLoadedAt)} (${new Date(lastLoadedAt).toLocaleTimeString()})`
    : 'Pick a class above to load devices.';
}

function setInvStatus(text, mode){
  if (!invStatus) return;
  invStatus.textContent = text || '';
  if (!text) {
    invStatus.style.color = '#555';
    return;
  }
  if (mode === 'error') invStatus.style.color = '#c62828';
  else if (mode === 'ok') invStatus.style.color = '#2e7d32';
  else invStatus.style.color = '#555';
}

function cycleLoadingHint(){
  if (!loadingHint) return;
  loadingHint.textContent = LOADING_HINTS[loadingHintIndex % LOADING_HINTS.length];
  loadingHintIndex++;
}

function setLoadingState(active, text = 'Loading class data...', detail = ''){
  if (!loadingOverlay) return;
  loadingOverlay.classList.toggle('hidden', !active);
  loadingOverlay.setAttribute('aria-busy', active ? 'true' : 'false');
  if (loadingText) loadingText.textContent = text || 'Loading class data...';
  if (loadingSubtext) loadingSubtext.textContent = detail || 'Please wait while Musky gathers the latest device data.';
  if (active) {
    if (loadingHintTimer === null) {
      cycleLoadingHint();
      loadingHintTimer = window.setInterval(cycleLoadingHint, 2600);
    }
  } else if (loadingHintTimer !== null) {
    window.clearInterval(loadingHintTimer);
    loadingHintTimer = null;
    loadingHintIndex = 0;
    if (loadingHint) loadingHint.textContent = LOADING_HINTS[0];
  }
}

function getDeviceIconMeta(r){
  return window.muskyDeviceIconMeta({
    deviceModel: r.device_model || '',
    modelName: r.model || '',
    iconBase: '../icons'
  });
}

// ---------------------------------------------------------------------------
// Health rendering (FIXED — safe when health unavailable)
// ---------------------------------------------------------------------------
function buildHealth(serial){
  if (!healthFetchOk) {
    return `<span class="health-note">Health unavailable</span>`;
  }

  const h = healthCache[serial] || {};
  const pills = [];

  if (h.flags?.includes('disk_full'))   pills.push('💾⚠️');
  if (h.flags?.includes('low_battery')) pills.push('🔋⚠️');
  if (h.flags?.includes('muted'))       pills.push('🔇');
  if (h.flags?.includes('lost_mode'))   pills.push('🚨❗');
  if (h.flags?.includes('on_campus'))   pills.push('🏫');

  let note = '';
  if (!h.date_last_beat) {
    note = 'Never seen on network';
  } else {
    note = `Last seen ${timeAgo(h.date_last_beat)}`;
  }

  return `
    ${pills.map(p=>`<span class="health-pill">${p}</span>`).join(' ')}
    <br><span class="health-note">${note}</span>
  `;
}

async function fetchHealthForSerials(serials, stageLabel = 'Loading health data'){
  const uniqueSerials = Array.from(new Set((serials || []).filter(Boolean)));
  if (!uniqueSerials.length) return { devices: {}, ok: false };

  const chunkSize = 75;
  const merged = {};
  let ok = false;

  for (let i = 0; i < uniqueSerials.length; i += chunkSize) {
    const chunk = uniqueSerials.slice(i, i + chunkSize);
    const idx = Math.floor(i / chunkSize) + 1;
    const total = Math.ceil(uniqueSerials.length / chunkSize);

    setLoadingState(
      true,
      `${stageLabel} (${idx}/${total})`,
      `Checking ${chunk.length} devices in this pass (${uniqueSerials.length} total serials).`
    );

    try {
      const h = await fetchJSON(
        `${ENDPOINTS.health}?serials=${encodeURIComponent(chunk.join(','))}`
      );

      if (h && typeof h === 'object' && h.devices) {
        Object.assign(merged, h.devices);
        ok = true;
      }
    } catch (e) {
      console.warn('Health fetch chunk failed:', e);
    }
  }

  return { devices: merged, ok };
}

// ---------------------------------------------------------------------------
// Rendering (FIXED uncontrollable logic)
// ---------------------------------------------------------------------------
function renderRows(){
  resultsBody.innerHTML = '';

  const hideUnctrl = hideUnctrlCB.checked;
  const hiddenToLog = [];
  let shownCount = 0;

  for (const r of lastRows){

    const h = healthCache[r.serial] || {};

    // ✅ FIX: only treat as "never seen" if health actually loaded
    const neverSeen = healthFetchOk ? !h.date_last_beat : false;

    const uncontrollable = isUnctrl(r.tags) || neverSeen;

    if (hideUnctrl && uncontrollable) {
      hiddenToLog.push({
        reason: neverSeen ? 'NEVER_SEEN' : 'UNCTRL_TAG',
        serial: r.serial || '',
        student: r.username || '',
        username: r.userid || ''
      });
      continue;
    }

    shownCount++;
    const tr = document.createElement('tr');

    const td0 = document.createElement('td');
    if (uncontrollable) {
      td0.className = 'unctrl-symbol';
      td0.textContent = '🚫';
    } else {
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'rowcheck';
      cb.dataset.serial = r.serial || '';
      td0.appendChild(cb);
    }
    tr.appendChild(td0);

    const tdStudent = document.createElement('td');
    tdStudent.textContent = r.username || '';
    tr.appendChild(tdStudent);

    const tdUser = document.createElement('td');
    tdUser.className = 'col-username';
    tdUser.textContent = r.userid || '';
    tr.appendChild(tdUser);

    const tdAsset = document.createElement('td');
    const assetWrap = document.createElement('div');
    assetWrap.className = 'asset-tag-wrap';

    const iconMeta = getDeviceIconMeta(r);
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

    const tdTags = document.createElement('td');
    tdTags.innerHTML = renderTags(r.tags);
    tr.appendChild(tdTags);

    const tdSeen = document.createElement('td');
    tdSeen.className = 'col-lastseen';
    if (r.last_seen) {
      tdSeen.textContent = timeAgo(r.last_seen);
    }
    tr.appendChild(tdSeen);

    const tdHealth = document.createElement('td');
    tdHealth.innerHTML = buildHealth(r.serial);

    const links = [];
    const mosyleHref = safeHttpUrl(r.open_direct_device_link);
    if (mosyleHref) {
      links.push(
        `<a href="${escapeHtml(mosyleHref)}" target="_blank" rel="noopener" class="health-text-link">📎 Mosyle</a>`
      );
    }
    if (r.asset_tag) {
      links.push(
        `<a href="../DeviceManager/index.php?assettag=${encodeURIComponent(r.asset_tag)}" target="_blank" rel="noopener" class="health-text-link">⚡︎ Details</a>`
      );
    }
    if (links.length) {
      tdHealth.innerHTML += `<div class="health-links">${links.join(' ')}</div>`;
    }

    tr.appendChild(tdHealth);

    resultsBody.appendChild(tr);
  }

  if (hideExtraCB.checked) {
    document.body.classList.add('hide-extra');
  } else {
    document.body.classList.remove('hide-extra');
  }

  const hiddenCount = Math.max(0, lastRows.length - shownCount);
  summaryBadge.textContent =
    `${shownCount}/${lastRows.length} shown` +
    ` — Hidden uncontrollable: ${hiddenCount}` +
    ` — Missing device: ${lastMissing.length}`;
}
// ---------------------------------------------------------------------------
// Nora sync helpers
// ---------------------------------------------------------------------------
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
        'INV_LOOKUP complete. Refreshing class results...',
        'Musky is pulling the freshest device data back into the class table.'
      );
      setInvStatus(`NORA inventory synced (Errand #${errandId}).`, 'ok');
      return true;
    }

    if (['FAILED','REJECTED','CANCELLED','UNKNOWN'].includes(up)) {
      setLoadingState(
        true,
        `INV_LOOKUP ended with status: ${status}`,
        'Using the best class data currently available.'
      );
      setInvStatus(`INV_LOOKUP ended with status "${status}".`, 'error');
      return false;
    }

    setLoadingState(
      true,
      `Waiting for INV_LOOKUP... ${status}`,
      'Nora is still refreshing inventory details for the class.'
    );
    setInvStatus(`INV_LOOKUP status: ${status}...`, 'info');
    await new Promise(r => setTimeout(r, interval));
  }
}

// ---------------------------------------------------------------------------
// Loaders
// ---------------------------------------------------------------------------
async function loadClassDevices(classId, className=''){
  if (!classId || isSyncing) return;
  isSyncing = true;
  currentClassId   = classId;
  currentClassName = className || currentClassName || '(unknown class)';
  trackActivity('LOAD_CLASS', { class_id: classId, class_name: currentClassName });
  setHeader(className || currentClassName);

  try {
    setLoadingState(
      true,
      `Loading ${currentClassName}...`,
      'Musky is gathering baseline rows before deeper sync work begins.'
    );
    setInvStatus('Loading baseline data...', 'info');

    const params = new URLSearchParams({ class_id: classId });
    if (SPOOF_AS) params.set('as', SPOOF_AS);

    const j = await fetchJSON(`${ENDPOINTS.devices}?${params.toString()}`);
    lastRows     = j.rows || [];
    lastMissing  = j.students_missing || [];
    lastLoadedAt = new Date().toISOString();

    if (missingBanner) {
      if (lastMissing.length) {
        missingBanner.style.display = 'block';
        missingBanner.textContent = `${lastMissing.length} student(s) in this roster do not currently have a matched device.`;
      } else {
        missingBanner.style.display = 'none';
        missingBanner.textContent = '';
      }
    }

    const serials = Array.from(new Set(lastRows.map(r => r.serial).filter(Boolean)));
    healthCache   = {};
    healthFetchOk = false;

    if (!serials.length) {
      renderRows();
      setHeader(currentClassName);
      setInvStatus('No devices found for this class.', 'info');
      return;
    }

    const baselineHealth = await fetchHealthForSerials(serials, 'Loading baseline health');
    healthCache   = baselineHealth.devices;
    healthFetchOk = baselineHealth.ok;

    renderRows();
    setHeader(currentClassName);
    setInvStatus('Baseline data + health loaded. Attempting NORA INV_LOOKUP...', 'info');

    const errandId = await startINVLookupForSerials(serials);
    if (!errandId) {
      setInvStatus('Using latest health data; INV_LOOKUP did not start.', 'error');
      return;
    }

    const ok = await waitForINVLookupComplete(errandId);

    setLoadingState(
      true,
      `Refreshing ${currentClassName}...`,
      'Pulling the updated class roster after Nora inventory sync.'
    );

    const fresh = await fetchJSON(`${ENDPOINTS.devices}?${params.toString()}`);
    lastRows     = fresh.rows || [];
    lastMissing  = fresh.students_missing || [];
    lastLoadedAt = new Date().toISOString();

    if (missingBanner) {
      if (lastMissing.length) {
        missingBanner.style.display = 'block';
        missingBanner.textContent = `${lastMissing.length} student(s) in this roster do not currently have a matched device.`;
      } else {
        missingBanner.style.display = 'none';
        missingBanner.textContent = '';
      }
    }

    const freshSerials = Array.from(new Set(lastRows.map(r => r.serial).filter(Boolean)));
    if (freshSerials.length) {
      const refreshedHealth = await fetchHealthForSerials(freshSerials, 'Refreshing health data');
      if (refreshedHealth.ok) {
        healthCache   = refreshedHealth.devices;
        healthFetchOk = true;
      }
    }

    renderRows();
    setHeader(currentClassName);

    if (ok) {
      setInvStatus(`NORA inventory synced ${new Date(lastLoadedAt).toLocaleTimeString()}.`, 'ok');
    } else {
      setInvStatus('Showing latest data available; INV_LOOKUP did not complete cleanly.', 'error');
    }
  } catch (e) {
    console.error('loadClassDevices exception', e);
    setInvStatus('Error loading class data; see console logs.', 'error');
  } finally {
    setLoadingState(false);
    isSyncing = false;
  }
}

// ---------------------------------------------------------------------------
// Admin chain
// ---------------------------------------------------------------------------
async function loadBuildings(){
  if (!adminBuilding) return;
  adminBuilding.innerHTML = '';
  adminBuilding.appendChild(new Option('(Select building)', ''));
  const j = await fetchJSON(ENDPOINTS.filters + '?mode=buildings');
  (j.buildings || []).forEach(b => adminBuilding.appendChild(new Option(b, b)));
}

async function loadTeachers(){
  if (!adminTeacher) return;
  const b = adminBuilding?.value || '';
  adminTeacher.innerHTML = '';
  if (!b) return;
  adminTeacher.appendChild(new Option('(Select teacher)', ''));
  const j = await fetchJSON(`${ENDPOINTS.filters}?mode=teachers&building=${encodeURIComponent(b)}`);
  (j.teachers || []).forEach(t => {
    adminTeacher.appendChild(new Option(`${t.teacher_name} (${t.teacher_username})`, t.teacher_username));
  });
}

async function loadClassesAdmin(){
  if (!adminClass) return;
  const b = adminBuilding?.value || '';
  const t = adminTeacher?.value  || '';
  adminClass.innerHTML = '';
  if (!b || !t) return;
  const j = await fetchJSON(`${ENDPOINTS.filters}?mode=classes&building=${encodeURIComponent(b)}&teacher=${encodeURIComponent(t)}`);
  (j.classes || []).forEach(c =>
    adminClass.appendChild(new Option(`${c.class_name} — ${c.location}`, c.class_id))
  );
}

// ---------------------------------------------------------------------------
// Teacher loader (spoof-aware)
// ---------------------------------------------------------------------------
async function loadTeacherClasses(){
  const sel = document.getElementById('teacherClass');
  if (!sel) return;
  sel.innerHTML = '';

  let url = ENDPOINTS.filters + '?mode=teacher';
  if (SPOOF_AS) {
    url += '&as=' + encodeURIComponent(SPOOF_AS);
  }

  const j = await fetchJSON(url);
  (j.classes || []).forEach(c =>
    sel.appendChild(new Option(`${c.class_name} — ${c.location}`, c.class_id))
  );

  if (!sel.options.length) {
    sel.appendChild(new Option('(no classes)', ''));
  }
}
// ---------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------

// Toggles
hideUnctrlCB.addEventListener('change', renderRows);
hideExtraCB.addEventListener('change', renderRows);

// Refresh
document.getElementById('reloadBtn')?.addEventListener('click', ()=>{
  if (!currentClassId) return;
  trackActivity('RELOAD_CLASS', { class_id: currentClassId, class_name: currentClassName });
  loadClassDevices(currentClassId, currentClassName);
});

// Teacher load
document.getElementById('teacherLoadBtn')?.addEventListener('click', ()=>{
  const sel = document.getElementById('teacherClass');
  const opt = sel?.selectedOptions?.[0];
  if (!opt || !opt.value) return;
  const name = (opt.textContent || '').split(' — ')[0];
  loadClassDevices(opt.value, name);
});

// Admin chain
adminBuilding?.addEventListener('change', async()=>{
  await loadTeachers();
  await loadClassesAdmin();
});
adminTeacher?.addEventListener('change', loadClassesAdmin);

document.getElementById('adminLoadBtn')?.addEventListener('click', ()=>{
  const opt = adminClass?.selectedOptions?.[0];
  if (!opt || !opt.value) return;
  const name = (opt.textContent || '').split(' — ')[0];
  loadClassDevices(opt.value, name);
});

document.getElementById('spoofBtn')?.addEventListener('click', ()=>{
  const teacher = adminTeacher?.value || '';
  if (!teacher) {
    alert('Pick a teacher first.');
    return;
  }
  const teacherEmailDomain = <?= json_encode(musky_identity_teacher_email_domain(), JSON_UNESCAPED_SLASHES) ?>;
  const u = new URL(window.location.href);
  u.searchParams.set('as', `${teacher}@${teacherEmailDomain}`);
  window.location.href = u.toString();
});

// ---------------------------------------------------------------------------
// Bulk Actions
// ---------------------------------------------------------------------------

document.getElementById('rebootBtn')?.addEventListener('click', ()=>{
  const picks = selectedSerials();
  if (!picks.length) { alert('Select at least one device.'); return; }
  trackActivity('SOFT_REBOOT', { class_id: currentClassId, class_name: currentClassName }, picks);
  window.open(
    `../HelperPages/Musky_Reboot_iPad.php?serial=${encodeURIComponent(picks.join(','))}`,
    '_blank'
  );
});

document.getElementById('reportBtn')?.addEventListener('click', ()=>{
  const picks = selectedSerials();
  if (!picks.length) { alert('Select at least one device.'); return; }
  trackActivity('REPORT_PROBLEM', { class_id: currentClassId, class_name: currentClassName }, picks);
  window.open(
    `../HelperPages/MuskyMakeATicket_iPad.php?serial=${encodeURIComponent(picks.join(','))}`,
    '_blank'
  );
});

document.getElementById('deviceReportBtn')?.addEventListener('click', ()=>{
  const picks = selectedSerials();
  if (!picks.length) { alert('Select at least one device.'); return; }
  trackActivity('DEVICE_REPORT', { class_id: currentClassId, class_name: currentClassName }, picks);
  picks.forEach(s =>
    window.open(`../DeviceManager/device_report.php?serial=${encodeURIComponent(s)}`, '_blank')
  );
});

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------
(async function init(){
  await loadTeacherClasses();
  if (adminBuilding) await loadBuildings();

  if (hideExtraCB.checked) {
    document.body.classList.add('hide-extra');
  }
})();
</script>
</body>
</html>
