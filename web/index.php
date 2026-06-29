<?php
session_start();
require_once 'bootstrap.php';
require_once 'check_access.php';
require_once __DIR__ . '/admin/_admin_policy.php';
require_once __DIR__ . '/../Functions/LoggedInUserPrefs.php';
require_once __DIR__ . '/../Functions/MuskyActivityLog.php';
require_once __DIR__ . '/../Functions/MuskyCsrf.php';
require_once __DIR__ . '/../Functions/MuskyUserMariaSync.php';
require_once __DIR__ . '/../Functions/MuskyConfig.php';

// -----------------------------------------------------------------------------
// Load unified user prefs (THE NEW WAY)
// -----------------------------------------------------------------------------
$prefs = musky_get_logged_in_user_prefs();

$email       = $prefs['email'];
$firstName   = $prefs['first_name'];
$name        = $prefs['display_name'];
$pic         = $prefs['photo_url'];
$theme       = $prefs['theme'];
$role        = $prefs['role'] ?? 'unknown';
$building    = $prefs['building'] ?? 'unknown';
$allowed     = $prefs['allowed_tools'] ?? [];
$hubThemeCsrfToken = musky_csrf_token('musky_hub_theme');
$lastMuskyLogin = $email ? musky_activity_get_last_login($email) : null;
$trackedUsageSeconds = $email ? musky_activity_get_user_tracked_active_seconds($email, 300) : 0;

function musky_format_sidebar_datetime(?string $value): string {
    if (!$value) return 'Not tracked yet';
    try {
        $dt = new DateTime($value);
        return $dt->format('M j, Y g:i A');
    } catch (Throwable $e) {
        return $value;
    }
}

function musky_format_duration_short(int $seconds): string {
    $seconds = max(0, $seconds);
    if ($seconds < 60) return $seconds . ' sec';

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return $minutes . ' min';
}

// -----------------------------------------------------------------------------
// Tool Permissions
// -----------------------------------------------------------------------------
$panda_enabled   = musky_config_truthy(musky_active_config_value('PANDA_CHARGES_ENABLED'));
$can_panda       = $panda_enabled && (
    in_array('PANDA_CASHIER', $allowed, true) ||
    in_array('PANDA_VIEWER', $allowed, true) ||
    in_array('PANDA_MANAGER', $allowed, true) ||
    in_array('PANDA_ADMIN', $allowed, true) ||
    in_array('ALL_TOOLS', $allowed, true)
);
$can_loaners     = in_array('LOANER_MGMT', $allowed)     || in_array('ALL_TOOLS', $allowed);
$can_devicemgr   = in_array('DEVICE_MANAGER', $allowed)  || in_array('ALL_TOOLS', $allowed);
$can_adminpanel  = musky_admin_equivalent_from_allowed($allowed, (string)$role);
// Strict gate for pages that hard-require ADMIN_PANEL (or ALL_TOOLS).
$can_upperadmin  = in_array('ADMIN_PANEL', $allowed, true) || in_array('ALL_TOOLS', $allowed, true);
$can_classmgr    = in_array('CLASS_MANAGER', $allowed)   || in_array('ALL_TOOLS', $allowed);
$can_technology_marsh = $can_devicemgr || $can_adminpanel;

// -----------------------------------------------------------------------------
// Private/GSD-only hide for Burrow / Technology Marsh launcher.
// -----------------------------------------------------------------------------
// Why this exists:
// - Burrows is currently treated as private/GSD-only work.
// - We do NOT want the launcher visible to normal users yet.
// - This keeps the access logic intact, but suppresses hub visibility globally.
//
// Re-enable plan:
// - Flip this flag to `false` when you are ready to announce the tool.
// -----------------------------------------------------------------------------
$hide_technology_marsh_from_hub = true;
$show_technology_marsh_launcher = $can_technology_marsh && !$hide_technology_marsh_from_hub;

// -----------------------------------------------------------------------------
// Handle Theme Updates (WRITE BACK to musky_users)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    if (!musky_csrf_is_valid('musky_hub_theme', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
        exit;
    }

    $newTheme = trim((string)$_POST['theme']);
    $db = musky_user_store_primary_pdo();

    if (!$db instanceof PDO || $email === '') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'user_store_unavailable']);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE musky_users SET theme = ? WHERE email = ?");
        $stmt->execute([$newTheme, $email]);

        if (isset($_SESSION['musky_user']) && is_array($_SESSION['musky_user'])) {
            $_SESSION['musky_user']['theme'] = $newTheme;
        }
        $GLOBALS['_musky_user_pref_cache'] = null;

        // Keep the opposite bridge warm when a legacy fallback store exists.
        if (musky_user_store_backend_name($db) === 'mysql') {
            musky_user_sqlite_upsert_user_row([
                'email' => $email,
                'theme' => $newTheme,
            ]);
        } else {
            musky_user_mysql_sync_by_email($email, [
                'email' => $email,
                'theme' => $newTheme,
            ]);
        }

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'theme_update_failed']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Musky Hub</title>
  <link rel="stylesheet" href="theme.css?theme=<?= htmlspecialchars($theme) ?>" />

  <style>
    :root {
      --hub-panel-bg: rgba(255,255,255,0.78);
      --hub-panel-border: rgba(0,0,0,0.10);
      --hub-panel-shadow: 0 18px 40px rgba(0,0,0,0.10);
      --hub-accent: #4CAF50;
      --hub-heading-accent: #2e7d32;
      --legacy-divider-color: #4CAF50;
      --legacy-heading-color: #1f4f22;
      --hub-accent-soft: rgba(76, 175, 80, 0.16);
      --hub-muted: rgba(0,0,0,0.65);
      --hub-hero-a: rgba(76, 175, 80, 0.16);
      --hub-hero-b: rgba(255,255,255,0.60);
    }
    body.light-mode {
      --hub-panel-bg: rgba(255,255,255,0.88);
      --hub-panel-border: rgba(60,80,60,0.12);
      --hub-panel-shadow: 0 20px 44px rgba(54, 79, 54, 0.10);
      --hub-accent: #2e7d32;
      --hub-heading-accent: #2e7d32;
      --legacy-divider-color: #4CAF50;
      --legacy-heading-color: #1f4f22;
      --hub-accent-soft: rgba(46, 125, 50, 0.14);
      --hub-muted: rgba(0,0,0,0.70);
      --hub-hero-a: rgba(76, 175, 80, 0.15);
      --hub-hero-b: rgba(255,255,255,0.72);
    }
    body.dark-mode {
      --hub-panel-bg: rgba(28,28,28,0.92);
      --hub-panel-border: rgba(255,255,255,0.08);
      --hub-panel-shadow: 0 20px 48px rgba(0,0,0,0.35);
      --hub-accent: #7ccf84;
      --hub-heading-accent: #bfe7c4;
      --legacy-divider-color: #7ccf84;
      --legacy-heading-color: #d8f2dc;
      --hub-accent-soft: rgba(124, 207, 132, 0.14);
      --hub-muted: rgba(255,255,255,0.72);
      --hub-hero-a: rgba(76, 175, 80, 0.12);
      --hub-hero-b: rgba(255,255,255,0.03);
    }
    body.musky-mode {
      --hub-panel-bg: rgba(0, 40, 85, 0.78);
      --hub-panel-border: rgba(255, 136, 0, 0.24);
      --hub-panel-shadow: 0 22px 50px rgba(0, 18, 38, 0.34);
      --hub-accent: #ff8800;
      --hub-heading-accent: #f4f7fb;
      --legacy-divider-color: #4CAF50;
      --legacy-heading-color: #f4f7fb;
      --hub-accent-soft: rgba(255, 136, 0, 0.16);
      --hub-muted: rgba(255,255,255,0.78);
      --hub-hero-a: rgba(255, 136, 0, 0.14);
      --hub-hero-b: rgba(255,255,255,0.04);
    }
    body.gator-time-mode {
      --hub-panel-bg: rgba(16,16,16,0.92);
      --hub-panel-border: rgba(197,179,88,0.20);
      --hub-panel-shadow: 0 22px 50px rgba(0,0,0,0.40);
      --hub-accent: #C5B358;
      --hub-heading-accent: #efe5af;
      --legacy-divider-color: #C5B358;
      --legacy-heading-color: #efe5af;
      --hub-accent-soft: rgba(197, 179, 88, 0.15);
      --hub-muted: rgba(255,255,255,0.70);
      --hub-hero-a: rgba(197, 179, 88, 0.12);
      --hub-hero-b: rgba(255,255,255,0.02);
    }
    body {
      margin: 0;
      font-family: sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .content {
      display: flex;
      flex: 1;
    }
    .main {
      flex: 1;
      padding: 20px;
      display: flex;
      flex-direction: column;
    }
    .main-shell {
      width: min(100%, 1120px);
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 22px;
    }
    .layout-old {
      width: min(100%, 1120px);
      margin: 0 auto;
    }
    .old-layout-details {
      display: flex;
      align-items: center;
      gap: 20px;
      margin-bottom: 1.5rem;
    }
    .layout-old h1,
    .layout-old h2 {
      color: var(--legacy-heading-color);
    }
    body[data-layout="old"] .layout-new {
      display: none;
    }
    body[data-layout="new"] .layout-old {
      display: none;
    }
    .hub-hero,
    .section-panel {
      position: relative;
      overflow: hidden;
      background: var(--hub-panel-bg);
      border: 1px solid var(--hub-panel-border);
      box-shadow: var(--hub-panel-shadow);
      border-radius: 24px;
      backdrop-filter: blur(10px);
    }
    .hub-hero {
      padding: 28px 30px;
      background-image:
        radial-gradient(circle at top left, var(--hub-hero-a), transparent 48%),
        linear-gradient(135deg, var(--hub-hero-b), transparent 70%);
    }
    .hub-hero::after,
    .section-panel::after {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: linear-gradient(180deg, rgba(255,255,255,0.08), transparent 30%);
      opacity: 0.8;
    }
    .hero-topline {
      font-size: 0.82rem;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--hub-heading-accent);
      margin-bottom: 10px;
    }
    .hero-heading {
      margin: 0;
      font-size: clamp(2rem, 3vw, 2.8rem);
      line-height: 1.02;
    }
    .hero-subtitle {
      margin: 12px 0 0 0;
      max-width: 60ch;
      color: var(--hub-muted);
      font-size: 1rem;
      line-height: 1.45;
    }
    .hero-profile {
      display: flex;
      align-items: center;
      gap: 18px;
      margin-top: 22px;
      padding-top: 20px;
      border-top: 1px solid var(--hub-panel-border);
    }
    .profile-photo {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      object-fit: cover;
      border: 3px solid rgba(255,255,255,0.32);
      box-shadow: 0 10px 24px rgba(0,0,0,0.14);
      flex: 0 0 auto;
    }
    .hero-details {
      min-width: 0;
      flex: 1;
    }
    .section-panel {
      padding: 22px 24px 24px;
    }
    .section-kicker {
      font-size: 0.76rem;
      font-weight: 800;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--hub-heading-accent);
      margin-bottom: 8px;
    }
    .section-panel h1,
    .section-panel h2 {
      margin: 0;
      font-size: clamp(1.4rem, 2.2vw, 1.8rem);
      line-height: 1.08;
    }
    .section-note {
      margin: 10px 0 18px 0;
      color: var(--hub-muted);
      font-size: 0.96rem;
      line-height: 1.45;
      max-width: 62ch;
    }
    .tool-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 12px;
    }
    .sidebar {
      width: 208px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
      position: relative;
    }
    .sidebar-top {
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }
    .sidebar-select-group {
      width: 100%;
    }
    .sidebar-select-group label {
      display: block;
      margin-bottom: 6px;
      font-size: 0.9rem;
      font-weight: 700;
    }
    .sidebar-select-group select {
      width: 100%;
      box-sizing: border-box;
    }
    .details-grid {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 4px 14px;
      align-items: start;
    }
    .details-grid .label {
      font-weight: 700;
    }
    .details-grid .value {
      min-width: 0;
    }
    .allowed-tools-wrap {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 8px 10px;
      margin-top: 6px;
      max-width: 720px;
    }
    .allowed-tool-pill {
      display: block;
      padding: 7px 10px;
      border-radius: 10px;
      background: rgba(0,0,0,0.08);
      font-size: 0.92rem;
      font-weight: 700;
      line-height: 1.2;
      overflow-wrap: anywhere;
    }
    .mascot {
      position: fixed;
      right: 20px;
      bottom: 20px;
      z-index: 5;
      transition: top 0.2s ease, bottom 0.2s ease;
    }
    .mascot img {
      width: 92px;
      display: block;
    }
    .tool-link {
      display: block;
      font-size: 1.3rem;
      font-weight: bold;
      padding: 14px;
      text-align: center;
      border-radius: 8px;
      text-decoration: none;
      margin-bottom: 12px;
      background-color: #4CAF50;
      color: white;
      transition: background-color 0.3s;
    }
    .tool-grid .tool-link {
      margin-bottom: 0;
      min-height: 72px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid rgba(255,255,255,0.10);
      box-shadow: 0 10px 20px rgba(0,0,0,0.10);
    }
    .tool-link:hover {
      background-color: #45a049;
    }
    body.gator-time-mode .tool-link {
      background-color: #C5B358;
      color: #000;
    }
    body.gator-time-mode .tool-link:hover {
      background-color: #b8a647;
    }
    /* --- Experimental section custom colors --- */

    .layout-new .tool-link-blue {
      background-color: #0074D9 !important;   /* vivid blue */
      color: white !important;
    }
    .layout-new .tool-link-blue:hover {
      background-color: #005fa3 !important;
    }

    .layout-new .tool-link-red {
      background-color: #D32F2F !important;   /* strong red */
      color: white !important;
    }
    .layout-new .tool-link-red:hover {
      background-color: #b71c1c !important;
    }

    .layout-new .tool-link-orange {
      background-color: #972fd3 !important;   /* strong Purple */
      color: white !important;
    }
    .layout-new .tool-link-orange:hover {
      background-color: #7611b0 !important;
    }

    .section-divider {
      margin: 30px 0;
      border: 0;
      border-top: 2px solid var(--legacy-divider-color, #4caf50);
      opacity: 0.4;
    }
    
    .sidebar-card {
      width: 100%;
      box-sizing: border-box;
      margin-top: 10px;
      padding: 12px 13px;
      border-radius: 14px;
      background: rgba(255,255,255,0.72);
      box-shadow: 0 8px 20px rgba(0,0,0,0.10);
      border: 1px solid rgba(0,0,0,0.08);
    }
    body.dark-mode .sidebar-card,
    body.musky-mode .sidebar-card,
    body.gator-time-mode .sidebar-card {
      background: rgba(12,18,24,0.88);
      border-color: rgba(255,255,255,0.10);
      box-shadow: 0 12px 26px rgba(0,0,0,0.22);
      color: rgba(255,255,255,0.94);
    }
    .sidebar-card h3 {
      margin: 0 0 10px 0;
      font-size: 0.96rem;
    }
    .sidebar-card .line {
      margin-bottom: 8px;
      line-height: 1.35;
    }
    .sidebar-card .label {
      display: block;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      opacity: 0.72;
      text-transform: uppercase;
    }
    .sidebar-card .value {
      display: block;
      font-size: 0.92rem;
      font-weight: 700;
      margin-top: 3px;
    }
    .sidebar-card .subtle {
      font-size: 0.75rem;
      opacity: 0.72;
    }
    body.dark-mode .sidebar-card .label,
    body.musky-mode .sidebar-card .label,
    body.gator-time-mode .sidebar-card .label,
    body.dark-mode .sidebar-card .subtle,
    body.musky-mode .sidebar-card .subtle,
    body.gator-time-mode .sidebar-card .subtle {
      opacity: 0.82;
    }
    .mascot-link {
      display: block;
      line-height: 0;
    }
    @media (max-width: 980px) {
      .content {
        flex-direction: column;
      }
      .main {
        padding: 16px;
      }
      .main-shell {
        gap: 18px;
      }
      .hub-hero,
      .section-panel {
        border-radius: 20px;
      }
      .hub-hero {
        padding: 22px 18px;
      }
      .hero-profile {
        align-items: flex-start;
      }
      .old-layout-details {
        align-items: flex-start;
      }
      .section-panel {
        padding: 18px;
      }
      .tool-grid {
        grid-template-columns: 1fr;
      }
      .sidebar {
        width: auto;
        align-items: stretch;
      }
      .sidebar-top {
        align-items: stretch;
      }
      .mascot {
        right: 16px;
        bottom: 16px;
      }
      .allowed-tools-wrap {
        grid-template-columns: repeat(auto-fit, minmax(135px, 1fr));
      }
    }
  </style>

  <script>
    const hubThemeCsrfToken = <?= json_encode($hubThemeCsrfToken) ?>;

    function logoff() {
      fetch("logout.php").then(() => {
        window.location.href = "/auth/login.php";
      });
    }

    function changeTheme(theme) {
      localStorage.setItem('selectedTheme', theme);
      document.body.className = theme;

      const fd = new FormData();
      fd.append('theme', theme);
      fd.append('csrf_token', hubThemeCsrfToken);
      fetch('', { method: 'POST', body: fd });
    }

    function changeLayout(layout) {
      localStorage.setItem('selectedLayout', layout);
      document.body.dataset.layout = layout;
      updateMascotPosition();
    }

    window.onload = function() {
      const savedTheme = <?= json_encode($theme) ?>;
      const savedLayout = localStorage.getItem('selectedLayout') || 'old';
      document.body.className = savedTheme;
      document.body.dataset.layout = savedLayout;
      const themeSelect = document.getElementById('theme-select');
      if (themeSelect) themeSelect.value = savedTheme;
      const layoutSelect = document.getElementById('layout-select');
      if (layoutSelect) layoutSelect.value = savedLayout;
      updateMascotPosition();
    };

    function updateMascotPosition() {
      const mascot = document.querySelector('.mascot');
      const sidebarTop = document.querySelector('.sidebar-top');
      if (!mascot || !sidebarTop) return;

      const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
      if (viewportWidth <= 980) {
        mascot.style.top = '';
        mascot.style.bottom = '16px';
        return;
      }

      mascot.style.top = '';
      mascot.style.bottom = '20px';

      const sidebarRect = sidebarTop.getBoundingClientRect();
      const mascotRect = mascot.getBoundingClientRect();
      const desiredTop = Math.ceil(sidebarRect.bottom + 16);
      const defaultTop = Math.floor(window.innerHeight - mascotRect.height - 20);

      if (desiredTop < defaultTop) {
        mascot.style.top = '';
        mascot.style.bottom = '20px';
      } else {
        mascot.style.bottom = 'auto';
        mascot.style.top = desiredTop + 'px';
      }
    }

    window.addEventListener('resize', updateMascotPosition);
    window.addEventListener('load', updateMascotPosition);
  </script>

</head>

<body class="<?= htmlspecialchars($theme) ?>">

<div class="content">
  <div class="main">
    <div class="main-shell layout-new">
      <section class="hub-hero">
        <div class="hero-topline">Musky Hub</div>
        <h1 class="hero-heading">Welcome<?= $firstName ? ', ' . htmlspecialchars($firstName) : '' ?>!</h1>
        <p class="hero-subtitle">
          Your launchpad for device support, classroom tools, PANDA charge workflows, and admin utilities.
        </p>

        <div class="hero-profile">
          <?php if (!empty($pic)): ?>
            <img src="<?= htmlspecialchars($pic) ?>" alt="User Picture" class="profile-photo">
          <?php endif; ?>
          <div class="hero-details">
            <strong style="font-size:1.2em;">My Details</strong><br>
            <div class="details-grid">
              <span class="label">Name:</span>
              <span class="value"><?= htmlspecialchars($name ?: 'N/A') ?></span>
              <span class="label">Email:</span>
              <span class="value"><?= htmlspecialchars($email ?: 'N/A') ?></span>
              <span class="label">Role:</span>
              <span class="value"><?= htmlspecialchars($role ?: 'N/A') ?></span>
              <span class="label">Building:</span>
              <span class="value"><?= htmlspecialchars($building ?: 'N/A') ?></span>
              <span class="label">Allowed Tools:</span>
              <div class="value">
                <?php if ($allowed): ?>
                  <div class="allowed-tools-wrap">
                    <?php foreach ($allowed as $toolCode): ?>
                      <span class="allowed-tool-pill"><?= htmlspecialchars($toolCode) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  N/A
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="section-panel">
        <div class="section-kicker">Daily Work</div>
        <h1>📱 Device Management Hub</h1>
        <p class="section-note">
          Start with your assigned devices, jump into the full device manager, or open loaner and classroom tools when available.
        </p>
        <div class="tool-grid">
          <a href="DeviceManager/MyDevices.php" class="tool-link">📋 Devices Assigned to Me</a>
          <?php if ($can_loaners): ?>
            <a href="LoanerExplorer/LoanerExplorer.php" class="tool-link">🛠 Loaner Explorer V2</a>
          <?php endif; ?>
          <?php if ($can_devicemgr): ?>
            <a href="DeviceManager/index.php" class="tool-link">🔧 Device Management</a>
            <a href="NewHardware/index.php" class="tool-link tool-link-blue">📦 New Hardware Intake</a>
          <?php endif; ?>
          <?php if ($show_technology_marsh_launcher): ?>
            <a href="burrows/index.php" class="tool-link tool-link-blue">Technology Marsh</a>
            <a href="burrows/index.php?tunnel=trail-single" class="tool-link tool-link-blue">Network Trail Burrow (Individual)</a>
          <?php endif; ?>
          <?php if ($can_classmgr): ?>
            <a href="ClassExplorer/ClassExplorer.php" class="tool-link">📋 Devices in my Classroom</a>
          <?php endif; ?>
        </div>
      </section>

      <?php if ($can_panda): ?>
        <section class="section-panel">
          <div class="section-kicker">Charge Workflow</div>
          <h1>🏦 Musky Device Charges</h1>
          <p class="section-note">
            Open the current PANDA charge workflow, from charge creation through queue review.
          </p>
          <div class="tool-grid">
            <a href="HelperPagesV2/MuskyMakeTicketCharges.php" class="tool-link tool-link-orange">💵 Musky Device Charges V2</a>
            <a href="PANDA/PANDA_ChargeQueue.php" class="tool-link tool-link-red">🏦 PANDA Charge Queue</a>
            <a href="PANDA/PANDA_ChargeHistory.php" class="tool-link tool-link-orange">🏦 PANDA Charge History</a>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($can_upperadmin): ?>
        <section class="section-panel">
          <div class="section-kicker">Administration</div>
          <h2>🧰 MUSKY Access Management</h2>
          <p class="section-note">
            Admin-side user management and legacy charge entry paths.
          </p>
          <div class="tool-grid">
            <a href="admin/musky_admin.php" class="tool-link">🛠 Admin Panel</a>
            <a href="admin/NoraWeb.Dashboard.php" class="tool-link tool-link-blue">📊 NORA Status Dashboard</a>
            <a href="admin/NoraWeb.ErrandsList.php" class="tool-link tool-link-red">♽ NORA Errands Monitor</a>
            <a href="admin/NoraWeb.ImpactBoard.php" class="tool-link tool-link-orange">📈 NORA Impact Board</a>
            <a href="admin/NoraWeb.ConfigStore.php" class="tool-link">🛠 Musky Configs</a>
            <a href="admin/NewHardwareAdmin.php" class="tool-link tool-link-blue">📦 New Hardware Admin</a>
            <?php if ($can_upperadmin): ?>
              <a href="admin/NoraWeb.TagDecode.php" class="tool-link">🏷️ Tag Decoding</a>
            <?php endif; ?>
            <a href="admin/MuskyActivityDashboard.php" class="tool-link">👀 Musky Activity</a>
            <a href="Inventory/index.php" class="tool-link tool-link-orange">🏦 Musky Inventory (Admin)</a>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <div class="layout-old">
      <h1>👋 Welcome<?= $firstName ? ', ' . htmlspecialchars($firstName) : '' ?>!</h1>

      <div class="old-layout-details">
        <?php if (!empty($pic)): ?>
          <img src="<?= htmlspecialchars($pic) ?>" alt="User Picture" class="profile-photo">
        <?php endif; ?>
        <div>
          <strong style="font-size:1.2em;">My Details</strong><br>
          <div class="details-grid">
            <span class="label">Name:</span>
            <span class="value"><?= htmlspecialchars($name ?: 'N/A') ?></span>
            <span class="label">Email:</span>
            <span class="value"><?= htmlspecialchars($email ?: 'N/A') ?></span>
            <span class="label">Role:</span>
            <span class="value"><?= htmlspecialchars($role ?: 'N/A') ?></span>
            <span class="label">Building:</span>
            <span class="value"><?= htmlspecialchars($building ?: 'N/A') ?></span>
            <span class="label">Allowed Tools:</span>
            <div class="value">
              <?php if ($allowed): ?>
                <div class="allowed-tools-wrap">
                  <?php foreach ($allowed as $toolCode): ?>
                    <span class="allowed-tool-pill"><?= htmlspecialchars($toolCode) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                N/A
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <h1>📱 Device Management Hub</h1>
      <a href="DeviceManager/MyDevices.php" class="tool-link">📋 Devices Assigned to Me</a>

      <?php if ($can_loaners): ?>
        <a href="LoanerExplorer/LoanerExplorer.php" class="tool-link">🛠 Loaner Explorer V2</a>
      <?php endif; ?>

      <?php if ($can_devicemgr): ?>
        <a href="DeviceManager/index.php" class="tool-link">🔧 Device Management</a>
        <a href="NewHardware/index.php" class="tool-link tool-link-blue">📦 New Hardware Intake</a>
      <?php endif; ?>

      <?php if ($show_technology_marsh_launcher): ?>
        <a href="burrows/index.php" class="tool-link tool-link-blue">Technology Marsh</a>
        <a href="burrows/index.php?tunnel=trail-single" class="tool-link tool-link-blue">Network Trail Burrow (Individual)</a>
      <?php endif; ?>

      <?php if ($can_classmgr): ?>
        <h1>🏛 Classroom Tools</h1>
        <a href="ClassExplorer/ClassExplorer.php" class="tool-link">📋 Devices in my Classroom</a>
      <?php endif; ?>

      <hr class="section-divider">

      <?php if ($can_panda): ?>
        <h1>🏦 Musky Device Charges</h1>
        <a href="HelperPagesV2/MuskyMakeTicketCharges.php" class="tool-link tool-link-orange">💵 Musky Device Charges V2</a>
        <a href="PANDA/PANDA_ChargeQueue.php" class="tool-link tool-link-red">🏦 PANDA Charge Queue</a>
        <a href="PANDA/PANDA_ChargeHistory.php" class="tool-link tool-link-orange">🏦 PANDA Charge History</a>
      <?php endif; ?>

      <?php if ($can_upperadmin): ?>
        <h2 style="margin-top:2rem;">🧰 MUSKY Access Management</h2>
        <a href="admin/musky_admin.php" class="tool-link">🛠 Admin Panel</a>
        <a href="admin/NoraWeb.Dashboard.php" class="tool-link tool-link-blue">📊 NORA Status Dashboard</a>
        <a href="admin/NoraWeb.ErrandsList.php" class="tool-link tool-link-red">♽ NORA Errands Monitor</a>
        <a href="admin/NoraWeb.ImpactBoard.php" class="tool-link tool-link-orange">📈 NORA Impact Board</a>
        <a href="admin/NoraWeb.ConfigStore.php" class="tool-link">🛠 Musky Configs</a>
        <a href="admin/NewHardwareAdmin.php" class="tool-link tool-link-blue">📦 New Hardware Admin</a>
        <?php if ($can_upperadmin): ?>
          <a href="admin/NoraWeb.TagDecode.php" class="tool-link">🏷️ Tag Decoding</a>
        <?php endif; ?>
        <a href="admin/MuskyActivityDashboard.php" class="tool-link">👀 Musky Activity</a>
        <a href="Inventory/index.php" class="tool-link tool-link-orange">🏦 Musky Inventory (Admin)</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="sidebar">
    <div class="sidebar-top">
      <div class="theme-switcher sidebar-select-group">
        <label for="theme-select">🎨 Theme Select:</label>
        <select id="theme-select" onchange="changeTheme(this.value)">
          <option value="light-mode">Light</option>
          <option value="dark-mode">Dark</option>
          <option value="musky-mode">Musky Mode</option>
          <option value="gator-time-mode">Gator Time</option>
        </select>
      </div>

      <div class="sidebar-select-group">
        <label for="layout-select">🧱 Layout Select:</label>
        <select id="layout-select" onchange="changeLayout(this.value)">
          <option value="new">NEW LAYOUT</option>
          <option value="old">OLD LAYOUT</option>
        </select>
      </div>

      <a href="Preferences/index.php" class="tool-link" style="font-size:1rem; padding:12px; margin-bottom:0;">⚙️ Preferences</a>

      <div class="sidebar-card">
        <h3>🕒 My Musky Activity</h3>
        <div class="line">
          <span class="label">Last Musky Login</span>
          <span class="value"><?= htmlspecialchars(musky_format_sidebar_datetime($lastMuskyLogin)) ?></span>
        </div>
        <div class="line">
          <span class="label">Tracked Active Time</span>
          <span class="value"><?= htmlspecialchars(musky_format_duration_short($trackedUsageSeconds)) ?></span>
        </div>
        <div class="subtle">
          Based on recent tracked Musky activity, with idle gaps capped so inactive time is not counted.
        </div>
      </div>

      <button class="logoff action-button" onclick="logoff()">Log Off</button>
    </div>

    <a href="about.php" target="_blank" rel="noopener noreferrer" class="mascot mascot-link" aria-label="Open About page">
      <img src="mascot.png" alt="Mascot" />
    </a>
  </div>

</div>

</body>
</html>
