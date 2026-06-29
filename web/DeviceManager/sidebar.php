<?php
// ======================================================================
// MUSKY - Shared Sidebar for DeviceManager Tools (sidebar.php)
// ----------------------------------------------------------------------
// PURPOSE
//   This sidebar is included by MyDevices.php and provides:
//     • Normal user actions (ticket, reboot, report)
//     • Admin-only tools (search other user, power actions)
//
// KEY BEHAVIOR
//   • NOTHING should submit/reload the page.
//   • Admin search supports:
//       - direct email search (if contains '@')
//       - username fallback search (if no '@'):
//            username@configured-domain-1 → if fail → username@configured-domain-2
//
// NOTES
//   • This file intentionally does not talk to SQLite.
//   • It reads $is_admin if the parent file provides it.
//   • If parent does NOT provide $is_admin, we derive it from session.
// ======================================================================

if (!function_exists('musky_sidebar_action_url_prefixes')) {
    function musky_sidebar_action_url_prefixes(): array
    {
        return [
            'ticket'       => '../HelperPages/MuskyMakeATicket_iPad.php?serial=',
            'soft_reboot'  => '../HelperPages/Musky_Reboot_iPad.php?serial=',
            'power_reboot' => '../HelperPages/Musky_Reboot_iPad.php?serial=',
            'power_wash'   => '../HelperPages/Musky_Wipe_iPad.php?serial=',
            'device_report'=> 'device_report.php?serial=',
        ];
    }
}

if (!function_exists('musky_sidebar_action_popup_specs')) {
    function musky_sidebar_action_popup_specs(): array
    {
        return [
            'helper' => 'width=900,height=800',
            'report' => 'width=900,height=800',
            'device_report' => 'width=900,height=800',
        ];
    }
}

// Allow other pages to include sidebar.php as a helper library without
// rendering sidebar markup or JavaScript.
if (defined('MUSKY_SIDEBAR_ACTIONS_ONLY') && MUSKY_SIDEBAR_ACTIONS_ONLY) {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If parent didn't define $is_admin, derive it from session allowed_tools.
if (!isset($is_admin)) {
    $allowed_raw = $_SESSION['musky_user']['allowed_tools'] ?? '';
    $allowed     = array_filter(array_map('trim', explode(',', $allowed_raw)));
    $is_admin    = in_array('DEVICE_MANAGER', $allowed, true)
                || in_array('ALL_TOOLS', $allowed, true);
}
?>

<div class="sidebar">

  <h3>Actions</h3>

  <!--
    IMPORTANT:
      We NEVER want this form to submit (Enter key / browser default),
      because that causes a full page reload and loses search context.
  -->
  <form method="post" onsubmit="return false;">
    <input type="hidden" id="selected_device" name="selected_device">

    <button type="button" class="action-button" id="btn_nowhere1"
            onclick="openMakeTicket()" disabled>
      Report Problem<br>with THIS DEVICE
    </button>

    <button type="button" class="action-button" id="btn_reboot"
            onclick="attemptSoftReboot()" disabled>
      🔁<br>Soft Reboot Device
    </button>

    <button type="button" class="action-button" id="btn_device_report"
            onclick="openDeviceReport()" disabled>
      📊<br>Device Report
    </button>

    <div class="action-row">
      <button type="button" class="action-button" id="btn_lookup"
              onclick="refreshDevices()">Look Up Again</button>

      <button type="button" class="action-button"
              onclick="handleDebugClick()">DEBUG</button>
    </div>

    <?php if ($is_admin): ?>
      <hr style="margin:16px 0;">

      <!-- HEADER + MUSKY PAW IMAGE -->
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <h3 style="margin:0;padding:0;">Power Tools</h3>
        <img src="../MuskyPaw.png"
             alt="Musky Paw"
             style="height:30px;width:auto;display:block;">
      </div>

      <!-- ADMIN USER SEARCH -->
      <label for="admin-search-input"
             style="display:block;font-size:0.9em;margin-bottom:4px;">
        Search another user:
      </label>

      <!--
        NOTE:
          - Enter key on this input should trigger a search.
          - It must NOT submit the form / reload the page.
      -->
      <input type="text" id="admin-search-input"
             placeholder="username or email"
             autocomplete="off"
             style="width:100%;box-sizing:border-box;margin-bottom:6px;">

      <button type="button" class="action-button"
              style="width:100%;margin-bottom:8px;"
              onclick="searchUserDevices()">
        🔍 Search User Devices
      </button>

      <!-- SINGLE-DEVICE POWER ACTIONS -->
      <button type="button" class="action-button power-action"
              id="btn_power_reboot"
              onclick="powerReboot()" disabled>
        ⚡ Power Reboot
      </button>

      <button type="button" class="action-button power-action"
              id="btn_power_wash"
              onclick="powerWash()" disabled
              style="margin-top:6px;">
        ☣ Power Wash &amp; Wax
      </button>
    <?php endif; ?>
  </form>
</div>

<div id="debugPane" style="display:none;"></div>

<script>
// ======================================================================
// sidebar.php JS
// ======================================================================

const SIDEBAR_ACTION_PREFIX = <?= json_encode(musky_sidebar_action_url_prefixes(), JSON_UNESCAPED_SLASHES) ?>;
const SIDEBAR_POPUP_SPECS = <?= json_encode(musky_sidebar_action_popup_specs(), JSON_UNESCAPED_SLASHES) ?>;

// Server-side admin flag is passed in as a literal true/false here.
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;

// Last selected device JSON blob (set by clicking a device block)
let lastDeviceJSON = {};

// Timer display vars
let secondsElapsed = 0;
let timerInt = null;

// Always correct base path (works even if query string changes)
function getBasePath() {
  return window.location.pathname;
}

// ------------------------------------------------------------
// Timer
// ------------------------------------------------------------
function updateTimer() {
  const m = Math.floor(secondsElapsed / 60);
  const s = secondsElapsed % 60;
  const t = document.getElementById('refresh-timer');
  if (t) t.textContent = `Last updated: ${m}m ${s}s ago`;
}
function startTimer() {
  secondsElapsed = 0;
  updateTimer();
  clearInterval(timerInt);
  timerInt = setInterval(() => { secondsElapsed++; updateTimer(); }, 1000);
}

// ------------------------------------------------------------
// Button enabling/disabling
// ------------------------------------------------------------
function toggleActionButtons(enable) {
  ["btn_nowhere1","btn_reboot","btn_device_report"].forEach(id => {
    const b = document.getElementById(id);
    if (b) b.disabled = !enable;
  });

  if (IS_ADMIN) {
    document.querySelectorAll('.power-action').forEach(b => b.disabled = !enable);
  }
}

// ------------------------------------------------------------
// Device selection (called from MyDevices.php device blocks)
// ------------------------------------------------------------
function selectDevice(el) {
  // Selected styling
  document.querySelectorAll('.device-block').forEach(b => b.classList.remove('selected'));
  el.classList.add('selected');

  // If MyDevices.php implements fading, it may apply .faded elsewhere;
  // we won't manage it here unless you ask.

  // Parse JSON payload stored in <script class="device-data">
  const tag = el.querySelector('.device-data');
  lastDeviceJSON = tag ? JSON.parse(tag.textContent) : {};

  // Store in hidden input for future expansions
  const hidden = document.getElementById('selected_device');
  if (hidden) hidden.value = JSON.stringify(lastDeviceJSON);

  toggleActionButtons(true);
  startTimer();
}

// ------------------------------------------------------------
// Debug toggle
// ------------------------------------------------------------
function handleDebugClick() {
  const p = document.getElementById('debugPane');
  if (!p) return;

  if (p.style.display === 'block') {
    p.style.display = 'none';
    p.innerHTML = '';
  } else {
    p.style.display = 'block';
    p.innerHTML = `<pre>${JSON.stringify(lastDeviceJSON, null, 2)}</pre>`;
  }
}

// ------------------------------------------------------------
// AJAX refresh for *current view* (self devices)
//   - This resets results back to "my devices"
// ------------------------------------------------------------
async function refreshDevices() {
  try {
    const res = await fetch(getBasePath() + '?ajax=1', { cache: 'no-store' });
    const raw = await res.text();

    let data;
    try { data = JSON.parse(raw); }
    catch { alert("Refresh failed:\n" + raw); return; }

    const list = document.getElementById('deviceList');
    if (list) list.innerHTML = (data.devices || []).map(x => x.html).join("");

    // Reset state
    lastDeviceJSON = {};
    toggleActionButtons(false);

    const lu = document.getElementById('last-updated-text');
    if (lu) lu.textContent = "Last updated: just now (refreshed)";

    // Reset header to self view (if you want this smarter later, we can store "self name" once)
    // We do NOT try to reconstruct the original H1 here; MyDevices.php already renders it on load.

    startTimer();
  } catch (e) {
    alert("Refresh failed: " + e.message);
  }
}

// ------------------------------------------------------------
// Standard soft reboot (non-admin action)
// ------------------------------------------------------------
function attemptSoftReboot() {
  if (!lastDeviceJSON.serial_number) return alert("Select a device first.");
  window.open(
    `${SIDEBAR_ACTION_PREFIX.soft_reboot}${encodeURIComponent(lastDeviceJSON.serial_number)}`,
    "_blank", SIDEBAR_POPUP_SPECS.helper
  );
}

// ------------------------------------------------------------
// Device report
// ------------------------------------------------------------
function openDeviceReport() {
  if (!lastDeviceJSON.serial_number) return alert("Select a device first.");
  window.open(
    `${SIDEBAR_ACTION_PREFIX.device_report}${encodeURIComponent(lastDeviceJSON.serial_number)}`,
    "_blank", SIDEBAR_POPUP_SPECS.device_report
  );
}

// ------------------------------------------------------------
// Ticket creation
// ------------------------------------------------------------
function openMakeTicket() {
  if (!lastDeviceJSON.serial_number) return alert("Select a device first.");
  window.open(
    `${SIDEBAR_ACTION_PREFIX.ticket}${encodeURIComponent(lastDeviceJSON.serial_number)}`,
    "_blank", SIDEBAR_POPUP_SPECS.report
  );
}

// ======================================================================
// ADMIN SEARCH (SMART FALLBACK)
// ======================================================================
//
// REQUIREMENT (your spec):
//   If input contains '@' → treat as email and try once.
//   If no '@' → treat as username, and try:
//       username@configured-domain-1 → if fails → username@configured-domain-2
//
// We intentionally do this in the client so MyDevices.php does not need to
// guess domains or do multiple lookups.
// ======================================================================

async function searchUserDevices() {
  if (!IS_ADMIN) return;

  const input = document.getElementById('admin-search-input');
  const termRaw = (input ? input.value : '').trim();
  if (!termRaw) return alert("Enter a username or email.");

  // Build candidate search terms (sent to MyDevices.php)
  const term = termRaw.toLowerCase();

  let candidates = [];
  if (term.includes('@')) {
    // Already an email
    candidates = [term];
  } else {
    // Username fallback path
    const usernameLookupDomains = <?= json_encode(array_values(musky_identity_username_lookup_domains()), JSON_UNESCAPED_SLASHES) ?>;
    candidates = usernameLookupDomains.map(domain => `${term}@${domain}`);
  }

  // Try candidates in order, stop on first success.
  for (let i = 0; i < candidates.length; i++) {
    const candidate = candidates[i];

    try {
      const url = getBasePath() + '?api=search_user&q=' + encodeURIComponent(candidate);
      const res = await fetch(url, { cache: 'no-store' });
      const raw = await res.text();

      let data;
      try { data = JSON.parse(raw); }
      catch {
        // If MyDevices.php returned HTML, show it once (first attempt)
        alert("Search failed:\n" + raw);
        return;
      }

      if (!data.ok) {
        // Not successful → if we have another candidate, continue silently
        if (i < candidates.length - 1) continue;

        // Last candidate failed → show final error
        alert("Search error: " + (data.error || "Unknown error."));
        return;
      }

      // SUCCESS: Render device cards
      const list = document.getElementById('deviceList');
      if (list) list.innerHTML = (data.devices || []).map(x => x.html).join("");

      // Update header so it's obvious we're viewing search results
      const h1 = document.querySelector('.main h1');
      if (h1) h1.textContent = `📱 Devices assigned to ${data.full_name} (Search: ${data.email})`;

      const lu = document.getElementById('last-updated-text');
      if (lu) lu.textContent = 'Last updated: just now (via search)';

      // Reset selection state
      lastDeviceJSON = {};
      toggleActionButtons(false);
      startTimer();

      // Done
      return;

    } catch (e) {
      // Network or fetch error; if we have another candidate, try it.
      if (i < candidates.length - 1) continue;

      // Otherwise report error
      alert("Search failed: " + e.message);
      return;
    }
  }
}

// ------------------------------------------------------------
// Admin power tools
// ------------------------------------------------------------
function powerReboot() {
  if (!lastDeviceJSON.serial_number) return alert("Select a device first.");
  window.open(
    `${SIDEBAR_ACTION_PREFIX.power_reboot}${encodeURIComponent(lastDeviceJSON.serial_number)}`,
    "_blank", SIDEBAR_POPUP_SPECS.helper
  );
}

function powerWash() {
  if (!lastDeviceJSON.serial_number) return alert("Select a device first.");
  window.open(
    `${SIDEBAR_ACTION_PREFIX.power_wash}${encodeURIComponent(lastDeviceJSON.serial_number)}`,
    "_blank", SIDEBAR_POPUP_SPECS.helper
  );
}

// ------------------------------------------------------------
// Prevent Enter from reloading the page (admin search UX)
//   - Enter inside the admin input should run the search.
//   - Enter anywhere else should not submit the form.
// ------------------------------------------------------------
document.addEventListener("DOMContentLoaded", () => {
  toggleActionButtons(false);
  startTimer();

  const input = document.getElementById('admin-search-input');
  if (input) {
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        searchUserDevices();
      }
    });
  }
});
</script>
