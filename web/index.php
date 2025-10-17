<?php
session_start();
require_once 'config.php';
require_once 'check_access.php';

$user = $_SESSION['musky_user'] ?? null;
if (!$user) {
    die("No valid user session.");
}

$email     = $user['email'] ?? 'unknown';
$firstName = $user['first_name'] ?? '';
$pic       = $user['photo_url'] ?? null;
$name      = $user['full_name'] ?? ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '');
$role      = $user['role'] ?? 'unknown';
$building  = $user['building'] ?? 'unknown';
$allowed   = $user['allowed_tools'] ?? 'none';
$tools     = array_map('trim', explode(',', $allowed));

$can_loaners     = in_array('LOANER_MGMT', $tools) || in_array('ALL_TOOLS', $tools);
$can_devicemgr   = in_array('DEVICE_MANAGER', $tools) || in_array('ALL_TOOLS', $tools);
$can_adminpanel  = in_array('ADMIN_PANEL', $tools) || in_array('ALL_TOOLS', $tools);
$can_experimental  = in_array('EXPERIMENTAL', $tools) || in_array('ALL_TOOLS', $tools);

// Load theme from musky_users
$theme = 'light-mode';
$db = new SQLite3($SQLITE_PATH);
$db->busyTimeout(3000);

$stmt = $db->prepare("SELECT theme FROM musky_users WHERE email = ?");
$stmt->bindValue(1, $email);
$result = $stmt->execute();
if ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
    if (!empty($row['theme'])) {
        $theme = $row['theme'];
    }
}
if ($result) $result->finalize();

// Handle theme update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    $newTheme = $_POST['theme'];
    $stmt = $db->prepare("UPDATE musky_users SET theme = ? WHERE email = ?");
    $stmt->bindValue(1, $newTheme);
    $stmt->bindValue(2, $email);
    $stmt->execute();
    $_SESSION['theme'] = $newTheme;
    $db->close();
    echo json_encode(['success' => true]);
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
    .sidebar {
      width: 240px;
      padding: 20px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      align-items: center;
    }
    .mascot img {
      width: 100px;
      margin-top: auto;
    }
    .logoff {
      margin-top: 10px;
    }
    .problem-button {
      position: fixed;
      bottom: 10px;
      left: 10px;
      z-index: 1000;
      background: red;
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 8px;
      font-weight: bold;
      cursor: pointer;
    }
    .theme-switcher {
      width: 100%;
    }
    .theme-switcher select {
      width: 100%;
      font-size: 1rem;
      margin-top: 5px;
    }
    .theme-switcher label {
      font-size: 1.05rem;
      font-weight: bold;
      font-family: "Segoe UI", sans-serif;
      display: block;
      margin-top: 1rem;
      margin-bottom: 0.4rem;
      text-align: left;
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
  </style>
  <script>
    function logoff() {
      fetch("logout.php").then(() => {
        window.location.href = "/auth/login.php";
      });
    }

    function reportProblem() {
      const desc = prompt("Describe the problem you're experiencing:");
      if (!desc) return;
      const fd = new FormData();
      fd.append('source_page', 'index.php');
      fd.append('description', desc);
      fd.append('browser_info', navigator.userAgent);
      fd.append('username', <?= json_encode($email) ?>);
      fetch("submit_problem.php", { method: 'POST', body: fd }).then(() => {
        alert("Thanks for your report!");
      });
    }

    function changeTheme(theme) {
      localStorage.setItem('selectedTheme', theme);
      document.body.className = theme;
      const fd = new FormData();
      fd.append('theme', theme);
      fetch('', { method: 'POST', body: fd });
    }

    window.onload = function() {
      const savedTheme = <?= json_encode($theme) ?>;
      document.body.className = savedTheme;
      const themeSelect = document.getElementById('theme-select');
      if (themeSelect) {
        themeSelect.value = savedTheme;
      }
    };
  </script>
</head>
<body class="<?= htmlspecialchars($theme) ?>">

<div class="content">

  <div class="main">
    <h1>👋 Welcome<?= $firstName ? ', ' . htmlspecialchars($firstName) : '' ?>!</h1>

    <div style="display:flex; align-items:center; margin-bottom:1.5rem;">
      <?php if (!empty($pic)): ?>
        <img src="<?= htmlspecialchars($pic) ?>" alt="User Picture"
             style="width:72px; height:72px; border-radius:50%; margin-right:20px;">
      <?php endif; ?>
      <div>
        <strong style="font-size:1.2em;">My Details</strong><br>
        Name: <?= htmlspecialchars($name ?: 'N/A') ?><br>
        Email: <?= htmlspecialchars($email ?: 'N/A') ?><br>
        Role: <?= htmlspecialchars($role ?: 'N/A') ?><br>
        Building: <?= htmlspecialchars($building ?: 'N/A') ?><br>
        Allowed Tools: <?= htmlspecialchars($allowed ?: 'N/A') ?>
      </div>
    </div>

    <h1>📱 Device Management Hub</h1>

    <?php if ($can_experimental): ?>
      <a href="DeviceManager/MyDevices.php" class="tool-link">📋 Devices Assigned to Me!!!!</a>
    <?php endif; ?>
    <?php if ($can_loaners): ?>
      <a href="Loaners/index.php" class="tool-link">📋 Loaner Management</a>
    <?php endif; ?>

    <?php if ($can_devicemgr): ?>
      <a href="DeviceManager/index.php" class="tool-link">🔧 Device Management</a>
    <?php endif; ?>

    <?php if ($can_adminpanel): ?>
      <h2 style="margin-top:2rem;">🧰 MUSKY Access Management</h2>
      <a href="admin/musky_admin.php" class="tool-link">🛠 Admin Panel</a>
    <?php endif; ?>
  </div>

  <div class="sidebar">
    <div class="theme-switcher">
      <label for="theme-select">🎨 Theme Select:</label>
      <select id="theme-select" onchange="changeTheme(this.value)">
        <option value="light-mode">Light</option>
        <option value="dark-mode">Dark</option>
        <option value="musky-mode">Musky Mode</option>
        <option value="gator-time-mode">Gator Time</option>
      </select>
    </div>

    <button class="logoff action-button" onclick="logoff()">Log Off</button>

    <div class="mascot">
      <img src="mascot.png" alt="Mascot" />
    </div>
  </div>

</div>

<button class="problem-button" onclick="reportProblem()">PROBLEM</button>

</body>
</html>